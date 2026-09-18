<?php
/**
 * Page files endpoint (admin only; scratchpad pages-design.md). One file per request.
 *
 * POST multipart / urlencoded:
 *   client        company slug — must be the page's company (403 otherwise; helpers.php scopes it too)
 *   page_id       pages.id (source must be 'upload')
 *   action        upload (default) | delete_file | set_entry | probe | chunk_init | chunk_put | chunk_status | chunk_finish | chunk_abort
 *   file          the upload (action=upload): html htm css js json png jpg jpeg gif webp svg ico
 *                 woff woff2 ttf mp4 webm, ≤ 10 MB; images must decode and match their extension
 *   subfolder     optional relative folder inside the page folder ('img', 'assets/fonts'):
 *                 [a-z0-9_-] segments, ≤ 4 deep, no '..' (400 otherwise)
 *   name          the stored relative name for delete_file / set_entry ('index.html', 'img/hero.png')
 *   batch         optional batch token shared by one drop (one activity line for the whole drop)
 *
 * Chunked, resumable uploads (large videos / assets; chunk-upload-lib.php) — the same protocol as
 * tire-upload.php: probe (GET or POST) → {chunk_size, max_file_bytes:{video, asset, text}, ini_max, exts};
 * chunk_init {client, page_id, name, size, type, subfolder, batch} → {upload_id, chunk_size, received: 0};
 * chunk_put {upload_id, index, offset, file}; chunk_status; chunk_finish (same checks + reply as
 * action=upload); chunk_abort. Spool: media/pages/.spool/ (deny-all .htaccess, cleaned after 24 h).
 * Chunked caps: video 4 GB, other assets 100 MB, HTML / CSS / JS / JSON stay at 10 MB (their body is scanned).
 *
 * Stores media/pages/<client-slug>/<page-slug>/[subfolder/]<safe-name> (an existing file with
 * the same name is REPLACED — that is how a page gets updated), upserts page_files, logs
 * page/uploaded. File names are sanitised (pageSanitizeFilename): basename only, no dotfiles,
 * no .php/.phtml/.htaccess anywhere in the dotted chain (415), unknown extensions 415,
 * dotfiles / traversal 400. media/pages/.htaccess (no PHP, no listing) is (re)written on every upload.
 *
 * Replies JSON {ok, file:{name, size, url, entry}, page:{id, entry, file_count}, batch}
 *   delete_file → {ok, name, deleted, page:{…}}   set_entry → {ok, page:{…}}
 * Errors: 400 bad request · 403 seat / tenant / cross-site · 404 unknown page, file or upload ·
 *         409 not an upload page / not migrated / chunk out of order · 413 too large · 415 unsupported type · 422 not a valid image · 500.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/chunk-upload-lib.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }

header('Content-Type: application/json');

$pageUploadDiscard = null;   // [root, upload_id] — a failing chunk_finish drops its spool before replying

function pageUploadFail(int $code, string $msg, array $extra = []): void {
    global $pageUploadDiscard;
    if ($pageUploadDiscard) { chunkUploadDiscard($pageUploadDiscard[0], $pageUploadDiscard[1]); $pageUploadDiscard = null; }
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    exit;
}
function pageUploadSummary(PDO $pdo, array $page): array {
    return ['id' => (int)$page['id'], 'entry' => (string)$page['entry'], 'file_count' => count(pageFilesFor($pdo, (int)$page['id']))];
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'upload');
if (!in_array($action, ['upload', 'delete_file', 'set_entry', 'probe', 'chunk_init', 'chunk_put', 'chunk_status', 'chunk_finish', 'chunk_abort'], true)) { pageUploadFail(400, 'Unknown action'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !($action === 'probe' && $_SERVER['REQUEST_METHOD'] === 'GET')) { pageUploadFail(405, 'Method not allowed'); }
requireSameSiteFetch();   // cross-site requests get a JSON 403 (helpers.php)
if (!currentAdmin()) { pageUploadFail(403, 'Admin sign-in required'); }
if (!hasPagesTable($pdo)) { pageUploadFail(409, 'Pages are not set up yet — run migrate.php.'); }

$maxBytes       = 10 * 1024 * 1024;               // single request (and the text cap for chunked)
$maxChunkedVideo = 4 * 1024 * 1024 * 1024;        // chunked video (mp4 / webm)
$maxChunkedAsset = 100 * 1024 * 1024;             // chunked images / fonts / other assets
$iniMax         = (string)(ini_get('upload_max_filesize') ?: '?');
$textExts       = ['html', 'htm', 'css', 'js', 'json'];

/** Spool root: media/pages (created when missing). Null when it cannot be written. */
function pageUploadSpoolRoot(): ?string {
    $root = pagesMediaRootPath();
    if (!is_dir($root)) { @mkdir($root, 0755, true); }
    if (!is_dir($root) || !is_writable($root)) return null;
    ensurePagesMediaHtaccess();
    return $root;
}

