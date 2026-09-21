<?php
/**
 * The one upload endpoint for files that end up in <app>/uploads/ — Compose, Studio → Uploads / Batch,
 * tire reference images, and Replace on posts / tires. Admin only, same-site only, JSON always.
 * Videos up to 4 GB and images up to 50 MB: a small file goes in one request, a large one in pieces
 * (chunk-upload-lib.php — the same protocol as tire-upload.php / page-upload.php).
 *
 *   action=probe          GET or POST → {ok, chunk_size, max_file_bytes:{image, video}, ini_max, exts, feature_exts}
 *   action=upload         one multipart request: purpose fields + `file`            → the purpose's reply
 *   action=chunk_init     purpose fields + name, size, type                          → {upload_id, chunk_size, received: 0}
 *   action=chunk_put      upload_id, index, offset + `file` = the piece              → {received} (409 {received} when out of order)
 *   action=chunk_status   upload_id                                                  → {received, size}
 *   action=chunk_finish   upload_id → validates the spooled file like a single upload → the purpose's reply
 *   action=chunk_abort    upload_id                                                  → {aborted}
 *   action=claim_discard  token (+ client)                                           → {discarded}   (a parked Compose / Batch file the admin removed)
 *
 * Purpose fields (chunk_init / upload; `client` is always required and is the tenant scope):
 *   purpose=post      Compose one-offs         → the file is parked as uploads/tmp_<token>.<ext>; reply
 *   purpose=batch     Studio → Uploads / Batch    {ok, token, name, size, type: image|video, mime, preview_url}
 *                     and the form posts claimed[] = token (add-post.php / batch-process.php turn it into the
 *                     final img_ / vid_ / batch_ file and the row; unclaimed files are removed after 24 h).
 *   purpose=feature   feature_id = tires.id     → images only; stored as uploads/feat_*, the reference row is
 *                     inserted (6 per item — 409 when full); reply {ok, image:{id, image_url, src, thumb, …}, count, max}
 *   purpose=replace   replace_kind = post|tire, replace_id = the image row → the file is swapped in right away;
 *                     reply = replace-image.php's {ok, image_id, image_url, src, media_type}
 *
 * Errors: 400 bad request · 403 seat / tenant / cross-site · 404 unknown row or upload · 405 · 409 chunk out of
 * order / incomplete / reference set full · 413 too large · 415 unsupported type · 422 not a valid image/video · 500.
 * Spool: uploads/.spool/ (dot-folder, deny-all .htaccess, 0600 files, 24 h cleanup on probe / init).
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/chunk-upload-lib.php';
require_once __DIR__ . '/upload-lib.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }

header('Content-Type: application/json');

$ucDiscard = null;   // [root, upload_id] — a failing chunk_finish drops its spool before replying

function ucFail(int $code, string $msg, array $extra = []): void {
    global $ucDiscard;
    if ($ucDiscard) { chunkUploadDiscard($ucDiscard[0], $ucDiscard[1]); $ucDiscard = null; }
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    exit;
}
function ucReply(array $r): void {
    if ((int)$r['code'] !== 200) { http_response_code((int)$r['code']); }
    echo json_encode($r['body']);
    exit;
}

$action  = (string)($_POST['action'] ?? $_GET['action'] ?? 'upload');
$actions = ['probe', 'upload', 'chunk_init', 'chunk_put', 'chunk_status', 'chunk_finish', 'chunk_abort', 'claim_discard'];
if (!in_array($action, $actions, true)) { ucFail(400, 'Unknown action'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !($action === 'probe' && $_SERVER['REQUEST_METHOD'] === 'GET')) { ucFail(405, 'Method not allowed'); }
requireSameSiteFetch();   // cross-site requests get a JSON 403 (helpers.php)
if (!currentAdmin()) { ucFail(403, 'Admin sign-in required'); }

$iniMax   = (string)(ini_get('upload_max_filesize') ?: '?');
$purposes = ['post', 'batch', 'feature', 'replace'];

/** The spool root: the app's uploads/ (created 0755); 500 when unusable. */
function ucRoot(): string {
    $up = uploadsDirEnsure();
    if ($up === null) { ucFail(500, 'uploads/ is not writable on the server.'); }
    return $up;
}

