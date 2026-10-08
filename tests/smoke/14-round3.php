<?php
/**
 * Round-3 UX fixes (admin re-score round 2 → ≥ 90):
 *   #1  add-email.php / add-page.php never approve: Draft · To Review (+ "keep" for a decided row); the server refuses
 *       draft/pending → approved / Needs changes / live; editing an approved row keeps it approved (edits logged);
 *       a save goes back to the edited row (N10).
 *   #2  comment threads are drawn from the viewer's seat: the admin sees the client's notes on the left with the
 *       client's name, their own on the right as "You" (posts, emails, pages, add-email / add-page threads).
 *   #3  the admin post ⋯ menu has one editor (Edit post…) — no Edit caption / Edit date / Replace image items.
 *   #4  admin segments: Draft · Needs changes first (Posts / Emails / Pages); "0 comments" is not said.
 *   #5  the comment Slide picker starts on slide 1 (posts.js follows the carousel — tests/e2e/10-round3.js).
 *   #6  Delete tire sits behind ⋯ on the tire page (no red header button).
 *   #7  Add to flow: steps carry their code ("After W2").   #8  flow cards show one timing (the connector pill).
 *   #9  add-page source chips are short.   #10  "Post date" in the sheet and the composer.
 *   #11 a client's link to a post they marked Needs changes opens a read-only notice with their note (never a 404).
 *   #12 leftovers: a draft's "created" row reads "started a draft"; "No note left."; tab-less Emails / Pages → Manage.
 * Seed: posts 1 pending · 2 pending carousel (3) · 4 denied (client note) · 6 draft. Emails (privacybee) 1 W1 draft ·
 *       2 W2 pending · 3 W3 approved · 4 R1 denied · 5 R2 approved + live. Pages 1 pending · 2 approved + live · 3 draft.
 */
require __DIR__ . '/lib.php';

const J = ['Accept' => 'application/json'];

/** Visible text only (no scripts / styles / comments / attribute values). */
function r3Text(string $html): string {
    $html = preg_replace('#<(script|style|template)\b.*?</\1>#si', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);
    return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}
/** The <option> values of one <select id="…">. */
function r3Options(string $html, string $id): array {
    if (!preg_match('#<select[^>]*id="' . preg_quote($id, '#') . '"[^>]*>(.*?)</select>#s', $html, $m)) return [];
    preg_match_all('#<option value="([^"]*)"#', $m[1], $o);
    return $o[1];
}
/** data-segment keys of the status control, in order. */
function r3Segments(string $html): array {
    preg_match_all('#class="ui-segmented-item[^"]*"[^>]*data-segment="([a-z]+)"#', $html, $m);
    return $m[1];
}
/** [actor, side, label] of every bubble in a thread. */
function r3Bubbles(string $html): array {
    preg_match_all('#<div class="pd-msg pd-msg--(mine|theirs)" data-actor="([a-z]+)"[^>]*>.*?<div class="ui-bubble-meta">(.*?)</div>#s', $html, $m, PREG_SET_ORDER);
    return array_map(static function ($x) {
        $meta = preg_replace('#<span class="ui-avatar[^>]*>.*?</span>#s', '', $x[3]);   // the avatar's initials are not the label
        return [$x[2], $x[1], trim(preg_replace('/\s*·.*$/s', '', strip_tags($meta)))];
    }, $m);
}
function emailForm(int $id, array $over = []): array {
    $e = rows("SELECT * FROM emails WHERE id = ?", [$id])[0];
    return $over + ['action' => 'update', 'id' => $id, 'code' => $e['code'], 'title' => $e['title'], 'html_url' => (string)$e['html_url'],
                    'subject' => (string)$e['subject'], 'trigger_text' => (string)$e['trigger_text'], 'status' => $e['status'], 'live' => $e['live'] ? '1' : ''];
}
function pageForm(int $id, array $over = []): array {
    $p = rows("SELECT * FROM pages WHERE id = ?", [$id])[0];
    return $over + ['action' => 'update', 'id' => $id, 'title' => $p['title'], 'slug' => $p['slug'], 'source' => $p['source'], 'url' => (string)$p['url'],
                    'entry' => (string)($p['entry'] ?: 'index.html'), 'status' => $p['status'], 'live' => $p['live'] ? '1' : ''];
}

