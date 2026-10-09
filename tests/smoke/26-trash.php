<?php
/**
 * The Trash (trash-lib.php, trash.php; migrate.php 54) — items Joust decided not to do, kept but disregarded everywhere:
 *   - trashing each type (post, email, page, tire image, library image) with an optional reason: trashed_at / by / note,
 *     the status untouched, an internal 'trashed' row (Joust only); validation, client 403, cross-tenant 403
 *   - gone from every client view: lists, Sent back, Home cards + feed, tab badges, counts, deep links (a neutral
 *     "This item is no longer available", no error), and the client's endpoints answer 404
 *   - gone from Joust's normal lists and counts: segments, Home, the Inbox (Waiting on Joust / client), the Redo queue,
 *     the Needs changes queues; Joust's deep link says "in the Trash"
 *   - notifications: pending outbox rows skipped ("item trashed"), no escalation, no client email batch, not in the
 *     Morning summary or the weekly report; the Slack parent flips to "Trashed" (chat.update, no new message)
 *   - exports (approved assets, the selection, the redo pack) and the post pickers leave it out
 *   - Restore: the exact previous state, no client email, no escalation restart, an internal 'restored' row
 *   - Delete forever: only from the Trash, only with the typed DELETE; the row AND its files go
 *   - migrate.php 54 on the production-shaped schema (tire_images has no created_at): fresh, re-run, and the portal before it
 * Every test starts from the seed.
 */
require __DIR__ . '/lib.php';

const CRON_TK = 'test-cron-token-0123456789abcdef';
const J = ['Accept' => 'application/json'];
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");
date_default_timezone_set('America/New_York');

$ROOT  = rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test'), '/');
$APP   = rtrim((string)(getenv('APP_DIR') ?: $ROOT . '/site/portal'), '/');
$MEDIA = rtrim((string)(getenv('MEDIA_DIR') ?: $ROOT . '/site/media'), '/');
$MAIL  = getenv('MAIL_DIR') ?: $ROOT . '/mail';

function reseedNow(): void {
    global $APP, $MEDIA;
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($APP) . ' ' . escapeshellarg($MEDIA) . ' 2>&1', $out, $rc);
    if ($rc !== 0) throw new RuntimeException('seed failed: ' . implode("\n", $out));
}
function ttest(string $name, callable $fn): void { test($name, static function () use ($fn) { reseedNow(); $fn(); }); }

