# Email through Google Workspace (Gmail API) + client replies

What you get:

- **Sending.** Every portal email goes out through your Google Workspace mailbox as **"Joust Media" <lance@joustmedia.com>**. It is signed with DKIM by Google, so it lands in inboxes, and a copy shows in your Gmail **Sent** folder. This covers sign-in links, client emails, reminders, the Morning summary and the weekly report.
- **Client replies.** Client emails say "reply to this email". Replies go to **lance+ai@joustmedia.com**, which arrives in your own inbox. Every 5 minutes the cron reads those replies and posts each one as a comment on the right post, email or page, written by that contact, and Slack pings you as for any client comment. Each processed reply gets the Gmail label **portal-processed**.
- **The fallback.** Until you connect Google, nothing changes: email goes out with the server's PHP `mail()` as before, and Manage → Notifications shows replies as **Not connected**.

It takes about 20 minutes plus DNS time. Do the steps in order. Only step 5 touches the server.

> **Staging:** use the same Google Cloud client for staging. Register both redirect URIs in step 4. The staging `config.php` gets the same three `google_*` values **plus `'inbound_address' => 'lance+ai-staging@joustmedia.com'`**, and you press **Connect Google** on staging as well.
>
> The separate address matters. Without it, staging would read, and label as processed, the replies meant for the live portal, and those replies would never reach the live portal. With it, staging only reads replies to staging's own emails.
>
> If you are not testing email on staging, leave the `google_*` keys blank there; staging then keeps using `mail()`.

---

## 1. Create a Google Cloud project

1. Open **https://console.cloud.google.com** and sign in as **lance@joustmedia.com**. It must be the Workspace account, not a personal Gmail.
2. Click the project picker at the top left (next to "Google Cloud"), then **New project**.
3. Set **Project name** to `Joust Portal` and leave **Organization** as `joustmedia.com`. Click **Create**.
4. Make sure the project picker now shows **Joust Portal**.

## 2. Turn on the Gmail API

1. Open the left menu ☰ and go to **APIs & Services** → **Library**.
2. Search for **Gmail API**, open it, and click **Enable**.

## 3. Consent screen: Internal

1. Open the left menu ☰ and go to **APIs & Services** → **OAuth consent screen**. The newer console names it **Google Auth Platform**. If it asks, click **Get started**.
2. Under **App information**, set **App name** to `Joust Portal` and **User support email** to `lance@joustmedia.com`. Click **Next**.
3. Under **Audience**, choose **Internal** and click **Next**.
   - Internal means only people in the joustmedia.com Workspace can use the app.
   - Internal apps need **no verification by Google**: no review, and no "unverified app" warning.
4. Under **Contact information**, enter `lance@joustmedia.com`, then click **Next**. Tick the agreement and click **Continue**, then **Create**.
5. Open **Data access** (or **Edit app** → **Scopes**) and click **Add or remove scopes**. Paste these two lines into **Manually add scopes**, click **Add to table**, then **Update**, then **Save**:

```
https://www.googleapis.com/auth/gmail.send
https://www.googleapis.com/auth/gmail.modify
```

**Why these two, and nothing broader:**

| Scope | Why the portal needs it |
|---|---|
| `gmail.send` | Send the portal's emails as lance@joustmedia.com. |
| `gmail.modify` | Read the replies that arrive at **lance+ai@joustmedia.com**, then add the label **portal-processed**, so a reply is never imported twice and you can see in Gmail what the portal picked up. `gmail.readonly` cannot add labels, so it is not enough. `gmail.modify` **cannot permanently delete** mail. |

How the portal uses that access:

- It only searches `to:lance+ai@joustmedia.com -label:portal-processed newer_than:14d`.
- It never deletes, archives, sends from or reads other mail.
- It never imports attachments. The comment only says "(attachment not imported)".

## 4. Create the OAuth client

1. Go to **APIs & Services** → **Credentials** → **+ Create credentials** → **OAuth client ID**. In the newer console this is **Google Auth Platform** → **Clients** → **+ Create client**.
2. Set **Application type** to **Web application** and **Name** to `Joust Portal (web)`.
3. Under **Authorized redirect URIs**, click **+ Add URI** twice and enter exactly:

