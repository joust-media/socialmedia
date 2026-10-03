<?php
/**
 * Image previews — two derived copies next to every image original, originals never modified:
 *
 *   <dir>/.thumbs/<stem>.sm.<fmt>    fit within 480 px (long edge)  — tiles, lists, cards, strips
 *   <dir>/.thumbs/<stem>.lg.<fmt>    fit within 1600 px              — viewer, detail, carousel
 *   <dir>/.thumbs/<stem>.dims.json   the original's oriented width / height (+ mtime) for width/height attributes
 *   <dir>/.thumbs/.htaccess          1-year immutable caching (media-lib.php mediaThumbsHtaccessText())
 *
 * <fmt> is webp when GD can encode WebP, else jpg (previewFormat()). Aspect kept, never upscaled: an
 * original whose long edge already fits the target gets no derivative and is used as-is for that size —
 * except `lg` of an original over previewHeavyBytes() (500 KB), re-encoded at its own size (previewTargetDims).
 * EXIF orientation is applied (JPEG), alpha kept for webp / flattened onto white for jpg, GIF = first frame.
 * SVG and videos never get derivatives. The old 640 px tire thumbs (<dir>/.thumbs/<stem>.jpg) are still
 * read as the `sm` fallback until regenerated.
 *
 * Render sites call previewUrl() / previewImgAttrs() with the URL they already print; the result is the
 * static derivative (?v=<mtime of the original>) when it is fresh, else the lazy endpoint preview.php
 * (HMAC-signed path; makes every stale size in one decode, then 302s to that same static URL; a placeholder
 * while the host's generator slots are busy or when it cannot — never the original).
 * Host protection: at most previewSlotCount() (2) decodes at a time across the host (flock slots in
 * uploads/.locks), the admin session released first (previewReleaseSession), X-Preview-Gd counts decodes.
 * Uploads: the browser sends its own sm / lg WebP (previewClientTicket / previewClientAccept) so the server
 * decodes nothing; otherwise stores call previewAfterStore(). Deletes call previewDelete(); a parked file's
 * previews follow it (previewMoveDerivatives); Manage → Tools → Image previews backfills (preview-job.php).
 * README "Image previews".
 *
 * Loaded from helpers.php (end of the chain), tire-series-lib.php and preview.php. Every function is
 * function_exists-guarded; no DB, no output, no work at load.
 */

require_once __DIR__ . '/media-lib.php';
require_once __DIR__ . '/tire-series-lib.php';

// ---------------------------------------------------------------------
// Sizes, format, secret
// ---------------------------------------------------------------------

if (!function_exists('previewSizes')) {
    /** Size key → long-edge pixels. */
    function previewSizes(): array {
        return ['sm' => 480, 'lg' => 1600];
    }
}

if (!function_exists('previewImageExts')) {
    /** Extensions that get derivatives (SVG / video never do). */
    function previewImageExts(): array {
        return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    }
}

if (!function_exists('previewFormat')) {
    /** 'webp' when GD encodes WebP, else 'jpg'. Cached per request; env PREVIEW_FORCE_FORMAT=jpg|webp overrides (harness). */
    function previewFormat(): string {
        static $fmt = null;
        if ($fmt !== null) return $fmt;
        $force = strtolower((string)getenv('PREVIEW_FORCE_FORMAT'));
        if ($force === 'jpg' || $force === 'webp') return $fmt = $force;
        $webp = false;
        if (function_exists('imagewebp') && function_exists('gd_info')) {
            $info = @gd_info();
            $webp = is_array($info) && !empty($info['WebP Support']);
        }
        return $fmt = $webp ? 'webp' : 'jpg';
    }
}

if (!function_exists('previewQuality')) {
    function previewQuality(string $fmt): int {
        return $fmt === 'webp' ? 78 : 80;
    }
}

if (!function_exists('previewMime')) {
    function previewMime(string $path): string {
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'webp': return 'image/webp';
            case 'png':  return 'image/png';
            case 'gif':  return 'image/gif';
            case 'svg':  return 'image/svg+xml';
            default:     return 'image/jpeg';
        }
    }
}

if (!function_exists('previewSecret')) {
    /**
     * HMAC key for lazy-endpoint tokens: config.php 'preview_secret' when set, else derived from the DB
     * password + name (never sent anywhere), else (no config) a per-install constant from the app path.
     */
    function previewSecret(): string {
        static $secret = null;
        if ($secret !== null) return $secret;
        $cfg = [];
        $file = __DIR__ . '/config.php';
        if (is_file($file)) {
            try { $c = (static function (string $f) { return include $f; })($file); if (is_array($c)) $cfg = $c; } catch (Throwable $e) { $cfg = []; }
        }
        $own = trim((string)($cfg['preview_secret'] ?? ''));
        if ($own !== '') return $secret = hash('sha256', 'joust-preview|' . $own);
        if ($cfg) return $secret = hash('sha256', 'joust-preview|' . (string)($cfg['password'] ?? '') . '|' . (string)($cfg['dbname'] ?? ''));
        return $secret = hash('sha256', 'joust-preview|' . __DIR__);
    }
}

if (!function_exists('previewB64')) {
    function previewB64(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
}

if (!function_exists('previewToken')) {
    /** base64url(ref) . '.' . 22 chars of base64url(HMAC-SHA256(ref)). */
    function previewToken(string $ref): string {
        return previewB64($ref) . '.' . substr(previewB64(hash_hmac('sha256', $ref, previewSecret(), true)), 0, 22);
    }
}

if (!function_exists('previewTokenRef')) {
    /** The signed ref of a token; null when malformed ('bad') or the signature does not match ('forged') — see $why. */
    function previewTokenRef(string $token, ?string &$why = null): ?string {
        $why = 'bad';
        if ($token === '' || strlen($token) > 2048 || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{22})$/', $token, $m)) return null;
        $ref = base64_decode(strtr($m[1], '-_', '+/'), true);
        if (!is_string($ref) || $ref === '') return null;
        $want = substr(previewB64(hash_hmac('sha256', $ref, previewSecret(), true)), 0, 22);
        if (!hash_equals($want, $m[2])) { $why = 'forged'; return null; }
        $why = '';
        return $ref;
    }
}

// ---------------------------------------------------------------------
// URL → canonical ref → absolute path (containment through the existing helpers)
// ---------------------------------------------------------------------

if (!function_exists('previewCanonicalRef')) {
    /**
     * 'uploads/<f>' | 'media/tires/<a>/<b>/<f>' | 'media/library/<slug>/<f>' | 'media/pages/<c>/<p>/<rel>' for a
     * portal media URL in any of the printed forms (root-rooted, basePath-prefixed, app-relative, rawurlencoded,
     * with ?query / #fragment); null for anything else (external URLs, traversal, dot segments, NUL).
     */
    function previewCanonicalRef(string $url): ?string {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) return null;
        if (preg_match('#^([a-z][a-z0-9+.\-]*:|//)#i', $url)) return null;   // scheme or host: never ours
        $url = preg_replace('/[?#].*$/s', '', $url);
        $segs = [];
        foreach (explode('/', ltrim((string)$url, '/')) as $raw) {
            $s = rawurldecode($raw);
            if ($s === '' || $s === '.' || $s === '..' || $s[0] === '.') return null;
            if (strpos($s, '/') !== false || strpos($s, '\\') !== false || strpos($s, "\0") !== false) return null;
            $segs[] = $s;
        }
        $n = count($segs);
        if ($n < 2) return null;
        if ($segs[0] === 'media') {
            if ($n === 5 && $segs[1] === 'tires') return implode('/', $segs);
            if ($n === 4 && $segs[1] === 'library' && preg_match('/^[a-z0-9\-]+$/', $segs[2])) return implode('/', $segs);
            if ($n >= 5 && $n <= 9 && $segs[1] === 'pages') return implode('/', $segs);
            return null;
        }
        // uploads/<file> directly, or '<base…>/uploads/<file>' (basePath-prefixed as printed by pages).
        if ($segs[$n - 2] === 'uploads') {
            if ($n > 2 && function_exists('basePath')) {
                $prefix = '/' . implode('/', array_slice($segs, 0, $n - 2));
                if ($prefix !== basePath()) return null;
            }
            return 'uploads/' . $segs[$n - 1];
        }
        return null;
    }
}

