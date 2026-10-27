<?php
/**
 * "Export open comments" → Bot pack (ZIP) (comment-export-lib.php cexpBotPlan / cexpBotStartJob / cexpBotFinalFiles,
 * comment-export.php pack=bot; no migration) and the "Export N open" buttons (commentExportPackButton(),
 * cexpPageCounts()):
 *   - the pack: <Client>/<Tire>/<Series | Reference>/<file> + <stem>.comments.md, Library / Posts folders,
 *     manifest.json, comments.csv, REVIEW_INSTRUCTIONS.md — never a .txt / summary.html; the zip's name
 *   - manifest.json: valid JSON, schema_version 1, ISO dates with an offset, scope / counts / items; stable ids (the
 *     portal's tire_image / tire_series / library_image / post ids, activity ids on comments — the same on a rebuild);
 *     every path / comments_path / slide path is a file in the zip; width / height of the file as zipped
 *   - scope: one tire (no other tire, no Library / Posts), one series, the Reference set, the Library; a tire / series of
 *     another client → 404; the sheet's one-pack-per-tire / per-series list
 *   - internal notes only on request (and marked internal); a whole post brings its slides; 1600 px previews; a file
 *     missing on disk → path null + its comments
 *   - the buttons: on the Tires list (per tire), the tire header, the series head, the Reference card, the Library
 *     toolbar, the Redo page (per client + per tire), the Inbox (per client) — each count = the export's count, none at 0,
 *     never for the client seat; client / anon 403 on the endpoint
 */
require __DIR__ . '/lib.php';

$MEDIA = rtrim((string)(getenv('MEDIA_DIR') ?: '/tmp/portal-test/site/media'), '/');
$APP   = rtrim((string)(getenv('APP_DIR') ?: '/tmp/portal-test/site/portal'), '/');
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");
date_default_timezone_set('America/New_York');

