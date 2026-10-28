<?php
/**
 * The admin's own sign-ins (auth.php, admin_sessions — migrate.php 56), listed in Manage → Tools → Your sign-ins.
 *   POST action=revoke id=<row>   sign one other device out (this browser's own row: use Sign out)
 *   POST action=others            sign every other device out; this one stays signed in
 * "Sign out everywhere" (this device too) is logout.php POST everywhere=1.
 * Admin only (requireAdmin: pages → sign-in, fetches → 401 JSON), POST only, same-site only (requireSameSiteFetch).
 * Replies: 303 back to Manage → Tools (#sign-ins) with a one-time msg / err, or JSON for fetches.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

$asJson = adminWantsJson();
$reply = static function (int $code, string $msg, bool $ok) use ($asJson): void {
    header('Cache-Control: no-store');
    if ($asJson) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($ok ? ['ok' => true, 'message' => $msg] : ['ok' => false, 'error' => $msg]);
        exit;
    }
    header('Location: ' . manageUrl('tools', [$ok ? 'msg' : 'err' => $msg]) . '#sign-ins', true, 303);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'POST only';
    exit;
}
requireSameSiteFetch();
if (!adminSessionsReady($pdo)) $reply(409, 'Sign-ins are kept in the database once migrate.php has run (step 56).', false);

$action = (string)($_POST['action'] ?? '');
if ($action === 'revoke') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) $reply(400, 'Which sign-in?', false);
    if (!adminSessionRevoke($pdo, $id)) $reply(404, 'That sign-in has already ended (or it is this device — use Sign out).', false);
    $reply(200, 'Signed out that device.', true);
}
if ($action === 'others') {
    $mine = adminKeyCookie() !== '' ? adminTokenHash(adminKeyCookie()) : '';
    $st = $pdo->prepare("UPDATE admin_sessions SET revoked_at = NOW(), revoked_by = 'admin' WHERE admin_email = ? AND revoked_at IS NULL AND token_hash <> ?");
    $st->execute([ADMIN_EMAIL, $mine]);
    $n = $st->rowCount();
    $reply(200, $n > 0 ? 'Signed out ' . $n . ' other device' . ($n === 1 ? '' : 's') . '.' : 'No other device was signed in.', true);
}
$reply(400, 'Unknown action', false);
