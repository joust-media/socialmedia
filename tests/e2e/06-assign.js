/* Assign: the ⋯ menus on emails / pages (Move to client, Add to flow, Set audiences), the multi-select
   bulk bar (fits a 390px phone), and "+ New → New email / New page" sheets in place. */
'use strict';
const { run } = require('./lib');

const LIST = 'emails.php?client=privacybee&status=all';

(async () => {
  await run('assign', async ({ test, url, expect, viewport }) => {
    // waits (up to 8 s) for the toast to read `want` — an earlier toast may still be showing — then returns its text
    const toastText = async (page, want) => {
      await page.waitForFunction((w) => { const t = document.getElementById('uiToast'); return t && t.classList.contains('is-visible') && t.textContent.trim() === w; }, want, { timeout: 8000 }).catch(() => {});
      return (await page.textContent('#uiToast')).trim();
    };

    await test('email row ⋯ → Move to client → Kenda (4 clicks): the row leaves, Kenda has it', async (page) => {
      await page.goto(url(LIST));
      await page.click('[data-email-item="2"] [data-asg-menu-toggle]');                         // 1
      expect(await page.isVisible('[data-email-item="2"] [data-asg-menu]'), 'menu open');
      await page.click('[data-email-item="2"] [data-assign="move"]');                           // 2
      await page.waitForSelector('#asgSheet.is-open [data-asg-client="kenda"]');
      expect(!(await page.isVisible('#asgSheet [data-asg-client="privacybee"]')), 'current client not offered');
      expect(await page.isDisabled('#asgSheet [data-asg-submit]'), 'Move disabled until a client is picked');
      await page.click('#asgSheet [data-asg-client="kenda"]');                                   // 3
      expect.eq((await page.textContent('#asgSheet [data-asg-submit]')).trim(), 'Move to Kenda Tires');
      await page.click('#asgSheet [data-asg-submit]');                                          // 4
      expect.eq(await toastText(page, 'Moved W2 · Your first scan to Kenda Tires'), 'Moved W2 · Your first scan to Kenda Tires');
      await page.waitForSelector('[data-email-item="2"]', { state: 'detached' });
      await page.goto(url('emails.php?client=kenda&status=all'));
      expect(await page.isVisible('[data-email-item="2"]'), 'listed under Kenda');
    });

    await test('detail ⋯ → Add to flow → Welcome (end): toast + the detail reopens with the flow chip', async (page) => {
      await page.goto(url(LIST + '&email=4'));
      await page.waitForSelector('#uiSheet.is-open [data-email-detail="4"]');
      await page.click('#uiSheet [data-asg-menu-toggle]');                                       // 1
      await page.click('#uiSheet [data-assign="flow"]');                                         // 2
      await page.waitForSelector('#asgSheet.is-open [data-asg-flow="1"]');
      await page.click('#asgSheet [data-asg-flow="1"]');                                         // 3
      expect(await page.isVisible('#asgSheet [data-asg-position]'), 'position picker shows for a flow with steps');
      expect.eq(await page.inputValue('#asgSheet [data-asg-pos]'), '', 'defaults to the end');
      await page.click('#asgSheet [data-asg-submit]');                                          // 4
      expect.eq(await toastText(page, 'Added to Welcome as step 4'), 'Added to Welcome as step 4');
      await page.waitForSelector('#uiSheet.is-open [data-email-detail="4"] [data-flow-chip="welcome"]');
      expect((await page.textContent('#uiSheet [data-flow-chip="welcome"]')).includes('step 4 of 4'), 'fresh detail');
    });

    await test('Add to flow at a position, and into a new flow', async (page) => {
      await page.goto(url(LIST));
      await page.click('[data-email-item="5"] [data-asg-menu-toggle]');
      await page.click('[data-email-item="5"] [data-assign="flow"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-flow="1"]');
      await page.click('#asgSheet [data-asg-flow="1"]');
      await page.selectOption('#asgSheet [data-asg-pos]', '0');
      await page.click('#asgSheet [data-asg-submit]');
      expect.eq(await toastText(page, 'Added to Welcome as step 1'), 'Added to Welcome as step 1');
      await page.click('[data-email-item="4"] [data-asg-menu-toggle]');
      await page.click('[data-email-item="4"] [data-assign="flow"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-new-flow]');
      await page.fill('#asgSheet [data-asg-new-flow]', 'Win-back');
      expect.eq((await page.textContent('#asgSheet [data-asg-submit]')).trim(), 'Create “Win-back”');
      await page.click('#asgSheet [data-asg-submit]');
      expect.eq(await toastText(page, 'Added to Win-back as step 1'), 'Added to Win-back as step 1');
    });

    await test('row ⋯ → Set audiences: tick Renewal → the row shows Free + Renewal', async (page) => {
      await page.goto(url(LIST));
      await page.click('[data-email-item="1"] [data-asg-menu-toggle]');
      await page.click('[data-email-item="1"] [data-assign="audiences"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-audience="2"]');
      expect.eq(await page.getAttribute('#asgSheet [data-asg-audience="1"]', 'data-state'), 'on');
      await page.click('#asgSheet [data-asg-audience="2"]');
      await page.fill('#asgSheet [data-asg-new-audience]', 'Leads');
      await page.click('#asgSheet [data-asg-submit]');
      expect.eq(await toastText(page, 'Audiences saved'), 'Audiences saved');
      const tags = await page.$$eval('[data-email-item="1"] .el-groups .el-tag', (els) => els.map((e) => e.textContent.trim()));
      expect.eq(tags.join(','), 'Free,Renewal,Leads');
    });

    await test('bulk bar: Select → 2 rows → Audiences shows the mixed state; Move… moves both', async (page) => {
      await page.goto(url(LIST));
      await page.click('[data-asg-select="email"]');
      expect.eq((await page.textContent('[data-asg-select="email"]')).trim(), 'Cancel');
      expect(await page.isVisible('[data-asg-bulkbar]'), 'bar shows');
      expect(await page.isDisabled('[data-asg-bulkbar] [data-asg-bulk="move"]'), 'disabled at 0');
      await page.click('[data-email-item="1"] .pl-card');
      await page.click('[data-email-item="4"] .pl-card');
      expect(!(await page.isVisible('#uiSheet.is-open')), 'a tap selects instead of opening');
      expect.eq((await page.textContent('[data-asg-count]')).trim(), '2 emails selected');
      // the whole bar is inside the viewport and above the tab bar (390px phone too)
      const vw = page.viewportSize().width, vh = page.viewportSize().height;
      const boxes = await page.$$eval('[data-asg-bulkbar] .ui-btn', (els) => els.map((e) => { const r = e.getBoundingClientRect(); return [r.left, r.right, r.top, r.bottom, e.scrollWidth <= e.clientWidth + 1]; }));
      expect.eq(boxes.length, 4);
      boxes.forEach(([l, r, t, b, fits]) => { expect(l >= 0 && r <= vw && t >= 0 && b <= vh, 'button inside the viewport ' + [l, r, t, b]); expect(fits, 'label fits its button'); });
      const tab = await page.$('.ui-tabbar');
      if (viewport === 'phone' && tab) {
        const bar = await page.$eval('[data-asg-bulkbar]', (e) => e.getBoundingClientRect().bottom);
        const tb = await tab.boundingBox();
        expect(bar <= tb.y + 1, 'bar sits above the tab bar');
      }
      await page.click('[data-asg-bulk="audiences"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-audience="1"]');
      expect.eq(await page.getAttribute('#asgSheet [data-asg-audience="1"]', 'data-state'), 'mixed', 'Free: W1 yes, R1 no');
      await page.click('#asgSheet [data-sheet-close]');
      await page.waitForSelector('#asgSheet.is-open', { state: 'detached' }).catch(() => {});
      await page.waitForTimeout(400);
      await page.click('[data-asg-bulk="move"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-client="hmf"]');
      expect((await page.textContent('#asgSheet .asg-lead')).includes('2 emails'), 'lead names the count');
      await page.click('#asgSheet [data-asg-client="hmf"]');
      await page.click('#asgSheet [data-asg-submit]');
      expect.eq(await toastText(page, 'Moved 2 emails to Hollow Mill Farm'), 'Moved 2 emails to Hollow Mill Farm');
      await page.waitForSelector('[data-email-item="4"]', { state: 'detached' });
      expect(!(await page.isVisible('[data-asg-bulkbar]')), 'bar gone after the action');
      expect.eq((await page.textContent('[data-asg-select="email"]')).trim(), 'Select');
    });

    await test('pages: row ⋯ → Move to client', async (page) => {
      await page.goto(url('pages.php?client=privacybee&status=all'));
      await page.click('[data-page-item="2"] [data-asg-menu-toggle]');
      expect.eq(await page.$$eval('[data-page-item="2"] [data-assign]', (els) => els.map((e) => e.dataset.assign).join(',')), 'move');
      await page.click('[data-page-item="2"] [data-assign="move"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-client="kenda"]');
      await page.click('#asgSheet [data-asg-client="kenda"]');
      await page.click('#asgSheet [data-asg-submit]');
      expect.eq(await toastText(page, 'Moved Pricing to Kenda Tires'), 'Moved Pricing to Kenda Tires');
      await page.waitForSelector('[data-page-item="2"]', { state: 'detached' });
    });

    await test('⋯ menu: Escape closes it and returns focus', async (page) => {
      await page.goto(url(LIST));
      await page.click('[data-email-item="3"] [data-asg-menu-toggle]');
      await page.keyboard.press('Escape');
      expect(!(await page.isVisible('[data-email-item="3"] [data-asg-menu]')), 'closed');
      expect.eq(await page.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-asg-menu-toggle')), true);
    });

    await test('+ New → New email: sheet in place → pasted HTML → the Draft opens with its preview', async (page) => {
      await page.goto(url('?client=privacybee'));
      const before = page.url();
      await page.click('[data-new-menu-toggle]');
      await page.click('[data-new-action="email"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-c-title]');
      expect.eq(page.url(), before, 'no navigation');
      expect.eq(await page.inputValue('#asgSheet [data-asg-c-client]'), 'privacybee');
      expect.eq(await page.getAttribute('#asgSheet [data-asg-fallback]', 'href'), '/portal/add-email.php?client=privacybee');
      await page.fill('#asgSheet [data-asg-c-title]', 'Launch teaser');
      await page.click('#asgSheet .asg-src [data-value="paste"]');
      await page.fill('#asgSheet [data-asg-c-html]', '<!doctype html><html><body><h1>Teaser</h1></body></html>');
      await Promise.all([page.waitForNavigation(), page.click('#asgSheet [data-asg-submit]')]);
      expect(/emails\.php\?client=privacybee&email=\d+/.test(page.url()) || /emails\.php\?client=privacybee/.test(page.url()), page.url());
      await page.waitForSelector('#uiSheet.is-open .ed[data-status="draft"]');
      const src = await page.getAttribute('#uiSheet [data-preview-frame]', 'src');
      expect(/^\/media\/emails\/privacybee\/e1-\d+\.html$/.test(src || ''), 'hosted preview ' + src);
    });

    await test('+ New → New page: an .html file → the Draft page opens', async (page) => {
      await page.goto(url('emails.php?client=privacybee'));
      await page.click('[data-new-menu-toggle]');
      await page.click('[data-new-action="page"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-c-file]');
      await page.setInputFiles('#asgSheet [data-asg-c-file]', { name: 'summer-sale.html', mimeType: 'text/html', buffer: Buffer.from('<html><body><h1>Summer</h1></body></html>') });
      expect.eq(await page.inputValue('#asgSheet [data-asg-c-title]'), 'summer sale', 'title from the file name');
      await page.fill('#asgSheet [data-asg-c-title]', 'Summer sale');
      await Promise.all([page.waitForNavigation(), page.click('#asgSheet [data-asg-submit]')]);
      expect(/pages\.php\?client=privacybee/.test(page.url()), page.url());
      await page.waitForSelector('#uiSheet.is-open .pg[data-status="draft"]');
      expect.eq(await page.getAttribute('#uiSheet .pg', 'data-slug'), 'summer-sale');
    });

    await test('+ New → New email: a missing title keeps the sheet open', async (page) => {
      await page.goto(url('pages.php?client=privacybee'));
      await page.click('[data-new-menu-toggle]');
      await page.click('[data-new-action="email"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-c-title]');
      await page.click('#asgSheet [data-asg-submit]');
      expect.eq(await toastText(page, 'Give the email a title'), 'Give the email a title');
      expect(await page.isVisible('#asgSheet.is-open'), 'still open');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('assign-client', async ({ test, url, expect }) => {
    await test('client seat: no ⋯, no Select, no assign script', async (page) => {
      await page.goto(url('emails.php?client=privacybee&status=approved'));
      expect.eq(await page.$$eval('[data-asg-menu-toggle], [data-asg-select], [data-assign]', (els) => els.length), 0);
      expect.eq(await page.evaluate(() => !!(window.App && window.App.assign)), false);
      await page.goto(url('pages.php?client=privacybee&status=approved'));
      expect.eq(await page.$$eval('[data-asg-menu-toggle], [data-asg-select]', (els) => els.length), 0);
    });
  }, { role: 'client', viewports: ['phone'] });
})();
