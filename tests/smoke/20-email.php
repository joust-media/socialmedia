<?php
/**
 * Email through Google + client emails + tracking (gmail-lib.php, client-notify-lib.php, tracking-lib.php; migrate 45–49):
 *   Connect Google (OAuth state / CSRF, admin only, the account check, the scope check), the tokens encrypted at rest,
 *   the transport choice, the Gmail transport (MIME parsed back, threading headers, token refresh), client emails
 *   (batch windows, per-recipient signed links, unsubscribe + preferences, the client switches, never internal notes
 *   or another client's data), inbound replies (In-Reply-To, the [J#…] token, sender check, quote stripping for
 *   Gmail / Outlook / Apple, attachments, dedupe, the unmatched list), the Joust Inbox (definitions, sorting, resolve),
 *   unread markers (admin + client contact), the weekly report numbers, previews, the router.
 *
 * Against the fakes: tests/google-stub.php (OAuth + Gmail API, state in $PORTAL_TEST_ROOT/google) and the mail sink.
 * Every test starts from the seed.
 */
require __DIR__ . '/lib.php';

const CRON_TK = 'test-cron-token-0123456789abcdef';
const J = ['Accept' => 'application/json'];
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");
date_default_timezone_set('America/New_York');

$ROOT = rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test'), '/');
$APP  = getenv('APP_DIR') ?: $ROOT . '/site/portal';
$MAIL = getenv('MAIL_DIR') ?: $ROOT . '/mail';
$GDIR = getenv('GOOGLE_STUB_DIR') ?: $ROOT . '/google';

function reseedNow(): void {
    global $APP, $ROOT;
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($APP) . ' ' . escapeshellarg(getenv('MEDIA_DIR') ?: $ROOT . '/site/media') . ' 2>&1', $out, $rc);
    if ($rc !== 0) throw new RuntimeException('seed failed: ' . implode("\n", $out));
}
function etest(string $name, callable $fn): void { test($name, static function () use ($fn) { reseedNow(); $fn(); }); }

// ---- helpers -------------------------------------------------------------------------------------------------------
function mails(): array { global $MAIL; $o = []; foreach (glob($MAIL . '/*.json') ?: [] as $f) $o[] = json_decode((string)file_get_contents($f), true); return $o; }
function clearMail(): void { global $MAIL; foreach (glob($MAIL . '/*') ?: [] as $f) @unlink($f); }
function mailsTo(string $to): array { return array_values(array_filter(mails(), static function ($m) use ($to) { return strtolower((string)$m['to']) === $to; })); }
function clientMails(): array { return array_values(array_filter(mails(), static function ($m) { return strpos((string)($m['kind'] ?? ''), 'client_') === 0; })); }
function cron(string $extra = ''): array {
    $r = get('notify-cron.php' . ($extra !== '' ? '?' . $extra : ''), 'anon', ['X-Notify-Token' => CRON_TK]);
    is($r['code'], 200, 'cron ran: ' . substr($r['body'], 0, 200));
    return $r['json'] ?? [];
}
function gcalls(): array { global $GDIR; $f = $GDIR . '/calls.jsonl'; return is_file($f) ? array_map(static function ($l) { return json_decode($l, true); }, file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : []; }
function gcallsTo(string $path): array { return array_values(array_filter(gcalls(), static function ($c) use ($path) { return strpos($c['path'], $path) !== false; })); }
function gsent(): array { global $GDIR; $o = []; foreach (glob($GDIR . '/sent/*.eml') ?: [] as $f) $o[] = (string)file_get_contents($f); return $o; }
function gfail(?string $mode): void { global $GDIR; $f = $GDIR . '/fail.txt'; if ($mode === null) @unlink($f); else file_put_contents($f, $mode); }
function gaccount(string $email): void { global $GDIR; file_put_contents($GDIR . '/account.txt', $email); }
function gbox(?array $set = null): array {
    global $GDIR;
    $f = $GDIR . '/mailbox.json';
    if ($set !== null) { file_put_contents($f, json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); return $set; }
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d + ['messages' => [], 'labels' => []] : ['messages' => [], 'labels' => []];
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
/** Drop a received message into the fake mailbox. */
function deliver(string $id, string $raw): void { $b = gbox(); $b['messages'][] = ['id' => $id, 'threadId' => 't' . $id, 'raw' => b64u($raw), 'labelIds' => ['INBOX']]; gbox($b); }
/** An RFC 5322 message (plain text, or multipart/mixed with one attachment). */
function rawMail(array $h, string $body, ?string $attachment = null): string {
    $h += ['To' => 'Joust Media <lance+ai@joustmedia.com>', 'Date' => date('r'), 'Message-ID' => '<' . bin2hex(random_bytes(6)) . '@mail.example>', 'MIME-Version' => '1.0'];
    $lines = [];
    foreach ($h as $k => $v) $lines[] = $k . ': ' . $v;
    if ($attachment === null) {
        $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
        $lines[] = 'Content-Transfer-Encoding: quoted-printable';
        return implode("\r\n", $lines) . "\r\n\r\n" . quoted_printable_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))) . "\r\n";
    }
    $b = 'mix_' . bin2hex(random_bytes(4));
    $lines[] = 'Content-Type: multipart/mixed; boundary="' . $b . '"';
    return implode("\r\n", $lines) . "\r\n\r\n--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . str_replace("\n", "\r\n", $body)
         . "\r\n--{$b}\r\nContent-Type: application/pdf; name=\"{$attachment}\"\r\nContent-Disposition: attachment; filename=\"{$attachment}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode('%PDF-1.4 fake')) . "--{$b}--\r\n";
}
/** PHP inside the test app (the whole helpers chain, a session-free CLI script); $cfg overrides config.php keys. */
function appRun(string $code, array $cfg = []): string {
    global $APP;
    $file = sys_get_temp_dir() . '/email_smoke_' . bin2hex(random_bytes(4)) . '.php';
    $base = getenv('PORTAL_TEST_BASE') ?: 'http://127.0.0.1:8099/portal';
    file_put_contents($file, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/portal/notify-cron.php'; \$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "\$_SERVER['HTTP_HOST'] = " . var_export(parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT), true) . ";\n"
        . "chdir(" . var_export($APP, true) . ");\nrequire 'db.php';\n"
        . "foreach (" . var_export($cfg, true) . " as \$__k => \$__v) { if (\$__v === null) unset(\$config[\$__k]); else \$config[\$__k] = \$__v; }\n"
        . "require_once 'helpers.php';\n" . $code . "\n");
    $out = (string)shell_exec('php -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return $out;
}
function appJson(string $code, array $cfg = []) {
    $out = appRun($code, $cfg);
    if (trim($out) === 'null') return null;
    $j = json_decode(trim($out), true);
    if ($j === null) fail('appRun: ' . substr($out, 0, 400));
    return $j;
}
/** The jsm_admin cookie a reply set (the admin's PHP session). */
function sessCookie(array $r): string {
    foreach ($r['cookies'] as $c) if (preg_match('/^jsm_admin=([^;]+)/', $c, $m)) return 'jsm_admin=' . $m[1];
    return '';
}
/** The whole Connect Google round trip against the fake Google; returns the final reply (303 → Manage). */
function connectGoogle(): array {
    $r = post('google-oauth.php', ['action' => 'start'], 'admin');
    is($r['code'], 303, 'start → Google');
    $cookie = sessCookie($r);
    ok($cookie !== '', 'the admin session cookie');
    $auth = $r['location'];
    has($auth, '/o/oauth2/v2/auth?');
    parse_str((string)parse_url($auth, PHP_URL_QUERY), $q);
    is($q['access_type'] ?? '', 'offline');
    is($q['prompt'] ?? '', 'consent');
    is($q['scope'] ?? '', 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.modify');
    ok(strlen((string)($q['state'] ?? '')) >= 30, 'random state');
    is($q['redirect_uri'] ?? '', base() . '/google-oauth.php', 'redirect = the machine URL');
    $g = get($auth, 'anon');
    is($g['code'], 302, 'consent → back to the portal');
    $back = $g['location'];
    has($back, 'state=' . rawurlencode($q['state']));
    return get($back, 'admin', ['Cookie' => $cookie]) + ['callback' => $back, 'cookie' => $cookie];
}
function googleRow(): ?array { $r = rows("SELECT * FROM google_account WHERE id = 1"); return $r[0] ?? null; }
function clientComment(int $postId, string $text, string $slug = 'kenda'): void {
    status(post('status.php', ['id' => $postId, 'comment' => $text, 'client' => $slug], 'client', [], J), 200, 'client comment');
}
function adminComment(int $postId, string $text, string $slug = 'kenda'): void {
    status(post('status.php', ['id' => $postId, 'comment' => $text, 'client' => $slug], 'admin', [], J), 200, 'admin comment');
}
function ageQueue(int $minutes): void { db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL {$minutes} MINUTE WHERE batch_key IS NULL"); }

// =====================================================================================================================
// Migration
// =====================================================================================================================
etest('migrate 45–49: tables + columns; re-run is a no-op', function () {
    foreach (['google_account', 'email_inbound', 'notify_email_refs', 'thread_seen', 'client_email_queue'] as $t) {
        is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]), 1, $t);
    }
    is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_contacts' AND COLUMN_NAME IN ('notify_prefs','unsubscribed_at')"), 2);
    is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notify_clients' AND COLUMN_NAME IN ('email_review','email_replies','email_live')"), 3);
    is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notify_threads' AND COLUMN_NAME = 'email_token'"), 1);
    $r = get('migrate.php', 'admin');
    is($r['code'], 200);
    has($r['body'], 'Migration complete');
    hasNot($r['body'], '<li class="ok">✓ Created `google_account`', 'nothing created twice');
});

