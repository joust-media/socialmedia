<?php
/**
 * Status / comment / date / caption update endpoint.
 * Accepts POST: id (int), and optionally:
 *   - status (pending|approved|denied; admin also draft — "move back to drafts", once migrate.php step 35 ran)
 *   - comment (string, max 2000 chars; '' clears it)
 *   - internal=1 (admin, with comment only): an internal note — Joust-only (activity_log.internal = 1), never on
 *     the post's client_comment, never in a client email; posted to the item's Slack thread marked internal
 *   - scheduled_date (datetime string, parseable by strtotime)
 *   - caption (string, max 10000 chars)
 *   - hashtags (string, max 2000 chars)
 *   - post_type (post|story|reel; only when the migration-gated column exists)
 * At least one must be provided.
 * Role: status + comment are open; caption + hashtags are open to the client seat
 * for its own company's posts while the post is not yet Scheduled (posted = 1 → 409);
 * scheduled_date / post_type / toggle_posted / delete_post need the admin session (403).
 *
 * Drafts (posts.status = 'draft'): Joust's work in progress. The client seat can never read or
 * touch one (every request on a draft answers 404 "Post not found", as if it did not exist).
 * The admin edits it freely; approve / deny on a draft is refused (409) — it goes to the client
 * first with:
 *   action=submit, id   draft → pending ("Send for review"); 422 "Add a caption first" when the
 *                       caption is empty; 409 when the post is not a draft. Logs 'submitted'.
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
// Clients may change `status`, `comment`, `caption` and `hashtags` (the last two
// only on their own company's posts and only until the post is Scheduled — see
// the posted check after the FOR UPDATE read). Everything else Joust does —
// toggle_posted, delete_post, scheduled_date / post_type edits — requires the
// admin session (auth.php via helpers.php).
$isAdminSession = function_exists('currentAdmin') && currentAdmin() !== null;
$adminOnlyFields = ['scheduled_date', 'post_type'];
$needsAdmin = in_array($action, ['toggle_posted', 'delete_post', 'submit'], true);
foreach ($adminOnlyFields as $f) {
    if (array_key_exists($f, $_POST)) { $needsAdmin = true; }
}
if ($needsAdmin && !$isAdminSession) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin sign-in required']);
    exit;
}

// ---- Toggle posted flag ---- (rules + activity row: transitions-lib.php, shared with the Slack buttons)
if ($action === 'toggle_posted') {
    $res = transitionPostScheduled($pdo, (int)($_POST['id'] ?? 0), ((string)($_POST['to'] ?? '1')) === '1' ? 1 : 0, actorFromPost());
    if (empty($res['ok'])) {
        http_response_code((int)$res['code']);
        echo json_encode(['ok' => false, 'error' => $res['error']]);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $res['id'], 'posted' => $res['posted'], 'posted_at' => $res['posted_at']]);
    exit;
}

// ---- Send a draft for review (draft → pending) ---- (transitions-lib.php, shared with the Slack buttons)
if ($action === 'submit') {
    $res = transitionPostSubmit($pdo, (int)($_POST['id'] ?? 0), 'admin');
    if (empty($res['ok'])) {
        http_response_code((int)$res['code']);
        $out = ['ok' => false, 'error' => $res['error']];
        if (isset($res['status'])) $out['status'] = $res['status'];
        if (isset($res['field']))  $out['field']  = $res['field'];
        echo json_encode($out);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $res['id'], 'status' => 'pending']);
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
            if ($path !== null) { if (function_exists('previewDelete')) previewDelete($path); @unlink($path); }
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
// ---- Internal note (admin, comment only): Joust-only — never shown to the client, no client email, Slack marks it ----
if (array_key_exists('internal', $_POST) && (string)$_POST['internal'] !== '' && (string)$_POST['internal'] !== '0') {
    if (!$isAdminSession) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin sign-in required']); exit; }
    $note = trim((string)($comment ?? ''));
    if (!$hasCmt || $note === '' || $hasStat || $hasDate || $hasCap || $hasTag || $hasType) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'An internal note is a message only']); exit;
    }
    if (strlen($note) > 2000) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Comment too long (max 2000 chars)']); exit; }
    $nameSel = hasPostsNameColumn($pdo) ? 'name' : "'' AS name";
    $st = $pdo->prepare("SELECT id, company_id, caption, {$nameSel} FROM posts WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Post not found']); exit; }
    $label = postDisplayLabel(['name' => $row['name'] ?? '', 'caption' => $row['caption'] ?? '', 'id' => $id]);
    activityWithContext(['internal' => 1], static function () use ($pdo, $row, $id, $label, $note) {
        logActivity($pdo, (int)$row['company_id'], 'post', $id, 'commented', 'admin', "Internal note on {$label}", $note, newBatchId());
    });
    echo json_encode(['ok' => true, 'id' => $id, 'comment' => $note, 'internal' => true, 'comment_id' => $GLOBALS['__lastCommentId'] ?? null]);
    exit;
}
$allowedStatuses = ['pending', 'approved', 'denied'];
if ($isAdminSession && postsHaveDraft($pdo)) { $allowedStatuses[] = 'draft'; }   // "Move back to drafts" is Joust's
if ($hasStat && !in_array($status, $allowedStatuses, true)) {
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
    // Drafts do not exist for the client seat (same answer as a missing post — nothing leaks).
    if (!$isAdminSession && $prev['status'] === 'draft') {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Post not found']);
        exit;
    }
    // A draft goes to the client before anyone decides on it: Send for review (action=submit) first.
    if ($hasStat && $prev['status'] === 'draft' && in_array($status, ['approved', 'denied'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Send this draft for review first']);
        exit;
    }
    // draft → pending through the generic path follows the Send for review rule: a caption first.
    if ($hasStat && $status === 'pending' && $prev['status'] === 'draft'
        && trim((string)($hasCap ? $caption : $prev['caption'])) === '') {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Add a caption first', 'field' => 'caption']);
        exit;
    }
    // Back to drafts only while it is not Scheduled (the client may already expect it).
    if ($hasStat && $status === 'draft' && !empty($prev['posted'])) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Unmark scheduled first']);
        exit;
    }
    // Tenant scope: a client seat may only act on its own company's posts (admin bypasses).
    if (!clientOwnsCompany($pdo, (int)$prev['company_id'])) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'This post belongs to another client']);
        exit;
    }
    // A client cannot re-decide a post that is already scheduled (spec §2). One it sent back (Needs changes) it may
    // only approve instead — the "Sent back" sheet's Approve instead (sentback-lib.php); anything else is a comment.
    // The rule lives in transitions-lib.php (transitionClientDecisionError()).
    if ($hasStat && !$isAdminSession && ($why = transitionClientDecisionError('post', (string)$prev['status'], (string)$status, !empty($prev['posted'])))) {
        $pdo->rollBack();
        http_response_code((int)$why['code']);
        echo json_encode(['ok' => false, 'error' => $why['error']]);
        exit;
    }
    // Caption / hashtags are frozen once the post is Scheduled (posted = 1) — for both seats.
    // Joust unmarks first; the client is told the copy is locked.
    if (($hasCap || $hasTag) && !empty($prev['posted'])) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => $isAdminSession ? 'Unmark scheduled first' : 'This post is already scheduled']);
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
                : (($status === 'draft')   ? 'moved_to_draft'
                : ($prev['status'] === 'draft' ? 'submitted' : 'reset_pending')));
        logActivity($pdo, $companyId, 'post', $id, $action, $actor,
            "{$postLabel} " . actionLabel($action) . ($action === 'approved' && $actor === 'client' && $prev['status'] === 'denied' ? ' instead (it was sent back)' : ''),
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
    // Caption / hashtags: "<Kenda|Joust> edited the caption on <post>" with a compact
    // old → new diff (each side collapsed to one line and capped at 300 chars; the full
    // new text lives on the post row). The editor's name is returned so the sheet can
    // show "Edited by Kenda · just now" without a reload.
    $capChanged = $hasCap && (string)$prev['caption'] !== (string)$caption;
    $tagChanged = $hasTag && (string)$prev['hashtags'] !== (string)$hashtags;
    $editorName = null;
    if ($capChanged || $tagChanged) {
        $editorName = statusEditorName($pdo, $actor, $companyId);
    }
    if ($capChanged) {
        logActivity($pdo, $companyId, 'post', $id, 'edited_caption', $actor,
            "{$editorName} edited the caption on {$postLabel}",
            statusDiffText((string)$prev['caption'], (string)$caption),
            $batchId);
    }
    if ($tagChanged) {
        logActivity($pdo, $companyId, 'post', $id, 'edited_hashtags', $actor,
            "{$editorName} edited the hashtags on {$postLabel}",
            statusDiffText((string)$prev['hashtags'], (string)$hashtags),
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
        'comment_id'     => $GLOBALS['__lastCommentId'] ?? null,   // the new comment (comment-edit.php can change it)
        'scheduled_date' => $hasDate ? $dateFormatted  : null,
        'caption'        => $hasCap  ? $caption        : null,
        'hashtags'       => $hasTag  ? $hashtags       : null,
        'post_type'      => $hasType ? $postType       : null,
        // Who the sheet should credit for the copy change: the company name for the client
        // seat, null for Joust (the "Edited by …" line only ever names the client).
        'edited_by'      => ($capChanged || $tagChanged) && $actor === 'client' ? $editorName : null,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}

/** Display name of whoever is editing copy: the company for the client seat, "Joust" for admin. */
function statusEditorName(PDO $pdo, string $actor, int $companyId): string
{
    if ($actor !== 'client') return 'Joust';
    try {
        $st = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $name = $st->fetchColumn();
    } catch (Throwable $e) {
        $name = false;
    }
    return is_string($name) && trim($name) !== '' ? trim($name) : 'Client';
}

/** Compact one-line "old → new" for the activity detail; each side capped at $max chars. */
function statusDiffText(string $old, string $new, int $max = 300): string
{
    $side = static function (string $s) use ($max): string {
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        if ($s === '') return '(empty)';
        return mb_strlen($s, 'UTF-8') > $max ? rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…' : $s;
    };
    return $side($old) . ' → ' . $side($new);
}
