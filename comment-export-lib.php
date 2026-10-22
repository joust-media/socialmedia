<?php
/**
 * "Export open comments" (admin) — every comment a client conversation left on an IMAGE, as a spreadsheet, a zip with
 * the images, or a printable page. Used by comment-export.php; function definitions only (function_exists-guarded,
 * no work at load). Reads only columns origin/main writes on production, every optional one probed.
 *
 * ── What is an image comment ──────────────────────────────────────────────────────────────────────────────────────
 *   activity_log rows with action 'commented' and a non-empty detail (a deleted comment has detail '' + deleted_at —
 *   left out; an edited one already holds its current text) on:
 *     tire_image     a series render ("Tire render") or a reference image ("Reference")
 *     tire_series    the series as a whole ("Series")
 *     library_image  "Library"
 *     post           a post that has images ("Post"): "[Slide N] …" is resolved to that slide's image; an untagged
 *                    comment on a one-image post belongs to that image, on a carousel to the post ("All slides")
 *   never an item in Joust's Trash, never a draft post, never an internal note unless asked for (internal = 1).
 *   A legacy tire_images.client_comment that never made it into the thread counts as the client's word (as in the
 *   redo pack) when no date filter is set.
 *
 * ── Thread state (per item) ───────────────────────────────────────────────────────────────────────────────────────
 *   Waiting on Joust   the Inbox's rule (notifyUnanswered()): a visible client comment with no Joust answer after it —
 *                      a visible Joust comment, a decision / status move, or Resolve (notifyAnswerActions())
 *   Resolved           not waiting, and a 'resolved' row (Inbox / Slack "Resolve") is newer than the newest client comment
 *   Answered           everything else
 *
 * ── Filters ───────────────────────────────────────────────────────────────────────────────────────────────────────
 *   open      (default) the item is not Approved / Scheduled — i.e. To Review, Needs changes, a series still in review,
 *             or in the Redo queue — and its thread is not Resolved; OR its thread is Waiting on Joust (even approved)
 *   waiting   only threads Waiting on Joust
 *   all       every comment on these images, answered ones included
 *   + from / to (the comment's day, Eastern), since (only comments written or edited after the client's last export —
 *   meta comment_export_last_<company id>, stamped when a CSV is downloaded or a zip is built).
 */

require_once __DIR__ . '/export-lib.php';

if (!function_exists('cexpTypes')) {
    function cexpTypes(): array { return ['tire_image', 'tire_series', 'library_image', 'post']; }
}

if (!function_exists('cexpColumns')) {
    /** The CSV header (one row per comment). */
    function cexpColumns(): array {
        return ['Client', 'Type', 'Tire', 'Series', 'Item / file name', 'Slide', 'Item status', 'Redo', 'Comment', 'Author',
                'Date (Eastern)', 'Edited', 'Thread state', 'Portal link', 'Image URL'];
    }
}

if (!function_exists('cexpOptions')) {
    /** The posted form → scope client|all, filter open|waiting|all, internal, since, from / to (Y-m-d or ''), size original|lg. */
    function cexpOptions(array $raw): array {
        $flag = static function ($v): bool { return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true); };
        $day = static function ($v): string {
            $v = trim((string)$v);
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return '';
            return $v;
        };
        $filter = strtolower(trim((string)($raw['filter'] ?? 'open')));
        if (!in_array($filter, ['open', 'waiting', 'all'], true)) $filter = 'open';
        $from = $day($raw['from'] ?? ''); $to = $day($raw['to'] ?? '');
        if ($from !== '' && $to !== '' && $from > $to) [$from, $to] = [$to, $from];
        return [
            'scope'    => strtolower(trim((string)($raw['scope'] ?? ''))) === 'all' ? 'all' : 'client',
            'filter'   => $filter,
            'internal' => $flag($raw['internal'] ?? ''),
            'since'    => $flag($raw['since'] ?? ''),
            'from'     => $from,
            'to'       => $to,
            'size'     => strtolower(trim((string)($raw['size'] ?? ''))) === 'lg' ? 'lg' : 'original',
        ];
    }
}

if (!function_exists('cexpFilterLabel')) {
    function cexpFilterLabel(string $filter): string {
        return ['open' => 'Open comments', 'waiting' => 'Waiting on Joust', 'all' => 'All comments'][$filter] ?? 'Open comments';
    }
}

if (!function_exists('cexpFileStem')) {
    /** "kenda-open-comments-2026-10-22" / "all-clients-waiting-on-joust-…" (no extension). */
    function cexpFileStem(?array $client, array $opts): string {
        $slug = $client ? trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)($client['slug'] ?? ''))), '-') : 'all-clients';
        $what = ['open' => 'open-comments', 'waiting' => 'waiting-on-joust', 'all' => 'comments'][$opts['filter']] ?? 'open-comments';
        return ($slug !== '' ? $slug : 'client') . '-' . $what . '-' . date('Y-m-d');
    }
}

if (!function_exists('cexpMetaKey')) {
    /** meta key of "the last comment export": per client, or every client together ('' id). */
    function cexpMetaKey(?int $companyId): string { return $companyId ? 'comment_export_last_' . $companyId : 'comment_export_last'; }
}

