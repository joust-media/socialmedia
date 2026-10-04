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
| 50 | Client emails start **off**. If no client email has ever been sent, every client's switches are turned off (once). |
| 51 | Gentle reminders (per client) and per-person settings (My notifications) |

- [ ] For staging, open `…/portal-staging/migrate.php` too.

## 2. config.php keys

Fill these in on the server. Manage → Notifications → **Setup** ticks each key that is set; it never shows the values.

| Key | Value | Needed for |
|---|---|---|
| `portal_url` | `https://joustmedia.com/portal` (staging: `…/portal-staging`) | every link in Slack and email |
| `environment` | `'production'`; staging: **`'staging'`** | keeps staging off production's client replies (see below) |
| `client_link_secret` | 32+ random characters | signed links in client emails, reply tokens, unsubscribe links |
| `notify_cron_token` | 32+ random characters | the cron (step 4) |
| `notify_to` | `lance@joustmedia.com` | Morning summary, reminder emails, weekly report |
| `notify_from`, `notify_from_name` | blank (= `lance@joustmedia.com`, `Joust Media`) | sender of every email |
| `mail_transport` | **blank** `''` (change it if it says `'mail'`) | automatic: Gmail once Google is connected, else PHP mail() |
| `slack_bot_token`, `slack_signing_secret` | from the Slack app (step 3). **Leave blank until then** | Slack |
| `google_client_id`, `google_client_secret` | from Google Cloud (step 5). **Leave blank until then** | Gmail sending and replies |
| `google_token_key` | 32+ random characters | encrypts the stored Google token |
| `inbound_address` | blank (= `lance+ai@joustmedia.com` in production, `lance+ai-staging@joustmedia.com` on staging) | where client replies go |
| `google_mailbox_aliases` | `[]` (normally) — send-as aliases of lance@ that may answer by email | your own email replies (step 5) |
| `staging_allowed_email_domains` | staging only; default `['joustmedia.com']` | which contacts staging may email (below) |

**Never put placeholder values in the Slack or Google keys.** Leave them blank (`''`) or out until steps 3 and 5:

- The portal treats any non-empty `slack_bot_token` as "Slack is set up". A placeholder makes every client comment and every cron reminder call Slack with a bad token; each fails with `invalid_auth` and fills the Delivery log with failures.
- A non-empty `google_client_id` shows a **Connect Google** button that cannot work.

### The staging environment setting

Staging and production read client replies from the same Gmail mailbox. Each must read its **own** address, or staging would import (and label) production's replies.

- [ ] In **staging's** `portal-staging/config.php`, set `'environment' => 'staging'`. Leave `inbound_address` blank: staging then uses `lance+ai-staging@joustmedia.com` by itself.
- [ ] In **production's** config.php, set `'environment' => 'production'`. Blank also means production, unless `portal_url` or the portal's folder name contains "staging".
- [ ] Check Manage → Notifications → Setup → **Environment**. It shows **Staging** or **Production** and how it was decided: set in config.php, or detected from `portal_url` or the folder name.
- [ ] If staging's `inbound_address` is ever set to production's `lance+ai@joustmedia.com`, staging **refuses to read replies**. Manage → Notifications → Setup then shows a red warning, and the **Environment** line says which address is in use.

### Staging safety

- [ ] **Redeploy staging first**, from the code that will be merged: GitHub → Actions → **Deploy to staging**, with `ref` = the branch or PR head, `server_dir` = `portal-staging/`, `confirm` = `staging`. Then run `…/portal-staging/migrate.php`.
- [ ] **Never give staging production's `slack_bot_token`.** Otherwise staging posts into the real `#portal-…` client channels and DMs Lance. Leave it blank on staging, or use a separate test Slack app with its own channels.
- [ ] **Don't add a staging cron** unless staging has its own Slack and Gmail setup. Without a cron, staging sends nothing that is queued.
- [ ] **Test contacts only on staging.** If staging is connected to Google as lance@, its client emails come from lance@. Staging's own database must hold test contacts, never real clients.
- Safety net: on staging, client emails go **only** to contacts whose email domain is in `staging_allowed_email_domains` (default `joustmedia.com`; sub-domains count). Every other copy is held back and logged in the Delivery log as **"blocked on staging"**, and Manage → Notifications → Setup shows the rule and how many were blocked. Production ignores this key.
- Staging contacts at @joustmedia.com are Joust addresses, so an **email reply** from one lands in Unmatched as "Failed sender check" (see step 5). Test replies on staging through the portal.

