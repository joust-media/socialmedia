<?php
/**
 * Admin-first review sheets + one word per action.
 *   - Post sheet: Joust's own next step is the primary per status; the client's Approve / Needs changes live in ⋯
 *     (Approve for client…) and never in the admin footer. The client keeps Needs changes · Approve.
 *   - Needs changes: the client's latest note renders at the TOP of the sheet (before the media).
 *   - Email / page sheets: one button row + ⋯, no status override (no data-set-status), draft → approved is 409.
 *   - posts.php &partial=row: one row + X-Post-Segment / X-Post-Month (App.posts.refresh after a pop-up save).
 *   - No "Deny", "Back to review", "Resubmit for review" or "Save & resubmit" in any rendered page (both seats) or script.
 */
require __DIR__ . '/lib.php';

/** The visible text of a page (inline <template> sheets included); tags, scripts and attributes never count. */
function textOf(string $html): string {
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html) ?? $html;
    return html_entity_decode(strip_tags(str_replace(['<template', '</template>'], ['<div', '</div>'], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
/** The footer's visible button rows (hidden ones dropped) → their button labels. */
function visibleActions(string $html): array {
    $out = [];
    if (!preg_match('#<div class="pd-actions" data-actions>(.*)</div></div></article>#s', $html, $m)) return $out;
    preg_match_all('#<div class="ui-btn-group [^"]*" data-state="([a-z-]+)"( hidden)?>(.*?)</div>#s', $m[1], $groups, PREG_SET_ORDER);
    foreach ($groups as $g) {
        if ($g[2] !== '') continue;
        preg_match_all('#<(?:button|a)\b[^>]*>(.*?)</(?:button|a)>#s', $g[3], $b);
        foreach ($b[1] as $label) $out[] = trim(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return $out;
}

// ---- post sheet: the admin primaries per status ---------------------------------------------------------
$postCases = [
    6 => ['draft',     ['Edit post…', 'Send for review']],
    1 => ['pending',   ['Edit post…']],
    4 => ['denied',    ['Edit & resubmit']],
    3 => ['approved',  ['Edit post…', 'Mark scheduled']],
    5 => ['scheduled', ['Unmark scheduled']],
];
foreach ($postCases as $id => [$st, $want]) {
    test("post sheet (admin, $st): primary row = " . implode(' · ', $want), function () use ($id, $want, $st) {
        $b = status(get("posts.php?client=kenda&post=$id&partial=1"), 200)['body'];
        is(implode(' | ', visibleActions($b)), implode(' | ', $want));
        hasNot($b, 'data-state="decide"', 'no client decision row in the admin footer');
        $note = strpos($b, 'data-pd-note');
        ok($note !== false, 'the note banner is always in the admin DOM');
        if ($st === 'denied') {
            ok(!preg_match('#data-pd-note[^>]*hidden#', $b), 'note shown');
            ok($note < strpos($b, 'data-carousel'), 'the note sits above the media');
            has(textOf($b), 'Kenda Tires asked for changes');
            has(textOf($b), 'Please use the darker render');
            has($b, 'data-newpost-edit="4" data-newpost-resubmit', 'Edit & resubmit opens the pop-up in edit mode');
        } else {
            ok((bool)preg_match('#data-pd-note[^>]*hidden#', $b), 'note hidden unless Needs changes');
        }
    });
}
test('post sheet (admin) ⋯: Approve for client… / Needs changes… / Send for review follow the status', function () {
    $vis = static function (string $b, string $attr): bool {
        return (bool)preg_match('#<button type="button" role="menuitem" ' . preg_quote($attr, '#') . '[^>]*data-state="[a-z-]+">#', $b);
    };
    $pending = get('posts.php?client=kenda&post=1&partial=1')['body'];
    ok($vis($pending, 'data-approve-for-client'), 'To Review: Approve for client…');
    ok($vis($pending, 'data-decide="denied"'), 'To Review: Needs changes…');
    ok(!$vis($pending, 'data-decide="pending"'), 'To Review: no Send for review');
    $denied = get('posts.php?client=kenda&post=4&partial=1')['body'];
    ok($vis($denied, 'data-approve-for-client') && $vis($denied, 'data-decide="pending"') && !$vis($denied, 'data-decide="denied"'), 'Needs changes: approve + send for review');
    $approved = get('posts.php?client=kenda&post=3&partial=1')['body'];
    ok(!$vis($approved, 'data-approve-for-client') && $vis($approved, 'data-decide="denied"'), 'Approved: Needs changes… only');
    has(get('posts.php?client=kenda&post=6&partial=1')['body'], 'data-state="menu-decide" hidden', 'Draft: no client decisions');
});
test('post sheet (client): Needs changes · Approve on To Review, nothing admin', function () {
    $b = status(get('posts.php?client=kenda&post=1&partial=1', 'client'), 200)['body'];
    is(implode(' | ', visibleActions($b)), 'Needs changes | Approve');
    foreach (['data-newpost-edit', 'data-approve-for-client', 'data-pd-note', 'admin-waiting'] as $k) hasNot($b, $k);
    is(visibleActions(get('posts.php?client=kenda&post=3&partial=1', 'client')['body']), [], 'Approved: no buttons');
    is(visibleActions(get('posts.php?client=kenda&post=5&partial=1', 'client')['body']), [], 'Scheduled: no buttons');
});
test('Posts list: admin rows never swipe (the client\'s decision); the client\'s do', function () {
    hasNot(get('posts.php?client=kenda&status=pending&month=all')['body'], 'data-swipe>');
    hasNot(get('posts.php?client=kenda&status=pending&month=all')['body'], 'pl-swipe--approve');
    has(get('posts.php?client=kenda&status=pending&month=all', 'client')['body'], 'pl-swipe--approve');
    $q = get('posts.php?client=kenda&status=denied&month=all')['body'];
    has($q, 'data-newpost-edit="4" data-newpost-resubmit', 'queue row: Edit & resubmit');
});

// ---- posts.php &partial=row (App.posts.refresh) ----------------------------------------------------------
test('row partial: the row as it renders in its own segment + X-Post-Segment / X-Post-Month', function () {
    $r = status(get('posts.php?client=kenda&post=4&partial=row'), 200);
    is($r['headers']['x-post-segment'] ?? null, 'denied');
    ok((bool)preg_match('/^\d{4}-\d{2}$/', $r['headers']['x-post-month'] ?? ''), 'month header');
    has($r['body'], 'data-post-item="4"');
    has($r['body'], 'data-queue', 'queue row (note + Edit & resubmit)');
    has($r['body'], '<template data-post-template="4">', 'carries its detail');
    is(substr_count($r['body'], 'data-post-item='), 1, 'one row');
    $d = status(get('posts.php?client=kenda&post=6&partial=row'), 200);
    is($d['headers']['x-post-segment'] ?? null, 'draft');
    is(get('posts.php?client=kenda&post=6&partial=row', 'client')['code'], 404, 'drafts never reach the client');
    is(get('posts.php?client=kenda&post=999&partial=row')['code'], 404);
});

// ---- email / page sheets ---------------------------------------------------------------------------------
$emailCases = [1 => ['draft', ['Edit', 'Send for review']], 2 => ['pending', ['Edit']], 4 => ['denied', ['Edit', 'Send for review']],
               3 => ['approved', ['Edit', 'Mark live']], 5 => ['live', ['Unmark live']]];
foreach ($emailCases as $id => [$st, $want]) {
    test("email sheet (admin, $st): one row = " . implode(' · ', $want) . ', no status override', function () use ($id, $want, $st) {
        $b = status(get("emails.php?client=privacybee&email=$id&partial=1"), 200)['body'];
        is(implode(' | ', visibleActions($b)), implode(' | ', $want));
        foreach (['data-set-status', 'ed-status-ctl', 'ed-admin-tools', 'data-state="decide"'] as $k) hasNot($b, $k);
        has($b, 'data-approve-for-client', '⋯ Approve for client…');
        has($b, 'data-delete-email', '⋯ Delete');
        if ($st === 'denied') ok(strpos($b, 'data-pd-note') < strpos($b, 'data-preview'), 'note above the preview');
    });
}
test('email sheet (client): Needs changes · Approve', function () {
    $b = status(get('emails.php?client=privacybee&email=2&partial=1', 'client'), 200)['body'];
    is(implode(' | ', visibleActions($b)), 'Needs changes | Approve');
    foreach (['data-approve-for-client', 'data-asg-menu', 'data-delete-email', 'data-pd-note'] as $k) hasNot($b, $k);
});
test('page sheet (admin): one row + ⋯, no status override', function () {
    foreach ([1 => 'Edit', 2 => 'Unmark live', 3 => 'Edit | Send for review'] as $id => $want) {
        $b = status(get("pages.php?client=privacybee&page=$id&partial=1"), 200)['body'];
        is(implode(' | ', visibleActions($b)), $want, "page $id");
        hasNot($b, 'data-set-status');
        hasNot($b, 'pg-admin-tools');
    }
    is(implode(' | ', visibleActions(get('pages.php?client=privacybee&page=1&partial=1', 'client')['body'])), 'Needs changes | Approve');
});
test('email / page status: a draft cannot be approved or sent back — Send for review first (posts\' rule)', function () {
    $r = post('email-status.php', ['id' => 1, 'status' => 'approved', 'actor' => 'admin']);
    is($r['code'], 409);
    is(q1('SELECT status FROM emails WHERE id = 1'), 'draft');
    is(post('page-status.php', ['id' => 3, 'status' => 'approved', 'actor' => 'admin'])['code'], 409);
    status(post('email-status.php', ['id' => 2, 'status' => 'approved', 'actor' => 'admin']), 200);   // Approve for client… (To Review)
    is(q1('SELECT status FROM emails WHERE id = 2'), 'approved');
});

// ---- one word per action -----------------------------------------------------------------------------------
$banned = ['Deny', 'Denied', 'Back to review', 'Resubmit for review', 'Save & resubmit', 'Resubmitted', 'reopened'];
test('no Deny / Back to review / Resubmit wording in any rendered page (admin + client)', function () use ($banned) {
    $pages = ['', '?client=kenda', '?client=privacybee'];
    foreach (['draft', 'pending', 'approved', 'scheduled', 'denied'] as $s) $pages[] = "posts.php?client=kenda&status=$s&month=all";
    foreach ([1, 2, 3, 4, 5, 6, 7] as $id) $pages[] = "posts.php?client=kenda&post=$id&partial=1";
    foreach (['all', 'draft', 'pending', 'approved', 'denied', 'live'] as $s) { $pages[] = "emails.php?client=privacybee&status=$s"; $pages[] = "pages.php?client=privacybee&status=$s"; }
    foreach ([1, 2, 3, 4, 5] as $id) $pages[] = "emails.php?client=privacybee&email=$id&partial=1";
    foreach ([1, 2, 3] as $id) $pages[] = "pages.php?client=privacybee&page=$id&partial=1";
    $pages[] = 'assets.php?client=kenda&view=collections&item=1&series=1';
    $pages[] = 'assets.php?client=kenda&view=library';
    $pages[] = 'flows.php?client=privacybee';
    foreach (['admin', 'client'] as $role) {
        foreach ($pages as $p) {
            $r = get($p, $role);
            if ($r['code'] !== 200) continue;   // seat without access (client on an admin-only status)
            $t = textOf($r['body']);
            foreach ($banned as $w) ok(!preg_match('/\b' . preg_quote($w, '/') . '\b/', $t), "$role $p: \"$w\" in the page");
        }
    }
});
test('no Deny / Back to review / Resubmit wording in the scripts (toasts, labels)', function () use ($banned) {
    $root = dirname(__DIR__, 2);
    foreach (glob($root . '/static/js/*.js') as $f) {
        $src = preg_replace('#/\*.*?\*/#s', '', file_get_contents($f));   // comments are not UI
        $src = preg_replace('#(^|[\s;{}(),])//[^\n]*#', '$1', $src);
        preg_match_all("/'(?:[^'\\\\]|\\\\.)*'/", $src, $m);   // string literals only (identifiers like openDeny don't count)
        foreach ($m[0] as $lit) {
            foreach ($banned as $w) ok(!preg_match('/\b' . preg_quote($w, '/') . '\b/', $lit), basename($f) . ": $lit");
        }
    }
});
test('activity reads "requested changes" and "sent for review"', function () {
    status(post('status.php', ['id' => 1, 'status' => 'denied', 'comment' => 'Brighter please', 'client' => 'kenda'], 'client'), 200);
    $h = textOf(get('?client=kenda')['body']);
    has($h, 'requested changes');
    hasNot($h, 'changes requested on');
    status(post('status.php', ['id' => 1, 'status' => 'pending', 'actor' => 'admin']), 200);
    ok((bool)preg_match('/ sent for review$/', (string)q1("SELECT summary FROM activity_log WHERE entity_type = 'post' AND entity_id = 1 AND action = 'reset_pending' ORDER BY id DESC LIMIT 1")), 'resubmit summary');
    has((string)q1("SELECT summary FROM activity_log WHERE entity_type = 'post' AND entity_id = 1 AND action = 'denied' ORDER BY id DESC LIMIT 1"), 'requested changes');
});

finish();
