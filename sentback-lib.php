<?php
/**
 * "Sent back" — the client's own view of the work it marked Needs changes (status 'denied') and Joust has not
 * resubmitted yet. Loaded by helpers.php; function definitions only, no work at load.
 *
 * One label everywhere the client reads it (segments, Home, pills, toasts): sentBackLabel() = "Sent back". The admin
 * seat keeps "Needs changes" — its queue, its words; nothing here changes what Joust sees.
 *
 * What counts: posts status 'denied' and not Scheduled · emails / pages status 'denied' and not live · tire images
 * (series renders + reference images) and Library images status 'denied' (a Library file that left the folder is not
 * shown — the Library grid's rule). Always ONE company (the caller's client scope), never a draft, never an internal
 * note (activity_log.internal = 1 is filtered in SQL) and never a redo note (redo_note is never read here).
 *
 *   sentBackLabel()                         "Sent back"
 *   sentBackCounts($pdo, $client)           ['post', 'email', 'page', 'tire_image', 'library_image', 'total'] — cheap COUNTs
 *   sentBackThreads($pdo, $type, $ids)      per id: note (the latest Needs-changes note), sent_at, reply (Joust's latest
 *                                           visible reply after it), comments (live count) — one query
 *   sentBackItems($pdo, $client, $opts)     the list (newest sent back first), every kind or opts kinds / tire_id
 *   sentBackStatusLine($redo)               "Being reworked" (in Joust's Redo queue) / "Joust is reworking this"
 *   sentBackRedoFromClient($pdo, $kind, $id) the image's Redo flag came from the client's own Needs changes (auto-queued:
 *                                           no author, no note, no manual mark since) — "Approve instead" takes it off
 *   sentBackApproveRedo($pdo, $kind, $id, $batch)   that clean-up (+ an internal 'redo_cleared' row for Joust's history)
 *   sentBackSegmentUrl($kind)               where each kind's "Sent back" list lives
 *
 * Rendering (client seat only — callers check): sentBackPanelHtml() (the top of a sent-back detail sheet: status, their
 * note — editable in place —, Joust's latest reply), sentBackFooterHtml() (Add a comment · Approve instead),
 * sentBackRowInfoHtml() (the note / status / reply lines of a list row), sentBackImageRowHtml() (an Assets / Tires list
 * row the media viewer opens), sentBackHomeHtml() (the Home card).
 */

if (!function_exists('sentBackLabel')) {
    function sentBackLabel(): string { return 'Sent back'; }
}

if (!function_exists('sentBackEsc')) {
    function sentBackEsc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('sentBackStatusLine')) {
    /** What Joust is doing with it, in the client's words. In the Redo queue = "Being reworked" (redoLabel(false)). */
    function sentBackStatusLine(bool $redo): string {
        return $redo ? (function_exists('redoLabel') ? redoLabel(false) : 'Being reworked') : 'Joust is reworking this';
    }
}

if (!function_exists('sentBackLibraryFiles')) {
    /** (internal) Library rows of one company in Needs changes whose file is still in the folder: [id => filename]. */
    function sentBackLibraryFiles(PDO $pdo, array $client): array {
        if (!function_exists('hasLibraryImagesTable') || !hasLibraryImagesTable($pdo)) return [];
        $s = $pdo->prepare("SELECT id, filename FROM library_images WHERE company_id = ? AND status = 'denied' ORDER BY id ASC");
        $s->execute([(int)$client['id']]);
        $dir = libraryDir((string)$client['slug']);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $f = (string)$r['filename'];
            if ($f !== '' && strpos($f, '/') === false && is_file($dir . '/' . $f)) $out[(int)$r['id']] = $f;
        }
        return $out;
    }
}

if (!function_exists('sentBackCounts')) {
    /** How many items of each kind the client has sent back (one company). Never throws. */
    function sentBackCounts(PDO $pdo, array $client): array {
        $out = ['post' => 0, 'email' => 0, 'page' => 0, 'tire_image' => 0, 'library_image' => 0, 'total' => 0];
        $cid = (int)($client['id'] ?? 0);
        if ($cid <= 0) return $out;
        try {
            $posted = function_exists('hasPostedColumn') && hasPostedColumn($pdo) ? ' AND posted = 0' : '';
            $s = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE company_id = ? AND status = 'denied'{$posted}");
            $s->execute([$cid]);
            $out['post'] = (int)$s->fetchColumn();
            if (function_exists('hasEmailsTable') && hasEmailsTable($pdo)) {
                $s = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE company_id = ? AND status = 'denied' AND live = 0");
                $s->execute([$cid]);
                $out['email'] = (int)$s->fetchColumn();
            }
            if (function_exists('hasPagesTable') && hasPagesTable($pdo)) {
                $s = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE company_id = ? AND status = 'denied' AND live = 0");
                $s->execute([$cid]);
                $out['page'] = (int)$s->fetchColumn();
            }
            $s = $pdo->prepare("SELECT COUNT(*) FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE t.company_id = ? AND ti.status = 'denied'");
            $s->execute([$cid]);
            $out['tire_image'] = (int)$s->fetchColumn();
            $out['library_image'] = count(sentBackLibraryFiles($pdo, $client));
        } catch (Throwable $e) {
            error_log('sentBackCounts: ' . $e->getMessage());
        }
        $out['total'] = $out['post'] + $out['email'] + $out['page'] + $out['tire_image'] + $out['library_image'];
        return $out;
    }
}

