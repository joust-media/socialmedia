<?php
/**
 * The Upload sheet's server side (static/js/upload-sheet.js): upload-sheet.php (clients / init) and every
 * destination's endpoint with its storage path, approval status and previews —
 *   tire series → tire-upload.php (media/tires/<tire>/<series>/, 'pending', series created on first use)
 *   Reference   → upload-chunk.php purpose=feature (uploads/feat_*, 'pending', images only, 6 per tire)
 *   Library     → upload-chunk.php purpose=library (media/library/<slug>/, 'pending', never inherits an old row)
 *   New post    → upload-chunk.php purpose=post (claim → post-compose.php slides upload:<token> → Draft)
 *                 / purpose=batch (→ batch-process.php claimed[] → one Draft per file)
 * plus the Assets selection Download / Export (export.php scope=selection), the retired upload routes and the
 * contextual entry points (admin only).
 */
require __DIR__ . '/lib.php';

const US = 'upload-sheet.php?client=kenda';
$GLOBALS['MEDIA'] = rtrim((string)(getenv('MEDIA_DIR') ?: ((getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test') . '/site/media')), '/');
$GLOBALS['APP']   = rtrim((string)(getenv('APP_DIR') ?: ((getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test') . '/site/portal')), '/');

/** A JPEG with an exact file name (tmpImage() adds a random suffix). */
function namedImage(string $name, int $w = 640, int $h = 480): string {
    $dir = sys_get_temp_dir() . '/smoke_up_' . bin2hex(random_bytes(3));
    @mkdir($dir, 0777, true);
    $src = tmpImage(pathinfo($name, PATHINFO_FILENAME), $w, $h);
    rename($src, $dir . '/' . $name);
    return $dir . '/' . $name;
}
/** chunk_init → chunk_put × n → chunk_finish against $ep; returns the finish response. */
function chunked(string $ep, array $fields, string $file, int $pieces = 3): array {
    $bytes = file_get_contents($file);
    $size = strlen($bytes);
    $init = status(post($ep, $fields + ['action' => 'chunk_init', 'name' => basename($file), 'size' => $size, 'type' => 'image/jpeg']), 200, 'chunk_init');
    $id = (string)$init['json']['upload_id'];
    $piece = (int)ceil($size / $pieces);
    for ($i = 0, $off = 0; $off < $size; $i++, $off += $piece) {
        $part = sys_get_temp_dir() . "/smoke_up_part_{$i}.bin";
        file_put_contents($part, substr($bytes, $off, $piece));
        status(post($ep, ['action' => 'chunk_put', 'upload_id' => $id, 'index' => $i, 'offset' => $off, 'client' => $fields['client'] ?? 'kenda'], 'admin', ['file' => $part]), 200, "piece {$i}");
    }
    return post($ep, ['action' => 'chunk_finish', 'upload_id' => $id, 'client' => $fields['client'] ?? 'kenda']);
}
/** The sm preview next to a stored original (<dir>/.thumbs/<stem>.sm.<webp|jpg>). */
function hasPreview(string $abs): bool {
    return (bool)glob(dirname($abs) . '/.thumbs/' . pathinfo($abs, PATHINFO_FILENAME) . '.sm.*');
}
function lib(string $file = '', string $role = 'admin'): array {
    return post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'library', 'client' => 'kenda'], $role, ['file' => $file ?: tmpImage('lib')]);
}

// ---- upload-sheet.php ------------------------------------------------------------------------
test('init: tires with series + reference slots, features, limits and the destination URLs', function () {
    $r = status(get(US . '&action=init'), 200);
    $j = $r['json'];
    is($j['client']['slug'], 'kenda');
    $byId = array_column($j['tires'], null, 'id');
    is(array_column($byId[1]['series'], 'name'), ['Series 1', 'Series 2'], 'Klever AT2 series in order');
    is((int)$byId[1]['refs'], 3, 'reference slots used');
    is($byId[3]['series'], [], 'Kenetica Sport has none');
    ok($j['features']['series'] && $j['features']['library'] && $j['features']['draft'], 'features');
    is((int)$j['limits']['reference'], 6);
    ok($j['limits']['image'] >= 50 * 1048576 && $j['limits']['video'] >= 4 * 1073741824, 'caps');
    has($j['urls']['tire'], 'tire-upload.php?client=kenda');
    has($j['urls']['upload'], 'upload-chunk.php?client=kenda');
    has($j['urls']['series'], 'assets.php?client=kenda&view=collections&item=__TIRE__&series=__SERIES__');
    has($j['urls']['library'], 'view=library');
});
test('init for a client without tires; clients list; seat / method / scope errors', function () {
    $j = status(get('upload-sheet.php?client=hmf&action=init'), 200)['json'];
    is($j['tires'], []);
    ok(!$j['features']['tires'], 'no tires feature');
    $c = status(get('upload-sheet.php?action=clients'), 200)['json'];
    is(array_column($c['clients'], 'slug'), ['hmf', 'kenda', 'privacybee']);
    is(get(US . '&action=init', 'client')['code'], 403, 'client seat');
    is(get(US . '&action=init', 'anon')['code'], 403, 'anonymous');
    is(get('upload-sheet.php?action=init')['code'], 400, 'no client');
    is(get(US . '&action=bogus')['code'], 400, 'unknown action');
    is(post(US, ['action' => 'init'])['code'], 405, 'POST');
    is(get(US . '&action=init', 'admin', ['Sec-Fetch-Site' => 'cross-site'])['code'], 403, 'cross-site');
});

// ---- tire series ------------------------------------------------------------------------------
test('series: one request → pending render in media/tires/<tire>/<series>/, preview made, logged', function () {
    $before = (int)q1("SELECT COUNT(*) FROM tire_images WHERE series_id = 1");
    $r = status(post('tire-upload.php?client=kenda', ['client' => 'kenda', 'tire_id' => 1, 'series_id' => 1, 'batch' => 'smokeseries'], 'admin', ['file' => namedImage('hero-shot.jpg')]), 200);
    is($r['json']['image']['status'], 'pending');
    is((int)$r['json']['image']['series_id'], 1);
    $row = rows("SELECT status, series_id, image_url, display_name FROM tire_images WHERE id = ?", [(int)$r['json']['image']['id']])[0];
    is($row['status'], 'pending', 'To Review for the client');
    is($row['image_url'], 'media/tires/klever-at2/Series 1/hero-shot.jpg');
    is($row['display_name'], 'hero-shot');
    $abs = $GLOBALS['MEDIA'] . '/tires/klever-at2/Series 1/hero-shot.jpg';
    ok(is_file($abs), 'stored in the series folder');
    ok(hasPreview($abs), 'sm preview made');
    is((int)q1("SELECT COUNT(*) FROM tire_images WHERE series_id = 1"), $before + 1);
    is(q1("SELECT action FROM activity_log WHERE entity_type = 'tire_series' AND entity_id = 1 ORDER BY id DESC LIMIT 1"), 'uploaded');
    // same name again → -2, never overwritten
    $r2 = status(post('tire-upload.php?client=kenda', ['client' => 'kenda', 'tire_id' => 1, 'series_id' => 1], 'admin', ['file' => namedImage('hero-shot.jpg')]), 200);
    has((string)q1("SELECT image_url FROM tire_images WHERE id = ?", [(int)$r2['json']['image']['id']]), 'hero-shot-2.jpg');
});
test('series: pieces into a NEW series → the series is created (logged) and holds the render', function () {
    $f = chunked('tire-upload.php?client=kenda', ['client' => 'kenda', 'tire_id' => 2, 'new_series' => 'Smoke Series', 'batch' => 'smokenew'], namedImage('big-render.jpg', 1600, 1200));
    status($f, 200, 'finish');
    $sid = (int)$f['json']['series']['id'];
    is((string)q1("SELECT name FROM tire_series WHERE id = ? AND tire_id = 2", [$sid]), 'Smoke Series');
    $row = rows("SELECT status, image_url FROM tire_images WHERE series_id = ?", [$sid]);
    is(count($row), 1);
    is($row[0]['status'], 'pending');
    ok(is_file(dirname($GLOBALS['MEDIA']) . '/' . $row[0]['image_url']), 'file on disk: ' . $row[0]['image_url']);
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'tire_series' AND entity_id = ? AND action = 'created'", [$sid]), 1);
});
test('series: the same 50 MB image cap as every other destination; tenant + series checks', function () {
    $p = status(get('tire-upload.php?action=probe&client=kenda'), 200);
    ok(($p['json']['max_file_bytes']['image'] ?? 0) >= 50 * 1048576, 'images up to 50 MB (was 10 MB)');
    is(post('tire-upload.php?client=kenda', ['client' => 'kenda', 'tire_id' => 1, 'series_id' => 3], 'admin', ['file' => tmpImage('x')])['code'], 404, 'series of another tire');
    is(post('tire-upload.php?client=privacybee', ['client' => 'privacybee', 'tire_id' => 1, 'series_id' => 1], 'admin', ['file' => tmpImage('x')])['code'], 403, 'another client\'s tire');
    is(post('tire-upload.php?client=kenda', ['client' => 'kenda', 'tire_id' => 1, 'series_id' => 1], 'client', ['file' => tmpImage('x')])['code'], 403, 'client seat');
});

// ---- Reference ------------------------------------------------------------------------------
test('reference: purpose=feature → reference row (no series, pending) in uploads/feat_*, preview made', function () {
    $r = status(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'feature', 'feature_id' => 3, 'client' => 'kenda'], 'admin', ['file' => namedImage('real-tire.jpg')]), 200);
    $id = (int)$r['json']['image']['id'];
    $row = rows("SELECT status, series_id, image_url, display_name FROM tire_images WHERE id = ?", [$id])[0];
    is($row['status'], 'pending', 'To Review');
    ok($row['series_id'] === null, 'a reference image (no series)');
    ok(preg_match('#^uploads/feat_[A-Za-z0-9_.\-]+\.jpg$#', $row['image_url']) === 1, 'the reference storage: ' . $row['image_url']);
    is($row['display_name'], 'real-tire');
    ok(hasPreview($GLOBALS['APP'] . '/' . $row['image_url']), 'sm preview made');
    is((int)$r['json']['count'], 3);
    is((int)status(get(US . '&action=init'), 200)['json']['tires'][0]['refs'], 3, 'init counts it (Kenetica Sport first)');
});
test('reference: images only (video → 415), 6 per tire (7th → 409)', function () {
    $v = sys_get_temp_dir() . '/smoke_up_clip.mp4';
    file_put_contents($v, pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isommp41' . pack('N', 1032) . 'mdat' . str_repeat("\0", 1024));
    is(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'feature', 'feature_id' => 1, 'client' => 'kenda'], 'admin', ['file' => $v])['code'], 415);
    for ($i = 4; $i <= 6; $i++) status(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'feature', 'feature_id' => 1, 'client' => 'kenda'], 'admin', ['file' => tmpImage("ref{$i}")]), 200, "ref {$i}");
    is(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'feature', 'feature_id' => 1, 'client' => 'kenda'], 'admin', ['file' => tmpImage('ref7')])['code'], 409);
});

