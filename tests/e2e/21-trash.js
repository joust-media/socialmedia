/* Joust's Trash in the browser (tests/smoke/26-trash.php covers the server side):
   - Move to Trash… from the post sheet's ⋯ menu (the ask panel in the sheet, an optional reason), the email sheet's ⋯
     menu, the media viewer's ⋯ (the action sheet above the viewer), the Assets select bar (several at once) and the
     Redo row — the item leaves the list, the counts drop, the toast links to the Trash; the status is untouched
   - the Trash page: rows grouped by type, the previous status, the reason, the client's last note, who + when;
     Restore (one, and Select all → Restore selected) puts each back exactly where it was; Delete forever asks for the
     typed DELETE (the button stays disabled until then) and the row + its file go
   - the client: a deep link to a trashed post / image shows a neutral "This item is no longer available" (no error,
     no sheet), the lists leave it out; Joust's own deep link says it is in the Trash, with a link
   - phones (390 / 320): no sideways scroll on the Trash page, the row buttons fit
   Screenshots (the Trash page, the move-to-trash sheet, the client "no longer available" — 1440 dark, 390 light) go to
   $TRASH_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/trash). */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run, url } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const MEDIA = process.env.MEDIA_DIR || path.join(ROOT, 'site/media');
const SHOTS = process.env.TRASH_SHOTS_DIR || path.join(ROOT, 'shots', 'trash');
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
function trash(items, note, client) {
  const code = api('admin', 'trash.php', { action: 'trash', items, note: note || '', client: client || 'kenda' });
  if (code !== '200') throw new Error('trash ' + items + ': HTTP ' + code);
}
const trashed = (table, id) => (sql(`SELECT trashed_at FROM ${table} WHERE id = ?`, [id])[0] || {}).trashed_at || null;
const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
const toastIs = (page, re) => page.waitForFunction((src) => new RegExp(src).test((document.getElementById('uiToast') || {}).textContent || ''), re.source, { timeout: 8000 });
const noSideways = (page) => page.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const segCount = (page, seg) => page.$eval(`.ui-segmented-item[data-segment="${seg}"] .ui-segmented-count`, (e) => parseInt(e.textContent, 10) || 0);
async function sheetOpen(page, inner) {
  await page.waitForSelector('#uiSheet.is-open ' + (inner || '.pd'));
  await page.waitForTimeout(450);   // the slide-in settles
}
async function actionSheetOpen(page) {
  await page.waitForFunction(() => { const s = document.getElementById('asActionSheet'); return s && s.classList.contains('is-visible'); });
  await page.waitForTimeout(400);
}
async function openViewerOn(page, sel) {
  await page.click(sel);
  await page.waitForSelector('[data-viewer]:not([hidden])');
  await page.waitForFunction(() => document.querySelector('[data-viewer]').classList.contains('is-visible'));
}

