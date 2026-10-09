<?php
/**
 * New post pop-up endpoint (static/js/newpost.js — App.newPost). Admin only, same-site only, JSON always.
 * The tenant is the ?client=<slug> on the URL (helpers.php scopes $client from the query string only).
 *
 *   GET  action=clients                       → {ok, clients:[{slug, name, logo}]}            (unscoped "+ New")
 *   GET  action=init                          → {ok, client:{slug,name,logo,default_hashtags}, max, supportsType,
 *                                                types, draft (bool: posts can be saved as Draft), facets, urls}
 *   GET  action=picker                        → {ok, total, offset, next, items:[item…], facets (first page)}
 *        tires=<id,id> (multi-select)  library=1 (with or without tires)  — neither = everything
 *        series=<tire>:<ref|series id>,… (narrows that tire) or bare ref|<series id> (every tire)
 *        scope=all|tires|library   media=all|image|video   q=<name search, every word must match>
 *        offset=N   limit≤60 (60 per page)   refs=tire:1,library:4 → exactly those approved items, in order
 *        Order: each tire (by name) → its Reference images → each series (series order) → Library.
 *        item = {ref:'tire:12'|'library:3', kind, id, label, group, group_label, series ('ref'|id|''), series_label,
 *                media, thumb (sm preview), large (lg preview), src (original), w, h}
 *   GET  action=load&id=N                     → {ok, post:{id,name,caption,hashtags,scheduled,status,post_type,posted},
 *                                                slides:[{ref:'image:<post_images.id>', media, thumb, large, src, w, h}],
 *                                                note: {who, text, slide, at, when} | null (Needs changes: the client's latest note)}
 *   POST action=create                        slides[] (order = carousel order; "tire:<id>" | "library:<id>" |
 *                                                "upload:<token>" (or "claim:<token>") from upload-chunk.php purpose=post), caption,
 *                                                hashtags, scheduled_date, post_type ('' = auto), name,
 *                                                intent=draft|review                → {ok, post_id, status, url, message}
 *   POST action=update&id=N                   the same fields; slides[] may also hold "image:<id>" (a slide the post
 *                                                already has). intent=keep (default) | draft | review.
 *                                                Rewrites post_images in one transaction: kept rows get the new
 *                                                sort_order, picked assets are copied in (+ previews), uploads are
 *                                                claimed, removed rows are deleted and their files unlinked only
 *                                                when no other row still references them. Logs edited_media.
 *
 * Errors: 400 bad request · 403 not admin / another tenant / an asset that is not approved for this client ·
 * 404 unknown post · 405 · 422 validation ({errors:{caption|slides|scheduled_date: msg}}) · 500.
 * Limits: 20 slides per post (Instagram's carousel cap).
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/chunk-upload-lib.php';   // chunkSpoolDir(): where the claim sidecars live
require_once __DIR__ . '/upload-lib.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }
if (is_file(__DIR__ . '/tire-series-lib.php')) { require_once __DIR__ . '/tire-series-lib.php'; }
require_once __DIR__ . '/partials/components/asset-pool.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!defined('NP_MAX_SLIDES')) define('NP_MAX_SLIDES', defined('POST_MAX_MEDIA') ? (int)POST_MAX_MEDIA : 20);   // Instagram's carousel cap
if (!defined('NP_PAGE')) define('NP_PAGE', 60);

function npOut(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
function npFail(int $code, string $msg, array $extra = []): void { npOut($code, ['ok' => false, 'error' => $msg] + $extra); }

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
requireSameSiteFetch();                                         // cross-site → JSON 403 (helpers.php)
if (!function_exists('currentAdmin') || !currentAdmin()) { npFail(403, 'Admin sign-in required'); }
previewGdHeader();   // X-Preview-Gd: originals decoded for previews in this request

$reads  = ['clients', 'init', 'picker', 'load'];
$writes = ['create', 'update'];
if (!in_array($action, array_merge($reads, $writes), true)) { npFail(400, 'Unknown action'); }
if (in_array($action, $writes, true) && !$isPost) { npFail(405, 'Method not allowed'); }

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/** posts.status accepts 'draft' (migrate.php step 35; helpers.php postsHaveDraft()) → a Draft can be saved. */
function npDraftSupported(PDO $pdo): bool {
    static $on = null;
    if ($on !== null) return $on;
    if (function_exists('postsHaveDraft')) return $on = (bool)postsHaveDraft($pdo);
    try {
        $row = $pdo->query("SHOW COLUMNS FROM posts LIKE 'status'")->fetch();
        $on = $row && stripos((string)($row['Type'] ?? $row[1] ?? ''), "'draft'") !== false;
    } catch (Throwable $e) { $on = false; }
    return $on;
}

