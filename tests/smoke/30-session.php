<?php
/**
 * Admin sign-in that lasts (auth.php, admin_sessions — migrate.php 56; logout.php, admin-sessions.php, login.php).
 *
 *   The report: "the portal signs me out every 15 min". The admin was a PHP session alone (cookie lifetime 0, no
 *   gc_maxlifetime of its own), and a shared host deletes idle session files after ~24 minutes. Now the sign-in is a
 *   database row behind the jsm_admin_key cookie; the PHP session is only scratch state, rebuilt when it disappears.
 *
 *   - the real login.php (the test password from tests/test-auth.php): row + HttpOnly / SameSite=Lax / path-scoped
 *     cookie that expires with the hard maximum, a fresh PHP session id, a retired previous key
 *   - the PHP session file deleted (= the host's clean-up) → still signed in, with or without the jsm_admin cookie
 *   - idle limit (12 h) and hard maximum (7 days), the clock faked in the database; last_seen_at at most every 5 min
 *   - config.php admin_idle_hours / admin_max_days (and nonsense values → the defaults)
 *   - signed out mid-page: pages → login with reason=expired and back to the same page (a POST → the page it came
 *     from), fetches → 401 JSON + X-Portal-Auth: signed-out, the client gate → the admin login, not the magic link
 *   - sign-out revokes the row; sign out everywhere (same-site only); one other device; other devices; Manage list
 *   - client sessions: 30 days from the magic link, never touched by any of this (no PHP session, no admin row)
 *   - before migrate 56: the old behaviour exactly; migrate 56 fresh + re-run; a PHP session from before it is carried
 *     over (no new sign-in), but never past the hard maximum or a "Sign out everywhere"
 */
require __DIR__ . '/lib.php';

$APP  = rtrim((string)(getenv('APP_DIR') ?: '/tmp/portal-test/site/portal'), '/');
$ROOT = rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: dirname($APP, 2)), '/');
$SESS = rtrim((string)(getenv('SESSION_DIR') ?: $ROOT . '/sessions'), '/');
$MAIL = rtrim((string)(getenv('MAIL_DIR') ?: $ROOT . '/mail'), '/');
// The app's clock (db.php pins America/New_York on its MySQL session): NOW() here must mean the same wall time.
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");

const PW = 'portal-test-admin-password';
const LANCE = 'lance@joustmedia.com';

