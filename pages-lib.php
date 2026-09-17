<?php
/**
 * Pages module — shared helpers (loaded from the end of emails-lib.php; never include directly).
 *
 * A "page" is a static HTML landing page Joust builds per client and the client
 * reviews inside the portal. Two sources:
 *   upload  a folder media/pages/<client-slug>/<page-slug>/ at the docroot (a
 *           sibling of the app, like media/tires/) holding the entry file
 *           (index.html by default) plus any css / js / images / fonts the admin
 *           uploads next to it (page-upload.php). Served statically by Apache —
 *           media/ carries a .htaccess that switches PHP off and hides listings.
 *   url     an external address (like an email's html_url).
 * The page is shown in a sandboxed <iframe> on pages.php with an "Open in new tab" link.
 *
 * Tables: pages, page_files (migrate.php 27–28) plus the 'pages' modules row
 * (28b) that company_modules points at to enable the Pages tab per client.
 * Every query is gated on hasPagesTable() so a deploy that has not run
 * migrate.php renders "no pages" instead of a 500.
 *
 * Status vocabulary = the emails one (display key = what pages branch on):
 *   draft | pending | approved | denied  — pages.status while live = 0
 *   live                                 — pages.live = 1, whatever the status
 * Labels: Draft / To Review / Approved / Needs changes / Live.
 *
 * All functions are function_exists-guarded and do no work at load. $pdo
 * arguments that default to null fall back to the global $pdo.
 */

if (!function_exists('pagesPdo')) {
    /** (internal) Resolve the PDO to use: the argument, else the global. */
    function pagesPdo(?PDO $pdo = null): ?PDO {
        if ($pdo instanceof PDO) return $pdo;
        $g = $GLOBALS['pdo'] ?? null;
        return $g instanceof PDO ? $g : null;
    }
}

if (!function_exists('hasPagesTable')) {
    /** Does the pages table exist yet? (migrate.php may not have run.) Cached per request. */
    function hasPagesTable(?PDO $pdo = null): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        $pdo = pagesPdo($pdo);
        if ($pdo === null) return false;   // not cached: a later call may have a PDO
        try {
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages'
            ");
            $s->execute();
            return $cached = (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return $cached = false;
        }
    }
}

