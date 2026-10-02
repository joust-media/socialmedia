/* Round-3 UX fixes in the browser: the full email form never approves (and keeps an approved email approved);
   threads drawn from the viewer's seat; one editor in the post ⋯ menu; the admin's Needs changes segment on screen
   at 390 px; no "0 comments"; the Slide picker follows the carousel; Delete tire behind ⋯ with a confirm; Add to flow
   Position; one timing per flow card; no 320 px overflow on the forms; "Post date"; a client's Needs-changes link
   opens a notice (never a silent 404); the caption takes the focus after an inline upload.
   Screens: $PORTAL_TEST_ROOT/shots/r3-*.png */
'use strict';
const path = require('path');
const { execFileSync } = require('child_process');
const { run } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = path.join(ROOT, 'shots');

const visibleTexts = (page, sel) => page.$$eval(sel, (els) => els.filter((e) => e.offsetParent !== null).map((e) => e.textContent.trim()));
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
async function toastText(page, re) {
  await page.waitForFunction((src) => [...document.querySelectorAll('.ui-toast')].some((t) => new RegExp(src).test(t.textContent)), re.source, { timeout: 5000 });
  return true;
}
/** [actor, side ('mine'|'theirs'), label, is right-aligned] for every bubble of the open sheet's thread. */
async function bubbles(page, root = '#uiSheet') {
  return page.$$eval(root + ' [data-thread] .pd-msg', (els) => els.map((m) => {
    const b = m.querySelector('.ui-bubble').getBoundingClientRect(), t = m.parentNode.getBoundingClientRect();
    const meta = m.querySelector('.ui-bubble-meta').cloneNode(true);
    meta.querySelectorAll('.ui-avatar').forEach((a) => a.remove());
    return [m.getAttribute('data-actor'), m.classList.contains('pd-msg--mine') ? 'mine' : 'theirs',
            meta.textContent.split('·')[0].trim(), (b.right > t.right - 4) && (b.left > t.left + 8)];
  }));
}
function jpeg(name) {
  const file = path.join(ROOT, name);
  execFileSync('php', ['-r', `$i=imagecreatetruecolor(600,600);imagefill($i,0,0,imagecolorallocate($i,160,60,40));imagejpeg($i,'${file}',80);`]);
  return file;
}

