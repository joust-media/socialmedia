<?php
/**
 * "Export open comments" (comment-export-lib.php, comment-export.php, static/js/comment-export.js; no migration):
 *   - every image type: tire renders, reference images, tire series, Library images, post images (slides resolved)
 *   - the filters: Open (not approved / scheduled, or in the Redo queue, and not resolved — plus anything waiting on
 *     Joust, even approved), Waiting on Joust only, All comments
 *   - internal notes left out unless asked for; deleted comments left out, edited ones with their current text
 *   - trashed items and drafts never; posts without images never
 *   - the CSV: UTF-8 + BOM, the columns, CRLF, quoting, emoji / accents, formula-safe cells, absolute links
 *   - the zip (export-lib.php's stepwise job): Client/Tire/Series/<file> + <stem>.txt, series / whole-post threads,
 *     comments.csv, summary.html, original bytes, the large-preview option
 *   - "only since last export" (meta comment_export_last_<id>), dates, every client
 *   - admin only (client / anon / cross-site 403), cross-tenant isolation; the entry points
 */
require __DIR__ . '/lib.php';

$MEDIA = rtrim((string)(getenv('MEDIA_DIR') ?: '/tmp/portal-test/site/media'), '/');
$APP   = rtrim((string)(getenv('APP_DIR') ?: '/tmp/portal-test/site/portal'), '/');
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");
date_default_timezone_set('America/New_York');   // the app's clock: NOW() here, date('Y-m-d') below and edited_at (DATETIME) agree with it

function fresh(): void {
    global $APP, $MEDIA;
    shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($APP) . ' ' . escapeshellarg($MEDIA) . ' 2>&1');
}
/** One activity row; $o: company (1), actor ('client'), internal (0), contact (1 for a Kenda client row), ago (minutes), action ('commented'), user (admin_users id). */
function act(string $type, int $id, string $text, array $o = []): int {
    $actor = $o['actor'] ?? 'client';
    $co = $o['company'] ?? 1;
    $contact = array_key_exists('contact', $o) ? $o['contact'] : ($actor === 'client' && $co === 1 ? 1 : null);
    db()->prepare("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, author_user_id, internal, client_contact_id, created_at)
                   VALUES (?, ?, ?, ?, ?, 'test', ?, ?, ?, ?, NOW() - INTERVAL ? MINUTE)")
        ->execute([$co, $type, $id, $o['action'] ?? 'commented', $actor, $text, $o['user'] ?? ($actor === 'admin' ? 1 : null), (int)($o['internal'] ?? 0), $contact, (int)($o['ago'] ?? 10)]);
    return (int)db()->lastInsertId();
}
function q(array $p): string { return http_build_query($p); }
/** GET the CSV → [response, rows as assoc arrays (header → cell)]. */
function csv(array $p, string $role = 'admin'): array {
    $r = get('comment-export.php?' . q(['action' => 'csv'] + $p), $role);
    if ($r['code'] !== 200) return [$r, []];
    $body = $r['body'];
    if (substr($body, 0, 3) === "\xEF\xBB\xBF") $body = substr($body, 3);
    $fh = fopen('php://memory', 'r+'); fwrite($fh, $body); rewind($fh);
    $head = fgetcsv($fh, 0, ',', '"', '');
    $rows = [];
    while (($l = fgetcsv($fh, 0, ',', '"', '')) !== false) { if ($l === [null]) continue; $rows[] = array_combine($head, $l); }
    fclose($fh);
    return [$r, $rows];
}
function names(array $rows, string $col = 'Item / file name'): array { return array_values(array_unique(array_column($rows, $col))); }
function findRow(array $rows, string $needle): ?array { foreach ($rows as $r) { if (strpos($r['Comment'], $needle) !== false) return $r; } return null; }
/** Build a zip: [job, local copy path, start reply]. */
function buildZip(array $p): array {
    $r = post('comment-export.php', ['action' => 'zip_start'] + $p, 'admin', [], ['Accept' => 'application/json']);
    if ($r['code'] !== 200) return [null, null, $r];
    $job = $r['json']['job'];
    for ($i = 0; $i < 200; $i++) {
        $s = status(post('comment-export.php', ['action' => 'zip_step', 'job' => $job], 'admin', [], ['Accept' => 'application/json']), 200, 'step');
        if (!empty($s['json']['done'])) break;
    }
    $d = status(get('comment-export.php?action=download&job=' . $job), 200, 'download');
    is(substr($d['body'], 0, 2), 'PK', 'a zip');
    $zip = sys_get_temp_dir() . '/cexp_' . $job . '.zip';
    file_put_contents($zip, $d['body']);
    return [$job, $zip, $r];
}
function zipEntries(string $zip): array {
    $z = new ZipArchive();
    ok($z->open($zip) === true, 'zip opens');
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) $out[$z->getNameIndex($i)] = $z->getFromIndex($i);
    $z->close();
    return $out;
}
/** The standard mix: one comment per image type, all waiting on Joust. */
function mix(): void {
    act('tire_image', 10, 'Render seven: tread too shallow');          // Series 1 render_07 (pending)
    act('tire_image', 2, 'Reference two is the old sidewall');          // Klever AT2 reference 2 (approved) — waiting
    act('tire_series', 1, 'The whole series reads too warm');           // Klever AT2 · Series 1
    act('library_image', 7, 'Library seven: crop tighter');             // lib_07 (pending)
    act('post', 2, '[Slide 2] Darker sky on this one');                  // AT2 carousel, slide 2
}

