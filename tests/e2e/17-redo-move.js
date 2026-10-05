/* The Redo queue + Move to tire in the browser (tests/smoke/22-redo-move.php covers the server side):
   - viewer ⋯ "Mark for redo…" opens the action sheet ABOVE the viewer, the note is saved, the tile gets the Redo pill,
     the menu then offers "Remove from redo"; the select bar marks several at once; the Assets "Redo" chip counts
   - Replace on a queued Library image (viewer ⋯ Replace) → back to To Review, the pill goes
   - the Redo view: rows, Remove, "Export redo pack" (a zip download with Client/Tire/Series/… + .txt + redo-index.csv),
     "Replace from folder" (pick files → the report sheet)
   - "Move to tire…" from the viewer (an existing series) and from the select bar ("New series…"), the toast links to the series
   - the client sees "Being reworked" (tile + viewer), never the note, never the admin actions
   - View as client: the banner says comments count as the client's
   Screenshots (the Redo view, the mark sheet, the move sheet — 1440 dark, 390 light) go to $REDO_SHOTS_DIR
   (default $PORTAL_TEST_ROOT/shots/redo). */
'use strict';
const path = require('path');
const fs = require('fs');
const os = require('os');
const { execFileSync } = require('child_process');
const { run, url } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const MEDIA = process.env.MEDIA_DIR || path.join(ROOT, 'site/media');
const SHOTS = process.env.REDO_SHOTS_DIR || path.join(ROOT, 'shots', 'redo');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name) => path.join(SHOTS, name);

function sql(query, params) {
  const php = `$p=new PDO('mysql:host=localhost;dbname='.getenv('PORTAL_TEST_DB').';charset=utf8mb4',getenv('PORTAL_TEST_DB_USER'),getenv('PORTAL_TEST_DB_PASS'));`
    + `$p->exec("SET time_zone = '".(new DateTime('now', new DateTimeZone('America/New_York')))->format('P')."'");`
    + `$s=$p->prepare($argv[1]);$s->execute(json_decode($argv[2],true));echo json_encode($s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):[]);`;
  const env = Object.assign({ PORTAL_TEST_DB: 'portal_test', PORTAL_TEST_DB_USER: 'portal_test', PORTAL_TEST_DB_PASS: 'portal_test' }, process.env);
  return JSON.parse(execFileSync('php', ['-r', php, query, JSON.stringify(params || [])], { env }).toString() || '[]');
}
/** A small JPEG with an exact name (GD through php -r). */
function jpeg(name, label) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'redo-e2e-'));
  const file = path.join(dir, name);
  execFileSync('php', ['-r', `$i=imagecreatetruecolor(480,360);imagefill($i,0,0,imagecolorallocate($i,40,120,90));imagestring($i,5,20,20,$argv[2],imagecolorallocate($i,255,255,255));imagejpeg($i,$argv[1],85);`, file, label || name]);
  return file;
}
/** The names inside a zip (php ZipArchive). */
function zipList(file) {
  return JSON.parse(execFileSync('php', ['-r', `$z=new ZipArchive;$z->open($argv[1]);$o=[];for($i=0;$i<$z->numFiles;$i++)$o[]=$z->getNameIndex($i);echo json_encode($o);`, file]).toString());
}
const queued = (table, id) => sql(`SELECT redo_at, redo_note, status FROM ${table} WHERE id = ?`, [id])[0];
const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
async function openViewerOn(page, sel) {
  await page.click(sel);
  await page.waitForSelector('[data-viewer]:not([hidden])');
  await page.waitForFunction(() => document.querySelector('[data-viewer]').classList.contains('is-visible'));
}
async function openMore(page) {
  await page.click('[data-viewer-more]');
  await page.waitForSelector('[data-viewer-menu]:not([hidden])');
}
async function sheetOpen(page) {
  await page.waitForFunction(() => { const s = document.getElementById('asActionSheet'); return s && s.classList.contains('is-visible'); });
  await page.waitForTimeout(400);   // the slide-in settles before a screenshot
}

