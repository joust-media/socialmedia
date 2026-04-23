<?php
require_once 'db.php';
require_once 'helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

// Resolve client from ?client= slug
$client = resolveClient($pdo);
if (!$client) {
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing client slug']);
    exit;
}

$companyId = $client['id'];

// Validate files
if (empty($_FILES['images']) || !is_array($_FILES['images']['name'])) {
    echo json_encode(['ok' => false, 'error' => 'No images uploaded']);
    exit;
}

$fileCount = count($_FILES['images']['name']);
if ($fileCount > 20) {
    echo json_encode(['ok' => false, 'error' => 'Maximum 20 images per batch']);
    exit;
}

// Load all categories for filename matching
$catStmt = $pdo->query('SELECT id, name FROM categories ORDER BY sort_order');
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Build a map of lowercase keywords to category IDs
// e.g. "sport atv" => 1, "atv" => 2, "utv" => 3, etc.
$catMap = [];
foreach ($categories as $cat) {
    $catMap[strtolower($cat['name'])] = $cat['id'];
}

// Find the latest scheduled_date for this client
$dateStmt = $pdo->prepare('SELECT MAX(scheduled_date) as latest FROM posts WHERE company_id = ?');
$dateStmt->execute([$companyId]);
$latest = $dateStmt->fetchColumn();

if ($latest) {
    $baseDate = new DateTime($latest);
} else {
    // No existing posts — start from today
    $baseDate = new DateTime();
}

$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$created = [];
$errors = [];

for ($i = 0; $i < $fileCount; $i++) {
    $name     = $_FILES['images']['name'][$i];
    $tmpName  = $_FILES['images']['tmp_name'][$i];
    $error    = $_FILES['images']['error'][$i];
    $size     = $_FILES['images']['size'][$i];

    // Skip empty slots
    if ($error === UPLOAD_ERR_NO_FILE) continue;

    if ($error !== UPLOAD_ERR_OK) {
        $errors[] = "$name: upload error code $error";
        continue;
    }

    // Validate image via getimagesize (same pattern as admin.php)
    $imgInfo = getimagesize($tmpName);
    if (!$imgInfo) {
        $errors[] = "$name: not a valid image";
        continue;
    }

    // 10 MB cap
    if ($size > 10 * 1024 * 1024) {
        $errors[] = "$name: exceeds 10 MB limit";
        continue;
    }

    // Generate unique filename
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $safeName = uniqid('batch_') . '.' . $ext;
    $destPath = $uploadDir . $safeName;

    if (!move_uploaded_file($tmpName, $destPath)) {
        $errors[] = "$name: failed to save";
        continue;
    }

    // Calculate scheduled date: +3 days from previous
    $baseDate->modify('+3 days');
    $scheduledDate = $baseDate->format('Y-m-d H:i:s');

    // Match categories from original filename
    $filenameLower = strtolower(pathinfo($name, PATHINFO_FILENAME));
    // Replace common separators with spaces for matching
    $filenameLower = str_replace(['-', '_', '.'], ' ', $filenameLower);

    $matchedCatIds = [];

    // Check longest category names first to avoid partial matches
    // e.g. "sport atv" before "atv"
    $sortedCats = $catMap;
    uksort($sortedCats, function($a, $b) {
        return strlen($b) - strlen($a);
    });

    foreach ($sortedCats as $catName => $catId) {
        // Check if the category name appears in the filename
        if (strpos($filenameLower, $catName) !== false) {
            $matchedCatIds[] = $catId;
        }
    }

    // Also check common abbreviations / partial matches
    $aliases = [
        'atv'      => 'atv',
        'utv'      => 'utv',
        'offroad'  => 'off-road',
        'off road' => 'off-road',
        'dualsport' => 'dual sport',
        'dual sport' => 'dual sport',
        'street'   => 'street',
        'scooter'  => 'scooter',
        'sport atv' => 'sport atv',
    ];

    foreach ($aliases as $alias => $catName) {
        if (strpos($filenameLower, $alias) !== false && isset($catMap[$catName])) {
            if (!in_array($catMap[$catName], $matchedCatIds)) {
                $matchedCatIds[] = $catMap[$catName];
            }
        }
    }

    // Create the post
    try {
        $pdo->beginTransaction();

        $postStmt = $pdo->prepare(
            'INSERT INTO posts (company_id, caption, hashtags, scheduled_date, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $postStmt->execute([
            $companyId,
            'Please insert caption here',
            '',
            $scheduledDate,
            'pending'
        ]);
        $postId = $pdo->lastInsertId();

        // Insert the image
        $imgStmt = $pdo->prepare(
            'INSERT INTO post_images (post_id, image_url, sort_order) VALUES (?, ?, 1)'
        );
        $imgStmt->execute([$postId, $destPath]);

        // Insert category associations
        if (!empty($matchedCatIds)) {
            $catInsert = $pdo->prepare(
                'INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)'
            );
            foreach ($matchedCatIds as $catId) {
                $catInsert->execute([$postId, $catId]);
            }
        }

        $pdo->commit();

        $created[] = [
            'filename'   => $name,
            'post_id'    => $postId,
            'date'       => $scheduledDate,
            'categories' => $matchedCatIds,
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "$name: database error — " . $e->getMessage();
    }
}

echo json_encode([
    'ok'      => true,
    'created' => $created,
    'errors'  => $errors,
    'count'   => count($created),
]);
