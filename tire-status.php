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
require_once __DIR__ . '/helpers.php';

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
        // Capture company_id before cascade.
        $coStmt = $pdo->prepare("SELECT company_id, name FROM tires WHERE id = ?");
        $coStmt->execute([$tireId]);
        $tireRow = $coStmt->fetch();

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
        if ($tireRow) {
            logActivity($pdo, (int)$tireRow['company_id'], 'tire', $tireId,
                'deleted', actorFromPost(),
                "Deleted item #{$tireId} (" . ($tireRow['name'] ?? '') . ")");
        }
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
    $pdo->beginTransaction();

    $hasDisplayName = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0;
    $nameSel = $hasDisplayName ? 'ti.display_name' : "'' AS display_name";
    $before = $pdo->prepare("
        SELECT t.company_id, ti.status, ti.client_comment, ti.caption, {$nameSel}
          FROM tire_images ti
          INNER JOIN tires t ON t.id = ti.tire_id
         WHERE ti.id = ?
         FOR UPDATE
    ");
    $before->execute([$id]);
    $prev = $before->fetch();
    if (!$prev) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Image not found']);
        exit;
    }
    // Friendly label for activity-log summaries — preferred over bare "image #42".
    $imgLabel = imageDisplayLabel(['display_name' => $prev['display_name'], 'caption' => $prev['caption'], 'id' => $id]);

    $sets   = [];
    $params = [];
    if ($hasStat) { $sets[] = 'status = ?';         $params[] = $status; }
    if ($hasCmt)  { $sets[] = 'client_comment = ?'; $params[] = $comment; }
    $params[] = $id;

    $sql  = 'UPDATE tire_images SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $companyId = (int)$prev['company_id'];
    $actor     = actorFromPost();
    $batchId   = newBatchId();

    if ($hasStat && $prev['status'] !== $status) {
        $action = ($status === 'approved') ? 'approved'
                : (($status === 'denied')  ? 'denied'
                : 'reset_pending');
        logActivity($pdo, $companyId, 'tire_image', $id, $action, $actor,
            "{$imgLabel} " . actionLabel($action),
            null, $batchId);
    }
    if ($hasCmt) {
        $prevCmt = $prev['client_comment'];
        // Chat semantics: any non-empty submission becomes a fresh message in the thread,
        // even if it matches the previous text. Empty submissions only clear once.
        if ($comment !== null && $comment !== '') {
            logActivity($pdo, $companyId, 'tire_image', $id, 'commented', $actor,
                "Comment on {$imgLabel}", $comment, $batchId);
        } elseif (($prevCmt ?? '') !== '') {
            logActivity($pdo, $companyId, 'tire_image', $id, 'uncommented', $actor,
                "Cleared comment on {$imgLabel}", $prevCmt, $batchId);
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok'      => true,
        'id'      => $id,
        'status'  => $hasStat ? $status  : null,
        'comment' => $hasCmt  ? $comment : null,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