/** Validate the purpose fields → the target array kept in the sidecar (404 / 403 / 409 / 400 on the way). */
function ucTarget(PDO $pdo, array $in): array {
    global $purposes;
    $purpose = (string)($in['purpose'] ?? '');
    if (!in_array($purpose, $purposes, true)) { ucFail(400, 'purpose must be post, batch, feature or replace'); }
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($in['client'] ?? ''))));
    if ($slug === '') { ucFail(400, 'Pick a client first'); }
    $co = $pdo->prepare("SELECT id, slug FROM companies WHERE slug = ?");
    $co->execute([$slug]);
    $company = $co->fetch();
    if (!$company) { ucFail(404, 'Unknown client'); }
    $t = ['purpose' => $purpose, 'client' => $slug, 'company_id' => (int)$company['id']];
    if ($purpose === 'feature') {
        $tire = uploadFeatureTire($pdo, (int)($in['feature_id'] ?? 0));
        if (!$tire) { ucFail(404, 'Item not found'); }
        if ((int)$tire['company_id'] !== (int)$company['id']) { ucFail(403, 'This item belongs to another client'); }
        $have = uploadFeatureCount($pdo, (int)$tire['id']);
        if ($have >= uploadFeatureMaxImages()) { ucFail(409, 'Max ' . uploadFeatureMaxImages() . ' reference images per item — remove one first.', ['count' => $have, 'max' => uploadFeatureMaxImages()]); }
        $t['feature_id'] = (int)$tire['id'];
    } elseif ($purpose === 'replace') {
        $kind = (string)($in['replace_kind'] ?? 'post');
        if (!in_array($kind, ['post', 'tire'], true)) { ucFail(400, 'replace_kind must be post or tire'); }
        $id = (int)($in['replace_id'] ?? 0);
        if ($id <= 0) { ucFail(400, 'Invalid replace_id'); }
        if (!uploadReplaceRow($pdo, $kind, $id)) { ucFail(404, 'Image not found'); }
        $owner = uploadReplaceOwner($pdo, $kind, $id);
        if ($owner > 0 && $owner !== (int)$company['id']) { ucFail(403, 'This image belongs to another client'); }
        $t['replace_kind'] = $kind;
        $t['replace_id']   = $id;
    }
    return $t;
}

/** Allowed extensions per purpose (reference images are images only). */
function ucExts(string $purpose): array {
    return $purpose === 'feature' ? imageExts() : array_merge(imageExts(), videoExts());
}

/** Name → [ext, isVideo] (415), then the size cap for that type (413). */
function ucCheckNameSize(string $purpose, string $origName, int $size): array {
    $c = uploadCheckName($origName, ucExts($purpose));
    if (isset($c['error'])) { ucFail(415, $c['error']); }
    $limit = uploadMaxBytes($c['video'] ? 'video' : 'image');
    if ($size > $limit) {
        ucFail(413, ($c['video'] ? 'Videos' : 'Images') . ' must be under ' . uploadCapLabel($limit) . '.', ['limit_bytes' => $limit, 'ini_max' => $GLOBALS['iniMax']]);
    }
    return [$c['ext'], (bool)$c['video']];
}

/** The purpose's finalize: the validated file at $src becomes a claim / reference row / replacement. Prints the reply. */
function ucFinalize(PDO $pdo, array $t, string $src, string $origName, string $ext, bool $isVideo, string $mime, bool $uploaded, array $extra = []): void {
    global $ucDiscard;
    $purpose = (string)$t['purpose'];
    if ($purpose === 'post' || $purpose === 'batch') {
        $claim = uploadClaimStore($src, $uploaded, ['purpose' => $purpose, 'client' => $t['client'], 'name' => $origName, 'ext' => $ext, 'video' => $isVideo, 'mime' => $mime]);
        if ($claim === null) { ucFail(500, 'Failed to save the file (check uploads/ permissions)'); }
        $ucDiscard = null;
        echo json_encode(['ok' => true, 'purpose' => $purpose] + $claim + $extra);
        exit;
    }
    if ($purpose === 'feature') {
        $tire = uploadFeatureTire($pdo, (int)$t['feature_id']);
        if (!$tire) { ucFail(404, 'Item not found'); }
        $r = uploadFeatureInsert($pdo, $tire, $src, $origName, $ext, $uploaded);
        if ((int)$r['code'] !== 200) { ucFail((int)$r['code'], (string)($r['body']['error'] ?? 'Upload failed'), array_diff_key($r['body'], ['ok' => 1, 'error' => 1])); }
        $ucDiscard = null;
        $r['body'] = ['purpose' => 'feature'] + $r['body'] + $extra;
        ucReply($r);
    }
    // replace
    $r = uploadReplaceApply($pdo, (string)$t['replace_kind'], (int)$t['replace_id'], $src, $ext, $isVideo, $uploaded);
    if ((int)$r['code'] !== 200) { ucFail((int)$r['code'], (string)($r['body']['error'] ?? 'Replace failed')); }
    $ucDiscard = null;
    $r['body'] = ['purpose' => 'replace'] + $r['body'] + $extra;
    ucReply($r);
}

