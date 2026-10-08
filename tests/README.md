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
open "http://127.0.0.1:8099/portal/?client=kenda&__role=client"   # client seat (test sign-in as Kenda's first contact)
open "http://127.0.0.1:8099/portal/?client=kenda&__role=client:privacybee"   # pinned to Privacy Bee → refused here
open "http://127.0.0.1:8099/portal/sign-in.php?__role=anon"   # nobody: the real magic-link sign-in (emails land in $PORTAL_TEST_ROOT/mail)
php tests/seed.php /tmp/portal-test/site/portal /tmp/portal-test/site/media   # reset the data
```

Requirements: PHP 8 CLI with `pdo_mysql`, `gd`, `curl`; MariaDB (or MySQL) server + client;
`rsync`; Node 18+ with Playwright (`/opt/node22/lib/node_modules/playwright` or a global
install) and Chromium (`/opt/pw-browsers`, or `PLAYWRIGHT_BROWSERS_PATH`). On Debian/Ubuntu:
`apt-get install mariadb-server php-cli php-mysql php-gd php-curl rsync`.

## Layout

| Path | What |
|---|---|
| `env.sh` | Shared settings: `PORTAL_TEST_ROOT` (default `/tmp/portal-test`), port 8099, DB `portal_test` (user/password `portal_test`), `sync_site` (test `config.php` with the canonical keys only: `mail_sink_dir` = `$PORTAL_TEST_ROOT/mail` (= `MAIL_DIR`), the fake Slack on port `PORTAL_TEST_PORT + 1000`, the fake Google on `PORTAL_TEST_PORT + 2000` (+100 more when that is a port Chromium refuses, e.g. 10080 for port 8080), a `client_link_secret`), `php_test`. A second stack runs beside the default one with e.g. `PORTAL_TEST_ROOT=/tmp/portal-test-email PORTAL_TEST_PORT=8087 PORTAL_TEST_DB=portal_test_email` (stubs then on 9087 / 10087). |
| `schema.sql` | The **pre-migration** base tables (companies, posts, post_images, tires, tire_images, categories, post/tire_categories, tasks). Everything else comes from `migrate.php`, so every bootstrap also proves the migration upgrades an old database and is idempotent (it runs twice; the second run must apply nothing). |
| `seed.php` | Deterministic fixtures (ids are stable — see the header; client contacts 1–4, no sessions, clean links off): Kenda (tires, series, renders in every status, references, library + a video, posts in every state incl. two drafts and a 3-image carousel), Privacy Bee (emails in every state, groups, a flow, pages), Hollow Mill Farm (empty). Images are generated with GD. |
| `bootstrap.sh` | Builds the stack from scratch. |
| `serve.sh` | `php -S` (4 workers — the Clean links installer requests its own server) with `router.php` and the `test-auth.php` prepend; log in `$PORTAL_TEST_ROOT/server.log`. |
| `router.php` | Serves `/portal/*.php` in one execution (so the prepend applies) and static files. When `<site>/portal/.htaccess` carries the Clean links block (Manage → Tools → Clean links), it emulates those rules: `/portal/<name>` → `<name>.php`, the machine endpoints 404, everything else → `route.php`. Without the block extensionless paths 404 — the "rewrites off" fallback. |
| `test-auth.php` | Seat shim: `?__role=admin|client|client:<slug>|anon` (or cookie `portal_test_role`, or `PORTAL_TEST_ROLE` on the CLI). `client` = a real client session (row + `jsm_client` value) for the first contact of the client the request names; `client:<slug>` = pinned to one client (cross-client checks); `anon` = nobody; no role = the browser's own cookies. Header `X-Test-Sync: <token>` → `$PORTAL_TEST_ROOT/sessions/sync-<token>` appears once that request has fully finished (after-response work included; the Slack suites wait on it since php -S runs several workers). Inert unless `PORTAL_TEST=1`. |
| `run.sh` | Everything, plus a scan of the server log: any PHP warning / notice / fatal fails the run. |
| `smoke/lib.php` | `get()` / `post()` per seat (`admin`, `client`, `client:<slug>`, `anon`; a `Cookie` header adds browser cookies; replies carry every `Set-Cookie` line), `db()` / `q1()` / `rows()`, `test()` / `ok()` / `is()` / `has()` / `status()`, `tmpImage()`. |
| `smoke/18-auth.php`, `e2e/14-auth.js` | Client sign-in (magic links, sessions, rate limits, revoke, deep links), the access gate (cross-client sweep over every page and endpoint), View as client, and clean links (route map, guarded `.htaccess` writer, live install, old → clean 301s, fallback). `14-auth.js` writes review screenshots to `$AUTH_SHOTS_DIR` (default `$PORTAL_TEST_ROOT/shots/auth`). |
| `smoke/19-integration.php` | Notifications × sign-in: one `notifyEmail()` (sign-in links through the outbox, retry, scrub), config aliases, clean / signed links in notifications, the router never capturing `slack-events` / `slack-actions` / `notify-cron` / `notify-thumb`, the client contact as author. |
| `google-stub.php` | A fake Google on its own `php -S` (port `PORTAL_TEST_PORT + 2000`): the OAuth consent page (instant consent → `?code&state`), `/token` (authorization code + refresh), `/revoke`, and the Gmail API (`profile`, `messages/send`, `messages` list with `-label:`, `messages/{id}?format=raw`, `modify`, `labels`). State in `$PORTAL_TEST_ROOT/google` (`mailbox.json` = received mail the tests drop in, `sent/*.eml`, `calls.jsonl`, `account.txt`, `fail.txt` = `invalid_grant` / `no_modify` / `send500` / `list500`, `rewrite_mid.txt` = replace the Message-ID on send like Gmail may; `messages/sent<n>?format=metadata` reads it back). The test config points `google_api_base` / `google_oauth_base` at it; `mail_transport` stays `sink`. |
| `smoke/20-email.php`, `e2e/15-email.js` | Email through Google + client emails + tracking: Connect Google (state / CSRF, admin only, account + scope checks), tokens encrypted at rest, the transport choice, the Gmail transport (MIME parsed back, threading, token refresh), client email batches (windows, per-recipient signed links, unsubscribe + preferences, switches, never internal notes or another client's data), inbound replies (In-Reply-To, `[J#…]` token, sender check, Gmail / Outlook / Apple quote stripping, attachments, dedupe, the unmatched list), the Joust Inbox, unread markers, the weekly report, previews. `15-email.js` writes screenshots to `$EMAIL_SHOTS_DIR` (default `$PORTAL_TEST_ROOT/shots/email`). |
| `smoke/21-notif-fixes.php`, `e2e/16-notif-fixes.js` | The fixes after the notification re-score: inbound sender authentication (Google's Authentication-Results → "Failed sender check"), client emails off by default and held until Google (migrate 47 / 50 / 51, sign-in exempt), staging's own inbound address, the real Gmail Message-ID (stub `rewrite_mid.txt`), portal internal notes, asset reviews, gentle reminders, quiet hours, My notifications + Inbox "Mine", the client Home "Joust replied" card, the no-Slack-channel email. Round 2: the quote- and comment-aware Authentication-Results parser (injection variants), Lance's own replies via Gmail's `SENT` label (the stub serves `labelIds`, and list honours `labelIds=`), reminder caps (one per client per N days, ≤ 2 per item, stop on client activity, a 30-day simulation), held client emails expiring after 72 h, staging detection, seen markers on the Needs-changes notice and asset deep links. Round 3: client tab badges = To Review + unread Joust replies (never twice; `data-badge-review` / `data-badge-replies`, aria "2 to review, 1 new reply"; admin unchanged), the Morning summary + weekly report for every active teammate with the switch on (scoped to the clients they own, one per person per day), one combined escalation per item after quiet hours. Round 4: the comment-embedded injection (the audit's n1–n3) and its variants — quoted strings inside comments are opaque, nothing inside a comment is trusted, anything malformed or ambiguous (open quote in a comment, stray `)`, deep nesting, control bytes) rejects; a Joust (@joustmedia.com) From is accepted only from the connected mailbox or a `google_mailbox_aliases` address via `SENT` with no Authentication-Results, never on DKIM / DMARC; Lance sets each teammate's Morning summary / weekly / Slack DM / escalation email in Manage → Team (new teammates: summary + weekly off; Lance's row unchanged; ownership untouched); staging emails only `staging_allowed_email_domains` contacts ("blocked on staging" in the Delivery log + Manage); the My notifications title fits at 390. In the browser: unread dots clearing on deep links (Inbox, Slack link, emailed link, the Needs-changes sheet, a library image, a tire series); the client tab badge dropping live when an item is opened. `16-notif-fixes.js` writes screenshots to `$NFIX_SHOTS_DIR` (default `$PORTAL_TEST_ROOT/shots/nfix`). |
| `smoke/22-redo-move.php`, `e2e/17-redo-move.js` | The Redo queue (migrate 52; `redo-lib.php`, `redo.php`): marking one / many with an optional note (an internal comment), validation, cross-client and client 403, the automatic queue on Needs changes, the client's "Being reworked" (never the note), the Redo page / chip / Home row, the redo pack (Client/Tire/Series/original + `.txt` + `redo-index.csv`, original bytes, "only new since last export", every client), Replace (tire + library, `replace-image.php` / `upload-chunk.php` / Replace from folder by name and by folder path) back to To Review, Remove from redo; Move to tire (`library-move.php`: file moved, row mapped, status / comments / previews / redo kept, `-2` on a clash, New series…, cross-client 403, client 403); migrate 52 backfill + idempotence; View as client wording. The smoke suite re-seeds before every test. `17-redo-move.js` writes screenshots (the Redo view, the mark sheet, the move sheet — 1440 dark, 390 light) to `$REDO_SHOTS_DIR` (default `$PORTAL_TEST_ROOT/shots/redo`). A separate stack: `PORTAL_TEST_ROOT=/tmp/portal-test-redo PORTAL_TEST_PORT=8078 PORTAL_TEST_DB=portal_test_redo` (stubs on 9078 / 10078). |
| `smoke/23-brand.php`, `e2e/18-brand.js` | The Joust mark (`static/brand/joust.png`, `joustAvatar()`) as the admin seat's brand: the sidebar brand (Joust mark + "Joust Media" + the scoped client / "All clients"), the phone eyebrow mark, the trailing avatar on unscoped admin pages, the Joust bubble's mark in comment threads (both seats, "Joust replied" too); the client seat keeps its own logo. `18-brand.js` checks 1440 + 390 in light and dark (loaded, size, round, ring colour) and writes screenshots (admin Home, Manage, a client page as admin, a comment thread — 1440 dark, 390 light) to `$BRAND_SHOTS_DIR` (default `$PORTAL_TEST_ROOT/shots/brand`). |
| `smoke/24-comment-edit.php`, `e2e/19-comment-edit.js` | Comment editing (migrate 53; `comment-edit-lib.php`, `comment-edit.php`): the client edits / deletes its own comments at any time (also after Joust replied; any contact of the company, the label names who), never Joust's / internal / another client's (403 / 404, anon 401); the admin edits / deletes any comment incl. internal notes; `comment_revisions` keeps every text, admin History; the "edited" label (when + who) and the "Comment deleted" placeholder (admin: Show original); `[Slide N]` kept / changed; the Needs-changes note (validation ≥ 3, the hidden-post "Your note" in place); Slack — `chat.update` of the comment's own message (`comment_slack` ts, " _(edited)_" / "_comment deleted_"), a threaded "✏️ … edited a comment" note when no ts is stored, nothing for Joust's own replies; no escalation / client email / Slack event from an edit; the client edit's unread dot; feeds, Inbox, Home, the Morning summary and the redo pack `.txt` show the current text and drop deleted comments; every thread (post, email, page, library / tire viewer, add-email / add-page); the pre-migrate probe. In the browser: inline edit (Enter / Shift+Enter / Esc), Delete with confirm, History, the media viewer, the email sheet, a touch phone (⋯ visible, long-press menu). Screenshots → `$CEDIT_SHOTS_DIR` (default `$PORTAL_TEST_ROOT/shots/cedit`). A separate stack: `PORTAL_TEST_ROOT=/tmp/portal-test-cedit PORTAL_TEST_PORT=8074 PORTAL_TEST_DB=portal_test_cedit`. |
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
