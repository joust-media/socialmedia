<?php
/**
 * Email through Google Workspace (the Gmail API over HTTPS — GoDaddy may block SMTP) and replies back into the portal.
 * Loaded by helpers.php after notify-lib.php; function definitions only (function_exists-guarded), no work at load.
 *
 * ── Connection (Manage → Notifications → Google; google-oauth.php) ───────────────────────────────────────────────────
 *   An OAuth 2.0 web client Lance creates in HIS Google Cloud project as an "Internal" app (Workspace users only, so
 *   Google needs no app verification). config.php: google_client_id, google_client_secret, google_token_key (the
 *   secret the token encryption key is derived from). Scopes:
 *     gmail.send    send as lance@joustmedia.com
 *     gmail.modify  read the replies that arrive at lance+ai@joustmedia.com and label them "portal-processed" so they
 *                   are never imported twice (gmail.readonly cannot add labels; modify can NOT delete mail for good)
 *   access_type=offline + prompt=consent → a refresh token. The connected account must be the sender (config
 *   notify_from, default lance@joustmedia.com). The refresh token and the cached access token are stored ENCRYPTED in
 *   google_account (migrate.php 45): libsodium secretbox when available, else AES-256-GCM (openssl); the key is
 *   HKDF-SHA256(google_token_key). Nothing secret is ever printed.
 *
 * ── Sending ─────────────────────────────────────────────────────────────────────────────────────────────────────────
 *   notifyMailSend_gmail($msg) — the 'gmail' transport of notifyEmail() (notify-lib.php): an RFC 5322 message built by
 *   notifyMimeBuild() (multipart/alternative text + HTML, UTF-8, quoted-printable, From / Reply-To / Message-ID /
 *   In-Reply-To / References / List-Unsubscribe), base64url → POST users/me/messages/send (8 s curl timeout).
 *   notifyMailTransport() picks the transport: config mail_transport when set ('mail' | 'gmail' | 'sink'); blank /
 *   'auto' = 'gmail' once Google is connected, else PHP mail() (the fallback until Lance connects Google).
 *
 * ── Replies in (notify-cron.php → gmailPollInbound()) ────────────────────────────────────────────────────────────────
 *   Polls `to:<inbound address> -label:portal-processed newer_than:14d`; each message (deduped by Gmail id in
 *   email_inbound, migrate.php 46) is matched to a portal item by In-Reply-To / References against the Message-IDs the
 *   portal sent (notify_email_refs), else by the signed [J#xxxxxx] subject token (notify_threads.email_token). The
 *   sender must be a contact of THAT item's client (or a Joust team member); quoted history and signatures are cut
 *   (Gmail / Apple "On … wrote:", Outlook "-----Original Message-----" / "From: … Sent:", "> " lines, "-- ");
 *   the rest is posted as a comment by that person through logActivity() (so Slack hears about it like any comment).
 *   Attachments are never imported ("(attachment not imported)"). No match or an unknown sender → nothing is posted;
 *   the message waits in Manage → Notifications → Unmatched email replies (Assign to item / Dismiss).
 *
 * Test seams: config google_api_base (default https://gmail.googleapis.com), google_oauth_base (token / revoke,
 * default https://oauth2.googleapis.com; when set, the consent page is <base>/o/oauth2/v2/auth too) —
 * tests/google-stub.php implements both.
 */

if (!defined('GOOGLE_HTTP_TIMEOUT')) define('GOOGLE_HTTP_TIMEOUT', 8);
if (!defined('GOOGLE_SCOPES')) define('GOOGLE_SCOPES', 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.modify');
if (!defined('GOOGLE_LABEL')) define('GOOGLE_LABEL', 'portal-processed');

// =====================================================================================================================
// Config
// =====================================================================================================================

if (!function_exists('googleApiBase')) {
    function googleApiBase(): string { return rtrim(notifyCfg('google_api_base', 'https://gmail.googleapis.com'), '/'); }
}
if (!function_exists('googleOauthBase')) {
    function googleOauthBase(): string { return rtrim(notifyCfg('google_oauth_base', 'https://oauth2.googleapis.com'), '/'); }
}
if (!function_exists('googleAuthUrl')) {
    /** The consent page: accounts.google.com, or <google_oauth_base>/o/oauth2/v2/auth when a test base is configured. */
    function googleAuthUrl(): string {
        return notifyCfg('google_oauth_base') !== '' ? googleOauthBase() . '/o/oauth2/v2/auth' : 'https://accounts.google.com/o/oauth2/v2/auth';
    }
}
if (!function_exists('googleRedirectUri')) {
    /** https://joustmedia.com/portal/google-oauth — extensionless, like every machine endpoint (notifyMachineUrl()). */
    function googleRedirectUri(): string { return notifyMachineUrl('google-oauth'); }
}
if (!function_exists('googleConfigured')) {
    /** The three config.php keys are set (client id, secret, token key ≥ 16 chars). */
    function googleConfigured(): bool {
        return notifyCfg('google_client_id') !== '' && notifyCfg('google_client_secret') !== '' && strlen(notifyCfg('google_token_key')) >= 16;
    }
}
if (!function_exists('googleExpectedAccount')) {
    /** The only mailbox that may be connected: the sender of every portal email (notify_from, default lance@joustmedia.com). */
    function googleExpectedAccount(): string { return strtolower(notifyMailSender()[0]); }
}
if (!function_exists('inboundAddress')) {
    /** Where client replies go (the Reply-To of client emails, polled by the cron): config inbound_address, default
     *  lance+ai@joustmedia.com (Workspace delivers plus-addresses to lance@ by default). */
    function inboundAddress(): string {
        $a = strtolower(notifyCfg('inbound_address', 'lance+ai@joustmedia.com'));
        return filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : 'lance+ai@joustmedia.com';
    }
}

// =====================================================================================================================
// Token encryption at rest
// =====================================================================================================================

if (!function_exists('googleTokenKey')) {
    /** 32-byte key = HKDF-SHA256(config google_token_key); null when the secret is missing / too short. */
    function googleTokenKey(): ?string {
        $s = notifyCfg('google_token_key');
        if (strlen($s) < 16) return null;
        return hash_hkdf('sha256', $s, 32, 'joust-portal google token v1');
    }
}

if (!function_exists('googleEncrypt')) {
    /** 's1:' + base64(nonce ‖ secretbox) (libsodium) or 'g1:' + base64(iv ‖ tag ‖ ciphertext) (AES-256-GCM).
     *  $method 'sodium' | 'openssl' forces one (tests); default = sodium when available. Throws without a key. */
    function googleEncrypt(string $plain, string $method = ''): string {
        $key = googleTokenKey();
        if ($key === null) throw new RuntimeException('google_token_key is not set');
        $useSodium = $method === 'sodium' || ($method === '' && function_exists('sodium_crypto_secretbox'));
        if ($useSodium) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'joust-google', 16);
        if ($ct === false) throw new RuntimeException('openssl encryption failed');
        return 'g1:' . base64_encode($iv . $tag . $ct);
    }
}