// =====================================================================================================================
// Connect Google
// =====================================================================================================================
etest('OAuth: admin only — a client or nobody cannot start or finish it', function () {
    is(post('google-oauth.php', ['action' => 'start'], 'client:kenda')['code'], 403, 'client start refused');
    is(post('google-oauth.php', ['action' => 'start'], 'anon')['code'], 403, 'anon start refused');
    $r = get('google-oauth.php?code=stubcode-x&state=abc', 'anon');
    is($r['code'], 302);
    has($r['location'], 'login.php', 'nobody → sign in first');
    is(googleRow(), null);
    is(count(gcallsTo('/token')), 0, 'no code was exchanged');
});

etest('OAuth: state (CSRF) — a forged, missing or replayed state is refused and nothing is stored', function () {
    // a callback with a state this session never started
    $start = post('google-oauth.php', ['action' => 'start'], 'admin');
    $cookie = sessCookie($start);
    $r = get('google-oauth.php?code=stubcode-forged&state=' . str_repeat('A', 32), 'admin', ['Cookie' => $cookie]);
    is($r['code'], 303);
    has(urldecode($r['location']), 'did not start here');
    is(googleRow(), null, 'nothing stored');
    is(count(gcallsTo('/token')), 0, 'the code was never exchanged');
    // the state is single use: the forged attempt consumed it, so the real one now fails too
    parse_str((string)parse_url($start['location'], PHP_URL_QUERY), $q);
    $r = get('google-oauth.php?code=stubcode-real&state=' . rawurlencode($q['state']), 'admin', ['Cookie' => $cookie]);
    has(urldecode($r['location']), 'did not start here', 'consumed');
    // another admin session cannot use this session's state
    $start2 = post('google-oauth.php', ['action' => 'start'], 'admin');
    parse_str((string)parse_url($start2['location'], PHP_URL_QUERY), $q2);
    $r = get('google-oauth.php?code=stubcode-x&state=' . rawurlencode($q2['state']), 'admin');   // no session cookie = a new session
    has(urldecode($r['location']), 'did not start here', 'bound to the session that started it');
    // a full round trip works, and replaying its callback afterwards does not
    $ok = connectGoogle();
    is($ok['code'], 303);
    has(urldecode($ok['location']), 'Connected as lance@joustmedia.com');
    $again = get($ok['callback'], 'admin', ['Cookie' => $ok['cookie']]);
    has(urldecode($again['location']), 'did not start here', 'replay refused');
    // Google said no
    $r = get('google-oauth.php?error=access_denied&state=x', 'admin');
    has(urldecode($r['location']), 'cancelled');
});

etest('OAuth: only the sender account (lance@joustmedia.com) may connect; both scopes are required', function () {
    gaccount('someone.else@gmail.com');
    $r = connectGoogle();
    has(urldecode($r['location']), 'That was someone.else@gmail.com');
    is(googleRow(), null, 'not stored');
    ok(count(gcallsTo('/revoke')) >= 1, 'the stray token was revoked');
    gaccount('lance@joustmedia.com');
    gfail('no_modify');
    $r = connectGoogle();
    has(urldecode($r['location']), 'Both permissions are needed');
    is(googleRow(), null);
    gfail(null);
    $r = connectGoogle();
    has(urldecode($r['location']), 'Connected as lance@joustmedia.com');
    $row = googleRow();
    is($row['account_email'], 'lance@joustmedia.com');
    is($row['connected_by'], 'lance@joustmedia.com');
    has((string)$row['scopes'], 'gmail.modify');
});

