<?php
/**
 * Flows endpoint (mirrors email-status.php; contract in scratchpad flows-design.md §3).
 *
 * POST only, JSON reply, EVERY action is admin-only (a client seat gets 403). Body is
 * form-encoded or JSON (Content-Type: application/json). Fields:
 *   action      create_flow | rename_flow | delete_flow | reorder_flows |
 *               add_step | remove_step | move_step | set_step_timing | seed_series
 *   client      tenant slug (App.post appends it) — the flow / email must belong to it
 *   flow_id, email_id, position, name, description, timing_text, note, flow_ids[]
 *
 * Tenant rule: the posted client slug must resolve to a company (400 when blank, 404 unknown),
 * clientOwnsCompany() must pass, the flow's company_id must equal it (403 otherwise) and any
 * email_id must belong to the same company (403; unknown email 404).
 *
 * Reply: {ok:true, …} per action · errors {ok:false, error} with 400/403/404/405/409/500.
 * Every mutation logs through logEmailFlowActivity() (created, renamed, deleted, step_added,
 * step_removed, step_moved, step_timing, reordered, seeded).
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

function flowFail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function flowReply(array $data): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flowFail(405, 'Method not allowed');
}
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)

if (!hasEmailsTable($pdo) || !hasEmailFlowsTable($pdo)) {
    flowFail(404, 'Flows are not set up yet');
}
if (!isAdmin()) {
    flowFail(403, 'Admin sign-in required');
}

// ---- Body: form-encoded or a JSON object ----
$in = $_POST;
$ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
if (strpos($ctype, 'application/json') !== false) {
    $raw = (string)file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        flowFail(400, 'Invalid JSON body');
    }
    $in = $decoded;
    if (isset($in['client']) && is_string($in['client'])) $_POST['client'] = $in['client'];   // postedClientSlug() / clientOwnsCompany()
    if (isset($in['actor']) && is_string($in['actor']))   $_POST['actor']  = $in['actor'];    // actorFromPost()
}

$action = is_string($in['action'] ?? null) ? trim($in['action']) : '';
$actions = ['create_flow', 'rename_flow', 'delete_flow', 'reorder_flows', 'add_step', 'remove_step', 'move_step', 'set_step_timing', 'seed_series'];
if (!in_array($action, $actions, true)) {
    flowFail(400, 'Unknown action');
}

// ---- Tenant: the posted client slug names the company every id must belong to ----
$slug = postedClientSlug();
if ($slug === '') {
    flowFail(400, 'Pick a client first');
}
$cs = $pdo->prepare("SELECT id, name, slug FROM companies WHERE slug = ?");
$cs->execute([$slug]);
$company = $cs->fetch();
if (!$company) {
    flowFail(404, 'Unknown client');
}
$companyId = (int)$company['id'];
if (!clientOwnsCompany($pdo, $companyId)) {
    flowFail(403, 'This client is not yours');
}
$clientSlug = (string)$company['slug'];   // clientUrl() scope for the URLs in replies

$actor = actorFromPost();

/** Scalar field from the body (null when absent). */
function flowField(array $in, string $key): ?string {
    if (!array_key_exists($key, $in) || $in[$key] === null) return null;
    if (is_array($in[$key])) return null;
    return (string)$in[$key];
}
/** Validated flow name (400 on blank / too long). */
function flowNameOr400(array $in): string {
    $name = trim(preg_replace('/\s+/u', ' ', (string)(flowField($in, 'name') ?? '')));
    if ($name === '') flowFail(400, 'Flow name is required');
    if (mb_strlen($name, 'UTF-8') > 120) flowFail(400, 'Flow name is too long (max 120 characters)');
    return $name;
}
/** Load the flow named by flow_id and enforce the tenant rule (400 / 404 / 403). */
function flowOrFail(PDO $pdo, array $in, int $companyId): array {
    $id = (int)(flowField($in, 'flow_id') ?? 0);
    if ($id <= 0) flowFail(400, 'Invalid flow id');
    $flow = emailFlowById($pdo, $id);
    if (!$flow) flowFail(404, 'Flow not found');
    if ((int)$flow['company_id'] !== $companyId) flowFail(403, 'This flow belongs to another client');
    return $flow;
}
/** Load the email named by email_id and enforce the tenant rule (400 / 404 / 403). */
function flowEmailOrFail(PDO $pdo, array $in, int $companyId): array {
    $id = (int)(flowField($in, 'email_id') ?? 0);
    if ($id <= 0) flowFail(400, 'Invalid email id');
    $email = emailById($pdo, $id);
    if (!$email) flowFail(404, 'Email not found');
    if ((int)$email['company_id'] !== $companyId) flowFail(403, 'This email belongs to another client');
    return $email;
}
/** Flow row for replies (adds url). */
function flowOut(array $flow): array {
    return [
        'id'          => (int)$flow['id'],
        'name'        => (string)$flow['name'],
        'slug'        => (string)$flow['slug'],
        'description' => $flow['description'] ?? null,
        'sort_order'  => (int)($flow['sort_order'] ?? 0),
        'step_count'  => (int)($flow['step_count'] ?? 0),
        'url'         => emailFlowUrl($flow),
    ];
}
/** Step row for replies: the emailFlowSteps() shape + timing, label and email key/label/url. */
function stepOut(array $step): array {
    $e = $step['email'] ?? [];
    $key = $e ? emailStatusKey($e) : 'draft';
    $step['timing'] = emailFlowTiming($step);
    $step['label']  = $e ? emailDisplayLabel($e) : '';
    if ($e) {
        $step['email']['key']   = $key;
        $step['email']['label'] = emailStatusLabelForKey($key);
        $step['email']['url']   = emailUrl($e);
    }
    return $step;
}
/** The step for one email in a flow, with its email attached (404 when the email is not in the flow). */
function stepWithEmailOr404(PDO $pdo, int $flowId, int $emailId): array {
    foreach (emailFlowSteps($pdo, $flowId) as $st) if ((int)$st['email_id'] === $emailId) return $st;
    flowFail(404, 'That email is not in this flow');
    return [];
}
/** Worker B's card partial, when present. */
function flowCardHtml(array $step, array $opts): ?string {
    $partial = __DIR__ . '/partials/components/flow-card.php';
    if (is_file($partial)) require_once $partial;
    if (!function_exists('renderFlowCard')) return null;
    return (string)renderFlowCard($step, $opts);
}