function fresh(): void {
    global $APP, $MEDIA;
    shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($APP) . ' ' . escapeshellarg($MEDIA) . ' 2>&1');
}
/** One activity row; $o: company (1), actor ('client'), internal (0), ago (minutes), action ('commented'). */
function act(string $type, int $id, string $text, array $o = []): int {
    $actor = $o['actor'] ?? 'client';
    $co = $o['company'] ?? 1;
    $contact = array_key_exists('contact', $o) ? $o['contact'] : ($actor === 'client' ? ($co === 1 ? 1 : ($co === 2 ? 3 : 4)) : null);
    db()->prepare("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, author_user_id, internal, client_contact_id, created_at)
                   VALUES (?, ?, ?, ?, ?, 'test', ?, ?, ?, ?, NOW() - INTERVAL ? MINUTE)")
        ->execute([$co, $type, $id, $o['action'] ?? 'commented', $actor, $text, $actor === 'admin' ? 1 : null, (int)($o['internal'] ?? 0), $contact, (int)($o['ago'] ?? 10)]);
    return (int)db()->lastInsertId();
}
function j(array $r): array { return $r['json'] ?? []; }
function cnt(array $p, string $role = 'admin'): array { return post('comment-export.php', ['action' => 'count'] + $p, $role, [], ['Accept' => 'application/json']); }
/** Build a bot pack: [entries (name → bytes), manifest (array), start reply]. */
function botPack(array $p): array {
    $r = post('comment-export.php', ['action' => 'zip_start', 'pack' => 'bot'] + $p, 'admin', [], ['Accept' => 'application/json']);
    if ($r['code'] !== 200) return [[], [], $r];
    $job = $r['json']['job'];
    for ($i = 0; $i < 200; $i++) {
        $s = status(post('comment-export.php', ['action' => 'zip_step', 'job' => $job], 'admin', [], ['Accept' => 'application/json']), 200, 'step');
        if (!empty($s['json']['done'])) break;
    }
    $d = status(get('comment-export.php?action=download&job=' . $job), 200, 'download');
    is(substr($d['body'], 0, 2), 'PK', 'a zip');
    $r['download'] = $d;
    $file = sys_get_temp_dir() . '/botpack_' . $job . '.zip';
    file_put_contents($file, $d['body']);
    $z = new ZipArchive();
    ok($z->open($file) === true, 'zip opens');
    $e = [];
    for ($i = 0; $i < $z->numFiles; $i++) $e[$z->getNameIndex($i)] = $z->getFromIndex($i);
    $z->close();
    @unlink($file);
    ok(isset($e['manifest.json']), 'manifest.json in ' . implode(' | ', array_keys($e)));
    $m = json_decode((string)($e['manifest.json'] ?? ''), true);
    ok(is_array($m), 'manifest.json is valid JSON: ' . json_last_error_msg());
    return [$e, $m ?: [], $r];
}
function item(array $m, string $key): ?array { foreach ($m['items'] ?? [] as $it) { if ($it['key'] === $key) return $it; } return null; }
function keys(array $m): array { $k = array_column($m['items'] ?? [], 'key'); sort($k); return $k; }
function texts(array $m): array { $o = []; foreach ($m['items'] ?? [] as $it) foreach ($it['comments'] as $c) $o[] = $c['text']; sort($o); return $o; }
/** data-cexp-count of the [attr] button on a page ('' when there is none). */
function btnCount(string $body, string $attr): string {
    if (!preg_match('#<button[^>]*\b' . preg_quote($attr, '#') . '[^>]*>#', $body, $m)) return '';
    return preg_match('#data-cexp-open-count="(\d+)"#', $m[0], $n) ? $n[1] : '?';
}
/** The standard mix: Klever AT2 (render, reference, series), Klever RT (a render), the Library, a carousel slide. */
function mix(): array {
    return [
        'render'  => act('tire_image', 10, 'Render seven: tread too shallow', ['ago' => 40]),     // AT2 · Series 1 render_07 (pending)
        'ref'     => act('tire_image', 2, 'Reference two is the old sidewall', ['ago' => 35]),    // AT2 reference 2 (approved, waiting)
        'series'  => act('tire_series', 1, 'The whole series reads too warm', ['ago' => 30]),     // AT2 · Series 1
        's2'      => act('tire_image', 13, 'Series two render two: logo blurry', ['ago' => 28]), // AT2 · Series 2 render_02 (approved, waiting)
        'rt'      => act('tire_image', 21, 'Klever RT render two: wrong rim', ['ago' => 27]),     // Klever RT · Series 1 render_02 (approved, waiting)
        'lib'     => act('library_image', 7, 'Library seven: crop tighter', ['ago' => 25]),
        'slide'   => act('post', 2, '[Slide 2] Darker sky on this one', ['ago' => 20]),
        'answer'  => act('tire_image', 10, 'On it — v2 tomorrow', ['actor' => 'admin', 'ago' => 15]),
        'note'    => act('tire_image', 10, 'Joust-only: use the v3 PSD', ['actor' => 'admin', 'internal' => 1, 'ago' => 5]),
    ];
}

// ---------------------------------------------------------------------------------------------------------------------
test('the pack: images + .comments.md, manifest.json, comments.csv, REVIEW_INSTRUCTIONS.md; the zip name', function () {
    global $MEDIA, $APP;
    fresh(); mix();
    [$e, $m, $r] = botPack(['client' => 'kenda']);
    status($r, 200);
    ok(preg_match('/^kenda-open-comments-\d{4}-\d{2}-\d{2}\.zip$/', $r['json']['filename']) === 1, $r['json']['filename']);
    ok(preg_match('/filename="kenda-open-comments-\d{4}-\d{2}-\d{2}\.zip"/', $r['download']['headers']['content-disposition'] ?? '') === 1, 'download name: ' . ($r['download']['headers']['content-disposition'] ?? ''));
    $want = [
        'Kenda Tires/Klever AT2/Series 1/render_07.jpg', 'Kenda Tires/Klever AT2/Series 1/render_07.comments.md',
        'Kenda Tires/Klever AT2/Reference/ref_klever-at2_2.jpg', 'Kenda Tires/Klever AT2/Reference/ref_klever-at2_2.comments.md',
        'Kenda Tires/Klever AT2/Series 1/_series.comments.md',
        'Kenda Tires/Klever AT2/Series 2/render_02.jpg', 'Kenda Tires/Klever AT2/Series 2/render_02.comments.md',
        'Kenda Tires/Klever RT/Series 1/render_02.jpg', 'Kenda Tires/Klever RT/Series 1/render_02.comments.md',
        'Kenda Tires/Library/lib_07.jpg', 'Kenda Tires/Library/lib_07.comments.md',
        'Kenda Tires/Posts/AT2 carousel (post 2)/Slide 2 - img_post02_2.jpg', 'Kenda Tires/Posts/AT2 carousel (post 2)/Slide 2 - img_post02_2.comments.md',
        'Kenda Tires/Posts/Winter promo (post 4)/img_post04_1.jpg', 'Kenda Tires/Posts/Winter promo (post 4)/img_post04_1.comments.md',
        'manifest.json', 'comments.csv', 'REVIEW_INSTRUCTIONS.md',
    ];
    foreach ($want as $n) ok(isset($e[$n]), 'pack has ' . $n . ' — got ' . implode(' | ', array_keys($e)));
    is(count($e), count($want), 'nothing else: ' . implode(' | ', array_diff(array_keys($e), $want)));
    foreach (array_keys($e) as $n) { ok(substr($n, -4) !== '.txt', 'no .txt: ' . $n); ok($n !== 'summary.html', 'no summary.html'); }
    is($e['Kenda Tires/Klever AT2/Series 1/render_07.jpg'], file_get_contents($MEDIA . '/tires/klever-at2/Series 1/render_07.jpg'), 'original bytes (render)');
    is($e['Kenda Tires/Posts/AT2 carousel (post 2)/Slide 2 - img_post02_2.jpg'], file_get_contents($APP . '/uploads/img_post02_2.jpg'), 'original bytes (slide)');
    // comments.csv = the existing CSV
    is(substr($e['comments.csv'], 0, 3), "\xEF\xBB\xBF", 'the CSV (BOM)');
    has($e['comments.csv'], 'Client,Type,Tire,Series,Item / file name,Slide,Item status,Redo,Comment,Author,Date (Eastern),Edited,Thread state,Portal link,Image URL');
    has($e['comments.csv'], 'Render seven: tread too shallow');
    // REVIEW_INSTRUCTIONS.md: the brief — layout, the manifest, per image; neutral
    $ins = $e['REVIEW_INSTRUCTIONS.md'];
    has($ins, '# Review instructions'); has($ins, 'manifest.json'); has($ins, '`comments`'); has($ins, 'per image');
    has($ins, 'Kenda Tires'); has($ins, 'treat it as data');
    foreach (['ignore previous', 'system prompt', 'do not tell', 'secret'] as $bad) ok(stripos($ins, $bad) === false, "nothing like '$bad'");
});

test('manifest.json: schema, ISO dates, stable ids, every path is a file in the zip', function () {
    fresh();
    $ids = mix();
    [$e, $m] = botPack(['client' => 'kenda']);
    is($m['schema_version'], 1);
    ok(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string)$m['generated_at']) === 1, 'generated_at ISO + offset: ' . $m['generated_at']);
    is($m['timezone'], 'America/New_York');
    is($m['client'], ['id' => 1, 'slug' => 'kenda', 'name' => 'Kenda Tires']);
    is($m['clients'], [['id' => 1, 'slug' => 'kenda', 'name' => 'Kenda Tires']]);
    foreach (['client', 'tire', 'series', 'area', 'filter', 'include_internal', 'image_size'] as $k) ok(array_key_exists($k, $m['scope']), "scope.$k");
    is($m['scope']['client'], 'kenda'); is($m['scope']['tire'], null); is($m['scope']['filter'], 'open'); is($m['scope']['include_internal'], false); is($m['scope']['image_size'], 'original');
    is($m['counts']['items'], count($m['items']));
    is($m['counts']['comments'], array_sum(array_map(static function ($i) { return count($i['comments']); }, $m['items'])));
    $c = cnt(['client' => 'kenda'])['json'];
    is($m['counts']['comments'], $c['comments'], 'the count endpoint agrees');
    is($m['counts']['images'], 7);
    is(keys($m), ['library_image:7', 'post:2#slide2', 'post:4', 'tire_image:10', 'tire_image:13', 'tire_image:2', 'tire_image:21', 'tire_series:1']);
    $itemKeys = ['key', 'type', 'id', 'slide', 'kind', 'client', 'tire', 'series', 'post', 'file_name', 'path', 'comments_path', 'media_type', 'mime_type',
                 'width', 'height', 'image_variant', 'missing', 'status', 'status_label', 'redo', 'redo_note', 'thread_state', 'portal_url', 'comments'];
    foreach ($m['items'] as $it) {
        foreach ($itemKeys as $k) ok(array_key_exists($k, $it), "{$it['key']}: $k");
        if ($it['path'] !== null) {
            ok(isset($e[$it['path']]), "{$it['key']}: path in the zip: {$it['path']}");
            $d = getimagesizefromstring($e[$it['path']]);
            is([$it['width'], $it['height']], [$d[0], $d[1]], "{$it['key']}: width/height of the zipped file");
            is($it['mime_type'], 'image/jpeg');
        }
        ok(isset($e[$it['comments_path']]), "{$it['key']}: comments_path in the zip: {$it['comments_path']}");
        ok(strpos((string)$it['portal_url'], 'http') === 0, "{$it['key']}: absolute portal_url");
        foreach ($it['comments'] as $cm) {
            foreach (['id', 'author', 'author_role', 'date', 'edited', 'slide', 'internal', 'text'] as $k) ok(array_key_exists($k, $cm), "{$it['key']} comment: $k");
            ok(in_array($cm['author_role'], ['client', 'joust'], true), 'role ' . $cm['author_role']);
            ok($cm['date'] === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $cm['date']) === 1, 'ISO date: ' . $cm['date']);
            is($cm['internal'], false, 'no internal note by default');
        }
    }
    // the portal's own ids: item {type, id}, comment = activity_log id
    $r = item($m, 'tire_image:10');
    is([$r['type'], $r['id'], $r['kind'], $r['slide']], ['tire_image', 10, 'tire_render', null]);
    is($r['client']['slug'], 'kenda'); is($r['tire'], ['id' => 1, 'name' => 'Klever AT2']); is($r['series'], ['id' => 1, 'name' => 'Series 1']);
    is($r['file_name'], 'render_07.jpg'); is($r['path'], 'Kenda Tires/Klever AT2/Series 1/render_07.jpg'); is($r['media_type'], 'image');
    is([$r['width'], $r['height']], [800, 800]); is($r['image_variant'], 'original'); is($r['missing'], false);
    is($r['status'], 'pending'); is($r['status_label'], 'To Review'); is($r['redo'], false); is($r['redo_note'], null); is($r['thread_state'], 'answered');
    is(array_column($r['comments'], 'id'), [$ids['render'], $ids['answer']], 'activity ids, thread order, no internal note');
    is(array_column($r['comments'], 'author_role'), ['client', 'joust']);
    is($r['comments'][0]['author'], 'Jane Kenda (Kenda Tires)'); is($r['comments'][1]['author'], 'Joust (Lance)');
    is($r['comments'][0]['text'], 'Render seven: tread too shallow');
    $ref = item($m, 'tire_image:2');
    is([$ref['kind'], $ref['series'], $ref['status'], $ref['thread_state']], ['reference', ['id' => null, 'name' => 'Reference'], 'approved', 'waiting_on_joust']);
    $ser = item($m, 'tire_series:1');
    is([$ser['kind'], $ser['path'], $ser['file_name'], $ser['comments_path']], ['series', null, null, 'Kenda Tires/Klever AT2/Series 1/_series.comments.md']);
    is($ser['comments'][0]['id'], $ids['series']);
    $sl = item($m, 'post:2#slide2');
    is([$sl['type'], $sl['id'], $sl['slide'], $sl['kind']], ['post', 2, 2, 'post_image']);
    is($sl['post'], ['id' => 2, 'name' => 'AT2 carousel', 'slides' => 3]);
    is($sl['comments'][0]['slide'], 2); is($sl['comments'][0]['slide_label'], '2'); is($sl['comments'][0]['text'], 'Darker sky on this one', 'no [Slide N] prefix');
    $lib = item($m, 'library_image:7');
    is([$lib['kind'], $lib['tire'], $lib['path']], ['library', null, 'Kenda Tires/Library/lib_07.jpg']);
    // stable: a rebuild gives the same keys, ids and paths
    [, $m2] = botPack(['client' => 'kenda']);
    $sig = static function (array $m): array { return array_map(static function ($i) { return [$i['key'], $i['type'], $i['id'], $i['path'], array_column($i['comments'], 'id')]; }, $m['items']); };
    is($sig($m2), $sig($m), 'the same ids on a rebuild');
    // a Redo-queued render: redo + note
    db()->exec("UPDATE tire_images SET redo_at = NOW(), redo_note = 'Wrong rim colour' WHERE id = 21");
    [, $m3] = botPack(['client' => 'kenda', 'tire' => 2]);
    $rt = item($m3, 'tire_image:21');
    is([$rt['redo'], $rt['redo_note']], [true, 'Wrong rim colour']);
});

