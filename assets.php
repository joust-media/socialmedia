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
 *
 * Tire series (tire-series-lib.php, feature-gated by hasTireSeries()):
 *   &series=<id>|ref   which series of the open collection the grid shows
 *                      (ref = the reference images, i.e. rows without a series);
 *                      default = the first series with pending images, else Reference
 *   &image=<id>        deep link alias of asset=<id>&kind=tire (the image's own
 *                      series wins over an inconsistent &series=)
 *   &offset=<n>        paging: the grid renders ASSETS_PAGE tiles from that offset
 *   &partial=1         answer with the tile markup only (the "Load more" fetch)
 *   &partial=comments&kind=tire|library&id=<image id>
 *                      JSON {ok, kind, id, count, html}: the image's comment thread
 *                      (commentThreadHtml() over commentThread(): deny notes and plain
 *                      comments, client vs Joust styling) for the viewer's Comments
 *                      panel, fetched lazily per image. Tenant-checked like the grid.
 *   &rescan=1          ask syncTireSeries() to rescan media/tires/<tire>/ now
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/partials/components/comment-thread.php';   // commentThreadHtml() for the viewer's Comments panel
if (is_file(__DIR__ . '/tire-series-lib.php')) { require_once __DIR__ . '/tire-series-lib.php'; }

$isAdmin  = isAdmin();
$seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);   // migration-gated feature
if (!defined('ASSETS_PAGE')) { define('ASSETS_PAGE', 60); }              // tiles per page (200-file series must not reflow)

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
if ($deepId === 0 && (int)($_GET['image'] ?? 0) > 0) { $deepId = (int)$_GET['image']; $deepKind = 'tire'; }   // &image= alias
$deepOpen = null;      // ['kind' => …, 'id' => …] once resolved
$notice   = '';        // one-off toast for the client (e.g. deep link no longer available)

// Series (validated: a positive int or the literal 'ref'; anything else = "not given")
$seriesReq = null;
if (($_GET['series'] ?? '') === 'ref') { $seriesReq = 'ref'; }
elseif (ctype_digit((string)($_GET['series'] ?? '')) && (int)$_GET['series'] > 0) { $seriesReq = (int)$_GET['series']; }
$partial = (($_GET['partial'] ?? '') === '1');
$offset  = max(0, (int)($_GET['offset'] ?? 0));

$libReady       = hasLibraryImagesTable($pdo);
$clientOnly     = $isAdmin ? '' : " AND status <> 'denied'";        // spec §2 / §7: filtered in SQL, never CSS
$clientOnlyTi   = $isAdmin ? '' : " AND ti.status <> 'denied'";
$hasDisplayName = false;
try { $hasDisplayName = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0; } catch (Throwable $e) {}
$nameSel   = $hasDisplayName ? ', ti.display_name' : ", '' AS display_name";
$seriesSel = $seriesOn ? ', ti.series_id' : ", NULL AS series_id";   // tire_images.series_id (NULL = reference image) — tire-series-lib.php migration
$hasLog    = hasActivityLog($pdo);

// ---------------------------------------------------------------------
// &partial=comments — the viewer's Comments panel: one image's thread as JSON.
// Same tenant rules as the grid (the company of the page's client, and a client
// seat never sees a denied image), so the answer is 404 for anything else.
// ---------------------------------------------------------------------
if (($_GET['partial'] ?? '') === 'comments') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $cKind = in_array($_GET['kind'] ?? '', ['library', 'tire'], true) ? (string)$_GET['kind'] : '';
    $cId   = max(0, (int)($_GET['id'] ?? 0));
    $hit   = null;
    if ($cKind === 'library' && $cId > 0 && $libReady) {
        $s = $pdo->prepare("SELECT id FROM library_images WHERE id = ? AND company_id = ?{$clientOnly}");
        $s->execute([$cId, $cid]);
        $hit = $s->fetch();
    } elseif ($cKind === 'tire' && $cId > 0) {
        $s = $pdo->prepare("SELECT ti.id FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.id = ? AND t.company_id = ?{$clientOnlyTi}");
        $s->execute([$cId, $cid]);
        $hit = $s->fetch();
    }
    if (!$hit) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Image not found']);
        exit;
    }
    $rows = $hasLog ? commentThread($pdo, $cKind === 'tire' ? 'tire_image' : 'library_image', $cId) : [];
    $rows = array_values(array_filter($rows, static function ($r) { return trim((string)($r['detail'] ?? '')) !== ''; }));
    echo json_encode([
        'ok'    => true,
        'kind'  => $cKind,
        'id'    => $cId,
        'count' => count($rows),
        'html'  => commentThreadHtml($rows, ['empty' => 'No comments yet.', 'class' => 'ui-viewer-thread-list']),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

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
            SELECT ti.id, ti.status, ti.tire_id{$seriesSel}
              FROM tire_images ti
              INNER JOIN tires t ON t.id = ti.tire_id
             WHERE ti.id = ? AND t.company_id = ?{$clientOnlyTi}
        ");
        $s->execute([$deepId, $cid]);
        $hit = $s->fetch();
        if ($hit) {
            $view = 'collections'; $itemId = (int)$hit['tire_id'];
            if ($seriesOn) { $seriesReq = !empty($hit['series_id']) ? (int)$hit['series_id'] : 'ref'; }   // the image's own series wins
        }
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
        global $view, $itemId, $filter, $seriesKey;
        $base = ['view' => $view, 'item' => $itemId > 0 ? $itemId : null, 'filter' => $filter,
                 'series' => (isset($seriesKey) && $seriesKey !== '' && $itemId > 0) ? $seriesKey : null];
        return clientUrl('assets.php', array_merge($base, $extra));
    }
}

