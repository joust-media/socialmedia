/* Posts → Calendar in the browser (tests/smoke/27-calendar.php covers the server side):
   - the month grid (admin, scoped): scheduled posts on their day, up to three chips + "+N more", no draft / trash /
     unscheduled post; "+N more" → the day sheet with every post; a row or a chip → the post sheet (?post=ID, Esc closes)
   - month navigation: next / prev arrows, ← / →, Today, the browser's back button
   - live: Unmark scheduled / Mark scheduled and the Post date edit in the sheet redraw the month (no reload)
   - the admin toggle "Show approved, not yet scheduled" (faded chips); All clients (each chip tagged, a chip opens its
     own client's post); List · Calendar (the choice remembered)
   - the client: its own posts only, no admin toggle, the sheet opens; phones (390 / 320): no sideways scroll, dots,
     a tap lists the day under the grid, a row opens the post; light and dark
   Screenshots (desktop month as admin and client, the day sheet, All clients, phones — 1440 dark / 390 light, plus
   320 light and 390 dark) go to $CAL_SHOTS_DIR (default $PORTAL_TEST_ROOT/shots/calendar). */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { run, url, reseed } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = process.env.CAL_SHOTS_DIR || path.join(ROOT, 'shots', 'calendar');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name) => path.join(SHOTS, name);

function sql(query, params) {
  const php = `$p=new PDO('mysql:host=localhost;dbname='.getenv('PORTAL_TEST_DB').';charset=utf8mb4',getenv('PORTAL_TEST_DB_USER'),getenv('PORTAL_TEST_DB_PASS'));`
    + `$p->exec("SET time_zone = '".(new DateTime('now', new DateTimeZone('America/New_York')))->format('P')."'");`
    + `$s=$p->prepare($argv[1]);$s->execute(json_decode($argv[2],true));echo json_encode($s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):[]);`;
  const env = Object.assign({ PORTAL_TEST_DB: 'portal_test', PORTAL_TEST_DB_USER: 'portal_test', PORTAL_TEST_DB_PASS: 'portal_test' }, process.env);
  return JSON.parse(execFileSync('php', ['-r', php, query, JSON.stringify(params || [])], { env }).toString() || '[]');
}
const T = sql("SELECT DATE_FORMAT(CURDATE(), '%Y-%m-%d') AS today, DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m') AS m, "
  + "DATE_FORMAT(CURDATE() + INTERVAL 2 MONTH, '%Y-%m') AS m2, DATE_FORMAT(CURDATE(), '%Y-%m') AS now")[0];
const M = T.m;
const d = (day) => `${M}-${day}`;

/** Seed + the calendar fixture (ids 301+). */
function fixture() {
  reseed();
  const rows = [
    // id, company, name, when, status, posted, type, media, trashed
    [301, 1, 'Cal reel', d('10') + ' 09:00:00', 'approved', 1, 'reel', ['uploads/img_post05_1.jpg'], 0],
    [302, 1, 'Cal carousel', d('10') + ' 11:00:00', 'approved', 1, 'post', ['uploads/img_post02_1.jpg', 'uploads/img_post02_2.jpg', 'uploads/img_post02_3.jpg'], 0],
    [303, 1, 'Cal image', d('10') + ' 14:30:00', 'approved', 1, 'post', ['uploads/img_post01_1.jpg'], 0],
    [304, 1, 'Cal fourth', d('10') + ' 16:00:00', 'approved', 1, 'post', ['uploads/img_post03_1.jpg'], 0],
    [305, 1, 'Cal fifth', d('10') + ' 18:00:00', 'approved', 1, 'post', [], 0],
    [306, 1, 'Cal draft', d('12') + ' 10:00:00', 'draft', 0, 'post', [], 0],
    [307, 1, 'Cal trashed', d('12') + ' 11:00:00', 'approved', 1, 'post', [], 1],
    [308, 1, 'Cal approved', d('13') + ' 10:00:00', 'approved', 0, 'post', [], 0],
    [309, 2, 'PB scheduled', d('15') + ' 10:00:00', 'approved', 1, 'post', ['uploads/img_post08_1.jpg'], 0],
    [310, 1, 'Cal twentieth', d('20') + ' 10:00:00', 'approved', 1, 'post', ['uploads/img_post04_1.jpg'], 0],
  ];
  for (const [id, co, name, when, st, posted, type, media, trashed] of rows) {
    sql(`INSERT INTO posts (id, company_id, name, caption, hashtags, scheduled_date, status, posted, posted_at, post_type, trashed_at)
         VALUES (?, ?, ?, ?, '#cal', ?, ?, ?, ?, ?, ${trashed ? 'NOW()' : 'NULL'})`, [id, co, name, name + ' caption', when, st, posted, posted ? when : null, type]);
    media.forEach((u, i) => sql("INSERT INTO post_images (post_id, image_url, sort_order, media_type) VALUES (?, ?, ?, 'image')", [id, u, i + 1]));
  }
}

