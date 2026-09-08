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

## Not deployed

`config.php` (live DB credentials), `uploads/`, `.htaccess` files, `error_log`, this README
and `redirect-old-folder/` are excluded from both workflows and must be managed on the server.