(async () => {
  await run('round3-admin', async ({ test, url, expect, viewport }) => {
    await test('#1 Edit email…: Draft / To Review only; an approved email keeps Approved and Save returns to it', async (page) => {
      await page.goto(url('add-email.php?client=privacybee&edit=1'));
      expect.eq((await page.$$eval('#email-status option', (o) => o.map((x) => x.textContent.trim()))).join(','), 'Draft,To Review', 'draft W1');
      expect(await page.isDisabled('[data-email-live]'), 'Live is off for a draft');
      await page.goto(url('add-email.php?client=privacybee&edit=3'));
      expect.eq((await page.$$eval('#email-status option', (o) => o.map((x) => x.textContent.trim()))).join(','), 'Approved — unchanged,Draft,To Review', 'approved W3');
      expect(!(await page.isDisabled('[data-email-live]')), 'Live can be ticked while it stays approved');
      await page.selectOption('#email-status', 'pending');
      expect(await page.isDisabled('[data-email-live]'), 'routing it back turns Live off');
      await page.selectOption('#email-status', 'approved');
      await page.fill('#email-title', 'Three quicker wins');
      expect.eq(await overflow(page), 0, 'no sideways scroll');
      await page.click('[data-email-form] button[type="submit"]');
      await page.waitForSelector('#uiSheet.is-open [data-email-detail="3"]');
      expect(/emails\.php/.test(page.url()), 'back on Emails: ' + page.url());
      await toastText(page, /Three quicker wins saved/);
      expect.eq(await page.getAttribute('#uiSheet [data-email-detail="3"]', 'data-status'), 'approved', 'still approved');
    });

    await test('#2 the admin thread: the client note on the left with the client name; my reply on the right as "You"', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=4'));
      await page.waitForSelector('#uiSheet.is-open .pd[data-status="denied"]');
      expect.eq(JSON.stringify(await bubbles(page)), JSON.stringify([['client', 'theirs', 'Kenda Tires', false]]), 'client note');
      await page.fill('#uiSheet [data-comment-input]', 'On it — darker render coming');
      await page.click('#uiSheet [data-comment-send]');
      await page.waitForFunction(() => document.querySelectorAll('#uiSheet [data-thread] .pd-msg').length === 2);
      expect.eq(JSON.stringify((await bubbles(page))[1]), JSON.stringify(['admin', 'mine', 'You', true]), 'my reply');
      await page.screenshot({ path: path.join(SHOTS, `r3-thread-admin-${viewport}.png`) });
      await page.reload();
      await page.waitForSelector('#uiSheet.is-open .pd[data-status="denied"]');
      expect.eq(JSON.stringify((await bubbles(page)).map((b) => b.slice(0, 3))), JSON.stringify([['client', 'theirs', 'Kenda Tires'], ['admin', 'mine', 'You']]), 'after a reload');
    });

    await test('#2 email + page threads in the admin seat: the client on the left, named', async (page) => {
      // a client note (the admin seat may log one as the client: actorFromPost)
      await page.goto(url('emails.php?client=privacybee'));
      expect((await page.evaluate(() => App.post('email-status.php', { id: 2, comment: 'Shorter subject?', actor: 'client' }))).ok, 'client note logged');
      await page.goto(url('emails.php?client=privacybee&email=2'));
      await page.waitForSelector('#uiSheet.is-open [data-email-detail="2"]');
      expect.eq(JSON.stringify((await bubbles(page)).map((b) => b.slice(0, 3))), JSON.stringify([['client', 'theirs', 'Privacy Bee']]));
      await page.fill('#uiSheet [data-comment-input]', 'Done');
      await page.click('#uiSheet [data-comment-send]');
      await page.waitForFunction(() => document.querySelectorAll('#uiSheet [data-thread] .pd-msg').length === 2);
      expect.eq(JSON.stringify((await bubbles(page))[1].slice(0, 3)), JSON.stringify(['admin', 'mine', 'You']));
    });

    await test('#3 the post ⋯ menu: Edit post… is the one editor (it opens the pop-up)', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=2'));
      await page.waitForSelector('#uiSheet.is-open .pd');
      await page.click('#uiSheet [data-menu-toggle]');
      const items = await visibleTexts(page, '#uiSheet [data-menu] [role="menuitem"]');
      expect.eq(items[0], 'Edit post…');
      for (const x of ['Edit caption', 'Edit date', 'Replace image']) expect(items.indexOf(x) === -1, 'no ' + x + ': ' + items.join(' / '));
      await page.screenshot({ path: path.join(SHOTS, `r3-post-menu-${viewport}.png`) });
      await page.click('#uiSheet [data-menu] [data-newpost-edit]');
      await page.waitForSelector('.np-root.is-visible');
      expect.eq((await page.textContent('label[for="npDate"]')).trim(), 'Post date', '#10 composer');
    });

    await test('#4 the admin\'s Needs changes segment is on screen (Posts, Emails, Pages); no "0 comments"', async (page) => {
      for (const p of ['posts.php?client=kenda', 'emails.php?client=privacybee', 'pages.php?client=privacybee']) {
        await page.goto(url(p));
        const box = await page.$eval('.ui-segmented-item[data-segment="denied"]', (e) => { const r = e.getBoundingClientRect(); return { l: r.left, r: r.right, w: window.innerWidth }; });
        expect(box.l >= 0 && box.r <= box.w, `${p}: Needs changes on screen (${Math.round(box.l)}–${Math.round(box.r)} of ${box.w})`);
        const zero = await page.$$eval('[data-comment-count-for]', (els) => els.filter((e) => e.offsetParent !== null && /^0 /.test(e.textContent.trim())).length);
        expect.eq(zero, 0, p + ': no visible "0 comments"');
        expect.eq(await overflow(page), 0, p + ': no sideways scroll');
        if (viewport === 'phone') await page.screenshot({ path: path.join(SHOTS, `r3-segments-${p.split('.')[0]}-phone.png`) });
      }
    });

    await test('#6 the tire page: no red Delete in the header; ⋯ → Delete tire… asks first, then goes to Tires', async (page) => {
      const asked = [];
      page.on('dialog', (d) => asked.push(d.message()));
      await page.goto(url('assets.php?client=kenda&view=collections&item=3'));
      expect.eq((await visibleTexts(page, '.as-reference-admin .ui-btn')).filter((t) => /Delete/.test(t)).length, 0, 'no visible Delete button');
      await page.click('.as-tire-more [data-asg-menu-toggle]');
      await page.waitForSelector('.as-tire-more [data-asg-menu]:not([hidden]) [data-tire-delete-menu]');
      await page.screenshot({ path: path.join(SHOTS, `r3-tire-menu-${viewport}.png`) });
      expect.eq(await overflow(page), 0, 'the open menu never scrolls the page sideways');
      await page.click('[data-tire-delete-menu]');
      await page.waitForURL(/view=collections$/);
      expect(asked.length === 1 && /Delete “Kenetica Sport” and all of its images\?/.test(asked[0]), 'confirm first: ' + asked.join(' / '));
      expect.eq(await page.$$eval('[data-collection="3"]', (e) => e.length), 0, 'gone from Tires');
    });

    await test('#7 Add to flow: no empty Position; "At the end" + "After <code>" for a flow with steps', async (page) => {
      await page.goto(url('emails.php?client=privacybee&status=draft'));
      await page.click('[data-email-item="1"] [data-asg-menu-toggle]');
      await page.click('[data-email-item="1"] [data-assign="flow"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-flow="1"].is-disabled');
      expect(!(await page.isVisible('#asgSheet [data-asg-position]')), 'already in the only flow: no Position');
      await page.fill('#asgSheet [data-asg-new-flow]', 'Win-back');
      expect(!(await page.isVisible('#asgSheet [data-asg-position]')), 'a new flow: no Position');
      await page.screenshot({ path: path.join(SHOTS, `r3-addflow-${viewport}.png`) });
      await page.goto(url('emails.php?client=privacybee&status=denied'));
      await page.click('[data-email-item="4"] [data-asg-menu-toggle]');
      await page.click('[data-email-item="4"] [data-assign="flow"]');
      await page.waitForSelector('#asgSheet.is-open [data-asg-flow="1"]');
      await page.click('#asgSheet [data-asg-flow="1"]');
      expect(await page.isVisible('#asgSheet [data-asg-position]'), 'Position for a flow with steps');
      expect.eq((await page.$$eval('#asgSheet [data-asg-pos] option', (o) => o.map((x) => x.textContent))).join(' | '),
        'At the end (after W3) | First (before W1) | After W1 | After W2');
    });

    await test('#8 every flow step shows one timing', async (page) => {
      await page.goto(url('flows.php?client=privacybee'));
      await page.waitForSelector('[data-flow-step]');
      const n = await page.$$eval('[data-flow-step]', (e) => e.length);
      expect.eq(await page.$$eval('[data-flow-step] .fl-timing', (e) => e.length), n, 'one pill per step');
      expect.eq(await page.$$eval('.fl-card .el-trigger, .fl-card .fl-trigger', (e) => e.length), 0, 'no trigger line on the cards');
    });

    await test('#10 one date word: "Post date" in the sheet and the pop-up preview', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=5'));
      await page.waitForSelector('#uiSheet.is-open .pd');
      expect.eq((await page.textContent('#uiSheet .pd-when-label')).trim(), 'Post date', 'scheduled post');
      await page.goto(url('posts.php?client=kenda&newpost=1'));
      await page.waitForSelector('.np-root.is-visible [data-np-grid] .np-tile');
      expect.eq((await page.textContent('label[for="npDate"]')).trim(), 'Post date');
      const words = await page.evaluate(() => document.body.innerText);
      expect(!/Planned for|Scheduled for/.test(words), 'no Planned for / Scheduled for');
    });

    if (viewport === 'desktop') {
      await test('#12 (N9) an inline upload in the pop-up: the caption takes the focus (two panes)', async (page) => {
        await page.goto(url('posts.php?client=kenda&newpost=1'));
        await page.waitForSelector('.np-root.is-visible [data-np-grid] .np-tile');
        await page.click('[data-np-source] [data-value="upload"]');
        await page.setInputFiles('[data-np-file]', jpeg('r3-inline.jpg'));
        await page.waitForFunction(() => document.activeElement && document.activeElement.getAttribute('data-np-field') === 'caption', null, { timeout: 4000 });
      });
    }
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('round3-client', async ({ test, url, expect, viewport }) => {
    await test('#2 the client thread: my notes on the right as "You", Joust on the left', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=1'));
      await page.waitForSelector('#uiSheet.is-open .pd');
      await page.fill('#uiSheet [data-comment-input]', 'Looks good');
      await page.click('#uiSheet [data-comment-send]');
      await page.waitForFunction(() => document.querySelectorAll('#uiSheet [data-thread] .pd-msg').length === 1);
      expect.eq(JSON.stringify(await bubbles(page)), JSON.stringify([['client', 'mine', 'You', true]]));
    });

    await test('#5 the Slide picker follows the carousel (open = All slides, then the slide on screen); the comment is tagged', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=2'));
      await page.waitForSelector('#uiSheet.is-open [data-carousel]');
      expect.eq(await page.inputValue('#uiSheet [data-comment-slide]'), '', 'All slides on open (round 4)');
      await page.evaluate(() => { const car = document.querySelector('#uiSheet [data-carousel]'); App.carousel.go(car, 1, false); });
      await page.waitForFunction(() => document.querySelector('#uiSheet [data-comment-slide]').value === '2', null, { timeout: 3000 });
      await page.evaluate(() => { const car = document.querySelector('#uiSheet [data-carousel]'); App.carousel.go(car, 2, false); });
      await page.waitForFunction(() => document.querySelector('#uiSheet [data-comment-slide]').value === '3', null, { timeout: 3000 });
      await page.fill('#uiSheet [data-comment-input]', 'Brighter wheel here');
      await page.click('#uiSheet [data-comment-send]');
      await page.waitForSelector('#uiSheet [data-thread] .pd-msg .pd-slide-chip');
      expect.eq((await page.textContent('#uiSheet [data-thread] .pd-msg:last-child .pd-slide-chip')).trim(), 'Slide 3', 'tagged with the slide on screen');
      expect.eq(await page.inputValue('#uiSheet [data-comment-slide]'), '3', 'still on the visible slide after sending');
      await page.selectOption('#uiSheet [data-comment-slide]', '');
      expect.eq(await page.inputValue('#uiSheet [data-comment-slide]'), '', 'All slides is one pick away');
    });

    await test('#11 Home → "requested changes on Winter promo" opens a read-only notice with my note (no 404)', async (page) => {
      const bad = [];
      page.on('response', (r) => { if (r.status() >= 400) bad.push(r.status() + ' ' + r.url()); });
      await page.goto(url('index.php?client=kenda'));
      await page.click('a[href*="post=4"]');
      await page.waitForSelector('#uiSheet.is-open [data-hidden-post]');
      expect.eq((await page.textContent('#uiSheet [data-sheet-title]')).trim(), 'Winter promo', 'titled');
      const t = await page.textContent('#uiSheet');
      expect(/Joust is updating this post/.test(t) && /Please use the darker render/.test(t), 'notice + my note');
      expect.eq(await page.$$eval('#uiSheet [data-comment-form], #uiSheet [data-decide], #uiSheet [data-carousel]', (e) => e.length), 0, 'read-only');
      expect.eq(bad.length, 0, 'no 4xx/5xx: ' + bad.join(', '));
      await page.screenshot({ path: path.join(SHOTS, `r3-client-hidden-${viewport}.png`) });
      await page.click('#uiSheet [data-sheet-close]:not(.ui-sheet-close)');
      await page.waitForSelector('#uiSheet:not(.is-open)', { state: 'attached' });
    });

    await test('#11 a post the client may not see (a draft) says so in a toast', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=6'));
      await toastText(page, /no longer available/);
      expect(!(await page.isVisible('#uiSheet.is-open')), 'no sheet');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('round3-320', async ({ test, url, expect }) => {
    await test('#9 add-page / add-email / tire page at 320 px: no sideways scroll', async (page) => {
      for (const p of ['add-page.php?client=privacybee', 'add-page.php?client=privacybee&edit=1', 'add-email.php?client=privacybee&edit=3', 'assets.php?client=kenda&view=collections&item=1']) {
        await page.goto(url(p));
        expect.eq(await overflow(page), 0, p);
      }
      await page.goto(url('add-page.php?client=privacybee'));
      await page.screenshot({ path: path.join(SHOTS, 'r3-add-page-320.png'), fullPage: true });
    });
  }, { role: 'admin', viewports: ['w320'] });
})();
