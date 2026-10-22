/* "Export open comments" in the browser (tests/smoke/28-comment-export.php covers the server side):
   - the export sheet from Manage → Export: Clients (this client · All clients), Which comments (Open · Waiting on Joust ·
     All), internal notes, dates, since last export, Format (CSV · Zip with images · Printable); the live count follows
   - CSV: a download, UTF-8 with BOM (emoji / accents intact), "since last export" reads "last just now" afterwards
   - Zip with images: the progress, a download with Client/Tire/Series/<file> + .txt, comments.csv, summary.html
   - Printable: a new tab with one block per image
   - the entry points: Manage → Export (one client / every client), the Redo page, the Assets Library / Tires ⋯ menus, the
     series ⋯ menu, the Inbox; on phones too (390): visible, tappable, the sheet fits, no sideways scroll
   - the client seat: none of it, and the endpoint answers 403
   Screenshots (the export sheet 1440 dark / 390 light, the entry points) + a sample CSV / zip listing go to
   $CEXP_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/cexp). */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run, url } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = process.env.CEXP_SHOTS_DIR || path.join(ROOT, 'shots', 'cexp');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name) => path.join(SHOTS, name);

function sql(query, params) {
  const php = `$p=new PDO('mysql:host=localhost;dbname='.getenv('PORTAL_TEST_DB').';charset=utf8mb4',getenv('PORTAL_TEST_DB_USER'),getenv('PORTAL_TEST_DB_PASS'));`
    + `$p->exec("SET time_zone = '".(new DateTime('now', new DateTimeZone('America/New_York')))->format('P')."'");`
    + `$s=$p->prepare($argv[1]);$s->execute(json_decode($argv[2],true));echo json_encode($s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):[]);`;
  const env = Object.assign({ PORTAL_TEST_DB: 'portal_test', PORTAL_TEST_DB_USER: 'portal_test', PORTAL_TEST_DB_PASS: 'portal_test' }, process.env);
  return JSON.parse(execFileSync('php', ['-r', php, query, JSON.stringify(params || [])], { env }).toString() || '[]');
}
function comment(type, id, text, o) {
  o = o || {};
  const actor = o.actor || 'client', co = o.company || 1;
  sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, author_user_id, internal, client_contact_id, created_at)
       VALUES (?, ?, ?, 'commented', ?, 'e2e', ?, ?, ?, ?, NOW() - INTERVAL ? MINUTE)`,
      [co, type, id, actor, text, actor === 'admin' ? 1 : null, o.internal ? 1 : 0, actor === 'client' ? (co === 1 ? 1 : 3) : null, o.ago || 10]);
}
/** The usual mix: one comment per image type (all waiting on Joust) + an answered one + an internal note. */
function mix() {
  comment('tire_image', 10, 'Render seven: tread too shallow — café 😀', { ago: 40 });
  comment('tire_image', 2, 'Reference two is the old sidewall', { ago: 35 });
  comment('tire_series', 1, 'The whole series reads too warm', { ago: 30 });
  comment('library_image', 7, 'Library seven: crop tighter', { ago: 25 });
  comment('post', 2, '[Slide 2] Darker sky on this one', { ago: 20 });
  comment('tire_image', 5, 'Approved and answered', { ago: 50 });
  comment('tire_image', 5, 'Done — thanks!', { actor: 'admin', ago: 45 });
  comment('tire_image', 10, 'Joust-only: use the v3 PSD', { actor: 'admin', internal: 1, ago: 5 });
  sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, client_contact_id, created_at)
       VALUES (2, 'post', 8, 'commented', 'client', 'e2e', 'Privacy Bee: logo too small', 3, NOW() - INTERVAL 15 MINUTE)`);
}
function zipList(file) {
  return JSON.parse(execFileSync('php', ['-r', `$z=new ZipArchive;$z->open($argv[1]);$o=[];for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$o[]=[$s['name'],$s['size']];}echo json_encode($o);`, file]).toString());
}
const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
const toastIs = (page, re) => page.waitForFunction((src) => new RegExp(src).test((document.getElementById('uiToast') || {}).textContent || ''), re.source, { timeout: 10000 });
const noSideways = (page) => page.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
async function sheetOpen(page) {
  await page.waitForSelector('#cexpSheet.is-open [data-cexp-form]');
  await page.waitForFunction(() => document.getElementById('cexpSheet').classList.contains('is-visible'));
  await page.waitForFunction(() => /comments? on|No comments match/.test((document.querySelector('[data-cexp-count]') || {}).textContent || ''));
  await page.waitForTimeout(450);   // the slide-in settles
}
const countText = (page) => page.textContent('[data-cexp-count]');
async function recount(page, act) {
  const before = await page.getAttribute('[data-cexp-count]', 'data-comments');
  await act();
  await page.waitForFunction(() => /comments? on|No comments match/.test(document.querySelector('[data-cexp-count]').textContent));
  await page.waitForTimeout(350);
  await page.waitForFunction(() => /comments? on|No comments match/.test(document.querySelector('[data-cexp-count]').textContent));
  return { before, after: await page.getAttribute('[data-cexp-count]', 'data-comments') };
}
const comments = (page) => page.getAttribute('[data-cexp-count]', 'data-comments').then((v) => parseInt(v, 10));

