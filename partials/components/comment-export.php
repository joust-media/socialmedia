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
