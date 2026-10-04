<?php
/**
 * The Redo queue (redo-lib.php, redo.php, migrate.php 52) and Library → tire moves (library-move.php):
 *   - marking (viewer = one item, select bar = many), the optional note = redo_note + an internal comment (Joust only),
 *     validation, cross-tenant 403, client 403
 *   - a Needs changes decision queues the image (client or admin seat) without a note or a log row
 *   - the client never sees the note (grid, comments panel, Home, feed); a queued image the client can see reads
 *     "Being reworked"
 *   - the Redo view, the Assets / Tires chip, the Home row
 *   - the redo pack: Client/Tire/Series/<original file> + <stem>.txt (feedback, comments, note) + redo-index.csv; the
 *     ORIGINAL bytes; "only new since last export"; every client; the export stamps redo_exported_at + meta
 *   - Replace (replace-image.php, upload-chunk.php, redo.php replace_match by name / by folder path) clears the flag and
 *     sends the image back to To Review (reset_pending by Joust); an ordinary Replace keeps the status
 *   - Remove from redo
 *   - Move to tire: files moved (copy → verify → rows → delete), status / comments / previews / redo flag kept,
 *     "-2" on a name clash, New series…, cross-tenant 403, client 403
 *   - migrate.php 52 is idempotent and queues what is already in Needs changes on first run
 *   - View as client says comments count as the client's
 */
require __DIR__ . '/lib.php';

$MEDIA = rtrim((string)(getenv('MEDIA_DIR') ?: '/tmp/portal-test/site/media'), '/');
$APP   = rtrim((string)(getenv('APP_DIR') ?: '/tmp/portal-test/site/portal'), '/');

/** A JPEG with an exact file name (the name is what "Replace from folder" matches on). */
function namedImage(string $name, string $label = 'fixed'): string {
    $dir = sys_get_temp_dir() . '/redo_smoke_' . bin2hex(random_bytes(4));
    @mkdir($dir, 0777, true);
    $src = tmpImage($label, 500, 400);
    rename($src, $dir . '/' . $name);
    return $dir . '/' . $name;
}
/** Back to the seed fixtures (the queue empty, the files where they were) — every test starts from them. */
function fresh(): void {
    global $APP, $MEDIA;
    shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($APP) . ' ' . escapeshellarg($MEDIA) . ' 2>&1');
}
function mark(string $items, string $note = '', string $role = 'admin', array $extra = []): array {
    return post('redo.php', ['action' => 'mark', 'items' => $items, 'note' => $note] + $extra, $role, [], ['Accept' => 'application/json']);
}
function redoAt(string $kind, int $id) { return q1('SELECT redo_at FROM ' . ($kind === 'library' ? 'library_images' : 'tire_images') . ' WHERE id = ?', [$id]); }
/** Build a redo pack and return [job id, zip path on disk (a local copy), the start reply]. */
function buildPack(array $fields): array {
    $r = post('redo.php', ['action' => 'export_start'] + $fields, 'admin', [], ['Accept' => 'application/json']);
    if ($r['code'] !== 200) return [null, null, $r];
    $job = $r['json']['job'];
    for ($i = 0; $i < 200; $i++) {
        $s = status(post('redo.php', ['action' => 'export_step', 'job' => $job], 'admin', [], ['Accept' => 'application/json']), 200, 'step');
        if (!empty($s['json']['done'])) break;
    }
    $d = status(get('redo.php?action=download&job=' . $job), 200, 'download');
    is(substr($d['body'], 0, 2), 'PK', 'a zip');
    $zip = sys_get_temp_dir() . '/redo_pack_' . $job . '.zip';
    file_put_contents($zip, $d['body']);
    return [$job, $zip, $r];
}
function zipNames(string $zip): array {
    $z = new ZipArchive();
    ok($z->open($zip) === true, 'zip opens');
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) $out[$z->getNameIndex($i)] = $z->getFromIndex($i);
    $z->close();
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------------
test('migrate 52: redo columns on tire_images + library_images', function () {
    foreach (['tire_images', 'library_images'] as $t) {
        foreach (['redo_at', 'redo_note', 'redo_by', 'redo_exported_at'] as $c) {
            is((int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$t, $c]), 1, "$t.$c");
        }
    }
    is((int)q1("SELECT COUNT(*) FROM tire_images WHERE redo_at IS NOT NULL"), 0, 'seed starts with an empty queue');
});

