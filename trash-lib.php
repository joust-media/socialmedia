<?php
/**
 * The Trash — items Joust has decided not to do (loaded by helpers.php; never include directly).
 *
 * Lance: "some of these that need change, i'm not going to do, so it just needs to go in the trash, but i want to make
 * sure it's still separate and still lives somewhere, but disregarded from notifications and everything like that".
 *
 * A state APART from the review status: posts, emails, pages, tire images (series renders + reference images) and
 * library images carry trashed_at / trashed_by / trash_note (migrate.php 54). trashed_at IS NOT NULL = in the Trash.
 * The status column is never touched, so Restore puts the item back exactly where it was (To Review, Needs changes,
 * Approved, Scheduled, Live, a draft, its Redo flag …). Nothing on disk moves: files and previews stay where they are.
 *
 *   in   trashMove()      admin: the ⋯ menus (post / email / page sheets, the media viewer), the Assets select bar, the
 *                         Redo page rows → trash.php action=trash (+ an optional reason: trash_note, Joust-only)
 *   out  trashRestore()   the Trash page → back to its exact previous state; no client email, no Slack ping, no
 *                         escalation restart (the waits it had are recorded as handled)
 *        trashDeleteForever()  the Trash page, behind a typed DELETE: the row AND its files, for good
 *
 * While trashed an item is disregarded everywhere: every client view (lists, Sent back, Home, badges, counts, deep
 * links → "no longer available"), Joust's normal lists and counts (segments, tab badges, Home, the Inbox, the Redo
 * queue, the Needs changes queues), notifications (Slack escalations / DMs, the client email batches, the Morning
 * summary, the weekly report — and its pending outbox rows are skipped, "item trashed"), exports and post pickers.
 * Every reader uses one of:
 *   trashAnd($pdo, $type, $alias)      " AND alias.trashed_at IS NULL" ('' before migrate)
 *   trashLive($pdo, $type, $alias)     "alias.trashed_at IS NULL" ('1 = 1' before migrate) — for WHERE arrays
 *   trashActivitySql($pdo, $tCol, $iCol)  " AND NOT (…)" — activity_log / outbox / queue rows about a trashed item
 *   trashedIds() / trashIsTrashed() / trashFilterRows()   the same in PHP
 *
 * Every function is function_exists-guarded, does no work at load, and is gated on the column probes, so a deploy that
 * has not run migrate.php behaves exactly as before (nothing is ever trashed, every filter is empty).
 */

if (!function_exists('trashTypes')) {
    /** entity_type → [table, noun]. The activity_log / notify entity types. */
    function trashTypes(): array {
        return [
            'post'          => ['table' => 'posts',          'noun' => 'Post'],
            'email'         => ['table' => 'emails',         'noun' => 'Email'],
            'page'          => ['table' => 'pages',          'noun' => 'Page'],
            'tire_image'    => ['table' => 'tire_images',    'noun' => 'Tire image'],
            'library_image' => ['table' => 'library_images', 'noun' => 'Library image'],
        ];
    }
}

if (!function_exists('trashNormType')) {
    /** 'tire' / 'library' (the viewer's kinds) and the entity types → the entity type; '' when unknown. */
    function trashNormType(string $t): string {
        $t = strtolower(trim($t));
        $alias = ['tire' => 'tire_image', 'library' => 'library_image', 'posts' => 'post', 'emails' => 'email', 'pages' => 'page'];
        $t = $alias[$t] ?? $t;
        return isset(trashTypes()[$t]) ? $t : '';
    }
}

if (!function_exists('trashColumnExists')) {
    /** (internal) Does <table>.<column> exist? Cached per request (trashProbeReset() clears it). */
    function trashColumnExists(PDO $pdo, string $table, string $column): bool {
        $k = $table . '.' . $column;
        if (isset($GLOBALS['__trashCols']) && array_key_exists($k, $GLOBALS['__trashCols'])) return $GLOBALS['__trashCols'][$k];
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $s->execute([$table, $column]);
            $v = (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            $v = false;
        }
        $GLOBALS['__trashCols'][$k] = $v;
        return $v;
    }
}

if (!function_exists('trashProbeReset')) {
    function trashProbeReset(): void { unset($GLOBALS['__trashCols']); }
}

if (!function_exists('trashReady')) {
    /** The type's table carries trashed_at (migrate.php 54). No type = the posts table (the feature's gate). */
    function trashReady(?PDO $pdo, string $type = 'post'): bool {
        $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
        if (!$pdo instanceof PDO) return false;
        $t = trashTypes()[trashNormType($type)] ?? null;
        return $t !== null && trashColumnExists($pdo, $t['table'], 'trashed_at');
    }
}

if (!function_exists('trashAnd')) {
    /** " AND <alias>.trashed_at IS NULL" for the type's table ('' when the column is not there yet). */
    function trashAnd(?PDO $pdo, string $type, string $alias = ''): string {
        return trashReady($pdo, $type) ? ' AND ' . ($alias !== '' ? $alias . '.' : '') . 'trashed_at IS NULL' : '';
    }
}