if (!function_exists('previewLibraryPath')) {
    /** media/library/<slug>/<file> on disk, realpath-contained in that brand folder; null otherwise. */
    function previewLibraryPath(string $slug, string $file): ?string {
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) return null;
        if ($file === '' || $file[0] === '.' || strpbrk($file, "/\\\0") !== false) return null;
        $dir = function_exists('libraryDir') ? libraryDir($slug) : mediaRootPath() . '/library/' . $slug;
        $path = $dir . '/' . $file;
        if (is_link($path) || !is_file($path)) return null;
        $real = realpath($path); $dirReal = realpath($dir);
        if ($real === false || $dirReal === false) return $path;
        return dirname($real) === rtrim($dirReal, '/') ? $real : null;
    }
}

if (!function_exists('previewRefPath')) {
    /** Absolute path of a canonical ref through the module's containment helper; null when missing / escaping. */
    function previewRefPath(string $ref): ?string {
        $segs = explode('/', $ref);
        if ($segs[0] === 'uploads' && count($segs) === 2) {
            // uploadsPathOrNull() (realpath containment); tireImagePath() applies the same textual + realpath rules
            // but also answers where realpath() cannot resolve (stream-wrapped hosts / harnesses) — as the render side does.
            $p = function_exists('uploadsPathOrNull') ? uploadsPathOrNull($ref) : null;
            return $p ?? (function_exists('tireImagePath') ? tireImagePath($ref) : null);
        }
        if (($segs[0] ?? '') !== 'media' || count($segs) < 4) return null;
        if ($segs[1] === 'tires') return function_exists('tireImagePath') ? tireImagePath($ref) : null;
        if ($segs[1] === 'library' && count($segs) === 4) return previewLibraryPath($segs[2], $segs[3]);
        if ($segs[1] === 'pages' && count($segs) >= 5 && function_exists('pageFilePath')) {
            $co = $segs[2]; $pg = $segs[3];
            if (!preg_match('/^[a-z0-9\-]+$/', $co) || !preg_match('/^[a-z0-9\-]+$/', $pg)) return null;
            return pageFilePath(['slug' => $co], ['slug' => $pg, 'company_slug' => $co], implode('/', array_slice($segs, 4)), true);
        }
        return null;
    }
}

if (!function_exists('previewResolveOriginal')) {
    /** Absolute path of the original behind a portal media URL; null for anything the containment helpers refuse. */
    function previewResolveOriginal(string $url): ?string {
        $ref = previewCanonicalRef($url);
        return $ref === null ? null : previewRefPath($ref);
    }
}

// ---------------------------------------------------------------------
// Paths, dimensions, freshness
// ---------------------------------------------------------------------

if (!function_exists('previewIsImage')) {
    function previewIsImage(string $abs): bool {
        return in_array(strtolower((string)pathinfo($abs, PATHINFO_EXTENSION)), previewImageExts(), true);
    }
}

if (!function_exists('previewThumbsDir')) {
    function previewThumbsDir(string $absOriginal): string {
        return dirname($absOriginal) . '/.thumbs';
    }
}

if (!function_exists('previewPathFor')) {
    /** <dir>/.thumbs/<stem>.<size>.<fmt> — where the derivative lives / would live (no existence check). */
    function previewPathFor(string $absOriginal, string $size): string {
        return previewThumbsDir($absOriginal) . '/' . pathinfo($absOriginal, PATHINFO_FILENAME) . '.' . $size . '.' . previewFormat();
    }
}

if (!function_exists('previewLegacyThumbPath')) {
    /** The old 640 px tire thumb (<dir>/.thumbs/<stem>.jpg). */
    function previewLegacyThumbPath(string $absOriginal): string {
        return previewThumbsDir($absOriginal) . '/' . pathinfo($absOriginal, PATHINFO_FILENAME) . '.jpg';
    }
}

if (!function_exists('previewMtime')) {
    function previewMtime(string $path): int {
        $m = @filemtime($path);
        return $m === false ? 0 : (int)$m;
    }
}