test('mark one image (viewer): redo_at / note / author + an internal note, nothing for the client', function () {
    fresh();
    $r = status(mark('tire:5', 'Sidewall lettering is warped', 'admin', ['client' => 'kenda']), 200);
    is($r['json']['marked'], 1); is($r['json']['count'], 1);
    $row = rows('SELECT redo_at, redo_note, redo_by, status FROM tire_images WHERE id = 5')[0];
    ok($row['redo_at'] !== null, 'queued');
    is($row['redo_note'], 'Sidewall lettering is warped');
    is((int)$row['redo_by'], 1, 'by Lance');
    is($row['status'], 'approved', 'the client status is untouched');
    $acts = rows("SELECT action, actor, internal, detail FROM activity_log WHERE entity_type = 'tire_image' AND entity_id = 5 ORDER BY id");
    is(array_column($acts, 'action'), ['redo_marked', 'commented']);
    is(array_map('intval', array_column($acts, 'internal')), [1, 1], 'both rows internal');
    is($acts[1]['detail'], 'Redo: Sidewall lettering is warped');
    // re-mark: the note changes, the queue time stays, no duplicate
    $at = redoAt('tire', 5);
    $r2 = status(mark('tire:5', 'Also the tread depth'), 200);
    is($r2['json']['marked'], 0); is($r2['json']['updated'], 1);
    is(redoAt('tire', 5), $at, 'redo_at kept');
    is(q1('SELECT redo_note FROM tire_images WHERE id = 5'), 'Also the tread depth');
});

test('mark many (select bar): tire + library in one request', function () {
    fresh();
    $r = status(mark('tire:4,library:1,library:7', ''), 200);
    is($r['json']['marked'], 3);
    ok(redoAt('tire', 4) !== null && redoAt('library', 1) !== null && redoAt('library', 7) !== null, 'all three queued');
    is(q1('SELECT redo_note FROM library_images WHERE id = 1'), null, 'no note');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE action = 'commented' AND entity_type = 'library_image' AND entity_id = 1"), 0, 'no note → no comment row');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE action = 'redo_marked' AND internal = 1"), 3);
});

test('mark: validation, cross-tenant and client 403', function () {
    fresh();
    status(mark('', 'x'), 400, 'no items');
    status(mark('post:1', 'x'), 400, 'not an image kind');
    status(mark('tire:9999'), 404, 'unknown image');
    status(mark('tire:6', '', 'admin', ['client' => 'privacybee']), 403, "another client's image under a client scope");
    is(redoAt('tire', 6), null, 'nothing changed on 403');
    status(mark('tire:6', '', 'client'), 403, 'client seat');
    status(mark('tire:6', '', 'anon'), 403, 'nobody');
    status(get('redo.php?action=mark&items=tire:6'), 405, 'GET is refused');
    status(mark('tire:6', str_repeat('x', 501)), 400, 'note over 500 characters');
    status(post('redo.php', ['action' => 'mark', 'items' => 'tire:6'], 'admin', [], ['Accept' => 'application/json', 'Sec-Fetch-Site' => 'cross-site']), 403, 'cross-site');
    is(redoAt('tire', 6), null);
});

test("auto-queue: the client's Needs changes puts the image in Redo (no note, no author, no extra row); the client still sees Needs changes", function () {
    fresh();
    $r = status(post('tire-status.php', ['id' => 10, 'status' => 'denied', 'comment' => 'The tread pattern is the old one', 'client' => 'kenda'], 'client'), 200);
    ok(redoAt('tire', 10) !== null, 'tire image queued');
    is(q1('SELECT redo_by FROM tire_images WHERE id = 10'), null, 'nobody marked it');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'tire_image' AND entity_id = 10 AND action LIKE 'redo%'"), 0, 'no redo row');
    is(q1('SELECT status FROM tire_images WHERE id = 10'), 'denied');
    status(post('library-status.php', ['id' => 8, 'status' => 'denied', 'comment' => 'Too dark, brighten it', 'client' => 'kenda'], 'client'), 200);
    ok(redoAt('library', 8) !== null, 'library image queued');
    // a client comment alone / an approval never queues
    status(post('tire-status.php', ['id' => 11, 'action' => 'comment', 'comment' => 'nice', 'client' => 'kenda'], 'client'), 200);
    status(post('library-status.php', ['id' => 2, 'status' => 'approved', 'client' => 'kenda'], 'client'), 200);
    is(redoAt('tire', 11), null); is(redoAt('library', 2), null);
});