(async () => {
  // -------------------------------------------------------------------------------------------------------------------
  await run('comment export: the sheet + formats (admin)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    const mode = viewport === 'desktop' ? 'dark' : 'light';
    await theme(ctx, mode);

    await test('Manage → Export: the sheet, the live count follows every option', async (page) => {
      mix();
      await page.goto(url('manage.php?client=kenda&section=export'));
      expect(await page.isVisible('[data-manage-cexp-open]'), 'the button on the card');
      await page.click('[data-manage-cexp-open]');
      await sheetOpen(page);
      expect.eq((await page.textContent('#cexpSheet [data-sheet-title]')).trim(), 'Export comments');
      expect(await page.isChecked('[data-cexp-filter="open"]'), 'Open by default');
      expect(!(await page.isChecked('[data-cexp-internal]')), 'internal notes off by default');
      expect.eq(await page.getAttribute('[data-cexp-scope="client"]', 'aria-selected'), 'true', 'this client');
      expect.eq((await page.textContent('[data-cexp-scope="client"]')).trim(), 'Kenda Tires');
      // open: the 5 mix comments + the seeded Needs-changes note on post 4
      expect.eq(await comments(page), 6, 'Open: ' + await countText(page));
      expect(/6 comments on 6 items \(5 images\)/.test(await countText(page)), await countText(page));
      expect(/never exported/.test(await page.textContent('[data-cexp-last]')), 'never exported');
      await page.screenshot({ path: shot(`export-sheet-${w}-${mode}.png`) });
      if (viewport !== 'desktop') {
        // phones: the sheet fits the screen, every control reachable
        const box = await page.$eval('#cexpSheet .ui-sheet', (e) => { const r = e.getBoundingClientRect(); return { l: r.left, r: r.right }; });
        expect(box.l >= -1 && box.r <= 391, 'the sheet fits 390: ' + JSON.stringify(box));
        await page.$eval('[data-cexp-submit]', (e) => e.scrollIntoView({ block: 'center' }));
        await page.waitForTimeout(200);
        await page.screenshot({ path: shot(`export-sheet-${w}-${mode}-bottom.png`) });
      }
      let c = await recount(page, () => page.check('[data-cexp-filter="all"]'));
      expect.eq(c.after, '8', 'All adds the answered thread (2 rows)');
      c = await recount(page, () => page.check('[data-cexp-internal]'));
      expect.eq(c.after, '9', 'internal notes on (the v3 note) — and the answered pair');
      c = await recount(page, () => page.check('[data-cexp-filter="waiting"]'));
      expect.eq(c.after, '7', 'waiting: 5 + seeded + the internal note on a waiting thread');
      await page.uncheck('[data-cexp-internal]');
      c = await recount(page, () => page.click('[data-cexp-scope="all"]'));
      expect.eq(c.after, '7', 'All clients adds Privacy Bee');
      expect(/2 clients/.test(await countText(page)), 'clients in the count: ' + await countText(page));
      // the format: zip shows the image options, the button follows
      await page.click('[data-cexp-format="zip"]');
      expect(await page.isVisible('[data-cexp-zipopts]'), 'image size for the zip');
      expect.eq((await page.textContent('[data-cexp-submit]')).trim(), 'Build zip');
      await page.click('[data-cexp-format="html"]');
      expect(!(await page.isVisible('[data-cexp-zipopts]')), 'no image size for the page');
      expect.eq((await page.textContent('[data-cexp-submit]')).trim(), 'Open printable page');
      // a date range that excludes everything → nothing to export, the button waits
      await page.fill('[data-cexp-to]', '2001-01-01');
      await page.waitForFunction(() => /No comments match/.test(document.querySelector('[data-cexp-count]').textContent));
      expect(await page.isDisabled('[data-cexp-submit]'), 'disabled with nothing to export');
      expect(await noSideways(page), 'no sideways scroll');
    });

    await test('CSV: a UTF-8 download with BOM; "since last export" then reads "last just now"', async (page) => {
      mix();
      await page.goto(url('manage.php?client=kenda&section=export'));
      await page.click('[data-manage-cexp-open]');
      await sheetOpen(page);
      const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 20000 }), page.click('[data-cexp-submit]')]);
      expect(/^kenda-open-comments-\d{4}-\d{2}-\d{2}\.csv$/.test(dl.suggestedFilename()), dl.suggestedFilename());
      const file = path.join(SHOTS, `sample-${viewport}.csv`);
      await dl.saveAs(file);
      const buf = fs.readFileSync(file);
      expect.eq(buf.slice(0, 3).toString('hex'), 'efbbbf', 'BOM');
      const text = buf.toString('utf8');
      expect(text.includes('Render seven: tread too shallow — café 😀'), 'emoji + accents intact');
      expect(text.split('\r\n')[0].startsWith('﻿Client,Type,Tire,Series,Item / file name,Slide,Item status,Redo,Comment,Author'), 'header');
      expect(!text.includes('v3 PSD'), 'no internal note');
      expect(!text.includes('Privacy Bee'), 'one client only');
      await toastIs(page, /Downloading 6 comments as CSV/);
      expect(/last just now/.test(await page.textContent('[data-cexp-last]')), 'last just now');
      expect(sql(`SELECT v FROM meta WHERE k = 'comment_export_last_1'`).length === 1, 'stamped');
      // since last export → nothing new
      await recount(page, () => page.check('[data-cexp-since]'));
      expect.eq(await comments(page), 0, 'nothing new since');
    });

    await test('Zip with images: progress, then the zip (Client/Tire/Series + .txt, comments.csv, summary.html)', async (page) => {
      mix();
      await page.goto(url('manage.php?client=kenda&section=export'));
      await page.click('[data-manage-cexp-open]');
      await sheetOpen(page);
      await page.click('[data-cexp-format="zip"]');
      await page.check('[data-cexp-filter="all"]');
      await page.waitForTimeout(400);
      const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), page.click('[data-cexp-submit]')]);
      expect(/^kenda-comments-\d{4}-\d{2}-\d{2}\.zip$/.test(dl.suggestedFilename()), dl.suggestedFilename());
      const file = path.join(SHOTS, `sample-${viewport}.zip`);
      await dl.saveAs(file);
      const list = zipList(file), names = list.map((e) => e[0]);
      ['Kenda Tires/Klever AT2/Series 1/render_07.jpg', 'Kenda Tires/Klever AT2/Series 1/render_07.txt', 'Kenda Tires/Klever AT2/Series 1/render_02.jpg',
       'Kenda Tires/Klever AT2/Series 1/_series-comments.txt', 'Kenda Tires/Klever AT2/Reference/ref_klever-at2_2.jpg', 'Kenda Tires/Library/lib_07.jpg',
       'Kenda Tires/Posts/AT2 carousel (post 2)/Slide 2 - img_post02_2.jpg', 'comments.csv', 'summary.html'].forEach((n) => expect(names.includes(n), 'zip has ' + n + ' — ' + names.join(' | ')));
      expect(/Ready/.test(await page.textContent('[data-cexp-progress-text]')), 'progress done');
      await toastIs(page, /Downloading the zip/);
      if (viewport === 'desktop') {
        fs.writeFileSync(path.join(SHOTS, 'sample-zip-listing.txt'), list.map((e) => String(e[1]).padStart(8) + '  ' + e[0]).join('\n') + '\n');
        await page.screenshot({ path: shot(`export-sheet-zip-${w}-${mode}.png`) });
      }
    });

    await test('Printable: a new tab with one block per image', async (page) => {
      mix();
      await page.goto(url('manage.php?client=kenda&section=export'));
      await page.click('[data-manage-cexp-open]');
      await sheetOpen(page);
      await page.click('[data-cexp-format="html"]');
      const [tab] = await Promise.all([ctx.waitForEvent('page'), page.click('[data-cexp-submit]')]);
      await tab.waitForLoadState();
      await tab.waitForSelector('[data-cexp-summary]');
      expect.eq(await tab.$$eval('[data-cexp-block]', (b) => b.length), 6, 'blocks');
      expect((await tab.textContent('body')).includes('Render seven: tread too shallow'), 'the comments');
      await tab.waitForFunction(() => Array.prototype.every.call(document.images, (i) => i.complete && i.naturalWidth > 0), null, { timeout: 15000 });
      expect.eq(await tab.$$eval('img', (i) => i.length), 5, 'a thumbnail per image (the series has none)');
      if (viewport === 'desktop') await tab.screenshot({ path: shot(`printable-${w}.png`), fullPage: false });
      await tab.close();
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  await run('comment export: the entry points (admin)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    const mode = viewport === 'desktop' ? 'dark' : 'light';
    await theme(ctx, mode);
    const opens = async (page, scope) => {
      await sheetOpen(page);
      expect(await page.isVisible('#cexpSheet [data-cexp-form]'), 'the sheet');
      expect.eq(await page.getAttribute('#cexpSheet [data-cexp-form]', 'data-scope'), scope, 'scope');
      await page.click('#cexpSheet [data-sheet-close]');
      await page.waitForFunction(() => !document.getElementById('cexpSheet').classList.contains('is-open'));
    };
    const tap = async (page, sel) => {
      await page.waitForSelector(sel, { state: 'visible' });
      const box = await page.$eval(sel, (e) => { e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(); return { w: r.width, h: r.height, r: r.right }; });
      expect(box.w >= 28 && box.h >= 28, sel + ' is tappable: ' + JSON.stringify(box));
      expect(box.r <= (viewport === 'desktop' ? 1440 : 390) + 1, sel + ' on screen');
      await page.click(sel);
    };

    await test('Manage → Export: one client, and every client unscoped', async (page) => {
      await page.goto(url('manage.php?section=export'));
      await tap(page, '[data-manage-cexp-open]');
      await opens(page, 'all');
      await page.goto(url('manage.php?client=kenda&section=export'));
      await tap(page, '[data-manage-cexp-open]');
      await opens(page, 'client');
      expect(await noSideways(page), 'no sideways scroll');
    });

    await test('the Redo page', async (page) => {
      await page.goto(url('redo.php?client=kenda'));
      await tap(page, '[data-redo-comments]');
      await opens(page, 'client');
      await page.goto(url('redo.php?client=kenda&all=1'));
      await tap(page, '[data-redo-comments]');
      await opens(page, 'all');
      if (viewport !== 'desktop') expect(await noSideways(page), 'no sideways scroll on the Redo page');
      await page.screenshot({ path: shot(`entry-redo-${w}-${mode}.png`) });
    });

    await test('Assets: the Library ⋯ menu, the Tires list ⋯ menu, the series ⋯ menu', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=library'));
      await tap(page, '[data-assets-tools] [data-asg-menu-toggle]');
      await page.waitForSelector('[data-assets-tools] [data-asg-menu]:not([hidden])');
      if (viewport !== 'desktop') await page.screenshot({ path: shot(`entry-library-menu-${w}-${mode}.png`) });
      await page.click('[data-assets-cexp]');
      await opens(page, 'client');
      expect(await page.isHidden('[data-assets-tools] [data-asg-menu]'), 'the menu closed');
      await page.goto(url('assets.php?client=kenda&view=collections'));
      await tap(page, '[data-assets-tools] [data-asg-menu-toggle]');
      await page.click('[data-assets-cexp]');
      await opens(page, 'client');
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1'));
      await tap(page, '[data-series-menu]');
      await page.waitForSelector('[data-series-menu-list]:not([hidden])');
      await page.click('[data-series-cexp]');
      await sheetOpen(page);
      expect(await page.isHidden('[data-series-menu-list]'), 'the series menu closed');
      expect(await noSideways(page), 'no sideways scroll');
    });

    await test('the Inbox (one client, every client)', async (page) => {
      await page.goto(url('inbox.php'));
      await tap(page, '[data-inbox-export]');
      await opens(page, 'all');
      await page.goto(url('inbox.php?client=kenda'));
      await tap(page, '[data-inbox-export]');
      await opens(page, 'client');
      if (viewport !== 'desktop') expect(await noSideways(page), 'no sideways scroll on the Inbox');
      await page.screenshot({ path: shot(`entry-inbox-${w}-${mode}.png`) });
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'] });

  // -------------------------------------------------------------------------------------------------------------------
  await run('comment export: never for the client', async ({ test, expect }) => {
    await test('no entry point, no sheet, the endpoint says 403', async (page) => {
      for (const p of ['assets.php?client=kenda&view=library', 'assets.php?client=kenda&view=collections&item=1&series=1', 'index.php?client=kenda']) {
        await page.goto(url(p));
        expect.eq(await page.$$eval('[data-cexp-open], [data-assets-tools]', (e) => e.length), 0, p);
        expect(await page.evaluate(() => !window.CommentExportConfig && !(window.App && window.App.commentExport)), 'no export script: ' + p);
      }
      const code = await page.evaluate((u) => fetch(u, { credentials: 'same-origin' }).then((r) => r.status), url('comment-export.php?action=csv&client=kenda'));
      expect.eq(code, 403, 'csv');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'] });
})();
