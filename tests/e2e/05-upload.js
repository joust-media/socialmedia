/* The Upload sheet (static/js/upload-sheet.js — App.uploadSheet): opens from "+ New", Files → Destination →
   Upload into every destination (tire series, a new series, Reference, Library, a draft post per file, one post →
   the New post pop-up), contextual entry points arrive with the destination preselected, per-file Retry / Cancel,
   the "Stop uploading?" guard, the deep link, and the Assets select bar's Download zip.
   Click counts → $PORTAL_TEST_ROOT/upload-clicks.txt; screenshots of every step (1440 dark, 390 light) →
   $PORTAL_TEST_ROOT/shots/upload-<step>-<1440-dark|390-light>.png. */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = path.join(ROOT, 'shots');
const CLICKS = path.join(ROOT, 'upload-clicks.txt');

/** A labelled JPEG made with PHP's GD (the harness already needs it). */
function jpeg(name, w, h, rgb) {
  const dir = path.join(ROOT, 'upfiles');
  fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, name);
  const [r, g, b] = rgb || [40, 90, 160];
  execFileSync('php', ['-r', `$i=imagecreatetruecolor(${w},${h});imagefill($i,0,0,imagecolorallocate($i,${r},${g},${b}));imagestring($i,5,12,12,'${name}',imagecolorallocate($i,255,255,255));imagejpeg($i,'${file}',80);`]);
  return file;
}
const S = {
  root: '.us-root.is-visible', file: '[data-us-file]', dest: '[data-us-pane="dest"]:not([hidden])', up: '[data-us-pane="upload"]:not([hidden])',
};
async function openFromNew(page) {
  await page.click('[data-new-menu-toggle]');
  await page.click('[data-new-action="upload"]');
  await page.waitForSelector(S.root);
  await page.waitForSelector('[data-us-client] .us-client-name');   // the client's tires / limits are in
}
async function pick(page, files) {
  await page.setInputFiles(S.file, files);
  await page.waitForSelector(S.dest);
}
async function choose(page, kind) {
  await page.click(`label[for="usDest_${kind}"]`);
  await page.waitForSelector(`[data-us-card="${kind}"].is-checked`);
}
/** Upload → the sheet closes and a toast says where the files went (+ a link, or the page reloads onto the destination). */
async function uploadAndClose(page) {
  await page.click('[data-us-act="upload"]');
  for (let i = 0; i < 80; i++) {
    const t = await page.evaluate(() => {
      if (window.App && App.uploadSheet && App.uploadSheet.isOpen()) return null;
      const el = document.getElementById('uiToast'); if (!el || !el.classList.contains('is-visible')) return null;
      const a = el.querySelector('a'); return { text: el.textContent, href: a ? a.getAttribute('href') : '' };
    }).catch(() => null);   // the page may be reloading onto the destination
    if (t) return Object.assign(t, { url: page.url(), dest: t.href || page.url() });
    await page.waitForTimeout(250);
  }
  throw new Error('no upload toast');
}
/** The next visible toast's text (survives a reload onto the destination). */
async function toastText(page, re) {
  for (let i = 0; i < 80; i++) {
    const t = await page.evaluate(() => { const el = document.getElementById('uiToast'); return el && el.classList.contains('is-visible') ? el.textContent : null; }).catch(() => null);
    if (t && (!re || re.test(t))) return t;
    await page.waitForTimeout(250);
  }
  return '';
}
async function html(page, u) { return page.evaluate(async (x) => (await fetch(x, { credentials: 'same-origin' })).text(), u); }
function note(line) { fs.appendFileSync(CLICKS, line + '\n'); }

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  fs.writeFileSync(CLICKS, '');
  const f = {};
  for (let i = 1; i <= 5; i++) f['r' + i] = jpeg(`e2e-render-${i}.jpg`, 800, 600, [30 * i, 80, 150]);
  f.ref = jpeg('e2e-ref.jpg', 600, 600, [120, 60, 40]);
  f.lib = jpeg('e2e-lib.jpg', 640, 800, [40, 120, 60]);
  f.post = jpeg('e2e-post.jpg', 1080, 1350, [90, 40, 120]);
  f.each1 = jpeg('e2e-each-1.jpg', 600, 600);
  f.each2 = jpeg('e2e-each-2.jpg', 600, 600);
  f.clip = path.join(ROOT, 'upfiles', 'clip.mp4');   // the container magic the server sniffs (ftyp isom) — never decoded
  fs.writeFileSync(f.clip, Buffer.concat([Buffer.from([0, 0, 0, 24]), Buffer.from('ftypisom'), Buffer.from([0, 0, 2, 0]), Buffer.from('isommp41'), Buffer.from([0, 0, 4, 8]), Buffer.from('mdat'), Buffer.alloc(1024)]));

  await run('upload', async ({ test, url, expect, viewport }) => {
    await test('+ New → Upload: Files → Destination (radio cards) → Upload, keyboard + ARIA', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      await openFromNew(page);
      expect.eq(await page.getAttribute('.us-panel', 'role'), 'dialog');
      expect.eq(await page.getAttribute('.us-panel', 'aria-modal'), 'true');
      expect.eq(await page.getAttribute('[data-us-step="files"]', 'aria-current'), 'step');
      expect(await page.isDisabled('[data-us-act="next"]'), 'Next waits for files');
      await pick(page, [f.r1]);
      expect.eq(await page.getAttribute('[data-us-step="dest"]', 'aria-current'), 'step', 'picking files moves on to Destination');
      const cards = await page.$$eval('[data-us-card]', (els) => els.map((e) => e.dataset.usCard));
      expect.eq(cards.join(','), 'series,reference,library,post');
      expect(await page.isDisabled('[data-us-act="upload"]'), 'Upload waits for a destination');
      await choose(page, 'series');
      expect.eq(await page.$eval('[data-us-tire]', (s) => s.options[s.selectedIndex].text.split(' · ')[0]), 'Klever AT2', 'the first tire with series');
      expect.eq(await page.$eval('[data-us-series]', (s) => s.options[s.selectedIndex].text.split(' · ')[0]), 'Series 2', 'its newest series');
      // Back to Files keeps the list; Escape closes (nothing uploaded yet → no guard)
      await page.click('[data-us-act="back"]');
      expect.eq(await page.$$eval('[data-us-file-row]', (r) => r.length), 1);
      await page.keyboard.press('Escape');
      await page.waitForSelector('.us-root', { state: 'detached' });
    });

    await test('tire series: 5 images from the series page Upload (destination preselected) — 3 clicks', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1'));
      let clicks = 0;
      await page.click('[data-series-upload]'); clicks++;
      await page.waitForSelector(S.root);
      await page.setInputFiles(S.file, [f.r1, f.r2, f.r3, f.r4, f.r5]); clicks++;   // "Choose files" + the picker
      await page.waitForSelector(S.dest);
      expect(await page.isChecked('#usDest_series'), 'series preselected');
      expect.eq(await page.$eval('[data-us-series]', (s) => s.value), '1', 'this series');
      expect.eq((await page.textContent('[data-us-act="upload"]')).trim(), 'Upload 5 files');
      const t = await uploadAndClose(page); clicks++;
      expect(/5 files uploaded to Klever AT2 · Series 1/.test(t.text), t.text);
      expect(/assets\.php\?client=kenda&view=collections&item=1&series=1&filter=pending/.test(t.url), 'already on the tire → reloaded onto the series To Review: ' + t.url);
      note(`${viewport}: upload 5 images into a tire series (series page Upload): ${clicks} clicks`);
      const body = await page.content();
      for (let i = 1; i <= 5; i++) expect(body.indexOf(`e2e-render-${i}`) !== -1, `render ${i} is To Review in the series`);
    });

    await test('tire series from + New: tire → series, then "New series…" creates one', async (page) => {
      await page.goto(url('manage.php?client=kenda'));
      let clicks = 0;
      await openFromNew(page); clicks += 2;
      await pick(page, [f.r1, f.r2, f.r3, f.r4, f.r5]); clicks++;
      await choose(page, 'series'); clicks++;
      await page.selectOption('[data-us-tire]', '2'); clicks++;
      await page.selectOption('[data-us-series]', '3'); clicks++;
      const t = await uploadAndClose(page); clicks++;
      expect(/5 files uploaded to Klever RT · Series 1/.test(t.text), t.text);
      note(`${viewport}: upload 5 images into a tire series (+ New → Upload, pick tire + series): ${clicks} clicks`);
      // a new series
      await openFromNew(page);
      await pick(page, [f.r1]);
      await choose(page, 'series');
      await page.selectOption('[data-us-tire]', '3');
      expect.eq(await page.$eval('[data-us-series]', (s) => s.value), 'new', 'a tire without series starts a new one');
      await page.fill('[data-us-new-series]', 'Launch Week');
      const t2 = await uploadAndClose(page);
      expect(/uploaded to Kenetica Sport · Launch Week/.test(t2.text), t2.text);
      const init = await page.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).json(), url('upload-sheet.php?client=kenda&action=init'));
      expect(init.tires.find((x) => x.id === 3).series.some((s) => s.name === 'Launch Week' && s.total === 1), 'series created with the file');
    });

    await test('Reference: from the Reference card (preselected), images only, lands To Review', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=collections&item=3'));
      await page.click('[data-ref-upload]');
      await page.waitForSelector(S.root);
      await page.setInputFiles(S.file, [f.ref]);
      await page.waitForSelector(S.dest);
      expect(await page.isChecked('#usDest_reference'), 'reference preselected');
      expect.eq(await page.$eval('[data-us-tire]', (s) => s.value), '3');
      expect((await page.$eval('[data-us-tire]', (s) => s.options[s.selectedIndex].text)).indexOf('2 of 6 used') !== -1, 'slots shown');
      const t = await uploadAndClose(page);
      expect(/1 file uploaded to Kenetica Sport · Reference/.test(t.text), t.text);
      expect(/series=ref&filter=pending/.test(t.dest), t.dest);
      expect((await html(page, t.dest)).indexOf('e2e-ref') !== -1, 'in the tire\'s Reference To Review');
    });

    await test('Library: from Assets → Library Upload; a video next to a Reference pick is left out with a reason', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=library'));
      await page.click('[data-library-upload]');
      await page.waitForSelector(S.root);
      await page.setInputFiles(S.file, [f.lib, f.clip]);
      await page.waitForSelector(S.dest);
      expect(await page.isChecked('#usDest_library'), 'library preselected');
      await choose(page, 'reference');
      expect((await page.textContent('[data-us-dest-note]')).indexOf('images only') !== -1, 'the video would be left out');
      await choose(page, 'library');
      expect.eq((await page.textContent('[data-us-act="upload"]')).trim(), 'Upload 2 files', 'the Library takes video too');
      const t = await uploadAndClose(page);
      expect(/2 files uploaded to Library/.test(t.text), t.text);
      const body = await html(page, t.dest);
      expect(body.indexOf('e2e-lib') !== -1 && body.indexOf('clip') !== -1, 'both To Review in the Library');
    });

    await test('New post, a draft per file: the old Studio Uploads link opens the sheet on Posts → Draft', async (page) => {
      const drafts = url('posts.php?client=kenda&status=draft&month=all');
      await page.goto(url('posts.php?client=kenda'));
      const before = ((await html(page, drafts)).match(/data-draft\b/g) || []).length;
      await page.goto(url('studio.php?client=kenda&tab=uploads'));
      expect(/posts\.php\?client=kenda/.test(page.url()), 'landed on Posts: ' + page.url());
      await page.waitForSelector(S.root);
      await page.setInputFiles(S.file, [f.each1, f.each2]);
      await page.waitForSelector(S.dest);
      expect(await page.isChecked('#usDest_post'), 'post preselected');
      expect(await page.isChecked('input[name="usEach"][value="each"]'), 'a draft per file');
      const t = await uploadAndClose(page);
      expect(/2 draft posts created/.test(t.text), t.text);
      expect(/status=draft/.test(t.href || t.url), 'already on Posts → reloaded onto Draft (or a link there): ' + (t.href || t.url));
      const after = ((await html(page, t.dest)).match(/data-draft\b/g) || []).length;
      expect(after > before, `more drafts in Posts → Draft (${before} → ${after})`);
    });

    await test('New post: upload an image and make a post from it — the files hand off to the New post pop-up', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      let clicks = 0;
      await openFromNew(page); clicks += 2;
      await pick(page, [f.post]); clicks++;
      await choose(page, 'post'); clicks++;
      expect.eq((await page.textContent('[data-us-act="upload"]')).trim(), 'Upload & make post');
      await page.click('[data-us-act="upload"]'); clicks++;
      await page.waitForSelector('.np-root.is-visible');
      await page.waitForFunction(() => App.newPost.isOpen() && App.newPost._state().slides.length === 1);
      expect(/^upload:[a-f0-9]{32}$/.test(await page.evaluate(() => App.newPost._state().slides[0].ref)), 'the parked upload is slide 1');
      if (viewport === 'phone') { await page.click('[data-np-steps] [data-value="details"]'); clicks++; }
      await page.fill('[data-np-field="caption"]', 'Made from an upload');
      await Promise.all([page.waitForNavigation(), page.click('[data-np-save="draft"]')]); clicks++;
      note(`${viewport}: upload an image and make a post from it (+ New → Upload → New post → Save draft, typing aside): ${clicks} clicks`);
      expect(/posts\.php\?client=kenda&post=\d+/.test(page.url()), page.url());
      const id = page.url().match(/post=(\d+)/)[1];
      const p = await page.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).json(), url('post-compose.php?client=kenda&action=load&id=' + id));
      expect.eq(p.post.status, 'draft');
      expect.eq(p.slides.length, 1);
    });

    await test('per-file Cancel and Retry; Stop guard while uploading', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=library'));
      let failOnce = true, slowOnce = true;
      await page.route('**/upload-chunk.php**', async (route) => {
        let body = ''; try { body = route.request().postData() || ''; } catch (e) { body = ''; }
        if (failOnce && body.indexOf('e2e-each-1') !== -1) { failOnce = false; return route.abort('failed'); }      // a dropped connection
        if (slowOnce && body.indexOf('e2e-render-1') !== -1) { slowOnce = false; await new Promise((r) => setTimeout(r, 4000)); }   // still running when Cancel is hit
        return route.continue().catch(() => {});
      });
      await page.click('[data-library-upload]');
      await page.waitForSelector(S.root);
      await page.setInputFiles(S.file, [f.each1, f.each2, f.r1]);
      await page.waitForSelector(S.dest);
      await page.click('[data-us-act="upload"]');
      await page.waitForSelector(S.up);
      // cancel the last one while it waits (or runs)
      const last = await page.$$eval('[data-us-job]', (r) => r[2].dataset.usJob);
      await page.waitForFunction((id) => { const j = App.uploadSheet._state().jobs.find((x) => x.id === id); return j && j.state === 'uploading'; }, last);
      await page.click(`[data-us-job-cancel="${last}"]`);
      await page.waitForFunction(() => !App.uploadSheet._state() || !App.uploadSheet._state().jobs.some((j) => j.state === 'queued' || j.state === 'uploading'));
      const states = await page.evaluate(() => App.uploadSheet._state().jobs.map((j) => j.state));
      expect.eq(states.join(','), 'failed,done,cancelled', 'one failed (network), one done, one cancelled');
      expect((await page.textContent('[data-us-job][data-state="failed"] .us-row-meta')).length > 0, 'failure shown');
      await page.click('[data-us-act="retry-all"]');
      const done = await toastText(page, /uploaded/);
      expect(/3 files uploaded to Library/.test(done), done);
      await page.waitForSelector('[data-library-upload]');
      await page.unroute('**/upload-chunk.php**');
      // the guard: a slow upload → Close asks first
      await page.route('**/upload-chunk.php**', async (route) => { await new Promise((r) => setTimeout(r, 1500)); return route.continue(); });
      await page.click('[data-library-upload]');
      await page.waitForSelector(S.root);
      await page.setInputFiles(S.file, [f.r2]);
      await page.waitForSelector(S.dest);
      await page.click('[data-us-act="upload"]');
      await page.click('[data-us-close]');
      await page.waitForSelector('[data-us-confirm]:not([hidden])');
      await page.click('[data-us-keep]');
      expect(await page.isVisible('.us-root'), 'still open');
      await page.waitForSelector('.us-root', { state: 'detached', timeout: 20000 });   // it finishes (nothing in flight when the test ends)
      await page.unroute('**/upload-chunk.php**');
    });

    await test('deep link ?upload=1&dest=library opens preselected and leaves a clean address bar; unscoped asks for the client', async (page) => {
      await page.goto(url('posts.php?client=kenda&upload=1&dest=library'));
      await page.waitForSelector(S.root);
      expect(!/upload=|dest=/.test(page.url()), 'params removed: ' + page.url());
      await page.setInputFiles(S.file, [f.lib]);
      await page.waitForSelector(S.dest);
      expect(await page.isChecked('#usDest_library'));
      await page.keyboard.press('Escape');
      await page.goto(url('manage.php'));
      await page.click('[data-new-menu-toggle]');
      await page.click('[data-new-action="upload"]');
      await page.waitForSelector('[data-us-pick-client="kenda"]');
      await page.click('[data-us-pick-client="kenda"]');
      await page.waitForSelector('[data-us-client] .us-client-name');
      expect((await page.textContent('[data-us-client]')).indexOf('Kenda') !== -1, 'scoped to Kenda');
      // retired route → the sheet with a draft per file
      await page.goto(url('batch.php?client=kenda'));
      await page.waitForSelector(S.root);
      await page.setInputFiles(S.file, [f.each1]);
      await page.waitForSelector(S.dest);
      expect(await page.isChecked('input[name="usEach"][value="each"]'), 'batch.php → a draft per file');
    });

    await test('Assets: select approved → Download (one zip) · Export (CSV) · Create post, in one bar', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=library&filter=approved'));
      await page.click('[data-assets-select]');
      const tiles = await page.$$('#assetsGrid .as-thumb[data-status="approved"]');
      await tiles[0].click(); await tiles[1].click();
      for (const s of ['[data-select-post]', '[data-select-download]', '[data-select-export]']) expect(await page.isEnabled(s), s + ' enabled');
      expect(!(await page.isVisible('[data-select-approve]')), 'Approve hidden on the approved grid');
      const bar = await page.$eval('[data-assets-selectbar]', (b) => { const r = b.getBoundingClientRect(); return { l: r.left, r: r.right, vw: window.innerWidth, overflow: b.scrollWidth > b.clientWidth + 1, buttons: Array.from(b.querySelectorAll('.ui-btn')).filter((x) => x.offsetParent).map((x) => { const q = x.getBoundingClientRect(); return [q.left, q.right]; }) }; });
      expect(!bar.overflow, 'no horizontal overflow');
      expect.eq(bar.buttons.length, 3, 'Create post · Download · Export');
      bar.buttons.forEach((b) => expect(b[0] >= bar.l - 1 && b[1] <= Math.min(bar.r, bar.vw) + 1, 'buttons inside the bar: ' + JSON.stringify(b) + ' in ' + bar.l + '–' + bar.r));
      const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 20000 }), page.click('[data-select-download]')]);
      expect(/kenda-selected-assets-\d{4}-\d{2}-\d{2}\.zip/.test(dl.suggestedFilename()), dl.suggestedFilename());
      const zipPath = path.join(ROOT, 'e2e-selection.zip');
      await dl.saveAs(zipPath);
      const zip = fs.readFileSync(zipPath);
      expect.eq(zip.slice(0, 2).toString(), 'PK');
      const names = (zip.toString('latin1').match(/Library\/[^\0\/]+?\.(?:jpg|mp4)/g) || []).filter((v, i, a) => a.indexOf(v) === i);
      expect.eq(names.length, 2, 'the two selected files: ' + names.join(', '));
      const [csv] = await Promise.all([page.waitForEvent('download', { timeout: 20000 }), page.click('[data-select-export]')]);
      expect(/selected-assets-.*-manifest\.csv$/.test(csv.suggestedFilename()), csv.suggestedFilename());
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  // Screenshots of every step: 1440 dark, 390 light.
  await run('upload-shots', async ({ test, url, viewport }) => {
    await test('screenshots', async (page) => {
      const tag = viewport === 'desktop' ? '1440-dark' : '390-light';
      await page.addInitScript((t) => { try { localStorage.setItem('portal.theme', t); } catch (e) {} }, viewport === 'desktop' ? 'dark' : 'light');
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1'));
      await page.click('[data-series-upload]');
      await page.waitForSelector(S.root);
      await page.waitForTimeout(350);
      await page.screenshot({ path: path.join(SHOTS, `upload-1-files-${tag}.png`) });
      await page.setInputFiles(S.file, [f.r1, f.r2, f.r3]);
      await page.waitForSelector(S.dest);
      await page.waitForTimeout(250);
      await page.screenshot({ path: path.join(SHOTS, `upload-2-destination-${tag}.png`) });
      await page.click('label[for="usDest_post"]');
      await page.waitForTimeout(200);
      await page.screenshot({ path: path.join(SHOTS, `upload-2-destination-post-${tag}.png`) });
      await page.click('label[for="usDest_series"]');
      await page.route('**/tire-upload.php**', async (route) => { await new Promise((r) => setTimeout(r, 700)); return route.continue(); });
      await page.click('[data-us-act="upload"]');
      await page.waitForSelector(S.up);
      await page.waitForTimeout(900);
      await page.screenshot({ path: path.join(SHOTS, `upload-3-progress-${tag}.png`) });
      await page.waitForSelector('.us-root', { state: 'detached', timeout: 20000 });
      await page.waitForTimeout(150);
      await page.screenshot({ path: path.join(SHOTS, `upload-4-done-toast-${tag}.png`) });
      await page.goto(url('assets.php?client=kenda&view=library&filter=approved'));
      await page.click('[data-assets-select]');
      const tiles = await page.$$('#assetsGrid .as-thumb[data-status="approved"]');
      await tiles[0].click(); await tiles[1].click(); await tiles[2].click();
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(SHOTS, `upload-assets-selectbar-${tag}.png`) });
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'viewport' });
})();
