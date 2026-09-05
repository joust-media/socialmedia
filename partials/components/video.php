<?php
/**
 * Video (spec §6) — the ONE renderer for every <video> in the portal.
 * static/js/video.js (App.video) enhances the output: Chrome/Firefox fallback
 * card, client-side poster frames, duration badges, tap-to-unmute, comment
 * timestamps.  All helpers are guarded; helpers.php loads this file.
 *
 *   renderVideoElement(string $url, array $opts = []): string
 *     <div class="ui-video" data-video data-video-url="…" data-video-ext="mov">
 *       <video playsinline muted controls preload="metadata" poster="{poster}">
 *         <source src="file.mov" type="video/quicktime">
 *         <source src="file.mp4" type="video/mp4">   ← only when a transcoded twin
 *       </video>                                        (same basename + .mp4) is on disk
 *       <button data-video-mute …>Tap to unmute</button>   ← when 'unmute' (default: = autoplay)
 *       <div data-video-fallback hidden>Preview not supported … Open video / Download</div>
 *     </div>
 *     $opts: 'poster' (URL), 'autoplay' (bool → autoplay attr; muted), 'unmute' (bool),
 *            'controls' (default true), 'class' (container), 'id' (on <video>),
 *            'data' (['name' => value] → data-name on the container), 'label' (aria-label),
 *            'download' (filename for the Download link), 'fallback' (default true),
 *            'twin' (explicit mp4 twin URL, or false to skip detection),
 *            'path' (absolute disk path of the file, for twin detection).
 *
 *   videoTwinUrl(string $url, ?string $path = null): string   — mp4 twin URL or ''.
 *   videoAbsUrl(string $url): string                          — root-rooted URL (basePath()-aware).
 *   videoThumbAttrs(string $url): string                      — ` data-video-thumb data-video-url="…"`
 *                                                               for any thumbnail App.video may give a poster.
 *   videoDurationBadge(string $class = ''): string            — play glyph + "--:--" ([data-video-duration]).
 *   videoTile(string $url, array $opts = []): string          — grid/list thumbnail for a video: poster
 *                                                               when known, else a dark tile + play glyph;
 *                                                               $opts: 'poster', 'badge' (default true),
 *                                                               'badgeClass', 'class'.
 */

if (!function_exists('videoEsc')) {
    function videoEsc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('videoAbsUrl')) {
    function videoAbsUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('#^(https?:)?//#i', $url) || $url[0] === '/') return $url;
        return (function_exists('basePath') ? basePath() : '') . '/' . ltrim($url, '/');
    }
}

if (!function_exists('videoUrlExt')) {
    function videoUrlExt(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return strtolower(pathinfo(is_string($path) && $path !== '' ? $path : $url, PATHINFO_EXTENSION));
    }
}

if (!function_exists('videoDiskPath')) {
    /** Best-effort absolute disk path for a media URL this app serves (uploads/ or /media/library/). */
    function videoDiskPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : $url;
        $appRoot = dirname(__DIR__, 2);
        $base = function_exists('basePath') ? basePath() : '';
        if ($base !== '' && strpos($path, $base . '/') === 0) $path = substr($path, strlen($base));
        if (preg_match('#^/?uploads/([^/]+)$#', $path, $m)) {
            return $appRoot . '/uploads/' . rawurldecode($m[1]);
        }
        if (preg_match('#^/media/library/([^/]+)/([^/]+)$#', $path, $m) && function_exists('libraryDir')) {
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(rawurldecode($m[1])));
            if ($slug === '') return '';
            return libraryDir($slug) . '/' . rawurldecode($m[2]);
        }
        return '';
    }
}

if (!function_exists('videoTwinUrl')) {
    /** URL of a transcoded .mp4 twin (same basename) if it exists on disk next to a .mov; '' otherwise. */
    function videoTwinUrl(string $url, ?string $path = null): string
    {
        if (videoUrlExt($url) !== 'mov') return '';
        $disk = $path !== null && $path !== '' ? $path : videoDiskPath($url);
        if ($disk === '' || strpos($disk, '..') !== false) return '';
        $twin = preg_replace('/\.mov$/i', '.mp4', $disk);
        if ($twin === $disk || !is_file($twin)) return '';
        return preg_replace('/\.mov(\?.*)?$/i', '.mp4$1', $url);
    }
}

