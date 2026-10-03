<?php
/**
 * Notifications (notify-lib.php, Phases 1–2): named authors, the outbox, Slack threads per item (against the fake Slack
 * API tests/slack-stub.php), escalation, Slack in (events + buttons, signatures, replay, dedupe), internal notes,
 * the Morning summary on the outbox, Home "Latest notes", the cron token, Manage → Notifications, and the admin
 * tire views' "All approved ✓".
 *
 * Seed (tests/seed.php): Lance = admin_users 1 (Slack U0LANCE, the admin seat's email); Kenda → C0KENDA, Privacy Bee →
 * C0PBEE, Hollow Mill Farm unmapped. The seed already holds an unanswered client deny note on post 4 (90 min old).
 */
require __DIR__ . '/lib.php';

const CRON_TK = 'test-cron-token-0123456789abcdef';
const SIGNING = 'test-signing-secret-abcdef';
const J       = ['Accept' => 'application/json'];

// The app pins MySQL sessions to America/New_York (db.php); NOW() here must mean the same wall clock.
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");

function nroot(): string { return rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test'), '/'); }
/** Every call the fake Slack received, oldest first: [{method, auth, body}]. */
function slackCalls(): array {
    $f = nroot() . '/slack-calls.jsonl';
    if (!is_file($f)) return [];
    return array_values(array_filter(array_map(static function ($l) { return json_decode($l, true); }, file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))));
}
function slackReset(): void { @unlink(nroot() . '/slack-calls.jsonl'); @unlink(nroot() . '/slack-calls.jsonl.fail'); }
function slackFail(?string $how): void { $f = nroot() . '/slack-calls.jsonl.fail'; if ($how === null) @unlink($f); else file_put_contents($f, $how); }
function callsOf(string $method, array $calls = null): array {
    return array_values(array_filter($calls ?? slackCalls(), static function ($c) use ($method) { return $c['method'] === $method; }));
}
function mailDir(): string { return nroot() . '/mail'; }
function mails(): array {
    $out = [];
    foreach (glob(mailDir() . '/*.json') ?: [] as $f) $out[] = json_decode((string)file_get_contents($f), true);
    return $out;
}
function cron(string $extra = '', array $headers = []): array { return get('notify-cron.php' . ($extra !== '' ? '?' . ltrim($extra, '&?') : ''), 'anon', $headers + ['X-Notify-Token' => CRON_TK]); }
function outbox(string $where = '1=1', array $p = []): array { return rows("SELECT * FROM notify_outbox WHERE {$where} ORDER BY id", $p); }
function thread(string $type, int $id): ?array { $r = rows("SELECT * FROM notify_threads WHERE entity_type = ? AND entity_id = ?", [$type, $id]); return $r[0] ?? null; }
/** A Slack-signed POST (JSON body or form). $ts lets a test send a stale timestamp; $sig overrides the signature. */
function slackPost(string $path, string $body, string $ctype = 'application/json', ?int $ts = null, ?string $sig = null): array {
    $ts = $ts ?? time();
    $sig = $sig ?? 'v0=' . hash_hmac('sha256', "v0:{$ts}:{$body}", SIGNING);
    $ch = curl_init(base() . '/' . $path);
    $hdrs = [];
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ["Content-Type: {$ctype}", "X-Slack-Request-Timestamp: {$ts}", "X-Slack-Signature: {$sig}"],
        CURLOPT_HEADERFUNCTION => static function ($c, $l) use (&$hdrs) { $p = strpos($l, ':'); if ($p) $hdrs[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1)); return strlen($l); }]);
    $out = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    // The endpoints ack first (Content-Length + Connection: close) and work after the response; php -S serves one
    // request at a time, so the next request returns only once that work is done.
    get('login.php', 'anon');
    return ['code' => $code, 'body' => $out, 'json' => json_decode($out, true), 'headers' => $hdrs];
}
function slackEvent(array $event, ?string $eventId = null): array {
    $body = json_encode(['type' => 'event_callback', 'team_id' => 'T0JOUST', 'event_id' => $eventId ?? ('Ev' . bin2hex(random_bytes(5))), 'event' => $event]);
    return slackPost('slack-events.php', $body);
}
function slackAction(string $actionId, string $value, string $channel, string $msgTs, string $user = 'U0LANCE'): array {
    $payload = ['type' => 'block_actions', 'trigger_id' => 'tr' . bin2hex(random_bytes(5)), 'user' => ['id' => $user],
                'container' => ['type' => 'message', 'channel_id' => $channel, 'message_ts' => $msgTs],
                'response_url' => 'http://127.0.0.1:' . (getenv('PORTAL_TEST_STUB_PORT') ?: '9099') . '/response/T0/1/abc',
                'actions' => [['action_id' => $actionId, 'value' => $value, 'action_ts' => (string)microtime(true)]]];
    return slackPost('slack-actions.php', http_build_query(['payload' => json_encode($payload)]), 'application/x-www-form-urlencoded');
}
function clientComment(int $postId, string $text, string $slug = 'kenda'): void {
    status(post('status.php', ['id' => $postId, 'comment' => $text, 'client' => $slug], 'client', [], J), 200, 'client comment');
}
/** Is $text listed as a waiting note in the admin's Home "Latest notes" (not just in the activity feed)? */
function waitingNote(string $html, string $text): bool { return strpos($html, 'data-note-waiting><q>' . htmlspecialchars($text, ENT_QUOTES)) !== false
    || preg_match('#data-note-waiting><span class="home-note-text">.*?<q>' . preg_quote(htmlspecialchars($text, ENT_QUOTES), '#') . '#', $html) === 1; }
/** Mark everything already waiting as answered, so a test only sees its own item (the seed's post 4 note). */
function quiet(): void {
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, internal) VALUES (1, 'post', 4, 'resolved', 'admin', 'test: resolved', 1)");
}
/** Every test starts from the seed (the suite writes a lot; tests must not see each other's rows). */
function reseedNow(): void {
    $app = getenv('APP_DIR') ?: nroot() . '/site/portal';
    $media = getenv('MEDIA_DIR') ?: nroot() . '/site/media';
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($app) . ' ' . escapeshellarg($media) . ' 2>&1', $out, $rc);
    if ($rc !== 0) throw new RuntimeException('seed failed: ' . implode("\n", $out));
}
function ntest(string $name, callable $fn): void { test($name, static function () use ($fn) { reseedNow(); $fn(); }); }
function textOf(array $call): string { return (string)($call['body']['text'] ?? ''); }
function blocksJson(array $call): string { return json_encode($call['body']['blocks'] ?? []); }

