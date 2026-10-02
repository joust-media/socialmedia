<?php
/**
 * Carousels end to end: many media per post (POST_MAX_MEDIA = 20), order kept on every path,
 * media[] (interleave picks / uploads / current items, reorder, remove), batch rows, rendering.
 */
require __DIR__ . '/lib.php';

const APPROVED_PICKS = [
    'library:1', 'library:2', 'library:3', 'library:4', 'library:5', 'library:6',
    'tire:4', 'tire:5', 'tire:6', 'tire:7', 'tire:8',          // AT2 series 1 renders 1–5
    'tire:12', 'tire:13', 'tire:14', 'tire:15', 'tire:16',     // AT2 series 2 renders 1–5
    'tire:18', 'tire:19', 'tire:20', 'tire:21', 'tire:22',     // RT series 1 renders 1–5
];
function appDir(): string { return rtrim((string)(getenv('APP_DIR') ?: '/tmp/portal-test/site/portal'), '/'); }
function mediaDir(): string { return rtrim((string)(getenv('MEDIA_DIR') ?: '/tmp/portal-test/site/media'), '/'); }
/** md5 of the file behind a pool key (what the copy in uploads/ must equal). */
function sourceHash(string $key): string {
    [$kind, $id] = explode(':', $key);
    if ($kind === 'library') return md5_file(mediaDir() . '/library/kenda/' . q1("SELECT filename FROM library_images WHERE id = ?", [(int)$id]));
    $url = (string)q1("SELECT image_url FROM tire_images WHERE id = ?", [(int)$id]);
    return md5_file(strpos($url, 'uploads/') === 0 ? appDir() . '/' . $url : dirname(appDir()) . '/' . $url);
}
function postHashes(int $pid): array {
    return array_map(static fn($u) => md5_file(appDir() . '/' . $u), array_column(rows("SELECT image_url FROM post_images WHERE post_id = ? ORDER BY sort_order, id", [$pid]), 'image_url'));
}
function createPost(array $fields): array {
    return post('add-post.php?client=kenda', $fields + ['action' => 'create', 'format' => 'json', 'caption' => 'Carousel', 'scheduled_date' => '2031-05-01T10:00', 'status' => 'pending']);
}
function uploadClaim(string $label): array {
    $f = tmpImage($label, 640, 640);
    $r = status(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'post', 'client' => 'kenda'], 'admin', ['file' => $f]), 200);
    return [(string)$r['json']['token'], md5_file($f)];
}

test('the seeded pool keys are approved assets (fixture sanity)', function () {
    foreach (APPROVED_PICKS as $k) {
        [$kind, $id] = explode(':', $k);
        $st = $kind === 'library' ? q1("SELECT status FROM library_images WHERE id = ?", [(int)$id]) : q1("SELECT status FROM tire_images WHERE id = ?", [(int)$id]);
        is($st, 'approved', $k);
    }
});