test('.comments.md: author, date, slide, status for every comment', function () {
    fresh(); $ids = mix();
    act('post', 2, 'Love the whole carousel', ['ago' => 3]);
    [$e] = botPack(['client' => 'kenda']);
    $md = $e['Kenda Tires/Klever AT2/Series 1/render_07.comments.md'];
    has($md, '# render_07.jpg');
    has($md, '- Client: Kenda Tires'); has($md, '- Tire: Klever AT2'); has($md, '- Series: Series 1');
    has($md, '- Portal item: tire_image 10'); has($md, '- Status: To Review'); has($md, '- Thread: Answered');
    has($md, '- Image in this pack: `Kenda Tires/Klever AT2/Series 1/render_07.jpg`');
    has($md, '## Comments (2)');
    has($md, '### 1. Jane Kenda (Kenda Tires)'); has($md, '### 2. Joust (Lance)');
    ok(preg_match('/_client · \d{4}-\d{2}-\d{2} \d{2}:\d{2} ET · item status: To Review · comment id ' . $ids['render'] . '_/', $md) === 1, 'author role, date, status, id: ' . $md);
    has($md, '> Render seven: tread too shallow');
    hasNot($md, 'v3 PSD', 'no internal note');
    ok(strpos($md, 'tread too shallow') < strpos($md, 'v2 tomorrow'), 'thread order');
    $slide = $e['Kenda Tires/Posts/AT2 carousel (post 2)/Slide 2 - img_post02_2.comments.md'];
    has($slide, '- Post: AT2 carousel (slide 2 of 3)'); has($slide, '· slide 2 ·'); has($slide, '> Darker sky on this one');
    // the whole post: _post.comments.md + every slide of the post in its folder
    $post = $e['Kenda Tires/Posts/AT2 carousel (post 2)/_post.comments.md'] ?? '';
    has($post, '# AT2 carousel — the whole post (all slides)'); has($post, '· all slides ·'); has($post, '> Love the whole carousel');
    foreach (['Slide 1 - img_post02_1.jpg', 'Slide 2 - img_post02_2.jpg', 'Slide 3 - img_post02_3.jpg'] as $f) ok(isset($e['Kenda Tires/Posts/AT2 carousel (post 2)/' . $f]), 'slide in the pack: ' . $f);
    ok(!isset($e['Kenda Tires/Posts/AT2 carousel (post 2)/Slide 1 - img_post02_1.comments.md']), 'no comments file for a slide without its own comments');
    $series = $e['Kenda Tires/Klever AT2/Series 1/_series.comments.md'];
    has($series, '# Series 1 — the whole series'); has($series, '> The whole series reads too warm');
});