/** ['w','h'] of an image file (previews' cached dims, else getimagesize), null for videos / unreadable files. */
function npDims(string $abs): ?array {
    if ($abs === '' || !is_file($abs)) return null;
    if (function_exists('previewDims')) { $d = previewDims($abs); if ($d) return $d; }
    $i = @getimagesize($abs);
    return is_array($i) && $i[0] > 0 ? ['w' => (int)$i[0], 'h' => (int)$i[1]] : null;
}

/** Picker item for a pool asset (asset-pool.php shape) → the JSON the pop-up renders. */
function npItem(array $a): array {
    $isV = ($a['media'] ?? 'image') === 'video';
    $pv  = !$isV && function_exists('pvUrls') ? pvUrls((string)$a['src']) : ['thumb' => (string)($a['thumb'] ?? $a['src']), 'large' => (string)$a['src']];
    $d   = !$isV ? npDims((string)($a['path'] ?? '')) : null;
    return [
        'ref'          => (string)$a['key'],
        'kind'         => (string)$a['kind'],
        'id'           => (int)$a['id'],
        'label'        => (string)$a['label'],
        'group'        => (string)$a['group'],
        'group_label'  => (string)$a['group_label'],
        'series'       => $a['kind'] === 'tire' ? (string)($a['series'] ?? 'ref') : '',
        'series_label' => $a['kind'] === 'tire' ? ((string)($a['series'] ?? 'ref') === 'ref' ? 'Reference' : (string)($a['series_label'] ?? '')) : '',
        'media'        => $isV ? 'video' : 'image',
        'thumb'        => (string)$pv['thumb'],
        'large'        => (string)$pv['large'],
        'src'          => (string)$a['src'],
        'w'            => $d['w'] ?? null,
        'h'            => $d['h'] ?? null,
    ];
}

/** A post_images row → a tray slide. */
function npSlideFromRow(array $row): array {
    $url = (string)$row['image_url'];
    $src = studioRootUrl($url);
    $isV = (($row['media_type'] ?? '') !== '' ? $row['media_type'] : mediaTypeFromUrl($url)) === 'video';
    $pv  = !$isV && function_exists('pvUrls') ? pvUrls($src) : ['thumb' => $src, 'large' => $src];
    $abs = uploadsPathOrNull($url);
    $d   = !$isV && $abs !== null ? npDims($abs) : null;
    return ['ref' => 'image:' . (int)$row['id'], 'media' => $isV ? 'video' : 'image', 'thumb' => (string)$pv['thumb'], 'large' => (string)$pv['large'],
            'src' => $src, 'w' => $d['w'] ?? null, 'h' => $d['h'] ?? null, 'label' => basename($url)];
}

/** Ordered pool for the picker: tires by name (Reference, then series in order), Library last. */
function npOrderedPool(PDO $pdo, array $client): array {
    $pool = studioApprovedPool($pdo, $client);
    $groups = function_exists('studioPoolGroups') ? studioPoolGroups($pool) : [];
    $tires = []; $library = null;
    foreach ($groups as $g) { if ($g['kind'] === 'library') $library = $g; else $tires[] = $g; }
    $ordered = [];
    foreach ($tires as $g) { foreach ($g['pages'] as $pg) { foreach ($pg['assets'] as $a) $ordered[] = $a; } }
    if ($library) { foreach ($library['pages'] as $pg) { foreach ($pg['assets'] as $a) $ordered[] = $a; } }
    $facets = ['tires' => [], 'library' => 0, 'media' => ['image' => 0, 'video' => 0]];
    foreach ($tires as $g) {
        $facets['tires'][] = ['id' => (int)substr($g['key'], 5), 'name' => $g['label'], 'count' => (int)$g['count'],
            'series' => array_map(static function ($s) { return ['key' => (string)$s['key'], 'name' => (string)($s['name'] !== '' ? $s['name'] : ($s['key'] === 'ref' ? 'Reference' : 'Series ' . $s['key'])), 'count' => (int)$s['count']]; }, $g['series'] ?: [])];
    }
    $facets['library'] = $library ? (int)$library['count'] : 0;
    foreach ($ordered as $a) { $facets['media'][$a['media'] === 'video' ? 'video' : 'image']++; }
    return [$ordered, $facets];
}

