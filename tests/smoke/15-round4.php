<?php
/**
 * Round-4 UX fixes (admin re-score round 3 = 90 → margin):
 *   #1  (P1-2) the admin's tab badges count Joust's own queue (Needs changes), the client's stay on To Review;
 *       Posts / Emails / Pages open on Needs changes for the admin when it has items (an explicit ?status= wins).
 *   #2  (P1-3) the comment Slide picker renders on "All slides" (posts.js follows the carousel once it moves).
 *   #3  the full email / page form: the Status help line fits the status (no "Approve for client…" on a Draft);
 *       "Approved — unchanged" / "Needs changes — unchanged" instead of "(keep)".
 *   #4  the email / page sheet ⋯ has Edit … only for a live row (every other state has Edit in the footer).
 *   #5  the client's Needs-changes notice shows Joust's replies after their note.
 *   #6  the tire series row: the admin's "Approve all remaining" is a gray secondary after Upload.
 *   #7  add-email.php / add-page.php use the 720 px column.
 *   #8  admin.php / studio.php ?tab=emails|pages for a client without the module: one hop to Manage → Clients.
 * Seed (tests/seed.php): Kenda posts 1, 2 pending · 4 denied · 6, 7 draft; tire images 2 pending · 2 denied; library
 * 2 pending. Privacy Bee emails 1 draft · 2 pending · 3 approved · 4 denied · 5 live; pages 1 pending · 2 live · 3 draft.
 */
require __DIR__ . '/lib.php';

/** [count, aria-label, data-queue] of a tab's badge, or null when the tab has none. */
function r4Badge(string $html, string $tab): ?array {
    if (!preg_match('#<a class="ui-tab ui-tab--' . preg_quote($tab, '#') . '[^"]*"[^>]*>(.*?)</a>#s', $html, $m)) return null;
    if (!preg_match('#<span class="ui-badge ui-tab-badge" aria-label="([^"]*)" data-queue="([a-z]+)">([^<]*)</span>#', $m[1], $b)) return null;
    return [$b[3], $b[1], $b[2]];
}
/** The active list segment (data-segment on the list section). */
function r4Segment(string $html, string $what): string {
    return preg_match('#data-' . $what . '-list data-segment="([a-z]+)"#', $html, $m) ? $m[1] : '';
}

// ---- #1 badges + landing segment --------------------------------------------------------------------------------
test('#1 admin tab badges count Needs changes (Joust\'s queue); the client\'s count To Review', function () {
    $a = status(get('posts.php?client=kenda&status=pending'), 200)['body'];
    is(r4Badge($a, 'posts'), ['1', '1 need changes', 'denied'], 'admin Posts = 1 Needs changes (post 4)');
    is(r4Badge($a, 'tires'), ['2', '2 need changes', 'denied'], 'admin Tires = 2 Needs changes images');
    is(r4Badge($a, 'assets'), null, 'admin Assets: no library image needs changes → no badge');
    $c = status(get('posts.php?client=kenda', 'client'), 200)['body'];
    is(r4Badge($c, 'posts'), ['2', '2 to review', 'pending'], 'client Posts = 2 To Review');
    is(r4Badge($c, 'tires'), ['2', '2 to review', 'pending'], 'client Tires = 2 To Review');
    is(r4Badge($c, 'assets'), ['2', '2 to review', 'pending'], 'client Assets = 2 To Review');
    $e = status(get('emails.php?client=privacybee&status=pending'), 200)['body'];
    is(r4Badge($e, 'emails'), ['1', '1 need changes', 'denied'], 'admin Emails = R1');
    is(r4Badge(status(get('emails.php?client=privacybee', 'client'), 200)['body'], 'emails'), ['1', '1 to review', 'pending'], 'client Emails = W2');
});
test('#1 the admin opens Posts / Emails / Pages on Needs changes when it has items (?status= wins; the client is unchanged)', function () {
    $b = status(get('posts.php?client=kenda'), 200)['body'];
    is(r4Segment($b, 'posts'), 'denied', 'posts: Needs changes');
    has($b, 'data-post-item="4"', 'the queue row');
    is(r4Segment(status(get('posts.php?client=kenda&status=pending'), 200)['body'], 'posts'), 'pending', 'explicit To Review');
    is(r4Segment(status(get('posts.php?client=kenda&post=1'), 200)['body'], 'posts'), 'pending', 'a deep link keeps its own segment');
    is(r4Segment(status(get('posts.php?client=kenda', 'client'), 200)['body'], 'posts'), 'pending', 'client: To Review');
    is(r4Segment(status(get('emails.php?client=privacybee'), 200)['body'], 'emails'), 'denied', 'emails: Needs changes');
    is(r4Segment(status(get('emails.php?client=privacybee', 'client'), 200)['body'], 'emails'), 'pending', 'client emails');
    is(r4Segment(status(get('pages.php?client=privacybee'), 200)['body'], 'pages'), 'pending', 'pages: nothing needs changes → To Review');
    db()->exec("UPDATE pages SET status = 'denied' WHERE id = 1");
    is(r4Segment(status(get('pages.php?client=privacybee'), 200)['body'], 'pages'), 'denied', 'pages: Needs changes once it has one');
    db()->exec("UPDATE posts SET status = 'pending' WHERE id = 4");
    $b = status(get('posts.php?client=kenda'), 200)['body'];
    is(r4Segment($b, 'posts'), 'pending', 'posts: an empty queue → To Review');
    is(r4Badge($b, 'posts'), null, 'and no Posts badge');
    db()->exec("UPDATE pages SET status = 'pending' WHERE id = 1");   // back to the seed for the tests below
    db()->exec("UPDATE posts SET status = 'denied' WHERE id = 4");
});

