<?php
/**
 * Page status / comment / live / delete endpoint (mirrors email-status.php; scratchpad pages-design.md).
 *
 * POST only. Fields:
 *   id                      pages.id (required)
 *   status                  draft|pending|approved|denied      — decide / route
 *   comment                 string, max 2000 chars            — a thread message (deny note when sent with status=denied)
 *   action=submit           draft → pending ("Send for review")            admin only
 *   action=toggle_live&to=0|1   live flag (to=1 requires status=approved → 409)   admin only
 *   action=delete_page      remove the row, its page_files rows and — for an upload page —
 *                           its media/pages/<client>/<slug>/ folder (contained; pages-lib.php)   admin only
 *   actor                   admin may act as client|admin; a client seat is always 'client' (actorFromPost)
 *   client                  tenant slug (App.post appends it) — must own the page's company (403)
 *
 * Client seat: approve / request changes (note ≥ 3 chars) on a pending, non-live page, and comment.
 * Everything else — draft/pending, live toggles, delete, re-deciding approved/denied/live rows —
 * needs the admin session (403; live rows answer 409 for a client decision).
 *
 * Reply: {ok, id, status, live, key, label} · errors {ok:false, error} with 400/403/404/405/409/422.
 * Every mutation logs through logPageActivity() (approved, denied, reset_pending, submitted,
 * edited_status, commented, marked_live, unmarked_live, deleted); a deny note shares the deny's batch.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

function pageFail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function pageReply(array $page, array $extra = []): void {
    $key = pageStatusKey($page);
    echo json_encode(array_merge([
        'ok'     => true,
        'id'     => (int)$page['id'],
        'status' => (string)$page['status'],
        'live'   => !empty($page['live']) ? 1 : 0,
        'key'    => $key,
        'label'  => pageStatusLabelForKey($key),
    ], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pageFail(405, 'Method not allowed');
}
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)

if (!hasPagesTable($pdo)) {
    pageFail(404, 'Pages are not set up yet');
}

$isAdminSession = isAdmin();
$action  = (string)($_POST['action'] ?? '');
$id      = (int)($_POST['id'] ?? 0);
$hasStat = array_key_exists('status', $_POST);
$hasCmt  = array_key_exists('comment', $_POST);
$status  = $hasStat ? strtolower(trim((string)$_POST['status'])) : null;
$comment = $hasCmt ? trim((string)$_POST['comment']) : null;

if ($id <= 0) {
    pageFail(400, 'Invalid id');
}
if (!in_array($action, ['', 'submit', 'toggle_live', 'delete_page'], true)) {
    pageFail(400, 'Unknown action');
}
// ---- Role gate (server-side): Joust-only verbs ----
if (in_array($action, ['submit', 'toggle_live', 'delete_page'], true) && !$isAdminSession) {
    pageFail(403, 'Admin sign-in required');
}
if ($action === '' && !$hasStat && !$hasCmt) {
    pageFail(400, 'Nothing to update');
}
if ($hasStat && !in_array($status, ['draft', 'pending', 'approved', 'denied'], true)) {
    pageFail(400, 'Invalid status');
}
// Client verbs are Approve / Needs changes / Comment only: routing to draft or back to review is Joust's.
if ($hasStat && in_array($status, ['draft', 'pending'], true) && !$isAdminSession) {
    pageFail(403, 'Admin sign-in required');
}
// Requesting changes needs a reason — a note of at least 3 characters — for every seat (same copy as status.php).
if ($hasStat && $status === 'denied' && mb_strlen(trim((string)($_POST['comment'] ?? '')), 'UTF-8') < 3) {
    pageFail(422, 'Please add a short note (at least 3 characters) explaining what should change.');
}
if ($hasCmt) {
    if (strlen((string)$comment) > 2000) {
        pageFail(400, 'Comment too long (max 2000 chars)');
    }
    if ($comment === '') { $hasCmt = false; $comment = null; }
    if (!$hasCmt && !$hasStat && $action === '') {
        pageFail(400, 'Nothing to update');
    }
}

// ---- Load + tenant check (company always comes from the row, never the form) ----
$page = pageById($pdo, $id);
if (!$page) {
    pageFail(404, 'Page not found');
}
if (!clientOwnsCompany($pdo, (int)$page['company_id'])) {
    pageFail(403, 'This page belongs to another client');
}
// Clients only ever see live / pending / approved rows — a hidden row is "not found" for them.
if (!$isAdminSession && empty($page['live']) && !in_array((string)$page['status'], ['pending', 'approved'], true)) {
    pageFail(404, 'Page not found');
}

$companyId = (int)$page['company_id'];
$actor     = actorFromPost();
$label     = pageDisplayLabel($page);
$prevStat  = (string)$page['status'];
$prevLive  = !empty($page['live']) ? 1 : 0;

try {
    // ---- toggle_live (admin) ----
    if ($action === 'toggle_live') {
        $to = ((string)($_POST['to'] ?? '1')) === '1' ? 1 : 0;
        if ($to === 1 && $prevStat !== 'approved') {
            pageFail(409, 'Only an approved page can be marked live');
        }
        if ($to !== $prevLive) {
            $pdo->prepare($to === 1
                ? "UPDATE pages SET live = 1, live_at = NOW() WHERE id = ?"
                : "UPDATE pages SET live = 0, live_at = NULL WHERE id = ?")->execute([$id]);
            logPageActivity($pdo, $actor, $to === 1 ? 'marked_live' : 'unmarked_live', $id,
                "Page {$label} " . ($to === 1 ? 'marked live' : 'unmarked live'), null, null, $companyId);
        }
        $page['live']    = $to;
        $page['live_at'] = $to === 1 ? date('Y-m-d H:i:s') : null;
        pageReply($page, ['live_at' => $page['live_at']]);
    }

    // ---- delete_page (admin): folder (contained) + page_files + row ----
    if ($action === 'delete_page') {
        $pdo->beginTransaction();
        $res = deletePage($pdo, $page, $actor);
        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $id, 'deleted' => 1, 'files' => (int)$res['files']]);
        exit;
    }

    // ---- submit (admin): draft → pending ----
    if ($action === 'submit') {
        $hasStat = true;
        $status  = 'pending';
    }

    // ---- status / comment ----
    if ($hasStat && !$isAdminSession) {
        // A client cannot re-decide a live page (409, like a scheduled post) or one that is not waiting on them.
        if ($prevLive) {
            pageFail(409, 'This page is already live — add a comment instead');
        }
        if ($prevStat !== 'pending') {
            pageFail(403, 'This page can no longer be changed here — add a comment instead');
        }
    }
    if ($hasStat && $isAdminSession && $prevLive && $status !== $prevStat) {
        // Status changes on a live row would silently hide it from the client's Live list; unmark first.
        pageFail(409, 'Unmark live before changing the status');
    }

    $pdo->beginTransaction();
    $batchId = newBatchId();

    if ($hasStat && $status !== $prevStat) {
        $pdo->prepare("UPDATE pages SET status = ? WHERE id = ?")->execute([$status, $id]);
        if ($status === 'approved') {
            $logAction = 'approved';  $summary = "Page {$label} approved";  $detail = null;
        } elseif ($status === 'denied') {
            $logAction = 'denied';    $summary = "Page {$label} denied";    $detail = null;
        } elseif ($status === 'pending') {
            $logAction = $prevStat === 'draft' ? 'submitted' : 'reset_pending';
            $summary   = "Page {$label} " . ($prevStat === 'draft' ? 'sent for review' : 'reset to review');
            $detail    = null;
        } else {
            $logAction = 'edited_status'; $summary = "Status edited on {$label}"; $detail = $prevStat . ' → ' . $status;
        }
        logPageActivity($pdo, $actor, $logAction, $id, $summary, $detail, $batchId, $companyId);
        $page['status'] = $status;
    }
    if ($hasCmt && $comment !== null && $comment !== '') {
        logPageActivity($pdo, $actor, 'commented', $id, "Comment on {$label}", $comment, $batchId, $companyId);
    }

    $pdo->commit();
    pageReply($page, ['comment' => $hasCmt ? $comment : null]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('page-status failed: ' . $e->getMessage());
    pageFail(500, 'Database error');
}
