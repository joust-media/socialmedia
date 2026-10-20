<?php
/**
 * Agent team intake (agent-intake.php; migrate.php 55) — the machine endpoint Lance's agent team delivers Drafts through:
 *   - auth: no / wrong secret → 401, Bearer or the X-Agent-Secret fallback → 200, no secret in config.php (or a short
 *     one) → 503 "not configured"; never a session (no Set-Cookie, a browser's admin cookie changes nothing); 405 / 400
 *   - the client list (every company, slug + name)
 *   - a chunked upload: begin → pieces (a wrong offset is a 409 with `received`, so the client resumes) → finish (size,
 *     sha256 and the real type checked; a sha mismatch or a PNG declared as JPEG is refused and discarded; a retried
 *     finish gets the same token); the spool behind its deny-all .htaccess
 *   - a post: ONE draft with a 3-image carousel in the posted order, auto type (one video → reel), the agent-team
 *     activity row and the internal note (Joust-only rows), nothing for the client / Slack / client emails, the note
 *     never visible to the client seat (also after Send for review), the draft 404 for the client seat
 *   - idempotency on (client, source_ref): a repeat changes nothing; refs compare byte for byte and per client
 *   - status read-back: draft → pending → approved / denied (+ the client's latest note, never an internal one),
 *     posted / trashed, unknown and other-client ids left out
 *   - isolation between clients, size limits (1 MB JSON, pieces, per-file caps, 20 media), not migrated → 503
 *   - migrate.php 55: fresh, partial, re-run; the router / clean links never capture the endpoint
 * Every test starts from the seed.
 */
require __DIR__ . '/lib.php';

const AI_SECRET = 'test-agent-intake-secret-0123456789abcdef';
const J = ['Accept' => 'application/json'];
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");
date_default_timezone_set('America/New_York');

$ROOT  = rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test'), '/');
$APP   = rtrim((string)(getenv('APP_DIR') ?: $ROOT . '/site/portal'), '/');
$MEDIA = rtrim((string)(getenv('MEDIA_DIR') ?: $ROOT . '/site/media'), '/');

function reseedNow(): void {
    global $APP, $MEDIA;
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($APP) . ' ' . escapeshellarg($MEDIA) . ' 2>&1', $out, $rc);
    if ($rc !== 0) throw new RuntimeException('seed failed: ' . implode("\n", $out));
}
function atest(string $name, callable $fn): void { test($name, static function () use ($fn) { reseedNow(); $fn(); }); }

/**
 * One request to agent-intake.php (or $path). $body: array → JSON, string → raw bytes. $auth: 'bearer' | 'header' |
 * 'none' | any other string = that Bearer value. Returns lib.php's req() shape.
 */
function ai(string $method, string $query, $body = null, string $auth = 'bearer', array $headers = [], string $path = 'agent-intake.php'): array {
    $url = base() . '/' . $path . ($query !== '' ? '?' . $query : '');
    $h = [];
    if ($auth === 'bearer') $h[] = 'Authorization: Bearer ' . AI_SECRET;
    elseif ($auth === 'header') $h[] = 'X-Agent-Secret: ' . AI_SECRET;
    elseif ($auth !== 'none') $h[] = 'Authorization: Bearer ' . $auth;
    $raw = null;
    if (is_array($body)) { $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
    elseif (is_string($body)) { $raw = $body; if (!array_key_exists('content-type', array_change_key_case($headers))) $h[] = 'Content-Type: application/octet-stream'; }
    foreach ($headers as $k => $v) $h[] = is_int($k) ? $v : "$k: $v";
    $hdrs = []; $cookies = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'agent-smoke/1', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$hdrs, &$cookies) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $n = strtolower(trim(substr($line, 0, $p)));
                $hdrs[$n] = trim(substr($line, $p + 1));
                if ($n === 'set-cookie') $cookies[] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    if ($raw !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
    $out = curl_exec($ch);
    if ($out === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException("request failed: $method $url — $e"); }
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode((string)$out, true);
    return ['code' => $code, 'body' => (string)$out, 'headers' => $hdrs, 'json' => is_array($json) ? $json : null, 'location' => $hdrs['location'] ?? '', 'cookies' => $cookies];
}
/** HTTP code + JSON shape check in one go. */
function aiIs(array $r, int $code, string $msg = ''): array {
    if ($r['code'] !== $code) fail(($msg !== '' ? $msg . ': ' : '') . "HTTP {$r['code']} (want {$code}) — " . substr($r['body'], 0, 300));
    ok(is_array($r['json']), ($msg !== '' ? $msg . ': ' : '') . 'reply is JSON: ' . substr($r['body'], 0, 200));
    is($r['json']['ok'] ?? null, $code === 200, ($msg !== '' ? $msg . ': ' : '') . 'ok flag');
    ok(stripos((string)($r['headers']['content-type'] ?? ''), 'application/json') === 0, 'JSON content type');
    return $r;
}

/** A PNG of random noise (incompressible: ~3 bytes a pixel) — big enough to need several pieces. */
function noisePng(int $w = 160, int $h = 160, int $seed = 1): string {
    mt_srand($seed);
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) imagesetpixel($im, $x, $y, mt_rand(0, 0xFFFFFF));
    ob_start(); imagepng($im); $b = (string)ob_get_clean();
    imagedestroy($im);
    return $b;
}
function smallJpeg(string $label = 'x'): string { return (string)file_get_contents(tmpImage($label, 120, 90)); }
/** Bytes the portal's container sniff accepts as MP4 (ftyp isom). */
function tinyMp4(): string { return pack('N', 24) . 'ftyp' . 'isom' . pack('N', 512) . 'isom' . 'mp41' . pack('N', 8 + 1024) . 'mdat' . str_repeat("\0", 1024); }

