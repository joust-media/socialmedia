/* "+ New" menu: admin only, keyboard / outside-click behaviour, the App.newMenu.handle hook, links. */
'use strict';
const { run } = require('./lib');

(async () => {
  await run('new-menu', async ({ test, url, expect }) => {
    await test('opens with four items for a client with Emails + Pages', async (page) => {
      await page.goto(url('posts.php?client=privacybee'));
      await page.click('[data-new-menu-toggle]');
      expect(await page.isVisible('[data-new-menu-panel]'), 'panel visible');
      const actions = await page.$$eval('[data-new-menu-panel] [data-new-action]', (els) => els.map((e) => e.dataset.newAction));
      expect.eq(actions.join(','), 'post,upload,email,page');
      expect.eq(await page.getAttribute('[data-new-menu-toggle]', 'aria-expanded'), 'true');
    });
    await test('Escape and outside click close it', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      await page.click('[data-new-menu-toggle]');
      await page.keyboard.press('Escape');
      expect(!(await page.isVisible('[data-new-menu-panel]')), 'closed by Escape');
      await page.click('[data-new-menu-toggle]');
      await page.mouse.click(10, 400);
      expect(!(await page.isVisible('[data-new-menu-panel]')), 'closed by outside click');
    });
    await test('only post + upload for a client without Emails / Pages', async (page) => {
      await page.goto(url('?client=kenda'));
      const actions = await page.$$eval('[data-new-action]', (els) => els.map((e) => e.dataset.newAction));
      expect.eq(actions.join(','), 'post,upload');
    });
    await test('App.newMenu.handle("post") keeps the page (pop-up hook)', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      await page.evaluate(() => { window.__opened = null; App.newMenu.handle('post', (d) => { window.__opened = d.action + '|' + d.client; return true; }); });
      const before = page.url();
      await page.click('[data-new-menu-toggle]');
      await page.click('[data-new-action="post"]');
      await page.waitForTimeout(300);
      expect.eq(page.url(), before, 'no navigation');
      expect.eq(await page.evaluate(() => window.__opened), 'post|kenda');
      expect(!(await page.isVisible('[data-new-menu-panel]')), 'menu closed after the action');
    });
    await test('App.newPost.open is used for "post" when a page defines it', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      await page.evaluate(() => { window.App.newPost = { open: (d) => { window.__np = d.action; } }; });
      await page.click('[data-new-menu-toggle]');
      await page.click('[data-new-action="post"]');
      await page.waitForTimeout(200);
      expect.eq(await page.evaluate(() => window.__np), 'post');
    });
    await test('Upload follows its link (Studio → Uploads)', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      await page.click('[data-new-menu-toggle]');
      await Promise.all([page.waitForNavigation(), page.click('[data-new-action="upload"]')]);
      expect(/studio\.php\?client=kenda&tab=uploads/.test(page.url()), page.url());
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'] });

  await run('new-menu-client', async ({ test, url, expect }) => {
    await test('client seat has no + New', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      expect.eq(await page.$$eval('[data-new-menu]', (els) => els.length), 0);
    });
  }, { role: 'client', viewports: ['phone'] });
})();
