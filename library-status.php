<?php
/**
 * Approve / deny / reset a single library image.
 * Accepts POST: id (library_images.id), status (pending|approved|denied)
 * Returns JSON { ok, id, status }.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!hasLibraryImagesTable($pdo)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'library_images table missing — run migrate.php']);
    exit;
}

$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}
if (!in_array($status, ['pending', 'approved', 'denied'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid status']);
    exit;
}

try {
    $pdo->beginTransaction();

    $sel = $pdo->prepare("
        SELECT id, company_id, filename, status
          FROM library_images
         WHERE id = ?
         FOR UPDATE
    ");
    $sel->execute([$id]);
    $row = $sel->fetch();
    if (!$row) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Image not found']);
        exit;
    }

    if ($row['status'] !== $status) {
        $pdo->prepare("UPDATE library_images SET status = ? WHERE id = ?")
            ->execute([$status, $id]);

        $action = ($status === 'approved') ? 'approved'
                : (($status === 'denied')  ? 'denied'
                : 'reset_pending');
        logActivity($pdo, (int)$row['company_id'], 'library_image', $id, $action, actorFromPost(),
            $row['filename'] . ' ' . actionLabel($action));
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'id' => $id, 'status' => $status]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
