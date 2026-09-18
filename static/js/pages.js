/* =====================================================================
   Joust client portal — pages.js  (Pages module; the emails.js twin)
   Extends the global App from app.js; never edits it. Loads BEFORE app.js
   (deferred, emitted by pages.php's / add-page.php's $footExtra) so deep links
   wait for 'app:ready'.

   App.swipe                       same API as posts.js (guarded copy — posts.js is not loaded here)
   App.pages.open(id, {deny})      open the page detail sheet (inline template or partial fetch)
   App.pages.close()
   App.pages.decide(id, status, note, {toast})  optimistic approve / needs changes (+ required note) / route (admin)
   App.pages.resubmit(id)          admin work queue: denied → pending, the row leaves the queue
   App.pages.submit(id)            admin: draft → pending ("Send for review")
   App.pages.toggleLive(id, to)    admin: live 0↔1 (to=1 needs status approved — server answers 409)
   App.pages.remove(id)            admin: delete (confirm) — the folder goes too
   App.pages.comment(id, text)
   App.pageForm                    add-page.php: slug auto-fill, source chips, Live guard
   App.pageFiles                   add-page.php: sequential XHR uploader → page-upload.php, delete file, set entry
   Events: 'pages:decided' {id, status, ok}, 'pages:open' {id}, 'pages:close' {id}
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var cfg = window.PagesConfig || {};
  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  var ENDPOINT = cfg.endpoint || 'page-status.php';
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
  /* (identical to posts.js / emails.js; defined only when no other script did) */
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
  /* App.pages — detail sheet + actions                                   */
  /* ================================================================== */
  var P = App.pages = {
    current: null,        // { id, item, root }
    _pushed: false,
    counts: cfg.counts || {},
    segment: cfg.segment || 'pending'
  };

  function sheetRoot() { return $('#uiSheet'); }
  function itemEl(id) { return $('[data-page-item="' + id + '"]'); }
  function pg(root) { return $('.pg[data-page-detail]', root || sheetRoot()); }

  /* ---- counts ------------------------------------------------------- */
  function bumpCount(seg, delta) {
    if (!seg) return;
    P.counts[seg] = Math.max(0, (P.counts[seg] || 0) + delta);
    if (seg !== 'all') P.counts.all = Math.max(0, (P.counts.all || 0) + delta);
    var n = P.counts[seg];
    var item = $('.ui-segmented-item[data-segment="' + seg + '"] .ui-segmented-count');
    if (item) item.textContent = n;
    if (seg === P.segment) { var hdr = $('[data-segment-count]'); if (hdr) hdr.textContent = n; }
    if (P.segment === 'all') { var h2 = $('[data-segment-count]'); if (h2) h2.textContent = P.counts.all; }
    if (seg === 'pending') {
      var badge = $('.ui-tab--pages .ui-badge');
      if (badge) { badge.textContent = n > 99 ? '99+' : n; badge.hidden = n === 0; }
    }
  }
  function maybeEmpty() {
    var list = $('[data-pages-items]');
    if (!list || list.children.length) return;
    var group = $('[data-pages-list]'); if (!group) return;
    group.hidden = true;
    var empty = $('[data-pages-empty]');
    if (!empty) {
      empty = document.createElement('div');
      empty.className = 'ui-empty posts-empty ui-enter';
      empty.setAttribute('data-pages-empty', '');
      empty.textContent = P.segment === 'pending' ? 'All caught up — nothing left to review.'
                        : (P.segment === 'denied' ? 'Nothing needs changes — the queue is clear.' : 'Nothing here.');
      group.parentNode.insertBefore(empty, group);
    }
    empty.hidden = false;
  }

  /* ---- detail loading ----------------------------------------------- */
  function detailHtml(id) {
    var tpl = $('template[data-page-template="' + id + '"]');
    if (tpl) return Promise.resolve(tpl.innerHTML);
    if (!cfg.partialUrl) return Promise.reject(new Error('No detail available'));
    return fetch(cfg.partialUrl.replace('__ID__', encodeURIComponent(id)), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) { if (!res.ok) throw new Error('Could not load this page'); return res.text(); });
  }

  function splitDetail(html) {
    var box = document.createElement('div');
    box.innerHTML = html;
    var art = $('.pg[data-page-detail]', box);
    if (!art) return { body: html, footer: '' };
    var body = $('[data-pd-body]', art), footer = $('[data-pd-footer]', art);
    var shell = art.cloneNode(false);
    shell.innerHTML = body ? body.outerHTML : '';
    return { body: shell.outerHTML, footer: footer ? footer.outerHTML : '' };
  }

  P.open = function (id, opts) {
    opts = opts || {};
    id = String(id);
    var item = itemEl(id);
    var title = item ? (item.getAttribute('data-title') || 'Page') : 'Page';
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
      P.current = { id: id, item: item, root: root };
      initPreview(root);
      autosize($('[data-comment-input]', root));
      syncState(root);
      if (opts.deny) openDeny(root);
      if (!opts.silent) pushPage(id);
      document.dispatchEvent(new CustomEvent('pages:open', { detail: { id: id } }));
      return root;
    }).catch(function (err) {
      toast(err && err.message ? err.message : 'Could not open this page', 'error');
      return null;
    });
  };

  P.close = function () { if (App.sheet.current === sheetRoot()) App.sheet.close(); };

  /* history: ?page=ID ⇄ sheet */
  function urlWithPage(id) {
    var u = new URL(window.location.href);
    if (id) u.searchParams.set('page', id); else u.searchParams.delete('page');
    return u.pathname + u.search + u.hash;
  }
  function pushPage(id) {
    try {
      if (P._pushed) history.replaceState({ page: id }, '', urlWithPage(id));
      else { history.pushState({ page: id }, '', urlWithPage(id)); P._pushed = true; }
    } catch (e) {}
  }
  window.addEventListener('popstate', function (e) {
    var id = e.state && e.state.page;
    if (!id) { P._pushed = false; if (P.current) { P.current = null; P.close(); } }
    else if (!P.current || P.current.id !== String(id)) { P._pushed = true; P.open(id, { silent: true }); }
  });
  window.addEventListener('scroll', function () { if (P.current && DESKTOP.matches) P._lastY = window.scrollY || 0; }, { passive: true });
  document.addEventListener('sheet:close', function (e) {
    if (e.detail.sheet !== sheetRoot()) return;
    var cur = P.current; P.current = null;
    if (DESKTOP.matches && P._lastY != null) { var y = P._lastY; P._lastY = null; requestAnimationFrame(function () { window.scrollTo(0, y); }); }
    e.detail.sheet.classList.remove('is-detail');
    $$('.pl-item.is-open').forEach(function (el) { el.classList.remove('is-open'); });
    if (P._pushed) { P._pushed = false; try { history.back(); } catch (err) {} }
    document.dispatchEvent(new CustomEvent('pages:close', { detail: { id: cur ? cur.id : null } }));
  });

  /* ---- preview frame: Phone / Desktop width, scaled to fit ----------- */
  function fitPreview(root) {
    var wrap = $('[data-preview-frame-wrap]', root); if (!wrap) return;
    var frame = $('[data-preview-frame]', wrap); if (!frame) return;
    var w = parseInt(wrap.getAttribute('data-preview-w') || '1280', 10) || 1280;
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
    if (!P._fitBound) {
      P._fitBound = true;
      window.addEventListener('resize', function () { if (P.current) fitPreview(P.current.root); });
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
    var art = pg(root); if (!art) return;
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
  }

  function applyPill(pill, key) {
    if (!pill) return;
    pill.className = pill.className.replace(/\bui-pill--(pending|approved|denied|scheduled|neutral)\b/g, '').replace(/\s+/g, ' ').trim();
    pill.classList.add('ui-pill--' + (PILL[key] || 'neutral'));
    pill.setAttribute('data-status', key === 'live' ? 'posted' : key);
    pill.textContent = LABELS[key] || key;
  }
  function applyStatus(id, status, live) {
    var art = pg(); var item = itemEl(id);
    if (art && art.getAttribute('data-id') !== String(id)) art = null;
    [art, item].forEach(function (el) {
      if (!el) return;
      if (status !== null) el.setAttribute('data-status', status);
      if (live !== null) el.setAttribute('data-live', live ? '1' : '0');
      var key = keyOf(el.getAttribute('data-status'), el.getAttribute('data-live') === '1');
      el.setAttribute('data-key', key);
      applyPill($('.ui-pill[data-status-pill]', el), key);
      if (el === item) {
        var tile = $('.pgl-tile', item);
        if (tile) tile.className = tile.className.replace(/\bpgl-tile--\w+\b/g, '').trim() + ' pgl-tile--' + key;
      }
    });
    if (art) syncState(art.closest('.ui-sheet-root') || document);
  }

  function snapshot(id) {
    var art = pg(); var item = itemEl(id);
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
      var group = $('[data-pages-list]'); if (group) group.hidden = false;
      var empty = $('[data-pages-empty]'); if (empty) empty.hidden = true;
    }
    item.classList.remove('ui-leave', 'is-busy', 'is-settling', 'is-swiping');
    item.removeAttribute('data-swipe-dir');
    var card = $('.pl-card', item); if (card) card.style.transform = '';
  }
  function stays(seg) { return P.segment === 'all' || seg === P.segment; }

  /* ---- decide: approve / needs changes(+note) / route (admin) -------- */
  P.decide = function (id, status, note, opts) {
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
        var art = pg();
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
        if (status === 'denied' && App.role !== 'admin' && P.current && P.current.id === id) setTimeout(P.close, 700);
      }
      document.dispatchEvent(new CustomEvent('pages:decided', { detail: { id: id, status: status, ok: res.ok } }));
      return res;
    });
  };

  /* ---- work queue (admin): denied → pending -------------------------- */
  P.resubmit = function (id) {
    id = String(id);
    var item = itemEl(id);
    var btns = item ? $$('[data-resubmit]', item) : [];
    btns.forEach(function (b) { b.disabled = true; });
    if (P.current && P.current.id === id && P.segment === 'denied') P.close();
    return P.decide(id, 'pending', null, { toast: 'Resubmitted — back in To Review' }).then(function (res) {
      if (!res || !res.ok) btns.forEach(function (b) { b.disabled = false; });
      return res;
    });
  };

  /* ---- admin: draft → pending ----------------------------------------- */
  P.submit = function (id) {
    return P.decide(id, 'pending', null, { toast: 'Sent for review' });
  };

  P.toggleLive = function (id, to) {
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

  P.remove = function (id) {
    id = String(id);
    var art = pg();
    var isUpload = !(art && art.getAttribute('data-id') === id && art.getAttribute('data-source') === 'url');
    if (!window.confirm('Delete this page?' + (isUpload ? ' Its folder and every uploaded file are removed too.' : '') + ' Its comments and history stay in the activity log. This cannot be undone.')) return Promise.resolve(null);
    return App.post(ENDPOINT, { action: 'delete_page', id: id, actor: App.actor }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Delete failed', 'error'); return res; }
      var before = snapshot(id);
      if (before) bumpCount(keyOf(before.status, before.live), -1);
      if (P.current && P.current.id === id) P.close();
      leaveList(id);
      toast('Page deleted', 'success');
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

  P.comment = function (id, text) {
    text = (text || '').trim();
    if (!text) return Promise.resolve(null);
    return App.post(ENDPOINT, { id: id, comment: text, actor: App.actor }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Could not send', 'error'); return res; }
      var art = pg(); if (art && art.getAttribute('data-id') === String(id)) appendComment(art, text, App.actor);
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

  /**
   * Admin: "Repair" on the sheet's Server check line → page-upload.php action=repair_media
   * (media/pages/.htaccess rewritten when old / missing, an old media/.htaccess of ours removed,
   * files 0644 / folders 0755 under media/pages/<client>/). The reply carries a fresh check of
   * the entry file, which replaces the line's text in place.
   */
  function repairMedia(art, btn) {
    var line = btn.closest('[data-server-check]');
    var endpoint = (line && line.getAttribute('data-repair-endpoint')) || 'page-upload.php';
    btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Repairing…';
    return App.post(endpoint, { action: 'repair_media', page_id: art.getAttribute('data-id'), actor: App.actor }).then(function (res) {
      btn.disabled = false; btn.removeAttribute('aria-busy'); btn.textContent = 'Repair';
      var d = res.data || {};
      if (!res.ok) { toast(res.error || 'Repair failed', 'error'); return res; }
      toast(d.summary || 'Server rules repaired', 'success');
      if (line && d.check) {
        var text = $('[data-server-check-text]', line);
        if (text) text.textContent = d.check.summary || '';
        line.setAttribute('data-server-check', d.check.ok ? 'ok' : 'warn');
        line.classList.toggle('pg-server-check--warn', !d.check.ok);
        if (d.check.ok) btn.hidden = true;
      }
      return res;
    });
  }

  /**
   * Admin: "Extract embedded images" on the Server check line → page-upload.php action=extract_inline
   * (every base64 data: URI in the page's HTML files becomes a file under assets/, the reference is
   * rewritten). The reply carries the summary + a fresh check of the entry; the sheet is reloaded so
   * the preview (switched off while the entry is over ~400 KB) comes back.
   */
  function extractInline(art, btn) {
    var line = btn.closest('[data-server-check]');
    var endpoint = (line && line.getAttribute('data-repair-endpoint')) || 'page-upload.php';
    var id = art.getAttribute('data-id');
    btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Extracting…';
    return App.post(endpoint, { action: 'extract_inline', page_id: id, actor: App.actor }).then(function (res) {
      btn.disabled = false; btn.removeAttribute('aria-busy'); btn.textContent = 'Extract embedded images';
      var d = res.data || {};
      if (!res.ok) { toast(res.error || 'Extraction failed', 'error'); return res; }
      var t = d.totals || {};
      toast(d.summary || 'Done', t.extracted > 0 ? 'success' : (t.skipped > 0 || t.failed > 0 ? 'error' : 'success'));
      if (line && d.check) {
        var text = $('[data-server-check-text]', line);
        if (text) text.textContent = d.check.summary || '';
        line.setAttribute('data-server-check', d.check.ok ? 'ok' : 'warn');
        line.classList.toggle('pg-server-check--warn', !d.check.ok);
        if (d.check.html && !d.check.html.large) btn.hidden = true;
      }
      if (t.extracted > 0 && cfg.partialUrl) {
        // the inline <template> (if any) still holds the old markup: drop it so the reopen fetches the partial
        var tpl = $('template[data-page-template="' + id + '"]'); if (tpl) tpl.remove();
        P.open(id, { silent: true });
      }
      return res;
    });
  }

  /* ================================================================== */
  /* Wiring — pages.php                                                  */
  /* ================================================================== */
  function initList() {
    var list = $('[data-pages-list]');
    if (list) {
      App.swipe.attach(list, {
        card: '.pl-card',
        canSwipe: function (item) {
          // Only a waiting (pending, not live) page can be decided from the list; the server re-checks.
          return item.getAttribute('data-status') === 'pending' && item.getAttribute('data-live') !== '1';
        },
        commitOut: 'right',
        onCommit: function (item, dir) {
          var id = item.getAttribute('data-id');
          if (dir === 'right') P.decide(id, 'approved');
          else P.open(id, { deny: true });
        }
      });
    }

    // open detail
    document.addEventListener('click', function (e) {
      var opener = e.target.closest('[data-page-open]');
      if (!opener) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault();
      P.open(opener.getAttribute('data-page-open'));
    });

    // work queue: Resubmit for review (admin-only markup; page-status.php enforces the role)
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-resubmit]');
      if (!btn || btn.disabled) return;
      e.preventDefault();
      P.resubmit(btn.getAttribute('data-resubmit'));
    });

    // everything inside the sheet
    document.addEventListener('click', function (e) {
      var root = sheetRoot(); if (!root || !root.contains(e.target)) return;
      var art = pg(root); if (!art) return;
      var id = art.getAttribute('data-id');
      var t = e.target;
      if (t.closest('[data-page-open-tab]')) return;   // plain link: let the browser open the tab

      var pw = t.closest('[data-preview-width]');
      if (pw) { setPreviewWidth(root, pw.getAttribute('data-preview-width')); return; }

      var decide = t.closest('[data-decide]');
      if (decide) {
        var st = decide.getAttribute('data-decide');
        if (st === 'denied') openDeny(root); else P.decide(id, st);
        return;
      }
      var setSt = t.closest('[data-set-status]');
      if (setSt) {
        var to = setSt.getAttribute('data-set-status');
        if (to === art.getAttribute('data-status')) return;
        if (to === 'denied') openDeny(root); else P.decide(id, to);
        return;
      }
      if (t.closest('[data-deny-cancel]')) { var f = $('[data-deny-form]', root); if (f) f.hidden = true; return; }
      if (t.closest('[data-resubmit-detail]')) { P.decide(id, 'pending', null, { toast: 'Resubmitted — back in To Review' }); return; }
      if (t.closest('[data-submit]')) { P.submit(id); return; }

      var tl = t.closest('[data-toggle-live]');
      if (tl) { P.toggleLive(id, tl.getAttribute('data-toggle-live')); return; }
      if (t.closest('[data-delete-page]')) { P.remove(id); return; }
      var rep = t.closest('[data-page-repair]');
      if (rep && !rep.disabled) { repairMedia(art, rep); return; }
      var ext = t.closest('[data-page-extract]');
      if (ext && !ext.disabled) { extractInline(art, ext); return; }
    });

    document.addEventListener('submit', function (e) {
      var root = sheetRoot(); if (!root || !root.contains(e.target)) return;
      var art = pg(root); if (!art) return;
      var form = e.target;
      e.preventDefault();
      if (form.hasAttribute('data-deny-form')) {
        if (!validateDeny(form)) { var ta = $('[data-deny-note]', form); if (ta) ta.focus(); return; }
        P.decide(art.getAttribute('data-id'), 'denied', $('[data-deny-note]', form).value.trim());
        return;
      }
      if (form.hasAttribute('data-comment-form')) {
        var input = $('[data-comment-input]', form), text = input ? input.value.trim() : '';
        if (!text) return;
        var send = $('[data-comment-send]', form); if (send) send.disabled = true;
        P.comment(art.getAttribute('data-id'), text).then(function (res) {
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

    // deep link (?page=ID): open once the shared App (sheet, toast) is ready —
    // pages.js is a deferred script emitted BEFORE app.js, so App.sheet may not exist yet.
    if (cfg.openPage) {
      var openDeepLink = function () {
        try { history.replaceState({ page: null }, '', urlWithPage(null)); } catch (err) {}
        P.open(cfg.openPage);
      };
      if (App._inited && App.sheet) openDeepLink();
      else document.addEventListener('app:ready', openDeepLink, { once: true });
    }
  }

  /* ================================================================== */
  /* App.pageForm — add-page.php: slug auto-fill, source chips, Live guard */
  /* ================================================================== */
  function slugify(s) {
    return String(s || '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120).replace(/-+$/, '');
  }
  App.pageForm = {
    init: function (form) {
      var title = $('[data-page-title]', form), slug = $('[data-page-slug]', form), folder = $('[data-page-folder]', form);
      var status = $('[data-page-status]', form), live = $('[data-email-live]', form), chip = $('[data-email-live-chip]', form), help = $('[data-email-live-help]', form);
      var locked = !!(slug && slug.hasAttribute('data-page-slug-locked'));   // editing: never overwrite an existing slug
      var touched = !locked && !!(slug && slug.value);                        // re-rendered form: keep what the admin typed
      function syncFolder() {
        if (!folder) return;
        var base = folder.textContent.replace(/\/[^\/]*\/?$/, '/');
        folder.textContent = base + (slug && slug.value ? slugify(slug.value) : '<slug>') + '/';
      }
      if (title && slug) {
        title.addEventListener('input', function () { if (!locked && !touched) { slug.value = slugify(title.value); syncFolder(); } });
        slug.addEventListener('input', function () { touched = slug.value !== ''; syncFolder(); });
        slug.addEventListener('blur', function () { slug.value = slugify(slug.value); syncFolder(); });
      }
      function syncLive() {
        var ok = status && status.value === 'approved';
        if (live) { live.disabled = !ok; if (!ok) live.checked = false; }
        if (chip) chip.classList.toggle('is-active', !!(live && live.checked));
        if (help) help.hidden = ok;
      }
      if (status) status.addEventListener('change', syncLive);
      if (live) live.addEventListener('change', syncLive);
      syncLive();
      function syncSource() {
        var cur = ($('[data-page-source]:checked', form) || {}).value || 'upload';
        $$('[data-page-source-chip]', form).forEach(function (c) { c.classList.toggle('is-active', c.getAttribute('data-page-source-chip') === cur); });
        $$('[data-page-when-source]', form).forEach(function (el) { el.hidden = el.getAttribute('data-page-when-source') !== cur; });
        var files = $('[data-page-files]'); if (files) files.hidden = cur !== 'upload';
      }
      form.addEventListener('change', function (e) { if (e.target.matches('[data-page-source]')) syncSource(); });
      syncSource();
    }
  };

  /* ================================================================== */
  /* App.pageFiles — add-page.php: uploader → page-upload.php            */
  /* ================================================================== */
  function mb(bytes) { return (bytes / 1024 / 1024).toFixed(bytes > 10 * 1024 * 1024 ? 0 : 1) + ' MB'; }
  function fmtBytes(b) { if (b >= 1048576) return (b / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB'; if (b >= 1024) return Math.round(b / 1024) + ' KB'; return b + ' B'; }
  function fileExt(name) { var m = String(name || '').toLowerCase().match(/\.([a-z0-9]+)$/); return m ? m[1] : ''; }

  function PageFiles(root, fc) {
    this.root = root; this.fc = fc;
    this.input = $('[data-page-files-input]', root); this.zone = $('[data-page-dropzone]', root);
    this.sub = $('[data-page-subfolder]', root); this.list = $('[data-page-upload-list]', root);
    this.rows = $('[data-page-file-rows]', root); this.empty = $('[data-page-files-empty]', root); this.count = $('[data-page-files-count]', root);
    this.queue = []; this.busy = false; this.batch = 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    this.entry = fc.entry || 'index.html';
    this.chunk = App.chunkUpload || null; this.info = null; this.infoP = null; this.pending = [];   // chunk-upload.js: large files in pieces
    this.resumeBox = $('[data-page-resume]', root); this.resumeInput = $('[data-page-resume-input]', root);
    var self = this;
    if (this.input) this.input.addEventListener('change', function () { self.add(self.input.files); self.input.value = ''; });
    if (this.resumeInput) this.resumeInput.addEventListener('change', function () { self.resumeFiles(Array.prototype.slice.call(self.resumeInput.files || [])); self.resumeInput.value = ''; });
    if (this.zone) {
      ['dragenter', 'dragover'].forEach(function (ev) { self.zone.addEventListener(ev, function (e) { e.preventDefault(); self.zone.classList.add('is-dragover'); }); });
      ['dragleave', 'drop'].forEach(function (ev) { self.zone.addEventListener(ev, function (e) { e.preventDefault(); self.zone.classList.remove('is-dragover'); }); });
      this.zone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) self.add(e.dataTransfer.files); });
    }
    root.addEventListener('click', function (e) {
      var del = e.target.closest('[data-page-delete-file]');
      if (del) { self.remove(del.getAttribute('data-page-delete-file'), del); return; }
      var ent = e.target.closest('[data-page-set-entry]');
      if (ent) { self.setEntry(ent.getAttribute('data-page-set-entry'), ent); return; }
      if (e.target.closest('[data-page-resume-discard]')) { self.discardResume(); return; }
      var cancel = e.target.closest('[data-page-cancel]');
      if (cancel) { var li = cancel.closest('.studio-upload-item'); if (li && li._job) self.cancel(li._job); return; }
    });
    this.offerResume();
  }
  /** Probe page-upload.php once (chunk size + per-type caps); null → single requests only. */
  PageFiles.prototype.probe = function () {
    if (this.infoP) return this.infoP;
    var self = this;
    this.infoP = (this.chunk ? this.chunk.probe(this.fc.endpoint || 'page-upload.php') : Promise.resolve(null)).then(function (info) { self.info = info; return info; }, function () { return null; });
    return this.infoP;
  };
  /** The size cap for a file: from the probe (video / text / asset) when chunking works, else the single-request 10 MB. */
  PageFiles.prototype.limitFor = function (file, info) {
    var ext = fileExt(file.name), single = (this.fc.maxMb || 10) * 1024 * 1024;
    if (!info || !info.max_file_bytes) return single;
    if ((info.video_exts || ['mp4', 'webm']).indexOf(ext) !== -1) return info.max_file_bytes.video || single;
    if (ext === 'html' || ext === 'htm') return info.max_file_bytes.html || info.max_file_bytes.text || single;   // embedded assets are extracted server-side
    if ((info.text_exts || ['html', 'htm', 'css', 'js', 'json']).indexOf(ext) !== -1) return info.max_file_bytes.text || single;
    return info.max_file_bytes.asset || single;
  };
  PageFiles.prototype.subfolder = function () {
    var v = this.sub ? this.sub.value.trim().replace(/^\/+|\/+$/g, '') : '';
    return v;
  };
  PageFiles.prototype.add = function (files) {
    var self = this, sub = this.subfolder();
    if (sub && !/^[a-z0-9_\-\/]+$/.test(sub) || /(^|\/)\.\.?(\/|$)/.test(sub)) { toast('Subfolder may only use a-z, 0-9, - and _ (e.g. img or assets/fonts)', 'error'); return; }
    Array.prototype.slice.call(files || []).forEach(function (file) { self.addOne(file, sub, null); });
    this.next();
  };
  /** One list row + queue entry. $resume = a ledger entry (upload_id + subfolder) to continue. */
  PageFiles.prototype.addOne = function (file, sub, resume) {
    var li = document.createElement('li');
    li.className = 'studio-upload-item';
    var ext = fileExt(file.name), bad = '';
    if (/^\./.test(file.name)) bad = 'Hidden files are not allowed';
    else if ((this.fc.exts || []).indexOf(ext) === -1) bad = 'Unsupported type .' + (ext || '?');
    else if (!this.chunk && file.size > (this.fc.maxMb || 10) * 1024 * 1024) bad = 'Over ' + (this.fc.maxMb || 10) + ' MB (' + mb(file.size) + ')';   // with chunking the probe's caps decide in next()
    li.innerHTML = '<div class="studio-upload-body"><div class="studio-upload-name">' + escapeHtml((sub ? sub + '/' : '') + file.name) + '</div>'
                 + '<div class="studio-upload-meta text-tertiary">' + escapeHtml(mb(file.size)) + (resume ? ' · resuming' : '') + '</div>'
                 + '<div class="studio-progress" data-upload-progress hidden><div class="studio-progress-bar"><div class="studio-progress-fill" data-upload-fill style="transform:translateX(-100%)"></div></div></div>'
                 + '<div class="studio-upload-status' + (bad ? ' is-error' : '') + '" data-upload-status>' + (bad ? escapeHtml(bad) : 'Queued') + '</div></div>'
                 + '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm studio-upload-retry studio-upload-cancel" data-page-cancel hidden>Cancel</button>';
    if (this.list) { this.list.hidden = false; this.list.appendChild(li); }
    var job = { file: file, item: li, sub: sub, uploadId: resume ? resume.id : null, ctl: null, state: bad ? 'failed' : 'queued' };
    li._job = job;
    if (!bad) this.queue.push(job);
    return job;
  };
  PageFiles.prototype.cancel = function (job) {
    if (job.state === 'queued') { this.queue = this.queue.filter(function (j) { return j !== job; }); this.failJob(job, 'Cancelled'); return; }
    if (job.state === 'uploading' && job.ctl) job.ctl.abort();
  };
  PageFiles.prototype.failJob = function (job, msg) {
    var status = $('[data-upload-status]', job.item), prog = $('[data-upload-progress]', job.item), cancel = $('[data-page-cancel]', job.item);
    job.state = 'failed';
    status.textContent = msg; status.classList.remove('is-ok'); status.classList.add('is-error');
    if (prog) prog.hidden = true;
    if (cancel) cancel.hidden = true;
  };
  PageFiles.prototype.doneJob = function (job, data) {
    var self = this, status = $('[data-upload-status]', job.item), fill = $('[data-upload-fill]', job.item);
    job.state = 'done';
    if (fill) fill.style.transform = 'translateX(0)';
    status.innerHTML = (data.file.replaced ? 'Replaced' : 'Uploaded') + ' — <a href="' + escapeHtml(data.file.url) + '" target="_blank" rel="noopener">open</a>';
    // HTML with embedded base64 assets: the server moved them into assets/ files ("Extracted 14 images · 5.4 MB → 180 KB")
    var ex = data.extract;
    if (ex && (ex.extracted > 0 || ex.skipped > 0 || ex.failed > 0)) {
      status.innerHTML += ' · <span data-upload-extract>' + escapeHtml(ex.summary || '') + '</span>';
      (ex.files || []).forEach(function (f) { self.upsertRow(f.name, f.size, f.url); });
    }
    status.classList.remove('is-error'); status.classList.add('is-ok');
    if (data.page && data.page.entry) self.entry = data.page.entry;
    self.upsertRow(data.file.name, data.file.size, data.file.url);
    if (data.page && typeof data.page.file_count === 'number') self.setCount(data.page.file_count);
  };
  PageFiles.prototype.settle = function () {
    var self = this;
    this.busy = false; this.next();
    if (!this.queue.length && !this.busy) { var ok = $$('.studio-upload-status.is-ok', self.list).length, bad = $$('.studio-upload-status.is-error', self.list).length; toast(ok + ' uploaded' + (bad ? ' · ' + bad + ' failed' : ''), bad ? 'error' : 'success'); }
  };
  PageFiles.prototype.next = function () {
    if (this.busy || !this.queue.length) return;
    var self = this, job = this.queue.shift(), item = job.item, file = job.file;
    var prog = $('[data-upload-progress]', item), status = $('[data-upload-status]', item);
    this.busy = true; job.state = 'uploading';
    if (prog) prog.hidden = false;
    status.textContent = 'Uploading… 0%';
    this.probe().then(function (info) {
      var limit = self.limitFor(file, info);
      if (file.size > limit) { self.failJob(job, 'Over ' + fmtBytes(limit) + ' (' + mb(file.size) + ')'); self.settle(); return; }
      if (self.chunk && info && (job.uploadId || file.size > info.chunk_size)) self.sendChunked(job, info);
      else self.sendSingle(job);
    });
  };
  /** Chunked path (chunk-upload.js): init → pieces with progress / retry → finish; the ledger entry survives a reload. */
  PageFiles.prototype.sendChunked = function (job, info) {
    var self = this, item = job.item, file = job.file, endpoint = this.fc.endpoint || 'page-upload.php';
    var fill = $('[data-upload-fill]', item), status = $('[data-upload-status]', item), cancel = $('[data-page-cancel]', item);
    var client = this.fc.client || (document.body.dataset.client || '');
    var fields = { client: client, page_id: this.fc.pageId, subfolder: job.sub || '', batch: this.batch, actor: App.actor || 'admin' };
    if (cancel) cancel.hidden = false;
    var ctl = this.chunk.send({
      endpoint: endpoint, file: file, fields: fields, chunkSize: info.chunk_size, uploadId: job.uploadId || null,
      onInit: function (d) {
        job.uploadId = d.upload_id;
        self.chunk.remember({ id: d.upload_id, kind: 'page', endpoint: endpoint, client: client, name: file.name, size: file.size, type: file.type || '',
                              fields: { page_id: self.fc.pageId, subfolder: job.sub || '' }, label: (job.sub ? job.sub + '/' : '') + file.name });
      },
      onProgress: function (p) { if (fill) fill.style.transform = 'translateX(' + (p.pct - 100) + '%)'; status.textContent = 'Uploading… ' + p.text; },
      onRetry: function (r) { status.textContent = 'Connection hiccup — retrying that piece (' + r.attempt + ' of ' + r.max + ')…'; }
    });
    job.ctl = ctl;
    ctl.promise.then(function (data) {
      job.ctl = null; if (cancel) cancel.hidden = true;
      self.chunk.forget(job.uploadId); job.uploadId = null;
      self.doneJob(job, data);
      self.settle();
    }, function (e) {
      job.ctl = null; if (cancel) cancel.hidden = true;
      if (e && e.aborted) { self.chunk.forget(job.uploadId); job.uploadId = null; self.failJob(job, 'Cancelled'); self.settle(); return; }
      if (!(e && e.retryable) || (e && e.expired)) { self.chunk.forget(job.uploadId); job.uploadId = null; }
      self.failJob(job, ((e && e.error) || 'Upload failed') + (job.uploadId ? ' — reload the page to resume.' : ''));
      self.settle();
    });
  };
  /* ---- resume after a reload (ledger in localStorage; the user re-picks the same files) ---- */
  PageFiles.prototype.offerResume = function () {
    if (!this.chunk || !this.resumeBox) return;
    var self = this, client = this.fc.client || (document.body.dataset.client || '');
    this.pending = this.chunk.list({ kind: 'page', client: client }).filter(function (e) { return e.fields && String(e.fields.page_id) === String(self.fc.pageId); });
    var n = this.pending.length;
    this.resumeBox.hidden = n === 0;
    var t = $('[data-page-resume-text]', this.resumeBox);
    if (t && n) t.textContent = 'Resume ' + n + ' unfinished upload' + (n === 1 ? '' : 's') + ': ' + this.pending.map(function (e) { return e.label + ' (' + mb(e.size) + ')'; }).join(', ') + '. Pick the same file' + (n === 1 ? '' : 's') + ' again and the upload continues where it stopped.';
  };
  PageFiles.prototype.resumeFiles = function (files) {
    var self = this, matched = [], unmatched = [];
    files.forEach(function (f) {
      var e = self.pending.filter(function (p) { return p.name === f.name && Number(p.size) === f.size && matched.indexOf(p) === -1; })[0];
      if (!e) { unmatched.push(f.name); return; }
      matched.push(e);
      self.addOne(f, (e.fields && e.fields.subfolder) || '', e);
    });
    if (unmatched.length) toast(unmatched.length + ' file' + (unmatched.length === 1 ? ' does' : 's do') + ' not match an unfinished upload (same name and size needed): ' + unmatched.join(', '), 'error');
    this.pending = this.pending.filter(function (p) { return matched.indexOf(p) === -1; });
    this.resumeBox.hidden = this.pending.length === 0;
    this.next();
  };
  PageFiles.prototype.discardResume = function () {
    var self = this;
    if (!this.pending.length) return;
    if (!window.confirm('Discard ' + this.pending.length + ' unfinished upload' + (this.pending.length === 1 ? '' : 's') + '? The pieces already sent are deleted from the server.')) return;
    this.pending.forEach(function (e) { self.chunk.abortStored(e); });
    this.offerResume();
  };
  /** Single-request path (small files). */
  PageFiles.prototype.sendSingle = function (job) {
    var self = this, item = job.item, file = job.file;
    var fill = $('[data-upload-fill]', item), status = $('[data-upload-status]', item);
    var fd = new FormData();
    fd.append('client', this.fc.client || (document.body.dataset.client || ''));
    fd.append('page_id', String(this.fc.pageId));
    fd.append('subfolder', job.sub || '');
    fd.append('batch', this.batch);
    fd.append('actor', App.actor || 'admin');
    fd.append('file', file, file.name);
    var xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', function (e) {
      if (!e.lengthComputable) return;
      var pct = Math.round(e.loaded / e.total * 100);
      if (fill) fill.style.transform = 'translateX(' + (pct - 100) + '%)';
      status.textContent = 'Uploading… ' + pct + '%';
    });
    xhr.onload = function () {
      var data = null; try { data = JSON.parse(xhr.responseText); } catch (e) {}
      if (fill) fill.style.transform = 'translateX(0)';
      if (!data || data.ok === false || xhr.status >= 400 || !data.file) self.failJob(job, (data && data.error) || ('Upload failed (' + xhr.status + ')'));
      else self.doneJob(job, data);
      self.settle();
    };
    xhr.onerror = function () { self.failJob(job, 'Network error — try again.'); self.settle(); };
    xhr.open('POST', this.fc.endpoint || 'page-upload.php');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(fd);
  };
  PageFiles.prototype.setCount = function (n) {
    if (this.count) this.count.textContent = n;
    if (this.empty) this.empty.hidden = n > 0;
  };
  PageFiles.prototype.rowFor = function (name) { return this.rows ? $('[data-page-file="' + name.replace(/"/g, '\\"') + '"]', this.rows) : null; };
  PageFiles.prototype.upsertRow = function (name, size, url) {
    if (!this.rows) return;
    var isEntry = name === this.entry, isHtml = /^html?$/.test(fileExt(name));
    var html = '<a class="pg-file-name" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(name) + '</a>'
             + '<span class="pg-file-meta">' + escapeHtml(fmtBytes(size)) + (isEntry ? ' · <span class="pg-file-entry-tag">entry</span>' : '') + '</span>'
             + '<span class="pg-file-actions">' + (!isEntry && isHtml ? '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-page-set-entry="' + escapeHtml(name) + '">Set as entry</button>' : '')
             + '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn" data-page-delete-file="' + escapeHtml(name) + '">Delete</button></span>';
    var li = this.rowFor(name);
    if (!li) { li = document.createElement('li'); li.setAttribute('data-page-file', name); li.classList.add('ui-enter'); this.rows.appendChild(li); }
    li.className = 'pg-file' + (isEntry ? ' pg-file--entry' : '');
    li.innerHTML = html;
    if (isEntry) this.markEntry(name);
  };
  PageFiles.prototype.markEntry = function (name) {
    var self = this;
    this.entry = name;
    var entryInput = $('[data-page-entry-input]'); if (entryInput) entryInput.value = name;
    var entryCode = $('[data-page-entry]'); if (entryCode) entryCode.textContent = name;
    $$('[data-page-file]', this.rows).forEach(function (li) {
      var n = li.getAttribute('data-page-file'), on = n === name;
      li.classList.toggle('pg-file--entry', on);
      var meta = $('.pg-file-meta', li);
      if (meta) { meta.innerHTML = meta.innerHTML.replace(/ · <span class="pg-file-entry-tag">entry<\/span>/, '') + (on ? ' · <span class="pg-file-entry-tag">entry</span>' : ''); }
      var btn = $('[data-page-set-entry]', li);
      if (on && btn) btn.remove();
      else if (!on && !btn && /^html?$/.test(fileExt(n))) {
        var acts = $('.pg-file-actions', li);
        if (acts) acts.insertAdjacentHTML('afterbegin', '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-page-set-entry="' + escapeHtml(n) + '">Set as entry</button>');
      }
    });
  };
  PageFiles.prototype.remove = function (name, btn) {
    var self = this;
    if (!window.confirm('Delete ' + name + ' from this page?')) return;
    if (btn) btn.disabled = true;
    App.post(this.fc.endpoint || 'page-upload.php', { action: 'delete_file', page_id: this.fc.pageId, name: name, client: this.fc.client }).then(function (res) {
      if (!res.ok) { if (btn) btn.disabled = false; toast(res.error || 'Could not delete', 'error'); return; }
      var li = self.rowFor(name); if (li) li.remove();
      if (res.data && res.data.page) self.setCount(res.data.page.file_count);
      toast('Deleted ' + name, 'success');
    });
  };
  PageFiles.prototype.setEntry = function (name, btn) {
    var self = this;
    if (btn) btn.disabled = true;
    App.post(this.fc.endpoint || 'page-upload.php', { action: 'set_entry', page_id: this.fc.pageId, name: name, client: this.fc.client }).then(function (res) {
      if (btn) btn.disabled = false;
      if (!res.ok) { toast(res.error || 'Could not set the entry file', 'error'); return; }
      self.markEntry(name);
      toast(name + ' is now the entry file', 'success');
    });
  };
  App.pageFiles = { create: function (root, fc) { return new PageFiles(root, fc); } };

  function init() {
    if ($('[data-pages-list]') || cfg.openPage) initList();
    var form = $('[data-page-form]'); if (form) App.pageForm.init(form);
    var filesRoot = $('[data-page-files]');
    if (filesRoot && window.PageFilesConfig) App.pageFiles.instance = App.pageFiles.create(filesRoot, window.PageFilesConfig);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})(window, document);
