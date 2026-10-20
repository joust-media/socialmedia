<?php
/**
 * Agent team intake — the machine endpoint Lance's AI agent team (Python, on his Mac) uses to deliver finished social
 * posts into the portal as Drafts and to read back what the client decided. README "Agent team intake".
 *
 * A delivered post is a Draft (posts.status = 'draft'): Joust-only, invisible to the client everywhere (status.php
 * answers 404 to the client seat) and never announced — no client email, no Slack message, no escalation. Lance
 * reviews it in the portal and sends it with the usual "Send for review" (status.php action=submit). Nothing here
 * ever publishes to a social network.
 *
 *   GET  ?health=1                     → {ok, configured, migrated, chunk_bytes, max_media, max_bytes{image, video}, mimes}
 *   GET  ?clients=1                    → {ok, clients:[{slug, name}]}         (every company: the portal never archives one)
 *   POST ?part=media_begin   {client, filename, mime, bytes, sha256}
 *                                      → {ok, upload_id, chunk_bytes, received: 0, bytes}
 *   POST ?part=media_chunk&upload_id=<id>&offset=<n>     raw body (application/octet-stream), at most chunk_bytes
 *                                      → {ok, received, bytes}   409 {received} when offset ≠ the bytes received so far
 *   POST ?part=media_finish  {upload_id}
 *                                      → {ok, media_token, bytes, mime, type}   (size + sha256 + real type checked)
 *   POST ?part=post          {client, caption, hashtags, scheduled_date, post_type?, media_tokens:[…], source_ref, note?}
 *                                      → {ok, post_id, status:'draft', admin_url, media}
 *                                        a repeat of (client, source_ref) → {ok, duplicate:true, post_id, status, admin_url}
 *   GET  ?part=status&client=<slug>&ids=1,2,3   (or &source_refs=a,b, or both)
 *                                      → {ok, posts:[{id, source_ref, status, scheduled_date, posted, trashed, updated_at,
 *                                         last_client_note}]}   (ids / refs of another client, or unknown, are left out)
 *
 * Auth: `Authorization: Bearer <agent_intake_secret>` (config.php, 24+ characters) — or `X-Agent-Secret: <secret>` for
 * hosts that strip Authorization before PHP sees it — compared in constant time, before anything else is loaded.
 * No secret configured → 503 {ok:false, error:'not configured'}; a missing / wrong one → 401. No session, no same-site
 * check: every cookie is dropped before helpers.php loads, so no admin or client session can start for this request.
 *
 * JSON only, both ways: {ok:true, …} / {ok:false, error:'…'}. Codes: 400 validation · 401 · 404 unknown client /
 * upload · 405 method · 409 state (chunk offset, incomplete upload) · 413 body / piece / file too large · 503 not
 * configured / not migrated (migrate.php step 55: posts.source_ref + its unique key; drafts need step 35) · 500
 * {ok:false, error:'server error'} for anything unexpected (logged via error_log; `&debug=1` with a valid secret adds
 * `detail` and `at`). A fatal anywhere, or a db.php that cannot connect, still answers that JSON: the shutdown handler
 * below is registered before the first require.
 *
 * Bodies: JSON parts ≤ 1 MB; a piece ≤ chunk_bytes (min(4 MiB, 80 % of the host's PHP request cap)).
 * Files: the pieces go to uploads/.spool/<upload_id>.part + .json (0600, deny-all .htaccess — chunk-upload-lib.php); a
 * finished file is parked as uploads/tmp_<token>.<ext> with its claim sidecar uploads/.spool/<token>.claim (upload-lib.php,
 * purpose 'agent' — the composer's tokens and these never mix); the post claims it as uploads/img_… / vid_… like any
 * composer upload. Unfinished uploads and unclaimed files are swept after 24 h (the shared spool rule).
 * Images: PNG / JPEG / WebP ≤ 50 MB; video: MP4 ≤ 4 GB (upload-lib.php uploadMaxBytes); 1–POST_MAX_MEDIA (20) per post.
 */

// =====================================================================
// 0. Fail-safe bootstrap — JSON no matter what happens below.
// =====================================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');      // never let a PHP diagnostic (HTML on most hosts) into the reply
ini_set('html_errors', '0');
error_reporting(E_ALL);
ob_start();                           // whatever a notice, db.php's die() or a fatal printed is dropped by agentEmit()

const AGENT_JSON_MAX    = 1048576;           // JSON bodies (413 above)
const AGENT_CHUNK_MAX   = 4 * 1024 * 1024;   // a piece, at most (and never above 80 % of the PHP request cap)
const AGENT_LIST_MAX    = 200;               // ids + source_refs per status call
const AGENT_REF_MAX     = 190;               // posts.source_ref (VARCHAR(190))
const AGENT_NOTE_MAX    = 2000;              // the internal note (status.php's comment cap)
const AGENT_CAPTION_MAX = 10000;             // post-compose.php's caps
const AGENT_TAGS_MAX    = 2000;
const AGENT_MIMES       = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'video/mp4' => 'mp4'];