if (!function_exists('googleDecrypt')) {
    /** The plaintext, or null (no key, wrong key, tampered, unknown format). */
    function googleDecrypt(string $blob): ?string {
        $key = googleTokenKey();
        if ($key === null || strlen($blob) < 4) return null;
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false) return null;
        if (strncmp($blob, 's1:', 3) === 0) {
            if (!function_exists('sodium_crypto_secretbox_open') || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
            $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
            return $p === false ? null : $p;
        }
        if (strncmp($blob, 'g1:', 3) === 0) {
            if (strlen($raw) < 29) return null;
            $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'joust-google');
            return $p === false ? null : $p;
        }
        return null;
    }
}

// =====================================================================================================================
// Account row
// =====================================================================================================================

if (!function_exists('googleReady')) {
    /** migrate.php 45–46 ran. Cached per request. */
    function googleReady(?PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!$pdo) return false;
        try {
            return $ready = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('google_account','email_inbound','notify_email_refs')")->fetchColumn() === 3;
        } catch (Throwable $e) {
            return $ready = false;
        }
    }
}

if (!function_exists('googleAccount')) {
    /** The connected account row (never the decrypted tokens) or null. $fresh re-reads it. */
    function googleAccount(?PDO $pdo, bool $fresh = false): ?array {
        if (!$pdo || !googleReady($pdo)) return null;
        if (!$fresh && array_key_exists('__googleAccount', $GLOBALS)) return $GLOBALS['__googleAccount'];
        try {
            $r = $pdo->query("SELECT * FROM google_account WHERE id = 1")->fetch() ?: null;
        } catch (Throwable $e) {
            $r = null;
        }
        return $GLOBALS['__googleAccount'] = $r;
    }
}

if (!function_exists('googleConnected')) {
    /** A refresh token is stored and the config keys are present (sending can be attempted). */
    function googleConnected(?PDO $pdo = null): bool {
        $pdo = $pdo ?: (($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null);
        return googleConfigured() && googleAccount($pdo) !== null;
    }
}

if (!function_exists('googleHealth')) {
    /** Record the outcome of a Google call (Manage → Notifications shows last success / last error). */
    function googleHealth(PDO $pdo, bool $ok, string $error = ''): void {
        try {
            if ($ok) $pdo->exec("UPDATE google_account SET last_success_at = NOW() WHERE id = 1");
            else $pdo->prepare("UPDATE google_account SET last_error = ?, last_error_at = NOW() WHERE id = 1")->execute([substr($error, 0, 500)]);
        } catch (Throwable $e) {
            error_log('googleHealth: ' . $e->getMessage());
        }
        unset($GLOBALS['__googleAccount']);
    }
}

if (!function_exists('googleDisconnect')) {
    /** Revoke the refresh token at Google (best effort) and forget the account. */
    function googleDisconnect(PDO $pdo): bool {
        $acc = googleAccount($pdo, true);
        if (!$acc) return false;
        $rt = googleDecrypt((string)$acc['refresh_token_enc']);
        if ($rt !== null && $rt !== '') googleHttp('POST', googleOauthBase() . '/revoke', ['token' => $rt], [], true);
        $pdo->exec("DELETE FROM google_account WHERE id = 1");
        unset($GLOBALS['__googleAccount']);
        return true;
    }
}

// =====================================================================================================================
// HTTP
// =====================================================================================================================

if (!function_exists('googleHttp')) {
    /**
     * One HTTPS call to Google with short timeouts (3 s connect, GOOGLE_HTTP_TIMEOUT total). $body: array (JSON, or a
     * form when $form) or null. → ['ok' (2xx), 'http', 'data' (decoded JSON), 'error' (Google's message)].
     * Tokens are never logged.
     */
    function googleHttp(string $method, string $url, ?array $body = null, array $headers = [], bool $form = false, int $timeout = GOOGLE_HTTP_TIMEOUT): array {
        if (!function_exists('curl_init')) return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'PHP curl extension missing'];
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'JoustPortal-Gmail/1',
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $form ? http_build_query($body) : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json; charset=utf-8';
        }
        $headers[] = 'Accept: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'network: ' . $err];
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) $data = [];
        $ok = $code >= 200 && $code < 300;
        $error = '';
        if (!$ok) {
            $e = $data['error'] ?? null;
            if (is_array($e)) $error = (string)($e['message'] ?? $e['status'] ?? ('HTTP ' . $code));
            elseif (is_string($e)) $error = $e . (isset($data['error_description']) ? ': ' . $data['error_description'] : '');
            else $error = 'HTTP ' . $code;
        }
        return ['ok' => $ok, 'http' => $code, 'data' => $data, 'error' => $error];
    }
}

// =====================================================================================================================
// OAuth
// =====================================================================================================================

if (!function_exists('googleOauthStart')) {
    /** The consent URL for a fresh state (kept in the admin's PHP session for 10 minutes; single use). */
    function googleOauthStart(): string {
        $state = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $_SESSION['google_oauth'] = ['state' => $state, 'exp' => time() + 600];
        return googleAuthUrl() . '?' . http_build_query([
            'client_id'              => notifyCfg('google_client_id'),
            'redirect_uri'           => googleRedirectUri(),
            'response_type'          => 'code',
            'scope'                  => GOOGLE_SCOPES,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'login_hint'             => googleExpectedAccount(),
            'state'                  => $state,
        ]);
    }
}

if (!function_exists('googleOauthStateOk')) {
    /** The state Google sent back equals the one this admin session started (constant time, unexpired); consumed. */
    function googleOauthStateOk(string $state): bool {
        $want = $_SESSION['google_oauth'] ?? null;
        unset($_SESSION['google_oauth']);
        if (!is_array($want) || empty($want['state']) || (int)($want['exp'] ?? 0) < time() || $state === '') return false;
        return hash_equals((string)$want['state'], $state);
    }
}

