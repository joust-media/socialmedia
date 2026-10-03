<?php
/**
 * Clean-link front controller. The portal's .htaccess (written by Manage → Tools → Clean links, url-lib.php
 * cleanLinksBlock()) sends every path that is not a real file, not a folder and not an extensionless script here:
 *
 *   /portal/kenda/posts/12  →  posts.php with $_GET = ['client' => 'kenda', 'post' => '12'] + the query string
 *
 * The target script runs in this same request at the top level (its globals stay global), with SCRIPT_NAME /
 * PHP_SELF / SCRIPT_FILENAME set to the script itself, so basePath(), the tab bar and every relative include
 * behave exactly as on a direct request. REQUEST_URI keeps the clean path (sign-in return paths use it).
 * PORTAL_ROUTED is defined so helpers.php never "redirects" a clean URL to itself.
 *
 *   /portal/__clean-links-check?n=… → {"router":"clean-links","n":…} (the installer's self-request)
 *   /portal/kenda                   → 301 /portal/kenda/
 *   anything the map does not know  → 404 page
 *
 * A request for route.php itself (no rewrite) answers 404 too.
 */
require_once __DIR__ . '/url-lib.php';

$__routeBase = portalBasePath();
$__routePath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$__routePath = rawurldecode($__routePath);
if ($__routeBase !== '' && strpos($__routePath, $__routeBase . '/') === 0) {
    $__routeRel = substr($__routePath, strlen($__routeBase) + 1);
} elseif ($__routeBase !== '' && $__routePath === $__routeBase) {
    $__routeRel = '';
} else {
    $__routeRel = ltrim($__routePath, '/');
}

if ($__routeRel === '__clean-links-check') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Portal-Router: clean-links');
    $n = is_string($_GET['n'] ?? null) ? preg_replace('/[^a-f0-9]/', '', $_GET['n']) : '';
    echo json_encode(['router' => 'clean-links', 'n' => $n, 'v' => CLEAN_LINKS_VERSION]);
    exit;
}

// Machine endpoints (slack-events, slack-actions, notify-cron, notify-thumb, drive-ingest, …) are never routed: the
// .htaccess serves '<name>' straight from '<name>.php' and passes '<name>/' through; should one still land here, it
// gets the 404 below — never a client page, never a sign-in redirect (Slack and cron do not follow redirects).
$__routeFirst = strtolower((string)explode('/', $__routeRel)[0]);
$__routeMachine = in_array(preg_replace('/\.php$/', '', $__routeFirst), portalMachineEndpoints(), true);
$__routeHit = ($__routeMachine || $__routeRel === '' || $__routeRel === 'route.php' || strpos($__routeRel, "\0") !== false) ? null : portalRouteMatch($__routeRel);
if ($__routeHit === null || !is_file(__DIR__ . '/' . $__routeHit['script'] . '.php')) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $home = htmlspecialchars($__routeBase . '/', ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="color-scheme" content="light dark"><title>Not found — Joust Media</title>'
       . '<style>body{font:17px/1.45 -apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:Canvas;color:CanvasText}'
       . 'main{max-width:380px;padding:24px;text-align:center}h1{font-size:22px;margin:0 0 8px}a{color:#007AFF}</style></head>'
       . '<body><main><h1>Page not found</h1><p>This link does not lead anywhere in the portal.</p><p><a href="' . $home . '">Go to the portal</a></p></main></body></html>';
    exit;
}

// /portal/kenda → /portal/kenda/ (Home's canonical form); other trailing slashes are tolerated as they are.
if (substr($__routeHit['canonical'], -1) === '/' && !$__routeHit['trailing'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . $__routeBase . '/' . $__routeHit['canonical'] . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit;
}

// Path parameters win over the query string (a stale ?post= can never contradict /posts/12).
foreach ($__routeHit['params'] as $__k => $__v) {
    $_GET[$__k] = $__v;
    $_REQUEST[$__k] = $__v;
}
define('PORTAL_ROUTED', $__routeRel);
$__routeScript = $__routeHit['script'] . '.php';
$_SERVER['SCRIPT_NAME']     = $__routeBase . '/' . $__routeScript;
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/' . $__routeScript;
$_SERVER['QUERY_STRING']    = http_build_query($_GET);
chdir(__DIR__);
unset($__routeBase, $__routePath, $__routeRel, $__routeHit, $__routeFirst, $__routeMachine, $__k, $__v, $n, $home, $qs);
require __DIR__ . '/' . $__routeScript;
