#!/usr/bin/env bash
# Build the local test stack from scratch (idempotent; wipes the previous test data):
#   1. MariaDB running (started here when installed but stopped)
#   2. database + user (tests/env.sh names; never the production config.php values)
#   3. tests/schema.sql = the pre-migration base tables
#   4. the repo copied into $PORTAL_TEST_ROOT/site/portal with a test config.php
#   5. migrate.php run twice from the CLI as admin (second run must be a clean no-op)
#   6. tests/seed.php = deterministic fixtures (companies, tires, series, renders, library,
#      posts in every state incl. drafts and a 3-image carousel, emails / flows / pages)
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

need() { command -v "$1" >/dev/null 2>&1 || { echo "bootstrap: '$1' is required ($2)" >&2; exit 2; }; }
need php "PHP 8 CLI with pdo_mysql + gd"
need rsync "apt-get install rsync"
need mysql "apt-get install mariadb-server mariadb-client"
php -r 'exit(extension_loaded("pdo_mysql") && extension_loaded("gd") ? 0 : 1);' \
    || { echo "bootstrap: PHP needs the pdo_mysql and gd extensions" >&2; exit 2; }

# ---- 1. MariaDB ---------------------------------------------------------------
if ! mysqladmin ping >/dev/null 2>&1 && ! mysql_root -e 'SELECT 1' >/dev/null 2>&1; then
    echo "• starting MariaDB"
    if command -v service >/dev/null 2>&1 && service mariadb start >/dev/null 2>&1; then :;
    elif command -v service >/dev/null 2>&1 && service mysql start >/dev/null 2>&1; then :;
    elif command -v mysqld_safe >/dev/null 2>&1; then (mysqld_safe --user=mysql >/dev/null 2>&1 &);
    else echo "bootstrap: cannot start MariaDB — start it by hand" >&2; exit 2; fi
    for _ in $(seq 1 30); do mysql_root -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
fi
mysql_root -e 'SELECT 1' >/dev/null || { echo "bootstrap: MariaDB is not answering as root" >&2; exit 2; }

# ---- 2–3. database + base schema -------------------------------------------------
echo "• database ${PORTAL_TEST_DB}"
mysql_root <<SQL
DROP DATABASE IF EXISTS \`${PORTAL_TEST_DB}\`;
CREATE DATABASE \`${PORTAL_TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${PORTAL_TEST_DB_USER}'@'localhost' IDENTIFIED BY '${PORTAL_TEST_DB_PASS}';
GRANT ALL ON \`${PORTAL_TEST_DB}\`.* TO '${PORTAL_TEST_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
mysql_root "$PORTAL_TEST_DB" < "$TESTS_DIR/schema.sql"

# ---- 4. site copy -----------------------------------------------------------------
echo "• site ${SITE_DIR}"
rm -rf "$APP_DIR/uploads" "$MEDIA_DIR" "$SESSION_DIR"
sync_site
mkdir -p "$APP_DIR/uploads" "$MEDIA_DIR"

# ---- 5. migrate (twice) ---------------------------------------------------------------
run_migrate() {
    local out
    out="$(cd "$APP_DIR" && PORTAL_TEST_ROLE=admin php_test migrate.php 2>&1)" || { echo "$out" >&2; return 1; }
    if ! grep -q 'Migration complete' <<<"$out" || grep -q 'class="err"' <<<"$out"; then
        echo "$out" | sed -e 's/<[^>]*>//g' | grep -v '^\s*$' | tail -20 >&2
        return 1
    fi
    # "✓ … 0 companies / 0 events" lines are reports, not changes
    grep '<li class="ok">' <<<"$out" | grep -cv ' 0 ' || true
}
echo "• migrate.php (fresh)"; applied="$(run_migrate)"
echo "  ${applied} step(s) applied"
echo "• migrate.php (re-run)"; again="$(run_migrate)"
if [ "${again:-0}" != "0" ]; then echo "bootstrap: migrate.php re-run applied ${again} step(s) — not idempotent" >&2; exit 1; fi

# ---- 6. seed ---------------------------------------------------------------------------
echo "• seed"
php "$TESTS_DIR/seed.php" "$APP_DIR" "$MEDIA_DIR"
echo "bootstrap: ok"
