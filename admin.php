<?php
/**
 * admin.php → 301 shim to studio.php (spec §3: old filenames keep working).
 *
 * The previous admin dashboard lives on at legacy/admin.php. Auth behaves as
 * it always did on this URL: a visitor without the admin session is sent to
 * login before any redirect or output; an admin is permanently redirected to
 * Studio with the client scope (and any flash message) preserved.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';      // resolves $client from ?client=
require_once __DIR__ . '/auth.php';
requireAdmin();

$extra = [];
if (!empty($_GET['msg'])) { $extra['msg'] = (string)$_GET['msg']; }
if (!empty($_GET['tab']) && in_array($_GET['tab'], ['compose', 'batch', 'uploads', 'posts'], true)) {
    $extra['tab'] = (string)$_GET['tab'];
}

header('Location: ' . clientUrl('studio.php', $extra), true, 301);
exit;
