<?php
/**
 * Upload ONE render into a tire's series (admin only; design §5).
 *
 * POST multipart:
 *   client      company slug (the tire must belong to it)
 *   tire_id     tires.id
 *   series_id   tire_series.id   — or —   new_series  a name (created on first use, folder = slug)
 *   file        the image / video
 *   batch       optional batch token shared by one drop (one activity line for the whole drop);
 *               16-hex is used as is, any other [A-Za-z0-9_-]{4,40} token is hashed to 16-hex
 *
 * Stores media/tires/<tire-slug>/<series-folder>/<stem>.<ext> (de-duplicated -2, -3 …), inserts a
 * pending tire_images row with series_id, makes the thumb, logs tire_series/uploaded.
 * Fallback when media/tires is not writable: <app>/uploads/feat_<uniqid>.<ext> (series_id still set).
 *
 * Replies JSON {ok, image:{id, src, thumb, display_name, status, type, mime, series_id, sort_order},
 *               series:{…tireSeriesById shape…}, storage: 'media'|'uploads'}
 * Errors: 400 bad request · 403 seat / tenant / cross-site · 404 unknown tire or series ·
 *         409 series not migrated · 413 too large · 415 unsupported type · 422 not a valid image/video · 500.
 * The Studio UI sends one XHR per file (sequential queue) so each reply maps to one tile.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }

header('Content-Type: application/json');

function tireUploadFail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { tireUploadFail(405, 'Method not allowed'); }
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)
if (!currentAdmin()) { tireUploadFail(403, 'Admin sign-in required'); }
if (!hasTireSeries($pdo)) { tireUploadFail(409, 'Render series are not set up yet — run migrate.php.'); }

$maxImageBytes = 10 * 1024 * 1024;    // matches add-feature.php
$maxVideoBytes = 200 * 1024 * 1024;   // bounded by upload_max_filesize / post_max_size on the host
$iniMax        = (string)(ini_get('upload_max_filesize') ?: '?');

// ---- params ----
$tireId = (int)($_POST['tire_id'] ?? 0);
if ($tireId <= 0) { tireUploadFail(400, 'Invalid tire_id'); }
$tire = tireWithSlug($pdo, $tireId);
if (!$tire) { tireUploadFail(404, 'Tire not found'); }
$slug = postedClientSlug();
if ($slug === '') { tireUploadFail(400, 'Pick a client first'); }
$coStmt = $pdo->prepare("SELECT id, name, slug FROM companies WHERE id = ?");
$coStmt->execute([(int)$tire['company_id']]);
$company = $coStmt->fetch();
if (!$company || (string)$company['slug'] !== $slug) { tireUploadFail(403, 'This tire belongs to another client'); }

$seriesId  = (int)($_POST['series_id'] ?? 0);
$newSeries = trim((string)($_POST['new_series'] ?? ''));
if ($seriesId <= 0 && $newSeries === '') { tireUploadFail(400, 'series_id or new_series is required'); }
if ($newSeries !== '' && mb_strlen($newSeries, 'UTF-8') > 120) { tireUploadFail(400, 'Series name is too long (max 120 characters)'); }

// Batch id: one per drop so the activity feed shows one line. The UI sends any short token
// (e.g. "b<base36 time><random>"); activity_log.batch_id is CHAR(16), so anything that is
// not already 16-hex is mapped to a stable 16-hex digest of the token.
$batchId = (string)($_POST['batch'] ?? '');
if (preg_match('/^[0-9a-f]{16}$/', $batchId)) { /* as is */ }
elseif (preg_match('/^[A-Za-z0-9_\-]{4,40}$/', $batchId)) { $batchId = substr(sha1('tire-upload:' . $batchId), 0, 16); }
else { $batchId = newBatchId(); }

// ---- the file ----
if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    tireUploadFail(400, 'No file uploaded');
}
$err = (int)$_FILES['file']['error'];
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    tireUploadFail(413, "File too large for the server (PHP limit: {$iniMax}). Ask hosting to raise upload_max_filesize and post_max_size.", ['ini_max' => $iniMax]);
}
if ($err !== UPLOAD_ERR_OK) { tireUploadFail(400, "Upload error code {$err}"); }