```
https://joustmedia.com/portal/google-oauth
https://joustmedia.com/portal-staging/google-oauth
```

   Use no `.php`, no trailing slash and `https`. The portal shows its exact URI in Manage → Notifications → Email → **Google redirect URI**; it must match character for character.

4. Click **Create**. Copy the **Client ID** (it ends in `.apps.googleusercontent.com`) and the **Client secret** (it starts with `GOCSPX-`).
   - Newer consoles show the secret **only once**, so copy it now, or click **Download JSON**.
   - Keep the secret in your password manager. Never paste it in chat, email or git.

You can skip **Authorized JavaScript origins**.

## 5. Add the keys to config.php on the server

Open `portal/config.php` on the server with cPanel → File Manager, or FTP. It is not in git, and deploys never touch it. If a key is missing, copy it from `config.example.php`.

| Key | Value |
|---|---|
| `google_client_id` | The Client ID from step 4. |
| `google_client_secret` | The Client secret from step 4. |
| `google_token_key` | 32+ random characters. They encrypt the stored Google token. Generate them with `php -r 'echo bin2hex(random_bytes(24));'`, or type any long random string. If you change this key later, press **Connect Google** again. |
| `mail_transport` | `''` (blank) means automatic: Gmail once connected, else `mail()`. **If your config.php says `'mail'`, change it to `''`**, because `'mail'` forces PHP `mail()` even after you connect. |
| `inbound_address` | Leave blank for `lance+ai@joustmedia.com`. |
| `client_link_secret` | 32+ random characters, if not set yet. They sign the links in client emails. |
| `portal_url` | `https://joustmedia.com/portal`. Staging uses `https://joustmedia.com/portal-staging`. |
| `notify_from` / `notify_from_name` | Blank means `lance@joustmedia.com` / `Joust Media`. The connected Google account **must be this address**. |

Then open **https://joustmedia.com/portal/migrate.php** once while signed in. Steps 45–49 add the tables for Google, inbound replies, client emails and the Inbox. Running it again is safe.

## 6. Connect Google

1. Go to Manage → **Notifications** → the **Email** card. It should show **Google (Gmail API): Not connected**, and the three `google_*` keys should be ticked under **Setup**.
2. Click **Connect Google**.
3. Google asks you to choose an account. Pick **lance@joustmedia.com**.
   - If you pick any other account, the portal refuses it with "That was x@…: connect lance@joustmedia.com" and revokes it straight away.
4. Google lists the two permissions: *Send email on your behalf*, and *Read, compose, and send emails… / Read, edit, label…*. Make sure **both are ticked**, then click **Continue**.
5. You land back on Manage → Notifications with **"Connected as lance@joustmedia.com"**. **Sending with** now says **Gmail API as lance@joustmedia.com**.

**Disconnect** revokes the token at Google and deletes it from the portal. Email then goes back to `mail()`. You can also remove access from your Google Account → Security → Third-party apps → Joust Portal.

## 7. Plus addressing (lance+ai@)

Google Workspace delivers `name+anything@domain` to `name@domain` by default, and there is no switch for it. To check:

1. From a personal email account, send a message to **lance+ai@joustmedia.com**.
2. It should arrive in lance@'s inbox, with `lance+ai@joustmedia.com` in the To line.

If it bounces, check **admin.google.com** → Apps → Google Workspace → Gmail → **Routing** for a rule that rejects or rewrites `+` addresses, and remove it.

Optional: you can make a Gmail filter `to:(lance+ai@joustmedia.com)` → *Apply label "Client replies"*, but keep the messages **in the inbox**. Do not tick **Skip the Inbox**. The portal searches all mail, so either works, but you will want to see them.

## 8. DKIM: Google signs your mail

1. Open **https://admin.google.com** and go to **Apps** → **Google Workspace** → **Gmail** → **Authenticate email**.
2. Select the domain **joustmedia.com**.
3. If it says *"You must update the DNS records for this domain"*, click **Generate new record**:
   - Set **key bit length** to **2048**.
   - Set **prefix selector** to `google`.
   - Click **Generate**.
4. Copy the two values shown:
   - **DNS Host name (TXT record name)**: `google._domainkey`
   - **TXT record value**: a long string that starts `v=DKIM1; k=rsa; p=…`
