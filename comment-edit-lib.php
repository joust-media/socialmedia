<?php
/**
 * Comment editing — clients edit / delete their own comments (any time, even after Joust replied), Joust edits /
 * deletes any comment (client comments and internal notes included). Loaded by helpers.php; function definitions only.
 *
 * ── Data (migrate.php 53; commentEditReady() gates everything — before the migration nothing can be edited) ──────
 *   activity_log.edited_at / deleted_at   a 'commented' row is edited IN PLACE (detail = the current text), so every
 *                                         reader — threads, feeds, the Morning summary, Inbox snippets, Home "Latest
 *                                         notes", redo packs, client emails — shows the current text with no change.
 *                                         A deleted comment keeps its row (thread order) with detail = '' + deleted_at:
 *                                         every reader that skips empty comments hides it; threads draw "Comment deleted".
 *   comment_revisions                     one row per edit / delete: old_detail → new_detail, who (actor + admin_users id
 *                                         / client_contacts id), when. The original text is never lost.
 *   comment_slack                         per comment, the Slack message carrying it (channel + ts + the outbox row),
 *                                         written when the message is delivered (notifyDeliver()).
 *
 * ── Rules (commentEditApply()) ────────────────────────────────────────────────────────────────────────────────────
 *   Admin seat: any 'commented' row. Client seat: only rows of ITS company with actor 'client' that are not internal —
 *   any contact of the company may edit (the label names who did); never Joust's, never another client's (403).
 *   Text: trimmed, non-empty, ≤ 2000 bytes (the composer's limit); a Needs-changes note keeps its 3-character minimum.
 *   "[Slide N] " prefixes are kept unless the edit changes the slide (slide=0 removes it).
 *
 * ── Side effects ──────────────────────────────────────────────────────────────────────────────────────────────────
 *   A 'comment_edited' / 'comment_deleted' activity row, internal (never in a client feed), batched per comment (repeat
 *   edits collapse into one feed line). A CLIENT edit counts as new for Joust's unread dot (trackingUnreadSql()) but is
 *   no 'commented' row: escalation timers, the waiting age and client emails are untouched. posts / tire_images
 *   client_comment follow the edit when they held the old text. Slack: outbox 'comment_edit' → chat.update of the
 *   comment's own message (re-rendered with " (edited)", or "_comment deleted_"); a comment with no stored message
 *   gets a short threaded note in the item's thread instead.
 */

if (!defined('COMMENT_MAX_BYTES')) define('COMMENT_MAX_BYTES', 2000);

if (!function_exists('commentEditReady')) {
    /** migrate.php 53 ran: activity_log.edited_at + deleted_at and comment_revisions exist. Cached per request. */
    function commentEditReady(?PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!$pdo) return false;
        try {
            $c = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'activity_log' AND COLUMN_NAME IN ('edited_at','deleted_at')")->fetchColumn();
            $t = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'comment_revisions'")->fetchColumn();
            return $ready = ($c === 2 && $t === 1);
        } catch (Throwable $e) {
            return $ready = false;
        }
    }
}

if (!function_exists('commentSlackReady')) {
    /** comment_slack exists (migrate.php 53). Cached per request. */
    function commentSlackReady(?PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!$pdo) return false;
        try {
            return $ready = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'comment_slack'")->fetchColumn() === 1;
        } catch (Throwable $e) {
            return $ready = false;
        }
    }
}

if (!function_exists('commentSelectCols')) {
    /** ', <a>.id, <a>.edited_at, <a>.deleted_at' for a thread reader's SELECT list (NULL stand-ins before migrate 53). */
    function commentSelectCols(?PDO $pdo, string $alias = ''): string {
        $a = $alias !== '' ? $alias . '.' : '';
        return ", {$a}id AS comment_id" . (commentEditReady($pdo) ? ", {$a}edited_at, {$a}deleted_at" : ', NULL AS edited_at, NULL AS deleted_at');
    }
}

if (!function_exists('commentThreadWhere')) {
    /** The thread readers' "has a message" condition: a non-empty comment, or a deleted one (drawn as "Comment deleted"
     *  in place). Wrapped in parentheses; append after AND. */
    function commentThreadWhere(?PDO $pdo, string $alias = ''): string {
        $a = $alias !== '' ? $alias . '.' : '';
        $base = "({$a}detail IS NOT NULL AND {$a}detail <> '')";
        return commentEditReady($pdo) ? "({$base} OR {$a}deleted_at IS NOT NULL)" : $base;
    }
}

