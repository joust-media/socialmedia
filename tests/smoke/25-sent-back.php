<?php
/**
 * The client's "Sent back" (sentback-lib.php): everything it marked Needs changes that Joust has not resubmitted.
 *   - the segment / chip on Posts, Emails, Pages, Assets (Library) and Tires (every tire + one tire: series renders and
 *     reference images), labelled "Sent back", last, with a neutral count; the admin keeps "Needs changes"
 *   - each item: thumbnail + title, the client's latest Needs-changes note, when, "Joust is reworking this" / "Being
 *     reworked" (Redo queue), Joust's latest reply — never an internal note, never a redo note, never a draft
 *   - counts: segments, chips, the Home card ("Sent back N", neutral) — never the red tab badges
 *   - the item: the full post / email / page (media, caption, thread, composer) with the panel on top and Add a
 *     comment · Approve instead; the old read-only "Joust is updating this post" notice is gone
 *   - Approve instead: denied → approved for the client (logged "approved … instead", Slack item_event like any client
 *     decision); a re-deny / reset stays 403; images leave the Redo queue only when the client's own Needs changes queued
 *     them (a Joust mark stays); comments on a sent-back item go through the normal pipeline
 *   - tenancy: another client's item is 403 (decisions) / 404 (views); the mobile segmented control scrolls
 * Every test starts from the seed (posts 4 + email R1 + renders 9 / 17 are sent back there).
 */
require __DIR__ . '/lib.php';

const J = ['Accept' => 'application/json'];
$GLOBALS['APP']   = rtrim((string)(getenv('APP_DIR') ?: '/tmp/portal-test/site/portal'), '/');
$GLOBALS['MEDIA'] = rtrim((string)(getenv('MEDIA_DIR') ?: '/tmp/portal-test/site/media'), '/');
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");

function fresh(): void {
    shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($GLOBALS['APP']) . ' ' . escapeshellarg($GLOBALS['MEDIA']) . ' 2>&1');
}
function stest(string $name, callable $fn): void { test($name, static function () use ($fn) { fresh(); $fn(); }); }
/** The segment keys of a page's segmented control, in order (+ their labels / counts). */
function segs(string $html): array {
    preg_match_all('#<a class="ui-segmented-item[^"]*" role="tab" href="[^"]*"[^>]*data-segment="(\w+)"[^>]*>([^<]+)(?:<span class="ui-segmented-count">(\d+)</span>)?#', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) $out[$x[1]] = ['label' => trim(html_entity_decode($x[2])), 'count' => isset($x[3]) ? (int)$x[3] : null];
    return $out;
}
function clientDeny(string $ep, int $id, string $note, string $slug = 'kenda'): array {
    return status(post($ep, ['id' => $id, 'status' => 'denied', 'comment' => $note, 'client' => $slug], 'client', [], J), 200, "deny {$ep} #{$id}");
}
function adminSays(string $ep, int $id, string $text, string $slug = 'kenda', array $extra = []): void {
    $data = ['id' => $id, 'comment' => $text, 'client' => $slug] + $extra;
    if ($ep === 'tire-status.php' || $ep === 'library-status.php') $data['action'] = 'comment';
    status(post($ep, $data, 'admin', [], J), 200, "admin comment {$ep} #{$id}");
}
function redoRow(string $table, int $id): array { return rows("SELECT redo_at, redo_note, redo_by FROM {$table} WHERE id = ?", [$id])[0]; }
function lastActivity(string $type, int $id, string $action): ?array {
    $r = rows("SELECT * FROM activity_log WHERE entity_type = ? AND entity_id = ? AND action = ? ORDER BY id DESC LIMIT 1", [$type, $id, $action]);
    return $r[0] ?? null;
}
function homeCard(string $html): string {
    return preg_match('#<section class="home-section" aria-labelledby="home-sentback".*?</section></section>#s', $html, $m) ? $m[0] : '';
}

