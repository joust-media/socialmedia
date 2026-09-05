<?php
/**
 * Shared helpers for client-scoped pages.
 * require_once __DIR__ . '/helpers.php';
 *
 * Exposes:
 *   $client         → company row (id, name, slug, feature_label) or null if unscoped
 *   $clientSlug     → string or ''
 *   clientQs()      → 'client=hmf' or '' for URL building
 *   clientUrl($page, $extra = []) → builds "page.php?client=hmf&…"
 */

// $pdo must be included before this file.

$clientSlug = '';
$client     = null;

if (!empty($_GET['client'])) {
    $slugCandidate = strtolower(trim((string)$_GET['client']));
    $slugCandidate = preg_replace('/[^a-z0-9\-]/', '', $slugCandidate);
    if ($slugCandidate !== '' && isset($pdo)) {
        // Probe for optional, migration-gated columns so we don't blow up
        // before migrate.php has run. default_hashtags, product_type and
        // industry are all added by later migration steps.
        static $extraCompanyCols = null;
        if ($extraCompanyCols === null) {
            $present = $pdo->query("
                SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
                  AND COLUMN_NAME IN ('default_hashtags','product_type','industry')
            ")->fetchAll(PDO::FETCH_COLUMN);
            $extraCompanyCols = [];
            foreach (['default_hashtags', 'product_type', 'industry'] as $col) {
                $extraCompanyCols[$col] = in_array($col, $present, true);
            }
        }
        $extraCol = '';
        foreach ($extraCompanyCols as $col => $exists) {
            $extraCol .= $exists ? ", {$col}" : ", '' AS {$col}";
        }
        $stmt = $pdo->prepare("SELECT id, name, slug, feature_label, logo_url{$extraCol} FROM companies WHERE slug = ?");
        $stmt->execute([$slugCandidate]);
        $row = $stmt->fetch();
        if ($row) {
            $client     = $row;
            $clientSlug = $row['slug'];
        }
    }
}

/** Return "client=hmf" or "" for building URLs */
function clientQs() {
    global $clientSlug;
    return $clientSlug !== '' ? 'client=' . urlencode($clientSlug) : '';
}

/** App root URL prefix — '' when deployed at the document root, '/socialmedia' when in a
 *  subdirectory, etc. Mirrors the dirname-of-SCRIPT_NAME pattern admin.php's digest
 *  shutdown trigger uses. Cached per-request because SCRIPT_NAME never changes mid-flight. */
function basePath() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '.' || $dir === '') $dir = '';
    return $cached = $dir;
}

/** Build URL to a page preserving client scope and merging extras.
 *  Output is always root-rooted ('/feed?client=hmf', '/socialmedia/feed?client=hmf'),
 *  so the same href works from any page in the app. .htaccess handles the .php rewrite. */
function clientUrl($page, $extra = []) {
    global $clientSlug;
    $qs = [];
    if ($clientSlug !== '') { $qs['client'] = $clientSlug; }
    foreach ($extra as $k => $v) {
        if ($v !== null && $v !== '') { $qs[$k] = $v; }
    }
    $clean = preg_replace('/\.php$/', '', $page);
    if ($clean === 'index') { $clean = ''; }   // homepage = "/"
    $path = basePath() . '/' . $clean;          // "/" or "/feed" or "/socialmedia/feed"
    return $path . ($qs ? '?' . http_build_query($qs) : '');
}

/**
 * Format a datetime as a short relative string ("2m ago", "yesterday", "Mar 14").
 *
 * Returns '' for null/empty/unparseable input so callers can ignore "no value yet".
 * The full timestamp is left to the caller to attach as a tooltip.
 */
function relativeTime($datetime) {
    if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = is_int($datetime) ? $datetime : strtotime((string)$datetime);
    if (!$ts) return '';

    $diff = time() - $ts;
    if ($diff < 0)        return 'just now';   // future stamps fall back gracefully
    if ($diff < 45)       return 'just now';
    if ($diff < 3600)     return max(1, (int)round($diff / 60)) . 'm ago';
    if ($diff < 86400)    return max(1, (int)round($diff / 3600)) . 'h ago';
    if ($diff < 2 * 86400) return 'yesterday';
    if ($diff < 7 * 86400) return (int)floor($diff / 86400) . 'd ago';
    if ($diff < 30 * 86400) return (int)floor($diff / (7 * 86400)) . 'w ago';

    // Older than a month — switch to absolute date
    return date(date('Y') === date('Y', $ts) ? 'M j' : 'M j, Y', $ts);
}

