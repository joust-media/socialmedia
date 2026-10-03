<?php
/**
 * Morning summary trigger (formerly the "daily digest") — builds + sends the summary of CLIENT activity since the
 * last one to config notify_to (notifyMorningSummary(), notify-lib.php; renderer digest-lib.php). Joust's own actions
 * are left out; activity rows are marked sent only after the email went out (the outbox retries a failure).
 *
 * Triggers:
 *   POST source=manual                              Manage → Tools → "Send Morning summary" (admin session).
 *   GET  ?source=cron&token=<notify_cron_token>     a cron. Preferred instead: notify-cron (one cron every 5 minutes
 *                                                   that also sends the summary at the hour set in Manage).
 *   GET  ?source=opportunistic                      admin session, at most once per 36 h (kept for old links).
 *
 * Backward compatibility of the old OPEN cron URL (…/digest.php?source=cron, no token):
 *   - while config.php has no notify_cron_token, it keeps working but runs at most once per 20 hours (429 otherwise),
 *     so nobody can flush or spam the summary by hitting the URL;
 *   - once notify_cron_token is set, ?source=cron requires it (403 without) — update the cPanel cron at the same time.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$source = $_REQUEST['source'] ?? 'manual';
if (!in_array($source, ['cron', 'manual', 'opportunistic'], true)) $source = 'manual';

function digest_response(string $source, int $code, string $status, string $message): void {
    http_response_code($code);
    if ($source === 'manual') {
        // Manual triggers (button click via a hidden iframe) get HTML; everything else plain text for cron logs.
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Morning summary</title>'
           . '<body style="font:14px -apple-system,sans-serif;padding:20px;color:#333">'
           . '<strong>' . htmlspecialchars($status) . '</strong>: ' . htmlspecialchars($message) . '</body>';
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $status . ': ' . $message . "\n";
    }
    exit;
}

$isAdminSession = function_exists('currentAdmin') && currentAdmin();
if ($source !== 'cron' && !$isAdminSession) {
    digest_response($source, 403, 'forbidden', 'admin sign-in required');
}
if ($source === 'cron') {
    if (strlen(notifyCfg('notify_cron_token')) >= 16) {
        if (!notifyCronTokenOk()) digest_response($source, 403, 'forbidden', 'token required (?token=<notify_cron_token>)');
    } else {
        // Legacy open URL: one run per 20 hours, counted on attempts (not only sends).
        $last = notifyMeta($pdo, 'digest_open_last', '1970-01-01 00:00:00');
        if (time() - (int)strtotime($last) < 20 * 3600) {
            digest_response($source, 429, 'throttled', 'the open cron URL runs at most once per 20 hours — set notify_cron_token in config.php and use notify-cron');
        }
        try { notifyMetaSet($pdo, 'digest_open_last', date('Y-m-d H:i:s')); } catch (Throwable $e) {}
    }
}
if ($source === 'opportunistic') {
    $last = notifyMeta($pdo, 'last_digest_sent_at', '1970-01-01 00:00:00');
    if (time() - (int)strtotime($last) < 36 * 3600) digest_response($source, 200, 'throttled', 'Last summary was less than 36 hours ago.');
}

$res = notifyMorningSummary($pdo, $source);
if ($source === 'cron' && in_array($res['status'], ['sent', 'empty', 'queued'], true)) {
    try { notifyMetaSet($pdo, 'notify_summary_last', date('Y-m-d')); } catch (Throwable $e) {}
}
$code = in_array($res['status'], ['sent', 'empty', 'queued'], true) ? 200 : ($res['status'] === 'locked' ? 409 : 500);
digest_response($source, $code, $res['status'], $res['message']);
