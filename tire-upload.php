<?php
/**
 * Upload ONE render into a tire's series (admin only; design §5) — single request or chunked.
 *
 * Single request (small files) — POST multipart:
 *   client      company slug (the tire must belong to it)
 *   tire_id     tires.id
 *   series_id   tire_series.id   — or —   new_series  a name (created on first use, folder = slug)
 *   file        the image / video
 *   batch       optional batch token shared by one drop (one activity line for the whole drop);
 *               16-hex is used as is, any other [A-Za-z0-9_-]{4,40} token is hashed to 16-hex
 *
 * Chunked, resumable (large files; chunk-upload-lib.php) — `action=`:
 *   probe         GET or POST → {ok, chunk_size, max_file_bytes:{image, video}, ini_max, exts}
 *   chunk_init    client, tire_id, series_id | new_series, name, size, type, batch
 *                 → {ok, upload_id, chunk_size, received: 0, series}
 *   chunk_put     upload_id, index, offset + `file` = the piece → {ok, received}
 *                 409 {received} when offset ≠ received (resume from there); an already-received
 *                 range is a 200 no-op
 *   chunk_status  upload_id → {ok, received, size}
 *   chunk_finish  upload_id → validates the spooled file exactly like the single path, moves it
 *                 into the series folder, inserts the row; same reply as the single path
 *   chunk_abort   upload_id → deletes the spool
 *   repair_media  (admin) rewrite media/tires/.htaccess when old / missing, drop an old media/.htaccess of
 *                 ours, chmod media/tires/ to 0644 / 0755 (capped) → {ok, summary, rules, parent, perms}
 * The spool lives in media/tires/.spool/ (dot-prefixed: never scanned, deny-all .htaccess), the
 * sidecar's client must match the posted `client`, and every action is admin + same-site only.
 *
 * Stores media/tires/<tire-slug>/<series-folder>/<stem>.<ext> (de-duplicated -2, -3 …), inserts a
 * pending tire_images row with series_id, makes the thumb, logs tire_series/uploaded.
 * Fallback when media/tires is not writable: <app>/uploads/feat_<uniqid>.<ext> (series_id still set).
 *
 * Replies JSON {ok, image:{id, src, thumb, display_name, status, type, mime, series_id, sort_order},
 *               series:{…tireSeriesById shape…}, storage: 'media'|'uploads'}
 * Errors: 400 bad request · 403 seat / tenant / cross-site · 404 unknown tire, series or upload ·
 *         409 series not migrated / chunk out of order / incomplete · 413 too large · 415 unsupported type ·
 *         422 not a valid image/video · 500.
 * The Studio UI sends one XHR per file (sequential queue) so each reply maps to one tile.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/chunk-upload-lib.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }

header('Content-Type: application/json');

$tireUploadDiscard = null;   // [root, upload_id] — a failing chunk_finish drops its spool before replying

function tireUploadFail(int $code, string $msg, array $extra = []): void {
    global $tireUploadDiscard;
    if ($tireUploadDiscard) { chunkUploadDiscard($tireUploadDiscard[0], $tireUploadDiscard[1]); $tireUploadDiscard = null; }
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    exit;
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'upload');
$chunkActions = ['probe', 'chunk_init', 'chunk_put', 'chunk_status', 'chunk_finish', 'chunk_abort'];
if ($action !== 'upload' && $action !== 'repair_media' && !in_array($action, $chunkActions, true)) { tireUploadFail(400, 'Unknown action'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !($action === 'probe' && $_SERVER['REQUEST_METHOD'] === 'GET')) { tireUploadFail(405, 'Method not allowed'); }
requireSameSiteFetch();   // cross-site requests get a JSON 403 (helpers.php)
if (!currentAdmin()) { tireUploadFail(403, 'Admin sign-in required'); }

// =====================================================================
// repair_media (admin, no DB needed) — the "Repair server rules" button in Studio → Renders:
// media/tires/.htaccess (re)written when missing / older than ours, an old media/.htaccess of ours
// removed, every file / folder under media/tires/ made 0644 / 0755 (capped at 5 000 entries per
// call). Replies {ok, summary, rules, parent, perms}.
// =====================================================================
if ($action === 'repair_media') {
    $root = tireMediaRootPath();
    if (!is_dir($root)) { mediaMkdir($root, mediaRootPath()); }
    $rep = mediaRepair($root, 'tire-series-lib.php', is_dir($root) ? $root : null, 5000, mediaRootPath());
    $rep['scope'] = 'media/tires';
    if (!$rep['ok']) { http_response_code(500); }
    echo json_encode($rep);
    exit;
}

if (!hasTireSeries($pdo)) { tireUploadFail(409, 'Render series are not set up yet — run migrate.php.'); }

$maxImageBytes   = 10 * 1024 * 1024;          // matches add-feature.php
$maxVideoBytes   = 200 * 1024 * 1024;         // single request — bounded by upload_max_filesize / post_max_size anyway
$maxChunkedVideo = 4 * 1024 * 1024 * 1024;    // chunked — the total size cap
$iniMax          = (string)(ini_get('upload_max_filesize') ?: '?');

/** Spool root: media/tires when it exists / can be created, else the app's uploads/ (same fallback as the store). */
function tireUploadSpoolRoot(): string {
    $root = tireMediaRootPath();
    if (!is_dir($root)) { mediaMkdir($root, mediaRootPath()); }   // 0755 whatever the umask
    if (is_dir($root) && is_writable($root)) { ensureTireMediaHtaccess(); return $root; }
    $up = __DIR__ . '/uploads';
    if (!is_dir($up)) { mediaMkdir($up); }
    return $up;
}

