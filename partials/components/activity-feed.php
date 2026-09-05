<?php
/**
 * Humanized activity feed as a grouped inset list (spec §4.1).
 *
 *   $rows = collapseActivityRuns(humanizeActivityRows(recentActivity($pdo, $cid, 40), $role, $client));
 *   echo activityFeed($rows, ['header' => 'Activity', 'limit' => 20]);
 *
 * Row shape = the output of humanizeActivityRows() / collapseActivityRuns()
 * in helpers.php (text, html, href, icon, tone, time_rel, time_abs, count,
 * children[], edits[]). Nothing here reads `summary`, `detail` or `_meta`,
 * so a filename can only appear if the helpers put one in `html` — and they
 * never do (see activityParentName()).
 *
 * Rows with two or more comments get a native <details> disclosure
 * ("3 comments") that expands inline; no JS required.
 */
if (!function_exists('activityFeed')) {
    function activityFeed(array $rows, array $opts = []): string
    {
        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $header = array_key_exists('header', $opts) ? (string)$opts['header'] : 'Activity';
        $limit  = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 20;
        $rows   = array_slice($rows, 0, $limit);
        $empty  = (string)($opts['empty'] ?? 'No activity yet. Approvals, comments and new work will show up here.');

        $out = '<section class="ui-list-group home-activity"' . (!empty($opts['id']) ? ' id="' . $esc($opts['id']) . '"' : '') . '>';
        if ($header !== '') {
            $out .= '<h2 class="ui-list-header">' . $esc($header) . '</h2>';
        }
        if (!$rows) {
            return $out . '<div class="ui-list"><div class="ui-empty">' . $esc($empty) . '</div></div></section>';
        }

        $out .= '<ul class="ui-list" role="list">';
        foreach ($rows as $r) {
            $tone     = preg_replace('/[^a-z\-]/', '', strtolower((string)($r['tone'] ?? 'neutral'))) ?: 'neutral';
            $iconName = (string)($r['icon'] ?? 'ellipsis');
            $iconHtml = function_exists('icon') ? icon($iconName) : '';
            $href     = (string)($r['href'] ?? '#');
            $children = array_values((array)($r['children'] ?? []));
            $hasDisc  = count($children) >= 2;
            $meta     = [];
            if (!empty($r['time_rel'])) $meta[] = (string)$r['time_rel'];
            if (!empty($r['edits']))    $meta[] = implode(', ', array_map('strval', (array)$r['edits']));
            if (!empty($r['company_name']) && !empty($opts['showCompany'])) array_unshift($meta, (string)$r['company_name']);

            $inner  = '<div class="ui-row-leading ui-row-leading--icon activity-icon activity-icon--' . $esc($tone) . '" aria-hidden="true">' . $iconHtml . '</div>';
            $inner .= '<div class="ui-row-body">';
            $title  = '<span class="ui-row-title ui-row-title--wrap activity-title">' . (string)($r['html'] ?? $esc($r['text'] ?? '')) . '</span>';
            $inner .= $hasDisc ? '<a class="activity-link" href="' . $esc($href) . '">' . $title . '</a>' : $title;
            if ($meta) {
                $inner .= '<div class="ui-row-subtitle activity-meta"><time title="' . $esc($r['time_abs'] ?? '') . '">' . $esc(array_shift($meta)) . '</time>'
                        . ($meta ? ' · ' . $esc(implode(' · ', $meta)) : '') . '</div>';
            }
            if ($hasDisc) {
                $n = count($children);
                $inner .= '<details class="activity-disclosure">'
                        . '<summary><span>' . $n . ' comments</span>' . (function_exists('icon') ? icon('chevron-down', 'activity-disclosure-chevron') : '') . '</summary>'
                        . '<ul class="activity-comments" role="list">';
                foreach ($children as $c) {
                    $q = (string)($c['text'] ?? '');
                    $inner .= '<li><a class="activity-comment" href="' . $esc($c['href'] ?? $href) . '">'
                            . '<q>' . $esc($q) . '</q>'
                            . (!empty($c['time_rel']) ? '<span class="activity-comment-time">' . $esc($c['time_rel']) . '</span>' : '')
                            . '</a></li>';
                }
                $inner .= '</ul></details>';
            }
            $inner .= '</div>';
            $inner .= '<div class="ui-row-trailing">' . (function_exists('icon') ? icon('chevron-right', 'ui-row-chevron') : '') . '</div>';

            $cls = 'ui-row ui-row--leading-sm activity-item activity-item--' . $esc($tone) . ($hasDisc ? ' activity-item--has-disclosure' : '');
            $attrs = ' class="' . $cls . '" data-entity="' . $esc(($r['entity_type'] ?? '') . ':' . ($r['entity_id'] ?? '')) . '"';
            $out .= '<li>' . ($hasDisc
                ? '<div' . $attrs . '>' . $inner . '</div>'
                : '<a href="' . $esc($href) . '"' . $attrs . '>' . $inner . '</a>') . '</li>';
        }
        $out .= '</ul>';
        if (!empty($opts['footer'])) {
            $out .= '<p class="ui-list-footer">' . $esc($opts['footer']) . '</p>';
        }
        return $out . '</section>';
    }
}