// =====================================================================
// probe — what the client should send
// =====================================================================
if ($action === 'probe') {
    ucRoot();
    if (uploadClaimSidecarDir(true) === null) { ucFail(500, 'uploads/ is not writable on the server.'); }   // uploads/.spool + its deny-all .htaccess exist from the first probe on
    uploadClaimCleanup();
    echo json_encode([
        'ok'               => true,
        'chunk_size'       => chunkUploadChunkSize(),
        'max_file_bytes'   => ['image' => uploadMaxBytes('image'), 'video' => uploadMaxBytes('video')],
        'single_max_bytes' => chunkUploadIniBytes(),
        'ini_max'          => $iniMax,
        'ini_max_bytes'    => chunkUploadIniBytes(),
        'exts'             => array_values(array_unique(array_merge(imageExts(), videoExts()))),
        'feature_exts'     => imageExts(),
        'max_files'        => ['post' => 10, 'batch' => 50, 'feature' => uploadFeatureMaxImages()],
    ]);
    exit;
}

// =====================================================================
// claim_discard — the admin removed a parked file from the form before submitting
// =====================================================================
if ($action === 'claim_discard') {
    $token = (string)($_POST['token'] ?? '');
    if (!uploadClaimValidToken($token)) { ucFail(400, 'Invalid token'); }
    $slug = postedClientSlug();
    $found = null;
    foreach (['post', 'batch'] as $p) { $found = $found ?? uploadClaimRead($token, $p, $slug); }
    if ($found === null) { ucFail(404, 'Unknown token'); }
    uploadClaimDiscard($token, true);
    echo json_encode(['ok' => true, 'token' => $token, 'discarded' => true]);
    exit;
}

// =====================================================================
// upload — one multipart request (small files)
// =====================================================================
if ($action === 'upload') {
    $t = ucTarget($pdo, $_POST);
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { ucFail(400, 'No file uploaded'); }
    $err = (int)$_FILES['file']['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        ucFail(413, "File too large for one request (PHP limit: {$iniMax}) — it should have been sent in pieces; reload and try again.", ['ini_max' => $iniMax, 'chunk_size' => chunkUploadChunkSize()]);
    }
    if ($err !== UPLOAD_ERR_OK) { ucFail(400, "Upload error code {$err}"); }
    $origName = basename(str_replace('\\', '/', (string)$_FILES['file']['name']));
    $tmpName  = (string)$_FILES['file']['tmp_name'];
    $size     = (int)$_FILES['file']['size'];
    [$ext, $isVideo] = ucCheckNameSize((string)$t['purpose'], $origName, $size);
    $why = uploadCheckContent($tmpName, $ext, $isVideo);
    if ($why !== '') { ucFail(422, $why); }
    $mime = substr(preg_replace('/[^a-z0-9\/\.\-\+]/', '', strtolower((string)($_FILES['file']['type'] ?? ''))), 0, 80);
    ucFinalize($pdo, $t, $tmpName, $origName, $ext, $isVideo, $mime, true);
}

