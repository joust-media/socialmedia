<?php
/**
 * Tire IMAGE status / comment update endpoint.
 * Each tire image is independently approvable / commentable.
 *
 * Accepts POST: id (int = tire_images.id), and optionally:
 *   - status (pending|approved|denied)
 *   - comment (string, max 2000 chars; '' clears it)
 * Returns JSON.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
if (!function_exists('currentAdmin')) { require_once __DIR__ . '/auth.php'; }   // helpers.php already loads it; belt and braces

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)

$id      = (int)($_POST['id'] ?? 0);
$action  = $_POST['action'] ?? '';

// ---- Delete entire tire ---- (admin only; approve / deny / comment below stay open)
if ($action === 'delete_tire') {
    if (!currentAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin sign-in required']);
        exit;
    }
    $tireId = (int)($_POST['tire_id'] ?? 0);
    if ($tireId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid tire_id']);
        exit;
    }
    try {
        $pdo->beginTransaction();
        // Capture company_id before cascade.
        $coStmt = $pdo->prepare("SELECT company_id, name FROM tires WHERE id = ?");
        $coStmt->execute([$tireId]);
        $tireRow = $coStmt->fetch();

        // Delete image files from disk
        $imgs = $pdo->prepare("SELECT image_url FROM tire_images WHERE tire_id = ?");
        $imgs->execute([$tireId]);
        foreach ($imgs->fetchAll() as $row) {
            $path = uploadsPathOrNull((string)$row['image_url']);   // realpath-contained in uploads/
            if ($path !== null) { @unlink($path); }
        }
        // CASCADE deletes tire_images and tire_categories
        $pdo->prepare("DELETE FROM tires WHERE id = ?")->execute([$tireId]);
        if ($tireRow) {
            logActivity($pdo, (int)$tireRow['company_id'], 'tire', $tireId,
                'deleted', actorFromPost(),
                "Deleted item #{$tireId} (" . ($tireRow['name'] ?? '') . ")");
        }
        $pdo->commit();
        echo json_encode(['ok' => true, 'tire_id' => $tireId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Delete failed']);
    }
    exit;
}

// ---- Series actions (tire-series-lib.php; design §5) ----
//   approve_series {series_id}            client or admin — approves every pending render of the series
//   delete_image {id}                     admin — row + file + thumb
//   set_reference {id}                    admin — moves the image to sort_order 0 among the reference images
//   series_create {tire_id, name}         admin
//   series_rename {series_id, name}       admin
//   series_delete {series_id, delete_files} admin
//   series_reorder {tire_id, ids[]}       admin
$seriesActions = ['approve_series', 'delete_image', 'set_reference', 'series_create', 'series_rename', 'series_delete', 'series_reorder'];
if (in_array($action, $seriesActions, true)) {
    $fail = static function (int $code, string $msg): void {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    };
    if (!hasTireSeries($pdo)) { $fail(409, 'Render series are not set up yet — run migrate.php.'); }
    if ($action !== 'approve_series' && !currentAdmin()) { $fail(403, 'Admin sign-in required'); }
    $actor   = actorFromPost();
    $batchId = newBatchId();
    /** The tire row (+ company_id) or 404; 403 when the posted client scope (required for a client seat,
     *  optional for the admin) is another company's — like flow-status.php. */
    $loadTire = static function (int $tireId) use ($pdo, $fail): array {
        if ($tireId <= 0) { $fail(400, 'Invalid tire_id'); }
        $s = $pdo->prepare("SELECT t.id, t.company_id, t.name, c.slug AS company_slug FROM tires t INNER JOIN companies c ON c.id = t.company_id WHERE t.id = ?");
        $s->execute([$tireId]);
        $t = $s->fetch();
        if (!$t) { $fail(404, 'Tire not found'); }
        if (!clientOwnsCompany($pdo, (int)$t['company_id'])) { $fail(403, 'This tire belongs to another client'); }
        $scope = postedClientSlug();
        if ($scope !== '' && $scope !== (string)$t['company_slug']) { $fail(403, 'This tire belongs to another client'); }
        return $t;
    };
    try {
        switch ($action) {
            case 'approve_series': {
                $sid = (int)($_POST['series_id'] ?? 0);
                if ($sid <= 0) { $fail(400, 'Invalid series_id'); }
                $series = tireSeriesById($pdo, $sid);
                if (!$series) { $fail(404, 'Series not found'); }
                $tire = $loadTire((int)$series['tire_id']);
                $pdo->beginTransaction();
                $upd = $pdo->prepare("UPDATE tire_images SET status = 'approved' WHERE series_id = ? AND status = 'pending'");
                $upd->execute([$sid]);
                $n = (int)$upd->rowCount();
                if ($n > 0) {
                    logTireSeriesActivity($pdo, $actor, 'approved', $sid,
                        "Approved {$n} render" . ($n === 1 ? '' : 's') . " in " . (string)$tire['name'] . " · " . $series['name'],
                        null, $batchId, (int)$tire['company_id']);
                }
                $pdo->commit();
                $counts = tireSeriesCounts($pdo, (int)$tire['id']);
                $c = $counts['series'][$sid] ?? ['pending' => 0, 'approved' => 0, 'denied' => 0, 'total' => 0];
                echo json_encode(['ok' => true, 'series_id' => $sid, 'approved' => $n, 'counts' => $c]);
                exit;
            }
            case 'delete_image': {
                $imgId = (int)($_POST['id'] ?? 0);
                if ($imgId <= 0) { $fail(400, 'Invalid id'); }
                $img = tireImageById($pdo, $imgId);
                if (!$img) { $fail(404, 'Image not found'); }
                $tire = $loadTire((int)$img['tire_id']);
                $path  = tireImagePath($img);
                $thumb = tireThumbPath($img);
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM tire_images WHERE id = ?")->execute([$imgId]);
                logActivity($pdo, (int)$tire['company_id'], 'tire_image', $imgId, 'deleted', $actor,
                    'Deleted ' . imageDisplayLabel($img) . ' from ' . (string)$tire['name'], null, $batchId);
                $pdo->commit();
                if ($path !== null) { @unlink($path); }
                if ($thumb !== null && is_file($thumb)) { @unlink($thumb); }
                echo json_encode(['ok' => true, 'id' => $imgId, 'series_id' => $img['series_id']]);
                exit;
            }
            case 'set_reference': {
                $imgId = (int)($_POST['id'] ?? 0);
                if ($imgId <= 0) { $fail(400, 'Invalid id'); }
                $img = tireImageById($pdo, $imgId);
                if (!$img) { $fail(404, 'Image not found'); }
                $tire = $loadTire((int)$img['tire_id']);
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE tire_images SET sort_order = sort_order + 1 WHERE tire_id = ? AND series_id IS NULL AND id <> ?")->execute([(int)$tire['id'], $imgId]);
                $pdo->prepare("UPDATE tire_images SET series_id = NULL, sort_order = 0 WHERE id = ?")->execute([$imgId]);
                logActivity($pdo, (int)$tire['company_id'], 'tire_image', $imgId, 'set_reference', $actor,
                    imageDisplayLabel($img) . ' is now the reference image of ' . (string)$tire['name'], null, $batchId);
                $pdo->commit();
                echo json_encode(['ok' => true, 'id' => $imgId, 'tire_id' => (int)$tire['id']]);
                exit;
            }
            case 'series_create': {
                $tire = $loadTire((int)($_POST['tire_id'] ?? 0));
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') { $fail(400, 'Series name is required'); }
                if (mb_strlen($name, 'UTF-8') > 120) { $fail(400, 'Series name is too long (max 120 characters)'); }
                $pdo->beginTransaction();
                $series = createTireSeries($pdo, (int)$tire['id'], $name);
                if (empty($series['created'])) { $pdo->rollBack(); $fail(409, 'A series with that name already exists on this tire'); }
                unset($series['created']);
                logTireSeriesActivity($pdo, $actor, 'created', (int)$series['id'],
                    'Created series ' . (string)$tire['name'] . ' · ' . $series['name'], null, $batchId, (int)$tire['company_id']);
                $pdo->commit();
                echo json_encode(['ok' => true, 'series' => $series]);
                exit;
            }
            case 'series_rename': {
                $sid = (int)($_POST['series_id'] ?? 0);
                if ($sid <= 0) { $fail(400, 'Invalid series_id'); }
                $series = tireSeriesById($pdo, $sid);
                if (!$series) { $fail(404, 'Series not found'); }
                $tire = $loadTire((int)$series['tire_id']);
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') { $fail(400, 'Series name is required'); }
                if (mb_strlen($name, 'UTF-8') > 120) { $fail(400, 'Series name is too long (max 120 characters)'); }
                $pdo->beginTransaction();
                $row = renameTireSeries($pdo, $sid, $name);
                if ($name !== $series['name']) {
                    logTireSeriesActivity($pdo, $actor, 'renamed', $sid,
                        'Renamed series ' . $series['name'] . ' → ' . $name . ' (' . (string)$tire['name'] . ')', null, $batchId, (int)$tire['company_id']);
                }
                $pdo->commit();
                echo json_encode(['ok' => true, 'series' => $row]);
                exit;
            }
            case 'series_delete': {
                $sid = (int)($_POST['series_id'] ?? 0);
                if ($sid <= 0) { $fail(400, 'Invalid series_id'); }
                $series = tireSeriesById($pdo, $sid);
                if (!$series) { $fail(404, 'Series not found'); }
                $tire = $loadTire((int)$series['tire_id']);
                $deleteFiles = in_array((string)($_POST['delete_files'] ?? '0'), ['1', 'true', 'on', 'yes'], true);
                $pdo->beginTransaction();
                $res = deleteTireSeries($pdo, $sid, $deleteFiles);
                logTireSeriesActivity($pdo, $actor, 'deleted', $sid,
                    'Deleted series ' . (string)$tire['name'] . ' · ' . $series['name'] . ' (' . (int)$res['images'] . ' renders' . ($deleteFiles ? ', files removed' : '') . ')',
                    null, $batchId, (int)$tire['company_id']);
                $pdo->commit();
                echo json_encode(['ok' => true, 'series_id' => $sid, 'images' => (int)$res['images'], 'files' => (int)$res['files']]);
                exit;
            }
            case 'series_reorder': {
                $tire = $loadTire((int)($_POST['tire_id'] ?? 0));
                $ids = $_POST['ids'] ?? null;
                if (!is_array($ids) || !$ids) { $fail(400, 'ids[] is required'); }
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; })));
                $own = array_map(static function ($s) { return (int)$s['id']; }, tireSeriesForTire($pdo, (int)$tire['id']));
                foreach ($ids as $i) { if (!in_array($i, $own, true)) { $fail(400, 'Series #' . $i . ' does not belong to this tire'); } }
                $pdo->beginTransaction();
                $rows = reorderTireSeries($pdo, (int)$tire['id'], $ids);
                $pdo->commit();
                echo json_encode(['ok' => true, 'tire_id' => (int)$tire['id'], 'series' => $rows]);
                exit;
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('tire-status ' . $action . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
        exit;
    }
}

