<?php
/**
 * Joust-side status transitions, shared by the portal's status endpoints and the Slack buttons
 * (slack-actions.php), so both follow exactly the same rules and log exactly the same activity rows:
 *
 *   transitionPostSubmit($pdo, $postId, $actor)               draft → pending ("Send for review") — status.php action=submit
 *   transitionPostScheduled($pdo, $postId, $to, $actor)        posted flag ("Mark scheduled")       — status.php action=toggle_posted
 *   transitionMailLive($pdo, 'email'|'page', $row, $to, $actor)  live flag ("Mark live")           — email-status.php / page-status.php action=toggle_live
 *   transitionMailSubmit($pdo, 'email'|'page', $row, $actor)     → pending ("Send for review")      — email-status.php / page-status.php action=submit (no comment)
 *
 * The client seat's own decisions (status.php / email-status.php / page-status.php, before they write):
 *   transitionClientDecisionError($noun, $from, $to, $locked)   null = allowed, else ['code', 'error']. A client decides
 *     an item waiting on it (To Review → Approved / Needs changes) and may approve instead one it sent back (Needs
 *     changes → Approved: its "Sent back" list, sentback-lib.php). Scheduled / live = locked (409 for emails / pages,
 *     403 for posts, as before); anything else — re-deciding an approved item, re-sending a sent-back one — is a comment.
 *
 * Each returns ['ok' => bool, 'code' => HTTP status, 'error' => message (on failure), …result fields]. Role checks
 * (admin session / mapped Slack user) are the caller's; every rule about the ROW lives here. Loaded by helpers.php
 * (function_exists-guarded, no output, no work at load).
 */

if (!function_exists('transitionFail')) {
    function transitionFail(int $code, string $error, array $extra = []): array {
        return ['ok' => false, 'code' => $code, 'error' => $error] + $extra;
    }
}

