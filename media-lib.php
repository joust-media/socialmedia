<?php
/**
 * media/ hardening + file permissions — shared by the Renders (tire-series-lib.php) and Pages
 * (pages-lib.php) modules. Loaded from the top of both; never include directly from a page.
 *
 * media/ sits next to the app at the docroot and is served by Apache as plain static files.
 * Two things can make the host answer 500 for EVERY file under a folder:
 *
 *   1. an .htaccess with a directive the host refuses (cPanel / EasyApache with PHP-FPM or LSAPI:
 *      an unguarded php_flag, XBitHack (Options-class), RemoveOutputFilter outside its module …).
 *      mediaHtaccessText() is therefore one conservative text for both folders: `Options -Indexes`
 *      (allowed on cPanel hosting) first and alone, everything else inside <IfModule> guards.
 *   2. files Apache cannot read: PHP's upload tmp files and the chunk spool are 0600, and
 *      mkdir() honours umask, so when PHP and Apache run as different users a stored file is
 *      unreadable. mediaChmodPath() / mediaMkdir() make every stored file 0644 and every created
 *      folder 0755, umask-independent; mediaChmodTree() repairs an existing tree.
 *
 * The .htaccess files the portal writes carry a marker line (`# joust-portal-media v3`); an older
 * portal version wrote `# Written by the portal (tire-series-lib.php|pages-lib.php)` as its first
 * line (treated as v1). mediaEnsureHtaccess() overwrites OUR older files with the current text and
 * never touches a file without a marker (server-managed). The portal no longer writes
 * media/.htaccess at the parent level (it would govern media/library/ too);
 * mediaRemoveParentHtaccess() deletes one that carries our marker.
 *
 * All functions are function_exists-guarded and do no work at load.
 */

if (!function_exists('mediaRootPath')) {
    /** media/ on disk — the docroot sibling of the app folder (same rule as tire-series-lib.php). */
    function mediaRootPath(): string {
        return __DIR__ . '/../media';
    }
}

if (!function_exists('mediaNormPath')) {
    /**
     * One spelling for a path: realpath() when it exists, else the textual form with '.', '..' and
     * doubled slashes collapsed — mediaRootPath() is `<app>/../media` while page / tire folders
     * come back resolved, and the ancestor walks below compare the two.
     */
    function mediaNormPath(string $p): string {
        if ($p === '') return '';
        $real = @realpath($p);
        if ($real !== false) return rtrim($real, '/') === '' ? '/' : rtrim($real, '/');
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $p)) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($parts); continue; }
            $parts[] = $seg;
        }
        return ($p[0] === '/' ? '/' : '') . implode('/', $parts);
    }
}

if (!function_exists('mediaHtaccessVersion')) {
    /** The version of the .htaccess text this portal writes (bump when mediaHtaccessText() changes). */
    function mediaHtaccessVersion(): int {
        return 3;   // v3: + guarded caching (mod_expires / mod_headers) and the .thumbs/ text (image previews)
    }
}

if (!function_exists('mediaHtaccessMarker')) {
    /** The marker line that identifies a file as ours: `# joust-portal-media v<N>`. */
    function mediaHtaccessMarker(?int $version = null): string {
        return '# joust-portal-media v' . ($version ?? mediaHtaccessVersion());
    }
}

if (!function_exists('mediaHtaccessText')) {
    /**
     * The one .htaccess text written into media/tires/ and media/pages/: no directory listing,
     * PHP engine off (only when PHP is an Apache module), no handler / type / output filter for
     * script-ish extensions, script-ish names refused outright, nosniff. Every line except
     * `Options -Indexes` sits inside an <IfModule> guard so a host without that module (or with
     * PHP-FPM / LSAPI) never sees an unknown directive.
     */
    function mediaHtaccessText(string $writer = 'media-lib.php'): string {
        $writer = preg_replace('/[^A-Za-z0-9._\-]/', '', $writer) ?: 'media-lib.php';
        $exts   = '.php .phtml .php3 .php4 .php5 .php7 .php8 .phps .phar .pl .py .cgi .shtml .shtm .stm .inc';
        $match  = '"\.(php|phtml|php[3-8]|phps|phar|pl|py|cgi|shtml|shtm|stm|inc|htaccess)$"';
        return mediaHtaccessMarker() . "\n"
             . "# Written by the portal ({$writer}): this folder only serves static files.\n"
             . "# Re-created on the next upload / rescan (or Studio > Repair server rules) if removed.\n"
             . "Options -Indexes\n"
             . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_php8.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_mime.c>\n"
             . "    RemoveHandler {$exts}\n"
             . "    RemoveType {$exts}\n"
             . "</IfModule>\n"
             . "<IfModule mod_include.c>\n"
             . "    <IfModule mod_mime.c>\n"
             . "        RemoveOutputFilter .shtml .shtm .stm .html .htm\n"
             . "    </IfModule>\n"
             . "</IfModule>\n"
             . "<IfModule mod_authz_core.c>\n"
             . "    <FilesMatch {$match}>\n        Require all denied\n    </FilesMatch>\n"
             . "</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\n"
             . "    <FilesMatch {$match}>\n        Order allow,deny\n        Deny from all\n    </FilesMatch>\n"
             . "</IfModule>\n"
             . "<IfModule mod_headers.c>\n    Header set X-Content-Type-Options nosniff\n</IfModule>\n"
             . mediaHtaccessCacheText(604800, false);
    }
}

