<?php
/**
 * Legacy shim — the tires module is now the Tires tab (assets.php?view=collections). 301s there, preserving ?client= and mapping ?tire= → ?item=.
 * Old unscoped bookmarks go to the unscoped Tires view (assets.php?view=collections: a chooser of the clients with Tires).
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

if (!$client) {
    header('Location: ' . pagePath('assets') . '?view=collections', true, 301);
    exit;
}

$extra = ['view' => 'collections'];
$item  = isset($_GET['item']) ? (int)$_GET['item'] : (isset($_GET['tire']) ? (int)$_GET['tire'] : 0);
if ($item > 0) { $extra['item'] = $item; }

header('Location: ' . clientUrl('assets.php', $extra), true, 301);
exit;
