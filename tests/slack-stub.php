<?php
/**
 * Test harness only — a fake Slack Web API (and response_url target) for the notification tests.
 * Started by tests/serve.sh as its own `php -S` (router = this file) on $PORTAL_TEST_STUB_PORT; the test config.php
 * points slack_api_base at http://127.0.0.1:<port>/api. Never deployed (tests/** is excluded).
 *
 * Every request is appended as one JSON line to $SLACK_STUB_LOG: {method, auth, body, at}.
 * Control file $SLACK_STUB_LOG.fail (contents = a Slack error code like "channel_not_found", or "http500"):
 * every API call fails that way while it exists.
 */
$log = (string)getenv('SLACK_STUB_LOG');
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$raw = (string)file_get_contents('php://input');
$ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
if (strpos($ctype, 'json') !== false) {
    $body = json_decode($raw, true);
} else {
    parse_str($raw, $body);
}
if (!is_array($body)) $body = [];
$method = preg_match('#^/api/([A-Za-z.]+)$#', $path, $m) ? $m[1] : ltrim($path, '/');
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');

$seq = 0;
if ($log !== '') {
    $fh = fopen($log, 'a+');
    if ($fh) {
        flock($fh, LOCK_EX);
        fseek($fh, 0);
        $seq = substr_count((string)stream_get_contents($fh), "\n") + 1;
        fwrite($fh, json_encode(['method' => $method, 'auth' => $auth, 'body' => $body, 'at' => microtime(true)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

header('Content-Type: application/json');
$fail = ($log !== '' && is_file($log . '.fail')) ? trim((string)file_get_contents($log . '.fail')) : '';
if (strpos($path, '/response/') === 0) { header('Content-Type: text/plain'); echo 'ok'; return true; }
if ($fail === 'http500') { http_response_code(500); echo '{"ok":false,"error":"internal_error"}'; return true; }
if ($fail !== '') { echo json_encode(['ok' => false, 'error' => $fail]); return true; }
if ($auth !== 'Bearer xoxb-test-token') { echo json_encode(['ok' => false, 'error' => 'invalid_auth']); return true; }

$ts = sprintf('%d.%06d', 1700000000 + $seq, $seq);
switch ($method) {
    case 'chat.postMessage':
        echo json_encode(['ok' => true, 'channel' => (string)($body['channel'] ?? ''), 'ts' => $ts]);
        break;
    case 'chat.update':
        echo json_encode(['ok' => true, 'channel' => (string)($body['channel'] ?? ''), 'ts' => (string)($body['ts'] ?? '')]);
        break;
    case 'conversations.open':
        echo json_encode(['ok' => true, 'channel' => ['id' => 'D' . substr((string)($body['users'] ?? 'X'), 1)]]);
        break;
    case 'conversations.list':
        echo json_encode(['ok' => true, 'channels' => [['id' => 'C0KENDA', 'name' => 'portal-kenda'], ['id' => 'C0PBEE', 'name' => 'portal-privacybee'], ['id' => 'C0HMF', 'name' => 'portal-hmf']], 'response_metadata' => ['next_cursor' => '']]);
        break;
    case 'users.lookupByEmail':
        echo ($body['email'] ?? '') === 'lance@joustmedia.com' ? json_encode(['ok' => true, 'user' => ['id' => 'U0LANCE']]) : json_encode(['ok' => false, 'error' => 'users_not_found']);
        break;
    default:
        echo json_encode(['ok' => true]);
}
return true;
