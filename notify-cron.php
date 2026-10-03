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
 *   4. the Morning summary, once a day at / after the configured hour (America/New_York).
 * Replies JSON {ok, reclaimed, escalated: {t1, t2}, delivered: {sent, failed, retry, skipped}, summary}.
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
    $out['delivered'] = notifyPump($pdo, ['limit' => 60, 'budget' => 20.0]);
    $out['summary'] = 'not due';
    if (notifySummaryDue($pdo) || (($_GET['summary'] ?? '') === 'now')) {
        $res = notifyMorningSummary($pdo, 'cron');
        $out['summary'] = $res['status'];
        if (in_array($res['status'], ['sent', 'empty', 'queued'], true)) notifyMetaSet($pdo, 'notify_summary_last', date('Y-m-d'));
    }
} catch (Throwable $e) {
    error_log('notify-cron: ' . $e->getMessage());
    http_response_code(500);
    $out = ['ok' => false, 'error' => 'server error'];
}
echo json_encode($out);
