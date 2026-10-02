<?php
/**
 * Image performance: browser-made previews accepted at upload (and every refusal), the host-wide generator cap +
 * placeholder, no redirect to the original, both sizes from one lazy build, lg for small-but-heavy originals,
 * claims carrying their previews into the post, the session released before GD, guarded .htaccess rules, no
 * preview work in the collections render, the Build previews job (progress + cap).
 */
require __DIR__ . '/lib.php';

$ROOT  = getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test';
$APP   = getenv('APP_DIR') ?: $ROOT . '/site/portal';
$MEDIA = getenv('MEDIA_DIR') ?: $ROOT . '/site/media';

/** A JPEG on disk: $noise = random pixels (heavy), else a flat fill with a shape (light). */
function ipJpeg(string $label, int $w, int $h, bool $noise = false, int $q = 85): string {
    $p = sys_get_temp_dir() . '/ip_' . preg_replace('/\W+/', '_', $label) . '_' . bin2hex(random_bytes(3)) . '.jpg';
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 160));
    if ($noise) {
        for ($y = 0; $y < $h; $y += 2) for ($x = 0; $x < $w; $x += 2) {
            $c = imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, $c);
        }
    } else {
        imagefilledellipse($im, (int)($w / 2), (int)($h / 2), (int)($w * .6), (int)($h * .6), imagecolorallocate($im, 220, 220, 220));
    }
    imagestring($im, 5, 10, 10, $label, imagecolorallocate($im, 255, 255, 255));
    imagejpeg($im, $p, $q);
    return $p;
}
/** A WebP of w×h ($q 101 = lossless; with $noise that is big). */
function ipWebp(int $w, int $h, int $q = 78, bool $noise = false): string {
    $p = sys_get_temp_dir() . '/ip_pv_' . bin2hex(random_bytes(4)) . '.webp';
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 90, 120, 60));
    if ($noise) for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) imagesetpixel($im, $x, $y, random_int(0, 0xFFFFFF));
    imagewebp($im, $p, $q);
    return $p;
}

