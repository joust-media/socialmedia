<?php
/**
 * Email status / comment / live / delete endpoint (mirrors status.php; scratchpad emails-design.md §2–3, §6).
 *
 * POST only. Fields:
 *   id                      emails.id (required)
 *   status                  draft|pending|approved|denied      — decide / route
 *   comment                 string, max 2000 chars            — a thread message (deny note when sent with status=denied)
 *   action=submit           draft → pending ("Send for review")            admin only
 *   action=toggle_live&to=0|1   live flag (to=1 requires status=approved → 409)   admin only
 *   action=delete_email     remove the row (+ group mapping)                admin only
 *   actor                   admin may act as client|admin; a client seat is always 'client' (actorFromPost)
 *   client                  tenant slug (App.post appends it) — must own the email's company (403)
 *
 * Client seat: approve / request changes (note ≥ 3 chars) on a pending, non-live email, Approve instead on one it
 * sent back (Needs changes — its "Sent back" list, sentback-lib.php), and comment (also on a sent-back one).
 * Everything else — draft/pending, live toggles, delete, re-deciding approved/denied/live rows —
 * needs the admin session (403; live rows answer 409 for a client decision).
 *
 * Reply: {ok, id, status, live, key, label} · errors {ok:false, error} with 400/403/404/405/409/422.
 * Every mutation logs through logEmailActivity() (approved, denied, reset_pending, submitted,
 * edited_status, commented, marked_live, unmarked_live, deleted); a deny note shares the deny's batch.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

function emailFail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function emailReply(array $email, array $extra = []): void {
    $key = emailStatusKey($email);
    echo json_encode(array_merge([
        'ok'     => true,
        'id'     => (int)$email['id'],
        'status' => (string)$email['status'],
        'live'   => !empty($email['live']) ? 1 : 0,
        'key'    => $key,
        'comment_id' => $GLOBALS['__lastCommentId'] ?? null,   // the new comment (comment-edit.php can change it)
        'label'  => emailStatusLabelForKey($key),
    ], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    emailFail(405, 'Method not allowed');
}
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)

if (!hasEmailsTable($pdo)) {
    emailFail(404, 'Emails are not set up yet');
}

$isAdminSession = isAdmin();
$action  = (string)($_POST['action'] ?? '');
$id      = (int)($_POST['id'] ?? 0);
$hasStat = array_key_exists('status', $_POST);
$hasCmt  = array_key_exists('comment', $_POST);
$status  = $hasStat ? strtolower(trim((string)$_POST['status'])) : null;
$comment = $hasCmt ? trim((string)$_POST['comment']) : null;

if ($id <= 0) {
    emailFail(400, 'Invalid id');
}
// Joust's Trash (trash-lib.php): gone for the client (404), frozen for Joust (409) until restored
if ($action !== 'delete_email') trashGuardJson($pdo, 'email', $id, $isAdminSession);
if (!in_array($action, ['', 'submit', 'toggle_live', 'delete_email'], true)) {
    emailFail(400, 'Unknown action');
}
// ---- Role gate (server-side): Joust-only verbs ----
if (in_array($action, ['submit', 'toggle_live', 'delete_email'], true) && !$isAdminSession) {
    emailFail(403, 'Admin sign-in required');
}
if ($action === '' && !$hasStat && !$hasCmt) {
    emailFail(400, 'Nothing to update');
}
if ($hasStat && !in_array($status, ['draft', 'pending', 'approved', 'denied'], true)) {
    emailFail(400, 'Invalid status');
}
// Client verbs are Approve / Needs changes / Comment only: routing to draft or back to review is Joust's.
if ($hasStat && in_array($status, ['draft', 'pending'], true) && !$isAdminSession) {
    emailFail(403, 'Admin sign-in required');
}
// Requesting changes needs a reason — a note of at least 3 characters — for every seat (same copy as status.php).
if ($hasStat && $status === 'denied' && mb_strlen(trim((string)($_POST['comment'] ?? '')), 'UTF-8') < 3) {
    emailFail(422, 'Please add a short note (at least 3 characters) explaining what should change.');
}
if ($hasCmt) {
    if (strlen((string)$comment) > 2000) {
        emailFail(400, 'Comment too long (max 2000 chars)');
    }
    if ($comment === '') { $hasCmt = false; $comment = null; }
    if (!$hasCmt && !$hasStat && $action === '') {
        emailFail(400, 'Nothing to update');
    }
}

// ---- Internal note (admin, comment only): Joust-only — never shown to the client, no client email, Slack marks it ----
if (array_key_exists('internal', $_POST) && (string)$_POST['internal'] !== '' && (string)$_POST['internal'] !== '0') {
    if (!$isAdminSession) emailFail(403, 'Admin sign-in required');
    if (!$hasCmt || $hasStat || $action !== '') emailFail(400, 'An internal note is a message only');
    $row = emailById($pdo, $id);
    if (!$row) emailFail(404, 'Email not found');
    $note = (string)$comment;
    $noteLabel = emailDisplayLabel($row);
    activityWithContext(['internal' => 1], static function () use ($pdo, $row, $id, $noteLabel, $note) {
        logActivity($pdo, (int)$row['company_id'], 'email', $id, 'commented', 'admin', "Internal note on {$noteLabel}", $note, newBatchId());
    });
    echo json_encode(['ok' => true, 'id' => $id, 'comment' => $note, 'internal' => true, 'comment_id' => $GLOBALS['__lastCommentId'] ?? null]);
    exit;
}

// ---- Load + tenant check (company always comes from the row, never the form) ----
$email = emailById($pdo, $id);
if (!$email) {
    emailFail(404, 'Email not found');
}
if (!clientOwnsCompany($pdo, (int)$email['company_id'])) {
    emailFail(403, 'This email belongs to another client');
}
// Clients see live / pending / approved rows and the ones they sent back (Needs changes — sentback-lib.php); a draft
// is "not found" for them.
if (!$isAdminSession && empty($email['live']) && !in_array((string)$email['status'], ['pending', 'approved', 'denied'], true)) {
    emailFail(404, 'Email not found');
}

$companyId = (int)$email['company_id'];
$actor     = actorFromPost();
$label     = emailDisplayLabel($email);
$prevStat  = (string)$email['status'];
$prevLive  = !empty($email['live']) ? 1 : 0;

try {
    // ---- toggle_live (admin) ---- (rules + activity row: transitions-lib.php, shared with the Slack buttons)
    if ($action === 'toggle_live') {
        $res = transitionMailLive($pdo, 'email', $email, ((string)($_POST['to'] ?? '1')) === '1' ? 1 : 0, $actor);
        if (empty($res['ok'])) {
            emailFail((int)$res['code'], (string)$res['error']);
        }
        $email = $res['row'];
        emailReply($email, ['live_at' => $email['live_at']]);
    }

    // ---- delete_email (admin) ----
    if ($action === 'delete_email') {
        // Same path as add-email.php's delete (emails-lib.php): map rows + row, 'deleted' logged with the company id.
        $pdo->beginTransaction();
        deleteEmail($pdo, $email, $actor);
        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $id, 'deleted' => 1]);
        exit;
    }

    // ---- submit (admin): draft → pending ---- (no note: transitions-lib.php, shared with the Slack buttons;
    //      with a note it takes the generic route below, which applies the same rules plus the comment)
    if ($action === 'submit' && !$hasCmt && !$hasStat) {
        $res = transitionMailSubmit($pdo, 'email', $email, $actor);
        if (empty($res['ok'])) {
            emailFail((int)$res['code'], (string)$res['error']);
        }
        emailReply($res['row'], ['comment' => null]);
    }
    if ($action === 'submit') {
        $hasStat = true;
        $status  = 'pending';
    }

    // ---- status / comment ----
    if ($hasStat && !$isAdminSession) {
        // A client cannot re-decide a live email (409, like a scheduled post) or one that is not waiting on them —
        // except Approve instead on one it sent back ("Sent back" sheet); anything else there is a comment.
        // (transitions-lib.php transitionClientDecisionError())
        $why = transitionClientDecisionError('email', $prevStat, (string)$status, (bool)$prevLive);
        if ($why) {
            emailFail((int)$why['code'], (string)$why['error']);
        }
    }
    // A draft goes to the client before anyone decides on it (posts' rule, status.php): Send for review first.
    if ($hasStat && $prevStat === 'draft' && in_array($status, ['approved', 'denied'], true)) {
        emailFail(409, 'Send this draft for review first');
    }
    if ($hasStat && $isAdminSession && $prevLive && $status !== $prevStat) {
        // Status changes on a live row would silently hide it from the client's Live list; unmark first.
        emailFail(409, 'Unmark live before changing the status');
    }

    $pdo->beginTransaction();
    $batchId = newBatchId();

    if ($hasStat && $status !== $prevStat) {
        $pdo->prepare("UPDATE emails SET status = ? WHERE id = ?")->execute([$status, $id]);
        if ($status === 'approved') {
            $logAction = 'approved';  $summary = "Email {$label} approved" . ($prevStat === 'denied' && $actor === 'client' ? ' instead (it was sent back)' : '');  $detail = null;
        } elseif ($status === 'denied') {
            $logAction = 'denied';    $summary = "Changes requested on email {$label}";    $detail = null;
        } elseif ($status === 'pending') {
            $logAction = $prevStat === 'draft' ? 'submitted' : 'reset_pending';
            $summary   = "Email {$label} sent for review";
            $detail    = null;
        } else {
            $logAction = 'edited_status'; $summary = "Status edited on {$label}"; $detail = $prevStat . ' → ' . $status;
        }
        logEmailActivity($pdo, $actor, $logAction, $id, $summary, $detail, $batchId, $companyId);
        $email['status'] = $status;
    }
    if ($hasCmt && $comment !== null && $comment !== '') {
        logEmailActivity($pdo, $actor, 'commented', $id, "Comment on {$label}", $comment, $batchId, $companyId);
    }

    $pdo->commit();
    emailReply($email, ['comment' => $hasCmt ? $comment : null]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('email-status failed: ' . $e->getMessage());
    emailFail(500, 'Database error');
}
