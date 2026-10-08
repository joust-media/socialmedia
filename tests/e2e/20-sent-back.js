/* The client's "Sent back" in the browser (tests/smoke/25-sent-back.php covers the server side):
   - the Sent back segment on Posts / Emails / Pages and the chip on Assets (Library) / Tires: rows with the note, when,
     "Joust is reworking this" / "Being reworked" and Joust's reply; the count follows decisions; the red tab badges
     never count them
   - the item: the full post with the panel on top; Add a comment focuses the composer and posts through the normal
     path; Approve instead asks first (Cancel keeps it), then approves — the row leaves, the counts move
   - the media viewer over the Assets list: Add a comment opens the Comments panel, Approve instead asks first, then the
     image leaves the list and the Redo queue (the client's own Needs changes queued it)
   - the Home card opens an item
   - phones (390 / 320): the segmented control scrolls with the active Sent back in view, no sideways page scroll, the
     sheet's two buttons fit
   Screenshots (client Posts / Emails / Assets "Sent back", the item view, the Home card — 1440 dark, 390 light) go to
   $SENTBACK_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/sentback). */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run, url, reseed } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = process.env.SENTBACK_SHOTS_DIR || path.join(ROOT, 'shots', 'sentback');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name) => path.join(SHOTS, name);

function sql(query, params) {
  const php = `$p=new PDO('mysql:host=localhost;dbname='.getenv('PORTAL_TEST_DB').';charset=utf8mb4',getenv('PORTAL_TEST_DB_USER'),getenv('PORTAL_TEST_DB_PASS'));`
    + `$p->exec("SET time_zone = '".(new DateTime('now', new DateTimeZone('America/New_York')))->format('P')."'");`
    + `$s=$p->prepare($argv[1]);$s->execute(json_decode($argv[2],true));echo json_encode($s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):[]);`;
  const env = Object.assign({ PORTAL_TEST_DB: 'portal_test', PORTAL_TEST_DB_USER: 'portal_test', PORTAL_TEST_DB_PASS: 'portal_test' }, process.env);
  return JSON.parse(execFileSync('php', ['-r', php, query, JSON.stringify(params || [])], { env }).toString() || '[]');
}
/** A POST as a seat (curl; the endpoints' own rules apply). */
function api(role, endpoint, data) {
  const args = ['-s', '-o', '/dev/null', '-w', '%{http_code}', '-b', 'portal_test_role=' + role];
  Object.keys(data).forEach((k) => { args.push('--data-urlencode', k + '=' + data[k]); });
  args.push(url(endpoint));
  return execFileSync('curl', args).toString();
}
/** The fixtures every test starts from: post 4 + email R1 + renders 9 / 17 (seed) and, here, library 8 + page 1 sent back, Joust replies. */
function scene() {
  reseed();
  const must = (code, what) => { if (code !== '200') throw new Error(what + ': HTTP ' + code); };
  must(api('client', 'library-status.php', { id: 8, status: 'denied', comment: 'Too dark — brighten the sky please', client: 'kenda' }), 'deny library 8');
  must(api('client', 'status.php', { id: 2, status: 'denied', comment: '[Slide 2] Crop tighter and warmer please', client: 'kenda' }), 'deny post 2');
  must(api('admin', 'status.php', { id: 4, comment: 'Darker render coming Friday', client: 'kenda' }), 'reply post 4');
  must(api('admin', 'status.php', { id: 4, comment: 'INTERNAL e2e secret', internal: 1, client: 'kenda' }), 'internal note');
  must(api('client', 'page-status.php', { id: 1, status: 'denied', comment: 'Headline should say Spring', client: 'privacybee' }), 'deny page 1');
  must(api('admin', 'email-status.php', { id: 4, comment: 'New subject line on the way', client: 'privacybee' }), 'reply email 4');
}
const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
const toastIs = (page, re) => page.waitForFunction((src) => new RegExp(src).test((document.getElementById('uiToast') || {}).textContent || ''), re.source, { timeout: 6000 });
const noSideways = (page) => page.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const segCount = (page, seg) => page.$eval(`.ui-segmented-item[data-segment="${seg}"] .ui-segmented-count`, (e) => parseInt(e.textContent, 10));
async function sheetOpen(page) {
  await page.waitForSelector('#uiSheet.is-open [data-pd-body]');
  await page.waitForTimeout(450);   // the slide-in settles
}