/** Root-rooted URL for whatever tireImageSrc()/tireImageThumb() hand back (root-rooted, absolute, or app-relative 'uploads/…'). */
if (!function_exists('assetsRootUrl')) {
    function assetsRootUrl(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        if ($url[0] === '/' || preg_match('#^(https?:)?//#i', $url)) return $url;
        return basePath() . '/' . ltrim($url, '/');
    }
}

/**
 * One grid tile. $index is the absolute position (offset + i) so aria-labels stay
 * "n of N" across pages; the same markup is answered by &partial=1 for "Load more".
 */
if (!function_exists('assetsTileHtml')) {
    function assetsTileHtml(array $it, int $index, int $total): string {
        $endpoint = basePath() . ($it['kind'] === 'tire' ? '/tire-status.php' : '/library-status.php');
        $cls   = 'ui-thumb as-thumb' . ($it['status'] === 'approved' ? ' ui-thumb--approved' : '');
        $thumb = ($it['thumb'] ?? '') !== '' ? (string)$it['thumb'] : (string)$it['src'];
        $out  = '<button type="button" class="' . esc($cls) . '" role="listitem"'
              . ' id="' . esc(($it['kind'] === 'tire' ? 'image-' : 'lib-') . $it['id']) . '"'
              . ' data-asset data-id="' . (int)$it['id'] . '" data-kind="' . esc($it['kind']) . '" data-status="' . esc($it['status']) . '"'
              . ' data-src="' . esc($it['src']) . '" data-type="' . esc($it['type']) . '"' . ($it['mime'] !== '' ? ' data-mime="' . esc($it['mime']) . '"' : '')
              . ' data-label="' . esc($it['label']) . '" data-download="' . esc($it['download']) . '"'
              . ' data-endpoint="' . esc($endpoint) . '"' . ($it['manage'] !== '' ? ' data-manage="' . esc($it['manage']) . '"' : '')
              . (!empty($it['twin']) ? ' data-twin="' . esc($it['twin']) . '"' : '')     // transcoded .mp4 next to a .mov → second <source> in the viewer
              . (isset($it['series']) && $it['series'] !== '' ? ' data-series="' . esc((string)$it['series']) . '"' : '')
              . ' data-comments="' . (int)($it['comments'] ?? 0) . '"'
              . ' aria-label="' . esc('Open ' . $it['label'] . ', ' . $index . ' of ' . $total . (!empty($it['comments']) ? ', ' . (int)$it['comments'] . ($it['comments'] === 1 ? ' comment' : ' comments') : '')) . '">';
        $nc = (int)($it['comments'] ?? 0);
        if ($it['type'] === 'video') {
            $out .= videoTile($it['src'], ['badge' => false, 'poster' => ($it['thumb'] ?? '') !== '' && $it['thumb'] !== $it['src'] ? $it['thumb'] : '']);   // poster when the lib made one, else dark tile + play glyph
        } else {
            $out .= '<img src="' . esc($thumb) . '" alt="" loading="lazy" decoding="async">';   // .ui-thumb is a fixed 1:1 box, so lazy tiles never reflow
        }
        $out .= '<span class="ui-pill ui-pill--glass ui-pill--nodot ui-thumb-badge as-badge"><i class="ui-dot ui-dot--' . esc($it['status']) . '" data-status-dot></i>'
              . ($it['type'] === 'video' ? videoDurationBadge('as-badge-video') : '') . '</span>'
              . '<span class="as-thumb-check" aria-hidden="true">' . icon('checkmark') . '</span>'
              . '<span class="as-thumb-select" aria-hidden="true">' . icon('checkmark') . '</span>'
              // Comment-count bubble (top-left; hidden at 0 so assets.js can reveal it after the first comment)
              . '<span class="ui-pill ui-pill--glass ui-pill--nodot as-thumb-comments" data-thumb-comments' . ($nc > 0 ? '' : ' hidden') . ' aria-hidden="true">'
              . icon('bubble') . '<span data-thumb-comments-count>' . $nc . '</span></span>'
              . '</button>';
        return $out;
    }
}