/** name=value of the LAST Set-Cookie for $name in a reply ('' when none, or when it deletes the cookie). */
function cookieOf(array $r, string $name): string {
    $out = '';
    foreach ($r['cookies'] as $c) {
        if (preg_match('/^' . preg_quote($name, '/') . '=([^;]*)/', $c, $m)) $out = ($m[1] === '' || $m[1] === 'deleted') ? '' : $name . '=' . $m[1];
    }
    return $out;
}
function cookieLine(array $r, string $name): string {
    $out = '';
    foreach ($r['cookies'] as $c) if (strpos($c, $name . '=') === 0) $out = $c;
    return $out;
}
/** A browser: name → value. */
final class Jar {
    public array $c = [];
    public function take(array $r): void {
        foreach ($r['cookies'] as $line) {
            if (!preg_match('/^([^=]+)=([^;]*)/', $line, $m)) continue;
            if ($m[2] === '' || $m[2] === 'deleted' || preg_match('/expires=[^;]*19[0-9]{2}/i', $line)) unset($this->c[$m[1]]); else $this->c[$m[1]] = $m[2];
        }
    }
    public function header(): string { $o = []; foreach ($this->c as $k => $v) $o[] = $k . '=' . $v; return implode('; ', $o); }
    public function get(string $path, array $h = []): array { $r = get($path, 'anon', ['Cookie' => $this->header()] + $h); $this->take($r); return $r; }
    public function post(string $path, array $d, array $h = []): array { $r = post($path, $d, 'anon', [], ['Cookie' => $this->header()] + $h); $this->take($r); return $r; }
    public function sid(): string { return (string)($this->c['jsm_admin'] ?? ''); }
    public function key(): string { return (string)($this->c['jsm_admin_key'] ?? ''); }
}
/** The real sign-in form → a signed-in browser. */
function login(?Jar $j = null, string $return = ''): Jar {
    $j = $j ?: new Jar();
    $r = $j->post('login.php' . ($return !== '' ? '?return=' . rawurlencode($return) : ''), ['email' => LANCE, 'password' => PW]);
    is($r['code'], 302, 'login redirects');
    ok($j->key() !== '', 'the key cookie');
    return $j;
}
function rowOf(Jar $j): ?array {
    $r = rows("SELECT * FROM admin_sessions WHERE token_hash = ?", [hash('sha256', $j->key())]);
    return $r[0] ?? null;
}
/** Signed in as the admin? (Manage answers 200 to the admin only; anyone else → login.) */
function isIn(Jar $j): bool {
    $r = $j->get('manage.php?section=tools');
    if ($r['code'] === 200) { has($r['body'], 'data-role="admin"'); return true; }
    is($r['code'], 302, 'not signed in → login');
    has($r['location'], 'login.php');
    return false;
}
function sessFile(Jar $j): string { global $SESS; return $SESS . '/sess_' . $j->sid(); }
function killPhpSessions(): void { global $SESS; foreach (glob($SESS . '/sess_*') ?: [] as $f) @unlink($f); }
function ageRow(Jar $j, string $col, string $interval): void {
    db()->prepare("UPDATE admin_sessions SET {$col} = NOW() - INTERVAL {$interval} WHERE token_hash = ?")->execute([hash('sha256', $j->key())]);
}
function hasTable(): bool { return (int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_sessions'") > 0; }
function runMigrate(): string {
    global $APP;
    return (string)shell_exec('cd ' . escapeshellarg($APP) . ' && PORTAL_TEST=1 PORTAL_TEST_ROLE=admin php -d auto_prepend_file=' . escapeshellarg(dirname(__DIR__) . '/test-auth.php') . ' migrate.php 2>&1');
}
/** Run $fn with extra config.php entries (restored afterwards). */
function withConfig(array $extra, callable $fn): void {
    global $APP;
    $file = $APP . '/config.php';
    $orig = (string)file_get_contents($file);
    $add = '';
    foreach ($extra as $k => $v) $add .= '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
    $new = preg_replace('/\];\s*$/', $add . "];\n", $orig, 1);
    ok($new !== $orig, 'config rewritten');
    file_put_contents($file, $new);
    sleep(3);   // php -S + opcache: config.php is re-read once its timestamp is revalidated
    try { $fn(); } finally { file_put_contents($file, $orig); sleep(3); }
}
function stest(string $name, callable $fn): void {
    test($name, static function () use ($fn) {
        if (!hasTable()) runMigrate();
        db()->exec("TRUNCATE TABLE admin_sessions");
        $fn();
    });
}
const SPA = ['Accept' => 'application/json', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Site' => 'same-origin'];

// =====================================================================================================================
// Sign-in
// =====================================================================================================================
stest('login.php: a database row + HttpOnly / SameSite=Lax / portal-scoped key cookie expiring with the hard maximum', function () {
    $j = new Jar();
    $pre = $j->post('login.php', ['email' => LANCE, 'password' => 'wrong']);
    is($pre['code'], 200, 'wrong password → the form again');
    has($pre['body'], 'Email or password is incorrect.');
    is(cookieOf($pre, 'jsm_admin_key'), '', 'no key for a wrong password');
    is((int)q1("SELECT COUNT(*) FROM admin_sessions"), 0, 'no row');
    $before = $j->sid();
    ok($before !== '', 'the failed POST left a PHP session');
    $r = $j->post('login.php?return=' . rawurlencode('/portal/posts.php?client=kenda'), ['email' => 'Lance@JoustMedia.com', 'password' => PW]);
    is($r['code'], 302);
    is($r['location'], '/portal/posts.php?client=kenda', 'back to the return path');
    ok($j->sid() !== '' && $j->sid() !== $before, 'session_regenerate_id on sign-in');
    $line = cookieLine($r, 'jsm_admin_key');
    ok((bool)preg_match('/^jsm_admin_key=[A-Za-z0-9_-]{43};/', $line), 'a 256-bit token: ' . $line);
    has($line, 'path=/portal/');
    has(strtolower($line), 'httponly');
    has(strtolower($line), 'samesite=lax');
    ok(preg_match('/expires=([^;]+)/i', $line, $m) === 1, 'a real expiry (not a browser-session cookie)');
    $exp = strtotime($m[1]);
    ok(abs($exp - (time() + 7 * 86400)) < 120, 'expires in 7 days: ' . $m[1]);
    $row = rowOf($j);
    ok($row !== null, 'the row (sha256 of the cookie, never the token)');
    is((int)q1("SELECT COUNT(*) FROM admin_sessions WHERE token_hash = ?", [$j->key()]), 0, 'the raw token is not stored');
    is($row['admin_email'], LANCE);
    is($row['via'], 'password');
    is($row['revoked_at'], null);
    is($row['user_agent'], SMOKE_UA);
    is((int)q1("SELECT TIMESTAMPDIFF(HOUR, created_at, expires_at) FROM admin_sessions WHERE id = ?", [$row['id']]), 168, 'row expires after 7 days');
    ok(isIn($j), 'signed in');
    // signing in again on this browser (its sign-in ended) retires its previous key
    $old = $row['id'];
    ageRow($j, 'last_seen_at', '13 HOUR');
    login($j);
    is((string)q1("SELECT revoked_by FROM admin_sessions WHERE id = ?", [$old]), 'replaced', 'old key retired');
    is((int)q1("SELECT COUNT(*) FROM admin_sessions WHERE revoked_at IS NULL"), 1, 'one live sign-in');
    // already signed in → login.php passes straight through
    $p = $j->get('login.php?return=' . rawurlencode('/portal/manage.php'));
    is($p['code'], 302);
    is($p['location'], '/portal/manage.php');
});

stest('the host deletes the PHP session file (gc) → still signed in; with the key alone a fresh PHP session is made', function () {
    $j = login();
    ok(is_file(sessFile($j)), 'the PHP session file exists');
    @unlink(sessFile($j));
    ok(!is_file(sessFile($j)), 'deleted (= session.gc_maxlifetime / the host clean-up)');
    ok(isIn($j), 'still signed in after the clean-up');
    killPhpSessions();
    unset($j->c['jsm_admin']);   // the browser lost its session cookie too (closed, iOS home-screen app relaunched)
    $r = $j->get('manage.php?section=tools');
    is($r['code'], 200, 'the key alone signs in');
    ok(cookieOf($r, 'jsm_admin') !== '', 'a fresh PHP session cookie');
    ok(is_file(sessFile($j)), 'and its file');
    // View as client lives in the PHP session and works on the rebuilt one
    $v = $j->post('view-as.php', ['client' => 'kenda']);
    is($v['code'], 303);
    has(status($j->get('?client=kenda'), 200)['body'], 'data-view-as="kenda"');
    is($j->get('view-as.php?exit=1')['code'], 303);
    // 30 deleted-session cycles in a row: never signed out
    for ($i = 0; $i < 5; $i++) { killPhpSessions(); ok(isIn($j), "cycle {$i}"); }
    is((int)q1("SELECT COUNT(*) FROM admin_sessions"), 1, 'no extra rows');
});

// =====================================================================================================================
// Idle limit + hard maximum (clock faked in the database)
// =====================================================================================================================
stest('idle: 11 h quiet → still in (last_seen_at moves); 13 h → signed out to login with reason=expired, back to the page', function () {
    $j = login();
    ageRow($j, 'last_seen_at', '11 HOUR');
    ok(isIn($j), '11 h idle: in');
    ok((int)q1("SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) FROM admin_sessions WHERE id = ?", [rowOf($j)['id']]) < 60, 'last_seen_at refreshed');
    ageRow($j, 'last_seen_at', '13 HOUR');
    $r = $j->get('manage.php?section=tools&client=kenda');
    is($r['code'], 302);
    has($r['location'], 'login.php?return=' . rawurlencode('/portal/manage.php?section=tools&client=kenda'));
    has($r['location'], 'reason=expired');
    is($r['headers']['x-portal-auth'] ?? '', 'signed-out');
    has(status($j->get(substr($r['location'], strlen('/portal/'))), 200)['body'], 'data-login-expired', 'the friendly notice');
    is(rowOf($j)['revoked_at'], null, 'idle is a limit, not a revoke');
    // sign in again → back to the same page
    $j2 = login($j, '/portal/manage.php?section=tools&client=kenda');
    ok(isIn($j2));
});

stest('last_seen_at is written at most every 5 minutes', function () {
    $j = login();
    ageRow($j, 'last_seen_at', '2 MINUTE');
    ok(isIn($j));
    $ago = (int)q1("SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) FROM admin_sessions WHERE id = ?", [rowOf($j)['id']]);
    ok($ago >= 110, '2 min: not rewritten (' . $ago . ' s)');
    ageRow($j, 'last_seen_at', '6 MINUTE');
    ok(isIn($j));
    ok((int)q1("SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) FROM admin_sessions WHERE id = ?", [rowOf($j)['id']]) < 60, '6 min: rewritten');
});

stest('hard maximum: 6 days after sign-in (active) → in; 8 days → signed out however active', function () {
    $j = login();
    ageRow($j, 'created_at', '6 DAY');
    ok(isIn($j), '6 days: in');
    ageRow($j, 'created_at', '8 DAY');
    ok(!isIn($j), '8 days: out (last_seen_at is fresh)');
    // expires_at alone also ends it
    $k = login();
    db()->prepare("UPDATE admin_sessions SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?")->execute([rowOf($k)['id']]);
    ok(!isIn($k), 'past expires_at: out');
});

stest('config.php admin_idle_hours / admin_max_days override the defaults (existing sign-ins included); nonsense → defaults', function () {
    $j = login();
    withConfig(['admin_idle_hours' => 1, 'admin_max_days' => 1], function () use ($j) {
        ageRow($j, 'last_seen_at', '30 MINUTE');
        ok(isIn($j), '30 min idle with a 1 h limit: in');
        $m = status($j->get('manage.php?section=tools'), 200)['body'];
        has($m, 'until you go 1 hour without using the portal', 'Manage says so');
        has($m, 'at most 1 day after signing in');
        ageRow($j, 'last_seen_at', '2 HOUR');
        ok(!isIn($j), '2 h idle: out');
        $k = login();
        ageRow($k, 'created_at', '25 HOUR');
        ok(!isIn($k), '25 h after sign-in with a 1-day maximum: out');
        $line = cookieLine((new Jar())->post('login.php', ['email' => LANCE, 'password' => PW]), 'jsm_admin_key');
        ok(preg_match('/expires=([^;]+)/i', $line, $mm) === 1 && abs(strtotime($mm[1]) - (time() + 86400)) < 120, 'cookie expires in 1 day');
    });
    withConfig(['admin_idle_hours' => 'soon', 'admin_max_days' => 0], function () {
        $j = login();
        ageRow($j, 'last_seen_at', '11 HOUR');
        ageRow($j, 'created_at', '6 DAY');
        ok(isIn($j), 'defaults: 12 h / 7 days');
    });
    withConfig(['admin_idle_hours' => 48], function () {
        $j = login();
        ageRow($j, 'last_seen_at', '30 HOUR');
        ok(isIn($j), '30 h idle with a 48 h limit: in');
    });
});

// =====================================================================================================================
// Signed out mid-page: never a silent failure
// =====================================================================================================================
stest('after the sign-in ended: fetches → 401 JSON + X-Portal-Auth, form POSTs → login and back to their page, client pages → admin login', function () {
    $j = login();
    ageRow($j, 'last_seen_at', '13 HOUR');
    $ref = base() . '/manage.php?section=tools&client=kenda';
    // a fetch to an admin endpoint (requireAdmin)
    $r = $j->post('admin-sessions.php', ['action' => 'others'], SPA + ['Referer' => $ref]);
    is($r['code'], 401);
    is($r['headers']['x-portal-auth'] ?? '', 'signed-out');
    is($r['json']['ok'] ?? null, false);
    is($r['json']['signedOut'] ?? null, true);
    is($r['json']['error'] ?? '', 'You were signed out. Please sign in again.');
    has($r['json']['signIn'] ?? '', 'login.php?return=' . rawurlencode('/portal/manage.php?section=tools&client=kenda'));
    has($r['json']['signIn'] ?? '', 'reason=expired');
    // an endpoint with its own currentAdmin() check still carries the header (app.js shows "Sign in again")
    $e = $j->post('export.php?client=kenda', ['action' => 'estimate'], SPA);
    is($e['code'], 403, 'export.php refuses');
    is($e['json']['error'] ?? '', 'Admin sign-in required');
    is($e['headers']['x-portal-auth'] ?? '', 'signed-out');
    // a client-facing endpoint (the gate): the admin login, not the client's magic link
    $s = $j->post('status.php', ['id' => 2, 'comment' => 'a long note', 'client' => 'kenda'], SPA + ['Referer' => base() . '/posts.php?client=kenda&post=2']);
    is($s['code'], 401);
    is($s['json']['signedOut'] ?? null, true);
    has($s['json']['signIn'] ?? '', 'login.php?return=' . rawurlencode('/portal/posts.php?client=kenda&post=2'));
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE detail = 'a long note'"), 0, 'nothing written');
    $p = $j->get('posts.php?client=kenda');
    is($p['code'], 302);
    has($p['location'], 'login.php?return=' . rawurlencode('/portal/posts.php?client=kenda'));
    has($p['location'], 'reason=expired');
    // a plain HTML form POST (navigation) → login, back to the page the form was on
    $f = $j->post('admin-sessions.php', ['action' => 'others'], ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Site' => 'same-origin', 'Accept' => 'text/html', 'Referer' => $ref]);
    is($f['code'], 302);
    has($f['location'], 'login.php?return=' . rawurlencode('/portal/manage.php?section=tools&client=kenda'));
    // an off-site Referer is never a return path
    $x = $j->post('admin-sessions.php', ['action' => 'others'], ['Accept' => 'text/html', 'Referer' => 'https://evil.example/portal/manage.php']);
    hasNot($x['location'], 'evil');
    // nobody at all (no key): the plain old answers, no header
    $n = get('manage.php', 'anon');
    is($n['code'], 302);
    hasNot($n['location'], 'reason=expired');
    ok(!isset($n['headers']['x-portal-auth']), 'no header without a key');
    has(status(get('posts.php?client=kenda', 'anon'), 302)['location'], 'sign-in', 'a stranger still gets the client sign-in');
    // app.js turns the header into the banner
    $js = (string)file_get_contents(dirname(__DIR__, 2) . '/static/js/app.js');
    has($js, "'X-Portal-Auth'");
    has($js, "App.urls.build('login', { 'return': here, reason: 'expired' })");
});

// =====================================================================================================================
// Sign-out
// =====================================================================================================================
stest('sign-out revokes the row and drops the cookies; the old key never works again', function () {
    $j = login();
    $key = $j->key(); $sid = $j->sid();
    $id = rowOf($j)['id'];
    $r = $j->get('logout.php');
    is($r['code'], 302);
    has($r['location'], 'login.php?signed_out=1');
    is((string)q1("SELECT revoked_by FROM admin_sessions WHERE id = ?", [$id]), 'sign_out');
    ok(q1("SELECT revoked_at FROM admin_sessions WHERE id = ?", [$id]) !== null);
    has(cookieLine($r, 'jsm_admin_key'), 'path=/portal/', 'the key cookie is deleted on its own path');
    is($j->key(), '', 'browser dropped the key');
    ok(!isIn($j), 'signed out');
    // a copied (stolen) key + session id: still nothing
    $c = new Jar(); $c->c = ['jsm_admin_key' => $key, 'jsm_admin' => $sid];
    ok(!isIn($c), 'the old key is dead');
});

stest('sign out everywhere (same-site POST) ends every device; a cross-site one only signs this browser out', function () {
    $a = login(); $b = login(); $c = login();
    is((int)q1("SELECT COUNT(*) FROM admin_sessions WHERE revoked_at IS NULL"), 3);
    // Manage lists them, this device marked, the others with Sign out
    $m = status($a->get('manage.php?section=tools'), 200)['body'];
    has($m, 'data-admin-sessions');
    is(substr_count($m, 'data-admin-session="'), 3);
    has($m, 'data-admin-session="' . rowOf($a)['id'] . '" data-admin-session-current');
    is(substr_count($m, 'data-admin-session-revoke'), 2, 'Sign out on the other two only');
    has($m, 'data-admin-sessions-everywhere');
    // one other device
    $r = $a->post('admin-sessions.php', ['action' => 'revoke', 'id' => rowOf($c)['id']], ['Referer' => base() . '/manage.php?section=tools']);
    is($r['code'], 303);
    has($r['location'], 'section=tools');
    ok(!isIn($c), 'C signed out');
    ok(isIn($a) && isIn($b), 'A and B still in');
    is($a->post('admin-sessions.php', ['action' => 'revoke', 'id' => rowOf($a)['id']], SPA)['code'], 404, 'not this device (that is Sign out)');
    is($a->post('admin-sessions.php', ['action' => 'others'], ['Sec-Fetch-Site' => 'cross-site'])['code'], 403, 'cross-site refused');
    is($a->get('admin-sessions.php?action=others')['code'], 405, 'GET refused');
    ok(isIn($b), 'B untouched');
    // cross-site "everywhere": only this browser
    $x = $b->post('logout.php', ['everywhere' => '1'], ['Sec-Fetch-Site' => 'cross-site']);
    has($x['location'], 'signed_out=1');
    ok(!isIn($b), 'B signed out');
    ok(isIn($a), 'A not touched by a cross-site everywhere');
    // the real one
    $d = login();
    $e = $a->post('logout.php', ['everywhere' => '1'], ['Sec-Fetch-Site' => 'same-origin']);
    has($e['location'], 'signed_out=everywhere');
    is((int)q1("SELECT COUNT(*) FROM admin_sessions WHERE revoked_at IS NULL"), 0, 'every row revoked');
    ok((int)q1("SELECT COUNT(*) FROM admin_sessions WHERE revoked_by = 'everywhere'") >= 2);
    ok(!isIn($a) && !isIn($d), 'A and D out');
    has(status(get('login.php?signed_out=everywhere', 'anon'), 200)['body'], 'Signed out on every device.');
});

stest('other devices: "Sign out other devices" keeps this one', function () {
    $a = login(); $b = login(); $c = login();
    $r = $a->post('admin-sessions.php', ['action' => 'others'], SPA);
    is($r['code'], 200);
    is($r['json']['message'] ?? '', 'Signed out 2 other devices.');
    ok(isIn($a) && !isIn($b) && !isIn($c));
});

// =====================================================================================================================
// Clients: 30-day sessions, untouched
// =====================================================================================================================
stest('client sessions: 30 days from the magic link, no PHP session, no admin row; PHP session clean-up changes nothing', function () {
    global $MAIL;
    foreach (glob($MAIL . '/*.json') ?: [] as $f) @unlink($f);
    db()->exec("DELETE FROM auth_attempts");
    status(post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon'), 200);
    $m = glob($MAIL . '/*.json') ?: [];
    ok($m !== [], 'the sign-in email');
    $mail = json_decode((string)file_get_contents(end($m)), true);
    ok(preg_match('#sign-in(?:\.php)?\?t=([A-Za-z0-9_-]+)#', (string)$mail['text'], $t) === 1, 'the link');
    $c = new Jar();
    $r = $c->post('sign-in.php', ['action' => 'consume', 't' => $t[1]]);
    is($r['code'], 303);
    $line = cookieLine($r, 'jsm_client');
    ok(preg_match('/expires=([^;]+)/i', $line, $mm) === 1 && abs(strtotime($mm[1]) - (time() + 30 * 86400)) < 120, '30-day cookie: ' . $line);
    is($c->sid(), '', 'no PHP session for a client');
    is((int)q1("SELECT TIMESTAMPDIFF(DAY, created_at, expires_at) FROM client_sessions WHERE revoked_at IS NULL ORDER BY id DESC LIMIT 1"), 30, '30-day row');
    $home = $c->get('?client=kenda');
    is($home['code'], 200);
    has($home['body'], 'data-role="client"');
    is(cookieOf($home, 'jsm_admin'), '', 'still no PHP session');
    killPhpSessions();
    is($c->get('posts.php?client=kenda')['code'], 200, 'unaffected by the session clean-up');
    // 29 days on (row aged in the database), quiet for 20 hours: still in
    db()->exec("UPDATE client_sessions SET created_at = NOW() - INTERVAL 29 DAY, last_seen_at = NOW() - INTERVAL 20 HOUR, expires_at = NOW() + INTERVAL 1 DAY WHERE revoked_at IS NULL");
    is($c->get('posts.php?client=kenda')['code'], 200, 'day 29: in');
    db()->exec("UPDATE client_sessions SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE revoked_at IS NULL");
    $out = $c->get('posts.php?client=kenda');
    is($out['code'], 302);
    has($out['location'], 'sign-in', 'day 30: the client sign-in (not the admin login)');
    hasNot($out['location'], 'login.php');
    is((int)q1("SELECT COUNT(*) FROM admin_sessions"), 0, 'no admin row ever');
});

// =====================================================================================================================
// Before migrate 56 / migrate 56 / the carry-over
// =====================================================================================================================
test('before migrate 56: the old behaviour (PHP session only); migrate 56 fresh + re-run; a pre-migrate sign-in is carried over', function () {
    if (!hasTable()) runMigrate();
    try {
        db()->exec("DROP TABLE admin_sessions");
        // the seat and the pages work
        status(get('manage.php', 'admin'), 200);
        status(get('posts.php?client=kenda', 'admin'), 200);
        status(get('posts.php?client=kenda', 'client'), 200);
        has(status(get('manage.php?section=tools', 'admin'), 200)['body'], 'data-tools-maintenance');
        hasNot(get('manage.php?section=tools', 'admin')['body'], 'data-admin-sessions', 'no sign-ins card before the table');
        // a real login: PHP session only, no key
        $j = new Jar();
        $r = $j->post('login.php', ['email' => LANCE, 'password' => PW]);
        is($r['code'], 302);
        is($j->key(), '', 'no key before migrate');
        ok($j->sid() !== '');
        ok(isIn($j), 'signed in (PHP session)');
        // the old weakness, unchanged until migrate: the host deletes the file → signed out
        @unlink(sessFile($j));
        ok(!isIn($j), 'gc → signed out (why migrate 56 matters)');
        $j = new Jar();
        $j->post('login.php', ['email' => LANCE, 'password' => PW]);
        ok(isIn($j));
        $old = $j; // stays signed in across the migration
        $stale = new Jar();
        $stale->post('login.php', ['email' => LANCE, 'password' => PW]);
        ok(isIn($stale));
        $logout = $stale->get('logout.php');
        has($logout['location'], 'signed_out=1');
        ok(!isIn($stale), 'logout works before migrate');
        // a pre-migrate sign-in older than the hard maximum (its admin_login_at aged in the session file)
        $ancient = new Jar();
        $ancient->post('login.php', ['email' => LANCE, 'password' => PW]);
        $f = sessFile($ancient);
        file_put_contents($f, preg_replace('/admin_login_at\|i:\d+;/', 'admin_login_at|i:' . (time() - 8 * 86400) . ';', (string)file_get_contents($f)));
        // migrate 56
        $out = runMigrate();
        has($out, 'Created `admin_sessions`');
        ok(hasTable());
        is(array_column(rows("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_sessions' ORDER BY ORDINAL_POSITION"), 'COLUMN_NAME'),
           ['id', 'admin_email', 'token_hash', 'via', 'ip', 'user_agent', 'created_at', 'last_seen_at', 'expires_at', 'revoked_at', 'revoked_by']);
        has(runMigrate(), '`admin_sessions` already exists — skipped', 're-run: nothing');
        db()->exec("TRUNCATE TABLE admin_sessions");
        // the pre-migrate sign-in: carried over on its next request, no new sign-in
        $r = $old->get('manage.php?section=tools');
        is($r['code'], 200, 'still signed in after migrate');
        ok($old->key() !== '', 'a key was issued');
        $row = rowOf($old);
        is($row['via'], 'carried');
        ok((int)q1("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM admin_sessions WHERE id = ?", [$row['id']]) >= 0, 'created at its sign-in time');
        // older than the hard maximum: not carried
        ok(!isIn($ancient), 'an 8-day-old pre-migrate sign-in is not carried over');
        is($ancient->key(), '');
        killPhpSessions();
        ok(isIn($old), 'and now it survives the clean-up');
        // "Sign out everywhere" after a pre-migrate sign-in: never carried
        db()->exec("TRUNCATE TABLE admin_sessions");
        db()->exec("DROP TABLE admin_sessions");
        $pre = new Jar();
        $pre->post('login.php', ['email' => LANCE, 'password' => PW]);
        ok(isIn($pre));
        sleep(1);
        runMigrate();
        $now = login();
        $now->post('logout.php', ['everywhere' => '1'], ['Sec-Fetch-Site' => 'same-origin']);
        ok(!isIn($pre), 'a pre-migrate sign-in older than "Sign out everywhere" is refused');
        is((int)q1("SELECT COUNT(*) FROM admin_sessions WHERE via = 'carried'"), 0);
    } finally {
        if (!hasTable()) runMigrate();
    }
});

finish();