// ---- migration + config ---------------------------------------------------------------------------------------
ntest('migrate 36–39: tables, columns, Lance seeded, settings in meta', function () {
    foreach (['admin_users', 'notify_outbox', 'notify_clients', 'notify_threads', 'slack_inbox'] as $t) {
        is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]), 1, $t);
    }
    is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME IN ('author_user_id','internal')"), 2);
    is(q1("SELECT name FROM admin_users WHERE email = 'lance@joustmedia.com'"), 'Lance');
    is(q1("SELECT v FROM meta WHERE k = 'notify_t1_minutes'"), '60');
    is(q1("SELECT v FROM meta WHERE k = 'notify_t2_minutes'"), '240');
});
ntest('config.php is out of git; config.example.php lists every key with blank values', function () {
    $repo = dirname(__DIR__, 2);
    $tracked = trim((string)shell_exec('cd ' . escapeshellarg($repo) . ' && git ls-files config.php config.example.php .gitignore 2>/dev/null'));
    hasNot($tracked . "\n", "config.php\n", 'config.php untracked');
    has($tracked, 'config.example.php');
    has((string)file_get_contents($repo . '/.gitignore'), 'config.php');
    $ex = require $repo . '/config.example.php';
    foreach (['host', 'dbname', 'username', 'password', 'charset', 'portal_base_url', 'notify_to', 'notify_from', 'notify_reply_to',
              'notify_message_domain', 'notify_envelope', 'mail_transport', 'notify_cron_token', 'slack_bot_token', 'slack_signing_secret',
              'slack_api_base', 'drive_ingest_secret', 'preview_secret', 'machine_url_ext'] as $k) ok(array_key_exists($k, $ex), "key {$k}");
    foreach (['password', 'notify_cron_token', 'slack_bot_token', 'slack_signing_secret', 'drive_ingest_secret', 'preview_secret'] as $k) is($ex[$k], '', "{$k} blank");
});

// ---- Slack out ------------------------------------------------------------------------------------------------------
ntest('client comment → one outbox row, the item thread is created (Block Kit parent) and the comment posted in it with @Lance', function () {
    slackReset();
    clientComment(1, 'Can we swap this tire angle?');
    $o = outbox();
    is(count($o), 1, 'one outbox row');
    is($o[0]['kind'], 'item_event');
    is($o[0]['status'], 'sent', 'delivered after the response');
    $calls = callsOf('chat.postMessage');
    is(count($calls), 2, 'parent + reply');
    [$parent, $reply] = $calls;
    is($parent['auth'], 'Bearer xoxb-test-token', 'bot token from config');
    is($parent['body']['channel'], 'C0KENDA');
    ok(!isset($parent['body']['thread_ts']), 'parent is top-level');
    $b = blocksJson($parent);
    has($b, 'Spring launch hero', 'title');
    has($b, 'Kenda Tires', 'client');
    has($b, 'To Review', 'status pill');
    has($b, '"image_url":"http:\/\/127.0.0.1', 'thumbnail');
    has($b, 'notify-thumb.php?t=', 'signed thumbnail route');
    has($b, '"action_id":"open"', 'Open in portal button');
    has($b, '"url":"http:\/\/127.0.0.1:' . (getenv('PORTAL_TEST_PORT') ?: '8099') . '\/portal\/posts.php?client=kenda&post=1"', 'absolute deep link');
    has($b, '"action_id":"resolve"', 'Resolve (a client message is waiting)');
    $t = thread('post', 1);
    ok($t && $t['slack_channel'] === 'C0KENDA' && $t['slack_ts'] !== '', 'thread stored');
    is($reply['body']['thread_ts'], $t['slack_ts'], 'reply in the item thread');
    has(textOf($reply), '<@U0LANCE>', '@mention of the owner');
    has(textOf($reply), 'Can we swap this tire angle?');
    has(textOf($reply), '*Kenda Tires* commented');
});
ntest('client deny with a slide note → ONE message: "requested changes on slide 2" + the quote; the parent shows Needs changes', function () {
    slackReset();
    status(post('status.php', ['id' => 2, 'status' => 'denied', 'comment' => '[Slide 2] Crop tighter & warmer', 'client' => 'kenda'], 'client', [], J), 200);
    $o = outbox();
    is(count($o), 1, 'one row for the batch');
    is(count(json_decode($o[0]['payload'], true)['activity_ids']), 2, 'deny + note merged');
    $calls = callsOf('chat.postMessage');
    is(count($calls), 2);
    has(blocksJson($calls[0]), 'Needs changes');
    $txt = textOf($calls[1]);
    has($txt, 'requested changes on slide 2:');
    has($txt, '> Crop tighter &amp; warmer', 'quoted, mrkdwn-escaped, slide tag humanised');
    hasNot($txt, '[Slide 2]');
});
ntest('client approve + caption edit ping too; Joust\'s own comments / decisions never post', function () {
    slackReset();
    status(post('status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda'], 'client', [], J), 200);
    status(post('status.php', ['id' => 3, 'caption' => 'New trail copy', 'client' => 'kenda'], 'client', [], J), 200);
    is(count(outbox("kind = 'item_event'")), 2);
    $calls = callsOf('chat.postMessage');
    ok((bool)array_filter($calls, static function ($c) { return strpos(textOf($c), 'approved it') !== false; }), 'approval posted');
    ok((bool)array_filter($calls, static function ($c) { return strpos(textOf($c), 'edited the caption') !== false; }), 'caption edit posted');
    slackReset();
    $before = count(outbox());
    status(post('status.php', ['id' => 2, 'comment' => 'Joust note', 'client' => 'kenda'], 'admin', [], J), 200);   // no thread on post 2
    status(post('status.php', ['id' => 8, 'status' => 'approved', 'client' => 'privacybee'], 'admin', [], J), 200);
    status(post('email-status.php', ['id' => 2, 'comment' => 'From Joust', 'client' => 'privacybee'], 'admin', [], J), 200);
    is(count(outbox()), $before, 'no outbox rows for Joust actions');
    is(count(slackCalls()), 0, 'nothing sent');
});
ntest('a client with no channel (Hollow Mill Farm) or an unmapped seat sends nothing; the activity is still logged', function () {
    db()->exec("DELETE FROM notify_clients WHERE company_id = 1");
    slackReset();
    clientComment(1, 'Unmapped');
    is(count(outbox()), 0);
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'post' AND entity_id = 1 AND detail = 'Unmapped'"), 1);
    db()->exec("INSERT INTO notify_clients (company_id, slack_channel_id) VALUES (1, 'C0KENDA')");
});
ntest('thread reuse: a second client comment goes into the same thread (no new parent); the parent is updated', function () {
    slackReset();
    clientComment(1, 'First');
    $ts = thread('post', 1)['slack_ts'];
    slackReset();
    clientComment(1, 'Second');
    $posts = callsOf('chat.postMessage');
    is(count($posts), 1, 'only the reply');
    is($posts[0]['body']['thread_ts'], $ts);
    $upd = callsOf('chat.update');
    is(count($upd), 1, 'parent re-rendered (2 messages waiting)');
    is($upd[0]['body']['ts'], $ts);
    has(blocksJson($upd[0]), '2 messages');
});
ntest('status change by Joust on an item WITH a thread → chat.update of the parent (pill + buttons), no thread post', function () {
    clientComment(1, 'Looks close');
    $ts = thread('post', 1)['slack_ts'];
    slackReset();
    status(post('status.php', ['id' => 1, 'status' => 'approved', 'actor' => 'admin', 'client' => 'kenda'], 'admin', [], J), 200);
    is(count(callsOf('chat.postMessage')), 0, 'Joust\'s decision is not posted');
    $upd = callsOf('chat.update');
    is(count($upd), 1);
    is($upd[0]['body']['ts'], $ts);
    $b = blocksJson($upd[0]);
    has($b, 'Approved');
    has($b, '"action_id":"scheduled"', 'Mark Scheduled offered');
    hasNot($b, '"action_id":"resolve"', 'answered by the decision');
    has($b, 'No open questions');
    slackReset();
    status(post('status.php', ['action' => 'toggle_posted', 'id' => 1, 'to' => '1', 'client' => 'kenda'], 'admin', [], J), 200);
    has(blocksJson(callsOf('chat.update')[0]), 'Scheduled');
});

