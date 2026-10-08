/* =====================================================================
   Joust client portal — posts.js  (Posts phase, spec §4.3)
   Extends the global App from app.js; never edits it.

   App.swipe.attach(root, opts)   iOS-Mail style swipe actions on [data-swipe] rows
       opts: { card: '.pl-card', threshold: 0.35 (fraction of width, min 80px),
               canSwipe(item, dir) → bool, onCommit(item, dir), commitOut: dir|false }
   App.swipe.reset(item)          snap a row back
   App.posts.open(id, {deny})     open the post detail sheet (inline template or partial fetch)
   App.posts.close()
   App.posts.decide(id, status, note, {toast})   optimistic approve / deny (+ required note) / reset
   App.posts.resubmit(id)         admin work queue: denied → pending ("Send for review", status.php status=pending), row leaves the queue
   App.posts.refresh(id)          after a save elsewhere (the New post pop-up: create / edit / draft / send for review): fetch the
                                  row as the server renders it now (posts.php &partial=row) and swap it in, insert it, or let it
                                  leave this segment; the segment counts follow. → Promise {segment, month, inView}
   App.posts.submit(id)           admin: draft → pending ("Send for review", status.php action=submit; 422 → "Add a caption first"
                                  and the caption editor opens when the sheet shows that post)
   App.posts.comment(id, text)
   App.posts.togglePosted(id, to) (admin)   App.posts.remove(id) (admin)
   App.posts.videoFallback(root)  swaps a non-playable <video> for the "Open / Download" card
   App.posts.approveForClient(id) admin ⋯ "Approve for client…": asks first, then approves on the client's behalf
   Events: 'posts:decided' {id, status, ok}, 'posts:submitted' {id, ok}, 'posts:open' {id}, 'posts:close' {id},
           'posts:refreshed' {id, segment, inView}
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var cfg = window.PostsConfig || {};
  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  var ENDPOINT = cfg.endpoint || (window.App && App.urls ? App.urls.abs('status.php') : 'status.php');
  var DESKTOP  = window.matchMedia ? window.matchMedia('(min-width: 1024px)') : { matches: false };
  var LABELS   = { draft: 'Draft', pending: 'To Review', approved: 'Approved', denied: 'Needs changes', scheduled: 'Scheduled' };

  function toast(msg, kind) { if (App.toast) App.toast(msg, { kind: kind }); }
  function segmentOf(status, posted) { return posted ? 'scheduled' : status; }
  function fmtDay(d) { return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); }
  function fmtWhen(d) {
    return d.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })
         + ' · ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function linkTags(text) {
    return escapeHtml(text).replace(/(^|[\s(])(#[\p{L}\p{N}_]+)/gu, '$1<span class="ig-tag">$2</span>').replace(/\n/g, '<br>');
  }

  /* ================================================================== */
  /* App.swipe — transform-only, velocity-aware, rubber-banded            */
  /* ================================================================== */
  App.swipe = {
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
        if (e.pointerType === 'mouse' || e.button !== 0) return;    // touch / pen only (spec: swipe on touch)
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
        state.v = (e.clientX - state.lastX) / dt;            // px per ms
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
  /* App.posts — detail sheet + actions                                   */
  /* ================================================================== */
  var P = App.posts = {
    current: null,        // { id, item, root }
    _pushed: false,
    counts: cfg.counts || {},
    segment: cfg.segment || 'pending'
  };

  function sheetRoot() { return $('#uiSheet'); }
  function itemEl(id) { return $('[data-post-item="' + id + '"]'); }
  function pd(root) { return $('.pd[data-post-detail]', root || sheetRoot()); }

  /* ---- counts ------------------------------------------------------- */
  function bumpCount(seg, delta) {
    if (!seg) return;
    P.counts[seg] = Math.max(0, (P.counts[seg] || 0) + delta);
    var n = P.counts[seg];
    var item = $('.ui-segmented-item[data-segment="' + seg + '"] .ui-segmented-count');
    if (item) item.textContent = n;
    if (seg === P.segment) { var hdr = $('[data-segment-count]'); if (hdr) hdr.textContent = n; }
    // The tab badge counts the viewer's own queue (client: To Review · admin: Needs changes) over every month,
    // so it moves by delta rather than copying the (month-scoped) segment count.
    if (seg === App.queueStatus() && App.bumpTabBadge) App.bumpTabBadge('posts', delta);
  }
  function maybeEmpty() {
    var list = $('[data-posts-items]');
    if (!list || list.children.length) return;
    var group = $('[data-posts-list]'); if (!group) return;
    group.hidden = true;
    var empty = $('[data-posts-empty]');
    if (!empty) {
      empty = document.createElement('div');
      empty.className = 'ui-empty posts-empty ui-enter';
      empty.setAttribute('data-posts-empty', '');
      empty.textContent = P.segment === 'pending' ? 'All caught up — nothing left to review.'
                        : (P.segment === 'denied' ? 'Nothing needs changes — the queue is clear.'
                        : (P.segment === 'draft' ? 'No drafts left.' : 'Nothing here.'));
      group.parentNode.insertBefore(empty, group);
    }
    empty.hidden = false;
  }

  /* ---- detail loading ----------------------------------------------- */
  function detailHtml(id) {
    var tpl = $('template[data-post-template="' + id + '"]');
    if (tpl) return Promise.resolve(tpl.innerHTML);
    if (!cfg.partialUrl) return Promise.reject(new Error('No detail available'));
    return fetch(cfg.partialUrl.replace('__ID__', encodeURIComponent(id)), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) { if (!res.ok) throw new Error(res.status === 404 ? 'This post is no longer available' : 'Could not load this post'); return res.text(); });
  }

  function splitDetail(html) {
    var box = document.createElement('div');
    box.innerHTML = html;
    var art = $('.pd[data-post-detail]', box);
    if (!art) return { body: html, footer: '' };
    var body = $('[data-pd-body]', art), footer = $('[data-pd-footer]', art);
    var shell = art.cloneNode(false);
    shell.innerHTML = body ? body.outerHTML : '';
    return { body: shell.outerHTML, footer: footer ? footer.outerHTML : '', title: art.getAttribute('data-title') || '' };
  }

  P.open = function (id, opts) {
    opts = opts || {};
    id = String(id);
    var item = itemEl(id);
    var title = item ? (item.getAttribute('data-title') || 'Post') : 'Post';
    return detailHtml(id).then(function (html) {
      var parts = splitDetail(html);
      if (!item && parts.title) title = parts.title;   // a post outside the list (e.g. the client's own Needs-changes link)
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
      P.videoFallback(root);
      initCarousel(root);
      syncSlidePick(root);
      autosize($('[data-comment-input]', root));
      syncState(root);
      if (opts.deny) openDeny(root);
      if (!opts.silent) pushPost(id);
      document.dispatchEvent(new CustomEvent('posts:open', { detail: { id: id } }));
      return root;
    }).catch(function (err) {
      toast(err && err.message ? err.message : 'Could not open this post', 'error');
      return null;
    });
  };

  P.close = function () { if (App.sheet.current === sheetRoot()) App.sheet.close(); };

  /* ---- paging ("Load more") ----------------------------------------- */
  function rowCount() { return $$('[data-posts-items] > [data-post-item]').length; }
  function syncMore(serverTotal) {
    var wrap = $('[data-posts-more-wrap]'), btn = $('[data-posts-more]'), count = $('[data-posts-more-count]');
    if (!wrap) return;
    var total = serverTotal != null ? serverTotal : (P.counts[P.segment] != null ? P.counts[P.segment] : (parseInt(btn && btn.getAttribute('data-total'), 10) || 0));
    var left = Math.max(0, total - rowCount());
    if (count) count.textContent = left + ' remaining';
    wrap.hidden = left <= 0;
  }
  P.loadMore = function () {
    var btn = $('[data-posts-more]'), list = $('[data-posts-items]');
    if (!btn || !list || P._loading || !cfg.listUrl) return Promise.resolve([]);
    P._loading = true; btn.disabled = true; btn.setAttribute('aria-busy', 'true');
    return fetch(cfg.listUrl.replace('__OFFSET__', String(rowCount())), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) {
        if (!res.ok) throw new Error('Could not load more posts');
        var total = res.headers.get('X-Posts-Total');
        return res.text().then(function (html) { return { html: html, total: total !== null && total !== '' ? parseInt(total, 10) : null }; });
      })
      .then(function (r) {
        var tpl = document.createElement('template'); tpl.innerHTML = r.html.trim();
        var added = [];
        $$('[data-post-item]', tpl.content).forEach(function (li) {
          if (itemEl(li.getAttribute('data-post-item'))) return;   // already shown (e.g. restored after a failed decision)
          li.classList.add('ui-enter'); list.appendChild(li); added.push(li);
        });
        if (App.video && App.video.enhance) App.video.enhance(list);
        if (!added.length) { var w = $('[data-posts-more-wrap]'); if (w) w.hidden = true; }
        else syncMore(r.total);
        return added;
      })
      .catch(function (err) { toast(err.message || 'Could not load more posts', 'error'); return []; })
      .then(function (added) { P._loading = false; btn.disabled = false; btn.removeAttribute('aria-busy'); return added; });
  };

  /* history: ?post=ID ⇄ sheet */
  function urlWithPost(id) {
    // App.urls: /portal/<client>/posts/ID with clean links (the id lives in the path), ?post=ID otherwise.
    if (App.urls) return App.urls.withParams({ post: id || null });
    var u = new URL(window.location.href);
    if (id) u.searchParams.set('post', id); else u.searchParams.delete('post');
    return u.pathname + u.search + u.hash;
  }
  function pushPost(id) {
    try {
      if (P._pushed) history.replaceState({ post: id }, '', urlWithPost(id));
      else { history.pushState({ post: id }, '', urlWithPost(id)); P._pushed = true; }
    } catch (e) {}
  }
  window.addEventListener('popstate', function (e) {
    var id = e.state && e.state.post;
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
    document.dispatchEvent(new CustomEvent('posts:close', { detail: { id: cur ? cur.id : null } }));
  });

  /* ---- state sync (which footer rows show) ------------------------- */
  function syncState(root) {
    var art = pd(root); if (!art) return;
    var status = art.getAttribute('data-status') || 'pending';
    var posted = art.getAttribute('data-posted') === '1';
    var show = {
      'decide':          status === 'pending' && !posted,
      'approved':        status === 'approved' && !posted,
      'scheduled':       posted,
      'denied':          status === 'denied' && !posted,
      'note':            status === 'denied' && !posted,
      'admin-waiting':   status === 'pending' && !posted,
      'admin-pending':   status === 'pending' && !posted,
      'admin-approved':  status === 'approved' && !posted,
      'admin-denied':    status === 'denied' && !posted,
      'draft':           status === 'draft',
      'admin-draft':     status === 'draft',
      'admin-scheduled': posted,
      // ⋯ "For the client" (admin): the client's decisions, on purpose only
      'menu-approve':    !posted && (status === 'pending' || status === 'denied'),
      'menu-deny':       !posted && (status === 'pending' || status === 'approved'),
      'menu-resubmit':   !posted && status === 'denied',
      'menu-decide':     !posted && status !== 'draft'
    };
    $$('[data-state]', root).forEach(function (el) {
      var k = el.getAttribute('data-state');
      if (k in show) el.hidden = !show[k];
    });
    var label = $('.pd-when-label', root);
    // Caption / hashtags are frozen once Scheduled (status.php → 409): hide the Edit button and fold an open editor.
    var capBtn = $('[data-caption-edit]', root); if (capBtn) capBtn.hidden = posted;
    if (posted) { var capForm = $('[data-edit-form="caption"]', root); if (capForm) capForm.hidden = true; }
    // "date has passed" note: only for Scheduled posts whose date is before today (data-past from PHP)
    var pastNote = $('[data-when-past]', root);
    if (pastNote) pastNote.hidden = !(posted && art.getAttribute('data-past') === '1');
  }
  /* Local-date ISO (YYYY-MM-DD) helpers for re-evaluating data-past after an admin date edit. */
  function isoDay(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function applyStatus(id, status, posted) {
    var art = pd(); var item = itemEl(id);
    if (art && art.getAttribute('data-id') !== String(id)) art = null;
    [art, item].forEach(function (el) {
      if (!el) return;
      if (status !== null) el.setAttribute('data-status', status);
      if (posted !== null) el.setAttribute('data-posted', posted ? '1' : '0');
      var pill = $('.ui-pill[data-status-pill]', el);
      if (pill && App.status) App.status.applyPill(pill, el.getAttribute('data-status'), el.getAttribute('data-posted') === '1');
    });
    if (art) syncState(art.closest('.ui-sheet-root') || document);
  }

  function snapshot(id) {
    var art = pd(); var item = itemEl(id);
    var src = (art && art.getAttribute('data-id') === String(id)) ? art : item;
    if (!src) return null;
    return { status: src.getAttribute('data-status'), posted: src.getAttribute('data-posted') === '1' };
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
      var group = $('[data-posts-list]'); if (group) group.hidden = false;
      var empty = $('[data-posts-empty]'); if (empty) empty.hidden = true;
    }
    item.classList.remove('ui-leave', 'is-busy', 'is-settling', 'is-swiping');
    item.removeAttribute('data-swipe-dir');
    var card = $('.pl-card', item); if (card) card.style.transform = '';
  }

  /* ---- decide: approve / deny(+note) / reset ------------------------ */
  P.decide = function (id, status, note, opts) {
    id = String(id);
    opts = opts || {};
    var before = snapshot(id);
    if (!before) return Promise.resolve(null);
    var fromSeg = segmentOf(before.status, before.posted);
    var toSeg   = segmentOf(status, before.posted);
    var params  = { id: id, status: status, actor: App.actor };
    if (note) params.comment = note;

    // optimistic
    applyStatus(id, status, null);
    bumpCount(fromSeg, -1); bumpCount(toSeg, +1);
    var item = itemEl(id); if (item) item.classList.add('is-busy');
    var moved = false;
    if (toSeg !== fromSeg) {
      // clients never see denied work: the card fades out and the list reflows
      leaveList(id, null); moved = true;
    }

    return App.post(ENDPOINT, params).then(function (res) {
      if (!res.ok) {
        applyStatus(id, before.status, before.posted);
        bumpCount(toSeg, -1); bumpCount(fromSeg, +1);
        restoreList(id);
        toast(res.error || 'Could not save' + (moved ? ' — back in ' + (LABELS[fromSeg] || fromSeg) : ''), 'error');
      } else {
        delete removed[id];
        if (item) item.classList.remove('is-busy');
        var art = pd();
        if (art && art.getAttribute('data-id') === id) {
          if (status === 'approved') {
            var line = $('[data-approved-line]', art.closest('.ui-sheet-root') || document);
            if (line) line.textContent = 'Approved ' + fmtDay(new Date()) + ' · Joust will schedule this';
          }
          if (note) appendComment(art, note, App.actor, false, res.data && res.data.comment_id);
          if (note && status === 'denied') fillNote(art, note, App.actor);
          var form = $('[data-deny-form]', sheetRoot()); if (form) { form.hidden = true; var ta = $('[data-deny-note]', form); if (ta) ta.value = ''; }
        }
        if (opts.toast) toast(opts.toast, 'success');
        else if (status === 'approved') toast('Approved', 'success');
        else if (status === 'denied') toast(App.role === 'admin' ? 'Marked as needs changes' : 'Sent to Joust', 'success');
        else toast('Sent for review — back in To Review', 'success');
        if (status === 'denied' && App.role !== 'admin' && P.current && P.current.id === id) setTimeout(P.close, 700);
      }
      document.dispatchEvent(new CustomEvent('posts:decided', { detail: { id: id, status: status, ok: res.ok } }));
      return res;
    });
  };

  /* ---- work queue (admin): denied → pending -------------------------- */
  P.resubmit = function (id) {
    id = String(id);
    var item = itemEl(id);
    var btns = item ? $$('[data-resubmit]', item) : [];
    btns.forEach(function (b) { b.disabled = true; });
    if (P.current && P.current.id === id) P.close();
    return P.decide(id, 'pending', null, { toast: 'Sent for review — back in To Review' }).then(function (res) {
      if (!res || !res.ok) btns.forEach(function (b) { b.disabled = false; });
      return res;
    });
  };

  /* ---- drafts (admin): draft → pending ------------------------------ */
  P.submit = function (id) {
    id = String(id);
    var item = itemEl(id);
    var btns = $$('[data-submit-post="' + id + '"]');
    btns.forEach(function (b) { b.disabled = true; });
    if (item) item.classList.add('is-busy');
    return App.post(ENDPOINT, { action: 'submit', id: id, actor: App.actor }).then(function (res) {
      btns.forEach(function (b) { b.disabled = false; });
      if (item) item.classList.remove('is-busy');
      if (!res.ok) {
        toast(res.error || 'Could not send', 'error');
        // 422 "Add a caption first": open this post's sheet (if needed) with the caption editor showing
        if (res.data && res.data.field === 'caption') {
          var showEditor = function () {
            var root = sheetRoot(), art = pd(root);
            if (!art || art.getAttribute('data-id') !== id) return;
            var form = $('[data-edit-form="caption"]', root);
            if (form) { form.hidden = false; $$('textarea', form).forEach(syncCount); var ta = $('textarea', form); if (ta) ta.focus(); }
          };
          var open = pd();
          if (open && open.getAttribute('data-id') === id) showEditor();
          else P.open(id).then(showEditor);
        }
      } else {
        applyStatus(id, 'pending', false);
        bumpCount('draft', -1); bumpCount('pending', +1);
        if (P.segment === 'draft') {
          if (P.current && P.current.id === id) P.close();
          leaveList(id);
        }
        toast('Sent for review — the client can see it now', 'success');
      }
      document.dispatchEvent(new CustomEvent('posts:submitted', { detail: { id: id, ok: !!res.ok } }));
      return res;
    });
  };

  /* ---- admin: the client's Approve, on their behalf (⋯ → Approve for client…) ---- */
  P.approveForClient = function (id) {
    id = String(id);
    var who = cfg.clientName || 'the client';
    var root = sheetRoot(), anchor = root && $('[data-deny-form]', root);
    return App.confirmInline(anchor, approveCopy('post', who)).then(function (ok) {
      return ok ? P.decide(id, 'approved', null, { toast: 'Approved for ' + who }) : null;
    });
  };
  /** The in-sheet confirm for ⋯ → Approve for client… (the client's decision, made on their behalf). */
  function approveCopy(noun, who) {
    return { name: 'approve', kind: 'approve', title: 'Approve this ' + noun + ' for ' + who + '?',
             text: 'Only do this when ' + who + ' has approved it outside the portal — they won’t be asked.',
             ok: 'Approve for ' + who };
  }

  /* ---- refresh one row after a save elsewhere (New post pop-up) ------- */
  function rowDate(li) { var t = li && $('.pl-date', li); return (t && t.getAttribute('datetime')) || ''; }
  function insertRow(li) {
    var list = $('[data-posts-items]'); if (!list) return false;
    var rows = $$('[data-posts-items] > [data-post-item]');
    var at = null;
    if (!cfg.queue) {   // the queue is newest client activity first → top; every other segment: date, then id
      var d = rowDate(li), id = parseInt(li.getAttribute('data-id'), 10) || 0;
      for (var i = 0; i < rows.length; i++) {
        var rd = rowDate(rows[i]), rid = parseInt(rows[i].getAttribute('data-id'), 10) || 0;
        if (rd > d || (rd === d && rid > id)) { at = rows[i]; break; }
      }
      var more = $('[data-posts-more-wrap]');
      if (!at && more && !more.hidden) return false;   // it sorts after rows not loaded yet — "Load more" brings it
    } else at = rows[0] || null;
    list.insertBefore(li, at);
    var group = $('[data-posts-list]'); if (group) group.hidden = false;
    var empty = $('[data-posts-empty]'); if (empty) empty.hidden = true;
    return true;
  }
  P.refresh = function (id, opts) {
    opts = opts || {};
    id = String(id);
    var old = itemEl(id);
    if (old) { var stale = $('template[data-post-template]', old); if (stale) stale.remove(); }   // never reopen the pre-save detail
    var loose = $('template[data-post-template="' + id + '"]'); if (loose) loose.remove();
    var fromSeg = old ? segmentOf(old.getAttribute('data-status'), old.getAttribute('data-posted') === '1') : null;
    if (!cfg.rowUrl) return Promise.reject(new Error('No row URL'));
    return fetch(cfg.rowUrl.replace('__ID__', encodeURIComponent(id)), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) {
        if (!res.ok) throw new Error('Could not refresh the list');
        var seg = res.headers.get('X-Post-Segment') || '', month = res.headers.get('X-Post-Month') || '';
        return res.text().then(function (html) { return { html: html, segment: seg, month: month }; });
      })
      .then(function (r) {
        var inMonth = !cfg.month || r.month === cfg.month;
        var inView = inMonth && r.segment === P.segment;
        if (fromSeg) bumpCount(fromSeg, -1);          // a row in the list is always counted in this view
        if (inMonth) bumpCount(r.segment, +1);
        var tpl = document.createElement('template'); tpl.innerHTML = r.html.trim();
        var li = $('[data-post-item]', tpl.content);
        if (old && document.contains(old)) {
          if (inView && li) {
            if (old.classList.contains('is-open')) li.classList.add('is-open');
            old.parentNode.replaceChild(li, old);
          } else { leaveList(id); delete removed[id]; li = null; }
        } else if (inView && li) {
          li.classList.add('ui-enter');
          if (!insertRow(li)) li = null;
        } else li = null;
        if (li) {
          if (App.video && App.video.enhance) App.video.enhance(li);
          if (P.current && P.current.id === id) { li.classList.add('is-open'); P.current.item = li; }
        }
        syncMore();
        var out = { id: id, segment: r.segment, month: r.month, inView: inView, label: LABELS[r.segment] || r.segment };
        document.dispatchEvent(new CustomEvent('posts:refreshed', { detail: out }));
        return out;
      });
  };
  P.label = function (seg) { return LABELS[seg] || seg; };

  /* "Kenda Tires asked for changes · just now" + the note, after an in-place Needs changes (admin ⋯). */
  function fillNote(art, text, actor) {
    var root = art.closest('.ui-sheet-root') || document;
    var box = $('[data-pd-note]', root); if (!box) return;
    var m = /^\[Slide (\d{1,2})\]\s*/.exec(text), slide = m ? parseInt(m[1], 10) : 0;
    if (m) text = text.slice(m[0].length);
    var who = actor === 'client' ? (cfg.clientName || 'The client') : 'Joust';
    var w = $('[data-pd-note-who]', box); if (w) w.textContent = who + ' asked for changes';
    var when = $('[data-pd-note-when]', box); if (when) when.textContent = ' · just now';
    var p = $('[data-pd-note-text]', box);
    if (p) p.innerHTML = (slide ? '<span class="pd-note-slide">On slide ' + slide + ':</span> ' : '') + escapeHtml(text);
  }

  P.togglePosted = function (id, to) {
    id = String(id);
    var before = snapshot(id); if (!before) return Promise.resolve(null);
    var posted = to === 1 || to === '1' || to === true;
    var fromSeg = segmentOf(before.status, before.posted), toSeg = segmentOf(before.status, posted);
    applyStatus(id, null, posted);
    bumpCount(fromSeg, -1); bumpCount(toSeg, +1);
    var moved = false;
    if (toSeg !== fromSeg) { leaveList(id); moved = true; }
    return App.post(ENDPOINT, { action: 'toggle_posted', id: id, to: posted ? '1' : '0', actor: App.actor }).then(function (res) {
      if (!res.ok) {
        applyStatus(id, null, before.posted);
        bumpCount(toSeg, -1); bumpCount(fromSeg, +1);
        restoreList(id);
        toast(res.error || 'Could not update', 'error');
      } else {
        delete removed[id];
        toast(posted ? 'Marked as scheduled' : 'Unmarked', 'success');
      }
      return res;
    });
  };

  P.remove = function (id) {
    id = String(id);
    if (!window.confirm('Delete this post and all its media? This cannot be undone.')) return Promise.resolve(null);
    return App.post(ENDPOINT, { action: 'delete_post', id: id, actor: App.actor }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Delete failed', 'error'); return res; }
      var before = snapshot(id);
      if (before) bumpCount(segmentOf(before.status, before.posted), -1);
      if (P.current && P.current.id === id) P.close();
      leaveList(id);
      toast('Post deleted', 'success');
      return res;
    });
  };

  /* ---- comments ----------------------------------------------------- */
  /* "[Slide 3] text" → the chip (the slide's sm thumb + "Slide 3") the server renders (commentSlideChip()). */
  function slideChipHtml(root, n) {
    var fig = $('[data-carousel] [data-slide="' + (n - 1) + '"]', root);
    var thumb = fig ? (fig.getAttribute('data-thumb') || '') : '';
    return '<button type="button" class="pd-slide-chip" data-goto-slide="' + (n - 1) + '" aria-label="Show slide ' + n + '">'
         + (thumb ? '<img src="' + escapeHtml(thumb) + '" alt="" decoding="async">' : '<span class="pd-slide-chip-blank" aria-hidden="true"></span>')
         + '<span>Slide ' + n + '</span></button>';
  }
  function appendComment(art, text, actor, internal, commentId) {
    var rawText = text;
    var root = art.closest('.ui-sheet-root') || document;
    var thread = $('[data-thread]', root); if (!thread) return;
    var bw = App.bubbleWho ? App.bubbleWho(actor) : { side: 'mine', who: 'You' };   // drawn from the viewer's seat
    var side = bw.side, who = escapeHtml(bw.who);
    var msg = document.createElement('div');
    msg.className = 'pd-msg pd-msg--' + side + ' ui-enter';
    msg.setAttribute('data-actor', actor);
    if (internal) msg.setAttribute('data-internal', '1');
    var chip = '', m = /^\[Slide (\d{1,2})\]\s*/.exec(text);
    if (m) { chip = slideChipHtml(root, parseInt(m[1], 10)); text = text.slice(m[0].length); }
    msg.innerHTML = '<div class="ui-bubble ui-bubble--' + side + (internal ? ' ui-bubble--internal' : '') + '">' + chip + escapeHtml(text).replace(/\n/g, '<br>') + '</div>'
                  + '<div class="ui-bubble-meta">' + (App.actorAvatar ? App.actorAvatar(actor) : '') + who + (internal ? ' <span class="ui-pill ui-pill--nodot ui-pill--internal" data-internal-pill>Internal</span>' : '') + ' · just now</div>';
    var empty = $('[data-thread-empty]', thread); if (empty) empty.hidden = true;
    thread.appendChild(msg);
    var n = (parseInt(thread.getAttribute('data-count') || '0', 10) || 0) + 1;
    thread.setAttribute('data-count', n);
    var c = $('[data-comment-count]', root); if (c) c.textContent = n;
    var lc = $('[data-comment-count-for="' + art.getAttribute('data-id') + '"]'); if (lc) { lc.textContent = n + (n === 1 ? ' comment' : ' comments'); if (lc.parentNode && lc.parentNode.hidden) lc.parentNode.hidden = false; }
    var body = $('[data-sheet-body]', root); if (body) body.scrollTop = body.scrollHeight;
    if (commentId && App.comments) App.comments.adopt(msg, commentId, rawText);   // editable in place (comment-edit.php)
    return msg;
  }

  P.comment = function (id, text, internal) {
    text = (text || '').trim();
    if (!text) return Promise.resolve(null);
    var body = { id: id, comment: text, actor: App.actor };
    if (internal) body.internal = 1;   // admin: an internal note (Joust only)
    return App.post(ENDPOINT, body).then(function (res) {
      if (!res.ok) { toast(res.error || 'Could not send', 'error'); return res; }
      var art = pd(); if (art && art.getAttribute('data-id') === String(id)) appendComment(art, text, App.actor, !!internal, res.data && res.data.comment_id);
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

  /* ---- carousel ----------------------------------------------------- */
  /* App.carousel (carousel.js) when loaded: swipe, dots, "2 / 7", arrows, ←/→, only the visible video plays.
     The fallback below is the pre-carousel.js behaviour (dots + counter). */
  /* The comment "Slide" picker: "All slides" on open (a general comment is never silently tagged "Slide 1"), then —
     once the user moves the carousel off the cover (swipe / arrow / dot / slide chip → carousel:change) — it follows
     the slide on screen, back to slide 1 included. "All slides" stays one tap away in the picker. */
  function syncSlidePick(root, index) {
    var pick = $('[data-comment-slide]', root); if (!pick) return;
    var moved = index != null;
    if (index == null) { var car = $('[data-carousel]', root); index = car && App.carousel && App.carousel.index ? App.carousel.index(car) : 0; }
    index = parseInt(index, 10) || 0;
    // carousel:change also fires for slide 1 on init / resize: only a move off the cover starts the following
    if (moved && index > 0) pick.__follow = true;
    if (!pick.__follow) { pick.value = ''; return; }
    var v = String(index + 1);
    if ($('option[value="' + v + '"]', pick)) pick.value = v;
  }
  function initCarousel(root) {
    if (App.carousel && App.carousel.init) { App.carousel.init(root); return; }
    $$('[data-carousel]', root).forEach(function (car) {
      var track = $('[data-carousel-track]', car); if (!track || track.__init) return;
      track.__init = true;
      var n = parseInt(car.getAttribute('data-count') || '1', 10);
      var dots = $$('[data-carousel-dot]', car), counter = $('[data-carousel-counter]', car);
      var ticking = false;
      function update() {
        ticking = false;
        var i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
        i = Math.max(0, Math.min(n - 1, i));
        car.setAttribute('data-index', i);
        dots.forEach(function (d, k) { d.classList.toggle('is-active', k === i); d.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
        if (counter) counter.textContent = (i + 1) + ' / ' + n;
        // pause videos that scrolled away
        $$('video', car).forEach(function (v, k) { if (k !== i && !v.paused) v.pause(); });
      }
      track.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
      dots.forEach(function (d, k) {
        d.addEventListener('click', function () {
          track.scrollTo({ left: k * track.clientWidth, behavior: App.reducedMotion && App.reducedMotion() ? 'auto' : 'smooth' });
        });
      });
      update();
    });
  }

  /* ---- video (spec §6) — App.video owns fallback / posters / unmute --- */
  P.videoFallback = function (root) {
    if (App.video) App.video.enhance(root || document);
  };

  /* ---- viewer (tap → full screen) ----------------------------------- */
  function openViewer(src, alt, original) {
    var wrap = document.createElement('div');
    wrap.className = 'pd-viewer';
    wrap.setAttribute('role', 'dialog'); wrap.setAttribute('aria-modal', 'true'); wrap.setAttribute('aria-label', 'Full screen image');
    wrap.innerHTML = '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(alt || '') + '" decoding="async">'
                   + (original && original !== src ? '<a class="pd-viewer-original" href="' + escapeHtml(original) + '" target="_blank" rel="noopener">View original</a>' : '')
                   + '<button type="button" class="pd-viewer-close" aria-label="Close"><svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>';
    document.body.appendChild(wrap);
    requestAnimationFrame(function () { wrap.classList.add('is-visible'); });
    var close = function () {
      wrap.classList.remove('is-visible');
      setTimeout(function () { if (wrap.parentNode) wrap.parentNode.removeChild(wrap); }, 240);
      document.removeEventListener('keydown', onKey);
    };
    var onKey = function (e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
    wrap.addEventListener('click', function (e) { if (!e.target.closest('.pd-viewer-original')) close(); });
    document.addEventListener('keydown', onKey, true);
    $('.pd-viewer-close', wrap).focus();
  }

  /* ---- copy caption --------------------------------------------------- */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
    }
    return Promise.resolve(legacyCopy(text));
  }
  function legacyCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
    document.body.appendChild(ta); ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    return ok;
  }

  /* ---- caption / hashtags (either seat until Scheduled) + admin date ---- */
  function saveCaption(art, form) {
    var id = art.getAttribute('data-id');
    var caption = form.caption.value, hashtags = form.hashtags.value;
    if (!caption.trim()) { toast('Caption cannot be empty', 'error'); return; }
    var btn = $('[type="submit"]', form); if (btn) btn.disabled = true;
    App.post(ENDPOINT, { id: id, caption: caption, hashtags: hashtags, actor: App.actor }).then(function (res) {
      if (btn) btn.disabled = false;
      if (!res.ok) { toast(res.error || 'Could not save', 'error'); return; }
      var root = art.closest('.ui-sheet-root') || document;
      var cap = $('[data-caption-display]', root), tags = $('[data-hashtags-display]', root), copy = $('[data-copy-caption]', root);
      var name = cap && $('.ig-name--inline', cap) ? $('.ig-name--inline', cap).outerHTML + ' ' : '';
      if (cap) { cap.setAttribute('data-raw', caption); cap.innerHTML = name + linkTags(caption); }
      if (tags) { tags.setAttribute('data-raw', hashtags); tags.innerHTML = linkTags(hashtags); tags.hidden = !hashtags.trim(); }
      if (copy) copy.setAttribute('data-text', (caption + (hashtags.trim() ? '\n\n' + hashtags.trim() : '')).trim());
      var item = itemEl(id), first = caption.split(/\r?\n/)[0].trim();
      if (item) { var c = $('.pl-caption', item); if (c && item.getAttribute('data-title') !== first) c.textContent = first || hashtags; }
      // "Edited by Kenda · just now" — status.php names the client when the client seat changed copy;
      // an admin edit hides the line (it only ever credits the client).
      var eb = $('[data-edited-by]', root), editedBy = res.data && res.data.edited_by;
      if (eb) {
        if (editedBy) { eb.textContent = 'Edited by ' + editedBy + ' · just now'; eb.hidden = false; }
        else if (App.actor !== 'client') { eb.textContent = ''; eb.hidden = true; }
      }
      form.hidden = true;
      toast('Caption saved', 'success');
    });
  }
  /* Character counter under each editor textarea ("123 / 10000"). */
  function syncCount(ta) {
    if (!ta || !ta.id) return;
    var form = ta.closest('form'); if (!form) return;
    var out = $('[data-count-for="' + ta.id + '"]', form); if (!out) return;
    var max = parseInt(ta.getAttribute('maxlength'), 10) || 0, n = ta.value.length;
    out.textContent = n + (max ? ' / ' + max : '');
    if (max) out.classList.toggle('is-limit', n >= max);
  }
  function saveDate(art, form) {
    var id = art.getAttribute('data-id');
    var v = form.scheduled_date.value;
    if (!v) return;
    var btn = $('[type="submit"]', form); if (btn) btn.disabled = true;
    App.post(ENDPOINT, { id: id, scheduled_date: v.replace('T', ' ') + ':00', actor: App.actor }).then(function (res) {
      if (btn) btn.disabled = false;
      if (!res.ok) { toast(res.error || 'Could not save', 'error'); return; }
      var d = new Date(v);
      var root = art.closest('.ui-sheet-root') || document;
      var disp = $('[data-when-display]', root); if (disp) { disp.textContent = fmtWhen(d); disp.setAttribute('data-iso', v); }
      var item = itemEl(id); if (item) { var t = $('.pl-date', item); if (t) { t.textContent = fmtDay(d); t.setAttribute('datetime', v); } }
      // Re-evaluate the past flag (date-only, local) so the note follows the new date without a reload;
      // the list row's fade is server-rendered and refreshes on the next page load.
      art.setAttribute('data-past', v.slice(0, 10) < isoDay(new Date()) ? '1' : '0');
      syncState(root);
      form.hidden = true;
      var row = $('[data-when-toggle]', root); if (row) row.setAttribute('aria-expanded', 'false');
      toast('Date saved', 'success');
    });
  }
  function requestDate(art, form) {
    var id = art.getAttribute('data-id');
    var v = form.date.value; if (!v) return;
    var d = new Date(v + 'T12:00:00');
    var nice = d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    var btn = $('[type="submit"]', form); if (btn) btn.disabled = true;
    P.comment(id, 'Requested move to ' + nice).then(function (res) {
      if (btn) btn.disabled = false;
      if (res && res.ok) {
        form.hidden = true;
        var row = $('[data-when-toggle]', art); if (row) row.setAttribute('aria-expanded', 'false');
        toast('Request sent to Joust', 'success');
      }
    });
  }

  /* ================================================================== */
  /* Wiring                                                              */
  /* ================================================================== */
  function init() {
    var list = $('[data-posts-list]');
    if (list) {
      App.swipe.attach(list, {
        card: '.pl-card',
        canSwipe: function (item, dir) {
          var status = item.getAttribute('data-status'), posted = item.getAttribute('data-posted') === '1';
          if (posted || status === 'draft') return false;   // drafts are sent with Send for review, never swiped
          if (dir === 'right') return status !== 'approved';
          return status !== 'denied';
        },
        commitOut: 'right',
        onCommit: function (item, dir) {
          var id = item.getAttribute('data-id');
          if (dir === 'right') P.decide(id, 'approved');
          else P.open(id, { deny: true });
        }
      });
    }

    // "Load more": the next rows of this status / month (posts.php &partial=list&offset=N). The offset is the rows
    // still in the list — decided rows leave it, so the server's next row is exactly the first one not shown.
    var moreBtn = $('[data-posts-more]');
    if (moreBtn && cfg.listUrl) moreBtn.addEventListener('click', function () { P.loadMore(); });

    // open detail
    document.addEventListener('click', function (e) {
      var opener = e.target.closest('[data-post-open]');
      if (!opener) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault();
      P.open(opener.getAttribute('data-post-open'));
    });

    // One-shot flash from a save elsewhere (add-post.php → posts.php?post=…&msg=…): toast it once, drop it from the URL.
    // (App.toast lives in app.js, which runs after this deferred script → wait for app:ready like the deep link.)
    if (cfg.flash) {
      try {
        var u = new URL(window.location.href); u.searchParams.delete('msg');
        history.replaceState(history.state, '', u.pathname + u.search + u.hash);
      } catch (err) {}
      var showFlash = function () { toast(cfg.flash, 'success'); };
      if (App._inited && App.toast) showFlash();
      else document.addEventListener('app:ready', showFlash, { once: true });
    }

    // drafts: Send for review (row button or the sheet's footer; admin-only markup, status.php enforces the role)
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-submit-post]');
      if (!btn || btn.disabled) return;
      e.preventDefault();
      P.submit(btn.getAttribute('data-submit-post'));
    });

    // work queue: Send for review (admin-only markup; status.php enforces the role)
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-resubmit]');
      if (!btn || btn.disabled) return;
      e.preventDefault();
      P.resubmit(btn.getAttribute('data-resubmit'));
    });

    // everything inside the sheet
    document.addEventListener('click', function (e) {
      var root = sheetRoot(); if (!root || !root.contains(e.target)) return;
      var art = pd(root); if (!art) return;
      var id = art.getAttribute('data-id');
      var t = e.target;

      var decide = t.closest('[data-decide]');
      if (decide) {
        var st = decide.getAttribute('data-decide');
        closeMenu(root);
        if (st === 'denied') openDeny(root);
        else if (st === 'pending') P.decide(id, 'pending', null, { toast: 'Sent for review — back in To Review' });
        else P.decide(id, st);
        return;
      }
      if (t.closest('[data-approve-for-client]')) { closeMenu(root); P.approveForClient(id); return; }
      if (t.closest('[data-deny-cancel]')) { var f = $('[data-deny-form]', root); if (f) f.hidden = true; return; }

      var tp = t.closest('[data-toggle-posted]');
      if (tp) { P.togglePosted(id, tp.getAttribute('data-toggle-posted')); return; }
      if (t.closest('[data-delete-post]')) { closeMenu(root); P.remove(id); return; }

      var menuBtn = t.closest('[data-menu-toggle]');
      if (menuBtn) { var m = $('[data-menu]', root); if (m) { m.hidden = !m.hidden; menuBtn.setAttribute('aria-expanded', m.hidden ? 'false' : 'true'); } return; }
      if (!t.closest('[data-menu]')) closeMenu(root);

      var edit = t.closest('[data-edit]');
      if (edit) {
        var which = edit.getAttribute('data-edit');
        closeMenu(root);
        if (which === 'caption' && art.getAttribute('data-posted') === '1') { toast(App.actor === 'client' ? 'This post is already scheduled' : 'Unmark scheduled first', 'error'); return; }
        var form = $('[data-edit-form="' + which + '"]', root);
        if (form) { form.hidden = false; $$('textarea', form).forEach(syncCount); var first = $('textarea, input', form); if (first) first.focus(); }
        if (which === 'date') { var r = $('[data-when-toggle]', root); if (r) r.setAttribute('aria-expanded', 'true'); }
        return;
      }
      if (t.closest('[data-edit-cancel]')) {
        var ef = t.closest('form'); if (ef) ef.hidden = true;
        var rr = $('[data-when-toggle]', root); if (rr) rr.setAttribute('aria-expanded', 'false');
        return;
      }
      if (t.closest('[data-download-media]')) { closeMenu(root); downloadMedia(root, id); return; }

      var when = t.closest('[data-when-toggle]');
      if (when) {
        var wf = $('[data-request-date], [data-edit-form="date"]', root);
        if (wf) { wf.hidden = !wf.hidden; when.setAttribute('aria-expanded', wf.hidden ? 'false' : 'true'); if (!wf.hidden) { var wi = $('input', wf); if (wi) wi.focus(); } }
        return;
      }

      var copy = t.closest('[data-copy-caption]');
      if (copy) {
        copyText(copy.getAttribute('data-text') || '').then(function (ok) { toast(ok ? 'Caption copied' : 'Copy failed — select and copy manually', ok ? 'success' : 'error'); });
        return;
      }
      var gotoSlide = t.closest('[data-goto-slide]');
      if (gotoSlide) {
        var car = $('[data-carousel]', root), gi = parseInt(gotoSlide.getAttribute('data-goto-slide'), 10) || 0;
        if (car && App.carousel) App.carousel.go(car, gi);
        else if (car) { var tr = $('[data-carousel-track]', car); if (tr) tr.scrollTo({ left: gi * tr.clientWidth, behavior: 'smooth' }); }
        var sb = $('[data-sheet-body]', root); if (sb && car) sb.scrollTo({ top: Math.max(0, car.offsetTop - 12), behavior: App.reducedMotion && App.reducedMotion() ? 'auto' : 'smooth' });
        return;
      }
      // "Edit post…" (admin ⋯ menu) → the New post pop-up in edit mode (newpost.js listens for the same click)
      if (t.closest('[data-newpost-edit]')) { closeMenu(root); return; }
      var view = t.closest('[data-viewer-open]');
      if (view) { var img = $('img', view); if (img) openViewer(img.currentSrc || img.src, img.alt, view.getAttribute('data-original') || ''); return; }   // the lg preview on screen, the file behind "View original"
    });

    /** Admin "Download media": every slide's file (data-src), saved one after another as <client>-<post id>-<n>.<ext>. */
    function downloadMedia(root, id) {
      var slides = $$('.pd-slide[data-src]', root);
      if (!slides.length) { toast('This post has no media yet', 'error'); return; }
      var stem = String(document.body.getAttribute('data-client') || 'post').replace(/[^a-z0-9-]/gi, '-');
      slides.forEach(function (s, i) {
        window.setTimeout(function () {
          var src = s.getAttribute('data-src'), ext = s.getAttribute('data-ext') || (src.split('?')[0].split('.').pop() || 'jpg');
          var a = document.createElement('a');
          a.href = src; a.download = stem + '-' + id + '-' + (i + 1) + '.' + ext.toLowerCase().replace('jpeg', 'jpg');
          a.rel = 'noopener'; a.hidden = true;
          document.body.appendChild(a); a.click(); a.remove();
        }, i * 300);   // browsers drop rapid-fire downloads
      });
      toast(slides.length === 1 ? 'Downloading 1 file' : 'Downloading ' + slides.length + ' files', 'success');
    }
    function closeMenu(root) {
      var m = $('[data-menu]', root); if (m && !m.hidden) { m.hidden = true; var b = $('[data-menu-toggle]', root); if (b) b.setAttribute('aria-expanded', 'false'); }
    }

    document.addEventListener('submit', function (e) {
      var root = sheetRoot(); if (!root || !root.contains(e.target)) return;
      var art = pd(root); if (!art) return;
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
        // Optional "Slide" picker (≥ 2 slides): stored as a "[Slide N] " prefix — no schema change
        var slidePick = $('[data-comment-slide]', form);
        if (slidePick && slidePick.value) text = '[Slide ' + parseInt(slidePick.value, 10) + '] ' + text;
        var send = $('[data-comment-send]', form); if (send) send.disabled = true;
        var internalBox = $('[data-comment-internal]', form);
        P.comment(art.getAttribute('data-id'), text, internalBox && internalBox.checked).then(function (res) {
          if (res && res.ok && input) { input.value = ''; autosize(input); syncSlidePick(root); }
          if (send) send.disabled = !(input && input.value.trim());
        });
        return;
      }
      if (form.hasAttribute('data-request-date')) { requestDate(art, form); return; }
      var kind = form.getAttribute('data-edit-form');
      if (kind === 'caption') saveCaption(art, form);
      else if (kind === 'date') saveDate(art, form);
    });

    document.addEventListener('input', function (e) {
      var root = sheetRoot(); if (!root || !root.contains(e.target)) return;
      if (e.target.matches('[data-deny-note]')) { validateDeny(e.target.closest('form')); return; }
      if (e.target.matches('[data-edit-form="caption"] textarea')) { syncCount(e.target); return; }
      if (e.target.matches('[data-comment-input]')) {
        autosize(e.target);
        var send = $('[data-comment-send]', e.target.closest('form')); if (send) send.disabled = !e.target.value.trim();
      }
    });
    document.addEventListener('carousel:change', function (e) {
      var root = sheetRoot(); if (!root || !root.contains(e.target)) return;
      syncSlidePick(root, e.detail ? e.detail.index : null);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.shiftKey || !e.target.matches || !e.target.matches('[data-comment-input]')) return;
      e.preventDefault();
      var form = e.target.closest('form'); if (form && form.requestSubmit) form.requestSubmit(); else if (form) form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    });

    // deep link (?post=ID): open the sheet once the shared App (sheet, toast) is
    // ready. posts.js is a deferred script emitted BEFORE app.js, so at init time
    // App.sheet does not exist yet — opening immediately used to throw inside
    // P.open()'s promise chain and be swallowed silently.
    if (cfg.openPost) {
      var openDeepLink = function () {
        try { history.replaceState({ post: null }, '', urlWithPost(null)); } catch (err) {}
        P.open(cfg.openPost);
      };
      if (App._inited && App.sheet) openDeepLink();
      else document.addEventListener('app:ready', openDeepLink, { once: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})(window, document);