// ---------------------------------------------------------------------------------------------------------------------
test('every image type: tire render, reference, series, Library, post (filter all)', function () {
    fresh(); mix();
    [$r, $rows] = csv(['client' => 'kenda', 'filter' => 'all']);
    status($r, 200);
    $types = names($rows, 'Type');
    foreach (['Tire render', 'Reference', 'Series', 'Library', 'Post'] as $t) ok(in_array($t, $types, true), "type $t in " . implode(', ', $types));
    $render = findRow($rows, 'tread too shallow');
    is($render['Client'], 'Kenda Tires'); is($render['Tire'], 'Klever AT2'); is($render['Series'], 'Series 1');
    is($render['Item / file name'], 'render_07.jpg'); is($render['Item status'], 'To Review'); is($render['Redo'], 'no');
    is($render['Author'], 'Jane Kenda (Kenda Tires)'); is($render['Edited'], 'no'); is($render['Thread state'], 'Waiting on Joust');
    ok(preg_match('#^https?://[^/]+/portal/assets\.php\?client=kenda&view=collections&item=1&series=1&asset=10&kind=tire$#', $render['Portal link']) === 1, 'absolute admin deep link: ' . $render['Portal link']);
    ok(preg_match('#^https?://[^/]+/media/tires/klever-at2/Series%201/render_07\.jpg$#', $render['Image URL']) === 1, 'absolute original: ' . $render['Image URL']);
    ok(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $render['Date (Eastern)']) === 1, 'date');
    $ref = findRow($rows, 'old sidewall');
    is($ref['Type'], 'Reference'); is($ref['Series'], 'Reference'); is($ref['Item / file name'], 'ref_klever-at2_2.jpg'); is($ref['Item status'], 'Approved');
    $ser = findRow($rows, 'too warm');
    is($ser['Type'], 'Series'); is($ser['Series'], 'Series 1'); is($ser['Image URL'], '', 'a series has no single image');
    ok(strpos($ser['Portal link'], 'item=1&series=1') !== false, 'series link');
    $lib = findRow($rows, 'crop tighter');
    is($lib['Type'], 'Library'); is($lib['Item / file name'], 'lib_07.jpg'); is($lib['Tire'], '');
    ok(preg_match('#/media/library/kenda/lib_07\.jpg$#', $lib['Image URL']) === 1, 'library image url');
    $post = findRow($rows, 'Darker sky');
    is($post['Type'], 'Post'); is($post['Item / file name'], 'AT2 carousel'); is($post['Slide'], '2');
    ok(strpos($post['Portal link'], 'posts.php?client=kenda&post=2') !== false, 'post link');
    // Privacy Bee's post never shows up under Kenda; email / page comments are never image comments
    act('email', 2, 'An email comment', ['company' => 2, 'contact' => 3]);
    act('post', 8, 'Privacy post note', ['company' => 2, 'contact' => 3]);
    [, $rows2] = csv(['client' => 'kenda', 'filter' => 'all']);
    ok(!findRow($rows2, 'Privacy post note') && !findRow($rows2, 'An email comment'), 'no other client, no emails');
});

