<?php
/**
 * Phase 4 tracking — who is waiting on whom. Loaded by helpers.php; function definitions only.
 *
 *   Joust Inbox (inbox.php, admin):
 *     Waiting on Joust   an item whose newest client message has no Joust answer after it (notifyUnanswered(): a
 *                        visible Joust comment, a decision / status move, or Resolve — Slack or the portal's
 *                        "Mark resolved"), OR an item in Needs changes (until it is resubmitted / resolved).
 *                        Oldest wait first; age = since the first unanswered message (or the Needs changes decision).
 *     Waiting on client  To Review items (posts, emails, pages, Library images, tire series with pending renders) with
 *                        no client response since they were sent for review; age = since then.
 *     Resolved recently  client messages answered in the last 14 days: who answered, how fast.
 *   Unread markers (thread_seen, migrate.php 48): per admin user — items with client messages newer than the last time
 *   that admin opened them; per client contact — items with visible Joust replies newer than their last look.
 *   Weekly owner report (Mondays, notify-cron.php): median / max first-response time, approvals, items waiting > 24h,
 *   per client — trackingWeeklyStats().
 */

if (!function_exists('trackingReady')) {
    function trackingReady(?PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!$pdo) return false;
        try {
            return $ready = notifyReady($pdo) && (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'thread_seen'")->fetchColumn() === 1;
        } catch (Throwable $e) {
            return $ready = false;
        }
    }
}

if (!function_exists('trackingAge')) {
    /** "12 min", "3h 20m", "2 days" since a datetime ('' for none). */
    function trackingAge(?string $since, ?int $now = null): string {
        if (!$since) return '';
        $m = max(0, (int)floor((($now ?? time()) - (int)strtotime($since)) / 60));
        return notifyAgeLabel($m);
    }
}

if (!function_exists('trackingMediaUrl')) {
    /** A media URL as the browser needs it ('uploads/x.jpg' → '/portal/uploads/x.jpg'; absolute / rooted as is). */
    function trackingMediaUrl(string $url): string {
        $url = trim($url);
        if ($url === '' || preg_match('#^(https?:)?//#i', $url) || $url[0] === '/') return $url;
        return (function_exists('basePath') ? basePath() : '') . '/' . ltrim($url, '/');
    }
}

if (!function_exists('trackingAgeClass')) {
    /** '' under 4 hours, 'warn' under 24 hours, 'late' after. */
    function trackingAgeClass(?string $since): string {
        if (!$since) return '';
        $h = (time() - (int)strtotime($since)) / 3600;
        return $h >= 24 ? 'late' : ($h >= 4 ? 'warn' : '');
    }
}

// =====================================================================================================================
// Waiting on Joust / on the client / resolved
// =====================================================================================================================

if (!function_exists('trackingDeniedItems')) {
    /** Items in Needs changes: [key => ['entity_type', 'entity_id', 'company_id', 'since']] — 'since' = the newest
     *  'denied' row; an item with a 'resolved' row after that row is left out. */
    function trackingDeniedItems(PDO $pdo, ?int $companyId): array {
        $sets = [
            ['post',  "SELECT id, company_id FROM posts WHERE status = 'denied'" . (function_exists('hasPostedColumn') && hasPostedColumn($pdo) ? ' AND posted = 0' : '')],
        ];
        if (function_exists('hasEmailsTable') && hasEmailsTable($pdo)) $sets[] = ['email', "SELECT id, company_id FROM emails WHERE status = 'denied' AND live = 0"];
        if (function_exists('hasPagesTable') && hasPagesTable($pdo))   $sets[] = ['page', "SELECT id, company_id FROM pages WHERE status = 'denied' AND live = 0"];
        $sets[] = ['tire_image', "SELECT ti.id, t.company_id FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.status = 'denied'"];
        if (function_exists('hasLibraryImagesTable') && hasLibraryImagesTable($pdo)) $sets[] = ['library_image', "SELECT id, company_id FROM library_images WHERE status = 'denied'"];
        $out = [];
        foreach ($sets as [$type, $sql]) {
            try {
                $rows = $pdo->query($sql . ($companyId ? ' AND ' . ($type === 'tire_image' ? 't.' : '') . 'company_id = ' . (int)$companyId : '') . ' LIMIT 300')->fetchAll();
            } catch (Throwable $e) {
                $rows = [];
            }
            if (!$rows) continue;
            $ids = array_map(static function ($r) { return (int)$r['id']; }, $rows);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT entity_id, MAX(CASE WHEN action = 'denied' THEN id END) AS denied_id, MAX(CASE WHEN action = 'denied' THEN created_at END) AS denied_at,
                                       MAX(CASE WHEN action = 'resolved' THEN id END) AS resolved_id
                                  FROM activity_log WHERE entity_type = ? AND entity_id IN ($ph) AND action IN ('denied','resolved') GROUP BY entity_id");
            $s->execute(array_merge([$type], $ids));
            $act = [];
            foreach ($s->fetchAll() as $a) $act[(int)$a['entity_id']] = $a;
            foreach ($rows as $r) {
                $a = $act[(int)$r['id']] ?? null;
                if ($a && $a['resolved_id'] && (int)$a['resolved_id'] > (int)$a['denied_id']) continue;
                $out[$type . ':' . (int)$r['id']] = ['entity_type' => $type, 'entity_id' => (int)$r['id'], 'company_id' => (int)$r['company_id'],
                                                     'since' => $a['denied_at'] ?? null];
            }
        }
        return $out;
    }
}