$agentTmpFiles = [];   // piece files written by this request (removed at shutdown, whatever happens)

/** Drop every output buffer and send exactly one JSON document with the given status (once per request). */
function agentEmit(int $code, array $payload): void {
    if (defined('AGENT_INTAKE_REPLIED')) return;
    define('AGENT_INTAKE_REPLIED', 1);
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** config.php as an array (db.php's $config once loaded; read here first so auth needs no database). */
function agentConfigArray(): array {
    static $cfg = null;
    if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) return $GLOBALS['config'];
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        $c = is_file($file) ? (static function (string $f) { return require $f; })($file) : [];
        $cfg = is_array($c) ? $c : [];
    }
    return $cfg;
}

/** The configured secret ('' when unset or shorter than 24 characters — "not configured"). */
function agentSecret(): string {
    $s = trim((string)(agentConfigArray()['agent_intake_secret'] ?? ''));
    return strlen($s) >= 24 ? $s : '';
}

/** Every secret the request presents: the Bearer token, the X-Agent-Secret header (requestHeader(): drive-lib.php). */
function agentPresented(): array {
    $read = static function (string $name): string {
        if (function_exists('requestHeader')) return (string)(requestHeader($name) ?? '');
        $k = strtoupper(str_replace('-', '_', $name));
        return (string)($_SERVER['HTTP_' . $k] ?? $_SERVER['REDIRECT_HTTP_' . $k] ?? '');
    };
    $out = [];
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $read('Authorization'), $m)) $out[] = $m[1];
    $x = trim($read('X-Agent-Secret'));
    if ($x !== '') $out[] = $x;
    return $out;
}

/** Does one of the presented secrets match? Constant time per comparison. */
function agentAuthorized(): bool {
    $secret = agentSecret();
    if ($secret === '') return false;
    $ok = false;
    foreach (agentPresented() as $p) { if (hash_equals($secret, (string)$p)) $ok = true; }
    return $ok;
}

/** 500 for anything unexpected: rolled back, logged in full, generic to the caller unless debugging. */
function agentServerError(string $where, string $class, string $message, string $file, int $line): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) { try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e) { /* connection closes anyway */ } }
    error_log("agent-intake {$where}: {$class}: {$message} in {$file}:{$line}");
    $out = ['ok' => false, 'error' => 'server error'];
    if (($_GET['debug'] ?? '') === '1' && agentAuthorized()) {
        $out['detail'] = $class . ': ' . $message;
        $out['at']     = basename($file) . ':' . $line;
    }
    agentEmit(500, $out);
}

register_shutdown_function(static function (): void {
    foreach ($GLOBALS['agentTmpFiles'] ?? [] as $f) { if (is_string($f) && is_file($f)) @unlink($f); }
    if (defined('AGENT_INTAKE_REPLIED')) return;
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        agentServerError('fatal', 'Fatal', (string)$e['message'], (string)$e['file'], (int)$e['line']);
        return;
    }
    // ended without a reply (db.php's die() when the database is down, a stray exit in an include)
    agentServerError('exit', 'NoReply', 'the request ended without a reply', __FILE__, __LINE__);
});

function agentFail(int $code, string $msg, array $extra = []): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e) { /* connection closes anyway */ } }
    agentEmit($code, ['ok' => false, 'error' => $msg] + $extra);
    exit;
}
function agentReply(array $data): void {
    agentEmit(200, ['ok' => true] + $data);
    exit;
}

// =====================================================================
// 1. Method, secret — before the database or any portal code is loaded
// =====================================================================

require_once __DIR__ . '/drive-lib.php';   // requestHeader() + the array_is_list polyfill; function definitions only

$part = '';
try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'POST') agentFail(405, 'method not allowed');
    if (agentSecret() === '') agentFail(503, 'not configured', ['hint' => 'set agent_intake_secret (24+ characters) in config.php']);
    if (!agentAuthorized()) agentFail(401, 'unauthorized');

    // The machine seat: no cookie reaches the portal code, so currentAdmin() / the client gate never start a session.
    $_COOKIE = [];
    require __DIR__ . '/db.php';
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/chunk-upload-lib.php';
    require_once __DIR__ . '/upload-lib.php';

    if (isset($_GET['health'])) $part = 'health';
    elseif (isset($_GET['clients'])) $part = 'clients';
    else $part = is_string($_GET['part'] ?? null) ? strtolower(trim((string)$_GET['part'])) : '';
    $gets  = ['health', 'clients', 'status'];
    $posts = ['media_begin', 'media_chunk', 'media_finish', 'post'];
    if (!in_array($part, array_merge($gets, $posts), true)) agentFail(400, 'unknown part');
    if (in_array($part, $gets, true) && $method !== 'GET') agentFail(405, 'method not allowed (use GET)');
    if (in_array($part, $posts, true) && $method !== 'POST') agentFail(405, 'method not allowed (use POST)');
    if (!in_array($part, ['health', 'clients'], true) && !agentMigrated($pdo)) {
        agentFail(503, 'not migrated', ['hint' => 'open migrate.php while signed in as admin (step 55: posts.source_ref)']);
    }
} catch (Throwable $e) {
    agentServerError('request', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    exit;
}

// =====================================================================
// Helpers
// =====================================================================

/** migrate.php step 55 ran (posts.source_ref + ux_posts_source_ref) and posts take 'draft' (step 35). */
function agentMigrated(PDO $pdo): bool {
    static $on = null;
    if ($on !== null) return $on;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'source_ref'");
        $s->execute();
        $col = (int)$s->fetchColumn() > 0;
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'ux_posts_source_ref'");
        $s->execute();
        $key = (int)$s->fetchColumn() > 0;
        return $on = $col && $key && postsHaveDraft($pdo);
    } catch (Throwable $e) {
        return $on = false;
    }
}

