/* =====================================================================
   Joust client portal — flows.js  (Flows: an email sequence as a timeline)
   Extends the global App from app.js; never edits it. Loads after emails.js
   (which owns the detail sheet: App.emails.open) and BEFORE app.js (deferred,
   emitted by flows.php's $footExtra) — anything that needs App.sheet / App.post
   runs on interaction, long after 'app:ready'.

   App.flows.setEditing(on)          admin: toggle edit mode (keeps ?edit=1 in the URL)
   App.flows.move(from, to)          reorder a step (optimistic, move_step, rollback)
   App.flows.remove(emailId)         remove a step (confirm, remove_step)
   App.flows.add(emailId, position)  add_step → inserts the returned card html
   App.flows.setTiming(li, text)     label only; App.flows.saveTiming(li, text) posts set_step_timing
   App.flows.renumber()              step numbers, up/down state, chip count, mini-map
   Every mutation posts to flow-status.php through App.post() (client slug appended).
   Events: 'flows:changed' {action, flowId}
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var cfg = window.FlowsConfig || {};
  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var ENDPOINT = cfg.endpoint || 'flow-status.php';
  var LS_COMPACT = 'flows:compact';

  function toast(msg, kind) { if (App.toast) App.toast(msg, { kind: kind }); }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function reduced() { return !!(App.reducedMotion && App.reducedMotion()); }

  var F = App.flows = {
    flowId: cfg.flowId || 0,
    editing: !!cfg.editing,
    admin: !!cfg.admin
  };

  function page()  { return $('[data-flow-page]'); }
  function list()  { return $('[data-flow-steps]'); }
  function steps() { var l = list(); return l ? $$('.fl-step', l) : []; }
  function stepEl(emailId) { return $('.fl-step[data-email-id="' + emailId + '"]'); }
  function sheetRoot() { return $('#uiSheet'); }
  function post(params) {
    params = params || {};
    if (params.flow_id === undefined) params.flow_id = F.flowId;
    return App.post(ENDPOINT, params);
  }

  /* ================================================================== */
  /* Numbering, mini-map, empty state                                     */
  /* ================================================================== */
  F.renumber = function () {
    var items = steps(), n = items.length;
    items.forEach(function (li, i) {
      var num = i + 1, label = 'Step ' + num + ' of ' + n;
      li.setAttribute('data-position', num);
      var badge = $('[data-flow-num]', li); if (badge) badge.textContent = num;
      var sr = $('[data-flow-num-sr]', li); if (sr) sr.textContent = label;
      var up = $('[data-flow-up]', li), down = $('[data-flow-down]', li), handle = $('[data-flow-handle]', li), ins = $('[data-flow-insert]', li);
      if (up)   { up.disabled = i === 0;       up.setAttribute('aria-label', 'Move step ' + num + ' up'); }
      if (down) { down.disabled = i === n - 1; down.setAttribute('aria-label', 'Move step ' + num + ' down'); }
      if (handle) handle.setAttribute('aria-label', 'Drag to reorder step ' + num);
      if (ins) ins.setAttribute('aria-label', 'Insert an email before step ' + num);
      var t = $('[data-flow-timing]', li);
      if (t && t.tagName === 'BUTTON') t.setAttribute('aria-label', 'Timing before step ' + num + ': ' + (($('[data-flow-timing-label]', t) || t).textContent.trim()) + '. Edit');
    });
    var count = $('[data-flow-count="' + F.flowId + '"]'); if (count) count.textContent = n;
    var sub = $('.ui-nav-eyebrow'); if (sub && /Email flow/.test(sub.textContent)) sub.textContent = 'Email flow · ' + n + ' step' + (n === 1 ? '' : 's');
    var empty = $('[data-flow-empty]'); if (empty) empty.hidden = n > 0;
    var end = $('[data-flow-end]'); if (end) end.hidden = n === 0;
    F.renderMap();
  };

  F.renderMap = function () {
    var map = $('[data-flow-map]'); if (!map) return;
    var html = '';
    var active = /^#flow-step-\d+$/.test(window.location.hash || '') ? window.location.hash.replace('#flow-step-', '') : '';
    steps().forEach(function (li, i) {
      var id = li.getAttribute('data-email-id'), code = li.getAttribute('data-code') || '—', key = li.getAttribute('data-key') || 'draft';
      var title = 'Step ' + (i + 1) + ' · ' + (li.getAttribute('data-title') || '');
      if (i > 0) html += '<span class="fl-map-arrow" aria-hidden="true">→</span>';
      html += '<a class="fl-map-chip el-code-tile--' + escapeHtml(key) + (id === active ? ' is-active' : '') + '" href="#flow-step-' + escapeHtml(id) + '" data-flow-map-chip="' + escapeHtml(id) + '" title="' + escapeHtml(title) + '">' + escapeHtml(code) + '</a>';
    });
    map.innerHTML = html;
  };

  /* ================================================================== */
  /* Edit mode + compact                                                  */
  /* ================================================================== */
  F.setEditing = function (on) {
    if (!F.admin) return;
    F.editing = !!on;
    var root = page(); if (root) root.classList.toggle('is-editing', F.editing);
    document.body.classList.toggle('is-editing', F.editing);
    var btn = $('[data-flow-edit-toggle]');
    if (btn) {
      btn.textContent = F.editing ? 'Done' : 'Edit';
      btn.setAttribute('aria-pressed', F.editing ? 'true' : 'false');
      btn.classList.toggle('ui-btn--filled', F.editing);
      btn.classList.toggle('ui-btn--tinted', !F.editing);
    }
    try {
      var u = new URL(window.location.href);
      if (F.editing) u.searchParams.set('edit', '1'); else u.searchParams.delete('edit');
      history.replaceState(history.state, '', u.pathname + u.search + u.hash);
    } catch (e) {}
    closeMenu();
  };

  function setCompact(on, persist) {
    var root = page(); if (root) root.classList.toggle('is-compact', !!on);
    var btn = $('[data-flow-compact]'); if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (persist) { try { localStorage.setItem(LS_COMPACT, on ? '1' : '0'); } catch (e) {} }
  }

  /* ================================================================== */
  /* Reorder: up/down buttons + pointer drag (vertical fork of studio.js) */
  /* ================================================================== */
  function place(li, index) {
    var l = list(); if (!l) return;
    var items = steps().filter(function (el) { return el !== li; });
    var ref = items[index] || null;
    l.insertBefore(li, ref);
  }

  F.move = function (from, to, opts) {
    opts = opts || {};
    var items = steps();
    var li = items[from];
    if (!li || to < 0 || to >= items.length || from === to) return Promise.resolve(null);
    place(li, to);
    F.renumber();
    if (opts.focus) { var b = $(opts.focus, li); if (b && !b.disabled) b.focus(); else { var alt = $('[data-flow-handle]', li); if (alt) alt.focus(); } }
    li.classList.add('is-busy');
    var emailId = li.getAttribute('data-email-id');
    return post({ action: 'move_step', email_id: emailId, position: to }).then(function (res) {   // positions are 0-based
      li.classList.remove('is-busy');
      if (!res.ok) {
        place(li, from);
        F.renumber();
        toast(res.error || 'Could not move this step', 'error');
      } else {
        document.dispatchEvent(new CustomEvent('flows:changed', { detail: { action: 'move', flowId: F.flowId } }));
      }
      return res;
    });
  };

  function bindDrag(l) {
    var drag = null;
    l.addEventListener('pointerdown', function (e) {
      var handle = e.target.closest('[data-flow-handle]');
      if (!handle || !F.editing) return;
      var li = handle.closest('.fl-step'); if (!li) return;
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      e.preventDefault();
      var items = steps();
      var sy = window.scrollY || 0;
      drag = {
        item: li, from: items.indexOf(li), to: items.indexOf(li), id: e.pointerId, moved: false,
        startY: e.clientY + sy, scrollY0: sy,
        centres: items.map(function (el) { var r = el.getBoundingClientRect(); return r.top + sy + r.height / 2; })
      };
      li.classList.add('is-dragging');
      try { handle.setPointerCapture(e.pointerId); } catch (err) {}
    });
    l.addEventListener('pointermove', function (e) {
      if (!drag || e.pointerId !== drag.id) return;
      var sy = window.scrollY || 0;
      var y = e.clientY + sy;
      var dy = y - drag.startY;
      if (!drag.moved && Math.abs(dy) < 4) return;
      drag.moved = true;
      e.preventDefault();
      var card = $('.fl-card', drag.item);
      if (card) card.style.transform = 'translateY(' + dy + 'px) scale(1.02)';
      // Target slot = the card whose centre the pointer has crossed.
      var to = drag.from;
      for (var i = 0; i < drag.centres.length; i++) {
        if (i < drag.from && y < drag.centres[i]) { to = i; break; }
        if (i > drag.from && y > drag.centres[i]) { to = i; }
      }
      drag.to = to;
      steps().forEach(function (el, i) {
        el.classList.toggle('is-shift-down', i >= to && i < drag.from);
        el.classList.toggle('is-shift-up',   i <= to && i > drag.from);
      });
      // Edge auto-scroll so long flows can be dragged across the fold.
      var edge = 72, vh = window.innerHeight || 800;
      if (e.clientY < edge) window.scrollBy(0, -Math.ceil((edge - e.clientY) / 4));
      else if (e.clientY > vh - edge) window.scrollBy(0, Math.ceil((e.clientY - (vh - edge)) / 4));
    });
    function end(e) {
      if (!drag || e.pointerId !== drag.id) return;
      var card = $('.fl-card', drag.item);
      if (card) card.style.transform = '';
      drag.item.classList.remove('is-dragging');
      steps().forEach(function (el) { el.classList.remove('is-shift-up', 'is-shift-down'); });
      var from = drag.from, to = drag.to, moved = drag.moved;
      drag = null;
      if (moved && e.type !== 'pointercancel' && from !== to) F.move(from, to);
    }
    l.addEventListener('pointerup', end);
    l.addEventListener('pointercancel', end);
  }

  /* ================================================================== */
  /* Remove / add                                                         */
  /* ================================================================== */
  F.remove = function (emailId) {
    var li = stepEl(emailId); if (!li) return Promise.resolve(null);
    var code = li.getAttribute('data-code') || 'this email';
    if (!window.confirm('Remove ' + code + ' from this flow? The email itself is kept.')) return Promise.resolve(null);
    var rec = { parent: li.parentNode, next: li.nextElementSibling };
    var items = steps(), idx = items.indexOf(li);
    var focusTo = items[idx + 1] || items[idx - 1] || null;
    // The leave animation removes the node when it ends; a rollback that arrives earlier must wait for it.
    var gone = false, afterGone = null;
    if (App.remove) App.remove(li, function () { gone = true; F.renumber(); if (afterGone) afterGone(); });
    else { li.remove(); gone = true; F.renumber(); }
    if (focusTo) { var b = $('[data-flow-remove]', focusTo); if (b) b.focus(); }
    return post({ action: 'remove_step', email_id: emailId }).then(function (res) {
      if (!res.ok) {
        var restore = function () {
          li.classList.remove('ui-leave');
          var next = rec.next && rec.next.parentNode === rec.parent ? rec.next : null;
          rec.parent.insertBefore(li, next);
          F.renumber();
        };
        if (gone) restore(); else afterGone = restore;
        toast(res.error || 'Could not remove this step', 'error');
      } else {
        toast(code + ' removed from the flow', 'success');
        document.dispatchEvent(new CustomEvent('flows:changed', { detail: { action: 'remove', flowId: F.flowId } }));
      }
      return res;
    });
  };

  /* position: 0-based insert index; null / undefined = append. */
  F.add = function (emailId, position) {
    var params = { action: 'add_step', email_id: emailId };
    var insertAt = (position === null || position === undefined || position === '') ? null : Math.max(0, parseInt(position, 10) || 0);
    if (insertAt !== null) params.position = insertAt;
    return post(params).then(function (res) {
      if (!res.ok) { toast(res.error || 'Could not add this email', 'error'); return res; }
      var html = res.data && res.data.html;
      if (!html) { window.location.reload(); return res; }
      var box = document.createElement('div');
      box.innerHTML = html;
      var li = $('.fl-step', box);
      if (!li) { window.location.reload(); return res; }
      var l = list(); if (!l) { window.location.reload(); return res; }
      var items = steps();
      var ref = insertAt !== null && items[insertAt] ? items[insertAt] : null;
      l.insertBefore(li, ref);
      li.classList.add('ui-enter');
      F.renumber();
      toast((li.getAttribute('data-code') || 'Email') + ' added', 'success');
      try { li.scrollIntoView({ block: 'center', behavior: reduced() ? 'auto' : 'smooth' }); } catch (e) {}
      document.dispatchEvent(new CustomEvent('flows:changed', { detail: { action: 'add', flowId: F.flowId } }));
      return res;
    });
  };

  /* ================================================================== */
  /* Timing override on the connector                                     */
  /* ================================================================== */
  F.setTiming = function (li, text) {
    text = (text || '').trim();
    li.setAttribute('data-timing', text);
    var el = $('[data-flow-timing]', li); if (!el) return;
    var trig = li.getAttribute('data-trigger') || '';
    var label = text || trig || 'Timing not set';
    var source = text ? 'override' : (trig ? 'trigger' : 'none');
    el.className = el.className.replace(/\bfl-timing--\w+\b/g, '').replace(/\s+/g, ' ').trim() + ' fl-timing--' + source;
    el.setAttribute('data-timing-source', source);
    var span = $('[data-flow-timing-label]', el);
    if (span) span.textContent = label; else el.textContent = label;
    el.hidden = false;
  };

  F.saveTiming = function (li, text) {
    text = (text || '').trim();
    var before = li.getAttribute('data-timing') || '';
    if (text === before) return Promise.resolve(null);
    F.setTiming(li, text);
    return post({ action: 'set_step_timing', email_id: li.getAttribute('data-email-id'), timing_text: text }).then(function (res) {
      if (!res.ok) { F.setTiming(li, before); toast(res.error || 'Could not save the timing', 'error'); }
      else {
        toast(text ? 'Timing saved' : 'Timing reset to the trigger', 'success');
        document.dispatchEvent(new CustomEvent('flows:changed', { detail: { action: 'timing', flowId: F.flowId } }));
      }
      return res;
    });
  };

  function editTiming(btn) {
    var li = btn.closest('.fl-step'); if (!li || $('.fl-timing-input', li)) return;
    var cur = li.getAttribute('data-timing') || '';
    var input = document.createElement('input');
    input.type = 'text'; input.className = 'ui-input fl-timing-input'; input.value = cur; input.maxLength = 120;
    input.placeholder = li.getAttribute('data-trigger') || 'e.g. 3 days later';
    input.setAttribute('aria-label', 'Timing before this step (leave empty to use the email trigger)');
    input.setAttribute('enterkeyhint', 'done');
    btn.hidden = true;
    btn.parentNode.insertBefore(input, btn.nextSibling);
    setTimeout(function () { input.focus(); input.select(); }, 10);
    var done = false;
    function finish(save) {
      if (done) return; done = true;
      var v = input.value.trim();
      input.remove();
      btn.hidden = false;
      if (save) F.saveTiming(li, v);
      btn.focus();
    }
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
    });
    input.addEventListener('blur', function () { finish(true); });
  }

  /* ================================================================== */
  /* Sheets: picker, flow form, reorder flows                             */
  /* ================================================================== */
  /* stepNum: 1-based step number to insert BEFORE (0 = append); the endpoint gets the 0-based index. */
  function openPicker(stepNum) {
    var tpl = $('template[data-flow-picker]'); if (!tpl) return;
    var root = sheetRoot(); if (!root || !App.sheet) return;
    App.sheet.open(root, { title: stepNum ? 'Insert before step ' + stepNum : 'Add email', html: tpl.innerHTML, footer: '' });
    var body = $('[data-sheet-body]', root);
    var picker = $('[data-flow-picker-root]', body); if (!picker) return;
    picker.setAttribute('data-flow-pick-position', stepNum ? String(stepNum - 1) : '');
    var present = {};
    steps().forEach(function (li) { present[li.getAttribute('data-email-id')] = true; });
    $$('[data-flow-pick-item]', picker).forEach(function (it) { it.hidden = !!present[it.getAttribute('data-flow-pick-item')]; });
    filterPicker(picker, '');
  }

  function filterPicker(picker, q) {
    q = (q || '').trim().toLowerCase();
    var visible = 0, candidates = 0;
    $$('[data-flow-pick-group]', picker).forEach(function (g) {
      var n = 0;
      $$('[data-flow-pick-item]', g).forEach(function (it) {
        var present = steps().some(function (li) { return li.getAttribute('data-email-id') === it.getAttribute('data-flow-pick-item'); });
        if (present) { it.hidden = true; return; }
        candidates++;
        var hit = !q || (it.getAttribute('data-search') || '').indexOf(q) !== -1;
        it.hidden = !hit;
        if (hit) n++;
      });
      g.hidden = n === 0;
      visible += n;
    });
    var empty = $('[data-flow-pick-empty]', picker), nomatch = $('[data-flow-pick-nomatch]', picker);
    if (empty && $$('[data-flow-pick-group]', picker).length) empty.hidden = candidates > 0;
    if (nomatch) nomatch.hidden = !(candidates > 0 && visible === 0);
  }

  function openFlowForm(mode) {
    var tpl = $('template[data-flow-form-template]'); if (!tpl) return;
    var root = sheetRoot(); if (!root || !App.sheet) return;
    App.sheet.open(root, { title: mode === 'rename' ? 'Rename flow' : 'New flow', html: tpl.innerHTML, footer: '' });
    var form = $('[data-flow-form]', root); if (!form) return;
    form.setAttribute('data-flow-form', mode === 'rename' ? 'rename_flow' : 'create_flow');
    var submit = $('[data-flow-form-submit]', form); if (submit) submit.textContent = mode === 'rename' ? 'Save' : 'Create flow';
    if (mode === 'rename') {
      var name = $('[name="name"]', form), desc = $('[name="description"]', form);
      if (name) name.value = cfg.flowName || '';
      if (desc) desc.value = cfg.flowDesc || '';
    }
  }

  function submitFlowForm(form) {
    var action = form.getAttribute('data-flow-form');
    var name = ($('[name="name"]', form) || {}).value || '';
    var desc = ($('[name="description"]', form) || {}).value || '';
    name = name.trim(); desc = desc.trim();
    if (name.length < 1) { var n = $('[name="name"]', form); if (n) n.focus(); return; }
    var btn = $('[data-flow-form-submit]', form); if (btn) btn.disabled = true;
    var params = { action: action, name: name, description: desc };
    if (action === 'rename_flow') params.flow_id = F.flowId; else params.flow_id = undefined;
    App.post(ENDPOINT, params).then(function (res) {
      if (btn) btn.disabled = false;
      if (!res.ok) { toast(res.error || 'Could not save', 'error'); return; }
      if (action === 'create_flow') {
        var flow = res.data && res.data.flow;
        var url = flow && flow.url ? flow.url : (cfg.flowsUrl + (cfg.flowsUrl.indexOf('?') === -1 ? '?' : '&') + 'flow=' + encodeURIComponent(flow && flow.slug ? flow.slug : ''));
        window.location.href = url;
        return;
      }
      cfg.flowName = name; cfg.flowDesc = desc;
      var t = $('.ui-nav-title'); if (t) t.textContent = name;
      var chip = $('[data-flow-chip="' + (cfg.flowSlug || '') + '"] [data-flow-chip-name]'); if (chip) chip.textContent = name;
      var d = $('[data-flow-desc]'); if (d) { d.textContent = desc; d.hidden = desc === ''; }
      document.title = document.title.replace(/^[^—]*/, name + ' ');
      if (App.sheet) App.sheet.close();
      toast('Flow renamed', 'success');
      document.dispatchEvent(new CustomEvent('flows:changed', { detail: { action: 'rename', flowId: F.flowId } }));
    });
  }

  function deleteFlow() {
    var name = cfg.flowName || 'this flow';
    if (!window.confirm('Delete the flow "' + name + '"? The emails themselves are kept.')) return;
    post({ action: 'delete_flow' }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Could not delete', 'error'); return; }
      window.location.href = cfg.flowsUrl || 'flows.php';
    });
  }

  function openReorder() {
    var root = sheetRoot(); if (!root || !App.sheet) return;
    var flows = cfg.flows || [];
    var html = '<ol class="fl-order" data-flow-order role="list">';
    flows.forEach(function (f, i) {
      html += '<li class="fl-order-item" data-flow-order-id="' + escapeHtml(f.id) + '">'
            + '<span class="fl-order-name">' + escapeHtml(f.name) + '</span>'
            + '<button type="button" class="fl-tool" data-order-up aria-label="Move ' + escapeHtml(f.name) + ' up"' + (i === 0 ? ' disabled' : '') + '><svg class="ui-icon fl-icon-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 9 7 7 7-7"/></svg></button>'
            + '<button type="button" class="fl-tool" data-order-down aria-label="Move ' + escapeHtml(f.name) + ' down"' + (i === flows.length - 1 ? ' disabled' : '') + '><svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 9 7 7 7-7"/></svg></button>'
            + '</li>';
    });
    html += '</ol>';
    var footer = '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-sheet-close>Cancel</button><button type="button" class="ui-btn ui-btn--filled ui-btn--primary" data-flow-order-save>Save order</button></div>';
    App.sheet.open(root, { title: 'Reorder flows', html: html, footer: footer });
  }
  function orderButtons(ol) {
    var items = $$('[data-flow-order-id]', ol);
    items.forEach(function (li, i) {
      var up = $('[data-order-up]', li), down = $('[data-order-down]', li);
      if (up) up.disabled = i === 0;
      if (down) down.disabled = i === items.length - 1;
    });
  }
  function saveOrder() {
    var ol = $('[data-flow-order]', sheetRoot()); if (!ol) return;
    var params = { action: 'reorder_flows', flow_id: undefined };
    $$('[data-flow-order-id]', ol).forEach(function (li, i) { params['flow_ids[' + i + ']'] = li.getAttribute('data-flow-order-id'); });
    var btn = $('[data-flow-order-save]', sheetRoot()); if (btn) btn.disabled = true;
    App.post(ENDPOINT, params).then(function (res) {
      if (btn) btn.disabled = false;
      if (!res.ok) { toast(res.error || 'Could not save the order', 'error'); return; }
      window.location.reload();
    });
  }

  function seedSeries(btn) {
    if (btn) btn.disabled = true;
    App.post(ENDPOINT, { action: 'seed_series' }).then(function (res) {
      if (!res.ok) { if (btn) btn.disabled = false; toast(res.error || 'Could not suggest flows', 'error'); return; }
      var n = res.data && res.data.created;
      toast(n ? n + ' flow' + (n === 1 ? '' : 's') + ' created' : 'Nothing to suggest — every series already has a flow', n ? 'success' : undefined);
      setTimeout(function () { window.location.reload(); }, 400);
    });
  }

  /* ================================================================== */
  /* Flow menu (…)                                                        */
  /* ================================================================== */
  function menu() { return $('[data-flow-menu-list]'); }
  function closeMenu() {
    var m = menu(); if (!m || m.hidden) return;
    m.hidden = true;
    var b = $('[data-flow-menu]'); if (b) b.setAttribute('aria-expanded', 'false');
  }
  function toggleMenu() {
    var m = menu(); if (!m) return;
    var open = m.hidden;
    m.hidden = !open;
    var b = $('[data-flow-menu]'); if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var first = $('button:not([disabled])', m); if (first) first.focus(); }
  }

  /* ================================================================== */
  /* Wiring                                                              */
  /* ================================================================== */
  function init() {
    var root = page(); if (!root) return;
    var l = list();
    if (l && F.admin) bindDrag(l);

    // Compact (remembered per browser)
    var compact = false;
    try { compact = localStorage.getItem(LS_COMPACT) === '1'; } catch (e) {}
    if (compact) setCompact(true, false);

    // Mini-map: highlight the chip whose card was reached via the hash / a click
    function highlightMap(id) {
      $$('[data-flow-map-chip]').forEach(function (c) { c.classList.toggle('is-active', c.getAttribute('data-flow-map-chip') === String(id)); });
    }

    document.addEventListener('click', function (e) {
      var t = e.target;

      var mapChip = t.closest('[data-flow-map-chip]');
      if (mapChip) {
        var target = stepEl(mapChip.getAttribute('data-flow-map-chip'));
        if (target) {
          e.preventDefault();
          try { history.replaceState(history.state, '', '#flow-step-' + mapChip.getAttribute('data-flow-map-chip')); } catch (err) {}
          highlightMap(mapChip.getAttribute('data-flow-map-chip'));
          try { target.scrollIntoView({ block: 'center', behavior: reduced() ? 'auto' : 'smooth' }); } catch (err) { target.scrollIntoView(); }
          var card = $('.fl-card', target); if (card) { card.setAttribute('tabindex', '-1'); card.focus({ preventScroll: true }); }
        }
        return;
      }
      if (t.closest('[data-flow-compact]')) {
        var on = !(root.classList.contains('is-compact'));
        setCompact(on, true);
        return;
      }
      if (t.closest('[data-flow-edit-toggle]')) { F.setEditing(!F.editing); return; }

      if (t.closest('[data-flow-menu]')) { e.preventDefault(); toggleMenu(); return; }
      if (!t.closest('[data-flow-menu-list]')) closeMenu();
      if (t.closest('[data-flow-rename]')) { closeMenu(); openFlowForm('rename'); return; }
      if (t.closest('[data-flow-delete]')) { closeMenu(); deleteFlow(); return; }
      if (t.closest('[data-flow-reorder-flows]')) { closeMenu(); openReorder(); return; }
      if (t.closest('[data-flow-new]')) { openFlowForm('create'); return; }
      if (t.closest('[data-flow-seed]')) { seedSeries(t.closest('[data-flow-seed]')); return; }

      if (!F.admin) return;
      var li = t.closest('.fl-step');
      if (t.closest('[data-flow-add]')) { if (!F.editing) F.setEditing(true); openPicker(0); return; }
      if (li && t.closest('[data-flow-insert]')) { openPicker(parseInt(li.getAttribute('data-position'), 10) || 0); return; }
      if (li && t.closest('[data-flow-up]'))     { var i = steps().indexOf(li); F.move(i, i - 1, { focus: '[data-flow-up]' }); return; }
      if (li && t.closest('[data-flow-down]'))   { var j = steps().indexOf(li); F.move(j, j + 1, { focus: '[data-flow-down]' }); return; }
      if (li && t.closest('[data-flow-remove]')) { F.remove(li.getAttribute('data-email-id')); return; }
      var timing = t.closest('button[data-flow-timing]');
      if (timing && F.editing) { editTiming(timing); return; }
      if (timing && !F.editing) { F.setEditing(true); editTiming(timing); return; }

      // Inside the sheet: picker rows, reorder buttons, save order
      var sheet = sheetRoot();
      if (sheet && sheet.contains(t)) {
        var pick = t.closest('[data-flow-pick]');
        if (pick) {
          var picker = t.closest('[data-flow-picker-root]');
          var posAttr = picker ? picker.getAttribute('data-flow-pick-position') : '';
          var pos = posAttr === '' || posAttr === null ? null : parseInt(posAttr, 10);
          pick.disabled = true;
          F.add(pick.getAttribute('data-flow-pick'), pos).then(function (res) { if (res && res.ok && App.sheet) App.sheet.close(); else pick.disabled = false; });
          return;
        }
        var ordLi = t.closest('[data-flow-order-id]');
        if (ordLi && t.closest('[data-order-up]'))   { var ol = ordLi.parentNode; if (ordLi.previousElementSibling) ol.insertBefore(ordLi, ordLi.previousElementSibling); orderButtons(ol); return; }
        if (ordLi && t.closest('[data-order-down]')) { var ol2 = ordLi.parentNode; if (ordLi.nextElementSibling) ol2.insertBefore(ordLi.nextElementSibling, ordLi); orderButtons(ol2); return; }
        if (t.closest('[data-flow-order-save]')) { saveOrder(); return; }
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { var m = menu(); if (m && !m.hidden) { closeMenu(); var b = $('[data-flow-menu]'); if (b) b.focus(); } }
      // Keyboard reorder while a card's handle has focus: Alt+ArrowUp / Alt+ArrowDown
      if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && e.altKey && F.editing) {
        var li = e.target.closest && e.target.closest('.fl-step'); if (!li) return;
        e.preventDefault();
        var i = steps().indexOf(li);
        F.move(i, e.key === 'ArrowUp' ? i - 1 : i + 1, { focus: '[data-flow-handle]' });
      }
    });

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form.hasAttribute || !form.hasAttribute('data-flow-form')) return;
      e.preventDefault();
      submitFlowForm(form);
    });

    document.addEventListener('input', function (e) {
      if (e.target.matches && e.target.matches('[data-flow-pick-search]')) {
        var picker = e.target.closest('[data-flow-picker-root]');
        if (picker) filterPicker(picker, e.target.value);
      }
    });

    // Keep the "open" highlight in sync with the detail sheet (emails.js only clears .pl-item)
    document.addEventListener('emails:open', function (e) {
      steps().forEach(function (li) { li.classList.toggle('is-open', li.getAttribute('data-email-id') === String(e.detail.id)); });
    });
    document.addEventListener('emails:close', function () {
      steps().forEach(function (li) { li.classList.remove('is-open'); });
    });
    // Decisions in the sheet re-tint the code tile (emails.js does it) — refresh the mini-map + past state too
    document.addEventListener('emails:decided', function (e) {
      var li = stepEl(e.detail.id);
      if (li) {
        var key = li.getAttribute('data-key') || 'draft';
        li.className = li.className.replace(/\bfl-step--(draft|pending|approved|denied|live)\b/g, '').replace(/\s+/g, ' ').trim() + ' fl-step--' + key;
        li.classList.toggle('fl-step--past', key === 'live' && li.getAttribute('data-past') === '1');
      }
      F.renderMap();
    });

    F.renumber();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})(window, document);