(async () => {
  // -------------------------------------------------------------------------------------------------------------------
  await run('trash: moving items (admin)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390', mode = viewport === 'desktop' ? 'dark' : 'light';
    await theme(ctx, mode);

    await test('post sheet ⋯ → Move to Trash…: the ask panel, a reason, the row leaves, the count drops, the status stays', async (page) => {
      await page.goto(url('posts.php?client=kenda&status=denied&month=all'));
      const before = await segCount(page, 'denied');
      await page.click('#post-4 [data-post-open]');
      await sheetOpen(page);
      await page.click('#uiSheet [data-menu-toggle]');
      expect(await page.isVisible('#uiSheet [data-trash-item="post:4"]'), 'Move to Trash… in the ⋯ menu');
      await page.click('#uiSheet [data-trash-item="post:4"]');
      await page.waitForSelector('#uiSheet [data-trash-ask] [data-trash-form]');
      expect(/Move this post to Trash\?/.test(await page.textContent('#uiSheet [data-trash-ask]')), 'the question');
      expect(await page.evaluate(() => document.activeElement && document.activeElement.matches('[data-trash-note]')), 'the reason is focused');
      await page.fill('#uiSheet [data-trash-note]', 'Not redoing this one — the client moved on');
      expect(await noSideways(page), 'no sideways scroll');
      await page.screenshot({ path: shot(`move-to-trash-post-${w}-${mode}.png`) });
      // Cancel keeps it
      await page.click('#uiSheet [data-trash-cancel]');
      expect(!(await page.$('#uiSheet [data-trash-ask]')), 'the panel goes');
      expect(!trashed('posts', 4), 'Cancel: not trashed');
      await page.click('#uiSheet [data-menu-toggle]');
      await page.click('#uiSheet [data-trash-item="post:4"]');
      await page.fill('#uiSheet [data-trash-note]', 'Not redoing this one');
      await page.click('#uiSheet [data-trash-submit]');
      await toastIs(page, /Moved to Trash/);
      expect(await page.isVisible('#uiToast a[href*="trash.php"]'), 'the toast links to the Trash');
      await page.waitForFunction(() => !document.querySelector('[data-posts-items] > [data-post-item="4"]'));
      expect.eq(await segCount(page, 'denied'), before - 1, 'Needs changes count');
      const row = sql('SELECT status, trashed_at, trash_note FROM posts WHERE id = 4')[0];
      expect(row.trashed_at, 'trashed'); expect.eq(row.status, 'denied', 'status untouched'); expect.eq(row.trash_note, 'Not redoing this one');
    });

    await test('email sheet ⋯ → Move to Trash…', async (page) => {
      await page.goto(url('emails.php?client=privacybee&status=denied'));
      await page.click('#email-4 [data-email-open]');
      await sheetOpen(page, '.ed');
      await page.click('#uiSheet [data-asg-menu-toggle]');
      await page.click('#uiSheet [data-trash-item="email:4"]');
      await page.waitForSelector('#uiSheet [data-trash-ask]');
      await page.click('#uiSheet [data-trash-submit]');
      await toastIs(page, /Moved to Trash/);
      await page.waitForFunction(() => !document.querySelector('[data-email-item="4"]'));
      expect(trashed('emails', 4), 'trashed');
    });

    await test('viewer ⋯ → Move to Trash…: the sheet above the viewer, the tile leaves', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=library&filter=approved'));
      await openViewerOn(page, '#lib-2');
      await page.click('[data-viewer-more]');
      await page.waitForSelector('[data-viewer-menu]:not([hidden])');
      expect(await page.isVisible('[data-viewer-trash]'), 'Move to Trash… in the viewer menu');
      await page.click('[data-viewer-trash]');
      await actionSheetOpen(page);
      const onTop = await page.evaluate(() => { const f = document.querySelector('#asActionSheet [data-trash-form]'); const r = f.getBoundingClientRect(); const el = document.elementFromPoint(r.left + r.width / 2, r.top + 10); return !!(el && el.closest('#asActionSheet')); });
      expect(onTop, 'sheet above the viewer');
      await page.fill('#asActionSheet [data-trash-note]', 'Duplicate of lib 1');
      await page.screenshot({ path: shot(`move-to-trash-viewer-${w}-${mode}.png`) });
      await page.click('#asActionSheet [data-trash-submit]');
      await toastIs(page, /Moved to Trash/);
      await page.waitForFunction(() => !document.getElementById('lib-2'), null, { timeout: 5000 });
      const row = sql('SELECT status, trashed_at, trash_note FROM library_images WHERE id = 2')[0];
      expect(row.trashed_at, 'trashed'); expect.eq(row.status, 'approved'); expect.eq(row.trash_note, 'Duplicate of lib 1');
      expect(fs.existsSync(path.join(MEDIA, 'library/kenda', sql('SELECT filename FROM library_images WHERE id = 2')[0].filename)), 'the file stays');
    });

    await test('select bar: Move to Trash… on several tiles; the Trash chip counts', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved'));
      await page.click('[data-assets-select]');
      expect(!(await page.isEnabled('[data-select-trash]')), 'disabled with nothing picked');
      await page.click('#image-4'); await page.click('#image-5');
      expect(await page.isEnabled('[data-select-trash]'), 'enabled');
      await page.click('[data-select-trash]');
      await actionSheetOpen(page);
      expect.eq((await page.textContent('#asActionSheet [data-trash-submit]')).trim(), 'Move 2 to Trash');
      await page.click('#asActionSheet [data-trash-submit]');
      await toastIs(page, /2 items moved to Trash/);
      await page.waitForFunction(() => !document.getElementById('image-4') && !document.getElementById('image-5'), null, { timeout: 5000 });
      expect(trashed('tire_images', 4) && trashed('tire_images', 5), 'both trashed');
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved'));
      expect(!(await page.$('#image-4')) && !(await page.$('#image-5')), 'gone after a reload');
      expect(/Trash\s*\(?2\)?/.test((await page.textContent('[data-trash-link]')).replace(/\s+/g, ' ')), 'the Trash chip: 2');
    });

    await test('Redo row → Trash…: not redoing it, the row leaves the queue', async (page) => {
      sql('UPDATE tire_images SET redo_at = NOW() - INTERVAL 3 HOUR WHERE id IN (9, 17)');
      await page.goto(url('redo.php?client=kenda'));
      await page.waitForSelector('[data-redo-row="tire:9"]');
      const n = parseInt(await page.textContent('[data-redo-count]'), 10);
      await page.click('[data-redo-row="tire:9"] [data-redo-trash]');
      await page.waitForSelector('[data-trash-form]');
      await page.waitForTimeout(400);
      await page.fill('[data-trash-note]', 'Client dropped this angle');
      await page.click('[data-trash-submit]');
      await toastIs(page, /Moved to Trash/);
      await page.waitForFunction(() => !document.querySelector('[data-redo-row="tire:9"]'));
      expect.eq(parseInt(await page.textContent('[data-redo-count]'), 10), n - 1, 'the Redo count');
      const row = sql('SELECT status, trashed_at FROM tire_images WHERE id = 9')[0];
      expect(row.trashed_at, 'trashed'); expect.eq(row.status, 'denied', 'status untouched');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  await run('trash: the Trash page (admin)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : (viewport === 'w320' ? '320' : '390'), mode = viewport === 'desktop' ? 'dark' : 'light';
    await theme(ctx, mode);
    const scene = () => {
      trash('post:4', 'Not doing the winter promo after all');
      trash('tire:9,tire:17', 'Client dropped these angles');
      trash('library:7', '');
      trash('email:4,page:1', 'Campaign cancelled', 'privacybee');
    };

    await test('rows grouped by type: previous status, reason, the client\'s last note, who + when; Home links it', async (page) => {
      scene();
      await page.goto(url('index.php?client=kenda'));
      expect(/Trash \(4\)/.test(await page.textContent('[data-home-link="trash"]')), 'Home: Trash (4)');
      await page.goto(url('trash.php?client=kenda'));
      await page.waitForSelector('[data-trash-row="post:4"]');
      expect.eq((await page.textContent('[data-trash-count]')).trim(), '4');
      const groups = await page.$$eval('[data-trash-group]', (els) => els.map((e) => e.getAttribute('data-trash-group')));
      expect(groups.length >= 3, 'grouped by type: ' + groups.join(' | '));
      const t = await page.textContent('[data-trash-row="post:4"]');
      expect(/Winter promo/.test(t), 'title');
      expect(/Not doing the winter promo after all/.test(t), 'reason');
      expect(/Please use the darker render/.test(t), 'the client\'s last note');
      expect.eq(await page.getAttribute('[data-trash-row="post:4"] [data-trash-prev]', 'data-trash-prev'), 'denied', 'was Needs changes');
      expect(/Trashed by/.test(t), 'who');
      expect(!(await page.$('[data-trash-row="email:4"]')), 'another client\'s item is not in this scope');
      expect(await noSideways(page), 'no sideways scroll');
      await page.evaluate(() => document.querySelectorAll('.rd-thumb img').forEach((i) => { i.loading = 'eager'; }));
      await page.waitForLoadState('networkidle');
      await page.screenshot({ path: shot(`trash-page-${w}-${mode}.png`), fullPage: true });
      // All clients
      await page.click('[data-trash-scope="all"]');
      await page.waitForSelector('[data-trash-row="email:4"]');
      expect.eq((await page.textContent('[data-trash-count]')).trim(), '6', 'all clients');
      if (viewport !== 'desktop') {
        const fits = await page.$$eval('[data-trash-row] [data-trash-restore]', (els) => els.every((b) => { const r = b.getBoundingClientRect(); return r.right <= window.innerWidth + 1 && r.width > 40; }));
        expect(fits, 'the Restore buttons fit');
      }
      expect(await noSideways(page), 'no sideways scroll (all clients)');
    });

    await test('Restore: back exactly where it was; Select all → Restore selected', async (page) => {
      scene();
      await page.goto(url('trash.php?client=kenda'));
      await page.click('[data-trash-row="post:4"] [data-trash-restore]');
      await toastIs(page, /Restored/);
      await page.waitForFunction(() => !document.querySelector('[data-trash-row="post:4"]'));
      expect.eq((await page.textContent('[data-trash-count]')).trim(), '3');
      const p = sql('SELECT status, trashed_at, trash_note FROM posts WHERE id = 4')[0];
      expect(!p.trashed_at && !p.trash_note, 'out of the Trash'); expect.eq(p.status, 'denied', 'still Needs changes');
      await page.check('[data-trash-all]');
      expect.eq((await page.textContent('[data-trash-restore-label]')).trim(), 'Restore 3');
      await page.click('[data-trash-restore-selected]');
      await toastIs(page, /3 restored/);
      await page.waitForSelector('[data-trash-empty]');
      expect(!trashed('tire_images', 9) && !trashed('tire_images', 17) && !trashed('library_images', 7), 'all back');
      expect.eq(sql('SELECT status FROM tire_images WHERE id = 9')[0].status, 'denied');
      // the post is back in its list
      await page.goto(url('posts.php?client=kenda&status=denied&month=all'));
      expect(await page.$('#post-4'), 'back in Needs changes');
    });

    await test('Delete forever: ⋯ → the typed DELETE; the row and its file go', async (page) => {
      scene();
      const file = path.join(MEDIA, 'library/kenda', sql('SELECT filename FROM library_images WHERE id = 7')[0].filename);
      expect(fs.existsSync(file), 'file there before');
      await page.goto(url('trash.php?client=kenda'));
      await page.click('[data-trash-row="library_image:7"] [data-trash-menu-toggle]');
      await page.click('[data-trash-row="library_image:7"] [data-trash-delete]');
      await sheetOpen(page, '[data-trash-delete-form]');
      expect(!(await page.isEnabled('[data-trash-delete-submit]')), 'disabled until DELETE is typed');
      await page.fill('[data-trash-delete-word]', 'delete');
      expect(!(await page.isEnabled('[data-trash-delete-submit]')), 'the word, exactly');
      await page.fill('[data-trash-delete-word]', 'DELETE');
      expect(await page.isEnabled('[data-trash-delete-submit]'), 'enabled');
      if (viewport !== 'w320') await page.screenshot({ path: shot(`trash-delete-forever-${w}-${mode}.png`) });
      await page.click('[data-trash-delete-submit]');
      await toastIs(page, /Deleted forever/);
      await page.waitForFunction(() => !document.querySelector('[data-trash-row="library_image:7"]'));
      expect.eq(sql('SELECT COUNT(*) AS n FROM library_images WHERE id = 7')[0].n, 0, 'row gone');
      expect(!fs.existsSync(file), 'file gone');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone', 'w320'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  await run('trash: deep links', async ({ test, expect, viewport, ctx, role }) => {
    const w = viewport === 'desktop' ? '1440' : '390', mode = viewport === 'desktop' ? 'dark' : 'light';
    await theme(ctx, mode);

    await test('client: a trashed post / image deep link reads "no longer available" — no error, no sheet, not in the list', async (page) => {
      trash('post:2,library:1', 'Not doing these');
      await page.goto(url('posts.php?client=kenda&post=2'));
      await page.waitForSelector('[data-item-unavailable]');
      expect.eq((await page.textContent('[data-item-unavailable]')).trim(), 'This item is no longer available.');
      await page.waitForTimeout(600);
      expect(!(await page.$('#uiSheet.is-open')), 'no sheet');
      expect(!(await page.$('#post-2')), 'not in the list');
      expect(!/Not doing these|Trash/.test(await page.textContent('main')), 'never the reason, never the word Trash');
      expect(await noSideways(page), 'no sideways scroll');
      await page.screenshot({ path: shot(`client-no-longer-available-${w}-${mode}.png`) });
      await page.goto(url('assets.php?client=kenda&asset=1&kind=library'));
      await toastIs(page, /This item is no longer available/);
      expect(!(await page.$('[data-viewer].is-visible')), 'no viewer');
      expect(!(await page.$('#lib-1')), 'the image is not in the Library');
      await page.goto(url('assets.php?client=kenda&view=library'));
      expect(!(await page.$('#lib-1')), 'not in the Library list');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('trash: Joust\'s deep link', async ({ test, expect }) => {
    await test('admin: a trashed post deep link says it is in the Trash, with a link', async (page) => {
      trash('post:2', '');
      await page.goto(url('posts.php?client=kenda&post=2'));
      await page.waitForSelector('[data-item-trashed]');
      expect(/in the Trash/.test(await page.textContent('[data-item-trashed]')), 'says so');
      await page.click('[data-item-trashed] a');
      await page.waitForSelector('[data-trash-row="post:2"]');
    });
  }, { role: 'admin', viewports: ['desktop'], reseed: 'test' });
})();