// ---- named authors + internal notes ---------------------------------------------------------------------------------
ntest('named authors: Joust\'s rows carry author_user_id; the client reads "Lance at Joust", the admin "You"', function () {
    status(post('status.php', ['id' => 1, 'comment' => 'On it', 'client' => 'kenda'], 'admin', [], J), 200);
    is((int)q1("SELECT author_user_id FROM activity_log WHERE detail = 'On it'"), 1);
    is(q1("SELECT author_user_id FROM activity_log WHERE entity_id = 1 AND actor = 'client' ORDER BY id DESC LIMIT 1"), false, 'no client rows yet') ;
    clientComment(1, 'Thanks');
    ok(q1("SELECT author_user_id FROM activity_log WHERE detail = 'Thanks'") === null, 'client rows have no admin author');
    has(get('posts.php?client=kenda&post=1&partial=1', 'client')['body'], 'Lance at Joust');
    $a = get('posts.php?client=kenda&post=1&partial=1', 'admin')['body'];
    ok(preg_match('#data-actor="admin">.*?<div class="ui-bubble-meta">.*?You#s', $a) === 1, 'admin sees You');
});

// ---- Slack in: events ------------------------------------------------------------------------------------------------
ntest('slack-events: url_verification, signature required, stale timestamp + replay refused', function () {
    $body = json_encode(['type' => 'url_verification', 'challenge' => 'chal-123']);
    $r = slackPost('slack-events.php', $body);
    is($r['code'], 200);
    is($r['json']['challenge'] ?? null, 'chal-123');
    is(slackPost('slack-events.php', $body, 'application/json', null, 'v0=deadbeef')['code'], 401, 'bad signature');
    is(slackPost('slack-events.php', $body, 'application/json', time() - 400)['code'], 401, 'older than 5 minutes');
    is(slackPost('slack-events.php', $body, 'application/json', time() + 400)['code'], 401, 'from the future');
    $ts = time() - 10;   // a captured request replayed with a different body keeps the old signature → refused
    $sig = 'v0=' . hash_hmac('sha256', "v0:{$ts}:{$body}", SIGNING);
    is(slackPost('slack-events.php', json_encode(['type' => 'url_verification', 'challenge' => 'evil']), 'application/json', $ts, $sig)['code'], 401, 'tampered body');
});
ntest('slack-events: a reply in the item thread from Lance becomes a portal comment by Lance on THAT item; event_id dedupe', function () {
    clientComment(1, 'Question?');
    $t = thread('post', 1);
    $n0 = (int)q1("SELECT COUNT(*) FROM activity_log");
    $ev = ['type' => 'message', 'channel' => 'C0KENDA', 'user' => 'U0LANCE', 'text' => 'Sure &amp; swapping now <https://example.com|ref>', 'ts' => '1800000000.000100', 'thread_ts' => $t['slack_ts']];
    $r = slackEvent($ev, 'EvDEDUPE1');
    is($r['code'], 200);
    $row = rows("SELECT * FROM activity_log WHERE entity_type = 'post' AND entity_id = 1 AND actor = 'admin' AND action = 'commented' ORDER BY id DESC LIMIT 1")[0];
    is($row['detail'], 'Sure & swapping now ref (https://example.com)', 'Slack markup turned into plain text');
    is((int)$row['author_user_id'], 1, 'by Lance');
    is((int)$row['internal'], 0);
    is((int)$row['company_id'], 1);
    is((int)q1("SELECT COUNT(*) FROM activity_log"), $n0 + 1, 'exactly one row');
    $again = slackEvent($ev, 'EvDEDUPE1');
    ok(!empty($again['json']['duplicate']), 'Slack retry acked as duplicate');
    is((int)q1("SELECT COUNT(*) FROM activity_log"), $n0 + 1, 'no second comment');
    is(q1("SELECT status FROM slack_inbox WHERE event_id = 'EvDEDUPE1'"), 'done');
    has(get('posts.php?client=kenda&post=1&partial=1', 'client')['body'], 'Sure &amp; swapping now', 'the client sees the reply');
    // the reply answered the client: Latest notes no longer list post 1
    ok(!waitingNote(get('index.php?client=kenda', 'admin')['body'], 'Question?'), 'answered: not in Latest notes');
});
ntest('slack-events: bot messages, edits, top-level messages, unknown threads, unmapped users and other channels are ignored', function () {
    clientComment(1, 'Waiting');
    $t = thread('post', 1);
    $n0 = (int)q1("SELECT COUNT(*) FROM activity_log");
    $base = ['type' => 'message', 'channel' => 'C0KENDA', 'user' => 'U0LANCE', 'text' => 'nope', 'ts' => '1800000001.000100', 'thread_ts' => $t['slack_ts']];
    $cases = [
        'bot'       => ['bot_id' => 'B0BOT'] + $base,
        'edit'      => ['subtype' => 'message_changed'] + $base,
        'top-level' => array_diff_key($base, ['thread_ts' => 1]),
        'parent'    => ['ts' => $t['slack_ts']] + $base,
        'unknown'   => ['thread_ts' => '1234.5678'] + $base,
        'unmapped'  => ['user' => 'U0STRANGER'] + $base,
        'other ch.' => ['channel' => 'C0PBEE'] + $base,   // client isolation: another client's channel + this ts → no thread
    ];
    foreach ($cases as $why => $ev) {
        is(slackEvent($ev)['code'], 200, $why);
    }
    is((int)q1("SELECT COUNT(*) FROM activity_log"), $n0, 'nothing written');
    is((int)q1("SELECT COUNT(*) FROM slack_inbox WHERE status = 'ignored'"), count($cases));
});
ntest('internal note: "!internal …" from Slack is Joust-only — hidden from the client in every surface', function () {
    clientComment(1, 'Visible question');
    $t = thread('post', 1);
    slackEvent(['type' => 'message', 'channel' => 'C0KENDA', 'user' => 'U0LANCE', 'text' => '!internal Check with design SECRETNOTE', 'ts' => '1800000002.000100', 'thread_ts' => $t['slack_ts']]);
    $row = rows("SELECT * FROM activity_log WHERE detail LIKE '%SECRETNOTE%'")[0];
    is((int)$row['internal'], 1);
    is($row['detail'], 'Check with design SECRETNOTE', 'prefix stripped');
    foreach (['posts.php?client=kenda&post=1&partial=1', 'posts.php?client=kenda', 'index.php?client=kenda', 'feed.php?client=kenda',
              'posts.php?client=kenda&status=pending'] as $u) {
        hasNot(get($u, 'client')['body'], 'SECRETNOTE', "client: {$u}");
    }
    is(q1("SELECT client_comment FROM posts WHERE id = 1"), 'Visible question', 'client_comment untouched by an internal note');
    $a = get('posts.php?client=kenda&post=1&partial=1', 'admin')['body'];
    has($a, 'SECRETNOTE', 'Joust sees it');
    has($a, 'data-internal-pill', 'with the Internal pill');
    // an internal note does not answer the client
    ok(waitingNote(get('index.php?client=kenda', 'admin')['body'], 'Visible question'), 'still waiting');
    // internal notes on emails / assets are hidden too (thread readers + comment counts)
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, author_user_id, internal, summary, detail) VALUES
        (2, 'email', 3, 'commented', 'admin', 1, 1, 'x', 'EMAILSECRET'), (1, 'library_image', 1, 'commented', 'admin', 1, 1, 'x', 'LIBSECRET')");
    hasNot(get('emails.php?client=privacybee&email=3&partial=1', 'client')['body'], 'EMAILSECRET');
    hasNot(get('emails.php?client=privacybee', 'client')['body'], 'EMAILSECRET');
    hasNot(get('assets.php?client=kenda&partial=comments&kind=library&id=1', 'client')['body'], 'LIBSECRET');
    has(get('assets.php?client=kenda&partial=comments&kind=library&id=1', 'admin')['body'], 'LIBSECRET');
    has(get('emails.php?client=privacybee&email=3&partial=1', 'admin')['body'], 'EMAILSECRET');
});

