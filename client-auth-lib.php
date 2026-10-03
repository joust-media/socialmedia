<?php
/**
 * Client sign-in: contacts, magic links, sessions, signed deep links, and the access gate every client-facing
 * page and endpoint goes through (helpers.php calls portalAccessGate()).
 *
 * Tables (migrate.php steps 40–43):
 *   client_contacts      id, company_id, email, name, link_epoch, created_at, last_login_at   UNIQUE(company_id, email)
 *   client_login_tokens  one-time magic links: token_hash (sha256), contact_id, return_path, expires_at (15 min), used_at
 *   client_sessions      30-day sign-ins: token_hash (sha256 of the jsm_client cookie), contact_id, company_id, via,
 *                        ip, user_agent, created_at, last_seen_at, expires_at, revoked_at, revoked_by
 *   auth_attempts        rate-limit ledger: scope (email | ip), key_hash, created_at
 *
 * The seat model:
 *   admin   the jsm_admin PHP session (auth.php) — sees every client; may "View as client" (adminViewAsSlug()),
 *           which renders that client's pages exactly as the client sees them (isAdmin() false there).
 *   client  the jsm_client cookie (random 256-bit token; only its sha256 is stored) → a client_sessions row that is
 *           not revoked, not expired, whose contact still exists → exactly ONE company. Every client-facing script
 *           (portalClientScripts()) refuses any other company: pages redirect to sign-in, JSON answers 401 / 403.
 *           clientOwnsCompany() (helpers.php) checks the session's company, never a posted slug.
 *
 * Interfaces for later phases (client notification emails):
 *   clientLink($slug, $path, $email, $ttlDays = 30)  absolute URL that signs that contact in and lands on $path
 *   clientContactEmails($pdo, $companyId)            the client's contact addresses
 *   clientContacts($pdo, $companyId) / clientContactsByEmail($pdo, $email)
 *   currentClientContact()                           the signed-in contact (id, email, name, company_id) or null
 *   notifyEmail($to, $subject, $html, $text, $opts)  auth-mail.php (shim) / notify-lib.php
 *
 * Function definitions only (function_exists-guarded). Needs url-lib.php.
 */
require_once __DIR__ . '/url-lib.php';

if (!defined('CLIENT_SESSION_COOKIE'))  define('CLIENT_SESSION_COOKIE', 'jsm_client');
if (!defined('CLIENT_SESSION_DAYS'))    define('CLIENT_SESSION_DAYS', 30);
if (!defined('CLIENT_MAGIC_TTL'))       define('CLIENT_MAGIC_TTL', 15 * 60);
if (!defined('CLIENT_RATE_EMAIL_MAX'))  define('CLIENT_RATE_EMAIL_MAX', 3);    // magic-link requests per address …
if (!defined('CLIENT_RATE_IP_MAX'))     define('CLIENT_RATE_IP_MAX', 10);      // … and per IP …
if (!defined('CLIENT_RATE_WINDOW'))     define('CLIENT_RATE_WINDOW', 15 * 60); // … per 15 minutes

// ---------------------------------------------------------------------
// Basics
// ---------------------------------------------------------------------

if (!function_exists('clientAuthReady')) {
    /** The four tables exist (migrate.php 40–43). Cached per request. */
    function clientAuthReady(PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('client_contacts', 'client_login_tokens', 'client_sessions', 'auth_attempts')")->fetchColumn();
            return $ready = ($n === 4);
        } catch (Throwable $e) {
            return $ready = false;
        }
    }
}

