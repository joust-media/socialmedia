/* The Joust mark as the admin seat's brand, in the browser (tests/smoke/23-brand.php covers the markup):
   - 1440: the sidebar brand is the Joust mark (loaded, 32px, round, not stretched) + "Joust Media" and the scoped
     client / "All clients"; the eyebrow mark is hidden next to the sidebar
   - 390: no sidebar; the Joust mark leads the eyebrow ("[J] Kenda Tires" / "[J] All clients"), the large title still fits
   - light and dark: the mark's ring is dark on light and light on dark (the navy rim reads on black)
   - comment threads: the Joust message carries the mark (admin and client seats)
   - the client seat keeps its own brand (no Joust mark in the sidebar / eyebrow)
   Screenshots (admin Home, Manage, a client page as admin, a comment thread — 1440 dark, 390 light) go to
   $BRAND_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/brand). */
'use strict';
const path = require('path');
const fs = require('fs');
const { run, url } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = process.env.BRAND_SHOTS_DIR || path.join(ROOT, 'shots', 'brand');
fs.mkdirSync(SHOTS, { recursive: true });
const themeOf = (vp) => (vp === 'desktop' ? 'dark' : 'light');
const widthOf = (vp) => (vp === 'desktop' ? '1440' : '390');
const setTheme = (page, mode) => page.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);

