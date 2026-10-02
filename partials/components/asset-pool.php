<?php
/**
 * Studio — Approved Pool (spec §4.5). Shared by add-post.php, post-compose.php
 * and batch-process.php. Every function is function_exists-guarded
 * and none of them produce output on include. NO schema changes: only the
 * columns catalogued in the analysis (§C) are read.
 *
 * Data
 *   studioApprovedPool(PDO $pdo, array $client): array
 *       ['assets' => [asset, …], 'collections' => [['id','name','count'], …],
 *        'counts' => ['library' => n, 'tire' => n]]
 *       asset = ['kind' => 'library'|'tire', 'id', 'key' => 'library:12', 'src' (root-rooted URL),
 *                'path' (absolute file path), 'label', 'group' => 'library'|'tire:30',
 *                'group_label', 'ext', 'media' => 'image'|'video']
 *       Library  = library_images WHERE company_id = ? AND status = 'approved'  (file must exist on disk)
 *       Tires    = tire_images JOIN tires ON tires.id = tire_images.tire_id
 *                  WHERE tires.company_id = ? AND tire_images.status = 'approved'
 *
 *   studioParsePicks($raw, int $max = POST_MAX_MEDIA): array
 *       Normalises the form value assets[] ("library:12", "tire:34") → [['kind','id'], …]
 *       in the order given, de-duplicated, capped at $max.
 *
 *   studioResolveAsset(PDO $pdo, array $client, string $kind, int $id): ?array
 *       Server-side validation: the asset must belong to this company AND be
 *       status='approved' AND exist on disk. Anything else → null.
 *
 *   studioCopyAssetToUploads(array $asset, string $uploadsDir): string
 *       COPY (never move) the file into uploads/ under a fresh unique name that
 *       follows the existing naming convention (img_<uniqid>.<ext> / vid_…).
 *       Returns the relative image_url value ('uploads/img_….jpg'). Throws on failure.
 *
 *   studioAttachAssetsToPost(PDO $pdo, array $client, int $postId, array $picks, array $opts = []): array
 *       Resolve → copy → INSERT post_images (sort_order continues from the
 *       post's current MAX, media_type when the column exists). Throws
 *       StudioAssetException (code 400/403) on an invalid pick so the caller's
 *       transaction rolls back; files copied before the failure are unlinked.
 *       Returns the inserted rows [['id','key','image_url','sort_order','media_type','asset'], …] (id = post_images.id).
 *
 * Grouping (the New post pop-up's Approved picker, post-compose.php)
 *   studioPoolGroups(array $pool): array — Library, then one group per collection whose page units are
 *       its Reference images first, then each series (chip order).
 *
 * The old Compose / Batch picker markup (studioPickerHtml, studioComposerHtml, studioPreviewHtml, pool tiles
 * and studio.php?partial=pool paging) was retired with those screens; the pop-up renders its own picker.
 */

if (!class_exists('StudioAssetException')) {
    class StudioAssetException extends RuntimeException {}
}

if (!function_exists('studioEsc')) {
    function studioEsc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('studioAppRoot')) {
    /** Absolute path of the app root (this file lives in partials/components/). */
    function studioAppRoot(): string { return dirname(__DIR__, 2); }
}

if (!function_exists('studioUploadsDir')) {
    function studioUploadsDir(): string { return studioAppRoot() . '/uploads'; }
}

if (!function_exists('studioRootUrl')) {
    /** Root-rooted URL for an app-relative path ('uploads/x.jpg'). Absolute/root URLs pass through. */
    function studioRootUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('#^(https?:)?//#i', $path) || $path[0] === '/') return $path;
        return (function_exists('basePath') ? basePath() : '') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('studioHasTireDisplayName')) {
    function studioHasTireDisplayName(PDO $pdo): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $cached = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0;
        } catch (Throwable $e) {
            $cached = false;
        }
        return $cached;
    }
}

