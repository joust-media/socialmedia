<?php
/**
 * Test harness only — seat emulation for the local php -S server and CLI runs.
 *
 * Loaded ONLY through `php -d auto_prepend_file=tests/test-auth.php` (tests/serve.sh, tests/env.sh
 * php_test). It is not app code: nothing in the app includes it, tests/** is excluded from both
 * deploy workflows, and it does nothing at all unless the environment variable PORTAL_TEST=1 is set.
 *
 *   ?__role=admin          sign in as the admin for this request (and remember it in a cookie)
 *   ?__role=client         test sign-in as a contact of the client THIS request names (?client= / POST client /
 *                          the first segment of a clean path /portal/<slug>/…, else the last one remembered in the
 *                          cookie portal_test_client) — a real client_sessions row + jsm_client cookie value, so the
 *                          app's own gate (client-auth-lib.php) decides exactly as for a magic-link sign-in
 *   ?__role=client:kenda   test sign-in pinned to ONE client (cross-client checks: asking for another client must fail)
 *   ?__role=anon           nobody (no admin, no client) — the bare ?client= visitor
 *   cookie portal_test_role=admin|client|client:<slug>|anon   the same, without the query parameter
 *   CLI: PORTAL_TEST_ROLE=admin            (bootstrap.sh runs migrate.php this way)
 *
 * Admin = a jsm_admin session carrying admin_email, exactly what login.php leaves behind, so
 * requireAdmin() / currentAdmin() / isAdmin() all take their normal code paths.
 * Client = the first contact (lowest id) of that client; the session token is derived from the contact so repeated
 * requests reuse one row (revoked rows are revived — the seat is "always signed in").
 * No role at all = the browser's own cookies decide (the magic-link / deep-link tests use that).
 */
if (getenv('PORTAL_TEST') !== '1' || defined('PORTAL_TEST_AUTH')) {
    return;
}
define('PORTAL_TEST_AUTH', 1);

$__ptRole = '';
if (PHP_SAPI === 'cli') {
    $__ptRole = (string)getenv('PORTAL_TEST_ROLE');
} else {
    if (isset($_GET['__role']) && is_string($_GET['__role']) && preg_match('/^(admin|client|anon|client:[a-z0-9-]{1,40})$/', $_GET['__role'])) {
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
} elseif ($__ptRole === 'anon') {
    unset($_COOKIE['jsm_admin'], $_COOKIE['jsm_client']);
} elseif ($__ptRole === 'client' || strpos($__ptRole, 'client:') === 0) {
    unset($_COOKIE['jsm_admin'], $_COOKIE['jsm_client']);   // no admin session can start for this request
    $__ptSlug = '';
    if (strpos($__ptRole, 'client:') === 0) {
        $__ptSlug = substr($__ptRole, 7);
    } else {
        foreach ([$_GET['client'] ?? null, $_POST['client'] ?? null] as $__v) {
            if (is_string($__v) && $__v !== '') { $__ptSlug = $__v; break; }
        }
        if ($__ptSlug === '' && preg_match('#^/portal/([a-z0-9-]+)/#', (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), $__m)) {
            $__ptSlug = $__m[1];
        }
        if ($__ptSlug === '' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
            $__j = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($__j) && is_string($__j['client'] ?? null)) $__ptSlug = $__j['client'];
        }
        if ($__ptSlug === '') $__ptSlug = (string)($_COOKIE['portal_test_client'] ?? '');
    }
    $__ptSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($__ptSlug));
    $__ptApp = getenv('APP_DIR') ?: (rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/portal');
    if ($__ptSlug !== '' && is_file($__ptApp . '/config.php')) {
        try {
            $__c = include $__ptApp . '/config.php';
            $__db = new PDO("mysql:host={$__c['host']};dbname={$__c['dbname']};charset=utf8mb4", $__c['username'], $__c['password'],
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $__db->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");   // the app's clock (db.php)
            $__s = $__db->prepare("SELECT id FROM companies WHERE slug = ?");
            $__s->execute([$__ptSlug]);
            $__co = (int)$__s->fetchColumn();
            if ($__co > 0) {
                $__s = $__db->prepare("SELECT id FROM client_contacts WHERE company_id = ? ORDER BY id ASC LIMIT 1");
                $__s->execute([$__co]);
                $__ct = (int)$__s->fetchColumn();
                if ($__ct <= 0) {
                    $__db->prepare("INSERT INTO client_contacts (company_id, email, name) VALUES (?, ?, 'Test seat')")->execute([$__co, 'seat@' . $__ptSlug . '.test']);
                    $__ct = (int)$__db->lastInsertId();
                }
                $__raw = hash_hmac('sha256', 'pt|' . $__co . '|' . $__ct, 'portal-test-client');
                $__db->prepare("INSERT INTO client_sessions (contact_id, company_id, token_hash, via, ip, user_agent, last_seen_at, expires_at)
                                VALUES (?, ?, ?, 'test', '127.0.0.1', 'portal-test', NOW(), NOW() + INTERVAL 30 DAY)
                                ON DUPLICATE KEY UPDATE revoked_at = NULL, revoked_by = NULL, expires_at = NOW() + INTERVAL 30 DAY")
                     ->execute([$__ct, $__co, hash('sha256', $__raw)]);
                $_COOKIE['jsm_client'] = $__raw;
                if ($__ptRole === 'client' && ($_COOKIE['portal_test_client'] ?? '') !== $__ptSlug) setcookie('portal_test_client', $__ptSlug, 0, '/');
            }
        } catch (Throwable $__e) {
            error_log('test-auth: client sign-in failed: ' . $__e->getMessage());
        }
    }
    unset($__ptSlug, $__ptApp, $__c, $__db, $__s, $__co, $__ct, $__raw, $__v, $__m, $__j, $__e);
}
unset($__ptRole);
