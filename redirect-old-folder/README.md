# Redirect kit for the old `/socialmedia/` folder

The portal now lives at `https://joustmedia.com/portal/`. Old links, bookmarks and the
review links already sent to clients still point at `/socialmedia/...`. These two files
turn the old folder into a permanent redirect to the new one, keeping the path and the
query string (`?client=kenda&...`).

This folder is **not** deployed by the workflows (it is in both `exclude:` lists); you
place the files by hand, once.

## Steps (cPanel > File Manager, "Show hidden files" on)

1. Rename the live app folder `public_html/socialmedia` to `public_html/portal`.
   Everything inside (config.php, uploads/, .htaccess) moves with it -- nothing needs
   editing inside the app. If the app's own `.htaccess` contains a `RewriteBase
   /socialmedia/` line, change it to `RewriteBase /portal/`.
2. Create a new, empty `public_html/socialmedia` folder.
3. Upload `.htaccess` and `index.php` from this folder into it (just those two files).
4. Test in a private window:
   - `https://joustmedia.com/socialmedia` -> `https://joustmedia.com/portal/`
   - `https://joustmedia.com/socialmedia/?client=kenda` -> `https://joustmedia.com/portal/?client=kenda`
   - `https://joustmedia.com/socialmedia/posts.php?client=kenda&status=denied`
     -> `https://joustmedia.com/portal/posts.php?client=kenda&status=denied`
5. Update the digest cron URL (cPanel > Cron Jobs) to
   `https://joustmedia.com/portal/digest.php?source=cron`.
6. Merge/deploy the branch that changes `server-dir` to `portal/` so future deploys go to
   the renamed folder. Deploy **after** the rename, never before: `config.php` and
   `uploads/` are excluded from deploys, so deploying into a fresh `portal/` first would
   create a broken install.

## How it works

- `.htaccess`: `RedirectMatch 301 ^/socialmedia(/(.*))?$ /portal/$2` (mod_alias). Matches
  `/socialmedia`, `/socialmedia/` and any deeper path; the query string is passed through
  automatically. This handles every URL, including `.php` scripts and static files.
- `index.php`: the same redirect in PHP, used only if the host ignores `.htaccess`. It can
  only catch requests that land on the folder index (`/socialmedia/` and
  `/socialmedia/index.php`), because other scripts no longer exist in the old folder.

If you ever want to drop the old folder entirely, delete `public_html/socialmedia`; nothing
else references it.
