<?php
/**
 * The Redo queue — images Joust has to make again (loaded by helpers.php; never include directly).
 *
 * A queue apart from the client's review status: tire images (series renders + reference images) and library images
 * carry redo_at / redo_note / redo_by / redo_exported_at (migrate.php 52). redo_at IS NOT NULL = in the queue. The
 * status column is never given a new value, so the client's To Review / Approved / Needs changes and every badge and
 * count keep working; the client sees a queued image that is not in Needs changes as "Being reworked" (and never the
 * note — redo_note is Joust-only, like an internal comment).
 *
 *   in   redoMark()        admin: viewer ⋯ "Mark for redo…", the select bar, redo.php action=mark (+ optional note:
 *                          stored in redo_note AND as an internal comment on the image)
 *        redoAutoQueue()   a "Needs changes" decision (tire-status.php / library-status.php) queues the image
 *   out  redoAfterReplace() a replacement file (replace-image.php, upload-chunk.php purpose=replace, redo.php "Replace
 *                          from folder") clears the flag and sends the image back to To Review (reset_pending →
 *                          the client's "Ready for your review" email, client-notify-lib.php)
 *        redoClear()       "Remove from redo"; a delete / move takes the row (and its flag) with it
 *
 *   redoItems()  the queue (one client or all), with the client's feedback, for the Redo view and the redo pack
 *   redoCount()  the badge on Home, Assets / Tires and the Redo view
 *
 * Every function is function_exists-guarded, does no work at load, and is gated on redoReady() / redoKindReady() so
 * a deploy that has not run migrate.php behaves exactly as before.
 */

if (!function_exists('redoColumnExists')) {
    /** (internal) Does <table>.<column> exist? Cached per request. */
    function redoColumnExists(PDO $pdo, string $table, string $column): bool {
        static $cache = [];
        $k = $table . '.' . $column;
        if (array_key_exists($k, $cache)) return $cache[$k];
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $s->execute([$table, $column]);
            return $cache[$k] = (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return $cache[$k] = false;
        }
    }
}

if (!function_exists('redoReady')) {
    /** tire_images.redo_at exists (migrate.php 52)? The feature's gate. */
    function redoReady(?PDO $pdo = null): bool {
        $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
        return $pdo instanceof PDO && redoColumnExists($pdo, 'tire_images', 'redo_at');
    }
}

if (!function_exists('redoKindReady')) {
    /** The kind's table carries the redo columns ('tire' | 'library'). */
    function redoKindReady(PDO $pdo, string $kind): bool {
        if ($kind === 'tire') return redoReady($pdo);
        if ($kind === 'library') return redoReady($pdo) && redoColumnExists($pdo, 'library_images', 'redo_at');
        return false;
    }
}

if (!function_exists('redoTable')) {
    function redoTable(string $kind): string { return $kind === 'library' ? 'library_images' : 'tire_images'; }
}
if (!function_exists('redoEntityType')) {
    function redoEntityType(string $kind): string { return $kind === 'library' ? 'library_image' : 'tire_image'; }
}
if (!function_exists('redoLabel')) {
    /** What a seat calls a queued image: Joust "Redo", the client "Being reworked" (never the note). */
    function redoLabel(bool $admin): string { return $admin ? 'Redo' : 'Being reworked'; }
}

if (!function_exists('redoKeepUpdated')) {
    /** (internal) ', updated_at = updated_at' when the table has the auto-bumping column: a queue change is not an edit of the image. */
    function redoKeepUpdated(PDO $pdo, string $table): string {
        return redoColumnExists($pdo, $table, 'updated_at') ? ', updated_at = updated_at' : '';
    }
}

if (!function_exists('redoParseItems')) {
    /** "tire:12,library:4" (or an array of those) → [['kind' => 'tire', 'id' => 12], …] — unique, ≤ 500. */
    function redoParseItems($raw): array {
        $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = []; $seen = [];
        foreach ((array)$list as $ref) {
            if (!is_scalar($ref) || !preg_match('/^(tire|library):(\d{1,10})$/', trim((string)$ref), $m) || (int)$m[2] <= 0) continue;
            $k = $m[1] . ':' . (int)$m[2];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = ['kind' => $m[1], 'id' => (int)$m[2]];
            if (count($out) >= 500) break;
        }
        return $out;
    }
}

