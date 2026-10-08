/* Round-4 UX fixes in the browser: the caption takes the focus after "Upload & make post" and Assets "Create post
   with N" (two panes); the admin's tab badges count Needs changes and move after a decision; the Slide picker starts
   on All slides and follows the carousel once it moves; Approve for client… asks in the sheet (no browser confirm);
   the email / page sheet ⋯ stays on the meta row at 390 / 320; Home reloads its counts after an upload from Home.
   Screens: $PORTAL_TEST_ROOT/shots/r4-*.png */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = path.join(ROOT, 'shots');

function jpeg(name, rgb) {
  const dir = path.join(ROOT, 'upfiles');
  fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, name);
  const [r, g, b] = rgb || [60, 120, 90];
  execFileSync('php', ['-r', `$i=imagecreatetruecolor(640,640);imagefill($i,0,0,imagecolorallocate($i,${r},${g},${b}));imagejpeg($i,'${file}',80);`]);
  return file;
}
const captionFocused = (page) => page.waitForFunction(() => document.activeElement && document.activeElement.getAttribute('data-np-field') === 'caption', null, { timeout: 5000 });
/** The Posts / Emails … tab badge number (0 when there is none). */
const badge = (page, tab) => page.evaluate((t) => { const b = document.querySelector('.ui-tab[data-tab="' + t + '"] .ui-badge'); return b && !b.hidden ? parseInt(b.textContent, 10) : 0; }, tab);
async function openUpload(page) {
  await page.click('[data-new-menu-toggle]');
  await page.click('[data-new-action="upload"]');
  await page.waitForSelector('.us-root.is-visible');
  await page.waitForSelector('[data-us-client] .us-client-name');
}
async function pickDest(page, files, kind) {
  await page.setInputFiles('[data-us-file]', files);
  await page.waitForSelector('[data-us-pane="dest"]:not([hidden])');
  await page.click(`label[for="usDest_${kind}"]`);
  await page.waitForSelector(`[data-us-card="${kind}"].is-checked`);
}