if (!function_exists('previewExifOrientation')) {
    /** EXIF Orientation (1–8) of a JPEG; 1 when unknown / not a JPEG / exif missing. */
    function previewExifOrientation(string $abs): int {
        if (!in_array(strtolower((string)pathinfo($abs, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) return 1;
        if (!function_exists('exif_read_data')) return 1;
        $exif = @exif_read_data($abs, 'IFD0');
        $o = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
        return ($o >= 1 && $o <= 8) ? $o : 1;
    }
}

if (!function_exists('previewDims')) {
    /**
     * The original's DISPLAYED size ['w' => …, 'h' => …] (EXIF 5–8 swap the sides): from <stem>.dims.json when it
     * matches the original's mtime, else getimagesize(). Null for non-images / undecodable files. Cached per request.
     */
    function previewDims(string $abs): ?array {
        static $cache = [];
        if (!previewIsImage($abs) || !is_file($abs)) return null;
        $m = previewMtime($abs);
        $key = $abs . '|' . $m;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $side = previewThumbsDir($abs) . '/' . pathinfo($abs, PATHINFO_FILENAME) . '.dims.json';
        if (is_file($side)) {
            $d = json_decode((string)@file_get_contents($side), true);
            if (is_array($d) && (int)($d['m'] ?? -1) === $m && (int)($d['w'] ?? 0) > 0 && (int)($d['h'] ?? 0) > 0
                && (string)($d['src'] ?? '') === basename($abs)) {
                return $cache[$key] = ['w' => (int)$d['w'], 'h' => (int)$d['h']];
            }
        }
        $info = @getimagesize($abs);
        if (!is_array($info) || (int)$info[0] <= 0 || (int)$info[1] <= 0) return $cache[$key] = null;
        $w = (int)$info[0]; $h = (int)$info[1];
        if (previewExifOrientation($abs) >= 5) { [$w, $h] = [$h, $w]; }
        if (count($cache) > 2000) $cache = [];
        return $cache[$key] = ['w' => $w, 'h' => $h];
    }
}

if (!function_exists('previewWriteDims')) {
    /** (internal) <stem>.dims.json next to the derivatives (atomic, best effort). */
    function previewWriteDims(string $abs, int $w, int $h): void {
        $dir = previewThumbsDir($abs);
        if (!is_dir($dir)) return;
        $file = $dir . '/' . pathinfo($abs, PATHINFO_FILENAME) . '.dims.json';
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['w' => $w, 'h' => $h, 'm' => previewMtime($abs), 'src' => basename($abs)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) { @unlink($tmp); return; }
        if (!@rename($tmp, $file)) { @unlink($tmp); return; }
        mediaChmodPath($file);
    }
}

if (!function_exists('previewScaleDims')) {
    /** [w, h] fitted within $max on the long edge; null when the original already fits (never upscale). */
    function previewScaleDims(int $w, int $h, int $max): ?array {
        if ($w <= 0 || $h <= 0 || max($w, $h) <= $max) return null;
        if ($w >= $h) return [$max, max(1, (int)round($h * $max / $w))];
        return [max(1, (int)round($w * $max / $h)), $max];
    }
}

if (!function_exists('previewHeavyBytes')) {
    /** An original at or under 1600 px that is bigger than this still gets an `lg` (same size, re-encoded). */
    function previewHeavyBytes(): int { return 500 * 1024; }
}

if (!function_exists('previewTargetDims')) {
    /**
     * [w, h] of the derivative this size needs, or null when none is needed: fitted within the size's long edge
     * (never upscaled); for `lg` also the original's own size when it already fits but weighs more than
     * previewHeavyBytes() (a 1080×1350 q95 JPEG, a 1500 px PNG) — GIFs excepted (the derivative is one frame).
     * $d = previewDims($abs) when the caller has it.
     */
    function previewTargetDims(string $abs, string $size, ?array $d = null): ?array {
        $sizes = previewSizes();
        if (!isset($sizes[$size])) return null;
        $d = $d ?? previewDims($abs);
        if ($d === null) return null;
        $t = previewScaleDims($d['w'], $d['h'], $sizes[$size]);
        if ($t !== null) return $t;
        if ($size === 'lg' && strtolower((string)pathinfo($abs, PATHINFO_EXTENSION)) !== 'gif' && (int)@filesize($abs) > previewHeavyBytes()) {
            return [$d['w'], $d['h']];
        }
        return null;
    }
}

if (!function_exists('previewNeeds')) {
    /** Does this size need a derivative at all? (image, decodable, larger than the target — or heavy, for lg) */
    function previewNeeds(string $abs, string $size): bool {
        $sizes = previewSizes();
        if (!isset($sizes[$size])) return false;
        $d = previewDims($abs);
        return $d !== null && previewTargetDims($abs, $size, $d) !== null;
    }
}

if (!function_exists('previewIsFresh')) {
    /** True when nothing has to be generated for this size (derivative newer than the original, or none needed). */
    function previewIsFresh(string $abs, string $size): bool {
        if (!previewNeeds($abs, $size)) return true;
        $p = previewPathFor($abs, $size);
        return is_file($p) && previewMtime($p) >= previewMtime($abs);
    }
}

// ---------------------------------------------------------------------
// URLs + attributes (render sites)
// ---------------------------------------------------------------------

if (!function_exists('previewSiblingUrl')) {
    /** '<dir of $originalUrl>/.thumbs/<name>?v=<mtime>' — keeps whatever relativity the original URL had. */
    function previewSiblingUrl(string $originalUrl, string $name, int $v): string {
        $u = (string)preg_replace('/[?#].*$/s', '', $originalUrl);
        $slash = strrpos($u, '/');
        $dir = $slash === false ? '' : substr($u, 0, $slash + 1);
        return $dir . '.thumbs/' . rawurlencode($name) . '?v=' . $v;
    }
}

if (!function_exists('previewEndpointUrl')) {
    /** <basePath>/preview.php?f=<token>&s=<size>&v=<mtime>. */
    function previewEndpointUrl(string $ref, string $size, int $v): string {
        $base = function_exists('basePath') ? basePath() : '';
        return $base . '/preview.php?f=' . previewToken($ref) . '&s=' . rawurlencode($size) . '&v=' . $v;
    }
}

if (!function_exists('previewRefUrl')) {
    /**
     * The public URL of a canonical ref, in the form the render sites print it: basePath-rooted for uploads/,
     * root-relative for media/ (segments rawurlencoded). Works without helpers.php (preview.php).
     */
    function previewRefUrl(string $ref): string {
        $segs = array_map('rawurlencode', explode('/', $ref));
        if ($segs[0] === 'uploads') {
            if (function_exists('basePath')) {
                $base = basePath();
            } else {
                $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/preview.php'))), '/');
                if ($base === '.') $base = '';
            }
            return $base . '/' . implode('/', $segs);
        }
        return '/' . implode('/', $segs);
    }
}

if (!function_exists('previewStaticUrl')) {
    /** '<dir>/.thumbs/<stem>.<size>.<fmt>?v=<mtime of the original>' for a ref — the URL pages print once the file exists. */
    function previewStaticUrl(string $ref, string $absOriginal, string $size): string {
        return previewSiblingUrl(previewRefUrl($ref), basename(previewPathFor($absOriginal, $size)), previewMtime($absOriginal));
    }
}

if (!function_exists('previewRefFromPath')) {
    /** Canonical ref of an absolute path under uploads/ or media/ (for callers without a URL); null otherwise. */
    function previewRefFromPath(string $abs): ?string {
        $real = realpath($abs);
        if ($real === false) return null;
        $up = realpath(__DIR__ . '/uploads');
        if ($up !== false && dirname($real) === rtrim($up, '/')) return 'uploads/' . basename($real);
        $media = realpath(mediaRootPath());
        if ($media !== false && strpos($real, rtrim($media, '/') . '/') === 0) {
            $ref = 'media/' . substr($real, strlen(rtrim($media, '/')) + 1);
            return previewCanonicalRef($ref) === $ref ? $ref : null;
        }
        return null;
    }
}

if (!function_exists('previewVariant')) {
    /**
     * The variant a render site shows for $size: ['url', 'w', 'h', 'kind' => static|legacy|lazy|original].
     * 'original' = no derivative applies (not an image, undecodable, or already within the target) → $originalUrl.
     */
    function previewVariant(string $originalUrl, string $absOriginal, string $size): array {
        $sizes = previewSizes();
        $orig  = ['url' => $originalUrl, 'w' => null, 'h' => null, 'kind' => 'original'];
        if (!isset($sizes[$size]) || $absOriginal === '' || !previewIsImage($absOriginal)) return $orig;
        $d = previewDims($absOriginal);
        if ($d === null) return $orig;
        $orig['w'] = $d['w']; $orig['h'] = $d['h'];
        $t = previewTargetDims($absOriginal, $size, $d);
        if ($t === null) return $orig;
        $m = previewMtime($absOriginal);
        $p = previewPathFor($absOriginal, $size);
        if (is_file($p) && previewMtime($p) >= $m) {
            return ['url' => previewSiblingUrl($originalUrl, basename($p), $m), 'w' => $t[0], 'h' => $t[1], 'kind' => 'static'];
        }
        if ($size === 'sm') {
            $legacy = previewLegacyThumbPath($absOriginal);
            if (is_file($legacy) && previewMtime($legacy) >= $m) {
                $lt = previewScaleDims($d['w'], $d['h'], 640) ?? [$d['w'], $d['h']];
                return ['url' => previewSiblingUrl($originalUrl, basename($legacy), $m), 'w' => $lt[0], 'h' => $lt[1], 'kind' => 'legacy'];
            }
        }
        $ref = previewCanonicalRef($originalUrl) ?? previewRefFromPath($absOriginal);
        if ($ref === null) return $orig;
        return ['url' => previewEndpointUrl($ref, $size, $m), 'w' => $t[0], 'h' => $t[1], 'kind' => 'lazy'];
    }
}

if (!function_exists('previewUrlFor')) {
    /** Static derivative URL (?v=mtime) when fresh, else the lazy endpoint, else $originalUrl (see previewVariant). */
    function previewUrlFor(string $originalUrl, string $absOriginal, string $size): string {
        return previewVariant($originalUrl, $absOriginal, $size)['url'];
    }
}

if (!function_exists('previewUrl')) {
    /** previewUrlFor() for a URL alone (resolved through previewResolveOriginal); $url unchanged when it does not resolve. */
    function previewUrl(string $url, string $size = 'sm'): string {
        $abs = previewResolveOriginal($url);
        return $abs === null ? $url : previewUrlFor($url, $abs, $size);
    }
}

if (!function_exists('previewAttrs')) {
    /**
     * Ready-to-print, HTML-escaped attributes with a leading space: src, srcset + sizes (only when $opts['sizes']
     * is given and srcset !== false), width/height (dims !== false), loading (lazy | eager + fetchpriority=high),
     * decoding="async". No alt.
     */
    function previewAttrs(string $originalUrl, string $absOriginal, string $size, array $opts = []): string {
        $e = static function ($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $v = previewVariant($originalUrl, $absOriginal, $size);
        $out = ' src="' . $e($v['url']) . '"';
        $sizesAttr = trim((string)($opts['sizes'] ?? ''));
        if ($sizesAttr !== '' && ($opts['srcset'] ?? true) !== false && $v['w'] !== null) {
            $set = [];
            foreach (array_keys(previewSizes()) as $k) {
                $x = $k === $size ? $v : previewVariant($originalUrl, $absOriginal, $k);
                if ($x['w'] === null) continue;
                $set[$x['url']] = (int)$x['w'];
            }
            if (count($set) >= 2) {
                asort($set);
                $parts = [];
                foreach ($set as $u => $w) $parts[] = $u . ' ' . $w . 'w';
                $out .= ' srcset="' . $e(implode(', ', $parts)) . '" sizes="' . $e($sizesAttr) . '"';
            }
        }
        if (($opts['dims'] ?? true) !== false && $v['w'] !== null && $v['h'] !== null) {
            $out .= ' width="' . (int)$v['w'] . '" height="' . (int)$v['h'] . '"';
        }
        $out .= !empty($opts['eager']) ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"';
        $out .= ' decoding="async"';
        return $out;
    }
}

if (!function_exists('previewImgAttrs')) {
    /** previewAttrs() for a URL alone; an unresolvable URL still gets src + loading + decoding (never breaks). */
    function previewImgAttrs(string $url, string $size = 'sm', array $opts = []): string {
        $abs = previewResolveOriginal($url);
        return previewAttrs($url, $abs ?? '', $size, $opts);
    }
}

// ---------------------------------------------------------------------
// Generation
// ---------------------------------------------------------------------

if (!function_exists('previewBytes')) {
    /** '512M' → bytes; -1 for unlimited / unset. */
    function previewBytes(string $v): int {
        $v = trim($v);
        if ($v === '' || $v === '-1') return -1;
        $n = (int)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;   // fall through
            case 'm': $n *= 1024;   // fall through
            case 'k': $n *= 1024;
        }
        return $n;
    }
}

if (!function_exists('previewMemoryOk')) {
    /**
     * Will decoding w×h fit? Need w×h×5 + 32 MB of headroom. memory_limit is raised to 512M first when it is
     * lower and ini_set() allows it (a host that refuses keeps its limit — the guard then decides).
     */
    function previewMemoryOk(int $w, int $h): bool {
        $need  = $w * $h * 5 + 32 * 1024 * 1024;
        $limit = previewBytes((string)ini_get('memory_limit'));
        if ($limit === -1) return true;
        if ($limit - memory_get_usage() > $need) return true;
        if ($limit < 512 * 1024 * 1024 && function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
            $limit = previewBytes((string)ini_get('memory_limit'));
            if ($limit === -1) return true;
        }
        return $limit - memory_get_usage() > $need;
    }
}

if (!function_exists('previewEnsureThumbsHtaccess')) {
    /** <dir>/.thumbs/.htaccess = the 1-year cache text (media-lib.php); once per folder per request. */
    function previewEnsureThumbsHtaccess(string $thumbsDir): void {
        static $done = [];
        if (isset($done[$thumbsDir])) return;
        $done[$thumbsDir] = true;
        if (function_exists('mediaThumbsHtaccessText')) mediaEnsureHtaccess($thumbsDir, 'preview-lib.php', mediaThumbsHtaccessText('preview-lib.php'));
    }
}

if (!function_exists('previewUploadsDir')) {
    function previewUploadsDir(): string { return __DIR__ . '/uploads'; }
}

if (!function_exists('previewEnsureUploadsHtaccess')) {
    /** uploads/.htaccess = the shared media text (static files only + caching); once per request. Returns mediaEnsureHtaccess()'s reply. */
    function previewEnsureUploadsHtaccess(): array {
        static $r = null;
        if ($r !== null) return $r;
        return $r = mediaEnsureHtaccess(previewUploadsDir(), 'preview-lib.php');
    }
}

// ---------------------------------------------------------------------
// Host protection: a global cap on simultaneous decodes, the session released before GD work,
// a per-request count of decodes (X-Preview-Gd), failure markers
// ---------------------------------------------------------------------

if (!function_exists('previewSlotCount')) {
    /** How many previews may be generated at the same time on this host (env PREVIEW_SLOTS=1..16, default 2). */
    function previewSlotCount(): int {
        $n = (int)getenv('PREVIEW_SLOTS');
        return ($n >= 1 && $n <= 16) ? $n : 2;
    }
}

if (!function_exists('previewSlotDir')) {
    /** uploads/.locks (0755, deny-all .htaccess) — slot-<i>.lock files for the flock() semaphore; null when unusable. */
    function previewSlotDir(): ?string {
        static $dir = false;
        if ($dir !== false) return $dir;
        $up = previewUploadsDir();
        if (!is_dir($up)) { function_exists('mediaMkdir') ? mediaMkdir($up) : @mkdir($up, 0755, true); }
        $d = $up . '/.locks';
        if (!is_dir($d) && !@mkdir($d, 0755) && !is_dir($d)) return $dir = null;
        if (is_link($d) || !is_writable($d)) return $dir = null;
        $ht = $d . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# Written by the portal (preview-lib.php): lock files of the image-preview generator. Never served.\n"
                . "Options -Indexes\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
            @chmod($ht, 0644);
        }
        return $dir = $d;
    }
}

if (!function_exists('previewSlotAcquire')) {
    /**
     * Take one of the previewSlotCount() generator slots (flock LOCK_NB on uploads/.locks/slot-<i>.lock), trying
     * again every 100 ms for up to $wait seconds. Returns the open handle (release with previewSlotRelease()),
     * true when no lock folder can be used (never block generation on a broken host), or false when every slot
     * stayed busy — the caller then serves a placeholder / leaves the work for later.
     */
    function previewSlotAcquire(float $wait = 0.0) {
        $dir = previewSlotDir();
        if ($dir === null) return true;
        $n = previewSlotCount();
        $deadline = microtime(true) + max(0.0, $wait);
        $start = mt_rand(0, $n - 1);
        do {
            for ($k = 0; $k < $n; $k++) {
                $i = ($start + $k) % $n;
                $h = @fopen($dir . '/slot-' . $i . '.lock', 'c');
                if (!$h) return true;
                if (@flock($h, LOCK_EX | LOCK_NB)) return $h;
                @fclose($h);
            }
            if (microtime(true) >= $deadline) break;
            usleep(100000);
        } while (true);
        return false;
    }
}

if (!function_exists('previewSlotRelease')) {
    function previewSlotRelease($h): void {
        if (is_resource($h)) { @flock($h, LOCK_UN); @fclose($h); }
    }
}

if (!function_exists('previewReleaseSession')) {
    /**
     * session_write_close() before slow work (GD decodes, uploads) so the admin's other requests are not queued
     * behind this one on the session lock. $_SESSION stays readable for the rest of the request; auth.php's
     * startAdminSession() does not reopen it (the flag below). Idempotent; no-op without a session.
     */
    function previewReleaseSession(): void {
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            $GLOBALS['__jsmSessionReleased'] = true;
            @session_write_close();
        }
    }
}

if (!function_exists('previewGdRuns')) {
    /** How many originals this request decoded to make previews ($add bumps it). Endpoints echo it as X-Preview-Gd. */
    function previewGdRuns(int $add = 0): int {
        static $n = 0;
        return $n += $add;
    }
}

if (!function_exists('previewGdHeader')) {
    /** Send X-Preview-Gd: <decodes this request> with the response headers (whenever they go out). */
    function previewGdHeader(): void {
        if (headers_sent() || !function_exists('header_register_callback')) return;
        @header_register_callback(static function (): void { header('X-Preview-Gd: ' . previewGdRuns()); });
    }
}

if (!function_exists('previewFailPath')) {
    /** <dir>/.thumbs/<stem>.fail.json — "this original could not be made into previews" (mtime-keyed). */
    function previewFailPath(string $absOriginal): string {
        return previewThumbsDir($absOriginal) . '/' . pathinfo($absOriginal, PATHINFO_FILENAME) . '.fail.json';
    }
}

if (!function_exists('previewFailed')) {
    /** Did a generation of this exact original (same mtime) fail before? (preview.php then answers at once) */
    function previewFailed(string $absOriginal): bool {
        $f = previewFailPath($absOriginal);
        if (!is_file($f)) return false;
        $d = json_decode((string)@file_get_contents($f), true);
        return is_array($d) && (int)($d['m'] ?? -1) === previewMtime($absOriginal);
    }
}

if (!function_exists('previewMarkFailed')) {
    function previewMarkFailed(string $absOriginal, string $why): void {
        $dir = previewThumbsDir($absOriginal);
        if (!is_dir($dir) || is_link($dir)) return;
        @file_put_contents(previewFailPath($absOriginal), json_encode(['m' => previewMtime($absOriginal), 'why' => $why, 'at' => time()]));
    }
}

if (!function_exists('previewOrientGd')) {
    /** (internal) Apply EXIF orientation to a GD image; returns the (possibly new) image. */
    function previewOrientGd($im, int $o) {
        $rot = static function ($img, int $deg) { $r = imagerotate($img, $deg, 0); if ($r) { imagedestroy($img); return $r; } return $img; };
        switch ($o) {
            case 2: imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 3: $im = $rot($im, 180); break;
            case 4: imageflip($im, IMG_FLIP_VERTICAL); break;
            case 5: $im = $rot($im, -90); imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 6: $im = $rot($im, -90); break;
            case 7: $im = $rot($im, 90); imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 8: $im = $rot($im, 90); break;
        }
        return $im;
    }
}

if (!function_exists('previewWriteGd')) {
    /** (internal) Encode a GD image to $dest atomically in $fmt. */
    function previewWriteGd($im, string $dest, string $fmt): bool {
        $tmp = $dest . '.' . getmypid() . '.' . mt_rand() . '.tmp';
        if ($fmt === 'webp') {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $ok = @imagewebp($im, $tmp, previewQuality('webp'));
        } else {
            $w = imagesx($im); $h = imagesy($im);
            $canvas = imagecreatetruecolor($w, $h);
            if (!$canvas) return false;
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, true);
            imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
            imageinterlace($canvas, true);   // progressive JPEG
            $ok = @imagejpeg($canvas, $tmp, previewQuality('jpg'));
            imagedestroy($canvas);
        }
        clearstatcache(true, $tmp);
        if (!$ok || !is_file($tmp) || (int)@filesize($tmp) === 0) { @unlink($tmp); return false; }
        if (!@rename($tmp, $dest)) { @unlink($tmp); return false; }
        mediaChmodPath($dest);
        return true;
    }
}

