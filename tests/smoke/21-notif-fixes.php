<?php
/**
 * Notification fixes after the re-score (scratchpad notif-rescore.md §5):
 *   1  inbound sender authentication — Google's Authentication-Results (the topmost mx.google.com one): dmarc=pass or an
 *      aligned dkim=pass, else Unmatched "Failed sender check", never posted (a forged From: lance@ with the [J#] tag)
 *   2  client emails start OFF (migrate 47 default, step 50 for older installs — only when none was ever sent) and are
 *      HELD until Google is connected (or mail() is explicitly allowed); sign-in emails never wait; Manage → Clients banner
 *   4  staging: environment detection, its own inbound address, refuses to poll production's
 *   5  the real Gmail Message-ID (the stub can rewrite it) is what threading stores
 *   6  run.sh is executable in git
 *   7  internal notes from the portal composer · asset reviews in "Ready for your review" · stale To Review reminders ·
 *      escalation quiet hours · per-person settings + the Inbox "Mine" filter · the client Home "Joust replied" card ·
 *      no Slack channel → an email to the owner (≤ 1 per item per 15 min) + the Manage warning
 * (3, unread on deep links, is a browser fix: tests/e2e/16-notif-fixes.js.) Every test starts from the seed.
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
function ftest(string $name, callable $fn): void { test($name, static function () use ($fn) { reseedNow(); $fn(); }); }

// ---- helpers (same shapes as 20-email.php) ------------------------------------------------------------------------
function mails(): array { global $MAIL; $o = []; foreach (glob($MAIL . '/*.json') ?: [] as $f) $o[] = json_decode((string)file_get_contents($f), true); return $o; }
function clearMail(): void { global $MAIL; foreach (glob($MAIL . '/*') ?: [] as $f) @unlink($f); }
function mailsTo(string $to): array { return array_values(array_filter(mails(), static function ($m) use ($to) { return strtolower((string)$m['to']) === $to; })); }
function clientMails(): array { return array_values(array_filter(mails(), static function ($m) { return strpos((string)($m['kind'] ?? ''), 'client_') === 0; })); }
function cron(string $extra = ''): array {
    $r = get('notify-cron.php' . ($extra !== '' ? '?' . $extra : ''), 'anon', ['X-Notify-Token' => CRON_TK]);
    is($r['code'], 200, 'cron ran: ' . substr($r['body'], 0, 200));
    return $r['json'] ?? [];
}
function gbox(?array $set = null): array {
    global $GDIR;
    $f = $GDIR . '/mailbox.json';
    if ($set !== null) { file_put_contents($f, json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); return $set; }
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d + ['messages' => [], 'labels' => []] : ['messages' => [], 'labels' => []];
}
function gsent(): array { global $GDIR; $o = []; $fs = glob($GDIR . '/sent/*.eml') ?: []; sort($fs); foreach ($fs as $f) $o[] = (string)file_get_contents($f); return $o; }
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function deliver(string $id, string $raw): void { $b = gbox(); $b['messages'][] = ['id' => $id, 'threadId' => 't' . $id, 'raw' => b64u($raw), 'labelIds' => ['INBOX']]; gbox($b); }
/** The Authentication-Results line Google's MX adds. */
function googleAr(string $dkim, string $dkimDomain, string $spf, string $dmarc, string $fromDomain): string {
    return "mx.google.com;\r\n       dkim={$dkim} header.i=@{$dkimDomain} header.s=s1 header.b=Zz9;\r\n       spf={$spf} (google.com: domain of x@{$fromDomain}) smtp.mailfrom=x@{$fromDomain};\r\n       dmarc={$dmarc} (p=QUARANTINE sp=QUARANTINE dis=NONE) header.from={$fromDomain}";
}
/** An RFC 5322 message; $ar = the Authentication-Results values, topmost first ([] = none). */
function rawMail(array $h, string $body, array $ar): string {
    $lines = [];
    foreach ($ar as $v) $lines[] = 'Authentication-Results: ' . $v;
    $h += ['To' => 'Joust Media <lance+ai@joustmedia.com>', 'Date' => date('r'), 'Message-ID' => '<' . bin2hex(random_bytes(6)) . '@mail.example>', 'MIME-Version' => '1.0'];
    foreach ($h as $k => $v) $lines[] = $k . ': ' . $v;
    $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
    return implode("\r\n", $lines) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n";
}
/** PHP inside the test app (the whole helpers chain); $cfg overrides config.php keys; $ini adds php -d settings. */
function appRun(string $code, array $cfg = [], array $ini = []): string {
    global $APP;
    $file = sys_get_temp_dir() . '/nfix_smoke_' . bin2hex(random_bytes(4)) . '.php';
    $base = getenv('PORTAL_TEST_BASE') ?: 'http://127.0.0.1:8099/portal';
    file_put_contents($file, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/portal/notify-cron.php'; \$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "\$_SERVER['HTTP_HOST'] = " . var_export(parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT), true) . ";\n"
        . "chdir(" . var_export($APP, true) . ");\nrequire 'db.php';\n"
        . "foreach (" . var_export($cfg, true) . " as \$__k => \$__v) { if (\$__v === null) unset(\$config[\$__k]); else \$config[\$__k] = \$__v; }\n"
        . "require_once 'helpers.php';\n" . $code . "\n");
    $d = '';
    foreach ($ini as $k => $v) $d .= ' -d ' . escapeshellarg($k . '=' . $v);
    $out = (string)shell_exec('php -d display_errors=stderr' . $d . ' ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return $out;
}
function appJson(string $code, array $cfg = [], array $ini = []) {
    $out = appRun($code, $cfg, $ini);
    $j = json_decode(trim($out), true);
    if ($j === null && trim($out) !== 'null') fail('appRun: ' . substr($out, 0, 400));
    return $j;
}
function sessCookie(array $r): string { foreach ($r['cookies'] as $c) if (preg_match('/^jsm_admin=([^;]+)/', $c, $m)) return 'jsm_admin=' . $m[1]; return ''; }
function connectGoogle(): void {
    $r = post('google-oauth.php', ['action' => 'start'], 'admin');
    $cookie = sessCookie($r);
    $g = get($r['location'], 'anon');
    $b = get($g['location'], 'admin', ['Cookie' => $cookie]);
    is($b['code'], 303, 'Google connected');
    ok((int)q1("SELECT COUNT(*) FROM google_account") === 1, 'account stored');
}
function adminComment(int $postId, string $text): void { status(post('status.php', ['id' => $postId, 'comment' => $text, 'client' => 'kenda'], 'admin', [], J), 200, 'admin comment'); }
function clientComment(int $postId, string $text): void { status(post('status.php', ['id' => $postId, 'comment' => $text, 'client' => 'kenda'], 'client', [], J), 200, 'client comment'); }
/** After-response deliveries (php -S finishes them after the reply): wait until $sql returns $want (≤ 8 s). */
function waitFor(string $sql, $want, array $p = []): void {
    for ($i = 0; $i < 160; $i++) { if ((string)q1($sql, $p) === (string)$want) return; usleep(50000); }
}
function ageQueue(int $minutes): void { db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL {$minutes} MINUTE WHERE batch_key IS NULL"); }
function commentsOn(int $postId, string $actor): array { return rows("SELECT * FROM activity_log WHERE entity_type = 'post' AND entity_id = ? AND action = 'commented' AND actor = ? ORDER BY id", [$postId, $actor]); }
function slackCalls(): array { global $ROOT; $f = $ROOT . '/slack-calls.jsonl'; return is_file($f) ? array_values(array_filter(array_map(static function ($l) { return json_decode($l, true); }, file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))) : []; }
function slackReset(): void { global $ROOT; @unlink($ROOT . '/slack-calls.jsonl'); }
/** Rewrite one key of the running test site's config.php (restored by the caller). */
function siteConfig(array $set): string {
    global $APP;
    $f = $APP . '/config.php';
    $orig = (string)file_get_contents($f);
    $add = '';
    foreach ($set as $k => $v) $add .= '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
    file_put_contents($f, preg_replace('/\];\s*$/', $add . "];\n", $orig));
    sleep(3);   // php -S + opcache: config.php is re-read once its timestamp is revalidated
    return $orig;
}
function siteConfigRestore(string $orig): void { global $APP; file_put_contents($APP . '/config.php', $orig); sleep(3); }
/** One "Joust replied" email about post 1 to Jane (the sink) → the item's [J#] token. */
function itemToken(): string {
    adminComment(1, 'We darkened the render — have another look?');
    ageQueue(11);
    cron();
    return (string)q1("SELECT email_token FROM notify_threads WHERE entity_type = 'post' AND entity_id = 1");
}

// =====================================================================================================================
// 6. Harness hygiene
// =====================================================================================================================
ftest('run.sh and the other harness scripts are executable in git', function () {
    $repo = dirname(__DIR__, 2);
    $ls = (string)shell_exec('cd ' . escapeshellarg($repo) . ' && git ls-files -s tests/*.sh');
    foreach (['run.sh', 'bootstrap.sh', 'serve.sh', 'env.sh'] as $f) ok(preg_match('/^100755 \S+ \d\ttests\/' . preg_quote($f, '/') . '$/m', $ls) === 1, $f . ' is 100755: ' . $ls);
});

// =====================================================================================================================
// 1. Inbound sender authentication
// =====================================================================================================================
ftest('inbound: a forged From: lance@joustmedia.com with the right [J#] tag → Unmatched "Failed sender check"; nothing posted, nothing emailed', function () {
    connectGoogle();
    $tok = itemToken();
    db()->exec("UPDATE client_email_queue SET batch_key = 'old' WHERE batch_key IS NULL");
    $adminBefore = count(commentsOn(1, 'admin'));
    // spf / dkim / dmarc fail (what Google stamps on a spoof), plus a forged "pass" header the attacker added BELOW Google's
    deliver('f1', rawMail(['From' => 'Lance <lance@joustmedia.com>', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Approved — we will waive the invoice.",
        [googleAr('none', 'evil.example', 'fail', 'fail', 'joustmedia.com'), 'mx.google.com; dkim=pass header.i=@joustmedia.com; dmarc=pass header.from=joustmedia.com']));
    // no Authentication-Results at all
    deliver('f2', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "No headers", []));
    // DKIM passes, but for another domain (not aligned with kenda.example); DMARC fails
    deliver('f3', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Signed by someone else",
        [googleAr('pass', 'mailer.evil.example', 'pass', 'fail', 'kenda.example')]));
    // an Authentication-Results that is NOT Google's (another authserv-id) does not count
    deliver('f4', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Other server",
        ['relay.evil.example; dkim=pass header.d=kenda.example; dmarc=pass header.from=kenda.example']));
    $c = cron();
    is((int)$c['inbound']['posted'], 0, 'nothing posted');
    is((int)$c['inbound']['unmatched'], 4);
    is(count(commentsOn(1, 'admin')), $adminBefore, 'no comment as Joust');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE detail LIKE '%waive the invoice%'"), 0);
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE batch_key IS NULL"), 0, 'no "Joust replied" queued for the client');
    foreach (['f1', 'f2', 'f3', 'f4'] as $g) {
        $row = rows("SELECT * FROM email_inbound WHERE gmail_id = ?", [$g])[0];
        is($row['status'], 'unmatched', $g);
        ok(strpos((string)$row['reason'], 'Failed sender check') === 0, $g . ' reason: ' . $row['reason']);
        is($row['entity_type'] . ':' . $row['entity_id'], 'post:1', $g . ' keeps the item it claimed (for Assign)');
    }
    has((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = 'f1'"), 'dmarc=fail');
    has((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = 'f1'"), 'claims to be Joust');
    has((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = 'f2'"), 'no Authentication-Results from mx.google.com');
    // Manage shows it, marked
    $m = get('manage.php?section=notifications', 'admin')['body'];
    has($m, 'data-notify-unmatched="4"');
    has($m, 'data-unmatched-auth="fail"');
    has($m, 'Failed sender check');
    // Assign never posts it as Joust
    $id = (int)q1("SELECT id FROM email_inbound WHERE gmail_id = 'f1'");
    status(post('notify-admin.php', ['action' => 'inbound_assign', 'id' => $id, 'entity' => 'post:1'], 'admin', [], J), 200);
    $last = rows("SELECT actor, author_user_id, client_contact_id FROM activity_log WHERE detail LIKE '%waive the invoice%'")[0];
    is($last['actor'], 'client', 'posted as an unnamed client message');
    is($last['author_user_id'], null);
    is($last['client_contact_id'], null);
});

ftest('inbound: genuine mail passes — DMARC pass from Lance posts as Joust; an aligned DKIM pass from a contact posts as Jane', function () {
    connectGoogle();
    $tok = itemToken();
    db()->exec("UPDATE client_email_queue SET batch_key = 'old' WHERE batch_key IS NULL");
    deliver('g1', rawMail(['From' => 'Lance <lance@joustmedia.com>', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Will do, Jane.",
        [googleAr('pass', 'joustmedia.com', 'pass', 'pass', 'joustmedia.com')]));
    // DMARC not evaluated (none), DKIM pass with a subdomain signature: relaxed alignment
    deliver('g2', rawMail(['From' => 'Jane Kenda <jane@kenda.example>', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Thanks, looks great.",
        ["mx.google.com;\r\n       dkim=pass header.i=@mail.kenda.example header.s=k1 header.b=Q1;\r\n       spf=softfail smtp.mailfrom=jane@kenda.example"]));
    $c = cron();
    is((int)$c['inbound']['posted'], 2);
    $a = commentsOn(1, 'admin');
    is(end($a)['detail'], 'Will do, Jane.');
    is((int)end($a)['author_user_id'], 1, 'Lance');
    $cl = commentsOn(1, 'client');
    is(end($cl)['detail'], 'Thanks, looks great.');
    is((int)end($cl)['client_contact_id'], 1, 'Jane');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'reply' AND batch_key IS NULL"), 1, 'Lance’s genuine reply reaches the client');
    // unit: the topmost mx.google.com header is the one that counts
    $j = appJson('$m = notifyMimeParse(' . var_export(rawMail(['From' => 'jane@kenda.example'], 'x', ['mx.google.com; dmarc=fail header.from=kenda.example', 'mx.google.com; dmarc=pass header.from=kenda.example']), true) . ');
        echo json_encode([inboundAuthCheck($m, "jane@kenda.example")["ok"], inboundDomainsAligned("kenda.example", "mail.kenda.example"), inboundDomainsAligned("example", "kenda.example"), inboundDomainsAligned("evil-kenda.example", "kenda.example")]);');
    is($j, [false, true, false, false]);
});

// =====================================================================================================================
// 2. Client emails: off by default, held until Google, sign-in exempt
// =====================================================================================================================
ftest('migrate 47 / 50 / 51: switches default OFF; step 50 turns existing rows off only when no client email was ever sent; once', function () {
    foreach (['email_review', 'email_replies', 'email_live', 'email_remind'] as $col) {
        is((string)q1("SELECT TRIM(BOTH '\\'' FROM COLUMN_DEFAULT) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notify_clients' AND COLUMN_NAME = ?", [$col]), '0', $col . ' default 0');
    }
    is((string)q1("SELECT TRIM(BOTH '\\'' FROM COLUMN_DEFAULT) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notify_clients' AND COLUMN_NAME = 'remind_days'"), '3');
    is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'notify_prefs'"), 1);
    ok(q1("SELECT v FROM meta WHERE k = 'client_email_default_off'") !== false, 'meta flag set');
    // a new client row starts off
    db()->exec("INSERT INTO notify_clients (company_id) VALUES (3)");
    is(rows("SELECT email_review, email_replies, email_live, email_remind FROM notify_clients WHERE company_id = 3")[0], ['email_review' => 0, 'email_replies' => 0, 'email_live' => 0, 'email_remind' => 0]);
    // an older install (step 47 created them ON) that never emailed a client → off
    db()->exec("ALTER TABLE notify_clients MODIFY COLUMN email_review TINYINT(1) NOT NULL DEFAULT 1, MODIFY COLUMN email_replies TINYINT(1) NOT NULL DEFAULT 1, MODIFY COLUMN email_live TINYINT(1) NOT NULL DEFAULT 1");
    db()->exec("DELETE FROM meta WHERE k = 'client_email_default_off'");
    db()->exec("UPDATE notify_clients SET email_review = 1, email_replies = 1, email_live = 1");
    $r = get('migrate.php', 'admin');
    has($r['body'], 'Migration complete');
    has($r['body'], 'Client emails now start off');
    is((int)q1("SELECT SUM(email_review + email_replies + email_live) FROM notify_clients"), 0, 'every switch off');
    is((string)q1("SELECT TRIM(BOTH '\\'' FROM COLUMN_DEFAULT) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notify_clients' AND COLUMN_NAME = 'email_live'"), '0');
    // runs once: switching back on and re-running changes nothing
    db()->exec("UPDATE notify_clients SET email_review = 1 WHERE company_id = 1");
    $r = get('migrate.php', 'admin');
    has($r['body'], 'Client emails already start off — skipped');
    is((int)q1("SELECT email_review FROM notify_clients WHERE company_id = 1"), 1, 'the admin’s later choice is kept');
    // an install that already emailed clients keeps its switches
    db()->exec("DELETE FROM meta WHERE k = 'client_email_default_off'");
    db()->exec("UPDATE notify_clients SET email_review = 1, email_replies = 1, email_live = 1");
    db()->exec("INSERT INTO notify_outbox (channel, kind, payload, status, dedupe_key) VALUES ('email', 'client_email', '{}', 'sent', 'old-ce')");
    $r = get('migrate.php', 'admin');
    has($r['body'], 'existing switches kept');
    is((int)q1("SELECT SUM(email_review + email_replies + email_live) FROM notify_clients"), 9, 'kept');
});

ftest('client emails are held (queued, not dropped) until Google is connected or mail() is allowed; sign-in emails always go', function () {
    status(post('status.php', ['action' => 'submit', 'id' => 6, 'client' => 'kenda'], 'admin', [], J), 200);
    ageQueue(16);
    $cfg = ['mail_transport' => '', 'mail_sink_dir' => null];   // production-like: automatic transport, no sink
    $ini = ['sendmail_path' => '/bin/true'];                    // mail() "works" but must not be used for clients
    $j = appJson('echo json_encode(["t" => notifyMailTransport(), "ok" => clientEmailTransportOk($pdo), "run" => clientEmailRun($pdo)]);', $cfg, $ini);
    is($j['t'], 'mail', 'not connected → mail()');
    is($j['ok'], false, 'not allowed for client emails');
    is((int)$j['run']['held'], 1, 'held');
    is((int)$j['run']['review'], 0);
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE batch_key IS NULL"), 1, 'still queued');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'client_email'"), 0, 'nothing enqueued');
    // the sign-in email is exempt: it goes out through mail() right away
    $s = appJson('echo json_encode(clientMagicSend("jane@kenda.example", [["company" => "Kenda Tires", "url" => "https://example.test/x"]]));', $cfg, $ini);
    is($s, true, 'sign-in sent');
    is((string)q1("SELECT status FROM notify_outbox WHERE kind = 'sign_in' ORDER BY id DESC LIMIT 1"), 'sent');
    // an already-queued client email waits too (hold: not a failed attempt)
    db()->exec("INSERT INTO notify_outbox (channel, kind, company_id, target, payload, dedupe_key) VALUES ('email', 'client_email', 1, 'jane@kenda.example', '{\"batch_key\":\"x\",\"kind\":\"review\",\"company_id\":1,\"contact_id\":1}', 'held-1')");
    $oid = (int)q1("SELECT id FROM notify_outbox WHERE dedupe_key = 'held-1'");
    appRun('notifyPump($pdo, ["ids" => [' . $oid . ']]);', $cfg, $ini);
    $row = rows("SELECT status, attempts, last_error, next_attempt_at > NOW() AS later FROM notify_outbox WHERE id = ?", [$oid])[0];
    is($row['status'], 'pending', 'held');
    is((int)$row['attempts'], 0, 'not counted as a try');
    has((string)$row['last_error'], 'held');
    is((int)$row['later'], 1);
    // Manage → Notifications: the held line and the allow switch
    $m = get('manage.php?section=notifications', 'admin')['body'];
    has($m, 'data-client-mail-allow');
    has($m, 'Allow sending client emails without Google (mail())');
    // the admin explicitly allows mail() → the batch goes
    status(post('notify-admin.php', ['action' => 'client_mail_allow', 'allow' => 1], 'admin', [], J), 200);
    is((string)q1("SELECT v FROM meta WHERE k = 'client_emails_allow_mail'"), '1');
    $j = appJson('echo json_encode(["ok" => clientEmailTransportOk($pdo), "run" => clientEmailRun($pdo)]);', $cfg, $ini);
    is($j['ok'], true);
    is((int)$j['run']['review'], 1, 'batched');
    is((int)$j['run']['emails'], 2);
    status(post('notify-admin.php', ['action' => 'client_mail_allow', 'allow' => 0], 'admin', [], J), 200);
    is(post('notify-admin.php', ['action' => 'client_mail_allow', 'allow' => 1], 'client:kenda', [], J)['code'], 403, 'admin only');
    // Google connected → gmail → allowed without the switch
    connectGoogle();
    is(appJson('echo json_encode([notifyMailTransport(), clientEmailTransportOk($pdo)]);', $cfg, $ini), ['gmail', true]);
});

ftest('Manage → Clients: "Client emails are off — turn on per client when ready" and "Connect Google first"', function () {
    db()->exec("UPDATE notify_clients SET email_review = 0, email_replies = 0, email_live = 0");
    $b = get('manage.php?section=clients', 'admin')['body'];
    has($b, 'data-client-emails-banner');
    has($b, 'Client emails are off — turn on per client when ready');
    has($b, 'Connect Google first');
    // a client's card: every switch off, the reminder days field
    $c = get('manage.php?section=clients&client=hmf', 'admin')['body'];
    has($c, 'data-client-email-kind="remind"');
    hasNot($c, 'data-client-email-state="on"', 'all off for a client without a row');
    has($c, 'data-client-remind-days="3"');
    // turn one on + connect Google → no banner
    status(post('client-admin.php', ['action' => 'email_toggle', 'id' => 1, 'kind' => 'review', 'to' => 1], 'admin', [], J), 200);
    connectGoogle();
    hasNot(get('manage.php?section=clients', 'admin')['body'], 'data-client-emails-banner');
    // remind days: 0–30, admin only
    status(post('client-admin.php', ['action' => 'remind_days', 'id' => 1, 'days' => 5], 'admin', [], J), 200);
    is((int)q1("SELECT remind_days FROM notify_clients WHERE company_id = 1"), 5);
    is(post('client-admin.php', ['action' => 'remind_days', 'id' => 1, 'days' => 31], 'admin', [], J)['code'], 400);
    is(post('client-admin.php', ['action' => 'remind_days', 'id' => 1, 'days' => 2], 'client:kenda', [], J)['code'], 403);
});

// =====================================================================================================================
// 4. Staging
// =====================================================================================================================
ftest('staging: detected from config / portal_url; its own inbound address; production’s address → refuses to poll + Manage warning', function () {
    $q = 'echo json_encode([portalEnvironment(), inboundAddress(), inboundSharedWithProduction()]);';
    is(appJson($q), ['production', 'lance+ai@joustmedia.com', false], 'the default');
    is(appJson($q, ['environment' => 'staging']), ['staging', 'lance+ai-staging@joustmedia.com', false], 'staging → its own address');
    is(appJson($q, ['portal_url' => 'https://joustmedia.com/portal-staging']), ['staging', 'lance+ai-staging@joustmedia.com', false], 'detected from portal_url');
    is(appJson($q, ['environment' => 'staging', 'inbound_address' => 'lance+ai@joustmedia.com']), ['staging', 'lance+ai@joustmedia.com', true], 'shared');
    is(appJson($q, ['environment' => 'production', 'portal_url' => 'https://joustmedia.com/portal-staging']), ['production', 'lance+ai@joustmedia.com', false], 'explicit wins');
    connectGoogle();
    $orig = siteConfig(['environment' => 'staging', 'inbound_address' => 'lance+ai@joustmedia.com']);
    try {
        deliver('st1', "From: jane@kenda.example\r\nSubject: x\r\n\r\nhello\r\n");
        $c = cron();
        is($c['inbound']['status'], 'refused_staging');
        has((string)$c['inbound']['error'], 'lance+ai-staging@joustmedia.com');
        is((int)q1("SELECT COUNT(*) FROM email_inbound"), 0, 'production’s mail untouched');
        $m = get('manage.php?section=notifications', 'admin')['body'];
        has($m, 'data-staging-inbound-warning');
        has($m, 'data-environment="staging"');
    } finally {
        siteConfigRestore($orig);
    }
    $orig = siteConfig(['environment' => 'staging']);
    try {
        $c = cron();
        is($c['inbound']['status'], 'ok', 'staging with its own address polls');
        $calls = array_map(static function ($l) { return json_decode($l, true); }, file($GLOBALS['GDIR'] . '/calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $list = array_values(array_filter($calls, static function ($x) { return $x['path'] === '/gmail/v1/users/me/messages' && $x['method'] === 'GET'; }));
        has((string)end($list)['query']['q'], 'to:lance+ai-staging@joustmedia.com');
        hasNot(get('manage.php?section=notifications', 'admin')['body'], 'data-staging-inbound-warning');
    } finally {
        siteConfigRestore($orig);
    }
});

// =====================================================================================================================
// 5. The real Message-ID (Gmail may rewrite it)
// =====================================================================================================================
ftest('Gmail Message-ID: the id Gmail really sent is stored and threaded on (stub rewriting on), unchanged when Gmail keeps ours', function () {
    global $GDIR;
    connectGoogle();
    $cfg = ['mail_transport' => 'gmail'];
    $send = 'echo json_encode([notifyEmail(["to" => "jane@kenda.example", "subject" => "One", "text" => "x", "thread" => ["entity_type" => "post", "entity_id" => 2, "company_id" => 1]]),
                               notifyEmail(["to" => "jane@kenda.example", "subject" => "Two", "text" => "y", "thread" => ["entity_type" => "post", "entity_id" => 2, "company_id" => 1]])]);';
    // Gmail keeps it
    $j = appJson($send, $cfg);
    is($j[0]['message_id'], $j[0]['generated_message_id'], 'kept');
    is(q1("SELECT email_message_id FROM notify_threads WHERE entity_type = 'post' AND entity_id = 2"), $j[0]['message_id']);
    // Gmail rewrites it
    db()->exec("UPDATE notify_threads SET email_message_id = NULL WHERE entity_type = 'post' AND entity_id = 2");
    file_put_contents($GDIR . '/rewrite_mid.txt', '1');
    try {
        $j = appJson($send, $cfg);
        ok($j[0]['message_id'] !== $j[0]['generated_message_id'], 'rewritten');
        has($j[0]['message_id'], '@mail.gmail.com>');
        is(q1("SELECT email_message_id FROM notify_threads WHERE entity_type = 'post' AND entity_id = 2"), $j[0]['message_id'], 'the thread keeps Gmail’s id');
        $sent = gsent();
        $last = end($sent);
        ok(preg_match('/^In-Reply-To: (.+)$/mi', $last, $m) === 1, 'the second mail replies');
        is(trim($m[1]), $j[0]['message_id'], 'In-Reply-To = the id the client really received');
        $calls = array_map(static function ($l) { return json_decode($l, true); }, file($GDIR . '/calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $meta = array_values(array_filter($calls, static function ($x) { return preg_match('#/messages/sent\d+$#', $x['path']) && ($x['query']['format'] ?? '') === 'metadata'; }));
        ok(count($meta) >= 2, 'read back after each send');
        is($meta[0]['query']['metadataHeaders'] ?? '', 'Message-ID');
        // a client email stores the real id in notify_email_refs, and the client's reply to it is matched
        db()->exec("UPDATE notify_threads SET email_message_id = NULL");
        adminComment(1, 'Swapped the crop.');
        ageQueue(11);
        appRun('clientEmailRun($pdo); notifyPump($pdo);', $cfg);
        $ref = (string)q1("SELECT message_id FROM notify_email_refs WHERE entity_type = 'post' AND entity_id = 1 AND contact_id = 1 ORDER BY created_at DESC LIMIT 1");
        has($ref, '@mail.gmail.com>', 'notify_email_refs holds Gmail’s id');
        deliver('mr1', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Re: Joust replied', 'In-Reply-To' => $ref, 'References' => $ref], "Matched by the real id",
            [googleAr('pass', 'kenda.example', 'pass', 'pass', 'kenda.example')]));
        cron();
        is((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = 'mr1'"), 'matched by in-reply-to');
    } finally {
        @unlink($GDIR . '/rewrite_mid.txt');
    }
});

// =====================================================================================================================
// 7a. Internal notes from the portal
// =====================================================================================================================
ftest('internal note from the portal: Joust-only everywhere, no client email, posted to Slack marked internal; clients cannot', function () {
    global $ROOT;
    slackReset();
    $page = get('posts.php?client=kenda&post=1&partial=1', 'admin')['body'];
    has($page, 'data-comment-internal', 'the admin composer has the switch');
    has($page, 'Internal (Joust only)');
    hasNot(get('posts.php?client=kenda&post=1&partial=1', 'client')['body'], 'data-comment-internal', 'never for the client');
    $r = post('status.php', ['id' => 1, 'comment' => 'SECRET: client pays late, keep it short', 'internal' => 1, 'client' => 'kenda'], 'admin', [], J);
    is($r['code'], 200, $r['body']);
    waitFor("SELECT status FROM notify_outbox WHERE kind = 'internal_note'", 'sent');
    is($r['json']['internal'], true);
    $row = rows("SELECT * FROM activity_log WHERE detail = 'SECRET: client pays late, keep it short'")[0];
    is((int)$row['internal'], 1);
    is($row['actor'], 'admin');
    is((int)$row['author_user_id'], 1);
    is(q1("SELECT client_comment FROM posts WHERE id = 1"), null, 'never the post’s visible comment');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue"), 0, 'no "Joust replied"');
    // Slack: the item's thread, marked internal
    $calls = slackCalls();
    $txt = implode("\n", array_map(static function ($c) { return (string)($c['body']['text'] ?? ''); }, $calls));
    has($txt, ':lock: *Internal note*');
    has($txt, 'SECRET: client pays late');
    is((string)q1("SELECT status FROM notify_outbox WHERE kind = 'internal_note'"), 'sent');
    // hidden from the client everywhere
    foreach (['posts.php?client=kenda&post=1&partial=1', 'posts.php?client=kenda&status=pending', 'index.php?client=kenda', 'feed.php?client=kenda'] as $p) {
        hasNot(get($p, 'client')['body'], 'SECRET: client pays late', 'client: ' . $p);
    }
    $adm = get('posts.php?client=kenda&post=1&partial=1', 'admin')['body'];
    has($adm, 'SECRET: client pays late', 'Joust sees it');
    has($adm, 'data-internal-pill');
    // the client cannot write one; an internal note is a message only
    is(post('status.php', ['id' => 1, 'comment' => 'x', 'internal' => 1, 'client' => 'kenda'], 'client', [], J)['code'], 403);
    is(post('status.php', ['id' => 1, 'comment' => 'x', 'status' => 'approved', 'internal' => 1, 'client' => 'kenda'], 'admin', [], J)['code'], 400);
    // emails + pages
    status(post('email-status.php', ['id' => 2, 'comment' => 'Internal email note', 'internal' => 1, 'client' => 'privacybee'], 'admin', [], J), 200);
    status(post('page-status.php', ['id' => 1, 'comment' => 'Internal page note', 'internal' => 1, 'client' => 'privacybee'], 'admin', [], J), 200);
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE internal = 1 AND detail IN ('Internal email note', 'Internal page note')"), 2);
    hasNot(get('emails.php?client=privacybee&email=2&partial=1', 'client:privacybee')['body'], 'Internal email note');
    hasNot(get('pages.php?client=privacybee&page=1&partial=1', 'client:privacybee')['body'], 'Internal page note');
    is(post('email-status.php', ['id' => 2, 'comment' => 'x', 'internal' => 1, 'client' => 'privacybee'], 'client:privacybee', [], J)['code'], 403);
    is((int)q1("SELECT COUNT(*) FROM client_email_queue"), 0);
    // a visible reply still emails the client (control)
    adminComment(1, 'Visible reply');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'reply'"), 1);
});

// =====================================================================================================================
// 7b. Asset reviews in "Ready for your review"
// =====================================================================================================================
ftest('assets sent for review (tire renders uploaded to a series, library drops, an image reset to To Review) → the review batch, with thumbnails + links', function () {
    global $ROOT;
    clearMail();
    // a render uploaded into Klever AT2 · Series 1
    status(post('tire-upload.php?client=kenda', ['client' => 'kenda', 'tire_id' => 1, 'series_id' => 1], 'admin', ['file' => tmpImage('new render')]), 200);
    // a file dropped into the Library folder (FTP / Drive) — registered when the Library is opened
    $media = getenv('MEDIA_DIR') ?: $ROOT . '/site/media';
    copy(tmpImage('ftp drop'), $media . '/library/kenda/ftp-drop.jpg');
    status(get('assets.php?client=kenda&view=library', 'admin'), 200);
    $lib = (int)q1("SELECT id FROM library_images WHERE filename = 'ftp-drop.jpg'");
    ok($lib > 0, 'registered');
    // an approved library image moved back to To Review
    status(post('library-status.php', ['id' => 1, 'status' => 'pending', 'client' => 'kenda'], 'admin', [], J), 200);
    $q = rows("SELECT entity_type, entity_id FROM client_email_queue WHERE kind = 'review' ORDER BY id");
    $keys = array_map(static function ($r) { return $r['entity_type'] . ':' . $r['entity_id']; }, $q);
    ok(in_array('tire_series:1', $keys, true), 'series queued: ' . json_encode($keys));
    ok(in_array('library_image:' . $lib, $keys, true), 'library drop queued');
    ok(in_array('library_image:1', $keys, true), 'library reset queued');
    ageQueue(16);
    cron();
    $m = mailsTo('jane@kenda.example');
    is(count($m), 1, 'one batch email');
    $x = $m[0];
    is($x['subject'], '3 items ready for your review — Kenda Tires');
    has($x['html'], 'Klever AT2 · Series 1', 'the series');
    has($x['html'], 'Image in Library #' . $lib);
    is(substr_count($x['html'], 'notify-thumb'), 3, 'a thumbnail per item');
    preg_match_all('#href="([^"]+)"[^>]*data-email-link#', $x['html'], $lk);
    $links = implode(' ', array_map('html_entity_decode', $lk[1]));
    has($links, 'item=1&view=collections&series=1&k=1.', 'a signed link to the series: ' . $links);
    has($links, 'asset=' . $lib . '&kind=library&k=1.', 'a signed link to the library image');
    ok(preg_match_all('#data-email-link#', $x['html']) === 3, 'three signed links');
    // the thumbnail URL serves a JPEG
    preg_match('#src="([^"]*notify-thumb[^"]*)"#', $x['html'], $tm);
    $t = get(html_entity_decode($tm[1]), 'anon');
    is($t['code'], 200);
});

// =====================================================================================================================
// 7c. Stale To Review reminders
// =====================================================================================================================
ftest('gentle reminders: items To Review with no answer for N days, at most once every N days per item; switch, days and preferences respected', function () {
    clearMail();
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, created_at) VALUES
        (1, 'post', 1, 'submitted', 'admin', 's', NOW() - INTERVAL 4 DAY),
        (1, 'post', 2, 'submitted', 'admin', 's', NOW() - INTERVAL 1 DAY)");
    // off by default
    $c = cron('remind=now');
    is((int)$c['client_emails']['remind'], 0, 'switch off → nothing');
    db()->exec("UPDATE notify_clients SET email_remind = 1, remind_days = 3 WHERE company_id = 1");
    $c = cron('remind=now');
    is((int)$c['client_emails']['remind'], 1);
    $m = mailsTo('jane@kenda.example');
    is(count($m), 1);
    $tok = (string)q1("SELECT email_token FROM notify_threads WHERE entity_type = 'post' AND entity_id = 1");
    is($m[0]['subject'], 'A gentle reminder: Spring launch hero is waiting for your review [J#' . $tok . ']');
    has($m[0]['html'], 'No rush');
    has($m[0]['html'], 'data-email-waiting>sent 4 days ago');
    hasNot($m[0]['html'], 'AT2 carousel', 'only 1 day old — not yet');
    has($m[0]['html'], 'data-email-unsub', 'unsubscribe');
    is(count(mailsTo('ops@kenda.example')), 1, 'every subscribed contact');
    // once every N days
    clearMail();
    cron('remind=now');
    is(count(mails()), 0, 'not again the next day');
    db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL 4 DAY WHERE kind = 'remind'");
    cron('remind=now');
    is(count(mailsTo('jane@kenda.example')), 1, 'again after N days');
    // the contact's preference
    clearMail();
    db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL 4 DAY WHERE kind = 'remind'");
    db()->exec("UPDATE client_contacts SET notify_prefs = '{\"review\":1,\"reply\":1,\"live\":1,\"remind\":0}' WHERE id = 2");
    cron('remind=now');
    is(count(mailsTo('jane@kenda.example')), 1);
    is(count(mailsTo('ops@kenda.example')), 0, 'ops@ turned reminders off');
    // 0 days = off; an answer stops it
    clearMail();
    db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL 4 DAY WHERE kind = 'remind'");
    db()->exec("UPDATE notify_clients SET remind_days = 0 WHERE company_id = 1");
    cron('remind=now');
    is(count(mails()), 0, '0 days → never');
    db()->exec("UPDATE notify_clients SET remind_days = 3 WHERE company_id = 1");
    clientComment(1, 'Looking at it now');
    cron('remind=now');
    is(count(clientMails()), 0, 'the client answered');
    // the preview renders
    $p = get('email-preview.php?type=remind&client=kenda', 'admin');
    is($p['code'], 200);
    has($p['body'], 'gentle reminder');
});

// =====================================================================================================================
// 7d. Quiet hours
// =====================================================================================================================
ftest('escalation quiet hours: none by default; inside the window nothing escalates, after it the steps go out once', function () {
    $m = get('manage.php?section=notifications', 'admin')['body'];
    has($m, 'data-quiet-hours="none"');
    has($m, 'None (around the clock)');
    clientComment(1, 'Anyone there?');
    db()->exec("UPDATE activity_log SET created_at = NOW() - INTERVAL 300 MINUTE WHERE entity_type = 'post' AND entity_id = 1 AND actor = 'client'");
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, internal) VALUES (1, 'post', 4, 'resolved', 'admin', 'x', 1)");
    $h = (int)date('G');
    status(post('notify-admin.php', ['action' => 'settings', 't1' => 60, 't2' => 240, 'summary_hour' => 8, 'quiet_start' => $h, 'quiet_end' => ($h + 2) % 24], 'admin', [], J), 200);
    is((string)q1("SELECT v FROM meta WHERE k = 'notify_quiet_start'"), (string)$h);
    $c = cron();
    is($c['escalated']['quiet'] ?? null, true, 'quiet now');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind LIKE 'escalate%'"), 0, 'nothing sent');
    has(get('manage.php?section=notifications', 'admin')['body'], 'data-quiet-hours="' . $h . '-' . (($h + 2) % 24) . '"');
    // a window that wraps midnight, unit
    is(appJson('notifyMetaSet($pdo, "notify_quiet_start", "22"); notifyMetaSet($pdo, "notify_quiet_end", "7"); echo json_encode([notifyQuietNow($pdo, 23), notifyQuietNow($pdo, 3), notifyQuietNow($pdo, 7), notifyQuietNow($pdo, 12)]);'), [true, true, false, false]);
    // validation
    is(post('notify-admin.php', ['action' => 'settings', 't1' => 60, 't2' => 240, 'summary_hour' => 8, 'quiet_start' => 22, 'quiet_end' => ''], 'admin', [], J)['code'], 422);
    is(post('notify-admin.php', ['action' => 'settings', 't1' => 60, 't2' => 240, 'summary_hour' => 8, 'quiet_start' => 5, 'quiet_end' => 5], 'admin', [], J)['code'], 422);
    // back to none → the escalation goes out
    status(post('notify-admin.php', ['action' => 'settings', 't1' => 60, 't2' => 240, 'summary_hour' => 8, 'quiet_start' => '', 'quiet_end' => ''], 'admin', [], J), 200);
    $c = cron();
    ok(!isset($c['escalated']['quiet']));
    ok((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'escalate_email'") === 1, 'email escalation after the window');
    ok((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'escalate_dm'") === 1, 'DM');
});

// =====================================================================================================================
// 7e. Per-person settings + "Mine"
// =====================================================================================================================
ftest('My notifications: each admin’s own switches (DM, reminder email, summary) are honoured; the Inbox "Mine" filter', function () {
    $p = get('my-notifications.php', 'admin');
    is($p['code'], 200);
    foreach (['dm', 'email', 'summary'] as $k) has($p['body'], 'data-my-pref="' . $k . '"');
    has($p['body'], 'data-my-client="kenda"', 'Lance owns the clients by default');
    is(get('my-notifications.php', 'client:kenda')['code'], 302, 'admin only');
    is(post('notify-admin.php', ['action' => 'my_prefs', 'dm' => 0, 'email' => 0, 'summary' => 0], 'client:kenda', [], J)['code'], 403);
    status(post('notify-admin.php', ['action' => 'my_prefs', 'dm' => 0, 'email' => 0, 'summary' => 1], 'admin', [], J), 200);
    is(json_decode((string)q1("SELECT notify_prefs FROM admin_users WHERE id = 1"), true), ['dm' => 0, 'email' => 0, 'summary' => 1]);
    ok(preg_match('#id="myPref-dm"[^>]*>#', get('my-notifications.php', 'admin')['body'], $mm) === 1 && strpos($mm[0], 'checked') === false, 'DM shows off');
    // escalation honours them: the thread re-ping still happens, no DM, no email
    clientComment(1, 'Hello?');
    db()->exec("UPDATE activity_log SET created_at = NOW() - INTERVAL 300 MINUTE WHERE entity_type = 'post' AND entity_id = 1 AND actor = 'client'");
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, internal) VALUES (1, 'post', 4, 'resolved', 'admin', 'x', 1)");
    cron();
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'escalate_thread'"), 1, 'the channel still hears');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'escalate_dm'"), 0, 'no DM');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'escalate_email'"), 0, 'no email');
    // the Morning summary off
    status(post('notify-admin.php', ['action' => 'my_prefs', 'dm' => 1, 'email' => 1, 'summary' => 0], 'admin', [], J), 200);
    $c = cron('summary=now');
    is($c['summary'], 'off');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'summary'"), 0);
    // Mine: a second teammate owns Privacy Bee
    db()->exec("INSERT INTO admin_users (id, name, email, role) VALUES (2, 'Sam', 'sam@joustmedia.com', 'admin')");
    db()->exec("UPDATE notify_clients SET owner_user_id = 2 WHERE company_id = 2");
    clientComment(1, 'Kenda question');
    status(post('email-status.php', ['id' => 2, 'comment' => 'PB question', 'client' => 'privacybee'], 'client:privacybee', [], J), 200);
    $all = get('inbox.php', 'admin')['body'];
    has($all, 'data-inbox-row="post:1"'); has($all, 'data-inbox-row="email:2"');
    has($all, 'data-inbox-filter="mine"');
    $mine = get('inbox.php?mine=1', 'admin')['body'];
    has($mine, 'data-inbox-who="mine"');
    has($mine, 'data-inbox-row="post:1"', 'Kenda is Lance’s');
    hasNot($mine, 'data-inbox-row="email:2"', 'Privacy Bee is Sam’s');
    has(get('inbox.php?mine=1&tab=client', 'admin')['body'], 'data-inbox-who="mine"');
});

// =====================================================================================================================
// 7f. Client Home: "Joust replied"
// =====================================================================================================================
ftest('client Home: a "Joust replied" card lists items with unread Joust replies; seen → gone; internal notes never', function () {
    hasNot(get('index.php?client=kenda', 'client')['body'], 'data-home-replied', 'nothing yet');
    adminComment(1, 'New render is up — have a look');
    appRun('activityWithContext(["internal" => 1], static function () use ($pdo) { logActivity($pdo, 1, "post", 2, "commented", "admin", "note", "internal only"); });');
    $h = get('index.php?client=kenda', 'client')['body'];
    has($h, 'data-home-replied="1"');
    has($h, 'data-replied-row="post:1"');
    has($h, 'New render is up');
    hasNot($h, 'data-replied-row="post:2"', 'an internal note is not a reply');
    hasNot(get('index.php?client=kenda', 'admin')['body'], 'data-home-replied', 'client seat only');
    hasNot(get('index.php?client=privacybee', 'client:privacybee')['body'], 'data-home-replied', 'another client sees nothing');
    status(post('thread-action.php', ['action' => 'seen', 'entity' => 'post:1', 'client' => 'kenda'], 'client', [], J), 200);
    hasNot(get('index.php?client=kenda', 'client')['body'], 'data-home-replied', 'seen → gone');
});

// =====================================================================================================================
// 7g. No Slack channel → email the owner
// =====================================================================================================================
ftest('no Slack channel: client activity emails the owner at once, at most one per item per 15 minutes; Manage lists the clients', function () {
    $m = get('manage.php?section=notifications', 'admin')['body'];
    has($m, 'data-no-channel-warning="1"');
    has($m, 'Hollow Mill Farm');
    db()->exec("UPDATE notify_clients SET slack_channel_id = NULL WHERE company_id = 1");
    clearMail(); slackReset();
    clientComment(1, 'First note without a channel');
    waitFor("SELECT status FROM notify_outbox WHERE kind = 'nochannel_email'", 'sent');
    $o = rows("SELECT * FROM notify_outbox WHERE kind = 'nochannel_email'");
    is(count($o), 1);
    is($o[0]['status'], 'sent', 'immediately');
    is($o[0]['target'], 'lance@joustmedia.com', 'the owner');
    $x = mailsTo('lance@joustmedia.com');
    is(count($x), 1);
    is($x[0]['subject'], 'Kenda Tires: commented on Spring launch hero');
    has($x[0]['html'], 'Jane Kenda (Kenda Tires)');
    has($x[0]['html'], 'First note without a channel');
    has($x[0]['html'], 'data-nochannel-why');
    is(count(array_filter(slackCalls(), static function ($c) { return ($c['method'] ?? '') === 'chat.postMessage'; })), 0, 'nothing to Slack');
    // within 15 minutes: one deferred email collects the rest
    clientComment(1, 'Second note');
    clientComment(1, 'Third note');
    $o = rows("SELECT * FROM notify_outbox WHERE kind = 'nochannel_email' ORDER BY id");
    is(count($o), 2, 'one more row, not two');
    is($o[1]['status'], 'pending');
    ok(strtotime((string)$o[1]['next_attempt_at']) > time() + 600, 'waits until 15 minutes after the first');
    is(count(json_decode((string)$o[1]['payload'], true)['activity_ids']), 2, 'both notes in it');
    cron();
    is(count(mailsTo('lance@joustmedia.com')), 1, 'not before its time');
    db()->exec("UPDATE notify_outbox SET next_attempt_at = NOW() - INTERVAL 1 MINUTE WHERE id = " . (int)$o[1]['id']);
    cron();
    $x = mailsTo('lance@joustmedia.com');
    is(count($x), 2);
    $two = array_values(array_filter($x, static function ($m) { return strpos($m['subject'], '2 updates') !== false; }));
    is(count($two), 1, 'the second email carries both');
    has($two[0]['html'], 'Third note');
    // another item is independent
    clientComment(2, 'Other item');
    waitFor("SELECT status FROM notify_outbox WHERE kind = 'nochannel_email' AND entity_id = 2", 'sent');
    is((string)q1("SELECT status FROM notify_outbox WHERE kind = 'nochannel_email' AND entity_id = 2"), 'sent');
});

finish();
