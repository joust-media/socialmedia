# Owner checklist: notifications, client sign-in, email, Inbox

This is everything to switch on after the notification work (Phases 1–4), in one place, in order. Each step says where to click. The longer guides are:

- `docs/slack-setup.md` for Slack
- `docs/google-setup.md` for Google and email

Tick a step once it is done. Nothing here needs a code change. The only deploy is the one you already do.

Two terms used below:

- **config.php** means `portal/config.php` on the server (cPanel → File Manager, or FTP). It is not in git, and deploys never overwrite it. Copy any missing key from `config.example.php`.
- **Manage** means the portal's **Manage** tab, signed in as Lance.

---

## 0. Before anything: rotate the database password

`config.php` used to be in git with the real database password, so treat that password as known.

- [ ] In cPanel, open **MySQL® Databases**.
- [ ] Scroll to **Current Users**. Find the portal's database user (the `username` value in config.php) and click **Change Password**.
- [ ] Generate a strong password, copy it, and click **Change Password**.
- [ ] Right away, open **config.php** and set `'password' => '…new password…'`, then save.
- [ ] Open https://joustmedia.com/portal/. It should load. If it says *Database connection failed*, the two passwords differ: paste the new one into config.php again.
- [ ] Do the same for **staging** if it uses its own database user (`portal-staging/config.php`).
- [ ] Store the new password in your password manager.

## 1. Update the database

- [ ] Sign in, then open **https://joustmedia.com/portal/migrate.php**. Every step should show ✓ or "skipped", and none should be red. Running it again is safe. The steps are:

| Steps | What they add |
|---|---|
| 36–39 | Notifications: the team, the outbox, Slack threads |
| 40–44 | Client sign-in: contacts, magic links, sessions |
| 45–49 | Google email, inbound replies, client emails, the Inbox and unread markers |

- [ ] For staging, open `…/portal-staging/migrate.php` too.

## 2. config.php keys

Fill these in on the server. Manage → Notifications → **Setup** ticks each key that is set; it never shows the values.

| Key | Value | Needed for |
|---|---|---|
| `portal_url` | `https://joustmedia.com/portal` (staging: `…/portal-staging`) | every link in Slack and email |
| `client_link_secret` | 32+ random characters | signed links in client emails, reply tokens, unsubscribe links |
| `notify_cron_token` | 32+ random characters | the cron (step 4) |
| `notify_to` | `lance@joustmedia.com` | Morning summary, reminder emails, weekly report |
| `notify_from`, `notify_from_name` | blank (= `lance@joustmedia.com`, `Joust Media`) | sender of every email |
| `mail_transport` | **blank** `''` (change it if it says `'mail'`) | automatic: Gmail once Google is connected, else PHP mail() |
| `slack_bot_token`, `slack_signing_secret` | from the Slack app (step 3) | Slack |
| `google_client_id`, `google_client_secret` | from Google Cloud (step 5) | Gmail sending and replies |
| `google_token_key` | 32+ random characters | encrypts the stored Google token |
| `inbound_address` | blank (= `lance+ai@joustmedia.com`); staging: `lance+ai-staging@joustmedia.com` | where client replies go |

To make a random string, run `php -r 'echo bin2hex(random_bytes(24));'`, or use any password generator with 32+ characters.

## 3. Slack (full guide: `docs/slack-setup.md`)

- [ ] api.slack.com/apps → **Create New App** → **From an app manifest** → paste `docs/slack-app-manifest.yml` → **Create** → **Install to Workspace**.
- [ ] Copy the **Bot User OAuth Token** (`xoxb-…`) to `slack_bot_token`, and the **Signing Secret** to `slack_signing_secret`.
- [ ] Create the channels `#portal-kenda`, `#portal-privacybee`, `#portal-cometic` and `#portal-hmf`. In each one, type `/invite @Joust Portal`.
- [ ] In Manage → Notifications → **Team**, click **Find in Slack by email** on your row.
- [ ] In Manage → Notifications → **Slack channel per client**, click **Find #portal-…** for each client, then **Send test** on one of them.
- [ ] In Manage → Notifications → **Setup**, click **Send test to Slack**. You should get a DM.

## 4. Cron (every 5 minutes)

- [ ] In cPanel → **Cron Jobs**, click **Add New Cron Job**. Choose *Once Per Five Minutes* and use this command:

```
curl -fsS -H "X-Notify-Token: YOUR_NOTIFY_CRON_TOKEN" "https://joustmedia.com/portal/notify-cron" >/dev/null 2>&1
```

Use the URL **without** `.php`. This one job runs everything time-based:

- retries
- Slack reminders
- the Morning summary
- client email batches
- reading client replies from Gmail
- the Monday report

- [ ] **Delete** the old digest cron (`…/digest.php?source=cron`).
- [ ] Within 5 minutes, Manage → Notifications → Setup should show **Cron: Ran just now**.

## 5. Google: email from lance@ and replies (full guide: `docs/google-setup.md`)

- [ ] In Google Cloud console, signed in as lance@joustmedia.com:
  1. Create the project **Joust Portal**.
  2. Enable the **Gmail API**.
  3. Set the consent screen to **Internal**, with the scopes `gmail.send` and `gmail.modify`.
  4. Create a client: **OAuth client ID** → **Web application**.
  5. Add the redirect URIs `https://joustmedia.com/portal/google-oauth` and `https://joustmedia.com/portal-staging/google-oauth`.
