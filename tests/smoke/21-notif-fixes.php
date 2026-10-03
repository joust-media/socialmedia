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
 *   round 2 (scratchpad notif-rescore2.md §5): the Authentication-Results parser is quote- and comment-aware (the A-e
 *      quoted-semicolon injection and other tricks), only Google's topmost header counts, dmarc for exactly the From
 *      domain, aligned DKIM required; Lance's own replies via the Gmail SENT label; reminders at most one per client
 *      every N days, ≤ 2 per item, stopped by client activity (+ a 30-day simulation); held client emails older than
 *      72 h expire ("expired, not sent"); staging detected from any "staging" in portal_url / the folder; the
 *      Needs-changes notice and asset deep links mark Joust's replies read
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
function deliver(string $id, string $raw, array $labels = ['INBOX']): void { $b = gbox(); $b['messages'][] = ['id' => $id, 'threadId' => 't' . $id, 'raw' => b64u($raw), 'labelIds' => $labels]; gbox($b); }
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
    // DMARC not evaluated (the domain has no DMARC record), DKIM pass signed by the From domain
    deliver('g2', rawMail(['From' => 'Jane Kenda <jane@kenda.example>', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Thanks, looks great.",
        ["mx.google.com;\r\n       dkim=pass header.i=@kenda.example header.s=k1 header.b=Q1;\r\n       spf=softfail smtp.mailfrom=jane@kenda.example"]));
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
ftest('gentle reminders: one email per client every N days, all stale items in it, ≤ 2 per item, stop once the client is active; switch, days, preferences', function () {
    clearMail();
    db()->exec("UPDATE activity_log SET created_at = created_at - INTERVAL 60 DAY WHERE actor = 'client'");   // the seed's client activity: long ago
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
    // time passes: every timestamp moves back
    $pass = static function (int $days): void {
        db()->exec("UPDATE activity_log SET created_at = created_at - INTERVAL {$days} DAY");
        db()->exec("UPDATE client_email_queue SET created_at = created_at - INTERVAL {$days} DAY, batched_at = batched_at - INTERVAL {$days} DAY");
    };
    // once every N days PER CLIENT: post 2 turning 3 days old two days later does not trigger its own email
    clearMail();
    $pass(2);
    cron('remind=now');
    is(count(mails()), 0, 'not again within N days, even for another stale item');
    // after N days: ONE email covering both stale items
    $pass(1);
    cron('remind=now');
    $m = mailsTo('jane@kenda.example');
    is(count($m), 1, 'one email for the client');
    has($m[0]['html'], 'Spring launch hero');
    has($m[0]['html'], 'AT2 carousel', 'both stale items in the one email');
    // at most 2 per item: post 1 had its second → the next email lists only post 2; the contact's preference
    clearMail();
    $pass(3);
    db()->exec("UPDATE client_contacts SET notify_prefs = '{\"review\":1,\"reply\":1,\"live\":1,\"remind\":0}' WHERE id = 2");
    cron('remind=now');
    $m = mailsTo('jane@kenda.example');
    is(count($m), 1);
    has($m[0]['html'], 'AT2 carousel');
    hasNot($m[0]['html'], 'Spring launch hero', 'post 1 was reminded twice already');
    is(count(mailsTo('ops@kenda.example')), 0, 'ops@ turned reminders off');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'remind' AND entity_type = 'post' AND entity_id = 1"), 2, 'post 1: 2 reminders in total');
    clearMail();
    $pass(3);
    cron('remind=now');
    $pass(10);
    cron('remind=now');
    is(count(mails()), 0, 'both items reminded twice → nothing more, ever');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'remind' AND entity_type = 'post' AND entity_id = 2"), 2);
    // the client is active after the last reminder → nothing sent before that activity is reminded again
    db()->exec("DELETE FROM client_email_queue WHERE kind = 'remind'");
    db()->exec("INSERT INTO client_email_queue (company_id, kind, entity_type, entity_id, created_at, batch_key) VALUES (1, 'remind', 'post', 1, NOW() - INTERVAL 4 DAY, 'remind:old')");
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, created_at) VALUES (1, 'post', 3, 'commented', 'client', 'c', NOW() - INTERVAL 1 DAY)");
    cron('remind=now');
    is(count(mails()), 0, 'a client comment elsewhere since the last reminder: stop');
    db()->exec("DELETE FROM activity_log WHERE entity_type = 'post' AND entity_id = 3 AND actor = 'client' AND summary = 'c'");
    $sess = (int)q1("SELECT COUNT(*) FROM client_sessions");
    db()->exec("INSERT INTO client_sessions (contact_id, company_id, token_hash, last_seen_at, expires_at) VALUES (1, 1, '" . str_repeat('a', 64) . "', NOW() - INTERVAL 2 DAY, NOW() + INTERVAL 20 DAY)");
    cron('remind=now');
    is(count(mails()), 0, 'a signed-in visit since the last reminder: stop');
    db()->exec("DELETE FROM client_sessions WHERE token_hash = '" . str_repeat('a', 64) . "'");
    is((int)q1("SELECT COUNT(*) FROM client_sessions"), $sess);
    cron('remind=now');
    is(count(mailsTo('jane@kenda.example')), 1, 'no activity → it goes');
    // 0 days = off; an answer stops it
    clearMail();
    db()->exec("DELETE FROM client_email_queue WHERE kind = 'remind'");
    db()->exec("UPDATE notify_clients SET remind_days = 0 WHERE company_id = 1");
    cron('remind=now');
    is(count(mails()), 0, '0 days → never');
    db()->exec("UPDATE notify_clients SET remind_days = 3 WHERE company_id = 1");
    clientComment(1, 'Looking at it now');
    clientComment(2, 'And this one');
    cron('remind=now');
    is(count(clientMails()), 0, 'the client answered');
    // the preview renders
    $p = get('email-preview.php?type=remind&client=kenda', 'admin');
    is($p['code'], 200);
    has($p['body'], 'gentle reminder');
});

