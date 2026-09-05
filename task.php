<?php
/**
 * Task CRUD endpoint.
 *
 * Accepts POST with `action`:
 *   - create:   company_id, title, [description], [priority], [created_by=client]
 *   - update:   id, [title], [description], [status], [priority]
 *   - toggle:   id  (flips status between 'open' and 'done')
 *   - delete:   id
 *
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

$action = $_POST['action'] ?? '';

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$VALID_STATUS   = ['open', 'in_progress', 'done'];
$VALID_PRIORITY = ['low', 'normal', 'high'];
$VALID_CREATOR  = ['client', 'admin'];

try {
    switch ($action) {

        case 'create': {
            $companyId = (int)($_POST['company_id'] ?? 0);
            $title     = trim((string)($_POST['title'] ?? ''));
            $desc      = trim((string)($_POST['description'] ?? ''));
            $priority  = $_POST['priority'] ?? 'normal';
            $createdBy = $_POST['created_by'] ?? 'client';

            if ($companyId <= 0) fail('Invalid company');
            if ($title === '')   fail('Title required');
            if (mb_strlen($title) > 300)  fail('Title too long (max 300 chars)');
            if (mb_strlen($desc)  > 5000) fail('Description too long');
            if (!in_array($priority,  $VALID_PRIORITY, true)) $priority  = 'normal';
            if (!in_array($createdBy, $VALID_CREATOR,  true)) $createdBy = 'client';

            $stmt = $pdo->prepare("
                INSERT INTO tasks (company_id, title, description, priority, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$companyId, $title, $desc ?: null, $priority, $createdBy]);
            $newId = (int)$pdo->lastInsertId();

            logActivity($pdo, $companyId, 'task', $newId, 'task_created', $createdBy,
                'Opened task: ' . mb_substr($title, 0, 200), $desc ?: null);

            // Return the full task row for easy client-side rendering
            $row = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
            $row->execute([$newId]);
            echo json_encode(['ok' => true, 'task' => $row->fetch()]);
            exit;
        }

        case 'update': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) fail('Invalid id');

            $sets   = [];
            $params = [];

            if (array_key_exists('title', $_POST)) {
                $title = trim((string)$_POST['title']);
                if ($title === '') fail('Title cannot be empty');
                if (mb_strlen($title) > 300) fail('Title too long');
                $sets[] = 'title = ?';
                $params[] = $title;
            }
            if (array_key_exists('description', $_POST)) {
                $desc = trim((string)$_POST['description']);
                if (mb_strlen($desc) > 5000) fail('Description too long');
                $sets[] = 'description = ?';
                $params[] = $desc !== '' ? $desc : null;
            }
            if (array_key_exists('status', $_POST)) {
                $status = $_POST['status'];
                if (!in_array($status, $VALID_STATUS, true)) fail('Invalid status');
                $sets[] = 'status = ?';
                $params[] = $status;
                // Set completed_at automatically
                if ($status === 'done') {
                    $sets[] = 'completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)';
                } else {
                    $sets[] = 'completed_at = NULL';
                }
            }
            if (array_key_exists('priority', $_POST)) {
                $priority = $_POST['priority'];
                if (!in_array($priority, $VALID_PRIORITY, true)) fail('Invalid priority');
                $sets[] = 'priority = ?';
                $params[] = $priority;
            }

            if (!$sets) fail('Nothing to update');

            // Capture company_id + before-status for logging.
            $pre = $pdo->prepare("SELECT company_id, status, title FROM tasks WHERE id = ?");
            $pre->execute([$id]);
            $preRow = $pre->fetch();

            $params[] = $id;
            $stmt = $pdo->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($params);

            if ($preRow) {
                $newStatus = $_POST['status'] ?? $preRow['status'];
                $summary   = "Updated task #{$id}";
                if (array_key_exists('status', $_POST) && $newStatus !== $preRow['status']) {
                    $summary = "Task #{$id} {$preRow['status']} → {$newStatus}: "
                             . mb_substr((string)$preRow['title'], 0, 200);
                }
                logActivity($pdo, (int)$preRow['company_id'], 'task', $id,
                    'task_updated', actorFromPost(), $summary);
            }

            echo json_encode(['ok' => true, 'id' => $id]);
            exit;
        }

        case 'toggle': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) fail('Invalid id');

            // Flip between open <-> done
            $stmt = $pdo->prepare("
                UPDATE tasks
                SET status = CASE WHEN status = 'done' THEN 'open' ELSE 'done' END,
                    completed_at = CASE WHEN status = 'done' THEN NULL ELSE CURRENT_TIMESTAMP END
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            $sel = $pdo->prepare("SELECT company_id, status, title FROM tasks WHERE id = ?");
            $sel->execute([$id]);
            $row = $sel->fetch();
            $newStatus = $row['status'] ?? null;

            if ($row) {
                logActivity($pdo, (int)$row['company_id'], 'task', $id,
                    'task_toggled', actorFromPost(),
                    "Task #{$id} → {$newStatus}: " . mb_substr((string)$row['title'], 0, 200));
            }

            echo json_encode(['ok' => true, 'id' => $id, 'status' => $newStatus]);
            exit;
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) fail('Invalid id');

            // Capture company_id + title before deletion.
            $pre = $pdo->prepare("SELECT company_id, title FROM tasks WHERE id = ?");
            $pre->execute([$id]);
            $preRow = $pre->fetch();

            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt->execute([$id]);

            if ($preRow) {
                logActivity($pdo, (int)$preRow['company_id'], 'task', $id,
                    'task_deleted', actorFromPost(),
                    "Deleted task #{$id}: " . mb_substr((string)$preRow['title'], 0, 200));
            }

            echo json_encode(['ok' => true, 'id' => $id]);
            exit;
        }

        default:
            fail('Unknown action');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