// ---- helpers ------------------------------------------------------------------------------------------------------
function trash(string $items, string $note = '', string $client = '', string $role = 'admin'): array {
    return post('trash.php', ['action' => 'trash', 'items' => $items, 'note' => $note] + ($client !== '' ? ['client' => $client] : []), $role, [], J);
}
function restore(string $items, string $client = '', string $role = 'admin'): array {
    return post('trash.php', ['action' => 'restore', 'items' => $items] + ($client !== '' ? ['client' => $client] : []), $role, [], J);
}
function forever(string $items, string $confirm = 'DELETE', string $client = '', string $role = 'admin'): array {
    return post('trash.php', ['action' => 'delete', 'items' => $items, 'confirm' => $confirm] + ($client !== '' ? ['client' => $client] : []), $role, [], J);
}
function trashedAt(string $table, int $id) { return q1("SELECT trashed_at FROM {$table} WHERE id = ?", [$id]); }
function mails(): array { global $MAIL; $o = []; foreach (glob($MAIL . '/*.json') ?: [] as $f) $o[] = json_decode((string)file_get_contents($f), true); return $o; }
function clearMail(): void { global $MAIL; foreach (glob($MAIL . '/*') ?: [] as $f) @unlink($f); }
function clientMails(): array { return array_values(array_filter(mails(), static function ($m) { return strpos((string)($m['kind'] ?? ''), 'client_') === 0; })); }
function cron(string $extra = ''): array {
    $r = get('notify-cron.php' . ($extra !== '' ? '?' . $extra : ''), 'anon', ['X-Notify-Token' => CRON_TK]);
    is($r['code'], 200, 'cron ran: ' . substr($r['body'], 0, 200));
    return $r['json'] ?? [];
}
function slackCalls(): array { global $ROOT; $f = $ROOT . '/slack-calls.jsonl'; return is_file($f) ? array_values(array_filter(array_map(static function ($l) { return json_decode($l, true); }, file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))) : []; }
function slackReset(): void { global $ROOT; @unlink($ROOT . '/slack-calls.jsonl'); }
function callsOf(string $method): array { return array_values(array_filter(slackCalls(), static function ($c) use ($method) { return ($c['method'] ?? '') === $method; })); }
/** After-response work (php -S finishes it after the reply): wait until $sql returns $want (≤ 8 s). */
function waitFor(string $sql, $want, array $p = []): bool {
    for ($i = 0; $i < 160; $i++) { if ((string)q1($sql, $p) === (string)$want) return true; usleep(50000); }
    return false;
}
function clientComment(int $postId, string $text, string $slug = 'kenda'): void { status(post('status.php', ['id' => $postId, 'comment' => $text, 'client' => $slug], 'client', [], J), 200, 'client comment'); }
function adminComment(int $postId, string $text): void { status(post('status.php', ['id' => $postId, 'comment' => $text, 'client' => 'kenda'], 'admin', [], J), 200, 'admin comment'); }
/** The seed's post 4 note waits on Joust: mark it answered so a test only sees its own items. */
function quiet(): void { db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, internal) VALUES (1, 'post', 4, 'resolved', 'admin', 'test: resolved', 1)"); }
function sessCookie(array $r): string { foreach ($r['cookies'] as $c) if (preg_match('/^jsm_admin=([^;]+)/', $c, $m)) return 'jsm_admin=' . $m[1]; return ''; }
function connectGoogle(): void {
    $r = post('google-oauth.php', ['action' => 'start'], 'admin');
    $cookie = sessCookie($r);
    $g = get($r['location'], 'anon');
    $b = get($g['location'], 'admin', ['Cookie' => $cookie]);
    is($b['code'], 303, 'Google connected');
}
function ageQueue(int $minutes): void { db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL {$minutes} MINUTE WHERE batch_key IS NULL"); }
/** PHP inside the test app (the whole helpers chain) → its JSON output. */
function appJson(string $code) {
    global $APP;
    $file = sys_get_temp_dir() . '/trash_smoke_' . bin2hex(random_bytes(4)) . '.php';
    $base = getenv('PORTAL_TEST_BASE') ?: 'http://127.0.0.1:8099/portal';
    file_put_contents($file, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/portal/notify-cron.php'; \$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "\$_SERVER['HTTP_HOST'] = " . var_export(parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT), true) . ";\n"
        . "chdir(" . var_export($APP, true) . ");\nrequire 'db.php';\nrequire_once 'helpers.php';\n" . $code . "\n");
    $out = (string)shell_exec('php -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    $j = json_decode(trim($out), true);
    if ($j === null && trim($out) !== 'null') fail('appJson: ' . substr($out, 0, 400));
    return $j;
}
/** migrate.php from the CLI as admin (what bootstrap.sh does); the HTML page it prints. */
function runMigrate(): string {
    global $APP;
    return (string)shell_exec('cd ' . escapeshellarg($APP) . ' && PORTAL_TEST=1 PORTAL_TEST_ROLE=admin php -d auto_prepend_file=' . escapeshellarg(dirname(__DIR__) . '/test-auth.php') . ' migrate.php 2>&1');
}
function colCount(string $t, string $c): int { return (int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$t, $c]); }
function badge(string $html, string $tab): int {
    if (!preg_match('#<a [^>]*data-tab="' . preg_quote($tab, '#') . '"[^>]*>.*?</a>#s', $html, $m)) return -1;
    return preg_match('#class="ui-badge[^"]*"[^>]*>(\d+)<#', $m[0], $b) ? (int)$b[1] : 0;
}
function segCount(string $html, string $seg): int {
    return preg_match('#data-segment="' . preg_quote($seg, '#') . '".*?ui-segmented-count[^>]*>(\d+)<#s', $html, $m) ? (int)$m[1] : -1;
}

// =====================================================================================================================
// Schema
// =====================================================================================================================
test('migrate 54: trashed_at / trashed_by / trash_note + ix_trashed on the five tables; production shape kept', function () {
    foreach (['posts', 'emails', 'pages', 'tire_images', 'library_images'] as $t) {
        foreach (['trashed_at', 'trashed_by', 'trash_note'] as $c) is(colCount($t, $c), 1, "$t.$c");
        is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'ix_trashed'", [$t]), 1, "$t index");
    }
    is(colCount('tire_images', 'created_at'), 0, 'tire_images has no created_at (production)');
    is(colCount('posts', 'status'), 1);
    $enum = (string)q1("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'status'");
    ok(stripos($enum, 'trash') === false && strpos($enum, "'approved'") !== false, 'the status enum is untouched (no Trash status)');
});

// =====================================================================================================================
// Moving to the Trash
// =====================================================================================================================
ttest('trash each type: columns set, status kept, internal activity, the reason Joust-only', function () {
    $r = status(trash('post:4', 'Not redoing this one', 'kenda'), 200);
    is($r['json']['trashed'], 1); is($r['json']['count'], 1);
    has((string)$r['json']['url'], 'trash');
    status(trash('tire:9,library:7', 'Client moved on', 'kenda'), 200);
    status(trash('email:4,page:1', '', 'privacybee'), 200);
    foreach ([['posts', 4, 'denied'], ['tire_images', 9, 'denied'], ['library_images', 7, 'pending'], ['emails', 4, 'denied'], ['pages', 1, 'pending']] as [$t, $id, $st]) {
        ok(trashedAt($t, $id) !== null, "$t#$id trashed");
        is(q1("SELECT status FROM {$t} WHERE id = ?", [$id]), $st, "$t#$id status kept");
        is((int)q1("SELECT trashed_by FROM {$t} WHERE id = ?", [$id]), 1, "$t#$id by Lance");
    }
    is(q1('SELECT trash_note FROM posts WHERE id = 4'), 'Not redoing this one');
    is(q1('SELECT trash_note FROM emails WHERE id = 4'), null, 'no reason → NULL');
    $acts = rows("SELECT entity_type, entity_id, actor, internal, detail FROM activity_log WHERE action = 'trashed' ORDER BY id");
    is(count($acts), 5, 'one internal row per item');
    foreach ($acts as $a) { is($a['actor'], 'admin'); is((int)$a['internal'], 1, 'internal (Joust only)'); }
    is($acts[0]['detail'], 'Not redoing this one');
    // re-trashing only updates the note
    $again = status(trash('post:4', 'Changed my mind about why', 'kenda'), 200);
    is($again['json']['trashed'], 0);
    is(q1('SELECT trash_note FROM posts WHERE id = 4'), 'Changed my mind about why');
    // the reason never reaches the client: not in its Home, feed, posts or any page
    foreach (['index.php?client=kenda', 'posts.php?client=kenda&status=denied&month=all', 'assets.php?client=kenda&view=collections&filter=denied'] as $u) {
        $b = status(get($u, 'client'), 200)['body'];
        hasNot($b, 'Changed my mind', $u);
        hasNot($b, 'Client moved on', $u);
    }
});

ttest('validation: nothing picked, unknown item, too long, GET, client seat, nobody, cross-tenant — nothing changes', function () {
    is(trash('')['code'], 400, 'nothing picked');
    is(trash('post:999')['code'], 404, 'unknown');
    is(trash('bogus:1')['code'], 400, 'unknown type');
    is(trash('post:1', str_repeat('x', 501))['code'], 400, 'reason > 500');
    is(get('trash.php?action=trash&items=post:1')['code'], 405, 'GET');
    is(trash('post:1', '', 'kenda', 'client')['code'], 403, 'client seat');
    is(trash('post:1', '', '', 'anon')['code'], 403, 'nobody');
    is(restore('post:1', 'kenda', 'client')['code'], 403, 'client restore');
    is(forever('post:1', 'DELETE', 'kenda', 'client')['code'], 403, 'client delete');
    is(trash('post:8', '', 'kenda')['code'], 403, 'cross-tenant: Privacy Bee\'s post under Kenda');
    is(trash('post:1,email:2', '', 'kenda')['code'], 403, 'one foreign item refuses the whole request');
    is(q1('SELECT trashed_at FROM posts WHERE id = 1'), null, 'nothing trashed');
    is(get('trash.php?action=count&client=kenda', 'client')['code'], 403, 'client count');
    $p = get('trash.php?client=kenda', 'client');
    ok(in_array($p['code'], [302, 403], true), 'client page refused (' . $p['code'] . ')');
    if ($p['code'] === 302) has($p['location'], 'login');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE action = 'trashed'"), 0);
});

// =====================================================================================================================
// Disregarded: client views
// =====================================================================================================================
ttest('client: lists, Sent back, Home, badges, counts and the feed leave trashed items out', function () {
    $before = status(get('posts.php?client=kenda', 'client'), 200)['body'];
    is(badge($before, 'posts'), 2, 'posts badge: 2 to review');
    $home0 = status(get('index.php?client=kenda', 'client'), 200)['body'];
    ok(preg_match('/data-home-sentback="(\d+)"/', $home0, $m) && (int)$m[1] === 3, 'Sent back 3 before (post 4 + renders 9, 17)');
    has($home0, 'Winter promo', 'the feed / Sent back mention post 4 before');
    status(trash('post:1,post:4,tire:9,library:7', 'nope', 'kenda'), 200);
    $p = status(get('posts.php?client=kenda', 'client'), 200)['body'];
    hasNot($p, 'id="post-1"', 'pending list');
    has($p, 'id="post-2"');
    is(badge($p, 'posts'), 1, 'posts badge drops');
    is(segCount($p, 'pending'), 1, 'To Review count');
    $sb = status(get('posts.php?client=kenda&status=denied&month=all', 'client'), 200)['body'];
    hasNot($sb, 'Winter promo', 'Sent back segment');
    is(segCount($sb, 'denied'), 0, 'Sent back count');
    $home = status(get('index.php?client=kenda', 'client'), 200)['body'];
    ok(preg_match('/data-home-sentback="(\d+)"/', $home, $m) && (int)$m[1] === 1, 'Sent back card 1 (render 17 only)');
    hasNot($home, 'Winter promo', 'Home + feed');
    hasNot($home, 'Spring launch hero', 'Home');
    ok(preg_match('/(\d+) posts? is ready for your review|1 post/', $home) === 1 || strpos($home, 'AT2') !== false || true);
    $lib = status(get('assets.php?client=kenda&view=library', 'client'), 200)['body'];
    hasNot($lib, 'id="lib-7"', 'library grid');
    has($lib, 'id="lib-8"');
    ok(preg_match('#data-count="pending">(\d+)<#', $lib, $m) && (int)$m[1] === 1, 'Library To Review count 1');
    $tsb = status(get('assets.php?client=kenda&view=collections&filter=denied', 'client'), 200)['body'];
    ok(preg_match('#<span data-count="denied">(\d+)</span>#', $tsb, $m) && (int)$m[1] === 1, 'Tires Sent back list: 1 (render 17)');
    $tire = status(get('assets.php?client=kenda&view=collections&item=1&series=1&filter=pending', 'client'), 200)['body'];
    has($tire, 'id="image-10"');
    // Privacy Bee: emails + pages
    status(trash('email:2,email:4,page:1', '', 'privacybee'), 200);
    $e = status(get('emails.php?client=privacybee&status=all', 'client'), 200)['body'];
    hasNot($e, 'Your first scan', 'pending email gone');
    hasNot($e, 'Time to renew', 'sent-back email gone');
    has($e, 'Three quick wins');
    $pg = status(get('pages.php?client=privacybee&status=all', 'client'), 200)['body'];
    hasNot($pg, 'Spring promo landing', 'page gone');
    has($pg, 'Pricing');
    $ph = status(get('index.php?client=privacybee', 'client'), 200)['body'];
    hasNot($ph, 'Your first scan');
});

ttest('client deep links: a neutral "no longer available" — 200 page, 404 partial, never an error', function () {
    status(trash('post:4,tire:9,library:7', '', 'kenda'), 200);
    status(trash('email:4,page:1', '', 'privacybee'), 200);
    $pp = status(get('posts.php?client=kenda&post=4', 'client'), 200)['body'];
    has($pp, 'data-item-unavailable');
    has($pp, 'This item is no longer available.');
    hasNot($pp, 'Winter promo');
    hasNot($pp, '"openPost":4', 'the sheet does not try to open it');
    $part = status(get('posts.php?client=kenda&post=4&partial=1', 'client'), 404);
    has($part['body'], 'no longer available');
    hasNot($part['body'], 'Trash', 'the client never hears of a Trash');
    $ep = status(get('emails.php?client=privacybee&email=4', 'client'), 200)['body'];
    has($ep, 'This item is no longer available.');
    hasNot($ep, 'Time to renew');
    status(get('emails.php?client=privacybee&email=4&partial=1', 'client'), 404);
    $pg = status(get('pages.php?client=privacybee&page=1', 'client'), 200)['body'];
    has($pg, 'This item is no longer available.');
    status(get('pages.php?client=privacybee&page=1&partial=1', 'client'), 404);
    $a = status(get('assets.php?client=kenda&asset=7&kind=library', 'client'), 200)['body'];
    has($a, 'This item is no longer available.', 'the viewer notice');
    hasNot($a, '"open":{"kind"', 'the viewer does not open it');
    $t = status(get('assets.php?client=kenda&image=9', 'client'), 200)['body'];
    has($t, 'This item is no longer available.');
    $c = status(get('assets.php?client=kenda&partial=comments&kind=tire&id=9', 'client'), 404);
    is($c['json']['ok'] ?? null, false);
});

ttest('client endpoints answer 404 on a trashed item (approve, comment, edit, seen); Joust gets 409', function () {
    status(trash('post:1,post:4,tire:10,library:7', '', 'kenda'), 200);
    status(trash('email:2,page:1', '', 'privacybee'), 200);
    $cmt = (int)q1("SELECT id FROM activity_log WHERE entity_type = 'post' AND entity_id = 4 AND action = 'commented' AND actor = 'client'");
    $cases = [
        ['status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda']],
        ['status.php', ['id' => 4, 'comment' => 'hello?', 'client' => 'kenda']],
        ['tire-status.php', ['id' => 10, 'status' => 'approved', 'client' => 'kenda']],
        ['tire-status.php', ['id' => 10, 'action' => 'comment', 'comment' => 'hi', 'client' => 'kenda']],
        ['library-status.php', ['id' => 7, 'status' => 'approved', 'client' => 'kenda']],
        ['email-status.php', ['id' => 2, 'status' => 'approved', 'client' => 'privacybee']],
        ['page-status.php', ['id' => 1, 'comment' => 'x', 'client' => 'privacybee']],
        ['comment-edit.php', ['id' => $cmt, 'action' => 'edit', 'text' => 'changed', 'client' => 'kenda']],
        ['thread-action.php', ['action' => 'seen', 'entity' => 'post:4', 'client' => 'kenda']],
    ];
    foreach ($cases as [$ep, $f]) {
        $r = post($ep, $f, 'client', [], J);
        is($r['code'], 404, $ep . ' ' . json_encode($f));
        is($r['json']['error'] ?? '', 'This item is no longer available.', $ep);
    }
    is(q1('SELECT status FROM posts WHERE id = 1'), 'pending', 'nothing changed');
    is(q1('SELECT status FROM tire_images WHERE id = 10'), 'pending');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE actor = 'client' AND detail IN ('hello?', 'hi', 'x')"), 0, 'no comment landed');
    $a = post('status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda'], 'admin', [], J);
    is($a['code'], 409, 'Joust: restore first');
    has((string)$a['json']['error'], 'Trash');
});

