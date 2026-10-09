<?php
/**
 * Move Library images into a tire series (Assets → Library, admin: the viewer's ⋯ "Move to tire…" and the select bar).
 * Admin only (403 JSON for anyone else, like every admin endpoint), same-site only.
 *
 *   GET  action=targets&client=<slug>   → {ok, tires: [{id, name, series: [{id, name, total}]}]}
 *   POST action=move {ids: "4,5", tire_id, series_id | new_series, client}
 *        → {ok, moved, items: [{from_id, id, filename, renamed_from?}], tire: {id, name}, series: {id, name, created}, url}
 *
 * Files move media/library/<slug>/<file> → media/tires/<tire>/<series>/<file> safely:
 *   1. every file is COPIED to a hidden temp name in the series folder (".moving-…", never picked up by a folder scan)
 *      and VERIFIED against its source (size + SHA-1; assign-lib.php assignSameFile) — any failure removes the copies and
 *      changes nothing;
 *   2. ONE transaction: a tire_images row per file (same status, the redo flag, display name = the file stem, after the
 *      series' last image), every activity row / Slack thread / seen marker / email reference of the library image follows
 *      it (redo-lib.php redoRetargetEntity — comments and history stay), the library_images row goes, a 'moved_to_tire'
 *      row is logged; the temp copies get their final names; commit;
 *   3. after the commit the originals are deleted and the previews follow (copied from the original's .thumbs, else
 *      made fresh).
 * A name already used in the series folder (or by a row) gets "-2", "-3" … A .mov's transcoded .mp4 twin moves with it.
 * 400 bad input · 403 not admin / cross-site / another client's image or tire · 404 unknown · 409 series off · 500.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/assign-lib.php';   // assignSameFile(): the copy → verify check assign.php uses

header('Content-Type: application/json');
header('Cache-Control: no-store');

function lmFail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($action, ['targets', 'move'], true)) lmFail(400, 'Unknown action');
if ($action === 'targets' && $method !== 'GET') lmFail(405, 'Method not allowed');
if ($action === 'move' && $method !== 'POST') lmFail(405, 'Method not allowed');
requireSameSiteFetch();
if (!currentAdmin()) lmFail(403, 'Admin sign-in required');
if (!hasLibraryImagesTable($pdo)) lmFail(409, 'The Library is not set up yet — run migrate.php.');
if (!hasTireSeries($pdo)) lmFail(409, 'Tire series are not set up yet — run migrate.php.');

$slug = postedClientSlug();
$company = $slug !== '' ? helpersLoadCompany($pdo, $slug) : null;
if ($slug !== '' && !$company) lmFail(404, 'Unknown client');

if ($action === 'targets') {
    if (!$company) lmFail(400, 'Pick a client first.');
    $tires = [];
    foreach (tiresWithSlugs($pdo, (int)$company['id']) as $t) {
        $series = [];
        foreach (tireSeriesForTire($pdo, (int)$t['id']) as $sr) $series[] = ['id' => (int)$sr['id'], 'name' => (string)$sr['name'], 'total' => (int)($sr['counts']['total'] ?? 0)];
        $tires[] = ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'series' => $series];
    }
    usort($tires, static function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    echo json_encode(['ok' => true, 'tires' => $tires, 'label' => tiresLabel($company)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------
// move
// ---------------------------------------------------------------------
$ids = [];
foreach (preg_split('/[\s,]+/', is_array($_POST['ids'] ?? null) ? implode(',', $_POST['ids']) : (string)($_POST['ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $v) {
    $i = (int)$v;
    if ($i > 0 && !in_array($i, $ids, true)) $ids[] = $i;
}
if (!$ids) lmFail(400, 'Pick at least one image');
if (count($ids) > 200) lmFail(400, 'Move at most 200 images at a time');

// The tire (and so the client) the images go to.
$tireId = (int)($_POST['tire_id'] ?? 0);
$st = $pdo->prepare("SELECT t.id, t.company_id, t.name, c.slug AS company_slug, c.name AS company_name FROM tires t INNER JOIN companies c ON c.id = t.company_id WHERE t.id = ?");
$st->execute([$tireId]);
$tireRow = $st->fetch();
if (!$tireRow) lmFail(404, 'Tire not found');
$cid = (int)$tireRow['company_id'];
if ($company && (int)$company['id'] !== $cid) lmFail(403, 'That tire belongs to another client');
$tire = tireWithSlug($pdo, (int)$tireRow['id']);
if (!$tire) lmFail(404, 'Tire not found');
$co = ['id' => $cid, 'slug' => (string)$tireRow['company_slug'], 'name' => (string)$tireRow['company_name']];

// The library images: all of them this client's (403 otherwise), each file present and inside media/library/<slug>/.
$ph = implode(',', array_fill(0, count($ids), '?'));
$redoLib = function_exists('redoKindReady') && redoKindReady($pdo, 'library');
$redoTire = function_exists('redoKindReady') && redoKindReady($pdo, 'tire');
$st = $pdo->prepare("SELECT li.*, c.slug AS company_slug FROM library_images li INNER JOIN companies c ON c.id = li.company_id WHERE li.id IN ($ph)");
$st->execute($ids);
$libRows = [];
foreach ((array)$st->fetchAll() as $r) $libRows[(int)$r['id']] = $r;
foreach ($ids as $i) {
    if (!isset($libRows[$i])) lmFail(404, 'Image not found');
    if ((int)$libRows[$i]['company_id'] !== $cid) lmFail(403, 'This image belongs to another client');
    if (!empty($libRows[$i]['trashed_at'])) lmFail(409, 'That image is in the Trash — restore it first');   // trash-lib.php
}

// The series: an existing one of this tire, or a new one by name.
$seriesId  = (int)($_POST['series_id'] ?? 0);
$newSeries = trim((string)($_POST['new_series'] ?? ''));
$series = null; $created = false;
if ($seriesId > 0) {
    $series = tireSeriesById($pdo, $seriesId);
    if (!$series || (int)$series['tire_id'] !== (int)$tire['id']) lmFail(404, 'Series not found on this tire');
} else {
    if ($newSeries === '') lmFail(400, 'Pick a series or name a new one');
    if (mb_strlen($newSeries, 'UTF-8') > 120) lmFail(400, 'Series name is too long (max 120 characters)');
}

$batch = newBatchId();
try {
    if (!$series) {
        $pdo->beginTransaction();
        $series = createTireSeries($pdo, (int)$tire['id'], $newSeries);
        $created = !empty($series['created']);
        unset($series['created']);
        if ($created) logTireSeriesActivity($pdo, 'admin', 'created', (int)$series['id'], 'Created series ' . (string)$tire['name'] . ' · ' . $series['name'], null, $batch, $cid);
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('library-move series: ' . $e->getMessage());
    lmFail(500, 'Could not create the series');
}

// Destination folder: media/tires/<tire>/<series>/, exactly two levels under media/tires/ (no symlink escape).
$destDir = tireSeriesFolderPath($co, $tire, $series);
mediaMkdir($destDir, mediaRootPath());
if (!is_dir($destDir) || !is_writable($destDir)) lmFail(500, 'The series folder is not writable on the server');
$rootReal = realpath(tireMediaRootPath()); $dirReal = realpath($destDir);
if ($rootReal !== false && $dirReal !== false && $dirReal !== rtrim($rootReal, '/') . '/' . tireSlug($tire) . '/' . tireSeriesFolderName($series)) lmFail(500, 'Series folder resolves outside media/tires/');
ensureTireMediaHtaccess();
$relDir = tireFolderRel($co, $tire) . '/' . tireSeriesFolderName($series);

// ---- 1. copy → verify (hidden temp names) ----
$plan = [];       // per image: src, tmp, final, name, url, twin {src, tmp, final}
$taken = [];      // lower-case names already claimed in the folder by this batch
$rowTaken = $pdo->prepare("SELECT 1 FROM tire_images WHERE image_url = ? LIMIT 1");
$cleanup = static function () use (&$plan): void {
    foreach ($plan as $p) { foreach ([$p['tmp'], $p['twin']['tmp'] ?? null] as $t) { if ($t && is_file($t)) @unlink($t); } }
};
foreach ($ids as $i) {
    $r = $libRows[$i];
    $file = (string)$r['filename'];
    $dir  = libraryDir((string)$r['company_slug']);
    $src  = $dir . '/' . $file;
    if ($file === '' || $file !== basename($file) || $file[0] === '.' || !is_file($src) || is_link($src)) { $cleanup(); lmFail(404, 'The file of ' . $file . ' is missing on the server'); }
    $real = realpath($src); $dirR = realpath($dir);
    if ($real !== false && $dirR !== false && dirname($real) !== rtrim($dirR, '/')) { $cleanup(); lmFail(403, 'That file resolves outside the Library'); }
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $stem = pathinfo($file, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9._\-]+/', '-', $stem);   // the original name, kept as it is when it is safe (render_01 stays render_01)
    $base = trim((string)$base, '.-_ ');
    if (function_exists('mb_substr')) $base = mb_substr($base, 0, 120);
    if ($base === '') $base = 'image';
    $name = '';
    for ($n = 1; $n < 1000; $n++) {
        $try = $base . ($n > 1 ? '-' . $n : '') . '.' . $ext;
        if (isset($taken[strtolower($try)]) || file_exists($destDir . '/' . $try)) continue;
        $rowTaken->execute([$relDir . '/' . $try]);
        if ($rowTaken->fetchColumn()) continue;
        $name = $try;
        break;
    }
    if ($name === '') { $cleanup(); lmFail(500, 'Could not pick a free file name'); }
    $taken[strtolower($name)] = true;
    $tmp = $destDir . '/.moving-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $entry = ['id' => $i, 'row' => $r, 'src' => $src, 'tmp' => $tmp, 'final' => $destDir . '/' . $name, 'name' => $name, 'url' => $relDir . '/' . $name, 'twin' => null, 'renamed' => $name !== $file];
    $plan[] = &$entry;
    if (!@copy($src, $tmp) || !assignSameFile($src, $tmp)) { unset($entry); $cleanup(); lmFail(500, 'Copying ' . $file . ' failed (check media/tires/ permissions)'); }
    mediaChmodPath($tmp);
    // a .mov's transcoded .mp4 twin (videoTwinUrl) travels with it under the new stem
    if ($ext === 'mov') {
        foreach ([$stem . '.mp4', $stem . '.MP4'] as $tw) {
            if (!is_file($dir . '/' . $tw) || is_link($dir . '/' . $tw)) continue;
            $twFinal = $destDir . '/' . pathinfo($name, PATHINFO_FILENAME) . '.mp4';
            if (file_exists($twFinal)) break;
            $twTmp = $destDir . '/.moving-' . bin2hex(random_bytes(6)) . '.mp4';
            $entry['twin'] = ['src' => $dir . '/' . $tw, 'tmp' => $twTmp, 'final' => $twFinal];
            if (!@copy($dir . '/' . $tw, $twTmp) || !assignSameFile($dir . '/' . $tw, $twTmp)) { unset($entry); $cleanup(); lmFail(500, 'Copying the video twin of ' . $file . ' failed'); }
            mediaChmodPath($twTmp);
            break;
        }
    }
    unset($entry);
}

// ---- 2. one transaction: rows, history, final names ----
$hasName = tireImagesHaveDisplayName($pdo);
$label = (string)$tire['name'] . ' · ' . (string)$series['name'];
$out = [];
$renamedDone = [];
try {
    $pdo->beginTransaction();
    $sq = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM tire_images WHERE tire_id = ? AND series_id = ?");
    $sq->execute([(int)$tire['id'], (int)$series['id']]);
    $sort = (int)$sq->fetchColumn();
    foreach ($plan as $p) {
        $r = $p['row'];
        $cols = ['tire_id', 'series_id', 'image_url', 'caption', 'sort_order', 'status'];
        $vals = [(int)$tire['id'], (int)$series['id'], $p['url'], '', ++$sort, (string)$r['status']];
        if ($hasName) { $cols[] = 'display_name'; $vals[] = mb_substr(pathinfo($p['name'], PATHINFO_FILENAME), 0, 150, 'UTF-8'); }
        if ($redoLib && $redoTire && $r['redo_at'] !== null) {
            array_push($cols, 'redo_at', 'redo_note', 'redo_by', 'redo_exported_at');
            array_push($vals, $r['redo_at'], $r['redo_note'], $r['redo_by'], $r['redo_exported_at']);
        }
        $pdo->prepare("INSERT INTO tire_images (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")->execute($vals);
        $newId = (int)$pdo->lastInsertId();
        redoRetargetEntity($pdo, 'library_image', (int)$p['id'], 'tire_image', $newId);
        $pdo->prepare("DELETE FROM library_images WHERE id = ?")->execute([(int)$p['id']]);
        logActivity($pdo, $cid, 'tire_image', $newId, 'moved_to_tire', 'admin', 'Moved to ' . $label, null, $batch);
        if (!@rename($p['tmp'], $p['final'])) throw new RuntimeException('Could not name ' . $p['name']);
        $renamedDone[] = $p['final'];
        if ($p['twin']) { if (!@rename($p['twin']['tmp'], $p['twin']['final'])) throw new RuntimeException('Could not name the video twin of ' . $p['name']); $renamedDone[] = $p['twin']['final']; }
        $out[] = ['from_id' => (int)$p['id'], 'id' => $newId, 'filename' => $p['name']] + ($p['renamed'] ? ['renamed_from' => (string)$r['filename']] : []);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($renamedDone as $f) { if (is_file($f)) @unlink($f); }
    $cleanup();
    error_log('library-move: ' . $e->getMessage());
    lmFail(500, 'The move failed — nothing was changed');
}

// ---- 3. the originals go; the previews follow ----
foreach ($plan as $p) {
    if (function_exists('previewCopyDerivatives') && previewIsImage($p['final'])) {
        try { previewCopyDerivatives($p['src'], $p['final']); } catch (Throwable $e) { error_log('library-move previews: ' . $e->getMessage()); }
    }
    if (function_exists('previewDelete')) previewDelete($p['src']);
    @unlink($p['src']);
    if ($p['twin']) @unlink($p['twin']['src']);
}

$first = $plan ? (string)$plan[0]['row']['status'] : 'pending';
echo json_encode([
    'ok'     => true,
    'moved'  => count($out),
    'items'  => $out,
    'tire'   => ['id' => (int)$tire['id'], 'name' => (string)$tire['name']],
    'series' => ['id' => (int)$series['id'], 'name' => (string)$series['name'], 'created' => $created],
    'url'    => clientUrl('assets.php', ['client' => $co['slug'], 'view' => 'collections', 'item' => (int)$tire['id'], 'series' => (int)$series['id'], 'filter' => $first]),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
