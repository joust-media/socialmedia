<?php
/**
 * Assign endpoint — where an email / page belongs (admin only; static/js/assign.js drives it).
 *
 * Every action is admin-only: the client seat (or no session) gets a JSON 403, cross-site POSTs a
 * JSON 403 (requireSameSiteFetch). `client` is the tenant slug every id must belong to (400 blank,
 * 404 unknown, 403 when an id belongs to another client, 404 unknown id).
 *
 *   GET  ?action=options&client=<slug>&kind=email|page[&ids=3,5]
 *        → {ok, kind, from:{slug,name}, clients:[{slug,name,avatar,emails,pages,current}],
 *           flows:[{id,name,slug,step_count,steps:[{email_id,label,position}]}],      (kind=email)
 *           audiences:[{id,name,slug,count,selected}],                                 (kind=email)
 *           items:[{id,label}]}
 *
 *   POST action=move           kind=email|page, ids (3,5 or ids[]), to=<target client slug>
 *        Files move copy → verify → commit → delete (assign-lib.php). Emails keep their Audiences (by
 *        name, created in the target when missing) and leave the source client's flows; a code / slug
 *        already used in the target gets a -2 suffix (reported in items[].renamed_from).
 *        → {ok, moved, to:{slug,name,url}, items, renamed, files, flows_left, cleanup_failed}
 *   POST action=add_to_flow    ids, flow_id | new_flow=<name>, position (0-based insert index; blank = end)
 *        Emails already in the flow are skipped (409 when every one is).
 *        → {ok, flow:{id,name,slug,url,step_count}, added:[{email_id, position}], skipped}
 *   POST action=set_audiences  ids, add[]=<audience id>, remove[]=<audience id>, new=<names, comma-separated>
 *        → {ok, items:[{id, audiences:[{id,name,slug}]}], audiences:[…], changed}
 *   POST action=create_email   client=<slug>, title*, code (blank → next E<n>), source=file|paste|url|none,
 *        file (multipart .html/.htm ≤ 10 MB) | html (pasted) | html_url (http/https)
 *        A file / paste is stored at media/emails/<client>/<code>-<id>.html (emails-lib.php).
 *        → {ok, id, code, url} (url = emails.php?client=…&email=ID — the new Draft opens there)
 *   POST action=create_page    client=<slug>, title*, source=file|paste|url|none, file | html | url
 *        A file / paste becomes media/pages/<client>/<slug>/index.html (embedded base64 assets are
 *        extracted like page-upload.php does). → {ok, id, slug, url}
 *
 * Errors {ok:false, error} with 400 / 403 / 404 / 405 / 409 / 413 / 415 / 422 / 500. Activity: 'moved'
 * (email / page, logged on the target client), step_added (flow), edited_groups (email), created (+ uploaded).
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/assign-lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function assignFail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function assignReply(array $data): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
if ($method === 'POST') {
    requireSameSiteFetch();
} elseif ($method !== 'GET' || $action !== 'options') {
    assignFail(405, 'Method not allowed');
}
if (!isAdmin()) {
    assignFail(403, 'Admin sign-in required');
}
$actions = ['options', 'move', 'add_to_flow', 'set_audiences', 'create_email', 'create_page'];
if (!in_array($action, $actions, true) || ($action === 'options') !== ($method === 'GET')) {
    assignFail(400, 'Unknown action');
}

// ---- Tenant: the posted client slug names the company every id belongs to ----
$slug = postedClientSlug();
if ($slug === '') assignFail(400, 'Pick a client first');
$cs = $pdo->prepare("SELECT id, name, slug, logo_url FROM companies WHERE slug = ?");
$cs->execute([$slug]);
$company = $cs->fetch();
if (!$company) assignFail(404, 'Unknown client');
$companyId  = (int)$company['id'];
$clientSlug = (string)$company['slug'];   // clientUrl() scope for the URLs in replies
$actor      = actorFromPost();
$in         = $method === 'POST' ? $_POST : $_GET;
$kind       = (string)($in['kind'] ?? 'email') === 'page' ? 'page' : 'email';
if ($action === 'create_page') $kind = 'page';
if (in_array($action, ['create_email', 'add_to_flow', 'set_audiences'], true)) {
    if ($kind !== 'email') assignFail(400, 'Only emails have flows and audiences');
}

if ($kind === 'email' && !hasEmailsTable($pdo)) assignFail(404, 'Emails are not set up yet');
if ($kind === 'page' && !hasPagesTable($pdo))   assignFail(404, 'Pages are not set up yet');

/** The rows named by ids — all of them must be this client's (403) and exist (404). */
function assignLoadItems(PDO $pdo, string $kind, array $ids, int $companyId): array {
    if (!$ids) assignFail(400, 'Pick at least one ' . $kind);
    $rows = [];
    foreach ($ids as $id) {
        $row = $kind === 'page' ? pageById($pdo, $id) : emailById($pdo, $id);
        if (!$row) assignFail(404, ucfirst($kind) . ' not found');
        if ((int)$row['company_id'] !== $companyId) assignFail(403, 'This ' . $kind . ' belongs to another client');
        $rows[] = $row;
    }
    return $rows;
}
function assignItemLabel(string $kind, array $row): string {
    return $kind === 'page' ? pageDisplayLabel($row) : emailDisplayLabel($row);
}
/** The uploaded / pasted HTML for a create action: [html|null, source] (url / none → null). */
function assignIncomingHtml(): array {
    $source = (string)($_POST['source'] ?? '');
    if ($source === 'file') {
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || is_array($f['name'] ?? null)) assignFail(400, 'Choose an HTML file');
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) assignFail(413, 'That file is too large for a direct upload — use the full form instead.');
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) assignFail(400, 'Choose an HTML file');
        $ext = strtolower((string)pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'htm'], true)) assignFail(415, 'Only .html or .htm files can be used here.');
        if ((int)$f['size'] > 10485760) assignFail(413, 'The HTML is over 10 MB — use the full form (chunked upload) instead.');
        $html = (string)@file_get_contents((string)$f['tmp_name']);
    } elseif ($source === 'paste') {
        $html = (string)($_POST['html'] ?? '');
    } else {
        return [null, in_array($source, ['url', 'none', ''], true) ? ($source === '' ? 'none' : $source) : 'none'];
    }
    $problem = assignHtmlProblem($html);
    if ($problem !== '') assignFail(strpos($problem, 'MB') !== false ? 413 : 422, $problem);
    return [$html, $source];
}

