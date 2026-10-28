<?php
/**
 * End the admin session and bounce to the login screen: this browser's admin_sessions row is revoked, its
 * jsm_admin_key cookie dropped and the PHP session torn down (auth.php adminLogout).
 * Accepts both GET (for plain links) and POST (for the topbar form).
 * POST everywhere=1 (Manage → Tools → Your sign-ins): every sign-in of the admin, on every device. Only for a signed-in
 * admin on a same-site request — the key cookie is SameSite=Lax, so a cross-site POST never carries it anyway.
 */
require __DIR__ . '/auth.php';

$everywhere = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST['everywhere'])
    && in_array(strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin'))), ['same-origin', 'same-site', 'none'], true)
    && currentAdmin();
adminLogout((bool)$everywhere);

$script = $_SERVER['SCRIPT_NAME'] ?? '/logout.php';
$base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
if ($base === '.' || $base === '') { $base = ''; }

// Explicit .php (unless CLEAN_URLS is on, see helpers.php): works with no extension-less rewrite.
header('Cache-Control: no-store');
header('Location: ' . $base . '/login' . ((defined('CLEAN_URLS') && CLEAN_URLS) ? '' : '.php') . '?signed_out=' . ($everywhere ? 'everywhere' : '1'));
exit;