if (!function_exists('trackingWaitingOnJoust')) {
    /**
     * Every item waiting on Joust (see the file header), oldest wait first (unknown ages last). Rows:
     * entity_type, entity_id, company_id, since, n (unanswered client messages), last_detail, reason ('message' |
     * 'changes' | both → 'message').
     */
    function trackingWaitingOnJoust(PDO $pdo, ?int $companyId = null): array {
        $key = 'j' . (int)$companyId;
        if (isset($GLOBALS['__trackWait'][$key])) return $GLOBALS['__trackWait'][$key];
        $out = [];
        foreach (notifyUnanswered($pdo, $companyId, date('Y-m-d H:i:s', time() - 60 * 86400), 500) as $w) {
            $out[$w['entity_type'] . ':' . $w['entity_id']] = ['entity_type' => $w['entity_type'], 'entity_id' => (int)$w['entity_id'],
                'company_id' => (int)$w['company_id'], 'since' => (string)$w['first_at'], 'n' => (int)$w['n'], 'last_detail' => (string)$w['last_detail'],
                'last_id' => (int)$w['last_id'], 'reason' => 'message'];
        }
        foreach (trackingDeniedItems($pdo, $companyId) as $k => $d) {
            if (isset($out[$k])) {
                if ($d['since'] && $d['since'] < $out[$k]['since']) $out[$k]['since'] = $d['since'];
                continue;
            }
            $out[$k] = $d + ['n' => 0, 'last_detail' => '', 'last_id' => 0, 'reason' => 'changes'];
        }
        $rows = array_values($out);
        usort($rows, static function ($a, $b) {
            if (!$a['since'] && !$b['since']) return $a['entity_id'] <=> $b['entity_id'];
            if (!$a['since']) return 1;
            if (!$b['since']) return -1;
            return strcmp($a['since'], $b['since']) ?: ($a['entity_id'] <=> $b['entity_id']);
        });
        // the newest visible client message for the "changes" rows (a Needs changes note)
        foreach ($rows as &$r) {
            if ($r['last_detail'] !== '') continue;
            $s = $pdo->prepare("SELECT detail FROM activity_log WHERE entity_type = ? AND entity_id = ? AND actor = 'client' AND action = 'commented'
                                  AND detail IS NOT NULL AND detail <> '' ORDER BY id DESC LIMIT 1");
            $s->execute([$r['entity_type'], $r['entity_id']]);
            $r['last_detail'] = (string)($s->fetchColumn() ?: '');
        }
        unset($r);
        return $GLOBALS['__trackWait'][$key] = $rows;
    }
}

if (!function_exists('trackingWaitingCount')) {
    /** The admin Home tab badge: items waiting on Joust across every client (0 before migrate). */
    function trackingWaitingCount(PDO $pdo): int {
        if (!notifyReady($pdo)) return 0;
        try { return count(trackingWaitingOnJoust($pdo, null)); } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('trackingWaitingOnClient')) {
    /** To Review items with no client response since they were sent (oldest first). Rows: entity_type, entity_id,
     *  company_id, since. Tire renders are grouped per series. */
    function trackingWaitingOnClient(PDO $pdo, ?int $companyId = null): array {
        $co = static function (string $col) use ($companyId): string { return $companyId ? " AND {$col} = " . (int)$companyId : ''; };
        $sets = [['post', "SELECT id, company_id FROM posts WHERE status = 'pending'" . $co('company_id')]];
        if (function_exists('hasEmailsTable') && hasEmailsTable($pdo)) $sets[] = ['email', "SELECT id, company_id FROM emails WHERE status = 'pending' AND live = 0" . $co('company_id')];
        if (function_exists('hasPagesTable') && hasPagesTable($pdo))   $sets[] = ['page', "SELECT id, company_id FROM pages WHERE status = 'pending' AND live = 0" . $co('company_id')];
        if (function_exists('hasLibraryImagesTable') && hasLibraryImagesTable($pdo)) $sets[] = ['library_image', "SELECT id, company_id FROM library_images WHERE status = 'pending'" . $co('company_id')];
        if (function_exists('hasTireSeries') && hasTireSeries($pdo)) {
            $sets[] = ['tire_series', "SELECT DISTINCT ti.series_id AS id, t.company_id FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id
                                       WHERE ti.status = 'pending' AND ti.series_id IS NOT NULL" . $co('t.company_id')];
        }
        $out = [];
        foreach ($sets as [$type, $sql]) {
            try { $rows = $pdo->query($sql . ' LIMIT 300')->fetchAll(); } catch (Throwable $e) { $rows = []; }
            if (!$rows) continue;
            $ids = array_map(static function ($r) { return (int)$r['id']; }, $rows);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT entity_id,
                                       MAX(CASE WHEN action IN ('submitted','reset_pending','created','uploaded') THEN created_at END) AS sent_at,
                                       MAX(CASE WHEN action IN ('submitted','reset_pending','created','uploaded') THEN id END) AS sent_id,
                                       MAX(CASE WHEN actor = 'client' AND (action IN ('commented','approved','denied','edited_caption','edited_hashtags')) THEN id END) AS client_id
                                  FROM activity_log WHERE entity_type = ? AND entity_id IN ($ph) GROUP BY entity_id");
            $s->execute(array_merge([$type], $ids));
            $act = [];
            foreach ($s->fetchAll() as $a) $act[(int)$a['entity_id']] = $a;
            foreach ($rows as $r) {
                $a = $act[(int)$r['id']] ?? null;
                if ($a && $a['client_id'] && (int)$a['client_id'] > (int)$a['sent_id']) continue;   // the client already answered
                $out[] = ['entity_type' => $type, 'entity_id' => (int)$r['id'], 'company_id' => (int)$r['company_id'], 'since' => $a['sent_at'] ?? null];
            }
        }
        usort($out, static function ($a, $b) {
            if (!$a['since'] && !$b['since']) return [$a['entity_type'], $a['entity_id']] <=> [$b['entity_type'], $b['entity_id']];
            if (!$a['since']) return 1;
            if (!$b['since']) return -1;
            return strcmp($a['since'], $b['since']);
        });
        return $out;
    }
}

if (!function_exists('trackingResponsePairs')) {
    /**
     * Client message → first Joust answer, per item, from the activity log. A wait opens on a visible client comment
     * (or a Needs changes decision) and closes on the next Joust answer (notifyAnswerActions(); an internal note does
     * not answer). Rows: entity_type, entity_id, company_id, opened_at, closed_at (null = still waiting), closed_by
     * (admin_users id), closed_action, minutes. Only waits opened in [$from, $to).
     */
    function trackingResponsePairs(PDO $pdo, string $from, string $to, ?int $companyId = null): array {
        $types = "'" . implode("','", notifyThreadTypes()) . "'";
        $s = $pdo->prepare("SELECT id, company_id, entity_type, entity_id, action, actor, internal, detail, author_user_id, created_at
                              FROM activity_log WHERE created_at >= ? AND created_at < ? + INTERVAL 30 DAY AND entity_type IN ({$types})"
                           . ($companyId ? ' AND company_id = ' . (int)$companyId : '') . " ORDER BY entity_type, entity_id, id");
        $s->execute([$from, $to]);
        $answers = notifyAnswerActions();
        $open = []; $pairs = [];
        foreach ($s->fetchAll() as $r) {
            $k = $r['entity_type'] . ':' . $r['entity_id'];
            $isClientMsg = $r['actor'] === 'client' && empty($r['internal'])
                && (($r['action'] === 'commented' && trim((string)$r['detail']) !== '') || $r['action'] === 'denied');
            if ($isClientMsg) {
                if (!isset($open[$k]) && $r['created_at'] < $to) $open[$k] = $r;
                continue;
            }
            if (isset($open[$k]) && $r['actor'] === 'admin' && in_array($r['action'], $answers, true) && !($r['action'] === 'commented' && !empty($r['internal']))) {
                $o = $open[$k];
                unset($open[$k]);
                if ($o['created_at'] < $from) continue;
                $pairs[] = ['entity_type' => $o['entity_type'], 'entity_id' => (int)$o['entity_id'], 'company_id' => (int)$o['company_id'],
                            'opened_at' => $o['created_at'], 'closed_at' => $r['created_at'], 'closed_by' => $r['author_user_id'] !== null ? (int)$r['author_user_id'] : null,
                            'closed_action' => $r['action'],
                            'minutes' => (int)round((strtotime($r['created_at']) - strtotime($o['created_at'])) / 60)];
            }
        }
        foreach ($open as $o) {
            if ($o['created_at'] < $from) continue;
            $pairs[] = ['entity_type' => $o['entity_type'], 'entity_id' => (int)$o['entity_id'], 'company_id' => (int)$o['company_id'],
                        'opened_at' => $o['created_at'], 'closed_at' => null, 'closed_by' => null, 'closed_action' => null, 'minutes' => null];
        }
        return $pairs;
    }
}

if (!function_exists('trackingResolvedRecently')) {
    /** Answered waits of the last $days days, newest answer first. */
    function trackingResolvedRecently(PDO $pdo, int $days = 14, ?int $companyId = null, int $limit = 60): array {
        $rows = array_values(array_filter(trackingResponsePairs($pdo, date('Y-m-d H:i:s', time() - $days * 86400), date('Y-m-d H:i:s', time() + 60), $companyId),
            static function ($p) { return $p['closed_at'] !== null; }));
        usort($rows, static function ($a, $b) { return strcmp($b['closed_at'], $a['closed_at']); });
        return array_slice($rows, 0, $limit);
    }
}

if (!function_exists('trackingMedian')) {
    function trackingMedian(array $xs): ?float {
        if (!$xs) return null;
        sort($xs);
        $n = count($xs);
        return $n % 2 ? (float)$xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }
}

if (!function_exists('trackingWeeklyStats')) {
    /**
     * The owner report for [$from, $to): overall + per client —
     *   messages (client messages that started a wait), answered, median / max first-response minutes (answered ones),
     *   approved (client approvals), waiting_now (items waiting on Joust right now), waiting_24h (of those, > 24 h).
     */
    function trackingWeeklyStats(PDO $pdo, string $from, string $to): array {
        $pairs = trackingResponsePairs($pdo, $from, $to);
        $s = $pdo->prepare("SELECT company_id, COUNT(*) AS n FROM activity_log WHERE actor = 'client' AND action = 'approved' AND created_at >= ? AND created_at < ? GROUP BY company_id");
        $s->execute([$from, $to]);
        $approved = array_map('intval', $s->fetchAll(PDO::FETCH_KEY_PAIR));
        $waiting = trackingWaitingOnJoust($pdo, null);
        $companies = [];
        foreach ($pdo->query("SELECT id, name, slug FROM companies ORDER BY name") as $c) $companies[(int)$c['id']] = $c;
        $blank = static function () { return ['messages' => 0, 'answered' => 0, 'times' => [], 'approved' => 0, 'waiting_now' => 0, 'waiting_24h' => 0]; };
        $all = $blank(); $per = [];
        foreach ($pairs as $p) {
            $cid = (int)$p['company_id'];
            $per[$cid] = $per[$cid] ?? $blank();
            $all['messages']++; $per[$cid]['messages']++;
            if ($p['minutes'] !== null) {
                $all['answered']++; $per[$cid]['answered']++;
                $all['times'][] = (int)$p['minutes']; $per[$cid]['times'][] = (int)$p['minutes'];
            }
        }
        foreach ($approved as $cid => $n) { $per[$cid] = $per[$cid] ?? $blank(); $per[$cid]['approved'] = $n; $all['approved'] += $n; }
        foreach ($waiting as $w) {
            $cid = (int)$w['company_id'];
            $per[$cid] = $per[$cid] ?? $blank();
            $late = $w['since'] && (time() - strtotime($w['since'])) > 86400;
            $per[$cid]['waiting_now']++; $all['waiting_now']++;
            if ($late) { $per[$cid]['waiting_24h']++; $all['waiting_24h']++; }
        }
        $fin = static function (array $b): array {
            $b['median_minutes'] = trackingMedian($b['times']);
            $b['max_minutes'] = $b['times'] ? max($b['times']) : null;
            unset($b['times']);
            return $b;
        };
        $clients = [];
        foreach ($per as $cid => $b) {
            if (!isset($companies[$cid])) continue;
            $clients[] = ['company_id' => $cid, 'name' => (string)$companies[$cid]['name']] + $fin($b);
        }
        usort($clients, static function ($a, $b) { return strcmp($a['name'], $b['name']); });
        return ['from' => $from, 'to' => $to, 'overall' => $fin($all), 'clients' => $clients];
    }
}

if (!function_exists('trackingMinutesLabel')) {
    function trackingMinutesLabel(?float $m): string { return $m === null ? '—' : notifyAgeLabel((int)round($m)); }
}

if (!function_exists('trackingWeeklyRender')) {
    /** The weekly report email → ['subject', 'html', 'text']. */
    function trackingWeeklyRender(array $st): array {
        $e = 'clientEmailEsc';
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $o = $st['overall'];
        $range = date('M j', strtotime($st['from'])) . ' – ' . date('M j', strtotime($st['to']) - 1);
        $subject = 'Weekly report — ' . $range . ' · median first reply ' . trackingMinutesLabel($o['median_minutes']);
        $tile = static function (string $label, string $value, string $attr) use ($e, $font): string {
            return '<td width="25%" valign="top" style="padding:0 4px 8px"><div style="background:#F2F2F7;border-radius:12px;padding:12px 10px" data-weekly="' . $attr . '">'
                 . '<div style="font:700 22px/28px ' . $font . ';color:#000">' . $e($value) . '</div>'
                 . '<div style="font:12px/16px ' . $font . ';color:#8E8E93">' . $e($label) . '</div></div></td>';
        };
        $body = '<div style="font:600 13px/18px ' . $font . ';color:#8E8E93;text-transform:uppercase;letter-spacing:.4px">' . $e($range) . '</div>'
              . '<h1 class="jm-h1" style="margin:4px 0 14px;font:700 26px/32px ' . $font . ';color:#000">Weekly report</h1>'
              . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
              . $tile('Median first reply', trackingMinutesLabel($o['median_minutes']), 'median')
              . $tile('Slowest first reply', trackingMinutesLabel($o['max_minutes'] !== null ? (float)$o['max_minutes'] : null), 'max')
              . $tile('Items approved', (string)$o['approved'], 'approved')
              . $tile('Waiting > 24h now', (string)$o['waiting_24h'], 'waiting24')
              . '</tr></table>'
              . '<p style="margin:6px 0 14px;font:14px/20px ' . $font . ';color:#3C3C43">' . (int)$o['messages'] . ' client message' . ($o['messages'] === 1 ? '' : 's') . ' started a conversation; '
              . (int)$o['answered'] . ' got an answer. ' . (int)$o['waiting_now'] . ' item' . ($o['waiting_now'] === 1 ? ' is' : 's are') . ' waiting on Joust right now.</p>'
              . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font:14px/20px ' . $font . ';color:#1C1C1E" data-weekly-clients>'
              . '<tr style="color:#8E8E93;font-size:12px"><td style="padding:6px 0;border-bottom:1px solid #E5E5EA">Client</td><td align="right" style="border-bottom:1px solid #E5E5EA">Msgs</td>'
              . '<td align="right" style="border-bottom:1px solid #E5E5EA">Median</td><td align="right" style="border-bottom:1px solid #E5E5EA">Max</td><td align="right" style="border-bottom:1px solid #E5E5EA">Approved</td><td align="right" style="border-bottom:1px solid #E5E5EA">Waiting</td></tr>';
        $text = "Weekly report — {$range}\n\nMedian first reply: " . trackingMinutesLabel($o['median_minutes'])
              . "\nSlowest first reply: " . trackingMinutesLabel($o['max_minutes'] !== null ? (float)$o['max_minutes'] : null)
              . "\nItems approved: {$o['approved']}\nWaiting > 24h now: {$o['waiting_24h']} (of {$o['waiting_now']} waiting)\n\n";
        foreach ($st['clients'] as $c) {
            $body .= '<tr data-weekly-client="' . (int)$c['company_id'] . '"><td style="padding:8px 0;border-bottom:1px solid #F2F2F7">' . $e($c['name']) . '</td>'
                   . '<td align="right" style="border-bottom:1px solid #F2F2F7">' . (int)$c['messages'] . '</td>'
                   . '<td align="right" style="border-bottom:1px solid #F2F2F7">' . $e(trackingMinutesLabel($c['median_minutes'])) . '</td>'
                   . '<td align="right" style="border-bottom:1px solid #F2F2F7">' . $e(trackingMinutesLabel($c['max_minutes'] !== null ? (float)$c['max_minutes'] : null)) . '</td>'
                   . '<td align="right" style="border-bottom:1px solid #F2F2F7">' . (int)$c['approved'] . '</td>'
                   . '<td align="right" style="border-bottom:1px solid #F2F2F7">' . (int)$c['waiting_now'] . ($c['waiting_24h'] ? ' <span style="color:#FF3B30">(' . (int)$c['waiting_24h'] . ' > 24h)</span>' : '') . '</td></tr>';
            $text .= "{$c['name']}: {$c['messages']} msgs, median " . trackingMinutesLabel($c['median_minutes']) . ', max '
                   . trackingMinutesLabel($c['max_minutes'] !== null ? (float)$c['max_minutes'] : null) . ", {$c['approved']} approved, {$c['waiting_now']} waiting"
                   . ($c['waiting_24h'] ? " ({$c['waiting_24h']} > 24h)" : '') . "\n";
        }
        if (!$st['clients']) $body .= '<tr><td colspan="6" style="padding:10px 0;color:#8E8E93">No client activity this week.</td></tr>';
        $inbox = notifyAbsoluteUrl(portalUrl('inbox'));
        $body .= '</table><p style="margin:18px 0 18px"><a href="' . $e($inbox) . '" style="display:inline-block;padding:10px 18px;border-radius:10px;background:#007AFF;color:#fff;font:600 14px/18px ' . $font . ';text-decoration:none">Open the Joust Inbox</a></p>';
        $text .= "\nJoust Inbox: {$inbox}\n";
        $html = clientEmailLayout($subject, $body, ['eyebrow' => 'Owner report', 'preheader' => 'Median first reply ' . trackingMinutesLabel($o['median_minutes']),
                                                    'footer_html' => 'Sent every Monday by the Joust portal. Change the hour in Manage → Notifications.']);
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
}

if (!function_exists('trackingWeeklyDue')) {
    /** Mondays at / after the Morning summary hour, once per week. */
    function trackingWeeklyDue(PDO $pdo): bool {
        if (date('N') !== '1' || (int)date('G') < notifySettings($pdo)['summary_hour']) return false;
        return notifyMeta($pdo, 'notify_weekly_last', '') !== date('Y-m-d');
    }
}

if (!function_exists('trackingWeeklyQueue')) {
    /** Queue the report for the 7 days before today 00:00 (to notify_to, else the owner). → outbox id or 0. */
    function trackingWeeklyQueue(PDO $pdo): int {
        $to = notifyCfg('notify_to');
        if ($to === '') { $o = notifyOwnerFor($pdo, 0); $to = $o ? (string)$o['email'] : ''; }
        if ($to === '') return 0;
        $end = date('Y-m-d 00:00:00');
        $start = date('Y-m-d 00:00:00', strtotime('-7 days', strtotime($end)));
        notifyMetaSet($pdo, 'notify_weekly_last', date('Y-m-d'));
        return notifyEnqueue($pdo, 'email', 'weekly', ['to' => $to, 'from' => $start, 'until' => $end], ['dedupe' => 'weekly:' . date('Y-m-d'), 'target' => $to, 'defer' => true]);
    }
}

if (!function_exists('trackingDeliverWeekly')) {
    function trackingDeliverWeekly(PDO $pdo, array $p): array {
        $r = trackingWeeklyRender(trackingWeeklyStats($pdo, (string)$p['from'], (string)$p['until']));
        $res = notifyEmail(['to' => (string)$p['to'], 'subject' => $r['subject'], 'html' => $r['html'], 'text' => $r['text'], 'kind' => 'weekly']);
        return $res['ok'] ? ['ok' => true, 'provider_id' => $res['message_id']] : ['ok' => false, 'error' => 'email: ' . $res['error']];
    }
}

// =====================================================================================================================
// Resolve
// =====================================================================================================================

if (!function_exists('trackingResolve')) {
    /** "Mark resolved" (the item sheet ⋯, the Inbox): the item stops waiting on Joust — the same internal 'resolved'
     *  row as Slack's Resolve button. → ['ok', 'message']. */
    function trackingResolve(PDO $pdo, string $type, int $id): array {
        if (!in_array($type, notifyThreadTypes(), true)) return ['ok' => false, 'message' => 'Unknown item'];
        $info = notifyItemInfo($pdo, $type, $id);
        if (!$info['exists']) return ['ok' => false, 'message' => 'That item no longer exists'];
        $waiting = notifyItemWaiting($pdo, $type, $id, (int)$info['company_id']);
        $denied = isset(trackingDeniedItems($pdo, (int)$info['company_id'])[$type . ':' . $id]);
        if (!$waiting && !$denied) return ['ok' => true, 'message' => 'Nothing was waiting'];
        activityWithContext(['internal' => 1], static function () use ($pdo, $info, $type, $id) {
            logActivity($pdo, (int)$info['company_id'], $type, $id, 'resolved', 'admin', 'Marked the client’s note on ' . $info['title'] . ' as answered');
        });
        unset($GLOBALS['__trackWait']);
        return ['ok' => true, 'message' => 'Marked resolved'];
    }
}

// =====================================================================================================================
// Unread markers
// =====================================================================================================================

if (!function_exists('trackingViewer')) {
    /** The viewer this request marks / reads: ['admin', admin_users.id] (the admin seat, not "view as"), ['contact',
     *  client_contacts.id] (a signed-in client), else null. */
    function trackingViewer(?PDO $pdo): ?array {
        if (!$pdo || !trackingReady($pdo)) return null;
        if (function_exists('isAdmin') && isAdmin()) {
            $uid = currentAdminUserId($pdo);
            return $uid ? ['admin', $uid] : null;
        }
        if (function_exists('currentAdmin') && currentAdmin()) return null;   // the admin viewing as a client
        $c = function_exists('currentClientContact') ? currentClientContact() : null;
        return $c ? ['contact', (int)$c['id'], (int)$c['company_id']] : null;
    }
}

if (!function_exists('trackingUnreadSql')) {
    /** Which rows count as "new" for a viewer type: client messages for Joust; visible Joust messages for a client. */
    function trackingUnreadSql(string $viewerType): string {
        return $viewerType === 'admin'
            ? "a.actor = 'client' AND a.action = 'commented' AND a.detail IS NOT NULL AND a.detail <> ''"
            : "a.actor = 'admin' AND a.action = 'commented' AND a.internal = 0 AND a.detail IS NOT NULL AND a.detail <> ''";
    }
}

if (!function_exists('trackingUnreadIds')) {
    /** The ids (of $ids) of $type with messages newer than the viewer's last look (and the unread floor). Cached. */
    function trackingUnreadIds(PDO $pdo, ?array $viewer, string $type, array $ids): array {
        if (!$viewer || !$ids) return [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $floor = (int)notifyMeta($pdo, 'unread_since', '0');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $s = $pdo->prepare("SELECT a.entity_id FROM activity_log a
                                 LEFT JOIN thread_seen s ON s.viewer_type = ? AND s.viewer_id = ? AND s.entity_type = a.entity_type AND s.entity_id = a.entity_id
                                 WHERE a.entity_type = ? AND a.entity_id IN ($ph) AND a.id > ? AND a.id > COALESCE(s.last_seen_id, 0)
                                   AND " . trackingUnreadSql($viewer[0]) . "
                                 GROUP BY a.entity_id");
            $s->execute(array_merge([$viewer[0], $viewer[1], $type], $ids, [$floor]));
            return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('trackingUnreadIds: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('trackingUnreadPreload')) {
    /** Compute the unread set of a list once (posts / emails / pages lists) for trackingUnreadDot(). */
    function trackingUnreadPreload(?PDO $pdo, string $type, array $ids): void {
        $viewer = trackingViewer($pdo);
        $GLOBALS['__unread'][$type] = array_flip($viewer ? trackingUnreadIds($pdo, $viewer, $type, $ids) : []);
    }
}

if (!function_exists('trackingUnreadDot')) {
    /** The unread dot for a list row ('' when read or not preloaded). */
    function trackingUnreadDot(string $type, int $id): string {
        if (!isset($GLOBALS['__unread'][$type])) {
            // not preloaded (a single row partial): ask for this one id
            $pdo = ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null;
            $viewer = trackingViewer($pdo);
            if (!$viewer || !in_array($id, trackingUnreadIds($pdo, $viewer, $type, [$id]), true)) return '';
        } elseif (!isset($GLOBALS['__unread'][$type][$id])) {
            return '';
        }
        return '<span class="ui-unread-dot" data-unread-for="' . htmlspecialchars($type . ':' . $id, ENT_QUOTES) . '" role="img" aria-label="New messages"></span>';
    }
}

if (!function_exists('trackingMarkSeen')) {
    /** The viewer has now seen everything on this item (the newest activity id is remembered). */
    function trackingMarkSeen(PDO $pdo, array $viewer, string $type, int $id): void {
        try {
            $s = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM activity_log WHERE entity_type = ? AND entity_id = ?");
            $s->execute([$type, $id]);
            $max = (int)$s->fetchColumn();
            $pdo->prepare("INSERT INTO thread_seen (viewer_type, viewer_id, entity_type, entity_id, last_seen_id, seen_at) VALUES (?, ?, ?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE last_seen_id = GREATEST(last_seen_id, VALUES(last_seen_id)), seen_at = NOW()")
                ->execute([$viewer[0], (int)$viewer[1], $type, $id, $max]);
        } catch (Throwable $e) {
            error_log('trackingMarkSeen: ' . $e->getMessage());
        }
    }
}

if (!function_exists('trackingSeenAttr')) {
    /** ' data-seen-entity="post:12"' on a detail sheet root: static/js/tracking.js marks it seen when the sheet opens. */
    function trackingSeenAttr(string $type, int $id): string {
        return ' data-seen-entity="' . htmlspecialchars($type . ':' . $id, ENT_QUOTES) . '"';
    }
}

if (!function_exists('trackingResolveMenuItem')) {
    /** The admin ⋯ "Mark resolved" item (tracking.js → thread-action.php). */
    function trackingResolveMenuItem(string $type, int $id): string {
        if (!function_exists('isAdmin') || !isAdmin()) return '';
        return '<div class="pd-menu-sep" role="separator"></div><button type="button" role="menuitem" data-thread-resolve="' . htmlspecialchars($type . ':' . $id, ENT_QUOTES) . '">Mark resolved</button>';
    }
}

if (!function_exists('trackingInboxHomeHtml')) {
    /** Home's "Joust Inbox" row (admin): how many conversations wait on Joust (all clients, or the scoped one) and the
     *  oldest wait, linking to inbox.php. '' before migrate.php 48 or for the client seat. */
    function trackingInboxHomeHtml(PDO $pdo, ?array $client): string {
        if (!function_exists('isAdmin') || !isAdmin() || !trackingReady($pdo)) return '';
        $rows = trackingWaitingOnJoust($pdo, $client ? (int)$client['id'] : null);
        $n = count($rows);
        $oldest = $rows && $rows[0]['since'] ? trackingAge((string)$rows[0]['since']) : '';
        $sub = $n === 0 ? 'Nothing is waiting on Joust' . ($client ? ' from ' . $client['name'] : '')
             : $n . ' waiting on Joust' . ($oldest !== '' ? ' · oldest ' . $oldest : '') . ($client ? '' : ' · all clients');
        $badge = $n > 0 ? '<span class="ui-badge" data-inbox-count="' . $n . '">' . ($n > 99 ? '99+' : $n) . '</span>' : '';
        return '<section class="home-section" data-home-inbox="' . $n . '">'
             . insetListOpen('', ['class' => 'home-inbox'])
             . insetRow(['href' => portalUrl('inbox', array_filter(['client' => $client['slug'] ?? null])), 'icon' => 'bubble', 'title' => 'Joust Inbox',
                         'subtitle' => $sub, 'trailing' => $badge, 'attrs' => ['data-home-link' => 'inbox']])
             . insetListClose() . '</section>';
    }
}
