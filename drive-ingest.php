<?php
/**
 * Drive snapshot ingest — the portal's only machine-auth endpoint (contract: scratchpad drive-design.md §2).
 *
 * The nightly Apps Script (docs/drive-collector/Code.gs) measures the agency Google Drive and posts the
 * result here in parts, so a large account never hits a request-size or time limit:
 *
 *   GET  ?health=1                       → {ok, configured, migrated, last_snapshot_at, last_snapshot_id, status, partial_snapshot_id}
 *   POST ?part=begin      {takenAt, quota{limit|null, usage, drive, trash, other}, quotaNote?, account{email?}, meta{…}}
 *                                        → {ok, snapshot_id}   (new drive_snapshots row, status 'partial')
 *   POST ?part=files      {snapshot_id, rows:[{id,name,mimeType,size,parentId?,modifiedTime?,viewedByMeTime?,createdTime?,md5?,webViewLink?}], batch?}
 *   POST ?part=folders    {snapshot_id, rows:[{id,name,parentId?,path,depth,clientSlug?,bytes,staleBytes,fileCount,lastActivityAt?,webViewLink?}]}
 *   POST ?part=clients    {snapshot_id, rows:[{slug,name,folderId?,bytes,staleBytes,fileCount,lastActivityAt?,webLink?}]}
 *   POST ?part=tree       {snapshot_id, tree:{id,name,path,bytes,staleBytes,fileCount,lastActivityAt,webLink,clientSlug?,truncated?,children[]}}
 *   POST ?part=quickwins  {snapshot_id, trash{bytes}}                       (optional)
 *   POST ?part=state      {snapshot_id, state:<any JSON>}  /  GET ?part=state&snapshot_id=N   (script scratch for resuming)
 *   POST ?part=finish     {snapshot_id, meta{durationMs?, fileCount?, folderCount?, listedBytes?}}
 *                                        → {ok, status:'complete', totals, quota, projection, alerts_due:[…], activity_logged}
 *   POST ?part=alerted    {snapshot_id, kinds:['pct80', …]}                → {ok, recorded}
 *
 * Auth: `Authorization: Bearer <drive_ingest_secret>` (config.php) — or `X-Drive-Secret: <secret>` for hosts
 * that strip Authorization before PHP sees it — compared in constant time. Headers are read through
 * requestHeader() (drive-lib.php): $_SERVER HTTP_* / REDIRECT_HTTP_* first, getallheaders() only where the
 * SAPI has it. No session, no same-site check: this file loads db.php + drive-lib.php only, never helpers.php
 * (which resolves the admin session and the UI chrome at load).
 * JSON bodies only, DRIVE_MAX_BODY (8 MB) cap. Codes: 400 validation · 401 auth · 404 unknown snapshot ·
 * 405 method · 409 state (parts after finish, finish with parts missing) · 413 body · 503 not configured /
 * tables missing · 500 {ok:false, error:'server error'} for anything unexpected (logged via error_log; with
 * `?debug=1` and a valid secret the reply also carries `detail` = "<class>: <message>" and `at` = file:line).
 * A fatal anywhere — even inside an include — still answers that JSON: the shutdown handler below is
 * registered before the first require. Every part runs in one transaction. Idempotency: the body's sha256 (or the
 * X-Drive-Part-Hash header) is recorded per part in drive_snapshots.part_hashes — a repeat answers
 * {ok:true, duplicate:true} and changes nothing; files / folders rows are also INSERT IGNOREd on their
 * (snapshot_id, id) unique key.
 *
 * What the server recomputes at finish from the files it received (the script streams every owned,
 * non-trashed file that uses quota): type bucket, idle days, raw score → score, candidates, by-type totals,
 * duplicate + old-version groups, per-client candidate counts, the burn rate / days-to-full from the
 * snapshot history, per-client 30-day growth, which threshold alerts are due; then it prunes drive_files
 * to candidates + the 1,000 largest + the 50 largest per client and drops detail older than the newest 7
 * complete snapshots. Folder rollups, the client rollup and the tree are the script's (it sees every file).
 */

// =====================================================================
// 0. Fail-safe bootstrap — JSON no matter what happens below.
//    Registered before any include, so a fatal inside db.php / drive-lib.php (or a PHP build that
//    lacks a function we call) still answers {ok:false, error:'server error'} with HTTP 500 and a
//    line in error_log, instead of the host's blank 500 page that tells the collector nothing.
// =====================================================================

header('Content-Type: application/json');
header('Cache-Control: no-store');
ini_set('display_errors', '0');      // never let a PHP diagnostic (HTML on most hosts) into the reply
ini_set('html_errors', '0');
error_reporting(E_ALL);
ob_start();                           // whatever a notice or fatal managed to print is dropped by driveIngestEmit()

/** The secret as presented (Bearer, else X-Drive-Secret), readable before drive-lib.php is loaded. */
function driveIngestPresented(): string {
    $read = static function (string $name): string {
        if (function_exists('requestHeader')) return (string)(requestHeader($name) ?? '');
        $k = strtoupper(str_replace('-', '_', $name));
        return (string)($_SERVER['HTTP_' . $k] ?? $_SERVER['REDIRECT_HTTP_' . $k] ?? '');
    };
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $read('Authorization'), $m)) return $m[1];
    return trim($read('X-Drive-Secret'));
}
/** ?debug=1 together with a presented secret that matches config.php → error replies carry the detail. */
function driveIngestDebug(): bool {
    if (($_GET['debug'] ?? '') !== '1') return false;
    $c = $GLOBALS['config'] ?? null;
    if (!is_array($c)) {
        $file = __DIR__ . '/config.php';
        $c = is_file($file) ? (require $file) : [];
        if (!is_array($c)) $c = [];
    }
    $secret = trim((string)($c['drive_ingest_secret'] ?? ''));
    $given = driveIngestPresented();
    return strlen($secret) >= 24 && $given !== '' && hash_equals($secret, $given);
}
/** Drop every output buffer and send exactly one JSON document with the given status. */
function driveIngestEmit(int $code, array $payload): void {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
/** 500 for anything unexpected: rolled back, logged in full, generic to the caller unless debugging. */
function driveIngestServerError(string $where, string $class, string $message, string $file, int $line): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) { try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e) { /* connection closes anyway */ } }
    error_log("drive-ingest {$where}: {$class}: {$message} in {$file}:{$line}");
    $out = ['ok' => false, 'error' => 'server error'];
    if (driveIngestDebug()) {
        $out['detail'] = $class . ': ' . $message;
        $out['at']     = basename($file) . ':' . $line;
    }
    driveIngestEmit(500, $out);
}
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) return;
    driveIngestServerError('fatal', 'Fatal', (string)$e['message'], (string)$e['file'], (int)$e['line']);
});

// The endpoint needs $pdo and the drive helpers, nothing else. helpers.php is deliberately not loaded:
// it resolves the admin session at load (currentAdmin() → session_start when the jsm_admin cookie is
// present), pulls in auth.php and the UI partials, and none of that belongs in a machine-auth JSON
// endpoint. drive-lib.php is function definitions only.
require __DIR__ . '/db.php';
require_once __DIR__ . '/drive-lib.php';

