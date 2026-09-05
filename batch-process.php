<?php
/**
 * Batch create endpoint (Studio → Batch). Admin only — JSON 401 otherwise.
 *
 * POST batch-process.php?client=<slug>   (multipart or urlencoded)
 *
 *   images[]        files — one pending post per file (existing contract).
 *                   Images must pass getimagesize(); MP4/WebM now accepted too
 *                   (same rules as add-post.php); .mov/.m4v/.avi/.mkv rejected
 *                   with the "convert to MP4" message.
 *   rows            JSON array — one post per row, media from the Approved Pool:
 *                   [{ "caption": "…", "hashtags": "…", "scheduled_date": "2026-09-12T10:00",
 *                      "post_type": "post|story|reel", "assets": ["library:12", "tire:34"] }, …]
 *                   Each asset is validated (this company + status='approved') and
 *                   COPIED into uploads/ as a post_images row in the given order.
 *   spacing_days    1–30 (default 3) — used for rows/files without a date.
 *
 * Response (unchanged shape):
 *   {"ok":true,"created":[{"filename","post_id","date","categories":[…]}],"errors":[…],"count":N}
 *
 * Client scoping comes from helpers.php ($client from ?client=) — the previous
 * resolveClient() call did not exist and fataled on every request.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/partials/components/asset-pool.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

// Admin-only API endpoint — JSON 401 instead of an HTML login redirect.
if (!currentAdmin()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in']);
    exit;
}

// Client from ?client= slug (helpers.php)
if (!$client) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing client slug']);
    exit;
}

$companyId = (int)$client['id'];
$rowsRaw   = trim((string)($_POST['rows'] ?? ''));
$rows      = [];
if ($rowsRaw !== '') {
    $rows = json_decode($rowsRaw, true);
    if (!is_array($rows)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'rows must be a JSON array']);
        exit;
    }
}
$hasFiles = !empty($_FILES['images']) && is_array($_FILES['images']['name']);

if (!$hasFiles && !$rows) {
    echo json_encode(['ok' => false, 'error' => 'No images uploaded']);
    exit;
}

$fileCount = $hasFiles ? count($_FILES['images']['name']) : 0;
if ($fileCount > 20) {
    echo json_encode(['ok' => false, 'error' => 'Maximum 20 images per batch']);
    exit;
}
if (count($rows) > 20) {
    echo json_encode(['ok' => false, 'error' => 'Maximum 20 posts per batch']);
    exit;
}

$spacingDays = max(1, min(30, (int)($_POST['spacing_days'] ?? 3)));
$maxFileSize = 10 * 1024 * 1024;
$allowedExt  = array_merge(imageExts(), videoExts());
$rejectedExt = ['mov', 'm4v', 'avi', 'mkv'];
$hasMedia    = hasMediaTypeColumn($pdo);
$hasType     = hasPostTypeColumn($pdo);
$defaultTags = trim((string)($client['default_hashtags'] ?? ''));

// Load all categories for filename matching
$catStmt = $pdo->query('SELECT id, name FROM categories ORDER BY sort_order');
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Build a map of lowercase keywords to category IDs
$catMap = [];
foreach ($categories as $cat) {
    $catMap[strtolower($cat['name'])] = (int)$cat['id'];
}
// Check longest category names first to avoid partial matches ("sport atv" before "atv")
$sortedCats = $catMap;
uksort($sortedCats, function ($a, $b) { return strlen($b) - strlen($a); });
$aliases = [
    'atv'        => 'atv',
    'utv'        => 'utv',
    'offroad'    => 'off-road',
    'off road'   => 'off-road',
    'dualsport'  => 'dual sport',
    'dual sport' => 'dual sport',
    'street'     => 'street',
    'scooter'    => 'scooter',
    'sport atv'  => 'sport atv',
];

function batchMatchCategories(string $name, array $sortedCats, array $catMap, array $aliases): array {
    $key = strtolower(pathinfo($name, PATHINFO_FILENAME));
    $key = str_replace(['-', '_', '.'], ' ', $key);
    $matched = [];
    foreach ($sortedCats as $catName => $catId) {
        if (strpos($key, $catName) !== false) { $matched[] = $catId; }
    }
    foreach ($aliases as $alias => $catName) {
        if (strpos($key, $alias) !== false && isset($catMap[$catName]) && !in_array($catMap[$catName], $matched, true)) {
            $matched[] = $catMap[$catName];
        }
    }
    return $matched;
}

// Find the latest scheduled_date for this client — spacing starts there
$dateStmt = $pdo->prepare('SELECT MAX(scheduled_date) AS latest FROM posts WHERE company_id = ?');
$dateStmt->execute([$companyId]);
$latest   = $dateStmt->fetchColumn();
$baseDate = $latest ? new DateTime($latest) : new DateTime();

$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }

$created = [];
$errors  = [];

/** Insert a pending post and return its id. */
function batchInsertPost(PDO $pdo, int $companyId, string $caption, string $hashtags, string $date, string $type, bool $hasType): int {
    if ($hasType) {
        $st = $pdo->prepare('INSERT INTO posts (company_id, caption, hashtags, scheduled_date, status, post_type) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$companyId, $caption, $hashtags, $date, 'pending', $type]);
    } else {
        $st = $pdo->prepare('INSERT INTO posts (company_id, caption, hashtags, scheduled_date, status) VALUES (?, ?, ?, ?, ?)');
        $st->execute([$companyId, $caption, $hashtags, $date, 'pending']);
    }
    return (int)$pdo->lastInsertId();
}

// ---------------------------------------------------------------------
// 1. Rows built from the Approved Pool
// ---------------------------------------------------------------------
foreach ($rows as $i => $row) {
    if (!is_array($row)) { $errors[] = 'Row ' . ($i + 1) . ': invalid'; continue; }
    $label    = 'Row ' . ($i + 1);
    $caption  = trim((string)($row['caption'] ?? ''));
    if ($caption === '') $caption = 'Please insert caption here';
    if (mb_strlen($caption) > 10000) $caption = mb_substr($caption, 0, 10000);
    $hashtags = array_key_exists('hashtags', $row) ? trim((string)$row['hashtags']) : $defaultTags;
    if (mb_strlen($hashtags) > 2000) $hashtags = mb_substr($hashtags, 0, 2000);
    $type     = strtolower(trim((string)($row['post_type'] ?? 'post')));
    if (!in_array($type, allowedPostTypes(), true)) $type = 'post';
    $picks    = studioParsePicks($row['assets'] ?? [], 10);
    if (!$picks) { $errors[] = "$label: pick at least one approved asset"; continue; }

    $dateIn = trim((string)($row['scheduled_date'] ?? ''));
    if ($dateIn !== '') {
        $ts = strtotime($dateIn);
        if (!$ts) { $errors[] = "$label: invalid date"; continue; }
        $scheduledDate = date('Y-m-d H:i:s', $ts);
    } else {
        $baseDate->modify('+' . $spacingDays . ' days');
        $scheduledDate = $baseDate->format('Y-m-d H:i:s');
    }

    try {
        $pdo->beginTransaction();
        $postId = batchInsertPost($pdo, $companyId, $caption, $hashtags, $scheduledDate, $type, $hasType);
        $attached = studioAttachAssetsToPost($pdo, $client, $postId, $picks, ['uploadsDir' => $uploadDir]);
        logActivity($pdo, $companyId, 'post', $postId, 'created', 'admin',
            "Created post #{$postId} via batch (" . count($attached) . ' from the Approved Pool)');
        $pdo->commit();
        $created[] = [
            'filename'   => $attached ? (string)$attached[0]['asset']['label'] : $label,
            'post_id'    => $postId,
            'date'       => $scheduledDate,
            'categories' => [],
            'assets'     => count($attached),
            'caption'    => $caption,
        ];
    } catch (StudioAssetException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = "$label: " . $e->getMessage();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = "$label: database error — " . $e->getMessage();
    }
}

// ---------------------------------------------------------------------
// 2. Direct uploads — one post per file (existing contract)
// ---------------------------------------------------------------------
for ($i = 0; $i < $fileCount; $i++) {
    $name    = (string)$_FILES['images']['name'][$i];
    $tmpName = $_FILES['images']['tmp_name'][$i];
    $error   = $_FILES['images']['error'][$i];
    $size    = (int)$_FILES['images']['size'][$i];

    if ($error === UPLOAD_ERR_NO_FILE) continue;
    if ($error !== UPLOAD_ERR_OK) {
        $errors[] = "$name: upload error code $error";
        continue;
    }
    if ($size > $maxFileSize) {
        $errors[] = "$name: exceeds 10 MB limit";
        continue;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, $rejectedExt, true)) {
        $errors[] = "$name: .$ext isn't web-playable — convert to MP4 first (QuickTime: File → Export As → 1080p). Chrome and Firefox can't play .$ext.";
        continue;
    }
    if (!in_array($ext, $allowedExt, true)) {
        $errors[] = "$name: unsupported file type — use JPG, PNG, GIF, WebP, MP4, or WebM";
        continue;
    }
    $isVideo = isVideoExt($ext);
    if ($isVideo) {
        if (!is_file($tmpName) || filesize($tmpName) === 0) { $errors[] = "$name: appears to be empty"; continue; }
    } else {
        if (!@getimagesize($tmpName)) { $errors[] = "$name: not a valid image"; continue; }
    }

    $safeName = uniqid($isVideo ? 'batch_vid_' : 'batch_', true) . '.' . $ext;
    $safeName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $safeName);
    $destPath = $uploadDir . '/' . $safeName;
    $imageUrl = 'uploads/' . $safeName;

    if (!move_uploaded_file($tmpName, $destPath)) {
        $errors[] = "$name: failed to save";
        continue;
    }

    $baseDate->modify('+' . $spacingDays . ' days');
    $scheduledDate = $baseDate->format('Y-m-d H:i:s');
    $matchedCatIds = batchMatchCategories($name, $sortedCats, $catMap, $aliases);

    try {
        $pdo->beginTransaction();
        $postId = batchInsertPost($pdo, $companyId, 'Please insert caption here', $defaultTags, $scheduledDate, 'post', $hasType);
        if ($hasMedia) {
            $imgStmt = $pdo->prepare('INSERT INTO post_images (post_id, image_url, media_type, sort_order) VALUES (?, ?, ?, 1)');
            $imgStmt->execute([$postId, $imageUrl, $isVideo ? 'video' : 'image']);
        } else {
            $imgStmt = $pdo->prepare('INSERT INTO post_images (post_id, image_url, sort_order) VALUES (?, ?, 1)');
            $imgStmt->execute([$postId, $imageUrl]);
        }
        if ($matchedCatIds) {
            $catInsert = $pdo->prepare('INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)');
            foreach ($matchedCatIds as $catId) { $catInsert->execute([$postId, $catId]); }
        }
        logActivity($pdo, $companyId, 'post', $postId, 'created', 'admin', "Created post #{$postId} via batch upload");
        $pdo->commit();
        $created[] = [
            'filename'   => $name,
            'post_id'    => $postId,
            'date'       => $scheduledDate,
            'categories' => $matchedCatIds,
            'image_url'  => $imageUrl,
            'media_type' => $isVideo ? 'video' : 'image',
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_file($destPath)) @unlink($destPath);
        $errors[] = "$name: database error — " . $e->getMessage();
    }
}

echo json_encode([
    'ok'      => true,
    'created' => $created,
    'errors'  => $errors,
    'count'   => count($created),
]);
