<?php
/**
 * Legacy shim — redirects to the generic features.php for the tires module.
 * Preserves ?client= and maps ?tire= to ?item=.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$qs = ['module' => 'tires'];
if ($client) { $qs['client'] = $client['slug']; }
if (isset($_GET['tire'])) { $qs['item'] = (int)$_GET['tire']; }

// If we have no client (old bookmark), send them to the admin picker
// so they can pick which Kenda-or-whoever this was for.
if (empty($qs['client'])) {
    header('Location: admin');
    exit;
}

header('Location: features?' . http_build_query($qs));
exit;