if (!function_exists('googleOauthFinish')) {
    /**
     * Exchange the code, check the scopes and the account (users/me/profile must be the sender address), store the
     * tokens encrypted. → ['ok' => bool, 'error' => message for Lance, 'email' => connected address].
     */
    function googleOauthFinish(PDO $pdo, string $code, string $adminEmail = ''): array {
        if (!googleConfigured()) return ['ok' => false, 'error' => 'Add google_client_id, google_client_secret and google_token_key to config.php first.'];
        $r = googleHttp('POST', googleOauthBase() . '/token', [
            'code' => $code, 'client_id' => notifyCfg('google_client_id'), 'client_secret' => notifyCfg('google_client_secret'),
            'redirect_uri' => googleRedirectUri(), 'grant_type' => 'authorization_code',
        ], [], true);
        if (!$r['ok']) return ['ok' => false, 'error' => 'Google refused the sign-in (' . $r['error'] . ').'];
        $access = (string)($r['data']['access_token'] ?? '');
        $refresh = (string)($r['data']['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') return ['ok' => false, 'error' => 'Google did not hand out offline access — try Connect Google again.'];
        $granted = preg_split('/\s+/', trim((string)($r['data']['scope'] ?? GOOGLE_SCOPES)));
        foreach (explode(' ', GOOGLE_SCOPES) as $need) {
            if (!in_array($need, $granted, true)) {
                googleHttp('POST', googleOauthBase() . '/revoke', ['token' => $refresh], [], true);
                return ['ok' => false, 'error' => 'Both permissions are needed (send email, and read + label replies). Tick both boxes on Google’s screen.'];
            }
        }
        $p = googleHttp('GET', googleApiBase() . '/gmail/v1/users/me/profile', null, ['Authorization: Bearer ' . $access]);
        $email = strtolower((string)($p['data']['emailAddress'] ?? ''));
        if (!$p['ok'] || $email === '') {
            googleHttp('POST', googleOauthBase() . '/revoke', ['token' => $refresh], [], true);
            return ['ok' => false, 'error' => 'Could not read the Gmail profile (' . ($p['error'] ?: 'no address') . ').'];
        }
        $want = googleExpectedAccount();
        if ($email !== $want) {
            googleHttp('POST', googleOauthBase() . '/revoke', ['token' => $refresh], [], true);
            return ['ok' => false, 'error' => 'That was ' . $email . ' — connect ' . $want . ' (the address portal emails come from).'];
        }
        $exp = date('Y-m-d H:i:s', time() + max(60, (int)($r['data']['expires_in'] ?? 3600)) - 60);
        $pdo->prepare("REPLACE INTO google_account (id, account_email, refresh_token_enc, access_token_enc, access_expires_at, scopes, connected_at, connected_by, last_success_at)
                       VALUES (1, ?, ?, ?, ?, ?, NOW(), ?, NOW())")
            ->execute([$email, googleEncrypt($refresh), googleEncrypt($access), $exp, implode(' ', $granted), substr($adminEmail, 0, 190) ?: null]);
        unset($GLOBALS['__googleAccount']);
        return ['ok' => true, 'error' => '', 'email' => $email];
    }
}

if (!function_exists('googleAccessToken')) {
    /** A valid access token (cached encrypted until 60 s before expiry; refreshed with the refresh token). $force skips
     *  the cache (after a 401). → ['ok', 'token', 'error', 'permanent' (access revoked → reconnect)]. */
    function googleAccessToken(PDO $pdo, bool $force = false): array {
        $acc = googleAccount($pdo, true);
        if (!$acc || !googleConfigured()) return ['ok' => false, 'token' => '', 'error' => 'Google is not connected', 'permanent' => true];
        if (!$force && !empty($acc['access_token_enc']) && strtotime((string)$acc['access_expires_at']) > time()) {
            $t = googleDecrypt((string)$acc['access_token_enc']);
            if ($t !== null && $t !== '') return ['ok' => true, 'token' => $t, 'error' => ''];
        }
        $rt = googleDecrypt((string)$acc['refresh_token_enc']);
        if ($rt === null || $rt === '') {
            googleHealth($pdo, false, 'The stored token cannot be decrypted (google_token_key changed?) — reconnect Google.');
            return ['ok' => false, 'token' => '', 'error' => 'stored token unreadable — reconnect Google', 'permanent' => true];
        }
        $r = googleHttp('POST', googleOauthBase() . '/token', ['client_id' => notifyCfg('google_client_id'),
            'client_secret' => notifyCfg('google_client_secret'), 'refresh_token' => $rt, 'grant_type' => 'refresh_token'], [], true);
        if (!$r['ok'] || empty($r['data']['access_token'])) {
            $revoked = ($r['data']['error'] ?? '') === 'invalid_grant';
            $msg = $revoked ? 'Google access was revoked or expired — press Connect Google again.' : 'token refresh failed: ' . $r['error'];
            googleHealth($pdo, false, $msg);
            return ['ok' => false, 'token' => '', 'error' => $msg, 'permanent' => $revoked];
        }
        $tok = (string)$r['data']['access_token'];
        $exp = date('Y-m-d H:i:s', time() + max(60, (int)($r['data']['expires_in'] ?? 3600)) - 60);
        $sets = "access_token_enc = ?, access_expires_at = ?";
        $vals = [googleEncrypt($tok), $exp];
        if (!empty($r['data']['refresh_token'])) { $sets .= ", refresh_token_enc = ?"; $vals[] = googleEncrypt((string)$r['data']['refresh_token']); }
        $pdo->prepare("UPDATE google_account SET {$sets} WHERE id = 1")->execute($vals);
        unset($GLOBALS['__googleAccount']);
        return ['ok' => true, 'token' => $tok, 'error' => ''];
    }
}

if (!function_exists('gmailApi')) {
    /** One Gmail API call on users/me (path like 'messages/send'); a 401 forces one token refresh and a retry. */
    function gmailApi(PDO $pdo, string $method, string $path, ?array $body = null, array $query = []): array {
        $tok = googleAccessToken($pdo);
        if (!$tok['ok']) return ['ok' => false, 'http' => 0, 'data' => [], 'error' => $tok['error'], 'permanent' => !empty($tok['permanent'])];
        $url = googleApiBase() . '/gmail/v1/users/me/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
        $r = googleHttp($method, $url, $body, ['Authorization: Bearer ' . $tok['token']]);
        if ($r['http'] === 401) {
            $tok = googleAccessToken($pdo, true);
            if (!$tok['ok']) return ['ok' => false, 'http' => 401, 'data' => [], 'error' => $tok['error'], 'permanent' => !empty($tok['permanent'])];
            $r = googleHttp($method, $url, $body, ['Authorization: Bearer ' . $tok['token']]);
        }
        return $r;
    }
}

// =====================================================================================================================
// MIME
// =====================================================================================================================

if (!function_exists('b64url')) {
    function b64url(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
}
if (!function_exists('b64urlDecode')) {
    function b64urlDecode(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
}

if (!function_exists('notifyMimeHeaderEncode')) {
    /** A header value: as is when plain ASCII and short, else RFC 2047 B-encoded words (≤ 45 bytes of UTF-8 each,
     *  split on character boundaries, folded with CRLF + space). */
    function notifyMimeHeaderEncode(string $v): string {
        $v = str_replace(["\r", "\n"], ' ', $v);
        if (!preg_match('/[^\x20-\x7e]/', $v) && strlen($v) <= 900) return $v;
        $words = []; $buf = '';
        foreach (preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($buf . $ch) > 45) { $words[] = $buf; $buf = ''; }
            $buf .= $ch;
        }
        if ($buf !== '') $words[] = $buf;
        return implode("\r\n ", array_map(static function ($w) { return '=?UTF-8?B?' . base64_encode($w) . '?='; }, $words));
    }
}

if (!function_exists('notifyMimeBuild')) {
    /**
     * The whole RFC 5322 message for a notifyEmail()-normalised $msg (to, subject, from, reply_to, message_id,
     * in_reply_to, references, headers, text, html): multipart/alternative (text/plain + text/html), UTF-8,
     * quoted-printable, CRLF line ends.
     */
    function notifyMimeBuild(array $msg): string {
        $h = ['From: ' . (string)$msg['from'], 'To: ' . (string)$msg['to'], 'Subject: ' . notifyMimeHeaderEncode((string)($msg['subject'] ?? '')),
              'Date: ' . date('r')];
        foreach (notifyMailHeaders(array_merge($msg, ['from' => ''])) as $line) $h[] = $line;   // Reply-To, Message-ID, threading, extras
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'X-Mailer: Joust-Portal-Notify';
        $norm = static function (string $s): string { return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $s)); };
        $text = (string)($msg['text'] ?? '');
        if ($text === '' && !empty($msg['html'])) $text = notifyHtmlToText((string)$msg['html']);
        $textPart = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($norm($text));
        if (empty($msg['html'])) {
            return implode("\r\n", $h) . "\r\n" . $textPart . "\r\n";
        }
        $b = 'jp_' . bin2hex(random_bytes(10));
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
        $htmlPart = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($norm((string)$msg['html']));
        return implode("\r\n", $h) . "\r\n\r\nThis is a multi-part message in MIME format.\r\n\r\n"
             . "--{$b}\r\n{$textPart}\r\n--{$b}\r\n{$htmlPart}\r\n--{$b}--\r\n";
    }
}

if (!function_exists('notifyMimeHeaderDecode')) {
    function notifyMimeHeaderDecode(string $v): string {
        if (strpos($v, '=?') === false) return trim($v);
        if (function_exists('iconv_mime_decode')) {
            $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($d !== false) return trim($d);
        }
        return trim(mb_decode_mimeheader($v));
    }
}

if (!function_exists('notifyMimeSplit')) {
    /** [headers (lower name → list of decoded values), raw headers (lower name → list), body] of one entity. */
    function notifyMimeSplit(string $raw): array {
        $raw = str_replace("\r\n", "\n", $raw);
        $p = strpos($raw, "\n\n");
        $head = $p === false ? $raw : substr($raw, 0, $p);
        $body = $p === false ? '' : substr($raw, $p + 2);
        $head = preg_replace("/\n[ \t]+/", ' ', $head);   // unfold
        $hdr = []; $rawHdr = [];
        foreach (explode("\n", (string)$head) as $line) {
            $c = strpos($line, ':');
            if ($c === false) continue;
            $name = strtolower(trim(substr($line, 0, $c)));
            $val = trim(substr($line, $c + 1));
            $rawHdr[$name][] = $val;
            $hdr[$name][] = notifyMimeHeaderDecode($val);
        }
        return [$hdr, $rawHdr, $body];
    }
}

if (!function_exists('notifyMimeParams')) {
    /** 'text/plain; charset="utf-8"; name=x' → ['text/plain', ['charset' => 'utf-8', 'name' => 'x']]. */
    function notifyMimeParams(string $v): array {
        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v);
        $type = strtolower(trim((string)array_shift($parts)));
        $params = [];
        foreach ($parts as $p) {
            $e = strpos($p, '=');
            if ($e === false) continue;
            $params[strtolower(trim(substr($p, 0, $e)))] = trim(trim(substr($p, $e + 1)), '"');
        }
        return [$type, $params];
    }
}

