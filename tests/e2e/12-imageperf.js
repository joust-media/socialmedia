/* Image performance in the browser:
   - the Upload sheet makes the sm / lg WebP previews of a 4000×3000 JPEG itself: they exist the moment the upload
     finishes and the server decoded nothing (X-Preview-Gd: 0 on every upload request), and the file rows show a
     downscaled thumbnail, not the 12 MP original;
   - "Upload & make post" hands the New post tray / carousel the preview URLs — /uploads/tmp_* is never requested;
     New post's own Upload pane does the same;
   - grid tiles never request an original;
   - a placeholder (every generator slot busy) is retried until the real preview shows. */
'use strict';
const path = require('path');
const fs = require('fs');
const { spawn, execFileSync } = require('child_process');
const { run } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const APP = process.env.APP_DIR || path.join(ROOT, 'site/portal');
const MEDIA = process.env.MEDIA_DIR || path.join(ROOT, 'site/media');
const UP = path.join(ROOT, 'upfiles');

/** A photo-like 4000×3000 JPEG drawn on a canvas in the page (gradients + shapes + grain) → a file on disk. */
async function bigJpeg(page, name, w, h) {
  const file = path.join(UP, name);
  if (fs.existsSync(file)) return file;
  fs.mkdirSync(UP, { recursive: true });
  const b64 = await page.evaluate(async ([w, h]) => {
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const x = c.getContext('2d');
    const g = x.createLinearGradient(0, 0, w, h); g.addColorStop(0, '#2a4f8f'); g.addColorStop(1, '#d9a441');
    x.fillStyle = g; x.fillRect(0, 0, w, h);
    let s = 7; const rnd = () => (s = (s * 16807) % 2147483647) / 2147483647;
    for (let i = 0; i < 900; i++) { x.fillStyle = `hsla(${rnd() * 360},60%,${30 + rnd() * 40}%,0.55)`; x.beginPath(); x.arc(rnd() * w, rnd() * h, 10 + rnd() * 220, 0, 7); x.fill(); }
    const img = x.getImageData(0, 0, w, h), d = img.data;
    for (let i = 0; i < d.length; i += 4) { const n = (rnd() - 0.5) * 34; d[i] += n; d[i + 1] += n; d[i + 2] += n; }
    x.putImageData(img, 0, 0);
    const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
    const buf = new Uint8Array(await blob.arrayBuffer());
    let bin = ''; for (let i = 0; i < buf.length; i += 32768) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 32768));
    return btoa(bin);
  }, [w, h]);
  fs.writeFileSync(file, Buffer.from(b64, 'base64'));
  return file;
}
function dims(file) {
  return execFileSync('php', ['-r', `$i=getimagesize(${JSON.stringify(file)});echo $i[0].'x'.$i[1].' '.$i['mime'];`]).toString();
}
/** Hold both generator slots for ms (a separate PHP process — like two big decodes running). Resolves once held. */
function holdSlots(ms) {
  const dir = path.join(APP, 'uploads', '.locks');
  fs.mkdirSync(dir, { recursive: true });
  const p = spawn('php', ['-r', `$h=[];for($i=0;$i<2;$i++){$f=fopen(${JSON.stringify(dir)}."/slot-$i.lock","c");flock($f,LOCK_EX);$h[]=$f;}echo "held\\n";usleep(${ms * 1000});`]);
  return new Promise((resolve) => { p.stdout.on('data', (d) => { if (String(d).indexOf('held') !== -1) resolve(p); }); });
}
/** Every upload-chunk / tire-upload / batch / compose response's X-Preview-Gd, and every image URL requested. */
function watch(page) {
  const w = { gd: [], images: [], actions: [] };
  page.on('response', (r) => {
    const u = r.url();
    if (/(upload-chunk|tire-upload|batch-process|post-compose)\.php/.test(u)) { const h = r.headers()['x-preview-gd']; if (h !== undefined) w.gd.push(h); }
    if (/(upload-chunk|tire-upload)\.php/.test(u)) r.json().then((j) => { if (j && j.accepted) w.actions.push('previews:' + j.accepted.join('+')); }).catch(() => {});
  });
  page.on('request', (q) => {
    if (q.resourceType() === 'image') w.images.push(q.url());
  });
  return w;
}
/** An original image file (uploads/ or media/), not a preview under .thumbs/ (blob: / data: never count). */
const isOriginal = (u) => /^https?:/.test(u) && /\/(uploads|media\/(library|tires))\/[^?]*\.(jpe?g|png|gif)(\?|$)/i.test(u) && !/\/\.thumbs\//.test(u);

