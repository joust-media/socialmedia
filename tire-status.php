<?php
/**
 * Tire IMAGE status / comment update endpoint.
 * Each tire image is independently approvable / commentable.
 *
 * Accepts POST: id (int = tire_images.id), and optionally:
 *   - status (pending|approved|denied)
 *   - comment (string, max 2000 chars; '' clears it)
 * Returns JSON.
 */

require __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$id      = (int)($_POST['id'] ?? 0);
$action  = $_POST['action'] ?? '';

// ---- Delete entire tire ----
if ($action === 'delete_tire') {
    $tireId = (int)($_POST['tire_id'] ?? 0);
    if ($tireId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid tire_id']);
        exit;
    }
    try {
        $pdo->beginTransaction();
        // Delete image files from disk
        $imgs = $pdo->prepare("SELECT image_url FROM tire_images WHERE tire_id = ?");
        $imgs->execute([$tireId]);
        foreach ($imgs->fetchAll() as $row) {
            if (strpos($row['image_url'], 'uploads/') === 0) {
                $path = __DIR__ . '/' . $row['image_url'];
                if (is_file($path)) { @unlink($path); }
            }
        }
        // CASCADE deletes tire_images and tire_categories
        $pdo->prepare("DELETE FROM tires WHERE id = ?")->execute([$tireId]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'tire_id' => $tireId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Delete failed']);
    }
    exit;
}

$hasStat = array_key_exists('status', $_POST);
$hasCmt  = array_key_exists('comment', $_POST);
$status  = $_POST['status']  ?? null;
$comment = $_POST['comment'] ?? null;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}
if (!$hasStat && !$hasCmt) {
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

try {
    $sets   = [];
    $params = [];
    if ($hasStat) { $sets[] = 'status = ?';         $params[] = $status; }
    if ($hasCmt)  { $sets[] = 'client_comment = ?'; $params[] = $comment; }
    $params[] = $id;

    $sql  = 'UPDATE tire_images SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'ok'      => true,
        'id'      => $id,
        'status'  => $hasStat ? $status  : null,
        'comment' => $hasCmt  ? $comment : null,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