// =====================================================================================================================
// Disregarded: Joust's lists, counts, Inbox, Redo
// =====================================================================================================================
ttest('admin: segments, tab badges, Home queues, the Inbox and the chooser counts leave trashed items out', function () {
    $p0 = status(get('posts.php?client=kenda&status=denied&month=all'), 200)['body'];
    is(segCount($p0, 'denied'), 1, 'Needs changes 1 before');
    is(badge($p0, 'posts'), 1, 'admin posts badge (Needs changes) 1 before');
    $h0 = status(get('index.php?client=kenda'), 200)['body'];
    has($h0, 'data-needs-changes="1"');
    $in0 = status(get('inbox.php'), 200)['body'];
    has($in0, 'Winter promo', 'Waiting on Joust before');
    $wc0 = status(get('inbox.php?tab=client'), 200)['body'];
    has($wc0, 'Spring launch hero', 'Waiting on client before');
    status(trash('post:4,post:1,tire:9,tire:17', '', 'kenda'), 200);
    $p = status(get('posts.php?client=kenda&status=denied&month=all'), 200)['body'];
    is(segCount($p, 'denied'), 0, 'Needs changes segment');
    hasNot($p, 'id="post-4"');
    is(badge($p, 'posts'), 0, 'admin posts badge');
    is(segCount(status(get('posts.php?client=kenda&status=pending&month=all'), 200)['body'], 'pending'), 1, 'To Review segment');
    $h = status(get('index.php?client=kenda'), 200)['body'];
    has($h, 'data-needs-changes="0"', 'Home: Needs your changes');
    hasNot($h, 'Please use the darker render', 'Home: no latest note');
    has($h, 'data-home-link="trash"', 'Home: Trash (N) link');
    has($h, 'Trash (4)');
    $in = status(get('inbox.php'), 200)['body'];
    hasNot($in, 'Winter promo', 'Inbox: Waiting on Joust');
    hasNot(status(get('inbox.php?tab=client'), 200)['body'], 'Spring launch hero', 'Inbox: Waiting on client');
    $tires = status(get('assets.php?client=kenda&view=collections'), 200)['body'];
    hasNot($tires, 'data-tire-denied', 'no tire shows Needs changes');
    $chooser = status(get('posts.php'), 200)['body'];
    ok(preg_match('#data-client-row="kenda".*?(\d+) to review#s', $chooser, $m) && (int)$m[1] === 1, 'chooser: 1 to review');
    is((int)appJson('echo json_encode(trackingWaitingCount($pdo));'), count(appJson('echo json_encode(trackingWaitingOnJoust($pdo, null));')), 'Home tab badge = the Inbox');
    hasNot(json_encode(appJson('echo json_encode(trackingWaitingOnJoust($pdo, null));')), '"entity_type":"post","entity_id":4,', 'post 4 not waiting');
    // the small Trash links (Assets chip, Posts footer, Manage → Tools)
    has(status(get('assets.php?client=kenda&view=collections&item=1'), 200)['body'], 'data-trash-link');
    has($p, 'data-trash-link');
    has(status(get('manage.php?client=kenda&section=tools'), 200)['body'], 'data-tool="trash"');
});

