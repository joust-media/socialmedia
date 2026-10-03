<?php
/**
 * Manage → Notifications endpoint (admin session, same-site POST, JSON) — static/js/notifications.js.
 *
 *   action=settings      t1, t2 (minutes; 5 ≤ t1 < t2 ≤ 10080), summary_hour (0–23, America/New_York)
 *   action=client        company_id, slack_channel_id ('' clears; C…/G… id), owner_user_id (0 = default owner)
 *   action=find_channel  company_id → looks up #portal-<slug> (conversations.list) and saves its id
 *   action=user          id (0 = new), name, email, slack_user_id ('' clears), active (0|1)
 *   action=find_user     id → users.lookupByEmail(email) and saves the Slack user id
 *   action=test          company_id (0 = DM to me) → a test message, delivered now; reply carries the result
 *   action=retry         id → that outbox row back in the queue and delivered now
 *   action=retry_all     every failed row back in the queue (delivered now, up to 20)
 * Replies {ok, message} / {ok:false, error}. Secrets are never echoed.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function notifyAdminOut(int $code, array $j): void {
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_SLASHES);
    exit;
}
function notifyAdminFail(int $code, string $msg): void { notifyAdminOut($code, ['ok' => false, 'error' => $msg]); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') notifyAdminFail(405, 'Method not allowed');
requireSameSiteFetch();
if (!isAdmin()) notifyAdminFail(403, 'Admin sign-in required');
if (!notifyReady($pdo)) notifyAdminFail(409, 'Run migrate.php first (steps 36–39).');

$action = (string)($_POST['action'] ?? '');
$int = static function (string $k, int $def = 0): int { return isset($_POST[$k]) && is_scalar($_POST[$k]) && preg_match('/^-?\d+$/', trim((string)$_POST[$k])) ? (int)$_POST[$k] : $def; };
$str = static function (string $k): string { return isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim((string)$_POST[$k]) : ''; };
$company = static function (PDO $pdo, int $id): array {
    $s = $pdo->prepare("SELECT id, name, slug FROM companies WHERE id = ?");
    $s->execute([$id]);
    $c = $s->fetch();
    if (!$c) notifyAdminFail(404, 'Unknown client');
    return $c;
};
$saveClient = static function (PDO $pdo, int $cid, ?string $channel, ?string $name, ?int $owner): void {
    $pdo->prepare("INSERT IGNORE INTO notify_clients (company_id) VALUES (?)")->execute([$cid]);
    if ($channel !== null) $pdo->prepare("UPDATE notify_clients SET slack_channel_id = ?, slack_channel_name = ? WHERE company_id = ?")->execute([$channel !== '' ? $channel : null, $name !== '' ? $name : null, $cid]);
    if ($owner !== null) $pdo->prepare("UPDATE notify_clients SET owner_user_id = ? WHERE company_id = ?")->execute([$owner > 0 ? $owner : null, $cid]);
};

try {
    switch ($action) {
        case 'settings': {
            $t1 = $int('t1'); $t2 = $int('t2'); $hr = $int('summary_hour', -1);
            if ($t1 < 5 || $t1 > 10080) notifyAdminFail(422, 'The Slack reminder needs 5 minutes to 7 days.');
            if ($t2 <= $t1 || $t2 > 10080) notifyAdminFail(422, 'The email reminder must come after the Slack one (and within 7 days).');
            if ($hr < 0 || $hr > 23) notifyAdminFail(422, 'Pick an hour from 0 to 23.');
            notifyMetaSet($pdo, 'notify_t1_minutes', (string)$t1);
            notifyMetaSet($pdo, 'notify_t2_minutes', (string)$t2);
            notifyMetaSet($pdo, 'notify_summary_hour', (string)$hr);
            notifyAdminOut(200, ['ok' => true, 'message' => 'Saved']);
        }
        case 'client': {
            $c = $company($pdo, $int('company_id'));
            $ch = strtoupper($str('slack_channel_id'));
            if ($ch !== '' && !preg_match('/^[CG][A-Z0-9]{6,20}$/', $ch)) notifyAdminFail(422, 'A Slack channel ID looks like C0123ABCD (channel details → bottom of the About tab).');
            $owner = $int('owner_user_id');
            if ($owner > 0 && !adminUserById($pdo, $owner)) notifyAdminFail(422, 'Unknown team member');
            $prev = notifyClientRow($pdo, (int)$c['id']);
            $name = ($prev && (string)$prev['slack_channel_id'] === $ch) ? (string)$prev['slack_channel_name'] : '';
            $saveClient($pdo, (int)$c['id'], $ch, $name, $owner);
            notifyAdminOut(200, ['ok' => true, 'message' => $c['name'] . ' saved']);
        }
        case 'find_channel': {
            $c = $company($pdo, $int('company_id'));
            if (!notifySlackConfigured()) notifyAdminFail(409, 'Add slack_bot_token to config.php first.');
            $want = 'portal-' . $c['slug'];
            $cursor = ''; $found = null;
            for ($page = 0; $page < 10 && !$found; $page++) {
                $r = slackApi('conversations.list', ['types' => 'public_channel,private_channel', 'exclude_archived' => 'true', 'limit' => '200'] + ($cursor !== '' ? ['cursor' => $cursor] : []), ['form' => true]);
                if (empty($r['ok'])) notifyAdminFail(502, 'Slack: ' . $r['error']);
                foreach ((array)($r['data']['channels'] ?? []) as $chn) {
                    if (strtolower((string)($chn['name'] ?? '')) === $want) { $found = $chn; break; }
                }
                $cursor = (string)($r['data']['response_metadata']['next_cursor'] ?? '');
                if ($cursor === '') break;
            }
            if (!$found) notifyAdminFail(404, "No channel #{$want} the bot can see — create it and invite the bot (/invite @Joust Portal), or paste the channel ID.");
            $saveClient($pdo, (int)$c['id'], (string)$found['id'], '#' . $want, null);
            notifyAdminOut(200, ['ok' => true, 'message' => "Found #{$want}", 'channel' => (string)$found['id']]);
        }
        case 'user': {
            $id = $int('id'); $name = $str('name'); $email = strtolower($str('email')); $slack = strtoupper($str('slack_user_id'));
            if ($name === '' || mb_strlen($name) > 80) notifyAdminFail(422, 'Add a name');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) notifyAdminFail(422, 'That email does not look right');
            if ($slack !== '' && !preg_match('/^[UW][A-Z0-9]{6,20}$/', $slack)) notifyAdminFail(422, 'A Slack user ID looks like U0123ABCD (profile → ⋯ → Copy member ID).');
            $active = $int('active', 1) ? 1 : 0;
            if ($id > 0) {
                if (!adminUserById($pdo, $id)) notifyAdminFail(404, 'Unknown team member');
                $pdo->prepare("UPDATE admin_users SET name = ?, email = ?, slack_user_id = ?, active = ? WHERE id = ?")->execute([$name, $email, $slack !== '' ? $slack : null, $active, $id]);
            } else {
                $pdo->prepare("INSERT INTO admin_users (name, email, slack_user_id, active) VALUES (?, ?, ?, ?)")->execute([$name, $email, $slack !== '' ? $slack : null, $active]);
                $id = (int)$pdo->lastInsertId();
            }
            adminUsersReset();
            notifyAdminOut(200, ['ok' => true, 'message' => $name . ' saved', 'id' => $id]);
        }
        case 'find_user': {
            $u = adminUserById($pdo, $int('id'));
            if (!$u) notifyAdminFail(404, 'Unknown team member');
            if (!notifySlackConfigured()) notifyAdminFail(409, 'Add slack_bot_token to config.php first.');
            $r = slackApi('users.lookupByEmail', ['email' => (string)$u['email']], ['form' => true]);
            if (empty($r['ok'])) notifyAdminFail(404, $r['error'] === 'users_not_found' ? 'No Slack user with ' . $u['email'] : 'Slack: ' . $r['error']);
            $sid = (string)($r['data']['user']['id'] ?? '');
            $pdo->prepare("UPDATE admin_users SET slack_user_id = ? WHERE id = ?")->execute([$sid, (int)$u['id']]);
            adminUsersReset();
            notifyAdminOut(200, ['ok' => true, 'message' => 'Linked ' . $u['name'] . ' to Slack', 'slack_user_id' => $sid]);
        }
        case 'test': {
            if (!notifySlackConfigured()) notifyAdminFail(409, 'Add slack_bot_token to config.php first.');
            $cid = $int('company_id');
            if ($cid > 0) {
                $c = $company($pdo, $cid);
                $ch = notifyClientChannel($pdo, (int)$c['id']);
                if ($ch === '') notifyAdminFail(409, 'Set ' . $c['name'] . '’s channel first.');
                $payload = ['channel' => $ch];
            } else {
                $me = adminUserById($pdo, (int)currentAdminUserId($pdo));
                if (!$me || trim((string)$me['slack_user_id']) === '') notifyAdminFail(409, 'Add your Slack user ID under Team first.');
                $payload = ['user' => (string)$me['slack_user_id']];
            }
            $oid = notifyEnqueue($pdo, 'slack', 'slack_test', $payload, ['company_id' => $cid ?: null, 'target' => $payload['channel'] ?? $payload['user'], 'defer' => true]);
            notifyPump($pdo, ['ids' => [$oid], 'limit' => 1]);
            $s = $pdo->prepare("SELECT status, last_error FROM notify_outbox WHERE id = ?");
            $s->execute([$oid]);
            $o = $s->fetch();
            if (($o['status'] ?? '') !== 'sent') notifyAdminFail(502, 'Slack said: ' . ($o['last_error'] ?? 'unknown error'));
            notifyAdminOut(200, ['ok' => true, 'message' => 'Test sent']);
        }
        case 'retry': {
            $id = $int('id');
            $ids = notifyRetry($pdo, $id);
            if (!$ids) notifyAdminFail(409, 'Already sent (or unknown)');
            $st = notifyPump($pdo, ['ids' => $ids, 'limit' => 1]);
            notifyAdminOut(200, ['ok' => true, 'message' => $st['sent'] ? 'Sent' : ($st['skipped'] ? 'Nothing to send — skipped' : 'Still failing — queued for another try'), 'stats' => $st]);
        }
        case 'retry_all': {
            $ids = notifyRetry($pdo, null);
            if (!$ids) notifyAdminOut(200, ['ok' => true, 'message' => 'Nothing failed']);
            $st = notifyPump($pdo, ['ids' => $ids, 'limit' => 20, 'budget' => 15.0]);
            notifyAdminOut(200, ['ok' => true, 'message' => $st['sent'] . ' sent · ' . ($st['retry'] + $st['failed']) . ' still failing', 'stats' => $st]);
        }
    }
    notifyAdminFail(400, 'Unknown action');
} catch (Throwable $e) {
    error_log('notify-admin: ' . $e->getMessage());
    notifyAdminFail(500, 'Server error');
}
