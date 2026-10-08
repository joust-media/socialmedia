<?php
/**
 * Client notification emails — to each client's contacts (client_contacts), one email per recipient with their own
 * signed links (clientLink(): one tap signs that person in and lands on the item). Loaded by helpers.php after
 * notify-lib.php / gmail-lib.php; function definitions only.
 *
 *   review  "Ready for your review" — an item sent for review (a post created To Review, Send for review, a resubmit).
 *           Batched per client: sent 15 minutes after the LAST such change, one email listing every item (thumbnail,
 *           per-item Review link, per-item "Reply by email").
 *   reply   "Joust replied" — a visible Joust comment on an item (internal notes never). Batched per client over 10
 *           minutes; a one-item email is threaded on that item's Message-ID (In-Reply-To / References) and carries the
 *           item's [J#…] subject token so an emailed answer lands on the item.
 *   live    "Live & scheduled" — a post marked Scheduled, an email / page marked Live. Once a day (the Morning summary
 *           hour).
 *   remind  "A gentle reminder" — items still To Review with no client answer after N days (per client, default 3;
 *           0 = off): at most one email per client every N days covering every such item, at most 2 per item, and
 *           none for items the client has been active since (clientEmailRemindQueue()); checked daily at the summary hour.
 *   Review covers assets too: tire renders uploaded into a series (one row per series), library uploads / FTP drops,
 *   an asset reset to To Review — with thumbnails and per-recipient links like posts.
 *
 * Off until Joust turns them on: every per-client switch defaults OFF (migrate.php 47 / 50), and nothing goes out
 * until Google is connected (clientEmailTransportOk(): transport gmail, or "Allow sending client emails without
 * Google (mail())" ticked in Manage → Notifications). Held batches stay queued; sign-in emails never wait (and expire
 * with their link). A held client email older than 72 h (config client_email_max_age_hours) is dropped when the queue
 * is released — "expired, not sent" in the Delivery log (clientEmailExpireStale()).
 *
 * Flow: logActivity() → notifyOnActivity() → clientEmailOnActivity() appends to client_email_queue (migrate.php 49).
 * The cron (notify-cron.php → clientEmailRun()) closes a batch once its window passed (batch_key on the rows) and
 * enqueues one outbox email per subscribed contact (kind client_email, dedupe "ce:<batch>:<contact>"); the outbox
 * renders it at delivery (clientEmailDeliver(): the items are re-checked — still To Review / still visible / still
 * that client's — so a stale or cross-client item can never go out) and sends it through notifyEmail() (Gmail once
 * connected, else mail()), From "Joust Media" <lance@joustmedia.com>, Reply-To the inbound address
 * (lance+ai@joustmedia.com), List-Unsubscribe + List-Unsubscribe-Post (RFC 8058 one-click).
 *
 * Staging (gmail-lib.php portalEnvironment()): client emails go only to contacts whose email domain is in config
 * staging_allowed_email_domains (default joustmedia.com); every other copy is held back and logged in the Delivery
 * log as "blocked on staging: …" (clientEmailStagingAllows()) — Manage → Notifications shows the rule and the count.
 *
 * Who gets what: the client's switch (Manage → Clients → Client emails: notify_clients.email_review / _replies /
 * _live / _remind, default OFF) AND the contact's preference (client_contacts.notify_prefs JSON {review, reply, live}, a
 * missing key = on; unsubscribed_at = "stop all") — set on email-prefs (signed link in every email, or from the
 * client portal: Email settings).
 */

if (!function_exists('clientEmailKinds')) {
    /** kind → [label for preferences, notify_clients column]. */
    function clientEmailKinds(): array {
        return [
            'review' => ['Ready for your review', 'email_review', 'When Joust sends something for you to review'],
            'reply'  => ['Joust replied', 'email_replies', 'When Joust answers a comment or adds a note on an item'],
            'live'   => ['Live & scheduled', 'email_live', 'A daily note when your approved items are scheduled or go live'],
            'remind' => ['Gentle reminders', 'email_remind', 'A short nudge when something has waited a few days for your review'],
        ];
    }
}

if (!function_exists('clientEmailAllowMail')) {
    /** Manage → Notifications: "Allow sending client emails without Google (mail())" — off unless ticked. */
    function clientEmailAllowMail(PDO $pdo): bool {
        return notifyMeta($pdo, 'client_emails_allow_mail', '0') === '1';
    }
}

if (!function_exists('clientEmailTransportOk')) {
    /**
     * Client emails go out only through Google (DKIM-signed, replies come back to the inbound address) — or PHP mail()
     * when the admin explicitly allowed it. Until then they are HELD: batches stay queued (nothing is dropped) and go
     * out once Google is connected. The test harness's sink counts as a real transport. Sign-in emails never wait.
     */
    function clientEmailTransportOk(PDO $pdo): bool {
        $t = function_exists('notifyMailTransport') ? notifyMailTransport() : 'mail';
        return $t === 'gmail' || $t === 'sink' || clientEmailAllowMail($pdo);
    }
}

if (!function_exists('clientEmailStagingDomains')) {
    /** Staging only: the email domains client emails may go to (config staging_allowed_email_domains — an array or a
     *  comma-separated list; default joustmedia.com). Sub-domains count. */
    function clientEmailStagingDomains(): array {
        $cfg = notifyConfig()['staging_allowed_email_domains'] ?? null;
        $list = is_array($cfg) ? $cfg : (is_scalar($cfg) && trim((string)$cfg) !== '' ? preg_split('/[\s,;]+/', (string)$cfg) : ['joustmedia.com']);
        return array_values(array_unique(array_filter(array_map(static function ($d) { return strtolower(trim((string)$d, " .\t@")); }, $list))));
    }
}

if (!function_exists('clientEmailStagingAllows')) {
    /** May a client email go to $email here? Always in production; on staging (portalEnvironment()) only to an
     *  address in clientEmailStagingDomains() — a staging copy with real client contacts never emails a real client. */
    function clientEmailStagingAllows(string $email): bool {
        if (!function_exists('portalEnvironment') || portalEnvironment() !== 'staging') return true;
        $dom = strtolower((string)substr((string)strrchr(trim($email), '@'), 1));
        if ($dom === '') return false;
        foreach (clientEmailStagingDomains() as $d) {
            if ($dom === $d || substr($dom, -strlen('.' . $d)) === '.' . $d) return true;
        }
        return false;
    }
}