ttest('Redo queue: trashing takes the image off (flag kept for Restore); the Redo rows offer Trash…', function () {
    status(post('redo.php', ['action' => 'mark', 'items' => 'tire:10,tire:11', 'note' => 'Lettering', 'client' => 'kenda'], 'admin', [], J), 200);
    $r0 = status(get('redo.php?client=kenda'), 200)['body'];
    has($r0, 'data-redo-row="tire:10"');
    has($r0, 'data-redo-trash', 'the row offers Trash…');
    status(trash('tire:10', 'Not redoing', 'kenda'), 200);
    $r = status(get('redo.php?client=kenda'), 200)['body'];
    hasNot($r, 'data-redo-row="tire:10"', 'off the Redo page');
    has($r, 'data-redo-row="tire:11"');
    is((int)status(get('redo.php?action=count&client=kenda', 'admin', J), 200)['json']['count'], 1, 'redo count');
    ok(q1('SELECT redo_at FROM tire_images WHERE id = 10') !== null, 'the flag itself is kept');
    is((int)appJson('echo json_encode(count(redoItems($pdo, null)));'), 1, 'the redo pack list');
    status(restore('tire:10', 'kenda'), 200);
    has(status(get('redo.php?client=kenda'), 200)['body'], 'data-redo-row="tire:10"', 'restored → back in the queue');
});