(async () => {
  // -------------------------------------------------------------------------------------------------------------------
  await run('sent back: posts, emails, pages (client)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390', mode = viewport === 'desktop' ? 'dark' : 'light';
    await theme(ctx, mode);

    await test('Posts → Sent back: the rows say what was sent back, by whom, what Joust is doing and replied', async (page) => {
      scene();
      await page.goto(url('posts.php?client=kenda'));
      const seg = '.ui-segmented-item[data-segment="denied"]';
      expect.eq((await page.textContent(seg)).replace(/\s+/g, ' ').trim(), 'Sent back 2', 'segment + count');
      await page.click(seg);
      await page.waitForSelector('[data-posts-list][data-segment="denied"]');
      const rows = await page.$$eval('[data-posts-items] > [data-post-item]', (els) => els.map((e) => e.getAttribute('data-id')));
      expect.eq(rows.join(','), '2,4', 'newest sent back first');
      const t = await page.textContent('#post-4');
      expect(/Please use the darker render/.test(t) && /Joust is reworking this/.test(t) && /Darker render coming Friday/.test(t), 'note + status + reply');
      expect(!/INTERNAL e2e secret/.test(await page.content()), 'never the internal note');
      expect(!(await page.$('#post-4[data-swipe]')), 'no swipe decisions');
      expect(await noSideways(page), 'no sideways scroll');
      if (viewport !== 'desktop') {
        const inView = await page.$eval(seg, (el) => { const c = el.closest('.ui-segmented'); const a = el.getBoundingClientRect(), b = c.getBoundingClientRect(); return a.left >= b.left - 1 && a.right <= b.right + 1; });
        expect(inView, 'the active Sent back segment is scrolled into view');
      }
      // the red tab badge counts To Review (+ unread replies) — never Sent back
      const badge = await page.getAttribute('.ui-tab[data-tab="posts"]', 'data-badge-review');
      expect.eq(badge, String(sql("SELECT COUNT(*) AS n FROM posts WHERE company_id = 1 AND status = 'pending'")[0].n), 'badge = To Review');
      await page.screenshot({ path: shot(`posts-sentback-${w}-${mode}.png`) });
    });

    await test('the item: full post + Sent back panel; Add a comment focuses the composer and sends; Approve instead asks, Cancel keeps it', async (page) => {
      scene();
      await page.goto(url('posts.php?client=kenda&status=denied&month=all'));
      await page.click('#post-4 [data-post-open]');
      await sheetOpen(page);
      for (const sel of ['[data-sentback-panel]', '[data-hidden-note]', '[data-hidden-reply]', '[data-carousel]', '[data-comment-form]', '[data-approve-instead]', '[data-sentback-comment]']) {
        expect(await page.isVisible('#uiSheet ' + sel), sel + ' visible');
      }
      expect(!(await page.isVisible('#uiSheet [data-decide="approved"]')), 'not the To Review pair');
      expect.eq((await page.textContent('#uiSheet .pd-meta [data-status-pill]')).trim(), 'Sent back', 'pill wording');
      expect(await noSideways(page), 'no sideways scroll');
      await page.screenshot({ path: shot(`post-item-${w}-${mode}.png`) });
      // Add a comment → the composer, the normal comment path
      await page.click('#uiSheet [data-sentback-comment]');
      expect(await page.evaluate(() => document.activeElement && document.activeElement.matches('[data-comment-input]')), 'composer focused');
      await page.fill('#uiSheet [data-comment-input]', 'Also lose the snowflakes');
      await page.click('#uiSheet [data-comment-send]');
      await page.waitForFunction(() => /Also lose the snowflakes/.test(document.querySelector('#uiSheet .pd-comments').textContent));
      expect.eq(sql("SELECT detail FROM activity_log WHERE entity_type = 'post' AND entity_id = 4 AND action = 'commented' ORDER BY id DESC LIMIT 1")[0].detail, 'Also lose the snowflakes');
      expect.eq(sql('SELECT status FROM posts WHERE id = 4')[0].status, 'denied', 'still sent back');
      // Approve instead: asks first; Cancel keeps it
      await page.click('#uiSheet [data-approve-instead]');
      await page.waitForSelector('#uiSheet [data-confirm-inline="approve-instead"]');
      expect(/Approve this post instead\?/.test(await page.textContent('#uiSheet [data-confirm-inline]')), 'the question');
      await page.click('#uiSheet [data-confirm-inline] [data-confirm-cancel]');
      expect.eq(sql('SELECT status FROM posts WHERE id = 4')[0].status, 'denied', 'Cancel: unchanged');
    });

    await test('Approve instead: confirmed → approved, the row leaves Sent back, the counts move, the sheet shows Approved', async (page) => {
      scene();
      await page.goto(url('posts.php?client=kenda&post=4'));
      await sheetOpen(page);
      const before = await segCount(page, 'denied');
      await page.click('#uiSheet [data-approve-instead]');
      await page.waitForSelector('#uiSheet [data-confirm-inline="approve-instead"]');
      if (viewport === 'desktop') await page.screenshot({ path: shot(`post-approve-instead-confirm-${w}-${mode}.png`) });
      await page.click('#uiSheet [data-confirm-inline] [data-confirm-ok]');
      await toastIs(page, /Approved/);
      await page.waitForFunction(() => !document.querySelector('[data-posts-items] > [data-post-item="4"]'));
      expect.eq(sql('SELECT status FROM posts WHERE id = 4')[0].status, 'approved', 'saved');
      expect.eq(await segCount(page, 'denied'), before - 1, 'Sent back count');
      expect(await page.isVisible('#uiSheet [data-state="approved"]'), 'the sheet reads Approved');
      expect(!(await page.isVisible('#uiSheet [data-sentback-panel]')), 'the panel goes');
      expect.eq(sql("SELECT actor FROM activity_log WHERE entity_type = 'post' AND entity_id = 4 AND action = 'approved' ORDER BY id DESC LIMIT 1")[0].actor, 'client', 'logged as the client');
    });

    await test('Emails + Pages: Sent back rows; Approve instead from the email sheet', async (page) => {
      scene();
      await page.goto(url('emails.php?client=privacybee&status=denied'));
      const t = await page.textContent('[data-emails-items]');
      expect(/Time to renew/.test(t) && /Joust is reworking this/.test(t) && /New subject line on the way/.test(t), 'row');
      expect(await noSideways(page), 'no sideways scroll');
      await page.screenshot({ path: shot(`emails-sentback-${w}-${mode}.png`) });
      await page.click('#email-4 [data-email-open]');
      await sheetOpen(page);
      expect(await page.isVisible('#uiSheet [data-sentback-panel]'), 'panel');
      await page.screenshot({ path: shot(`email-item-${w}-${mode}.png`) });
      await page.click('#uiSheet [data-approve-instead]');
      await page.click('#uiSheet [data-confirm-inline="approve-instead"] [data-confirm-ok]');
      await toastIs(page, /Approved/);
      await page.waitForFunction(() => !document.querySelector('[data-email-item="4"]'));
      expect.eq(sql('SELECT status FROM emails WHERE id = 4')[0].status, 'approved');
      await page.goto(url('pages.php?client=privacybee&status=denied'));
      expect(/Headline should say Spring/.test(await page.textContent('[data-pages-items]')), 'page row');
      await page.screenshot({ path: shot(`pages-sentback-${w}-${mode}.png`) });
    });

    await test('Home: the "Sent back N" card (neutral count) opens an item', async (page) => {
      scene();
      await page.goto(url('index.php?client=kenda'));
      await page.waitForSelector('[data-home-sentback]');
      expect.eq((await page.textContent('[data-sentback-count]')).trim(), '5', 'posts 2 + 4, renders 9 + 17, library 8');
      const bg = await page.$eval('[data-sentback-count]', (e) => getComputedStyle(e).backgroundColor);
      expect(!/rgb\(255, 59, 48\)/.test(bg), 'not the red badge: ' + bg);
      await page.$eval('[data-home-sentback]', (e) => { e.scrollIntoView({ block: 'start' }); window.scrollBy(0, -120); });   // clear of the sticky title
      await page.waitForTimeout(700);   // lazy thumbnails
      await page.screenshot({ path: shot(`home-sentback-${w}-${mode}.png`) });
      await page.click('[data-sentback-row="post:4"]');
      await sheetOpen(page);
      expect(await page.isVisible('#uiSheet [data-sentback-panel]'), 'the item opens in its Sent back view');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'] });

  // -------------------------------------------------------------------------------------------------------------------
  await run('sent back: assets + tires, the viewer (client)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390', mode = viewport === 'desktop' ? 'dark' : 'light';
    await theme(ctx, mode);

    await test('Assets → Sent back: the list; the viewer says Add a comment · Approve instead; approving takes it off the list and the Redo queue', async (page) => {
      scene();
      expect(sql('SELECT redo_at FROM library_images WHERE id = 8')[0].redo_at, 'queued by the client\'s Needs changes');
      await page.goto(url('assets.php?client=kenda&view=library'));
      await page.click('.as-filters a.as-chip[href*="filter=denied"]');
      await page.waitForSelector('[data-sentback-list]');
      const t = await page.textContent('#lib-8');
      expect(/Too dark — brighten the sky please/.test(t) && /Being reworked/.test(t), 'note + Being reworked');
      expect(await noSideways(page), 'no sideways scroll');
      await page.screenshot({ path: shot(`assets-sentback-${w}-${mode}.png`) });
      await page.click('#lib-8');
      await page.waitForSelector('[data-viewer]:not([hidden])');
      await page.waitForFunction(() => document.querySelector('[data-viewer]').classList.contains('is-visible'));
      expect.eq((await page.textContent('[data-viewer-approve-label]')).trim(), 'Approve instead');
      expect.eq((await page.textContent('[data-viewer-deny-label]')).trim(), 'Add a comment');
      expect(/Sent back · Being reworked/.test(await page.textContent('[data-viewer-sentback]')), 'the status line');
      await page.waitForFunction(() => /Too dark/.test((document.querySelector('[data-viewer-thread]') || {}).textContent || ''));
      await page.waitForTimeout(250);
      await page.screenshot({ path: shot(`asset-item-${w}-${mode}.png`) });
      // Add a comment → the Comments panel + composer
      await page.click('[data-viewer-deny]');
      expect(await page.isVisible('[data-viewer-comments-panel]'), 'comments open');
      expect.eq(sql('SELECT status FROM library_images WHERE id = 8')[0].status, 'denied', 'no decision from that button');
      // Approve instead → asks → approved, off the list + the queue
      await page.click('[data-viewer-approve]');
      await page.waitForSelector('[data-viewer-confirm]:not([hidden])');
      await page.click('[data-viewer-confirm-ok]');
      await page.waitForFunction(() => document.querySelector('[data-viewer]').hidden || !document.querySelector('[data-viewer]').classList.contains('is-visible'), null, { timeout: 6000 });
      await page.waitForFunction(() => !document.querySelector('#lib-8'));
      const row = sql('SELECT status, redo_at FROM library_images WHERE id = 8')[0];
      expect.eq(row.status, 'approved'); expect(!row.redo_at, 'off the Redo queue');
      expect.eq((await page.textContent('.as-chip.is-active [data-count="denied"]')).trim(), '0', 'chip count');
    });

    await test('Tires → Sent back (every tire): series renders and reference images, Cancel keeps it', async (page) => {
      scene();
      api('client', 'tire-status.php', { id: 3, status: 'denied', comment: 'Wrong reference angle', client: 'kenda' });
      await page.goto(url('assets.php?client=kenda&view=collections'));
      await page.click('[data-sentback-chip]');
      await page.waitForSelector('[data-sentback-list]');
      for (const id of [3, 9, 17]) expect(await page.isVisible('#image-' + id), 'image ' + id);
      expect(/Klever AT2 · Reference/.test(await page.textContent('#image-3')), 'reference image labelled');
      expect(await noSideways(page), 'no sideways scroll');
      await page.screenshot({ path: shot(`tires-sentback-${w}-${mode}.png`) });
      await page.click('#image-3');
      await page.waitForSelector('[data-viewer]:not([hidden])');
      await page.click('[data-viewer-approve]');
      await page.waitForSelector('[data-viewer-confirm]:not([hidden])');
      await page.click('[data-viewer-confirm-cancel]');
      expect(await page.isVisible('[data-viewer-actions]'), 'back to the buttons');
      expect.eq(sql('SELECT status FROM tire_images WHERE id = 3')[0].status, 'denied', 'Cancel: unchanged');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'] });

  // -------------------------------------------------------------------------------------------------------------------
  await run('sent back: 320 px (client)', async ({ test, expect }) => {
    await test('segments scroll, the sheet buttons and the viewer buttons fit', async (page) => {
      scene();
      for (const u of ['posts.php?client=kenda&status=denied&month=all', 'emails.php?client=privacybee&status=denied', 'pages.php?client=privacybee&status=denied',
                       'assets.php?client=kenda&view=library&filter=denied', 'assets.php?client=kenda&view=collections&filter=denied', 'index.php?client=kenda']) {
        await page.goto(url(u));
        expect(await noSideways(page), u + ': no sideways scroll');
      }
      await page.goto(url('posts.php?client=kenda&status=denied&month=all'));
      expect(await page.$('.ui-segmented--scroll'), 'the scrolling control');
      await page.goto(url('posts.php?client=kenda&post=4'));
      await sheetOpen(page);
      const fit = await page.$$eval('#uiSheet .pd-sentback .ui-btn', (bs) => bs.every((b) => b.scrollWidth <= b.clientWidth + 1));
      expect(fit, 'Add a comment · Approve instead fit');
      await page.goto(url('assets.php?client=kenda&view=library&filter=denied'));
      await page.click('#lib-8');
      await page.waitForSelector('[data-viewer]:not([hidden])');
      const vfit = await page.$$eval('[data-viewer-approve], [data-viewer-deny]', (bs) => bs.every((b) => b.scrollWidth <= b.clientWidth + 1));
      expect(vfit, 'the viewer buttons fit');
    });
  }, { role: 'client', viewports: ['w320'] });

  // -------------------------------------------------------------------------------------------------------------------
  await run('sent back: the admin is unchanged', async ({ test, expect }) => {
    await test('admin Posts keep Needs changes up front, the sheet keeps Edit & resubmit', async (page) => {
      scene();
      await page.goto(url('posts.php?client=kenda'));
      expect.eq((await page.textContent('.ui-segmented-item[data-segment="denied"]')).replace(/\s+\d+$/, '').trim(), 'Needs changes');
      await page.goto(url('posts.php?client=kenda&post=4'));
      await sheetOpen(page);
      expect(!(await page.$('#uiSheet [data-sentback-panel]')), 'no client panel');
      expect(await page.isVisible('#uiSheet [data-newpost-resubmit]'), 'Edit & resubmit');
      expect(/INTERNAL e2e secret/.test(await page.textContent('#uiSheet')), 'Joust sees its internal note');
    });
  }, { role: 'admin', viewports: ['desktop'] });
})();
