<?php
/**
 * Studio — Approved Pool (spec §4.5). Shared by add-post.php, batch.php,
 * batch-process.php and studio.php. Every function is function_exists-guarded
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
 *   studioParsePicks($raw, int $max = 10): array
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
 *       Returns the inserted rows [['image_url','sort_order','media_type','asset'], …].
 *
 * Rendering (markup only; wired by static/js/studio.js)
 *   studioPickerHtml(array $pool, array $opts = []): string
 *   studioPreviewHtml(array $post, array $brand, array $images = [], array $opts = []): string
 *   studioComposerHtml(array $ctx): string
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
        return [
            'kind'        => 'tire',
            'id'          => (int)$row['id'],
            'key'         => 'tire:' . (int)$row['id'],
            'src'         => studioRootUrl($url),
            'path'        => studioTireImagePath($url),
            'label'       => $label,
            'group'       => 'tire:' . (int)$row['tire_id'],
            'group_label' => (string)($row['tire_name'] ?? 'Collection'),
            'ext'         => $ext,
            'media'       => (function_exists('isVideoExt') && isVideoExt($ext)) ? 'video' : 'image',
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

        // Collections (tires module) — approved images, scoped by company through the tires JOIN
        $nameSel = studioHasTireDisplayName($pdo) ? 'ti.display_name,' : "'' AS display_name,";
        $st = $pdo->prepare("
            SELECT ti.id, ti.tire_id, ti.image_url, ti.caption, {$nameSel} ti.sort_order,
                   t.name AS tire_name
            FROM tire_images ti
            INNER JOIN tires t ON t.id = ti.tire_id
            WHERE t.company_id = ? AND ti.status = 'approved'
            ORDER BY t.name ASC, ti.sort_order ASC, ti.id ASC
        ");
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $row) {
            $a = studioAssetFromTireRow($row);
            $assets[] = $a;
            $counts['tire']++;
            $tid = (int)$row['tire_id'];
            if (!isset($collections[$tid])) {
                $collections[$tid] = ['id' => $tid, 'name' => (string)($row['tire_name'] ?? ''), 'count' => 0];
            }
            $collections[$tid]['count']++;
        }

        return ['assets' => $assets, 'collections' => array_values($collections), 'counts' => $counts];
    }
}

if (!function_exists('studioParsePicks')) {
    function studioParsePicks($raw, int $max = 10): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') return [];
            $decoded = null;
            if ($raw[0] === '[') { $decoded = json_decode($raw, true); }
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $raw);
        }
        if (!is_array($raw)) return [];
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
            $nameSel = studioHasTireDisplayName($pdo) ? 'ti.display_name,' : "'' AS display_name,";
            $st = $pdo->prepare("
                SELECT ti.id, ti.tire_id, ti.image_url, ti.caption, {$nameSel} ti.status,
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
        $picks = studioParsePicks($picks, (int)($opts['max'] ?? 10));
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
                    ($isLibrary ? 'Library image' : 'Collection image') . ' #' . $p['id']
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
                $rows[] = ['image_url' => $rel, 'sort_order' => $sortOrder, 'media_type' => $a['media'], 'asset' => $a];
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

if (!function_exists('studioPickerHtml')) {
    /**
     * Approved Pool picker. $opts: 'max' (10), 'id', 'selected' (array of keys),
     * 'name' (form field, default 'assets[]'), 'title', 'assetsUrl' (link when empty).
     */
    function studioPickerHtml(array $pool, array $opts = []): string
    {
        $esc      = 'studioEsc';
        $max      = (int)($opts['max'] ?? 10);
        $id       = (string)($opts['id'] ?? 'studioPicker');
        $name     = (string)($opts['name'] ?? 'assets[]');
        $title    = (string)($opts['title'] ?? 'Approved Pool');
        $selected = array_values(array_filter(array_map('strval', (array)($opts['selected'] ?? []))));
        $assets   = $pool['assets'] ?? [];
        $cols     = $pool['collections'] ?? [];
        $counts   = $pool['counts'] ?? ['library' => 0, 'tire' => 0];
        $total    = count($assets);

        $out  = '<section class="studio-picker" id="' . $esc($id) . '" data-picker data-max="' . $max . '" data-name="' . $esc($name) . '" data-selected="' . $esc(json_encode($selected)) . '">';
        $out .= '<header class="studio-picker-head">'
              . '<div class="studio-picker-heading"><h2 class="studio-section-title">' . $esc($title) . '</h2>'
              . '<p class="studio-picker-sub text-secondary">' . $total . ' approved · <span data-pick-count>0</span>/' . $max . ' selected</p></div>'
              . '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-pick-clear hidden>Clear</button>'
              . '</header>';

        if ($total > 0) {
            $out .= '<div class="studio-chips" role="group" aria-label="Filter the pool">';
            $out .= '<button type="button" class="studio-chip is-active" data-pool-filter="all" aria-pressed="true">All <span class="studio-chip-n">' . $total . '</span></button>';
            if (($counts['library'] ?? 0) > 0) {
                $out .= '<button type="button" class="studio-chip" data-pool-filter="library" aria-pressed="false">Library <span class="studio-chip-n">' . (int)$counts['library'] . '</span></button>';
            }
            foreach ($cols as $c) {
                $out .= '<button type="button" class="studio-chip" data-pool-filter="tire:' . (int)$c['id'] . '" aria-pressed="false">' . $esc($c['name']) . ' <span class="studio-chip-n">' . (int)$c['count'] . '</span></button>';
            }
            $out .= '</div>';

            $out .= '<div class="ui-grid studio-pool" data-pool-grid role="listbox" aria-multiselectable="true" aria-label="Approved assets">';
            foreach ($assets as $a) {
                $on = in_array($a['key'], $selected, true);
                $out .= '<button type="button" class="ui-thumb studio-asset' . ($on ? ' is-selected ui-thumb--selected' : '') . '" role="option"'
                      . ' data-asset-key="' . $esc($a['key']) . '" data-asset-kind="' . $esc($a['kind']) . '" data-asset-id="' . (int)$a['id'] . '"'
                      . ' data-asset-src="' . $esc($a['src']) . '" data-asset-label="' . $esc($a['label']) . '" data-asset-group="' . $esc($a['group']) . '"'
                      . ' data-asset-group-label="' . $esc($a['group_label']) . '" data-asset-media="' . $esc($a['media']) . '"'
                      . ' aria-selected="' . ($on ? 'true' : 'false') . '" title="' . $esc($a['label'] . ' — ' . $a['group_label']) . '">';
                if ($a['media'] === 'video') {
                    $out .= videoTile($a['src'], ['badgeClass' => 'studio-asset-duration']);
                } else {
                    $out .= '<img src="' . $esc($a['src']) . '" alt="' . $esc($a['label']) . '" loading="lazy" decoding="async">';
                }
                $out .= '<span class="studio-asset-order" data-asset-order aria-hidden="true"></span>'
                      . '<span class="ui-pill ui-pill--glass ui-pill--nodot ui-thumb-badge studio-asset-group">' . $esc($a['group_label']) . '</span>'
                      . '</button>';
            }
            $out .= '</div>';
            $out .= '<p class="ui-empty studio-pool-empty" data-pool-empty hidden>Nothing approved in this collection yet.</p>';
        } else {
            $assetsUrl = (string)($opts['assetsUrl'] ?? '');
            $out .= '<div class="ui-empty studio-pool-empty">No approved assets yet. Once the client approves images in Assets they show up here.'
                  . ($assetsUrl !== '' ? ' <a href="' . $esc($assetsUrl) . '">Open Assets</a>' : '') . '</div>';
        }

        // Selected strip: order = carousel order. Drag (Pointer Events) or use the arrows.
        $out .= '<div class="studio-strip" data-pick-strip' . ($selected ? '' : ' hidden') . '>'
              . '<div class="studio-strip-head"><span class="studio-strip-title">Selected — drag to reorder</span></div>'
              . '<ol class="studio-strip-list" data-pick-list role="list"></ol>'
              . '</div>';
        $out .= '<div data-pick-inputs hidden></div>';
        $out .= '</section>';
        return $out;
    }
}