// ---- Library ---------------------------------------------------------------------------------
test('library: one request → media/library/kenda/<name>, pending row, preview, in Assets → Library (both seats)', function () {
    $r = status(lib(namedImage('brand-moment.jpg')), 200);
    is($r['json']['purpose'], 'library');
    is($r['json']['image']['filename'], 'brand-moment.jpg');
    is($r['json']['image']['status'], 'pending');
    $abs = $GLOBALS['MEDIA'] . '/library/kenda/brand-moment.jpg';
    ok(is_file($abs), 'stored in the library folder');
    is(substr(sprintf('%o', fileperms($abs)), -4), '0644', 'servable');
    ok(hasPreview($abs), 'sm preview made');
    is(q1("SELECT status FROM library_images WHERE company_id = 1 AND filename = 'brand-moment.jpg'"), 'pending');
    has(status(get('assets.php?client=kenda&view=library&filter=pending', 'admin'), 200)['body'], 'brand-moment', 'admin sees it to review');
    has(status(get('assets.php?client=kenda&view=library&filter=pending', 'client'), 200)['body'], 'brand-moment', 'client sees it to review');
    is((int)q1("SELECT COUNT(*) FROM library_images WHERE filename = 'brand-moment.jpg'"), 1, 'the folder scan does not add it twice');
});
test('library: a taken name — on disk or by an old row — gets -2 (a new file never inherits an approval)', function () {
    status(lib(namedImage('dup-name.jpg')), 200);
    db()->prepare("UPDATE library_images SET status = 'approved' WHERE company_id = 1 AND filename = 'dup-name.jpg'")->execute();
    $r = status(lib(namedImage('dup-name.jpg')), 200);
    is($r['json']['image']['filename'], 'dup-name-2.jpg');
    is(q1("SELECT status FROM library_images WHERE company_id = 1 AND filename = 'dup-name.jpg'"), 'approved', 'the original keeps its decision');
    is(q1("SELECT status FROM library_images WHERE company_id = 1 AND filename = 'dup-name-2.jpg'"), 'pending');
    db()->prepare("INSERT INTO library_images (company_id, filename, status) VALUES (1, 'ghost.jpg', 'approved')")->execute();   // file gone from disk, row stays
    $g = status(lib(namedImage('ghost.jpg')), 200);
    is($g['json']['image']['filename'], 'ghost-2.jpg');
    is(q1("SELECT status FROM library_images WHERE company_id = 1 AND filename = 'ghost-2.jpg'"), 'pending');
});
test('library: in pieces (large files) → stored + pending; videos accepted', function () {
    $f = chunked('upload-chunk.php?client=kenda', ['client' => 'kenda', 'purpose' => 'library'], namedImage('chunky.jpg', 1800, 1200));
    status($f, 200, 'finish');
    is($f['json']['image']['filename'], 'chunky.jpg');
    ok(is_file($GLOBALS['MEDIA'] . '/library/kenda/chunky.jpg'));
    is(q1("SELECT status FROM library_images WHERE filename = 'chunky.jpg'"), 'pending');
    $v = sys_get_temp_dir() . '/smoke_up_libclip.mp4';
    file_put_contents($v, pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isommp41' . pack('N', 1032) . 'mdat' . str_repeat("\0", 1024));
    $r = status(lib($v), 200);
    is($r['json']['image']['type'], 'video');
});
test('library: client seat 403, a fake .jpg 422, a script 415', function () {
    is(lib('', 'client')['code'], 403);
    $f = sys_get_temp_dir() . '/smoke_up_fake.jpg';
    file_put_contents($f, str_repeat('x', 4096));
    is(lib($f)['code'], 422);
    $p = sys_get_temp_dir() . '/smoke_up_evil.php';
    file_put_contents($p, '<?php echo 1;');
    is(lib($p)['code'], 415);
    ok(!glob($GLOBALS['MEDIA'] . '/library/kenda/*evil*'), 'nothing written');
});

// ---- New post ----------------------------------------------------------------------------------
test('new post: purpose=post claims → the pop-up\'s slides upload:<token> → one Draft carousel, in order', function () {
    $toks = [];
    foreach (['one', 'two'] as $n) {
        $r = status(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'post', 'client' => 'kenda'], 'admin', ['file' => tmpImage("np{$n}")]), 200);
        $toks[] = (string)$r['json']['token'];
        ok(strpos((string)$r['json']['preview_url'], '/uploads/tmp_') !== false, 'preview_url for the tray');
    }
    $c = status(post('post-compose.php?client=kenda', ['action' => 'create', 'intent' => 'draft', 'caption' => '', 'slides' => ['upload:' . $toks[0], 'upload:' . $toks[1]]]), 200);
    $pid = (int)$c['json']['post_id'];
    is(q1("SELECT status FROM posts WHERE id = ?", [$pid]), 'draft');
    is((int)q1("SELECT COUNT(*) FROM post_images WHERE post_id = ?", [$pid]), 2);
});
test('new post, a draft per file: purpose=batch claims → batch-process.php → one Draft each', function () {
    foreach (['a', 'b'] as $n) {
        $tok = (string)status(post('upload-chunk.php?client=kenda', ['action' => 'upload', 'purpose' => 'batch', 'client' => 'kenda'], 'admin', ['file' => tmpImage("each{$n}")]), 200)['json']['token'];
        $r = status(post('batch-process.php?client=kenda', ['claimed' => [$tok], 'client' => 'kenda']), 200);
        is($r['json']['created'][0]['status'] ?? null, 'draft');
    }
});