if (!function_exists('mediaHtaccessCacheText')) {
    /**
     * (internal) The caching block of the .htaccess texts: images + video only (HTML under media/pages/ is never
     * cached), both halves guarded — mod_expires (Expires + max-age) and mod_headers (<FilesMatch> is core, so it
     * is safe inside the guard). $immutable for .thumbs/ (derivative URLs carry ?v=<mtime of the original>).
     */
    function mediaHtaccessCacheText(int $seconds, bool $immutable): string {
        $types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'video/mp4', 'video/webm', 'video/quicktime'];
        $human = $seconds >= 31536000 ? '1 year' : ($seconds % 86400 === 0 ? ($seconds / 86400) . ' days' : $seconds . ' seconds');
        $t = "<IfModule mod_expires.c>\n    ExpiresActive On\n";
        foreach ($types as $mime) $t .= "    ExpiresByType {$mime} \"access plus {$human}\"\n";
        $t .= "</IfModule>\n";
        $t .= "<IfModule mod_headers.c>\n"
            . "    <FilesMatch \"\\.(jpe?g|png|gif|webp|avif|svg|mp4|webm|mov)$\">\n"
            . "        Header set Cache-Control \"public, max-age={$seconds}" . ($immutable ? ', immutable' : '') . "\"\n"
            . "    </FilesMatch>\n"
            . "</IfModule>\n";
        return $t;
    }
}

if (!function_exists('mediaThumbsHtaccessText')) {
    /**
     * The .htaccess written into every <folder>/.thumbs/ (image previews, preview-lib.php): no listing, 1-year
     * immutable caching for the derivatives, the bookkeeping files (.json dims, .lock, .tmp) refused. Same marker
     * as the media text, so the repair / upgrade logic owns it too. Only `Options -Indexes` is unguarded.
     */
    function mediaThumbsHtaccessText(string $writer = 'preview-lib.php'): string {
        $writer = preg_replace('/[^A-Za-z0-9._\-]/', '', $writer) ?: 'preview-lib.php';
        $deny = '"\\.(json|lock|tmp|php|phtml|phar|htaccess)$"';
        return mediaHtaccessMarker() . "\n"
             . "# Written by the portal ({$writer}): image previews (sm / lg), derived from the originals one folder up.\n"
             . "# Safe to delete: they are regenerated on demand.\n"
             . "Options -Indexes\n"
             . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_php8.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_authz_core.c>\n"
             . "    <FilesMatch {$deny}>\n        Require all denied\n    </FilesMatch>\n"
             . "</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\n"
             . "    <FilesMatch {$deny}>\n        Order allow,deny\n        Deny from all\n    </FilesMatch>\n"
             . "</IfModule>\n"
             . "<IfModule mod_headers.c>\n    Header set X-Content-Type-Options nosniff\n</IfModule>\n"
             . mediaHtaccessCacheText(31536000, true);
    }
}

if (!function_exists('mediaHtaccessTextVersion')) {
    /**
     * Which portal version wrote this .htaccess text: N from the `# joust-portal-media vN` marker,
     * 1 for the legacy `# Written by the portal (tire-series-lib.php|pages-lib.php)` header,
     * 0 when it is not ours (server-managed, or empty).
     */
    function mediaHtaccessTextVersion(string $text): int {
        $head = substr($text, 0, 512);
        if (preg_match('/^# joust-portal-media v(\d+)\s*$/m', $head, $m)) return max(1, (int)$m[1]);
        if (preg_match('/^# Written by the portal \((tire-series-lib|pages-lib|media-lib)\.php\)/m', $head)) return 1;
        return 0;
    }
}

