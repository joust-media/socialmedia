<?php
/**
 * Home "Needs your attention" cards (spec §4.1 — iOS Wallet-style stack).
 *
 *   actionCard([
 *     'count'  => 4,                        // int; the bold lead ("4 posts")
 *     'noun'   => 'post',                   // singular; plural = noun + 's' unless 'nouns' given
 *     'one'    => 'is ready for your review',  // rest of the sentence when count === 1
 *     'many'   => 'ready for your review',     // rest of the sentence otherwise
 *     'href'   => clientUrl('posts', ['status' => 'pending']),
 *     'icon'   => 'grid',                   // icon() name
 *     'subtitle' => 'Posts · To Review',    // optional footnote
 *     'tone'   => 'accent',                 // accent | pending | scheduled (icon tile colour)
 *     'index'  => 0,                        // position in the stack (sets --i)
 *   ]);
 *
 *   actionCardStack(array $cards): string   → wraps rendered cards in the stack container
 *   actionCardCaughtUp(): string            → the quiet zero-state card (checkmark icon, no emoji)
 *
 * All output is escaped here; callers pass plain strings.
 */
if (!function_exists('actionCard')) {
    function actionCard(array $o): string
    {
        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $count = max(0, (int)($o['count'] ?? 0));
        $noun  = (string)($o['noun'] ?? 'item');
        $nouns = (string)($o['nouns'] ?? ($noun . 's'));
        $lead  = $count . ' ' . ($count === 1 ? $noun : $nouns);
        $rest  = $count === 1 ? (string)($o['one'] ?? ($o['many'] ?? '')) : (string)($o['many'] ?? '');
        $tone  = preg_replace('/[^a-z\-]/', '', strtolower((string)($o['tone'] ?? 'accent'))) ?: 'accent';
        $idx   = (int)($o['index'] ?? 0);
        $href  = (string)($o['href'] ?? '#');
        $icon  = function_exists('icon') ? icon((string)($o['icon'] ?? 'grid')) : '';
        $chev  = function_exists('icon') ? icon('chevron-right', 'action-card-chevron') : '';

        $out  = '<a class="action-card action-card--' . $esc($tone) . '" href="' . $esc($href) . '" style="--i:' . $idx . '">';
        $out .= '<span class="action-card-icon" aria-hidden="true">' . $icon . '</span>';
        $out .= '<span class="action-card-body">';
        $out .= '<span class="action-card-title"><strong>' . $esc($lead) . '</strong>' . ($rest !== '' ? ' ' . $esc($rest) : '') . '</span>';
        if (!empty($o['subtitle'])) {
            $out .= '<span class="action-card-sub">' . $esc($o['subtitle']) . '</span>';
        }
        $out .= '</span>';
        $out .= $chev;
        $out .= '</a>';
        return $out;
    }
}

if (!function_exists('actionCardStack')) {
    function actionCardStack(array $cardsHtml): string
    {
        $n = count($cardsHtml);
        if ($n === 0) return '';
        return '<div class="home-stack" style="--n:' . $n . '" role="list">' . implode('', $cardsHtml) . '</div>';
    }
}

if (!function_exists('actionCardCaughtUp')) {
    function actionCardCaughtUp(string $title = "You're all caught up", string $sub = 'Nothing needs your review right now.'): string
    {
        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $icon = function_exists('icon') ? icon('checkmark') : '';
        return '<div class="ui-card ui-card--quiet home-caught-up" role="status">'
             . '<span class="home-caught-up-icon" aria-hidden="true">' . $icon . '</span>'
             . '<span class="home-caught-up-body">'
             . '<span class="home-caught-up-title">' . $esc($title) . '</span>'
             . ($sub !== '' ? '<span class="home-caught-up-sub">' . $esc($sub) . '</span>' : '')
             . '</span></div>';
    }
}
