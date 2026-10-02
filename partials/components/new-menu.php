<?php
/**
 * "+ New" — the admin's one create menu, in the nav bar's trailing slot on every app page
 * (navbar.php puts it left of the Appearance button). Never emitted for the client seat:
 * the isAdmin() check is server-side.
 *
 *   newMenuItems(?array $client, ?PDO $pdo): array   [['action','label','sub','icon','href'], …]
 *   newMenuHtml(?array $client, ?PDO $pdo): string   '' for the client seat
 *
 * Items (each <a role="menuitem" data-new-action="…" href="…"> — the href is the working fallback):
 *   post    New post   always → the New post pop-up (App.newPost; href posts.php?newpost=1, unscoped: its client chooser)
 *   upload  Upload     always → the Upload sheet (App.uploadSheet: tire series / Reference / Library / New post;
 *                      registered through App.newMenu.handle('upload'); href posts.php?upload=1)
 *   email   New email  only when the scoped client has Emails (companyHasEmails())
 *   page    New page   only when the scoped client has Pages (companyHasPages())
 *
 * JS contract (app.js App.newMenu): a click on an item first dispatches a cancelable
 * `app:new` event on document — detail {action, href, item, client}. A handler registered with
 * App.newMenu.handle(action, fn) runs first; fn(detail) returning true (or calling
 * event.preventDefault()) keeps the browser on the page, otherwise the href is followed.
 * Built-in: action "post" calls App.newPost.open(detail) when a page has defined it.
 */

if (!function_exists('newMenuItems')) {
    function newMenuItems(?array $client, ?PDO $pdo): array
    {
        $scoped = !empty($client['slug']);
        $items  = [];
        $items[] = [
            'action' => 'post',
            'label'  => 'New post',
            'sub'    => 'Approved images or new files, saved as a draft',
            'icon'   => 'grid',
            // no-JS fallback: Posts with the New post pop-up open (newpost.js; unscoped → its client chooser)
            'href'   => $scoped ? clientUrl('posts.php', ['newpost' => 1]) : pagePath('posts') . '?newpost=1',
        ];
        $items[] = [
            'action' => 'upload',
            'label'  => 'Upload',
            'sub'    => 'Images or video — to a tire, the Library or a new post',
            'icon'   => 'upload',
            // no-JS fallback: Posts with the Upload sheet open (upload-sheet.js; unscoped → its client chooser)
            'href'   => $scoped ? uploadSheetUrl('posts.php') : pagePath('posts') . '?upload=1',
        ];
        if ($scoped && $pdo) {
            $hasEmails = false; $hasPages = false;
            try { $hasEmails = function_exists('companyHasEmails') && companyHasEmails($client, $pdo); } catch (Throwable $e) { $hasEmails = false; }
            try { $hasPages  = function_exists('companyHasPages') && companyHasPages($client, $pdo); } catch (Throwable $e) { $hasPages = false; }
            if ($hasEmails) {
                $items[] = ['action' => 'email', 'label' => 'New email', 'sub' => 'Add an email for review',
                            'icon' => 'mail', 'href' => clientUrl('add-email.php')];
            }
            if ($hasPages) {
                $items[] = ['action' => 'page', 'label' => 'New page', 'sub' => 'Add a landing page for review',
                            'icon' => 'page', 'href' => clientUrl('add-page.php')];
            }
        }
        return $items;
    }
}

if (!function_exists('newMenuHtml')) {
    function newMenuHtml(?array $client, ?PDO $pdo): string
    {
        if (!(function_exists('isAdmin') && isAdmin())) return '';
        $items = newMenuItems($client, $pdo);
        if (!$items) return '';
        $out  = '<div class="ui-newmenu" data-new-menu' . (!empty($client['slug']) ? ' data-client="' . esc($client['slug']) . '"' : '') . '>';
        $out .= '<button type="button" class="ui-btn ui-btn--filled ui-btn--sm ui-newmenu-btn" data-new-menu-toggle'
              . ' aria-haspopup="menu" aria-expanded="false" aria-controls="uiNewMenu" aria-label="New">'
              . icon('plus') . '<span class="ui-newmenu-label">New</span></button>';
        $out .= '<div class="ui-newmenu-panel" id="uiNewMenu" role="menu" aria-label="Create" data-new-menu-panel hidden>';
        foreach ($items as $it) {
            $out .= '<a class="ui-newmenu-item" role="menuitem" href="' . esc($it['href']) . '" data-new-action="' . esc($it['action']) . '">'
                  . '<span class="ui-newmenu-icon">' . icon($it['icon']) . '</span>'
                  . '<span class="ui-newmenu-text"><span class="ui-newmenu-title">' . esc($it['label']) . '</span>'
                  . '<span class="ui-newmenu-sub">' . esc($it['sub']) . '</span></span>'
                  . '</a>';
        }
        $out .= '</div></div>';
        return $out;
    }
}
