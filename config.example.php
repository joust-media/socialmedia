<?php
/**
 * Portal configuration — TEMPLATE. Copy to config.php on the server and fill in the values there.
 *
 *   config.php is NOT in git (.gitignore) and both deploy workflows exclude it, so the live copy is never
 *   overwritten or deleted by a deploy. Never commit real values to this file.
 *
 * Every key is optional except the database ones; a blank value means "not set" (the feature that needs it stays
 * off, and Manage → Notifications shows it as Not set — it never shows a value).
 */
return [
    // ---- Database (required) ------------------------------------------------------------------------------
    'host'     => 'localhost',   // MySQL host (GoDaddy cPanel: usually localhost)
    'dbname'   => '',            // database name
    'username' => '',            // database user
    'password' => '',            // database password
    'charset'  => 'utf8mb4',

    // ---- Portal address + links ----------------------------------------------------------------------------
    // Absolute base of the portal, no trailing slash. Every absolute link is built on it: Slack messages and emails
    // (Open in portal, Morning summary, thumbnails), client sign-in and deep links. Blank = derived from the
    // request's host (fine in the browser, but cron / Slack-triggered messages may then use the wrong host — set it).
    // Older name still accepted: portal_base_url.
    'portal_url' => '',          // e.g. 'https://joustmedia.com/portal' (staging: 'https://joustmedia.com/portal-staging')
    // Machine endpoints (notify-thumb, notify-cron, slack-events, slack-actions) are linked WITHOUT '.php' because the
    // live host 301-redirects 'x.php' to 'x' and Slack does not follow redirects. Set to '.php' only on a host with
    // no extension-less rewrite (the test harness does). Leave blank on the live server.
    'machine_url_ext' => '',
    // Clean links (/portal/kenda/posts/12). Normally leave unset: they turn on by themselves once Manage → Tools →
    // Clean links has installed (and verified) its .htaccess block. true / false forces them on / off.
    'clean_urls' => null,
    // Origin the Clean links installer uses for its self-check request, e.g. 'https://joustmedia.com'. Blank = this
    // request's own origin (right on the live host).
    'clean_links_check_origin' => '',

    // ---- Client sign-in (client-auth-lib.php) -------------------------------------------------------------------
    // HMAC key for the signed deep links in client emails (clientLink()). 32+ random characters; changing it voids
    // every link already sent. Blank = derived from the database settings (works, but set it).
    'client_link_secret' => '',

    // ---- Email (sign-in links, Morning summary, reminder emails) — every email goes through notifyEmail() ------
    'notify_to'             => '',   // who gets the Morning summary (Lance), e.g. 'lance@joustmedia.com'
    // Sender of every portal email, client sign-in links included. A bare address ('lance@joustmedia.com') with the
    // display name below, or a full 'Name <address>'. Blank = lance@joustmedia.com. Must be SPF-authorized for this
    // host. Older name still accepted: auth_mail_from.
    'notify_from'           => '',
    'notify_from_name'      => '',   // display name for a bare notify_from; blank = 'Joust Media' (old name: auth_mail_from_name)
    'notify_reply_to'       => '',   // Reply-To: header, e.g. 'lance@joustmedia.com' (old name: auth_mail_reply_to)
    'notify_message_domain' => '',   // right-hand side of generated Message-IDs, e.g. 'joustmedia.com'
    // Envelope sender for PHP mail() (-f). Blank = the From address. (old name: auth_mail_envelope)
    'notify_envelope'       => '',
    // How email is sent: 'mail' = PHP mail() (default). Phase 3 adds 'gmail' (Gmail API). 'sink' is the test
    // harness only: each message is written to mail_sink_dir as JSON and NOTHING is sent — never on the live server.
    'mail_transport'        => 'mail',
    'mail_sink_dir'         => '',   // test harness only (old name: mail_capture_dir); setting it alone also means 'sink'

    // ---- Notifications: cron ----------------------------------------------------------------------------------
    // Secret for the cPanel cron URL (…/portal/notify-cron?token=…) and for digest.php?source=cron. 16+ random
    // characters, e.g. the output of: php -r 'echo bin2hex(random_bytes(24));'
    'notify_cron_token' => '',

    // ---- Notifications: Slack (Joust-internal; docs: slack-app-manifest.yml) ---------------------------------
    'slack_bot_token'      => '',   // Bot User OAuth Token, 'xoxb-…' (Slack app → OAuth & Permissions)
    'slack_signing_secret' => '',   // Slack app → Basic Information → App Credentials → Signing Secret
    // Web API base URL. Leave blank (= https://slack.com/api). Only the test harness points it at a local stub.
    'slack_api_base'       => '',

    // ---- Google Drive storage view (docs/drive-collector/README.md) --------------------------------------------
    'drive_ingest_secret'          => '',   // 24+ random characters, shared with the Apps Script (INGEST_SECRET)
    'drive_clients_root_folder_id' => '',   // optional: the Drive folder whose sub-folders are the clients

    // ---- Image previews ------------------------------------------------------------------------------------------
    // HMAC key for signed preview / notification thumbnail URLs. Blank = derived from the database settings above.
    // Changing it invalidates preview URLs already handed out (they are regenerated on the next page view).
    'preview_secret' => '',
];
