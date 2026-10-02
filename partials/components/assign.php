<?php
/**
 * Assign — the admin's "where does this email / page belong" actions (assign.php is the endpoint,
 * static/js/assign.js the sheets). Nothing here is ever emitted for the client seat: every helper
 * checks isAdmin() server-side (or takes 'admin' => false from its caller).
 *
 *   assignBootHtml(?array $client): string
 *       layout-bottom.php, every admin page: window.AssignConfig = {endpoint, base, client, emailsUrl,
 *       pagesUrl, newEmailUrl, newPageUrl} + assign.css + assign.js (deferred). assign.js registers
 *       App.newMenu.handle('email' | 'page') so "+ New → New email / New page" opens a sheet in place
 *       (client · title · HTML file, paste or link); the menu item's href stays the no-JS fallback.
 *
 *   assignMenuItemsHtml(string $kind, int $id, string $label): string
 *       The <button role="menuitem" data-assign="move|flow|audiences" data-kind data-ids data-label>
 *       items (emails: Move to client… · Add to flow… · Set audiences…; pages: Move to client…).
 *
 *   assignMenuHtml(string $kind, int $id, string $label, array $opts = []): string
 *       A ⋯ button ([data-asg-menu-toggle]) + its menu ([data-asg-menu], .pd-menu look). $opts:
 *       'class' extra wrapper class ('asg-row-more' on list rows), 'editUrl' adds an "Edit …" link,
 *       'extra' trusted menu markup appended last (the detail sheets' review items), 'admin' (default isAdmin()).
 *
 *   assignSelectButtonHtml(string $kind): string
 *       The list header's "Select" toggle (multi-select → bulk bar: Move · Add to flow · Audiences).
 */

if (!function_exists('assignBootHtml')) {
    function assignBootHtml(?array $client): string
    {
        if (!function_exists('isAdmin') || !isAdmin()) return '';
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $slug = (string)($client['slug'] ?? '');
        $cfg = [
            'endpoint'    => basePath() . '/assign.php',
            'base'        => basePath(),
            'client'      => $slug,
            'clientName'  => (string)($client['name'] ?? ''),
            'emailsUrl'   => pagePath('emails'),
            'pagesUrl'    => pagePath('pages'),
            'newEmailUrl' => pagePath('add-email'),
            'newPageUrl'  => pagePath('add-page'),
        ];
        return '<link rel="stylesheet" href="' . $e(staticUrl('css/assign.css')) . '">' . "\n"
             . '<script>window.AssignConfig = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . $e(staticUrl('js/assign.js')) . '" defer></script>' . "\n";
    }
}

if (!function_exists('assignMenuItemsHtml')) {
    function assignMenuItemsHtml(string $kind, int $id, string $label): string
    {
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $kind = $kind === 'page' ? 'page' : 'email';
        $data = ' data-kind="' . $kind . '" data-ids="' . $id . '" data-label="' . $e($label) . '"';
        $out  = '<button type="button" role="menuitem" data-assign="move"' . $data . '>Move to client…</button>';
        if ($kind === 'email') {
            $out .= '<button type="button" role="menuitem" data-assign="flow"' . $data . '>Add to flow…</button>';
            $out .= '<button type="button" role="menuitem" data-assign="audiences"' . $data . '>Set audiences…</button>';
        }
        return $out;
    }
}

if (!function_exists('assignMenuHtml')) {
    function assignMenuHtml(string $kind, int $id, string $label, array $opts = []): string
    {
        $admin = array_key_exists('admin', $opts) ? (bool)$opts['admin'] : (function_exists('isAdmin') && isAdmin());
        if (!$admin || $id <= 0) return '';
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $kind  = $kind === 'page' ? 'page' : 'email';
        $class = trim('pd-more asg-more ' . (string)($opts['class'] ?? ''));
        $ico   = function_exists('icon') ? icon('ellipsis') : '&hellip;';
        $out  = '<div class="' . $e($class) . '" data-asg-more="' . $kind . ':' . $id . '">';
        $out .= '<button type="button" class="ui-btn ui-btn--gray ui-btn--icon ui-btn--sm asg-more-btn" data-asg-menu-toggle'
              . ' aria-haspopup="menu" aria-expanded="false" aria-label="' . $e('More actions for ' . $label) . '">' . $ico . '</button>';
        $out .= '<div class="pd-menu asg-menu" role="menu" data-asg-menu hidden>';
        $out .= assignMenuItemsHtml($kind, $id, $label);
        if (!empty($opts['editUrl'])) {
            $out .= '<a role="menuitem" class="asg-menu-link" href="' . $e($opts['editUrl']) . '">Edit ' . $kind . '…</a>';
        }
        if (!empty($opts['extra'])) $out .= (string)$opts['extra'];   // trusted markup from the caller (review-actions.php)
        $out .= '</div></div>';
        return $out;
    }
}

if (!function_exists('assignSelectButtonHtml')) {
    function assignSelectButtonHtml(string $kind): string
    {
        if (!function_exists('isAdmin') || !isAdmin()) return '';
        $kind = $kind === 'page' ? 'page' : 'email';
        return '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm asg-select-btn" data-asg-select="' . $kind . '" aria-pressed="false">Select</button>';
    }
}