if (!function_exists('previewGenerateGd')) {
    /**
     * (internal) One decode, every requested size (largest first, each scaled from the previous). size → path|null.
     * $want = size → long edge; a size whose target is the original's own size (heavy `lg`) is re-encoded unscaled.
     */
    function previewGenerateGd(string $abs, array $want, array $dims, ?callable $early = null): array {
        $out = array_fill_keys(array_keys($want), null);
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) return $out;
        $fmt = previewFormat();
        if ($fmt === 'webp' && !function_exists('imagewebp')) return $out;
        $info = @getimagesize($abs);
        if (!is_array($info) || (int)$info[0] <= 0 || (int)$info[1] <= 0) return $out;
        if (!previewMemoryOk((int)$info[0], (int)$info[1])) {
            error_log('preview: ' . basename($abs) . ' (' . $info[0] . 'x' . $info[1] . ') too large for memory_limit ' . ini_get('memory_limit'));
            return $out;
        }
        $data = @file_get_contents($abs);
        if ($data === false || $data === '') return $out;
        $im = @imagecreatefromstring($data);
        unset($data);
        if (!$im) return $out;
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        $im = previewOrientGd($im, previewExifOrientation($abs));
        imagealphablending($im, false);
        imagesavealpha($im, true);
        // $early (preview.php): the small size first, straight from the full decode (area-averaged — the tile can be
        // answered after ~0.3 s), then the rest from the same decode while the browser already shows the tile.
        if ($early !== null && isset($want['sm']) && count($want) > 1) {
            $t = previewTargetDims($abs, 'sm', $dims);
            if ($t !== null && ($sm = imagecreatetruecolor($t[0], $t[1]))) {
                imagealphablending($sm, false);
                imagesavealpha($sm, true);
                imagefill($sm, 0, 0, imagecolorallocatealpha($sm, 0, 0, 0, 127));
                if (imagecopyresampled($sm, $im, 0, 0, 0, 0, $t[0], $t[1], imagesx($im), imagesy($im))) {
                    $dest = previewPathFor($abs, 'sm');
                    if (previewWriteGd($sm, $dest, $fmt)) { $out['sm'] = $dest; $early('sm', $dest); }
                }
                imagedestroy($sm);
            }
            unset($want['sm']);
        }
        $cur = $im;
        arsort($want);   // largest target first
        foreach ($want as $size => $max) {
            $t = previewScaleDims(imagesx($im), imagesy($im), (int)$max);
            if ($t === null) {
                // Already within the target: only a heavy `lg` gets here (previewTargetDims) — re-encode unscaled.
                if (previewTargetDims($abs, (string)$size, $dims) === null) continue;
                $dest = previewPathFor($abs, $size);
                if (previewWriteGd($cur, $dest, $fmt)) $out[$size] = $dest;
                continue;
            }
            if (imagesx($cur) === $t[0] && imagesy($cur) === $t[1]) { $scaled = $cur; }
            else {
                $scaled = imagescale($cur, $t[0], $t[1], IMG_BICUBIC);
                if (!$scaled) continue;
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
            }
            $dest = previewPathFor($abs, $size);
            if (previewWriteGd($scaled, $dest, $fmt)) $out[$size] = $dest;
            if ($scaled !== $cur) {
                if ($cur !== $im) imagedestroy($cur);
                $cur = $scaled;
            }
        }
        if ($cur !== $im) imagedestroy($cur);
        imagedestroy($im);
        return $out;
    }
}