if (!function_exists('trashLive')) {
    /** "<alias>.trashed_at IS NULL" (a WHERE-array entry) — '1 = 1' before migrate. */
    function trashLive(?PDO $pdo, string $type, string $alias = ''): string {
        return trashReady($pdo, $type) ? ($alias !== '' ? $alias . '.' : '') . 'trashed_at IS NULL' : '1 = 1';
    }
}

if (!function_exists('trashActivitySql')) {
    /**
     * " AND NOT (…)" leaving out rows (activity_log, notify_outbox, client_email_queue, …) about a trashed item:
     * $typeCol / $idCol name the row's entity columns ('a.entity_type', 'a.entity_id'). '' before migrate.
     */
    function trashActivitySql(?PDO $pdo, string $typeCol = 'entity_type', string $idCol = 'entity_id'): string {
        $parts = [];
        foreach (trashTypes() as $type => $t) {
            if (!trashReady($pdo, $type)) continue;
            $parts[] = "({$typeCol} = '{$type}' AND {$idCol} IN (SELECT id FROM {$t['table']} WHERE trashed_at IS NOT NULL))";
        }
        return $parts ? ' AND NOT (' . implode(' OR ', $parts) . ')' : '';
    }
}

if (!function_exists('trashedIds')) {
    /** [id => true] for the trashed ones among $ids of one type (one query; [] before migrate). */
    function trashedIds(?PDO $pdo, string $type, array $ids): array {
        $type = trashNormType($type);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; })));
        if ($type === '' || !$ids || !trashReady($pdo, $type)) return [];
        $out = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $s = $pdo->prepare("SELECT id FROM " . trashTypes()[$type]['table'] . " WHERE trashed_at IS NOT NULL AND id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
                $s->execute($chunk);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $out[(int)$id] = true;
            }
        } catch (Throwable $e) {
            error_log('trashedIds: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('trashIsTrashed')) {
    function trashIsTrashed(?PDO $pdo, string $type, int $id): bool {
        return $id > 0 && isset(trashedIds($pdo, $type, [$id])[$id]);
    }
}

if (!function_exists('trashFilterRows')) {
    /** Drop the rows about a trashed item (rows carry $typeKey / $idKey — activity rows, notifyUnanswered() rows …). */
    function trashFilterRows(?PDO $pdo, array $rows, string $typeKey = 'entity_type', string $idKey = 'entity_id'): array {
        if (!$rows || !trashReady($pdo, 'post')) return $rows;
        $by = [];
        foreach ($rows as $r) { $t = trashNormType((string)($r[$typeKey] ?? '')); if ($t !== '') $by[$t][] = (int)($r[$idKey] ?? 0); }
        $gone = [];
        foreach ($by as $t => $ids) { foreach (trashedIds($pdo, $t, $ids) as $id => $_) $gone[$t . ':' . $id] = true; }
        if (!$gone) return $rows;
        return array_values(array_filter($rows, static function ($r) use ($gone, $typeKey, $idKey) {
            $t = trashNormType((string)($r[$typeKey] ?? ''));
            return !isset($gone[$t . ':' . (int)($r[$idKey] ?? 0)]);
        }));
    }
}

if (!function_exists('trashParseItems')) {
    /** "post:3,email:4,tire:12,library_image:5" (or an array of those) → [['type' => 'post', 'id' => 3], …] — unique, ≤ 500. */
    function trashParseItems($raw): array {
        $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = []; $seen = [];
        foreach ((array)$list as $ref) {
            if (!is_scalar($ref) || !preg_match('/^([a-z_]+):(\d{1,10})$/', trim((string)$ref), $m) || (int)$m[2] <= 0) continue;
            $type = trashNormType($m[1]);
            if ($type === '') continue;
            $k = $type . ':' . (int)$m[2];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = ['type' => $type, 'id' => (int)$m[2]];
            if (count($out) >= 500) break;
        }
        return $out;
    }
}

if (!function_exists('trashKeepUpdated')) {
    /** (internal) ', updated_at = updated_at' when the table auto-bumps it: trashing is not an edit of the item. */
    function trashKeepUpdated(PDO $pdo, string $table): string {
        return trashColumnExists($pdo, $table, 'updated_at') ? ', updated_at = updated_at' : '';
    }
}

if (!function_exists('trashItemRow')) {
    /** One item with its company and trash columns: {type, id, company_id, status, live/posted, trashed_at, trash_note, trashed_by, …}; null when unknown. */
    function trashItemRow(PDO $pdo, string $type, int $id): ?array {
        $type = trashNormType($type);
        if ($id <= 0 || $type === '' || !trashReady($pdo, $type)) return null;
        try {
            switch ($type) {
                case 'tire_image':
                    $s = $pdo->prepare("SELECT ti.*, t.company_id, t.name AS tire_name FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.id = ?");
                    break;
                default:
                    $s = $pdo->prepare("SELECT * FROM " . trashTypes()[$type]['table'] . " WHERE id = ?");
            }
            $s->execute([$id]);
            $r = $s->fetch();
        } catch (Throwable $e) {
            error_log('trashItemRow: ' . $e->getMessage());
            return null;
        }
        if (!$r) return null;
        $r['type'] = $type;
        $r['company_id'] = (int)$r['company_id'];
        return $r;
    }
}

if (!function_exists('trashCleanNote')) {
    function trashCleanNote(string $note): string {
        $note = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $note));
        return function_exists('mb_substr') ? mb_substr($note, 0, 500, 'UTF-8') : substr($note, 0, 500);
    }
}

