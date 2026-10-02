/* Drafts in the browser: admin Drafts segment, Send for review (422 → caption editor, then success). */
'use strict';
const { run } = require('./lib');

(async () => {
  await run('drafts', async ({ test, url, expect }) => {
    await test('Drafts segment is first and lists both drafts without swipe', async (page) => {
      await page.goto(url('posts.php?client=kenda&status=draft&month=all'));
      const segs = await page.$$eval('.ui-segmented-item[data-segment]', (els) => els.map((e) => e.dataset.segment));
      expect.eq(segs[0], 'draft');
      expect.eq(await page.$$eval('[data-posts-items] [data-post-item]', (els) => els.length), 2);
      expect.eq(await page.$$eval('[data-posts-items] [data-swipe]', (els) => els.length), 0, 'no swipe rows');
    });
    await test('Send for review without a caption → error toast + caption editor', async (page) => {
      await page.goto(url('posts.php?client=kenda&status=draft&month=all'));
      await page.click('[data-post-item="7"] [data-submit-post="7"]');
      await page.waitForSelector('#uiSheet [data-edit-form="caption"]:not([hidden])', { timeout: 5000 });
      const toast = await page.textContent('.ui-toast').catch(() => '');
      expect(/caption/i.test(toast || ''), 'toast mentions the caption: ' + toast);
    });
    await test('Send for review → the row leaves Drafts, counts move', async (page) => {
      await page.goto(url('posts.php?client=kenda&status=draft&month=all'));
      const before = await page.textContent('.ui-segmented-item[data-segment="pending"] .ui-segmented-count');
      await page.click('[data-post-item="6"] [data-submit-post="6"]');
      await page.waitForSelector('[data-post-item="6"]', { state: 'detached', timeout: 5000 });
      expect.eq(await page.textContent('.ui-segmented-item[data-segment="draft"] .ui-segmented-count'), '1');
      expect.eq(await page.textContent('.ui-segmented-item[data-segment="pending"] .ui-segmented-count'), String(parseInt(before, 10) + 1));
    });
    await test('draft sheet shows the Draft pill and Send for review footer', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=7'));
      await page.waitForSelector('#uiSheet .pd');
      expect.eq((await page.textContent('#uiSheet .pd-pill')).trim(), 'Draft');
      expect(await page.isVisible('#uiSheet [data-state="admin-draft"] [data-submit-post]'), 'Send for review visible');
      expect(!(await page.isVisible('#uiSheet [data-state="decide"]')), 'no Approve / Deny on a draft');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });
})();
