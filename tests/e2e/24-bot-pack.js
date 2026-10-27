/* "Export open comments" → the Bot pack and its "Export N open" buttons in the browser (tests/smoke/29-bot-pack.php
   covers the server side):
   - the buttons: a Tires list row per tire ("Export N open"), the tire header ("Export open comments (N)"), the series
     head + the Reference card ("Export (N)"), the Library toolbar, the Redo page (per client + per tire), the Inbox (per
     client) — each count = the sheet's count; none where nothing is open
   - a button opens the sheet scoped (Kenda Tires › Klever AT2 › …) on "Bot pack (ZIP)" with the big "Download bot pack";
     the download is a zip named kenda-klever-at2-open-comments-<date>.zip with manifest.json, comments.csv,
     REVIEW_INSTRUCTIONS.md and the images + .comments.md of that tire only; "Whole client" widens it; the client sheet
     lists one pack per tire, each its own download
   - phones (390 / 320): the buttons collapse to the icon + the number, stay on screen and tappable; no sideways scroll
   - the client seat: none of it
   Screenshots (the Tires list, a tire page, the series rows, the sheet — 1440 dark, 390 light) + a sample pack go to
   $BOTPACK_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/botpack). */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run, url } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = process.env.BOTPACK_SHOTS_DIR || path.join(ROOT, 'shots', 'botpack');
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
/** Klever AT2 (Series 1 render + the series + Series 2 + a reference), Klever RT, the Library, a carousel slide, Privacy Bee. */
function mix() {
  comment('tire_image', 10, 'Render seven: tread too shallow — café 😀', { ago: 40 });
  comment('tire_image', 11, 'Render eight: sidewall letters too thin', { ago: 39 });
  comment('tire_series', 1, 'The whole series reads too warm', { ago: 38 });
  comment('tire_image', 13, 'Series two render two: logo blurry', { ago: 37 });
  comment('tire_image', 2, 'Reference two is the old sidewall', { ago: 36 });
  comment('tire_image', 21, 'Klever RT render two: wrong rim', { ago: 35 });
  comment('library_image', 7, 'Library seven: crop tighter', { ago: 30 });
  comment('post', 2, '[Slide 2] Darker sky on this one', { ago: 25 });
  comment('tire_image', 10, 'On it — v2 tomorrow', { actor: 'admin', ago: 20 });
  comment('tire_image', 10, 'Joust-only: use the v3 PSD', { actor: 'admin', internal: 1, ago: 5 });
  comment('post', 8, 'Privacy Bee: logo too small', { company: 2, ago: 15 });
}
function zipList(file) {
  return JSON.parse(execFileSync('php', ['-r', `$z=new ZipArchive;$z->open($argv[1]);$o=[];for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$o[]=[$s['name'],$s['size']];}echo json_encode($o);`, file]).toString());
}
function zipRead(file, name) {
  return execFileSync('php', ['-r', `$z=new ZipArchive;$z->open($argv[1]);echo $z->getFromName($argv[2]);`, file, name]).toString();
}
const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
const noSideways = (page) => page.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
async function sheetOpen(page) {
  await page.waitForSelector('#cexpSheet.is-open [data-cexp-form]');
  await page.waitForFunction(() => document.getElementById('cexpSheet').classList.contains('is-visible'));
  await page.waitForFunction(() => /comments? on|No comments match/.test((document.querySelector('[data-cexp-count]') || {}).textContent || ''));
  await page.waitForTimeout(450);
}
const sheetCount = (page) => page.getAttribute('#cexpSheet [data-cexp-count]', 'data-comments').then((v) => parseInt(v, 10));
const btnCount = (page, sel) => page.getAttribute(sel, 'data-cexp-open-count').then((v) => parseInt(v, 10));
/** every <img> on screen loaded (lazy previews), so the screenshots show the thumbnails */
async function imgsLoaded(page) {
  await page.waitForFunction(() => Array.prototype.every.call(document.images, (i) => {
    const r = i.getBoundingClientRect();
    return r.bottom < 0 || r.top > innerHeight || r.width === 0 || (i.complete && i.naturalWidth > 0);
  }), null, { timeout: 15000 }).catch(() => {});
}
async function closeSheet(page) {
  await page.click('#cexpSheet [data-sheet-close]');
  await page.waitForFunction(() => !document.getElementById('cexpSheet').classList.contains('is-open'));
}