if (!function_exists('commentIsDeleted')) {
    function commentIsDeleted(array $row): bool { return !empty($row['deleted_at']); }
}

if (!function_exists('commentsLive')) {
    /** A thread's rows without the deleted ones (counts, "latest note" pickers, queue facts). */
    function commentsLive(array $rows): array {
        return array_values(array_filter($rows, static function ($r) { return is_array($r) && empty($r['deleted_at']); }));
    }
}

if (!function_exists('commentRowId')) {
    /** The activity_log id of a thread row (commentSelectCols() names it comment_id; feed rows carry id). */
    function commentRowId(array $row): int { return (int)($row['comment_id'] ?? ($row['id'] ?? 0)); }
}

if (!function_exists('commentCanEdit')) {
    /** May this viewer edit / delete this thread row? Admin: any comment. Client: its own company's client comments
     *  (the thread only ever shows its own company's), never internal ones, never a deleted one. */
    function commentCanEdit(array $row, string $viewer): bool {
        if (commentRowId($row) <= 0 || !commentEditReady($GLOBALS['pdo'] ?? null)) return false;
        if (commentIsDeleted($row)) return false;
        if ($viewer === 'admin') return true;
        return strtolower((string)($row['actor'] ?? '')) === 'client' && empty($row['internal']);
    }
}

if (!function_exists('commentEditBatchKey')) {
    /** One batch_id per comment for its edit / delete events: repeat edits collapse into one feed line. */
    function commentEditBatchKey(int $activityId): string { return substr(sha1('comment-edit:' . $activityId), 0, 16); }
}

