<?php
/**
 * Image-preview backfill (Studio → Export → "Image previews"). Admin only, same-site, POST, JSON.
 *
 *   action=start  scope=client|all  → enumerates every image of the client (?client=<slug>) or of every company:
 *                                     tire images (references + series renders), library files on disk, post images;
 *                                     writes uploads/.exports/previews-<company id|all>.json (deny-all folder shared
 *                                     with Export) and replies with the status shape below (nothing generated yet)
 *   action=step   scope=…           → makes the missing / stale sm + lg previews for ≈15 s (env PREVIEW_QA_STEP_ITEMS=n
 *                                     caps the items per step for the harness), then replies with the status shape
 *   action=status scope=…           → the status shape, or {ok, job: null} when nothing was started
 *
 * Status: {ok, job: {scope, total, processed, done, skipped, failed, missing, bytes_original, bytes_sm, bytes_saved,
 *          finished, format, started_at, updated_at}}. done = generated now, skipped = already up to date (or the
 * original is already small), failed = could not be made (too big for memory, undecodable — the lazy endpoint
 * serves the original), missing = the file is gone from disk. bytes_* sum the original vs its sm file over done +
 * skipped items. Idempotent: running it again only makes what is missing.
 *
 * 400 bad action / scope / no client · 403 not admin / cross-site · 405 not POST · 409 a step is already running.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/preview-lib.php';
require_once __DIR__ . '/export-lib.php';
if (is_file(__DIR__ . '/pages-lib.php')) require_once __DIR__ . '/pages-lib.php';   // ensurePagesMediaHtaccess() (definitions only)
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }

function previewJobFail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function previewJobReply(array $data): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') previewJobFail(405, 'Method not allowed');
requireSameSiteFetch();
if (!currentAdmin()) previewJobFail(403, 'Admin sign-in required');
$action = strtolower(trim((string)($_POST['action'] ?? '')));
if (!in_array($action, ['start', 'step', 'status'], true)) previewJobFail(400, 'Unknown action');
$scope = strtolower(trim((string)($_POST['scope'] ?? 'client')));
if (!in_array($scope, ['client', 'all'], true)) previewJobFail(400, 'Unknown scope');
if ($scope === 'client' && !$client) previewJobFail(400, 'Pick a client first.');
$key = $scope === 'all' ? 'all' : (string)(int)$client['id'];

/** uploads/.exports/previews-<key>.json (+ .lock); null when the folder cannot be used. */
function previewJobFile(string $key, bool $create): ?string {
    $dir = exportsDir($create);
    return $dir === null ? null : $dir . '/previews-' . $key . '.json';
}
function previewJobRead(string $key): ?array {
    $f = previewJobFile($key, false);
    if ($f === null || is_link($f) || !is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) && isset($j['refs']) && is_array($j['refs']) ? $j : null;
}
function previewJobSave(string $key, array $job): bool {
    $f = previewJobFile($key, true);
    if ($f === null) return false;
    $job['updated_at'] = time();
    $tmp = $f . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) { @unlink($tmp); return false; }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    return true;
}
function previewJobSummary(array $job): array {
    $o = (int)$job['bytes_original']; $s = (int)$job['bytes_sm'];
    return ['job' => [
        'scope' => (string)$job['scope'], 'total' => count($job['refs']), 'processed' => (int)$job['pos'],
        'done' => (int)$job['done'], 'skipped' => (int)$job['skipped'], 'failed' => (int)$job['failed'], 'missing' => (int)$job['missing'],
        'bytes_original' => $o, 'bytes_sm' => $s, 'bytes_saved' => max(0, $o - $s),
        'finished' => (int)$job['pos'] >= count($job['refs']), 'format' => previewFormat(),
        'started_at' => (int)$job['started_at'], 'updated_at' => (int)($job['updated_at'] ?? 0),
    ]];
}

