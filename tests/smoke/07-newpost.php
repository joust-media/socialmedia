<?php
/**
 * New post pop-up endpoint (post-compose.php): picker paging / filters / refs, create (draft | review) from
 * tire renders + references + Library + fresh uploads, the 20-slide cap, update (add / remove / reorder /
 * replace), permissions (client seat, other tenants), and the review side (carousel markup, slide comments).
 * Seed (tests/seed.php): kenda tires 1 Klever AT2 (series 1 + 2), 2 Klever RT (series 3), 3 Kenetica Sport;
 * Library lib_01..06 + clip_01.mp4 approved; post 2 = 3-image carousel; privacybee = company 2.
 */
require __DIR__ . '/lib.php';

const NP = 'post-compose.php?client=kenda';

/** The test site's app folder (tests/env.sh), with a trailing slash. */
function appRoot(): string { return rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test'), '/') . '/site/portal/'; }

/** Approved tire image ids [tire id][series key 'ref'|id] in display order. */
function approvedTire(int $tire, $series): array {
    $where = $series === 'ref' ? 'series_id IS NULL' : 'series_id = ' . (int)$series;
    return array_map('intval', array_column(rows("SELECT id FROM tire_images WHERE tire_id = ? AND status = 'approved' AND {$where} ORDER BY sort_order, id", [$tire]), 'id'));
}
function approvedLib(): array {
    return array_map('intval', array_column(rows("SELECT id FROM library_images WHERE company_id = 1 AND status = 'approved' ORDER BY filename"), 'id'));
}
/** A fresh upload-chunk.php purpose=post token for $client. */
function uploadToken(string $client = 'kenda', int $w = 640, int $h = 800): string {
    $f = tmpImage('np-' . $client, $w, $h);
    $r = status(post("upload-chunk.php?client={$client}", ['action' => 'upload', 'purpose' => 'post', 'client' => $client, 'name' => basename($f), 'size' => filesize($f), 'type' => 'image/jpeg'], 'admin', ['file' => $f]), 200, 'upload');
    ok(!empty($r['json']['token']), 'token');
    return $r['json']['token'];
}
function slidesOf(int $postId): array {
    return rows("SELECT id, image_url, sort_order FROM post_images WHERE post_id = ? ORDER BY sort_order, id", [$postId]);
}
function create(array $slides, array $extra = [], string $client = 'kenda', string $role = 'admin'): array {
    return post("post-compose.php?client={$client}", array_merge(['action' => 'create', 'intent' => 'draft', 'slides' => $slides, 'caption' => ''], $extra), $role);
}

// ---- permissions --------------------------------------------------------------------------
test('client seat → 403 on every action', function () {
    foreach (['init', 'picker', 'clients', 'load&id=2'] as $a) is(get(NP . '&action=' . $a, 'client')['code'], 403, $a);
    is(post(NP, ['action' => 'create', 'slides' => ['library:1'], 'caption' => 'x'], 'client')['code'], 403, 'create');
    is(post(NP, ['action' => 'update', 'id' => 2, 'slides' => [], 'caption' => 'x'], 'client')['code'], 403, 'update');
    is(get(NP . '&action=picker', 'anon')['code'], 403, 'anonymous');
});
test('cross-site POST → 403', function () {
    is(post(NP, ['action' => 'create', 'slides' => ['library:1']], 'admin', [], ['Sec-Fetch-Site' => 'cross-site'])['code'], 403);
});
test('GET create → 405; unknown action → 400; no client → 400', function () {
    is(get(NP . '&action=create')['code'], 405);
    is(get(NP . '&action=bogus')['code'], 400);
    is(get('post-compose.php?action=picker')['code'], 400);
});
test('cross-tenant: another client\'s assets, posts and uploads → 403', function () {
    $tire = approvedTire(1, 1)[0];
    is(create(['tire:' . $tire], ['caption' => 'x'], 'privacybee')['code'], 403, 'kenda render as privacybee');
    is(create(['library:' . approvedLib()[0]], [], 'privacybee')['code'], 403, 'kenda library as privacybee');
    is(get('post-compose.php?client=privacybee&action=load&id=2')['code'], 403, 'load kenda post as privacybee');
    is(post('post-compose.php?client=privacybee', ['action' => 'update', 'id' => 2, 'slides' => [], 'caption' => 'x'])['code'], 403, 'update kenda post as privacybee');
    $tok = uploadToken('kenda');
    is(create(['upload:' . $tok], [], 'privacybee')['code'], 403, 'kenda upload token as privacybee');
    is(get(NP . '&action=load&id=999')['code'], 404, 'unknown post');
});
test('pending / denied renders are not pickable (403)', function () {
    $pending = (int)q1("SELECT id FROM tire_images WHERE tire_id = 1 AND status = 'pending' LIMIT 1");
    $denied  = (int)q1("SELECT id FROM tire_images WHERE tire_id = 1 AND status = 'denied' LIMIT 1");
    is(create(['tire:' . $pending])['code'], 403);
    is(create(['tire:' . $denied])['code'], 403);
    is((int)q1("SELECT COUNT(*) FROM posts"), 8, 'nothing written');
});

// ---- picker ---------------------------------------------------------------------------------
test('init: client, 20 slides, Draft on, facets', function () {
    $j = status(get(NP . '&action=init'), 200)['json'];
    is($j['client']['slug'], 'kenda');
    is($j['max'], 20);
    is($j['draft'], true);
    is(count($j['facets']['tires']), 3);
    ok($j['facets']['library'] >= 7, 'library facet');
    ok($j['facets']['media']['video'] >= 1, 'a video in the pool');
});
test('picker: approved only, tires (Reference then series) then Library, 60 per page', function () {
    $j = status(get(NP . '&action=picker'), 200)['json'];
    $all = (int)q1("SELECT COUNT(*) FROM tire_images ti JOIN tires t ON t.id = ti.tire_id WHERE t.company_id = 1 AND ti.status = 'approved'")
         + (int)q1("SELECT COUNT(*) FROM library_images WHERE company_id = 1 AND status = 'approved'");
    is($j['total'], $all, 'every approved asset');
    is($j['next'], null, 'one page');
    $kinds = array_column($j['items'], 'kind');
    is(array_search('library', $kinds, true), count($kinds) - count(array_keys($kinds, 'library', true)), 'Library last');
    foreach ($j['items'] as $it) { ok($it['thumb'] !== '' && $it['src'] !== '', 'urls'); ok(preg_match('/^(tire|library):\d+$/', $it['ref']) === 1, 'ref'); }
    // within a tire: Reference first
    $at2 = array_values(array_filter($j['items'], fn($i) => $i['group'] === 'tire:1'));
    is($at2[0]['series'], 'ref');
});
test('picker: paging with limit + offset', function () {
    $a = get(NP . '&action=picker&limit=10')['json'];
    is(count($a['items']), 10); is($a['next'], 10);
    $b = get(NP . '&action=picker&limit=10&offset=10')['json'];
    is($b['offset'], 10); is($b['facets'], null, 'facets on page 1 only');
    is(count(array_intersect(array_column($a['items'], 'ref'), array_column($b['items'], 'ref'))), 0, 'no overlap');
    $c = get(NP . '&action=picker&limit=999')['json'];
    ok(count($c['items']) <= 60, 'limit capped at 60');
});
test('picker filters: tires (multi), library, series, media, search', function () {
    $t1 = get(NP . '&action=picker&tires=1')['json']['items'];
    ok($t1 && !array_filter($t1, fn($i) => $i['group'] !== 'tire:1'), 'tire 1 only');
    $t12 = get(NP . '&action=picker&tires=1,2')['json']['items'];
    is(count(array_unique(array_column($t12, 'group'))), 2, 'tires 1 + 2');
    $lib = get(NP . '&action=picker&library=1')['json']['items'];
    ok($lib && !array_filter($lib, fn($i) => $i['kind'] !== 'library'), 'library only');
    $mix = get(NP . '&action=picker&tires=3&library=1')['json']['items'];
    is(array_values(array_unique(array_column($mix, 'group'))), ['tire:3', 'library'], 'tire 3 + Library');
    $s1 = get(NP . '&action=picker&tires=1&series=1:1')['json']['items'];
    is(count($s1), count(approvedTire(1, 1)), 'series 1 of tire 1');
    ok(!array_filter($s1, fn($i) => $i['series'] !== '1'), 'series 1 only');
    $ref = get(NP . '&action=picker&tires=1&series=1:ref')['json']['items'];
    is(count($ref), count(approvedTire(1, 'ref')), 'tire 1 Reference');
    $vid = get(NP . '&action=picker&media=video')['json']['items'];
    ok($vid && !array_filter($vid, fn($i) => $i['media'] !== 'video'), 'videos only');
    $img = get(NP . '&action=picker&media=image')['json']['items'];
    ok(!array_filter($img, fn($i) => $i['media'] !== 'image'), 'images only');
    $q = get(NP . '&action=picker&q=' . rawurlencode('klever rt'))['json']['items'];
    ok($q && !array_filter($q, fn($i) => $i['group_label'] !== 'Klever RT'), 'search by tire name');
    is(get(NP . '&action=picker&q=zzzz-nothing')['json']['total'], 0, 'no match');
});
test('picker refs: exactly those approved items, in order', function () {
    $t = approvedTire(2, 3); $l = approvedLib();
    $pending = (int)q1("SELECT id FROM tire_images WHERE status = 'pending' LIMIT 1");
    $j = get(NP . '&action=picker&refs=' . rawurlencode("library:{$l[1]},tire:{$t[0]},tire:{$pending}"))['json'];
    is(array_column($j['items'], 'ref'), ["library:{$l[1]}", "tire:{$t[0]}"]);
});

// ---- create -------------------------------------------------------------------------------------
test('create: 1 render → draft, copied file, sm/lg-ready slide', function () {
    $t = approvedTire(1, 1)[0];
    $r = status(create(['tire:' . $t], ['caption' => 'One render']), 200);
    $id = $r['json']['post_id'];
    is($r['json']['status'], 'draft');
    is((string)q1("SELECT status FROM posts WHERE id = ?", [$id]), 'draft');
    $s = slidesOf($id);
    is(count($s), 1);
    ok(strpos($s[0]['image_url'], 'uploads/img_') === 0, 'copied into uploads/');
    ok(is_file(appRoot() . $s[0]['image_url']), 'file on disk');
    has($r['json']['url'], 'posts.php?client=kenda&post=' . $id);
    is((string)q1("SELECT action FROM activity_log WHERE entity_type = 'post' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$id]), 'drafted');
    is(get("posts.php?client=kenda&post={$id}&partial=1", 'client')['code'], 404, 'a draft never reaches the client');
});
test('create: 5 slides mixing Reference, two series, Library and an upload — order kept', function () {
    $ref = approvedTire(1, 'ref')[0]; $s1 = approvedTire(1, 1); $s2 = approvedTire(1, 2); $lib = approvedLib()[2];
    $tok = uploadToken();
    $slides = ["tire:{$s2[1]}", 'upload:' . $tok, "tire:{$ref}", "library:{$lib}", "tire:{$s1[3]}"];
    $r = status(create($slides, ['caption' => 'Five', 'intent' => 'review', 'scheduled_date' => '2026-11-03 09:30:00']), 200);
    $id = $r['json']['post_id'];
    is($r['json']['status'], 'pending', 'sent for review');
    $s = slidesOf($id);
    is(array_map('intval', array_column($s, 'sort_order')), [1, 2, 3, 4, 5]);
    is(count(array_unique(array_column($s, 'image_url'))), 5, 'five files');
    is((string)q1("SELECT scheduled_date FROM posts WHERE id = ?", [$id]), '2026-11-03 09:30:00');
    is((string)q1("SELECT post_type FROM posts WHERE id = ?", [$id]), 'post');
    // the upload is the 2nd slide: 640×800 → its dims come back in the slide list
    is($r['json']['slides'][1]['w'] . 'x' . $r['json']['slides'][1]['h'], '640x800', 'upload kept its place');
    is(get(NP . '&action=picker')['code'], 200);
    status(get("posts.php?client=kenda&post={$id}&partial=1", 'client'), 200, 'pending → the client sees it');
});
test('create: 20 slides OK, 21 → 422', function () {
    $pool = array_map(fn($i) => $i['ref'], get(NP . '&action=picker&media=image')['json']['items']);
    ok(count($pool) >= 20, 'enough approved images');
    $twenty = array_slice($pool, 0, 19);
    $twenty[] = 'upload:' . uploadToken();
    $r = status(create($twenty, ['caption' => 'Twenty']), 200);
    is(count(slidesOf($r['json']['post_id'])), 20);
    $more = array_slice($pool, 0, 20); $more[] = 'upload:' . uploadToken();
    $before = (int)q1("SELECT COUNT(*) FROM posts");
    $bad = create($more, ['caption' => 'Too many']);
    is($bad['code'], 422);
    ok(isset($bad['json']['errors']['slides']), 'slides error');
    is((int)q1("SELECT COUNT(*) FROM posts"), $before, 'nothing written');
});
test('create: a single video → Reel (auto type); explicit type wins', function () {
    $clip = (int)q1("SELECT id FROM library_images WHERE filename LIKE '%.mp4' AND status = 'approved' LIMIT 1");
    $r = status(create(["library:{$clip}"], ['caption' => 'Clip']), 200);
    is((string)q1("SELECT post_type FROM posts WHERE id = ?", [$r['json']['post_id']]), 'reel');
    is((string)q1("SELECT media_type FROM post_images WHERE post_id = ?", [$r['json']['post_id']]), 'video');
    $r = status(create(["library:{$clip}"], ['caption' => 'Clip', 'post_type' => 'story']), 200);
    is((string)q1("SELECT post_type FROM posts WHERE id = ?", [$r['json']['post_id']]), 'story');
});
test('create: caption required for review (422 with field errors), optional for a draft', function () {
    $t = approvedTire(2, 3)[0];
    $r = create(["tire:{$t}"], ['intent' => 'review', 'caption' => '  ', 'scheduled_date' => '2026-12-01 10:00:00']);
    is($r['code'], 422);
    ok(isset($r['json']['errors']['caption']), 'caption error');
    $r = create([], ['intent' => 'review', 'caption' => 'Words only', 'scheduled_date' => '2026-12-01 10:00:00']);
    is($r['code'], 422); ok(isset($r['json']['errors']['slides']), 'slides error');
    is(create([], ['caption' => ''])['code'], 422, 'an empty draft');
    status(create(["tire:{$t}"], ['caption' => '']), 200, 'draft without caption');
});
test('create: an expired / unknown upload token → 400, nothing written', function () {
    $before = (int)q1("SELECT COUNT(*) FROM posts");
    is(create(['upload:' . str_repeat('a', 32)])['code'], 400);
    is(create(['bogus:1'])['code'], 400);
    is((int)q1("SELECT COUNT(*) FROM posts"), $before);
});
test('create: a token is single-use', function () {
    $tok = uploadToken();
    status(create(['upload:' . $tok], ['caption' => 'x']), 200);
    is(create(['upload:' . $tok], ['caption' => 'x'])['code'], 400);
});

// ---- review side: carousel + slide comments ----------------------------------------------------------
test('carousel markup: arrows, "1 / 3", dots, sm thumbs per slide (both seats)', function () {
    foreach (['admin', 'client'] as $seat) {
        $b = status(get('posts.php?client=kenda&post=2&partial=1', $seat), 200)['body'];
        has($b, 'data-carousel-prev'); has($b, 'data-carousel-next'); has($b, '1 / 3');
        is(substr_count($b, 'data-carousel-dot='), 3);
        is(preg_match_all('/data-thumb="[^"]+"/', $b), 3, 'thumbs');
        has($b, 'data-comment-slide', 'slide picker (≥ 2 slides)');
    }
    hasNot(get('posts.php?client=kenda&post=1&partial=1', 'client')['body'], 'data-comment-slide', 'no picker for one slide');
    has(get('posts.php?client=kenda', 'client')['body'], 'js/carousel.js', 'carousel.js on Posts');
});
test('slide comment: stored as "[Slide 2] …", rendered as a slide chip', function () {
    status(post('status.php', ['id' => 2, 'comment' => '[Slide 2] Darker sky please', 'actor' => 'client', 'client' => 'kenda'], 'client'), 200);
    is((string)q1("SELECT detail FROM activity_log WHERE entity_id = 2 AND action = 'commented' ORDER BY id DESC LIMIT 1"), '[Slide 2] Darker sky please');
    $b = get('posts.php?client=kenda&post=2&partial=1', 'client')['body'];
    has($b, 'data-goto-slide="1"'); has($b, '<span>Slide 2</span>'); has($b, 'Darker sky please');
    hasNot($b, '[Slide 2]', 'the prefix itself is not shown');
});
test('slide comment: feeds, Home notes and the Needs changes row say "on slide N:", never the raw prefix', function () {
    status(post('status.php', ['id' => 2, 'comment' => '[Slide 3] Crop tighter', 'actor' => 'client', 'client' => 'kenda'], 'client'), 200);
    foreach (['admin', 'client'] as $seat) {
        $b = get('index.php?client=kenda', $seat)['body'];
        has($b, 'on slide 3: <q>Crop tighter</q>', "$seat Home activity");
        hasNot($b, '[Slide 3]', "$seat Home: no raw prefix");
    }
    $b = get('index.php', 'admin')['body'];   // cross-client feed
    has($b, 'on slide 3: <q>Crop tighter</q>', 'cross-client feed');
    hasNot($b, '[Slide 3]', 'cross-client feed: no raw prefix');
    // a note on a Needs-changes post (post 4 is denied): Home "Latest notes" + the queue row
    status(post('status.php', ['id' => 4, 'comment' => '[Slide 1] Wrong tire', 'actor' => 'client', 'client' => 'kenda'], 'client'), 200);
    $b = get('index.php?client=kenda', 'admin')['body'];
    has($b, '<span class="home-note-lead">On slide 1:</span> <q>Wrong tire</q>', 'Home note lead');
    hasNot($b, '[Slide 1]', 'Home notes: no raw prefix');
    $b = get('posts.php?client=kenda&status=denied', 'admin')['body'];
    has($b, 'On slide 1: <q>Wrong tire</q>', 'Needs changes row');
    hasNot($b, '[Slide 1]', 'Needs changes row: no raw prefix');
    is((string)q1("SELECT detail FROM activity_log WHERE entity_id = 4 AND action = 'commented' ORDER BY id DESC LIMIT 1"), '[Slide 1] Wrong tire', 'stored text unchanged');
});
// ---- update -------------------------------------------------------------------------------------
test('load: the post with its slides in order', function () {
    $j = status(get(NP . '&action=load&id=2'), 200)['json'];
    is($j['post']['id'], 2);
    is(count($j['slides']), 3);
    is(array_column($j['slides'], 'ref'), array_map(fn($r) => 'image:' . $r['id'], slidesOf(2)));
});
test('update: reorder + remove + add + replace in one save keeps the order', function () {
    $s = slidesOf(2);
    [$a, $b, $c] = array_map(fn($r) => (int)$r['id'], $s);
    $removedUrl = $s[1]['image_url'];
    $lib = approvedLib()[0]; $t = approvedTire(2, 3)[1];
    $tok = uploadToken();
    // new order: C, [new lib], A, [upload]  (B removed; "replace" = B's slot taken by the library image)
    $r = status(post(NP, ['action' => 'update', 'id' => 2, 'slides' => ["image:{$c}", "library:{$lib}", "image:{$a}", 'upload:' . $tok, "tire:{$t}"],
                          'caption' => 'AT2 carousel v2', 'hashtags' => '#Kenda', 'scheduled_date' => '2026-11-10 10:00:00']), 200);
    $after = slidesOf(2);
    is(count($after), 5);
    is((int)$after[0]['id'], $c, 'C first');
    is((int)$after[2]['id'], $a, 'A third');
    is(array_map('intval', array_column($after, 'sort_order')), [1, 2, 3, 4, 5]);
    ok(!in_array($b, array_map('intval', array_column($after, 'id')), true), 'B gone');
    ok(!is_file(appRoot() . $removedUrl), 'orphaned file unlinked');
    is((string)q1("SELECT status FROM posts WHERE id = 2"), 'pending', 'status kept (intent keep)');
    $log = (string)q1("SELECT detail FROM activity_log WHERE entity_type = 'post' AND entity_id = 2 AND action = 'edited_media' ORDER BY id DESC LIMIT 1");
    has($log, '3 → 5 slides'); has($log, 'removed'); has($log, 'reordered');
    is($r['json']['slides'][0]['ref'], 'image:' . $c);
});
test('update: a file another row still references is kept', function () {
    $s = slidesOf(1); $url = $s[0]['image_url'];
    db()->prepare("INSERT INTO post_images (post_id, image_url, sort_order, media_type) VALUES (3, ?, 9, 'image')")->execute([$url]);
    $t = approvedTire(1, 1)[0];
    status(post(NP, ['action' => 'update', 'id' => 1, 'slides' => ["tire:{$t}"], 'caption' => 'Spring launch hero']), 200);
    ok(is_file(appRoot() . $url), 'shared file stays');
    is(count(slidesOf(1)), 1);
});
test('update: a post the client can see keeps a caption and a slide (422, nothing changed)', function () {
    $keep = array_map(fn($x) => 'image:' . $x['id'], slidesOf(3));
    $before = slidesOf(3);
    is(post(NP, ['action' => 'update', 'id' => 3, 'slides' => $keep, 'caption' => ''])['code'], 422, 'empty caption');
    is(post(NP, ['action' => 'update', 'id' => 3, 'slides' => [], 'caption' => 'Trail day'])['code'], 422, 'no slides');
    is(slidesOf(3), $before, 'untouched');
    status(post(NP, ['action' => 'update', 'id' => 7, 'slides' => [], 'caption' => '']), 200, 'a draft may be empty');
});
test('update: slides of another post → 403; draft → review via intent; scheduled refuses intent', function () {
    $other = (int)slidesOf(3)[0]['id'];
    is(post(NP, ['action' => 'update', 'id' => 2, 'slides' => ["image:{$other}"], 'caption' => 'x'])['code'], 403);
    $r = post(NP, ['action' => 'update', 'id' => 7, 'intent' => 'review', 'slides' => array_map(fn($x) => 'image:' . $x['id'], slidesOf(7)), 'caption' => '']);
    is($r['code'], 422, 'draft 7 has no caption');
    status(post(NP, ['action' => 'update', 'id' => 6, 'intent' => 'review', 'slides' => array_map(fn($x) => 'image:' . $x['id'], slidesOf(6)), 'caption' => 'Behind the scenes', 'scheduled_date' => '2026-11-20 10:00:00']), 200);
    is((string)q1("SELECT status FROM posts WHERE id = 6"), 'pending');
    is((string)q1("SELECT action FROM activity_log WHERE entity_type = 'post' AND entity_id = 6 ORDER BY id DESC LIMIT 1"), 'submitted');
    is(post(NP, ['action' => 'update', 'id' => 5, 'intent' => 'draft', 'slides' => array_map(fn($x) => 'image:' . $x['id'], slidesOf(5)), 'caption' => 'x'])['code'], 409);
});

test('admin entry points are server-gated', function () {
    $a = get('posts.php?client=kenda', 'admin')['body'];
    has($a, 'data-newpost'); has($a, 'js/newpost.js'); has($a, 'post-compose.php');
    has(get('posts.php?client=kenda&post=2&partial=1', 'admin')['body'], 'data-newpost-edit="2"');
    $c = get('posts.php?client=kenda', 'client')['body'];
    hasNot($c, 'data-newpost'); hasNot($c, 'newpost.js'); hasNot($c, 'post-compose.php');
    hasNot(get('posts.php?client=kenda&post=2&partial=1', 'client')['body'], 'data-newpost-edit');
    hasNot(get('assets.php?client=kenda&view=collections&item=1', 'client')['body'], 'data-select-post');
    has(get('assets.php?client=kenda&view=collections&item=1', 'admin')['body'], 'data-select-post');
    has(get('assets.php?client=kenda&view=collections&item=1', 'admin')['body'], 'data-viewer-use-in-post');
    hasNot(get('assets.php?client=kenda&view=collections&item=1', 'client')['body'], 'data-viewer-use-in-post');
    has(get('?client=kenda', 'admin')['body'], 'data-newpost');
    has(get('studio.php?client=kenda', 'admin')['body'], 'data-newpost');
    hasNot(get('studio.php?client=kenda', 'admin')['body'], 'data-studio-tab="compose"', 'no Compose tab');
    hasNot(get('studio.php?client=kenda', 'admin')['body'], 'data-studio-tab="batch"', 'no Batch tab');
});

finish();
