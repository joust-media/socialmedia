# socialmedia
Social Media Builder

## Where it lives

- Production: `https://joustmedia.com/portal/` (server folder `portal/`, deployed by
  `.github/workflows/deploy.yml` on every push to `main`).
- Staging: `https://joustmedia.com/portal-staging/` (server folder `portal-staging/`,
  deployed by hand via Actions > "Deploy to staging").
- The public folder used to be `/socialmedia/`. The app derives its URL prefix at runtime
  (`basePath()` in `helpers.php`), so no code refers to the folder name; the GitHub repo and
  the MySQL database keep the name `socialmedia`.
- Old links: drop the two files from `redirect-old-folder/` into the old, now-empty
  `socialmedia/` server folder and every `/socialmedia/...` URL 301s to `/portal/...` with
  its query string intact. That folder is excluded from deploys. See
  `redirect-old-folder/README.md`.

## Daily digest cron (cPanel > Cron Jobs)

```
0 13 * * * curl -fsS "https://joustmedia.com/portal/digest.php?source=cron" >/dev/null 2>&1
```

## Emails

A per-client module for reviewing lifecycle / marketing emails the same way posts are
reviewed: each email has a code (the spreadsheet's "ID", e.g. `C1`, `R3`, `CX-017`), a title,
subject line, preview text, trigger, optional send date, priority, groups (sequences such as
Free / Pro / Renewal) and a link to the rendered HTML. Statuses: **Draft** (admin only),
**To Review** (waiting on the client), **Approved**, **Needs changes** (denied with a note,
admin work queue) and **Live** (marked active by Joust; wins over the status for display).
Clients see To Review / Approved / Live rows and can approve, deny with a note, or comment;
Joust does everything else. Decisions and comments land in the activity feed, the daily
digest, and the Home screen (a "N emails ready for your review" card, Live emails with a
future send date under "Coming up", and "M emails need changes" + the latest client notes on
the admin's "Needs changes" card).

**After deploying**, open `migrate.php` while signed in as admin. It is idempotent and
creates `emails`, `email_groups` and `email_group_map`, and seeds the `emails` row in
`modules`. Until it has run, every page renders as if the client had no emails.

**Enabling the tab for a client**: Studio → Emails → Enable (adds one
`company_modules (company_id, module_id)` row; the same row by hand in phpMyAdmin works
too). The Emails tab also appears automatically once a client has at least one email row.

**Pages**

| URL | What it does |
|-----|--------------|
| `emails.php?client=<slug>[&status=pending\|approved\|live\|denied\|draft\|all][&group=<slug>][&q=…][&email=<id>]` | List + detail sheet. Default segment is To Review; `email=<id>` deep-links one email (the segment follows the row). `denied` / `draft` are admin only. |
| `email-status.php` (POST) | Approve / deny (note of at least 3 characters required) / comment / send for review / reset / mark live / delete. Mirrors `status.php`. |
| `add-email.php?client=<slug>[&edit=<id>]` | Admin create / edit form, including groups. |
| `emails-io.php?client=<slug>&format=csv\|json` | Admin export (GET) and import (POST `file`, optional `dry_run=1`). |
| `studio.php?client=<slug>&tab=emails` | Counts, add / import / export, group management, module enable. |

**CSV contract** (header row, this order; matched case-insensitively on import):

```
Status, ID, Title, Sequence, Trigger, Subject Line, Preview Text, View Email, URL, Priority, Groups, Latest Note, Updated
```

- `Status` uses the spreadsheet's labels, emoji optional on import: `⚪ Not Started` (Draft),
  `🔴 Waiting Approval` (To Review), `🔵 Ready for Dev` (Approved), `🟣 Update Design`
  (Needs changes), `🟢 Active` (Live; imports as approved + live). Blank = Draft for new rows,
  "leave unchanged" for existing rows. Any other label imports as Draft and is reported.
- `ID` is the match key within the client (case-insensitive, trimmed): existing code = update,
  new code = create. A blank `ID` skips the row (reported); a duplicate `ID` later in the same
  file is ignored (reported as `duplicate`; the first occurrence wins).
- `#ERROR!` and `Active 👍` cells are treated as blank in every column except `Status`.
- `Sequence` = the first group; `Groups` = all groups, pipe-separated (`Free|Pro`). On import
  the sequence is added to the groups when missing; unknown group names are created.
- `Trigger` keeps line breaks. `Priority` = Low / Medium / High (anything else = blank).
- `View Email`, `Latest Note` and `Updated` are export-only and ignored on import.
- Only the columns present are updated; a present-but-blank cell clears that field.
- Export is UTF-8 with BOM, CRLF rows, file name `<slug>-emails-YYYYMMDD.csv`. `format=json`
  gives the same data as JSON (`{version, company, groups, emails}`) and imports back.

### Flows

A **flow** is a named, ordered sequence of a client's emails ("Free" = F1 → F4 → F3 …; series
can be mixed) shown as a vertical timeline on `flows.php?client=<slug>[&flow=<flow-slug>]`,
with the timing between steps on the connector (a per-step override, else the email's first
trigger line). Clients only view flows and never see Draft / Needs-changes steps; Joust edits.

- **Migration**: `migrate.php` steps 23–24 create `email_flows` and `email_flow_steps`
  (idempotent, nothing existing is altered). Until they exist the Flows button, chips and
  page stay hidden ("Flows are not set up yet").
- **First flows**: Studio → Emails → **Suggest flows from series** creates one flow per code
  series present (F Free, N Essentials, P Pro, G Signature, L Lead, R Renewal, S System, … in the
  series order), each with that series' emails ordered by their number; a series whose flow
  already exists is skipped. "New flow" on `flows.php` starts an empty one.
- **Edit mode** (`flows.php` → Edit, or `&edit=1`): drag handles / up-down buttons reorder
  steps, "Add email" / the "+" on a connector insert from a picker of the client's emails,
  "×" removes a step (the email is kept), tapping the timing pill edits the override; the
  "…" menu renames, reorders or deletes flows. Every change posts to `flow-status.php`
  (admin only, same-site, scoped to the posted client) and lands in the activity feed and digest.
- **Export / import**: `emails-io.php?client=<slug>&format=json` now carries a `flows` list
  (name, slug, description, steps by email code with 0-based positions and timing overrides)
  and imports it back (upsert by slug; unknown codes are reported, not created).
  `format=flows-csv` downloads `<slug>-flows-YYYY-MM-DD.csv`, one row per step
  (`Flow, Position, ID, Title, Timing, Trigger, Subject Line, Preview Text, Status, URL`, Position 1-based).
- Deleting an email removes it from every flow; an email's detail sheet lists the flows it is in.

## Tire render series

Each tire ("collection" in Assets) can carry any number of **series** — folders of generated
real-life renders (images and MP4/WebM/MOV videos, typically ~200 per series) that the client
reviews with the same approve / deny-with-note flow as the reference images. Approved renders
join the Approved Pool and the composer like any tire image.

- **Folder layout** (a sibling of `portal/`, next to the library): `media/tires/<tire-slug>/<series-folder>/<file>`.
  The tire slug is the tire name lower-cased with runs of non-alphanumerics turned into `-`
  ("Klever R/T" → `klever-r-t`; two tires with the same slug: the older keeps it, the newer gets
  `-<id>`); the edit screen in Studio and the Renders tab show the exact folder (with a Copy
  button). Any subfolder becomes a series named after it (`series-1` → "Series 1");
  dot-folders, dotfiles, symlinks, non-media files and the `.mp4` twin of a `.mov` are ignored.
- **Two ways in**:
  1. **FTP**: create `media/tires/<tire-slug>/<series-folder>/` (create `media/tires/` next to
     `media/library/` the first time), drop the files, then open Assets → Collections — the folder
     is rescanned on every collections view, throttled by folder mtimes + 60 s — or press
     **Rescan folders** in Studio → Renders (`tire-status.php` `action=rescan`, admin; the page
     falls back to `assets.php?…&rescan=1`, also admin-only). Existing rows are never touched; a
     removed file only stops showing up (its decisions stay).
  2. **Upload in the portal**: Studio → **Renders** → pick the tire → pick a series or "New
     series…" → drop files. One request per file (`tire-upload.php`, admin, same-site, 10 MB
     images / 200 MB videos, sequential queue with progress + Retry), stored under the series
     folder as `<original stem>.<ext>` (de-duplicated `-2`, `-3` …; the folder is created with
     0755), falling back to `uploads/` when `media/tires` is not writable. Every file is
     sniffed: images must decode as the format their extension claims, videos must carry the
     container magic; anything else is 422.
