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

## Tests

`tests/run.sh` builds a local copy (MariaDB + PHP's built-in server, a fresh database migrated
with `migrate.php`, deterministic fixtures) and runs the HTTP smoke suites and the Playwright
checks. See `tests/README.md`. `tests/` is never deployed.

## Posts: drafts and carousels

- **Draft** (`posts.status = 'draft'`, `migrate.php` step 35): a post Joust is still building.
  The client never sees it anywhere (lists, counts, badges, Home, activity, deep links;
  `status.php` answers 404 to the client seat). Studio uploads and batch posts start as drafts
  with an empty caption. **Send for review** (Posts → Draft, or the post sheet;
  `status.php action=submit`) moves it to To Review and needs a caption first. Until step 35
  has run, those paths keep the old behaviour (To Review, placeholder caption).
- **Carousels**: up to `POST_MAX_MEDIA` (20, `helpers.php`) images / videos per post.
  `add-post.php` takes them as `assets[]` / `claimed[]`, or as one ordered `media[]` list
  (`image:<id>`, `library:<id>`, `tire:<id>`, `claim:<token>`) that also reorders and
  removes current media on edit.
- **+ New** (admin, top right of every page): New post · Upload · New email · New page.

## Daily digest cron (cPanel > Cron Jobs)

```
0 13 * * * curl -fsS "https://joustmedia.com/portal/digest.php?source=cron" >/dev/null 2>&1
```

## Emails

