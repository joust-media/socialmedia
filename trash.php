<?php
/**
 * The Trash (admin) — items Joust decided not to do (trash-lib.php; migrate.php 54). Kept, separate, and left out of
 * every list, count and notification until restored. Nothing is ever purged automatically.
 *
 * Page (GET, requireAdmin):
 *   trash.php[?client=<slug>]   one client (Manage, Home / Assets / Posts "Trash (N)") or every client (&all=1, or no
 *                               client): grouped by client and type — thumbnail, title, the state it returns to, the
 *                               client's last note, who trashed it, when, the reason; Restore (one or many) and, apart
 *                               behind ⋯, "Delete forever…" (type DELETE).
 *
 * JSON (admin, same-site, POST unless noted; a client seat gets 403):
 *   action=trash    {items: "post:3,email:4,tire:12,library:5", note?}   → {ok, trashed, count}   (403 for another
 *                                                                           client's item when a client scope is posted)
 *   action=restore  {items}                                               → {ok, restored, count}
 *   action=delete   {items, confirm: "DELETE"}                            → {ok, deleted, files, count} — 422 without
 *                                                                           the typed word; only items IN the Trash
 *   GET action=count                                                      → {ok, count, counts}
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
if (is_file(__DIR__ . '/pages-lib.php')) require_once __DIR__ . '/pages-lib.php';

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

function trashOut(int $code, array $body): void {
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
    $posts = ['trash', 'restore', 'delete'];
    $gets  = ['count'];
    if (!in_array($action, $posts, true) && !in_array($action, $gets, true)) trashOut(400, ['ok' => false, 'error' => 'Unknown action']);
    if (in_array($action, $posts, true) && $method !== 'POST') trashOut(405, ['ok' => false, 'error' => 'Method not allowed']);
    if (in_array($action, $gets, true) && $method !== 'GET' && $method !== 'HEAD') trashOut(405, ['ok' => false, 'error' => 'Method not allowed']);
    requireSameSiteFetch();
    if (!currentAdmin() || !isAdmin()) trashOut(403, ['ok' => false, 'error' => 'Admin sign-in required']);
    if (!trashReady($pdo)) trashOut(409, ['ok' => false, 'error' => 'The Trash is not set up yet — run migrate.php (step 54).']);

    // Scope: the posted client (App.post adds the page's) — '' = no scope (every client).
    $scopeSlug = postedClientSlug();
    $scope = $scopeSlug !== '' ? helpersLoadCompany($pdo, $scopeSlug) : null;
    if ($scopeSlug !== '' && !$scope) trashOut(404, ['ok' => false, 'error' => 'Unknown client']);
    $countNow = static function () use ($pdo, $scope): int { return trashCount($pdo, $scope ? (int)$scope['id'] : null); };

    if ($action === 'count') trashOut(200, ['ok' => true, 'count' => $countNow(), 'counts' => trashCounts($pdo, $scope ? (int)$scope['id'] : null)]);

    $items = trashParseItems($_POST['items'] ?? '');
    if (!$items) trashOut(400, ['ok' => false, 'error' => 'Pick at least one item']);
    $note = (string)($_POST['note'] ?? '');
    if (mb_strlen(trim($note), 'UTF-8') > 500) trashOut(400, ['ok' => false, 'error' => 'Keep the reason under 500 characters']);
    if ($action === 'delete' && trim((string)($_POST['confirm'] ?? '')) !== 'DELETE') {
        trashOut(422, ['ok' => false, 'error' => 'Type DELETE to delete forever — this removes the item and its files for good.']);
    }
    // every item must exist (404), be of a set-up type (409) and, with a client scope, be that client's (403) — before anything changes
    foreach ($items as $it) {
        if (!trashReady($pdo, $it['type'])) trashOut(409, ['ok' => false, 'error' => 'The Trash columns are missing on ' . trashTypes()[$it['type']]['table'] . ' — run migrate.php.']);
        $row = trashItemRow($pdo, $it['type'], $it['id']);
        if (!$row) trashOut(404, ['ok' => false, 'error' => 'Item not found']);
        if ($scope && (int)$row['company_id'] !== (int)$scope['id']) trashOut(403, ['ok' => false, 'error' => 'This item belongs to another client']);
        if ($action === 'delete' && $row['trashed_at'] === null) trashOut(409, ['ok' => false, 'error' => 'Only items in the Trash can be deleted forever']);
    }

    $batch = newBatchId();
    $n = 0; $files = 0; $kept = 0; $skipped = 0;
    try {
        if ($action !== 'delete') $pdo->beginTransaction();
        foreach ($items as $it) {
            if ($action === 'trash') {
                $r = trashMove($pdo, $it['type'], $it['id'], $note, ['batch' => $batch]);
                if (!empty($r['trashed'])) $n++;
                $skipped += (int)($r['skipped'] ?? 0);
            } elseif ($action === 'restore') {
                $r = trashRestore($pdo, $it['type'], $it['id'], ['batch' => $batch]);
                if (!empty($r['restored'])) $n++;
            } else {
                // one transaction per item: its row goes, then its files (a failed file unlink never brings the row back)
                $pdo->beginTransaction();
                $r = trashDeleteForever($pdo, $it['type'], $it['id']);
                if (empty($r['ok'])) { $pdo->rollBack(); trashOut(409, ['ok' => false, 'error' => (string)($r['error'] ?? 'Could not delete'), 'deleted' => $n]); }
                $pdo->commit();
                $n++; $files += (int)($r['files'] ?? 0); $kept += (int)($r['kept'] ?? 0);
            }
        }
        if ($pdo->inTransaction()) $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('trash ' . $action . ': ' . $e->getMessage());
        trashOut(500, ['ok' => false, 'error' => 'Database error']);
    }
    if ($action === 'trash')   trashOut(200, ['ok' => true, 'trashed' => $n, 'skipped' => $skipped, 'count' => $countNow(), 'url' => portalUrl('trash', $scope ? ['client' => $scope['slug']] : [])]);
    if ($action === 'restore') trashOut(200, ['ok' => true, 'restored' => $n, 'count' => $countNow()]);
    trashOut(200, ['ok' => true, 'deleted' => $n, 'files' => $files, 'kept' => $kept, 'count' => $countNow()]);
}

// ---------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------
requireAdmin();
$wantAll = !$client || (string)($_GET['all'] ?? '') === '1';
$cid     = !$wantAll ? (int)$client['id'] : null;
$ready   = trashReady($pdo);
$items   = $ready ? trashItems($pdo, $cid) : [];
$countClient = ($ready && $client) ? trashCount($pdo, (int)$client['id']) : 0;
$countAll    = $ready ? trashCount($pdo, null) : 0;

$pageTitle   = 'Trash';
$navSubtitle = $wantAll ? 'All clients' : $client['name'];
$htmlTitle   = 'Trash' . (!$wantAll ? ' — ' . $client['name'] : '') . ' — Joust Media';
$activeTab   = 'manage';
$navTrailing = (!$wantAll && $client) ? clientAvatar($client) : joustAvatar();
$bodyClass   = 'page-trash';
$headExtra   = '<link rel="stylesheet" href="' . esc(staticUrl('css/redo.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . esc(staticUrl('css/trash.css')) . '">';
include __DIR__ . '/partials/layout-top.php';

$companies = [];
foreach ($items as $it) $companies[$it['company_id']] = ['name' => $it['company_name'], 'slug' => $it['company_slug']];
$typeIcon = ['post' => 'grid', 'email' => 'mail', 'page' => 'page', 'tire_image' => 'tire', 'library_image' => 'photo'];
?>
<div class="rd tr" data-trash-page data-scope="<?= $wantAll ? 'all' : 'client' ?>" data-endpoint="<?= esc(basePath() . '/trash.php') ?>"
     data-client="<?= esc($wantAll ? '' : (string)$client['slug']) ?>" data-count="<?= count($items) ?>">
  <?php if ($client): ?>
    <div class="rd-scope"><?= segmented([
        ['label' => $client['name'], 'href' => portalUrl('trash', ['client' => $client['slug']]), 'active' => !$wantAll, 'count' => $countClient, 'attrs' => ['data-trash-scope' => 'client']],
        ['label' => 'All clients', 'href' => portalUrl('trash', ['client' => $client['slug'], 'all' => 1]), 'active' => $wantAll, 'count' => $countAll, 'attrs' => ['data-trash-scope' => 'all']],
    ], ['label' => 'Which clients', 'auto' => true]) ?></div>
  <?php endif; ?>

  <?php if (!$ready): ?>
    <div class="studio-alert studio-alert--error" role="alert">The Trash needs the database update: open <a href="<?= esc(basePath() . '/migrate.php') ?>">migrate.php</a> once (step 54).</div>
  <?php else: ?>
  <section class="rd-head tr-head" aria-label="Trash">
    <div class="rd-head-count">
      <span class="ui-badge tr-badge" data-trash-count><?= count($items) ?></span>
      <span class="rd-head-text"><?= count($items) === 1 ? 'item in the Trash' : 'items in the Trash' ?><?= $wantAll ? ' across every client' : ' for ' . esc($client['name']) ?></span>
    </div>
    <div class="rd-head-actions">
      <label class="tr-all"<?= $items ? '' : ' hidden' ?>><input type="checkbox" data-trash-all> Select all</label>
      <button type="button" class="ui-btn ui-btn--tinted" data-trash-restore-selected disabled><?= icon('checkmark') ?><span data-trash-restore-label>Restore selected</span></button>
    </div>
    <p class="rd-help text-secondary">Trashed items keep their files, previews, comments and status — they are just left out of every list, count, export and notification, for you and the client. <strong>Restore</strong> puts one back exactly where it was, without notifying anyone. Nothing here is ever deleted automatically.</p>
  </section>

  <?php if (!$items): ?>
    <div class="ui-empty rd-empty" data-trash-empty>
      <?= icon('trash', 'as-empty-icon') ?><p class="as-empty-title">The Trash is empty</p>
      <p>Items you decide not to do land here from <em>Move to Trash…</em> in a post, email or page’s ⋯ menu, the image viewer, the Assets Select bar or the Redo queue.</p>
    </div>
  <?php else:
    $group = null;
    foreach ($items as $it):
      $g = ($wantAll ? $it['company_name'] . ' · ' : '') . trashTypeGroupLabel($it['type'], $companies[$it['company_id']] ?? null);
      if ($g !== $group) {
          if ($group !== null) echo insetListClose();
          echo insetListOpen($g, ['class' => 'rd-group tr-group', 'attrs' => ['data-trash-group' => $g]]);
          $group = $g;
      }
      $thumb = $it['thumb'] !== '' ? pvImg($it['thumb'], 'sm', ['alt' => '', 'sizes' => pvSizes('row')])
             : ($it['video'] && $it['original'] !== '' ? videoTile($it['original'], ['badge' => false, 'probe' => false]) : icon($typeIcon[$it['type']] ?? 'photo'));
      $note = $it['note'];
      $noteText = $note ? (mb_strlen($note['text']) > 200 ? rtrim(mb_substr($note['text'], 0, 199)) . '…' : $note['text']) : '';
      $label = $it['title'] !== '' ? $it['title'] : ucfirst(str_replace('_', ' ', $it['type'])) . ' #' . $it['id'];
  ?>
      <li class="rd-row tr-row" data-trash-row="<?= esc($it['key']) ?>" data-type="<?= esc($it['type']) ?>" data-id="<?= (int)$it['id'] ?>" data-client="<?= esc($it['company_slug']) ?>" data-prev="<?= esc($it['prev_key']) ?>">
        <label class="tr-check"><input type="checkbox" data-trash-pick aria-label="<?= esc('Select ' . $label) ?>"></label>
        <?php if ($it['original'] !== ''): ?>
          <a class="rd-thumb tr-thumb" href="<?= esc($it['original']) ?>" target="_blank" rel="noopener" aria-label="<?= esc('View ' . $label) ?>"><?= $thumb ?></a>
        <?php else: ?>
          <span class="rd-thumb tr-thumb tr-thumb--icon" aria-hidden="true"><?= $thumb ?></span>
        <?php endif; ?>
        <div class="rd-body">
          <span class="rd-title tr-title"><?= esc($label) ?></span>
          <div class="rd-meta">
            <span class="text-secondary tr-context"><?= esc($it['context']) ?></span>
            <span class="tr-was" title="Restore puts it back here">was <?= statusPill($it['prev_key'] === 'live' ? 'approved' : ($it['prev_key'] === 'posted' ? 'approved' : $it['prev_key']), $it['prev_key'] === 'posted', ['label' => $it['prev_label'], 'attrs' => ['data-trash-prev' => $it['prev_key']]]) ?></span>
          </div>
          <div class="rd-meta tr-by">
            <span>Trashed by <?= esc($it['trashed_by_name']) ?> · <time title="<?= esc(absoluteTime($it['trashed_at'])) ?>"><?= esc(relativeTime($it['trashed_at'])) ?></time></span>
          </div>
          <?php if ($it['trash_note'] !== ''): ?><p class="rd-note tr-reason" data-trash-reason><span class="rd-note-label tr-reason-label">Reason:</span> <?= esc($it['trash_note']) ?></p><?php endif; ?>
          <?php if ($noteText !== ''): ?><p class="rd-feedback tr-feedback" data-trash-feedback><span class="text-secondary"><?= esc($it['company_name']) ?>’s last note:</span> “<?= esc($noteText) ?>”</p><?php endif; ?>
        </div>
        <div class="rd-actions tr-actions">
          <button type="button" class="ui-btn ui-btn--sm ui-btn--tinted" data-trash-restore>Restore</button>
          <div class="asg-more tr-more">
            <button type="button" class="ui-btn ui-btn--sm ui-btn--gray ui-btn--icon asg-more-btn" data-trash-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="<?= esc('More for ' . $label) ?>"><?= icon('ellipsis') ?></button>
            <div class="pd-menu asg-menu tr-menu" role="menu" data-trash-menu hidden>
              <button type="button" role="menuitem" class="is-destructive" data-trash-delete>Delete forever…</button>
            </div>
          </div>
        </div>
      </li>
  <?php endforeach; echo insetListClose('Restore returns an item to exactly where it was. Delete forever (behind ⋯) removes it and its files for good — you will be asked to type DELETE.'); endif; ?>
  <?php endif; ?>
</div>
<?php
$footExtra = '<script src="' . esc(staticUrl('js/trash.js')) . '" defer></script>' . "\n";
$includeSheet = true;
include __DIR__ . '/partials/layout-bottom.php';
