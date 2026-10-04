/* Client sign-in + clean links in the browser:
   - a bare ?client= link lands on the branded sign-in page (Joust mark × client logo, friendly line)
   - magic link end to end: email → "Check your email" → the captured email's link → Continue → the client's Home,
     the signed-in line, Sign out → "You're signed out"; the session refuses another client
   - Manage → Tools → Clean links: Install (checked live by the server) → clean URLs in the tab bar, the post sheet
     pushes /portal/<client>/posts/<id> and closing it goes back to /posts, old URLs 301 to clean ones, the client
     approves from a clean URL (relative endpoints still reach the portal folder), Turn off → classic links again
   - Manage → Clients: add a contact, the Signed in list, revoke a device, View as client (+ Exit)
   Screenshots for review go to $AUTH_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/auth). */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run, url, BASE } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const MAIL = process.env.MAIL_DIR || path.join(ROOT, 'mail');
const SHOTS = process.env.AUTH_SHOTS_DIR || path.join(ROOT, 'shots', 'auth');
fs.mkdirSync(SHOTS, { recursive: true });
const ORIGIN = BASE.replace(/\/portal$/, '');

function sql(query, params) {
  const php = `$p=new PDO('mysql:host=localhost;dbname='.getenv('PORTAL_TEST_DB').';charset=utf8mb4',getenv('PORTAL_TEST_DB_USER'),getenv('PORTAL_TEST_DB_PASS'));`
    + `$s=$p->prepare($argv[1]);$s->execute(json_decode($argv[2],true));echo json_encode($s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):[]);`;
  const env = Object.assign({ PORTAL_TEST_DB: 'portal_test', PORTAL_TEST_DB_USER: 'portal_test', PORTAL_TEST_DB_PASS: 'portal_test' }, process.env);
  return JSON.parse(execFileSync('php', ['-r', php, query, JSON.stringify(params || [])], { env }).toString() || '[]');
}
function clearMail() { if (fs.existsSync(MAIL)) fs.readdirSync(MAIL).forEach((f) => fs.unlinkSync(path.join(MAIL, f))); }
function lastMail() {
  if (!fs.existsSync(MAIL)) return null;
  const files = fs.readdirSync(MAIL).filter((f) => f.endsWith('.json')).sort();
  return files.length ? JSON.parse(fs.readFileSync(path.join(MAIL, files[files.length - 1]), 'utf8')) : null;
}
const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
const setRole = (ctx, role) => ctx.addCookies([{ name: 'portal_test_role', value: role, url: ORIGIN }]);
const pathOf = (page) => new URL(page.url()).pathname + new URL(page.url()).search;

