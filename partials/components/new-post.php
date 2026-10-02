<?php
/**
 * New post pop-up — the boot tags every admin page carries (layout-bottom.php), so the "+ New" menu,
 * "New post" buttons, Assets "Create post with N", the viewer's "Use in post" and Posts "Edit post…"
 * open the same sheet anywhere. Nothing is rendered for the client seat.
 *
 *   newPostBootHtml(?array $client): string
 *       window.NewPostConfig = {endpoint (post-compose.php), base, client (scoped slug or ''), maxImageMb,
 *       maxVideoMb, css: [posts.css, newpost.css]} + chunk-upload.js, carousel.js, newpost.js (deferred).
 *   newPostUrl(array $extra = []): string
 *       Deep link that opens the pop-up on load: posts.php?client=…&newpost=1 (+ 'newpost' => 'upload' |
 *       'edit' with 'post' => ID, 'newpost_assets' => 'tire:1,library:4').
 */
if (!function_exists('newPostBootHtml')) {
    function newPostBootHtml(?array $client): string
    {
        if (!function_exists('isAdmin') || !isAdmin()) return '';
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $cfg = [
            'endpoint'   => basePath() . '/post-compose.php',
            'base'       => basePath(),
            'client'     => (string)($client['slug'] ?? ''),
            'maxImageMb' => function_exists('uploadMaxBytes') ? (int)(uploadMaxBytes('image') / 1048576) : 50,
            'maxVideoMb' => function_exists('uploadMaxBytes') ? (int)(uploadMaxBytes('video') / 1048576) : 4096,
            'css'        => [staticUrl('css/posts.css'), staticUrl('css/newpost.css')],
        ];
        return '<script>window.NewPostConfig = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . $e(staticUrl('js/chunk-upload.js')) . '" defer></script>' . "\n"
             . '<script src="' . $e(staticUrl('js/carousel.js')) . '" defer></script>' . "\n"
             . '<script src="' . $e(staticUrl('js/newpost.js')) . '" defer></script>' . "\n";
    }
}

if (!function_exists('newPostUrl')) {
    function newPostUrl(array $extra = []): string
    {
        return clientUrl('posts.php', array_merge(['newpost' => '1'], $extra));
    }
}