/** "12 to review · 40 approved[ · 3 needs changes]" (denied only for admin — client counts never mention it). */
if (!function_exists('assetsCountsLine')) {
    function assetsCountsLine(array $c, bool $admin, bool $withTotal = false): string {
        $p = (int)($c['pending'] ?? 0); $a = (int)($c['approved'] ?? 0); $d = (int)($c['denied'] ?? 0);
        $parts = [$p . ' to review', $a . ' approved'];
        if ($admin && $d > 0) $parts[] = $d . ' needs changes';
        if ($withTotal) { $t = (int)($c['total'] ?? ($p + $a + $d)); $parts[] = $t . ($t === 1 ? ' file' : ' files'); }
        return implode(' · ', $parts);
    }
}

/** Normalised media descriptor for a library or tire row — neither table has a media_type column, so video-ness is by extension (spec §6). */
if (!function_exists('assetMediaMeta')) {
    function assetMediaMeta(string $url): array {
        $ext  = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));
        $isV  = isVideoExt($ext);
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
$seriesList  = [];      // tireSeriesForTire() rows for the open collection
$seriesActive = null;   // the series the grid shows (null = Reference)
$seriesKey   = '';      // 'ref' | '<id>' — the &series= value of the current view ('' when the feature is off)
$seriesSummary = [];    // collections list: tire id → ['series' => n, 'pending' => n]
$gridTotal   = 0;       // rows in the current filter/series (paging)

