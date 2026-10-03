# Notifications setup (Slack + cron + email)

What you get: every client comment, approval, change request and caption edit posts to that client's Slack channel
right away, one thread per portal item, with an @mention of you. Your own actions in the portal never post (status
changes only update the thread's first message). If a client message is still unanswered after 60 minutes you get a
reminder in the thread and a DM; after 240 minutes an email. Both times can be changed in Manage → Notifications.
Replying in a thread in Slack posts your reply in the portal as you; start a reply with `!internal` to keep it a
Joust-only note. The daily digest is now the **Morning summary**.

Do these steps once, in order. Nothing here needs a code change or a deploy.

## 1. Update the database
Open `https://joustmedia.com/portal/migrate.php` while signed in. Steps 36–39 create the notification tables and
add you (Lance) as the first team member. Running it again is safe.

## 2. Create the Slack app
1. Go to https://api.slack.com/apps → **Create New App** → **From an app manifest** → pick the Joust workspace.
2. Paste the contents of `docs/slack-app-manifest.yml` (YAML tab) → Next → **Create**.
   Slack checks the Events URL at this point. If it shows an error, finish steps 3–4 first, then open
   **Event Subscriptions** in the app settings and click **Retry**.
3. **Install App** (left menu) → **Install to Workspace** → Allow.

## 3. Copy the two secrets into config.php on the server
Open `portal/config.php` on the server (cPanel File Manager or FTP; it is not in git and deploys never touch it).
If a key is missing, copy it from `config.example.php`. Fill in:

| Key | Where to find it |
|---|---|
| `slack_bot_token` | Slack app → **OAuth & Permissions** → *Bot User OAuth Token* (starts with `xoxb-`) |
| `slack_signing_secret` | Slack app → **Basic Information** → *App Credentials* → *Signing Secret* (Show) |
| `notify_cron_token` | Make one up: 32+ random letters and digits (or run `php -r 'echo bin2hex(random_bytes(24));'`) |
| `portal_base_url` | `https://joustmedia.com/portal` |
| `notify_to` | `lance@joustmedia.com` (Morning summary + reminder emails) |
| `notify_from`, `notify_reply_to`, `notify_message_domain`, `notify_envelope` | keep your current values |

Never paste these values anywhere else (chat, email, git). Manage → Notifications → **Setup** shows a green check
for each key that is set; it never shows the values.

## 4. Create the four client channels and invite the bot
In Slack create `#portal-kenda`, `#portal-privacybee`, `#portal-cometic`, `#portal-hmf` (public or private).
In each one type `/invite @Joust Portal` (needed for private channels and for thread replies to reach the portal).

## 5. Connect the channels and yourself in the portal
Manage → **Notifications**:
1. **Team** → your row → **Find in Slack by email** (fills your Slack user ID; or paste it from your Slack profile →
   ⋯ → *Copy member ID*) → it saves.
2. **Slack channel per client** → for each client click **Find #portal-…** (or paste the channel ID from the
   channel's details → About → bottom). Click **Send test** on one row to check.
3. **Setup** → **Send test to Slack** → you get a DM from Joust Portal.
4. **Reminders**: 60 / 240 minutes and Morning summary at 8 AM are the defaults; change them if you like.

## 6. Add the cron job (every 5 minutes)
cPanel → **Cron Jobs** → Add New Cron Job → Common Settings: *Once Per Five Minutes* (`*/5 * * * *`). Command:

```
curl -fsS -H "X-Notify-Token: YOUR_NOTIFY_CRON_TOKEN" "https://joustmedia.com/portal/notify-cron" >/dev/null 2>&1
```

(The header form keeps the token out of the server's access logs. `curl -fsS "https://joustmedia.com/portal/notify-cron?token=YOUR_NOTIFY_CRON_TOKEN" >/dev/null 2>&1`
also works, and `wget -qO- --header="X-Notify-Token: YOUR_NOTIFY_CRON_TOKEN" "https://joustmedia.com/portal/notify-cron" >/dev/null 2>&1`
if curl is not available.) Use the address **without** `.php`: the server redirects the `.php` form and the cron
would stop at the redirect.

This one job retries failed messages, sends the reminders and sends the Morning summary once a day at the hour you
chose. **Delete the old digest cron** (`…/digest.php?source=cron`): once `notify_cron_token` is set that URL needs
the token (it answers 403 without it). Until the token is set, the old URL still works but at most once per 20 hours.

Within 5 minutes Manage → Notifications → Setup should show **Cron: Ran just now**.

## 7. Try it
Open a client review link in a private window (e.g. `…/portal/?client=kenda`), comment on a post. Within seconds
`#portal-kenda` shows a message for that post (title, status, thumbnail, Open in portal) with your comment in its
thread and an @mention. Reply in the thread → the reply appears on the post in the portal. Click **Resolve** when a
question needs no reply.

## If something does not arrive
Manage → Notifications → **Delivery log** lists every message with its status and the error Slack gave
(e.g. `not_in_channel` → invite the bot; `channel_not_found` → fix the channel ID). Failures retry by themselves
(1, 5, 15, 60, 180 minutes); **Retry** / **Retry all failed** sends again now.