/** Every image ref of the given companies: tire images, library files on disk, post images (canonical refs, deduped). */
function previewJobEnumerate(PDO $pdo, array $companies): array {
    $refs = [];
    $add = static function (string $url) use (&$refs): void {
        $ref = previewCanonicalRef($url);
        if ($ref === null) return;
        if (!in_array(strtolower((string)pathinfo($ref, PATHINFO_EXTENSION)), previewImageExts(), true)) return;
        $refs[$ref] = true;
    };
    foreach ($companies as $co) {
        $cid = (int)$co['id'];
        try {
            $st = $pdo->prepare("SELECT ti.image_url FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE t.company_id = ? ORDER BY ti.id");
            $st->execute([$cid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $add((string)$u);
        } catch (Throwable $e) { error_log('preview-job tires: ' . $e->getMessage()); }
        $slug = (string)($co['slug'] ?? '');
        if ($slug !== '' && preg_match('/^[a-z0-9\-]+$/', $slug) && function_exists('scanLibraryDir')) {
            foreach (scanLibraryDir(libraryDir($slug)) as $f) $add('media/library/' . $slug . '/' . rawurlencode($f));
        }
        try {
            $st = $pdo->prepare("SELECT pi.image_url FROM post_images pi INNER JOIN posts p ON p.id = pi.post_id WHERE p.company_id = ? ORDER BY pi.id");
            $st->execute([$cid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $add((string)$u);
        } catch (Throwable $e) { error_log('preview-job posts: ' . $e->getMessage()); }
    }
    return array_keys($refs);
}

if ($action === 'status') {
    $job = previewJobRead($key);
    previewJobReply($job === null ? ['job' => null, 'format' => previewFormat()] : previewJobSummary($job));
}

if ($action === 'start') {
    if ($scope === 'all') {
        $companies = $pdo->query("SELECT id, slug FROM companies ORDER BY id")->fetchAll();
    } else {
        $companies = [['id' => (int)$client['id'], 'slug' => (string)$client['slug']]];
    }
    previewEnsureUploadsHtaccess();
    if (function_exists('ensureTireMediaHtaccess')) ensureTireMediaHtaccess();
    if (function_exists('ensurePagesMediaHtaccess') && is_dir(mediaRootPath() . '/pages')) ensurePagesMediaHtaccess();   // v1/v2 → v3 for the pages folder too
    $job = ['scope' => $scope, 'refs' => previewJobEnumerate($pdo, $companies), 'pos' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0,
            'missing' => 0, 'bytes_original' => 0, 'bytes_sm' => 0, 'started_at' => time()];
    if (!previewJobSave($key, $job)) previewJobFail(500, 'uploads/ is not writable on the server');
    previewJobReply(previewJobSummary($job));
}

// ---- step ----
$job = previewJobRead($key);
if ($job === null) previewJobFail(404, 'Nothing started — press Build previews first');
$lockPath = previewJobFile($key, true) . '.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) previewJobFail(409, 'A step is already running');
if (function_exists('session_write_close')) @session_write_close();
@set_time_limit(120);
$job = previewJobRead($key) ?? $job;   // re-read under the lock
$deadline = microtime(true) + 15.0;
$cap = (int)getenv('PREVIEW_QA_STEP_ITEMS');
$n = 0;
$total = count($job['refs']);
while ((int)$job['pos'] < $total) {
    if ($n > 0 && (microtime(true) >= $deadline || ($cap > 0 && $n >= $cap))) break;
    $ref = (string)$job['refs'][(int)$job['pos']];
    $job['pos'] = (int)$job['pos'] + 1;
    $n++;
    $abs = previewRefPath($ref);
    if ($abs === null || !is_file($abs)) { $job['missing']++; continue; }
    $fresh = true;
    foreach (array_keys(previewSizes()) as $s) { if (!previewIsFresh($abs, $s)) { $fresh = false; break; } }
    if ($fresh) {
        $job['skipped']++;
    } else {
        $made = previewEnsureAll($abs);
        if (in_array(null, $made, true)) { $job['failed']++; continue; }
        $job['done']++;
    }
    clearstatcache();
    $sm = previewNeeds($abs, 'sm') ? previewPathFor($abs, 'sm') : $abs;
    $job['bytes_original'] += (int)@filesize($abs);
    $job['bytes_sm'] += (int)@filesize($sm);
}
previewJobSave($key, $job);
@flock($lock, LOCK_UN); @fclose($lock);
previewJobReply(previewJobSummary($job));
