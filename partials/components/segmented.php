<?php
/**
 * segmented(array $items, array $opts = []): string
 *
 * iOS segmented control. Items with an href render as links (server-side
 * filter navigation); items without render as buttons that app.js toggles.
 *
 *   $items = [
 *     ['label' => 'To Review', 'href' => '…', 'active' => true,  'count' => 4],
 *     ['label' => 'Approved',  'href' => '…', 'active' => false, 'count' => 2],
 *     ['label' => 'Denied',    'value' => 'denied'],            // button item
 *   ];
 *   $opts: 'class' (extra classes), 'auto' (bool: shrink to content), 'label' (aria-label)
 */
if (!function_exists('segmented')) {
    function segmented(array $items, array $opts = []): string
    {
        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $cls = 'ui-segmented';
        if (!empty($opts['auto']))  $cls .= ' ui-segmented--auto';
        if (!empty($opts['class'])) $cls .= ' ' . $opts['class'];
        $aria = !empty($opts['label']) ? ' aria-label="' . $esc($opts['label']) . '"' : '';

        $out = '<div class="' . $esc($cls) . '" role="tablist"' . $aria . '>';
        foreach ($items as $it) {
            $active = !empty($it['active']);
            $icls   = 'ui-segmented-item' . ($active ? ' is-active' : '') . (!empty($it['class']) ? ' ' . $it['class'] : '');
            $inner  = $esc($it['label'] ?? '');
            if (isset($it['count']) && $it['count'] !== null && $it['count'] !== '') {
                $inner .= ' <span class="ui-segmented-count">' . $esc($it['count']) . '</span>';
            }
            $attrs = '';
            foreach (($it['attrs'] ?? []) as $k => $v) {
                $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
                if ($k !== '') $attrs .= ' ' . $k . '="' . $esc($v) . '"';
            }
            if (isset($it['value'])) $attrs .= ' data-value="' . $esc($it['value']) . '"';

            if (!empty($it['href'])) {
                $out .= '<a class="' . $esc($icls) . '" role="tab" href="' . $esc($it['href']) . '"'
                      . ($active ? ' aria-selected="true" aria-current="page"' : ' aria-selected="false"')
                      . $attrs . '>' . $inner . '</a>';
            } else {
                $out .= '<button type="button" class="' . $esc($icls) . '" role="tab"'
                      . ' aria-selected="' . ($active ? 'true' : 'false') . '"'
                      . $attrs . '>' . $inner . '</button>';
            }
        }
        return $out . '</div>';
    }
}