// ---- Slack in: buttons -----------------------------------------------------------------------------------------------
ntest('slack-actions: signature checked; Resolve marks the waiting message answered (internal row by Lance) and updates the parent', function () {
    clientComment(2, 'Is slide B final?');
    $t = thread('post', 2);
    is(slackPost('slack-actions.php', 'payload=%7B%7D', 'application/x-www-form-urlencoded', null, 'v0=bad')['code'], 401);
    slackReset();
    $r = slackAction('resolve', 'post:2', 'C0KENDA', $t['slack_ts']);
    is($r['code'], 200);
    $row = rows("SELECT * FROM activity_log WHERE action = 'resolved' AND entity_id = 2")[0];
    is((int)$row['author_user_id'], 1);
    is((int)$row['internal'], 1);
    ok(!waitingNote(get('index.php?client=kenda', 'admin')['body'], 'Is slide B final?'), 'gone from Latest notes');
    hasNot(get('feed.php?client=kenda', 'client')['body'], 'answered', 'the client never sees the resolve');
    $upd = callsOf('chat.update');
    ok(count($upd) >= 1, 'parent updated');
    hasNot(blocksJson(end($upd)), '"action_id":"resolve"');
});
ntest('slack-actions follow the portal\'s transition rules: Send for review, Mark Scheduled, Mark Live; refusals reply ephemerally', function () {
    clientComment(1, 'Ready?');
    $t = thread('post', 1);
    // pending post → Mark Scheduled is refused (only an approved post can be scheduled)
    slackReset();
    slackAction('scheduled', 'post:1', 'C0KENDA', $t['slack_ts']);
    is((int)q1("SELECT posted FROM posts WHERE id = 1"), 0, 'refused');
    $resp = callsOf('response/T0/1/abc');
    is(count($resp), 1, 'ephemeral reply');
    has((string)$resp[0]['body']['text'], 'Only an approved post can be marked as scheduled');
    // back to draft (Joust, portal) → Send for review from Slack → pending, logged 'submitted' by Lance
    status(post('status.php', ['id' => 1, 'status' => 'draft', 'client' => 'kenda'], 'admin', [], J), 200);
    slackAction('submit', 'post:1', 'C0KENDA', $t['slack_ts']);
    is(q1("SELECT status FROM posts WHERE id = 1"), 'pending');
    is((int)q1("SELECT author_user_id FROM activity_log WHERE entity_id = 1 AND entity_type = 'post' AND action = 'submitted' ORDER BY id DESC LIMIT 1"), 1);
    // approved → Mark Scheduled works
    status(post('status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda'], 'admin', [], J), 200);
    slackAction('scheduled', 'post:1', 'C0KENDA', $t['slack_ts']);
    is((int)q1("SELECT posted FROM posts WHERE id = 1"), 1);
    // empty caption draft (post 7): Send for review refused with the portal's 422 message
    db()->exec("INSERT INTO notify_threads (company_id, entity_type, entity_id, slack_channel, slack_ts) VALUES (1, 'post', 7, 'C0KENDA', '1700009999.000007')");
    slackReset();
    slackAction('submit', 'post:7', 'C0KENDA', '1700009999.000007');
    is(q1("SELECT status FROM posts WHERE id = 7"), 'draft');
    has((string)(callsOf('response/T0/1/abc')[0]['body']['text'] ?? ''), 'Add a caption first');
    // email W3 (approved) → Mark Live
    status(post('email-status.php', ['id' => 3, 'comment' => 'Ship it', 'client' => 'privacybee'], 'client', [], J), 200);
    $te = thread('email', 3);
    ok($te && $te['slack_channel'] === 'C0PBEE', 'Privacy Bee thread in its own channel');
    has(blocksJson(callsOf('chat.postMessage')[0] ?? []) . json_encode(slackCalls()), '"action_id":"live"');
    slackAction('live', 'email:3', 'C0PBEE', $te['slack_ts']);
    is((int)q1("SELECT live FROM emails WHERE id = 3"), 1);
    is((int)q1("SELECT author_user_id FROM activity_log WHERE entity_type = 'email' AND entity_id = 3 AND action = 'marked_live'"), 1);
});
ntest('slack-actions: a button only acts on its own message\'s item; unmapped users are refused; trigger_id dedupe', function () {
    clientComment(1, 'Hello');
    $t = thread('post', 1);
    status(post('status.php', ['id' => 3, 'status' => 'approved', 'client' => 'kenda'], 'admin', [], J), 200);
    slackReset();
    slackAction('scheduled', 'post:3', 'C0KENDA', $t['slack_ts']);         // value names post 3, message is post 1's
    is((int)q1("SELECT posted FROM posts WHERE id = 3"), 0, 'mismatch refused');
    has((string)(callsOf('response/T0/1/abc')[0]['body']['text'] ?? ''), 'does not belong');
    slackAction('resolve', 'post:1', 'C0KENDA', $t['slack_ts'], 'U0STRANGER');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE action = 'resolved' AND entity_id = 1"), 0, 'unmapped user refused');
    $payload = ['type' => 'block_actions', 'trigger_id' => 'trSAME', 'user' => ['id' => 'U0LANCE'],
                'container' => ['channel_id' => 'C0KENDA', 'message_ts' => $t['slack_ts']], 'actions' => [['action_id' => 'resolve', 'value' => 'post:1']]];
    $body = http_build_query(['payload' => json_encode($payload)]);
    slackPost('slack-actions.php', $body, 'application/x-www-form-urlencoded');
    $dup = slackPost('slack-actions.php', $body, 'application/x-www-form-urlencoded');
    ok(!empty($dup['json']['duplicate']), 'same trigger_id acked as duplicate');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE action = 'resolved' AND entity_id = 1"), 1, 'resolved once');
});