// =====================================================================
// chunk_init — validate the target + name + size, allocate the spool
// =====================================================================
if ($action === 'chunk_init') {
    $t = ucTarget($pdo, $_POST);
    $origName = basename(str_replace('\\', '/', trim((string)($_POST['name'] ?? ''))));
    if ($origName === '') { ucFail(400, 'File name is required'); }
    $size = (int)($_POST['size'] ?? 0);
    if ($size <= 0) { ucFail(400, 'File size is required'); }
    [$ext, $isVideo] = ucCheckNameSize((string)$t['purpose'], $origName, $size);
    $root = ucRoot();
    uploadClaimCleanup();
    $meta = chunkUploadInit($root, $t + [
        'kind'     => 'app',
        'name'     => $origName,
        'ext'      => $ext,
        'is_video' => $isVideo,
        'size'     => $size,
        'mime'     => substr(preg_replace('/[^a-z0-9\/\.\-\+]/', '', strtolower((string)($_POST['type'] ?? ''))), 0, 80),
        'actor'    => actorFromPost(),
    ]);
    if ($meta === null) { ucFail(500, 'uploads/ is not writable on the server.'); }
    echo json_encode([
        'ok'         => true,
        'upload_id'  => $meta['upload_id'],
        'chunk_size' => chunkUploadChunkSize(),
        'received'   => 0,
        'size'       => $size,
        'purpose'    => $t['purpose'],
    ]);
    exit;
}

// =====================================================================
// chunk_put / chunk_status / chunk_finish / chunk_abort — need an upload_id owned by this client
// =====================================================================
$uploadId = (string)($_POST['upload_id'] ?? '');
if (!chunkUploadValidId($uploadId)) { ucFail(400, 'Invalid upload_id'); }
$root = ucRoot();
$meta = chunkUploadMeta($root, $uploadId);
if ($meta === null || ($meta['kind'] ?? '') !== 'app') { ucFail(404, 'Unknown upload — it may have expired. Start it again.'); }
$slug = postedClientSlug();
if ($slug === '' || (string)($meta['client'] ?? '') !== $slug) { ucFail(403, 'This upload belongs to another client'); }
$size = (int)($meta['size'] ?? 0);

if ($action === 'chunk_status') {
    echo json_encode(['ok' => true, 'upload_id' => $uploadId, 'received' => (int)$meta['received'], 'size' => $size, 'chunk_size' => chunkUploadChunkSize(), 'purpose' => $meta['purpose'] ?? '']);
    exit;
}
if ($action === 'chunk_abort') {
    chunkUploadDiscard($root, $uploadId);
    echo json_encode(['ok' => true, 'upload_id' => $uploadId, 'aborted' => true]);
    exit;
}
if ($action === 'chunk_put') {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { ucFail(400, 'No chunk received', ['received' => (int)$meta['received']]); }
    $err = (int)$_FILES['file']['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        ucFail(413, "Piece too large for the server (PHP limit: {$iniMax}) — reload and try again.", ['ini_max' => $iniMax, 'chunk_size' => chunkUploadChunkSize()]);
    }
    if ($err !== UPLOAD_ERR_OK) { ucFail(400, "Upload error code {$err}", ['received' => (int)$meta['received']]); }
    $offset = (int)($_POST['offset'] ?? -1);
    if ($offset < 0) { ucFail(400, 'Invalid offset', ['received' => (int)$meta['received']]); }
    $r = chunkUploadAppend($root, $uploadId, (string)$_FILES['file']['tmp_name'], $offset, $size);
    if (!$r['ok']) { ucFail((int)$r['code'], (string)$r['error'], ['received' => (int)$r['received'], 'size' => $size]); }
    echo json_encode(['ok' => true, 'upload_id' => $uploadId, 'received' => (int)$r['received'], 'size' => $size, 'index' => (int)($_POST['index'] ?? 0)]);
    exit;
}
// chunk_finish
if ((int)$meta['received'] !== $size) {
    ucFail(409, 'Upload incomplete — ' . (int)$meta['received'] . ' of ' . $size . ' bytes received.', ['received' => (int)$meta['received'], 'size' => $size]);
}
$ucDiscard = [$root, $uploadId];   // any failure from here on drops the spool
$t = ucTarget($pdo, $meta);        // re-checked: the row / item may have gone, the reference set may be full by now
[$ext, $isVideo] = ucCheckNameSize((string)$t['purpose'], (string)$meta['name'], $size);
$paths = chunkSpoolPaths($root, $uploadId);
$why = uploadCheckContent($paths['part'], $ext, $isVideo);
if ($why !== '') { ucFail(422, $why); }
// The finalize renames the part file into place; after a successful reply only the sidecar remains — drop it.
register_shutdown_function(function () use ($root, $uploadId) { chunkUploadDiscard($root, $uploadId); });
ucFinalize($pdo, $t, $paths['part'], (string)$meta['name'], $ext, $isVideo, (string)($meta['mime'] ?? ''), false, ['upload_id' => $uploadId]);