if (!function_exists('studioPreviewHtml')) {
    /**
     * Live Instagram-style preview — the SAME partial the client sees in the
     * Posts detail (renderCaptionPreview + renderPostMedia from post-detail.php),
     * wrapped in a phone-ish frame. studio.js mirrors this markup as the form changes.
     */
    function studioPreviewHtml(array $post, array $brand, array $images = [], array $opts = []): string
    {
        $esc   = 'studioEsc';
        $when  = trim((string)($post['scheduled_date'] ?? ''));
        $ts    = $when !== '' ? strtotime($when) : false;
        $type  = function_exists('postTypeLabel') ? postTypeLabel((string)($post['post_type'] ?? 'post')) : 'Post';
        $media = function_exists('renderPostMedia')
            ? renderPostMedia($images, ['label' => (string)($brand['name'] ?? '') . ' post'])
            : '<div class="pd-media pd-media--empty"><span class="text-tertiary">No media yet</span></div>';
        $caption = function_exists('renderCaptionPreview')
            ? renderCaptionPreview($post, $brand, ['copy' => false])
            : '';

        $out  = '<aside class="studio-preview" data-preview data-brand-name="' . $esc($brand['name'] ?? '') . '" data-brand-logo="' . $esc($brand['logo_url'] ?? '') . '" aria-label="Preview — what the client sees">';
        $out .= '<div class="studio-preview-head"><h2 class="studio-section-title">Preview</h2><p class="text-secondary studio-preview-sub">Exactly what ' . $esc($brand['name'] ?? 'the client') . ' will see</p></div>';
        $out .= '<div class="studio-phone"><article class="pd studio-pd" data-post-detail="0">';
        $out .= '<div class="pd-meta"><span class="pd-type" data-preview-type>' . $esc($type) . '</span>'
              . (function_exists('statusPill') ? statusPill((string)($post['status'] ?? 'pending'), false, ['class' => 'pd-pill', 'attrs' => ['data-preview-status' => '1']]) : '')
              . '</div>';
        $out .= '<div data-preview-media>' . $media . '</div>';
        $out .= $caption;
        $out .= '<div class="pd-when"><div class="pd-when-row">'
              . (function_exists('icon') ? icon('calendar', 'pd-when-icon') : '')
              . '<span class="pd-when-body"><span class="pd-when-label">Planned for</span>'
              . '<span class="pd-when-date" data-preview-date>' . $esc($ts && function_exists('pdFormatWhen') ? pdFormatWhen($when) : 'Date to be confirmed') . '</span></span>'
              . '</div></div>';
        $out .= '</article></div></aside>';
        return $out;
    }
}