(async () => {
  // -------------------------------------------------------------------------------------------------------------------
  await run('redo: mark, remove, replace (admin)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    await theme(ctx, viewport === 'desktop' ? 'dark' : 'light');

    await test('viewer ⋯ Mark for redo… → the sheet above the viewer, note saved, Redo pill, then Remove from redo', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=library&filter=approved'));
      await openViewerOn(page, '#lib-1');
      await openMore(page);
      expect(await page.isVisible('[data-viewer-redo]'), 'Mark for redo… in the menu');
      expect(!(await page.isVisible('[data-viewer-unredo]')), 'not Remove yet');
      expect(await page.isVisible('[data-viewer-move]'), 'Move to tire… for a library image');
      await page.click('[data-viewer-redo]');
      await sheetOpen(page);
      // the sheet is on top of the viewer: the element at the centre of the form is inside the sheet
      const onTop = await page.evaluate(() => { const f = document.querySelector('#asActionSheet [data-redo-form]'); const r = f.getBoundingClientRect(); const el = document.elementFromPoint(r.left + r.width / 2, r.top + 10); return !!(el && el.closest('#asActionSheet')); });
      expect(onTop, 'sheet above the viewer');
      await page.fill('[data-redo-note]', 'Sky is blown out — redo the grade');
      await page.screenshot({ path: shot(`mark-sheet-${w}-${viewport === 'desktop' ? 'dark' : 'light'}.png`) });
      await page.click('[data-redo-submit]');
      await page.waitForFunction(() => /Marked for redo/.test((document.getElementById('uiToast') || {}).textContent || ''));
      const row = queued('library_images', 1);
      expect(row.redo_at, 'queued'); expect.eq(row.redo_note, 'Sky is blown out — redo the grade'); expect.eq(row.status, 'approved', 'status untouched');
      expect.eq(await page.getAttribute('#lib-1', 'data-redo'), '1', 'tile flagged');
      expect(await page.isVisible('[data-viewer-redo-pill]') || viewport !== 'desktop', 'viewer header pill (desktop)');
      await openMore(page);
      expect(await page.isVisible('[data-viewer-unredo]'), 'now: Remove from redo');
      await page.click('[data-viewer-unredo]');
      await page.waitForFunction(() => /Removed from redo/.test((document.getElementById('uiToast') || {}).textContent || ''));
      expect(!queued('library_images', 1).redo_at, 'off the queue');
      expect.eq(await page.getAttribute('#lib-1', 'data-redo'), null, 'tile unflagged');
    });

    await test('select bar: Mark for redo on several tiles, the Redo chip counts', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved'));
      expect.eq((await page.textContent('[data-redo-chip-count]')).trim(), '0');
      await page.click('[data-assets-select]');
      await page.click('#image-4'); await page.click('#image-5');
      expect(await page.isEnabled('[data-select-redo]'), 'enabled');
      expect(!(await page.$('[data-select-move]')), 'no Move on a tire grid');
      await page.click('[data-select-redo]');
      await sheetOpen(page);
      expect.eq((await page.textContent('[data-redo-submit]')).trim(), 'Mark 2 for redo');
      await page.click('[data-redo-submit]');
      await page.waitForFunction(() => /2 marked for redo/.test((document.getElementById('uiToast') || {}).textContent || ''));
      expect.eq(await page.getAttribute('#image-4', 'data-redo'), '1'); expect.eq(await page.getAttribute('#image-5', 'data-redo'), '1');
      expect.eq((await page.textContent('[data-redo-chip-count]')).trim(), '2', 'chip');
      expect(await page.isVisible('#image-4 [data-thumb-redo]'), 'pill on the tile');
      expect(queued('tire_images', 4).redo_at && queued('tire_images', 5).redo_at, 'both queued');
    });

    await test('viewer ⋯ Replace on a queued library image → back to To Review', async (page) => {
      sql(`UPDATE library_images SET redo_at = NOW(), redo_note = 'Brighter' WHERE id = 7`);
      await page.goto(url('assets.php?client=kenda&view=library&filter=pending'));
      expect.eq(await page.getAttribute('#lib-7', 'data-redo'), '1');
      await openViewerOn(page, '#lib-7');
      await page.setInputFiles('[data-viewer-replace-input]', jpeg('fixed.jpg', 'fixed 7'));
      await page.waitForFunction(() => /back to To Review/.test((document.getElementById('uiToast') || {}).textContent || ''), null, { timeout: 15000 });
      const row = queued('library_images', 7);
      expect(!row.redo_at, 'off the queue'); expect.eq(row.status, 'pending');
      expect.eq(await page.getAttribute('#lib-7', 'data-redo'), null);
      expect(!(await page.isVisible('[data-viewer-redo-pill]')), 'the pill went');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  await run('redo: the Redo view (admin)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    await theme(ctx, viewport === 'desktop' ? 'dark' : 'light');
    const seedQueue = () => {
      sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, client_contact_id, created_at)
           VALUES (1, 'tire_image', 9, 'commented', 'client', 'Comment', 'The tread blocks look melted on the left', 1, NOW() - INTERVAL 3 HOUR)`);
      sql(`UPDATE tire_images SET redo_at = NOW() - INTERVAL 3 HOUR WHERE id IN (9, 17)`);
      sql(`UPDATE tire_images SET redo_at = NOW() - INTERVAL 2 DAY, redo_note = 'Wrong rim colour — should be matte black', redo_by = 1 WHERE id = 6`);
      sql(`UPDATE library_images SET redo_at = NOW() - INTERVAL 30 MINUTE, redo_note = 'Crop tighter on the logo', redo_by = 1 WHERE id = 5`);
    };

    await test('rows, feedback, note, age, Remove', async (page) => {
      seedQueue();
      await page.goto(url('redo.php?client=kenda'));
      await page.waitForSelector('[data-redo-row="tire:9"]');
      expect.eq((await page.textContent('[data-redo-count]')).trim(), '4');
      expect(/melted on the left/.test(await page.textContent('[data-redo-row="tire:9"]')), 'client feedback');
      expect(/matte black/.test(await page.textContent('[data-redo-row="tire:6"]')), 'the note');
      await page.evaluate(() => document.querySelectorAll('.rd-thumb img').forEach((i) => { i.loading = 'eager'; }));
      await page.waitForLoadState('networkidle');
      await page.screenshot({ path: shot(`redo-view-${w}-${viewport === 'desktop' ? 'dark' : 'light'}.png`), fullPage: true });
      const box = await page.$eval('[data-redo-page]', (el) => ({ sw: document.documentElement.scrollWidth, vw: window.innerWidth }));
      expect(box.sw <= box.vw + 1, 'no horizontal overflow');
      await page.click('[data-redo-row="tire:17"] [data-redo-remove]');
      await page.waitForFunction(() => !document.querySelector('[data-redo-row="tire:17"]'));
      expect.eq((await page.textContent('[data-redo-count]')).trim(), '3');
      expect(!queued('tire_images', 17).redo_at, 'cleared');
    });

    await test('Export redo pack → a zip with Client/Tire/Series/<file> + .txt + redo-index.csv', async (page) => {
      seedQueue();
      await page.goto(url('redo.php?client=kenda'));
      const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), page.click('[data-redo-export]')]);
      expect(/^kenda-redo-pack-\d{4}-\d{2}-\d{2}\.zip$/.test(dl.suggestedFilename()), dl.suggestedFilename());
      const file = path.join(ROOT, 'e2e-redo-pack.zip');
      await dl.saveAs(file);
      const names = zipList(file);
      for (const n of ['Kenda Tires/Klever AT2/Series 1/render_06.jpg', 'Kenda Tires/Klever AT2/Series 1/render_06.txt', 'Kenda Tires/Klever AT2/Series 2/render_06.jpg',
                       'Kenda Tires/Klever AT2/Series 1/render_03.jpg', 'Kenda Tires/Library/lib_05.jpg', 'Kenda Tires/Library/lib_05.txt', 'redo-index.csv']) {
        expect(names.includes(n), n + ' in ' + names.join(', '));
      }
      await page.waitForSelector('[data-redo-row="tire:9"] [data-redo-exported]');
      expect(sql(`SELECT COUNT(*) AS n FROM tire_images WHERE redo_exported_at IS NOT NULL`)[0].n >= 3, 'stamped');
    });

    await test('Replace from folder (pick files) → the report sheet; the matched row leaves', async (page) => {
      seedQueue();
      await page.goto(url('redo.php?client=kenda'));
      await page.setInputFiles('[data-redo-files-input]', [jpeg('lib_05.jpg', 'fixed lib 5'), jpeg('unrelated.jpg', 'x')]);
      await page.waitForSelector('#uiSheet.is-visible [data-redo-report]', { timeout: 20000 });
      expect.eq(await page.locator('[data-redo-result="ok"]').count(), 1);
      expect.eq(await page.locator('[data-redo-result="none"]').count(), 1);
      await page.waitForFunction(() => !document.querySelector('[data-redo-row="library:5"]'));
      const row = queued('library_images', 5);
      expect(!row.redo_at && row.status === 'pending', 'replaced → To Review');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  await run('move: Library → a tire series (admin)', async ({ test, expect, viewport, ctx }) => {
    const w = viewport === 'desktop' ? '1440' : '390';
    await theme(ctx, viewport === 'desktop' ? 'dark' : 'light');

    await test('viewer ⋯ Move to tire… → Klever AT2 · Series 2; the toast links to the series', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=library&filter=approved'));
      await openViewerOn(page, '#lib-2');
      await openMore(page);
      await page.click('[data-viewer-move]');
      await sheetOpen(page);
      await page.waitForFunction(() => !document.querySelector('[data-move-tire]').disabled);
      await page.selectOption('[data-move-tire]', { label: 'Klever AT2' });
      await page.selectOption('[data-move-series]', { label: 'Series 2 (6)' });
      await page.screenshot({ path: shot(`move-sheet-${w}-${viewport === 'desktop' ? 'dark' : 'light'}.png`) });
      await page.click('[data-move-submit]');
      await page.waitForFunction(() => /Moved to Klever AT2 · Series 2/.test((document.getElementById('uiToast') || {}).textContent || ''), null, { timeout: 15000 });
      const href = await page.getAttribute('#uiToast .ui-toast-link', 'href');
      expect(/item=1/.test(href) && /series=2/.test(href), 'link to the series: ' + href);
      await page.waitForFunction(() => !document.getElementById('lib-2'), null, { timeout: 5000 });   // the tile leaves the Library (fade, then gone)
      expect(fs.existsSync(path.join(MEDIA, 'tires/klever-at2/Series 2/lib_02.jpg')) && !fs.existsSync(path.join(MEDIA, 'library/kenda/lib_02.jpg')), 'file moved');
      const row = sql(`SELECT id, status FROM tire_images WHERE image_url = 'media/tires/klever-at2/Series 2/lib_02.jpg'`)[0];
      expect.eq(row.status, 'approved');
      await page.goto(new URL(href, page.url()).toString());
      await page.waitForSelector('#image-' + row.id);
    });

    await test('select bar: Move 2 to a New series…', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=library&filter=approved'));
      await page.click('[data-assets-select]');
      await page.click('#lib-3'); await page.click('#lib-4');
      await page.click('[data-select-move]');
      await sheetOpen(page);
      await page.waitForFunction(() => !document.querySelector('[data-move-tire]').disabled);
      await page.selectOption('[data-move-tire]', { label: 'Klever RT' });
      await page.selectOption('[data-move-series]', 'new');
      await page.fill('[data-move-new-name]', 'Lifestyle');
      await page.click('[data-move-submit]');
      await page.waitForFunction(() => /2 moved to Klever RT · Lifestyle/.test((document.getElementById('uiToast') || {}).textContent || ''), null, { timeout: 15000 });
      await page.waitForFunction(() => !document.getElementById('lib-3') && !document.getElementById('lib-4'), null, { timeout: 5000 });
      expect.eq(sql(`SELECT COUNT(*) AS n FROM tire_images ti JOIN tire_series s ON s.id = ti.series_id WHERE s.name = 'Lifestyle'`)[0].n, 2);
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  // -------------------------------------------------------------------------------------------------------------------
  await run('redo: the client', async ({ test, expect }) => {
    await test('"Being reworked" on the tile and in the viewer; no note, no admin actions', async (page) => {
      sql(`UPDATE tire_images SET redo_at = NOW(), redo_note = 'SECRET grade note' WHERE id = 5`);
      sql(`INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, author_user_id, internal, summary, detail) VALUES (1, 'tire_image', 5, 'commented', 'admin', 1, 1, 'Redo note', 'Redo: SECRET grade note')`);
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved'));
      expect.eq((await page.textContent('#image-5 [data-thumb-redo]')).trim(), 'Being reworked');
      expect(!(await page.content()).includes('SECRET'), 'no note in the page');
      expect(!(await page.$('[data-redo-chip]')) && !(await page.$('[data-select-redo]')), 'no admin chip / bar button');
      await openViewerOn(page, '#image-5');
      expect.eq((await page.textContent('[data-viewer-redo-pill]')).trim(), 'Being reworked');
      expect(await page.isVisible('[data-viewer-redo-pill]'), 'viewer pill');
      expect(!(await page.$('[data-viewer-redo]')) && !(await page.$('[data-viewer-move]')), 'no admin menu rows');
      await page.click('[data-viewer-comments-toggle]');
      await page.waitForSelector('[data-viewer-thread] .ui-viewer-thread-list, [data-viewer-thread] [data-thread-empty]');
      expect(!(await page.textContent('[data-viewer-thread]')).includes('SECRET'), 'no internal note in the thread');
    });
  }, { role: 'client', viewports: ['phone'], reseed: 'test' });
})();
