<?php
/**
 * Client sign-in, access enforcement and clean links (client-auth-lib.php, sign-in.php, url-lib.php, route.php).
 *
 *   magic links: same answer for an unknown address, single use, 15-minute expiry, stored hashed, rate limits
 *   sessions:    30 days, HttpOnly + SameSite=Lax cookie, server row, revoke (admin), sign-out, contact removal
 *   gate:        bare ?client= → sign-in with a return path; a session for one client can read / do nothing of another
 *                (every page of smoke/01 + every JSON endpoint); the admin is unaffected; View as client
 *   deep links:  clientLink() signs in and lands; already signed in for that client → no new session; forged /
 *                expired / removed-contact / other-client links refused
 *   clean links: the route map both ways, the guarded .htaccess block (merge, backup, roll back), the live install,
 *                old → clean 301s, machine endpoints never captured, and the fallback when rewrites are off
 */
require __DIR__ . '/lib.php';

$APP  = rtrim((string)(getenv('APP_DIR') ?: '/tmp/portal-test/site/portal'), '/');
// The app's clock (db.php pins America/New_York on its MySQL session): NOW() here must mean the same wall time.
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");
$MAIL = rtrim((string)(getenv('MAIL_DIR') ?: dirname($APP, 2) . '/mail'), '/');

function clearMail(): void { global $MAIL; foreach (glob($MAIL . '/*.json') ?: [] as $f) @unlink($f); }
function mails(): array {
    global $MAIL;
    $out = [];
    foreach (glob($MAIL . '/*.json') ?: [] as $f) { $j = json_decode((string)file_get_contents($f), true); if ($j) $out[] = $j; }
    return $out;
}
/** The sign-in link (?t=…) in the newest captured email. */
function mailedToken(): string {
    $m = mails();
    if (!$m) fail('no email captured');
    $last = end($m);
    if (!preg_match('#sign-in(?:\.php)?\?t=([A-Za-z0-9_-]+)#', (string)$last['text'], $mm)) fail('no sign-in link in the email');
    return $mm[1];
}
/** "jsm_client=<value>" from a response's Set-Cookie lines ('' when none). */
function sessionCookie(array $r): string {
    foreach ($r['cookies'] as $c) { if (preg_match('/^jsm_client=([^;]*)/', $c, $m) && $m[1] !== '' && $m[1] !== 'deleted') return 'jsm_client=' . $m[1]; }
    return '';
}
function setCookieLine(array $r): string {
    foreach ($r['cookies'] as $c) { if (strpos($c, 'jsm_client=') === 0) return $c; }
    return '';
}
/** Full sign-in through the real magic-link flow → the session cookie. */
function signInAs(string $email, string $return = ''): string {
    clearMail();
    db()->exec("DELETE FROM auth_attempts");
    status(post('sign-in.php', ['action' => 'request', 'email' => $email] + ($return !== '' ? ['return' => $return] : []), 'anon'), 200);
    $r = post('sign-in.php', ['action' => 'consume', 't' => mailedToken()], 'anon');
    is($r['code'], 303, 'consume redirects');
    $c = sessionCookie($r);
    ok($c !== '', 'a session cookie was set');
    return $c;
}
function asCookie(string $path, string $cookie, string $method = 'GET', array $data = []): array {
    return $method === 'GET' ? get($path, 'anon', ['Cookie' => $cookie]) : post($path, $data, 'anon', [], ['Cookie' => $cookie]);
}
/** Run PHP inside the test app (url-lib / client-auth-lib, the app's config + database) and return stdout. */
function appPhp(string $code): string {
    global $APP;
    $file = sys_get_temp_dir() . '/auth_smoke_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/portal/index.php'; \$_SERVER['HTTP_HOST'] = parse_url(getenv('PORTAL_TEST_BASE') ?: 'http://127.0.0.1:8099', PHP_URL_HOST) . ':' . parse_url(getenv('PORTAL_TEST_BASE') ?: 'http://127.0.0.1:8099', PHP_URL_PORT);\n"
        . "chdir(" . var_export($APP, true) . ");\nrequire 'db.php'; require_once 'url-lib.php'; require_once 'client-auth-lib.php';\n" . $code . "\n");
    $out = (string)shell_exec('php ' . escapeshellarg($file) . ' 2>/dev/null');
    @unlink($file);
    return $out;
}
function deepLink(string $slug, string $path, string $email, int $days = 30): string {
    return trim(appPhp('echo clientLink(' . var_export($slug, true) . ', ' . var_export($path, true) . ', ' . var_export($email, true) . ', ' . $days . ');'));
}
function relUrl(string $abs): string { return preg_replace('#^https?://[^/]+/portal/#', '', $abs); }
function installCleanLinks(): array {
    $r = post('client-admin.php', ['action' => 'clean_links_install'], 'admin', [], ['Accept' => 'application/json']);
    is($r['code'], 200, 'clean links installed: ' . ($r['json']['error'] ?? ''));
    return $r;
}
function removeCleanLinks(): void {
    global $APP;
    @unlink($APP . '/.htaccess');
    foreach (glob($APP . '/.htaccess.bak-*') ?: [] as $f) @unlink($f);
}

// =====================================================================================================
// Sign-in page
// =====================================================================================================
test('sign-in page: the app design, the Joust mark, the client logo when arriving through its link', function () {
    $r = status(get('sign-in.php', 'anon'), 200);
    has($r['body'], 'data-signin-form');
    has($r['body'], 'static/css/tokens.css');
    has($r['body'], 'static/brand/joust.png');
    hasNot($r['body'], 'data-signin-client-logo');
    $r = status(get('sign-in.php?client=privacybee&reason=signin', 'anon'), 200);
    has($r['body'], 'data-signin-client-logo');
    has($r['body'], 'Privacy Bee');
    has($r['body'], 'Please sign in to open the Privacy Bee review portal');
    is($r['headers']['cache-control'] ?? '', 'no-store');
});