/** The largest piece this host takes: min(4 MiB, chunkUploadChunkSize() = min(8 MiB, 80 % of the PHP request cap)). */
function agentChunkBytes(): int {
    return min(AGENT_CHUNK_MAX, chunkUploadChunkSize());
}

/** The JSON object in the body (413 over 1 MB, 400 when it is not an object). */
function agentJsonBody(): array {
    $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? requestHeader('Content-Length') ?? 0);
    if ($declared > AGENT_JSON_MAX) agentFail(413, 'request body is too large (max 1 MB)');
    $raw = (string)file_get_contents('php://input', false, null, 0, AGENT_JSON_MAX + 1);
    if (strlen($raw) > AGENT_JSON_MAX) agentFail(413, 'request body is too large (max 1 MB)');
    $d = json_decode($raw, true, 32);
    if (!is_array($d) || ($d !== [] && array_is_list($d))) agentFail(400, 'invalid JSON body (expected an object)');
    return $d;
}

/** The company for a slug: 400 when missing, 404 when unknown. */
function agentCompany(PDO $pdo, $slug): array {
    if (!is_string($slug) || trim($slug) === '') agentFail(400, 'client is required');
    $slug = strtolower(trim($slug));
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $slug)) agentFail(404, 'unknown client');
    $s = $pdo->prepare('SELECT id, name, slug FROM companies WHERE slug = ?');
    $s->execute([$slug]);
    $row = $s->fetch();
    if (!$row) agentFail(404, 'unknown client');
    return $row;
}

/** Text field: absent / null → '', otherwise a string without control characters (tabs and line breaks kept), ≤ $max. */
function agentText($v, int $max, string $field): string {
    if ($v === null) return '';
    if (!is_string($v)) agentFail(400, "{$field} must be a string");
    if (!mb_check_encoding($v, 'UTF-8')) agentFail(400, "{$field} is not valid UTF-8");
    $v = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $v)));
    if (mb_strlen($v, 'UTF-8') > $max) agentFail(400, "{$field} is limited to " . number_format($max) . ' characters');
    return $v;
}

/** hashtags: a string as is, or a list of tags (each gets its '#', joined by spaces). */
function agentHashtags($v): string {
    if (is_array($v)) {
        if (!array_is_list($v)) agentFail(400, 'hashtags must be a string or a list of strings');
        $tags = [];
        foreach ($v as $t) {
            if (!is_string($t)) agentFail(400, 'hashtags must be a string or a list of strings');
            $t = trim($t);
            if ($t === '') continue;
            $tags[] = $t[0] === '#' ? $t : '#' . $t;
        }
        $v = implode(' ', $tags);
    }
    return agentText($v, AGENT_TAGS_MAX, 'hashtags');
}

/** source_ref: 1–190 characters, no control characters (compared byte for byte: the column is utf8mb4_bin). */
function agentRef($v, string $field = 'source_ref'): string {
    if (!is_string($v) && !is_int($v)) agentFail(400, "{$field} is required (a string of 1–" . AGENT_REF_MAX . ' characters)');
    $v = trim((string)$v);
    if ($v === '' || !mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $v)) {
        agentFail(400, "{$field} is required (a string of 1–" . AGENT_REF_MAX . ' characters, no control characters)');
    }
    if (mb_strlen($v, 'UTF-8') > AGENT_REF_MAX) agentFail(400, "{$field} is longer than " . AGENT_REF_MAX . ' characters');
    return $v;
}

/** scheduled_date: 'YYYY-MM-DD' (10:00 that day, the portal's default time) or 'YYYY-MM-DD HH:MM[:SS]'; null / '' →
 *  tomorrow 10:00, which is what the portal gives a draft without a date (post-compose.php). */
function agentDate($v): string {
    if ($v === null || $v === '') return date('Y-m-d 10:00:00', strtotime('+1 day'));
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', trim($v), $m)
        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        agentFail(400, 'scheduled_date must be YYYY-MM-DD (or null)');
    }
    if (!isset($m[4]) || $m[4] === '') return sprintf('%04d-%02d-%02d 10:00:00', $m[1], $m[2], $m[3]);
    if ((int)$m[4] > 23 || (int)$m[5] > 59 || (int)($m[6] ?? 0) > 59) agentFail(400, 'scheduled_date has an invalid time');
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], (int)($m[6] ?? 0));
}

