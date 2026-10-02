<?php
/**
 * Module router (old features.php bookmarks) — 301 to the page that owns the module now, ?client= kept:
 *
 *   module=tires (or no module)  → assets.php?view=collections[&item=<tire id>]
 *                                   (the legacy ?tire=<id> alias still maps to item)
 *   module=emails                → emails.php
 *   module=pages                 → pages.php
 *   any other module             → assets.php?view=collections
 *
 * The old generic gallery (legacy/features.php) is retired; it now redirects through here too.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';   // resolves $client from ?client=

$moduleSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_GET['module'] ?? 'tires')));
if ($moduleSlug === 'emails' || $moduleSlug === 'pages') {
    header('Location: ' . clientUrl($moduleSlug . '.php'), true, 301);
    exit;
}

$extra = ['view' => 'collections'];
$item  = isset($_GET['item']) ? (int)$_GET['item'] : (isset($_GET['tire']) ? (int)$_GET['tire'] : 0);
if ($item > 0) { $extra['item'] = $item; }

header('Location: ' . clientUrl('assets.php', $extra), true, 301);
exit;