test('the client never sees the note; a queued image reads "Being reworked"', function () {
    fresh();
    mark('tire:5', 'SECRET-NOTE-xyz Sidewall lettering');
    $g = status(get('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved', 'client'), 200)['body'];
    hasNot($g, 'SECRET-NOTE-xyz', 'grid');
    ok((bool)preg_match('/id="image-5"[^>]*data-redo="1"/', $g), 'the tile is flagged');
    ok((bool)preg_match('/data-thumb-redo>Being reworked</', $g), 'client pill wording');
    hasNot($g, 'data-redo-chip', 'no Redo chip for the client');
    hasNot($g, 'data-viewer-redo ', 'no Mark for redo in the client viewer');
    $c = status(get('assets.php?client=kenda&partial=comments&kind=tire&id=5', 'client'), 200)['json'];
    hasNot($c['html'], 'SECRET-NOTE-xyz', 'comments panel');
    is($c['count'], 0, 'the internal note is not counted for the client');
    $a = status(get('assets.php?client=kenda&partial=comments&kind=tire&id=5'), 200)['json'];
    has($a['html'], 'SECRET-NOTE-xyz', 'Joust sees the note in the thread');
    foreach (['index.php?client=kenda', 'feed.php?client=kenda', 'posts.php?client=kenda'] as $p) {
        $b = get($p, 'client')['body'];
        hasNot($b, 'SECRET-NOTE-xyz', $p); hasNot($b, 'marked for redo', $p);
    }
    status(get('redo.php?action=count', 'client'), 403, 'client: no queue count');
    is(get('redo.php?client=kenda', 'client')['code'], 302, 'client: the Redo page sends to sign-in');
});

test('Redo view: rows with thumbnail, feedback, note, age; scope client / all; chip + Home count', function () {
    fresh();
    mark('tire:5', 'Fix the sidewall');
    status(post('tire-status.php', ['id' => 10, 'status' => 'denied', 'comment' => 'The tread pattern is the old one', 'client' => 'kenda'], 'client'), 200);
    $b = status(get('redo.php?client=kenda'), 200)['body'];
    has($b, 'data-redo-row="tire:5"'); has($b, 'data-redo-row="tire:10"');
    has($b, 'The tread pattern is the old one', 'client feedback');
    has($b, 'Fix the sidewall', 'the note');
    has($b, 'data-redo-count>2<', 'count badge');
    has($b, 'client asked for changes', 'auto-queued line');
    has($b, 'marked by Lance');
    has($b, 'data-redo-age');
    ok((bool)preg_match('#data-redo-row="tire:5".*?class="rd-thumb"[^>]*><img#s', $b), 'thumbnail');
    has($b, 'data-redo-export'); has($b, 'data-redo-folder');
    has(get('redo.php?client=kenda&all=1')['body'], 'data-scope="all"');
    has(get('redo.php')['body'], 'Kenda Tires · Klever AT2 · Series 1', 'all clients: grouped by client');
    $a = get('assets.php?client=kenda')['body'];
    ok((bool)preg_match('/data-redo-chip-count>2</', $a), 'Assets chip count');
    ok((bool)preg_match('/data-redo-chip-count>2</', get('assets.php?client=kenda&view=collections')['body']), 'Tires list chip');
    ok((bool)preg_match('/data-home-link="redo"/', get('index.php?client=kenda')['body']), 'client Home row');
    ok((bool)preg_match('/data-home-redo-count>2</', get('')['body']), 'Today: the count across clients');
    has($a, 'data-select-redo', 'select bar: Mark for redo');
    has($a, 'data-viewer-redo', 'viewer: Mark for redo');
    has($a, 'data-viewer-move', 'viewer: Move to tire');
    has($a, 'id="asActionSheet"', 'the sheet above the viewer');
});