function begin(string $client, string $bytes, string $mime = 'image/png', string $name = 'slide.png', ?string $sha = null): array {
    return ai('POST', 'part=media_begin', ['client' => $client, 'filename' => $name, 'mime' => $mime, 'bytes' => strlen($bytes), 'sha256' => $sha ?? hash('sha256', $bytes)]);
}
function chunk(string $id, int $offset, string $bytes, array $headers = []): array {
    return ai('POST', 'part=media_chunk&upload_id=' . $id . '&offset=' . $offset, $bytes, 'bearer', $headers);
}
function finishUpload(string $id): array { return ai('POST', 'part=media_finish', ['upload_id' => $id]); }
/** The whole upload (in pieces of $piece bytes) → the media token. */
function upload(string $client, string $bytes, string $mime = 'image/png', string $name = 'slide.png', int $piece = 0): string {
    $b = aiIs(begin($client, $bytes, $mime, $name), 200, 'begin')['json'];
    $piece = $piece > 0 ? $piece : (int)$b['chunk_bytes'];
    for ($o = 0; $o < strlen($bytes); $o += $piece) aiIs(chunk($b['upload_id'], $o, substr($bytes, $o, $piece)), 200, 'piece at ' . $o);
    $f = aiIs(finishUpload($b['upload_id']), 200, 'finish')['json'];
    ok(preg_match('/^[a-f0-9]{32}$/', (string)$f['media_token']) === 1, 'media token');
    return (string)$f['media_token'];
}
function makePost(array $fields): array { return ai('POST', 'part=post', $fields); }
function statusOf(string $client, string $q): array { return aiIs(ai('GET', 'part=status&client=' . $client . '&' . $q), 200, 'status')['json']['posts']; }
function byId(array $posts): array { $o = []; foreach ($posts as $p) $o[(int)$p['id']] = $p; return $o; }
/** A draft for kenda with $n noise-PNG slides; returns [post_id, the slide bytes in order]. */
function quickPost(string $ref, int $n = 1, array $extra = [], string $client = 'kenda'): array {
    $files = []; $tokens = [];
    for ($i = 0; $i < $n; $i++) { $files[] = noisePng(40, 40, crc32($ref) + $i); $tokens[] = upload($client, $files[$i]); }
    $r = aiIs(makePost($extra + ['client' => $client, 'caption' => 'Caption for ' . $ref, 'hashtags' => '#kenda #tires', 'scheduled_date' => '2026-11-05',
                                  'media_tokens' => $tokens, 'source_ref' => $ref]), 200, 'post ' . $ref);
    return [(int)$r['json']['post_id'], $files];
}
function appPhp(string $code): string {
    global $APP;
    $file = sys_get_temp_dir() . '/agent_smoke_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/portal/index.php'; \$_SERVER['HTTP_HOST'] = '127.0.0.1';\n"
        . "chdir(" . var_export($APP, true) . ");\nrequire 'db.php'; require_once 'url-lib.php';\n" . $code . "\n");
    $out = (string)shell_exec('php ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return $out;
}
/** route.php as Apache would reach it for $uri (CLI): "<http code>|<start of the output>". */
function routeProbe(string $uri, string $method = 'GET'): string {
    global $APP;
    $file = sys_get_temp_dir() . '/agent_route_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/portal/route.php'; \$_SERVER['REQUEST_URI'] = " . var_export($uri, true) . ";\n"
        . "\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . "; \$_SERVER['HTTP_HOST'] = '127.0.0.1';\n"
        . "chdir(" . var_export($APP, true) . ");\nob_start();\n"
        . "register_shutdown_function(static function () { \$b = (string)ob_get_clean(); echo (int)http_response_code(), '|', substr(strip_tags(\$b), 0, 60); });\n"
        . "require 'route.php';\n");
    $out = (string)shell_exec('php ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return trim($out);
}
function runMigrate(): string {
    global $APP;
    return (string)shell_exec('cd ' . escapeshellarg($APP) . ' && PORTAL_TEST=1 PORTAL_TEST_ROLE=admin php -d auto_prepend_file=' . escapeshellarg(dirname(__DIR__) . '/test-auth.php') . ' migrate.php 2>&1');
}
function hasKey(): bool { return (int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'ux_posts_source_ref'") > 0; }
function hasCol(): bool { return (int)q1("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'source_ref'") > 0; }
/** Run $fn with config.php's agent_intake_secret replaced by $value (restored afterwards). */
function withSecret(string $value, callable $fn): void {
    global $APP;
    $file = $APP . '/config.php';
    $orig = (string)file_get_contents($file);
    $line = "'agent_intake_secret'   => '" . AI_SECRET . "',";
    ok(strpos($orig, $line) !== false, 'the test config carries agent_intake_secret (tests/env.sh)');
    file_put_contents($file, str_replace($line, "'agent_intake_secret'   => " . var_export($value, true) . ',', $orig));
    sleep(3);   // php -S + opcache: config.php is re-read once its timestamp is revalidated
    try { $fn(); } finally { file_put_contents($file, $orig); sleep(3); }
}

// =====================================================================================================================
// Auth
// =====================================================================================================================
atest('auth: none / wrong → 401, Bearer and the X-Agent-Secret fallback → 200, health says configured + migrated', function () {
    $r = aiIs(ai('GET', 'health=1', null, 'none'), 401, 'no secret');
    is($r['json']['error'], 'unauthorized');
    aiIs(ai('GET', 'health=1', null, 'wrong-secret-0123456789abcdef-xyz'), 401, 'wrong secret');
    aiIs(ai('GET', 'health=1', null, substr(AI_SECRET, 0, -1)), 401, 'a prefix of the secret');
    aiIs(ai('GET', 'health=1', null, 'none', ['Authorization' => 'Basic ' . base64_encode('x:' . AI_SECRET)]), 401, 'not a Bearer');
    aiIs(ai('GET', 'part=status&client=kenda&ids=1', null, 'none'), 401, 'every part needs it');
    aiIs(ai('POST', 'part=post', ['client' => 'kenda'], 'none'), 401, 'POST too');
    $h = aiIs(ai('GET', 'health=1'), 200, 'Bearer')['json'];
    is($h['configured'], true);
    is($h['migrated'], true);
    ok($h['chunk_bytes'] > 0 && $h['chunk_bytes'] <= 4 * 1024 * 1024, 'chunk_bytes ≤ 4 MiB: ' . $h['chunk_bytes']);
    is($h['max_media'], 20);
    is($h['mimes'], ['image/png', 'image/jpeg', 'image/webp', 'video/mp4']);
    aiIs(ai('GET', 'health=1', null, 'header'), 200, 'X-Agent-Secret (hosts that strip Authorization)');
    aiIs(ai('GET', 'health=1', null, 'garbage-bearer-0123456789-abcdef', ['X-Agent-Secret' => AI_SECRET]), 200, 'either header may carry it');
    aiIs(ai('GET', 'part=health'), 200, '?part=health works too');
});

atest('auth: no secret configured (or a short one) → 503 "not configured", whatever is presented', function () {
    withSecret('', function () {
        $r = aiIs(ai('GET', 'health=1'), 503, 'blank');
        is($r['json']['error'], 'not configured');
        aiIs(ai('GET', 'health=1', null, 'none'), 503, 'blank, nothing presented');
        aiIs(ai('POST', 'part=post', ['client' => 'kenda']), 503, 'blank, POST');
    });
    withSecret('short-secret-123', function () {
        aiIs(ai('GET', 'health=1', null, 'short-secret-123'), 503, 'shorter than 24 characters');
    });
    aiIs(ai('GET', 'health=1'), 200, 'restored');
});

atest('no session, no CSRF: no Set-Cookie, a browser admin cookie gives nothing; 405 / 400 for the wrong method or part', function () {
    $r = aiIs(ai('GET', 'clients=1'), 200);
    is($r['cookies'], [], 'no cookie set');
    // a signed-in admin's browser cookie never turns a request into an admin one (and never needs the secret less)
    $adm = get('manage.php', 'admin');
    $sess = '';
    foreach ($adm['cookies'] as $c) if (preg_match('/^(jsm_admin=[^;]+)/', $c, $m)) $sess = $m[1];
    aiIs(ai('GET', 'clients=1', null, 'none', $sess !== '' ? ['Cookie' => $sess] : []), 401, 'cookie without the secret');
    $r = aiIs(ai('GET', 'clients=1', null, 'bearer', $sess !== '' ? ['Cookie' => $sess] : []), 200, 'cookie + secret');
    is($r['cookies'], [], 'still no cookie');
    aiIs(ai('PUT', 'health=1'), 405, 'PUT');
    aiIs(ai('DELETE', 'part=post'), 405, 'DELETE');
    aiIs(ai('GET', 'part=post'), 405, 'post is POST');
    aiIs(ai('POST', 'health=1', ['x' => 1]), 405, 'health is GET');
    aiIs(ai('POST', 'part=status&client=kenda&ids=1', ['x' => 1]), 405, 'status is GET');
    aiIs(ai('GET', 'part=media_begin'), 405, 'media_begin is POST');
    $r = aiIs(ai('GET', 'part=bogus'), 400, 'unknown part');
    is($r['json']['error'], 'unknown part');
    aiIs(ai('GET', ''), 400, 'no part');
    // a cross-site browser POST is not a thing here: the secret is the only gate (no Origin / Sec-Fetch check)
    aiIs(ai('POST', 'part=media_begin', ['client' => 'kenda', 'filename' => 'a.png', 'mime' => 'image/png', 'bytes' => 10, 'sha256' => str_repeat('a', 64)],
            'bearer', ['Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'cross-site']), 200, 'machine auth only');
});

// =====================================================================================================================
// Clients
// =====================================================================================================================
atest('clients: every company as {slug, name}, by name', function () {
    $c = aiIs(ai('GET', 'clients=1'), 200)['json']['clients'];
    is($c, [['slug' => 'hmf', 'name' => 'Hollow Mill Farm'], ['slug' => 'kenda', 'name' => 'Kenda Tires'], ['slug' => 'privacybee', 'name' => 'Privacy Bee']]);
    aiIs(ai('GET', 'part=clients'), 200, '?part=clients works too');
});

// =====================================================================================================================
// Chunked upload
// =====================================================================================================================
atest('upload: begin → pieces (a wrong offset is a 409 with received) → finish; spool behind a deny-all .htaccess; a retried finish', function () {
    global $APP;
    $png = noisePng(160, 160, 7);
    $n = strlen($png);
    ok($n > 60000, 'the noise PNG is big: ' . $n);
    $b = aiIs(begin('kenda', $png, 'image/png', 'hero shot.png'), 200, 'begin')['json'];
    ok(preg_match('/^[a-f0-9]{32}$/', (string)$b['upload_id']) === 1, 'upload_id');
    is($b['received'], 0);
    ok($b['chunk_bytes'] >= 256 * 1024 && $b['chunk_bytes'] <= 4 * 1024 * 1024, 'chunk_bytes');
    $id = $b['upload_id'];
    $spool = $APP . '/uploads/.spool';
    ok(is_file("$spool/$id.part") && is_file("$spool/$id.json"), 'spool part + sidecar');
    has((string)file_get_contents("$spool/.htaccess"), 'Require all denied', 'the spool is never served');
    is(substr(sprintf('%o', fileperms("$spool/$id.part")), -3), '600', 'part file 0600');
    $p = intdiv($n, 3) + 1;
    is(aiIs(chunk($id, 0, substr($png, 0, $p)), 200, 'piece 1')['json']['received'], $p);
    // the same piece again (a retry after a lost reply) and an offset from nowhere: 409 + where the server is
    $r = aiIs(chunk($id, 0, substr($png, 0, $p)), 409, 'repeat of piece 1');
    is($r['json']['received'], $p, '409 says where to resume');
    $r = aiIs(chunk($id, $p + 5, substr($png, $p + 5, $p)), 409, 'a gap');
    is($r['json']['received'], $p);
    $r = aiIs(chunk($id, 3, substr($png, 3, $p)), 409, 'an overlap');
    is($r['json']['received'], $p);
    // finish before the last piece: 409 with the count
    $r = aiIs(finishUpload($id), 409, 'incomplete');
    is($r['json']['received'], $p);
    is(aiIs(chunk($id, $p, substr($png, $p, $p)), 200, 'piece 2')['json']['received'], 2 * $p);
    is(aiIs(chunk($id, 2 * $p, substr($png, 2 * $p)), 200, 'piece 3')['json']['received'], $n);
    $f = aiIs(finishUpload($id), 200, 'finish')['json'];
    $tok = (string)$f['media_token'];
    ok(preg_match('/^[a-f0-9]{32}$/', $tok) === 1, 'media token');
    is($f['bytes'], $n);
    is($f['mime'], 'image/png');
    is($f['type'], 'image');
    $parked = $APP . "/uploads/tmp_{$tok}.png";
    ok(is_file($parked), 'parked as uploads/tmp_<token>.png');
    is(hash_file('sha256', $parked), hash('sha256', $png), 'byte for byte');
    ok(!is_file("$spool/$id.part"), 'the part file became the parked file');
    $claim = json_decode((string)file_get_contents("$spool/$tok.claim"), true);
    is($claim['purpose'], 'agent');
    is($claim['client'], 'kenda');
    is($claim['name'], 'hero shot.png');
    // previews are made right away for an image big enough to need them (they follow the file into the post)
    $big = (string)file_get_contents(tmpImage('preview me', 900, 600));
    $btok = upload('kenda', $big, 'image/jpeg', 'big.jpg');
    ok(is_file($APP . "/uploads/.thumbs/tmp_{$btok}.sm.webp") || is_file($APP . "/uploads/.thumbs/tmp_{$btok}.sm.jpg"), 'sm preview of the parked file');
    // a retried finish: the same token
    $again = aiIs(finishUpload($id), 200, 'finish again')['json'];
    is($again['media_token'], $tok);
    is($again['duplicate'], true);
    // the upload is done: no more pieces
    aiIs(chunk($id, $n, 'x'), 404, 'a piece after finish');
    // bad ids
    aiIs(chunk('nothex', 0, 'x'), 400, 'invalid upload_id');
    aiIs(chunk(str_repeat('a', 32), 0, 'x'), 404, 'unknown upload_id');
    aiIs(finishUpload(str_repeat('b', 32)), 404, 'unknown upload_id on finish');
    $live = aiIs(begin('kenda', $png), 200)['json']['upload_id'];
    aiIs(ai('POST', 'part=media_chunk&upload_id=' . $live, 'x'), 400, 'offset required');
    aiIs(ai('POST', 'part=media_chunk&upload_id=' . $live . '&offset=-1', 'x'), 400, 'negative offset');
});

atest('upload: a sha256 mismatch or a file that is not what its mime says is refused and discarded; begin validates', function () {
    global $APP;
    $png = noisePng(60, 60, 3);
    $b = aiIs(begin('kenda', $png, 'image/png', 'a.png', hash('sha256', 'something else')), 200)['json'];
    aiIs(chunk($b['upload_id'], 0, $png), 200);
    $r = aiIs(finishUpload($b['upload_id']), 400, 'sha mismatch');
    has($r['json']['error'], 'sha256 mismatch');
    ok(!is_file($APP . '/uploads/.spool/' . $b['upload_id'] . '.part'), 'discarded');
    aiIs(chunk($b['upload_id'], 0, $png), 404, 'gone: begin again');
    // a PNG declared as JPEG: the real type decides
    $b = aiIs(begin('kenda', $png, 'image/jpeg', 'a.jpg'), 200)['json'];
    aiIs(chunk($b['upload_id'], 0, $png), 200);
    $r = aiIs(finishUpload($b['upload_id']), 400, 'PNG declared as JPEG');
    has($r['json']['error'], 'not a valid image/jpeg');
    // not an image at all
    $php = "<?php echo 'hi'; ?>" . str_repeat(' ', 100);
    $b = aiIs(begin('kenda', $php, 'image/png', 'evil.png'), 200)['json'];
    aiIs(chunk($b['upload_id'], 0, $php), 200);
    aiIs(finishUpload($b['upload_id']), 400, 'PHP named .png');
    ok(!glob($APP . '/uploads/tmp_*.php'), 'nothing executable parked');
    // a JPEG, a WebP and an MP4 pass
    $jpg = smallJpeg('jpg');
    upload('kenda', $jpg, 'image/jpeg', 'photo.jpeg');
    if (function_exists('imagewebp')) { $im = imagecreatetruecolor(50, 40); ob_start(); imagewebp($im); upload('kenda', (string)ob_get_clean(), 'image/webp', 'w.webp'); }
    upload('kenda', tinyMp4(), 'video/mp4', 'clip.mp4');
    // begin validation
    $ok = ['client' => 'kenda', 'filename' => 'a.png', 'mime' => 'image/png', 'bytes' => 100, 'sha256' => str_repeat('c', 64)];
    aiIs(ai('POST', 'part=media_begin', ['client' => 'nobody'] + $ok), 404, 'unknown client');
    aiIs(ai('POST', 'part=media_begin', array_diff_key($ok, ['client' => 1])), 400, 'no client');
    aiIs(ai('POST', 'part=media_begin', ['mime' => 'image/gif'] + $ok), 400, 'gif');
    aiIs(ai('POST', 'part=media_begin', ['mime' => 'video/quicktime'] + $ok), 400, 'mov');
    aiIs(ai('POST', 'part=media_begin', ['mime' => 'text/html'] + $ok), 400, 'html');
    aiIs(ai('POST', 'part=media_begin', ['bytes' => 0] + $ok), 400, 'zero bytes');
    aiIs(ai('POST', 'part=media_begin', ['bytes' => -5] + $ok), 400, 'negative bytes');
    aiIs(ai('POST', 'part=media_begin', ['bytes' => 'lots'] + $ok), 400, 'bytes not a number');
    aiIs(ai('POST', 'part=media_begin', ['sha256' => 'abc'] + $ok), 400, 'bad sha256');
    aiIs(ai('POST', 'part=media_begin', ['filename' => ''] + $ok), 400, 'no filename');
    aiIs(ai('POST', 'part=media_begin', '[1,2,3]', 'bearer', ['Content-Type' => 'application/json']), 400, 'a JSON list');
    aiIs(ai('POST', 'part=media_begin', 'not json', 'bearer', ['Content-Type' => 'application/json']), 400, 'not JSON');
    // a piece must be the raw body
    $b = aiIs(begin('kenda', $png), 200)['json'];
    aiIs(chunk($b['upload_id'], 0, 'a=1&b=2', ['Content-Type' => 'application/x-www-form-urlencoded']), 400, 'form-encoded piece');
    aiIs(chunk($b['upload_id'], 0, ''), 400, 'empty piece');
});

// =====================================================================================================================
// Posts
// =====================================================================================================================
atest('post: one Draft with a 3-image carousel in the posted order, the agent-team row + the internal note; nothing announced', function () {
    global $APP;
    $files = [(string)file_get_contents(tmpImage('slide A', 900, 600)), (string)file_get_contents(tmpImage('slide B', 900, 600)), noisePng(50, 50, 103)];
    $tokens = [];
    foreach ($files as $i => $f) $tokens[] = upload('kenda', $f, $i < 2 ? 'image/jpeg' : 'image/png', "slide-{$i}", 2000);   // small pieces
    $order = [$tokens[2], 'claim:' . $tokens[0], $tokens[1]];   // the carousel order the agent wants (claim: prefix accepted)
    $before = (int)q1('SELECT COUNT(*) FROM posts');
    $r = aiIs(makePost(['client' => 'kenda', 'caption' => "Fall is here.\nNew Klever AT2 🍂", 'hashtags' => ['kenda', '#KleverAT2', ' '],
                        'scheduled_date' => '2026-11-05', 'media_tokens' => $order, 'source_ref' => 'kenda/2026-11-05/fall-01',
                        'note' => 'AGENT-NOTE: built from the fall brief, slide 2 is the hero']), 200, 'post')['json'];
    $id = (int)$r['post_id'];
    is($r['status'], 'draft');
    is($r['media'], 3);
    is($r['admin_url'], base() . '/posts.php?client=kenda&post=' . $id, 'absolute admin URL');
    ok(!isset($r['duplicate']), 'not a duplicate');
    is((int)q1('SELECT COUNT(*) FROM posts'), $before + 1, 'exactly one post');
    $p = rows('SELECT * FROM posts WHERE id = ?', [$id])[0];
    is($p['company_id'], 1);
    is($p['status'], 'draft');
    is($p['caption'], "Fall is here.\nNew Klever AT2 🍂");
    is($p['hashtags'], '#kenda #KleverAT2');
    is($p['scheduled_date'], '2026-11-05 10:00:00');
    is($p['post_type'], 'post', 'auto: images → post');
    is($p['source_ref'], 'kenda/2026-11-05/fall-01');
    is((int)$p['posted'], 0);
    $imgs = rows('SELECT image_url, media_type, sort_order FROM post_images WHERE post_id = ? ORDER BY sort_order', [$id]);
    is(count($imgs), 3);
    foreach ([2, 0, 1] as $k => $src) {
        is((int)$imgs[$k]['sort_order'], $k + 1);
        is($imgs[$k]['media_type'], 'image');
        ok(preg_match('#^uploads/img_[A-Za-z0-9_.-]+\.' . ($src < 2 ? 'jpg' : 'png') . '$#', $imgs[$k]['image_url']) === 1, 'final img_ name: ' . $imgs[$k]['image_url']);
        is(hash_file('sha256', $APP . '/' . $imgs[$k]['image_url']), hash('sha256', $files[$src]), "slide " . ($k + 1) . " = upload {$src}");
    }
    foreach ($tokens as $t) ok(!glob($APP . "/uploads/tmp_{$t}.*") && !is_file($APP . "/uploads/.spool/{$t}.claim"), 'claims taken');
    $stem = pathinfo($imgs[1]['image_url'], PATHINFO_FILENAME);   // slide 2 = upload 0, a 900 × 600 JPEG
    ok(is_file($APP . "/uploads/.thumbs/{$stem}.sm.webp") || is_file($APP . "/uploads/.thumbs/{$stem}.sm.jpg"), 'previews came along');
    // the activity: Joust-only rows saying where it came from, the note as an internal comment
    $acts = rows("SELECT action, actor, internal, author_user_id, summary, detail FROM activity_log WHERE entity_type = 'post' AND entity_id = ? ORDER BY id", [$id]);
    is(count($acts), 2);
    is([$acts[0]['action'], $acts[0]['actor'], (int)$acts[0]['internal']], ['drafted', 'admin', 1]);
    has($acts[0]['summary'], 'Agent team');
    has($acts[0]['detail'], 'from the agent team');
    has($acts[0]['detail'], 'kenda/2026-11-05/fall-01');
    is([$acts[1]['action'], $acts[1]['actor'], (int)$acts[1]['internal'], $acts[1]['detail']], ['commented', 'admin', 1, 'AGENT-NOTE: built from the fall brief, slide 2 is the hero']);
    is($acts[0]['author_user_id'], null);
    // nothing announced: no outbox row (Slack internal note, client email), no client email queue, after a cron run too
    usleep(300000);
    get('notify-cron.php', 'anon', ['X-Notify-Token' => 'test-cron-token-0123456789abcdef']);
    is((int)q1("SELECT COUNT(*) FROM notify_outbox WHERE entity_type = 'post' AND entity_id = ?", [$id]), 0, 'no outbox row');
    is((int)q1("SELECT COUNT(*) FROM client_email_queue WHERE entity_type = 'post' AND entity_id = ?", [$id]), 0, 'no client email');
    is((int)q1("SELECT COUNT(*) FROM notify_threads WHERE entity_type = 'post' AND entity_id = ?", [$id]), 0, 'no Slack thread');
    // the admin sees it as a Draft, with the note
    $adm = status(get("posts.php?client=kenda&post={$id}&partial=1", 'admin'), 200)['body'];
    has($adm, 'AGENT-NOTE: built from the fall brief');
    has($adm, 'data-internal-pill');
    has(status(get('posts.php?client=kenda&status=draft&month=all', 'admin'), 200)['body'], 'Fall is here.', 'in Joust’s Drafts');
});

atest('post: the draft does not exist for the client seat (404), and the note never shows — also after Send for review', function () {
    [$id] = quickPost('kenda-hidden-1', 2, ['note' => 'SECRET-AGENT-NOTE do not show']);
    is(get("posts.php?client=kenda&post={$id}&partial=1", 'client')['code'], 404, 'deep link');
    is(post('status.php', ['id' => $id, 'comment' => 'hello?', 'client' => 'kenda'], 'client', [], J)['code'], 404, 'status.php');
    is(post('status.php', ['id' => $id, 'status' => 'approved', 'client' => 'kenda'], 'client', [], J)['code'], 404, 'approve');
    hasNot(status(get('posts.php?client=kenda&month=all', 'client'), 200)['body'], 'Caption for kenda-hidden-1', 'not in the list');
    hasNot(status(get('posts.php?client=kenda&status=all&month=all', 'client'), 200)['body'], 'Caption for kenda-hidden-1');
    $home = status(get('index.php?client=kenda', 'client'), 200)['body'];
    hasNot($home, 'Caption for kenda-hidden-1');
    hasNot($home, 'Agent team');
    // Lance sends it for review (the existing flow) → the client sees the post, never the note or the agent row
    $r = status(post('status.php', ['action' => 'submit', 'id' => $id, 'client' => 'kenda'], 'admin', [], J), 200, 'Send for review');
    is($r['json']['status'], 'pending');
    has(status(get('posts.php?client=kenda&month=all', 'client'), 200)['body'], 'Caption for kenda-hidden-1', 'now in the client’s list');
    $cl = status(get("posts.php?client=kenda&post={$id}&partial=1", 'client'), 200)['body'];
    has($cl, 'Caption for kenda-hidden-1');
    hasNot($cl, 'SECRET-AGENT-NOTE');
    hasNot($cl, 'agent team');
    hasNot($cl, 'data-internal');
    $home = status(get('index.php?client=kenda', 'client'), 200)['body'];
    hasNot($home, 'SECRET-AGENT-NOTE');
    hasNot($home, 'Agent team');
    has(status(get("posts.php?client=kenda&post={$id}&partial=1", 'admin'), 200)['body'], 'SECRET-AGENT-NOTE', 'Joust still sees it');
});

atest('post: idempotent on (client, source_ref) — a repeat changes nothing; refs are exact and per client', function () {
    [$id] = quickPost('Ref-A', 1);
    $posts = (int)q1('SELECT COUNT(*) FROM posts');
    $imgs = (int)q1('SELECT COUNT(*) FROM post_images');
    $acts = (int)q1('SELECT COUNT(*) FROM activity_log');
    // the very same delivery again (its token is spent) and one with different content: both just answer the post
    $tok = upload('kenda', noisePng(30, 30, 9));
    foreach ([['media_tokens' => [str_repeat('d', 32)]], ['media_tokens' => [$tok], 'caption' => 'changed', 'note' => 'again']] as $variant) {
        $r = aiIs(makePost($variant + ['client' => 'kenda', 'caption' => 'x', 'source_ref' => 'Ref-A']), 200, 'repeat')['json'];
        is($r['duplicate'], true);
        is((int)$r['post_id'], $id);
        is($r['status'], 'draft');
        is($r['admin_url'], base() . '/posts.php?client=kenda&post=' . $id);
    }
    is((int)q1('SELECT COUNT(*) FROM posts'), $posts, 'no new post');
    is((int)q1('SELECT COUNT(*) FROM post_images'), $imgs, 'no new media');
    is((int)q1('SELECT COUNT(*) FROM activity_log'), $acts, 'no new activity');
    is((string)q1('SELECT caption FROM posts WHERE id = ?', [$id]), 'Caption for Ref-A', 'unchanged');
    // the repeat did not spend the fresh token: it still makes a post under another ref
    $r = aiIs(makePost(['client' => 'kenda', 'caption' => 'y', 'media_tokens' => [$tok], 'source_ref' => 'Ref-B']), 200)['json'];
    ok((int)$r['post_id'] !== $id && empty($r['duplicate']), 'a new ref = a new post');
    // exact comparison (utf8mb4_bin): 'ref-a' is another ref; the same ref at another client is another post
    [$lower] = quickPost('ref-a', 1);
    ok($lower !== $id, 'case matters');
    [$other] = quickPost('Ref-A', 1, [], 'privacybee');
    ok($other !== $id, 'per client');
    is((int)q1('SELECT company_id FROM posts WHERE id = ?', [$other]), 2);
    // after a status change the repeat still answers the post, with its status
    db()->prepare("UPDATE posts SET status = 'approved' WHERE id = ?")->execute([$id]);
    is(aiIs(makePost(['client' => 'kenda', 'source_ref' => 'Ref-A', 'media_tokens' => []]), 200)['json']['status'], 'approved');
});

atest('post: validation (400), tokens of another client or spent ones, auto reel for one video, the scheduled date rules', function () {
    $tok = upload('kenda', noisePng(30, 30, 11));
    $base = ['client' => 'kenda', 'caption' => 'c', 'media_tokens' => [$tok], 'source_ref' => 'v-1'];
    aiIs(makePost(['client' => 'nobody'] + $base), 404, 'unknown client');
    aiIs(makePost(array_diff_key($base, ['source_ref' => 1])), 400, 'no source_ref');
    aiIs(makePost(['source_ref' => '   '] + $base), 400, 'blank source_ref');
    aiIs(makePost(['source_ref' => str_repeat('r', 191)] + $base), 400, 'source_ref > 190');
    aiIs(makePost(['source_ref' => "a\nb"] + $base), 400, 'control characters');
    aiIs(makePost(['media_tokens' => []] + $base), 400, 'no media');
    aiIs(makePost(['media_tokens' => 'abc'] + $base), 400, 'not a list');
    aiIs(makePost(['media_tokens' => ['nope']] + $base), 400, 'not a token');
    aiIs(makePost(['media_tokens' => [$tok, $tok]] + $base), 400, 'repeated token');
    aiIs(makePost(['media_tokens' => array_fill(0, 21, $tok)] + $base), 400, '21 media');
    aiIs(makePost(['media_tokens' => [str_repeat('e', 32)]] + $base), 400, 'unknown token');
    aiIs(makePost(['scheduled_date' => '2026-13-01'] + $base), 400, 'bad date');
    aiIs(makePost(['scheduled_date' => 'next tuesday'] + $base), 400, 'free-form date');
    aiIs(makePost(['post_type' => 'carousel'] + $base), 400, 'unknown type');
    aiIs(makePost(['caption' => ['x']] + $base), 400, 'caption not a string');
    aiIs(makePost(['caption' => str_repeat('c', 10001)] + $base), 400, 'caption too long');
    aiIs(makePost(['note' => str_repeat('n', 2001)] + $base), 400, 'note too long');
    is((int)q1("SELECT COUNT(*) FROM posts WHERE source_ref = 'v-1'"), 0, 'nothing saved');
    // a kenda token cannot go into a privacybee post (and is not spent by trying)
    $r = aiIs(makePost(['client' => 'privacybee'] + $base), 400, 'another client’s token');
    has($r['json']['error'], 'media_tokens[0]');
    $ok = aiIs(makePost($base), 200, 'the token still works for its own client')['json'];
    // spent
    aiIs(makePost(['source_ref' => 'v-2'] + $base), 400, 'a spent token');
    // one video → reel; null date → tomorrow 10:00; explicit type and time kept
    $vid = upload('kenda', tinyMp4(), 'video/mp4', 'clip.mp4');
    $r = aiIs(makePost(['client' => 'kenda', 'caption' => 'reel', 'media_tokens' => [$vid], 'source_ref' => 'v-3', 'scheduled_date' => null]), 200)['json'];
    $row = rows('SELECT post_type, scheduled_date FROM posts WHERE id = ?', [$r['post_id']])[0];
    is($row['post_type'], 'reel');
    is($row['scheduled_date'], date('Y-m-d 10:00:00', strtotime('+1 day')));
    is((string)q1('SELECT media_type FROM post_images WHERE post_id = ?', [$r['post_id']]), 'video');
    ok(preg_match('#^uploads/vid_.+\.mp4$#', (string)q1('SELECT image_url FROM post_images WHERE post_id = ?', [$r['post_id']])) === 1, 'vid_ name');
    $t2 = upload('kenda', noisePng(30, 30, 12));
    $r = aiIs(makePost(['client' => 'kenda', 'caption' => 's', 'media_tokens' => [$t2], 'source_ref' => 'v-4', 'post_type' => 'story', 'scheduled_date' => '2026-12-01T18:30']), 200)['json'];
    is(rows('SELECT post_type, scheduled_date FROM posts WHERE id = ?', [$r['post_id']])[0], ['post_type' => 'story', 'scheduled_date' => '2026-12-01 18:30:00']);
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'post' AND entity_id = ? AND action = 'commented'", [$r['post_id']]), 0, 'no note → no comment row');
    is((int)$ok['post_id'] > 0, true);
});

// =====================================================================================================================
// Status read-back
// =====================================================================================================================
atest('status: draft → pending → approved / denied with the client’s latest note (never an internal one), posted, trashed', function () {
    [$a] = quickPost('st-a', 1, ['note' => 'INTERNAL-ONLY note']);
    [$b] = quickPost('st-b', 1);
    $s = byId(statusOf('kenda', "ids={$a},{$b},999999"));
    is(array_keys($s), [$a, $b], 'unknown ids left out');
    is($s[$a]['status'], 'draft');
    is($s[$a]['source_ref'], 'st-a');
    is($s[$a]['scheduled_date'], '2026-11-05');
    is($s[$a]['posted'], false);
    is($s[$a]['trashed'], false);
    is($s[$a]['last_client_note'], null, 'the internal note is never the client note');
    ok(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string)$s[$a]['updated_at']) === 1, 'ISO 8601: ' . $s[$a]['updated_at']);
    is(array_keys($s[$a]), ['id', 'source_ref', 'status', 'scheduled_date', 'posted', 'trashed', 'updated_at', 'last_client_note']);
    // by source_refs (comma list and the repeated form), both together
    is(array_column(statusOf('kenda', 'source_refs=st-b,st-a,nope'), 'id'), [$a, $b]);
    is(array_column(statusOf('kenda', 'source_refs[]=st-b&source_refs[]=st-a'), 'id'), [$a, $b]);
    is(array_column(statusOf('kenda', "ids={$a}&source_refs=st-b"), 'id'), [$a, $b]);
    // flipped in the database: every raw status
    foreach (['pending', 'approved', 'denied', 'draft'] as $st) {
        db()->prepare('UPDATE posts SET status = ? WHERE id = ?')->execute([$st, $a]);
        is(byId(statusOf('kenda', "ids={$a}"))[$a]['status'], $st, $st);
    }
    // the real flow: Send for review, the client asks for changes with a note → denied + the note
    status(post('status.php', ['action' => 'submit', 'id' => $a, 'client' => 'kenda'], 'admin', [], J), 200);
    is(byId(statusOf('kenda', "ids={$a}"))[$a]['status'], 'pending');
    status(post('status.php', ['id' => $a, 'comment' => 'First thought: brighter please', 'client' => 'kenda'], 'client', [], J), 200);
    status(post('status.php', ['id' => $a, 'status' => 'denied', 'comment' => '[Slide 1] Swap the tire for the AT2', 'client' => 'kenda'], 'client', [], J), 200, 'client deny');
    status(post('status.php', ['id' => $a, 'comment' => 'INTERNAL-2 after the deny', 'internal' => 1, 'client' => 'kenda'], 'admin', [], J), 200, 'internal note');
    status(post('status.php', ['id' => $a, 'comment' => 'Joust reply', 'client' => 'kenda'], 'admin', [], J), 200, 'Joust reply');
    $x = byId(statusOf('kenda', "ids={$a}"))[$a];
    is($x['status'], 'denied');
    is($x['last_client_note'], '[Slide 1] Swap the tire for the AT2', 'the client’s latest, not Joust’s, not internal');
    // approved later (the client changes its mind) → approved; the note stays the latest client one
    status(post('status.php', ['id' => $a, 'status' => 'approved', 'client' => 'kenda'], 'client', [], J), 200, 'approve instead');
    is(byId(statusOf('kenda', "ids={$a}"))[$a]['status'], 'approved');
    // posted (Mark scheduled) and trashed
    db()->prepare('UPDATE posts SET posted = 1, posted_at = NOW() WHERE id = ?')->execute([$a]);
    db()->prepare('UPDATE posts SET trashed_at = NOW() WHERE id = ?')->execute([$b]);
    $s = byId(statusOf('kenda', "ids={$a},{$b}"));
    is($s[$a]['posted'], true);
    is($s[$b]['trashed'], true);
    // a client comment that was deleted is no note
    db()->exec("UPDATE activity_log SET detail = '', deleted_at = NOW() WHERE entity_type = 'post' AND entity_id = {$a} AND actor = 'client' AND detail LIKE '[Slide 1]%'");
    is(byId(statusOf('kenda', "ids={$a}"))[$a]['last_client_note'], 'First thought: brighter please');
    // seed posts (no source_ref) can be read by id too
    $seed = byId(statusOf('kenda', 'ids=4'));
    is($seed[4]['status'], 'denied');
    is($seed[4]['source_ref'], null);
    ok(is_string($seed[4]['last_client_note']) && $seed[4]['last_client_note'] !== '', 'the seed’s deny note');
    // validation
    aiIs(ai('GET', 'part=status&client=kenda'), 400, 'neither ids nor source_refs');
    aiIs(ai('GET', 'part=status&client=kenda&ids=1,x'), 400, 'bad id');
    aiIs(ai('GET', 'part=status&ids=1'), 400, 'no client');
    aiIs(ai('GET', 'part=status&client=nobody&ids=1'), 404, 'unknown client');
    aiIs(ai('GET', 'part=status&client=kenda&ids=' . implode(',', range(1, 201))), 400, 'over 200');
    aiIs(ai('GET', 'part=status&client=kenda&ids=' . implode(',', range(1, 200))), 200, '200 is fine');
});

atest('isolation: another client’s ids / refs are never returned, its uploads never usable', function () {
    [$k] = quickPost('iso-1', 1, ['note' => 'kenda only']);
    [$p] = quickPost('iso-1', 1, [], 'privacybee');
    is(array_column(statusOf('privacybee', "ids={$k},{$p},1,2,3,8"), 'id'), [8, $p], 'privacybee sees its own only (by id)');
    is(array_column(statusOf('kenda', "ids={$p},8"), 'id'), [], 'kenda sees none of privacybee’s');
    is(array_column(statusOf('hmf', 'source_refs=iso-1'), 'id'), []);
    is(array_column(statusOf('kenda', 'source_refs=iso-1'), 'id'), [$k]);
    // a piece / finish never needs the client again, but a token is bound to the client it was uploaded for
    $tok = upload('privacybee', noisePng(30, 30, 21));
    aiIs(makePost(['client' => 'kenda', 'caption' => 'x', 'media_tokens' => [$tok], 'source_ref' => 'iso-2']), 400, 'privacybee token into kenda');
    aiIs(makePost(['client' => 'privacybee', 'caption' => 'x', 'media_tokens' => [$tok], 'source_ref' => 'iso-2']), 200, 'its own client');
    // the composer's own tokens (purpose post) are not agent tokens, and the reverse
    is((int)q1("SELECT COUNT(*) FROM posts WHERE company_id = 1 AND source_ref = 'iso-2'"), 0);
});

// =====================================================================================================================
// Limits
// =====================================================================================================================
atest('limits: JSON over 1 MB, a piece over chunk_bytes or past the announced size, files over the caps → 413', function () {
    $big = ['client' => 'kenda', 'caption' => str_repeat('x', 1048600), 'media_tokens' => [], 'source_ref' => 'big'];
    $r = aiIs(makePost($big), 413, 'JSON > 1 MB');
    has($r['json']['error'], '1 MB');
    $ok = ['client' => 'kenda', 'filename' => 'a.png', 'mime' => 'image/png', 'sha256' => str_repeat('c', 64)];
    $r = aiIs(ai('POST', 'part=media_begin', $ok + ['bytes' => 50 * 1024 * 1024 + 1]), 413, 'image > 50 MB');
    is($r['json']['limit_bytes'], 50 * 1024 * 1024);
    aiIs(ai('POST', 'part=media_begin', $ok + ['bytes' => 50 * 1024 * 1024]), 200, 'image = 50 MB');
    aiIs(ai('POST', 'part=media_begin', ['mime' => 'video/mp4'] + $ok + ['bytes' => 4 * 1024 * 1024 * 1024 + 1]), 413, 'video > 4 GB');
    $b = aiIs(ai('POST', 'part=media_begin', ['mime' => 'video/mp4'] + $ok + ['bytes' => 4 * 1024 * 1024 * 1024]), 200, 'video = 4 GB')['json'];
    $cb = (int)$b['chunk_bytes'];
    $r = aiIs(chunk($b['upload_id'], 0, str_repeat('z', $cb + 1)), 413, 'piece > chunk_bytes');
    is($r['json']['received'], 0);
    aiIs(chunk($b['upload_id'], 0, str_repeat('z', $cb)), 200, 'piece = chunk_bytes');
    // more than announced
    $png = noisePng(20, 20, 5);
    $b = aiIs(begin('kenda', $png), 200)['json'];
    aiIs(chunk($b['upload_id'], 0, $png . 'extra'), 413, 'more bytes than announced');
});

// =====================================================================================================================
// migrate.php 55
// =====================================================================================================================
atest('migrate 55: before it the endpoint answers 503 "not migrated" (health / clients still work); fresh, partial, re-run', function () {
    ok(hasCol() && hasKey(), 'bootstrap ran step 55');
    is((string)q1("SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'source_ref'"), 'utf8mb4_bin');
    is((string)q1("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'source_ref'"), 'varchar(190)');
    is((string)q1("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'source_ref'"), 'YES');
    is(array_column(rows("SELECT COLUMN_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'ux_posts_source_ref' ORDER BY SEQ_IN_INDEX"), 'COLUMN_NAME'), ['company_id', 'source_ref']);
    is((int)q1("SELECT NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'ux_posts_source_ref' LIMIT 1"), 0);
    try {
        // the portal before step 55
        db()->exec('ALTER TABLE posts DROP INDEX ux_posts_source_ref, DROP COLUMN source_ref');
        $h = aiIs(ai('GET', 'health=1'), 200)['json'];
        is($h['migrated'], false);
        aiIs(ai('GET', 'clients=1'), 200, 'clients still listed');
        foreach ([['POST', 'part=media_begin', ['client' => 'kenda']], ['POST', 'part=post', ['client' => 'kenda']], ['GET', 'part=status&client=kenda&ids=1', null]] as [$m, $q, $body]) {
            $r = aiIs(ai($m, $q, $body), 503, $q);
            is($r['json']['error'], 'not migrated');
        }
        // the portal itself is unaffected (it never names the column)
        status(get('posts.php?client=kenda', 'admin'), 200);
        status(get('posts.php?client=kenda&post=2&partial=1', 'client'), 200);
        // fresh
        $out = runMigrate();
        has($out, 'Migration complete');
        has($out, 'Added posts.source_ref for the agent team intake (2 changes)');
        ok(hasCol() && hasKey(), 'column + key');
        // re-run: nothing
        $out = runMigrate();
        has($out, 'posts.source_ref (agent team intake) already exists — skipped');
        // partial: the column without its key
        db()->exec('ALTER TABLE posts DROP INDEX ux_posts_source_ref');
        aiIs(ai('POST', 'part=post', ['client' => 'kenda']), 503, 'no key = not migrated (idempotency needs it)');
        has(runMigrate(), 'Added posts.source_ref for the agent team intake (1 change)');
        ok(hasKey(), 'key back');
        aiIs(ai('GET', 'part=status&client=kenda&ids=1'), 200, 'migrated again');
    } finally {
        if (!hasCol() || !hasKey()) runMigrate();
    }
});

// =====================================================================================================================
// Router / clean links
// =====================================================================================================================
atest('router: agent-intake is a machine endpoint — never routed, never a client slug; with clean links it answers itself', function () {
    $out = appPhp('
        $f = [];
        if (!in_array("agent-intake", portalMachineEndpoints(), true)) $f[] = "not a machine endpoint";
        if (!in_array("agent-intake", portalReservedSegments(), true)) $f[] = "not reserved";
        foreach (["agent-intake", "agent-intake/", "agent-intake/posts", "agent-intake.php"] as $p) if (portalRouteMatch($p) !== null) $f[] = "routed: $p";
        $pass = "";
        foreach (explode("\n", cleanLinksBlock("/portal")) as $l) if (strpos($l, "/?$ - [L]") !== false) $pass = $l;
        if (!preg_match("#\\(([a-z|-]+)\\)#", $pass, $m) || !in_array("agent-intake", explode("|", $m[1]), true)) $f[] = "not passed through by the .htaccess block: " . $pass;
        echo $f ? implode("; ", $f) : "OK";');
    is(trim($out), 'OK');
    foreach (['/portal/agent-intake', '/portal/agent-intake/', '/portal/agent-intake/x', '/portal/agent-intake.php/x'] as $u) {
        ok(strpos(routeProbe($u, 'POST'), '404|') === 0, "route.php: {$u} → 404");
    }
    $r = post('client-admin.php', ['action' => 'create', 'name' => 'Agent Intake Co', 'slug' => 'agent-intake'], 'admin', [], J);
    ok($r['code'] >= 400 || (int)q1("SELECT COUNT(*) FROM companies WHERE slug = 'agent-intake'") === 0, 'slug refused');
    // live: clean links installed → /portal/agent-intake is the script itself (no redirect, no sign-in page)
    status(post('client-admin.php', ['action' => 'clean_links_install'], 'admin', [], J), 200, 'clean links installed');
    try {
        $r = aiIs(ai('GET', 'health=1', null, 'bearer', [], 'agent-intake'), 200, 'extensionless');
        is($r['json']['configured'], true);
        $r = aiIs(ai('GET', 'health=1', null, 'none', [], 'agent-intake'), 401, 'extensionless, no secret');
        is($r['location'], '');
        hasNot($r['body'], 'data-signin');
        $tok = upload('kenda', noisePng(20, 20, 31));
        $r = aiIs(ai('POST', 'part=post', ['client' => 'kenda', 'caption' => 'clean', 'media_tokens' => [$tok], 'source_ref' => 'clean-1'], 'bearer', [], 'agent-intake'), 200, 'POST extensionless');
        $r2 = get('agent-intake/', 'anon');
        is($r2['code'], 404, 'agent-intake/ is not routed');
        is($r2['location'], '');
        is(get('kenda/posts', 'client')['code'], 200, 'client pages still routed');
    } finally {
        post('client-admin.php', ['action' => 'clean_links_remove'], 'admin', [], J);
    }
});

finish();