To make a random string, run `php -r 'echo bin2hex(random_bytes(24));'`, or use any password generator with 32+ characters.

## 3. Slack (full guide: `docs/slack-setup.md`)

`slack_bot_token` and `slack_signing_secret` stay blank until this step.

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
- [ ] Put the Client ID, Client secret and a new `google_token_key` into config.php (step 2); they stay blank until now. Make sure `mail_transport` is blank.
- [ ] In Manage → Notifications → **Email**, click **Connect Google**, choose lance@joustmedia.com, keep **both** permissions ticked, and click **Continue**. It should say **Connected as lance@joustmedia.com** and **Sending with: Gmail API**.
- [ ] Check plus addressing: an email to **lance+ai@joustmedia.com** from a personal account should arrive in your inbox. Workspace allows this by default.
- [ ] Turn on **DKIM** in Google Admin:
  1. Go to admin.google.com → **Apps** → **Google Workspace** → **Gmail** → **Authenticate email**.
  2. Choose **joustmedia.com** and click **Generate new record**: key length **2048**, prefix selector **`google`**. (If GoDaddy refuses a 2048-bit value, generate a 1024-bit one.)
  3. Copy the TXT record value it shows (it starts with `v=DKIM1; k=rsa; p=…`).
  4. At GoDaddy, go to joustmedia.com → **DNS** → **Add New Record**. Set **Type** to TXT, **Name** to `google._domainkey`, **Value** to that string, TTL 1 hour.
  5. Wait for DNS (usually under an hour, up to 48), then go back to Admin → **Authenticate email** → **Start authentication**. The status should read **Authenticating email with DKIM**.
- [ ] Add **SPF**. There must be one TXT record on `@`, for example `v=spf1 include:_spf.google.com include:secureserver.net ~all`. If an SPF record already exists, merge into it; don't add a second one.
- [ ] Add **DMARC. This is required**, and must be published **before** you test your own replies (below). At GoDaddy add a TXT record with **Name** `_dmarc` and **Value** exactly:

  ```
  v=DMARC1; p=none; rua=mailto:lance@joustmedia.com
  ```

  With a DMARC record, Google always writes a real `dmarc=` verdict, so a forged `From: lance@joustmedia.com` shows `dmarc=fail`. Check it with `dig +short TXT _dmarc.joustmedia.com` or any online DMARC checker.
  After two to four weeks of clean aggregate reports (they arrive at lance@) with nothing legitimate failing, tighten it to `v=DMARC1; p=quarantine; pct=100; rua=mailto:lance@joustmedia.com` (later, optionally, `p=reject`).
- [ ] Click **Send test email** on the Email card. In Gmail → ⋮ → **Show original** you should see **SPF: PASS**, **DKIM: PASS** and **DMARC: PASS**.

Until this step is done, sign-in links and your own reminders go out with PHP mail(). **Client emails are held** (kept in the queue) until Google is connected, and replies show **Not connected**. A held client email older than **72 hours** is dropped when Google is connected, so a weeks-old "Joust replied" never goes out late. It shows in the Delivery log as **expired, not sent**. To change the limit, set `client_email_max_age_hours` in config.php. Sign-in emails expire with their 15-minute link, as before.

Every inbound reply is checked before anything is posted:

- **A client's reply** is judged on Google's own verdict: the topmost `Authentication-Results` header stamped by mx.google.com. It must show no DMARC failure, a DMARC pass for exactly the From domain (when the domain has DMARC), and a **DKIM pass signed by the From domain** (or its parent domain). Nothing inside a parenthesised comment in that header is trusted, and a header that is malformed or ambiguous is rejected.
- **An email from a Joust address** (anything @joustmedia.com) would post as Joust, so it is accepted **only** as your own reply from the connected mailbox: the From is lance@ (or an alias listed in `google_mailbox_aliases`), the message carries no `Authentication-Results` (Gmail delivers its own mail internally), and Gmail labels it **SENT** in the connected mailbox, which an outside sender cannot set. A Joust address is **never** accepted on DKIM / DMARC alone.

A reply that fails, for example a forged `From: lance@joustmedia.com`, is never posted. It waits under **Unmatched email replies** marked **Failed sender check**, and **Assign** posts it as an unnamed client message, never as Joust.

