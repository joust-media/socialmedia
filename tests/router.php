<?php
/**
 * Test harness only — router for `php -S` (tests/serve.sh). Docroot = $PORTAL_TEST_ROOT/site, so the
 * app answers under /portal/ and media/ is its docroot sibling, like production.
 *
 * PHP files are required from here (top level, so the app's globals stay global) to keep the whole
 * request in one execution with the test-auth.php prepend; static files are served by php -S.
 */
$__uri  = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$__root = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
if (strpos($__uri, "\0") !== false || strpos($__uri, '..') !== false) {
    http_response_code(400);
    return true;
}
$__file = $__root . $__uri;
if (is_dir($__file)) {
    if (substr($__uri, -1) !== '/') {
        header('Location: ' . $__uri . '/' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
        return true;
    }
    if (!is_file($__file . 'index.php')) {
        return false;
    }
    $__uri  .= 'index.php';
    $__file .= 'index.php';
}
if (substr($__file, -4) !== '.php') {
    return false;   // static file (or 404) — php -S handles it
}
if (!is_file($__file)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
$_SERVER['SCRIPT_NAME']     = $__uri;
$_SERVER['PHP_SELF']        = $__uri;
$_SERVER['SCRIPT_FILENAME'] = $__file;
chdir(dirname($__file));
unset($__root);
require $__file;
return true;