/** Extension checks shared by every path → [$ext, $isVideo]; 415 otherwise. */
function tireUploadCheckName(string $origName): array {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') { $ext = 'jpg'; }
    if (in_array($ext, ['m4v', 'avi', 'mkv'], true)) {
        tireUploadFail(415, ".{$ext} isn't web-playable. Convert to MP4 first (QuickTime: File → Export As → 1080p).");
    }
    if (!in_array($ext, array_merge(imageExts(), videoExts()), true)) {
        tireUploadFail(415, 'Unsupported file type — use JPG, PNG, GIF, WebP, MP4, WebM, or MOV.');
    }
    return [$ext, isVideoExt($ext)];
}

/** Content check — the extension alone is never trusted: images must decode AND match the extension's
 *  format (a PHP/HTML file renamed .jpg fails here), videos must carry the container magic (EBML / ftyp). */
function tireUploadCheckContent(string $path, string $ext, bool $isVideo): void {
    if ($isVideo) {
        if (!videoFileLooksValid($path, $ext)) { tireUploadFail(422, 'Not a valid video file'); }
        return;
    }
    $info = @getimagesize($path);
    if ($info === false || (int)($info[0] ?? 0) <= 0 || (int)($info[1] ?? 0) <= 0) { tireUploadFail(422, 'Not a valid image'); }
    $byType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) { $byType[IMAGETYPE_WEBP] = 'webp'; }
    $detected = $byType[(int)($info[2] ?? 0)] ?? '';
    if ($detected === '' || $detected !== $ext) {
        tireUploadFail(422, $detected === '' ? 'Unsupported image format — use JPG, PNG, GIF or WebP.' : "The file is a {$detected} image, not .{$ext} — rename it and try again.");
    }
    if (function_exists('finfo_open')) {   // belt and braces: the MIME sniff must agree too
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string)@finfo_file($fi, $path) : '';
        if ($fi) { @finfo_close($fi); }
        if ($mime !== '' && strpos($mime, 'image/') !== 0) { tireUploadFail(422, 'Not a valid image'); }
    }
}