if (!function_exists('redoItemRow')) {
    /** One image with its company and redo columns: {kind, id, company_id, status, redo_at, redo_note, redo_by, redo_exported_at, …}; null when unknown. */
    function redoItemRow(PDO $pdo, string $kind, int $id): ?array {
        if ($id <= 0 || !redoKindReady($pdo, $kind)) return null;
        if ($kind === 'tire') {
            $s = $pdo->prepare("SELECT ti.id, ti.tire_id, ti.image_url, ti.status, ti.redo_at, ti.redo_note, ti.redo_by, ti.redo_exported_at, t.company_id
                                  FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.id = ?");
        } else {
            $s = $pdo->prepare("SELECT id, filename, status, redo_at, redo_note, redo_by, redo_exported_at, company_id FROM library_images WHERE id = ?");
        }
        $s->execute([$id]);
        $r = $s->fetch();
        if (!$r) return null;
        $r['kind'] = $kind;
        $r['company_id'] = (int)$r['company_id'];
        return $r;
    }
}

if (!function_exists('redoMark')) {
    /**
     * Put an image in the queue (or update its note when it is already there). $opts: auto (bool — a client's Needs
     * changes: no note, no author, nothing logged), batch (activity batch id). A manual mark logs an internal
     * 'redo_marked' row and, with a note, an internal comment "Redo: <note>" (Joust-only everywhere). Re-marking an image
     * that already went out in a redo pack makes it "new" again for "only new since last export" (its queue time stays).
     * Returns ['ok' => bool, 'queued' => bool (newly queued), 'row' => the row before] or ['ok' => false, 'error' => …].
     */
    function redoMark(PDO $pdo, string $kind, int $id, string $note = '', array $opts = []): array {
        $row = redoItemRow($pdo, $kind, $id);
        if (!$row) return ['ok' => false, 'error' => 'Image not found'];
        $auto  = !empty($opts['auto']);
        $note  = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $note));
        if (function_exists('mb_substr')) $note = mb_substr($note, 0, 500, 'UTF-8'); else $note = substr($note, 0, 500);
        $table = redoTable($kind);
        $keep  = redoKeepUpdated($pdo, $table);
        $by    = (!$auto && function_exists('currentAdminUserId')) ? currentAdminUserId($pdo) : null;
        $was   = $row['redo_at'] !== null;
        if ($auto && $was) return ['ok' => true, 'queued' => false, 'row' => $row];
        if (!$was) {
            $pdo->prepare("UPDATE {$table} SET redo_at = NOW(), redo_note = ?, redo_by = ?, redo_exported_at = NULL{$keep} WHERE id = ?")
                ->execute([$note !== '' ? $note : null, $by, $id]);
        } else {
            $pdo->prepare("UPDATE {$table} SET redo_note = COALESCE(?, redo_note), redo_by = COALESCE(?, redo_by), redo_exported_at = NULL{$keep}
                            WHERE id = ?")
                ->execute([$note !== '' ? $note : null, $by, $id]);
        }
        if (!$auto) {
            $batch = $opts['batch'] ?? newBatchId();
            $type  = redoEntityType($kind);
            $cid   = (int)$row['company_id'];
            $log = static function () use ($pdo, $cid, $type, $id, $note, $batch, $was) {
                logActivity($pdo, $cid, $type, $id, 'redo_marked', 'admin',
                    ($was ? 'Updated the redo note' : 'Marked for redo') . ($note !== '' ? ': ' . $note : ''), null, $batch);
                if ($note !== '') logActivity($pdo, $cid, $type, $id, 'commented', 'admin', 'Redo note', 'Redo: ' . $note, $batch);
            };
            if (function_exists('activityWithContext')) activityWithContext(['internal' => 1], $log); else $log();
        }
        return ['ok' => true, 'queued' => !$was, 'row' => $row];
    }
}

