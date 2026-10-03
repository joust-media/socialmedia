<?php
/**
 * Client sign-out: revokes this browser's client_sessions row and drops the jsm_client cookie
 * (client-auth-lib.php clientSignOut), then shows the sign-in page ("You're signed out", with the client's logo).
 * GET (a plain link in the tab bar / Home) or POST. The Joust team signs out at logout.php.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$sess = currentClientSession($pdo);
$slug = $sess ? (string)$sess['slug'] : '';
clientSignOut($pdo);
header('Cache-Control: no-store');
header('Location: ' . portalUrl('sign-in', ['client' => $slug !== '' ? $slug : null, 'reason' => 'signed_out']), true, 302);
exit;
