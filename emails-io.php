<?php
/**
 * Emails import / export (Studio → Emails). Admin only — requireAdmin() sends a
 * client session to login before any output.
 *
 *   GET  emails-io.php?client=<slug>&format=csv   → text/csv download <slug>-emails-YYYY-MM-DD.csv
 *                                                   (columns per emails-design.md §7, UTF-8 BOM, CRLF)
 *   GET  emails-io.php?client=<slug>&format=json  → application/json download (full rows + groups + comment threads)
 *
 *   POST emails-io.php?client=<slug>   multipart `file` (CSV or JSON) or `payload` (rows from a preview)
 *        mode=preview (default; also dry_run=1) → diff table, NO writes, plus a Confirm form
 *        mode=apply                              → one transaction, then studio?tab=emails&msg=summary
 *        format=json / Accept: application/json  → {ok, dry_run, summary, rows} instead of HTML
 *
 * The Confirm form re-posts the normalised rows as JSON in a hidden field (`payload`) —
 * equivalent to re-uploading the file; the server re-parses, re-validates and re-diffs
 * them against the live table before writing, so nothing from the preview is trusted.
 * Every write is scoped to the active client's company_id.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') { requireSameSiteFetch(); }

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$wantsJson = (($_POST['format'] ?? $_GET['format'] ?? '') === 'json' && $_SERVER['REQUEST_METHOD'] === 'POST')
          || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
              && stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') === false);

function ioFail(string $msg, int $code = 400): void {
    global $wantsJson, $client;
    if ($wantsJson || !$client) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
    header('Location: ' . clientUrl('studio.php', ['tab' => 'emails', 'msg' => $msg]));
    exit;
}

if (!$client) ioFail('Pick a client first.', 400);
$cid = (int)$client['id'];
if (!hasEmailsTable($pdo)) ioFail('The emails tables are missing — run migrate.php first.', 503);

// -------------------------------------------------------------------
// GET: export
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $format = strtolower(trim((string)($_GET['format'] ?? '')));
    $stem   = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)$client['slug'])) . '-emails-' . date('Y-m-d');
    if ($format === 'csv') {
        $csv = emailExportCsv($pdo, $cid);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $stem . '.csv"');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }
    if ($format === 'json') {
        $json = json_encode(emailExportJson($pdo, $client), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $stem . '.json"');
        header('Cache-Control: no-store');
        echo $json;
        exit;
    }
    header('Location: ' . clientUrl('studio.php', ['tab' => 'emails']));
    exit;
}

// -------------------------------------------------------------------
// POST: import (preview / apply)
// -------------------------------------------------------------------
$mode = strtolower(trim((string)($_POST['mode'] ?? '')));
if ($mode === '' || !empty($_POST['dry_run'])) $mode = 'preview';
if (!in_array($mode, ['preview', 'apply'], true)) $mode = 'preview';

/** Re-validate rows that came back from a preview (`payload`) — same shape emailImportNormalizeRows() emits. */
function ioRowsFromPayload(string $json): ?array {
    $data = json_decode($json, true);
    if (!is_array($data) || !array_is_list($data)) return null;
    $allowed = emailImportFields();
    $rows = [];
    foreach ($data as $r) {
        if (!is_array($r)) continue;
        $row = ['line' => (int)($r['line'] ?? 0), 'code' => emailNormalizeCode((string)($r['code'] ?? '')), 'action' => null, 'message' => '',
                'fields' => [], 'status' => null, 'status_blank' => !empty($r['status_blank']), 'groups' => null, 'warnings' => []];
        if ($row['code'] === '') continue;
        foreach ((array)($r['fields'] ?? []) as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if ($k === 'priority')     $v = emailPriorityFromLabel($v === null ? '' : (string)$v);
            elseif ($k === 'send_at')  { $v = trim((string)$v); $ts = $v === '' ? false : strtotime($v); $v = $ts ? date('Y-m-d', $ts) : null; }
            else                       $v = emailCleanCell($v);
            $row['fields'][$k] = $v;
        }
        if (isset($r['status']) && is_array($r['status'])) {
            $st = in_array($r['status']['status'] ?? '', ['draft', 'pending', 'approved', 'denied'], true) ? $r['status']['status'] : 'draft';
            $row['status'] = ['status' => $st, 'live' => !empty($r['status']['live']) ? 1 : 0, 'known' => !empty($r['status']['known'])];
        }
        if (array_key_exists('groups', $r) && is_array($r['groups'])) {
            $names = []; $seen = [];
            foreach ($r['groups'] as $g) {
                $g = trim(preg_replace('/\s+/', ' ', (string)$g)); $s = emailSlugify($g);
                if ($g === '' || $s === '' || isset($seen[$s])) continue;
                $seen[$s] = 1; $names[] = $g;
            }
            $row['groups'] = $names;
        }
        $rows[] = $row;
    }
    // duplicate IDs inside the payload: first wins (same rule as a fresh file)
    $seen = [];
    foreach ($rows as &$row) {
        if (isset($seen[$row['code']])) { $row['action'] = 'duplicate'; $row['message'] = 'Duplicate ID — line ' . $seen[$row['code']] . ' wins'; continue; }
        $seen[$row['code']] = $row['line'];
    }
    unset($row);
    return $rows;
}