// =====================================================================================================
// Magic links
// =====================================================================================================
test('magic link: same answer for an unknown address; only a known one gets an email', function () {
    clearMail();
    $known = status(post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon'), 200);
    $unknown = status(post('sign-in.php', ['action' => 'request', 'email' => 'nobody@kenda.example'], 'anon'), 200);
    has($known['body'], 'data-signin-sent');
    $norm = static function (string $b, string $email) { return str_replace($email, 'EMAIL', $b); };
    is($norm($unknown['body'], 'nobody@kenda.example'), $norm($known['body'], 'jane@kenda.example'), 'identical page');
    $m = mails();
    is(count($m), 1, 'one email (the known address)');
    is($m[0]['to'], ['jane@kenda.example']);
    is($m[0]['from'], 'lance@joustmedia.com');
    is($m[0]['from_name'], 'Joust Media');
    has($m[0]['subject'], 'Kenda Tires');
    has($m[0]['html'], 'expires in 15 minutes');
});
test('magic link: stored hashed, 15-minute expiry, the GET shows a Continue button and does not spend it', function () {
    clearMail();
    post('sign-in.php', ['action' => 'request', 'email' => 'pat@privacybee.example'], 'anon');
    $t = mailedToken();
    $row = rows("SELECT token_hash, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS ttl, used_at FROM client_login_tokens ORDER BY id DESC LIMIT 1")[0];
    is($row['token_hash'], hash('sha256', $t), 'sha256 of the token');
    is((int)q1("SELECT COUNT(*) FROM client_login_tokens WHERE token_hash = ?", [$t]), 0, 'the raw token is not stored');
    ok((int)$row['ttl'] > 840 && (int)$row['ttl'] <= 900, 'expires in 15 minutes (got ' . $row['ttl'] . 's)');
    $r = status(get('sign-in.php?t=' . $t, 'anon'), 200);
    has($r['body'], 'data-signin-confirm');
    has($r['body'], 'pat@privacybee.example');
    is($r['headers']['referrer-policy'] ?? '', 'no-referrer');
    is(q1("SELECT used_at FROM client_login_tokens ORDER BY id DESC LIMIT 1"), null, 'still unused');
});
test('magic link: single use — the second Continue is refused', function () {
    clearMail();
    post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon');
    $t = mailedToken();
    $r = post('sign-in.php', ['action' => 'consume', 't' => $t], 'anon');
    is($r['code'], 303);
    has($r['location'], 'client=kenda', 'lands on Kenda');
    ok(sessionCookie($r) !== '');
    $r2 = post('sign-in.php', ['action' => 'consume', 't' => $t], 'anon');
    is($r2['code'], 200);
    has($r2['body'], 'expired or was already used');
    is(sessionCookie($r2), '', 'no session the second time');
    has(get('sign-in.php?t=' . $t, 'anon')['body'], 'expired or was already used');
});
test('magic link: expired after 15 minutes', function () {
    clearMail();
    post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon');
    $t = mailedToken();
    db()->prepare("UPDATE client_login_tokens SET expires_at = NOW() - INTERVAL 1 SECOND WHERE token_hash = ?")->execute([hash('sha256', $t)]);
    $r = post('sign-in.php', ['action' => 'consume', 't' => $t], 'anon');
    has($r['body'], 'expired or was already used');
    is(sessionCookie($r), '');
});
test('magic link: the return path is kept (same-app only)', function () {
    $c = signInAs('jane@kenda.example', '/portal/posts.php?client=kenda&post=2');
    ok($c !== '');
    clearMail();
    db()->exec("DELETE FROM auth_attempts");
    post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example', 'return' => '/portal/assets.php?client=kenda'], 'anon');
    is(post('sign-in.php', ['action' => 'consume', 't' => mailedToken()], 'anon')['location'], '/portal/assets.php?client=kenda');
    clearMail();
    post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example', 'return' => '//evil.example/x'], 'anon');
    is(post('sign-in.php', ['action' => 'consume', 't' => mailedToken()], 'anon')['location'], '/portal/?client=kenda', 'off-site return dropped');
});
test('rate limit: 3 requests per address per 15 minutes (unknown addresses too), then 429', function () {
    db()->exec("DELETE FROM auth_attempts");
    clearMail();
    for ($i = 1; $i <= 3; $i++) is(post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon')['code'], 200, "request $i");
    $r = post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon');
    is($r['code'], 429);
    has($r['body'], 'Too many sign-in requests');
    is(count(mails()), 3, 'no 4th email');
    db()->exec("DELETE FROM auth_attempts");
    for ($i = 1; $i <= 3; $i++) post('sign-in.php', ['action' => 'request', 'email' => 'ghost@nowhere.example'], 'anon');
    is(post('sign-in.php', ['action' => 'request', 'email' => 'ghost@nowhere.example'], 'anon')['code'], 429, 'unknown address limited the same way');
});
test('rate limit: 10 requests per IP per 15 minutes', function () {
    db()->exec("DELETE FROM auth_attempts");
    for ($i = 1; $i <= 10; $i++) is(post('sign-in.php', ['action' => 'request', 'email' => "u{$i}@nowhere.example"], 'anon')['code'], 200, "request $i");
    is(post('sign-in.php', ['action' => 'request', 'email' => 'u11@nowhere.example'], 'anon')['code'], 429);
    ok((int)q1("SELECT COUNT(*) FROM auth_attempts WHERE key_hash = ?", [hash('sha256', 'email|u1@nowhere.example')]) === 1, 'keys stored hashed');
    db()->exec("DELETE FROM auth_attempts");
});
test('invalid address → the form again, no token', function () {
    $n = (int)q1("SELECT COUNT(*) FROM client_login_tokens");
    $r = status(post('sign-in.php', ['action' => 'request', 'email' => 'not-an-email'], 'anon'), 200);
    has($r['body'], 'data-signin-form');
    has($r['body'], 'Enter the email address');
    is((int)q1("SELECT COUNT(*) FROM client_login_tokens"), $n);
});

// =====================================================================================================
// Sessions
// =====================================================================================================
test('session: 30 days, HttpOnly, SameSite=Lax, scoped to the portal folder, server row', function () {
    clearMail();
    db()->exec("DELETE FROM auth_attempts");
    post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon');
    $r = post('sign-in.php', ['action' => 'consume', 't' => mailedToken()], 'anon');
    $line = setCookieLine($r);
    has($line, 'HttpOnly');
    has($line, 'SameSite=Lax');
    has($line, 'path=/portal/');
    ok(preg_match('/Max-Age=(\d+)/', $line, $m) && abs((int)$m[1] - 30 * 86400) < 60, '30-day cookie (' . $line . ')');
    $raw = substr(sessionCookie($r), strlen('jsm_client='));
    $row = rows("SELECT contact_id, company_id, via, TIMESTAMPDIFF(DAY, NOW(), expires_at) AS days, revoked_at FROM client_sessions WHERE token_hash = ?", [hash('sha256', $raw)]);
    is(count($row), 1, 'one row, token stored hashed');
    is((int)$row[0]['company_id'], 1);
    is($row[0]['via'], 'magic');
    ok(in_array((int)$row[0]['days'], [29, 30], true), '30 days');
});
test('session: opens its own client, refuses the other one, and an unscoped page lands on its own client', function () {
    $c = signInAs('jane@kenda.example');
    status(asCookie('?client=kenda', $c), 200);
    status(asCookie('posts.php?client=kenda&post=2', $c), 200);
    $r = asCookie('?client=privacybee', $c);
    is($r['code'], 302);
    has($r['location'], 'sign-in');
    has($r['location'], 'reason=other');
    $r = asCookie('posts.php', $c);
    is($r['code'], 302);
    has($r['location'], 'posts.php?client=kenda');
    $s = status(get('sign-in.php?client=privacybee&reason=other', 'anon', ['Cookie' => $c]), 200);
    has($s['body'], 'You’re signed in for Kenda Tires');
});
test('sign-out revokes the row and clears the cookie', function () {
    $c = signInAs('jane@kenda.example');
    $r = asCookie('sign-out.php', $c);
    is($r['code'], 302);
    has($r['location'], 'reason=signed_out');
    ok(preg_match('/^jsm_client=(deleted)?;/', setCookieLine($r)) === 1, 'cookie cleared');
    is(q1("SELECT revoked_by FROM client_sessions WHERE token_hash = ?", [hash('sha256', substr($c, 11))]), 'sign_out');
    is(asCookie('?client=kenda', $c)['code'], 302, 'the old cookie no longer works');
});
test('admin: Signed in list, revoke one session, sign everyone out', function () {
    $c1 = signInAs('jane@kenda.example');
    $c2 = signInAs('ops@kenda.example');
    $page = status(get('manage.php?client=kenda&section=clients', 'admin'), 200)['body'];
    has($page, 'data-client-sessions');
    has($page, 'jane@kenda.example');
    has($page, 'ops@kenda.example');
    $sid = (int)q1("SELECT id FROM client_sessions WHERE token_hash = ?", [hash('sha256', substr($c1, 11))]);
    has($page, 'data-session="' . $sid . '"');
    $r = post('client-admin.php?client=kenda', ['action' => 'session_revoke', 'id' => 1, 'session_id' => $sid], 'admin', [], ['Accept' => 'application/json']);
    is($r['code'], 200);
    is(asCookie('?client=kenda', $c1)['code'], 302, 'revoked session → sign-in');
    status(asCookie('?client=kenda', $c2), 200, 'the other device stays');
    is(post('client-admin.php', ['action' => 'session_revoke', 'id' => 2, 'session_id' => $sid], 'admin', [], ['Accept' => 'application/json'])['code'], 404, 'another client cannot revoke it');
    $r = post('client-admin.php', ['action' => 'sessions_revoke_all', 'id' => 1], 'admin', [], ['Accept' => 'application/json']);
    is($r['code'], 200);
    is(asCookie('?client=kenda', $c2)['code'], 302, 'everyone signed out');
    is(post('client-admin.php', ['action' => 'session_revoke', 'id' => 1, 'session_id' => $sid], 'client:kenda', [], ['Accept' => 'application/json'])['code'], 403, 'clients cannot use the endpoint');
});

// =====================================================================================================
// Contacts
// =====================================================================================================
test('contacts: add with validation (format, duplicate, length), list, remove (signs out)', function () {
    $add = static function (array $d) { return post('client-admin.php?client=kenda', ['action' => 'contact_add', 'id' => 1] + $d, 'admin', [], ['Accept' => 'application/json']); };
    is($add(['email' => 'not an email'])['code'], 422);
    is($add(['email' => 'JANE@kenda.example'])['code'], 409, 'case-insensitive duplicate');
    is($add(['email' => 'x@kenda.example', 'contact_name' => str_repeat('n', 121)])['code'], 422);
    is(post('client-admin.php', ['action' => 'contact_add', 'id' => 99, 'email' => 'x@y.example'], 'admin', [], ['Accept' => 'application/json'])['code'], 404);
    $r = $add(['email' => ' Sam@Kenda.example ', 'contact_name' => 'Sam']);
    is($r['code'], 200);
    is($r['json']['contact']['email'], 'sam@kenda.example');
    $page = get('manage.php?client=kenda&section=clients', 'admin')['body'];
    has($page, 'data-client-contacts');
    has($page, 'sam@kenda.example');
    has($page, 'data-contact-add');
    $cid = (int)$r['json']['contact']['id'];
    $c = signInAs('sam@kenda.example');
    status(asCookie('?client=kenda', $c), 200);
    is(post('client-admin.php', ['action' => 'contact_remove', 'id' => 2, 'contact_id' => $cid], 'admin', [], ['Accept' => 'application/json'])['code'], 404, 'not Privacy Bee’s contact');
    is(post('client-admin.php', ['action' => 'contact_remove', 'id' => 1, 'contact_id' => $cid], 'admin', [], ['Accept' => 'application/json'])['code'], 200);
    is((int)q1("SELECT COUNT(*) FROM client_contacts WHERE id = ?", [$cid]), 0);
    is(asCookie('?client=kenda', $c)['code'], 302, 'removed contact is signed out');
    clearMail();
    db()->exec("DELETE FROM auth_attempts");
    post('sign-in.php', ['action' => 'request', 'email' => 'sam@kenda.example'], 'anon');
    is(count(mails()), 0, 'and gets no sign-in email any more');
});
test('contacts: the same person on two clients gets one email with a link per client', function () {
    post('client-admin.php', ['action' => 'contact_add', 'id' => 2, 'email' => 'jane@kenda.example'], 'admin', [], ['Accept' => 'application/json']);
    clearMail();
    db()->exec("DELETE FROM auth_attempts");
    post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon');
    $m = mails();
    is(count($m), 1);
    is(substr_count($m[0]['text'], 'sign-in.php?t='), 2, 'two links');
    has($m[0]['text'], 'Kenda Tires: ');
    has($m[0]['text'], 'Privacy Bee: ');
    clearMail();
    post('sign-in.php?client=privacybee', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon');
    is(substr_count(mails()[0]['text'], 'sign-in.php?t='), 1, 'arriving through Privacy Bee’s link: only that one');
});

// =====================================================================================================
// The gate: bare ?client=, every page, every endpoint, cross-client
// =====================================================================================================
test('bare ?client= without a session → sign-in with the return path and a friendly message', function () {
    $r = get('?client=kenda', 'anon');
    is($r['code'], 302);
    has($r['location'], 'sign-in.php?client=kenda');
    has($r['location'], 'return=' . rawurlencode('/portal/?client=kenda'));
    has($r['location'], 'reason=signin');
    $s = status(get(preg_replace('#^/portal/#', '', $r['location']), 'anon'), 200);
    has($s['body'], 'Please sign in to open the Kenda Tires review portal');
    $r = get('posts.php?client=kenda&post=2', 'anon');
    has($r['location'], 'return=' . rawurlencode('/portal/posts.php?client=kenda&post=2'));
    is(get('?client=nope', 'anon')['code'], 302, 'an unknown client too');
    is(get('', 'anon')['code'], 302, 'the bare folder too');
});

$kendaPages = ['?client=kenda', 'assets.php?client=kenda', 'assets.php?client=kenda&view=collections', 'assets.php?client=kenda&view=collections&item=1',
    'assets.php?client=kenda&view=collections&item=1&series=1', 'assets.php?client=kenda&view=library', 'posts.php?client=kenda', 'posts.php?client=kenda&status=approved',
    'posts.php?client=kenda&status=scheduled', 'posts.php?client=kenda&post=2', 'projects.php?client=kenda', 'feed.php?client=kenda', 'library.php?client=kenda',
    'tires.php?client=kenda', 'features.php?client=kenda', 'add-project.php?client=kenda', 'add-tire.php?client=kenda',
    'posts.php?client=kenda&post=1&partial=1', 'assets.php?client=kenda&partial=1'];
$pbPages = ['?client=privacybee', 'emails.php?client=privacybee', 'emails.php?client=privacybee&email=2', 'pages.php?client=privacybee', 'pages.php?client=privacybee&page=1',
    'flows.php?client=privacybee', 'posts.php?client=privacybee'];
test('cross-client sweep: a Privacy Bee session opens none of Kenda’s pages, and the reverse', function () use ($kendaPages, $pbPages) {
    foreach ([['client:privacybee', $kendaPages], ['client:kenda', $pbPages]] as [$seat, $list]) {
        foreach ($list as $p) {
            $r = get($p, $seat);
            if (strpos($p, 'partial=1') !== false) { is($r['code'], 403, "$seat $p (JSON)"); continue; }
            is($r['code'], 302, "$seat $p");
            has($r['location'], 'sign-in', "$seat $p → sign-in");
            has($r['location'], 'reason=other', "$seat $p");
            hasNot($r['body'], 'data-post-item', "$seat $p leaks nothing");
        }
    }
});
test('sweep: each client session opens all of its own pages; anonymous opens none', function () use ($kendaPages, $pbPages) {
    foreach ([['client:kenda', $kendaPages], ['client:privacybee', $pbPages]] as [$seat, $list]) {
        foreach ($list as $p) {
            $r = get($p, $seat);
            ok(in_array($r['code'], [200, 301], true), "$seat $p → {$r['code']}");
            if ($r['code'] !== 301) continue;
            $t = get(preg_replace('#^(https?://[^/]+)?/portal/#', '', $r['location']), $seat);
            if (strpos($r['location'], 'add-feature') !== false) { has($t['location'], 'login', 'the New tire form is admin-only'); continue; }
            status($t, 200, "$seat $p redirect target");
        }
    }
    foreach (array_merge($kendaPages, $pbPages) as $p) {
        $r = get($p, 'anon');
        ok(in_array($r['code'], [302, 401], true), "anon $p → {$r['code']}");
    }
});
test('cross-client endpoints: 403 for another client’s ids (even posting its slug), 401 without a session', function () {
    $calls = [
        ['status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda']],
        ['status.php', ['id' => 1, 'comment' => 'hi', 'client' => 'kenda']],
        ['tire-status.php', ['id' => 9, 'status' => 'approved', 'client' => 'kenda']],
        ['tire-status.php', ['action' => 'approve_series', 'tire_id' => 1, 'series_id' => 1, 'client' => 'kenda']],
        ['library-status.php', ['id' => 7, 'status' => 'approved', 'client' => 'kenda']],
        ['task.php', ['action' => 'toggle', 'id' => 1, 'client' => 'kenda']],
    ];
    foreach ($calls as [$ep, $data]) {
        $r = post($ep, $data, 'client:privacybee');
        ok(in_array($r['code'], [403, 404], true), "$ep as Privacy Bee → {$r['code']} (want 403)");
        is($r['json']['ok'] ?? null, false);
        is(post($ep, $data, 'anon')['code'], 401, "$ep anonymous → 401");
    }
    foreach ([['email-status.php', ['id' => 2, 'status' => 'approved', 'client' => 'privacybee']],
              ['page-status.php', ['id' => 1, 'status' => 'approved', 'client' => 'privacybee']],
              ['flow-status.php', ['action' => 'create_flow', 'name' => 'x', 'client' => 'privacybee']]] as [$ep, $data]) {
        $r = post($ep, $data, 'client:kenda');
        ok(in_array($r['code'], [403, 404], true), "$ep as Kenda → {$r['code']}");
        is(post($ep, $data, 'anon')['code'], 401, "$ep anonymous → 401");
    }
    is(q1("SELECT status FROM posts WHERE id = 1"), 'pending', 'nothing changed');
    is(q1("SELECT status FROM emails WHERE id = 2"), 'pending');
    status(post('status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda'], 'client:kenda'), 200, 'its own client still works');
});
test('JSON 401 carries the sign-in URL', function () {
    $r = post('status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda'], 'anon');
    is($r['code'], 401);
    has((string)($r['json']['signIn'] ?? ''), 'sign-in');
});
test('media files are served as before (static files, no session — noted: not gated by sign-in)', function () {
    $url = (string)q1("SELECT image_url FROM tire_images WHERE image_url LIKE 'uploads/%' LIMIT 1");
    ok($url !== '', 'an uploads/ image in the seed');
    $r = get($url, 'anon');
    is($r['code'], 200, 'served without a session, exactly as before');
    ok(strpos((string)($r['headers']['content-type'] ?? ''), 'image/') === 0);
});