if (!function_exists('trashLog')) {
    /** (internal) An internal (admin-only) activity row about the item — never a client email or a Slack ping. */
    function trashLog(PDO $pdo, int $companyId, string $type, int $id, string $action, string $summary, ?string $detail, ?string $batch): void {
        $fn = static function () use ($pdo, $companyId, $type, $id, $action, $summary, $detail, $batch) {
            logActivity($pdo, $companyId, $type, $id, $action, 'admin', $summary, $detail, $batch ?? newBatchId());
        };
        if (function_exists('activityWithContext')) activityWithContext(['internal' => 1], $fn); else $fn();
    }
}

if (!function_exists('trashSkipPending')) {
    /**
     * What was still going to go out about the item: pending (and failed, not yet sent) outbox rows → skipped "item
     * trashed"; open client email queue rows (Ready for review / Joust replied / Live / reminders not batched yet) are
     * closed so no batch carries them. Returns the outbox rows skipped.
     */
    function trashSkipPending(PDO $pdo, string $type, int $id): int {
        $n = 0;
        try {
            if (function_exists('notifyReady') && notifyReady($pdo)) {
                $s = $pdo->prepare("UPDATE notify_outbox SET status = 'skipped', last_error = 'item trashed' WHERE entity_type = ? AND entity_id = ? AND status IN ('pending','failed')");
                $s->execute([$type, $id]);
                $n = $s->rowCount();
            }
        } catch (Throwable $e) { error_log('trashSkipPending outbox: ' . $e->getMessage()); }
        try {
            if (function_exists('clientEmailReady') && clientEmailReady($pdo)) {
                $pdo->prepare("UPDATE client_email_queue SET batch_key = ?, batched_at = NOW() WHERE batch_key IS NULL AND entity_type = ? AND entity_id = ?")
                    ->execute([substr('trashed:' . $type . ':' . $id, 0, 60), $type, $id]);
            }
        } catch (Throwable $e) { error_log('trashSkipPending queue: ' . $e->getMessage()); }
        return $n;
    }
}

if (!function_exists('trashMove')) {
    /**
     * Put one item in the Trash (admin). $note = the optional reason (trash_note; Joust-only, also the detail of the
     * internal 'trashed' activity row). Re-trashing an item already there only updates the note. The status, the Redo
     * flag, files and previews are left exactly as they are. → ['ok', 'trashed' (newly), 'skipped' (outbox rows)] or
     * ['ok' => false, 'error'].
     */
    function trashMove(PDO $pdo, string $type, int $id, string $note = '', array $opts = []): array {
        $type = trashNormType($type);
        $row = trashItemRow($pdo, $type, $id);
        if (!$row) return ['ok' => false, 'error' => 'Item not found'];
        $note  = trashCleanNote($note);
        $table = trashTypes()[$type]['table'];
        $by    = function_exists('currentAdminUserId') ? currentAdminUserId($pdo) : null;
        $was   = $row['trashed_at'] !== null;
        if ($was) {
            if ($note !== '') $pdo->prepare("UPDATE {$table} SET trash_note = ?" . trashKeepUpdated($pdo, $table) . " WHERE id = ?")->execute([$note, $id]);
            return ['ok' => true, 'trashed' => false, 'skipped' => 0, 'row' => $row];
        }
        $pdo->prepare("UPDATE {$table} SET trashed_at = NOW(), trashed_by = ?, trash_note = ?" . trashKeepUpdated($pdo, $table) . " WHERE id = ?")
            ->execute([$by, $note !== '' ? $note : null, $id]);
        $skipped = trashSkipPending($pdo, $type, $id);
        // the internal row: Joust's history, and the Slack parent's status pill (chat.update — no new message)
        trashLog($pdo, (int)$row['company_id'], $type, $id, 'trashed', 'Moved to Trash' . ($note !== '' ? ': ' . $note : ''), $note !== '' ? $note : null, $opts['batch'] ?? null);
        return ['ok' => true, 'trashed' => true, 'skipped' => $skipped, 'row' => $row];
    }
}

if (!function_exists('trashRestore')) {
    /**
     * Take one item out of the Trash: back to its exact previous state and lists (the status was never changed). No
     * client email (nothing is queued), no Slack ping (only the parent's pill is re-rendered), and the escalation /
     * gentle-reminder clocks do not restart: the client's unanswered message and the item's review request are marked
     * handled ("restored from Trash"). → ['ok', 'restored'] / ['ok' => false, 'error'].
     */
    function trashRestore(PDO $pdo, string $type, int $id, array $opts = []): array {
        $type = trashNormType($type);
        $row = trashItemRow($pdo, $type, $id);
        if (!$row) return ['ok' => false, 'error' => 'Item not found'];
        if ($row['trashed_at'] === null) return ['ok' => true, 'restored' => false, 'row' => $row];
        $table = trashTypes()[$type]['table'];
        $pdo->prepare("UPDATE {$table} SET trashed_at = NULL, trashed_by = NULL, trash_note = NULL" . trashKeepUpdated($pdo, $table) . " WHERE id = ?")->execute([$id]);
        trashQuietRestore($pdo, $type, $id, (int)$row['company_id']);
        trashLog($pdo, (int)$row['company_id'], $type, $id, 'restored', 'Restored from Trash', null, $opts['batch'] ?? null);
        return ['ok' => true, 'restored' => true, 'row' => $row];
    }
}

