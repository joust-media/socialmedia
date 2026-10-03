<?php
/**
 * Notifications — named Joust authors, internal notes, the outbox (Slack + email), Slack threads per portal item,
 * escalation of unanswered client messages, the Morning summary, and the email abstraction (notifyEmail()).
 * Loaded by helpers.php (end of the chain); every function is function_exists-guarded; no output, no work at load.
 *
 * ── Data (migrate.php 36–39; notifyReady() gates everything until they exist) ──────────────────────────────────
 *   admin_users      named Joust people (name, email, slack_user_id). Lance is seeded from auth.php's login.
 *   activity_log     + author_user_id (admin_users.id of whoever wrote it; NULL = client / legacy) + internal (Joust-only).
 *   notify_outbox    every outbound message: channel slack|email, kind, payload JSON, status pending → sending → sent |
 *                    failed (gave up / permanent error) | skipped (nothing to do), attempts, next_attempt_at (backoff),
 *                    last_error, dedupe_key (idempotent enqueue), provider_id (Slack ts / email Message-ID).
 *   notify_clients   per client: Slack channel id (+ name) and the owner (admin_users.id) to @mention.
 *   notify_threads   per portal item: the Slack parent message (channel + ts + rendered hash) and email_message_id
 *                    (the first Message-ID about the item; later mails carry In-Reply-To / References to it).
 *   slack_inbox      every verified Slack event / interaction by id (dedupe) and what was done with it.
 *   meta             notify_t1_minutes (60), notify_t2_minutes (240), notify_summary_hour (8), notify_summary_last,
 *                    notify_since (escalation floor), notify_cron_last.
 *
 * ── Flow ──────────────────────────────────────────────────────────────────────────────────────────────────────────
 *   logActivity() (helpers.php) → notifyOnActivity(): a CLIENT comment / decision / copy edit enqueues one Slack
 *   'item_event' per request batch (posted in the item's thread, the parent is created on first use); any later
 *   status change or answer on an item that already has a thread enqueues a 'parent_update' (chat.update). Joust's
 *   own actions are never posted. Enqueued rows are delivered after the response is flushed (notifyAfterResponse:
 *   fastcgi_finish_request / litespeed_finish_request, else flush + a short inline attempt, 3 s curl timeouts); the
 *   cron endpoint (notify-cron.php) retries with backoff, runs the escalation checks and the Morning summary.
 *
 * ── Interfaces other work plugs into ───────────────────────────────────────────────────────────────────────────────
 *   portalUrl($page, $params) (url-lib.php: root-rooted, clean when clean links are on) → notifyAbsoluteUrl() for
 *   an absolute link; portalItemUrl(...) is the absolute deep link to one item (admin form — Slack "Open in portal",
 *   reminder emails to Joust). Any link a CLIENT receives by email goes through notifyItemLinkFor() → clientLink()
 *   (client-auth-lib.php: signed, signs that contact in). notifyMachineUrl($name, $params): the session-free
 *   endpoints (thumbs, cron, Slack), extensionless on the live host (never captured by the clean-link router).
 *   notifyEmail($msg): ONE function every email goes through (client sign-in links included); transports are
 *   functions notifyMailSend_<name>($msg) chosen by config 'mail_transport' (mail = PHP mail(), sink = test
 *   harness). notifySendEmailNow(): an email through the outbox with an immediate delivery attempt (sign-in links).
 *   Config keys are read alias-aware (url-lib.php portalConfigAliases(): portal_url ← portal_base_url, mail_sink_dir
 *   ← mail_capture_dir, notify_from / _from_name / _reply_to / _envelope ← auth_mail_*).
 */

if (!defined('NOTIFY_MAX_ATTEMPTS')) define('NOTIFY_MAX_ATTEMPTS', 6);
if (!defined('NOTIFY_HTTP_TIMEOUT')) define('NOTIFY_HTTP_TIMEOUT', 3);   // seconds, every outbound HTTP call
if (!defined('NOTIFY_THUMB_TTL'))    define('NOTIFY_THUMB_TTL', 30 * 86400);

// =====================================================================================================================
// Config + probes
// =====================================================================================================================

if (!function_exists('notifyConfig')) {
    /** config.php as an array (the global $config db.php loaded, else read once). Never printed anywhere. */
    function notifyConfig(): array {
        if (function_exists('portalConfigArray')) return portalConfigArray();
        static $cfg = null;
        if ($cfg !== null) return $cfg;
        if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) return $cfg = $GLOBALS['config'];
        $file = __DIR__ . '/config.php';
        $c = [];
        if (is_file($file)) {
            try { $c = (static function (string $f) { return include $f; })($file); } catch (Throwable $e) { $c = []; }
        }
        return $cfg = is_array($c) ? $c : [];
    }
}

if (!function_exists('notifyCfg')) {
    /** One config key as a trimmed string ($default when unset / blank). Alias-aware (url-lib.php portalConfigAliases()). */
    function notifyCfg(string $key, string $default = ''): string {
        $v = function_exists('portalConfigPick') ? portalConfigPick(notifyConfig(), $key) : (notifyConfig()[$key] ?? null);
        if ($v === null || !is_scalar($v)) return $default;
        $v = trim((string)$v);
        return $v === '' ? $default : $v;
    }
}

if (!function_exists('notifySlackConfigured')) {
    function notifySlackConfigured(): bool { return notifyCfg('slack_bot_token') !== ''; }
}

if (!function_exists('notifyReady')) {
    /** True once migrate.php 36–39 ran (tables + activity_log columns). Cached per request. */
    function notifyReady(?PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!$pdo) return false;
        try {
            $t = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('admin_users','notify_outbox','notify_clients','notify_threads','slack_inbox')")->fetchColumn();
            return $ready = ($t === 5 && activityHasNotifyCols($pdo));
        } catch (Throwable $e) {
            return $ready = false;
        }
    }
}

if (!function_exists('activityHasNotifyCols')) {
    /** activity_log.author_user_id + internal exist (migrate.php 37). Cached per request. */
    function activityHasNotifyCols(?PDO $pdo): bool {
        static $has = null;
        if ($has !== null) return $has;
        if (!$pdo) return false;
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'activity_log' AND COLUMN_NAME IN ('author_user_id','internal')")->fetchColumn();
            return $has = ($n === 2);
        } catch (Throwable $e) {
            return $has = false;
        }
    }
}

