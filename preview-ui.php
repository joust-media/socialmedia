<?php
/**
 * Image previews — the render-side helpers every page uses to put a small
 * derivative (sm, grid tiles / list thumbs) or a large one (lg, the viewer and
 * the post carousel) on screen instead of the multi-MB original.
 *
 * preview-lib.php (derivative generation, preview.php, upload hooks, backfill;
 * scratchpad previews-design.md) owns previewImgAttrs() / previewUrl(); this
 * file only wraps them for the markup:
 *
 *   pvImg(string $url, string $size = 'sm', array $opts = []): string
 *       <img …> for a content image: src + srcset + sizes + width/height +
 *       loading (lazy unless $opts['eager']) + decoding=async + alt/class.
 *       $url: any image URL the pages already hold (root-rooted, app-relative
 *       'uploads/…' / 'media/…', or absolute).
 *   pvUrls(string $url): array
 *       ['thumb' => sm URL, 'large' => lg URL, 'original' => $url] — tiles keep
 *       data-src = large (the viewer) and data-original = original (download /
 *       "View original"); JS swaps read the same keys from endpoint replies.
 *
 * Nothing here writes a file. Every function is function_exists-guarded.
 */

require_once __DIR__ . '/preview-lib.php';   // helpers.php loads it first; a no-op then

/* ---------------------------------------------------------------------
 * Markup helpers (the only API the pages call)
 * --------------------------------------------------------------------- */
if (!function_exists('pvImg')) {
    /**
     * <img> for a content image at $size ('sm' | 'lg'). $opts: sizes, eager (bool), alt, class, attrs (extra
     * name => value pairs appended verbatim, escaped). '' URL → ''.
     */
    function pvImg(string $url, string $size = 'sm', array $opts = []): string {
        if (trim($url) === '') return '';
        $size = $size === 'lg' ? 'lg' : 'sm';
        $extra = '';
        foreach ((array)($opts['attrs'] ?? []) as $k => $v) {
            $extra .= ' ' . preg_replace('/[^a-zA-Z0-9_:\-]/', '', (string)$k) . '="' . htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        $e = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $cls = trim((string)($opts['class'] ?? ''));
        $alt = (string)($opts['alt'] ?? '');
        unset($opts['attrs'], $opts['class'], $opts['alt']);
        // previewImgAttrs(): src (the size's derivative / lazy preview.php / the original when none applies),
        // srcset + sizes (sm + lg candidates with their real widths), width / height, loading, decoding — escaped.
        return '<img' . ($cls !== '' ? ' class="' . $e($cls) . '"' : '') . previewImgAttrs($url, $size, $opts) . ' alt="' . $e($alt) . '"' . $extra . '>';
    }
}

if (!function_exists('pvUrl')) {
    /** The $size derivative URL of $url (the original itself when there is none / it is not an image of ours). */
    function pvUrl(string $url, string $size = 'sm'): string {
        if (trim($url) === '') return '';
        return previewUrl($url, $size === 'lg' ? 'lg' : 'sm');
    }
}

if (!function_exists('pvUrls')) {
    /** ['thumb' => sm, 'large' => lg, 'original' => $url] for one image URL (videos: all three = $url). */
    function pvUrls(string $url): array {
        $ext = strtolower(pathinfo((string)(parse_url($url, PHP_URL_PATH) ?: $url), PATHINFO_EXTENSION));
        if ($url === '' || (function_exists('isVideoExt') && isVideoExt($ext))) return ['thumb' => $url, 'large' => $url, 'original' => $url];
        return ['thumb' => pvUrl($url, 'sm'), 'large' => pvUrl($url, 'lg'), 'original' => $url];
    }
}

if (!function_exists('pvSizes')) {
    /** The `sizes` value of each render box (kept in one place so the CSS and the attribute agree). */
    function pvSizes(string $box): string {
        switch ($box) {
            case 'grid':   return '(min-width: 1280px) 220px, (min-width: 1024px) 18vw, (min-width: 600px) 24vw, 34vw';   // .ui-grid 3 → 6 columns
            case 'pool':   return '(min-width: 1024px) 180px, (min-width: 600px) 22vw, 34vw';
            case 'row':    return '56px';     // .ui-row-leading
            case 'ref':    return '96px';     // .as-reference-media
            case 'strip':  return '112px';    // .as-refstrip tiles
            case 'mini':   return '72px';     // Studio strip / batch rows / "Current media"
            case 'card':   return '200px';    // Home "Up next"
            case 'legacy': return '160px';
            case 'slide':  return '(min-width: 900px) 560px, 100vw';   // post carousel
            default:       return '100vw';
        }
    }
}

if (!function_exists('pvReplyFields')) {
    /**
     * Replace replies (replace-image.php / upload-chunk.php purpose=replace): the sm / lg preview URLs of the
     * new file next to `src`, so the page swaps the tile to `thumb` and the viewer / carousel to `large`
     * (videos and failed replies: nothing added).
     */
    function pvReplyFields(array $body): array {
        if (empty($body['ok']) || ($body['media_type'] ?? 'image') === 'video') return [];
        $src = (string)($body['src'] ?? '');
        if ($src === '' && !empty($body['image_url'])) $src = (function_exists('basePath') ? basePath() : '') . '/' . ltrim((string)$body['image_url'], '/');
        if ($src === '') return [];
        $u = pvUrls($src);
        return ['thumb' => $u['thumb'], 'large' => $u['large'], 'original' => $u['original']];
    }
}