if (!function_exists('mediaHtaccessFileVersion')) {
    /** mediaHtaccessTextVersion() of a file on disk; 0 when missing, unreadable, a symlink or foreign. */
    function mediaHtaccessFileVersion(string $file): int {
        if (is_link($file) || !is_file($file)) return 0;
        $fh = @fopen($file, 'rb');
        if ($fh === false) return 0;
        $head = (string)fread($fh, 512);
        fclose($fh);
        return mediaHtaccessTextVersion($head);
    }
}

if (!function_exists('mediaEnsureHtaccess')) {
    /**
     * Make sure <dir>/.htaccess is the current text: written when missing, overwritten when it
     * carries an older marker of ours, kept when current, left alone when it is not ours.
     * $text: another text of ours (mediaThumbsHtaccessText() for .thumbs/); default mediaHtaccessText($writer).
     * Returns ['file' => path, 'action' => written | updated | kept | foreign | failed | no-dir,
     *          'from' => previous version (0 = none), 'version' => current version]. Never fatal.
     */
    function mediaEnsureHtaccess(string $dir, string $writer = 'media-lib.php', ?string $text = null): array {
        $dir  = rtrim($dir, '/');
        $file = $dir . '/.htaccess';
        $out  = ['file' => $file, 'action' => 'no-dir', 'from' => 0, 'version' => mediaHtaccessVersion()];
        if ($dir === '' || !is_dir($dir)) return $out;
        if (is_link($file) || (file_exists($file) && !is_file($file))) { $out['action'] = 'foreign'; return $out; }
        $from = is_file($file) ? mediaHtaccessFileVersion($file) : 0;
        $out['from'] = $from;
        if (is_file($file) && $from === 0) { $out['action'] = 'foreign'; return $out; }
        if ($from === mediaHtaccessVersion()) { $out['action'] = 'kept'; return $out; }
        if (@file_put_contents($file, $text ?? mediaHtaccessText($writer)) === false) { $out['action'] = 'failed'; return $out; }   // no LOCK_EX: stream-wrapped harnesses refuse it; two writers produce the same bytes anyway
        @chmod($file, 0644);
        $out['action'] = $from > 0 ? 'updated' : 'written';
        return $out;
    }
}

if (!function_exists('mediaRemoveParentHtaccess')) {
    /**
     * Delete media/.htaccess when it carries our marker (an older portal wrote it there; it governs
     * media/library/ too, so the portal no longer manages that level). A file without our marker
     * is never touched. Returns removed | foreign | absent | failed.
     */
    function mediaRemoveParentHtaccess(?string $mediaRoot = null): string {
        $file = rtrim($mediaRoot ?? mediaRootPath(), '/') . '/.htaccess';
        if (is_link($file) || !is_file($file)) return 'absent';
        if (mediaHtaccessFileVersion($file) === 0) return 'foreign';
        return @unlink($file) ? 'removed' : 'failed';
    }
}

/** Absolute path of a stored media URL ('uploads/x.jpg') ONLY when the file
 *  really lives directly inside this app's uploads/ directory (realpath
 *  containment — 'uploads/../config.php' and symlink tricks return null).
 *  Use before every unlink of a DB-supplied path. (Lives here so the session-free
 *  preview.php can use it; helpers.php loads this file.) */
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

// ---------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------

if (!function_exists('mediaPermsOctal')) {
    /** '0644'-style permission bits of a path ('' when it cannot be stat'd). */
    function mediaPermsOctal(string $path): string {
        clearstatcache(true, $path);
        $p = @fileperms($path);
        return $p === false ? '' : substr(sprintf('%04o', $p & 0777), -4);
    }
}

if (!function_exists('mediaPermsServable')) {
    /**
     * Can a web server running as ANOTHER user read this? Files need o+r, folders o+rx.
     * (PHP's own is_readable() answers for the PHP user, which is not the question.)
     */
    function mediaPermsServable(string $path): bool {
        clearstatcache(true, $path);
        $p = @fileperms($path);
        if ($p === false) return false;
        return is_dir($path) ? (($p & 0005) === 0005) : (($p & 0004) === 0004);
    }
}

