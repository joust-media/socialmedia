<?php
/**
 * Upload ONE render into a tire's series (admin only; design §5).
 *
 * POST multipart:
 *   client      company slug (the tire must belong to it)
 *   tire_id     tires.id
 *   series_id   tire_series.id   — or —   new_series  a name (created on first use, folder = slug)
 *   file        the image / video
 *   batch       optional 16-hex batch id shared by one drop (one activity line for the whole drop)
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

$batchId = (string)($_POST['batch'] ?? '');
if (!preg_match('/^[0-9a-f]{16}$/', $batchId)) { $batchId = newBatchId(); }

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
if ($isVideo) {
    if (!videoFileLooksValid($tmpName, $ext)) { tireUploadFail(422, 'Not a valid video file'); }
} else {
    if (@getimagesize($tmpName) === false) { tireUploadFail(422, 'Not a valid image'); }
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
$mediaDir  = tireSeriesFolderPath($company, $tire, $series);
if (!is_dir($mediaDir)) { @mkdir($mediaDir, 0755, true); }
if (!is_dir($mediaDir) || !is_writable($mediaDir)) {
    $storage = 'uploads';
    $mediaDir = __DIR__ . '/uploads';
    if (!is_dir($mediaDir)) { @mkdir($mediaDir, 0755, true); }
    if (!is_dir($mediaDir) || !is_writable($mediaDir)) {
        tireUploadFail(500, 'Neither media/tires/ nor uploads/ is writable on the server.');
    }
}

$stem = safeFilenameStem(pathinfo($origName, PATHINFO_FILENAME));
if ($stem === '') { $stem = 'render'; }
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
