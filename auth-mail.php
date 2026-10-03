<?php
/**
 * notifyEmail() shim — the client sign-in email's transport until notify-lib.php (wt/notify) owns outbound mail.
 * When both files are present, notify-lib.php's notifyEmail() wins (client-auth-lib.php loads notify-lib.php
 * first, and everything here is function_exists-guarded), so the integrator can delete this file or keep it as
 * the fallback.
 *
 *   notifyEmail(string|array $to, string $subject, string $html, string $text, array $opts = []): array
 *       → ['ok' => bool, 'id' => Message-ID, 'error' => string]
 *   $opts: from (address), from_name, reply_to, kind (free-form tag, e.g. 'sign_in'), headers (extra lines)
 *
 * Transport: PHP mail() with a multipart/alternative body (the same path as digest.php), envelope sender -f.
 * Sender: config 'auth_mail_from' (default lance@joustmedia.com) with display name 'auth_mail_from_name'
 * (default "Joust Media"); 'auth_mail_reply_to' optional; 'auth_mail_envelope' defaults to the From address.
 * Development / tests: config 'mail_capture_dir' => '/path' writes each message there as JSON instead of sending.
 */
require_once __DIR__ . '/url-lib.php';

if (!function_exists('notifyEmailHeaderSafe')) {
    function notifyEmailHeaderSafe(string $s): string { return trim(preg_replace('/[\r\n]+/', ' ', $s)); }
}

if (!function_exists('notifyEmail')) {
    function notifyEmail($to, string $subject, string $html, string $text, array $opts = []): array {
        $list = is_array($to) ? $to : [$to];
        $rcpt = [];
        foreach ($list as $a) {
            $a = strtolower(trim((string)$a));
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n<>,;"]/', $a)) $rcpt[] = $a;
        }
        if (!$rcpt) return ['ok' => false, 'id' => '', 'error' => 'No valid recipient.'];
        $from     = notifyEmailHeaderSafe((string)($opts['from'] ?? portalConfig('auth_mail_from', 'lance@joustmedia.com')));
        $fromName = notifyEmailHeaderSafe((string)($opts['from_name'] ?? portalConfig('auth_mail_from_name', 'Joust Media')));
        $replyTo  = notifyEmailHeaderSafe((string)($opts['reply_to'] ?? portalConfig('auth_mail_reply_to', '')));
        $envelope = notifyEmailHeaderSafe((string)portalConfig('auth_mail_envelope', $from));
        $subject  = notifyEmailHeaderSafe($subject);
        $domain   = substr(strrchr($from, '@') ?: '@joustmedia.com', 1);
        $id       = 'portal-' . bin2hex(random_bytes(8)) . '@' . $domain;
        $encName  = preg_match('/[^\x20-\x7e]/', $fromName) ? '=?UTF-8?B?' . base64_encode($fromName) . '?=' : '"' . str_replace('"', '', $fromName) . '"';
        $encSubj  = preg_match('/[^\x20-\x7e]/', $subject) ? '=?UTF-8?B?' . base64_encode($subject) . '?=' : $subject;

        $capture = trim((string)portalConfig('mail_capture_dir', ''));
        if ($capture !== '') {
            if (!is_dir($capture)) @mkdir($capture, 0700, true);
            $file = rtrim($capture, '/') . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.json';
            $ok = @file_put_contents($file, json_encode(['to' => $rcpt, 'from' => $from, 'from_name' => $fromName, 'reply_to' => $replyTo,
                'subject' => $subject, 'html' => $html, 'text' => $text, 'kind' => (string)($opts['kind'] ?? ''), 'id' => $id,
                'at' => date('c')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
            return ['ok' => $ok, 'id' => $id, 'error' => $ok ? '' : 'Could not write the captured message.'];
        }

        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers  = "From: {$encName} <{$from}>\r\n";
        if ($replyTo !== '') $headers .= "Reply-To: {$replyTo}\r\n";
        $headers .= "Message-ID: <{$id}>\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "Auto-Submitted: auto-generated\r\n";
        $headers .= "X-Mailer: Joust-Portal\r\n";
        foreach ((array)($opts['headers'] ?? []) as $line) {
            $line = notifyEmailHeaderSafe((string)$line);
            if ($line !== '' && strpos($line, ':') !== false) $headers .= $line . "\r\n";
        }
        $body  = "This is a multi-part message in MIME format.\r\n\r\n";
        $body .= "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n\r\n";
        $body .= "--{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n\r\n";
        $body .= "--{$boundary}--\r\n";
        $params = filter_var($envelope, FILTER_VALIDATE_EMAIL) ? '-f' . $envelope : '';
        $ok = @mail(implode(', ', $rcpt), $encSubj, $body, $headers, $params);
        if (!$ok) error_log('notifyEmail (auth-mail.php shim): mail() refused the message to ' . implode(', ', $rcpt));
        return ['ok' => (bool)$ok, 'id' => $id, 'error' => $ok ? '' : 'mail() refused the message.'];
    }
}