if (!function_exists('notifyMimeParse')) {
    /**
     * Parse an RFC 5322 message → ['headers' => lower name → [decoded values], 'raw_headers', 'text', 'html',
     * 'attachments' => [file names], 'parts' => [['type', 'charset', 'body']]]. Handles nested multiparts,
     * base64 / quoted-printable, charsets (converted to UTF-8).
     */
    function notifyMimeParse(string $raw): array {
        $out = ['headers' => [], 'raw_headers' => [], 'text' => '', 'html' => '', 'attachments' => [], 'parts' => []];
        [$out['headers'], $out['raw_headers'], $body] = notifyMimeSplit($raw);
        notifyMimeWalk($out['headers'], $body, $out, 0);
        return $out;
    }
}

if (!function_exists('notifyMimeWalk')) {
    function notifyMimeWalk(array $hdr, string $body, array &$out, int $depth): void {
        if ($depth > 8) return;
        [$type, $params] = notifyMimeParams((string)($hdr['content-type'][0] ?? 'text/plain'));
        if (strpos($type, 'multipart/') === 0 && !empty($params['boundary'])) {
            $b = '--' . $params['boundary'];
            $chunks = explode("\n" . $b, "\n" . $body);
            array_shift($chunks);   // preamble
            foreach ($chunks as $chunk) {
                if (strncmp($chunk, '--', 2) === 0) break;   // closing delimiter
                $chunk = ltrim(substr($chunk, (int)strpos($chunk, "\n") + 0), "\n");
                [$h2, , $b2] = notifyMimeSplit($chunk);
                notifyMimeWalk($h2, $b2, $out, $depth + 1);
            }
            return;
        }
        $cte = strtolower(trim((string)($hdr['content-transfer-encoding'][0] ?? '')));
        if ($cte === 'base64') $data = (string)base64_decode(preg_replace('/\s+/', '', $body));
        elseif ($cte === 'quoted-printable') $data = quoted_printable_decode(str_replace("\n", "\r\n", $body));
        else $data = $body;
        $charset = strtolower((string)($params['charset'] ?? 'utf-8'));
        $disp = notifyMimeParams((string)($hdr['content-disposition'][0] ?? ''));
        $fname = (string)($disp[1]['filename'] ?? ($params['name'] ?? ''));
        $isAttachment = $disp[0] === 'attachment' || $fname !== '' || (!in_array($type, ['text/plain', 'text/html'], true) && strpos($type, 'multipart/') !== 0);
        if ($isAttachment) {
            $out['attachments'][] = $fname !== '' ? notifyMimeHeaderDecode($fname) : $type;
            return;
        }
        if ($charset !== 'utf-8' && $charset !== 'us-ascii' && $charset !== '') {
            $enc = in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true) ? $charset : 'Windows-1252';
            $data = (string)@mb_convert_encoding($data, 'UTF-8', $enc);
        }
        $data = str_replace("\r\n", "\n", $data);
        $out['parts'][] = ['type' => $type, 'charset' => $charset, 'body' => $data];
        if ($type === 'text/plain' && $out['text'] === '') $out['text'] = $data;
        if ($type === 'text/html' && $out['html'] === '') $out['html'] = $data;
    }
}