test('redo pack: Client/Tire/Series/<original> + .txt + redo-index.csv; original bytes; stamps the export', function () {
    fresh();
    global $MEDIA;
    mark('tire:5', 'Fix the sidewall');
    status(post('tire-status.php', ['id' => 10, 'status' => 'denied', 'comment' => 'The tread pattern is the old one', 'client' => 'kenda'], 'client'), 200);
    mark('tire:1,library:7', '');
    [$job, $zip, $start] = buildPack(['scope' => 'client', 'client' => 'kenda', 'since' => 0]);
    ok($job !== null, 'started: ' . ($start['body'] ?? ''));
    is($start['json']['files'], 4);
    $z = zipNames($zip);
    $names = array_keys($z);
    foreach (['Kenda Tires/Klever AT2/Series 1/render_02.jpg', 'Kenda Tires/Klever AT2/Series 1/render_02.txt',
              'Kenda Tires/Klever AT2/Series 1/render_07.jpg', 'Kenda Tires/Klever AT2/Series 1/render_07.txt',
              'Kenda Tires/Klever AT2/Reference/ref_klever-at2_1.jpg', 'Kenda Tires/Library/lib_07.jpg', 'Kenda Tires/Library/lib_07.txt', 'redo-index.csv'] as $n) {
        ok(in_array($n, $names, true), 'in the zip: ' . $n . ' — have ' . implode(', ', $names));
    }
    is(count($names), 9, 'four files, four txt, one index');
    is(md5($z['Kenda Tires/Klever AT2/Series 1/render_07.jpg']), md5_file($MEDIA . '/tires/klever-at2/Series 1/render_07.jpg'), 'the ORIGINAL file');
    $txt = $z['Kenda Tires/Klever AT2/Series 1/render_07.txt'];
    has($txt, 'Kenda Tires · Klever AT2 · Series 1'); has($txt, 'File: render_07.jpg'); has($txt, 'Status: Needs changes');
    has($txt, 'The tread pattern is the old one'); has($txt, 'Jane Kenda (Kenda Tires)', 'who said it');
    has($txt, 'automatically — the client asked for changes');
    has($z['Kenda Tires/Klever AT2/Series 1/render_02.txt'], 'Fix the sidewall', 'the note');
    has($z['Kenda Tires/Klever AT2/Series 1/render_02.txt'], 'Lance (internal)', 'the internal note is labelled');
    has($txt, 'Link: http://', 'absolute link');
    $csv = array_map('str_getcsv', preg_split('/\r\n/', trim(substr($z['redo-index.csv'], 3))));
    is($csv[0], ['client', 'tire', 'series', 'file', 'path', 'status', 'feedback', 'redo_note', 'link', 'marked_at', 'marked_by', 'kind', 'id']);
    is(count($csv), 5, 'header + 4 rows');
    $byFile = []; foreach (array_slice($csv, 1) as $row) $byFile[$row[3]] = $row;
    is($byFile['render_07.jpg'][0], 'Kenda Tires'); is($byFile['render_07.jpg'][1], 'Klever AT2'); is($byFile['render_07.jpg'][2], 'Series 1');
    is($byFile['render_07.jpg'][5], 'Needs changes'); is($byFile['render_07.jpg'][6], 'The tread pattern is the old one');
    is($byFile['render_02.jpg'][7], 'Fix the sidewall'); is($byFile['render_02.jpg'][10], 'Lance');
    is($byFile['ref_klever-at2_1.jpg'][2], 'Reference');
    is($byFile['lib_07.jpg'][1], ''); is($byFile['lib_07.jpg'][11], 'library');
    ok(preg_match('#^http://.+asset=10.+kind=tire#', $byFile['render_07.jpg'][8]) === 1, 'link: ' . $byFile['render_07.jpg'][8]);
    ok($byFile['render_07.jpg'][9] !== '', 'date marked');
    // stamped: "only new since last export"
    is((int)q1('SELECT COUNT(*) FROM tire_images WHERE redo_exported_at IS NOT NULL'), 3);
    is((int)q1('SELECT COUNT(*) FROM library_images WHERE redo_exported_at IS NOT NULL'), 1);
    ok(q1("SELECT v FROM meta WHERE k = 'redo_export_last_1'") !== false, 'last export recorded');
    $r = post('redo.php', ['action' => 'export_start', 'scope' => 'client', 'client' => 'kenda', 'since' => 1], 'admin', [], ['Accept' => 'application/json']);
    is($r['code'], 400); has((string)$r['json']['error'], 'Nothing new');
    mark('tire:6', 'New one');
    [$job2, $zip2, $s2] = buildPack(['scope' => 'client', 'client' => 'kenda', 'since' => 1]);
    is($s2['json']['files'], 1, 'only the new one');
    $n2 = array_keys(zipNames($zip2));
    sort($n2);
    is($n2, ['Kenda Tires/Klever AT2/Series 1/render_03.jpg', 'Kenda Tires/Klever AT2/Series 1/render_03.txt', 'redo-index.csv']);
    // re-marking an exported image makes it new again
    mark('tire:5', 'Second pass');
    is((int)post('redo.php', ['action' => 'export_start', 'scope' => 'client', 'client' => 'kenda', 'since' => 1], 'admin', [], ['Accept' => 'application/json'])['json']['files'], 1);
    $page = get('redo.php?client=kenda')['body'];
    has($page, 'data-redo-exported', 'exported pill');
    status(get('redo.php?action=download&job=' . str_repeat('a', 32)), 404, 'unknown job');
    status(get('redo.php?action=download&job=' . $job, 'client'), 403, 'client cannot download');
});