if (!function_exists('sentBackThreads')) {
    /**
     * For each id of one entity type: what the client said and what Joust answered since — client-visible rows only.
     *   note     ['id', 'text', 'slide', 'at', 'actor', 'edited', 'raw'] | null — the note sent with the latest Needs
     *            changes (same batch as the 'denied' row), else the client's first comment after it, else its newest
     *   sent_at  the latest 'denied' row's time ('' when none was logged: the note's time)
     *   reply    ['text', 'slide', 'at'] | null — Joust's newest visible comment after the latest Needs changes
     *   comments live (not deleted) comment count
     * One query; internal notes (redo notes included — they are internal comments) never reach the result.
     */
    function sentBackThreads(PDO $pdo, string $type, array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; })));
        $out = [];
        foreach ($ids as $id) $out[$id] = ['note' => null, 'sent_at' => '', 'reply' => null, 'comments' => 0];
        if (!$ids || !function_exists('hasActivityLog') || !hasActivityLog($pdo)) return $out;
        $editCols = function_exists('commentEditReady') && commentEditReady($pdo) ? ', edited_at, deleted_at' : ', NULL AS edited_at, NULL AS deleted_at';
        $internal = function_exists('activityHasNotifyCols') && activityHasNotifyCols($pdo) ? ' AND internal = 0' : '';
        $rows = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $s = $pdo->prepare("SELECT id, entity_id, action, actor, detail, batch_id, created_at{$editCols}
                                      FROM activity_log
                                     WHERE entity_type = ? AND action IN ('denied', 'commented') AND entity_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . "){$internal}
                                     ORDER BY created_at ASC, id ASC");
                $s->execute(array_merge([$type], $chunk));
                foreach ($s->fetchAll() as $r) $rows[(int)$r['entity_id']][] = $r;
            }
        } catch (Throwable $e) {
            error_log('sentBackThreads: ' . $e->getMessage());
            return $out;
        }
        $split = static function (string $raw): array {
            return function_exists('commentSlideSplit') ? commentSlideSplit($raw) : [0, $raw];
        };
        foreach ($rows as $id => $list) {
            $denyIdx = -1;
            foreach ($list as $k => $r) if ($r['action'] === 'denied') $denyIdx = $k;
            $live = static function ($r) { return $r['action'] === 'commented' && empty($r['deleted_at']) && trim((string)$r['detail']) !== ''; };
            $out[$id]['comments'] = count(array_filter($list, static function ($r) { return $r['action'] === 'commented' && empty($r['deleted_at']) && trim((string)$r['detail']) !== ''; }));
            $note = null;
            if ($denyIdx >= 0) {
                $out[$id]['sent_at'] = (string)$list[$denyIdx]['created_at'];
                $batch = (string)($list[$denyIdx]['batch_id'] ?? '');
                foreach ($list as $r) {
                    if ($batch !== '' && (string)($r['batch_id'] ?? '') === $batch && $live($r)) $note = $r;
                }
                if (!$note) {   // no shared batch (older rows) or the note was deleted: the client's first word since
                    for ($k = $denyIdx + 1; $k < count($list); $k++) if ($live($list[$k]) && $list[$k]['actor'] === 'client') { $note = $list[$k]; break; }
                }
            }
            if (!$note) {
                for ($k = count($list) - 1; $k >= 0; $k--) if ($live($list[$k]) && $list[$k]['actor'] === 'client') { $note = $list[$k]; break; }
            }
            if ($note) {
                $raw = trim((string)$note['detail']);
                [$slide, $text] = $split($raw);
                $out[$id]['note'] = ['id' => (int)$note['id'], 'text' => trim((string)$text), 'slide' => (int)$slide, 'at' => (string)$note['created_at'],
                                     'actor' => (string)$note['actor'], 'edited' => !empty($note['edited_at']), 'raw' => $raw];
                if ($out[$id]['sent_at'] === '') $out[$id]['sent_at'] = (string)$note['created_at'];
            }
            for ($k = count($list) - 1; $k > $denyIdx; $k--) {
                $r = $list[$k];
                if ($r['actor'] !== 'admin' || !$live($r)) continue;
                [$slide, $text] = $split(trim((string)$r['detail']));
                $out[$id]['reply'] = ['text' => trim((string)$text), 'slide' => (int)$slide, 'at' => (string)$r['created_at']];
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('sentBackInfo')) {
    /** sentBackThreads() for one item (+ redo for images). */
    function sentBackInfo(PDO $pdo, string $type, int $id): array {
        $info = sentBackThreads($pdo, $type, [$id])[$id] ?? ['note' => null, 'sent_at' => '', 'reply' => null, 'comments' => 0];
        $kind = $type === 'tire_image' ? 'tire' : ($type === 'library_image' ? 'library' : '');
        $info['redo'] = $kind !== '' && function_exists('redoFlags') && isset(redoFlags($pdo, $kind, [$id])[$id]);
        return $info;
    }
}

if (!function_exists('sentBackSegmentUrl')) {
    /** The client's "Sent back" list for a kind (clientUrl() keeps the client scope). */
    function sentBackSegmentUrl(string $kind): string {
        switch ($kind) {
            case 'post':          return clientUrl('posts', ['status' => 'denied', 'month' => 'all']);
            case 'email':         return function_exists('emailsUrl') ? emailsUrl(['status' => 'denied']) : clientUrl('emails', ['status' => 'denied']);
            case 'page':          return function_exists('pagesUrl') ? pagesUrl(['status' => 'denied']) : clientUrl('pages', ['status' => 'denied']);
            case 'tire_image':    return clientUrl('assets', ['view' => 'collections', 'filter' => 'denied']);
            case 'library_image': return clientUrl('assets', ['view' => 'library', 'filter' => 'denied']);
        }
        return clientUrl('index');
    }
}

if (!function_exists('sentBackItems')) {
    /**
     * Every item the client sent back, newest first. $opts: kinds (subset of post|email|page|tire_image|library_image),
     * tire_id (tire images of one tire only), limit. Each item:
     *   type, id, title, context ("Klever AT2 · Series 1" / "Email · R1" …), type_label, thumb (URL or ''), video (bool),
     *   href (opens it), note / sent_at / reply / comments (sentBackThreads()), redo (bool), status_line
     *   images also: kind (tire|library), src, original, media ('image'|'video'), mime, label, download, endpoint, twin
     */
    function sentBackItems(PDO $pdo, array $client, array $opts = []): array {
        $cid = (int)($client['id'] ?? 0);
        $slug = (string)($client['slug'] ?? '');
        if ($cid <= 0) return [];
        $kinds = isset($opts['kinds']) && is_array($opts['kinds']) ? $opts['kinds'] : ['post', 'email', 'page', 'tire_image', 'library_image'];
        $base = function_exists('basePath') ? basePath() : '';
        $items = [];
        try {
            if (in_array('post', $kinds, true)) {
                $nameSel = function_exists('hasPostsNameColumn') && hasPostsNameColumn($pdo) ? 'p.name' : "'' AS name";
                $posted  = function_exists('hasPostedColumn') && hasPostedColumn($pdo) ? ' AND p.posted = 0' : '';
                $mt = function_exists('hasMediaTypeColumn') && hasMediaTypeColumn($pdo)
                    ? "(SELECT pi2.media_type FROM post_images pi2 WHERE pi2.post_id = p.id ORDER BY pi2.sort_order ASC, pi2.id ASC LIMIT 1)" : "''";
                $s = $pdo->prepare("SELECT p.id, p.caption, {$nameSel},
                                           (SELECT pi.image_url FROM post_images pi WHERE pi.post_id = p.id ORDER BY pi.sort_order ASC, pi.id ASC LIMIT 1) AS thumb_url,
                                           {$mt} AS thumb_type
                                      FROM posts p WHERE p.company_id = ? AND p.status = 'denied'{$posted}");
                $s->execute([$cid]);
                foreach ($s->fetchAll() as $r) {
                    $u = trim((string)($r['thumb_url'] ?? ''));
                    $src = $u === '' ? '' : (preg_match('#^(https?:)?//#i', $u) || $u[0] === '/' ? $u : $base . '/' . ltrim($u, '/'));
                    $isV = (string)$r['thumb_type'] === 'video' || ($u !== '' && function_exists('mediaTypeFromUrl') && mediaTypeFromUrl($u) === 'video');
                    $items[] = ['type' => 'post', 'id' => (int)$r['id'], 'type_label' => 'Post',
                                'title' => postDisplayLabel(['name' => $r['name'] ?? '', 'caption' => $r['caption'] ?? '', 'id' => (int)$r['id']]),
                                'context' => 'Post', 'thumb' => $src, 'video' => $isV,
                                'href' => clientUrl('posts', ['status' => 'denied', 'month' => 'all', 'post' => (int)$r['id']])];
                }
            }
            if (in_array('email', $kinds, true) && function_exists('hasEmailsTable') && hasEmailsTable($pdo)) {
                $s = $pdo->prepare("SELECT * FROM emails WHERE company_id = ? AND status = 'denied' AND live = 0");
                $s->execute([$cid]);
                foreach ($s->fetchAll() as $r) {
                    $code = trim((string)($r['code'] ?? ''));
                    $items[] = ['type' => 'email', 'id' => (int)$r['id'], 'type_label' => 'Email',
                                'title' => trim((string)($r['title'] ?? '')) !== '' ? trim((string)$r['title']) : (function_exists('emailDisplayLabel') ? emailDisplayLabel($r) : 'Email #' . (int)$r['id']),
                                'context' => 'Email' . ($code !== '' ? ' · ' . $code : ''), 'code' => $code, 'thumb' => '', 'video' => false,
                                'href' => function_exists('emailsUrl') ? emailsUrl(['status' => 'denied', 'email' => (int)$r['id']]) : clientUrl('emails', ['email' => (int)$r['id']])];
                }
            }
            if (in_array('page', $kinds, true) && function_exists('hasPagesTable') && hasPagesTable($pdo)) {
                $s = $pdo->prepare("SELECT * FROM pages WHERE company_id = ? AND status = 'denied' AND live = 0");
                $s->execute([$cid]);
                foreach ($s->fetchAll() as $r) {
                    $pslug = trim((string)($r['slug'] ?? ''));
                    $items[] = ['type' => 'page', 'id' => (int)$r['id'], 'type_label' => 'Page',
                                'title' => trim((string)($r['title'] ?? '')) !== '' ? trim((string)$r['title']) : ($pslug !== '' ? '/' . $pslug : 'Page #' . (int)$r['id']),
                                'context' => 'Page' . ($pslug !== '' ? ' · /' . $pslug : ''), 'thumb' => '', 'video' => false,
                                'href' => function_exists('pagesUrl') ? pagesUrl(['status' => 'denied', 'page' => (int)$r['id']]) : clientUrl('pages', ['page' => (int)$r['id']])];
                }
            }
            if (in_array('tire_image', $kinds, true)) {
                $seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);
                $hasDn = false;
                try { $hasDn = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0; } catch (Throwable $e) {}
                $tireOnly = (int)($opts['tire_id'] ?? 0) > 0 ? ' AND ti.tire_id = ?' : '';
                $s = $pdo->prepare("SELECT ti.id, ti.tire_id, ti.image_url, ti.caption, ti.client_comment, ti.sort_order, " . ($hasDn ? 'ti.display_name' : "'' AS display_name") . ", "
                    . ($seriesOn ? 'ti.series_id, s.name AS series_name, s.sort_order AS series_sort' : 'NULL AS series_id, NULL AS series_name, 0 AS series_sort') . ", t.name AS tire_name
                      FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id" . ($seriesOn ? ' LEFT JOIN tire_series s ON s.id = ti.series_id' : '') . "
                     WHERE t.company_id = ? AND ti.status = 'denied'{$tireOnly}
                     ORDER BY t.name ASC, ti.tire_id ASC, " . ($seriesOn ? '(ti.series_id IS NOT NULL) ASC, s.sort_order ASC, ' : '') . "ti.sort_order ASC, ti.id ASC");
                $s->execute($tireOnly !== '' ? [$cid, (int)$opts['tire_id']] : [$cid]);
                foreach ($s->fetchAll() as $r) {
                    $src  = function_exists('tireImageSrc') ? tireImageSrc($r) : (string)$r['image_url'];
                    $ext  = strtolower(pathinfo((string)(parse_url((string)$r['image_url'], PHP_URL_PATH) ?: $r['image_url']), PATHINFO_EXTENSION));
                    $isV  = function_exists('isVideoExt') && isVideoExt($ext);
                    $where = $r['series_id'] !== null ? (string)($r['series_name'] ?? ('Series ' . (int)$r['series_id'])) : 'Reference';
                    $label = imageDisplayLabel(['display_name' => $r['display_name'] ?? '', 'caption' => $r['caption'] ?? '', 'id' => (int)$r['id']]);
                    if (stripos($label, 'image #') === 0 || (function_exists('activityLooksLikeFilename') && activityLooksLikeFilename($label))) $label = (string)$r['tire_name'] . ' · ' . $where;
                    $stem = function_exists('safeFilenameStem') ? safeFilenameStem(((string)($r['display_name'] ?? '')) ?: ((string)$r['tire_name'] . '-' . (int)$r['id'])) : 'image';
                    $path = $isV && function_exists('tireImagePath') ? tireImagePath($r) : null;
                    $items[] = ['type' => 'tire_image', 'kind' => 'tire', 'id' => (int)$r['id'], 'type_label' => function_exists('tiresLabel') ? rtrim(tiresLabel($client), 's') . ' image' : 'Tire image',
                                'title' => $label, 'context' => (string)$r['tire_name'] . ' · ' . $where, 'tire_id' => (int)$r['tire_id'],
                                'series' => $r['series_id'] !== null ? (string)(int)$r['series_id'] : 'ref',
                                'thumb' => $isV ? '' : $src, 'video' => $isV, 'src' => $src, 'media' => $isV ? 'video' : 'image',
                                'mime' => $isV && function_exists('videoMime') ? videoMime($ext) : '', 'twin' => $isV && function_exists('videoTwinUrl') ? videoTwinUrl($src, $path) : '',
                                'download' => ($stem !== '' ? $stem : 'image') . '.' . ($ext !== '' ? $ext : 'jpg'), 'label' => $label,
                                'endpoint' => $base . '/tire-status.php', 'legacy_comment' => trim((string)($r['client_comment'] ?? '')),
                                'href' => clientUrl('assets', ['view' => 'collections', 'item' => (int)$r['tire_id'], 'filter' => 'denied', 'asset' => (int)$r['id'], 'kind' => 'tire'])];
                }
            }
            if (in_array('library_image', $kinds, true)) {
                foreach (sentBackLibraryFiles($pdo, $client) as $id => $file) {
                    $src = libraryFileUrl($slug, $file);
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $isV = function_exists('isVideoExt') && isVideoExt($ext);
                    $items[] = ['type' => 'library_image', 'kind' => 'library', 'id' => $id, 'type_label' => 'Library',
                                'title' => $isV ? 'Library video' : 'Library image', 'context' => 'Assets · Library',   // never the on-disk filename
                                'thumb' => $isV ? '' : $src, 'video' => $isV, 'src' => $src, 'media' => $isV ? 'video' : 'image',
                                'mime' => $isV && function_exists('videoMime') ? videoMime($ext) : '',
                                'twin' => $isV && function_exists('videoTwinUrl') ? videoTwinUrl($src, libraryDir($slug) . '/' . $file) : '',
                                'download' => $file, 'label' => $isV ? 'Library video' : 'Library image', 'endpoint' => $base . '/library-status.php',
                                'href' => clientUrl('assets', ['view' => 'library', 'filter' => 'denied', 'asset' => $id, 'kind' => 'library'])];
                }
            }
        } catch (Throwable $e) {
            error_log('sentBackItems: ' . $e->getMessage());
            return [];
        }
        // Threads (one query per type) + the Redo queue flags (images)
        $byType = [];
        foreach ($items as $i => $it) $byType[$it['type']][$i] = $it['id'];
        foreach ($byType as $type => $map) {
            $th = sentBackThreads($pdo, $type, array_values($map));
            $redo = [];
            if (($type === 'tire_image' || $type === 'library_image') && function_exists('redoFlags')) {
                $redo = redoFlags($pdo, $type === 'tire_image' ? 'tire' : 'library', array_values($map));
            }
            foreach ($map as $i => $id) {
                $items[$i] += $th[$id] ?? ['note' => null, 'sent_at' => '', 'reply' => null, 'comments' => 0];
                // a legacy tire_images.client_comment that never made it into the thread is still the client's word
                if (!$items[$i]['note'] && ($items[$i]['legacy_comment'] ?? '') !== '') {
                    $items[$i]['note'] = ['id' => 0, 'text' => $items[$i]['legacy_comment'], 'slide' => 0, 'at' => '', 'actor' => 'client', 'edited' => false, 'raw' => $items[$i]['legacy_comment']];
                }
                $items[$i]['redo'] = isset($redo[$id]);   // the flag only — never redo_note
                $items[$i]['status_line'] = sentBackStatusLine($items[$i]['redo']);
            }
        }
        // Newest sent back first (the list the client scans for "what did I send back last"); stable on the query order
        $order = array_flip(array_keys($items));
        uksort($items, static function ($a, $b) use ($items, $order) {
            $ta = (int)strtotime((string)($items[$a]['sent_at'] ?: '1970-01-01'));
            $tb = (int)strtotime((string)($items[$b]['sent_at'] ?: '1970-01-01'));
            return ($tb <=> $ta) ?: ($order[$a] <=> $order[$b]);
        });
        $items = array_values($items);
        if (!empty($opts['limit'])) $items = array_slice($items, 0, (int)$opts['limit']);
        return $items;
    }
}

// =====================================================================================================================
// "Approve instead" on an image: the Redo queue follows the client's change of mind
// =====================================================================================================================

if (!function_exists('sentBackRedoFromClient')) {
    /**
     * Is the image's Redo flag the one the client's own Needs changes put there (redoAutoQueue(): no author, no note,
     * nothing logged)? A Joust mark — redo_by / redo_note set, or a 'redo_marked' row since it was queued — is Joust's
     * own decision and stays.
     */
    function sentBackRedoFromClient(PDO $pdo, string $kind, int $id): bool {
        if (!function_exists('redoItemRow')) return false;
        $row = redoItemRow($pdo, $kind, $id);
        if (!$row || $row['redo_at'] === null) return false;
        if ($row['redo_by'] !== null || trim((string)($row['redo_note'] ?? '')) !== '') return false;
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE entity_type = ? AND entity_id = ? AND action = 'redo_marked' AND created_at >= ?");
            $s->execute([function_exists('redoEntityType') ? redoEntityType($kind) : ($kind === 'library' ? 'library_image' : 'tire_image'), $id, (string)$row['redo_at']]);
            return (int)$s->fetchColumn() === 0;
        } catch (Throwable $e) {
            error_log('sentBackRedoFromClient: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sentBackApproveRedo')) {
    /**
     * The client approved an image it had sent back: when its Redo flag came from that Needs changes, take it off the
     * queue (Joust's Redo view and pack drop it) and leave an internal 'redo_cleared' row in Joust's history. Returns
     * whether it was cleared. Never throws.
     */
    function sentBackApproveRedo(PDO $pdo, string $kind, int $id, int $companyId, ?string $batch = null): bool {
        try {
            if (!in_array($kind, ['tire', 'library'], true) || !function_exists('redoKindReady') || !redoKindReady($pdo, $kind)) return false;
            if (!sentBackRedoFromClient($pdo, $kind, $id)) return false;
            if (!redoClear($pdo, $kind, $id, false)) return false;
            $log = static function () use ($pdo, $kind, $id, $companyId, $batch) {
                logActivity($pdo, $companyId, redoEntityType($kind), $id, 'redo_cleared', 'client',
                    'Off the redo list: the client approved it instead', null, $batch ?? newBatchId());
            };
            if (function_exists('activityWithContext')) activityWithContext(['internal' => 1], $log); else $log();
            return true;
        } catch (Throwable $e) {
            error_log('sentBackApproveRedo: ' . $e->getMessage());
            return false;
        }
    }
}

// =====================================================================================================================
// Rendering (client seat)
// =====================================================================================================================

if (!function_exists('sentBackWhen')) {
    /** "2h ago" with the absolute time as a tooltip ('' when unknown). */
    function sentBackWhen(string $at): string {
        if ($at === '' || !function_exists('relativeTime') || relativeTime($at) === '') return '';
        return '<time datetime="' . sentBackEsc(date('c', strtotime($at) ?: time())) . '" title="' . sentBackEsc(function_exists('absoluteTime') ? absoluteTime($at) : $at) . '">' . sentBackEsc(relativeTime($at)) . '</time>';
    }
}

if (!function_exists('sentBackPanelHtml')) {
    /**
     * The top of a sent-back item's sheet (post / email / page; client seat): the "Sent back" pill + when, what Joust is
     * doing, the client's note (editable in place: comment-edit-lib.php, app.js App.comments — data-comment-host="note")
     * and Joust's latest reply. $info: sentBackThreads() / sentBackInfo() of the item. $noun: 'post' | 'email' | 'page'.
     * Rendered with [data-state="sentback"] so the page JS hides it once the client approves instead.
     */
    function sentBackPanelHtml(array $info, string $noun = 'post'): string {
        $note  = $info['note'] ?? null;
        $reply = $info['reply'] ?? null;
        $redo  = !empty($info['redo']);
        $when  = sentBackWhen((string)($info['sent_at'] ?? ''));
        // (the pill in the sheet's meta row already says "Sent back": the panel leads with what Joust is doing)
        $out = '<section class="sb-panel" data-sentback-panel data-state="sentback" aria-label="' . sentBackEsc(sentBackLabel()) . '">'
             . '<p class="sb-panel-status" data-sentback-status>' . (function_exists('icon') ? icon('wand', 'sb-status-icon') : '') . '<span>' . sentBackEsc(sentBackStatusLine($redo)) . '</span></p>'
             . '<p class="sb-panel-text text-secondary">You sent this ' . sentBackEsc($noun) . ' back' . ($when !== '' ? ' ' . $when : '') . '. It comes back to To Review when it is ready — or, if you have changed your mind, approve it instead or add a comment.</p>';
        if ($note && trim((string)$note['text']) !== '') {
            $nWhen = ($note['at'] ?? '') !== '' && function_exists('relativeTime') ? relativeTime($note['at']) : '';
            $mine = ($note['actor'] ?? '') === 'client';
            $editable = $mine && (int)($note['id'] ?? 0) > 0 && function_exists('commentEditReady') && commentEditReady($GLOBALS['pdo'] ?? null);
            $out .= '<figure class="pd-hidden-note" data-hidden-note'
                  . ($editable ? ' data-comment-id="' . (int)$note['id'] . '" data-comment-host="note" data-comment-can="edit" data-comment-raw="' . sentBackEsc((string)$note['text']) . '"'
                     . ((int)$note['slide'] > 0 ? ' data-comment-on-slide="' . (int)$note['slide'] . '"' : '') : '') . '>'
                  . '<figcaption class="pd-hidden-note-head">' . ($mine ? 'Your note' : 'The note') . ($nWhen !== '' ? ' · ' . sentBackEsc($nWhen) : '')
                  . (!empty($note['edited']) ? ' · <span class="pd-msg-edited-tag" data-comment-edited-tag>edited</span>' : '')
                  . (($editable && function_exists('commentMoreButton')) ? commentMoreButton() : '') . '</figcaption>'
                  . '<blockquote data-comment-body>' . ((int)$note['slide'] > 0 ? '<span class="pd-note-slide">On slide ' . (int)$note['slide'] . ':</span> ' : '') . nl2br(sentBackEsc($note['text'])) . '</blockquote>'
                  . '</figure>';
        }
        if ($reply && trim((string)$reply['text']) !== '') {
            $rWhen = ($reply['at'] ?? '') !== '' && function_exists('relativeTime') ? relativeTime($reply['at']) : '';
            $out .= '<figure class="pd-hidden-note pd-hidden-note--joust" data-hidden-reply>'
                  . '<figcaption class="pd-hidden-note-head">' . (function_exists('joustAvatar') ? joustAvatar('ui-avatar--xs pd-msg-avatar', '') : '') . 'Joust replied' . ($rWhen !== '' ? ' · ' . sentBackEsc($rWhen) : '') . '</figcaption>'
                  . '<blockquote>' . ((int)$reply['slide'] > 0 ? '<span class="pd-note-slide">On slide ' . (int)$reply['slide'] . ':</span> ' : '') . nl2br(sentBackEsc($reply['text'])) . '</blockquote>'
                  . '</figure>';
        }
        return $out . '</section>';
    }
}

if (!function_exists('sentBackFooterHtml')) {
    /** The sheet's action row for a sent-back item (client seat): Add a comment · Approve instead (asks first — app.js). */
    function sentBackFooterHtml(bool $show): string {
        return '<div class="ui-btn-group pd-sentback" data-state="sentback"' . ($show ? '' : ' hidden') . '>'
             . '<button type="button" class="ui-btn ui-btn--large ui-btn--gray" data-sentback-comment>Add a comment</button>'
             . '<button type="button" class="ui-btn ui-btn--large ui-btn--approve ui-btn--primary" data-approve-instead>Approve instead</button>'
             . '</div>';
    }
}

if (!function_exists('sentBackRowInfoHtml')) {
    /** The note / when / status / reply lines of a "Sent back" list row (posts, emails, pages, the Assets list). */
    function sentBackRowInfoHtml(array $info): string {
        $note = $info['note'] ?? null;
        $reply = $info['reply'] ?? null;
        $text = $note ? (string)$note['text'] : '';
        if (mb_strlen($text) > 220) $text = rtrim(mb_substr($text, 0, 219)) . '…';
        $when = sentBackWhen((string)($info['sent_at'] ?? ''));
        $out = '<div class="pl-note sb-note' . ($text === '' ? ' pl-note--empty' : '') . '" data-sentback-note>'
             . ($text !== '' ? (($note && (int)$note['slide'] > 0) ? 'On slide ' . (int)$note['slide'] . ': ' : '') . '<q>' . sentBackEsc($text) . '</q>' : '<span>No note left</span>')
             . (($meta = trim(($text !== '' ? (($note['actor'] ?? 'client') === 'client' ? 'Your note' : 'Note') : '') . ($when !== '' ? ($text !== '' ? ' · sent back ' : 'Sent back ') . $when : ''))) !== ''
                ? '<span class="pl-note-meta">' . $meta . '</span>' : '')
             . '</div>'
             . '<div class="sb-status" data-sentback-line>' . (function_exists('icon') ? icon('wand', 'sb-status-icon') : '') . '<span>' . sentBackEsc(sentBackStatusLine(!empty($info['redo']))) . '</span></div>';
        if ($reply && trim((string)$reply['text']) !== '') {
            $r = (string)$reply['text'];
            if (mb_strlen($r) > 160) $r = rtrim(mb_substr($r, 0, 159)) . '…';
            $rWhen = sentBackWhen((string)($reply['at'] ?? ''));
            $out .= '<div class="sb-reply" data-sentback-reply>' . (function_exists('joustAvatar') ? joustAvatar('ui-avatar--xs sb-reply-avatar', '') : '')
                  . '<span><span class="sb-reply-who">Joust replied' . ($rWhen !== '' ? ' · ' . $rWhen : '') . '</span> <q>' . sentBackEsc($r) . '</q></span></div>';
        }
        return $out;
    }
}

if (!function_exists('sentBackImageRowHtml')) {
    /**
     * One image row of the Assets / Tires "Sent back" list. It is the grid's [data-asset] element (same data-* as a tile,
     * assets.php assetsTileHtml()), so static/js/assets.js opens the media viewer over the list, moves the counts and
     * lets the row leave when the client approves instead. No JS: the href deep-links the viewer.
     */
    function sentBackImageRowHtml(array $it, int $index, int $total): string {
        $pv = $it['media'] === 'video' ? ['thumb' => $it['src'], 'large' => $it['src'], 'original' => $it['src']] : (function_exists('pvUrls') ? pvUrls((string)$it['src']) : ['thumb' => $it['src'], 'large' => $it['src'], 'original' => $it['src']]);
        $thumb = $it['media'] === 'video'
            ? (function_exists('videoTile') ? videoTile((string)$it['src'], ['badge' => false, 'probe' => false, 'class' => 'sb-thumb-video']) : (function_exists('icon') ? icon('play') : ''))
            : (function_exists('pvImg') ? pvImg((string)$it['src'], 'sm', ['sizes' => function_exists('pvSizes') ? pvSizes('row') : '', 'eager' => $index <= 6]) : '');
        return '<a class="ui-row ui-row--leading sb-row" role="listitem" href="' . sentBackEsc($it['href']) . '"'
             . ' id="' . sentBackEsc(($it['kind'] === 'tire' ? 'image-' : 'lib-') . $it['id']) . '"'
             . ' data-asset data-id="' . (int)$it['id'] . '" data-kind="' . sentBackEsc($it['kind']) . '" data-status="denied"'
             . ' data-src="' . sentBackEsc($pv['large']) . '" data-original="' . sentBackEsc($pv['original']) . '" data-thumb="' . sentBackEsc($pv['thumb']) . '"'
             . ' data-type="' . sentBackEsc($it['media']) . '"' . ($it['mime'] !== '' ? ' data-mime="' . sentBackEsc($it['mime']) . '"' : '')
             . ' data-label="' . sentBackEsc($it['label']) . '" data-download="' . sentBackEsc($it['download']) . '" data-endpoint="' . sentBackEsc($it['endpoint']) . '"'
             . (!empty($it['twin']) ? ' data-twin="' . sentBackEsc($it['twin']) . '"' : '')
             . (isset($it['series']) ? ' data-series="' . sentBackEsc((string)$it['series']) . '"' : '')
             . ' data-comments="' . (int)($it['comments'] ?? 0) . '"' . (!empty($it['redo']) ? ' data-redo="1"' : '')
             . ' data-sentback-line="' . sentBackEsc((string)$it['status_line']) . '"'
             . ' aria-label="' . sentBackEsc('Open ' . $it['title'] . ', ' . $index . ' of ' . $total) . '">'
             . '<span class="ui-row-leading sb-thumb' . ($it['media'] === 'video' ? ' sb-thumb--video' : '') . '">' . $thumb . '</span>'
             . '<span class="ui-row-body">'
             . '<span class="pl-top"><span class="ui-row-title pl-title">' . (function_exists('trackingUnreadDot') ? trackingUnreadDot($it['type'], (int)$it['id']) : '') . sentBackEsc($it['title']) . '</span></span>'
             . '<span class="pl-caption">' . sentBackEsc($it['context']) . '</span>'
             . sentBackRowInfoHtml($it)
             . '</span>'
             . (function_exists('icon') ? icon('chevron-right', 'ui-row-chevron') : '')
             . '</a>';
    }
}

if (!function_exists('sentBackHomeHtml')) {
    /**
     * Client Home: "Sent back" — what the client sent back and Joust is still working on (newest first, five at most),
     * each row opening the item, plus one link per list ("Posts 2 · Emails 1 …"). The count is a neutral grey badge:
     * these wait on Joust, not on the client (the red attention badges never count them). '' when nothing is sent back.
     */
    function sentBackHomeHtml(PDO $pdo, array $client): string {
        $counts = sentBackCounts($pdo, $client);
        if ($counts['total'] <= 0) return '';
        $items = sentBackItems($pdo, $client, ['limit' => 5]);
        $icons = ['post' => 'grid', 'email' => 'mail', 'page' => 'page', 'tire_image' => 'tire', 'library_image' => 'photo'];
        $head = sentBackEsc(sentBackLabel()) . ' <span class="ui-badge ui-badge--neutral sb-count" data-sentback-count>' . (int)$counts['total'] . '</span>';
        $out = '<section class="home-section" aria-labelledby="home-sentback" data-home-sentback="' . (int)$counts['total'] . '">'
             . '<h2 class="ui-list-header" id="home-sentback">' . $head . '</h2>'
             . insetListOpen('', ['class' => 'home-sentback']);
        foreach ($items as $it) {
            $note = $it['note'] ? (string)$it['note']['text'] : '';
            if (mb_strlen($note) > 90) $note = rtrim(mb_substr($note, 0, 89)) . '…';
            $leading = $it['thumb'] !== '' && function_exists('pvImg') ? pvImg((string)$it['thumb'], 'sm', ['alt' => '']) : '';
            $sub = $it['context'] . ' · ' . $it['status_line'] . ($note !== '' ? ' · “' . $note . '”' : '');
            $out .= insetRow(['href' => $it['href'], 'leading' => $leading, 'icon' => $leading === '' ? ($icons[$it['type']] ?? 'xmark') : null,
                              'title' => $it['title'], 'subtitle' => $sub,
                              'trailing' => !empty($it['reply']) ? '<span class="sb-home-reply" title="Joust replied">' . (function_exists('icon') ? icon('bubble') : '') . '</span>' : '',
                              'attrs' => ['data-sentback-row' => $it['type'] . ':' . $it['id']]]);
        }
        $links = [];
        $names = ['post' => 'Posts', 'email' => 'Emails', 'page' => 'Pages', 'library_image' => 'Assets',
                  'tire_image' => function_exists('tiresLabel') ? tiresLabel($client) : 'Tires'];
        foreach ($names as $k => $name) {
            if ($counts[$k] > 0) $links[] = '<a href="' . sentBackEsc(sentBackSegmentUrl($k)) . '" data-sentback-link="' . $k . '">' . sentBackEsc($name) . ' <span class="sb-link-count">' . (int)$counts[$k] . '</span></a>';
        }
        $more = $counts['total'] > count($items) ? '+' . ($counts['total'] - count($items)) . ' more · ' : '';
        return $out . insetListClose('Joust is working on these — open one to approve it instead or add a comment.'
             . '<span class="sb-home-foot">' . $more . 'See all in ' . implode(' · ', $links) . '</span>', true)
             . '</section>';
    }
}
