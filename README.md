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

**URLs**

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
     series…" → drop files. One file at a time (`tire-upload.php`, admin, same-site; 10 MB
     images, videos up to 4 GB — large files go in pieces, see *Large uploads* below; sequential
     queue with progress, Retry and Cancel), stored under the series folder as
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
  (row + file + thumb). Series renders show in the Approved Pool grouped per collection with
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
  with videos. In the Approved Pool, **Photos / Videos** chips filter client-side and videos start
  collapsed behind **Show N videos** so no posters are fetched for them by default.
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

## Large uploads (chunked, resumable)

Shared hosting caps one request at `upload_max_filesize` / `post_max_size` (often 64 MB or
less) and at `max_execution_time`, so multi-GB files cannot arrive in one POST. Every upload
surface therefore speaks a small chunk protocol (`chunk-upload-lib.php` on the server,
`static/js/chunk-upload.js` in the browser); a file at or below one piece still goes in a single
request exactly as before. The surfaces and their endpoints:

| Surface | Endpoint | Caps |
| --- | --- | --- |
| Studio → Compose (one-offs) | `upload-chunk.php` `purpose=post` → `claimed[]` tokens to `add-post.php` | videos 4 GB, images 50 MB, 10 media per post |
| Studio → Uploads tab, Batch (direct files) | `upload-chunk.php` `purpose=batch` → `claimed[]` tokens to `batch-process.php` | videos 4 GB, images 50 MB, 50 files per batch |
| Tire reference images (`add-feature.php`, "Add more images") | `upload-chunk.php` `purpose=feature` (`feature_id`) | images 50 MB, 6 per item, images only |
| Replace image / video (Posts detail, Assets viewer, `add-feature.php`) | `upload-chunk.php` `purpose=replace` (`replace_kind=post\|tire`, `replace_id`) — `replace-image.php` stays the single-request path | videos 4 GB, images 50 MB |
| Studio → Renders | `tire-upload.php` | images 10 MB, videos 4 GB |
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
  noted in `localStorage`, so after a reload the Renders tab, the composer, the Uploads tab and
  Batch offer **Resume N unfinished uploads**: pick the same files again (name + size must
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
`redirect-old-folder/`, `media-hardening/` and `docs/` (the Drive collector source) are excluded from
both workflows and must be managed on the server. `media/` lives outside the app folder, so deploys
never touch it.
