/* Navigation + Manage: the admin tab bar at 1440 (sidebar) and 320 / 360 / 390 / 430 (bottom bar) — ≤ 6 items,
   no overlap, no clipped label, no horizontal scroll, the large title never truncated next to "+ New"; the Manage
   sections; Manage series on a tire (rename, reorder, Drive link, add, rescan); the module toggle in Manage →
   Clients; the Posts header; the client seat. */
'use strict';
const { run } = require('./lib');
const { execFileSync } = require('child_process');

/** Run SQL against the test database (php + PDO, like the smoke suites). */
function sql(q) {
  const code = `$p = new PDO('mysql:host=localhost;dbname=' . (getenv('PORTAL_TEST_DB') ?: 'portal_test') . ';charset=utf8mb4', getenv('PORTAL_TEST_DB_USER') ?: 'portal_test', getenv('PORTAL_TEST_DB_PASS') ?: 'portal_test'); $p->exec($argv[1]);`;
  execFileSync('php', ['-r', code, q], { stdio: 'ignore' });
}

/** Layout facts of the tab bar + nav bar on the current page. */
async function layout(page) {
  return page.evaluate(() => {
    const r = (el) => { const b = el.getBoundingClientRect(); return { l: b.left, r: b.right, t: b.top, b: b.bottom, w: b.width, h: b.height }; };
    const tabs = Array.from(document.querySelectorAll('.ui-tabbar .ui-tab')).map((a) => {
      const label = Array.from(a.querySelectorAll('.ui-tab-label')).find((s) => s.offsetParent !== null);
      return { key: a.dataset.tab, box: r(a), label: label ? label.textContent.trim() : '', clipped: label ? label.scrollWidth > label.clientWidth + 1 : true };
    });
    const title = document.querySelector('.ui-nav-title');
    const trailing = document.querySelector('.ui-nav-trailing');
    return {
      vw: window.innerWidth, scrollW: document.documentElement.scrollWidth,
      tabs, title: title ? { box: r(title), text: title.textContent.trim(), truncated: title.scrollWidth > title.clientWidth + 1 } : null,
      trailing: trailing ? r(trailing) : null,
      trailingItems: trailing ? Array.from(trailing.children).filter((c) => c.offsetParent !== null).map((c) => r(c)) : [],
    };
  });
}
function checkLayout(L, expect, where) {
  expect(L.scrollW <= L.vw, `${where}: no horizontal scroll (${L.scrollW} > ${L.vw})`);
  expect(L.tabs.length >= 4 && L.tabs.length <= 6, `${where}: 4–6 tabs, got ${L.tabs.length}`);
  for (let i = 0; i < L.tabs.length; i++) {
    const a = L.tabs[i].box;
    expect(a.l >= -0.5 && a.r <= L.vw + 0.5, `${where}: tab ${L.tabs[i].key} inside the viewport`);
    expect(!L.tabs[i].clipped, `${where}: label "${L.tabs[i].label}" not clipped`);
    for (let j = i + 1; j < L.tabs.length; j++) {
      const b = L.tabs[j].box;
      const overlap = Math.min(a.r, b.r) - Math.max(a.l, b.l) > 0.5 && Math.min(a.b, b.b) - Math.max(a.t, b.t) > 0.5;
      expect(!overlap, `${where}: tabs ${L.tabs[i].key} / ${L.tabs[j].key} overlap`);
    }
  }
  if (L.title) {
    // Section titles must never truncate; a client / flow name may ellipsize, but never under the buttons.
    if (/^(Posts|Assets|Manage|Emails|Pages|Flows|Drive|Projects|Today)$/.test(L.title.text)) expect(!L.title.truncated, `${where}: title "${L.title.text}" not truncated`);
    if (L.trailing) expect(L.title.box.r <= L.trailing.l + 0.5, `${where}: title clear of the trailing buttons`);
    L.trailingItems.forEach((b) => expect(b.r <= L.vw + 0.5 && b.l >= 0, `${where}: trailing button inside the viewport`));
  }
}