// ---- #1 the full email / page forms never approve --------------------------------------------------------------
test('#1 add-email: Status offers Draft / To Review (+ keep for a decided email), never Approved / Needs changes', function () {
    is(r3Options(status(get('add-email.php?client=privacybee'), 200)['body'], 'email-status'), ['draft', 'pending'], 'new');
    is(r3Options(status(get('add-email.php?client=privacybee&edit=1'), 200)['body'], 'email-status'), ['draft', 'pending'], 'draft W1');
    is(r3Options(status(get('add-email.php?client=privacybee&edit=2'), 200)['body'], 'email-status'), ['draft', 'pending'], 'pending W2');
    $b = status(get('add-email.php?client=privacybee&edit=3'), 200)['body'];
    is(r3Options($b, 'email-status'), ['approved', 'draft', 'pending'], 'approved W3: keep first');
    has($b, '<option value="approved" selected>Approved — unchanged</option>');
    has($b, 'Saving keeps it approved');
    is(r3Options(status(get('add-email.php?client=privacybee&edit=4'), 200)['body'], 'email-status'), ['denied', 'draft', 'pending'], 'denied R1: keep first');
});
test('#1 add-email: a draft cannot be saved as Approved / Needs changes / live (server)', function () {
    foreach ([['approved', '1'], ['approved', ''], ['denied', '']] as [$st, $live]) {
        $r = status(post('add-email.php?client=privacybee', emailForm(1, ['status' => $st, 'live' => $live])), 200, "draft → $st");
        has($r['body'], 'data-form-errors');
        is(q1("SELECT CONCAT(status, '/', live) FROM emails WHERE id = 1"), 'draft/0', "draft → $st refused");
    }
    $r = status(post('add-email.php?client=privacybee', emailForm(1, ['status' => 'approved'])), 200);
    has(r3Text($r['body']), 'Only the client approves');
    $r = status(post('add-email.php?client=privacybee', emailForm(2, ['status' => 'approved'])), 200, 'pending → approved');
    is(q1("SELECT status FROM emails WHERE id = 2"), 'pending');
    $n = (int)q1("SELECT COUNT(*) FROM emails");
    status(post('add-email.php?client=privacybee', ['action' => 'create', 'code' => 'Z9', 'title' => 'Sneaky', 'status' => 'approved', 'live' => '1']), 200, 'create approved');
    is((int)q1("SELECT COUNT(*) FROM emails"), $n, 'nothing created');
    $r = post('add-email.php?client=privacybee', ['action' => 'create', 'code' => 'Z9', 'title' => 'Fine', 'status' => 'pending']);
    is($r['code'], 302, 'To Review is fine');
    is(q1("SELECT status FROM emails WHERE code = 'Z9'"), 'pending');
});
test('#1 add-email: editing an approved email keeps it approved (edit logged) and returns to that email', function () {
    $r = post('add-email.php?client=privacybee', emailForm(3, ['title' => 'Three quicker wins']));
    is($r['code'], 302);
    has($r['location'], 'emails.php?client=privacybee&email=3&msg=', 'back to the edited email (N10)');
    is(q1("SELECT CONCAT(status, '/', title) FROM emails WHERE id = 3"), 'approved/Three quicker wins');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'email' AND entity_id = 3 AND action = 'edited_title'"), 1, 'logged');
    // the live one keeps live; sending it back for review (unticked live) is the admin's call
    is(post('add-email.php?client=privacybee', emailForm(5, ['subject' => 'New subject']))['code'], 302);
    is(q1("SELECT CONCAT(status, '/', live) FROM emails WHERE id = 5"), 'approved/1', 'live kept');
    is(post('add-email.php?client=privacybee', emailForm(3, ['status' => 'pending']))['code'], 302, 'back to To Review');
    is(q1("SELECT status FROM emails WHERE id = 3"), 'pending');
});
test('#1 add-page: same rules (Draft / To Review, keep, no approve from the form) and back to the edited page', function () {
    is(r3Options(status(get('add-page.php?client=privacybee'), 200)['body'], 'page-status'), ['draft', 'pending'], 'new');
    is(r3Options(status(get('add-page.php?client=privacybee&edit=3'), 200)['body'], 'page-status'), ['draft', 'pending'], 'draft');
    is(r3Options(status(get('add-page.php?client=privacybee&edit=2'), 200)['body'], 'page-status'), ['approved', 'draft', 'pending'], 'approved');
    $r = status(post('add-page.php?client=privacybee', pageForm(3, ['status' => 'approved', 'live' => '1'])), 200);
    has($r['body'], 'data-form-errors');
    is(q1("SELECT CONCAT(status, '/', live) FROM pages WHERE id = 3"), 'draft/0', 'refused');
    status(post('add-page.php?client=privacybee', pageForm(1, ['status' => 'denied'])), 200);
    is(q1("SELECT status FROM pages WHERE id = 1"), 'pending', 'pending → Needs changes refused');
    $r = post('add-page.php?client=privacybee', pageForm(2, ['title' => 'Pricing 2027']));
    is($r['code'], 302);
    has($r['location'], 'pages.php?client=privacybee&page=2&msg=');
    is(q1("SELECT CONCAT(status, '/', live, '/', title) FROM pages WHERE id = 2"), 'approved/1/Pricing 2027', 'kept approved + live');
    has(status(get('add-page.php?client=privacybee'), 200)['body'], '>Upload files to the portal</label>', '#9 short source chip');
});

