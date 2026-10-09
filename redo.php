<?php
/**
 * The Redo queue (admin) — every image Joust has to make again, across tires, series, reference images and the
 * Library (redo-lib.php; migrate.php 52). The client's status is untouched: a client's Needs changes queues the image
 * automatically; Joust marks any other image from the viewer's ⋯ menu or the Assets select bar (an optional "what to
 * fix" note = an internal comment). A replacement file (the viewer's Replace, a row's Replace here, or "Replace from
 * folder") takes it off the queue and sends it back to To Review, which emails / pings the client as usual.
 *
 * Page (GET, requireAdmin):
 *   redo.php[?client=<slug>]   one client (the Assets / Tires "Redo" chip, the client Home) or every client
 *                              (&all=1, or no client): thumbnails, the client's feedback, the redo note, the age,
 *                              Replace / Remove per image, "Export redo pack", "Replace from folder".
 *
 * JSON (admin, same-site; POST unless noted):
 *   action=mark      {items: "tire:12,library:4", note?}   → {ok, marked, updated, count}   (403 for another client's
 *                                                            image when a client scope is posted)
 *   action=unmark    {items}                              → {ok, cleared, count}
 *   action=export_start {scope: client|all, since: 0|1}   → {ok, job, files, bytes, label, filename}   (export-lib.php
 *                                                            exportRedoStartJob — the same stepwise zip as Manage → Export)
 *   action=export_step  {job}                             → {ok, done, …}; once built, every file in it is stamped
 *                                                            redo_exported_at and meta redo_export_last[_<client id>] is set
 *   action=replace_match  multipart {file, path?, scope}  → {ok, kind, id, status, label} — the file replaces the queued
 *                                                            image of the same name (path = the browser's relative path
 *                                                            in a dropped redo pack folder, which settles same-named files)
 *                                                            · 404 no match · 409 ambiguous · 413 too large
 *   GET action=download&job=…                             → the zip (a redo job only)
 *   GET action=count                                      → {ok, count}
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/export-lib.php';
require_once __DIR__ . '/upload-lib.php';

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

function redoOut(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------
// JSON endpoints
// ---------------------------------------------------------------------
if ($action !== '') {
    $posts = ['mark', 'unmark', 'export_start', 'export_step', 'replace_match'];
    $gets  = ['download', 'count'];
    if (!in_array($action, $posts, true) && !in_array($action, $gets, true)) redoOut(400, ['ok' => false, 'error' => 'Unknown action']);
    if (in_array($action, $posts, true) && $method !== 'POST') redoOut(405, ['ok' => false, 'error' => 'Method not allowed']);
    if (in_array($action, $gets, true) && $method !== 'GET' && $method !== 'HEAD') redoOut(405, ['ok' => false, 'error' => 'Method not allowed']);
    requireSameSiteFetch();
    if (!currentAdmin()) redoOut(403, ['ok' => false, 'error' => 'Admin sign-in required']);
    if (!redoReady($pdo)) redoOut(409, ['ok' => false, 'error' => 'The Redo queue is not set up yet — run migrate.php (step 52).']);

    // Scope: the posted client (App.post adds the page's) — '' = no scope (every client).
    $scopeSlug = postedClientSlug();
    $scope = $scopeSlug !== '' ? helpersLoadCompany($pdo, $scopeSlug) : null;
    if ($scopeSlug !== '' && !$scope) redoOut(404, ['ok' => false, 'error' => 'Unknown client']);

    if ($action === 'count') redoOut(200, ['ok' => true, 'count' => redoCount($pdo, $scope ? (int)$scope['id'] : null)]);

    if ($action === 'mark' || $action === 'unmark') {
        $items = redoParseItems($_POST['items'] ?? '');
        if (!$items) redoOut(400, ['ok' => false, 'error' => 'Pick at least one image']);
        $note = (string)($_POST['note'] ?? '');
        if (mb_strlen(trim($note), 'UTF-8') > 500) redoOut(400, ['ok' => false, 'error' => 'Keep the note under 500 characters']);
        // every image must exist (404) and, with a client scope, be that client's (403) — before anything changes
        $rows = [];
        foreach ($items as $it) {
            if (!redoKindReady($pdo, $it['kind'])) redoOut(409, ['ok' => false, 'error' => 'The Library redo columns are missing — run migrate.php.']);
            $row = redoItemRow($pdo, $it['kind'], $it['id']);
            if (!$row) redoOut(404, ['ok' => false, 'error' => 'Image not found']);
            if ($scope && (int)$row['company_id'] !== (int)$scope['id']) redoOut(403, ['ok' => false, 'error' => 'This image belongs to another client']);
            $rows[] = $it;
        }
        $batch = newBatchId();
        $n = 0; $updated = 0;
        try {
            $pdo->beginTransaction();
            foreach ($rows as $it) {
                if ($action === 'mark') {
                    $r = redoMark($pdo, $it['kind'], $it['id'], $note, ['batch' => $batch]);
                    if (!empty($r['queued'])) $n++; else $updated++;
                } elseif (redoClear($pdo, $it['kind'], $it['id'], true, $batch)) {
                    $n++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('redo ' . $action . ': ' . $e->getMessage());
            redoOut(500, ['ok' => false, 'error' => 'Database error']);
        }
        $count = redoCount($pdo, $scope ? (int)$scope['id'] : null);
        redoOut(200, $action === 'mark' ? ['ok' => true, 'marked' => $n, 'updated' => $updated, 'count' => $count]
                                         : ['ok' => true, 'cleared' => $n, 'count' => $count]);
    }

    if ($action === 'export_start') {
        if (!exportZipSupported()) redoOut(503, ['ok' => false, 'error' => 'This server runs a 32-bit PHP build, which cannot write zips safely']);
        $all = (string)($_POST['scope'] ?? '') === 'all' || !$scope;
        try { $job = exportRedoStartJob($pdo, $all ? null : $scope, ['since' => (string)($_POST['since'] ?? '0')]); }
        catch (InvalidArgumentException $e) { redoOut(400, ['ok' => false, 'error' => $e->getMessage()]); }
        catch (Throwable $e) { error_log('redo export start: ' . $e->getMessage()); redoOut(500, ['ok' => false, 'error' => $e->getMessage() ?: 'Could not start the export']); }
        redoOut(200, ['ok' => true, 'job' => $job['job'], 'files' => count($job['files']), 'bytes' => $job['bytes'], 'label' => $job['label'],
                      'filename' => $job['filename'], 'warnings' => $job['warnings'], 'clients' => count(array_unique(array_column($job['files'], 'company_id')))]);
    }

    /** A redo job by id (400 bad id, 404 unknown or not a redo pack). */
    $loadJob = static function (): array {
        $id = (string)($_POST['job'] ?? $_GET['job'] ?? '');
        if (!exportValidJobId($id)) redoOut(400, ['ok' => false, 'error' => 'Bad job id']);
        $job = exportReadJob($id);
        if ($job === null || empty($job['redo'])) redoOut(404, ['ok' => false, 'error' => 'Unknown export — it may have expired (exports are kept for 24 hours)']);
        return $job;
    };

    if ($action === 'export_step') {
        $job = $loadJob();
        $budget = [];
        if (getenv('EXPORT_QA_STEP_BYTES')) $budget['bytes'] = (int)getenv('EXPORT_QA_STEP_BYTES');
        $r = exportStep($job, ['id' => (int)$job['company_id'], 'slug' => (string)$job['client']], $budget);
        if (!$r['ok']) redoOut((int)($r['code'] ?? 500), ['ok' => false, 'error' => (string)($r['error'] ?? 'Step failed')]);
        if (!empty($r['done'])) {
            $job = exportReadJob($job['job']) ?? $job;
            if (empty($job['stamped'])) {
                // "Only new since last export": every file that went into the pack is stamped (not the ones missing on disk)
                try {
                    foreach ($job['files'] as $f) {
                        if (!empty($f['skipped']) || !in_array($f['kind'] ?? '', ['tire', 'library'], true)) continue;
                        $t = redoTable((string)$f['kind']);
                        $pdo->prepare("UPDATE {$t} SET redo_exported_at = NOW()" . redoKeepUpdated($pdo, $t) . " WHERE id = ? AND redo_at IS NOT NULL")->execute([(int)$f['id']]);
                    }
                    if (function_exists('hasMetaTable') && hasMetaTable($pdo)) {
                        $m = $pdo->prepare("INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
                        $now = date('Y-m-d H:i:s');
                        $m->execute(['redo_export_last', $now]);
                        foreach (array_unique(array_map('intval', array_column($job['files'], 'company_id'))) as $cid) $m->execute(['redo_export_last_' . $cid, $now]);
                    }
                    $job['stamped'] = true;
                    exportSaveJob($job);
                } catch (Throwable $e) { error_log('redo export stamp: ' . $e->getMessage()); }
            }
        }
        unset($r['ok']);
        redoOut(200, ['ok' => true] + $r);
    }

    if ($action === 'download') {
        $job = $loadJob();
        $r = exportStreamZip($job, $method, 'redo-pack.zip');
        redoOut((int)$r['code'], ['ok' => false, 'error' => (string)$r['error']]);
    }

    // ---- replace_match: one file of a dropped folder → the queued image of the same name ----
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) redoOut(400, ['ok' => false, 'error' => 'No file uploaded']);
    $err = (int)$_FILES['file']['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) redoOut(413, ['ok' => false, 'error' => 'File too large for one request (PHP limit: ' . (ini_get('upload_max_filesize') ?: '?') . ') — use Replace on that image instead']);
    if ($err !== UPLOAD_ERR_OK) redoOut(400, ['ok' => false, 'error' => 'Upload error code ' . $err]);
    $orig = (string)$_FILES['file']['name'];
    $tmp  = (string)$_FILES['file']['tmp_name'];
    $check = uploadCheckName($orig);
    if (isset($check['error'])) redoOut(415, ['ok' => false, 'error' => $check['error']]);
    $isVideo = (bool)$check['video'];
    if ((int)$_FILES['file']['size'] > uploadMaxBytes($isVideo ? 'video' : 'image')) redoOut(413, ['ok' => false, 'error' => 'File exceeds ' . uploadCapLabel(uploadMaxBytes($isVideo ? 'video' : 'image'))]);
    $why = uploadCheckContent($tmp, $check['ext'], $isVideo);
    if ($why !== '') redoOut(422, ['ok' => false, 'error' => $why]);
    $all = (string)($_POST['scope'] ?? '') === 'all' || !$scope;
    $m = redoMatchFile($pdo, $all ? null : (int)$scope['id'], $orig, (string)($_POST['path'] ?? ''));
    if (isset($m['error'])) {
        redoOut($m['error'] === 'none' ? 404 : 409, ['ok' => false, 'match' => $m['error'], 'candidates' => (int)$m['candidates'],
            'error' => $m['error'] === 'none' ? 'No image in the redo queue is called ' . $orig : $orig . ' matches ' . (int)$m['candidates'] . ' queued images — drop the redo pack folder (Client/Tire/Series/…) so the folders tell them apart, or use Replace on the image']);
    }
    $it = $m['item'];
    $r = uploadReplaceApply($pdo, $it['kind'], (int)$it['id'], $tmp, $check['ext'], $isVideo, true);
    if ((int)$r['code'] !== 200) redoOut((int)$r['code'], $r['body']);
    redoOut(200, ['ok' => true, 'kind' => $it['kind'], 'id' => (int)$it['id'], 'status' => (string)($r['body']['status'] ?? 'pending'),
                  'label' => redoPackFolder($it) . '/' . $it['filename'], 'client' => $it['company_slug']]);
}

