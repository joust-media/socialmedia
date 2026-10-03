/* Notification fixes in the browser (tests/smoke/21-notif-fixes.php covers the server side):
   - unread markers clear when an item is opened by a DEEP LINK (static/js/tracking.js boot()): from the Inbox, from a
     Slack-style "Open in portal" link, and from a client's emailed (signed) link — the sheet is already open when
     tracking.js starts, which used to leave the dots forever
   - the "Internal (Joust only)" switch in the comment composer: distinct styling, the bubble is marked, the client never
     sees it
   - Joust replies are marked read from the client's Needs-changes notice (renderPostHiddenNotice) and from asset
     deep links (assets.php [data-seen-on-load]: a library image, a tire series) — the Home "Joust replied" card empties
   - screenshots for review: Manage → Clients banner, Manage → Notifications (client emails held / allow mail(), clients
     without a Slack channel, quiet hours, a "Failed sender check" reply), My notifications, the Inbox "Mine" filter, the
     client Home "Joust replied" card, the gentle reminder email
   Screenshots go to $NFIX_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/nfix). */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run, url } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const APP = process.env.APP_DIR || path.join(ROOT, 'site/portal');
const SHOTS = process.env.NFIX_SHOTS_DIR || path.join(ROOT, 'shots', 'nfix');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name) => path.join(SHOTS, name);

function sql(query, params) {
  const php = `$p=new PDO('mysql:host=localhost;dbname='.getenv('PORTAL_TEST_DB').';charset=utf8mb4',getenv('PORTAL_TEST_DB_USER'),getenv('PORTAL_TEST_DB_PASS'));`
    + `$p->exec("SET time_zone = '".(new DateTime('now', new DateTimeZone('America/New_York')))->format('P')."'");`
    + `$s=$p->prepare($argv[1]);$s->execute(json_decode($argv[2],true));echo json_encode($s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):[]);`;
  const env = Object.assign({ PORTAL_TEST_DB: 'portal_test', PORTAL_TEST_DB_USER: 'portal_test', PORTAL_TEST_DB_PASS: 'portal_test' }, process.env);
  return JSON.parse(execFileSync('php', ['-r', php, query, JSON.stringify(params || [])], { env }).toString() || '[]');
}
/** PHP inside the test app (helpers loaded) → its stdout. */
function app(code) {
  const base = process.env.PORTAL_TEST_BASE || 'http://127.0.0.1:8099/portal';
  const host = base.replace(/^https?:\/\//, '').replace(/\/.*$/, '');
  return execFileSync('php', ['-r', `$_SERVER['SCRIPT_NAME']='/portal/notify-cron.php'; $_SERVER['HTTP_HOST']=${JSON.stringify(host)}; chdir(${JSON.stringify(APP)}); require 'db.php'; require_once 'helpers.php'; ${code}`]).toString().trim();
}
const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
const clientSays = (type, id, cid, detail) => sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, client_contact_id, created_at)
  VALUES (?, ?, ?, 'commented', 'client', 'Comment', ?, 1, NOW() - INTERVAL 2 HOUR)`, [cid, type, id, detail]);
const joustSays = (type, id, cid, detail) => sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, author_user_id, summary, detail, created_at)
  VALUES (?, ?, ?, 'commented', 'admin', 1, 'Comment', ?, NOW() - INTERVAL 30 MINUTE)`, [cid, type, id, detail]);
async function seenStored(page, viewerType, type, id) {
  for (let i = 0; i < 60; i++) {
    if (sql(`SELECT 1 FROM thread_seen WHERE viewer_type = ? AND entity_type = ? AND entity_id = ?`, [viewerType, type, id]).length) return true;
    await page.waitForTimeout(100);
  }
  return false;
}