// ---- segments + labels ------------------------------------------------------------------------------------------------
stest('the client gets a "Sent back" segment last (Posts, Emails, Pages), scrollable on phones; the admin keeps Needs changes', function () {
    $p = segs(status(get('posts.php?client=kenda', 'client'), 200)['body']);
    is(array_keys($p), ['pending', 'approved', 'scheduled', 'denied'], 'posts order');
    is($p['denied']['label'], 'Sent back');
    $e = segs(status(get('emails.php?client=privacybee', 'client'), 200)['body']);
    is(array_keys($e), ['pending', 'approved', 'live', 'denied'], 'emails order');
    is($e['denied']['label'], 'Sent back');
    $g = segs(status(get('pages.php?client=privacybee', 'client'), 200)['body']);
    is(array_keys($g), ['pending', 'approved', 'live', 'denied'], 'pages order');
    foreach (['posts.php?client=kenda', 'emails.php?client=privacybee', 'pages.php?client=privacybee'] as $u) {
        ok((bool)preg_match('#class="ui-segmented ui-segmented--dense ui-segmented--scroll"#', get($u, 'client')['body']), "{$u}: the scrolling segmented control");
    }
    $a = segs(get('posts.php?client=kenda')['body']);
    is($a['denied']['label'], 'Needs changes', 'admin label');
    is(array_keys($a)[1], 'denied', 'admin: Joust\'s queue stays up front');
});

// ---- the lists, every type ----------------------------------------------------------------------------------------------
stest('Posts: the Sent back list (every month, newest first) shows thumbnail, title, the note, when, the status line and Joust\'s reply', function () {
    clientDeny('status.php', 2, '[Slide 2] Crop tighter please');
    adminSays('status.php', 4, 'Darker render coming Friday');
    $b = status(get('posts.php?client=kenda&status=denied&month=2001-01', 'client'), 200)['body'];
    $s = segs($b);
    is($s['denied']['count'], 2, 'count');
    has($b, 'All months · <span data-segment-count>2</span> sent back', 'the month filter does not hide Sent back');
    ok(strpos($b, 'data-post-item="2"') < strpos($b, 'data-post-item="4"'), 'newest sent back first');
    hasNot($b, 'data-post-item="1"', 'only sent-back posts');
    preg_match('#<li class="pl-item pl-item--sentback" id="post-4".*?</li>#s', $b, $m);
    $row = $m[0] ?? '';
    has($row, 'data-sentback'); hasNot($row, 'data-swipe', 'no swipe decisions on a sent-back row');
    has($row, '<img', 'thumbnail'); has($row, 'Winter promo');
    has($row, '<q>Please use the darker render</q>'); has($row, 'Your note · sent back');
    has($row, 'Joust is reworking this');
    has($row, 'Joust replied'); has($row, 'Darker render coming Friday');
    ok((bool)preg_match('#data-status-pill data-status="denied">Sent back<#', $row), 'pill reads Sent back');
    has($b, 'On slide 2: <q>Crop tighter please</q>', 'slide tag shown as words');
});
stest('Emails + Pages: the Sent back list; a draft never shows', function () {
    adminSays('email-status.php', 4, 'New copy on the way', 'privacybee');
    clientDeny('page-status.php', 1, 'Headline should say Spring', 'privacybee');
    $e = status(get('emails.php?client=privacybee&status=denied', 'client'), 200)['body'];
    has($e, 'data-email-item="4"'); has($e, 'Time to renew'); has($e, 'Joust is reworking this'); has($e, 'New copy on the way');
    hasNot($e, 'data-email-item="1"', 'draft W1 never');
    is(segs($e)['denied']['count'], 1);
    $p = status(get('pages.php?client=privacybee&status=denied', 'client'), 200)['body'];
    has($p, 'data-page-item="1"'); has($p, '<q>Headline should say Spring</q>');
    is(segs($p)['denied']['count'], 1);
    hasNot($p, 'data-page-item="3"', 'draft page never');
    // "all" (Studio's Open emails) still hides drafts for the client
    hasNot(get('emails.php?client=privacybee&status=all', 'client')['body'], 'data-email-item="1"');
});
stest('Assets (Library) + Tires (every tire, one tire, series renders and reference images): the Sent back list', function () {
    clientDeny('library-status.php', 8, 'Too dark, brighten it');
    clientDeny('tire-status.php', 3, 'Wrong reference angle');   // a reference image (series_id NULL)
    adminSays('tire-status.php', 9, 'Re-rendering the tread');
    $l = status(get('assets.php?client=kenda&view=library&filter=denied', 'client'), 200)['body'];
    has($l, 'data-sentback-list'); has($l, 'id="assetsGrid"');
    ok((bool)preg_match('#<a class="ui-row ui-row--leading sb-row" role="listitem" href="[^"]*asset=8[^"]*" id="lib-8" data-asset data-id="8" data-kind="library" data-status="denied"#', $l), 'a viewer row');
    has($l, '<q>Too dark, brighten it</q>'); has($l, 'Being reworked', 'auto-queued for redo → Being reworked');
    hasNot($l, '>lib_08.jpg<', 'never the on-disk name as a title');
    ok((bool)preg_match('#<a class="as-chip is-active"[^>]*>\s*Sent back<span class="as-chip-count" data-count="denied">1</span>#', $l), 'the chip + count');
    $t = status(get('assets.php?client=kenda&view=collections&filter=denied', 'client'), 200)['body'];
    foreach ([3, 9, 17] as $id) has($t, 'id="image-' . $id . '" data-asset data-id="' . $id . '" data-kind="tire" data-status="denied"', "tire image {$id}");
    has($t, 'Klever AT2 · Reference', 'reference images are in'); has($t, 'Klever AT2 · Series 2');
    has($t, 'Re-rendering the tread', 'Joust\'s reply');
    has($t, 'data-sentback-chips'); ok((bool)preg_match('#Sent back<span class="as-chip-count" data-count="denied">3</span>#', $t), 'Tires chip = every tire');
    // one tire: its series + reference images only; the chip counts that tire
    $one = status(get('assets.php?client=kenda&view=collections&item=1&filter=denied', 'client'), 200)['body'];
    has($one, 'id="image-3"'); has($one, 'id="image-9"'); has($one, 'id="image-17"');
    hasNot($one, 'data-series-switcher', 'no series grid in the list');
    $tp = (int)q1("SELECT COUNT(*) FROM tire_images WHERE tire_id = 1 AND status = 'pending'");
    has($one, '<span class="as-chip-count" data-count="pending">' . $tp . '</span>', 'the To Review chip counts this tire');
    // the Tires list mentions the sent-back images per tire; a grid never shows them
    has(get('assets.php?client=kenda&view=collections', 'client')['body'], ' · 3 sent back', 'tire 1: its sent-back count');
    hasNot(get('assets.php?client=kenda&view=collections&item=1&series=1&filter=pending', 'client')['body'], 'data-id="9"', 'the grids keep them out');
});