/** Comma list or array → trimmed non-empty strings. */
function npList($raw): array {
    if (is_array($raw)) $raw = implode(',', array_map('strval', $raw));
    return array_values(array_filter(array_map('trim', explode(',', (string)$raw)), static function ($s) { return $s !== ''; }));
}

/**
 * Parse slides[] → [['type' => asset|upload|image, 'kind', 'id', 'token', 'ref'], …] in order (duplicates dropped).
 * Returns [$slides, $error].
 */
function npParseSlides($raw, bool $allowExisting): array {
    if (is_string($raw)) { $j = json_decode($raw, true); $raw = is_array($j) ? $j : npList($raw); }
    if (!is_array($raw)) return [[], ''];
    $out = []; $seen = [];
    foreach ($raw as $r) {
        $r = trim((string)$r);
        if ($r === '') continue;
        if (preg_match('/^(tire|library):(\d+)$/', $r, $m)) {
            $s = ['type' => 'asset', 'kind' => $m[1], 'id' => (int)$m[2], 'ref' => $m[1] . ':' . (int)$m[2]];
        } elseif (preg_match('/^(?:upload|claim):([a-f0-9]{32})$/', $r, $m)) {   // claim:<token> = add-post.php media[] spelling
            $s = ['type' => 'upload', 'token' => $m[1], 'ref' => 'upload:' . $m[1]];
        } elseif ($allowExisting && preg_match('/^image:(\d+)$/', $r, $m)) {
            $s = ['type' => 'image', 'id' => (int)$m[1], 'ref' => 'image:' . (int)$m[1]];
        } else {
            return [[], 'Unrecognised slide "' . mb_substr($r, 0, 40) . '".'];
        }
        if (isset($seen[$s['ref']])) continue;
        $seen[$s['ref']] = true;
        $out[] = $s;
    }
    return [$out, ''];
}

