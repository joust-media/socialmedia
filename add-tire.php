<?php
/**
 * Legacy shim — redirects to add-feature.php for the tires module.
 * Preserves ?client= and maps ?edit_tire= to ?edit_item=.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$qs = ['module' => 'tires'];
if ($client) { $qs['client'] = $client['slug']; }
if (isset($_GET['edit_tire'])) { $qs['edit_item'] = (int)$_GET['edit_tire']; }

if (empty($qs['client'])) {
    header('Location: admin.php');
    exit;
}

header('Location: add-feature.php?' . http_build_query($qs));
exit;