if (!function_exists('clientEmailRemindReady')) {
    /** notify_clients.email_remind + remind_days exist (migrate.php 51). Cached per request. */
    function clientEmailRemindReady(?PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!$pdo) return false;
        try {
            return $ready = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'notify_clients' AND COLUMN_NAME IN ('email_remind','remind_days')")->fetchColumn() === 2;
        } catch (Throwable $e) {
            return $ready = false;
        }
    }
}

if (!function_exists('clientEmailRemindDays')) {
    /** A client's reminder interval in days (default 3; 0 = off). */
    function clientEmailRemindDays(PDO $pdo, int $companyId): int {
        if (!clientEmailRemindReady($pdo)) return 0;
        $s = $pdo->prepare("SELECT remind_days FROM notify_clients WHERE company_id = ?");
        $s->execute([$companyId]);
        $v = $s->fetchColumn();
        return $v === false ? 3 : max(0, min(30, (int)$v));
    }
}

if (!function_exists('clientEmailReviewTypes')) {
    /** Items a "Ready for your review" email can be about: posts / emails / pages, and assets sent for review (tire
     *  renders, grouped per series, and library images). */
    function clientEmailReviewTypes(): array { return ['post', 'email', 'page', 'tire_image', 'tire_series', 'library_image']; }
}

if (!function_exists('clientEmailReady')) {
    /** migrate.php 47 + 49 ran. Cached per request. */
    function clientEmailReady(?PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!$pdo) return false;
        try {
            $t = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_email_queue'")->fetchColumn();
            $c = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                    AND ((TABLE_NAME = 'client_contacts' AND COLUMN_NAME IN ('notify_prefs','unsubscribed_at'))
                      OR (TABLE_NAME = 'notify_clients' AND COLUMN_NAME = 'email_review'))")->fetchColumn();
            return $ready = ($t === 1 && $c === 3);
        } catch (Throwable $e) {
            return $ready = false;
        }
    }
}

// =====================================================================================================================
// Preferences
// =====================================================================================================================

if (!function_exists('clientContactPrefs')) {
    /** ['review' => bool, 'reply' => bool, 'live' => bool, 'unsubscribed' => bool] for a client_contacts row. */
    function clientContactPrefs(array $contact): array {
        $j = json_decode((string)($contact['notify_prefs'] ?? ''), true);
        $j = is_array($j) ? $j : [];
        $out = [];
        foreach (array_keys(clientEmailKinds()) as $k) $out[$k] = !array_key_exists($k, $j) || !empty($j[$k]);
        $out['unsubscribed'] = !empty($contact['unsubscribed_at']);
        return $out;
    }
}

if (!function_exists('clientContactPrefsSave')) {
    /** Store a contact's choices: $prefs kind → bool; $unsubscribed true = stop all, false = resubscribe, null = keep. */
    function clientContactPrefsSave(PDO $pdo, int $contactId, array $prefs, ?bool $unsubscribed = null): void {
        $j = [];
        foreach (array_keys(clientEmailKinds()) as $k) $j[$k] = !empty($prefs[$k]) ? 1 : 0;
        $pdo->prepare("UPDATE client_contacts SET notify_prefs = ? WHERE id = ?")->execute([json_encode($j), $contactId]);
        if ($unsubscribed === true) $pdo->prepare("UPDATE client_contacts SET unsubscribed_at = COALESCE(unsubscribed_at, NOW()) WHERE id = ?")->execute([$contactId]);
        if ($unsubscribed === false) $pdo->prepare("UPDATE client_contacts SET unsubscribed_at = NULL WHERE id = ?")->execute([$contactId]);
    }
}

if (!function_exists('clientEmailClientOn')) {
    /** The client's switch for a kind (Manage → Clients; default OFF — turned on per client when ready). */
    function clientEmailClientOn(PDO $pdo, int $companyId, string $kind): bool {
        $col = clientEmailKinds()[$kind][1] ?? null;
        if (!$col || !clientEmailReady($pdo)) return false;
        if ($kind === 'remind' && !clientEmailRemindReady($pdo)) return false;
        $s = $pdo->prepare("SELECT {$col} FROM notify_clients WHERE company_id = ?");
        $s->execute([$companyId]);
        $v = $s->fetchColumn();
        // Default OFF (migrate.php 47 / 50): client emails start only when Joust turns them on per client.
        return $v === false ? false : (bool)(int)$v;
    }
}

if (!function_exists('clientEmailClientSwitches')) {
    /** kind → bool for one client (Manage → Clients). */
    function clientEmailClientSwitches(PDO $pdo, int $companyId): array {
        $out = [];
        foreach (array_keys(clientEmailKinds()) as $k) $out[$k] = clientEmailClientOn($pdo, $companyId, $k);
        return $out;
    }
}

if (!function_exists('clientEmailRecipients')) {
    /** The contacts of a client who get $kind: the client switch on, not unsubscribed, the kind not turned off. */
    function clientEmailRecipients(PDO $pdo, int $companyId, string $kind): array {
        if (!clientEmailReady($pdo) || !clientEmailClientOn($pdo, $companyId, $kind)) return [];
        $s = $pdo->prepare("SELECT * FROM client_contacts WHERE company_id = ? AND unsubscribed_at IS NULL ORDER BY id ASC");
        $s->execute([$companyId]);
        return array_values(array_filter($s->fetchAll(), static function ($c) use ($kind) { return !empty(clientContactPrefs($c)[$kind]); }));
    }
}

// ---- the signed preferences / unsubscribe link -------------------------------------------------------------------------

if (!function_exists('clientPrefsSignature')) {
    function clientPrefsSignature(int $contactId, int $companyId, string $email, int $exp): string {
        $key = hash('sha256', 'joust-email-prefs|' . (function_exists('clientLinkSecret') ? clientLinkSecret() : __DIR__));
        return substr(clientB64(hash_hmac('sha256', 'prefs|v1|' . $contactId . '|' . $companyId . '|' . strtolower($email) . '|' . $exp, $key, true)), 0, 32);
    }
}

if (!function_exists('clientPrefsToken')) {
    /** <contact id>.<expiry>.<HMAC over contact, client, email, expiry> — valid a year, dead once the contact is removed
     *  or its address changes. */
    function clientPrefsToken(array $contact, int $ttlDays = 365): string {
        $exp = time() + $ttlDays * 86400;
        return (int)$contact['id'] . '.' . $exp . '.' . clientPrefsSignature((int)$contact['id'], (int)$contact['company_id'], (string)$contact['email'], $exp);
    }
}

