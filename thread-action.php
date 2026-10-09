<?php
/**
 * Thread state (tracking-lib.php) — same-site POST, JSON. static/js/tracking.js calls it.
 *
 *   action=seen     entity=<type>:<id>   the viewer opened the item: its unread dot goes (an admin user, or a signed-in
 *                                        client contact of THAT item's client — anyone else 403)
 *   action=resolve  entity=<type>:<id>   admin: "Mark resolved" — the item stops waiting on Joust (the same internal
 *                                        'resolved' row as Slack's Resolve button)
 * Replies {ok, message} / {ok: false, error}.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$out = static function (int $code, array $j): void { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_SLASHES); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $out(405, ['ok' => false, 'error' => 'Method not allowed']);
requireSameSiteFetch();
if (!trackingReady($pdo)) $out(409, ['ok' => false, 'error' => 'Run migrate.php first (steps 45–49).']);
if (!preg_match('/^([a-z_]{3,20}):([1-9][0-9]{0,9})$/', (string)($_POST['entity'] ?? ''), $m) || !in_array($m[1], notifyThreadTypes(), true)) {
    $out(400, ['ok' => false, 'error' => 'Unknown item']);
}
$type = $m[1]; $id = (int)$m[2];
$action = (string)($_POST['action'] ?? '');
if (!isAdmin()) trashGuardJson($pdo, $type, $id, false);   // an item in Joust's Trash is gone for the client (trash-lib.php)

if ($action === 'resolve') {
    if (!isAdmin()) $out(403, ['ok' => false, 'error' => 'Admin sign-in required']);
    $r = trackingResolve($pdo, $type, $id);
    $out($r['ok'] ? 200 : 404, $r['ok'] ? ['ok' => true, 'message' => $r['message']] : ['ok' => false, 'error' => $r['message']]);
}
if ($action === 'seen') {
    $viewer = trackingViewer($pdo);
    if (!$viewer) $out(403, ['ok' => false, 'error' => 'Not signed in']);
    $info = notifyItemInfo($pdo, $type, $id);
    if (!$info['exists']) $out(404, ['ok' => false, 'error' => 'Unknown item']);
    if ($viewer[0] === 'contact' && (int)$info['company_id'] !== (int)$viewer[2]) $out(403, ['ok' => false, 'error' => 'This belongs to another client.']);
    trackingMarkSeen($pdo, $viewer, $type, $id);
    $out(200, ['ok' => true, 'message' => 'Seen']);
}
$out(400, ['ok' => false, 'error' => 'Unknown action']);
