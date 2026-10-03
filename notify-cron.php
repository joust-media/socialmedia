<?php
/**
 * Notification cron — call every 5 minutes (cPanel → Cron Jobs):
 *
 *   curl -fsS "https://joustmedia.com/portal/notify-cron?token=<notify_cron_token>" >/dev/null 2>&1
 *
 * (extensionless: the live host 301s notify-cron.php to it; curl without -L would stop at the redirect.)
 * Auth: config notify_cron_token (≥ 16 chars) as ?token=, X-Notify-Token or Authorization: Bearer — compared in
 * constant time. No session, no same-site check, no client scope. 503 when the token is not configured, 403 when it
 * does not match. Each run, within ~25 s:
 *   1. reclaims outbox rows stuck in 'sending' past their lease (a crashed request);
 *   2. escalation check (notifyEscalate): unanswered client messages ≥ T1 → Slack thread re-ping + DM to the owner;
 *      ≥ T2 → email to the owner (thresholds: Manage → Notifications);
 *   3. delivers every due outbox row (retries with backoff 1 / 5 / 15 / 60 / 180 min, then 'failed');
 *   4. the Morning summary, once a day at / after the configured hour (America/New_York);
 *   inbound email replies (gmail-lib.php gmailPollInbound: replies to lance+ai@ → portal comments / the unmatched list;
 *   "not_connected" until Google is connected), client email batches (client-notify-lib.php clientEmailRun: Ready
 *   for review 15 min after the last change, Joust replied 10 min, Live & scheduled once a day) and the Monday owner
 *   report (tracking-lib.php) run before step 3, so what they queue goes out in the same run.
 *   for review 15 min after the last change, Joust replied 10 min, Live & scheduled and gentle reminders once a day;
 *   all held until Google is connected) …
 *   ?summary=now / ?live=now / ?weekly=now / ?remind=now force those steps (testing).
 * Replies JSON {ok, reclaimed, escalated: {t1, t2}, inbound, client_emails, weekly, delivered: {sent, failed, retry, skipped}, summary}.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
ini_set('display_errors', '0');

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (strlen(notifyCfg('notify_cron_token')) < 16) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'notify_cron_token is not set in config.php (16+ characters)']);
    exit;
}
if (!notifyCronTokenOk()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'bad token']);
    exit;
}
if (!notifyReady($pdo)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'run migrate.php (steps 36–39) first']);
    exit;
}

@set_time_limit(60);
@ignore_user_abort(true);
$out = ['ok' => true];
try {
    notifyMetaSet($pdo, 'notify_cron_last', date('Y-m-d H:i:s'));
    $out['reclaimed'] = notifyReclaimStale($pdo);
    $out['escalated'] = notifyEscalate($pdo);
    $out['inbound'] = function_exists('gmailPollInbound') ? gmailPollInbound($pdo) : ['status' => 'unavailable'];
    $out['client_emails'] = function_exists('clientEmailRun') ? clientEmailRun($pdo, ['live_now' => ($_GET['live'] ?? '') === 'now', 'remind_now' => ($_GET['remind'] ?? '') === 'now']) : [];
    $out['weekly'] = 'not due';
    if (function_exists('trackingWeeklyDue') && (trackingWeeklyDue($pdo) || (($_GET['weekly'] ?? '') === 'now'))) {
        $out['weekly'] = trackingWeeklyQueue($pdo) > 0 ? 'queued' : 'not sent (no recipient, or turned off)';
    }
    $out['delivered'] = notifyPump($pdo, ['limit' => 60, 'budget' => 20.0]);
    $out['summary'] = 'not due';
    if (notifySummaryDue($pdo) || (($_GET['summary'] ?? '') === 'now')) {
        $res = notifyMorningSummary($pdo, 'cron');
        $out['summary'] = $res['status'];
        if (in_array($res['status'], ['sent', 'empty', 'queued', 'off'], true)) notifyMetaSet($pdo, 'notify_summary_last', date('Y-m-d'));
        // every teammate with the summary on: their own, scoped to the clients they own (one per person per day)
        if (function_exists('notifyMemberSummaries')) $out['summary_members'] = notifyMemberSummaries($pdo);
    }
} catch (Throwable $e) {
    error_log('notify-cron: ' . $e->getMessage());
    http_response_code(500);
    $out = ['ok' => false, 'error' => 'server error'];
}
echo json_encode($out);