// ---- counts: segments, chips, Home — never the red badges -----------------------------------------------------------
stest('counts: the Home card ("Sent back N", neutral) lists them and links to every list; the red tab badges never count them', function () {
    clientDeny('library-status.php', 8, 'Too dark');
    $h = status(get('index.php?client=kenda', 'client'), 200)['body'];
    $card = homeCard($h);
    ok($card !== '', 'the card');
    has($card, 'data-home-sentback="4"', 'post 4 + renders 9, 17 + library 8');
    has($card, '<span class="ui-badge ui-badge--neutral sb-count" data-sentback-count>4</span>', 'neutral count');
    foreach (['post:4', 'library_image:8', 'tire_image:9', 'tire_image:17'] as $k) has($card, 'data-sentback-row="' . $k . '"', $k);
    has($card, 'data-sentback-link="post"'); has($card, 'data-sentback-link="library_image"'); has($card, 'data-sentback-link="tire_image"');
    has($card, 'posts.php?client=kenda&amp;status=denied&amp;month=all');
    has($card, 'assets.php?client=kenda&amp;view=library&amp;filter=denied');
    has($card, 'Joust is reworking this'); has($card, 'Being reworked');
    // the red badges: To Review (+ unread replies) only
    $pending = (int)q1("SELECT COUNT(*) FROM posts WHERE company_id = 1 AND status = 'pending'");
    ok((bool)preg_match('#data-tab="posts"[^>]*data-badge-review="' . $pending . '"#', $h) || (bool)preg_match('#data-badge-review="' . $pending . '"[^>]*data-tab="posts"#', $h), 'posts badge = To Review');
    hasNot($card, 'ui-badge--deny'); hasNot($card, 'class="ui-badge"', 'no red badge in the card');
    // nothing sent back → no card
    is(homeCard(get('index.php?client=hmf', 'client')['body']), '', 'no card for a client with nothing sent back');
    // the admin Home is unchanged
    is(homeCard(get('index.php?client=kenda')['body']), '', 'admin: no client card');
});