const theme = (ctx, mode) => ctx.addInitScript((m) => { try { localStorage.setItem('portal.theme', m); } catch (e) {} }, mode);
const noSideways = (page) => page.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const chipsOn = (page, date) => page.$$eval(`.cal-day[data-date="${date}"] .cal-chip`, (els) => els.map((e) => parseInt(e.getAttribute('data-post-open'), 10)));
const allIds = (page) => page.$$eval('.cal-chip, template[data-cal-day-tpl]', (els) => {
  const out = [];
  els.forEach((e) => { if (e.tagName === 'TEMPLATE') e.content.querySelectorAll('[data-post-open]').forEach((a) => out.push(+a.getAttribute('data-post-open'))); else out.push(+e.getAttribute('data-post-open')); });
  return out;
});
const calMonth = (page) => page.$eval('[data-cal]', (s) => s.getAttribute('data-month'));
async function sheetOpen(page, id) {
  await page.waitForSelector(`#uiSheet.is-open .pd[data-id="${id}"]`);
  await page.waitForTimeout(450);   // the slide-in settles
}
async function waitMonth(page, ym) { await page.waitForFunction((m) => { const s = document.querySelector('[data-cal]'); return s && s.getAttribute('data-month') === m && !s.hasAttribute('aria-busy'); }, ym); }
async function ready(page) { await page.waitForFunction(() => !!(window.App && App._inited && App.postsCalendar && App.posts)); }
const bg = (page, sel) => page.$eval(sel, (e) => getComputedStyle(e).backgroundColor);