if (!function_exists('renderVideoElement')) {
    function renderVideoElement(string $url, array $opts = []): string
    {
        $src      = videoAbsUrl($url);
        $ext      = videoUrlExt($src);
        $mime     = function_exists('videoMime') ? videoMime($ext) : ($ext === 'webm' ? 'video/webm' : ($ext === 'mov' ? 'video/quicktime' : 'video/mp4'));
        $autoplay = !empty($opts['autoplay']);
        $unmute   = array_key_exists('unmute', $opts) ? (bool)$opts['unmute'] : $autoplay;
        $controls = !array_key_exists('controls', $opts) || $opts['controls'];
        $fallback = !array_key_exists('fallback', $opts) || $opts['fallback'];
        $poster   = trim((string)($opts['poster'] ?? ''));
        $label    = trim((string)($opts['label'] ?? ''));
        $download = trim((string)($opts['download'] ?? ''));
        $twin     = '';
        if (array_key_exists('twin', $opts)) {
            $twin = is_string($opts['twin']) ? $opts['twin'] : '';
        } else {
            $twin = videoTwinUrl($src, isset($opts['path']) ? (string)$opts['path'] : null);
        }

        $attrs = ' data-video data-video-url="' . videoEsc($src) . '" data-video-ext="' . videoEsc($ext) . '"';
        if ($autoplay) $attrs .= ' data-video-autoplay';
        foreach ((array)($opts['data'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$k));
            if ($k !== '') $attrs .= ' data-' . $k . ($v === true || $v === null ? '' : '="' . videoEsc($v) . '"');
        }

        $out  = '<div class="ui-video' . (!empty($opts['class']) ? ' ' . videoEsc($opts['class']) : '') . '"' . $attrs . '>';
        $out .= '<video playsinline muted' . ($controls ? ' controls' : '') . ' preload="metadata"'
              . ($poster !== '' ? ' poster="' . videoEsc($poster) . '"' : '')
              . ($autoplay ? ' autoplay' : '')
              . (!empty($opts['id']) ? ' id="' . videoEsc($opts['id']) . '"' : '')
              . ($label !== '' ? ' aria-label="' . videoEsc($label) . '"' : '')
              . '>';
        $out .= '<source src="' . videoEsc($src) . '" type="' . videoEsc($mime) . '">';
        if ($twin !== '') {
            $out .= '<source src="' . videoEsc($twin) . '" type="video/mp4">';
        }
        $out .= '</video>';

        if ($unmute) {
            $out .= '<button type="button" class="ui-pill ui-pill--glass ui-pill--nodot ui-video-mute" data-video-mute aria-pressed="true" hidden>'
                  . (function_exists('icon') ? icon('speaker-slash', 'ui-video-mute-icon') : '')
                  . '<span data-video-mute-label>Tap to unmute</span></button>';
        }

        if ($fallback) {
            $out .= '<div class="ui-video-fallback" data-video-fallback hidden>'
                  . '<div class="ui-video-fallback-card">'
                  . '<p class="ui-video-fallback-title">Preview not supported in this browser</p>'
                  . '<p class="ui-video-fallback-text">This video plays in Safari on iPhone, iPad and Mac.</p>'
                  . '<div class="ui-btn-group">'
                  . '<a class="ui-btn ui-btn--filled ui-btn--sm" data-video-open href="' . videoEsc($src) . '" target="_blank" rel="noopener">Open video</a>'
                  . '<a class="ui-btn ui-btn--gray ui-btn--sm" data-video-download href="' . videoEsc($src) . '" download' . ($download !== '' ? '="' . videoEsc($download) . '"' : '') . '>Download</a>'
                  . '</div></div></div>';
        }
        return $out . '</div>';
    }
}

if (!function_exists('videoThumbAttrs')) {
    function videoThumbAttrs(string $url): string
    {
        return ' data-video-thumb data-video-url="' . videoEsc(videoAbsUrl($url)) . '"';
    }
}

if (!function_exists('videoDurationBadge')) {
    function videoDurationBadge(string $class = ''): string
    {
        return '<span class="ui-video-duration' . ($class !== '' ? ' ' . videoEsc($class) : '') . '" data-video-duration>'
             . (function_exists('icon') ? icon('play') : '')
             . '<span data-video-duration-text>--:--</span></span>';
    }
}

if (!function_exists('videoTile')) {
    function videoTile(string $url, array $opts = []): string
    {
        $poster = trim((string)($opts['poster'] ?? ''));
        $badge  = !array_key_exists('badge', $opts) || $opts['badge'];
        $out  = '<span class="ui-video-tile' . (!empty($opts['class']) ? ' ' . videoEsc($opts['class']) : '') . ($poster !== '' ? ' has-poster' : '') . '"' . videoThumbAttrs($url) . '>';
        $out .= '<img class="ui-video-poster" data-video-poster alt="" decoding="async"' . ($poster !== '' ? ' src="' . videoEsc($poster) . '"' : ' hidden') . '>';
        $out .= '<span class="ui-video-glyph" aria-hidden="true">' . (function_exists('icon') ? icon('play') : '') . '</span>';
        if ($badge) {
            $out .= '<span class="ui-pill ui-pill--glass ui-pill--nodot ui-thumb-badge ui-video-badge' . (!empty($opts['badgeClass']) ? ' ' . videoEsc($opts['badgeClass']) : '') . '">'
                  . videoDurationBadge() . '</span>';
        }
        return $out . '</span>';
    }
}