ftest('gentle reminders: a 30-day simulation with staggered stale items stays bounded (≤ 1 per 3 days, ≤ 2 per item, then silence)', function () {
    db()->exec("UPDATE activity_log SET created_at = created_at - INTERVAL 60 DAY WHERE actor = 'client'");
    db()->exec("UPDATE notify_clients SET email_remind = 1, remind_days = 3 WHERE company_id = 1");
    db()->exec("UPDATE client_contacts SET notify_prefs = '{\"review\":1,\"reply\":1,\"live\":1,\"remind\":0}' WHERE id = 2");   // count Jane's copies only
    $items = [0 => ['post', 1], 1 => ['post', 2], 2 => ['library_image', 7], 7 => ['library_image', 8]];   // sent for review on day 0, 1, 2 and 7
    $days = []; $perItem = [];
    for ($d = 0; $d < 30; $d++) {
        if (isset($items[$d])) db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, created_at) VALUES (1, '{$items[$d][0]}', {$items[$d][1]}, 'submitted', 'admin', 's', NOW())");
        clearMail();
        cron('remind=now');
        $m = mailsTo('jane@kenda.example');
        if ($m) $days[] = $d;
        is(count($m), count($m) ? 1 : 0, 'day ' . $d . ': at most one email');
        // one day passes
        db()->exec("UPDATE activity_log SET created_at = created_at - INTERVAL 1 DAY");
        db()->exec("UPDATE client_email_queue SET created_at = created_at - INTERVAL 1 DAY, batched_at = batched_at - INTERVAL 1 DAY");
    }
    foreach (rows("SELECT entity_type, entity_id, COUNT(*) AS n FROM client_email_queue WHERE kind = 'remind' GROUP BY entity_type, entity_id") as $r) $perItem[$r['entity_type'] . ':' . $r['entity_id']] = (int)$r['n'];
    ok(count($days) >= 2 && count($days) <= 5, 'reminder days bounded: ' . json_encode($days));
    for ($i = 1; $i < count($days); $i++) ok($days[$i] - $days[$i - 1] >= 3, 'at least 3 days apart: ' . json_encode($days));
    foreach ($perItem as $k => $n) ok($n <= 2, $k . ' reminded ' . $n . ' times');
    is(count($perItem), 4, 'every item got reminded: ' . json_encode($perItem));
    ok(max($days) <= 16, 'silence after the caps: ' . json_encode($days));
    // for comparison (audit check C): the old per-item cadence gave 10 of 15 days
    file_put_contents(sys_get_temp_dir() . '/nfix-remind-sim.json', json_encode(['days' => $days, 'per_item' => $perItem]));
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

// =====================================================================================================================
// Round 2 (scratchpad notif-rescore2.md §5): the parser bypass, Lance's own replies (SENT), held-email expiry, staging
// detection, the hidden-post notice + asset deep links marking replies read
// =====================================================================================================================
/** inboundAuthCheck() on one message with the given Authentication-Results values (topmost first). */
function authOk(string $from, array $ar, array $o = []): array {
    $raw = rawMail(['From' => $from, 'Subject' => 'x'], 'x', $ar);
    return appJson('echo json_encode(inboundAuthCheck(notifyMimeParse(' . var_export($raw, true) . '), ' . var_export(strtolower(preg_replace('/^.*<|>.*$/', '', $from)), true) . ', ' . var_export($o, true) . '));');
}
ftest('inbound sender check: quote- and comment-aware parsing; only Google’s topmost header; dmarc for exactly the From domain; aligned DKIM required', function () {
    $L = 'lance@joustmedia.com';
    $inj = '"x;dmarc=pass header.from=joustmedia.com"@evil.example';
    $no = [
        // the audit's A-e: a quoted envelope sender carrying ";dmarc=pass …", Google's real verdict dmarc=fail
        'quoted-semicolon injection (A-e)' => [$L, ["mx.google.com;\r\n       dkim=none;\r\n       spf=softfail (google.com: domain of transitioning {$inj} does not designate 192.0.2.9 as permitted sender) smtp.mailfrom={$inj};\r\n       dmarc=fail (p=NONE sp=NONE dis=NONE) header.from=joustmedia.com"]],
        'injection of dkim=pass + dmarc=pass, no Google dmarc' => [$L, ['mx.google.com; spf=pass smtp.mailfrom="a;dkim=pass header.d=joustmedia.com;dmarc=pass header.from=joustmedia.com"@evil.example']],
        'escaped quote inside the quoted string' => [$L, ['mx.google.com; spf=pass smtp.mailfrom="a\";dkim=pass header.d=joustmedia.com; dmarc=pass header.from=joustmedia.com;"@evil.example; dmarc=fail header.from=joustmedia.com']],
        'two dmarc results (pass + fail)' => [$L, ['mx.google.com; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=joustmedia.com; dmarc=fail header.from=joustmedia.com']],
        'two dmarc results (both pass)' => [$L, ['mx.google.com; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=joustmedia.com; dmarc=pass header.from=joustmedia.com']],
        'dmarc=quarantine' => [$L, ['mx.google.com; dkim=pass header.d=joustmedia.com; dmarc=quarantine header.from=joustmedia.com']],
        'header.from of another domain' => [$L, ['mx.google.com; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=evil.example']],
        'header.from a sub-domain, not the From domain' => [$L, ['mx.google.com; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=mail.joustmedia.com']],
        'header.from given twice' => [$L, ['mx.google.com; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=evil.example header.from=joustmedia.com']],
        'comments that say pass' => [$L, ['mx.google.com; dkim=fail (dkim=pass header.d=joustmedia.com; dmarc=pass) header.d=joustmedia.com; dmarc=fail (dmarc=pass header.from=joustmedia.com) header.from=joustmedia.com']],
        'nested comment hiding a ; and a pass' => [$L, ['mx.google.com; dkim=none (outer (inner; dmarc=pass header.from=joustmedia.com) still; dkim=pass header.d=joustmedia.com); spf=pass smtp.mailfrom=evil.example']],
        'unterminated quoted string' => [$L, ['mx.google.com; spf=pass smtp.mailfrom="x; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=joustmedia.com']],
        'unterminated comment' => [$L, ['mx.google.com; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=joustmedia.com (oops']],
        'dmarc pass via SPF only, no DKIM' => [$L, ['mx.google.com; spf=pass smtp.mailfrom=lance@joustmedia.com; dmarc=pass header.from=joustmedia.com']],
        'DKIM by a look-alike domain' => [$L, ['mx.google.com; dkim=pass header.d=joustmedia.com.evil.example; dmarc=pass header.from=joustmedia.com']],
        'DKIM by evil-joustmedia.com' => [$L, ['mx.google.com; dkim=pass header.i=@evil-joustmedia.com; dmarc=pass header.from=joustmedia.com']],
        'DKIM by a public suffix (co.uk)' => ['x@shop.example.co.uk', ['mx.google.com; dkim=pass header.d=co.uk']],
        'a foreign header on top, Google’s pass below' => [$L, ['relay.example; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=joustmedia.com', 'mx.google.com; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=joustmedia.com']],
        'Google fail on top, forged pass below' => [$L, ['mx.google.com; dkim=none; dmarc=fail header.from=joustmedia.com', 'mx.google.com; dkim=pass header.d=joustmedia.com; dmarc=pass header.from=joustmedia.com']],
        'a contact: dmarc fail, DKIM signed by her domain' => ['jane@kenda.example', ['mx.google.com; dkim=pass header.d=kenda.example; dmarc=fail header.from=kenda.example']],
        'no headers, SENT label but not fetched from the mailbox' => [$L, [], ['labelIds' => ['SENT']]],
        'no headers, fetched, no SENT label' => [$L, [], ['labelIds' => ['INBOX'], 'fetched' => true]],
        'no headers, SENT label, but not a Joust address' => ['jane@kenda.example', [], ['labelIds' => ['SENT', 'INBOX'], 'fetched' => true]],
        'SENT label does not override a failing header' => [$L, ['mx.google.com; dkim=none; dmarc=fail header.from=joustmedia.com'], ['labelIds' => ['SENT'], 'fetched' => true]],
    ];
    foreach ($no as $name => $c) {
        $r = authOk($c[0], $c[1], $c[2] ?? []);
        is($r['ok'], false, 'rejected: ' . $name . ' → ' . $r['reason']);
        ok(strpos((string)$r['reason'], 'Failed sender check') === 0, $name . ': ' . $r['reason']);
    }
    $yes = [
        'Lance through Google' => [$L, ['mx.google.com; dkim=pass header.i=@joustmedia.com header.s=google header.b=Ab1; spf=pass (google.com: domain of "lance"@joustmedia.com designates 192.0.2.1 as permitted sender) smtp.mailfrom="lance"@joustmedia.com; dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=joustmedia.com']],
        'versioned method and quoted values' => [$L, ['mx.google.com 1; dkim/1=pass header.d="joustmedia.com" header.s=google; dmarc=pass header.from="joustmedia.com"']],
        'a contact without a DMARC record' => ['jane@kenda.example', ['mx.google.com; dkim=pass header.i=@kenda.example; spf=pass smtp.mailfrom=kenda.example']],
        'dmarc=bestguesspass' => ['jane@kenda.example', ['mx.google.com; dkim=pass header.d=kenda.example; dmarc=bestguesspass header.from=kenda.example']],
        'a sub-domain From, signed by the organizational domain' => ['jane@mail.kenda.example', ['mx.google.com; dkim=pass header.d=kenda.example; dmarc=pass header.from=mail.kenda.example']],
        'a co.uk organizational domain' => ['x@shop.example.co.uk', ['mx.google.com; dkim=pass header.d=example.co.uk']],
        'one failing signature, one aligned pass' => ['jane@kenda.example', ['mx.google.com; dkim=fail header.d=kenda.example; dkim=pass header.d=kenda.example; dmarc=pass header.from=kenda.example']],
        'Lance’s own reply: no headers, SENT, fetched' => [$L, [], ['labelIds' => ['SENT', 'INBOX'], 'fetched' => true]],
    ];
    foreach ($yes as $name => $c) {
        $r = authOk($c[0], $c[1], $c[2] ?? []);
        is($r['ok'], true, 'accepted: ' . $name . ' → ' . ($r['reason'] ?? ''));
    }
    // the tokenizer itself: a ; inside quotes / comments never splits
    $j = appJson('echo json_encode([count(inboundArSegments("mx.google.com; spf=pass smtp.mailfrom=\"a;b\"@c.example (x;y)")), inboundArSegments("a \"x"), inboundOrgDomain("a.b.example.com"), inboundOrgDomain("x.shop.example.co.uk")]);');
    is($j, [2, null, 'example.com', 'example.co.uk']);
});

ftest('inbound end to end: the A-e injection is parked (no post, no client email); Lance’s own SENT reply posts as Joust', function () {
    connectGoogle();
    $tok = itemToken();
    db()->exec("UPDATE client_email_queue SET batch_key = 'old' WHERE batch_key IS NULL");
    $inj = '"x;dmarc=pass header.from=joustmedia.com"@evil.example';
    deliver('ae1', rawMail(['From' => 'Lance <lance@joustmedia.com>', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Approved, invoice waived.",
        ["mx.google.com;\r\n       spf=softfail (google.com: domain of transitioning {$inj} does not designate 192.0.2.9 as permitted sender) smtp.mailfrom={$inj};\r\n       dmarc=fail (p=NONE sp=NONE dis=NONE) header.from=joustmedia.com"]));
    // Lance replying from his own Gmail: delivered internally, no Authentication-Results, Gmail labels it SENT
    deliver('own1', rawMail(['From' => 'Lance <lance@joustmedia.com>', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Sent from my own Gmail — new render tonight.", []), ['SENT', 'INBOX']);
    // the same without SENT (an outside message that lost its headers) → parked
    deliver('own2', rawMail(['From' => 'Lance <lance@joustmedia.com>', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "No SENT label here.", []), ['INBOX']);
    // a contact can't use the SENT path
    deliver('own3', rawMail(['From' => 'jane@kenda.example', 'Subject' => 'Re: Spring launch hero [J#' . $tok . ']'], "Jane with a SENT label.", []), ['SENT', 'INBOX']);
    $c = cron();
    is((int)$c['inbound']['posted'], 1, 'only Lance’s own reply');
    is((int)$c['inbound']['unmatched'], 3);
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE detail LIKE '%invoice waived%'"), 0, 'A-e never posted');
    foreach (['ae1', 'own2', 'own3'] as $g) ok(strpos((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = ?", [$g]), 'Failed sender check') === 0, $g);
    has((string)q1("SELECT reason FROM email_inbound WHERE gmail_id = 'ae1'"), 'dmarc=fail');
    $a = commentsOn(1, 'admin');
    is(end($a)['detail'], 'Sent from my own Gmail — new render tonight.');
    is((int)end($a)['author_user_id'], 1, 'as Lance');
    is((string)q1("SELECT status FROM email_inbound WHERE gmail_id = 'own1'"), 'posted');
    $q = rows("SELECT * FROM client_email_queue WHERE batch_key IS NULL");
    is(count($q), 1, 'only the genuine reply is queued for the client');
    is((int)$q[0]['activity_id'], (int)end($a)['id']);
    // the stub honours labelIds= on list (what a SENT-only query would see)
    $r = appJson('echo json_encode(gmailApi($pdo, "GET", "messages", null, ["labelIds" => "SENT"])["data"]["messages"] ?? []);');
    is(array_column($r, 'id'), ['own1', 'own3']);
});

ftest('held client emails older than 72 h are dropped on release: "expired, not sent" in the Delivery log; fresh ones go; configurable', function () {
    adminComment(1, 'OLD reply from three weeks ago');
    db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL 21 DAY WHERE batch_key IS NULL");
    adminComment(2, 'FRESH reply from today');
    db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL 11 MINUTE WHERE batch_key IS NULL AND entity_id = 2");
    status(post('status.php', ['action' => 'submit', 'id' => 6, 'client' => 'kenda'], 'admin', [], J), 200);
    db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL 80 HOUR WHERE batch_key IS NULL AND kind = 'review'");
    // held while there is no transport
    $cfg = ['mail_transport' => '', 'mail_sink_dir' => null];
    $j = appJson('echo json_encode(clientEmailRun($pdo));', $cfg, ['sendmail_path' => '/bin/true']);
    is((int)$j['held'], 3, 'held, nothing dropped yet');
    // released (the test transport): the 21-day reply and the 80-hour review expire, today's reply goes
    clearMail();
    $c = cron();
    is((int)$c['client_emails']['expired'], 2, 'two batches expired');
    is((int)$c['client_emails']['reply'], 1);
    $jane = mailsTo('jane@kenda.example');
    is(count($jane), 1, 'only the fresh email');
    has($jane[0]['html'], 'FRESH reply from today');
    hasNot(json_encode(mails()), 'OLD reply from three weeks ago');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE batch_key LIKE 'expired:%'"), 2);
    $log = rows("SELECT * FROM notify_outbox WHERE kind = 'client_email' AND status = 'skipped' ORDER BY id");
    is(count($log), 2, 'one Delivery-log row per expired batch');
    has((string)$log[0]['last_error'], 'expired, not sent');
    has((string)$log[0]['last_error'], 'held longer than 72 h');
    has(get('manage.php?section=notifications', 'admin')['body'], 'expired, not sent');
    // a retry of an expired row never sends it
    db()->exec("UPDATE notify_outbox SET status = 'pending', next_attempt_at = NOW() WHERE id = " . (int)$log[0]['id']);
    appRun('notifyPump($pdo, ["ids" => [' . (int)$log[0]['id'] . ']]);');
    is((string)q1("SELECT status FROM notify_outbox WHERE id = ?", [(int)$log[0]['id']]), 'skipped');
    is(count(mailsTo('jane@kenda.example')), 1, 'still one');
    // an outbox email already enqueued but held past the limit is dropped at delivery too
    db()->exec("INSERT INTO client_email_queue (company_id, kind, entity_type, entity_id, created_at, batch_key) VALUES (1, 'review', 'post', 1, NOW() - INTERVAL 5 DAY, 'review:1:held')");
    db()->exec("INSERT INTO notify_outbox (channel, kind, company_id, target, payload, dedupe_key) VALUES ('email', 'client_email', 1, 'jane@kenda.example', '{\"batch_key\":\"review:1:held\",\"kind\":\"review\",\"company_id\":1,\"contact_id\":1}', 'held-old')");
    db()->exec("UPDATE notify_clients SET email_review = 1 WHERE company_id = 1");
    $oid = (int)q1("SELECT id FROM notify_outbox WHERE dedupe_key = 'held-old'");
    appRun('notifyPump($pdo, ["ids" => [' . $oid . ']]);');
    $row = rows("SELECT status, last_error FROM notify_outbox WHERE id = ?", [$oid])[0];
    is($row['status'], 'skipped');
    has((string)$row['last_error'], 'expired, not sent');
    // configurable
    is(appJson('echo json_encode([clientEmailMaxAgeHours()]);'), [72]);
    is(appJson('echo json_encode([clientEmailMaxAgeHours()]);', ['client_email_max_age_hours' => 24]), [24]);
    is(appJson('echo json_encode([clientEmailMaxAgeHours()]);', ['client_email_max_age_hours' => 99999]), [720]);
});

ftest('staging detection: any portal_url or folder containing "staging", or the explicit environment; Manage shows it and how', function () {
    $q = static function (string $dir, array $cfg = []) { return appJson('echo json_encode(portalEnvironmentInfo(' . var_export($dir, true) . '));', $cfg); };
    is($q('/home/joust/public_html/portal'), ['env' => 'production', 'source' => 'default']);
    is($q('/home/joust/public_html/portal-staging'), ['env' => 'staging', 'source' => 'folder']);
    is($q('/home/joust/public_html/staging-portal2'), ['env' => 'staging', 'source' => 'folder']);
    is($q('/home/joust/public_html/Portal_STAGING'), ['env' => 'staging', 'source' => 'folder']);
    is($q('/x/portal', ['portal_url' => 'https://staging.joustmedia.com/portal']), ['env' => 'staging', 'source' => 'portal_url']);
    is($q('/x/portal', ['portal_url' => 'https://joustmedia.com/portal-staging2']), ['env' => 'staging', 'source' => 'portal_url']);
    is($q('/x/portal-staging', ['environment' => 'production']), ['env' => 'production', 'source' => 'config']);
    is($q('/x/portal', ['environment' => 'Staging']), ['env' => 'staging', 'source' => 'config']);
    is(appJson('echo json_encode([portalEnvironment(), inboundAddress()]);', ['portal_url' => 'https://joustmedia.com/my-staging']), ['staging', 'lance+ai-staging@joustmedia.com']);
    $m = get('manage.php?section=notifications', 'admin')['body'];
    has($m, 'data-environment="production" data-environment-source="default"');
    has($m, 'data-environment-label>Production');
    $orig = siteConfig(['portal_url' => (getenv('PORTAL_TEST_BASE') ?: 'http://127.0.0.1:8099/portal') . '?staging=1']);
    try {
        $m = get('manage.php?section=notifications', 'admin')['body'];
        has($m, 'data-environment="staging" data-environment-source="portal_url"');
        has($m, 'data-environment-label>Staging');
        has($m, 'portal_url</code> contains “staging”');
    } finally {
        siteConfigRestore($orig);
    }
});

ftest('Joust replies are marked read from every view the client reaches: the Needs-changes notice, a deep-linked asset, an opened series', function () {
    // post 4 is Needs changes (the client's own view = the "Joust is updating this post" notice)
    $b = get('posts.php?client=kenda&post=4&partial=1', 'client')['body'];
    has($b, 'data-hidden-post');
    has($b, 'data-seen-entity="post:4"');
    is(get('posts.php?client=kenda&post=4&partial=1', 'admin')['code'], 200, 'the admin still gets the full sheet');
    // assets: the deep-linked image (library / tire) and an explicitly opened series carry an on-load marker
    has(get('assets.php?client=kenda&asset=7&kind=library', 'client')['body'], '<span hidden data-seen-on-load data-seen-entity="library_image:7"></span>');
    $tid = (int)q1("SELECT id FROM tire_images WHERE series_id = 1 AND status = 'pending' ORDER BY id LIMIT 1");
    $b = get('assets.php?client=kenda&view=collections&item=1&series=1&asset=' . $tid . '&kind=tire', 'client')['body'];
    has($b, 'data-seen-entity="tire_image:' . $tid . '"');
    hasNot($b, 'data-seen-entity="tire_series:1"', 'an image link does not mark its whole series');
    has(get('assets.php?client=kenda&view=collections&item=1&series=1', 'client')['body'], 'data-seen-entity="tire_series:1"');
    hasNot(get('assets.php?client=kenda&view=collections&item=1', 'client')['body'], 'data-seen-on-load', 'no marker without a link to an item');
    // the marker posts to the same endpoint (a client may mark its own series, not another client's)
    status(post('thread-action.php', ['action' => 'seen', 'entity' => 'tire_series:1', 'client' => 'kenda'], 'client', [], J), 200);
    is(post('thread-action.php', ['action' => 'seen', 'entity' => 'tire_series:1', 'client' => 'privacybee'], 'client:privacybee', [], J)['code'], 403);
});

// =====================================================================================================================
// Round 3 (scratchpad notif-rescore2.md criterion 8 / category F): client tab badges count unread Joust replies;
// teammates get their own Morning summary + weekly report; one combined escalation after quiet hours
// =====================================================================================================================
/** A client tab's badge → ['n' => shown number, 'aria' => label, 'review' => data-badge-review, 'replies' => keys]. */
function tabBadge(string $html, string $tab): array {
    if (!preg_match('#<a class="ui-tab ui-tab--' . $tab . '[^"]*"[^>]*>.*?</a>#s', $html, $m)) fail('no ' . $tab . ' tab');
    $a = $m[0];
    $attr = static function (string $name) use ($a): ?string { return preg_match('#' . $name . '="([^"]*)"#', $a, $x) ? html_entity_decode($x[1]) : null; };
    $n = preg_match('#<span class="ui-badge ui-tab-badge"[^>]*>(\d+)</span>#', $a, $x) ? (int)$x[1] : 0;
    return ['n' => $n, 'aria' => $attr('aria-label'), 'review' => $attr('data-badge-review'), 'replies' => $attr('data-badge-replies')];
}
function joustReply(string $type, int $id, int $cid, string $text, int $internal = 0): void {
    db()->prepare("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, author_user_id, summary, detail, internal) VALUES (?, ?, ?, 'commented', 'admin', 1, 'Comment', ?, ?)")
        ->execute([$cid, $type, $id, $text, $internal]);
}

ftest('client tab badges: To Review + unread Joust replies, never counted twice; seen → gone; admin badges unchanged', function () {
    $h = get('posts.php?client=kenda', 'client')['body'];
    $p = tabBadge($h, 'posts');
    is($p['n'], 2, 'posts 1 + 2 to review');
    is($p['aria'], '2 to review');
    is($p['review'], '2'); is($p['replies'], '');
    $deniedTire = (int)q1("SELECT ti.id FROM tire_images ti JOIN tires t ON t.id = ti.tire_id WHERE t.company_id = 1 AND ti.status = 'denied' ORDER BY ti.id LIMIT 1");
    $tiresBefore = tabBadge($h, 'tires')['n'];
    $assetsBefore = tabBadge($h, 'assets')['n'];
    is($assetsBefore, 2, 'library 7 + 8');
    joustReply('post', 4, 1, 'Darker render tomorrow.');            // Needs changes → +1
    joustReply('post', 1, 1, 'Have a look at the new crop');        // To Review already → no change
    joustReply('post', 2, 1, 'internal only', 1);                    // an internal note → never
    joustReply('library_image', 1, 1, 'Swapped the background.');   // approved library image → Assets +1
    joustReply('library_image', 7, 1, 'Pending one, cropped.');      // To Review already
    joustReply('tire_image', $deniedTire, 1, 'Re-rendered.');        // denied render → Tires +1
    joustReply('tire_series', 1, 1, 'Two new angles.');              // series with pending renders → already counted
    joustReply('post', 8, 2, 'Privacy Bee only');                     // another client
    $h = get('posts.php?client=kenda', 'client')['body'];
    $p = tabBadge($h, 'posts');
    is($p['n'], 3);
    is($p['aria'], '2 to review, 1 new reply');
    is($p['review'], '2'); is($p['replies'], 'post:4');
    $a = tabBadge($h, 'assets');
    is($a['n'], $assetsBefore + 1); is($a['replies'], 'library_image:1'); is($a['aria'], '2 to review, 1 new reply');
    $t = tabBadge($h, 'tires');
    is($t['n'], $tiresBefore + 1); is($t['replies'], 'tire_image:' . $deniedTire);
    // a tab with only replies: "1 new reply"
    db()->exec("UPDATE posts SET status = 'approved' WHERE id IN (1, 2)");
    $p = tabBadge(get('posts.php?client=kenda', 'client')['body'], 'posts');
    is([$p['n'], $p['aria']], [2, '2 new replies'], 'post 1 left To Review → its unread reply now counts');
    db()->exec("UPDATE posts SET status = 'pending' WHERE id IN (1, 2)");
    // seen → gone
    status(post('thread-action.php', ['action' => 'seen', 'entity' => 'post:4', 'client' => 'kenda'], 'client', [], J), 200);
    $p = tabBadge(get('posts.php?client=kenda', 'client')['body'], 'posts');
    is([$p['n'], $p['aria'], $p['replies']], [2, '2 to review', '']);
    // another client's badge never sees Kenda's replies
    $pb = tabBadge(get('posts.php?client=privacybee', 'client:privacybee')['body'], 'posts');
    is([$pb['n'], $pb['aria'], $pb['replies']], [1, '1 to review', ''], 'post 8 is To Review already: counted once');
    // admin: unchanged — Needs changes, no reply part, no data attributes
    $ad = tabBadge(get('posts.php?client=kenda', 'admin')['body'], 'posts');
    is([$ad['n'], $ad['aria'], $ad['review'], $ad['replies']], [1, '1 need changes', null, null]);
    // the live helpers ship
    $js = (string)file_get_contents(dirname(__DIR__, 2) . '/static/js/app.js');
    has($js, 'App.tabBadgeSeen = function');
    has((string)file_get_contents(dirname(__DIR__, 2) . '/static/js/tracking.js'), 'App.tabBadgeSeen(key)');
});

ftest('Morning summary + weekly report for teammates: every active one with the switch on, scoped to "Mine", one per person per day; Lance still gets everything', function () {
    db()->exec("INSERT INTO admin_users (id, name, email, role, active) VALUES (2, 'Sam', 'sam@joustmedia.com', 'admin', 1), (3, 'Ana', 'ana@joustmedia.com', 'admin', 1),
                (4, 'Bob', 'bob@joustmedia.com', 'admin', 1), (5, 'Old', 'old@joustmedia.com', 'admin', 0)");
    db()->exec("UPDATE admin_users SET notify_prefs = '{\"summary\":0}' WHERE id = 4");
    db()->exec("UPDATE notify_clients SET owner_user_id = 2 WHERE company_id = 2");   // Sam owns Privacy Bee; Ana owns nothing
    clearMail();
    clientComment(1, 'Kenda morning note');
    status(post('email-status.php', ['id' => 2, 'comment' => 'PB morning note', 'client' => 'privacybee'], 'client:privacybee', [], J), 200);
    appRun('activityWithContext(["internal" => 1], static function () use ($pdo) { logActivity($pdo, 2, "email", 2, "commented", "admin", "note", "SECRET internal"); });');
    $c = cron('summary=now');
    is($c['summary'], 'sent', 'Lance');
    is($c['summary_members']['queued'] ?? null, 2, json_encode($c['summary_members'] ?? null));
    $lance = mailsTo('lance@joustmedia.com');
    $lanceSum = array_values(array_filter($lance, static function ($m) { return strpos((string)$m['subject'], 'Morning summary') === 0; }));
    is(count($lanceSum), 1);
    has($lanceSum[0]['html'], 'Kenda morning note'); has($lanceSum[0]['html'], 'PB morning note');
    $sam = mailsTo('sam@joustmedia.com');
    is(count($sam), 1, 'Sam: one');
    has($sam[0]['subject'], 'from your clients');
    has($sam[0]['html'], 'data-summary-scope="mine"'); has($sam[0]['html'], 'Your clients: Privacy Bee');
    has($sam[0]['html'], 'PB morning note');
    hasNot($sam[0]['html'], 'Kenda morning note', 'not Sam’s client');
    hasNot($sam[0]['html'], 'SECRET', 'internal notes never');
    $ana = mailsTo('ana@joustmedia.com');
    is(count($ana), 1, 'Ana (owns none): every client');
    has($ana[0]['html'], 'Kenda morning note'); has($ana[0]['html'], 'PB morning note');
    hasNot($ana[0]['html'], 'data-summary-scope="mine"');
    is(count(mailsTo('bob@joustmedia.com')), 0, 'switch off');
    is(count(mailsTo('old@joustmedia.com')), 0, 'inactive');
    is((string)q1("SELECT status FROM notify_outbox WHERE dedupe_key = ?", ['summary:u2:' . date('Y-m-d')]), 'sent');
    is((string)q1("SELECT kind FROM notify_outbox WHERE dedupe_key = ?", ['summary:u3:' . date('Y-m-d')]), 'summary_member');
    ok((int)q1("SELECT v FROM meta WHERE k = 'summary_member_last_2'") > 0, 'Sam’s watermark moved');
    // once per person per day
    clientComment(1, 'Another one');
    $c = cron('summary=now');
    is($c['summary_members']['queued'] ?? null, 0, 'deduped for today');
    is(count(mailsTo('sam@joustmedia.com')), 1);
    has(get('manage.php?section=notifications', 'admin')['body'], 'Morning summary (teammate)');
    // the weekly report: Lance (all), Sam (Privacy Bee only), Ana (all) — Bob off, Old inactive
    clearMail();
    $c = cron('weekly=now');
    is($c['weekly'], 'queued');
    cron();
    is(count(array_filter(mailsTo('lance@joustmedia.com'), static function ($m) { return strpos((string)$m['subject'], 'Weekly report') === 0; })), 1);
    $sw = mailsTo('sam@joustmedia.com');
    is(count($sw), 1);
    has($sw[0]['html'], 'data-weekly-scope="mine"'); has($sw[0]['html'], 'data-weekly-client="2"');
    hasNot($sw[0]['html'], 'data-weekly-client="1"', 'Kenda is not Sam’s');
    $aw = mailsTo('ana@joustmedia.com');
    is(count($aw), 1);
    has($aw[0]['html'], 'data-weekly-client="1"'); has($aw[0]['html'], 'data-weekly-client="2"');
    is(count(mailsTo('bob@joustmedia.com')) + count(mailsTo('old@joustmedia.com')), 0);
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE dedupe_key IN (?, ?)", ['weekly:u2:' . date('Y-m-d'), 'weekly:u3:' . date('Y-m-d')]), 2);
    cron('weekly=now');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'weekly'"), 3, 'one per person per day');
});

ftest('quiet hours catch-up: an item that came due while it was quiet gets ONE combined reminder; the other steps are skipped, never sent later', function () {
    $h = (int)date('G');
    // the window ended at the top of this hour: [h-3, h)
    status(post('notify-admin.php', ['action' => 'settings', 't1' => 60, 't2' => 240, 'summary_hour' => 8, 'quiet_start' => ($h + 21) % 24, 'quiet_end' => $h], 'admin', [], J), 200);
    clientComment(1, 'Waiting all night');
    db()->exec("UPDATE activity_log SET created_at = NOW() - INTERVAL 300 MINUTE WHERE entity_type = 'post' AND entity_id = 1 AND actor = 'client'");
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, internal) VALUES (1, 'post', 4, 'resolved', 'admin', 'x', 1)");
    clearMail(); slackReset();
    $c = cron();
    ok(!isset($c['escalated']['quiet']), 'outside the window now');
    is($c['escalated']['combined'] ?? null, 1, json_encode($c['escalated']));
    $live = rows("SELECT kind, status, payload FROM notify_outbox WHERE kind LIKE 'escalate%' AND status <> 'skipped'");
    is(count($live), 1, 'one message');
    is($live[0]['kind'], 'escalate_email', 'the highest step due');
    is($live[0]['status'], 'sent');
    $p = json_decode($live[0]['payload'], true);
    is($p['combined'], ['escalate_email', 'escalate_dm', 'escalate_thread']);
    $skipped = rows("SELECT kind, last_error FROM notify_outbox WHERE kind LIKE 'escalate%' AND status = 'skipped' ORDER BY kind");
    is(array_column($skipped, 'kind'), ['escalate_dm', 'escalate_thread']);
    foreach ($skipped as $r) has((string)$r['last_error'], 'combined into #');
    $m = array_values(array_filter(mailsTo('lance@joustmedia.com'), static function ($x) { return strpos((string)$x['subject'], 'Waiting') === 0; }));
    is(count($m), 1);
    has($m[0]['subject'], 'Waiting 5h');
    has($m[0]['html'], 'data-escalation-held'); has($m[0]['text'], 'Held during quiet hours');
    $posts = array_filter(slackCalls(), static function ($x) { return ($x['method'] ?? '') === 'chat.postMessage' && strpos(json_encode($x['body'] ?? ''), 'alarm_clock') !== false; });
    is(count($posts), 0, 'no Slack re-ping / DM on top');
    // later runs send nothing more for that message
    cron();
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind LIKE 'escalate%' AND status <> 'skipped'"), 1);
    // control: an item that came due AFTER the window → the ordinary steps (re-ping + DM), not combined
    status(post('notify-admin.php', ['action' => 'settings', 't1' => 60, 't2' => 240, 'summary_hour' => 8, 'quiet_start' => ($h + 18) % 24, 'quiet_end' => ($h + 21) % 24], 'admin', [], J), 200);
    status(post('email-status.php', ['id' => 2, 'comment' => 'PB waiting', 'client' => 'privacybee'], 'client:privacybee', [], J), 200);
    db()->exec("UPDATE activity_log SET created_at = NOW() - INTERVAL 130 MINUTE WHERE entity_type = 'email' AND entity_id = 2 AND actor = 'client'");
    $c = cron();
    ok(!isset($c['escalated']['combined']), 'nothing combined');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE entity_type = 'email' AND entity_id = 2 AND kind = 'escalate_thread' AND status <> 'skipped'"), 1);
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE entity_type = 'email' AND entity_id = 2 AND kind = 'escalate_dm' AND status <> 'skipped'"), 1);
    // unit: when the window last ended
    is(appJson('notifyMetaSet($pdo, "notify_quiet_start", "22"); notifyMetaSet($pdo, "notify_quiet_end", "7"); $t = mktime(9, 30, 0, 3, 10, 2026); $u = mktime(6, 0, 0, 3, 10, 2026);'
             . ' echo json_encode([date("Y-m-d H:i", notifyQuietLastEnd($pdo, $t)), date("Y-m-d H:i", notifyQuietLastEnd($pdo, $u))]);'), ['2026-03-10 07:00', '2026-03-09 07:00']);
});

finish();
