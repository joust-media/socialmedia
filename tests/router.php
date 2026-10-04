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
// Clean links: once Manage → Tools → Clean links has written its block into <site>/portal/.htaccess (url-lib.php
// cleanLinksBlock), emulate those rules here — php -S never reads .htaccess. Same order as the block: real files and
// folders as they are; /portal/<name> → <name>.php when that script exists; the machine endpoints 404; anything
// else → route.php. Without the block, extensionless paths 404 (the "rewrites off" fallback the portal must survive).
$__ht = $__root . '/portal/.htaccess';
$__cleanLinks = is_file($__ht) && strpos((string)file_get_contents($__ht), '# BEGIN joust-portal-clean-links') !== false;
if ($__cleanLinks && strpos($__uri, '/portal/') === 0 && !file_exists($__file)) {
    $__rel = substr($__uri, strlen('/portal/'));
    if (preg_match('#^([A-Za-z0-9_-]+)$#', $__rel, $__m) && is_file($__root . '/portal/' . $__m[1] . '.php')) {
        $__uri  = '/portal/' . $__m[1] . '.php';
        $__file = $__root . $__uri;
    } elseif (preg_match('#^(slack-events|slack-actions|notify-cron|notify-thumb|notify-pump|drive-ingest|mail-inbound|google-oauth|email-prefs)/?$#', $__rel)) {
        http_response_code(404);
        echo 'Not found';
        return true;
    } else {
        $__uri  = '/portal/route.php';
        $__file = $__root . $__uri;
    }
}
unset($__ht, $__rel, $__m);
// Apache answers 404 for a path that does not exist (php -S would fall back to the nearest index.php instead).
if (!file_exists($__file)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
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
unset($__root, $__cleanLinks);
require $__file;
return true;
