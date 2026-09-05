<?php
/**
 * End the admin session and bounce to the login screen.
 * Accepts both GET (for plain links) and POST (for the topbar form).
 */
require __DIR__ . '/auth.php';
adminLogout();

$script = $_SERVER['SCRIPT_NAME'] ?? '/logout.php';
$base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
if ($base === '.' || $base === '') { $base = ''; }

header('Location: ' . $base . '/login?signed_out=1');
exit;
