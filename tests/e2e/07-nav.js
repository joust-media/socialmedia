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
    if (/^(Posts|Assets|Tires|Manage|Emails|Pages|Flows|Drive|Projects|Today|AI Builder|Prompt Library|Vehicle Library|New tire|Edit tire|New prompt|New vehicle)$/.test(L.title.text)) expect(!L.title.truncated, `${where}: title "${L.title.text}" not truncated`);
    // clear of the trailing buttons when they share a row (tool pages put the large title on its own row on phones)
    const sameRow = L.trailing && L.title.box.t < L.trailing.b - 0.5 && L.title.box.b > L.trailing.t + 0.5;
    if (sameRow) expect(L.title.box.r <= L.trailing.l + 0.5, `${where}: title clear of the trailing buttons`);
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
                         'manage.php?client=kenda', 'manage.php?client=kenda&section=tools', 'manage.php?client=kenda&section=export', 'drive.php', 'manage.php',
                         'build.php?client=kenda', 'add-feature.php?client=kenda&module=tires', 'add-feature.php?client=kenda&module=tires&edit_item=1',
                         'prompts.php', 'vehicles.php', 'add-prompt.php', 'add-vehicle.php']) {
          await page.goto(url(p));
          const L = await layout(page);
          checkLayout(L, expect, `${viewport} ${p}`);
          if (p.indexOf('client=kenda') !== -1) expect.eq(L.tabs.map((t) => t.key).join(','), 'home,assets,tires,posts,manage', p);
          if (L.title) expect(!L.title.truncated || !/^(Prompt Library|Vehicle Library|AI Builder|New tire|Edit tire|Tires)$/.test(L.title.text), `${viewport} ${p}: title "${L.title.text}" not truncated`);
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

  // ---- one page frame: the same title colour, title position and column width on every top-level page ---------
  const TOP = ['?client=kenda', 'assets.php?client=kenda', 'assets.php?client=kenda&view=collections', 'posts.php?client=kenda&month=all',
               'emails.php?client=privacybee', 'pages.php?client=privacybee', 'manage.php?client=kenda', 'manage.php?client=kenda&section=export',
               'manage.php?client=kenda&section=tools', 'drive.php'];
  await run('frame', async ({ test, url, expect, viewport }) => {
    for (const theme of ['light', 'dark']) {
      await test(`top-level pages share one frame (${theme})`, async (page) => {
        await page.addInitScript((t) => { try { localStorage.setItem('portal.theme', t); } catch (e) {} }, theme);
        const seen = [];
        for (const p of TOP) {
          await page.goto(url(p));
          seen.push(Object.assign({ p }, await page.evaluate(() => {
            const t = document.querySelector('.ui-nav-title'), inner = document.querySelector('.ui-nav-inner'), main = document.querySelector('main.ui-page');
            const label = getComputedStyle(document.documentElement).getPropertyValue('--label').trim();
            const probe = document.createElement('span'); probe.style.color = label; document.body.appendChild(probe);
            const labelRgb = getComputedStyle(probe).color; probe.remove();
            return { color: getComputedStyle(t).color, labelRgb, titleLeft: Math.round(t.getBoundingClientRect().left),
                     navW: Math.round(inner.getBoundingClientRect().width), mainW: Math.round(main.getBoundingClientRect().width),
                     mainLeft: Math.round(main.getBoundingClientRect().left), size: getComputedStyle(t).fontSize };
          })));
        }
        const first = seen[0];
        for (const s of seen) {
          expect.eq(s.color, s.labelRgb, `${viewport} ${theme} ${s.p}: title in the label colour`);
          expect(s.color !== 'rgb(255, 106, 61)', `${s.p}: never Joust orange`);
          expect.eq(s.color, first.color, `${s.p}: same title colour as Home`);
          expect.eq(s.titleLeft, first.titleLeft, `${viewport} ${s.p}: title starts where Home's does`);
          expect.eq(s.navW, first.navW, `${viewport} ${s.p}: same nav column`);
          expect.eq(s.mainW, first.mainW, `${viewport} ${s.p}: same content column`);
          expect.eq(s.mainLeft, first.mainLeft, `${viewport} ${s.p}: content column aligned`);
          expect.eq(s.size, first.size, `${viewport} ${s.p}: same large-title size`);
        }
      });
    }
  }, { role: 'admin', viewports: ['desktop', 'phone'] });

  // ---- tool pages: shared shell, no overflow at 320, follow Appearance ------------------------------------------
  const TOOLS = ['build.php?client=kenda', 'add-feature.php?client=kenda&module=tires', 'add-feature.php?client=kenda&module=tires&edit_item=1',
                 'prompts.php', 'prompts.php?tag=studio', 'vehicles.php', 'add-prompt.php?edit=1', 'add-vehicle.php'];
  await run('tools', async ({ test, url, expect, viewport }) => {
    await test('no horizontal overflow, no Sign out row, the title in full', async (page) => {
      for (const p of TOOLS) {
        await page.goto(url(p));
        const m = await page.evaluate(() => {
          const t = document.querySelector('.ui-nav-title');
          const over = Array.from(document.querySelectorAll('main *, .ui-nav *')).filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > window.innerWidth + 1 && !e.closest('.tl-table-wrap, .studio-chips, .ui-nav-links'); }).map((e) => e.tagName + '.' + e.className).slice(0, 3);
          const signOut = Array.from(document.querySelectorAll('.ui-nav a, main a')).some((a) => /sign out/i.test(a.textContent));
          return { doc: document.documentElement.scrollWidth, vw: window.innerWidth, over, signOut, title: t.textContent.trim(), truncated: t.scrollWidth > t.clientWidth + 1 };
        });
        expect(m.doc <= m.vw, `${viewport} ${p}: no horizontal scroll (${m.doc} > ${m.vw})`);
        expect.eq(m.over.length, 0, `${viewport} ${p}: nothing past the right edge ${JSON.stringify(m.over)}`);
        expect(!m.signOut, `${viewport} ${p}: no Sign out row`);
        expect(!m.truncated, `${viewport} ${p}: title "${m.title}" not truncated`);
      }
      // the Search button stays whole on a 320px phone
      for (const p of ['prompts.php', 'vehicles.php']) {
        await page.goto(url(p));
        const b = await page.$eval('[data-tool-search] button[type="submit"]', (el) => { const r = el.getBoundingClientRect(); return { r: r.right, vw: window.innerWidth, clip: el.scrollWidth > el.clientWidth + 1 }; });
        expect(b.r <= b.vw && !b.clip, `${viewport} ${p}: Search button inside the screen`);
      }
    });
    await test('the AI Builder follows Appearance (light and dark), and still composes a prompt', async (page) => {
      for (const theme of ['light', 'dark']) {
        await page.addInitScript((t) => { try { localStorage.setItem('portal.theme', t); } catch (e) {} }, theme);
        await page.goto(url('build.php?client=kenda'));
        expect.eq(await page.evaluate(() => document.documentElement.getAttribute('data-theme')), theme, 'theme from Appearance');
        expect(!(await page.evaluate(() => document.documentElement.hasAttribute('data-theme-pinned'))), 'not pinned');
      }
      for (const id of ['sel-camera', 'sel-lighting', 'sel-environment', 'sel-product']) {
        const v = await page.$eval('#' + id, (s) => { const o = Array.from(s.options).find((x) => x.value && !x.disabled); return o ? o.value : ''; });
        if (v) await page.selectOption('#' + id, v);
      }
      await page.click('#refGrid [data-ref]');
      expect.eq(await page.getAttribute('#refGrid [data-ref]', 'aria-pressed'), 'true', 'tile selected');
      expect(await page.isEnabled('#downloadBtn'), 'Download enabled');
      expect((await page.inputValue('#finalText')).length > 10, 'final prompt composed');
      expect(await page.isEnabled('#copyBtn'), 'Copy enabled');
    });
  }, { role: 'admin', viewports: ['w320', 'phone', 'desktop'] });

  // ---- New tire: from "+ New" and from the Tires list; the selection bar on a tire's grid ----------------------
  await run('tires', async ({ test, url, expect }) => {
    await test('+ New → New tire opens the form; the Tires list has the same button', async (page) => {
      await page.goto(url('posts.php?client=kenda'));
      await page.click('[data-new-menu-toggle]');
      await Promise.all([page.waitForURL(/add-feature\.php\?client=kenda&module=tires$/), page.click('[data-new-action="tire"]')]);
      expect.eq((await page.textContent('.ui-nav-title')).trim(), 'New tire');
      await page.fill('#item_name', 'E2E Mud Pro');
      await Promise.all([page.waitForNavigation(), page.click('[data-tire-form] button[type="submit"]')]);
      expect(/msg=/.test(page.url()), 'saved: ' + page.url());
      await page.goto(url('assets.php?client=kenda&view=collections'));
      expect(await page.isVisible('text=E2E Mud Pro'), 'on the Tires list');
      await Promise.all([page.waitForURL(/add-feature\.php/), page.click('[data-new-tire]')]);
    });
    await test('Edit tire: status chips save in place; prompt form inserts variables', async (page) => {
      await page.goto(url('add-feature.php?client=kenda&module=tires&edit_item=1'));
      const row = '[data-tire-row]:first-of-type';
      await page.click(row + ' [data-status-set="pending"]');   // (Needs changes asks for a note — tire-status.php 422 — so To Review here)
      await page.waitForFunction((r) => { const el = document.querySelector(r + ' [data-status-hint]'); return el && /Saved/.test(el.textContent); }, row);
      expect(await page.$eval(row + ' [data-status-set="pending"]', (b) => b.classList.contains('is-active')), 'To Review on');
      const id = await page.getAttribute(row, 'data-image-id');
      await page.click(row + ' [data-status-set="approved"]');
      await page.waitForFunction((r) => document.querySelector(r).getAttribute('data-status') === 'approved', row);
      await page.check(row + ' [data-tire-remove]');
      expect(await page.$eval(row, (r) => r.classList.contains('marked')), 'marked for removal');
      await page.uncheck(row + ' [data-tire-remove]');
      expect(!!id, 'row id');
      await page.goto(url('add-prompt.php'));
      await page.click('[data-var="brand_name"]');
      expect((await page.inputValue('#prompt_text')).indexOf('{{brand_name}}') !== -1, 'variable inserted');
      await page.fill('#prompt_text', 'Bad {{nope}}');
      await page.waitForFunction(() => document.getElementById('submitBtn').disabled);
      await page.click('[data-model-chip]');
      expect(await page.$eval('[data-model-chip]', (c) => c.classList.contains('is-active')), 'model chip on');
    });
    await test('Tires tab → a tire → Select approved → Create post · Download · Export', async (page) => {
      await page.goto(url('assets.php?client=kenda'));
      expect(!(await page.$('[aria-label="Assets view"]')), 'no Library · Tires switch');
      await Promise.all([page.waitForURL(/view=collections/), page.click('.ui-tabbar [data-tab="tires"]')]);
      await page.goto(url('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved'));
      await page.click('[data-assets-select]');
      const tiles = await page.$$('#assetsGrid .as-thumb[data-status="approved"]');
      await tiles[0].click(); await tiles[1].click();
      for (const s of ['[data-select-post]', '[data-select-download]', '[data-select-export]']) expect(await page.isEnabled(s), s + ' enabled');
      const [csv] = await Promise.all([page.waitForEvent('download', { timeout: 20000 }), page.click('[data-select-export]')]);
      expect(/manifest\.csv$/.test(csv.suggestedFilename()), csv.suggestedFilename());
      await page.click('[data-select-post]');
      await page.waitForFunction(() => window.App && App.newPost && App.newPost._state && App.newPost._state() && App.newPost._state().slides.length === 2, null, { timeout: 10000 });
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });
})();