// ---- #2 slide picker ---------------------------------------------------------------------------------------------
test('#2 the Slide picker renders on All slides (no slide preselected)', function () {
    foreach (['admin', 'client'] as $seat) {
        $b = status(get('posts.php?client=kenda&post=2&partial=1', $seat), 200)['body'];
        ok(preg_match('#<select class="pd-composer-slide"[^>]*data-comment-slide[^>]*>(.*?)</select>#s', $b, $m) === 1, "$seat: picker");
        hasNot($m[1], 'selected', "$seat: nothing preselected → All slides");
    }
});

// ---- #3 the form's Status help + labels --------------------------------------------------------------------------
test('#3 add-email / add-page: the Status help fits the status; "— unchanged" instead of "(keep)"', function () {
    $help = static function (string $html): string {
        return preg_match('#<p class="studio-help" data-status-help>(.*?)</p>#s', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
    };
    $d = status(get('add-email.php?client=privacybee&edit=1'), 200)['body'];
    is($help($d), 'The client approves. Send it for review when it’s ready.', 'draft W1');
    hasNot($d, 'Approve for client…', 'no pointer to a ⋯ item a Draft does not have');
    has($help(status(get('add-email.php?client=privacybee&edit=2'), 200)['body']), 'Approve for client… is in the email’s ⋯ menu', 'pending W2 keeps the hint');
    $a = status(get('add-email.php?client=privacybee&edit=3'), 200)['body'];
    has($a, '<option value="approved" selected>Approved — unchanged</option>');
    has($help($a), 'Saving keeps it approved', 'approved W3');
    $n = status(get('add-email.php?client=privacybee&edit=4'), 200)['body'];
    has($n, '<option value="denied" selected>Needs changes — unchanged</option>');
    has($help($n), 'asked for changes', 'denied R1');
    hasNot($a . $n, '(keep)');
    is($help(status(get('add-email.php?client=privacybee'), 200)['body']), 'The client approves. Send it for review when it’s ready.', 'new email');
    $p = status(get('add-page.php?client=privacybee&edit=3'), 200)['body'];
    is($help($p), 'The client approves. Send it for review when it’s ready.', 'draft page 3');
    hasNot($p, 'Approve for client…');
    has($help(status(get('add-page.php?client=privacybee&edit=1'), 200)['body']), 'Approve for client… is in the page’s ⋯ menu', 'pending page 1');
});

// ---- #4 one Edit entry -------------------------------------------------------------------------------------------
test('#4 email / page sheet ⋯: Edit … only for a live row (the footer has Edit everywhere else)', function () {
    $menuEdit = static function (string $html): string {
        return preg_match('#<div class="pd-menu-group" role="group" data-state="menu-edit"( hidden)?>#', $html, $m) ? (isset($m[1]) && $m[1] !== '' ? 'hidden' : 'shown') : 'missing';
    };
    foreach ([1 => 'hidden', 2 => 'hidden', 3 => 'hidden', 4 => 'hidden', 5 => 'shown'] as $id => $want) {
        is($menuEdit(status(get("emails.php?client=privacybee&email=$id&partial=1"), 200)['body']), $want, "email $id");
    }
    is($menuEdit(status(get('pages.php?client=privacybee&page=1&partial=1'), 200)['body']), 'hidden', 'page 1 (pending)');
    is($menuEdit(status(get('pages.php?client=privacybee&page=2&partial=1'), 200)['body']), 'shown', 'page 2 (live)');
    $js = file_get_contents(dirname(__DIR__, 2) . '/static/js/emails.js') . file_get_contents(dirname(__DIR__, 2) . '/static/js/pages.js');
    is(substr_count($js, "'menu-edit':      live"), 2, 'the JS state sync keeps it in step');
});

// ---- #5 the client's notice shows Joust's replies ----------------------------------------------------------------
test('#5 the client\'s Needs-changes notice lists Joust\'s replies after their note', function () {
    $b = status(get('posts.php?client=kenda&post=4&partial=1', 'client'), 200)['body'];
    hasNot($b, 'data-hidden-reply', 'no reply yet');
    status(post('status.php', ['id' => 4, 'comment' => 'Darker render coming Friday', 'client' => 'kenda']), 200);
    $b = status(get('posts.php?client=kenda&post=4&partial=1', 'client'), 200)['body'];
    has($b, 'data-hidden-note', 'the client\'s note');
    ok(preg_match('#<figure class="pd-hidden-note pd-hidden-note--joust" data-hidden-reply><figcaption class="pd-hidden-note-head"><img class="ui-avatar ui-avatar--joust[^"]*"[^>]*data-joust-logo>Joust replied[^<]*</figcaption><blockquote>Darker render coming Friday</blockquote>#u', $b) === 1, 'Joust\'s reply, read-only');
    ok(strpos($b, 'data-hidden-note') < strpos($b, 'data-hidden-reply'), 'note first, then the reply');
});

// ---- #6 series row weight ----------------------------------------------------------------------------------------
test('#6 the tire series row: admin "Approve all remaining" is a gray secondary; the client keeps the green tint', function () {
    $q = 'assets.php?client=kenda&view=collections&item=1&series=1';
    $a = status(get($q), 200)['body'];
    ok(preg_match('#<button type="button" class="ui-btn ui-btn--sm ui-btn--gray as-series-approve--admin as-series-approve"#', $a) === 1, 'admin: gray');
    hasNot($a, 'ui-btn--approve ui-btn--tinted as-series-approve', 'admin: no green tint');
    has(status(get($q, 'client'), 200)['body'], 'class="ui-btn ui-btn--sm ui-btn--approve ui-btn--tinted as-series-approve"', 'client: green tint');
});

// ---- #7 720 px forms ---------------------------------------------------------------------------------------------
test('#7 add-email.php / add-page.php sit in the 720 px column', function () {
    foreach (['add-email.php?client=privacybee', 'add-email.php?client=privacybee&edit=2', 'add-page.php?client=privacybee', 'add-page.php?client=privacybee&edit=1'] as $p) {
        $b = status(get($p), 200)['body'];
        has($b, '<main class="ui-page" id="main">', $p);
        hasNot($b, 'ui-nav--wide', $p . ': nav too');
    }
});

// ---- #8 one hop ----------------------------------------------------------------------------------------------------
test('#8 admin.php / studio.php ?tab=emails|pages for a client without the module: one hop to Manage → Clients', function () {
    foreach (['admin.php?client=kenda&tab=emails' => ['1', 'Emails'], 'studio.php?client=kenda&tab=emails' => ['1', 'Emails'],
              'admin.php?client=hmf&tab=pages' => [null, 'Pages'], 'legacy/admin.php?client=kenda&tab=emails' => ['1', 'Emails']] as $p => [$edit, $what]) {
        $r = get($p);
        is($r['code'], 301, $p);
        has($r['location'], 'manage.php?client=', $p . ': straight to Manage');
        has($r['location'], 'section=clients', $p);
        if ($edit) has($r['location'], 'edit=' . $edit, $p);
        $r2 = get(preg_replace('#^(https?://[^/]+)?/portal/?#', '', $r['location']));
        is($r2['code'], 200, $p . ': lands');
        has($r2['body'], 'doesn’t use ' . $what . ' yet', $p . ': the flash');
    }
    has(get('admin.php?client=privacybee&tab=emails')['location'], 'emails.php?client=privacybee', 'a client with Emails: unchanged');
    has(get('admin.php?client=privacybee&tab=pages')['location'], 'pages.php?client=privacybee', 'a client with Pages: unchanged');
});

finish();
