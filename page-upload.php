<?php
/**
 * Page files endpoint (admin only; scratchpad pages-design.md). One file per request.
 *
 * POST multipart / urlencoded:
 *   client        company slug — must be the page's company (403 otherwise; helpers.php scopes it too)
 *   page_id       pages.id (source must be 'upload')
 *   action        upload (default) | delete_file | set_entry
 *   file          the upload (action=upload): html htm css js json png jpg jpeg gif webp svg ico
 *                 woff woff2 ttf mp4 webm, ≤ 10 MB; images must decode and match their extension
 *   subfolder     optional relative folder inside the page folder ('img', 'assets/fonts'):
 *                 [a-z0-9_-] segments, ≤ 4 deep, no '..' (400 otherwise)
 *   name          the stored relative name for delete_file / set_entry ('index.html', 'img/hero.png')
 *   batch         optional batch token shared by one drop (one activity line for the whole drop)
 *
 * Stores media/pages/<client-slug>/<page-slug>/[subfolder/]<safe-name> (an existing file with
 * the same name is REPLACED — that is how a page gets updated), upserts page_files, logs
 * page/uploaded. File names are sanitised (pageSanitizeFilename): basename only, no dotfiles,
 * no .php/.phtml/.htaccess anywhere in the dotted chain (415), unknown extensions 415,
 * dotfiles / traversal 400. media/pages/.htaccess (no PHP, no listing) is (re)written on every upload.
 *
 * Replies JSON {ok, file:{name, size, url, entry}, page:{id, entry, file_count}, batch}
 *   delete_file → {ok, name, deleted, page:{…}}   set_entry → {ok, page:{…}}
 * Errors: 400 bad request · 403 seat / tenant / cross-site · 404 unknown page or file ·
 *         409 not an upload page / not migrated · 413 too large · 415 unsupported type · 422 not a valid image · 500.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }

header('Content-Type: application/json');

function pageUploadFail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    exit;
}
function pageUploadSummary(PDO $pdo, array $page): array {
    return ['id' => (int)$page['id'], 'entry' => (string)$page['entry'], 'file_count' => count(pageFilesFor($pdo, (int)$page['id']))];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { pageUploadFail(405, 'Method not allowed'); }
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)
if (!currentAdmin()) { pageUploadFail(403, 'Admin sign-in required'); }
if (!hasPagesTable($pdo)) { pageUploadFail(409, 'Pages are not set up yet — run migrate.php.'); }

$maxBytes = 10 * 1024 * 1024;
$iniMax   = (string)(ini_get('upload_max_filesize') ?: '?');
$action   = (string)($_POST['action'] ?? 'upload');
if (!in_array($action, ['upload', 'delete_file', 'set_entry'], true)) { pageUploadFail(400, 'Unknown action'); }

// ---- the page + tenant scope ----
$pageId = (int)($_POST['page_id'] ?? 0);
if ($pageId <= 0) { pageUploadFail(400, 'Invalid page_id'); }
$page = pageById($pdo, $pageId);
if (!$page) { pageUploadFail(404, 'Page not found'); }
$slug = postedClientSlug();
if ($slug === '') { pageUploadFail(400, 'Pick a client first'); }
if ((string)$page['company_slug'] !== $slug) { pageUploadFail(403, 'This page belongs to another client'); }
if (strtolower((string)$page['source']) !== 'upload') { pageUploadFail(409, 'This page links to an external URL — switch its source to Upload first.'); }
$company = ['id' => (int)$page['company_id'], 'slug' => (string)$page['company_slug']];

// Batch id: one per drop so the activity feed shows one line (same rule as tire-upload.php).
$batchId = (string)($_POST['batch'] ?? '');
if (preg_match('/^[0-9a-f]{16}$/', $batchId)) { /* as is */ }
elseif (preg_match('/^[A-Za-z0-9_\-]{4,40}$/', $batchId)) { $batchId = substr(sha1('page-upload:' . $batchId), 0, 16); }
else { $batchId = newBatchId(); }

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

// ---- upload: the file ----
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
if ($size > $maxBytes) {
    pageUploadFail(413, 'Files must be under 10 MB.', ['limit_mb' => 10, 'ini_max' => $iniMax]);
}
$subfolder = trim((string)($_POST['subfolder'] ?? ''), " \t\n\r/");
if (!pageSubfolderValid($subfolder)) {
    pageUploadFail(400, 'Subfolder may only use lowercase letters, digits, - and _ (e.g. img or assets/fonts), no ".."');
}
// Content check — images must decode AND match the extension's format (a PHP/HTML file renamed .png fails here).
if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
    $info = @getimagesize($tmpName);
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
    $head = (string)@file_get_contents($tmpName, false, null, 0, 4096);
    if (!preg_match('/<svg[\s>]/i', $head) || preg_match('/<\?php/i', $head)) { pageUploadFail(422, 'Not a valid SVG image'); }
} elseif (in_array($ext, ['mp4', 'webm'], true)) {
    if (function_exists('videoFileLooksValid') && !videoFileLooksValid($tmpName, $ext)) { pageUploadFail(422, 'Not a valid video file'); }
} else {
    // Text-ish files (html/css/js/json) and fonts: refuse anything that carries a PHP open tag.
    $head = (string)@file_get_contents($tmpName, false, null, 0, 65536);
    if (in_array($ext, ['html', 'htm', 'css', 'js', 'json'], true) && preg_match('/<\?php|<\?=/i', $head)) {
        pageUploadFail(415, 'PHP code is not allowed in page files.');
    }
}

// ---- destination: media/pages/<client>/<slug>/[subfolder]/ ----
$dir = pageFolderContained($company, $page);
if ($dir === null) { pageUploadFail(500, 'Page folder resolves outside media/pages/'); }
$destDir = $subfolder !== '' ? $dir . '/' . $subfolder : $dir;
if (!is_dir($destDir)) { @mkdir($destDir, 0755, true); }
if (!is_dir($destDir) || !is_writable($destDir)) {
    pageUploadFail(500, 'media/pages/ is not writable on the server — create it next to the portal folder and give it write permission.');
}
ensurePagesMediaHtaccess();   // media/pages/.htaccess (+ media/.htaccess when absent): no PHP/CGI, no listing
$rel  = $subfolder !== '' ? $subfolder . '/' . $name : $name;
if (!pageFileRelValid($rel)) { pageUploadFail(400, 'That file name cannot be used'); }
$dest = $destDir . '/' . $name;
if (is_link($dest) || (file_exists($dest) && !is_file($dest))) { pageUploadFail(500, 'Destination is not a regular file'); }
$replaced = is_file($dest) ? 1 : 0;
if (!move_uploaded_file($tmpName, $dest)) { pageUploadFail(500, 'Failed to save the file (check folder permissions)'); }
@chmod($dest, 0644);

// ---- the row ----
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
]);