test('whole post in the manifest: images[] = every slide, each path in the zip', function () {
    fresh();
    act('post', 2, 'Love the whole carousel');
    [$e, $m] = botPack(['client' => 'kenda', 'area' => 'posts']);
    $p = item($m, 'post:2');
    is([$p['kind'], $p['path'], $p['slide']], ['post', null, null]);
    is(array_column($p['images'], 'slide'), [1, 2, 3]);
    foreach ($p['images'] as $im) { ok(isset($e[$im['path']]), 'slide path in the zip: ' . $im['path']); is([$im['width'], $im['height']], [800, 800]); }
    is($p['comments'][0]['slide'], null); is($p['comments'][0]['slide_label'], 'All slides');
    is($m['counts']['images'], 4, '3 slides + Winter promo');
});

test('scope: one tire — no other tire, no Library, no Posts; the name; parts per series', function () {
    fresh(); mix();
    [$e, $m, $r] = botPack(['client' => 'kenda', 'tire' => 1]);
    ok(preg_match('/^kenda-klever-at2-open-comments-\d{4}-\d{2}-\d{2}\.zip$/', $r['json']['filename']) === 1, $r['json']['filename']);
    is(keys($m), ['tire_image:10', 'tire_image:13', 'tire_image:2', 'tire_series:1']);
    foreach (array_keys($e) as $n) ok(strpos($n, 'Klever RT') === false && strpos($n, 'Library') === false && strpos($n, 'Posts') === false, 'only Klever AT2: ' . $n);
    is($m['scope']['tire'], ['id' => 1, 'name' => 'Klever AT2']); is($m['scope']['area'], 'tires');
    foreach (texts($m) as $t) ok(strpos($t, 'Klever RT') === false && strpos($t, 'Library') === false, 'no other tire\'s comment: ' . $t);
    has($e['comments.csv'], 'Render seven'); hasNot($e['comments.csv'], 'Klever RT render two'); hasNot($e['comments.csv'], 'Library seven');
    // the count = the pack; the sheet's per-series parts
    $c = cnt(['client' => 'kenda', 'tire' => 1])['json'];
    is($c['comments'], $m['counts']['comments']);
    is($c['scope_path'], ['Kenda Tires', 'Klever AT2']);
    is(array_column($c['parts'], 'label'), ['Reference', 'Series 1', 'Series 2']);
    is(array_column($c['parts'], 'series'), ['ref', 1, 2]);
    is(array_sum(array_column($c['parts'], 'comments')), $c['comments'], 'the parts add up');
    // the other tire: only its own
    [, $m2] = botPack(['client' => 'kenda', 'tire' => 2]);
    is(keys($m2), ['tire_image:21']);
    // a tire with nothing open: 400, nothing to export
    status(post('comment-export.php', ['action' => 'zip_start', 'pack' => 'bot', 'client' => 'kenda', 'tire' => 3], 'admin', [], ['Accept' => 'application/json']), 400, 'nothing open on Kenetica Sport');
});

