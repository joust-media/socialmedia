<?php
/**
 * Comment editing endpoint (comment-edit-lib.php) — same-site POST, JSON. static/js/app.js App.comments calls it.
 *
 *   action=edit     id=<activity_log id>  text=<the new message>  [slide=<0|N>]   (no slide = keep the slide tag)
 *   action=delete   id=<activity_log id>
 *   action=history  id=<activity_log id>                                          admin only
 *
 * Who: the admin seat edits / deletes any comment; a signed-in client only its own company's client comments (any of
 * its contacts — the "edited" label names who) — Joust's, internal notes and another client's answer 403 / 404.
 * Text: trimmed, non-empty (422), ≤ 2000 bytes (400). No time limit, also after Joust replied.
 * Replies {ok, id, html (the bubble, re-rendered for this seat), body_html (just the message), text, slide,
 * deleted, edited} / {ok: false, error}. History: {ok, items: [{kind, text, old, who, at, at_label}]}.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/partials/components/comment-thread.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$out = static function (int $code, array $j): void { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $out(405, ['ok' => false, 'error' => 'Method not allowed']);
requireSameSiteFetch();
$isAdminSession = function_exists('currentAdmin') && currentAdmin() !== null;
if (!$isAdminSession && !currentClientSession($pdo)) $out(401, ['ok' => false, 'error' => 'Please sign in to continue.']);
if (!commentEditReady($pdo)) $out(409, ['ok' => false, 'error' => 'Comment editing needs the latest database update (migrate.php step 53).']);

$action = (string)($_POST['action'] ?? '');
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) $out(400, ['ok' => false, 'error' => 'Invalid id']);

if ($action === 'history') {
    if (!$isAdminSession) $out(403, ['ok' => false, 'error' => 'Admin sign-in required']);
    $items = commentHistory($pdo, $id);
    if (!$items) $out(404, ['ok' => false, 'error' => 'Comment not found']);
    foreach ($items as &$it) $it['at_label'] = absoluteTime($it['at']);
    unset($it);
    $out(200, ['ok' => true, 'id' => $id, 'items' => $items]);
}

if ($action !== 'edit' && $action !== 'delete') $out(400, ['ok' => false, 'error' => 'Unknown action']);
// A comment on an item in Joust's Trash (trash-lib.php): gone for the client (404), frozen for Joust (409)
$tq = $pdo->prepare("SELECT entity_type, entity_id FROM activity_log WHERE id = ?");
$tq->execute([$id]);
if ($te = $tq->fetch()) trashGuardJson($pdo, (string)$te['entity_type'], (int)$te['entity_id'], $isAdminSession);
$in = ['text' => (string)($_POST['text'] ?? '')];
if (array_key_exists('slide', $_POST)) $in['slide'] = (string)$_POST['slide'];
$res = commentEditApply($pdo, $id, $action, $in);
if (empty($res['ok'])) $out((int)$res['code'], ['ok' => false, 'error' => $res['error']]);

// The bubble as this seat sees it now (the thread swaps it in place). A post's slide chip gets its thumbnail.
$row = $res['row'];
$viewer = commentViewerRole();
$opts = ['viewer' => $viewer];
$meta = commentRevisionMeta($pdo, [$id]);
if (isset($meta[$id])) $opts['edit'] = $meta[$id];
[$slide, $body] = commentSlideSplit((string)$row['detail']);
if ($slide > 0 && $row['entity_type'] === 'post') {
    require_once __DIR__ . '/partials/components/post-detail.php';
    $mt = function_exists('hasMediaTypeColumn') && hasMediaTypeColumn($pdo) ? ', media_type AS type' : ", '' AS type";
    $st = $pdo->prepare("SELECT image_url AS url{$mt} FROM post_images WHERE post_id = ? ORDER BY sort_order ASC, id ASC");
    $st->execute([(int)$row['entity_id']]);
    $imgs = $st->fetchAll(PDO::FETCH_ASSOC);
    $opts['slides'] = function_exists('pdSlideThumbs') ? pdSlideThumbs($imgs) : [];
}
$esc = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$out(200, [
    'ok'        => true,
    'id'        => $id,
    'changed'   => (bool)$res['changed'],
    'deleted'   => commentIsDeleted($row),
    'edited'    => !empty($row['edited_at']),
    'text'      => $body,
    'raw'       => (string)$row['detail'],
    'slide'     => $slide,
    'html'      => commentBubble($row, $opts),
    'body_html' => ($slide > 0 ? '<span class="pd-note-slide">On slide ' . $slide . ':</span> ' : '') . nl2br($esc($body)),
    'edited_title' => isset($meta[$id]) ? commentEditedTitle($meta[$id], $viewer, commentIsDeleted($row)) : '',
]);
