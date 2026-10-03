<?php
/**
 * Test harness only — a fake Google (OAuth consent + token endpoints, Gmail API) for the email tests.
 * Started by tests/serve.sh as its own `php -S` (router = this file) on $GOOGLE_STUB_PORT; the test config.php points
 * google_api_base and google_oauth_base at http://127.0.0.1:<port>. Never deployed (tests/** is excluded).
 *
 * State lives in $GOOGLE_STUB_DIR:
 *   calls.jsonl     every request: {path, method, auth, query, body, at}
 *   account.txt     the address users/me/profile answers (default lance@joustmedia.com)
 *   fail.txt        a failure mode while it exists: invalid_grant (refresh refused) · no_modify (the consent grants
 *                   gmail.send only) · send500 (messages/send answers 500) · list500 (messages.list answers 500)
 *   mailbox.json    {"messages": [{id, threadId, raw (base64url RFC 5322), labelIds}], "labels": [{id, name}]}
 *                   — tests drop replies in here; modify adds label ids; list honours "-label:<name>"
 *   sent/<n>.eml    every message messages/send received (decoded)
 *   refresh.txt     every refresh token handed out (one per line) — the at-rest test looks for them in the DB
 *
 *   GET  /o/oauth2/v2/auth?...          → 302 <redirect_uri>?code=stubcode-…&state=<state> (instant consent)
 *   POST /token                         authorization_code | refresh_token (client test-google-client-id / test-google-secret)
 *   POST /revoke                        → {}
 *   GET  /gmail/v1/users/me/profile, POST …/messages/send, GET …/messages?q=, GET …/messages/{id}?format=raw,
 *   POST …/messages/{id}/modify, GET / POST …/labels        (Bearer ya29.stub-…, else 401)
 */
