<?php
/**
 * Assets — Stage 1 review (spec §4.2). Replaces library.php and the tires
 * module of features.php under one tab.
 *
 *   assets.php?client=<slug>[&view=library|collections][&item=<tire id>]
 *              [&filter=pending|approved|denied][&asset=<id>&kind=library|tire]
 *
 *   view=library      the client's disk-synced library_images as a square grid
 *   view=collections  grouped list of the client's tires ("collections", labelled
 *                     with the company's own term when companies.feature_label is
 *                     set); item=<tire id> opens one collection as the same grid
 *   filter            pending (default) | approved | denied (admin only — the
 *                     client's SQL always adds AND status <> 'denied')
 *   asset + kind      deep link: open the full-screen viewer on that item
 *
 * Tapping a thumbnail opens the media viewer (partials/components/media-viewer.php
 * + App.viewer in static/js/assets.js): Approve / Deny-with-note / more, swipe to
 * navigate, auto-advance after each decision. Multi-select ("Select" in the nav
 * bar) batch-approves only — denials always need a reason.
 *
 * Role is enforced here in PHP (isAdmin()): admin-only markup is never rendered
 * for a client, and client queries exclude denied rows in SQL.
 *
 * ASSUMPTION (no schema change allowed): tires has no reference-image column, so
 * the lowest sort_order image of a collection is treated as the reference (the
 * "real tire") and pinned at the top of the collection view. Admin controls the
 * upload order in add-feature.php.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$isAdmin = isAdmin();

// ---------------------------------------------------------------------
// Scope — Assets only makes sense for one client.
// ---------------------------------------------------------------------
if (!$client) {
    if ($isAdmin) {
        header('Location: ' . clientUrl('admin.php'), true, 302);   // pick a client first
        exit;
    }
    http_response_code(400);
    $pageTitle   = 'Assets';
    $navTrailing = '';
    $showTabs    = false;
    $includeSheet = false;
    include __DIR__ . '/partials/layout-top.php';
    echo '<div class="ui-empty">This link is missing its client. Please use the review link Joust sent you.</div>';
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

$cid  = (int)$client['id'];
$slug = (string)$client['slug'];

// ---------------------------------------------------------------------
// Params
// ---------------------------------------------------------------------
$view    = (($_GET['view'] ?? 'library') === 'collections') ? 'collections' : 'library';
$itemId  = max(0, (int)($_GET['item'] ?? 0));
$filters = $isAdmin ? ['pending', 'approved', 'denied'] : ['pending', 'approved'];
$filter  = in_array($_GET['filter'] ?? '', $filters, true) ? (string)$_GET['filter'] : 'pending';

$deepId   = max(0, (int)($_GET['asset'] ?? 0));
$deepKind = in_array($_GET['kind'] ?? '', ['library', 'tire'], true) ? (string)$_GET['kind'] : '';
$deepOpen = null;      // ['kind' => …, 'id' => …] once resolved
$notice   = '';        // one-off toast for the client (e.g. deep link no longer available)

$libReady       = hasLibraryImagesTable($pdo);
$clientOnly     = $isAdmin ? '' : " AND status <> 'denied'";        // spec §2 / §7: filtered in SQL, never CSS
$clientOnlyTi   = $isAdmin ? '' : " AND ti.status <> 'denied'";
$hasDisplayName = false;
try { $hasDisplayName = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0; } catch (Throwable $e) {}
$nameSel = $hasDisplayName ? ', ti.display_name' : ", '' AS display_name";

// ---------------------------------------------------------------------
// Deep link → resolve the item's view / collection / filter first so the
// rendered grid actually contains it. A client is never pointed at a denied item.
// ---------------------------------------------------------------------
if ($deepId > 0 && $deepKind !== '') {
    $hit = null;
    if ($deepKind === 'library' && $libReady) {
        $s = $pdo->prepare("SELECT id, status FROM library_images WHERE id = ? AND company_id = ?{$clientOnly}");
        $s->execute([$deepId, $cid]);
        $hit = $s->fetch();
        if ($hit) { $view = 'library'; $itemId = 0; }
    } elseif ($deepKind === 'tire') {
        $s = $pdo->prepare("
            SELECT ti.id, ti.status, ti.tire_id
              FROM tire_images ti
              INNER JOIN tires t ON t.id = ti.tire_id
             WHERE ti.id = ? AND t.company_id = ?{$clientOnlyTi}
        ");
        $s->execute([$deepId, $cid]);
        $hit = $s->fetch();
        if ($hit) { $view = 'collections'; $itemId = (int)$hit['tire_id']; }
    }
    if ($hit && in_array($hit['status'], $filters, true)) {
        $filter   = (string)$hit['status'];
        $deepOpen = ['kind' => $deepKind, 'id' => (int)$hit['id']];
    } else {
        $notice = 'That image is no longer available to review.';
    }
}

// ---------------------------------------------------------------------
// Labels
// ---------------------------------------------------------------------
// "Collections" in the client's own words: companies.feature_label when set
// (the only per-company term in the schema), else the tires module's plural
// label, else the generic word.
$collectionsLabel = trim((string)($client['feature_label'] ?? ''));
if ($collectionsLabel === '') {
    try {
        $s = $pdo->prepare("SELECT plural_label FROM modules WHERE slug = 'tires'");
        $s->execute();
        $collectionsLabel = trim((string)($s->fetchColumn() ?: ''));
    } catch (Throwable $e) { $collectionsLabel = ''; }
}
if ($collectionsLabel === '') { $collectionsLabel = 'Collections'; }

$filterLabels = ['pending' => 'To Review', 'approved' => 'Approved', 'denied' => 'Needs changes'];

/** URL for this page with the current scope merged with $extra (null drops a key). */
if (!function_exists('assetsUrl')) {
    function assetsUrl(array $extra = []): string {
        global $view, $itemId, $filter;
        $base = ['view' => $view, 'item' => $itemId > 0 ? $itemId : null, 'filter' => $filter];
        return clientUrl('assets.php', array_merge($base, $extra));
    }
}

