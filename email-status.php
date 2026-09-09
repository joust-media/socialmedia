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
 * Client seat: approve / request changes (note ≥ 3 chars) on a pending, non-live email, and comment.
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

// ---- Load + tenant check (company always comes from the row, never the form) ----
$email = emailById($pdo, $id);
if (!$email) {
    emailFail(404, 'Email not found');
}
if (!clientOwnsCompany($pdo, (int)$email['company_id'])) {
    emailFail(403, 'This email belongs to another client');
}
// Clients only ever see live / pending / approved rows — a hidden row is "not found" for them.
if (!$isAdminSession && empty($email['live']) && !in_array((string)$email['status'], ['pending', 'approved'], true)) {
    emailFail(404, 'Email not found');
}

$companyId = (int)$email['company_id'];
$actor     = actorFromPost();
$label     = emailDisplayLabel($email);
$prevStat  = (string)$email['status'];
$prevLive  = !empty($email['live']) ? 1 : 0;

try {
    // ---- toggle_live (admin) ----
    if ($action === 'toggle_live') {
        $to = ((string)($_POST['to'] ?? '1')) === '1' ? 1 : 0;
        if ($to === 1 && $prevStat !== 'approved') {
            emailFail(409, 'Only an approved email can be marked live');
        }
        if ($to !== $prevLive) {
            $pdo->prepare($to === 1
                ? "UPDATE emails SET live = 1, live_at = NOW() WHERE id = ?"
                : "UPDATE emails SET live = 0, live_at = NULL WHERE id = ?")->execute([$id]);
            logEmailActivity($pdo, $actor, $to === 1 ? 'marked_live' : 'unmarked_live', $id,
                "Email {$label} " . ($to === 1 ? 'marked live' : 'unmarked live'), null, null, $companyId);
        }
        $email['live']    = $to;
        $email['live_at'] = $to === 1 ? date('Y-m-d H:i:s') : null;
        emailReply($email, ['live_at' => $email['live_at']]);
    }

    // ---- delete_email (admin) ----
    if ($action === 'delete_email') {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM email_group_map WHERE email_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM emails WHERE id = ?")->execute([$id]);
        logEmailActivity($pdo, $actor, 'deleted', $id, "Email {$label} deleted", null, null, $companyId);
        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $id, 'deleted' => 1]);
        exit;
    }

    // ---- submit (admin): draft → pending ----
    if ($action === 'submit') {
        $hasStat = true;
        $status  = 'pending';
    }

    // ---- status / comment ----
    if ($hasStat && !$isAdminSession) {
        // A client cannot re-decide a live email (409, like a scheduled post) or one that is not waiting on them.
        if ($prevLive) {
            emailFail(409, 'This email is already live — add a comment instead');
        }
        if ($prevStat !== 'pending') {
            emailFail(403, 'This email can no longer be changed here — add a comment instead');
        }
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
            $logAction = 'approved';  $summary = "Email {$label} approved";  $detail = null;
        } elseif ($status === 'denied') {
            $logAction = 'denied';    $summary = "Email {$label} denied";    $detail = null;
        } elseif ($status === 'pending') {
            $logAction = $prevStat === 'draft' ? 'submitted' : 'reset_pending';
            $summary   = "Email {$label} " . ($prevStat === 'draft' ? 'sent for review' : 'reset to review');
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