- **Review**: Assets → Collections → the tire shows a series switcher (Reference · Series 1 ·
  …, default = the first series with something to review) over a paged grid (60 tiles + "Load
  more"; the viewer keeps fetching as it walks). **Approve all remaining** (client or admin)
  approves every pending render of the open series in one request. The admin "…" menu renames /
  deletes the series (optionally deleting the files); in the viewer the admin can **Set as
  reference** (moves the image to the tire's reference set, sort_order 0) and **Delete image…**
  (row + file + thumb). Series renders show in the Approved Pool grouped per collection with
  series chips.
- **Comments** (every Assets image — library and tire, any status, both seats): the viewer has a
  "Comments (N)" panel under Approve / Deny with the image's thread (deny notes and replies, client
  vs Joust bubbles) and a composer (Enter sends on desktop). The thread is fetched per image
  (`assets.php?…&partial=comments&kind=tire|library&id=<id>` → `{count, html}`); posting is
  `action=comment {id, comment}` to `tire-status.php` / `library-status.php` (1–2000 characters,
  tenant-checked, same-site). Tiles with comments carry a count bubble (one grouped query per
  page); the admin's Home "Latest notes" merges client comments on assets from the last 7 days
  with the post / email notes, each linking to the viewer.
- **Thumbnails**: `<series>/.thumbs/<stem>.jpg` (max 640 px) are generated with GD, up to 40 per
  page view, so a 200-image grid stays light; the viewer / downloads / posts use the original.
- **Migration**: `migrate.php` steps 25–26 create `tire_series` and add `tire_images.series_id`
  (one idempotent `ALTER TABLE tire_images ADD COLUMN series_id INT UNSIGNED NULL` — the only
  change to an existing table). Until they exist everything behaves as before ("Render series
  are not set up yet").
- **Endpoints**: `tire-status.php` gains `approve_series` (client or admin), `delete_image`,
  `set_reference`, `series_create` / `series_rename` / `series_delete` / `series_reorder`,
  `rescan` (admin, same-site, scoped to the posted client). Reference images (the ≤6 in Studio)
  are the rows without a series; the 6-image cap counts only those.
- **`media/` hardening**: the first upload or rescan writes `media/tires/.htaccess` (and
  `media/.htaccess` when the parent has none) — `Options -Indexes`, PHP engine off, script
  extensions refused — so nothing dropped by FTP or upload can ever execute. Existing files
  are never overwritten; the text and the by-hand steps are in `media-hardening/`.

## Not deployed

`config.php` (live DB credentials), `uploads/`, `.htaccess` files, `error_log`, this README,
`redirect-old-folder/` and `media-hardening/` are excluded from both workflows and must be
managed on the server. `media/` lives outside the app folder, so deploys never touch it.
