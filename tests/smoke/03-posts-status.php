<?php
/**
 * status.php matrix (both seats) + the Draft state end to end.
 * Seed: 1 pending · 2 pending carousel · 3 approved · 4 denied · 5 scheduled · 6 draft (caption) · 7 draft (no caption).
 */
require __DIR__ . '/lib.php';

function st(int $id): string { return (string)q1("SELECT status FROM posts WHERE id = ?", [$id]); }
function clientSees(int $id): bool {
    foreach (['pending', 'approved', 'scheduled', 'denied', 'draft'] as $seg) {
        $r = get("posts.php?client=kenda&status={$seg}&month=all", 'client');
        if (strpos($r['body'], 'data-post-item="' . $id . '"') !== false) return true;
    }
    return false;
}

// ---- admin --------------------------------------------------------------------------------
test('admin: approve pending', function () {
    $r = status(post('status.php', ['id' => 1, 'status' => 'approved']), 200);
    is($r['json']['ok'] ?? null, true);
    is(st(1), 'approved');
});
test('admin: deny without a note → 422', function () {
    is(post('status.php', ['id' => 2, 'status' => 'denied'])['code'], 422);
    is(st(2), 'pending');
});
test('admin: deny with a note', function () {
    status(post('status.php', ['id' => 2, 'status' => 'denied', 'comment' => 'Swap slide 2']), 200);
    is(st(2), 'denied');
});
test('admin: back to review', function () {
    status(post('status.php', ['id' => 2, 'status' => 'pending']), 200);
    is(st(2), 'pending');
});
test('admin: invalid status → 400', function () {
    is(post('status.php', ['id' => 2, 'status' => 'bogus'])['code'], 400);
});
test('admin: schedule a pending post → 409', function () {
    is(post('status.php', ['action' => 'toggle_posted', 'id' => 2, 'to' => 1])['code'], 409);
});
test('admin: schedule an approved post', function () {
    status(post('status.php', ['action' => 'toggle_posted', 'id' => 3, 'to' => 1]), 200);
    is((int)q1("SELECT posted FROM posts WHERE id = 3"), 1);
});
test('admin: caption frozen once scheduled → 409', function () {
    is(post('status.php', ['id' => 3, 'caption' => 'new'])['code'], 409);
});
test('admin: reschedule', function () {
    status(post('status.php', ['id' => 2, 'scheduled_date' => '2031-03-04 09:30']), 200);
    is(q1("SELECT scheduled_date FROM posts WHERE id = 2"), '2031-03-04 09:30:00');
});
test('admin: unknown post → 404', function () {
    is(post('status.php', ['id' => 999, 'status' => 'approved'])['code'], 404);
});

// ---- client --------------------------------------------------------------------------------
test('client: approve own pending post', function () {
    status(post('status.php', ['id' => 2, 'status' => 'approved', 'client' => 'kenda'], 'client'), 200);
    is(st(2), 'approved');
    is(q1("SELECT actor FROM activity_log WHERE entity_id = 2 AND action = 'approved' ORDER BY id DESC LIMIT 1"), 'client');
});
test('client: a denied post can only be approved instead (Sent back), never re-denied', function () {
    is(post('status.php', ['id' => 4, 'status' => 'denied', 'comment' => 'Still wrong', 'client' => 'kenda'], 'client')['code'], 403);
    is(post('status.php', ['id' => 4, 'status' => 'pending', 'client' => 'kenda'], 'client')['code'], 403);
    status(post('status.php', ['id' => 4, 'status' => 'approved', 'client' => 'kenda'], 'client'), 200);
    is(q1("SELECT status FROM posts WHERE id = 4"), 'approved');
});
test('client: edits a caption, empty caption refused', function () {
    status(post('status.php', ['id' => 2, 'caption' => 'Client wording', 'client' => 'kenda'], 'client'), 200);
    is(q1("SELECT caption FROM posts WHERE id = 2"), 'Client wording');
    is(post('status.php', ['id' => 2, 'caption' => '   ', 'client' => 'kenda'], 'client')['code'], 400);
});
test('client: comment', function () {
    $r = status(post('status.php', ['id' => 2, 'comment' => 'Looks great', 'client' => 'kenda'], 'client'), 200);
    is($r['json']['comment'] ?? null, 'Looks great');
});