// ---- escalation ------------------------------------------------------------------------------------------------------
ntest('escalation: T1 → thread re-ping (@Lance) + DM; T2 → email (Message-ID threaded per item); each once; answered → none', function () {
    quiet();
    clientComment(2, 'Any update on this?');
    $aid = (int)q1("SELECT id FROM activity_log WHERE detail = 'Any update on this?'");
    $t = thread('post', 2);
    slackReset();
    // younger than T1: nothing
    $j = status(cron(), 200)['json'];
    is($j['escalated']['t1'], 0);
    db()->prepare("UPDATE activity_log SET created_at = NOW() - INTERVAL 61 MINUTE WHERE id = ?")->execute([$aid]);
    $j = status(cron(), 200)['json'];
    is($j['escalated']['t1'], 1, 'T1 reached');
    is($j['escalated']['t2'], 0);
    $posts = callsOf('chat.postMessage');
    $thr = array_values(array_filter($posts, static function ($c) use ($t) { return ($c['body']['thread_ts'] ?? '') === $t['slack_ts']; }));
    is(count($thr), 1, 'thread re-ping');
    has(textOf($thr[0]), '<@U0LANCE>');
    has(textOf($thr[0]), 'Still unanswered');
    is(count(callsOf('conversations.open')), 1, 'DM opened');
    $dm = array_values(array_filter($posts, static function ($c) { return ($c['body']['channel'] ?? '') === 'D0LANCE'; }));
    is(count($dm), 1, 'DM sent');
    has(textOf($dm[0]), 'Any update on this?');
    slackReset();
    status(cron(), 200);
    is(count(callsOf('chat.postMessage')), 0, 'not repeated');
    // T2
    db()->prepare("UPDATE activity_log SET created_at = NOW() - INTERVAL 241 MINUTE WHERE id = ?")->execute([$aid]);
    $j = status(cron(), 200)['json'];
    is($j['escalated']['t2'], 1);
    $m = array_values(array_filter(mails(), static function ($m) { return strpos($m['subject'], 'AT2 carousel') !== false; }));
    is(count($m), 1, 'one email');
    is($m[0]['to'], 'lance@joustmedia.com');
    has($m[0]['text'], 'Any update on this?');
    has($m[0]['html'], 'posts.php?client=kenda&amp;post=2', 'deep link');
    $first = $m[0]['message_id'];
    is(thread('post', 2)['email_message_id'], $first, 'Message-ID stored on the item thread');
    status(cron(), 200);
    is(count(array_filter(mails(), static function ($m) { return strpos($m['subject'], 'AT2 carousel') !== false; })), 1, 'email not repeated');
    // answered (a Joust comment) → the next client message starts a fresh wait; its email replies to the first
    status(post('status.php', ['id' => 2, 'comment' => 'Tomorrow!', 'client' => 'kenda'], 'admin', [], J), 200);
    clientComment(2, 'Still need it');
    $aid2 = (int)q1("SELECT id FROM activity_log WHERE detail = 'Still need it'");
    db()->prepare("UPDATE activity_log SET created_at = NOW() - INTERVAL 300 MINUTE WHERE id = ?")->execute([$aid2]);
    status(cron(), 200);
    $m = array_values(array_filter(mails(), static function ($m) { return strpos($m['subject'], 'AT2 carousel') !== false && strpos($m['text'], 'Still need it') !== false; }));
    is(count($m), 1);
    is($m[0]['in_reply_to'] ?? null, $first, 'In-Reply-To the item\'s first email');
    has(implode("\n", $m[0]['header_lines']), 'References: ' . $first);
});
ntest('escalation: an answered message never escalates; thresholds come from Manage', function () {
    quiet();
    clientComment(1, 'Answer me');
    status(post('status.php', ['id' => 1, 'comment' => 'Answered', 'client' => 'kenda'], 'admin', [], J), 200);
    db()->exec("UPDATE activity_log SET created_at = NOW() - INTERVAL 500 MINUTE WHERE detail = 'Answer me'");
    $j = status(cron(), 200)['json'];
    is($j['escalated'], ['t1' => 0, 't2' => 0]);
    // T1 = 10 minutes (Manage → Notifications)
    status(post('notify-admin.php', ['action' => 'settings', 't1' => 10, 't2' => 20, 'summary_hour' => 9], 'admin', [], J), 200);
    is(status(post('notify-admin.php', ['action' => 'settings', 't1' => 30, 't2' => 20, 'summary_hour' => 9], 'admin', [], J), 422)['json']['ok'], false, 't2 must follow t1');
    clientComment(3, 'Quick one');
    db()->exec("UPDATE activity_log SET created_at = NOW() - INTERVAL 11 MINUTE WHERE detail = 'Quick one'");
    is(status(cron(), 200)['json']['escalated']['t1'], 1);
});