$dir = rtrim((string)getenv('GOOGLE_STUB_DIR'), '/');
if ($dir === '') { http_response_code(500); echo 'GOOGLE_STUB_DIR missing'; return true; }
@mkdir($dir . '/sent', 0777, true);
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = (string)file_get_contents('php://input');
$ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
$body = strpos($ctype, 'json') !== false ? json_decode($raw, true) : (static function ($r) { parse_str($r, $o); return $o; })($raw);
if (!is_array($body)) $body = [];
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$fail = is_file($dir . '/fail.txt') ? trim((string)file_get_contents($dir . '/fail.txt')) : '';
file_put_contents($dir . '/calls.jsonl', json_encode(['path' => $path, 'method' => $method, 'auth' => $auth, 'query' => $_GET,
    'body' => $path === '/gmail/v1/users/me/messages/send' ? ['raw_len' => strlen((string)($body['raw'] ?? ''))] : $body, 'at' => microtime(true)],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

$json = static function (int $code, array $j): bool { http_response_code($code); header('Content-Type: application/json'); echo json_encode($j, JSON_UNESCAPED_SLASHES); return true; };
$b64d = static function (string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); };
$account = is_file($dir . '/account.txt') ? trim((string)file_get_contents($dir . '/account.txt')) : 'lance@joustmedia.com';
$box = static function (?array $set = null) use ($dir) {
    $f = $dir . '/mailbox.json';
    if ($set !== null) { file_put_contents($f, json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX); return $set; }
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d + ['messages' => [], 'labels' => []] : ['messages' => [], 'labels' => []];
};

// ---- OAuth -------------------------------------------------------------------------------------------------------------
if ($path === '/o/oauth2/v2/auth') {
    $redir = (string)($_GET['redirect_uri'] ?? '');
    if ($redir === '' || ($_GET['client_id'] ?? '') !== 'test-google-client-id') return $json(400, ['error' => 'invalid_client']);
    header('Location: ' . $redir . (strpos($redir, '?') === false ? '?' : '&') . http_build_query(['code' => 'stubcode-' . bin2hex(random_bytes(6)), 'state' => (string)($_GET['state'] ?? ''),
        'scope' => (string)($_GET['scope'] ?? '')]), true, 302);
    return true;
}
if ($path === '/token' && $method === 'POST') {
    if (($body['client_id'] ?? '') !== 'test-google-client-id' || ($body['client_secret'] ?? '') !== 'test-google-secret') return $json(401, ['error' => 'invalid_client']);
    $access = 'ya29.stub-' . bin2hex(random_bytes(8));
    if (($body['grant_type'] ?? '') === 'authorization_code') {
        if (strpos((string)($body['code'] ?? ''), 'stubcode-') !== 0) return $json(400, ['error' => 'invalid_grant', 'error_description' => 'Bad code']);
        $refresh = '1//stub-refresh-' . bin2hex(random_bytes(12));
        file_put_contents($dir . '/refresh.txt', $refresh . "\n", FILE_APPEND | LOCK_EX);
        $scope = $fail === 'no_modify' ? 'https://www.googleapis.com/auth/gmail.send' : 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.modify';
        return $json(200, ['access_token' => $access, 'expires_in' => 3599, 'refresh_token' => $refresh, 'scope' => $scope, 'token_type' => 'Bearer']);
    }
    if (($body['grant_type'] ?? '') === 'refresh_token') {
        if ($fail === 'invalid_grant') return $json(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']);
        return $json(200, ['access_token' => $access, 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.modify', 'token_type' => 'Bearer']);
    }
    return $json(400, ['error' => 'unsupported_grant_type']);
}
if ($path === '/revoke') return $json(200, []);

// ---- Gmail -------------------------------------------------------------------------------------------------------------
if (strpos($path, '/gmail/v1/users/me/') !== 0) return $json(404, ['error' => ['code' => 404, 'message' => 'Not found']]);
if (strpos($auth, 'Bearer ya29.stub-') !== 0) return $json(401, ['error' => ['code' => 401, 'message' => 'Invalid Credentials', 'status' => 'UNAUTHENTICATED']]);
$rest = substr($path, strlen('/gmail/v1/users/me/'));

if ($rest === 'profile') return $json(200, ['emailAddress' => $account, 'messagesTotal' => 10]);

if ($rest === 'messages/send' && $method === 'POST') {
    if ($fail === 'send500') return $json(500, ['error' => ['code' => 500, 'message' => 'Backend Error']]);
    $mime = $b64d((string)($body['raw'] ?? ''));
    if ($mime === '') return $json(400, ['error' => ['code' => 400, 'message' => 'Invalid raw']]);
    $n = count(glob($dir . '/sent/*.eml') ?: []) + 1;
    file_put_contents(sprintf('%s/sent/%04d.eml', $dir, $n), $mime);
    return $json(200, ['id' => 'sent' . $n, 'threadId' => 'thr' . $n, 'labelIds' => ['SENT']]);
}

if ($rest === 'labels') {
    $b = $box();
    if ($method === 'POST') {
        $id = 'Label_' . (count($b['labels']) + 1);
        $b['labels'][] = ['id' => $id, 'name' => (string)($body['name'] ?? '')];
        $box($b);
        return $json(200, ['id' => $id, 'name' => (string)($body['name'] ?? '')]);
    }
    return $json(200, ['labels' => array_merge([['id' => 'INBOX', 'name' => 'INBOX'], ['id' => 'SENT', 'name' => 'SENT']], $b['labels'])]);
}

if ($rest === 'messages' && $method === 'GET') {
    if ($fail === 'list500') return $json(500, ['error' => ['code' => 500, 'message' => 'Backend Error']]);
    $b = $box();
    $q = (string)($_GET['q'] ?? '');
    $exclude = [];
    if (preg_match_all('/-label:(\S+)/', $q, $m)) {
        foreach ($m[1] as $name) foreach ($b['labels'] as $l) if (strcasecmp($l['name'], $name) === 0) $exclude[] = $l['id'];
    }
    $to = preg_match('/\bto:(\S+)/', $q, $mm) ? strtolower($mm[1]) : '';
    $out = [];
    foreach ($b['messages'] as $msg) {
        if (array_intersect($exclude, (array)($msg['labelIds'] ?? []))) continue;
        if ($to !== '' && stripos($b64d((string)$msg['raw']), $to) === false) continue;
        $out[] = ['id' => $msg['id'], 'threadId' => $msg['threadId'] ?? $msg['id']];
    }
    $out = array_slice($out, 0, max(1, (int)($_GET['maxResults'] ?? 100)));
    return $json(200, $out ? ['messages' => $out, 'resultSizeEstimate' => count($out)] : ['resultSizeEstimate' => 0]);
}

if (preg_match('#^messages/([A-Za-z0-9_-]+)(/modify)?$#', $rest, $m)) {
    $b = $box();
    foreach ($b['messages'] as $i => $msg) {
        if ($msg['id'] !== $m[1]) continue;
        if (!empty($m[2]) && $method === 'POST') {
            $b['messages'][$i]['labelIds'] = array_values(array_unique(array_merge((array)($msg['labelIds'] ?? []), (array)($body['addLabelIds'] ?? []))));
            $box($b);
            return $json(200, ['id' => $msg['id'], 'labelIds' => $b['messages'][$i]['labelIds']]);
        }
        return $json(200, ['id' => $msg['id'], 'threadId' => $msg['threadId'] ?? $msg['id'], 'labelIds' => $msg['labelIds'] ?? [], 'raw' => $msg['raw']]);
    }
    return $json(404, ['error' => ['code' => 404, 'message' => 'Requested entity was not found.']]);
}
return $json(404, ['error' => ['code' => 404, 'message' => 'Not found']]);