$rows     = null;
$source   = '';
$parseErr = '';
if (!empty($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) ioFail('Upload failed (code ' . (int)$f['error'] . ').');
    if ((int)$f['size'] > 5 * 1024 * 1024) ioFail('The file is larger than 5 MB.');
    $text = (string)file_get_contents($f['tmp_name']);
    $source = (string)$f['name'];
    $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    $head = ltrim(substr(strncmp($text, "\xEF\xBB\xBF", 3) === 0 ? substr($text, 3) : $text, 0, 64));
    $isJson = $ext === 'json' || (($head[0] ?? '') === '{' || ($head[0] ?? '') === '[');
    $parsed = $isJson ? emailImportParseJson($text) : emailImportParseCsv($text);
    if ($parsed['error'] !== null) $parseErr = $parsed['error'];
    else $rows = emailImportNormalizeRows($parsed['records'], $parsed['columns']);
} elseif (isset($_POST['payload']) && is_string($_POST['payload']) && trim($_POST['payload']) !== '') {
    $rows = ioRowsFromPayload($_POST['payload']);
    if ($rows === null) $parseErr = 'The preview payload could not be read — upload the file again.';
    $source = trim((string)($_POST['source'] ?? '')) ?: 'preview';
} elseif (isset($_POST['text']) && is_string($_POST['text']) && trim($_POST['text']) !== '') {
    $text = (string)$_POST['text'];
    $head = ltrim($text);
    $parsed = (($head[0] ?? '') === '{' || ($head[0] ?? '') === '[') ? emailImportParseJson($text) : emailImportParseCsv($text);
    $source = 'pasted text';
    if ($parsed['error'] !== null) $parseErr = $parsed['error'];
    else $rows = emailImportNormalizeRows($parsed['records'], $parsed['columns']);
} else {
    ioFail('Choose a CSV or JSON file to import.');
}
if ($parseErr !== '') ioFail('Import failed: ' . $parseErr, 422);

if ($mode === 'apply') {
    try {
        $result = emailImportApply($pdo, $cid, $rows, 'admin');
    } catch (Throwable $e) {
        error_log('emails-io apply: ' . $e->getMessage());
        ioFail('Import failed: database error — nothing was changed.', 500);
    }
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'dry_run' => false, 'batch_id' => $result['batch_id'], 'summary' => $result['summary'],
            'rows' => array_map(static function ($r) { return ['line' => $r['line'], 'code' => $r['code'], 'action' => $r['action'], 'changes' => $r['changes'] ?? [], 'message' => $r['message']]; }, $result['rows'])]);
        exit;
    }
    header('Location: ' . clientUrl('studio.php', ['tab' => 'emails', 'msg' => emailImportSummaryText($result['summary'], true)]));
    exit;
}

// ---- preview -------------------------------------------------------
$diff    = emailImportDiff($pdo, $cid, $rows);
$summary = $diff['summary'];
$writes  = (int)$summary['create'] + (int)$summary['update'];

if ($wantsJson) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'dry_run' => true, 'summary' => $summary,
        'rows' => array_map(static function ($r) { return ['line' => $r['line'], 'code' => $r['code'], 'action' => $r['action'], 'changes' => $r['changes'] ?? [], 'message' => $r['message'], 'warnings' => $r['warnings']]; }, $diff['rows'])]);
    exit;
}