// ---------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------
requireAdmin();
$wantAll = !$client || (string)($_GET['all'] ?? '') === '1';
$cid     = !$wantAll ? (int)$client['id'] : null;
$ready   = redoReady($pdo);
$trashOn = trashReady($pdo, 'tire_image') && trashReady($pdo, 'library_image');
$items   = $ready ? redoItems($pdo, $cid) : [];
$countClient = ($ready && $client) ? redoCount($pdo, (int)$client['id']) : 0;
$countAll    = $ready ? redoCount($pdo, null) : 0;
$newCount    = 0;
foreach ($items as $it) { if ($it['redo_exported_at'] === null || $it['redo_exported_at'] < $it['redo_at']) $newCount++; }
$lastExport = '';
if ($ready && function_exists('hasMetaTable') && hasMetaTable($pdo)) {
    $st = $pdo->prepare("SELECT v FROM meta WHERE k = ?");
    $st->execute([$cid ? 'redo_export_last_' . $cid : 'redo_export_last']);
    $lastExport = (string)($st->fetchColumn() ?: '');
}

$pageTitle   = 'Redo';
$navSubtitle = $wantAll ? 'All clients' : $client['name'];
$htmlTitle   = 'Redo' . (!$wantAll ? ' — ' . $client['name'] : '') . ' — Joust Media';
$activeTab   = 'assets';
$navTrailing = (!$wantAll && $client) ? clientAvatar($client) : joustAvatar();
$bodyClass   = 'page-redo';
$headExtra   = '<link rel="stylesheet" href="' . esc(staticUrl('css/assets.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . esc(staticUrl('css/redo.css')) . '">';
include __DIR__ . '/partials/layout-top.php';

$scopeSlug = $client ? (string)$client['slug'] : '';
?>
<div class="rd" data-redo-page data-scope="<?= $wantAll ? 'all' : 'client' ?>" data-endpoint="<?= esc(basePath() . '/redo.php') ?>"
     data-client="<?= esc($scopeSlug) ?>" data-count="<?= count($items) ?>">
  <?php if ($client): ?>
    <div class="rd-scope"><?= segmented([
        ['label' => $client['name'], 'href' => portalUrl('redo', ['client' => $client['slug']]), 'active' => !$wantAll, 'count' => $countClient, 'attrs' => ['data-redo-scope' => 'client']],
        ['label' => 'All clients', 'href' => portalUrl('redo', ['client' => $client['slug'], 'all' => 1]), 'active' => $wantAll, 'count' => $countAll, 'attrs' => ['data-redo-scope' => 'all']],
    ], ['label' => 'Which clients', 'auto' => true]) ?></div>
  <?php endif; ?>

  <?php if (!$ready): ?>
    <div class="studio-alert studio-alert--error" role="alert">The Redo queue needs the database update: open <a href="<?= esc(basePath() . '/migrate.php') ?>">migrate.php</a> once (step 52).</div>
  <?php else: ?>
  <section class="rd-head" aria-label="Redo queue">
    <div class="rd-head-count">
      <span class="ui-badge rd-badge" data-redo-count><?= count($items) ?></span>
      <span class="rd-head-text"><?= count($items) === 1 ? 'image to redo' : 'images to redo' ?><?= $wantAll ? ' across every client' : ' for ' . esc($client['name']) ?></span>
    </div>
    <div class="rd-head-actions">
      <button type="button" class="ui-btn ui-btn--tinted" data-redo-export<?= $items ? '' : ' disabled' ?>><?= icon('download') ?><span data-redo-export-label>Export redo pack</span></button>
      <button type="button" class="ui-btn ui-btn--gray" data-redo-folder<?= $items ? '' : ' disabled' ?> title="Pick the folder with the fixed files: each one replaces the queued image of the same name"><?= icon('upload') ?><span>Replace from folder</span></button>
    </div>
    <label class="rd-since"><input type="checkbox" data-redo-since<?= $lastExport !== '' && $newCount > 0 ? ' checked' : '' ?>> Only new since last export
      <span class="text-secondary" data-redo-last><?= $lastExport !== '' ? '· last ' . esc(relativeTime($lastExport)) . ' · ' . $newCount . ' new' : '· never exported' ?></span></label>
    <input type="file" class="ui-visually-hidden" data-redo-folder-input webkitdirectory directory multiple tabindex="-1" aria-hidden="true">
    <input type="file" class="ui-visually-hidden" data-redo-files-input multiple accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov" tabindex="-1" aria-hidden="true">
    <input type="file" class="ui-visually-hidden" data-redo-replace-input accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov" tabindex="-1" aria-hidden="true">
    <p class="rd-help text-secondary">The pack holds the original files in <code>Client/Tire/Series/</code> folders with a <code>.txt</code> of the client's feedback next to each, and <code>redo-index.csv</code>. Fix them, then drop the folder back with <em>Replace from folder</em> (or <button type="button" class="ui-btn ui-btn--plain ui-btn--sm rd-inline-btn" data-redo-files>pick files</button>): each file replaces the queued image of the same name and goes back to the client as To Review.</p>
  </section>

  <?php if (!$items): ?>
    <div class="ui-empty rd-empty" data-redo-empty>
      <?= icon('checkmark', 'as-empty-icon') ?><p class="as-empty-title">Nothing to redo</p>
      <p>Images land here when a client asks for changes, or when you pick <em>Mark for redo…</em> in the viewer's ⋯ menu or the Select bar.</p>
    </div>
  <?php else:
    $group = null;
    foreach ($items as $it):
      $g = ($wantAll ? $it['company_name'] . ' · ' : '') . ($it['kind'] === 'library' ? 'Library' : $it['tire_name'] . ' · ' . ($it['series_id'] ? $it['series_name'] : 'Reference'));
      if ($g !== $group) {
          if ($group !== null) echo insetListClose();
          echo insetListOpen($g, ['class' => 'rd-group', 'attrs' => ['data-redo-group' => $g]]);
          $group = $g;
      }
      $key   = $it['kind'] . ':' . $it['id'];
      $thumb = $it['path'] === null ? icon('photo') : ($it['type'] === 'video' ? videoTile($it['src'], ['badge' => false, 'probe' => false]) : pvImg($it['src'], 'sm', ['alt' => '', 'sizes' => pvSizes('row')]));
      $new   = $it['redo_exported_at'] === null || $it['redo_exported_at'] < $it['redo_at'];
      $fb    = $it['feedback'] !== '' ? (mb_strlen($it['feedback']) > 200 ? rtrim(mb_substr($it['feedback'], 0, 199)) . '…' : $it['feedback']) : '';
  ?>
      <li class="rd-row" data-redo-row="<?= esc($key) ?>" data-kind="<?= esc($it['kind']) ?>" data-id="<?= (int)$it['id'] ?>" data-client="<?= esc($it['company_slug']) ?>" data-status="<?= esc($it['status']) ?>">
        <a class="rd-thumb" href="<?= esc($it['open']) ?>" aria-label="<?= esc('Open ' . $it['filename']) ?>"><?= $thumb ?></a>
        <div class="rd-body">
          <a class="rd-title" href="<?= esc($it['open']) ?>"><?= esc($it['filename']) ?></a>
          <div class="rd-meta">
            <?= statusPill($it['status']) ?>
            <span class="rd-age" title="<?= esc(absoluteTime($it['redo_at'])) ?>" data-redo-age><?= esc(trackingAge($it['redo_at']) !== '' ? trackingAge($it['redo_at']) : relativeTime($it['redo_at'])) ?></span>
            <span class="rd-by text-secondary"><?= $it['redo_by_name'] !== '' ? 'marked by ' . esc($it['redo_by_name']) : 'client asked for changes' ?></span>
            <?php if (!$new): ?><span class="ui-pill ui-pill--nodot rd-exported" data-redo-exported title="<?= esc('In a redo pack ' . relativeTime((string)$it['redo_exported_at'])) ?>">exported</span><?php endif; ?>
            <?php if ($it['path'] === null): ?><span class="ui-pill ui-pill--denied ui-pill--nodot">file missing</span><?php endif; ?>
          </div>
          <?php if ($fb !== ''): ?><p class="rd-feedback" data-redo-feedback>“<?= esc($fb) ?>”</p><?php endif; ?>
          <?php if ($it['redo_note'] !== ''): ?><p class="rd-note" data-redo-note><span class="rd-note-label">To fix:</span> <?= esc($it['redo_note']) ?></p><?php endif; ?>
        </div>
        <div class="rd-actions">
          <button type="button" class="ui-btn ui-btn--sm ui-btn--tinted" data-redo-replace>Replace…</button>
          <button type="button" class="ui-btn ui-btn--sm ui-btn--gray" data-redo-remove>Remove</button>
          <?php if ($trashOn): // Lance will not redo it: Joust's Trash (trash.php) — kept, out of every list and notification ?>
            <button type="button" class="ui-btn ui-btn--sm ui-btn--gray rd-trash" data-redo-trash title="Not redoing it: move it to the Trash (kept, out of every list and notification)"><?= icon('trash') ?><span>Trash…</span></button>
          <?php endif; ?>
        </div>
      </li>
  <?php endforeach; echo insetListClose('Replace sends the image back to the client as To Review. Remove takes it off this list and changes nothing else.' . ($trashOn ? ' Trash… keeps it but drops it from every list and notification (restore it from the Trash).' : '')); endif; ?>
  <?php if ($trashOn && ($trashLink = trashLinkHtml($pdo, $wantAll ? null : $client)) !== ''): ?><p class="trash-link-row"><?= $trashLink ?></p><?php endif; ?>
  <?php endif; ?>
</div>
<?php
$footExtra = '<script src="' . esc(staticUrl('js/chunk-upload.js')) . '" defer></script>' . "\n"
           . '<script src="' . esc(staticUrl('js/redo.js')) . '" defer></script>' . "\n";
$includeSheet = true;
include __DIR__ . '/partials/layout-bottom.php';
