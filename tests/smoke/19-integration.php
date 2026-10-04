<?php
/**
 * Notifications × client sign-in, integrated (wt/notify + wt/auth):
 *   - ONE email function (notify-lib.php notifyEmail(array $msg)); the auth-mail.php shim is gone; sign-in links go
 *     through the outbox with an immediate attempt, are retried by the cron while valid, and never stay in the row
 *   - config keys: one canonical name each, the other accepted as an alias (url-lib.php portalConfigAliases())
 *   - links: notify's absolute links follow clean links; a client recipient gets clientLink(), Joust the admin URL
 *   - the clean-link router never captures slack-events, slack-actions, notify-cron or notify-thumb
 *   - authorship: a client comment records the signed-in contact (activity_log.client_contact_id, migrate.php 44)
 *     and Slack / the admin views name them ("Jane Kenda (Kenda Tires)")
 */
require __DIR__ . '/lib.php';

const J     = ['Accept' => 'application/json'];
const CRON  = 'test-cron-token-0123456789abcdef';

$APP = rtrim((string)(getenv('APP_DIR') ?: '/tmp/portal-test/site/portal'), '/');
$ROOT = rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test'), '/');
$MAIL = rtrim((string)(getenv('MAIL_SINK_DIR') ?: $ROOT . '/mail'), '/');
$REPO = dirname(__DIR__, 2);

db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");

