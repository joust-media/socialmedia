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
 * original whose long edge already fits the target gets no derivative and is used as-is for that size.
 * EXIF orientation is applied (JPEG), alpha kept for webp / flattened onto white for jpg, GIF = first frame.
 * SVG and videos never get derivatives. The old 640 px tire thumbs (<dir>/.thumbs/<stem>.jpg) are still
 * read as the `sm` fallback until regenerated.
 *
 * Render sites call previewUrl() / previewImgAttrs() with the URL they already print; the result is the
 * static derivative (?v=<mtime of the original>) when it is fresh, else the lazy endpoint preview.php
 * (HMAC-signed path, generates once, 302s to the original when it cannot) — so nothing ever breaks.
 * Stores call previewAfterStore(); deletes call previewDelete(); Studio → Export → Image previews backfills
 * (preview-job.php). Contract: scratchpad previews-design.md; README "Image previews".
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
            return function_exists('uploadsPathOrNull') ? uploadsPathOrNull($ref) : null;
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

if (!function_exists('previewNeeds')) {
    /** Does this size need a derivative at all? (image, decodable, larger than the target) */
    function previewNeeds(string $abs, string $size): bool {
        $sizes = previewSizes();
        if (!isset($sizes[$size])) return false;
        $d = previewDims($abs);
        return $d !== null && previewScaleDims($d['w'], $d['h'], $sizes[$size]) !== null;
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
        $t = previewScaleDims($d['w'], $d['h'], $sizes[$size]);
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
    /** (internal) One decode, every requested size (largest first, each scaled from the previous). size → path|null. */
    function previewGenerateGd(string $abs, array $want, array $dims): array {
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
        $cur = $im;
        arsort($want);   // largest target first
        foreach ($want as $size => $max) {
            $t = previewScaleDims(imagesx($im), imagesy($im), (int)$max);
            if ($t === null) continue;
            $scaled = imagescale($cur, $t[0], $t[1], IMG_BICUBIC);
            if (!$scaled) continue;
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $dest = previewPathFor($abs, $size);
            if (previewWriteGd($scaled, $dest, $fmt)) $out[$size] = $dest;
            if ($cur !== $im) imagedestroy($cur);
            $cur = $scaled;
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
            $w = $im->getImageWidth(); $h = $im->getImageHeight();
            arsort($want);
            foreach ($want as $size => $max) {
                $t = previewScaleDims($w, $h, (int)$max);
                if ($t === null) continue;
                $c = clone $im;
                $c->resizeImage($t[0], $t[1], Imagick::FILTER_LANCZOS, 1);
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
     * (internal) Make the given sizes for one original under a per-original lock; re-checks freshness after the
     * lock (a concurrent request may have done it). Returns size → path|null for the requested sizes.
     */
    function previewGenerate(string $abs, array $sizes): array {
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

        $lockFile = $dir . '/' . pathinfo($abs, PATHINFO_FILENAME) . '.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock) @flock($lock, LOCK_EX);
        try {
            clearstatcache();
            $want = [];
            foreach (array_keys($out) as $s) {
                if (!previewNeeds($abs, $s)) continue;
                if (previewIsFresh($abs, $s)) { $out[$s] = previewPathFor($abs, $s); continue; }
                $want[$s] = $all[$s];
            }
            if ($want && getenv('PREVIEW_QA_FAIL') !== '1') {
                @set_time_limit(120);
                $made = previewGenerateImagick($abs, $want);
                $left = array_filter($want, static function ($k) use ($made) { return $made[$k] === null; }, ARRAY_FILTER_USE_KEY);
                if ($left) {
                    try { $made = array_merge($made, array_filter(previewGenerateGd($abs, $left, $dims))); }
                    catch (Throwable $e) { error_log('preview gd: ' . basename($abs) . ': ' . $e->getMessage()); }
                }
                foreach ($made as $s => $p) { if ($p !== null) $out[$s] = $p; }
                if (array_filter($made)) previewWriteDims($abs, $dims['w'], $dims['h']);
            }
        } finally {
            if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); @unlink($lockFile); }
        }
        return $out;
    }
}

if (!function_exists('previewEnsure')) {
    /**
     * The file to serve for $size: the fresh derivative (generated when missing / stale), the ORIGINAL itself when
     * it already fits the target (never upscale), or null (not an image, SVG / video, undecodable, too big for
     * memory, GD missing, folder not writable). Never fatal.
     */
    function previewEnsure(string $absOriginal, string $size): ?string {
        $sizes = previewSizes();
        if (!isset($sizes[$size]) || !previewIsImage($absOriginal) || !is_file($absOriginal)) return null;
        if (previewDims($absOriginal) === null) return null;
        if (!previewNeeds($absOriginal, $size)) return $absOriginal;
        if (previewIsFresh($absOriginal, $size)) return previewPathFor($absOriginal, $size);
        try {
            return previewGenerate($absOriginal, [$size])[$size] ?? null;
        } catch (Throwable $e) {
            error_log('previewEnsure: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('previewEnsureAll')) {
    /** Every size in one decode: size → the file to serve (derivative / the original when small) | null. */
    function previewEnsureAll(string $absOriginal): array {
        $out = [];
        $stale = [];
        foreach (array_keys(previewSizes()) as $s) {
            $out[$s] = null;
            if (!previewIsImage($absOriginal) || !is_file($absOriginal) || previewDims($absOriginal) === null) continue;
            if (!previewNeeds($absOriginal, $s)) { $out[$s] = $absOriginal; continue; }
            if (previewIsFresh($absOriginal, $s)) { $out[$s] = previewPathFor($absOriginal, $s); continue; }
            $stale[] = $s;
        }
        if ($stale) {
            try {
                foreach (previewGenerate($absOriginal, $stale) as $s => $p) $out[$s] = $p;
            } catch (Throwable $e) {
                error_log('previewEnsureAll: ' . $e->getMessage());
            }
        }
        return $out;
    }
}

if (!function_exists('previewAfterStore')) {
    /**
     * Generation hook after a store: previewEnsureAll() (or $opts['sizes']) while this request's generation budget
     * lasts ($opts['budget'] seconds, default 8 s shared by every call in the request); later files are left to the
     * lazy endpoint / backfill. Returns true when it generated (or nothing was needed), false when skipped / failed.
     */
    function previewAfterStore(string $absOriginal, array $opts = []): bool {
        static $spent = 0.0;
        if ($absOriginal === '' || !previewIsImage($absOriginal) || !is_file($absOriginal)) return false;
        $budget = isset($opts['budget']) ? (float)$opts['budget'] : 8.0;
        if ($spent >= $budget) return false;
        $t0 = microtime(true);
        try {
            if (isset($opts['sizes']) && is_array($opts['sizes'])) {
                $ok = true;
                foreach ($opts['sizes'] as $s) { if (previewEnsure($absOriginal, (string)$s) === null) $ok = false; }
            } else {
                $ok = !in_array(null, previewEnsureAll($absOriginal), true);
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
        $names = [$stem . '.dims.json', $stem . '.lock', $stem . '.jpg'];
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
     * they are newer than the copy) instead of decoding again; falls back to previewAfterStore(). Returns true
     * when every needed size exists afterwards.
     */
    function previewCopyDerivatives(string $srcAbs, string $destAbs): bool {
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
        return previewAfterStore($destAbs);
    }
}
