/* =====================================================================
   App.assign — where an email / page belongs (admin; server: assign.php)

   App.assign.move(kind, ids, {label})       "Move to client…" sheet (emails + pages)
   App.assign.flow(ids, {label})             "Add to flow…" sheet (emails): pick a flow (or a new
                                             one) and a position
   App.assign.audiences(ids, {label})        "Set audiences…" sheet (emails): checkboxes, mixed
                                             state for a selection (a dash = leave as is)
   App.assign.create('email' | 'page', {client})   "+ New → New email / New page" in place:
                                             client · title · HTML (file, paste or link) → a Draft
   App.assign.select(kind, on)               list multi-select → bulk bar (Move · Add to flow · Audiences)

   Entry points (delegated, any admin page — layout-bottom.php boots this file):
     [data-asg-menu-toggle] + [data-asg-menu]   ⋯ menus (email / page detail head, list rows)
     [data-assign="move|flow|audiences"][data-kind][data-ids][data-label]
     [data-asg-select="email|page"]             list header "Select" toggle
     App.newMenu.handle('email' | 'page')       the "+ New" menu items (their href = no-JS fallback)
   After a change the list row updates in place (moved rows leave, audience tags redraw) and the
   detail sheet it came from reopens fresh.
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  if (App.assign) return;
  var cfg = window.AssignConfig || {};

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function toast(msg, kind, dur) { if (App.toast) App.toast(msg, { kind: kind, duration: dur || (kind === 'error' ? 4000 : 2600) }); }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }
  function pageClient() { return cfg.client || (document.body && document.body.dataset.client) || ''; }
  function endpoint() { return cfg.endpoint || ((cfg.base || '') + '/assign.php'); }
  function idList(ids) { return (Array.isArray(ids) ? ids : String(ids || '').split(',')).map(function (x) { return parseInt(x, 10); }).filter(function (x) { return x > 0; }); }

  var CHECK = '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L19 7"/></svg>';
  var XMARK = '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

  /* ------------------------------------------------------------------ */
  /* Server                                                              */
  /* ------------------------------------------------------------------ */
  function options(kind, client, ids) {
    var qs = new URLSearchParams({ action: 'options', kind: kind, client: client || '' });
    if (ids && ids.length) qs.set('ids', ids.join(','));
    return fetch(endpoint() + '?' + qs.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        return res.json().catch(function () { return null; }).then(function (data) {
          if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || 'Could not load the choices (' + res.status + ')');
          return data;
        });
      });
  }
  function send(params) {
    if (App.post) return App.post(endpoint(), params);
    return Promise.resolve({ ok: false, error: 'App not ready' });
  }
  function sendForm(fd) {
    return fetch(endpoint(), { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: fd })
      .then(function (res) {
        return res.text().then(function (text) {
          var data = null; try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
          var ok = res.ok && !!data && data.ok !== false;
          return { ok: ok, status: res.status, data: data, error: ok ? null : ((data && data.error) || 'Request failed (' + res.status + ')') };
        });
      }).catch(function (err) { return { ok: false, status: 0, data: null, error: (err && err.message) || 'Network error' }; });
  }

  /* ------------------------------------------------------------------ */
  /* Sheet (own root, same shell as partials/sheet.php)                  */
  /* ------------------------------------------------------------------ */
  var sheetEl = null;
  var ctx = null;            // {returnTo: {kind, id}} — the detail to reopen on close
  function sheet() {
    if (sheetEl && document.body.contains(sheetEl)) return sheetEl;
    sheetEl = document.createElement('div');
    sheetEl.className = 'ui-sheet-root asg-sheet-root';
    sheetEl.id = 'asgSheet';
    sheetEl.setAttribute('aria-hidden', 'true');
    sheetEl.innerHTML =
      '<button type="button" class="ui-sheet-backdrop" tabindex="-1" aria-label="Close"></button>' +
      '<div class="ui-sheet asg-sheet" role="dialog" aria-modal="true" aria-labelledby="asgSheetTitle" tabindex="-1">' +
        '<div class="ui-sheet-grabber" aria-hidden="true"></div>' +
        '<header class="ui-sheet-header"><div class="ui-sheet-leading"></div>' +
          '<h2 class="ui-sheet-title" id="asgSheetTitle" data-sheet-title></h2>' +
          '<button type="button" class="ui-sheet-close" data-sheet-close aria-label="Close">' + XMARK + '</button></header>' +
        '<div class="ui-sheet-body asg-sheet-body" data-sheet-body></div>' +
        '<footer class="ui-sheet-footer ui-glass ui-glass--top asg-sheet-footer" data-sheet-footer hidden></footer>' +
      '</div>';
    document.body.appendChild(sheetEl);
    sheetEl.addEventListener('sheet:close', onSheetClose);
    return sheetEl;
  }
  function openSheet(title, body, footer, kindClass) {
    var root = sheet();
    root.setAttribute('data-asg-sheet', kindClass || '');
    if (App.sheet && App.sheet.current === root) {
      $('[data-sheet-title]', root).textContent = title;
      $('[data-sheet-body]', root).innerHTML = body;
      var f = $('[data-sheet-footer]', root); f.innerHTML = footer || ''; f.hidden = !footer;
    } else if (App.sheet) {
      App.sheet.open(root, { title: title, html: body, footer: footer || '', focus: false });
    }
    var first = $('[data-asg-autofocus]', root);
    if (first) setTimeout(function () { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } }, 80);
    return root;
  }
  function closeSheet() { if (App.sheet && App.sheet.current === sheetEl) App.sheet.close(); }
  function loadingBody(text) { return '<div class="asg-loading" role="status"><span class="asg-spinner" aria-hidden="true"></span>' + esc(text || 'Loading…') + '</div>'; }
  function errorBody(msg) { return '<div class="ui-empty asg-error" role="alert">' + esc(msg) + '</div>'; }
  function footerButtons(primary, opts) {
    opts = opts || {};
    return '<div class="asg-footer">' +
      (opts.leading || '') +
      '<span class="asg-footer-space"></span>' +
      '<button type="button" class="ui-btn ui-btn--gray" data-sheet-close>Cancel</button>' +
      '<button type="button" class="ui-btn ui-btn--filled" data-asg-submit' + (opts.enabled ? '' : ' disabled') + '>' + esc(primary) + '</button>' +
      '</div>';
  }
  function busy(root, on, label) {
    var b = $('[data-asg-submit]', root);
    if (!b) return;
    if (on) { b.dataset.label = b.textContent; b.textContent = label || 'Saving…'; b.disabled = true; b.setAttribute('aria-busy', 'true'); }
    else { b.textContent = b.dataset.label || b.textContent; b.disabled = false; b.removeAttribute('aria-busy'); }
  }

  /** Where was the action launched from? An open email / page detail is reopened after the sheet closes. */
  function rememberDetail(kind, ids) {
    var open = kind === 'page' ? (App.pages && App.pages.current) : (App.emails && App.emails.current);
    ctx = { returnTo: null, moved: false };
    if (open && ids.length === 1 && String(open.id) === String(ids[0]) && App.sheet && App.sheet.current && App.sheet.current.id === 'uiSheet') {
      ctx.returnTo = { kind: kind, id: String(ids[0]) };
    }
  }
  function onSheetClose() {
    var c = ctx; ctx = null;
    if (!c || !c.returnTo || c.moved) return;
    var api = c.returnTo.kind === 'page' ? App.pages : App.emails;
    if (api && api.open) setTimeout(function () { api.open(c.returnTo.id, { silent: true }); }, 30);
  }
  /** Drop the inline detail templates so the next open fetches the fresh partial. */
  function staleDetail(kind, ids) {
    ids.forEach(function (id) {
      var t = $('template[data-' + kind + '-template="' + id + '"]');
      if (t && t.parentNode) t.parentNode.removeChild(t);
    });
  }
  function rowOf(kind, id) { return $('[data-' + kind + '-item="' + id + '"]'); }

  /* ------------------------------------------------------------------ */
  /* ⋯ menus                                                             */
  /* ------------------------------------------------------------------ */
  function closeMenus(except) {
    $$('[data-asg-menu]').forEach(function (m) {
      if (m === except || m.hidden) return;
      m.hidden = true;
      m.style.top = m.style.left = m.style.right = m.style.bottom = '';
      var t = m.parentNode && $('[data-asg-menu-toggle]', m.parentNode);
      if (t) t.setAttribute('aria-expanded', 'false');
    });
  }
  function toggleMenu(btn) {
    var wrap = btn.parentNode, menu = wrap && $('[data-asg-menu]', wrap);
    if (!menu) return;
    var open = menu.hidden;
    closeMenus(menu);
    menu.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) return;
    menu._asgY = window.scrollY || 0;
    if (wrap.classList.contains('asg-row-more')) {
      // list rows clip (overflow: hidden for the swipe) → a viewport-fixed menu under the button
      var r = btn.getBoundingClientRect();
      menu.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
      menu.style.left = 'auto';
      var h = menu.offsetHeight || 160;
      if (r.bottom + 6 + h > window.innerHeight - 8) { menu.style.top = Math.max(8, r.top - 6 - h) + 'px'; }
      else menu.style.top = (r.bottom + 6) + 'px';
    }
    var first = $('[role="menuitem"]', menu);
    if (first) first.focus({ preventScroll: true });
  }

  /* ------------------------------------------------------------------ */
  /* Move to client…                                                     */
  /* ------------------------------------------------------------------ */
  function move(kind, ids, opts) {
    opts = opts || {};
    kind = kind === 'page' ? 'page' : 'email';
    ids = idList(ids);
    if (!ids.length) return;
    rememberDetail(kind, ids);
    var noun = kind === 'page' ? 'page' : 'email';
    var what = ids.length === 1 && opts.label ? opts.label : plural(ids.length, noun);
    var root = openSheet('Move to client', loadingBody(), '', 'move');
    var client = opts.client || pageClient();
    options(kind, client, ids).then(function (o) {
      var rows = o.clients.filter(function (c) { return !c.current; }).map(function (c) {
        var on = kind === 'page' ? c.pages : c.emails;
        return '<li><label class="ui-row ui-row--leading asg-pick" data-asg-client="' + esc(c.slug) + '">' +
          '<input type="radio" name="asg-to" value="' + esc(c.slug) + '" class="asg-radio-input">' +
          '<span class="ui-row-leading asg-pick-avatar">' + (c.avatar || '') + '</span>' +
          '<span class="ui-row-body"><span class="ui-row-title">' + esc(c.name) + '</span>' +
          '<span class="ui-row-subtitle">' + (on ? (kind === 'page' ? 'Has Pages' : 'Has Emails') : (kind === 'page' ? 'No pages yet' : 'No emails yet')) + '</span></span>' +
          '<span class="asg-radio" aria-hidden="true">' + CHECK + '</span></label></li>';
      }).join('');
      var body =
        '<form class="asg-form" data-asg-form="move" novalidate>' +
        '<p class="asg-lead">Move <strong>' + esc(what) + '</strong> from ' + esc(o.from.name) + ' to:</p>' +
        (rows ? '<ul class="ui-list asg-list" role="radiogroup" aria-label="Client">' + rows + '</ul>' : '<div class="ui-empty">There is no other client yet.</div>') +
        '<p class="asg-note">' + (kind === 'page'
          ? 'Its files move too: copied to the new client, checked, then removed here. Comments and history come along.'
          : 'Hosted HTML moves too (copied, checked, then removed here). Audiences come along; it leaves ' + esc(o.from.name) + '’s flows. Comments and history come along.') + '</p>' +
        '</form>';
      openSheet('Move to client', body, footerButtons('Move'), 'move');
      var form = $('[data-asg-form="move"]', root);
      form.addEventListener('change', function () {
        var pick = $('input[name="asg-to"]:checked', form);
        var b = $('[data-asg-submit]', root);
        $$('.asg-pick', form).forEach(function (l) { l.classList.toggle('is-checked', !!pick && l.getAttribute('data-asg-client') === pick.value); });
        if (b && pick) { b.disabled = false; b.textContent = 'Move to ' + (o.clients.filter(function (c) { return c.slug === pick.value; })[0] || {}).name; }
      });
      root._asgSubmit = function () {
        var pick = $('input[name="asg-to"]:checked', form);
        if (!pick) return;
        busy(root, true, 'Moving…');
        send({ action: 'move', kind: kind, ids: ids.join(','), to: pick.value, client: client }).then(function (r) {
          if (!r.ok) { busy(root, false); toast(r.error || 'Could not move', 'error'); return; }
          var d = r.data;
          if (ctx) ctx.moved = true;
          var msg = 'Moved ' + (d.moved === 1 && d.items[0] ? d.items[0].label : plural(d.moved, noun)) + ' to ' + d.to.name;
          var ren = (d.items || []).filter(function (it) { return it.renamed_from; });
          if (ren.length === 1) msg += ' · ' + (kind === 'page' ? '/' + ren[0].renamed_from + ' is now /' + ren[0].slug : ren[0].renamed_from + ' is now ' + ren[0].code);
          else if (ren.length > 1) msg += ' · ' + ren.length + ' renamed (already taken there)';
          if (d.cleanup_failed) msg += ' · some old files could not be removed';
          closeSheet();
          removeRows(kind, ids);
          exitSelect();
          toast(msg, 'success', 3600);
          document.dispatchEvent(new CustomEvent('assign:moved', { detail: { kind: kind, ids: ids, data: d } }));
        });
      };
    }).catch(function (err) { openSheet('Move to client', errorBody(err.message), footerButtons('Move'), 'move'); });
  }

  function removeRows(kind, ids) {
    ids.forEach(function (id) {
      var li = rowOf(kind, id);
      if (!li) return;
      var key = li.getAttribute('data-key');
      bumpCount(key, -1);
      if (App.remove) App.remove(li, maybeEmpty); else { li.parentNode.removeChild(li); maybeEmpty(); }
    });
    staleDetail(kind, ids);
  }
  function bumpCount(key, delta) {
    var seg = key ? $('.ui-segmented-item[data-segment="' + key + '"] .ui-segmented-count') : null;
    if (seg) seg.textContent = Math.max(0, (parseInt(seg.textContent, 10) || 0) + delta);
    var hdr = $('[data-segment-count]');
    if (hdr) hdr.textContent = Math.max(0, (parseInt(hdr.textContent, 10) || 0) + delta);
  }
  function maybeEmpty() {
    var list = $('[data-emails-list], [data-pages-list]');
    if (!list || $('.pl-item', list)) return;
    list.hidden = true;
    if (!$('.posts-empty')) {
      var empty = document.createElement('div');
      empty.className = 'ui-empty posts-empty';
      empty.textContent = 'Nothing here now.';
      list.parentNode.insertBefore(empty, list);
    }
  }

  /* ------------------------------------------------------------------ */
  /* Add to flow…                                                        */
  /* ------------------------------------------------------------------ */
  function flow(ids, opts) {
    opts = opts || {};
    ids = idList(ids);
    if (!ids.length) return;
    rememberDetail('email', ids);
    var what = ids.length === 1 && opts.label ? opts.label : plural(ids.length, 'email');
    var root = openSheet('Add to flow', loadingBody(), '', 'flow');
    var client = opts.client || pageClient();
    options('email', client, ids).then(function (o) {
      var rows = o.flows.map(function (f) {
        var inIt = f.steps.filter(function (s) { return ids.indexOf(s.email_id) >= 0; }).length;
        var all = inIt === ids.length;
        return '<li><label class="ui-row asg-pick' + (all ? ' is-disabled' : '') + '" data-asg-flow="' + f.id + '">' +
          '<input type="radio" name="asg-flow" value="' + f.id + '" class="asg-radio-input"' + (all ? ' disabled' : '') + '>' +
          '<span class="ui-row-body"><span class="ui-row-title">' + esc(f.name) + '</span>' +
          '<span class="ui-row-subtitle">' + plural(f.step_count, 'step') + (all ? ' · already in it' : (inIt ? ' · ' + inIt + ' already in it' : '')) + '</span></span>' +
          '<span class="asg-radio" aria-hidden="true">' + CHECK + '</span></label></li>';
      }).join('');
      rows += '<li><label class="ui-row asg-pick asg-pick--new" data-asg-flow="new">' +
        '<input type="radio" name="asg-flow" value="new" class="asg-radio-input">' +
        '<span class="ui-row-body"><span class="ui-row-title">New flow</span>' +
        '<input class="ui-input asg-new-input" type="text" name="new_flow" maxlength="120" placeholder="Name, e.g. Free trial" aria-label="New flow name" data-asg-new-flow></span>' +
        '<span class="asg-radio" aria-hidden="true">' + CHECK + '</span></label></li>';
      var body =
        '<form class="asg-form" data-asg-form="flow" novalidate>' +
        '<p class="asg-lead">Add <strong>' + esc(what) + '</strong> to a flow:</p>' +
        '<ul class="ui-list asg-list" role="radiogroup" aria-label="Flow">' + rows + '</ul>' +
        '<div class="asg-field" data-asg-position hidden>' +
          '<label class="asg-label" for="asg-pos">Position</label>' +
          '<select class="ui-select" id="asg-pos" name="position" data-asg-pos></select>' +
        '</div>' +
        '<p class="asg-note">Flows are the order a client’s emails send in. The timing between steps is set on the flow.</p>' +
        '</form>';
      openSheet('Add to flow', body, footerButtons('Add to flow'), 'flow');
      var form = $('[data-asg-form="flow"]', root);
      var posWrap = $('[data-asg-position]', form), pos = $('[data-asg-pos]', form);
      var posFor = null;   // the flow the position options were built for
      function refresh() {
        var pick = $('input[name="asg-flow"]:checked', form);
        var b = $('[data-asg-submit]', root);
        $$('.asg-pick', form).forEach(function (l) { l.classList.toggle('is-checked', !!pick && l.getAttribute('data-asg-flow') === pick.value); });
        if (!pick) { if (b) b.disabled = true; return; }
        var f = o.flows.filter(function (x) { return String(x.id) === pick.value; })[0];
        if (posFor === pick.value) {
          // same flow: keep the chosen position
        } else if (f && f.steps.length) {
          // "At the end" (default) · "First" · "After <code>" for every step but the last (= At the end)
          posFor = pick.value;
          var name = function (s, i) { return s.code || ('step ' + (i + 1)); };
          var last = f.steps[f.steps.length - 1];
          var html = '<option value="">At the end (after ' + esc(name(last, f.steps.length - 1)) + ')</option><option value="0">First (before ' + esc(name(f.steps[0], 0)) + ')</option>';
          f.steps.forEach(function (s, i) {
            if (i === f.steps.length - 1) return;
            html += '<option value="' + (i + 1) + '">After ' + esc(name(s, i)) + '</option>';
          });
          pos.innerHTML = html;
          posWrap.hidden = false;
        } else {
          // an empty flow or a new one: nothing to place it against — the field stays hidden (the email goes first)
          posFor = pick.value;
          pos.innerHTML = '<option value="">At the end</option>';
          posWrap.hidden = true;
        }
        var newName = $('[data-asg-new-flow]', form).value.trim();
        if (b) {
          b.disabled = pick.value === 'new' && !newName;
          b.textContent = pick.value === 'new' ? (newName ? 'Create “' + newName + '”' : 'Add to flow') : 'Add to ' + (f ? f.name : 'flow');
        }
      }
      form.addEventListener('change', refresh);
      form.addEventListener('input', function (e) {
        if (e.target.matches('[data-asg-new-flow]')) { var r = $('input[value="new"]', form); if (r && !r.checked) r.checked = true; refresh(); }
      });
      form.addEventListener('focusin', function (e) {
        if (e.target.matches('[data-asg-new-flow]')) { var r = $('input[value="new"]', form); if (r && !r.checked) { r.checked = true; refresh(); } }
      });
      root._asgSubmit = function () {
        var pick = $('input[name="asg-flow"]:checked', form);
        if (!pick) return;
        var params = { action: 'add_to_flow', kind: 'email', ids: ids.join(','), client: client, position: pos.value };
        if (pick.value === 'new') params.new_flow = $('[data-asg-new-flow]', form).value.trim(); else params.flow_id = pick.value;
        busy(root, true, 'Adding…');
        send(params).then(function (r) {
          if (!r.ok) { busy(root, false); toast(r.error || 'Could not add to the flow', 'error'); return; }
          var d = r.data, n = d.added.length;
          var msg = n === 1 ? 'Added to ' + d.flow.name + ' as step ' + (d.added[0].position + 1) : 'Added ' + n + ' emails to ' + d.flow.name;
          if (d.skipped) msg += ' · ' + d.skipped + ' already in it';
          staleDetail('email', ids);
          closeSheet();
          exitSelect();
          toast(msg, 'success', 3200);
          document.dispatchEvent(new CustomEvent('assign:flow', { detail: { ids: ids, data: d } }));
        });
      };
      refresh();
    }).catch(function (err) { openSheet('Add to flow', errorBody(err.message), footerButtons('Add to flow'), 'flow'); });
  }

  /* ------------------------------------------------------------------ */
  /* Set audiences…                                                      */
  /* ------------------------------------------------------------------ */
  function audiences(ids, opts) {
    opts = opts || {};
    ids = idList(ids);
    if (!ids.length) return;
    rememberDetail('email', ids);
    var many = ids.length > 1;
    var what = !many && opts.label ? opts.label : plural(ids.length, 'email');
    var root = openSheet('Audiences', loadingBody(), '', 'audiences');
    var client = opts.client || pageClient();
    options('email', client, ids).then(function (o) {
      var rows = o.audiences.map(function (a) {
        var state = a.selected === 0 ? 'off' : (a.selected >= ids.length ? 'on' : 'mixed');
        return '<li><label class="ui-row asg-pick asg-check-row" data-asg-audience="' + a.id + '" data-state="' + state + '" data-initial="' + state + '">' +
          '<input type="checkbox" class="asg-radio-input" value="' + a.id + '"' + (state === 'on' ? ' checked' : '') + '>' +
          '<span class="ui-row-body"><span class="ui-row-title">' + esc(a.name) + '</span>' +
          '<span class="ui-row-subtitle">' + plural(a.count, 'email') + (state === 'mixed' ? ' · ' + a.selected + ' of these' : '') + '</span></span>' +
          '<span class="asg-box" aria-hidden="true">' + CHECK + '<span class="asg-box-dash"></span></span></label></li>';
      }).join('');
      var body =
        '<form class="asg-form" data-asg-form="audiences" novalidate>' +
        '<p class="asg-lead">Who is <strong>' + esc(what) + '</strong> for?</p>' +
        (rows ? '<ul class="ui-list asg-list" aria-label="Audiences">' + rows + '</ul>' : '<p class="asg-note">No audiences yet — add the first one below.</p>') +
        '<div class="asg-field"><label class="asg-label" for="asg-new-aud">New audience</label>' +
        '<input class="ui-input" type="text" id="asg-new-aud" name="new" maxlength="400" placeholder="e.g. Leads — comma-separate several" data-asg-new-audience></div>' +
        '<p class="asg-note">' + (many ? 'Ticked = added to all ' + ids.length + ', cleared = removed from all, a dash = left as it is. ' : '') +
        'The client can filter their Emails by audience.</p>' +
        '</form>';
      openSheet('Audiences', body, footerButtons('Save', { enabled: true }), 'audiences');
      var form = $('[data-asg-form="audiences"]', root);
      $$('.asg-check-row', form).forEach(function (row) {
        var box = $('input', row);
        if (row.getAttribute('data-state') === 'mixed') box.indeterminate = true;
        box.setAttribute('aria-checked', row.getAttribute('data-state') === 'mixed' ? 'mixed' : (box.checked ? 'true' : 'false'));
      });
      form.addEventListener('change', function (e) {
        var row = e.target.closest('.asg-check-row'); if (!row) return;
        // a mixed row cycles: mixed → on → off → mixed (only when it started mixed)
        var st = row.getAttribute('data-state'), init = row.getAttribute('data-initial');
        var next = st === 'mixed' ? 'on' : (st === 'on' ? 'off' : (init === 'mixed' ? 'mixed' : 'on'));
        row.setAttribute('data-state', next);
        e.target.indeterminate = next === 'mixed';
        e.target.checked = next === 'on';
        e.target.setAttribute('aria-checked', next === 'mixed' ? 'mixed' : (next === 'on' ? 'true' : 'false'));
      });
      root._asgSubmit = function () {
        var add = [], remove = [];
        $$('.asg-check-row', form).forEach(function (row) {
          var st = row.getAttribute('data-state'), id = row.getAttribute('data-asg-audience');
          if (st === 'on') add.push(id); else if (st === 'off') remove.push(id);   // mixed = leave as it is
        });
        busy(root, true);
        send({ action: 'set_audiences', kind: 'email', ids: ids.join(','), add: add.join(','), remove: remove.join(','),
               'new': $('[data-asg-new-audience]', form).value, client: client }).then(function (r) {
          if (!r.ok) { busy(root, false); toast(r.error || 'Could not save the audiences', 'error'); return; }
          var d = r.data;
          d.items.forEach(function (it) { drawRowAudiences(it.id, it.audiences); });
          staleDetail('email', ids);
          closeSheet();
          exitSelect();
          toast(d.changed ? 'Audiences saved' + (many ? ' for ' + plural(d.changed, 'email') : '') : 'No changes', d.changed ? 'success' : undefined);
          document.dispatchEvent(new CustomEvent('assign:audiences', { detail: { ids: ids, data: d } }));
        });
      };
    }).catch(function (err) { openSheet('Audiences', errorBody(err.message), footerButtons('Save'), 'audiences'); });
  }
  function drawRowAudiences(id, list) {
    var li = rowOf('email', id); if (!li) return;
    var meta = $('.pl-meta', li); if (!meta) return;
    var span = $('.el-groups', meta);
    if (!list.length) { if (span) span.parentNode.removeChild(span); return; }
    var html = '<span class="pl-meta-sep">·</span>' + list.map(function (a) { return '<span class="el-tag">' + esc(a.name) + '</span>'; }).join('');
    if (span) { span.innerHTML = html; return; }
    span = document.createElement('span');
    span.className = 'pl-meta-item el-groups';
    span.innerHTML = html;
    var after = $('.el-prio', meta) || $('.ui-pill', meta);
    if (after && after.nextSibling) meta.insertBefore(span, after.nextSibling); else meta.appendChild(span);
  }

  /* ------------------------------------------------------------------ */
  /* + New → New email / New page (in place)                             */
  /* ------------------------------------------------------------------ */
  function create(kind, detail) {
    kind = kind === 'page' ? 'page' : 'email';
    detail = detail || {};
    var client = detail.client || pageClient();
    if (!client) return false;          // unscoped: the menu item's link (the full form) takes over
    ctx = null;
    var noun = kind === 'page' ? 'page' : 'email';
    var title = kind === 'page' ? 'New page' : 'New email';
    var fallback = detail.href || ((kind === 'page' ? cfg.newPageUrl : cfg.newEmailUrl) + '?client=' + encodeURIComponent(client));
    var root = openSheet(title, loadingBody(), '', 'create');
    options(kind, client, []).then(function (o) {
      var opts = o.clients.map(function (c) {
        return '<option value="' + esc(c.slug) + '"' + (c.current ? ' selected' : '') + '>' + esc(c.name) + '</option>';
      }).join('');
      var body =
        '<form class="asg-form asg-create" data-asg-form="create" novalidate>' +
        '<div class="asg-field"><label class="asg-label" for="asg-c-client">Client</label>' +
          '<select class="ui-select" id="asg-c-client" name="client" data-asg-c-client>' + opts + '</select></div>' +
        '<div class="asg-field"><label class="asg-label" for="asg-c-title">Title</label>' +
          '<input class="ui-input" type="text" id="asg-c-title" name="title" maxlength="' + (kind === 'page' ? 160 : 255) + '" required placeholder="' + (kind === 'page' ? 'Spring launch landing page' : 'Welcome to the trial') + '" data-asg-autofocus data-asg-c-title></div>' +
        (kind === 'email'
          ? '<div class="asg-field"><label class="asg-label" for="asg-c-code">ID <span class="asg-label-hint">— the sheet’s code, optional</span></label>' +
            '<input class="ui-input asg-code-input" type="text" id="asg-c-code" name="code" maxlength="32" placeholder="Auto" autocomplete="off"></div>'
          : '') +
        '<div class="asg-field"><span class="asg-label" id="asg-c-src-l">HTML</span>' +
          '<div class="ui-segmented ui-segmented--auto asg-src" role="group" aria-labelledby="asg-c-src-l">' +
            '<button type="button" class="ui-segmented-item is-active" data-value="file" aria-pressed="true">File</button>' +
            '<button type="button" class="ui-segmented-item" data-value="paste" aria-pressed="false">Paste</button>' +
            '<button type="button" class="ui-segmented-item" data-value="url" aria-pressed="false">Link</button>' +
          '</div>' +
          '<div class="asg-src-pane" data-src-pane="file">' +
            '<label class="asg-drop" data-asg-drop><input type="file" name="file" accept=".html,.htm,text/html" data-asg-c-file>' +
            '<span class="asg-drop-text" data-asg-drop-text>Choose an .html file <span class="asg-label-hint">or drop it here</span></span></label></div>' +
          '<div class="asg-src-pane" data-src-pane="paste" hidden>' +
            '<textarea class="ui-textarea asg-paste" name="html" rows="7" spellcheck="false" placeholder="&lt;!doctype html&gt;…" aria-label="Paste the HTML" data-asg-c-html></textarea></div>' +
          '<div class="asg-src-pane" data-src-pane="url" hidden>' +
            '<input class="ui-input" type="url" name="' + (kind === 'page' ? 'url' : 'html_url') + '" maxlength="512" placeholder="https://…" aria-label="Link to the hosted HTML" data-asg-c-url></div>' +
        '</div>' +
        '<p class="asg-note">Saved as a <strong>Draft</strong> — the client sees it once you send it for review. Leave the HTML empty to add it later.' +
          (kind === 'page' ? ' A page with images, CSS or several files: use the full form.' : '') + '</p>' +
        '</form>';
      var lead = '<a class="ui-btn ui-btn--plain asg-fallback" href="' + esc(fallback) + '" data-asg-fallback>Full form</a>';
      openSheet(title, body, footerButtons('Create draft', { enabled: true, leading: lead }), 'create');
      var form = $('[data-asg-form="create"]', root);
      var src = 'file';
      form.addEventListener('click', function (e) {
        var seg = e.target.closest('.asg-src .ui-segmented-item'); if (!seg) return;
        src = seg.getAttribute('data-value');
        $$('.asg-src .ui-segmented-item', form).forEach(function (b) { var on = b === seg; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        $$('[data-src-pane]', form).forEach(function (p) { p.hidden = p.getAttribute('data-src-pane') !== src; });
      });
      var file = $('[data-asg-c-file]', form), drop = $('[data-asg-drop]', form);
      file.addEventListener('change', function () {
        var f = file.files && file.files[0];
        $('[data-asg-drop-text]', form).innerHTML = f ? esc(f.name) + ' <span class="asg-label-hint">' + Math.max(1, Math.round(f.size / 1024)) + ' KB</span>' : 'Choose an .html file <span class="asg-label-hint">or drop it here</span>';
        drop.classList.toggle('has-file', !!f);
        var t = $('[data-asg-c-title]', form);
        if (f && t && !t.value.trim()) t.value = f.name.replace(/\.html?$/i, '').replace(/[-_]+/g, ' ').trim();
      });
      ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
      drop.addEventListener('drop', function (e) {
        e.preventDefault();
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { file.files = e.dataTransfer.files; file.dispatchEvent(new Event('change')); }
      });
      root._asgSubmit = function () {
        var t = $('[data-asg-c-title]', form);
        if (!t.value.trim()) { toast('Give the ' + noun + ' a title', 'error'); t.focus(); return; }
        var fd = new FormData();
        fd.append('action', kind === 'page' ? 'create_page' : 'create_email');
        fd.append('kind', kind);
        fd.append('client', $('[data-asg-c-client]', form).value);
        fd.append('title', t.value.trim());
        var code = $('[name="code"]', form); if (code) fd.append('code', code.value.trim());
        var f = file.files && file.files[0];
        var html = $('[data-asg-c-html]', form).value, url = $('[data-asg-c-url]', form).value.trim();
        if (src === 'file' && f) { fd.append('source', 'file'); fd.append('file', f, f.name); }
        else if (src === 'paste' && html.trim()) { fd.append('source', 'paste'); fd.append('html', html); }
        else if (src === 'url' && url) { fd.append('source', 'url'); fd.append(kind === 'page' ? 'url' : 'html_url', url); }
        else fd.append('source', 'none');
        busy(root, true, 'Creating…');
        sendForm(fd).then(function (r) {
          if (!r.ok) {
            busy(root, false);
            toast(r.error || 'Could not create the ' + noun, 'error');
            var field = r.data && r.data.field;
            var el = field === 'title' ? t : (field === 'code' ? code : null);
            if (el) el.focus();
            return;
          }
          toast(r.data.label + ' created as a draft', 'success');
          window.location.href = r.data.url;
        });
      };
    }).catch(function (err) {
      openSheet(title, errorBody(err.message) + '<p class="asg-note"><a href="' + esc(fallback) + '">Open the full form instead</a></p>', '', 'create');
    });
    return true;
  }

  /* ------------------------------------------------------------------ */
  /* Multi-select + bulk bar (emails.php / pages.php lists)              */
  /* ------------------------------------------------------------------ */
  var sel = null;   // {kind, ids: [], btn}
  var bar = null;
  function items(kind) { return $$('[data-' + kind + '-item]'); }
  function barEl(kind) {
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'asg-bulkbar ui-glass ui-glass--top';
      bar.setAttribute('role', 'region');
      bar.setAttribute('aria-label', 'Selected items');
      bar.setAttribute('data-asg-bulkbar', '');
      bar.hidden = true;
      document.body.appendChild(bar);
    }
    bar.setAttribute('data-kind', kind);
    bar.innerHTML =
      '<div class="asg-bulkbar-top"><span class="asg-bulkbar-count" data-asg-count aria-live="polite">Select ' + (kind === 'page' ? 'pages' : 'emails') + '</span>' +
      '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-asg-all>Select all</button></div>' +
      '<div class="asg-bulkbar-actions">' +
        '<button type="button" class="ui-btn ui-btn--tinted ui-btn--sm" data-asg-bulk="move" disabled>Move…</button>' +
        (kind === 'email'
          ? '<button type="button" class="ui-btn ui-btn--tinted ui-btn--sm" data-asg-bulk="flow" disabled>Add to flow…</button>' +
            '<button type="button" class="ui-btn ui-btn--tinted ui-btn--sm" data-asg-bulk="audiences" disabled>Audiences…</button>'
          : '') +
      '</div>';
    return bar;
  }
  function select(kind, on) {
    kind = kind === 'page' ? 'page' : 'email';
    if (on === undefined) on = !sel;
    if (!on) return exitSelect();
    closeMenus();
    sel = { kind: kind, ids: [], btn: $('[data-asg-select="' + kind + '"]') };
    document.body.classList.add('asg-selecting');
    if (sel.btn) { sel.btn.textContent = 'Cancel'; sel.btn.setAttribute('aria-pressed', 'true'); }
    items(kind).forEach(function (li) {
      li.classList.add('asg-selectable');
      if (!$('.asg-check', li)) { var c = document.createElement('span'); c.className = 'asg-check'; c.setAttribute('aria-hidden', 'true'); c.innerHTML = CHECK; li.appendChild(c); }
      var a = $('.pl-card', li); if (a) a.setAttribute('aria-pressed', 'false');
    });
    var b = barEl(kind);
    b.hidden = false;
    requestAnimationFrame(function () { b.classList.add('is-visible'); });
    syncBar();
  }
  function exitSelect() {
    if (!sel) return;
    var kind = sel.kind;
    document.body.classList.remove('asg-selecting');
    if (sel.btn) { sel.btn.textContent = 'Select'; sel.btn.setAttribute('aria-pressed', 'false'); }
    items(kind).forEach(function (li) {
      li.classList.remove('asg-selectable', 'is-selected');
      var a = $('.pl-card', li); if (a) a.removeAttribute('aria-pressed');
    });
    sel = null;
    if (bar) { bar.classList.remove('is-visible'); bar.hidden = true; }
  }
  function toggleItem(li) {
    var kind = sel.kind, id = parseInt(li.getAttribute('data-' + kind + '-item'), 10);
    var i = sel.ids.indexOf(id);
    if (i >= 0) sel.ids.splice(i, 1); else sel.ids.push(id);
    var on = i < 0;
    li.classList.toggle('is-selected', on);
    var a = $('.pl-card', li); if (a) a.setAttribute('aria-pressed', on ? 'true' : 'false');
    syncBar();
  }
  function syncBar() {
    if (!sel || !bar) return;
    var n = sel.ids.length, noun = sel.kind === 'page' ? 'page' : 'email';
    $('[data-asg-count]', bar).textContent = n ? plural(n, noun) + ' selected' : 'Tap ' + noun + 's to select';
    $$('[data-asg-bulk]', bar).forEach(function (b) { b.disabled = n === 0; });
    var all = $('[data-asg-all]', bar);
    if (all) all.textContent = n && n === items(sel.kind).length ? 'Clear' : 'Select all';
  }
  function bulk(action) {
    if (!sel || !sel.ids.length) return;
    var ids = sel.ids.slice();
    var one = ids.length === 1 ? rowOf(sel.kind, ids[0]) : null;
    var label = one ? (one.getAttribute('data-title') || '') : '';
    if (action === 'move') move(sel.kind, ids, { label: label });
    else if (action === 'flow') flow(ids, { label: label });
    else if (action === 'audiences') audiences(ids, { label: label });
  }

  /* ------------------------------------------------------------------ */
  /* Wiring                                                              */
  /* ------------------------------------------------------------------ */
  function run(el) {
    var action = el.getAttribute('data-assign');
    var kind = el.getAttribute('data-kind') || 'email';
    var ids = idList(el.getAttribute('data-ids'));
    var label = el.getAttribute('data-label') || '';
    if (action === 'move') move(kind, ids, { label: label });
    else if (action === 'flow') flow(ids, { label: label });
    else if (action === 'audiences') audiences(ids, { label: label });
  }

  // select mode swallows taps (and swipes) on the rows before emails.js / pages.js see them
  document.addEventListener('click', function (e) {
    if (!sel) return;
    var li = e.target.closest('[data-' + sel.kind + '-item]');
    if (!li || !li.classList.contains('asg-selectable')) return;
    e.preventDefault();
    e.stopPropagation();
    toggleItem(li);
  }, true);
  document.addEventListener('pointerdown', function (e) {
    if (e.target.closest && e.target.closest('[data-asg-menu-toggle], [data-asg-menu]')) { e.stopPropagation(); return; }
    if (sel && e.target.closest && e.target.closest('.asg-selectable')) e.stopPropagation();
  }, true);

  document.addEventListener('click', function (e) {
    var t = e.target;
    var tog = t.closest('[data-asg-menu-toggle]');
    if (tog) { e.preventDefault(); e.stopPropagation(); toggleMenu(tog); return; }
    var item = t.closest('[data-assign]');
    if (item) { e.preventDefault(); closeMenus(); run(item); return; }
    if (!t.closest('[data-asg-menu]')) closeMenus();
    else if (t.closest('a[role="menuitem"], button[role="menuitem"]')) closeMenus();   // the detail sheets' review items act in emails.js / pages.js

    var s = t.closest('[data-asg-select]');
    if (s) { e.preventDefault(); select(s.getAttribute('data-asg-select')); return; }
    if (bar && bar.contains(t)) {
      var b = t.closest('[data-asg-bulk]');
      if (b && !b.disabled) { bulk(b.getAttribute('data-asg-bulk')); return; }
      if (t.closest('[data-asg-all]') && sel) {
        var all = items(sel.kind), full = sel.ids.length === all.length;
        all.forEach(function (li) { if (li.classList.contains('is-selected') === full) toggleItem(li); });
      }
      return;
    }
    if (sheetEl && sheetEl.contains(t) && t.closest('[data-asg-submit]')) {
      e.preventDefault();
      var btn = t.closest('[data-asg-submit]');
      if (!btn.disabled && typeof sheetEl._asgSubmit === 'function') sheetEl._asgSubmit();
    }
  });
  document.addEventListener('submit', function (e) {
    if (!sheetEl || !sheetEl.contains(e.target)) return;
    e.preventDefault();
    var btn = $('[data-asg-submit]', sheetEl);
    if (btn && !btn.disabled && typeof sheetEl._asgSubmit === 'function') sheetEl._asgSubmit();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var open = $$('[data-asg-menu]').filter(function (m) { return !m.hidden; })[0];
      if (open) { e.preventDefault(); e.stopPropagation(); var tg = $('[data-asg-menu-toggle]', open.parentNode); closeMenus(); if (tg) tg.focus(); return; }
      if (sel && !(App.sheet && App.sheet.current)) { exitSelect(); return; }
    }
    if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && e.target.closest && e.target.closest('[data-asg-menu]')) {
      var list = $$('[role="menuitem"]', e.target.closest('[data-asg-menu]'));
      var i = list.indexOf(document.activeElement);
      e.preventDefault();
      i = e.key === 'ArrowDown' ? (i + 1) % list.length : (i <= 0 ? list.length - 1 : i - 1);
      if (list[i]) list[i].focus();
    }
    if (e.key === 'Enter' && sheetEl && sheetEl.contains(e.target) && e.target.matches('input[type="text"], input[type="url"]')) {
      e.preventDefault();
      var btn = $('[data-asg-submit]', sheetEl);
      if (btn && !btn.disabled && typeof sheetEl._asgSubmit === 'function') sheetEl._asgSubmit();
    }
  }, true);
  // a row menu is viewport-fixed: close it once the page really scrolls away from where it opened
  window.addEventListener('scroll', function () {
    $$('.asg-row-more [data-asg-menu]').forEach(function (m) {
      if (!m.hidden && Math.abs((window.scrollY || 0) - (m._asgY || 0)) > 24) closeMenus();
    });
  }, { passive: true });
  window.addEventListener('resize', function () { closeMenus(); });

  App.assign = { move: move, flow: flow, audiences: audiences, create: create, select: select, exitSelect: exitSelect,
                 isSelecting: function () { return !!sel; }, selected: function () { return sel ? sel.ids.slice() : []; } };

  // "+ New → New email / New page": the sheet in place (the item's href stays the fallback)
  function hookNewMenu() {
    if (!App.newMenu || !App.newMenu.handle) return false;
    App.newMenu.handle('email', function (detail) { return create('email', detail); });
    App.newMenu.handle('page', function (detail) { return create('page', detail); });
    return true;
  }
  if (!hookNewMenu()) document.addEventListener('app:ready', hookNewMenu, { once: true });

})(window, document);
