/* New post pop-up (static/js/newpost.js): every entry point, a 5-slide carousel across two series in ≤ 8
   clicks (the audit's T2 target; the Posts header's own "New post" button is gone — "+ New → New post" is 2 clicks), drag + keyboard reorder, the shape warning, Save draft → the post, edit → reorder → saved order,
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
        for (const p of ['?client=kenda']) {
          await page.goto(url(p));
          await page.click('[data-newpost]');
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

      await test('5-slide carousel across two series in ≤ 8 clicks → Save draft lands on the post', async (page) => {
        let clicks = 0;
        const tap = async (s) => { clicks++; await page.click(s); };
        await page.goto(url('posts.php?client=kenda'));
        await tap('[data-new-menu-toggle]'); await tap('[data-new-action="post"]');   // "+ New → New post" (Posts has no second button)
        await page.waitForSelector(sel.tile);
        const r = await seriesRefs(page);
        const picks = [r.s1[0], r.s1[1], r.s1[2], r.s2[0], r.s2[1]];
        for (const ref of picks) await tap(`[data-np-grid] [data-np-ref="${ref}"]`);
        expect.eq((await trayRefs(page)).join(','), picks.join(','), 'tap order = slide order');
        expect.eq(await page.textContent('[data-np-count]'), '5 / 20');
        expect.eq(await page.textContent('[data-np-foot-count]'), '5 selected');
        expect.eq(await page.textContent(sel.slide + ':first-child .np-slide-badge'), 'Cover');
        expect.eq(await page.$$eval('[data-np-preview-media] .pd-slide', (e) => e.length), 5, 'live preview carousel');
        expect((await page.textContent('[data-np-format]')).indexOf('Carousel') === 0, 'auto type Carousel');
        await page.waitForTimeout(400);
        await page.screenshot({ path: path.join(SHOTS, 'newpost-1440-light.png') });
        await Promise.all([page.waitForNavigation(), tap('[data-np-save="draft"]')]);
        expect(clicks <= 8, 'clicks: ' + clicks);
        fs.appendFileSync(CLICKS, `carousel5 ${clicks}\n`);
        const id = parseInt(new URL(page.url()).searchParams.get('post') || '0', 10);
        expect(id > 0, 'landed on posts.php?post=ID: ' + page.url());
        await page.waitForSelector('#uiSheet.is-open .pd[data-post-detail="' + id + '"]');
        expect.eq(await page.$$eval('#uiSheet .pd-slide', (e) => e.length), 5);
        expect.eq(await page.getAttribute('#uiSheet .pd[data-post-detail]', 'data-status'), 'draft');
        const saved = await load(page, url, id);
        expect.eq(saved.slides.length, 5);
        expect.eq(saved.post.status, 'draft');
      });

      await test('1-image post and a post from a fresh upload (click counts)', async (page) => {
        let clicks = 0;
        const tap = async (s) => { clicks++; await page.click(s); };
        await page.goto(url('posts.php?client=kenda'));
        await tap('[data-new-menu-toggle]'); await tap('[data-new-action="post"]');   // "+ New → New post" (Posts has no second button)
        await page.waitForSelector(sel.tile);
        const r = await seriesRefs(page);
        await tap(`[data-np-grid] [data-np-ref="${r.s1[0]}"]`);
        await page.fill('#npCaption', 'One render');
        await Promise.all([page.waitForNavigation(), tap('[data-np-save="review"]')]);
        fs.appendFileSync(CLICKS, `single ${clicks}\n`);
        expect(clicks <= 4, 'single: ' + clicks);   // the audit's T1 target
        expect.eq(await page.getAttribute('#uiSheet .pd[data-post-detail]', 'data-status'), 'pending', 'sent for review');

        clicks = 0;
        await page.goto(url('posts.php?client=kenda'));
        await tap('[data-new-menu-toggle]'); await tap('[data-new-action="post"]');   // "+ New → New post" (Posts has no second button)
        await page.waitForSelector(sel.root);
        await tap('[data-np-source] [data-value="upload"]');
        const [chooser] = await Promise.all([page.waitForEvent('filechooser'), tap('[data-np-drop]')]);
        await chooser.setFiles(square);
        await page.waitForFunction(() => App.newPost._state().slides.length === 1 && /^upload:/.test(App.newPost._state().slides[0].ref || ''));
        await Promise.all([page.waitForNavigation(), tap('[data-np-save="draft"]')]);
        fs.appendFileSync(CLICKS, `upload ${clicks}\n`);
        expect(clicks <= 5, 'upload: ' + clicks);
        expect.eq(await page.$$eval('#uiSheet .pd-slide', (e) => e.length), 1);
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

      await test('edit: ⋯ → Edit post… → reorder + add → Save changes → saved order', async (page) => {
        await page.goto(url('posts.php?client=kenda&post=2'));
        await page.waitForSelector('#uiSheet.is-open [data-menu-toggle]');
        const before = await load(page, url, 2);
        await page.click('#uiSheet [data-menu-toggle]');
        await page.click('#uiSheet [data-newpost-edit="2"]');
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
        const after = await load(page, url, 2);
        const ids = (x) => x.slides.map((s) => s.ref);
        expect.eq(ids(after).slice(0, 3).join(','), [ids(before)[2], ids(before)[0], ids(before)[1]].join(','), 'saved order');
        expect.eq(after.slides.length, 4);
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
        await page.fill('#npCaption', 'Two angles, one tire.');
        await page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'light'); });
        await page.waitForTimeout(300);
        await page.screenshot({ path: path.join(SHOTS, 'newpost-390-light.png') });
        await page.click('[data-np-steps] [data-value="media"]');
        await page.waitForTimeout(200);
        await page.screenshot({ path: path.join(SHOTS, 'newpost-390-light-media.png') });
      });
    }
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

})();