(async () => {
  // -------------------------------------------------------------------------------------------------------------------
  await run('calendar: admin', async ({ test, expect, viewport, ctx }) => {
    fixture();
    const desk = viewport === 'desktop';
    const w = desk ? '1440' : '390', mode = desk ? 'dark' : 'light';
    await theme(ctx, mode);

    await test('the month grid: scheduled posts on their day, three chips + "+2 more", no draft / trash / unscheduled', async (page) => {
      await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
      await ready(page);
      expect.eq(await calMonth(page), M);
      expect.eq(await page.getAttribute('[data-posts-view="calendar"]', 'aria-selected'), 'true', 'Calendar active in the switch');
      expect.eq(JSON.stringify(await chipsOn(page, d('10'))), '[301,302,303]', 'day 10 chips, by time');
      expect.eq(JSON.stringify(await chipsOn(page, d('20'))), '[310]');
      const ids = await allIds(page);
      for (const id of [306, 307, 308, 309]) expect(!ids.includes(id), `post ${id} is not on the calendar`);
      expect.eq((await page.textContent(`.cal-day[data-date="${d('10')}"] [data-cal-more]`)).trim(), '+2 more');
      expect(await noSideways(page), 'no sideways scroll');
      if (desk) {
        expect(await page.isVisible(`.cal-day[data-date="${d('10')}"] .cal-chip >> nth=0`), 'chips on desktop');
        expect(await page.$eval('.cal-chip[data-post-open="303"] img', (i) => /(s=sm|\.sm\.)/.test(i.currentSrc || i.src)), 'the sm preview');
        expect(await page.$eval('.cal-chip[data-post-open="301"] .cal-kind use', (u) => u.getAttribute('href')) === '#cal-i-reel', 'the Reel icon');
        expect(await page.$eval('.cal-chip[data-post-open="302"] .cal-kind use', (u) => u.getAttribute('href')) === '#cal-i-carousel', 'the Carousel icon');
        // a chip fits its cell
        const fits = await page.$$eval('.cal-chip', (els) => els.every((c) => { const r = c.getBoundingClientRect(), p = c.closest('.cal-day').getBoundingClientRect(); return r.left >= p.left - 0.5 && r.right <= p.right + 0.5; }));
        expect(fits, 'chips stay inside their day');
        await page.screenshot({ path: shot(`calendar-admin-${w}-${mode}.png`) });
      } else {
        expect(!(await page.isVisible('.cal-chip >> nth=0')), 'no chips on phones');
        expect(await page.isVisible(`.cal-day[data-date="${d('10')}"] .cal-dots`), 'dots on phones');
      }
    });

    await test('"+N more" → the day sheet with every post; a row opens the post sheet', async (page) => {
      await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
      await ready(page);
      if (desk) {
        await page.click(`[data-cal-more="${d('10')}"]`);
        await page.waitForSelector('#calDaySheet.is-open .cal-row');
        await page.waitForTimeout(450);
        const rows = await page.$$eval('#calDaySheet .cal-row', (els) => els.map((e) => +e.getAttribute('data-post-open')));
        expect.eq(JSON.stringify(rows), '[301,302,303,304,305]', 'every post that day, by time');
        expect(/Thursday|Monday|Tuesday|Wednesday|Friday|Saturday|Sunday/.test(await page.textContent('#calDaySheet [data-sheet-title]')), 'the day as the title');
        expect((await page.textContent('#calDaySheet')).includes('5 scheduled posts'));
        await page.screenshot({ path: shot(`day-sheet-${w}-${mode}.png`) });
        await page.click('#calDaySheet .cal-row[data-post-open="305"]');
      } else {
        // phones: tap the day → its posts listed under the grid
        await page.click(`.cal-day[data-date="${d('10')}"] .cal-daynum`);
        await page.waitForFunction((dt) => document.querySelector('[data-cal]').getAttribute('data-selected') === dt, d('10'));
        const rows = await page.$$eval('[data-cal-agenda-body] .cal-row', (els) => els.map((e) => +e.getAttribute('data-post-open')));
        expect.eq(JSON.stringify(rows), '[301,302,303,304,305]', 'the day list');
        expect(await page.$eval(`.cal-day[data-date="${d('10')}"]`, (e) => e.classList.contains('is-selected')), 'the day is selected');
        await page.click('[data-cal-agenda-body] .cal-row[data-post-open="305"]');
      }
      await sheetOpen(page, 305);
      expect(!(await page.$('#calDaySheet.is-open')), 'the day sheet gave way');
      expect((await page.textContent('#uiSheet [data-sheet-title]')).includes('Cal fifth'));
    });

    await test('a chip opens the post sheet (?post=ID), Esc closes it back to the month', async (page) => {
      await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
      await ready(page);
      if (desk) await page.click('.cal-chip[data-post-open="303"]');
      else { await page.click(`.cal-day[data-date="${d('10')}"] .cal-daynum`); await page.click('[data-cal-agenda-body] .cal-row[data-post-open="303"]'); }
      await sheetOpen(page, 303);
      expect(/[?&]post=303|\/posts\/303/.test(page.url()), 'the URL names the post: ' + page.url());
      expect(/view=calendar/.test(page.url()), 'and keeps the calendar');
      await page.keyboard.press('Escape');
      await page.waitForFunction(() => !document.querySelector('#uiSheet.is-open'));
      await page.waitForFunction(() => !/[?&]post=/.test(location.search));
      expect.eq(await calMonth(page), M, 'still the month');
    });

    await test('month navigation: next / prev, ← / →, Today, the back button', async (page) => {
      await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
      await ready(page);
      const title0 = await page.textContent('[data-cal-title]');
      await page.click('[data-cal-next]');
      await waitMonth(page, T.m2);
      expect(new URL(page.url()).searchParams.get('month') === T.m2, 'the URL follows: ' + page.url());
      expect(title0 !== await page.textContent('[data-cal-title]'), 'the title changes');
      await page.click('[data-cal-prev]');
      await waitMonth(page, M);
      await page.click('[data-cal-prev]');
      await waitMonth(page, T.now);
      expect(await page.$(`.cal-day.is-today[data-date="${T.today}"]`), 'today is marked');
      await page.goBack();
      await waitMonth(page, M);
      await page.goBack();
      await waitMonth(page, T.m2);
      if (desk) {
        await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
        await page.keyboard.press('ArrowLeft');
        await waitMonth(page, M);
      }
      await page.click('.cal-today');
      await waitMonth(page, T.now);
      expect(await page.$('.cal-day.is-today'), 'Today');
      const before = await page.$$eval(`.cal-day.is-past`, (els) => els.length);
      expect(before >= 0, 'past days');
      expect(await noSideways(page), 'no sideways scroll');
    });

    await test('live: Unmark / Mark scheduled and the Post date edit redraw the month without a reload', async (page) => {
      await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
      await ready(page);
      await page.evaluate(() => { window.__noReload = true; });
      await page.evaluate((id) => App.posts.open(id), 310);
      await sheetOpen(page, 310);
      await page.click('#uiSheet [data-toggle-posted="0"]');
      await page.waitForFunction(() => !document.querySelector('.cal-day [data-post-open="310"]'));
      await page.click('#uiSheet [data-toggle-posted="1"]');
      await page.waitForFunction(() => !!document.querySelector('.cal-day [data-post-open="310"]'));
      // the Post date: 20th → 21st
      await page.click('#uiSheet [data-when-toggle]');
      await page.fill('#uiSheet input[name="scheduled_date"]', `${d('21')}T10:00`);
      await page.click('#uiSheet [data-edit-form="date"] [type="submit"]');
      await page.waitForFunction((dt) => !!document.querySelector(`.cal-day[data-date="${dt}"] [data-post-open="310"]`), d('21'));
      expect.eq(JSON.stringify(await chipsOn(page, d('20'))), '[]', 'gone from the 20th');
      expect(await page.evaluate(() => window.__noReload === true), 'no page reload');
      expect(await page.$('#uiSheet.is-open .pd[data-id="310"]'), 'the sheet stays open');
    });

    await test('admin toggle: Show approved, not yet scheduled (faded)', async (page) => {
      await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
      await ready(page);
      expect.eq(await page.getAttribute('[data-cal-approved]', 'aria-checked'), 'false', 'off by default');
      expect(!(await allIds(page)).includes(308));
      await page.click('[data-cal-approved]');
      await page.waitForFunction(() => document.querySelector('[data-cal]').getAttribute('data-approved') === '1');
      expect.eq(await page.getAttribute('[data-cal-approved]', 'aria-checked'), 'true');
      expect(await page.$(`.cal-day[data-date="${d('13')}"] .cal-chip.is-tentative[data-post-open="308"]`), 'the approved post, faded');
      expect(new URL(page.url()).searchParams.get('approved') === '1', 'the URL keeps it');
      expect(!(await allIds(page)).includes(306), 'never a draft');
    });

    await test('All clients: every client, each chip tagged; a Privacy Bee chip opens its own post', async (page) => {
      await page.goto(url(`posts.php?view=calendar&month=${M}`));
      await ready(page);
      expect.eq(await page.$eval('[data-cal]', (s) => s.getAttribute('data-all')), '1');
      const ids = await allIds(page);
      expect(ids.includes(309) && ids.includes(301), 'both clients');
      expect(await page.$('.cal-chip[data-post-open="309"] .cal-avatar'), 'the client tag');
      if (desk) {
        await page.screenshot({ path: shot(`calendar-all-clients-${w}-${mode}.png`) });
        await page.click('.cal-chip[data-post-open="309"]');
      } else {
        await page.click(`.cal-day[data-date="${d('15')}"] .cal-daynum`);
        await page.waitForSelector('[data-cal-agenda-body] .cal-row[data-post-open="309"]');
        expect((await page.textContent('[data-cal-agenda-body]')).includes('Privacy Bee'), 'the client named in the row');
        await page.click('[data-cal-agenda-body] .cal-row[data-post-open="309"]');
      }
      await sheetOpen(page, 309);
      expect((await page.textContent('#uiSheet')).includes('PB scheduled'), 'Privacy Bee\'s post');
    });

    await test('List · Calendar: the switch, and the choice remembered', async (page) => {
      await page.goto(url('posts.php?client=kenda&view=list'));
      expect(await page.$('[data-posts-list]'), 'the list');
      await Promise.all([page.waitForURL(/view=calendar/), page.click('[data-posts-view="calendar"]')]);
      expect(await page.$('[data-cal]'), 'the calendar');
      await page.goto(url('posts.php?client=kenda'));
      expect(await page.$('[data-cal]'), 'the Posts tab remembers Calendar');
      await page.click('[data-posts-view="list"]');
      await page.waitForSelector('[data-posts-list]');
      await page.goto(url('posts.php?client=kenda'));
      expect(await page.$('[data-posts-list]'), '…and List');
      expect.eq(await page.$$eval('.ui-tab', (t) => t.filter((e) => /calendar/i.test(e.textContent)).length), 0, 'no new tab');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'] });

  // -------------------------------------------------------------------------------------------------------------------
  await run('calendar: client', async ({ test, expect, viewport, ctx }) => {
    fixture();
    const desk = viewport === 'desktop';
    const w = desk ? '1440' : (viewport === 'w320' ? '320' : '390');
    const mode = 'light';
    await theme(ctx, mode);

    await test('own scheduled posts only, no admin toggle; the sheet opens; no sideways scroll', async (page) => {
      await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
      await ready(page);
      const ids = await allIds(page);
      for (const id of [301, 302, 303, 304, 305, 310]) expect(ids.includes(id), `post ${id}`);
      for (const id of [306, 307, 308, 309]) expect(!ids.includes(id), `no post ${id}`);
      expect(!(await page.$('[data-cal-approved]')), 'no admin toggle');
      expect(await noSideways(page), 'no sideways scroll');
      if (desk) {
        await page.screenshot({ path: shot(`calendar-client-${w}-${mode}.png`) });
        await page.click('.cal-chip[data-post-open="301"]');
      } else {
        const g = await page.$eval('.cal-days', (e) => { const r = e.getBoundingClientRect(); return { l: r.left, r: r.right, w: innerWidth }; });
        expect(g.l >= 0 && g.r <= g.w + 0.5, 'the grid fits the screen');
        const cell = await page.$eval('.cal-day', (e) => e.getBoundingClientRect().width);
        expect(cell >= 38, 'tappable days (' + cell.toFixed(1) + 'px)');
        await page.click(`.cal-day[data-date="${d('10')}"] .cal-daynum`);
        await page.waitForSelector('[data-cal-agenda-body] .cal-row[data-post-open="301"]');
        await page.screenshot({ path: shot(`calendar-mobile-${w}-${mode}.png`) });
        const rowFits = await page.$$eval('[data-cal-agenda-body] .cal-row', (els) => els.every((r) => r.getBoundingClientRect().right <= innerWidth + 0.5));
        expect(rowFits, 'the day list fits');
        await page.click('[data-cal-agenda-body] .cal-row[data-post-open="301"]');
      }
      await sheetOpen(page, 301);
      expect(await page.$('#uiSheet .pd[data-posted="1"]'), 'the Scheduled post');
      expect(!(await page.$('#uiSheet [data-toggle-posted]:not([hidden])')) || !(await page.isVisible('#uiSheet [data-toggle-posted]')), 'no Joust controls for the client');
    });

    await test('another client\'s calendar is refused', async (page) => {
      // the 'client' seat signs in as whichever client the URL names — pin this browser to Kenda first
      await ctx.addCookies([{ name: 'portal_test_role', value: 'client:kenda', url: url('').replace(/\/portal\/?$/, '') }]);
      const res = await page.goto(url(`posts.php?client=privacybee&view=calendar&month=${M}`));
      expect(!(await page.$('[data-post-open="309"]')), 'never Privacy Bee\'s post (' + (res && res.status()) + ' ' + page.url() + ')');
    });

    if (!desk) {
      await test('phones: a muted day of the next month moves there with that day selected', async (page) => {
        await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
        await ready(page);
        const out = await page.$eval('.cal-day.is-out:last-child', (e) => e.getAttribute('data-date')).catch(() => null);
        if (!out) return;   // the month ends on the last weekday: no trailing days
        await page.click(`.cal-day[data-date="${out}"] .cal-daynum`);
        await waitMonth(page, out.slice(0, 7));
        await page.waitForFunction((dt) => document.querySelector('[data-cal]').getAttribute('data-selected') === dt, out);
      });
    }
  }, { role: 'client', viewports: ['desktop', 'phone', 'w320'] });

  // -------------------------------------------------------------------------------------------------------------------
  await run('calendar: themes', async ({ test, expect, viewport, ctx }) => {
    fixture();
    await theme(ctx, 'dark');
    await test('dark: the board, the chips and the selected day follow the theme', async (page) => {
      await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
      await ready(page);
      const board = await bg(page, '.cal-board');
      expect(board === 'rgb(28, 28, 30)', 'the dark elevated surface: ' + board);
      const color = await page.$eval('.cal-daynum', (e) => getComputedStyle(e).color);
      expect(/255, 255, 255|235, 235, 245/.test(color) || color === 'rgb(255, 255, 255)' || /rgba\(235, 235, 245/.test(color), 'light day numbers: ' + color);
      expect(await noSideways(page), 'no sideways scroll');
      if (viewport !== 'desktop') {
        await page.click(`.cal-day[data-date="${d('10')}"] .cal-daynum`);
        await page.waitForSelector('[data-cal-agenda-body] .cal-row');
      }
      await page.screenshot({ path: shot(`calendar-${viewport === 'desktop' ? '1440' : '390'}-dark-client.png`) });
    });
  }, { role: 'client', viewports: ['phone'] });
  await run('calendar: light', async ({ test, expect, ctx }) => {
    fixture();
    await theme(ctx, 'light');
    await test('light: the board is white', async (page) => {
      await page.goto(url(`posts.php?client=kenda&view=calendar&month=${M}`));
      await ready(page);
      expect.eq(await bg(page, '.cal-board'), 'rgb(255, 255, 255)');
      await page.screenshot({ path: shot('calendar-admin-1440-light.png') });
    });
  }, { role: 'admin', viewports: ['desktop'] });
})();
