<?php
/**
 * Legacy shim — 301s to add-feature.php for the tires module.
 * Preserves ?client= and maps ?edit_tire= to ?edit_item=.
 * Old unscoped bookmarks go to the Studio (admin) client picker.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

if (!$client) {
    header('Location: ' . clientUrl('studio.php'), true, 301);
    exit;
}

$extra = ['module' => 'tires'];
if (isset($_GET['edit_tire'])) { $extra['edit_item'] = (int)$_GET['edit_tire']; }

header('Location: ' . clientUrl('add-feature.php', $extra), true, 301);
exit;
