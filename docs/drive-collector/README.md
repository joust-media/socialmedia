# Drive storage collector (Google Apps Script)

Measures the agency Google Drive every night with **metadata-only** access and posts a snapshot to
the portal (`drive-ingest.php`). The portal's storage view (`drive.php`) shows only what this script
sent. Nothing under `docs/` is deployed to the server; this folder is the source of the script.

Files: `Code.gs` (the script), `appsscript.json` (manifest: Drive advanced service v3 + the exact
OAuth scopes). Portal side: `drive-ingest.php`, `drive-lib.php`, `migrate.php` steps 30–34.

## What it reads and why the scope is safe

- `Drive.About.get` — the account's storage quota (limit, usage, usage in Drive, usage in trash).
- `Drive.Files.list` with `'me' in owners and trashed = false` — every owned file's id, name, MIME
  type, size / `quotaBytesUsed`, parent, modified / viewed / created times, md5 and web link.
- Scope: `https://www.googleapis.com/auth/drive.metadata.readonly` only (plus "connect to an external
  service" for the POSTs, "send email as you" for the threshold alerts, and "manage triggers" so a
  long run can schedule its own continuation). It cannot read file contents or change anything in
  Drive. The authorization screen must show exactly those.

## Install (one time, about ten minutes)

1. **Portal**: add `'drive_ingest_secret' => '<a random 32+ character string>'` to the server's
   `config.php` (not the repo copy — the file is excluded from deploys). Optionally
   `'drive_clients_root_folder_id' => '<folder id>'` as documentation of which folder holds the
   client folders. Open `migrate.php` while signed in as admin so steps 30–34 create the tables.
   Check `https://joustmedia.com/portal/drive-ingest.php?health=1` with
   `curl -H "Authorization: Bearer <secret>" …` → `{"ok":true,"configured":true,"migrated":true,…}`.
   If curl prints a `301` instead, the host strips `.php` — use the `Location` it points to
   (`https://joustmedia.com/portal/drive-ingest?health=1`) directly; that extensionless address is
   the one to put in `PORTAL_INGEST_URL` below.
2. **Script**: signed in as the Google account that owns the Drive, open https://script.google.com →
   New project → name it "Joust Drive collector". Replace the default `Code.gs` with this folder's
   `Code.gs`. Project Settings (gear) → tick **Show "appsscript.json" manifest file in editor**, then
   replace the manifest with this folder's `appsscript.json` (it enables the Drive advanced service
   and pins the scopes).
3. **Script properties** (Project Settings → Script properties → Add):
   - `PORTAL_INGEST_URL` = `https://joustmedia.com/portal/drive-ingest.php`, or
     `https://joustmedia.com/portal/drive-ingest` on hosts that strip `.php` (the live server
     301-redirects the `.php` form). The script never follows redirects — the bearer secret must
     not be replayed to another address — so the property must be the final URL. The alert
     emails link to `drive.php` / `drive` in the same form.
   - `INGEST_SECRET` = the same secret as `config.php`
   - `ALERT_EMAIL` = where the 80 / 90 / 95 % and "under 14 days" emails go
   - `CLIENTS_ROOT_FOLDER_ID` = *(optional)* the id (from its URL) of the folder whose sub-folders are
     the clients. Leave it out when the client folders sit directly in My Drive.
   - `MIN_SEND_BYTES` = *(optional)* skip files smaller than this; leave unset.
4. Run `verify` from the function dropdown → **Review permissions** → the consent screen lists
   *View metadata for files in your Google Drive*, *Connect to an external service*, *Send email as
   you*, *Allow this application to run when you are not present* (the trigger scope) — nothing that
   edits Drive. Check the execution log: portal health, quota, the first five files.
5. Run `setupTrigger` once (installs the nightly 2–3 AM trigger), then `runNightly` by hand for the
   first snapshot. The log ends with `Snapshot N complete: {...}`; `drive.php` shows it.
6. **Check the totals** before trusting the page: open https://one.google.com/storage in the same
   account and compare its Drive / Trash / total with the capacity strip on `drive.php` ("Drive files"
   there is Google's Drive figure minus the trash). The client tiles plus "(unfiled)" must add up to
   that "Drive files" figure; if they are more than 1 % apart the page shows the gap as a footnote
   (`quota_note`) — usually files in a client folder that someone else owns.

Time-zone: the manifest pins `America/New_York` (the trigger's clock). The portal converts every
timestamp it receives to its own zone.

## How a run works

`begin` (quota) → one `files` POST per Drive page of 1,000 (only files with `quotaBytesUsed > 0`) →
after the last page the folder rollups, client rollup and trimmed tree are computed here and posted as
`folders`, `clients`, `tree`, `quickwins`, `finish`. `finish` answers which threshold alerts are due;
each is emailed once and confirmed with `alerted`.

**Long Drives**: after five minutes the run parks its folder map on the portal (`?part=state`), stores
`nextPageToken` in a script property and schedules a one-off trigger a minute later that continues
where it left off. A parked run older than twelve hours is abandoned; the portal marks a partial
snapshot `failed` after 48 hours. `resetRun` clears a stuck run by hand.

Idempotent by design: every POST carries a SHA-256 of its body; the portal ignores a repeat.

## Definitions the portal applies (so the numbers match what you see)

- idle days = days since the later of *modified* and *last viewed by me*; **stale** = idle ≥ 180 days.
- offboard candidate = owned, ≥ 100 MB, idle ≥ 180 days; score = bytes × min(idle, 730) scaled to 0–100.
- burn rate = (usage now − usage 30 days ago) / 30 (or the oldest snapshot there is, labelled);
  days to full = free / burn rate, "not growing" when the rate is ≤ 0.
- duplicates = same md5 and size, more than one copy (Google-native Docs skipped);
  old versions = `name_v1.ext … name_v7.ext` in one folder, everything but the highest.
- "(unfiled)" = everything not inside a client folder (files in My Drive itself, other top folders).
- Client tiles + "(unfiled)" add up to the bytes of every owned file outside the trash; the quota bar
  uses Google's own numbers, and `quota_note` on the snapshot explains any gap over 1 %.

## Troubleshooting

- `verify` fails with 401 → `INGEST_SECRET` and `config.php` differ. 503 → the secret is missing in
  `config.php` (or shorter than 24 chars) or `migrate.php` has not run.
- `verify` fails with `HTTP 301 → Location: https://…/drive-ingest; set PORTAL_INGEST_URL to that
  address` → the host strips `.php`; redirects are refused by design, so set the property to the
  address in the message and run `verify` again.
- Authorization screen asks for full Drive access → the manifest was not replaced (the advanced
  service defaults to the full scope); fix the manifest and re-authorize.
- "Parked state … is missing" → the partial snapshot was pruned; run `resetRun`, then `runNightly`.
- Executions page (left sidebar) shows every run with its log.
