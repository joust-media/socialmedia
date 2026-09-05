<?php
// Not a page: only meaningful when included from a page that loaded helpers.php.
if (!function_exists('esc')) { http_response_code(404); exit; }
/**
 * Page shell — top half. Include at the top level of a page AFTER db.php,
 * helpers.php and any redirect/role logic (this partial starts output).
 *
 *   $pageTitle = 'Posts';
 *   include __DIR__ . '/partials/layout-top.php';
 *   … page body …
 *   include __DIR__ . '/partials/layout-bottom.php';
 *
 * Variables read from the including scope (all optional except $pageTitle):
 *   $pageTitle    large title text
 *   $htmlTitle    <title>; defaults to "$pageTitle — <client name>"
 *   $navSubtitle  eyebrow above the large title
 *   $navBack      ['href' => …, 'label' => …] leading back button
 *   $navLeading   raw HTML for the leading slot
 *   $navTrailing  raw HTML for the top-right slot (default: client avatar)
 *   $navLinks     [['label', 'href', 'primary' => bool], …] small button row under the title
 *   $activeTab    'home'|'assets'|'posts'|'projects'|'studio' (default: from script name)
 *   $pageWide     true → 1200px content column (grids); default 720px
 *   $bodyClass    extra <body> classes
 *   $headExtra    raw HTML appended to <head> (page-specific <style>/<link>)
 */
$pageTitle = isset($pageTitle) ? (string)$pageTitle : (($client['name'] ?? null) ?: 'Joust');
if (!isset($htmlTitle)) {
    $htmlTitle = $pageTitle;
    if (!empty($client['name']) && $client['name'] !== $pageTitle) {
        $htmlTitle .= ' — ' . $client['name'];
    }
}
$uiRole = isAdmin() ? 'admin' : 'client';
?>
<!DOCTYPE html>
<html lang="en" class="ui-shell">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="format-detection" content="telephone=no">
<title><?= esc($htmlTitle) ?></title>
<?= appStylesheets() ?>
<?= isset($headExtra) ? $headExtra : '' ?>
</head>
<body class="<?= esc(trim('ui-body ' . (isset($bodyClass) ? (string)$bodyClass : ''))) ?>" data-role="<?= $uiRole ?>" data-actor="<?= $uiRole ?>"<?= !empty($client['slug']) ? ' data-client="' . esc($client['slug']) . '"' : '' ?>>
<?php include __DIR__ . '/navbar.php'; ?>
<main class="ui-page<?= !empty($pageWide) ? ' ui-page--wide' : '' ?>" id="main">
