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

    // ---- Portal address -----------------------------------------------------------------------------------
    // Absolute base of the portal, no trailing slash. Every link in Slack messages and emails (Open in portal,
    // Morning summary deep links, thumbnails) is built on it. Blank = derived from the request's host (fine in the
    // browser, but cron / Slack-triggered messages may then use the wrong host — set it).
    'portal_base_url' => '',     // e.g. 'https://joustmedia.com/portal'
    // Machine endpoints (notify-thumb, notify-cron, slack-events, slack-actions) are linked WITHOUT '.php' because the
    // live host 301-redirects 'x.php' to 'x' and Slack does not follow redirects. Set to '.php' only on a host with
    // no extension-less rewrite (the test harness does). Leave blank on the live server.
    'machine_url_ext' => '',

    // ---- Email (Morning summary, reminder emails) ---------------------------------------------------------------
    'notify_to'             => '',   // who gets the Morning summary (Lance), e.g. 'lance@joustmedia.com'
    'notify_from'           => '',   // From: header, e.g. 'Joust Portal <portal@joustmedia.com>' (must be SPF-authorized for this host)
    'notify_reply_to'       => '',   // Reply-To: header, e.g. 'lance@joustmedia.com'
    'notify_message_domain' => '',   // right-hand side of generated Message-IDs, e.g. 'joustmedia.com'
    'notify_envelope'       => '',   // envelope sender for PHP mail() (-f), e.g. 'portal@joustmedia.com'
    // How email is sent: 'mail' = PHP mail() (default). Phase 3 adds 'gmail' (Gmail API); 'sink' is the test
    // harness only (writes each message to mail_sink_dir as JSON — never use it on the live server).
    'mail_transport'        => 'mail',
    'mail_sink_dir'         => '',

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
