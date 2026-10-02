/* Integration leftovers in the browser: Edit tire → a reference photo's Needs changes asks for the note in a sheet;
   the Library Upload sits in the header; unscoped add-post.php ends at the client chooser → New post pop-up;
   the series chips follow the active filter and move when the client approves. Screens: $PORTAL_TEST_ROOT/shots/left-*.png */
'use strict';
const path = require('path');
const { run } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = path.join(ROOT, 'shots');
const chip = async (page, key) => parseInt((await page.textContent(`[data-series-count="${key}"]`)).trim(), 10);

(async () => {
  await run('leftovers', async ({ test, url, expect, viewport }) => {
    await test('Edit tire: Needs changes asks for a note (>= 3 characters) in a sheet, then saves', async (page) => {
      const posts = [];
      page.on('request', (r) => { if (r.method() === 'POST' && /tire-status\.php/.test(r.url())) posts.push(r.postData() || ''); });
      await page.goto(url('add-feature.php?client=kenda&module=tires&edit_item=1'));
      const row = '[data-tire-row][data-image-id="2"]';
      expect.eq((await page.textContent(`${row} [data-status-set="denied"]`)).trim(), 'Needs changes');
      // Cancel: nothing is sent, the status stays
      await page.click(`${row} [data-status-set="denied"]`);
      await page.waitForSelector('#uiSheet.is-open [data-tl-note]');
      await page.click('#uiSheet [data-sheet-footer] [data-sheet-close]');
      await page.waitForSelector('#uiSheet:not(.is-open)', { state: 'attached' });
      expect.eq(await page.getAttribute(row, 'data-status'), 'approved', 'Cancel keeps the status');
      expect.eq(posts.length, 0, 'Cancel sends nothing');
      // The note: Send stays off until 3 characters
      await page.click(`${row} [data-status-set="denied"]`);
      await page.waitForSelector('#uiSheet.is-open [data-tl-note]');
      await page.waitForFunction(() => document.activeElement && document.activeElement.hasAttribute('data-tl-note'), null, { timeout: 2000 });   // the note is focused
      expect(await page.isDisabled('#uiSheet [data-tl-note-submit]'), 'disabled when empty');
      await page.fill('#uiSheet [data-tl-note]', 'ab');
      expect(await page.isDisabled('#uiSheet [data-tl-note-submit]'), 'disabled at 2 characters');
      await page.fill('#uiSheet [data-tl-note]', 'Tread is blurry');
      expect(!(await page.isDisabled('#uiSheet [data-tl-note-submit]')), 'enabled at 3+');
      await page.screenshot({ path: path.join(SHOTS, `left-tire-note-${viewport}.png`) });
      await page.click('#uiSheet [data-tl-note-submit]');
      await page.waitForSelector(`${row}[data-status="denied"] [data-status-set="denied"].is-active`);
      await page.waitForFunction((r) => /Saved/.test(document.querySelector(r + ' [data-status-hint]').textContent), row);
      expect(posts.length === 1 && /Tread is blurry/.test(posts[0]), 'one POST carrying the note');
      await page.reload();
      expect.eq(await page.getAttribute(row, 'data-status'), 'denied', 'saved');
      // Back to To Review: no sheet, saved straight away
      await page.click(`${row} [data-status-set="pending"]`);
      await page.waitForSelector(`${row}[data-status="pending"]`);
      expect(!(await page.isVisible('#uiSheet.is-open')), 'no sheet for To Review');
    });

    await test('Assets: the Library Upload is in the header, above the filter chips, and opens the Upload sheet', async (page) => {
      await page.goto(url('assets.php?client=kenda'));
      const btn = '.ui-nav [data-library-upload]';
      expect(await page.isVisible(btn), 'in the header');
      expect.eq(await page.$$eval('.as-controls [data-library-upload]', (e) => e.length), 0, 'not under the chips');
      const b = await page.$eval(btn, (e) => e.getBoundingClientRect().bottom), f = await page.$eval('.as-filters', (e) => e.getBoundingClientRect().top);
      expect(b <= f, `above the chips (${b} ≤ ${f})`);
      const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(over <= 0, 'no horizontal overflow: ' + over);
      await page.screenshot({ path: path.join(SHOTS, `left-assets-upload-${viewport}.png`) });
      await page.click(btn);
      await page.waitForSelector('.us-root.is-visible');
    });

    await test('#13 unscoped add-post.php → the client chooser → the New post pop-up for that client', async (page) => {
      await page.goto(url('add-post.php'));
      await page.waitForSelector('[data-np-pick-client="kenda"]');
      expect(/posts\.php/.test(page.url()) && !/newpost=/.test(page.url()), 'landed on Posts: ' + page.url());
      await page.click('[data-np-pick-client="kenda"]');
      await page.waitForSelector('[data-np-grid] .np-tile');
      expect((await page.textContent('[data-np-client]')).indexOf('Kenda') !== -1, 'scoped to Kenda');
    });

    await test('#14 the series chips follow the filter (To Review → Approved)', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1'));
      expect.eq([await chip(page, 'ref'), await chip(page, '1'), await chip(page, '2')].join(','), '0,2,0', 'To Review');
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved'));
      expect.eq([await chip(page, 'ref'), await chip(page, '1'), await chip(page, '2')].join(','), '3,5,5', 'Approved');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('leftovers-client', async ({ test, url, expect }) => {
    await test('#14 approving in the viewer moves the active series chip (To Review 2 → 1)', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1'));
      expect.eq(await chip(page, '1'), 2);
      await page.click('#assetsGrid .as-thumb[data-status="pending"]');
      await page.waitForSelector('[data-viewer-approve]:visible');
      await page.click('[data-viewer-approve]');
      await page.waitForFunction(() => document.querySelector('[data-series-count="1"]').textContent.trim() === '1');
      expect.eq(await chip(page, '2'), 0, 'other chips untouched');
    });
  }, { role: 'client', viewports: ['desktop'], reseed: 'test' });
})();