if (!function_exists('clientPrefsVerify')) {
    /** A preferences token → the client_contacts row (+ company name / slug), or null. */
    function clientPrefsVerify(PDO $pdo, string $token): ?array {
        if (!preg_match('/^([1-9][0-9]{0,9})\.([0-9]{9,11})\.([A-Za-z0-9_-]{32})$/', $token, $m)) return null;
        if ((int)$m[2] < time()) return null;
        $s = $pdo->prepare("SELECT c.*, co.name AS company_name, co.slug, co.logo_url FROM client_contacts c INNER JOIN companies co ON co.id = c.company_id WHERE c.id = ?");
        $s->execute([(int)$m[1]]);
        $c = $s->fetch();
        if (!$c) return null;
        return hash_equals(clientPrefsSignature((int)$c['id'], (int)$c['company_id'], (string)$c['email'], (int)$m[2]), $m[3]) ? $c : null;
    }
}

if (!function_exists('clientPrefsUrl')) {
    /** Absolute preferences page for a contact (machine URL: session-free, never routed); $unsub = the one-click form. */
    function clientPrefsUrl(array $contact, bool $unsub = false): string {
        $p = ['t' => clientPrefsToken($contact)];
        if ($unsub) $p['u'] = 1;
        return notifyMachineUrl('email-prefs', $p);
    }
}

// =====================================================================================================================
// Queue (logActivity hook) + batches (cron)
// =====================================================================================================================

if (!function_exists('clientEmailOnActivity')) {
    /** notifyOnActivity() hook: queue the events client emails are about. Never throws. */
    function clientEmailOnActivity(PDO $pdo, int $activityId, array $row): void {
        try {
            if (!clientEmailReady($pdo)) return;
            $type = (string)$row['entity_type']; $a = (string)$row['action']; $actor = (string)$row['actor'];
            $kind = null;
            if ($actor === 'admin' && in_array($type, ['post', 'email', 'page'], true) && in_array($a, ['submitted', 'reset_pending', 'created'], true)) $kind = 'review';
            // assets sent for review: renders uploaded into a series, library uploads / FTP drops, an image reset to To Review
            elseif ($actor === 'admin' && in_array($type, ['tire_image', 'tire_series', 'library_image'], true) && in_array($a, ['uploaded', 'reset_pending', 'submitted'], true)) $kind = 'review';
            elseif ($actor === 'admin' && $a === 'commented' && empty($row['internal']) && trim((string)($row['detail'] ?? '')) !== ''
                    && in_array($type, notifyThreadTypes(), true)) $kind = 'reply';
            elseif (in_array($a, ['posted', 'marked_live'], true) && in_array($type, ['post', 'email', 'page'], true)) $kind = 'live';
            if ($kind === null) return;
            $pdo->prepare("INSERT INTO client_email_queue (company_id, kind, entity_type, entity_id, activity_id) VALUES (?, ?, ?, ?, ?)")
                ->execute([(int)$row['company_id'], $kind, $type, (int)$row['entity_id'], $activityId]);
        } catch (Throwable $e) {
            error_log('clientEmailOnActivity: ' . $e->getMessage());
        }
    }
}

if (!function_exists('clientEmailWindows')) {
    /** Minutes of quiet after the last change before a batch goes out. */
    function clientEmailWindows(): array { return ['review' => 15, 'reply' => 10]; }
}

if (!function_exists('clientEmailRemindDue')) {
    /** The daily reminder pass: once a day at / after the Morning summary hour (New York). */
    function clientEmailRemindDue(PDO $pdo): bool {
        if ((int)date('G') < notifySettings($pdo)['summary_hour']) return false;
        return notifyMeta($pdo, 'client_remind_last', '1970-01-01') !== date('Y-m-d');
    }
}

if (!defined('CLIENT_REMIND_MAX_PER_ITEM')) define('CLIENT_REMIND_MAX_PER_ITEM', 2);

if (!function_exists('clientEmailClientLastActive')) {
    /** When anyone at the client last did anything in the portal — a comment / decision / edit (an activity row by
     *  the client) or a signed-in visit (client_sessions.last_seen_at). '' = never. */
    function clientEmailClientLastActive(PDO $pdo, int $companyId): string {
        $at = '';
        foreach (["SELECT MAX(created_at) FROM activity_log WHERE company_id = ? AND actor = 'client'",
                  "SELECT MAX(last_seen_at) FROM client_sessions WHERE company_id = ?"] as $sql) {
            try {
                $s = $pdo->prepare($sql);
                $s->execute([$companyId]);
                $v = (string)($s->fetchColumn() ?: '');
                if ($v !== '' && ($at === '' || strtotime($v) > strtotime($at))) $at = $v;
            } catch (Throwable $e) {}
        }
        return $at;
    }
}

if (!function_exists('clientEmailRemindQueue')) {
    /**
     * Stale To Review items → at most ONE gentle reminder email per client every N days (the client's remind_days,
     * default 3; 0 = off), covering every item waiting on the client (no answer since it was sent) for ≥ N days:
     *   · per client: nothing if the client's last reminder went out less than N days ago
     *   · per item: at most CLIENT_REMIND_MAX_PER_ITEM (2) reminders per review request, then never again
     *   · the client is around: once anyone at the client did anything in the portal (a comment, a decision, a
     *     signed-in visit) after the last reminder, reminders stop for everything sent before that activity — only
     *     items sent for review after it can start a new cycle
     * → batches closed (one per client).
     */
    function clientEmailRemindQueue(PDO $pdo): int {
        if (!clientEmailRemindReady($pdo) || !function_exists('trackingWaitingOnClient')) return 0;
        $n = 0;
        $rows = $pdo->query("SELECT company_id, remind_days FROM notify_clients WHERE email_remind = 1 AND remind_days > 0")->fetchAll();
        $last = $pdo->prepare("SELECT MAX(created_at) FROM client_email_queue WHERE kind = 'remind' AND company_id = ?");
        $count = $pdo->prepare("SELECT COUNT(*) FROM client_email_queue WHERE kind = 'remind' AND entity_type = ? AND entity_id = ? AND created_at >= ?");
        $ins = $pdo->prepare("INSERT INTO client_email_queue (company_id, kind, entity_type, entity_id) VALUES (?, 'remind', ?, ?)");
        foreach ($rows as $c) {
            $cid = (int)$c['company_id']; $days = max(1, min(30, (int)$c['remind_days']));
            $cut = time() - $days * 86400;
            $last->execute([$cid]);
            $lastAt = (string)($last->fetchColumn() ?: '');
            if ($lastAt !== '' && strtotime($lastAt) > $cut) continue;                          // one email per client per N days
            $active = $lastAt !== '' ? clientEmailClientLastActive($pdo, $cid) : '';
            $activeAt = ($active !== '' && strtotime($active) > strtotime($lastAt)) ? strtotime($active) : 0;   // the client is around
            $pick = [];
            foreach (trackingWaitingOnClient($pdo, $cid) as $w) {
                if (empty($w['since']) || strtotime((string)$w['since']) > $cut) continue;     // not N days old yet
                if ($activeAt && strtotime((string)$w['since']) <= $activeAt) continue;            // seen it, chose not to answer
                $count->execute([$w['entity_type'], (int)$w['entity_id'], (string)$w['since']]);
                if ((int)$count->fetchColumn() >= CLIENT_REMIND_MAX_PER_ITEM) continue;          // reminded twice already
                $pick[] = $w;
            }
            if (!$pick) continue;
            foreach ($pick as $w) $ins->execute([$cid, $w['entity_type'], (int)$w['entity_id']]);
            $max = (int)$pdo->query("SELECT MAX(id) FROM client_email_queue WHERE kind = 'remind' AND batch_key IS NULL AND company_id = " . $cid)->fetchColumn();
            clientEmailBatch($pdo, $cid, 'remind', $max);
            $n++;
        }
        return $n;
    }
}