test('filter Open / Waiting on Joust / All — the exact rule', function () {
    fresh();
    // a) approved render, client asked, Joust replied → answered: only in All
    act('tire_image', 5, 'A: approved and answered', ['ago' => 50]); act('tire_image', 5, 'A: Joust reply', ['actor' => 'admin', 'ago' => 40]);
    // b) approved render, the client spoke last → waiting (even approved)
    act('tire_image', 7, 'B: approved but waiting', ['ago' => 30]);
    // c) To Review render, only Joust spoke → open (status), not waiting
    act('tire_image', 10, 'C: Joust note on a pending render', ['actor' => 'admin', 'ago' => 30]);
    // d) To Review render, client asked, Joust resolved → resolved: only in All
    act('tire_image', 11, 'D: pending but resolved', ['ago' => 30]); act('tire_image', 11, '', ['actor' => 'admin', 'action' => 'resolved', 'internal' => 1, 'ago' => 20]);
    // e) approved render in the Redo queue, answered → open (redo)
    db()->exec("UPDATE tire_images SET redo_at = NOW(), redo_note = 'Wrong rim colour' WHERE id = 8");
    act('tire_image', 8, 'E: in the redo queue', ['ago' => 30]); act('tire_image', 8, 'E: on it', ['actor' => 'admin', 'ago' => 20]);
    // f) Needs changes render, answered → open (status)
    act('tire_image', 9, 'F: needs changes, answered', ['ago' => 30]); act('tire_image', 9, 'F: will fix', ['actor' => 'admin', 'ago' => 20]);
    // g) a scheduled post, answered → only in All; h) an approved post with the client last → waiting
    act('post', 5, 'G: scheduled, answered', ['ago' => 30]); act('post', 5, 'G: thanks', ['actor' => 'admin', 'ago' => 20]);
    act('post', 3, 'H: approved post, one more thing', ['ago' => 5]);
    $set = static function (array $p): array {
        [$r, $rows] = csv(['client' => 'kenda'] + $p);
        status($r, 200);
        $out = [];
        foreach ($rows as $row) { if (preg_match('/^([A-H]):/', $row['Comment'], $m)) $out[$m[1]] = $row['Thread state']; }
        ksort($out);
        return $out;
    };
    $open = $set([]);   // the default
    is(array_keys($open), ['B', 'C', 'E', 'F', 'H'], 'Open');
    is($open['B'], 'Waiting on Joust'); is($open['C'], 'Answered'); is($open['E'], 'Answered'); is($open['H'], 'Waiting on Joust');
    is(array_keys($set(['filter' => 'open'])), ['B', 'C', 'E', 'F', 'H'], 'Open (explicit)');
    is(array_keys($set(['filter' => 'waiting'])), ['B', 'H'], 'Waiting on Joust only');
    $all = $set(['filter' => 'all']);
    is(array_keys($all), ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], 'All');
    is($all['A'], 'Answered'); is($all['D'], 'Resolved'); is($all['G'], 'Answered');
    // the whole thread of an included item travels (the Joust reply on E / F), the Redo column says so
    [, $rows] = csv(['client' => 'kenda']);
    ok(findRow($rows, 'E: on it') !== null, 'Joust reply on an open item');
    is(findRow($rows, 'E: in the redo queue')['Redo'], 'yes — Wrong rim colour');
    is(findRow($rows, 'F: needs changes')['Item status'], 'Needs changes');
    is(findRow($rows, 'H: approved post')['Item status'], 'Approved');
    // the seed's own Needs-changes note on post 4 (no Joust answer) is open and waiting
    ok(findRow($rows, 'Please use the darker render') !== null, 'seeded Needs changes note');
    // a later client message re-opens a resolved thread
    act('tire_image', 11, 'D: actually, one more thing', ['ago' => 1]);
    ok(isset($set([])['D']), 'resolved, then the client wrote again → open');
});

test('internal notes: never by default, only with "Include internal notes"', function () {
    fresh();
    act('tire_image', 10, 'Client: tread too shallow', ['ago' => 30]);
    act('tire_image', 10, 'Joust-only: use the v3 PSD', ['actor' => 'admin', 'internal' => 1, 'ago' => 20]);
    [, $rows] = csv(['client' => 'kenda', 'filter' => 'all']);
    ok(findRow($rows, 'tread too shallow') !== null, 'the client comment');
    ok(findRow($rows, 'v3 PSD') === null, 'no internal note by default');
    [, $rows] = csv(['client' => 'kenda', 'filter' => 'all', 'internal' => 1]);
    $n = findRow($rows, 'v3 PSD');
    ok($n !== null, 'internal note when asked');
    is($n['Author'], 'Joust (Lance) — internal note');
    is(findRow($rows, 'tread too shallow')['Thread state'], 'Waiting on Joust', 'an internal note never answers the client');
    // an internal-only thread is not an export by itself
    fresh();
    act('library_image', 7, 'Joust-only note', ['actor' => 'admin', 'internal' => 1]);
    [, $rows] = csv(['client' => 'kenda', 'filter' => 'all']);
    is(count($rows), 1, 'only the seeded post note');
});

test('deleted comments are left out; edited ones carry the current text (comment-edit.php)', function () {
    fresh();
    $a = act('tire_image', 10, 'Original wording here', ['ago' => 30]);
    $b = act('tire_image', 10, 'This one gets deleted', ['ago' => 20]);
    status(post('comment-edit.php', ['action' => 'edit', 'id' => $a, 'text' => 'Edited wording — darker tread'], 'admin', [], ['Accept' => 'application/json']), 200, 'edit');
    status(post('comment-edit.php', ['action' => 'delete', 'id' => $b], 'admin', [], ['Accept' => 'application/json']), 200, 'delete');
    [, $rows] = csv(['client' => 'kenda', 'filter' => 'all']);
    $e = findRow($rows, 'Edited wording');
    ok($e !== null, 'the current text'); is($e['Edited'], 'yes');
    ok(findRow($rows, 'Original wording') === null, 'never the old text');
    ok(findRow($rows, 'gets deleted') === null, 'never a deleted comment');
    foreach ($rows as $r) ok($r['Comment'] !== '', 'no empty (deleted) rows');
});