if (!function_exists('studioHasTireSeriesColumn')) {
    /** tire_images.series_id exists once tire-series-lib.php's migration ran (NULL = a reference image). */
    function studioHasTireSeriesColumn(PDO $pdo): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $cached = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'series_id'")->rowCount() > 0;
        } catch (Throwable $e) {
            $cached = false;
        }
        return $cached;
    }
}

if (!function_exists('studioTireImagePath')) {
    /** Absolute filesystem path for a tire_images.image_url ('uploads/feat_x.jpg'), or '' if it is not a local file. */
    function studioTireImagePath(string $imageUrl): string
    {
        $imageUrl = ltrim(trim($imageUrl), '/');
        if ($imageUrl === '' || preg_match('#^(https?:)?//#i', $imageUrl)) return '';
        if (strpos($imageUrl, '..') !== false) return '';
        return studioAppRoot() . '/' . $imageUrl;
    }
}

if (!function_exists('studioAssetFromLibraryRow')) {
    function studioAssetFromLibraryRow(array $row, array $client): array
    {
        $file = (string)$row['filename'];
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        // A library row must name a plain file inside the brand folder — never a path.
        $safe = $file !== '' && $file === basename($file) && strpos($file, '..') === false;
        $dir  = libraryDir((string)$client['slug']);
        return [
            'kind'        => 'library',
            'id'          => (int)$row['id'],
            'key'         => 'library:' . (int)$row['id'],
            'src'         => libraryFileUrl((string)$client['slug'], $file),
            'path'        => $safe ? $dir . '/' . $file : '',
            'dir'         => $dir,
            'label'       => function_exists('safeFilenameStem') ? safeFilenameStem($file) : pathinfo($file, PATHINFO_FILENAME),
            'group'       => 'library',
            'group_label' => 'Library',
            'ext'         => $ext,
            'media'       => (function_exists('isVideoExt') && isVideoExt($ext)) ? 'video' : 'image',
        ];
    }
}

if (!function_exists('studioAssetFromTireRow')) {
    function studioAssetFromTireRow(array $row): array
    {
        $url   = (string)$row['image_url'];
        $ext   = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        $label = trim((string)($row['display_name'] ?? ''));
        if ($label === '') $label = trim((string)($row['caption'] ?? ''));
        if ($label === '') $label = 'Image #' . (int)$row['id'];
        // Series rows (tire-series-lib.php): src/thumb come from the lib. Files dropped by FTP live in
        // media/tires/<tire>/<series>/ next to the app (like libraryDir()), uploads in uploads/ as before.
        $hasSeries = array_key_exists('series_id', $row) && function_exists('tireImageSrc');
        $src   = $hasSeries ? (string)tireImageSrc($row) : $url;
        $thumb = $hasSeries && function_exists('tireImageThumb') ? (string)tireImageThumb($row) : $src;
        $rel   = ltrim($url, '/');
        $dir   = '';
        if (function_exists('tireImagePath')) {                        // the lib validates + realpath-contains (null when missing)
            $path = (string)(tireImagePath($row) ?? '');
            $dir  = $path !== '' && strpos($rel, 'media/tires/') === 0 ? dirname($path) : '';
        } elseif (isset($row['path']) && (string)$row['path'] !== '') {
            $path = (string)$row['path'];
            $dir  = dirname($path);
        } elseif (strpos($rel, 'media/tires/') === 0 && strpos($rel, '..') === false) {
            $dir  = dirname(studioAppRoot()) . '/media/tires';      // sibling of the app folder (helpers.php libraryDir() convention)
            $path = dirname(studioAppRoot()) . '/' . $rel;
        } else {
            $path = studioTireImagePath($url);
        }
        return [
            'kind'         => 'tire',
            'id'           => (int)$row['id'],
            'key'          => 'tire:' . (int)$row['id'],
            'src'          => studioRootUrl($src),
            'thumb'        => studioRootUrl($thumb !== '' ? $thumb : $src),
            'path'         => $path,
            'dir'          => $dir,
            'label'        => $label,
            'group'        => 'tire:' . (int)$row['tire_id'],
            'group_label'  => (string)($row['tire_name'] ?? 'Tire'),
            'series'       => !empty($row['series_id']) ? (string)(int)$row['series_id'] : 'ref',   // 'ref' = a reference image
            'series_label' => (string)($row['series_name'] ?? ''),
            'ext'          => $ext,
            'media'        => (function_exists('isVideoExt') && isVideoExt($ext)) ? 'video' : 'image',
        ];
    }
}