/** Name checks shared by every path → [$name (sanitised), $ext]; 400 / 415 otherwise. */
function pageUploadCheckName(string $origName): array {
    $baseName = basename(str_replace('\\', '/', trim($origName)));
    if ($baseName === '' || $baseName[0] === '.') { pageUploadFail(400, 'Hidden files (names starting with a dot) are not allowed'); }
    $ext = strtolower((string)pathinfo($baseName, PATHINFO_EXTENSION));
    foreach (explode('.', strtolower($baseName)) as $part) {
        if (in_array($part, pageForbiddenExts(), true)) {
            pageUploadFail(415, 'Server-side files (.php, .phtml, .htaccess …) can never be uploaded here.');
        }
    }
    if ($ext === '' || !in_array($ext, pageUploadExts(), true)) {
        pageUploadFail(415, 'Unsupported file type — use HTML, CSS, JS, JSON, images (PNG, JPG, GIF, WebP, SVG, ICO), fonts (WOFF, WOFF2, TTF) or MP4 / WebM video.');
    }
    $name = pageSanitizeFilename($baseName);
    if ($name === '') { pageUploadFail(400, 'That file name cannot be used — rename it and try again.'); }
    return [$name, $ext];
}

/** The chunked size cap for an extension (video 4 GB · text 10 MB · other assets 100 MB). */
function pageUploadChunkedLimit(string $ext): int {
    global $maxChunkedVideo, $maxChunkedAsset, $maxBytes, $textExts;
    if (in_array($ext, ['mp4', 'webm'], true)) return $maxChunkedVideo;
    if (in_array($ext, $textExts, true)) return $maxBytes;
    return $maxChunkedAsset;
}

/** Content check — images must decode AND match the extension's format, SVG must be SVG, videos are sniffed,
 *  text files must not carry a PHP open tag (whole body — it is capped at 10 MB). */
function pageUploadCheckContent(string $path, string $ext): void {
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
        $info = @getimagesize($path);
        if ($info === false || (int)($info[0] ?? 0) <= 0 || (int)($info[1] ?? 0) <= 0) { pageUploadFail(422, 'Not a valid image'); }
        $byType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
        if (defined('IMAGETYPE_WEBP')) { $byType[IMAGETYPE_WEBP] = 'webp'; }
        $detected = $byType[(int)($info[2] ?? 0)] ?? '';
        $want = $ext === 'jpeg' ? 'jpg' : $ext;
        if ($detected === '' || $detected !== $want) {
            pageUploadFail(422, $detected === '' ? 'Unsupported image format — use PNG, JPG, GIF or WebP.' : "The file is a {$detected} image, not .{$ext} — rename it and try again.");
        }
    } elseif ($ext === 'svg') {
        // SVG is XML: it must parse and its root must be <svg> (no scripts inside the sandboxed page's own origin matter, but a
        // renamed HTML/PHP file must not pass as an image).
        $head = (string)@file_get_contents($path, false, null, 0, 4096);
        if (!preg_match('/<svg[\s>]/i', $head) || preg_match('/<\?php/i', $head)) { pageUploadFail(422, 'Not a valid SVG image'); }
    } elseif (in_array($ext, ['mp4', 'webm'], true)) {
        if (function_exists('videoFileLooksValid') && !videoFileLooksValid($path, $ext)) { pageUploadFail(422, 'Not a valid video file'); }
    } elseif (in_array($ext, ['html', 'htm', 'css', 'js', 'json'], true)) {
        // Text-ish files (html/css/js/json): refuse anything that carries a PHP open tag — the WHOLE
        // file (≤ 10 MB), not just the head, so a tag after a large preamble cannot slip through.
        $body = (string)@file_get_contents($path);
        if (preg_match('/<\?php|<\?=/i', $body)) {
            pageUploadFail(415, 'PHP code is not allowed in page files.');
        }
        unset($body);
    }
}