if (!function_exists('trashQuietRestore')) {
    /** (internal) A restored item must not restart reminders: its current wait's escalation steps and the gentle
     *  reminders for its current review request are recorded as already handled. Best-effort, never throws. */
    function trashQuietRestore(PDO $pdo, string $type, int $id, int $companyId): void {
        try {
            if (function_exists('notifyItemWaiting') && function_exists('notifyReady') && notifyReady($pdo)) {
                $w = notifyItemWaiting($pdo, $type, $id, $companyId);
                if ($w) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO notify_outbox (channel, kind, company_id, entity_type, entity_id, payload, dedupe_key, status, last_error)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, 'skipped', 'restored from Trash — no reminder')");
                    $payload = json_encode(['entity_type' => $type, 'entity_id' => $id, 'company_id' => $companyId, 'first_id' => (int)$w['first_id']], JSON_UNESCAPED_SLASHES);
                    foreach ([['slack', 'escalate_thread', 'esc1t:'], ['slack', 'escalate_dm', 'esc1d:'], ['email', 'escalate_email', 'esc2:']] as [$ch, $kind, $pre]) {
                        $ins->execute([$ch, $kind, $companyId, $type, $id, $payload, $pre . (int)$w['first_id']]);
                    }
                }
            }
        } catch (Throwable $e) { error_log('trashQuietRestore escalation: ' . $e->getMessage()); }
        try {
            if (function_exists('clientEmailReady') && clientEmailReady($pdo) && defined('CLIENT_REMIND_MAX_PER_ITEM')) {
                // closed 'remind' rows count against the per-item cap of the current review request; their batch key
                // ('trash:…') keeps them out of the per-client "last reminder" clock (clientEmailRemindQueue()).
                $ins = $pdo->prepare("INSERT INTO client_email_queue (company_id, kind, entity_type, entity_id, batch_key, batched_at) VALUES (?, 'remind', ?, ?, ?, NOW())");
                for ($i = 0; $i < CLIENT_REMIND_MAX_PER_ITEM; $i++) $ins->execute([$companyId, $type, $id, substr('trash:restored:' . $type . ':' . $id, 0, 60)]);
            }
        } catch (Throwable $e) { error_log('trashQuietRestore remind: ' . $e->getMessage()); }
    }
}