if (!function_exists('clientEmailLiveDue')) {
    /** The daily Live & scheduled email: once a day at / after the Morning summary hour (New York). */
    function clientEmailLiveDue(PDO $pdo): bool {
        if ((int)date('G') < notifySettings($pdo)['summary_hour']) return false;
        return notifyMeta($pdo, 'client_live_last', '1970-01-01') !== date('Y-m-d');
    }
}

if (!function_exists('clientEmailMaxAgeHours')) {
    /** How old a held client email may be and still go out (config client_email_max_age_hours, default 72, 1–720). */
    function clientEmailMaxAgeHours(): int {
        $h = (int)notifyCfg('client_email_max_age_hours', '72');
        return $h > 0 ? min(720, $h) : 72;
    }
}

if (!function_exists('clientEmailExpireStale')) {
    /**
     * Held client email that is too old to send (clientEmailMaxAgeHours()): open queue rows are closed as
     * "expired:<kind>:<client>:<max id>" and each such batch gets one Delivery-log row (outbox status skipped,
     * "expired, not sent …") — a weeks-old "Joust replied" or "Ready for your review" never goes out when Google is
     * finally connected. → batches expired.
     */
    function clientEmailExpireStale(PDO $pdo): int {
        $h = clientEmailMaxAgeHours();
        $s = $pdo->prepare("SELECT company_id, kind, MAX(id) AS max_id, COUNT(*) AS n, MIN(created_at) AS oldest FROM client_email_queue
                             WHERE batch_key IS NULL AND created_at < NOW() - INTERVAL ? HOUR GROUP BY company_id, kind");
        $s->execute([$h]);
        $n = 0;
        foreach ($s->fetchAll() as $r) {
            $cid = (int)$r['company_id']; $kind = (string)$r['kind']; $key = 'expired:' . $kind . ':' . $cid . ':' . (int)$r['max_id'];
            $u = $pdo->prepare("UPDATE client_email_queue SET batch_key = ?, batched_at = NOW() WHERE batch_key IS NULL AND kind = ? AND company_id = ? AND id <= ?
                                   AND created_at < NOW() - INTERVAL ? HOUR");
            $u->execute([$key, $kind, $cid, (int)$r['max_id'], $h]);
            if ($u->rowCount() === 0) continue;
            $why = 'expired, not sent: held longer than ' . $h . ' h (' . $u->rowCount() . ' item' . ($u->rowCount() === 1 ? '' : 's') . ', oldest ' . $r['oldest'] . ')';
            try {
                $pdo->prepare("INSERT IGNORE INTO notify_outbox (channel, kind, company_id, target, payload, status, last_error, dedupe_key) VALUES ('email', 'client_email', ?, '', ?, 'skipped', ?, ?)")
                    ->execute([$cid, json_encode(['batch_key' => $key, 'kind' => $kind, 'company_id' => $cid, 'expired' => 1], JSON_UNESCAPED_SLASHES), $why, 'ce:' . $key]);
            } catch (Throwable $e) {
                error_log('clientEmailExpireStale: ' . $e->getMessage());
            }
            $n++;
        }
        return $n;
    }
}

if (!function_exists('clientEmailRun')) {
    /**
     * The cron step: close every batch whose window has passed and queue one email per recipient.
     * $o['live_now'] forces the daily Live batch. → ['review' => batches, 'reply' => …, 'live' => …, 'emails' => n].
     */
    function clientEmailRun(PDO $pdo, array $o = []): array {
        $out = ['review' => 0, 'reply' => 0, 'live' => 0, 'remind' => 0, 'emails' => 0];
        if (!clientEmailReady($pdo)) return $out;
        if (!clientEmailTransportOk($pdo)) {
            // Held until Google is connected (or mail() is explicitly allowed): nothing is batched or dropped.
            $out['held'] = (int)$pdo->query("SELECT COUNT(*) FROM client_email_queue WHERE batch_key IS NULL")->fetchColumn();
            return $out;
        }
        $out['expired'] = clientEmailExpireStale($pdo);
        foreach (clientEmailWindows() as $kind => $min) {
            $s = $pdo->prepare("SELECT company_id, MAX(id) AS max_id FROM client_email_queue WHERE batch_key IS NULL AND kind = ?
                                GROUP BY company_id HAVING MAX(created_at) <= NOW() - INTERVAL ? MINUTE");
            $s->execute([$kind, $min]);
            foreach ($s->fetchAll() as $r) {
                $out['emails'] += clientEmailBatch($pdo, (int)$r['company_id'], $kind, (int)$r['max_id']);
                $out[$kind]++;
            }
        }
        if (!empty($o['live_now']) || clientEmailLiveDue($pdo)) {
            $s = $pdo->query("SELECT company_id, MAX(id) AS max_id FROM client_email_queue WHERE batch_key IS NULL AND kind = 'live' GROUP BY company_id");
            foreach ($s->fetchAll() as $r) {
                $out['emails'] += clientEmailBatch($pdo, (int)$r['company_id'], 'live', (int)$r['max_id']);
                $out['live']++;
            }
            if (empty($o['live_now'])) notifyMetaSet($pdo, 'client_live_last', date('Y-m-d'));
        }
        if (!empty($o['remind_now']) || clientEmailRemindDue($pdo)) {
            $before = (int)$pdo->query("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'client_email'")->fetchColumn();
            $out['remind'] = clientEmailRemindQueue($pdo);
            $out['emails'] += max(0, (int)$pdo->query("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'client_email'")->fetchColumn() - $before);
            if (empty($o['remind_now'])) notifyMetaSet($pdo, 'client_remind_last', date('Y-m-d'));
        }
        return $out;
    }
}

if (!function_exists('clientEmailBatch')) {
    /** Close one batch (its rows get the batch key) and enqueue an email per recipient. Returns emails queued. */
    function clientEmailBatch(PDO $pdo, int $companyId, string $kind, int $maxId): int {
        $key = $kind . ':' . $companyId . ':' . $maxId;
        $u = $pdo->prepare("UPDATE client_email_queue SET batch_key = ?, batched_at = NOW() WHERE batch_key IS NULL AND kind = ? AND company_id = ? AND id <= ?");
        $u->execute([$key, $kind, $companyId, $maxId]);
        if ($u->rowCount() === 0) return 0;
        $n = 0;
        foreach (clientEmailRecipients($pdo, $companyId, $kind) as $c) {
            $id = notifyEnqueue($pdo, 'email', 'client_email', ['batch_key' => $key, 'kind' => $kind, 'company_id' => $companyId, 'contact_id' => (int)$c['id']],
                ['dedupe' => 'ce:' . $key . ':' . (int)$c['id'], 'company_id' => $companyId, 'target' => (string)$c['email'], 'defer' => true]);
            if ($id > 0) $n++;
        }
        return $n;
    }
}

// =====================================================================================================================
// Delivery (outbox kind client_email)
// =====================================================================================================================

if (!function_exists('clientEmailItems')) {
    /**
     * The items one batch email is about, re-checked NOW: the item still exists, still belongs to THIS client, and is
     * still in the state the email talks about (review: To Review · live: Scheduled / Live). Replies carry only
     * visible Joust comments of this client (internal notes are never selected).
     * → [['info' => notifyItemInfo(), 'comments' => [rows]], …] in queue order.
     */
    function clientEmailItems(PDO $pdo, string $kind, array $queueRows, int $companyId): array {
        $seen = []; $items = []; $ids = [];
        foreach ($queueRows as $q) {
            if ((int)$q['company_id'] !== $companyId) continue;
            $k = $q['entity_type'] . ':' . (int)$q['entity_id'];
            if (!empty($q['activity_id'])) $ids[$k][] = (int)$q['activity_id'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $items[$k] = ['type' => (string)$q['entity_type'], 'id' => (int)$q['entity_id']];
        }
        $out = [];
        foreach ($items as $k => $it) {
            $info = notifyItemInfo($pdo, $it['type'], $it['id']);
            if (!$info['exists'] || (int)$info['company_id'] !== $companyId) continue;
            $comments = [];
            if (($kind === 'review' || $kind === 'remind') && !in_array($info['status_key'], ['pending', 'mixed'], true)) continue;
            if ($kind === 'remind') $info['waiting_since'] = clientEmailWaitingSince($pdo, $it['type'], $it['id']);
            if ($kind === 'live' && !in_array($info['status_key'], ['scheduled', 'live'], true)) continue;
            if ($kind === 'reply') {
                $aids = array_values(array_unique($ids[$k] ?? []));
                if (!$aids) continue;
                $ph = implode(',', array_fill(0, count($aids), '?'));
                $s = $pdo->prepare("SELECT id, detail, author_user_id, internal, created_at FROM activity_log
                                     WHERE id IN ($ph) AND company_id = ? AND entity_type = ? AND entity_id = ? AND actor = 'admin'
                                       AND action = 'commented' AND internal = 0 AND detail IS NOT NULL AND detail <> '' ORDER BY id ASC");
                $s->execute(array_merge($aids, [$companyId, $it['type'], $it['id']]));
                $comments = $s->fetchAll();
                if (!$comments) continue;
            }
            $out[] = ['info' => $info, 'comments' => $comments];
        }
        return $out;
    }
}

if (!function_exists('clientEmailWaitingSince')) {
    /** When an item was (last) sent for review — the newest submitted / reset / created / uploaded row ('' unknown). */
    function clientEmailWaitingSince(PDO $pdo, string $type, int $id): string {
        $s = $pdo->prepare("SELECT MAX(created_at) FROM activity_log WHERE entity_type = ? AND entity_id = ? AND action IN ('submitted','reset_pending','created','uploaded')");
        $s->execute([$type, $id]);
        return (string)($s->fetchColumn() ?: '');
    }
}

if (!function_exists('clientEmailDeliver')) {
    /** Render + send one recipient's copy of a batch. */
    function clientEmailDeliver(PDO $pdo, array $p): array {
        $kind = (string)($p['kind'] ?? ''); $cid = (int)($p['company_id'] ?? 0);
        if (!isset(clientEmailKinds()[$kind])) return ['ok' => false, 'permanent' => true, 'error' => 'unknown client email kind'];
        if (!empty($p['expired'])) return ['ok' => false, 'skip' => true, 'error' => 'expired, not sent'];
        $s = $pdo->prepare("SELECT * FROM client_contacts WHERE id = ? AND company_id = ?");
        $s->execute([(int)($p['contact_id'] ?? 0), $cid]);
        $contact = $s->fetch();
        if (!$contact) return ['ok' => false, 'skip' => true, 'error' => 'the contact was removed'];
        if (!clientEmailStagingAllows((string)$contact['email'])) {   // staging: test contacts only — held back, logged, never sent
            return ['ok' => false, 'skip' => true, 'error' => 'blocked on staging: ' . $contact['email'] . ' is not in staging_allowed_email_domains (' . implode(', ', clientEmailStagingDomains()) . ')'];
        }
        $prefs = clientContactPrefs($contact);
        if ($prefs['unsubscribed'] || empty($prefs[$kind])) return ['ok' => false, 'skip' => true, 'error' => 'the contact turned these emails off'];
        if (!clientEmailClientOn($pdo, $cid, $kind)) return ['ok' => false, 'skip' => true, 'error' => 'client emails of this kind are off for the client'];
        if (!clientEmailTransportOk($pdo)) return ['ok' => false, 'hold' => true, 'retry_in' => 3600, 'error' => 'held: client emails wait for Google (Manage → Notifications → Connect Google)'];
        $company = clientCompanyById($pdo, $cid);
        if (!$company) return ['ok' => false, 'skip' => true, 'error' => 'unknown client'];
        $q = $pdo->prepare("SELECT * FROM client_email_queue WHERE batch_key = ? AND company_id = ? ORDER BY id ASC");
        $q->execute([(string)($p['batch_key'] ?? ''), $cid]);
        $qrows = $q->fetchAll();
        // an email held (or retried) past clientEmailMaxAgeHours() since the newest change it is about: too stale to send
        $newest = $qrows ? max(array_map(static function ($r) { return strtotime((string)$r['created_at']) ?: 0; }, $qrows)) : 0;
        if ($newest > 0 && $newest < time() - clientEmailMaxAgeHours() * 3600) return ['ok' => false, 'skip' => true, 'error' => 'expired, not sent: held longer than ' . clientEmailMaxAgeHours() . ' h'];
        $items = clientEmailItems($pdo, $kind, $qrows, $cid);
        if (!$items) return ['ok' => false, 'skip' => true, 'error' => 'nothing left to send (the items changed since)'];
        $mail = clientEmailCompose($pdo, $kind, $company, $contact, $items);
        $res = notifyEmail($mail);
        if (!$res['ok']) return ['ok' => false, 'error' => 'email: ' . $res['error']];
        $one = count($items) === 1 ? $items[0]['info'] : null;
        notifyEmailRefAdd($pdo, $res['message_id'], $cid, $one ? (string)$one['entity_type'] : null, $one ? (int)$one['entity_id'] : null, (int)$contact['id'], $kind);
        return ['ok' => true, 'provider_id' => $res['message_id']];
    }
}

if (!function_exists('clientEmailCompose')) {
    /** The notifyEmail() message for one recipient: per-recipient links, threading, unsubscribe headers. */
    function clientEmailCompose(PDO $pdo, string $kind, array $company, array $contact, array $items): array {
        $one = count($items) === 1;
        $rendered = [];
        foreach ($items as $it) {
            $info = $it['info'];
            $rendered[] = [
                // the client's words: its Needs-changes items are "Sent back" (sentback-lib.php), as in the portal
                'title' => (string)$info['title'], 'type' => (string)$info['type_label'],
                'status' => $info['status_key'] === 'denied' && function_exists('sentBackLabel') ? sentBackLabel() : (string)$info['status_label'],
                'status_key' => (string)$info['status_key'],
                'waiting' => (string)($info['waiting_since'] ?? ''),
                'link'  => notifyItemLinkFor($pdo, $info, (string)$contact['email']),   // clientLink() — signs THIS contact in
                'thumb' => $info['thumb'] !== '' ? notifyThumbUrl((string)$info['thumb']) : '',
                'token' => notifyItemToken($pdo, (string)$info['entity_type'], (int)$info['entity_id'], (int)$info['company_id']),
                'comments' => array_map(static function ($c) use ($pdo) {
                    $u = adminUserById($pdo, (int)($c['author_user_id'] ?? 0));
                    return ['text' => (string)$c['detail'], 'who' => ($u ? adminUserFirstName($u) . ' at Joust' : 'Joust'), 'at' => (string)$c['created_at']];
                }, $it['comments']),
            ];
        }
        $r = clientEmailRender($kind, $company, $contact, $rendered, ['prefs_url' => clientPrefsUrl($contact), 'unsub_url' => clientPrefsUrl($contact, true)]);
        $msg = ['to' => (string)$contact['email'], 'subject' => $r['subject'], 'text' => $r['text'], 'html' => $r['html'],
                'reply_to' => inboundAddress(), 'kind' => 'client_' . $kind,
                'headers' => ['List-Unsubscribe' => '<' . clientPrefsUrl($contact, true) . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                              'X-Joust-Portal' => $kind]];
        if ($one) {
            $info = $items[0]['info'];
            $msg['thread'] = ['entity_type' => (string)$info['entity_type'], 'entity_id' => (int)$info['entity_id'], 'company_id' => (int)$info['company_id']];
        }
        return $msg;
    }
}

// =====================================================================================================================
// Templates (tables + inline CSS; a plain-text twin)
// =====================================================================================================================

if (!function_exists('clientEmailEsc')) {
    function clientEmailEsc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('clientEmailAgo')) {
    /** '4 days ago' / 'yesterday' / 'today' for a datetime. */
    function clientEmailAgo(string $at): string {
        $ts = strtotime($at);
        if (!$ts) return '';
        $d = (int)floor((strtotime('today') - strtotime(date('Y-m-d', $ts))) / 86400);
        return $d <= 0 ? 'today' : ($d === 1 ? 'yesterday' : $d . ' days ago');
    }
}

if (!function_exists('clientEmailLayout')) {
    /**
     * The shared email frame: grouped-background page, Joust logo + name, the client's name, one white card,
     * a footer. $o: preheader, eyebrow (the client name), footer_html, footer_text. Responsive (≤ 620 px: full width,
     * smaller thumbs, block buttons) with inline styles first (clients that drop <style> still get the layout).
     */
    function clientEmailLayout(string $title, string $bodyHtml, array $o = []): string {
        $e = 'clientEmailEsc';
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $logo = rtrim(notifyBaseUrl(), '/') . '/static/brand/joust-180.png';
        $eyebrow = (string)($o['eyebrow'] ?? '');
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
             . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . $e($title) . '</title>'
             . '<style>body{margin:0;padding:0}img{border:0;outline:none;text-decoration:none}a{color:#007AFF}'
             . '@media only screen and (max-width:620px){.jm-wrap{width:100% !important}.jm-card{padding:22px 18px 6px !important;border-radius:14px !important}'
             . '.jm-thumb{width:56px !important;height:56px !important}.jm-thumbcell{width:68px !important}.jm-h1{font-size:22px !important;line-height:28px !important}'
             . '.jm-btns td{display:block !important;padding:0 0 8px !important}.jm-btn{display:block !important;text-align:center !important}.jm-outer{padding:16px 8px !important}}</style></head>'
             . '<body style="margin:0;padding:0;background:#F2F2F7;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%">'
             . '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F2F2F7">' . $e($o['preheader'] ?? '') . '</div>'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F2F2F7"><tr><td align="center" class="jm-outer" style="padding:28px 12px">'
             . '<table role="presentation" class="jm-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px">'
             // header: Joust mark + name · client name
             . '<tr><td style="padding:0 6px 14px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
             . '<td width="44" valign="middle" style="width:44px"><img src="' . $e($logo) . '" width="36" height="36" alt="Joust Media" style="display:block;width:36px;height:36px;border-radius:9px"></td>'
             . '<td valign="middle" style="font:600 16px/20px ' . $font . ';color:#1C1C1E">Joust Media</td>'
             . ($eyebrow !== '' ? '<td valign="middle" align="right" style="font:600 13px/18px ' . $font . ';color:#8E8E93;letter-spacing:.2px" data-email-client>' . $e($eyebrow) . '</td>' : '')
             . '</tr></table></td></tr>'
             // the card
             . '<tr><td class="jm-card" style="background:#FFFFFF;border-radius:16px;padding:28px 28px 10px">' . $bodyHtml . '</td></tr>'
             // footer
             . '<tr><td style="padding:18px 10px 6px;font:12px/18px ' . $font . ';color:#8E8E93;text-align:center">' . ($o['footer_html'] ?? '') . '</td></tr>'
             . '</table></td></tr></table></body></html>';
    }
}

if (!function_exists('clientEmailRender')) {
    /**
     * One client email. $items: [['title', 'type', 'status', 'status_key', 'link', 'thumb', 'token', 'comments' =>
     * [['text', 'who', 'at']]]] (already this recipient's links). $o: prefs_url, unsub_url.
     * → ['subject', 'html', 'text'].
     */
    function clientEmailRender(string $kind, array $company, array $contact, array $items, array $o = []): array {
        $e = 'clientEmailEsc';
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $co = (string)$company['name'];
        $n = count($items);
        $one = $n === 1;
        $first = trim((string)($contact['name'] ?? ''));
        $first = $first !== '' ? preg_split('/\s+/', $first)[0] : '';
        $hi = $first !== '' ? 'Hi ' . $first . ',' : 'Hi,';
        $tok = static function (array $it): string { return $it['token'] !== '' ? ' [J#' . $it['token'] . ']' : ''; };
        switch ($kind) {
            case 'review':
                $subject = $one ? 'Ready for your review: ' . $items[0]['title'] . $tok($items[0]) : $n . ' items ready for your review — ' . $co;
                $title = $one ? 'Ready for your review' : $n . ' items ready for your review';
                $intro = $one ? 'Joust just sent this over for you to look at. Approve it or ask for changes right in the portal.'
                              : 'Joust just sent these over for you to look at. Approve them or ask for changes right in the portal.';
                $btn = 'Review';
                break;
            case 'remind':
                $subject = $one ? 'A gentle reminder: ' . $items[0]['title'] . ' is waiting for your review' . $tok($items[0])
                                : 'A gentle reminder: ' . $n . ' items are waiting for your review — ' . $co;
                $title = $one ? 'Still waiting for your review' : $n . ' items still waiting for your review';
                $intro = 'No rush — just a friendly nudge. ' . ($one ? 'This has' : 'These have') . ' been waiting a few days. Approve or ask for changes in the portal, or reply to this email.';
                $btn = 'Review';
                break;
            case 'reply':
                $subject = $one ? 'Joust replied: ' . $items[0]['title'] . $tok($items[0]) : 'Joust replied on ' . $n . ' items — ' . $co;
                $title = $one ? 'Joust replied' : 'Joust replied on ' . $n . ' items';
                $intro = 'There’s a new message from Joust. Answer in the portal, or just reply to this email.';
                $btn = 'Open';
                break;
            default:
                $allLive = !in_array('scheduled', array_column($items, 'status_key'), true);
                $subject = ($one ? ($items[0]['status_key'] === 'live' ? 'Now live: ' : 'Scheduled: ') . $items[0]['title']
                                 : ($allLive ? 'Now live: ' : 'Scheduled & live: ') . $n . ' items — ' . $co);
                $title = $one ? ($items[0]['status_key'] === 'live' ? 'It’s live' : 'It’s scheduled') : 'Scheduled & live';
                $intro = $one ? 'Something you approved is on its way.' : 'Here’s what you approved that is now scheduled or live.';
                $btn = 'View';
        }
        $replyAddr = inboundAddress();
        $rows = ''; $text = $hi . "\n\n" . $intro . "\n";
        foreach ($items as $i => $it) {
            $thumb = $it['thumb'] !== ''
                ? '<img class="jm-thumb" src="' . $e($it['thumb']) . '" width="64" height="64" alt="" style="display:block;width:64px;height:64px;border-radius:10px;background:#E5E5EA">'
                : '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td class="jm-thumb" width="64" height="64" align="center" valign="middle" style="width:64px;height:64px;border-radius:10px;background:#E8F1FF;font:700 22px/64px ' . $font . ';color:#007AFF">'
                  . $e(mb_strtoupper(mb_substr($it['type'] !== '' ? $it['type'] : 'I', 0, 1))) . '</td></tr></table>';
            $mailto = 'mailto:' . $replyAddr . '?subject=' . rawurlencode('Re: ' . $it['title'] . $tok($it));
            $bubbles = '';
            foreach ($it['comments'] as $c) {
                $ts = strtotime((string)$c['at']);
                $bubbles .= '<div style="margin:10px 0 0;padding:10px 13px;background:#F2F2F7;border-radius:14px;font:15px/21px ' . $font . ';color:#1C1C1E" data-email-comment>'
                          . nl2br($e($c['text'])) . '</div>'
                          . '<div style="margin:4px 0 0 4px;font:12px/16px ' . $font . ';color:#8E8E93">' . $e($c['who']) . ($ts ? ' · ' . $e(date('M j, g:i A', $ts)) : '') . '</div>';
            }
            $rows .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #E5E5EA" data-email-item>'
                   . '<tr><td class="jm-thumbcell" width="78" valign="top" style="width:78px;padding:16px 14px 16px 0">' . $thumb . '</td>'
                   . '<td valign="top" style="padding:16px 0">'
                   . '<div style="font:600 16px/21px ' . $font . ';color:#000000">' . $e($it['title']) . '</div>'
                   . '<div style="margin-top:2px;font:13px/18px ' . $font . ';color:#8E8E93">' . $e(trim($it['type'] . ' · ' . $it['status'], ' ·'))
                   . (($it['waiting'] ?? '') !== '' ? ' · <span data-email-waiting>sent ' . $e(clientEmailAgo((string)$it['waiting'])) . '</span>' : '') . '</div>'
                   . $bubbles
                   . '<table role="presentation" class="jm-btns" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px"><tr>'
                   . '<td style="padding:0 12px 0 0"><a class="jm-btn" href="' . $e($it['link']) . '" style="display:inline-block;padding:9px 18px;border-radius:10px;background:#007AFF;color:#FFFFFF;font:600 14px/18px ' . $font . ';text-decoration:none" data-email-link>' . $e($btn) . '</a></td>'
                   . ($kind !== 'live' ? '<td style="padding:0"><a href="' . $e($mailto) . '" style="font:14px/18px ' . $font . ';color:#007AFF;text-decoration:none" data-email-mailto>Reply by email</a></td>' : '')
                   . '</tr></table></td></tr></table>';
            $text .= "\n• " . $it['title'] . ' (' . trim($it['type'] . ', ' . $it['status'], ', ') . (($it['waiting'] ?? '') !== '' ? ', sent ' . clientEmailAgo((string)$it['waiting']) : '') . ")\n";
            foreach ($it['comments'] as $c) $text .= '  ' . $c['who'] . ': ' . str_replace("\n", "\n  ", $c['text']) . "\n";
            $text .= '  ' . $btn . ': ' . $it['link'] . "\n";
            if ($kind !== 'live' && $it['token'] !== '') $text .= '  Reply by email: write to ' . $replyAddr . ' with [J#' . $it['token'] . "] in the subject\n";
        }
        $body = '<div style="font:600 13px/18px ' . $font . ';color:#8E8E93;text-transform:uppercase;letter-spacing:.4px">' . $e($co) . '</div>'
              . '<h1 class="jm-h1" style="margin:4px 0 10px;font:700 26px/32px ' . $font . ';color:#000000">' . $e($title) . '</h1>'
              . '<p style="margin:0 0 6px;font:15px/22px ' . $font . ';color:#3C3C43">' . $e($hi) . '</p>'
              . '<p style="margin:0 0 16px;font:15px/22px ' . $font . ';color:#3C3C43">' . $e($intro) . '</p>'
              . $rows;
        $prefs = (string)($o['prefs_url'] ?? '#');
        $unsub = (string)($o['unsub_url'] ?? '#');
        $footer = 'You’re getting this because you review content for ' . $e($co) . ' with Joust Media.<br>'
                . 'Reply to this email to reach Joust. · <a href="' . $e($prefs) . '" style="color:#8E8E93;text-decoration:underline" data-email-prefs>Email preferences</a>'
                . ' · <a href="' . $e($unsub) . '" style="color:#8E8E93;text-decoration:underline" data-email-unsub>Unsubscribe</a>';
        $text .= "\n—\nJoust Media · reply to this email to reach Joust.\nEmail preferences: " . $prefs . "\nUnsubscribe: " . $unsub . "\n";
        $html = clientEmailLayout($subject, $body, ['eyebrow' => $co, 'preheader' => $intro, 'footer_html' => $footer]);
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
}

// =====================================================================================================================
// Previews (Manage → Notifications → Preview; email-preview.php)
// =====================================================================================================================

if (!function_exists('clientEmailSample')) {
    /** Sample data for a template preview: the client's real recent items when it has some (thumbnails), else
     *  made-up ones. Nothing is written; links are inert. → [company, contact, items]. */
    function clientEmailSample(PDO $pdo, string $kind, ?array $company): array {
        $company = $company ?: ['id' => 0, 'name' => 'Kenda Tires', 'slug' => 'kenda'];
        $contact = ['id' => 0, 'company_id' => (int)$company['id'], 'email' => 'jane@example.com', 'name' => 'Jane Example'];
        if (!empty($company['id'])) {
            $cs = clientContacts($pdo, (int)$company['id']);
            if ($cs) $contact = ['name' => (string)($cs[0]['name'] ?? ''), 'email' => (string)$cs[0]['email']] + $contact;
        }
        $items = [];
        if (!empty($company['id'])) {
            $s = $pdo->prepare("SELECT id FROM posts WHERE company_id = ? AND status <> 'draft' ORDER BY id DESC LIMIT 3");
            $s->execute([(int)$company['id']]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $info = notifyItemInfo($pdo, 'post', (int)$pid);
                if ($info['exists']) $items[] = $info;
            }
            if (count($items) < 2 && function_exists('hasEmailsTable') && hasEmailsTable($pdo)) {
                $s = $pdo->prepare("SELECT id FROM emails WHERE company_id = ? ORDER BY id DESC LIMIT 2");
                $s->execute([(int)$company['id']]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $eid) { $info = notifyItemInfo($pdo, 'email', (int)$eid); if ($info['exists']) $items[] = $info; }
            }
        }
        if (!$items) {
            foreach ([['Spring launch hero', 'Post'], ['AT2 carousel', 'Post'], ['W2 · Your first scan', 'Email']] as [$t, $ty]) {
                $items[] = ['title' => $t, 'type_label' => $ty, 'thumb' => '', 'status_key' => 'pending', 'status_label' => 'To Review'];
            }
        }
        $status = ['review' => ['pending', 'To Review'], 'reply' => ['pending', 'To Review'], 'live' => ['scheduled', 'Scheduled'], 'remind' => ['pending', 'To Review']][$kind] ?? ['pending', 'To Review'];
        $out = [];
        foreach (array_slice($items, 0, $kind === 'reply' ? 1 : 3) as $i => $info) {
            $out[] = ['title' => (string)$info['title'], 'type' => (string)$info['type_label'], 'status' => $status[1], 'status_key' => $status[0],
                      'link' => '#preview', 'thumb' => ($info['thumb'] ?? '') !== '' ? notifyThumbUrl((string)$info['thumb']) : '', 'token' => 'ab12cd',
                      'waiting' => $kind === 'remind' ? date('Y-m-d H:i:s', time() - (4 + $i) * 86400) : '',
                      'comments' => $kind === 'reply' ? [['text' => "Good catch — we swapped in the darker render and tightened the crop.\nHave another look when you get a sec?", 'who' => 'Lance at Joust', 'at' => date('Y-m-d H:i:s', time() - 600)]] : []];
        }
        return [$company, $contact, $out];
    }
}