if (!function_exists('mediaChmodPath')) {
    /**
     * Make one stored path servable: files 0644, folders 0755 (umask-independent). Symlinks are
     * never touched. Returns true when the bits are right afterwards (changed or already so).
     */
    function mediaChmodPath(string $path): bool {
        if (is_link($path)) return false;
        clearstatcache(true, $path);
        $p = @fileperms($path);
        if ($p === false) return false;
        $want = is_dir($path) ? 0755 : 0644;
        if (($p & 0777) === $want) return true;
        return @chmod($path, $want);
    }
}

if (!function_exists('mediaMkdir')) {
    /**
     * mkdir -p with every folder it creates set to 0755 whatever the umask (plus the existing
     * ancestors inside $within, when given, if they are not servable). True when $dir exists after.
     */
    function mediaMkdir(string $dir, ?string $within = null): bool {
        $dir = mediaNormPath(rtrim($dir, '/'));
        if ($within !== null) $within = mediaNormPath($within);
        if ($dir === '') return false;
        if (!is_dir($dir)) {
            $missing = [];
            for ($d = $dir; $d !== '' && $d !== '/' && $d !== '.' && !is_dir($d); $d = dirname($d)) {
                $missing[] = $d;
                if (dirname($d) === $d) break;
            }
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
            foreach ($missing as $d) { mediaChmodPath($d); }
        }
        if ($within !== null) {
            $stop = rtrim($within, '/');
            for ($d = $dir; $d !== '' && strpos($d . '/', $stop . '/') === 0; $d = dirname($d)) {
                if (!mediaPermsServable($d)) mediaChmodPath($d);
                if ($d === $stop || dirname($d) === $d) break;
            }
        }
        return is_dir($dir);
    }
}

if (!function_exists('mediaChmodTree')) {
    /**
     * Walk a folder (symlinks skipped, .spool/ skipped — its 0600/0700 is deliberate) and make
     * every file 0644 and folder 0755. Capped at $cap entries per call (the reply says so).
     * Returns ['root' => …, 'scanned', 'files', 'dirs', 'changed', 'failed', 'capped' => bool].
     */
    function mediaChmodTree(string $root, int $cap = 5000): array {
        $root = rtrim($root, '/');
        $out  = ['root' => $root, 'scanned' => 0, 'files' => 0, 'dirs' => 0, 'changed' => 0, 'failed' => 0, 'capped' => false];
        if ($root === '' || is_link($root) || !is_dir($root)) return $out;
        $fix = static function (string $path) use (&$out): void {
            $out['scanned']++;
            $isDir = is_dir($path);
            $isDir ? $out['dirs']++ : $out['files']++;
            $before = @fileperms($path);
            $want   = $isDir ? 0755 : 0644;
            if ($before !== false && ($before & 0777) === $want) return;
            if (mediaChmodPath($path)) $out['changed']++; else $out['failed']++;
        };
        $fix($root);
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME),
                    static function ($current, $key, $iterator): bool {
                        $name = basename((string)$current);
                        if (is_link((string)$current)) return false;
                        if ($name === '.spool' && $iterator->hasChildren()) return false;
                        return true;
                    }
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $path) {
                if ($out['scanned'] >= $cap) { $out['capped'] = true; break; }
                $fix((string)$path);
            }
        } catch (Throwable $e) {
            error_log('mediaChmodTree: ' . $e->getMessage());
        }
        return $out;
    }
}

// ---------------------------------------------------------------------
// Diagnostics + repair
// ---------------------------------------------------------------------

