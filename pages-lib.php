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

require_once __DIR__ . '/media-lib.php';   // media/ hardening + permissions, shared with tire-series-lib.php

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
    /**
     * Extensions that are refused anywhere in the dotted chain ('x.php.html' and 'x.html.php'
     * are both refused): Apache's mod_mime applies handlers / filters for EVERY extension of a
     * multi-extension name, so a server-side extension must never appear at all. Covers PHP,
     * CGI / script handlers, server-side includes (.shtml .shtm .stm), .inc and other
     * server-executed page types, plus the Apache control files.
     */
    function pageForbiddenExts(): array {
        return ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phar', 'cgi', 'pl', 'py', 'sh',
                'shtml', 'shtm', 'stm', 'inc', 'asp', 'aspx', 'jsp', 'jspx', 'cfm', 'cfml', 'hta',
                'htaccess', 'htpasswd'];
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
    /**
     * The .htaccess written into media/pages/: the ONE shared text of media-lib.php (same as
     * media/tires/). No PHP/CGI, no directory listing, no server-side-include filter on .html,
     * script-ish names refused — and every directive except `Options -Indexes` inside an
     * <IfModule> guard, so a cPanel host with PHP-FPM / LSAPI never answers 500 for the folder.
     */
    function pageMediaHtaccessText(): string {
        return mediaHtaccessText('pages-lib.php');
    }
}