const DRIVE_INGEST_MAX_FILE_ROWS   = 2000;
const DRIVE_INGEST_MAX_FOLDER_ROWS = 5000;
const DRIVE_INGEST_MAX_CLIENTS     = 500;
const DRIVE_INGEST_MAX_TREE_BYTES  = 4 * 1024 * 1024;
const DRIVE_INGEST_MAX_TREE_NODES  = 20000;
const DRIVE_INGEST_INSERT_CHUNK    = 200;
const DRIVE_INGEST_GROUP_MEMBERS   = 100;    // members stored per duplicate / old-version group (files_json is TEXT)

function driveFail(int $code, string $msg, array $extra = []): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e) { /* connection closes anyway */ } }
    driveIngestEmit($code, ['ok' => false, 'error' => $msg] + $extra);
    exit;
}
function driveReply(array $data): void {
    driveIngestEmit(200, ['ok' => true] + $data);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'POST') {
        driveFail(405, 'Method not allowed');
    }

    // ---- Auth: bearer secret from config.php, constant-time ----
    $cfg = driveConfig();
    if (!$cfg['configured']) {
        driveFail(503, 'Drive ingest is not configured: set drive_ingest_secret (24+ characters) in config.php');
    }
    $presented = driveIngestPresented();
    if ($presented === '' || !hash_equals($cfg['ingest_secret'], $presented)) {
        driveFail(401, 'Unauthorized');
    }

    // QA hook (never set in production): DRIVE_INGEST_TEST_FAIL=throw | fatal makes the request fail right
    // here — after auth, so the 401 still wins — to prove the JSON 500 paths end to end. 'fatal' redeclares
    // a function, an E_COMPILE_ERROR no catch block sees; only the shutdown handler above can answer it.
    $hook = getenv('DRIVE_INGEST_TEST_FAIL');
    if ($hook === 'throw') throw new RuntimeException('test hook: thrown after auth');
    if ($hook === 'fatal') eval('function driveFail() {}');

    $migrated = hasDriveTables($pdo);

    // ---- Health (GET) ----
    if ($method === 'GET' && isset($_GET['health'])) {
        // last_snapshot_* = the newest row of any status (so the script sees its own partial run);
        // last_complete_* = the newest complete one (what drive.php shows); stale = that one is > 48 h old or missing.
        $out = ['configured' => true, 'migrated' => $migrated, 'last_snapshot_at' => null, 'last_snapshot_id' => null, 'status' => null,
                'last_complete_id' => null, 'last_complete_at' => null, 'stale' => true, 'partial_snapshot_id' => null];
        if ($migrated) {
            $latest = driveLatestSnapshot($pdo, false);
            if ($latest !== null) {
                $out['last_snapshot_at'] = $latest['taken_at'];
                $out['last_snapshot_id'] = $latest['id'];
                $out['status']           = $latest['status'];
            }
            $complete = $latest !== null && $latest['status'] === 'complete' ? $latest : driveLatestSnapshot($pdo, true);
            if ($complete !== null) {
                $out['last_complete_id'] = $complete['id'];
                $out['last_complete_at'] = $complete['taken_at'];
                $out['stale']            = driveSnapshotIsStale($complete);
            }
            $p = $pdo->prepare("SELECT id FROM drive_snapshots WHERE status = 'partial' ORDER BY id DESC LIMIT 1");
            $p->execute();
            $pid = $p->fetchColumn();
            $out['partial_snapshot_id'] = $pid ? (int)$pid : null;
        }
        driveReply($out);
    }

    if (!$migrated) {
        driveFail(503, 'Drive tables are missing: open migrate.php while signed in as admin');
    }

    $part = is_string($_GET['part'] ?? null) ? trim($_GET['part']) : '';
    $parts = ['begin', 'files', 'folders', 'clients', 'tree', 'quickwins', 'state', 'finish', 'alerted'];
    if (!in_array($part, $parts, true)) {
        driveFail(400, 'Unknown part');
    }
    if ($method === 'GET' && $part !== 'state') {
        driveFail(405, 'Method not allowed');
    }

    // ---- Body: a JSON object, bounded ----
    $in = [];
    $bodyHash = '';
    if ($method === 'POST') {
        $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? requestHeader('Content-Length') ?? 0);
        if ($declared > DRIVE_MAX_BODY) {
            driveFail(413, 'Request body is too large (max ' . driveFormatBytes(DRIVE_MAX_BODY) . ')');
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, DRIVE_MAX_BODY + 1);
        if (strlen($raw) > DRIVE_MAX_BODY) {
            driveFail(413, 'Request body is too large (max ' . driveFormatBytes(DRIVE_MAX_BODY) . ')');
        }
        $decoded = json_decode($raw, true, 64);
        if (!is_array($decoded) || array_is_list($decoded)) {
            driveFail(400, 'Invalid JSON body (expected an object)');
        }
        $in = $decoded;
        $hdr = strtolower(trim((string)(requestHeader('X-Drive-Part-Hash') ?? '')));
        $bodyHash = preg_match('/^[a-f0-9]{64}$/', $hdr) ? $hdr : hash('sha256', $raw);
        unset($raw);
    }
} catch (Throwable $e) {
    driveIngestServerError('request', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    exit;
}

// =====================================================================
// Validation helpers (400 with the row index in the message)
// =====================================================================

/** Google file / folder id ([A-Za-z0-9_-]{10,}); 'root' / 'unfiled' allowed when $special. */
function driveVId($v, bool $special = false): ?string {
    if (!is_string($v)) return null;
    $v = trim($v);
    if ($special && ($v === 'root' || $v === 'unfiled')) return $v;
    return preg_match('/^[A-Za-z0-9_-]{10,64}$/', $v) ? $v : null;
}
/** Non-negative byte count from an int, float or digit string. */
function driveVBytes($v): ?int {
    if (is_int($v)) return $v >= 0 ? $v : null;
    if (is_float($v)) return ($v >= 0 && $v <= PHP_INT_MAX && floor($v) === $v) ? (int)$v : null;
    if (is_string($v) && preg_match('/^\d{1,19}$/', trim($v))) { $n = (int)trim($v); return $n >= 0 ? $n : null; }
    return null;
}
/** Bounded int (null when absent / not numeric). */
function driveVInt($v, int $min, int $max): ?int {
    if (is_bool($v) || $v === null || $v === '') return null;
    if (!is_numeric($v)) return null;
    $n = (int)$v;
    return ($n >= $min && $n <= $max) ? $n : null;
}
/** Trimmed, whitespace-collapsed string cut to $max characters ('' when absent). */
function driveVStr($v, int $max): string {
    if (!is_scalar($v)) return '';
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '');
    if ($s === '') return '';
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
}
/** https URL ≤ 255 chars or null. */
function driveVUrl($v): ?string {
    $s = driveVStr($v, 255);
    return ($s !== '' && preg_match('#^https://[^\s"<>]+$#', $s)) ? $s : null;
}
/** Client slug ([a-z0-9-]{1,120}) or null. */
function driveVSlug($v): ?string {
    if (!is_string($v)) return null;
    $s = strtolower(trim($v));
    return preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/', $s) ? $s : null;
}
/** Optional date: null when absent, 400 when present but unparseable. */
function driveVDate($v, string $what): ?string {
    if ($v === null || $v === '') return null;
    $d = driveParseDate($v);
    if ($d === null) driveFail(400, "$what is not an ISO-8601 date");
    return $d;
}