/** page_id + client scope (+ upload source) → [$page, $company]; 400 / 404 / 403 / 409 otherwise. */
function pageUploadResolvePage(PDO $pdo, int $pageId, string $slug): array {
    if ($pageId <= 0) { pageUploadFail(400, 'Invalid page_id'); }
    $page = pageById($pdo, $pageId);
    if (!$page) { pageUploadFail(404, 'Page not found'); }
    if ($slug === '') { pageUploadFail(400, 'Pick a client first'); }
    if ((string)$page['company_slug'] !== $slug) { pageUploadFail(403, 'This page belongs to another client'); }
    if (strtolower((string)$page['source']) !== 'upload') { pageUploadFail(409, 'This page links to an external URL — switch its source to Upload first.'); }
    return [$page, ['id' => (int)$page['company_id'], 'slug' => (string)$page['company_slug']]];
}

/** The validated subfolder (400 otherwise). */
function pageUploadCheckSubfolder(string $raw): string {
    $subfolder = trim($raw, " \t\n\r/");
    if (!pageSubfolderValid($subfolder)) {
        pageUploadFail(400, 'Subfolder may only use lowercase letters, digits, - and _ (e.g. img or assets/fonts), no ".."');
    }
    return $subfolder;
}

/**
 * Put a validated file into media/pages/<client>/<slug>/[subfolder]/<name> (replacing an existing one),
 * upsert page_files, promote the first HTML to entry, log page/uploaded and print the reply.
 * $uploaded: true = PHP upload tmp (move_uploaded_file), false = a spool file (rename).
 */