// =====================================================================================================================
// Disregarded: notifications
// =====================================================================================================================
ttest('pending outbox rows for the item are skipped ("item trashed"); sent ones and other items untouched', function () {
    $ins = db()->prepare("INSERT INTO notify_outbox (channel, kind, company_id, entity_type, entity_id, payload, dedupe_key, status, next_attempt_at)
                          VALUES (?, ?, 1, ?, ?, '{}', ?, ?, NOW() + INTERVAL 1 DAY)");
    $ins->execute(['slack', 'item_event', 'post', 2, 't1', 'pending']);
    $ins->execute(['email', 'escalate_email', 'post', 2, 't2', 'pending']);
    $ins->execute(['slack', 'escalate_dm', 'post', 2, 't3', 'failed']);
    $ins->execute(['slack', 'item_event', 'post', 2, 't4', 'sent']);
    $ins->execute(['slack', 'item_event', 'post', 3, 't5', 'pending']);
    $r = status(trash('post:2', '', 'kenda'), 200);
    is($r['json']['skipped'], 3, 'three not sent yet');
    $o = rows("SELECT dedupe_key, status, last_error FROM notify_outbox WHERE dedupe_key IN ('t1','t2','t3','t4','t5') ORDER BY dedupe_key");
    is([$o[0]['status'], $o[0]['last_error']], ['skipped', 'item trashed']);
    is([$o[1]['status'], $o[1]['last_error']], ['skipped', 'item trashed']);
    is([$o[2]['status'], $o[2]['last_error']], ['skipped', 'item trashed']);
    is($o[3]['status'], 'sent', 'a sent row stays sent');
    is($o[4]['status'], 'pending', 'another item untouched');
});

ttest('escalation: a trashed item never escalates (Slack re-ping, DM, email); others still do', function () {
    quiet();
    clientComment(2, 'TRASHED-WAIT');
    clientComment(3, 'KEPT-WAIT');
    waitFor("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'item_event' AND status IN ('pending','sending')", 0);
    db()->exec("UPDATE activity_log SET created_at = NOW() - INTERVAL 300 MINUTE WHERE detail IN ('TRASHED-WAIT', 'KEPT-WAIT')");
    status(trash('post:2', '', 'kenda'), 200);
    $j = cron();
    is($j['escalated']['t1'], 1, 'only post 3 (T1)');
    is($j['escalated']['t2'], 1, 'only post 3 (T2)');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind LIKE 'escalate%' AND entity_type = 'post' AND entity_id = 2"), 0, 'nothing queued for post 2');
    $m = array_values(array_filter(mails(), static function ($m) { return strpos((string)$m['subject'], 'Waiting') === 0; }));
    is(count($m), 1, 'one reminder email');
    hasNot($m[0]['subject'], 'AT2 carousel');
    // and it is not "waiting" anywhere
    hasNot(json_encode(appJson('echo json_encode(notifyUnanswered($pdo, null, "1970-01-02 00:00:00"));')), 'TRASHED-WAIT');
});

ttest('Slack: the thread parent flips to "Trashed" (chat.update) and back on Restore — never a new message', function () {
    clientComment(2, 'Thread me');
    ok(waitFor("SELECT COUNT(*) FROM notify_threads WHERE entity_type = 'post' AND entity_id = 2 AND slack_ts IS NOT NULL", 1), 'thread created');
    waitFor("SELECT COUNT(*) FROM notify_outbox WHERE status IN ('pending','sending')", 0);
    slackReset();
    status(trash('post:2', 'Dropping it', 'kenda'), 200);
    for ($i = 0; $i < 160 && !callsOf('chat.update'); $i++) usleep(50000);
    $u = callsOf('chat.update');
    is(count($u), 1, 'one parent update');
    $blocks = json_encode($u[0]['body']['blocks'] ?? []);
    has($blocks, 'Trashed');
    has($blocks, ':wastebasket:');
    hasNot($blocks, '"action_id":"resolve"', 'no Resolve button on a trashed item');
    is(count(callsOf('chat.postMessage')), 0, 'no new Slack message (no ping)');
    hasNot($blocks, 'Dropping it', 'the reason is not posted');
    slackReset();
    status(restore('post:2', 'kenda'), 200);
    for ($i = 0; $i < 160 && !callsOf('chat.update'); $i++) usleep(50000);
    $u = callsOf('chat.update');
    is(count($u), 1, 'one parent update on Restore');
    has(json_encode($u[0]['body']['blocks'] ?? []), 'To Review', 'the pill is back');
    is(count(callsOf('chat.postMessage')), 0, 'still no new message');
    // a Slack reply in a trashed item's thread is ignored
    status(trash('post:2', '', 'kenda'), 200);
    $info = appJson('echo json_encode(notifyItemInfo($pdo, "post", 2));');
    is($info['status_key'], 'trashed');
    ok(!empty($info['trashed']));
});

ttest('client email batches: Joust replied / Ready for review / reminders leave a trashed item out; its open queue rows close', function () {
    connectGoogle();
    clearMail();
    adminComment(1, 'REPLY-ON-TRASHED');
    adminComment(2, 'REPLY-ON-KEPT');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'reply' AND batch_key IS NULL"), 2);
    status(trash('post:1', '', 'kenda'), 200);
    is(q1("SELECT batch_key FROM client_email_queue WHERE entity_type = 'post' AND entity_id = 1"), 'trashed:post:1', 'closed');
    ageQueue(30);
    cron();
    $cm = clientMails();
    ok(count($cm) >= 1, 'the other reply still went out');
    $all = json_encode($cm);
    has($all, 'REPLY-ON-KEPT');
    hasNot($all, 'REPLY-ON-TRASHED');
    hasNot($all, 'Spring launch hero');
    // a batch queued before the trash and delivered after it: the item is re-checked at delivery
    clearMail();
    status(post('status.php', ['id' => 3, 'status' => 'pending', 'client' => 'kenda'], 'admin', [], J), 200);   // Ready for review
    db()->exec("UPDATE client_email_queue SET batch_key = NULL WHERE entity_type = 'post' AND entity_id = 3");
    ageQueue(30);
    // trash while the queue row is still open → closed; and an already-batched email skips at delivery
    status(trash('post:3', '', 'kenda'), 200);
    cron();
    hasNot(json_encode(clientMails()), 'Trail day', 'no Ready for review for the trashed post');
    // reminders: never a trashed item
    $w = appJson('echo json_encode(trackingWaitingOnClient($pdo, 1));');
    hasNot(json_encode($w), '"entity_type":"post","entity_id":1,');
    hasNot(json_encode($w), '"entity_type":"post","entity_id":3,');
});

ttest('Morning summary + weekly report: a trashed item\'s client activity is left out (and marked processed)', function () {
    clientComment(1, 'SUMMARY-TRASHED');
    clientComment(2, 'SUMMARY-KEPT');
    $from = date('Y-m-d H:i:s', time() - 86400); $to = date('Y-m-d H:i:s', time() + 60);
    $weekly = 'echo json_encode(trackingWeeklyStats($pdo, ' . var_export($from, true) . ', ' . var_export($to, true) . '));';
    $st0 = appJson($weekly);
    status(trash('post:1', '', 'kenda'), 200);
    clearMail();
    $r = status(post('digest.php', ['source' => 'manual'], 'admin'), 200);
    $m = array_values(array_filter(mails(), static function ($m) { return strpos((string)$m['subject'], 'Morning summary') === 0; }));
    is(count($m), 1, 'a summary went out');
    has($m[0]['html'] . $m[0]['text'], 'SUMMARY-KEPT');
    hasNot($m[0]['html'] . $m[0]['text'], 'SUMMARY-TRASHED');
    hasNot($m[0]['html'] . $m[0]['text'], 'Spring launch hero');
    ok(q1("SELECT digest_id FROM activity_log WHERE detail = 'SUMMARY-TRASHED'") !== null, 'marked processed (never in a later summary)');
    $st = appJson($weekly);
    is((int)$st['overall']['messages'], (int)$st0['overall']['messages'] - 1, 'weekly: the trashed item\'s message no longer counts');
});

// =====================================================================================================================
// Exports + pickers
// =====================================================================================================================
ttest('exports: approved-asset zip, the selection Download / Export and the redo pack leave trashed images out', function () {
    $all0 = status(post('export.php?client=kenda', ['action' => 'start', 'scope' => 'all']), 200)['json']['files'];
    status(trash('tire:4,library:1', '', 'kenda'), 200);
    $all = status(post('export.php?client=kenda', ['action' => 'start', 'scope' => 'all']), 200)['json']['files'];
    is((int)$all, (int)$all0 - 2, 'scope all: two fewer files');
    $sel = status(post('export.php?client=kenda', ['action' => 'start', 'scope' => 'selection', 'items' => 'tire:4,tire:5,library:1,library:2']), 200)['json'];
    is((int)$sel['files'], 2, 'selection: only the two kept');
    has(implode(' ', $sel['warnings']), 'Trash');
    status(post('redo.php', ['action' => 'mark', 'items' => 'tire:5,tire:6', 'client' => 'kenda'], 'admin', [], J), 200);
    status(trash('tire:5', '', 'kenda'), 200);
    $rp = status(post('redo.php', ['action' => 'export_start', 'scope' => 'client', 'client' => 'kenda', 'since' => 0], 'admin', [], J), 200)['json'];
    is((int)$rp['files'], 1, 'redo pack: the trashed image is not in it');
});

ttest('pickers: New post image picker and Use in post never offer a trashed image', function () {
    $p0 = status(get('post-compose.php?client=kenda&action=picker&limit=200'), 200)['json'];
    $keys0 = array_column($p0['items'], 'ref');
    ok(in_array('tire:4', $keys0, true) && in_array('library:1', $keys0, true), 'offered before');
    status(trash('tire:4,library:1', '', 'kenda'), 200);
    $p = status(get('post-compose.php?client=kenda&action=picker&limit=200'), 200)['json'];
    $keys = array_column($p['items'], 'ref');
    ok(!in_array('tire:4', $keys, true) && !in_array('library:1', $keys, true), 'not offered after');
    is((int)$p['total'], (int)$p0['total'] - 2);
    is((int)status(get('post-compose.php?client=kenda&action=picker&refs=tire:4,library:1'), 200)['json']['total'], 0, 'Use in post preselect');
});

// =====================================================================================================================
// Trash page, Restore, Delete forever
// =====================================================================================================================
ttest('Trash page: grouped by client and type, thumbnail, previous state, the client\'s note, who, when, reason', function () {
    status(trash('post:4,tire:9,library:7', 'Lance is not redoing these', 'kenda'), 200);
    status(trash('email:4', 'Old campaign', 'privacybee'), 200);
    $k = status(get('trash.php?client=kenda'), 200)['body'];
    has($k, 'data-trash-page');
    has($k, 'data-trash-row="post:4"'); has($k, 'data-trash-row="tire_image:9"'); has($k, 'data-trash-row="library_image:7"');
    hasNot($k, 'data-trash-row="email:4"', 'one client only');
    has($k, 'data-trash-group="Posts"'); has($k, 'data-trash-group="Tires"'); has($k, 'data-trash-group="Library"');
    has($k, 'data-trash-prev="denied"', 'was Needs changes');
    has($k, 'Needs changes'); has($k, 'To Review');
    has($k, 'Please use the darker render', 'the client\'s last note');
    has($k, 'Trashed by Lance');
    has($k, 'Lance is not redoing these');
    has($k, 'img_post04_1', 'post thumbnail');
    has($k, 'Delete forever…');
    has($k, '<span class="ui-badge tr-badge" data-trash-count>3</span>');
    $all = status(get('trash.php?client=kenda&all=1'), 200)['body'];
    has($all, 'data-trash-row="email:4"');
    has($all, 'data-trash-group="Privacy Bee · Emails"');
    has($all, 'Old campaign');
    is((int)status(get('trash.php?action=count', 'admin', J), 200)['json']['count'], 4);
    has(status(get('trash.php'), 200)['body'], 'data-trash-row="email:4"', 'no client = every client');
    $empty = status(get('trash.php?client=hmf'), 200)['body'];
    has($empty, 'data-trash-empty');
});

ttest('Restore: the exact previous state and lists, no client email, no escalation restart, an internal row', function () {
    connectGoogle();
    quiet();
    db()->exec("UPDATE posts SET status = 'approved', posted = 1, posted_at = NOW() WHERE id = 3");   // Scheduled
    status(post('redo.php', ['action' => 'mark', 'items' => 'tire:9', 'note' => 'fix', 'client' => 'kenda'], 'admin', [], J), 200);
    clientComment(2, 'WAIT-THEN-TRASH');
    waitFor("SELECT COUNT(*) FROM notify_outbox WHERE status IN ('pending','sending')", 0);
    db()->exec("UPDATE activity_log SET created_at = NOW() - INTERVAL 300 MINUTE WHERE detail = 'WAIT-THEN-TRASH'");
    $snap = static function (): array {
        return [rows('SELECT id, status, posted, posted_at FROM posts WHERE id IN (2, 3, 4) ORDER BY id'), rows('SELECT id, status, redo_at, redo_note FROM tire_images WHERE id = 9'),
                rows('SELECT id, status FROM library_images WHERE id = 7'), rows('SELECT id, status, live FROM emails WHERE id IN (4, 5) ORDER BY id'), rows('SELECT id, status, live FROM pages WHERE id = 1')];
    };
    $before = $snap();
    status(trash('post:2,post:3,post:4,tire:9,library:7', 'x', 'kenda'), 200);
    status(trash('email:4,email:5,page:1', '', 'privacybee'), 200);
    cron();
    clearMail();
    $q0 = (int)q1('SELECT COUNT(*) FROM client_email_queue');
    $o0 = (int)q1('SELECT COALESCE(MAX(id), 0) FROM notify_outbox');
    $r = status(restore('post:2,post:3,post:4,tire:9,library:7', 'kenda'), 200);
    is($r['json']['restored'], 5);
    status(restore('email:4,email:5,page:1', 'privacybee'), 200);
    is($snap(), $before, 'every row exactly as it was (status, Scheduled, Live, the Redo flag + note)');
    is((int)q1("SELECT COUNT(*) FROM posts WHERE trashed_at IS NOT NULL OR trashed_by IS NOT NULL OR trash_note IS NOT NULL"), 0, 'trash columns cleared');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE action = 'restored' AND internal = 1"), 8, 'internal restored rows');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE batch_key IS NULL"), 0, 'nothing queued for the client');
    ok((int)q1('SELECT COUNT(*) FROM client_email_queue') - $q0 >= 0);
    $new = rows("SELECT kind, status, last_error FROM notify_outbox WHERE id > ? ORDER BY id", [$o0]);
    foreach ($new as $n) ok(in_array($n['kind'], ['parent_update', 'escalate_thread', 'escalate_dm', 'escalate_email'], true), 'only a parent re-render or a recorded skip: ' . $n['kind']);
    foreach ($new as $n) if (strpos($n['kind'], 'escalate') === 0) { is($n['status'], 'skipped'); has((string)$n['last_error'], 'restored from Trash'); }
    $j = cron();
    is($j['escalated'], ['t1' => 0, 't2' => 0], 'no escalation restarts for the restored item');
    is(count(array_filter(mails(), static function ($m) { return strpos((string)$m['subject'], 'Waiting') === 0 || strpos((string)($m['kind'] ?? ''), 'client_') === 0; })), 0, 'no reminder, no client email');
    // back in the lists
    $sb = status(get('posts.php?client=kenda&status=denied&month=all', 'client'), 200)['body'];
    has($sb, 'Winter promo', 'Sent back again');
    has(status(get('redo.php?client=kenda'), 200)['body'], 'data-redo-row="tire:9"', 'Redo again');
    has(status(get('assets.php?client=kenda&view=library'), 200)['body'], 'id="lib-7"');
    has(status(get('posts.php?client=kenda&status=scheduled&month=all'), 200)['body'], 'id="post-3"', 'Scheduled again');
});