(async () => {
  // ---------------------------------------------------------------------------------------------------------------------
  await run('nfix: unread clears on deep links (admin)', async ({ test, expect, viewport }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    await test('from the Inbox: the row opens the item → it is marked seen, the dot is gone everywhere', async (page) => {
      clientSays('post', 1, 1, 'Can the tread face the camera?');
      await page.goto(url('inbox.php'));
      await page.waitForSelector('[data-inbox-row="post:1"] [data-unread-for="post:1"]');
      if (w === '1440') await page.screenshot({ path: shot('deeplink-inbox-before-1440.png'), fullPage: true });
      const seenReq = page.waitForRequest((r) => /thread-action/.test(r.url()) && r.method() === 'POST', { timeout: 8000 });
      await Promise.all([page.waitForNavigation(), page.locator('[data-inbox-row="post:1"] a.ibx-link').click()]);
      await page.waitForFunction(() => window.App && App.sheet && App.sheet.current);
      const req = await seenReq;
      expect(/action=seen/.test(req.postData() || '') && /post%3A1/.test(req.postData() || ''), 'seen sent: ' + req.postData());
      expect(await seenStored(page, 'admin', 'post', 1), 'thread_seen stored');
      await page.waitForFunction(() => !document.querySelector('[data-unread-for="post:1"]'));
      await page.goto(url('inbox.php'));
      await page.waitForSelector('[data-inbox-row="post:1"]');
      expect.eq(await page.locator('[data-inbox-row="post:1"] [data-unread-for]').count(), 0, 'no dot in the Inbox');
      await page.screenshot({ path: shot(`deeplink-inbox-after-${w}.png`), fullPage: true });
    });
    await test('from Slack ("Open in portal" = the item’s absolute deep link)', async (page) => {
      clientSays('post', 2, 1, '[Slide 2] Lettering is blurry');
      const link = app(`echo notifyItemInfo($pdo, 'post', 2)['url'];`);
      expect(/post=2/.test(link), 'the Slack link: ' + link);
      await page.goto(link);
      await page.waitForFunction(() => window.App && App.sheet && App.sheet.current);
      expect(await seenStored(page, 'admin', 'post', 2), 'thread_seen stored');
      await page.waitForFunction(() => !document.querySelector('[data-unread-for="post:2"]'));
      await page.goto(url('posts.php?client=kenda&status=pending'));
      expect.eq(await page.locator('[data-unread-for="post:2"]').count(), 0, 'no dot in the list');
    });
    await test('an email item opened by its deep link (emails.php?email=…)', async (page) => {
      clientSays('email', 2, 2, 'Subject line feels long');
      await page.goto(url('emails.php?client=privacybee&email=2'));
      await page.waitForFunction(() => window.App && App.sheet && App.sheet.current);
      expect(await seenStored(page, 'admin', 'email', 2), 'thread_seen stored');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('nfix: unread clears on deep links (client, from an email)', async ({ test, expect, viewport }) => {
    await test('the contact taps the signed link in a "Joust replied" email → signed in, the sheet opens, the reply is marked read', async (page) => {
      joustSays('post', 1, 1, 'Swapped — the tread faces the camera now.');
      const link = app(`echo clientLink('kenda', 'posts/1', 'jane@kenda.example');`);
      expect(/k=1\./.test(link), 'a signed link: ' + link);
      await page.goto(link);
      await page.waitForFunction(() => window.App && App.sheet && App.sheet.current);
      expect(await seenStored(page, 'contact', 'post', 1), 'thread_seen stored for the contact');
      await page.waitForFunction(() => !document.querySelector('[data-unread-for="post:1"]'));
      if (viewport === 'phone') await page.screenshot({ path: shot('deeplink-client-email-390.png'), fullPage: false });
      await page.goto(url('index.php?client=kenda'));
      expect.eq(await page.locator('[data-home-replied]').count(), 0, 'the Home "Joust replied" card is empty again');
    });
  }, { role: 'none', viewports: ['desktop', 'phone'], reseed: 'test' });

  // ---------------------------------------------------------------------------------------------------------------------
  await run('nfix: internal note from the composer', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    await theme(ctx, 'light');
    await test('the switch turns the composer amber; the note is marked internal; the client never sees it', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=1'));
      await page.waitForFunction(() => window.App && App.sheet && App.sheet.current);
      const box = page.locator('.ui-sheet-root[aria-hidden="false"] [data-comment-internal]');
      await box.waitFor();
      await box.check();
      const form = page.locator('.ui-sheet-root[aria-hidden="false"] [data-comment-form]');
      expect(await form.evaluate((f) => f.classList.contains('is-internal')), 'amber composer');
      expect(/Internal note/.test(await form.locator('[data-comment-input]').getAttribute('placeholder')), 'placeholder says so');
      await form.locator('[data-comment-input]').fill('Client is slow to pay — keep this one short');
      await form.screenshot({ path: shot(`composer-internal-${w}.png`) });
      await form.locator('[data-comment-input]').press('Enter');
      await page.waitForSelector('.ui-sheet-root[aria-hidden="false"] .pd-msg[data-internal="1"] [data-internal-pill]');
      const row = sql(`SELECT internal, actor FROM activity_log WHERE detail = 'Client is slow to pay — keep this one short'`);
      expect.eq(row.length, 1); expect.eq(Number(row[0].internal), 1); expect.eq(row[0].actor, 'admin');
      expect.eq(sql(`SELECT COUNT(*) AS n FROM client_email_queue`)[0].n, 0, 'no client email');
      await page.locator('.ui-sheet-root[aria-hidden="false"] [data-thread]').screenshot({ path: shot(`thread-internal-note-${w}.png`) });
      // reload: still there for Joust, styled as internal
      await page.goto(url('posts.php?client=kenda&post=1'));
      await page.waitForSelector('.ui-sheet-root[aria-hidden="false"] .ui-bubble--internal');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('nfix: internal note hidden from the client', async ({ test, expect }) => {
    await test('the client’s sheet has no internal switch and no internal note', async (page) => {
      sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, author_user_id, internal, summary, detail) VALUES (1, 'post', 1, 'commented', 'admin', 1, 1, 'Internal note', 'TOP SECRET margin note')`);
      await page.goto(url('posts.php?client=kenda&post=1'));
      await page.waitForFunction(() => window.App && App.sheet && App.sheet.current);
      await page.waitForSelector('.ui-sheet-root[aria-hidden="false"] [data-comment-form]');
      expect.eq(await page.locator('[data-comment-internal]').count(), 0, 'no switch');
      expect(!/TOP SECRET/.test(await page.content()), 'no note');
    });
  }, { role: 'client:kenda', viewports: ['desktop'], reseed: 'test' });

  // ---------------------------------------------------------------------------------------------------------------------
  await run('nfix: Manage + Inbox + My notifications', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    await theme(ctx, 'light');
    await test('Manage → Clients: client emails off + Connect Google first', async (page) => {
      sql(`UPDATE notify_clients SET email_review = 0, email_replies = 0, email_live = 0`);
      await page.goto(url('manage.php?section=clients'));
      await page.waitForSelector('[data-client-emails-banner] [data-banner-off]');
      expect(await page.locator('[data-banner-google]').count() === 1, 'Connect Google first');
      await page.screenshot({ path: shot(`manage-clients-banner-${w}.png`), fullPage: false });
      await page.goto(url('manage.php?section=clients&client=kenda'));
      await page.waitForSelector('[data-client-emails]');
      await page.locator('[data-client-emails]').screenshot({ path: shot(`manage-client-emails-off-${w}.png`) });
      expect.eq(await page.locator('[data-client-email-kind="remind"]').count(), 1, 'the reminder switch');
    });
    await test('Manage → Notifications: held client emails, allow mail(), no-channel warning, quiet hours, a failed sender check', async (page) => {
      sql(`INSERT INTO client_email_queue (company_id, kind, entity_type, entity_id) VALUES (1, 'review', 'post', 1)`);
      sql(`INSERT INTO email_inbound (gmail_id, from_email, from_name, subject, body_text, received_at, status, reason, company_id, entity_type, entity_id)
           VALUES ('forged1', 'lance@joustmedia.com', 'Lance', 'Re: Spring launch hero [J#abc123]', 'Approved — invoice waived.', NOW() - INTERVAL 7 MINUTE, 'unmatched',
                   'Failed sender check: dmarc=fail, dkim=none, spf=fail — claims to be Joust (lance@joustmedia.com) but not verified as lance@joustmedia.com', 1, 'post', 1)`);
      sql(`INSERT INTO google_account (id, account_email, refresh_token_enc, connected_at, last_success_at, last_poll_at) VALUES (1, 'lance@joustmedia.com', 's1:x', NOW(), NOW(), NOW())`);
      await page.goto(url('manage.php?section=notifications'));
      await page.waitForSelector('[data-no-channel-warning]');
      expect(/Hollow Mill Farm/.test(await page.locator('[data-no-channel-warning]').innerText()), 'lists Hollow Mill Farm');
      expect.eq(await page.locator('[data-unmatched-auth="fail"]').count(), 1, 'Failed sender check');
      await page.locator('#client-emails').screenshot({ path: shot(`notifications-client-emails-${w}.png`) });
      await page.locator('#unmatched').screenshot({ path: shot(`notifications-unmatched-failed-sender-${w}.png`) });
      await page.locator('[data-notify-settings]').screenshot({ path: shot(`notifications-quiet-hours-${w}.png`) });
      await page.locator('[data-no-channel-warning]').screenshot({ path: shot(`notifications-no-channel-${w}.png`) });
      // the allow-mail() switch saves by itself
      await Promise.all([page.waitForEvent('load'), page.locator('[data-client-mail-allow]').check()]);
      for (let i = 0; i < 30 && sql(`SELECT v FROM meta WHERE k = 'client_emails_allow_mail'`)[0].v !== '1'; i++) await page.waitForTimeout(100);
      expect.eq(sql(`SELECT v FROM meta WHERE k = 'client_emails_allow_mail'`)[0].v, '1', 'saved');
      // quiet hours save
      await page.waitForSelector('[data-client-mail-allow]:checked');
      await page.selectOption('#nfQs', '22');
      await page.selectOption('#nfQe', '7');
      await Promise.all([page.waitForEvent('load'), page.locator('[data-notify-form="settings"] [type="submit"]').click()]);
      await page.waitForSelector('[data-quiet-hours="22-7"]');
    });
    await test('My notifications: turn the Slack DM off', async (page) => {
      await page.goto(url('my-notifications.php'));
      await page.waitForSelector('[data-my-prefs]');
      await page.screenshot({ path: shot(`my-notifications-${w}.png`), fullPage: true });
      await page.locator('#myPref-dm').uncheck();
      await Promise.all([page.waitForEvent('load'), page.locator('[data-notify-form="my_prefs"] [type="submit"]').click()]);
      await page.waitForSelector('#myPref-dm:not(:checked)');
      expect.eq(JSON.parse(sql(`SELECT notify_prefs FROM admin_users WHERE id = 1`)[0].notify_prefs).dm, 0);
    });
    await test('Inbox: All clients / Mine', async (page) => {
      sql(`INSERT INTO admin_users (id, name, email, role) VALUES (2, 'Sam', 'sam@joustmedia.com', 'admin')`);
      sql(`UPDATE notify_clients SET owner_user_id = 2 WHERE company_id = 2`);
      clientSays('post', 1, 1, 'Kenda question');
      clientSays('email', 2, 2, 'Privacy Bee question');
      await page.goto(url('inbox.php'));
      await page.waitForSelector('[data-inbox-row="email:2"]');
      await Promise.all([page.waitForNavigation(), page.locator('[data-inbox-filter="mine"]').click()]);
      await page.waitForSelector('[data-inbox-who="mine"]');
      expect.eq(await page.locator('[data-inbox-row="email:2"]').count(), 0, 'Sam’s client is not mine');
      expect.eq(await page.locator('[data-inbox-row="post:1"]').count(), 1);
      await page.screenshot({ path: shot(`inbox-mine-${w}.png`), fullPage: true });
    });
    await test('the gentle reminder email (preview)', async (page) => {
      await page.goto(url('email-preview.php?type=remind&client=kenda'));
      await page.waitForSelector('[data-preview-type="remind"]');
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'no horizontal scroll');
      await page.screenshot({ path: shot(`email-reminder-${w}.png`), fullPage: true });
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('nfix: client Home "Joust replied"', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    await theme(ctx, viewport === 'desktop' ? 'light' : 'dark');
    await test('the card lists unread Joust replies; opening one clears it', async (page) => {
      joustSays('post', 1, 1, 'New render is up — the tread faces the camera now.');
      joustSays('post', 2, 1, 'Fixed the lettering on slide 2.');
      await page.goto(url('index.php?client=kenda'));
      await page.waitForSelector('[data-home-replied="2"]');
      await page.screenshot({ path: shot(`client-home-joust-replied-${w}.png`), fullPage: true });
      await Promise.all([page.waitForNavigation(), page.locator('[data-replied-row="post:1"]').click()]);
      await page.waitForFunction(() => window.App && App.sheet && App.sheet.current);
      expect(await seenStored(page, 'contact', 'post', 1), 'seen');
      await page.goto(url('index.php?client=kenda'));
      await page.waitForSelector('[data-home-replied="1"]');
      expect.eq(await page.locator('[data-replied-row="post:1"]').count(), 0, 'gone once read');
    });
  }, { role: 'client:kenda', viewports: ['desktop', 'phone'], reseed: 'test' });
  // ---------------------------------------------------------------------------------------------------------------------
  await run('nfix: replies read from the Needs-changes notice and asset deep links (client)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    await theme(ctx, 'light');
    await test('post 4 (Needs changes): Joust replies, the client opens the "Joust is updating this post" sheet → read, the Home card empties', async (page) => {
      joustSays('post', 4, 1, 'Darker render is coming tomorrow.');
      await page.goto(url('index.php?client=kenda'));
      await page.waitForSelector('[data-replied-row="post:4"]');
      await page.goto(url('posts.php?client=kenda&post=4'));
      await page.waitForSelector('.ui-sheet-root[aria-hidden="false"] [data-hidden-post][data-seen-entity="post:4"]');
      expect(await seenStored(page, 'contact', 'post', 4), 'thread_seen stored for the contact');
      await page.screenshot({ path: shot(`client-needs-changes-read-${w}.png`), fullPage: false });
      await page.goto(url('index.php?client=kenda'));
      expect.eq(await page.locator('[data-home-replied]').count(), 0, 'no "Joust replied" card left');
    });
    await test('a library image and a tire series: the Home card links there and opening them marks the reply read', async (page) => {
      joustSays('library_image', 7, 1, 'Cropped tighter as asked.');
      joustSays('tire_series', 1, 1, 'Series 1 has two new angles.');
      await page.goto(url('index.php?client=kenda'));
      await page.waitForSelector('[data-home-replied="2"]');
      await Promise.all([page.waitForNavigation(), page.locator('[data-replied-row="library_image:7"]').click()]);
      expect(await seenStored(page, 'contact', 'library_image', 7), 'library image seen');
      await page.goto(url('index.php?client=kenda'));
      await page.waitForSelector('[data-home-replied="1"]');
      await Promise.all([page.waitForNavigation(), page.locator('[data-replied-row="tire_series:1"]').click()]);
      expect(await seenStored(page, 'contact', 'tire_series', 1), 'series seen');
      await page.goto(url('index.php?client=kenda'));
      expect.eq(await page.locator('[data-home-replied]').count(), 0, 'card empty');
    });
  }, { role: 'client:kenda', viewports: ['desktop', 'phone'], reseed: 'test' });
})();