// ---- security: internal notes, redo notes, drafts, tenancy ---------------------------------------------------------
stest('internal notes and redo notes never reach the client (lists, sheets, viewer thread, Home)', function () {
    status(post('status.php', ['id' => 4, 'comment' => 'INTERNAL-SECRET-POST', 'internal' => 1, 'client' => 'kenda'], 'admin', [], J), 200);
    status(post('email-status.php', ['id' => 4, 'comment' => 'INTERNAL-SECRET-EMAIL', 'internal' => 1, 'client' => 'privacybee'], 'admin', [], J), 200);
    clientDeny('library-status.php', 8, 'Too dark');
    status(post('redo.php', ['action' => 'mark', 'items' => 'library:8,tire:9', 'note' => 'REDO-SECRET-NOTE'], 'admin', [], J), 200, 'redo mark with a note');
    $pages = [
        get('posts.php?client=kenda&status=denied&month=all', 'client')['body'],
        get('posts.php?client=kenda&post=4&partial=1', 'client')['body'],
        get('emails.php?client=privacybee&status=denied', 'client')['body'],
        get('emails.php?client=privacybee&email=4&partial=1', 'client')['body'],
        get('assets.php?client=kenda&view=library&filter=denied', 'client')['body'],
        get('assets.php?client=kenda&view=collections&filter=denied', 'client')['body'],
        (string)json_encode(get('assets.php?client=kenda&partial=comments&kind=library&id=8', 'client')['json']),
        (string)json_encode(get('assets.php?client=kenda&partial=comments&kind=tire&id=9', 'client')['json']),
        get('index.php?client=kenda', 'client')['body'],
    ];
    foreach ($pages as $i => $b) {
        foreach (['INTERNAL-SECRET', 'REDO-SECRET', 'Redo: '] as $secret) hasNot($b, $secret, "page {$i}");
    }
    // …while Joust still sees them
    has(get('posts.php?client=kenda&post=4&partial=1')['body'], 'INTERNAL-SECRET-POST');
});
stest('drafts never show; another client\'s items are 404 to view and 403 to decide / comment', function () {
    is(get('posts.php?client=kenda&post=6&partial=1', 'client')['code'], 404, 'draft post');
    is(get('emails.php?client=privacybee&email=1&partial=1', 'client')['code'], 404, 'draft email');
    is(get('pages.php?client=privacybee&page=3&partial=1', 'client')['code'], 404, 'draft page');
    is(post('email-status.php', ['id' => 1, 'comment' => 'hi', 'client' => 'privacybee'], 'client', [], J)['code'], 404, 'draft email: no comment');
    // Privacy Bee's seat on Kenda's sent-back items
    is(get('posts.php?client=privacybee&post=4&partial=1', 'client:privacybee')['code'], 404, 'Kenda post');
    is(get('assets.php?client=privacybee&partial=comments&kind=tire&id=9', 'client:privacybee')['code'], 404, 'Kenda render thread');
    is(post('status.php', ['id' => 4, 'status' => 'approved', 'client' => 'privacybee'], 'client:privacybee', [], J)['code'], 403, 'approve Kenda post');
    is(post('status.php', ['id' => 4, 'comment' => 'x', 'client' => 'privacybee'], 'client:privacybee', [], J)['code'], 403, 'comment Kenda post');
    is(post('tire-status.php', ['id' => 9, 'status' => 'approved', 'client' => 'privacybee'], 'client:privacybee', [], J)['code'], 403, 'approve Kenda render');
    is(post('library-status.php', ['id' => 8, 'status' => 'approved', 'client' => 'privacybee'], 'client:privacybee', [], J)['code'], 403, 'Kenda library');
    is(post('email-status.php', ['id' => 4, 'status' => 'approved', 'client' => 'kenda'], 'client:kenda', [], J)['code'], 403, 'Kenda on Privacy Bee email');
    is(post('page-status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda'], 'client:kenda', [], J)['code'], 403, 'Kenda on Privacy Bee page');
    // nothing changed
    is(q1("SELECT status FROM posts WHERE id = 4"), 'denied');
    is(q1("SELECT status FROM tire_images WHERE id = 9"), 'denied');
    // Privacy Bee's own lists never hold Kenda's
    $pb = get('index.php?client=privacybee', 'client:privacybee')['body'];
    hasNot(homeCard($pb), 'Winter promo');
});