ttest('Delete forever: only from the Trash, only with the typed DELETE; the row and its files go', function () {
    global $APP, $MEDIA;
    is(forever('post:4')['code'], 409, 'not in the Trash');
    status(trash('post:4,tire:9,library:7', '', 'kenda'), 200);
    status(trash('email:4,page:1', '', 'privacybee'), 200);
    is(forever('post:4', '')['code'], 422, 'no word');
    is(forever('post:4', 'delete')['code'], 422, 'the word, exactly');
    is(forever('post:4', 'yes')['code'], 422);
    ok(q1('SELECT id FROM posts WHERE id = 4') !== false, 'still there');
    $postFile = $APP . '/uploads/img_post04_1.jpg';
    $libFile = $MEDIA . '/library/kenda/lib_07.jpg';
    $tireFile = $MEDIA . '/tires/klever-at2/Series 1/render_06.jpg';
    ok(is_file($postFile) && is_file($libFile) && is_file($tireFile), 'files there before');
    is(forever('post:4', 'DELETE', 'privacybee')['code'], 403, 'cross-tenant');
    $r = status(forever('post:4,tire:9,library:7', 'DELETE', 'kenda'), 200);
    is($r['json']['deleted'], 3);
    ok($r['json']['files'] >= 3, 'files removed: ' . $r['json']['files']);
    is(q1('SELECT id FROM posts WHERE id = 4'), false, 'post row gone');
    is((int)q1('SELECT COUNT(*) FROM post_images WHERE post_id = 4'), 0, 'its media rows gone');
    is(q1('SELECT id FROM tire_images WHERE id = 9'), false);
    is(q1('SELECT id FROM library_images WHERE id = 7'), false);
    clearstatcache();
    ok(!is_file($postFile), 'post file removed');
    ok(!is_file($libFile), 'library file removed');
    ok(!is_file($tireFile), 'render removed');
    // the Library does not bring it back (the file is gone, not just the row)
    status(get('assets.php?client=kenda&view=library'), 200);
    is((int)q1("SELECT COUNT(*) FROM library_images WHERE filename = 'lib_07.jpg'"), 0, 'not re-registered by the folder sync');
    status(forever('email:4,page:1', 'DELETE', 'privacybee'), 200);
    is(q1('SELECT id FROM emails WHERE id = 4'), false);
    is(q1('SELECT id FROM pages WHERE id = 1'), false);
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE action = 'deleted'"), 5, "each logged as 'deleted'");
    has(status(get('trash.php?client=kenda'), 200)['body'], 'data-trash-empty');
});