Your own replies:

- **Reply from the Gmail account that is connected (lance@joustmedia.com)**, not from an alias or another account. That is the only way an email reply posts as Joust.
- **A teammate's email reply from their own mailbox** waits in Unmatched (it is a Joust address from outside the connected mailbox). Teammates answer in the portal or in Slack.
- [ ] After connecting (and after DMARC is published), reply to one portal email from lance@ and check that it posts (not **Failed sender check**). Real Workspace behaviour is untested here; if it does not post, use the portal or Slack and report it.
- [ ] **Spoof test.** From an outside account (a personal SMTP server, or a mail-tester / spoof-test service that lets you set the From), send an email with `From: lance@joustmedia.com` to `lance+ai@joustmedia.com`, using the subject of a portal email (keep its `[J#…]` tag). It must land in **Unmatched email replies** as **Failed sender check … claims to be Joust**, and Show original should say **DMARC: FAIL**. Nothing is posted and no client email is queued. Then **Dismiss** it.

## 6. Client contacts and client emails

**Client emails are OFF by default.** Adding contacts (so clients can sign in) does not email anyone. Manage → Clients shows a banner, *"Client emails are off — turn on per client when ready"*, plus *"Connect Google first"* until step 5 is done.

To turn them on, **after connecting Google (step 5)**:

- [ ] In Manage → Clients, for each client, add the people who review under **Contacts**. They sign in with a one-time emailed link; no passwords. Sign-in emails always go out, even before Google.
- [ ] Check that Manage → Notifications → **Email** says **Sending with: Gmail API**. Until then, client emails stay held in the queue.
- [ ] Open a client's card in Manage → Clients → **Client emails** and click **Turn on** for each kind it should get:

| Email | When it is sent |
|---|---|
| **Ready for your review** | One email listing everything sent for review, 15 minutes after the last item. This includes posts, emails, pages, tire renders (one row per series) and library images, with thumbnails. |
| **Joust replied** | Your visible replies, batched over 10 minutes. Internal notes never go out. |
| **Live & scheduled** | Once a day, at the Morning summary hour. |
| **Gentle reminders** | For items still To Review with no answer after **N days** (the **Remind after** field on the same card, default 3; 0 = never). At most **one email per client every N days**, listing every such item; each item is mentioned at most **twice**; and once anyone at the client has done anything in the portal since the last reminder (a comment, a decision, a visit), nothing sent before that is reminded again. |

- [ ] Repeat per client, when each one is ready.
- [ ] Look at each template in Manage → Notifications → **Client emails** → **Preview** / **Text**.
- [ ] Each contact can turn kinds off, or unsubscribe, from the link in every email, or from the portal (tab bar → **Email settings**).
- [ ] Only if you must email clients before Google is connected: tick **Allow sending client emails without Google (mail())** on the Client emails card. These emails are not DKIM-signed (more spam folders), so connecting Google is better.

## 7. Clean links

- [ ] In Manage → **Tools** → **Clean links**, click **Install**. The portal writes its `.htaccess` rules, checks them live, and rolls back by itself if the server does not support them. Links then look like `/portal/kenda/posts/12`, and old links redirect.

## 8. Team

- [ ] **Check the Team list now** (Manage → Notifications → **Team**). Today only Lance exists, so nothing changes. Make sure it shows **only Lance**, with email **exactly** `lance@joustmedia.com` (the same as `notify_to`). Otherwise Lance gets a second, scoped "Morning summary (teammate)" and a second weekly report.
- [ ] Add anyone else at Joust (name, email, Slack ID). Their portal comments and Slack replies carry their name.
- [ ] In **Slack channel per client**, pick who gets @mentioned for each client. That person is the client's **owner**: their reminders go to them, and their Inbox **Mine** filter shows that client.
- **Adding a teammate starts no emails.** A new teammate's **Morning summary** and **Weekly report** switches start **off**; their **Slack DM** and **Escalation email** switches start on (those only fire for clients they own).
- There is one admin login, so teammates can't change their own switches. **Lance sets them on each teammate's row under Team** (Morning summary, Weekly report, Slack DM, Escalation email), then clicks **Save**. Lance's own switches are in **My notifications** (link in the Inbox and on Manage → Notifications); his are all on unless he turns them off.
- With the summary on, a teammate who owns clients gets only those clients ("Your clients: …"); one who owns none gets every client. The `notify_to` address (Lance) always gets every client.
- These switches never change who owns a client. **Inactive** is separate: it removes the person entirely (no emails, not a client owner, not an @mention or DM target).
- A client with **no Slack channel** is listed in a warning on Manage → Notifications. Its comments and decisions are emailed to the owner instead, at most one email per item every 15 minutes.
- **Internal notes:** in any comment box, tick **Internal (Joust only)**. The box turns amber, the client never sees the note or gets an email about it, and Slack shows it in the item's thread marked internal.

