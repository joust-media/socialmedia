<?php
// Not a page: only meaningful when included from a page that loaded helpers.php.
if (!function_exists('esc')) { http_response_code(404); exit; }
/**
 * Page shell — bottom half. Closes <main>, renders the role-aware tab bar,
 * the generic sheet shell and toast, then loads app.js.
 *
 * Optional variables from the including scope:
 *   $showTabs      false → no tab bar (default: shown when a client is scoped or the visitor is admin)
 *   $includeSheet  false → skip the #uiSheet shell
 *   $footExtra     raw HTML before the closing </body> (page-specific <script>)
 */
?>
</main>
<?php
$uiShowTabs = isset($showTabs) ? (bool)$showTabs : (!empty($client) || isAdmin());
if ($uiShowTabs) {
    include __DIR__ . '/tabbar.php';
}
if (!isset($includeSheet) || $includeSheet) {
    include __DIR__ . '/sheet.php';
}
unset($uiShowTabs);
?>
<div class="ui-toast" id="uiToast" role="status" aria-live="polite"></div>
<?= isset($footExtra) ? $footExtra : '' ?>
<?php
// Admin: the New post pop-up (App.newPost) on every page — "+ New", "New post", Assets "Create post with N", "Edit post…"
require_once __DIR__ . '/components/new-post.php';
echo newPostBootHtml($client ?? null);
// Admin: the Upload sheet (App.uploadSheet) — "+ New → Upload" and every contextual "Upload" button
require_once __DIR__ . '/components/upload-sheet.php';
echo uploadSheetBootHtml($client ?? null);
?>
<?= appScript() ?>
<script src="<?= esc(staticUrl('js/video.js')) ?>" defer></script>

</body>
</html>