if (!function_exists('trashDeleteForever')) {
    /**
     * Delete a TRASHED item for good: the row and its files (post media + previews, the tire / library file + previews
     * + a .mov's .mp4 twin, a page's folder, a hosted email's HTML when no other email uses it). Only items in the Trash
     * (anything else → error). Logs 'deleted' (the existing per-type path). → ['ok', 'files' => n] / ['ok' => false, 'error'].
     */
    function trashDeleteForever(PDO $pdo, string $type, int $id): array {
        $type = trashNormType($type);
        $row = trashItemRow($pdo, $type, $id);
        if (!$row) return ['ok' => false, 'error' => 'Item not found'];
        if ($row['trashed_at'] === null) return ['ok' => false, 'error' => 'Only items in the Trash can be deleted forever'];
        $cid = (int)$row['company_id'];
        $files = 0; $kept = 0;
        // Staging shares media/ (tire renders, the Library) with production: there the row goes but a shared file stays.
        $shared = trashKeepsSharedMedia() && function_exists('mediaRootPath') ? realpath(mediaRootPath()) : false;
        $unlink = static function (?string $path) use (&$files, &$kept, $shared): void {
            if ($path === null || $path === '' || !is_file($path) || is_link($path)) return;
            if ($shared !== false) {
                $real = realpath($path);
                if ($real === false || strpos($real, rtrim($shared, '/') . '/') === 0) { $kept++; return; }
            }
            if (function_exists('previewDelete')) previewDelete($path);
            if (@unlink($path)) $files++;
        };
        switch ($type) {
            case 'post': {
                $s = $pdo->prepare("SELECT image_url FROM post_images WHERE post_id = ?");
                $s->execute([$id]);
                $paths = [];
                foreach ($s->fetchAll() as $r) {
                    $url = (string)$r['image_url'];
                    // a post's media is its own copy in uploads/ unless another post (or a tire image) still uses it
                    $o = $pdo->prepare("SELECT COUNT(*) FROM post_images WHERE image_url = ? AND post_id <> ?");
                    $o->execute([$url, $id]);
                    if ((int)$o->fetchColumn() > 0) continue;
                    $p = function_exists('uploadsPathOrNull') ? uploadsPathOrNull($url) : null;
                    if ($p !== null) $paths[] = $p;
                }
                $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);   // CASCADE: post_images, post_categories
                logActivity($pdo, $cid, 'post', $id, 'deleted', 'admin', "Deleted post #{$id} forever (from the Trash)");
                foreach ($paths as $p) $unlink($p);
                break;
            }
            case 'email': {
                $email = function_exists('emailById') ? emailById($pdo, $id) : null;
                if (!$email) return ['ok' => false, 'error' => 'Item not found'];
                $html = function_exists('emailHostedPath') ? emailHostedPath((string)($email['html_url'] ?? '')) : null;
                if ($html !== null) {
                    $o = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE html_url = ? AND id <> ?");
                    $o->execute([(string)$email['html_url'], $id]);
                    if ((int)$o->fetchColumn() > 0) $html = null;
                }
                deleteEmail($pdo, $email, 'admin');
                if ($html !== null && is_file($html) && !is_link($html) && @unlink($html)) $files++;
                break;
            }
            case 'page': {
                if (!function_exists('deletePage') && is_file(__DIR__ . '/pages-lib.php')) require_once __DIR__ . '/pages-lib.php';
                $page = pageById($pdo, $id);
                if (!$page) return ['ok' => false, 'error' => 'Item not found'];
                $r = deletePage($pdo, $page, 'admin');
                $files += max(0, (int)($r['files'] ?? 0));
                break;
            }
            case 'tire_image': {
                $path = function_exists('tireImagePath') ? tireImagePath($row) : null;
                $thumb = function_exists('tireThumbPath') ? tireThumbPath($row) : null;
                $pdo->prepare("DELETE FROM tire_images WHERE id = ?")->execute([$id]);
                logActivity($pdo, $cid, 'tire_image', $id, 'deleted', 'admin',
                    'Deleted ' . imageDisplayLabel($row) . ' from ' . (string)($row['tire_name'] ?? 'a tire') . ' forever (from the Trash)', null, newBatchId());
                if ($path !== null && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mov') $unlink(preg_replace('/\.mov$/i', '.mp4', $path));
                $unlink($path);
                if ($thumb !== null && is_file($thumb) && !is_link($thumb) && $shared === false && @unlink($thumb)) $files++;
                break;
            }
            case 'library_image': {
                $s = $pdo->prepare("SELECT slug FROM companies WHERE id = ?");
                $s->execute([$cid]);
                $slug = (string)($s->fetchColumn() ?: '');
                $file = (string)$row['filename'];
                $path = null;
                if ($slug !== '' && $file !== '' && $file === basename($file) && $file[0] !== '.') {
                    $dir = libraryDir($slug);
                    $cand = $dir . '/' . $file;
                    $real = is_file($cand) ? realpath($cand) : false;
                    $dirR = realpath($dir);
                    if ($real !== false && $dirR !== false && dirname($real) === rtrim($dirR, '/')) $path = $real;
                }
                $pdo->prepare("DELETE FROM library_images WHERE id = ?")->execute([$id]);
                logActivity($pdo, $cid, 'library_image', $id, 'deleted', 'admin', 'Deleted a Library image forever (from the Trash)', null, newBatchId());
                if ($path !== null && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mov') {
                    foreach (['.mp4', '.MP4'] as $e) $unlink(substr($path, 0, -4) . $e);
                }
                $unlink($path);
                break;
            }
        }
        return ['ok' => true, 'files' => $files, 'kept' => $kept];
    }
}

if (!function_exists('trashItemCompany')) {
    /** The client an item belongs to (0 = not found) — a deep-link notice only ever speaks about the page's own client. */
    function trashItemCompany(PDO $pdo, string $type, int $id): int {
        $r = trashItemRow($pdo, $type, $id);
        return $r ? (int)$r['company_id'] : 0;
    }
}

if (!function_exists('trashKeepsSharedMedia')) {
    /** On staging (gmail-lib.php portalEnvironment()) Delete forever never unlinks a file under the shared media/ folder. */
    function trashKeepsSharedMedia(): bool {
        if (!function_exists('portalEnvironment') && is_file(__DIR__ . '/gmail-lib.php')) require_once __DIR__ . '/gmail-lib.php';
        return function_exists('portalEnvironment') && portalEnvironment() === 'staging';
    }
}

if (!function_exists('trashCount')) {
    /** Items in the Trash — one client ($companyId) or every client (null); per type with 'total'. */
    function trashCounts(?PDO $pdo, ?int $companyId = null): array {
        $out = ['post' => 0, 'email' => 0, 'page' => 0, 'tire_image' => 0, 'library_image' => 0, 'total' => 0];
        if (!$pdo || !trashReady($pdo, 'post')) return $out;
        foreach (trashTypes() as $type => $t) {
            if (!trashReady($pdo, $type)) continue;
            try {
                if ($type === 'tire_image') {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.trashed_at IS NOT NULL" . ($companyId ? ' AND t.company_id = ?' : ''));
                } else {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM {$t['table']} WHERE trashed_at IS NOT NULL" . ($companyId ? ' AND company_id = ?' : ''));
                }
                $s->execute($companyId ? [$companyId] : []);
                $out[$type] = (int)$s->fetchColumn();
            } catch (Throwable $e) { error_log('trashCounts: ' . $e->getMessage()); }
        }
        $out['total'] = $out['post'] + $out['email'] + $out['page'] + $out['tire_image'] + $out['library_image'];
        return $out;
    }
    function trashCount(?PDO $pdo, ?int $companyId = null): int { return trashCounts($pdo, $companyId)['total']; }
}

