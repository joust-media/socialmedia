<?php
/**
 * Comment editing (comment-edit-lib.php, comment-edit.php, migrate.php 53): clients edit / delete their own comments
 * (any time — also after Joust replied), Joust edits / deletes any comment; revisions keep every text; the "edited"
 * label and the "Comment deleted" placeholder; Slack (chat.update of the comment's own message, or a threaded note for
 * a comment without one); no escalation, no email; feeds, the Morning summary, Inbox, Home "Latest notes" and the redo
 * pack show the current text; internal notes stay Joust-only.
 *
 * Seed (tests/seed.php): post 4 (Kenda, Needs changes) carries the client's note "Please use the darker render" (90 min
 * old, unanswered); contacts 1 = Jane Kenda, 2 = ops@kenda (no name); Lance = admin_users 1 (U0LANCE); Kenda → C0KENDA.
 * Every test starts from the seed.
 */
require __DIR__ . '/lib.php';

const J = ['Accept' => 'application/json'];
const CRON_TK = 'test-cron-token-0123456789abcdef';

db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");
date_default_timezone_set('America/New_York');

function croot(): string { return rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test'), '/'); }
function reseedNow(): void {
    $app = getenv('APP_DIR') ?: croot() . '/site/portal';
    $media = getenv('MEDIA_DIR') ?: croot() . '/site/media';
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($app) . ' ' . escapeshellarg($media) . ' 2>&1', $out, $rc);
    if ($rc !== 0) throw new RuntimeException('seed failed: ' . implode("\n", $out));
}
function ctest(string $name, callable $fn): void { test($name, static function () use ($fn) { reseedNow(); $fn(); }); }
function slackCalls(): array {
    $f = croot() . '/slack-calls.jsonl';
    if (!is_file($f)) return [];
    return array_values(array_filter(array_map(static function ($l) { return json_decode($l, true); }, file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))));
}
function slackReset(): void { @unlink(croot() . '/slack-calls.jsonl'); }
function callsOf(string $method): array { return array_values(array_filter(slackCalls(), static function ($c) use ($method) { return $c['method'] === $method; })); }
function mails(): array {
    $out = [];
    foreach (glob(croot() . '/mail/*.json') ?: [] as $f) $out[] = json_decode((string)file_get_contents($f), true);
    return $out;
}
/** Wait (≤ 20 s) until a request sent with X-Test-Sync has completely finished (Slack is delivered after the response). */
function syncWait(string $token): void {
    $f = croot() . '/sessions/sync-' . $token;
    for ($i = 0; $i < 400 && !is_file($f); $i++) usleep(50000);
    if (!is_file($f)) throw new RuntimeException('request did not finish (no sync marker)');
    @unlink($f);
}
/** POST and wait for the after-response work (Slack delivery). */
function postSync(string $path, array $data, string $role): array {
    $t = bin2hex(random_bytes(8));
    $r = post($path, $data, $role, [], J + ['X-Test-Sync' => $t]);
    syncWait($t);
    return $r;
}
function edit(int $id, string $text, string $role = 'client', array $extra = []): array {
    return postSync('comment-edit.php', ['action' => 'edit', 'id' => $id, 'text' => $text, 'client' => 'kenda'] + $extra, $role);
}
function del(int $id, string $role = 'client', array $extra = []): array {
    return postSync('comment-edit.php', ['action' => 'delete', 'id' => $id, 'client' => 'kenda'] + $extra, $role);
}
/** A comment through the real endpoint → its activity id (status.php answers comment_id). */
function say(int $postId, string $text, string $role = 'client', array $extra = []): int {
    $r = status(postSync('status.php', ['id' => $postId, 'comment' => $text, 'client' => 'kenda'] + $extra, $role), 200, 'comment');
    $id = (int)($r['json']['comment_id'] ?? 0);
    ok($id > 0, 'the endpoint hands back the new comment id');
    is(q1("SELECT detail FROM activity_log WHERE id = ?", [$id]), $text);
    return $id;
}
const SEED_NOTE = 'Please use the darker render';
function seedNoteId(): int { return (int)q1("SELECT id FROM activity_log WHERE entity_type = 'post' AND entity_id = 4 AND action = 'commented'"); }
function postSheet(int $id, string $role): string { return status(get("posts.php?client=kenda&post={$id}&partial=1", $role), 200, "post {$id} sheet")['body']; }
/** The bubble <div class="pd-msg …" data-comment-id="$id" …> … of one comment ('' when absent). */
function bubble(string $html, int $id): string {
    return preg_match('#<div class="pd-msg[^"]*"[^>]*data-comment-id="' . $id . '"[^>]*>.*?</div></div>#s', $html, $m) ? $m[0] : '';
}