if (!function_exists('notifyHtmlToText')) {
    /** HTML → readable plain text (quoted-reply containers dropped first: Gmail, Apple, Outlook). */
    function notifyHtmlToText(string $html): string {
        $html = preg_replace('#<(head|style|script)\b[^>]*>.*?</\1>#is', '', $html);
        // everything from the first quoted-reply container on is history
        $cut = '#<div[^>]+class="[^"]*gmail_quote|<blockquote\b|<div[^>]+id="(?:divRplyFwdMsg|appendonsend)"|<hr[^>]+id="stopSpelling"#i';
        if (preg_match($cut, (string)$html, $m, PREG_OFFSET_CAPTURE)) $html = substr((string)$html, 0, $m[0][1]);
        $html = preg_replace('#<br\s*/?>#i', "\n", (string)$html);
        $html = preg_replace('#</(p|div|li|tr|h[1-6])>#i', "\n", (string)$html);
        $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", (string)$text);
        return trim((string)preg_replace("/\n{3,}/", "\n\n", (string)$text));
    }
}

if (!function_exists('notifyStripQuoted')) {
    /**
     * The new part of a reply: cut at the first quoted-history marker —
     *   Gmail / Apple Mail "On <date>, <name> <addr> wrote:" (also wrapped over two or three lines; Le … a écrit :,
     *   Am … schrieb …:), Outlook "-----Original Message-----", an Outlook header block "From: … / Sent: …" (after an
     *   optional "_____" rule), a signature delimiter "-- ", mobile signatures ("Sent from my iPhone", "Get Outlook
     *   for iOS"); lines starting with ">" are dropped. Blank runs collapse.
     */
    function notifyStripQuoted(string $text): string {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $n = count($lines);
        $out = [];
        $wrote = '/^(On\b.{0,400}\bwrote|Le\b.{0,400}\ba écrit|Am\b.{0,400}\bschrieb\b.{0,200}|El\b.{0,400}\bescribió)\s?:\s*$/iu';
        for ($i = 0; $i < $n; $i++) {
            $raw = $lines[$i];
            $t = trim($raw);
            $next = trim($lines[$i + 1] ?? '');
            $next2 = trim($lines[$i + 2] ?? '');
            if (preg_match($wrote, $t)) break;
            // an attribution wrapped over 2–3 lines: starts like one (a date in it, not a finished sentence)
            if (preg_match('/^(On|Le|Am|El)\b/u', $t) && preg_match('/\d/', $t) && !preg_match('/[.!?]$/u', $t)
                && (preg_match($wrote, $t . ' ' . $next) || preg_match($wrote, $t . ' ' . $next . ' ' . $next2))) break;
            if (preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $t)) break;
            if (preg_match('/^_{8,}$/', $t) && preg_match('/^From:/i', $next)) break;
            if (preg_match('/^From:\s*\S/i', $t) && (preg_match('/^(Sent|Date):/i', $next) || preg_match('/^(Sent|Date):/i', $next2))) break;
            if (rtrim($raw) === '--' || $raw === '-- ') break;
            if (preg_match('/^(Sent from my (iPhone|iPad|Android|mobile|Galaxy|Samsung)\b|Sent from (Outlook|Mail for Windows|Yahoo Mail)|Get Outlook for (iOS|Android))/i', $t)) break;
            if ($t !== '' && $t[0] === '>') continue;
            $out[] = rtrim($raw);
        }
        $s = trim(implode("\n", $out));
        return (string)preg_replace("/\n{3,}/", "\n\n", $s);
    }
}

if (!function_exists('notifyParseAddress')) {
    /** '"Jane Kenda" <Jane@Kenda.example>' → ['jane@kenda.example', 'Jane Kenda'] ('' when there is no address). */
    function notifyParseAddress(string $v): array {
        $v = trim($v);
        if (preg_match('/^(.*)<([^>]+)>\s*$/s', $v, $m)) {
            $addr = strtolower(trim($m[2]));
            $name = trim(trim($m[1]), "\"' ");
        } else {
            $addr = strtolower($v); $name = '';
        }
        return [filter_var($addr, FILTER_VALIDATE_EMAIL) ? $addr : '', $name];
    }
}

if (!function_exists('notifyMessageIds')) {
    /** Every <message-id> in a header value, in order. */
    function notifyMessageIds(string $v): array {
        preg_match_all('/<[^<>\s]{3,250}>/', $v, $m);
        return array_values(array_unique($m[0]));
    }
}

// =====================================================================================================================
// Transport selection + the gmail transport
// =====================================================================================================================

if (!function_exists('notifyMailTransport')) {
    /** The transport notifyEmail() uses: config mail_transport when set ('mail' | 'gmail' | 'sink'); blank or 'auto' →
     *  'sink' when the test harness's mail_sink_dir is set, else 'gmail' once Google is connected, else 'mail'. */
    function notifyMailTransport(): string {
        $t = preg_replace('/[^a-z0-9_]/', '', strtolower(notifyCfg('mail_transport')));
        if ($t !== '' && $t !== 'auto') return $t;
        if (notifyCfg('mail_sink_dir') !== '') return 'sink';
        return googleConnected() ? 'gmail' : 'mail';
    }
}

