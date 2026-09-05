<?php
/**
 * Approve / deny / reset a single library image.
 *
 * Accepts POST:
 *   - id      (library_images.id, required)
 *   - status  (pending|approved|denied, required)
 *   - comment (string, optional, max 2000 chars) — the client's note.
 *             library_images has no comment column, so the note is persisted
 *             only as an activity_log row (action='commented', text in
 *             `detail`), exactly like tire-status.php / status.php do, sharing
 *             a batch_id with the status-change row. commentThread($pdo,
 *             'library_image', $id) reads it back unchanged.
 *             REQUIRED (>= 3 chars) when status=denied → otherwise HTTP 422.
 * Returns JSON { ok, id, status, comment }.
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

$id      = (int)($_POST['id'] ?? 0);
$status  = $_POST['status'] ?? '';
$hasCmt  = array_key_exists('comment', $_POST);
$comment = $hasCmt ? trim((string)$_POST['comment']) : '';

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
if (strlen($comment) > 2000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Comment too long (max 2000 chars)']);
    exit;
}
// Denying requires a reason — a note of at least 3 characters (spec §4.2 / §9).
if ($status === 'denied' && mb_strlen($comment, 'UTF-8') < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please add a short note (at least 3 characters) explaining what should change.']);
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

    $companyId = (int)$row['company_id'];
    $actor     = actorFromPost();
    $batchId   = newBatchId();   // groups the status row and the comment row from this request

    if ($row['status'] !== $status) {
        $pdo->prepare("UPDATE library_images SET status = ? WHERE id = ?")
            ->execute([$status, $id]);

        $action = ($status === 'approved') ? 'approved'
                : (($status === 'denied')  ? 'denied'
                : 'reset_pending');
        // Human summary — never the raw on-disk filename (it used to leak into the activity feed).
        $summary = ($action === 'approved') ? 'Approved an image in Library'
                 : (($action === 'denied')  ? 'Denied an image in Library'
                 : 'Reset an image in Library to pending');
        logActivity($pdo, $companyId, 'library_image', $id, $action, $actor, $summary, null, $batchId);
    }

    if ($comment !== '') {
        // Chat semantics (same as tire-status.php): every non-empty submission is a new
        // message in the thread. The text lives in `detail`; commentThread() reads it back.
        logActivity($pdo, $companyId, 'library_image', $id, 'commented', $actor,
            'Comment on an image in Library', $comment, $batchId);
    }

    $pdo->commit();
    echo json_encode([
        'ok'      => true,
        'id'      => $id,
        'status'  => $status,
        'comment' => $comment !== '' ? $comment : null,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