(async () => {
  await run('round4-admin', async ({ test, url, expect, viewport }) => {
    if (viewport === 'desktop') {
      await test('P1-1 Upload & make post: the caption has the focus — typing goes straight in', async (page) => {
        await page.goto(url('posts.php?client=kenda'));
        await openUpload(page);
        await pickDest(page, [jpeg('r4-make-post.jpg')], 'post');
        await page.click('[data-us-act="upload"]');
        await page.waitForSelector('.np-root.is-visible');
        await page.waitForFunction(() => App.newPost.isOpen() && App.newPost._state().slides.length === 1);
        await captionFocused(page);
        await page.waitForTimeout(600);   // nothing steals it back (the old 40 ms panel focus did)
        await captionFocused(page);
        await page.keyboard.type('Typed with no click');
        expect.eq(await page.inputValue('[data-np-field="caption"]'), 'Typed with no click');
      });

      await test('P1-1 Assets "Create post with 2": the caption has the focus — typing goes straight in', async (page) => {
        await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved'));
        await page.click('[data-assets-select]');
        const tiles = await page.$$('[data-asset][data-status="approved"]');
        await tiles[0].click(); await tiles[1].click();
        await page.click('[data-select-post]');
        await page.waitForSelector('.np-root.is-visible');
        await page.waitForFunction(() => App.newPost._state().slides.length === 2);
        await captionFocused(page);
        await page.waitForTimeout(600);
        await captionFocused(page);
        await page.keyboard.type('Two angles');
        expect.eq(await page.inputValue('[data-np-field="caption"]'), 'Two angles');
      });
    }

    await test('P1-2 the admin lands on Needs changes; the Posts badge counts it and moves after a decision', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      expect.eq(await page.getAttribute('[data-posts-list]', 'data-segment'), 'denied', 'opens on Needs changes');
      expect.eq(await badge(page, 'posts'), 1, 'badge = 1 needs changes');
      expect.eq(await page.getAttribute('.ui-tab[data-tab="posts"] .ui-badge', 'aria-label'), '1 need changes');
      // To Review → Needs changes… : Joust's queue grows
      await page.goto(url('posts.php?client=kenda&status=pending&post=1'));
      await page.waitForSelector('#uiSheet.is-open .pd[data-status="pending"]');
      await page.click('#uiSheet [data-menu-toggle]');
      await page.click('#uiSheet [data-menu] [data-decide="denied"]');
      await page.fill('#uiSheet [data-deny-note]', 'Client asked by phone');
      await page.click('#uiSheet [data-deny-submit]');
      await page.waitForSelector('#uiSheet .pd[data-status="denied"]');
      await page.waitForFunction(() => { const b = document.querySelector('.ui-tab[data-tab="posts"] .ui-badge'); return b && b.textContent === '2'; }, null, { timeout: 4000 });
      // Needs changes → Send for review (twice): the queue empties and the badge goes
      for (const id of [1, 4]) {
        await page.goto(url(`posts.php?client=kenda&status=denied&post=${id}`));
        await page.waitForSelector(`#uiSheet.is-open .pd[data-post-detail="${id}"][data-status="denied"]`);
        const n0 = await badge(page, 'posts');
        await page.click('#uiSheet [data-menu-toggle]');
        await page.click('#uiSheet [data-menu] [data-decide="pending"]');
        await page.waitForSelector('#uiSheet .pd[data-status="pending"]');
        await page.waitForFunction((n) => { const b = document.querySelector('.ui-tab[data-tab="posts"] .ui-badge'); return (b && !b.hidden ? parseInt(b.textContent, 10) : 0) === n; }, n0 - 1, { timeout: 4000 });
      }
      expect.eq(await badge(page, 'posts'), 0, 'no badge once the queue is clear');
      await page.goto(url('posts.php?client=kenda'));
      expect.eq(await page.getAttribute('[data-posts-list]', 'data-segment'), 'pending', 'nothing needs changes → To Review');
    });

    await test('P2 Approve for client… (page): asks in the sheet, not with a browser confirm', async (page) => {
      const dialogs = [];
      page.on('dialog', (d) => dialogs.push(d.message()));
      await page.goto(url('pages.php?client=privacybee&page=1'));
      await page.waitForSelector('#uiSheet.is-open .pg[data-status="pending"]');
      await page.click('#uiSheet [data-asg-menu-toggle]');
      await page.click('#uiSheet [data-approve-for-client]');
      await page.waitForSelector('#uiSheet [data-confirm-inline="approve"]');
      const t = await page.textContent('#uiSheet [data-confirm-inline]');
      expect(/Approve this page for Privacy Bee\?/.test(t) && /won’t be asked/.test(t), t);
      expect.eq(await page.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-confirm-ok')), true, 'the confirm has the focus');
      await page.screenshot({ path: path.join(SHOTS, `r4-approve-confirm-${viewport}.png`) });
      await page.keyboard.press('Escape');
      await page.waitForSelector('#uiSheet [data-confirm-inline]', { state: 'detached' });
      expect(await page.isVisible('#uiSheet.is-open'), 'Escape closes only the confirm');
      await page.click('#uiSheet [data-asg-menu-toggle]');
      await page.click('#uiSheet [data-approve-for-client]');
      await page.click('#uiSheet [data-confirm-inline] [data-confirm-ok]');
      await page.waitForSelector('#uiSheet .pg[data-status="approved"]');
      expect.eq(dialogs.length, 0, 'no browser dialog: ' + dialogs.join(' / '));
    });

    if (viewport !== 'desktop') {
      await test('P2 email / page sheet: ⋯ sits on the meta row (no row of its own)', async (page) => {
        for (const u of ['emails.php?client=privacybee&email=4', 'emails.php?client=privacybee&email=2', 'pages.php?client=privacybee&page=1']) {
          await page.goto(url(u));
          await page.waitForSelector('#uiSheet.is-open .pd-meta [data-asg-menu-toggle]');
          const g = await page.evaluate(() => {
            const head = document.querySelector('#uiSheet .pd-meta'), pill = head.querySelector('.pd-pill'), more = head.querySelector('[data-asg-menu-toggle]');
            const a = pill.getBoundingClientRect(), b = more.getBoundingClientRect();
            return { dy: Math.abs((a.top + a.bottom) / 2 - (b.top + b.bottom) / 2), h: head.getBoundingClientRect().height, right: b.right, vw: window.innerWidth };
          });
          expect(g.dy < 4, `${u}: ⋯ on the pill's row (dy ${g.dy})`);
          expect(g.h < 50, `${u}: one row (${g.h}px)`);
          expect(g.right <= g.vw, `${u}: on screen`);
        }
        await page.screenshot({ path: path.join(SHOTS, `r4-page-head-${viewport}.png`) });
      });
    }

    if (viewport === 'desktop') {
      await test('P2 Home: an upload started from Home reloads the Home counts (and keeps the toast)', async (page) => {
        await page.goto(url('index.php?client=kenda'));
        const libCount = async () => {
          const m = (await page.evaluate(() => document.body.innerText)).match(/(\d+) images? waiting for their review\s*Assets · Library/);
          return m ? parseInt(m[1], 10) : -1;
        };
        const before = await libCount();
        expect(before > 0, 'Home shows the Library count: ' + before);
        await openUpload(page);
        await pickDest(page, [jpeg('r4-home.jpg', [90, 40, 140])], 'library');
        await Promise.all([page.waitForNavigation({ timeout: 30000 }), page.click('[data-us-act="upload"]')]);
        await page.waitForFunction(() => { const t = document.getElementById('uiToast'); return t && t.classList.contains('is-visible') && /uploaded to/.test(t.textContent); }, null, { timeout: 8000 });
        expect(/index\.php\?client=kenda/.test(page.url()), 'still on Home: ' + page.url());
        expect.eq(await libCount(), before + 1, 'the Home card counts the new file');
        expect(await page.isVisible('#uiToast a'), 'the toast keeps its View link');
      });
    }

    await test('P2 the email / page forms sit in the 720 px column', async (page) => {
      for (const u of ['add-email.php?client=privacybee&edit=2', 'add-page.php?client=privacybee&edit=1']) {
        await page.goto(url(u));
        const w = await page.evaluate(() => document.querySelector('main.ui-page').getBoundingClientRect().width);
        expect(w <= 720, `${u}: ${w}px`);
        expect.eq(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, u + ': no sideways scroll');
      }
    });
  }, { role: 'admin', viewports: ['desktop', 'phone', 'w320'], reseed: 'test' });

  await run('round4-client', async ({ test, url, expect }) => {
    await test('P1-3 the Slide picker starts on All slides; a general comment is untagged; it follows the carousel once moved', async (page) => {
      await page.goto(url('posts.php?client=kenda&post=2'));
      await page.waitForSelector('#uiSheet.is-open [data-carousel]');
      await page.waitForTimeout(300);   // carousel init / resize events fire for slide 1 — they must not tag anything
      expect.eq(await page.inputValue('#uiSheet [data-comment-slide]'), '', 'All slides on open');
      await page.fill('#uiSheet [data-comment-input]', 'Love the set overall');
      await page.click('#uiSheet [data-comment-send]');
      await page.waitForFunction(() => document.querySelectorAll('#uiSheet [data-thread] .pd-msg').length === 1);
      expect.eq(await page.$$eval('#uiSheet [data-thread] .pd-msg .pd-slide-chip', (e) => e.length), 0, 'general comment: no Slide chip');
      expect.eq(await page.inputValue('#uiSheet [data-comment-slide]'), '', 'still All slides after sending');
      await page.evaluate(() => App.carousel.go(document.querySelector('#uiSheet [data-carousel]'), 1, false));
      await page.waitForFunction(() => document.querySelector('#uiSheet [data-comment-slide]').value === '2', null, { timeout: 3000 });
      await page.evaluate(() => App.carousel.go(document.querySelector('#uiSheet [data-carousel]'), 0, false));
      await page.waitForFunction(() => document.querySelector('#uiSheet [data-comment-slide]').value === '1', null, { timeout: 3000 });
    });

    await test('P2 the client\'s Needs-changes notice shows Joust\'s reply', async (page) => {
      // Joust replies on Winter promo (post 4) from the admin seat, then the client follows their Home link
      const code = execFileSync('curl', ['-s', '-o', '/dev/null', '-w', '%{http_code}', '-b', 'portal_test_role=admin',
        '--data-urlencode', 'id=4', '--data-urlencode', 'comment=Darker render coming Friday', '--data-urlencode', 'client=kenda', url('status.php')]).toString();
      expect.eq(code, '200', 'admin comment');
      await page.goto(url('posts.php?client=kenda&post=4'));
      await page.waitForSelector('#uiSheet.is-open [data-sentback-panel]');
      const t = await page.textContent('#uiSheet [data-sentback-panel]');
      expect(/Your note/.test(t) && /Please use the darker render/.test(t), 'my note');
      expect(/Joust replied/.test(t) && /Darker render coming Friday/.test(t), 'Joust\'s reply');
      // the badge also counts unread Joust replies (notif round 3): opening the notice reads the reply, so it settles at To Review
      await page.waitForFunction(() => { const b = document.querySelector('.ui-tab[data-tab="posts"] .ui-tab-badge'); return b && b.textContent.trim() === '2'; }, null, { timeout: 5000 }).catch(() => {});
      expect.eq(await badge(page, 'posts'), 2, 'the client\'s Posts badge counts To Review once the reply is read');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'], reseed: 'test' });
})();