/** Normalised media descriptor for a library or tire row — neither table has a media_type column, so video-ness is by extension (spec §6). */
if (!function_exists('assetMediaMeta')) {
    function assetMediaMeta(string $url): array {
        $ext  = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));
        $isV  = isVideoExt($ext) || $ext === 'm4v';
        return ['type' => $isV ? 'video' : 'image', 'ext' => $ext ?: 'jpg', 'mime' => $isV ? videoMime($ext) : ''];
    }
}

// ---------------------------------------------------------------------
// Counts — the same pending definitions the tab-bar badge uses
// (tabbar.php: pending tire_images JOIN tires ON company + pending library_images).
// ---------------------------------------------------------------------
$libCounts  = ['pending' => 0, 'approved' => 0, 'denied' => 0];
$tireCounts = ['pending' => 0, 'approved' => 0, 'denied' => 0];
if ($libReady) {
    if ($view === 'library') {
        syncLibraryImages($pdo, $cid, $slug);   // register new files dropped in media/library/<slug>/ (once per request)
    }
    $s = $pdo->prepare("SELECT status, COUNT(*) AS n FROM library_images WHERE company_id = ? GROUP BY status");
    $s->execute([$cid]);
    foreach ($s->fetchAll() as $r) { if (isset($libCounts[$r['status']])) $libCounts[$r['status']] = (int)$r['n']; }
}
$s = $pdo->prepare("
    SELECT ti.status, COUNT(*) AS n
      FROM tire_images ti
      INNER JOIN tires t ON t.id = ti.tire_id
     WHERE t.company_id = ?
     GROUP BY ti.status
");
$s->execute([$cid]);
foreach ($s->fetchAll() as $r) { if (isset($tireCounts[$r['status']])) $tireCounts[$r['status']] = (int)$r['n']; }

// ---------------------------------------------------------------------
// Data for the current view
// ---------------------------------------------------------------------
$items       = [];      // grid tiles (normalised)
$scopeCounts = $libCounts;
$collection  = null;    // tires row when a collection is open
$reference   = null;    // pinned reference image for the open collection
$collections = [];      // list rows (collections view without item)

if ($view === 'library') {
    if ($libReady) {
        $sql = "SELECT id, filename, status, created_at, updated_at
                  FROM library_images
                 WHERE company_id = ?{$clientOnly} AND status = ?
                 ORDER BY filename ASC";
        $s = $pdo->prepare($sql);
        $s->execute([$cid, $filter]);
        $onDisk = array_flip(scanLibraryDir(libraryDir($slug)));   // a row whose file vanished from the folder is not shown
        foreach ($s->fetchAll() as $r) {
            if (!isset($onDisk[$r['filename']])) continue;
            $url  = libraryFileUrl($slug, $r['filename']);
            $meta = assetMediaMeta($r['filename']);
            $items[] = [
                'id'       => (int)$r['id'],
                'kind'     => 'library',
                'status'   => (string)$r['status'],
                'src'      => $url,
                'type'     => $meta['type'],
                'mime'     => $meta['mime'],
                'label'    => 'Library image',               // never the raw filename in the UI
                'download' => (string)$r['filename'],
                'manage'   => '',
            ];
        }
    }
} else {
    if ($itemId > 0) {
        $s = $pdo->prepare("SELECT id, name FROM tires WHERE id = ? AND company_id = ?");
        $s->execute([$itemId, $cid]);
        $collection = $s->fetch() ?: null;
        if (!$collection) {
            $itemId = 0;
            $notice = $notice ?: 'That collection is no longer available.';
        }
    }

    if ($collection) {
        $scopeCounts = ['pending' => 0, 'approved' => 0, 'denied' => 0];
        $s = $pdo->prepare("SELECT status, COUNT(*) AS n FROM tire_images WHERE tire_id = ? GROUP BY status");
        $s->execute([$itemId]);
        foreach ($s->fetchAll() as $r) { if (isset($scopeCounts[$r['status']])) $scopeCounts[$r['status']] = (int)$r['n']; }

        // Reference = lowest sort_order image (see the ASSUMPTION at the top). Clients never see a denied one.
        $s = $pdo->prepare("SELECT id, image_url, status FROM tire_images WHERE tire_id = ?{$clientOnly} ORDER BY sort_order ASC, id ASC LIMIT 1");
        $s->execute([$itemId]);
        $reference = $s->fetch() ?: null;

        $sql = "SELECT ti.id, ti.tire_id, ti.image_url, ti.caption, ti.status, ti.sort_order{$nameSel}, t.name AS tire_name
                  FROM tire_images ti
                  INNER JOIN tires t ON t.id = ti.tire_id
                 WHERE t.company_id = ? AND ti.tire_id = ?{$clientOnlyTi} AND ti.status = ?
                 ORDER BY ti.sort_order ASC, ti.id ASC";
        $s = $pdo->prepare($sql);
        $s->execute([$cid, $itemId, $filter]);
        $n = 0;
        foreach ($s->fetchAll() as $r) {
            $n++;
            $meta  = assetMediaMeta((string)$r['image_url']);
            $label = trim((string)($r['display_name'] ?: ($r['caption'] ?: '')));
            if ($label === '') { $label = (string)$collection['name'] . ' · ' . $n; }
            $stem  = safeFilenameStem($r['display_name'] ?: ($collection['name'] . '-' . $n));
            $items[] = [
                'id'       => (int)$r['id'],
                'kind'     => 'tire',
                'status'   => (string)$r['status'],
                'src'      => basePath() . '/' . ltrim((string)$r['image_url'], '/'),
                'type'     => $meta['type'],
                'mime'     => $meta['mime'],
                'label'    => $label,
                'download' => ($stem !== '' ? $stem : 'image') . '.' . $meta['ext'],
                'manage'   => $isAdmin ? clientUrl('add-feature.php', ['module' => 'tires', 'edit_item' => $itemId]) : '',
            ];
        }
    } else {
        $scopeCounts = $tireCounts;
        $s = $pdo->prepare("
            SELECT t.id, t.name,
                   SUM(CASE WHEN ti.status = 'pending'  THEN 1 ELSE 0 END) AS pending_count,
                   SUM(CASE WHEN ti.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                   SUM(CASE WHEN ti.status = 'denied'   THEN 1 ELSE 0 END) AS denied_count,
                   COUNT(ti.id) AS total_count
              FROM tires t
              LEFT JOIN tire_images ti ON ti.tire_id = t.id
             WHERE t.company_id = ?
             GROUP BY t.id, t.name
             ORDER BY t.name ASC
        ");
        $s->execute([$cid]);
        $collections = $s->fetchAll();

        // 56px thumbnail = the reference image (lowest sort_order; never a denied one for clients)
        $thumbs = [];
        if ($collections) {
            $ids = array_map('intval', array_column($collections, 'id'));
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT tire_id, image_url FROM tire_images WHERE tire_id IN ($ph){$clientOnly} ORDER BY tire_id, sort_order ASC, id ASC");
            $s->execute($ids);
            foreach ($s->fetchAll() as $r) {
                $tid = (int)$r['tire_id'];
                if (!isset($thumbs[$tid])) $thumbs[$tid] = basePath() . '/' . ltrim((string)$r['image_url'], '/');
            }
        }
    }
}

$isGrid       = ($view === 'library') || ($view === 'collections' && $collection);
$pendingTotal = $libCounts['pending'] + $tireCounts['pending'];   // = the tab-bar badge

// ---------------------------------------------------------------------
// Chrome
// ---------------------------------------------------------------------
$pageTitle  = 'Assets';
$htmlTitle  = 'Assets — ' . $client['name'];
$pageWide   = true;
$navWide    = true;
$activeTab  = 'assets';
$bodyClass  = 'as-body';
$headExtra  = '<link rel="stylesheet" href="' . esc(staticUrl('css/assets.css')) . '">';
if ($collection) {
    $pageTitle   = (string)$collection['name'];
    $htmlTitle   = $collection['name'] . ' — Assets — ' . $client['name'];
    $navSubtitle = $collectionsLabel;
    $navBack     = ['href' => clientUrl('assets.php', ['view' => 'collections']), 'label' => $collectionsLabel];
}
$navTrailing = '';
if ($isGrid && $items) {
    $navTrailing .= '<button type="button" class="ui-btn ui-btn--sm ui-btn--gray" data-assets-select aria-pressed="false">Select</button>';
}
if ($collection) {
    // Pushed detail screen: back button + inline-sized title; the avatar would crowd the tire name on a phone.
    $bodyClass .= ' as-body--collection';
} else {
    $navTrailing .= clientAvatar($client);
}

include __DIR__ . '/partials/layout-top.php';
?>

<div class="as-controls">
  <?= segmented([
      ['label' => 'Library', 'href' => clientUrl('assets.php', ['view' => 'library']),
       'active' => $view === 'library', 'count' => $libCounts['pending'] > 0 ? $libCounts['pending'] : null],
      ['label' => $collectionsLabel, 'href' => clientUrl('assets.php', ['view' => 'collections']),
       'active' => $view === 'collections', 'count' => $tireCounts['pending'] > 0 ? $tireCounts['pending'] : null],
  ], ['label' => 'Assets view']) ?>

  <?php if ($isGrid): ?>
    <nav class="as-filters" aria-label="Filter">
      <?php foreach ($filters as $f): ?>
        <a class="as-chip<?= $f === $filter ? ' is-active' : '' ?>" href="<?= esc(assetsUrl(['filter' => $f])) ?>"<?= $f === $filter ? ' aria-current="page"' : '' ?>>
          <?= esc($filterLabels[$f]) ?><span class="as-chip-count" data-count="<?= esc($f) ?>"><?= (int)$scopeCounts[$f] ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
</div>

<?php if ($view === 'collections' && !$collection): ?>

  <?php if (!$collections): ?>
    <div class="ui-empty">No <?= esc(strtolower($collectionsLabel)) ?> yet.</div>
  <?php else: ?>
    <?= insetListOpen('', ['class' => 'as-collections', 'listClass' => 'as-collection-list']) ?>
    <?php foreach ($collections as $c):
        $tid = (int)$c['id'];
        $p = (int)$c['pending_count']; $a = (int)$c['approved_count']; $d = (int)$c['denied_count'];
        $parts = [$p . ' to review', $a . ' approved'];
        if ($isAdmin && $d > 0) $parts[] = $d . ' needs changes';       // client counts exclude denied
        if ((int)$c['total_count'] === 0) $parts = ['No images yet'];
        $thumb = isset($thumbs[$tid])
            ? '<img src="' . esc($thumbs[$tid]) . '" alt="" loading="lazy">'
            : icon('photo');
        echo insetRow([
            'href'     => clientUrl('assets.php', ['view' => 'collections', 'item' => $tid]),
            'leading'  => $thumb,
            'title'    => (string)$c['name'],
            'subtitle' => implode(' · ', $parts),
            'trailing' => $p > 0 ? '<span class="ui-badge">' . ($p > 99 ? '99+' : $p) . '</span>' : '',
            'chevron'  => true,
            'attrs'    => ['id' => 'collection-' . $tid, 'data-collection' => $tid],
        ]);
    endforeach; ?>
    <?= insetListClose() ?>
  <?php endif; ?>

<?php else: ?>

  <?php if ($collection): ?>
    <section class="as-reference" aria-label="Reference image">
      <?php if ($reference): ?>
        <div class="as-reference-media"><img src="<?= esc(basePath() . '/' . ltrim((string)$reference['image_url'], '/')) ?>" alt="<?= esc('Reference for ' . $collection['name']) ?>"></div>
      <?php else: ?>
        <div class="as-reference-media as-reference-media--empty"><?= icon('photo') ?></div>
      <?php endif; ?>
      <div class="as-reference-body">
        <p class="as-reference-label">Reference</p>
        <h2 class="as-reference-title"><?= esc($collection['name']) ?></h2>
        <p class="as-reference-hint"><?= $reference ? 'Compare each render to this image.' : 'No reference image yet.' ?></p>
      </div>
      <?php if ($isAdmin): // admin-only: never rendered for clients ?>
        <div class="as-reference-admin">
          <a class="ui-btn ui-btn--sm ui-btn--gray" href="<?= esc(clientUrl('add-feature.php', ['module' => 'tires', 'edit_item' => $itemId])) ?>">Edit</a>
          <button type="button" class="ui-btn ui-btn--sm ui-btn--deny ui-btn--tinted"
                  data-action="delete_tire" data-endpoint="<?= esc(basePath() . '/tire-status.php') ?>"
                  data-param-action="delete_tire" data-tire-id="<?= $itemId ?>"
                  data-confirm="<?= esc('Delete “' . $collection['name'] . '” and all of its images? This cannot be undone.') ?>"
                  data-href="<?= esc(clientUrl('assets.php', ['view' => 'collections'])) ?>">Delete</button>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if (!$items): ?>
    <div class="ui-empty as-empty">
      <?php if ($filter === 'pending'): ?>
        <?= icon('checkmark', 'as-empty-icon') ?><p class="as-empty-title">All caught up</p><p>Nothing to review here right now.</p>
      <?php elseif ($filter === 'approved'): ?>
        <p>No approved images yet.</p>
      <?php else: ?>
        <p>Nothing needs changes.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="ui-grid as-grid" id="assetsGrid" role="list"
         data-filter="<?= esc($filter) ?>" data-scope="<?= $collection ? 'tire' : 'library' ?>">
      <?php $total = count($items); foreach ($items as $i => $it):
        $endpoint = basePath() . ($it['kind'] === 'tire' ? '/tire-status.php' : '/library-status.php');
        $cls = 'ui-thumb as-thumb' . ($it['status'] === 'approved' ? ' ui-thumb--approved' : '');
      ?>
        <button type="button" class="<?= esc($cls) ?>" role="listitem"
                id="<?= esc(($it['kind'] === 'tire' ? 'image-' : 'lib-') . $it['id']) ?>"
                data-asset data-id="<?= (int)$it['id'] ?>" data-kind="<?= esc($it['kind']) ?>" data-status="<?= esc($it['status']) ?>"
                data-src="<?= esc($it['src']) ?>" data-type="<?= esc($it['type']) ?>"<?= $it['mime'] !== '' ? ' data-mime="' . esc($it['mime']) . '"' : '' ?>
                data-label="<?= esc($it['label']) ?>" data-download="<?= esc($it['download']) ?>"
                data-endpoint="<?= esc($endpoint) ?>"<?= $it['manage'] !== '' ? ' data-manage="' . esc($it['manage']) . '"' : '' ?>
                aria-label="<?= esc('Open ' . $it['label'] . ', ' . ($i + 1) . ' of ' . $total) ?>">
          <?php if ($it['type'] === 'video'): ?>
            <?= videoTile($it['src'], ['badge' => false]) /* poster when App.video has one cached, else dark tile + play glyph */ ?>
          <?php else: ?>
            <img src="<?= esc($it['src']) ?>" alt="" loading="lazy" decoding="async">
          <?php endif; ?>
          <span class="ui-pill ui-pill--glass ui-pill--nodot ui-thumb-badge as-badge">
            <i class="ui-dot ui-dot--<?= esc($it['status']) ?>" data-status-dot></i>
            <?php if ($it['type'] === 'video'): ?>
              <?= videoDurationBadge('as-badge-video') ?>
            <?php endif; ?>
          </span>
          <span class="as-thumb-check" aria-hidden="true"><?= icon('checkmark') ?></span>
          <span class="as-thumb-select" aria-hidden="true"><?= icon('checkmark') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php if ($isGrid && $items): ?>
  <div class="as-selectbar ui-glass ui-glass--top" data-assets-selectbar hidden>
    <span class="as-selectbar-count" data-select-count>0 selected</span>
    <button type="button" class="ui-btn ui-btn--approve" data-select-approve disabled><?= icon('checkmark') ?>Approve</button>
  </div>
<?php endif; ?>

<?php
// Full-screen viewer (hidden until App.viewer.open). Admin-only controls are
// rendered inside the partial only when isAdmin().
$viewerAdmin = $isAdmin;
include __DIR__ . '/partials/components/media-viewer.php';

$assetsConfig = [
    'view'      => $view,
    'filter'    => $filter,
    'item'      => $itemId,
    'mode'      => $filter === 'pending' ? 'review' : 'browse',   // review: auto-advance targets pending items only
    'isAdmin'   => $isAdmin,
    'open'      => $deepOpen,
    'notice'    => $notice,
    'endpoints' => [
        'library' => basePath() . '/library-status.php',
        'tire'    => basePath() . '/tire-status.php',
        'replace' => basePath() . '/replace-image.php',
    ],
    'labels'    => ['collections' => $collectionsLabel],
];
$footExtra = '<script>window.AssetsPage = ' . json_encode($assetsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) . ';</script>' . "\n"
           . '<script src="' . esc(staticUrl('js/assets.js')) . '" defer></script>' . "\n";
$includeSheet = false;   // the viewer is its own overlay; no generic sheet on this page
include __DIR__ . '/partials/layout-bottom.php';