/** post_type: '' / null = auto (the portal's rule: one video → reel, anything else → post), else one of allowedPostTypes(). */
function agentPostType($v): string {
    if ($v === null || $v === '' || (is_string($v) && strtolower(trim($v)) === 'auto')) return '';
    $t = is_string($v) ? strtolower(trim($v)) : '';
    if (!in_array($t, allowedPostTypes(), true)) agentFail(400, 'post_type must be one of ' . implode(', ', allowedPostTypes()) . ' (or null for auto)');
    return $t;
}

/** media_tokens: 1–POST_MAX_MEDIA tokens ("<32 hex>" or "claim:<32 hex>") in carousel order, no repeats. */
function agentTokens($v): array {
    if (!is_array($v) || !array_is_list($v)) agentFail(400, 'media_tokens must be a list');
    if (count($v) < 1) agentFail(400, 'media_tokens needs at least one item');
    if (count($v) > POST_MAX_MEDIA) agentFail(400, 'up to ' . POST_MAX_MEDIA . ' media per post (got ' . count($v) . ')');
    $out = [];
    foreach ($v as $i => $t) {
        $t = is_string($t) ? strtolower(trim($t)) : '';
        if (strpos($t, 'claim:') === 0) $t = substr($t, 6);
        if (!uploadClaimValidToken($t)) agentFail(400, "media_tokens[{$i}] is not a media token", ['index' => $i]);
        if (in_array($t, $out, true)) agentFail(400, "media_tokens[{$i}] repeats an earlier token", ['index' => $i]);
        $out[] = $t;
    }
    return $out;
}

/** A positive integer from JSON (int, integral float or digit string), else null. */
function agentPosInt($v): ?int {
    if (is_int($v)) return $v > 0 ? $v : null;
    if (is_float($v)) return ($v > 0 && $v <= PHP_INT_MAX && floor($v) === $v) ? (int)$v : null;
    if (is_string($v) && preg_match('/^\d{1,19}$/', trim($v))) { $n = (int)trim($v); return $n > 0 ? $n : null; }
    return null;
}

/** The display name of an upload: its base name, no control characters, ≤ 190 characters. */
function agentFileName($v): string {
    if (!is_string($v)) agentFail(400, 'filename is required');
    $n = basename(str_replace('\\', '/', trim($v)));
    $n = (string)preg_replace('/[\x00-\x1F\x7F]/', '', mb_check_encoding($n, 'UTF-8') ? $n : mb_convert_encoding($n, 'UTF-8', 'UTF-8'));
    if ($n === '' || $n === '.' || $n === '..') agentFail(400, 'filename is required');
    return mb_strlen($n, 'UTF-8') > 190 ? mb_substr($n, 0, 190, 'UTF-8') : $n;
}

/** uploads/ (the spool root; 500 when it cannot be used). */
function agentRoot(): string {
    $up = uploadsDirEnsure();
    if ($up === null) agentFail(500, 'uploads/ is not writable on the server');
    return $up;
}

/** The raw spool sidecar of an agent upload (also after finish, when the part file is gone), else null. */
function agentUploadSidecar(string $root, string $id): ?array {
    $p = chunkSpoolPaths($root, $id);
    if ($p === null || !is_file($p['meta']) || is_link($p['meta'])) return null;
    $m = json_decode((string)@file_get_contents($p['meta']), true);
    return is_array($m) && ($m['upload_id'] ?? '') === $id && ($m['kind'] ?? '') === 'agent' ? $m : null;
}

/** The existing post of this company for a source_ref, or null. */
function agentPostByRef(PDO $pdo, int $companyId, string $ref): ?array {
    $s = $pdo->prepare('SELECT id, status FROM posts WHERE company_id = ? AND source_ref = ? LIMIT 1');
    $s->execute([$companyId, $ref]);
    $r = $s->fetch();
    return $r ?: null;
}

/** Absolute link to the post's sheet for Joust (activityDeepLink → posts?client=…&post=…; portal_url when set). */
function agentAdminUrl(int $postId, string $slug): string {
    return function_exists('portalItemUrl') ? portalItemUrl('post', $postId, $slug) : clientUrl('posts', ['client' => $slug, 'post' => $postId]);
}

/** 'YYYY-MM-DD HH:MM:SS' (the portal's New York wall clock) → ISO 8601 with offset; null stays null. */
function agentIso($v): ?string {
    if ($v === null || $v === '') return null;
    $ts = strtotime((string)$v);
    return $ts === false ? null : date('c', $ts);
}

/** Does $table.$column exist? (cached per request) */
function agentColumnExists(PDO $pdo, string $table, string $column): bool {
    static $c = [];
    $k = $table . '.' . $column;
    if (isset($c[$k])) return $c[$k];
    $s = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$table, $column]);
    return $c[$k] = (int)$s->fetchColumn() > 0;
}