if (!function_exists('redoAutoQueue')) {
    /** A "Needs changes" decision: the image joins the queue (quietly — the decision row already says it). Never throws. */
    function redoAutoQueue(PDO $pdo, string $kind, int $id): bool {
        try {
            if (!redoKindReady($pdo, $kind)) return false;
            $r = redoMark($pdo, $kind, $id, '', ['auto' => true]);
            return !empty($r['queued']);
        } catch (Throwable $e) {
            error_log('redoAutoQueue: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('redoClear')) {
    /** Take an image off the queue. $log: an internal 'redo_cleared' row (the manual "Remove from redo"). Returns whether it was queued. */
    function redoClear(PDO $pdo, string $kind, int $id, bool $log = true, ?string $batch = null): bool {
        $row = redoItemRow($pdo, $kind, $id);
        if (!$row || $row['redo_at'] === null) return false;
        $table = redoTable($kind);
        $pdo->prepare("UPDATE {$table} SET redo_at = NULL, redo_note = NULL, redo_by = NULL, redo_exported_at = NULL" . redoKeepUpdated($pdo, $table) . " WHERE id = ?")
            ->execute([$id]);
        if ($log) {
            $fn = static function () use ($pdo, $row, $kind, $id, $batch) {
                logActivity($pdo, (int)$row['company_id'], redoEntityType($kind), $id, 'redo_cleared', 'admin', 'Removed from the redo list', null, $batch ?? newBatchId());
            };
            if (function_exists('activityWithContext')) activityWithContext(['internal' => 1], $fn); else $fn();
        }
        return true;
    }
}

if (!function_exists('redoAfterReplace')) {
    /**
     * A replacement file landed on a queued image: off the queue, back to To Review, and a 'reset_pending' row by Joust
     * (the client's "Ready for your review" email and the Slack parent hear of it). Not queued → null (nothing changes:
     * replacing an ordinary image keeps its status, as before). Never throws.
     */
    function redoAfterReplace(PDO $pdo, string $kind, int $id): ?array {
        try {
            if (!in_array($kind, ['tire', 'library'], true) || !redoKindReady($pdo, $kind)) return null;
            $row = redoItemRow($pdo, $kind, $id);
            if (!$row || $row['redo_at'] === null) return null;
            $table = redoTable($kind);
            $pdo->prepare("UPDATE {$table} SET status = 'pending', redo_at = NULL, redo_note = NULL, redo_by = NULL, redo_exported_at = NULL WHERE id = ?")
                ->execute([$id]);
            logActivity($pdo, (int)$row['company_id'], redoEntityType($kind), $id, 'reset_pending', 'admin',
                $kind === 'library' ? 'Sent a reworked image in Library for review' : 'Sent a reworked image for review', null, newBatchId());
            return ['cleared' => true, 'status' => 'pending', 'prev_status' => (string)$row['status']];
        } catch (Throwable $e) {
            error_log('redoAfterReplace: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('redoFlags')) {
    /** [id => ['at' => redo_at, 'note' => redo_note]] for the queued ones among $ids (one query; [] before migrate.php 52). */
    function redoFlags(PDO $pdo, string $kind, array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; })));
        if (!$ids || !redoKindReady($pdo, $kind)) return [];
        $out = [];
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT id, redo_at, redo_note FROM " . redoTable($kind) . " WHERE id IN ($ph) AND redo_at IS NOT NULL");
            $s->execute($ids);
            foreach ((array)$s->fetchAll() as $r) $out[(int)$r['id']] = ['at' => (string)$r['redo_at'], 'note' => (string)($r['redo_note'] ?? '')];
        } catch (Throwable $e) { error_log('redoFlags: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('redoCount')) {
    /** Images in the queue — one client ($companyId) or every client (null). */
    function redoCount(PDO $pdo, ?int $companyId = null): int {
        if (!redoReady($pdo)) return 0;
        $n = 0;
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.redo_at IS NOT NULL" . ($companyId ? ' AND t.company_id = ?' : ''));
            $s->execute($companyId ? [$companyId] : []);
            $n += (int)$s->fetchColumn();
            if (redoKindReady($pdo, 'library')) {
                $s = $pdo->prepare("SELECT COUNT(*) FROM library_images WHERE redo_at IS NOT NULL" . ($companyId ? ' AND company_id = ?' : ''));
                $s->execute($companyId ? [$companyId] : []);
                $n += (int)$s->fetchColumn();
            }
        } catch (Throwable $e) { error_log('redoCount: ' . $e->getMessage()); }
        return $n;
    }
}

if (!function_exists('redoWhoLabel')) {
    /** (internal) Who wrote a comment row, for Joust's eyes: "Jane Kenda (Kenda Tires)" / the client / "Lance" / "Joust". */
    function redoWhoLabel(PDO $pdo, array $c, string $companyName): string {
        if (($c['actor'] ?? '') === 'client') {
            $l = function_exists('clientContactLabel') ? clientContactLabel($pdo, $c['client_contact_id'] ?? null) : '';
            return $l !== '' ? $l : ($companyName !== '' ? $companyName : 'Client');
        }
        $u = (function_exists('adminUserById') && (int)($c['author_user_id'] ?? 0) > 0) ? adminUserById($pdo, (int)$c['author_user_id']) : null;
        $n = $u && function_exists('adminUserFirstName') ? adminUserFirstName($u) : 'Joust';
        return $n . (!empty($c['internal']) ? ' (internal)' : '');
    }
}

if (!function_exists('redoItems')) {
    /**
     * The queue, in folder order (client, tire, Reference before the series in their order, Library last), oldest first
     * inside a folder. $companyId null = every client. $opts: since (bool — only what has not gone out in a redo pack
     * since it was queued), refs (['tire' => ids, 'library' => ids] — only those), feedback (bool, default true — attach
     * the comment thread). Each item:
     *   kind, id, company_id, company_name, company_slug, tire_id, tire_name, series_id, series_name, series_sort,
     *   filename, image_url, src, type, status, redo_at, redo_note, redo_by, redo_by_name, redo_exported_at, path (abs or null),
     *   thread [{who, actor, internal, text, at}], feedback (the latest client comment, '' when none), feedback_at, link (absolute)
     */
    function redoItems(PDO $pdo, ?int $companyId = null, array $opts = []): array {
        if (!redoReady($pdo)) return [];
        $since = !empty($opts['since']) ? " AND (%s.redo_exported_at IS NULL OR %s.redo_exported_at < %s.redo_at)" : '';
        $refs  = isset($opts['refs']) && is_array($opts['refs']) ? $opts['refs'] : null;
        $items = [];
        $seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);
        $nameSel  = function_exists('tireImagesHaveDisplayName') && tireImagesHaveDisplayName($pdo) ? 'ti.display_name' : "'' AS display_name";
        try {
            if ($refs === null || !empty($refs['tire'])) {
                $sql = "SELECT ti.id, ti.tire_id, " . ($seriesOn ? 'ti.series_id' : 'NULL AS series_id') . ", ti.image_url, ti.status, ti.sort_order, {$nameSel}, ti.client_comment,
                               ti.redo_at, ti.redo_note, ti.redo_by, ti.redo_exported_at, t.name AS tire_name, t.company_id, c.name AS company_name, c.slug AS company_slug"
                     . ($seriesOn ? ", s.name AS series_name, s.sort_order AS series_sort" : ", NULL AS series_name, 0 AS series_sort") . "
                          FROM tire_images ti
                          INNER JOIN tires t ON t.id = ti.tire_id
                          INNER JOIN companies c ON c.id = t.company_id"
                     . ($seriesOn ? " LEFT JOIN tire_series s ON s.id = ti.series_id" : '') . "
                         WHERE ti.redo_at IS NOT NULL" . ($companyId ? ' AND t.company_id = ?' : '') . ($since !== '' ? sprintf($since, 'ti', 'ti', 'ti') : '');
                $p = $companyId ? [$companyId] : [];
                if ($refs !== null) { $ids = array_values(array_map('intval', $refs['tire'])); $sql .= ' AND ti.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $p = array_merge($p, $ids); }
                $s = $pdo->prepare($sql);
                $s->execute($p);
                foreach ((array)$s->fetchAll() as $r) {
                    $url = (string)$r['image_url'];
                    $ext = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                    $items[] = [
                        'kind' => 'tire', 'id' => (int)$r['id'], 'company_id' => (int)$r['company_id'], 'company_name' => (string)$r['company_name'],
                        'company_slug' => (string)$r['company_slug'], 'tire_id' => (int)$r['tire_id'], 'tire_name' => (string)$r['tire_name'],
                        'series_id' => $r['series_id'] !== null ? (int)$r['series_id'] : null, 'series_name' => $r['series_id'] !== null ? (string)($r['series_name'] ?? ('Series ' . (int)$r['series_id'])) : '',
                        'series_sort' => $r['series_id'] !== null ? (int)($r['series_sort'] ?? 0) : -1, 'sort_order' => (int)$r['sort_order'],
                        'filename' => basename((string)parse_url($url, PHP_URL_PATH) ?: $url), 'display_name' => (string)($r['display_name'] ?? ''),
                        'image_url' => $url, 'src' => function_exists('tireImageSrc') ? tireImageSrc($r) : $url,
                        'type' => function_exists('isVideoExt') && isVideoExt($ext) ? 'video' : 'image', 'ext' => $ext,
                        'status' => (string)$r['status'], 'client_comment' => (string)($r['client_comment'] ?? ''),
                        'redo_at' => (string)$r['redo_at'], 'redo_note' => (string)($r['redo_note'] ?? ''), 'redo_by' => $r['redo_by'] !== null ? (int)$r['redo_by'] : null,
                        'redo_exported_at' => $r['redo_exported_at'] !== null ? (string)$r['redo_exported_at'] : null,
                        'path' => function_exists('tireImagePath') ? tireImagePath($r) : null,
                    ];
                }
            }
            if (redoKindReady($pdo, 'library') && ($refs === null || !empty($refs['library']))) {
                $sql = "SELECT li.id, li.filename, li.status, li.redo_at, li.redo_note, li.redo_by, li.redo_exported_at, li.company_id, c.name AS company_name, c.slug AS company_slug
                          FROM library_images li INNER JOIN companies c ON c.id = li.company_id
                         WHERE li.redo_at IS NOT NULL" . ($companyId ? ' AND li.company_id = ?' : '') . ($since !== '' ? sprintf($since, 'li', 'li', 'li') : '');
                $p = $companyId ? [$companyId] : [];
                if ($refs !== null) { $ids = array_values(array_map('intval', $refs['library'])); $sql .= ' AND li.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $p = array_merge($p, $ids); }
                $s = $pdo->prepare($sql);
                $s->execute($p);
                foreach ((array)$s->fetchAll() as $r) {
                    $file = (string)$r['filename'];
                    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $path = libraryDir((string)$r['company_slug']) . '/' . $file;
                    $items[] = [
                        'kind' => 'library', 'id' => (int)$r['id'], 'company_id' => (int)$r['company_id'], 'company_name' => (string)$r['company_name'],
                        'company_slug' => (string)$r['company_slug'], 'tire_id' => 0, 'tire_name' => '', 'series_id' => null, 'series_name' => '',
                        'series_sort' => 0, 'sort_order' => 0, 'filename' => $file, 'display_name' => '', 'image_url' => $file,
                        'src' => libraryFileUrl((string)$r['company_slug'], $file),
                        'type' => function_exists('isVideoExt') && isVideoExt($ext) ? 'video' : 'image', 'ext' => $ext,
                        'status' => (string)$r['status'], 'client_comment' => '',
                        'redo_at' => (string)$r['redo_at'], 'redo_note' => (string)($r['redo_note'] ?? ''), 'redo_by' => $r['redo_by'] !== null ? (int)$r['redo_by'] : null,
                        'redo_exported_at' => $r['redo_exported_at'] !== null ? (string)$r['redo_exported_at'] : null,
                        'path' => (is_file($path) && !is_link($path)) ? $path : null,
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('redoItems: ' . $e->getMessage());
            return [];
        }
        usort($items, static function ($a, $b) {
            return [strtolower($a['company_name']), $a['company_id'], $a['kind'] === 'library' ? 1 : 0, strtolower($a['tire_name']), $a['tire_id'], $a['series_sort'], $a['sort_order'], $a['redo_at'], $a['id']]
               <=> [strtolower($b['company_name']), $b['company_id'], $b['kind'] === 'library' ? 1 : 0, strtolower($b['tire_name']), $b['tire_id'], $b['series_sort'], $b['sort_order'], $b['redo_at'], $b['id']];
        });
        // Who marked it, the comment thread (client feedback + Joust's notes, internal ones included — this is Joust's view)
        $byIds = array_values(array_unique(array_filter(array_map(static function ($i) { return (int)($i['redo_by'] ?? 0); }, $items))));
        $names = [];
        foreach ($byIds as $u) { $row = function_exists('adminUserById') ? adminUserById($pdo, $u) : null; $names[$u] = $row && function_exists('adminUserFirstName') ? adminUserFirstName($row) : 'Joust'; }
        $threads = ['tire_image' => [], 'library_image' => []];
        if (($opts['feedback'] ?? true) && $items && function_exists('hasActivityLog') && hasActivityLog($pdo)) {
            foreach (['tire' => 'tire_image', 'library' => 'library_image'] as $kind => $type) {
                $ids = array_values(array_map(static function ($i) { return (int)$i['id']; }, array_filter($items, static function ($i) use ($kind) { return $i['kind'] === $kind; })));
                foreach (array_chunk($ids, 500) as $chunk) {
                    $cols = function_exists('activityAuthorCols') ? activityAuthorCols($pdo) : ', NULL AS author_user_id, 0 AS internal, NULL AS client_contact_id';
                    $s = $pdo->prepare("SELECT entity_id, actor, detail, created_at{$cols} FROM activity_log
                                         WHERE entity_type = ? AND action = 'commented' AND detail IS NOT NULL AND detail <> ''
                                           AND entity_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                                         ORDER BY created_at ASC, id ASC");
                    $s->execute(array_merge([$type], $chunk));
                    foreach ((array)$s->fetchAll() as $c) $threads[$type][(int)$c['entity_id']][] = $c;
                }
            }
        }
        foreach ($items as &$it) {
            $it['redo_by_name'] = $it['redo_by'] ? ($names[$it['redo_by']] ?? 'Joust') : '';
            $thread = [];
            $feedback = ''; $feedbackAt = '';
            foreach ($threads[redoEntityType($it['kind'])][$it['id']] ?? [] as $c) {
                $text = trim((string)$c['detail']);
                $thread[] = ['who' => redoWhoLabel($pdo, $c, $it['company_name']), 'actor' => (string)$c['actor'], 'internal' => !empty($c['internal']),
                             'text' => $text, 'at' => (string)$c['created_at']];
                if ($c['actor'] === 'client' && empty($c['internal'])) { $feedback = $text; $feedbackAt = (string)$c['created_at']; }
            }
            // A legacy tire_images.client_comment that never made it into the thread still counts as the client's word.
            if ($it['client_comment'] !== '' && !in_array($it['client_comment'], array_column($thread, 'text'), true)) {
                array_unshift($thread, ['who' => $it['company_name'] !== '' ? $it['company_name'] : 'Client', 'actor' => 'client', 'internal' => false, 'text' => $it['client_comment'], 'at' => '']);
                if ($feedback === '') $feedback = $it['client_comment'];
            }
            $it['thread'] = $thread;
            $it['feedback'] = $feedback;
            $it['feedback_at'] = $feedbackAt;
            $meta = $it['kind'] === 'tire' ? ['tire_id' => $it['tire_id'], 'series_id' => (int)($it['series_id'] ?? 0)] : [];
            $it['link'] = function_exists('portalItemUrl') ? portalItemUrl(redoEntityType($it['kind']), $it['id'], $it['company_slug'], $meta) : '';
            $it['open'] = $it['kind'] === 'tire'
                ? clientUrl('assets.php', ['client' => $it['company_slug'], 'view' => 'collections', 'item' => $it['tire_id'], 'asset' => $it['id'], 'kind' => 'tire'])
                : clientUrl('assets.php', ['client' => $it['company_slug'], 'asset' => $it['id'], 'kind' => 'library']);
        }
        unset($it);
        return $items;
    }
}

if (!function_exists('redoMatchStem')) {
    /** (internal) The comparable stem of a file name: lower-case, no extension, "-2"-style copy suffixes and " (1)" dropped. */
    function redoMatchStem(string $name): string {
        $stem = strtolower(pathinfo(basename($name), PATHINFO_FILENAME));
        $stem = preg_replace('/\s*\(\d+\)$/', '', $stem);
        return trim((string)$stem);
    }
}

if (!function_exists('redoMatchFile')) {
    /**
     * "Replace from folder": which queued image does an uploaded file replace? $name = the file name, $relPath = the
     * browser's relative path inside the dropped folder ("Kenda Tires/Klever AT2/Series 1/render_06.jpg" — the redo
     * pack's own layout) or ''. Candidates = queued images (of $companyId, or every client) whose file has the same stem;
     * several → the one whose redo-pack path ends like $relPath. Returns ['item' => …] or ['error' => 'none'|'ambiguous', 'candidates' => n].
     */
    function redoMatchFile(PDO $pdo, ?int $companyId, string $name, string $relPath = ''): array {
        $want = redoMatchStem($name);
        if ($want === '') return ['error' => 'none', 'candidates' => 0];
        $cands = [];
        foreach (redoItems($pdo, $companyId, ['feedback' => false]) as $it) {
            if (redoMatchStem($it['filename']) === $want || ($it['display_name'] !== '' && redoMatchStem($it['display_name']) === $want)) $cands[] = $it;
        }
        if (count($cands) === 1) return ['item' => $cands[0]];
        if (!$cands) return ['error' => 'none', 'candidates' => 0];
        $rel = strtolower(trim(str_replace('\\', '/', $relPath), '/'));
        if ($rel !== '' && strpos($rel, '/') !== false) {
            $dir = strtolower(dirname($rel));
            $hits = array_values(array_filter($cands, static function ($it) use ($dir) {
                $folder = strtolower(redoPackFolder($it));
                return $folder === $dir || substr($dir, -strlen('/' . $folder)) === '/' . $folder || substr($folder, -strlen('/' . $dir)) === '/' . $dir;
            }));
            if (count($hits) === 1) return ['item' => $hits[0]];
            if (count($hits) > 1) {   // the longest folder match wins when one is strictly longer (Client/Tire/Series beats Tire/Series)
                $exact = array_values(array_filter($hits, static function ($it) use ($dir) { return strtolower(redoPackFolder($it)) === $dir; }));
                if (count($exact) === 1) return ['item' => $exact[0]];
            }
        }
        return ['error' => 'ambiguous', 'candidates' => count($cands)];
    }
}

if (!function_exists('redoPackFolder')) {
    /** The folder an item sits in inside a redo pack: "<Client>/<Tire>/<Series | Reference>" or "<Client>/Library" (export-lib.php names). */
    function redoPackFolder(array $it): string {
        $safe = function_exists('exportSafeName') ? 'exportSafeName' : static function ($s, $f) { return trim((string)$s) !== '' ? (string)$s : $f; };
        $client = $safe($it['company_name'] ?? '', 'Client');
        if (($it['kind'] ?? '') === 'library') return $client . '/Library';
        $tire = $safe($it['tire_name'] ?? '', 'Tire ' . (int)($it['tire_id'] ?? 0));
        $sub  = ($it['series_id'] ?? null) ? $safe($it['series_name'] ?? '', 'Series ' . (int)$it['series_id']) : 'Reference';
        return $client . '/' . $tire . '/' . $sub;
    }
}

if (!function_exists('redoRetargetEntity')) {
    /**
     * Point everything that names one item at another (a library image becoming a tire image): its activity rows
     * (comments, decisions, history), Slack thread, email references, seen markers, queued client emails. Each table is
     * optional (older installs) and best-effort. Returns the activity rows moved.
     */
    function redoRetargetEntity(PDO $pdo, string $fromType, int $fromId, string $toType, int $toId): int {
        $n = 0;
        foreach (['activity_log', 'notify_threads', 'notify_email_refs', 'thread_seen', 'client_email_queue', 'notify_outbox', 'email_inbound'] as $t) {
            if (!redoColumnExists($pdo, $t, 'entity_type') || !redoColumnExists($pdo, $t, 'entity_id')) continue;
            try {
                $s = $pdo->prepare("UPDATE {$t} SET entity_type = ?, entity_id = ? WHERE entity_type = ? AND entity_id = ?");
                $s->execute([$toType, $toId, $fromType, $fromId]);
                if ($t === 'activity_log') $n = $s->rowCount();
            } catch (Throwable $e) {
                if ($t === 'activity_log') throw $e;   // the history must follow the image; everything else is best-effort
                error_log('redoRetargetEntity ' . $t . ': ' . $e->getMessage());
            }
        }
        return $n;
    }
}
