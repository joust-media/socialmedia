<?php
/**
 * Replace an image / video on an existing post or tire — the single-request path (small files).
 * Large files (above the host's upload_max_filesize) go through upload-chunk.php with
 * purpose=replace, which ends in the same uploadReplaceApply() (upload-lib.php).
 *
 * Accepts POST:
 *   - image_id (int, required) — post_images.id or tire_images.id to replace
 *   - image (file, required)   — the new image or video (images ≤ 50 MB, videos ≤ 4 GB; one request is
 *                                bounded by php.ini, so anything bigger is sent in pieces by the client)
 *   - type (string, optional)  — 'post' (default) or 'tire'
 * Deletes the old file, saves the new one, updates the DB (a series render is replaced in place).
 * Returns JSON { ok, image_id, image_url, src, media_type }.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/upload-lib.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }   // helpers.php already loads it; belt and braces

header('Content-Type: application/json');

function riFail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { riFail(405, 'Method not allowed'); }
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)

// Replacing a file is an admin verb (spec §2) — never available to a client seat.
if (!currentAdmin()) { riFail(403, 'Admin sign-in required'); }

// Determine target table
$type    = ($_POST['type'] ?? 'post') === 'tire' ? 'tire' : 'post';
$imageId = (int)($_POST['image_id'] ?? 0);
if ($imageId <= 0) { riFail(400, 'Invalid image_id'); }

if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { riFail(400, 'No image uploaded'); }

$err = (int)$_FILES['image']['error'];
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    $iniMax = ini_get('upload_max_filesize') ?: '?';
    riFail(413, "File too large for server (PHP limit: {$iniMax})");
}
if ($err !== UPLOAD_ERR_OK) { riFail(400, "Upload error code {$err}"); }

$origName = (string)$_FILES['image']['name'];
$tmpName  = (string)$_FILES['image']['tmp_name'];
$check    = uploadCheckName($origName);   // jpg/png/gif/webp + mp4/webm/mov (spec §6); m4v/avi/mkv → "convert to MP4"
if (isset($check['error'])) { riFail(400, $check['error']); }
$ext     = $check['ext'];
$isVideo = (bool)$check['video'];

$limit = uploadMaxBytes($isVideo ? 'video' : 'image');
if ((int)$_FILES['image']['size'] > $limit) { riFail(400, 'File exceeds ' . uploadCapLabel($limit)); }

$why = uploadCheckContent($tmpName, $ext, $isVideo);   // real image / video check (not a renamed file)
if ($why !== '') { riFail(400, $why); }

// The row must exist before anything is written.
if (!uploadReplaceRow($pdo, $type, $imageId)) { riFail(404, 'Image not found'); }

$r = uploadReplaceApply($pdo, $type, $imageId, $tmpName, $ext, $isVideo, true);
if ((int)$r['code'] !== 200) { http_response_code((int)$r['code']); }
echo json_encode($r['body']);
