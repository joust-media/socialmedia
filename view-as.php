<?php
/**
 * Admin "View as client" — the portal exactly as that client sees it (no magic link, no client session):
 *   POST client=<slug>          start (admin only, same-site) → that client's Home
 *   POST/GET exit=1[&return=]   stop → back to Manage → Clients for that client (or the return path)
 * The admin session keeps its rights: endpoints still see the admin (actions are logged as Joust). Pages render the
 * client seat because isAdmin() (helpers.php) is false while $_SESSION['view_as'] names the client in scope; the
 * banner (partials/layout-top.php) says so and carries the Exit link.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') requireSameSiteFetch();

if (!empty($_REQUEST['exit'])) {
    $was = (string)($_SESSION['view_as'] ?? '');
    unset($_SESSION['view_as']);
    $ret = clientSafeReturn(is_string($_REQUEST['return'] ?? null) ? $_REQUEST['return'] : '');
    header('Location: ' . ($ret !== '' ? $ret : portalUrl('manage', ['client' => $was !== '' ? $was : null, 'section' => 'clients'])), true, 303);
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'POST only';
    exit;
}
$co = clientCompanyBySlug($pdo, is_string($_POST['client'] ?? null) ? $_POST['client'] : '');
if (!$co) {
    http_response_code(404);
    echo 'Unknown client';
    exit;
}
$_SESSION['view_as'] = (string)$co['slug'];
header('Location: ' . portalUrl('index', ['client' => (string)$co['slug']]), true, 303);
exit;
