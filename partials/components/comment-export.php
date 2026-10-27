<?php
/**
 * "Export open comments" — the export sheet (static/js/comment-export.js, App.commentExport) on every admin page;
 * comment-export.php does the work. Nothing is rendered for the client seat (or the admin viewing as a client).
 *
 *   commentExportBootHtml(?array $client): string
 *       window.CommentExportConfig = {endpoint, client (scoped slug or ''), clientName, css: [comment-export.css], close}
 *       + comment-export.js (deferred). Any [data-cexp-open] opens the sheet (data-cexp-scope="all" starts on
 *       All clients, data-cexp-filter="waiting|all" on that filter).
 *   commentExportButton(array $opts = []): string
 *       The entry point markup: ['label', 'class', 'scope' => 'all', 'menuitem' => true (a ⋯ menu row), 'attrs' => […]].
 *   commentExportPackButton(array $o): string
 *       A visible "Export N open" button (count from comment-export-lib.php cexpPageCounts()) scoped to a tire / series /
 *       the Reference / the Library / a client: opens the sheet on "Bot pack (ZIP)". '' at 0 and for the client seat.
 */
if (!function_exists('commentExportBootHtml')) {
    function commentExportBootHtml(?array $client): string
    {
        if (!function_exists('isAdmin') || !isAdmin()) return '';
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $cfg = [
            'endpoint'   => basePath() . '/comment-export.php',
            'client'     => (string)($client['slug'] ?? ''),
            'clientName' => (string)($client['name'] ?? ''),
            'css'        => [staticUrl('css/comment-export.css')],
            'close'      => function_exists('icon') ? icon('xmark') : '×',
            'download'   => function_exists('icon') ? icon('download') : '',
        ];
        return '<script>window.CommentExportConfig = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . $e(staticUrl('js/comment-export.js')) . '" defer></script>' . "\n";
    }
}

if (!function_exists('commentExportButton')) {
    function commentExportButton(array $opts = []): string
    {
        if (!function_exists('isAdmin') || !isAdmin()) return '';
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $label = (string)($opts['label'] ?? 'Export open comments…');
        $attrs = ' data-cexp-open';
        if (($opts['scope'] ?? '') === 'all') $attrs .= ' data-cexp-scope="all"';
        foreach ((array)($opts['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . ($v === '' ? '' : '="' . $e($v) . '"');
        }
        $ico = function_exists('icon') ? icon('bubble') : '';
        if (!empty($opts['menuitem'])) {
            return '<button type="button" role="menuitem" class="' . $e((string)($opts['class'] ?? '')) . '"' . $attrs . '>' . (!empty($opts['icon']) ? $ico : '') . $e($label) . '</button>';
        }
        return '<button type="button" class="' . $e((string)($opts['class'] ?? 'ui-btn ui-btn--gray')) . '"' . $attrs . '>' . $ico . '<span>' . $e($label) . '</span></button>';
    }
}

if (!function_exists('commentExportPackButton')) {
    /**
     * A visible "Export N open" button where a batch of comments lives (Tires list rows, a tire, a series, the Reference
     * card, the Library toolbar, the Redo groups, the Inbox): opens the export sheet scoped to that part of the client
     * with "Bot pack (ZIP)" preselected. Nothing at all for the client seat, or when the count is 0.
     *   $o: count (int, from cexpPageCounts()), label ('Export {n} open' — the text either side of {n} hides on phones,
     *       leaving the icon + the number), aria ('Export 6 open comments on Klever AT2'), tire, series (id | 'ref'),
     *       area (tires | library | posts), client (slug — another client than the page's, e.g. the unscoped Inbox),
     *       client_name, scope ('all'), class (extra classes), attrs.
     */
    function commentExportPackButton(array $o): string
    {
        if (!function_exists('isAdmin') || !isAdmin()) return '';
        $n = (int)($o['count'] ?? 0);
        if ($n <= 0) return '';
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $label = (string)($o['label'] ?? 'Export {n} open');
        [$before, $after] = array_pad(explode('{n}', $label, 2), 2, '');
        $attrs = ' data-cexp-open data-cexp-pack data-cexp-format="botpack" data-cexp-open-count="' . $n . '"';
        foreach (['tire' => 'data-cexp-tire', 'series' => 'data-cexp-series', 'area' => 'data-cexp-area', 'client' => 'data-cexp-client', 'client_name' => 'data-cexp-client-name'] as $k => $a) {
            if (isset($o[$k]) && (string)$o[$k] !== '' && (string)$o[$k] !== '0') $attrs .= ' ' . $a . '="' . $e($o[$k]) . '"';
        }
        if (($o['scope'] ?? '') === 'all') $attrs .= ' data-cexp-scope="all"';
        foreach ((array)($o['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . ($v === '' ? '' : '="' . $e($v) . '"');
        }
        $aria = trim((string)($o['aria'] ?? '')) ?: trim(str_replace('{n}', (string)$n, $label));
        $ico = function_exists('icon') ? icon('bubble') : '';
        return '<button type="button" class="ui-btn ui-btn--sm ui-btn--gray cexp-btn' . (!empty($o['class']) ? ' ' . $e($o['class']) : '') . '"' . $attrs
             . ' aria-label="' . $e($aria) . '" title="' . $e($aria . ' — a bot pack (ZIP): the images, their comments and manifest.json') . '">'
             . $ico . '<span class="cexp-btn-label">' . ($before !== '' ? '<span class="cexp-btn-text">' . $e($before) . '</span>' : '')
             . '<span class="cexp-btn-n" data-cexp-n>' . $n . '</span>'
             . ($after !== '' ? '<span class="cexp-btn-text">' . $e($after) . '</span>' : '') . '</span></button>';
    }
}
