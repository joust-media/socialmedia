<?php
/**
 * Legacy shim — 301s to add-feature.php for the tires module.
 * Preserves ?client= and maps ?edit_tire= to ?edit_item=.
 * Old unscoped bookmarks go to Home (its "Choose a client" list).
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

if (!$client) {
    header('Location: ' . pagePath('index'), true, 301);
    exit;
}

$extra = ['module' => 'tires'];
if (isset($_GET['edit_tire'])) { $extra['edit_item'] = (int)$_GET['edit_tire']; }

header('Location: ' . clientUrl('add-feature.php', $extra), true, 301);
exit;
