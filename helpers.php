<?php
/**
 * Shared helpers for client-scoped pages.
 * require_once __DIR__ . '/helpers.php';
 *
 * Exposes:
 *   $client         → company row (id, name, slug, feature_label) or null if unscoped
 *   $clientSlug     → string or ''
 *   $role           → 'admin' (signed-in jsm_admin session) or 'client'; see isAdmin()
 *   clientQs()      → 'client=hmf' or '' for URL building
 *   clientUrl($page, $extra = []) → builds "page.php?client=hmf&…"
 *   renderClientNav() / renderAppChrome() / renderAppHead() → the shared iOS-style shell
 *   icon(), statusPill(), segmented(), insetRow(), card() → partials/components/*
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

// ---------------------------------------------------------------------
// Role — resolved once, server-side, next to the client scoping so every
// page and partial can branch on it. auth.php only declares constants and
// functions when included; currentAdmin() starts the jsm_admin session,
// which is safe here because helpers.php is included before any output.
// Pages that also include auth.php must use require_once (they do).
// ---------------------------------------------------------------------
require_once __DIR__ . '/auth.php';
$role = currentAdmin() ? 'admin' : 'client';

/** True when the visitor is the signed-in admin (session-based). */
if (!function_exists('isAdmin')) {
    function isAdmin(): bool {
        return function_exists('currentAdmin') && currentAdmin() !== null;
    }
}

/** Shared escaper for partials. Pages keep their own page-local h(); this
 *  name is unique so nothing can collide. */
