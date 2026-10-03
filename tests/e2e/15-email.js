/* Email through Google + client emails + the Joust Inbox, in the browser:
   - Manage → Notifications: Connect Google end to end (against tests/google-stub.php) → "Connected as …"; the
     Unmatched email replies list: Assign to an item, Dismiss; template Preview links
   - every email template (Ready for your review, Joust replied, Live & scheduled, the weekly report) at desktop and
     phone widths
   - the Joust Inbox (1440 dark, 390 light): oldest first, ages, Resolve; Waiting on client
   - unread dots: the admin opens a post → its dot goes; ⋯ → Mark resolved in the post sheet
   - Email preferences from the client portal: toggle, save
   Screenshots for review go to $EMAIL_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/email). */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run, url } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = process.env.EMAIL_SHOTS_DIR || path.join(ROOT, 'shots', 'email');
fs.mkdirSync(SHOTS, { recursive: true });

function sql(query, params) {
  const php = `$p=new PDO('mysql:host=localhost;dbname='.getenv('PORTAL_TEST_DB').';charset=utf8mb4',getenv('PORTAL_TEST_DB_USER'),getenv('PORTAL_TEST_DB_PASS'));`
    + `$p->exec("SET time_zone = '".(new DateTime('now', new DateTimeZone('America/New_York')))->format('P')."'");`
    + `$s=$p->prepare($argv[1]);$s->execute(json_decode($argv[2],true));echo json_encode($s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):[]);`;
  const env = Object.assign({ PORTAL_TEST_DB: 'portal_test', PORTAL_TEST_DB_USER: 'portal_test', PORTAL_TEST_DB_PASS: 'portal_test' }, process.env);
  return JSON.parse(execFileSync('php', ['-r', php, query, JSON.stringify(params || [])], { env }).toString() || '[]');
}
const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
const shot = (name) => path.join(SHOTS, name);

/** A realistic spread of conversations across clients (ages in minutes). */
function seedConversations() {
  const add = (cid, type, id, actor, detail, minutes, contact) => sql(
    `INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, client_contact_id, created_at)
     VALUES (?, ?, ?, 'commented', ?, 'Comment', ?, ?, NOW() - INTERVAL ? MINUTE)`, [cid, type, id, actor, detail, contact, minutes]);
  add(1, 'post', 1, 'client', 'Can we swap the tire angle on the hero? The tread should face the camera.', 26 * 60, 1);
  add(1, 'post', 2, 'client', '[Slide 2] The sidewall lettering looks blurry here.', 5 * 60 + 20, 2);
  add(2, 'email', 2, 'client', 'Subject line feels long — can we try “Your first scan is ready”?', 40, 3);
  add(2, 'page', 1, 'client', 'Hero image is cropped on mobile.', 2 * 60 + 5, 3);
  sql(`UPDATE activity_log SET created_at = NOW() - INTERVAL 30 HOUR WHERE entity_type = 'post' AND entity_id = 4`);
}

/** Unmatched email replies for the list. */
function seedUnmatched() {
  const ins = `INSERT INTO email_inbound (gmail_id, from_email, from_name, subject, body_text, received_at, status, reason, company_id, has_attachments)
               VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL ? MINUTE, 'unmatched', ?, ?, ?)`;
  sql(ins, ['u1', 'marketing@kenda.example', 'Kenda Marketing', 'Re: 2 items ready for your review — Kenda Tires',
    'Both look good to me. Can the carousel go out Thursday instead?', 18, 'a reply to an email about several items — pick the item', 1, 0]);
  sql(ins, ['u2', 'someone@gmail.com', 'Sam Someone', 'Question about pricing', 'Hi, do you also do TikTok ads? Attached our deck.', 95,
    'not a reply to a portal email (no matching Message-ID or [J#…] token)', null, 1]);
}

