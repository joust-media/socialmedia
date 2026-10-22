<?php
/**
 * "Export open comments" endpoint (admin only) — comment-export-lib.php does the work; static/js/comment-export.js
 * (App.commentExport, the export sheet) calls it from Manage → Export, the Redo page, the Assets / Tires ⋯ menus and the
 * Inbox. Every request: same-site (requireSameSiteFetch) and an admin session (403 otherwise — the client seat never
 * gets anything). Scope: the posted / ?client= slug (one client; nothing of another client's is ever read), or
 * scope=all (every client).
 *
 * Fields (every action): scope client|all, filter open|waiting|all, internal 0|1, since 0|1, from / to (YYYY-MM-DD),
 *                        size original|lg (zip)
 *   GET|POST action=count      → {ok, comments, items, images, clients, last_export, last_export_label}
 *   GET      action=csv        → text/csv (UTF-8 + BOM, CRLF; streamed); stamps "the last export"
 *   GET      action=html       → the printable summary page (Print → Save as PDF); stamps nothing
 *   POST     action=zip_start  → {ok, job, files, bytes, comments, label, filename, warnings} (export-lib.php's stepwise zip)
 *   POST     action=zip_step   {job} → {ok, done, …}; the step that finishes it stamps "the last export"
 *   GET      action=download&job=… → the zip
 * Errors: 400 bad input / nothing to export, 403 not admin / cross-site, 404 unknown client / job, 405 method,
 *         409 a step is already running, 503 zip unsupported (32-bit PHP).
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/comment-export-lib.php';

function cexpOut(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// the seat first: nobody but Joust learns anything here (not even which actions exist)
requireSameSiteFetch();
if (!currentAdmin()) cexpOut(403, ['ok' => false, 'error' => 'Admin sign-in required']);

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$posts = ['zip_start', 'zip_step'];
$gets  = ['csv', 'html', 'download'];
if ($action !== 'count' && !in_array($action, $posts, true) && !in_array($action, $gets, true)) cexpOut(400, ['ok' => false, 'error' => 'Unknown action']);
if (in_array($action, $posts, true) && $method !== 'POST') cexpOut(405, ['ok' => false, 'error' => 'Method not allowed']);
if (in_array($action, $gets, true) && $method !== 'GET' && $method !== 'HEAD') cexpOut(405, ['ok' => false, 'error' => 'Method not allowed']);
if ($action === 'count' && !in_array($method, ['GET', 'HEAD', 'POST'], true)) cexpOut(405, ['ok' => false, 'error' => 'Method not allowed']);
$in = $method === 'POST' ? $_POST : $_GET;
$opts = cexpOptions($in);
$slug = postedClientSlug();
$scope = $slug !== '' ? helpersLoadCompany($pdo, $slug) : null;
if ($slug !== '' && !$scope) cexpOut(404, ['ok' => false, 'error' => 'Unknown client']);
$target = ($opts['scope'] === 'all' || !$scope) ? null : $scope;   // null = every client

/** A comment-export job by id (400 bad id, 404 unknown or not one of ours). */
$loadJob = static function () use ($in): array {
    $id = (string)($in['job'] ?? '');
    if (!exportValidJobId($id)) cexpOut(400, ['ok' => false, 'error' => 'Bad job id']);
    $job = exportReadJob($id);
    if ($job === null || empty($job['cexp'])) cexpOut(404, ['ok' => false, 'error' => 'Unknown export — it may have expired (exports are kept for 24 hours)']);
    return $job;
};

if ($action === 'count') {
    try { $data = cexpCollect($pdo, $target, $opts); }
    catch (Throwable $e) { error_log('comment export count: ' . $e->getMessage()); cexpOut(500, ['ok' => false, 'error' => 'Could not count the comments']); }
    $images = 0;
    foreach (cexpUnits($data) as $u) { if ($u['image'] !== null) $images++; }
    $last = cexpLastExport($pdo, $target ? (int)$target['id'] : null);
    cexpOut(200, ['ok' => true, 'comments' => count($data['rows']), 'items' => count($data['items']), 'images' => $images,
                  'clients' => count($data['clients']), 'scope' => $target ? 'client' : 'all', 'client' => $target ? (string)$target['slug'] : '',
                  'last_export' => $last, 'last_export_label' => $last !== '' ? relativeTime($last) : '', 'zip' => exportZipSupported()]);
}