// ---------------------------------------------------------------------------------------------------------------------
test('migrate 53: edited_at / deleted_at on activity_log, comment_revisions, comment_slack; re-run is a no-op', function () {
    foreach (['edited_at', 'deleted_at'] as $c) {
        is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = ?", [$c]), 1, "activity_log.$c");
    }
    foreach (['comment_revisions', 'comment_slack'] as $t) {
        is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]), 1, $t);
    }
    // the activity_log columns the code relies on are the ones production has (migrate 13 / 37 / 44 / 53) — no others
    $cols = array_column(rows("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' ORDER BY ORDINAL_POSITION"), 'COLUMN_NAME');
    foreach (['id', 'company_id', 'entity_type', 'entity_id', 'action', 'actor', 'author_user_id', 'internal', 'batch_id', 'summary', 'detail', 'digest_id', 'created_at', 'client_contact_id', 'edited_at', 'deleted_at'] as $c) {
        ok(in_array($c, $cols, true), "activity_log.$c exists");
    }
    is(q1("SELECT COUNT(*) FROM meta WHERE k = 'comment_slack_backfill'"), 1, 'the Slack backfill ran once');
});

ctest('client edits their own comment AFTER Joust replied (no time limit): text in place, revision kept, "edited" label', function () {
    $id = seedNoteId();
    say(4, 'On it — darker render Friday', 'admin');   // Joust answered
    $r = status(edit($id, 'Please use the darker render, and a warmer sky'), 200, 'client edit');
    is($r['json']['ok'], true);
    is($r['json']['edited'], true);
    is(q1("SELECT detail FROM activity_log WHERE id = ?", [$id]), 'Please use the darker render, and a warmer sky');
    ok(q1("SELECT edited_at FROM activity_log WHERE id = ?", [$id]) !== null, 'edited_at set');
    is(q1("SELECT created_at FROM activity_log WHERE id = ?", [$id]) !== null, true);
    $rev = rows("SELECT * FROM comment_revisions WHERE activity_id = ?", [$id]);
    is(count($rev), 1, 'one revision');
    is($rev[0]['kind'], 'edit');
    is($rev[0]['old_detail'], SEED_NOTE, 'the original text is kept');
    is($rev[0]['new_detail'], 'Please use the darker render, and a warmer sky');
    is($rev[0]['actor'], 'client');
    is((int)$rev[0]['client_contact_id'], 1, 'who: Jane (contact 1)');
    // the "edited" label: the client's own view and Joust's
    $b = bubble(postSheet(4, 'admin'), $id);
    ok($b !== '', 'the bubble is in the admin thread');
    has($b, 'data-comment-edited', 'edited label');
    has($b, '>edited</button>');
    has($b, 'by Jane Kenda (Kenda Tires)', 'Joust sees who edited');
    has($b, 'Please use the darker render, and a warmer sky');
    // any contact of the company may edit a colleague's comment: ops@ (contact 2) wrote it, Jane edits — and is named
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, client_contact_id) VALUES (1, 'post', 1, 'commented', 'client', 'c', 'From ops', 2)");
    $ops = (int)db()->lastInsertId();
    status(edit($ops, 'From ops, tidied by Jane'), 200, 'a colleague’s comment');
    is((int)q1("SELECT client_contact_id FROM comment_revisions WHERE activity_id = ?", [$ops]), 1, 'the revision names Jane');
    is((int)q1("SELECT client_contact_id FROM activity_log WHERE id = ?", [$ops]), 2, 'the comment stays ops@’s');
    has(bubble(postSheet(1, 'admin'), $ops), 'by Jane Kenda (Kenda Tires)');
});

