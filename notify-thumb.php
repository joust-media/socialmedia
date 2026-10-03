<?php
/**
 * Signed, expiring thumbnail for notifications (Slack Block Kit image accessory, emails) — no session, no DB:
 *
 *   GET notify-thumb?t=<token>     token = base64url("<media ref>|<expires>") . '.' . HMAC (notifyThumbToken(),
 *                                  notify-lib.php; key derived from previewSecret()) — only refs the portal signed,
 *                                  only until they expire (30 days; Slack copies the image when the message is posted).
 *
 * The ref is resolved through the same containment helpers as preview.php (uploads/, media/tires/, media/library/,
 * media/pages/). Answers a ≤ 600 px JPEG (Slack does not render WebP), cached next to the original as
 * <dir>/.thumbs/<stem>.notify.jpg and remade when the original changes.
 *   200 image/jpeg · 400 bad token · 403 forged · 410 expired · 404 missing / not an image · 405 method · 503 no GD
 */
require_once __DIR__ . '/media-lib.php';
require_once __DIR__ . '/tire-series-lib.php';
if (is_file(__DIR__ . '/pages-lib.php')) require_once __DIR__ . '/pages-lib.php';
require_once __DIR__ . '/preview-lib.php';
require_once __DIR__ . '/notify-lib.php';

function notifyThumbFail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $msg;
    exit;
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') notifyThumbFail(405, 'Method not allowed');
$why = '';
$ref = notifyThumbTokenRef((string)($_GET['t'] ?? ''), $why);
if ($ref === null) notifyThumbFail($why === 'forged' ? 403 : ($why === 'expired' ? 410 : 400), $why === 'expired' ? 'Expired' : 'Bad token');
$abs = previewRefPath($ref);
if ($abs === null || !is_file($abs) || !previewIsImage($abs)) notifyThumbFail(404, 'Not found');

$dest = previewThumbsDir($abs) . '/' . pathinfo($abs, PATHINFO_FILENAME) . '.notify.jpg';
if (!is_file($dest) || previewMtime($dest) < previewMtime($abs)) {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) notifyThumbFail(503, 'No image support');
    $info = @getimagesize($abs);
    if (!is_array($info) || !previewMemoryOk((int)$info[0], (int)$info[1])) notifyThumbFail(404, 'Not decodable');
    $im = @imagecreatefromstring((string)@file_get_contents($abs));
    if (!$im) notifyThumbFail(404, 'Not decodable');
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    $im = previewOrientGd($im, previewExifOrientation($abs));
    $t = previewScaleDims(imagesx($im), imagesy($im), 600) ?? [imagesx($im), imagesy($im)];
    $out = imagecreatetruecolor($t[0], $t[1]);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // alpha flattened onto white
    imagecopyresampled($out, $im, 0, 0, 0, 0, $t[0], $t[1], imagesx($im), imagesy($im));
    imagedestroy($im);
    $dir = dirname($dest);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (function_exists('previewEnsureThumbsHtaccess')) previewEnsureThumbsHtaccess($dir);
    $tmp = $dest . '.' . getmypid() . '.tmp';
    if (is_dir($dir) && @imagejpeg($out, $tmp, 82) && @rename($tmp, $dest)) {
        if (function_exists('mediaChmodPath')) mediaChmodPath($dest);
        imagedestroy($out);
    } else {
        @unlink($tmp);
        // cannot cache (read-only folder): answer the bytes directly
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        imagejpeg($out, null, 82);
        imagedestroy($out);
        exit;
    }
}
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($dest));
if ($method === 'GET') readfile($dest);
