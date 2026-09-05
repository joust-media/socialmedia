<?php
/**
 * Daily activity digest — emails recent activity_log rows to lance.
 *
 * Triggers (any one is enough):
 *   - GET ?source=cron        — wired up via real cron, preferred
 *   - POST source=manual      — "Send digest now" button on admin.php
 *   - GET ?source=opportunistic — fired from admin.php shutdown hook
 *
 * Uses PHP mail(). For deliverability the From: domain MUST be SPF-authorized
 * for this host's outbound IP — see config.php notify_* keys.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$config = require __DIR__ . '/config.php';

// --- Source ----------------------------------------------------------
$source = $_REQUEST['source'] ?? 'manual';
if (!in_array($source, ['cron', 'manual', 'opportunistic'], true)) {
    $source = 'manual';
}

// --- Lock --------------------------------------------------------------
// Acquire a 5-minute lock so two concurrent triggers can't double-send.
// `last_digest_sent_at` is the floor for opportunistic/cron triggers.
try {
    $lockStmt = $pdo->prepare("
        UPDATE meta SET v = ?
        WHERE k = 'digest_lock_until' AND v < NOW()
    ");
    $lockUntil = date('Y-m-d H:i:s', time() + 300);
    $lockStmt->execute([$lockUntil]);
    if ($lockStmt->rowCount() === 0) {
        digest_response($source, 'locked', 'Another digest run is in progress; try again in a few minutes.');
        exit;
    }
} catch (Throwable $e) {
    digest_response($source, 'error', 'Setup failed: ' . $e->getMessage());
    exit;
}

// --- Throttle non-manual triggers --------------------------------------
// 'manual' is always allowed (the user explicitly clicked send).
// 'opportunistic' is only allowed if last send was >36h ago (cron should win normally).
// 'cron' fires once a day from cron — let it through.
if ($source === 'opportunistic') {
    $last = $pdo->query("SELECT v FROM meta WHERE k = 'last_digest_sent_at'")->fetchColumn();
    if ($last && (time() - strtotime($last)) < 36 * 3600) {
        clear_lock($pdo);
        digest_response($source, 'throttled', 'Last digest was less than 36 hours ago.');
        exit;
    }
}

// --- Pick events --------------------------------------------------------
$rows = $pdo->query("
    SELECT a.id, a.company_id, a.entity_type, a.entity_id, a.action, a.actor,
           a.batch_id, a.summary, a.detail, a.created_at,
           c.name AS company_name
      FROM activity_log a
      LEFT JOIN companies c ON c.id = a.company_id
     WHERE a.digest_id IS NULL
     ORDER BY a.company_id, a.created_at
     LIMIT 200
")->fetchAll();

if (empty($rows) && $source !== 'manual') {
    clear_lock($pdo);
    digest_response($source, 'empty', 'Nothing new to send.');
    exit;
}

// --- Insert digest_runs row first --------------------------------------
$ins = $pdo->prepare("
    INSERT INTO digest_runs (sent_at, event_count, recipient, trigger_source)
    VALUES (NOW(), ?, ?, ?)
");
$ins->execute([count($rows), $config['notify_to'], $source]);
$digestId = (int)$pdo->lastInsertId();

// Mark these rows as belonging to this digest so the next run skips them
// (this happens BEFORE mail() so a hung mail() never blocks the DB).
if ($rows) {
    $ids = array_column($rows, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE activity_log SET digest_id = ? WHERE id IN ($ph)");
    $stmt->execute(array_merge([$digestId], $ids));
}

// Are there more pending past the 200 cap?
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE digest_id IS NULL")->fetchColumn();

// --- Build email -------------------------------------------------------
$summary       = render_summary($rows, $leftover, $config);
$textBody      = $summary['text'];
$htmlBody      = $summary['html'];
$companyCount  = $summary['company_count'];
$eventCount    = count($rows);
$subject       = "Joust admin — {$eventCount} update"
               . ($eventCount === 1 ? '' : 's')
               . " from {$companyCount} client"
               . ($companyCount === 1 ? '' : 's');

if ($eventCount === 0) {
    $subject  = 'Joust admin — manual digest (no new activity)';
    $textBody = "No new activity since the last digest.\n\n— Joust admin";
    $htmlBody = '<p>No new activity since the last digest.</p>';
}

// MIME multipart/alternative
$boundary  = 'b_' . bin2hex(random_bytes(8));
$messageId = 'digest-' . $digestId . '-' . date('Ymd') . '@' . $config['notify_message_domain'];

$headers  = "From: {$config['notify_from']}\r\n";
$headers .= "Reply-To: {$config['notify_reply_to']}\r\n";
$headers .= "Message-ID: <{$messageId}>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
$headers .= "X-Mailer: Joust-Admin-Digest\r\n";

$body  = "This is a multi-part message in MIME format.\r\n\r\n";
$body .= "--{$boundary}\r\n";
$body .= "Content-Type: text/plain; charset=utf-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $textBody . "\r\n\r\n";
$body .= "--{$boundary}\r\n";
$body .= "Content-Type: text/html; charset=utf-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $htmlBody . "\r\n\r\n";
$body .= "--{$boundary}--\r\n";

$envelope = '-f' . $config['notify_envelope'];
$sent = @mail($config['notify_to'], $subject, $body, $headers, $envelope);

if ($sent) {
    $pdo->prepare("UPDATE meta SET v = NOW() WHERE k = 'last_digest_sent_at'")->execute();
}
clear_lock($pdo);

digest_response($source, $sent ? 'sent' : 'mail_failed',
    $sent
        ? "Sent {$eventCount} event(s) to {$config['notify_to']} (digest #{$digestId})."
        : 'mail() returned false — check shared host mail config and SPF for the From: domain.');

// =====================================================================
// Helpers
// =====================================================================

function clear_lock(PDO $pdo) {
    $pdo->prepare("UPDATE meta SET v = '1970-01-01 00:00:00' WHERE k = 'digest_lock_until'")->execute();
}

function digest_response($source, $status, $message) {
    // Manual triggers (button click via hidden iframe) get HTML; everything
    // else gets plain text so cron logs stay readable.
    if ($source === 'manual') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Digest</title>'
           . '<body style="font:14px -apple-system,sans-serif;padding:20px;color:#333">'
           . '<strong>' . htmlspecialchars($status) . '</strong>: '
           . htmlspecialchars($message)
           . '</body>';
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $status . ": " . $message . "\n";
    }
}

/** Group activity rows by company → entity → batch and render text + HTML. */
function render_summary(array $rows, int $leftover, array $config) {
    $companies = [];
    foreach ($rows as $r) {
        $cid = (int)$r['company_id'];
        if (!isset($companies[$cid])) {
            $companies[$cid] = [
                'name'    => $r['company_name'] ?? ('Company #' . $cid),
                'entries' => [],
                'batched' => [],
            ];
        }
        $key = $r['batch_id'] ?: ('id:' . $r['id']);
        if (!isset($companies[$cid]['batched'][$key])) {
            $companies[$cid]['batched'][$key] = [
                'entity_type' => $r['entity_type'],
                'entity_id'   => $r['entity_id'],
                'actor'       => $r['actor'],
                'created_at'  => $r['created_at'],
                'actions'     => [],
                'details'     => [],
            ];
            $companies[$cid]['entries'][] =& $companies[$cid]['batched'][$key];
        }
        $companies[$cid]['batched'][$key]['actions'][] = $r['action'];
        if ($r['detail'] !== null && $r['detail'] !== '') {
            $companies[$cid]['batched'][$key]['details'][] = [
                'action' => $r['action'],
                'text'   => $r['detail'],
            ];
        }
    }

    $h = function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    // Batch-fetch labels for the post entities referenced in this digest so the
    // "Post #N" line can read "Spring launch — hero shot" instead.
    $postLabels = [];
    $postIds = [];
    foreach ($companies as $cid => $co) {
        foreach ($co['entries'] as $e) {
            if ($e['entity_type'] === 'post') $postIds[] = (int)$e['entity_id'];
        }
    }
    if ($postIds) {
        $postIds = array_values(array_unique($postIds));
        $ph = implode(',', array_fill(0, count($postIds), '?'));
        $nameSel = hasPostsNameColumn($pdo) ? 'name' : "'' AS name";
        $s = $pdo->prepare("SELECT id, {$nameSel}, caption FROM posts WHERE id IN ($ph)");
        $s->execute($postIds);
        foreach ($s->fetchAll() as $r) {
            $postLabels[(int)$r['id']] = postDisplayLabel([
                'name'    => $r['name'] ?? '',
                'caption' => $r['caption'] ?? '',
                'id'      => (int)$r['id'],
            ]);
        }
    }

    $textOut = "Joust admin — daily activity digest\n";
    $textOut .= str_repeat('=', 40) . "\n\n";

    $htmlOut  = '<div style="font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#1a1a1a;max-width:640px;margin:0 auto;padding:20px">';
    $htmlOut .= '<h1 style="font-size:20px;margin:0 0 4px">Joust admin — activity digest</h1>';
    $htmlOut .= '<div style="color:#65676b;font-size:13px;margin-bottom:24px">' . $h(date('l, F j, Y')) . '</div>';

    foreach ($companies as $cid => $co) {
        $textOut .= "## " . $co['name'] . "\n";
        $htmlOut .= '<h2 style="font-size:16px;margin:24px 0 8px;padding-bottom:4px;border-bottom:1px solid #e4e6eb">'
                  . $h($co['name']) . '</h2>';

        foreach ($co['entries'] as $e) {
            $actions = array_unique($e['actions']);
            if ($e['entity_type'] === 'post') {
                $entityLabel = $postLabels[(int)$e['entity_id']] ?? ('Post #' . (int)$e['entity_id']);
            } elseif ($e['entity_type'] === 'tire_image') {
                $entityLabel = 'Image #' . (int)$e['entity_id'];
            } elseif ($e['entity_type'] === 'task') {
                $entityLabel = 'Task #' . (int)$e['entity_id'];
            } else {
                $entityLabel = ucfirst($e['entity_type']) . ' #' . (int)$e['entity_id'];
            }
            $verb = (count($actions) > 1)
                  ? 'edits (' . implode(', ', array_map('actionLabel', $actions)) . ')'
                  : actionLabel($actions[0]);
            $when = date('M j g:ia', strtotime($e['created_at']));

            $textOut .= "  • [{$e['actor']}] {$entityLabel} — {$verb} ({$when})\n";
            foreach ($e['details'] as $d) {
                if (in_array($d['action'], ['commented', 'uncommented'], true)) {
                    $excerpt = mb_substr($d['text'], 0, 240);
                    $textOut .= "      \"" . str_replace("\n", ' ', $excerpt)
                              . (mb_strlen($d['text']) > 240 ? '…' : '') . "\"\n";
                } elseif (strpos($d['action'], 'edited_') === 0) {
                    $textOut .= "      " . str_replace("\n", ' ', mb_substr($d['text'], 0, 200)) . "\n";
                }
            }

            $htmlOut .= '<div style="padding:10px 0;border-bottom:1px solid #f0f2f5">';
            $actorColor = $e['actor'] === 'client' ? '#1e40af'
                       : ($e['actor'] === 'admin' ? '#6b21a8' : '#65676b');
            $htmlOut .= '<span style="font-size:10px;font-weight:700;text-transform:uppercase;'
                      . 'background:#f0f2f5;color:' . $actorColor . ';padding:2px 8px;border-radius:10px;'
                      . 'margin-right:8px">' . $h($e['actor']) . '</span>';
            $htmlOut .= '<strong>' . $h($entityLabel) . '</strong> '
                      . '<span style="color:#65676b">' . $h($verb) . '</span> '
                      . '<span style="color:#9ca3af;font-size:12px">· ' . $h($when) . '</span>';
            foreach ($e['details'] as $d) {
                if (in_array($d['action'], ['commented', 'uncommented'], true)) {
                    $excerpt = mb_substr($d['text'], 0, 240);
                    $htmlOut .= '<div style="margin-top:6px;padding:8px 10px;background:#f7f8fa;'
                              . 'border-left:3px solid #1877f2;font-style:italic;color:#3a3b3c">'
                              . '"' . $h($excerpt) . ($h(mb_strlen($d['text']) > 240 ? '…' : '')) . '"</div>';
                } elseif (strpos($d['action'], 'edited_') === 0) {
                    $htmlOut .= '<div style="margin-top:4px;font-size:12px;color:#65676b">'
                              . $h(mb_substr($d['text'], 0, 200)) . '</div>';
                }
            }
            $htmlOut .= '</div>';
        }
        $textOut .= "\n";
    }

    if ($leftover > 0) {
        $textOut .= "…and {$leftover} more older event(s) — see admin.php for the full list.\n\n";
        $htmlOut .= '<p style="color:#65676b;font-size:13px;margin-top:16px">'
                  . '…and ' . $leftover . ' more older event(s) — see admin.php for the full list.</p>';
    }

    $textOut .= "Reply to this email to talk to Lance directly.\n";
    $htmlOut .= '<hr style="border:none;border-top:1px solid #e4e6eb;margin:24px 0">';
    $htmlOut .= '<div style="color:#9ca3af;font-size:12px">'
              . 'Reply directly to this email to talk to Lance. '
              . 'You can also <a href="#" style="color:#1877f2">open the admin</a>.</div>';
    $htmlOut .= '</div>';

    return [
        'text'          => $textOut,
        'html'          => '<!doctype html><html><body>' . $htmlOut . '</body></html>',
        'company_count' => count($companies),
    ];
}