test('redo pack across every client; the redo job never shows as an approved-assets job of another client', function () {
    fresh();
    global $MEDIA;
    @mkdir($MEDIA . '/library/hmf', 0777, true);
    copy(tmpImage('farm'), $MEDIA . '/library/hmf/barn.jpg');
    db()->exec("INSERT INTO library_images (company_id, filename, status) VALUES (3, 'barn.jpg', 'pending')");
    $barn = (int)q1("SELECT id FROM library_images WHERE company_id = 3 AND filename = 'barn.jpg'");
    mark('library:' . $barn, 'Fence is crooked');
    mark('tire:4', '');
    [$job, $zip, $start] = buildPack(['scope' => 'all', 'since' => 0]);
    is($start['json']['clients'], 2);
    $names = array_keys(zipNames($zip));
    ok(in_array('Hollow Mill Farm/Library/barn.jpg', $names, true) && in_array('Kenda Tires/Klever AT2/Series 1/render_01.jpg', $names, true), implode(', ', $names));
    has((string)get('redo.php?action=download&job=' . $job)['headers']['content-disposition'], 'all-clients-redo-pack-');
    status(get('export.php?client=kenda&action=download&job=' . $job), 403, 'not a Kenda export');
});

test('Replace clears the flag and sends the image back to To Review (tire + library, both upload paths)', function () {
    fresh();
    global $MEDIA;
    status(post('tire-status.php', ['id' => 10, 'status' => 'denied', 'comment' => 'Old tread', 'client' => 'kenda'], 'client'), 200);
    $before = (int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'tire_image' AND entity_id = 10 AND action = 'reset_pending'");
    $r = status(post('replace-image.php', ['image_id' => 10, 'type' => 'tire'], 'admin', ['image' => namedImage('fixed.jpg')]), 200);
    is($r['json']['redo_cleared'], true); is($r['json']['status'], 'pending');
    $row = rows('SELECT status, redo_at, redo_note, image_url FROM tire_images WHERE id = 10')[0];
    is($row['status'], 'pending'); is($row['redo_at'], null);
    is($row['image_url'], 'media/tires/klever-at2/Series 1/render_07.jpg', 'replaced in place');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'tire_image' AND entity_id = 10 AND action = 'reset_pending' AND actor = 'admin' AND internal = 0"), $before + 1, 'sent for review by Joust');
    // library through replace-image.php (in place, same name)
    mark('library:7', 'Brighter');
    $old = md5_file($MEDIA . '/library/kenda/lib_07.jpg');
    $r = status(post('replace-image.php', ['image_id' => 7, 'type' => 'library'], 'admin', ['image' => namedImage('whatever.jpg')]), 200);
    is($r['json']['redo_cleared'], true); is($r['json']['filename'], 'lib_07.jpg');
    ok(md5_file($MEDIA . '/library/kenda/lib_07.jpg') !== $old, 'the file changed');
    is(rows('SELECT status, redo_at FROM library_images WHERE id = 7')[0], ['status' => 'pending', 'redo_at' => null]);
    // library through upload-chunk.php purpose=replace (an approved one marked by Joust): approved → To Review; a .png keeps the stem
    mark('library:3', 'Crop tighter');
    $png = sys_get_temp_dir() . '/redo_' . bin2hex(random_bytes(3)) . '.png';
    $im = imagecreatetruecolor(300, 200); imagepng($im, $png);
    $r = status(post('upload-chunk.php', ['action' => 'upload', 'purpose' => 'replace', 'replace_kind' => 'library', 'replace_id' => 3, 'client' => 'kenda'], 'admin', ['file' => $png]), 200);
    is($r['json']['filename'], 'lib_03.png');
    ok(is_file($MEDIA . '/library/kenda/lib_03.png') && !is_file($MEDIA . '/library/kenda/lib_03.jpg'), 'new extension, old file gone');
    is(rows('SELECT filename, status, redo_at FROM library_images WHERE id = 3')[0], ['filename' => 'lib_03.png', 'status' => 'pending', 'redo_at' => null]);
    status(post('upload-chunk.php', ['action' => 'upload', 'purpose' => 'replace', 'replace_kind' => 'library', 'replace_id' => 3, 'client' => 'privacybee'], 'admin', ['file' => $png]), 403, "another client's library image");
    // an ordinary Replace (not queued) keeps the status, as before
    $r = status(post('replace-image.php', ['image_id' => 12, 'type' => 'tire'], 'admin', ['image' => namedImage('x.jpg')]), 200);
    ok(!isset($r['json']['redo_cleared']), 'no redo reply');
    is(q1('SELECT status FROM tire_images WHERE id = 12'), 'approved', 'status kept');
});

test('Replace from folder: by name, by the pack folder when names repeat, 404 / 409 otherwise', function () {
    fresh();
    status(post('tire-status.php', ['id' => 9, 'status' => 'approved'], 'admin'), 200);   // reset the seeded denied ones
    mark('tire:9,tire:17', 'Fix');                                                         // render_06.jpg in Series 1 AND Series 2
    mark('library:5', '');
    $up = static function (string $name, string $path = '', string $scope = 'client') {
        return post('redo.php', ['action' => 'replace_match', 'path' => $path, 'scope' => $scope, 'client' => 'kenda'], 'admin', ['file' => namedImage($name)], ['Accept' => 'application/json']);
    };
    $r = status($up('lib_05.jpg', 'my fixes/lib_05.jpg'), 200);
    is([$r['json']['kind'], $r['json']['id'], $r['json']['status']], ['library', 5, 'pending']);
    is(redoAt('library', 5), null);
    $r = $up('render_06.jpg', 'render_06.jpg');
    is($r['code'], 409, 'two queued render_06.jpg'); is($r['json']['match'], 'ambiguous');
    $r = status($up('render_06.jpg', 'redo-pack/Kenda Tires/Klever AT2/Series 2/render_06.jpg'), 200);
    is($r['json']['id'], 17, 'the folder settles it');
    is(redoAt('tire', 17), null); ok(redoAt('tire', 9) !== null, 'the other stays queued');
    $r = status($up('render_06.jpg', 'render_06.jpg'), 200);   // now only one left
    is($r['json']['id'], 9);
    $r = $up('nothing_like_it.jpg');
    is($r['code'], 404); is($r['json']['match'], 'none');
    $r = $up('render_01.jpg');
    is($r['code'], 404, 'not queued = not replaced');
    status(post('redo.php', ['action' => 'replace_match', 'client' => 'kenda'], 'admin', ['file' => namedImage('lib_04.jpg')], ['Accept' => 'application/json']), 404);
    status(post('redo.php', ['action' => 'replace_match', 'client' => 'kenda'], 'client', ['file' => namedImage('lib_04.jpg')], ['Accept' => 'application/json']), 403, 'client');
});

test('Remove from redo: off the queue, an internal row, nothing else changes', function () {
    fresh();
    mark('tire:4,library:2', 'x');
    $r = status(post('redo.php', ['action' => 'unmark', 'items' => 'tire:4,library:2', 'client' => 'kenda'], 'admin', [], ['Accept' => 'application/json']), 200);
    is($r['json']['cleared'], 2);
    is(redoAt('tire', 4), null); is(redoAt('library', 2), null);
    is(q1('SELECT status FROM tire_images WHERE id = 4'), 'approved');
    is((int)q1("SELECT internal FROM activity_log WHERE action = 'redo_cleared' AND entity_type = 'tire_image' AND entity_id = 4"), 1);
    is(status(post('redo.php', ['action' => 'unmark', 'items' => 'tire:4'], 'admin', [], ['Accept' => 'application/json']), 200)['json']['cleared'], 0, 'not queued: nothing to clear');
    status(post('redo.php', ['action' => 'unmark', 'items' => 'tire:4'], 'client', [], ['Accept' => 'application/json']), 403);
});

// ---------------------------------------------------------------------------------------------------------------------
// Move to tire
// ---------------------------------------------------------------------------------------------------------------------
function moveReq(array $f, string $role = 'admin'): array { return post('library-move.php', ['action' => 'move', 'client' => 'kenda'] + $f, $role, [], ['Accept' => 'application/json']); }

test('Move to tire: targets list', function () {
    fresh();
    $r = status(get('library-move.php?action=targets&client=kenda'), 200)['json'];
    is(array_column($r['tires'], 'name'), ['Kenetica Sport', 'Klever AT2', 'Klever RT']);
    is(array_column($r['tires'][1]['series'], 'name'), ['Series 1', 'Series 2']);
    status(get('library-move.php?action=targets&client=kenda', 'client'), 403, 'client');
});

test('Move to tire: file moved, row mapped, status / comments / previews / redo kept, history follows', function () {
    fresh();
    global $MEDIA, $APP;
    status(post('library-status.php', ['id' => 2, 'action' => 'comment', 'comment' => 'Love this one', 'client' => 'kenda'], 'client'), 200);
    mark('library:2', 'Warm it up');
    // previews of the original (as the Library made them)
    $php = '$_SERVER["SCRIPT_NAME"]="/portal/notify-cron.php"; chdir(' . var_export($APP, true) . '); require "db.php"; require_once "helpers.php"; previewEnsureAll(' . var_export($MEDIA . '/library/kenda/lib_02.jpg', true) . ');';
    shell_exec('php -r ' . escapeshellarg($php));
    ok((bool)glob($MEDIA . '/library/kenda/.thumbs/lib_02.sm.*'), 'the original has previews');
    $bytes = md5_file($MEDIA . '/library/kenda/lib_02.jpg');
    $r = status(moveReq(['ids' => '2', 'tire_id' => 1, 'series_id' => 1]), 200)['json'];
    is($r['moved'], 1); is($r['series']['name'], 'Series 1'); is($r['tire']['name'], 'Klever AT2');
    has($r['url'], 'item=1'); has($r['url'], 'series=1'); has($r['url'], 'filter=approved');
    $new = (int)$r['items'][0]['id'];
    ok(!is_file($MEDIA . '/library/kenda/lib_02.jpg'), 'the original is gone');
    is(md5_file($MEDIA . '/tires/klever-at2/Series 1/lib_02.jpg'), $bytes, 'same bytes in the series folder');
    is((int)q1('SELECT COUNT(*) FROM library_images WHERE id = 2'), 0, 'library row gone');
    $row = rows('SELECT * FROM tire_images WHERE id = ?', [$new])[0];
    is([(int)$row['tire_id'], (int)$row['series_id'], $row['image_url'], $row['status'], $row['display_name']],
       [1, 1, 'media/tires/klever-at2/Series 1/lib_02.jpg', 'approved', 'lib_02']);
    ok($row['redo_at'] !== null && $row['redo_note'] === 'Warm it up', 'the redo flag came along');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'library_image' AND entity_id = 2"), 0, 'no history left behind');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'tire_image' AND entity_id = ? AND action = 'commented' AND detail = 'Love this one'", [$new]), 1, 'the comment follows');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'tire_image' AND entity_id = ? AND action = 'moved_to_tire' AND summary = 'Moved to Klever AT2 · Series 1'", [$new]), 1, 'logged');
    ok((bool)glob($MEDIA . '/tires/klever-at2/Series 1/.thumbs/lib_02.sm.*'), 'previews moved with it');
    ok(!glob($MEDIA . '/library/kenda/.thumbs/lib_02.*'), 'old previews removed');
    ok(!glob($MEDIA . '/tires/klever-at2/Series 1/.moving-*'), 'no temp files');
    $c = status(get('assets.php?client=kenda&partial=comments&kind=tire&id=' . $new, 'client'), 200)['json'];
    has($c['html'], 'Love this one', 'the client still sees their comment');
    hasNot($c['html'], 'Warm it up', '…and never the note');
    $g = get('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved', 'client')['body'];
    has($g, 'id="image-' . $new . '"', 'in the series grid');
    has(get('index.php?client=kenda')['body'], 'moved a Library image to <em>Klever AT2 · Series 1</em>', 'feed: moved to <tire> · <series>');
    // a folder rescan does not register it twice
    status(post('tire-status.php', ['action' => 'rescan', 'tire_id' => 1], 'admin'), 200);
    is((int)q1("SELECT COUNT(*) FROM tire_images WHERE image_url = 'media/tires/klever-at2/Series 1/lib_02.jpg'"), 1);
});