// ---- #2 the thread from the viewer's seat --------------------------------------------------------------------------
test('#2 posts: the admin sees the client note on the left with the client name; the client sees "You"', function () {
    $b = r3Bubbles(status(get('posts.php?client=kenda&post=4&partial=1'), 200)['body']);
    is($b, [['client', 'theirs', 'Kenda Tires']], 'admin seat');
    status(post('status.php', ['id' => 1, 'comment' => 'Love it', 'actor' => 'client', 'client' => 'kenda'], 'client', [], J), 200);
    status(post('status.php', ['id' => 1, 'comment' => 'Thanks!', 'actor' => 'admin', 'client' => 'kenda'], 'admin', [], J), 200);
    is(r3Bubbles(get('posts.php?client=kenda&post=1&partial=1', 'client')['body']), [['client', 'mine', 'You'], ['admin', 'theirs', 'Lance at Joust']], 'client seat (named author, notify-lib.php)');
    // the signed-in contact who wrote it (activity_log.client_contact_id, migrate.php 44); legacy rows keep the client name
    is(r3Bubbles(get('posts.php?client=kenda&post=1&partial=1')['body']), [['client', 'theirs', 'Jane Kenda (Kenda Tires)'], ['admin', 'mine', 'You']], 'admin seat');
    has(get('posts.php?client=kenda')['body'], '"names":{"client":"Kenda Tires"}', 'App.bubbleWho gets the client name');
});
test('#2 emails + pages (sheet and full form): the same sides', function () {
    status(post('email-status.php', ['id' => 2, 'comment' => 'Shorter subject?', 'actor' => 'client', 'client' => 'privacybee'], 'client', [], J), 200);
    is(r3Bubbles(get('emails.php?client=privacybee&email=2&partial=1')['body']), [['client', 'theirs', 'Pat Bee (Privacy Bee)']], 'email sheet, admin');
    is(r3Bubbles(get('emails.php?client=privacybee&email=2&partial=1', 'client')['body']), [['client', 'mine', 'You']], 'email sheet, client');
    is(r3Bubbles(get('add-email.php?client=privacybee&edit=2')['body']), [['client', 'theirs', 'Pat Bee (Privacy Bee)']], 'add-email thread');
    status(post('page-status.php', ['id' => 1, 'comment' => 'Hero looks great', 'actor' => 'client', 'client' => 'privacybee'], 'client', [], J), 200);
    is(r3Bubbles(get('pages.php?client=privacybee&page=1&partial=1')['body']), [['client', 'theirs', 'Pat Bee (Privacy Bee)']], 'page sheet, admin');
    is(r3Bubbles(get('add-page.php?client=privacybee&edit=1')['body']), [['client', 'theirs', 'Pat Bee (Privacy Bee)']], 'add-page thread');
    hasNot(get('index.php?client=kenda')['body'], '>You</span> · ', 'Home notes never say "You" for the client');
});

// ---- #3 one editor in the post ⋯ menu ------------------------------------------------------------------------------
test('#3 the admin post ⋯ menu: Edit post… only (no Edit caption / Edit date / Replace image); client keeps quick caption edit', function () {
    $b = status(get('posts.php?client=kenda&post=2&partial=1'), 200)['body'];
    ok(preg_match('#<div class="pd-menu" role="menu" data-menu hidden>(.*?)</div></div>#s', $b, $m) === 1, 'menu');
    has($m[1], 'data-newpost-edit="2">Edit post…</button>');
    foreach (['Edit caption', 'Edit date', 'Replace image', 'data-caption-menu', 'data-replace-image'] as $x) hasNot($m[1], $x, "menu has no $x");
    hasNot($b, 'data-replace-input', 'no hidden Replace input');
    has($b, 'data-caption-edit', 'the inline caption shortcut stays');
    $c = status(get('posts.php?client=kenda&post=2&partial=1', 'client'), 200)['body'];
    has($c, 'data-caption-edit', 'client: quick caption edit');
    hasNot($c, 'data-menu-toggle', 'client: no ⋯ menu');
    $js = file_get_contents(dirname(__DIR__, 2) . '/static/js/posts.js');
    foreach (['replaceImage', 'data-replace-input', 'data-caption-menu'] as $x) hasNot($js, $x, "posts.js: no dead $x");
});