// ---- Assets selection: Download (zip) + Export (CSV) -------------------------------------------
function selItems(): array {
    $tire = array_map('intval', array_column(rows("SELECT id FROM tire_images WHERE series_id = 1 AND status = 'approved' ORDER BY sort_order LIMIT 2"), 'id'));
    $lib  = (int)q1("SELECT id FROM library_images WHERE filename = 'lib_01.jpg'");
    $pend = (int)q1("SELECT id FROM library_images WHERE filename = 'lib_07.jpg'");   // pending → left out
    return ['tire:' . $tire[0], 'tire:' . $tire[1], 'library:' . $lib, 'library:' . $pend];
}
test('Download: export.php scope=selection → a zip of exactly the selected approved files', function () {
    $items = implode(',', selItems());
    $s = status(post('export.php?client=kenda', ['action' => 'start', 'scope' => 'selection', 'items' => $items]), 200);
    is((int)$s['json']['files'], 3, 'two renders + one library image (the pending one is left out)');
    ok(strpos(implode(' ', $s['json']['warnings']), 'not approved') !== false, 'says why one was left out');
    has($s['json']['filename'], 'kenda-selected-assets-');
    $job = $s['json']['job'];
    for ($i = 0, $done = false; $i < 20 && !$done; $i++) $done = !empty(status(post('export.php?client=kenda', ['action' => 'step', 'job' => $job]), 200)['json']['done']);
    ok($done, 'built');
    $z = status(get('export.php?client=kenda&action=download&job=' . $job), 200);
    is(substr($z['body'], 0, 2), 'PK');
    $path = sys_get_temp_dir() . '/smoke_up_sel.zip';
    file_put_contents($path, $z['body']);
    if (class_exists('ZipArchive')) {
        $za = new ZipArchive(); ok($za->open($path) === true, 'opens');
        $names = []; for ($i = 0; $i < $za->numFiles; $i++) $names[] = $za->getNameIndex($i);
        is(count(array_filter($names, static function ($n) { return !preg_match('/manifest\.(csv|json)$/', $n); })), 3, 'three files');
        ok((bool)array_filter($names, static function ($n) { return strpos($n, 'Klever AT2/Series 1/') !== false; }), 'tire / series folders');
        ok((bool)array_filter($names, static function ($n) { return strpos($n, '/Library/lib_01.jpg') !== false; }), 'library folder');
    } else {
        is(substr_count($z['body'], "PK\x03\x04"), 5, 'three files + two manifests');
    }
});
test('Export: the selection\'s manifest CSV; nothing selected → 400; client seat 403', function () {
    $r = status(get('export.php?client=kenda&action=manifest&scope=selection&items=' . rawurlencode(implode(',', selItems()))), 200);
    has($r['headers']['content-type'] ?? '', 'text/csv');
    has($r['headers']['content-disposition'] ?? '', 'kenda-selected-assets-');
    is(count(array_filter(explode("\n", trim($r['body'])))), 4, 'header + three rows');
    is(post('export.php?client=kenda', ['action' => 'start', 'scope' => 'selection', 'items' => ''])['code'], 400);
    is(post('export.php?client=privacybee', ['action' => 'start', 'scope' => 'selection', 'items' => implode(',', selItems())])['code'], 400, 'another client\'s ids are not exported');
    is(post('export.php?client=kenda', ['action' => 'start', 'scope' => 'selection', 'items' => implode(',', selItems())], 'client')['code'], 403);
});
test('Assets select bar: Download + Export for the admin only', function () {
    $a = status(get('assets.php?client=kenda&view=library&filter=approved', 'admin'), 200)['body'];
    has($a, 'data-select-download'); has($a, 'data-select-export'); has($a, 'data-select-post');
    $c = status(get('assets.php?client=kenda&view=library&filter=approved', 'client'), 200)['body'];
    hasNot($c, 'data-select-download'); hasNot($c, 'data-select-export');
});