if (!function_exists('ensurePagesMediaHtaccess')) {
    /**
     * Make sure media/pages/.htaccess is the current text: written when missing, rewritten when
     * it carries an older marker of ours, never touched when it is not ours. An old media/.htaccess
     * of ours at the parent level is removed (that level is no longer managed by the portal).
     * Returns the number of files written or updated.
     */
    function ensurePagesMediaHtaccess(): int {
        $pages = pagesMediaRootPath();
        $r = mediaEnsureHtaccess($pages, 'pages-lib.php');
        mediaRemoveParentHtaccess(dirname($pages));
        return in_array($r['action'], ['written', 'updated'], true) ? 1 : 0;
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
// Embedded assets: base64 data: URIs in uploaded HTML → files under assets/
//
// A single-file landing page (images, fonts, even video inlined as data: URIs) can be
// megabytes of text/html. Shared hosts with ModSecurity response-body inspection answer
// 500 for HTML responses over their limit (cPanel default 512 KB; images and video are not
// inspected), so page-upload.php extracts every embedded asset into a real file next to
// the HTML and rewrites the reference. Nothing else in the markup is touched.
// ---------------------------------------------------------------------

if (!function_exists('pageInlineAssetMaxHtmlBytes')) {
    /** The largest HTML file the extractor (and the chunked HTML upload) accepts. */
    function pageInlineAssetMaxHtmlBytes(): int {
        return 64 * 1024 * 1024;
    }
}

if (!function_exists('pageInlineAssetMinBase64')) {
    /** data: URIs shorter than this (base64 chars, ≈ 190 bytes) stay inline — a 1×1 pixel is not worth a file. */
    function pageInlineAssetMinBase64(): int {
        return 256;
    }
}

if (!function_exists('pageInlineAssetExt')) {
    /** MIME type of a data: URI → the stored extension ('' = not a type we extract). */
    function pageInlineAssetExt(string $mime): string {
        static $map = [
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
            'font/woff2' => 'woff2', 'application/font-woff2' => 'woff2', 'application/x-font-woff2' => 'woff2',
            'font/woff' => 'woff', 'application/font-woff' => 'woff', 'application/x-font-woff' => 'woff',
            'font/ttf' => 'ttf', 'font/truetype' => 'ttf', 'font/sfnt' => 'ttf', 'application/font-sfnt' => 'ttf',
            'application/x-font-ttf' => 'ttf', 'application/x-font-truetype' => 'ttf',
            'text/css' => 'css',
            'video/mp4' => 'mp4', 'video/webm' => 'webm',
        ];
        return $map[strtolower(trim($mime))] ?? '';
    }
}

if (!function_exists('pageInlineAssetIsImage')) {
    function pageInlineAssetIsImage(string $ext): bool {
        return in_array($ext, ['png', 'jpg', 'gif', 'webp', 'svg', 'ico'], true);
    }
}

if (!function_exists('pageInlineBlobValid')) {
    /**
     * Is a decoded data: URI body really what its MIME type says? Images must decode
     * (getimagesizefromstring) AND match the extension; SVG must start with <svg / <?xml and carry no
     * <script, no on*= handler and no PHP tag; fonts by magic bytes (wOFF / wOF2 / \0\1\0\0 / true /
     * OTTO); ICO by its header; CSS must not carry a PHP tag or <script; MP4 / WebM by the same
     * container sniff as videoFileLooksValid() (ftyp brands / EBML). Anything else: false.
     */
    function pageInlineBlobValid(string $bytes, string $ext): bool {
        if (strlen($bytes) < 8) return false;
        switch ($ext) {
            case 'png': case 'jpg': case 'gif': case 'webp':
                $info = @getimagesizefromstring($bytes);
                if ($info === false || (int)($info[0] ?? 0) <= 0 || (int)($info[1] ?? 0) <= 0) return false;
                $byType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
                if (defined('IMAGETYPE_WEBP')) $byType[IMAGETYPE_WEBP] = 'webp';
                return ($byType[(int)($info[2] ?? 0)] ?? '') === $ext;
            case 'svg':
                $head = ltrim(substr($bytes, 0, 4096), " \t\r\n\xEF\xBB\xBF");
                if (stripos($head, '<svg') !== 0 && stripos($head, '<?xml') !== 0) return false;
                return stripos($bytes, '<script') === false && !preg_match('/<\?php|<\?=/i', $bytes) && !preg_match('/\son[a-z]+\s*=/i', $bytes);
            case 'woff':  return strncmp($bytes, 'wOFF', 4) === 0;
            case 'woff2': return strncmp($bytes, 'wOF2', 4) === 0;
            case 'ttf':   return strncmp($bytes, "\0\1\0\0", 4) === 0 || strncmp($bytes, 'true', 4) === 0 || strncmp($bytes, 'OTTO', 4) === 0;
            case 'ico':   return strncmp($bytes, "\0\0\1\0", 4) === 0;
            case 'css':   return !preg_match('/<\?php|<\?=|<script/i', $bytes);
            case 'webm':  return strncmp($bytes, "\x1A\x45\xDF\xA3", 4) === 0;
            case 'mp4':
                if (strncmp($bytes, "\x1A\x45\xDF\xA3", 4) === 0 || substr($bytes, 4, 4) !== 'ftyp') return false;
                $size = (int)(unpack('N', substr($bytes, 0, 4))[1] ?? 0);
                return $size >= 8 && in_array(substr($bytes, 8, 4), ['isom', 'iso2', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'qt  ', 'M4V ', 'mp71', 'dash'], true);
        }
        return false;
    }
}

if (!function_exists('pageBumpMemoryLimit')) {
    /** Raise memory_limit to at least 512M (when the host allows ini_set) before holding a large HTML body + one decoded blob. */
    function pageBumpMemoryLimit(): void {
        $cur = trim((string)ini_get('memory_limit'));
        if ($cur === '-1' || $cur === '') return;
        $n = (int)$cur;
        switch (strtolower(substr($cur, -1))) {
            case 'g': $n *= 1024;   // fall through
            case 'm': $n *= 1024;   // fall through
            case 'k': $n *= 1024;
        }
        if ($n > 0 && $n < 512 * 1024 * 1024) @ini_set('memory_limit', '512M');
    }
}

if (!function_exists('pageExtractInlineAssets')) {
    /**
     * Rewrite base64 data: URIs in an HTML string into files. Every `data:<mime>;base64,<b64>` whose
     * MIME is one of pageInlineAssetExt() and whose body is at least pageInlineAssetMinBase64()
     * chars — wherever it sits: src / srcset / poster / href attributes, CSS url(…) in <style> blocks
     * and style="" attributes, even a JS string — is decoded, validated (pageInlineBlobValid) and
     * written to <$dir>/<$assetsRel>/<sha1-12>.<ext> (content-addressed, so identical blobs share one
     * file and re-running is a no-op), and the reference becomes "<assetsRel>/<name>". A blob that
     * fails validation stays inline and is counted as skipped. Nothing else in the markup changes.
     *
     * The scan is linear (stripos + strspn + one anchored preg_match per candidate), never a regex
     * over the whole document, so a 50 MB file costs the document + one decoded blob in memory.
     * Limits: base64 only (a percent-encoded `data:image/svg+xml,%3Csvg…` stays inline), no
     * whitespace inside the base64 run (a wrapped URI is left alone).
     *
     * Returns ['html', 'extracted' => references rewritten, 'files' => [name => bytes] (written or
     * reused this call), 'skipped', 'failed' => write errors, 'bytes_saved', 'html_bytes_before', 'html_bytes_after'].
     */
    function pageExtractInlineAssets(string $html, string $dir, string $assetsRel = 'assets'): array {
        $before = strlen($html);
        $out = ['html' => $html, 'extracted' => 0, 'files' => [], 'skipped' => 0, 'failed' => 0, 'bytes_saved' => 0,
                'html_bytes_before' => $before, 'html_bytes_after' => $before];
        if ($before === 0 || $dir === '' || stripos($html, 'data:') === false) return $out;
        $min = pageInlineAssetMinBase64();
        $b64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
        $assetsDir = rtrim($dir, '/') . '/' . $assetsRel;
        $written = [];
        $result = ''; $last = 0; $pos = 0;
        while (($p = stripos($html, 'data:', $pos)) !== false) {
            $pos = $p + 5;
            $prev = $p > 0 ? $html[$p - 1] : ' ';
            if (strpos("\"'(,= \t\r\n", $prev) === false) continue;      // "metadata:" and friends: not the start of a URL
            if (!preg_match('/\Gdata:([a-z0-9.+\-]+\/[a-z0-9.+\-]+)(?:;[a-z0-9\-]+=[a-z0-9.\-_]*)*;base64,/i', $html, $m, 0, $p)) continue;
            $ext   = pageInlineAssetExt($m[1]);
            $start = $p + strlen($m[0]);
            $len   = strspn($html, $b64, $start);
            $end   = $start + $len;
            $next  = $end < $before ? $html[$end] : ' ';
            $pos   = max($pos, $end);
            if ($ext === '' || $len < $min) continue;
            if (strpos("\"') ,>< \t\r\n\\", $next) === false) continue;   // runs into a non-delimiter: leave it alone
            $bytes = base64_decode(substr($html, $start, $len), true);
            if ($bytes === false || $bytes === '' || !pageInlineBlobValid($bytes, $ext)) { $out['skipped']++; continue; }
            $name = substr(sha1($bytes), 0, 12) . '.' . $ext;
            $path = $assetsDir . '/' . $name;
            if (!isset($written[$name])) {
                if (!is_dir($assetsDir) && !(function_exists('mediaMkdir') ? mediaMkdir($assetsDir) : (@mkdir($assetsDir, 0755, true) || is_dir($assetsDir)))) { $out['failed']++; continue; }
                if (is_link($path) || (file_exists($path) && !is_file($path))) { $out['failed']++; continue; }
                // content-addressed: an existing file of that name and size IS this blob — nothing to write
                if (!is_file($path) || (int)@filesize($path) !== strlen($bytes)) {
                    if (@file_put_contents($path, $bytes) === false) { $out['failed']++; continue; }
                }
                if (function_exists('mediaChmodPath')) mediaChmodPath($path); else @chmod($path, 0644);
                $written[$name] = strlen($bytes);
            }
            unset($bytes);
            $result .= substr($html, $last, $p - $last) . $assetsRel . '/' . $name;
            $last = $end;
            $out['extracted']++;
        }
        $out['files'] = $written;
        if ($out['extracted'] === 0) return $out;
        $result .= substr($html, $last);
        $out['html'] = $result;
        $out['html_bytes_after'] = strlen($result);
        $out['bytes_saved'] = $before - strlen($result);
        return $out;
    }
}

if (!function_exists('pageExtractInlineAssetsFile')) {
    /**
     * pageExtractInlineAssets() over one stored HTML file: assets go to <its folder>/assets/, the file
     * is rewritten in place (temp file + rename, 0644) only when something was extracted. $rel is the
     * page-relative name ('index.html', 'pages/start.html'); the returned 'files' are page-relative too
     * ('assets/ab12cd34ef56.png', 'pages/assets/…') and 'name' echoes $rel. Returns null when the file is
     * missing, over pageInlineAssetMaxHtmlBytes() or its assets folder would not be a valid page path
     * (5 levels deep). A failed rewrite reports extracted 0 / failed 1 and leaves the HTML as it was.
     */
    function pageExtractInlineAssetsFile(string $path, string $rel): ?array {
        if (is_link($path) || !is_file($path)) return null;
        clearstatcache(true, $path);
        $size = (int)filesize($path);
        if ($size > pageInlineAssetMaxHtmlBytes()) return null;
        $sub = strpos($rel, '/') !== false ? substr($rel, 0, strrpos($rel, '/')) : '';
        $assetsSub = ($sub !== '' ? $sub . '/' : '') . 'assets';
        if (!pageSubfolderValid($assetsSub)) return null;
        pageBumpMemoryLimit();
        $html = @file_get_contents($path);
        if ($html === false) return null;
        $r = pageExtractInlineAssets($html, dirname($path), 'assets');
        unset($html);
        $files = [];
        foreach ($r['files'] as $name => $bytes) $files[$assetsSub . '/' . $name] = $bytes;
        $r['files'] = $files;
        $r['name'] = $rel;
        if ($r['extracted'] > 0) {
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $r['html']) === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                $r['failed']++; $r['extracted'] = 0; $r['bytes_saved'] = 0; $r['html_bytes_after'] = $r['html_bytes_before'];
            } else {
                if (function_exists('mediaChmodPath')) mediaChmodPath($path); else @chmod($path, 0644);
            }
        }
        unset($r['html']);
        return $r;
    }
}

