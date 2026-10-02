<?php
/**
 * Batch create endpoint (Studio → Batch). Admin only — JSON 401 otherwise.
 *
 * POST batch-process.php?client=<slug>   (multipart or urlencoded)
 *
 *   Every post this endpoint creates is a DRAFT (posts.status = 'draft', migrate.php step 35): only
 *   Joust sees it until "Send for review" (status.php action=submit). Before that migration has run
 *   the old behaviour stays (pending, with the placeholder caption for captionless files).
 *
 *   images[]        files — one draft post per file, empty caption (existing contract; the no-JS path).
 *                   Images must pass getimagesize(); MP4/WebM/MOV accepted
 *                   (same rules as add-post.php, spec §6 — .mov is kept as-is);
 *                   .m4v/.avi/.mkv rejected with the "convert to MP4" message.
 *   claimed[]       tokens of files already sent to upload-chunk.php (purpose=batch —
 *                   in pieces when large: videos up to 4 GB, images up to 50 MB). Each
 *                   is validated (format, sidecar purpose + client, file present, < 24 h;
 *                   a bad one is an entry in `errors`), renamed to its batch_ / batch_vid_
 *                   name and becomes one draft post exactly like a file in images[].
 *                   Up to 50 files (images[] + claimed[]) per batch.
 *   rows            JSON array — one post per row, media from the Approved Pool:
 *                   [{ "caption": "…", "hashtags": "…", "scheduled_date": "2026-09-12T10:00",
 *                      "post_type": "post|story|reel", "assets": ["library:12", "tire:34"],
 *                      "status": "draft|pending" (optional, default draft; pending needs a caption) }, …]
 *                   Each asset is validated (this company + status='approved') and
 *                   COPIED into uploads/ as a post_images row in the given order (up to
 *                   POST_MAX_MEDIA per row — a carousel).
 *   spacing_days    1–30 (default 3) — used for rows/files without a date.
 *
 * Response (same shape; each created item also carries "status"):
 *   {"ok":true,"created":[{"filename","post_id","date","categories":[…],"status"}],"errors":[…],"count":N}
 *
 * Client scoping comes from helpers.php ($client from ?client=) — the previous
 * resolveClient() call did not exist and fataled on every request.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/chunk-upload-lib.php';
require_once __DIR__ . '/upload-lib.php';
require_once __DIR__ . '/partials/components/asset-pool.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)

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
$claimedRaw = $_POST['claimed'] ?? [];
$claimedRaw = is_array($claimedRaw) ? array_values(array_filter($claimedRaw, 'is_string')) : [];

if (!$hasFiles && !$rows && !$claimedRaw) {
    echo json_encode(['ok' => false, 'error' => 'No images uploaded']);
    exit;
}

$maxFiles  = 50;
$fileCount = $hasFiles ? count($_FILES['images']['name']) : 0;
if ($fileCount + count($claimedRaw) > $maxFiles) {
    echo json_encode(['ok' => false, 'error' => "Maximum {$maxFiles} files per batch"]);
    exit;
}
if (count($rows) > 20) {
    echo json_encode(['ok' => false, 'error' => 'Maximum 20 posts per batch']);
    exit;
}

$spacingDays = max(1, min(30, (int)($_POST['spacing_days'] ?? 3)));
$allowedExt  = array_merge(imageExts(), videoExts());   // + mov (spec §6)
$rejectedExt = ['m4v', 'avi', 'mkv'];
$hasMedia    = hasMediaTypeColumn($pdo);
$hasType     = hasPostTypeColumn($pdo);
$defaultTags = trim((string)($client['default_hashtags'] ?? ''));
$hasDraft    = postsHaveDraft($pdo);
// Before migrate.php step 35 a file post is client-visible at once, so it keeps the old placeholder.
$fileCaption = $hasDraft ? '' : 'Please insert caption here';

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
if (!is_dir($uploadDir)) { mediaMkdir($uploadDir); }

$created = [];
$errors  = [];

/** Insert a post (draft or pending) and return its id. */
function batchInsertPost(PDO $pdo, int $companyId, string $caption, string $hashtags, string $date, string $type, bool $hasType, string $status = 'pending'): int {
    if ($hasType) {
        $st = $pdo->prepare('INSERT INTO posts (company_id, caption, hashtags, scheduled_date, status, post_type) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$companyId, $caption, $hashtags, $date, $status, $type]);
    } else {
        $st = $pdo->prepare('INSERT INTO posts (company_id, caption, hashtags, scheduled_date, status) VALUES (?, ?, ?, ?, ?)');
        $st->execute([$companyId, $caption, $hashtags, $date, $status]);
    }
    return (int)$pdo->lastInsertId();
}

/**
 * One draft post for a stored file (uploads/<safeName>, already validated + moved): the post, its
 * post_images row, filename-matched categories, the activity line. Appends to $created / $errors.
 */
function batchCreateFromFile(PDO $pdo, int $companyId, string $name, string $safeName, bool $isVideo, string $scheduledDate, array $matchedCatIds, bool $hasMedia, bool $hasType, string $defaultTags, array &$created, array &$errors): void {
    global $hasDraft, $fileCaption;
    $destPath = __DIR__ . '/uploads/' . $safeName;
    $imageUrl = 'uploads/' . $safeName;
    $status   = $hasDraft ? 'draft' : 'pending';
    try {
        $pdo->beginTransaction();
        $postId = batchInsertPost($pdo, $companyId, $fileCaption, $defaultTags, $scheduledDate, 'post', $hasType, $status);
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
        logActivity($pdo, $companyId, 'post', $postId, $status === 'draft' ? 'drafted' : 'created', 'admin',
            ($status === 'draft' ? 'Started draft post #' : 'Created post #') . $postId . ' from an upload');
        $pdo->commit();
        if (!$isVideo && function_exists('previewAfterStore')) previewAfterStore($destPath);   // sm + lg previews (per-request budget; the rest lazily)
        $created[] = [
            'filename'   => $name,
            'post_id'    => $postId,
            'date'       => $scheduledDate,
            'categories' => $matchedCatIds,
            'image_url'  => $imageUrl,
            'media_type' => $isVideo ? 'video' : 'image',
            'status'     => $status,
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_file($destPath)) @unlink($destPath);
        error_log('batch-process file ' . $name . ': ' . $e->getMessage());
        $errors[] = "$name: database error";
    }
}

// ---------------------------------------------------------------------
// 1. Rows built from the Approved Pool
// ---------------------------------------------------------------------
foreach ($rows as $i => $row) {
    if (!is_array($row)) { $errors[] = 'Row ' . ($i + 1) . ': invalid'; continue; }
    $label    = 'Row ' . ($i + 1);
    $caption  = trim((string)($row['caption'] ?? ''));
    if (mb_strlen($caption) > 10000) $caption = mb_substr($caption, 0, 10000);
    // Draft unless the row asks for review AND has a caption (Send for review needs one).
    $status   = $hasDraft ? 'draft' : 'pending';
    if ($hasDraft && ($row['status'] ?? '') === 'pending') {
        if ($caption !== '') { $status = 'pending'; }
        else { $errors[] = "$label: saved as a draft — add a caption before sending it for review"; }
    }
    if ($caption === '' && $status === 'pending') $caption = 'Please insert caption here';   // pre-migration only
    $hashtags = array_key_exists('hashtags', $row) ? trim((string)$row['hashtags']) : $defaultTags;
    if (mb_strlen($hashtags) > 2000) $hashtags = mb_substr($hashtags, 0, 2000);
    $type     = strtolower(trim((string)($row['post_type'] ?? 'post')));
    if (!in_array($type, allowedPostTypes(), true)) $type = 'post';
    $picks    = studioParsePicks($row['assets'] ?? [], POST_MAX_MEDIA);
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
        $postId = batchInsertPost($pdo, $companyId, $caption, $hashtags, $scheduledDate, $type, $hasType, $status);
        $attached = studioAttachAssetsToPost($pdo, $client, $postId, $picks, ['uploadsDir' => $uploadDir, 'max' => POST_MAX_MEDIA]);
        logActivity($pdo, $companyId, 'post', $postId, $status === 'draft' ? 'drafted' : 'created', 'admin',
            ($status === 'draft' ? 'Started draft post #' : 'Created post #') . $postId . ' via batch (' . count($attached) . ' from Approved assets)');
        $pdo->commit();
        $created[] = [
            'filename'   => $attached ? (string)$attached[0]['asset']['label'] : $label,
            'post_id'    => $postId,
            'date'       => $scheduledDate,
            'categories' => [],
            'assets'     => count($attached),
            'caption'    => $caption,
            'status'     => $status,
        ];
    } catch (StudioAssetException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = "$label: " . $e->getMessage();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('batch-process row ' . $label . ': ' . $e->getMessage());
        $errors[] = "$label: database error";
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
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, $rejectedExt, true)) {
        $errors[] = "$name: .$ext isn't web-playable — convert to MP4 first (QuickTime: File → Export As → 1080p). Chrome and Firefox can't play .$ext.";
        continue;
    }
    if (!in_array($ext, $allowedExt, true)) {
        $errors[] = "$name: unsupported file type — use JPG, PNG, GIF, WebP, MP4, WebM, or MOV";
        continue;
    }
    $isVideo = isVideoExt($ext);
    if ($size > uploadMaxBytes($isVideo ? 'video' : 'image')) {
        $errors[] = "$name: exceeds " . uploadCapLabel(uploadMaxBytes($isVideo ? 'video' : 'image')) . ' limit';
        continue;
    }
    if ($isVideo) {
        if (!videoFileLooksValid((string)$tmpName, $ext)) { $errors[] = "$name: doesn't look like a valid video file"; continue; }
    } else {
        if (!@getimagesize($tmpName)) { $errors[] = "$name: not a valid image"; continue; }
    }

    $safeName = uniqid($isVideo ? 'batch_vid_' : 'batch_', true) . '.' . $ext;
    $safeName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $safeName);
    $destPath = $uploadDir . '/' . $safeName;
    $imageUrl = 'uploads/' . $safeName;

    if (!uploadMoveInto((string)$tmpName, $destPath, true)) {
        $errors[] = "$name: failed to save";
        continue;
    }

    $baseDate->modify('+' . $spacingDays . ' days');
    $scheduledDate = $baseDate->format('Y-m-d H:i:s');
    $matchedCatIds = batchMatchCategories($name, $sortedCats, $catMap, $aliases);
    batchCreateFromFile($pdo, $companyId, $name, $safeName, $isVideo, $scheduledDate, $matchedCatIds, $hasMedia, $hasType, $defaultTags, $created, $errors);
}