function reseedNow(): void {
    global $APP;
    $media = getenv('MEDIA_DIR') ?: dirname($APP) . '/media';
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($APP) . ' ' . escapeshellarg($media) . ' 2>&1', $out, $rc);
    if ($rc !== 0) throw new RuntimeException('seed failed: ' . implode("\n", $out));
}
function itest(string $name, callable $fn): void { test($name, static function () use ($fn) { reseedNow(); $fn(); }); }
function clearMail(): void { global $MAIL; foreach (glob($MAIL . '/*') ?: [] as $f) @unlink($f); }
function mails(): array { global $MAIL; $o = []; foreach (glob($MAIL . '/*.json') ?: [] as $f) $o[] = json_decode((string)file_get_contents($f), true); return $o; }
function slackCalls(): array {
    global $ROOT;
    $f = $ROOT . '/slack-calls.jsonl';
    return is_file($f) ? array_values(array_filter(array_map(static function ($l) { return json_decode($l, true); }, file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))) : [];
}
function slackReset(): void { global $ROOT; @unlink($ROOT . '/slack-calls.jsonl'); @unlink($ROOT . '/slack-calls.jsonl.fail'); }
/** Run PHP inside the test app with the whole helpers chain loaded, as a session-free machine script. */
function appRun(string $code, string $script = 'notify-cron.php'): string {
    global $APP;
    $file = sys_get_temp_dir() . '/int_smoke_' . bin2hex(random_bytes(4)) . '.php';
    $base = getenv('PORTAL_TEST_BASE') ?: 'http://127.0.0.1:8099/portal';
    file_put_contents($file, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/portal/{$script}'; \$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "\$_SERVER['HTTP_HOST'] = " . var_export(parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT), true) . ";\n"
        . "chdir(" . var_export($APP, true) . ");\nrequire 'db.php'; require_once 'helpers.php';\n" . $code . "\n");
    $out = (string)shell_exec('php -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return $out;
}
/** route.php as Apache would reach it for $uri (CLI): "<http code>|<first 60 chars of output>". */
function routeProbe(string $uri, string $method = 'GET'): string {
    global $APP;
    $file = sys_get_temp_dir() . '/int_route_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/portal/route.php'; \$_SERVER['REQUEST_URI'] = " . var_export($uri, true) . ";\n"
        . "\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . "; \$_SERVER['HTTP_HOST'] = '127.0.0.1';\n"
        . "chdir(" . var_export($APP, true) . ");\nob_start();\n"
        . "register_shutdown_function(static function () { \$b = (string)ob_get_clean(); echo (int)http_response_code(), '|', substr(strip_tags(\$b), 0, 60); });\n"
        . "require 'route.php';\n");
    $out = (string)shell_exec('php ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return trim($out);
}
function installCleanLinks(): void {
    $r = post('client-admin.php', ['action' => 'clean_links_install'], 'admin', [], J);
    is($r['code'], 200, 'clean links installed: ' . ($r['json']['error'] ?? ''));
}
function removeCleanLinks(): void { post('client-admin.php', ['action' => 'clean_links_remove'], 'admin', [], J); }

// ---- one email function ----------------------------------------------------------------------------------------------
itest('notifyEmail: ONE definition, the array form; the auth-mail.php shim is gone', function () use ($REPO) {
    ok(!is_file($REPO . '/auth-mail.php'), 'auth-mail.php deleted');
    $defs = trim((string)shell_exec('cd ' . escapeshellarg($REPO) . " && git grep -n 'function notifyEmail(' -- '*.php' | grep -v '^tests/'"));
    is(count(array_filter(explode("\n", $defs))), 1, 'exactly one definition: ' . $defs);
    has($defs, 'notify-lib.php');
    is(trim(appRun('$f = new ReflectionFunction("notifyEmail"); echo $f->getNumberOfParameters(), ":", $f->getParameters()[0]->getType();')), '1:array');
    $calls = (string)shell_exec('cd ' . escapeshellarg($REPO) . " && git grep -n 'notifyEmail(' -- '*.php' ':!tests'");
    foreach (array_filter(explode("\n", $calls)) as $line) {
        if (strpos($line, 'function notifyEmail(') !== false || preg_match('/notifyEmail\(\)/', $line)) continue;
        ok(preg_match('/notifyEmail\((\[|\$\w+|array)/', $line) === 1, 'array-form call: ' . $line);
    }
});

itest('sign-in email: through the outbox, sent at once, From "Joust Media" <lance@joustmedia.com>, link scrubbed from the row', function () {
    clearMail();
    status(post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon'), 200);
    $m = mails();
    is(count($m), 1, 'one email');
    is($m[0]['from'], '"Joust Media" <lance@joustmedia.com>');
    has(implode("\n", $m[0]['header_lines']), 'From: "Joust Media" <lance@joustmedia.com>', 'header line');
    has(implode("\n", $m[0]['header_lines']), 'Reply-To: lance@joustmedia.com');
    ok(preg_match('#^http://127\.0\.0\.1:\d+/portal/sign-in\.php\?t=[A-Za-z0-9_-]{40,}#m', (string)$m[0]['text']) === 1, 'absolute sign-in link on portal_url');
    $o = rows("SELECT * FROM notify_outbox WHERE kind = 'sign_in'");
    is(count($o), 1);
    is([$o[0]['channel'], $o[0]['status'], (int)$o[0]['attempts']], ['email', 'sent', 1]);
    hasNot($o[0]['payload'], 't=', 'token gone from the outbox');
});

itest('sign-in email: a failed first attempt stays queued (bodies kept) and the cron delivers it; an expired one is dropped', function () use ($MAIL) {
    clearMail();
    touch($MAIL . '/FAIL');
    status(post('sign-in.php', ['action' => 'request', 'email' => 'jane@kenda.example'], 'anon'), 200);
    @unlink($MAIL . '/FAIL');
    $o = rows("SELECT * FROM notify_outbox WHERE kind = 'sign_in'")[0];
    is($o['status'], 'pending', 'queued for retry');
    has((string)$o['last_error'], 'forced failure');
    has($o['payload'], 'sign-in', 'bodies kept while it may still be sent');
    db()->exec("UPDATE notify_outbox SET next_attempt_at = NOW() WHERE id = " . (int)$o['id']);
    status(get('notify-cron.php', 'anon', ['X-Notify-Token' => CRON]), 200, 'cron');
    $o = rows("SELECT * FROM notify_outbox WHERE id = ?", [(int)$o['id']])[0];
    is($o['status'], 'sent', 'the cron delivered it');
    is(count(mails()), 1);
    ok(!empty(json_decode($o['payload'], true)['redacted']), 'scrubbed after delivery');
    // past its expiry (the link is dead after 15 minutes): skipped, never sent, scrubbed
    clearMail();
    touch($MAIL . '/FAIL');
    post('sign-in.php', ['action' => 'request', 'email' => 'pat@privacybee.example'], 'anon');
    @unlink($MAIL . '/FAIL');
    $o = rows("SELECT * FROM notify_outbox WHERE kind = 'sign_in' ORDER BY id DESC LIMIT 1")[0];
    $p = json_decode($o['payload'], true);
    $p['expires'] = time() - 5;
    db()->prepare("UPDATE notify_outbox SET payload = ?, next_attempt_at = NOW() WHERE id = ?")->execute([json_encode($p), (int)$o['id']]);
    status(get('notify-cron.php', 'anon', ['X-Notify-Token' => CRON]), 200);
    $o = rows("SELECT * FROM notify_outbox WHERE id = ?", [(int)$o['id']])[0];
    is($o['status'], 'skipped');
    is(count(mails()), 0, 'nothing sent');
    hasNot($o['payload'], 't=', 'scrubbed');
    // Retry from Manage → Notifications on a handled row sends nothing (bodies are gone)
    status(post('notify-admin.php', ['action' => 'retry', 'id' => (int)$o['id']], 'admin', [], J), 200);
    status(get('notify-cron.php', 'anon', ['X-Notify-Token' => CRON]), 200);
    is(count(mails()), 0, 'a scrubbed row is never re-sent');
});

// ---- config keys -----------------------------------------------------------------------------------------------------
itest('config: one canonical key each, the other name accepted as an alias (both directions; canonical wins; blank = unset)', function () {
    $out = appRun('
        $c = ["portal_base_url" => "https://a.example/p", "mail_capture_dir" => "/tmp/x", "auth_mail_from" => "x@y.example",
              "auth_mail_reply_to" => "r@y.example", "auth_mail_from_name" => "N", "auth_mail_envelope" => "e@y.example"];
        $o = [];
        foreach (["portal_url", "mail_sink_dir", "notify_from", "notify_reply_to", "notify_from_name", "notify_envelope"] as $k) $o[] = portalConfigPick($c, $k);
        $o[] = portalConfigPick(["portal_url" => "https://canon.example", "portal_base_url" => "https://alias.example"], "portal_base_url");
        $o[] = portalConfigPick(["portal_url" => "", "portal_base_url" => "https://alias.example"], "portal_url");
        $o[] = var_export(portalConfigPick(["portal_url" => ""], "portal_url"), true);
        $o[] = portalConfigPick(["clean_urls" => true], "clean_urls") === true ? "bool-ok" : "bool-bad";
        // the app reads its own config.php through both names (it holds the canonical ones)
        $o[] = notifyCfg("portal_base_url") === portalConfig("portal_url") && notifyCfg("portal_url") !== "" ? "app-ok" : "app-bad";
        $o[] = notifyCfg("mail_capture_dir") === notifyCfg("mail_sink_dir") && notifyCfg("mail_sink_dir") !== "" ? "sink-ok" : "sink-bad";
        $o[] = portalConfig("auth_mail_from") . "|" . portalConfig("auth_mail_from_name");
        echo implode("\n", $o);');
    is(explode("\n", trim($out)), ['https://a.example/p', '/tmp/x', 'x@y.example', 'r@y.example', 'N', 'e@y.example',
        'https://canon.example', 'https://alias.example', 'NULL', 'bool-ok', 'app-ok', 'sink-ok', 'lance@joustmedia.com|Joust Media']);
    // a config that only has the OLD names still drives notifications + sign-in (sender, sink, absolute links)
    $out = appRun('
        $GLOBALS["config"] = ["host" => "x", "portal_base_url" => "https://old.example/portal", "auth_mail_from" => "old@joustmedia.example",
                              "auth_mail_from_name" => "Old Name", "mail_capture_dir" => "/tmp/nowhere"];
        echo notifyBaseUrl(), "|", implode(",", notifyMailSender()), "|", portalAbsoluteUrl("/portal/x"), "|", notifyCfg("mail_sink_dir");');
    is(trim($out), 'https://old.example/portal|old@joustmedia.example,Old Name|https://old.example/portal/x|/tmp/nowhere');
    // sender forms: bare address + name, full "Name <addr>", defaults
    $out = appRun('echo implode(",", notifyMailSender("Team <t@joustmedia.com>")), "|", implode(",", notifyMailSender("t@joustmedia.com", "Team")), "|",
        notifyMailFromHeader("t@joustmedia.com", "Zoë"), "|", implode(",", notifyMailSender("not an address"));');
    is(trim($out), 't@joustmedia.com,Team|t@joustmedia.com,Team|=?UTF-8?B?' . base64_encode('Zoë') . '?= <t@joustmedia.com>|lance@joustmedia.com,Joust Media');
});

// ---- links -------------------------------------------------------------------------------------------------------------
itest('links: notify\'s absolute links follow clean links; a client contact gets clientLink(), Joust the admin URL', function () {
    $port = parse_url(base(), PHP_URL_PORT);
    $out = appRun('
        $i = notifyItemInfo($pdo, "post", 1);
        echo portalItemUrl("post", 1, "kenda"), "\n", notifyPortalUrl("index"), "\n";
        $plainJane = notifyItemLinkFor($pdo, $i, "jane@kenda.example");
        $GLOBALS["__portal_clean_override"] = true;
        $i = notifyItemInfo($pdo, "post", 1);
        echo portalItemUrl("post", 1, "kenda"), "\n", $i["url"], "\n", notifyPortalUrl("index", ["client" => "kenda"]), "\n";
        echo notifyItemLinkFor($pdo, $i, "Jane <jane@kenda.example>"), "\n", notifyItemLinkFor($pdo, $i, "lance@joustmedia.com"), "\n";
        echo notifyItemLinkFor($pdo, $i, "pat@privacybee.example"), "\n";
        $t = notifyItemInfo($pdo, "tire_image", 1);
        echo notifyItemLinkFor($pdo, $t, "jane@kenda.example"), "\n", $plainJane, "\n";');
    $l = explode("\n", trim($out));
    is($l[0], "http://127.0.0.1:{$port}/portal/posts.php?client=kenda&post=1", 'clean links off');
    is($l[1], "http://127.0.0.1:{$port}/portal/");
    is($l[2], "http://127.0.0.1:{$port}/portal/kenda/posts/1", 'clean links on');
    is($l[3], $l[2], 'notifyItemInfo url (Slack "Open in portal") is the same clean admin URL');
    is($l[4], "http://127.0.0.1:{$port}/portal/kenda/");
    ok(preg_match('#^http://127\.0\.0\.1:' . $port . '/portal/kenda/posts/1\?k=1\.\d+\.[A-Za-z0-9_-]{32}$#', $l[5]) === 1, 'contact → signed clientLink: ' . $l[5]);
    is($l[6], $l[2], 'Joust → plain admin URL');
    is($l[7], $l[2], 'a contact of ANOTHER client is not signed in to this one');
    ok(preg_match('#/portal/kenda/tires/\d+\?(series=\d+&)?asset=1&kind=tire&k=#', $l[8]) === 1, 'tire image deep link: ' . $l[8]);
    // the deep link really signs Jane in and lands on the post
    ok(preg_match('#^http://127\.0\.0\.1:' . $port . '/portal/posts\.php\?client=kenda&post=1&k=1\.\d+\.[A-Za-z0-9_-]{32}$#', $l[9]) === 1, 'clean links off: query form: ' . $l[9]);
    $r = get(preg_replace('#^https?://[^/]+/portal/#', '', $l[9]), 'anon');
    is($r['code'], 302, 'the link signs Jane in');
    is($r['location'], '/portal/posts.php?client=kenda&post=1', 'and lands on the post, k dropped');
    ok(preg_grep('/^jsm_client=/', $r['cookies']) !== [], 'session cookie set');
});

itest('links: with clean links installed the Slack parent links the clean admin URL; the extensionless thumbnail route still answers', function () {
    installCleanLinks();
    try {
        slackReset();
        status(post('status.php', ['id' => 1, 'comment' => 'Clean link please', 'client' => 'kenda'], 'client', [], J), 200);
        $parent = array_values(array_filter(slackCalls(), static function ($c) { return $c['method'] === 'chat.postMessage' && !isset($c['body']['thread_ts']); }))[0] ?? null;
        ok($parent !== null, 'parent posted');
        $b = json_encode($parent['body']['blocks']);
        has($b, '"url":"http:\/\/127.0.0.1:' . parse_url(base(), PHP_URL_PORT) . '\/portal\/kenda\/posts\/1"', 'clean Open in portal');
        ok(preg_match('#"image_url":"([^"]+)"#', $b, $m) === 1, 'thumbnail');
        $thumb = str_replace('\/', '/', $m[1]);
        $r = get(str_replace('notify-thumb.php?', 'notify-thumb?', $thumb), 'anon');
        is($r['code'], 200, 'extensionless notify-thumb served by notify-thumb.php');
        has((string)($r['headers']['content-type'] ?? ''), 'image/jpeg');
    } finally {
        removeCleanLinks();
    }
});

// ---- router -------------------------------------------------------------------------------------------------------------
itest('router: never captures slack-events, slack-actions, notify-cron or notify-thumb', function () {
    $out = appRun('
        $o = [];
        foreach (["slack-events", "slack-actions", "notify-cron", "notify-thumb"] as $n) {
            $o[] = $n . ":" . (in_array($n, portalMachineEndpoints(), true) ? "machine" : "-") . (in_array($n, portalReservedSegments(), true) ? ",reserved" : "")
                 . "," . var_export(portalRouteMatch($n), true) . "," . var_export(portalRouteMatch($n . "/"), true) . "," . var_export(portalRouteMatch($n . "/posts"), true);
        }
        $o[] = base64_encode(cleanLinksBlock("/portal"));
        echo implode("\n", $o);');
    $l = explode("\n", trim($out));
    $blk = (string)base64_decode($l[4]);
    $l[4] = preg_match('#RewriteRule \^\(([a-z|-]+)\)/\?\$ - \[L\]#', $blk, $m) ? $m[1] : 'no-passthrough';
    foreach (['slack-events', 'slack-actions', 'notify-cron', 'notify-thumb'] as $i => $n) is($l[$i], "{$n}:machine,reserved,NULL,NULL,NULL", $n);
    foreach (['slack-events', 'slack-actions', 'notify-cron', 'notify-thumb'] as $n) has('|' . $l[4] . '|', "|{$n}|", "{$n} passes through the .htaccess block");
    // route.php itself (should a machine path ever reach it): a 404, never a client page or a sign-in redirect
    foreach (['/portal/slack-events', '/portal/slack-events/', '/portal/slack-actions/', '/portal/notify-cron/x', '/portal/notify-thumb', '/portal/notify-thumb.php/x'] as $u) {
        $r = routeProbe($u, 'POST');
        ok(strpos($r, '404|') === 0, "{$u} → 404 ({$r})");
    }
    ok(strpos(routeProbe('/portal/kenda/posts'), '404|') !== 0, 'a real clean path is still routed');
    // a client slug can never be a machine endpoint
    $r = post('client-admin.php', ['action' => 'create', 'name' => 'Slack Events Co', 'slug' => 'slack-events'], 'admin', [], J);
    ok($r['code'] >= 400 || (int)q1("SELECT COUNT(*) FROM companies WHERE slug = 'slack-events'") === 0, 'slug refused');
});

itest('router: with clean links installed the machine endpoints answer themselves (no redirect, no sign-in page)', function () {
    installCleanLinks();
    try {
        $r = post('slack-events', ['x' => 1], 'anon');
        is($r['code'], 401, 'slack-events.php checked the signature');
        is($r['json']['error'] ?? '', 'missing signature');
        $r = post('slack-actions', ['payload' => '{}'], 'anon');
        is($r['code'], 401, 'slack-actions.php');
        $r = get('notify-cron', 'anon');
        is($r['code'], 403, 'notify-cron.php wants its token');
        $r = get('notify-cron', 'anon', ['X-Notify-Token' => CRON]);
        is($r['code'], 200, 'notify-cron.php runs');
        is($r['json']['ok'] ?? null, true);
        $r = get('notify-thumb?t=bogus', 'anon');
        is($r['code'], 400, 'notify-thumb.php judged the token');
        foreach (['slack-events/', 'notify-cron/', 'notify-thumb/'] as $p) {
            $r = get($p, 'anon');
            is($r['code'], 404, "{$p} is not routed");
            is($r['location'], '', "{$p} no redirect");
        }
        // the client pages are routed as before
        is(get('kenda/posts', 'client')['code'], 200);
    } finally {
        removeCleanLinks();
    }
});

// ---- authorship -----------------------------------------------------------------------------------------------------------
itest('authorship: migrate 44 adds activity_log.client_contact_id (idempotent probe)', function () {
    is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'client_contact_id'"), 1);
    is(trim(appRun('echo activityHasContactCol($pdo) ? "yes" : "no";')), 'yes');
});

itest('authorship: a client comment records the signed-in contact; Slack and the admin views name them; the admin\'s "reply as client" stays anonymous', function () {
    slackReset();
    status(post('status.php', ['id' => 1, 'comment' => 'From Jane herself', 'client' => 'kenda'], 'client', [], J), 200);
    is((int)q1("SELECT client_contact_id FROM activity_log WHERE detail = 'From Jane herself'"), 1, 'contact 1 = jane@kenda.example');
    $reply = array_values(array_filter(slackCalls(), static function ($c) { return isset($c['body']['thread_ts']); }))[0] ?? null;
    ok($reply !== null, 'thread reply');
    has((string)$reply['body']['text'], '*Jane Kenda (Kenda Tires)* commented');
    // a contact without a name → the email
    $sess = 'client:privacybee';
    db()->exec("UPDATE client_contacts SET name = NULL WHERE id = 3");
    slackReset();
    status(post('email-status.php', ['id' => 2, 'comment' => 'Pat here', 'client' => 'privacybee'], $sess, [], J), 200);
    is((int)q1("SELECT client_contact_id FROM activity_log WHERE detail = 'Pat here'"), 3);
    $reply = array_values(array_filter(slackCalls(), static function ($c) { return isset($c['body']['thread_ts']); }))[0] ?? null;
    ok($reply !== null, 'thread reply (Privacy Bee)');
    has((string)$reply['body']['text'], '*pat@privacybee.example (Privacy Bee)* commented');
    // admin views: the comment thread and the activity feed
    has(get('posts.php?client=kenda&post=1&partial=1')['body'], 'Jane Kenda (Kenda Tires)', 'post thread (admin)');
    hasNot(get('posts.php?client=kenda&post=1&partial=1', 'client')['body'], 'Jane Kenda (Kenda Tires)', 'the client seat keeps "You"');
    has(get('index.php?client=kenda')['body'], 'Jane Kenda (Kenda Tires)', 'Home activity (admin)');
    // the admin's "reply as client" is not attributed to a contact
    status(post('status.php', ['id' => 1, 'comment' => 'Admin as client', 'actor' => 'client', 'client' => 'kenda'], 'admin', [], J), 200);
    ok(q1("SELECT client_contact_id FROM activity_log WHERE detail = 'Admin as client'") === null, 'NULL for the admin seat');
    // a removed contact → the client name again
    db()->exec("DELETE FROM client_contacts WHERE id = 1");
    $b = get('posts.php?client=kenda&post=1&partial=1')['body'];
    hasNot($b, 'Jane Kenda (Kenda Tires)', 'removed contact');
    has($b, '>Kenda Tires', 'falls back to the client name');
});

finish();