if (!function_exists('trashPrevStatus')) {
    /** The state an item returns to on Restore, in Joust's words: [key for statusPill(), label]. */
    function trashPrevStatus(array $it): array {
        $st = (string)($it['status'] ?? '');
        if (!empty($it['posted'])) return ['posted', 'Scheduled'];
        if (!empty($it['live'])) return ['live', 'Live'];
        $map = ['draft' => 'Draft', 'pending' => 'To Review', 'approved' => 'Approved', 'denied' => 'Needs changes'];
        return [$st, $map[$st] ?? ucfirst($st)];
    }
}

if (!function_exists('trashItems')) {
    /**
     * The Trash (Joust's view): every trashed item of one client ($companyId) or every client (null), grouped by client
     * then type, newest trashed first inside a group. Each item:
     *   type, id, key ("post:3"), company_id, company_name, company_slug, title, context, thumb (URL or ''), video,
     *   original (the file, or ''), status, prev_key, prev_label, trashed_at, trashed_by_name, trash_note,
     *   note (the client's latest visible comment: ['text', 'at'] or null)
     */
    function trashItems(PDO $pdo, ?int $companyId = null): array {
        if (!trashReady($pdo, 'post')) return [];
        $base = function_exists('basePath') ? basePath() : '';
        $rootUrl = static function (string $u) use ($base): string {
            $u = trim($u);
            if ($u === '') return '';
            return (preg_match('#^(https?:)?//#i', $u) || $u[0] === '/') ? $u : $base . '/' . ltrim($u, '/');
        };
        $co = static function (string $col) use ($companyId): string { return $companyId ? " AND {$col} = " . (int)$companyId : ''; };
        $items = [];
        try {
            // Posts
            $nameSel = function_exists('hasPostsNameColumn') && hasPostsNameColumn($pdo) ? 'p.name' : "'' AS name";
            $postedSel = function_exists('hasPostedColumn') && hasPostedColumn($pdo) ? 'p.posted' : '0 AS posted';
            foreach ($pdo->query("SELECT p.id, p.company_id, p.caption, p.status, {$nameSel}, {$postedSel}, p.trashed_at, p.trashed_by, p.trash_note, c.name AS company_name, c.slug AS company_slug,
                                         (SELECT pi.image_url FROM post_images pi WHERE pi.post_id = p.id ORDER BY pi.sort_order ASC, pi.id ASC LIMIT 1) AS thumb_url
                                    FROM posts p INNER JOIN companies c ON c.id = p.company_id WHERE p.trashed_at IS NOT NULL" . $co('p.company_id'))->fetchAll() as $r) {
                $u = (string)($r['thumb_url'] ?? '');
                $isV = $u !== '' && function_exists('mediaTypeFromUrl') && mediaTypeFromUrl($u) === 'video';
                $items[] = ['type' => 'post', 'id' => (int)$r['id'], 'row' => $r,
                            'title' => postDisplayLabel(['name' => $r['name'] ?? '', 'caption' => $r['caption'] ?? '', 'id' => (int)$r['id']]),
                            'context' => 'Post', 'thumb' => $isV ? '' : $rootUrl($u), 'video' => $isV, 'original' => $rootUrl($u)];
            }
            if (trashReady($pdo, 'email') && function_exists('hasEmailsTable') && hasEmailsTable($pdo)) {
                foreach ($pdo->query("SELECT e.*, c.name AS company_name, c.slug AS company_slug FROM emails e INNER JOIN companies c ON c.id = e.company_id WHERE e.trashed_at IS NOT NULL" . $co('e.company_id'))->fetchAll() as $r) {
                    $code = trim((string)($r['code'] ?? ''));
                    $items[] = ['type' => 'email', 'id' => (int)$r['id'], 'row' => $r, 'title' => function_exists('emailDisplayLabel') ? emailDisplayLabel($r) : 'Email #' . (int)$r['id'],
                                'context' => 'Email' . ($code !== '' ? ' · ' . $code : ''), 'thumb' => '', 'video' => false, 'original' => ''];
                }
            }
            if (trashReady($pdo, 'page') && function_exists('hasPagesTable') && hasPagesTable($pdo)) {
                foreach ($pdo->query("SELECT p.*, c.name AS company_name, c.slug AS company_slug FROM pages p INNER JOIN companies c ON c.id = p.company_id WHERE p.trashed_at IS NOT NULL" . $co('p.company_id'))->fetchAll() as $r) {
                    $ps = trim((string)($r['slug'] ?? ''));
                    $items[] = ['type' => 'page', 'id' => (int)$r['id'], 'row' => $r,
                                'title' => trim((string)($r['title'] ?? '')) !== '' ? trim((string)$r['title']) : ($ps !== '' ? '/' . $ps : 'Page #' . (int)$r['id']),
                                'context' => 'Page' . ($ps !== '' ? ' · /' . $ps : ''), 'thumb' => '', 'video' => false, 'original' => ''];
                }
            }
            if (trashReady($pdo, 'tire_image')) {
                $seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);
                $dn = function_exists('tireImagesHaveDisplayName') && tireImagesHaveDisplayName($pdo) ? 'ti.display_name' : "'' AS display_name";
                $sql = "SELECT ti.id, ti.tire_id, ti.image_url, ti.caption, ti.status, {$dn}, " . ($seriesOn ? 'ti.series_id, s.name AS series_name' : 'NULL AS series_id, NULL AS series_name') . ",
                               ti.trashed_at, ti.trashed_by, ti.trash_note, t.name AS tire_name, t.company_id, c.name AS company_name, c.slug AS company_slug
                          FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id INNER JOIN companies c ON c.id = t.company_id"
                     . ($seriesOn ? ' LEFT JOIN tire_series s ON s.id = ti.series_id' : '') . " WHERE ti.trashed_at IS NOT NULL" . $co('t.company_id');
                foreach ($pdo->query($sql)->fetchAll() as $r) {
                    $src = $rootUrl(function_exists('tireImageSrc') ? (string)tireImageSrc($r) : (string)$r['image_url']);
                    $ext = strtolower(pathinfo((string)(parse_url((string)$r['image_url'], PHP_URL_PATH) ?: $r['image_url']), PATHINFO_EXTENSION));
                    $isV = function_exists('isVideoExt') && isVideoExt($ext);
                    $where = $r['series_id'] !== null ? (string)($r['series_name'] ?? ('Series ' . (int)$r['series_id'])) : 'Reference';
                    $label = imageDisplayLabel(['display_name' => $r['display_name'] ?? '', 'caption' => $r['caption'] ?? '', 'id' => (int)$r['id']]);
                    if (stripos($label, 'image #') === 0) $label = basename((string)parse_url((string)$r['image_url'], PHP_URL_PATH));
                    $items[] = ['type' => 'tire_image', 'id' => (int)$r['id'], 'row' => $r, 'title' => $label,
                                'context' => (string)$r['tire_name'] . ' · ' . $where, 'thumb' => $isV ? '' : $src, 'video' => $isV, 'original' => $src];
                }
            }
            if (trashReady($pdo, 'library_image') && function_exists('hasLibraryImagesTable') && hasLibraryImagesTable($pdo)) {
                foreach ($pdo->query("SELECT li.id, li.company_id, li.filename, li.status, li.trashed_at, li.trashed_by, li.trash_note, c.name AS company_name, c.slug AS company_slug
                                        FROM library_images li INNER JOIN companies c ON c.id = li.company_id WHERE li.trashed_at IS NOT NULL" . $co('li.company_id'))->fetchAll() as $r) {
                    $src = libraryFileUrl((string)$r['company_slug'], (string)$r['filename']);
                    $isV = function_exists('isVideoExt') && isVideoExt(strtolower(pathinfo((string)$r['filename'], PATHINFO_EXTENSION)));
                    $items[] = ['type' => 'library_image', 'id' => (int)$r['id'], 'row' => $r, 'title' => (string)$r['filename'],
                                'context' => 'Assets · Library', 'thumb' => $isV ? '' : $src, 'video' => $isV, 'original' => $src];
                }
            }
        } catch (Throwable $e) {
            error_log('trashItems: ' . $e->getMessage());
            return [];
        }
        // the client's latest visible note per item (one query per type), who trashed it
        $byType = [];
        foreach ($items as $i => $it) $byType[$it['type']][$i] = $it['id'];
        $notes = [];
        if (function_exists('hasActivityLog') && hasActivityLog($pdo)) {
            $internal = function_exists('activityHasNotifyCols') && activityHasNotifyCols($pdo) ? ' AND internal = 0' : '';
            foreach ($byType as $type => $map) {
                foreach (array_chunk(array_values(array_unique($map)), 500) as $chunk) {
                    $s = $pdo->prepare("SELECT entity_id, detail, created_at FROM activity_log WHERE entity_type = ? AND action = 'commented' AND actor = 'client'
                                          AND detail IS NOT NULL AND detail <> ''{$internal} AND entity_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ") ORDER BY id ASC");
                    $s->execute(array_merge([$type], $chunk));
                    foreach ($s->fetchAll() as $c) $notes[$type . ':' . (int)$c['entity_id']] = ['text' => (string)$c['detail'], 'at' => (string)$c['created_at']];
                }
            }
        }
        $names = [];
        $out = [];
        foreach ($items as $it) {
            $r = $it['row'];
            $by = $r['trashed_by'] !== null ? (int)$r['trashed_by'] : 0;
            if ($by > 0 && !isset($names[$by])) { $u = function_exists('adminUserById') ? adminUserById($pdo, $by) : null; $names[$by] = $u && function_exists('adminUserFirstName') ? adminUserFirstName($u) : 'Joust'; }
            [$pk, $pl] = trashPrevStatus($r);
            $note = $notes[$it['type'] . ':' . $it['id']] ?? null;
            if ($note && function_exists('commentSlideHuman')) $note['text'] = commentSlideHuman($note['text']);
            $out[] = ['type' => $it['type'], 'id' => $it['id'], 'key' => $it['type'] . ':' . $it['id'], 'company_id' => (int)$r['company_id'],
                      'company_name' => (string)$r['company_name'], 'company_slug' => (string)$r['company_slug'], 'title' => (string)$it['title'],
                      'context' => (string)$it['context'], 'thumb' => (string)$it['thumb'], 'video' => (bool)$it['video'], 'original' => (string)$it['original'],
                      'status' => (string)$r['status'], 'prev_key' => $pk, 'prev_label' => $pl, 'trashed_at' => (string)$r['trashed_at'],
                      'trashed_by_name' => $by > 0 ? $names[$by] : 'Joust', 'trash_note' => (string)($r['trash_note'] ?? ''), 'note' => $note];
        }
        $order = ['post' => 0, 'email' => 1, 'page' => 2, 'tire_image' => 3, 'library_image' => 4];
        usort($out, static function ($a, $b) use ($order) {
            return [strtolower($a['company_name']), $a['company_id'], $order[$a['type']]] <=> [strtolower($b['company_name']), $b['company_id'], $order[$b['type']]]
                ?: (strcmp($b['trashed_at'], $a['trashed_at']) ?: ($b['id'] <=> $a['id']));
        });
        return $out;
    }
}

if (!function_exists('trashTypeGroupLabel')) {
    /** The Trash page's group heading for a type. */
    function trashTypeGroupLabel(string $type, ?array $company = null): string {
        switch ($type) {
            case 'post': return 'Posts';
            case 'email': return 'Emails';
            case 'page': return 'Pages';
            case 'tire_image': return function_exists('tiresLabel') ? tiresLabel($company) : 'Tires';
            case 'library_image': return 'Library';
        }
        return ucfirst($type);
    }
}

if (!function_exists('trashUnavailableHtml')) {
    /** The neutral "no longer available" a client gets for a trashed item (the admin: "in the Trash" + a link). */
    function trashUnavailableHtml(bool $admin, string $noun = 'item', ?array $client = null): string {
        if (!$admin) return '<div class="ui-empty" data-item-unavailable>This ' . htmlspecialchars($noun, ENT_QUOTES, 'UTF-8') . ' is no longer available.</div>';
        $href = function_exists('portalUrl') ? portalUrl('trash', $client ? ['client' => (string)$client['slug']] : []) : 'trash.php';
        return '<div class="ui-empty" data-item-trashed>This ' . htmlspecialchars($noun, ENT_QUOTES, 'UTF-8') . ' is in the Trash. <a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">Open Trash</a> to restore it.</div>';
    }
}

if (!function_exists('trashLinkHtml')) {
    /** The small admin "Trash (N)" link (Home, Assets, Posts …): '' when the Trash of this scope is empty or not set up. */
    function trashLinkHtml(?PDO $pdo, ?array $client, string $class = ''): string {
        if (!$pdo || !(function_exists('isAdmin') && isAdmin()) || !trashReady($pdo, 'post')) return '';
        $n = trashCount($pdo, $client ? (int)$client['id'] : null);
        if ($n <= 0) return '';
        $href = portalUrl('trash', $client ? ['client' => (string)$client['slug']] : []);
        return '<a class="trash-link' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') : '') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" data-trash-link>'
             . icon('trash') . '<span>Trash (<span data-trash-link-count>' . $n . '</span>)</span></a>';
    }
}

if (!function_exists('trashGuardJson')) {
    /**
     * Endpoints acting on one item: a trashed item answers JSON and stops — 404 "no longer available" for the client
     * seat (as if it did not exist), 409 "in the Trash — restore it first" for Joust. No-op otherwise / before migrate.
     */
    function trashGuardJson(?PDO $pdo, string $type, int $id, bool $admin): void {
        if ($id <= 0 || !trashIsTrashed($pdo, $type, $id)) return;
        if (!headers_sent()) {
            http_response_code($admin ? 409 : 404);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
        }
        echo json_encode($admin ? ['ok' => false, 'trashed' => true, 'error' => 'This item is in the Trash — restore it first.']
                                : ['ok' => false, 'error' => 'This item is no longer available.']);
        exit;
    }
}

if (!function_exists('trashMenuItemHtml')) {
    /** The admin ⋯ "Move to Trash…" item of a post / email / page sheet ('' for the client seat or before migrate). */
    function trashMenuItemHtml(string $type, int $id, bool $sep = true): string {
        $type = trashNormType($type);
        if ($type === '' || $id <= 0 || !(function_exists('isAdmin') && isAdmin()) || !trashReady($GLOBALS['pdo'] ?? null, $type)) return '';
        return ($sep ? '<div class="pd-menu-sep" role="separator"></div>' : '')
             . '<button type="button" role="menuitem" data-trash-item="' . $type . ':' . $id . '">Move to Trash…</button>';
    }
}