if (!function_exists('notifyMailSend_gmail')) {
    /** Send through the Gmail API as the connected account. */
    function notifyMailSend_gmail(array $msg): array {
        $pdo = ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null;
        if (!$pdo || !googleConnected($pdo)) return ['ok' => false, 'error' => 'gmail: Google is not connected (Manage → Notifications → Connect Google)'];
        $raw = notifyMimeBuild($msg);
        $r = gmailApi($pdo, 'POST', 'messages/send', ['raw' => b64url($raw)]);
        googleHealth($pdo, $r['ok'], $r['ok'] ? '' : 'send: ' . $r['error']);
        if (!$r['ok']) return ['ok' => false, 'error' => 'gmail: ' . $r['error']];
        return ['ok' => true, 'error' => '', 'provider_id' => (string)($r['data']['id'] ?? '')];
    }
}

// =====================================================================================================================
// Item tokens + sent Message-IDs (what replies are matched on)
// =====================================================================================================================

if (!function_exists('notifyItemTokenFor')) {
    /** The HMAC an item's subject token is cut from (lower-case hex). */
    function notifyItemTokenFor(string $type, int $id): string {
        $key = hash('sha256', 'joust-mail-token|' . (function_exists('clientLinkSecret') ? clientLinkSecret() : __DIR__));
        return hash_hmac('sha256', 'item|' . $type . '|' . $id, $key);
    }
}

if (!function_exists('notifyItemToken')) {
    /** The item's short signed subject token (6 hex; 8 / 10 on the rare collision), stored in notify_threads.email_token. */
    function notifyItemToken(PDO $pdo, string $type, int $id, int $companyId): string {
        try {
            $t = notifyThreadRow($pdo, $type, $id);
            if ($t && !empty($t['email_token'])) return (string)$t['email_token'];
            $full = notifyItemTokenFor($type, $id);
            foreach ([6, 8, 10] as $len) {
                $tok = substr($full, 0, $len);
                $s = $pdo->prepare("SELECT entity_type, entity_id FROM notify_threads WHERE email_token = ?");
                $s->execute([$tok]);
                $other = $s->fetch();
                if ($other && !($other['entity_type'] === $type && (int)$other['entity_id'] === $id)) continue;
                $pdo->prepare("INSERT INTO notify_threads (company_id, entity_type, entity_id, email_token) VALUES (?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE email_token = COALESCE(email_token, VALUES(email_token))")
                    ->execute([$companyId, $type, $id, $tok]);
                return $tok;
            }
        } catch (Throwable $e) {
            error_log('notifyItemToken: ' . $e->getMessage());
        }
        return '';
    }
}

if (!function_exists('notifyItemByToken')) {
    /** [type, id, company_id] for a subject token whose signature checks out, else null. */
    function notifyItemByToken(PDO $pdo, string $token): ?array {
        $token = strtolower($token);
        if (!preg_match('/^[a-f0-9]{6,10}$/', $token)) return null;
        $s = $pdo->prepare("SELECT entity_type, entity_id, company_id FROM notify_threads WHERE email_token = ?");
        $s->execute([$token]);
        $r = $s->fetch();
        if (!$r) return null;
        if (!hash_equals(substr(notifyItemTokenFor((string)$r['entity_type'], (int)$r['entity_id']), 0, strlen($token)), $token)) return null;
        return [(string)$r['entity_type'], (int)$r['entity_id'], (int)$r['company_id']];
    }
}