if (!function_exists('esc')) {
    function esc($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Shared UI component helpers (all function_exists-guarded, no output).
require_once __DIR__ . '/partials/components/icon.php';
require_once __DIR__ . '/partials/components/status-pill.php';
require_once __DIR__ . '/partials/components/segmented.php';
require_once __DIR__ . '/partials/components/inset-list.php';
require_once __DIR__ . '/partials/components/card.php';
require_once __DIR__ . '/partials/components/video.php';

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

/** URL style switch for clientUrl() / pagePath().
 *
 *  false (default) → explicit script URLs: '/socialmedia/posts.php?client=hmf'. Works on
 *                    any Apache folder with or without an extension-less rewrite, so the
 *                    portal never depends on the host's .htaccess being in place.
 *  true            → pretty URLs: '/socialmedia/posts?client=hmf'. Only flip this once the
 *                    server's .htaccess rewrite (name → name.php) is confirmed working.
 *  Home is the folder root ('/socialmedia/') in both modes. Guarded so a config.php or a
 *  test harness can define it first. */
if (!defined('CLEAN_URLS')) { define('CLEAN_URLS', false); }

/** Root-rooted path for a page name honouring CLEAN_URLS:
 *    pagePath('posts') / pagePath('posts.php') → '/socialmedia/posts.php' (or '/socialmedia/posts')
 *    pagePath('index') / pagePath('index.php') / pagePath('') → '/socialmedia/'
 *  Paths with a directory component ('legacy/admin.php') are treated the same way. */
function pagePath($page) {
    $name = preg_replace('/\.php$/', '', (string)$page);
    if ($name === '' || $name === 'index') { return basePath() . '/'; }   // homepage = folder root
    return basePath() . '/' . $name . (CLEAN_URLS ? '' : '.php');
}

/** Build URL to a page preserving client scope and merging extras.
 *  Output is always root-rooted ('/posts.php?client=hmf', '/socialmedia/posts.php?client=hmf'),
 *  so the same href works from any page in the app. The page name may be given with or
 *  without '.php' — see pagePath() / CLEAN_URLS for the emitted form. */
function clientUrl($page, $extra = []) {
    global $clientSlug;
    $qs = [];
    if ($clientSlug !== '') { $qs['client'] = $clientSlug; }
    foreach ($extra as $k => $v) {
        if ($v !== null && $v !== '') { $qs[$k] = $v; }
    }
    return pagePath($page) . ($qs ? '?' . http_build_query($qs) : '');
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

/**
 * Render the page chrome for a client page: the large-title nav bar plus the
 * role-aware tab bar (partials/navbar.php + partials/tabbar.php).
 *
 * Kept as the single call every existing page already makes, so the whole
 * site moved to the new shell at once. $items (from clientNavItems()) is only
 * used to look up a module label; $current is the legacy page key
 * ('index', 'feed', 'library', 'projects', 'module:<slug>') and is mapped to a
 * tab by appTabForPage(). $opts are passed through to renderAppChrome()
 * ('title', 'subtitle', 'back', 'trailing', 'links', 'wide', 'active', 'tabs').
 */
function renderClientNav(array $items, $current = '', array $opts = []) {
    global $client;
    if (!isset($opts['active'])) { $opts['active'] = appTabForPage((string)$current); }
    if (!isset($opts['width'])) {
        // Match each legacy page's own content column so the nav edges line up.
        $widths = ['index' => '900px', 'feed' => '680px', 'library' => '1200px', 'projects' => '760px'];
        $opts['width'] = $widths[$current] ?? (strpos((string)$current, 'module:') === 0 ? '1100px' : null);
    }
    $title = isset($opts['title']) ? (string)$opts['title'] : appPageTitle((string)$current, $items, $client);
    unset($opts['title']);
    return renderAppChrome($title, $opts);
}

/** Map a legacy page key to a tab id ('home'|'assets'|'posts'|'projects'|'studio'|null). */
function appTabForPage(string $current) {
    if ($current === 'index')    return 'home';
    if ($current === 'feed')     return 'posts';
    if ($current === 'library')  return 'assets';
    if (strpos($current, 'module:') === 0) return 'assets';
    if ($current === 'projects') return 'projects';
    if ($current === 'admin' || $current === 'studio') return 'studio';
    return null;
}

/** Default large title for a legacy page key. */
function appPageTitle(string $current, array $items, $client): string {
    switch ($current) {
        case 'index':    return $client ? (string)$client['name'] : 'Dashboard';
        case 'feed':     return 'Posts';
        case 'library':  return 'Assets';
        case 'projects': return 'Projects';
        case 'admin':    return 'Studio';
    }
    foreach ($items as $it) {
        if (($it['page'] ?? '') === $current && !empty($it['label'])) return (string)$it['label'];
    }
    return $client ? (string)$client['name'] : 'Joust';
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

/** Does posts.posted exist yet? (migrate.php may not have run.) Cached for the request.
 *  The single shared copy — pages must not redeclare it. */
if (!function_exists('hasPostedColumn')) {
    function hasPostedColumn(PDO $pdo) {
        static $cached = null;
        if ($cached !== null) return $cached;
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'posts'
              AND COLUMN_NAME = 'posted'
        ");
        $s->execute();
        return $cached = (int)$s->fetchColumn() > 0;
    }
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
 * Allowed media extensions for uploads (spec §6).
 * .mov (QuickTime) is accepted and kept as-is: it plays natively in Safari /
 * iOS; other browsers get the "Open video / Download" card from App.video.
 * Video-ness for library_images and tire_images (no media_type column) is
 * decided by extension through isVideoExt().
 */
function imageExts() { return ['jpg', 'jpeg', 'png', 'gif', 'webp']; }
function videoExts() { return ['mp4', 'webm', 'mov']; }

/** True if the given file extension is one of our supported video formats. */
function isVideoExt($ext) {
    return in_array(strtolower((string)$ext), videoExts(), true);
}

/** Returns 'video' or 'image' based on the URL's extension. */
function mediaTypeFromUrl($url) {
    $path = parse_url((string)$url, PHP_URL_PATH);
    $ext  = strtolower(pathinfo($path !== null && $path !== false ? $path : (string)$url, PATHINFO_EXTENSION));
    return isVideoExt($ext) ? 'video' : 'image';
}

if (!function_exists('videoMime')) {
    /** MIME type for a <source type> by extension: mp4 → video/mp4, webm → video/webm, mov → video/quicktime. */
    function videoMime(string $ext): string {
        $ext = strtolower(trim($ext));
        if ($ext === 'webm') return 'video/webm';
        if ($ext === 'mov')  return 'video/quicktime';
        return 'video/mp4'; // mp4 / unknown
    }
}

/** Legacy name — same table as videoMime(). */
function videoMimeForExt($ext) {
    return videoMime((string)$ext);
}

if (!function_exists('videoFileLooksValid')) {
    /**
     * Container sniff for an uploaded video. $ext is the extension the file
     * will be stored under (mp4 / webm / mov; the upload's tmp_name has none) —
     * the container must agree with it:
     *   webm      → EBML magic 1A 45 DF A3 at offset 0.
     *   mp4 / mov → an ISO-BMFF/QuickTime `ftyp` box: big-endian box size ≥ 8
     *               and a known major brand (isom, iso2, iso5, iso6, mp41, mp42,
     *               avc1, "qt  ", "M4V ", mp71, dash). A file that starts with
     *               `ftyp` but fails that is rejected outright (no finfo rescue).
     *               Legacy QuickTime layouts without a leading ftyp (wide/mdat/
     *               moov first) may still pass when finfo says
     *               video/mp4 | video/quicktime | video/x-m4v.
     * Anything else (other video/* MIMEs, other extensions) → false.
     */
    function videoFileLooksValid(string $path, string $ext = ''): bool {
        if (!is_file($path) || filesize($path) === 0) return false;
        $h = (string)@file_get_contents($path, false, null, 0, 64);
        if (strlen($h) < 12) return false;
        $ext = strtolower(trim($ext));
        if ($ext === '') $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'mov'], true)) return false;

        if ($ext === 'webm') {
            return strncmp($h, "\x1A\x45\xDF\xA3", 4) === 0;                          // EBML (WebM / Matroska)
        }
        if (strncmp($h, "\x1A\x45\xDF\xA3", 4) === 0) return false;                  // EBML under an mp4/mov name
        $size = unpack('N', substr($h, 0, 4))[1];
        if (substr($h, 4, 4) === 'ftyp') {
            $brands = ['isom', 'iso2', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'qt  ', 'M4V ', 'mp71', 'dash'];
            return $size >= 8 && in_array(substr($h, 8, 4), $brands, true);
        }
        if (function_exists('finfo_open')) {
            $f = @finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = (string)@finfo_file($f, $path);
                finfo_close($f);
                return in_array($mime, ['video/mp4', 'video/quicktime', 'video/x-m4v'], true);
            }
        }
        return false;
    }
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

/** List media filenames (images + videos, spec §6) sitting directly in a
 *  brand's library folder, natural-sorted. Skips dotfiles (.DS_Store etc.),
 *  subfolders, anything that isn't a recognized media extension, and the
 *  transcoded .mp4 twin of a .mov (surfaced through videoTwinUrl() instead). */
function scanLibraryDir($dir) {
    if (!is_dir($dir)) return [];
    $exts = array_merge(imageExts(), videoExts());
    $out = [];
    $names = scandir($dir);
    $set = array_flip($names);
    foreach ($names as $f) {
        if ($f === '.' || $f === '..' || strpos($f, '.') === 0) continue;
        if (!is_file($dir . '/' . $f)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        // X.mp4 next to X.mov is the transcoded twin videoTwinUrl() serves as the second <source>, not its own asset.
        if ($ext === 'mp4' && (isset($set[substr($f, 0, -3) . 'mov']) || isset($set[substr($f, 0, -3) . 'MOV']))) continue;
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

/** Actor for activity rows. The seat is decided by the session, never by the
 *  request: a non-admin session is always 'client' (POST['actor'] is ignored, so
 *  the feed cannot be spoofed). The admin may pass actor=client|admin (Studio's
 *  "reply as client"); anything else → 'admin'. */
function actorFromPost() {
    if (function_exists('currentAdmin') && currentAdmin()) {
        $a = $_POST['actor'] ?? 'admin';
        return in_array($a, ['client', 'admin'], true) ? $a : 'admin';
    }
    return 'client';
}

/** Client slug sent with a state-changing request (POST `client`, falling back
 *  to the ?client= scope), sanitised to [a-z0-9-]; '' when absent. */
if (!function_exists('postedClientSlug')) {
    function postedClientSlug(): string {
        $raw = $_POST['client'] ?? $_GET['client'] ?? '';
        if (!is_string($raw)) return '';
        return preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($raw)));
    }
}

/** Tenant scoping for the client seat. Admin sessions always pass. A non-admin
 *  request passes only when the posted client slug resolves to $companyId
 *  (one companies lookup per request). Callers answer 403 on false. */
if (!function_exists('clientOwnsCompany')) {
    function clientOwnsCompany(PDO $pdo, int $companyId): bool {
        if (function_exists('currentAdmin') && currentAdmin()) return true;
        $slug = postedClientSlug();
        if ($slug === '' || $companyId <= 0) return false;
        try {
            $st = $pdo->prepare("SELECT slug FROM companies WHERE id = ?");
            $st->execute([$companyId]);
            $have = $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
        return is_string($have) && $have !== '' && $have === $slug;
    }
}

/** Refuse cross-site requests on state-changing endpoints. Uses the browser's
 *  Sec-Fetch-Site header only when present (absent = older client = allow);
 *  same-origin / same-site / none pass, anything else gets a JSON 403. */
if (!function_exists('requireSameSiteFetch')) {
    function requireSameSiteFetch(): void {
        $sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        if ($sfs === '') return;
        if (in_array(strtolower(trim($sfs)), ['same-origin', 'same-site', 'none'], true)) return;
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Cross-site request refused']);
        exit;
    }
}

/** Absolute path of a stored media URL ('uploads/x.jpg') ONLY when the file
 *  really lives directly inside this app's uploads/ directory (realpath
 *  containment — 'uploads/../config.php' and symlink tricks return null).
 *  Use before every unlink of a DB-supplied path. */
if (!function_exists('uploadsPathOrNull')) {
    function uploadsPathOrNull(string $url): ?string {
        $url = trim($url);
        if ($url === '' || strpos($url, 'uploads/') !== 0) return null;
        $uploadsDir = realpath(__DIR__ . '/uploads');
        if ($uploadsDir === false) return null;
        $path = __DIR__ . '/' . $url;
        if (!is_file($path)) return null;
        $real = realpath($path);
        if ($real === false || realpath(dirname($path)) !== $uploadsDir || dirname($real) !== $uploadsDir) return null;
        return $real;
    }
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
    $taskIds    = [];
    foreach ($grouped as $g) {
        if ($g['entity_type'] === 'tire_image')    { $imageIds[]  = (int)$g['entity_id']; }
        if ($g['entity_type'] === 'tire')          { $tireIds[]   = (int)$g['entity_id']; }
        if ($g['entity_type'] === 'post')          { $postIds[]   = (int)$g['entity_id']; }
        if ($g['entity_type'] === 'library_image') { $libImgIds[] = (int)$g['entity_id']; }
        if ($g['entity_type'] === 'task')          { $taskIds[]   = (int)$g['entity_id']; }
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
                   t.name AS tire_name, m.slug AS module_slug
              FROM tire_images ti
              INNER JOIN tires t ON t.id = ti.tire_id
              INNER JOIN modules m ON m.id = t.module_id
             WHERE ti.id IN ($ph)
        ");
        $s->execute($imageIds);
        foreach ($s->fetchAll() as $r) {
            $imageMeta[(int)$r['image_id']] = [
                'tire_id'      => (int)$r['tire_id'],
                'tire_name'    => (string)($r['tire_name'] ?? ''),
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
            SELECT t.id AS tire_id, t.name AS tire_name, m.slug AS module_slug
              FROM tires t
              INNER JOIN modules m ON m.id = t.module_id
             WHERE t.id IN ($ph)
        ");
        $s->execute($tireIds);
        foreach ($s->fetchAll() as $r) {
            $tireMeta[(int)$r['tire_id']] = [
                'module_slug' => $r['module_slug'],
                'name'        => (string)($r['tire_name'] ?? ''),
            ];
        }
    }
    $taskMeta = [];
    if ($taskIds) {
        $taskIds = array_values(array_unique($taskIds));
        $ph = implode(',', array_fill(0, count($taskIds), '?'));
        try {
            $s = $pdo->prepare("SELECT id, title FROM tasks WHERE id IN ($ph)");
            $s->execute($taskIds);
            foreach ($s->fetchAll() as $r) {
                $taskMeta[(int)$r['id']] = ['title' => (string)($r['title'] ?? '')];
            }
        } catch (Throwable $e) {
            $taskMeta = [];
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
        } elseif ($g['entity_type'] === 'task' && isset($taskMeta[(int)$g['entity_id']])) {
            $g['_meta'] = $taskMeta[(int)$g['entity_id']];
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
 *  Root-rooted via pagePath() so it resolves the same from any page. Aims to land the
 *  user *on the exact entity* — the post editor, the specific tire's review with the
 *  image anchored, the task highlighted in the project list — never a generic gallery. */
function activityLink($entry) {
    $slug = $entry['company_slug'] ?? '';
    $meta = $entry['_meta'] ?? null;

    $clientPair = $slug !== '' ? ['client' => $slug] : [];

    switch ($entry['entity_type']) {
        case 'post':
            // Land in the post editor with the comment thread visible.
            $qs = http_build_query(array_merge($clientPair, ['edit' => (int)$entry['entity_id']]));
            return pagePath('add-post') . '?' . $qs;

        case 'tire_image':
            // Per-image review: features?client=X&module=<slug>&item=<tire_id>#image-<id>
            $moduleSlug = $meta['module_slug'] ?? 'tires';
            $tireId     = (int)($meta['tire_id'] ?? 0);
            $params     = array_merge($clientPair, ['module' => $moduleSlug]);
            if ($tireId > 0) { $params['item'] = $tireId; }
            $url = pagePath('features') . '?' . http_build_query($params);
            if ($tireId > 0) { $url .= '#image-' . (int)$entry['entity_id']; }
            return $url;

        case 'tire':
            // Per-tire review (catch the tire entity itself: created/deleted/etc.)
            $moduleSlug = $meta['module_slug'] ?? 'tires';
            $params     = array_merge($clientPair, [
                'module' => $moduleSlug,
                'item'   => (int)$entry['entity_id'],
            ]);
            return pagePath('features') . '?' . http_build_query($params);

        case 'task':
            // Anchor straight to the task in the project list.
            $url = pagePath('projects');
            if ($clientPair) { $url .= '?' . http_build_query($clientPair); }
            return $url . '#task-' . (int)$entry['entity_id'];

        case 'library_image':
            // Anchor straight to the tile in the brand's library gallery.
            $url = pagePath('library');
            if ($clientPair) { $url .= '?' . http_build_query($clientPair); }
            return $url . '#lib-' . (int)$entry['entity_id'];

        default:
            return pagePath('admin') . ($clientPair ? '?' . http_build_query($clientPair) : '');
    }
}

// =====================================================================
// Humanized activity (spec §4.1 / §9: "Activity feed contains zero raw
// filenames"). Every sentence is built from entity_type + action + actor +
// the *parent's* name (post title, collection name, "Library", task title).
// The stored `summary` string is never printed — old rows embed filenames.
// `detail` is quoted only for `commented` rows.
// =====================================================================

/** True when a label is really a filename / upload stem (IMG_0042.jpg, hf-20260904-…, img_66f1…). */
if (!function_exists('activityLooksLikeFilename')) {
    function activityLooksLikeFilename(string $s): bool {
        $s = trim($s);
        if ($s === '') return false;
        if (preg_match('/\.(jpe?g|png|gif|webp|heic|mp4|mov|m4v|webm|avi|mkv)$/i', $s)) return true;
        if (preg_match('/^(img|vid|batch(_vid)?|feat|veh)_[0-9a-f.]+/i', $s)) return true;   // this app's upload prefixes
        if (preg_match('/^hf-\d{8}-\d{6}/i', $s)) return true;                                 // Higgsfield export stems
        if (preg_match('/^(IMG|DSC|DSCF|PXL|MVI|GOPR|DJI)[_-]?\d{3,}/i', $s)) return true;     // camera / phone stems
        return false;
    }
}

/**
 * Resolve the human "thing + parent" for one recentActivity() entry.
 *
 * Returns ['thing' => 'post'|'image'|'collection'|'task'|'item',
 *          'name'   => post title | task title | collection name | '' ,
 *          'parent' => collection name | 'Library' | '' ,
 *          'parent_key' => stable key used to collapse runs].
 * Never returns a filename: library images have no name at all (their
 * parent is "Library"), tire images are named by their collection.
 */
if (!function_exists('activityParentName')) {
    function activityParentName(array $entry): array {
        $meta = $entry['_meta'] ?? [];
        $id   = (int)($entry['entity_id'] ?? 0);
        $firstLine = static function ($s, $max = 60) {
            $s = trim((string)$s);
            if ($s === '') return '';
            $s = preg_split('/\r\n|\r|\n/', $s)[0];
            $s = trim(preg_replace('/\s+/', ' ', $s));
            if (function_exists('mb_strlen') && mb_strlen($s) > $max) {
                $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
            }
            return $s;
        };
        switch ($entry['entity_type'] ?? '') {
            case 'post':
                // Internal reference name first, then the caption's first line.
                // Either could have been typed as an upload stem — never show one.
                $name = trim((string)($meta['name'] ?? ''));
                if ($name === '' || activityLooksLikeFilename($name)) $name = $firstLine($meta['caption'] ?? '');
                if (activityLooksLikeFilename($name)) $name = '';
                return ['thing' => 'post', 'name' => $name, 'parent' => '', 'parent_key' => 'post:' . $id];
            case 'tire_image':
                $tireId = (int)($meta['tire_id'] ?? 0);
                return ['thing' => 'image', 'name' => '',
                        'parent' => trim((string)($meta['tire_name'] ?? '')),
                        'parent_key' => 'tire:' . ($tireId > 0 ? $tireId : 'unknown')];
            case 'tire':
                return ['thing' => 'collection', 'name' => trim((string)($meta['name'] ?? '')),
                        'parent' => '', 'parent_key' => 'tire:' . $id];
            case 'library_image':
                return ['thing' => 'image', 'name' => '', 'parent' => 'Library', 'parent_key' => 'library'];
            case 'task':
                return ['thing' => 'task', 'name' => $firstLine($meta['title'] ?? '', 80),
                        'parent' => '', 'parent_key' => 'task:' . $id];
            default:
                return ['thing' => 'item', 'name' => '', 'parent' => '',
                        'parent_key' => (string)($entry['entity_type'] ?? 'item') . ':' . $id];
        }
    }
}

/**
 * Deep link for an activity row using the redesign's URL contracts
 * (Posts detail sheet, Assets viewer / collection, Projects task anchor).
 * Cross-client feeds pass the entry's own company slug through clientUrl().
 * $collapsed = true → link to the parent (collection / Library) instead of
 * one item, because the row stands for several items.
 */
if (!function_exists('activityDeepLink')) {
    function activityDeepLink(array $entry, bool $collapsed = false): string {
        $slug = (string)($entry['company_slug'] ?? '');
        $qs   = $slug !== '' ? ['client' => $slug] : [];
        $id   = (int)($entry['entity_id'] ?? 0);
        $meta = $entry['_meta'] ?? [];
        switch ($entry['entity_type'] ?? '') {
            case 'post':
                return clientUrl('posts', $qs + ['post' => $id]);
            case 'tire_image':
                $tireId = (int)($meta['tire_id'] ?? 0);
                $p = $qs + ['view' => 'collections'];
                if ($tireId > 0) $p['item'] = $tireId;
                if (!$collapsed && $tireId > 0) { $p['asset'] = $id; $p['kind'] = 'tire'; }
                return clientUrl('assets', $p);
            case 'tire':
                return clientUrl('assets', $qs + ['view' => 'collections', 'item' => $id]);
            case 'library_image':
                $p = $qs + ['view' => 'library'];
                if (!$collapsed) { $p['asset'] = $id; $p['kind'] = 'library'; }
                return clientUrl('assets', $p);
            case 'task':
                return clientUrl('projects', $qs) . '#task-' . $id;
            default:
                return clientUrl('index.php', $qs);
        }
    }
}

/** Pick the action that describes a batch (deny + comment in one request → "denied"). */
if (!function_exists('activityPrimaryAction')) {
    function activityPrimaryAction(array $actions): string {
        static $rank = [
            'denied' => 1, 'approved' => 2, 'reset_pending' => 3, 'posted' => 4, 'unposted' => 5,
            'created' => 6, 'deleted' => 7,
            'task_created' => 8, 'task_toggled' => 9, 'task_deleted' => 10, 'task_updated' => 11,
            'edited_schedule' => 12, 'renamed_post' => 13, 'renamed_image' => 14,
            'edited_caption' => 15, 'edited_hashtags' => 16, 'edited_type' => 17,
            'type_changed' => 18, 'edited_image_caption' => 19,
            'commented' => 30, 'uncommented' => 31,
        ];
        $best = null; $bestRank = PHP_INT_MAX;
        foreach ($actions as $a) {
            $r = $rank[$a] ?? 25;
            if ($r < $bestRank) { $bestRank = $r; $best = $a; }
        }
        return (string)($best ?? ($actions[0] ?? 'updated'));
    }
}

/**
 * Turn recentActivity() entries into humanized rows.
 *
 *   humanizeActivityRows($entries, $role, $client)  → array of rows:
 *     text        plain sentence         "You denied 3 images in Warhawk renders"
 *     html        escaped HTML sentence, names wrapped in <em>
 *     href        deep link (see activityDeepLink)
 *     icon        icon() name            checkmark | xmark | ellipsis | calendar | plus | grid | photo | checklist
 *     tone        approve | deny | accent | scheduled | neutral
 *     time        created_at of the newest merged row; time_rel / time_abs formatted
 *     count       how many items the row stands for (1 unless collapsed)
 *     children    [['text','who','time','time_rel','href'], …]  comment texts (for the disclosure)
 *     edits       ['caption','schedule',…] for edit batches
 *     who, is_you, actor, action, actions, verb, thing, name, parent, parent_key,
 *     entity_type, entity_id, entity_ids, company_id, company_name, company_slug, batch_id
 *
 * $viewerRole is 'client' or 'admin'; the matching actor renders as "You".
 * $client (the scoped company row) names the other party; cross-client
 * feeds fall back to each entry's company_name.
 */
if (!function_exists('humanizeActivityRows')) {
    function humanizeActivityRows(array $entries, string $viewerRole = 'client', ?array $client = null): array {
        $rows = [];
        foreach ($entries as $e) {
            $actor   = (string)($e['actor'] ?? 'unknown');
            $actions = array_values(array_unique((array)($e['actions'] ?? [])));
            $action  = activityPrimaryAction($actions);
            $pn      = activityParentName($e);
            $isYou   = ($actor === $viewerRole);
            if ($isYou) {
                $who = 'You';
            } elseif ($actor === 'admin') {
                $who = 'Joust';
            } elseif ($actor === 'client') {
                $who = trim((string)(($client['name'] ?? '') ?: ($e['company_name'] ?? ''))) ?: 'Your team';
            } else {
                $who = 'Someone';
            }

            $children = [];
            foreach ((array)($e['details'] ?? []) as $d) {
                if (($d['action'] ?? '') === 'commented' && trim((string)($d['text'] ?? '')) !== '') {
                    $children[] = [
                        'text'     => trim((string)$d['text']),
                        'who'      => $who,
                        'time'     => (string)($e['created_at'] ?? ''),
                        'time_rel' => relativeTime($e['created_at'] ?? null),
                        'href'     => activityDeepLink($e, false),
                    ];
                }
            }
            $edits = [];
            foreach ($actions as $a) {
                if (strpos($a, 'edited_') === 0) $edits[] = str_replace('_', ' ', substr($a, 7));
                elseif (strpos($a, 'renamed_') === 0) $edits[] = 'name';
                elseif ($a === 'type_changed') $edits[] = 'type';
            }
            $edits = array_values(array_unique($edits));

            $rows[] = [
                'key'          => $actor . '|' . $action . '|' . ($e['entity_type'] ?? '') . '|' . $pn['parent_key'],
                'entity_type'  => (string)($e['entity_type'] ?? ''),
                'entity_id'    => (int)($e['entity_id'] ?? 0),
                'entity_ids'   => [(int)($e['entity_id'] ?? 0)],
                'action'       => $action,
                'actions'      => $actions,
                'actor'        => $actor,
                'who'          => $who,
                'is_you'       => $isYou,
                'thing'        => $pn['thing'],
                'name'         => $pn['name'],
                'parent'       => $pn['parent'],
                'parent_key'   => $pn['parent_key'],
                'count'        => 1,
                'children'     => $children,
                'edits'        => $edits,
                'time'         => (string)($e['created_at'] ?? ''),
                'company_id'   => $e['company_id'] ?? null,
                'company_name' => (string)($e['company_name'] ?? ''),
                'company_slug' => (string)($e['company_slug'] ?? ''),
                'batch_id'     => $e['batch_id'] ?? null,
                '_entry'       => $e,
            ];
        }
        return activityFinalizeRows($rows);
    }
}

/**
 * Collapse consecutive rows with the same actor + action + parent that fall
 * within $windowSec of each other (default 10 minutes) into one row whose
 * count is the number of items and whose children hold every comment.
 * Input must be newest-first (recentActivity() order). Rows sharing a
 * batch_id were already merged by recentActivity().
 */
if (!function_exists('collapseActivityRuns')) {
    function collapseActivityRuns(array $rows, int $windowSec = 600): array {
        $out = [];
        $prev = null;
        foreach ($rows as $r) {
            if ($prev !== null && $prev['key'] === $r['key']) {
                $tPrev = strtotime((string)$prev['_last_time']);
                $tCur  = strtotime((string)$r['time']);
                if ($tPrev !== false && $tCur !== false && abs($tPrev - $tCur) <= $windowSec) {
                    $prev['entity_ids'] = array_values(array_unique(array_merge($prev['entity_ids'], $r['entity_ids'])));
                    $prev['count']      = count($prev['entity_ids']);   // distinct items, not rows
                    $prev['children']   = array_merge($prev['children'], $r['children']);
                    $prev['edits']      = array_values(array_unique(array_merge($prev['edits'], $r['edits'])));
                    $prev['_last_time'] = $r['time'];
                    if ($prev['parent'] === '' && $r['parent'] !== '') $prev['parent'] = $r['parent'];
                    if ($prev['name'] === '' && $r['name'] !== '')     $prev['name']   = $r['name'];
                    continue;
                }
            }
            if ($prev !== null) $out[] = $prev;
            $prev = $r;
            $prev['_last_time'] = $r['time'];
        }
        if ($prev !== null) $out[] = $prev;
        foreach ($out as &$r) { unset($r['_last_time']); }
        unset($r);
        return activityFinalizeRows($out);
    }
}

/** (internal) Build text/html/href/icon/tone for rows after humanize or collapse. */
if (!function_exists('activityFinalizeRows')) {
    function activityFinalizeRows(array $rows): array {
        $h = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        foreach ($rows as &$r) {
            $n      = max(1, (int)$r['count']);
            $many   = $n > 1;
            // Object phrase, plain + html ("3 images in <em>Warhawk renders</em>", "<em>Dominator</em>")
            $objT = ''; $objH = '';
            switch ($r['thing']) {
                case 'post':
                    if (!$many && $r['name'] !== '')      { $objT = $r['name']; $objH = '<em>' . $h($r['name']) . '</em>'; }
                    elseif ($many)                        { $objT = $objH = $n . ' posts'; }
                    else                                  { $objT = $objH = 'a post'; }
                    break;
                case 'image':
                    $lead = $many ? $n . ' images' : 'an image';
                    if ($r['parent'] !== '') { $objT = $lead . ' in ' . $r['parent']; $objH = $h($lead) . ' in <em>' . $h($r['parent']) . '</em>'; }
                    else                     { $objT = $objH = $lead; }
                    break;
                case 'collection':
                    if (!$many && $r['name'] !== '') { $objT = 'the ' . $r['name'] . ' collection'; $objH = 'the <em>' . $h($r['name']) . '</em> collection'; }
                    else                             { $objT = $objH = $many ? $n . ' collections' : 'a collection'; }
                    break;
                case 'task':
                    if (!$many && $r['name'] !== '') { $objT = $r['name']; $objH = '<em>' . $h($r['name']) . '</em>'; }
                    else                             { $objT = $objH = $many ? $n . ' tasks' : 'a task'; }
                    break;
                default:
                    $objT = $objH = $many ? $n . ' items' : 'an item';
            }
            $who  = $r['who'];
            $whoH = $h($who);
            $a    = $r['action'];
            $icon = 'ellipsis'; $tone = 'neutral';
            switch ($a) {
                case 'approved':
                    $verb = 'approved'; $icon = 'checkmark'; $tone = 'approve';
                    $t = "$who approved $objT"; $hh = "$whoH approved $objH"; break;
                case 'denied':
                    $verb = 'denied'; $icon = 'xmark'; $tone = 'deny';
                    $t = "$who denied $objT"; $hh = "$whoH denied $objH"; break;
                case 'reset_pending':
                    $verb = 'reopened'; $icon = 'grid'; $tone = 'accent';
                    $t = "$who reopened $objT for review"; $hh = "$whoH reopened $objH for review"; break;
                case 'posted':
                    $verb = 'scheduled'; $icon = 'calendar'; $tone = 'scheduled';
                    $t = "$who scheduled $objT"; $hh = "$whoH scheduled $objH"; break;
                case 'unposted':
                    $verb = 'unscheduled'; $icon = 'calendar'; $tone = 'neutral';
                    $t = "$who unscheduled $objT"; $hh = "$whoH unscheduled $objH"; break;
                case 'commented':
                    $verb = 'commented'; $icon = 'ellipsis'; $tone = 'accent';
                    $t = "$who commented on $objT"; $hh = "$whoH commented on $objH"; break;
                case 'uncommented':
                    $verb = 'cleared a comment'; $icon = 'ellipsis'; $tone = 'neutral';
                    $t = "$who cleared a comment on $objT"; $hh = "$whoH cleared a comment on $objH"; break;
                case 'edited_schedule':
                    $verb = 'rescheduled'; $icon = 'calendar'; $tone = 'scheduled';
                    $t = "$who rescheduled $objT"; $hh = "$whoH rescheduled $objH"; break;
                case 'created':
                    $icon = 'plus'; $tone = 'accent';
                    if ($r['thing'] === 'post')     { $verb = 'added'; $t = "$who added $objT for review"; $hh = "$whoH added $objH for review"; }
                    elseif ($r['thing'] === 'task') { $verb = 'opened'; $t = "$who opened $objT"; $hh = "$whoH opened $objH"; }
                    else                            { $verb = 'added'; $t = "$who added $objT"; $hh = "$whoH added $objH"; }
                    break;
                case 'deleted':
                    $verb = 'removed'; $icon = 'xmark'; $tone = 'neutral';
                    // The entity is gone, so no name resolves; say what kind of thing it was.
                    $kind = $r['thing'] === 'image' ? ($many ? $n . ' images' : 'an image')
                          : ($r['thing'] === 'collection' ? ($many ? $n . ' collections' : 'a collection')
                          : ($r['thing'] === 'post' ? ($many ? $n . ' posts' : 'a post') : $objT));
                    $t = "$who removed $kind"; $hh = "$whoH removed " . $h($kind); break;
                case 'task_created':
                    $verb = 'opened'; $icon = 'checklist'; $tone = 'accent';
                    $t = "$who opened $objT"; $hh = "$whoH opened $objH"; break;
                case 'task_updated':
                    $verb = 'updated'; $icon = 'checklist'; $tone = 'neutral';
                    $t = "$who updated $objT"; $hh = "$whoH updated $objH"; break;
                case 'task_toggled':
                    $icon = 'checklist'; $tone = 'approve';
                    // Direction comes from the stored status token only (never the free text).
                    $sum = implode(' ', (array)($r['_entry']['summaries'] ?? []));
                    $reopened = (bool)preg_match('/→\s*(open|in_progress)\b/u', $sum);
                    $verb = $reopened ? 'reopened' : 'completed';
                    if ($reopened) $tone = 'neutral';
                    $t = "$who $verb $objT"; $hh = "$whoH $verb $objH"; break;
                case 'task_deleted':
                    $verb = 'removed'; $icon = 'xmark'; $tone = 'neutral';
                    $t = "$who removed a task"; $hh = "$whoH removed a task"; break;
                default:
                    if (strpos($a, 'edited_') === 0 || strpos($a, 'renamed_') === 0 || $a === 'type_changed') {
                        $verb = 'updated'; $icon = 'ellipsis'; $tone = 'neutral';
                        $t = "$who updated $objT"; $hh = "$whoH updated $objH";
                    } else {
                        $verb = actionLabel($a); $icon = 'ellipsis'; $tone = 'neutral';
                        $t = "$who $verb $objT"; $hh = "$whoH " . $h($verb) . " $objH";
                    }
            }
            if ($r['entity_type'] === 'task' && $icon === 'ellipsis') $icon = 'checklist';
            // One comment → quote it inline; several → the disclosure lists them.
            if (count($r['children']) === 1) {
                $q = $r['children'][0]['text'];
                $qShort = (function_exists('mb_strlen') && mb_strlen($q) > 240) ? rtrim(mb_substr($q, 0, 239)) . '…' : $q;
                $t  .= " — '" . $qShort . "'";
                $hh .= ' — <q>' . $h($qShort) . '</q>';
            }
            $r['verb']     = $verb;
            $r['text']     = $t;
            $r['html']     = $hh;
            $r['icon']     = $icon;
            $r['tone']     = $tone;
            $r['href']     = activityDeepLink($r['_entry'], $many);
            $r['time_rel'] = relativeTime($r['time']);
            $r['time_abs'] = absoluteTime($r['time']);
        }
        unset($r);
        return $rows;
    }
}

/**
 * Render the activity feed panel HTML. $companyId optional (null = global).
 * Returns a string of HTML — caller is responsible for surrounding container.
 *
 * Signature unchanged (admin.php and index.php call it). Rows are now the
 * humanized, run-collapsed sentences from humanizeActivityRows() +
 * collapseActivityRuns(); the markup keeps the legacy class names
 * (.activity-day / .activity-row / .activity-actor / .activity-text /
 * .activity-detail / .activity-time) so existing page CSS still applies.
 */
function renderActivityFeed(PDO $pdo, $companyId = null, $limit = 20) {
    global $client;
    $entries = recentActivity($pdo, $companyId, (int)$limit * 2);
    if (!$entries) {
        return '<div class="activity-empty">No activity yet. Approvals, comments, and edits will show up here.</div>';
    }
    $h = function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $viewerRole = isAdmin() ? 'admin' : 'client';
    $scoped = $companyId ? (is_array($client) && (int)($client['id'] ?? 0) === (int)$companyId ? $client : null) : null;
    $rows = collapseActivityRuns(humanizeActivityRows($entries, $viewerRole, $scoped));
    $rows = array_slice($rows, 0, max(1, (int)$limit));

    $out = '';
    $lastBucket = null;
    foreach ($rows as $r) {
        $bucket = dayBucket($r['time']);
        if ($bucket !== $lastBucket) {
            $out .= '<div class="activity-day">' . $h($bucket) . '</div>';
            $lastBucket = $bucket;
        }
        $actor = $r['actor'] ?: 'unknown';
        $pill  = $actor === 'admin' ? 'Joust' : ($actor === 'client' ? ($r['company_name'] !== '' ? $r['company_name'] : 'Client') : 'Unknown');
        $companyLine = ($companyId === null && $r['company_name'] !== '' && $actor !== 'client')
            ? '<span class="activity-company">' . $h($r['company_name']) . '</span> · '
            : '';
        $detailHtml = '';
        if (count($r['children']) > 1) {
            foreach ($r['children'] as $c) {
                $q = $c['text'];
                $detailHtml .= '<div class="activity-detail">"' . $h(mb_substr($q, 0, 240))
                            . (mb_strlen($q) > 240 ? '…' : '') . '"</div>';
            }
        }
        $editList = $r['edits'] ? '<span class="activity-verb"> — ' . $h(implode(', ', $r['edits'])) . '</span>' : '';

        $out .= '<a class="activity-row" href="' . $h($r['href']) . '">'
              . '<span class="activity-actor activity-actor-' . $h($actor) . '">' . $h($pill) . '</span>'
              . '<span class="activity-text">'
              . $companyLine
              . '<span class="activity-target">' . $r['html'] . '</span>'
              . $editList
              . $detailHtml
              . '</span>'
              . '<span class="activity-time" title="' . $h($r['time_abs']) . '">' . $h($r['time_rel']) . '</span>'
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

// =====================================================================
// New shell (static/ + partials/) — see partials/layout-top.php for the
// page-level usage; the functions below are what the legacy pages call.
// =====================================================================

/**
 * 36px rounded-square client avatar: the logo when the company has one,
 * otherwise the first letter of the name on a tertiary fill. Unscoped → "J".
 */
if (!function_exists('clientAvatar')) {
    function clientAvatar($client, string $class = ''): string {
        $name = !empty($client['name']) ? (string)$client['name'] : 'Joust Media';
        $logo = !empty($client['logo_url']) ? (string)$client['logo_url'] : '';
        $cls  = trim('ui-avatar ' . $class);
        if ($logo !== '') {
            return '<img class="' . esc($cls) . '" src="' . esc($logo) . '" alt="' . esc($name) . '" width="36" height="36" loading="lazy">';
        }
        $initial = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
        $initial = function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
        return '<span class="' . esc($cls . ' ui-avatar--initial') . '" aria-label="' . esc($name) . '">' . esc($initial) . '</span>';
    }
}

/** Root-rooted URL for a file under static/, cache-busted by mtime. */
function staticUrl(string $path): string {
    $path = ltrim($path, '/');
    $file = __DIR__ . '/static/' . $path;
    $v    = is_file($file) ? (string)filemtime($file) : '';
    return basePath() . '/static/' . $path . ($v !== '' ? '?v=' . $v : '');
}

/** The four shared stylesheets, in cascade order. */
function appStylesheets(): string {
    $out = '';
    foreach (['tokens', 'base', 'components', 'motion'] as $name) {
        $out .= '<link rel="stylesheet" href="' . esc(staticUrl('css/' . $name . '.css')) . '">' . "\n";
    }
    return $out;
}

/** app.js — deferred so it can sit in <head> on legacy pages. */
function appScript(): string {
    return '<script src="' . esc(staticUrl('js/app.js')) . '" defer></script>' . "\n";
}

/**
 * Everything a legacy page needs in its existing <head> to render inside the
 * new shell: color-scheme/theme-color metas, the stylesheets and app.js.
 * (New pages use partials/layout-top.php instead.)
 */
function renderAppHead(): string {
    return "\n" . '<meta name="color-scheme" content="light dark">' . "\n"
         . '<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">' . "\n"
         . '<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">' . "\n"
         . '<meta name="format-detection" content="telephone=no">' . "\n"
         . appStylesheets()
         . appScript();
}

/**
 * Nav bar + role-aware tab bar as one HTML string. Safe to call from any
 * page scope: the partials get $pdo/$client/$role via `global` here.
 *
 *   $opts: 'subtitle' (eyebrow), 'back' (['href','label']), 'leading' (HTML),
 *          'trailing' (HTML; default client avatar; '' hides),
 *          'links' ([['label','href','primary']]), 'wide' (bool),
 *          'width' ('900px' — content column to align the nav with),
 *          'active' (tab id), 'tabs' (bool; default: client scoped or admin)
 */
function renderAppChrome(string $pageTitle, array $opts = []): string {
    global $pdo, $client, $clientSlug, $role;

    $navSubtitle = isset($opts['subtitle']) ? (string)$opts['subtitle'] : '';
    $navBack     = isset($opts['back']) && is_array($opts['back']) ? $opts['back'] : null;
    $navLeading  = isset($opts['leading']) ? (string)$opts['leading'] : '';
    $navTrailing = array_key_exists('trailing', $opts) ? $opts['trailing'] : null;
    $navLinks    = isset($opts['links']) && is_array($opts['links']) ? $opts['links'] : [];
    $navWide     = !empty($opts['wide']);
    $navWidth    = isset($opts['width']) && preg_match('/^\d{2,4}px$/', (string)$opts['width']) ? (string)$opts['width'] : '';
    $activeTab   = isset($opts['active']) ? $opts['active'] : null;
    $showTabs    = array_key_exists('tabs', $opts) ? (bool)$opts['tabs'] : (!empty($client) || isAdmin());

    ob_start();
    include __DIR__ . '/partials/navbar.php';
    if ($showTabs) {
        include __DIR__ . '/partials/tabbar.php';
    }
    return (string)ob_get_clean();
}