$origName = (string)$_FILES['file']['name'];
$tmpName  = (string)$_FILES['file']['tmp_name'];
$size     = (int)$_FILES['file']['size'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if ($ext === 'jpeg') { $ext = 'jpg'; }
if (in_array($ext, ['m4v', 'avi', 'mkv'], true)) {
    tireUploadFail(415, ".{$ext} isn't web-playable. Convert to MP4 first (QuickTime: File → Export As → 1080p).");
}
$allowedExt = array_merge(imageExts(), videoExts());
if (!in_array($ext, $allowedExt, true)) {
    tireUploadFail(415, 'Unsupported file type — use JPG, PNG, GIF, WebP, MP4, WebM, or MOV.');
}
$isVideo = isVideoExt($ext);
$limit   = $isVideo ? $maxVideoBytes : $maxImageBytes;
if ($size > $limit) {
    $mb = (int)round($limit / (1024 * 1024));
    tireUploadFail(413, ($isVideo ? 'Videos' : 'Images') . " must be under {$mb} MB.", ['limit_mb' => $mb, 'ini_max' => $iniMax]);
}
// Content check — the extension alone is never trusted: images must decode AND match the
// extension's format (a PHP/HTML file renamed .jpg fails here), videos must carry the
// container magic (videoFileLooksValid: EBML / ftyp).
if ($isVideo) {
    if (!videoFileLooksValid($tmpName, $ext)) { tireUploadFail(422, 'Not a valid video file'); }
} else {
    $info = @getimagesize($tmpName);
    if ($info === false || (int)($info[0] ?? 0) <= 0 || (int)($info[1] ?? 0) <= 0) { tireUploadFail(422, 'Not a valid image'); }
    $byType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) { $byType[IMAGETYPE_WEBP] = 'webp'; }
    $detected = $byType[(int)($info[2] ?? 0)] ?? '';
    if ($detected === '' || $detected !== $ext) {
        tireUploadFail(422, $detected === '' ? 'Unsupported image format — use JPG, PNG, GIF or WebP.' : "The file is a {$detected} image, not .{$ext} — rename it and try again.");
    }
    if (function_exists('finfo_open')) {   // belt and braces: the MIME sniff must agree too
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string)@finfo_file($fi, $tmpName) : '';
        if ($fi) { @finfo_close($fi); }
        if ($mime !== '' && strpos($mime, 'image/') !== 0) { tireUploadFail(422, 'Not a valid image'); }
    }
}

// ---- the series ----
try {
    if ($seriesId > 0) {
        $series = tireSeriesById($pdo, $seriesId);
        if (!$series || (int)$series['tire_id'] !== $tireId) { tireUploadFail(404, 'Series not found on this tire'); }
    } else {
        $created = createTireSeries($pdo, $tireId, $newSeries);
        $isNew   = !empty($created['created']);
        unset($created['created']);
        $series = $created;
        if ($isNew) {
            logTireSeriesActivity($pdo, actorFromPost(), 'created', (int)$series['id'],
                'Created series ' . (string)$tire['name'] . ' · ' . $series['name'], null, $batchId, (int)$tire['company_id']);
        }
    }
} catch (Throwable $e) {
    error_log('tire-upload series: ' . $e->getMessage());
    tireUploadFail(500, 'Database error');
}

// ---- destination: media/tires/<slug>/<folder>/ (fallback: uploads/) ----
$storage   = 'media';
$mediaDir  = tireSeriesFolderPath($company, $tire, $series);   // <root>/<slug [a-z0-9-]>/<folder: no slashes, no dot prefix>
if (!is_dir($mediaDir)) { @mkdir($mediaDir, 0755, true); }
if (is_dir($mediaDir)) {
    // Containment: the resolved folder must sit exactly two levels under media/tires/ (no symlink escape).
    // (When realpath() cannot resolve — stream-wrapped harness — the textually validated path stands, like tireImagePath().)
    $rootReal = realpath(tireMediaRootPath());
    $dirReal  = realpath($mediaDir);
    if ($rootReal !== false && $dirReal !== false && $dirReal !== rtrim($rootReal, '/') . '/' . tireSlug($tire) . '/' . tireSeriesFolderName($series)) {
        tireUploadFail(500, 'Series folder resolves outside media/tires/');
    }
    ensureTireMediaHtaccess();   // media/tires/.htaccess (+ media/.htaccess when absent): no PHP/CGI, no listing
}
if (!is_dir($mediaDir) || !is_writable($mediaDir)) {
    $storage = 'uploads';
    $mediaDir = __DIR__ . '/uploads';
    if (!is_dir($mediaDir)) { @mkdir($mediaDir, 0755, true); }
    if (!is_dir($mediaDir) || !is_writable($mediaDir)) {
        tireUploadFail(500, 'Neither media/tires/ nor uploads/ is writable on the server.');
    }
}