if ($view === 'collections' && $seriesOn) {
    // Register files dropped by FTP into media/tires/<tire>/<series>/: the throttled hook (folder mtimes + 60 s,
    // tire-series-lib.php) on every collections view; &rescan=1 (admin, the Studio "Rescan folders" button) forces a scan.
    try {
        if ($isAdmin && isset($_GET['rescan'])) { syncTireSeries($pdo, $client, $itemId > 0 ? $itemId : null, ['thumbs' => true]); }
        elseif (function_exists('tireSeriesSyncThrottled')) { tireSeriesSyncThrottled($pdo, $client); }
        else { syncTireSeries($pdo, $client, null, ['thumbs' => true]); }
    } catch (Throwable $e) { error_log('tire series sync failed: ' . $e->getMessage()); }
}

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
                'twin'     => $meta['type'] === 'video' ? videoTwinUrl($url, libraryDir($slug) . '/' . $r['filename']) : '',
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
        // With series on, the reference is always one of the tire's own (series-less) images.
        $refOnly = $seriesOn ? ' AND series_id IS NULL' : '';
        $s = $pdo->prepare("SELECT id, image_url, status FROM tire_images WHERE tire_id = ?{$clientOnly}{$refOnly} ORDER BY sort_order ASC, id ASC LIMIT 1");
        $s->execute([$itemId]);
        $reference = $s->fetch() ?: null;

        if ($seriesOn) {
            // ---- Series switcher: which series does the grid show? --------------------------------
            $seriesList = tireSeriesForTire($pdo, $itemId);   // [{id,name,slug,folder,sort_order,counts:{pending,approved,denied,total}}]
            if (is_int($seriesReq)) {
                foreach ($seriesList as $sr) { if ((int)$sr['id'] === $seriesReq) { $seriesActive = $sr; break; } }
                if (!$seriesActive) { $seriesReq = null; $notice = $notice ?: 'That series is no longer available.'; }
            }
            if ($seriesReq === null) {   // default: the first series that still has something to review, else Reference
                foreach ($seriesList as $sr) { if ((int)($sr['counts']['pending'] ?? 0) > 0) { $seriesActive = $sr; break; } }
            }
            $seriesKey = $seriesActive ? (string)(int)$seriesActive['id'] : 'ref';
            // Reference counts (rows without a series) from the lib's one GROUP BY (tireSeriesCounts()['reference']).
            $tc = tireSeriesCounts($pdo, $itemId);
            $refCounts = ['pending' => 0, 'approved' => 0, 'denied' => 0, 'total' => 0];
            foreach ($refCounts as $k => $v) { $refCounts[$k] = (int)($tc['reference'][$k] ?? 0); }
            $scopeCounts = $seriesActive
                ? ['pending' => (int)($seriesActive['counts']['pending'] ?? 0), 'approved' => (int)($seriesActive['counts']['approved'] ?? 0), 'denied' => (int)($seriesActive['counts']['denied'] ?? 0)]
                : ['pending' => $refCounts['pending'], 'approved' => $refCounts['approved'], 'denied' => $refCounts['denied']];
            $gridTotal = (int)$scopeCounts[$filter];

            $rows = tireImagesForSeries($pdo, $itemId, $seriesActive ? (int)$seriesActive['id'] : null,
                                        ['status' => $filter, 'client' => !$isAdmin, 'company_id' => $cid, 'limit' => ASSETS_PAGE, 'offset' => $offset]);
        } else {
            $gridTotal = (int)$scopeCounts[$filter];
            $sql = "SELECT ti.id, ti.tire_id, ti.image_url, ti.caption, ti.status, ti.sort_order{$nameSel}, t.name AS tire_name
                      FROM tire_images ti
                      INNER JOIN tires t ON t.id = ti.tire_id
                     WHERE t.company_id = ? AND ti.tire_id = ?{$clientOnlyTi} AND ti.status = ?
                     ORDER BY ti.sort_order ASC, ti.id ASC
                     LIMIT " . (int)ASSETS_PAGE . " OFFSET " . (int)$offset;
            $s = $pdo->prepare($sql);
            $s->execute([$cid, $itemId, $filter]);
            $rows = $s->fetchAll();
        }
        $n = $offset;
        foreach ($rows as $r) {
            $n++;
            if ($seriesOn) {
                $src   = assetsRootUrl((string)tireImageSrc($r));
                $thumb = assetsRootUrl((string)tireImageThumb($r));
                $meta  = assetMediaMeta((string)($r['image_url'] ?? $src));
                $twin  = $meta['type'] === 'video' ? videoTwinUrl($src, function_exists('tireImagePath') ? tireImagePath($r) : null) : '';
            } else {
                $src   = basePath() . '/' . ltrim((string)$r['image_url'], '/');
                $thumb = $src;
                $meta  = assetMediaMeta((string)$r['image_url']);
                $twin  = $meta['type'] === 'video' ? videoTwinUrl($src) : '';
            }
            $label = trim((string)(($r['display_name'] ?? '') ?: (($r['caption'] ?? '') ?: '')));
            if ($label === '') { $label = (string)$collection['name'] . ($seriesActive ? ' · ' . $seriesActive['name'] : '') . ' · ' . $n; }
            $stem  = safeFilenameStem(($r['display_name'] ?? '') ?: ($collection['name'] . '-' . $n));
            $items[] = [
                'id'       => (int)$r['id'],
                'kind'     => 'tire',
                'status'   => (string)$r['status'],
                'src'      => $src,
                'thumb'    => $thumb,
                'type'     => $meta['type'],
                'mime'     => $meta['mime'],
                'label'    => $label,
                'download' => ($stem !== '' ? $stem : 'image') . '.' . $meta['ext'],
                'manage'   => $isAdmin ? clientUrl('add-feature.php', ['module' => 'tires', 'edit_item' => $itemId]) : '',
                'twin'     => $twin,
                'series'   => $seriesKey,
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
        // Clients never see denied items, so a collection with nothing else in it would only open onto the
        // empty state — skip it. The admin list keeps every collection (with its needs-changes count).
        if (!$isAdmin) {
            $collections = array_values(array_filter($collections, static function ($c) {
                return ((int)$c['total_count'] - (int)$c['denied_count']) > 0;
            }));
        }

        // 56px thumbnail = the reference image (lowest sort_order; never a denied one for clients)
        $thumbs = [];
        if ($collections) {
            $ids = array_map('intval', array_column($collections, 'id'));
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $refOnly = $seriesOn ? ' AND series_id IS NULL' : '';
            $s = $pdo->prepare("SELECT tire_id, image_url FROM tire_images WHERE tire_id IN ($ph){$clientOnly}{$refOnly} ORDER BY tire_id, sort_order ASC, id ASC");
            $s->execute($ids);
            foreach ($s->fetchAll() as $r) {
                $tid = (int)$r['tire_id'];
                if (!isset($thumbs[$tid])) $thumbs[$tid] = $seriesOn ? assetsRootUrl((string)tireImageThumb($r)) : basePath() . '/' . ltrim((string)$r['image_url'], '/');   // a reference promoted from a series lives under /media/tires/
            }
            // Series summary per tire ("3 series · 24 to review") — one lib call per collection (a handful of tires).
            if ($seriesOn) {
                foreach ($ids as $tid) {
                    $list = tireSeriesForTire($pdo, $tid);
                    $sum  = ['series' => count($list), 'pending' => 0];
                    foreach ($list as $sr) { $sum['pending'] += (int)($sr['counts']['pending'] ?? 0); }
                    $seriesSummary[$tid] = $sum;
                }
            }
        }
    }
}