if (!function_exists('previewGenerateImagick')) {
    /**
     * (internal) Imagick path (streams large originals through its own pixel cache). Every written file is
     * verified with getimagesize(); anything unexpected is deleted and left to GD. size → path|null.
     */
    function previewGenerateImagick(string $abs, array $want): array {
        $out = array_fill_keys(array_keys($want), null);
        if (!class_exists('Imagick') || getenv('PREVIEW_NO_IMAGICK') === '1') return $out;
        $fmt = previewFormat();
        try {
            if (!Imagick::queryFormats($fmt === 'webp' ? 'WEBP' : 'JPEG')) return $out;
            $im = new Imagick();
            $im->readImage($abs . '[0]');
            switch ($im->getImageOrientation()) {
                case 2: $im->flopImage(); break;
                case 3: $im->rotateImage('none', 180); break;
                case 4: $im->flipImage(); break;
                case 5: $im->transposeImage(); break;
                case 6: $im->rotateImage('none', 90); break;
                case 7: $im->transverseImage(); break;
                case 8: $im->rotateImage('none', -90); break;
            }
            $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
            if ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);   // browsers expect sRGB
            $w = $im->getImageWidth(); $h = $im->getImageHeight();
            arsort($want);
            foreach ($want as $size => $max) {
                $t = previewScaleDims($w, $h, (int)$max);
                if ($t === null && previewTargetDims($abs, (string)$size) !== null) $t = [$w, $h];   // heavy lg: re-encode unscaled
                if ($t === null) continue;
                $c = clone $im;
                if ($t[0] !== $w || $t[1] !== $h) $c->resizeImage($t[0], $t[1], Imagick::FILTER_LANCZOS, 1);
                if ($fmt === 'jpg') {
                    $c->setImageBackgroundColor('white');
                    $c = $c->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                    $c->setImageFormat('jpeg');
                    $c->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                } else {
                    $c->setImageFormat('webp');
                }
                $c->setImageCompressionQuality(previewQuality($fmt));
                $c->stripImage();
                $dest = previewPathFor($abs, $size);
                $tmp  = $dest . '.' . getmypid() . '.' . mt_rand() . '.tmp';
                $c->writeImage($fmt . ':' . $tmp);
                $c->clear();
                $chk = @getimagesize($tmp);
                $type = $fmt === 'webp' ? (defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : -1) : IMAGETYPE_JPEG;
                if (!is_array($chk) || (int)$chk[0] !== $t[0] || (int)$chk[1] !== $t[1] || (int)$chk[2] !== $type || !@rename($tmp, $dest)) { @unlink($tmp); continue; }
                mediaChmodPath($dest);
                $out[$size] = $dest;
            }
            $im->clear();
        } catch (Throwable $e) {
            error_log('preview imagick: ' . basename($abs) . ': ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('previewGenerate')) {
    /**
     * (internal) Make the given sizes for one original in ONE decode, under a per-original lock and one of the
     * host-wide generator slots (previewSlotAcquire); re-checks freshness after the lock (a concurrent request may
     * have done it). The session is released before the decode (previewReleaseSession).
     *   $opts['wait']  seconds to wait for the original's lock / a free slot (default 10; 0 = never wait — preview.php)
     *   $opts['early'] callable(size, path): called as soon as `sm` is written, before the other sizes of the same
     *                  decode (preview.php answers the tile there and finishes `lg` after the response)
     *   $status        'ok' (made, or nothing to do) | 'busy' (lock / slots taken, nothing done) | 'failed'
     * Returns size → path|null for the requested sizes.
     */
    function previewGenerate(string $abs, array $sizes, array $opts = [], ?string &$status = null): array {
        $status = 'failed';
        $all = previewSizes();
        $out = [];
        foreach ($sizes as $s) { if (isset($all[$s])) $out[$s] = null; }
        if (!$out || !previewIsImage($abs) || !is_file($abs)) return $out;
        $dims = previewDims($abs);
        if ($dims === null) return $out;
        $dir = previewThumbsDir($abs);
        if (is_link($dir)) return $out;
        if (!is_dir($dir) && !mediaMkdir($dir)) return $out;
        if (!is_writable($dir)) return $out;
        previewEnsureThumbsHtaccess($dir);
        $up = realpath(previewUploadsDir());
        if ($up !== false && dirname((string)realpath($abs)) === rtrim($up, '/')) previewEnsureUploadsHtaccess();
        $wait = isset($opts['wait']) ? max(0.0, (float)$opts['wait']) : 10.0;

        $lockFile = $dir . '/' . pathinfo($abs, PATHINFO_FILENAME) . '.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock) {
            if ($wait <= 0.0) {
                if (!@flock($lock, LOCK_EX | LOCK_NB)) { @fclose($lock); $status = 'busy'; return $out; }   // being made right now
            } else {
                @flock($lock, LOCK_EX);
            }
        }
        $slot = null;
        try {
            clearstatcache();
            $want = [];
            foreach (array_keys($out) as $s) {
                if (!previewNeeds($abs, $s)) continue;
                if (previewIsFresh($abs, $s)) { $out[$s] = previewPathFor($abs, $s); continue; }
                $want[$s] = $all[$s];
            }
            if (!$want) { $status = 'ok'; return $out; }
            $slot = previewSlotAcquire($wait);
            if ($slot === false) { $slot = null; $status = 'busy'; return $out; }
            previewReleaseSession();
            if (getenv('PREVIEW_QA_FAIL') === '1') return $out;
            @set_time_limit(120);
            previewGdRuns(1);
            $early = isset($opts['early']) && is_callable($opts['early']) ? $opts['early'] : null;
            $made = previewGenerateImagick($abs, $want);
            if ($early !== null && !empty($made['sm'])) { previewWriteDims($abs, $dims['w'], $dims['h']); $early('sm', $made['sm']); $early = null; }
            $left = array_filter($want, static function ($k) use ($made) { return $made[$k] === null; }, ARRAY_FILTER_USE_KEY);
            if ($left) {
                $cb = $early === null ? null : static function (string $s, string $p) use ($early, $abs, $dims): void { previewWriteDims($abs, $dims['w'], $dims['h']); $early($s, $p); };
                try { $made = array_merge($made, array_filter(previewGenerateGd($abs, $left, $dims, $cb))); }
                catch (Throwable $e) { error_log('preview gd: ' . basename($abs) . ': ' . $e->getMessage()); }
            }
            foreach ($made as $s => $p) { if ($p !== null) $out[$s] = $p; }
            if (array_filter($made)) previewWriteDims($abs, $dims['w'], $dims['h']);
            $missing = array_diff(array_keys($want), array_keys(array_filter($made)));
            if (!$missing) {
                $status = 'ok';
                if (is_file(previewFailPath($abs))) @unlink(previewFailPath($abs));
            }
        } finally {
            if ($slot !== null) previewSlotRelease($slot);
            if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); @unlink($lockFile); }
        }
        return $out;
    }
}