test('12 picks (over the old 10 cap) → 12 slides in pick order', function () {
    $picks = array_slice(APPROVED_PICKS, 0, 12);
    $r = status(createPost(['assets' => $picks]), 200);
    $pid = (int)$r['json']['post_id'];
    is(count($r['json']['media']), 12);
    is(postHashes($pid), array_map('sourceHash', $picks), 'order + content');
    is(array_map('intval', array_column(rows("SELECT sort_order FROM post_images WHERE post_id = ? ORDER BY sort_order", [$pid]), 'sort_order')), range(1, 12));
});
test('20 picks is the cap; 21 is refused / trimmed', function () {
    $r = status(createPost(['media' => APPROVED_PICKS]), 422, '21 via media[]');
    has($r['json']['error'] ?? '', 'Up to 20');
    $r = status(createPost(['assets' => array_slice(APPROVED_PICKS, 0, 20)]), 200);
    is(count($r['json']['media']), 20);
});
test('reverse pick order is kept (not id order)', function () {
    $picks = ['tire:16', 'library:3', 'tire:4'];
    $pid = (int)status(createPost(['assets' => $picks]), 200)['json']['post_id'];
    is(postHashes($pid), array_map('sourceHash', $picks));
});
test('media[]: uploads interleaved with picks keep their place', function () {
    [$t1, $h1] = uploadClaim('first');
    [$t2, $h2] = uploadClaim('third');
    $media = ['claim:' . $t1, 'tire:5', 'claim:' . $t2, 'library:2'];
    $pid = (int)status(createPost(['media' => $media]), 200)['json']['post_id'];
    is(postHashes($pid), [$h1, sourceHash('tire:5'), $h2, sourceHash('library:2')]);
});
test('media[] on update: reorder current items, add, remove (file deleted after commit)', function () {
    $pid = (int)status(createPost(['assets' => ['library:1', 'library:2', 'library:3']]), 200)['json']['post_id'];
    $cur = rows("SELECT id, image_url FROM post_images WHERE post_id = ? ORDER BY sort_order", [$pid]);
    [$a, $b, $c] = $cur;
    $r = status(post('add-post.php?client=kenda', ['action' => 'update', 'id' => $pid, 'format' => 'json', 'caption' => 'Carousel v2',
        'scheduled_date' => '2031-05-01T10:00', 'status' => 'pending',
        'media' => ['image:' . $c['id'], 'tire:7', 'image:' . $a['id']]]), 200);
    is(array_column($r['json']['media'], 'id')[0], (int)$c['id'], 'C first');
    is(array_column($r['json']['media'], 'id')[2], (int)$a['id'], 'A last');
    is(postHashes($pid), [sourceHash('library:3'), sourceHash('tire:7'), sourceHash('library:1')]);
    ok(!is_file(appDir() . '/' . $b['image_url']), 'removed item\'s file deleted');
});
test('media[] on update: an id from another post → 422 and nothing changes', function () {
    $pid = (int)status(createPost(['assets' => ['library:1', 'library:2']]), 200)['json']['post_id'];
    $other = (int)q1("SELECT id FROM post_images WHERE post_id = 1 LIMIT 1");
    $before = postHashes($pid);
    $r = post('add-post.php?client=kenda', ['action' => 'update', 'id' => $pid, 'format' => 'json', 'caption' => 'x',
        'scheduled_date' => '2031-05-01T10:00', 'media' => ['image:' . $other]]);
    is($r['code'], 422);
    is(postHashes($pid), $before, 'unchanged, files intact');
});
test('legacy edit (assets[] + remove_images[]) still appends after the kept media', function () {
    $pid = (int)status(createPost(['assets' => ['library:1', 'library:2']]), 200)['json']['post_id'];
    $first = (int)q1("SELECT id FROM post_images WHERE post_id = ? ORDER BY sort_order LIMIT 1", [$pid]);
    status(post('add-post.php?client=kenda', ['action' => 'update', 'id' => $pid, 'format' => 'json', 'caption' => 'x',
        'scheduled_date' => '2031-05-01T10:00', 'remove_images' => [$first], 'assets' => ['tire:4', 'tire:5']]), 200);
    is(postHashes($pid), [sourceHash('library:2'), sourceHash('tire:4'), sourceHash('tire:5')]);
});
test('batch row with 5 assets → one draft carousel in row order', function () {
    $assets = ['tire:8', 'tire:4', 'library:6', 'tire:20', 'library:1'];
    $r = status(post('batch-process.php?client=kenda', ['rows' => json_encode([['caption' => 'Batch carousel', 'assets' => $assets]])]), 200);
    $c = $r['json']['created'][0];
    is($c['assets'], 5);
    is($c['status'], 'draft');
    is(postHashes((int)$c['post_id']), array_map('sourceHash', $assets));
});
test('post sheet renders every slide with dots and a counter (seed carousel + a 12)', function () {
    $r = status(get('posts.php?client=kenda&post=2&partial=1', 'client'), 200);
    is(substr_count($r['body'], 'class="pd-slide"'), 3);
    is(substr_count($r['body'], 'data-carousel-dot='), 3);
    has($r['body'], '>1/3<');
    $pid = (int)status(createPost(['assets' => array_slice(APPROVED_PICKS, 0, 12)]), 200)['json']['post_id'];
    $r = status(get("posts.php?client=kenda&post={$pid}&partial=1", 'admin'), 200);
    is(substr_count($r['body'], 'class="pd-slide"'), 12);
    has($r['body'], '>1/12<');
});
test('list row says "N media" for a carousel', function () {
    $r = status(get('posts.php?client=kenda&status=pending&month=all', 'client'), 200);
    ok(preg_match('#data-post-item="2".*?<span>3 media</span>#s', $r['body']) === 1);
});

finish();