(async () => {
  // ---- tab bar + nav bar at every width ------------------------------------------------------------
  for (const vps of [['desktop'], ['w320', 'w360', 'phone', 'w430']]) {
    await run('nav-layout', async ({ test, url, expect, viewport }) => {
      const phone = viewport !== 'desktop';
      await test('admin: Home · Assets · Tires · Posts · Manage, nothing overlaps (Kenda)', async (page) => {
        for (const p of ['?client=kenda', 'assets.php?client=kenda', 'assets.php?client=kenda&view=collections', 'posts.php?client=kenda&month=all',
                         'manage.php?client=kenda', 'manage.php?client=kenda&section=tools', 'manage.php?client=kenda&section=export', 'drive.php', 'manage.php']) {
          await page.goto(url(p));
          const L = await layout(page);
          checkLayout(L, expect, `${viewport} ${p}`);
          if (p.indexOf('client=kenda') !== -1) expect.eq(L.tabs.map((t) => t.key).join(','), 'home,assets,tires,posts,manage', p);
        }
        const L = await layout(page);
        if (!phone) expect(L.tabs[0].box.w > 150, 'desktop: a sidebar');
        else expect(L.tabs[0].box.t > 500, 'phone: a bottom bar');
      });
      await test('admin: six tabs for Privacy Bee (Emails + Pages), labels intact', async (page) => {
        for (const p of ['emails.php?client=privacybee', 'pages.php?client=privacybee', 'flows.php?client=privacybee', 'manage.php?client=privacybee&section=tools']) {
          await page.goto(url(p));
          const L = await layout(page);
          checkLayout(L, expect, `${viewport} ${p}`);
          expect.eq(L.tabs.map((t) => t.label).join(','), 'Home,Assets,Posts,Emails,Pages,Manage', p);
        }
      });
      await test('every module on: Emails/Pages share one tab, still ≤ 6, the switch moves between them', async (page) => {
        sql("INSERT IGNORE INTO company_modules (company_id, module_id, sort_order) SELECT 2, id, 1 FROM modules WHERE slug = 'tires'");
        try {
          await page.goto(url('emails.php?client=privacybee'));
          const L = await layout(page);
          checkLayout(L, expect, `${viewport} merged`);
          expect.eq(L.tabs.map((t) => t.key).join(','), 'home,assets,tires,posts,emails,manage');
          expect.eq(L.tabs[4].label, phone ? 'Emails' : 'Emails/Pages', 'merged label');
          await Promise.all([page.waitForURL(/pages\.php/), page.click('[data-mail-switch="pages"]')]);
          expect(await page.$eval('.ui-tab--emails', (a) => a.classList.contains('is-active')), 'Pages lights the merged tab');
        } finally {
          sql("DELETE cm FROM company_modules cm JOIN modules m ON m.id = cm.module_id WHERE cm.company_id = 2 AND m.slug = 'tires'");
        }
      });
      await test('Emails / Pages / Posts status segments: no label clipped or overlapping, active in view', async (page) => {
        for (const [p, label] of [['emails.php?client=privacybee&status=denied', 'Email status'], ['emails.php?client=privacybee', 'Email status'],
                                  ['pages.php?client=privacybee&status=denied', 'Page status'], ['pages.php?client=privacybee', 'Page status'],
                                  ['posts.php?client=kenda&status=denied&month=all', 'Post status']]) {
          await page.goto(url(p));
          const m = await page.$eval(`.ui-segmented[aria-label="${label}"]`, (s) => {
            const items = [...s.querySelectorAll('.ui-segmented-item')];
            const boxes = items.map((i) => i.getBoundingClientRect());
            const sr = s.getBoundingClientRect();
            const overlaps = [];
            for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
              if (Math.min(boxes[i].right, boxes[j].right) - Math.max(boxes[i].left, boxes[j].left) > 0.5) overlaps.push(items[i].textContent.trim() + ' / ' + items[j].textContent.trim());
            }
            // a label's own text must fit its segment (range rect = the rendered glyphs)
            const textOverflow = items.filter((i) => { const rg = document.createRange(); rg.selectNodeContents(i); const t = rg.getBoundingClientRect(), b = i.getBoundingClientRect(); return t.left < b.left - 0.5 || t.right > b.right + 0.5; }).map((i) => i.textContent.trim());
            const a = s.querySelector('.is-active').getBoundingClientRect();
            return {
              n: items.length,
              clipped: items.filter((i) => i.scrollWidth > i.clientWidth + 1).map((i) => i.textContent.trim()),
              textOverflow, overlaps,
              doc: document.documentElement.scrollWidth, vw: window.innerWidth,
              inside: sr.left >= -0.5 && sr.right <= window.innerWidth + 0.5,
              active: a.left >= sr.left - 1 && a.right <= sr.right + 1,
            };
          });
          const where = `${viewport} ${p}`;
          expect.eq(m.n, 5, `${where}: five admin segments`);
          expect.eq(m.clipped.length, 0, `${where}: clipped labels ${JSON.stringify(m.clipped)}`);
          expect.eq(m.textOverflow.length, 0, `${where}: labels run past their segment ${JSON.stringify(m.textOverflow)}`);
          expect.eq(m.overlaps.length, 0, `${where}: overlapping segments ${JSON.stringify(m.overlaps)}`);
          expect(m.doc <= m.vw, `${where}: no horizontal page scroll (${m.doc} > ${m.vw})`);
          expect(m.inside, `${where}: the control stays inside the viewport`);
          expect(m.active, `${where}: the active segment is in view`);
        }
      });
      await test('Posts header: one "+ New", the title is never truncated (All months)', async (page) => {
        await page.goto(url('posts.php?client=kenda&month=all'));
        expect.eq(await page.$$eval('.ui-nav [data-new-menu-toggle]', (e) => e.length), 1, 'the global + New');
        expect.eq(await page.$$eval('.ui-nav [data-newpost], .ui-nav .posts-new', (e) => e.length), 0, 'no second New post');
        const L = await layout(page);
        expect.eq(L.title.text, 'Posts');
        checkLayout(L, expect, `${viewport} posts`);
        expect.eq((await page.innerText('.posts-month-pill')).trim(), phone ? 'All' : 'All months', 'month pill');
      });
    }, { role: 'admin', viewports: vps });

    await run('nav-layout-client', async ({ test, url, expect }) => {
      await test('client: unchanged tabs, no Manage, nothing overlaps', async (page) => {
        for (const [p, keys] of [['?client=kenda', 'home,assets,tires,posts,projects'], ['posts.php?client=kenda', 'home,assets,tires,posts,projects'],
                                 ['emails.php?client=privacybee', 'home,assets,posts,emails,pages,projects']]) {
          await page.goto(url(p));
          const L = await layout(page);
          expect.eq(L.tabs.map((t) => t.key).join(','), keys, p);
          checkLayout(L, expect, `client ${p}`);
        }
        await page.goto(url('manage.php?client=kenda'));
        expect(/login/.test(page.url()), 'Manage → sign-in: ' + page.url());
      });
    }, { role: 'client', viewports: vps });
  }

  // ---- Manage sections + tools ---------------------------------------------------------------------
  await run('manage', async ({ test, url, expect, viewport }) => {
    await test('the Manage tab → Clients (this client) → Export → Drive → Tools', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      await Promise.all([page.waitForURL(/manage\.php/), page.click('.ui-tabbar [data-tab="manage"]')]);
      await page.waitForSelector('[data-client-edit="1"]');
      expect(await page.isVisible('[data-client-settings]'), 'Settings');
      expect.eq(await page.$$eval('[data-client-modules] [data-client-module]', (e) => e.length), 3, 'Tires / Emails / Pages toggles');
      await Promise.all([page.waitForURL(/section=export/), page.click('[data-manage-section-link="export"]')]);
      await page.waitForFunction(() => { const e = document.querySelector('[data-export-estimate]'); return e && !/Counting/.test(e.textContent); }, null, { timeout: 15000 });
      await page.waitForFunction(() => { const e = document.querySelector('[data-previews-status]'); return e && !/Checking/.test(e.textContent); }, null, { timeout: 15000 });
      await Promise.all([page.waitForURL(/drive\.php/), page.click('[data-manage-section-link="drive"]')]);
      expect(await page.$eval('[data-manage-section-link="drive"]', (a) => a.classList.contains('is-active')), 'Drive active');
      expect(await page.$eval('.ui-tabbar [data-tab="manage"]', (a) => a.classList.contains('is-active')), 'Manage tab stays lit');
      await Promise.all([page.waitForURL(/section=tools/), page.click('[data-manage-section-link="tools"]')]);
      for (const k of ['new-tire', 'series', 'builder', 'prompts', 'vehicles', 'projects', 'digest']) expect(await page.$(`[data-tool="${k}"]`), k);
      await Promise.all([page.waitForURL(/prompts\.php/), page.click('[data-tool="prompts"]')]);
      await Promise.all([page.waitForURL(/manage\.php\?(client=kenda&)?section=tools/), page.click('.ui-back')]);
    });
    await test('module toggle in Manage → Clients: Emails on for Hollow Mill Farm → its tab appears', async (page) => {
      await page.goto(url('manage.php?client=hmf'));
      const row = '[data-client-module="emails"]';
      expect.eq(await page.getAttribute(row + ' [data-client-module-state]', 'data-client-module-state'), 'off');
      await Promise.all([page.waitForNavigation(), page.click(row + ' button[type="submit"]')]);
      expect.eq(await page.getAttribute(row + ' [data-client-module-state]', 'data-client-module-state'), 'on');
      expect(await page.$('.ui-tabbar [data-tab="emails"]'), 'Emails tab now');
      await Promise.all([page.waitForNavigation(), page.click(row + ' button[type="submit"]')]);
      expect(!(await page.$('.ui-tabbar [data-tab="emails"]')), 'gone again');
    });
    await test('client settings save (default hashtags → the New post pop-up)', async (page) => {
      await page.goto(url('manage.php?client=kenda'));
      await page.fill('[data-client-hashtags]', '#KendaTires #Grip');
      await Promise.all([page.waitForNavigation(), page.click('[data-client-settings] button[type="submit"]')]);
      expect.eq(await page.inputValue('[data-client-hashtags]'), '#KendaTires #Grip');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'viewport' });

  // ---- Manage series on the tire ---------------------------------------------------------------------
  await run('series', async ({ test, url, expect }) => {
    await test('⋯ → Manage series: rename, reorder, Drive link, add a series; closing refreshes the chips', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1'));
      await page.click('[data-series-menu]');
      await page.click('[data-series-manage]');
      await page.waitForSelector('#seriesManageSheet.is-open [data-sm-row]');
      expect.eq(await page.$$eval('#seriesManageSheet [data-sm-row]', (e) => e.length), 2);
      // rename series 2
      await page.fill('#seriesManageSheet [data-sm-row="2"] [data-sm-name]', 'Series Two');
      await page.click('#seriesManageSheet [data-sm-row="2"] [data-sm-rename]');
      await page.waitForFunction(() => document.querySelector('#seriesManageSheet [data-sm-row="2"] [data-sm-name]').value === 'Series Two' && !document.querySelector('#seriesManageSheet [data-sm-row="2"] [data-sm-rename]').disabled);
      // move it up
      await page.click('#seriesManageSheet [data-sm-row="2"] [data-sm-up]');
      await page.waitForFunction(() => document.querySelector('#seriesManageSheet [data-sm-row]').getAttribute('data-sm-row') === '2');
      // a bad Drive link is refused, a good one saved
      await page.fill('#seriesManageSheet [data-sm-row="1"] [data-sm-drive]', 'https://example.com/folder');
      await page.click('#seriesManageSheet [data-sm-row="1"] [data-sm-drive-save]');
      expect.eq(await page.getAttribute('#seriesManageSheet [data-sm-row="1"] [data-sm-drive]', 'aria-invalid'), 'true');
      await page.fill('#seriesManageSheet [data-sm-row="1"] [data-sm-drive]', 'https://drive.google.com/drive/folders/series1');
      await page.click('#seriesManageSheet [data-sm-row="1"] [data-sm-drive-save]');
      await page.waitForSelector('#seriesManageSheet [data-sm-row="1"] .sm-drive.is-set');
      // add a series with a Drive link
      await page.fill('#seriesManageSheet [data-sm-new-name]', 'Launch shoot');
      await page.fill('#seriesManageSheet [data-sm-new-drive]', 'https://drive.google.com/drive/folders/launch');
      await page.click('#seriesManageSheet [data-sm-add] button[type="submit"]');
      await page.waitForFunction(() => document.querySelectorAll('#seriesManageSheet [data-sm-row]').length === 3);
      // rescan answers
      await page.click('#seriesManageSheet [data-sm-rescan]');
      await page.waitForFunction(() => !document.querySelector('#seriesManageSheet [data-sm-rescan]').disabled);
      // close → reload: the chips follow (order + names + the new one)
      await Promise.all([page.waitForNavigation(), page.click('#seriesManageSheet [data-sheet-close]')]);
      const chips = await page.$$eval('[data-series-chip]', (els) => els.map((e) => e.textContent.replace(/\s+/g, ' ').trim()));
      expect(/^Series Two/.test(chips[1]) && /^Series 1/.test(chips[2]) && /^Launch shoot/.test(chips[3]), 'chips: ' + chips.join(' | '));
    });
    await test('the old Studio Renders link opens Manage series on that tire', async (page) => {
      await page.goto(url('studio.php?client=kenda&tab=renders&tire=2'));
      await page.waitForSelector('#seriesManageSheet.is-open');
      expect(/item=2/.test(page.url()) && !/manage=/.test(page.url()), 'clean URL: ' + page.url());
      expect((await page.textContent('#seriesManageSheet [data-sm-folder]')).indexOf('media/tires/') === 0, 'FTP folder');
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });
})();