test('scope: one series, the Reference set, the Library; one pack per tire from the client', function () {
    fresh(); mix();
    [$e, $m, $r] = botPack(['client' => 'kenda', 'tire' => 1, 'series' => 1]);
    ok(preg_match('/^kenda-klever-at2-series-1-open-comments-\d{4}-\d{2}-\d{2}\.zip$/', $r['json']['filename']) === 1, $r['json']['filename']);
    is(keys($m), ['tire_image:10', 'tire_series:1'], 'Series 1 renders + the series thread');
    is($m['scope']['series'], ['id' => 1, 'name' => 'Series 1']);
    foreach (array_keys($e) as $n) ok(strpos($n, 'Reference') === false && strpos($n, 'Series 2') === false, 'only Series 1: ' . $n);
    // a series alone gives its tire
    [, $mS] = botPack(['client' => 'kenda', 'series' => 2]);
    is(keys($mS), ['tire_image:13']); is($mS['scope']['tire'], ['id' => 1, 'name' => 'Klever AT2']);
    [$eR, $mR, $rR] = botPack(['client' => 'kenda', 'tire' => 1, 'series' => 'ref']);
    ok(preg_match('/^kenda-klever-at2-reference-open-comments-/', $rR['json']['filename']) === 1, $rR['json']['filename']);
    is(keys($mR), ['tire_image:2']);
    is($mR['scope']['series'], ['id' => null, 'name' => 'Reference']);
    [$eL, $mL, $rL] = botPack(['client' => 'kenda', 'area' => 'library']);
    ok(preg_match('/^kenda-library-open-comments-/', $rL['json']['filename']) === 1, $rL['json']['filename']);
    is(keys($mL), ['library_image:7']);
    // the client: one pack per tire (+ Library + Posts) in the count
    $c = cnt(['client' => 'kenda'])['json'];
    is(array_column($c['parts'], 'label'), ['Klever AT2', 'Klever RT', 'Library', 'Posts']);
    is(array_column($c['parts'], 'tire'), [1, 2, 0, 0]);
    is(array_sum(array_column($c['parts'], 'comments')), $c['comments'], 'the parts add up');
    // the parts' own counts = their own packs
    is($c['parts'][0]['comments'], cnt(['client' => 'kenda', 'tire' => 1])['json']['comments']);
    is($c['parts'][2]['comments'], cnt(['client' => 'kenda', 'area' => 'library'])['json']['comments']);
});

