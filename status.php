<?php
/**
 * Status / comment / date / caption update endpoint.
 * Accepts POST: id (int), and optionally:
 *   - status (pending|approved|denied)
 *   - comment (string, max 2000 chars; '' clears it)
 *   - scheduled_date (datetime string, parseable by strtotime)
 *   - caption (string, max 10000 chars)
 *   - hashtags (string, max 2000 chars)
 *   - post_type (post|story|reel; only when the migration-gated column exists)
 * At least one must be provided.
 * Role: status + comment are open; everything else needs the admin session (403 otherwise).
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
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)

$action = $_POST['action'] ?? '';

// ---- Role gate (server-side) ----
// Clients may change `status` and `comment` only. Everything Joust does —
// toggle_posted, delete_post, and edits to caption / hashtags / scheduled_date /
// post_type — requires the admin session (auth.php via helpers.php).
$isAdminSession = function_exists('currentAdmin') && currentAdmin() !== null;
$adminOnlyFields = ['scheduled_date', 'caption', 'hashtags', 'post_type'];
$needsAdmin = in_array($action, ['toggle_posted', 'delete_post'], true);
foreach ($adminOnlyFields as $f) {
    if (array_key_exists($f, $_POST)) { $needsAdmin = true; }
}
if ($needsAdmin && !$isAdminSession) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin sign-in required']);
    exit;
}

// ---- Toggle posted flag ----
if ($action === 'toggle_posted') {
    $postId = (int)($_POST['id'] ?? 0);
    $target = ((string)($_POST['to'] ?? '1')) === '1' ? 1 : 0;
    if ($postId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid id']);
        exit;
    }
    // Make sure the column actually exists (migrate.php may not have run).
    $colStmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'posts'
          AND COLUMN_NAME = 'posted'
    ");
    $colStmt->execute();
    if ((int)$colStmt->fetchColumn() === 0) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'posts.posted column missing — run migrate.php']);
        exit;
    }
    try {
        // Spec §4.3: only an approved post can be marked as scheduled (a stale tab
        // must not schedule a post the client has since denied / reset).
        if ($target === 1) {
            $stStmt = $pdo->prepare("SELECT status FROM posts WHERE id = ?");
            $stStmt->execute([$postId]);
            $curStatus = $stStmt->fetchColumn();
            if ($curStatus !== false && $curStatus !== 'approved') {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Only an approved post can be marked as scheduled']);
                exit;
            }
        }
        if ($target === 1) {
            $stmt = $pdo->prepare("UPDATE posts SET posted = 1, posted_at = NOW() WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE posts SET posted = 0, posted_at = NULL WHERE id = ?");
        }
        $stmt->execute([$postId]);

        // Capture company_id for activity log
        $coStmt = $pdo->prepare("SELECT company_id, posted_at FROM posts WHERE id = ?");
        $coStmt->execute([$postId]);
        $row = $coStmt->fetch();
        $coId = (int)($row['company_id'] ?? 0);
        $postedAt = $row['posted_at'] ?? null;
        if ($coId > 0) {
            logActivity($pdo, $coId, 'post', $postId,
                $target === 1 ? 'posted' : 'unposted', actorFromPost(),
                $target === 1 ? "Marked post #{$postId} as posted"
                              : "Unmarked post #{$postId}");
        }

        echo json_encode([
            'ok'        => true,
            'id'        => $postId,
            'posted'    => $target,
            'posted_at' => $postedAt,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Update failed']);
    }
    exit;
}

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
        // Capture company_id for the activity log before we cascade-delete.
        $coStmt = $pdo->prepare("SELECT company_id FROM posts WHERE id = ?");
        $coStmt->execute([$postId]);
        $deletedCompanyId = (int)$coStmt->fetchColumn();

        $imgs = $pdo->prepare("SELECT image_url FROM post_images WHERE post_id = ?");
        $imgs->execute([$postId]);
        foreach ($imgs->fetchAll() as $row) {
            $path = uploadsPathOrNull((string)$row['image_url']);   // realpath-contained in uploads/
            if ($path !== null) { @unlink($path); }
        }
        // CASCADE deletes post_images and post_categories
        $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
        if ($deletedCompanyId > 0) {
            logActivity($pdo, $deletedCompanyId, 'post', $postId,
                'deleted', actorFromPost(),
                "Deleted post #{$postId}");
        }
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
$hasType = array_key_exists('post_type', $_POST);
$status  = $_POST['status']  ?? null;
$comment = $_POST['comment'] ?? null;
$date    = $_POST['scheduled_date'] ?? null;
$caption = $_POST['caption'] ?? null;
$hashtags = $_POST['hashtags'] ?? null;
$postType = $hasType ? strtolower(trim((string)$_POST['post_type'])) : null;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}
if (!$hasStat && !$hasCmt && !$hasDate && !$hasCap && !$hasTag && !$hasType) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nothing to update']);
    exit;
}
if ($hasStat && !in_array($status, ['pending', 'approved', 'denied'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid status']);
    exit;
}
// Client verbs are Approve / Deny / Comment only (spec §2): resetting to review is Joust's.
if ($hasStat && $status === 'pending' && !$isAdminSession) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin sign-in required']);
    exit;
}
// Denying requires a reason — a note of at least 3 characters (spec §4.2 / §9), for every seat.
if ($hasStat && $status === 'denied' && mb_strlen(trim((string)($_POST['comment'] ?? '')), 'UTF-8') < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please add a short note (at least 3 characters) explaining what should change.']);
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

if ($hasType) {
    if (!hasPostTypeColumn($pdo)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'post_type not supported — run migrate.php']);
        exit;
    }
    if (!in_array($postType, allowedPostTypes(), true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid post_type']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Capture before-values for diff logging. posts.name / post_type are optional (migration-gated).
    $nameSel   = hasPostsNameColumn($pdo) ? 'name' : "'' AS name";
    $typeSel   = hasPostTypeColumn($pdo) ? 'post_type' : "'post' AS post_type";
    $postedSel = hasPostedColumn($pdo) ? 'posted' : '0 AS posted';
    $before = $pdo->prepare("
        SELECT company_id, status, client_comment, scheduled_date, caption, hashtags, {$nameSel}, {$typeSel}, {$postedSel}
          FROM posts WHERE id = ? FOR UPDATE
    ");
    $before->execute([$id]);
    $prev = $before->fetch();
    if (!$prev) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Post not found']);
        exit;
    }
    // Tenant scope: a client seat may only act on its own company's posts (admin bypasses).
    if (!clientOwnsCompany($pdo, (int)$prev['company_id'])) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'This post belongs to another client']);
        exit;
    }
    // A client cannot re-decide a post that is already scheduled or that they denied (spec §2).
    if ($hasStat && !$isAdminSession && (!empty($prev['posted']) || $prev['status'] === 'denied')) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'This post can no longer be changed here — add a comment instead']);
        exit;
    }
    // Friendly label used in activity-log summaries.
    $postLabel = postDisplayLabel([
        'name'    => $prev['name'] ?? '',
        'caption' => $prev['caption'] ?? '',
        'id'      => $id,
    ]);

    $sets   = [];
    $params = [];
    if ($hasStat) { $sets[] = 'status = ?';         $params[] = $status; }
    if ($hasCmt)  { $sets[] = 'client_comment = ?'; $params[] = $comment; }
    if ($hasDate) { $sets[] = 'scheduled_date = ?'; $params[] = $dateFormatted; }
    if ($hasCap)  { $sets[] = 'caption = ?';        $params[] = $caption; }
    if ($hasTag)  { $sets[] = 'hashtags = ?';       $params[] = $hashtags; }
    if ($hasType) { $sets[] = 'post_type = ?';      $params[] = $postType; }
    $params[] = $id;

    $sql  = 'UPDATE posts SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Diff-based activity logging — one row per actually-changed field, all sharing one batch_id.
    $companyId = (int)$prev['company_id'];
    $actor     = actorFromPost();
    $batchId   = newBatchId();

    if ($hasStat && $prev['status'] !== $status) {
        $action = ($status === 'approved') ? 'approved'
                : (($status === 'denied')  ? 'denied'
                : 'reset_pending');
        logActivity($pdo, $companyId, 'post', $id, $action, $actor,
            "{$postLabel} " . actionLabel($action),
            null, $batchId);
    }
    if ($hasCmt) {
        $prevCmt = $prev['client_comment'];
        // Chat semantics: any non-empty submission becomes a fresh message in the thread,
        // even if it matches the previous text. Empty submissions only clear once.
        if ($comment !== null && $comment !== '') {
            logActivity($pdo, $companyId, 'post', $id, 'commented', $actor,
                "Comment on {$postLabel}", $comment, $batchId);
        } elseif (($prevCmt ?? '') !== '') {
            logActivity($pdo, $companyId, 'post', $id, 'uncommented', $actor,
                "Cleared comment on {$postLabel}", $prevCmt, $batchId);
        }
    }
    if ($hasDate && $prev['scheduled_date'] !== $dateFormatted) {
        logActivity($pdo, $companyId, 'post', $id, 'edited_schedule', $actor,
            "Rescheduled post #{$id}",
            ($prev['scheduled_date'] ?? '') . ' → ' . ($dateFormatted ?? ''),
            $batchId);
    }
    if ($hasCap && (string)$prev['caption'] !== (string)$caption) {
        logActivity($pdo, $companyId, 'post', $id, 'edited_caption', $actor,
            "Edited caption on post #{$id}",
            mb_substr((string)$prev['caption'], 0, 200) . ' → ' . mb_substr((string)$caption, 0, 200),
            $batchId);
    }
    if ($hasTag && (string)$prev['hashtags'] !== (string)$hashtags) {
        logActivity($pdo, $companyId, 'post', $id, 'edited_hashtags', $actor,
            "Edited hashtags on post #{$id}",
            mb_substr((string)$prev['hashtags'], 0, 200) . ' → ' . mb_substr((string)$hashtags, 0, 200),
            $batchId);
    }

    if ($hasType && (string)($prev['post_type'] ?? 'post') !== $postType) {
        logActivity($pdo, $companyId, 'post', $id, 'edited_type', $actor,
            "Changed type on post #{$id}",
            ($prev['post_type'] ?? 'post') . ' → ' . $postType,
            $batchId);
    }

    $pdo->commit();

    echo json_encode([
        'ok'             => true,
        'id'             => $id,
        'status'         => $hasStat ? $status         : null,
        'comment'        => $hasCmt  ? $comment        : null,
        'scheduled_date' => $hasDate ? $dateFormatted  : null,
        'caption'        => $hasCap  ? $caption        : null,
        'hashtags'       => $hasTag  ? $hashtags       : null,
        'post_type'      => $hasType ? $postType       : null,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
