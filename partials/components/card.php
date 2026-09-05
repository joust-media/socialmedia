<?php
/**
 * card(string $body, array $opts = []): string
 *
 * Rounded card (--radius-card) on the elevated surface.
 *   $opts:
 *     'title'    → headline text (escaped)           'rawTitle' => true to pass HTML
 *     'subtitle' → secondary line (escaped)          'rawSubtitle' => true
 *     'header'   → HTML placed right of the title (e.g. a pill or "See all" link)
 *     'footer'   → HTML footer row
 *     'href'     → renders the card as a link (adds .ui-card--link)
 *     'variant'  → 'action' (accent), 'quiet' (tertiary fill), 'flat' (no shadow)
 *     'big'      → true → Title 2 sized title
 *     'class', 'attrs'
 *
 *   echo card('<p>4 posts are waiting for you.</p>', ['title' => 'Posts to review', 'href' => $url, 'variant' => 'action']);
 */
if (!function_exists('card')) {
    function card(string $body, array $opts = []): string
    {
        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $cls = 'ui-card';
        if (!empty($opts['href']))    $cls .= ' ui-card--link';
        if (!empty($opts['variant'])) $cls .= ' ui-card--' . preg_replace('/[^a-z\-]/', '', strtolower($opts['variant']));
        if (!empty($opts['class']))   $cls .= ' ' . $opts['class'];

        $attrs = ' class="' . $esc($cls) . '"';
        foreach (($opts['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . '="' . $esc($v) . '"';
        }

        $inner = '';
        $hasTitle = isset($opts['title']) && $opts['title'] !== '';
        $hasSub   = isset($opts['subtitle']) && $opts['subtitle'] !== '';
        if ($hasTitle || $hasSub || !empty($opts['header'])) {
            $inner .= '<div class="ui-card-header"><div class="ui-card-heading">';
            if ($hasTitle) {
                $tcls = 'ui-card-title' . (!empty($opts['big']) ? ' ui-card-title--big' : '');
                $inner .= '<h3 class="' . $tcls . '">' . (!empty($opts['rawTitle']) ? $opts['title'] : $esc($opts['title'])) . '</h3>';
            }
            if ($hasSub) {
                $inner .= '<p class="ui-card-subtitle">' . (!empty($opts['rawSubtitle']) ? $opts['subtitle'] : $esc($opts['subtitle'])) . '</p>';
            }
            $inner .= '</div>';
            if (!empty($opts['header'])) $inner .= '<div class="ui-card-aside">' . $opts['header'] . '</div>';
            $inner .= '</div>';
        }
        if ($body !== '') $inner .= '<div class="ui-card-body">' . $body . '</div>';
        if (!empty($opts['footer'])) $inner .= '<div class="ui-card-footer">' . $opts['footer'] . '</div>';

        if (!empty($opts['href'])) {
            return '<a href="' . $esc($opts['href']) . '"' . $attrs . '>' . $inner . '</a>';
        }
        return '<div' . $attrs . '>' . $inner . '</div>';
    }
}
