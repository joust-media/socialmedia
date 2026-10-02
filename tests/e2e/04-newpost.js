/* New post pop-up (static/js/newpost.js): every entry point, a 5-slide carousel across two series in ≤ 8
   clicks and a single image in ≤ 4 (the audit's T2 / T1 targets, counted like the rescore: every click or tap,
   focusing the caption included unless it happens on its own — the test asserts it does), the list refreshing in
   place after every save (no reload: row, status, segment counts, the open sheet), Edit & resubmit from Needs
   changes, drag + keyboard reorder, the shape warning, edit → reorder → saved order,
   validation, the unsaved-changes guard, the phone layout, and the client's swipeable carousel + slide
   comment chip. Screenshots of the pop-up: $PORTAL_TEST_ROOT/shots/newpost-1440-dark.png, -390-light.png. */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = path.join(ROOT, 'shots');
const CLICKS = path.join(ROOT, 'newpost-clicks.txt');

/** A JPEG of w×h made with PHP's GD (the harness already needs it). */
function jpeg(name, w, h) {
  const file = path.join(ROOT, name);
  execFileSync('php', ['-r', `$i=imagecreatetruecolor(${w},${h});imagefill($i,0,0,imagecolorallocate($i,40,90,160));imagejpeg($i,'${file}',80);`]);
  return file;
}
const sel = {
  root: '.np-root.is-visible', tile: '[data-np-grid] .np-tile', slide: '[data-np-tray-list] .np-slide',
};
async function openFromPosts(page, url) {
  await page.goto(url('posts.php?client=kenda'));
  await page.click('[data-new-menu-toggle]');
  await page.click('[data-new-action="post"]');
  await page.waitForSelector(sel.root);
  await page.waitForSelector(sel.tile);
}
/** refs of approved Klever AT2 renders in series 1 / 2 as the picker lists them. */
async function seriesRefs(page) {
  return page.evaluate(() => {
    const s = App.newPost._state();
    const by = (k) => s.items.filter((i) => i.group === 'tire:1' && i.series === k).map((i) => i.ref);
    return { s1: by('1'), s2: by('2'), ref: by('ref') };
  });
}
/** A marker on window: still there after the save = the page never reloaded. */
async function mark(page) { await page.evaluate(() => { window.__noReload = 1; }); }
async function stillSamePage(page) { return page.evaluate(() => window.__noReload === 1); }
async function segCount(page, seg) { return parseInt((await page.textContent(`.ui-segmented-item[data-segment="${seg}"] .ui-segmented-count`)).trim(), 10); }
/** The caption has the focus without a click (the rubric counts a focusing click). */
async function captionFocused(page) { return page.evaluate(() => document.activeElement && document.activeElement.id === 'npCaption'); }
async function trayRefs(page) { return page.evaluate(() => App.newPost._state().slides.map((s) => s.ref)); }
async function load(page, url, id) {
  return page.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).json(), url('post-compose.php?client=kenda&action=load&id=' + id));
}

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  fs.writeFileSync(CLICKS, '');
  const portrait = jpeg('np-portrait.jpg', 640, 800);
  const square = jpeg('np-square.jpg', 600, 600);

  await run('newpost-client', async ({ test, url, expect, viewport }) => {
    await test('client: swipeable carousel ("2 / 3"), slide comment → chip', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=2'));
      await page.waitForSelector('#uiSheet.is-open [data-carousel]');
      expect.eq(await page.textContent('#uiSheet [data-carousel-counter]'), '1 / 3');
      if (viewport === 'desktop') {
        await page.hover('#uiSheet [data-carousel]');
        await page.click('#uiSheet [data-carousel-next]');
      } else {
        await page.$eval('#uiSheet [data-carousel-track]', (t) => t.scrollTo({ left: t.clientWidth, behavior: 'auto' }));
      }
      await page.waitForFunction(() => document.querySelector('#uiSheet [data-carousel-counter]').textContent === '2 / 3');
      await page.focus('#uiSheet [data-carousel-track]');
      await page.keyboard.press('ArrowRight');
      await page.waitForFunction(() => document.querySelector('#uiSheet [data-carousel-counter]').textContent === '3 / 3');
      expect.eq(await page.$$eval('[data-new-menu], [data-newpost]', (e) => e.length), 0, 'no admin chrome');
      await page.selectOption('#uiSheet [data-comment-slide]', '3');
      await page.fill('#uiSheet [data-comment-input]', 'Slide three is too dark');
      await page.click('#uiSheet [data-comment-send]');
      await page.waitForSelector('#uiSheet [data-thread] .pd-slide-chip');
      expect.eq((await page.textContent('#uiSheet [data-thread] .pd-msg:last-child .pd-slide-chip')).trim(), 'Slide 3');
      expect((await page.textContent('#uiSheet [data-thread] .pd-msg:last-child .ui-bubble')).indexOf('[Slide') === -1, 'prefix hidden');
      await page.click('#uiSheet [data-thread] .pd-msg:last-child .pd-slide-chip');
      await page.waitForFunction(() => document.querySelector('#uiSheet [data-carousel-counter]').textContent === '3 / 3');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'] });

  await run('newpost', async ({ test, url, expect, viewport }) => {
    if (viewport === 'desktop') {
      await test('entry points: + New, Home, ?newpost=1, old Studio tab=compose, add-post.php', async (page) => {
        await page.goto(url('posts.php?client=kenda'));
        await page.click('[data-new-menu-toggle]');
        await page.click('[data-new-action="post"]');
        await page.waitForSelector(sel.root);
        expect.eq(await page.textContent('[data-np-title]'), 'New post');
        expect((await page.getAttribute('.np-panel', 'role')) === 'dialog' && (await page.getAttribute('.np-panel', 'aria-modal')) === 'true', 'ARIA dialog');
        await page.keyboard.press('Escape');
        await page.waitForSelector('.np-root', { state: 'detached' });
        expect.eq(await page.$$eval('.ui-nav [data-newpost]', (e) => e.length), 0, 'Posts header: only the global + New');
        for (const p of ['?client=kenda']) {   // Home: its own New post tile is gone — "+ New" is the one entry point there too
          await page.goto(url(p));
          expect.eq(await page.$$eval('main [data-newpost]', (e) => e.length), 0, 'Home: no duplicate New post tile');
          await page.click('[data-new-menu-toggle]');
          await page.click('[data-new-action="post"]');
          await page.waitForSelector(sel.root);
          await page.keyboard.press('Escape');
          await page.waitForSelector('.np-root', { state: 'detached' });
        }
        for (const p of ['posts.php?client=kenda&newpost=1', 'studio.php?client=kenda&tab=compose', 'add-post.php?client=kenda', 'batch.php?client=kenda']) {
          await page.goto(url(p));
          if (p.indexOf('batch') !== -1) { expect(/posts\.php\?client=kenda/.test(page.url()), 'batch → Posts: ' + page.url()); await page.waitForSelector('.us-root'); continue; }   // the Upload sheet (a draft post per file)
          await page.waitForSelector(sel.root);
          expect(!/newpost=/.test(page.url()), 'param stripped: ' + page.url());
          await page.keyboard.press('Escape');
        }
      });

      await test('unscoped + New → client chooser → that client\'s approved images', async (page) => {
        await page.goto(url(''));
        await page.click('[data-new-menu-toggle]');
        await page.click('[data-new-action="post"]');
        await page.waitForSelector('[data-np-pick-client="kenda"]');
        await page.click('[data-np-pick-client="kenda"]');
        await page.waitForSelector(sel.tile);
        expect((await page.textContent('[data-np-client]')).indexOf('Kenda') !== -1, 'scoped to Kenda');
      });

      await test('T2: 5-slide carousel across two series in ≤ 8 clicks → Send for review, the row appears in To Review (no reload)', async (page) => {
        let clicks = 0;
        const tap = async (s) => { clicks++; await page.click(s); };
        // status=pending: the admin opens on Needs changes when it has items (round 4); this test watches To Review
        await page.goto(url('posts.php?client=kenda&month=all&status=pending'));
        await mark(page);
        const before = { pending: await segCount(page, 'pending'), draft: await segCount(page, 'draft') };
        await tap('[data-new-menu-toggle]'); await tap('[data-new-action="post"]');   // "+ New → New post" (Posts has no second button)
        await page.waitForSelector(sel.tile);
        const r = await seriesRefs(page);
        const picks = [r.s1[0], r.s1[1], r.s1[2], r.s2[0], r.s2[1]];
        for (const ref of picks) await tap(`[data-np-grid] [data-np-ref="${ref}"]`);
        expect.eq((await trayRefs(page)).join(','), picks.join(','), 'tap order = slide order');
        expect.eq(await page.textContent('[data-np-count]'), '5 / 20');
        expect.eq(await page.textContent('[data-np-foot-count]'), '5 slides');
        expect.eq(await page.textContent(sel.slide + ':first-child .np-slide-badge'), 'Cover');
        expect.eq(await page.$$eval('[data-np-preview-media] .pd-slide', (e) => e.length), 5, 'live preview carousel');
        expect((await page.textContent('[data-np-format]')).indexOf('Carousel') === 0, 'auto type Carousel');
        expect(await captionFocused(page), 'the caption took the focus after the picks (no click)');
        await page.keyboard.type('Five angles of the Klever AT2.');
        await page.waitForTimeout(400);
        await page.screenshot({ path: path.join(SHOTS, 'newpost-1440-light.png') });
        await tap('[data-np-save="review"]');
        await page.waitForSelector('.np-root', { state: 'detached' });
        expect(clicks <= 8, 'clicks: ' + clicks);
        fs.appendFileSync(CLICKS, `carousel5 ${clicks}\n`);
        await page.waitForFunction(() => /[?&]post=\d+/.test(location.search));   // the list refreshed, then the sheet opened
        const id = await page.evaluate(() => parseInt(new URL(location.href).searchParams.get('post') || '0', 10));
        expect(id > 0, 'the sheet is on the new post: ' + page.url());
        await page.waitForSelector('#uiSheet.is-open .pd[data-post-detail="' + id + '"]');
        expect(await stillSamePage(page), 'no reload');
        expect.eq(await page.$$eval('#uiSheet .pd-slide', (e) => e.length), 5);
        expect.eq(await page.getAttribute('#uiSheet .pd[data-post-detail]', 'data-status'), 'pending');
        await page.waitForSelector(`[data-posts-items] [data-post-item="${id}"]`);
        expect.eq(await segCount(page, 'pending'), before.pending + 1, 'To Review count');
        expect.eq(await segCount(page, 'draft'), before.draft, 'Draft count');
        const saved = await load(page, url, id);
        expect.eq(saved.slides.length, 5);
        expect.eq(saved.post.status, 'pending');
      });

      await test('T1: 1-image post in ≤ 4 clicks; a fresh-upload draft lands in Draft (counts, toast, no reload)', async (page) => {
        let clicks = 0;
        const tap = async (s) => { clicks++; await page.click(s); };
        await page.goto(url('posts.php?client=kenda&month=all'));
        await mark(page);
        const pending0 = await segCount(page, 'pending'), draft0 = await segCount(page, 'draft');
        await tap('[data-new-menu-toggle]'); await tap('[data-new-action="post"]');   // "+ New → New post" (Posts has no second button)
        await page.waitForSelector(sel.tile);
        const r = await seriesRefs(page);
        await tap(`[data-np-grid] [data-np-ref="${r.s1[0]}"]`);
        expect(await captionFocused(page), 'the caption took the focus after the first pick (no click)');
        await page.keyboard.type('One render');
        await tap('[data-np-save="review"]');
        await page.waitForSelector('.np-root', { state: 'detached' });
        fs.appendFileSync(CLICKS, `single ${clicks}\n`);
        expect(clicks <= 4, 'single: ' + clicks);   // the audit's T1 target
        await page.waitForSelector('#uiSheet.is-open .pd[data-status="pending"]');
        expect.eq(await page.getAttribute('#uiSheet .pd[data-post-detail]', 'data-status'), 'pending', 'sent for review');
        expect(await stillSamePage(page), 'no reload');
        expect.eq(await segCount(page, 'pending'), pending0 + 1, 'To Review +1');

        clicks = 0;
        await page.keyboard.press('Escape');
        await tap('[data-new-menu-toggle]'); await tap('[data-new-action="post"]');
        await page.waitForSelector(sel.root);
        await tap('[data-np-source] [data-value="upload"]');
        const [chooser] = await Promise.all([page.waitForEvent('filechooser'), tap('[data-np-drop]')]);
        await chooser.setFiles(square);
        await page.waitForFunction(() => App.newPost._state().slides.length === 1 && /^upload:/.test(App.newPost._state().slides[0].ref || ''));
        await tap('[data-np-save="draft"]');
        await page.waitForSelector('.np-root', { state: 'detached' });
        fs.appendFileSync(CLICKS, `upload ${clicks}\n`);
        expect(clicks <= 5, 'upload: ' + clicks);
        await page.waitForSelector('#uiSheet.is-open .pd[data-status="draft"]');
        expect.eq(await page.$$eval('#uiSheet .pd-slide', (e) => e.length), 1);
        expect(await stillSamePage(page), 'still no reload');
        expect.eq(await segCount(page, 'draft'), draft0 + 1, 'Draft +1 (the list shows To Review)');
        await page.waitForFunction(() => /in Draft/.test((document.querySelector('.ui-toast') || {}).textContent || ''));
        expect((await page.getAttribute('.ui-toast .ui-toast-link', 'href')).indexOf('post=') !== -1, 'toast links to the post in Draft');
      });

      await test('T2 with filters: series chips show directly (one tap = tire + series) — ≤ 10 clicks', async (page) => {
        let clicks = 0;
        const tap = async (s) => { clicks++; await page.click(s); };
        await page.goto(url('posts.php?client=kenda&month=all'));
        await tap('[data-new-menu-toggle]'); await tap('[data-new-action="post"]');
        await page.waitForSelector(sel.tile);
        expect(await page.isVisible('[data-np-series] [data-key="1:1"]'), 'series chips without picking a tire first');
        await tap('[data-np-series] [data-key="1:1"]');
        await page.waitForFunction(() => App.newPost._state().items.length && App.newPost._state().items.every((i) => i.group === 'tire:1' && i.series === '1'));
        expect.eq(await page.getAttribute('[data-np-groups] [data-np-chip="tire"][data-id="1"]', 'aria-pressed'), 'true', 'its tire chip reads pressed');
        const s1 = await page.evaluate(() => App.newPost._state().items.map((i) => i.ref));
        for (const ref of s1.slice(0, 3)) await tap(`[data-np-grid] [data-np-ref="${ref}"]`);
        await tap('[data-np-series] [data-key="1:2"]');
        await page.waitForFunction(() => App.newPost._state().items.some((i) => i.series === '2'));
        const s2 = await page.evaluate(() => App.newPost._state().items.filter((i) => i.series === '2').map((i) => i.ref));
        for (const ref of s2.slice(0, 2)) await tap(`[data-np-grid] [data-np-ref="${ref}"]`);
        expect.eq((await trayRefs(page)).length, 5);
        expect(await captionFocused(page), 'caption focused after chips + picks');
        await page.keyboard.type('Two series, chips on.');
        await tap('[data-np-save="review"]');
        await page.waitForSelector('#uiSheet.is-open .pd[data-status="pending"]');
        fs.appendFileSync(CLICKS, `carousel5-chips ${clicks}\n`);
        expect(clicks <= 10, 'with chips: ' + clicks);
      });

      await test('drag to reorder, keyboard reorder, slide menu, shape warning from an upload', async (page) => {
        await openFromPosts(page, url);
        const r = await seriesRefs(page);
        for (const ref of [r.s1[0], r.s1[1], r.s2[0]]) await page.click(`[data-np-grid] [data-np-ref="${ref}"]`);
        // drag slide 3 onto slide 1
        const a = await page.$(sel.slide + ':nth-child(3)'), b = await page.$(sel.slide + ':nth-child(1)');
        const ba = await a.boundingBox(), bb = await b.boundingBox();
        await page.mouse.move(ba.x + ba.width / 2, ba.y + ba.height / 2);
        await page.mouse.down();
        await page.mouse.move(ba.x + ba.width / 2 - 20, ba.y + ba.height / 2, { steps: 4 });
        await page.mouse.move(bb.x + 5, bb.y + bb.height / 2, { steps: 8 });
        await page.mouse.up();
        expect.eq((await trayRefs(page)).join(','), [r.s2[0], r.s1[0], r.s1[1]].join(','), 'dragged to the front');
        // keyboard: focus slide 1, Alt+→ moves it to 2
        await page.focus(sel.slide + ':nth-child(1)');
        await page.keyboard.press('Alt+ArrowRight');
        expect.eq((await trayRefs(page)).join(','), [r.s1[0], r.s2[0], r.s1[1]].join(','), 'Alt+→');
        // tap → menu → Make cover
        await page.click(sel.slide + ':nth-child(3)');
        await page.waitForSelector('[data-np-menu]:not([hidden])');
        await page.click('[data-np-menu-act="cover"]');
        expect.eq((await trayRefs(page))[0], r.s1[1], 'Make cover');
        // shape warning: a 4:5 upload next to square renders
        await page.click('[data-np-source] [data-value="upload"]');
        await page.setInputFiles('[data-np-file]', portrait);
        await page.waitForFunction(() => App.newPost._state().slides.length === 4 && !App.newPost._state().slides[3].uploading);
        await page.waitForSelector(sel.slide + ':nth-child(4).is-shape-off .np-slide-warn');
        expect.eq(await page.$$eval(sel.slide + '.is-shape-off', (e) => e.length), 1, 'only the odd one');
        expect((await page.textContent('[data-np-tray-hint]')).indexOf('different shape') !== -1, 'tray hint names it');
        // × removes
        await page.click(sel.slide + ':nth-child(4) [data-np-remove]');
        expect.eq((await trayRefs(page)).length, 3);
        await page.click('[data-np-source] [data-value="approved"]');
        await page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'dark'); });
        await page.fill('#npCaption', 'Trail-ready. Three angles of the Klever AT2 — swipe through.');
        await page.waitForTimeout(400);
        await page.screenshot({ path: path.join(SHOTS, 'newpost-1440-dark.png') });
      });

      await test('Send for review needs a caption + a slide (inline errors, nothing sent)', async (page) => {
        await openFromPosts(page, url);
        let posted = 0;
        page.on('request', (q) => { if (q.method() === 'POST' && /post-compose\.php/.test(q.url())) posted++; });
        await page.click('[data-np-save="review"]');
        expect((await page.textContent('[data-np-foot-error]')).length > 0, 'footer error');
        expect(await page.$eval('[data-np-tray]', (e) => e.classList.contains('is-invalid')), 'tray flagged');
        const r = await seriesRefs(page);
        await page.click(`[data-np-grid] [data-np-ref="${r.s1[0]}"]`);
        await page.click('[data-np-save="review"]');
        expect.eq(await page.getAttribute('#npCaption', 'aria-invalid'), 'true', 'caption flagged');
        expect(await page.isVisible('[data-np-err="caption"]'), 'caption message');
        expect.eq(posted, 0, 'no request');
      });

      await test('unsaved changes: Esc / × ask first; Keep editing / Discard; focus stays inside', async (page) => {
        await openFromPosts(page, url);
        const r = await seriesRefs(page);
        await page.click(`[data-np-grid] [data-np-ref="${r.s1[0]}"]`);
        await page.keyboard.press('Escape');
        await page.waitForSelector('[data-np-confirm]:not([hidden])');
        await page.click('[data-np-keep]');
        expect(await page.isVisible(sel.root), 'still open');
        for (let i = 0; i < 40; i++) await page.keyboard.press('Tab');
        expect(await page.evaluate(() => !!document.activeElement.closest('.np-panel')), 'focus trapped');
        await page.click('[data-np-close]');
        await page.waitForSelector('[data-np-confirm]:not([hidden])');
        await page.click('[data-np-discard]');
        await page.waitForSelector('.np-root', { state: 'detached' });
      });

      await test('edit: Edit post… → reorder + add → Save changes → saved order, the row refreshes in place', async (page) => {
        await page.goto(url('posts.php?client=kenda&post=2'));
        await page.waitForSelector('#uiSheet.is-open [data-menu-toggle]');
        await mark(page);
        const before = await load(page, url, 2);
        const pending0 = await segCount(page, 'pending');
        await page.click('#uiSheet [data-actions] [data-newpost-edit="2"]:visible');   // the To Review primary
        await page.waitForSelector(sel.root);
        await page.waitForFunction(() => App.newPost._state().slides.length === 3);
        expect.eq(await page.textContent('[data-np-title]'), 'Edit post');
        await page.focus(sel.slide + ':nth-child(3)');
        await page.keyboard.press('Alt+ArrowLeft');
        await page.keyboard.press('Alt+ArrowLeft');      // C, A, B
        await page.waitForSelector(sel.tile);
        const r = await seriesRefs(page);
        await page.click(`[data-np-grid] [data-np-ref="${r.s2[2]}"]`);
        await page.click('[data-np-save="keep"]');
        await page.waitForSelector('.np-root', { state: 'detached' });
        await page.waitForFunction(() => document.querySelectorAll('#uiSheet .pd-slide').length === 4);
        expect(await stillSamePage(page), 'no reload');
        expect.eq((await page.textContent('[data-post-item="2"] .pl-meta')).replace(/\s+/g, ' ').indexOf('4 media') !== -1, true, 'row says 4 media');
        expect.eq(await segCount(page, 'pending'), pending0, 'counts unchanged');
        const after = await load(page, url, 2);
        const ids = (x) => x.slides.map((s) => s.ref);
        expect.eq(ids(after).slice(0, 3).join(','), [ids(before)[2], ids(before)[0], ids(before)[1]].join(','), 'saved order');
        expect.eq(after.slides.length, 4);
      });

      await test('Needs changes: note on top → Edit & resubmit → Send for review — row leaves the queue, counts move, no reload', async (page) => {
        await page.goto(url('posts.php?client=kenda&post=4'));
        await page.waitForSelector('#uiSheet.is-open .pd[data-status="denied"]');
        await mark(page);
        const q0 = await segCount(page, 'denied'), p0 = await segCount(page, 'pending');
        const noteY = await page.$eval('#uiSheet [data-pd-note]', (e) => e.getBoundingClientRect().top);
        const mediaY = await page.$eval('#uiSheet [data-carousel]', (e) => e.getBoundingClientRect().top);
        expect(noteY < mediaY, 'the client note sits above the media');
        expect((await page.textContent('#uiSheet [data-pd-note]')).indexOf('Please use the darker render') !== -1, 'the note');
        expect.eq((await page.textContent('#uiSheet [data-actions] [data-newpost-resubmit]')).trim(), 'Edit & resubmit');
        await page.click('#uiSheet [data-actions] [data-newpost-resubmit]');
        await page.waitForSelector(sel.root);
        await page.waitForSelector('[data-np-note]:not([hidden])');
        expect((await page.textContent('[data-np-note]')).indexOf('Please use the darker render') !== -1, 'the note above the tray');
        expect.eq((await page.textContent('[data-np-save="review"]')).trim(), 'Send for review');
        await page.waitForSelector(sel.tile);
        const r = await seriesRefs(page);
        await page.click(sel.slide + ':first-child [data-np-remove]');
        await page.click(`[data-np-grid] [data-np-ref="${r.s1[3]}"]`);
        await page.click('[data-np-save="review"]');
        await page.waitForSelector('.np-root', { state: 'detached' });
        await page.waitForSelector('#uiSheet.is-open .pd[data-post-detail="4"][data-status="pending"]');
        expect(await stillSamePage(page), 'no reload');
        await page.waitForSelector('[data-post-item="4"]', { state: 'detached' });
        expect.eq(await segCount(page, 'denied'), q0 - 1, 'Needs changes −1');
        expect.eq(await segCount(page, 'pending'), p0 + 1, 'To Review +1');
        expect(await page.isVisible('#uiSheet [data-state="admin-pending"]'), 'sheet: the To Review primary');
        expect(!(await page.isVisible('#uiSheet [data-pd-note]')), 'note folded away');
        expect.eq((await load(page, url, 4)).post.status, 'pending');
      });

      await test('Assets: select approved → "Create post with 2"; viewer "Use in post"', async (page) => {
        await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved'));
        await page.click('[data-assets-select]');
        const tiles = await page.$$('[data-asset][data-status="approved"]');
        await tiles[0].click(); await tiles[1].click();
        expect.eq((await page.textContent('[data-select-post]')).trim(), 'Create post with 2');
        await page.click('[data-select-post]');
        await page.waitForSelector(sel.root);
        await page.waitForFunction(() => App.newPost._state().slides.length === 2);
        await page.click('[data-np-close]');
        await page.click('[data-np-discard]');
        await page.waitForSelector('.np-root', { state: 'detached' });
        await page.click('[data-asset][data-status="approved"]');
        await page.waitForSelector('[data-viewer-more]');
        await page.click('[data-viewer-more]');
        await page.click('[data-viewer-use-in-post]');
        await page.waitForSelector(sel.root);
        await page.waitForFunction(() => App.newPost._state().slides.length === 1);
      });
    }

    if (viewport === 'phone') {
      await test('phone: full screen, Media → Details step switch, sticky footer', async (page) => {
        await page.goto(url('posts.php?client=kenda'));
        expect(!(await page.isVisible('[data-newpost]')), 'phones use "+ New" (no duplicate header button)');
        await page.click('[data-new-menu-toggle]');
        await page.click('[data-new-action="post"]');
        await page.waitForSelector(sel.tile);
        await page.waitForTimeout(450);   // the open transition
        const box = await page.$eval('.np-panel', (e) => { const r = e.getBoundingClientRect(); return [r.x, r.y, r.width, r.height]; });
        expect.eq(box.map(Math.round).join(','), [0, 0, 390, 844].join(','), 'full screen');
        expect(await page.isVisible('[data-np-steps]'), 'step switch');
        expect(await page.isVisible('[data-np-pane="media"]') && !(await page.isVisible('[data-np-pane="details"]')), 'Media first');
        const r = await seriesRefs(page);
        await page.click(`[data-np-grid] [data-np-ref="${r.s1[0]}"]`);
        await page.click(`[data-np-grid] [data-np-ref="${r.s2[0]}"]`);
        const foot = await page.$eval('[data-np-foot]', (e) => e.getBoundingClientRect().bottom);
        expect(Math.abs(foot - 844) < 2, 'footer pinned to the bottom: ' + foot);
        await page.click('[data-np-steps] [data-value="details"]');
        expect(await page.isVisible('[data-np-pane="details"]') && !(await page.isVisible('[data-np-pane="media"]')), 'Details');
        expect(await captionFocused(page), 'Details on a phone: the caption has the focus (no tap)');
        await page.keyboard.type('Two angles, one tire.');
        await page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'light'); });
        await page.waitForTimeout(300);
        await page.screenshot({ path: path.join(SHOTS, 'newpost-390-light.png') });
        await page.click('[data-np-steps] [data-value="media"]');
        await page.waitForTimeout(200);
        await page.screenshot({ path: path.join(SHOTS, 'newpost-390-light-media.png') });
      });

      await test('phone T1: + New → New post → tile → Details → (caption focused) → Send for review = 5 taps, no reload', async (page) => {
        let clicks = 0;
        const tap = async (s) => { clicks++; await page.click(s); };
        await page.goto(url('posts.php?client=kenda&month=all'));
        await mark(page);
        await tap('[data-new-menu-toggle]'); await tap('[data-new-action="post"]');
        await page.waitForSelector(sel.tile);
        const r = await seriesRefs(page);
        await tap(`[data-np-grid] [data-np-ref="${r.s1[0]}"]`);
        await tap('[data-np-steps] [data-value="details"]');
        expect(await captionFocused(page), 'caption focused on Details');
        await page.keyboard.type('One render, phone');
        await tap('[data-np-save="review"]');
        await page.waitForSelector('#uiSheet.is-open .pd[data-status="pending"]');
        fs.appendFileSync(CLICKS, `phone-single ${clicks}\n`);
        expect(clicks <= 5, 'phone single: ' + clicks);
        expect(await stillSamePage(page), 'no reload');
      });
    }
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

})();
