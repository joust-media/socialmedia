<?php
/** Seat + tenant gates on the state-changing endpoints (server-side, never CSS). */
require __DIR__ . '/lib.php';

test('client cannot act on another client\'s post (tenant)', function () {
    $r = post('status.php', ['id' => 1, 'status' => 'approved', 'client' => 'privacybee'], 'client');
    is($r['code'], 403);
    is(q1("SELECT status FROM posts WHERE id = 1"), 'pending', 'unchanged');
});
test('client cannot act without a client slug (no session for the test seat → 401)', function () {
    is(post('status.php', ['id' => 1, 'status' => 'approved'], 'client')['code'], 401);
    is(q1("SELECT status FROM posts WHERE id = 1"), 'pending', 'unchanged');
});
test('a client signed in for Privacy Bee cannot act on a Kenda post, even posting client=kenda', function () {
    $r = post('status.php', ['id' => 1, 'status' => 'approved', 'client' => 'kenda'], 'client:privacybee');
    is($r['code'], 403);
    is(q1("SELECT status FROM posts WHERE id = 1"), 'pending', 'unchanged');
});
foreach ([
    'toggle_posted' => ['action' => 'toggle_posted', 'id' => 3, 'to' => 1],
    'delete_post'   => ['action' => 'delete_post', 'id' => 1],
    'submit'        => ['action' => 'submit', 'id' => 6],
    'scheduled_date'=> ['id' => 1, 'scheduled_date' => '2030-01-01 10:00'],
    'post_type'     => ['id' => 1, 'post_type' => 'reel'],
    'status=pending'=> ['id' => 4, 'status' => 'pending'],
    'status=draft'  => ['id' => 1, 'status' => 'draft'],
] as $what => $data) {
    test("client seat refused: {$what}", function () use ($data) {
        $r = post('status.php', $data + ['client' => 'kenda'], 'client');
        ok(in_array($r['code'], [400, 403], true), "HTTP {$r['code']} (want 403/400)");
        is($r['json']['ok'] ?? null, false);
    });
}
test('post rows survive the refused client requests', function () {
    is((int)q1("SELECT COUNT(*) FROM posts WHERE company_id = 1"), 7);
    is((int)q1("SELECT posted FROM posts WHERE id = 3"), 0);
});

test('cross-site POST refused', function () {
    $r = post('status.php', ['id' => 1, 'status' => 'approved'], 'admin', [], ['Sec-Fetch-Site' => 'cross-site']);
    is($r['code'], 403);
});
test('status.php is POST only', function () {
    is(get('status.php?id=1', 'admin')['code'], 405);
});

test('upload-chunk.php: client 403', function () {
    is(post('upload-chunk.php?client=kenda', ['action' => 'probe'], 'client')['code'], 403);
});
test('batch-process.php: client 401', function () {
    is(post('batch-process.php?client=kenda', ['rows' => '[]'], 'client')['code'], 401);
});
test('add-post.php POST: client → sign-in', function () {
    $r = post('add-post.php?client=kenda', ['action' => 'create', 'caption' => 'x', 'scheduled_date' => '2030-01-01T10:00'], 'client');
    is($r['code'], 302);
    has($r['location'], 'login');
    is((int)q1("SELECT COUNT(*) FROM posts WHERE caption = 'x'"), 0, 'nothing created');
});
test('preview-job.php: client 403', function () {
    is(post('preview-job.php?client=kenda', ['action' => 'status', 'scope' => 'client'], 'client')['code'], 403);
});

test('client post sheet carries no admin markup', function () {
    $r = status(get('posts.php?client=kenda&post=1&partial=1', 'client'), 200);
    hasNot($r['body'], 'data-menu-toggle');
    hasNot($r['body'], 'data-delete-post');
    hasNot($r['body'], 'data-submit-post');
    hasNot($r['body'], 'data-toggle-posted');
    has($r['body'], 'data-decide="approved"', 'client can approve');
});
test('admin post sheet has the admin menu', function () {
    $r = status(get('posts.php?client=kenda&post=1&partial=1', 'admin'), 200);
    has($r['body'], 'data-menu-toggle');
    has($r['body'], 'data-delete-post');
});
test('client sees its denied posts only as its own Sent back (never another client\'s, never a draft)', function () {
    // Its Needs-changes post is its "Sent back" (tests/smoke/25-sent-back.php): the full post, read + comment + Approve instead
    $r = status(get('posts.php?client=kenda&status=denied&month=all', 'client'), 200);
    has($r['body'], 'data-post-item="4"');
    $p = status(get('posts.php?client=kenda&post=4&partial=1', 'client'), 200)['body'];
    has($p, 'data-sentback'); has($p, 'data-carousel'); has($p, 'data-comment-form'); has($p, 'data-approve-instead');
    has($p, '<div class="ui-btn-group pd-decide" data-state="decide" hidden>', 'no second Needs changes (the To Review pair stays hidden)');
    is(get('posts.php?client=kenda&post=6&partial=1', 'client')['code'], 404, 'a draft stays not found');
    is(get('posts.php?client=privacybee&post=4&partial=1', 'client:privacybee')['code'], 404, 'another client\'s post stays not found');
});
test('client never sees draft emails / pages', function () {
    $r = status(get('emails.php?client=privacybee&status=all', 'client'), 200);
    hasNot($r['body'], 'Welcome to Privacy Bee', 'draft email W1 hidden');
    $r = status(get('pages.php?client=privacybee&status=all', 'client'), 200);
    hasNot($r['body'], 'Webinar signup', 'draft page hidden');
});

finish();