// Comment-count bubbles: ONE grouped query for the page of tiles (a grid holds a single kind).
if ($items && $hasLog) {
    $ids = array_map(static function ($it) { return (int)$it['id']; }, $items);
    $nc  = commentCounts($pdo, $items[0]['kind'] === 'tire' ? 'tire_image' : 'library_image', $ids);
    foreach ($items as &$it) { $it['comments'] = (int)($nc[(int)$it['id']] ?? 0); }
    unset($it);
}

$isGrid       = ($view === 'library') || ($view === 'collections' && $collection);
$pendingTotal = $libCounts['pending'] + $tireCounts['pending'];   // = the Assets + Tires tab badges (split by partials/tabbar.php)
$hasMore      = $isGrid && ($offset + count($items)) < $gridTotal;

// ---------------------------------------------------------------------
// &partial=1 — the "Load more" fetch: tile markup only, nothing else.
// ---------------------------------------------------------------------
if ($partial) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Assets-Total: ' . (int)$gridTotal);
    header('X-Assets-Next: ' . ($hasMore ? (string)($offset + count($items)) : ''));
    foreach ($items as $i => $it) { echo assetsTileHtml($it, $offset + $i + 1, max($gridTotal, $offset + count($items))), "\n"; }
    exit;
}

// ---------------------------------------------------------------------
// Chrome
// ---------------------------------------------------------------------
$pageTitle  = 'Assets';
$htmlTitle  = 'Assets — ' . $client['name'];
$pageWide   = true;
$navWide    = true;
$activeTab  = $view === 'collections' ? 'tires' : 'assets';   // Tires tab (tabbar falls back to Assets when the company has none)
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
        if (!empty($seriesSummary[$tid]['series'])) {                    // "3 series · 24 to review · 40 approved"
            array_unshift($parts, (int)$seriesSummary[$tid]['series'] . ' series');
        }
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
        <div class="as-reference-media"><img src="<?= esc($seriesOn ? assetsRootUrl((string)tireImageSrc($reference)) : basePath() . '/' . ltrim((string)$reference['image_url'], '/')) ?>" alt="<?= esc('Reference for ' . $collection['name']) ?>"></div>
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

    <?php if ($seriesOn && $seriesList): // ---- series switcher (Reference · Series 1 · Series 2 …) + header row ---- ?>
      <nav class="as-filters as-series" aria-label="Series" data-series-switcher>
        <?php
          $chips = [['key' => 'ref', 'label' => 'Reference', 'pending' => (int)$refCounts['pending']]];
          foreach ($seriesList as $sr) { $chips[] = ['key' => (string)(int)$sr['id'], 'label' => (string)$sr['name'], 'pending' => (int)($sr['counts']['pending'] ?? 0)]; }
          foreach ($chips as $ch): $on = $ch['key'] === $seriesKey; ?>
          <a class="as-chip as-series-chip<?= $on ? ' is-active' : '' ?>" href="<?= esc(assetsUrl(['series' => $ch['key'], 'offset' => null])) ?>"
             data-series-chip="<?= esc($ch['key']) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
            <?= esc($ch['label']) ?><span class="as-chip-count as-chip-count--pending" data-series-pending="<?= esc($ch['key']) ?>"<?= $ch['pending'] > 0 ? '' : ' hidden' ?>><?= $ch['pending'] ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <?php
        $headCounts = $seriesActive ? ($seriesActive['counts'] ?? []) + ['total' => 0] : $refCounts;
        $headPending = (int)($headCounts['pending'] ?? 0);
        $studioUploadUrl = clientUrl('studio.php', ['tab' => 'renders', 'tire' => $itemId, 'series' => $seriesActive ? (int)$seriesActive['id'] : null]);
      ?>
      <section class="as-series-head" data-series-head data-series-id="<?= esc($seriesKey) ?>" aria-label="<?= esc($seriesActive ? $seriesActive['name'] : 'Reference images') ?>">
        <div class="as-series-body">
          <h2 class="as-series-title" data-series-title><?= esc($seriesActive ? $seriesActive['name'] : 'Reference images') ?></h2>
          <p class="as-series-meta" data-series-meta><?= esc(assetsCountsLine($headCounts, $isAdmin, true)) ?></p>
        </div>
        <div class="as-series-actions">
          <?php if ($seriesActive && $headPending > 0): // client + admin: approve every remaining pending render of this series ?>
            <button type="button" class="ui-btn ui-btn--sm ui-btn--approve ui-btn--tinted as-series-approve"
                    data-action="approve_series" data-endpoint="<?= esc(basePath() . '/tire-status.php') ?>"
                    data-param-action="approve_series" data-series-id="<?= (int)$seriesActive['id'] ?>"
                    data-confirm="<?= esc('Approve all ' . $headPending . ' remaining ' . ($headPending === 1 ? 'render' : 'renders') . ' in ' . $seriesActive['name'] . '?') ?>"
                    data-toast="<?= esc($seriesActive['name'] . ' approved') ?>" data-reload><?= icon('checkmark') ?><span>Approve all remaining</span></button>
          <?php endif; ?>
          <?php if ($isAdmin): // admin-only: never rendered for clients ?>
            <div class="as-menu" data-series-menu-root>
              <button type="button" class="ui-btn ui-btn--sm ui-btn--gray as-menu-btn" data-series-menu aria-haspopup="menu" aria-expanded="false" aria-label="Series options"><?= icon('ellipsis') ?></button>
              <div class="as-menu-list" data-series-menu-list role="menu" hidden>
                <a class="as-menu-item" role="menuitem" href="<?= esc($studioUploadUrl) ?>" data-series-upload><?= icon('plus') ?>Upload more…</a>
                <?php if ($seriesActive): ?>
                  <button type="button" class="as-menu-item" role="menuitem" data-series-rename><?= icon('wand') ?>Rename series…</button>
                  <button type="button" class="as-menu-item is-destructive" role="menuitem" data-series-delete><?= icon('xmark') ?>Delete series…</button>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php elseif ($seriesOn && $isAdmin): ?>
      <p class="as-series-hint text-secondary" data-series-hint>No series yet — <a href="<?= esc(clientUrl('studio.php', ['tab' => 'renders', 'tire' => $itemId])) ?>">upload renders in Studio</a> or drop a folder into <code><?= esc(function_exists('tireFolderRel') ? tireFolderRel($client, $collection) . '/' : 'media/tires/<tire>/') ?></code>.</p>
    <?php endif; ?>
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
         data-filter="<?= esc($filter) ?>" data-scope="<?= $collection ? 'tire' : 'library' ?>"<?= $seriesKey !== '' ? ' data-series="' . esc($seriesKey) . '"' : '' ?>
         data-offset="<?= (int)$offset ?>" data-total="<?= (int)max($gridTotal, $offset + count($items)) ?>">
      <?php $total = max($gridTotal, $offset + count($items)); foreach ($items as $i => $it): ?>
        <?= assetsTileHtml($it, $offset + $i + 1, $total) ?>
      <?php endforeach; ?>
    </div>
    <?php if ($hasMore): $remaining = $gridTotal - $offset - count($items); ?>
      <div class="as-more" data-assets-more-wrap>
        <button type="button" class="ui-btn ui-btn--gray as-more-btn" data-assets-more data-offset="<?= (int)($offset + count($items)) ?>" data-total="<?= (int)$gridTotal ?>">
          Load more <span class="as-more-count" data-assets-more-count><?= (int)$remaining ?> remaining</span>
        </button>
        <noscript><a class="ui-btn ui-btn--gray" href="<?= esc(assetsUrl(['offset' => $offset + count($items)])) ?>">Next <?= (int)min(ASSETS_PAGE, $remaining) ?></a></noscript>
      </div>
    <?php endif; ?>
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
$viewerCommentsEndpoint = clientUrl('assets.php', ['partial' => 'comments']);   // Comments panel thread fetch (+ &kind=&id=)
include __DIR__ . '/partials/components/media-viewer.php';

