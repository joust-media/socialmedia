/* =====================================================================
   Joust client portal — emails.js  (Emails module; the posts.js twin)
   Extends the global App from app.js; never edits it. Loads BEFORE app.js
   (deferred, emitted by emails.php's $footExtra) so deep links wait for 'app:ready'.

   App.swipe                       same API as posts.js (guarded copy — posts.js is not loaded here)
   App.emails.open(id, {deny})     open the email detail sheet (inline template or partial fetch)
   App.emails.close()
   App.emails.decide(id, status, note, {toast})  optimistic approve / needs changes (+ required note) / route (admin)
   App.emails.resubmit(id)         admin work queue: denied → pending, the row leaves the queue
   App.emails.submit(id)           admin: draft → pending ("Send for review")
   App.emails.toggleLive(id, to)   admin: live 0↔1 (to=1 needs status approved — server answers 409)
   App.emails.remove(id)           admin: delete (confirm)
   App.emails.comment(id, text)
   Events: 'emails:decided' {id, status, ok}, 'emails:open' {id}, 'emails:close' {id}
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var cfg = window.EmailsConfig || {};
  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  var ENDPOINT = cfg.endpoint || 'email-status.php';
  var DESKTOP  = window.matchMedia ? window.matchMedia('(min-width: 1024px)') : { matches: false };
  var LABELS   = { draft: 'Draft', pending: 'To Review', approved: 'Approved', denied: 'Needs changes', live: 'Live' };
  var PILL     = { draft: 'neutral', pending: 'pending', approved: 'approved', denied: 'denied', live: 'scheduled' };

  function toast(msg, kind) { if (App.toast) App.toast(msg, { kind: kind }); }
  function keyOf(status, live) { return live ? 'live' : status; }
  function fmtDay(d) { return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  /* ================================================================== */
  /* App.swipe — transform-only, velocity-aware, rubber-banded            */
  /* (identical to posts.js; defined only when no other script did)      */
  /* ================================================================== */
  App.swipe = App.swipe || {
    attach: function (root, opts) {
      root = typeof root === 'string' ? $(root) : root;
      if (!root || root.__swipe) return;
      opts = opts || {};
      var cardSel = opts.card || '.pl-card';
      var state = null;
      var suppressClickUntil = 0;

      function itemOf(target) { var it = target.closest('[data-swipe]'); return it && root.contains(it) ? it : null; }
      function cardOf(item) { return $(cardSel, item) || item; }
      function setX(item, x, settle) {
        var card = cardOf(item);
        item.classList.toggle('is-settling', !!settle);
        item.classList.toggle('is-swiping', !settle);
        card.style.transform = x ? 'translate3d(' + x + 'px,0,0)' : '';
      }
      function threshold(item) {
        var w = item.offsetWidth || 320;
        var t = opts.threshold == null ? 0.35 : opts.threshold;
        return Math.max(80, t < 1 ? w * t : t);
      }
      function rubber(dx, limit) {
        var a = Math.abs(dx);
        if (a <= limit) return dx;
        return Math.sign(dx) * (limit + (a - limit) * 0.22);
      }

      root.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse' || e.button !== 0) return;    // touch / pen only
        var item = itemOf(e.target);
        if (!item || item.classList.contains('ui-leave') || item.classList.contains('is-busy')) return;
        state = { item: item, id: e.pointerId, x0: e.clientX, y0: e.clientY, dx: 0, lastX: e.clientX, lastT: e.timeStamp, v: 0, locked: null, dir: null };
        item.__swipeCardSel = cardSel;
        item.classList.remove('is-settling');
      }, { passive: true });

      root.addEventListener('pointermove', function (e) {
        if (!state || e.pointerId !== state.id) return;
        var dx = e.clientX - state.x0, dy = e.clientY - state.y0;
        if (state.locked === null) {
          if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
          state.locked = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
          if (state.locked === 'x') { try { state.item.setPointerCapture(e.pointerId); } catch (err) {} }
        }
        if (state.locked !== 'x') return;
        e.preventDefault();
        var item = state.item;
        var dir  = dx > 0 ? 'right' : 'left';
        var allowed = !opts.canSwipe || opts.canSwipe(item, dir);
        var dt = Math.max(1, e.timeStamp - state.lastT);
        state.v = (e.clientX - state.lastX) / dt;
        state.lastX = e.clientX; state.lastT = e.timeStamp;
        state.dir = dir; state.dx = dx;
        item.setAttribute('data-swipe-dir', dir);
        item.classList.toggle('is-blocked', !allowed);
        var x = allowed ? rubber(dx, item.offsetWidth * 0.9) : rubber(dx, 0) * 0.6;
        item.classList.toggle('is-past', allowed && Math.abs(dx) >= threshold(item));
        setX(item, x, false);
      });

      function finish(e, cancelled) {
        if (!state || (e && e.pointerId !== state.id)) return;
        var s = state; state = null;
        var item = s.item;
        try { item.releasePointerCapture(s.id); } catch (err) {}
        if (s.locked !== 'x') { item.classList.remove('is-swiping'); return; }
        suppressClickUntil = Date.now() + 400;
        var dir = s.dir;
        var allowed = !!dir && (!opts.canSwipe || opts.canSwipe(item, dir));
        var flung = Math.abs(s.v) > 0.55 && Math.sign(s.v) === Math.sign(s.dx) && Math.abs(s.dx) > 24;
        var commit = !cancelled && allowed && (Math.abs(s.dx) >= threshold(item) || flung);
        item.classList.remove('is-past', 'is-blocked');
        if (!commit) { App.swipe.reset(item); return; }
        var out = opts.commitOut === undefined ? 'right' : opts.commitOut;
        if (out === dir) {
          setX(item, (dir === 'right' ? 1 : -1) * item.offsetWidth, true);
        } else {
          App.swipe.reset(item);
        }
        if (opts.onCommit) opts.onCommit(item, dir);
      }
      root.addEventListener('pointerup', function (e) { finish(e, false); });
      root.addEventListener('pointercancel', function (e) { finish(e, true); });
      root.addEventListener('lostpointercapture', function (e) { if (state && e.pointerId === state.id) finish(e, true); });
      root.addEventListener('click', function (e) {
        if (Date.now() < suppressClickUntil) { e.preventDefault(); e.stopPropagation(); }
      }, true);
      root.__swipe = { opts: opts };
    },

    reset: function (item) {
      if (!item) return;
      var card = $(item.__swipeCardSel || '.pl-card', item) || item;
      item.classList.remove('is-swiping', 'is-past', 'is-blocked');
      item.classList.add('is-settling');
      card.style.transform = '';
      var done = function () { item.classList.remove('is-settling'); item.removeAttribute('data-swipe-dir'); card.removeEventListener('transitionend', done); };
      card.addEventListener('transitionend', done);
      setTimeout(done, App.reducedMotion && App.reducedMotion() ? 200 : 420);
    }
  };

  /* ================================================================== */
  /* App.emails — detail sheet + actions                                  */
  /* ================================================================== */
  var E = App.emails = {
    current: null,        // { id, item, root }
    _pushed: false,
    counts: cfg.counts || {},
    segment: cfg.segment || 'pending'
  };

  function sheetRoot() { return $('#uiSheet'); }
  function itemEl(id) { return $('[data-email-item="' + id + '"]'); }
  function ed(root) { return $('.ed[data-email-detail]', root || sheetRoot()); }

  /* ---- counts ------------------------------------------------------- */
  function bumpCount(seg, delta) {
    if (!seg) return;
    E.counts[seg] = Math.max(0, (E.counts[seg] || 0) + delta);
    if (seg !== 'all') E.counts.all = Math.max(0, (E.counts.all || 0) + delta);
    var n = E.counts[seg];
    var item = $('.ui-segmented-item[data-segment="' + seg + '"] .ui-segmented-count');
    if (item) item.textContent = n;
    if (seg === E.segment) { var hdr = $('[data-segment-count]'); if (hdr) hdr.textContent = n; }
    if (E.segment === 'all') { var h2 = $('[data-segment-count]'); if (h2) h2.textContent = E.counts.all; }
    if (seg === 'pending') {
      var badge = $('.ui-tab--emails .ui-badge');
      if (badge) { badge.textContent = n > 99 ? '99+' : n; badge.hidden = n === 0; }
    }
  }
  function maybeEmpty() {
    var list = $('[data-emails-items]');
    if (!list || list.children.length) return;
    var group = $('[data-emails-list]'); if (!group) return;
    group.hidden = true;
    var empty = $('[data-emails-empty]');
    if (!empty) {
      empty = document.createElement('div');
      empty.className = 'ui-empty posts-empty ui-enter';
      empty.setAttribute('data-emails-empty', '');
      empty.textContent = E.segment === 'pending' ? 'All caught up — nothing left to review.'
                        : (E.segment === 'denied' ? 'Nothing needs changes — the queue is clear.' : 'Nothing here.');
      group.parentNode.insertBefore(empty, group);
    }
    empty.hidden = false;
  }

  /* ---- detail loading ----------------------------------------------- */
  function detailHtml(id) {
    var tpl = $('template[data-email-template="' + id + '"]');
    if (tpl) return Promise.resolve(tpl.innerHTML);
    if (!cfg.partialUrl) return Promise.reject(new Error('No detail available'));
    return fetch(cfg.partialUrl.replace('__ID__', encodeURIComponent(id)), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) { if (!res.ok) throw new Error('Could not load this email'); return res.text(); });
  }

  function splitDetail(html) {
    var box = document.createElement('div');
    box.innerHTML = html;
    var art = $('.ed[data-email-detail]', box);
    if (!art) return { body: html, footer: '' };
    var body = $('[data-pd-body]', art), footer = $('[data-pd-footer]', art);
    var shell = art.cloneNode(false);
    shell.innerHTML = body ? body.outerHTML : '';
    return { body: shell.outerHTML, footer: footer ? footer.outerHTML : '' };
  }

  E.open = function (id, opts) {
    opts = opts || {};
    id = String(id);
    var item = itemEl(id);
    var title = item ? (item.getAttribute('data-title') || 'Email') : 'Email';
    return detailHtml(id).then(function (html) {
      var parts = splitDetail(html);
      var root = sheetRoot();
      if (!root) return null;
      var wasOpen = App.sheet.current === root;
      root.classList.add('is-detail');
      if (wasOpen) {
        var t = $('[data-sheet-title]', root); if (t) t.textContent = title;
        var b = $('[data-sheet-body]', root);  if (b) { b.innerHTML = parts.body; b.scrollTop = 0; }
        var f = $('[data-sheet-footer]', root); if (f) { f.innerHTML = parts.footer; f.hidden = !parts.footer; }
      } else {
        App.sheet.open(root, { title: title, html: parts.body, footer: parts.footer, focus: false });
        if (DESKTOP.matches && App.unlockScroll) App.unlockScroll();   // list stays scrollable beside the panel
      }
      $$('.pl-item.is-open').forEach(function (el) { el.classList.remove('is-open'); });
      if (item) item.classList.add('is-open');
      E.current = { id: id, item: item, root: root };
      initPreview(root);
      autosize($('[data-comment-input]', root));
      syncState(root);
      if (opts.deny) openDeny(root);
      if (!opts.silent) pushEmail(id);
      document.dispatchEvent(new CustomEvent('emails:open', { detail: { id: id } }));
      return root;
    }).catch(function (err) {
      toast(err && err.message ? err.message : 'Could not open this email', 'error');
      return null;
    });
  };

  E.close = function () { if (App.sheet.current === sheetRoot()) App.sheet.close(); };

  /* history: ?email=ID ⇄ sheet */
  function urlWithEmail(id) {
    var u = new URL(window.location.href);
    if (id) u.searchParams.set('email', id); else u.searchParams.delete('email');
    return u.pathname + u.search + u.hash;
  }
  function pushEmail(id) {
    try {
      if (E._pushed) history.replaceState({ email: id }, '', urlWithEmail(id));
      else { history.pushState({ email: id }, '', urlWithEmail(id)); E._pushed = true; }
    } catch (e) {}
  }
  window.addEventListener('popstate', function (e) {
    var id = e.state && e.state.email;
    if (!id) { E._pushed = false; if (E.current) { E.current = null; E.close(); } }
    else if (!E.current || E.current.id !== String(id)) { E._pushed = true; E.open(id, { silent: true }); }
  });
  window.addEventListener('scroll', function () { if (E.current && DESKTOP.matches) E._lastY = window.scrollY || 0; }, { passive: true });
  document.addEventListener('sheet:close', function (e) {
    if (e.detail.sheet !== sheetRoot()) return;
    var cur = E.current; E.current = null;
    if (DESKTOP.matches && E._lastY != null) { var y = E._lastY; E._lastY = null; requestAnimationFrame(function () { window.scrollTo(0, y); }); }
    e.detail.sheet.classList.remove('is-detail');
    $$('.pl-item.is-open').forEach(function (el) { el.classList.remove('is-open'); });
    if (E._pushed) { E._pushed = false; try { history.back(); } catch (err) {} }
    document.dispatchEvent(new CustomEvent('emails:close', { detail: { id: cur ? cur.id : null } }));
  });

  /* ---- preview frame: Phone / Desktop width, scaled to fit ----------- */
  function fitPreview(root) {
    var wrap = $('[data-preview-frame-wrap]', root); if (!wrap) return;
    var frame = $('[data-preview-frame]', wrap); if (!frame) return;
    var w = parseInt(wrap.getAttribute('data-preview-w') || '650', 10) || 650;
    var avail = wrap.clientWidth || w;
    var scale = Math.min(1, avail / w);
    var h = wrap.clientHeight || 480;
    frame.style.width = w + 'px';
    frame.style.height = Math.round(h / scale) + 'px';
    frame.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
    wrap.classList.toggle('is-scaled', scale < 1);
    frame.style.marginLeft = Math.max(0, Math.round((avail - w * scale) / 2)) + 'px';
  }
  function initPreview(root) {
    var wrap = $('[data-preview-frame-wrap]', root); if (!wrap) return;
    fitPreview(root);
    if (!E._fitBound) {
      E._fitBound = true;
      window.addEventListener('resize', function () { if (E.current) fitPreview(E.current.root); });
    }
  }
  function setPreviewWidth(root, w) {
    var wrap = $('[data-preview-frame-wrap]', root);
    if (wrap) wrap.setAttribute('data-preview-w', String(w));
    $$('[data-preview-width]', root).forEach(function (b) {
      var on = b.getAttribute('data-preview-width') === String(w);
      b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    fitPreview(root);
  }

  /* ---- state sync (which footer rows show) ------------------------- */
  function syncState(root) {
    var art = ed(root); if (!art) return;
    var status = art.getAttribute('data-status') || 'draft';
    var live = art.getAttribute('data-live') === '1';
    var key = keyOf(status, live);
    art.setAttribute('data-key', key);
    var show = {
      'decide':         key === 'pending',
      'approved':       key === 'approved',
      'live':           live,
      'denied':         key === 'denied',
      'draft':          key === 'draft',
      'admin-status':   !live,
      'admin-draft':    key === 'draft',
      'admin-approved': key === 'approved',
      'admin-denied':   key === 'denied',
      'admin-live':     live
    };
    $$('[data-state]', root).forEach(function (el) {
      var k = el.getAttribute('data-state');
      if (k in show) el.hidden = !show[k];
    });
    $$('[data-set-status]', root).forEach(function (b) {
      var on = b.getAttribute('data-set-status') === status;
      b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    // "send date has passed" note: only for live emails whose date is before today (data-past from PHP)
    var pastNote = $('[data-send-past]', root);
    if (pastNote) pastNote.hidden = !(live && art.getAttribute('data-past') === '1');
  }

  function applyPill(pill, key) {
    if (!pill) return;
    pill.className = pill.className.replace(/\bui-pill--(pending|approved|denied|scheduled|neutral)\b/g, '').replace(/\s+/g, ' ').trim();
    pill.classList.add('ui-pill--' + (PILL[key] || 'neutral'));
    pill.setAttribute('data-status', key === 'live' ? 'posted' : key);
    pill.textContent = LABELS[key] || key;
  }
  function applyStatus(id, status, live) {
    var art = ed(); var item = itemEl(id);
    if (art && art.getAttribute('data-id') !== String(id)) art = null;
    [art, item].forEach(function (el) {
      if (!el) return;
      if (status !== null) el.setAttribute('data-status', status);
      if (live !== null) el.setAttribute('data-live', live ? '1' : '0');
      var key = keyOf(el.getAttribute('data-status'), el.getAttribute('data-live') === '1');
      el.setAttribute('data-key', key);
      applyPill($('.ui-pill[data-status-pill]', el), key);
      if (el === item) {
        var tile = $('.el-code-tile', item);
        if (tile) tile.className = tile.className.replace(/\bel-code-tile--\w+\b/g, '').trim() + ' el-code-tile--' + key;
        item.classList.toggle('pl-item--past', key === 'live' && item.getAttribute('data-past') === '1');
      }
    });
    if (art) syncState(art.closest('.ui-sheet-root') || document);
  }

  function snapshot(id) {
    var art = ed(); var item = itemEl(id);
    var src = (art && art.getAttribute('data-id') === String(id)) ? art : item;
    if (!src) return null;
    return { status: src.getAttribute('data-status'), live: src.getAttribute('data-live') === '1' };
  }

  /* ---- move the card between segments ------------------------------- */
  var removed = {};   // id → {el, parent, next} so a failed request can put the card back
  function leaveList(id, cb) {
    var item = itemEl(id);
    if (!item) { if (cb) cb(); return; }
    item.classList.remove('is-open');
    removed[id] = { el: item, parent: item.parentNode, next: item.nextElementSibling };
    if (App.remove) App.remove(item, function () { maybeEmpty(); if (cb) cb(); });
    else { item.remove(); maybeEmpty(); if (cb) cb(); }
  }
  function restoreList(id) {
    var rec = removed[id]; delete removed[id];
    var item = itemEl(id) || (rec && rec.el);
    if (!item) return;
    if (!document.contains(item) && rec && rec.parent) {
      var next = rec.next && rec.next.parentNode === rec.parent ? rec.next : null;
      rec.parent.insertBefore(item, next);
      var group = $('[data-emails-list]'); if (group) group.hidden = false;
      var empty = $('[data-emails-empty]'); if (empty) empty.hidden = true;
    }
    item.classList.remove('ui-leave', 'is-busy', 'is-settling', 'is-swiping');
    item.removeAttribute('data-swipe-dir');
    var card = $('.pl-card', item); if (card) card.style.transform = '';
  }
  function stays(seg) { return E.segment === 'all' || seg === E.segment; }

  /* ---- decide: approve / needs changes(+note) / route (admin) -------- */
  E.decide = function (id, status, note, opts) {
    id = String(id);
    opts = opts || {};
    var before = snapshot(id);
    if (!before) return Promise.resolve(null);
    var fromSeg = keyOf(before.status, before.live);
    var toSeg   = keyOf(status, before.live);
    var params  = { id: id, status: status, actor: App.actor };
    if (note) params.comment = note;

    // optimistic
    applyStatus(id, status, null);
    bumpCount(fromSeg, -1); bumpCount(toSeg, +1);
    var item = itemEl(id); if (item) item.classList.add('is-busy');
    var moved = false;
    if (toSeg !== fromSeg && !stays(toSeg)) { leaveList(id, null); moved = true; }

    return App.post(ENDPOINT, params).then(function (res) {
      if (!res.ok) {
        applyStatus(id, before.status, before.live);
        bumpCount(toSeg, -1); bumpCount(fromSeg, +1);
        restoreList(id);
        toast(res.error || 'Could not save' + (moved ? ' — back in ' + (LABELS[fromSeg] || fromSeg) : ''), 'error');
      } else {
        delete removed[id];
        if (item) item.classList.remove('is-busy');
        var art = ed();
        if (art && art.getAttribute('data-id') === id) {
          if (status === 'approved') {
            var line = $('[data-approved-line]', art.closest('.ui-sheet-root') || document);
            if (line) line.textContent = 'Approved ' + fmtDay(new Date()) + ' · Joust will make it live';
          }
          if (note) appendComment(art, note, App.actor);
          var form = $('[data-deny-form]', sheetRoot()); if (form) { form.hidden = true; var ta = $('[data-deny-note]', form); if (ta) ta.value = ''; }
        }
        if (opts.toast) toast(opts.toast, 'success');
        else if (status === 'approved') toast('Approved', 'success');
        else if (status === 'denied') toast(App.role === 'admin' ? 'Marked as needs changes' : 'Sent to Joust', 'success');
        else if (status === 'pending') toast(before.status === 'draft' ? 'Sent for review' : 'Back in To Review');
        else toast('Moved to Draft');
        if (status === 'denied' && App.role !== 'admin' && E.current && E.current.id === id) setTimeout(E.close, 700);
      }
      document.dispatchEvent(new CustomEvent('emails:decided', { detail: { id: id, status: status, ok: res.ok } }));
      return res;
    });
  };

  /* ---- work queue (admin): denied → pending -------------------------- */
  E.resubmit = function (id) {
    id = String(id);
    var item = itemEl(id);
    var btns = item ? $$('[data-resubmit]', item) : [];
    btns.forEach(function (b) { b.disabled = true; });
    if (E.current && E.current.id === id && E.segment === 'denied') E.close();
    return E.decide(id, 'pending', null, { toast: 'Resubmitted — back in To Review' }).then(function (res) {
      if (!res || !res.ok) btns.forEach(function (b) { b.disabled = false; });
      return res;
    });
  };

  /* ---- admin: draft → pending ----------------------------------------- */
  E.submit = function (id) {
    return E.decide(id, 'pending', null, { toast: 'Sent for review' });
  };

  E.toggleLive = function (id, to) {
    id = String(id);
    var before = snapshot(id); if (!before) return Promise.resolve(null);
    var live = to === 1 || to === '1' || to === true;
    var fromSeg = keyOf(before.status, before.live), toSeg = keyOf(before.status, live);
    applyStatus(id, null, live);
    bumpCount(fromSeg, -1); bumpCount(toSeg, +1);
    var moved = false;
    if (toSeg !== fromSeg && !stays(toSeg)) { leaveList(id); moved = true; }
    return App.post(ENDPOINT, { action: 'toggle_live', id: id, to: live ? '1' : '0', actor: App.actor }).then(function (res) {
      if (!res.ok) {
        applyStatus(id, null, before.live);
        bumpCount(toSeg, -1); bumpCount(fromSeg, +1);
        restoreList(id);
        toast(res.error || 'Could not update', 'error');
      } else {
        delete removed[id];
        toast(live ? 'Marked live' : 'Unmarked — back in Approved', 'success');
      }
      return res;
    });
  };

  E.remove = function (id) {
    id = String(id);
    if (!window.confirm('Delete this email? Its comments and history stay in the activity log. This cannot be undone.')) return Promise.resolve(null);
    return App.post(ENDPOINT, { action: 'delete_email', id: id, actor: App.actor }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Delete failed', 'error'); return res; }
      var before = snapshot(id);
      if (before) bumpCount(keyOf(before.status, before.live), -1);
      if (E.current && E.current.id === id) E.close();
      leaveList(id);
      toast('Email deleted', 'success');
      return res;
    });
  };

  /* ---- comments ----------------------------------------------------- */
  function appendComment(art, text, actor) {
    var root = art.closest('.ui-sheet-root') || document;
    var thread = $('[data-thread]', root); if (!thread) return;
    var side = actor === 'client' ? 'client' : 'joust';
    var who  = actor === 'client' ? 'You' : (actor === 'admin' ? 'Joust' : 'Note');
    var msg = document.createElement('div');
    msg.className = 'pd-msg pd-msg--' + side + ' ui-enter';
    msg.setAttribute('data-actor', actor);
    msg.innerHTML = '<div class="ui-bubble ui-bubble--' + side + '">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>'
                  + '<div class="ui-bubble-meta">' + who + ' · just now</div>';
    var empty = $('[data-thread-empty]', thread); if (empty) empty.hidden = true;
    thread.appendChild(msg);
    var n = (parseInt(thread.getAttribute('data-count') || '0', 10) || 0) + 1;
    thread.setAttribute('data-count', n);
    var c = $('[data-comment-count]', root); if (c) c.textContent = n;
    var id = art.getAttribute('data-id');
    var lc = $('[data-comment-count-for="' + id + '"]'); if (lc) lc.textContent = n + (n === 1 ? ' comment' : ' comments');
    if (actor === 'client') {
      var qc = $('[data-queue-count="' + id + '"]');
      if (qc) { var k = (parseInt((qc.textContent.match(/\d+/) || ['0'])[0], 10) || 0) + 1; qc.textContent = k + ' client ' + (k === 1 ? 'comment' : 'comments'); }
    }
    var body = $('[data-sheet-body]', root); if (body) body.scrollTop = body.scrollHeight;
  }

  E.comment = function (id, text) {
    text = (text || '').trim();
    if (!text) return Promise.resolve(null);
    return App.post(ENDPOINT, { id: id, comment: text, actor: App.actor }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Could not send', 'error'); return res; }
      var art = ed(); if (art && art.getAttribute('data-id') === String(id)) appendComment(art, text, App.actor);
      return res;
    });
  };

  function autosize(ta) {
    if (!ta) return;
    ta.style.height = 'auto';
    ta.style.height = Math.min(120, ta.scrollHeight) + 'px';
  }

  /* ---- deny note ---------------------------------------------------- */
  function openDeny(root) {
    var form = $('[data-deny-form]', root); if (!form) return;
    form.hidden = false;
    var ta = $('[data-deny-note]', form);
    if (ta) setTimeout(function () { ta.focus(); }, 50);
    validateDeny(form);
  }
  function validateDeny(form) {
    var ta = $('[data-deny-note]', form), btn = $('[data-deny-submit]', form), hint = $('[data-deny-hint]', form);
    var ok = ta && ta.value.trim().length >= 3;
    if (btn) btn.disabled = !ok;
    if (hint) hint.classList.toggle('is-error', !ok && ta && ta.value.trim().length > 0);
    return ok;
  }

  /* ================================================================== */
  /* Wiring                                                              */
  /* ================================================================== */
  function init() {
    var list = $('[data-emails-list]');
    if (list) {
      App.swipe.attach(list, {
        card: '.pl-card',
        canSwipe: function (item) {
          // Only a waiting (pending, not live) email can be decided from the list; the server re-checks.
          return item.getAttribute('data-status') === 'pending' && item.getAttribute('data-live') !== '1';
        },
        commitOut: 'right',
        onCommit: function (item, dir) {
          var id = item.getAttribute('data-id');
          if (dir === 'right') E.decide(id, 'approved');
          else E.open(id, { deny: true });
        }
      });
    }

    // open detail
    document.addEventListener('click', function (e) {
      var opener = e.target.closest('[data-email-open]');
      if (!opener) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault();
      E.open(opener.getAttribute('data-email-open'));
    });

    // work queue: Resubmit for review (admin-only markup; email-status.php enforces the role)
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-resubmit]');
      if (!btn || btn.disabled) return;
      e.preventDefault();
      E.resubmit(btn.getAttribute('data-resubmit'));
    });

    // everything inside the sheet
    document.addEventListener('click', function (e) {
      var root = sheetRoot(); if (!root || !root.contains(e.target)) return;
      var art = ed(root); if (!art) return;
      var id = art.getAttribute('data-id');
      var t = e.target;

      var pw = t.closest('[data-preview-width]');
      if (pw) { setPreviewWidth(root, pw.getAttribute('data-preview-width')); return; }

      var decide = t.closest('[data-decide]');
      if (decide) {
        var st = decide.getAttribute('data-decide');
        if (st === 'denied') openDeny(root); else E.decide(id, st);
        return;
      }
      var setSt = t.closest('[data-set-status]');
      if (setSt) {
        var to = setSt.getAttribute('data-set-status');
        if (to === art.getAttribute('data-status')) return;
        if (to === 'denied') openDeny(root); else E.decide(id, to);
        return;
      }
      if (t.closest('[data-deny-cancel]')) { var f = $('[data-deny-form]', root); if (f) f.hidden = true; return; }
      if (t.closest('[data-resubmit-detail]')) { E.decide(id, 'pending', null, { toast: 'Resubmitted — back in To Review' }); return; }
      if (t.closest('[data-submit]')) { E.submit(id); return; }

      var tl = t.closest('[data-toggle-live]');
      if (tl) { E.toggleLive(id, tl.getAttribute('data-toggle-live')); return; }
      if (t.closest('[data-delete-email]')) { E.remove(id); return; }
    });

    document.addEventListener('submit', function (e) {
      var root = sheetRoot(); if (!root || !root.contains(e.target)) return;
      var art = ed(root); if (!art) return;
      var form = e.target;
      e.preventDefault();
      if (form.hasAttribute('data-deny-form')) {
        if (!validateDeny(form)) { var ta = $('[data-deny-note]', form); if (ta) ta.focus(); return; }
        E.decide(art.getAttribute('data-id'), 'denied', $('[data-deny-note]', form).value.trim());
        return;
      }
      if (form.hasAttribute('data-comment-form')) {
        var input = $('[data-comment-input]', form), text = input ? input.value.trim() : '';
        if (!text) return;
        var send = $('[data-comment-send]', form); if (send) send.disabled = true;
        E.comment(art.getAttribute('data-id'), text).then(function (res) {
          if (res && res.ok && input) { input.value = ''; autosize(input); }
          if (send) send.disabled = !(input && input.value.trim());
        });
      }
    });

    document.addEventListener('input', function (e) {
      var root = sheetRoot(); if (!root || !root.contains(e.target)) return;
      if (e.target.matches('[data-deny-note]')) { validateDeny(e.target.closest('form')); return; }
      if (e.target.matches('[data-comment-input]')) {
        autosize(e.target);
        var send = $('[data-comment-send]', e.target.closest('form')); if (send) send.disabled = !e.target.value.trim();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.shiftKey || !e.target.matches || !e.target.matches('[data-comment-input]')) return;
      e.preventDefault();
      var form = e.target.closest('form'); if (form && form.requestSubmit) form.requestSubmit(); else if (form) form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    });

    // deep link (?email=ID): open once the shared App (sheet, toast) is ready —
    // emails.js is a deferred script emitted BEFORE app.js, so App.sheet may not exist yet.
    if (cfg.openEmail) {
      var openDeepLink = function () {
        try { history.replaceState({ email: null }, '', urlWithEmail(null)); } catch (err) {}
        E.open(cfg.openEmail);
      };
      if (App._inited && App.sheet) openDeepLink();
      else document.addEventListener('app:ready', openDeepLink, { once: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})(window, document);