/** A browser seat with its own cookie jar (a real jsm_admin session across requests). */
function ipSeat(): string { return tempnam(sys_get_temp_dir(), 'ipjar'); }
/** POST multipart with a seat's cookies. $files = field → path (sent as image/webp unless $types says otherwise). */
function ipPost(string $jar, string $path, array $data, array $files = [], array $types = []): array {
    $hdrs = [];
    $fields = $data;
    foreach ($files as $k => $f) $fields[$k] = new CURLFile($f, $types[$k] ?? 'image/webp', basename($f));
    $ch = curl_init(base() . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_TIMEOUT => 120,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIE => 'portal_test_role=admin', CURLOPT_USERAGENT => SMOKE_UA,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$hdrs) {
            $p = strpos($line, ':');
            if ($p !== false) $hdrs[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'headers' => $hdrs, 'json' => json_decode($body, true)];
}
/** Upload one image to upload-chunk.php (single request) with client_previews=1. */
function ipUpload(string $jar, string $file, array $fields): array {
    return ipPost($jar, 'upload-chunk.php', $fields + ['action' => 'upload', 'client' => 'kenda', 'client_previews' => '1',
        'name' => basename($file), 'size' => filesize($file), 'type' => 'image/jpeg'], ['file' => $file], ['file' => 'image/jpeg']);
}
/** Run PHP against the test site's own libraries (same config.php → same preview secret). Returns stdout. */
function ipApp(string $code): string {
    global $APP;
    $pre = 'require ' . var_export($APP . '/media-lib.php', true) . '; require ' . var_export($APP . '/tire-series-lib.php', true) . '; '
         . 'if (is_file(' . var_export($APP . '/pages-lib.php', true) . ')) require ' . var_export($APP . '/pages-lib.php', true) . '; '
         . 'require ' . var_export($APP . '/preview-lib.php', true) . '; ';
    $out = shell_exec('cd ' . escapeshellarg($APP) . ' && php -d display_errors=stderr -r ' . escapeshellarg($pre . $code) . ' 2>&1');
    return trim((string)$out);
}
/** The signed lazy URL of a ref, relative to the app (preview.php?f=…&s=…&v=…). */
function ipLazy(string $ref, string $size): string {
    return ipApp('$abs = previewRefPath(' . var_export($ref, true) . '); echo ltrim(previewEndpointUrl(' . var_export($ref, true) . ', ' . var_export($size, true) . ', previewMtime((string)$abs)), "/");');
}
function ipGet(string $path): array { return get($path, 'anon'); }
/** Hold every generator slot (flock) — like two big decodes running right now. Returns the handles. */
function ipHoldSlots(): array {
    global $APP;
    $dir = $APP . '/uploads/.locks';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $hs = [];
    for ($i = 0; $i < 2; $i++) {
        $h = fopen("$dir/slot-$i.lock", 'c');
        $got = false;
        for ($k = 0; $k < 100 && !($got = flock($h, LOCK_EX | LOCK_NB)); $k++) usleep(100000);   // an lg finishing after its early answer
        ok($got, "hold slot $i");
        $hs[] = $h;
    }
    return $hs;
}
function ipRelease(array $hs): void { foreach ($hs as $h) { flock($h, LOCK_UN); fclose($h); } }
function ipWebpDims(string $f): array { $i = getimagesize($f); return [(int)$i[0], (int)$i[1], (int)$i[2]]; }

// ---------------------------------------------------------------------------------------------------------------
// Client-made previews
// ---------------------------------------------------------------------------------------------------------------
test('library upload with client previews: no server decode, both files accepted at the preview-lib paths', function () use ($MEDIA) {
    $jar = ipSeat();
    $src = ipJpeg('cp-ok', 2000, 1500);
    $r = ipUpload($jar, $src, ['purpose' => 'library']);
    status($r, 200, 'upload');
    $key = (string)($r['json']['preview_key'] ?? '');
    ok(preg_match('/^[a-f0-9]{32}$/', $key) === 1, 'preview_key in the reply');
    is($r['headers']['x-preview-gd'] ?? '', '0', 'no GD during the upload');
    $name = (string)$r['json']['image']['filename'];
    $stem = pathinfo($name, PATHINFO_FILENAME);
    $thumbs = "$MEDIA/library/kenda/.thumbs";
    ok(!is_file("$thumbs/$stem.sm.webp"), 'nothing made by the server yet');
    $p = ipPost($jar, 'upload-chunk.php', ['action' => 'previews', 'preview_key' => $key, 'client' => 'kenda'], ['sm' => ipWebp(480, 360), 'lg' => ipWebp(1600, 1200)]);
    status($p, 200, 'previews');
    is($p['json']['accepted'] ?? null, ['sm', 'lg'], 'accepted');
    is($p['json']['generated'] ?? null, false, 'nothing generated');
    is($p['headers']['x-preview-gd'] ?? '', '0', 'no GD on accept');
    ok(is_file("$thumbs/$stem.sm.webp") && is_file("$thumbs/$stem.lg.webp"), 'files at <dir>/.thumbs/<stem>.sm|lg.webp');
    is(ipWebpDims("$thumbs/$stem.lg.webp"), [1600, 1200, IMAGETYPE_WEBP], 'lg dims');
    has((string)$p['json']['thumb'], '/media/library/kenda/.thumbs/' . $stem . '.sm.webp?v=', 'thumb is the static URL');
    has((string)$p['json']['large'], '.lg.webp?v=', 'large is the static URL');
    // the library grid now prints the static files, not preview.php, for this image
    $page = get('assets.php?client=kenda&view=library', 'admin')['body'];
    has($page, '.thumbs/' . rawurlencode($stem) . '.sm.webp', 'grid uses the static sm');
    // single use
    $again = ipPost($jar, 'upload-chunk.php', ['action' => 'previews', 'preview_key' => $key, 'client' => 'kenda'], ['sm' => ipWebp(480, 360)]);
    is($again['code'], 404, 'a used key is gone');
});

test('client previews refused: wrong type, oversize, wrong aspect, wrong size, not needed → server makes them', function () use ($MEDIA) {
    $jar = ipSeat();
    $cases = [
        'jpeg bytes'  => [['sm' => ipJpeg('x', 480, 360)], 'not a WebP'],
        'fake magic'  => [['sm' => (function () { $f = sys_get_temp_dir() . '/ip_fake_' . bin2hex(random_bytes(3)) . '.webp'; file_put_contents($f, 'RIFF' . pack('V', 100) . 'WEBP' . str_repeat('x', 100)); return $f; })()], 'not a WebP image'],
        'oversize'    => [['sm' => ipWebp(480, 360, 101, true)], 'too large'],
        'aspect'      => [['sm' => ipWebp(480, 320)], 'aspect'],
        'long edge'   => [['sm' => ipWebp(400, 300)], 'longest side'],
        'over max'    => [['lg' => ipWebp(1700, 1275)], 'longest side'],
    ];
    foreach ($cases as $label => [$files, $why]) {
        $src = ipJpeg('cp-' . $label, 2000, 1500);
        $r = status(ipUpload($jar, $src, ['purpose' => 'library']), 200, $label . ' upload');
        $key = (string)$r['json']['preview_key'];
        $stem = pathinfo((string)$r['json']['image']['filename'], PATHINFO_FILENAME);
        $p = status(ipPost($jar, 'upload-chunk.php', ['action' => 'previews', 'preview_key' => $key, 'client' => 'kenda'], $files), 200, $label);
        $size = array_keys($files)[0];
        ok(isset($p['json']['rejected'][$size]), "$label: $size refused");
        has((string)$p['json']['rejected'][$size], $why, $label);
        is($p['json']['accepted'] ?? null, [], "$label: nothing accepted");
        is($p['json']['generated'] ?? null, true, "$label: server fallback");
        is($p['headers']['x-preview-gd'] ?? '', '1', "$label: one decode for both sizes");
        $sm = "$MEDIA/library/kenda/.thumbs/$stem.sm.webp";
        ok(is_file($sm), "$label: server-made sm exists");
        is(array_slice(ipWebpDims($sm), 0, 2), [480, 360], "$label: the server's own sm");
    }
    // a small original: sm not needed → a sent sm is refused, nothing else
    $r = status(ipUpload($jar, ipJpeg('cp-small', 400, 300), ['purpose' => 'library']), 200);
    ok(!isset($r['json']['preview_key']), 'no key when no preview is needed');
});

test("someone else's key / another client / unknown key are refused", function () {
    $a = ipSeat(); $b = ipSeat();
    $r = status(ipUpload($a, ipJpeg('cp-other', 2000, 1500), ['purpose' => 'library']), 200);
    $key = (string)$r['json']['preview_key'];
    $p = ipPost($b, 'upload-chunk.php', ['action' => 'previews', 'preview_key' => $key, 'client' => 'kenda'], ['sm' => ipWebp(480, 360)]);
    is($p['code'], 403, 'another admin session');
    $p = ipPost($a, 'upload-chunk.php', ['action' => 'previews', 'preview_key' => $key, 'client' => 'hmf'], ['sm' => ipWebp(480, 360)]);
    is($p['code'], 403, 'another client');
    is(ipPost($a, 'upload-chunk.php', ['action' => 'previews', 'preview_key' => str_repeat('a', 32), 'client' => 'kenda'], [])['code'], 404, 'unknown key');
    is(ipPost($a, 'upload-chunk.php', ['action' => 'previews', 'preview_key' => '../x', 'client' => 'kenda'], [])['code'], 400, 'malformed key');
    // the rightful owner can still redeem it (refusals above did not burn it)
    $p = status(ipPost($a, 'upload-chunk.php', ['action' => 'previews', 'preview_key' => $key, 'client' => 'kenda'], ['sm' => ipWebp(480, 360), 'lg' => ipWebp(1600, 1200)]), 200, 'owner');
    is($p['json']['accepted'] ?? null, ['sm', 'lg']);
});

test('parked New-post file: previews accepted, reply never points at tmp_, they follow the file into the post (0 decodes)', function () use ($APP) {
    $jar = ipSeat();
    $r = status(ipUpload($jar, ipJpeg('cp-claim', 2400, 1600), ['purpose' => 'batch']), 200);
    $token = (string)$r['json']['token'];
    hasNot((string)$r['json']['preview_url'], '/uploads/tmp_' . $token . '.jpg', 'preview_url is not the original');
    hasNot((string)$r['json']['thumb'] . (string)$r['json']['large'], 'uploads/tmp_' . $token . '.jpg?', 'thumb / large are not the original');
    $p = status(ipPost($jar, 'upload-chunk.php', ['action' => 'previews', 'preview_key' => (string)$r['json']['preview_key'], 'client' => 'kenda'],
        ['sm' => ipWebp(480, 320), 'lg' => ipWebp(1600, 1067)]), 200);
    is($p['json']['accepted'] ?? null, ['sm', 'lg']);
    has((string)$p['json']['thumb'], '/uploads/.thumbs/tmp_' . $token . '.sm.webp?v=');
    is($p['json']['generated'] ?? null, false, 'a parked file is never generated at upload');
    $b = ipPost($jar, 'batch-process.php?client=kenda', ['claimed[0]' => $token, 'client' => 'kenda']);
    status($b, 200, 'draft created');
    is($b['headers']['x-preview-gd'] ?? '', '0', 'post save decoded nothing');
    $postId = (int)($b['json']['created'][0]['post_id'] ?? 0);
    ok($postId > 0, 'post id');
    $url = (string)q1("SELECT image_url FROM post_images WHERE post_id = ? ORDER BY id LIMIT 1", [$postId]);
    $stem = pathinfo($url, PATHINFO_FILENAME);
    ok(is_file("$APP/uploads/.thumbs/$stem.sm.webp") && is_file("$APP/uploads/.thumbs/$stem.lg.webp"), 'previews moved with the file');
    ok(!is_file("$APP/uploads/.thumbs/tmp_$token.sm.webp"), 'old stem gone');
    ok(filemtime("$APP/uploads/.thumbs/$stem.sm.webp") >= filemtime("$APP/uploads/$stem.jpg"), 'fresh');
});

test('series upload (tire-upload.php) takes client previews too', function () use ($MEDIA) {
    $jar = ipSeat();
    $src = ipJpeg('cp-series', 2000, 1500);
    $r = ipPost($jar, 'tire-upload.php', ['client' => 'kenda', 'tire_id' => 1, 'series_id' => 1, 'client_previews' => '1'], ['file' => $src], ['file' => 'image/jpeg']);
    status($r, 200, 'series upload');
    is($r['headers']['x-preview-gd'] ?? '', '0', 'no decode');
    $key = (string)($r['json']['preview_key'] ?? '');
    ok($key !== '', 'key');
    $p = status(ipPost($jar, 'tire-upload.php', ['action' => 'previews', 'preview_key' => $key, 'client' => 'kenda'], ['sm' => ipWebp(480, 360), 'lg' => ipWebp(1600, 1200)]), 200);
    is($p['json']['accepted'] ?? null, ['sm', 'lg']);
    has((string)$p['json']['thumb'], '/media/tires/klever-at2/Series%201/.thumbs/');
});

// ---------------------------------------------------------------------------------------------------------------
// Lazy path: one decode for both sizes, the cap, placeholders, no original
// ---------------------------------------------------------------------------------------------------------------
test('lazy build makes BOTH sizes in one decode and 302s to the static URL (1-year cache)', function () use ($MEDIA) {
    $f = "$MEDIA/library/kenda/lazy-both.jpg";
    copy(ipJpeg('lazy-both', 2000, 1500), $f);
    $u = ipLazy('media/library/kenda/lazy-both.jpg', 'sm');
    has($u, 'preview.php?f=');
    $r = ipGet($u);
    is($r['code'], 302, 'redirect');
    is($r['location'], '/media/library/kenda/.thumbs/lazy-both.sm.webp?v=' . filemtime($f), 'to the static sm');
    has($r['headers']['cache-control'] ?? '', 'max-age=31536000');
    has($r['headers']['cache-control'] ?? '', 'immutable');
    is($r['headers']['x-preview-gd'] ?? '', '1', 'one decode');
    // the tile was answered as soon as sm existed; lg of the same decode is finished right after the response
    for ($i = 0; $i < 100 && !is_file("$MEDIA/library/kenda/.thumbs/lazy-both.lg.webp"); $i++) usleep(100000);
    ok(is_file("$MEDIA/library/kenda/.thumbs/lazy-both.sm.webp"), 'sm there when answered');
    ok(is_file("$MEDIA/library/kenda/.thumbs/lazy-both.lg.webp"), 'lg made in the same decode');
    usleep(300000);
    $r2 = ipGet(ipLazy('media/library/kenda/lazy-both.jpg', 'lg'));
    is($r2['code'], 302);
    is($r2['headers']['x-preview-gd'] ?? '', '0', 'lg request decodes nothing');
    has($r2['location'], '.lg.webp?v=');
    // the next render prints exactly the URL the browser was redirected to (downloaded once)
    $page = get('assets.php?client=kenda&view=library', 'admin')['body'];
    has($page, '/media/library/kenda/.thumbs/lazy-both.sm.webp?v=' . filemtime($f), 'same URL in the page');
});

test('generator cap: with every slot busy preview.php serves a no-store placeholder, never the original', function () use ($MEDIA) {
    $f = "$MEDIA/library/kenda/lazy-cap.jpg";
    copy(ipJpeg('lazy-cap', 2000, 1500), $f);
    $u = ipLazy('media/library/kenda/lazy-cap.jpg', 'sm');
    $hs = ipHoldSlots();
    try {
        $t0 = microtime(true);
        $r = ipGet($u);
        ok(microtime(true) - $t0 < 2.0, 'answers at once (no waiting for a slot)');
        is($r['code'], 200, 'placeholder 200');
        has($r['headers']['content-type'] ?? '', 'image/svg+xml');
        is($r['headers']['cache-control'] ?? '', 'no-store');
        is($r['headers']['x-preview'] ?? '', 'pending');
        is($r['headers']['x-preview-gd'] ?? '', '0');
        has($r['body'], 'width="1" height="1"', '1×1 (the retry marker)');
        is($r['location'], '', 'no redirect');
        ok(!is_file("$MEDIA/library/kenda/.thumbs/lazy-cap.sm.webp"), 'nothing made');
    } finally { ipRelease($hs); }
    $r = ipGet($u . '&r=1');
    is($r['code'], 302, 'after the slots free up: made');
    has($r['location'], '/.thumbs/lazy-cap.sm.webp?v=');
});

test('a preview that cannot be made → failed placeholder (logged, remembered), never a redirect to the original', function () use ($MEDIA) {
    $im = imagecreatetruecolor(2000, 1500); ob_start(); imagepng($im); $png = (string)ob_get_clean();
    file_put_contents("$MEDIA/library/kenda/broken.png", substr($png, 0, 33) . str_repeat("\x00junk", 400));   // valid header, garbage pixels
    $u = ipLazy('media/library/kenda/broken.png', 'sm');
    $r = ipGet($u);
    is($r['code'], 200, 'placeholder');
    is($r['headers']['x-preview'] ?? '', 'failed');
    has($r['body'], 'width="2" height="2"');
    is($r['location'], '', 'no redirect to the original');
    ok(is_file("$MEDIA/library/kenda/.thumbs/broken.fail.json"), 'failure remembered');
    $r = ipGet($u . '&r=2');
    is($r['headers']['x-preview-gd'] ?? '', '0', 'no second decode attempt');
    is($r['headers']['x-preview'] ?? '', 'failed');
});

test('lg for a small-but-heavy original (≤ 1600 px, > 500 KB); none for a light one', function () use ($MEDIA) {
    $heavy = "$MEDIA/library/kenda/heavy-1200.jpg";
    copy(ipJpeg('heavy', 1200, 900, true, 100), $heavy);
    clearstatcache();
    ok(filesize($heavy) > 500 * 1024, 'fixture is heavy: ' . filesize($heavy));
    $r = ipGet(ipLazy('media/library/kenda/heavy-1200.jpg', 'lg'));
    is($r['code'], 302);
    has($r['location'], '/.thumbs/heavy-1200.lg.webp?v=');
    $lg = "$MEDIA/library/kenda/.thumbs/heavy-1200.lg.webp";
    is(array_slice(ipWebpDims($lg), 0, 2), [1200, 900], 'same size, re-encoded');
    ok(filesize($lg) < filesize($heavy), 'smaller than the original');
    $light = "$MEDIA/library/kenda/light-1200.jpg";
    copy(ipJpeg('light', 1200, 900), $light);
    is(ipApp('echo previewNeeds(' . var_export($light, true) . ', "lg") ? "yes" : "no";'), 'no', 'a light 1200 px original is its own lg');
    is(ipApp('echo previewNeeds(' . var_export($MEDIA . '/library/kenda/heavy-1200.jpg', true) . ', "lg") ? "yes" : "no";'), 'yes');
});

test('the session is released before GD work (and not reopened)', function () use ($MEDIA, $APP) {
    $f = "$MEDIA/library/kenda/sess.jpg";
    copy(ipJpeg('sess', 2000, 1500), $f);
    $out = ipApp('session_save_path(sys_get_temp_dir()); @session_start(); $_SESSION["x"] = 1; $before = session_status();'
        . '$st = ""; previewGenerate(' . var_export($f, true) . ', ["sm", "lg"], [], $st);'
        . 'echo json_encode([$before, session_status(), $st, !empty($GLOBALS["__jsmSessionReleased"]), $_SESSION["x"] ?? null]);');
    is(json_decode($out, true), [PHP_SESSION_ACTIVE, PHP_SESSION_NONE, 'ok', true, 1], 'active → released, data still readable: ' . $out);
    // every upload / save handler releases it before its preview work
    foreach (['upload-chunk.php', 'tire-upload.php', 'batch-process.php', 'post-compose.php', 'add-post.php', 'upload-lib.php', 'preview.php'] as $file) {
        has((string)file_get_contents("$APP/$file"), 'previewReleaseSession()', $file);
    }
    has((string)file_get_contents("$APP/auth.php"), '__jsmSessionReleased', 'auth.php does not reopen a released session');
});

// ---------------------------------------------------------------------------------------------------------------
// Server rules, page renders, the job
// ---------------------------------------------------------------------------------------------------------------
/** Every directive outside <IfModule> must be `Options -Indexes` (one unguarded line 500s the folder). */
function ipUnguarded(string $text): array {
    $depth = 0; $bad = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $l = trim($line);
        if ($l === '' || $l[0] === '#') continue;
        if (stripos($l, '<IfModule') === 0) { $depth++; continue; }
        if (stripos($l, '</IfModule>') === 0) { $depth--; continue; }
        if ($depth === 0 && $l !== 'Options -Indexes') $bad[] = $l;
    }
    if ($depth !== 0) $bad[] = 'unbalanced <IfModule>';
    return $bad;
}
test('.htaccess: library originals 7 days, library .thumbs 1 year immutable, every directive guarded', function () use ($MEDIA, $APP) {
    get('assets.php?client=kenda&view=library', 'admin');   // the library sync writes media/library/.htaccess
    ipGet(ipLazy('media/library/kenda/lib_01.jpg', 'sm'));
    $lib = "$MEDIA/library/.htaccess";
    ok(is_file($lib), 'media/library/.htaccess written');
    $t = (string)file_get_contents($lib);
    has($t, 'max-age=604800', '7-day originals');
    has($t, '# joust-portal-media v', 'marker (repair / upgrade owns it)');
    is(ipUnguarded($t), [], 'library rules guarded');
    $files = glob("$MEDIA/library/kenda/.thumbs/.htaccess");
    ok($files, 'library .thumbs rules');
    $tt = (string)file_get_contents($files[0]);
    has($tt, 'max-age=31536000, immutable');
    is(ipUnguarded($tt), [], 'thumbs rules guarded');
    foreach (glob("$APP/uploads/{.locks,.thumbs}/.htaccess", GLOB_BRACE) ?: [] as $f) is(ipUnguarded((string)file_get_contents($f)), [], $f);
    foreach (['mediaHtaccessText()', 'mediaThumbsHtaccessText()'] as $fn) is(ipUnguarded(ipApp('echo ' . $fn . ';')), [], $fn);
    // Repair server rules rewrites it when removed
    unlink($lib);
    $r = status(post('tire-upload.php?client=kenda', ['action' => 'repair_media', 'client' => 'kenda']), 200);
    ok(is_file($lib), 'repair rewrote media/library/.htaccess');
    has((string)($r['json']['summary'] ?? ''), 'library rules');
});