/** The sidecar's client for a token (any purpose) — tells "another tenant's upload" (403) from "expired" (400). */
function npTokenClient(string $token): ?string {
    if (!function_exists('uploadClaimSidecarDir')) return null;
    $dir = uploadClaimSidecarDir(false);
    if ($dir === null || !preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $f = $dir . '/' . $token . '.claim';
    if (!is_file($f) || is_link($f)) return null;
    $side = json_decode((string)@file_get_contents($f), true);
    return is_array($side) ? (string)($side['client'] ?? '') : null;
}

/** Validate every slide before anything is written: assets resolve (approved, this client), uploads are claimable. */
function npResolveSlides(PDO $pdo, array $client, array $slides, array $existingById): array {
    $resolved = [];
    foreach ($slides as $s) {
        if ($s['type'] === 'asset') {
            $a = studioResolveAsset($pdo, $client, $s['kind'], $s['id']);
            if (!$a) npFail(403, ($s['kind'] === 'library' ? 'Library image' : 'Tire image') . ' #' . $s['id'] . ' is not an approved asset for ' . $client['name'] . '.', ['ref' => $s['ref']]);
            $resolved[] = $s + ['asset' => $a];
        } elseif ($s['type'] === 'upload') {
            $c = uploadClaimRead($s['token'], 'post', (string)$client['slug']);
            if ($c === null) {
                $owner = npTokenClient($s['token']);
                if ($owner !== null && $owner !== '' && $owner !== (string)$client['slug']) npFail(403, 'That upload belongs to another client.', ['ref' => $s['ref']]);
                npFail(400, 'One of the uploaded files has expired or could not be found — please add it again.', ['ref' => $s['ref']]);
            }
            $resolved[] = $s + ['claim' => $c];
        } else {
            if (!isset($existingById[$s['id']])) npFail(403, 'Slide image #' . $s['id'] . ' is not part of this post.', ['ref' => $s['ref']]);
            $resolved[] = $s + ['row' => $existingById[$s['id']]];
        }
    }
    return $resolved;
}

/** Common field validation for create / update. Returns [$fields, $errors]. */
function npFields(array $in, string $intent, int $nSlides, bool $isCreate): array {
    $errors = [];
    $caption  = trim((string)($in['caption'] ?? ''));
    $hashtags = trim((string)($in['hashtags'] ?? ''));
    $name     = trim((string)($in['name'] ?? ''));
    if (mb_strlen($name) > 150) $name = mb_substr($name, 0, 150);
    if (mb_strlen($caption) > 10000) $errors['caption'] = 'Caption is limited to 10,000 characters.';
    if (mb_strlen($hashtags) > 2000) $errors['hashtags'] = 'Hashtags are limited to 2,000 characters.';
    $type = strtolower(trim((string)($in['post_type'] ?? '')));
    if ($type !== '' && !in_array($type, allowedPostTypes(), true)) $type = '';
    $when = trim((string)($in['scheduled_date'] ?? ''));
    $dt = null;
    if ($when !== '') {
        $ts = strtotime($when);
        if ($ts === false) $errors['scheduled_date'] = 'That date is not valid.';
        else $dt = date('Y-m-d H:i:s', $ts);
    }
    if ($nSlides > NP_MAX_SLIDES) $errors['slides'] = 'Up to ' . NP_MAX_SLIDES . ' slides per post — remove ' . ($nSlides - NP_MAX_SLIDES) . '.';
    if ($intent === 'review') {
        if ($caption === '') $errors['caption'] = 'Write a caption before sending it for review.';
        if ($nSlides === 0) $errors['slides'] ??= 'Add at least one image or video.';
        if ($dt === null && !isset($errors['scheduled_date'])) $errors['scheduled_date'] = 'Pick a date.';
    } elseif ($isCreate && $nSlides === 0 && $caption === '') {
        $errors['slides'] = 'Add an image or a caption first.';
    }
    if ($dt === null && !isset($errors['scheduled_date'])) $dt = date('Y-m-d 10:00:00', strtotime('+1 day'));   // a draft without a date: tomorrow 10:00
    return [['caption' => $caption, 'hashtags' => $hashtags, 'name' => $name, 'post_type' => $type, 'scheduled_date' => $dt], $errors];
}

/** post_type for the stored row: the admin's override, else auto (a single video → reel, anything else → post). */
function npAutoType(string $override, array $resolved): string {
    if ($override !== '') return $override;
    if (count($resolved) === 1) {
        $s = $resolved[0];
        $isV = $s['type'] === 'asset' ? (($s['asset']['media'] ?? 'image') === 'video')
             : ($s['type'] === 'upload' ? !empty($s['claim']['video'])
             : (((string)($s['row']['media_type'] ?? '') !== '' ? $s['row']['media_type'] : mediaTypeFromUrl((string)$s['row']['image_url'])) === 'video'));
        if ($isV && in_array('reel', allowedPostTypes(), true)) return 'reel';
    }
    return 'post';
}

/**
 * Write the new slides (asset copies + claimed uploads) and set sort_order for every slide in order.
 * Returns [$previewQueue, $createdFiles]. Throws on failure (caller rolls back + unlinks $createdFiles).
 */
function npWriteSlides(PDO $pdo, int $postId, array $resolved, array &$created): array {
    $uploadsDir = studioUploadsDir();
    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }
    $hasMedia = hasMediaTypeColumn($pdo);
    $insM = $hasMedia ? $pdo->prepare("INSERT INTO post_images (post_id, image_url, media_type, sort_order) VALUES (?, ?, ?, ?)")
                      : $pdo->prepare("INSERT INTO post_images (post_id, image_url, sort_order) VALUES (?, ?, ?)");
    $upd = $pdo->prepare("UPDATE post_images SET sort_order = ? WHERE id = ? AND post_id = ?");
    $queue = [];
    foreach ($resolved as $i => $s) {
        $order = $i + 1;
        if ($s['type'] === 'image') { $upd->execute([$order, (int)$s['id'], $postId]); continue; }
        if ($s['type'] === 'asset') {
            $rel = studioCopyAssetToUploads($s['asset'], $uploadsDir);
            $abs = $uploadsDir . '/' . basename($rel);
            $created[] = $abs;
            $media = ($s['asset']['media'] ?? 'image') === 'video' ? 'video' : 'image';
        } else {
            $claim = $s['claim'];
            $isV = !empty($claim['video']);
            $name = uploadFreshName($isV ? 'vid_' : 'img_', (string)$claim['ext']);
            $abs = $uploadsDir . '/' . $name;
            if (!uploadClaimTake($claim, $abs)) throw new RuntimeException("Failed to save '" . (string)$claim['name'] . "'.");
            $created[] = $abs;
            $rel = 'uploads/' . $name;
            $media = $isV ? 'video' : 'image';
        }
        if ($hasMedia) $insM->execute([$postId, $rel, $media, $order]); else $insM->execute([$postId, $rel, $order]);
        if ($media === 'image') $queue[] = $abs;
    }
    return $queue;
}