try {
    // =================================================================== options
    if ($action === 'options') {
        $ids   = assignIds($in['ids'] ?? '');
        $items = $ids ? assignLoadItems($pdo, $kind, $ids, $companyId) : [];
        $clients = [];
        foreach ($pdo->query("SELECT id, name, slug, logo_url FROM companies ORDER BY name ASC")->fetchAll() as $c) {
            $clients[] = [
                'slug'    => (string)$c['slug'],
                'name'    => (string)$c['name'],
                'avatar'  => function_exists('clientAvatar') ? clientAvatar($c) : '',
                'emails'  => companyHasEmails($c, $pdo),
                'pages'   => companyHasPages($c, $pdo),
                'current' => (int)$c['id'] === $companyId,
            ];
        }
        $out = [
            'kind'    => $kind,
            'from'    => ['slug' => $clientSlug, 'name' => (string)$company['name']],
            'clients' => $clients,
            'items'   => array_map(static function ($r) use ($kind) { return ['id' => (int)$r['id'], 'label' => assignItemLabel($kind, $r)]; }, $items),
        ];
        if ($kind === 'email') {
            $flows = [];
            if (hasEmailFlowsTable($pdo)) {
                foreach (emailFlowsForCompany($pdo, $companyId) as $f) {
                    $steps = [];
                    foreach (emailFlowSteps($pdo, (int)$f['id']) as $st) {
                        $steps[] = ['email_id' => (int)$st['email_id'], 'position' => (int)$st['position'],
                                    'label' => !empty($st['email']) ? emailDisplayLabel($st['email']) : 'Email #' . (int)$st['email_id']];
                    }
                    $flows[] = ['id' => (int)$f['id'], 'name' => (string)$f['name'], 'slug' => (string)$f['slug'], 'step_count' => count($steps), 'steps' => $steps];
                }
            }
            $counts = emailGroupCounts($pdo, $companyId);
            $sel = [];
            foreach ($items as $r) foreach ($r['groups'] ?? [] as $g) $sel[(int)$g['id']] = ($sel[(int)$g['id']] ?? 0) + 1;
            $aud = [];
            foreach (emailGroupsForCompany($pdo, $companyId) as $g) {
                $aud[] = ['id' => (int)$g['id'], 'name' => (string)$g['name'], 'slug' => (string)$g['slug'],
                          'count' => (int)($counts[(int)$g['id']] ?? 0), 'selected' => (int)($sel[(int)$g['id']] ?? 0)];
            }
            $out['flows'] = $flows;
            $out['audiences'] = $aud;
        }
        assignReply($out);
    }

    // =================================================================== move
    if ($action === 'move') {
        $items = assignLoadItems($pdo, $kind, assignIds($in['ids'] ?? ''), $companyId);
        $toSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($in['to'] ?? ''))));
        if ($toSlug === '') assignFail(400, 'Pick the client to move to');
        $cs->execute([$toSlug]);
        $to = $cs->fetch();
        if (!$to) assignFail(404, 'Unknown target client');
        if ((int)$to['id'] === $companyId) assignFail(400, 'Pick a different client — these already belong to ' . $company['name']);
        try {
            $res = $kind === 'page'
                ? assignMovePages($pdo, $company, $to, $items, $actor)
                : assignMoveEmails($pdo, $company, $to, $items, $actor);
        } catch (RuntimeException $ex) {
            error_log('assign move failed: ' . $ex->getMessage() . ($ex->getPrevious() ? ' (' . $ex->getPrevious()->getMessage() . ')' : ''));
            assignFail(500, 'Nothing was moved: ' . $ex->getMessage());
        }
        $toUrl = $kind === 'page' ? pagesUrl(['client' => $to['slug'], 'status' => 'all']) : emailsUrl(['client' => $to['slug'], 'status' => 'all']);
        assignReply($res + ['kind' => $kind, 'to' => ['slug' => (string)$to['slug'], 'name' => (string)$to['name'], 'url' => $toUrl]]);
    }

    // =================================================================== add_to_flow
    if ($action === 'add_to_flow') {
        if (!hasEmailFlowsTable($pdo)) assignFail(404, 'Flows are not set up yet');
        $items = assignLoadItems($pdo, 'email', assignIds($in['ids'] ?? ''), $companyId);
        $newName = trim(preg_replace('/\s+/u', ' ', (string)($in['new_flow'] ?? '')));
        $flowId  = (int)($in['flow_id'] ?? 0);
        $flow = null;
        if ($newName === '') {
            if ($flowId <= 0) assignFail(400, 'Pick a flow');
            $flow = emailFlowById($pdo, $flowId);
            if (!$flow) assignFail(404, 'Flow not found');
            if ((int)$flow['company_id'] !== $companyId) assignFail(403, 'This flow belongs to another client');
        } elseif (mb_strlen($newName, 'UTF-8') > 120) {
            assignFail(400, 'Flow name is too long (max 120 characters)');
        }
        $posRaw = trim((string)($in['position'] ?? ''));
        $pos = ($posRaw === '' || !is_numeric($posRaw)) ? null : max(0, (int)$posRaw);
        $added = []; $skipped = 0;
        $pdo->beginTransaction();
        if ($flow === null) {
            $flowId = createEmailFlow($pdo, $companyId, $newName, null);
            if ($flowId <= 0) throw new RuntimeException('createEmailFlow returned 0');
            logEmailFlowActivity($pdo, $actor, 'created', $flowId, "Flow {$newName} created", null, null, $companyId);
            $flow = emailFlowById($pdo, $flowId);
        }
        $batch = newBatchId();
        foreach ($items as $e) {
            if (emailFlowStepByEmail($pdo, (int)$flow['id'], (int)$e['id']) !== null) { $skipped++; continue; }
            $step = addEmailFlowStep($pdo, (int)$flow['id'], (int)$e['id'], $pos);
            $at = (int)($step['position'] ?? 0);
            if ($pos !== null) $pos = $at + 1;   // a selection lands in order, one after the other
            $added[] = ['email_id' => (int)$e['id'], 'position' => $at];
            logEmailFlowActivity($pdo, $actor, 'step_added', (int)$flow['id'], emailDisplayLabel($e) . " added to flow {$flow['name']}",
                'position ' . $at, $batch, $companyId);
        }
        if (!$added) {
            $pdo->rollBack();
            assignFail(409, count($items) === 1 ? 'That email is already in this flow' : 'Those emails are already in this flow');
        }
        $pdo->commit();
        $flow = emailFlowById($pdo, (int)$flow['id']);
        assignReply([
            'flow'    => ['id' => (int)$flow['id'], 'name' => (string)$flow['name'], 'slug' => (string)$flow['slug'],
                          'url' => emailFlowUrl($flow), 'step_count' => (int)($flow['step_count'] ?? 0)],
            'added'   => $added,
            'skipped' => $skipped,
        ]);
    }

    // =================================================================== set_audiences
    if ($action === 'set_audiences') {
        $items = assignLoadItems($pdo, 'email', assignIds($in['ids'] ?? ''), $companyId);
        $own = [];
        foreach (emailGroupsForCompany($pdo, $companyId) as $g) $own[(int)$g['id']] = $g;
        $pick = static function ($raw) use ($own, $pdo, $companyId): array {
            $ids = assignIds($raw ?? []);
            foreach ($ids as $gid) {
                if (!isset($own[$gid])) {
                    $s = $pdo->prepare("SELECT company_id FROM email_groups WHERE id = ?");
                    $s->execute([$gid]);
                    $c = $s->fetchColumn();
                    assignFail($c === false ? 404 : 403, $c === false ? 'Audience not found' : 'This audience belongs to another client');
                }
            }
            return $ids;
        };
        $add = $pick($in['add'] ?? []);
        $remove = $pick($in['remove'] ?? []);
        $newNames = array_values(array_filter(array_map(static function ($n) {
            return trim(preg_replace('/\s+/u', ' ', $n));
        }, preg_split('/[,|\n]+/', (string)($in['new'] ?? '')) ?: []), static function ($n) { return $n !== '' && emailSlugify($n) !== ''; }));
        foreach ($newNames as $n) if (mb_strlen($n, 'UTF-8') > 80) assignFail(400, 'Audience names are 80 characters max');

        $pdo->beginTransaction();
        foreach ($newNames as $n) { $gid = ensureEmailGroup($pdo, $companyId, $n); if ($gid) $add[] = $gid; }
        $add = array_values(array_unique($add));
        $all = [];
        foreach (emailGroupsForCompany($pdo, $companyId) as $g) $all[(int)$g['id']] = $g;
        $names = static function (array $ids) use ($all) {
            return implode('|', array_map(static function ($i) use ($all) { return $all[$i]['name'] ?? ('#' . $i); }, $ids));
        };
        $batch = newBatchId();
        $changed = 0; $outItems = [];
        foreach ($items as $e) {
            $cur = array_map(static function ($g) { return (int)$g['id']; }, $e['groups'] ?? []);
            $next = array_values(array_diff(array_unique(array_merge($cur, $add)), $remove));
            $a = $cur; sort($a); $b = $next; sort($b);
            if ($a !== $b) {
                setEmailGroups($pdo, (int)$e['id'], $next);
                logEmailActivity($pdo, $actor, 'edited_groups', (int)$e['id'], emailFieldLabel('groups') . ' edited on ' . emailDisplayLabel($e),
                    mb_substr($names($a), 0, 200) . ' → ' . mb_substr($names($b), 0, 200), $batch, $companyId);
                $changed++;
            }
            $ord = array_values(array_filter(array_keys($all), static function ($i) use ($next) { return in_array($i, $next, true); }));
            $outItems[] = ['id' => (int)$e['id'], 'audiences' => array_map(static function ($i) use ($all) {
                return ['id' => $i, 'name' => (string)$all[$i]['name'], 'slug' => (string)$all[$i]['slug']];
            }, $ord)];
        }
        $pdo->commit();
        assignReply([
            'items'     => $outItems,
            'changed'   => $changed,
            'audiences' => array_values(array_map(static function ($g) { return ['id' => (int)$g['id'], 'name' => (string)$g['name'], 'slug' => (string)$g['slug']]; }, $all)),
        ]);
    }

    // =================================================================== create_email
    if ($action === 'create_email') {
        $title = trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? '')));
        if ($title === '') assignFail(422, 'Give the email a title', ['field' => 'title']);
        if (mb_strlen($title, 'UTF-8') > 255) assignFail(422, 'Title is too long (255 characters max)', ['field' => 'title']);
        $code = emailNormalizeCode((string)($in['code'] ?? ''));
        if ($code === '') $code = assignNextEmailCode($pdo, $companyId);
        if (mb_strlen($code) > 32) assignFail(422, 'ID must be 32 characters or fewer', ['field' => 'code']);
        if ($dup = emailByCode($pdo, $companyId, $code)) {
            assignFail(409, 'ID "' . $code . '" is already used by ' . emailDisplayLabel($dup) . ' — pick another.', ['field' => 'code']);
        }
        [$html, $source] = assignIncomingHtml();
        $url = '';
        if ($source === 'url') {
            $url = trim((string)($in['html_url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url) || !emailValidUrl($url) || mb_strlen($url) > 512) {
                assignFail(422, 'The link must be a full http:// or https:// address', ['field' => 'html_url']);
            }
        }
        $written = null;
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO emails (company_id, code, title, html_url, status, live) VALUES (?, ?, ?, ?, 'draft', 0)")
                ->execute([$companyId, $code, mb_substr($title, 0, 255), $url]);
            $id = (int)$pdo->lastInsertId();
            if ($html !== null) {
                $url = emailWriteHostedHtml($clientSlug, emailHostedFileName($code, $id), $html);
                $written = emailHostedPath($url);
                $pdo->prepare("UPDATE emails SET html_url = ? WHERE id = ?")->execute([$url, $id]);
            }
            $label = emailDisplayLabel(['code' => $code, 'title' => $title]);
            logEmailActivity($pdo, $actor, 'created', $id, 'Email ' . $label . ' created', null, null, $companyId);
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($written) @unlink($written);
            error_log('assign create_email: ' . $ex->getMessage());
            assignFail(500, $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not create the email (database error)');
        }
        assignReply(['id' => $id, 'code' => $code, 'html_url' => $url, 'label' => $label,
                     'url' => emailsUrl(['client' => $clientSlug, 'email' => $id])]);
    }

    // =================================================================== create_page
    if ($action === 'create_page') {
        $title = trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? '')));
        if ($title === '') assignFail(422, 'Give the page a title', ['field' => 'title']);
        if (mb_strlen($title, 'UTF-8') > 160) assignFail(422, 'Title is too long (160 characters max)', ['field' => 'title']);
        $base = pageSlugify($title);
        if ($base === '') $base = 'page';
        [$html, $source] = assignIncomingHtml();
        $url = null;
        if ($source === 'url') {
            $url = trim((string)($in['url'] ?? ''));
            if ($url === '' || !pageValidUrl($url) || mb_strlen($url) > 512) assignFail(422, 'The link must be a full http:// or https:// address', ['field' => 'url']);
        }
        $taken = [];
        $pslug = assignUniquePageSlug($pdo, $company, $base, $taken);
        $dir = null; $written = false;
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO pages (company_id, title, slug, source, url, entry, status, live) VALUES (?, ?, ?, ?, ?, 'index.html', 'draft', 0)")
                ->execute([$companyId, mb_substr($title, 0, 160), $pslug, $source === 'url' ? 'url' : 'upload', $url]);
            $id = (int)$pdo->lastInsertId();
            $page = ['id' => $id, 'slug' => $pslug, 'company_id' => $companyId, 'company_slug' => $clientSlug, 'title' => $title];
            $label = pageDisplayLabel($page);
            logPageActivity($pdo, $actor, 'created', $id, 'Page ' . $label . ' created', null, null, $companyId);
            if ($html !== null) {
                $dir = pageFolderContained($company, $page);
                if ($dir === null) throw new RuntimeException('Page folder resolves outside media/pages/');
                ensurePagesMediaHtaccess();
                if (!mediaMkdir($dir, mediaRootPath()) || !is_writable($dir)) throw new RuntimeException('media/pages/ is not writable on the server');
                $written = true;
                $dest = $dir . '/index.html';
                $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4));
                if (@file_put_contents($tmp, $html) === false || !@rename($tmp, $dest)) { @unlink($tmp); throw new RuntimeException('Failed to save the page HTML (check folder permissions)'); }
                mediaChmodPath($dest);
                $extract = pageExtractInlineAssetsFile($dest, 'index.html');
                clearstatcache(true, $dest);
                pageFileUpsert($pdo, $id, 'index.html', (int)filesize($dest));
                foreach (($extract['files'] ?? []) as $assetRel => $assetBytes) pageFileUpsert($pdo, $id, (string)$assetRel, (int)$assetBytes);
                logPageActivity($pdo, $actor, 'uploaded', $id, 'Uploaded index.html to ' . $label,
                    !empty($extract['extracted']) ? pageExtractSummaryText($extract) : null, null, $companyId);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($written && $dir) assignRemoveTree($dir);
            error_log('assign create_page: ' . $ex->getMessage());
            assignFail(500, $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not create the page (database error)');
        }
        assignReply(['id' => $id, 'slug' => $pslug, 'label' => $label, 'url' => pagesUrl(['client' => $clientSlug, 'page' => $id])]);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('assign failed: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    assignFail(500, 'Database error');
}
assignFail(400, 'Unknown action');