// ---- outbox: retry + backoff --------------------------------------------------------------------------------------
ntest('outbox: a Slack outage queues with backoff, the cron retries when due, a permanent error fails at once; Retry / Retry all', function () {
    slackFail('http500');
    clientComment(1, 'During the outage');
    $o = outbox()[0];
    is($o['status'], 'pending');
    is((int)$o['attempts'], 1);
    has((string)$o['last_error'], 'internal_error');
    is((int)q1("SELECT next_attempt_at > NOW() + INTERVAL 30 SECOND FROM notify_outbox WHERE id = ?", [$o['id']]), 1, 'next try ~1 minute later');
    status(cron(), 200);
    is((int)outbox()[0]['attempts'], 1, 'not due → untouched');
    db()->exec("UPDATE notify_outbox SET next_attempt_at = NOW() - INTERVAL 1 SECOND");
    status(cron(), 200);
    is((int)outbox()[0]['attempts'], 2, 'retried');
    slackFail(null);
    db()->exec("UPDATE notify_outbox SET next_attempt_at = NOW() - INTERVAL 1 SECOND");
    status(cron(), 200);
    is(outbox()[0]['status'], 'sent', 'delivered on retry');
    // permanent error → failed now; Manage Retry delivers once fixed
    slackFail('channel_not_found');
    clientComment(2, 'Wrong channel');
    $f = outbox("status = 'failed'");
    is(count($f), 1);
    has((string)$f[0]['last_error'], 'channel_not_found');
    slackFail(null);
    $r = status(post('notify-admin.php', ['action' => 'retry', 'id' => $f[0]['id']], 'admin', [], J), 200);
    is(outbox("id = ?", [$f[0]['id']])[0]['status'], 'sent');
    slackFail('channel_not_found');
    clientComment(3, 'Again wrong');
    slackFail(null);
    status(post('notify-admin.php', ['action' => 'retry_all'], 'admin', [], J), 200);
    is(count(outbox("status = 'failed'")), 0);
    // stuck 'sending' rows (a crashed request) are reclaimed by the cron
    db()->exec("UPDATE notify_outbox SET status = 'sending', next_attempt_at = NOW() - INTERVAL 5 MINUTE WHERE id = " . (int)$f[0]['id']);
    is(status(cron(), 200)['json']['reclaimed'], 1);
});

// ---- cron token -----------------------------------------------------------------------------------------------------
ntest('notify-cron: constant-time token, no session; digest.php?source=cron needs the token once configured', function () {
    is(get('notify-cron.php', 'anon')['code'], 403, 'no token');
    is(get('notify-cron.php?token=wrong', 'anon')['code'], 403, 'wrong token');
    is(get('notify-cron.php?token=' . substr(CRON_TK, 0, -1), 'anon')['code'], 403, 'prefix');
    is(get('notify-cron.php?token=' . CRON_TK, 'anon')['code'], 200, 'query form');
    $r = status(cron(), 200);
    ok($r['json']['ok'] === true);
    ok(!isset($r['headers']['set-cookie']), 'no session cookie');
    is(get('notify-cron.php', 'anon', ['X-Notify-Token' => CRON_TK])['code'], 200, 'header form');
    is(get('notify-cron.php', 'anon', ['Authorization' => 'Bearer ' . CRON_TK])['code'], 200, 'bearer form');
    is(get('digest.php?source=cron', 'anon')['code'], 403, 'open digest URL closed once a token exists');
    is(get('digest.php?source=cron&token=' . CRON_TK, 'anon')['code'], 200);
    is(get('digest.php?source=manual', 'anon')['code'], 403);
});

