<?php
/**
 * Upload sheet — the one uploader (static/js/upload-sheet.js, App.uploadSheet). Every admin page carries the boot
 * tags (layout-bottom.php), so "+ New → Upload" and every contextual "Upload" button open the same sheet.
 * Nothing is rendered for the client seat.
 *
 *   uploadSheetBootHtml(?array $client): string
 *       window.UploadSheetConfig = {endpoint (upload-sheet.php), base, client (scoped slug or ''), css: [upload.css]}
 *       + upload-sheet.js (deferred; chunk-upload.js comes with the New post boot tags).
 *   uploadSheetUrl(string $page, array $dest = [], array $extra = []): string
 *       Deep link that opens the sheet on load — the no-JS / retired-route fallback:
 *       <page>?client=…&upload=1[&dest=series|reference|library|post][&tire=<id>][&series=<id>|new][&each=1]
 *   uploadSheetAttrs(array $dest): string
 *       ' data-upload-open data-upload-dest="…" data-upload-tire="…" data-upload-series="…" data-upload-each="1"'
 *       for a contextual button (the destination arrives preselected).
 */
if (!function_exists('uploadSheetBootHtml')) {
    function uploadSheetBootHtml(?array $client): string
    {
        if (!function_exists('isAdmin') || !isAdmin()) return '';
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $cfg = [
            'endpoint' => basePath() . '/upload-sheet.php',
            'base'     => basePath(),
            'client'   => (string)($client['slug'] ?? ''),
            'css'      => [staticUrl('css/upload.css')],
        ];
        return '<script>window.UploadSheetConfig = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . $e(staticUrl('js/upload-sheet.js')) . '" defer></script>' . "\n";
    }
}

if (!function_exists('uploadSheetParams')) {
    /** dest = ['dest' => series|reference|library|post, 'tire' => id, 'series' => id|'new', 'each' => bool] → query params. */
    function uploadSheetParams(array $dest): array
    {
        $q = ['upload' => '1'];
        if (!empty($dest['dest']))   $q['dest']   = (string)$dest['dest'];
        if (!empty($dest['tire']))   $q['tire']   = (string)(int)$dest['tire'];
        if (!empty($dest['series'])) $q['series'] = $dest['series'] === 'new' ? 'new' : (string)(int)$dest['series'];
        if (!empty($dest['each']))   $q['each']   = '1';
        return $q;
    }
}

if (!function_exists('uploadSheetUrl')) {
    function uploadSheetUrl(string $page, array $dest = [], array $extra = []): string
    {
        return clientUrl($page, array_merge($extra, uploadSheetParams($dest)));
    }
}

if (!function_exists('uploadSheetAttrs')) {
    function uploadSheetAttrs(array $dest): string
    {
        $out = ' data-upload-open';
        $q = uploadSheetParams($dest);
        foreach (['dest' => 'data-upload-dest', 'tire' => 'data-upload-tire', 'series' => 'data-upload-series', 'each' => 'data-upload-each'] as $k => $attr) {
            if (isset($q[$k])) $out .= ' ' . $attr . '="' . htmlspecialchars($q[$k], ENT_QUOTES, 'UTF-8') . '"';
        }
        return $out;
    }
}