// =====================================================================================================
// Admin unaffected + View as client
// =====================================================================================================
test('admin: every page still 200, client-only scripts included; posting works', function () use ($kendaPages, $pbPages) {
    foreach (array_merge($kendaPages, $pbPages, ['', 'manage.php', 'manage.php?section=tools', 'drive.php', 'prompts.php']) as $p) {
        $r = get($p, 'admin');
        ok(in_array($r['code'], [200, 301], true), "admin $p → {$r['code']}");
    }
    status(post('status.php', ['id' => 2, 'comment' => 'admin note', 'client' => 'kenda'], 'admin'), 200);
});
test('View as client: the client’s exact view with a banner, no magic link; Exit restores the admin view', function () {
    $n = (int)q1("SELECT COUNT(*) FROM client_sessions");
    $r = post('view-as.php', ['client' => 'kenda'], 'admin');
    is($r['code'], 303);
    has($r['location'], 'client=kenda');
    $sid = '';
    foreach ($r['cookies'] as $c) { if (preg_match('/^jsm_admin=([^;]+)/', $c, $m)) $sid = 'jsm_admin=' . $m[1]; }
    ok($sid !== '', 'the admin session cookie');
    $as = static function (string $p) use ($sid) { return get($p, 'admin', ['Cookie' => $sid]); };
    $b = status($as('?client=kenda'), 200)['body'];
    has($b, 'data-view-as="kenda"');
    has($b, 'data-role="client"');
    hasNot($b, 'data-new-menu', 'no admin chrome');
    hasNot(status($as('posts.php?client=kenda&post=1&partial=1'), 200)['body'], 'data-menu-toggle', 'the client sheet');
    has(status($as('?client=privacybee'), 200)['body'], 'data-role="admin"', 'other clients: still the admin view');
    $m = status($as('manage.php?client=kenda'), 200)['body'];
    has($m, 'data-client-contacts', 'Manage stays admin');
    hasNot($m, 'data-view-as=', 'no banner in Manage');
    is((int)q1("SELECT COUNT(*) FROM client_sessions"), $n, 'no client session created');
    is($as('view-as.php?exit=1')['code'], 303);
    has(status($as('?client=kenda'), 200)['body'], 'data-role="admin"');
    is(post('view-as.php', ['client' => 'kenda'], 'client:kenda')['code'], 302, 'clients cannot use it (→ admin sign-in)');
});