if (!function_exists('studioComposerHtml')) {
    /**
     * The composer (spec §4.5): picker + post form + live preview.
     * $ctx keys: client (array), pool (studioApprovedPool), action (form URL), isEdit (bool),
     *   post (values: id, name, caption, hashtags, scheduled (Y-m-d\TH:i), status, post_type, categories[]),
     *   editImages ([['id','url','type'], …]), categories ([['id','name'], …]), supportsType (bool),
     *   maxImages (10), maxFileMb (25), submitText, cancelUrl, assetsUrl, selected (keys), errors ([]),
     *   defaultHashtags (string), replaceEndpoint.
     */
    function studioComposerHtml(array $ctx): string
    {
        $esc      = 'studioEsc';
        $client   = $ctx['client'];
        $pool     = $ctx['pool'] ?? ['assets' => [], 'collections' => [], 'counts' => []];
        $isEdit   = !empty($ctx['isEdit']);
        $post     = $ctx['post'] ?? [];
        $cats     = $ctx['categories'] ?? [];
        $postCats = array_map('intval', (array)($post['categories'] ?? []));
        $editImgs = $ctx['editImages'] ?? [];
        $max      = (int)($ctx['maxImages'] ?? 10);
        $maxMb    = (int)($ctx['maxFileMb'] ?? 25);
        $selected = (array)($ctx['selected'] ?? []);
        $supportsType = !empty($ctx['supportsType']);
        $defaults = trim((string)($ctx['defaultHashtags'] ?? ''));
        $types    = function_exists('allowedPostTypes') ? allowedPostTypes() : ['post', 'story', 'reel'];
        $slots    = max(0, $max - count($editImgs));
        $formId   = (string)($ctx['formId'] ?? 'studioComposer');

        $brand = ['name' => (string)($client['name'] ?? ''), 'logo_url' => (string)($client['logo_url'] ?? '')];
        $previewPost = [
            'caption'        => (string)($post['caption'] ?? ''),
            'hashtags'       => (string)($post['hashtags'] ?? ''),
            'scheduled_date' => !empty($post['scheduled']) ? str_replace('T', ' ', (string)$post['scheduled']) : '',
            'post_type'      => (string)($post['post_type'] ?? 'post'),
            'status'         => (string)($post['status'] ?? 'pending'),
        ];

        $out  = '<form class="studio-composer" id="' . $esc($formId) . '" method="POST" action="' . $esc($ctx['action']) . '" enctype="multipart/form-data" data-composer data-max="' . $max . '" data-slots="' . $slots . '">';
        $out .= '<input type="hidden" name="action" value="' . ($isEdit ? 'update' : 'create') . '">';
        if ($isEdit) $out .= '<input type="hidden" name="id" value="' . (int)$post['id'] . '">';

        // ---- Left / top: Approved Pool picker --------------------------------
        $out .= '<div class="studio-composer-pool">';
        $out .= studioPickerHtml($pool, ['max' => $slots > 0 ? $slots : 0, 'selected' => $selected, 'assetsUrl' => (string)($ctx['assetsUrl'] ?? ''), 'id' => $formId . 'Picker']);

        // Direct upload for one-offs (existing add-post contract: images[])
        $out .= '<section class="studio-upload-oneoff">'
              . '<h3 class="studio-section-title studio-section-title--sm">Or upload a one-off</h3>'
              . '<label class="studio-dropzone studio-dropzone--sm" data-file-drop>'
              . '<input type="file" name="images[]" data-composer-files accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov" multiple' . ($slots <= 0 ? ' disabled' : '') . '>'
              . '<span class="studio-dropzone-label">Choose files</span>'
              . '<span class="studio-dropzone-hint">or drop them here · up to ' . $maxMb . ' MB · JPG, PNG, GIF, WebP, MP4, WebM, MOV</span>'
              . '</label>'
              . '<ul class="studio-filelist" data-composer-filelist role="list"></ul>'
              . '</section>';
        $out .= '</div>';

        // ---- Right / bottom: the post form + live preview -------------------
        $out .= '<div class="studio-composer-side">';
        $out .= studioPreviewHtml($previewPost, $brand, $editImgs);

        $out .= '<section class="studio-fields">';
        if (!empty($ctx['errors'])) {
            $out .= '<div class="studio-alert studio-alert--error" role="alert"><ul>';
            foreach ((array)$ctx['errors'] as $e) $out .= '<li>' . $esc($e) . '</li>';
            $out .= '</ul></div>';
        }
        if ($isEdit && $editImgs) {
            $out .= '<div class="studio-field"><span class="studio-label">Current media — tick to remove on save</span><div class="studio-existing" data-existing-media>';
            foreach ($editImgs as $img) {
                $isVid = ($img['type'] ?? '') === 'video';
                $src   = studioRootUrl((string)$img['url']);
                $out  .= '<label class="ui-thumb studio-existing-item" data-existing-item data-src="' . $esc($src) . '" data-media="' . ($isVid ? 'video' : 'image') . '">'
                       . ($isVid ? videoTile($src, ['badge' => false]) : '<img src="' . $esc($src) . '" alt="">')
                       . '<input type="checkbox" name="remove_images[]" value="' . (int)$img['id'] . '" data-remove-image data-image-id="' . (int)$img['id'] . '" aria-label="Remove this media">'
                       . '<span class="studio-existing-x" aria-hidden="true">Remove</span>'
                       . '</label>';
            }
            $out .= '</div></div>';
        }

        $out .= '<div class="studio-field"><label class="studio-label" for="' . $formId . '-caption">Caption</label>'
              . '<textarea class="ui-textarea studio-caption" id="' . $formId . '-caption" name="caption" rows="5" maxlength="10000" required placeholder="What does the post say?" data-field="caption">' . $esc($post['caption'] ?? '') . '</textarea></div>';

        $out .= '<div class="studio-field"><label class="studio-label" for="' . $formId . '-hashtags">Hashtags</label>'
              . '<textarea class="ui-textarea studio-tags" id="' . $formId . '-hashtags" name="hashtags" rows="2" maxlength="2000" placeholder="#Brand #Campaign" data-field="hashtags">' . $esc($post['hashtags'] ?? '') . '</textarea>';
        if ($defaults !== '') {
            $out .= '<div class="studio-help"><button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-apply-defaults data-defaults="' . $esc($defaults) . '">Append client defaults</button>'
                  . '<code class="studio-defaults" title="' . $esc($defaults) . '">' . $esc(mb_strimwidth($defaults, 0, 60, '…')) . '</code></div>';
        }
        $out .= '</div>';

        $out .= '<div class="studio-field-row">';
        $out .= '<div class="studio-field"><label class="studio-label" for="' . $formId . '-date">Scheduled for</label>'
              . '<input class="ui-input" type="datetime-local" id="' . $formId . '-date" name="scheduled_date" value="' . $esc($post['scheduled'] ?? '') . '" required data-field="scheduled_date"></div>';
        if ($supportsType) {
            $out .= '<div class="studio-field"><label class="studio-label" for="' . $formId . '-type">Type</label><select class="ui-select" id="' . $formId . '-type" name="post_type" data-field="post_type">';
            foreach ($types as $t) {
                $out .= '<option value="' . $esc($t) . '"' . (($post['post_type'] ?? 'post') === $t ? ' selected' : '') . '>' . $esc(function_exists('postTypeLabel') ? postTypeLabel($t) : ucfirst($t)) . '</option>';
            }
            $out .= '</select></div>';
        }
        $out .= '<div class="studio-field"><label class="studio-label" for="' . $formId . '-status">Status</label><select class="ui-select" id="' . $formId . '-status" name="status" data-field="status">';
        foreach (['pending' => 'To Review', 'approved' => 'Approved', 'denied' => 'Needs changes'] as $v => $l) {
            $out .= '<option value="' . $v . '"' . (($post['status'] ?? 'pending') === $v ? ' selected' : '') . '>' . $l . '</option>';
        }
        $out .= '</select></div>';
        $out .= '</div>';

        $out .= '<div class="studio-field"><label class="studio-label" for="' . $formId . '-name">Reference name <span class="text-tertiary">— internal, for lists and activity</span></label>'
              . '<input class="ui-input" type="text" id="' . $formId . '-name" name="name" maxlength="150" value="' . $esc($post['name'] ?? '') . '" placeholder="e.g. Spring launch — hero shot"></div>';

        if ($cats) {
            $out .= '<div class="studio-field"><span class="studio-label">Categories</span><div class="studio-chips studio-chips--wrap">';
            foreach ($cats as $c) {
                $on = in_array((int)$c['id'], $postCats, true);
                $out .= '<label class="studio-chip' . ($on ? ' is-active' : '') . '" data-cat-chip><input type="checkbox" name="categories[]" value="' . (int)$c['id'] . '"' . ($on ? ' checked' : '') . '>' . $esc($c['name']) . '</label>';
            }
            $out .= '</div></div>';
        }

        $out .= '<div class="studio-actions">';
        if (!empty($ctx['cancelUrl'])) $out .= '<a class="ui-btn ui-btn--gray" href="' . $esc($ctx['cancelUrl']) . '">Cancel</a>';
        $out .= '<button type="submit" class="ui-btn ui-btn--filled" data-composer-submit>' . $esc($ctx['submitText'] ?? ($isEdit ? 'Save changes' : 'Create post')) . '</button>';
        $out .= '</div>';
        $out .= '</section>';
        $out .= '</div>'; // /.studio-composer-side
        $out .= '</form>';
        return $out;
    }
}