try {
    // ---- create_flow ----
    if ($action === 'create_flow') {
        $name = flowNameOr400($in);
        $pdo->beginTransaction();
        $id = createEmailFlow($pdo, $companyId, $name, flowField($in, 'description'));
        if ($id <= 0) throw new RuntimeException('createEmailFlow returned 0');
        logEmailFlowActivity($pdo, $actor, 'created', $id, "Flow {$name} created", null, null, $companyId);
        $pdo->commit();
        $flow = emailFlowById($pdo, $id) ?: ['id' => $id, 'name' => $name, 'slug' => emailSlugify($name), 'description' => null, 'sort_order' => 0, 'step_count' => 0];
        flowReply(['flow' => flowOut($flow)]);
    }

    // ---- rename_flow ----
    if ($action === 'rename_flow') {
        $flow = flowOrFail($pdo, $in, $companyId);
        $name = flowNameOr400($in);
        $desc = array_key_exists('description', $in) ? flowField($in, 'description') : ($flow['description'] ?? null);
        $descClean = trim(str_replace(["\r\n", "\r"], "\n", (string)$desc));
        $descClean = $descClean === '' ? null : $descClean;
        $nameChanged = $name !== (string)$flow['name'];
        $descChanged = $descClean !== ($flow['description'] ?? null);
        $pdo->beginTransaction();
        if ($nameChanged || $descChanged) {
            renameEmailFlow($pdo, (int)$flow['id'], $name, $descClean);
            $detail = $nameChanged ? $flow['name'] . ' → ' . $name : '';
            if ($descChanged) $detail .= ($detail !== '' ? ' · ' : '') . 'description edited';
            logEmailFlowActivity($pdo, $actor, 'renamed', (int)$flow['id'], "Flow {$flow['name']} renamed", $detail, null, $companyId);
        }
        $pdo->commit();
        $flow['name'] = $name;
        $flow['description'] = $descClean;
        flowReply(['flow' => flowOut($flow), 'changed' => ($nameChanged || $descChanged) ? 1 : 0]);
    }

    // ---- delete_flow ----
    if ($action === 'delete_flow') {
        $flow = flowOrFail($pdo, $in, $companyId);
        $pdo->beginTransaction();
        deleteEmailFlow($pdo, (int)$flow['id']);
        logEmailFlowActivity($pdo, $actor, 'deleted', (int)$flow['id'], "Flow {$flow['name']} deleted", null, null, $companyId);
        $pdo->commit();
        flowReply(['flow_id' => (int)$flow['id'], 'deleted' => 1]);
    }

    // ---- reorder_flows ----
    if ($action === 'reorder_flows') {
        $raw = $in['flow_ids'] ?? null;
        if (is_string($raw)) $raw = preg_split('/[,\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($raw) || !$raw) flowFail(400, 'flow_ids is required');
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static function ($i) { return $i > 0; })));
        if (!$ids) flowFail(400, 'flow_ids is required');
        $own = [];
        foreach (emailFlowsForCompany($pdo, $companyId) as $f) $own[(int)$f['id']] = $f;
        foreach ($ids as $id) {
            if (!isset($own[$id])) {
                if (emailFlowById($pdo, $id)) flowFail(403, 'This flow belongs to another client');
                flowFail(404, 'Flow not found');
            }
        }
        $pdo->beginTransaction();
        reorderEmailFlows($pdo, $companyId, $ids);
        $final = emailFlowsForCompany($pdo, $companyId);
        logEmailFlowActivity($pdo, $actor, 'reordered', $ids[0], 'Flows reordered',
            implode(', ', array_map(static function ($f) { return $f['slug']; }, $final)), null, $companyId);
        $pdo->commit();
        flowReply(['flow_ids' => array_map(static function ($f) { return (int)$f['id']; }, $final)]);
    }

    // ---- add_step ----
    if ($action === 'add_step') {
        $flow  = flowOrFail($pdo, $in, $companyId);
        $email = flowEmailOrFail($pdo, $in, $companyId);
        if (emailFlowStepByEmail($pdo, (int)$flow['id'], (int)$email['id']) !== null) {
            flowFail(409, 'That email is already in this flow');
        }
        $posRaw = flowField($in, 'position');
        $position = ($posRaw === null || trim($posRaw) === '' || !is_numeric($posRaw)) ? null : max(0, (int)$posRaw);
        $pdo->beginTransaction();
        $step = addEmailFlowStep($pdo, (int)$flow['id'], (int)$email['id'], $position);
        $label = emailDisplayLabel($email);
        logEmailFlowActivity($pdo, $actor, 'step_added', (int)$flow['id'], "{$label} added to flow {$flow['name']}",
            'position ' . (int)($step['position'] ?? 0), null, $companyId);
        $pdo->commit();
        $count = (int)$flow['step_count'] + 1;
        $flow['step_count'] = $count;
        $out = stepOut($step);
        $html = flowCardHtml($out, ['admin' => true, 'flow' => $flow, 'company' => $company, 'index' => (int)$out['position'], 'count' => $count]);
        flowReply(['step' => $out, 'html' => $html, 'step_count' => $count, 'flow_id' => (int)$flow['id']]);
    }

    // ---- remove_step ----
    if ($action === 'remove_step') {
        $flow  = flowOrFail($pdo, $in, $companyId);
        $email = flowEmailOrFail($pdo, $in, $companyId);
        if (emailFlowStepByEmail($pdo, (int)$flow['id'], (int)$email['id']) === null) {
            flowFail(404, 'That email is not in this flow');
        }
        $pdo->beginTransaction();
        removeEmailFlowStep($pdo, (int)$flow['id'], (int)$email['id']);
        logEmailFlowActivity($pdo, $actor, 'step_removed', (int)$flow['id'], emailDisplayLabel($email) . " removed from flow {$flow['name']}", null, null, $companyId);
        $pdo->commit();
        flowReply(['flow_id' => (int)$flow['id'], 'email_id' => (int)$email['id'], 'removed' => 1, 'step_count' => max(0, (int)$flow['step_count'] - 1)]);
    }

    // ---- move_step ----
    if ($action === 'move_step') {
        $flow  = flowOrFail($pdo, $in, $companyId);
        $email = flowEmailOrFail($pdo, $in, $companyId);
        $current = emailFlowStepByEmail($pdo, (int)$flow['id'], (int)$email['id']);
        if ($current === null) flowFail(404, 'That email is not in this flow');
        $posRaw = flowField($in, 'position');
        if ($posRaw === null || trim($posRaw) === '' || !is_numeric($posRaw)) flowFail(400, 'Position is required');
        $to = max(0, min((int)$flow['step_count'] - 1, (int)$posRaw));
        $pdo->beginTransaction();
        if ($to !== (int)$current['position']) {
            moveEmailFlowStep($pdo, (int)$flow['id'], (int)$email['id'], $to);
            logEmailFlowActivity($pdo, $actor, 'step_moved', (int)$flow['id'], emailDisplayLabel($email) . " moved in flow {$flow['name']}",
                (int)$current['position'] . ' → ' . $to, null, $companyId);
        }
        $pdo->commit();
        $step = stepWithEmailOr404($pdo, (int)$flow['id'], (int)$email['id']);
        flowReply(['step' => stepOut($step), 'step_count' => (int)$flow['step_count'], 'flow_id' => (int)$flow['id']]);
    }

    // ---- set_step_timing ----
    if ($action === 'set_step_timing') {
        $flow  = flowOrFail($pdo, $in, $companyId);
        $email = flowEmailOrFail($pdo, $in, $companyId);
        $current = emailFlowStepByEmail($pdo, (int)$flow['id'], (int)$email['id']);
        if ($current === null) flowFail(404, 'That email is not in this flow');
        $timing = array_key_exists('timing_text', $in) ? flowField($in, 'timing_text') : $current['timing_text'];
        $note   = array_key_exists('note', $in) ? flowField($in, 'note') : $current['note'];
        $timingClean = trim(str_replace(["\r\n", "\r"], "\n", (string)$timing));
        $timingClean = $timingClean === '' ? null : mb_substr($timingClean, 0, 255);
        $noteClean   = trim(str_replace(["\r\n", "\r"], "\n", (string)$note));
        $noteClean   = $noteClean === '' ? null : $noteClean;
        $changed = $timingClean !== $current['timing_text'] || $noteClean !== $current['note'];
        $pdo->beginTransaction();
        if ($changed) {
            setEmailFlowStepTiming($pdo, (int)$flow['id'], (int)$email['id'], $timingClean, $noteClean);
            $detail = ($current['timing_text'] ?? '') . ' → ' . ($timingClean ?? '');
            if ($noteClean !== $current['note']) $detail .= ' · note edited';
            logEmailFlowActivity($pdo, $actor, 'step_timing', (int)$flow['id'], 'Timing edited on ' . emailDisplayLabel($email) . " in flow {$flow['name']}", $detail, null, $companyId);
        }
        $pdo->commit();
        $step = stepWithEmailOr404($pdo, (int)$flow['id'], (int)$email['id']);
        flowReply(['step' => stepOut($step), 'changed' => $changed ? 1 : 0, 'flow_id' => (int)$flow['id']]);
    }

    // ---- seed_series ----
    if ($action === 'seed_series') {
        $pdo->beginTransaction();
        $res = seedEmailFlowsFromSeries($pdo, $companyId);
        $batchId = newBatchId();
        foreach ($res['flows'] as $f) {
            logEmailFlowActivity($pdo, $actor, 'seeded', (int)$f['id'],
                "Flow {$f['name']} seeded from series {$f['series']} ({$f['step_count']} step" . ($f['step_count'] === 1 ? '' : 's') . ')',
                null, $batchId, $companyId);
        }
        $pdo->commit();
        $flows = [];
        foreach ($res['flows'] as $f) {
            $flows[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'slug' => $f['slug'], 'step_count' => (int)$f['step_count'], 'series' => $f['series'], 'url' => emailFlowUrl($f)];
        }
        flowReply(['created' => (int)$res['created'], 'skipped' => (int)$res['skipped'], 'flows' => $flows]);
    }

    flowFail(400, 'Unknown action');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('flow-status failed: ' . $e->getMessage());
    flowFail(500, 'Database error');
}
