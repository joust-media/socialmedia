# Hardening kit for the `media/` folder

`media/` sits next to `portal/` at the site docroot and holds files that never go through
the app's deploy: the brand libraries (`media/library/<client>/`), the tire render series
(`media/tires/<tire-slug>/<series>/`) and the uploaded landing pages
(`media/pages/<client>/<page-slug>/`). Files land there by FTP or through the portal's upload
endpoints, so the web server must treat the whole tree as **static files only** — nothing in
it may ever execute, and folders must not list their contents.

`htaccess.txt` is the exact text the portal writes by itself (`media-lib.php`
`mediaHtaccessText()`, one text for both modules): `tire-series-lib.php` puts it in
`media/tires/.htaccess` on the first upload / folder rescan, `pages-lib.php` in
`media/pages/.htaccess` on the first page upload. Both re-create the file whenever it is
missing and **rewrite it when it carries an older marker of the portal's** (first line
`# joust-portal-media vN` with N below the current version, or the legacy
`# Written by the portal (…)` header = v1). A file without the portal's marker is never
touched, so a server-managed `.htaccess` stays as it is.

The portal **no longer writes `media/.htaccess`** at the parent level (an older version did
when none existed): that file governed `media/library/` too, and one refused directive there
took the whole tree down. A `media/.htaccess` that carries the portal's marker is deleted by
the next upload / rescan / Repair; one without the marker is left alone. If you want the same
protection for `media/library/`, place `htaccess.txt` there by hand (steps below).

This folder is **not** deployed by the workflows (it is in both `exclude:` lists, like
`redirect-old-folder/`); nothing here needs placing by hand unless you want the protection
before the first upload.

## Why every directive is guarded (the 500 of September 2026)

A static `index.html` under `media/pages/` answered cPanel's branded **500 Internal Server
Error**. A static file cannot fail in PHP, so the two candidates are the folder's `.htaccess`
and file permissions:

- **`.htaccess`**: an earlier text carried `XBitHack off` (an Options-class directive: needs
  `AllowOverride Options`), `RemoveOutputFilter` and `RemoveHandler` outside any module guard,
  and the PCRE-only `(?i)` in `<FilesMatch>`. On cPanel / EasyApache with PHP-FPM or LSAPI any
  directive the host refuses makes Apache answer 500 for *every* file under that folder — and
  the parent `media/.htaccess` spread it to the libraries. The text is now `Options -Indexes`
  first and alone (cPanel hosting allows `Options` in `.htaccess`) and everything else inside
  `<IfModule>` guards: `php_flag engine off` only under `mod_php*.c`, `RemoveHandler` /
  `RemoveType` under `mod_mime.c`, `RemoveOutputFilter` under `mod_include.c` (no `XBitHack`),
  the `<FilesMatch>` deny under `mod_authz_core.c` with the 2.2 `Order` / `Deny` fallback under
  `!mod_authz_core.c`, and a harmless `X-Content-Type-Options: nosniff` under `mod_headers.c`.
- **Permissions**: PHP's upload tmp files and the chunk spool are `0600`, and `mkdir()` honours
  the umask. When PHP (the cPanel user) and Apache (`nobody`) are different users, a stored
  file Apache cannot read is a 403 or — behind cPanel's error pages — a 500. Every store now
  `chmod`s files to `0644` and created folders to `0755` (umask-independent), and the Repair
  action walks the tree to fix what is already there.

## Repair (one click, admin only)

Studio → **Pages** → *Repair server rules* (`page-upload.php` `action=repair_media`, scoped to
`media/pages/<client>/`) and Studio → **Renders** → *Repair server rules* (`tire-upload.php`
`action=repair_media`, all of `media/tires/`). Each call:

1. writes the module's `.htaccess` when missing, rewrites it when it is an older version of
   ours, leaves a foreign one alone;
2. deletes `media/.htaccess` when it carries the portal's marker;
3. walks the folder and sets files to `0644` and folders to `0755` (symlinks and `.spool/`
   skipped; capped at 5 000 entries per call — the reply says when to run it again);

