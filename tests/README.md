# Test harness

A local copy of the portal (PHP built-in server + MariaDB) with deterministic data, HTTP smoke
suites and Playwright browser checks. Nothing here is deployed: `tests/**` is excluded in both
`.github/workflows/deploy*.yml`, and the seat shim only works when `PORTAL_TEST=1` is set.

## Run

```sh
tests/run.sh                  # fresh DB + site, every smoke suite, every e2e script → "ALL GREEN"
tests/run.sh --no-bootstrap   # keep the DB/site, re-sync code, re-seed before each suite
tests/run.sh smoke            # smoke suites only          tests/run.sh e2e   Playwright only
tests/run.sh smoke/03         # one suite (path substring)
SMOKE_VERBOSE=1 tests/run.sh  # print every passing test name
```

By hand:

```sh
tests/bootstrap.sh            # MariaDB up, DB + user, schema.sql, site copy, migrate.php ×2, seed
tests/serve.sh                # php -S on http://127.0.0.1:8099/portal/  (tests/serve.sh stop | sync)
open "http://127.0.0.1:8099/portal/?client=kenda&__role=admin"    # admin seat (sticky cookie)
open "http://127.0.0.1:8099/portal/?client=kenda&__role=client"   # client seat
php tests/seed.php /tmp/portal-test/site/portal /tmp/portal-test/site/media   # reset the data
```

Requirements: PHP 8 CLI with `pdo_mysql`, `gd`, `curl`; MariaDB (or MySQL) server + client;
`rsync`; Node 18+ with Playwright (`/opt/node22/lib/node_modules/playwright` or a global
install) and Chromium (`/opt/pw-browsers`, or `PLAYWRIGHT_BROWSERS_PATH`). On Debian/Ubuntu:
`apt-get install mariadb-server php-cli php-mysql php-gd php-curl rsync`.

## Layout

| Path | What |
|---|---|
| `env.sh` | Shared settings: `PORTAL_TEST_ROOT` (default `/tmp/portal-test`), port 8099, DB `portal_test` (user/password `portal_test`), `sync_site`, `php_test` |
| `schema.sql` | The **pre-migration** base tables (companies, posts, post_images, tires, tire_images, categories, post/tire_categories, tasks). Everything else comes from `migrate.php`, so every bootstrap also proves the migration upgrades an old database and is idempotent (it runs twice; the second run must apply nothing). |
| `seed.php` | Deterministic fixtures (ids are stable — see the header): Kenda (tires, series, renders in every status, references, library + a video, posts in every state incl. two drafts and a 3-image carousel), Privacy Bee (emails in every state, groups, a flow, pages), Hollow Mill Farm (empty). Images are generated with GD. |
| `bootstrap.sh` | Builds the stack from scratch. |
| `serve.sh` | `php -S` with `router.php` and the `test-auth.php` prepend; log in `$PORTAL_TEST_ROOT/server.log`. |
| `router.php` | Serves `/portal/*.php` in one execution (so the prepend applies) and static files. |
| `test-auth.php` | Seat shim: `?__role=admin|client` (or cookie `portal_test_role`, or `PORTAL_TEST_ROLE` on the CLI). Inert unless `PORTAL_TEST=1`. |
| `run.sh` | Everything, plus a scan of the server log: any PHP warning / notice / fatal fails the run. |
| `smoke/lib.php` | `get()` / `post()` per seat, `db()` / `q1()` / `rows()`, `test()` / `ok()` / `is()` / `has()` / `status()`, `tmpImage()`. |
| `smoke/NN-*.php` | HTTP-level suites (re-seeded before each one). |
| `e2e/lib.js`, `e2e/NN-*.js` | Playwright scripts (desktop 1440×900 and phone 390×844 contexts). Screenshots on failure go to `$PORTAL_TEST_ROOT/shots/`. |

The site is an rsync **copy** of the repo at `$PORTAL_TEST_ROOT/site/portal` (with its own
`uploads/` and a test `config.php`); `media/` sits beside it like on the server. Edit code in the
repo, then `tests/serve.sh sync` (run.sh does it for you).

## Adding a suite

```php
<?php
require __DIR__ . '/lib.php';
test('drafts never reach the client', function () {
    $r = status(get('posts.php?client=kenda&post=6&partial=1', 'client'), 404);
});
finish();
```

Name it `smoke/NN-topic.php` (run order = file name). Use the seed ids from `seed.php`; never
depend on another suite's writes. Playwright scripts follow `e2e/01-new-menu.js`.