test('Move to tire: many at once, name clash → -2, New series…, pending kept', function () {
    fresh();
    global $MEDIA;
    copy($MEDIA . '/tires/klever-at2/Series 1/render_01.jpg', $MEDIA . '/library/kenda/render_01.jpg');
    db()->exec("INSERT INTO library_images (company_id, filename, status) VALUES (1, 'render_01.jpg', 'pending')");
    $clash = (int)q1("SELECT id FROM library_images WHERE filename = 'render_01.jpg'");
    $r = status(moveReq(['ids' => $clash . ',7', 'tire_id' => 1, 'series_id' => 1]), 200)['json'];
    is($r['moved'], 2);
    $byFrom = []; foreach ($r['items'] as $it) $byFrom[$it['from_id']] = $it;
    is($byFrom[$clash]['filename'], 'render_01-2.jpg'); is($byFrom[$clash]['renamed_from'], 'render_01.jpg');
    ok(is_file($MEDIA . '/tires/klever-at2/Series 1/render_01.jpg') && is_file($MEDIA . '/tires/klever-at2/Series 1/render_01-2.jpg'), 'both files');
    is(q1('SELECT status FROM tire_images WHERE id = ?', [$byFrom[7]['id']]), 'pending');
    $r = status(moveReq(['ids' => '4', 'tire_id' => 2, 'new_series' => 'Studio Shots']), 200)['json'];
    is($r['series']['created'], true); is($r['series']['name'], 'Studio Shots');
    ok(is_file($MEDIA . '/tires/klever-rt/studio-shots/lib_04.jpg'), 'new series folder');
    is((int)q1("SELECT COUNT(*) FROM tire_series WHERE tire_id = 2 AND name = 'Studio Shots'"), 1);
});