// ---------------------------------------------------------------------
// 3. Files already uploaded through upload-chunk.php (claimed[] tokens) — one post per file
// ---------------------------------------------------------------------
foreach ($claimedRaw as $token) {
    $label = 'Uploaded file ' . substr($token, 0, 8);
    $claim = uploadClaimRead($token, 'batch', (string)$client['slug']);
    if ($claim === null) { $errors[] = "$label: that upload has expired or could not be found — add the file again"; continue; }
    $name     = (string)$claim['name'];
    $isVideo  = !empty($claim['video']);
    $safeName = uploadFreshName($isVideo ? 'batch_vid_' : 'batch_', (string)$claim['ext']);
    if (!uploadClaimTake($claim, $uploadDir . '/' . $safeName)) { $errors[] = "$name: failed to save"; continue; }

    $baseDate->modify('+' . $spacingDays . ' days');
    $scheduledDate = $baseDate->format('Y-m-d H:i:s');
    $matchedCatIds = batchMatchCategories($name, $sortedCats, $catMap, $aliases);
    batchCreateFromFile($pdo, $companyId, $name, $safeName, $isVideo, $scheduledDate, $matchedCatIds, $hasMedia, $hasType, $defaultTags, $created, $errors);
}

echo json_encode([
    'ok'      => true,
    'created' => $created,
    'errors'  => $errors,
    'count'   => count($created),
]);