- [ ] Put the Client ID, Client secret and a new `google_token_key` into config.php (step 2). Make sure `mail_transport` is blank.
- [ ] In Manage → Notifications → **Email**, click **Connect Google**, choose lance@joustmedia.com, keep **both** permissions ticked, and click **Continue**. It should say **Connected as lance@joustmedia.com** and **Sending with: Gmail API**.
- [ ] Check plus addressing: an email to **lance+ai@joustmedia.com** from a personal account should arrive in your inbox. Workspace allows this by default.
- [ ] Turn on **DKIM**:
  1. Go to admin.google.com → **Apps** → **Google Workspace** → **Gmail** → **Authenticate email**.
  2. Choose joustmedia.com and click **Generate new record** (2048-bit, selector `google`).
  3. At GoDaddy, go to joustmedia.com → **DNS** → **Add New Record**. Set **Type** to TXT, **Name** to `google._domainkey`, and **Value** to the long `v=DKIM1; …` string.
  4. Wait up to a few hours, then go back to Admin → **Start authentication**.
- [ ] Add **SPF**. There must be one TXT record on `@`, for example `v=spf1 include:_spf.google.com include:secureserver.net ~all`. If an SPF record already exists, merge into it; don't add a second one.
- [ ] Optional: add **DMARC**, a TXT record with **Name** `_dmarc` and **Value** `v=DMARC1; p=none; rua=mailto:lance@joustmedia.com`.
- [ ] Click **Send test email** on the Email card. In Gmail → ⋮ → **Show original** you should see **SPF: PASS** and **DKIM: PASS**.

Until this step is done, email keeps going out with PHP mail() as before, and replies show **Not connected**.

## 6. Client contacts and client emails

- [ ] In Manage → Clients, for each client, add the people who review under **Contacts**. They sign in with a one-time emailed link; no passwords.
- [ ] On the same card, check the **Client emails** switches. All are **On** by default:

| Email | When it is sent |
|---|---|
| **Ready for your review** | One email listing everything sent for review, 15 minutes after the last item. |
| **Joust replied** | Your visible replies, batched over 10 minutes. Internal notes never go out. |
| **Live & scheduled** | Once a day, at the Morning summary hour. |

- [ ] Look at each template in Manage → Notifications → **Client emails** → **Preview** / **Text**.
- [ ] Each contact can turn kinds off, or unsubscribe, from the link in every email, or from the portal (tab bar → **Email settings**).
- [ ] Turn a client's switches **Off** if they should not get email yet.

## 7. Clean links

- [ ] In Manage → **Tools** → **Clean links**, click **Install**. The portal writes its `.htaccess` rules, checks them live, and rolls back by itself if the server does not support them. Links then look like `/portal/kenda/posts/12`, and old links redirect.

## 8. Team

- [ ] In Manage → Notifications → **Team**, add anyone else at Joust (name, email, Slack ID). Their portal comments and Slack replies carry their name.
- [ ] In **Slack channel per client**, pick who gets @mentioned for each client.

## 9. The Inbox and reports

The **Joust Inbox** is Home → **Joust Inbox**. The Home tab's red badge counts everything waiting on Joust across all clients. It has three tabs:

| Tab | What it lists |
|---|---|
| **Waiting on Joust** | The client spoke last, or the item is in Needs changes. Oldest first, orange after 4 hours, red after 24. |
| **Waiting on client** | Items sent for review with no answer yet. |
| **Resolved** | Items answered in the last 14 days. |

- To clear an item without replying, use **Resolve** in the Inbox, **Mark resolved** in an item's ⋯ menu, or **Resolve** in Slack.
- Blue dots mark threads with messages you have not opened yet. Clients see the same dots for your replies.
- Every **Monday**, at the Morning summary hour, you get the **weekly report**: median and slowest first reply, items approved, items waiting over 24 hours, and per-client numbers. Preview it in Manage → Notifications → Client emails → **Weekly owner report**.
- [ ] Change the reminder times and the Morning summary hour in Manage → Notifications → **Reminders** if you want.

## 10. Final end-to-end check (10 minutes)

- [ ] Add your personal address as a contact of Hollow Mill Farm, create a post there, and click **Send for review**.
- [ ] About 15 minutes later you get **"Ready for your review"**. **Review** opens the post, already signed in.
- [ ] Reply to that email. Within 5 minutes the comment is on the post, Slack pings you in `#portal-hmf`, and the Inbox lists it under **Waiting on Joust** with a blue dot.
- [ ] Answer it in the portal, or in the Slack thread. About 10 minutes later **"Joust replied"** arrives in the same email thread, and the item moves to **Resolved**.
- [ ] Remove the test contact afterwards: Manage → Clients → Hollow Mill Farm → **Contacts** → **Remove**.

If something does not arrive, start at Manage → Notifications:

- **Delivery log** shows every Slack message and email with the error.
- The **Email** card shows Google's last error.
- **Unmatched email replies** lists replies that could not be placed on an item.