test('Move to tire: refused — client, nobody, cross-tenant, bad input; nothing moves', function () {
    fresh();
    global $MEDIA;
    db()->exec("INSERT INTO tires (id, company_id, module_id, name) VALUES (90, 3, 1, 'Farm Tire')");
    status(moveReq(['ids' => '5', 'tire_id' => 1, 'series_id' => 1], 'client'), 403, 'client');
    status(moveReq(['ids' => '5', 'tire_id' => 1, 'series_id' => 1], 'anon'), 403, 'nobody');
    status(moveReq(['ids' => '5', 'tire_id' => 90, 'new_series' => 'x']), 403, "another client's tire");
    status(post('library-move.php', ['action' => 'move', 'client' => 'hmf', 'ids' => '5', 'tire_id' => 90, 'new_series' => 'x'], 'admin', [], ['Accept' => 'application/json']), 403, "Kenda's image to the farm's tire");
    status(moveReq(['ids' => '5', 'tire_id' => 1, 'series_id' => 3]), 404, "another tire's series");
    status(moveReq(['ids' => '', 'tire_id' => 1, 'series_id' => 1]), 400, 'no ids');
    status(moveReq(['ids' => '5', 'tire_id' => 1]), 400, 'no series');
    status(moveReq(['ids' => '999', 'tire_id' => 1, 'series_id' => 1]), 404);
    status(get('library-move.php?action=move&ids=5&tire_id=1&series_id=1'), 405);
    ok(is_file($MEDIA . '/library/kenda/lib_05.jpg'), 'still in the Library');
    is((int)q1('SELECT COUNT(*) FROM library_images WHERE id = 5'), 1);
    is((int)q1("SELECT COUNT(*) FROM tire_series WHERE name = 'x'"), 0, 'no series created on a refused move');
});