(async () => {
  // -------------------------------------------------------------------------------------------------------------------
  await run('bot pack: the buttons + the sheet (admin)', async ({ test, expect, viewport, ctx }) => {
    const desktop = viewport === 'desktop';
    const W = { desktop: 1440, phone: 390, w320: 320 }[viewport];
    const mode = desktop ? 'dark' : 'light';
    await theme(ctx, mode);
    /** visible, on screen, tappable; phones: the icon + the number only */
    const checkBtn = async (page, sel) => {
      await page.waitForSelector(sel, { state: 'visible' });
      const b = await page.$eval(sel, (e) => {
        e.scrollIntoView({ block: 'center' });
        const r = e.getBoundingClientRect();
        const texts = Array.prototype.map.call(e.querySelectorAll('.cexp-btn-text'), (t) => getComputedStyle(t).display);
        return { w: r.width, h: r.height, l: r.left, r: r.right, n: (e.querySelector('[data-cexp-n]') || {}).textContent, texts, label: e.textContent.trim() };
      });
      expect(b.w >= 28 && b.h >= 28, sel + ' tappable: ' + JSON.stringify(b));
      expect(b.l >= -1 && b.r <= W + 1, sel + ' on screen: ' + JSON.stringify(b));
      if (desktop) expect(b.texts.every((d) => d !== 'none'), sel + ' shows its words: ' + JSON.stringify(b));
      else expect(b.texts.every((d) => d === 'none'), sel + ' collapses to the icon + count: ' + JSON.stringify(b));
      return b;
    };

    await test('Tires list: "Export N open" per tire (none at 0) → the sheet on the Bot pack, scoped to the tire', async (page) => {
      mix();
      await page.goto(url('assets.php?client=kenda&view=collections'));
      const b1 = await checkBtn(page, '[data-tire-cexp="1"]');
      if (desktop) expect(/^Export \d+ open$/.test(b1.label), 'label: ' + b1.label);
      await checkBtn(page, '[data-tire-cexp="2"]');
      expect.eq(await page.$$eval('[data-tire-cexp="3"]', (e) => e.length), 0, 'Kenetica Sport: nothing open, no button');
      expect(await noSideways(page), 'no sideways scroll');
      await imgsLoaded(page);
      await page.screenshot({ path: shot(`tires-list-${W}-${mode}.png`) });
      const n = await btnCount(page, '[data-tire-cexp="1"]');
      await page.click('[data-tire-cexp="1"]');
      await sheetOpen(page);
      expect.eq(await sheetCount(page), n, 'the sheet counts what the button said');
      expect.eq(await page.getAttribute('#cexpSheet [data-cexp-format="botpack"]', 'aria-selected'), 'true', 'Bot pack preselected');
      expect.eq((await page.textContent('[data-cexp-submit]')).trim(), 'Download bot pack');
      expect(await page.$eval('[data-cexp-submit]', (e) => e.classList.contains('cexp-submit--big') && e.getBoundingClientRect().height >= 44), 'the big primary button');
      expect.eq((await page.textContent('[data-cexp-path-text]')).trim(), 'Kenda Tires › Klever AT2');
      expect(/kenda-klever-at2-open-comments-\d{4}-\d{2}-\d{2}\.zip/.test(await page.textContent('[data-cexp-file]')), 'the zip name: ' + await page.textContent('[data-cexp-file]'));
      // one pack per series of this tire
      await page.waitForSelector('[data-cexp-parts]:not([hidden])');
      expect.eq(await page.$$eval('[data-cexp-part-row]', (r) => r.map((x) => x.querySelector('.cexp-part-name').textContent)).then((a) => a.join(',')), 'Reference,Series 1,Series 2');
      expect(!(await page.isChecked('[data-cexp-internal]')), 'internal notes off');
      await imgsLoaded(page);
      await page.screenshot({ path: shot(`sheet-tire-${W}-${mode}.png`) });
      if (!desktop) {
        const box = await page.$eval('#cexpSheet .ui-sheet', (e) => { const r = e.getBoundingClientRect(); return { l: r.left, r: r.right }; });
        expect(box.l >= -1 && box.r <= W + 1, 'the sheet fits: ' + JSON.stringify(box));
        await page.$eval('[data-cexp-parts]', (e) => e.scrollIntoView({ block: 'end' }));
        await page.waitForTimeout(200);
        await imgsLoaded(page);
        await page.screenshot({ path: shot(`sheet-tire-${W}-${mode}-bottom.png`) });
      }
      // "Whole client" widens it: every tire, the Library, Posts
      await page.click('[data-cexp-widen]');
      await page.waitForFunction((x) => parseInt(document.querySelector('[data-cexp-count]').getAttribute('data-comments'), 10) > x, n);
      await page.waitForFunction(() => document.querySelectorAll('[data-cexp-part-row]').length === 4);
      expect.eq(await page.$$eval('.cexp-part-name', (r) => r.map((x) => x.textContent).join(',')), 'Klever AT2,Klever RT,Library,Posts');
    });

    await test('Download bot pack: the tire only — images + .comments.md, manifest.json, comments.csv, REVIEW_INSTRUCTIONS.md', async (page) => {
      mix();
      await page.goto(url('assets.php?client=kenda&view=collections'));
      await page.click('[data-tire-cexp="1"]');
      await sheetOpen(page);
      const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), page.click('[data-cexp-submit]')]);
      expect(/^kenda-klever-at2-open-comments-\d{4}-\d{2}-\d{2}\.zip$/.test(dl.suggestedFilename()), dl.suggestedFilename());
      const file = path.join(SHOTS, `sample-${viewport}.zip`);
      await dl.saveAs(file);
      const names = zipList(file).map((e) => e[0]);
      ['manifest.json', 'comments.csv', 'REVIEW_INSTRUCTIONS.md', 'Kenda Tires/Klever AT2/Series 1/render_07.jpg', 'Kenda Tires/Klever AT2/Series 1/render_07.comments.md',
       'Kenda Tires/Klever AT2/Series 1/_series.comments.md', 'Kenda Tires/Klever AT2/Reference/ref_klever-at2_2.jpg', 'Kenda Tires/Klever AT2/Series 2/render_02.jpg']
        .forEach((n) => expect(names.includes(n), 'pack has ' + n + ' — ' + names.join(' | ')));
      expect(names.every((n) => !/Klever RT|Library|Posts/.test(n)), 'only Klever AT2: ' + names.join(' | '));
      const m = JSON.parse(zipRead(file, 'manifest.json'));
      expect.eq(m.schema_version, 1);
      expect.eq(m.scope.tire.name, 'Klever AT2');
      m.items.forEach((it) => {
        if (it.path) expect(names.includes(it.path), 'manifest path in the zip: ' + it.path);
        expect(names.includes(it.comments_path), 'comments_path in the zip: ' + it.comments_path);
      });
      expect(!zipRead(file, 'manifest.json').includes('v3 PSD'), 'no internal note');
      expect(/Downloading the bot pack/.test(await page.textContent('#uiToast')), 'toast');
      expect(/Ready — kenda-klever-at2-open-comments/.test(await page.textContent('[data-cexp-progress-text]')), 'progress names the file');
    });

    await test('the sheet: one pack per tire, each its own download (Inbox, per client)', async (page) => {
      mix();
      await page.goto(url('inbox.php'));
      await checkBtn(page, '[data-inbox-client-cexp="kenda"]').catch(async (e) => {
        // the per-client Inbox buttons keep the client name on phones (cexp-btn--keep): only on screen + tappable then
        if (desktop) throw e;
        const b = await page.$eval('[data-inbox-client-cexp="kenda"]', (x) => { const r = x.getBoundingClientRect(); return { w: r.width, h: r.height, r: r.right }; });
        expect(b.w >= 28 && b.h >= 28 && b.r <= W + 1, 'Inbox Kenda button: ' + JSON.stringify(b));
      });
      expect.eq(await page.$$eval('[data-inbox-client-cexp="hmf"]', (e) => e.length), 0, 'Hollow Mill Farm: nothing open');
      expect(await noSideways(page), 'no sideways scroll on the Inbox');
      await imgsLoaded(page);
      if (desktop || viewport === 'phone') await page.screenshot({ path: shot(`inbox-${W}-${mode}.png`) });
      const n = await btnCount(page, '[data-inbox-client-cexp="kenda"]');
      await page.click('[data-inbox-client-cexp="kenda"]');
      await sheetOpen(page);
      expect.eq(await sheetCount(page), n, 'the count');
      expect.eq(await page.getAttribute('[data-cexp-scope="client"]', 'aria-selected'), 'true', 'this client');
      expect.eq((await page.textContent('[data-cexp-scope="client"]')).trim(), 'Kenda Tires');
      await page.waitForSelector('[data-cexp-parts]:not([hidden])');
      expect.eq((await page.textContent('[data-cexp-parts-title]')).trim(), 'Or one pack per tire');
      const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), page.click('[data-cexp-part-row="tires:2:"] [data-cexp-part]')]);
      expect(/^kenda-klever-rt-open-comments-\d{4}-\d{2}-\d{2}\.zip$/.test(dl.suggestedFilename()), dl.suggestedFilename());
      const file = path.join(SHOTS, `part-${viewport}.zip`);
      await dl.saveAs(file);
      const names = zipList(file).map((e) => e[0]);
      expect(names.includes('Kenda Tires/Klever RT/Series 1/render_02.jpg') && names.every((n) => !/Klever AT2/.test(n)), 'Klever RT only: ' + names.join(' | '));
      fs.unlinkSync(file);
    });

    await test('a tire page: the header, the series head, the Reference card; the Library toolbar', async (page) => {
      mix();
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1'));
      const t = await checkBtn(page, '[data-tire-page-cexp]');
      if (desktop) expect(/^Export open comments \(\d+\)$/.test(t.label), t.label);
      const s = await checkBtn(page, '[data-series-head-cexp="1"]');
      if (desktop) expect(/^Export \(\d+\)$/.test(s.label), s.label);
      await checkBtn(page, '[data-ref-cexp]');
      expect(await noSideways(page), 'no sideways scroll');
      await page.evaluate(() => window.scrollTo(0, 0));
      await imgsLoaded(page);
      await page.screenshot({ path: shot(`tire-page-${W}-${mode}.png`) });
      await page.$eval('[data-series-head]', (e) => e.scrollIntoView({ block: 'start' }));
      await page.waitForTimeout(150);
      await imgsLoaded(page);
      await page.screenshot({ path: shot(`series-head-${W}-${mode}.png`) });
      // the series button: scoped to the series, no per-part list
      const ns = await btnCount(page, '[data-series-head-cexp="1"]');
      await page.click('[data-series-head-cexp="1"]');
      await sheetOpen(page);
      expect.eq(await sheetCount(page), ns, 'series count');
      expect.eq((await page.textContent('[data-cexp-path-text]')).trim(), 'Kenda Tires › Klever AT2 › Series 1');
      expect(await page.isHidden('[data-cexp-parts]'), 'no parts inside one series');
      await closeSheet(page);
      const nr = await btnCount(page, '[data-ref-cexp]');
      await page.click('[data-ref-cexp]');
      await sheetOpen(page);
      expect.eq(await sheetCount(page), nr, 'reference count');
      expect.eq((await page.textContent('[data-cexp-path-text]')).trim(), 'Kenda Tires › Klever AT2 › Reference');
      await closeSheet(page);
      // a tire with nothing open: no buttons
      await page.goto(url('assets.php?client=kenda&view=collections&item=3'));
      expect.eq(await page.$$eval('[data-cexp-pack]', (e) => e.length), 0, 'Kenetica Sport: none');
      // the Library toolbar
      await page.goto(url('assets.php?client=kenda&view=library'));
      await checkBtn(page, '[data-library-cexp]');
      const nl = await btnCount(page, '[data-library-cexp]');
      await page.click('[data-library-cexp]');
      await sheetOpen(page);
      expect.eq(await sheetCount(page), nl, 'library count');
      expect.eq((await page.textContent('[data-cexp-path-text]')).trim(), 'Kenda Tires › Library');
      expect(await noSideways(page), 'no sideways scroll on the Library');
      await imgsLoaded(page);
      if (desktop || viewport === 'phone') await page.screenshot({ path: shot(`sheet-library-${W}-${mode}.png`) });
    });

    await test('the Redo page: a bar per client and per tire', async (page) => {
      mix();
      sql(`UPDATE tire_images SET redo_at = NOW() WHERE id IN (10, 21)`);
      sql(`UPDATE library_images SET redo_at = NOW() WHERE id = 7`);
      await page.goto(url('redo.php?client=kenda&all=1'));
      await checkBtn(page, '[data-redo-client-cexp="kenda"]');
      await checkBtn(page, '[data-redo-tire-cexp="1"]');
      await checkBtn(page, '[data-redo-tire-cexp="2"]');
      await checkBtn(page, '[data-redo-library-cexp="kenda"]');
      expect(await noSideways(page), 'no sideways scroll on the Redo page');
      await page.evaluate(() => window.scrollTo(0, 0));
      await imgsLoaded(page);
      if (desktop || viewport === 'phone') await page.screenshot({ path: shot(`redo-${W}-${mode}.png`), fullPage: true });
      const n = await btnCount(page, '[data-redo-tire-cexp="2"]');
      await page.click('[data-redo-tire-cexp="2"]');
      await sheetOpen(page);
      expect.eq(await sheetCount(page), n);
      expect.eq((await page.textContent('[data-cexp-path-text]')).trim(), 'Kenda Tires › Klever RT');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone', 'w320'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  await run('bot pack: never for the client', async ({ test, expect }) => {
    await test('no button, no sheet; the endpoint says 403', async (page) => {
      mix();
      for (const p of ['assets.php?client=kenda&view=collections', 'assets.php?client=kenda&view=collections&item=1&series=1', 'assets.php?client=kenda&view=library']) {
        await page.goto(url(p));
        expect.eq(await page.$$eval('[data-cexp-pack], .cexp-btn, [data-cexp-open]', (e) => e.length), 0, p);
        expect(await page.evaluate(() => !window.CommentExportConfig), 'no export config: ' + p);
      }
      const code = await page.evaluate((u) => fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=zip_start&pack=bot&client=kenda&tire=1' }).then((r) => r.status), url('comment-export.php'));
      expect.eq(code, 403, 'zip_start');
    });
  }, { role: 'client', viewports: ['desktop', 'phone'], reseed: 'test' });
})();
