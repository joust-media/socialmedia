#!/usr/bin/env bash
# Start / stop the local php -S server for the test site (after tests/bootstrap.sh).
#   tests/serve.sh          (re)sync the repo into the test site and start on $PORTAL_TEST_PORT (default 8099)
#   tests/serve.sh stop
#   tests/serve.sh sync     copy code changes into the running site (no restart needed)
# The test-auth.php prepend (?__role=admin|client) is active only because PORTAL_TEST=1 is set here.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

stop() {
    if [ -f "$SERVER_PID" ]; then
        kill "$(cat "$SERVER_PID")" 2>/dev/null || true
        rm -f "$SERVER_PID"
    fi
    # anything else still bound to the port from an earlier run
    pkill -f "php .*-S 127.0.0.1:${PORTAL_TEST_PORT}" 2>/dev/null || true
}

case "${1:-start}" in
    stop) stop; exit 0 ;;
    sync) sync_site; exit 0 ;;
    start) ;;
    *) echo "usage: $0 [start|stop|sync]" >&2; exit 2 ;;
esac

[ -d "$APP_DIR" ] || { echo "serve: run tests/bootstrap.sh first" >&2; exit 2; }
stop
sync_site
: > "$SERVER_LOG"
PORTAL_TEST=1 setsid nohup php \
    -d "auto_prepend_file=$TESTS_DIR/test-auth.php" \
    -d "session.save_path=$SESSION_DIR" \
    -d display_errors=0 -d log_errors=1 -d error_log="$SERVER_LOG" \
    -d upload_max_filesize=64M -d post_max_size=80M -d memory_limit=512M \
    -S "127.0.0.1:${PORTAL_TEST_PORT}" -t "$SITE_DIR" "$TESTS_DIR/router.php" >>"$SERVER_LOG" 2>&1 &
echo $! > "$SERVER_PID"
for _ in $(seq 1 50); do
    if curl -fsS -o /dev/null "${PORTAL_TEST_BASE}/login.php" 2>/dev/null; then
        echo "serve: ${PORTAL_TEST_BASE}/ (pid $(cat "$SERVER_PID"))"
        exit 0
    fi
    sleep 0.1
done
echo "serve: server did not come up — see $SERVER_LOG" >&2
tail -20 "$SERVER_LOG" >&2
exit 1