/** tire_id + client scope → [$tire, $company]; 400 / 404 / 403 otherwise. */
function tireUploadResolveTire(PDO $pdo, int $tireId, string $slug): array {
    if ($tireId <= 0) { tireUploadFail(400, 'Invalid tire_id'); }
    $tire = tireWithSlug($pdo, $tireId);
    if (!$tire) { tireUploadFail(404, 'Tire not found'); }
    if ($slug === '') { tireUploadFail(400, 'Pick a client first'); }
    $coStmt = $pdo->prepare("SELECT id, name, slug FROM companies WHERE id = ?");
    $coStmt->execute([(int)$tire['company_id']]);
    $company = $coStmt->fetch();
    if (!$company || (string)$company['slug'] !== $slug) { tireUploadFail(403, 'This tire belongs to another client'); }
    return [$tire, $company];
}

/** series_id (must belong to the tire) or new_series (created on first use, logged) → the series row. */
function tireUploadResolveSeries(PDO $pdo, array $tire, int $seriesId, string $newSeries, string $batchId): array {
    try {
        if ($seriesId > 0) {
            $series = tireSeriesById($pdo, $seriesId);
            if (!$series || (int)$series['tire_id'] !== (int)$tire['id']) { tireUploadFail(404, 'Series not found on this tire'); }
            return $series;
        }
        $created = createTireSeries($pdo, (int)$tire['id'], $newSeries);
        $isNew   = !empty($created['created']);
        unset($created['created']);
        if ($isNew) {
            logTireSeriesActivity($pdo, actorFromPost(), 'created', (int)$created['id'],
                'Created series ' . (string)$tire['name'] . ' · ' . $created['name'], null, $batchId, (int)$tire['company_id']);
        }
        return $created;
    } catch (Throwable $e) {
        error_log('tire-upload series: ' . $e->getMessage());
        tireUploadFail(500, 'Database error');
    }
    return [];
}

/**
 * Put a validated file into media/tires/<slug>/<folder>/ (fallback uploads/), insert the pending row,
 * make the thumb, log tire_series/uploaded and print the reply. $uploaded: true = PHP upload tmp
 * (move_uploaded_file), false = a spool file (rename).
 */
function tireUploadStore(PDO $pdo, array $company, array $tire, array $series, string $srcPath, string $origName, string $ext, bool $isVideo, string $batchId, bool $uploaded, array $extraReply = []): void {
    $storage  = 'media';
    $mediaDir = tireSeriesFolderPath($company, $tire, $series);   // <root>/<slug [a-z0-9-]>/<folder: no slashes, no dot prefix>
    mediaMkdir($mediaDir, mediaRootPath());   // created folders 0755 whatever the umask; the chain up to media/ made traversable
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
        if (!is_dir($mediaDir)) { mediaMkdir($mediaDir); }
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
    $moved = $uploaded ? move_uploaded_file($srcPath, $dest) : (@rename($srcPath, $dest) || (@copy($srcPath, $dest) && @unlink($srcPath)));
    if (!$moved) { tireUploadFail(500, 'Failed to save the file (check folder permissions)'); }
    mediaChmodPath($dest);   // 0644: the upload tmp / chunk spool file was 0600 (unreadable by Apache)

    $tireId = (int)$tire['id'];
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
    ] + $extraReply);
}

// =====================================================================
// probe — what the client should send
// =====================================================================
if ($action === 'probe') {
    $root = tireUploadSpoolRoot();
    chunkSpoolCleanup($root);
    echo json_encode([
        'ok'             => true,
        'chunk_size'     => chunkUploadChunkSize(),
        'max_file_bytes' => ['image' => $maxImageBytes, 'video' => $maxChunkedVideo],
        'single_max_bytes' => ['image' => $maxImageBytes, 'video' => min($maxVideoBytes, chunkUploadIniBytes() ?: $maxVideoBytes)],
        'ini_max'        => $iniMax,
        'ini_max_bytes'  => chunkUploadIniBytes(),
        'exts'           => array_values(array_unique(array_merge(imageExts(), videoExts()))),
    ]);
    exit;
}

$batchId = chunkUploadBatchId($_POST['batch'] ?? '', 'tire-upload');

