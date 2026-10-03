/* Notifications in the browser: Manage → Notifications (setup state, Send test → toast, phone layout), Home "Latest
   notes" with every unanswered client comment, the admin tire views' "All approved ✓", an internal note from Slack
   (Joust sees it with the Internal pill, the client never does), and a rendered preview of the Slack Block Kit parent
   the fake Slack received. Screens: $PORTAL_TEST_ROOT/shots/notify/*.png (+ blockkit.json). */
'use strict';
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const { run, BASE } = require('./lib');

const ROOT = process.env.PORTAL_TEST_ROOT || '/tmp/portal-test';
const SHOTS = path.join(ROOT, 'shots', 'notify');
fs.mkdirSync(SHOTS, { recursive: true });
const SIGNING = 'test-signing-secret-abcdef';

/** A request in a given seat (the cookie the test-auth shim reads), outside the page. */
async function as(role, p, form, headers = {}) {
  const res = await fetch(BASE + '/' + p, {
    method: form === undefined ? 'GET' : 'POST',
    headers: Object.assign({ Cookie: 'portal_test_role=' + role, Accept: 'application/json' },
      form === undefined ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' }, headers),
    body: form === undefined ? undefined : (typeof form === 'string' ? form : new URLSearchParams(form).toString()),
  });
  return { status: res.status, text: await res.text() };
}
const clientComment = (id, text, slug = 'kenda') => as('client', 'status.php', { id, comment: text, client: slug });
function slackCalls() {
  const f = path.join(ROOT, 'slack-calls.jsonl');
  return fs.existsSync(f) ? fs.readFileSync(f, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)) : [];
}
async function slackEvent(event) {
  const body = JSON.stringify({ type: 'event_callback', event_id: 'Ev' + crypto.randomBytes(5).toString('hex'), event });
  const ts = Math.floor(Date.now() / 1000);
  const sig = 'v0=' + crypto.createHmac('sha256', SIGNING).update(`v0:${ts}:${body}`).digest('hex');
  await fetch(BASE + '/slack-events.php', { method: 'POST', body, headers: { 'Content-Type': 'application/json', 'X-Slack-Request-Timestamp': String(ts), 'X-Slack-Signature': sig } });
  await fetch(BASE + '/login.php');   // php -S: returns once the post-ack work is done
}
const noOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);

