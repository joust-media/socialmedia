<?php
/**
 * Single-admin sign-in.
 *
 * Testing credentials are baked in for now. When the design firms up, swap
 * ADMIN_EMAIL / ADMIN_PASSWORD_HASH for a small `users` table and update the
 * helpers below — every caller already uses adminLogin / requireAdmin /
 * adminLogout, so they won't need to change.
 *
 * Usage on any admin page:
 *   require __DIR__ . '/auth.php';
 *   requireAdmin();
 *
 * Who is signed in (migrate.php 56 — admin_sessions):
 *   The jsm_admin_key cookie (a random 256-bit token; only its sha256 is stored) → an admin_sessions row that is not
 *   revoked, was used within admin_idle_hours (config.php, default 12) and is younger than admin_max_days (default 7).
 *   That row is the sign-in — not the PHP session: a shared host deletes idle PHP session files after
 *   session.gc_maxlifetime (cPanel's default 1440 s = 24 min, swept by its own cron), which used to sign Lance out
 *   every half hour or so. The jsm_admin PHP session is still there for scratch state (View as client, the Google
 *   OAuth state) and is rebuilt from the row whenever the host has thrown it away.
 *   last_seen_at is written at most every 5 minutes. Sign out revokes the row; "Sign out everywhere" revokes all of
 *   them (Manage → Tools → Your sign-ins). The cookie is HttpOnly, SameSite=Lax, Secure on HTTPS, scoped to this
 *   portal's folder (production and staging never share it) and expires with the hard maximum.
 *   Before migrate 56 the PHP session alone decides, exactly as before. A PHP session signed in before migrate 56
 *   is carried over into a row on its next request (no new sign-in needed).
 */

require_once __DIR__ . '/url-lib.php';   // portalConfig() / portalBasePath() (function definitions only)

const ADMIN_EMAIL         = 'lance@joustmedia.com';
// bcrypt hash generated with password_hash($pw, PASSWORD_BCRYPT). Rotate the
// password by re-hashing; never record the plaintext here. (Defined only when not already defined: the test
// harness's seat shim, tests/test-auth.php, sets a known test password — it is never deployed.)
if (!defined('ADMIN_PASSWORD_HASH')) define('ADMIN_PASSWORD_HASH', '$2y$12$hQcjKIHKxzi7IXBJewJ9TuNHO89QsG.SF3eXHTCFzcDl3/vaXmUcy');

if (!defined('ADMIN_SESSION_COOKIE'))     define('ADMIN_SESSION_COOKIE', 'jsm_admin_key');
if (!defined('ADMIN_IDLE_HOURS_DEFAULT')) define('ADMIN_IDLE_HOURS_DEFAULT', 12);
if (!defined('ADMIN_MAX_DAYS_DEFAULT'))   define('ADMIN_MAX_DAYS_DEFAULT', 7);
if (!defined('ADMIN_SEEN_EVERY'))         define('ADMIN_SEEN_EVERY', 300);   // last_seen_at is written at most this often (s)

/** Start a session with safe cookie flags. Idempotent.
 *  Only starts one when the jsm_admin cookie is already present (or login.php
 *  defines JSM_FORCE_SESSION for the sign-in POST, or $force: a valid admin_sessions
 *  key whose PHP session the host has thrown away) — anonymous client visits
 *  and JSON calls get no Set-Cookie and no session file. */
function startAdminSession(bool $force = false) {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (!empty($GLOBALS['__jsmSessionReleased'])) return;   // released for slow work (preview-lib.php previewReleaseSession): $_SESSION stays readable
    if (empty($_COOKIE['jsm_admin']) && !defined('JSM_FORCE_SESSION') && !$force) return;
    if (headers_sent()) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => adminCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('jsm_admin');
    session_start();
}