// File name: safeFilenameStem() keeps [A-Za-z0-9._-] only, trims leading dots/dashes and caps at 80
// chars, so the name can never be a dotfile, ".."-ish or carry a path separator; a stem that
// collapses to nothing (or would become a dotfile) is called "render".
$stem = safeFilenameStem(basename($origName) !== '' ? pathinfo(basename($origName), PATHINFO_FILENAME) : '');
$stem = trim((string)$stem, '.-_ ');
if ($stem === '' || $stem[0] === '.' || preg_match('/[\/\\\\\0]/', $stem)) { $stem = 'render'; }
if ($storage === 'media') {
    $name = $stem . '.' . $ext;
    for ($n = 2; file_exists($mediaDir . '/' . $name) && $n < 1000; $n++) { $name = $stem . '-' . $n . '.' . $ext; }
    $imageUrl = tireFolderRel($company, $tire) . '/' . tireSeriesFolderName($series) . '/' . $name;
} else {
    $name = preg_replace('/[^a-zA-Z0-9_.\-]/', '', uniqid('feat_', true) . '.' . $ext);
    $imageUrl = 'uploads/' . $name;
}
$dest = $mediaDir . '/' . $name;
if (file_exists($dest)) { tireUploadFail(500, 'Could not pick a free file name'); }
if (!move_uploaded_file($tmpName, $dest)) { tireUploadFail(500, 'Failed to save the file (check folder permissions)'); }
@chmod($dest, 0644);

// ---- the row ----
try {
    $pdo->beginTransaction();
    $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM tire_images WHERE tire_id = ? AND series_id = ?");
    $sortQ->execute([$tireId, (int)$series['id']]);
    $sortOrder = (int)$sortQ->fetchColumn() + 1;
    $displayName = mb_substr($stem, 0, 150, 'UTF-8');
    if (tireImagesHaveDisplayName($pdo)) {
        $ins = $pdo->prepare("INSERT INTO tire_images (tire_id, series_id, image_url, caption, sort_order, display_name, status) VALUES (?, ?, ?, '', ?, ?, 'pending')");
        $ins->execute([$tireId, (int)$series['id'], $imageUrl, $sortOrder, $displayName]);
    } else {
        $ins = $pdo->prepare("INSERT INTO tire_images (tire_id, series_id, image_url, caption, sort_order, status) VALUES (?, ?, ?, '', ?, 'pending')");
        $ins->execute([$tireId, (int)$series['id'], $imageUrl, $sortOrder]);
    }
    $imageId = (int)$pdo->lastInsertId();
    logTireSeriesActivity($pdo, actorFromPost(), 'uploaded', (int)$series['id'],
        'Uploaded ' . $displayName . ' to ' . (string)$tire['name'] . ' · ' . $series['name'], null, $batchId, (int)$tire['company_id']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    @unlink($dest);
    error_log('tire-upload insert: ' . $e->getMessage());
    tireUploadFail(500, 'Database error');
}

$row = ['id' => $imageId, 'tire_id' => $tireId, 'series_id' => (int)$series['id'], 'image_url' => $imageUrl,
        'display_name' => $displayName, 'caption' => '', 'status' => 'pending', 'sort_order' => $sortOrder];
if (!$isVideo) { ensureTireThumb($row); }
$meta = tireImageRowMeta($row, ['tire_name' => (string)$tire['name']]);

echo json_encode([
    'ok'      => true,
    'image'   => [
        'id'           => $imageId,
        'src'          => $meta['src'],
        'thumb'        => $meta['thumb'],
        'display_name' => $displayName,
        'status'       => 'pending',
        'type'         => $meta['type'],
        'mime'         => $meta['mime'],
        'series_id'    => (int)$series['id'],
        'sort_order'   => $sortOrder,
        'download'     => $meta['download'],
    ],
    'series'  => tireSeriesById($pdo, (int)$series['id']) ?: $series,
    'storage' => $storage,
    'batch'   => $batchId,
]);
