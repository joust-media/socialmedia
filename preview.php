<?php
/**
 * Lazy image-preview endpoint (preview-lib.php). Render sites point <img> at
 *
 *   GET preview.php?f=<token>&s=sm|lg[&v=<mtime of the original>]
 *
 * when the derivative does not exist yet. token = base64url(ref) . '.' . HMAC — only paths the portal itself
 * printed can be requested, and the ref is resolved again through the containment helpers (uploads/,
 * media/tires/, media/library/, media/pages/). The derivative is generated once (per-original lock) and then
 * served with a year of immutable caching (the URL carries v=); the next page render links the static file.
 *
 *   400 bad size / malformed token · 403 signature mismatch · 404 unknown or missing file · 405 method
 *   200 image (ETag / Last-Modified, 304 on revalidation) · 302 to the original when no derivative can be made
 *   (not an image, SVG, too big for memory, GD missing, decode error, original already small)
 *
 * No session, no DB, no helpers.php: media-lib / tire-series-lib / pages-lib / preview-lib are function
 * definitions only. Env PREVIEW_QA_FAIL=1 forces the fallback branch (harness).
 */
require_once __DIR__ . '/media-lib.php';
require_once __DIR__ . '/tire-series-lib.php';
if (is_file(__DIR__ . '/pages-lib.php')) require_once __DIR__ . '/pages-lib.php';
require_once __DIR__ . '/preview-lib.php';

function previewEndpointFail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $msg;
    exit;
}

/** Public URL of the original for the 302: basePath-relative for uploads/, root-relative for media/. */
function previewEndpointOriginalUrl(string $ref): string {
    $segs = array_map('rawurlencode', explode('/', $ref));
    if ($segs[0] === 'uploads') {
        $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/preview.php'))), '/');
        if ($base === '.') $base = '';
        return $base . '/' . implode('/', $segs);
    }
    return '/' . implode('/', $segs);
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') previewEndpointFail(405, 'Method not allowed');
$size = (string)($_GET['s'] ?? 'sm');
if (!isset(previewSizes()[$size])) previewEndpointFail(400, 'Unknown size');
$token = (string)($_GET['f'] ?? '');
$why = '';
$ref = previewTokenRef($token, $why);
if ($ref === null) previewEndpointFail($why === 'forged' ? 403 : 400, $why === 'forged' ? 'Bad signature' : 'Bad token');
$abs = previewRefPath($ref);
if ($abs === null || !is_file($abs)) previewEndpointFail(404, 'Not found');

$file = previewEnsure($abs, $size);
if ($file === null || $file === $abs) {
    // No derivative (or none needed): the original — never a broken image.
    header('Cache-Control: public, max-age=300');
    header('Location: ' . previewEndpointOriginalUrl($ref), true, 302);
    exit;
}
clearstatcache(true, $file);
$bytes = (int)@filesize($file);
$mtime = previewMtime($file);
if ($bytes <= 0) previewEndpointFail(404, 'Not found');
$etag = '"' . substr(sha1($ref . '|' . $size . '|' . $mtime . '|' . $bytes), 0, 20) . '"';
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Content-Type-Options: nosniff');
$inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$ims = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
if (($inm !== '' && $inm === $etag) || ($inm === '' && $ims !== '' && strtotime($ims) >= $mtime)) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . previewMime($file));
header('Content-Length: ' . $bytes);
if ($method === 'HEAD') exit;
while (ob_get_level() > 0) { @ob_end_clean(); }
readfile($file);
exit;