ctest('client deletes their own comment after a reply: "Comment deleted" in place, original only for Joust (Show original)', function () {
    $id = seedNoteId();
    $reply = say(4, 'Darker render coming', 'admin');
    $r = status(del($id), 200, 'client delete');
    is($r['json']['deleted'], true);
    is(q1("SELECT detail FROM activity_log WHERE id = ?", [$id]), '', 'the row stays (thread order), its text is gone');
    ok(q1("SELECT deleted_at FROM activity_log WHERE id = ?", [$id]) !== null);
    is(q1("SELECT old_detail FROM comment_revisions WHERE activity_id = ? AND kind = 'delete'", [$id]), SEED_NOTE, 'revision keeps the original');
    $admin = postSheet(4, 'admin');
    $b = bubble($admin, $id);
    has($b, 'Comment deleted');
    has($b, 'data-comment-original', 'Joust can expand the original');
    has($b, SEED_NOTE);
    ok(strpos($admin, 'data-comment-id="' . $id . '"') < strpos($admin, 'data-comment-id="' . $reply . '"'), 'placeholder keeps its place before the reply');
    // the comment count is the live ones
    ok((bool)preg_match('#data-comment-count>1<#', $admin), 'count excludes the deleted comment');
    // the client's own view: the hidden-post notice drops the note (post 4 is in Needs changes = hidden for the client)
    $client = postSheet(4, 'client');
    hasNot($client, SEED_NOTE, 'the client never gets the deleted text back');
    // deleting twice is harmless
    status(del($id), 200, 'idempotent');
    is((int)q1("SELECT COUNT(*) FROM comment_revisions WHERE activity_id = ?", [$id]), 1);
    // and a deleted comment cannot be edited
    is(edit($id, 'resurrect')['code'], 409);
});

ctest('client edits / deletes in a visible thread: "edited" label + placeholder on the client seat too', function () {
    $id = say(1, 'Can we crop tighter?');
    status(edit($id, 'Can we crop a little tighter?'), 200);
    $b = bubble(postSheet(1, 'client'), $id);
    has($b, 'data-comment-can="edit"', 'the client can edit its own comment');
    has($b, 'data-comment-more', 'the ⋯');
    has($b, '>edited</button>');
    has($b, 'Edited ', 'when');
    has($b, 'by you', 'who (the client reads "you")');
    $id2 = say(1, 'Ignore this one');
    status(del($id2), 200);
    $html = postSheet(1, 'client');
    has(bubble($html, $id2), 'Comment deleted');
    hasNot($html, 'Ignore this one');
    hasNot(bubble($html, $id2), 'data-comment-more', 'no menu on a deleted comment for the client');
    hasNot(bubble($html, $id2), 'data-comment-original', 'no original for the client');
});

ctest('client cannot edit or delete Joust comments (403), internal notes (404) or another client’s comments (403); anon 401', function () {
    $joust = say(1, 'Joust reply', 'admin');
    $internal = say(1, 'SECRET internal margin note', 'admin', ['internal' => 1]);
    is(edit($joust, 'hijack')['code'], 403, 'Joust’s comment');
    is(del($joust)['code'], 403);
    is(edit($internal, 'hijack')['code'], 404, 'an internal note does not exist for the client');
    is(del($internal)['code'], 404);
    is(q1("SELECT detail FROM activity_log WHERE id = ?", [$joust]), 'Joust reply');
    is(q1("SELECT detail FROM activity_log WHERE id = ?", [$internal]), 'SECRET internal margin note');
    // Privacy Bee's session on Kenda's comment: refused (posting either slug)
    $kenda = say(1, 'Kenda only');
    is(post('comment-edit.php', ['action' => 'edit', 'id' => $kenda, 'text' => 'x', 'client' => 'privacybee'], 'client:privacybee', [], J)['code'], 403, 'own slug, foreign comment');
    is(post('comment-edit.php', ['action' => 'edit', 'id' => $kenda, 'text' => 'x', 'client' => 'kenda'], 'client:privacybee', [], J)['code'], 403, 'foreign slug');
    is(post('comment-edit.php', ['action' => 'delete', 'id' => $kenda, 'client' => 'privacybee'], 'client:privacybee', [], J)['code'], 403);
    is(q1("SELECT detail FROM activity_log WHERE id = ?", [$kenda]), 'Kenda only');
    is(post('comment-edit.php', ['action' => 'edit', 'id' => $kenda, 'text' => 'x'], 'anon', [], J)['code'], 401, 'anonymous');
    // a draft's comments do not exist for the client
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail) VALUES (1, 'post', 6, 'commented', 'client', 'c', 'on a draft')");
    $draft = (int)db()->lastInsertId();
    is(edit($draft, 'x')['code'], 404);
    // history is Joust's
    is(post('comment-edit.php', ['action' => 'history', 'id' => $kenda, 'client' => 'kenda'], 'client', [], J)['code'], 403);
    // cross-site and GET refused
    is(post('comment-edit.php', ['action' => 'edit', 'id' => $kenda, 'text' => 'x', 'client' => 'kenda'], 'client', [], J + ['Sec-Fetch-Site' => 'cross-site'])['code'], 403, 'cross-site');
    is(get('comment-edit.php?client=kenda&action=edit&id=' . $kenda, 'client')['code'], 405);
    // the client's markup: no menu on Joust's bubble, the internal note absent altogether
    $html = postSheet(1, 'client');
    hasNot(bubble($html, $joust), 'data-comment-can', 'no edit on Joust’s bubble');
    hasNot($html, 'SECRET internal margin note');
    hasNot($html, 'data-comment-id="' . $internal . '"');
});