(async () => {
  // ---------------------------------------------------------------------------------------------------------
  await run('auth: sign-in', async ({ test, expect, ctx, viewport }) => {
    const desktop = viewport === 'desktop';
    await theme(ctx, desktop ? 'dark' : 'light');

    await test('a bare ?client= link lands on the branded sign-in page', async (page) => {
      await page.goto(url('?client=kenda'));
      expect(/\/portal\/sign-in\.php\?client=kenda&return=/.test(pathOf(page)), 'on sign-in: ' + pathOf(page));
      await page.waitForSelector('[data-signin-form]');
      expect(await page.locator('[data-signin-notice="signin"]').innerText().then((t) => /Please sign in to open the Kenda Tires review portal/.test(t)), 'friendly line');
      expect(await page.locator('[data-signin-client-logo]').count() === 1, 'the client logo');
      expect(await page.locator('.signin-mark').first().isVisible(), 'the Joust mark');
      expect.eq(await page.evaluate(() => document.documentElement.getAttribute('data-theme')), desktop ? 'dark' : 'light');
      await page.screenshot({ path: path.join(SHOTS, desktop ? 'sign-in-1440-dark.png' : 'sign-in-390-light.png'), fullPage: true });
    });

    await test('magic link end to end, then sign out', async (page) => {
      clearMail();
      sql('DELETE FROM auth_attempts');
      await page.goto(url('posts.php?client=kenda&post=2'));
      await page.fill('#signinEmail', 'jane@kenda.example');
      await Promise.all([page.waitForNavigation(), page.click('.signin-submit')]);
      await page.waitForSelector('[data-signin-sent]');
      if (desktop) await page.screenshot({ path: path.join(SHOTS, 'sign-in-sent-1440-dark.png') });
      const mail = lastMail();
      expect(mail && mail.to === 'jane@kenda.example', 'the email was sent');
      const link = (mail.text.match(/https?:\/\/\S+sign-in(?:\.php)?\?t=[A-Za-z0-9_-]+/) || [])[0];
      expect(link, 'a link in the email');
      await page.goto(link);
      await page.waitForSelector('[data-signin-confirm]');
      if (desktop) await page.screenshot({ path: path.join(SHOTS, 'sign-in-continue-1440-dark.png') });
      await Promise.all([page.waitForNavigation(), page.click('[data-signin-confirm] .signin-submit')]);
      expect(/posts\.php\?client=kenda&post=2/.test(pathOf(page)), 'back where the link pointed: ' + pathOf(page));
      expect.eq(await page.evaluate(() => document.body.dataset.role), 'client');
      const cookies = await ctx.cookies(BASE + '/');   // path=/portal/
      const c = cookies.find((k) => k.name === 'jsm_client');
      expect(c && c.httpOnly && c.sameSite === 'Lax', 'HttpOnly + SameSite=Lax cookie');
      expect(c.expires * 1000 - Date.now() > 29 * 86400 * 1000, '30-day cookie');
      // another client: refused with a clear line
      await page.goto(url('?client=privacybee'));
      expect(await page.locator('[data-signin-notice="other"]').innerText().then((t) => /signed in for Kenda Tires/.test(t)), 'other client refused');
      // sign out
      await page.goto(url('?client=kenda'));
      const out = desktop ? page.locator('.ui-tabbar-footer[data-client-signout] a') : page.locator('.home-signout a');
      await Promise.all([page.waitForNavigation(), out.click()]);
      await page.waitForSelector('[data-signin-notice="signed_out"]');
      await page.goto(url('?client=kenda'));
      expect(/sign-in/.test(pathOf(page)), 'signed out for real');
    });
  }, { role: 'none', viewports: ['desktop', 'phone'], reseed: 'test' });

  // ---------------------------------------------------------------------------------------------------------
  await run('auth: clean links', async ({ test, expect, ctx }) => {
    await test('Manage → Tools → Clean links → Install (checked live)', async (page) => {
      await page.goto(url('manage.php?section=tools'));
      await page.waitForSelector('[data-clean-links-install]');
      await Promise.all([page.waitForNavigation(), page.click('[data-clean-links-install]')]);
      expect.eq(new URL(page.url()).pathname, '/portal/manage/tools');
      expect(/Clean links are on/.test(await page.locator('[data-manage-flash]').innerText()), 'flash');
      expect(await page.locator('[data-clean-links-state="on"]').count() === 1, 'state on');
    });
    await test('clean URLs: tab bar, post sheet push / close, tires, old URL → 301', async (page) => {
      await page.goto(url('kenda/'));
      expect.eq(await page.evaluate(() => window.PortalUrls.clean), true);
      await Promise.all([page.waitForNavigation(), page.click('.ui-tab[data-tab="posts"]')]);
      expect.eq(new URL(page.url()).pathname, '/portal/kenda/posts');
      await page.locator('[data-post-open="4"]').first().click();   // the admin's first segment: Needs changes (post 4)
      await page.waitForFunction(() => /\/portal\/kenda\/posts\/4$/.test(location.pathname));
      await page.keyboard.press('Escape');
      await page.waitForFunction(() => location.pathname === '/portal/kenda/posts');
      await Promise.all([page.waitForNavigation(), page.click('.ui-tab[data-tab="tires"]')]);
      expect.eq(new URL(page.url()).pathname, '/portal/kenda/tires');
      await Promise.all([page.waitForNavigation(), page.click('a[href="/portal/kenda/tires/1"]')]);
      expect.eq(new URL(page.url()).pathname, '/portal/kenda/tires/1');
      await page.goto(url('posts.php?client=kenda&post=2'));
      expect.eq(new URL(page.url()).pathname, '/portal/kenda/posts/2');
      await page.waitForFunction(() => App.sheet && App.sheet.current);
    });
    await test('the client approves from a clean URL (relative endpoints still reach the portal)', async (page) => {
      await setRole(ctx, 'client:kenda');
      await page.goto(url('kenda/posts/1'));
      expect.eq(await page.evaluate(() => document.body.dataset.role), 'client');
      const approve = page.locator('[data-decide="approved"]:visible').first();
      await approve.waitFor();
      await approve.click();
      await page.waitForFunction(() => document.querySelector('.ui-toast, .toast'));
      for (let i = 0; i < 30 && sql('SELECT status FROM posts WHERE id = 1')[0].status !== 'approved'; i++) await page.waitForTimeout(100);
      expect.eq(sql('SELECT status FROM posts WHERE id = 1')[0].status, 'approved');
      await page.goto(url('privacybee/emails'));
      expect(/\/portal\/privacybee\/sign-in\?/.test(pathOf(page)), 'cross-client refused on clean URLs too: ' + pathOf(page));
      await setRole(ctx, 'admin');
    });
    await test('Turn off → classic links again', async (page) => {
      await page.goto(url('manage/tools'));
      await Promise.all([page.waitForNavigation(), page.click('[data-clean-links-remove]')]);
      await page.goto(url('?client=kenda'));
      expect.eq(await page.evaluate(() => window.PortalUrls.clean), false);
      expect(await page.locator('.ui-tab[data-tab="posts"]').getAttribute('href') === '/portal/posts.php?client=kenda', 'classic tab link');
    });
  }, { role: 'admin', viewports: ['desktop'], reseed: 'viewport' });

  // ---------------------------------------------------------------------------------------------------------
  await run('auth: Manage → Clients', async ({ test, expect, viewport }) => {
    const desktop = viewport === 'desktop';
    await test('contacts: add one, see it, validation', async (page) => {
      await page.goto(url('manage.php?client=kenda&section=clients'));
      await page.fill('[data-contact-email]', 'sarah@kenda.example');
      await page.fill('[data-contact-add] input[name="contact_name"]', 'Sarah Lee');
      await Promise.all([page.waitForNavigation(), page.click('[data-contact-add] button[type="submit"]')]);
      await page.waitForSelector('[data-contact] >> text=Sarah Lee');
      await page.fill('[data-contact-email]', 'jane@kenda.example');
      await page.click('[data-contact-add] button[type="submit"]');
      await page.waitForFunction(() => /already on this client/.test((document.querySelector('[data-contact-add] [data-client-status]') || {}).textContent || ''));
    });
    await test('signed-in devices: list, revoke one; screenshots', async (page) => {
      const ua = [['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 1, 'magic'],
                  ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', 2, 'link']];
      ua.forEach(([u, contact, via], i) => sql(`INSERT INTO client_sessions (contact_id, company_id, token_hash, via, ip, user_agent, created_at, last_seen_at, expires_at)
        VALUES (?, 1, SHA2(?, 256), ?, '203.0.113.7', ?, NOW() - INTERVAL ? HOUR, NOW() - INTERVAL ? MINUTE, NOW() + INTERVAL 29 DAY)`, [contact, 'shot-' + viewport + i, via, u, 20 + i * 30, 5 + i * 50]));
      await page.goto(url('manage.php?client=kenda&section=clients'));
      await page.waitForSelector('[data-client-sessions] [data-session]');
      const n = await page.locator('[data-client-sessions] [data-session]').count();
      expect(n >= 2, 'two devices listed');
      expect(/iPhone · Safari/.test(await page.locator('[data-client-sessions]').innerText()), 'device label');
      if (desktop) {
        await page.locator('#contacts').scrollIntoViewIfNeeded();
        await page.locator('#contacts').screenshot({ path: path.join(SHOTS, 'manage-clients-contacts-1440.png') });
        await page.locator('#sessions').screenshot({ path: path.join(SHOTS, 'manage-clients-sessions-1440.png') });
      } else {
        await page.locator('#contacts').screenshot({ path: path.join(SHOTS, 'manage-clients-contacts-390.png') });
        await page.locator('#sessions').screenshot({ path: path.join(SHOTS, 'manage-clients-sessions-390.png') });
      }
      await Promise.all([page.waitForNavigation(), page.locator('[data-session-revoke]').first().click()]);
      expect.eq(await page.locator('[data-client-sessions] [data-session]').count(), n - 1);
    });
    await test('View as client → the client view with a banner → Exit', async (page) => {
      await page.goto(url('manage.php?client=kenda&section=clients'));
      await Promise.all([page.waitForNavigation(), page.click('[data-view-as-start]')]);
      await page.waitForSelector('[data-view-as="kenda"]');
      expect.eq(await page.evaluate(() => document.body.dataset.role), 'client');
      expect.eq(await page.locator('[data-new-menu]').count(), 0);
      if (desktop) await page.screenshot({ path: path.join(SHOTS, 'view-as-client-1440.png') });
      await Promise.all([page.waitForNavigation(), page.click('[data-view-as-exit]')]);
      expect(/manage/.test(page.url()), 'back in Manage');
      await page.goto(url('?client=kenda'));
      expect.eq(await page.evaluate(() => document.body.dataset.role), 'admin');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'viewport' });
})();