if ($action === 'csv') {
    try { $data = cexpCollect($pdo, $target, $opts); }
    catch (Throwable $e) { error_log('comment export csv: ' . $e->getMessage()); cexpOut(500, ['ok' => false, 'error' => 'Could not build the CSV']); }
    if (function_exists('session_write_close')) @session_write_close();
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . cexpFileStem($target, $opts) . '.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Comment-Count: ' . count($data['rows']));
    if ($method !== 'HEAD') {
        // streamed: the header line, then the rows in batches (no big string in memory, the download starts at once)
        echo cexpCsvHead();
        $buf = ''; $n = 0;
        foreach ($data['rows'] as $r) {
            $buf .= cexpCsvLine(cexpCsvCells($r));
            if (++$n % 200 === 0) { echo $buf; $buf = ''; flush(); }
        }
        echo $buf;
        flush();
        cexpStamp($pdo, $target ? [(int)$target['id']] : null, $data['at']);
    }
    exit;
}

if ($action === 'html') {
    try { $data = cexpCollect($pdo, $target, $opts); }
    catch (Throwable $e) { error_log('comment export html: ' . $e->getMessage()); cexpOut(500, ['ok' => false, 'error' => 'Could not build the summary']); }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    if ($method !== 'HEAD') echo cexpSummaryHtml($data, $opts, $target, null);
    exit;
}

if ($action === 'zip_start') {
    if (!exportZipSupported()) cexpOut(503, ['ok' => false, 'error' => 'This server runs a 32-bit PHP build, which cannot write zips safely — download the CSV instead']);
    try { $job = cexpZipStartJob($pdo, $target, $opts); }
    catch (InvalidArgumentException $e) { cexpOut(400, ['ok' => false, 'error' => $e->getMessage()]); }
    catch (Throwable $e) { error_log('comment export zip start: ' . $e->getMessage()); cexpOut(500, ['ok' => false, 'error' => 'Could not start the zip']); }
    cexpOut(200, ['ok' => true, 'job' => $job['job'], 'files' => count($job['files']), 'bytes' => $job['bytes'], 'comments' => (int)$job['comments'],
                  'label' => $job['label'], 'filename' => $job['filename'], 'warnings' => $job['warnings']]);
}

if ($action === 'zip_step') {
    $job = $loadJob();
    $budget = [];
    if (getenv('EXPORT_QA_STEP_BYTES')) $budget['bytes'] = (int)getenv('EXPORT_QA_STEP_BYTES');   // harness: tiny steps
    $r = exportStep($job, ['id' => (int)$job['company_id'], 'slug' => (string)$job['client']], $budget);
    if (!$r['ok']) cexpOut((int)($r['code'] ?? 500), ['ok' => false, 'error' => (string)($r['error'] ?? 'Step failed')]);
    if (!empty($r['done'])) {
        $job = exportReadJob($job['job']) ?? $job;
        if (empty($job['stamped'])) {
            cexpStamp($pdo, isset($job['stamp_ids']) && is_array($job['stamp_ids']) ? $job['stamp_ids'] : null, (string)($job['stamp_at'] ?? date('Y-m-d H:i:s')));
            $job['stamped'] = true;
            exportSaveJob($job);
        }
    }
    unset($r['ok']);
    cexpOut(200, ['ok' => true] + $r);
}

// download
$job = $loadJob();
$r = exportStreamZip($job, $method, 'comments.zip');
cexpOut((int)$r['code'], ['ok' => false, 'error' => (string)$r['error']]);