if (!function_exists('pageExtractSummaryText')) {
    /** 'Extracted 14 images · 5.4 MB → 180 KB' (+ ' · 1 skipped'), 'Nothing to extract', or the failure. */
    function pageExtractSummaryText(array $r): string {
        $n = (int)($r['extracted'] ?? 0); $sk = (int)($r['skipped'] ?? 0); $failed = (int)($r['failed'] ?? 0);
        if ($n === 0) {
            if ($failed) return 'Could not write the extracted files (check folder permissions)';
            return $sk ? $sk . ' embedded file' . ($sk === 1 ? '' : 's') . ' could not be extracted (not a valid image / font)' : 'Nothing to extract';
        }
        $files = is_array($r['files'] ?? null) ? $r['files'] : [];
        $count = count($files);
        $allImages = $count > 0;
        foreach ($files as $name => $b) { if (!pageInlineAssetIsImage(strtolower((string)pathinfo((string)$name, PATHINFO_EXTENSION)))) { $allImages = false; break; } }
        $noun = $allImages ? 'image' : 'file';
        $s = 'Extracted ' . $count . ' ' . $noun . ($count === 1 ? '' : 's') . ($n > $count ? ' (' . $n . ' references)' : '')
           . ' · ' . pageFormatBytes((int)($r['html_bytes_before'] ?? 0)) . ' → ' . pageFormatBytes((int)($r['html_bytes_after'] ?? 0));
        if ($sk) $s .= ' · ' . $sk . ' skipped';
        if ($failed) $s .= ' · ' . $failed . ' failed';
        return $s;
    }
}