/** Unlink an uploads/ file (+ its previews) unless another row still points at it. */
function npUnlinkIfOrphan(PDO $pdo, string $imageUrl): void {
    $n = 0;
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM post_images WHERE image_url = ?"); $q->execute([$imageUrl]); $n += (int)$q->fetchColumn();
        $q = $pdo->prepare("SELECT COUNT(*) FROM tire_images WHERE image_url = ?"); $q->execute([$imageUrl]); $n += (int)$q->fetchColumn();
    } catch (Throwable $e) { return; }   // unsure → keep the file
    if ($n > 0) return;
    $path = uploadsPathOrNull($imageUrl);
    if ($path === null) return;
    if (function_exists('previewDelete')) previewDelete($path);
    @unlink($path);
}

function npPostUrl(int $postId): string {
    return clientUrl('posts.php', ['post' => $postId]);
}

// ---------------------------------------------------------------------
// clients (no scope needed)
// ---------------------------------------------------------------------
if ($action === 'clients') {
    $rows = $pdo->query("SELECT name, slug, logo_url FROM companies ORDER BY name ASC")->fetchAll();
    npOut(200, ['ok' => true, 'clients' => array_map(static function ($c) {
        return ['slug' => (string)$c['slug'], 'name' => (string)$c['name'], 'logo' => brandLogoUrl((string)($c['logo_url'] ?? ''))];
    }, $rows)]);
}

if (!$client) { npFail(400, 'Pick a client first.'); }
$cid = (int)$client['id'];

// ---------------------------------------------------------------------
// init
// ---------------------------------------------------------------------
if ($action === 'init') {
    [, $facets] = npOrderedPool($pdo, $client);
    npOut(200, [
        'ok'           => true,
        'client'       => ['slug' => (string)$client['slug'], 'name' => (string)$client['name'], 'logo' => brandLogoUrl((string)($client['logo_url'] ?? '')),
                           'default_hashtags' => trim((string)($client['default_hashtags'] ?? ''))],
        'max'          => NP_MAX_SLIDES,
        'page'         => NP_PAGE,
        'supportsType' => hasPostTypeColumn($pdo),
        'types'        => array_map(static function ($t) { return ['value' => $t, 'label' => postTypeLabel($t)]; }, allowedPostTypes()),
        'draft'        => npDraftSupported($pdo),
        'facets'       => $facets,
        'urls'         => ['post' => clientUrl('posts.php', ['post' => '__ID__']), 'upload' => basePath() . '/upload-chunk.php?client=' . rawurlencode((string)$client['slug'])],
    ]);
}

// ---------------------------------------------------------------------
// picker
// ---------------------------------------------------------------------
if ($action === 'picker') {
    [$all, $facets] = npOrderedPool($pdo, $client);
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = max(1, min(NP_PAGE, (int)($_GET['limit'] ?? NP_PAGE)));
    // refs=tire:1,library:4 — exactly these (approved, this client) in the given order (preselection)
    $refs = npList($_GET['refs'] ?? '');
    if ($refs) {
        $byRef = [];
        foreach ($all as $a) $byRef[(string)$a['key']] = $a;
        $hit = [];
        foreach (array_slice($refs, 0, NP_MAX_SLIDES) as $r) { if (isset($byRef[$r])) $hit[] = $byRef[$r]; }
        npOut(200, ['ok' => true, 'total' => count($hit), 'offset' => 0, 'next' => null, 'items' => array_map('npItem', $hit), 'facets' => null]);
    }
    $tires  = array_values(array_filter(array_map('intval', npList($_GET['tires'] ?? ''))));
    $lib    = !empty($_GET['library']);
    $scope  = (string)($_GET['scope'] ?? 'all');
    if ($scope === 'library') { $lib = true; }
    $media  = (string)($_GET['media'] ?? 'all');
    $q      = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    // series: "<tire id>:<ref|series id>" narrows that tire only; a bare "ref" / "<series id>" applies to every tire
    $perTire = []; $anyTire = [];
    foreach (npList($_GET['series'] ?? '') as $sk) {
        if (preg_match('/^(\d+):(ref|\d+)$/', $sk, $m)) $perTire[(int)$m[1]][] = $m[2];
        elseif (preg_match('/^(ref|\d+)$/', $sk)) $anyTire[] = $sk;
    }
    $narrow = $tires || $lib || $scope === 'tires';
    $hit = array_values(array_filter($all, static function ($a) use ($tires, $lib, $scope, $narrow, $perTire, $anyTire, $media, $q) {
        if ($a['kind'] === 'tire') {
            $t = (int)substr((string)$a['group'], 5);
            $sk = (string)($a['series'] ?? 'ref');
            if ($narrow && $scope !== 'tires' && !in_array($t, $tires, true)) return false;
            if (isset($perTire[$t]) && !in_array($sk, $perTire[$t], true)) return false;
            if (!isset($perTire[$t]) && $anyTire && !in_array($sk, $anyTire, true)) return false;
        } else {
            if ($narrow && !$lib) return false;
            if ($anyTire || $perTire) { if (!$lib) return false; }
        }
        if ($media === 'image' && $a['media'] === 'video') return false;
        if ($media === 'video' && $a['media'] !== 'video') return false;
        if ($q !== '') {
            $hay = mb_strtolower($a['label'] . ' ' . $a['group_label'] . ' ' . ($a['series_label'] ?? '') . ' ' . basename((string)$a['src']));
            foreach (preg_split('/\s+/', $q) as $w) { if ($w !== '' && mb_strpos($hay, $w) === false) return false; }
        }
        return true;
    }));
    $total = count($hit);
    $slice = array_slice($hit, $offset, $limit);
    $next  = $offset + count($slice) < $total ? $offset + count($slice) : null;
    npOut(200, ['ok' => true, 'total' => $total, 'offset' => $offset, 'next' => $next, 'items' => array_map('npItem', $slice), 'facets' => $offset === 0 ? $facets : null]);
}