// ---- #4 admin segments + no "0 comments" -------------------------------------------------------------------------
test('#4 admin segments lead with Draft · Needs changes (Posts, Emails, Pages); the client never gets Needs changes', function () {
    is(r3Segments(status(get('posts.php?client=kenda'), 200)['body']), ['draft', 'denied', 'pending', 'approved', 'scheduled'], 'posts');
    is(r3Segments(status(get('emails.php?client=privacybee'), 200)['body']), ['draft', 'denied', 'pending', 'approved', 'live'], 'emails');
    is(r3Segments(status(get('pages.php?client=privacybee'), 200)['body']), ['draft', 'denied', 'pending', 'approved', 'live'], 'pages');
    is(r3Segments(status(get('posts.php?client=kenda', 'client'), 200)['body']), ['pending', 'approved', 'scheduled'], 'client posts');
});
test('#4 rows never say "0 comments"', function () {
    $b = status(get('posts.php?client=kenda&status=pending&month=all'), 200)['body'];
    ok(preg_match('#<span class="pl-meta-item" hidden><span class="pl-meta-sep">·</span><span data-comment-count-for="2">0 comments#', $b) === 1, 'post 2: hidden');
    status(post('status.php', ['id' => 2, 'comment' => 'One', 'actor' => 'admin', 'client' => 'kenda'], 'admin', [], J), 200);
    $b = get('posts.php?client=kenda&status=pending&month=all')['body'];
    ok(preg_match('#<span class="pl-meta-item"><span class="pl-meta-sep">·</span><span data-comment-count-for="2">1 comment<#', $b) === 1, 'post 2: shown at 1');
    ok(preg_match('#<span class="pl-meta-item" hidden><span class="pl-meta-sep">·</span><span data-comment-count-for="1">0 comments#', get('emails.php?client=privacybee&status=draft')['body']) === 1, 'email W1: hidden');
    ok(preg_match('#<span class="pl-meta-item" hidden><span class="pl-meta-sep">·</span><span data-comment-count-for="3">0 comments#', get('pages.php?client=privacybee&status=draft')['body']) === 1, 'page 3: hidden');
    ok(preg_match('#\.pl-meta-item\[hidden\]\s*\{\s*display:\s*none#', file_get_contents(dirname(__DIR__, 2) . '/static/css/posts.css')) === 1, 'hidden wins over inline-flex');
});

// ---- #5 slide picker default ---------------------------------------------------------------------------------------
test('#5 the comment Slide picker starts on All slides (round 4: a general comment is never tagged Slide 1)', function () {
    $b = status(get('posts.php?client=kenda&post=2&partial=1', 'client'), 200)['body'];
    has($b, '<option value="">All slides</option><option value="1">Slide 1</option><option value="2">Slide 2</option>');
});

// ---- #6 Delete tire behind ⋯ ----------------------------------------------------------------------------------------
test('#6 the tire page: Delete tire… is a ⋯ menu item with a confirm, not a red header button', function () {
    $b = status(get('assets.php?client=kenda&view=collections&item=1'), 200)['body'];
    ok(preg_match('#<div class="asg-menu" role="menu" data-asg-menu hidden>\s*<button type="button" role="menuitem" class="is-destructive" data-action="delete_tire"[^>]*data-confirm="Delete “Klever AT2” and all of its images\? This cannot be undone\."[^>]*>Delete tire…</button>#u', $b) === 1, 'in the menu, with a confirm');
    hasNot($b, 'ui-btn--deny ui-btn--tinted" data-action="delete_tire"', 'no red header button');
    has($b, 'data-asg-menu-toggle', '⋯ toggle');
    hasNot(get('assets.php?client=kenda&view=collections&item=1', 'client')['body'], 'delete_tire', 'client: nothing');
});