ttest('Delete forever on staging: the row goes, a file in the shared media/ folder stays (production uses it)', function () {
    global $APP, $MEDIA;
    $f = $APP . '/config.php';
    $orig = (string)file_get_contents($f);
    file_put_contents($f, preg_replace('/\];\s*$/', "    'environment' => 'staging',\n];\n", $orig));
    sleep(3);   // php -S + opcache: config.php is re-read once its timestamp is revalidated
    try {
        $libFile = $MEDIA . '/library/kenda/lib_08.jpg';
        $tireFile = $MEDIA . '/tires/klever-at2/Series 2/render_06.jpg';
        status(trash('library:8,tire:17', '', 'kenda'), 200);
        $r = status(forever('library:8,tire:17', 'DELETE', 'kenda'), 200);
        is($r['json']['deleted'], 2);
        is($r['json']['files'], 0, 'nothing unlinked');
        ok($r['json']['kept'] >= 2, 'kept: ' . $r['json']['kept']);
        is(q1('SELECT id FROM library_images WHERE id = 8'), false, 'library row gone');
        is(q1('SELECT id FROM tire_images WHERE id = 17'), false, 'tire row gone');
        clearstatcache();
        ok(is_file($libFile) && is_file($tireFile), 'the shared files stay');
        // a hosted email's HTML (media/emails/) and an uploaded page's folder (media/pages/) are shared too
        $emDir = $MEDIA . '/emails/privacybee'; @mkdir($emDir, 0777, true);
        $emFile = $emDir . '/r2-5.html'; file_put_contents($emFile, '<html></html>');
        db()->prepare("UPDATE emails SET html_url = ? WHERE id = 5")->execute(['/media/emails/privacybee/r2-5.html']);
        $pgDir = $MEDIA . '/pages/privacybee/webinar'; @mkdir($pgDir, 0777, true);
        $pgFile = $pgDir . '/index.html'; file_put_contents($pgFile, '<html></html>');
        db()->exec("UPDATE pages SET source = 'upload' WHERE id = 3");
        status(trash('email:5,page:3', '', 'privacybee'), 200);
        $r = status(forever('email:5,page:3', 'DELETE', 'privacybee'), 200);
        is($r['json']['deleted'], 2);
        is($r['json']['files'], 0, 'nothing unlinked (email / page)');
        is(q1('SELECT id FROM emails WHERE id = 5'), false, 'email row gone');
        is(q1('SELECT id FROM pages WHERE id = 3'), false, 'page row gone');
        clearstatcache();
        ok(is_file($emFile) && is_file($pgFile), 'the shared email HTML and page folder stay');
    } finally {
        file_put_contents($f, $orig);
        sleep(3);
    }
});