if (!function_exists('notifyMeta')) {
    function notifyMeta(PDO $pdo, string $key, string $default = ''): string {
        try {
            $s = $pdo->prepare("SELECT v FROM meta WHERE k = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('notifyMetaSet')) {
    function notifyMetaSet(PDO $pdo, string $key, string $value): void {
        $pdo->prepare("INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$key, $value]);
    }
}

if (!function_exists('notifySettings')) {
    /** Escalation thresholds (minutes) + Morning summary hour (0–23, America/New_York), with defaults. */
    function notifySettings(PDO $pdo): array {
        $t1 = (int)notifyMeta($pdo, 'notify_t1_minutes', '60');
        $t2 = (int)notifyMeta($pdo, 'notify_t2_minutes', '240');
        $h  = (int)notifyMeta($pdo, 'notify_summary_hour', '8');
        return [
            't1'           => $t1 > 0 ? $t1 : 60,
            't2'           => $t2 > 0 ? $t2 : 240,
            'summary_hour' => ($h >= 0 && $h <= 23) ? $h : 8,
        ];
    }
}

// =====================================================================================================================
// URLs
// =====================================================================================================================

if (!function_exists('notifyBaseUrl')) {
    /** The portal's absolute base ('https://joustmedia.com/portal'): config portal_url (alias portal_base_url), else
     *  this request's host. */
    function notifyBaseUrl(): string {
        $cfg = rtrim(notifyCfg('portal_url'), '/');
        if ($cfg !== '') return $cfg;
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) $host = 'localhost';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443')
              || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return ($https ? 'https' : 'http') . '://' . $host . (function_exists('basePath') ? basePath() : '');
    }
}

if (!function_exists('notifyAbsoluteUrl')) {
    /** Root-rooted ('/portal/posts.php?…') → absolute on the portal's origin. Absolute input passes through. */
    function notifyAbsoluteUrl(string $url): string {
        if (preg_match('#^https?://#i', $url)) return $url;
        // url-lib.php's absolutizer knows the request's folder vs. the configured one (cron / Slack-triggered senders)
        if ($url !== '' && $url[0] === '/' && function_exists('portalAbsoluteUrl') && notifyCfg('portal_url') !== '') return portalAbsoluteUrl($url);
        $base = notifyBaseUrl();
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'localhost') . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($url === '' || $url[0] !== '/') return rtrim($base, '/') . '/' . ltrim($url, '/');
        return $origin . $url;
    }
}

if (!function_exists('notifyPortalUrl')) {
    /** Absolute link to a portal page for an admin-facing message: notifyPortalUrl('posts', ['client' => 'kenda']).
     *  url-lib.php portalUrl() (the one link builder: clean links when they are on) made absolute. */
    function notifyPortalUrl(string $page, array $params = []): string {
        return notifyAbsoluteUrl(portalUrl($page, $params));
    }
}

if (!function_exists('portalItemUrl')) {
    /** Absolute deep link to one item (activityDeepLink() contracts: post sheet, asset viewer, email / page sheet). */
    function portalItemUrl(string $entityType, int $entityId, string $companySlug, array $meta = []): string {
        $rel = function_exists('activityDeepLink')
            ? activityDeepLink(['entity_type' => $entityType, 'entity_id' => $entityId, 'company_slug' => $companySlug, '_meta' => $meta])
            : '/';
        return notifyAbsoluteUrl($rel);
    }
}

if (!function_exists('notifyClientItemPath')) {
    /** An item as a clientLink() path inside its client's portal ('posts/12', 'tires/3?asset=9&kind=tire', …). */
    function notifyClientItemPath(string $entityType, int $entityId, array $meta = []): string {
        switch ($entityType) {
            case 'post':  return 'posts/' . $entityId;
            case 'email': return 'emails/' . $entityId;
            case 'page':  return 'pages/' . $entityId;
            case 'tire_image':
                $tire = (int)($meta['tire_id'] ?? 0);
                if ($tire <= 0) return 'tires';
                $q = ['asset' => $entityId, 'kind' => 'tire'];
                if ((int)($meta['series_id'] ?? 0) > 0) $q = ['series' => (int)$meta['series_id']] + $q;
                return 'tires/' . $tire . '?' . http_build_query($q);
            case 'tire_series':
                $tire = (int)($meta['tire_id'] ?? 0);
                return $tire > 0 ? 'tires/' . $tire . '?' . http_build_query(['series' => $entityId]) : 'tires';
            case 'library_image': return 'assets?' . http_build_query(['asset' => $entityId, 'kind' => 'library']);
        }
        return '';
    }
}

if (!function_exists('notifyItemLinkFor')) {
    /**
     * The link to put in an email about an item, for THIS recipient: a contact of the item's client gets
     * clientLink() (signed — one tap signs them in and lands on the item); anyone else (Joust) gets the plain admin
     * deep link ($info['url']). $info = notifyItemInfo(). Never hands a client an unsigned admin URL.
     */
    function notifyItemLinkFor(PDO $pdo, array $info, string $recipient): string {
        $addr = strtolower(trim((string)preg_replace('/^.*<([^>]+)>\s*$/', '$1', $recipient)));
        $slug = (string)($info['company_slug'] ?? '');
        if ($addr !== '' && $slug !== '' && function_exists('clientLink') && function_exists('clientAuthReady') && clientAuthReady($pdo)) {
            $isContact = false;
            try {
                $st = $pdo->prepare("SELECT 1 FROM client_contacts WHERE company_id = ? AND email = ?");
                $st->execute([(int)($info['company_id'] ?? 0), $addr]);
                $isContact = (bool)$st->fetchColumn();
            } catch (Throwable $e) {
                $isContact = false;
            }
            if ($isContact) {
                $link = clientLink($slug, notifyClientItemPath((string)$info['entity_type'], (int)$info['entity_id'], (array)($info['meta'] ?? [])), $addr);
                // a contact never gets the admin URL: '' (logged by clientLink) rather than an unsigned link
                return $link;
            }
        }
        return (string)($info['url'] ?? '');
    }
}

if (!function_exists('notifyMachineUrl')) {
    /** Absolute URL of a session-free endpoint (notify-thumb, notify-cron, slack-events, slack-actions). Extensionless
     *  by default — the live host 301s '.php' to it and Slack never follows a redirect; config machine_url_ext ('.php')
     *  is for hosts / harnesses without that rewrite. */
    function notifyMachineUrl(string $name, array $params = []): string {
        $ext = notifyCfg('machine_url_ext');
        $ext = ($ext === '.php') ? '.php' : '';
        return rtrim(notifyBaseUrl(), '/') . '/' . $name . $ext . ($params ? '?' . http_build_query($params) : '');
    }
}

// =====================================================================================================================
// Admin users (named authors)
// =====================================================================================================================

if (!function_exists('adminUsers')) {
    /** id → row for every admin user (active and not). Cached per request; adminUsersReset() after edits. */
    function adminUsers(?PDO $pdo): array {
        if (!$pdo || !notifyReady($pdo)) return [];
        if (isset($GLOBALS['__adminUsers']) && is_array($GLOBALS['__adminUsers'])) return $GLOBALS['__adminUsers'];
        $out = [];
        try {
            foreach ($pdo->query("SELECT id, name, email, slack_user_id, role, active FROM admin_users ORDER BY (role = 'owner') DESC, id ASC") as $r) {
                $out[(int)$r['id']] = $r;
            }
        } catch (Throwable $e) {
            $out = [];
        }
        return $GLOBALS['__adminUsers'] = $out;
    }
}

if (!function_exists('adminUsersReset')) {
    function adminUsersReset(): void { unset($GLOBALS['__adminUsers']); }
}

if (!function_exists('adminUserById')) {
    function adminUserById(?PDO $pdo, $id): ?array {
        $id = (int)$id;
        return $id > 0 ? (adminUsers($pdo)[$id] ?? null) : null;
    }
}

if (!function_exists('adminUserBySlack')) {
    /** The ACTIVE admin user mapped to a Slack user id (Slack replies / buttons are attributed to them). */
    function adminUserBySlack(?PDO $pdo, string $slackUserId): ?array {
        $slackUserId = trim($slackUserId);
        if ($slackUserId === '') return null;
        foreach (adminUsers($pdo) as $u) {
            if (!empty($u['active']) && (string)$u['slack_user_id'] === $slackUserId) return $u;
        }
        return null;
    }
}

if (!function_exists('currentAdminUserId')) {
    /** admin_users.id of the signed-in admin (matched on the session email), null for the client seat / unknown. */
    function currentAdminUserId(?PDO $pdo): ?int {
        $email = function_exists('currentAdmin') ? currentAdmin() : null;
        if (!$email || !$pdo) return null;
        foreach (adminUsers($pdo) as $u) {
            if (strcasecmp((string)$u['email'], (string)$email) === 0) return (int)$u['id'];
        }
        return null;
    }
}

if (!function_exists('adminUserFirstName')) {
    function adminUserFirstName(array $u): string {
        $n = trim((string)($u['name'] ?? ''));
        return $n !== '' ? $n : 'Joust';
    }
}

if (!function_exists('notifyOwnerFor')) {
    /** The Joust owner of a client (notify_clients.owner_user_id), else the first active admin (owner role first). */
    function notifyOwnerFor(PDO $pdo, int $companyId): ?array {
        $map = notifyClientRow($pdo, $companyId);
        $u = $map ? adminUserById($pdo, (int)($map['owner_user_id'] ?? 0)) : null;
        if ($u && !empty($u['active'])) return $u;
        foreach (adminUsers($pdo) as $row) { if (!empty($row['active'])) return $row; }
        return null;
    }
}

if (!function_exists('notifyClientRow')) {
    function notifyClientRow(PDO $pdo, int $companyId): ?array {
        if ($companyId <= 0 || !notifyReady($pdo)) return null;
        try {
            $s = $pdo->prepare("SELECT company_id, slack_channel_id, slack_channel_name, owner_user_id FROM notify_clients WHERE company_id = ?");
            $s->execute([$companyId]);
            $r = $s->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('notifyClientChannel')) {
    function notifyClientChannel(PDO $pdo, int $companyId): string {
        $r = notifyClientRow($pdo, $companyId);
        return $r ? trim((string)($r['slack_channel_id'] ?? '')) : '';
    }
}

// =====================================================================================================================
// Activity context, visibility, labels
// =====================================================================================================================

if (!function_exists('activityWithContext')) {
    /** Run $fn with logActivity() writing $ctx: ['author_user_id' => id, 'internal' => 1, 'source' => 'slack']. */
    function activityWithContext(array $ctx, callable $fn) {
        $prev = $GLOBALS['__activityCtx'] ?? [];
        $GLOBALS['__activityCtx'] = $ctx + $prev;
        try {
            return $fn();
        } finally {
            $GLOBALS['__activityCtx'] = $prev;
        }
    }
}

if (!function_exists('activityViewerIsAdmin')) {
    function activityViewerIsAdmin(): bool {
        return function_exists('isAdmin') ? isAdmin() : (function_exists('currentAdmin') && currentAdmin() !== null);
    }
}

if (!function_exists('activityVisibleSql')) {
    /** ' AND <alias>.internal = 0' for the client seat once the column exists ('' for the admin seat). Every reader of
     *  activity_log that can reach a client appends it — internal notes are Joust-only everywhere. */
    function activityVisibleSql(?PDO $pdo, string $alias = '', ?bool $forAdmin = null): string {
        $forAdmin = $forAdmin ?? activityViewerIsAdmin();
        if ($forAdmin || !$pdo || !activityHasNotifyCols($pdo)) return '';
        return ' AND ' . ($alias !== '' ? $alias . '.' : '') . 'internal = 0';
    }
}

if (!function_exists('activityHasContactCol')) {
    /** activity_log.client_contact_id exists (migrate.php 44). Cached per request. */
    function activityHasContactCol(?PDO $pdo): bool {
        static $has = null;
        if ($has !== null) return $has;
        if (!$pdo) return false;
        try {
            return $has = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'client_contact_id'")->fetchColumn() === 1;
        } catch (Throwable $e) {
            return $has = false;
        }
    }
}

if (!function_exists('activityAuthorCols')) {
    /** ', <a>.author_user_id, <a>.internal, <a>.client_contact_id' (or NULL / 0 stand-ins before migrate.php 37 / 44)
     *  for SELECT lists. */
    function activityAuthorCols(?PDO $pdo, string $alias = ''): string {
        $a = $alias !== '' ? $alias . '.' : '';
        return (($pdo && activityHasNotifyCols($pdo))
            ? ", {$a}author_user_id, {$a}internal"
            : ', NULL AS author_user_id, 0 AS internal')
            . (($pdo && activityHasContactCol($pdo)) ? ", {$a}client_contact_id" : ', NULL AS client_contact_id');
    }
}

if (!function_exists('clientContactLabel')) {
    /**
     * Who at the client wrote something, for Joust: "Jane Kenda (Kenda Tires)" — the contact's name, else its email,
     * with the client's name. '' when the id is unknown (removed contact / legacy row): callers fall back to the
     * client's name. Cached per request.
     */
    function clientContactLabel(?PDO $pdo, $contactId, bool $withCompany = true): string {
        $id = (int)$contactId;
        if ($id <= 0 || !$pdo) return '';
        static $cache = [];
        if (!array_key_exists($id, $cache)) {
            $cache[$id] = null;
            try {
                $st = $pdo->prepare("SELECT c.name, c.email, co.name AS company_name FROM client_contacts c
                                       LEFT JOIN companies co ON co.id = c.company_id WHERE c.id = ?");
                $st->execute([$id]);
                $cache[$id] = $st->fetch() ?: null;
            } catch (Throwable $e) {
                $cache[$id] = null;
            }
        }
        $r = $cache[$id];
        if (!$r) return '';
        $who = trim((string)($r['name'] ?? '')) !== '' ? trim((string)$r['name']) : trim((string)$r['email']);
        $co = trim((string)($r['company_name'] ?? ''));
        return ($withCompany && $co !== '') ? $who . ' (' . $co . ')' : $who;
    }
}

if (!function_exists('activityClientLabel')) {
    /** The client contact behind a client row, for the ADMIN seat ("Jane Kenda (Kenda Tires)"), or ''. */
    function activityClientLabel(array $row): string {
        if (($row['actor'] ?? '') !== 'client' || empty($row['client_contact_id'])) return '';
        return clientContactLabel($GLOBALS['pdo'] ?? null, (int)$row['client_contact_id']);
    }
}

if (!function_exists('activityCurrentClientContactId')) {
    /** client_contacts.id to record on a client row of $companyId: the signed-in contact of THAT client (never for the
     *  admin seat — its "reply as client" stays anonymous), else null. */
    function activityCurrentClientContactId(?PDO $pdo, int $companyId): ?int {
        if (!$pdo || !activityHasContactCol($pdo) || !function_exists('currentClientContact')) return null;
        if (function_exists('currentAdmin') && currentAdmin()) return null;
        $c = currentClientContact();
        return ($c && (int)$c['company_id'] === $companyId && (int)$c['id'] > 0) ? (int)$c['id'] : null;
    }
}

if (!function_exists('activityAuthorLabel')) {
    /**
     * Who wrote an admin row, from the viewer's side: 'You' (the viewer wrote it), the teammate's name (admin seat),
     * '<Name> at Joust' (client seat), or '' when the row has no named author (callers fall back to "Joust").
     */
    function activityAuthorLabel(array $row, string $viewer): string {
        $uid = (int)($row['author_user_id'] ?? 0);
        if ($uid <= 0) return '';
        $u = adminUserById($GLOBALS['pdo'] ?? null, $uid);
        if (!$u) return '';
        if ($viewer === 'admin') {
            $me = currentAdminUserId($GLOBALS['pdo'] ?? null);
            return ($me !== null && $me === $uid) ? 'You' : adminUserFirstName($u);
        }
        return adminUserFirstName($u) . ' at Joust';
    }
}

if (!function_exists('notifySlackEscape')) {
    /** Slack mrkdwn control characters (&, <, >) escaped. */
    function notifySlackEscape(string $s): string {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }
}

if (!function_exists('notifyQuote')) {
    /** "> line" per line, capped. */
    function notifyQuote(string $text, int $max = 1200): string {
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > $max) $text = rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…';
        return implode("\n", array_map(static function ($l) { return '>' . ($l === '' ? '' : ' ' . notifySlackEscape($l)); }, preg_split('/\R/u', $text)));
    }
}

// =====================================================================================================================
// Items: what a thread is about
// =====================================================================================================================

if (!function_exists('notifyThreadTypes')) {
    /** Portal items that get a Slack thread (one per item). */
    function notifyThreadTypes(): array {
        return ['post', 'email', 'page', 'tire_image', 'tire_series', 'library_image'];
    }
}

if (!function_exists('notifyStatusPill')) {
    /** status key → [label, emoji] for the Slack parent's status pill. */
    function notifyStatusPill(string $key): array {
        static $map = [
            'draft'     => ['Draft', ':memo:'],
            'pending'   => ['To Review', ':large_yellow_circle:'],
            'approved'  => ['Approved', ':white_check_mark:'],
            'denied'    => ['Needs changes', ':red_circle:'],
            'scheduled' => ['Scheduled', ':calendar:'],
            'live'      => ['Live', ':large_green_circle:'],
            'mixed'     => ['In review', ':large_yellow_circle:'],
            'gone'      => ['Removed', ':wastebasket:'],
        ];
        return $map[$key] ?? ['Updated', ':white_circle:'];
    }
}

if (!function_exists('notifyItemInfo')) {
    /**
     * Everything a notification says about one portal item (never cached — a transition in the same request must show):
     *   exists, company_id, company_name, company_slug, entity_type, entity_id, title, type_label, status_key,
     *   status_label, status_emoji, thumb (portal media URL or ''), url (absolute deep link),
     *   can => [submit, scheduled, live] (the Joust buttons this state allows).
     */
    function notifyItemInfo(PDO $pdo, string $type, int $id): array {
        $info = ['exists' => false, 'entity_type' => $type, 'entity_id' => $id, 'company_id' => 0, 'company_name' => '',
                 'company_slug' => '', 'title' => ucfirst(str_replace('_', ' ', $type)) . ' #' . $id, 'type_label' => '',
                 'status_key' => 'gone', 'thumb' => '', 'url' => '', 'can' => ['submit' => false, 'scheduled' => false, 'live' => false],
                 'meta' => []];
        try {
            switch ($type) {
                case 'post': {
                    $nameSel = function_exists('hasPostsNameColumn') && hasPostsNameColumn($pdo) ? 'name' : "'' AS name";
                    $postedSel = function_exists('hasPostedColumn') && hasPostedColumn($pdo) ? 'posted' : '0 AS posted';
                    $s = $pdo->prepare("SELECT id, company_id, status, caption, {$nameSel}, {$postedSel} FROM posts WHERE id = ?");
                    $s->execute([$id]);
                    $r = $s->fetch();
                    $info['type_label'] = 'Post';
                    if (!$r) break;
                    $info['exists'] = true;
                    $info['company_id'] = (int)$r['company_id'];
                    $info['title'] = postDisplayLabel(['name' => $r['name'] ?? '', 'caption' => $r['caption'] ?? '', 'id' => $id]);
                    $info['status_key'] = !empty($r['posted']) ? 'scheduled' : (string)$r['status'];
                    $info['can']['submit'] = $r['status'] === 'draft';
                    $info['can']['scheduled'] = $r['status'] === 'approved' && empty($r['posted']);
                    $s = $pdo->prepare("SELECT image_url FROM post_images WHERE post_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
                    $s->execute([$id]);
                    $info['thumb'] = (string)($s->fetchColumn() ?: '');
                    break;
                }
                case 'email':
                case 'page': {
                    if ($type === 'page' && !function_exists('pageById') && is_file(__DIR__ . '/pages-lib.php')) require_once __DIR__ . '/pages-lib.php';
                    $r = $type === 'page' ? (function_exists('pageById') ? pageById($pdo, $id) : null) : (function_exists('emailById') ? emailById($pdo, $id) : null);
                    $info['type_label'] = $type === 'page' ? 'Page' : 'Email';
                    if (!$r) break;
                    $info['exists'] = true;
                    $info['company_id'] = (int)$r['company_id'];
                    $info['title'] = $type === 'page' ? pageDisplayLabel($r) : emailDisplayLabel($r);
                    $info['status_key'] = !empty($r['live']) ? 'live' : (string)$r['status'];
                    $info['can']['submit'] = $r['status'] === 'draft' && empty($r['live']);
                    $info['can']['live'] = $r['status'] === 'approved' && empty($r['live']);
                    $info['row'] = $r;
                    break;
                }
                case 'tire_image': {
                    $seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);
                    $hasDn = false;
                    try { $hasDn = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0; } catch (Throwable $e) {}
                    $s = $pdo->prepare("SELECT ti.id, ti.tire_id, ti.status, ti.image_url, ti.caption, " . ($hasDn ? 'ti.display_name' : "'' AS display_name") . ", "
                        . ($seriesOn ? 'ti.series_id' : 'NULL AS series_id') . ", t.name AS tire_name, t.company_id
                          FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.id = ?");
                    $s->execute([$id]);
                    $r = $s->fetch();
                    $info['type_label'] = 'Tire image';
                    if (!$r) break;
                    $info['exists'] = true;
                    $info['company_id'] = (int)$r['company_id'];
                    $prefix = (string)$r['tire_name'];
                    if ($seriesOn && !empty($r['series_id'])) {
                        $sr = tireSeriesById($pdo, (int)$r['series_id']);
                        if ($sr) $prefix .= ' · ' . (string)$sr['name'];
                    }
                    $name = imageDisplayLabel(['display_name' => $r['display_name'] ?? '', 'caption' => $r['caption'] ?? '', 'id' => $id]);
                    $info['title'] = trim($prefix) !== '' ? trim($prefix) . ' · ' . $name : $name;
                    $info['status_key'] = (string)$r['status'];
                    $info['thumb'] = function_exists('tireImageSrc') ? tireImageSrc($r) : (string)$r['image_url'];
                    $info['meta'] = ['tire_id' => (int)$r['tire_id'], 'series_id' => (int)($r['series_id'] ?? 0)];
                    break;
                }
                case 'tire_series': {
                    $sr = function_exists('tireSeriesById') ? tireSeriesById($pdo, $id) : null;
                    $info['type_label'] = 'Tire series';
                    if (!$sr) break;
                    $s = $pdo->prepare("SELECT id, name, company_id FROM tires WHERE id = ?");
                    $s->execute([(int)$sr['tire_id']]);
                    $t = $s->fetch();
                    if (!$t) break;
                    $info['exists'] = true;
                    $info['company_id'] = (int)$t['company_id'];
                    $info['title'] = (string)$t['name'] . ' · ' . (string)$sr['name'];
                    $c = tireSeriesCounts($pdo, (int)$t['id'])['series'][$id] ?? ['pending' => 0, 'approved' => 0, 'denied' => 0, 'total' => 0];
                    $info['status_key'] = (int)$c['pending'] > 0 ? ((int)$c['approved'] + (int)$c['denied'] > 0 ? 'mixed' : 'pending')
                                        : ((int)$c['denied'] > 0 ? 'denied' : ((int)$c['total'] > 0 ? 'approved' : 'pending'));
                    $info['meta'] = ['tire_id' => (int)$t['id']];
                    break;
                }
                case 'library_image': {
                    $s = $pdo->prepare("SELECT l.id, l.company_id, l.filename, l.status, c.slug FROM library_images l INNER JOIN companies c ON c.id = l.company_id WHERE l.id = ?");
                    $s->execute([$id]);
                    $r = $s->fetch();
                    $info['type_label'] = 'Library image';
                    if (!$r) break;
                    $info['exists'] = true;
                    $info['company_id'] = (int)$r['company_id'];
                    $info['title'] = 'Image in Library #' . $id;   // never the on-disk filename
                    $info['status_key'] = (string)$r['status'];
                    $info['thumb'] = libraryFileUrl((string)$r['slug'], (string)$r['filename']);
                    break;
                }
            }
            if ($info['company_id'] > 0) {
                $s = $pdo->prepare("SELECT name, slug FROM companies WHERE id = ?");
                $s->execute([$info['company_id']]);
                $c = $s->fetch();
                if ($c) { $info['company_name'] = (string)$c['name']; $info['company_slug'] = (string)$c['slug']; }
            }
        } catch (Throwable $e) {
            error_log('notifyItemInfo ' . $type . '#' . $id . ': ' . $e->getMessage());
        }
        [$info['status_label'], $info['status_emoji']] = notifyStatusPill($info['status_key']);
        if ($info['company_slug'] !== '') {
            $info['url'] = portalItemUrl($type, $id, $info['company_slug'], $info['meta']);
        }
        if (function_exists('mediaTypeFromUrl') && $info['thumb'] !== '' && mediaTypeFromUrl($info['thumb']) === 'video') $info['thumb'] = '';
        return $info;
    }
}

// =====================================================================================================================
// "Answered": unanswered client messages (Home Latest notes, escalation, the Slack parent's waiting line)
// =====================================================================================================================

if (!function_exists('notifyAnswerActions')) {
    /** Admin actions that answer a client message on the same item (internal notes do NOT answer). */
    function notifyAnswerActions(): array {
        return ['commented', 'approved', 'denied', 'reset_pending', 'submitted', 'moved_to_draft', 'posted',
                'marked_live', 'resolved'];
    }
}

if (!function_exists('notifyUnanswered')) {
    /**
     * Items whose newest client comments have no Joust answer after them (an admin comment that is not internal, a
     * decision / status move, or a Slack "Resolve"). One row per item, oldest wait first:
     *   company_id, entity_type, entity_id, n (unanswered client comments), first_id / first_at (the oldest of them),
     *   last_id / last_at / last_detail (the newest).
     * $companyId null = every client; $since = only client comments at / after it ('Y-m-d H:i:s').
     */
    function notifyUnanswered(PDO $pdo, ?int $companyId, string $since, int $limit = 200, ?array $entity = null): array {
        $answers = "'" . implode("','", notifyAnswerActions()) . "'";
        $internalOk = activityHasNotifyCols($pdo) ? " AND (a.action <> 'commented' OR a.internal = 0)" : '';
        $sql = "
            SELECT c.company_id, c.entity_type, c.entity_id, COUNT(*) AS n,
                   MIN(c.id) AS first_id, MIN(c.created_at) AS first_at, MAX(c.id) AS last_id, MAX(c.created_at) AS last_at
              FROM activity_log c
             WHERE c.actor = 'client' AND c.action = 'commented' AND c.detail IS NOT NULL AND c.detail <> ''
               AND c.created_at >= ? " . ($companyId ? ' AND c.company_id = ? ' : '') . ($entity ? ' AND c.entity_type = ? AND c.entity_id = ? ' : '') . "
               AND c.entity_type IN ('" . implode("','", notifyThreadTypes()) . "')
               AND NOT EXISTS (
                   SELECT 1 FROM activity_log a
                    WHERE a.entity_type = c.entity_type AND a.entity_id = c.entity_id AND a.id > c.id
                      AND a.actor = 'admin' AND a.action IN ({$answers}){$internalOk}
               )
             GROUP BY c.company_id, c.entity_type, c.entity_id
             ORDER BY first_at ASC
             LIMIT " . max(1, (int)$limit);
        $p = [$since];
        if ($companyId) $p[] = $companyId;
        if ($entity) { $p[] = (string)$entity[0]; $p[] = (int)$entity[1]; }
        try {
            $s = $pdo->prepare($sql);
            $s->execute($p);
            $rows = $s->fetchAll();
            if (!$rows) return [];
            $ids = array_map(static function ($r) { return (int)$r['last_id']; }, $rows);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $d = $pdo->prepare("SELECT id, detail FROM activity_log WHERE id IN ($ph)");
            $d->execute($ids);
            $details = $d->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as &$r) {
                foreach (['company_id', 'entity_id', 'n', 'first_id', 'last_id'] as $k) $r[$k] = (int)$r[$k];
                $r['last_detail'] = (string)($details[$r['last_id']] ?? '');
            }
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            error_log('notifyUnanswered: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('notifyItemWaiting')) {
    /** The unanswered-client-message summary for one item (notifyUnanswered row) or null. */
    function notifyItemWaiting(PDO $pdo, string $type, int $id, int $companyId): ?array {
        $rows = notifyUnanswered($pdo, $companyId > 0 ? $companyId : null, '1970-01-02 00:00:00', 1, [$type, $id]);
        return $rows[0] ?? null;
    }
}

// =====================================================================================================================
// Outbox
// =====================================================================================================================

if (!function_exists('notifyEnqueue')) {
    /**
     * Queue one outbound message; returns the outbox id (an existing one when dedupe_key matches — enqueueing is
     * idempotent). $o: dedupe (string), company_id, entity_type, entity_id, target, defer (bool: do not deliver at
     * the end of this request — the caller pumps), merge_ids (int[]: append these to payload.activity_ids of a still
     * pending row with the same dedupe key — one Slack post per request batch).
     */
    function notifyEnqueue(PDO $pdo, string $channel, string $kind, array $payload, array $o = []): int {
        if (!notifyReady($pdo)) return 0;
        $dedupe = isset($o['dedupe']) ? substr((string)$o['dedupe'], 0, 120) : null;
        try {
            if ($dedupe !== null && !empty($o['merge_ids'])) {
                $s = $pdo->prepare("SELECT id, status, payload FROM notify_outbox WHERE dedupe_key = ?");
                $s->execute([$dedupe]);
                $have = $s->fetch();
                if ($have && $have['status'] === 'pending') {
                    $p = json_decode((string)$have['payload'], true) ?: [];
                    $p['activity_ids'] = array_values(array_unique(array_merge((array)($p['activity_ids'] ?? []), array_map('intval', (array)$o['merge_ids']))));
                    $pdo->prepare("UPDATE notify_outbox SET payload = ? WHERE id = ?")->execute([json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int)$have['id']]);
                    if (empty($o['defer'])) notifyScheduleFlush((int)$have['id']);
                    return (int)$have['id'];
                }
                if ($have) $dedupe .= '#' . (int)max($o['merge_ids']);   // already sent: a new message for the late rows
                $payload['activity_ids'] = array_values(array_unique(array_merge((array)($payload['activity_ids'] ?? []), array_map('intval', (array)$o['merge_ids']))));
            }
            $st = $pdo->prepare("
                INSERT INTO notify_outbox (channel, kind, company_id, entity_type, entity_id, target, payload, dedupe_key, next_attempt_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
            ");
            $st->execute([
                $channel === 'email' ? 'email' : 'slack', substr($kind, 0, 30),
                isset($o['company_id']) ? (int)$o['company_id'] : null,
                isset($o['entity_type']) ? substr((string)$o['entity_type'], 0, 20) : null,
                isset($o['entity_id']) ? (int)$o['entity_id'] : null,
                isset($o['target']) ? substr((string)$o['target'], 0, 190) : null,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $dedupe,
            ]);
            $id = (int)$pdo->lastInsertId();
            if ($id > 0 && empty($o['defer'])) notifyScheduleFlush($id);
            return $id;
        } catch (Throwable $e) {
            error_log('notifyEnqueue ' . $kind . ': ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('notifyScheduleFlush')) {
    /** Deliver this outbox row after the response has gone out (one shutdown hook per request). */
    function notifyScheduleFlush(int $id): void {
        if ($id <= 0) return;
        if (!isset($GLOBALS['__notifyFlush'])) {
            $GLOBALS['__notifyFlush'] = [];
            register_shutdown_function('notifyAfterResponse');
        }
        $GLOBALS['__notifyFlush'][$id] = true;
    }
}

if (!function_exists('notifyFinishResponse')) {
    /** Hand the response to the client now and keep running: PHP-FPM / LiteSpeed finish calls, else flush. */
    function notifyFinishResponse(): void {
        @ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); return; }
        if (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); return; }
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }
}

if (!function_exists('notifyRespondEarly')) {
    /** Send a complete JSON response (Content-Length + Connection: close) and keep running — Slack's 3 s ack. */
    function notifyRespondEarly(int $code, array $json): void {
        $body = json_encode($json, JSON_UNESCAPED_SLASHES);
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            header('Content-Length: ' . strlen($body));
            header('Connection: close');
        }
        echo $body;
        notifyFinishResponse();
    }
}

if (!function_exists('notifyAfterResponse')) {
    /** Shutdown hook: finish the response, then deliver what this request queued (bounded: 6 rows, ~8 s). */
    function notifyAfterResponse(): void {
        $ids = array_keys($GLOBALS['__notifyFlush'] ?? []);
        $GLOBALS['__notifyFlush'] = [];
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$ids || !$pdo instanceof PDO) return;
        try {
            if ($pdo->inTransaction()) return;   // never deliver from inside an unfinished transaction (the cron will)
            notifyFinishResponse();
            @set_time_limit(30);
            notifyPump($pdo, ['ids' => $ids, 'limit' => 6, 'budget' => 8.0]);
        } catch (Throwable $e) {
            error_log('notifyAfterResponse: ' . $e->getMessage());
        }
    }
}

if (!function_exists('notifyBackoff')) {
    /** Seconds before attempt n+1 (n = attempts so far): 1 min, 5 min, 15 min, 1 h, 3 h. */
    function notifyBackoff(int $attempts): int {
        $steps = [1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 10800];
        return $steps[max(1, min(5, $attempts))];
    }
}

if (!function_exists('notifyPump')) {
    /**
     * Deliver due outbox rows. $o: ids (only these), limit (rows, default 50), budget (seconds, default 20).
     * Each row is claimed first (status sending + a 2-minute lease), so two pumps never send the same row.
     * Returns ['sent' => n, 'failed' => n, 'retry' => n, 'skipped' => n].
     */
    function notifyPump(PDO $pdo, array $o = []): array {
        $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0];
        if (!notifyReady($pdo)) return $stats;
        $limit = max(1, (int)($o['limit'] ?? 50));
        $budget = (float)($o['budget'] ?? 20.0);
        $t0 = microtime(true);
        try {
            if (!empty($o['ids'])) {
                $ids = array_values(array_unique(array_map('intval', (array)$o['ids'])));
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT * FROM notify_outbox WHERE id IN ($ph) AND status = 'pending' AND next_attempt_at <= NOW() ORDER BY id ASC LIMIT {$limit}");
                $s->execute($ids);
            } else {
                $s = $pdo->query("SELECT * FROM notify_outbox WHERE status = 'pending' AND next_attempt_at <= NOW() ORDER BY next_attempt_at ASC, id ASC LIMIT {$limit}");
            }
            $rows = $s->fetchAll();
        } catch (Throwable $e) {
            error_log('notifyPump: ' . $e->getMessage());
            return $stats;
        }
        $claim = $pdo->prepare("UPDATE notify_outbox SET status = 'sending', attempts = attempts + 1, next_attempt_at = NOW() + INTERVAL 2 MINUTE
                                 WHERE id = ? AND status = 'pending'");
        foreach ($rows as $row) {
            if (microtime(true) - $t0 > $budget) break;
            $claim->execute([(int)$row['id']]);
            if ($claim->rowCount() !== 1) continue;
            $row['attempts'] = (int)$row['attempts'] + 1;
            try {
                $res = notifyDeliver($pdo, $row);
            } catch (Throwable $e) {
                error_log('notifyDeliver #' . $row['id'] . ': ' . $e->getMessage());
                $res = ['ok' => false, 'error' => 'internal error: ' . get_class($e)];
            }
            $stats[notifyFinish($pdo, $row, $res)]++;
        }
        return $stats;
    }
}

if (!function_exists('notifyFinish')) {
    /** Record a delivery result → 'sent' | 'skipped' | 'retry' | 'failed'. */
    function notifyFinish(PDO $pdo, array $row, array $res): string {
        $out = notifyFinishRow($pdo, $row, $res);
        // A ready-made email (sign-in link) keeps its bodies only while it may still be sent.
        if ($out !== 'retry' && in_array((string)$row['kind'], ['sign_in', 'email'], true)) {
            $p = json_decode((string)$row['payload'], true) ?: [];
            $keep = ['redacted' => 1, 'to' => (string)($p['to'] ?? ''), 'subject' => (string)($p['subject'] ?? ''), 'kind' => (string)($p['kind'] ?? '')];
            try {
                $pdo->prepare("UPDATE notify_outbox SET payload = ? WHERE id = ?")->execute([json_encode($keep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int)$row['id']]);
            } catch (Throwable $e) {
                error_log('notifyFinish scrub: ' . $e->getMessage());
            }
        }
        return $out;
    }
}

if (!function_exists('notifyFinishRow')) {
    function notifyFinishRow(PDO $pdo, array $row, array $res): string {
        $id = (int)$row['id'];
        $err = substr((string)($res['error'] ?? ''), 0, 500);
        if (!empty($res['ok'])) {
            $pdo->prepare("UPDATE notify_outbox SET status = 'sent', sent_at = NOW(), last_error = ?, provider_id = ? WHERE id = ?")
                ->execute([($res['note'] ?? '') !== '' ? substr((string)$res['note'], 0, 500) : null, isset($res['provider_id']) ? substr((string)$res['provider_id'], 0, 190) : null, $id]);
            return 'sent';
        }
        if (!empty($res['skip'])) {
            $pdo->prepare("UPDATE notify_outbox SET status = 'skipped', last_error = ? WHERE id = ?")->execute([$err, $id]);
            return 'skipped';
        }
        $attempts = (int)$row['attempts'];
        if (!empty($res['permanent']) || $attempts >= NOTIFY_MAX_ATTEMPTS) {
            $pdo->prepare("UPDATE notify_outbox SET status = 'failed', last_error = ? WHERE id = ?")->execute([$err, $id]);
            return 'failed';
        }
        $wait = isset($res['retry_in']) ? max(5, (int)$res['retry_in']) : notifyBackoff($attempts);
        $pdo->prepare("UPDATE notify_outbox SET status = 'pending', last_error = ?, next_attempt_at = NOW() + INTERVAL ? SECOND WHERE id = ?")
            ->execute([$err, $wait, $id]);
        return 'retry';
    }
}

if (!function_exists('notifyReclaimStale')) {
    /** Rows left in 'sending' past their lease (a crashed request) go back to the queue. */
    function notifyReclaimStale(PDO $pdo): int {
        try {
            $s = $pdo->prepare("UPDATE notify_outbox SET status = 'pending' WHERE status = 'sending' AND next_attempt_at < NOW()");
            $s->execute();
            return $s->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('notifyRetry')) {
    /** Manage → Retry: a failed / pending / skipped row goes back to the queue now (attempts reset). */
    function notifyRetry(PDO $pdo, ?int $id = null): array {
        $sql = "UPDATE notify_outbox SET status = 'pending', attempts = 0, next_attempt_at = NOW() WHERE ";
        if ($id) {
            $s = $pdo->prepare($sql . "id = ? AND status IN ('failed','pending','skipped')");
            $s->execute([$id]);
            return $s->rowCount() ? [$id] : [];
        }
        $ids = $pdo->query("SELECT id FROM notify_outbox WHERE status = 'failed' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) $pdo->exec($sql . "status = 'failed'");
        return array_map('intval', $ids);
    }
}

// =====================================================================================================================
// Event routing (called by logActivity)
// =====================================================================================================================

if (!function_exists('notifyClientEventActions')) {
    /** Client actions that ping Slack immediately. */
    function notifyClientEventActions(): array {
        return ['commented', 'approved', 'denied', 'edited_caption', 'edited_hashtags'];
    }
}

if (!function_exists('notifyParentActions')) {
    /** Actions (any actor) after which an existing Slack parent message is re-rendered (status pill / waiting line). */
    function notifyParentActions(): array {
        return ['approved', 'denied', 'reset_pending', 'submitted', 'moved_to_draft', 'posted', 'unposted', 'marked_live',
                'unmarked_live', 'commented', 'resolved', 'deleted', 'renamed_post', 'renamed', 'renamed_image'];
    }
}

if (!function_exists('notifyOnActivity')) {
    /** logActivity() hook: decide what (if anything) goes out. $row: company_id, entity_type, entity_id, action, actor,
     *  batch_id, detail, internal. Never throws. */
    function notifyOnActivity(PDO $pdo, int $activityId, array $row): void {
        try {
            if ($activityId <= 0 || !notifyReady($pdo)) return;
            // Client emails (client-notify-lib.php): items sent for review, visible Joust replies, live / scheduled
            if (function_exists('clientEmailOnActivity')) clientEmailOnActivity($pdo, $activityId, $row);
            $type = (string)$row['entity_type'];
            if (!in_array($type, notifyThreadTypes(), true)) return;
            $action = (string)$row['action'];
            $cid = (int)$row['company_id'];
            $eid = (int)$row['entity_id'];
            $batchKey = ($row['batch_id'] ?? '') !== '' && $row['batch_id'] !== null ? 'b' . $row['batch_id'] : 'a' . $activityId;
            $isClientEvent = ($row['actor'] === 'client') && empty($row['internal']) && in_array($action, notifyClientEventActions(), true)
                && !($action === 'commented' && trim((string)($row['detail'] ?? '')) === '');
            if ($isClientEvent) {
                if (!notifySlackConfigured() || notifyClientChannel($pdo, $cid) === '') return;
                notifyEnqueue($pdo, 'slack', 'item_event',
                    ['entity_type' => $type, 'entity_id' => $eid, 'company_id' => $cid, 'activity_ids' => [$activityId]],
                    ['dedupe' => 'evt:' . $batchKey, 'merge_ids' => [$activityId], 'company_id' => $cid, 'entity_type' => $type, 'entity_id' => $eid]);
                return;
            }
            if (!in_array($action, notifyParentActions(), true) || !notifySlackConfigured()) return;
            $t = notifyThreadRow($pdo, $type, $eid);
            if (!$t || (string)$t['slack_ts'] === '') return;   // Joust's own actions never start a thread
            notifyEnqueue($pdo, 'slack', 'parent_update', ['entity_type' => $type, 'entity_id' => $eid, 'company_id' => $cid],
                ['dedupe' => 'upd:' . $batchKey, 'company_id' => $cid, 'entity_type' => $type, 'entity_id' => $eid]);
        } catch (Throwable $e) {
            error_log('notifyOnActivity: ' . $e->getMessage());
        }
    }
}

// =====================================================================================================================
// Slack API
// =====================================================================================================================

if (!function_exists('slackApi')) {
    /**
     * One Slack Web API call with the bot token (config slack_bot_token; base config slack_api_base, default
     * https://slack.com/api). JSON body by default, form-encoded with $o['form'] (read methods such as
     * users.lookupByEmail / conversations.list). 2 s connect / 3 s total. Returns
     * ['ok', 'error', 'data' (decoded reply), 'http', 'retry_after' (s, on 429), 'permanent' (no point retrying)].
     * The token is never logged or returned.
     */
    function slackApi(string $method, array $params, array $o = []): array {
        $token = notifyCfg('slack_bot_token');
        if ($token === '') return ['ok' => false, 'error' => 'Slack is not configured (slack_bot_token)', 'permanent' => true, 'data' => []];
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'PHP curl extension missing', 'permanent' => true, 'data' => []];
        $url = rtrim(notifyCfg('slack_api_base', 'https://slack.com/api'), '/') . '/' . rawurlencode($method);
        return notifyHttpPost($url, $params, ['Authorization: Bearer ' . $token], !empty($o['form']));
    }
}

if (!function_exists('notifyHttpPost')) {
    /** POST JSON (or a form) with short timeouts; Slack-style reply decoding. */
    function notifyHttpPost(string $url, array $params, array $headers = [], bool $form = false): array {
        $hdrs = [];
        $ch = curl_init();
        $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json; charset=utf-8';
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $form ? http_build_query($params) : json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => NOTIFY_HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'JoustPortal-Notify/1',
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$hdrs) {
                $p = strpos($line, ':');
                if ($p !== false) $hdrs[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $body === false ? curl_error($ch) : '';
        curl_close($ch);
        if ($body === false) return ['ok' => false, 'error' => 'network: ' . $err, 'http' => 0, 'data' => []];
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            // response_url replies are a bare "ok"
            $ok = $code >= 200 && $code < 300 && trim((string)$body) === 'ok';
            return ['ok' => $ok, 'error' => $ok ? '' : 'HTTP ' . $code, 'http' => $code, 'data' => [],
                    'retry_after' => isset($hdrs['retry-after']) ? (int)$hdrs['retry-after'] : null];
        }
        $ok = !empty($json['ok']) && $code < 400;
        $error = $ok ? '' : (string)($json['error'] ?? ('HTTP ' . $code));
        static $permanent = ['channel_not_found', 'not_in_channel', 'invalid_auth', 'not_authed', 'account_inactive',
                             'is_archived', 'msg_too_long', 'invalid_blocks', 'missing_scope', 'token_revoked', 'user_not_found',
                             'cant_update_message', 'message_not_found', 'invalid_arguments', 'no_permission'];
        return ['ok' => $ok, 'error' => $error, 'http' => $code, 'data' => $json,
                'retry_after' => $code === 429 ? (int)($hdrs['retry-after'] ?? 30) : null,
                'permanent' => !$ok && in_array($error, $permanent, true)];
    }
}

if (!function_exists('notifySlackResult')) {
    /** slackApi() reply → a delivery result for notifyFinish(). */
    function notifySlackResult(array $r, string $what): array {
        if (!empty($r['ok'])) return ['ok' => true, 'provider_id' => (string)($r['data']['ts'] ?? '')];
        $out = ['ok' => false, 'error' => $what . ': ' . (string)$r['error']];
        if (!empty($r['permanent'])) $out['permanent'] = true;
        if (!empty($r['retry_after'])) $out['retry_in'] = (int)$r['retry_after'];
        return $out;
    }
}

// =====================================================================================================================
// Signed thumbnails (notify-thumb.php) — session-free, expiring, JPEG (Slack does not render WebP)
// =====================================================================================================================

if (!function_exists('notifyThumbKey')) {
    function notifyThumbKey(): string {
        return hash('sha256', 'joust-notify-thumb|' . (function_exists('previewSecret') ? previewSecret() : __DIR__), true);
    }
}

if (!function_exists('notifyThumbToken')) {
    /** base64url("<ref>|<expires>") . '.' . 22 chars of the HMAC. */
    function notifyThumbToken(string $ref, int $expires): string {
        $payload = $ref . '|' . $expires;
        $b64 = static function (string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); };
        return $b64($payload) . '.' . substr($b64(hash_hmac('sha256', $payload, notifyThumbKey(), true)), 0, 22);
    }
}

if (!function_exists('notifyThumbTokenRef')) {
    /** The ref of a valid, unexpired token; null otherwise ($why: bad | forged | expired). */
    function notifyThumbTokenRef(string $token, ?string &$why = null): ?string {
        $why = 'bad';
        if ($token === '' || strlen($token) > 2048 || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{22})$/', $token, $m)) return null;
        $payload = base64_decode(strtr($m[1], '-_', '+/'), true);
        if (!is_string($payload) || !preg_match('/^(.+)\|(\d{9,11})$/s', $payload, $pm)) return null;
        $want = substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, notifyThumbKey(), true)), '+/', '-_'), '='), 0, 22);
        if (!hash_equals($want, $m[2])) { $why = 'forged'; return null; }
        if ((int)$pm[2] < time()) { $why = 'expired'; return null; }
        $why = '';
        return $pm[1];
    }
}

if (!function_exists('notifyThumbUrl')) {
    /** Absolute signed thumbnail URL for a portal media URL ('' for anything that is not one of ours / an image). */
    function notifyThumbUrl(string $mediaUrl, int $ttl = NOTIFY_THUMB_TTL): string {
        if ($mediaUrl === '' || !function_exists('previewCanonicalRef')) return '';
        $ref = previewCanonicalRef($mediaUrl);
        if ($ref === null || !preg_match('/\.(jpe?g|png|gif|webp)$/i', $ref)) return '';
        // expiry rounded to the day so a re-render of the same parent message keeps the same URL (and hash)
        $exp = (int)(ceil((time() + $ttl) / 86400) * 86400);
        return notifyMachineUrl('notify-thumb', ['t' => notifyThumbToken($ref, $exp)]);
    }
}

// =====================================================================================================================
// Slack: threads, parent message (Block Kit), item events, escalation
// =====================================================================================================================

if (!function_exists('notifyThreadRow')) {
    function notifyThreadRow(PDO $pdo, string $type, int $id): ?array {
        try {
            $s = $pdo->prepare("SELECT * FROM notify_threads WHERE entity_type = ? AND entity_id = ?");
            $s->execute([$type, $id]);
            $r = $s->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('notifyThreadBySlack')) {
    function notifyThreadBySlack(PDO $pdo, string $channel, string $ts): ?array {
        if ($channel === '' || $ts === '') return null;
        $s = $pdo->prepare("SELECT * FROM notify_threads WHERE slack_channel = ? AND slack_ts = ?");
        $s->execute([$channel, $ts]);
        $r = $s->fetch();
        return $r ?: null;
    }
}

if (!function_exists('notifySlackParent')) {
    /** The parent message for an item: ['text' => fallback, 'blocks' => Block Kit]. */
    function notifySlackParent(PDO $pdo, array $info): array {
        $waiting = $info['exists'] ? notifyItemWaiting($pdo, $info['entity_type'], (int)$info['entity_id'], (int)$info['company_id']) : null;
        $title = notifySlackEscape((string)$info['title']);
        $head = $info['url'] !== '' ? '*<' . $info['url'] . '|' . $title . '>*' : '*' . $title . '*';
        $section = ['type' => 'section', 'text' => ['type' => 'mrkdwn',
            'text' => $head . "\n" . notifySlackEscape($info['company_name']) . ' · ' . notifySlackEscape((string)$info['type_label'])]];
        $thumb = $info['exists'] && $info['thumb'] !== '' ? notifyThumbUrl((string)$info['thumb']) : '';
        if ($thumb !== '') $section['accessory'] = ['type' => 'image', 'image_url' => $thumb, 'alt_text' => mb_substr((string)$info['title'], 0, 200)];
        $ctx = [['type' => 'mrkdwn', 'text' => $info['status_emoji'] . ' *' . $info['status_label'] . '*']];
        if ($waiting) {
            $ctx[] = ['type' => 'mrkdwn', 'text' => ':hourglass_flowing_sand: Waiting on Joust since <!date^' . (int)strtotime((string)$waiting['first_at'])
                . '^{date_short_pretty} {time}|' . date('M j g:ia', (int)strtotime((string)$waiting['first_at'])) . '>'
                . ($waiting['n'] > 1 ? ' · ' . $waiting['n'] . ' messages' : '')];
        } elseif ($info['exists']) {
            $ctx[] = ['type' => 'mrkdwn', 'text' => ':speech_balloon: No open questions'];
        }
        $blocks = [$section, ['type' => 'context', 'elements' => $ctx]];
        $value = $info['entity_type'] . ':' . (int)$info['entity_id'];
        $buttons = [];
        if ($info['url'] !== '') $buttons[] = ['type' => 'button', 'action_id' => 'open', 'text' => ['type' => 'plain_text', 'text' => 'Open in portal'], 'url' => $info['url'], 'value' => $value];
        if ($info['exists']) {
            if ($waiting) $buttons[] = ['type' => 'button', 'action_id' => 'resolve', 'style' => 'primary', 'text' => ['type' => 'plain_text', 'text' => 'Resolve'], 'value' => $value];
            if (!empty($info['can']['submit']))    $buttons[] = ['type' => 'button', 'action_id' => 'submit', 'text' => ['type' => 'plain_text', 'text' => 'Send for review'], 'value' => $value];
            if (!empty($info['can']['scheduled'])) $buttons[] = ['type' => 'button', 'action_id' => 'scheduled', 'text' => ['type' => 'plain_text', 'text' => 'Mark Scheduled'], 'value' => $value];
            if (!empty($info['can']['live']))      $buttons[] = ['type' => 'button', 'action_id' => 'live', 'text' => ['type' => 'plain_text', 'text' => 'Mark Live'], 'value' => $value];
        }
        if ($buttons) $blocks[] = ['type' => 'actions', 'block_id' => 'portal_item', 'elements' => $buttons];
        $text = $info['company_name'] . ': ' . $info['title'] . ' — ' . $info['status_label'];
        return ['text' => $text, 'blocks' => $blocks];
    }
}

if (!function_exists('notifySlackEnsureThread')) {
    /** The item's Slack thread, creating the parent message on first use. → ['ok', 'channel', 'ts', 'created'] or an error
     *  result (a concurrent creator holds the claim → retry in 20 s). */
    function notifySlackEnsureThread(PDO $pdo, array $info, string $channel): array {
        $type = $info['entity_type']; $id = (int)$info['entity_id'];
        $t = notifyThreadRow($pdo, $type, $id);
        if ($t && (string)$t['slack_ts'] !== '') return ['ok' => true, 'channel' => (string)$t['slack_channel'], 'ts' => (string)$t['slack_ts'], 'created' => false];
        if (!$t) {
            $pdo->prepare("INSERT IGNORE INTO notify_threads (company_id, entity_type, entity_id) VALUES (?, ?, ?)")->execute([(int)$info['company_id'], $type, $id]);
        }
        $claim = $pdo->prepare("UPDATE notify_threads SET slack_claimed_at = NOW() WHERE entity_type = ? AND entity_id = ? AND slack_ts IS NULL
                                AND (slack_claimed_at IS NULL OR slack_claimed_at < NOW() - INTERVAL 1 MINUTE)");
        $claim->execute([$type, $id]);
        if ($claim->rowCount() !== 1) return ['ok' => false, 'error' => 'thread is being created', 'retry_in' => 20];
        $msg = notifySlackParent($pdo, $info);
        $r = slackApi('chat.postMessage', ['channel' => $channel, 'text' => $msg['text'], 'blocks' => $msg['blocks'], 'unfurl_links' => false, 'unfurl_media' => false]);
        if (empty($r['ok'])) {
            $pdo->prepare("UPDATE notify_threads SET slack_claimed_at = NULL WHERE entity_type = ? AND entity_id = ?")->execute([$type, $id]);
            return notifySlackResult($r, 'parent');
        }
        $ch = (string)($r['data']['channel'] ?? $channel);
        $ts = (string)($r['data']['ts'] ?? '');
        $pdo->prepare("UPDATE notify_threads SET slack_channel = ?, slack_ts = ?, parent_hash = ?, slack_claimed_at = NULL WHERE entity_type = ? AND entity_id = ?")
            ->execute([$ch, $ts, sha1(json_encode($msg)), $type, $id]);
        return ['ok' => true, 'channel' => $ch, 'ts' => $ts, 'created' => true];
    }
}

if (!function_exists('notifySlackUpdateParent')) {
    /** Re-render the parent (chat.update) when its content changed. Skips items without a thread. */
    function notifySlackUpdateParent(PDO $pdo, string $type, int $id, bool $force = false): array {
        $t = notifyThreadRow($pdo, $type, $id);
        if (!$t || (string)$t['slack_ts'] === '') return ['ok' => false, 'skip' => true, 'error' => 'no Slack thread for this item'];
        $info = notifyItemInfo($pdo, $type, $id);
        if (!$info['exists']) $info['company_name'] = $info['company_name'] ?: '';
        $msg = notifySlackParent($pdo, $info);
        $hash = sha1(json_encode($msg));
        if (!$force && $hash === (string)$t['parent_hash']) return ['ok' => true, 'note' => 'unchanged'];
        $r = slackApi('chat.update', ['channel' => (string)$t['slack_channel'], 'ts' => (string)$t['slack_ts'], 'text' => $msg['text'], 'blocks' => $msg['blocks']]);
        if (empty($r['ok'])) return notifySlackResult($r, 'chat.update');
        $pdo->prepare("UPDATE notify_threads SET parent_hash = ? WHERE id = ?")->execute([$hash, (int)$t['id']]);
        return ['ok' => true, 'provider_id' => (string)$t['slack_ts']];
    }
}

if (!function_exists('notifySlackEventText')) {
    /** The thread reply for a client batch: "@Lance — *Jane (Kenda Tires)* requested changes on slide 2:\n> note".
     *  Each line names the signed-in contact who did it (activity_log.client_contact_id), else the client. */
    function notifySlackEventText(array $info, array $acts, ?array $owner): string {
        $company = $info['company_name'] !== '' ? $info['company_name'] : 'The client';
        $whoOf = static function (array $a) use ($company): string {
            $label = !empty($a['client_contact_id']) ? clientContactLabel($GLOBALS['pdo'] ?? null, (int)$a['client_contact_id']) : '';
            return '*' . notifySlackEscape($label !== '' ? $label : $company) . '*';
        };
        $who = $whoOf($acts[0] ?? []);
        $mention = ($owner && trim((string)$owner['slack_user_id']) !== '') ? '<@' . trim((string)$owner['slack_user_id']) . '> ' : '';
        $actions = array_column($acts, 'action');
        $comments = array_values(array_filter($acts, static function ($a) { return $a['action'] === 'commented' && trim((string)$a['detail']) !== ''; }));
        $lines = [];
        $quote = static function (array $c): array {
            [$slide, $body] = commentSlideSplit(trim((string)$c['detail']));
            return [$slide, notifyQuote($body)];
        };
        if (in_array('denied', $actions, true) || in_array('approved', $actions, true)) {
            $denied = in_array('denied', $actions, true);
            $decider = array_values(array_filter($acts, static function ($a) { return in_array($a['action'], ['denied', 'approved'], true); }))[0] ?? [];
            $who = $whoOf($decider);
            $verb = $denied ? ':red_circle: ' . $who . ' requested changes' : ':white_check_mark: ' . $who . ' approved it';
            if ($comments) {
                [$slide, $q] = $quote(array_shift($comments));
                $lines[] = $verb . ($slide > 0 ? ' on slide ' . $slide : '') . ':' . "\n" . $q;
            } else {
                $lines[] = $verb . '.';
            }
        }
        foreach ($comments as $c) {
            [$slide, $q] = $quote($c);
            $lines[] = ':speech_balloon: ' . $whoOf($c) . ' commented' . ($slide > 0 ? ' on slide ' . $slide : '') . ':' . "\n" . $q;
        }
        foreach ($acts as $a) {
            if ($a['action'] === 'edited_caption' || $a['action'] === 'edited_hashtags') {
                $lines[] = ':pencil2: ' . $whoOf($a) . ' edited the ' . ($a['action'] === 'edited_caption' ? 'caption' : 'hashtags') . ':' . "\n" . notifyQuote((string)$a['detail'], 700);
            }
        }
        if (!$lines) $lines[] = $who . ' updated it.';
        return $mention . implode("\n\n", $lines);
    }
}

if (!function_exists('notifyDeliverItemEvent')) {
    function notifyDeliverItemEvent(PDO $pdo, array $p): array {
        $type = (string)($p['entity_type'] ?? ''); $id = (int)($p['entity_id'] ?? 0); $cid = (int)($p['company_id'] ?? 0);
        $info = notifyItemInfo($pdo, $type, $id);
        if (!$info['exists'] || (int)$info['company_id'] !== $cid) return ['ok' => false, 'skip' => true, 'error' => 'the item no longer exists'];
        $channel = notifyClientChannel($pdo, $cid);
        if ($channel === '') return ['ok' => false, 'skip' => true, 'error' => 'no Slack channel set for ' . $info['company_name']];
        $ids = array_values(array_filter(array_map('intval', (array)($p['activity_ids'] ?? []))));
        if (!$ids) return ['ok' => false, 'skip' => true, 'error' => 'no events'];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT id, action, actor, detail, internal" . (activityHasContactCol($pdo) ? ', client_contact_id' : ', NULL AS client_contact_id') . " FROM activity_log
                             WHERE id IN ($ph) AND company_id = ? AND entity_type = ? AND entity_id = ? AND actor = 'client' AND internal = 0 ORDER BY id ASC");
        $s->execute(array_merge($ids, [$cid, $type, $id]));
        $acts = $s->fetchAll();
        if (!$acts) return ['ok' => false, 'skip' => true, 'error' => 'the events were removed'];
        $thread = notifySlackEnsureThread($pdo, $info, $channel);
        if (empty($thread['ok'])) return $thread;
        $text = notifySlackEventText($info, $acts, notifyOwnerFor($pdo, $cid));
        $r = slackApi('chat.postMessage', ['channel' => $thread['channel'], 'thread_ts' => $thread['ts'], 'text' => $text, 'unfurl_links' => false, 'unfurl_media' => false]);
        if (empty($r['ok'])) return notifySlackResult($r, 'reply');
        if (empty($thread['created'])) notifySlackUpdateParent($pdo, $type, $id);   // status pill / waiting line
        return ['ok' => true, 'provider_id' => (string)($r['data']['ts'] ?? '')];
    }
}

if (!function_exists('notifyAgeLabel')) {
    function notifyAgeLabel(int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        $h = intdiv($minutes, 60); $m = $minutes % 60;
        if ($h < 48) return $h . 'h' . ($m >= 5 ? ' ' . $m . 'm' : '');
        return intdiv($h, 24) . ' days';
    }
}

if (!function_exists('notifyDeliver')) {
    /** Deliver one claimed outbox row → result for notifyFinish(). */
    function notifyDeliver(PDO $pdo, array $row): array {
        $p = json_decode((string)$row['payload'], true);
        if (!is_array($p)) return ['ok' => false, 'permanent' => true, 'error' => 'bad payload'];
        switch ((string)$row['kind']) {
            case 'item_event':
                return notifyDeliverItemEvent($pdo, $p);
            case 'parent_update':
                return notifySlackUpdateParent($pdo, (string)$p['entity_type'], (int)$p['entity_id']);
            case 'escalate_thread': {
                $info = notifyItemInfo($pdo, (string)$p['entity_type'], (int)$p['entity_id']);
                if (!$info['exists']) return ['ok' => false, 'skip' => true, 'error' => 'the item no longer exists'];
                if (!notifyEscalationStillOpen($pdo, $p)) return ['ok' => false, 'skip' => true, 'error' => 'answered before the reminder went out'];
                $channel = notifyClientChannel($pdo, (int)$info['company_id']);
                if ($channel === '') return ['ok' => false, 'skip' => true, 'error' => 'no Slack channel set for ' . $info['company_name']];
                $thread = notifySlackEnsureThread($pdo, $info, $channel);
                if (empty($thread['ok'])) return $thread;
                $owner = adminUserById($pdo, (int)($p['owner_user_id'] ?? 0));
                $mention = $owner && $owner['slack_user_id'] ? '<@' . $owner['slack_user_id'] . '> ' : '';
                $r = slackApi('chat.postMessage', ['channel' => $thread['channel'], 'thread_ts' => $thread['ts'], 'reply_broadcast' => true,
                    'text' => $mention . ':alarm_clock: Still unanswered after ' . notifyAgeLabel((int)$p['minutes']) . ' — '
                            . notifySlackEscape($info['company_name']) . ' is waiting on a reply.']);
                if (!empty($r['ok'])) notifySlackUpdateParent($pdo, (string)$p['entity_type'], (int)$p['entity_id']);
                return notifySlackResult($r, 'escalation');
            }
            case 'escalate_dm': {
                $info = notifyItemInfo($pdo, (string)$p['entity_type'], (int)$p['entity_id']);
                if (!$info['exists']) return ['ok' => false, 'skip' => true, 'error' => 'the item no longer exists'];
                if (!notifyEscalationStillOpen($pdo, $p)) return ['ok' => false, 'skip' => true, 'error' => 'answered before the reminder went out'];
                $owner = adminUserById($pdo, (int)($p['owner_user_id'] ?? 0));
                if (!$owner || trim((string)$owner['slack_user_id']) === '') return ['ok' => false, 'skip' => true, 'error' => 'the owner has no Slack user id'];
                $open = slackApi('conversations.open', ['users' => (string)$owner['slack_user_id']]);
                if (empty($open['ok'])) return notifySlackResult($open, 'conversations.open');
                $dm = (string)($open['data']['channel']['id'] ?? '');
                $last = notifyLastClientMessage($pdo, $p);
                $text = ':alarm_clock: *' . notifySlackEscape($info['company_name']) . '* has been waiting ' . notifyAgeLabel((int)$p['minutes'])
                      . ' for a reply on *<' . $info['url'] . '|' . notifySlackEscape($info['title']) . '>*'
                      . ($last !== '' ? ":\n" . notifyQuote($last, 600) : '.');
                return notifySlackResult(slackApi('chat.postMessage', ['channel' => $dm, 'text' => $text, 'unfurl_links' => false]), 'DM');
            }
            case 'escalate_email': {
                $info = notifyItemInfo($pdo, (string)$p['entity_type'], (int)$p['entity_id']);
                if (!$info['exists']) return ['ok' => false, 'skip' => true, 'error' => 'the item no longer exists'];
                if (!notifyEscalationStillOpen($pdo, $p)) return ['ok' => false, 'skip' => true, 'error' => 'answered before the reminder went out'];
                $owner = adminUserById($pdo, (int)($p['owner_user_id'] ?? 0));
                $to = $owner ? (string)$owner['email'] : notifyCfg('notify_to');
                if ($to === '') return ['ok' => false, 'skip' => true, 'error' => 'no recipient'];
                $last = notifyLastClientMessage($pdo, $p);
                [$slide, $body] = commentSlideSplit($last);
                $age = notifyAgeLabel((int)$p['minutes']);
                $info['url'] = notifyItemLinkFor($pdo, $info, $to);   // the admin link for Joust; clientLink() for a client
                $subject = "Waiting {$age}: {$info['company_name']} — {$info['title']}";
                $text = "{$info['company_name']} has been waiting {$age} for a reply on {$info['title']}.\n\n"
                      . ($slide > 0 ? "On slide {$slide}: " : '') . "\"{$body}\"\n\nOpen it: {$info['url']}\n";
                $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
                $html = '<div style="font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#1c1c1e;max-width:560px">'
                      . '<p><strong>' . $e($info['company_name']) . '</strong> has been waiting <strong>' . $e($age) . '</strong> for a reply on '
                      . '<a href="' . $e($info['url']) . '">' . $e($info['title']) . '</a>.</p>'
                      . '<blockquote style="margin:12px 0;padding:8px 12px;border-left:3px solid #ff9500;background:#f2f2f7">'
                      . ($slide > 0 ? '<span style="color:#8e8e93">On slide ' . $slide . ':</span> ' : '') . nl2br($e($body)) . '</blockquote>'
                      . '<p><a href="' . $e($info['url']) . '" style="display:inline-block;background:#007aff;color:#fff;padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:600">Open in portal</a></p></div>';
                $res = notifyEmail(['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html,
                                    'thread' => ['entity_type' => $info['entity_type'], 'entity_id' => (int)$info['entity_id'], 'company_id' => (int)$info['company_id']]]);
                return $res['ok'] ? ['ok' => true, 'provider_id' => $res['message_id']] : ['ok' => false, 'error' => 'email: ' . $res['error']];
            }
            case 'summary':
                return notifyDeliverSummary($pdo, $p);
            case 'client_email':   // client-notify-lib.php: Ready for review / Joust replied / Live & scheduled (rendered now, per recipient)
                return function_exists('clientEmailDeliver') ? clientEmailDeliver($pdo, $p) : ['ok' => false, 'permanent' => true, 'error' => 'client emails are not available'];
            case 'weekly':         // tracking-lib.php: the Monday owner report
                return function_exists('trackingDeliverWeekly') ? trackingDeliverWeekly($pdo, $p) : ['ok' => false, 'permanent' => true, 'error' => 'weekly report is not available'];
            case 'sign_in':
            case 'email':
                return notifyDeliverDirectEmail($pdo, $p);
            case 'slack_test': {
                $text = ':wave: Test from the Joust portal (' . notifySlackEscape(notifyBaseUrl()) . '). Notifications for this channel are working.';
                $channel = (string)($p['channel'] ?? '');
                if ($channel === '' && !empty($p['user'])) {
                    $open = slackApi('conversations.open', ['users' => (string)$p['user']]);
                    if (empty($open['ok'])) return notifySlackResult($open, 'conversations.open');
                    $channel = (string)($open['data']['channel']['id'] ?? '');
                }
                if ($channel === '') return ['ok' => false, 'permanent' => true, 'error' => 'no channel'];
                return notifySlackResult(slackApi('chat.postMessage', ['channel' => $channel, 'text' => $text]), 'test');
            }
        }
        return ['ok' => false, 'permanent' => true, 'error' => 'unknown kind ' . $row['kind']];
    }
}

if (!function_exists('notifyDeliverDirectEmail')) {
    /** Outbox 'sign_in' / 'email' row: one ready-made message (notifySendEmailNow()). A row whose body was already
     *  scrubbed (delivered / given up) or whose expiry passed (a sign-in link is dead after 15 minutes) is skipped. */
    function notifyDeliverDirectEmail(PDO $pdo, array $p): array {
        if (!empty($p['redacted']) || !isset($p['to'])) return ['ok' => false, 'skip' => true, 'error' => 'nothing to send (message already handled)'];
        if (!empty($p['expires']) && (int)$p['expires'] < time()) return ['ok' => false, 'skip' => true, 'error' => 'expired before it could be sent'];
        $msg = array_intersect_key($p, array_flip(['to', 'subject', 'text', 'html', 'from', 'from_name', 'reply_to', 'kind', 'headers']));
        $res = notifyEmail($msg);
        return $res['ok'] ? ['ok' => true, 'provider_id' => $res['message_id']] : ['ok' => false, 'error' => 'email: ' . $res['error']];
    }
}

if (!function_exists('notifySendEmailNow')) {
    /**
     * An email through the outbox with an IMMEDIATE delivery attempt in this request (client sign-in links: they must
     * arrive while the person is waiting). The row is the delivery log entry (Manage → Notifications) and the retry
     * path when the first attempt fails (the cron retries with backoff until $o['expires']); once delivered or given
     * up, the bodies are scrubbed from the row (a sign-in token never stays in the database in clear).
     * $o: kind ('sign_in' | 'email'), expires (unix time after which sending is pointless), company_id.
     * Without the outbox (migrate.php 36–39 not run yet) it sends directly. → ['ok' => bool, 'queued' => bool, 'error'].
     */
    function notifySendEmailNow(?PDO $pdo, array $msg, array $o = []): array {
        $kind = in_array($o['kind'] ?? '', ['sign_in', 'email'], true) ? $o['kind'] : 'email';
        if (!$pdo || !notifyReady($pdo)) {
            $r = notifyEmail($msg + ['kind' => $kind]);
            return ['ok' => $r['ok'], 'queued' => false, 'error' => $r['error']];
        }
        $payload = $msg + ['kind' => $kind];
        if (!empty($o['expires'])) $payload['expires'] = (int)$o['expires'];
        $id = notifyEnqueue($pdo, 'email', $kind, $payload, ['target' => (string)($msg['to'] ?? ''), 'defer' => true,
            'company_id' => isset($o['company_id']) ? (int)$o['company_id'] : null]);
        if ($id <= 0) {
            $r = notifyEmail($msg + ['kind' => $kind]);
            return ['ok' => $r['ok'], 'queued' => false, 'error' => $r['error']];
        }
        notifyPump($pdo, ['ids' => [$id], 'limit' => 1, 'budget' => 10.0]);
        $st = $pdo->prepare("SELECT status, last_error FROM notify_outbox WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch() ?: ['status' => 'pending', 'last_error' => ''];
        return ['ok' => $row['status'] === 'sent', 'queued' => $row['status'] === 'pending', 'error' => (string)($row['last_error'] ?? '')];
    }
}

if (!function_exists('notifyEscalationStillOpen')) {
    /** The client message an escalation is about is still unanswered (nothing from Joust answered after it). */
    function notifyEscalationStillOpen(PDO $pdo, array $p): bool {
        $w = notifyItemWaiting($pdo, (string)$p['entity_type'], (int)$p['entity_id'], (int)($p['company_id'] ?? 0));
        return $w !== null && (int)$w['first_id'] <= (int)($p['first_id'] ?? 0);
    }
}

if (!function_exists('notifyLastClientMessage')) {
    function notifyLastClientMessage(PDO $pdo, array $p): string {
        $w = notifyItemWaiting($pdo, (string)$p['entity_type'], (int)$p['entity_id'], (int)($p['company_id'] ?? 0));
        return $w ? (string)$w['last_detail'] : '';
    }
}

if (!function_exists('notifyEscalate')) {
    /**
     * The escalation check (notify-cron.php): for every item whose oldest unanswered client message is at least
     * T1 minutes old → a re-ping in its Slack thread (@owner) + a DM to the owner; at least T2 → an email to the owner.
     * Each step happens once per waiting message (dedupe keys on the message id). Messages older than notify_since
     * (the migration) or 7 days are never escalated. Returns ['t1' => n enqueued, 't2' => n enqueued].
     */
    function notifyEscalate(PDO $pdo): array {
        $out = ['t1' => 0, 't2' => 0];
        if (!notifyReady($pdo)) return $out;
        $set = notifySettings($pdo);
        $floor = max((int)strtotime(notifyMeta($pdo, 'notify_since', '1970-01-02 00:00:00')), time() - 7 * 86400);
        $now = (int)$pdo->query("SELECT UNIX_TIMESTAMP(NOW())")->fetchColumn();
        foreach (notifyUnanswered($pdo, null, date('Y-m-d H:i:s', $floor)) as $w) {
            $age = (int)floor(($now - (int)strtotime((string)$w['first_at'])) / 60);
            if ($age < $set['t1']) continue;
            $owner = notifyOwnerFor($pdo, (int)$w['company_id']);
            $base = ['entity_type' => $w['entity_type'], 'entity_id' => (int)$w['entity_id'], 'company_id' => (int)$w['company_id'],
                     'first_id' => (int)$w['first_id'], 'owner_user_id' => $owner ? (int)$owner['id'] : 0];
            $o = ['company_id' => (int)$w['company_id'], 'entity_type' => $w['entity_type'], 'entity_id' => (int)$w['entity_id'], 'defer' => true];
            if (notifySlackConfigured()) {
                if (notifyClientChannel($pdo, (int)$w['company_id']) !== '') {
                    if (notifyEnqueueOnce($pdo, 'slack', 'escalate_thread', $base + ['minutes' => $set['t1']], $o + ['dedupe' => 'esc1t:' . $w['first_id']])) $out['t1']++;
                }
                if ($owner && trim((string)$owner['slack_user_id']) !== '') {
                    notifyEnqueueOnce($pdo, 'slack', 'escalate_dm', $base + ['minutes' => $set['t1']], $o + ['dedupe' => 'esc1d:' . $w['first_id'], 'target' => (string)$owner['slack_user_id']]);
                }
            }
            if ($age >= $set['t2'] && ($owner || notifyCfg('notify_to') !== '')) {
                if (notifyEnqueueOnce($pdo, 'email', 'escalate_email', $base + ['minutes' => $set['t2']], $o + ['dedupe' => 'esc2:' . $w['first_id'], 'target' => $owner ? (string)$owner['email'] : notifyCfg('notify_to')])) $out['t2']++;
            }
        }
        return $out;
    }
}

if (!function_exists('notifyEnqueueOnce')) {
    /** Enqueue unless the dedupe key already exists; true when a new row was created. */
    function notifyEnqueueOnce(PDO $pdo, string $channel, string $kind, array $payload, array $o): bool {
        $s = $pdo->prepare("SELECT 1 FROM notify_outbox WHERE dedupe_key = ?");
        $s->execute([$o['dedupe']]);
        if ($s->fetchColumn()) return false;
        return notifyEnqueue($pdo, $channel, $kind, $payload, $o) > 0;
    }
}

// =====================================================================================================================
// Slack in: signature, events, interactivity
// =====================================================================================================================

if (!function_exists('slackVerifyRequest')) {
    /**
     * Verify a Slack request: X-Slack-Signature = 'v0=' . hex(HMAC-SHA256(signing secret, "v0:{timestamp}:{raw body}")),
     * compared in constant time; X-Slack-Request-Timestamp within 5 minutes of now (replay guard).
     * → ['ok' => bool, 'code' => 200|401|503, 'error' => …]. $now is injectable for tests of the guard itself.
     */
    function slackVerifyRequest(string $raw, ?string $ts, ?string $sig, ?int $now = null): array {
        $secret = notifyCfg('slack_signing_secret');
        if ($secret === '') return ['ok' => false, 'code' => 503, 'error' => 'Slack signing secret not configured'];
        $ts = trim((string)$ts); $sig = trim((string)$sig);
        if ($ts === '' || $sig === '' || !ctype_digit($ts)) return ['ok' => false, 'code' => 401, 'error' => 'missing signature'];
        if (abs(($now ?? time()) - (int)$ts) > 300) return ['ok' => false, 'code' => 401, 'error' => 'stale timestamp'];
        $want = 'v0=' . hash_hmac('sha256', 'v0:' . $ts . ':' . $raw, $secret);
        if (!hash_equals($want, $sig)) return ['ok' => false, 'code' => 401, 'error' => 'bad signature'];
        return ['ok' => true, 'code' => 200, 'error' => ''];
    }
}

if (!function_exists('notifyRequestHeader')) {
    function notifyRequestHeader(string $name): ?string {
        if (function_exists('requestHeader')) return requestHeader($name);
        $k = strtoupper(str_replace('-', '_', $name));
        $v = $_SERVER['HTTP_' . $k] ?? $_SERVER['REDIRECT_HTTP_' . $k] ?? null;
        return is_string($v) ? $v : null;
    }
}

if (!function_exists('slackInboxClaim')) {
    /** Record an event id; false when it was already seen (Slack retry / replay) — the caller acks and stops. */
    function slackInboxClaim(PDO $pdo, string $eventId, string $kind, string $channel = '', string $user = ''): bool {
        if ($eventId === '') return false;
        $s = $pdo->prepare("INSERT IGNORE INTO slack_inbox (event_id, kind, channel, user_id) VALUES (?, ?, ?, ?)");
        $s->execute([substr($eventId, 0, 80), substr($kind, 0, 30), substr($channel, 0, 32) ?: null, substr($user, 0, 32) ?: null]);
        return $s->rowCount() === 1;
    }
}

if (!function_exists('slackInboxDone')) {
    function slackInboxDone(PDO $pdo, string $eventId, string $status, string $note = '', ?int $activityId = null): void {
        try {
            $pdo->prepare("UPDATE slack_inbox SET status = ?, note = ?, activity_id = ?, processed_at = NOW() WHERE event_id = ?")
                ->execute([substr($status, 0, 20), substr($note, 0, 255) ?: null, $activityId, substr($eventId, 0, 80)]);
        } catch (Throwable $e) {
            error_log('slackInboxDone: ' . $e->getMessage());
        }
    }
}

if (!function_exists('slackPlainText')) {
    /** Slack message text → plain portal text: entities decoded, <@U…> → @Name, <url|label> → label (url), <#C|name> → #name. */
    function slackPlainText(PDO $pdo, string $text): string {
        $text = preg_replace_callback('/<([^>]+)>/', static function ($m) use ($pdo) {
            $in = $m[1];
            if ($in[0] === '@') {
                $u = adminUserBySlack($pdo, explode('|', substr($in, 1))[0]);
                return '@' . ($u ? adminUserFirstName($u) : 'someone');
            }
            if ($in[0] === '#') { $p = explode('|', substr($in, 1)); return '#' . ($p[1] ?? $p[0]); }
            if ($in[0] === '!') { $p = explode('|', substr($in, 1)); return '@' . ($p[1] ?? $p[0]); }
            $p = explode('|', $in, 2);
            return isset($p[1]) && $p[1] !== $p[0] ? $p[1] . ' (' . $p[0] . ')' : $p[0];
        }, $text);
        return trim(str_replace(['&lt;', '&gt;', '&amp;'], ['<', '>', '&'], (string)$text));
    }
}

if (!function_exists('notifyLogComment')) {
    /** A Joust comment on an item written outside the portal (Slack): the same rows the portal's comment endpoints write
     *  (posts / tire images also keep client_comment = the newest visible message). Returns the activity id. */
    function notifyLogComment(PDO $pdo, array $info, string $text, bool $internal): int {
        $type = $info['entity_type']; $id = (int)$info['entity_id']; $cid = (int)$info['company_id'];
        $batch = newBatchId();
        if (!$internal) {
            if ($type === 'post') $pdo->prepare("UPDATE posts SET client_comment = ? WHERE id = ?")->execute([$text, $id]);
            if ($type === 'tire_image') $pdo->prepare("UPDATE tire_images SET client_comment = ? WHERE id = ?")->execute([$text, $id]);
        }
        $summary = ($internal ? 'Internal note on ' : 'Comment on ') . $info['title'];
        return (int)logActivity($pdo, $cid, $type, $id, 'commented', 'admin', $summary, $text, $batch);
    }
}

if (!function_exists('slackHandleMessageEvent')) {
    /**
     * Events API 'message' in a portal thread → a portal comment by the mapped Joust user on THAT item only (the thread
     * row is looked up by channel + thread_ts; the item must still belong to the thread's client). '!internal …' →
     * an internal note. Ignored: bot messages, edits / deletes (any subtype), top-level messages, unmapped users.
     * Returns [status, note, activity_id].
     */
    function slackHandleMessageEvent(PDO $pdo, array $ev): array {
        if (($ev['type'] ?? '') !== 'message') return ['ignored', 'not a message', null];
        if (!empty($ev['bot_id']) || !empty($ev['bot_profile']) || isset($ev['subtype'])) return ['ignored', 'bot message or edit (' . ($ev['subtype'] ?? 'bot') . ')', null];
        $threadTs = (string)($ev['thread_ts'] ?? ''); $ts = (string)($ev['ts'] ?? '');
        if ($threadTs === '' || $threadTs === $ts) return ['ignored', 'not a thread reply', null];
        $t = notifyThreadBySlack($pdo, (string)($ev['channel'] ?? ''), $threadTs);
        if (!$t) return ['ignored', 'not a portal thread', null];
        $user = adminUserBySlack($pdo, (string)($ev['user'] ?? ''));
        if (!$user) return ['ignored', 'Slack user is not mapped to a Joust team member', null];
        $text = slackPlainText($pdo, (string)($ev['text'] ?? ''));
        $internal = false;
        if (preg_match('/^\s*!internal\b[:\s]*/i', $text, $m)) { $internal = true; $text = trim(substr($text, strlen($m[0]))); }
        if ($text === '') return ['ignored', 'empty message', null];
        if (mb_strlen($text, 'UTF-8') > 2000) $text = rtrim(mb_substr($text, 0, 1999, 'UTF-8')) . '…';
        $info = notifyItemInfo($pdo, (string)$t['entity_type'], (int)$t['entity_id']);
        if (!$info['exists'] || (int)$info['company_id'] !== (int)$t['company_id']) return ['ignored', 'the item no longer exists', null];
        $aid = activityWithContext(['author_user_id' => (int)$user['id'], 'internal' => $internal ? 1 : 0, 'source' => 'slack'],
            static function () use ($pdo, $info, $text, $internal) { return notifyLogComment($pdo, $info, $text, $internal); });
        return ['done', ($internal ? 'internal note' : 'comment') . ' by ' . $user['name'], $aid ?: null];
    }
}

if (!function_exists('slackHandleAction')) {
    /**
     * Interactivity (block_actions) on a portal parent message. The button value names the item; it must match the
     * thread the message belongs to (channel + message ts), so a button only ever touches its own item. The Slack user
     * must be a mapped Joust user. Applies the portal's own transition rules (transitions-lib.php) as that user.
     * Returns ['ok' => bool, 'message' => text for an ephemeral reply].
     */
    function slackHandleAction(PDO $pdo, array $payload): array {
        $act = $payload['actions'][0] ?? null;
        if (!is_array($act)) return ['ok' => false, 'message' => 'No action.'];
        $aid = (string)($act['action_id'] ?? '');
        if ($aid === 'open') return ['ok' => true, 'message' => ''];
        $user = adminUserBySlack($pdo, (string)($payload['user']['id'] ?? ''));
        if (!$user) return ['ok' => false, 'message' => 'You are not mapped to a Joust team member in the portal (Manage → Notifications → Team).'];
        if (!preg_match('/^([a-z_]{3,20}):(\d{1,10})$/', (string)($act['value'] ?? ''), $m) || !in_array($m[1], notifyThreadTypes(), true)) {
            return ['ok' => false, 'message' => 'Unknown item.'];
        }
        $type = $m[1]; $id = (int)$m[2];
        $channel = (string)($payload['container']['channel_id'] ?? ($payload['channel']['id'] ?? ''));
        $msgTs = (string)($payload['container']['message_ts'] ?? ($payload['message']['ts'] ?? ''));
        $t = notifyThreadBySlack($pdo, $channel, $msgTs);
        if (!$t || $t['entity_type'] !== $type || (int)$t['entity_id'] !== $id) return ['ok' => false, 'message' => 'That button does not belong to this message.'];
        $info = notifyItemInfo($pdo, $type, $id);
        if (!$info['exists'] || (int)$info['company_id'] !== (int)$t['company_id']) return ['ok' => false, 'message' => 'That item is no longer in the portal.'];
        $ctx = ['author_user_id' => (int)$user['id'], 'source' => 'slack'];
        $res = activityWithContext($ctx, static function () use ($pdo, $aid, $type, $id, $info) {
            switch ($aid) {
                case 'resolve':
                    if (!notifyItemWaiting($pdo, $type, $id, (int)$info['company_id'])) return ['ok' => true, 'code' => 200, 'note' => 'Nothing was waiting.'];
                    activityWithContext(['internal' => 1], static function () use ($pdo, $info, $type, $id) {
                        logActivity($pdo, (int)$info['company_id'], $type, $id, 'resolved', 'admin', 'Marked the client’s note on ' . $info['title'] . ' as answered');
                    });
                    return ['ok' => true, 'code' => 200];
                case 'submit':
                    if ($type === 'post') return transitionPostSubmit($pdo, $id, 'admin');
                    if ($type === 'email' || $type === 'page') {
                        if (($info['row']['status'] ?? '') !== 'draft') return transitionFail(409, 'Only a draft can be sent for review');
                        return transitionMailSubmit($pdo, $type, $info['row'], 'admin');
                    }
                    return transitionFail(400, 'Send for review does not apply here');
                case 'scheduled':
                    if ($type !== 'post') return transitionFail(400, 'Only posts are scheduled');
                    return transitionPostScheduled($pdo, $id, 1, 'admin');
                case 'live':
                    if ($type !== 'email' && $type !== 'page') return transitionFail(400, 'Only emails and pages go live');
                    return transitionMailLive($pdo, $type, $info['row'], 1, 'admin');
            }
            return transitionFail(400, 'Unknown action');
        });
        // The parent always re-renders after a button (a refused transition may mean it is stale).
        notifyEnqueue($pdo, 'slack', 'parent_update', ['entity_type' => $type, 'entity_id' => $id, 'company_id' => (int)$info['company_id']],
            ['dedupe' => 'upd:act:' . substr((string)($payload['trigger_id'] ?? bin2hex(random_bytes(6))), 0, 60), 'company_id' => (int)$info['company_id'], 'entity_type' => $type, 'entity_id' => $id]);
        if (empty($res['ok'])) return ['ok' => false, 'message' => 'Could not do that: ' . ($res['error'] ?? 'refused') . '.'];
        return ['ok' => true, 'message' => (string)($res['note'] ?? '')];
    }
}

if (!function_exists('slackRespondEphemeral')) {
    /** Post an ephemeral note back through the interaction's response_url (Slack hosts only, or the configured API host). */
    function slackRespondEphemeral(string $responseUrl, string $text): void {
        if ($responseUrl === '' || $text === '') return;
        $host = strtolower((string)parse_url($responseUrl, PHP_URL_HOST));
        $apiHost = strtolower((string)parse_url(notifyCfg('slack_api_base', 'https://slack.com/api'), PHP_URL_HOST));
        if (!preg_match('/(^|\.)slack\.com$/', $host) && $host !== $apiHost) return;
        notifyHttpPost($responseUrl, ['response_type' => 'ephemeral', 'replace_original' => false, 'text' => $text]);
    }
}

// =====================================================================================================================
// Email (one abstraction; transports are pluggable)
// =====================================================================================================================

if (!function_exists('notifyEmailClean')) {
    function notifyEmailClean(string $s): string { return trim(str_replace(["\r", "\n"], ' ', $s)); }
}

if (!function_exists('notifyEmail')) {
    /**
     * Send one email — THE email function (notifications, reminders, the Morning summary, client sign-in links). $msg:
     *   to (required), subject, text, html,
     *   from (an address, or a full 'Name <address>'; default config notify_from, else lance@joustmedia.com),
     *   from_name (display name for a bare from address; default config notify_from_name, else "Joust Media"),
     *   reply_to (default notify_reply_to), kind (free-form tag kept in the sink, e.g. 'sign_in'),
     *   message_id (default generated '<…@notify_message_domain>'), in_reply_to, references (string|array),
     *   headers (extra 'Name' => 'value'), thread (['entity_type','entity_id','company_id'] — threads the mail on the
     *   item: the first message's id is stored in notify_threads.email_message_id and later ones reply to it).
     * Transport = gmail-lib.php notifyMailTransport(): config mail_transport when set ('mail' | 'gmail' | 'sink'); blank =
     * 'gmail' once Google is connected, else 'mail' ('sink' when only the harness's mail_sink_dir is set),
     * dispatched to notifyMailSend_<transport>(array $msg): ['ok' => bool, 'error' => string, 'provider_id' => ?string].
     * Returns ['ok', 'error', 'message_id', 'transport'].
     */
    function notifyEmail(array $msg): array {
        // gmail-lib.php notifyMailTransport(): config mail_transport when set, else Gmail once Google is connected, else mail()
        $transport = function_exists('notifyMailTransport') ? notifyMailTransport()
            : preg_replace('/[^a-z0-9_]/', '', strtolower(notifyCfg('mail_transport', notifyCfg('mail_sink_dir') !== '' ? 'sink' : 'mail')));
        $fn = 'notifyMailSend_' . $transport;
        $msg['to'] = notifyEmailClean((string)($msg['to'] ?? ''));
        if ($msg['to'] === '' || !filter_var(preg_replace('/^.*<([^>]+)>$/', '$1', $msg['to']), FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'no valid recipient', 'message_id' => '', 'transport' => $transport];
        }
        if (!function_exists($fn)) return ['ok' => false, 'error' => 'unknown mail transport ' . $transport, 'message_id' => '', 'transport' => $transport];
        $msg['subject']  = notifyEmailClean((string)($msg['subject'] ?? ''));
        [$msg['from_address'], $msg['from_name']] = notifyMailSender((string)($msg['from'] ?? ''), (string)($msg['from_name'] ?? ''));
        $msg['from']     = notifyMailFromHeader($msg['from_address'], $msg['from_name']);
        $msg['reply_to'] = notifyEmailClean((string)($msg['reply_to'] ?? notifyCfg('notify_reply_to')));
        $domain = notifyCfg('notify_message_domain', (string)(parse_url(notifyBaseUrl(), PHP_URL_HOST) ?: 'localhost'));
        $msg['message_id'] = notifyEmailClean((string)($msg['message_id'] ?? ('<notify-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '@' . $domain . '>')));
        $pdo = $GLOBALS['pdo'] ?? null;
        $thread = $msg['thread'] ?? null;
        if (is_array($thread) && $pdo instanceof PDO && notifyReady($pdo)) {
            $t = notifyThreadRow($pdo, (string)$thread['entity_type'], (int)$thread['entity_id']);
            $root = $t ? trim((string)$t['email_message_id']) : '';
            if ($root !== '') {
                $msg['in_reply_to'] = $msg['in_reply_to'] ?? $root;
                $msg['references']  = $msg['references'] ?? $root;
            }
        }
        $res = $fn($msg);
        $ok = !empty($res['ok']);
        if ($ok && is_array($thread) && $pdo instanceof PDO && notifyReady($pdo)) {
            try {
                $pdo->prepare("INSERT INTO notify_threads (company_id, entity_type, entity_id, email_message_id) VALUES (?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE email_message_id = COALESCE(NULLIF(email_message_id, ''), VALUES(email_message_id))")
                    ->execute([(int)($thread['company_id'] ?? 0), (string)$thread['entity_type'], (int)$thread['entity_id'], $msg['message_id']]);
            } catch (Throwable $e) {
                error_log('notifyEmail thread: ' . $e->getMessage());
            }
        }
        return ['ok' => $ok, 'error' => $ok ? '' : (string)($res['error'] ?? 'send failed'), 'message_id' => $msg['message_id'], 'transport' => $transport];
    }
}

if (!function_exists('notifyMailSender')) {
    /** [address, display name] of the sender: $from (bare address or 'Name <address>'), else config notify_from
     *  (alias auth_mail_from; same two forms), else lance@joustmedia.com; the name from the 'Name <…>' form, else
     *  $name, else config notify_from_name (alias auth_mail_from_name), else "Joust Media". */
    function notifyMailSender(string $from = '', string $name = ''): array {
        $from = notifyEmailClean($from !== '' ? $from : notifyCfg('notify_from', 'lance@joustmedia.com'));
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $from, $m)) {
            $addr = trim($m[2]);
            if (trim($m[1]) !== '' && $name === '') $name = trim($m[1]);
        } else {
            $addr = trim($from);
        }
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) $addr = 'lance@joustmedia.com';
        $name = notifyEmailClean($name !== '' ? $name : notifyCfg('notify_from_name', 'Joust Media'));
        return [$addr, str_replace(['"', '\\'], '', $name)];
    }
}

if (!function_exists('notifyMailFromHeader')) {
    /** '"Joust Media" <lance@joustmedia.com>' (RFC 2047-encoded name when it is not plain ASCII). */
    function notifyMailFromHeader(string $addr, string $name): string {
        if ($name === '') return $addr;
        $enc = preg_match('/[^\x20-\x7e]/', $name) ? '=?UTF-8?B?' . base64_encode($name) . '?=' : '"' . $name . '"';
        return $enc . ' <' . $addr . '>';
    }
}

if (!function_exists('notifyMailHeaders')) {
    /** The RFC 5322 header lines shared by transports (From, Reply-To, Message-ID, In-Reply-To, References, extras). */
    function notifyMailHeaders(array $msg): array {
        $h = [];
        if ($msg['from'] !== '')     $h[] = 'From: ' . $msg['from'];
        if ($msg['reply_to'] !== '') $h[] = 'Reply-To: ' . $msg['reply_to'];
        $h[] = 'Message-ID: ' . $msg['message_id'];
        if (!empty($msg['in_reply_to'])) $h[] = 'In-Reply-To: ' . notifyEmailClean((string)$msg['in_reply_to']);
        if (!empty($msg['references']))  $h[] = 'References: ' . notifyEmailClean(is_array($msg['references']) ? implode(' ', $msg['references']) : (string)$msg['references']);
        foreach ((array)($msg['headers'] ?? []) as $k => $v) {
            $k = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$k);
            if ($k !== '') $h[] = $k . ': ' . notifyEmailClean((string)$v);
        }
        return $h;
    }
}

if (!function_exists('notifyMailSend_mail')) {
    /** PHP mail() with multipart/alternative and the envelope sender (config notify_envelope, alias auth_mail_envelope;
     *  default the From address — the host's SPF / DMARC alignment). */
    function notifyMailSend_mail(array $msg): array {
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = notifyMailHeaders($msg);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'X-Mailer: Joust-Portal-Notify';
        $text = (string)($msg['text'] ?? strip_tags((string)($msg['html'] ?? '')));
        $body = "This is a multi-part message in MIME format.\r\n\r\n"
              . "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n\r\n";
        if (!empty($msg['html'])) {
            $body .= "--{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $msg['html'] . "\r\n\r\n";
        }
        $body .= "--{$boundary}--\r\n";
        $env = notifyCfg('notify_envelope', (string)($msg['from_address'] ?? ''));
        if ($env !== '' && !filter_var($env, FILTER_VALIDATE_EMAIL)) $env = '';
        $subject = '=?UTF-8?B?' . base64_encode((string)$msg['subject']) . '?=';
        $ok = $env !== '' ? @mail($msg['to'], $subject, $body, implode("\r\n", $headers), '-f' . $env)
                          : @mail($msg['to'], $subject, $body, implode("\r\n", $headers));
        return ['ok' => (bool)$ok, 'error' => $ok ? '' : 'mail() returned false — check the host mail setup and SPF for the From domain'];
    }
}

if (!function_exists('notifyMailSend_sink')) {
    /** Test harness transport: one JSON file per message in config mail_sink_dir; a file named FAIL there makes every
     *  send fail (delivery-failure tests). Refuses to run without the directory. */
    function notifyMailSend_sink(array $msg): array {
        $dir = notifyCfg('mail_sink_dir');
        if (function_exists('notifyMimeBuild')) $msg['mime'] = notifyMimeBuild($msg);   // the exact message the Gmail transport would send
        if ($dir === '' || !is_dir($dir)) return ['ok' => false, 'error' => 'sink: mail_sink_dir missing'];
        if (is_file($dir . '/FAIL')) return ['ok' => false, 'error' => 'sink: forced failure'];
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $ok = @file_put_contents($file, json_encode($msg + ['header_lines' => notifyMailHeaders($msg)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
        return ['ok' => $ok, 'error' => $ok ? '' : 'sink: write failed'];
    }
}

// =====================================================================================================================
// Morning summary (the daily digest, rebuilt on the outbox)
// =====================================================================================================================

if (!function_exists('notifyMorningSummary')) {
    /**
     * Build + send the Morning summary of client activity since the last one. Joust's own actions and internal notes
     * are left out (and marked processed). Sent through the outbox: activity rows are marked (digest_id) only after a
     * successful send — a failed send stays queued for the cron's retries, and the next summary supersedes it (its rows
     * are still unmarked, so nothing is lost or sent twice). → ['status' => sent|queued|empty|locked|error, 'message'].
     */
    function notifyMorningSummary(PDO $pdo, string $source = 'manual'): array {
        if (!notifyReady($pdo)) return ['status' => 'error', 'message' => 'Run migrate.php first (steps 36–39).'];
        $to = notifyCfg('notify_to');
        if ($to === '') {
            $owner = notifyOwnerFor($pdo, 0);
            $to = $owner ? (string)$owner['email'] : '';
        }
        if ($to === '') return ['status' => 'error', 'message' => 'No recipient: set notify_to in config.php.'];
        $lock = $pdo->prepare("UPDATE meta SET v = ? WHERE k = 'digest_lock_until' AND v < NOW()");
        $lock->execute([date('Y-m-d H:i:s', time() + 300)]);
        if ($lock->rowCount() === 0) return ['status' => 'locked', 'message' => 'Another summary run is in progress; try again in a few minutes.'];
        try {
            $rows = $pdo->query("
                SELECT a.id, a.company_id, a.entity_type, a.entity_id, a.action, a.actor, a.batch_id, a.summary, a.detail, a.created_at, a.internal,
                       c.name AS company_name, c.slug AS company_slug
                  FROM activity_log a LEFT JOIN companies c ON c.id = a.company_id
                 WHERE a.digest_id IS NULL
                 ORDER BY a.company_id, a.created_at, a.id
                 LIMIT 500
            ")->fetchAll();
            $client = array_values(array_filter($rows, static function ($r) { return $r['actor'] !== 'admin' && empty($r['internal']); }));
            $joustIds = array_map('intval', array_column(array_filter($rows, static function ($r) { return $r['actor'] === 'admin' || !empty($r['internal']); }), 'id'));
            if (!$client) {
                if ($joustIds) notifyMarkDigest($pdo, $joustIds, 0);   // Joust's own rows never go in a summary
                return ['status' => 'empty', 'message' => 'Nothing new from clients since the last summary.'];
            }
            $leftover = max(0, (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE digest_id IS NULL")->fetchColumn() - count($rows));
            $pdo->prepare("INSERT INTO digest_runs (sent_at, event_count, recipient, trigger_source) VALUES (NOW(), ?, ?, ?)")
                ->execute([count($client), substr($to, 0, 120), in_array($source, ['cron', 'manual', 'opportunistic'], true) ? $source : 'cron']);
            $digestId = (int)$pdo->lastInsertId();
            require_once __DIR__ . '/digest-lib.php';
            $waiting = notifyUnanswered($pdo, null, date('Y-m-d H:i:s', time() - 30 * 86400), 50);
            $sum = render_summary($client, $leftover, notifyConfig(), $waiting);
            $n = count($client);
            $subject = 'Morning summary — ' . $n . ' update' . ($n === 1 ? '' : 's') . ' from ' . $sum['company_count'] . ' client' . ($sum['company_count'] === 1 ? '' : 's')
                     . ($waiting ? ' · ' . count($waiting) . ' waiting on Joust' : '');
            // A newer summary carries every unmarked row, so older undelivered ones are superseded (never sent twice).
            $pdo->prepare("UPDATE notify_outbox SET status = 'skipped', last_error = ? WHERE kind = 'summary' AND status IN ('pending','failed')")
                ->execute(['superseded by Morning summary #' . $digestId]);
            $oid = notifyEnqueue($pdo, 'email', 'summary', [
                'digest_id' => $digestId, 'to' => $to, 'subject' => $subject, 'text' => $sum['text'], 'html' => $sum['html'],
                'activity_ids' => array_map('intval', array_column($rows, 'id')), 'joust_ids' => $joustIds,
            ], ['dedupe' => 'summary:' . $digestId, 'target' => $to, 'defer' => true]);
            notifyPump($pdo, ['ids' => [$oid], 'limit' => 1]);
            $st = $pdo->prepare("SELECT status, last_error FROM notify_outbox WHERE id = ?");
            $st->execute([$oid]);
            $o = $st->fetch() ?: ['status' => 'pending', 'last_error' => ''];
            if ($o['status'] === 'sent') {
                return ['status' => 'sent', 'message' => "Sent the Morning summary ({$n} update" . ($n === 1 ? '' : 's') . ") to {$to} (#{$digestId})."];
            }
            return ['status' => 'queued', 'message' => 'Could not send yet (' . ($o['last_error'] ?: 'unknown error') . '); it stays queued and the cron retries it. Nothing was marked as sent.'];
        } catch (Throwable $e) {
            error_log('notifyMorningSummary: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Summary failed: ' . $e->getMessage()];
        } finally {
            $pdo->prepare("UPDATE meta SET v = '1970-01-01 00:00:00' WHERE k = 'digest_lock_until'")->execute();
        }
    }
}

if (!function_exists('notifyMarkDigest')) {
    function notifyMarkDigest(PDO $pdo, array $ids, int $digestId): void {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $pdo->prepare("UPDATE activity_log SET digest_id = ? WHERE digest_id IS NULL AND id IN ($ph)")->execute(array_merge([$digestId], $chunk));
        }
    }
}

if (!function_exists('notifyDeliverSummary')) {
    /** Outbox 'summary' row: send, then mark its activity rows (client rows → the run id, Joust rows → 0). */
    function notifyDeliverSummary(PDO $pdo, array $p): array {
        $res = notifyEmail(['to' => (string)$p['to'], 'subject' => (string)$p['subject'], 'text' => (string)$p['text'], 'html' => (string)$p['html'],
                            'message_id' => '<summary-' . (int)$p['digest_id'] . '-' . date('Ymd') . '@'
                                . notifyCfg('notify_message_domain', (string)(parse_url(notifyBaseUrl(), PHP_URL_HOST) ?: 'localhost')) . '>']);
        if (!$res['ok']) return ['ok' => false, 'error' => 'email: ' . $res['error']];
        $joust = array_map('intval', (array)($p['joust_ids'] ?? []));
        notifyMarkDigest($pdo, $joust, 0);
        notifyMarkDigest($pdo, array_diff(array_map('intval', (array)($p['activity_ids'] ?? [])), $joust), (int)$p['digest_id']);
        $pdo->prepare("UPDATE meta SET v = NOW() WHERE k = 'last_digest_sent_at'")->execute();
        return ['ok' => true, 'provider_id' => $res['message_id']];
    }
}

if (!function_exists('notifySummaryDue')) {
    /** True once a day, at / after the configured hour (America/New_York), when today's summary has not run yet. */
    function notifySummaryDue(PDO $pdo): bool {
        $set = notifySettings($pdo);
        if ((int)date('G') < $set['summary_hour']) return false;
        return notifyMeta($pdo, 'notify_summary_last', '1970-01-01') !== date('Y-m-d');
    }
}

// =====================================================================================================================
// Cron token
// =====================================================================================================================

if (!function_exists('notifyCronTokenOk')) {
    /** The presented token (?token=, X-Notify-Token, or Authorization: Bearer) equals config notify_cron_token
     *  (constant-time; a token shorter than 16 characters is treated as not configured). */
    function notifyCronTokenOk(): bool {
        $want = notifyCfg('notify_cron_token');
        if (strlen($want) < 16) return false;
        $given = '';
        if (isset($_GET['token']) && is_string($_GET['token'])) $given = $_GET['token'];
        if ($given === '') $given = (string)(notifyRequestHeader('X-Notify-Token') ?? '');
        if ($given === '' && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', (string)(notifyRequestHeader('Authorization') ?? ''), $m)) $given = $m[1];
        return $given !== '' && hash_equals($want, $given);
    }
}

// =====================================================================================================================
// Delivery log labels (Manage → Notifications)
// =====================================================================================================================

if (!function_exists('notifyKindLabel')) {
    function notifyKindLabel(string $kind): string {
        static $map = [
            'item_event' => 'Client activity', 'parent_update' => 'Status update', 'escalate_thread' => 'Reminder in thread',
            'escalate_dm' => 'Reminder DM', 'escalate_email' => 'Reminder email', 'summary' => 'Morning summary', 'slack_test' => 'Test message',
            'sign_in' => 'Sign-in link', 'email' => 'Email', 'client_email' => 'Client email', 'weekly' => 'Weekly report',
        ];
        return $map[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
    }
}