test('trashed items, drafts and posts without images are never exported', function () {
    fresh();
    act('post', 6, 'On the draft post');                                  // draft
    act('post', 1, 'On a post that goes to the Trash');
    act('library_image', 7, 'On a library image that goes to the Trash');
    act('tire_image', 10, 'On a render that goes to the Trash');
    act('post', 2, '[Slide 1] Stays');
    db()->exec("UPDATE posts SET trashed_at = NOW() WHERE id = 1");
    db()->exec("UPDATE library_images SET trashed_at = NOW() WHERE id = 7");
    db()->exec("UPDATE tire_images SET trashed_at = NOW() WHERE id = 10");
    // a post whose images are all gone
    db()->exec("DELETE FROM post_images WHERE post_id = 3");
    act('post', 3, 'On a post with no images');
    foreach (['open', 'all'] as $f) {
        [$r, $rows] = csv(['client' => 'kenda', 'filter' => $f]);
        status($r, 200);
        foreach (['draft post', 'goes to the Trash', 'no images'] as $needle) ok(findRow($rows, $needle) === null, "$f: never '$needle'");
        ok(findRow($rows, 'Stays') !== null, "$f: the live post");
    }
    is((int)post('comment-export.php', ['action' => 'count', 'client' => 'kenda', 'filter' => 'all'], 'admin', [], ['Accept' => 'application/json'])['json']['comments'], 2, 'count agrees (Stays + the seeded note)');
});

test('CSV: UTF-8 + BOM, the columns, CRLF, quoting, emoji / accents, formula-safe cells', function () {
    fresh();
    act('tire_image', 10, "Café crème 😀 — naïve façade, \"quoted\", commas, and\na second line");
    act('library_image', 7, '=HYPERLINK("http://evil.example","x")');
    act('library_image', 8, '-5% darker is fine');
    $r = get('comment-export.php?' . q(['action' => 'csv', 'client' => 'kenda', 'filter' => 'all']));
    status($r, 200);
    is(substr($r['body'], 0, 3), "\xEF\xBB\xBF", 'BOM');
    ok(stripos($r['headers']['content-type'] ?? '', 'text/csv; charset=utf-8') === 0, 'content type: ' . ($r['headers']['content-type'] ?? ''));
    ok(preg_match('/attachment; filename="kenda-comments-\d{4}-\d{2}-\d{2}\.csv"/', $r['headers']['content-disposition'] ?? '') === 1, 'file name: ' . ($r['headers']['content-disposition'] ?? ''));
    $o = get('comment-export.php?' . q(['action' => 'csv', 'client' => 'kenda']));
    ok(preg_match('/attachment; filename="kenda-open-comments-\d{4}-\d{2}-\d{2}\.csv"/', $o['headers']['content-disposition'] ?? '') === 1, 'Open: ' . ($o['headers']['content-disposition'] ?? ''));
    ok(mb_check_encoding($r['body'], 'UTF-8'), 'valid UTF-8 throughout');
    $lines = explode("\r\n", substr($r['body'], 3));
    is($lines[0], 'Client,Type,Tire,Series,Item / file name,Slide,Item status,Redo,Comment,Author,Date (Eastern),Edited,Thread state,Portal link,Image URL', 'header');
    ok(substr($r['body'], -2) === "\r\n", 'CRLF');
    has($r['body'], 'Café crème 😀 — naïve façade', 'raw UTF-8 bytes (no escaping, no mojibake)');
    [, $rows] = csv(['client' => 'kenda', 'filter' => 'all']);
    is(findRow($rows, 'Café')['Comment'], "Café crème 😀 — naïve façade, \"quoted\", commas, and\na second line", 'round trip through a CSV parser');
    is(findRow($rows, 'HYPERLINK')['Comment'], "'=HYPERLINK(\"http://evil.example\",\"x\")", 'a formula is defused');
    is(findRow($rows, 'darker is fine')['Comment'], '-5% darker is fine', 'a number stays as typed');
    // the file name follows the filter / scope
    $w = get('comment-export.php?' . q(['action' => 'csv', 'scope' => 'all', 'filter' => 'waiting']));
    ok(preg_match('/filename="all-clients-waiting-on-joust-\d{4}-\d{2}-\d{2}\.csv"/', $w['headers']['content-disposition'] ?? '') === 1, $w['headers']['content-disposition'] ?? '');
    // no comments: still a valid CSV (BOM + header)
    fresh();
    $e = get('comment-export.php?' . q(['action' => 'csv', 'client' => 'hmf']));
    status($e, 200);
    is($e['body'], "\xEF\xBB\xBF" . $lines[0] . "\r\n", 'header only');
});