if (!function_exists('mediaFormatBytes')) {
    /** '12 KB' / '1.4 MB' (same format as pageFormatBytes(); media-lib.php loads before pages-lib.php). */
    function mediaFormatBytes(int $bytes): string {
        if ($bytes >= 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.') . ' MB';
        if ($bytes >= 1024) return (int)round($bytes / 1024) . ' KB';
        return $bytes . ' B';
    }
}

if (!function_exists('mediaHtmlWarnBytes')) {
    /**
     * An HTML file above this size gets the "hosts often reject HTML responses over ~512 KB" warning.
     * cPanel's ModSecurity ships with SecResponseBodyLimit 524288 and SecResponseBodyLimitAction Reject:
     * a text/html response over the limit is answered with a 500 (images / video are not body-scanned).
     * 400 KB leaves headroom for gzip / chunked framing differences between hosts.
     */
    function mediaHtmlWarnBytes(): int {
        return 400 * 1024;
    }
}

if (!function_exists('mediaHtmlTooLargeProblem')) {
    /** The one warning line for an oversized HTML file (mediaServerCheck() and the Studio / sheet markup share it). */
    function mediaHtmlTooLargeProblem(string $name, int $bytes): string {
        return $name . ' is ' . mediaFormatBytes($bytes) . ' — hosts often reject HTML responses over ~512 KB (ModSecurity). Extract embedded images or reduce the file.';
    }
}

if (!function_exists('mediaServerCheck')) {
    /**
     * Would Apache serve this file? No HTTP — just the filesystem facts an admin needs:
     * the file (exists, readable, bits), every folder from its parent up to $stopDir (bits),
     * the module's .htaccess ($rulesDir/.htaccess: our version, foreign, or missing) and a
     * leftover media/.htaccess of ours at the parent. Returns
     *   ['ok' => bool, 'summary' => 'index.html readable (0644) · folder 0755 · rules v2',
     *    'problems' => [..strings..], 'file' => [name, exists, readable, perms],
     *    'dirs' => [[path (relative to the docroot), perms, ok], …],
     *    'rules' => [file, version, state: ok | old | missing | foreign],
     *    'parent' => [file, state: absent | ours | foreign],
     *    'html'  => [bytes, large: bool]   — an .html / .htm over mediaHtmlWarnBytes() is a problem too
     *                                        (ModSecurity response-body limit → 500; see mediaHtmlWarnBytes)]
     */
    function mediaServerCheck(string $file, ?string $stopDir = null, ?string $rulesDir = null): array {
        $file     = mediaNormPath($file);
        $stopDir  = mediaNormPath(rtrim($stopDir ?? mediaRootPath(), '/'));
        $rulesDir = mediaNormPath(rtrim($rulesDir ?? dirname($file), '/'));
        $docroot  = dirname($stopDir);
        $rel = static function (string $p) use ($docroot): string {
            return strpos($p . '/', $docroot . '/') === 0 ? ltrim(substr($p, strlen($docroot)), '/') : basename($p);
        };
        $name = basename($file);
        $problems = [];

        // the file
        $exists   = !is_link($file) && is_file($file);
        $perms    = $exists ? mediaPermsOctal($file) : '';
        $readable = $exists && is_readable($file) && mediaPermsServable($file);
        if (!$exists) {
            $problems[] = $name . ' is missing on the server';
        } elseif (!$readable) {
            $problems[] = $name . ' is not readable by the web server (' . ($perms !== '' ? $perms : '?') . ', needs 0644)';
        }

        // HTML size: hosts with ModSecurity response-body inspection answer 500 for a text/html
        // file over their limit (cPanel default 512 KB); images / video are not inspected.
        $htmlBytes = 0; $htmlLarge = false;
        if ($exists && in_array(strtolower((string)pathinfo($file, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
            $htmlBytes = (int)@filesize($file);
            if ($htmlBytes > mediaHtmlWarnBytes()) { $htmlLarge = true; $problems[] = mediaHtmlTooLargeProblem($name, $htmlBytes); }
        }

        // folders: parent → … → stopDir
        $dirs = [];
        $folderPerms = '';
        for ($d = dirname($file), $n = 0; $n < 12; $d = dirname($d), $n++) {
            $isDir = !is_link($d) && is_dir($d);
            $p = $isDir ? mediaPermsOctal($d) : '';
            $ok = $isDir && mediaPermsServable($d);
            $dirs[] = ['path' => $rel($d), 'perms' => $p, 'ok' => $ok];
            if ($n === 0) $folderPerms = $p;
            if (!$isDir) {
                // the file's own folder missing is already reported through the file; deeper gaps are named
                if ($n > 0 || $exists) $problems[] = 'folder ' . $rel($d) . ' is missing';
            } elseif (!$ok) {
                $problems[] = 'folder ' . $rel($d) . ' is ' . $p . ' (needs 0755)';
            }
            if ($d === $stopDir || dirname($d) === $d) break;
        }

        // rules
        $rulesFile = $rulesDir . '/.htaccess';
        $rv = mediaHtaccessFileVersion($rulesFile);
        if (!is_file($rulesFile)) { $rulesState = 'missing'; $problems[] = 'rules missing in ' . $rel($rulesDir) . '/ (written by the next upload or Repair)'; }
        elseif ($rv === 0) { $rulesState = 'foreign'; $problems[] = $rel($rulesFile) . ' is not the portal\'s (server-managed) — check it if the page answers 500'; }
        elseif ($rv < mediaHtaccessVersion()) { $rulesState = 'old'; $problems[] = 'rules v' . $rv . ' (old) in ' . $rel($rulesFile) . ' — Repair rewrites them'; }
        else { $rulesState = 'ok'; }

        // the parent media/.htaccess
        $parentFile = $stopDir . '/.htaccess';
        if (!is_file($parentFile)) $parentState = 'absent';
        elseif (mediaHtaccessFileVersion($parentFile) > 0) { $parentState = 'ours'; $problems[] = 'old ' . $rel($parentFile) . ' still present — Repair removes it'; }
        else { $parentState = 'foreign'; }

        $ok = !$problems;
        $summary = $ok
            ? $name . ' readable (' . $perms . ') · folder ' . $folderPerms . ' · rules v' . $rv
            : implode(' · ', $problems);
        return [
            'ok' => $ok, 'summary' => $summary, 'problems' => $problems,
            'file'   => ['name' => $name, 'exists' => $exists, 'readable' => $readable, 'perms' => $perms],
            'dirs'   => $dirs,
            'rules'  => ['file' => $rel($rulesFile), 'version' => $rv, 'state' => $rulesState],
            'parent' => ['file' => $rel($parentFile), 'state' => $parentState],
            'html'   => ['bytes' => $htmlBytes, 'large' => $htmlLarge],
        ];
    }
}

if (!function_exists('mediaRepair')) {
    /**
     * The one-click repair behind action=repair_media: (re)write $rulesDir/.htaccess when missing
     * or older than ours, remove a media/.htaccess of ours at the parent, chmod the tree under
     * $chmodRoot (files 0644 / folders 0755, capped). Returns the pieces plus a one-line summary.
     */
    function mediaRepair(string $rulesDir, string $writer, ?string $chmodRoot = null, int $cap = 5000, ?string $mediaRoot = null): array {
        $mediaRoot = mediaNormPath(rtrim($mediaRoot ?? mediaRootPath(), '/'));
        $rulesDir  = mediaNormPath($rulesDir);
        if ($chmodRoot !== null) $chmodRoot = mediaNormPath($chmodRoot);
        $rules  = mediaEnsureHtaccess($rulesDir, $writer);
        $parent = mediaRemoveParentHtaccess($mediaRoot);
        $perms  = $chmodRoot !== null ? mediaChmodTree($chmodRoot, $cap) : null;
        $docroot = dirname($mediaRoot);
        $rel = static function (string $p) use ($docroot): string {
            return strpos($p . '/', $docroot . '/') === 0 ? ltrim(substr($p, strlen($docroot)), '/') : $p;
        };
        $bits = [];
        switch ($rules['action']) {
            case 'written': $bits[] = 'rules v' . $rules['version'] . ' written to ' . $rel($rules['file']); break;
            case 'updated': $bits[] = 'rules updated v' . $rules['from'] . ' → v' . $rules['version'] . ' in ' . $rel($rules['file']); break;
            case 'kept':    $bits[] = 'rules already v' . $rules['version']; break;
            case 'foreign': $bits[] = $rel($rules['file']) . ' is not the portal\'s — left alone'; break;
            case 'failed':  $bits[] = 'could not write ' . $rel($rules['file']) . ' (folder not writable)'; break;
            default:        $bits[] = $rel($rulesDir) . '/ does not exist yet'; break;
        }
        if ($parent === 'removed') $bits[] = 'old media/.htaccess removed';
        elseif ($parent === 'failed') $bits[] = 'could not remove media/.htaccess';
        elseif ($parent === 'foreign') $bits[] = 'media/.htaccess is not the portal\'s — left alone';
        if ($perms !== null) {
            $bits[] = $perms['changed'] . ' permission' . ($perms['changed'] === 1 ? '' : 's') . ' fixed of ' . $perms['scanned'] . ' checked'
                    . ($perms['failed'] ? ' (' . $perms['failed'] . ' could not be changed)' : '')
                    . ($perms['capped'] ? ' — capped at ' . $cap . ', run Repair again' : '');
        }
        return [
            'ok'      => !in_array($rules['action'], ['failed'], true),
            'rules'   => $rules + ['file_rel' => $rel($rules['file'])],
            'parent'  => $parent,
            'perms'   => $perms,
            'summary' => implode(' · ', $bits),
        ];
    }
}