if (!function_exists('pageLargeHtmlFiles')) {
    /**
     * [page_id => [filename => size]] of the .html / .htm rows in page_files over $minBytes
     * (default mediaHtmlWarnBytes()) for a set of pages — the Studio list and the sheet use it to offer
     * "Extract embedded images". Sizes are the recorded ones (refreshed on every upload / extraction).
     */
    function pageLargeHtmlFiles(PDO $pdo, array $pageIds, ?int $minBytes = null): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds), static function ($i) { return $i > 0; })));
        $out = [];
        if (!$ids || !hasPagesTable($pdo)) return $out;
        $min = $minBytes ?? (function_exists('mediaHtmlWarnBytes') ? mediaHtmlWarnBytes() : 400 * 1024);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $s = $pdo->prepare("SELECT page_id, filename, size FROM page_files WHERE page_id IN ($ph) AND size > ?");
            $s->execute(array_merge($ids, [$min]));
            foreach ($s->fetchAll() as $r) {
                $name = (string)$r['filename'];
                if (!in_array(strtolower((string)pathinfo($name, PATHINFO_EXTENSION)), ['html', 'htm'], true)) continue;
                $out[(int)$r['page_id']][$name] = (int)$r['size'];
            }
        } catch (Throwable $e) {
            error_log('pageLargeHtmlFiles: ' . $e->getMessage());
        }
        return $out;
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
