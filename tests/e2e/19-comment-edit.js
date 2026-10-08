/* Comment editing in the browser (tests/smoke/24-comment-edit.php covers the server side):
   - the client: ⋯ → Edit inline (Enter saves, Esc cancels, Shift+Enter = new line), the "edited" label (tap → when + who),
     ⋯ → Delete (asks first) → "Comment deleted" in place + the count drops; a just-sent bubble is editable at once;
     never a menu on Joust's bubbles; the Needs-changes "Your note" edits in place
   - the admin: History (every revision), "Show original" on a deleted comment, editing a client's comment
   - the media viewer's thread (Esc inside the editor never closes the viewer) and the email sheet
   - phone 390 (touch): the ⋯ is there, long-press opens the same menu
   Screenshots (edit inline, edited label, deleted placeholder, admin history — 1440 dark, 390 light) go to
   $CEDIT_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/cedit). */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run, url, BASE } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = process.env.CEDIT_SHOTS_DIR || path.join(ROOT, 'shots', 'cedit');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name) => path.join(SHOTS, name);

function sql(query, params) {
  const php = `$p=new PDO('mysql:host=localhost;dbname='.getenv('PORTAL_TEST_DB').';charset=utf8mb4',getenv('PORTAL_TEST_DB_USER'),getenv('PORTAL_TEST_DB_PASS'));`
    + `$p->exec("SET time_zone = '".(new DateTime('now', new DateTimeZone('America/New_York')))->format('P')."'");`
    + `$s=$p->prepare($argv[1]);$s->execute(json_decode($argv[2],true));echo json_encode($s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):['id'=>$p->lastInsertId()]);`;
  const env = Object.assign({ PORTAL_TEST_DB: 'portal_test', PORTAL_TEST_DB_USER: 'portal_test', PORTAL_TEST_DB_PASS: 'portal_test' }, process.env);
  return JSON.parse(execFileSync('php', ['-r', php, query, JSON.stringify(params || [])], { env }).toString() || '[]');
}
/** A comment row → its id. */
function comment(type, id, actor, text, extra) {
  extra = extra || {};
  const r = sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, author_user_id, internal, summary, detail, client_contact_id, created_at)
                 VALUES (?, ?, ?, 'commented', ?, ?, ?, 'Comment', ?, ?, NOW() - INTERVAL ? MINUTE)`,
    [extra.company || 1, type, id, actor, actor === 'admin' ? 1 : null, extra.internal ? 1 : 0, text, actor === 'client' ? (extra.contact || 1) : null, extra.ago || 30]);
  return parseInt(r.id, 10);
}
const detail = (id) => (sql('SELECT detail, edited_at, deleted_at FROM activity_log WHERE id = ?', [id])[0] || {});
const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
const toast = (page, re) => page.waitForFunction((src) => new RegExp(src).test((document.getElementById('uiToast') || {}).textContent || ''), re.source);
const msg = (id) => `.ui-sheet-root.is-visible .pd-msg[data-comment-id="${id}"]`;
async function openPost(page, postId, id) {
  await page.goto(url(`posts.php?client=kenda&post=${postId}`));
  await page.waitForSelector(id ? msg(id) : '.ui-sheet-root.is-visible [data-post-detail]');
  await page.waitForTimeout(350);   // the sheet's slide-in settles
}
async function openMenu(page, sel) {
  await page.hover(sel + ' .ui-bubble').catch(() => {});
  await page.click(sel + ' [data-comment-more]');
  await page.waitForSelector(sel + ' [data-comment-menu]');
}

(async () => {
  // -------------------------------------------------------------------------------------------------------------------
  await run('comment edit: the client', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390', mode = viewport === 'desktop' ? 'dark' : 'light';
    await theme(ctx, mode);

    await test('⋯ → Edit inline after Joust replied: Enter saves, the "edited" label tells when and who', async (page) => {
      const id = comment('post', 1, 'client', 'Can we crop tighter on the tire?', { ago: 120 });
      comment('post', 1, 'admin', 'Sure — new crop tomorrow.', { ago: 60 });
      await openPost(page, 1, id);
      expect(!(await page.$(msg(id) + ' [data-comment-edited]')), 'not edited yet');
      await openMenu(page, msg(id));
      const items = await page.$$eval(msg(id) + ' [data-comment-menu-item]', (els) => els.map((e) => e.textContent));
      expect.eq(items.join(','), 'Edit,Delete', 'the client menu');
      await page.click(msg(id) + ' [data-comment-menu-item="edit"]');
      await page.waitForSelector(msg(id) + ' [data-comment-edit-input]');
      expect(await page.evaluate((s) => document.activeElement === document.querySelector(s + ' [data-comment-edit-input]'), msg(id)), 'the textarea has focus');
      expect.eq(await page.inputValue(msg(id) + ' [data-comment-edit-input]'), 'Can we crop tighter on the tire?');
      await page.fill(msg(id) + ' [data-comment-edit-input]', 'Can we crop a little tighter on the tire?');
      await page.screenshot({ path: shot(`edit-inline-${w}-${mode}.png`) });
      await page.press(msg(id) + ' [data-comment-edit-input]', 'Enter');
      await toast(page, /Comment updated/);
      await page.waitForSelector(msg(id) + ' [data-comment-edited]');
      expect.eq((await page.textContent(msg(id) + ' .ui-bubble')).trim(), 'Can we crop a little tighter on the tire?');
      expect.eq(detail(id).detail, 'Can we crop a little tighter on the tire?');
      expect(!(await page.$(msg(id) + ' [data-comment-editor]')), 'editor closed');
      // hover → title; tap → the line under the bubble
      const title = await page.getAttribute(msg(id) + ' [data-comment-edited]', 'title');
      expect(/^Edited .+ by you$/.test(title), 'title: ' + title);
      await page.click(msg(id) + ' [data-comment-edited]');
      expect(await page.isVisible(msg(id) + ' [data-comment-edited-detail]'), 'tap shows when + who');
      await page.screenshot({ path: shot(`edited-label-${w}-${mode}.png`) });
    });

    await test('Esc cancels without saving (the sheet stays open); Shift+Enter is a new line', async (page) => {
      const id = comment('post', 1, 'client', 'Keep me');
      await openPost(page, 1, id);
      await openMenu(page, msg(id));
      await page.keyboard.press('Enter');   // the first item has focus → Edit
      await page.waitForSelector(msg(id) + ' [data-comment-edit-input]');
      await page.fill(msg(id) + ' [data-comment-edit-input]', 'Changed my mind');
      await page.keyboard.press('Escape');
      await page.waitForSelector(msg(id) + ' [data-comment-editor]', { state: 'detached' });
      expect(await page.isVisible('.ui-sheet-root.is-visible'), 'the sheet is still open');
      expect.eq((await page.textContent(msg(id) + ' .ui-bubble')).trim(), 'Keep me');
      expect.eq(detail(id).detail, 'Keep me');
      await openMenu(page, msg(id));
      await page.click(msg(id) + ' [data-comment-menu-item="edit"]');
      await page.fill(msg(id) + ' [data-comment-edit-input]', 'Line one');
      await page.press(msg(id) + ' [data-comment-edit-input]', 'Shift+Enter');
      await page.type(msg(id) + ' [data-comment-edit-input]', 'Line two');
      await page.click(msg(id) + ' [data-comment-edit-save]');
      await toast(page, /Comment updated/);
      expect.eq(detail(id).detail, 'Line one\nLine two');
    });

    await test('⋯ → Delete asks first → "Comment deleted" in place, the count drops', async (page) => {
      const a = comment('post', 1, 'client', 'First', { ago: 50 });
      const b = comment('post', 1, 'client', 'Second — delete me', { ago: 40 });
      const c = comment('post', 1, 'admin', 'Joust reply', { ago: 30 });
      await openPost(page, 1, b);
      const before = parseInt(await page.textContent('.ui-sheet-root.is-visible [data-comment-count]'), 10);
      await openMenu(page, msg(b));
      await page.click(msg(b) + ' [data-comment-menu-item="delete"]');
      await page.waitForSelector('[data-confirm-inline="comment-delete"]');
      await page.click('[data-confirm-inline="comment-delete"] [data-confirm-ok]');
      await toast(page, /Comment deleted/);
      await page.waitForSelector(msg(b) + '[data-comment-deleted]');
      expect.eq((await page.textContent(msg(b) + ' .ui-bubble')).trim(), 'Comment deleted');
      expect(!(await page.$(msg(b) + ' [data-comment-more]')), 'no menu on the placeholder');
      expect.eq(parseInt(await page.textContent('.ui-sheet-root.is-visible [data-comment-count]'), 10), before - 1, 'count');
      const order = await page.$$eval('.ui-sheet-root.is-visible [data-thread] .pd-msg', (els) => els.map((e) => e.getAttribute('data-comment-id')));
      expect(order.indexOf(String(a)) < order.indexOf(String(b)) && order.indexOf(String(b)) < order.indexOf(String(c)), 'thread order kept');
      expect(detail(b).deleted_at, 'deleted in the DB');
      expect(!(await page.textContent('.ui-sheet-root.is-visible [data-thread]')).includes('Second — delete me'), 'the text is gone from the thread');
      await page.screenshot({ path: shot(`deleted-placeholder-${w}-${mode}.png`) });
      // after a reload too
      await openPost(page, 1, b);
      expect.eq((await page.textContent(msg(b) + ' .ui-bubble')).trim(), 'Comment deleted');
    });

    await test('no menu on Joust’s bubbles; a just-sent comment is editable at once', async (page) => {
      const j = comment('post', 1, 'admin', 'Joust only');
      await openPost(page, 1, j);
      expect(!(await page.$(msg(j) + ' [data-comment-more]')), 'no ⋯ on Joust’s bubble');
      await page.fill('.ui-sheet-root.is-visible [data-comment-input]', 'Fresh comment');
      await page.click('.ui-sheet-root.is-visible [data-comment-send]');
      await page.waitForSelector('.ui-sheet-root.is-visible .pd-msg[data-comment-id][data-actor="client"] [data-comment-more]');
      const fresh = await page.getAttribute('.ui-sheet-root.is-visible .pd-msg[data-comment-id][data-actor="client"]:last-of-type', 'data-comment-id');
      expect(fresh && detail(parseInt(fresh, 10)).detail === 'Fresh comment', 'the bubble carries the new id');
      await openMenu(page, msg(fresh));
      await page.click(msg(fresh) + ' [data-comment-menu-item="edit"]');
      await page.fill(msg(fresh) + ' [data-comment-edit-input]', 'Fresh comment, fixed');
      await page.press(msg(fresh) + ' [data-comment-edit-input]', 'Enter');
      await toast(page, /Comment updated/);
      expect.eq(detail(parseInt(fresh, 10)).detail, 'Fresh comment, fixed');
    });

    await test('the Needs-changes note ("Your note") edits in place', async (page) => {
      const note = parseInt(sql("SELECT id FROM activity_log WHERE entity_type = 'post' AND entity_id = 4 AND action = 'commented'")[0].id, 10);
      await page.goto(url('posts.php?client=kenda&post=4'));
      const host = `.ui-sheet-root.is-visible [data-hidden-note][data-comment-id="${note}"]`;
      await page.waitForSelector(host);
      await page.click(host + ' [data-comment-more]');
      await page.click(host + ' [data-comment-menu-item="edit"]');
      await page.fill(host + ' [data-comment-edit-input]', 'Please use the darker render from March');
      await page.click(host + ' [data-comment-edit-save]');
      await toast(page, /Comment updated/);
      await page.waitForSelector(host + ' [data-comment-edited-tag]');
      expect.eq((await page.textContent(host + ' [data-comment-body]')).trim(), 'Please use the darker render from March');
      expect.eq(detail(note).detail, 'Please use the darker render from March');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  await run('comment edit: the admin', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390', mode = viewport === 'desktop' ? 'dark' : 'light';
    await theme(ctx, mode);

    await test('Joust edits a client’s comment, then History lists every revision; internal notes stay editable', async (page) => {
      const id = comment('post', 2, 'client', '[Slide 2] Make it pop', { ago: 90 });
      const note = comment('post', 2, 'admin', 'Internal: check brand book', { internal: true, ago: 20 });
      await openPost(page, 2, id);
      expect.eq(await page.getAttribute(msg(id), 'data-comment-on-slide'), '2');
      await openMenu(page, msg(id));
      await page.click(msg(id) + ' [data-comment-menu-item="edit"]');
      expect(await page.isVisible(msg(id) + ' [data-comment-edit-slide]'), 'the slide picker');
      expect.eq(await page.inputValue(msg(id) + ' [data-comment-edit-slide]'), '2');
      await page.selectOption(msg(id) + ' [data-comment-edit-slide]', '3');
      await page.fill(msg(id) + ' [data-comment-edit-input]', 'Make it pop more');
      await page.press(msg(id) + ' [data-comment-edit-input]', 'Enter');
      await toast(page, /Comment updated/);
      expect.eq(detail(id).detail, '[Slide 3] Make it pop more');
      await page.waitForSelector(msg(id) + ' .pd-slide-chip');
      expect((await page.textContent(msg(id) + ' .pd-slide-chip')).includes('Slide 3'), 'the chip follows');
      await openMenu(page, msg(id));
      await page.click(msg(id) + ' [data-comment-menu-item="history"]');
      await page.waitForSelector(msg(id) + ' [data-comment-history-list] li');
      const kinds = await page.$$eval(msg(id) + ' [data-comment-history-list] li', (els) => els.map((e) => e.getAttribute('data-history-kind')));
      expect.eq(kinds.join(','), 'posted,edit');
      const text = await page.textContent(msg(id) + ' [data-comment-history-list]');
      expect(text.includes('Make it pop') && text.includes('[Slide 3] Make it pop more') && text.includes('by you'), 'both texts + who');
      await page.screenshot({ path: shot(`admin-history-${w}-${mode}.png`) });
      // an internal note: editable, still marked internal
      await openMenu(page, msg(note));
      await page.click(msg(note) + ' [data-comment-menu-item="edit"]');
      await page.fill(msg(note) + ' [data-comment-edit-input]', 'Internal: check the 2026 brand book');
      await page.press(msg(note) + ' [data-comment-edit-input]', 'Enter');
      await toast(page, /Comment updated/);
      expect(await page.isVisible(msg(note) + ' [data-internal-pill]'), 'still internal');
    });

    await test('a deleted comment: "Comment deleted", Joust can open the original', async (page) => {
      const id = comment('post', 1, 'client', 'Original words', { ago: 30 });
      await openPost(page, 1, id);
      await openMenu(page, msg(id));
      await page.click(msg(id) + ' [data-comment-menu-item="delete"]');
      await page.click('[data-confirm-inline="comment-delete"] [data-confirm-ok]');
      await page.waitForSelector(msg(id) + '[data-comment-deleted]');
      await page.click(msg(id) + ' [data-comment-original] summary');
      expect(await page.isVisible(msg(id) + ' .pd-msg-original-text'), 'the original');
      expect.eq((await page.textContent(msg(id) + ' .pd-msg-original-text')).trim(), 'Original words');
      expect(await page.isVisible(msg(id) + ' [data-comment-more]'), 'History stays on the admin seat');
    });

    await test('the media viewer’s thread: edit in place, Esc in the editor never closes the viewer', async (page) => {
      const id = comment('library_image', 7, 'client', 'Library note', { ago: 15 });
      await page.goto(url('assets.php?client=kenda&view=library&filter=pending'));
      await page.click('#lib-7');
      await page.waitForFunction(() => document.querySelector('[data-viewer]').classList.contains('is-visible'));
      await page.click('[data-viewer-comments-toggle]');
      const m = `[data-viewer-thread] .pd-msg[data-comment-id="${id}"]`;
      await page.waitForSelector(m);
      await page.hover(m + ' .ui-bubble').catch(() => {});
      await page.click(m + ' [data-comment-more]');
      await page.click(m + ' [data-comment-menu-item="edit"]');
      await page.fill(m + ' [data-comment-edit-input]', 'Library note, sharper');
      await page.keyboard.press('Escape');
      await page.waitForSelector(m + ' [data-comment-editor]', { state: 'detached' });
      expect(await page.evaluate(() => document.querySelector('[data-viewer]').classList.contains('is-visible')), 'the viewer stays open');
      await page.click(m + ' [data-comment-more]');
      await page.click(m + ' [data-comment-menu-item="edit"]');
      await page.fill(m + ' [data-comment-edit-input]', 'Library note, sharper');
      await page.press(m + ' [data-comment-edit-input]', 'Enter');
      await toast(page, /Comment updated/);
      expect.eq(detail(id).detail, 'Library note, sharper');
      expect(await page.isVisible(`[data-viewer-thread] .pd-msg[data-comment-id="${id}"] [data-comment-edited]`), 'edited label in the viewer');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  await run('comment edit: Privacy Bee (email sheet)', async ({ test, expect }) => {
    await test('the email sheet: the client edits and deletes its comment', async (page) => {
      const email = parseInt(sql("SELECT id FROM emails WHERE company_id = 2 AND status = 'pending' LIMIT 1")[0].id, 10);
      const id = comment('email', email, 'client', 'Subject line typo', { company: 2, contact: 3 });
      await page.goto(url(`emails.php?client=privacybee&email=${email}`));
      await page.waitForSelector(msg(id));
      await page.waitForTimeout(350);
      await openMenu(page, msg(id));
      await page.click(msg(id) + ' [data-comment-menu-item="edit"]');
      await page.fill(msg(id) + ' [data-comment-edit-input]', 'Subject line typo: “Privcy”');
      await page.press(msg(id) + ' [data-comment-edit-input]', 'Enter');
      await toast(page, /Comment updated/);
      expect.eq(detail(id).detail, 'Subject line typo: “Privcy”');
      await openMenu(page, msg(id));
      await page.click(msg(id) + ' [data-comment-menu-item="delete"]');
      await page.click('[data-confirm-inline="comment-delete"] [data-confirm-ok]');
      await page.waitForSelector(msg(id) + '[data-comment-deleted]');
    });
  }, { role: 'client:privacybee', viewports: ['desktop'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  // A real phone (touch, no hover): the ⋯ shows without hover; a long-press opens the same menu.
  await run('comment edit: phone 390 (touch)', async ({ test, expect, ctx }) => {
    await test('⋯ visible on touch, long-press opens the menu, Edit works', async (page) => {
      const id = comment('post', 1, 'client', 'Touch me', { ago: 10 });
      const touch = await ctx.browser().newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 1 });
      await touch.addCookies([{ name: 'portal_test_role', value: 'client', url: BASE.replace(/\/portal$/, '') }]);
      await touch.addInitScript(() => { try { localStorage.setItem('portal.theme', 'light'); } catch (e) {} });
      const p = await touch.newPage();
      const errors = [];
      p.on('pageerror', (e) => errors.push(e.message));
      try {
        await openPost(p, 1, id);
        const op = await p.$eval(msg(id) + ' [data-comment-more]', (b) => parseFloat(getComputedStyle(b).opacity));
        expect(op > 0.5, 'the ⋯ is visible without hover (opacity ' + op + ')');
        await p.$eval(msg(id), (el) => el.scrollIntoView({ block: 'center' }));
        await p.waitForTimeout(300);
        const box = await p.$eval(msg(id) + ' .ui-bubble', (el) => { const r = el.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
        const cdp = await touch.newCDPSession(p);
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: box.x, y: box.y }] });
        await p.waitForTimeout(700);
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await p.waitForSelector(msg(id) + ' [data-comment-menu]');
        await p.screenshot({ path: shot('longpress-menu-390-light.png') });
        await p.tap(msg(id) + ' [data-comment-menu-item="edit"]');
        await p.waitForSelector(msg(id) + ' [data-comment-edit-input]');
        await p.fill(msg(id) + ' [data-comment-edit-input]', 'Touched and edited');
        await p.tap(msg(id) + ' [data-comment-edit-save]');
        await toast(p, /Comment updated/);
        expect.eq(detail(id).detail, 'Touched and edited');
        // the menu fits the 390 screen
        await p.tap(msg(id) + ' [data-comment-more]');
        await p.waitForSelector(msg(id) + ' [data-comment-menu]');
        const r = await p.$eval(msg(id) + ' [data-comment-menu]', (el) => { const b = el.getBoundingClientRect(); return { l: b.left, r: b.right }; });
        expect(r.l >= 0 && r.r <= 390, 'menu inside the screen: ' + JSON.stringify(r));
        if (errors.length) throw new Error('page error: ' + errors.join(' | '));
      } finally {
        await touch.close();
      }
    });
  }, { role: 'client', viewports: ['phone'], reseed: 'test' });
})();
