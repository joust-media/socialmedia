<?php
/**
 * Grouped inset list (iOS "Inset Grouped" table).
 *
 *   echo insetListOpen('Open');                       // <section><h2 header><ul class="ui-list">
 *   echo insetRow([
 *     'href'     => clientUrl('feed.php', ['post' => 75]),   // omit → static row; 'button' => true → <button>
 *     'leading'  => '<img src="…" alt="">',                  // HTML, 56px box; or 'icon' => 'calendar' for a 30px tinted icon
 *     'title'    => 'Warhawk',
 *     'subtitle' => '3 to review · 5 approved',
 *     'trailing' => statusPill('pending'),                   // HTML
 *     'chevron'  => true,
 *     'attrs'    => ['id' => 'post-75', 'data-id' => 75],
 *     'class'    => '',
 *     'wrap'     => false,                                   // allow title to wrap
 *   ]);
 *   echo insetListClose('Footnote text');             // </ul>[<p footer>]</section>
 */
if (!function_exists('insetListOpen')) {
    function insetListOpen(string $header = '', array $opts = []): string
    {
        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $cls = 'ui-list-group' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
        $attrs = '';
        foreach (($opts['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . '="' . $esc($v) . '"';
        }
        $out = '<section class="' . $esc($cls) . '"' . $attrs . '>';
        if ($header !== '') {
            $out .= '<h2 class="ui-list-header">' . (!empty($opts['raw']) ? $header : $esc($header)) . '</h2>';
        }
        $listCls = 'ui-list' . (!empty($opts['listClass']) ? ' ' . $opts['listClass'] : '');
        $listId  = !empty($opts['id']) ? ' id="' . $esc($opts['id']) . '"' : '';
        return $out . '<ul class="' . $esc($listCls) . '"' . $listId . ' role="list">';
    }
}

if (!function_exists('insetListClose')) {
    function insetListClose(string $footer = '', bool $raw = false): string
    {
        $out = '</ul>';
        if ($footer !== '') {
            $out .= '<p class="ui-list-footer">'
                  . ($raw ? $footer : htmlspecialchars($footer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                  . '</p>';
        }
        return $out . '</section>';
    }
}

if (!function_exists('insetRow')) {
    function insetRow(array $row): string
    {
        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $hasLeading = !empty($row['leading']) || !empty($row['icon']);
        $cls = 'ui-row';
        if (!empty($row['icon']) && empty($row['leading'])) $cls .= ' ui-row--leading-sm';
        elseif ($hasLeading)                                 $cls .= ' ui-row--leading';
        if (!empty($row['class'])) $cls .= ' ' . $row['class'];

        $attrs = ' class="' . $esc($cls) . '"';
        foreach (($row['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . '="' . $esc($v) . '"';
        }

        $inner = '';
        if (!empty($row['leading'])) {
            $inner .= '<div class="ui-row-leading">' . $row['leading'] . '</div>';
        } elseif (!empty($row['icon']) && function_exists('icon')) {
            $tint = !empty($row['iconStyle']) ? ' style="' . $esc($row['iconStyle']) . '"' : '';
            $inner .= '<div class="ui-row-leading ui-row-leading--icon"' . $tint . '>' . icon($row['icon']) . '</div>';
        }

        $inner .= '<div class="ui-row-body">';
        if (isset($row['title']) && $row['title'] !== '') {
            $tcls = 'ui-row-title' . (!empty($row['wrap']) ? ' ui-row-title--wrap' : '');
            $inner .= '<div class="' . $tcls . '">' . (!empty($row['rawTitle']) ? $row['title'] : $esc($row['title'])) . '</div>';
        }
        if (isset($row['subtitle']) && $row['subtitle'] !== '') {
            $inner .= '<div class="ui-row-subtitle">' . (!empty($row['rawSubtitle']) ? $row['subtitle'] : $esc($row['subtitle'])) . '</div>';
        }
        $inner .= '</div>';

        $trailing = (string)($row['trailing'] ?? '');
        $chevron  = !empty($row['chevron']) || (!empty($row['href']) && !isset($row['chevron']));
        if ($trailing !== '' || $chevron) {
            $inner .= '<div class="ui-row-trailing">' . $trailing
                    . ($chevron && function_exists('icon') ? icon('chevron-right', 'ui-row-chevron') : '')
                    . '</div>';
        }

        if (!empty($row['href'])) {
            $tag = '<a href="' . $esc($row['href']) . '"' . $attrs . '>' . $inner . '</a>';
        } elseif (!empty($row['button'])) {
            $tag = '<button type="button"' . $attrs . '>' . $inner . '</button>';
        } else {
            $tag = '<div' . $attrs . '>' . $inner . '</div>';
        }
        return '<li>' . $tag . '</li>';
    }
}