/**
 * Format a datetime as the long, hover-friendly absolute string.
 * Returns '' for null/unparseable input.
 */
function absoluteTime($datetime) {
    if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = is_int($datetime) ? $datetime : strtotime((string)$datetime);
    return $ts ? date('M j, Y \a\t g:i A', $ts) : '';
}

/**
 * Build the canonical client nav. Same set of links every client sees on their
 * front page, so every page can render an identical top-bar nav.
 *
 * Returns [['label','icon','url','page'], ...] or [] if unscoped.
 *   $current is one of: 'index', 'projects', 'feed', or 'module:<slug>'.
 */
function clientNavItems(PDO $pdo, $client) {
    if (!$client) return [];

    $items = [
        ['label' => 'Home',     'icon' => '🏠', 'url' => clientUrl('index.php'),    'page' => 'index'],
        ['label' => 'Projects', 'icon' => '📋', 'url' => clientUrl('projects.php'), 'page' => 'projects'],
        ['label' => 'Feed',     'icon' => '📰', 'url' => clientUrl('feed.php'),     'page' => 'feed'],
        ['label' => 'Library',  'icon' => '🖼️', 'url' => clientUrl('library.php'),  'page' => 'library'],
    ];

    $s = $pdo->prepare("
        SELECT m.slug, m.plural_label, m.icon
        FROM company_modules cm
        INNER JOIN modules m ON m.id = cm.module_id
        WHERE cm.company_id = ?
        ORDER BY cm.sort_order, m.plural_label
    ");
    $s->execute([$client['id']]);
    foreach ($s->fetchAll() as $mod) {
        $items[] = [
            'label' => $mod['plural_label'],
            'icon'  => $mod['icon'],
            'url'   => clientUrl('features.php', ['module' => $mod['slug']]),
            'page'  => 'module:' . $mod['slug'],
        ];
    }
    return $items;
}

/** Render the nav links built by clientNavItems(). $current marks the active link. */
function renderClientNav(array $items, $current = '') {
    if (!$items) return '';
    $out = '';
    foreach ($items as $it) {
        $active = $it['page'] === $current ? ' active' : '';
        $out .= '<a class="nav-link' . $active . '" href="'
             . htmlspecialchars($it['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
             . '<span class="nav-link-icon">' . htmlspecialchars($it['icon'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
             . '<span class="nav-link-label">' . htmlspecialchars($it['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
             . '</a>';
    }
    return $out;
}

/** Produce a human-friendly label for a tire_images row (admin-set display_name preferred,
 *  otherwise the existing caption, otherwise "image #<id>"). Used in activity summaries
 *  so the feed reads "Comment on Pathfinder hero" rather than "Comment on image #42". */
function imageDisplayLabel($img) {
    if (!empty($img['display_name'])) return trim((string)$img['display_name']);
    if (!empty($img['caption']))      return trim((string)$img['caption']);
    return 'image #' . (int)($img['id'] ?? 0);
}

/** Same idea for a post row — prefers the admin-set name, then a clipped caption,
 *  then the bare "post #N" fallback. Keep the clip short so activity rows stay scannable. */
function postDisplayLabel($post) {
    if (!empty($post['name']))    return trim((string)$post['name']);
    if (!empty($post['caption'])) return mb_strimwidth(trim((string)$post['caption']), 0, 60, '…');
    return 'post #' . (int)($post['id'] ?? 0);
}

/** Does posts.name exist yet? Cached for the request. */
function hasPostsNameColumn(PDO $pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'name'
    ");
    $s->execute();
    return $cached = (int)$s->fetchColumn() > 0;
}

/** Sanitize a display_name into a safe filename stem (no extension).
 *  Strips path separators, collapses whitespace + non-name chars to dashes,
 *  trims leading/trailing junk, caps at 80 chars. Returns '' for blank input. */
function safeFilenameStem($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    // Drop any extension the user typed — we always re-attach the real one.
    $name = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '', $name);
    $name = preg_replace('/[^A-Za-z0-9._\- ]+/', '-', $name);
    $name = preg_replace('/[\s_]+/', '-', $name);
    $name = trim(preg_replace('/-+/', '-', $name), '-_.');
    return mb_substr($name, 0, 80);
}

/** Cheap check for whether activity_log exists yet (migrate.php may not have run on this deploy). */
function hasActivityLog(PDO $pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log'
    ");
    $s->execute();
    return $cached = (int)$s->fetchColumn() > 0;
}

/** Does posts.post_type exist yet? Cached for the request. */
function hasPostTypeColumn(PDO $pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'post_type'
    ");
    $s->execute();
    return $cached = (int)$s->fetchColumn() > 0;
}

/** Allowed values for posts.post_type — keep in sync with the ENUM in migrate.php. */
function allowedPostTypes() {
    return ['post', 'story', 'reel'];
}

/** Human label for a post_type value (e.g. 'reel' -> 'Reel'). */
function postTypeLabel($t) {
    $t = strtolower((string)$t);
    return in_array($t, allowedPostTypes(), true) ? ucfirst($t) : 'Post';
}

/** Same idea for post_images.media_type — degrades gracefully if migration hasn't run. */
function hasMediaTypeColumn(PDO $pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'post_images' AND COLUMN_NAME = 'media_type'
    ");
    $s->execute();
    return $cached = (int)$s->fetchColumn() > 0;
}

/**
 * Allowed media extensions for post uploads.
 * .mov is intentionally NOT here — Chrome/Firefox don't reliably play it
 * even inside a <video> tag. We surface a friendly "please convert to mp4"
 * error to the user instead of silently uploading a file that won't work.
 */
function imageExts() { return ['jpg', 'jpeg', 'png', 'gif', 'webp']; }
function videoExts() { return ['mp4', 'webm']; }

/** True if the given file extension is one of our supported video formats. */
function isVideoExt($ext) {
    return in_array(strtolower((string)$ext), videoExts(), true);
}

/** Returns 'video' or 'image' based on the URL's extension. */
function mediaTypeFromUrl($url) {
    $ext = strtolower(pathinfo((string)$url, PATHINFO_EXTENSION));
    return isVideoExt($ext) ? 'video' : 'image';
}

/** MIME type for the <video> source tag (defaults to mp4 if unknown). */
function videoMimeForExt($ext) {
    $ext = strtolower((string)$ext);
    if ($ext === 'webm') return 'video/webm';
    return 'video/mp4'; // mp4 / m4v / etc.
}

/**
 * Library gallery — reads image files straight off disk from a folder Lance
 * drops files into by hand (Drive/FTP), one folder per brand, keyed on the
 * company's slug: media/library/{slug}/. That folder is a *sibling* of this
 * app (http/media/ next to http/socialmedia/), not inside it.
 */

/** Filesystem path to a brand's library folder. */
function libraryDir($slug) {
    return __DIR__ . '/../media/library/' . $slug;
}

/** Public URL for a file inside a brand's library folder. Root-relative —
 *  assumes media/ is served from the same document root as this app. */
function libraryFileUrl($slug, $filename) {
    return '/media/library/' . rawurlencode($slug) . '/' . rawurlencode($filename);
}

/** List image filenames sitting directly in a brand's library folder,
 *  natural-sorted. Skips dotfiles (.DS_Store etc.), subfolders, and
 *  anything that isn't a recognized image extension. */
function scanLibraryDir($dir) {
    if (!is_dir($dir)) return [];
    $exts = imageExts();
    $out = [];
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || strpos($f, '.') === 0) continue;
        if (!is_file($dir . '/' . $f)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        $out[] = $f;
    }
    natcasesort($out);
    return array_values($out);
}

/** Does library_images exist yet? (migrate.php may not have run) */
function hasLibraryImagesTable(PDO $pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'library_images'
    ");
    $s->execute();
    return $cached = (int)$s->fetchColumn() > 0;
}

/**
 * Scan a brand's library folder, register any not-yet-seen files as new
 * 'pending' rows, then return the library_images rows for files that are
 * still actually present on disk (a file removed from the folder just
 * stops showing up — its decision history stays in the table quietly).
 * Auto-creates the folder if it's missing so there's always somewhere to
 * drop files into.
 */
function syncLibraryImages(PDO $pdo, $companyId, $slug) {
    if (!hasLibraryImagesTable($pdo)) return [];
    $dir = libraryDir($slug);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $onDisk = scanLibraryDir($dir);

    if ($onDisk) {
        $ins = $pdo->prepare("
            INSERT IGNORE INTO library_images (company_id, filename, status)
            VALUES (?, ?, 'pending')
        ");
        foreach ($onDisk as $f) {
            $ins->execute([$companyId, $f]);
        }
    }

    $sel = $pdo->prepare("
        SELECT id, filename, status, created_at, updated_at
        FROM library_images
        WHERE company_id = ?
        ORDER BY filename ASC
    ");
    $sel->execute([$companyId]);
    $rows = $sel->fetchAll();

    $onDiskSet = array_flip($onDisk);
    return array_values(array_filter($rows, function ($r) use ($onDiskSet) {
        return isset($onDiskSet[$r['filename']]);
    }));
}

/**
 * Insert one row into activity_log. Swallows any exception — a logging
 * failure must never break the user-facing mutation.
 */
function logActivity(PDO $pdo, $companyId, $entityType, $entityId,
                     $action, $actor, $summary,
                     $detail = null, $batchId = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log
                (company_id, entity_type, entity_id, action, actor, batch_id, summary, detail)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)$companyId, $entityType, (int)$entityId, $action,
            $actor, $batchId, mb_substr((string)$summary, 0, 500), $detail,
        ]);
    } catch (Throwable $e) {
        error_log('logActivity failed: ' . $e->getMessage());
    }
}

/** 16-hex-char id used to group multi-field edits in one feed line. */
function newBatchId() {
    return bin2hex(random_bytes(8));
}

/** Read POST['actor'] with a whitelist; default 'unknown'. */
function actorFromPost() {
    $a = $_POST['actor'] ?? 'unknown';
    return in_array($a, ['client', 'admin'], true) ? $a : 'unknown';
}

/**
 * Fetch the full comment thread for one entity, oldest → newest.
 * Each row has actor, detail (the message text), and created_at.
 * Used to render chat-style comment history on posts and tire images.
 */
function commentThread(PDO $pdo, $entityType, $entityId) {
    $stmt = $pdo->prepare("
        SELECT actor, detail, created_at
          FROM activity_log
         WHERE entity_type = ?
           AND entity_id = ?
           AND action = 'commented'
           AND detail IS NOT NULL AND detail <> ''
         ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute([$entityType, (int)$entityId]);
    return $stmt->fetchAll();
}

/** Render the chat-thread HTML for one entity. Empty string if no messages. */
function renderCommentThread(PDO $pdo, $entityType, $entityId) {
    $msgs = commentThread($pdo, $entityType, $entityId);
    if (!$msgs) return '';
    $h = function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $out = '<div class="comment-thread">';
    foreach ($msgs as $m) {
        $actor = $m['actor'] ?: 'unknown';
        $rel   = relativeTime($m['created_at']);
        $abs   = absoluteTime($m['created_at']);
        $out .= '<div class="comment-msg comment-msg-' . $h($actor) . '">'
              . '<div class="comment-msg-head">'
              . '<span class="comment-msg-actor">' . $h($actor) . '</span>'
              . '<span class="comment-msg-time" title="' . $h($abs) . '">' . $h($rel) . '</span>'
              . '</div>'
              . '<div class="comment-msg-body">' . nl2br($h($m['detail'])) . '</div>'
              . '</div>';
    }
    $out .= '</div>';
    return $out;
}

/**
 * Latest 'commented' timestamp per entity, keyed by entity_id.
 * Used to prefix client_comment displays with a date.
 */
function latestCommentDates(PDO $pdo, $entityType, array $entityIds) {
    if (!$entityIds) return [];
    $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
    $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
    $stmt = $pdo->prepare("
        SELECT entity_id, MAX(created_at) AS last_at
          FROM activity_log
         WHERE entity_type = ? AND action = 'commented'
           AND entity_id IN ($placeholders)
         GROUP BY entity_id
    ");
    $stmt->execute(array_merge([$entityType], $entityIds));
    $out = [];
    foreach ($stmt as $row) { $out[(int)$row['entity_id']] = $row['last_at']; }
    return $out;
}

/**
 * Pull recent activity for the feed panel. If $companyId is null, return
 * cross-client. Rows sharing a batch_id are returned grouped (one entry
 * with `actions` array) so the UI can collapse them.
 */
function recentActivity(PDO $pdo, $companyId = null, $limit = 20) {
    $sql = "
        SELECT a.id, a.company_id, a.entity_type, a.entity_id, a.action, a.actor,
               a.batch_id, a.summary, a.detail, a.created_at,
               c.name AS company_name, c.slug AS company_slug
          FROM activity_log a
          LEFT JOIN companies c ON c.id = a.company_id
    ";
    $params = [];
    if ($companyId) {
        $sql .= " WHERE a.company_id = ? ";
        $params[] = (int)$companyId;
    }
    $sql .= " ORDER BY a.created_at DESC LIMIT " . (int)max(1, $limit * 3);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Collapse rows that share a non-null batch_id into one entry.
    $grouped = [];
    $byBatch = [];
    foreach ($rows as $r) {
        if ($r['batch_id']) {
            $key = $r['batch_id'];
            if (!isset($byBatch[$key])) {
                $byBatch[$key] = [
                    'company_id'   => $r['company_id'],
                    'company_name' => $r['company_name'],
                    'company_slug' => $r['company_slug'],
                    'entity_type'  => $r['entity_type'],
                    'entity_id'    => $r['entity_id'],
                    'actor'        => $r['actor'],
                    'created_at'   => $r['created_at'],
                    'batch_id'     => $r['batch_id'],
                    'actions'      => [],
                    'summaries'    => [],
                    'details'      => [],
                ];
                $grouped[] =& $byBatch[$key];
            }
            $byBatch[$key]['actions'][]   = $r['action'];
            $byBatch[$key]['summaries'][] = $r['summary'];
            if ($r['detail'] !== null && $r['detail'] !== '') {
                $byBatch[$key]['details'][] = ['action' => $r['action'], 'text' => $r['detail']];
            }
            // earliest created_at within batch (rows are DESC, so the last wins)
            $byBatch[$key]['created_at'] = $r['created_at'];
        } else {
            $grouped[] = [
                'company_id'   => $r['company_id'],
                'company_name' => $r['company_name'],
                'company_slug' => $r['company_slug'],
                'entity_type'  => $r['entity_type'],
                'entity_id'    => $r['entity_id'],
                'actor'        => $r['actor'],
                'created_at'   => $r['created_at'],
                'batch_id'     => null,
                'actions'      => [$r['action']],
                'summaries'    => [$r['summary']],
                'details'      => ($r['detail'] !== null && $r['detail'] !== '')
                                  ? [['action' => $r['action'], 'text' => $r['detail']]]
                                  : [],
            ];
        }
    }
    unset($byBatch);

    // Sort by most-recent created_at within the grouped collection and trim to $limit
    usort($grouped, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });
    $grouped = array_slice($grouped, 0, $limit);

    // -------------------------------------------------------------------
    // Enrich tire / tire_image rows so activityLink() can deep-link to the
    // exact item (and image anchor) instead of dumping the user on a gallery.
    // Two batched lookups — only fire when we actually have rows that need them.
    // -------------------------------------------------------------------
    $imageIds   = [];
    $tireIds    = [];
    $postIds    = [];
    $libImgIds  = [];
    foreach ($grouped as $g) {
        if ($g['entity_type'] === 'tire_image')    { $imageIds[]  = (int)$g['entity_id']; }
        if ($g['entity_type'] === 'tire')          { $tireIds[]   = (int)$g['entity_id']; }
        if ($g['entity_type'] === 'post')          { $postIds[]   = (int)$g['entity_id']; }
        if ($g['entity_type'] === 'library_image') { $libImgIds[] = (int)$g['entity_id']; }
    }
    $imageMeta = [];
    if ($imageIds) {
        $imageIds = array_values(array_unique($imageIds));
        $ph = implode(',', array_fill(0, count($imageIds), '?'));
        // Pull display_name only if the column exists (backwards-compatible with pre-migrate dbs).
        static $hasDisplayName = null;
        if ($hasDisplayName === null) {
            $hasDisplayName = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0;
        }
        $nameSel = $hasDisplayName ? 'ti.display_name' : "'' AS display_name";
        $s = $pdo->prepare("
            SELECT ti.id AS image_id, ti.tire_id, ti.caption, {$nameSel},
                   m.slug AS module_slug
              FROM tire_images ti
              INNER JOIN tires t ON t.id = ti.tire_id
              INNER JOIN modules m ON m.id = t.module_id
             WHERE ti.id IN ($ph)
        ");
        $s->execute($imageIds);
        foreach ($s->fetchAll() as $r) {
            $imageMeta[(int)$r['image_id']] = [
                'tire_id'      => (int)$r['tire_id'],
                'module_slug'  => $r['module_slug'],
                'display_name' => $r['display_name'] ?? '',
                'caption'      => $r['caption'] ?? '',
            ];
        }
    }
    $tireMeta = [];
    if ($tireIds) {
        $tireIds = array_values(array_unique($tireIds));
        $ph = implode(',', array_fill(0, count($tireIds), '?'));
        $s = $pdo->prepare("
            SELECT t.id AS tire_id, m.slug AS module_slug
              FROM tires t
              INNER JOIN modules m ON m.id = t.module_id
             WHERE t.id IN ($ph)
        ");
        $s->execute($tireIds);
        foreach ($s->fetchAll() as $r) {
            $tireMeta[(int)$r['tire_id']] = ['module_slug' => $r['module_slug']];
        }
    }
    $postMeta = [];
    if ($postIds) {
        $postIds = array_values(array_unique($postIds));
        $ph = implode(',', array_fill(0, count($postIds), '?'));
        // posts.name is optional — degrade gracefully if migrate hasn't run.
        $nameSel = hasPostsNameColumn($pdo) ? 'name' : "'' AS name";
        $s = $pdo->prepare("SELECT id, {$nameSel}, caption FROM posts WHERE id IN ($ph)");
        $s->execute($postIds);
        foreach ($s->fetchAll() as $r) {
            $postMeta[(int)$r['id']] = [
                'name'    => $r['name'] ?? '',
                'caption' => $r['caption'] ?? '',
            ];
        }
    }
    $libImgMeta = [];
    if ($libImgIds && hasLibraryImagesTable($pdo)) {
        $libImgIds = array_values(array_unique($libImgIds));
        $ph = implode(',', array_fill(0, count($libImgIds), '?'));
        $s = $pdo->prepare("SELECT id, filename FROM library_images WHERE id IN ($ph)");
        $s->execute($libImgIds);
        foreach ($s->fetchAll() as $r) {
            $libImgMeta[(int)$r['id']] = ['filename' => $r['filename']];
        }
    }

    foreach ($grouped as &$g) {
        if ($g['entity_type'] === 'tire_image' && isset($imageMeta[(int)$g['entity_id']])) {
            $g['_meta'] = $imageMeta[(int)$g['entity_id']];
        } elseif ($g['entity_type'] === 'tire' && isset($tireMeta[(int)$g['entity_id']])) {
            $g['_meta'] = $tireMeta[(int)$g['entity_id']];
        } elseif ($g['entity_type'] === 'post' && isset($postMeta[(int)$g['entity_id']])) {
            $g['_meta'] = $postMeta[(int)$g['entity_id']];
        } elseif ($g['entity_type'] === 'library_image' && isset($libImgMeta[(int)$g['entity_id']])) {
            $g['_meta'] = $libImgMeta[(int)$g['entity_id']];
        }
    }
    unset($g);

    return $grouped;
}

/** Pretty label for an activity action ('approved' → 'approved'; 'edited_caption' → 'edited caption'). */
function actionLabel($action) {
    static $map = [
        'approved'             => 'approved',
        'denied'               => 'denied',
        'reset_pending'        => 'reset to pending',
        'posted'               => 'marked posted',
        'unposted'             => 'unmarked posted',
        'commented'            => 'commented',
        'uncommented'          => 'cleared comment',
        'edited_caption'       => 'edited caption',
        'edited_hashtags'      => 'edited hashtags',
        'edited_schedule'      => 'rescheduled',
        'edited_type'          => 'changed content type',
        'edited_image_caption' => 'edited image caption',
        'renamed_post'         => 'renamed',
        'renamed_image'        => 'renamed',
        'created'              => 'created',
        'task_created'         => 'opened task',
        'task_updated'         => 'updated task',
        'task_toggled'         => 'toggled task',
        'task_deleted'         => 'deleted task',
    ];
    return $map[$action] ?? str_replace('_', ' ', $action);
}

/** Day bucket label (Today / Yesterday / "Mon May 5") for grouping a feed. */
function dayBucket($datetime) {
    if (!$datetime) return '';
    $ts    = is_int($datetime) ? $datetime : strtotime((string)$datetime);
    if (!$ts) return '';
    $today = strtotime('today');
    if ($ts >= $today)              return 'Today';
    if ($ts >= $today - 86400)      return 'Yesterday';
    return date(date('Y') === date('Y', $ts) ? 'D M j' : 'M j, Y', $ts);
}

/** Build a deep-link URL for an activity row.
 *  Root-rooted via basePath() so it resolves the same from any page. Aims to land the
 *  user *on the exact entity* — the post editor, the specific tire's review with the
 *  image anchored, the task highlighted in the project list — never a generic gallery. */
function activityLink($entry) {
    $slug = $entry['company_slug'] ?? '';
    $base = basePath();
    $meta = $entry['_meta'] ?? null;

    $clientPair = $slug !== '' ? ['client' => $slug] : [];

    switch ($entry['entity_type']) {
        case 'post':
            // Land in the post editor with the comment thread visible.
            $qs = http_build_query(array_merge($clientPair, ['edit' => (int)$entry['entity_id']]));
            return $base . '/add-post?' . $qs;

        case 'tire_image':
            // Per-image review: features?client=X&module=<slug>&item=<tire_id>#image-<id>
            $moduleSlug = $meta['module_slug'] ?? 'tires';
            $tireId     = (int)($meta['tire_id'] ?? 0);
            $params     = array_merge($clientPair, ['module' => $moduleSlug]);
            if ($tireId > 0) { $params['item'] = $tireId; }
            $url = $base . '/features?' . http_build_query($params);
            if ($tireId > 0) { $url .= '#image-' . (int)$entry['entity_id']; }
            return $url;

        case 'tire':
            // Per-tire review (catch the tire entity itself: created/deleted/etc.)
            $moduleSlug = $meta['module_slug'] ?? 'tires';
            $params     = array_merge($clientPair, [
                'module' => $moduleSlug,
                'item'   => (int)$entry['entity_id'],
            ]);
            return $base . '/features?' . http_build_query($params);

        case 'task':
            // Anchor straight to the task in the project list.
            $url = $base . '/projects';
            if ($clientPair) { $url .= '?' . http_build_query($clientPair); }
            return $url . '#task-' . (int)$entry['entity_id'];

        case 'library_image':
            // Anchor straight to the tile in the brand's library gallery.
            $url = $base . '/library';
            if ($clientPair) { $url .= '?' . http_build_query($clientPair); }
            return $url . '#lib-' . (int)$entry['entity_id'];

        default:
            return $base . '/admin' . ($clientPair ? '?' . http_build_query($clientPair) : '');
    }
}

/**
 * Render the activity feed panel HTML. $companyId optional (null = global).
 * Returns a string of HTML — caller is responsible for surrounding container.
 */
function renderActivityFeed(PDO $pdo, $companyId = null, $limit = 20) {
    $entries = recentActivity($pdo, $companyId, $limit);
    if (!$entries) {
        return '<div class="activity-empty">No activity yet. Approvals, comments, and edits will show up here.</div>';
    }
    $h = function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $out = '';
    $lastBucket = null;
    foreach ($entries as $e) {
        $bucket = dayBucket($e['created_at']);
        if ($bucket !== $lastBucket) {
            $out .= '<div class="activity-day">' . $h($bucket) . '</div>';
            $lastBucket = $bucket;
        }
        $actor   = $e['actor'] ?: 'unknown';
        $actions = array_unique($e['actions']);
        $primary = $actions[0];
        $verb    = count($actions) > 1
            ? 'made ' . count($actions) . ' edits'
            : actionLabel($primary);
        // Prefer human-readable labels (admin-set image display_name, then caption)
        // before falling back to "image #42" or the bare entity_type id.
        if ($e['entity_type'] === 'post') {
            $meta = $e['_meta'] ?? [];
            $entityLabel = postDisplayLabel([
                'name'    => $meta['name'] ?? '',
                'caption' => $meta['caption'] ?? '',
                'id'      => (int)$e['entity_id'],
            ]);
        } elseif ($e['entity_type'] === 'tire_image') {
            $meta = $e['_meta'] ?? [];
            $named = imageDisplayLabel([
                'display_name' => $meta['display_name'] ?? '',
                'caption'      => $meta['caption'] ?? '',
                'id'           => (int)$e['entity_id'],
            ]);
            // imageDisplayLabel already returns "image #N" when nothing better exists,
            // so use it verbatim — no double prefix.
            $entityLabel = $named;
        } elseif ($e['entity_type'] === 'task') {
            $entityLabel = 'task #' . (int)$e['entity_id'];
        } elseif ($e['entity_type'] === 'library_image') {
            $meta = $e['_meta'] ?? [];
            $entityLabel = !empty($meta['filename']) ? $meta['filename'] : 'image #' . (int)$e['entity_id'];
        } else {
            $entityLabel = $e['entity_type'] . ' #' . (int)$e['entity_id'];
        }
        $companyLine = $e['company_name']
            ? '<span class="activity-company">' . $h($e['company_name']) . '</span> '
            : '';
        $rel  = relativeTime($e['created_at']);
        $abs  = absoluteTime($e['created_at']);
        $link = activityLink($e);

        $detailHtml = '';
        if ($e['details']) {
            foreach ($e['details'] as $d) {
                if (in_array($d['action'], ['commented', 'uncommented'], true) && $d['text']) {
                    $detailHtml .= '<div class="activity-detail">"' . $h(mb_substr($d['text'], 0, 240))
                                . (mb_strlen($d['text']) > 240 ? '…' : '') . '"</div>';
                }
            }
        }

        $editFields = [];
        foreach ($actions as $a) {
            if (strpos($a, 'edited_') === 0) {
                $editFields[] = trim(str_replace('_', ' ', substr($a, 7)));
            }
        }
        $editList = $editFields ? ' — ' . $h(implode(', ', $editFields)) : '';

        $out .= '<a class="activity-row" href="' . $h($link) . '">'
              . '<span class="activity-actor activity-actor-' . $h($actor) . '">' . $h($actor) . '</span>'
              . '<span class="activity-text">'
              . $companyLine
              . '<span class="activity-verb">' . $h($verb) . '</span> '
              . '<span class="activity-target">' . $h($entityLabel) . '</span>'
              . $h($editList)
              . $detailHtml
              . '</span>'
              . '<span class="activity-time" title="' . $h($abs) . '">' . $h($rel) . '</span>'
              . '</a>';
    }
    return $out;
}

/**
 * Render the brand block (logo + name) for the client topbar.
 * Falls back to a "J" mark when unscoped or when the client has no logo.
 */
function renderBrand($client, $sub = '') {
    $h = function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $logoUrl = $client['logo_url'] ?? '';
    $name    = $client ? $client['name'] : 'Joust Media';

    $mark = $logoUrl
        ? '<img class="brand-logo" src="' . $h($logoUrl) . '" alt="' . $h($name) . ' logo">'
        : '<div class="brand-mark">J</div>';

    $subHtml = $sub !== '' ? '<span class="brand-sub">' . $h($sub) . '</span>' : '';

    return '<div class="brand">' . $mark . '<span class="brand-name">' . $h($name) . '</span>' . $subHtml . '</div>';
}