(async () => {
  await run('imageperf', async ({ test, url, expect }) => {
    await test('Upload sheet: a 4000×3000 JPEG → the browser makes sm + lg, the server decodes nothing', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=library'));
      const big = await bigJpeg(page, 'perf-big-4000.jpg', 4000, 3000);
      const bytes = fs.statSync(big).size;
      expect(bytes > 2.5 * 1048576, 'a multi-MB original: ' + bytes);
      const w = watch(page);
      await page.click('[data-library-upload]');
      await page.waitForSelector('.us-root.is-visible');
      await page.setInputFiles('[data-us-file]', [big]);
      await page.waitForSelector('[data-us-pane="dest"]:not([hidden])');
      // the summary / rows show a downscaled browser-made WebP, never the 12 MP original
      await page.waitForFunction(() => { const i = document.querySelector('.us-summary-thumbs img'); return i && i.complete && i.naturalWidth > 0; }, null, { timeout: 15000 });
      const th = await page.$eval('.us-summary-thumbs img', (i) => ({ src: i.src, w: i.naturalWidth, h: i.naturalHeight }));
      expect(/^blob:/.test(th.src) && th.w <= 480 && th.h <= 480, 'local thumb is the sm preview: ' + JSON.stringify(th));
      const t0 = Date.now();
      await page.click('[data-us-act="upload"]');
      // the upload is done when the sheet closes (it waits for the previews reply) and reloads onto the Library
      const thumbs = path.join(MEDIA, 'library', 'kenda', '.thumbs');
      const sm = path.join(thumbs, 'perf-big-4000.sm.webp'), lg = path.join(thumbs, 'perf-big-4000.lg.webp');
      for (let i = 0; i < 240; i++) {
        const open = await page.evaluate(() => !!(window.App && App.uploadSheet && App.uploadSheet.isOpen())).catch(() => false);
        if (!open && fs.existsSync(path.join(MEDIA, 'library', 'kenda', 'perf-big-4000.jpg'))) break;
        await new Promise((r) => setTimeout(r, 250));
      }
      const took = Date.now() - t0;
      expect(fs.existsSync(sm) && fs.existsSync(lg), 'sm + lg exist when the upload finishes (gd ' + w.gd.join(',') + ')');
      expect.eq(dims(sm), '480x360 image/webp', 'sm');
      expect.eq(dims(lg), '1600x1200 image/webp', 'lg');
      // probe + upload + previews all answered X-Preview-Gd: 0 — the server decoded nothing; no lazy request either
      expect(w.gd.length >= 3 && w.gd.every((x) => x === '0'), 'X-Preview-Gd 0 on every upload request: ' + w.gd.join(','));
      expect(!w.images.some((u) => /preview\.php/.test(u) && u.indexOf(Buffer.from('media/library/kenda/perf-big-4000.jpg').toString('base64').replace(/=+$/, '').slice(0, 30)) !== -1), 'never built lazily');
      expect(fs.statSync(sm).size < 300 * 1024 && fs.statSync(lg).size < 1536 * 1024, 'within the caps');
      fs.appendFileSync(path.join(ROOT, 'imageperf.txt'), `upload ${bytes} B 4000x3000 → sm ${fs.statSync(sm).size} B, lg ${fs.statSync(lg).size} B, ${took} ms, server decodes 0\n`);
    });

    await test('Upload & make post: the New post tray / carousel get preview URLs — /uploads/tmp_* is never requested', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      const big = await bigJpeg(page, 'perf-big-4000.jpg', 4000, 3000);
      const w = watch(page);
      await page.click('[data-new-menu-toggle]');
      await page.click('[data-new-action="upload"]');
      await page.waitForSelector('[data-us-client] .us-client-name');
      await page.setInputFiles('[data-us-file]', [big]);
      await page.waitForSelector('[data-us-pane="dest"]:not([hidden])');
      await page.click('label[for="usDest_post"]');
      await page.click('[data-us-act="upload"]');
      await page.waitForSelector('.np-root.is-visible', { timeout: 60000 });
      await page.waitForFunction(() => App.newPost.isOpen() && App.newPost._state().slides.length === 1);
      const s = await page.evaluate(() => { const x = App.newPost._state().slides[0]; return { ref: x.ref, thumb: x.thumb, large: x.large, src: x.src }; });
      const token = s.ref.split(':')[1];
      expect(/\/uploads\/\.thumbs\/tmp_[a-f0-9]{32}\.sm\.webp\?v=\d+$/.test(s.thumb), 'tray thumb = static sm: ' + s.thumb);
      expect(/\/uploads\/\.thumbs\/tmp_[a-f0-9]{32}\.lg\.webp\?v=\d+$/.test(s.large) && s.src === s.large, 'carousel = static lg: ' + s.large);
      await page.waitForSelector('[data-np-tray-list] .np-slide img');
      await page.waitForFunction(() => { const i = document.querySelector('[data-np-preview-media] .pd-slide img'); return i && i.complete && i.naturalWidth > 0; });
      expect.eq(await page.$eval('[data-np-preview-media] .pd-slide img', (i) => i.naturalWidth), 1600, 'carousel shows the lg preview');
      const orig = w.images.filter(isOriginal);
      expect.eq(orig.length, 0, 'no original requested: ' + orig.join(' '));
      expect(w.gd.every((x) => x === '0'), 'no server decode: ' + w.gd.join(','));
      // save: the post's image keeps its previews (moved with the file — no decode on save)
      await page.fill('[data-np-field="caption"]', 'Perf post');
      await page.click('[data-np-save="draft"]');
      await page.waitForFunction(() => /[?&]post=\d+/.test(location.search));
      const id = page.url().match(/post=(\d+)/)[1];
      const p = await page.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).json(), url('post-compose.php?client=kenda&action=load&id=' + id));
      const file = String(p.slides[0].image_url || p.slides[0].src || '').split('/').pop().split('?')[0];
      const stem = file.replace(/\.[a-z]+$/i, '');
      expect(fs.existsSync(path.join(APP, 'uploads', '.thumbs', stem + '.sm.webp')), 'saved post image has its sm: ' + file);
      expect(!fs.existsSync(path.join(APP, 'uploads', '.thumbs', 'tmp_' + token + '.sm.webp')), 'nothing left under the tmp name');
      expect(w.gd.every((x) => x === '0'), 'saving decoded nothing: ' + w.gd.join(','));
    });

    await test('New post Upload pane: local slides show browser-made previews, never the original', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      const big = await bigJpeg(page, 'perf-big-4000.jpg', 4000, 3000);
      const w = watch(page);
      await page.click('[data-new-menu-toggle]');
      await page.click('[data-new-action="post"]');
      await page.waitForSelector('.np-root.is-visible');
      await page.click('[data-np-source] [data-value="upload"]');
      await page.setInputFiles('[data-np-file]', big);
      await page.waitForFunction(() => { const s = App.newPost._state().slides[0]; return s && !s.uploading && s.ref; }, null, { timeout: 60000 });
      const s = await page.evaluate(() => { const x = App.newPost._state().slides[0]; return { thumb: x.thumb, large: x.large }; });
      expect(/^blob:/.test(s.thumb) && /^blob:/.test(s.large), 'local previews: ' + JSON.stringify(s));
      const nat = await page.$eval('[data-np-tray-list] .np-slide img', (i) => i.naturalWidth);
      expect(nat <= 480, 'tray image is the sm preview: ' + nat);
      expect(w.actions.indexOf('previews:sm+lg') !== -1, 'previews sent + accepted: ' + w.actions.join(','));
      expect(w.gd.every((x) => x === '0'), 'no server decode: ' + w.gd.join(','));
      expect.eq(w.images.filter(isOriginal).length, 0, 'no original requested');
    });

    await test('grid tiles never request an original (previews not built yet)', async (page) => {
      const w = watch(page);
      for (const u of ['assets.php?client=kenda&view=library', 'assets.php?client=kenda&view=collections&item=1&series=1', 'posts.php?client=kenda&status=pending&month=all']) {
        await page.goto(url(u));
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await page.waitForLoadState('networkidle');
      }
      expect(w.images.some((u) => /preview\.php|\.thumbs\//.test(u)), 'previews were requested');
      const orig = w.images.filter(isOriginal);
      expect.eq(orig.length, 0, 'originals requested: ' + orig.join(' '));
    });

    await test('placeholder while every generator slot is busy → retried with backoff until the real preview shows', async (page) => {
      const holder = await holdSlots(3000);
      const w = watch(page);
      await page.goto(url('assets.php?client=kenda&view=library'));
      await page.waitForFunction(() => document.querySelectorAll('img[data-pv-pending]').length > 0, null, { timeout: 10000 });
      const pending = await page.$$eval('img[data-pv-pending]', (e) => e.length);
      await new Promise((r) => holder.on('exit', r));
      await page.waitForFunction(() => {
        const imgs = Array.from(document.querySelectorAll('#assetsGrid img'));
        return imgs.length > 0 && imgs.every((i) => i.complete && i.naturalWidth > 2) && !document.querySelector('img[data-pv-pending]');
      }, null, { timeout: 30000 });
      const retried = await page.$$eval('img[data-pv-retry]', (e) => e.length);
      expect(pending > 0 && retried >= pending, `placeholders ${pending}, retried ${retried}`);
      expect.eq(w.images.filter(isOriginal).length, 0, 'no original requested');
    });
  }, { role: 'admin', viewports: ['desktop'], reseed: 'test' });
})();
