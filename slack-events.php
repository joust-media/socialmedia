<?php
/**
 * Slack Events API endpoint — https://joustmedia.com/portal/slack-events (register the EXTENSIONLESS URL: the live
 * host 301s the .php form and Slack never follows redirects).
 *
 *   - every request is verified first: X-Slack-Signature = v0=HMAC-SHA256(slack_signing_secret, "v0:{ts}:{raw body}")
 *     (constant-time compare) and X-Slack-Request-Timestamp within 5 minutes (replay guard) → 401 otherwise, 503
 *     when the secret is not configured;
 *   - url_verification → {"challenge": …};
 *   - event_callback → deduped by event_id (slack_inbox; Slack's retries and replays are acked and dropped), acked
 *     with 200 at once (Slack wants it within 3 s), then processed after the response is flushed:
 *     a `message` in a thread the portal created, from a Slack user mapped to a Joust team member, becomes a portal
 *     comment by that member on THAT item; "!internal …" makes it an internal note (never shown to the client).
 *     Bot messages, edits / deletes (any subtype) and messages outside portal threads are ignored.
 * No session, no client scope (helpers.php is loaded for its functions; Slack sends no cookies).
 */

header('Cache-Control: no-store');
ini_set('display_errors', '0');

$raw = (string)file_get_contents('php://input', false, null, 0, 1024 * 1024);

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function slackEventsReply(int $code, array $json): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($json, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') slackEventsReply(405, ['ok' => false, 'error' => 'POST only']);
$v = slackVerifyRequest($raw, notifyRequestHeader('X-Slack-Request-Timestamp'), notifyRequestHeader('X-Slack-Signature'));
if (!$v['ok']) slackEventsReply($v['code'], ['ok' => false, 'error' => $v['error']]);

$body = json_decode($raw, true);
if (!is_array($body)) slackEventsReply(400, ['ok' => false, 'error' => 'bad JSON']);

if (($body['type'] ?? '') === 'url_verification') {
    slackEventsReply(200, ['challenge' => (string)($body['challenge'] ?? '')]);
}
if (($body['type'] ?? '') !== 'event_callback' || !is_array($body['event'] ?? null)) {
    slackEventsReply(200, ['ok' => true, 'ignored' => true]);
}
if (!notifyReady($pdo)) slackEventsReply(503, ['ok' => false, 'error' => 'run migrate.php first']);

$ev = $body['event'];
$eventId = (string)($body['event_id'] ?? '');
if (!slackInboxClaim($pdo, $eventId, 'event:' . (string)($ev['type'] ?? ''), (string)($ev['channel'] ?? ''), (string)($ev['user'] ?? ''))) {
    slackEventsReply(200, ['ok' => true, 'duplicate' => true]);
}

notifyRespondEarly(200, ['ok' => true]);   // the 3-second ack; the work below happens after the response
@set_time_limit(30);
try {
    [$status, $note, $aid] = slackHandleMessageEvent($pdo, $ev);
    slackInboxDone($pdo, $eventId, $status, $note, $aid);
} catch (Throwable $e) {
    error_log('slack-events: ' . $e->getMessage());
    slackInboxDone($pdo, $eventId, 'error', 'server error');
}