if (!function_exists('studioApprovedPool')) {
    function studioApprovedPool(PDO $pdo, array $client): array
    {
        $cid = (int)($client['id'] ?? 0);
        $assets = [];
        $collections = [];
        $counts = ['library' => 0, 'tire' => 0];
        if ($cid <= 0) return ['assets' => [], 'collections' => [], 'counts' => $counts];

        // Library — approved rows whose file is still in media/library/<slug>/
        if (function_exists('hasLibraryImagesTable') && hasLibraryImagesTable($pdo)) {
            $st = $pdo->prepare("
                SELECT id, filename, status, created_at
                FROM library_images
                WHERE company_id = ? AND status = 'approved'
                ORDER BY filename ASC
            ");
            $st->execute([$cid]);
            foreach ($st->fetchAll() as $row) {
                $a = studioAssetFromLibraryRow($row, $client);
                if ($a['path'] === '' || !is_file($a['path'])) continue;
                $assets[] = $a;
                $counts['library']++;
            }
        }

        // Collections (tires module) — approved images, scoped by company through the tires JOIN.
        // With tire series (tire-series-lib.php) each row also carries series_id so the picker can chip by series.
        $nameSel   = studioHasTireDisplayName($pdo) ? 'ti.display_name,' : "'' AS display_name,";
        $seriesOn  = function_exists('hasTireSeries') && hasTireSeries($pdo) && studioHasTireSeriesColumn($pdo);
        $seriesSel = $seriesOn ? 'ti.series_id,' : '';
        $st = $pdo->prepare("
            SELECT ti.id, ti.tire_id, ti.image_url, ti.caption, {$nameSel} {$seriesSel} ti.sort_order,
                   t.name AS tire_name
            FROM tire_images ti
            INNER JOIN tires t ON t.id = ti.tire_id
            WHERE t.company_id = ? AND ti.status = 'approved'
            ORDER BY t.name ASC, ti.sort_order ASC, ti.id ASC
        ");
        $st->execute([$cid]);
        $rows = $st->fetchAll();
        $seriesNames = [];   // tire id → [series id → name] (one lib call per collection)
        if ($seriesOn && function_exists('tireSeriesForTire')) {
            foreach (array_unique(array_map('intval', array_column($rows, 'tire_id'))) as $tid) {
                $seriesNames[$tid] = [];
                foreach (tireSeriesForTire($pdo, $tid) as $sr) { $seriesNames[$tid][(int)$sr['id']] = (string)$sr['name']; }
            }
        }
        foreach ($rows as $row) {
            $tid = (int)$row['tire_id'];
            if ($seriesOn) {
                $row['series_name'] = !empty($row['series_id']) ? ($seriesNames[$tid][(int)$row['series_id']] ?? ('Series ' . (int)$row['series_id'])) : 'Reference';
            }
            $a = studioAssetFromTireRow($row);
            if ($a['path'] === '' || !is_file($a['path'])) continue;   // a render whose file left the folder is not offered
            $assets[] = $a;
            $counts['tire']++;
            if (!isset($collections[$tid])) {
                $collections[$tid] = ['id' => $tid, 'name' => (string)($row['tire_name'] ?? ''), 'count' => 0, 'series' => []];
            }
            $collections[$tid]['count']++;
            if ($seriesOn) {   // series chips per collection: key 'ref' | '<id>' → approved count (ordered below)
                $sk = $a['series'];
                if (!isset($collections[$tid]['series'][$sk])) $collections[$tid]['series'][$sk] = ['key' => $sk, 'name' => $a['series_label'], 'count' => 0];
                $collections[$tid]['series'][$sk]['count']++;
            }
        }
        // Chip order = Reference first, then the tire's series in their own (sort_order) sequence.
        foreach ($collections as $tid => &$c) {
            $ordered = [];
            if (isset($c['series']['ref'])) $ordered[] = $c['series']['ref'];
            foreach ($seriesNames[$tid] ?? [] as $sid => $sname) { if (isset($c['series'][(string)$sid])) $ordered[] = $c['series'][(string)$sid]; }
            foreach ($c['series'] as $sk => $chip) { if ($sk !== 'ref' && !isset($seriesNames[$tid][(int)$sk])) $ordered[] = $chip; }
            $c['series'] = $ordered;
        }
        unset($c);

        return ['assets' => $assets, 'collections' => array_values($collections), 'counts' => $counts];
    }
}

if (!function_exists('studioParsePicks')) {
    function studioParsePicks($raw, int $max = 0): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') return [];
            $decoded = null;
            if ($raw[0] === '[') { $decoded = json_decode($raw, true); }
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $raw);
        }
        if (!is_array($raw)) return [];
        if ($max <= 0) $max = defined('POST_MAX_MEDIA') ? POST_MAX_MEDIA : 20;
        $out = []; $seen = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $kind = (string)($item['kind'] ?? ''); $id = (int)($item['id'] ?? 0);
            } elseif (is_string($item) && preg_match('/^\s*(library|tire)\s*[:\-_]\s*(\d+)\s*$/i', $item, $m)) {
                $kind = strtolower($m[1]); $id = (int)$m[2];
            } else {
                continue;
            }
            $kind = strtolower(trim($kind));
            if (!in_array($kind, ['library', 'tire'], true) || $id <= 0) continue;
            $key = $kind . ':' . $id;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['kind' => $kind, 'id' => $id, 'key' => $key];
            if (count($out) >= $max) break;
        }
        return $out;
    }
}