ctest('admin edits and deletes any comment: a client’s, Joust’s, an internal note; History lists every revision', function () {
    $client = say(2, 'Client wording');
    $joust = say(2, 'Joust wording', 'admin');
    $internal = say(2, 'Internal wording', 'admin', ['internal' => 1]);
    status(edit($client, 'Client wording (fixed typo by Joust)', 'admin'), 200, 'admin edits the client’s comment');
    status(edit($joust, 'Joust wording v2', 'admin'), 200);
    status(edit($internal, 'Internal wording v2', 'admin'), 200);
    is((int)q1("SELECT internal FROM activity_log WHERE id = ?", [$internal]), 1, 'still internal');
    status(del($joust, 'admin'), 200);
    $rev = rows("SELECT * FROM comment_revisions WHERE activity_id = ? ORDER BY id", [$client]);
    is($rev[0]['actor'], 'admin');
    is((int)$rev[0]['author_user_id'], 1, 'Lance');
    // the client reads "edited … by Lance at Joust"
    has(bubble(postSheet(2, 'client'), $client), 'by Lance at Joust');
    // History (admin): posted → edit → delete, every text
    $h = status(post('comment-edit.php', ['action' => 'history', 'id' => $joust, 'client' => 'kenda'], 'admin', [], J), 200)['json'];
    is(array_column($h['items'], 'kind'), ['posted', 'edit', 'delete']);
    is($h['items'][0]['text'], 'Joust wording');
    is($h['items'][1]['text'], 'Joust wording v2');
    is($h['items'][2]['old'], 'Joust wording v2');
    // the admin bubble offers History once there is one
    has(bubble(postSheet(2, 'admin'), $client), 'data-comment-history="1"');
    // internal notes edited by Joust stay out of the client's thread
    hasNot(postSheet(2, 'client'), 'Internal wording');
});

ctest('validation: non-empty, ≤ 2000 bytes, a Needs-changes note keeps 3 characters; unchanged = no revision', function () {
    $id = say(1, 'Original');
    is(edit($id, '   ')['code'], 422, 'empty');
    is(edit($id, str_repeat('x', 2001))['code'], 400, 'too long');
    status(edit($id, str_repeat('y', 2000)), 200, '2000 is fine');
    status(edit($id, str_repeat('y', 2000)), 200, 'unchanged');
    is((int)q1("SELECT COUNT(*) FROM comment_revisions WHERE activity_id = ?", [$id]), 1, 'an unchanged save adds no revision');
    // a Needs-changes note (denied + comment in one batch)
    status(post('status.php', ['id' => 1, 'status' => 'denied', 'comment' => 'Wrong logo colour', 'client' => 'kenda'], 'client', [], J), 200);
    $note = (int)q1("SELECT id FROM activity_log WHERE entity_type = 'post' AND entity_id = 1 AND action = 'commented' ORDER BY id DESC LIMIT 1");
    is(edit($note, 'ok')['code'], 422, 'a decision note needs 3 characters');
    status(edit($note, 'Wrong logo colour — use the navy one'), 200, 'the client edits its Needs-changes note');
    // the hidden-post notice (post 1 is now hidden for the client) shows the current note, editable
    $html = postSheet(1, 'client');
    ok((bool)preg_match('#data-hidden-note data-comment-id="' . $note . '" data-comment-host="note" data-comment-can="edit"#', $html), 'the note is editable in place');
    has($html, 'Wrong logo colour — use the navy one');
    has($html, 'data-comment-edited-tag');
    // Joust's Needs-changes banner shows the current text
    has(postSheet(1, 'admin'), 'Wrong logo colour — use the navy one');
});