test('View as client: the banner says comments count as the client\'s — and they do', function () {
    fresh();
    $r = post('view-as.php', ['client' => 'kenda'], 'admin');
    is($r['code'], 303);
    $sid = '';
    foreach ($r['cookies'] as $c) { if (preg_match('/^jsm_admin=([^;]+)/', $c, $m)) $sid = 'jsm_admin=' . $m[1]; }
    ok($sid !== '', 'the admin session cookie');
    $b = status(get('?client=kenda', 'admin', ['Cookie' => $sid]), 200)['body'];
    has($b, 'data-view-as="kenda"');
    has($b, "Comments you leave here count as the client's and will notify Slack.");
    hasNot($b, 'logged as Joust');
    // what the page posts (actor=client, App.actor from data-actor) is logged as the client's
    has($b, 'data-actor="client"');
    status(post('tire-status.php', ['id' => 11, 'action' => 'comment', 'comment' => 'said while viewing as', 'actor' => 'client', 'client' => 'kenda'], 'admin', [], ['Cookie' => $sid]), 200);
    is(q1("SELECT actor FROM activity_log WHERE detail = 'said while viewing as'"), 'client');
    get('view-as.php?exit=1', 'admin', ['Cookie' => $sid]);
});

test('migrate.php 52: first run queues what is already in Needs changes; a re-run changes nothing', function () {
    fresh();
    global $APP;
    db()->exec("UPDATE tire_images SET status = 'denied' WHERE id = 20");
    db()->exec("ALTER TABLE tire_images DROP INDEX ix_redo, DROP COLUMN redo_at, DROP COLUMN redo_note, DROP COLUMN redo_by, DROP COLUMN redo_exported_at");
    $run = static function () use ($APP): string {
        $tests = dirname(__DIR__);
        return (string)shell_exec('cd ' . escapeshellarg($APP) . ' && PORTAL_TEST=1 PORTAL_TEST_ROLE=admin php -d auto_prepend_file=' . escapeshellarg($tests . '/test-auth.php') . ' migrate.php 2>&1');
    };
    $out = $run();
    has($out, 'Migration complete');
    ok((bool)preg_match('/Added the Redo queue to tire_images[^<]*— (\d+) image/', $out, $m) && (int)$m[1] >= 1, 'queued on first run');
    ok(q1('SELECT redo_at FROM tire_images WHERE id = 20') !== null, 'the denied image is queued');
    is(q1('SELECT redo_at FROM tire_images WHERE id = 21'), null, 'approved ones are not');
    $again = $run();
    has($again, 'tire_images redo columns already exist — skipped');
    has($again, 'library_images redo columns already exist — skipped');
});

finish();
