# Hardening kit for the `media/` folder

`media/` sits next to `portal/` at the site docroot and holds files that never go through
the app's deploy: the brand libraries (`media/library/<client>/`) and the tire render series
(`media/tires/<tire-slug>/<series>/`). Files land there by FTP or through the portal's upload
endpoint, so the web server must treat the whole tree as **static files only** — nothing in
it may ever execute, and folders must not list their contents.

`htaccess.txt` is the exact text the portal writes by itself: `tire-series-lib.php`
(`ensureTireMediaHtaccess()`) creates `media/tires/.htaccess` and — when the parent has none —
`media/.htaccess` on the first upload or folder rescan, and re-creates them whenever they are
missing. It never overwrites a file that is already there, so a server-managed `.htaccess`
stays as it is.

This folder is **not** deployed by the workflows (it is in both `exclude:` lists, like
`redirect-old-folder/`); nothing here needs placing by hand unless you want the protection
before the first upload.

## Placing it by hand (cPanel > File Manager, "Show hidden files" on)

1. Open `public_html/media/` (create it next to `public_html/portal/` if it does not exist yet;
   `media/tires/` is created by the first upload — for FTP drops create it yourself).
2. Upload `htaccess.txt` into `media/` and rename it to `.htaccess`. Do the same in
   `media/tires/` (the portal would write it there anyway).
3. Test in a private window: a library image such as
   `https://joustmedia.com/media/library/<client>/<file>.jpg` still loads, and
   `https://joustmedia.com/media/` / `https://joustmedia.com/media/tires/` answer 403 instead of
   a directory listing.

## What the file does

- `Options -Indexes` — no directory listings (cPanel hosting allows `Options` in `.htaccess`;
  if the host ever returns 500 for every file under `media/`, delete the two `.htaccess` files —
  the portal only re-creates them on the next upload / rescan, so remove them from
  `ensureTireMediaHtaccess()` too before that happens).
- `php_flag engine off` inside `<IfModule mod_php*.c>` — the PHP engine is switched off for
  the folder when PHP runs as an Apache module; the `IfModule` guard keeps the file valid
  under FPM / CGI / LSAPI, where the directive would otherwise be an error.
- `RemoveHandler` / `RemoveType` — no handler or type mapping for script extensions, whatever
  PHP SAPI the host uses.
- `<FilesMatch …>` `Require all denied` — anything named like a script (`.php`, `.phtml`,
  `.phar`, `.cgi`, `.pl`, `.py`, `.sh`, …) is refused outright, so even a renamed upload that
  slipped past the content checks cannot be fetched, let alone run. (`tire-upload.php` only
  accepts files whose bytes decode as the image / video format their extension claims.)