// ---------------------------------------------------------------------
// load (edit mode)
// ---------------------------------------------------------------------
/** The post row when it exists; 404 / 403 (another tenant) otherwise. */
function npPostOr404(PDO $pdo, int $postId, int $cid, bool $lock = false): array {
    if ($postId <= 0) npFail(400, 'Invalid post id.');
    $st = $pdo->prepare("SELECT * FROM posts WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$postId]);
    $p = $st->fetch();
    if (!$p) npFail(404, 'That post no longer exists.');
    if ((int)$p['company_id'] !== $cid) npFail(403, 'That post belongs to another client.');
    if (!empty($p['trashed_at'])) npFail(409, 'That post is in the Trash — restore it first.');   // trash-lib.php
    return $p;
}
function npRows(PDO $pdo, int $postId): array {
    $mt = hasMediaTypeColumn($pdo) ? ', media_type' : ", '' AS media_type";
    $st = $pdo->prepare("SELECT id, image_url{$mt}, sort_order FROM post_images WHERE post_id = ? ORDER BY sort_order ASC, id ASC");
    $st->execute([$postId]);
    return $st->fetchAll();
}

/** Needs changes: the client's latest note (else anyone's) → {who, text, slide, at}; null otherwise. Pinned above the tray. */
function npLatestNote(PDO $pdo, array $p, string $clientName): ?array {
    if ((string)$p['status'] !== 'denied' || !function_exists('hasActivityLog') || !hasActivityLog($pdo)) return null;
    require_once __DIR__ . '/partials/components/review-actions.php';
    $st = $pdo->prepare("SELECT actor, detail, created_at FROM activity_log WHERE entity_type = 'post' AND action = 'commented' AND entity_id = ?
                         AND detail IS NOT NULL AND detail <> ''" . activityVisibleSql($pdo) . " ORDER BY created_at ASC, id ASC");
    $st->execute([(int)$p['id']]);
    $n = reviewLatestNote($st->fetchAll(), $clientName);
    if ($n && function_exists('relativeTime') && $n['at'] !== '') $n['when'] = relativeTime($n['at']);
    return $n;
}

if ($action === 'load') {
    $p = npPostOr404($pdo, (int)($_GET['id'] ?? 0), $cid);
    $ts = !empty($p['scheduled_date']) ? strtotime((string)$p['scheduled_date']) : false;
    npOut(200, ['ok' => true, 'post' => [
        'id' => (int)$p['id'], 'name' => (string)($p['name'] ?? ''), 'caption' => (string)($p['caption'] ?? ''), 'hashtags' => (string)($p['hashtags'] ?? ''),
        'scheduled' => $ts ? date('Y-m-d\TH:i', $ts) : '', 'status' => (string)$p['status'], 'post_type' => (string)($p['post_type'] ?? 'post'),
        'posted' => !empty($p['posted']),
    ], 'slides' => array_map('npSlideFromRow', npRows($pdo, (int)$p['id'])), 'note' => npLatestNote($pdo, $p, (string)($client['name'] ?? ''))]);
}

// ---------------------------------------------------------------------
// create / update
// ---------------------------------------------------------------------
$isCreate = $action === 'create';
$intent   = (string)($_POST['intent'] ?? ($isCreate ? 'draft' : 'keep'));
if (!in_array($intent, $isCreate ? ['draft', 'review'] : ['keep', 'draft', 'review'], true)) $intent = $isCreate ? 'draft' : 'keep';

[$slides, $slideErr] = npParseSlides($_POST['slides'] ?? [], !$isCreate);
if ($slideErr !== '') npFail(400, $slideErr);
[$f, $errors] = npFields($_POST, $intent, count($slides), $isCreate);
if ($errors) npOut(422, ['ok' => false, 'error' => implode(' ', $errors), 'errors' => $errors]);

$draftOn   = npDraftSupported($pdo);
// Never fall back to a client-visible status for a "Save draft": without the Draft state the admin must send it for review.
if ($intent === 'draft' && !$draftOn) npFail(409, 'Saving a draft needs the latest migrate.php (step 35) — run it, or send this post for review.');
$hasName   = hasPostsNameColumn($pdo);
$hasType   = hasPostTypeColumn($pdo);
$created   = [];      // files written by this request (unlinked on rollback)
$toUnlink  = [];      // image_urls of removed rows (unlinked after commit when orphaned)
$previewQ  = [];
$postId    = 0;
$status    = 'pending';

try {
    $pdo->beginTransaction();
    if ($isCreate) {
        $resolved = npResolveSlides($pdo, $client, $slides, []);
        $status = $intent === 'review' ? 'pending' : ($draftOn ? 'draft' : 'pending');
        $cols = ['company_id', 'caption', 'hashtags', 'scheduled_date', 'status']; $vals = [$cid, $f['caption'], $f['hashtags'], $f['scheduled_date'], $status];
        if ($hasName) { $cols[] = 'name'; $vals[] = $f['name'] === '' ? null : $f['name']; }
        if ($hasType) { $cols[] = 'post_type'; $vals[] = npAutoType($f['post_type'], $resolved); }
        $pdo->prepare("INSERT INTO posts (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")")->execute($vals);
        $postId = (int)$pdo->lastInsertId();
        $previewQ = npWriteSlides($pdo, $postId, $resolved, $created);
        $label = $f['name'] !== '' ? $f['name'] : mb_substr($f['caption'], 0, 200);
        // 'drafted' (admin-only in the feed) for a Draft, 'created' for a post that goes straight to the client
        logActivity($pdo, $cid, 'post', $postId, $status === 'draft' ? 'drafted' : 'created', 'admin',
            ($status === 'draft' ? "Started a draft #{$postId}" : "Created post #{$postId}") . ($label !== '' ? ': ' . $label : ''),
            count($resolved) . ' slide' . (count($resolved) === 1 ? '' : 's'));
    } else {
        $prev = npPostOr404($pdo, (int)($_POST['id'] ?? $_GET['id'] ?? 0), $cid, true);
        $postId = (int)$prev['id'];
        if ($intent !== 'keep' && !empty($prev['posted'])) npFail(409, 'This post is scheduled — unmark it first to send it back.');
        $status = (string)$prev['status'];
        if ($intent === 'review') $status = 'pending';
        elseif ($intent === 'draft' && $draftOn) $status = 'draft';
        // A post the client can see keeps a caption and at least one slide (a draft may be empty)
        if ($status !== 'draft') {
            $e = [];
            if ($f['caption'] === '') $e['caption'] = 'A post the client can see needs a caption.';
            if (!$slides) $e['slides'] = 'Keep at least one image or video.';
            if ($e) npOut(422, ['ok' => false, 'error' => implode(' ', $e), 'errors' => $e]);
        }
        $rows = npRows($pdo, $postId);
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r;
        $resolved = npResolveSlides($pdo, $client, $slides, $byId);
        $kept = [];
        foreach ($resolved as $s) if ($s['type'] === 'image') $kept[(int)$s['id']] = true;
        // Removed rows first (their sort_order slots are rewritten below anyway)
        $del = $pdo->prepare("DELETE FROM post_images WHERE id = ? AND post_id = ?");
        foreach ($byId as $rid => $r) { if (!isset($kept[$rid])) { $del->execute([$rid, $postId]); $toUnlink[] = (string)$r['image_url']; } }
        $previewQ = npWriteSlides($pdo, $postId, $resolved, $created);

        $set = ['caption = ?', 'hashtags = ?', 'scheduled_date = ?', 'status = ?']; $vals = [$f['caption'], $f['hashtags'], $f['scheduled_date'], $status];
        if ($hasName) { $set[] = 'name = ?'; $vals[] = $f['name'] === '' ? null : $f['name']; }
        $newType = $hasType ? ($f['post_type'] !== '' ? $f['post_type'] : (string)($prev['post_type'] ?? 'post')) : null;
        if ($hasType) { $set[] = 'post_type = ?'; $vals[] = $newType; }
        $vals[] = $postId;
        $pdo->prepare("UPDATE posts SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);

        // Activity: one batch for the whole save
        $batch = newBatchId();
        $before = array_map(static function ($r) { return (int)$r['id']; }, $rows);
        $after  = [];
        foreach ($resolved as $s) $after[] = $s['type'] === 'image' ? (int)$s['id'] : 0;
        $added = count(array_filter($resolved, static function ($s) { return $s['type'] !== 'image'; }));
        $removed = count($byId) - count($kept);
        $keptBefore = array_values(array_filter($before, static function ($id) use ($kept) { return isset($kept[$id]); }));
        $keptAfter  = array_values(array_filter($after, static function ($id) { return $id > 0; }));
        $reordered = $keptBefore !== $keptAfter;
        if ($added || $removed || $reordered) {
            $bits = [];
            if ($added) $bits[] = '+' . $added . ' added';
            if ($removed) $bits[] = $removed . ' removed';
            if ($reordered) $bits[] = 'reordered';
            logActivity($pdo, $cid, 'post', $postId, 'edited_media', 'admin', "Edited media on post #{$postId}",
                count($rows) . ' → ' . count($resolved) . ' slides (' . implode(', ', $bits) . ')', $batch);
        }
        if ((string)$prev['caption'] !== $f['caption']) logActivity($pdo, $cid, 'post', $postId, 'edited_caption', 'admin', "Edited caption on post #{$postId}", mb_substr((string)$prev['caption'], 0, 200) . ' → ' . mb_substr($f['caption'], 0, 200), $batch);
        if ((string)$prev['hashtags'] !== $f['hashtags']) logActivity($pdo, $cid, 'post', $postId, 'edited_hashtags', 'admin', "Edited hashtags on post #{$postId}", mb_substr((string)$prev['hashtags'], 0, 200) . ' → ' . mb_substr($f['hashtags'], 0, 200), $batch);
        if ((string)$prev['scheduled_date'] !== (string)$f['scheduled_date']) logActivity($pdo, $cid, 'post', $postId, 'edited_schedule', 'admin', "Rescheduled post #{$postId}", ($prev['scheduled_date'] ?? '') . ' → ' . $f['scheduled_date'], $batch);
        if ($hasType && (string)($prev['post_type'] ?? 'post') !== (string)$newType) logActivity($pdo, $cid, 'post', $postId, 'edited_type', 'admin', "Changed type on post #{$postId}", ($prev['post_type'] ?? 'post') . ' → ' . $newType, $batch);
        if ($status !== (string)$prev['status']) {
            // draft → pending = 'submitted' (status.php action=submit's action); → draft = 'moved_to_draft'; anything else → pending = 'reset_pending'
            $sa = $status === 'draft' ? 'moved_to_draft' : ((string)$prev['status'] === 'draft' ? 'submitted' : 'reset_pending');
            logActivity($pdo, $cid, 'post', $postId, $sa, 'admin', "Post #{$postId} " . ($status === 'pending' ? 'sent for review' : 'moved back to drafts'), null, $batch);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($created as $file) { if (is_file($file)) { if (function_exists('previewDelete')) previewDelete($file); @unlink($file); } }
    error_log('post-compose ' . $action . ': ' . $e->getMessage());
    npFail(($e instanceof StudioAssetException && $e->getCode() >= 400) ? (int)$e->getCode() : 500, 'Save failed: ' . ($e instanceof StudioAssetException ? $e->getMessage() : 'database error.'));
}

foreach ($toUnlink as $u) npUnlinkIfOrphan($pdo, $u);
if (function_exists('previewAfterStore') && $previewQ) { previewReleaseSession(); foreach ($previewQ as $pq) previewAfterStore($pq); }   // session released: GD never holds other admin requests

$msg = $isCreate ? ($status === 'draft' ? 'Draft saved' : 'Sent for review') : ($intent === 'review' ? 'Sent for review' : 'Post saved');
npOut(200, ['ok' => true, 'post_id' => $postId, 'status' => $status, 'url' => npPostUrl($postId), 'message' => $msg, 'slides' => array_map('npSlideFromRow', npRows($pdo, $postId))]);
