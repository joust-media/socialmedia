<?php
/**
 * Test harness only — seat emulation for the local php -S server and CLI runs.
 *
 * Loaded ONLY through `php -d auto_prepend_file=tests/test-auth.php` (tests/serve.sh, tests/env.sh
 * php_test). It is not app code: nothing in the app includes it, tests/** is excluded from both
 * deploy workflows, and it does nothing at all unless the environment variable PORTAL_TEST=1 is set.
 *
 *   ?__role=admin   sign in as the admin for this request (and remember it in a cookie)
 *   ?__role=client  act as the anonymous client seat (and remember it)
 *   cookie portal_test_role=admin|client   the same, without the query parameter
 *   CLI: PORTAL_TEST_ROLE=admin            (bootstrap.sh runs migrate.php this way)
 *
 * Admin = a jsm_admin session carrying admin_email, exactly what login.php leaves behind, so
 * requireAdmin() / currentAdmin() / isAdmin() all take their normal code paths.
 */
if (getenv('PORTAL_TEST') !== '1' || defined('PORTAL_TEST_AUTH')) {
    return;
}
define('PORTAL_TEST_AUTH', 1);

$__ptRole = '';
if (PHP_SAPI === 'cli') {
    $__ptRole = (string)getenv('PORTAL_TEST_ROLE');
} else {
    if (isset($_GET['__role']) && in_array($_GET['__role'], ['admin', 'client'], true)) {
        $__ptRole = $_GET['__role'];
        setcookie('portal_test_role', $__ptRole, 0, '/');
    } else {
        $__ptRole = (string)($_COOKIE['portal_test_role'] ?? '');
    }
}

if ($__ptRole === 'admin') {
    define('JSM_FORCE_SESSION', 1);
    session_name('jsm_admin');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['admin_email'] = 'lance@joustmedia.com';
} elseif ($__ptRole === 'client') {
    unset($_COOKIE['jsm_admin']);   // no admin session can start for this request
}
unset($__ptRole);