if (!function_exists('transitionPostSubmit')) {
    /** draft → pending: 404 unknown post · 409 not a draft · 422 empty caption. Logs 'submitted'. */
    function transitionPostSubmit(PDO $pdo, int $postId, string $actor = 'admin'): array {
        if ($postId <= 0) return transitionFail(400, 'Invalid id');
        try {
            $pdo->beginTransaction();
            $nameSel = hasPostsNameColumn($pdo) ? 'name' : "'' AS name";
            $st = $pdo->prepare("SELECT company_id, status, caption, {$nameSel} FROM posts WHERE id = ? FOR UPDATE");
            $st->execute([$postId]);
            $row = $st->fetch();
            if (!$row) { $pdo->rollBack(); return transitionFail(404, 'Post not found'); }
            if ($row['status'] !== 'draft') {
                $pdo->rollBack();
                return transitionFail(409, 'Only a draft can be sent for review', ['status' => $row['status']]);
            }
            if (trim((string)$row['caption']) === '') {
                $pdo->rollBack();
                return transitionFail(422, 'Add a caption first', ['field' => 'caption']);
            }
            $pdo->prepare("UPDATE posts SET status = 'pending' WHERE id = ?")->execute([$postId]);
            $label = postDisplayLabel(['name' => $row['name'] ?? '', 'caption' => $row['caption'] ?? '', 'id' => $postId]);
            logActivity($pdo, (int)$row['company_id'], 'post', $postId, 'submitted', $actor, "{$label} sent for review");
            $pdo->commit();
            return ['ok' => true, 'code' => 200, 'id' => $postId, 'status' => 'pending'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('transitionPostSubmit: ' . $e->getMessage());
            return transitionFail(500, 'Update failed');
        }
    }
}

if (!function_exists('transitionPostScheduled')) {
    /** posted flag: to=1 only on an approved post (409). Logs 'posted' / 'unposted'. */
    function transitionPostScheduled(PDO $pdo, int $postId, int $to, string $actor = 'admin'): array {
        if ($postId <= 0) return transitionFail(400, 'Invalid id');
        if (!hasPostedColumn($pdo)) return transitionFail(500, 'posts.posted column missing — run migrate.php');
        $to = $to === 1 ? 1 : 0;
        try {
            // Spec §4.3: only an approved post can be marked as scheduled (a stale tab must not schedule a post
            // the client has since denied / reset).
            if ($to === 1) {
                $stStmt = $pdo->prepare("SELECT status FROM posts WHERE id = ?");
                $stStmt->execute([$postId]);
                $curStatus = $stStmt->fetchColumn();
                if ($curStatus !== false && $curStatus !== 'approved') {
                    return transitionFail(409, 'Only an approved post can be marked as scheduled');
                }
            }
            $pdo->prepare($to === 1
                ? "UPDATE posts SET posted = 1, posted_at = NOW() WHERE id = ?"
                : "UPDATE posts SET posted = 0, posted_at = NULL WHERE id = ?")->execute([$postId]);
            $coStmt = $pdo->prepare("SELECT company_id, posted_at FROM posts WHERE id = ?");
            $coStmt->execute([$postId]);
            $row = $coStmt->fetch();
            $coId = (int)($row['company_id'] ?? 0);
            if ($coId > 0) {
                logActivity($pdo, $coId, 'post', $postId, $to === 1 ? 'posted' : 'unposted', $actor,
                    $to === 1 ? "Marked post #{$postId} as posted" : "Unmarked post #{$postId}");
            }
            return ['ok' => true, 'code' => 200, 'id' => $postId, 'posted' => $to, 'posted_at' => $row['posted_at'] ?? null];
        } catch (Throwable $e) {
            error_log('transitionPostScheduled: ' . $e->getMessage());
            return transitionFail(500, 'Update failed');
        }
    }
}

if (!function_exists('transitionMailLabel')) {
    /** "W3 · Welcome" / "Pricing page" — the same label the endpoints log with. */
    function transitionMailLabel(string $kind, array $row): string {
        if ($kind === 'page') {
            if (!function_exists('pageDisplayLabel') && is_file(__DIR__ . '/pages-lib.php')) require_once __DIR__ . '/pages-lib.php';
            return function_exists('pageDisplayLabel') ? pageDisplayLabel($row) : ('#' . (int)($row['id'] ?? 0));
        }
        return function_exists('emailDisplayLabel') ? emailDisplayLabel($row) : ('#' . (int)($row['id'] ?? 0));
    }
}

if (!function_exists('transitionMailLog')) {
    function transitionMailLog(PDO $pdo, string $kind, string $actor, string $action, array $row, string $summary, ?string $batchId = null): void {
        $id = (int)$row['id']; $cid = (int)$row['company_id'];
        if ($kind === 'page') {
            if (function_exists('logPageActivity')) { logPageActivity($pdo, $actor, $action, $id, $summary, null, $batchId, $cid); return; }
            logActivity($pdo, $cid, 'page', $id, $action, $actor, $summary, null, $batchId);
            return;
        }
        if (function_exists('logEmailActivity')) { logEmailActivity($pdo, $actor, $action, $id, $summary, null, $batchId, $cid); return; }
        logActivity($pdo, $cid, 'email', $id, $action, $actor, $summary, null, $batchId);
    }
}

if (!function_exists('transitionMailLive')) {
    /** Live flag on an email / page row: to=1 only when approved (409). Logs 'marked_live' / 'unmarked_live'
     *  (only when the flag actually changes). Returns the row with live / live_at updated. */
    function transitionMailLive(PDO $pdo, string $kind, array $row, int $to, string $actor = 'admin'): array {
        $kind  = $kind === 'page' ? 'page' : 'email';
        $table = $kind === 'page' ? 'pages' : 'emails';
        $noun  = $kind === 'page' ? 'page' : 'email';
        $to    = $to === 1 ? 1 : 0;
        $prevLive = !empty($row['live']) ? 1 : 0;
        if ($to === 1 && (string)($row['status'] ?? '') !== 'approved') {
            return transitionFail(409, 'Only an approved ' . $noun . ' can be marked live');
        }
        try {
            if ($to !== $prevLive) {
                $pdo->prepare($to === 1
                    ? "UPDATE {$table} SET live = 1, live_at = NOW() WHERE id = ?"
                    : "UPDATE {$table} SET live = 0, live_at = NULL WHERE id = ?")->execute([(int)$row['id']]);
                $label = transitionMailLabel($kind, $row);
                transitionMailLog($pdo, $kind, $actor, $to === 1 ? 'marked_live' : 'unmarked_live', $row,
                    ucfirst($noun) . " {$label} " . ($to === 1 ? 'marked live' : 'unmarked live'));
            }
        } catch (Throwable $e) {
            error_log('transitionMailLive: ' . $e->getMessage());
            return transitionFail(500, 'Database error');
        }
        $row['live']    = $to;
        $row['live_at'] = $to === 1 ? date('Y-m-d H:i:s') : null;
        return ['ok' => true, 'code' => 200, 'row' => $row];
    }
}

if (!function_exists('transitionMailSubmit')) {
    /** Send for review (→ pending) on an email / page row, Joust's generic route: a live row must be unmarked first
     *  (409); a row already To Review is a no-op. Logs 'submitted' (from draft) or 'reset_pending'. */
    function transitionMailSubmit(PDO $pdo, string $kind, array $row, string $actor = 'admin'): array {
        $kind  = $kind === 'page' ? 'page' : 'email';
        $table = $kind === 'page' ? 'pages' : 'emails';
        $prev  = (string)($row['status'] ?? '');
        if (!empty($row['live']) && $prev !== 'pending') {
            return transitionFail(409, 'Unmark live before changing the status');
        }
        if ($prev === 'pending') return ['ok' => true, 'code' => 200, 'row' => $row];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE {$table} SET status = 'pending' WHERE id = ?")->execute([(int)$row['id']]);
            $label = transitionMailLabel($kind, $row);
            transitionMailLog($pdo, $kind, $actor, $prev === 'draft' ? 'submitted' : 'reset_pending', $row,
                ucfirst($kind) . " {$label} sent for review", newBatchId());
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('transitionMailSubmit: ' . $e->getMessage());
            return transitionFail(500, 'Database error');
        }
        $row['status'] = 'pending';
        return ['ok' => true, 'code' => 200, 'row' => $row];
    }
}

if (!function_exists('transitionClientDecisionError')) {
    /** The client seat's decision on a post / email / page: null when allowed, else ['code' => HTTP, 'error' => message]. */
    function transitionClientDecisionError(string $noun, string $from, string $to, bool $locked): ?array {
        $noun = in_array($noun, ['post', 'email', 'page'], true) ? $noun : 'post';
        if ($locked) {
            return $noun === 'post'
                ? ['code' => 403, 'error' => 'This post can no longer be changed here — add a comment instead']
                : ['code' => 409, 'error' => 'This ' . $noun . ' is already live — add a comment instead'];
        }
        if ($from === 'pending' && in_array($to, ['approved', 'denied'], true)) return null;   // its review
        if ($from === 'denied' && $to === 'approved') return null;                             // Sent back → Approve instead
        if ($noun === 'post' && $from !== 'denied') return null;   // posts: an approved one may still be re-decided (as before)
        return ['code' => 403, 'error' => 'This ' . $noun . ' can no longer be changed here — add a comment instead'];
    }
}
