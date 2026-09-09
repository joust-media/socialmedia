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

## Not deployed

`config.php` (live DB credentials), `uploads/`, `.htaccess` files, `error_log`, this README
and `redirect-old-folder/` are excluded from both workflows and must be managed on the server.
