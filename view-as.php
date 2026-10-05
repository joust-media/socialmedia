<?php
/**
 * Admin "View as client" — the portal exactly as that client sees it (no magic link, no client session):
 *   POST client=<slug>          start (admin only, same-site) → that client's Home
 *   POST/GET exit=1[&return=]   stop → back to Manage → Clients for that client (or the return path)
 * The admin session keeps its rights on endpoints, but the pages render the client seat (isAdmin() in helpers.php is
 * false while $_SESSION['view_as'] names the client in scope), so what you do there is done AS THE CLIENT: the page
 * posts actor=client (body data-actor), and comments / approvals are logged as the client's — they count as the
 * client's and notify Slack like any client message. The banner (partials/layout-top.php) says so and carries the
 * Exit link.
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
