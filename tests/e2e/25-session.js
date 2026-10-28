/* Admin sign-in that lasts (auth.php, migrate 56; tests/smoke/30-session.php has the HTTP details).
 * In the browser: the real login form → the PHP session files deleted (the host's clean-up) → still signed in;
 * the sign-in ended (idle, faked in the database) → the next action shows the "Sign in again" banner, which goes to
 * the login page ("You were signed out…") and straight back to the same page. Screenshots → $SESSION_SHOTS_DIR
 * (default $PORTAL_TEST_ROOT/shots/session).
 */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SESS = process.env.SESSION_DIR || path.join(ROOT, 'sessions');
const SHOTS = process.env.SESSION_SHOTS_DIR || path.join(ROOT, 'shots', 'session');
fs.mkdirSync(SHOTS, { recursive: true });

/** One SQL statement against the test database (the app's New York clock). */
function sql(q) {
  const code = `$p = new PDO('mysql:host=localhost;dbname=' . (getenv('PORTAL_TEST_DB') ?: 'portal_test') . ';charset=utf8mb4', getenv('PORTAL_TEST_DB_USER') ?: 'portal_test', getenv('PORTAL_TEST_DB_PASS') ?: 'portal_test');`
    + `$p->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");`
    + `$p->exec(${JSON.stringify(q)});`;
  execFileSync('php', ['-r', code], { stdio: 'inherit' });
}
function killSessions() { for (const f of fs.readdirSync(SESS)) if (f.startsWith('sess_')) fs.unlinkSync(path.join(SESS, f)); }

run('session', async ({ test, url, expect, ctx, viewport }) => {
  const signIn = async (page, ret) => {
    await page.goto(url('login.php' + (ret ? '?return=' + encodeURIComponent(ret) : '')));
    await page.fill('#email', 'lance@joustmedia.com');
    await page.fill('#password', 'portal-test-admin-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
  };

  await test('the host deletes the PHP session → still signed in', async (page) => {
    await ctx.clearCookies();
    sql('TRUNCATE TABLE admin_sessions');
    await signIn(page, '/portal/posts.php?client=kenda');
    expect(/\/portal\/posts\.php\?client=kenda$/.test(page.url()), 'back to the return page: ' + page.url());
    const key = (await ctx.cookies()).find((c) => c.name === 'jsm_admin_key');
    expect(key && key.httpOnly && key.sameSite === 'Lax' && key.path === '/portal/' && key.expires > Date.now() / 1000 + 6 * 86400, 'key cookie: ' + JSON.stringify(key));
    killSessions();
    await page.goto(url('manage.php?section=tools'));
    expect.eq(await page.getAttribute('body', 'data-role'), 'admin');
    await page.waitForSelector('[data-admin-sessions] [data-admin-session-current]');
    await page.locator('#sign-ins').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(SHOTS, `sign-ins-${viewport}.png`) });
  });

  await test('signed out mid-page → the banner → login → back to the same page', async (page) => {
    await ctx.clearCookies();
    sql('TRUNCATE TABLE admin_sessions');
    await signIn(page, '/portal/posts.php?client=kenda&post=2');
    await page.waitForSelector('body[data-role="admin"]');
    const here = new URL(page.url());
    sql('UPDATE admin_sessions SET last_seen_at = NOW() - INTERVAL 13 HOUR');
    // the next action (any fetch: here a comment through App.post)
    const res = await page.evaluate(() => window.App.post('status.php', { id: 2, comment: 'note written after a long lunch', client: 'kenda' }));
    expect.eq(res.status, 401, 'refused');
    const banner = page.locator('[data-signed-out]');
    await banner.waitFor({ state: 'visible' });
    expect((await banner.textContent()).indexOf('You were signed out') >= 0, 'banner text');
    expect.eq(await page.locator('[data-signed-out]').count(), 1, 'one banner');
    await page.evaluate(() => window.App.post('status.php', { id: 2, comment: 'again', client: 'kenda' }));
    expect.eq(await page.locator('[data-signed-out]').count(), 1, 'still one banner');
    const box = await banner.boundingBox();
    const vw = page.viewportSize().width;
    expect(box && box.x >= 0 && box.x + box.width <= vw && box.y >= 0, 'on screen: ' + JSON.stringify(box));
    await page.screenshot({ path: path.join(SHOTS, `banner-${viewport}.png`) });
    const href = await banner.locator('a').getAttribute('href');
    expect(href.indexOf('reason=expired') >= 0 && href.indexOf(encodeURIComponent(here.pathname + here.search)) >= 0, 'href: ' + href);
    await Promise.all([page.waitForNavigation(), banner.locator('a').click()]);
    await page.waitForSelector('[data-login-expired]');
    await page.screenshot({ path: path.join(SHOTS, `login-expired-${viewport}.png`) });
    await page.fill('#email', 'lance@joustmedia.com');
    await page.fill('#password', 'portal-test-admin-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
    expect.eq(new URL(page.url()).pathname + new URL(page.url()).search, here.pathname + here.search, 'back on the same page');
    expect.eq(await page.getAttribute('body', 'data-role'), 'admin');
  });

  await test('a page load after the sign-in ended → login with the notice, then back', async (page) => {
    await ctx.clearCookies();
    sql('TRUNCATE TABLE admin_sessions');
    await signIn(page, '/portal/manage.php?section=tools');
    sql('UPDATE admin_sessions SET created_at = NOW() - INTERVAL 8 DAY');
    await page.goto(url('posts.php?client=kenda'));
    expect(/\/portal\/login\.php\?/.test(page.url()), 'admin login, not the client sign-in: ' + page.url());
    await page.waitForSelector('[data-login-expired]');
    await page.fill('#email', 'lance@joustmedia.com');
    await page.fill('#password', 'portal-test-admin-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
    expect(/\/portal\/posts\.php\?client=kenda$/.test(page.url()), 'back: ' + page.url());
  });
}, { role: 'none', viewports: ['desktop', 'phone'] });
