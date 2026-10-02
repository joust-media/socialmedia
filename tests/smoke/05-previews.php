<?php
/** Image previews: render sites link sm previews, the lazy endpoint makes them (signed refs only), the backfill job runs. */
require __DIR__ . '/lib.php';

function previewUrls(string $html): array {
    preg_match_all('#(?:src|srcset)="([^"]*preview\.php\?f=[^"\s]+)#', $html, $m);
    return array_values(array_unique(array_map('html_entity_decode', $m[1])));
}

test('Posts list links sm previews, never the originals', function () {
    $r = status(get('posts.php?client=kenda&status=pending&month=all', 'admin'), 200);
    ok(count(previewUrls($r['body'])) > 0 || strpos($r['body'], '.thumbs/') !== false, 'preview URLs present');
    ok(preg_match('#pl-thumb[^>]*>\s*<img[^>]+src="([^"]+)"#', $r['body'], $m) === 1, 'row thumb found');
    ok(strpos($m[1], '.sm.') !== false || strpos($m[1], 'preview.php') !== false, 'row thumb is the sm preview: ' . $m[1]);
});
test('lazy endpoint makes an sm preview (200 image)', function () {
    $urls = previewUrls(get('assets.php?client=kenda&view=collections&item=1&series=1', 'admin')['body']);
    ok($urls, 'assets grid has lazy preview URLs');
    $u = preg_replace('#^/portal/#', '', $urls[0]);
    $r = get($u, 'anon');
    ok(in_array($r['code'], [200, 302], true), "HTTP {$r['code']}");
    if ($r['code'] === 200) ok(strpos($r['headers']['content-type'] ?? '', 'image/') === 0, 'an image');
});
test('tampered signature → 403, bad size → 400', function () {
    $urls = previewUrls(get('posts.php?client=kenda&status=pending&month=all', 'admin')['body']);
    if (!$urls) { $urls = previewUrls(get('assets.php?client=kenda&view=collections&item=1&series=1', 'admin')['body']); }
    ok($urls, 'have a preview URL');
    $u = preg_replace('#^/portal/#', '', $urls[0]);
    $bad = preg_replace('/\.([A-Za-z0-9_\-]{4})/', '.AAAA', $u, 1);
    is(get($bad, 'anon')['code'], 403);
    is(get(preg_replace('/s=sm/', 's=xl', $u), 'anon')['code'], 400);
});
test('preview.php refuses a ref outside the media roots', function () {
    $ref = rtrim(strtr(base64_encode('../config.php'), '+/', '-_'), '=');
    ok(in_array(get("preview.php?f={$ref}.xxxxxxxx&s=sm", 'anon')['code'], [400, 403, 404], true));
});
test('backfill job: start → step → status (admin)', function () {
    $r = status(post('preview-job.php?client=kenda', ['action' => 'start', 'scope' => 'client']), 200);
    ok(($r['json']['job']['total'] ?? 0) > 0, 'enumerated images');
    $r = status(post('preview-job.php?client=kenda', ['action' => 'step', 'scope' => 'client']), 200);
    ok(($r['json']['job']['processed'] ?? 0) > 0, 'made some');
    $r = status(post('preview-job.php?client=kenda', ['action' => 'status', 'scope' => 'client']), 200);
    ok(isset($r['json']['job']['missing']), 'status shape');
});

finish();
