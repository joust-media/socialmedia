/* Test harness only — tiny Playwright runner for tests/e2e/NN-*.js.
 *
 *   const { run } = require('./lib');
 *   run('new menu', async ({ test, url, expect }) => {
 *     await test('opens', async (page) => { await page.goto(url('posts.php?client=kenda')); … });
 *   }, { role: 'admin', viewports: ['desktop', 'phone'] });
 *
 * opts: role 'admin' | 'client' | 'client:<slug>' | 'anon' | 'none' (no seat cookie), viewports ['desktop', 'phone' (390), 'w320', 'w360', 'w430'], reseed 'viewport' | 'test' (tests that write).
 * Every test gets a fresh page per viewport; a failing test saves a screenshot to
 * $PORTAL_TEST_ROOT/shots/. Uncaught page errors (pageerror) fail the test they happen in.
 */
'use strict';
const path = require('path');
const fs = require('fs');

function loadPlaywright() {
  const tries = [process.env.PLAYWRIGHT_MODULE, 'playwright', '/opt/node22/lib/node_modules/playwright'].filter(Boolean);
  for (const t of tries) { try { return require(t); } catch (e) { /* next */ } }
  throw new Error('Playwright not found — npm i -g playwright (or set PLAYWRIGHT_MODULE)');
}
if (!process.env.PLAYWRIGHT_BROWSERS_PATH && fs.existsSync('/opt/pw-browsers')) process.env.PLAYWRIGHT_BROWSERS_PATH = '/opt/pw-browsers';
const { chromium } = loadPlaywright();

const BASE = (process.env.PORTAL_TEST_BASE || 'http://127.0.0.1:8099/portal').replace(/\/$/, '');
const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const VIEWPORTS = { desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 },
                    // narrow / wide phones for layout checks (07-nav.js): iPhone SE 1st gen, small Android, Pro Max
                    w320: { width: 320, height: 640 }, w360: { width: 360, height: 760 }, w430: { width: 430, height: 932 } };

const url = (p) => BASE + '/' + String(p || '').replace(/^\//, '');

/** Re-run tests/seed.php (same fixtures + ids) — opts.reseed: 'viewport' (before each viewport) | 'test' (before each test). */
function reseed() {
  const app = process.env.APP_DIR || path.join(ROOT, 'site/portal');
  const media = process.env.MEDIA_DIR || path.join(ROOT, 'site/media');
  require('child_process').execFileSync('php', [path.join(__dirname, '..', 'seed.php'), app, media], { stdio: 'ignore' });
}

function expect(cond, msg) { if (!cond) throw new Error(msg || 'expectation failed'); }
expect.eq = (a, b, msg) => { if (a !== b) throw new Error((msg ? msg + ': ' : '') + 'expected ' + JSON.stringify(b) + ', got ' + JSON.stringify(a)); };

async function run(suite, body, opts = {}) {
  const role = opts.role || 'admin';
  const viewports = opts.viewports || ['desktop'];
  const shots = path.join(ROOT, 'shots');
  fs.mkdirSync(shots, { recursive: true });
  let pass = 0, fail = 0;
  const browser = await chromium.launch();
  try {
    for (const vp of viewports) {
      if (opts.reseed === 'viewport') reseed();
      const ctx = await browser.newContext({ viewport: VIEWPORTS[vp] || vp, deviceScaleFactor: 1 });
      // role 'none': no seat cookie at all — the browser's own cookies decide (real sign-in flows, 14-auth.js)
      if (role !== 'none') await ctx.addCookies([{ name: 'portal_test_role', value: role, url: BASE.replace(/\/portal$/, '') }]);
      const errors = [];
      let page = null;
      const fresh = async () => {
        if (page) await page.close();
        page = await ctx.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        page.on('dialog', (d) => d.accept().catch(() => {}));
        return page;
      };
      const test = async (name, fn) => {
        const label = `${vp}: ${name}`;
        if (opts.reseed === 'test') reseed();
        await fresh();
        errors.length = 0;
        try {
          await fn(page);
          if (errors.length) throw new Error('page error: ' + errors.join(' | '));
          pass++;
          if (process.env.SMOKE_VERBOSE) console.log('  ok   ' + label);
        } catch (e) {
          fail++;
          const file = path.join(shots, (suite + '-' + label).replace(/[^a-z0-9]+/gi, '_') + '.png');
          try { await page.screenshot({ path: file, fullPage: false }); } catch (err) { /* ignore */ }
          console.log('  FAIL ' + label + '\n       ' + String(e.message || e).split('\n')[0] + '\n       shot: ' + file);
        }
      };
      await body({ test, url, expect, ctx, role, viewport: vp, page: () => page });
      if (page) await page.close();
      await ctx.close();
    }
  } finally {
    await browser.close();
  }
  console.log(`${(path.basename(process.argv[1] || suite, '.js')).padEnd(28)} ${String(pass).padStart(3)} passed, ${fail} failed`);
  if (process.env.SMOKE_TALLY) fs.appendFileSync(process.env.SMOKE_TALLY, `${pass} ${fail}\n`);
  if (fail) process.exitCode = 1;   // several run() calls in one script: any failure fails the script
}

module.exports = { run, url, expect, reseed, BASE };