// =====================================================================
// Snapshot row helpers
// =====================================================================

/** Lock + fetch the snapshot named in the body (404 unknown). */
function driveLoadSnapshot(PDO $pdo, array $in): array {
    $id = driveVInt($in['snapshot_id'] ?? null, 1, PHP_INT_MAX);
    if ($id === null) driveFail(400, 'snapshot_id is required');
    $s = $pdo->prepare('SELECT * FROM drive_snapshots WHERE id = ? FOR UPDATE');
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) driveFail(404, 'Unknown snapshot_id');
    return $row;
}
/** part_hashes JSON → array. */
function driveHashes(array $snap): array {
    $h = is_string($snap['part_hashes'] ?? null) && $snap['part_hashes'] !== '' ? json_decode($snap['part_hashes'], true) : null;
    return is_array($h) ? $h : [];
}
/** Has this exact body been accepted for the part before? (files / folders keep a list; the rest one hash.) */
function driveHashSeen(array $hashes, string $part, string $hash): bool {
    if ($part === 'files' || $part === 'folders') return isset($hashes[$part]) && is_array($hashes[$part]) && array_key_exists($hash, $hashes[$part]);
    return ($hashes[$part] ?? null) === $hash;
}
function driveHashRecord(PDO $pdo, int $snapshotId, array $hashes, string $part, string $hash, int $rows = 0): void {
    if ($part === 'files' || $part === 'folders') {
        if (!isset($hashes[$part]) || !is_array($hashes[$part])) $hashes[$part] = [];
        $hashes[$part][$hash] = $rows;
    } else {
        $hashes[$part] = $hash;
    }
    $u = $pdo->prepare('UPDATE drive_snapshots SET part_hashes = ? WHERE id = ?');
    $u->execute([json_encode($hashes, JSON_UNESCAPED_SLASHES), $snapshotId]);
}
/** Multi-row INSERT IGNORE in chunks; returns rows actually inserted. */
function driveInsertRows(PDO $pdo, string $table, array $cols, array $rows): int {
    if (!$rows) return 0;
    $inserted = 0;
    $n = count($cols);
    $one = '(' . implode(',', array_fill(0, $n, '?')) . ')';
    foreach (array_chunk($rows, DRIVE_INGEST_INSERT_CHUNK) as $chunk) {
        $sql = "INSERT IGNORE INTO {$table} (" . implode(', ', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $one));
        $params = [];
        foreach ($chunk as $r) foreach ($r as $v) $params[] = $v;
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $inserted += (int)$s->rowCount();
    }
    return $inserted;
}

// =====================================================================
// Parts
// =====================================================================