/** Comma list (or a repeated `name[]=` list) from the query string → trimmed, non-empty strings. */
function agentQueryList(string $name): array {
    $raw = $_GET[$name] ?? null;
    if ($raw === null) return [];
    $items = is_array($raw) ? $raw : explode(',', (string)$raw);
    $out = [];
    foreach ($items as $it) {
        if (!is_string($it)) agentFail(400, "{$name} must be a comma-separated list");
        $it = trim($it);
        if ($it !== '') $out[] = $it;
    }
    return $out;
}

// =====================================================================
// 2. Parts
// =====================================================================

try {
    // ---------------------------------------------------------------- health
    if ($part === 'health') {
        agentReply([
            'configured'  => true,
            'migrated'    => agentMigrated($pdo),
            'chunk_bytes' => agentChunkBytes(),
            'max_media'   => (int)POST_MAX_MEDIA,
            'max_bytes'   => ['image' => uploadMaxBytes('image'), 'video' => uploadMaxBytes('video')],
            'mimes'       => array_keys(AGENT_MIMES),
        ]);
    }

    // ---------------------------------------------------------------- clients
    if ($part === 'clients') {
        $rows = $pdo->query('SELECT slug, name FROM companies ORDER BY name ASC, id ASC')->fetchAll();
        agentReply(['clients' => array_map(static function ($r) { return ['slug' => (string)$r['slug'], 'name' => (string)$r['name']]; }, $rows)]);
    }

    // ---------------------------------------------------------------- media_begin
    if ($part === 'media_begin') {
        $in = agentJsonBody();
        $co = agentCompany($pdo, $in['client'] ?? null);
        $name = agentFileName($in['filename'] ?? null);
        $mime = is_string($in['mime'] ?? null) ? strtolower(trim($in['mime'])) : '';
        if (!isset(AGENT_MIMES[$mime])) agentFail(400, 'mime must be one of ' . implode(', ', array_keys(AGENT_MIMES)));
        $ext = AGENT_MIMES[$mime];
        $isVideo = isVideoExt($ext);
        $bytes = agentPosInt($in['bytes'] ?? null);
        if ($bytes === null) agentFail(400, 'bytes must be a positive integer');
        $limit = uploadMaxBytes($isVideo ? 'video' : 'image');
        if ($bytes > $limit) agentFail(413, ($isVideo ? 'videos' : 'images') . ' must be at most ' . uploadCapLabel($limit), ['limit_bytes' => $limit]);
        $sha = is_string($in['sha256'] ?? null) ? strtolower(trim($in['sha256'])) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) agentFail(400, 'sha256 must be 64 hex characters');
        $root = agentRoot();
        uploadClaimCleanup();   // the shared 24 h sweep (spool pieces, parked files, orphaned sidecars), as chunk_init does
        $chunk = agentChunkBytes();
        $meta = chunkUploadInit($root, [
            'kind' => 'agent', 'purpose' => 'agent', 'client' => (string)$co['slug'], 'company_id' => (int)$co['id'],
            'name' => $name, 'ext' => $ext, 'is_video' => $isVideo, 'size' => $bytes, 'mime' => $mime, 'sha256' => $sha,
            'chunk_bytes' => $chunk,
        ]);
        if ($meta === null) agentFail(500, 'uploads/ is not writable on the server');
        agentReply(['upload_id' => $meta['upload_id'], 'chunk_bytes' => $chunk, 'received' => 0, 'bytes' => $bytes]);
    }

    // ---------------------------------------------------------------- media_chunk
    if ($part === 'media_chunk') {
        $id = is_string($_GET['upload_id'] ?? null) ? strtolower(trim((string)$_GET['upload_id'])) : '';
        if (!chunkUploadValidId($id)) agentFail(400, 'invalid upload_id');
        $root = agentRoot();
        $meta = chunkUploadMeta($root, $id);
        if ($meta === null || ($meta['kind'] ?? '') !== 'agent') agentFail(404, 'unknown upload (it may have expired or finished; begin again)');
        $received = (int)$meta['received'];
        $size     = (int)$meta['size'];
        $max      = max(1, (int)($meta['chunk_bytes'] ?? agentChunkBytes()));
        $offRaw = $_GET['offset'] ?? null;
        if (!is_string($offRaw) || !preg_match('/^\d{1,19}$/', trim($offRaw))) agentFail(400, 'offset must be a non-negative integer', ['received' => $received]);
        $offset = (int)trim($offRaw);
        $ctype = strtolower((string)(requestHeader('Content-Type') ?? ''));
        if (strpos($ctype, 'multipart/') === 0 || strpos($ctype, 'application/x-www-form-urlencoded') === 0) {
            agentFail(400, 'send the piece as the raw body (Content-Type: application/octet-stream)', ['received' => $received]);
        }
        $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? requestHeader('Content-Length') ?? 0);
        if ($declared > $max) agentFail(413, "piece is too large (max {$max} bytes)", ['received' => $received, 'chunk_bytes' => $max]);
        // The piece lands in the spool folder itself (deny-all, known writable — no reliance on the host's /tmp or its
        // open_basedir) under a fresh <32 hex>.part name with no sidecar: never taken for an upload, and swept with the
        // spool's leftovers after 24 h should this request die before its shutdown handler removes it.
        $paths = chunkSpoolPaths($root, $id);
        $tmp = $paths['dir'] . '/' . bin2hex(random_bytes(16)) . '.part';
        $GLOBALS['agentTmpFiles'][] = $tmp;
        $src = fopen('php://input', 'rb');
        $dst = @fopen($tmp, 'xb');
        if ($src === false || $dst === false) agentFail(500, 'could not read the piece');
        @chmod($tmp, 0600);
        $got = (int)stream_copy_to_stream($src, $dst, $max + 1);
        fclose($src);
        fclose($dst);
        if ($got > $max) agentFail(413, "piece is too large (max {$max} bytes)", ['received' => $received, 'chunk_bytes' => $max]);
        if ($got === 0) agentFail(400, 'empty piece', ['received' => $received]);
        // Appended under an exclusive lock; the part file's real size is the truth (chunk-upload-lib.php).
        $r = chunkUploadAppend($root, $id, $tmp, $offset, $size);
        @unlink($tmp);
        if (!empty($r['duplicate']) || (!$r['ok'] && (int)$r['code'] === 409)) {
            agentFail(409, 'offset ' . $offset . ' does not match the bytes received so far (' . (int)$r['received'] . ')', ['received' => (int)$r['received']]);
        }
        if (!$r['ok']) {
            $code = (int)$r['code'];
            $msg = $code === 413 ? 'more bytes than announced in media_begin' : ($code === 404 ? 'unknown upload' : strtolower((string)$r['error']));
            agentFail($code, $msg, ['received' => (int)$r['received']]);
        }
        agentReply(['received' => (int)$r['received'], 'bytes' => $size]);
    }

    // ---------------------------------------------------------------- media_finish
    if ($part === 'media_finish') {
        $in = agentJsonBody();
        $id = is_string($in['upload_id'] ?? null) ? strtolower(trim($in['upload_id'])) : '';
        if (!chunkUploadValidId($id)) agentFail(400, 'invalid upload_id');
        $root = agentRoot();
        $side = agentUploadSidecar($root, $id);
        if ($side === null) agentFail(404, 'unknown upload (it may have expired; begin again)');
        $typeOf = static function (array $m): string { return !empty($m['is_video']) ? 'video' : 'image'; };
        // Finished before (a retried finish after a lost reply): the same token while it is still unclaimed.
        if (!empty($side['media_token'])) {
            $c = uploadClaimRead((string)$side['media_token'], 'agent', (string)$side['client']);
            if ($c === null) agentFail(404, 'unknown upload (already used in a post, or expired; begin again)');
            agentReply(['media_token' => (string)$side['media_token'], 'duplicate' => true, 'bytes' => (int)$c['size'], 'mime' => (string)$side['mime'], 'type' => $typeOf($side)]);
        }
        $meta = chunkUploadMeta($root, $id);   // received = the part file's real size
        if ($meta === null) agentFail(404, 'unknown upload (it may have expired; begin again)');
        $size = (int)$meta['size'];
        if ((int)$meta['received'] !== $size) {
            agentFail(409, 'upload incomplete: ' . (int)$meta['received'] . ' of ' . $size . ' bytes received', ['received' => (int)$meta['received'], 'bytes' => $size]);
        }
        $paths = chunkSpoolPaths($root, $id);
        $sha = (string)@hash_file('sha256', $paths['part']);
        if (!hash_equals((string)$meta['sha256'], $sha)) {
            chunkUploadDiscard($root, $id);
            agentFail(400, 'sha256 mismatch: the file was changed in transit (upload discarded; begin again)', ['received' => 0]);
        }
        // The real type, never the declared one: images must decode as the format their mime names, videos carry the
        // MP4 container magic (upload-lib.php uploadCheckContent — the composer's rule).
        $why = uploadCheckContent($paths['part'], (string)$meta['ext'], !empty($meta['is_video']));
        if ($why !== '') {
            chunkUploadDiscard($root, $id);
            agentFail(400, 'not a valid ' . (string)$meta['mime'] . ' file: ' . $why . ' (upload discarded)', ['received' => 0]);
        }
        $claim = uploadClaimStore($paths['part'], false, [
            'purpose' => 'agent', 'client' => (string)$meta['client'], 'name' => (string)$meta['name'], 'ext' => (string)$meta['ext'],
            'video' => !empty($meta['is_video']), 'mime' => (string)$meta['mime'],
        ]);
        if ($claim === null) agentFail(500, 'failed to save the file (check uploads/ permissions)');
        // The part file is now the parked uploads/tmp_<token>.<ext>; the sidecar stays (swept after 24 h) so a retried
        // finish gets the same token.
        unset($meta['received']);
        $meta['media_token'] = $claim['token'];
        $meta['finished_at'] = time();
        chunkUploadSaveMeta($root, $id, $meta);
        // Previews now, one image per request (they follow the file when the post claims it — upload-lib.php).
        if (empty($meta['is_video']) && function_exists('previewAfterStore')) {
            $abs = uploadClaimFilePath((string)$claim['file']);
            if ($abs !== null) previewAfterStore($abs);
        }
        agentReply(['media_token' => $claim['token'], 'bytes' => (int)$claim['size'], 'mime' => (string)$meta['mime'], 'type' => $typeOf($meta)]);
    }

    // ---------------------------------------------------------------- post
    if ($part === 'post') {
        $in = agentJsonBody();
        $co = agentCompany($pdo, $in['client'] ?? null);
        $cid = (int)$co['id'];
        $slug = (string)$co['slug'];
        $ref = agentRef($in['source_ref'] ?? null);
        // Idempotent on (client, source_ref): a repeat changes nothing — its media tokens were spent by the first one.
        $dup = agentPostByRef($pdo, $cid, $ref);
        if ($dup) agentReply(['duplicate' => true, 'post_id' => (int)$dup['id'], 'status' => (string)$dup['status'], 'admin_url' => agentAdminUrl((int)$dup['id'], $slug)]);

        $caption  = agentText($in['caption'] ?? null, AGENT_CAPTION_MAX, 'caption');
        $hashtags = agentHashtags($in['hashtags'] ?? null);
        $when     = agentDate($in['scheduled_date'] ?? null);
        $type     = agentPostType($in['post_type'] ?? null);
        $note     = agentText($in['note'] ?? null, AGENT_NOTE_MAX, 'note');
        $tokens   = agentTokens($in['media_tokens'] ?? null);
        $claims = [];
        foreach ($tokens as $i => $t) {
            $c = uploadClaimRead($t, 'agent', $slug);   // this client's own finished uploads only
            if ($c === null) agentFail(400, "media_tokens[{$i}] is unknown, expired or already used", ['index' => $i]);
            $claims[] = $c;
        }
        if ($type === '' && count($claims) === 1 && !empty($claims[0]['video'])) $type = 'reel';   // the portal's auto rule
        if ($type === '') $type = 'post';

        $uploads = agentRoot();
        $hasType  = hasPostTypeColumn($pdo);
        $hasMedia = hasMediaTypeColumn($pdo);
        $created = [];   // files placed by this request (unlinked if it fails)
        $images  = [];
        try {
            $pdo->beginTransaction();
            $cols = ['company_id', 'caption', 'hashtags', 'scheduled_date', 'status', 'source_ref'];
            $vals = [$cid, $caption, $hashtags, $when, 'draft', $ref];
            if ($hasType) { $cols[] = 'post_type'; $vals[] = $type; }
            try {
                // The row first: the unique key (company_id, source_ref) makes a concurrent twin wait here, then fail,
                // before either has touched a file.
                $pdo->prepare('INSERT INTO posts (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($vals), '?')) . ')')->execute($vals);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                $pdo->rollBack();
                $dup = agentPostByRef($pdo, $cid, $ref);
                if (!$dup) throw $e;
                agentReply(['duplicate' => true, 'post_id' => (int)$dup['id'], 'status' => (string)$dup['status'], 'admin_url' => agentAdminUrl((int)$dup['id'], $slug)]);
            }
            $postId = (int)$pdo->lastInsertId();
            $ins = $hasMedia ? $pdo->prepare('INSERT INTO post_images (post_id, image_url, media_type, sort_order) VALUES (?, ?, ?, ?)')
                             : $pdo->prepare('INSERT INTO post_images (post_id, image_url, sort_order) VALUES (?, ?, ?)');
            foreach ($claims as $i => $c) {
                $isV  = !empty($c['video']);
                $file = uploadFreshName($isV ? 'vid_' : 'img_', (string)$c['ext']);
                $abs  = $uploads . '/' . $file;
                if (!uploadClaimTake($c, $abs)) throw new RuntimeException("could not move media_tokens[{$i}] into uploads/");
                $created[] = $abs;
                if ($hasMedia) $ins->execute([$postId, 'uploads/' . $file, $isV ? 'video' : 'image', $i + 1]);
                else $ins->execute([$postId, 'uploads/' . $file, $i + 1]);
                if (!$isV) $images[] = $abs;
            }
            // Activity: Joust-only rows (internal), no author, source 'agent' — nothing here reaches the client, Slack
            // (notify-lib.php skips the internal-note message for this source) or a client email.
            $label = postDisplayLabel(['name' => '', 'caption' => $caption, 'id' => $postId]);
            $n = count($claims);
            $batch = newBatchId();
            $ctx = ['internal' => 1, 'author_user_id' => null, 'source' => 'agent'];
            activityWithContext($ctx, static function () use ($pdo, $cid, $postId, $label, $n, $ref, $batch) {
                logActivity($pdo, $cid, 'post', $postId, 'drafted', 'admin', "Agent team delivered a draft #{$postId}: {$label}",
                    $n . ' slide' . ($n === 1 ? '' : 's') . ' · from the agent team · source_ref ' . mb_substr($ref, 0, 190), $batch);
            });
            if ($note !== '') {
                activityWithContext($ctx, static function () use ($pdo, $cid, $postId, $label, $note, $batch) {
                    logActivity($pdo, $cid, 'post', $postId, 'commented', 'admin', "Internal note on {$label}", $note, $batch);
                });
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($created as $f) { if (is_file($f)) { if (function_exists('previewDelete')) previewDelete($f); @unlink($f); } }
            throw $e;
        }
        // sm / lg previews: normally they came along with the files (made at media_finish) — this only fills gaps.
        if (function_exists('previewAfterStore')) { foreach ($images as $abs) previewAfterStore($abs); }
        agentReply(['post_id' => $postId, 'status' => 'draft', 'admin_url' => agentAdminUrl($postId, $slug), 'media' => count($claims)]);
    }

    // ---------------------------------------------------------------- status
    if ($part === 'status') {
        $co = agentCompany($pdo, $_GET['client'] ?? null);
        $cid = (int)$co['id'];
        $ids = [];
        foreach (agentQueryList('ids') as $v) {
            if (!preg_match('/^[1-9]\d{0,9}$/', $v)) agentFail(400, 'ids must be a comma-separated list of post ids');
            $ids[(int)$v] = (int)$v;
        }
        $refs = [];
        foreach (agentQueryList('source_refs') as $v) $refs[$v] = agentRef($v, 'source_refs');
        $ids = array_values($ids);
        $refs = array_values($refs);
        if (!$ids && !$refs) agentFail(400, 'ids or source_refs is required');
        if (count($ids) + count($refs) > AGENT_LIST_MAX) agentFail(400, 'at most ' . AGENT_LIST_MAX . ' ids / source_refs per call');

        $postedSel  = hasPostedColumn($pdo) ? 'p.posted' : '0';
        $trashedSel = function_exists('trashReady') && trashReady($pdo, 'post') ? 'p.trashed_at' : 'NULL';
        $updatedSel = agentColumnExists($pdo, 'posts', 'updated_at') ? 'p.updated_at' : 'NULL';
        $where = [];
        $params = [$cid];
        if ($ids)  { $where[] = 'p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';         $params = array_merge($params, $ids); }
        if ($refs) { $where[] = 'p.source_ref IN (' . implode(',', array_fill(0, count($refs), '?')) . ')'; $params = array_merge($params, $refs); }
        $s = $pdo->prepare("SELECT p.id, p.source_ref, p.status, p.scheduled_date, {$postedSel} AS posted, {$trashedSel} AS trashed_at,
                                   {$updatedSel} AS updated_at, p.created_at
                              FROM posts p WHERE p.company_id = ? AND (" . implode(' OR ', $where) . ') ORDER BY p.id ASC');
        $s->execute($params);
        $rows = $s->fetchAll();

        // The client's latest note / comment per post: its own rows only, never an internal note, never a deleted comment.
        $notes = [];
        if ($rows && hasActivityLog($pdo)) {
            $pids = array_map(static function ($r) { return (int)$r['id']; }, $rows);
            $internal = function_exists('activityHasNotifyCols') && activityHasNotifyCols($pdo) ? ' AND internal = 0' : '';
            $deleted  = function_exists('commentEditReady') && commentEditReady($pdo) ? ' AND deleted_at IS NULL' : '';
            $n = $pdo->prepare("SELECT entity_id, detail FROM activity_log
                                 WHERE company_id = ? AND entity_type = 'post' AND action = 'commented' AND actor = 'client'
                                   AND detail IS NOT NULL AND detail <> ''{$internal}{$deleted}
                                   AND entity_id IN (" . implode(',', array_fill(0, count($pids), '?')) . ')
                                 ORDER BY created_at ASC, id ASC');
            $n->execute(array_merge([$cid], $pids));
            foreach ($n->fetchAll() as $r) $notes[(int)$r['entity_id']] = (string)$r['detail'];
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $out[] = [
                'id'               => $id,
                'source_ref'       => $r['source_ref'] !== null ? (string)$r['source_ref'] : null,
                'status'           => (string)$r['status'],
                'scheduled_date'   => !empty($r['scheduled_date']) ? substr((string)$r['scheduled_date'], 0, 10) : null,
                'posted'           => !empty($r['posted']),
                'trashed'          => !empty($r['trashed_at']),
                'updated_at'       => agentIso($r['updated_at'] ?: $r['created_at']),
                'last_client_note' => $notes[$id] ?? null,
            ];
        }
        agentReply(['posts' => $out]);
    }

    agentFail(400, 'unknown part');
} catch (Throwable $e) {
    agentServerError('part=' . $part, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    exit;
}