// ---- entry points + retired routes ------------------------------------------------------------
test('contextual Upload buttons carry their destination (admin only)', function () {
    $s = status(get('assets.php?client=kenda&view=collections&item=1&series=1', 'admin'), 200)['body'];
    ok(preg_match('#data-upload-open data-upload-dest="series" data-upload-tire="1" data-upload-series="1"[^>]*data-series-upload#', $s) === 1, 'series page Upload');
    ok(preg_match('#data-upload-open data-upload-dest="reference" data-upload-tire="1"[^>]*data-ref-upload#', $s) === 1, 'Reference card Upload');
    has($s, 'item=1&amp;series=1&amp;upload=1&amp;dest=series&amp;tire=1', 'no-JS deep link');
    $l = status(get('assets.php?client=kenda&view=library', 'admin'), 200)['body'];
    ok(preg_match('#data-upload-open data-upload-dest="library"[^>]*data-library-upload#', $l) === 1, 'Library Upload');
    // The Studio Uploads / Renders launchers are retired: their URLs land on Posts with the sheet / on the tire (Manage series).
    $u = get('studio.php?client=kenda&tab=uploads', 'admin');
    has($u['location'], 'posts.php?client=kenda&upload=1&dest=post&each=1', 'Studio Uploads → Posts + the sheet (a draft post per file)');
    $rr = get('studio.php?client=kenda&tab=renders&tire=2', 'admin');
    has($rr['location'], 'assets.php?client=kenda&view=collections&item=2&manage=series', 'Studio Renders → the tire, Manage series open');
    $t2 = status(get('assets.php?client=kenda&view=collections&item=2', 'admin'), 200)['body'];
    ok(preg_match('#data-upload-open data-upload-dest="series" data-upload-tire="2" data-upload-series="\d+"[^>]*data-series-upload#', $t2) === 1, 'the tire page Upload is on its series');
    $f = status(get('add-feature.php?client=kenda&module=tires&edit_item=1', 'admin'), 200)['body'];
    has($f, 'assets.php?client=kenda&amp;view=collections&amp;item=1&amp;series=ref&amp;upload=1&amp;dest=reference&amp;tire=1', 'tire form → the sheet on its Reference');
    hasNot($f, 'name="item_images[]"', 'the old reference file input is gone');
    ok(preg_match('#data-new-action="upload"#', status(get('posts.php?client=kenda', 'admin'), 200)['body']) === 1, '+ New → Upload');
    foreach (['assets.php?client=kenda&view=collections&item=1&series=1', 'assets.php?client=kenda&view=library', '?client=kenda'] as $p) {
        hasNot(status(get($p, 'client'), 200)['body'], 'data-upload-open', "client seat: no Upload on {$p}");
    }
});
test('every admin page boots the sheet; the client seat never gets it', function () {
    foreach (['posts.php?client=kenda', 'assets.php?client=kenda', 'manage.php?client=kenda', '?client=kenda', 'manage.php', 'posts.php'] as $p) {
        $b = status(get($p, 'admin'), 200)['body'];
        has($b, 'window.UploadSheetConfig', $p); has($b, 'upload-sheet.js', $p);
    }
    hasNot(status(get('assets.php?client=kenda', 'client'), 200)['body'], 'UploadSheetConfig');
});
test('retired upload routes land on the sheet', function () {
    foreach (['batch.php?client=kenda' => 'posts.php?client=kenda&upload=1&dest=post&each=1',
              'studio.php?client=kenda&tab=batch' => 'posts.php?client=kenda&upload=1&dest=post&each=1',
              'batch.php' => 'posts.php?upload=1'] as $from => $to) {
        $r = get($from, 'admin');
        ok(in_array($r['code'], [301, 302], true), $from . ' redirects');
        has($r['location'], $to, $from);
    }
});

finish();