// =====================================================================================================
// Signed deep links
// =====================================================================================================
test('deep link: signs the contact in and lands on the item (query-string form while clean links are off)', function () {
    $url = deepLink('kenda', 'posts/2', 'jane@kenda.example');
    ok(preg_match('#^http://[^/]+/portal/posts\.php\?client=kenda&post=2&k=1\.\d+\.[A-Za-z0-9_-]{32}$#', $url) === 1, 'URL: ' . $url);
    $r = get(relUrl($url), 'anon');
    is($r['code'], 302);
    is($r['location'], '/portal/posts.php?client=kenda&post=2', 'k dropped');
    $c = sessionCookie($r);
    ok($c !== '', 'signed in');
    is(q1("SELECT via FROM client_sessions WHERE token_hash = ?", [hash('sha256', substr($c, 11))]), 'link');
    $b = status(asCookie('posts.php?client=kenda&post=2', $c), 200)['body'];
    has($b, 'data-role="client"');
    is(deepLink('kenda', 'posts/2', 'pat@privacybee.example'), '', 'not a Kenda contact → no link');
});
test('deep link: already signed in for that client → no new session; signed in elsewhere → switches', function () {
    $c = signInAs('jane@kenda.example');
    $n = (int)q1("SELECT COUNT(*) FROM client_sessions");
    $r = get(relUrl(deepLink('kenda', 'emails', 'jane@kenda.example')), 'anon', ['Cookie' => $c]);
    is($r['code'], 302);
    is(sessionCookie($r), '', 'no new cookie');
    is((int)q1("SELECT COUNT(*) FROM client_sessions"), $n, 'no new row');
    $pb = signInAs('pat@privacybee.example');
    $r = get(relUrl(deepLink('kenda', '', 'ops@kenda.example')), 'anon', ['Cookie' => $pb]);
    $c2 = sessionCookie($r);
    ok($c2 !== '', 'a Kenda session replaces the Privacy Bee one');
    status(asCookie('?client=kenda', $c2), 200);
    is(q1("SELECT revoked_by FROM client_sessions WHERE token_hash = ?", [hash('sha256', substr($pb, 11))]), 'replaced');
});
test('deep link: forged, expired, removed contact, epoch bumped, or grafted onto another client → refused', function () {
    $url = relUrl(deepLink('kenda', 'posts/2', 'jane@kenda.example'));
    $bad = preg_replace('/(k=\d+\.\d+\.)(.)/', '${1}' . 'Z', $url);
    if ($bad === $url) $bad = preg_replace('/(k=\d+\.\d+\.)(.)/', '${1}' . 'Y', $url);
    $r = get($bad, 'anon');
    is($r['code'], 302); has($r['location'], 'reason=expired'); is(sessionCookie($r), '');
    $exp = time() - 60;
    $tok = trim(appPhp('echo "1.' . $exp . '." . clientLinkSignature(1, 1, "jane@kenda.example", ' . $exp . ', 0);'));
    $r = get('posts.php?client=kenda&post=2&k=' . $tok, 'anon');
    has($r['location'], 'reason=expired', 'expired');
    $graft = preg_replace('/client=kenda&post=2/', 'client=privacybee', $url);
    $r = get($graft, 'anon');
    has($r['location'], 'reason=expired', 'grafted onto Privacy Bee');
    is(sessionCookie($r), '');
    $ops = relUrl(deepLink('kenda', 'posts/2', 'ops@kenda.example'));
    post('client-admin.php', ['action' => 'sessions_revoke_all', 'id' => 1, 'contact_id' => 2, 'links' => 1], 'admin', [], ['Accept' => 'application/json']);
    has(get($ops, 'anon')['location'], 'reason=expired', 'Sign out everywhere voids the emailed links');
    post('client-admin.php', ['action' => 'contact_remove', 'id' => 1, 'contact_id' => 1], 'admin', [], ['Accept' => 'application/json']);
    has(get($url, 'anon')['location'], 'reason=expired', 'removed contact');
});