and replies `{ok, summary, rules, parent, perms}`. The page sheet on `pages.php` shows admins a
**Server check** line under the preview (`index.html readable (0644) · folder 0755 · rules v3`,
or the exact problem with a *Repair* button); the Studio → Pages list carries the same check as
a glyph per row. The check is filesystem-only (`is_file`, permission bits, our marker) — the
portal never makes an HTTP request to itself.

## If it still answers 500

cPanel → **Metrics → Errors** (or `~/logs/` / `error_log` in the docroot) shows Apache's exact
reason as one line per request, e.g.

- `... .htaccess: Options not allowed here` → the host forbids `Options` in `.htaccess`: remove
  the `Options -Indexes` line from the two files (and from `mediaHtaccessText()` so the next
  upload does not put it back — it is the first line by design).
- `... .htaccess: Invalid command 'XBitHack'` / `'php_flag'` / `'RemoveOutputFilter'` → an old
  file is still in place: click Repair, or delete the file and re-upload once.
- `(13)Permission denied: ... access to /media/pages/... denied` → permissions: click Repair;
  if it persists the folder chain above `media/` (the docroot itself) is not `0755`.
- `ModSecurity: Output filter: Response body too large` → a mod_security response-body limit,
  not the portal: ask hosting to raise `SecResponseBodyLimit` or set
  `SecResponseBodyAccess Off` for the site (large HTML pages trip the 512 KB default when
  response inspection is on).

## Placing it by hand (cPanel > File Manager, "Show hidden files" on)

1. Open `public_html/media/` (create it next to `public_html/portal/` if it does not exist yet;
   `media/tires/` and `media/pages/` are created by the first upload — for FTP drops create
   them yourself).
2. Upload `htaccess.txt` into `media/tires/` and `media/pages/` and rename it to `.htaccess`
   (the portal would write it there anyway). Optionally do the same in `media/library/` — the
   portal does not manage that folder.
3. Test in a private window: a library image such as
   `https://joustmedia.com/media/library/<client>/<file>.jpg` still loads, an uploaded page
   under `https://joustmedia.com/media/pages/<client>/<slug>/index.html` renders, and
   `https://joustmedia.com/media/tires/` / `https://joustmedia.com/media/pages/` answer 403
   instead of a directory listing.

## What the file does

- `# joust-portal-media v3` — the marker the portal looks for before it ever rewrites or
  deletes a file. Keep it if you edit the file by hand and want the portal to keep managing
  it; remove it to make the file yours (the portal will then never touch it).
- `Options -Indexes` — no directory listings.
- `php_flag engine off` inside `<IfModule mod_php*.c>` — the PHP engine is switched off for
  the folder when PHP runs as an Apache module; under FPM / CGI / LSAPI the guard keeps the
  file valid (the directive would otherwise be an error).
- `RemoveHandler` / `RemoveType` inside `<IfModule mod_mime.c>` — no handler or type mapping
  for script extensions, whatever PHP SAPI the host uses.
- `RemoveOutputFilter` inside `<IfModule mod_include.c>` — no server-side-include filter on
  `.shtml .shtm .stm` and never on `.html / .htm`.
- `<FilesMatch …>` `Require all denied` — anything named like a script (`.php`, `.phtml`,
  `.phar`, `.cgi`, `.pl`, `.py`, `.shtml`, `.inc`, `.htaccess` …) is refused outright, so even
  a renamed upload that slipped past the content checks cannot be fetched, let alone run.
  (`tire-upload.php` and `page-upload.php` only accept files whose bytes match the format their
  extension claims, and refuse those names anywhere in the dotted chain.)
- `Header set X-Content-Type-Options nosniff` — browsers keep the declared type.
- `<IfModule mod_expires.c>` `ExpiresByType … "access plus 7 days"` and `<IfModule mod_headers.c>`
  `<FilesMatch images / video>` `Header set Cache-Control "public, max-age=604800"` (v3) — images and
  video are cached for a week; HTML is never matched.
- The same text is written to the app's `uploads/` (v3). Every `.thumbs/` folder (image previews)
  gets its own small text with the same marker: no listing, PHP off, `.json / .lock / .tmp`
  refused, and a 1-year `public, max-age=31536000, immutable` cache rule (`mediaThumbsHtaccessText()`).