function pageUploadStore(PDO $pdo, array $company, array $page, string $srcPath, string $name, string $ext, string $subfolder, int $size, string $batchId, bool $uploaded, array $extraReply = []): void {
    $pageId = (int)$page['id'];
    $dir = pageFolderContained($company, $page);
    if ($dir === null) { pageUploadFail(500, 'Page folder resolves outside media/pages/'); }
    $destDir = $subfolder !== '' ? $dir . '/' . $subfolder : $dir;
    if (!is_dir($destDir)) { @mkdir($destDir, 0755, true); }
    if (!is_dir($destDir) || !is_writable($destDir)) {
        pageUploadFail(500, 'media/pages/ is not writable on the server — create it next to the portal folder and give it write permission.');
    }
    // The subfolder chain must resolve inside the (already contained) page folder — a symlinked
    // segment left on the server must not redirect the write elsewhere.
    if ($subfolder !== '') {
        $dirReal = realpath($dir); $destReal = realpath($destDir);
        if ($dirReal !== false && ($destReal === false || strpos($destReal . '/', rtrim($dirReal, '/') . '/') !== 0)) {
            pageUploadFail(500, 'Subfolder resolves outside the page folder');
        }
        foreach (explode('/', $subfolder) as $i => $seg) {
            $probe = $dir . '/' . implode('/', array_slice(explode('/', $subfolder), 0, $i + 1));
            if (is_link($probe)) { pageUploadFail(500, 'Subfolder resolves outside the page folder'); }
        }
    }
    ensurePagesMediaHtaccess();   // media/pages/.htaccess (+ media/.htaccess when absent): no PHP/CGI, no listing
    $rel  = $subfolder !== '' ? $subfolder . '/' . $name : $name;
    if (!pageFileRelValid($rel)) { pageUploadFail(400, 'That file name cannot be used'); }
    $dest = $destDir . '/' . $name;
    if (is_link($dest) || (file_exists($dest) && !is_file($dest))) { pageUploadFail(500, 'Destination is not a regular file'); }
    $replaced = is_file($dest) ? 1 : 0;
    $moved = $uploaded ? move_uploaded_file($srcPath, $dest) : (@rename($srcPath, $dest) || (@copy($srcPath, $dest) && @unlink($srcPath)));
    if (!$moved) { pageUploadFail(500, 'Failed to save the file (check folder permissions)'); }
    @chmod($dest, 0644);

    try {
        $pdo->beginTransaction();
        pageFileUpsert($pdo, $pageId, $rel, $size);
        // First HTML file on a page whose entry is missing becomes the entry automatically.
        $entry = (string)$page['entry'];
        if (in_array($ext, ['html', 'htm'], true)) {
            $haveEntry = false;
            foreach (pageFilesFor($pdo, $pageId) as $f) { if ((string)$f['filename'] === $entry) { $haveEntry = true; break; } }
            if (!$haveEntry) {
                $pdo->prepare("UPDATE pages SET entry = ? WHERE id = ? AND company_id = ?")->execute([$rel, $pageId, (int)$page['company_id']]);
                $page['entry'] = $rel;
            }
        }
        logPageActivity($pdo, actorFromPost(), 'uploaded', $pageId, ($replaced ? 'Replaced ' : 'Uploaded ') . $rel . ' on ' . pageDisplayLabel($page), $rel, $batchId, (int)$page['company_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!$replaced) { @unlink($dest); }
        error_log('page-upload insert: ' . $e->getMessage());
        pageUploadFail(500, 'Database error');
    }

    $folderRel = pageFolderRel($company, $page);
    $url = '/' . implode('/', array_map('rawurlencode', array_merge(explode('/', $folderRel), explode('/', $rel))));
    echo json_encode([
        'ok'   => true,
        'file' => ['name' => $rel, 'size' => $size, 'url' => $url, 'entry' => $rel === (string)$page['entry'] ? 1 : 0, 'replaced' => $replaced],
        'page' => pageUploadSummary($pdo, $page),
        'view' => pageViewUrl($page, $company),
        'batch' => $batchId,
    ] + $extraReply);
}

// =====================================================================
// probe
// =====================================================================
if ($action === 'probe') {
    $root = pageUploadSpoolRoot();
    if ($root !== null) chunkSpoolCleanup($root);
    echo json_encode([
        'ok'             => true,
        'chunk_size'     => chunkUploadChunkSize(),
        'max_file_bytes' => ['video' => $maxChunkedVideo, 'asset' => $maxChunkedAsset, 'text' => $maxBytes],
        'single_max_bytes' => min($maxBytes, chunkUploadIniBytes() ?: $maxBytes),
        'text_exts'      => $textExts,
        'video_exts'     => ['mp4', 'webm'],
        'ini_max'        => $iniMax,
        'ini_max_bytes'  => chunkUploadIniBytes(),
        'exts'           => pageUploadExts(),
        'spool'          => $root !== null,
    ]);
    exit;
}

// Batch id: one per drop so the activity feed shows one line (same rule as tire-upload.php).
$batchId = chunkUploadBatchId($_POST['batch'] ?? '', 'page-upload');

// =====================================================================
// chunk_put / chunk_status / chunk_finish / chunk_abort — an upload_id owned by this client
// =====================================================================
if (in_array($action, ['chunk_put', 'chunk_status', 'chunk_finish', 'chunk_abort'], true)) {
    $uploadId = (string)($_POST['upload_id'] ?? '');
    if (!chunkUploadValidId($uploadId)) { pageUploadFail(400, 'Invalid upload_id'); }
    $root = pageUploadSpoolRoot();
    $meta = $root !== null ? chunkUploadMeta($root, $uploadId) : null;
    if ($meta === null || ($meta['kind'] ?? '') !== 'page') { pageUploadFail(404, 'Unknown upload — it may have expired. Start it again.'); }
    $slug = postedClientSlug();
    if ($slug === '' || (string)($meta['client'] ?? '') !== $slug) { pageUploadFail(403, 'This upload belongs to another client'); }
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
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { pageUploadFail(400, 'No chunk received', ['received' => (int)$meta['received']]); }
        $err = (int)$_FILES['file']['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            pageUploadFail(413, "Piece too large for the server (PHP limit: {$iniMax}) — reload and try again.", ['ini_max' => $iniMax, 'chunk_size' => chunkUploadChunkSize()]);
        }
        if ($err !== UPLOAD_ERR_OK) { pageUploadFail(400, "Upload error code {$err}", ['received' => (int)$meta['received']]); }
        $offset = (int)($_POST['offset'] ?? -1);
        if ($offset < 0) { pageUploadFail(400, 'Invalid offset', ['received' => (int)$meta['received']]); }
        $r = chunkUploadAppend($root, $uploadId, (string)$_FILES['file']['tmp_name'], $offset, $size);
        if (!$r['ok']) { pageUploadFail((int)$r['code'], (string)$r['error'], ['received' => (int)$r['received'], 'size' => $size]); }
        echo json_encode(['ok' => true, 'upload_id' => $uploadId, 'received' => (int)$r['received'], 'size' => $size, 'index' => (int)($_POST['index'] ?? 0)]);
        exit;
    }
    // chunk_finish
    if ((int)$meta['received'] !== $size) {
        pageUploadFail(409, 'Upload incomplete — ' . (int)$meta['received'] . ' of ' . $size . ' bytes received.', ['received' => (int)$meta['received'], 'size' => $size]);
    }
    $pageUploadDiscard = [$root, $uploadId];   // any failure from here on drops the spool
    [$page, $company] = pageUploadResolvePage($pdo, (int)($meta['page_id'] ?? 0), $slug);
    [$name, $ext] = pageUploadCheckName((string)$meta['name']);
    $subfolder = pageUploadCheckSubfolder((string)($meta['subfolder'] ?? ''));
    $paths = chunkSpoolPaths($root, $uploadId);
    pageUploadCheckContent($paths['part'], $ext);
    pageUploadStore($pdo, $company, $page, $paths['part'], $name, $ext, $subfolder, $size, (string)($meta['batch'] ?? $batchId), false, ['upload_id' => $uploadId]);
    $pageUploadDiscard = null;
    chunkUploadDiscard($root, $uploadId);
    exit;
}

// ---- the page + tenant scope (upload / delete_file / set_entry / chunk_init) ----
[$page, $company] = pageUploadResolvePage($pdo, (int)($_POST['page_id'] ?? 0), postedClientSlug());
$pageId = (int)$page['id'];

// =====================================================================
// chunk_init — validate name + size + subfolder, allocate the spool
// =====================================================================
if ($action === 'chunk_init') {
    [$name, $ext] = pageUploadCheckName((string)($_POST['name'] ?? ''));
    $size = (int)($_POST['size'] ?? 0);
    if ($size <= 0) { pageUploadFail(400, 'File size is required'); }
    $limit = pageUploadChunkedLimit($ext);
    if ($size > $limit) {
        $mb = (int)round($limit / (1024 * 1024));
        pageUploadFail(413, 'Files of this type must be under ' . ($limit >= 1024 * 1024 * 1024 ? (int)round($limit / (1024 * 1024 * 1024)) . ' GB' : "{$mb} MB") . '.', ['limit_mb' => $mb, 'ini_max' => $iniMax]);
    }
    $subfolder = pageUploadCheckSubfolder((string)($_POST['subfolder'] ?? ''));
    $root = pageUploadSpoolRoot();
    if ($root === null) { pageUploadFail(500, 'media/pages/ is not writable on the server — create it next to the portal folder and give it write permission.'); }
    chunkSpoolCleanup($root);
    $meta = chunkUploadInit($root, [
        'kind'       => 'page',
        'client'     => (string)$company['slug'],
        'company_id' => (int)$company['id'],
        'page_id'    => $pageId,
        'name'       => $name,
        'ext'        => $ext,
        'subfolder'  => $subfolder,
        'size'       => $size,
        'mime'       => substr(preg_replace('/[^a-z0-9\/\.\-\+]/', '', strtolower((string)($_POST['type'] ?? ''))), 0, 80),
        'batch'      => $batchId,
        'actor'      => actorFromPost(),
    ]);
    if ($meta === null) { pageUploadFail(500, 'media/pages/ is not writable on the server — create it next to the portal folder and give it write permission.'); }
    echo json_encode(['ok' => true, 'upload_id' => $meta['upload_id'], 'chunk_size' => chunkUploadChunkSize(), 'received' => 0, 'size' => $size, 'batch' => $batchId]);
    exit;
}

// ---- delete_file / set_entry ----
if ($action === 'delete_file' || $action === 'set_entry') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || !pageFileRelValid($name)) { pageUploadFail(400, 'Invalid file name'); }
    $known = false;
    foreach (pageFilesFor($pdo, $pageId) as $f) { if ((string)$f['filename'] === $name) { $known = true; break; } }
    if ($action === 'set_entry') {
        if (!$known) { pageUploadFail(404, 'File not found on this page'); }
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'htm'], true)) { pageUploadFail(400, 'The entry file must be an .html file'); }
        try {
            if ($name !== (string)$page['entry']) {
                $pdo->prepare("UPDATE pages SET entry = ? WHERE id = ? AND company_id = ?")->execute([$name, $pageId, (int)$page['company_id']]);
                logPageActivity($pdo, actorFromPost(), 'edited_entry', $pageId, 'Entry file edited on ' . pageDisplayLabel($page),
                    (string)$page['entry'] . ' → ' . $name, null, (int)$page['company_id']);
                $page['entry'] = $name;
            }
        } catch (Throwable $e) {
            error_log('page-upload set_entry: ' . $e->getMessage());
            pageUploadFail(500, 'Database error');
        }
        echo json_encode(['ok' => true, 'page' => pageUploadSummary($pdo, $page)]);
        exit;
    }
    // delete_file: unlink (contained) + forget the row; unknown row but file on disk is still removed
    $path = pageFilePath($company, $page, $name, true);
    if (!$known && $path === null) { pageUploadFail(404, 'File not found on this page'); }
    $deleted = 0;
    if ($path !== null && @unlink($path)) { $deleted = 1; }
    try {
        pageFileForget($pdo, $pageId, $name);
        logPageActivity($pdo, actorFromPost(), 'deleted_file', $pageId, 'Removed ' . $name . ' from ' . pageDisplayLabel($page), $name, null, (int)$page['company_id']);
    } catch (Throwable $e) {
        error_log('page-upload delete_file: ' . $e->getMessage());
        pageUploadFail(500, 'Database error');
    }
    echo json_encode(['ok' => true, 'name' => $name, 'deleted' => $deleted, 'page' => pageUploadSummary($pdo, $page)]);
    exit;
}

// ---- upload: the file (single request) ----
if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    pageUploadFail(400, 'No file uploaded');
}
$err = (int)$_FILES['file']['error'];
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    pageUploadFail(413, "File too large for the server (PHP limit: {$iniMax}). Ask hosting to raise upload_max_filesize and post_max_size.", ['ini_max' => $iniMax]);
}
if ($err !== UPLOAD_ERR_OK) { pageUploadFail(400, "Upload error code {$err}"); }

$origName = (string)$_FILES['file']['name'];
$tmpName  = (string)$_FILES['file']['tmp_name'];
$size     = (int)$_FILES['file']['size'];
[$name, $ext] = pageUploadCheckName($origName);
if ($size > $maxBytes) {
    pageUploadFail(413, 'Files must be under 10 MB.', ['limit_mb' => 10, 'ini_max' => $iniMax]);
}
$subfolder = pageUploadCheckSubfolder((string)($_POST['subfolder'] ?? ''));
pageUploadCheckContent($tmpName, $ext);
pageUploadStore($pdo, $company, $page, $tmpName, $name, $ext, $subfolder, $size, $batchId, true);