// ---- Morning summary ------------------------------------------------------------------------------------------------
ntest('Morning summary: client activity only, real deep links, rows marked sent only after a successful send', function () {
    clientComment(2, 'Summary note');
    status(post('status.php', ['id' => 2, 'comment' => 'JOUSTOWNREPLY', 'client' => 'kenda'], 'admin', [], J), 200);
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, author_user_id, internal, summary, detail) VALUES (1, 'post', 2, 'commented', 'admin', 1, 1, 'x', 'INTERNALNOTE')");
    touch(mailDir() . '/FAIL');
    $r = post('digest.php', ['source' => 'manual'], 'admin');
    is($r['code'], 200);
    has($r['body'], 'queued');
    $clientRows = (int)q1("SELECT COUNT(*) FROM activity_log WHERE actor = 'client'");
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE actor = 'client' AND digest_id IS NULL"), $clientRows, 'nothing marked after a failed send');
    $o = outbox("kind = 'summary'");
    is(count($o), 1);
    is($o[0]['status'], 'pending', 'queued for retry');
    @unlink(mailDir() . '/FAIL');
    db()->exec("UPDATE notify_outbox SET next_attempt_at = NOW() - INTERVAL 1 SECOND WHERE kind = 'summary'");
    status(cron(), 200);
    is(outbox("kind = 'summary'")[0]['status'], 'sent');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE digest_id IS NULL"), 0, 'all marked after the send');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE actor = 'admin' AND digest_id <> 0"), 0, 'Joust rows marked processed (0), not sent');
    $m = array_values(array_filter(mails(), static function ($m) { return strpos($m['subject'], 'Morning summary') === 0; }));
    is(count($m), 1);
    $html = $m[0]['html'];
    has($html, 'Morning summary');
    has($html, 'http://127.0.0.1:' . (getenv('PORTAL_TEST_PORT') ?: '8099') . '/portal/posts.php?client=kenda&amp;post=2', 'absolute deep link');
    has($html, 'Summary note');
    hasNot($html, 'JOUSTOWNREPLY', 'Joust\'s own actions left out');
    hasNot($html, 'Behind the scenes', 'the seeded admin "created" row left out');
    hasNot($html, 'INTERNALNOTE');
    hasNot($html . $m[0]['text'], 'admin.php');
    hasNot($html, 'href="#"');
    // nothing new → no email
    $n = count(mails());
    has(post('digest.php', ['source' => 'manual'], 'admin')['body'], 'empty');
    is(count(mails()), $n);
});
ntest('Morning summary: a newer run supersedes an undelivered one (no double send); the cron sends it once a day at the hour', function () {
    clientComment(1, 'A');
    touch(mailDir() . '/FAIL');
    post('digest.php', ['source' => 'manual'], 'admin');
    clientComment(1, 'B');
    post('digest.php', ['source' => 'manual'], 'admin');
    @unlink(mailDir() . '/FAIL');
    $o = outbox("kind = 'summary'");
    is(count($o), 2);
    is($o[0]['status'], 'skipped', 'the older one is superseded');
    db()->exec("UPDATE notify_outbox SET next_attempt_at = NOW() - INTERVAL 1 SECOND");
    status(cron(), 200);
    $m = array_values(array_filter(mails(), static function ($m) { return strpos($m['subject'], 'Morning summary') === 0; }));
    is(count($m), 1, 'one email');
    has($m[0]['text'], '"A"');
    has($m[0]['text'], '"B"');
    // daily trigger
    clientComment(1, 'C');
    db()->exec("UPDATE meta SET v = '2000-01-01' WHERE k = 'notify_summary_last'");
    db()->exec("UPDATE meta SET v = '0' WHERE k = 'notify_summary_hour'");
    is(status(cron(), 200)['json']['summary'], 'sent');
    is(q1("SELECT v FROM meta WHERE k = 'notify_summary_last'"), date('Y-m-d'));
    is(status(cron(), 200)['json']['summary'], 'not due', 'once a day');
});

// ---- Home: Latest notes -----------------------------------------------------------------------------------------------
ntest('Home Latest notes: every unanswered client comment, on any item and status (To Review post, approved email)', function () {
    clientComment(2, 'Can we swap this tire angle?');                                   // pending post
    status(post('email-status.php', ['id' => 3, 'comment' => 'Can the CTA be green?', 'client' => 'privacybee'], 'client', [], J), 200);   // approved email
    $k = get('index.php?client=kenda', 'admin')['body'];
    has($k, 'data-note-waiting');
    ok(waitingNote($k, 'Can we swap this tire angle?'), 'pending post note');
    has($k, 'on AT2 carousel');
    has($k, 'waiting on you');
    ok(waitingNote(get('index.php?client=privacybee', 'admin')['body'], 'Can the CTA be green?'), 'approved email note');
    hasNot(get('index.php?client=kenda', 'client')['body'], 'data-note-waiting', 'admin only');
    status(post('status.php', ['id' => 2, 'comment' => 'Yes, done', 'client' => 'kenda'], 'admin', [], J), 200);
    ok(!waitingNote(get('index.php?client=kenda', 'admin')['body'], 'Can we swap this tire angle?'), 'answered → gone');
});

// ---- Manage → Notifications ------------------------------------------------------------------------------------------
ntest('Manage → Notifications: admin only; config state without values; channels, team, test, find', function () {
    is(get('manage.php?section=notifications', 'client')['code'], 302);
    $b = status(get('manage.php?section=notifications'), 200)['body'];
    foreach (['data-notify-setup', 'data-notify-settings', 'data-notify-channels', 'data-notify-team', 'data-notify-log', 'slack-events.php', 'slack-actions.php', 'notify-cron.php'] as $n) has($b, $n);
    has($b, 'data-config-check="set"');
    foreach (['xoxb-test-token', SIGNING, CRON_TK] as $secret) hasNot($b, $secret, 'no secret in HTML');
    is(post('notify-admin.php', ['action' => 'settings', 't1' => 30, 't2' => 90, 'summary_hour' => 7], 'client', [], J)['code'], 403);
    status(post('notify-admin.php', ['action' => 'client', 'company_id' => 3, 'slack_channel_id' => 'bad id'], 'admin', [], J), 422);
    status(post('notify-admin.php', ['action' => 'find_channel', 'company_id' => 3], 'admin', [], J), 200);
    is(q1("SELECT slack_channel_id FROM notify_clients WHERE company_id = 3"), 'C0HMF', '#portal-hmf found');
    status(post('notify-admin.php', ['action' => 'user', 'id' => 0, 'name' => 'Sam', 'email' => 'sam@joustmedia.com'], 'admin', [], J), 200);
    is(status(post('notify-admin.php', ['action' => 'find_user', 'id' => (int)q1("SELECT id FROM admin_users WHERE email = 'sam@joustmedia.com'")], 'admin', [], J), 404)['json']['ok'], false);
    slackReset();
    status(post('notify-admin.php', ['action' => 'test', 'company_id' => 0], 'admin', [], J), 200);
    is(count(callsOf('conversations.open')), 1, 'test DM');
    status(post('notify-admin.php', ['action' => 'test', 'company_id' => 1], 'admin', [], J), 200);
    $pm = callsOf('chat.postMessage');
    is($pm[count($pm) - 1]['body']['channel'], 'C0KENDA');
    has(get('manage.php?section=notifications&log=sent')['body'], 'data-log-row');
});