// =====================================================================
// chunk_init — validate the target + name + size, allocate the spool
// =====================================================================
if ($action === 'chunk_init') {
    [$tire, $company] = tireUploadResolveTire($pdo, (int)($_POST['tire_id'] ?? 0), postedClientSlug());
    $seriesId  = (int)($_POST['series_id'] ?? 0);
    $newSeries = trim((string)($_POST['new_series'] ?? ''));
    if ($seriesId <= 0 && $newSeries === '') { tireUploadFail(400, 'series_id or new_series is required'); }
    if ($newSeries !== '' && mb_strlen($newSeries, 'UTF-8') > 120) { tireUploadFail(400, 'Series name is too long (max 120 characters)'); }
    $origName = basename(str_replace('\\', '/', trim((string)($_POST['name'] ?? ''))));
    if ($origName === '') { tireUploadFail(400, 'File name is required'); }
    [$ext, $isVideo] = tireUploadCheckName($origName);
    $size = (int)($_POST['size'] ?? 0);
    if ($size <= 0) { tireUploadFail(400, 'File size is required'); }
    $limit = $isVideo ? $maxChunkedVideo : $maxImageBytes;
    if ($size > $limit) {
        $mb = (int)round($limit / (1024 * 1024));
        tireUploadFail(413, ($isVideo ? 'Videos' : 'Images') . ' must be under ' . ($isVideo ? (int)round($limit / (1024 * 1024 * 1024)) . ' GB' : "{$mb} MB") . '.', ['limit_mb' => $mb, 'ini_max' => $iniMax]);
    }
    $series = tireUploadResolveSeries($pdo, $tire, $seriesId, $newSeries, $batchId);
    $root = tireUploadSpoolRoot();
    chunkSpoolCleanup($root);
    $meta = chunkUploadInit($root, [
        'kind'       => 'tire',
        'client'     => (string)$company['slug'],
        'company_id' => (int)$company['id'],
        'tire_id'    => (int)$tire['id'],
        'series_id'  => (int)$series['id'],
        'name'       => $origName,
        'ext'        => $ext,
        'is_video'   => $isVideo,
        'size'       => $size,
        'mime'       => substr(preg_replace('/[^a-z0-9\/\.\-\+]/', '', strtolower((string)($_POST['type'] ?? ''))), 0, 80),
        'batch'      => $batchId,
        'actor'      => actorFromPost(),
    ]);
    if ($meta === null) { tireUploadFail(500, 'Neither media/tires/ nor uploads/ is writable on the server.'); }
    echo json_encode([
        'ok'         => true,
        'upload_id'  => $meta['upload_id'],
        'chunk_size' => chunkUploadChunkSize(),
        'received'   => 0,
        'size'       => $size,
        'series'     => tireSeriesById($pdo, (int)$series['id']) ?: $series,
        'batch'      => $batchId,
    ]);
    exit;
}

