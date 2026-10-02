<?php
/**
 * Test harness only — tiny HTTP + assertion kit for the smoke suites (tests/smoke/*.php).
 *
 *   require __DIR__ . '/lib.php';
 *   test('posts page renders', function () {
 *       $r = get('posts.php?client=kenda', 'admin');
 *       is($r['code'], 200);
 *       has($r['body'], 'data-posts-list');
 *   });
 *   finish();
 *
 * Seats: 'admin' (the test-auth.php shim signs in), 'client' (anonymous client seat), 'anon' (no cookie).
 * Requests go to $PORTAL_TEST_BASE (tests/env.sh); db() opens the test database.
 */

const SMOKE_UA = 'portal-smoke/1';
$GLOBALS['__smoke'] = ['pass' => 0, 'fail' => 0, 'current' => '', 'failures' => []];

function base(): string { return rtrim((string)(getenv('PORTAL_TEST_BASE') ?: 'http://127.0.0.1:8099/portal'), '/'); }

/**
 * One request. $data = form fields (POST, multipart when $files is given), $files = ['field' => path | [paths]].
 * Returns ['code', 'body', 'headers' (lower-case name → last value), 'json' (decoded or null), 'location'].
 */
function req(string $method, string $path, string $role = 'admin', array $data = [], array $files = [], array $headers = []): array {
    $url = preg_match('#^https?://#', $path) ? $path : base() . '/' . ltrim($path, '/');
    $ch = curl_init();
    $hdrs = [];
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => SMOKE_UA,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$hdrs) {
            $p = strpos($line, ':');
            if ($p !== false) $hdrs[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ];
    if ($role === 'admin' || $role === 'client') $opts[CURLOPT_COOKIE] = 'portal_test_role=' . $role;
    $h = [];
    foreach ($headers as $k => $v) $h[] = is_int($k) ? $v : "$k: $v";
    if ($method !== 'GET') {
        if ($files) {
            $fields = [];
            foreach ($data as $k => $v) {
                if (is_array($v)) { foreach (array_values($v) as $i => $vv) $fields["{$k}[{$i}]"] = (string)$vv; }
                else $fields[$k] = (string)$v;
            }
            foreach ($files as $k => $paths) {
                if (is_array($paths)) { foreach (array_values($paths) as $i => $p) $fields["{$k}[{$i}]"] = new CURLFile($p, '', basename($p)); }
                else $fields[$k] = new CURLFile($paths, '', basename($paths));
            }
            $opts[CURLOPT_POSTFIELDS] = $fields;
        } else {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
        }
    }
    if ($h) $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    if ($body === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException("request failed: $method $url — $err"); }
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = null;
    if (isset($hdrs['content-type']) && stripos($hdrs['content-type'], 'json') !== false) $json = json_decode($body, true);
    return ['code' => $code, 'body' => (string)$body, 'headers' => $hdrs, 'json' => $json, 'location' => $hdrs['location'] ?? ''];
}
function get(string $path, string $role = 'admin', array $headers = []): array { return req('GET', $path, $role, [], [], $headers); }
function post(string $path, array $data, string $role = 'admin', array $files = [], array $headers = []): array { return req('POST', $path, $role, $data, $files, $headers); }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host=localhost;dbname=' . (getenv('PORTAL_TEST_DB') ?: 'portal_test') . ';charset=utf8mb4',
        getenv('PORTAL_TEST_DB_USER') ?: 'portal_test', getenv('PORTAL_TEST_DB_PASS') ?: 'portal_test',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}
function q1(string $sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function rows(string $sql, array $p = []): array { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }

/** A labelled JPEG in the system temp dir (GD) for upload tests. */
function tmpImage(string $label = 'smoke', int $w = 400, int $h = 300): string {
    $p = sys_get_temp_dir() . '/smoke_' . preg_replace('/\W+/', '_', $label) . '_' . bin2hex(random_bytes(3)) . '.jpg';
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, random_int(0, 200), 80, 120));
    imagestring($im, 5, 10, 10, $label, imagecolorallocate($im, 255, 255, 255));
    imagejpeg($im, $p, 80);
    return $p;
}

// ---- assertions -------------------------------------------------------------------------------
final class SmokeFail extends Exception {}
function fail(string $msg): void { throw new SmokeFail($msg); }
function ok($cond, string $msg = 'expected true'): void { if (!$cond) fail($msg); }
function is($actual, $expected, string $msg = ''): void {
    if ($actual !== $expected) fail(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function has(string $hay, string $needle, string $msg = ''): void {
    if (strpos($hay, $needle) === false) fail(($msg !== '' ? $msg . ': ' : '') . 'missing ' . var_export($needle, true));
}
function hasNot(string $hay, string $needle, string $msg = ''): void {
    if (strpos($hay, $needle) !== false) fail(($msg !== '' ? $msg . ': ' : '') . 'unexpected ' . var_export($needle, true));
}
/** HTTP code + (optionally) the JSON ok flag in one go; returns the response for chaining. */
function status(array $r, int $code, string $msg = ''): array {
    if ($r['code'] !== $code) {
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags(substr($r['body'], 0, 300))));
        fail(($msg !== '' ? $msg . ': ' : '') . "HTTP {$r['code']} (want {$code}) — {$snippet}");
    }
    return $r;
}

function test(string $name, callable $fn): void {
    $S = &$GLOBALS['__smoke'];
    $S['current'] = $name;
    try {
        $fn();
        $S['pass']++;
        if (getenv('SMOKE_VERBOSE')) echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $S['fail']++;
        $where = $e instanceof SmokeFail ? '' : ' [' . get_class($e) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        $S['failures'][] = $name;
        echo "  FAIL {$name}\n       " . $e->getMessage() . $where . "\n";
    }
}

function finish(): void {
    $S = $GLOBALS['__smoke'];
    $suite = basename($_SERVER['argv'][0] ?? 'suite', '.php');
    printf("%-28s %3d passed, %d failed\n", $suite, $S['pass'], $S['fail']);
    // machine-readable tally for run.sh
    if ($f = getenv('SMOKE_TALLY')) file_put_contents($f, "{$S['pass']} {$S['fail']}\n", FILE_APPEND);
    exit($S['fail'] > 0 ? 1 : 0);
}