ctest('the [Slide N] tag: kept on edit, changed with slide=N, removed with slide=0', function () {
    $id = say(2, '[Slide 2] Brighter please');
    $r = status(edit($id, 'Much brighter please'), 200);
    is(q1("SELECT detail FROM activity_log WHERE id = ?", [$id]), '[Slide 2] Much brighter please', 'tag kept');
    is($r['json']['slide'], 2);
    has($r['json']['html'], 'pd-slide-chip', 'the re-rendered bubble keeps its chip');
    status(edit($id, 'Much brighter please', 'client', ['slide' => 3]), 200);
    is(q1("SELECT detail FROM activity_log WHERE id = ?", [$id]), '[Slide 3] Much brighter please', 'moved to slide 3');
    status(edit($id, 'Much brighter please', 'client', ['slide' => 0]), 200);
    is(q1("SELECT detail FROM activity_log WHERE id = ?", [$id]), 'Much brighter please', 'tag removed');
    // the thread tells the editor how many slides there are
    ok((bool)preg_match('#data-thread data-count="\d+" data-slides="3"#', postSheet(2, 'client')), 'data-slides on the carousel thread');
});

ctest('Slack: an edit chat.updates the comment’s own message (stored ts, " (edited)"); a delete leaves "_comment deleted_"', function () {
    slackReset();
    $id = say(1, 'First thought');
    $map = rows("SELECT * FROM comment_slack WHERE activity_id = ?", [$id]);
    is(count($map), 1, 'the message ts is stored per comment');
    is($map[0]['slack_channel'], 'C0KENDA');
    $reply = callsOf('chat.postMessage')[1] ?? null;   // [0] = the thread parent, [1] = the comment
    ok($reply !== null && strpos($reply['body']['text'], 'First thought') !== false, 'the comment went to Slack');
    slackReset();
    status(edit($id, 'Second thought'), 200);
    $u = callsOf('chat.update');
    is(count($u), 1, 'one chat.update');
    is($u[0]['body']['ts'], $map[0]['slack_ts'], 'the stored ts');
    is($u[0]['body']['channel'], 'C0KENDA');
    has($u[0]['body']['text'], 'Second thought _(edited)_');
    hasNot($u[0]['body']['text'], 'First thought');
    is(count(callsOf('chat.postMessage')), 0, 'no new message');
    slackReset();
    status(del($id), 200);
    $u = callsOf('chat.update');
    ok(count($u) >= 1, 'chat.update on delete');
    is($u[0]['body']['ts'], $map[0]['slack_ts']);
    is($u[0]['body']['text'], '_comment deleted_');
    is(count(callsOf('chat.postMessage')), 0);
});

ctest('Slack: a Needs-changes message re-renders with the edited note; an internal note’s message too', function () {
    slackReset();
    status(postSync('status.php', ['id' => 1, 'status' => 'denied', 'comment' => 'Wrong logo colour', 'client' => 'kenda'], 'client'), 200);
    $note = (int)q1("SELECT id FROM activity_log WHERE entity_type = 'post' AND entity_id = 1 AND action = 'commented' ORDER BY id DESC LIMIT 1");
    $ts = q1("SELECT slack_ts FROM comment_slack WHERE activity_id = ?", [$note]);
    ok($ts, 'mapped');
    slackReset();
    status(edit($note, 'Wrong logo colour — navy please'), 200);
    $u = callsOf('chat.update')[0] ?? null;
    ok($u !== null, 'chat.update');
    is($u['body']['ts'], $ts);
    has($u['body']['text'], 'requested changes', 'the decision line stays');
    has($u['body']['text'], 'navy please _(edited)_');
    // an internal note written in the portal is posted marked internal; its edit updates that message
    slackReset();
    $int = say(1, 'Check the brand book', 'admin', ['internal' => 1]);
    $its = q1("SELECT slack_ts FROM comment_slack WHERE activity_id = ? AND kind = 'internal_note'", [$int]);
    ok($its, 'internal note mapped');
    slackReset();
    status(edit($int, 'Check the 2026 brand book', 'admin'), 200);
    $u = callsOf('chat.update')[0] ?? null;
    ok($u !== null && $u['body']['ts'] === $its, 'chat.update of the internal note');
    has($u['body']['text'], 'Internal note');
    has($u['body']['text'], 'Check the 2026 brand book _(edited)_');
});