// ---- the item: the full view with Add a comment · Approve instead ------------------------------------------------
stest('the sheet: the full post (media, caption, thread, composer) under the Sent back panel; the note edits in place', function () {
    adminSays('status.php', 4, 'Darker render coming Friday');
    $b = status(get('posts.php?client=kenda&post=4&partial=1', 'client'), 200)['body'];
    has($b, 'data-sentback'); has($b, 'data-sentback-panel');
    has($b, 'data-carousel', 'media'); has($b, 'Winter is coming', 'caption'); has($b, 'data-comment-form', 'composer');
    has($b, 'class="pd-comments"', 'thread');
    has($b, 'Joust is reworking this'); has($b, 'You sent this post back');
    $note = (int)q1("SELECT id FROM activity_log WHERE entity_type = 'post' AND entity_id = 4 AND action = 'commented' AND actor = 'client' ORDER BY id DESC LIMIT 1");
    ok((bool)preg_match('#data-hidden-note data-comment-id="' . $note . '" data-comment-host="note" data-comment-can="edit"#', $b), 'their note, editable');
    ok((bool)preg_match('#data-hidden-reply>.*?Joust replied.*?Darker render coming Friday#s', $b), 'Joust\'s reply');
    has($b, 'data-sentback-comment>Add a comment</button>'); has($b, 'data-approve-instead>Approve instead</button>');
    ok((bool)preg_match('#<div class="ui-btn-group pd-decide" data-state="decide" hidden>#', $b), 'no Approve / Needs changes pair');
    hasNot($b, 'Joust is updating this post', 'the old notice is gone');
    // the deep link opens it inside the Sent back segment (every month)
    $page = status(get('posts.php?client=kenda&post=4', 'client'), 200)['body'];
    ok((bool)preg_match('#class="ui-segmented-item is-active" role="tab" href="[^"]*status=denied[^"]*month=all#', $page), 'Sent back segment, all months');
    has($page, '"openPost":4');
    // emails / pages get the same panel + footer
    foreach (['emails.php?client=privacybee&email=4&partial=1', ] as $u) {
        $x = status(get($u, 'client'), 200)['body'];
        has($x, 'data-sentback-panel'); has($x, 'data-approve-instead'); has($x, 'data-comment-form');
    }
    clientDeny('page-status.php', 1, 'Headline should say Spring', 'privacybee');
    $pg = status(get('pages.php?client=privacybee&page=1&partial=1', 'client'), 200)['body'];
    has($pg, 'data-sentback-panel'); has($pg, 'Headline should say Spring');
    // the admin sheet is unchanged (Joust's Needs changes banner + Edit & resubmit)
    $a = get('posts.php?client=kenda&post=4&partial=1')['body'];
    hasNot($a, 'data-sentback-panel'); has($a, 'data-newpost-resubmit'); has($a, 'asked for changes');
});
stest('Add a comment: the normal pipeline (thread row, Slack item_event) on a sent-back post and email', function () {
    $c = status(post('status.php', ['id' => 4, 'comment' => 'Also lose the snowflakes', 'client' => 'kenda'], 'client', [], J), 200);
    $row = lastActivity('post', 4, 'commented');
    is($row['detail'], 'Also lose the snowflakes'); is($row['actor'], 'client');
    is(q1("SELECT status FROM posts WHERE id = 4"), 'denied', 'still sent back');
    ok((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'item_event' AND entity_type = 'post' AND entity_id = 4") >= 1, 'Slack hears of it');
    status(post('email-status.php', ['id' => 4, 'comment' => 'Keep the old subject', 'client' => 'privacybee'], 'client', [], J), 200, 'a sent-back email takes comments now');
    is(lastActivity('email', 4, 'commented')['detail'], 'Keep the old subject');
    // the Needs-changes note stays the row's note; the new comment is in the thread
    $b = get('posts.php?client=kenda&status=denied&month=all', 'client')['body'];
    has($b, '<q>Please use the darker render</q>');
});
stest('Approve instead (posts, emails, pages): denied → approved, logged as the client\'s, Slack item_event; re-deny / reset stay 403', function () {
    is(post('status.php', ['id' => 4, 'status' => 'denied', 'comment' => 'still no', 'client' => 'kenda'], 'client', [], J)['code'], 403, 'no re-deny');
    is(post('status.php', ['id' => 4, 'status' => 'pending', 'client' => 'kenda'], 'client', [], J)['code'], 403, 'no reset');
    is(post('email-status.php', ['id' => 4, 'status' => 'denied', 'comment' => 'still no', 'client' => 'privacybee'], 'client', [], J)['code'], 403, 'email: no re-deny');
    $r = status(post('status.php', ['id' => 4, 'status' => 'approved', 'client' => 'kenda'], 'client', [], J), 200);
    is($r['json']['status'], 'approved');
    is(q1("SELECT status FROM posts WHERE id = 4"), 'approved');
    $a = lastActivity('post', 4, 'approved');
    is($a['actor'], 'client'); has($a['summary'], 'instead (it was sent back)');
    ok((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'item_event' AND entity_type = 'post' AND entity_id = 4 AND payload LIKE ?", ['%' . (int)$a['id'] . '%']) === 1, 'Slack item_event for the decision');
    // it leaves Sent back and is in Approved
    $b = get('posts.php?client=kenda&status=denied&month=all', 'client')['body'];
    hasNot($b, 'data-post-item="4"'); is(segs($b)['denied']['count'], 0);
    has(get('posts.php?client=kenda&status=approved&month=all', 'client')['body'], 'data-post-item="4"');
    // emails + pages
    status(post('email-status.php', ['id' => 4, 'status' => 'approved', 'client' => 'privacybee'], 'client', [], J), 200);
    is(q1("SELECT status FROM emails WHERE id = 4"), 'approved');
    has(lastActivity('email', 4, 'approved')['summary'], 'instead');
    clientDeny('page-status.php', 1, 'Headline should say Spring', 'privacybee');
    status(post('page-status.php', ['id' => 1, 'status' => 'approved', 'client' => 'privacybee'], 'client', [], J), 200);
    is(q1("SELECT status FROM pages WHERE id = 1"), 'approved');
    // the admin flow is unchanged: Joust may still resubmit / approve for the client
    clientDeny('status.php', 1, 'Brighter please');
    status(post('status.php', ['id' => 1, 'status' => 'pending', 'client' => 'kenda'], 'admin', [], J), 200, 'Joust resubmits');
    hasNot(lastActivity('post', 1, 'reset_pending')['summary'], 'instead');
});
stest('Approve instead on images: off the Redo queue when the client\'s Needs changes queued it — a Joust mark stays', function () {
    // auto-queued by the client's own Needs changes
    clientDeny('library-status.php', 8, 'Too dark');
    $q = redoRow('library_images', 8);
    ok($q['redo_at'] !== null && $q['redo_by'] === null && $q['redo_note'] === null, 'auto-queued');
    $r = status(post('library-status.php', ['id' => 8, 'status' => 'approved', 'client' => 'kenda'], 'client', [], J), 200);
    is($r['json']['redo_cleared'], true, 'reply says so');
    is(redoRow('library_images', 8)['redo_at'], null, 'off the queue');
    is(q1("SELECT status FROM library_images WHERE id = 8"), 'approved');
    $c = lastActivity('library_image', 8, 'redo_cleared');
    ok($c !== null, 'logged'); is((int)$c['internal'], 1, 'internal (Joust\'s history)');
    has(lastActivity('library_image', 8, 'approved')['summary'], 'instead');
    is(redoRow('library_images', 8)['redo_at'], null);
    // the same for a tire render
    clientDeny('tire-status.php', 10, 'Old tread');
    ok(redoRow('tire_images', 10)['redo_at'] !== null, 'render queued');
    is(status(post('tire-status.php', ['id' => 10, 'status' => 'approved', 'client' => 'kenda'], 'client', [], J), 200)['json']['redo_cleared'], true);
    is(redoRow('tire_images', 10)['redo_at'], null);
    // Joust marked it (with a note) before the client sent it back: Joust's decision, it stays
    status(post('redo.php', ['action' => 'mark', 'items' => 'tire:11', 'note' => 'Fix the rim'], 'admin', [], J), 200);
    clientDeny('tire-status.php', 11, 'Rim looks off');
    is(status(post('tire-status.php', ['id' => 11, 'status' => 'approved', 'client' => 'kenda'], 'client', [], J), 200)['json']['redo_cleared'], false);
    ok(redoRow('tire_images', 11)['redo_at'] !== null, 'Joust\'s mark stays');
    // auto-queued, then Joust re-marked it without a note (a redo_marked row since): stays too
    clientDeny('tire-status.php', 12, 'Shadow is wrong');
    status(post('redo.php', ['action' => 'mark', 'items' => 'tire:12', 'note' => ''], 'admin', [], J), 200);
    status(post('tire-status.php', ['id' => 12, 'status' => 'approved', 'client' => 'kenda'], 'client', [], J), 200);
    ok(redoRow('tire_images', 12)['redo_at'] !== null, 'Joust touched it: stays');
    // the admin approving for the client leaves the queue alone (Joust's flow is unchanged)
    clientDeny('library-status.php', 7, 'Crop it');
    status(post('library-status.php', ['id' => 7, 'status' => 'approved', 'client' => 'kenda'], 'admin', [], J), 200);
    ok(redoRow('library_images', 7)['redo_at'] !== null, 'admin approval: the queue is Joust\'s call');
});
stest('the client\'s "Joust replied" email names a sent-back item "Sent back", like the portal', function () {
    $mail = rtrim((string)(getenv('MAIL_DIR') ?: rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test'), '/') . '/mail'), '/');
    foreach (glob($mail . '/*') ?: [] as $f) @unlink($f);
    adminSays('status.php', 4, 'Darker render coming Friday');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'reply' AND entity_type = 'post' AND entity_id = 4"), 1, 'queued');
    db()->exec("UPDATE client_email_queue SET created_at = NOW() - INTERVAL 11 MINUTE WHERE batch_key IS NULL");
    status(get('notify-cron.php', 'anon', ['X-Notify-Token' => 'test-cron-token-0123456789abcdef']), 200, 'cron');
    $m = array_values(array_filter(array_map(static function ($f) { return json_decode((string)file_get_contents($f), true); }, glob($mail . '/*.json') ?: []),
        static function ($x) { return strtolower((string)($x['to'] ?? '')) === 'jane@kenda.example'; }));
    is(count($m), 1, 'one email to Jane');
    has($m[0]['html'], 'Post · Sent back'); hasNot($m[0]['html'], 'Needs changes');
    has($m[0]['text'] ?? '', 'Post, Sent back');
});
stest('the client seat reads "Sent back" in the JS too (pills, toasts) and the viewer offers Add a comment · Approve instead', function () {
    $app = (string)file_get_contents(dirname(__DIR__, 2) . '/static/js/app.js');
    has($app, "if (status === 'denied' && App.role !== 'admin') return this.sentBack;");
    has($app, 'data-approve-instead');
    $as = (string)file_get_contents(dirname(__DIR__, 2) . '/static/js/assets.js');
    has($as, "sb ? 'Approve instead' : 'Approve'"); has($as, "sb ? 'Add a comment' : 'Needs changes'");
    foreach (['posts', 'emails', 'pages'] as $m) has((string)file_get_contents(dirname(__DIR__, 2) . "/static/js/{$m}.js"), "'sentback':", "{$m}.js state");
    // the viewer's confirm exists for the client only
    has(get('assets.php?client=kenda&view=library&filter=denied', 'client')['body'], 'data-viewer-confirm');
    hasNot(get('assets.php?client=kenda&view=library&filter=denied')['body'], 'data-viewer-confirm', 'never for Joust');
    // a deep link to a sent-back image opens its list with the viewer on it
    clientDeny('library-status.php', 8, 'Too dark');
    $b = get('assets.php?client=kenda&asset=8&kind=library', 'client')['body'];
    has($b, 'data-sentback-list'); has($b, '"open":{"kind":"library","id":8}');
    has($b, '"mode":"sentback"');
});

finish();
