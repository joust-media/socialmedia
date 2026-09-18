<?php
/**
 * Replace an image on an existing post or tire.
 * Accepts POST:
 *   - image_id (int, required) — post_images.id or tire_images.id to replace
 *   - image (file, required)   — the new image
 *   - type (string, optional)  — 'post' (default) or 'tire'
 * Deletes the old file, saves the new one, updates the DB.
 * Returns JSON { ok, image_url }.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }   // helpers.php already loads it; belt and braces

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)

// Replacing a file is an admin verb (spec §2) — never available to a client seat.
if (!currentAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin sign-in required']);
    exit;
}

$uploadsDir  = __DIR__ . '/uploads';
$uploadsUrl  = 'uploads';
$allowedExt  = array_merge(imageExts(), videoExts()); // jpg/png/gif/webp + mp4/webm/mov (spec §6)
$rejectedExt = ['m4v', 'avi', 'mkv'];
$maxFileSize = 25 * 1024 * 1024; // 25 MB — matches add-post.php

// Determine target table
$type = ($_POST['type'] ?? 'post') === 'tire' ? 'tire' : 'post';
$table = $type === 'tire' ? 'tire_images' : 'post_images';

$imageId = (int)($_POST['image_id'] ?? 0);
if ($imageId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid image_id']);
    exit;
}

if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No image uploaded']);
    exit;
}

$err = $_FILES['image']['error'];
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    $iniMax = ini_get('upload_max_filesize') ?: '?';
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "File too large for server (PHP limit: {$iniMax})"]);
    exit;
}
if ($err !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Upload error code {$err}"]);
    exit;
}

if ($_FILES['image']['size'] > $maxFileSize) {
    $mb = number_format($maxFileSize / (1024 * 1024), 0);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "File exceeds {$mb} MB"]);
    exit;
}

$origName = $_FILES['image']['name'];
$tmpName  = $_FILES['image']['tmp_name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (in_array($ext, $rejectedExt, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' =>
        ".{$ext} isn't web-playable. Convert to MP4 first (QuickTime: File → Export As → 1080p)."
    ]);
    exit;
}
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported file type — use JPG, PNG, GIF, WebP, MP4, WebM, or MOV.']);
    exit;
}

$isVideo = isVideoExt($ext);
if ($isVideo) {
    if (!videoFileLooksValid((string)$tmpName, $ext)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Not a valid video file']);
        exit;
    }
} else {
    // Real image check (not a renamed file)
    $finfo = @getimagesize($tmpName);
    if ($finfo === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Not a valid image']);
        exit;
    }
}

// Fetch the existing row so we can delete the old file
$sel = $pdo->prepare("SELECT id, image_url FROM {$table} WHERE id = ?");
$sel->execute([$imageId]);
$row = $sel->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Image not found']);
    exit;
}

// A series render (media/tires/<tire>/<series>/<file>, tire-series-lib.php) is replaced IN PLACE —
// same folder, same stem, the new extension — so a later folder scan doesn't re-import the old name.
$inPlace  = false;
$oldPath  = null;
$oldThumb = null;
if ($type === 'tire') {
    $oldPath  = tireImagePath($row);
    $oldThumb = tireThumbPath($row);
    if ($oldPath !== null && strpos(ltrim((string)$row['image_url'], '/'), 'media/tires/') === 0) {
        $inPlace = true;
        $dir     = dirname($oldPath);
        $stem    = pathinfo($oldPath, PATHINFO_FILENAME);
        $newName = $stem . '.' . $ext;
        if (strcasecmp($newName, basename($oldPath)) !== 0) {
            for ($n = 2; file_exists($dir . '/' . $newName) && $n < 1000; $n++) { $newName = $stem . '-' . $n . '.' . $ext; }
        }
        $dest   = $dir . '/' . $newName;
        $newUrl = rtrim(dirname(ltrim((string)$row['image_url'], '/')), '/') . '/' . $newName;
    }
}
if (!$inPlace) {
    if (!is_dir($uploadsDir)) { function_exists('mediaMkdir') ? mediaMkdir($uploadsDir) : @mkdir($uploadsDir, 0755, true); }
    $prefix  = $isVideo ? 'vid_' : 'img_';
    $newName = uniqid($prefix, true) . '.' . $ext;
    $newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $newName);
    $dest    = $uploadsDir . '/' . $newName;
    $newUrl  = $uploadsUrl . '/' . $newName;
}

if (!move_uploaded_file($tmpName, $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save file (check ' . ($inPlace ? 'media/tires/' : 'uploads/') . ' permissions)']);
    exit;
}
function_exists('mediaChmodPath') ? mediaChmodPath($dest) : @chmod($dest, 0644);   // 0644: the upload tmp was 0600 (unreadable by Apache as another user)

try {
    // post_images has a media_type column once migrate.php has run; tire_images doesn't.
    // Falls back to just image_url if the column isn't there yet (pre-migration deploys).
    if ($table === 'post_images' && hasMediaTypeColumn($pdo)) {
        $upd = $pdo->prepare("UPDATE post_images SET image_url = ?, media_type = ? WHERE id = ?");
        $upd->execute([$newUrl, $isVideo ? 'video' : 'image', $imageId]);
    } else {
        $upd = $pdo->prepare("UPDATE {$table} SET image_url = ? WHERE id = ?");
        $upd->execute([$newUrl, $imageId]);
    }

    // Delete the old file from disk if it's inside uploads/ (or, for an in-place series replace,
    // the old file when the extension changed) and drop the stale thumb.
    $oldUrl = $row['image_url'];
    if ($inPlace) {
        if ($oldPath !== null && realpath($oldPath) !== realpath($dest) && is_file($oldPath)) { @unlink($oldPath); }
    } elseif (strpos($oldUrl, 'uploads/') === 0) {
        $oldPath = __DIR__ . '/' . $oldUrl;
        if (is_file($oldPath) && realpath(dirname($oldPath)) === realpath($uploadsDir)) {
            @unlink($oldPath);
        }
    }
    if ($type === 'tire') {
        if ($oldThumb !== null && is_file($oldThumb)) { @unlink($oldThumb); }
        $newThumb = tireThumbPath(['image_url' => $newUrl]);
        if ($newThumb !== null && is_file($newThumb)) { @unlink($newThumb); }
        if (!$isVideo) { ensureTireThumb(['image_url' => $newUrl]); }
    }

    // Bump updated_at on the parent post (only for post images)
    if ($type === 'post') {
        $pdo->prepare("UPDATE posts p INNER JOIN post_images pi ON pi.post_id = p.id SET p.updated_at = NOW() WHERE pi.id = ?")
            ->execute([$imageId]);
    }

    echo json_encode([
        'ok'         => true,
        'image_id'   => $imageId,
        'image_url'  => $newUrl,
        'src'        => $type === 'tire' ? tireImageSrc($newUrl) : (basePath() . '/' . ltrim($newUrl, '/')),   // ready-to-use URL (media/tires rows are root-relative)
        'media_type' => $isVideo ? 'video' : 'image',
    ]);
} catch (Exception $e) {
    // Rollback: delete the newly uploaded file
    if (is_file($dest)) { @unlink($dest); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