// ---- thumbnails ------------------------------------------------------------------------------------------------------
ntest('notify-thumb: the signed thumbnail answers a JPEG without a session; forged / expired / malformed refused', function () {
    clientComment(1, 'Thumb');
    $b = blocksJson(callsOf('chat.postMessage')[0]);
    ok(preg_match('#notify-thumb\.php\?t=([A-Za-z0-9_.\-]+)#', $b, $m) === 1);
    $r = get('notify-thumb.php?t=' . $m[1], 'anon');
    is($r['code'], 200);
    is($r['headers']['content-type'] ?? '', 'image/jpeg');
    ok(!isset($r['headers']['set-cookie']));
    is(get('notify-thumb.php?t=' . substr($m[1], 0, -2) . 'xx', 'anon')['code'], 403, 'forged');
    is(get('notify-thumb.php?t=nope', 'anon')['code'], 400);
    // expired: same key derivation as preview-lib (config password + dbname), expiry in the past
    $key = hash('sha256', 'joust-notify-thumb|' . hash('sha256', 'joust-preview|' . (getenv('PORTAL_TEST_DB_PASS') ?: 'portal_test') . '|' . (getenv('PORTAL_TEST_DB') ?: 'portal_test')), true);
    $payload = 'uploads/img_post01_1.jpg|' . (time() - 10);
    $b64 = static function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
    $tok = $b64($payload) . '.' . substr($b64(hash_hmac('sha256', $payload, $key, true)), 0, 22);
    is(get('notify-thumb.php?t=' . $tok, 'anon')['code'], 410, 'expired');
});

// ---- tires: All approved ✓ ---------------------------------------------------------------------------------------------
ntest('tires (admin): "All approved ✓" on the list, series chips, page header and Reference card — distinct from empty and Needs changes', function () {
    $list = get('assets.php?client=kenda&view=collections')['body'];
    // Klever RT (2): refs approved + Series 1 all approved; Kenetica Sport (3): refs approved; Klever AT2 (1): a denied render
    ok(preg_match('#data-collection="2"(?:(?!data-collection=).)*data-allok="tire"#s', $list) === 1, 'tire 2 all approved');
    ok(preg_match('#data-collection="1"(?:(?!data-collection=).)*data-tire-denied#s', $list) === 1, 'tire 1 needs changes');
    ok(preg_match('#data-collection="1"(?:(?!data-collection=).)*data-allok#s', $list) === 0, 'tire 1 not all approved');
    $t2 = get('assets.php?client=kenda&view=collections&item=2')['body'];
    has($t2, 'data-tire-state="allok"', 'page header');
    has($t2, 'data-allok="page"');
    has($t2, 'data-allok="series-3"', 'series chip');
    has($t2, 'data-allok="series-ref"', 'Reference chip');
    has($t2, 'data-allok="reference"', 'Reference card');
    $t1 = get('assets.php?client=kenda&view=collections&item=1')['body'];
    has($t1, 'data-tire-state="denied"');
    hasNot($t1, 'data-allok="page"');
    has($t1, 'data-allok="series-ref"', 'tire 1 references are all approved');
    hasNot($t1, 'data-allok="series-1"', 'series 1 has pending + denied');
    // one pending render → the tire is no longer all approved; an empty tire is "No images yet", never ✓
    db()->exec("UPDATE tire_images SET status = 'pending' WHERE id = 20");
    hasNot(get('assets.php?client=kenda&view=collections&item=2')['body'], 'data-allok="page"');
    db()->exec("DELETE FROM tire_images WHERE tire_id = 3");
    $list = get('assets.php?client=kenda&view=collections')['body'];
    ok(preg_match('#data-collection="3"(?:(?!data-collection=).)*data-allok#s', $list) === 0, 'empty tire has no ✓');
    has(get('assets.php?client=kenda&view=collections&item=3')['body'], 'data-tire-state="empty"');
    hasNot(get('assets.php?client=kenda&view=collections&item=2', 'client')['body'], 'data-allok', 'admin views only');
});

// ---- secrets never leak ----------------------------------------------------------------------------------------------
ntest('secrets: never in pages, JS config, Slack payloads or the server log', function () {
    clientComment(1, 'Leak check');
    $all = '';
    foreach (['index.php?client=kenda', 'posts.php?client=kenda', 'manage.php?section=notifications', 'manage.php?client=kenda&section=tools', 'static/js/notifications.js'] as $u) {
        $all .= get($u, 'admin')['body'] . get($u, 'client')['body'];
    }
    $payloads = json_encode(array_map(static function ($c) { return $c['body']; }, slackCalls()));
    // php -S access lines carry request URLs (the cron URL's ?token= — the setup steps use the X-Notify-Token header
    // form for exactly this reason); every other line (PHP notices, error_log) must be free of secrets.
    $log = implode("\n", array_filter(file(nroot() . '/server.log', FILE_IGNORE_NEW_LINES) ?: [], static function ($l) {
        return !preg_match('#\] 127\.0\.0\.1:\d+ \[\d+\]: (GET|POST|HEAD) #', $l);
    }));
    foreach (['xoxb-test-token', SIGNING, CRON_TK] as $s) {
        hasNot($all, $s, 'pages');
        hasNot($payloads, $s, 'Slack payloads');
        hasNot($log, $s, 'server log');
    }
});

finish();