test('never mix clients: another client\'s tire / series → 404; a tire pins its own client', function () {
    fresh(); mix();
    db()->exec("INSERT INTO tires (id, company_id, module_id, name) VALUES (9, 2, (SELECT id FROM modules WHERE slug = 'tires'), 'Bee Tire')");
    db()->exec("INSERT INTO tire_images (id, tire_id, series_id, image_url, sort_order, status) VALUES (90, 9, NULL, 'uploads/ref_klever-at2_1.jpg', 1, 'pending')");
    act('tire_image', 90, 'Privacy Bee tire note', ['company' => 2]);
    foreach ([['client' => 'privacybee', 'tire' => 1], ['client' => 'kenda', 'tire' => 9], ['client' => 'privacybee', 'series' => 1], ['client' => 'kenda', 'tire' => 2, 'series' => 1], ['client' => 'kenda', 'tire' => 12345]] as $p) {
        status(cnt($p), 404, 'count ' . json_encode($p));
        status(post('comment-export.php', ['action' => 'zip_start', 'pack' => 'bot'] + $p, 'admin', [], ['Accept' => 'application/json']), 404, 'zip ' . json_encode($p));
        status(get('comment-export.php?' . http_build_query(['action' => 'csv'] + $p)), 404, 'csv ' . json_encode($p));
    }
    // scope=all never widens a tire: Klever AT2 only, Kenda only
    [, $m] = botPack(['scope' => 'all', 'tire' => 1]);
    is(array_unique(array_column(array_column($m['items'], 'client'), 'slug')), ['kenda']);
    is($m['client']['slug'], 'kenda'); is($m['scope']['all_clients'], false);
    // no posted client: the tire's own
    [, $m2] = botPack(['tire' => 9]);
    is(keys($m2), ['tire_image:90']); is($m2['client']['slug'], 'privacybee');
    // the Kenda pack never carries the Privacy Bee tire
    [, $mk] = botPack(['client' => 'kenda']);
    ok(item($mk, 'tire_image:90') === null, 'not in Kenda');
});

test('internal notes: never by default; with "Include internal notes" they are marked internal', function () {
    fresh(); $ids = mix();
    [$e, $m] = botPack(['client' => 'kenda']);
    ok(!in_array('Joust-only: use the v3 PSD', texts($m), true), 'not in the manifest');
    foreach ($e as $n => $b) hasNot($b, 'v3 PSD', 'not in ' . $n);
    [$e2, $m2] = botPack(['client' => 'kenda', 'internal' => 1]);
    $r = item($m2, 'tire_image:10');
    $n = array_values(array_filter($r['comments'], static function ($c) use ($ids) { return $c['id'] === $ids['note']; }));
    is(count($n), 1, 'the note');
    is([$n[0]['internal'], $n[0]['author_role']], [true, 'joust']);
    is($m2['scope']['include_internal'], true);
    has($e2['Kenda Tires/Klever AT2/Series 1/render_07.comments.md'], 'INTERNAL NOTE (Joust only)');
});

