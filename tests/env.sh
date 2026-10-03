# Shared settings for the test harness (sourced by bootstrap.sh / serve.sh / run.sh).
# Everything lives outside the repo so a run never writes into the working tree:
#   $PORTAL_TEST_ROOT/site/portal   rsync copy of the repo (the app; its own uploads/ + test config.php)
#   $PORTAL_TEST_ROOT/site/media    the docroot sibling media/ (tires/, library/, pages/)
#   $PORTAL_TEST_ROOT/sessions      PHP session files for the php -S server
#   $PORTAL_TEST_ROOT/server.log    php -S output (PHP warnings / fatals land here; run.sh fails on them)

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$TESTS_DIR/.." && pwd)"
PORTAL_TEST_ROOT="${PORTAL_TEST_ROOT:-/tmp/portal-test}"
SITE_DIR="$PORTAL_TEST_ROOT/site"
APP_DIR="$SITE_DIR/portal"
MEDIA_DIR="$SITE_DIR/media"
SESSION_DIR="$PORTAL_TEST_ROOT/sessions"
SERVER_LOG="$PORTAL_TEST_ROOT/server.log"
SERVER_PID="$PORTAL_TEST_ROOT/server.pid"
PORTAL_TEST_PORT="${PORTAL_TEST_PORT:-8099}"
PORTAL_TEST_DB="${PORTAL_TEST_DB:-portal_test}"
PORTAL_TEST_DB_USER="${PORTAL_TEST_DB_USER:-portal_test}"
PORTAL_TEST_DB_PASS="${PORTAL_TEST_DB_PASS:-portal_test}"
PORTAL_TEST_BASE="http://127.0.0.1:${PORTAL_TEST_PORT}/portal"
# Fake Slack Web API (tests/slack-stub.php, its own php -S) + where it logs calls. Every test email (notifyEmail(),
# transport 'sink': notifications AND client sign-in links) is written to MAIL_SINK_DIR as JSON; MAIL_DIR is the same
# folder under the name the sign-in suites read.
PORTAL_TEST_STUB_PORT="${PORTAL_TEST_STUB_PORT:-$((PORTAL_TEST_PORT + 1000))}"
SLACK_STUB_LOG="$PORTAL_TEST_ROOT/slack-calls.jsonl"
SLACK_STUB_PID="$PORTAL_TEST_ROOT/slack-stub.pid"
MAIL_SINK_DIR="$PORTAL_TEST_ROOT/mail"
MAIL_DIR="$MAIL_SINK_DIR"
export TESTS_DIR REPO_DIR PORTAL_TEST_ROOT SITE_DIR APP_DIR MEDIA_DIR SESSION_DIR SERVER_LOG SERVER_PID \
       PORTAL_TEST_PORT PORTAL_TEST_DB PORTAL_TEST_DB_USER PORTAL_TEST_DB_PASS PORTAL_TEST_BASE \
       PORTAL_TEST_STUB_PORT SLACK_STUB_LOG SLACK_STUB_PID MAIL_SINK_DIR MAIL_DIR

# Copy the repo into the test site (code only: never .git, tests/, the real config.php or uploads/).
sync_site() {
    mkdir -p "$APP_DIR" "$MEDIA_DIR" "$SESSION_DIR" "$MAIL_SINK_DIR"
    rsync -a --delete \
        --exclude '.git' --exclude '.github' --exclude 'tests' --exclude 'uploads' \
        --exclude 'config.php' --exclude 'error_log' --exclude 'node_modules' \
        "$REPO_DIR/" "$APP_DIR/"
    cat > "$APP_DIR/config.php" <<PHP
<?php
// Written by tests/env.sh — the TEST database (never the real credentials).
return [
    'host'     => 'localhost',
    'dbname'   => '${PORTAL_TEST_DB}',
    'username' => '${PORTAL_TEST_DB_USER}',
    'password' => '${PORTAL_TEST_DB_PASS}',
    'charset'  => 'utf8mb4',
    // notifications (notify-lib.php) + client sign-in against local fakes: Slack = tests/slack-stub.php, email = JSON
    // files. Canonical key names only (config.example.php); the aliases are covered by tests/smoke/19-integration.php.
    'portal_url'            => 'http://127.0.0.1:${PORTAL_TEST_PORT}/portal',
    'machine_url_ext'       => '.php',
    'notify_to'             => 'lance@joustmedia.com',
    'notify_from'           => 'lance@joustmedia.com',
    'notify_from_name'      => 'Joust Media',
    'notify_reply_to'       => 'lance@joustmedia.com',
    'notify_message_domain' => 'joustmedia.test',
    'mail_transport'        => 'sink',
    'mail_sink_dir'         => '${MAIL_SINK_DIR}',
    'notify_cron_token'     => 'test-cron-token-0123456789abcdef',
    'slack_bot_token'       => 'xoxb-test-token',
    'slack_signing_secret'  => 'test-signing-secret-abcdef',
    'slack_api_base'        => 'http://127.0.0.1:${PORTAL_TEST_STUB_PORT}/api',
    'client_link_secret'    => 'portal-test-client-link-secret-0123456789',
];
PHP
}

# php with the test auth shim (inert unless PORTAL_TEST=1).
php_test() {
    PORTAL_TEST=1 php -d "auto_prepend_file=$TESTS_DIR/test-auth.php" -d "session.save_path=$SESSION_DIR" "$@"
}

mysql_root() {
    if mysql -uroot -e 'SELECT 1' >/dev/null 2>&1; then mysql -uroot "$@"; else sudo -n mysql -uroot "$@"; fi
}
