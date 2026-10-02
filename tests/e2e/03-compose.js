/* Compose = the New post pop-up now (the Studio Compose tab is retired → studio.php?tab=compose opens the
   pop-up): the unsaved-work guard (in-sheet confirm + beforeunload), no guard when untouched, and a 3-image
   carousel that lands on its post with 3 slides and a working counter. Full pop-up coverage: 04-newpost.js. */
'use strict';
const { run } = require('./lib');

async function pick(page, n) {
  await page.waitForSelector('[data-np-grid] .np-tile');
  const refs = await page.evaluate(() => App.newPost._state().items.filter((i) => i.media !== 'video').map((i) => i.ref));
  const chosen = refs.slice(0, n);
  for (const r of chosen) await page.click(`[data-np-grid] [data-np-ref="${r}"]`);
  return chosen;
}

(async () => {
  await run('compose', async ({ test, url, expect }) => {
    await test('Studio ?tab=compose opens the pop-up on Studio', async (page) => {
      await page.goto(url('studio.php?client=kenda&tab=compose'));
      await page.waitForSelector('.np-root.is-visible');
      expect(/studio\.php\?client=kenda$/.test(page.url()), 'landed on Studio: ' + page.url());
      expect.eq(await page.$$eval('[data-studio-tab="compose"], [data-studio-section="compose"]', (e) => e.length), 0, 'no Compose tab');
    });
    await test('leaving with unsaved picks asks first (beforeunload)', async (page) => {
      await page.goto(url('studio.php?client=kenda&tab=compose'));
      let asked = null;
      page.removeAllListeners('dialog');
      page.on('dialog', (d) => { asked = d.type(); d.dismiss().catch(() => {}); });
      await pick(page, 2);
      await page.goto(url('posts.php?client=kenda')).catch(() => {});
      expect.eq(asked, 'beforeunload');
    });
    await test('no guard when nothing was touched', async (page) => {
      await page.goto(url('studio.php?client=kenda&tab=compose'));
      await page.waitForSelector('.np-root.is-visible');
      let asked = null;
      page.on('dialog', (d) => { asked = d.type(); d.accept().catch(() => {}); });
      await page.keyboard.press('Escape');
      await page.waitForSelector('.np-root', { state: 'detached' });
      await page.goto(url('posts.php?client=kenda'));
      expect.eq(asked, null);
    });
    await test('3-image carousel: Send for review lands on the post with 3 slides (no guard on submit)', async (page) => {
      await page.goto(url('studio.php?client=kenda&tab=compose'));
      let asked = null;
      page.on('dialog', (d) => { asked = d.type(); d.accept().catch(() => {}); });
      await pick(page, 3);
      expect.eq(await page.$$eval('[data-np-preview-media] .pd-slide', (els) => els.length), 3, 'preview slides');
      if (await page.isVisible('[data-np-steps]')) await page.click('[data-np-steps] [data-value="details"]');
      await page.fill('#npCaption', 'Three looks, one tire.');
      await Promise.all([page.waitForURL(/posts\.php\?client=kenda&post=\d+/), page.click('[data-np-save="review"]')]);
      await page.waitForSelector('#uiSheet .pd-slide');
      expect.eq(await page.$$eval('#uiSheet .pd-slide', (els) => els.length), 3, 'sheet slides');
      expect.eq((await page.textContent('#uiSheet [data-carousel-counter]')).trim(), '1 / 3');
      await page.click('#uiSheet [data-carousel-dot="2"]');
      await page.waitForFunction(() => document.querySelector('#uiSheet [data-carousel-counter]').textContent.trim() === '3 / 3', null, { timeout: 4000 });
      expect.eq(asked, null, 'no beforeunload on our own submit');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'viewport' });
})();