test('1600 px previews; a file missing on disk keeps its comments with path null', function () {
    global $MEDIA;
    fresh();
    $big = $MEDIA . '/library/kenda/lib_08.jpg';
    $im = imagecreatetruecolor(2400, 1600); imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160)); imagejpeg($im, $big, 92); imagedestroy($im);
    act('library_image', 8, 'Big one');
    act('library_image', 7, 'This file goes missing');
    @unlink($MEDIA . '/library/kenda/lib_07.jpg');
    [$e, $m, $r] = botPack(['client' => 'kenda', 'area' => 'library', 'size' => 'lg']);
    ok(count($r['json']['warnings']) === 1, 'a warning: ' . json_encode($r['json']['warnings']));
    $b = item($m, 'library_image:8');
    is($b['image_variant'], 'preview_1600');
    ok(isset($e[$b['path']]), 'the preview in the zip: ' . $b['path']);
    ok(max($b['width'], $b['height']) <= 1600 && $b['width'] > 0, 'at most 1600 px: ' . $b['width'] . '×' . $b['height']);
    $d = getimagesizefromstring($e[$b['path']]);
    is([$b['width'], $b['height']], [$d[0], $d[1]]);
    is($m['scope']['image_size'], 'preview_1600');
    $gone = item($m, 'library_image:7');
    is([$gone['path'], $gone['missing'], $gone['width']], [null, true, null]);
    ok(isset($e[$gone['comments_path']]), 'its comments still travel');
    has($e[$gone['comments_path']], 'missing on the server');
    is($m['counts']['missing_images'], 1);
    // originals by default
    [$e2, $m2] = botPack(['client' => 'kenda', 'area' => 'library']);
    is(item($m2, 'library_image:8')['image_variant'], 'original');
    is($e2['Kenda Tires/Library/lib_08.jpg'], file_get_contents($big), 'the original bytes');
});

test('client / anon: 403 for the bot pack and the scoped count', function () {
    fresh(); mix();
    foreach (['client', 'client:kenda', 'client:privacybee', 'anon'] as $seat) {
        status(post('comment-export.php', ['action' => 'zip_start', 'pack' => 'bot', 'client' => 'kenda', 'tire' => 1], $seat, [], ['Accept' => 'application/json']), 403, "$seat: bot pack");
        status(cnt(['client' => 'kenda', 'tire' => 1], $seat), 403, "$seat: count");
    }
    [$e, , $r] = botPack(['client' => 'kenda', 'tire' => 1]);
    status(get('comment-export.php?action=download&job=' . $r['json']['job'], 'client'), 403, 'client: the zip');
});

test('buttons: Tires list per tire, tire header, series head, Reference card — counts = the export, none at 0', function () {
    fresh(); mix();
    $list = status(get('assets.php?client=kenda&view=collections'), 200)['body'];
    is(btnCount($list, 'data-tire-cexp="1"'), (string)cnt(['client' => 'kenda', 'tire' => 1])['json']['comments'], 'Klever AT2');
    is(btnCount($list, 'data-tire-cexp="2"'), (string)cnt(['client' => 'kenda', 'tire' => 2])['json']['comments'], 'Klever RT');
    is(btnCount($list, 'data-tire-cexp="3"'), '', 'Kenetica Sport: nothing open → no button');
    has($list, 'data-cexp-format="botpack"'); has($list, 'data-cexp-tire="1"');
    ok(preg_match('#<button[^>]*data-tire-cexp="1"[^>]*>.*?<span class="cexp-btn-text">Export </span><span class="cexp-btn-n" data-cexp-n>(\d+)</span><span class="cexp-btn-text"> open</span>#s', $list) === 1, '"Export N open"');
    $tire = status(get('assets.php?client=kenda&view=collections&item=1&series=1'), 200)['body'];
    is(btnCount($tire, 'data-tire-page-cexp'), (string)cnt(['client' => 'kenda', 'tire' => 1])['json']['comments'], 'tire header');
    has($tire, 'Export open comments (');
    is(btnCount($tire, 'data-series-head-cexp="1"'), (string)cnt(['client' => 'kenda', 'tire' => 1, 'series' => 1])['json']['comments'], 'Series 1 head');
    is(btnCount($tire, 'data-ref-cexp'), (string)cnt(['client' => 'kenda', 'tire' => 1, 'series' => 'ref'])['json']['comments'], 'Reference card');
    $s2 = status(get('assets.php?client=kenda&view=collections&item=1&series=2'), 200)['body'];
    is(btnCount($s2, 'data-series-head-cexp="2"'), (string)cnt(['client' => 'kenda', 'tire' => 1, 'series' => 2])['json']['comments'], 'Series 2 head');
    // a tire / series / Reference with nothing open: no buttons at all
    $rt = status(get('assets.php?client=kenda&view=collections&item=3'), 200)['body'];
    foreach (['data-tire-page-cexp', 'data-ref-cexp', 'data-series-head-cexp'] as $a) is(btnCount($rt, $a), '', "Kenetica Sport: no $a");
    $rt2 = status(get('assets.php?client=kenda&view=collections&item=2&series=3'), 200)['body'];
    is(btnCount($rt2, 'data-ref-cexp'), '', 'Klever RT Reference: nothing open');
    is(btnCount($rt2, 'data-series-head-cexp="3"'), '1');
    // nothing open anywhere: no button on the Tires list
    fresh();
    $none = status(get('assets.php?client=kenda&view=collections'), 200)['body'];
    hasNot($none, 'data-tire-cexp', 'no tire buttons at 0');
    hasNot($none, 'data-cexp-pack', 'no pack buttons at 0');
});