// Admin series sheets (Rename / Delete) — markup only for admin; App.sheet fills #uiSheet from these templates.
if ($isAdmin && $seriesOn && $seriesActive):
?>
  <template data-series-form="rename">
    <form class="as-series-form" data-series-form-el="rename" novalidate>
      <label class="studio-label as-series-label" for="seriesRenameName">Series name</label>
      <input class="ui-input" type="text" id="seriesRenameName" name="name" maxlength="80" required value="<?= esc($seriesActive['name']) ?>" data-sheet-autofocus>
      <p class="as-series-help text-secondary">The folder on disk keeps its name; only the label the client sees changes.</p>
      <div class="as-series-form-actions"><button type="button" class="ui-btn ui-btn--gray" data-sheet-close>Cancel</button><button type="submit" class="ui-btn ui-btn--filled" data-series-form-submit>Save</button></div>
    </form>
  </template>
  <template data-series-form="delete">
    <form class="as-series-form" data-series-form-el="delete" novalidate>
      <p>Remove <strong><?= esc($seriesActive['name']) ?></strong> (<?= (int)($seriesActive['counts']['total'] ?? 0) ?> files) from <?= esc($collection['name']) ?>? The client will no longer see it and its decisions are dropped.</p>
      <label class="as-series-check"><input type="checkbox" name="delete_files" value="1"> Also delete the files in <code><?= esc((function_exists('tireFolderRel') ? tireFolderRel($client, $collection) : 'media/tires/' . $slug) . '/' . ($seriesActive['folder'] ?? $seriesActive['slug'] ?? '')) ?>/</code></label>
      <div class="as-series-form-actions"><button type="button" class="ui-btn ui-btn--gray" data-sheet-close>Cancel</button><button type="submit" class="ui-btn ui-btn--deny" data-series-form-submit>Delete series</button></div>
    </form>
  </template>