/** Box, load state and ring colour of the first visible element matching sel ('' when none is visible). */
async function markInfo(page, sel) {
  // visible images (lazy ones included) finish loading first, so `loaded` is never a race
  await page.waitForFunction((s) => Array.from(document.querySelectorAll(s)).filter((e) => e.offsetParent !== null && e.tagName === 'IMG')
    .every((e) => e.complete), sel, { timeout: 5000 }).catch(() => {});
  return page.evaluate((s) => {
    const el = Array.from(document.querySelectorAll(s)).find((e) => e.offsetParent !== null && e.getBoundingClientRect().width > 0);
    if (!el) return null;
    const b = el.getBoundingClientRect(); const cs = getComputedStyle(el);
    return { w: b.width, h: b.height, loaded: el.complete && el.naturalWidth > 0, natW: el.naturalWidth, natH: el.naturalHeight,
             radius: cs.borderTopLeftRadius, fit: cs.objectFit, shadow: cs.boxShadow, src: el.getAttribute('src') || '' };
  }, sel);
}
/** The ring is white-ish in dark mode, dark-ish in light mode. */
function ringFor(info, mode, expect, where) {
  const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(info.shadow || '');
  expect(m, `${where}: the mark has a ring (${info.shadow})`);
  const lum = (+m[1] + +m[2] + +m[3]) / 3;
  expect(mode === 'dark' ? lum > 200 : lum < 100, `${where}: ${mode} ring colour (${info.shadow})`);
}
async function postComment(page, id, text) {
  const r = await page.evaluate(async ([pid, t]) => {
    const fd = new FormData(); fd.append('id', String(pid)); fd.append('comment', t); fd.append('client', 'kenda');
    const res = await fetch('status.php', { method: 'POST', body: fd, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    return res.status;
  }, [id, text]);
  if (r !== 200) throw new Error('comment POST → ' + r);
}
async function openThread(page) {
  await page.goto(url('posts.php?client=kenda&post=1'));
  await page.waitForSelector('[data-post-detail="1"] [data-actor="admin"] .pd-msg-avatar', { state: 'attached' });
  await page.evaluate(() => { const t = document.querySelector('[data-post-detail="1"] [data-thread]'); if (t) t.scrollIntoView({ block: 'center' }); });
  await page.waitForTimeout(500);   // the sheet / panel slide-in settles before a screenshot
}

(async () => {
  // ---------------------------------------------------------------------------------------------------------------
  await run('brand: admin seat', async ({ test, expect, viewport }) => {
    const desk = viewport === 'desktop';
    for (const mode of ['light', 'dark']) {
      await test(`admin Home (${mode}): the Joust mark is the brand`, async (page) => {
        await setTheme(page, mode);
        await page.goto(url('index.php'));
        if (desk) {
          const m = await markInfo(page, '.ui-tabbar-brand[data-brand="joust"] img.ui-avatar--joust');
          expect(m, 'sidebar Joust mark visible');
          expect(m.loaded, 'the image loaded');
          expect(Math.round(m.w) === 32 && Math.round(m.h) === 32, `32×32 (got ${m.w}×${m.h})`);
          expect(m.natW === m.natH ? Math.abs(m.w - m.h) < 0.5 : m.fit === 'contain', 'aspect ratio kept');
          expect(m.radius === '50%' || parseFloat(m.radius) >= 16, 'round');
          ringFor(m, mode, expect, 'sidebar');
          const text = await page.textContent('.ui-tabbar-brand');
          expect(/Joust Media/.test(text) && /All clients/.test(text), 'Joust Media · All clients: ' + text.trim());
          expect(!(await markInfo(page, '.ui-nav-brandmark')), 'no eyebrow mark next to the sidebar');
          expect(await markInfo(page, '.ui-nav-trailing img.ui-avatar--joust'), 'Joust avatar in the nav bar');
        } else {
          expect(!(await page.isVisible('.ui-tabbar-brand')), 'no sidebar brand on a phone');
          const m = await markInfo(page, '.ui-nav-brandmark');
          expect(m && m.loaded, 'eyebrow Joust mark visible + loaded');
          expect(Math.round(m.w) === 20 && Math.round(m.h) === 20, `20×20 (got ${m.w}×${m.h})`);
          ringFor(m, mode, expect, 'eyebrow');
          expect((await page.textContent('[data-nav-brand] .ui-nav-eyebrow')).trim() === 'All clients', 'eyebrow text');
        }
        if (mode === themeOf(viewport)) await page.screenshot({ path: path.join(SHOTS, `admin-home-${widthOf(viewport)}-${mode}.png`) });
      });
    }

    await test('Manage: the Joust brand', async (page) => {
      await setTheme(page, themeOf(viewport));
      await page.goto(url('manage.php'));
      expect(await markInfo(page, desk ? '.ui-tabbar-brand img.ui-avatar--joust' : '.ui-nav-brandmark'), 'Joust mark visible');
      await page.screenshot({ path: path.join(SHOTS, `admin-manage-${widthOf(viewport)}-${themeOf(viewport)}.png`) });
    });

    await test('a client page as admin: the Joust brand + which client', async (page) => {
      await setTheme(page, themeOf(viewport));
      await page.goto(url('posts.php?client=kenda'));
      if (desk) {
        expect(await markInfo(page, '.ui-tabbar-brand img.ui-avatar--joust'), 'Joust mark in the sidebar');
        expect((await page.textContent('[data-brand-client="kenda"]')).trim() === 'KKenda Tires', 'client context line');
        expect(await page.isVisible('[data-brand-client="kenda"] .ui-avatar'), 'client avatar in the context line');
      } else {
        expect(await markInfo(page, '.ui-nav-brandmark'), 'eyebrow Joust mark');
        expect((await page.textContent('[data-nav-brand] .ui-nav-eyebrow')).trim() === 'Kenda Tires', 'eyebrow = the client');
        const t = await page.evaluate(() => { const h = document.querySelector('.ui-nav-title'); return h.scrollWidth <= h.clientWidth + 1; });
        expect(t, 'large title not truncated');
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no horizontal scroll');
      await page.screenshot({ path: path.join(SHOTS, `admin-client-page-${widthOf(viewport)}-${themeOf(viewport)}.png`) });
    });

    await test('comment thread as admin: the Joust message carries the mark', async (page) => {
      await setTheme(page, themeOf(viewport));
      await page.goto(url('index.php?client=kenda'));
      await postComment(page, 1, 'New grade is up — warmer, as asked.');
      await openThread(page);
      const m = await markInfo(page, '[data-post-detail="1"] [data-actor="admin"] img.ui-avatar--joust.pd-msg-avatar');
      expect(m && m.loaded, 'Joust mark on the Joust bubble');
      ringFor(m, themeOf(viewport), expect, 'bubble');
      await page.screenshot({ path: path.join(SHOTS, `thread-admin-${widthOf(viewport)}-${themeOf(viewport)}.png`) });
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'viewport' });

  // ---------------------------------------------------------------------------------------------------------------
  await run('brand: client seat', async ({ test, expect, viewport }) => {
    const desk = viewport === 'desktop';
    for (const mode of ['light', 'dark']) {
      await test(`client Home (${mode}): the client's own brand, no Joust mark`, async (page) => {
        await setTheme(page, mode);
        await page.goto(url('index.php?client=privacybee'));
        expect(!(await markInfo(page, '.ui-tabbar-brand img.ui-avatar--joust, .ui-nav-brandmark')), 'no Joust brand');
        if (desk) {
          const m = await markInfo(page, '.ui-tabbar-brand img.ui-avatar');
          expect(m && m.loaded && /privacybee\.png/.test(m.src), 'Privacy Bee logo in the sidebar');
        } else {
          const m = await markInfo(page, '.ui-nav-trailing img.ui-avatar');
          expect(m && m.loaded && /privacybee\.png/.test(m.src), 'Privacy Bee logo in the nav bar');
        }
      });
    }
  }, { role: 'client:privacybee', viewports: ['desktop', 'phone'] });

  await run('brand: client thread', async ({ test, expect, viewport, ctx }) => {
    await test('comment thread as the client: the Joust message carries the mark', async (page) => {
      await setTheme(page, themeOf(viewport));
      // the admin answers first (admin seat cookie for this one request), then the client opens the thread
      await ctx.addCookies([{ name: 'portal_test_role', value: 'admin', url: url('').replace(/\/portal\/?$/, '') }]);
      await page.goto(url('index.php?client=kenda'));
      await postComment(page, 1, 'Warmer grade is up — have a look.');
      await ctx.addCookies([{ name: 'portal_test_role', value: 'client', url: url('').replace(/\/portal\/?$/, '') }]);
      await openThread(page);
      const m = await markInfo(page, '[data-post-detail="1"] [data-actor="admin"] img.ui-avatar--joust.pd-msg-avatar');
      expect(m && m.loaded, 'Joust mark on the Joust bubble');
      expect(!(await markInfo(page, '.ui-tabbar-brand img.ui-avatar--joust, .ui-nav-brandmark')), 'still no Joust brand in the chrome');
      await page.screenshot({ path: path.join(SHOTS, `thread-client-${widthOf(viewport)}-${themeOf(viewport)}.png`) });
    });
  }, { role: 'client', viewports: ['desktop', 'phone'], reseed: 'viewport' });
})();
