/* Compose (Studio): "Clear" only with a selection, the unsaved-work guard, a 3-image carousel lands on its post. */
'use strict';
const { run } = require('./lib');

async function pick(page, n) {
  const keys = await page.$$eval('[data-picker] [data-asset-key]', (els) => els.filter((e) => !e.hidden && e.dataset.assetMedia !== 'video').map((e) => e.dataset.assetKey));
  const chosen = keys.slice(0, n);
  for (const k of chosen) await page.click(`[data-picker] [data-asset-key="${k}"]`);
  return chosen;
}

(async () => {
  await run('compose', async ({ test, url, expect }) => {
    await test('"Clear" is hidden with nothing selected, shown with a selection', async (page) => {
      await page.goto(url('studio.php?client=kenda'));
      expect(!(await page.isVisible('[data-pick-clear]')), 'hidden at 0');
      await pick(page, 1);
      expect(await page.isVisible('[data-pick-clear]'), 'visible at 1');
      await page.click('[data-pick-clear]');
      expect(!(await page.isVisible('[data-pick-clear]')), 'hidden again');
    });
    await test('leaving with unsaved picks asks first (beforeunload)', async (page) => {
      await page.goto(url('studio.php?client=kenda'));
      let asked = null;
      page.removeAllListeners('dialog');
      page.on('dialog', (d) => { asked = d.type(); d.dismiss().catch(() => {}); });
      await pick(page, 2);
      await page.goto(url('posts.php?client=kenda')).catch(() => {});
      expect.eq(asked, 'beforeunload');
    });
    await test('no guard when nothing was touched', async (page) => {
      await page.goto(url('studio.php?client=kenda'));
      let asked = null;
      page.on('dialog', (d) => { asked = d.type(); d.accept().catch(() => {}); });
      await page.goto(url('posts.php?client=kenda'));
      expect.eq(asked, null);
    });
    await test('3-image carousel: Create post lands on the post with 3 slides (no guard on submit)', async (page) => {
      await page.goto(url('studio.php?client=kenda'));
      let asked = null;
      page.on('dialog', (d) => { asked = d.type(); d.accept().catch(() => {}); });
      await pick(page, 3);
      expect.eq(await page.$$eval('[data-preview] .pd-slide', (els) => els.length), 3, 'preview slides');
      await page.fill('[data-field="caption"]', 'Three looks, one tire.');
      await Promise.all([page.waitForURL(/posts\.php\?client=kenda&post=\d+/), page.click('[data-composer-submit]')]);
      await page.waitForSelector('#uiSheet .pd-slide');
      expect.eq(await page.$$eval('#uiSheet .pd-slide', (els) => els.length), 3, 'sheet slides');
      expect.eq((await page.textContent('#uiSheet [data-carousel-counter]')).trim(), '1/3');
      await page.click('#uiSheet [data-carousel-dot="2"]');
      await page.waitForFunction(() => document.querySelector('#uiSheet [data-carousel-counter]').textContent.trim() === '3/3', null, { timeout: 4000 });
      expect.eq(asked, null, 'no beforeunload on our own submit');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'viewport' });
})();