if (!function_exists('companyHasPages')) {
    /**
     * Should this company see the Pages tab? True when the 'pages' module is
     * enabled for it (company_modules) OR at least one pages row exists. Cheap
     * (two LIMIT 1 probes, fetch() not COUNT — the older suites' regex fakes
     * answer false, so a company without pages stays byte-identical) and cached
     * per company id for the request.
     */
    function companyHasPages(array $company, ?PDO $pdo = null): bool {
        static $cache = [];
        $cid = (int)($company['id'] ?? 0);
        if ($cid <= 0) return false;
        if (array_key_exists($cid, $cache)) return $cache[$cid];
        $pdo = pagesPdo($pdo);
        if ($pdo === null || !hasPagesTable($pdo)) return false;
        try {
            $s = $pdo->prepare("
                SELECT cm.company_id
                  FROM company_modules cm
                 INNER JOIN modules m ON m.id = cm.module_id
                 WHERE cm.company_id = ? AND m.slug = 'pages'
                 LIMIT 1
            ");
            $s->execute([$cid]);
            if ($s->fetch()) return $cache[$cid] = true;
            $s = $pdo->prepare("SELECT id FROM pages WHERE company_id = ? LIMIT 1");
            $s->execute([$cid]);
            return $cache[$cid] = (bool)$s->fetch();
        } catch (Throwable $e) {
            error_log('companyHasPages failed: ' . $e->getMessage());
            return $cache[$cid] = false;
        }
    }
}

if (!function_exists('pagesModuleId')) {
    /** modules.id of the 'pages' row (migrate.php 28b), 0 when not seeded. */
    function pagesModuleId(PDO $pdo): int {
        try {
            $s = $pdo->prepare("SELECT id FROM modules WHERE slug = 'pages'");
            $s->execute();
            return (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('pagesModuleEnabled')) {
    /** Is the 'pages' module switched on for the company (company_modules row)? */
    function pagesModuleEnabled(PDO $pdo, int $companyId): bool {
        $mid = pagesModuleId($pdo);
        if ($mid <= 0 || $companyId <= 0) return false;
        $s = $pdo->prepare("SELECT 1 FROM company_modules WHERE company_id = ? AND module_id = ? LIMIT 1");
        $s->execute([$companyId, $mid]);
        return (bool)$s->fetchColumn();
    }
}

if (!function_exists('setPagesModuleEnabled')) {
    /** Enable / disable the Pages tab for a company. Returns false when the module row is missing. */
    function setPagesModuleEnabled(PDO $pdo, int $companyId, bool $on): bool {
        $mid = pagesModuleId($pdo);
        if ($mid <= 0 || $companyId <= 0) return false;
        if ($on) {
            $pdo->prepare("INSERT IGNORE INTO company_modules (company_id, module_id, sort_order) VALUES (?, ?, ?)")->execute([$companyId, $mid, 98]);
        } else {
            $pdo->prepare("DELETE FROM company_modules WHERE company_id = ? AND module_id = ?")->execute([$companyId, $mid]);
        }
        return true;
    }
}

// ---------------------------------------------------------------------
// Status vocabulary (same five keys / labels / pills as emails)
// ---------------------------------------------------------------------

if (!function_exists('pageStatusKeys')) {
    function pageStatusKeys(): array {
        return ['draft', 'pending', 'approved', 'denied', 'live'];
    }
}

if (!function_exists('pageStatusKey')) {
    /** Display key for a row: live=1 → 'live', else its status (unknown → 'draft'). */
    function pageStatusKey(array $page): string {
        if (!empty($page['live'])) return 'live';
        $s = strtolower(trim((string)($page['status'] ?? '')));
        return in_array($s, ['draft', 'pending', 'approved', 'denied'], true) ? $s : 'draft';
    }
}

if (!function_exists('pageStatusLabelForKey')) {
    function pageStatusLabelForKey(string $key): string {
        static $map = [
            'draft'    => 'Draft',
            'pending'  => 'To Review',
            'approved' => 'Approved',
            'denied'   => 'Needs changes',
            'live'     => 'Live',
        ];
        return $map[strtolower(trim($key))] ?? 'Draft';
    }
}

if (!function_exists('pageStatusLabel')) {
    function pageStatusLabel(array $page): string {
        return pageStatusLabelForKey(pageStatusKey($page));
    }
}

if (!function_exists('pageStatusPill')) {
    /** statusPill() for a page: live → green "scheduled" pill labelled Live; draft → neutral; the rest 1:1. */
    function pageStatusPill(array $page, array $opts = []): string {
        $key  = pageStatusKey($page);
        $live = $key === 'live';
        $opts['label'] = $opts['label'] ?? pageStatusLabelForKey($key);
        return statusPill($live ? 'approved' : $key, $live, $opts);
    }
}

if (!function_exists('pageSourceLabel')) {
    /** 'Upload' | 'URL' for the source badge. */
    function pageSourceLabel($source): string {
        return strtolower(trim((string)$source)) === 'url' ? 'URL' : 'Upload';
    }
}

// ---------------------------------------------------------------------
// Small value helpers
// ---------------------------------------------------------------------

if (!function_exists('pageSlugify')) {
    /** 'Spring Launch 2026' → 'spring-launch-2026': lowercase ASCII, [a-z0-9-], ≤120 chars; '' when nothing survives. */
    function pageSlugify(string $s): string {
        $s = trim($s);
        if ($s !== '' && function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string)$s, '-');
        if (strlen($s) > 120) $s = rtrim(substr($s, 0, 120), '-');
        return $s;
    }
}

if (!function_exists('pageDisplayLabel')) {
    /** The page's name for summaries: title, else slug, else 'page #N'. */
    function pageDisplayLabel(array $page): string {
        $title = trim((string)($page['title'] ?? ''));
        if ($title !== '') return $title;
        $slug = trim((string)($page['slug'] ?? ''));
        if ($slug !== '') return $slug;
        return 'page #' . (int)($page['id'] ?? 0);
    }
}

if (!function_exists('pageValidUrl')) {
    /** '' or an absolute http(s) URL → true. */
    function pageValidUrl(string $url): bool {
        $url = trim($url);
        if ($url === '') return true;
        return (bool)preg_match('#^https?://[^\s]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

// ---------------------------------------------------------------------
// Files: names, folders, containment
// ---------------------------------------------------------------------

if (!function_exists('pageUploadExts')) {
    /** Extensions page-upload.php accepts (lowercase). Never anything the web server could execute. */
    function pageUploadExts(): array {
        return ['html', 'htm', 'css', 'js', 'json', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico',
                'woff', 'woff2', 'ttf', 'mp4', 'webm'];
    }
}

if (!function_exists('pageForbiddenExts')) {
    /** Extensions that are refused outright even when hidden behind a second dot ('x.php.html' is fine, 'x.html.php' is not). */
    function pageForbiddenExts(): array {
        return ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phar', 'cgi', 'pl', 'py', 'sh', 'htaccess', 'htpasswd'];
    }
}

if (!function_exists('pageSanitizeFilename')) {
    /**
     * A safe file name for the page folder from the browser's name: basename only,
     * stem restricted to [A-Za-z0-9._-] (spaces → '-'), lowercase extension.
     * Returns '' for dotfiles, empty stems, forbidden or unknown extensions, or names
     * with a forbidden extension anywhere in the dotted chain.
     */
    function pageSanitizeFilename(string $name): string {
        $name = str_replace('\\', '/', trim($name));
        $name = basename($name);
        if ($name === '' || $name[0] === '.') return '';
        $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $stem = (string)pathinfo($name, PATHINFO_FILENAME);
        if ($ext === '' || !in_array($ext, pageUploadExts(), true)) return '';
        foreach (explode('.', strtolower($name)) as $part) {
            if (in_array($part, pageForbiddenExts(), true)) return '';
        }
        $stem = preg_replace('/[^A-Za-z0-9._\-\s]+/', '-', $stem);
        $stem = preg_replace('/[\s]+/', '-', (string)$stem);
        $stem = preg_replace('/-+/', '-', (string)$stem);
        $stem = trim((string)$stem, '-_. ');
        if ($stem === '' || $stem[0] === '.') return '';
        if (strlen($stem) > 100) $stem = rtrim(substr($stem, 0, 100), '-_.');
        if ($stem === '') return '';
        return $stem . '.' . $ext;
    }
}

if (!function_exists('pageSubfolderValid')) {
    /** Optional relative subfolder ('img', 'assets/fonts'): [a-z0-9_-] segments joined by '/', ≤4 deep, no '..', no dot-segments. */
    function pageSubfolderValid(string $sub): bool {
        $sub = trim($sub);
        if ($sub === '') return true;
        if (strlen($sub) > 120) return false;
        if (!preg_match('#^[a-z0-9_\-/]+$#', $sub)) return false;
        $segs = explode('/', $sub);
        if (count($segs) > 4) return false;
        foreach ($segs as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || $seg[0] === '.') return false;
        }
        return true;
    }
}

if (!function_exists('pageFileRelValid')) {
    /**
     * Is a stored relative file path ('index.html', 'img/hero.png') one we would
     * have written? Sanitised basename + valid subfolder, no dot segments, ≤255 chars.
     */
    function pageFileRelValid(string $rel): bool {
        $rel = trim($rel);
        if ($rel === '' || strlen($rel) > 255 || strpos($rel, '\\') !== false) return false;
        if ($rel[0] === '/' || substr($rel, -1) === '/') return false;
        $dir  = strpos($rel, '/') !== false ? substr($rel, 0, strrpos($rel, '/')) : '';
        $name = basename($rel);
        if (!pageSubfolderValid($dir)) return false;
        return pageSanitizeFilename($name) === $name;
    }
}

if (!function_exists('pagesMediaRootPath')) {
    /** media/pages on disk (docroot sibling, like media/tires and media/library). */
    function pagesMediaRootPath(): string {
        $root = function_exists('mediaRootPath') ? mediaRootPath() : __DIR__ . '/../media';
        return $root . '/pages';
    }
}

if (!function_exists('pageCompanySlug')) {
    /** The tenant slug for a page: $company['slug'] when given, else the 'company_slug' pageById()/pagesForCompany() attach. */
    function pageCompanySlug(array $page, ?array $company = null): string {
        $slug = trim((string)($company['slug'] ?? ($page['company_slug'] ?? '')));
        return preg_match('/^[a-z0-9\-]+$/', $slug) ? $slug : '';
    }
}

if (!function_exists('pageFolderRel')) {
    /** 'media/pages/<client-slug>/<page-slug>' (docroot-relative, no leading slash); '' when either slug is unusable. */
    function pageFolderRel(array $company, array $page): string {
        $co = pageCompanySlug($page, $company);
        $pg = trim((string)($page['slug'] ?? ''));
        if ($co === '' || $pg === '' || !preg_match('/^[a-z0-9\-]+$/', $pg)) return '';
        return 'media/pages/' . $co . '/' . $pg;
    }
}

if (!function_exists('pageFolderPath')) {
    /** Filesystem path of the page folder ('' when the slugs are unusable). Existence is not checked. */
    function pageFolderPath(array $company, array $page): string {
        $rel = pageFolderRel($company, $page);
        if ($rel === '') return '';
        return pagesMediaRootPath() . substr($rel, strlen('media/pages'));
    }
}

if (!function_exists('pageFolderContained')) {
    /**
     * Containment check for a page folder: the textual path must be exactly two
     * validated segments under media/pages/ and, when realpath() resolves, the
     * resolved folder must sit there too (no symlink escape). Returns the path to
     * use or null.
     */
    function pageFolderContained(array $company, array $page): ?string {
        $path = pageFolderPath($company, $page);
        if ($path === '') return null;
        if (is_link($path)) return null;
        if (!is_dir($path)) return $path;   // nothing on disk yet: the validated string path stands
        $rootReal = realpath(pagesMediaRootPath());
        $dirReal  = realpath($path);
        if ($rootReal === false || $dirReal === false) return $path;   // stream-wrapped harness: textual validation only
        $want = rtrim($rootReal, '/') . '/' . pageCompanySlug($page, $company) . '/' . (string)$page['slug'];
        return $dirReal === $want ? $dirReal : null;
    }
}

if (!function_exists('pageFilePath')) {
    /**
     * Absolute path of one file inside the page folder, or null when the relative
     * name is not one we would have written, the folder escapes media/pages/, or
     * (with $mustExist) the file is missing / a symlink / outside the folder.
     */
    function pageFilePath(array $company, array $page, string $rel, bool $mustExist = true): ?string {
        if (!pageFileRelValid($rel)) return null;
        $dir = pageFolderContained($company, $page);
        if ($dir === null) return null;
        $path = $dir . '/' . $rel;
        if (!$mustExist) return $path;
        if (is_link($path) || !is_file($path)) return null;
        $real = realpath($path);
        $dirReal = realpath($dir);
        if ($real !== false && $dirReal !== false && strpos($real, rtrim($dirReal, '/') . '/') !== 0) return null;
        return $real !== false ? $real : $path;
    }
}

if (!function_exists('pageViewUrl')) {
    /**
     * Where the page is viewed: the external URL (http(s) only) for source = url,
     * else the root-relative '/media/pages/<client>/<slug>/<entry>' (segments
     * rawurlencoded, like libraryFileUrl()). '' when nothing usable.
     */
    function pageViewUrl(array $page, ?array $company = null): string {
        if (strtolower((string)($page['source'] ?? 'upload')) === 'url') {
            $url = trim((string)($page['url'] ?? ''));
            return preg_match('#^https?://[^\s"\'<>]+$#i', $url) ? $url : '';
        }
        $rel = pageFolderRel($company ?? [], $page);
        if ($rel === '') return '';
        $entry = trim((string)($page['entry'] ?? 'index.html'));
        if ($entry === '' || !pageFileRelValid($entry)) $entry = 'index.html';
        $segs = array_merge(explode('/', $rel), explode('/', $entry));
        return '/' . implode('/', array_map('rawurlencode', $segs));
    }
}

if (!function_exists('pageMediaHtaccessText')) {
    /** The .htaccess written into media/pages/ (and media/ when absent): no PHP/CGI, no directory listing. */
    function pageMediaHtaccessText(): string {
        if (function_exists('tireMediaHtaccessText')) {
            return str_replace('tire-series-lib.php', 'pages-lib.php', tireMediaHtaccessText());
        }
        return "# Written by the portal (pages-lib.php): this folder only serves static files.\n"
             . "Options -Indexes\n"
             . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_php8.c>\n    php_flag engine off\n</IfModule>\n"
             . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .pht .phar .cgi .pl .py .sh\n"
             . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .pht .phar\n"
             . "<FilesMatch \"(?i)\\.(php\\d?|phtml|phps|pht|phar|cgi|pl|py|sh|htaccess)$\">\n"
             . "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
             . "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n"
             . "</FilesMatch>\n";
    }
}

if (!function_exists('ensurePagesMediaHtaccess')) {
    /** Make sure media/pages/.htaccess exists (and media/.htaccess when the parent has none). Writes only when missing. */
    function ensurePagesMediaHtaccess(): int {
        $n = 0;
        $pages = pagesMediaRootPath();
        $media = dirname($pages);
        foreach ([$media, $pages] as $dir) {
            if (!is_dir($dir)) continue;
            $file = $dir . '/.htaccess';
            if (is_file($file)) continue;
            if (@file_put_contents($file, pageMediaHtaccessText()) !== false) { @chmod($file, 0644); $n++; }
        }
        return $n;
    }
}

if (!function_exists('deletePageFolder')) {
    /**
     * Remove a page's folder and everything in it — ONLY when the folder passes
     * pageFolderContained() (exactly media/pages/<client>/<slug>, no symlinks).
     * Symlinks inside are unlinked, never followed. Returns the number of files removed
     * (0 when nothing was on disk), or -1 when the folder was refused.
     */
    function deletePageFolder(array $company, array $page): int {
        $dir = pageFolderContained($company, $page);
        if ($dir === null) return -1;
        if (!is_dir($dir)) return 0;
        $n = 0;
        $walk = static function (string $d) use (&$walk, &$n): void {
            $names = @scandir($d);
            if (!is_array($names)) return;
            foreach ($names as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $d . '/' . $f;
                if (is_link($p) || is_file($p)) { if (@unlink($p)) $n++; continue; }
                if (is_dir($p)) { $walk($p); @rmdir($p); }
            }
        };
        $walk($dir);
        @rmdir($dir);
        return $n;
    }
}

if (!function_exists('renamePageFolder')) {
    /** After a slug change: move media/pages/<client>/<old>/ to <new>/ when the old folder exists and the new one does not. */
    function renamePageFolder(array $company, array $oldPage, array $newPage): bool {
        $from = pageFolderContained($company, $oldPage);
        $to   = pageFolderContained($company, $newPage);
        if ($from === null || $to === null || !is_dir($from) || file_exists($to)) return false;
        return @rename($from, $to);
    }
}

// ---------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------

if (!function_exists('pagesSortRows')) {
    /** (internal) sort_order ASC, then natural title order, then id. Stable. */
    function pagesSortRows(array $rows): array {
        usort($rows, static function ($a, $b) {
            $c = ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
            if ($c !== 0) return $c;
            $c = strnatcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
            if ($c !== 0) return $c;
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });
        return array_values($rows);
    }
}

if (!function_exists('pagesForCompany')) {
    /**
     * Pages for one company (+ 'company_slug' from companies for URLs).
     *   $opts['status']    draft|pending|approved|denied|live|all (default all); the four
     *                      statuses imply live = 0, 'live' means live = 1 whatever the status.
     *   $opts['q']         substring over title / slug / description.
     *   $opts['visibleTo'] 'admin' (default, everything) or 'client' (live = 1 OR status IN
     *                      pending, approved — drafts and Needs-changes rows are hidden in SQL).
     * Ordered by sort_order, then natural title order.
     */
    function pagesForCompany(PDO $pdo, int $companyId, array $opts = []): array {
        if ($companyId <= 0 || !hasPagesTable($pdo)) return [];
        $where  = ['p.company_id = ?'];
        $params = [$companyId];

        $status = strtolower(trim((string)($opts['status'] ?? 'all')));
        if ($status === 'live') {
            $where[] = 'p.live = 1';
        } elseif (in_array($status, ['draft', 'pending', 'approved', 'denied'], true)) {
            $where[]  = 'p.status = ?';
            $params[] = $status;
            $where[]  = 'p.live = 0';
        }
        if (($opts['visibleTo'] ?? 'admin') === 'client') {
            $where[] = "(p.live = 1 OR p.status IN ('pending','approved'))";
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(p.title LIKE ? OR p.slug LIKE ? OR p.description LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $s = $pdo->prepare("SELECT p.*, c.slug AS company_slug FROM pages p INNER JOIN companies c ON c.id = p.company_id WHERE "
                         . implode(' AND ', $where) . " ORDER BY p.sort_order ASC, p.title ASC");
        $s->execute($params);
        return pagesSortRows($s->fetchAll());
    }
}

if (!function_exists('pageById')) {
    /** One row (+ company_slug) or null. Callers scope with company_id / clientOwnsCompany() themselves. */
    function pageById(PDO $pdo, int $id): ?array {
        if ($id <= 0 || !hasPagesTable($pdo)) return null;
        $s = $pdo->prepare("SELECT p.*, c.slug AS company_slug FROM pages p INNER JOIN companies c ON c.id = p.company_id WHERE p.id = ?");
        $s->execute([$id]);
        $row = $s->fetch();
        return $row ?: null;
    }
}

if (!function_exists('pageBySlug')) {
    /** Row (+ company_slug) matching the slug within the company, or null. */
    function pageBySlug(PDO $pdo, int $companyId, string $slug): ?array {
        $slug = pageSlugify($slug);
        if ($companyId <= 0 || $slug === '' || !hasPagesTable($pdo)) return null;
        $s = $pdo->prepare("SELECT p.*, c.slug AS company_slug FROM pages p INNER JOIN companies c ON c.id = p.company_id WHERE p.company_id = ? AND p.slug = ?");
        $s->execute([$companyId, $slug]);
        $row = $s->fetch();
        return $row ?: null;
    }
}

if (!function_exists('pageFilesFor')) {
    /** page_files rows for a page: [['id','page_id','filename','size','created_at'], …] natural-sorted by filename. */
    function pageFilesFor(PDO $pdo, int $pageId): array {
        if ($pageId <= 0 || !hasPagesTable($pdo)) return [];
        $s = $pdo->prepare("SELECT id, page_id, filename, size, created_at FROM page_files WHERE page_id = ? ORDER BY filename ASC");
        $s->execute([$pageId]);
        $rows = [];
        foreach ($s->fetchAll() as $r) {
            $r['id'] = (int)$r['id']; $r['page_id'] = (int)$r['page_id']; $r['size'] = (int)$r['size'];
            $rows[] = $r;
        }
        usort($rows, static function ($a, $b) { return strnatcasecmp((string)$a['filename'], (string)$b['filename']); });
        return array_values($rows);
    }
}

if (!function_exists('pageFileCounts')) {
    /** [page_id => number of files] for a set of pages (one query). */
    function pageFileCounts(PDO $pdo, array $pageIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds), static function ($i) { return $i > 0; })));
        $out = [];
        if (!$ids || !hasPagesTable($pdo)) return $out;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT page_id, COUNT(*) AS n FROM page_files WHERE page_id IN ($ph) GROUP BY page_id");
        $s->execute($ids);
        foreach ($s->fetchAll() as $r) $out[(int)$r['page_id']] = (int)$r['n'];
        return $out;
    }
}

if (!function_exists('pageFileUpsert')) {
    /** Record (or refresh) one file of a page. Returns the page_files id. */
    function pageFileUpsert(PDO $pdo, int $pageId, string $filename, int $size): int {
        $s = $pdo->prepare("SELECT id FROM page_files WHERE page_id = ? AND filename = ?");
        $s->execute([$pageId, $filename]);
        $id = (int)$s->fetchColumn();
        if ($id > 0) {
            $pdo->prepare("UPDATE page_files SET size = ? WHERE id = ?")->execute([$size, $id]);
            return $id;
        }
        $pdo->prepare("INSERT INTO page_files (page_id, filename, size) VALUES (?, ?, ?)")->execute([$pageId, $filename, $size]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('pageFileForget')) {
    /** Drop one page_files row (the file itself is the caller's business — see page-upload.php). */
    function pageFileForget(PDO $pdo, int $pageId, string $filename): void {
        $pdo->prepare("DELETE FROM page_files WHERE page_id = ? AND filename = ?")->execute([$pageId, $filename]);
    }
}

if (!function_exists('pageCounts')) {
    /** Per display key: draft, pending, approved, denied, live (live=1 rows count only as live) + total. */
    function pageCounts(PDO $pdo, int $companyId): array {
        $out = ['draft' => 0, 'pending' => 0, 'approved' => 0, 'denied' => 0, 'live' => 0, 'total' => 0];
        if ($companyId <= 0 || !hasPagesTable($pdo)) return $out;
        $s = $pdo->prepare("SELECT status, live, COUNT(*) AS n FROM pages WHERE company_id = ? GROUP BY status, live");
        $s->execute([$companyId]);
        foreach ($s->fetchAll() as $r) {
            $n = (int)($r['n'] ?? 0);
            $key = pageStatusKey(['status' => $r['status'] ?? '', 'live' => $r['live'] ?? 0]);
            $out[$key] += $n;
            $out['total'] += $n;
        }
        return $out;
    }
}

if (!function_exists('pageLatestNotes')) {
    /** Newest 'commented' row per page: [page_id => ['detail','actor','created_at']]. */
    function pageLatestNotes(PDO $pdo, array $pageIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds))));
        if (!$ids || !function_exists('hasActivityLog') || !hasActivityLog($pdo)) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("
            SELECT entity_id, actor, detail, created_at
              FROM activity_log
             WHERE entity_type = 'page' AND action = 'commented'
               AND detail IS NOT NULL AND detail <> ''
               AND entity_id IN ($ph)
             ORDER BY created_at DESC, id DESC
        ");
        $s->execute($ids);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $eid = (int)$r['entity_id'];
            if (isset($out[$eid])) continue;   // rows are newest first
            $out[$eid] = ['detail' => (string)$r['detail'], 'actor' => (string)$r['actor'], 'created_at' => (string)$r['created_at']];
        }
        return $out;
    }
}

if (!function_exists('logPageActivity')) {
    /**
     * activity_log row with entity_type 'page'. Actions: created, edited_<field>, uploaded,
     * submitted, approved, denied, reset_pending, commented, marked_live, unmarked_live, deleted.
     * company_id is read from the row when not supplied. Never throws.
     */
    function logPageActivity(PDO $pdo, string $actor, string $action, int $pageId, string $summary,
                             ?string $detail = null, ?string $batchId = null, ?int $companyId = null): void {
        if ($companyId === null || $companyId <= 0) {
            $companyId = 0;
            try {
                $s = $pdo->prepare("SELECT company_id FROM pages WHERE id = ?");
                $s->execute([$pageId]);
                $companyId = (int)$s->fetchColumn();
            } catch (Throwable $e) {
                $companyId = 0;
            }
        }
        logActivity($pdo, $companyId, 'page', $pageId, $action, $actor, $summary, $detail, $batchId);
    }
}

if (!function_exists('deletePage')) {
    /**
     * Delete a page: its folder (contained; upload source only), page_files rows and
     * the row; logs 'deleted' with the company id given explicitly.
     * Returns ['files' => n] (n = files unlinked, -1 = folder refused, 0 = nothing on disk).
     */
    function deletePage(PDO $pdo, array $page, string $actor = 'admin'): array {
        $id  = (int)($page['id'] ?? 0);
        $cid = (int)($page['company_id'] ?? 0);
        $out = ['files' => 0];
        if ($id <= 0 || !hasPagesTable($pdo)) return $out;
        if (strtolower((string)($page['source'] ?? 'upload')) !== 'url') {
            $out['files'] = deletePageFolder(['slug' => pageCompanySlug($page)], $page);
        }
        $pdo->prepare("DELETE FROM page_files WHERE page_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM pages WHERE id = ? AND company_id = ?")->execute([$id, $cid]);
        logPageActivity($pdo, $actor, 'deleted', $id, 'Page ' . pageDisplayLabel($page) . ' deleted', null, null, $cid);
        return $out;
    }
}

if (!function_exists('pageFieldLabel')) {
    /** Human label for a field / change key ('entry' → 'Entry file'). */
    function pageFieldLabel(string $key): string {
        static $map = [
            'title' => 'Title', 'slug' => 'Slug', 'source' => 'Source', 'url' => 'URL', 'entry' => 'Entry file',
            'description' => 'Description', 'notes' => 'Notes', 'status' => 'Status', 'live' => 'Live',
        ];
        return $map[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}

if (!function_exists('pageFormatBytes')) {
    /** '12 KB' / '1.4 MB' for the file list. */
    function pageFormatBytes(int $bytes): string {
        if ($bytes >= 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.') . ' MB';
        if ($bytes >= 1024) return (int)round($bytes / 1024) . ' KB';
        return $bytes . ' B';
    }
}

// ---------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------

if (!function_exists('pagesUrl')) {
    /** pages.php URL in the current client scope (pass 'client' => slug to override). */
    function pagesUrl(array $params = []): string {
        return clientUrl('pages.php', $params);
    }
}

if (!function_exists('pageUrl')) {
    /** Deep link to one page's detail (pages.php?client=…&page=ID). */
    function pageUrl(array $page, array $params = []): string {
        return pagesUrl(['page' => (int)($page['id'] ?? 0)] + $params);
    }
}