if (!function_exists('previewEnsure')) {
    /**
     * The file to serve for $size: the fresh derivative (generated when missing / stale — together with the other
     * stale sizes, one decode), the ORIGINAL itself when it needs no derivative, or null (not an image, SVG / video,
     * undecodable, too big for memory, GD missing, folder not writable, every generator slot busy). Never fatal.
     * $opts as previewGenerate().
     */
    function previewEnsure(string $absOriginal, string $size, array $opts = []): ?string {
        $sizes = previewSizes();
        if (!isset($sizes[$size]) || !previewIsImage($absOriginal) || !is_file($absOriginal)) return null;
        if (previewDims($absOriginal) === null) return null;
        if (!previewNeeds($absOriginal, $size)) return $absOriginal;
        if (previewIsFresh($absOriginal, $size)) return previewPathFor($absOriginal, $size);
        return previewEnsureAll($absOriginal, $opts)[$size] ?? null;
    }
}

if (!function_exists('previewEnsureAll')) {
    /** Every size in one decode: size → the file to serve (derivative / the original when none is needed) | null. $opts / $status as previewGenerate(). */
    function previewEnsureAll(string $absOriginal, array $opts = [], ?string &$status = null): array {
        $out = [];
        $stale = [];
        $status = 'ok';
        foreach (array_keys(previewSizes()) as $s) {
            $out[$s] = null;
            if (!previewIsImage($absOriginal) || !is_file($absOriginal) || previewDims($absOriginal) === null) { $status = 'failed'; continue; }
            if (!previewNeeds($absOriginal, $s)) { $out[$s] = $absOriginal; continue; }
            if (previewIsFresh($absOriginal, $s)) { $out[$s] = previewPathFor($absOriginal, $s); continue; }
            $stale[] = $s;
        }
        if ($stale) {
            try {
                foreach (previewGenerate($absOriginal, $stale, $opts, $status) as $s => $p) $out[$s] = $p;
            } catch (Throwable $e) {
                $status = 'failed';
                error_log('previewEnsureAll: ' . $e->getMessage());
            }
        }
        return $out;
    }
}

if (!function_exists('previewClientDefer')) {
    /**
     * "The browser is sending this upload's previews" (upload-chunk.php / tire-upload.php with client_previews=1):
     * while on, previewAfterStore() does nothing — previewClientAccept() writes the files, or generates them itself
     * when what arrives is refused. previewClientDefer(true|false) sets, previewClientDefer() reads.
     */
    function previewClientDefer(?bool $set = null): bool {
        static $on = false;
        if ($set !== null) $on = $set;
        return $on;
    }
}

if (!function_exists('previewAfterStore')) {
    /**
     * Generation hook after a store: previewEnsureAll() (or $opts['sizes']) while this request's generation budget
     * lasts ($opts['budget'] seconds, default 8 s shared by every call in the request) and a generator slot frees up
     * within $opts['wait'] (default 3 s); later files are left to the lazy endpoint / backfill. Skipped entirely while
     * previewClientDefer() is on. Returns true when it generated (or nothing was needed), false when skipped / failed.
     */
    function previewAfterStore(string $absOriginal, array $opts = []): bool {
        static $spent = 0.0;
        if ($absOriginal === '' || !previewIsImage($absOriginal) || !is_file($absOriginal)) return false;
        if (previewClientDefer() && empty($opts['force'])) return false;
        $budget = isset($opts['budget']) ? (float)$opts['budget'] : 8.0;
        if ($spent >= $budget) return false;
        $gen = ['wait' => isset($opts['wait']) ? (float)$opts['wait'] : 3.0];
        $t0 = microtime(true);
        try {
            if (isset($opts['sizes']) && is_array($opts['sizes'])) {
                $ok = true;
                foreach ($opts['sizes'] as $s) { if (previewEnsure($absOriginal, (string)$s, $gen) === null) $ok = false; }
            } else {
                $ok = !in_array(null, previewEnsureAll($absOriginal, $gen), true);
            }
        } catch (Throwable $e) {
            error_log('previewAfterStore: ' . $e->getMessage());
            $ok = false;
        }
        $spent += microtime(true) - $t0;
        return $ok;
    }
}

