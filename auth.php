<?php
/**
 * Single-admin session gate.
 *
 * Testing credentials are baked in for now. When the design firms up, swap
 * ADMIN_EMAIL / ADMIN_PASSWORD_HASH for a small `users` table and update the
 * three helpers below — every caller already uses adminLogin / requireAdmin /
 * adminLogout, so they won't need to change.
 *
 * Usage on any admin page:
 *   require __DIR__ . '/auth.php';
 *   requireAdmin();
 */

const ADMIN_EMAIL         = 'lance@joustmedia.com';
// Hash of the plaintext password "Wrst!551", generated once with
// password_hash($pw, PASSWORD_BCRYPT). Rotate the password by re-hashing.
const ADMIN_PASSWORD_HASH = '$2y$12$hQcjKIHKxzi7IXBJewJ9TuNHO89QsG.SF3eXHTCFzcDl3/vaXmUcy';

/** Start a session with safe cookie flags. Idempotent. */
function startAdminSession() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('jsm_admin');
    session_start();
}

/** Currently-signed-in admin email, or null. */
function currentAdmin() {
    startAdminSession();
    return $_SESSION['admin_email'] ?? null;
}

/** Block the page when there's no admin in the session. */
function requireAdmin() {
    if (currentAdmin()) return;
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '.' || $base === '') { $base = ''; }
    $return = $_SERVER['REQUEST_URI'] ?? ($base . '/admin');
    header('Location: ' . $base . '/login?return=' . urlencode($return));
    exit;
}

/** Validate credentials and start the session. Returns true on success. */
function adminLogin($email, $password) {
    startAdminSession();
    $email = trim((string)$email);
    if (strcasecmp($email, ADMIN_EMAIL) !== 0)            return false;
    if (!password_verify((string)$password, ADMIN_PASSWORD_HASH)) return false;
    session_regenerate_id(true);
    $_SESSION['admin_email']    = ADMIN_EMAIL;
    $_SESSION['admin_login_at'] = time();
    return true;
}

/** Tear down the session entirely. */
function adminLogout() {
    startAdminSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