// =====================================================================
// chunk_put / chunk_status / chunk_finish / chunk_abort — need an upload_id owned by this client
// =====================================================================
if (in_array($action, ['chunk_put', 'chunk_status', 'chunk_finish', 'chunk_abort'], true)) {
    $uploadId = (string)($_POST['upload_id'] ?? '');
    if (!chunkUploadValidId($uploadId)) { tireUploadFail(400, 'Invalid upload_id'); }
    $root = tireUploadSpoolRoot();
    $meta = chunkUploadMeta($root, $uploadId);
    if ($meta === null || ($meta['kind'] ?? '') !== 'tire') { tireUploadFail(404, 'Unknown upload — it may have expired. Start it again.'); }
    $slug = postedClientSlug();
    if ($slug === '' || (string)($meta['client'] ?? '') !== $slug) { tireUploadFail(403, 'This upload belongs to another client'); }
    $size = (int)($meta['size'] ?? 0);

    if ($action === 'chunk_status') {
        echo json_encode(['ok' => true, 'upload_id' => $uploadId, 'received' => (int)$meta['received'], 'size' => $size, 'chunk_size' => chunkUploadChunkSize()]);
        exit;
    }
    if ($action === 'chunk_abort') {
        chunkUploadDiscard($root, $uploadId);
        echo json_encode(['ok' => true, 'upload_id' => $uploadId, 'aborted' => true]);
        exit;
    }
    if ($action === 'chunk_put') {
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { tireUploadFail(400, 'No chunk received', ['received' => (int)$meta['received']]); }
        $err = (int)$_FILES['file']['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            tireUploadFail(413, "Piece too large for the server (PHP limit: {$iniMax}) — reload and try again.", ['ini_max' => $iniMax, 'chunk_size' => chunkUploadChunkSize()]);
        }
        if ($err !== UPLOAD_ERR_OK) { tireUploadFail(400, "Upload error code {$err}", ['received' => (int)$meta['received']]); }
        $offset = (int)($_POST['offset'] ?? -1);
        if ($offset < 0) { tireUploadFail(400, 'Invalid offset', ['received' => (int)$meta['received']]); }
        $r = chunkUploadAppend($root, $uploadId, (string)$_FILES['file']['tmp_name'], $offset, $size);
        if (!$r['ok']) { tireUploadFail((int)$r['code'], (string)$r['error'], ['received' => (int)$r['received'], 'size' => $size]); }
        echo json_encode(['ok' => true, 'upload_id' => $uploadId, 'received' => (int)$r['received'], 'size' => $size, 'index' => (int)($_POST['index'] ?? 0)]);
        exit;
    }
    // chunk_finish
    if ((int)$meta['received'] !== $size) {
        tireUploadFail(409, 'Upload incomplete — ' . (int)$meta['received'] . ' of ' . $size . ' bytes received.', ['received' => (int)$meta['received'], 'size' => $size]);
    }
    $tireUploadDiscard = [$root, $uploadId];   // any failure from here on drops the spool
    [$tire, $company] = tireUploadResolveTire($pdo, (int)($meta['tire_id'] ?? 0), $slug);
    $series = tireUploadResolveSeries($pdo, $tire, (int)($meta['series_id'] ?? 0), '', (string)($meta['batch'] ?? $batchId));
    [$ext, $isVideo] = tireUploadCheckName((string)$meta['name']);
    $paths = chunkSpoolPaths($root, $uploadId);
    tireUploadCheckContent($paths['part'], $ext, $isVideo);
    // The store renames the part file into place (a failure inside it → tireUploadFail → the discard hook
    // above removes whatever is left of the spool); after a successful reply only the sidecar remains.
    tireUploadStore($pdo, $company, $tire, $series, $paths['part'], (string)$meta['name'], $ext, $isVideo, (string)($meta['batch'] ?? $batchId), false, ['upload_id' => $uploadId]);
    $tireUploadDiscard = null;
    chunkUploadDiscard($root, $uploadId);
    exit;
}

// =====================================================================
// single-request upload (small files)
// =====================================================================
[$tire, $company] = tireUploadResolveTire($pdo, (int)($_POST['tire_id'] ?? 0), postedClientSlug());
$seriesId  = (int)($_POST['series_id'] ?? 0);
$newSeries = trim((string)($_POST['new_series'] ?? ''));
if ($seriesId <= 0 && $newSeries === '') { tireUploadFail(400, 'series_id or new_series is required'); }
if ($newSeries !== '' && mb_strlen($newSeries, 'UTF-8') > 120) { tireUploadFail(400, 'Series name is too long (max 120 characters)'); }

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
[$ext, $isVideo] = tireUploadCheckName($origName);
$limit = $isVideo ? $maxVideoBytes : $maxImageBytes;
if ($size > $limit) {
    $mb = (int)round($limit / (1024 * 1024));
    tireUploadFail(413, ($isVideo ? 'Videos' : 'Images') . " must be under {$mb} MB.", ['limit_mb' => $mb, 'ini_max' => $iniMax]);
}
tireUploadCheckContent($tmpName, $ext, $isVideo);

$series = tireUploadResolveSeries($pdo, $tire, $seriesId, $newSeries, $batchId);
tireUploadStore($pdo, $company, $tire, $series, $tmpName, $origName, $ext, $isVideo, $batchId, true);
