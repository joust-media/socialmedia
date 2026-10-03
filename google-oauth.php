<?php
/**
 * Connect Google (gmail-lib.php) — https://joustmedia.com/portal/google-oauth, the redirect URI registered on the
 * Google Cloud OAuth client (extensionless: a machine endpoint, never captured by the clean-link router).
 *
 *   POST action=start        (Manage → Notifications → Connect Google; admin, same-site) → 302 to Google's consent
 *                            page with a fresh random state kept in this admin's session (10 minutes, single use),
 *                            scopes gmail.send + gmail.modify, access_type=offline, prompt=consent.
 *   GET  ?code=…&state=…     Google's redirect back: admin only; the state must match (constant time) or nothing
 *                            happens; the code is exchanged, the account must be the sender address (notify_from,
 *                            default lance@joustmedia.com), the tokens are stored encrypted → back to Manage.
 *   GET  ?error=…            the admin said no on Google's page → back to Manage with a note.
 * Never prints a token or the client secret.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // the ?code= must not leak to anything this page links to

$back = static function (string $key, string $msg): void {
    header('Location: ' . portalUrl('manage', ['section' => 'notifications', $key => $msg]) . '#google', true, 303);
    exit;
};

if (!currentAdmin()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { http_response_code(403); echo 'Admin sign-in required'; exit; }
    requireAdmin();   // GET (Google's redirect) → sign in, then come back here
}
if (!googleReady($pdo)) $back('err', 'Run migrate.php first (steps 45–49).');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireSameSiteFetch();
    if (($_POST['action'] ?? '') !== 'start') { http_response_code(400); echo 'Unknown action'; exit; }
    if (!googleConfigured()) $back('err', 'Add google_client_id, google_client_secret and google_token_key to config.php first (docs/google-setup.md).');
    header('Location: ' . googleOauthStart(), true, 303);
    exit;
}

$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
if (isset($_GET['error'])) {
    unset($_SESSION['google_oauth']);
    $back('err', 'Google sign-in was cancelled (' . preg_replace('/[^a-z_]/', '', (string)$_GET['error']) . ').');
}
if (!googleOauthStateOk($state)) {
    $back('err', 'That Google sign-in did not start here (or took longer than 10 minutes) — press Connect Google again.');
}
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
if ($code === '' || strlen($code) > 2048) $back('err', 'Google did not send a sign-in code.');
$res = googleOauthFinish($pdo, $code, (string)currentAdmin());
if (!$res['ok']) $back('err', $res['error']);
$back('msg', 'Connected as ' . $res['email'] . ' — portal email now goes out through Gmail.');