test('buttons: the Library toolbar, the Redo page (per client + per tire), the Inbox (per client)', function () {
    fresh(); mix();
    act('post', 8, 'Privacy Bee: logo too small', ['company' => 2]);
    $lib = status(get('assets.php?client=kenda&view=library'), 200)['body'];
    is(btnCount($lib, 'data-library-cexp'), (string)cnt(['client' => 'kenda', 'area' => 'library'])['json']['comments'], 'Library toolbar');
    has($lib, 'data-cexp-area="library"');
    db()->exec("UPDATE tire_images SET redo_at = NOW() WHERE id IN (10, 21)");
    db()->exec("UPDATE library_images SET redo_at = NOW() WHERE id = 7");
    $redo = status(get('redo.php?client=kenda'), 200)['body'];
    is(btnCount($redo, 'data-redo-client-cexp="kenda"'), (string)cnt(['client' => 'kenda'])['json']['comments'], 'Redo: the client');
    is(btnCount($redo, 'data-redo-tire-cexp="1"'), (string)cnt(['client' => 'kenda', 'tire' => 1])['json']['comments'], 'Redo: Klever AT2');
    is(btnCount($redo, 'data-redo-tire-cexp="2"'), (string)cnt(['client' => 'kenda', 'tire' => 2])['json']['comments'], 'Redo: Klever RT');
    is(btnCount($redo, 'data-redo-library-cexp="kenda"'), (string)cnt(['client' => 'kenda', 'area' => 'library'])['json']['comments'], 'Redo: Library');
    $all = status(get('redo.php?client=kenda&all=1'), 200)['body'];
    has($all, 'data-redo-client-head="kenda"');
    is(btnCount($all, 'data-redo-client-cexp="kenda"'), (string)cnt(['client' => 'kenda'])['json']['comments'], 'Redo all: Kenda');
    $ibx = status(get('inbox.php'), 200)['body'];
    is(btnCount($ibx, 'data-inbox-client-cexp="kenda"'), (string)cnt(['client' => 'kenda'])['json']['comments'], 'Inbox: Kenda');
    is(btnCount($ibx, 'data-inbox-client-cexp="privacybee"'), (string)cnt(['client' => 'privacybee'])['json']['comments'], 'Inbox: Privacy Bee');
    is(btnCount($ibx, 'data-inbox-client-cexp="hmf"'), '', 'Inbox: Hollow Mill Farm has nothing open');
    has($ibx, 'data-cexp-client="privacybee"');
    $ibxK = status(get('inbox.php?client=kenda'), 200)['body'];
    is(btnCount($ibxK, 'data-inbox-client-cexp="kenda"'), (string)cnt(['client' => 'kenda'])['json']['comments'], 'Inbox (Kenda)');
    hasNot($ibxK, 'data-inbox-client-cexp="privacybee"', 'one client only');
    // nothing open in the Library → no toolbar button
    fresh();
    hasNot(status(get('assets.php?client=kenda&view=library'), 200)['body'], 'data-library-cexp', 'Library: none at 0');
});

test('the client seat never sees a button', function () {
    fresh(); mix();
    foreach (['assets.php?client=kenda&view=collections', 'assets.php?client=kenda&view=collections&item=1&series=1', 'assets.php?client=kenda&view=collections&item=1&series=ref',
              'assets.php?client=kenda&view=library', 'index.php?client=kenda'] as $page) {
        $b = status(get($page, 'client'), 200, 'client ' . $page)['body'];
        foreach (['data-cexp-pack', 'data-cexp-open', 'cexp-btn', 'CommentExportConfig'] as $needle) hasNot($b, $needle, "client $page: $needle");
    }
});

finish();