$hasStat = array_key_exists('status', $_POST);
$hasCmt  = array_key_exists('comment', $_POST);
$status  = $_POST['status']  ?? null;
$comment = $_POST['comment'] ?? null;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}
if (!$hasStat && !$hasCmt) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nothing to update']);
    exit;
}
if ($hasStat && !in_array($status, ['pending', 'approved', 'denied'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid status']);
    exit;
}
// Client verbs are Approve / Deny / Comment only (spec §2): resetting to review is Joust's.
if ($hasStat && $status === 'pending' && !currentAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin sign-in required']);
    exit;
}
// Denying requires a reason — a note of at least 3 characters (spec §4.2 / §9), for every seat.
if ($hasStat && $status === 'denied' && mb_strlen(trim((string)($_POST['comment'] ?? '')), 'UTF-8') < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please add a short note (at least 3 characters) explaining what should change.']);
    exit;
}
if ($hasCmt) {
    $comment = trim((string)$comment);
    if (strlen($comment) > 2000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Comment too long (max 2000 chars)']);
        exit;
    }
    if ($comment === '') { $comment = null; }
}

try {
    $pdo->beginTransaction();

    $hasDisplayName = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0;
    $nameSel = $hasDisplayName ? 'ti.display_name' : "'' AS display_name";
    $before = $pdo->prepare("
        SELECT t.company_id, ti.status, ti.client_comment, ti.caption, {$nameSel}
          FROM tire_images ti
          INNER JOIN tires t ON t.id = ti.tire_id
         WHERE ti.id = ?
         FOR UPDATE
    ");
    $before->execute([$id]);
    $prev = $before->fetch();
    if (!$prev) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Image not found']);
        exit;
    }
    // Tenant scope: a client seat may only act on its own company's images (admin bypasses).
    if (!clientOwnsCompany($pdo, (int)$prev['company_id'])) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'This image belongs to another client']);
        exit;
    }
    // Friendly label for activity-log summaries — preferred over bare "image #42".
    $imgLabel = imageDisplayLabel(['display_name' => $prev['display_name'], 'caption' => $prev['caption'], 'id' => $id]);

    $sets   = [];
    $params = [];
    if ($hasStat) { $sets[] = 'status = ?';         $params[] = $status; }
    if ($hasCmt)  { $sets[] = 'client_comment = ?'; $params[] = $comment; }
    $params[] = $id;

    $sql  = 'UPDATE tire_images SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $companyId = (int)$prev['company_id'];
    $actor     = actorFromPost();
    $batchId   = newBatchId();

    if ($hasStat && $prev['status'] !== $status) {
        $action = ($status === 'approved') ? 'approved'
                : (($status === 'denied')  ? 'denied'
                : 'reset_pending');
        logActivity($pdo, $companyId, 'tire_image', $id, $action, $actor,
            "{$imgLabel} " . actionLabel($action),
            null, $batchId);
    }
    if ($hasCmt) {
        $prevCmt = $prev['client_comment'];
        // Chat semantics: any non-empty submission becomes a fresh message in the thread,
        // even if it matches the previous text. Empty submissions only clear once.
        if ($comment !== null && $comment !== '') {
            logActivity($pdo, $companyId, 'tire_image', $id, 'commented', $actor,
                "Comment on {$imgLabel}", $comment, $batchId);
        } elseif (($prevCmt ?? '') !== '') {
            logActivity($pdo, $companyId, 'tire_image', $id, 'uncommented', $actor,
                "Cleared comment on {$imgLabel}", $prevCmt, $batchId);
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok'      => true,
        'id'      => $id,
        'status'  => $hasStat ? $status  : null,
        'comment' => $hasCmt  ? $comment : null,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
