#!/usr/bin/env bash
# Run the whole harness: bootstrap (fresh DB + site), serve, every smoke suite (re-seeded before
# each one so suites never see each other's writes), every Playwright script, then check the
# php -S log for PHP warnings / fatals. Exit 0 only when everything is green.
#
#   tests/run.sh                 full run
#   tests/run.sh --no-bootstrap  reuse the existing DB/site (still re-syncs code + re-seeds)
#   tests/run.sh smoke           smoke suites only      tests/run.sh e2e   Playwright only
#   tests/run.sh smoke/03        only suites whose path contains "smoke/03"
#   SMOKE_VERBOSE=1 tests/run.sh  print every passing assertion name
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

BOOT=1; FILTER=""
for a in "$@"; do
    case "$a" in
        --no-bootstrap) BOOT=0 ;;
        *) FILTER="$a" ;;
    esac
done

if [ "$BOOT" = 1 ] || [ ! -d "$APP_DIR" ]; then
    bash "$TESTS_DIR/bootstrap.sh" || { echo "run: bootstrap failed" >&2; exit 1; }
fi
bash "$TESTS_DIR/serve.sh" || exit 1
trap 'bash "$TESTS_DIR/serve.sh" stop' EXIT

TALLY="$PORTAL_TEST_ROOT/tally.txt"; : > "$TALLY"
export SMOKE_TALLY="$TALLY"
failed_suites=()

reseed() { php "$TESTS_DIR/seed.php" "$APP_DIR" "$MEDIA_DIR" >/dev/null; }

if [ -z "$FILTER" ] || [[ "smoke" == *"$FILTER"* ]] || [[ "$FILTER" == smoke* ]]; then
    echo "== smoke"
    for f in "$TESTS_DIR"/smoke/[0-9]*.php; do
        [ -n "$FILTER" ] && [[ "$f" != *"$FILTER"* ]] && [[ "$FILTER" != "smoke" ]] && continue
        reseed
        php "$f" || failed_suites+=("$(basename "$f")")
    done
fi

if [ -z "$FILTER" ] || [[ "$FILTER" == e2e* ]]; then
    echo "== e2e (Playwright)"
    for f in "$TESTS_DIR"/e2e/[0-9]*.js; do
        [ -n "$FILTER" ] && [[ "$f" != *"$FILTER"* ]] && [[ "$FILTER" != "e2e" ]] && continue
        reseed
        node "$f" || failed_suites+=("$(basename "$f")")
    done
fi

# PHP warnings / notices / fatals anywhere in the run fail it (the app must stay warning-clean).
php_issues="$(grep -E 'PHP (Warning|Fatal error|Parse error|Notice|Deprecated)' "$SERVER_LOG" | sed -E 's/^\[[^]]*\] //' | sort | uniq -c | sort -rn)"
if [ -n "$php_issues" ]; then
    echo "== PHP log issues ($SERVER_LOG)"
    echo "$php_issues" | head -20
    failed_suites+=("php-log")
fi

read -r pass fail < <(awk '{p+=$1; f+=$2} END {print p+0, f+0}' "$TALLY")
echo "== total: ${pass} passed, ${fail} failed"
if [ ${#failed_suites[@]} -gt 0 ]; then
    echo "FAILED: ${failed_suites[*]}"
    exit 1
fi
echo "ALL GREEN"