// Payload the Confirm form re-posts: only the normalised inputs, never the diff.
$payloadRows = array_map(static function ($r) {
    return ['line' => $r['line'], 'code' => $r['code'], 'fields' => $r['fields'], 'status' => $r['status'], 'status_blank' => $r['status_blank'], 'groups' => $r['groups']];
}, array_values(array_filter($rows, static function ($r) { return $r['action'] !== 'skip' && $r['action'] !== 'duplicate'; })));
$payload = json_encode($payloadRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$actionLabel = ['create' => 'New', 'update' => 'Changed', 'unchanged' => 'Unchanged', 'skip' => 'Skipped', 'duplicate' => 'Duplicate'];
$actionTone  = ['create' => 'approved', 'update' => 'pending', 'unchanged' => 'neutral', 'skip' => 'denied', 'duplicate' => 'denied'];

$pageTitle   = 'Import emails';
$navSubtitle = 'Studio · ' . $client['name'] . ' · Emails';
$activeTab   = 'studio';
$pageWide    = true;
$navWide     = true;
$navBack     = ['href' => clientUrl('studio.php', ['tab' => 'emails']), 'label' => 'Studio'];
$bodyClass   = 'page-studio page-email-import';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">';
$footExtra   = '<script>window.StudioConfig = ' . json_encode(['base' => basePath(), 'client' => $client['slug']], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . h(staticUrl('js/studio.js')) . '" defer></script>';
include __DIR__ . '/partials/layout-top.php';
?>

<section class="ui-card studio-import" data-import-preview>
  <div class="ui-card-header"><div class="ui-card-heading">
    <h3 class="ui-card-title">Preview — nothing has been written yet</h3>
    <p class="ui-card-subtitle"><?= h($source) ?> · <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?> read · matched on ID within <?= h($client['name']) ?></p>
  </div></div>
  <div class="ui-card-body">
    <div class="studio-chips studio-chips--wrap" data-import-summary>
      <span class="studio-chip studio-chip--static"><?= (int)$summary['create'] ?> <span class="studio-chip-n">new</span></span>
      <span class="studio-chip studio-chip--static"><?= (int)$summary['update'] ?> <span class="studio-chip-n">changed</span></span>
      <span class="studio-chip studio-chip--static"><?= (int)$summary['unchanged'] ?> <span class="studio-chip-n">unchanged</span></span>
      <span class="studio-chip studio-chip--static"><?= (int)$summary['duplicates'] ?> <span class="studio-chip-n">duplicate<?= (int)$summary['duplicates'] === 1 ? '' : 's' ?></span></span>
      <span class="studio-chip studio-chip--static"><?= (int)$summary['skipped'] ?> <span class="studio-chip-n">skipped</span></span>
      <?php if ((int)$summary['unknown_status'] > 0): ?><span class="studio-chip studio-chip--static"><?= (int)$summary['unknown_status'] ?> <span class="studio-chip-n">unknown status → Draft</span></span><?php endif; ?>
    </div>

    <form method="POST" action="<?= h(clientUrl('emails-io.php')) ?>" class="studio-import-confirm" data-import-confirm>
      <input type="hidden" name="mode" value="apply">
      <input type="hidden" name="source" value="<?= h($source) ?>">
      <input type="hidden" name="payload" value="<?= h($payload) ?>">
      <div class="studio-actions studio-actions--start">
        <button type="submit" class="ui-btn ui-btn--filled"<?= $writes === 0 ? ' disabled' : '' ?>>Apply <?= $writes ?> change<?= $writes === 1 ? '' : 's' ?></button>
        <a class="ui-btn ui-btn--gray" href="<?= h(clientUrl('studio.php', ['tab' => 'emails'])) ?>">Cancel</a>
        <span class="studio-help">Applies in one transaction; changed emails are logged as “imported”, new ones as “created”.</span>
      </div>
    </form>

    <div class="studio-table-wrap">
      <table class="studio-table studio-import-table">
        <thead><tr><th>Line</th><th>ID</th><th>Result</th><th>Changes</th></tr></thead>
        <tbody>
        <?php foreach ($diff['rows'] as $r): $a = $r['action']; ?>
          <tr class="studio-import-row studio-import-row--<?= h($a) ?>" data-import-row="<?= h($a) ?>" data-code="<?= h($r['code']) ?>">
            <td class="studio-import-line"><?= (int)$r['line'] ?></td>
            <td class="studio-import-id"><strong><?= h($r['code'] !== '' ? $r['code'] : '—') ?></strong><?php if (!empty($r['label']) && $r['label'] !== $r['code']): ?><div class="text-secondary"><?= h($r['label']) ?></div><?php endif; ?></td>
            <td><?= statusPill($actionTone[$a] ?? 'neutral', false, ['label' => $actionLabel[$a] ?? ucfirst((string)$a)]) ?>
              <?php if ($r['message'] !== ''): ?><div class="studio-help"><?= h($r['message']) ?></div><?php endif; ?>
              <?php foreach ($r['warnings'] as $w): ?><div class="studio-help studio-import-warn"><?= h($w) ?></div><?php endforeach; ?>
            </td>
            <td>
              <?php if (!empty($r['changes'])): ?>
                <ul class="studio-import-changes">
                <?php foreach ($r['changes'] as $field => [$old, $new]): ?>
                  <li><span class="studio-import-field"><?= h(emailFieldLabel((string)$field)) ?></span>
                    <?php if ($a === 'create'): ?><ins><?= h($new === '' ? '—' : $new) ?></ins>
                    <?php else: ?><del><?= h($old === '' ? '—' : $old) ?></del> <span aria-hidden="true">→</span> <ins><?= h($new === '' ? '—' : $new) ?></ins><?php endif; ?>
                  </li>
                <?php endforeach; ?>
                </ul>
              <?php elseif ($a === 'unchanged'): ?><span class="text-secondary">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
