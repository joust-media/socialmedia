<?php
/**
 * Upload sheet endpoint (static/js/upload-sheet.js — App.uploadSheet). Admin only, same-site only, JSON, GET.
 * The sheet itself stores nothing here: the files go to the endpoints every destination already had —
 *
 *   Tire series     tire-upload.php      media/tires/<tire>/<series>/   tire_images 'pending' (+ series_id)
 *   Tire Reference  upload-chunk.php     purpose=feature → uploads/feat_*   tire_images (column default 'pending'), 6 per tire
 *   Library         upload-chunk.php     purpose=library → media/library/<slug>/   library_images 'pending'
 *   New post        upload-chunk.php     purpose=post → claim tokens → App.newPost.open({preselect}) → a Draft post
 *                   (or purpose=batch → batch-process.php claimed[] → one Draft post per file)
 *
 *   GET action=clients          → {ok, clients:[{slug, name, logo}]}                       (unscoped pages: the chooser)
 *   GET action=init&client=…    → {ok, client:{slug, name, logo, label}, tires:[{id, name, refs, series:[{id, name, total, pending}]}],
 *                                  features:{tires, series, seriesDrive, library, draft}, limits:{image, video, reference, post, batch},
 *                                  (seriesDrive: tire_series.drive_url exists → "New series…" offers a Google Drive link field)
 *                                  urls:{upload, tire, batch, series, reference, library, drafts}}
 *        urls.series / urls.reference carry __TIRE__ / __SERIES__ placeholders (clientUrl(): the host's .php
 *        handling and the ?client= scope stay in one place).
 *
 * Errors: 400 no client / unknown action · 403 not admin or cross-site · 405 not GET.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/upload-lib.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }

header('Content-Type: application/json');
header('Cache-Control: no-store');

function usOut(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$action = (string)($_GET['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { usOut(405, ['ok' => false, 'error' => 'Method not allowed']); }
requireSameSiteFetch();
if (!function_exists('currentAdmin') || !currentAdmin()) { usOut(403, ['ok' => false, 'error' => 'Admin sign-in required']); }
if (!in_array($action, ['clients', 'init'], true)) { usOut(400, ['ok' => false, 'error' => 'Unknown action']); }

if ($action === 'clients') {
    $rows = $pdo->query("SELECT name, slug, logo_url FROM companies ORDER BY name ASC")->fetchAll();
    usOut(200, ['ok' => true, 'clients' => array_map(static function ($c) {
        return ['slug' => (string)$c['slug'], 'name' => (string)$c['name'], 'logo' => brandLogoUrl((string)($c['logo_url'] ?? ''))];
    }, $rows)]);
}

if (!$client) { usOut(400, ['ok' => false, 'error' => 'Pick a client first.']); }
$cid = (int)$client['id'];
$seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);

// Tires (by name) with their series (series order) and how many of the 6 reference slots are used.
$tires = [];
$st = $pdo->prepare("SELECT id, name FROM tires WHERE company_id = ? ORDER BY name ASC, id ASC");
$st->execute([$cid]);
foreach ($st->fetchAll() as $t) {
    $tid = (int)$t['id'];
    $series = [];
    if ($seriesOn) {
        foreach (tireSeriesForTire($pdo, $tid) as $sr) {
            $series[] = ['id' => (int)$sr['id'], 'name' => (string)$sr['name'],
                         'total' => (int)($sr['counts']['total'] ?? 0), 'pending' => (int)($sr['counts']['pending'] ?? 0)];
        }
    }
    $tires[] = ['id' => $tid, 'name' => (string)$t['name'], 'refs' => uploadFeatureCount($pdo, $tid), 'series' => $series];
}

usOut(200, [
    'ok'       => true,
    'client'   => ['slug' => (string)$client['slug'], 'name' => (string)$client['name'], 'logo' => brandLogoUrl((string)($client['logo_url'] ?? '')),
                   'label' => tiresLabel($client)],   // the Tires tab the series live under (the client's word, "Tires" by default)
    'tires'    => $tires,
    'features' => [
        'tires'   => $tires || companyHasTires($client, $pdo),
        'series'  => $seriesOn,
        'seriesDrive' => $seriesOn && function_exists('tireSeriesHasDriveUrl') && tireSeriesHasDriveUrl($pdo),   // migrate.php 29
        'library' => hasLibraryImagesTable($pdo),
        'draft'   => function_exists('postsHaveDraft') && postsHaveDraft($pdo),
    ],
    'limits'   => [
        'image'     => uploadMaxBytes('image'),
        'video'     => uploadMaxBytes('video'),
        'reference' => uploadFeatureMaxImages(),
        'post'      => defined('POST_MAX_MEDIA') ? (int)POST_MAX_MEDIA : 20,
        'batch'     => 50,
    ],
    'urls'     => [
        'upload'    => basePath() . '/upload-chunk.php?client=' . rawurlencode((string)$client['slug']),
        'tire'      => basePath() . '/tire-upload.php?client=' . rawurlencode((string)$client['slug']),
        'batch'     => basePath() . '/batch-process.php?client=' . rawurlencode((string)$client['slug']),
        'series'    => clientUrl('assets.php', ['view' => 'collections', 'item' => '__TIRE__', 'series' => '__SERIES__', 'filter' => 'pending']),
        'reference' => clientUrl('assets.php', ['view' => 'collections', 'item' => '__TIRE__', 'series' => 'ref', 'filter' => 'pending']),
        'library'   => clientUrl('assets.php', ['view' => 'library', 'filter' => 'pending']),
        'drafts'    => clientUrl('posts.php', ['status' => 'draft', 'month' => 'all']),
    ],
]);