A per-client module for reviewing lifecycle / marketing emails the same way posts are
reviewed: each email has a code (the spreadsheet's "ID", e.g. `C1`, `R3`, `CX-017`), a title,
subject line, preview text, trigger, optional send date, priority, **Audiences** (who it is for:
Free / Pro / Renewal …; `email_groups` in the database) and a link to the rendered HTML (an
external URL, or HTML hosted by the portal under `media/emails/<client>/`). Statuses: **Draft** (admin only),
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

**URLs**

| URL | What it does |
|-----|--------------|
| `emails.php?client=<slug>[&status=pending\|approved\|live\|denied\|draft\|all][&audience=<slug>][&q=…][&email=<id>]` | List + detail sheet. Default segment is To Review; `email=<id>` deep-links one email (the segment follows the row). `denied` / `draft` are admin only. `&group=<slug>` (the old name) still filters the same way. |
| `email-status.php` (POST) | Approve / deny (note of at least 3 characters required) / comment / send for review / reset / mark live / delete. Mirrors `status.php`. |
| `add-email.php?client=<slug>[&edit=<id>]` | Admin create / edit form, including audiences. |
| `emails-io.php?client=<slug>&format=csv\|json` | Admin export (GET) and import (POST `file`, optional `dry_run=1`). |
| `studio.php?client=<slug>&tab=emails` | Counts, add / import / export, audience management, module enable. |
| `assign.php` | Admin: move emails / pages to another client, add to a flow, set audiences, create from "+ New" — see **Assigning emails and pages** below. |

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
- `Sequence` = the first audience; `Groups` = all audiences, pipe-separated (`Free|Pro`). The
  column keeps its name so the client's sheet round-trips (an `Audiences` header imports too). On
  import the sequence is added to the audiences when missing; unknown names are created.
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

### Assigning emails and pages

Admin only (the client seat never gets the markup, and `assign.php` answers it with a JSON 403).

- **⋯ menu** on every email / page row and in the detail sheet: **Move to client…**, and for emails
  **Add to flow…** and **Set audiences…**. **Select** in the list header turns on multi-select; the
  bulk bar (above the tab bar on phones) runs the same three actions on the selection.
- **Move to client** moves the rows and their files: every file is copied into the new client's
  folder (`media/pages/<client>/<slug>/`, `media/emails/<client>/`), each copy is verified (size +
  SHA-1), the rows change in one transaction, and only then are the old files deleted; any failure
  removes the copies and changes nothing. A code / slug the new client already uses gets a `-2`
  suffix (reported in the toast). Emails keep their audiences (by name, created when missing) and
  leave the old client's flows; comments and history follow the item; a "moved" row is logged.
- **Add to flow**: pick a flow (or name a new one) and a position (end by default, first, or after
  any step). **Set audiences**: tick / clear, a dash = mixed in a selection (left as it is).
- **+ New → New email / New page** opens a sheet in place: client, title, then the HTML as a file,
  pasted, or a link (or nothing yet). It is saved as a Draft and its detail opens. Pasted / uploaded
  email HTML is stored as `media/emails/<client>/<code>-<id>.html` (shared media `.htaccess`, every
  directive guarded, plus a guarded `script-src 'none'` CSP); page HTML becomes the page's
  `index.html`. "Full form" (and the menu item's link without JavaScript) is the old form.
- `assign.php`: `GET ?action=options&client=&kind=email|page[&ids=]` and `POST action=move | add_to_flow |
  set_audiences | create_email | create_page` — contract in the file header. Tests:
  `tests/smoke/09-assign.php`, `tests/e2e/06-assign.js`.

## Pages

A per-client module for static HTML landing pages (and other one-off pages) that Joust
builds per client and the client reviews inside the portal. It is the Emails module's twin:
the same statuses (**Draft** admin only · **To Review** · **Approved** · **Needs changes**
with a note · **Live**, which wins for display), the same client verbs (approve, request
changes with a note of at least 3 characters, comment), the same admin verbs (send for
review, reset, mark / unmark live — only an approved page can go live, delete), the same
Home cards ("N pages ready for your review"; "M pages need changes" + the latest client
notes on the admin's Needs-changes card), activity feed lines, digest labels and Pages tab
badge (pending, not live). Clients only ever receive To Review / Approved / Live rows — the
filter is in SQL and re-checked on deep links, partials and the endpoint.

- **Migration**: `migrate.php` steps 27–28 create `pages` and `page_files` and seed the
  `pages` row in `modules` (28b). Idempotent; until it has run there is no Pages tab, no
  Studio tab, `pages.php` says "not set up yet" and the endpoints answer 404 / 409 / 503.
- **Enabling the tab for a client**: Studio → Pages → "Enable Pages tab", or the Pages
  toggle on the client's card in Studio → Clients (both write one `company_modules` row).
  The tab also appears automatically once the client has at least one page row.
- **Two sources per page**: *Upload* — the HTML and its assets live in
  `media/pages/<client-slug>/<page-slug>/` (a sibling of `media/tires/` and
  `media/library/`, outside the app folder, never touched by deploys) and the portal frames
  `/media/pages/<client>/<slug>/<entry>`; *URL* — an external `http(s)://` address framed
  like an email's rendered link (hosts that refuse framing still get "Open in new tab").
- **Uploading** (Studio → Pages → Edit, or right after "Create page"; `page-upload.php`,
  admin + same-site only, one file per request, ≤ 10 MB in one request — bigger assets and
  videos go in pieces, see *Large uploads*): `html htm css js json png jpg
  jpeg gif webp svg ico woff woff2 ttf mp4 webm` only. Anything server-side is refused
  anywhere in the dotted name (`.php .phtml .phar .cgi .pl .py .sh .shtml .shtm .stm .inc
  .asp .jsp .cfm .hta .htaccess` — so `x.php.html` and `x.shtml.html` are refused too),
  dotfiles and bad names are refused, an optional subfolder is `[a-z0-9_-]` segments at most
  4 deep, images must decode and match their extension, SVG must be an `<svg>` document,
  HTML / CSS / JS / JSON must not contain a PHP open tag, videos are sniffed. Uploading a
  file with a name that exists **replaces** it (that is how a page is updated); the first
  HTML file becomes the entry when none is set; "Set as entry" picks another `.html`.
  Renaming a page's slug moves its folder; deleting a page removes the folder (contained —
  a symlinked folder is refused and left alone).
- **Embedded images are extracted** (`.html` / `.htm`, single and chunked uploads, up to 64 MB
  of HTML): every base64 `data:` URI — images, SVG, fonts, CSS, MP4 / WebM, in `src` / `srcset` /
  `poster` / `href`, CSS `url()` in `<style>` and `style=""` — is decoded, validated (images must
  decode and match their type, SVG must be `<svg>` without `<script>` / `on*=`, fonts by magic
  bytes, videos sniffed) and written to `assets/<sha1-12>.<ext>` next to the HTML (identical
  blobs share one file); the reference becomes the relative path and the files join
  `page_files`. Invalid blobs stay inline and are counted as skipped; nothing else in the
  markup changes and the original single-file upload is not kept (you have the source). The
  uploader row says "Extracted 14 images · 5.4 MB → 180 KB". Why: cPanel's ModSecurity
  inspects `text/html` responses and rejects bodies over `SecResponseBodyLimit` (512 KB by
  default) with a **500** — images and video are not inspected. For pages already uploaded,
  **Extract embedded images** sits on the sheet's Server check line and under the Studio →
  Pages row whenever an HTML file is over ~400 KB (`page-upload.php` `action=extract_inline`);
  the sheet's preview is switched off (Open in new tab stays) while the entry is that large.
- **Troubleshooting a 500 on an uploaded page**: cPanel → **Metrics → Errors** shows Apache's
  reason. `ModSecurity: Output filter: Response body too large` → the HTML is over the host's
  limit: click *Extract embedded images*, reduce the file, or turn ModSecurity off for the
  domain (cPanel → **Security → ModSecurity**, per domain). `.htaccess` / permission lines →
  *Repair server rules*; more in `media-hardening/README.md`.
- **`media/` hardening** (`media-lib.php`, shared with Renders): every upload writes
  `media/pages/.htaccess` when missing and rewrites it when it carries an older marker of ours
  (`# joust-portal-media vN`): `Options -Indexes` first and alone, then — every line inside an
  `<IfModule>` guard so a cPanel host with PHP-FPM / LSAPI never answers 500 for the folder —
  PHP engine off, PHP / CGI handlers and types removed, the server-side-include filter removed
  (`.shtml .shtm .stm` and never `.html`), `.php* .cgi .pl .py .shtml .inc .htaccess` denied
  outright, `nosniff`. A file without our marker is never touched. The portal no longer writes
  `media/.htaccess` at the parent level and deletes one that carries our marker. Stored files
  are `chmod 0644`, created folders `0755`, whatever the umask (Apache reads them as another
  user on shared hosting). Studio → Pages → **Repair server rules** (`page-upload.php`
  `action=repair_media`) rewrites the rules and fixes permissions under `media/pages/<client>/`;
  the admin sheet shows a **Server check** line (file / folder bits, rules version) with a
  *Repair* button when something is off. Details: `media-hardening/README.md`.
- **Preview**: `<iframe sandbox="allow-scripts allow-same-origin allow-forms allow-popups">`
  with a Phone / Desktop toggle. `allow-same-origin` is deliberate — only the admin can
  upload, the files are Joust's own work and PHP is off under `media/` — but it means an
  uploaded page runs with the portal's origin. If clients ever get to upload, drop that one
  token in `partials/components/page-detail.php`.

| URL | What it does |
|-----|--------------|
| `pages.php?client=<slug>[&status=pending\|approved\|live\|denied\|draft\|all][&q=…][&page=<id>]` | List + detail sheet. Default segment is To Review; `page=<id>` deep-links one page. `denied` / `draft` are admin only. |
| `page-status.php` (POST) | Approve / deny (note required) / comment / `action=submit` / `toggle_live&to=0\|1` / `delete_page`. Mirrors `email-status.php`. |
| `page-upload.php` (POST, admin) | `page_id`, `client`, `action=upload` (`file`, optional `subfolder`, `batch`) / `delete_file` / `set_entry` (`name`) / `extract_inline` (embedded `data:` assets of every HTML file → `assets/`) / `repair_media`. |
| `add-page.php?client=<slug>[&edit=<id>]` | Admin create / edit form (title, slug, source, URL, entry file, description, status, live, notes) + the file uploader and delete on edit. |
| `studio.php?client=<slug>&tab=pages` | Counts strip, New page, the list with Edit, the Pages-tab toggle. |

## Tire render series

Each tire ("collection" in Assets) can carry any number of **series** — folders of generated
real-life renders (images and MP4/WebM/MOV videos, typically ~200 per series) that the client
reviews with the same approve / deny-with-note flow as the reference images. Approved renders
join Approved assets and the composer like any tire image.

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
  2. **Upload in the portal**: the **Upload sheet** (see *Upload sheet* below) — the series
     page's **Upload**, Studio → Renders' launcher, or "+ New → Upload" → Tire series → tire →
     series or "New series…". One file at a time (`tire-upload.php`, admin, same-site; images
     50 MB, videos up to 4 GB — large files go in pieces, see *Large uploads* below; progress,
     Retry and Cancel per file), stored under the series folder as
     `<original stem>.<ext>` (de-duplicated `-2`, `-3` …; the folder is created with 0755),
     falling back to `uploads/` when `media/tires` is not writable. Every file is sniffed:
     images must decode as the format their extension claims, videos must carry the container
     magic; anything else is 422.
- **Review**: Assets → Collections → the tire shows a series switcher (Reference · Series 1 ·
  …, default = the first series with something to review) over a paged grid (60 tiles + "Load
  more"; the viewer keeps fetching as it walks). **Approve all remaining** (client or admin)
  approves every pending render of the open series in one request. The admin "…" menu renames /
  deletes the series (optionally deleting the files); in the viewer the admin can **Set as
  reference** (moves the image to the tire's reference set, sort_order 0) and **Delete image…**
  (row + file + thumb). Series renders show under Approved assets grouped per collection with
  series chips.
- **Photos · Videos**: videos are a load on the server, so a series never puts them on the page
  unasked. A series that holds at least one video gets a small **Photos N · Videos N** control under
  its counts line and opens on **Photos**; `&type=photos|videos|all` is applied in SQL
  (`tireMediaTypeSql()`, media type = the file extension) so paging, `partial=1`, the filter chips
  and **Approve all remaining** (posts `type=`; "Approve all 3 remaining videos in Series 2?") all
  follow it. A deep link to a video opens the Videos view. The Videos grid renders play-glyph
  tiles with the file size — no `<video>` element, never a probe of the file; the viewer streams
  the one video on screen (`preload="metadata"`) and unloads it the moment you navigate away, and
  its "…" menu offers a direct **Download** link. The switcher chips show a subtle "3▶" for series
  with videos. In Approved assets, **Photos / Videos** chips filter client-side and videos start
  collapsed behind **Show N videos** so no posters are fetched for them by default.
- **Comments** (every Assets image — library and tire, any status, both seats): the viewer has a
  "Comments (N)" panel under Approve / Deny with the image's thread (deny notes and replies, client
  vs Joust bubbles) and a composer (Enter sends on desktop). The thread is fetched per image
  (`assets.php?…&partial=comments&kind=tire|library&id=<id>` → `{count, html}`); posting is
  `action=comment {id, comment}` to `tire-status.php` / `library-status.php` (1–2000 characters,
  tenant-checked, same-site). Tiles with comments carry a count bubble (one grouped query per
  page); the admin's Home "Latest notes" merges client comments on assets from the last 7 days
  with the post / email notes, each linking to the viewer.
- **Thumbnails**: every render gets the portal-wide image previews (see *Image previews* below):
  `sm` made at upload, and during a folder scan for at most 3 s per page view (the rest on first
  view through `preview.php`). Older `<series>/.thumbs/<stem>.jpg` (640 px) thumbs keep working
  until the preview replaces them.
- **Migration**: `migrate.php` steps 25–26 create `tire_series` and add `tire_images.series_id`
  (one idempotent `ALTER TABLE tire_images ADD COLUMN series_id INT UNSIGNED NULL` — the only
  change to an existing table). Until they exist everything behaves as before ("Render series
  are not set up yet").
- **Endpoints**: `tire-status.php` gains `approve_series` (client or admin; optional
  `type=photos|videos` limits it to that media type), `delete_image`,
  `set_reference`, `series_create` / `series_rename` / `series_delete` / `series_reorder`,
  `rescan` (admin, same-site, scoped to the posted client). Reference images (the ≤6 in Studio)
  are the rows without a series; the 6-image cap counts only those.
- **`media/` hardening**: the first upload or rescan writes `media/tires/.htaccess` (the
  `media-lib.php` text shared with Pages — `Options -Indexes`, then PHP engine off, script
  handlers removed and script-ish names refused, every directive `<IfModule>`-guarded) so
  nothing dropped by FTP or upload can ever execute; an older file of ours is rewritten, a
  foreign one never touched, and an old `media/.htaccess` of ours is removed. Uploads and
  thumbs are `chmod 0644` / folders `0755`. Studio → Renders → **Repair server rules**
  (`tire-upload.php` `action=repair_media`) rewrites the rules and fixes permissions under
  `media/tires/`. The text and the by-hand steps are in `media-hardening/`.

## Image previews

Every image the portal shows gets two derived copies next to its original — the original is never
changed:

| size | long edge | used for |
|---|---|---|
| `sm` | 480 px | grid tiles, lists, cards, strips |
| `lg` | 1600 px | viewer, post detail, carousels |

They live in a `.thumbs/` folder beside the original: `<dir>/.thumbs/<stem>.sm.webp` /
`<stem>.lg.webp` (`<stem>.sm.jpg` / `<stem>.lg.jpg` when the server's GD cannot encode WebP), plus `<stem>.dims.json`
(the original's size, for `width` / `height` attributes). WebP quality 78, JPEG 80; aspect ratio
kept; never upscaled (an original already smaller than the size is used as-is); EXIF rotation
applied; transparency kept in WebP (flattened onto white in JPEG); animated GIFs use the first
frame. SVGs and videos get no previews. Code: `preview-lib.php`.

- **When they are made**: right after each upload (renders, reference images, Compose / Uploads /
  Batch files, Approved assets picks — copied from the source's previews when it has them, Replace
  regenerates), within a per-request time budget. Anything not made yet — FTP drops into
  `media/library/` or `media/tires/`, big batches — is made on first view by **`preview.php`**:
  the page links `preview.php?f=<signed path>&s=sm|lg&v=<mtime>`, which makes the file once and
  serves it; the next page view links the static file. If a preview cannot be made (too large for
  the PHP memory limit even at 512 MB, damaged file, no GD) `preview.php` redirects to the
  original, so an image never breaks.
- **Backfill**: Studio → **Export** → *Image previews* → **Build previews** (optionally *All
  clients*) walks every tire image, library file and post image in ~15-second steps
  (`preview-job.php`) and reports how many were made, already up to date, failed or missing, and
  the bytes of the small previews against the originals. Safe to run any time; it only makes what
  is missing or stale.
- **Signed URLs**: `preview.php` only accepts paths the portal signed (HMAC-SHA256). Set a
  `'preview_secret' => '<32+ random characters>'` key in `config.php` to use your own key;
  without it the key is derived from the database credentials. Changing it only changes the lazy
  URLs (existing previews are static files).
- **Caching**: the portal's `.htaccess` text (marker `# joust-portal-media v3`, written to
  `media/tires/`, `media/pages/` and now `uploads/`) adds guarded `mod_expires` / `mod_headers`
  rules — 7 days for originals — and every `.thumbs/` folder gets its own `.htaccess` with a
  1-year `immutable` rule (preview URLs carry `?v=<mtime of the original>`, so a replaced image
  gets a new URL). Older v1 / v2 files of ours are upgraded on the next upload, scan, backfill or
  Repair; files without the marker are never touched. `uploads/` holds only media and data files
  (nothing there needs PHP), so it gets the same "static files only" rules.
- **Deleting**: removing an image, post, tire, series or page removes its previews too. Deleting a
  `.thumbs/` folder by hand is harmless — the previews come back on the next view.

## Exporting approved assets

Studio → **Export** (admin, scoped to the chosen client) builds one zip of everything the client
has approved, categorised by tire, and hands it over as a single download:

```
<Client Name>/
  manifest.csv          id, kind, tire, series, filename, media_type, bytes, status, approved_at,
  manifest.json         comments_count, drive_url, source_path (+ path in the JSON)
  <Tire Name>/
    Reference/<file>    the tire's reference images
    <Series Name>/<file>
  Library/<file>        approved library images (only for the "All approved" scope)
```

- **Options**: scope *All approved* · *One tire* · *One series*; include Photos (on), Videos (off —
  the size they would add is shown next to the box), Reference images (on), Library approved images
  (on, own folder). Only `approved` rows are ever included. The estimate line ("N files · X GB")
  is computed server-side (one `filesize()` per file) and refreshes as the options change.
  Folder and file names are filesystem-safe versions of the tire / series / display name
  ("Klever R/T" → `Klever R-T`); duplicates within a folder get `-2`, `-3`, …; the original
  extension is kept. `approved_at` is the latest `approved` activity row for the image, else its
  `updated_at`. The Assets series "…" menu has **Export approved…**, which opens the tab with that
  tire preselected.
- **How it builds** (`export.php`, admin + same-site; helpers in `export-lib.php`): *Build export*
  posts `action=start`, which lists the files into `uploads/.exports/<job>.json` (job = 32 hex,
  sidecar carries the company, options and the validated source paths; the folder is dot-prefixed
  with a deny-all `.htaccess`, so nothing in it is web-reachable), then the page calls `action=step`
  repeatedly. Each step appends about 64 MB / 15 s worth of bytes to `uploads/.exports/<job>.zip`
  and records where it got to — a file larger than the budget continues across steps — so no
  request runs long on shared hosting. The zip is written by the portal itself (entries *stored*,
  no compression, so no CPU is spent on media; zip64 records when a file or the archive passes
  4 GB; UTF-8 names), because `ZipArchive` rewrites the whole archive on every `close()`. The last
  step adds `manifest.csv` / `manifest.json` and the central directory. Every source path is
  re-validated through `tireImagePath()` / the library containment helper at step time; a file that
  disappeared mid-build is listed in the manifest as `missing`.
- **Download**: `action=download&job=…` (GET, only a finished job of the same client) streams the
  zip in 1 MB pieces with `Accept-Ranges` / a single `Range` honoured, so a dropped multi-GB
  download resumes. The file is named `<client>[-tire[-series]]-approved-assets-YYYY-MM-DD.zip`.
  **Recent exports** lists the last 24 hours' jobs (Continue an unfinished one, Download, Delete);
  jobs and zips older than 24 h are removed on the next `start`. A single export is capped at
  **20 GB** — split by tire above that. **Manifest CSV only** (`action=manifest`) downloads the
  file list without building a zip; on a 32-bit PHP build the tab offers only that.
- Staging shares `media/` with production, so an export built there contains the real files.

## New post pop-up (admin)

One sheet builds and edits every post: `static/js/newpost.js` (`App.newPost`) + `static/css/newpost.css`,
server `post-compose.php`, booted on every admin page by `partials/layout-bottom.php`
(`partials/components/new-post.php`). Nothing of it reaches the client seat.

- **Open it from**: "+ New → New post" (`App.newMenu` calls `App.newPost.open`), "New post" on Posts,
  Studio and Home, Assets → Select → "Create post with N" (approved items), the media viewer's ⋯ →
  "Use in post", the Posts detail ⋯ → "Edit post…" (edit mode), or any URL with `?newpost=1`
  (`=upload` opens the Upload pane, `=edit&post=<id>` edits, `&newpost_assets=tire:1,library:4`
  preselects). Retired routes redirect here: `studio.php?tab=compose` → `studio.php?newpost=1`,
  `add-post.php` → `posts.php?newpost=1`, `add-post.php?edit=<id>` → `posts.php?post=<id>&newpost=edit`,
  `studio.php?tab=batch` and `batch.php` → Studio → Uploads with the Upload sheet open (a draft post per file).
- **Layout**: header (client, close) · the slide tray pinned under it (numbered, slide 1 = Cover,
  drag or Alt+←/→ to reorder, Delete / × to remove, a tap opens Move left / right · Make cover ·
  Replace · Remove, "N / 20", an amber badge on slides whose shape differs from the cover because
  Instagram crops to slide 1) · Media pane (Approved grid grouped tire → Reference / series, then
  Library; tire chips multi-select, series chips, Photos / Videos, name search, 60 per page + Load
  more · Upload pane: chunked, each file joins the tray) · Details pane (caption with a 2,200 counter,
  hashtags + client defaults, date, type Auto / Post / Story / Reel, reference name, live preview) ·
  sticky footer (Save draft · Send for review; edit: Save changes / Save & resubmit). Phone: full
  screen with a Media → Details switch. Esc, focus trap, and an unsaved-changes guard.
- **`post-compose.php`** (admin + same-site, JSON; tenant = `?client=`): `init`, `clients`,
  `picker` (`tires`, `library`, `series=<tire>:<ref|id>`, `media`, `q`, `offset`, `limit` ≤ 60,
  `refs`), `load&id=`, `create` / `update` with `slides[]` in carousel order (`tire:<id>`,
  `library:<id>`, `upload:<token>`, and on update `image:<post_images.id>`) + caption, hashtags,
  scheduled_date, post_type ('' = auto: one video → Reel), name, `intent=draft|review|keep`.
  Approved assets are copied into `uploads/` (previews reused), uploads claimed; update rewrites
  `post_images.sort_order` in one transaction, deletes removed rows and unlinks a file only when no
  other row still points at it, and logs `edited_media`. 403 for the client seat, another tenant's
  post / asset / upload, or an unapproved asset; 422 for 21+ slides or a review without caption /
  slide / date; 409 for a draft before migrate.php step 35.
- **Carousel (both seats)**: `renderPostMedia()` + `static/js/carousel.js` (`App.carousel`):
  scroll-snap swipe, dots, "2 / 7", arrows on hover-capable pointers, ←/→ on the focused track,
  lg previews, only the visible slide's video plays. With 2+ slides the comment composer offers a
  "Slide" picker; the comment is stored as `[Slide 3] …` (no schema change) and the thread shows a
  slide chip (thumb + "Slide 3") that jumps the carousel there.

## Upload sheet (admin)

One uploader for every destination: `static/js/upload-sheet.js` (`App.uploadSheet`) + `static/css/upload.css`,
server `upload-sheet.php` (`clients`, `init`: tires + series + reference slots, limits, URLs), booted on every
admin page by `partials/layout-bottom.php` (`partials/components/upload-sheet.php`). Nothing of it reaches the
client seat.

- **Steps**: 1 Files (drop or pick; images 50 MB, videos 4 GB, up to 50 at a time; picking moves on) →
  2 Destination (radio cards: **Tire series** — tire, then series or "New series…" · **Tire reference** —
  tire, images only, 6 per tire · **Library** · **New post** — one post with the files as slides, or a
  draft post per file; files a destination cannot take are listed with the reason) → 3 Upload (one file at
  a time, progress, Cancel and Retry per file, "Retry all", a "Stop uploading?" guard, Resume after a
  reload). When everything is in the sheet closes with a toast and a link to where the files landed (already
  on that page → it reloads onto the destination's To Review list). **New post** hands the parked files to
  `App.newPost.open({preselect: [{ref: 'upload:<token>', …}]})`, so they arrive as slides.
- **Unscoped pages** ask for the client first.
- **Where each destination's files go:**
  - Tire series: `tire-upload.php`, stored in `media/tires/<tire>/<series>/`, a `pending` row.
  - Reference: `upload-chunk.php` `purpose=feature`, stored as `uploads/feat_*`, `pending`.
  - Library: `upload-chunk.php` `purpose=library`, stored in `media/library/<slug>/`, a `pending` row (the
    status an FTP drop gets). A name already used on disk or by an old row gets `-2`.
  - New post: `upload-chunk.php` `purpose=post` claims, then a Draft from the pop-up; or `purpose=batch`,
    then `batch-process.php`, one Draft per file.
  - Previews are made right after each store (`previewAfterStore`).
- **Open it from**:
  - "+ New → Upload" (`App.newMenu.handle('upload')`).
  - A contextual **Upload**, which arrives with the destination preselected (`uploadSheetAttrs()` →
    `data-upload-open data-upload-dest/-tire/-series/-each`). These are: the series header in Assets, the
    Reference card ("Add reference images" too), Assets → Library, Studio → Uploads (a draft post per file)
    and Studio → Renders (the picked tire + series).
  - Home "Upload".
  - Launchers marked `data-upload-drop`, which also take dropped files.
  - Any URL with `?upload=1[&dest=series|reference|library|post][&tire=][&series=<id>|new][&each=1]`
    (`uploadSheetUrl()`; the no-JS fallback of every button).
- **Retired** (redirect or link here): the Studio Uploads drop zone and Renders drop zone / queue,
  `batch.php` and `studio.php?tab=batch` (→ `…&tab=uploads&upload=1&dest=post&each=1`), and the
  "Add more images" file input on `add-feature.php` (→ Assets with the sheet on the tire's Reference). The
  New post pop-up keeps its own Upload pane (files straight into the post being edited).
- **Assets → Select (admin, approved items)**: one bottom bar with **Create post with N**, **Download**
  and **Export**. Download is a zip of the selection; Export is its manifest CSV. Both use
  `export.php` `scope=selection&items=tire:<id>,library:<id>,…`, with the same folder layout as Studio →
  Export and approved files only.

## Large uploads (chunked, resumable)

Shared hosting caps one request at `upload_max_filesize` / `post_max_size` (often 64 MB or
less) and at `max_execution_time`, so multi-GB files cannot arrive in one POST. Every upload
surface therefore speaks a small chunk protocol (`chunk-upload-lib.php` on the server,
`static/js/chunk-upload.js` in the browser); a file at or below one piece still goes in a single
request exactly as before. The surfaces and their endpoints:

| Surface | Endpoint | Caps |
| --- | --- | --- |
| New post pop-up → Upload | `upload-chunk.php` `purpose=post` → `upload:<token>` (or `claim:<token>`) slides to `post-compose.php`; `claim:<token>` in `media[]` / `claimed[]` to `add-post.php` for its JSON callers | videos 4 GB, images 50 MB, 20 slides per post (`POST_MAX_MEDIA`) |
| Upload sheet → New post · a draft per file | `upload-chunk.php` `purpose=batch` → `claimed[]` tokens to `batch-process.php` | videos 4 GB, images 50 MB, 50 files per batch |
| Upload sheet → Tire reference | `upload-chunk.php` `purpose=feature` (`feature_id`) | images 50 MB, 6 per item, images only |
| Upload sheet → Library | `upload-chunk.php` `purpose=library` | videos 4 GB, images 50 MB |
| Replace image / video (Posts detail, Assets viewer, `add-feature.php`) | `upload-chunk.php` `purpose=replace` (`replace_kind=post\|tire`, `replace_id`) — `replace-image.php` stays the single-request path | videos 4 GB, images 50 MB |
| Upload sheet → Tire series | `tire-upload.php` | images 50 MB, videos 4 GB |
| Studio → Pages | `page-upload.php` | HTML 64 MB, CSS / JS / JSON 10 MB, other assets 100 MB, MP4 / WebM 4 GB |
| Studio → Clients logo | `client-admin.php` (single request) | **Logos unchanged: 2 MB**, resized to 512×512 |

- **Protocol** (`action=`, every call admin + same-site, `upload_id` is 32 hex):
  `probe` (GET or POST) → `{chunk_size, max_file_bytes, ini_max, exts}` — `chunk_size` is
  min(8 MB, 80 % of the PHP request cap); `chunk_init {client, …target fields…, name, size,
  type}` (Renders: `tire_id, series_id | new_series, batch`; Pages: `page_id, subfolder`;
  `upload-chunk.php`: `purpose` + `feature_id` / `replace_kind` + `replace_id`) → `{upload_id,
  chunk_size, received: 0}`; `chunk_put {upload_id, index, offset, file}` → `{received}` (409 with
  the server's `received` when `offset` is not where the server is — the client re-syncs; an
  already-received range is a 200 no-op); `chunk_status` → `{received, size}`; `chunk_finish`
  runs the same extension / content checks as a single-request upload (images must decode as the
  format their extension claims, videos must carry the container magic) and finalizes — same
  reply shape as the single path; `chunk_abort` deletes the pieces. `upload-chunk.php` also takes
  `action=upload` (one multipart request with the same fields + `file`) for small files, so
  `App.chunkUpload.upload()` is the one browser call for any size.
- **Claim tokens** (Compose / Uploads / Batch): the file is validated and parked as
  `uploads/tmp_<token>.<ext>` with a sidecar `uploads/.spool/<token>.claim` (purpose, client,
  name, size); the reply is `{token, name, size, type, preview_url}` and the form submits
  `claimed[]`. `add-post.php` / `batch-process.php` check each token (32 hex, sidecar purpose +
  client match, file directly inside `uploads/`, younger than 24 h — a bad one is a 400 in the
  composer and a per-row error in a batch, nothing is saved), rename the file to its final
  `img_` / `vid_` / `batch_` name and insert the row exactly as for a direct upload. Removing a
  row before submitting posts `action=claim_discard`; anything unclaimed is swept after 24 h.
  Publish / Create posts wait until every file is in.
- **Spool**: `<root>/.spool/<upload_id>.part` + `.json` sidecar (owner client, target, name,
  size, received), 0600, in a dot-folder the folder scans skip, with its own deny-all
  `.htaccess` — `media/tires/.spool/` (Renders), `media/pages/.spool/` (Pages), `uploads/.spool/`
  (`upload-chunk.php`, next to where its files end up). Pieces are appended under an exclusive
  lock; the part file's real size is the truth. Spool files, claim sidecars and parked `tmp_`
  files older than 24 h are removed on the next `probe` / `chunk_init`. Stored files come out
  0644 whatever the umask (`media-lib.php`).
- **Client**: one probe per page, then per file: single request when `size ≤ chunk_size`,
  otherwise init → sequential pieces (progress bar with bytes, %, speed and ETA, "piece n of
  m") → finish. A failed piece is retried up to 3 times (1 s / 2 s / 4 s back-off, asking the
  server where it is first). Cancel aborts and deletes the spool. Every in-flight upload is
  noted in `localStorage`, so after a reload the Upload sheet (step 1) offers **Resume N unfinished uploads**: pick the same files again (name + size must
  match), the client asks `chunk_status` and continues from `received`; Discard aborts them on
  the server. Local videos are never decoded for a thumbnail or the live preview (a poster-less
  tile with the name and size instead).
- **Playback**: Apache serves `media/` statically with `Accept-Ranges: bytes`, so the viewer's
  `<video preload="metadata">` and seeking only fetch the ranges the browser needs. Grid tiles
  never load a video: they show the cached poster or a play glyph, and the poster probe is
  skipped entirely for files over 256 MB (`data-video-bytes`). For instant playback of big MP4s
  export them with the `moov` atom at the front ("fast start" / "web optimized").
- **Bigger single requests (optional)**: there is deliberately no `.user.ini` in the repo
  (deploys are file-synced; a bad value could take the host down). If you want single-request
  uploads above the host default, set these in cPanel → *MultiPHP INI Editor* (or a hand-made
  `.user.ini` in the app folder): `upload_max_filesize = 256M`, `post_max_size = 260M`,
  `max_execution_time = 300`, `max_input_time = 300`, `memory_limit = 256M`. The probe picks
  the bigger piece size up automatically; nothing else needs changing.

## Clients and logos

- **Studio → Clients** (admin, `studio.php?tab=clients`, also the "Clients" link on the Studio
  chooser) lists every company and is the one place that creates or edits one: name, slug
  (auto from the name, `[a-z0-9-]{2,40}`, unique — the review link is `?client=<slug>`),
  feature label (the Tires tab's name), logo upload / replace / remove, and the Tires /
  Emails / Pages module toggles. Everything posts to `client-admin.php` (admin + same-site
  only). Clients are never deleted from the portal.
- **Logo upload**: an image by content (PNG / JPG / GIF / WebP, ≤ 2 MB), resized to fit
  512×512 and written to `uploads/logo_<slug>.png` (JPEG stays `.jpg`); `companies.logo_url`
  is set to that app-relative path. Renaming a slug renames the file with it and moves the
  client's `media/pages/<slug>/` folder (uploaded Pages) along; if that move fails the save
  still goes through and the message says which folder to move by hand.
- **Logo resolution** (`brandLogoUrl()` in `helpers.php`): the stored `logo_url` when its
  file exists (an `uploads/...` value is re-rooted under the current folder, so a row that
  still says `/socialmedia/uploads/x.png` keeps working after the rename) → the bundled
  `static/brand/<slug>.png` when there is one → the initials avatar. So a client created
  with slug `cometic` or `hmf` shows its mark before any upload. Details and how to replace a
  mark: `static/brand/README.md`.
- **Joust mark**: `static/brand/joust.png` is the favicon / touch icon (`appIconTags()`), the
  sign-in card, the admin chooser header and the avatar of the Joust actor in comment threads
  and the activity feed; clients keep their own logo / initials as the other actor.
- If a client's logo went missing after the folder rename, check that
  `portal/uploads/<file>` exists on the server (uploads are never deployed), or simply upload
  it again in Studio → Clients.

## Drive storage view

An admin-only page (`drive.php`) that shows how full the agency Google Drive is, which client folder
holds what, what has gone stale, and which files could be offboarded. The portal never talks to
Google: a Google Apps Script in the Drive owner's account (`docs/drive-collector/`, metadata-only
scope) measures the Drive every night and POSTs a snapshot to `drive-ingest.php` in parts; the page
reads only from the `drive_*` tables (`migrate.php` steps 30–34; helpers in `drive-lib.php`).

- **`config.php` keys**: `'drive_ingest_secret' => '<random, 24+ chars>'` — the bearer the script sends
  (`Authorization: Bearer …`, or `X-Drive-Secret` when the host strips Authorization); without it
  `drive-ingest.php` answers 503. Optional `'drive_clients_root_folder_id' => '<folder id>'`
  documents which folder holds the client folders (the script has its own copy in its properties).
- **Install**: follow `docs/drive-collector/README.md` (add the secret, run `migrate.php`, paste
  `Code.gs` + `appsscript.json`, set the script properties, run `verify`, `setupTrigger`, `runNightly`).
- **Health**: `curl -H "Authorization: Bearer <secret>" https://joustmedia.com/portal/drive-ingest.php?health=1`.
  The live host strips `.php` and answers that with a 301 to `…/portal/drive-ingest?health=1`; use
  the extensionless address directly (no `-L` needed then) and set the script's `PORTAL_INGEST_URL`
  to `https://joustmedia.com/portal/drive-ingest` — the script refuses redirects on purpose and
  reports the `Location` it was given.
  Without a secret the reply is a `401` JSON, with the wrong one too; `503` means `config.php` has no
  `drive_ingest_secret` yet. Apache strips `Authorization` for CGI / FastCGI PHP on many shared hosts —
  if the bearer form keeps answering 401, send `-H "X-Drive-Secret: <secret>"` instead (the collector
  always sends both). Anything unexpected answers `500 {"ok":false,"error":"server error"}` (never the
  host's blank error page) and writes the cause to the portal's `error_log`; append `&debug=1` to the
  health URL, with a valid secret, to get `detail` (class + message) and `at` (file:line) in the reply.
- **Parts protocol** (`drive-ingest.php`, bearer only — no session, no CSRF token; JSON in, JSON out):
  `POST ?part=begin` (quota from `Drive.About`) → `?part=files` (≤ 2,000 rows per request, every owned
  non-trashed file that uses quota) → `?part=folders` (≤ 5,000) → `?part=clients` → `?part=tree` (≤ 4 MB)
  → `?part=quickwins` → `?part=finish` (the server scores candidates, finds duplicates / old versions,
  computes the burn rate from history and answers `alerts_due`) → `?part=alerted`. `?part=state` parks
  a long run's folder map so it can resume past Apps Script's 6-minute limit. Every part is idempotent
  (body SHA-256, `X-Drive-Part-Hash`); errors are 400 (validation, with the row index) / 401 / 404 /
  405 / 409 (state) / 413 / 503 (not configured or tables missing).
- **Request sizes**: the script posts files 1,000 per request (~300 KB), folders 2,000 per request and
  the tree as one JSON of at most 4 MB; the endpoint caps bodies at 8 MB (413). Keep the host's
  `post_max_size` at 8M or above (the recommended cPanel values above are far higher).
- **What the numbers mean**: the capacity bar is Google's own quota (`Drive files` = usage in Drive
  *minus the trash*, `Trash`, `Gmail & Photos`, `Free`; they sum to the limit). The client tiles plus
  "(unfiled)" sum to that same "Drive files" figure — it is the total of every owned, non-trashed file
  the collector listed. Offboard candidates are files ≥ 100 MB nobody has modified or opened in 180 days,
  ranked by size × idle days (capped at 730). Nothing on the page writes to Drive.
- **Before trusting the UI**: after the first run open https://one.google.com/storage in the Drive
  owner's account and compare its "Drive" / "Trash" / total figures with the capacity strip. A gap over
  1 % between the client tiles and Google's Drive figure shows as a footnote on the page (`quota_note`);
  the usual cause is files owned by someone else in a client folder (they count against *their* quota).
- **Retention**: snapshot rows (the usage history) are kept forever; folder / file / quick-win detail
  for the newest 7 complete snapshots; per snapshot the offboard candidates + the 1,000 largest files
  + the 50 largest per client. Threshold emails (80 / 90 / 95 % used, under 14 days to full) are sent by
  the script once per crossing (`drive_alerts`). Each completed snapshot adds one activity-feed row
  ("Drive snapshot — 71% used, 12 candidates", admin feed only) that never triggers the daily digest.

## Not deployed

`config.php` (live DB credentials), `uploads/`, `.htaccess` files, `error_log`, this README,
`redirect-old-folder/`, `media-hardening/`, `docs/` (the Drive collector source) and `tests/` (the local
test harness) are excluded from
both workflows and must be managed on the server. `media/` lives outside the app folder, so deploys
never touch it.