test('the collections render builds no previews (an FTP drop gets a lazy URL instead)', function () use ($MEDIA) {
    $dir = "$MEDIA/tires/klever-at2/Series 1";
    copy(ipJpeg('ftp-drop', 2000, 1500), "$dir/ftp_drop.jpg");
    touch($dir, time() + 5);   // folder signature changes → the throttled sync scans
    $r = status(get('assets.php?client=kenda&view=collections&item=1&series=1', 'admin'), 200);
    ok(!is_file("$dir/.thumbs/ftp_drop.sm.webp"), 'no preview made during the render');
    ok((int)q1("SELECT COUNT(*) FROM tire_images WHERE image_url LIKE '%ftp_drop.jpg'") === 1, 'row registered');
    ok(preg_match('#preview\.php\?f=[^"]+#', $r['body']) === 1, 'tiles point at the lazy endpoint');
    ok(preg_match('#\ssrc="[^"]*ftp_drop\.jpg"#', $r['body']) === 0, 'no <img src> is the original');
});

test('Build previews job: several per step, done / remaining, includes library FTP drops, backs off when the cap is busy', function () use ($MEDIA) {
    copy(ipJpeg('job-drop', 2000, 1500), "$MEDIA/library/kenda/job_drop.jpg");   // dropped by FTP: no row, no preview yet
    $r = status(post('preview-job.php?client=kenda', ['action' => 'start', 'scope' => 'client']), 200);
    $total = (int)$r['json']['job']['total'];
    is((int)$r['json']['job']['remaining'], $total, 'remaining = total at start');
    $hs = ipHoldSlots();
    try {
        // the first items are the fixture references (800 px light → only sm): they need work, so the step backs off
        $r = status(post('preview-job.php?client=kenda', ['action' => 'step', 'scope' => 'client']), 200);
        is($r['json']['busy'] ?? null, true, 'busy when no slot frees up');
    } finally { ipRelease($hs); }
    $seen = 0;
    for ($i = 0; $i < 40; $i++) {
        $r = status(post('preview-job.php?client=kenda', ['action' => 'step', 'scope' => 'client']), 200);
        $seen = max($seen, (int)($r['json']['step_items'] ?? 0));
        if (!empty($r['json']['job']['finished'])) break;
    }
    ok(!empty($r['json']['job']['finished']), 'finished');
    is((int)$r['json']['job']['remaining'], 0);
    ok($seen > 1, 'several images per step');
    ok(is_file("$MEDIA/library/kenda/.thumbs/job_drop.sm.webp") && is_file("$MEDIA/library/kenda/.thumbs/job_drop.lg.webp"), 'the FTP drop got both previews');
});

finish();