<?php endif;

$seriesCfg = null;
if ($seriesOn && $collection) {
    $seriesCfg = [
        'key'     => $seriesKey,
        'id'      => $seriesActive ? (int)$seriesActive['id'] : null,
        'name'    => $seriesActive ? (string)$seriesActive['name'] : 'Reference',
        'tire'    => (string)$collection['name'],
        'tireId'  => $itemId,
        'tireUrl' => clientUrl('assets.php', ['view' => 'collections', 'item' => $itemId]),
        'list'    => array_map(static function ($sr) { return ['id' => (int)$sr['id'], 'name' => (string)$sr['name'], 'counts' => $sr['counts'] ?? []]; }, $seriesList),
    ];
}
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
        'upload'  => basePath() . '/tire-upload.php',              // admin: one file per request into a series
        'comments' => clientUrl('assets.php', ['partial' => 'comments']),   // + &kind=&id= → {ok, count, html} (viewer Comments panel)
    ],
    'labels'    => ['collections' => $collectionsLabel],
    // Viewer heading context "<tire> · <series>" (the count "n of N" is appended by App.viewer)
    'context'   => $collection ? (string)$collection['name'] . ($seriesCfg ? ' · ' . $seriesCfg['name'] : '') : '',
    'series'    => $seriesCfg,
    'page'      => [
        'size'    => ASSETS_PAGE,
        'offset'  => $offset,
        'loaded'  => count($items),
        'total'   => $isGrid ? max($gridTotal, $offset + count($items)) : 0,
        'partial' => $isGrid ? assetsUrl(['partial' => 1, 'offset' => '__OFFSET__']) : '',
    ],
];
$footExtra = '<script>window.AssetsPage = ' . json_encode($assetsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) . ';</script>' . "\n"
           . '<script src="' . esc(staticUrl('js/assets.js')) . '" defer></script>' . "\n";
$includeSheet = $isAdmin && $seriesOn && $seriesActive !== null;   // only the admin's Rename / Delete series forms use the generic sheet
include __DIR__ . '/partials/layout-bottom.php';