ctest('Slack fallback: an older comment with no stored ts gets a short threaded note; Joust’s own replies never go to Slack', function () {
    // the seeded note (post 4) predates comment_slack; the item already has a Slack thread
    db()->exec("INSERT INTO notify_threads (company_id, entity_type, entity_id, slack_channel, slack_ts) VALUES (1, 'post', 4, 'C0KENDA', '1699999999.000100')");
    slackReset();
    status(edit(seedNoteId(), 'Please use the darker render — the March one'), 200);
    $m = callsOf('chat.postMessage');
    is(count($m), 1, 'one threaded note');
    is($m[0]['body']['thread_ts'], '1699999999.000100', 'in the item’s thread');
    has($m[0]['body']['text'], '✏️ *Jane Kenda (Kenda Tires)* edited a comment');
    has($m[0]['body']['text'], 'the March one');
    // no thread yet: nothing is posted for an edit (a thread is never started for one)
    db()->exec("DELETE FROM notify_threads");
    slackReset();
    status(edit(seedNoteId(), 'Darker render, the March one'), 200);
    is(count(slackCalls()), 0, 'no thread → nothing');
    // Joust's own portal reply: never in Slack, so editing it is silent too
    db()->exec("INSERT INTO notify_threads (company_id, entity_type, entity_id, slack_channel, slack_ts) VALUES (1, 'post', 4, 'C0KENDA', '1699999999.000100')");
    $j = say(4, 'Joust answer', 'admin');
    slackReset();
    status(edit($j, 'Joust answer, edited', 'admin'), 200);
    is(count(callsOf('chat.postMessage')), 0, 'no note for Joust’s reply');
    is(count(array_filter(callsOf('chat.update'), static function ($c) { return ($c['body']['ts'] ?? '') !== '1699999999.000100'; })), 0, 'no message update');
});

