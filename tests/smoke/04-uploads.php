<?php
/** upload-chunk.php (single request + pieces), claims → add-post / batch-process, and what those create (drafts). */
require __DIR__ . '/lib.php';

/** Upload one file with purpose=$purpose in a single request → token. */
function uploadOne(string $file, string $purpose = 'post'): string {
    $r = status(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => $purpose, 'client' => 'kenda'], 'admin', ['file' => $file]), 200, 'upload');
    ok(!empty($r['json']['token']), 'token returned');
    return (string)$r['json']['token'];
}

test('probe answers the chunk size and caps', function () {
    $r = status(get('upload-chunk.php?action=probe&client=kenda', 'admin'), 200);
    ok(($r['json']['chunk_size'] ?? 0) > 0, 'chunk_size');
    ok(($r['json']['max_file_bytes']['image'] ?? 0) >= 50 * 1024 * 1024, 'image cap');
});

test('single-request upload → claim token (purpose=post)', function () {
    $tok = uploadOne(tmpImage('single'));
    ok(preg_match('/^[A-Za-z0-9_\-]{8,}$/', $tok) === 1);
});

test('chunked upload (3 pieces) → claim token', function () {
    $file = tmpImage('chunked', 1600, 1200);
    $bytes = file_get_contents($file);
    $size = strlen($bytes);
    $init = status(post('upload-chunk.php?client=kenda', ['action' => 'chunk_init', 'purpose' => 'post', 'client' => 'kenda',
        'name' => 'chunked.jpg', 'size' => $size, 'type' => 'image/jpeg']), 200, 'init');
    $id = (string)$init['json']['upload_id'];
    $piece = (int)ceil($size / 3);
    for ($i = 0, $off = 0; $off < $size; $i++, $off += $piece) {
        $part = sys_get_temp_dir() . "/smoke_part_{$i}.bin";
        file_put_contents($part, substr($bytes, $off, $piece));
        $r = status(post('upload-chunk.php?client=kenda', ['action' => 'chunk_put', 'upload_id' => $id, 'index' => $i, 'offset' => $off], 'admin', ['file' => $part]), 200, "piece $i");
        is((int)$r['json']['received'], min($size, $off + $piece));
    }
    $fin = status(post('upload-chunk.php?client=kenda', ['action' => 'chunk_finish', 'upload_id' => $id]), 200, 'finish');
    ok(!empty($fin['json']['token']));
});

test('unsupported type → 415', function () {
    $f = sys_get_temp_dir() . '/smoke_note.txt';
    file_put_contents($f, 'hello');
    is(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'post', 'client' => 'kenda'], 'admin', ['file' => $f])['code'], 415);
});
test('a .jpg that is not an image → 422', function () {
    $f = sys_get_temp_dir() . '/smoke_fake.jpg';
    file_put_contents($f, str_repeat('x', 2048));
    is(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'post', 'client' => 'kenda'], 'admin', ['file' => $f])['code'], 422);
});
test('client seat cannot upload', function () {
    is(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'post', 'client' => 'kenda'], 'client', ['file' => tmpImage('c')])['code'], 403);
});

test('Uploads tab path: batch claim → one DRAFT post, empty caption, logged as drafted', function () {
    $tok = uploadOne(tmpImage('batchfile'), 'batch');
    $r = status(post('batch-process.php?client=kenda', ['claimed' => [$tok], 'client' => 'kenda']), 200);
    $c = $r['json']['created'][0] ?? null;
    ok($c !== null, 'created');
    is($c['status'] ?? null, 'draft');
    $row = rows("SELECT status, caption FROM posts WHERE id = ?", [(int)$c['post_id']])[0];
    is($row['status'], 'draft');
    is($row['caption'], '', 'no placeholder caption');
    is(q1("SELECT action FROM activity_log WHERE entity_type = 'post' AND entity_id = ?", [(int)$c['post_id']]), 'drafted');
    is(get('posts.php?client=kenda&post=' . (int)$c['post_id'] . '&partial=1', 'client')['code'], 404, 'client cannot see it');
});
test('a post claim cannot be used as a batch claim (purpose is checked)', function () {
    $tok = uploadOne(tmpImage('wrongpurpose'), 'post');
    $r = status(post('batch-process.php?client=kenda', ['claimed' => [$tok], 'client' => 'kenda']), 200);
    is(count($r['json']['created'] ?? []), 0);
    ok(count($r['json']['errors'] ?? []) === 1, 'reported');
});

test('add-post: claimed upload + caption → post with that media, JSON', function () {
    $tok = uploadOne(tmpImage('composer'));
    $r = status(post('add-post.php?client=kenda', ['action' => 'create', 'format' => 'json', 'caption' => 'From an upload',
        'scheduled_date' => '2031-01-02T10:00', 'status' => 'pending', 'claimed' => [$tok]]), 200);
    $pid = (int)$r['json']['post_id'];
    is((int)q1("SELECT COUNT(*) FROM post_images WHERE post_id = ?", [$pid]), 1);
    is(count($r['json']['media'] ?? []), 1, 'media echoed');
    has((string)($r['json']['post_url'] ?? ''), 'post=' . $pid);
});
test('add-post: no caption + To Review → 422; as a Draft → saved', function () {
    $r = post('add-post.php?client=kenda', ['action' => 'create', 'format' => 'json', 'caption' => '', 'scheduled_date' => '2031-01-02T10:00', 'status' => 'pending', 'assets' => ['library:1']]);
    is($r['code'], 422);
    $r = status(post('add-post.php?client=kenda', ['action' => 'create', 'format' => 'json', 'caption' => '', 'scheduled_date' => '2031-01-02T10:00', 'status' => 'draft', 'assets' => ['library:1']]), 200);
    is(q1("SELECT status FROM posts WHERE id = ?", [(int)$r['json']['post_id']]), 'draft');
    is(q1("SELECT action FROM activity_log WHERE entity_type = 'post' AND entity_id = ?", [(int)$r['json']['post_id']]), 'drafted');
});
test('add-post (form post) lands on the new post, not the Studio tab', function () {
    $r = post('add-post.php?client=kenda', ['action' => 'create', 'caption' => 'Landing', 'scheduled_date' => '2031-01-02T10:00', 'status' => 'pending', 'assets' => ['library:2']]);
    is($r['code'], 302);
    $pid = (int)q1("SELECT MAX(id) FROM posts");
    has($r['location'], 'posts.php?client=kenda&post=' . $pid);
});
test('add-post: a pool pick that is not approved → 403, nothing saved', function () {
    $before = (int)q1("SELECT COUNT(*) FROM posts");
    $r = post('add-post.php?client=kenda', ['action' => 'create', 'format' => 'json', 'caption' => 'x', 'scheduled_date' => '2031-01-02T10:00', 'assets' => ['library:7']]);
    is($r['code'], 403);
    is((int)q1("SELECT COUNT(*) FROM posts"), $before);
});
test('add-post: another client\'s asset → 403', function () {
    $r = post('add-post.php?client=privacybee', ['action' => 'create', 'format' => 'json', 'caption' => 'x', 'scheduled_date' => '2031-01-02T10:00', 'assets' => ['library:1']]);
    is($r['code'], 403);
});

finish();