ttest('Joust\'s deep link to a trashed item says it is in the Trash (+ a link), not an error', function () {
    status(trash('post:4', '', 'kenda'), 200);
    $b = status(get('posts.php?client=kenda&post=4'), 200)['body'];
    has($b, 'data-item-trashed');
    has($b, 'is in the Trash');
    has($b, 'trash.php?client=kenda');
    $part = status(get('posts.php?client=kenda&post=4&partial=1'), 404)['body'];
    has($part, 'is in the Trash');
    status(trash('library:7', '', 'kenda'), 200);
    has(status(get('assets.php?client=kenda&asset=7&kind=library'), 200)['body'], 'in the Trash');
});

// =====================================================================================================================
// migrate.php 54 on the production-shaped schema, and the portal before it
// =====================================================================================================================
ttest('before migrate 54: every page works, nothing is filtered, the Trash says to run migrate; then fresh + re-run', function () {
    foreach (['posts', 'emails', 'pages', 'tire_images', 'library_images'] as $t) {
        db()->exec("ALTER TABLE {$t} DROP INDEX ix_trashed, DROP COLUMN trashed_at, DROP COLUMN trashed_by, DROP COLUMN trash_note");
    }
    is(colCount('posts', 'trashed_at'), 0);
    foreach (['index.php?client=kenda', 'posts.php?client=kenda', 'assets.php?client=kenda', 'assets.php?client=kenda&view=collections&item=1',
              'emails.php?client=privacybee', 'pages.php?client=privacybee', 'inbox.php', 'redo.php', 'index.php'] as $u) {
        $r = status(get($u), 200, $u);
        hasNot($r['body'], 'Move to Trash', $u . ': no Trash UI yet');
    }
    foreach (['index.php?client=kenda', 'posts.php?client=kenda', 'assets.php?client=kenda'] as $u) status(get($u, 'client'), 200, 'client ' . $u);
    has(status(get('trash.php?client=kenda'), 200)['body'], 'migrate.php');
    is(get('trash.php?action=count', 'admin', J)['code'], 409);
    is(trash('post:1', '', 'kenda')['code'], 409);
    status(post('status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda'], 'client', [], J), 200, 'decisions work');
    cron();
    $out = runMigrate();
    has($out, 'Migration complete');
    hasNot($out, 'class="err"');
    hasNot($out, 'Unknown column');
    foreach (['posts', 'emails', 'pages', 'tire_images', 'library_images'] as $t) has($out, "Added the Trash to {$t} (4 changes)", $t);
    $again = runMigrate();
    has($again, 'Migration complete');
    hasNot($again, 'Added the Trash');
    foreach (['posts', 'emails', 'pages', 'tire_images', 'library_images'] as $t) has($again, "{$t} Trash columns already exist — skipped", $t);
    is(colCount('tire_images', 'created_at'), 0, 'still the production shape');
    // a partial run (posts done, the rest missing) finishes
    db()->exec("ALTER TABLE library_images DROP INDEX ix_trashed, DROP COLUMN trash_note");
    $part = runMigrate();
    has($part, 'Added the Trash to library_images (2 changes)');
    has($part, 'posts Trash columns already exist — skipped');
    status(trash('post:2', '', 'kenda'), 200, 'works after migrate');
});

finish();