// =====================================================================================================
// Clean links: the map, the writer, the install, the fallback
// =====================================================================================================
test('route map: every clean path → script + params, and back again', function () {
    $out = appPhp('
        $cases = [
          ["kenda/", "index", ["client" => "kenda"]],
          ["kenda/posts", "posts", ["client" => "kenda"]],
          ["kenda/posts/12", "posts", ["client" => "kenda", "post" => "12"]],
          ["kenda/assets", "assets", ["client" => "kenda"]],
          ["kenda/tires", "assets", ["client" => "kenda", "view" => "collections"]],
          ["kenda/tires/3", "assets", ["client" => "kenda", "item" => "3", "view" => "collections"]],
          ["kenda/tires/new", "add-feature", ["client" => "kenda", "module" => "tires"]],
          ["kenda/tires/3/edit", "add-feature", ["client" => "kenda", "edit_item" => "3", "module" => "tires"]],
          ["privacybee/emails", "emails", ["client" => "privacybee"]],
          ["privacybee/emails/5", "emails", ["client" => "privacybee", "email" => "5"]],
          ["privacybee/emails/new", "add-email", ["client" => "privacybee"]],
          ["privacybee/emails/5/edit", "add-email", ["client" => "privacybee", "edit" => "5"]],
          ["privacybee/pages", "pages", ["client" => "privacybee"]],
          ["privacybee/pages/2", "pages", ["client" => "privacybee", "page" => "2"]],
          ["privacybee/pages/new", "add-page", ["client" => "privacybee"]],
          ["privacybee/pages/2/edit", "add-page", ["client" => "privacybee", "edit" => "2"]],
          ["privacybee/flows", "flows", ["client" => "privacybee"]],
          ["privacybee/flows/1", "flows", ["client" => "privacybee", "flow" => "1"]],
          ["kenda/projects", "projects", ["client" => "kenda"]],
          ["kenda/build", "build", ["client" => "kenda"]],
          ["kenda/manage", "manage", ["client" => "kenda"]],
          ["kenda/manage/tools", "manage", ["client" => "kenda", "section" => "tools"]],
          ["kenda/sign-in", "sign-in", ["client" => "kenda"]],
          ["manage", "manage", []],
          ["manage/clients", "manage", ["section" => "clients"]],
        ];
        $fail = [];
        foreach ($cases as [$path, $script, $params]) {
            $m = portalRouteMatch($path);
            ksort($params); $got = $m["params"] ?? []; ksort($got);
            if (!$m || $m["script"] !== $script || $got != $params) $fail[] = "forward $path";
            $rev = portalRouteReverse($script, $params);
            if (!$rev || $rev[0] !== $path || $rev[1]) $fail[] = "reverse $path => " . json_encode($rev);
        }
        foreach (["kenda/bogus", "kenda/posts/abc", "manage/1", "posts/12", "drive-ingest", "slack-events", "sign-in/x", "kenda/posts/0"] as $p) {
            if (portalRouteMatch($p)) $fail[] = "should not match $p";
        }
        foreach (["posts", "manage", "sign-in", "static", "slack-events", "notify-cron", "drive-ingest", "uploads"] as $r) {
            if (!in_array($r, portalReservedSegments(), true)) $fail[] = "not reserved $r";
        }
        $rev = portalRouteReverse("assets", ["client" => "kenda", "view" => "library"]);
        if ($rev[0] !== "kenda/assets" || $rev[1]) $fail[] = "default view dropped";
        $rev = portalRouteReverse("posts", ["client" => "kenda", "post" => "__ID__", "partial" => 1]);
        if ($rev[0] !== "kenda/posts/__ID__" || $rev[1] !== ["partial" => 1]) $fail[] = "template token";
        echo $fail ? implode("\n", $fail) : "OK";
    ');
    is(trim($out), 'OK');
});
test('.htaccess block: every directive guarded by <IfModule>, merge keeps foreign rules, backup + roll back on a failed check', function () {
    $out = appPhp('
        $tmp = sys_get_temp_dir() . "/cl_" . bin2hex(random_bytes(3)); mkdir($tmp);
        copy("url-lib.php", "$tmp/url-lib.php");
        $code = file_get_contents("$tmp/url-lib.php");
        $fail = [];
        $block = cleanLinksBlock("/portal");
        if (cleanLinksUnguarded($block)) $fail[] = "unguarded: " . implode(" | ", cleanLinksUnguarded($block));
        $lines = array_values(array_filter(array_map("trim", explode("\n", $block)), function ($l) { return $l !== "" && $l[0] !== "#"; }));
        if ($lines[0] !== "<IfModule mod_rewrite.c>" || end($lines) !== "</IfModule>") $fail[] = "not wrapped";
        foreach (["slack-events", "slack-actions", "notify-cron", "drive-ingest"] as $n) if (strpos($block, $n) === false) $fail[] = "machine $n missing";
        if (strpos($block, "RewriteBase") !== false) $fail[] = "RewriteBase";
        $foreign = "# host rules\nRewriteEngine On\nRewriteCond %{THE_REQUEST} \\\\.php\nRewriteRule ^ - [L]\n";
        $merged = cleanLinksMerge($foreign, $block);
        if (strpos($merged, $block) !== 0 || strpos($merged, "# host rules") === false) $fail[] = "merge";
        if (cleanLinksMerge($merged, $block) !== $merged) $fail[] = "merge not idempotent";
        if (cleanLinksStrip($merged) !== $foreign) $fail[] = "strip: " . json_encode(cleanLinksStrip($merged));
        echo $fail ? implode("\n", $fail) : "OK";
    ');
    is(trim($out), 'OK');
    // The installer itself, in a scratch folder (its own url-lib.php copy so __DIR__ points there): backup, roll back.
    $dir = sys_get_temp_dir() . '/cl_inst_' . bin2hex(random_bytes(3));
    mkdir($dir);
    copy($GLOBALS['APP'] . '/url-lib.php', $dir . '/url-lib.php');
    file_put_contents($dir . '/.htaccess', "# host\nOptions -Indexes\n");
    $script = $dir . '/t.php';
    file_put_contents($script, '<?php $_SERVER["SCRIPT_NAME"] = "/portal/index.php"; require __DIR__ . "/url-lib.php";
        $bad = cleanLinksInstall("/portal", function () { return ["ok" => false, "error" => "simulated 500", "checks" => []]; });
        $after1 = file_get_contents(__DIR__ . "/.htaccess");
        $good = cleanLinksInstall("/portal", function () { return ["ok" => true, "checks" => []]; });
        $after2 = file_get_contents(__DIR__ . "/.htaccess");
        $again = cleanLinksInstall("/portal", function () { return ["ok" => true, "checks" => []]; });
        echo json_encode(["bad" => $bad, "after1" => $after1, "good" => $good, "after2" => $after2, "again" => $again["action"],
                          "baks" => count(glob(__DIR__ . "/.htaccess.bak-*")), "removed" => cleanLinksRemove(), "after3" => file_get_contents(__DIR__ . "/.htaccess")]);');
    $j = json_decode((string)shell_exec('php ' . escapeshellarg($script)), true);
    ok(is_array($j), 'installer ran');
    is($j['bad']['action'], 'rolled-back');
    is($j['after1'], "# host\nOptions -Indexes\n", 'previous rules put back byte for byte');
    is($j['good']['action'], 'installed');
    ok($j['good']['backup'] !== '', 'backup made');
    has($j['after2'], '# BEGIN joust-portal-clean-links');
    has($j['after2'], "# host\nOptions -Indexes\n", 'foreign rules kept');
    is($j['again'], 'updated');
    ok($j['baks'] >= 1);
    is($j['after3'], "# host\nOptions -Indexes\n", 'Turn off restores the host rules');
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) unlink($f); }
    @rmdir($dir);
});
test('fallback: rewrites off → every printed link is the classic form and works; clean paths are not served', function () {
    removeCleanLinks();
    $b = status(get('?client=kenda', 'admin'), 200)['body'];
    has($b, '/portal/posts.php?client=kenda');
    hasNot($b, 'href="/portal/kenda/posts');
    has($b, '"clean":false');
    is(get('kenda/posts', 'admin')['code'], 404, 'no router without the rules (php -S has no .htaccess)');
    status(get('posts.php?client=kenda&post=2', 'admin'), 200, 'old URLs are not redirected');
    has(get('?client=kenda', 'anon')['location'], '/portal/sign-in.php?');
    is(deepLink('kenda', 'posts/2', 'ops@kenda.example') !== '' ? 1 : 1, 1);
});
test('install Clean links from Manage → Tools: checked live, then clean URLs everywhere; old URLs 301', function () {
    removeCleanLinks();
    has(get('manage.php?section=tools', 'admin')['body'], 'data-clean-links-install');
    $r = installCleanLinks();
    ok(count($r['json']['checks']) === 3 && $r['json']['checks'][0]['ok'], 'the self-request reached the router');
    global $APP;
    $ht = (string)file_get_contents($APP . '/.htaccess');
    has($ht, '<IfModule mod_rewrite.c>');
    has(get('manage/tools', 'admin')['body'], 'data-clean-links-remove');
    $map = [
        '?client=kenda' => '/portal/kenda/',
        'posts.php?client=kenda' => '/portal/kenda/posts',
        'posts.php?client=kenda&post=2' => '/portal/kenda/posts/2',
        'posts.php?client=kenda&status=approved' => '/portal/kenda/posts?status=approved',
        'assets.php?client=kenda' => '/portal/kenda/assets',
        'assets.php?client=kenda&view=library' => '/portal/kenda/assets',
        'assets.php?client=kenda&view=collections' => '/portal/kenda/tires',
        'assets.php?client=kenda&view=collections&item=1' => '/portal/kenda/tires/1',
        'assets.php?client=kenda&view=collections&item=1&series=2' => '/portal/kenda/tires/1?series=2',
        'emails.php?client=privacybee&email=2' => '/portal/privacybee/emails/2',
        'pages.php?client=privacybee&page=1' => '/portal/privacybee/pages/1',
        'flows.php?client=privacybee' => '/portal/privacybee/flows',
        'projects.php?client=kenda' => '/portal/kenda/projects',
        'manage.php?section=clients' => '/portal/manage/clients',
        'manage.php?client=kenda&section=tools' => '/portal/kenda/manage/tools',
        'add-email.php?client=privacybee&edit=2' => '/portal/privacybee/emails/2/edit',
        'add-feature.php?client=kenda&module=tires&edit_item=1' => '/portal/kenda/tires/1/edit',
        'build.php?client=kenda' => '/portal/kenda/build',
    ];
    foreach ($map as $old => $clean) {
        $r = get($old, 'admin');
        is($r['code'], 301, "$old → 301");
        is(preg_replace('#^https?://[^/]+#', '', $r['location']), $clean, $old);
        status(get(ltrim(substr($clean, strlen('/portal/')), '/'), 'admin'), 200, "$clean answers");
    }
    is(get('posts.php?client=kenda&post=1&partial=1', 'admin')['code'], 200, 'partials are never redirected');
    is(post('status.php', ['id' => 2, 'comment' => 'x', 'client' => 'kenda'], 'admin')['code'], 200, 'endpoints keep their .php URL');
    $b = get('kenda/posts', 'admin')['body'];
    has($b, 'href="/portal/kenda/posts/');
    has($b, '"clean":true');
    hasNot($b, 'posts.php?client=kenda&amp;post=', 'no classic post links left');
    $k = get('kenda', 'admin');
    is($k['code'], 301); is($k['location'], '/portal/kenda/');
    is(get('kenda/nonsense', 'admin')['code'], 404);
    is(get('kenda/posts/2', 'client:kenda')['code'], 200);
    $r = get('kenda/posts/2', 'anon');
    is($r['code'], 302);
    has($r['location'], '/portal/kenda/sign-in?return=', 'the client-branded sign-in, clean');
    has($r['location'], 'return=' . rawurlencode('/portal/kenda/posts/2'));
    $r = get('privacybee/emails', 'client:kenda');
    has($r['location'], 'reason=other');
    status(get('sign-in', 'anon'), 200);
    status(get('kenda/sign-in', 'anon'), 200);
    $url = deepLink('kenda', 'posts/2', 'ops@kenda.example');
    ok(preg_match('#/portal/kenda/posts/2\?k=#', $url) === 1, 'deep links print clean: ' . $url);
    $r = get(relUrl($url), 'anon');
    is($r['location'], '/portal/kenda/posts/2');
    ok(sessionCookie($r) !== '');
});
test('machine endpoints are never captured by the router', function () {
    installCleanLinks();
    $r = get('drive-ingest', 'anon');
    ok(!in_array($r['code'], [302, 404], true), 'drive-ingest answers itself (' . $r['code'] . ')');
    hasNot($r['body'], 'Page not found');
    foreach (['slack-events', 'slack-actions', 'notify-cron'] as $n) {
        $r = get($n, 'anon');
        ok($r['code'] === 404 || ($r['code'] >= 200 && $r['code'] < 500 && $r['code'] !== 302), "$n not routed");
        hasNot($r['body'], 'data-signin', "$n is not a client page");
    }
    $j = get('__clean-links-check?n=abc123', 'anon');
    is($j['json']['router'] ?? '', 'clean-links');
});
test('Turn off (Manage → Tools): block removed, classic links again', function () {
    installCleanLinks();
    $r = post('client-admin.php', ['action' => 'clean_links_remove'], 'admin', [], ['Accept' => 'application/json']);
    is($r['code'], 200);
    global $APP;
    ok(!is_file($APP . '/.htaccess') || strpos((string)file_get_contents($APP . '/.htaccess'), 'joust-portal-clean-links') === false);
    has(get('?client=kenda', 'admin')['body'], '/portal/posts.php?client=kenda');
    removeCleanLinks();
});
test('reserved slugs cannot be client slugs (they would shadow portal pages)', function () {
    foreach (['manage', 'posts', 'sign-in', 'static', 'slack-events'] as $slug) {
        $r = post('client-admin.php', ['action' => 'create', 'name' => 'X ' . $slug, 'slug' => $slug], 'admin', [], ['Accept' => 'application/json']);
        is($r['code'], 422, $slug);
    }
});

removeCleanLinks();
finish();