if (!function_exists('previewDelete')) {
    /**
     * Remove the derivatives of one original (sm / lg in either format, dims sidecar, lock, legacy <stem>.jpg).
     * Call on delete and BEFORE regenerating after a replace. Returns the number of files removed.
     */
    function previewDelete(string $absOriginal): int {
        if ($absOriginal === '') return 0;
        $dir  = previewThumbsDir($absOriginal);
        if (is_link($dir) || !is_dir($dir)) return 0;
        $stem = pathinfo($absOriginal, PATHINFO_FILENAME);
        if ($stem === '') return 0;
        $names = [$stem . '.dims.json', $stem . '.fail.json', $stem . '.lock', $stem . '.jpg'];
        foreach (array_keys(previewSizes()) as $s) { foreach (['webp', 'jpg'] as $f) $names[] = $stem . '.' . $s . '.' . $f; }
        $n = 0;
        foreach ($names as $name) {
            $p = $dir . '/' . $name;
            if ((is_file($p) || is_link($p)) && @unlink($p)) $n++;
        }
        return $n;
    }
}

if (!function_exists('previewCopyDerivatives')) {
    /**
     * Pool copy-in: $destAbs is a fresh byte copy of $srcAbs — reuse the source's fresh derivatives (copied, so
     * they are newer than the copy) instead of decoding again; falls back to previewAfterStore() unless
     * $fallback is false (caller inside a transaction). Returns true when every needed size exists afterwards.
     */
    function previewCopyDerivatives(string $srcAbs, string $destAbs, bool $fallback = true): bool {
        if (!previewIsImage($destAbs) || !is_file($destAbs)) return false;
        $need = array_values(array_filter(array_keys(previewSizes()), static function ($s) use ($destAbs) { return previewNeeds($destAbs, $s); }));
        if (!$need) return true;
        $dir = previewThumbsDir($destAbs);
        $copied = 0;
        foreach ($need as $s) {
            if (!is_file($srcAbs) || !previewIsFresh($srcAbs, $s)) continue;
            if (!is_dir($dir) && !mediaMkdir($dir)) break;
            previewEnsureThumbsHtaccess($dir);
            $to = previewPathFor($destAbs, $s);
            $tmp = $to . '.' . getmypid() . '.tmp';
            if (@copy(previewPathFor($srcAbs, $s), $tmp) && @touch($tmp) && @rename($tmp, $to)) { mediaChmodPath($to); $copied++; }
            else @unlink($tmp);
        }
        clearstatcache();
        if ($copied === count($need)) {
            $d = previewDims($destAbs);
            if ($d) previewWriteDims($destAbs, $d['w'], $d['h']);
            return true;
        }
        return $fallback ? previewAfterStore($destAbs) : false;
    }
}

if (!function_exists('previewMoveDerivatives')) {
    /**
     * A parked original moved to its final name (uploadClaimTake: uploads/tmp_<token>.jpg → uploads/img_….jpg): its
     * fresh derivatives + dims sidecar follow it, so the store hook finds them and decodes nothing. Stale or foreign
     * files are left behind (deleted with the old stem). Returns how many derivatives moved.
     */
    function previewMoveDerivatives(string $fromAbs, string $toAbs): int {
        if (!previewIsImage($toAbs) || !is_file($toAbs)) return 0;
        $fromDir = previewThumbsDir($fromAbs); $toDir = previewThumbsDir($toAbs);
        if (is_link($fromDir) || !is_dir($fromDir)) return 0;
        $n = 0;
        foreach (array_keys(previewSizes()) as $s) {
            $src = previewPathFor($fromAbs, $s);
            if (!is_file($src) || is_link($src)) continue;
            if (!is_dir($toDir) && !mediaMkdir($toDir)) break;
            previewEnsureThumbsHtaccess($toDir);
            $dest = previewPathFor($toAbs, $s);
            if (!@rename($src, $dest)) { @unlink($src); continue; }
            clearstatcache(true, $dest);
            if (previewMtime($dest) < previewMtime($toAbs)) @touch($dest, previewMtime($toAbs));   // copy fallback gave the original a new mtime
            mediaChmodPath($dest);
            $n++;
        }
        if ($n) {
            $d = previewDims($toAbs);
            if ($d) previewWriteDims($toAbs, $d['w'], $d['h']);
        }
        previewDelete($fromAbs);
        return $n;
    }
}

// ---------------------------------------------------------------------
// Placeholder (preview.php when every generator slot is busy / a build failed)
// ---------------------------------------------------------------------

if (!function_exists('previewPlaceholderSvg')) {
    /**
     * A neutral tile. 'pending' is 1×1 intrinsic (static/js/app.js App.previewRetry spots naturalWidth === 1 on a
     * preview.php image and retries with backoff); 'failed' is 2×2 (never retried). The <img> keeps its own
     * width / height attributes, so the layout does not move.
     */
    function previewPlaceholderSvg(string $kind): string {
        $n = $kind === 'failed' ? 2 : 1;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $n . '" height="' . $n . '" viewBox="0 0 ' . $n . ' ' . $n . '">'
             . '<rect width="' . $n . '" height="' . $n . '" fill="#8e8e93" fill-opacity="0.16"/></svg>';
    }
}

// ---------------------------------------------------------------------
// Client-made previews (static/js/chunk-upload.js App.imagePreview): the browser encodes sm / lg WebP while the
// original uploads; the upload reply carries a one-time preview_key (a ticket tied to this admin session, the
// client and that one stored file); action=previews brings the files. Strict validation, server-chosen names
// (exactly previewPathFor()), anything refused → the server makes that size itself (or leaves it to the lazy path).
// ---------------------------------------------------------------------

if (!function_exists('previewClientCaps')) {
    /** size → max bytes of a client-made preview. */
    function previewClientCaps(): array { return ['sm' => 300 * 1024, 'lg' => 1536 * 1024]; }
}

if (!function_exists('previewClientEnabled')) {
    /** Client previews are WebP: only when the server's own format is WebP (else the paths would not match). Env PREVIEW_NO_CLIENT=1 turns them off. */
    function previewClientEnabled(): bool {
        return previewFormat() === 'webp' && getenv('PREVIEW_NO_CLIENT') !== '1';
    }
}

if (!function_exists('previewClientRequested')) {
    /** Did this upload request say "the browser sends the previews" (client_previews=1)? */
    function previewClientRequested(): bool {
        return previewClientEnabled() && !empty($_POST['client_previews']) && (string)$_POST['client_previews'] !== '0';
    }
}

if (!function_exists('previewClientTicketDir')) {
    /** uploads/.spool (deny-all, chunk-upload-lib.php) — <32hex>.pvt tickets live next to the claim sidecars. */
    function previewClientTicketDir(): ?string {
        $up = previewUploadsDir();
        if (!is_dir($up)) return null;
        if (function_exists('chunkSpoolDir')) return chunkSpoolDir($up, true);
        $d = $up . '/.spool';
        return is_dir($d) && !is_link($d) && is_writable($d) ? $d : null;
    }
}

if (!function_exists('previewClientSessionTag')) {
    /** Who may redeem a ticket: a hash of this admin session's id ('' without a session — such tickets never redeem). */
    function previewClientSessionTag(): string {
        $id = function_exists('session_id') ? (string)session_id() : '';
        return $id === '' ? '' : hash_hmac('sha256', $id, previewSecret());
    }
}