// ---- #7 / #8 flows ---------------------------------------------------------------------------------------------------
test('#7 Add to flow options carry each step\'s code; #8 flow cards show one timing', function () {
    $j = status(get('assign.php?action=options&client=privacybee&kind=email&ids=4'), 200)['json'];
    is(array_column($j['flows'][0]['steps'], 'code'), ['W1', 'W2', 'W3']);
    has(file_get_contents(dirname(__DIR__, 2) . '/static/css/assign.css'), '.asg-field[hidden] { display: none; }');
    $b = status(get('flows.php?client=privacybee'), 200)['body'];
    hasNot($b, 'fl-trigger', 'no second timing line on the cards');
    hasNot(r3Text($b), 'Day 1 after signup', 'the email trigger is not repeated');
    has(r3Text($b), '3 days after W2', 'the step timing stays');
});

// ---- #10 one date word -----------------------------------------------------------------------------------------------
test('#10 "Post date" in the sheet (both seats, any state) and the composer; never "Planned for" / "Scheduled for"', function () {
    foreach (['posts.php?client=kenda&post=1&partial=1', 'posts.php?client=kenda&post=5&partial=1'] as $u) {
        foreach (['admin', 'client'] as $seat) {
            $t = r3Text(status(get($u, $seat), 200)['body']);
            has($t, 'Post date', "$u $seat");
            hasNot($t, 'Planned for'); hasNot($t, 'Scheduled for');
        }
    }
    $js = file_get_contents(dirname(__DIR__, 2) . '/static/js/newpost.js');
    has($js, '>Post date</label>'); has($js, 'pd-when-label">Post date<');
    hasNot($js, 'Scheduled for'); hasNot($js, 'Planned for');
    hasNot(file_get_contents(dirname(__DIR__, 2) . '/static/js/posts.js'), 'Planned for');
});

// ---- #11 the client's own Needs-changes link --------------------------------------------------------------------------
test('#11 client: their Needs-changes post opens a read-only notice with their note; the activity link points there', function () {
    $b = status(get('posts.php?client=kenda&post=4&partial=1', 'client'), 200)['body'];
    has($b, 'data-hidden-post'); has($b, 'data-title="Winter promo"');
    $t = r3Text($b);
    has($t, 'Joust is updating this post');
    has($t, 'Please use the darker render', 'their note');
    hasNot($b, 'data-comment-form'); hasNot($b, 'data-decide'); hasNot($b, 'data-carousel');
    has($b, 'data-sheet-close>Back to posts</button>');
    status(get('posts.php?client=kenda&post=4', 'client'), 200);
    is(get('posts.php?client=kenda&post=6&partial=1', 'client')['code'], 404, 'drafts stay hidden');
    is(get('posts.php?client=privacybee&post=4&partial=1', 'client')['code'], 404, 'another client\'s post');
    has(get('index.php?client=kenda', 'client')['body'], 'posts.php?client=kenda&amp;post=4', 'Home activity links to it');
    // the admin still gets the full sheet
    has(get('posts.php?client=kenda&post=4&partial=1')['body'], 'data-newpost-resubmit');
});

// ---- #12 leftovers ------------------------------------------------------------------------------------------------------
test('#12 activity: a Draft\'s "created" row says "started a draft" (never "for review")', function () {
    $t = r3Text(status(get('index.php?client=kenda'), 200)['body']);
    has($t, 'started a draft: Behind the scenes');
    hasNot($t, 'Behind the scenes for review');
});
test('#12 the Needs changes note banner without a note says "No note left."', function () {
    db()->exec("DELETE FROM activity_log WHERE entity_type = 'post' AND entity_id = 4 AND action = 'commented'");
    $t = r3Text(status(get('posts.php?client=kenda&post=4&partial=1'), 200)['body']);
    has($t, 'No note left.');
    hasNot($t, 'see the comments below');
});
test('#12 a client without Emails / Pages: the admin\'s Emails / Pages link lands on Manage → Clients (module switch)', function () {
    $r = get('emails.php?client=kenda');
    is($r['code'], 302);
    has($r['location'], 'manage.php?client=kenda&section=clients&edit=1');
    $r = get('admin.php?client=kenda&tab=emails');
    $hops = 0;
    while (in_array($r['code'], [301, 302, 303], true) && $hops++ < 4) $r = get(preg_replace('#^(https?://[^/]+)?/portal/?#', '', $r['location']));
    is($r['code'], 200);
    has($r['body'], 'doesn’t use Emails yet', 'the flash says why');
    is(get('pages.php?client=hmf')['code'], 302, 'pages too');
    status(get('emails.php?client=privacybee'), 200);
    status(get('pages.php?client=privacybee'), 200);
    is(get('emails.php?client=kenda', 'client')['code'], 200, 'the client seat is unchanged');
});

finish();
