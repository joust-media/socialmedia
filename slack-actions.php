<?php
/**
 * Slack interactivity endpoint — https://joustmedia.com/portal/slack-actions (extensionless, like slack-events).
 * Buttons on a portal item's parent message: Resolve (marks the client's waiting message answered), Send for review
 * (draft → To Review), Mark Scheduled (posts), Mark Live (emails / pages), Open (a link; nothing to do here).
 *
 * Same verification as slack-events.php (signing secret, constant-time compare, 5-minute window). The form field
 * `payload` is the JSON interaction. Deduped by trigger_id. Acked with an empty 200 at once; then, after the flush,
 * the action runs as the mapped Joust team member through the portal's own transition rules (transitions-lib.php),
 * the parent message is re-rendered (chat.update) and a refusal / unmapped user gets an ephemeral reply via
 * response_url. A button only ever acts on the item its own message belongs to (slackHandleAction()).
 */

header('Cache-Control: no-store');
ini_set('display_errors', '0');

$raw = (string)file_get_contents('php://input', false, null, 0, 1024 * 1024);

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function slackActionsReply(int $code, array $json): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($json, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') slackActionsReply(405, ['ok' => false, 'error' => 'POST only']);
$v = slackVerifyRequest($raw, notifyRequestHeader('X-Slack-Request-Timestamp'), notifyRequestHeader('X-Slack-Signature'));
if (!$v['ok']) slackActionsReply($v['code'], ['ok' => false, 'error' => $v['error']]);

parse_str($raw, $form);
$payload = json_decode((string)($form['payload'] ?? ''), true);
if (!is_array($payload)) slackActionsReply(400, ['ok' => false, 'error' => 'bad payload']);
if (($payload['type'] ?? '') !== 'block_actions') slackActionsReply(200, ['ok' => true, 'ignored' => true]);
if (!notifyReady($pdo)) slackActionsReply(503, ['ok' => false, 'error' => 'run migrate.php first']);

$trigger = 'act:' . (string)($payload['trigger_id'] ?? (($payload['container']['message_ts'] ?? '') . ':' . ($payload['actions'][0]['action_ts'] ?? '')));
if (!slackInboxClaim($pdo, $trigger, 'action:' . (string)($payload['actions'][0]['action_id'] ?? ''),
        (string)($payload['container']['channel_id'] ?? ''), (string)($payload['user']['id'] ?? ''))) {
    slackActionsReply(200, ['ok' => true, 'duplicate' => true]);
}

notifyRespondEarly(200, ['ok' => true]);
@set_time_limit(30);
try {
    $res = slackHandleAction($pdo, $payload);
    slackInboxDone($pdo, $trigger, $res['ok'] ? 'done' : 'refused', (string)$res['message']);
    if ($res['message'] !== '') slackRespondEphemeral((string)($payload['response_url'] ?? ''), $res['message']);
} catch (Throwable $e) {
    error_log('slack-actions: ' . $e->getMessage());
    slackInboxDone($pdo, $trigger, 'error', 'server error');
}