if (!function_exists('notifyEmailRefAdd')) {
    /** Remember a Message-ID the portal sent to a client (→ client, item or null for a multi-item email, contact). */
    function notifyEmailRefAdd(PDO $pdo, string $messageId, int $companyId, ?string $type, ?int $id, ?int $contactId, string $kind): void {
        if ($messageId === '' || !googleReady($pdo)) return;
        try {
            $pdo->prepare("INSERT IGNORE INTO notify_email_refs (message_id, company_id, entity_type, entity_id, contact_id, kind) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([substr($messageId, 0, 190), $companyId, $type, $id, $contactId, substr($kind, 0, 20)]);
        } catch (Throwable $e) {
            error_log('notifyEmailRefAdd: ' . $e->getMessage());
        }
    }
}

// =====================================================================================================================
// Inbound
// =====================================================================================================================

if (!function_exists('inboundSender')) {
    /** Who may post on $info's item from $email: ['actor' => 'client', 'contact_id'] for a contact of THAT item's client,
     *  ['actor' => 'admin', 'user_id'] for an active Joust team member (or the portal's own sender), else null. */
    function inboundSender(PDO $pdo, array $info, string $email): ?array {
        if ($email === '') return null;
        if (function_exists('clientAuthReady') && clientAuthReady($pdo)) {
            $s = $pdo->prepare("SELECT id FROM client_contacts WHERE company_id = ? AND email = ?");
            $s->execute([(int)$info['company_id'], $email]);
            $cid = (int)$s->fetchColumn();
            if ($cid > 0) return ['actor' => 'client', 'contact_id' => $cid];
        }
        foreach (adminUsers($pdo) as $u) {
            if (!empty($u['active']) && strcasecmp((string)$u['email'], $email) === 0) return ['actor' => 'admin', 'user_id' => (int)$u['id']];
        }
        if ($email === googleExpectedAccount()) {
            $owner = notifyOwnerFor($pdo, (int)$info['company_id']);
            return ['actor' => 'admin', 'user_id' => $owner ? (int)$owner['id'] : null];
        }
        return null;
    }
}

if (!function_exists('inboundPostComment')) {
    /** Post an email reply as a portal comment by $who on $info's item (the same rows the comment endpoints write). */
    function inboundPostComment(PDO $pdo, array $info, array $who, string $text): int {
        $type = (string)$info['entity_type']; $id = (int)$info['entity_id']; $cid = (int)$info['company_id'];
        $ctx = ['source' => 'email'];
        if ($who['actor'] === 'client') { $ctx['client_contact_id'] = $who['contact_id'] ?? null; $ctx['author_user_id'] = null; }
        else $ctx['author_user_id'] = $who['user_id'] ?? null;
        return (int)activityWithContext($ctx, static function () use ($pdo, $type, $id, $cid, $info, $who, $text) {
            if ($type === 'post') $pdo->prepare("UPDATE posts SET client_comment = ? WHERE id = ?")->execute([$text, $id]);
            if ($type === 'tire_image') $pdo->prepare("UPDATE tire_images SET client_comment = ? WHERE id = ?")->execute([$text, $id]);
            return logActivity($pdo, $cid, $type, $id, 'commented', $who['actor'], 'Comment on ' . $info['title'] . ' (by email)', $text, newBatchId());
        });
    }
}

if (!function_exists('inboundBody')) {
    /** The text to post from a parsed message: plain part (else HTML → text), quoted history cut, attachments noted. */
    function inboundBody(array $mime): string {
        $text = $mime['text'] !== '' ? $mime['text'] : notifyHtmlToText($mime['html']);
        $text = notifyStripQuoted($text);
        if ($mime['attachments']) $text = trim($text . "\n\n(attachment not imported)");
        if (mb_strlen($text, 'UTF-8') > 2000) $text = rtrim(mb_substr($text, 0, 1999, 'UTF-8')) . '…';
        return $text;
    }
}

if (!function_exists('inboundProcess')) {
    /**
     * One received message (raw RFC 5322) → posted / unmatched / ignored. The email_inbound row (claimed by the
     * caller, so a Gmail id is processed once) is updated with the outcome. Returns the status.
     */
    function inboundProcess(PDO $pdo, int $rowId, string $raw): string {
        $mime = notifyMimeParse($raw);
        $hd = static function (string $k) use ($mime): string { return (string)($mime['headers'][$k][0] ?? ''); };
        [$from, $fromName] = notifyParseAddress($hd('from'));
        $subject = $hd('subject');
        $mid = notifyMessageIds((string)($mime['raw_headers']['message-id'][0] ?? ''))[0] ?? '';
        $refs = array_merge(notifyMessageIds((string)($mime['raw_headers']['in-reply-to'][0] ?? '')), notifyMessageIds((string)($mime['raw_headers']['references'][0] ?? '')));
        $date = strtotime($hd('date')) ?: time();
        $body = inboundBody($mime);
        $set = static function (array $f) use ($pdo, $rowId): void {
            $cols = []; $vals = [];
            foreach ($f as $k => $v) { $cols[] = "`{$k}` = ?"; $vals[] = $v; }
            $vals[] = $rowId;
            $pdo->prepare("UPDATE email_inbound SET " . implode(', ', $cols) . ", handled_at = NOW() WHERE id = ?")->execute($vals);
        };
        $base = ['message_id' => substr($mid, 0, 190) ?: null, 'from_email' => substr($from, 0, 190), 'from_name' => substr($fromName, 0, 190) ?: null,
                 'subject' => mb_substr($subject, 0, 500, 'UTF-8'), 'body_text' => $body, 'received_at' => date('Y-m-d H:i:s', $date),
                 'has_attachments' => $mime['attachments'] ? 1 : 0];
        // Loops and robots: our own notifications, auto-replies, bounces.
        $auto = strtolower($hd('auto-submitted'));
        if ($hd('x-joust-portal') !== '' || ($auto !== '' && $auto !== 'no') || preg_match('/^(bulk|junk|auto_reply|list)$/i', $hd('precedence'))
            || preg_match('/^(mailer-daemon|postmaster)@/i', $from)) {
            $set($base + ['status' => 'ignored', 'reason' => 'automatic message (auto-reply, bounce or the portal’s own)']);
            return 'ignored';
        }
        // 1. In-Reply-To / References → a Message-ID the portal sent (newest reference first)
        $match = null; $how = ''; $hintCompany = null;
        if ($refs) {
            $ph = implode(',', array_fill(0, count($refs), '?'));
            $s = $pdo->prepare("SELECT * FROM notify_email_refs WHERE message_id IN ($ph)");
            $s->execute(array_map(static function ($r) { return substr($r, 0, 190); }, $refs));
            $found = [];
            foreach ($s->fetchAll() as $r) $found[$r['message_id']] = $r;
            foreach (array_reverse($refs) as $r) {
                $row = $found[substr($r, 0, 190)] ?? null;
                if (!$row) continue;
                $hintCompany = $hintCompany ?? (int)$row['company_id'];
                if (!empty($row['entity_type'])) { $match = [(string)$row['entity_type'], (int)$row['entity_id'], (int)$row['company_id']]; $how = 'in-reply-to'; break; }
            }
        }
        // 2. the signed subject token [J#xxxxxx]
        if (!$match && preg_match('/\[J#([a-f0-9]{6,10})\]/i', $subject, $m)) {
            $match = notifyItemByToken($pdo, $m[1]);
            if ($match) $how = 'subject token';
        }
        if (!$match) {
            $set($base + ['status' => 'unmatched', 'company_id' => $hintCompany,
                          'reason' => $hintCompany ? 'a reply to an email about several items — pick the item' : 'not a reply to a portal email (no matching Message-ID or [J#…] token)']);
            return 'unmatched';
        }
        [$type, $id, $cid] = $match;
        $info = notifyItemInfo($pdo, $type, $id);
        if (!$info['exists'] || (int)$info['company_id'] !== $cid) {
            $set($base + ['status' => 'unmatched', 'company_id' => $cid, 'reason' => 'the item it answers no longer exists']);
            return 'unmatched';
        }
        $who = inboundSender($pdo, $info, $from);
        if (!$who) {
            $set($base + ['status' => 'unmatched', 'company_id' => $cid, 'entity_type' => $type, 'entity_id' => $id,
                          'reason' => ($from !== '' ? $from : 'the sender') . ' is not a contact of ' . $info['company_name']]);
            return 'unmatched';
        }
        if (trim($body) === '') {
            $set($base + ['status' => 'ignored', 'company_id' => $cid, 'entity_type' => $type, 'entity_id' => $id, 'reason' => 'nothing left after removing the quoted email']);
            return 'ignored';
        }
        $aid = inboundPostComment($pdo, $info, $who, $body);
        $set($base + ['status' => 'posted', 'company_id' => $cid, 'entity_type' => $type, 'entity_id' => $id, 'reason' => 'matched by ' . $how,
                      'contact_id' => $who['contact_id'] ?? null, 'author_user_id' => $who['user_id'] ?? null, 'activity_id' => $aid ?: null]);
        return 'posted';
    }
}

if (!function_exists('gmailEnsureLabel')) {
    /** The id of the "portal-processed" label (created when missing; cached on the account row). */
    function gmailEnsureLabel(PDO $pdo): string {
        $acc = googleAccount($pdo, true);
        if ($acc && !empty($acc['label_id'])) return (string)$acc['label_id'];
        $id = '';
        $r = gmailApi($pdo, 'GET', 'labels');
        if ($r['ok']) {
            foreach ((array)($r['data']['labels'] ?? []) as $l) {
                if (strcasecmp((string)($l['name'] ?? ''), GOOGLE_LABEL) === 0) { $id = (string)$l['id']; break; }
            }
        }
        if ($id === '') {
            $c = gmailApi($pdo, 'POST', 'labels', ['name' => GOOGLE_LABEL, 'labelListVisibility' => 'labelShow', 'messageListVisibility' => 'show']);
            if ($c['ok']) $id = (string)($c['data']['id'] ?? '');
        }
        if ($id !== '') $pdo->prepare("UPDATE google_account SET label_id = ? WHERE id = 1")->execute([$id]);
        return $id;
    }
}

if (!function_exists('gmailPollInbound')) {
    /**
     * The cron's inbound step: new replies to the inbound address → portal comments / the unmatched list. Bounded
     * (25 messages, ~20 s). → ['status' => ok|not_connected|error, 'seen', 'posted', 'unmatched', 'ignored', 'error'].
     */
    function gmailPollInbound(PDO $pdo, int $limit = 25, float $budget = 20.0): array {
        $out = ['status' => 'ok', 'seen' => 0, 'posted' => 0, 'unmatched' => 0, 'ignored' => 0, 'error' => ''];
        if (!googleReady($pdo) || !googleConnected($pdo)) { $out['status'] = 'not_connected'; return $out; }
        $t0 = microtime(true);
        $q = 'to:' . inboundAddress() . ' -label:' . GOOGLE_LABEL . ' newer_than:14d';
        $list = gmailApi($pdo, 'GET', 'messages', null, ['q' => $q, 'maxResults' => $limit]);
        $pdo->exec("UPDATE google_account SET last_poll_at = NOW() WHERE id = 1");
        if (!$list['ok']) {
            googleHealth($pdo, false, 'inbox: ' . $list['error']);
            return ['status' => 'error', 'error' => $list['error']] + $out;
        }
        googleHealth($pdo, true);
        $label = '';
        foreach ((array)($list['data']['messages'] ?? []) as $m) {
            if (microtime(true) - $t0 > $budget) break;
            $gid = substr((string)($m['id'] ?? ''), 0, 64);
            if ($gid === '') continue;
            $out['seen']++;
            $claim = $pdo->prepare("INSERT IGNORE INTO email_inbound (gmail_id, thread_id, status) VALUES (?, ?, 'processing')");
            $claim->execute([$gid, substr((string)($m['threadId'] ?? ''), 0, 64) ?: null]);
            $fresh = $claim->rowCount() === 1;
            if ($fresh) {
                $rowId = (int)$pdo->lastInsertId();
                $g = gmailApi($pdo, 'GET', 'messages/' . rawurlencode($gid), null, ['format' => 'raw']);
                if (!$g['ok'] || empty($g['data']['raw'])) {
                    $pdo->prepare("DELETE FROM email_inbound WHERE id = ?")->execute([$rowId]);   // try again next run
                    $out['error'] = 'fetch: ' . ($g['error'] ?: 'no body');
                    continue;
                }
                try {
                    $st = inboundProcess($pdo, $rowId, b64urlDecode((string)$g['data']['raw']));
                } catch (Throwable $e) {
                    error_log('inboundProcess: ' . $e->getMessage());
                    $pdo->prepare("UPDATE email_inbound SET status = 'unmatched', reason = 'could not be read' WHERE id = ?")->execute([$rowId]);
                    $st = 'unmatched';
                }
                if (isset($out[$st])) $out[$st]++;
            }
            // label it (also an already-imported message whose label failed last time) so the search skips it
            if ($label === '') $label = gmailEnsureLabel($pdo);
            if ($label !== '') gmailApi($pdo, 'POST', 'messages/' . rawurlencode($gid) . '/modify', ['addLabelIds' => [$label]]);
        }
        return $out;
    }
}

if (!function_exists('inboundAssign')) {
    /** Manage → Unmatched → Assign: post a stored reply on the item the admin picked (as the matching contact of that
     *  item's client or team member when there is one, else as the client). → ['ok', 'error']. */
    function inboundAssign(PDO $pdo, int $rowId, string $type, int $id): array {
        $s = $pdo->prepare("SELECT * FROM email_inbound WHERE id = ? AND status = 'unmatched'");
        $s->execute([$rowId]);
        $row = $s->fetch();
        if (!$row) return ['ok' => false, 'error' => 'That reply was already handled.'];
        if (!in_array($type, notifyThreadTypes(), true)) return ['ok' => false, 'error' => 'Pick an item.'];
        $info = notifyItemInfo($pdo, $type, $id);
        if (!$info['exists']) return ['ok' => false, 'error' => 'That item no longer exists.'];
        $text = trim((string)$row['body_text']);
        if ($text === '') return ['ok' => false, 'error' => 'The reply is empty.'];
        $who = inboundSender($pdo, $info, (string)$row['from_email']) ?? ['actor' => 'client', 'contact_id' => null];
        $aid = inboundPostComment($pdo, $info, $who, $text);
        $pdo->prepare("UPDATE email_inbound SET status = 'assigned', company_id = ?, entity_type = ?, entity_id = ?, contact_id = ?, author_user_id = ?,
                       activity_id = ?, handled_at = NOW(), reason = 'assigned by Joust' WHERE id = ?")
            ->execute([(int)$info['company_id'], $type, $id, $who['contact_id'] ?? null, $who['user_id'] ?? null, $aid ?: null, $rowId]);
        return ['ok' => true, 'error' => '', 'title' => $info['title']];
    }
}

if (!function_exists('inboundUnmatched')) {
    /** The Unmatched email replies list (newest first). */
    function inboundUnmatched(PDO $pdo, int $limit = 50): array {
        if (!googleReady($pdo)) return [];
        $s = $pdo->query("SELECT i.*, c.name AS company_name FROM email_inbound i LEFT JOIN companies c ON c.id = i.company_id
                           WHERE i.status = 'unmatched' ORDER BY i.id DESC LIMIT " . max(1, $limit));
        return $s->fetchAll();
    }
}