test('posts: [Slide N] resolves to that slide\'s image; general comments go to the post', function () {
    fresh();
    act('post', 2, '[Slide 2] Darker sky on slide two', ['ago' => 30]);
    act('post', 2, '[Slide 3] Swap the third one', ['ago' => 25]);
    act('post', 2, 'Love the whole carousel', ['ago' => 20]);
    act('post', 2, '[Slide 7] A slide that is gone', ['ago' => 15]);
    act('post', 1, 'Single image, untagged', ['ago' => 10]);
    [, $rows] = csv(['client' => 'kenda']);
    $s2 = findRow($rows, 'Darker sky');
    is($s2['Slide'], '2'); is($s2['Comment'], 'Darker sky on slide two', 'no [Slide N] prefix in the text');
    ok(preg_match('#/uploads/img_post02_2\.jpg$#', $s2['Image URL']) === 1, 'slide 2 image: ' . $s2['Image URL']);
    $s3 = findRow($rows, 'Swap the third');
    is($s3['Slide'], '3'); ok(preg_match('#/uploads/img_post02_3\.jpg$#', $s3['Image URL']) === 1, 'slide 3 image');
    $g = findRow($rows, 'whole carousel');
    is($g['Slide'], 'All slides'); ok(preg_match('#/uploads/img_post02_1\.jpg$#', $g['Image URL']) === 1, 'the cover for a general comment');
    is(findRow($rows, 'slide that is gone')['Slide'], '7 (no longer in the post)');
    $one = findRow($rows, 'Single image');
    is($one['Slide'], ''); ok(preg_match('#/uploads/img_post01_1\.jpg$#', $one['Image URL']) === 1, 'the one image');
    // the post's comments keep their thread order
    $order = array_values(array_filter(array_column($rows, 'Comment'), static function ($c) { return in_array($c, ['Darker sky on slide two', 'Swap the third one', 'Love the whole carousel', 'A slide that is gone'], true); }));
    is($order, ['Darker sky on slide two', 'Swap the third one', 'Love the whole carousel', 'A slide that is gone']);
});

test('zip: Client/Tire/Series/<file> + <stem>.txt, series + whole-post threads, comments.csv, summary.html', function () {
    global $MEDIA, $APP;
    fresh(); mix();
    act('post', 2, 'General note on the carousel');
    act('tire_image', 10, 'Second note on render seven', ['actor' => 'admin', 'ago' => 1]);
    [$job, $zip, $start] = buildZip(['client' => 'kenda', 'filter' => 'all']);
    ok($job !== null, 'started: ' . ($start['body'] ?? ''));
    ok(preg_match('/^kenda-comments-\d{4}-\d{2}-\d{2}\.zip$/', $start['json']['filename']) === 1, $start['json']['filename']);
    $e = zipEntries($zip);
    $want = [
        'Kenda Tires/Klever AT2/Series 1/render_07.jpg', 'Kenda Tires/Klever AT2/Series 1/render_07.txt',
        'Kenda Tires/Klever AT2/Reference/ref_klever-at2_2.jpg', 'Kenda Tires/Klever AT2/Reference/ref_klever-at2_2.txt',
        'Kenda Tires/Klever AT2/Series 1/_series-comments.txt',
        'Kenda Tires/Library/lib_07.jpg', 'Kenda Tires/Library/lib_07.txt',
        'Kenda Tires/Posts/AT2 carousel (post 2)/Slide 2 - img_post02_2.jpg', 'Kenda Tires/Posts/AT2 carousel (post 2)/Slide 2 - img_post02_2.txt',
        'Kenda Tires/Posts/AT2 carousel (post 2)/_post-comments.txt',
        'Kenda Tires/Posts/Winter promo (post 4)/img_post04_1.jpg', 'Kenda Tires/Posts/Winter promo (post 4)/img_post04_1.txt',
        'comments.csv', 'summary.html',
    ];
    foreach ($want as $n) ok(isset($e[$n]), 'zip has ' . $n . ' — got ' . implode(' | ', array_keys($e)));
    is(count($e), count($want), 'nothing else');
    is($e['Kenda Tires/Klever AT2/Series 1/render_07.jpg'], file_get_contents($MEDIA . '/tires/klever-at2/Series 1/render_07.jpg'), 'original bytes (render)');
    is($e['Kenda Tires/Library/lib_07.jpg'], file_get_contents($MEDIA . '/library/kenda/lib_07.jpg'), 'original bytes (library)');
    is($e['Kenda Tires/Posts/AT2 carousel (post 2)/Slide 2 - img_post02_2.jpg'], file_get_contents($APP . '/uploads/img_post02_2.jpg'), 'original bytes (post slide)');
    $t = $e['Kenda Tires/Klever AT2/Series 1/render_07.txt'];
    has($t, 'Kenda Tires · Klever AT2 · Series 1'); has($t, 'File: render_07.jpg'); has($t, 'Status: To Review');
    has($t, 'Jane Kenda (Kenda Tires):'); has($t, 'Render seven: tread too shallow'); has($t, 'Joust (Lance):'); has($t, 'Second note on render seven');
    has($t, "\r\n", 'CRLF');
    ok(strpos($t, 'Render seven') < strpos($t, 'Second note'), 'thread order');
    has($e['Kenda Tires/Klever AT2/Series 1/_series-comments.txt'], 'The whole series reads too warm');
    has($e['Kenda Tires/Posts/AT2 carousel (post 2)/_post-comments.txt'], 'General note on the carousel');
    $slide = $e['Kenda Tires/Posts/AT2 carousel (post 2)/Slide 2 - img_post02_2.txt'];
    has($slide, 'Darker sky on this one'); hasNot($slide, 'General note', 'a slide .txt carries that slide only');
    has($slide, '(slide 2 of 3)');
    is(substr($e['comments.csv'], 0, 3), "\xEF\xBB\xBF", 'the CSV inside');
    has($e['comments.csv'], 'Render seven: tread too shallow');
    has($e['summary.html'], 'src="Kenda%20Tires/Klever%20AT2/Series%201/render_07.jpg"', 'summary links the zipped image');
    // the job is a comment-export job: redo.php / export.php jobs are other jobs
    status(get('comment-export.php?action=download&job=' . str_repeat('a', 32)), 404, 'unknown job');
    status(post('comment-export.php', ['action' => 'zip_start', 'client' => 'hmf'], 'admin', [], ['Accept' => 'application/json']), 400, 'nothing to export');
});