if (!function_exists('cexpWatermarks')) {
    /** [company id => 'Y-m-d H:i:s'] of every client's last comment export (one query; [] without the meta table). */
    function cexpWatermarks(PDO $pdo): array {
        if (!function_exists('hasMetaTable') || !hasMetaTable($pdo)) return [];
        $out = [];
        try {
            foreach ($pdo->query("SELECT k, v FROM meta WHERE k LIKE 'comment\\_export\\_last\\_%'")->fetchAll() as $r) {
                if (preg_match('/^comment_export_last_(\d+)$/', (string)$r['k'], $m) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$r['v'])) {
                    $out[(int)$m[1]] = (string)$r['v'];
                }
            }
        } catch (Throwable $e) { error_log('cexpWatermarks: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('cexpLastExport')) {
    /** The last export of one client (or of "All clients") — '' when never. */
    function cexpLastExport(PDO $pdo, ?int $companyId): string {
        if (!function_exists('hasMetaTable') || !hasMetaTable($pdo)) return '';
        try {
            $s = $pdo->prepare("SELECT v FROM meta WHERE k = ?");
            $s->execute([cexpMetaKey($companyId)]);
            return (string)($s->fetchColumn() ?: '');
        } catch (Throwable $e) { return ''; }
    }
}

if (!function_exists('cexpStamp')) {
    /** "Only since last export" starts from $at for these clients ($companyIds; null = every client + the all-clients key). */
    function cexpStamp(PDO $pdo, ?array $companyIds, string $at): void {
        if (!function_exists('hasMetaTable') || !hasMetaTable($pdo)) return;
        try {
            $m = $pdo->prepare("INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
            if ($companyIds === null) {
                $m->execute([cexpMetaKey(null), $at]);
                $companyIds = array_map('intval', $pdo->query("SELECT id FROM companies")->fetchAll(PDO::FETCH_COLUMN));
            }
            foreach (array_unique(array_map('intval', $companyIds)) as $cid) { if ($cid > 0) $m->execute([cexpMetaKey($cid), $at]); }
        } catch (Throwable $e) { error_log('cexpStamp: ' . $e->getMessage()); }
    }
}

if (!function_exists('cexpIn')) {
    /** (internal) "?,?,?" for a list. */
    function cexpIn(array $ids): string { return implode(',', array_fill(0, count($ids), '?')); }
}

if (!function_exists('cexpAuthor')) {
    /** Who wrote a comment, for Joust: "Jane Kenda (Kenda Tires)" / the client / "Joust (Lance)" / "Joust (Lance) — internal note". */
    function cexpAuthor(PDO $pdo, array $c, string $companyName): string {
        $actor = (string)($c['actor'] ?? '');
        if ($actor === 'client') {
            $l = function_exists('clientContactLabel') ? clientContactLabel($pdo, $c['client_contact_id'] ?? null) : '';
            return $l !== '' ? $l : ($companyName !== '' ? $companyName : 'Client');
        }
        if ($actor !== 'admin') return 'Note';
        $u = (function_exists('adminUserById') && (int)($c['author_user_id'] ?? 0) > 0) ? adminUserById($pdo, (int)$c['author_user_id']) : null;
        $who = $u && function_exists('adminUserFirstName') ? 'Joust (' . adminUserFirstName($u) . ')' : 'Joust';
        return $who . (!empty($c['internal']) ? ' — internal note' : '');
    }
}

if (!function_exists('cexpAbsUrl')) {
    /** A portal media URL ('uploads/x.jpg', '/media/…') → absolute. */
    function cexpAbsUrl(string $url): string {
        if ($url === '') return '';
        $u = function_exists('trackingMediaUrl') ? trackingMediaUrl($url) : $url;
        if (preg_match('#^https?://#i', $u)) return $u;
        // media/ is the portal folder's sibling ('/media/tires/…', '/media/library/…'): the site origin + the path as is
        $base = function_exists('basePath') ? basePath() : '';
        if ($u !== '' && $u[0] === '/' && ($base === '' || strpos($u, $base . '/') !== 0) && function_exists('portalOrigin')) return portalOrigin() . $u;
        return function_exists('notifyAbsoluteUrl') ? notifyAbsoluteUrl($u) : $u;
    }
}

if (!function_exists('cexpCollect')) {
    /**
     * The export: ['items' => [key => item], 'rows' => [row, …] (CSV order), 'clients' => [id => name], 'at' => now].
     *   item: key, type, id, company_id, company_name, company_slug, type_label, tire_id, tire_name, series_id,
     *         series_name, name, status_key, status_label, redo, redo_label, state, link, images [{n, url, ref, file,
     *         video}], waiting, resolved, open
     *   row:  item (key), unit (slide index into images, 1-based; 0 = the item as a whole), id (activity id or 0),
     *         actor, internal, at, + the CSV cells: client, type, tire, series, name, slide, status, redo, comment,
     *         author, date, edited, state, link, image
     * $client null = every client. Never another client's row when one is given (every query is scoped).
     */
    function cexpCollect(PDO $pdo, ?array $client, array $opts): array {
        $opts = cexpOptions($opts);
        $cid = $client ? (int)$client['id'] : null;
        $out = ['items' => [], 'rows' => [], 'clients' => [], 'at' => date('Y-m-d H:i:s'), 'options' => $opts];
        if (!function_exists('hasActivityLog') || !hasActivityLog($pdo)) return $out;

        $companies = [];
        $s = $pdo->prepare("SELECT id, name, slug FROM companies" . ($cid ? ' WHERE id = ?' : ''));
        $s->execute($cid ? [$cid] : []);
        foreach ($s->fetchAll() as $c) $companies[(int)$c['id']] = ['name' => (string)$c['name'], 'slug' => (string)$c['slug']];
        if (!$companies) return $out;

        // ---- every visible-or-internal comment on an image type, one query (the thread facts need all of them) ----
        $editOn = function_exists('commentEditReady') && commentEditReady($pdo);
        $types = cexpTypes();
        $sql = "SELECT a.id, a.company_id, a.entity_type, a.entity_id, a.actor, a.detail, a.created_at"
             . (function_exists('activityAuthorCols') ? activityAuthorCols($pdo, 'a') : ', NULL AS author_user_id, 0 AS internal, NULL AS client_contact_id')
             . ($editOn ? ', a.edited_at' : ', NULL AS edited_at')
             . " FROM activity_log a
                WHERE a.action = 'commented' AND a.entity_type IN (" . cexpIn($types) . ") AND a.detail IS NOT NULL AND a.detail <> ''"
             . ($editOn ? ' AND a.deleted_at IS NULL' : '') . ($cid ? ' AND a.company_id = ?' : '') . "
                ORDER BY a.id ASC";
        $s = $pdo->prepare($sql);
        $s->execute($cid ? array_merge($types, [$cid]) : $types);
        $byItem = [];
        foreach ($s->fetchAll() as $r) {
            if (!isset($companies[(int)$r['company_id']])) continue;
            $byItem[(string)$r['entity_type']][(int)$r['entity_id']][] = $r;
        }

        // ---- legacy tire_images.client_comment (no activity row) ----
        $legacyTire = [];
        try {
            $s = $pdo->prepare("SELECT ti.id FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id
                                 WHERE ti.client_comment IS NOT NULL AND ti.client_comment <> ''" . ($cid ? ' AND t.company_id = ?' : ''));
            $s->execute($cid ? [$cid] : []);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $legacyTire[(int)$id] = true;
        } catch (Throwable $e) { /* no client_comment column: nothing legacy */ }

        // ---- thread facts: Waiting on Joust (the Inbox's notifyUnanswered()), the newest Resolve per item ----
        $waiting = [];
        if (function_exists('notifyUnanswered')) {
            foreach (notifyUnanswered($pdo, $cid, '1970-01-02 00:00:00', 100000) as $w) $waiting[$w['entity_type'] . ':' . (int)$w['entity_id']] = true;
        }
        $resolvedId = [];
        $s = $pdo->prepare("SELECT entity_type, entity_id, MAX(id) AS rid FROM activity_log WHERE action = 'resolved' AND entity_type IN (" . cexpIn($types) . ")"
                           . ($cid ? ' AND company_id = ?' : '') . " GROUP BY entity_type, entity_id");
        $s->execute($cid ? array_merge($types, [$cid]) : $types);
        foreach ($s->fetchAll() as $r) $resolvedId[$r['entity_type'] . ':' . (int)$r['entity_id']] = (int)$r['rid'];

        $trash = static function (string $type, string $alias) use ($pdo): string { return function_exists('trashAnd') ? trashAnd($pdo, $type, $alias) : ''; };
        $chunks = static function (array $ids): array { return array_chunk(array_values(array_unique(array_map('intval', $ids))), 500); };
        $items = [];
        $statusLabel = static function (string $k): string { return function_exists('notifyStatusPill') ? notifyStatusPill($k)[0] : ucfirst($k); };

        // ---- tire images (renders + references) ----
        $tireIds = array_merge(array_keys($byItem['tire_image'] ?? []), array_keys($legacyTire));
        $seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);
        $dnOn = function_exists('tireImagesHaveDisplayName') && tireImagesHaveDisplayName($pdo);
        $redoTire = function_exists('redoKindReady') && redoKindReady($pdo, 'tire');
        foreach ($chunks($tireIds) as $ids) {
            $s = $pdo->prepare("SELECT ti.id, ti.tire_id, " . ($seriesOn ? 'ti.series_id' : 'NULL AS series_id') . ", ti.image_url, ti.status, ti.sort_order, ti.client_comment, "
                . ($dnOn ? 'ti.display_name' : "'' AS display_name") . ", " . ($redoTire ? 'ti.redo_at, ti.redo_note' : 'NULL AS redo_at, NULL AS redo_note')
                . ", t.name AS tire_name, t.company_id" . ($seriesOn ? ', s.name AS series_name, s.sort_order AS series_sort' : ', NULL AS series_name, 0 AS series_sort') . "
                  FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id" . ($seriesOn ? ' LEFT JOIN tire_series s ON s.id = ti.series_id' : '') . "
                 WHERE ti.id IN (" . cexpIn($ids) . ")" . $trash('tire_image', 'ti') . ($cid ? ' AND t.company_id = ?' : ''));
            $s->execute($cid ? array_merge($ids, [$cid]) : $ids);
            foreach ($s->fetchAll() as $r) {
                $co = (int)$r['company_id'];
                if (!isset($companies[$co])) continue;
                $url = (string)$r['image_url'];
                $src = function_exists('tireImageSrc') ? tireImageSrc($r) : $url;
                $file = basename((string)(parse_url($url, PHP_URL_PATH) ?: $url));
                $sid = $r['series_id'] !== null ? (int)$r['series_id'] : 0;
                $items['tire_image:' . (int)$r['id']] = [
                    'type' => 'tire_image', 'id' => (int)$r['id'], 'company_id' => $co,
                    'type_label' => $sid ? 'Tire render' : 'Reference',
                    'tire_id' => (int)$r['tire_id'], 'tire_name' => (string)$r['tire_name'],
                    'series_id' => $sid, 'series_name' => $sid ? (string)($r['series_name'] ?? ('Series ' . $sid)) : 'Reference',
                    'series_sort' => $sid ? (int)($r['series_sort'] ?? 0) : -1, 'sort' => (int)$r['sort_order'], 'group' => 0, 'sub' => 1,
                    'name' => $file, 'status_key' => (string)$r['status'],
                    'redo' => $r['redo_at'] !== null, 'redo_note' => (string)($r['redo_note'] ?? ''),
                    'images' => [1 => ['url' => $src, 'file' => $file, 'video' => function_exists('isVideoExt') && isVideoExt(strtolower(pathinfo($file, PATHINFO_EXTENSION)))]],
                    'meta' => ['tire_id' => (int)$r['tire_id'], 'series_id' => $sid],
                    'legacy' => isset($legacyTire[(int)$r['id']]) ? trim((string)$r['client_comment']) : '',
                ];
            }
        }

        // ---- tire series (the series as a whole) ----
        $seriesIds = array_keys($byItem['tire_series'] ?? []);
        if ($seriesIds && $seriesOn) {
            foreach ($chunks($seriesIds) as $ids) {
                $s = $pdo->prepare("SELECT s.id, s.name, s.sort_order, s.tire_id, t.name AS tire_name, t.company_id FROM tire_series s INNER JOIN tires t ON t.id = s.tire_id
                                     WHERE s.id IN (" . cexpIn($ids) . ")" . ($cid ? ' AND t.company_id = ?' : ''));
                $s->execute($cid ? array_merge($ids, [$cid]) : $ids);
                $rows = $s->fetchAll();
                $counts = [];
                $c = $pdo->prepare("SELECT series_id, SUM(status = 'pending') AS p, SUM(status = 'approved') AS a, SUM(status = 'denied') AS d, COUNT(*) AS n"
                                   . ($redoTire ? ', SUM(redo_at IS NOT NULL) AS r' : ', 0 AS r')
                                   . " FROM tire_images WHERE series_id IN (" . cexpIn($ids) . ")" . $trash('tire_image', '') . " GROUP BY series_id");
                $c->execute($ids);
                foreach ($c->fetchAll() as $x) $counts[(int)$x['series_id']] = $x;
                foreach ($rows as $r) {
                    $co = (int)$r['company_id'];
                    if (!isset($companies[$co])) continue;
                    $k = $counts[(int)$r['id']] ?? ['p' => 0, 'a' => 0, 'd' => 0, 'n' => 0, 'r' => 0];
                    $p = (int)$k['p']; $a = (int)$k['a']; $d = (int)$k['d']; $n = (int)$k['n'];
                    $st = $p > 0 ? ($a + $d > 0 ? 'mixed' : 'pending') : ($d > 0 ? 'denied' : ($n > 0 ? 'approved' : 'pending'));
                    $items['tire_series:' . (int)$r['id']] = [
                        'type' => 'tire_series', 'id' => (int)$r['id'], 'company_id' => $co, 'type_label' => 'Series',
                        'tire_id' => (int)$r['tire_id'], 'tire_name' => (string)$r['tire_name'],
                        'series_id' => (int)$r['id'], 'series_name' => (string)$r['name'], 'series_sort' => (int)$r['sort_order'], 'sort' => 0, 'group' => 0, 'sub' => 0,
                        'name' => (string)$r['name'] . ' (whole series)', 'status_key' => $st,
                        'redo' => (int)$k['r'] > 0, 'redo_note' => (int)$k['r'] > 0 ? (int)$k['r'] . ((int)$k['r'] === 1 ? ' image' : ' images') . ' in the Redo queue' : '',
                        'images' => [], 'meta' => ['tire_id' => (int)$r['tire_id']], 'legacy' => '',
                    ];
                }
            }
        }

        // ---- library images ----
        $libIds = array_keys($byItem['library_image'] ?? []);
        if ($libIds && function_exists('hasLibraryImagesTable') && hasLibraryImagesTable($pdo)) {
            $redoLib = function_exists('redoKindReady') && redoKindReady($pdo, 'library');
            foreach ($chunks($libIds) as $ids) {
                $s = $pdo->prepare("SELECT li.id, li.filename, li.status, li.company_id" . ($redoLib ? ', li.redo_at, li.redo_note' : ', NULL AS redo_at, NULL AS redo_note') . "
                                      FROM library_images li WHERE li.id IN (" . cexpIn($ids) . ")" . $trash('library_image', 'li') . ($cid ? ' AND li.company_id = ?' : ''));
                $s->execute($cid ? array_merge($ids, [$cid]) : $ids);
                foreach ($s->fetchAll() as $r) {
                    $co = (int)$r['company_id'];
                    if (!isset($companies[$co])) continue;
                    $file = (string)$r['filename'];
                    $items['library_image:' . (int)$r['id']] = [
                        'type' => 'library_image', 'id' => (int)$r['id'], 'company_id' => $co, 'type_label' => 'Library',
                        'tire_id' => 0, 'tire_name' => '', 'series_id' => 0, 'series_name' => '', 'series_sort' => 0, 'sort' => 0, 'group' => 1, 'sub' => 1,
                        'name' => $file, 'status_key' => (string)$r['status'],
                        'redo' => $r['redo_at'] !== null, 'redo_note' => (string)($r['redo_note'] ?? ''),
                        'images' => [1 => ['url' => libraryFileUrl($companies[$co]['slug'], $file), 'file' => $file,
                                           'video' => function_exists('isVideoExt') && isVideoExt(strtolower(pathinfo($file, PATHINFO_EXTENSION)))]],
                        'meta' => [], 'legacy' => '',
                    ];
                }
            }
        }

        // ---- posts with images (never a draft) ----
        $postIds = array_keys($byItem['post'] ?? []);
        if ($postIds) {
            $nameSel = function_exists('hasPostsNameColumn') && hasPostsNameColumn($pdo) ? 'p.name' : "'' AS name";
            $postedSel = function_exists('hasPostedColumn') && hasPostedColumn($pdo) ? 'p.posted' : '0 AS posted';
            foreach ($chunks($postIds) as $ids) {
                $s = $pdo->prepare("SELECT p.id, p.company_id, p.status, p.caption, {$nameSel}, {$postedSel} FROM posts p
                                     WHERE p.id IN (" . cexpIn($ids) . ") AND p.status <> 'draft'" . $trash('post', 'p') . ($cid ? ' AND p.company_id = ?' : ''));
                $s->execute($cid ? array_merge($ids, [$cid]) : $ids);
                $posts = $s->fetchAll();
                if (!$posts) continue;
                $pids = array_map(static function ($p) { return (int)$p['id']; }, $posts);
                $imgs = [];
                $s = $pdo->prepare("SELECT post_id, image_url FROM post_images WHERE post_id IN (" . cexpIn($pids) . ") ORDER BY post_id, sort_order ASC, id ASC");
                $s->execute($pids);
                foreach ($s->fetchAll() as $im) {
                    $u = (string)$im['image_url'];
                    $f = basename((string)(parse_url($u, PHP_URL_PATH) ?: $u));
                    $list = $imgs[(int)$im['post_id']] ?? [];
                    $list[count($list) + 1] = ['url' => $u, 'file' => $f, 'video' => function_exists('isVideoExt') && isVideoExt(strtolower(pathinfo($f, PATHINFO_EXTENSION)))];
                    $imgs[(int)$im['post_id']] = $list;
                }
                foreach ($posts as $p) {
                    $co = (int)$p['company_id'];
                    if (!isset($companies[$co]) || empty($imgs[(int)$p['id']])) continue;   // "posts that have images"
                    $label = function_exists('postDisplayLabel') ? postDisplayLabel($p) : ('post #' . (int)$p['id']);
                    $items['post:' . (int)$p['id']] = [
                        'type' => 'post', 'id' => (int)$p['id'], 'company_id' => $co, 'type_label' => 'Post',
                        'tire_id' => 0, 'tire_name' => '', 'series_id' => 0, 'series_name' => '', 'series_sort' => 0, 'sort' => (int)$p['id'], 'group' => 2, 'sub' => 1,
                        'name' => $label, 'status_key' => !empty($p['posted']) ? 'scheduled' : (string)$p['status'],
                        'redo' => false, 'redo_note' => '', 'images' => $imgs[(int)$p['id']], 'meta' => [], 'legacy' => '',
                    ];
                }
            }
        }

        // ---- per item: thread state, the filter, the rows ----
        $wm = $opts['since'] ? cexpWatermarks($pdo) : [];
        $dateFiltered = $opts['from'] !== '' || $opts['to'] !== '' || $opts['since'];
        uasort($items, static function ($a, $b) use ($companies) {
            $ka = [strtolower($companies[$a['company_id']]['name']), $a['company_id'], $a['group'], strtolower($a['tire_name']), $a['tire_id'], $a['series_sort'], $a['series_id'], $a['sub'], $a['sort'], strtolower($a['name']), $a['id']];
            $kb = [strtolower($companies[$b['company_id']]['name']), $b['company_id'], $b['group'], strtolower($b['tire_name']), $b['tire_id'], $b['series_sort'], $b['series_id'], $b['sub'], $b['sort'], strtolower($b['name']), $b['id']];
            return $ka <=> $kb;
        });
        foreach ($items as $key => $it) {
            $co = $it['company_id'];
            $thread = [];
            foreach ($byItem[$it['type']][$it['id']] ?? [] as $c) {
                if ((int)$c['company_id'] !== $co) continue;   // a row filed under another client never travels with this item
                $thread[] = $c;
            }
            $lastClient = 0;
            foreach ($thread as $c) { if ($c['actor'] === 'client' && empty($c['internal'])) $lastClient = max($lastClient, (int)$c['id']); }
            if ($it['legacy'] !== '' && in_array($it['legacy'], array_map(static function ($c) { return trim((string)$c['detail']); }, $thread), true)) $it['legacy'] = '';
            if (!$thread && $it['legacy'] === '') continue;
            $isWaiting = isset($waiting[$key]);
            $rid = $resolvedId[$key] ?? 0;
            $isResolved = !$isWaiting && $rid > 0 && $rid > $lastClient;
            $state = $isWaiting ? 'Waiting on Joust' : ($isResolved ? 'Resolved' : 'Answered');
            $openStatus = in_array($it['status_key'], ['pending', 'denied', 'mixed'], true) || $it['redo'];
            $isOpen = $isWaiting || ($openStatus && !$isResolved);
            if ($opts['filter'] === 'open' && !$isOpen) continue;
            if ($opts['filter'] === 'waiting' && !$isWaiting) continue;

            $slug = $companies[$co]['slug'];
            $it['key'] = $key;
            $it['company_name'] = $companies[$co]['name'];
            $it['company_slug'] = $slug;
            $it['status_label'] = $statusLabel($it['status_key']);
            $it['redo_label'] = $it['redo'] ? 'yes' . ($it['redo_note'] !== '' ? ' — ' . $it['redo_note'] : '') : 'no';
            $it['state'] = $state;
            $it['waiting'] = $isWaiting; $it['resolved'] = $isResolved; $it['open'] = $isOpen;
            $it['link'] = function_exists('portalItemUrl') ? portalItemUrl($it['type'], $it['id'], $slug, $it['meta']) : '';
            $nImg = count($it['images']);

            $rows = [];
            $mk = function (array $c, string $text, int $unit, string $slideLabel) use ($pdo, $it): array {
                $img = $it['images'][$unit] ?? ($it['images'][1] ?? null);
                $at = (string)($c['created_at'] ?? '');
                return [
                    'item' => $it['key'], 'unit' => $unit, 'id' => (int)($c['id'] ?? 0), 'actor' => (string)($c['actor'] ?? ''), 'internal' => !empty($c['internal']), 'at' => $at,
                    'client' => $it['company_name'], 'type' => $it['type_label'], 'tire' => $it['tire_name'],
                    'series' => $it['type'] === 'tire_image' || $it['type'] === 'tire_series' ? $it['series_name'] : '',
                    'name' => $it['name'], 'slide' => $slideLabel, 'status' => $it['status_label'], 'redo' => $it['redo_label'],
                    'comment' => $text, 'author' => cexpAuthor($pdo, $c, $it['company_name']),
                    'date' => $at !== '' ? date('Y-m-d H:i', (int)strtotime($at)) : '',
                    'edited' => !empty($c['edited_at']) ? 'yes' : 'no', 'state' => $it['state'], 'link' => $it['link'],
                    'image' => $img ? cexpAbsUrl((string)$img['url']) : '',
                ];
            };
            if ($it['legacy'] !== '' && !$dateFiltered) {
                $rows[] = $mk(['id' => 0, 'actor' => 'client', 'internal' => 0, 'created_at' => '', 'client_contact_id' => null], $it['legacy'], $nImg ? 1 : 0, '');
            }
            foreach ($thread as $c) {
                if (!empty($c['internal']) && !$opts['internal']) continue;
                $at = (string)$c['created_at'];
                $day = substr($at, 0, 10);
                if ($opts['from'] !== '' && $day < $opts['from']) continue;
                if ($opts['to'] !== '' && $day > $opts['to']) continue;
                if ($opts['since'] && isset($wm[$co])) {
                    $edited = (string)($c['edited_at'] ?? '');
                    if ($at <= $wm[$co] && ($edited === '' || $edited <= $wm[$co])) continue;
                }
                $text = trim((string)$c['detail']);
                $unit = $nImg ? 1 : 0; $slideLabel = '';
                if ($it['type'] === 'post') {
                    [$n, $body] = function_exists('commentSlideSplit') ? commentSlideSplit($text) : [0, $text];
                    $text = trim($body);
                    if ($n >= 1 && $n <= $nImg) { $unit = $n; $slideLabel = (string)$n; }
                    elseif ($nImg === 1) { $unit = 1; $slideLabel = $n > 0 ? (string)$n : ''; }
                    else { $unit = 0; $slideLabel = $n > 0 ? $n . ' (no longer in the post)' : 'All slides'; }
                }
                $rows[] = $mk($c, $text, $unit, $slideLabel);
            }
            if (!$rows) continue;
            $out['items'][$key] = $it;
            $out['clients'][$co] = $it['company_name'];
            foreach ($rows as $r) $out['rows'][] = $r;
        }
        return $out;
    }
}

if (!function_exists('cexpCsvCells')) {
    function cexpCsvCells(array $r): array {
        return [$r['client'], $r['type'], $r['tire'], $r['series'], $r['name'], $r['slide'], $r['status'], $r['redo'], $r['comment'], $r['author'],
                $r['date'], $r['edited'], $r['state'], $r['link'], $r['image']];
    }
}

if (!function_exists('cexpCsvCell')) {
    /** A cell that opens safely in Excel / Sheets: a leading = + - @ (a formula) gets a quote mark in front. */
    function cexpCsvCell($v): string {
        $v = str_replace("\r\n", "\n", (string)$v);
        if ($v !== '' && strpos('=+-@', $v[0]) !== false && !preg_match('/^-?\d/', $v)) $v = "'" . $v;
        return $v;
    }
}

if (!function_exists('cexpCsvLine')) {
    function cexpCsvLine(array $cells): string {
        return exportCsvRow(array_map('cexpCsvCell', $cells));
    }
}

if (!function_exists('cexpCsvHead')) {
    /** UTF-8 BOM + the header line (CRLF). */
    function cexpCsvHead(): string { return "\xEF\xBB\xBF" . exportCsvRow(cexpColumns()); }
}

if (!function_exists('cexpCsv')) {
    /** The whole CSV as a string (the zip carries it). */
    function cexpCsv(array $data): string {
        $csv = cexpCsvHead();
        foreach ($data['rows'] as $r) $csv .= cexpCsvLine(cexpCsvCells($r));
        return $csv;
    }
}

if (!function_exists('cexpPlace')) {
    /** "Kenda Tires · Klever AT2 · Series 1" / "Kenda Tires · Library" / "Kenda Tires · Posts · AT2 carousel". */
    function cexpPlace(array $it): string {
        $parts = [$it['company_name']];
        if ($it['type'] === 'tire_image' || $it['type'] === 'tire_series') { $parts[] = $it['tire_name']; $parts[] = $it['series_name']; }
        elseif ($it['type'] === 'library_image') $parts[] = 'Library';
        else { $parts[] = 'Posts'; $parts[] = $it['name']; }
        return implode(' · ', array_filter($parts, 'strlen'));
    }
}

if (!function_exists('cexpThreadTxt')) {
    /** The "<stem>.txt" next to an image (or a series' / a carousel's own comments): where, status, link, the comments. CRLF. */
    function cexpThreadTxt(array $it, array $rows, string $what, string $zipName = '', bool $missing = false): string {
        $place = cexpPlace($it);
        $lines = [$place, str_repeat('=', min(60, max(10, mb_strlen($place, 'UTF-8'))))];
        $lines[] = $what;
        if ($zipName !== '') $lines[] = 'In this zip: ' . $zipName . ($missing ? '  (the file is missing on the server)' : '');
        $lines[] = 'Status: ' . $it['status_label'] . ($it['redo'] ? ' · in the Redo queue' . ($it['redo_note'] !== '' ? ' — ' . $it['redo_note'] : '') : '');
        $lines[] = 'Thread: ' . $it['state'];
        if ($it['link'] !== '') $lines[] = 'Open in the portal: ' . $it['link'];
        $lines[] = '';
        $lines[] = 'Comments:';
        foreach ($rows as $r) {
            $lines[] = '  ' . ($r['date'] !== '' ? '[' . $r['date'] . ' ET] ' : '') . $r['author'] . ($r['edited'] === 'yes' ? ' (edited)' : '')
                     . ($r['slide'] !== '' && $r['slide'] !== 'All slides' ? ' — slide ' . $r['slide'] : '') . ':';
            $lines[] = '    ' . str_replace("\n", "\n    ", trim((string)$r['comment']));
        }
        return str_replace(["\r\n", "\n"], ["\n", "\r\n"], implode("\n", $lines)) . "\r\n";
    }
}

if (!function_exists('cexpUnits')) {
    /**
     * The export grouped by what it is about: one unit per image with comments (tire / library image, a post's slide)
     * and one per series / carousel-as-a-whole: [['item' => item, 'unit' => n (0 = no image), 'image' => image|null,
     * 'rows' => […]]] in CSV order.
     */
    function cexpUnits(array $data): array {
        $units = [];
        foreach ($data['rows'] as $r) {
            $k = $r['item'] . '#' . (int)$r['unit'];
            if (!isset($units[$k])) {
                $it = $data['items'][$r['item']];
                $units[$k] = ['item' => $it, 'unit' => (int)$r['unit'], 'image' => (int)$r['unit'] > 0 ? ($it['images'][(int)$r['unit']] ?? null) : null, 'rows' => []];
            }
            $units[$k]['rows'][] = $r;
        }
        return array_values($units);
    }
}

if (!function_exists('cexpFolder')) {
    /** Where a unit sits in the zip: "<Client>/<Tire>/<Series | Reference>", "<Client>/Library", "<Client>/Posts/<post> (post <id>)". */
    function cexpFolder(array $it, array &$tireFolders): string {
        $client = exportSafeName($it['company_name'], 'Client');
        if ($it['type'] === 'library_image') return $client . '/Library';
        if ($it['type'] === 'post') return $client . '/Posts/' . exportSafeName($it['name'], 'Post', 60) . ' (post ' . (int)$it['id'] . ')';
        // a tire name two tires share gets " (<id>)" on the second (export-lib.php's rule)
        $k = $it['company_id'] . ':' . $it['tire_id'];
        if (!isset($tireFolders[$k])) {
            $f = exportSafeName($it['tire_name'], 'Tire ' . (int)$it['tire_id']);
            $taken = array_map('strtolower', array_values(array_filter($tireFolders, static function ($v, $kk) use ($it) { return strpos((string)$kk, $it['company_id'] . ':') === 0; }, ARRAY_FILTER_USE_BOTH)));
            if (in_array(strtolower($f), $taken, true)) $f = exportSafeName($f . ' (' . (int)$it['tire_id'] . ')', 'Tire ' . (int)$it['tire_id']);
            $tireFolders[$k] = $f;
        }
        $sub = $it['series_id'] ? exportSafeName($it['series_name'], 'Series ' . (int)$it['series_id']) : 'Reference';
        return $client . '/' . $tireFolders[$k] . '/' . $sub;
    }
}

if (!function_exists('cexpZipPlan')) {
    /**
     * The zip's contents: files (export-lib.php's job shape, kind 'media': each image with comments, its original or —
     * size 'lg' — the large preview, plus txt_name / txt next to it), texts (series / carousel comments with no single
     * image: [name, text]), csv, summary (summary.html, images linked by their path in the zip).
     */
    function cexpZipPlan(array $data, string $size = 'original'): array {
        $plan = ['files' => [], 'texts' => [], 'bytes' => 0, 'video_bytes' => 0, 'missing' => 0, 'counts' => ['photos' => 0, 'videos' => 0, 'reference' => 0, 'series' => 0, 'library' => 0, 'missing' => 0]];
        $used = []; $tireFolders = []; $paths = [];
        foreach (cexpUnits($data) as $ui => $u) {
            $it = $u['item'];
            $folder = cexpFolder($it, $tireFolders);
            if ($u['image'] === null) {
                $base = $it['type'] === 'tire_series' ? '_series-comments' : '_post-comments';
                for ($n = 1; ; $n++) { $nm = $base . ($n > 1 ? '-' . $n : '') . '.txt'; if (!isset($used[$folder . '/' . strtolower($nm)])) { $used[$folder . '/' . strtolower($nm)] = true; break; } }
                $what = $it['type'] === 'tire_series' ? 'Comments on the whole series' : 'Comments on the whole post (all slides)';
                $plan['texts'][] = [$folder . '/' . $nm, cexpThreadTxt($it, $u['rows'], $what)];
                continue;
            }
            $img = $u['image'];
            $ref = function_exists('previewCanonicalRef') ? previewCanonicalRef((string)$img['url']) : null;
            $abs = $ref !== null && function_exists('previewRefPath') ? previewRefPath($ref) : null;
            $orig = (string)$img['file'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $useLg = false; $bytes = $abs !== null ? (int)@filesize($abs) : 0;
            if ($size === 'lg' && $abs !== null && !$img['video'] && function_exists('previewIsImage') && previewIsImage($abs) && function_exists('previewNeeds') && previewNeeds($abs, 'lg')) {
                $useLg = true; $ext = previewFormat();
                $lg = previewPathFor($abs, 'lg');
                if (is_file($lg)) $bytes = (int)@filesize($lg);
            }
            $stem = exportSafeName(pathinfo($orig, PATHINFO_FILENAME), 'image', 120);
            if ($it['type'] === 'post' && count($it['images']) > 1) $stem = exportSafeName('Slide ' . $u['unit'] . ' - ' . $stem, 'image', 120);
            for ($n = 1; ; $n++) {   // the file and its .txt must both be free in the folder
                $nm = $stem . ($n > 1 ? '-' . $n : '');
                $k1 = $folder . '/' . strtolower($nm . ($ext !== '' ? '.' . $ext : '')); $k2 = $folder . '/' . strtolower($nm . '.txt');
                if (!isset($used[$k1]) && !isset($used[$k2])) { $used[$k1] = $used[$k2] = true; break; }
            }
            $name = $nm . ($ext !== '' ? '.' . $ext : '');
            $what = 'File: ' . $orig . ($it['type'] === 'post' ? (count($it['images']) > 1 ? ' (slide ' . $u['unit'] . ' of ' . count($it['images']) . ')' : '') : '');
            $txt = cexpThreadTxt($it, $u['rows'], $what, $folder . '/' . $name, $abs === null);
            if ($abs === null) {   // missing on disk: the comments still travel
                $plan['missing']++; $plan['counts']['missing']++;
                $plan['texts'][] = [$folder . '/' . $nm . '.txt', $txt];
                continue;
            }
            $plan['files'][] = [
                'id' => $it['id'], 'kind' => 'media', 'company_id' => $it['company_id'], 'client' => $it['company_name'], 'tire' => $it['tire_name'],
                'series' => $it['type'] === 'tire_image' ? $it['series_name'] : '', 'folder' => $folder, 'name' => $folder . '/' . $name, 'filename' => $name,
                'original' => $orig, 'media_type' => $img['video'] ? 'video' : 'image', 'bytes' => $bytes, 'status' => $it['status_key'],
                'source_path' => (string)$ref, 'size' => $useLg ? 'lg' : 'original',
                'txt_name' => $folder . '/' . $nm . '.txt', 'txt' => $txt, 'approved_at' => '', 'comments_count' => count($u['rows']), 'drive_url' => '',
            ];
            $paths[$ui] = $folder . '/' . $name;
            $plan['bytes'] += $bytes;
            if ($img['video']) { $plan['video_bytes'] += $bytes; $plan['counts']['videos']++; } else { $plan['counts']['photos']++; }
            if ($it['type'] === 'library_image') $plan['counts']['library']++; elseif ($it['type'] === 'tire_image') { if ($it['series_id']) $plan['counts']['series']++; else $plan['counts']['reference']++; }
        }
        $plan['paths'] = $paths;
        return $plan;
    }
}

if (!function_exists('cexpZipStartJob')) {
    /** Collect + plan + write the job sidecar (export-lib.php's stepwise zip; cexp = true, company_id 0 = every client). */
    function cexpZipStartJob(PDO $pdo, ?array $client, array $opts): array {
        exportCleanup();
        $opts = cexpOptions($opts);
        $data = cexpCollect($pdo, $client, $opts);
        if (!$data['rows']) throw new InvalidArgumentException('No comments match — nothing to export');
        $plan = cexpZipPlan($data, $opts['size']);
        if ($plan['bytes'] > EXPORT_MAX_BYTES) throw new InvalidArgumentException('That zip would be ' . exportFormatBytes($plan['bytes']) . ' — the limit for one zip is ' . exportFormatBytes(EXPORT_MAX_BYTES) . '. Export one client at a time, pick large previews, or narrow the dates.');
        $dir = exportsDir(true);
        if ($dir === null) throw new RuntimeException('uploads/.exports is not writable on this server');
        $n = count($data['rows']);
        $label = cexpFilterLabel($opts['filter']) . ' · ' . ($client ? (string)$client['name'] : 'all clients') . ' · ' . $n . ($n === 1 ? ' comment' : ' comments')
               . ' · ' . count($plan['files']) . (count($plan['files']) === 1 ? ' image' : ' images');
        for ($try = 0; $try < 5; $try++) {
            $id = bin2hex(random_bytes(16));
            if (is_file($dir . '/' . $id . '.json') || is_file($dir . '/' . $id . '.zip')) continue;
            $job = [
                'job' => $id, 'cexp' => true, 'company_id' => $client ? (int)$client['id'] : 0,
                'client' => $client ? (string)($client['slug'] ?? '') : '', 'company' => $client ? (string)($client['name'] ?? '') : 'All clients',
                'folder' => 'Comments', 'options' => $opts + ['scope_all' => $client === null], 'label' => $label,
                'filename' => cexpFileStem($client, $opts) . '.zip', 'created_at' => time(),
                'files' => $plan['files'], 'texts' => $plan['texts'], 'csv' => cexpCsv($data),
                'summary' => cexpSummaryHtml($data, $opts, $client, $plan['paths']), 'comments' => $n,
                'bytes' => $plan['bytes'], 'video_bytes' => $plan['video_bytes'], 'counts' => $plan['counts'],
                'warnings' => $plan['missing'] > 0 ? [$plan['missing'] . ' image' . ($plan['missing'] === 1 ? ' is' : 's are') . ' missing on disk — their comments are still in the zip'] : [],
                'progress' => ['index' => 0, 'offset' => 0, 'crc' => 0, 'pos' => 0, 'done_files' => 0, 'bytes_done' => 0, 'skipped' => 0],
                'entries' => [], 'done' => false, 'zip_bytes' => 0, 'error' => '', 'stamped' => false,
                'stamp_at' => $data['at'], 'stamp_ids' => $client ? [(int)$client['id']] : null,
            ];
            if (!exportSaveJob($job)) throw new RuntimeException('Could not write the export job');
            return $job;
        }
        throw new RuntimeException('Could not allocate an export job id');
    }
}

if (!function_exists('cexpSummaryHtml')) {
    /**
     * A printable page (Print → Save as PDF): one block per image — the thumbnail and its comments. $zipPaths (unit
     * index → path in the zip) = the copy inside the zip, images linked relatively; [] = the standalone page, images
     * through the portal's previews.
     */
    function cexpSummaryHtml(array $data, array $opts, ?array $client, ?array $zipPaths = null): string {
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $opts = cexpOptions($opts);
        $title = cexpFilterLabel($opts['filter']) . ' — ' . ($client ? (string)$client['name'] : 'All clients');
        $sub = [];
        $n = count($data['rows']);
        $sub[] = $n . ($n === 1 ? ' comment' : ' comments');
        $sub[] = 'exported ' . date('M j, Y g:i A') . ' ET';
        if ($opts['from'] !== '' || $opts['to'] !== '') $sub[] = 'dates ' . ($opts['from'] ?: '…') . ' – ' . ($opts['to'] ?: '…');
        if ($opts['since']) $sub[] = 'only since the last export';
        if ($opts['internal']) $sub[] = 'internal notes included';
        $h = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>' . $e($title) . '</title><style>'
           . 'body{font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1c1c1e;margin:24px auto;max-width:900px;padding:0 16px}'
           . 'h1{font-size:24px;margin:0 0 4px}.sub{color:#6e6e73;margin:0 0 20px}.cx-block{display:flex;gap:16px;padding:14px 0;border-top:1px solid #e5e5ea;break-inside:avoid}'
           . '.cx-thumb{flex:0 0 160px}.cx-thumb img{width:160px;height:auto;border-radius:8px;display:block;background:#f2f2f7}.cx-thumb .cx-none{width:160px;height:100px;border-radius:8px;background:#f2f2f7;color:#8e8e93;display:flex;align-items:center;justify-content:center;font-size:12px}'
           . '.cx-body{flex:1 1 auto;min-width:0}.cx-place{font-weight:600}.cx-meta{color:#6e6e73;font-size:12px;margin:2px 0 8px}.cx-c{margin:0 0 8px}.cx-who{font-size:12px;color:#6e6e73}.cx-text{white-space:pre-wrap;margin:1px 0 0}'
           . '.cx-btn{display:inline-block;margin:0 0 16px;padding:8px 14px;border-radius:10px;background:#007aff;color:#fff;border:0;font:600 14px/18px inherit;cursor:pointer}'
           . 'a{color:#007aff}@media print{.cx-btn{display:none}body{margin:0}}'
           . '</style></head><body data-cexp-summary>'
           . '<h1>' . $e($title) . '</h1><p class="sub">' . $e(implode(' · ', $sub)) . '</p>'
           . ($zipPaths === null ? '<button type="button" class="cx-btn" onclick="window.print()">Print / Save as PDF</button>' : '');
        if (!$data['rows']) $h .= '<p>No comments match.</p>';
        foreach (cexpUnits($data) as $ui => $u) {
            $it = $u['item'];
            $img = $u['image'];
            $thumb = '<div class="cx-none">' . ($it['type'] === 'tire_series' ? 'Whole series' : ($it['type'] === 'post' ? 'All slides' : 'No image')) . '</div>';
            if ($img !== null && empty($img['video'])) {
                $src = $zipPaths !== null ? (isset($zipPaths[$ui]) ? implode('/', array_map('rawurlencode', explode('/', $zipPaths[$ui]))) : '')
                                          : (function_exists('pvUrl') ? pvUrl(trackingMediaUrl((string)$img['url']), 'sm') : trackingMediaUrl((string)$img['url']));
                if ($src !== '') $thumb = '<img src="' . $e($src) . '" alt="" decoding="async">';   // never lazy: a print must carry every image
            } elseif ($img !== null) {
                $thumb = '<div class="cx-none">Video</div>';
            }
            $what = $it['type'] === 'post' && $u['unit'] > 0 && count($it['images']) > 1 ? ' · slide ' . $u['unit'] : '';
            $h .= '<section class="cx-block" data-cexp-block="' . $e($it['key'] . '#' . $u['unit']) . '"><div class="cx-thumb">' . $thumb . '</div><div class="cx-body">'
                . '<div class="cx-place">' . $e(cexpPlace($it)) . ($it['type'] !== 'post' && $it['type'] !== 'tire_series' ? ' · ' . $e($it['name']) : '') . $e($what) . '</div>'
                . '<div class="cx-meta">' . $e($it['type_label'] . ' · ' . $it['status_label'] . ($it['redo'] ? ' · Redo' : '') . ' · ' . $it['state'])
                . ($it['link'] !== '' ? ' · <a href="' . $e($it['link']) . '">Open in the portal</a>' : '') . '</div>';
            foreach ($u['rows'] as $r) {
                $h .= '<div class="cx-c"><div class="cx-who">' . $e($r['author'] . ($r['date'] !== '' ? ' · ' . $r['date'] . ' ET' : '') . ($r['edited'] === 'yes' ? ' · edited' : '')) . '</div>'
                    . '<div class="cx-text">' . $e($r['comment']) . '</div></div>';
            }
            $h .= '</div></section>';
        }
        return $h . '</body></html>';
    }
}
