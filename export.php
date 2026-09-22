<?php
/**
 * Approved-asset export endpoint (Studio → Export). Admin only, scoped to ?client=<slug>;
 * every request is same-site checked (requireSameSiteFetch) and answers JSON except the
 * two downloads. Helpers: export-lib.php.
 *
 *   POST action=estimate  {scope, tire_id, series_id, photos, videos, reference, library}
 *                         → {ok, files, bytes, video_bytes, counts, warnings, label}   (no job written)
 *   POST action=start     same fields → {ok, job, files, bytes, label, filename}       (sidecar written; stale jobs cleaned)
 *   POST action=step      {job}   → {ok, done, added, remaining, bytes_done, bytes, files, zip_bytes}
 *   POST action=status    {job}   → the same shape (+ label, created_at, expires_at)
 *   POST action=cancel    {job}   → {ok} — deletes sidecar + zip
 *   POST action=list              → {ok, jobs: [summary…]} — this client's jobs from the last 24 h
 *   GET  action=download&job=…    → application/zip attachment (only a finished job of this client;
 *                                   Accept-Ranges / single Range honoured so a download can resume)
 *   GET  action=manifest&scope=…  → text/csv attachment of the enumeration (no zip, no job)
 *
 * Errors: 400 bad input / nothing to export / over the cap, 403 client seat, cross-site or another
 * client's job, 404 unknown job, 405 method, 409 job still building (download) / step already running,
 * 503 zip unsupported on this PHP build (32-bit) — the form falls back to the manifest.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/export-lib.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }

function exportFail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    exit;
}
function exportReply(array $data): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
$postActions = ['estimate', 'start', 'step', 'status', 'cancel', 'list'];
$getActions  = ['download', 'manifest'];
if (!in_array($action, $postActions, true) && !in_array($action, $getActions, true)) exportFail(400, 'Unknown action');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (in_array($action, $postActions, true) && $method !== 'POST') exportFail(405, 'Method not allowed');
if (in_array($action, $getActions, true) && $method !== 'GET' && $method !== 'HEAD') exportFail(405, 'Method not allowed');
requireSameSiteFetch();
if (!currentAdmin()) exportFail(403, 'Admin sign-in required');
if (!$client) exportFail(400, 'Pick a client first.');
$cid = (int)$client['id'];

/** The job for {job} — 404 unknown, 403 another client's. */
function exportLoadJob(int $cid): array {
    $id = (string)($_POST['job'] ?? $_GET['job'] ?? '');
    if (!exportValidJobId($id)) exportFail(400, 'Bad job id');
    $job = exportReadJob($id);
    if ($job === null) exportFail(404, 'Unknown export — it may have expired (exports are kept for 24 hours)');
    if ((int)($job['company_id'] ?? 0) !== $cid) exportFail(403, 'That export belongs to another client');
    return $job;
}

// ---------------------------------------------------------------------
if ($action === 'estimate') {
    try { $enum = exportEnumerate($pdo, $client, $_POST); }
    catch (InvalidArgumentException $e) { exportFail(400, $e->getMessage()); }
    catch (Throwable $e) { error_log('export estimate: ' . $e->getMessage()); exportFail(500, 'Could not count the files'); }
    exportReply(['files' => count($enum['files']), 'bytes' => $enum['bytes'], 'video_bytes' => $enum['video_bytes'], 'counts' => $enum['counts'],
                 'warnings' => $enum['warnings'], 'label' => $enum['label'], 'over_cap' => $enum['bytes'] > EXPORT_MAX_BYTES, 'cap' => EXPORT_MAX_BYTES,
                 'zip' => exportZipSupported()]);
}

if ($action === 'start') {
    if (!exportZipSupported()) exportFail(503, 'This server runs a 32-bit PHP build, which cannot write zips over 2 GB safely — download the manifest CSV instead', ['manifest_only' => true]);
    try { $job = exportStartJob($pdo, $client, $_POST); }
    catch (InvalidArgumentException $e) { exportFail(400, $e->getMessage()); }
    catch (Throwable $e) { error_log('export start: ' . $e->getMessage()); exportFail(500, $e->getMessage() ?: 'Could not start the export'); }
    exportReply(['job' => $job['job'], 'files' => count($job['files']), 'bytes' => $job['bytes'], 'video_bytes' => $job['video_bytes'], 'counts' => $job['counts'],
                 'warnings' => $job['warnings'], 'label' => $job['label'], 'filename' => $job['filename'], 'created_at' => $job['created_at']]);
}