test('zip: "Large previews" sends the 1600 px preview instead of a big original; a missing file keeps its .txt', function () {
    global $MEDIA;
    fresh();
    $big = $MEDIA . '/library/kenda/lib_08.jpg';
    $im = imagecreatetruecolor(2400, 1600); imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160)); imagejpeg($im, $big, 92); imagedestroy($im);
    act('library_image', 8, 'Big one');
    act('library_image', 7, 'This file goes missing');
    @unlink($MEDIA . '/library/kenda/lib_07.jpg');
    [$job, $zip] = buildZip(['client' => 'kenda', 'size' => 'lg']);
    $e = zipEntries($zip);
    $lg = array_values(array_filter(array_keys($e), static function ($n) { return preg_match('#^Kenda Tires/Library/lib_08\.(webp|jpg)$#', $n); }));
    is(count($lg), 1, 'one preview: ' . implode(' | ', array_keys($e)));
    $dims = getimagesizefromstring($e[$lg[0]]);
    ok($dims && max($dims[0], $dims[1]) <= 1600, 'at most 1600 px: ' . json_encode($dims ? [$dims[0], $dims[1]] : null));
    ok(strlen($e[$lg[0]]) < filesize($big), 'smaller than the original');
    ok(isset($e['Kenda Tires/Library/lib_08.txt']), 'its .txt');
    ok(!isset($e['Kenda Tires/Library/lib_07.jpg']), 'the missing file is not in the zip');
    has($e['Kenda Tires/Library/lib_07.txt'] ?? '', 'This file goes missing', 'but its comments are');
    has($e['Kenda Tires/Library/lib_07.txt'] ?? '', 'missing on the server');
    // originals by default
    [, $zip2] = buildZip(['client' => 'kenda']);
    is(zipEntries($zip2)['Kenda Tires/Library/lib_08.jpg'] ?? null, file_get_contents($big), 'the original bytes');
});

test('only since last export (per client), dates', function () {
    fresh();
    act('tire_image', 10, 'Old comment', ['ago' => 600]);
    $old2 = act('library_image', 7, 'Old library comment', ['ago' => 600]);
    act('post', 8, 'Privacy Bee old', ['company' => 2, 'contact' => 3, 'ago' => 600]);
    $c = post('comment-export.php', ['action' => 'count', 'client' => 'kenda', 'since' => 1], 'admin', [], ['Accept' => 'application/json']);
    is($c['json']['last_export'], '', 'never exported');
    is($c['json']['comments'], 3, 'never exported → since = everything');
    [$r] = csv(['client' => 'kenda']);
    status($r, 200);
    $wm = (string)q1("SELECT v FROM meta WHERE k = 'comment_export_last_1'");
    ok(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $wm) === 1, 'the CSV download stamped Kenda: ' . $wm);
    is(q1("SELECT v FROM meta WHERE k = 'comment_export_last_2'"), false, 'not another client');
    is(q1("SELECT v FROM meta WHERE k = 'comment_export_last'"), false, 'not "all clients"');
    sleep(1);
    act('tire_image', 10, 'Brand new comment', ['ago' => 0]);
    [, $rows] = csv(['client' => 'kenda', 'since' => 1]);
    is(array_column($rows, 'Comment'), ['Brand new comment'], 'only what is new');
    // that download moved the watermark again: an edit after it counts as new, the comment it carried does not
    sleep(1);
    db()->prepare("UPDATE activity_log SET detail = 'Old library comment, reworded', edited_at = NOW() WHERE id = ?")->execute([$old2]);
    [, $rows] = csv(['client' => 'kenda', 'since' => 1]);
    is(array_column($rows, 'Comment'), ['Old library comment, reworded']);
    is(findRow($rows, 'reworded')['Edited'], 'yes');
    // all clients: Kenda since its own watermark, Privacy Bee everything (never exported); the zip stamps too
    [, $rows] = csv(['scope' => 'all', 'since' => 1]);
    ok(findRow($rows, 'Privacy Bee old') !== null && findRow($rows, 'Old comment') === null, 'per-client watermarks');
    ok(q1("SELECT v FROM meta WHERE k = 'comment_export_last'") !== false && q1("SELECT v FROM meta WHERE k = 'comment_export_last_2'") !== false, 'all clients stamped every client');
    [, $rows] = csv(['scope' => 'all', 'since' => 1]);
    is(count($rows), 0, 'nothing new since');
    // the zip build stamps
    db()->exec("DELETE FROM meta WHERE k LIKE 'comment\\_export\\_last%'");
    buildZip(['client' => 'kenda']);
    ok(q1("SELECT v FROM meta WHERE k = 'comment_export_last_1'") !== false, 'a built zip stamped');
    // printing never stamps
    db()->exec("DELETE FROM meta WHERE k LIKE 'comment\\_export\\_last%'");
    status(get('comment-export.php?' . q(['action' => 'html', 'client' => 'kenda'])), 200);
    is(q1("SELECT v FROM meta WHERE k = 'comment_export_last_1'"), false, 'the printable page does not stamp');
    // dates (the comment's day, Eastern)
    fresh();
    act('tire_image', 10, 'Ten days ago', ['ago' => 10 * 1440]);
    act('tire_image', 10, 'Today', ['ago' => 1]);
    $today = date('Y-m-d'); $tenAgo = date('Y-m-d', time() - 10 * 86400);
    [, $rows] = csv(['client' => 'kenda', 'from' => $today]);
    ok(findRow($rows, 'Today') && !findRow($rows, 'Ten days ago'), 'from today');
    [, $rows] = csv(['client' => 'kenda', 'to' => $tenAgo]);
    ok(!findRow($rows, 'Today') && findRow($rows, 'Ten days ago'), 'up to ten days ago');
    [, $rows] = csv(['client' => 'kenda', 'from' => $tenAgo, 'to' => $today]);
    ok(findRow($rows, 'Today') && findRow($rows, 'Ten days ago'), 'both');
    [, $rows] = csv(['client' => 'kenda', 'from' => 'not-a-date']);
    ok(findRow($rows, 'Today') && findRow($rows, 'Ten days ago'), 'a bad date is ignored');
});