etest('tokens are encrypted at rest — the raw refresh / access tokens appear nowhere in the database', function () {
    global $GDIR;
    connectGoogle();
    $row = googleRow();
    ok(preg_match('/^(s1|g1):/', (string)$row['refresh_token_enc']) === 1, 'refresh token sealed');
    ok(preg_match('/^(s1|g1):/', (string)$row['access_token_enc']) === 1, 'access token sealed');
    $issued = array_filter(array_map('trim', file($GDIR . '/refresh.txt')));
    ok(count($issued) >= 1, 'the stub issued a refresh token');
    $dump = json_encode(rows("SELECT * FROM google_account")) . json_encode(rows("SELECT * FROM notify_outbox")) . json_encode(rows("SELECT * FROM meta"));
    foreach ($issued as $t) { hasNot($dump, $t, 'raw refresh token'); hasNot($dump, substr($t, 4, 20), 'not even part of it'); }
    hasNot($dump, 'ya29.stub-', 'no raw access token');
    // the crypto itself: both methods round-trip; tampering, another key or garbage → null
    $j = appJson('
        $o = [];
        foreach (["sodium", "openssl"] as $m) {
            $c = googleEncrypt("1//secret-refresh", $m);
            $o[$m] = ["prefix" => substr($c, 0, 3), "back" => googleDecrypt($c), "tamper" => googleDecrypt(substr($c, 0, -2) . (substr($c, -2) === "AA" ? "BB" : "AA"))];
        }
        $o["garbage"] = googleDecrypt("x1:abc");
        $o["fresh_nonce"] = googleEncrypt("same") !== googleEncrypt("same");
        echo json_encode($o);');
    is($j['sodium']['prefix'], 's1:'); is($j['sodium']['back'], '1//secret-refresh'); is($j['sodium']['tamper'], null);
    is($j['openssl']['prefix'], 'g1:'); is($j['openssl']['back'], '1//secret-refresh'); is($j['openssl']['tamper'], null);
    is($j['garbage'], null); is($j['fresh_nonce'], true);
    $other = appJson('echo json_encode(googleDecrypt(' . var_export((string)$row['refresh_token_enc'], true) . '));', ['google_token_key' => 'a-different-key-0123456789abcdef']);
    is($other, null, 'another google_token_key cannot read it');
});

etest('Manage → Notifications: Google status, Disconnect revokes and forgets', function () {
    $r = get('manage.php?section=notifications', 'admin');
    has($r['body'], 'data-notify-google="not-connected"');
    has($r['body'], 'Connect Google');
    has($r['body'], base() . '/google-oauth.php', 'the redirect URI to register');
    has($r['body'], 'data-unmatched-state="not-connected"');
    foreach (['google_client_id', 'google_client_secret', 'google_token_key'] as $k) hasNot($r['body'], 'test-google-secret', 'never the secret');
    connectGoogle();
    $r = get('manage.php?section=notifications', 'admin');
    has($r['body'], 'data-notify-google="connected"');
    has($r['body'], 'Connected as lance@joustmedia.com');
    has($r['body'], 'data-google-last-success');
    hasNot($r['body'], 'ya29.stub');
    $n = count(gcallsTo('/revoke'));
    $d = post('notify-admin.php', ['action' => 'google_disconnect'], 'admin', [], J);
    is($d['code'], 200, $d['body']);
    is(googleRow(), null, 'forgotten');
    is(count(gcallsTo('/revoke')), $n + 1, 'revoked at Google');
    is(post('notify-admin.php', ['action' => 'google_disconnect'], 'client:kenda', [], J)['code'], 403, 'client refused');
});

// =====================================================================================================================
// Transport + MIME
// =====================================================================================================================
etest('transport: automatic = mail() until Google is connected, then gmail; an explicit mail_transport wins', function () {
    $auto = ['mail_transport' => '', 'mail_sink_dir' => null];
    is(trim(appRun('echo notifyMailTransport();', $auto)), 'mail', 'not connected → mail()');
    connectGoogle();
    is(trim(appRun('echo notifyMailTransport();', $auto)), 'gmail', 'connected → gmail');
    is(trim(appRun('echo notifyMailTransport();', ['mail_transport' => 'mail', 'mail_sink_dir' => null])), 'mail', 'override');
    is(trim(appRun('echo notifyMailTransport();')), 'sink', 'the harness keeps its sink');
});

etest('gmail transport: the message parses back (headers, UTF-8 subject, text + HTML, List-Unsubscribe); threading on the item', function () {
    connectGoogle();
    $cfg = ['mail_transport' => 'gmail'];
    $long = str_repeat('Wide line ', 40);
    $j = appJson('
        $th = ["entity_type" => "post", "entity_id" => 1, "company_id" => 1];
        $a = notifyEmail(["to" => "jane@kenda.example", "subject" => "Café ✓ — ready for your review", "text" => "Hi Jane,\nLine two ünïcode.\n",
              "html" => "<p>Hi <b>Jane</b> ✓</p><p>' . $long . '</p>", "reply_to" => inboundAddress(), "thread" => $th,
              "headers" => ["List-Unsubscribe" => "<https://example.test/u?t=1>", "List-Unsubscribe-Post" => "List-Unsubscribe=One-Click"]]);
        $b = notifyEmail(["to" => "jane@kenda.example", "subject" => "Second", "text" => "x", "thread" => $th]);
        echo json_encode([$a, $b]);', $cfg);
    is($j[0]['ok'], true, 'sent: ' . ($j[0]['error'] ?? ''));
    is($j[0]['transport'], 'gmail');
    is($j[1]['ok'], true);
    $sent = gsent();
    is(count($sent), 2, 'two messages reached messages/send');
    $send = gcallsTo('/messages/send');
    has((string)$send[0]['auth'], 'Bearer ya29.stub-', 'OAuth bearer');
    foreach (preg_split("/\r\n/", $sent[0]) as $line) ok(strlen($line) <= 998, 'RFC 5322 line length');
    has($sent[0], "\r\n", 'CRLF');
    $p = appJson('$m = notifyMimeParse(' . var_export($sent[0], true) . '); $m2 = notifyMimeParse(' . var_export($sent[1], true) . ');
        echo json_encode(["h" => $m["headers"], "text" => $m["text"], "html" => $m["html"], "parts" => count($m["parts"]), "h2" => $m2["headers"]]);');
    $h = $p['h'];
    is($h['from'][0], '"Joust Media" <lance@joustmedia.com>');
    is($h['to'][0], 'jane@kenda.example');
    is($h['reply-to'][0], 'lance+ai@joustmedia.com');
    is($h['subject'][0], 'Café ✓ — ready for your review', 'UTF-8 subject round-trips');
    is($h['mime-version'][0], '1.0');
    has($h['content-type'][0], 'multipart/alternative');
    is($h['message-id'][0], $j[0]['message_id']);
    is($h['list-unsubscribe'][0], '<https://example.test/u?t=1>');
    is($h['list-unsubscribe-post'][0], 'List-Unsubscribe=One-Click');
    ok(!isset($h['in-reply-to']), 'the first message about an item starts the thread');
    is($p['parts'], 2, 'text + html');
    is($p['text'], "Hi Jane,\nLine two ünïcode.\n");
    has($p['html'], '<p>Hi <b>Jane</b> ✓</p>');
    has($p['html'], $long, 'a long line survives quoted-printable');
    is($p['h2']['in-reply-to'][0], $j[0]['message_id'], 'the next one replies to it');
    is($p['h2']['references'][0], $j[0]['message_id']);
    is(q1("SELECT email_message_id FROM notify_threads WHERE entity_type = 'post' AND entity_id = 1"), $j[0]['message_id']);
    ok(googleRow()['last_success_at'] !== null, 'health: last success');
});

etest('gmail transport: an expired access token is refreshed; a revoked grant is reported (reconnect)', function () {
    connectGoogle();
    db()->exec("UPDATE google_account SET access_expires_at = NOW() - INTERVAL 1 MINUTE");
    $before = count(gcallsTo('/token'));
    $j = appJson('echo json_encode(notifyEmail(["to" => "lance@joustmedia.com", "subject" => "x", "text" => "y"]));', ['mail_transport' => 'gmail']);
    is($j['ok'], true);
    is(count(gcallsTo('/token')), $before + 1, 'one refresh');
    is(gcallsTo('/token')[$before]['body']['grant_type'], 'refresh_token');
    ok(strtotime((string)googleRow()['access_expires_at']) > time() + 3000, 'cached until the new expiry');
    $j = appJson('echo json_encode(notifyEmail(["to" => "lance@joustmedia.com", "subject" => "x", "text" => "y"]));', ['mail_transport' => 'gmail']);
    is(count(gcallsTo('/token')), $before + 1, 'the cached token is reused');
    db()->exec("UPDATE google_account SET access_expires_at = NOW() - INTERVAL 1 MINUTE");
    gfail('invalid_grant');
    $j = appJson('echo json_encode(notifyEmail(["to" => "lance@joustmedia.com", "subject" => "x", "text" => "y"]));', ['mail_transport' => 'gmail']);
    is($j['ok'], false);
    has($j['error'], 'revoked or expired');
    has((string)googleRow()['last_error'], 'Connect Google again');
    gfail(null);
    // not connected + explicit gmail → a clear error, no crash
    db()->exec("DELETE FROM google_account");
    $j = appJson('echo json_encode(notifyEmail(["to" => "lance@joustmedia.com", "subject" => "x", "text" => "y"]));', ['mail_transport' => 'gmail']);
    is($j['ok'], false);
    has($j['error'], 'not connected');
});

// =====================================================================================================================
// Client emails
// =====================================================================================================================
etest('Ready for your review: waits 15 min after the LAST change, then one email per contact listing every item', function () {
    clearMail();
    status(post('status.php', ['action' => 'submit', 'id' => 6, 'client' => 'kenda'], 'admin', [], J), 200, 'post 6 sent for review');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'review' AND company_id = 1"), 1, 'queued');
    cron();
    is(count(clientMails()), 0, 'nothing right away');
    ageQueue(14);
    cron();
    is(count(clientMails()), 0, 'still inside the 15-minute window');
    // a second change restarts the window
    status(post('status.php', ['id' => 4, 'status' => 'pending', 'client' => 'kenda'], 'admin', [], J), 200, 'post 4 resubmitted');
    db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL 20 MINUTE WHERE entity_id = 6");
    cron();
    is(count(clientMails()), 0, 'the newest change is fresh → wait');
    ageQueue(16);
    $c = cron();
    is((int)$c['client_emails']['review'], 1, 'one batch');
    $m = clientMails();
    is(count($m), 2, 'one email per Kenda contact');
    $to = array_map(static function ($x) { return $x['to']; }, $m);
    sort($to);
    is($to, ['jane@kenda.example', 'ops@kenda.example']);
    foreach ($m as $x) {
        is($x['subject'], '2 items ready for your review — Kenda Tires');
        has($x['html'], 'Behind the scenes');
        has($x['html'], 'Winter promo');
        is($x['from'], '"Joust Media" <lance@joustmedia.com>');
        is($x['reply_to'], 'lance+ai@joustmedia.com');
        has($x['html'], 'static/brand/joust-180.png', 'the Joust logo');
        has($x['html'], 'data-email-client>Kenda Tires', 'the client name');
        has($x['html'], 'notify-thumb', 'signed JPEG thumbnails');
        has($x['text'], 'Behind the scenes', 'plain-text part');
        ok(!isset($x['in_reply_to']), 'a multi-item email is not threaded on one item');
    }
    // dedupe: the cron again sends nothing new
    cron();
    is(count(clientMails()), 2, 'no duplicates');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'client_email'"), 2);
});

etest('per-recipient links: each contact gets clientLink() signed for THEM (and it signs that contact in)', function () {
    clearMail();
    status(post('status.php', ['action' => 'submit', 'id' => 6, 'client' => 'kenda'], 'admin', [], J), 200);
    ageQueue(16);
    cron();
    $m = clientMails();
    is(count($m), 2);
    foreach ($m as $x) {
        ok(preg_match('#href="([^"]+(?:\?|&amp;)k=([0-9]+)\.[0-9]+\.[A-Za-z0-9_-]{32})"[^>]*data-email-link#', $x['html'], $mm) === 1, 'a signed link');
        $cid = (int)q1("SELECT id FROM client_contacts WHERE email = ?", [$x['to']]);
        is((int)$mm[2], $cid, 'signed for ' . $x['to']);
        has($mm[1], 'post=6');
        $r = get(html_entity_decode($mm[1]), 'anon');
        is($r['code'], 302, 'the link signs in');
        $sid = rows("SELECT contact_id FROM client_sessions WHERE via = 'link' ORDER BY id DESC LIMIT 1");
        is((int)$sid[0]['contact_id'], $cid, 'as that contact');
        // the preferences link is signed for them too
        ok(preg_match('#email-prefs\.php\?t=(\d+)\.#', $x['html'], $pm) === 1);
        is((int)$pm[1], $cid);
    }
});

etest('Joust replied: 10-minute batch, threaded on the item, [J#token] subject, never an internal note', function () {
    clearMail();
    adminComment(1, 'We darkened the render — have another look?');
    appRun('activityWithContext(["internal" => 1], static function () use ($pdo) { logActivity($pdo, 1, "post", 1, "commented", "admin", "note", "SECRET internal: client is slow to pay"); });');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'reply'"), 1, 'only the visible reply is queued');
    ageQueue(9);
    cron();
    is(count(clientMails()), 0, 'inside 10 minutes');
    ageQueue(11);
    cron();
    $m = mailsTo('jane@kenda.example');
    is(count($m), 1);
    $x = $m[0];
    $tok = (string)q1("SELECT email_token FROM notify_threads WHERE entity_type = 'post' AND entity_id = 1");
    ok(preg_match('/^[a-f0-9]{6}$/', $tok) === 1, 'item token stored');
    is($x['subject'], 'Joust replied: Spring launch hero [J#' . $tok . ']');
    has($x['html'], 'We darkened the render');
    has($x['html'], 'Lance at Joust', 'named author');
    foreach (mails() as $any) { hasNot(json_encode($any), 'SECRET internal', 'no internal note anywhere'); }
    has($x['html'], 'mailto:lance+ai@joustmedia.com?subject=' . rawurlencode('Re: Spring launch hero [J#' . $tok . ']'), 'Reply by email');
    $mid = (string)$x['message_id'];
    is(q1("SELECT entity_id FROM notify_email_refs WHERE message_id = ?", [$mid]), 1, 'the Message-ID is remembered for replies');
    // a second reply later threads on the first
    clearMail();
    adminComment(1, 'Also fixed the crop.');
    ageQueue(11);
    cron();
    $y = mailsTo('jane@kenda.example')[0];
    is($y['in_reply_to'] ?? '', $mid, 'In-Reply-To the earlier email about the item');
    is($y['references'] ?? '', $mid);
    hasNot($y['html'], 'We darkened', 'only the new reply');
});

etest('Live & scheduled: daily; switches and preferences decide who gets what', function () {
    clearMail();
    status(post('status.php', ['action' => 'toggle_posted', 'id' => 3, 'to' => 1, 'client' => 'kenda'], 'admin', [], J), 200, 'post 3 scheduled');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'live'"), 1);
    cron();
    is(count(clientMails()), 0, 'not before the daily run');
    cron('live=now');
    $m = clientMails();
    is(count($m), 2);
    is($m[0]['subject'], 'Scheduled: Trail day');
    hasNot($m[0]['html'], 'mailto:', 'nothing to reply to');
    // the client switch off → nobody; one contact's preference off → only the other
    clearMail();
    status(post('client-admin.php', ['action' => 'email_toggle', 'id' => 1, 'kind' => 'review', 'to' => 0], 'admin', [], J), 200);
    is((int)q1("SELECT email_review FROM notify_clients WHERE company_id = 1"), 0);
    status(post('status.php', ['action' => 'submit', 'id' => 6, 'client' => 'kenda'], 'admin', [], J), 200);
    ageQueue(16);
    cron();
    is(count(clientMails()), 0, 'review emails off for Kenda');
    status(post('client-admin.php', ['action' => 'email_toggle', 'id' => 1, 'kind' => 'review', 'to' => 1], 'admin', [], J), 200);
    db()->exec("UPDATE client_contacts SET notify_prefs = '{\"review\":0,\"reply\":1,\"live\":1}' WHERE id = 2");
    status(post('status.php', ['id' => 4, 'status' => 'pending', 'client' => 'kenda'], 'admin', [], J), 200);
    ageQueue(16);
    cron();
    $m = clientMails();
    is(count($m), 1);
    is($m[0]['to'], 'jane@kenda.example', 'ops@ opted out of review emails');
    is(post('client-admin.php', ['action' => 'email_toggle', 'id' => 1, 'kind' => 'review', 'to' => 0], 'client:kenda', [], J)['code'], 403, 'client cannot flip switches');
});

etest('never another client’s data: a foreign item in a batch is dropped, and only that client’s contacts are emailed', function () {
    clearMail();
    status(post('status.php', ['action' => 'submit', 'id' => 6, 'client' => 'kenda'], 'admin', [], J), 200);
    // tamper: a Privacy Bee post and a draft slipped into Kenda's batch
    db()->exec("INSERT INTO client_email_queue (company_id, kind, entity_type, entity_id) VALUES (1, 'review', 'post', 8), (1, 'review', 'post', 7)");
    db()->exec("INSERT INTO client_email_queue (company_id, kind, entity_type, entity_id, activity_id) VALUES (1, 'reply', 'post', 8, 1)");
    ageQueue(16);
    cron();
    $m = clientMails();
    ok(count($m) >= 2);
    foreach ($m as $x) {
        ok(in_array($x['to'], ['jane@kenda.example', 'ops@kenda.example'], true), 'Kenda contacts only: ' . $x['to']);
        hasNot($x['html'], 'Privacy week', 'no Privacy Bee item');
        hasNot($x['html'], 'Privacy Bee');
        hasNot($x['text'], 'Privacy week');
    }
    is(count(mailsTo('pat@privacybee.example')), 0);
});

etest('unsubscribe: RFC 8058 one-click POST stops all; the preferences page saves per kind; bad links are refused', function () {
    clearMail();
    status(post('status.php', ['action' => 'submit', 'id' => 6, 'client' => 'kenda'], 'admin', [], J), 200);
    ageQueue(16);
    cron();
    $x = mailsTo('jane@kenda.example')[0];
    $lu = '';
    foreach ($x['header_lines'] as $l) if (stripos($l, 'List-Unsubscribe: ') === 0) $lu = trim(substr($l, 18), '<> ');
    ok($lu !== '', 'List-Unsubscribe header');
    ok(in_array('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $x['header_lines'], true), 'List-Unsubscribe-Post');
    has($lu, 'email-prefs.php?t=1.');
    // the page (GET) never unsubscribes by itself — mail scanners open links
    $g = get($lu, 'anon');
    is($g['code'], 200);
    has($g['body'], 'data-ep-unsub-confirm');
    is(q1("SELECT unsubscribed_at FROM client_contacts WHERE id = 1"), null, 'GET changes nothing');
    // one-click POST (no cookies, no Sec-Fetch headers — what Gmail does)
    $p = post($lu, ['List-Unsubscribe' => 'One-Click'], 'anon');
    is($p['code'], 200);
    is(trim($p['body']), 'Unsubscribed');
    ok(q1("SELECT unsubscribed_at FROM client_contacts WHERE id = 1") !== null, 'stopped');
    clearMail();
    status(post('status.php', ['id' => 4, 'status' => 'pending', 'client' => 'kenda'], 'admin', [], J), 200);
    ageQueue(16);
    cron();
    is(count(mailsTo('jane@kenda.example')), 0, 'no more emails');
    is(count(mailsTo('ops@kenda.example')), 1, 'the other contact still gets them');
    // preferences page: resubscribe, then turn one kind off
    $tok = preg_replace('/^.*t=([^&]+).*$/', '$1', $lu);
    $r = post('email-prefs.php', ['t' => $tok, 'action' => 'resubscribe'], 'anon');
    is($r['code'], 303);
    is(q1("SELECT unsubscribed_at FROM client_contacts WHERE id = 1"), null);
    $r = post('email-prefs.php', ['t' => $tok, 'action' => 'save', 'review' => '1', 'live' => '1'], 'anon');
    is($r['code'], 303);
    is(json_decode((string)q1("SELECT notify_prefs FROM client_contacts WHERE id = 1"), true), ['review' => 1, 'reply' => 0, 'live' => 1]);
    $page = get('email-prefs.php?t=' . $tok . '&done=saved', 'anon');
    has($page['body'], 'jane@kenda.example');
    has($page['body'], 'data-ep-pref="reply"');
    ok(preg_match('#id="ep-reply"[^>]*>#', $page['body'], $mm) === 1 && strpos($mm[0], 'checked') === false, 'reply switch off');
    // forged / foreign tokens
    $bad = get('email-prefs.php?t=1.' . (time() + 999) . '.' . str_repeat('x', 32), 'anon');
    is($bad['code'], 404);
    has($bad['body'], 'data-ep-invalid');
    is(post('email-prefs.php?t=1.' . (time() + 999) . '.' . str_repeat('x', 32), ['List-Unsubscribe' => 'One-Click'], 'anon')['code'], 404);
    // a cross-site form post is refused
    $x = post('email-prefs.php', ['t' => $tok, 'action' => 'unsubscribe'], 'anon', [], ['Sec-Fetch-Site' => 'cross-site']);
    is($x['code'], 403);
    is(q1("SELECT unsubscribed_at FROM client_contacts WHERE id = 1"), null);
});

etest('preferences from the client portal: the signed-in contact, their own row only; the tab bar links it', function () {
    $r = get('email-prefs.php', 'client:kenda');
    is($r['code'], 200);
    has($r['body'], 'jane@kenda.example');
    has($r['body'], 'Back to the portal');
    status(post('email-prefs.php', ['action' => 'save', 'review' => '1'], 'client:kenda'), 303);
    is(json_decode((string)q1("SELECT notify_prefs FROM client_contacts WHERE id = 1"), true), ['review' => 1, 'reply' => 0, 'live' => 0]);
    is(q1("SELECT notify_prefs FROM client_contacts WHERE id = 3"), null, 'Privacy Bee untouched');
    $a = get('email-prefs.php', 'anon');
    is($a['code'], 302);
    has($a['location'], 'sign-in');
    $home = get('index.php?client=kenda', 'client');
    has($home['body'], 'data-email-settings');
});

// =====================================================================================================================
// Inbound replies
// =====================================================================================================================
/** Connect + one sent "Joust replied" email about post 1 to Jane → [message id, token]. */
function setupReplyThread(): array {
    connectGoogle();
    clearMail();
    adminComment(1, 'We darkened the render — have another look?');
    ageQueue(11);
    cron();
    $x = mailsTo('jane@kenda.example')[0];
    return [(string)$x['message_id'], (string)q1("SELECT email_token FROM notify_threads WHERE entity_type = 'post' AND entity_id = 1")];
}
function commentsOn(int $postId, string $actor = 'client'): array {
    return rows("SELECT * FROM activity_log WHERE entity_type = 'post' AND entity_id = ? AND action = 'commented' AND actor = ? ORDER BY id", [$postId, $actor]);
}

etest('inbound: a Gmail reply (In-Reply-To) becomes Jane’s comment on the item; quoted history cut; labelled; Slack pinged', function () {
    global $ROOT;
    [$mid] = setupReplyThread();
    @unlink($ROOT . '/slack-calls.jsonl');
    $before = count(commentsOn(1));
    deliver('m1', rawMail(['From' => 'Jane Kenda <Jane@Kenda.example>', 'Subject' => 'Re: Joust replied: Spring launch hero', 'In-Reply-To' => $mid, 'References' => $mid],
        "Looks great — ship it!\n\nOn Sat, Oct 3, 2026 at 9:14 AM Joust Media <lance+ai@joustmedia.com>\nwrote:\n> We darkened the render — have another look?\n> \n"));
    $c = cron();
    is($c['inbound']['status'], 'ok');
    is((int)$c['inbound']['posted'], 1);
    $rows = commentsOn(1);
    is(count($rows), $before + 1);
    $last = end($rows);
    is($last['detail'], 'Looks great — ship it!', 'Gmail quote + wrapped "wrote:" line removed');
    is((int)$last['client_contact_id'], 1, 'authored by Jane (contact 1)');
    is((int)$last['internal'], 0);
    $in = rows("SELECT * FROM email_inbound WHERE gmail_id = 'm1'")[0];
    is($in['status'], 'posted');
    has((string)$in['reason'], 'in-reply-to');
    is((int)$in['activity_id'], (int)$last['id']);
    // labelled portal-processed (created on first use)
    $box = gbox();
    $label = array_values(array_filter($box['labels'], static function ($l) { return $l['name'] === 'portal-processed'; }));
    is(count($label), 1, 'label created');
    ok(in_array($label[0]['id'], $box['messages'][0]['labelIds'], true), 'message labelled');
    $q = gcallsTo('/gmail/v1/users/me/messages');
    has((string)($q[0]['query']['q'] ?? ''), 'to:lance+ai@joustmedia.com -label:portal-processed newer_than:14d');
    // the comment went through the normal pipeline: Slack thread message
    $slack = is_file($ROOT . '/slack-calls.jsonl') ? (string)file_get_contents($ROOT . '/slack-calls.jsonl') : '';
    has($slack, 'Looks great', 'Slack heard about it');
    has($slack, 'Jane Kenda (Kenda Tires)');
});

etest('inbound: dedupe by Gmail id — a second run (even with the label gone) never posts twice', function () {
    [$mid] = setupReplyThread();
    deliver('m1', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Re: x', 'In-Reply-To' => $mid], "Once only please"));
    cron();
    $n = count(commentsOn(1));
    $b = gbox(); $b['messages'][0]['labelIds'] = ['INBOX']; gbox($b);   // someone removed the label in Gmail
    $c = cron();
    is((int)$c['inbound']['posted'], 0);
    is(count(commentsOn(1)), $n, 'not posted again');
    is((int)q1("SELECT COUNT(*) FROM email_inbound WHERE gmail_id = 'm1'"), 1);
    ok(count(gbox()['messages'][0]['labelIds']) === 2, 'label put back');
});

etest('inbound: the [J#token] subject fallback (Outlook "-----Original Message-----"), Apple "Sent from my iPhone", attachments', function () {
    [, $tok] = setupReplyThread();
    deliver('o1', rawMail(['From' => 'ops@kenda.example', 'Subject' => 'RE: Ready for your review: Spring launch hero [J#' . $tok . ']'],
        "Please make it brighter.\r\n\r\n-----Original Message-----\r\nFrom: Joust Media <lance+ai@joustmedia.com>\r\nSent: Saturday, October 3, 2026 9:14 AM\r\nTo: ops@kenda.example\r\nSubject: Ready for your review\r\n\r\nHi,\r\n"));
    deliver('a1', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Re: Spring launch hero [J#' . strtoupper($tok) . ']'],
        "Love it\n\nSent from my iPhone\n\n> On Oct 3, 2026, at 9:14 AM, Joust Media <lance+ai@joustmedia.com> wrote:\n>\n> We darkened the render\n"));
    deliver('x1', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Here is our logo file.", 'logo.pdf'));
    $c = cron();
    is((int)$c['inbound']['posted'], 3);
    $d = array_column(commentsOn(1), 'detail');
    ok(in_array('Please make it brighter.', $d, true), 'Outlook history cut');
    ok(in_array('Love it', $d, true), 'Apple signature + quote cut');
    ok(in_array("Here is our logo file.\n\n(attachment not imported)", $d, true), 'attachment noted, not imported');
    is((int)q1("SELECT client_contact_id FROM activity_log WHERE detail = 'Please make it brighter.'"), 2, 'by ops@ (contact 2)');
    is(q1("SELECT reason FROM email_inbound WHERE gmail_id = 'o1'"), 'matched by subject token');
    is((int)q1("SELECT has_attachments FROM email_inbound WHERE gmail_id = 'x1'"), 1);
});

etest('inbound: quote stripping unit cases (Gmail, Outlook, Apple, ">" lines, signatures, HTML-only)', function () {
    $j = appJson('echo json_encode([
        notifyStripQuoted("Yes, approved.\n\nOn Fri, Oct 2, 2026 at 4:01 PM Joust Media <lance+ai@joustmedia.com> wrote:\n> old\n> older"),
        notifyStripQuoted("Swap slide 2.\r\n\r\n________________________________\r\nFrom: Joust Media <lance+ai@joustmedia.com>\r\nSent: Friday, October 2, 2026 4:01 PM\r\nSubject: x\r\n\r\nold"),
        notifyStripQuoted("Swap slide 3.\n\nFrom: Joust Media <lance+ai@joustmedia.com>\nDate: Friday, October 2, 2026\nTo: Jane\n\nold"),
        notifyStripQuoted("Sounds good\n\n> On Oct 2, 2026, at 4:01 PM, Joust Media <lance+ai@joustmedia.com> wrote:\n> old"),
        notifyStripQuoted("Line one\n> quoted inline\nLine two\n\n-- \nJane Kenda\nMarketing"),
        notifyStripQuoted("On it!\nOn Fri, Oct 2, 2026 at 4:01 PM Joust Media <\nlance+ai@joustmedia.com> wrote:\n> x"),
        notifyHtmlToText("<div dir=\"ltr\">New <b>text</b><br>here</div><div class=\"gmail_quote\"><div>On Fri … wrote:</div><blockquote>old</blockquote></div>"),
        notifyHtmlToText("<p>Outlook reply</p><div id=\"divRplyFwdMsg\"><b>From:</b> Joust</div><div>old</div>"),
    ]);');
    is($j[0], 'Yes, approved.');
    is($j[1], 'Swap slide 2.');
    is($j[2], 'Swap slide 3.');
    is($j[3], 'Sounds good');
    is($j[4], "Line one\nLine two");
    is($j[5], 'On it!');
    is($j[6], "New text\nhere");
    is($j[7], 'Outlook reply');
});

etest('inbound: sender must be a contact of THAT item’s client; unknown / unmatched mail goes to the list, never posted', function () {
    [$mid, $tok] = setupReplyThread();
    $n = count(commentsOn(1));
    deliver('s1', rawMail(['From' => 'pat@privacybee.example', 'Subject' => 'Re: x [J#' . $tok . ']'], "I am from another client"));
    deliver('s2', rawMail(['From' => 'stranger@evil.example', 'Subject' => 'Re: x', 'In-Reply-To' => $mid], "Let me in"));
    deliver('s3', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Hello Lance'], "A brand new email, no thread"));
    deliver('s4', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Re: x [J#ffffff]'], "Forged token"));
    deliver('s5', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Out of office', 'Auto-Submitted' => 'auto-replied', 'In-Reply-To' => $mid], "I am away"));
    $c = cron();
    is((int)$c['inbound']['posted'], 0);
    is((int)$c['inbound']['unmatched'], 4);
    is((int)$c['inbound']['ignored'], 1);
    is(count(commentsOn(1)), $n, 'nothing posted');
    has((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = 's1'"), 'pat@privacybee.example is not a contact of Kenda Tires');
    has((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = 's2'"), 'stranger@evil.example is not a contact of Kenda Tires');
    has((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = 's3'"), 'not a reply to a portal email');
    has((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = 's5'"), 'automatic');
    // the list in Manage → Notifications, Assign / Dismiss
    $r = get('manage.php?section=notifications', 'admin');
    has($r['body'], 'data-notify-unmatched="4"');
    has($r['body'], 'stranger@evil.example');
    has($r['body'], 'A brand new email, no thread');
    has($r['body'], 'value="post:2"', 'items to assign to');
    $s3 = (int)q1("SELECT id FROM email_inbound WHERE gmail_id = 's3'");
    $a = post('notify-admin.php', ['action' => 'inbound_assign', 'id' => $s3, 'entity' => 'post:2'], 'admin', [], J);
    is($a['code'], 200, $a['body']);
    $c2 = commentsOn(2);
    is(end($c2)['detail'], 'A brand new email, no thread');
    is((int)end($c2)['client_contact_id'], 1, 'Jane is a Kenda contact → hers');
    is(q1("SELECT status FROM email_inbound WHERE id = ?", [$s3]), 'assigned');
    is(post('notify-admin.php', ['action' => 'inbound_assign', 'id' => $s3, 'entity' => 'post:2'], 'admin', [], J)['code'], 409, 'only once');
    $s2 = (int)q1("SELECT id FROM email_inbound WHERE gmail_id = 's2'");
    is(post('notify-admin.php', ['action' => 'inbound_dismiss', 'id' => $s2], 'admin', [], J)['code'], 200);
    is(q1("SELECT status FROM email_inbound WHERE id = ?", [$s2]), 'dismissed');
    is(post('notify-admin.php', ['action' => 'inbound_dismiss', 'id' => $s2], 'client:kenda', [], J)['code'], 403, 'client refused');
    $r = get('manage.php?section=notifications', 'admin');
    has($r['body'], 'data-notify-unmatched="2"');
    hasNot($r['body'], 'Let me in');
});

etest('inbound: Lance answering by email posts as Lance (admin), which queues the client’s “Joust replied”', function () {
    [, $tok] = setupReplyThread();
    deliver('l1', rawMail(['From' => 'Lance <lance@joustmedia.com>', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Will do, Jane.\n\nOn Sat … wrote:\n> x"));
    db()->exec("UPDATE client_email_queue SET batch_key = 'old' WHERE batch_key IS NULL");
    cron();
    $a = commentsOn(1, 'admin');
    is(end($a)['detail'], 'Will do, Jane.');
    is((int)end($a)['author_user_id'], 1, 'Lance');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'reply' AND batch_key IS NULL"), 1, 'the client hears about it');
});

etest('inbound: not connected → the cron says so and touches nothing', function () {
    $c = cron();
    is($c['inbound']['status'], 'not_connected');
    is(count(gcallsTo('/gmail/')), 0);
});

// =====================================================================================================================
// Inbox, resolve, unread
// =====================================================================================================================
/** The Inbox rows in page order: [[key, since], …]. */
function inboxRows(string $q = ''): array {
    $r = get('inbox.php' . ($q !== '' ? '?' . $q : ''), 'admin');
    is($r['code'], 200);
    preg_match_all('#data-inbox-row="([a-z_]+:\d+)" data-company="[a-z]+"(?: data-since="([^"]+)")?#', $r['body'], $m, PREG_SET_ORDER);
    return array_map(static function ($x) { return [$x[1], $x[2] ?? '']; }, $m);
}

etest('Inbox: Waiting on Joust = the client spoke last OR Needs changes; oldest first; answers and Resolve clear it', function () {
    $rows = inboxRows();
    $keys = array_column($rows, 0);
    ok(in_array('post:4', $keys, true), 'denied post with the client note');
    ok(in_array('email:4', $keys, true), 'denied email (Needs changes, no note)');
    ok(!in_array('post:1', $keys, true), 'post 1 is not waiting');
    // a client question on a To Review item
    clientComment(1, 'Can we swap this tire angle?');
    db()->exec("UPDATE activity_log SET created_at = NOW() - INTERVAL 5 HOUR WHERE entity_id = 1 AND entity_type = 'post' AND actor = 'client'");
    clientComment(2, 'Slide 2 looks off');
    $rows = inboxRows();
    $keys = array_column($rows, 0);
    is(array_slice($keys, 0, 3), ['post:1', 'post:4', 'post:2'], 'oldest wait first (5h, 90min, now)');
    $since = array_values(array_filter(array_column($rows, 1)));
    $sorted = $since; sort($sorted);
    is($since, $sorted, 'ages ascending');
    $page = get('inbox.php', 'admin')['body'];
    has($page, 'Can we swap this tire angle?', 'last message snippet');
    has($page, 'ibx-age--warn', 'over 4 hours → orange');
    // answering clears it; it shows under Resolved with who and how fast
    adminComment(1, 'Sure — swapping it now.');
    ok(!in_array('post:1', array_column(inboxRows(), 0), true), 'answered');
    $res = get('inbox.php?tab=resolved', 'admin')['body'];
    has($res, 'data-inbox-row="post:1"');
    has($res, 'Replied by Lance after 5h');
    // Resolve (portal) — admin only; the denied post leaves too
    is(post('thread-action.php', ['action' => 'resolve', 'entity' => 'post:4'], 'client:kenda', [], J)['code'], 403);
    $r = post('thread-action.php', ['action' => 'resolve', 'entity' => 'post:4'], 'admin', [], J);
    is($r['code'], 200, $r['body']);
    is($r['json']['message'], 'Marked resolved');
    ok(!in_array('post:4', array_column(inboxRows(), 0), true), 'resolved');
    is((int)q1("SELECT internal FROM activity_log WHERE entity_id = 4 AND entity_type = 'post' AND action = 'resolved' ORDER BY id DESC LIMIT 1"), 1, 'an internal row (never shown to the client)');
    is(post('thread-action.php', ['action' => 'resolve', 'entity' => 'post:3'], 'admin', [], J)['json']['message'], 'Nothing was waiting');
    // the client resubmitting is not needed: a new client message re-opens it
    clientComment(4, 'Still too light');
    ok(in_array('post:4', array_column(inboxRows(), 0), true), 'back in the Inbox');
});

etest('Inbox: Waiting on client = To Review with no client answer since it was sent; Home badge + scoped view', function () {
    $keys = array_column(inboxRows('tab=client'), 0);
    foreach (['post:1', 'post:2', 'post:8', 'email:2', 'page:1'] as $k) ok(in_array($k, $keys, true), $k . ' waiting on the client');
    ok(in_array('library_image:7', $keys, true), 'pending library image');
    ok(count(preg_grep('/^tire_series:/', $keys)) >= 1, 'tire series with pending renders');
    ok(!in_array('post:6', $keys, true), 'drafts are not waiting on anyone');
    clientComment(1, 'Looking now');
    ok(!in_array('post:1', array_column(inboxRows('tab=client'), 0), true), 'the client answered');
    $scoped = array_column(inboxRows('client=privacybee&tab=client'), 0);
    ok(in_array('post:8', $scoped, true) && !in_array('post:2', $scoped, true), 'scoped to one client');
    // the admin Home tab badge = Waiting on Joust across clients
    $n = count(inboxRows());
    $home = get('index.php', 'admin')['body'];
    ok(preg_match('#data-queue="inbox">(\d+)<#', $home, $m) === 1, 'Home badge');
    is((int)$m[1], $n);
    has($home, 'data-home-link="inbox"');
    is(get('inbox.php', 'client:kenda')['code'], 302, 'admin only');
});

etest('unread: the admin sees a dot on threads with new client messages until opened; the contact sees Joust replies', function () {
    clientComment(1, 'New question from Jane');
    $list = get('posts.php?client=kenda&status=pending', 'admin')['body'];
    has($list, 'data-unread-for="post:1"', 'dot for the admin');
    hasNot($list, 'data-unread-for="post:2"', 'nothing new on post 2');
    has(get('inbox.php', 'admin')['body'], 'data-unread-for="post:1"', 'and in the Inbox');
    has($list, 'js/tracking.js', 'the marker script');
    $r = post('thread-action.php', ['action' => 'seen', 'entity' => 'post:1'], 'admin', [], J);
    is($r['code'], 200, $r['body']);
    hasNot(get('posts.php?client=kenda&status=pending', 'admin')['body'], 'data-unread-for="post:1"', 'gone once opened');
    // the client contact: a Joust reply is new for them
    hasNot(get('posts.php?client=kenda&status=pending', 'client')['body'], 'data-unread-for="post:1"', 'their own message is not new to them');
    adminComment(1, 'Answering Jane');
    has(get('posts.php?client=kenda&status=pending', 'client')['body'], 'data-unread-for="post:1"', 'dot for the client');
    hasNot(get('posts.php?client=kenda&status=pending', 'admin')['body'], 'data-unread-for="post:1"', 'Joust\'s own reply is not new to Joust');
    status(post('thread-action.php', ['action' => 'seen', 'entity' => 'post:1', 'client' => 'kenda'], 'client', [], J), 200);
    hasNot(get('posts.php?client=kenda&status=pending', 'client')['body'], 'data-unread-for="post:1"');
    // internal notes are never "new" for a client
    appRun('activityWithContext(["internal" => 1], static function () use ($pdo) { logActivity($pdo, 1, "post", 1, "commented", "admin", "note", "internal only"); });');
    hasNot(get('posts.php?client=kenda&status=pending', 'client')['body'], 'data-unread-for="post:1"');
    // a contact cannot mark another client's item; nobody cannot mark anything
    is(post('thread-action.php', ['action' => 'seen', 'entity' => 'post:8'], 'client:kenda', [], J)['code'], 403);
    is(post('thread-action.php', ['action' => 'seen', 'entity' => 'post:1'], 'anon', [], J)['code'], 403);
    is(post('thread-action.php', ['action' => 'seen', 'entity' => 'company:1'], 'admin', [], J)['code'], 400);
    // the per-viewer rows
    is((int)q1("SELECT COUNT(*) FROM thread_seen WHERE viewer_type = 'admin' AND viewer_id = 1 AND entity_id = 1"), 1);
    is((int)q1("SELECT COUNT(*) FROM thread_seen WHERE viewer_type = 'contact' AND viewer_id = 1 AND entity_id = 1"), 1);
});

etest('the asset viewer’s Comments panel marks a tire image read', function () {
    $img = (int)q1("SELECT id FROM tire_images WHERE status = 'pending' ORDER BY id LIMIT 1");
    status(post('tire-status.php', ['action' => 'comment', 'id' => $img, 'comment' => 'Angle?', 'client' => 'kenda'], 'client', [], J), 200);
    $j = appJson('$v = ["admin", 1]; echo json_encode(trackingUnreadIds($pdo, $v, "tire_image", [' . $img . ']));');
    is($j, [$img]);
    is(get('assets.php?client=kenda&partial=comments&kind=tire&id=' . $img, 'admin')['code'], 200);
    is(appJson('$v = ["admin", 1]; echo json_encode(trackingUnreadIds($pdo, $v, "tire_image", [' . $img . ']));'), []);
});

// =====================================================================================================================
// Weekly report + previews
// =====================================================================================================================
etest('weekly stats: median / max first response, approvals, waiting > 24h, per client; the Monday email', function () {
    $pdo = db();
    $pdo->exec("DELETE FROM activity_log");
    $t = strtotime('-3 days 10:00');
    $ins = $pdo->prepare("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, internal, created_at) VALUES (?, ?, ?, ?, ?, 's', ?, ?, ?)");
    $at = static function (int $min) use ($t) { return date('Y-m-d H:i:s', $t + $min * 60); };
    // Kenda: answered after 30 min and after 120 min (a follow-up while waiting does not restart the clock); an internal note does not answer
    $ins->execute([1, 'post', 1, 'commented', 'client', 'q1', 0, $at(0)]);
    $ins->execute([1, 'post', 1, 'commented', 'admin', 'internal', 1, $at(10)]);
    $ins->execute([1, 'post', 1, 'commented', 'client', 'q1 again', 0, $at(20)]);
    $ins->execute([1, 'post', 1, 'commented', 'admin', 'a1', 0, $at(30)]);
    $ins->execute([1, 'post', 2, 'denied', 'client', null, 0, $at(60)]);
    $ins->execute([1, 'post', 2, 'reset_pending', 'admin', null, 0, $at(180)]);
    $ins->execute([1, 'post', 3, 'approved', 'client', null, 0, $at(200)]);
    // Privacy Bee: answered after 60 min; and one still open for 2 days (waiting > 24h)
    $ins->execute([2, 'email', 2, 'commented', 'client', 'q', 0, $at(0)]);
    $ins->execute([2, 'email', 2, 'resolved', 'admin', null, 1, $at(60)]);
    $ins->execute([2, 'post', 8, 'commented', 'client', 'still waiting', 0, date('Y-m-d H:i:s', strtotime('-2 days'))]);
    $ins->execute([2, 'post', 8, 'approved', 'client', null, 0, date('Y-m-d H:i:s', strtotime('-2 days'))]);
    $j = appJson('echo json_encode(trackingWeeklyStats($pdo, date("Y-m-d 00:00:00", strtotime("-7 days")), date("Y-m-d H:i:s", time() + 60)));');
    $o = $j['overall'];
    is($o['messages'], 4, 'four waits opened (q1, the deny, the PB question, the open one)');
    is($o['answered'], 3);
    is((int)$o['median_minutes'], 60, 'median of 30 / 120 / 60');
    is($o['max_minutes'], 120);
    is($o['approved'], 2);
    ok($o['waiting_24h'] >= 1, 'the 2-day-old question');
    $k = array_values(array_filter($j['clients'], static function ($c) { return $c['name'] === 'Kenda Tires'; }))[0];
    is($k['messages'], 2); is((int)$k['median_minutes'], 75); is($k['max_minutes'], 120); is($k['approved'], 1);
    $p = array_values(array_filter($j['clients'], static function ($c) { return $c['name'] === 'Privacy Bee'; }))[0];
    is($p['answered'], 1); is((int)$p['median_minutes'], 60); ok($p['waiting_24h'] >= 1);
    // the email (forced; normally Mondays at the summary hour)
    clearMail();
    $c = cron('weekly=now');
    is($c['weekly'], 'queued');
    $m = mailsTo('lance@joustmedia.com');
    $w = array_values(array_filter($m, static function ($x) { return ($x['kind'] ?? '') === 'weekly'; }));
    is(count($w), 1);
    has($w[0]['subject'], 'Weekly report');
    has($w[0]['html'], 'data-weekly="median"');
    has($w[0]['html'], 'Kenda Tires');
    has($w[0]['text'], 'Median first reply');
    cron('weekly=now');
    is(count(array_filter(mailsTo('lance@joustmedia.com'), static function ($x) { return ($x['kind'] ?? '') === 'weekly'; })), 1, 'once per day');
});

etest('previews: every template renders with sample data (admin only); Manage links them', function () {
    foreach (['review', 'reply', 'live', 'weekly'] as $t) {
        $r = get('email-preview.php?type=' . $t . '&client=kenda', 'admin');
        is($r['code'], 200, $t);
        has($r['body'], 'data-preview-type="' . $t . '"');
        has($r['body'], '<table role="presentation"', 'table layout');
        has($r['body'], '@media only screen and (max-width:620px)', 'responsive');
        $x = get('email-preview.php?type=' . $t . '&format=text', 'admin');
        has($x['body'], 'data-preview-text');
    }
    has(get('email-preview.php?type=reply&client=kenda', 'admin')['body'], 'Lance at Joust');
    is(get('email-preview.php?type=review', 'client:kenda')['code'], 302, 'client → sign in');
    $m = get('manage.php?section=notifications', 'admin')['body'];
    foreach (['review', 'reply', 'live', 'weekly'] as $t) has($m, 'data-email-preview="' . $t . '"');
    $c = get('manage.php?section=clients&client=kenda', 'admin')['body'];
    has($c, 'data-client-email-kind="review"');
    has($c, 'data-client-email-state="on"');
});

// =====================================================================================================================
// Router + config
// =====================================================================================================================
etest('router: google-oauth and email-prefs are machine endpoints (never routed, never a client page)', function () {
    $out = appRun('foreach (["google-oauth", "email-prefs"] as $n) echo $n, ":", in_array($n, portalMachineEndpoints(), true) ? "m" : "-", in_array($n, portalReservedSegments(), true) ? "r" : "-", var_export(portalRouteMatch($n . "/"), true), "\n";');
    has($out, 'google-oauth:mrNULL');
    has($out, 'email-prefs:mrNULL');
    $ex = require dirname(__DIR__, 2) . '/config.example.php';
    foreach (['google_client_id', 'google_client_secret', 'google_token_key', 'inbound_address', 'google_api_base', 'google_oauth_base'] as $k) {
        ok(array_key_exists($k, $ex), 'config.example.php: ' . $k);
        is($ex[$k], '', $k . ' blank');
    }
    is($ex['mail_transport'], '', 'automatic by default');
});

finish();
