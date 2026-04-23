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

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$uploadsDir = __DIR__ . '/uploads';
$uploadsUrl = 'uploads';
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxFileSize = 10 * 1024 * 1024; // 10 MB

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
if ($err !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Upload error code {$err}"]);
    exit;
}

if ($_FILES['image']['size'] > $maxFileSize) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Image exceeds 10 MB']);
    exit;
}

$origName = $_FILES['image']['name'];
$tmpName  = $_FILES['image']['tmp_name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported file type']);
    exit;
}

// Real image check (not a renamed file)
$finfo = @getimagesize($tmpName);
if ($finfo === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Not a valid image']);
    exit;
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

if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

$newName = uniqid('img_', true) . '.' . $ext;
$newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $newName);
$dest    = $uploadsDir . '/' . $newName;

if (!move_uploaded_file($tmpName, $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save file (check uploads/ permissions)']);
    exit;
}

$newUrl = $uploadsUrl . '/' . $newName;

try {
    $upd = $pdo->prepare("UPDATE {$table} SET image_url = ? WHERE id = ?");
    $upd->execute([$newUrl, $imageId]);

    // Delete the old file from disk if it's inside uploads/
    $oldUrl = $row['image_url'];
    if (strpos($oldUrl, 'uploads/') === 0) {
        $oldPath = __DIR__ . '/' . $oldUrl;
        if (is_file($oldPath) && realpath(dirname($oldPath)) === realpath($uploadsDir)) {
            @unlink($oldPath);
        }
    }

    // Bump updated_at on the parent post (only for post images)
    if ($type === 'post') {
        $pdo->prepare("UPDATE posts p INNER JOIN post_images pi ON pi.post_id = p.id SET p.updated_at = NOW() WHERE pi.id = ?")
            ->execute([$imageId]);
    }

    echo json_encode([
        'ok'        => true,
        'image_id'  => $imageId,
        'image_url' => $newUrl,
    ]);
} catch (Exception $e) {
    // Rollback: delete the newly uploaded file
    if (is_file($dest)) { @unlink($dest); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