ctest('edits trigger no escalation, no client email and no Slack event: only the comment_edit job', function () {
    // the seeded note is 90 min old and unanswered: the cron escalates it once
    status(get('notify-cron.php', 'anon', ['X-Notify-Token' => CRON_TK]), 200);
    $esc = (int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind LIKE 'escalate%'");
    ok($esc > 0, 'the seeded wait escalated');
    $first = q1("SELECT MIN(created_at) FROM activity_log WHERE entity_type = 'post' AND entity_id = 4 AND action = 'commented'");
    status(edit(seedNoteId(), 'Darker render please (edited)'), 200);
    status(get('notify-cron.php', 'anon', ['X-Notify-Token' => CRON_TK]), 200);
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind LIKE 'escalate%'"), $esc, 'no new escalation after the edit');
    is(q1("SELECT MIN(created_at) FROM activity_log WHERE entity_type = 'post' AND entity_id = 4 AND action = 'commented'"), $first, 'the wait still starts at the original message');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE action = 'commented' AND entity_type = 'post' AND entity_id = 4"), 1, 'no new comment row');
    // Joust edits its reply: no "Joust replied" client email (a new reply would queue one)
    $reply = say(1, 'A reply', 'admin');
    $queued = (int)q1("SELECT COUNT(*) FROM client_email_queue");
    ok($queued >= 1, 'the reply itself queued a client email');
    $mails = count(mails());
    status(edit($reply, 'A reply, reworded', 'admin'), 200);
    status(del($reply, 'admin'), 200);
    $cid = say(1, 'client says');
    $events = (int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind IN ('item_event','nochannel_email')");
    status(edit($cid, 'client says, edited'), 200);
    is((int)q1("SELECT COUNT(*) FROM client_email_queue"), $queued, 'edits queue no client email');
    is(count(mails()), $mails, 'no email sent');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind IN ('item_event','nochannel_email')"), $events, 'no new Slack event / owner email');
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'comment_edit'") >= 1, true, 'only the edit job');
});

ctest('unread: a client edit marks the thread updated for Joust (the dot), Joust’s own edit does not', function () {
    $id = say(1, 'Question');
    status(post('thread-action.php', ['action' => 'seen', 'entity' => 'post:1'], 'admin', [], J), 200);
    hasNot(get('posts.php?client=kenda&status=pending', 'admin')['body'], 'data-unread-for="post:1"', 'read');
    status(edit($id, 'Question, rephrased'), 200);
    has(get('posts.php?client=kenda&status=pending', 'admin')['body'], 'data-unread-for="post:1"', 'the client edit is news');
    status(post('thread-action.php', ['action' => 'seen', 'entity' => 'post:1'], 'admin', [], J), 200);
    status(edit($id, 'Question, rephrased by Joust', 'admin'), 200);
    hasNot(get('posts.php?client=kenda&status=pending', 'admin')['body'], 'data-unread-for="post:1"', 'Joust’s own edit is not');
});

ctest('feeds, Inbox, Home "Latest notes" and the Morning summary show the current text; deleted comments disappear; the edit event is Joust-only', function () {
    $id = say(1, 'OLDTEXT question about the crop');
    status(edit($id, 'NEWTEXT question about the crop'), 200);
    $home = get('index.php?client=kenda', 'admin')['body'];
    has($home, 'NEWTEXT question', 'Home');
    hasNot($home, 'OLDTEXT', 'Home: never the old text');
    has($home, 'edited a comment on', 'Joust’s feed records the edit');
    $inbox = get('inbox.php', 'admin')['body'];
    has($inbox, 'NEWTEXT question', 'Inbox snippet');
    hasNot($inbox, 'OLDTEXT');
    $cl = get('index.php?client=kenda', 'client')['body'];
    hasNot($cl, 'edited a comment', 'not in the client’s feed');
    hasNot($cl, 'OLDTEXT');
    // the Morning summary
    status(post('digest.php', ['source' => 'manual'], 'admin'), 200);
    $m = array_values(array_filter(mails(), static function ($m) { return strpos((string)$m['subject'], 'Morning summary') === 0; }));
    is(count($m), 1, 'a summary');
    has($m[0]['html'], 'NEWTEXT question');
    hasNot($m[0]['html'] . $m[0]['text'], 'OLDTEXT');
    hasNot($m[0]['html'], 'edited a comment', 'the edit event is not client news');
    // a deleted comment leaves every reader
    reseedNow();
    $gone = say(1, 'GONETEXT never mind');
    status(del($gone), 200);
    foreach (['index.php?client=kenda' => 'admin', 'inbox.php' => 'admin'] as $page => $seat) hasNot(get($page, $seat)['body'], 'GONETEXT', $page);
    hasNot(get('index.php?client=kenda', 'client')['body'], 'GONETEXT', 'client Home');
    status(post('digest.php', ['source' => 'manual'], 'admin'), 200);
    foreach (mails() as $mm) hasNot((string)($mm['html'] ?? '') . (string)($mm['text'] ?? ''), 'GONETEXT', 'summary');
});

ctest('the redo pack’s .txt carries the edited Needs-changes note, never the old one', function () {
    status(post('tire-status.php', ['id' => 10, 'status' => 'denied', 'comment' => 'OLDNOTE tread pattern', 'client' => 'kenda'], 'client', [], J), 200);
    $note = (int)q1("SELECT id FROM activity_log WHERE entity_type = 'tire_image' AND entity_id = 10 AND action = 'commented' ORDER BY id DESC LIMIT 1");
    status(edit($note, 'NEWNOTE tread pattern is the 2025 one'), 200);
    is(q1("SELECT client_comment FROM tire_images WHERE id = 10"), 'NEWNOTE tread pattern is the 2025 one', 'client_comment follows the edit');
    $r = status(post('redo.php', ['action' => 'export_start', 'scope' => 'client', 'client' => 'kenda', 'since' => 0], 'admin', [], J), 200);
    $job = $r['json']['job'];
    for ($i = 0; $i < 200; $i++) { $s = status(post('redo.php', ['action' => 'export_step', 'job' => $job], 'admin', [], J), 200); if (!empty($s['json']['done'])) break; }
    $d = status(get('redo.php?action=download&job=' . $job), 200)['body'];
    $zip = sys_get_temp_dir() . '/cedit_pack_' . $job . '.zip';
    file_put_contents($zip, $d);
    $z = new ZipArchive();
    ok($z->open($zip) === true);
    $txt = '';
    for ($i = 0; $i < $z->numFiles; $i++) if (substr($z->getNameIndex($i), -4) === '.txt') $txt .= $z->getFromIndex($i);
    $z->close();
    @unlink($zip);
    has($txt, 'NEWNOTE tread pattern is the 2025 one');
    hasNot($txt, 'OLDNOTE', 'never the old text (no legacy client_comment either)');
});

ctest('every thread component renders the editable markup: email, page, library image, tire image', function () {
    // Privacy Bee email 2 (pending) + page 1 (pending)
    $e = (int)q1("SELECT id FROM emails WHERE company_id = 2 AND status = 'pending' LIMIT 1");
    $r = status(postSync('email-status.php', ['id' => $e, 'comment' => 'Email note', 'client' => 'privacybee'], 'client:privacybee'), 200);
    $eid = (int)$r['json']['comment_id'];
    ok($eid > 0, 'email-status hands back the id');
    has(bubble(get("emails.php?client=privacybee&email={$e}&partial=1", 'client:privacybee')['body'], $eid), 'data-comment-can="edit"', 'email thread');
    status(postSync('comment-edit.php', ['action' => 'edit', 'id' => $eid, 'text' => 'Email note v2', 'client' => 'privacybee'], 'client:privacybee'), 200);
    has(get("emails.php?client=privacybee&email={$e}&partial=1", 'client:privacybee')['body'], 'Email note v2');
    $r = status(postSync('page-status.php', ['id' => 1, 'comment' => 'Page note', 'client' => 'privacybee'], 'client:privacybee'), 200);
    $pid = (int)$r['json']['comment_id'];
    has(bubble(get("pages.php?client=privacybee&page=1&partial=1", 'client:privacybee')['body'], $pid), 'data-comment-can="edit"', 'page thread');
    // the admin add-email / add-page threads
    has(get("add-email.php?client=privacybee&edit={$e}", 'admin')['body'], 'data-comment-id="' . $eid . '"', 'add-email thread');
    has(get("add-page.php?client=privacybee&edit=1", 'admin')['body'], 'data-comment-id="' . $pid . '"', 'add-page thread');
    // the media viewer's threads (library + tire)
    $r = status(postSync('library-status.php', ['id' => 7, 'action' => 'comment', 'comment' => 'Library note', 'client' => 'kenda'], 'client'), 200);
    $lid = (int)$r['json']['comment_id'];
    ok($lid > 0, 'library-status hands back the id');
    $t = status(get('assets.php?client=kenda&partial=comments&kind=library&id=7', 'client', J), 200)['json'];
    has((string)$t['html'], 'data-comment-id="' . $lid . '"');
    has((string)$t['html'], 'data-comment-can="edit"');
    status(postSync('comment-edit.php', ['action' => 'delete', 'id' => $lid, 'client' => 'kenda'], 'client'), 200);
    $t = status(get('assets.php?client=kenda&partial=comments&kind=library&id=7', 'client', J), 200)['json'];
    has((string)$t['html'], 'Comment deleted', 'viewer placeholder');
    is((int)$t['count'], 0, 'the viewer count excludes it');
    $r = status(postSync('tire-status.php', ['id' => 11, 'action' => 'comment', 'comment' => 'Tire note', 'client' => 'kenda'], 'client'), 200);
    $tid = (int)$r['json']['comment_id'];
    ok($tid > 0, 'tire-status hands back the id');
    status(postSync('comment-edit.php', ['action' => 'edit', 'id' => $tid, 'text' => 'Tire note v2', 'client' => 'kenda'], 'client'), 200);
    $t = status(get('assets.php?client=kenda&partial=comments&kind=tire&id=11', 'client', J), 200)['json'];
    has((string)$t['html'], 'Tire note v2');
    has((string)$t['html'], '>edited</button>');
});

ctest('before migrate 53 the portal still works (runtime probe): threads render, editing answers 409', function () {
    db()->exec("ALTER TABLE activity_log DROP COLUMN edited_at, DROP COLUMN deleted_at");
    try {
        $html = postSheet(4, 'admin');
        has($html, SEED_NOTE, 'the thread still renders');
        hasNot($html, 'data-comment-can', 'no edit controls without the columns');
        status(get('index.php?client=kenda', 'admin'), 200);
        status(get('posts.php?client=kenda', 'client'), 200);
        is(post('comment-edit.php', ['action' => 'edit', 'id' => seedNoteId(), 'text' => 'x', 'client' => 'kenda'], 'client', [], J)['code'], 409);
        status(post('status.php', ['id' => 1, 'comment' => 'still works', 'client' => 'kenda'], 'client', [], J), 200, 'commenting still works');
    } finally {
        db()->exec("ALTER TABLE activity_log ADD COLUMN edited_at DATETIME NULL DEFAULT NULL, ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
    }
});

finish();