(async () => {
  // ---------------------------------------------------------------------------------------------------------------------
  await run('email: Manage → Notifications', async ({ test, expect, ctx, viewport }) => {
    const desktop = viewport === 'desktop';
    await theme(ctx, 'light');
    await test('Connect Google end to end (fake Google) → Connected as lance@joustmedia.com', async (page) => {
      await page.goto(url('manage.php?section=notifications'));
      await page.waitForSelector('[data-notify-google="not-connected"]');
      if (desktop) await page.locator('#google').screenshot({ path: shot('notifications-google-not-connected-1440.png') });
      await Promise.all([page.waitForURL(/section=notifications/), page.click('[data-google-connect]')]);
      await page.waitForSelector('[data-notify-google="connected"]');
      expect(/Connected as lance@joustmedia\.com/.test(await page.locator('[data-manage-flash]').innerText()), 'flash');
      expect(/Gmail API|test mail sink/.test(await page.locator('#google').innerText()), 'transport line');
      expect.eq(sql('SELECT account_email FROM google_account')[0].account_email, 'lance@joustmedia.com');
    });
    await test('Unmatched email replies: Assign to an item, Dismiss', async (page) => {
      sql(`INSERT INTO google_account (id, account_email, refresh_token_enc, connected_at, last_success_at, last_poll_at, connected_by)
           VALUES (1, 'lance@joustmedia.com', 's1:x', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 3 MINUTE, NOW() - INTERVAL 3 MINUTE, 'lance@joustmedia.com')
           ON DUPLICATE KEY UPDATE last_poll_at = VALUES(last_poll_at)`);
      seedUnmatched();
      await page.goto(url('manage.php?section=notifications'));
      await page.waitForSelector('[data-unmatched-row]');
      expect.eq(await page.locator('[data-unmatched-row]').count(), 2);
      if (desktop) {
        await page.locator('#google').screenshot({ path: shot('notifications-google-connected-1440.png') });
        await page.locator('#unmatched').screenshot({ path: shot('notifications-unmatched-1440.png') });
        await page.locator('#client-emails').screenshot({ path: shot('notifications-client-emails-1440.png') });
        await page.screenshot({ path: shot('notifications-1440.png'), fullPage: true });
      } else {
        await page.locator('#unmatched').screenshot({ path: shot('notifications-unmatched-390.png') });
      }
      const first = page.locator('[data-unmatched-row]', { hasText: 'Thursday' });
      await first.locator('[data-unmatched-item]').selectOption('post:2');
      await Promise.all([page.waitForEvent('load'), first.locator('[data-unmatched-assign]').click()]);
      await page.waitForSelector('[data-unmatched-row]');
      expect.eq(await page.locator('[data-unmatched-row]').count(), 1);
      const posted = sql(`SELECT detail FROM activity_log WHERE entity_type = 'post' AND entity_id = 2 AND action = 'commented' ORDER BY id DESC LIMIT 1`);
      expect(/Thursday/.test(posted[0].detail), 'posted on post 2');
      await Promise.all([page.waitForEvent('load'), page.locator('[data-unmatched-dismiss]').first().click()]);
      await page.waitForSelector('[data-unmatched-state="empty"]');
    });
    await test('Preview links open every template', async (page) => {
      await page.goto(url('manage.php?section=notifications'));
      for (const t of ['review', 'reply', 'live', 'weekly']) {
        expect.eq(await page.locator(`[data-email-preview="${t}"]`).count(), 1, t);
      }
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'viewport' });

  // ---------------------------------------------------------------------------------------------------------------------
  await run('email: templates', async ({ test, expect, viewport }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    for (const [t, client] of [['review', 'kenda'], ['reply', 'kenda'], ['live', 'kenda'], ['review', 'privacybee'], ['weekly', '']]) {
      await test(`${t} ${client} renders`, async (page) => {
        if (t === 'weekly') {
          seedConversations();
          // a few answers so the report has times to show
          const answer = (cid, type, id, minutesAgo) => sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, author_user_id, summary, detail, created_at)
            VALUES (?, ?, ?, 'commented', 'admin', 1, 'Comment', 'On it', NOW() - INTERVAL ? MINUTE)`, [cid, type, id, minutesAgo]);
          answer(2, 'page', 1, 80); answer(1, 'post', 2, 230); answer(2, 'email', 2, 5);
          sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, created_at) VALUES (1, 'post', 3, 'approved', 'client', 'Approved', NOW() - INTERVAL 1 DAY), (2, 'page', 2, 'approved', 'client', 'Approved', NOW() - INTERVAL 2 DAY)`);
        }
        await page.goto(url(`email-preview.php?type=${t}${client ? '&client=' + client : ''}`));
        await page.waitForSelector(`[data-preview-type="${t}"]`);
        const width = await page.evaluate(() => document.querySelector('.jm-wrap').getBoundingClientRect().width);
        if (viewport === 'desktop') expect(width === 600, 'a 600px column: ' + width); else expect(width <= 390, 'full width on a phone: ' + width);
        // nothing overflows sideways on a phone
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'no horizontal scroll');
        if (t !== 'weekly') {
          expect(await page.locator('[data-email-item]').count() >= 1, 'items');
          expect(await page.locator('[data-email-unsub]').count() === 1, 'unsubscribe link');
          const img = page.locator('img.jm-thumb').first();
          if (await img.count()) expect(await img.evaluate((i) => i.complete && i.naturalWidth > 0), 'thumbnail loads (signed JPEG)');
        }
        await page.screenshot({ path: shot(`template-${t}${client && client !== 'kenda' ? '-' + client : ''}-${w}.png`), fullPage: true });
      });
    }
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'viewport' });

  // ---------------------------------------------------------------------------------------------------------------------
  await run('email: Joust Inbox', async ({ test, expect, ctx, viewport }) => {
    const desktop = viewport === 'desktop';
    await theme(ctx, desktop ? 'dark' : 'light');
    await test('Waiting on Joust: oldest first, ages, unread dots, Resolve', async (page) => {
      seedConversations();
      await page.goto(url('index.php'));
      const badge = await page.locator('.ui-tab[data-tab="home"] [data-queue="inbox"]').innerText();
      expect(Number(badge) >= 4, 'Home badge = waiting on Joust: ' + badge);
      await Promise.all([page.waitForNavigation(), page.click('[data-home-link="inbox"]')]);
      await page.waitForSelector('[data-inbox="joust"]');
      const keys = await page.locator('[data-inbox-row]').evaluateAll((els) => els.map((e) => e.getAttribute('data-inbox-row')));
      expect.eq(keys.slice(0, 5).join(','), 'post:4,post:1,post:2,page:1,email:2', 'oldest first');
      expect(await page.locator('[data-inbox-row="post:1"] .ibx-age--late').count() === 1, '26h → red');
      expect(await page.locator('[data-inbox-row="post:2"] .ibx-age--warn').count() === 1, '5h → orange');
      expect(await page.locator('[data-inbox-row="post:1"] .ui-unread-dot').count() === 1, 'unread dot');
      expect.eq(await page.evaluate(() => document.documentElement.getAttribute('data-theme')), desktop ? 'dark' : 'light');
      await page.screenshot({ path: shot(desktop ? 'inbox-1440-dark.png' : 'inbox-390-light.png'), fullPage: true });
      await page.locator('[data-inbox-row="email:2"] [data-inbox-resolve]').click();
      await page.waitForFunction(() => !document.querySelector('[data-inbox-row="email:2"]'));
      expect.eq(sql(`SELECT COUNT(*) AS n FROM activity_log WHERE entity_type = 'email' AND entity_id = 2 AND action = 'resolved'`)[0].n, 1);
    });
    await test('Waiting on client + Resolved tabs', async (page) => {
      seedConversations();
      await page.goto(url('inbox.php?tab=client'));
      await page.waitForSelector('[data-inbox="client"]');
      expect(await page.locator('[data-inbox-row="post:8"]').count() === 1, 'Privacy Bee post waiting on the client');
      if (desktop) await page.screenshot({ path: shot('inbox-waiting-on-client-1440-dark.png'), fullPage: true });
      sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, author_user_id, summary, detail, created_at)
           VALUES (1, 'post', 1, 'commented', 'admin', 1, 'Comment', 'Swapped — the tread faces the camera now.', NOW() - INTERVAL 20 HOUR)`);
      await page.goto(url('inbox.php?tab=resolved'));
      await page.waitForSelector('[data-inbox-row="post:1"]');
      expect(/Replied by Lance after 6h/.test(await page.locator('[data-inbox-row="post:1"]').innerText()), 'who + how fast');
      if (desktop) await page.screenshot({ path: shot('inbox-resolved-1440-dark.png'), fullPage: true });
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  // ---------------------------------------------------------------------------------------------------------------------
  await run('email: unread + Mark resolved in the sheet', async ({ test, expect }) => {
    await test('opening a post clears its dot; ⋯ → Mark resolved', async (page) => {
      seedConversations();
      await page.goto(url('posts.php?client=kenda&status=pending'));
      await page.waitForSelector('[data-unread-for="post:1"]');
      await page.locator('[data-post-open="1"]').first().click();
      await page.waitForFunction(() => App.sheet && App.sheet.current);
      await page.waitForFunction(() => !document.querySelector('[data-unread-for="post:1"]'));
      for (let i = 0; i < 30 && !sql(`SELECT 1 FROM thread_seen WHERE viewer_type = 'admin' AND entity_id = 1`).length; i++) await page.waitForTimeout(100);
      expect(sql(`SELECT 1 FROM thread_seen WHERE viewer_type = 'admin' AND entity_type = 'post' AND entity_id = 1`).length === 1, 'seen stored');
      await page.keyboard.press('Escape');
      await page.goto(url('posts.php?client=kenda&status=pending'));
      expect.eq(await page.locator('[data-unread-for="post:1"]').count(), 0, 'stays read');
      // Mark resolved from the sheet's ⋯ menu (post 1 has an unanswered client question)
      await page.locator('[data-post-open="1"]').first().click();
      await page.waitForFunction(() => App.sheet && App.sheet.current);
      await page.locator('[data-post-detail="1"] [data-menu-toggle]:visible').first().click();
      await page.locator('[data-thread-resolve="post:1"]:visible').first().click();
      await page.waitForFunction(() => /Marked resolved/.test((document.querySelector('.ui-toast') || {}).textContent || ''));
      expect.eq(sql(`SELECT COUNT(*) AS n FROM activity_log WHERE entity_type = 'post' AND entity_id = 1 AND action = 'resolved'`)[0].n, 1);
    });
  }, { role: 'admin', viewports: ['desktop'], reseed: 'test' });

  // ---------------------------------------------------------------------------------------------------------------------
  await run('email: preferences', async ({ test, expect, ctx, viewport }) => {
    const desktop = viewport === 'desktop';
    await theme(ctx, desktop ? 'light' : 'dark');
    await test('the signed-in contact turns one kind off', async (page) => {
      await page.goto(url('index.php?client=kenda'));
      const href = await page.locator('[data-email-settings]').getAttribute('href');
      expect(/email-prefs/.test(href), 'the tab bar links Email settings');
      await page.goto(href);
      await page.waitForSelector('[data-ep-form]');
      await page.screenshot({ path: shot(desktop ? 'preferences-1440-light.png' : 'preferences-390-dark.png'), fullPage: true });
      await page.locator('#ep-reply').uncheck();
      await Promise.all([page.waitForNavigation(), page.click('[data-ep-form] .signin-submit')]);
      await page.waitForSelector('[data-ep-notice]');
      expect.eq(await page.locator('#ep-reply').isChecked(), false);
      expect.eq(JSON.parse(sql('SELECT notify_prefs FROM client_contacts WHERE id = 1')[0].notify_prefs).reply, 0);
      if (desktop) await page.screenshot({ path: shot('preferences-saved-1440-light.png'), fullPage: true });
    });
    await test('the emailed one-click page (unsubscribe confirmation)', async (page) => {
      const tok = execFileSync('php', ['-r', `$_SERVER['SCRIPT_NAME']='/portal/x.php'; chdir(getenv('APP_DIR') ?: '${path.join(ROOT, 'site/portal')}'); require 'db.php'; require_once 'helpers.php'; echo clientPrefsToken(clientContactById($pdo, 2));`]).toString().trim();
      await page.goto(url('email-prefs.php?u=1&t=' + encodeURIComponent(tok)));
      await page.waitForSelector('[data-ep-unsub-confirm]');
      if (desktop) await page.screenshot({ path: shot('unsubscribe-confirm-1440-light.png'), fullPage: true });
      await Promise.all([page.waitForNavigation(), page.click('[data-ep-unsub-confirm] button')]);
      await page.waitForSelector('[data-ep-state="unsubscribed"]');
      expect(sql('SELECT unsubscribed_at FROM client_contacts WHERE id = 2')[0].unsubscribed_at !== null, 'unsubscribed');
    });
  }, { role: 'client:kenda', viewports: ['desktop', 'phone'], reseed: 'test' });
})();