if (!function_exists('clientB64')) {
    function clientB64(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
}

if (!function_exists('clientRandomToken')) {
    /** 256 random bits, base64url (43 chars). */
    function clientRandomToken(): string { return clientB64(random_bytes(32)); }
}

if (!function_exists('clientTokenHash')) {
    function clientTokenHash(string $raw): string { return hash('sha256', $raw); }
}

if (!function_exists('clientNormalizeEmail')) {
    /** Lower-cased, trimmed address, or '' when it is not a plausible email (≤ 190 chars). */
    function clientNormalizeEmail(string $email): string {
        $e = strtolower(trim($email));
        if ($e === '' || strlen($e) > 190) return '';
        if (filter_var($e, FILTER_VALIDATE_EMAIL) === false) return '';
        if (preg_match('/[\r\n<>",;]/', $e)) return '';
        return $e;
    }
}

if (!function_exists('clientRequestIp')) {
    function clientRequestIp(): string {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}

if (!function_exists('clientCookieSecure')) {
    function clientCookieSecure(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    }
}

if (!function_exists('clientSafeReturn')) {
    /**
     * A same-app return path or '' — must start with the portal folder, never "//" or "/\", no control
     * characters or backslashes (same rule as login.php). The deep-link parameter k is dropped.
     */
    function clientSafeReturn(string $path): string {
        $base = portalBasePath();
        if ($path === '' || strlen($path) > 1000) return '';
        if (!preg_match('#^/(?![/\\\\])#', $path) || preg_match('#[\x00-\x1f\x7f\\\\]#', $path)) return '';
        if ($base !== '' && strpos($path, $base . '/') !== 0) return '';
        return clientStripParam($path, 'k');
    }
}

if (!function_exists('clientStripParam')) {
    /** $url without query parameter $name. */
    function clientStripParam(string $url, string $name): string {
        $q = strpos($url, '?');
        if ($q === false) return $url;
        $path = substr($url, 0, $q);
        $frag = '';
        $query = substr($url, $q + 1);
        if (($h = strpos($query, '#')) !== false) { $frag = substr($query, $h); $query = substr($query, 0, $h); }
        $keep = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') continue;
            $k = urldecode(explode('=', $pair, 2)[0]);
            if ($k === $name) continue;
            $keep[] = $pair;
        }
        return $path . ($keep ? '?' . implode('&', $keep) : '') . $frag;
    }
}

if (!function_exists('clientCompanyById')) {
    function clientCompanyById(PDO $pdo, int $id): ?array {
        if ($id <= 0) return null;
        $st = $pdo->prepare("SELECT id, name, slug, logo_url FROM companies WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    }
}

if (!function_exists('clientCompanyBySlug')) {
    function clientCompanyBySlug(PDO $pdo, string $slug): ?array {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($slug)));
        if ($slug === '') return null;
        $st = $pdo->prepare("SELECT id, name, slug, logo_url FROM companies WHERE slug = ?");
        $st->execute([$slug]);
        $r = $st->fetch();
        return $r ?: null;
    }
}

// ---------------------------------------------------------------------
// Contacts (Manage → Clients → Contacts)
// ---------------------------------------------------------------------

if (!function_exists('clientContacts')) {
    /** A client's contacts, oldest first: id, company_id, email, name, created_at, last_login_at, active_sessions. */
    function clientContacts(PDO $pdo, int $companyId): array {
        if (!clientAuthReady($pdo)) return [];
        $st = $pdo->prepare("SELECT c.id, c.company_id, c.email, c.name, c.created_at, c.last_login_at,
                (SELECT COUNT(*) FROM client_sessions s WHERE s.contact_id = c.id AND s.revoked_at IS NULL AND s.expires_at > NOW()) AS active_sessions
            FROM client_contacts c WHERE c.company_id = ? ORDER BY c.id ASC");
        $st->execute([$companyId]);
        return $st->fetchAll();
    }
}

if (!function_exists('clientContactEmails')) {
    /** Just the addresses (Phase 3b recipients). */
    function clientContactEmails(PDO $pdo, int $companyId): array {
        return array_values(array_map(static function ($r) { return (string)$r['email']; }, clientContacts($pdo, $companyId)));
    }
}

if (!function_exists('clientContactsByEmail')) {
    /** Every contact row for an address (one person may review for two clients), with the company. */
    function clientContactsByEmail(PDO $pdo, string $email): array {
        $email = clientNormalizeEmail($email);
        if ($email === '' || !clientAuthReady($pdo)) return [];
        $st = $pdo->prepare("SELECT c.id, c.company_id, c.email, c.name, c.link_epoch, co.slug, co.name AS company_name, co.logo_url
            FROM client_contacts c INNER JOIN companies co ON co.id = c.company_id WHERE c.email = ? ORDER BY co.name ASC");
        $st->execute([$email]);
        return $st->fetchAll();
    }
}

if (!function_exists('clientContactById')) {
    function clientContactById(PDO $pdo, int $id): ?array {
        if ($id <= 0 || !clientAuthReady($pdo)) return null;
        $st = $pdo->prepare("SELECT c.id, c.company_id, c.email, c.name, c.link_epoch, co.slug, co.name AS company_name, co.logo_url
            FROM client_contacts c INNER JOIN companies co ON co.id = c.company_id WHERE c.id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    }
}

if (!function_exists('clientContactAdd')) {
    /**
     * Add an address to a client's list. Validation: a real company, a valid address (≤ 190), not already on THIS
     * client's list, name ≤ 120 (optional), at most 50 contacts per client.
     * Returns ['ok' => true, 'contact' => row] or ['ok' => false, 'code' => 4xx, 'error' => message].
     */
    function clientContactAdd(PDO $pdo, int $companyId, string $email, string $name = ''): array {
        if (!clientAuthReady($pdo)) return ['ok' => false, 'code' => 409, 'error' => 'Run migrate.php first (client sign-in tables).'];
        if (!clientCompanyById($pdo, $companyId)) return ['ok' => false, 'code' => 404, 'error' => 'Unknown client.'];
        $norm = clientNormalizeEmail($email);
        if ($norm === '') return ['ok' => false, 'code' => 422, 'error' => 'Enter a valid email address.'];
        $name = trim(preg_replace('/\s+/', ' ', strip_tags($name)));
        if (mb_strlen($name) > 120) return ['ok' => false, 'code' => 422, 'error' => 'The name is too long (120 characters at most).'];
        $st = $pdo->prepare("SELECT COUNT(*) FROM client_contacts WHERE company_id = ?");
        $st->execute([$companyId]);
        if ((int)$st->fetchColumn() >= 50) return ['ok' => false, 'code' => 422, 'error' => 'This client already has 50 contacts.'];
        $st = $pdo->prepare("SELECT id FROM client_contacts WHERE company_id = ? AND email = ?");
        $st->execute([$companyId, $norm]);
        if ($st->fetchColumn()) return ['ok' => false, 'code' => 409, 'error' => $norm . ' is already on this client’s list.'];
        try {
            $pdo->prepare("INSERT INTO client_contacts (company_id, email, name) VALUES (?, ?, ?)")
                ->execute([$companyId, $norm, $name !== '' ? $name : null]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') return ['ok' => false, 'code' => 409, 'error' => $norm . ' is already on this client’s list.'];
            throw $e;
        }
        return ['ok' => true, 'contact' => clientContactById($pdo, (int)$pdo->lastInsertId())];
    }
}

if (!function_exists('clientContactRemove')) {
    /** Remove a contact: its sessions are revoked, its pending magic links deleted, and its deep links stop working
     *  (clientLinkVerify() needs the contact row). False when the id is not this company's. */
    function clientContactRemove(PDO $pdo, int $companyId, int $contactId): bool {
        if (!clientAuthReady($pdo)) return false;
        $st = $pdo->prepare("SELECT id FROM client_contacts WHERE id = ? AND company_id = ?");
        $st->execute([$contactId, $companyId]);
        if (!$st->fetchColumn()) return false;
        $pdo->prepare("UPDATE client_sessions SET revoked_at = NOW(), revoked_by = 'contact_removed' WHERE contact_id = ? AND revoked_at IS NULL")->execute([$contactId]);
        $pdo->prepare("DELETE FROM client_login_tokens WHERE contact_id = ?")->execute([$contactId]);
        $pdo->prepare("DELETE FROM client_contacts WHERE id = ? AND company_id = ?")->execute([$contactId, $companyId]);
        return true;
    }
}

// ---------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------

if (!function_exists('clientAuthThrottle')) {
    /** Record one attempt for (scope, key) and say whether it is within $max per $window seconds. */
    function clientAuthThrottle(PDO $pdo, string $scope, string $key, int $max, int $window): bool {
        $hash = hash('sha256', $scope . '|' . $key);
        $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope = ? AND key_hash = ? AND created_at > (NOW() - INTERVAL ? SECOND)");
        $st->execute([$scope, $hash, $window]);
        $n = (int)$st->fetchColumn();
        $pdo->prepare("INSERT INTO auth_attempts (scope, key_hash) VALUES (?, ?)")->execute([$scope, $hash]);
        if (random_int(1, 50) === 1) {
            try { $pdo->exec("DELETE FROM auth_attempts WHERE created_at < (NOW() - INTERVAL 2 DAY)"); } catch (Throwable $e) {}
        }
        return $n < $max;
    }
}

// ---------------------------------------------------------------------
// Magic links (sign-in.php)
// ---------------------------------------------------------------------

if (!function_exists('clientMagicRequest')) {
    /**
     * A sign-in request for $email. ALWAYS answers the same way to the visitor whether or not the address is known
     * (the caller shows one message for 'sent' and 'unknown'); 'limited' = too many requests for this address or IP
     * (counted for unknown addresses too, so it reveals nothing); 'invalid' = not an email address.
     * Known address → one 15-minute single-use token per matching contact (normally one), stored hashed, and
     * ['send' => callable] that mails the link(s) — the caller runs it AFTER the response is flushed, so the
     * answer takes the same time either way.
     * Returns ['status' => sent|unknown|limited|invalid|unavailable, 'send' => ?callable].
     */
    function clientMagicRequest(PDO $pdo, string $email, string $ip, string $returnPath = '', string $clientHint = ''): array {
        $out = ['status' => 'invalid', 'send' => null];
        $norm = clientNormalizeEmail($email);
        if ($norm === '') return $out;
        if (!clientAuthReady($pdo)) { $out['status'] = 'unavailable'; return $out; }
        $okEmail = clientAuthThrottle($pdo, 'email', $norm, CLIENT_RATE_EMAIL_MAX, CLIENT_RATE_WINDOW);
        $okIp    = clientAuthThrottle($pdo, 'ip', $ip, CLIENT_RATE_IP_MAX, CLIENT_RATE_WINDOW);
        if (!$okEmail || !$okIp) { $out['status'] = 'limited'; return $out; }
        $contacts = clientContactsByEmail($pdo, $norm);
        if (!$contacts) { $out['status'] = 'unknown'; return $out; }
        // A return path or client hint that names one of this person's clients narrows the email to that client.
        $hint = preg_replace('/[^a-z0-9\-]/', '', strtolower($clientHint));
        if ($hint === '' && $returnPath !== '') {
            $rel = substr($returnPath, strlen(portalBasePath()) + 1);
            $m = portalRouteMatch((string)parse_url($rel, PHP_URL_PATH));
            if ($m && !empty($m['params']['client'])) $hint = $m['params']['client'];
            elseif (preg_match('/[?&]client=([a-z0-9\-]+)/', $returnPath, $mm)) $hint = $mm[1];
        }
        if ($hint !== '') {
            $narrow = array_values(array_filter($contacts, static function ($c) use ($hint) { return $c['slug'] === $hint; }));
            if ($narrow) $contacts = $narrow;
        }
        $links = [];
        $ins = $pdo->prepare("INSERT INTO client_login_tokens (contact_id, token_hash, return_path, ip, expires_at)
                              VALUES (?, ?, ?, ?, NOW() + INTERVAL " . (int)CLIENT_MAGIC_TTL . " SECOND)");
        foreach ($contacts as $c) {
            $raw = clientRandomToken();
            $ret = ($hint !== '' && $c['slug'] === $hint) ? $returnPath : '';
            $ins->execute([(int)$c['id'], clientTokenHash($raw), $ret !== '' ? $ret : null, $ip]);
            $links[] = ['company' => (string)$c['company_name'], 'slug' => (string)$c['slug'],
                        'url' => portalAbsoluteUrl(portalUrl('sign-in', ['t' => $raw]))];
        }
        try { $pdo->exec("DELETE FROM client_login_tokens WHERE expires_at < (NOW() - INTERVAL 1 DAY)"); } catch (Throwable $e) {}
        $out['status'] = 'sent';
        $out['send'] = static function () use ($norm, $links) { return clientMagicSend($norm, $links); };
        return $out;
    }
}

if (!function_exists('clientMagicSend')) {
    /** The sign-in email (one link per client). Through notifyEmail() (notify-lib.php, else the auth-mail.php shim). */
    function clientMagicSend(string $to, array $links): bool {
        if (!function_exists('notifyEmail')) {
            if (is_file(__DIR__ . '/notify-lib.php')) require_once __DIR__ . '/notify-lib.php';
            if (!function_exists('notifyEmail')) require_once __DIR__ . '/auth-mail.php';
        }
        $mins = (int)round(CLIENT_MAGIC_TTL / 60);
        $one = count($links) === 1;
        $subject = $one ? 'Your sign-in link for ' . $links[0]['company'] . ' — Joust Media' : 'Your Joust Media sign-in links';
        $esc = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $btns = ''; $text = '';
        foreach ($links as $l) {
            $btns .= '<p style="margin:0 0 12px"><a href="' . $esc($l['url']) . '" style="display:inline-block;padding:12px 22px;border-radius:12px;background:#007AFF;color:#ffffff;text-decoration:none;font-weight:600">'
                   . ($one ? 'Sign in to the portal' : 'Sign in to ' . $esc($l['company'])) . '</a></p>';
            $text .= ($one ? '' : $l['company'] . ': ') . $l['url'] . "\n";
        }
        $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#F2F2F7;font-family:-apple-system,BlinkMacSystemFont,\'Helvetica Neue\',Arial,sans-serif;color:#000">'
              . '<div style="max-width:440px;margin:0 auto;background:#fff;border-radius:16px;padding:28px">'
              . '<p style="margin:0 0 6px;font-size:13px;color:#8E8E93;font-weight:600;letter-spacing:.3px;text-transform:uppercase">Joust Media</p>'
              . '<h1 style="margin:0 0 12px;font-size:22px">Sign in' . ($one ? ' to ' . $esc($links[0]['company']) : '') . '</h1>'
              . '<p style="margin:0 0 18px;font-size:15px;line-height:1.45;color:#3C3C43">Tap the button to open your review portal. The link works once and expires in ' . $mins . ' minutes.</p>'
              . $btns
              . '<p style="margin:18px 0 0;font-size:13px;line-height:1.45;color:#8E8E93">Didn’t ask for this? You can ignore this email — nobody can sign in without the link.</p>'
              . '</div></body></html>';
        $plain = "Sign in to your Joust Media review portal:\n\n" . $text
               . "\nThe link works once and expires in {$mins} minutes. Didn't ask for this? Ignore this email.\n";
        try {
            $r = notifyEmail($to, $subject, $html, $plain, ['kind' => 'sign_in', 'priority' => 'immediate']);
            return is_array($r) ? !empty($r['ok']) : (bool)$r;
        } catch (Throwable $e) {
            error_log('client sign-in email failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('clientMagicPeek')) {
    /** A usable magic link (not used, not expired, contact still listed) → its row + contact + company; else null. */
    function clientMagicPeek(PDO $pdo, string $raw): ?array {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $raw) || !clientAuthReady($pdo)) return null;
        $st = $pdo->prepare("SELECT t.id AS token_id, t.return_path, t.expires_at, c.id, c.company_id, c.email, c.name, c.link_epoch,
                co.slug, co.name AS company_name, co.logo_url
            FROM client_login_tokens t
            INNER JOIN client_contacts c ON c.id = t.contact_id
            INNER JOIN companies co ON co.id = c.company_id
            WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()");
        $st->execute([clientTokenHash($raw)]);
        $r = $st->fetch();
        return $r ?: null;
    }
}

if (!function_exists('clientMagicConsume')) {
    /** Use a magic link exactly once (atomic): the row as clientMagicPeek() returns it, or null (used / expired / unknown). */
    function clientMagicConsume(PDO $pdo, string $raw): ?array {
        $row = clientMagicPeek($pdo, $raw);
        if (!$row) return null;
        $st = $pdo->prepare("UPDATE client_login_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL AND expires_at > NOW()");
        $st->execute([(int)$row['token_id']]);
        return $st->rowCount() === 1 ? $row : null;
    }
}

// ---------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------

if (!function_exists('clientSetCookie')) {
    function clientSetCookie(string $value, int $expires): void {
        if (headers_sent()) return;
        setcookie(CLIENT_SESSION_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => portalBasePath() . '/',
            'domain'   => '',
            'secure'   => clientCookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('clientSessionStart')) {
    /**
     * Sign $contact (a clientContactById() row) in for CLIENT_SESSION_DAYS: a new random cookie token and a new
     * client_sessions row (the previous one of this browser is revoked — a fresh id on every sign-in, and any PHP
     * session is regenerated too). $via = magic | link | test. Returns the session row id.
     */
    function clientSessionStart(PDO $pdo, array $contact, string $via = 'magic'): int {
        $old = (string)($_COOKIE[CLIENT_SESSION_COOKIE] ?? '');
        if ($old !== '') {
            try { $pdo->prepare("UPDATE client_sessions SET revoked_at = NOW(), revoked_by = 'replaced' WHERE token_hash = ? AND revoked_at IS NULL")->execute([clientTokenHash($old)]); }
            catch (Throwable $e) {}
        }
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) session_regenerate_id(true);
        $raw = clientRandomToken();
        $ua = substr(preg_replace('/[\x00-\x1f\x7f]/', '', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
        $pdo->prepare("INSERT INTO client_sessions (contact_id, company_id, token_hash, via, ip, user_agent, last_seen_at, expires_at)
                       VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL " . (int)CLIENT_SESSION_DAYS . " DAY)")
            ->execute([(int)$contact['id'], (int)$contact['company_id'], clientTokenHash($raw), substr($via, 0, 12), clientRequestIp(), $ua]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE client_contacts SET last_login_at = NOW() WHERE id = ?")->execute([(int)$contact['id']]);
        clientSetCookie($raw, time() + CLIENT_SESSION_DAYS * 86400);
        $_COOKIE[CLIENT_SESSION_COOKIE] = $raw;
        clientSessionReset();
        return $id;
    }
}

if (!function_exists('clientSessionReset')) {
    function clientSessionReset(): void { $GLOBALS['__clientSessionCache'] = null; unset($GLOBALS['__clientSessionCache']); }
}

if (!function_exists('currentClientSession')) {
    /**
     * The signed-in client of this request: ['session_id', 'contact_id', 'company_id', 'slug', 'company_name',
     * 'email', 'name', 'expires_at', 'via'] or null (no cookie, unknown / revoked / expired row, contact removed).
     */
    function currentClientSession(?PDO $pdo = null): ?array {
        if (array_key_exists('__clientSessionCache', $GLOBALS)) return $GLOBALS['__clientSessionCache'];
        $pdo = $pdo ?: (($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null);
        $raw = (string)($_COOKIE[CLIENT_SESSION_COOKIE] ?? '');
        if (!$pdo || $raw === '' || !preg_match('/^[A-Za-z0-9_-]{40,64}$/', $raw) || !clientAuthReady($pdo)) {
            return $GLOBALS['__clientSessionCache'] = null;
        }
        try {
            $st = $pdo->prepare("SELECT s.id AS session_id, s.contact_id, s.company_id, s.expires_at, s.via, s.last_seen_at,
                    c.email, c.name, co.slug, co.name AS company_name, co.logo_url
                FROM client_sessions s
                INNER JOIN client_contacts c ON c.id = s.contact_id AND c.company_id = s.company_id
                INNER JOIN companies co ON co.id = s.company_id
                WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()");
            $st->execute([clientTokenHash($raw)]);
            $row = $st->fetch() ?: null;
            if ($row && (strtotime((string)$row['last_seen_at']) ?: 0) < time() - 300) {
                $pdo->prepare("UPDATE client_sessions SET last_seen_at = NOW() WHERE id = ?")->execute([(int)$row['session_id']]);
            }
        } catch (Throwable $e) {
            error_log('client session lookup failed: ' . $e->getMessage());
            $row = null;
        }
        return $GLOBALS['__clientSessionCache'] = $row;
    }
}

if (!function_exists('currentClientContact')) {
    /** The signed-in contact (id, email, name, company_id, slug) or null — for "who said what" in later phases. */
    function currentClientContact(): ?array {
        $s = currentClientSession();
        if (!$s) return null;
        return ['id' => (int)$s['contact_id'], 'email' => (string)$s['email'], 'name' => (string)($s['name'] ?? ''),
                'company_id' => (int)$s['company_id'], 'slug' => (string)$s['slug']];
    }
}

if (!function_exists('clientSessionsForCompany')) {
    /** Active sessions of a client (Manage → Clients → Signed in), newest first. */
    function clientSessionsForCompany(PDO $pdo, int $companyId): array {
        if (!clientAuthReady($pdo)) return [];
        $st = $pdo->prepare("SELECT s.id, s.contact_id, s.via, s.ip, s.user_agent, s.created_at, s.last_seen_at, s.expires_at, c.email, c.name
            FROM client_sessions s INNER JOIN client_contacts c ON c.id = s.contact_id
            WHERE s.company_id = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()
            ORDER BY s.last_seen_at DESC, s.id DESC LIMIT 200");
        $st->execute([$companyId]);
        return $st->fetchAll();
    }
}

if (!function_exists('clientDeviceLabel')) {
    /** "iPhone · Safari", "Mac · Chrome", "Windows · Edge" … from a User-Agent (Manage → Clients → Signed in). */
    function clientDeviceLabel(string $ua): string {
        if ($ua === '') return 'Unknown device';
        $os = 'Device';
        foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh' => 'Mac',
                  'CrOS' => 'Chromebook', 'Linux' => 'Linux'] as $needle => $label) {
            if (stripos($ua, $needle) !== false) { $os = $label; break; }
        }
        $br = '';
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'CriOS/' => 'Chrome', 'Chrome/' => 'Chrome',
                  'Safari/' => 'Safari', 'curl/' => 'curl'] as $needle => $label) {
            if (stripos($ua, $needle) !== false) { $br = $label; break; }
        }
        return $br !== '' ? $os . ' · ' . $br : $os;
    }
}

if (!function_exists('clientSessionRevoke')) {
    /** Revoke one session of $companyId (admin). True when a live row was revoked. */
    function clientSessionRevoke(PDO $pdo, int $companyId, int $sessionId, string $by = 'admin'): bool {
        if (!clientAuthReady($pdo)) return false;
        $st = $pdo->prepare("UPDATE client_sessions SET revoked_at = NOW(), revoked_by = ? WHERE id = ? AND company_id = ? AND revoked_at IS NULL");
        $st->execute([substr($by, 0, 20), $sessionId, $companyId]);
        return $st->rowCount() > 0;
    }
}

if (!function_exists('clientSessionsRevokeAll')) {
    /** Sign a whole client (or one contact of it) out everywhere; $killLinks also voids that contact's emailed deep
     *  links (link_epoch + 1). Returns the number of sessions revoked. */
    function clientSessionsRevokeAll(PDO $pdo, int $companyId, int $contactId = 0, bool $killLinks = false): int {
        if (!clientAuthReady($pdo)) return 0;
        if ($contactId > 0) {
            $st = $pdo->prepare("UPDATE client_sessions SET revoked_at = NOW(), revoked_by = 'admin' WHERE company_id = ? AND contact_id = ? AND revoked_at IS NULL");
            $st->execute([$companyId, $contactId]);
            if ($killLinks) $pdo->prepare("UPDATE client_contacts SET link_epoch = link_epoch + 1 WHERE id = ? AND company_id = ?")->execute([$contactId, $companyId]);
        } else {
            $st = $pdo->prepare("UPDATE client_sessions SET revoked_at = NOW(), revoked_by = 'admin' WHERE company_id = ? AND revoked_at IS NULL");
            $st->execute([$companyId]);
            if ($killLinks) $pdo->prepare("UPDATE client_contacts SET link_epoch = link_epoch + 1 WHERE company_id = ?")->execute([$companyId]);
        }
        return $st->rowCount();
    }
}

if (!function_exists('clientSignOut')) {
    /** Revoke this browser's session and drop the cookie. */
    function clientSignOut(PDO $pdo): void {
        $raw = (string)($_COOKIE[CLIENT_SESSION_COOKIE] ?? '');
        if ($raw !== '' && clientAuthReady($pdo)) {
            try { $pdo->prepare("UPDATE client_sessions SET revoked_at = NOW(), revoked_by = 'sign_out' WHERE token_hash = ? AND revoked_at IS NULL")->execute([clientTokenHash($raw)]); }
            catch (Throwable $e) { error_log('client sign-out failed: ' . $e->getMessage()); }
        }
        clientSetCookie('', time() - 42000);
        unset($_COOKIE[CLIENT_SESSION_COOKIE]);
        clientSessionReset();
    }
}

// ---------------------------------------------------------------------
// Signed deep links (emails → one tap into the right item)
// ---------------------------------------------------------------------

if (!function_exists('clientLinkSecret')) {
    /** HMAC key for deep links: config 'client_link_secret' (≥ 32 chars recommended); else derived from the DB
     *  credentials (works, but set the key so rotating it voids every link at once). */
    function clientLinkSecret(): string {
        $own = trim((string)portalConfig('client_link_secret', ''));
        if ($own !== '') return hash('sha256', 'joust-client-link|' . $own);
        return hash('sha256', 'joust-client-link|' . (string)portalConfig('password', '') . '|' . (string)portalConfig('dbname', '') . '|' . __DIR__);
    }
}

if (!function_exists('clientLinkSignature')) {
    function clientLinkSignature(int $contactId, int $companyId, string $email, int $exp, int $epoch): string {
        $msg = 'v1|' . $companyId . '|' . $contactId . '|' . strtolower($email) . '|' . $exp . '|' . $epoch;
        return substr(clientB64(hash_hmac('sha256', $msg, clientLinkSecret(), true)), 0, 32);
    }
}

if (!function_exists('clientLink')) {
    /**
     * An absolute URL that signs $email (a contact of $clientSlug) in and lands on $path — a path inside that
     * client's portal: '' (Home), 'posts/12', 'emails/5', 'tires/3', 'pages/2', … (the clean-link map; printed in
     * the query-string form when clean links are off). Token = <contact id>.<expiry>.<HMAC over company, contact,
     * email, expiry and the contact's link epoch> in ?k=. Valid for $ttlDays (1–90), reusable until then, dead as
     * soon as the contact is removed, its email changes, or "Sign out everywhere" bumps its epoch.
     * Returns '' (and logs) when the address is not on that client's list.
     */
    function clientLink(string $clientSlug, string $path, string $email, int $ttlDays = 30): string {
        $pdo = ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null;
        if (!$pdo) { error_log('clientLink: no database'); return ''; }
        $co = clientCompanyBySlug($pdo, $clientSlug);
        $norm = clientNormalizeEmail($email);
        if (!$co || $norm === '') { error_log('clientLink: unknown client or address'); return ''; }
        $st = $pdo->prepare("SELECT id, company_id, email, link_epoch FROM client_contacts WHERE company_id = ? AND email = ?");
        $st->execute([(int)$co['id'], $norm]);
        $c = $st->fetch();
        if (!$c) { error_log('clientLink: ' . $norm . ' is not a contact of ' . $co['slug']); return ''; }
        $ttlDays = max(1, min(90, $ttlDays));
        $exp = time() + $ttlDays * 86400;
        $token = (int)$c['id'] . '.' . $exp . '.' . clientLinkSignature((int)$c['id'], (int)$c['company_id'], (string)$c['email'], $exp, (int)$c['link_epoch']);
        // Where to land: the clean-link map, so 'posts/12' works in both URL styles.
        $rel = trim((string)parse_url($path, PHP_URL_PATH), '/');
        parse_str((string)parse_url($path, PHP_URL_QUERY), $query);
        $hit = portalRouteMatch($co['slug'] . '/' . $rel . ($rel === '' ? '/' : ''));
        if (!$hit || ($hit['params']['client'] ?? '') !== $co['slug']) $hit = ['script' => 'index', 'params' => ['client' => $co['slug']]];
        $params = $hit['params'] + (is_array($query) ? $query : []);
        unset($params['k']);
        $params['k'] = $token;
        return portalAbsoluteUrl(portalUrl($hit['script'], $params));
    }
}

if (!function_exists('clientLinkVerify')) {
    /** A deep-link token → the contact (clientContactById() row + 'exp') or null (malformed, forged, expired, removed, epoch bumped). */
    function clientLinkVerify(PDO $pdo, string $token): ?array {
        if (!preg_match('/^([1-9][0-9]{0,9})\.([0-9]{9,11})\.([A-Za-z0-9_-]{32})$/', $token, $m)) return null;
        $exp = (int)$m[2];
        if ($exp < time() || $exp > time() + 91 * 86400) return null;
        $c = clientContactById($pdo, (int)$m[1]);
        if (!$c) return null;
        $want = clientLinkSignature((int)$c['id'], (int)$c['company_id'], (string)$c['email'], $exp, (int)$c['link_epoch']);
        if (!hash_equals($want, $m[3])) return null;
        $c['exp'] = $exp;
        return $c;
    }
}

// ---------------------------------------------------------------------
// Admin "View as client"
// ---------------------------------------------------------------------

if (!function_exists('adminViewAsSlug')) {
    /** The client slug the admin is previewing as (Manage → Clients → View as client), or ''. */
    function adminViewAsSlug(): string {
        if (!function_exists('currentAdmin') || !currentAdmin()) return '';
        $v = $_SESSION['view_as'] ?? '';
        return is_string($v) ? $v : '';
    }
}

// ---------------------------------------------------------------------
// The gate
// ---------------------------------------------------------------------

if (!function_exists('portalClientScripts')) {
    /**
     * Every client-facing script (pages + the endpoints a client may call). The gate runs for these only;
     * admin-only scripts keep their own requireAdmin() / currentAdmin() checks (they send to login.php), and
     * machine endpoints (drive-ingest, digest cron, Slack / notify endpoints of later phases) are never gated here.
     */
    function portalClientScripts(): array {
        return ['index', 'assets', 'posts', 'emails', 'pages', 'flows', 'projects',
                'feed', 'library', 'tires', 'features', 'add-project', 'add-tire',
                'status', 'email-status', 'page-status', 'flow-status', 'library-status', 'tire-status', 'task'];
    }
}

if (!function_exists('portalJsonScripts')) {
    function portalJsonScripts(): array {
        return ['status', 'email-status', 'page-status', 'flow-status', 'library-status', 'tire-status', 'task'];
    }
}

if (!function_exists('portalWantsJson')) {
    /** JSON endpoint, fetch()/XHR, a POST or a partial — answer 401 / 403 JSON rather than a redirect. */
    function portalWantsJson(string $script): bool {
        if (in_array($script, portalJsonScripts(), true)) return true;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return true;
        if (isset($_GET['partial']) || isset($_GET['format'])) return true;
        if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') return true;
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false) return true;
        $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
        if ($mode !== '' && $mode !== 'navigate') return true;
        return false;
    }
}

if (!function_exists('portalSignInUrl')) {
    /** The sign-in page with a return path, a reason (signin | other | expired) and the client (for its logo). */
    function portalSignInUrl(string $return = '', string $reason = '', string $slug = ''): string {
        $p = [];
        if ($slug !== '') $p['client'] = $slug;
        if ($return !== '' && ($safe = clientSafeReturn($return)) !== '') $p['return'] = $safe;
        if ($reason !== '') $p['reason'] = $reason;
        return portalUrl('sign-in', $p);
    }
}

if (!function_exists('portalDeny')) {
    /** Stop: JSON 401 (nobody signed in) / 403 (signed in for another client), or a redirect to sign-in. */
    function portalDeny(string $script, int $code, string $reason, string $slug): void {
        $return = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (portalWantsJson($script)) {
            http_response_code($code);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => false, 'error' => $code === 401 ? 'Please sign in to continue.' : 'This belongs to another client.',
                              'signIn' => portalSignInUrl($return, $reason, $slug)], JSON_UNESCAPED_SLASHES);
            exit;
        }
        header('Cache-Control: no-store');
        header('Location: ' . portalSignInUrl(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? $return : '', $reason, $slug), true, 302);
        exit;
    }
}

if (!function_exists('portalCanonicalRedirect')) {
    /** Clean links on + an old query-string page URL (GET, a real navigation, not routed) → 301 to the clean form. */
    function portalCanonicalRedirect(string $script): void {
        if (!cleanUrlsOn() || defined('PORTAL_ROUTED')) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        if (!in_array($script, ['index', 'posts', 'assets', 'emails', 'pages', 'flows', 'projects', 'build', 'manage',
                                'add-email', 'add-page', 'add-feature'], true)) return;
        if (portalWantsJson($script) || isset($_GET['action'])) return;
        $cur = portalCurrentRequest();
        $target = portalUrl($script, $cur['params']);
        $now = (string)($_SERVER['REQUEST_URI'] ?? '');
        $nowPath = (string)parse_url($now, PHP_URL_PATH);
        $tgtPath = (string)parse_url($target, PHP_URL_PATH);
        if ($tgtPath === $nowPath) return;   // already canonical (e.g. /portal/ for the unscoped Home)
        header('Location: ' . $target, true, 301);
        exit;
    }
}

if (!function_exists('portalAccessGate')) {
    /**
     * Run by helpers.php before $role is decided. For a client-facing script:
     *   1. old query-string URL + clean links on → 301 to the clean URL (every mapped page, admin ones included)
     *   2. ?k=<deep link> → sign that contact in (unless this browser is already signed in for that client) and
     *      reload without k; a dead link → sign-in ("expired")
     *   3. the admin passes (View as client included)
     *   4. a client session passes for ITS company only; with no ?client= it scopes the request to its company
     *   5. anything else → sign-in page (pages) or 401 / 403 JSON (endpoints, fetches, POSTs)
     * $client / $clientSlug are the helpers.php globals (by reference); $askedSlug = the raw ?client= value.
     */
    function portalAccessGate(PDO $pdo, ?array &$client, string &$clientSlug, string $askedSlug, callable $loadClient): void {
        $script = portalScriptName(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')));
        portalCanonicalRedirect($script);   // admin pages too (manage, build, add-*): old URL → clean URL
        if (!in_array($script, portalClientScripts(), true)) return;
        if (defined('PORTAL_GATE_OFF')) return;

        $isAdminSeat = function_exists('currentAdmin') && currentAdmin();

        // 2. Signed deep link
        $k = $_GET['k'] ?? null;
        if (is_string($k) && $k !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $here = clientStripParam((string)($_SERVER['REQUEST_URI'] ?? portalUrl('index')), 'k');
            if (!$isAdminSeat) {
                $contact = clientAuthReady($pdo) ? clientLinkVerify($pdo, $k) : null;
                if (!$contact || ($client && (int)$client['id'] !== (int)$contact['company_id'])) {
                    portalDeny($script, 401, 'expired', $client['slug'] ?? $askedSlug);
                }
                $sess = currentClientSession($pdo);
                if (!$sess || (int)$sess['company_id'] !== (int)$contact['company_id']) {
                    clientSessionStart($pdo, $contact, 'link');
                }
            }
            header('Cache-Control: no-store');
            header('Location: ' . $here, true, 302);
            exit;
        }

        if ($isAdminSeat) return;

        $sess = currentClientSession($pdo);
        if ($askedSlug !== '') {
            if ($sess && $client && (int)$sess['company_id'] === (int)$client['id']) return;
            portalDeny($script, $sess ? 403 : 401, $sess ? 'other' : 'signin', $client['slug'] ?? '');
        }
        if ($sess) {
            // No ?client= → the signed-in client's own scope.
            $row = $loadClient((string)$sess['slug']);
            if ($row) {
                if (!portalWantsJson($script) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                    header('Cache-Control: no-store');
                    header('Location: ' . portalUrl($script, ['client' => (string)$row['slug']] + portalCurrentRequest()['params']), true, 302);
                    exit;
                }
                $client = $row; $clientSlug = (string)$row['slug']; $_GET['client'] = $clientSlug;
                return;
            }
        }
        portalDeny($script, 401, 'signin', '');
    }
}
