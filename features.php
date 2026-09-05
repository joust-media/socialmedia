<?php
/**
 * Module router.
 *
 *   module=tires (or no module)  → 301 to assets.php?view=collections[&item=<tire id>]
 *                                   (the tires module is now the Collections view of
 *                                   the unified Assets page; ?client= is preserved and
 *                                   the legacy ?tire=<id> alias still maps to item)
 *   any other module             → served by legacy/features.php, required from here so
 *                                   SCRIPT_NAME stays /features.php: basePath(), the
 *                                   tab-bar active detection and every relative URL on
 *                                   that page keep working unchanged.
 */

$moduleSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_GET['module'] ?? 'tires')));
if ($moduleSlug === '') { $moduleSlug = 'tires'; }

if ($moduleSlug === 'tires') {
    require __DIR__ . '/db.php';
    require __DIR__ . '/helpers.php';   // resolves $client from ?client=

    $extra = ['view' => 'collections'];
    $item  = isset($_GET['item']) ? (int)$_GET['item'] : (isset($_GET['tire']) ? (int)$_GET['tire'] : 0);
    if ($item > 0) { $extra['item'] = $item; }

    header('Location: ' . clientUrl('assets.php', $extra), true, 301);
    exit;
}

// Every other module keeps its existing gallery (it loads db.php/helpers.php itself).
require __DIR__ . '/legacy/features.php';