if (!function_exists('studioResolveAsset')) {
    function studioResolveAsset(PDO $pdo, array $client, string $kind, int $id): ?array
    {
        $cid = (int)($client['id'] ?? 0);
        if ($cid <= 0 || $id <= 0) return null;
        $kind = strtolower(trim($kind));

        if ($kind === 'library') {
            if (!function_exists('hasLibraryImagesTable') || !hasLibraryImagesTable($pdo)) return null;
            $st = $pdo->prepare("
                SELECT id, filename, status FROM library_images
                WHERE id = ? AND company_id = ? AND status = 'approved'
                LIMIT 1
            ");
            $st->execute([$id, $cid]);
            $row = $st->fetch();
            if (!$row || ($row['status'] ?? '') !== 'approved') return null;
            $a = studioAssetFromLibraryRow($row, $client);
            return ($a['path'] !== '' && is_file($a['path'])) ? $a : null;
        }

        if ($kind === 'tire') {
            $nameSel   = studioHasTireDisplayName($pdo) ? 'ti.display_name,' : "'' AS display_name,";
            $seriesSel = (function_exists('hasTireSeries') && hasTireSeries($pdo) && studioHasTireSeriesColumn($pdo)) ? 'ti.series_id,' : '';
            $st = $pdo->prepare("
                SELECT ti.id, ti.tire_id, ti.image_url, ti.caption, {$nameSel} {$seriesSel} ti.status,
                       t.name AS tire_name
                FROM tire_images ti
                INNER JOIN tires t ON t.id = ti.tire_id
                WHERE ti.id = ? AND t.company_id = ? AND ti.status = 'approved'
                LIMIT 1
            ");
            $st->execute([$id, $cid]);
            $row = $st->fetch();
            if (!$row || ($row['status'] ?? '') !== 'approved') return null;
            $a = studioAssetFromTireRow($row);
            return ($a['path'] !== '' && is_file($a['path'])) ? $a : null;
        }
        return null;
    }
}

if (!function_exists('studioCopyAssetToUploads')) {
    function studioCopyAssetToUploads(array $asset, string $uploadsDir = ''): string
    {
        $uploadsDir = $uploadsDir !== '' ? rtrim($uploadsDir, '/') : studioUploadsDir();
        $src = (string)($asset['path'] ?? '');
        if ($src === '' || !is_file($src)) {
            throw new StudioAssetException('Source file is missing for ' . ($asset['key'] ?? 'asset') . '.', 400);
        }
        $ext = strtolower((string)($asset['ext'] ?? pathinfo($src, PATHINFO_EXTENSION)));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext);
        // Destination extension whitelist — only web media ever lands in uploads/ (never .php/.svg/…).
        $allowedExt = array_merge(
            function_exists('imageExts') ? imageExts() : ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            function_exists('videoExts') ? videoExts() : ['mp4', 'webm', 'mov']
        );
        if (!in_array($ext, $allowedExt, true)) {
            throw new StudioAssetException('Unsupported file type for ' . ($asset['label'] ?? 'the file') . ' — use JPG, PNG, GIF, WebP, MP4, WebM or MOV.', 400);
        }
        // Source containment: the file must resolve inside this brand's library folder
        // (asset['dir']) or this app's uploads/ — a DB row cannot point the copy elsewhere.
        $real = realpath($src);
        $roots = [];
        foreach ([(string)($asset['dir'] ?? ''), $uploadsDir] as $root) {
            if ($root === '') continue;
            $r = realpath($root);
            if ($r !== false) $roots[] = rtrim($r, '/');
        }
        $contained = false;
        if ($real !== false) {
            foreach ($roots as $r) {
                if (dirname($real) === $r || strpos($real, $r . '/') === 0) { $contained = true; break; }
            }
        }
        if (!$contained) {
            throw new StudioAssetException('Source file for ' . ($asset['label'] ?? 'the file') . ' is outside the media folders.', 400);
        }
        $src = $real;
        if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }
        if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
            throw new StudioAssetException('uploads/ is not writable.', 500);
        }
        $prefix  = (($asset['media'] ?? 'image') === 'video') ? 'vid_' : 'img_';
        for ($try = 0; $try < 5; $try++) {
            $newName = uniqid($prefix, true) . '.' . $ext;
            $newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $newName);
            $dest    = $uploadsDir . '/' . $newName;
            if (file_exists($dest)) continue;
            if (@copy($src, $dest)) {
                @chmod($dest, 0644);
                // Reuse the source's fresh sm / lg previews (a byte copy) — no decode here; add-post.php makes any missing ones after its commit.
                if (($asset['media'] ?? 'image') !== 'video' && function_exists('previewCopyDerivatives')) previewCopyDerivatives($src, $dest, false);
                return 'uploads/' . $newName;
            }
            break;
        }
        throw new StudioAssetException('Could not copy ' . ($asset['label'] ?? 'the file') . ' into uploads/.', 500);
    }
}