if ($action === 'step') {
    $job = exportLoadJob($cid);
    $budget = [];
    if (getenv('EXPORT_QA_STEP_BYTES')) $budget['bytes'] = (int)getenv('EXPORT_QA_STEP_BYTES');   // harness: tiny steps so the loop is exercised
    $r = exportStep($job, $client, $budget);
    if (!$r['ok']) exportFail((int)($r['code'] ?? 500), (string)($r['error'] ?? 'Step failed'));
    unset($r['ok']);
    exportReply($r);
}

if ($action === 'status') {
    $job = exportLoadJob($cid);
    exportReply(exportStepReply($job) + ['label' => (string)$job['label'], 'created_at' => (int)$job['created_at'], 'expires_at' => (int)$job['created_at'] + EXPORT_JOB_MAX_AGE, 'error' => (string)($job['error'] ?? ''), 'warnings' => $job['warnings'] ?? []]);
}

if ($action === 'cancel') {
    $job = exportLoadJob($cid);
    exportDeleteJob($job['job']);
    exportReply(['job' => $job['job'], 'deleted' => true]);
}

if ($action === 'list') {
    exportReply(['jobs' => exportListJobs($cid), 'zip' => exportZipSupported(), 'cap' => EXPORT_MAX_BYTES, 'now' => time()]);
}

if ($action === 'manifest') {
    try { $enum = exportEnumerate($pdo, $client, $_GET); }
    catch (InvalidArgumentException $e) { exportFail(400, $e->getMessage()); }
    catch (Throwable $e) { error_log('export manifest: ' . $e->getMessage()); exportFail(500, 'Could not build the manifest'); }
    $csv  = exportManifestCsv($enum);
    $stem = preg_replace('/\.zip$/', '', exportZipFilename($client, $enum['options'], ['tire_name' => $enum['tire_name'], 'series_name' => $enum['series_name']]));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $stem . '-manifest.csv"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($csv));
    if ($method !== 'HEAD') echo $csv;
    exit;
}

// ---------------------------------------------------------------------
// download — streams uploads/.exports/<job>.zip (never web-reachable itself) in 1 MB pieces
// with a single byte range honoured, so a dropped multi-GB download can resume.
// ---------------------------------------------------------------------
$job = exportLoadJob($cid);
if (empty($job['done'])) exportFail(409, 'This export is still building');
$paths = exportJobPaths($job['job']);
$zip = $paths ? $paths['zip'] : '';
if ($zip === '' || !is_file($zip) || is_link($zip)) exportFail(404, 'The zip is gone — build the export again');
clearstatcache(true, $zip);
$size = (int)filesize($zip);
$start = 0; $end = $size - 1; $partial = false;
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) || ($m[1] === '' && $m[2] === '')) {
        http_response_code(416); header('Content-Range: bytes */' . $size); exit;
    }
    if ($m[1] === '') { $n = (int)$m[2]; $start = max(0, $size - $n); }                    // suffix range: the last n bytes
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($size - 1, (int)$m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
    $partial = true;
}
$fh = @fopen($zip, 'rb');
if ($fh === false) exportFail(500, 'Could not open the zip');
if (function_exists('session_write_close')) @session_write_close();   // do not hold the admin session lock for the whole transfer
@set_time_limit(0);
ignore_user_abort(false);
while (ob_get_level() > 0) { @ob_end_clean(); }
$name = (string)($job['filename'] ?? '') ?: exportZipFilename($client);
$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
http_response_code($partial ? 206 : 200);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($partial) header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
if ($method === 'HEAD') { fclose($fh); exit; }
if ($start > 0) fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $buf = fread($fh, (int)min(EXPORT_IO_CHUNK, $left));
    if ($buf === false || $buf === '') break;
    echo $buf;
    $left -= strlen($buf);
    flush();
    if (connection_aborted()) break;
}
fclose($fh);
exit;