test('admin only: client / anon / cross-site 403, methods, unknown client', function () {
    fresh(); mix();
    foreach (['client', 'client:kenda', 'client:privacybee', 'anon'] as $seat) {
        status(get('comment-export.php?' . q(['action' => 'csv', 'client' => 'kenda']), $seat), 403, "$seat: csv");
        status(get('comment-export.php?' . q(['action' => 'html', 'client' => 'kenda']), $seat), 403, "$seat: html");
        status(post('comment-export.php', ['action' => 'count', 'client' => 'kenda'], $seat, [], ['Accept' => 'application/json']), 403, "$seat: count");
        status(post('comment-export.php', ['action' => 'zip_start', 'client' => 'kenda'], $seat, [], ['Accept' => 'application/json']), 403, "$seat: zip");
        hasNot(get('comment-export.php?' . q(['action' => 'csv', 'client' => 'kenda']), $seat)['body'], 'tread too shallow', "$seat: no data");
        status(get('comment-export.php?action=nope', $seat), 403, "$seat: not even an unknown action");
    }
    [$job] = buildZip(['client' => 'kenda']);
    status(get('comment-export.php?action=download&job=' . $job, 'client'), 403, 'client: the zip');
    status(post('comment-export.php', ['action' => 'zip_step', 'job' => $job], 'client', [], ['Accept' => 'application/json']), 403, 'client: a step');
    status(get('comment-export.php?' . q(['action' => 'csv', 'client' => 'kenda']), 'admin', ['Sec-Fetch-Site' => 'cross-site']), 403, 'cross-site');
    status(get('comment-export.php?action=zip_start&client=kenda'), 405, 'zip_start is POST');
    status(post('comment-export.php', ['action' => 'csv', 'client' => 'kenda']), 405, 'csv is GET');
    status(get('comment-export.php?action=nope'), 400, 'unknown action');
    status(get('comment-export.php?' . q(['action' => 'csv', 'client' => 'nobody-here'])), 404, 'unknown client');
    status(get('comment-export.php?action=download&job=../../etc'), 400, 'bad job id');
    // a redo pack's job is not a comment export
    db()->exec("UPDATE tire_images SET redo_at = NOW() WHERE id = 9");
    $rp = status(post('redo.php', ['action' => 'export_start', 'client' => 'kenda'], 'admin', [], ['Accept' => 'application/json']), 200);
    status(get('comment-export.php?action=download&job=' . $rp['json']['job']), 404, 'another kind of job');
});