if (!function_exists('previewClientTicket')) {
    /**
     * Issue the one-time key for the file this admin just stored at $abs (images that need a derivative only).
     * $gen: when what the browser sends is refused (or never arrives in this request), make the missing sizes
     * here (library / reference / replace / series) — false for parked Compose files (made when the post is saved).
     * Returns the key, or null (then the caller makes previews the usual way).
     */
    function previewClientTicket(string $abs, string $client, bool $gen): ?string {
        if (!previewClientEnabled() || !previewIsImage($abs) || !is_file($abs)) return null;
        $ref = previewRefFromPath($abs);
        $tag = previewClientSessionTag();
        $d = previewDims($abs);
        if ($ref === null || $tag === '' || $d === null) return null;
        $need = array_values(array_filter(array_keys(previewSizes()), static function ($s) use ($abs) { return previewNeeds($abs, $s); }));
        if (!$need) return null;
        $dir = previewClientTicketDir();
        if ($dir === null) return null;
        previewClientCleanup($dir);
        $key = bin2hex(random_bytes(16));
        $t = ['key' => $key, 'ref' => $ref, 'client' => $client, 'sess' => $tag, 'm' => previewMtime($abs), 'w' => $d['w'], 'h' => $d['h'],
              'need' => $need, 'gen' => $gen, 'created_at' => time()];
        if (@file_put_contents($dir . '/' . $key . '.pvt', json_encode($t, JSON_UNESCAPED_SLASHES)) === false) return null;
        @chmod($dir . '/' . $key . '.pvt', 0600);
        return $key;
    }
}

if (!function_exists('previewClientCleanup')) {
    /** Tickets live an hour. */
    function previewClientCleanup(string $dir, int $maxAge = 3600, int $cap = 200): void {
        $dh = @opendir($dir);
        if ($dh === false) return;
        $seen = 0; $cut = time() - $maxAge;
        while (($f = readdir($dh)) !== false && $seen < $cap) {
            if (!preg_match('/^[a-f0-9]{32}\.pvt$/', $f)) continue;
            $seen++;
            $mt = @filemtime($dir . '/' . $f);
            if ($mt !== false && $mt < $cut) @unlink($dir . '/' . $f);
        }
        closedir($dh);
    }
}

if (!function_exists('previewClientCheckFile')) {
    /**
     * Validate one uploaded preview against the original: '' when acceptable, else the reason. $f = a $_FILES entry,
     * $t = [w, h] the derivative the server would make (previewTargetDims), $d = the original's oriented size.
     */
    function previewClientCheckFile(array $f, string $size, array $t, array $d): string {
        $caps = previewClientCaps();
        if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return 'upload error';
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) return 'not an upload';
        $bytes = (int)@filesize($tmp);
        if ($bytes <= 0 || $bytes > $caps[$size]) return 'too large (' . $bytes . ' bytes, max ' . $caps[$size] . ')';
        $fh = @fopen($tmp, 'rb');
        $head = $fh ? (string)fread($fh, 12) : '';
        if ($fh) fclose($fh);
        if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') return 'not a WebP file';
        $info = @getimagesize($tmp);
        if (!is_array($info) || !defined('IMAGETYPE_WEBP') || (int)($info[2] ?? 0) !== IMAGETYPE_WEBP) return 'not a WebP image';
        $w = (int)$info[0]; $h = (int)$info[1];
        if ($w <= 0 || $h <= 0) return 'no dimensions';
        $max = previewSizes()[$size];
        $long = max($w, $h);
        if ($long > $max + 2) return "longest side {$long} over {$max}";
        if (abs($long - max($t[0], $t[1])) > 2) return "longest side {$long}, expected " . max($t[0], $t[1]);
        $want = $d['w'] / $d['h']; $got = $w / $h;
        if (abs($got / $want - 1) > 0.02) return 'aspect ' . round($got, 4) . ' vs original ' . round($want, 4);
        return '';
    }
}

if (!function_exists('previewClientAccept')) {
    /**
     * Redeem a ticket: $files = ['sm' => $_FILES entry, 'lg' => …] (either may be missing). Every needed size is
     * either written from the browser's file (validated, renamed to previewPathFor()) or — when refused / absent
     * and the ticket says so — made here in one decode (generator slot, short wait). The ticket is single-use.
     * Returns ['code' => 200|400|403|404|409, 'body' => [ok, accepted, rejected{size: why}, generated, thumb, large]].
     */
    function previewClientAccept(string $key, string $client, array $files): array {
        $fail = static function (int $code, string $msg): array { return ['code' => $code, 'body' => ['ok' => false, 'error' => $msg]]; };
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) return $fail(400, 'Invalid preview key');
        $dir = previewClientTicketDir();
        $file = $dir === null ? '' : $dir . '/' . $key . '.pvt';
        if ($file === '' || is_link($file) || !is_file($file)) return $fail(404, 'Unknown or used preview key');
        $t = json_decode((string)@file_get_contents($file), true);
        if (!is_array($t) || ($t['key'] ?? '') !== $key) { @unlink($file); return $fail(404, 'Unknown preview key'); }
        if (!hash_equals((string)$t['sess'], previewClientSessionTag()) || (string)$t['client'] !== $client) return $fail(403, 'This preview key belongs to another upload');
        @unlink($file);   // single use from here on
        if ((int)$t['created_at'] < time() - 3600) return $fail(404, 'Preview key expired');
        $abs = previewRefPath((string)$t['ref']);
        if ($abs === null || !is_file($abs) || previewMtime($abs) !== (int)$t['m']) return $fail(409, 'The file changed since it was uploaded');
        $d = ['w' => (int)$t['w'], 'h' => (int)$t['h']];
        $now = previewDims($abs);
        if ($now === null || $now['w'] !== $d['w'] || $now['h'] !== $d['h']) return $fail(409, 'The file changed since it was uploaded');

        $accepted = []; $rejected = [];
        $thumbs = previewThumbsDir($abs);
        foreach (array_keys(previewSizes()) as $s) {
            $f = $files[$s] ?? null;
            if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $target = previewTargetDims($abs, $s, $d);
            if ($target === null) { $rejected[$s] = 'not needed'; continue; }
            $why = previewClientCheckFile($f, $s, $target, $d);
            if ($why === '') {
                if (is_link($thumbs) || (!is_dir($thumbs) && !mediaMkdir($thumbs)) || !is_writable($thumbs)) { $why = 'previews folder not writable'; }
            }
            if ($why !== '') { $rejected[$s] = $why; continue; }
            previewEnsureThumbsHtaccess($thumbs);
            $dest = previewPathFor($abs, $s);
            $tmp = $dest . '.' . getmypid() . '.' . mt_rand() . '.tmp';
            if (!@move_uploaded_file((string)$f['tmp_name'], $tmp) || !@rename($tmp, $dest)) { @unlink($tmp); $rejected[$s] = 'could not be saved'; continue; }
            clearstatcache(true, $dest);
            if (previewMtime($dest) < previewMtime($abs)) @touch($dest, previewMtime($abs));
            mediaChmodPath($dest);
            $accepted[] = $s;
        }
        if ($accepted) previewWriteDims($abs, $d['w'], $d['h']);
        foreach ($rejected as $s => $why) error_log('preview client: ' . basename($abs) . ' ' . $s . ' refused — ' . $why);

        $generated = false;
        $missing = array_values(array_filter((array)$t['need'], static function ($s) use ($abs) { return !previewIsFresh($abs, (string)$s); }));
        if ($missing && !empty($t['gen'])) {
            previewEnsureAll($abs, ['wait' => 3.0]);
            $generated = true;
        }
        $url = previewRefUrl((string)$t['ref']);
        clearstatcache();
        return ['code' => 200, 'body' => [
            'ok' => true, 'accepted' => $accepted, 'rejected' => (object)$rejected, 'generated' => $generated,
            'thumb' => previewUrlFor($url, $abs, 'sm'), 'large' => previewUrlFor($url, $abs, 'lg'),
        ]];
    }
}
