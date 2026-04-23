<?php
/**
 * Status / comment / date / caption update endpoint.
 * Accepts POST: id (int), and optionally:
 *   - status (pending|approved|denied)
 *   - comment (string, max 2000 chars; '' clears it)
 *   - scheduled_date (datetime string, parseable by strtotime)
 *   - caption (string, max 10000 chars)
 * At least one must be provided.
 * Returns JSON.
 */

require __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

// ---- Delete entire post ----
if ($action === 'delete_post') {
    $postId = (int)($_POST['id'] ?? 0);
    if ($postId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid id']);
        exit;
    }
    try {
        $pdo->beginTransaction();
        $imgs = $pdo->prepare("SELECT image_url FROM post_images WHERE post_id = ?");
        $imgs->execute([$postId]);
        foreach ($imgs->fetchAll() as $row) {
            if (strpos($row['image_url'], 'uploads/') === 0) {
                $path = __DIR__ . '/' . $row['image_url'];
                if (is_file($path)) { @unlink($path); }
            }
        }
        // CASCADE deletes post_images and post_categories
        $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $postId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Delete failed']);
    }
    exit;
}

$id      = (int)($_POST['id'] ?? 0);
$hasStat = array_key_exists('status', $_POST);
$hasCmt  = array_key_exists('comment', $_POST);
$hasDate = array_key_exists('scheduled_date', $_POST);
$hasCap  = array_key_exists('caption', $_POST);
$hasTag  = array_key_exists('hashtags', $_POST);
$status  = $_POST['status']  ?? null;
$comment = $_POST['comment'] ?? null;
$date    = $_POST['scheduled_date'] ?? null;
$caption = $_POST['caption'] ?? null;
$hashtags = $_POST['hashtags'] ?? null;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}
if (!$hasStat && !$hasCmt && !$hasDate && !$hasCap && !$hasTag) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nothing to update']);
    exit;
}
if ($hasStat && !in_array($status, ['pending', 'approved', 'denied'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid status']);
    exit;
}
if ($hasCmt) {
    $comment = trim((string)$comment);
    if (strlen($comment) > 2000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Comment too long (max 2000 chars)']);
        exit;
    }
    if ($comment === '') { $comment = null; }
}
$dateFormatted = null;
if ($hasDate) {
    $ts = strtotime((string)$date);
    if ($ts === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid date']);
        exit;
    }
    $dateFormatted = date('Y-m-d H:i:s', $ts);
}
if ($hasCap) {
    $caption = (string)$caption;
    if (strlen($caption) > 10000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Caption too long (max 10000 chars)']);
        exit;
    }
    if (trim($caption) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Caption cannot be empty']);
        exit;
    }
}
if ($hasTag) {
    $hashtags = trim((string)$hashtags);
    if (strlen($hashtags) > 2000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Hashtags too long (max 2000 chars)']);
        exit;
    }
}

try {
    $sets   = [];
    $params = [];
    if ($hasStat) { $sets[] = 'status = ?';         $params[] = $status; }
    if ($hasCmt)  { $sets[] = 'client_comment = ?'; $params[] = $comment; }
    if ($hasDate) { $sets[] = 'scheduled_date = ?'; $params[] = $dateFormatted; }
    if ($hasCap)  { $sets[] = 'caption = ?';        $params[] = $caption; }
    if ($hasTag)  { $sets[] = 'hashtags = ?';       $params[] = $hashtags; }
    $params[] = $id;

    $sql  = 'UPDATE posts SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'ok'             => true,
        'id'             => $id,
        'status'         => $hasStat ? $status         : null,
        'comment'        => $hasCmt  ? $comment        : null,
        'scheduled_date' => $hasDate ? $dateFormatted  : null,
        'caption'        => $hasCap  ? $caption        : null,
        'hashtags'       => $hasTag  ? $hashtags       : null,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
