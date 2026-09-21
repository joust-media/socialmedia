<?php
/**
 * Horizontal bars — by-type breakdown, the phone-sized "biggest clients" list, any label → size list.
 *
 *   driveBars(array $rows, array $opts = []): string
 *       row:  ['label' => 'Video', 'bytes' => 980e9, 'href' => '…' (optional), 'sub' => 'kenda' (optional),
 *              'pct' => 64.5 (optional, shown after the size), 'attrs' => []]
 *       opts: 'max' (bytes = 100 %; default the largest row), 'class', 'attrs', 'ariaLabel', 'tone' (accent|warning)
 *   Bars are ≤ 8px thick with a rounded data end; text uses the label tokens, only the mark is coloured.
 */
if (!function_exists('driveBars')) {
    function driveBars(array $rows, array $opts = []): string
    {
        $rows = array_values(array_filter($rows, static fn($r) => isset($r['label'])));
        $max  = isset($opts['max']) && (float)$opts['max'] > 0 ? (float)$opts['max'] : 0.0;
        if ($max <= 0) foreach ($rows as $r) $max = max($max, (float)($r['bytes'] ?? 0));
        $cls = 'drive-bars' . (!empty($opts['class']) ? ' ' . $opts['class'] : '') . (!empty($opts['tone']) ? ' drive-bars--' . preg_replace('/[^a-z]/', '', (string)$opts['tone']) : '');
        $attrs = '';
        foreach (($opts['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . '="' . esc($v) . '"';
        }
        $aria = !empty($opts['ariaLabel']) ? ' aria-label="' . esc($opts['ariaLabel']) . '"' : '';
        $out = '<ul class="' . esc($cls) . '"' . $aria . $attrs . ' data-drive-bars="' . count($rows) . '">';
        foreach ($rows as $r) {
            $b = max(0.0, (float)($r['bytes'] ?? 0));
            $w = $max > 0 ? min(100.0, 100.0 * $b / $max) : 0.0;
            $rattrs = '';
            foreach (($r['attrs'] ?? []) as $k => $v) {
                $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
                if ($k !== '') $rattrs .= ' ' . $k . '="' . esc($v) . '"';
            }
            $label = esc((string)$r['label']);
            if (!empty($r['href'])) $label = '<a href="' . esc($r['href']) . '">' . $label . '</a>';
            $value = esc(driveUiBytes($b)) . (isset($r['pct']) && $r['pct'] !== null ? ' <span class="text-tertiary">' . esc(driveUiPct($r['pct'], (float)$r['pct'] < 10 ? 1 : 0)) . '</span>' : '');
            $out .= '<li class="drive-bar" data-bytes="' . esc((string)(int)round($b)) . '"' . $rattrs . '>'
                  . '<span class="drive-bar-label">' . $label . (!empty($r['sub']) ? ' <span class="drive-bar-sub">' . esc($r['sub']) . '</span>' : '') . '</span>'
                  . '<span class="drive-bar-track" aria-hidden="true"><span class="drive-bar-fill" style="width:' . esc(number_format($w, 2, '.', '')) . '%"></span></span>'
                  . '<span class="drive-bar-value">' . $value . '</span>'
                  . '</li>';
        }
        return $out . '</ul>';
    }
}