try {
    $pdo->beginTransaction();

    // ---------------------------------------------------------------- begin
    if ($part === 'begin') {
        $takenAt = driveVDate($in['takenAt'] ?? null, 'takenAt');
        if ($takenAt === null) driveFail(400, 'takenAt is required');
        $q = is_array($in['quota'] ?? null) ? $in['quota'] : null;
        if ($q === null) driveFail(400, 'quota is required');
        $limit = array_key_exists('limit', $q) && $q['limit'] !== null && $q['limit'] !== '' ? driveVBytes($q['limit']) : null;
        if (array_key_exists('limit', $q) && $q['limit'] !== null && $q['limit'] !== '' && $limit === null) driveFail(400, 'quota.limit must be a byte count or null');
        $usage = driveVBytes($q['usage'] ?? null);
        $drive = driveVBytes($q['drive'] ?? null);
        $trash = driveVBytes($q['trash'] ?? 0);
        if ($usage === null || $drive === null || $trash === null) driveFail(400, 'quota.usage, quota.drive and quota.trash must be byte counts');
        if ($trash > $drive) driveFail(400, 'quota.trash cannot exceed quota.drive');
        $note = driveVStr($in['quotaNote'] ?? '', 255);
        $other = max(0, $usage - $drive);
        $sentOther = array_key_exists('other', $q) ? driveVBytes($q['other']) : null;
        if ($sentOther !== null && $sentOther !== $other) {
            $extra = 'other recomputed as usage − drive (' . driveFormatBytes($other) . ')';
            $note = $note === '' ? $extra : driveVStr($note . '; ' . $extra, 255);
        }
        if ($drive > $usage) {
            $extra = 'Drive usage exceeds the account total by ' . driveFormatBytes($drive - $usage);
            $note = $note === '' ? $extra : driveVStr($note . '; ' . $extra, 255);
        }
        $account = is_array($in['account'] ?? null) ? $in['account'] : [];
        $email = driveVStr($account['email'] ?? ($account['emailAddress'] ?? ''), 255);
        $meta = is_array($in['meta'] ?? null) ? $in['meta'] : [];
        $ins = $pdo->prepare("INSERT INTO drive_snapshots
            (taken_at, status, quota_limit, usage_bytes, drive_bytes, trash_bytes, other_bytes, quota_note, account_email, script_version,
             file_count, folder_count, listed_bytes, part_hashes, created_at)
            VALUES (?, 'partial', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $ins->execute([
            $takenAt, $limit, $usage, $drive, $trash, $other, $note !== '' ? $note : null, $email !== '' ? $email : null,
            driveVStr($meta['scriptVersion'] ?? '', 40) ?: null,
            driveVInt($meta['fileCount'] ?? 0, 0, PHP_INT_MAX) ?? 0,
            driveVInt($meta['folderCount'] ?? 0, 0, PHP_INT_MAX) ?? 0,
            driveVBytes($meta['listedBytes'] ?? 0) ?? 0,
            json_encode(['begin' => $bodyHash]),
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        driveReply(['snapshot_id' => $id, 'taken_at' => $takenAt]);
    }

    // ---------------------------------------------------------------- state (GET)
    if ($part === 'state' && $method === 'GET') {
        $snap = driveLoadSnapshot($pdo, ['snapshot_id' => $_GET['snapshot_id'] ?? null]);
        $pdo->commit();
        $state = is_string($snap['state_json'] ?? null) && $snap['state_json'] !== '' ? json_decode($snap['state_json'], true) : null;
        driveReply(['snapshot_id' => (int)$snap['id'], 'status' => $snap['status'], 'state' => $state]);
    }

    $snap = driveLoadSnapshot($pdo, $in);
    $snapshotId = (int)$snap['id'];
    $hashes = driveHashes($snap);

    if ($snap['status'] === 'complete' && !in_array($part, ['finish', 'alerted'], true)) {
        driveFail(409, 'Snapshot is already complete');
    }
    if ($snap['status'] === 'failed') {
        driveFail(409, 'Snapshot was abandoned (partial for more than ' . DRIVE_STALE_SNAPSHOT_HOURS . ' hours); begin a new one');
    }
    if ($part !== 'finish' && $part !== 'alerted' && $part !== 'state' && driveHashSeen($hashes, $part, $bodyHash)) {
        $pdo->commit();
        driveReply(['snapshot_id' => $snapshotId, 'duplicate' => true]);
    }
    $takenTs = strtotime((string)$snap['taken_at']) ?: time();

    // ---------------------------------------------------------------- files
    if ($part === 'files') {
        $rows = $in['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) driveFail(400, 'rows must be a list');
        if (count($rows) > DRIVE_INGEST_MAX_FILE_ROWS) driveFail(400, 'Too many rows (max ' . DRIVE_INGEST_MAX_FILE_ROWS . ' per request)');
        $out = []; $ignored = 0;
        foreach ($rows as $i => $r) {
            if (!is_array($r)) driveFail(400, "rows[$i] is not an object");
            $id = driveVId($r['id'] ?? null);
            if ($id === null) driveFail(400, "rows[$i].id is not a Drive file id");
            $mime = driveVStr($r['mimeType'] ?? '', 120);
            if ($mime === 'application/vnd.google-apps.folder') { $ignored++; continue; }   // folders belong in ?part=folders
            $bytes = driveVBytes($r['size'] ?? ($r['quotaBytesUsed'] ?? ($r['bytes'] ?? null)));
            if ($bytes === null) driveFail(400, "rows[$i].size must be a byte count");
            $name = driveVStr($r['name'] ?? '', 255);
            if ($name === '') $name = '(untitled)';
            $parent = isset($r['parentId']) && $r['parentId'] !== null && $r['parentId'] !== '' ? driveVId($r['parentId'], true) : null;
            if (isset($r['parentId']) && $r['parentId'] !== null && $r['parentId'] !== '' && $parent === null) driveFail(400, "rows[$i].parentId is not a Drive folder id");
            $modified = driveVDate($r['modifiedTime'] ?? null, "rows[$i].modifiedTime");
            $viewed   = driveVDate($r['viewedByMeTime'] ?? null, "rows[$i].viewedByMeTime");
            $created  = driveVDate($r['createdTime'] ?? null, "rows[$i].createdTime");
            $md5 = isset($r['md5']) ? strtolower(trim((string)$r['md5'])) : (isset($r['md5Checksum']) ? strtolower(trim((string)$r['md5Checksum'])) : '');
            if ($md5 !== '' && !preg_match('/^[a-f0-9]{32}$/', $md5)) driveFail(400, "rows[$i].md5 is not an md5");
            $idle = driveIdleDays($modified, $viewed, $takenTs);
            $raw  = driveRawScore($bytes, $idle);
            $out[] = [$snapshotId, $id, $name, $mime, $parent, driveTypeBucket($mime, $name), $bytes, $modified, $viewed, $created,
                      $idle, $raw, 0, driveIsCandidate($bytes, $idle) ? 1 : 0, $md5 !== '' ? $md5 : null, driveVUrl($r['webViewLink'] ?? ($r['webLink'] ?? null))];
        }
        $inserted = driveInsertRows($pdo, 'drive_files',
            ['snapshot_id', 'file_id', 'name', 'mime_type', 'parent_id', 'type_bucket', 'bytes', 'modified_at', 'viewed_at', 'created_at_drive',
             'idle_days', 'raw_score', 'score', 'is_candidate', 'md5', 'web_link'], $out);
        $ignored += count($out) - $inserted;
        driveHashRecord($pdo, $snapshotId, $hashes, 'files', $bodyHash, count($out));
        $pdo->commit();
        driveReply(['snapshot_id' => $snapshotId, 'inserted' => $inserted, 'ignored' => $ignored, 'batch' => driveVInt($in['batch'] ?? null, 0, PHP_INT_MAX)]);
    }

    // ---------------------------------------------------------------- folders
    if ($part === 'folders') {
        $rows = $in['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) driveFail(400, 'rows must be a list');
        if (count($rows) > DRIVE_INGEST_MAX_FOLDER_ROWS) driveFail(400, 'Too many rows (max ' . DRIVE_INGEST_MAX_FOLDER_ROWS . ' per request)');
        $out = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r)) driveFail(400, "rows[$i] is not an object");
            $id = driveVId($r['id'] ?? null, true);
            if ($id === null) driveFail(400, "rows[$i].id is not a Drive folder id");
            $name = driveVStr($r['name'] ?? '', 255);
            if ($name === '') $name = $id === 'root' ? 'My Drive' : '(untitled)';
            $parent = isset($r['parentId']) && $r['parentId'] !== null && $r['parentId'] !== '' ? driveVId($r['parentId'], true) : null;
            if (isset($r['parentId']) && $r['parentId'] !== null && $r['parentId'] !== '' && $parent === null) driveFail(400, "rows[$i].parentId is not a Drive folder id");
            $path = driveVStr($r['path'] ?? '', 1024);
            if ($path === '') $path = '/' . $name;
            $depth = driveVInt($r['depth'] ?? 0, 0, 64);
            if ($depth === null) driveFail(400, "rows[$i].depth must be 0–64");
            $slug = null;
            if (isset($r['clientSlug']) && $r['clientSlug'] !== null && $r['clientSlug'] !== '') {
                $slug = driveVSlug($r['clientSlug']);
                if ($slug === null) driveFail(400, "rows[$i].clientSlug is not a slug");
            }
            $bytes = driveVBytes($r['bytes'] ?? 0); $stale = driveVBytes($r['staleBytes'] ?? 0); $count = driveVInt($r['fileCount'] ?? 0, 0, PHP_INT_MAX);
            if ($bytes === null || $stale === null || $count === null) driveFail(400, "rows[$i]: bytes, staleBytes and fileCount must be counts");
            $last = driveVDate($r['lastActivityAt'] ?? null, "rows[$i].lastActivityAt");
            $out[] = [$snapshotId, $id, $parent, $name, $path, $depth, $slug, $bytes, min($stale, $bytes), $count, $last,
                      driveVUrl($r['webViewLink'] ?? ($r['webLink'] ?? null)) ?? ($id === 'root' || $id === 'unfiled' ? null : driveParentLink($id))];
        }
        $inserted = driveInsertRows($pdo, 'drive_folders',
            ['snapshot_id', 'folder_id', 'parent_id', 'name', 'path', 'depth', 'client_slug', 'bytes', 'stale_bytes', 'file_count', 'last_activity_at', 'web_link'], $out);
        driveHashRecord($pdo, $snapshotId, $hashes, 'folders', $bodyHash, count($out));
        $pdo->commit();
        driveReply(['snapshot_id' => $snapshotId, 'inserted' => $inserted, 'ignored' => count($out) - $inserted]);
    }

    // ---------------------------------------------------------------- clients
    if ($part === 'clients') {
        $rows = $in['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) driveFail(400, 'rows must be a list');
        if (count($rows) > DRIVE_INGEST_MAX_CLIENTS) driveFail(400, 'Too many rows (max ' . DRIVE_INGEST_MAX_CLIENTS . ')');
        $out = []; $seen = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r)) driveFail(400, "rows[$i] is not an object");
            $slug = driveVSlug($r['slug'] ?? null);
            if ($slug === null) driveFail(400, "rows[$i].slug is not a slug");
            if (isset($seen[$slug])) driveFail(400, "rows[$i].slug '$slug' repeats");
            $seen[$slug] = true;
            $folderId = isset($r['folderId']) && $r['folderId'] !== null && $r['folderId'] !== '' ? driveVId($r['folderId'], true) : null;
            if (isset($r['folderId']) && $r['folderId'] !== null && $r['folderId'] !== '' && $folderId === null) driveFail(400, "rows[$i].folderId is not a Drive folder id");
            $bytes = driveVBytes($r['bytes'] ?? 0); $stale = driveVBytes($r['staleBytes'] ?? 0); $count = driveVInt($r['fileCount'] ?? 0, 0, PHP_INT_MAX);
            if ($bytes === null || $stale === null || $count === null) driveFail(400, "rows[$i]: bytes, staleBytes and fileCount must be counts");
            $out[] = [
                'slug'           => $slug,
                'name'           => $slug === 'unfiled' ? '(unfiled)' : (driveVStr($r['name'] ?? '', 120) ?: $slug),
                'folderId'       => $folderId,
                'bytes'          => $bytes,
                'staleBytes'     => min($stale, $bytes),
                'fileCount'      => $count,
                'lastActivityAt' => driveVDate($r['lastActivityAt'] ?? null, "rows[$i].lastActivityAt"),
                'webLink'        => driveVUrl($r['webLink'] ?? ($r['webViewLink'] ?? null)) ?? ($folderId !== null && $folderId !== 'root' && $folderId !== 'unfiled' ? driveParentLink($folderId) : null),
            ];
        }
        if (!isset($seen['unfiled'])) {
            $out[] = ['slug' => 'unfiled', 'name' => '(unfiled)', 'folderId' => null, 'bytes' => 0, 'staleBytes' => 0, 'fileCount' => 0, 'lastActivityAt' => null, 'webLink' => null];
        }
        $u = $pdo->prepare('UPDATE drive_snapshots SET clients_json = ? WHERE id = ?');
        $u->execute([json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $snapshotId]);
        driveHashRecord($pdo, $snapshotId, $hashes, 'clients', $bodyHash);
        $pdo->commit();
        driveReply(['snapshot_id' => $snapshotId, 'count' => count($out)]);
    }

    // ---------------------------------------------------------------- tree
    if ($part === 'tree') {
        $tree = $in['tree'] ?? null;
        if (!is_array($tree) || array_is_list($tree)) driveFail(400, 'tree must be an object');
        $nodes = 0;
        $norm = function (array $n, int $depth, string $where) use (&$norm, &$nodes): array {
            if (++$nodes > DRIVE_INGEST_MAX_TREE_NODES) driveFail(400, 'tree has too many nodes (max ' . DRIVE_INGEST_MAX_TREE_NODES . ')');
            if ($depth > 12) driveFail(400, "tree is nested too deep at $where");
            $id = driveVId($n['id'] ?? null, true);
            if ($id === null) driveFail(400, "$where.id is not a Drive folder id");
            $name = driveVStr($n['name'] ?? '', 255);
            $bytes = driveVBytes($n['bytes'] ?? 0); $stale = driveVBytes($n['staleBytes'] ?? 0);
            if ($bytes === null || $stale === null) driveFail(400, "$where: bytes and staleBytes must be counts");
            $slug = null;
            if (isset($n['clientSlug']) && $n['clientSlug'] !== null && $n['clientSlug'] !== '') {
                $slug = driveVSlug($n['clientSlug']);
                if ($slug === null) driveFail(400, "$where.clientSlug is not a slug");
            }
            $node = [
                'id'             => $id,
                'name'           => $name !== '' ? $name : ($id === 'root' ? 'My Drive' : ($id === 'unfiled' ? '(unfiled)' : '(untitled)')),
                'path'           => driveVStr($n['path'] ?? '', 1024),
                'bytes'          => $bytes,
                'staleBytes'     => min($stale, $bytes),
                'fileCount'      => driveVInt($n['fileCount'] ?? 0, 0, PHP_INT_MAX) ?? 0,
                'lastActivityAt' => driveVDate($n['lastActivityAt'] ?? null, "$where.lastActivityAt"),
                'webLink'        => driveVUrl($n['webLink'] ?? ($n['webViewLink'] ?? null)) ?? ($id === 'root' || $id === 'unfiled' ? null : driveParentLink($id)),
                'clientSlug'     => $slug,
                'truncated'      => driveVInt($n['truncated'] ?? 0, 0, PHP_INT_MAX) ?? 0,
                'children'       => [],
            ];
            $children = $n['children'] ?? [];
            if (!is_array($children)) driveFail(400, "$where.children must be a list");
            foreach ($children as $i => $c) {
                if (!is_array($c)) driveFail(400, "$where.children[$i] is not an object");
                $node['children'][] = $norm($c, $depth + 1, "$where.children[$i]");
            }
            return $node;
        };
        $clean = $norm($tree, 0, 'tree');
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > DRIVE_INGEST_MAX_TREE_BYTES) driveFail(413, 'tree is too large (max ' . driveFormatBytes(DRIVE_INGEST_MAX_TREE_BYTES) . ')');
        $u = $pdo->prepare('UPDATE drive_snapshots SET tree_json = ? WHERE id = ?');
        $u->execute([$json, $snapshotId]);
        driveHashRecord($pdo, $snapshotId, $hashes, 'tree', $bodyHash);
        $pdo->commit();
        driveReply(['snapshot_id' => $snapshotId, 'nodes' => $nodes]);
    }

    // ---------------------------------------------------------------- quickwins (optional: trash bytes)
    if ($part === 'quickwins') {
        $trash = is_array($in['trash'] ?? null) ? driveVBytes($in['trash']['bytes'] ?? null) : null;
        if (array_key_exists('trash', $in) && $trash === null) driveFail(400, 'trash.bytes must be a byte count');
        $existing = is_string($snap['quick_wins_json'] ?? null) && $snap['quick_wins_json'] !== '' ? (json_decode($snap['quick_wins_json'], true) ?: []) : [];
        if ($trash !== null) $existing['trash'] = ['bytes' => $trash];
        $u = $pdo->prepare('UPDATE drive_snapshots SET quick_wins_json = ? WHERE id = ?');
        $u->execute([json_encode($existing), $snapshotId]);
        driveHashRecord($pdo, $snapshotId, $hashes, 'quickwins', $bodyHash);
        $pdo->commit();
        driveReply(['snapshot_id' => $snapshotId]);
    }

    // ---------------------------------------------------------------- state (POST: the script's resume scratch)
    if ($part === 'state') {
        if (!array_key_exists('state', $in)) driveFail(400, 'state is required');
        $json = $in['state'] === null ? null : json_encode($in['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $u = $pdo->prepare('UPDATE drive_snapshots SET state_json = ? WHERE id = ?');
        $u->execute([$json, $snapshotId]);
        $pdo->commit();
        driveReply(['snapshot_id' => $snapshotId, 'bytes' => $json === null ? 0 : strlen($json)]);
    }

    // ---------------------------------------------------------------- alerted
    if ($part === 'alerted') {
        $kinds = $in['kinds'] ?? null;
        if (!is_array($kinds) || !array_is_list($kinds)) driveFail(400, 'kinds must be a list');
        $allowed = ['pct80', 'pct90', 'pct95', 'days14'];
        $recorded = [];
        $ins = $pdo->prepare('INSERT IGNORE INTO drive_alerts (snapshot_id, kind, sent_at) VALUES (?, ?, NOW())');
        foreach ($kinds as $i => $k) {
            if (!is_string($k) || !in_array($k, $allowed, true)) driveFail(400, "kinds[$i] is not one of " . implode(', ', $allowed));
            $ins->execute([$snapshotId, $k]);
            if ((int)$ins->rowCount() > 0) $recorded[] = $k;
        }
        $pdo->commit();
        driveReply(['snapshot_id' => $snapshotId, 'recorded' => $recorded]);
    }

    // ---------------------------------------------------------------- finish
    if ($part === 'finish') {
        $wasComplete = $snap['status'] === 'complete';
        $meta = is_array($in['meta'] ?? null) ? $in['meta'] : [];
        $fileCount = driveVInt($meta['fileCount'] ?? null, 0, PHP_INT_MAX) ?? (int)$snap['file_count'];

        // Every part present?
        $missing = [];
        foreach (['begin', 'folders', 'clients', 'tree'] as $p) if (!isset($hashes[$p])) $missing[] = $p;
        if ($fileCount > 0 && empty($hashes['files'])) $missing[] = 'files';
        if ($missing) driveFail(409, 'Parts missing before finish: ' . implode(', ', $missing), ['missing' => $missing]);

        // Folder map (id → path / client) for paths + client assignment of files.
        $folders = [];
        $fs = $pdo->prepare('SELECT folder_id, path, client_slug FROM drive_folders WHERE snapshot_id = ?');
        $fs->execute([$snapshotId]);
        foreach ($fs->fetchAll() as $f) $folders[(string)$f['folder_id']] = [(string)$f['path'], $f['client_slug'] !== null && $f['client_slug'] !== '' ? (string)$f['client_slug'] : null];
        $folderCount = count($folders);
        $pathOf = static function (?string $parent, string $name) use ($folders): string {
            $base = ($parent !== null && isset($folders[$parent])) ? rtrim($folders[$parent][0], '/') : '';
            return $base . '/' . $name;
        };

        // 1. client_slug from the parent folder (NULL = unfiled).
        $pdo->prepare('UPDATE drive_files SET client_slug = (SELECT f.client_slug FROM drive_folders f WHERE f.snapshot_id = drive_files.snapshot_id AND f.folder_id = drive_files.parent_id)
                        WHERE snapshot_id = ?')->execute([$snapshotId]);

        // 2. score = round(100 · raw / maxRaw) over the candidates.
        $mx = $pdo->prepare('SELECT MAX(raw_score) FROM drive_files WHERE snapshot_id = ? AND is_candidate = 1');
        $mx->execute([$snapshotId]);
        $maxRaw = (int)$mx->fetchColumn();
        $pdo->prepare('UPDATE drive_files SET score = 0 WHERE snapshot_id = ?')->execute([$snapshotId]);
        if ($maxRaw > 0) {
            $pdo->prepare('UPDATE drive_files SET score = ROUND(100.0 * raw_score / ?) WHERE snapshot_id = ? AND is_candidate = 1')->execute([$maxRaw, $snapshotId]);
        }
        $cc = $pdo->prepare('SELECT COUNT(*) FROM drive_files WHERE snapshot_id = ? AND is_candidate = 1');
        $cc->execute([$snapshotId]);
        $candidateCount = (int)$cc->fetchColumn();
        $perClientCand = [];
        $pc = $pdo->prepare('SELECT client_slug, COUNT(*) AS n, SUM(bytes) AS b FROM drive_files WHERE snapshot_id = ? AND is_candidate = 1 GROUP BY client_slug');
        $pc->execute([$snapshotId]);
        foreach ($pc->fetchAll() as $r) $perClientCand[$r['client_slug'] === null || $r['client_slug'] === '' ? 'unfiled' : (string)$r['client_slug']] = [(int)$r['n'], (int)$r['b']];

        // 3. by type over every received file.
        $byType = array_fill_keys(driveTypeBuckets(), 0);
        $bt = $pdo->prepare('SELECT type_bucket, SUM(bytes) AS b, COUNT(*) AS n FROM drive_files WHERE snapshot_id = ? GROUP BY type_bucket');
        $bt->execute([$snapshotId]);
        $receivedFiles = 0; $receivedBytes = 0;
        foreach ($bt->fetchAll() as $r) {
            $bucket = isset($byType[$r['type_bucket']]) ? (string)$r['type_bucket'] : 'Other';
            $byType[$bucket] += (int)$r['b'];
            $receivedFiles += (int)$r['n']; $receivedBytes += (int)$r['b'];
        }
        $byTypeRows = [];
        foreach ($byType as $b => $n) $byTypeRows[] = ['bucket' => $b, 'bytes' => $n];
        usort($byTypeRows, fn($a, $b) => $b['bytes'] <=> $a['bytes']);

        // 4. exact duplicates: md5 + size, count > 1, native Docs skipped.
        $pdo->prepare('DELETE FROM drive_quick_wins WHERE snapshot_id = ?')->execute([$snapshotId]);
        $dg = $pdo->prepare("SELECT md5, bytes, COUNT(*) AS n FROM drive_files
                              WHERE snapshot_id = ? AND md5 IS NOT NULL AND bytes > 0 AND mime_type NOT LIKE 'application/vnd.google-apps.%'
                              GROUP BY md5, bytes HAVING COUNT(*) > 1 ORDER BY (COUNT(*) - 1) * bytes DESC");
        $dg->execute([$snapshotId]);
        $dupGroups = $dg->fetchAll();
        $dupFiles = 0; $dupBytes = 0;
        foreach ($dupGroups as $g) { $dupFiles += (int)$g['n'] - 1; $dupBytes += ((int)$g['n'] - 1) * (int)$g['bytes']; }
        $members = $pdo->prepare('SELECT file_id, name, parent_id, client_slug, bytes, md5, mime_type, web_link, modified_at FROM drive_files WHERE snapshot_id = ? AND md5 = ? AND bytes = ? ORDER BY modified_at DESC, file_id ASC');
        $qwIns = $pdo->prepare('INSERT INTO drive_quick_wins (snapshot_id, kind, group_key, name, path, client_slug, file_count, bytes, reclaimable_bytes, files_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $toMember = static function (array $r) use ($pathOf): array {
            return ['id' => $r['file_id'], 'name' => $r['name'], 'path' => $pathOf($r['parent_id'], $r['name']), 'parentId' => $r['parent_id'],
                    'clientSlug' => $r['client_slug'], 'bytes' => (int)$r['bytes'], 'md5' => $r['md5'], 'mimeType' => $r['mime_type'],
                    'webLink' => $r['web_link'], 'modifiedAt' => $r['modified_at']];
        };
        $storedDup = 0;
        foreach (array_slice($dupGroups, 0, DRIVE_KEEP_QUICK_WIN_GROUPS) as $g) {
            $members->execute([$snapshotId, $g['md5'], (int)$g['bytes']]);
            $groups = driveComputeDuplicateGroups(array_map($toMember, $members->fetchAll()));
            foreach ($groups as $grp) {
                $qwIns->execute([$snapshotId, 'duplicate', driveVStr($grp['key'], 80), driveVStr($grp['name'], 255), driveVStr((string)$grp['path'], 1024) ?: null,
                                 $grp['clientSlug'], $grp['fileCount'], $grp['bytes'], $grp['reclaimableBytes'], json_encode(array_slice($grp['files'], 0, DRIVE_INGEST_GROUP_MEMBERS), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                $storedDup++;
            }
        }

        // 5. old versions: stream every file name through the regex, group per parent folder.
        $versioned = [];
        $lastId = 0;
        $chunk = $pdo->prepare('SELECT id, file_id, name, parent_id, client_slug, bytes, md5, mime_type, web_link, modified_at FROM drive_files WHERE snapshot_id = ? AND id > ? ORDER BY id ASC LIMIT 5000');
        while (true) {
            $chunk->execute([$snapshotId, $lastId]);
            $rows = $chunk->fetchAll();
            if (!$rows) break;
            foreach ($rows as $r) {
                $lastId = (int)$r['id'];
                if (driveOldVersionMatch((string)$r['name']) !== null) $versioned[] = $toMember($r);
            }
            if (count($rows) < 5000) break;
        }
        $ovGroups = driveComputeOldVersionGroups($versioned);
        unset($versioned);
        $ovFiles = 0; $ovBytes = 0;
        foreach ($ovGroups as $g) { $ovFiles += $g['fileCount'] - 1; $ovBytes += $g['reclaimableBytes']; }
        foreach (array_slice($ovGroups, 0, DRIVE_KEEP_QUICK_WIN_GROUPS) as $grp) {
            $qwIns->execute([$snapshotId, 'old_version', driveVStr($grp['key'], 80), driveVStr($grp['name'], 255), driveVStr((string)$grp['path'], 1024) ?: null,
                             $grp['clientSlug'], $grp['fileCount'], $grp['bytes'], $grp['reclaimableBytes'], json_encode(array_slice($grp['files'], 0, DRIVE_INGEST_GROUP_MEMBERS), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
        $ovGroupCount = count($ovGroups);
        unset($ovGroups);

        // 6. prune: keep candidates + the largest DRIVE_KEEP_TOP_FILES + the largest DRIVE_KEEP_PER_CLIENT per client.
        $keep = [];
        $top = $pdo->prepare('SELECT id FROM drive_files WHERE snapshot_id = ? ORDER BY bytes DESC, id ASC LIMIT ' . (int)DRIVE_KEEP_TOP_FILES);
        $top->execute([$snapshotId]);
        foreach ($top->fetchAll() as $r) $keep[(int)$r['id']] = true;
        $slugs = $pdo->prepare('SELECT DISTINCT client_slug FROM drive_files WHERE snapshot_id = ?');
        $slugs->execute([$snapshotId]);
        $perNull = $pdo->prepare('SELECT id FROM drive_files WHERE snapshot_id = ? AND client_slug IS NULL ORDER BY bytes DESC, id ASC LIMIT ' . (int)DRIVE_KEEP_PER_CLIENT);
        $perSlug = $pdo->prepare('SELECT id FROM drive_files WHERE snapshot_id = ? AND client_slug = ? ORDER BY bytes DESC, id ASC LIMIT ' . (int)DRIVE_KEEP_PER_CLIENT);
        foreach ($slugs->fetchAll() as $r) {
            if ($r['client_slug'] === null || $r['client_slug'] === '') { $perNull->execute([$snapshotId]); $st = $perNull; }
            else { $perSlug->execute([$snapshotId, (string)$r['client_slug']]); $st = $perSlug; }
            foreach ($st->fetchAll() as $k) $keep[(int)$k['id']] = true;
        }
        if ($keep) {
            $ids = array_keys($keep);
            $del = $pdo->prepare('DELETE FROM drive_files WHERE snapshot_id = ? AND is_candidate = 0 AND id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $del->execute(array_merge([$snapshotId], $ids));
        } else {
            $pdo->prepare('DELETE FROM drive_files WHERE snapshot_id = ? AND is_candidate = 0')->execute([$snapshotId]);
        }

        // 7. paths for the files that stay.
        $keptRows = $pdo->prepare('SELECT id, name, parent_id FROM drive_files WHERE snapshot_id = ?');
        $keptRows->execute([$snapshotId]);
        $setPath = $pdo->prepare('UPDATE drive_files SET path = ? WHERE id = ?');
        $keptFiles = 0;
        foreach ($keptRows->fetchAll() as $r) {
            $setPath->execute([driveVStr($pathOf($r['parent_id'], (string)$r['name']), 1024), (int)$r['id']]);
            $keptFiles++;
        }

        // 8. clients: shares, stale %, 30-day growth, portal company match, candidate counts.
        $clients = is_string($snap['clients_json'] ?? null) && $snap['clients_json'] !== '' ? (json_decode($snap['clients_json'], true) ?: []) : [];
        $totalClientBytes = 0;
        foreach ($clients as $c) $totalClientBytes += (int)($c['bytes'] ?? 0);
        $growthBasis = driveSnapshotBefore($pdo, date('Y-m-d H:i:s', $takenTs - DRIVE_HISTORY_BASIS_DAYS * 86400), $snapshotId);
        $basisClients = [];
        $growthDays = null;
        if ($growthBasis !== null) {
            $growthDays = (int)max(1, round(($takenTs - strtotime((string)$growthBasis['taken_at'])) / 86400));
            foreach ((array)(json_decode((string)$growthBasis['clients_json'], true) ?: []) as $bc) {
                if (is_array($bc) && isset($bc['slug'])) $basisClients[(string)$bc['slug']] = (int)($bc['bytes'] ?? 0);
            }
        }
        $companies = [];
        try {
            foreach ($pdo->query('SELECT id, name, slug FROM companies')->fetchAll() as $co) {
                $companies[strtolower((string)$co['slug'])] = $co;
                $companies[driveSlugify((string)$co['name'])] = $co;
            }
        } catch (Throwable $e) { /* companies table is not this feature's concern */ }
        foreach ($clients as &$c) {
            $slug = (string)$c['slug'];
            $c['pctOfDrive'] = drivePercent((int)$c['bytes'], $totalClientBytes);
            $c['stalePct']   = drivePercent((int)$c['staleBytes'], (int)$c['bytes']);
            $c['growth30d']  = ($growthBasis !== null && isset($basisClients[$slug])) ? (int)$c['bytes'] - $basisClients[$slug] : null;
            $c['growthBasisDays'] = $c['growth30d'] === null ? null : $growthDays;
            $match = $slug !== 'unfiled' ? ($companies[$slug] ?? $companies[driveSlugify((string)$c['name'])] ?? null) : null;
            $c['companyId']   = $match ? (int)$match['id'] : null;
            $c['companySlug'] = $match ? (string)$match['slug'] : null;
            $c['candidateCount'] = $perClientCand[$slug][0] ?? 0;
            $c['candidateBytes'] = $perClientCand[$slug][1] ?? 0;
        }
        unset($c);

        // 9. quota self-check note: the collector's rollup vs Google's non-trash Drive usage.
        $note = (string)($snap['quota_note'] ?? '');
        $active = max(0, (int)$snap['drive_bytes'] - (int)$snap['trash_bytes']);
        if ($active > 0 && $totalClientBytes > 0 && abs($active - $totalClientBytes) > 0.01 * $active) {
            $extra = 'Client folders sum to ' . driveFormatBytes($totalClientBytes) . ' vs ' . driveFormatBytes($active) . ' Drive usage outside the trash';
            if (strpos($note, 'Client folders sum to') === false) $note = driveVStr($note === '' ? $extra : $note . '; ' . $extra, 255);
        }

        // 10. projection from the history, then the row itself.
        $quick = is_string($snap['quick_wins_json'] ?? null) && $snap['quick_wins_json'] !== '' ? (json_decode($snap['quick_wins_json'], true) ?: []) : [];
        $quick['trash']       = ['bytes' => (int)($quick['trash']['bytes'] ?? (int)$snap['trash_bytes'])];
        $quick['duplicates']  = ['bytes' => $dupBytes, 'files' => $dupFiles, 'groups' => count($dupGroups)];
        $quick['oldVersions'] = ['bytes' => $ovBytes, 'files' => $ovFiles, 'groups' => $ovGroupCount];
        $listedBytes = driveVBytes($meta['listedBytes'] ?? null) ?? ((int)$snap['listed_bytes'] ?: $receivedBytes);
        $snapForProj = ['id' => $snapshotId, 'taken_at' => $snap['taken_at'], 'usage_bytes' => (int)$snap['usage_bytes'],
                        'quota_limit' => ($snap['quota_limit'] === null || $snap['quota_limit'] === '') ? null : (int)$snap['quota_limit']];
        $proj = driveComputeProjection($pdo, $snapForProj);
        $u = $pdo->prepare("UPDATE drive_snapshots SET status = 'complete', finished_at = NOW(), state_json = NULL, quota_note = ?,
                              file_count = ?, folder_count = ?, candidate_count = ?, listed_bytes = ?, duration_ms = ?,
                              burn_rate_per_day = ?, days_to_full = ?, basis_days = ?, clients_json = ?, by_type_json = ?, quick_wins_json = ?
                            WHERE id = ?");
        $u->execute([
            $note !== '' ? $note : null,
            $fileCount > 0 ? $fileCount : $receivedFiles,
            driveVInt($meta['folderCount'] ?? null, 0, PHP_INT_MAX) ?? $folderCount,
            $candidateCount, $listedBytes,
            driveVInt($meta['durationMs'] ?? null, 0, PHP_INT_MAX) ?? (($snap['duration_ms'] === null || $snap['duration_ms'] === '') ? null : (int)$snap['duration_ms']),
            $proj['burn_rate_per_day'], $proj['days_to_full'], $proj['basis_days'],
            json_encode(array_values($clients), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($byTypeRows), json_encode($quick),
            $snapshotId,
        ]);

        // 11. one activity row per completed snapshot (digest_id 0 keeps it out of the daily digest email).
        $activityLogged = false;
        if (!$wasComplete) {
            $pctText = ($snapForProj['quota_limit'] !== null && $snapForProj['quota_limit'] > 0)
                ? (int)round(100 * $snapForProj['usage_bytes'] / $snapForProj['quota_limit']) . '% used'
                : driveFormatBytes($snapForProj['usage_bytes']) . ' used';
            $summary = 'Drive snapshot: ' . $pctText . ', ' . $candidateCount . ' candidate' . ($candidateCount === 1 ? '' : 's');
            try {
                $pdo->prepare("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, batch_id, summary, detail, digest_id)
                               VALUES (0, 'drive_snapshot', ?, 'snapshot', 'admin', NULL, ?, NULL, 0)")->execute([$snapshotId, $summary]);
                $activityLogged = true;
            } catch (Throwable $e) {
                error_log('drive-ingest: activity row failed: ' . $e->getMessage());
            }
        }

        // 12. retention: detail for the newest DRIVE_KEEP_SNAPSHOTS complete snapshots only; stale partials → failed.
        $old = $pdo->prepare("SELECT id FROM drive_snapshots WHERE status = 'complete' ORDER BY taken_at DESC, id DESC LIMIT 1000 OFFSET " . (int)DRIVE_KEEP_SNAPSHOTS);
        $old->execute();
        $drop = array_map(fn($r) => (int)$r['id'], $old->fetchAll());
        $stalePartials = $pdo->prepare("SELECT id FROM drive_snapshots WHERE status = 'partial' AND taken_at < ? AND id <> ?");
        $stalePartials->execute([date('Y-m-d H:i:s', time() - DRIVE_STALE_SNAPSHOT_HOURS * 3600), $snapshotId]);
        $failed = array_map(fn($r) => (int)$r['id'], $stalePartials->fetchAll());
        foreach (array_merge($drop, $failed) as $sid) {
            foreach (['drive_folders', 'drive_files', 'drive_quick_wins'] as $t) $pdo->prepare("DELETE FROM {$t} WHERE snapshot_id = ?")->execute([$sid]);
        }
        if ($drop) {
            $ph = implode(',', array_fill(0, count($drop), '?'));
            $pdo->prepare("UPDATE drive_snapshots SET tree_json = NULL, state_json = NULL WHERE id IN ($ph) AND tree_json IS NOT NULL")->execute($drop);
        }
        if ($failed) {
            $ph = implode(',', array_fill(0, count($failed), '?'));
            $pdo->prepare("UPDATE drive_snapshots SET status = 'failed', tree_json = NULL, state_json = NULL WHERE id IN ($ph)")->execute($failed);
        }

        // 13. alerts due (after the row is complete so the history comparison sees the final numbers).
        $alerts = driveAlertsDue($pdo, $snapForProj + ['days_to_full' => $proj['days_to_full']]);
        $pdo->commit();

        $fresh = driveSnapshot($pdo, $snapshotId);
        driveReply([
            'snapshot_id'     => $snapshotId,
            'status'          => 'complete',
            'totals'          => ['files' => $fresh['file_count'], 'received_files' => $receivedFiles, 'folders' => $fresh['folder_count'], 'candidates' => $candidateCount,
                                  'kept_files' => $keptFiles, 'quick_wins' => $storedDup + min($ovGroupCount, DRIVE_KEEP_QUICK_WIN_GROUPS),
                                  'duplicate_groups' => count($dupGroups), 'old_version_groups' => $ovGroupCount, 'pruned_snapshots' => count($drop), 'failed_snapshots' => count($failed)],
            'quota'           => ['limit' => $fresh['quota_limit'], 'usage' => $fresh['usage_bytes'], 'drive' => $fresh['drive_bytes'], 'trash' => $fresh['trash_bytes'],
                                  'other' => $fresh['other_bytes'], 'free' => $fresh['free_bytes'], 'pctUsed' => $fresh['pct_used'], 'note' => $fresh['quota_note']],
            'projection'      => driveProjection($fresh),
            'quick_wins'      => $fresh['quick_wins'],
            'alerts_due'      => $alerts,
            'activity_logged' => $activityLogged,
        ]);
    }

    driveFail(400, 'Unknown part');
} catch (Throwable $e) {
    driveIngestServerError('part=' . $part, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    exit;
}