if (!function_exists('studioAttachAssetsToPost')) {
    function studioAttachAssetsToPost(PDO $pdo, array $client, int $postId, array $picks, array $opts = []): array
    {
        $picks = studioParsePicks($picks, (int)($opts['max'] ?? (defined('POST_MAX_MEDIA') ? POST_MAX_MEDIA : 20)));
        if (!$picks) return [];
        $uploadsDir = (string)($opts['uploadsDir'] ?? studioUploadsDir());
        $slots      = array_key_exists('slots', $opts) ? (int)$opts['slots'] : count($picks);
        if ($slots <= 0) return [];
        $picks = array_slice($picks, 0, $slots);

        // Validate everything first so nothing is copied for a request that must fail.
        $resolved = [];
        foreach ($picks as $p) {
            $a = studioResolveAsset($pdo, $client, $p['kind'], $p['id']);
            if (!$a) {
                $isLibrary = $p['kind'] === 'library';
                throw new StudioAssetException(
                    ($isLibrary ? 'Library image' : 'Tire image') . ' #' . $p['id']
                    . ' is not an approved asset for ' . ($client['name'] ?? 'this client') . '.', 403);
            }
            $resolved[] = $a;
        }

        $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM post_images WHERE post_id = ?");
        $sortQ->execute([$postId]);
        $sortOrder = (int)$sortQ->fetchColumn();
        $hasMedia  = function_exists('hasMediaTypeColumn') && hasMediaTypeColumn($pdo);

        $copied = [];
        $rows   = [];
        try {
            foreach ($resolved as $a) {
                $rel = studioCopyAssetToUploads($a, $uploadsDir);
                $copied[] = $uploadsDir . '/' . basename($rel);
                $sortOrder++;
                if ($hasMedia) {
                    $ins = $pdo->prepare("INSERT INTO post_images (post_id, image_url, media_type, sort_order) VALUES (?, ?, ?, ?)");
                    $ins->execute([$postId, $rel, $a['media'], $sortOrder]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO post_images (post_id, image_url, sort_order) VALUES (?, ?, ?)");
                    $ins->execute([$postId, $rel, $sortOrder]);
                }
                $rows[] = ['id' => (int)$pdo->lastInsertId(), 'key' => (string)$a['key'], 'image_url' => $rel, 'sort_order' => $sortOrder, 'media_type' => $a['media'], 'asset' => $a];
            }
        } catch (Throwable $e) {
            foreach ($copied as $f) { if (is_file($f)) @unlink($f); }
            throw $e;
        }
        return $rows;
    }
}

// ---------------------------------------------------------------------
// Markup
// ---------------------------------------------------------------------

if (!function_exists('studioPoolGroups')) {
    /**
     * The pool as rendered: Library, then one group per collection whose page units are its Reference images
     * first, then each series (chip order). [['key','kind','label','count','series' (chips),'pages' => [
     * ['key' => 'library' | 'tire:<id>|<ref|series id>', 'label', 'series', 'assets' => [...]], …]], …]
     */
    function studioPoolGroups(array $pool): array
    {
        $groups = [];
        foreach (($pool['assets'] ?? []) as $a) {
            $gk = (string)$a['group'];
            if (!isset($groups[$gk])) {
                $groups[$gk] = ['key' => $gk, 'kind' => $a['kind'], 'label' => (string)$a['group_label'], 'count' => 0, 'series' => [], 'pages' => []];
            }
            $groups[$gk]['count']++;
            $sk = $a['kind'] === 'tire' ? (string)($a['series'] ?? 'ref') : '';
            $pk = $a['kind'] === 'tire' ? $gk . '|' . $sk : 'library';
            if (!isset($groups[$gk]['pages'][$pk])) {
                $label = $a['kind'] === 'tire' ? ($sk === 'ref' ? 'Reference' : ((string)($a['series_label'] ?? '') !== '' ? (string)$a['series_label'] : 'Series ' . $sk)) : '';
                $groups[$gk]['pages'][$pk] = ['key' => $pk, 'label' => $label, 'series' => $sk, 'assets' => []];
            }
            $groups[$gk]['pages'][$pk]['assets'][] = $a;
        }
        foreach (($pool['collections'] ?? []) as $c) {
            $gk = 'tire:' . (int)$c['id'];
            if (!isset($groups[$gk])) continue;
            $groups[$gk]['series'] = $c['series'] ?? [];
            // Page order = Reference first, then the series in chip order (studioApprovedPool orders the chips), then anything else.
            $ordered = [];
            foreach ($groups[$gk]['series'] as $chip) { $pk = $gk . '|' . $chip['key']; if (isset($groups[$gk]['pages'][$pk])) $ordered[$pk] = $groups[$gk]['pages'][$pk]; }
            if (isset($groups[$gk]['pages'][$gk . '|ref'])) $ordered = [$gk . '|ref' => $groups[$gk]['pages'][$gk . '|ref']] + $ordered;
            $groups[$gk]['pages'] = $ordered + $groups[$gk]['pages'];
        }
        foreach ($groups as &$g) { $g['pages'] = array_values($g['pages']); }
        unset($g);
        // Library first, then the collections in pool order (tire name)
        uasort($groups, static function ($x, $y) { return ($x['kind'] === 'library' ? 0 : 1) <=> ($y['kind'] === 'library' ? 0 : 1); });
        return array_values($groups);
    }
}