## 9. The Inbox and reports

The **Joust Inbox** is Home → **Joust Inbox**. The Home tab's red badge counts everything waiting on Joust across all clients. It has three tabs:

| Tab | What it lists |
|---|---|
| **Waiting on Joust** | The client spoke last, or the item is in Needs changes. Oldest first, orange after 4 hours, red after 24. |
| **Waiting on client** | Items sent for review with no answer yet. |
| **Resolved** | Items answered in the last 14 days. |

- To clear an item without replying, use **Resolve** in the Inbox, **Mark resolved** in an item's ⋯ menu, or **Resolve** in Slack.
- Blue dots mark threads with messages you have not opened yet. Clients see the same dots for your replies, and a client's tab badge counts those unread replies on top of their To Review items (for example "3 to review, 1 new reply"). A reply on an item that is still To Review is not counted twice: the client Home's **"Joust replied"** card shows it instead.
- Every **Monday**, at the Morning summary hour, you get the **weekly report**: median and slowest first reply, items approved, items waiting over 24 hours, and per-client numbers. Preview it in Manage → Notifications → Client emails → **Weekly owner report**.
- [ ] Change the reminder times and the Morning summary hour in Manage → Notifications → **Reminders** if you want.
- [ ] **Quiet hours** (same card) hold back reminder nudges, DMs and emails during a window, for example 10 PM to 7 AM. The default is **None**: reminders run around the clock. When the window ends, each waiting item gets **one** combined reminder (the email if it is due, else the DM, else the thread nudge) instead of every step at once. The catch-up skips the Slack thread re-ping, so the channel's parent message is not bumped; the Delivery log shows the others as "combined into #…".
- The Inbox has **All clients / Mine** at the top. **Mine** shows only the clients you own.

## 10. Final end-to-end check (10 minutes)

- [ ] Add your personal address as a contact of Hollow Mill Farm, turn on its **Ready for your review** and **Joust replied** emails (Manage → Clients → Hollow Mill Farm → Client emails), create a post there, and click **Send for review**.
- [ ] About 15 minutes later you get **"Ready for your review"**. **Review** opens the post, already signed in.
- [ ] Reply to that email. Within 5 minutes the comment is on the post, Slack pings you in `#portal-hmf`, and the Inbox lists it under **Waiting on Joust** with a blue dot.
- [ ] Answer it in the portal, or in the Slack thread. About 10 minutes later **"Joust replied"** arrives in the same email thread, and the item moves to **Resolved**. Signed in as the contact, the Home shows a **"Joust replied"** card. After the contact approves or asks for changes, the Posts badge's aria-label says **"1 new reply"** until the post is opened.
- [ ] Remove the test contact afterwards: Manage → Clients → Hollow Mill Farm → **Contacts** → **Remove**.

If something does not arrive, start at Manage → Notifications:

- **Delivery log** shows every Slack message and email with the error.
- The **Email** card shows Google's last error.
- **Unmatched email replies** lists replies that could not be placed on an item, including any that **Failed sender check**.
- Client emails not arriving at all? Check the **Client emails** card for *held* (Google not connected) and the client's switches in Manage → Clients (they start off).

## Rollback

Do these in order:

1. **Remove the cron job first** (cPanel → Cron Jobs → delete the `notify-cron` line), so no queued work runs against reverted code.
2. **Disconnect Google**: Manage → Notifications → Email → **Disconnect**, or revoke it at myaccount.google.com → **Security** → **Third-party apps with account access** → Joust Portal → **Remove access**. This stops inbound polling and outbound Gmail.
3. Revert the code (revert the merge on `main`; the deploy runs again). The migrations are additive; to restore data, use your database backup.

To silence everything without a deploy: remove the cron job, turn every client's email kinds off, clear `slack_bot_token`, and disconnect Google.
