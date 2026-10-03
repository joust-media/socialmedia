<?php
/**
 * Email template previews (Manage → Notifications → Client emails → Preview) — admin only, nothing is sent or written.
 *
 *   ?type=review|reply|live|weekly   which template (Ready for your review · Joust replied · Live & scheduled · the
 *                                    Monday owner report)
 *   &client=<slug>                   whose name / items the sample uses (default: the first client)
 *   &format=text                     the plain-text part instead of the HTML
 * Client templates use the client's real recent items for the sample when it has some (links are inert: #preview);
 * the weekly report uses the real numbers of the last 7 days.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

$type = (string)($_GET['type'] ?? 'review');
if (!in_array($type, ['review', 'reply', 'live', 'weekly'], true)) $type = 'review';
$co = $client;
if (!$co) {
    try { $co = $pdo->query("SELECT id, name, slug, logo_url FROM companies ORDER BY name ASC LIMIT 1")->fetch() ?: null; } catch (Throwable $e) { $co = null; }
}
if ($type === 'weekly') {
    $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $r = trackingWeeklyRender(trackingWeeklyStats($pdo, date('Y-m-d 00:00:00', strtotime('-7 days', strtotime($end))), $end));
} else {
    [$company, $contact, $items] = clientEmailSample($pdo, $type, $co);
    $r = clientEmailRender($type, $company, $contact, $items, ['prefs_url' => '#preview-prefs', 'unsub_url' => '#preview-unsubscribe']);
}
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (($_GET['format'] ?? '') === 'text') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>' . esc($r['subject']) . ' (text)</title><body style="font:14px/1.5 ui-monospace,Menlo,monospace;padding:20px;max-width:720px">'
       . '<p style="color:#8E8E93">Subject: <strong style="color:#000">' . esc($r['subject']) . '</strong></p><pre style="white-space:pre-wrap" data-preview-text>' . esc($r['text']) . '</pre></body>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
echo str_replace('<body ', '<body data-preview-type="' . esc($type) . '" data-preview-subject="' . esc($r['subject']) . '" ', $r['html']);