5. Add the record where joustmedia.com's DNS lives. For GoDaddy:
   1. Go to **My Products** → joustmedia.com → **DNS** → **Add New Record**.
   2. Set **Type** to **TXT**, **Name** to `google._domainkey` and **Value** to the long string, all on one line.
   3. Set **TTL** to 1 hour and click **Save**.
   - Some DNS hosts cap TXT values at 255 characters. GoDaddy splits long values for you.
6. Wait. It usually takes 15–60 minutes, and can take up to 48 hours. Then go back to **Authenticate email** and click **Start authentication**. The status changes to *"Authenticating email with DKIM"*.

## 9. SPF: allow Google to send for joustmedia.com

joustmedia.com may have **only one** SPF record, which is a TXT record on `@` that starts `v=spf1`.

- **No SPF record yet:** add a TXT record with Name `@` and Value `v=spf1 include:_spf.google.com ~all`.
- **One already exists** (for example `v=spf1 include:secureserver.net -all`): edit that record and add `include:_spf.google.com` before the `~all` or `-all`. For example:

  ```
  v=spf1 include:_spf.google.com include:secureserver.net ~all
  ```

  Keep the host's include while the `mail()` fallback may still be used (staging, or before you connect). Once Gmail sends everything you can drop it.

Optional but recommended once SPF and DKIM pass: add a DMARC record with TXT Name `_dmarc` and Value `v=DMARC1; p=none; rua=mailto:lance@joustmedia.com`.

## 10. Test it

1. **Send a test.** In Manage → Notifications → Email, click **Send test email**. It arrives from *Joust Media* within a minute and shows in Gmail → Sent.
   - In the received copy, open ⋮ → **Show original**. You should see **SPF: PASS** and **DKIM: PASS** with domain joustmedia.com.
2. **Do a client round trip.** Use a test client such as Hollow Mill Farm, and a personal address you can read.
   1. In Manage → Clients → Hollow Mill Farm → **Contacts**, add your personal address. Its **Client emails** switches are all **On** by default.
   2. Create a post for it and send it for review. About **15 minutes** later (the batch window; the cron runs every 5 minutes), your personal inbox gets **"Ready for your review: …"** with the thumbnail and a **Review** button.
   3. Click **Review**. You are signed in as that contact and land on the post.
   4. Reply to the email: "Can we try a darker crop?". **Within 5 minutes** the reply shows on the post as a comment by that contact, Slack pings you, and Gmail labels the reply **portal-processed**.
   5. Answer the comment in the portal. About **10 minutes** later the contact gets **"Joust replied: …"** in the same email thread.
3. **Check unsubscribe.** At the bottom of any client email, click **Email preferences** to toggle the kinds, or **Unsubscribe**. Gmail's own **Unsubscribe** button next to the sender also works; it is one-click.
4. **Check the unmatched list.** Email lance+ai@ directly, not as a reply, from an unknown address. After the next cron run it shows under Manage → Notifications → **Unmatched email replies**, with **Assign** and **Dismiss** buttons. Nothing is posted until you assign it.

You can look at every template, with sample data, from Manage → Notifications → **Client emails** → **Preview** / **Text**.

## If something is wrong

| You see | Do this |
|---|---|
| `Error 400: redirect_uri_mismatch` on Google's page | The URI in step 4 must equal Manage → Notifications → **Google redirect URI** exactly. No `.php`, `https`, and the right folder. |
| `Error 403: org_internal` | You signed in with a non-joustmedia.com account. Use lance@joustmedia.com. |
| "Both permissions are needed…" | On Google's screen, tick **both** boxes, then press **Connect Google** again. |
| "That was x@…: connect lance@joustmedia.com" | You picked another account. Press **Connect Google** again and choose lance@. |
| **Last error:** "Google access was revoked or expired" | The token was removed, or your Google password changed with "sign out everywhere". Press **Connect Google** again. Meanwhile emails retry (1, 5, 15, 60, 180 minutes); after that, **Retry all failed** in the Delivery log. |
| **Last error:** "stored token unreadable" | `google_token_key` changed. Press **Connect Google** again. |
| **Sending with: PHP mail()** after connecting | config.php has `'mail_transport' => 'mail'`. Set it to `''`. |
| Replies never arrive | Check that **Cron: Ran just now** shows under Setup, then use **Check replies now** on the Email card. Unmatched replies are listed there with the reason. |