test('cross-tenant: one client never carries another client\'s rows; All clients carries both', function () {
    fresh(); mix();
    act('post', 8, 'Privacy Bee: logo too small', ['company' => 2, 'contact' => 3]);
    // a row filed under Privacy Bee about a Kenda image (should never happen) travels with neither
    act('tire_image', 10, 'Misfiled row', ['company' => 2, 'contact' => 3]);
    [, $k] = csv(['client' => 'kenda', 'filter' => 'all']);
    is(names($k, 'Client'), ['Kenda Tires']);
    ok(!findRow($k, 'logo too small') && !findRow($k, 'Misfiled'), 'Kenda: only Kenda');
    [, $p] = csv(['client' => 'privacybee', 'filter' => 'all']);
    is(array_column($p, 'Comment'), ['Privacy Bee: logo too small'], 'Privacy Bee: only its post');
    is($p[0]['Author'], 'Pat Bee (Privacy Bee)');
    [, $a] = csv(['scope' => 'all', 'filter' => 'all']);
    $clients = names($a, 'Client');
    sort($clients);
    is($clients, ['Kenda Tires', 'Privacy Bee'], 'All clients');
    ok(!findRow($a, 'Misfiled'), 'never the misfiled row');
    // scope=all wins over a page's client; client=hmf (no images) → header only
    [, $a2] = csv(['scope' => 'all', 'client' => 'kenda', 'filter' => 'all']);
    is(count($a2), count($a));
    [, $h] = csv(['client' => 'hmf', 'filter' => 'all']);
    is(count($h), 0);
    $c = post('comment-export.php', ['action' => 'count', 'scope' => 'all', 'filter' => 'all'], 'admin', [], ['Accept' => 'application/json'])['json'];
    is($c['clients'], 2); is($c['comments'], count($a)); is($c['scope'], 'all');
    // the zip of one client holds one client folder
    [, $zip] = buildZip(['client' => 'privacybee', 'filter' => 'all']);
    foreach (array_keys(zipEntries($zip)) as $n) ok(strpos($n, 'Kenda') === false, 'no Kenda in the Privacy Bee zip: ' . $n);
});

test('count + the printable summary', function () {
    fresh(); mix();
    $c = status(post('comment-export.php', ['action' => 'count', 'client' => 'kenda'], 'admin', [], ['Accept' => 'application/json']), 200)['json'];
    is($c['comments'], 6, 'mix + the seeded note'); is($c['items'], 6); is($c['images'], 5, 'the series has no image'); is($c['clients'], 1);
    is(status(get('comment-export.php?' . q(['action' => 'count', 'client' => 'kenda', 'filter' => 'waiting'])), 200)['json']['comments'], 6, 'GET works too; all waiting');
    $h = status(get('comment-export.php?' . q(['action' => 'html', 'client' => 'kenda'])), 200);
    ok(stripos($h['headers']['content-type'] ?? '', 'text/html') === 0, 'html');
    has($h['body'], 'data-cexp-summary'); has($h['body'], 'Open comments — Kenda Tires'); has($h['body'], 'Print / Save as PDF');
    is(substr_count($h['body'], 'data-cexp-block='), 6, 'one block per image (+ the series)');
    has($h['body'], 'Render seven: tread too shallow');
    ok(preg_match('#<img src="[^"]+"#', $h['body']) === 1, 'thumbnails');
    has($h['body'], 'Whole series');
});

test('entry points: Manage → Export, Redo, the Assets / Tires ⋯ menus, the series ⋯, the Inbox — admin only', function () {
    fresh();
    $pages = [
        'manage.php?client=kenda&section=export' => 'data-manage-cexp-open',
        'manage.php?section=export' => 'data-manage-cexp-open',
        'redo.php?client=kenda' => 'data-redo-comments',
        'assets.php?client=kenda&view=library' => 'data-assets-cexp',
        'assets.php?client=kenda&view=collections' => 'data-assets-cexp',
        'assets.php?client=kenda&view=collections&item=1&series=1' => 'data-series-cexp',
        'inbox.php' => 'data-inbox-export',
        'inbox.php?client=kenda' => 'data-inbox-export',
    ];
    foreach ($pages as $page => $marker) {
        $b = status(get($page), 200, $page)['body'];
        has($b, $marker, $page);
        has($b, 'data-cexp-open', $page);
        has($b, 'window.CommentExportConfig', $page);
        has($b, 'js/comment-export.js', $page);
    }
    has(get('manage.php?section=export')['body'], 'data-cexp-scope="all"', 'unscoped Manage → All clients');
    has(get('inbox.php')['body'], 'data-cexp-scope="all"', 'unscoped Inbox → All clients');
    hasNot(get('redo.php?client=kenda')['body'], 'data-redo-comments data-cexp-scope="all"', 'scoped redo → this client');
    has(get('redo.php?client=kenda&all=1')['body'], 'data-cexp-scope="all"', 'Redo · All clients');
    // the client seat: no button, no boot tags
    foreach (['assets.php?client=kenda&view=library', 'assets.php?client=kenda&view=collections', 'assets.php?client=kenda&view=collections&item=1&series=1', 'index.php?client=kenda'] as $page) {
        $b = status(get($page, 'client'), 200, 'client ' . $page)['body'];
        hasNot($b, 'data-cexp-open', 'client ' . $page);
        hasNot($b, 'CommentExportConfig', 'client ' . $page);
    }
});

finish();