function adminCookieSecure(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

/** Idle limit in seconds: config admin_idle_hours (default 12; 0.25 h – 30 days). */
function adminIdleSeconds(): int {
    $v = portalConfig('admin_idle_hours', null);
    $h = is_numeric($v) ? (float)$v : (float)ADMIN_IDLE_HOURS_DEFAULT;
    if ($h <= 0) $h = (float)ADMIN_IDLE_HOURS_DEFAULT;
    return (int)round(max(0.25, min(720.0, $h)) * 3600);
}

/** Hard maximum in seconds since sign-in: config admin_max_days (default 7; 1 – 90 days). */
function adminMaxSeconds(): int {
    $v = portalConfig('admin_max_days', null);
    $d = is_numeric($v) ? (float)$v : (float)ADMIN_MAX_DAYS_DEFAULT;
    if ($d <= 0) $d = (float)ADMIN_MAX_DAYS_DEFAULT;
    return (int)round(max(1.0, min(90.0, $d)) * 86400);
}

/** The database for the admin sessions: db.php's $pdo when loaded, else a quiet connection of our own (login.php /
 *  logout.php load auth.php alone). null = no database (then the PHP session alone decides, as before migrate 56). */
function adminSessionPdo(): ?PDO {
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    static $own = false;
    if ($own !== false) return $own;
    $own = null;
    $cfg = portalConfigArray();
    if (empty($cfg['dbname'])) return null;
    try {
        $own = new PDO('mysql:host=' . ($cfg['host'] ?? 'localhost') . ';dbname=' . $cfg['dbname'] . ';charset=' . ($cfg['charset'] ?? 'utf8mb4'),
            (string)($cfg['username'] ?? ''), (string)($cfg['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        // the same wall clock as db.php (every admin_sessions time is written and compared by MySQL itself)
        try { $own->exec("SET time_zone = 'America/New_York'"); }
        catch (PDOException $e) { $own->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'"); }
    } catch (Throwable $e) {
        error_log('admin session: no database: ' . $e->getMessage());
        $own = null;
    }
    return $own;
}

/** admin_sessions exists (migrate.php 56). Cached per request. */
function adminSessionsReady(PDO $pdo): bool {
    static $ready = [];
    $k = spl_object_id($pdo);
    if (isset($ready[$k])) return $ready[$k];
    try {
        return $ready[$k] = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_sessions'")->fetchColumn() > 0;
    } catch (Throwable $e) {
        return $ready[$k] = false;
    }
}

function adminTokenHash(string $raw): string { return hash('sha256', $raw); }

function adminSetKeyCookie(string $value, int $expires): void {
    if (headers_sent()) return;
    setcookie(ADMIN_SESSION_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => portalBasePath() . '/',
        'domain'   => '',
        'secure'   => adminCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** The raw key of this request ('' when absent or malformed). */
function adminKeyCookie(): string {
    $raw = (string)($_COOKIE[ADMIN_SESSION_COOKIE] ?? '');
    return preg_match('/^[A-Za-z0-9_-]{40,64}$/', $raw) ? $raw : '';
}

/** A new admin_sessions row + the jsm_admin_key cookie. $createdTs = when the sign-in happened (a carried-over PHP
 *  session keeps its own sign-in time, so the hard maximum still counts from there). Returns the row id. */
function adminSessionCreate(PDO $pdo, string $via, ?int $createdTs = null): int {
    $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $ua  = substr(preg_replace('/[\x00-\x1f\x7f]/', '', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = '';
    $max = adminMaxSeconds();
    $created = $createdTs !== null ? 'FROM_UNIXTIME(' . (int)$createdTs . ')' : 'NOW()';
    $pdo->prepare("INSERT INTO admin_sessions (admin_email, token_hash, via, ip, user_agent, created_at, last_seen_at, expires_at)
                   VALUES (?, ?, ?, ?, ?, {$created}, NOW(), {$created} + INTERVAL {$max} SECOND)")
        ->execute([ADMIN_EMAIL, adminTokenHash($raw), substr($via, 0, 12), $ip !== '' ? $ip : null, $ua]);
    $id = (int)$pdo->lastInsertId();
    adminSetKeyCookie($raw, ($createdTs ?? time()) + $max);
    $_COOKIE[ADMIN_SESSION_COOKIE] = $raw;
    return $id;
}

/** This request's live admin_sessions row (not revoked, used within the idle limit, younger than the hard maximum,
 *  for today's ADMIN_EMAIL) or null. Touches last_seen_at at most every ADMIN_SEEN_EVERY seconds. */
function adminSessionLookup(PDO $pdo): ?array {
    $raw = adminKeyCookie();
    if ($raw === '') return null;
    try {
        $idle = adminIdleSeconds();
        $st = $pdo->prepare("SELECT id, admin_email, created_at, last_seen_at, expires_at,
                    UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(last_seen_at) AS seen_ago
                FROM admin_sessions
                WHERE token_hash = ? AND admin_email = ? AND revoked_at IS NULL AND expires_at > NOW()
                  AND created_at > NOW() - INTERVAL " . adminMaxSeconds() . " SECOND
                  AND last_seen_at > NOW() - INTERVAL {$idle} SECOND");
        $st->execute([adminTokenHash($raw), ADMIN_EMAIL]);
        $row = $st->fetch() ?: null;
        if ($row && (int)$row['seen_ago'] >= min(ADMIN_SEEN_EVERY, max(60, intdiv($idle, 4)))) {
            $pdo->prepare("UPDATE admin_sessions SET last_seen_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
        }
        return $row;
    } catch (Throwable $e) {
        error_log('admin session lookup failed: ' . $e->getMessage());
        $GLOBALS['__jsmAdminDbFault'] = true;   // currentAdmin(): the PHP session decides (never a lockout on a DB fault)
        return null;
    }
}

/** A PHP session signed in before migrate 56 (admin_email + admin_login_at, no admin_sid) may become a row once:
 *  only inside the hard maximum and never when "Sign out everywhere" ran after it signed in. */
function adminLegacyCarryOk(PDO $pdo): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) return false;
    $email = $_SESSION['admin_email'] ?? null;
    $at = (int)($_SESSION['admin_login_at'] ?? 0);
    if (!is_string($email) || strcasecmp($email, ADMIN_EMAIL) !== 0 || isset($_SESSION['admin_sid'])) return false;
    if ($at <= 0 || $at > time() + 60 || $at < time() - adminMaxSeconds()) return false;
    try {
        $all = $pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(revoked_at)) FROM admin_sessions WHERE admin_email = ? AND revoked_by = 'everywhere'");
        $all->execute([ADMIN_EMAIL]);
        $last = (int)$all->fetchColumn();
        return $last === 0 || $at > $last;
    } catch (Throwable $e) {
        error_log('admin session carry-over check failed: ' . $e->getMessage());
        $GLOBALS['__jsmAdminDbFault'] = true;
        return false;
    }
}

/** This request carried an admin key (or, for the client gate, an admin cookie) whose sign-in has ended (idle, the
 *  hard maximum, signed out elsewhere). Replies then say so: header X-Portal-Auth: signed-out (app.js shows
 *  "Sign in again", back to the same page) and the sign-in redirects carry reason=expired. */
function adminSignedOutHere(): bool { return !empty($GLOBALS['__jsmAdminSignedOut']); }

function adminMarkSignedOut(): void {
    $GLOBALS['__jsmAdminSignedOut'] = true;
    if (!headers_sent()) header('X-Portal-Auth: signed-out');
}

/** Forget this request's answer (after a sign-in / sign-out in the same request). */
function adminSessionReset(): void { unset($GLOBALS['__jsmAdmin'], $GLOBALS['__jsmAdminSignedOut']); }

/** Currently-signed-in admin email, or null. */
function currentAdmin() {
    if (array_key_exists('__jsmAdmin', $GLOBALS)) return $GLOBALS['__jsmAdmin'];
    startAdminSession();
    $pdo = adminSessionPdo();
    if ($pdo === null || !adminSessionsReady($pdo)) {
        // Before migrate 56: the PHP session alone (unchanged behaviour; not cached — login / logout change it).
        return $_SESSION['admin_email'] ?? null;
    }
    unset($GLOBALS['__jsmAdminDbFault']);
    $row = adminSessionLookup($pdo);
    if ($row) {
        startAdminSession(true);   // the host may have deleted the PHP session: start a fresh one for the scratch state
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (($_SESSION['admin_sid'] ?? null) !== (int)$row['id']) $_SESSION['admin_sid'] = (int)$row['id'];
            if (($_SESSION['admin_email'] ?? null) !== (string)$row['admin_email']) $_SESSION['admin_email'] = (string)$row['admin_email'];
        }
        return $GLOBALS['__jsmAdmin'] = (string)$row['admin_email'];
    }
    if (adminLegacyCarryOk($pdo)) {
        // Output already started: no cookie can be set now, so the carry-over waits for the next request (this one is
        // still the PHP session's, as before migrate 56).
        if (headers_sent()) return $_SESSION['admin_email'] ?? null;
        try {
            $_SESSION['admin_sid'] = adminSessionCreate($pdo, 'carried', (int)$_SESSION['admin_login_at']);
            return $GLOBALS['__jsmAdmin'] = ADMIN_EMAIL;
        } catch (Throwable $e) {
            error_log('admin session carry-over failed: ' . $e->getMessage());
            $GLOBALS['__jsmAdminDbFault'] = true;
        }
    }
    // The database failed inside the new path (an error, not an answer): fall back to the PHP session, exactly the
    // sign-in before migrate 56 — a broken / unreachable admin_sessions must never lock the admin out. A row that is
    // merely revoked / idle / past the maximum is an answer, not a fault, and still signs out below. Not cached.
    if (!empty($GLOBALS['__jsmAdminDbFault'])) {
        unset($GLOBALS['__jsmAdminDbFault']);
        $email = (session_status() === PHP_SESSION_ACTIVE) ? ($_SESSION['admin_email'] ?? null) : null;
        if (is_string($email) && strcasecmp($email, ADMIN_EMAIL) === 0) return $email;
    }
    // Not signed in: a PHP session that still says otherwise (its row is revoked / expired) is cleared.
    if (session_status() === PHP_SESSION_ACTIVE && (isset($_SESSION['admin_email']) || isset($_SESSION['admin_sid']))) {
        unset($_SESSION['admin_email'], $_SESSION['admin_sid'], $_SESSION['admin_login_at'], $_SESSION['view_as'], $_SESSION['google_oauth']);
    }
    if (adminKeyCookie() !== '') adminMarkSignedOut();
    return $GLOBALS['__jsmAdmin'] = null;
}

/** The admin sign-in page, coming back to $return (a same-app path) — reason=expired shows "Your session ended". */
function adminLoginUrl(string $return = '', string $reason = ''): string {
    $base = portalBasePath();
    // Explicit .php targets (unless CLEAN_URLS is on, see helpers.php) so the redirect works
    // on a folder with no extension-less rewrite. auth.php is loaded standalone by login.php.
    $ext = (defined('CLEAN_URLS') && CLEAN_URLS) ? '' : '.php';
    $q = [];
    if ($return !== '') $q['return'] = $return;
    if ($reason !== '') $q['reason'] = $reason;
    return $base . '/login' . $ext . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}

/** The page the browser was on (Referer), when it is a page of this portal on this host — the place to come back to
 *  after a POST / fetch whose sign-in had ended. '' otherwise. */
function adminSameAppReferer(): string {
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') return '';
    $p = parse_url($ref);
    if (!is_array($p) || empty($p['path'])) return '';
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $refHost = strtolower((string)($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
    if ($host === '' || ($refHost !== $host && strtolower((string)($p['host'] ?? '')) !== $host)) return '';
    $path = (string)$p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
    $base = portalBasePath();
    if (!preg_match('#^/(?![/\\\\])#', $path) || preg_match('#[\x00-\x1f\x7f\\\\]#', $path)) return '';
    if (strpos($path, $base . '/') !== 0) return '';
    if (preg_match('#/(login|logout)(\.php)?($|\?)#', $path)) return '';
    return $path;
}

/** A fetch / XHR (not a page navigation): answer JSON rather than a redirect. */
function adminWantsJson(): bool {
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') return true;
    $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
    if ($mode !== '' && $mode !== 'navigate') return true;
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false;
}

/** Block the page when there's no admin signed in: pages go to the sign-in page (and back here afterwards — for a
 *  POST, back to the page the form was on); fetches get 401 JSON {ok:false, error, signIn}. */
function requireAdmin() {
    if (currentAdmin()) return;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $json   = adminWantsJson();
    $return = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (($method !== 'GET' && $method !== 'HEAD') || $json) {
        $ref = adminSameAppReferer();
        if ($ref !== '') $return = $ref;
    }
    if ($return === '') $return = portalBasePath() . '/admin' . ((defined('CLEAN_URLS') && CLEAN_URLS) ? '' : '.php');
    $url = adminLoginUrl($return, adminSignedOutHere() ? 'expired' : '');
    header('Cache-Control: no-store');
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'signedOut' => true,
                          'error' => adminSignedOutHere() ? 'You were signed out. Please sign in again.' : 'Admin sign-in required',
                          'signIn' => $url], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: ' . $url);
    exit;
}

/** Validate credentials and start the session. Returns true on success. */
function adminLogin($email, $password) {
    startAdminSession();
    $email = trim((string)$email);
    if (strcasecmp($email, ADMIN_EMAIL) !== 0)            return false;
    if (!password_verify((string)$password, ADMIN_PASSWORD_HASH)) return false;
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['admin_email']    = ADMIN_EMAIL;
    $_SESSION['admin_login_at'] = time();
    unset($_SESSION['admin_sid']);
    $pdo = adminSessionPdo();
    if ($pdo !== null && adminSessionsReady($pdo)) {
        try {
            // A fresh key on every sign-in: the one this browser held before is retired.
            $old = adminKeyCookie();
            if ($old !== '') {
                $pdo->prepare("UPDATE admin_sessions SET revoked_at = NOW(), revoked_by = 'replaced' WHERE token_hash = ? AND revoked_at IS NULL")
                    ->execute([adminTokenHash($old)]);
            }
            $_SESSION['admin_sid'] = adminSessionCreate($pdo, 'password');
            // housekeeping: rows long past any use (kept 100 days for the "Sign out everywhere" carry-over check)
            $pdo->exec("DELETE FROM admin_sessions WHERE COALESCE(revoked_at, expires_at) < NOW() - INTERVAL 100 DAY");
        } catch (Throwable $e) {
            error_log('admin session create failed: ' . $e->getMessage());
        }
    }
    adminSessionReset();
    return true;
}

/** Sign out: this browser's admin_sessions row is revoked and its cookie dropped, and the PHP session torn down.
 *  $everywhere = every live sign-in of the admin (other browsers / phones) too. */
function adminLogout(bool $everywhere = false) {
    $pdo = adminSessionPdo();
    if ($pdo !== null && adminSessionsReady($pdo)) {
        try {
            $raw = adminKeyCookie();
            if ($everywhere) {
                $pdo->prepare("UPDATE admin_sessions SET revoked_at = NOW(), revoked_by = 'everywhere' WHERE admin_email = ? AND revoked_at IS NULL")
                    ->execute([ADMIN_EMAIL]);
                if ($raw !== '') {   // also the marker the carry-over check reads when this browser's row was already gone
                    $pdo->prepare("UPDATE admin_sessions SET revoked_at = NOW(), revoked_by = 'everywhere' WHERE token_hash = ?")->execute([adminTokenHash($raw)]);
                }
            } elseif ($raw !== '') {
                $pdo->prepare("UPDATE admin_sessions SET revoked_at = NOW(), revoked_by = 'sign_out' WHERE token_hash = ? AND revoked_at IS NULL")
                    ->execute([adminTokenHash($raw)]);
            }
        } catch (Throwable $e) {
            error_log('admin sign-out failed: ' . $e->getMessage());
        }
    }
    if (isset($_COOKIE[ADMIN_SESSION_COOKIE])) {
        adminSetKeyCookie('', time() - 42000);
        unset($_COOKIE[ADMIN_SESSION_COOKIE]);
    }
    adminSessionReset();
    $GLOBALS['__jsmAdmin'] = null;
    startAdminSession();
    if (session_status() !== PHP_SESSION_ACTIVE) return;   // nothing more to tear down (no PHP session cookie)
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** The admin's live sign-ins (Manage → Tools → Your sign-ins), newest first; 'current' marks this browser's. */
function adminSessionsList(PDO $pdo): array {
    if (!adminSessionsReady($pdo)) return [];
    try {
        $st = $pdo->prepare("SELECT id, via, ip, user_agent, created_at, last_seen_at, expires_at, token_hash FROM admin_sessions
            WHERE admin_email = ? AND revoked_at IS NULL AND expires_at > NOW()
              AND created_at > NOW() - INTERVAL " . adminMaxSeconds() . " SECOND
              AND last_seen_at > NOW() - INTERVAL " . adminIdleSeconds() . " SECOND
            ORDER BY last_seen_at DESC, id DESC LIMIT 50");
        $st->execute([ADMIN_EMAIL]);
        $all = $st->fetchAll();
    } catch (Throwable $e) {   // a broken table must not take Manage → Tools down with it
        error_log('admin sessions list failed: ' . $e->getMessage());
        return [];
    }
    $mine = adminKeyCookie() !== '' ? adminTokenHash(adminKeyCookie()) : '';
    $out = [];
    foreach ($all as $r) {
        $r['current'] = $mine !== '' && hash_equals((string)$r['token_hash'], $mine);
        unset($r['token_hash']);
        $out[] = $r;
    }
    return $out;
}

/** Revoke one of the admin's sign-ins (not this browser's — that is Sign out). True when a live row was revoked. */
function adminSessionRevoke(PDO $pdo, int $id): bool {
    if (!adminSessionsReady($pdo)) return false;
    $mine = adminKeyCookie() !== '' ? adminTokenHash(adminKeyCookie()) : '';
    $st = $pdo->prepare("UPDATE admin_sessions SET revoked_at = NOW(), revoked_by = 'admin' WHERE id = ? AND admin_email = ? AND revoked_at IS NULL AND token_hash <> ?");
    $st->execute([$id, ADMIN_EMAIL, $mine]);
    return $st->rowCount() > 0;
}