if (!function_exists('commentRevisionMeta')) {
    /**
     * The edit history summary of some comments, keyed by activity id: n (revisions), at / actor / author_user_id /
     * client_contact_id / kind (the newest revision), original (the text before the deletion — for the admin's
     * "Show original"). One query.
     */
    function commentRevisionMeta(?PDO $pdo, array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$pdo || !commentEditReady($pdo)) return [];
        $out = [];
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT id, activity_id, kind, old_detail, actor, author_user_id, client_contact_id, created_at
                                  FROM comment_revisions WHERE activity_id IN ($ph) ORDER BY activity_id ASC, id ASC");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $aid = (int)$r['activity_id'];
                $m = $out[$aid] ?? ['n' => 0, 'original' => null, 'first' => null];
                $m['n']++;
                if ($m['first'] === null) $m['first'] = (string)$r['old_detail'];
                $m['at'] = (string)$r['created_at'];
                $m['actor'] = (string)$r['actor'];
                $m['author_user_id'] = $r['author_user_id'] !== null ? (int)$r['author_user_id'] : null;
                $m['client_contact_id'] = $r['client_contact_id'] !== null ? (int)$r['client_contact_id'] : null;
                $m['kind'] = (string)$r['kind'];
                if ($r['kind'] === 'delete') $m['original'] = (string)$r['old_detail'];
                $out[$aid] = $m;
            }
        } catch (Throwable $e) {
            error_log('commentRevisionMeta: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('commentEditorLabel')) {
    /** Who made a revision, from the viewer's side: 'you', a teammate's name (admin seat) / "Lance at Joust" (client
     *  seat), the contact ("Jane Kenda (Kenda Tires)" for Joust, "Jane Kenda" for the client), else the company. */
    function commentEditorLabel(array $m, string $viewer, ?array $company = null): string {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (($m['actor'] ?? '') === 'admin') {
            $uid = (int)($m['author_user_id'] ?? 0);
            $u = $uid > 0 && function_exists('adminUserById') ? adminUserById($pdo, $uid) : null;
            if ($viewer === 'admin') {
                $me = function_exists('currentAdminUserId') ? currentAdminUserId($pdo) : null;
                if ($me !== null && $uid > 0 && $me === $uid) return 'you';
                return $u ? adminUserFirstName($u) : 'Joust';
            }
            return $u ? adminUserFirstName($u) . ' at Joust' : 'Joust';
        }
        $cid = (int)($m['client_contact_id'] ?? 0);
        if ($viewer !== 'admin' && $cid > 0 && function_exists('currentClientContact')) {
            $me = currentClientContact();
            if ($me && (int)$me['id'] === $cid) return 'you';
        }
        $label = $cid > 0 && function_exists('clientContactLabel') ? clientContactLabel($pdo, $cid, $viewer === 'admin') : '';
        if ($label !== '') return $label;
        $company = $company ?? ($GLOBALS['client'] ?? null);
        $name = is_array($company) ? trim((string)($company['name'] ?? '')) : '';
        return $name !== '' ? $name : 'the client';
    }
}

if (!function_exists('commentEditedTitle')) {
    /** "Edited Oct 8, 2026 at 3:12 PM by Jane Kenda" (or "Deleted …"). */
    function commentEditedTitle(array $m, string $viewer, bool $deleted = false): string {
        $at = function_exists('absoluteTime') ? absoluteTime((string)($m['at'] ?? '')) : (string)($m['at'] ?? '');
        return ($deleted ? 'Deleted' : 'Edited') . ($at !== '' ? ' ' . $at : '') . ' by ' . commentEditorLabel($m, $viewer);
    }
}

if (!function_exists('commentLoad')) {
    /** One activity row with everything the edit rules need (null when missing). $lock = FOR UPDATE. */
    function commentLoad(PDO $pdo, int $id, bool $lock = false): ?array {
        $cols = 'id, company_id, entity_type, entity_id, action, actor, batch_id, detail'
              . (activityHasNotifyCols($pdo) ? ', author_user_id, internal' : ', NULL AS author_user_id, 0 AS internal')
              . (activityHasContactCol($pdo) ? ', client_contact_id' : ', NULL AS client_contact_id')
              . (commentEditReady($pdo) ? ', edited_at, deleted_at' : ', NULL AS edited_at, NULL AS deleted_at')
              . ', created_at';
        $s = $pdo->prepare("SELECT {$cols} FROM activity_log WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['comment_id'] = (int)$r['id'];
        return $r;
    }
}

if (!function_exists('commentIsDecisionNote')) {
    /** A Needs-changes note: the comment shares its batch with a 'denied' row (status.php & co. write them together). */
    function commentIsDecisionNote(PDO $pdo, array $row): bool {
        if (empty($row['batch_id'])) return false;
        try {
            $s = $pdo->prepare("SELECT 1 FROM activity_log WHERE batch_id = ? AND entity_type = ? AND entity_id = ? AND action = 'denied' LIMIT 1");
            $s->execute([(string)$row['batch_id'], (string)$row['entity_type'], (int)$row['entity_id']]);
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('commentClientMayReach')) {
    /** The client seat can see the item the comment is on (a draft post does not exist for it). */
    function commentClientMayReach(PDO $pdo, array $row): bool {
        if (!in_array((string)$row['entity_type'], notifyThreadTypes(), true)) return false;
        if ((string)$row['entity_type'] === 'post') {
            $s = $pdo->prepare("SELECT status FROM posts WHERE id = ? AND company_id = ?");
            $s->execute([(int)$row['entity_id'], (int)$row['company_id']]);
            $st = $s->fetchColumn();
            return $st !== false && $st !== 'draft';
        }
        return true;
    }
}

if (!function_exists('commentEditApply')) {
    /**
     * Edit or delete one comment as the current seat. $op 'edit' | 'delete'; $in: text (the new message, may carry its
     * own "[Slide N] " prefix), slide ('' / absent = keep the current slide tag, 0 = none, N = slide N).
     * → ['ok' => true, 'row' => the updated row, 'changed' => bool, 'revision_id'] or ['ok' => false, 'code', 'error'].
     */
    function commentEditApply(PDO $pdo, int $id, string $op, array $in = []): array {
        if (!commentEditReady($pdo)) return ['ok' => false, 'code' => 503, 'error' => 'Comment editing needs the latest database update (migrate.php)'];
        if (!in_array($op, ['edit', 'delete'], true)) return ['ok' => false, 'code' => 400, 'error' => 'Unknown action'];
        if ($id <= 0) return ['ok' => false, 'code' => 400, 'error' => 'Invalid id'];
        $isAdmin = function_exists('currentAdmin') && currentAdmin() !== null;
        $text = null;
        if ($op === 'edit') {
            $text = trim(str_replace("\r\n", "\n", (string)($in['text'] ?? '')));
        }
        try {
            $pdo->beginTransaction();
            $row = commentLoad($pdo, $id, true);
            if (!$row || $row['action'] !== 'commented') { $pdo->rollBack(); return ['ok' => false, 'code' => 404, 'error' => 'Comment not found']; }
            // ---- who may ----
            if (!$isAdmin) {
                if (!clientOwnsCompany($pdo, (int)$row['company_id'])) { $pdo->rollBack(); return ['ok' => false, 'code' => 403, 'error' => 'This comment belongs to another client']; }
                if (!empty($row['internal'])) { $pdo->rollBack(); return ['ok' => false, 'code' => 404, 'error' => 'Comment not found']; }
                if ($row['actor'] !== 'client') { $pdo->rollBack(); return ['ok' => false, 'code' => 403, 'error' => 'You can only change your own comments']; }
                if (!commentClientMayReach($pdo, $row)) { $pdo->rollBack(); return ['ok' => false, 'code' => 404, 'error' => 'Comment not found']; }
            }
            if (commentIsDeleted($row)) {
                $pdo->rollBack();
                return $op === 'delete' ? ['ok' => true, 'row' => $row, 'changed' => false, 'revision_id' => 0]
                                        : ['ok' => false, 'code' => 409, 'error' => 'This comment was deleted'];
            }
            $old = (string)$row['detail'];
            $new = '';
            if ($op === 'edit') {
                // The slide tag: kept unless the edit names one (the text's own prefix, or slide=N / slide=0).
                [$oldSlide] = commentSlideSplit($old);
                [$txtSlide, $body] = commentSlideSplit($text);
                $slide = $txtSlide > 0 ? $txtSlide : $oldSlide;
                if (array_key_exists('slide', $in) && (string)$in['slide'] !== '') $slide = max(0, min(99, (int)$in['slide']));
                $body = trim($body);
                if ($body === '') { $pdo->rollBack(); return ['ok' => false, 'code' => 422, 'error' => 'A comment can’t be empty — delete it instead']; }
                $new = ($slide > 0 ? '[Slide ' . $slide . '] ' : '') . $body;
                if (strlen($new) > COMMENT_MAX_BYTES) { $pdo->rollBack(); return ['ok' => false, 'code' => 400, 'error' => 'Comment too long (max 2000 chars)']; }
                if (mb_strlen($body, 'UTF-8') < 3 && $row['actor'] === 'client' && commentIsDecisionNote($pdo, $row)) {
                    $pdo->rollBack();
                    return ['ok' => false, 'code' => 422, 'error' => 'Please keep a short note (at least 3 characters) explaining what should change.'];
                }
                if ($new === $old) { $pdo->rollBack(); return ['ok' => true, 'row' => $row, 'changed' => false, 'revision_id' => 0]; }
            }
            // ---- write: the revision first (the original is never lost), then the row in place ----
            $actor = $isAdmin ? 'admin' : 'client';
            $authorId = $isAdmin && function_exists('currentAdminUserId') ? currentAdminUserId($pdo) : null;
            $contactId = !$isAdmin ? activityCurrentClientContactId($pdo, (int)$row['company_id']) : null;
            $pdo->prepare("INSERT INTO comment_revisions (activity_id, company_id, kind, old_detail, new_detail, actor, author_user_id, client_contact_id, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$id, (int)$row['company_id'], $op, $old, $op === 'edit' ? $new : null, $actor, $authorId, $contactId]);
            $revId = (int)$pdo->lastInsertId();
            if ($op === 'edit') {
                $pdo->prepare("UPDATE activity_log SET detail = ?, edited_at = NOW() WHERE id = ?")->execute([$new, $id]);
            } else {
                $pdo->prepare("UPDATE activity_log SET detail = '', deleted_at = NOW() WHERE id = ?")->execute([$id]);
            }
            // posts / tire_images keep client_comment = the newest message: follow the edit when it held the old text
            // (the redo pack treats a client_comment missing from the thread as a legacy note).
            $mirror = ['post' => 'posts', 'tire_image' => 'tire_images'][(string)$row['entity_type']] ?? null;
            if ($mirror !== null) {
                try {
                    $pdo->prepare("UPDATE {$mirror} SET client_comment = ? WHERE id = ? AND client_comment = ?")
                        ->execute([$op === 'edit' ? $new : null, (int)$row['entity_id'], $old]);
                } catch (Throwable $e) {
                    error_log('commentEditApply mirror: ' . $e->getMessage());
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('commentEditApply #' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'code' => 500, 'error' => 'Database error'];
        }
        $row = commentLoad($pdo, $id) ?? $row;
        // ---- the event (internal: Joust's history only; one feed line per comment) + Slack ----
        $info = function_exists('notifyItemInfo') ? notifyItemInfo($pdo, (string)$row['entity_type'], (int)$row['entity_id']) : ['title' => ''];
        $what = $info['title'] !== '' ? ' on ' . $info['title'] : '';
        $ctx = ['internal' => 1];
        if (!$isAdmin) $ctx['client_contact_id'] = $contactId;
        activityWithContext($ctx, static function () use ($pdo, $row, $op, $what, $actor, $id) {
            logActivity($pdo, (int)$row['company_id'], (string)$row['entity_type'], (int)$row['entity_id'],
                $op === 'edit' ? 'comment_edited' : 'comment_deleted', $actor,
                ($op === 'edit' ? 'Edited a comment' : 'Deleted a comment') . $what, '#' . $id, commentEditBatchKey($id));
        });
        // Slack names the editor in the third person: the teammate's name, else "Jane Kenda (Kenda Tires)" / the client.
        $u = $authorId ? adminUserById($pdo, $authorId) : null;
        $editor = $actor === 'admin' ? ($u ? adminUserFirstName($u) : 'Joust')
                : (($contactId ? clientContactLabel($pdo, $contactId) : '') ?: (string)($info['company_name'] ?? '') ?: 'The client');
        commentSlackEnqueue($pdo, $row, $op, $revId, $editor);
        unset($GLOBALS['__trackWait']);
        return ['ok' => true, 'row' => $row, 'changed' => true, 'revision_id' => $revId];
    }
}

if (!function_exists('commentHistory')) {
    /** Every revision of one comment, oldest first, for the admin's History: [['kind', 'old', 'new', 'who', 'at']] +
     *  the row's created text first ('posted'). */
    function commentHistory(PDO $pdo, int $id): array {
        $row = commentLoad($pdo, $id);
        if (!$row || $row['action'] !== 'commented' || !commentEditReady($pdo)) return [];
        $s = $pdo->prepare("SELECT id, kind, old_detail, new_detail, actor, author_user_id, client_contact_id, created_at FROM comment_revisions WHERE activity_id = ? ORDER BY id ASC");
        $s->execute([$id]);
        $revs = $s->fetchAll(PDO::FETCH_ASSOC);
        $company = ['name' => ''];
        try {
            $c = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
            $c->execute([(int)$row['company_id']]);
            $company['name'] = (string)($c->fetchColumn() ?: '');
        } catch (Throwable $e) {}
        $origAuthor = ['actor' => (string)$row['actor'], 'author_user_id' => $row['author_user_id'], 'client_contact_id' => $row['client_contact_id']];
        $out = [[
            'kind' => 'posted',
            'text' => $revs ? (string)$revs[0]['old_detail'] : (string)$row['detail'],
            'who'  => $row['actor'] === 'unknown' ? 'Note' : commentEditorLabel($origAuthor, 'admin', $company),
            'at'   => (string)$row['created_at'],
        ]];
        foreach ($revs as $r) {
            $out[] = [
                'kind' => (string)$r['kind'],
                'text' => $r['kind'] === 'delete' ? '' : (string)$r['new_detail'],
                'old'  => (string)$r['old_detail'],
                'who'  => commentEditorLabel($r, 'admin', $company),
                'at'   => (string)$r['created_at'],
            ];
        }
        return $out;
    }
}

// =====================================================================================================================
// Slack
// =====================================================================================================================

if (!function_exists('commentSlackRemember')) {
    /** A Slack message carrying comments was delivered: remember it per comment (only 'commented' rows). */
    function commentSlackRemember(PDO $pdo, array $activityIds, string $kind, string $channel, string $ts, int $outboxId): void {
        $ids = array_values(array_unique(array_filter(array_map('intval', $activityIds))));
        if (!$ids || $channel === '' || $ts === '' || !commentSlackReady($pdo)) return;
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT id FROM activity_log WHERE id IN ($ph) AND action = 'commented'");
            $s->execute($ids);
            $ins = $pdo->prepare("INSERT INTO comment_slack (activity_id, kind, slack_channel, slack_ts, outbox_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())
                                  ON DUPLICATE KEY UPDATE kind = VALUES(kind), slack_channel = VALUES(slack_channel), slack_ts = VALUES(slack_ts), outbox_id = VALUES(outbox_id)");
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $aid) $ins->execute([(int)$aid, substr($kind, 0, 20), substr($channel, 0, 40), substr($ts, 0, 40), $outboxId > 0 ? $outboxId : null]);
        } catch (Throwable $e) {
            error_log('commentSlackRemember: ' . $e->getMessage());
        }
    }
}

if (!function_exists('commentSlackEnqueue')) {
    /** Queue the Slack side of an edit / delete (delivered after the response; the cron retries). Never an email. */
    function commentSlackEnqueue(PDO $pdo, array $row, string $op, int $revId, string $editor): void {
        try {
            if ($revId <= 0 || !notifyReady($pdo) || !notifySlackConfigured()) return;
            if (!in_array((string)$row['entity_type'], notifyThreadTypes(), true)) return;
            notifyEnqueue($pdo, 'slack', 'comment_edit',
                ['activity_id' => (int)$row['id'], 'op' => $op, 'editor' => $editor, 'revision_id' => $revId,
                 'entity_type' => (string)$row['entity_type'], 'entity_id' => (int)$row['entity_id'], 'company_id' => (int)$row['company_id']],
                ['dedupe' => 'cedit:' . $revId, 'company_id' => (int)$row['company_id'], 'entity_type' => (string)$row['entity_type'], 'entity_id' => (int)$row['entity_id']]);
        } catch (Throwable $e) {
            error_log('commentSlackEnqueue: ' . $e->getMessage());
        }
    }
}

if (!function_exists('commentSlackPending')) {
    /** Is the comment still waiting in an undelivered Slack message (it will go out with the current text)? */
    function commentSlackPending(PDO $pdo, array $row): bool {
        try {
            $s = $pdo->prepare("SELECT kind, payload FROM notify_outbox WHERE kind IN ('item_event','internal_note') AND status IN ('pending','sending')
                                   AND entity_type = ? AND entity_id = ?");
            $s->execute([(string)$row['entity_type'], (int)$row['entity_id']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $p = json_decode((string)$o['payload'], true) ?: [];
                $ids = $o['kind'] === 'internal_note' ? [(int)($p['activity_id'] ?? 0)] : array_map('intval', (array)($p['activity_ids'] ?? []));
                if (in_array((int)$row['id'], $ids, true)) return true;
            }
        } catch (Throwable $e) {
            error_log('commentSlackPending: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('commentSlackDeliver')) {
    /** Outbox 'comment_edit': chat.update the comment's own Slack message, else a short note in the item's thread. */
    function commentSlackDeliver(PDO $pdo, array $p): array {
        $id = (int)($p['activity_id'] ?? 0);
        $op = (string)($p['op'] ?? 'edit');
        $row = $id > 0 ? commentLoad($pdo, $id) : null;
        if (!$row || $row['action'] !== 'commented') return ['ok' => false, 'skip' => true, 'error' => 'the comment no longer exists'];
        $type = (string)$row['entity_type']; $eid = (int)$row['entity_id']; $cid = (int)$row['company_id'];
        $map = null;
        if (commentSlackReady($pdo)) {
            $s = $pdo->prepare("SELECT * FROM comment_slack WHERE activity_id = ?");
            $s->execute([$id]);
            $map = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($map) {
            $info = notifyItemInfo($pdo, $type, $eid);
            if ($map['kind'] === 'internal_note') {
                $u = adminUserById($pdo, (int)($row['author_user_id'] ?? 0));
                $text = commentIsDeleted($row) ? '_comment deleted_'
                      : ':lock: *Internal note* (Joust only — the client never sees it) from *' . notifySlackEscape($u ? adminUserFirstName($u) : 'Joust') . "*:\n"
                        . notifyQuote((string)$row['detail']) . ' _(edited)_';
            } else {
                // every activity the message was rendered from (a Needs-changes decision + its note travel together)
                $ids = [];
                if (!empty($map['outbox_id'])) {
                    $o = $pdo->prepare("SELECT payload FROM notify_outbox WHERE id = ?");
                    $o->execute([(int)$map['outbox_id']]);
                    $op0 = json_decode((string)($o->fetchColumn() ?: ''), true);
                    if (is_array($op0)) $ids = array_map('intval', (array)($op0['activity_ids'] ?? []));
                }
                $s = $pdo->prepare("SELECT activity_id FROM comment_slack WHERE slack_channel = ? AND slack_ts = ?");
                $s->execute([(string)$map['slack_channel'], (string)$map['slack_ts']]);
                $ids = array_values(array_unique(array_merge($ids, array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)), [$id])));
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT id, action, actor, detail, internal, edited_at, deleted_at" . (activityHasContactCol($pdo) ? ', client_contact_id' : ', NULL AS client_contact_id')
                                   . " FROM activity_log WHERE id IN ($ph) AND company_id = ? AND entity_type = ? AND entity_id = ? AND actor = 'client' AND internal = 0 ORDER BY id ASC");
                $s->execute(array_merge($ids, [$cid, $type, $eid]));
                $acts = $s->fetchAll(PDO::FETCH_ASSOC);
                if (!$acts) return ['ok' => false, 'skip' => true, 'error' => 'the events were removed'];
                $onlyDeleted = true;
                foreach ($acts as $a) { if ($a['action'] !== 'commented' || !commentIsDeleted($a)) { $onlyDeleted = false; break; } }
                $text = $onlyDeleted ? '_comment deleted_' : notifySlackEventText($info, $acts, notifyOwnerFor($pdo, $cid));
            }
            $r = slackApi('chat.update', ['channel' => (string)$map['slack_channel'], 'ts' => (string)$map['slack_ts'], 'text' => $text]);
            if (empty($r['ok'])) return notifySlackResult($r, 'chat.update');
            if ($op === 'delete') notifySlackUpdateParent($pdo, $type, $eid);   // the "waiting on Joust" line
            return ['ok' => true, 'provider_id' => (string)$map['slack_ts'], 'note' => 'updated the comment’s message'];
        }
        // No stored message (an older comment, or one never posted): the pending message carries the current text …
        if (commentSlackPending($pdo, $row)) return ['ok' => false, 'skip' => true, 'error' => 'the comment’s message has not gone out yet — it will carry the current text'];
        // … Joust's own portal replies never went to Slack …
        $posted = ($row['actor'] === 'client' && empty($row['internal'])) || ($row['actor'] === 'admin' && !empty($row['internal']));
        if (!$posted) return ['ok' => false, 'skip' => true, 'error' => 'Joust’s replies are not posted to Slack'];
        // … else a short note in the item's thread (never one started just for this).
        $t = notifyThreadRow($pdo, $type, $eid);
        if (!$t || (string)$t['slack_ts'] === '') return ['ok' => false, 'skip' => true, 'error' => 'no Slack thread for this item'];
        $who = '*' . notifySlackEscape((string)($p['editor'] ?? 'Someone')) . '*';
        if ($op === 'delete') {
            $text = '🗑️ ' . $who . ' deleted a comment.';
        } else {
            [$slide, $body] = commentSlideSplit((string)$row['detail']);
            $text = '✏️ ' . $who . ' edited a comment' . ($slide > 0 ? ' on slide ' . $slide : '') . ":\n" . notifyQuote($body, 700);
        }
        $r = slackApi('chat.postMessage', ['channel' => (string)$t['slack_channel'], 'thread_ts' => (string)$t['slack_ts'], 'text' => $text, 'unfurl_links' => false, 'unfurl_media' => false]);
        if (empty($r['ok'])) return notifySlackResult($r, 'edit note');
        if ($op === 'delete') notifySlackUpdateParent($pdo, $type, $eid);
        return ['ok' => true, 'provider_id' => (string)($r['data']['ts'] ?? ''), 'note' => 'posted an edit note in the thread'];
    }
}