// ---- drafts: invisible to the client ----------------------------------------------------------
test('drafts: client lists never include them', function () {
    ok(!clientSees(6), 'draft 6 hidden');
    ok(!clientSees(7), 'draft 7 hidden');
});
test('drafts: client deep link / partial → 404', function () {
    is(get('posts.php?client=kenda&post=6&partial=1', 'client')['code'], 404);
    $r = status(get('posts.php?client=kenda&post=6', 'client'), 200);
    hasNot($r['body'], 'data-post-template="6"', 'no detail for a draft');
    hasNot($r['body'], 'Behind the scenes');
});
test('drafts: client has no Drafts segment', function () {
    hasNot(get('posts.php?client=kenda', 'client')['body'], 'data-segment="draft"');
});
test('drafts: client Home + activity never mention them', function () {
    $r = status(get('?client=kenda', 'client'), 200);
    hasNot($r['body'], 'Behind the scenes');
    hasNot($r['body'], 'started a draft');
});
test('drafts: client cannot act on a draft (404)', function () {
    foreach ([['status' => 'approved'], ['comment' => 'hi'], ['caption' => 'x']] as $d) {
        $r = post('status.php', ['id' => 6, 'client' => 'kenda'] + $d, 'client');
        is($r['code'], 404, json_encode($d));
    }
    is(st(6), 'draft');
});
test('drafts: tab badge counts only To Review', function () {
    $r = status(get('posts.php?client=kenda', 'client'), 200);
    $pending = (int)q1("SELECT COUNT(*) FROM posts WHERE company_id = 1 AND status = 'pending' AND posted = 0");
    ok(preg_match('/data-segment="pending"[^>]*>.*?ui-segmented-count[^>]*>(\d+)/s', $r['body'], $m) === 1, 'To Review count rendered');
    is((int)$m[1], $pending);
});

// ---- drafts: admin -------------------------------------------------------------------------------
test('drafts: admin Drafts segment comes first and lists both', function () {
    $r = status(get('posts.php?client=kenda&status=draft&month=all', 'admin'), 200);
    $first = strpos($r['body'], 'data-segment="draft"');
    ok($first !== false && $first < strpos($r['body'], 'data-segment="pending"'), 'Drafts before To Review');
    has($r['body'], 'data-post-item="6"');
    has($r['body'], 'data-post-item="7"');
    has($r['body'], 'data-submit-post="6"');
    hasNot($r['body'], 'data-post-item="1"');
});
test('drafts: approve / deny a draft → 409', function () {
    is(post('status.php', ['id' => 6, 'status' => 'approved'])['code'], 409);
    is(post('status.php', ['id' => 6, 'status' => 'denied', 'comment' => 'nope'])['code'], 409);
    is(st(6), 'draft');
});
test('drafts: Send for review without a caption → 422', function () {
    $r = post('status.php', ['action' => 'submit', 'id' => 7]);
    is($r['code'], 422);
    is($r['json']['error'] ?? null, 'Add a caption first');
    is(st(7), 'draft');
    is(post('status.php', ['id' => 7, 'status' => 'pending'])['code'], 422, 'generic path keeps the rule');
});
test('drafts: Send for review on a non-draft → 409', function () {
    is(post('status.php', ['action' => 'submit', 'id' => 4])['code'], 409);
});
test('drafts: Send for review → To Review, logged, visible to the client', function () {
    $r = status(post('status.php', ['action' => 'submit', 'id' => 6]), 200);
    is($r['json']['status'] ?? null, 'pending');
    is(st(6), 'pending');
    is(q1("SELECT action FROM activity_log WHERE entity_type = 'post' AND entity_id = 6 ORDER BY id DESC LIMIT 1"), 'submitted');
    ok(clientSees(6), 'client sees it now');
    has(get('?client=kenda', 'client')['body'], 'sent', 'client feed says it was sent for review');
});
test('drafts: admin moves it back to drafts → hidden again', function () {
    status(post('status.php', ['id' => 6, 'status' => 'draft']), 200);
    is(st(6), 'draft');
    ok(!clientSees(6));
    is(get('posts.php?client=kenda&post=6&partial=1', 'client')['code'], 404);
});
test('drafts: a scheduled post cannot go back to drafts', function () {
    is(post('status.php', ['id' => 5, 'status' => 'draft'])['code'], 409);
});
test('drafts: caption first, then Send for review works', function () {
    status(post('status.php', ['id' => 7, 'caption' => 'Now it has words']), 200);
    status(post('status.php', ['action' => 'submit', 'id' => 7]), 200);
    is(st(7), 'pending');
});
test('drafts: admin activity reads well', function () {
    $r = status(get('?client=kenda', 'admin'), 200);
    has($r['body'], 'Behind the scenes');
});

finish();
