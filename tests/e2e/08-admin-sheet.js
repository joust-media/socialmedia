/* Admin-first review sheets in the browser: the visible primary per status (post + email), the client's decisions
   only through ⋯ (Approve for client… asks first; Needs changes… pins the note on top), the client's own
   Needs changes · Approve, and the list moving without a reload. Screens: $PORTAL_TEST_ROOT/shots/sheet-*.png */
'use strict';
const path = require('path');
const { run } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = path.join(ROOT, 'shots');

/** Labels of the footer buttons the user can see right now. */
async function primaries(page) {
  return page.$$eval('#uiSheet [data-actions] .ui-btn', (els) => els.filter((e) => e.offsetParent !== null).map((e) => e.textContent.trim()));
}
async function segCount(page, seg) { return parseInt((await page.textContent(`.ui-segmented-item[data-segment="${seg}"] .ui-segmented-count`)).trim(), 10); }

(async () => {
  await run('admin-sheet', async ({ test, url, expect, viewport }) => {
    const posts = { draft: [6, ['Edit post…', 'Send for review']], pending: [1, ['Edit post…']], denied: [4, ['Edit & resubmit']],
                    approved: [3, ['Edit post…', 'Mark scheduled']], scheduled: [5, ['Unmark scheduled']] };
    await test('post sheet: one admin primary row per status; never the client\'s Approve', async (page) => {
      for (const [st, [id, want]] of Object.entries(posts)) {
        await page.goto(url(`posts.php?client=kenda&post=${id}`));
        await page.waitForSelector('#uiSheet.is-open .pd');
        expect.eq((await primaries(page)).join(' | '), want.join(' | '), st);
        expect(!(await page.isVisible('#uiSheet [data-decide="approved"]')), st + ': no Approve button');
        expect.eq(await page.isVisible('#uiSheet [data-pd-note]'), st === 'denied', st + ': note only for Needs changes');
      }
      await page.screenshot({ path: path.join(SHOTS, `sheet-post-scheduled-${viewport}.png`) });
    });

    await test('⋯ → Approve for client… asks first, then approves in place (row leaves To Review, counts move)', async (page) => {
      const asked = [];
      page.on('dialog', (d) => asked.push(d.message()));
      await page.goto(url('posts.php?client=kenda&post=1'));
      await page.waitForSelector('#uiSheet.is-open .pd[data-status="pending"]');
      await page.evaluate(() => { window.__noReload = 1; });
      const p0 = await segCount(page, 'pending'), a0 = await segCount(page, 'approved');
      await page.click('#uiSheet [data-menu-toggle]');
      expect(await page.isVisible('#uiSheet [data-approve-for-client]'), 'in ⋯');
      await page.click('#uiSheet [data-approve-for-client]');
      await page.waitForSelector('#uiSheet .pd[data-status="approved"]');
      expect(asked.length === 1 && /Approve this post for Kenda Tires\?/.test(asked[0]), 'confirm first: ' + asked.join(' / '));
      expect.eq((await primaries(page)).join(' | '), 'Edit post… | Mark scheduled');
      expect.eq(await segCount(page, 'pending'), p0 - 1); expect.eq(await segCount(page, 'approved'), a0 + 1);
      await page.waitForSelector('[data-posts-items] [data-post-item="1"]', { state: 'detached' });
      expect(await page.evaluate(() => window.__noReload === 1), 'no reload');
    });

    await test('⋯ → Needs changes… (Approved): note → the note banner on top + Edit & resubmit', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=3'));
      await page.waitForSelector('#uiSheet.is-open .pd[data-status="approved"]');
      await page.click('#uiSheet [data-menu-toggle]');
      await page.click('#uiSheet [data-menu] [data-decide="denied"]');
      await page.fill('#uiSheet [data-deny-note]', 'Logo is cropped');
      await page.click('#uiSheet [data-deny-submit]');
      await page.waitForSelector('#uiSheet .pd[data-status="denied"]');
      await page.waitForSelector('#uiSheet [data-pd-note]:not([hidden])');
      expect((await page.textContent('#uiSheet [data-pd-note]')).indexOf('Logo is cropped') !== -1, 'note text');
      expect.eq((await primaries(page)).join(' | '), 'Edit & resubmit');
    });

    await test('⋯ → Send for review (Needs changes) resubmits as is; the queue count moves', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=4'));
      await page.waitForSelector('#uiSheet.is-open .pd[data-status="denied"]');
      const q0 = await segCount(page, 'denied');
      await page.click('#uiSheet [data-menu-toggle]');
      await page.click('#uiSheet [data-menu] [data-decide="pending"]');
      await page.waitForSelector('#uiSheet .pd[data-status="pending"]');
      expect.eq(await segCount(page, 'denied'), q0 - 1, 'Needs changes −1');
      await page.waitForFunction(() => /Sent for review/.test((document.querySelector('.ui-toast') || {}).textContent || ''));
    });

    const emails = { draft: [1, ['Edit', 'Send for review']], pending: [2, ['Edit']], denied: [4, ['Edit', 'Send for review']],
                     approved: [3, ['Edit', 'Mark live']], live: [5, ['Unmark live']] };
    await test('email sheet: one row + ⋯ (no status segment); Approve for client… asks first', async (page) => {
      for (const [st, [id, want]] of Object.entries(emails)) {
        await page.goto(url(`emails.php?client=privacybee&email=${id}`));
        await page.waitForSelector('#uiSheet.is-open .ed');
        expect.eq((await primaries(page)).join(' | '), want.join(' | '), st);
        expect.eq(await page.$$eval('#uiSheet [data-set-status]', (e) => e.length), 0, st + ': no status override');
        if (st === 'pending') await page.screenshot({ path: path.join(SHOTS, `sheet-email-pending-${viewport}.png`) });
      }
      const asked = [];
      page.on('dialog', (d) => asked.push(d.message()));
      await page.goto(url('emails.php?client=privacybee&email=2'));
      await page.waitForSelector('#uiSheet.is-open .ed[data-status="pending"]');
      await page.click('#uiSheet [data-asg-menu-toggle]');
      await page.click('#uiSheet [data-approve-for-client]');
      await page.waitForSelector('#uiSheet .ed[data-status="approved"]');
      expect(asked.length === 1 && /Approve this email for Privacy Bee\?/.test(asked[0]), 'confirm first');
      expect(await page.isHidden('#uiSheet [data-asg-menu]'), 'menu closed');
      expect.eq((await primaries(page)).join(' | '), 'Edit | Mark live');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('admin-sheet-client', async ({ test, url, expect }) => {
    await test('client: post + email To Review = Needs changes · Approve; Approved / Scheduled = no buttons', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=1'));
      await page.waitForSelector('#uiSheet.is-open .pd');
      expect.eq((await primaries(page)).join(' | '), 'Needs changes | Approve');
      for (const id of [3, 5]) {
        await page.goto(url(`posts.php?client=kenda&post=${id}`));
        await page.waitForSelector('#uiSheet.is-open .pd');
        expect.eq((await primaries(page)).length, 0, 'post ' + id);
      }
      await page.goto(url('emails.php?client=privacybee&email=2'));
      await page.waitForSelector('#uiSheet.is-open .ed');
      expect.eq((await primaries(page)).join(' | '), 'Needs changes | Approve');
      await page.click('#uiSheet [data-decide="denied"]');
      expect.eq((await page.textContent('#uiSheet [data-deny-submit]')).trim(), 'Send');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'], reseed: 'test' });
})();