/** A Slack-ish rendering of one chat.postMessage / chat.update body (Block Kit subset the portal uses). */
function blockKitHtml(msgs) {
  const esc = (s) => String(s).replace(/&(?!amp;|lt;|gt;)/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const mrkdwn = (s) => esc(String(s).replace(/&lt;/g, '\u0001').replace(/&gt;/g, '\u0002'))
    .replace(/&lt;!date\^\d+\^[^|]*\|([^&]*)&gt;/g, '$1')
    .replace(/&lt;@(U\w+)&gt;/g, '<span class="mention">@Lance</span>')
    .replace(/&lt;(https?:[^|]+?)\|(.+?)&gt;/g, '<a href="#">$2</a>')
    .replace(/\*([^*\n]+)\*/g, '<b>$1</b>')
    .replace(/:large_yellow_circle:/g, '🟡').replace(/:white_check_mark:/g, '✅').replace(/:red_circle:/g, '🔴')
    .replace(/:calendar:/g, '📅').replace(/:large_green_circle:/g, '🟢').replace(/:memo:/g, '📝').replace(/:speech_balloon:/g, '💬')
    .replace(/:hourglass_flowing_sand:/g, '⏳').replace(/:alarm_clock:/g, '⏰').replace(/:pencil2:/g, '✏️')
    .replace(/^&gt; ?(.*)$/gm, '<span class="quote">$1</span>').replace(/\u0001/g, '&lt;').replace(/\u0002/g, '&gt;').replace(/\n/g, '<br>');
  const block = (b) => {
    if (b.type === 'section') return `<div class="section"><div class="txt">${mrkdwn(b.text.text)}</div>${b.accessory ? `<img class="thumb" src="${esc(b.accessory.image_url)}" alt="">` : ''}</div>`;
    if (b.type === 'context') return `<div class="context">${b.elements.map((e) => `<span>${mrkdwn(e.text)}</span>`).join('')}</div>`;
    if (b.type === 'actions') return `<div class="actions">${b.elements.map((e) => `<span class="btn${e.style === 'primary' ? ' primary' : ''}">${esc(e.text.text)}</span>`).join('')}</div>`;
    return '';
  };
  const parent = msgs.find((m) => m.body.blocks);
  const replies = msgs.filter((m) => m.body.thread_ts);
  return `<!doctype html><meta charset="utf-8"><style>
    body{font:15px/1.46 -apple-system,Segoe UI,Lato,sans-serif;margin:0;background:#fff;color:#1d1c1d;display:flex}
    .pane{flex:1;padding:20px 24px;border-right:1px solid #ddd;min-width:0} h3{margin:0 0 12px;font-size:15px;color:#616061}
    .msg{display:flex;gap:10px;margin-bottom:14px}.av{width:36px;height:36px;border-radius:8px;background:#ff6a3d;color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;flex:0 0 36px}
    .name{font-weight:900}.app{font-size:10px;background:#ddd;border-radius:3px;padding:1px 3px;margin-left:4px;color:#555}
    .section{display:flex;gap:12px;justify-content:space-between}.thumb{width:88px;height:88px;object-fit:cover;border-radius:6px;background:#eee}
    .context{display:flex;gap:14px;font-size:13px;color:#616061;margin-top:6px}.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
    .btn{border:1px solid #ccc;border-radius:4px;padding:3px 10px;font-weight:700;font-size:13px}.btn.primary{background:#007a5a;border-color:#007a5a;color:#fff}
    .quote{display:block;border-left:4px solid #ddd;padding-left:10px;color:#1d1c1d}.mention{background:#e8f5fa;color:#1264a3;border-radius:3px;padding:0 2px}
    a{color:#1264a3;text-decoration:none} pre{font-size:11px;white-space:pre-wrap;word-break:break-all;background:#f8f8f8;padding:10px;border-radius:6px;max-height:820px;overflow:hidden}</style>
    <div class="pane"><h3>#portal-kenda</h3>
      <div class="msg"><div class="av">J</div><div style="flex:1"><span class="name">Joust Portal</span><span class="app">APP</span>${parent.body.blocks.map(block).join('')}</div></div>
      <h3 style="margin-top:22px">Thread · ${replies.length} repl${replies.length === 1 ? 'y' : 'ies'}</h3>
      ${replies.map((r) => `<div class="msg"><div class="av">J</div><div><span class="name">Joust Portal</span><span class="app">APP</span><div>${mrkdwn(r.body.text)}</div></div></div>`).join('')}
    </div>
    <div class="pane" style="max-width:560px"><h3>Block Kit JSON (chat.postMessage)</h3><pre>${esc(JSON.stringify(parent.body, null, 2))}</pre></div>`;
}

(async () => {
  await run('notify-admin', async ({ test, url, expect, viewport }) => {
    await test('Manage → Notifications: setup state, URLs, Send test → toast; no horizontal scroll', async (page) => {
      await page.goto(url('manage.php?client=kenda&section=notifications'));
      await page.waitForSelector('[data-notify-setup]');
      expect.eq(await page.$$eval('[data-config-check="set"]', (e) => e.length) >= 6, true, 'config keys set');
      expect(!(await page.content()).includes('xoxb-test-token'), 'no token in the page');
      expect(await noOverflow(page), 'no horizontal overflow');
      await page.screenshot({ path: path.join(SHOTS, `manage-notifications-${viewport}.png`), fullPage: true });
      await page.click('[data-notify-setup] [data-notify-action="test"]');
      await page.waitForSelector('#uiToast.is-visible', { timeout: 5000 });
      expect(/Test sent/.test(await page.textContent('#uiToast')), 'toast');
      expect(slackCalls().some((c) => c.method === 'conversations.open'), 'DM opened on the fake Slack');
    });

    await test('Manage → Notifications: the delivery log lists a failure; Retry delivers it', async (page) => {
      fs.writeFileSync(path.join(ROOT, 'slack-calls.jsonl.fail'), 'channel_not_found');
      await clientComment(1, 'Delivery log demo');
      fs.unlinkSync(path.join(ROOT, 'slack-calls.jsonl.fail'));
      await clientComment(2, 'Can we swap this tire angle?');
      await page.goto(url('manage.php?client=kenda&section=notifications&log=all#log'));
      await page.waitForSelector('[data-log-row] [data-log-status="failed"]');
      await page.screenshot({ path: path.join(SHOTS, `delivery-log-${viewport}.png`), fullPage: true });
      await page.click('[data-log-row] [data-notify-action="retry"]');
      await page.waitForSelector('#uiToast.is-visible', { timeout: 5000 });
      await page.waitForLoadState('load');
      await page.waitForFunction(() => !document.querySelector('[data-log-status="failed"]'), null, { timeout: 8000 });
    });

    await test('Home: Latest notes lists every unanswered client comment (To Review post too)', async (page) => {
      await clientComment(2, 'Can we swap this tire angle?');
      await clientComment(1, '[Slide 1] Love it — can the logo be bigger?');
      await page.goto(url('index.php?client=kenda'));
      await page.waitForSelector('[data-note-waiting]');
      const notes = await page.$$eval('[data-note-waiting]', (e) => e.map((n) => n.textContent));
      expect(notes.some((t) => /swap this tire angle/.test(t)), 'pending post note');
      expect(notes.some((t) => /logo be bigger/.test(t)), 'second note');
      const card = await page.$('.home-changes-notes');
      await card.scrollIntoViewIfNeeded();
      await page.screenshot({ path: path.join(SHOTS, `latest-notes-${viewport}.png`), fullPage: true });
    });

    await test('Tires: All approved ✓ on the list, the tire header, the chips and the Reference card (green)', async (page) => {
      await page.goto(url('assets.php?client=kenda&view=collections'));
      await page.waitForSelector('[data-allok="tire"]');
      const color = await page.$eval('[data-allok="tire"]', (e) => getComputedStyle(e).color);
      expect.eq(color, 'rgb(52, 199, 89)', 'approve green');
      expect(await page.$('[data-tire-denied]'), 'a tire with Needs changes is marked differently');
      await page.screenshot({ path: path.join(SHOTS, `tires-list-${viewport}.png`), fullPage: true });
      await page.goto(url('assets.php?client=kenda&view=collections&item=2'));
      await page.waitForSelector('[data-tire-state="allok"]');
      for (const s of ['page', 'reference', 'series-ref', 'series-3']) expect(await page.$(`[data-allok="${s}"]`), s);
      expect(await noOverflow(page), 'no horizontal overflow');
      await page.screenshot({ path: path.join(SHOTS, `tire-allok-${viewport}.png`), fullPage: false });
    });

    await test('Internal note from Slack: Joust sees it with the Internal pill', async (page) => {
      await clientComment(1, 'Can we see another angle?');
      await new Promise((r) => setTimeout(r, 100));
      const parent = slackCalls().find((c) => c.method === 'chat.postMessage' && c.body.blocks);
      await slackEvent({ type: 'message', channel: 'C0KENDA', user: 'U0LANCE', text: 'Sure, sending options today', ts: '1800000000.000001', thread_ts: '1700000001.000001' });
      await slackEvent({ type: 'message', channel: 'C0KENDA', user: 'U0LANCE', text: '!internal ask design for the 3/4 angle', ts: '1800000000.000002', thread_ts: '1700000001.000001' });
      expect(parent, 'thread exists');
      await page.goto(url('posts.php?client=kenda&post=1'));
      await page.waitForSelector('#uiSheet.is-open [data-internal-pill]');
      expect(/ask design/.test(await page.textContent('#uiSheet [data-thread]')), 'internal text');
      const sheet = await page.$('#uiSheet .pd-thread');
      await sheet.scrollIntoViewIfNeeded();
      await page.screenshot({ path: path.join(SHOTS, `internal-note-admin-${viewport}.png`) });
      if (viewport === 'desktop') {
        const calls = slackCalls().filter((c) => c.method === 'chat.postMessage');
        fs.writeFileSync(path.join(SHOTS, 'blockkit.json'), JSON.stringify(calls.find((c) => c.body.blocks).body, null, 2));
        const preview = await page.context().newPage();
        await preview.setViewportSize({ width: 1280, height: 900 });
        await preview.setContent(blockKitHtml(calls));
        await preview.screenshot({ path: path.join(SHOTS, 'blockkit-preview.png') });
        await preview.close();
      }
    });
  }, { role: 'admin', viewports: ['desktop', 'phone'], reseed: 'test' });

  await run('notify-client', async ({ test, url, expect, viewport }) => {
    await test('the client never sees an internal note (sheet, feed, Home) and reads "Lance at Joust"', async (page) => {
      await clientComment(1, 'Can we see another angle?');
      await slackEvent({ type: 'message', channel: 'C0KENDA', user: 'U0LANCE', text: 'Sure, sending options today', ts: '1800000000.000001', thread_ts: '1700000001.000001' });
      await slackEvent({ type: 'message', channel: 'C0KENDA', user: 'U0LANCE', text: '!internal ask design for the 3/4 angle', ts: '1800000000.000002', thread_ts: '1700000001.000001' });
      await page.goto(url('posts.php?client=kenda&post=1'));
      await page.waitForSelector('#uiSheet.is-open [data-thread] .pd-msg');
      const t = await page.textContent('#uiSheet [data-thread]');
      expect(/Sure, sending options today/.test(t), 'the public reply');
      expect(/Lance at Joust/.test(t), 'named author');
      expect(!/ask design/.test(t) && !(await page.$('[data-internal-pill]')), 'no internal note');
      await page.screenshot({ path: path.join(SHOTS, `client-thread-${viewport}.png`) });
      for (const p of ['index.php?client=kenda', 'feed.php?client=kenda']) {
        await page.goto(url(p));
        expect(!/ask design/.test(await page.content()), p);
      }
    });
  }, { role: 'client', viewports: ['desktop'], reseed: 'test' });
})();
