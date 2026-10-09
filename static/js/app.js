/* =====================================================================
   Joust client portal — app.js  (vanilla, no dependencies)

   App.toast(message, {kind, duration, link: {href, label}})
   App.sheet.open(target, {title, html, footer}) / .close() / .current
   App.post(endpoint, params) → {ok, status, data, error}
   App.actions  — delegated poster for [data-action][data-endpoint]
                  (optimistic UI via data-optimistic-target, rollback + toast)
   App.status   — client-facing status labels / pill swapping
   App.segmented — keyboard + button enhancement for .ui-segmented
   App.theme    — Appearance: get() → 'light'|'dark'|'auto', set(mode), cycle(),
                  effective() → 'light'|'dark'; persists localStorage portal.theme,
                  drives <html data-theme>, the theme-color metas, every
                  [data-theme-toggle] button and .ui-theme-control; fires
                  'theme:change' {theme, effective} on document.
   Events: 'app:action' (bubbles) with {action, endpoint, id, params, ok,
           status, data, error, el}; 'sheet:open' / 'sheet:close'; 'theme:change'.

   Later phases add App.swipe, App.viewer, App.video onto the same object.
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};

  /* ---------------------------------------------------------------- */
  /* Environment                                                       */
  /* ---------------------------------------------------------------- */
  var reduceMotionMQ = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  App.reducedMotion = function () { return !!(reduceMotionMQ && reduceMotionMQ.matches); };
  App.role  = (document.body && document.body.dataset.role)  || 'client';
  App.actor = (document.body && document.body.dataset.actor) || App.role;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function resolveEl(target) {
    if (!target) return null;
    if (typeof target === 'string') return $(target);
    return target.nodeType === 1 ? target : null;
  }
  function afterTransition(el, cb, fallbackMs) {
    var done = false;
    function finish() { if (done) return; done = true; el.removeEventListener('transitionend', onEnd); cb(); }
    function onEnd(e) { if (e.target === el) finish(); }
    if (App.reducedMotion()) { setTimeout(finish, 160); return; }
    el.addEventListener('transitionend', onEnd);
    setTimeout(finish, fallbackMs || 450);
  }
  App.$ = $; App.$$ = $$;

  /* ---------------------------------------------------------------- */
  /* Links — the JS twin of url-lib.php (window.PortalUrls, written by */
  /* helpers.php portalUrlsScript()):                                  */
  /*   App.urls.build('posts', {client: 'kenda', post: 12})            */
  /*     → /portal/kenda/posts/12   (clean links on)                   */
  /*     → /portal/posts.php?client=kenda&post=12   (off)              */
  /*   App.urls.parse(href) → {script, params}  (both URL styles)      */
  /*   App.urls.withParams({post: 12})  this page's URL with params set */
  /*     (null removes one) — what history.pushState should get       */
  /*   App.urls.abs('status.php') → '/portal/status.php'               */
  /* ---------------------------------------------------------------- */
  App.urls = (function () {
    var PU = window.PortalUrls || {};
    var base = String(PU.base || ''), clean = !!PU.clean, ext = PU.ext === undefined ? '.php' : String(PU.ext);
    var routes = PU.routes || [], reserved = {};
    (PU.reserved || []).forEach(function (r) { reserved[r] = 1; });
    function paramOk(name, v) {
      v = String(v);
      if (/^__[A-Z_]+__$/.test(v)) return name !== 'client';
      if (name === 'client') return /^[a-z0-9][a-z0-9-]{0,39}$/.test(v) && !reserved[v];
      if (name === 'section') return /^[a-z]{2,20}$/.test(v);
      return /^[1-9][0-9]{0,9}$/.test(v);
    }
    function names(pattern) { var out = [], re = /\{([a-z_]+)\}/g, m; while ((m = re.exec(pattern))) out.push(m[1]); return out; }
    function qs(obj) {
      var u = new URLSearchParams();
      Object.keys(obj).forEach(function (k) { u.append(k, String(obj[k])); });
      var s = u.toString();
      return s ? '?' + s : '';
    }
    function tidy(params) {
      var out = {};
      Object.keys(params || {}).forEach(function (k) { var v = params[k]; if (v !== null && v !== undefined && v !== '') out[k] = v; });
      return out;
    }
    function build(script, params) {
      script = String(script || 'index').replace(/\.php$/, '').replace(/^\/+|\/+$/g, '') || 'index';
      var p = tidy(params);
      if (clean) {
        var best = null, bestScore = -1;
        routes.forEach(function (r) {
          if (r[1] !== script) return;
          var ns = names(r[0]), fixed = r[2] || {}, defs = r[3] || {};
          if ((ns.indexOf('client') >= 0) !== (p.client !== undefined)) return;
          for (var i = 0; i < ns.length; i++) { if (p[ns[i]] === undefined || !paramOk(ns[i], p[ns[i]])) return; }
          for (var k in fixed) { if (Object.prototype.hasOwnProperty.call(fixed, k) && String(p[k]) !== String(fixed[k])) return; }
          var score = ns.length * 2 + Object.keys(fixed).length;
          if (score <= bestScore) return;
          var rest = {};
          Object.keys(p).forEach(function (k2) { if (ns.indexOf(k2) < 0 && !(k2 in fixed)) rest[k2] = p[k2]; });
          Object.keys(defs).forEach(function (k3) { if (rest[k3] !== undefined && String(rest[k3]) === String(defs[k3])) delete rest[k3]; });
          var path = r[0].replace(/\{([a-z_]+)\}/g, function (_, n) { return encodeURIComponent(String(p[n])); });
          best = base + '/' + path + qs(rest); bestScore = score;
        });
        if (best !== null) return best;
        return base + '/' + (script === 'index' ? '' : script) + qs(p);
      }
      return base + '/' + (script === 'index' ? '' : script + ext) + qs(p);
    }
    function parse(href) {
      var u;
      try { u = new URL(href || window.location.href, window.location.href); } catch (e) { return { script: 'index', params: {} }; }
      var params = {};
      u.searchParams.forEach(function (v, k) { params[k] = v; });
      var path = decodeURIComponent(u.pathname), rel;
      if (base && path.indexOf(base + '/') === 0) rel = path.slice(base.length + 1);
      else if (base && path === base) rel = '';
      else rel = path.replace(/^\/+/, '');
      if (rel === '' || rel === 'index.php') return { script: 'index', params: params };
      if (/^[A-Za-z0-9_-]+\.php$/.test(rel)) return { script: rel.replace(/\.php$/, ''), params: params };
      var segs = rel.replace(/\/+$/, '').split('/');
      for (var i = 0; i < routes.length; i++) {
        var r = routes[i], ps = r[0].replace(/\/+$/, '').split('/');
        if (ps.length !== segs.length) continue;
        var got = {}, ok = true;
        for (var j = 0; j < ps.length; j++) {
          var m = /^\{([a-z_]+)\}$/.exec(ps[j]);
          if (m) { if (!paramOk(m[1], segs[j])) { ok = false; break; } got[m[1]] = segs[j]; }
          else if (ps[j] !== segs[j].toLowerCase()) { ok = false; break; }
        }
        if (!ok) continue;
        var fixed = r[2] || {};
        Object.keys(fixed).forEach(function (k) { got[k] = String(fixed[k]); });
        Object.keys(got).forEach(function (k) { params[k] = got[k]; });
        return { script: r[1], params: params };
      }
      if (segs.length === 1) return { script: segs[0], params: params };
      return { script: 'index', params: params };
    }
    function withParams(changes, href) {
      var cur = parse(href), p = cur.params;
      Object.keys(changes || {}).forEach(function (k) {
        var v = changes[k];
        if (v === null || v === undefined || v === '') delete p[k]; else p[k] = String(v);
      });
      var hash = '';
      try { hash = new URL(href || window.location.href, window.location.href).hash; } catch (e) {}
      return build(cur.script, p) + hash;
    }
    function abs(u) {
      u = String(u || '');
      if (u === '' || /^([a-z][a-z0-9+.-]*:|\/|#|\?)/i.test(u)) return u;
      return base + '/' + u;
    }
    return { base: base, clean: clean, build: build, parse: parse, withParams: withParams, abs: abs,
             current: function () { return parse(window.location.href); } };
  })();

  /* ---------------------------------------------------------------- */
  /* Tab-bar badges: the viewer's own queue (partials/tabbar.php)      */
  /* ---------------------------------------------------------------- */
  /** The status the viewer's tab badges count: the client's To Review ('pending'), Joust's Needs changes ('denied'). */
  App.queueStatus = function () { return App.role === 'admin' ? 'denied' : 'pending'; };
  function tabEl(tab) { return typeof tab === 'string' ? ($('.ui-tab[data-tab="' + tab + '"]') || $('.ui-tab--' + tab)) : tab; }
  /** A client tab's unread-reply keys (data-badge-replies="post:4 post:7", partials/tabbar.php). */
  function tabReplyKeys(el) { return (el.getAttribute('data-badge-replies') || '').split(/\s+/).filter(Boolean); }
  /** "3 to review, 1 new reply" (the client's badge label; tracking-lib.php trackingTabBadgeLabel()). */
  function clientBadgeLabel(review, replies) {
    var parts = [];
    if (review > 0) parts.push(review + ' to review');
    if (replies > 0) parts.push(replies + ' new ' + (replies === 1 ? 'reply' : 'replies'));
    return parts.join(', ');
  }
  function renderTabBadge(el, review, replies) {
    var n = review + replies;
    var badge = $('.ui-badge', el);
    if (!n) { if (badge) badge.remove(); return; }
    if (!badge) { badge = document.createElement('span'); badge.className = 'ui-badge ui-tab-badge'; el.appendChild(badge); }
    badge.hidden = false;
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.setAttribute('aria-label', App.queueStatus() === 'denied' ? n + ' need changes' : clientBadgeLabel(review, replies));
    badge.setAttribute('data-queue', App.queueStatus());
  }
  /** Set the To Review / Needs changes part of a tab's badge (data-tab key or a .ui-tab element) to n — created when
   *  missing, removed at 0. A client tab keeps its unread-reply part (data-badge-replies) on top. */
  App.tabBadge = function (tab, n) {
    var el = tabEl(tab);
    if (!el) return;
    n = Math.max(0, parseInt(n, 10) || 0);
    var client = el.hasAttribute('data-badge-review');
    if (client) el.setAttribute('data-badge-review', String(n));
    renderTabBadge(el, n, client ? tabReplyKeys(el).length : 0);
  };
  /** Move a tab badge's queue part by delta (its current queue number + delta). */
  App.bumpTabBadge = function (tab, delta) {
    var el = tabEl(tab);
    if (!el || !delta) return;
    var cur;
    if (el.hasAttribute('data-badge-review')) cur = parseInt(el.getAttribute('data-badge-review'), 10) || 0;
    else { var badge = $('.ui-badge', el); cur = badge && !badge.hidden ? (parseInt(badge.textContent, 10) || 0) : 0; }
    App.tabBadge(el, cur + delta);
  };
  /** An item's Joust replies were just read (tracking.js, key "post:4"): drop it from the client tab badge counting it. */
  App.tabBadgeSeen = function (key) {
    Array.prototype.forEach.call(document.querySelectorAll('.ui-tab[data-badge-replies]'), function (el) {
      var keys = tabReplyKeys(el), i = keys.indexOf(key);
      if (i < 0) return;
      keys.splice(i, 1);
      el.setAttribute('data-badge-replies', keys.join(' '));
      renderTabBadge(el, parseInt(el.getAttribute('data-badge-review'), 10) || 0, keys.length);
    });
  };

  /* ---------------------------------------------------------------- */
  /* Inline confirm: the in-sheet panel the Needs changes… note uses   */
  /* (.pd-deny), for a decision that needs a second look — instead of  */
  /* the browser's confirm(). Resolves true (confirmed) / false.       */
  /*   App.confirmInline(beforeEl, {title, text, ok, kind: 'approve'}) */
  /* ---------------------------------------------------------------- */
  var confirmSeq = 0;
  App.confirmInline = function (before, opts) {
    opts = opts || {};
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    return new Promise(function (resolve) {
      if (!before || !before.parentNode) { resolve(window.confirm((opts.title || '') + '\n\n' + (opts.text || ''))); return; }
      var prev = before.parentNode.querySelector('[data-confirm-inline]');
      if (prev && prev.__done) prev.__done(false);
      var n = ++confirmSeq, back = document.activeElement;
      var box = document.createElement('section');
      box.className = 'pd-deny pd-confirm';
      box.setAttribute('data-confirm-inline', opts.name || '');
      box.setAttribute('role', 'alertdialog');
      box.setAttribute('aria-labelledby', 'uiConfirmTitle' + n);
      box.setAttribute('aria-describedby', 'uiConfirmText' + n);
      var kind = opts.kind === 'deny' ? 'ui-btn--deny' : (opts.kind === 'approve' ? 'ui-btn--approve' : 'ui-btn--filled');
      box.innerHTML = '<p class="pd-editor-label pd-confirm-title" id="uiConfirmTitle' + n + '">' + esc(opts.title) + '</p>'
        + (opts.text ? '<p class="pd-editor-hint" id="uiConfirmText' + n + '">' + esc(opts.text) + '</p>' : '')
        + '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-confirm-cancel>Cancel</button>'
        + '<button type="button" class="ui-btn ui-btn--primary ' + kind + '" data-confirm-ok>' + esc(opts.ok || 'OK') + '</button></div>';
      before.parentNode.insertBefore(box, before);
      var settled = false;
      function done(v) {
        if (settled) return; settled = true;
        if (box.parentNode) box.parentNode.removeChild(box);
        if (!v && back && back.focus && document.contains(back)) try { back.focus({ preventScroll: true }); } catch (e) {}
        resolve(v);
      }
      box.__done = done;
      box.addEventListener('click', function (e) {
        if (e.target.closest('[data-confirm-ok]')) done(true);
        else if (e.target.closest('[data-confirm-cancel]')) done(false);
      });
      box.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(false); }
      });
      var ok = box.querySelector('[data-confirm-ok]');
      try { box.scrollIntoView({ block: 'nearest', behavior: App.reducedMotion() ? 'auto' : 'smooth' }); } catch (e) {}
      if (ok) try { ok.focus({ preventScroll: true }); } catch (e) { ok.focus(); }
    });
  };

  /* ---------------------------------------------------------------- */
  /* Sent back (client seat; sentback-lib.php): the sheet of a post /  */
  /* email / page it marked Needs changes — Add a comment (the         */
  /* composer) · Approve instead (asks first, then the page module's   */
  /* own decide(): optimistic, the row leaves the Sent back list).     */
  /* ---------------------------------------------------------------- */
  App.sentBack = {
    /** The open sheet's item + its page module (App.posts / App.emails / App.pages). */
    target: function (el) {
      var root = (el && el.closest && el.closest('.ui-sheet-root')) || document;
      var art = root.querySelector('.pd[data-id]');
      if (!art) return null;
      var mod = art.hasAttribute('data-post-detail') ? { m: App.posts, noun: 'post' }
              : art.hasAttribute('data-email-detail') ? { m: App.emails, noun: 'email' }
              : art.hasAttribute('data-page-detail') ? { m: App.pages, noun: 'page' } : null;
      if (!mod || !mod.m || !mod.m.decide) return null;
      return { root: root, art: art, id: art.getAttribute('data-id'), mod: mod.m, noun: mod.noun };
    },
    approve: function (btn) {
      var t = this.target(btn);
      if (!t) return Promise.resolve(null);
      return App.confirmInline(t.root.querySelector('[data-deny-form]'), {
        name: 'approve-instead', kind: 'approve', title: 'Approve this ' + t.noun + ' instead?',
        text: 'You sent it back for changes. Approving tells Joust to go ahead with it as it is — your note stays in the thread.',
        ok: 'Approve'
      }).then(function (ok) {
        return ok ? t.mod.decide(t.id, 'approved', null, { toast: 'Approved — Joust will take it from here' }) : null;
      });
    },
    comment: function (btn) {
      var t = this.target(btn), input = t && t.root.querySelector('[data-comment-input]');
      if (!input) return;
      try { input.scrollIntoView({ block: 'nearest', behavior: App.reducedMotion() ? 'auto' : 'smooth' }); } catch (e) {}
      try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); }
    }
  };
  // The note shows twice in a Sent back sheet (the panel + its bubble in the thread): an edit in one updates the other.
  document.addEventListener('comment:changed', function (e) {
    var d = e.detail || {}, el = d.el;
    if (!el || d.deleted || !el.closest) return;
    var root = el.closest('.ui-sheet-root');
    if (!root || !root.querySelector('[data-sentback-panel]')) return;
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }).replace(/\n/g, '<br>'); };
    var hosts = Array.prototype.slice.call(root.querySelectorAll('[data-comment-id="' + d.id + '"]')).filter(function (h) { return h !== el; });
    var raw = el.getAttribute('data-comment-raw');
    if (raw == null) { var b0 = el.querySelector('[data-comment-body]'); raw = b0 ? b0.textContent : null; }
    if (raw == null) return;
    hosts.forEach(function (h) {
      var body = h.querySelector('[data-comment-body]');
      if (!body) return;
      var lead = body.firstElementChild && body.firstElementChild.tagName !== 'BR' ? body.firstElementChild.outerHTML + ' ' : '';   // slide chip / "On slide N:"
      body.innerHTML = lead + esc(raw);
      if (h.hasAttribute('data-comment-raw')) h.setAttribute('data-comment-raw', raw);
    });
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-approve-instead]');
    if (a) { e.preventDefault(); App.sentBack.approve(a); return; }
    var c = e.target.closest && e.target.closest('[data-sentback-comment]');
    if (c) { e.preventDefault(); App.sentBack.comment(c); }
  });

  /* ---------------------------------------------------------------- */
  /* The Trash (admin; trash-lib.php / trash.php): "Move to Trash…"   */
  /* asks for an optional reason (Joust only) — inline in an open     */
  /* post / email / page sheet, or in a sheet of its own (Redo rows,  */
  /* the Assets viewer has its own action sheet) — then posts         */
  /* trash.php action=trash. The caller removes the item from view.   */
  /*   App.trash.inline(beforeEl, {noun, n}) → Promise<note | null>   */
  /*   App.trash.sheet({noun, n, sheet})     → Promise<note | null>   */
  /*   App.trash.move(['post:3'], note, client) → App.post reply      */
  /* ---------------------------------------------------------------- */
  App.trash = {
    ep: function () { return App.urls && App.urls.abs ? App.urls.abs('trash.php') : 'trash.php'; },
    esc: function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); },
    title: function (o) { var n = o.n || 1; return n === 1 ? 'Move this ' + (o.noun || 'item') + ' to Trash?' : 'Move ' + n + ' ' + (o.nouns || 'items') + ' to Trash?'; },
    formHtml: function (o) {
      var n = o.n || 1, id = 'trNote' + (++confirmSeq);
      return '<form class="tr-ask-form" data-trash-form novalidate>'
        + '<p class="pd-editor-hint tr-ask-text">' + (n === 1 ? 'It is kept' : 'They are kept') + ' — just left out of every list, count, export and notification, for you and the client. '
        + 'Files stay where they are. Restore it from the Trash any time.</p>'
        + '<label class="pd-editor-label tr-ask-label" for="' + id + '">Reason <span class="text-tertiary">(optional · Joust only, never shown to the client)</span></label>'
        + '<textarea class="ui-textarea tr-ask-note" id="' + id + '" rows="2" maxlength="500" placeholder="e.g. Not redoing this one — the client moved on" data-trash-note data-sheet-autofocus></textarea>'
        + '<div class="ui-btn-group tr-ask-actions"><button type="button" class="ui-btn ui-btn--gray" data-trash-cancel>Cancel</button>'
        + '<button type="submit" class="ui-btn ui-btn--filled tr-ask-submit" data-trash-submit>' + (n === 1 ? 'Move to Trash' : 'Move ' + n + ' to Trash') + '</button></div></form>';
    },
    _wire: function (form, done) {
      form.addEventListener('submit', function (e) { e.preventDefault(); done((form.querySelector('[data-trash-note]').value || '').trim()); });
      form.addEventListener('click', function (e) { if (e.target.closest('[data-trash-cancel]')) { e.preventDefault(); done(null); } });
      form.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(null); } });
    },
    /** In the open sheet, above `before` (the Needs changes form): the same panel style as App.confirmInline. */
    inline: function (before, o) {
      o = o || {};
      var self = this;
      return new Promise(function (resolve) {
        if (!before || !before.parentNode) { var r = window.prompt(self.title(o) + '\n\nReason (optional, Joust only):', ''); resolve(r === null ? null : r.trim()); return; }
        var prev = before.parentNode.querySelector('[data-trash-ask]');
        if (prev && prev.__done) prev.__done(null);
        var box = document.createElement('section');
        box.className = 'pd-deny pd-confirm tr-ask';
        box.setAttribute('data-trash-ask', '');
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-label', self.title(o));
        box.innerHTML = '<p class="pd-editor-label pd-confirm-title">' + self.esc(self.title(o)) + '</p>' + self.formHtml(o);
        before.parentNode.insertBefore(box, before);
        var settled = false;
        var done = function (v) { if (settled) return; settled = true; if (box.parentNode) box.parentNode.removeChild(box); resolve(v); };
        box.__done = done;
        self._wire(box.querySelector('[data-trash-form]'), done);
        try { box.scrollIntoView({ block: 'nearest', behavior: App.reducedMotion() ? 'auto' : 'smooth' }); } catch (e) {}
        var ta = box.querySelector('[data-trash-note]');
        if (ta) try { ta.focus({ preventScroll: true }); } catch (e) { ta.focus(); }
      });
    },
    /** In a sheet of its own (#uiSheet unless o.sheet names another). */
    sheet: function (o) {
      o = o || {};
      var self = this;
      return new Promise(function (resolve) {
        var root = App.sheet && App.sheet.open(o.sheet || '#uiSheet', { title: self.title(o), html: self.formHtml(o), footer: '' });
        if (!root) { var r = window.prompt(self.title(o) + '\n\nReason (optional, Joust only):', ''); resolve(r === null ? null : r.trim()); return; }
        var form = root.querySelector('[data-trash-form]'), settled = false;
        var onClose = function () { if (!settled) { settled = true; resolve(null); } root.removeEventListener('sheet:close', onClose); };
        root.addEventListener('sheet:close', onClose);
        self._wire(form, function (v) {
          if (settled) return;
          settled = true;
          root.removeEventListener('sheet:close', onClose);
          if (App.sheet.current === root) App.sheet.close();
          resolve(v);
        });
      });
    },
    move: function (refs, note, client) {
      var params = { action: 'trash', items: (refs || []).join(','), note: note || '' };
      if (client !== undefined) params.client = client;
      return App.post(this.ep(), params);
    },
    toastDone: function (res, n) {
      var url = res && res.data && res.data.url;
      App.toast((n > 1 ? n + ' items moved' : 'Moved') + ' to Trash', { kind: 'success', duration: 4000, link: url ? { href: url, label: 'Open Trash' } : null });
    }
  };

  /* ---------------------------------------------------------------- */
  /* Toast                                                             */
  /* ---------------------------------------------------------------- */
  var toastTimer = null;
  App.toast = function (message, opts) {
    opts = opts || {};
    var el = document.getElementById('uiToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'uiToast';
      el.className = 'ui-toast';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    el.textContent = message;
    // opts.link = {href, label}: a tappable link after the message ("5 files uploaded · View") — the toast takes taps then
    if (opts.link && opts.link.href) {
      var a = document.createElement('a');
      a.className = 'ui-toast-link'; a.href = opts.link.href; a.textContent = opts.link.label || 'View';
      el.appendChild(document.createTextNode(' ')); el.appendChild(a);
    }
    el.classList.toggle('has-link', !!(opts.link && opts.link.href));
    el.classList.remove('ui-toast--error', 'ui-toast--success');
    if (opts.kind) el.classList.add('ui-toast--' + opts.kind);
    // restart the transition even when a toast is already showing
    el.classList.remove('is-visible');
    void el.offsetWidth;
    el.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove('is-visible'); }, opts.duration || 2200);
    return el;
  };

  /* ---------------------------------------------------------------- */
  /* Body scroll lock (iOS-safe)                                       */
  /* ---------------------------------------------------------------- */
  var scrollLock = { count: 0, y: 0 };
  function lockScroll() {
    if (scrollLock.count++ > 0) return;
    scrollLock.y = window.scrollY || window.pageYOffset || 0;
    var b = document.body;
    b.style.position = 'fixed';
    b.style.top = (-scrollLock.y) + 'px';
    b.style.left = '0'; b.style.right = '0';
    b.style.overflow = 'hidden';
    b.classList.add('ui-scroll-locked');
  }
  function unlockScroll() {
    if (--scrollLock.count > 0) return;
    scrollLock.count = 0;
    var b = document.body;
    b.style.position = ''; b.style.top = ''; b.style.left = ''; b.style.right = ''; b.style.overflow = '';
    b.classList.remove('ui-scroll-locked');
    window.scrollTo(0, scrollLock.y);
  }
  App.lockScroll = lockScroll;
  App.unlockScroll = unlockScroll;

  /* ---------------------------------------------------------------- */
  /* Sheet controller — bottom sheet (mobile) / right panel (desktop)  */
  /* ---------------------------------------------------------------- */
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  App.sheet = {
    current: null,
    _lastFocus: null,
    _closing: false,

    open: function (target, opts) {
      opts = opts || {};
      var root = resolveEl(target) || $('#uiSheet');
      if (!root) return null;
      if (this.current && this.current !== root) this.close(true);
      if (this.current === root) return root;

      if (opts.title !== undefined) { var t = $('[data-sheet-title]', root); if (t) t.textContent = opts.title; }
      if (opts.html !== undefined)  { var b = $('[data-sheet-body]', root);  if (b) b.innerHTML = opts.html; }
      if (opts.footer !== undefined) {
        var f = $('[data-sheet-footer]', root);
        if (f) { f.innerHTML = opts.footer || ''; f.hidden = !opts.footer; }
      }
      var panel = $('.ui-sheet', root) || root;
      panel.classList.toggle('ui-sheet--full', !!opts.full);

      this._lastFocus = document.activeElement;
      this.current = root;
      this._closing = false;
      lockScroll();
      root.classList.add('is-open');
      root.setAttribute('aria-hidden', 'false');
      requestAnimationFrame(function () { requestAnimationFrame(function () { root.classList.add('is-visible'); }); });

      var first = opts.focus === false ? null : ($('[data-sheet-autofocus]', root) || $('.ui-sheet-close', root) || panel);
      if (first) setTimeout(function () { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } }, 60);

      root.dispatchEvent(new CustomEvent('sheet:open', { bubbles: true, detail: { sheet: root, opts: opts } }));
      return root;
    },

    close: function (immediate) {
      var root = this.current;
      if (!root || this._closing) return;
      this._closing = true;
      var self = this;
      var panel = $('.ui-sheet', root) || root;
      var finish = function () {
        root.classList.remove('is-open');
        root.setAttribute('aria-hidden', 'true');
        unlockScroll();
        if (self.current === root) self.current = null;
        self._closing = false;
        var back = self._lastFocus;
        self._lastFocus = null;
        if (back && back.focus && document.contains(back)) { try { back.focus({ preventScroll: true }); } catch (e) {} }
        root.dispatchEvent(new CustomEvent('sheet:close', { bubbles: true, detail: { sheet: root } }));
      };
      root.classList.remove('is-visible');
      if (immediate) finish(); else afterTransition(panel, finish, 450);
    },

    toggle: function (target, opts) {
      var root = resolveEl(target);
      if (root && this.current === root) this.close(); else this.open(target, opts);
    },

    _trapFocus: function (e) {
      var root = this.current;
      if (!root || e.key !== 'Tab') return;
      var items = $$(FOCUSABLE, root).filter(function (el) { return el.offsetParent !== null || el === document.activeElement; });
      if (!items.length) { e.preventDefault(); return; }
      var first = items[0], last = items[items.length - 1];
      if (e.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  };

  document.addEventListener('keydown', function (e) {
    if (!App.sheet.current) return;
    if (e.key === 'Escape') { e.preventDefault(); App.sheet.close(); return; }
    App.sheet._trapFocus(e);
  });

  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-sheet-open]');
    if (opener) {
      e.preventDefault();
      var sel = opener.getAttribute('data-sheet-open') || '#uiSheet';
      App.sheet.open(sel, { title: opener.getAttribute('data-sheet-title') || undefined, full: opener.hasAttribute('data-sheet-full') });
      return;
    }
    if (e.target.closest('[data-sheet-close]')) { e.preventDefault(); App.sheet.close(); return; }
    var backdrop = e.target.closest('.ui-sheet-backdrop');
    if (backdrop && App.sheet.current && App.sheet.current.contains(backdrop) && !App.sheet.current.hasAttribute('data-sheet-static')) {
      App.sheet.close();
    }
  });

  /* ---------------------------------------------------------------- */
  /* Status language (DB value → client-facing label)                  */
  /* ---------------------------------------------------------------- */
  App.status = {
    labels: { draft: 'Draft', pending: 'To Review', approved: 'Approved', denied: 'Needs changes', posted: 'Scheduled', scheduled: 'Scheduled' },
    /** The client reads its Needs-changes items as "Sent back" (sentback-lib.php sentBackLabel()); Joust keeps "Needs changes". */
    sentBack: 'Sent back',
    label: function (status, posted) {
      if (posted) return this.labels.posted;
      if (status === 'denied' && App.role !== 'admin') return this.sentBack;
      return this.labels[status] || (status ? status.charAt(0).toUpperCase() + status.slice(1) : '');
    },
    /** Swap a .ui-pill element to a new status (or posted=true → Scheduled). */
    applyPill: function (pill, status, posted) {
      if (!pill) return;
      var key = posted ? 'scheduled' : status;
      pill.className = pill.className.replace(/\bui-pill--(pending|approved|denied|scheduled|neutral)\b/g, '').replace(/\s+/g, ' ').trim();
      pill.classList.add('ui-pill--' + (['pending', 'approved', 'denied', 'scheduled'].indexOf(key) >= 0 ? key : 'neutral'));
      pill.setAttribute('data-status', posted ? 'posted' : status);
      pill.textContent = this.label(status, posted);
    }
  };

  /* ---------------------------------------------------------------- */
  /* Actor avatar markup for a freshly appended bubble: the server puts */
  /* window.AppAvatars = {admin: <html>, client: <html>} in <head>     */
  /* (avatarScriptTag(), helpers.php) — same markup commentBubble()    */
  /* renders, so a sent message looks like a reloaded one.             */
  /* ---------------------------------------------------------------- */
  App.actorAvatar = function (actor) {
    var a = window.AppAvatars || {};
    if (actor === 'client') return a.client || '';
    if (actor === 'admin')  return a.admin  || '';
    return '';
  };
  /** Side + label of a bubble from the VIEWER's seat (= commentBubble() / commentActorLabel(), comment-thread.php):
   *  the viewer's own message → {side: 'mine', who: 'You'}; the other party → {side: 'theirs', who: 'Joust' | client name}. */
  App.bubbleWho = function (actor) {
    var viewer = App.role === 'admin' ? 'admin' : 'client';
    var names = (window.AppAvatars && window.AppAvatars.names) || {};
    if ((actor === 'admin' || actor === 'client') && actor === viewer) return { side: 'mine', who: 'You' };
    return { side: 'theirs', who: actor === 'admin' ? 'Joust' : (actor === 'client' ? (names.client || 'Client') : 'Note') };
  };

  /* ---------------------------------------------------------------- */
  /* Fetch helper — application/x-www-form-urlencoded, JSON back      */
  /* ---------------------------------------------------------------- */
  App.post = function (endpoint, params) {
    var body = new URLSearchParams();
    Object.keys(params || {}).forEach(function (k) {
      var v = params[k];
      if (v === undefined || v === null) return;
      body.append(k, String(v));
    });
    // Tenant scope: every state-changing request carries the page's client slug
    // (<body data-client>, set server-side) unless the caller set one explicitly.
    if (!body.has('client') && document.body && document.body.dataset.client) {
      body.append('client', document.body.dataset.client);
    }
    // Relative endpoints ('status.php') resolve against the portal folder, not a clean-link path (/portal/kenda/posts/12).
    return fetch(App.urls.abs(endpoint), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
      body: body.toString()
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
        // The client's session ended (signed out elsewhere, revoked, 30 days up): back to sign-in with this page as the return.
        if (res.status === 401 && data && data.signIn && App.role !== 'admin') {
          setTimeout(function () { window.location.href = data.signIn; }, 1200);
        }
        var ok = res.ok && !!data && data.ok !== false;
        return {
          ok: ok, status: res.status, data: data,
          error: ok ? null : ((data && data.error) || (res.ok ? 'Unexpected response' : 'Request failed (' + res.status + ')'))
        };
      });
    }).catch(function (err) {
      return { ok: false, status: 0, data: null, error: (err && err.message) || 'Network error' };
    });
  };

  /* ---------------------------------------------------------------- */
  /* Generic action poster                                             */
  /*   <button data-action="approve" data-endpoint="status.php"        */
  /*           data-id="75" data-status="approved"                     */
  /*           data-optimistic-target=".post-row"                      */
  /*           data-confirm="…" data-toast="Approved">                 */
  /*   Every data-* except the reserved keys is posted as a form field.*/
  /*   data-param-<name> forces a field (e.g. data-param-action).      */
  /* ---------------------------------------------------------------- */
  var RESERVED = {
    action: 1, endpoint: 1, optimisticTarget: 1, optimisticClass: 1, confirm: 1, toast: 1,
    reload: 1, href: 1, busy: 1, sheetOpen: 1, sheetClose: 1, sheetTitle: 1, sheetFull: 1, remove: 1
  };
  function collectParams(el) {
    var params = {};
    var ds = el.dataset;
    Object.keys(ds).forEach(function (key) {
      if (key.indexOf('param') === 0 && key.length > 5) {
        var name = key.charAt(5).toLowerCase() + key.slice(6);
        params[name.replace(/[A-Z]/g, function (m) { return '_' + m.toLowerCase(); })] = ds[key];
        return;
      }
      if (RESERVED[key]) return;
      params[key.replace(/[A-Z]/g, function (m) { return '_' + m.toLowerCase(); })] = ds[key];
    });
    if (params.actor === undefined) params.actor = App.actor;
    return params;
  }

  App.actions = {
    run: function (el) {
      if (!el || el.dataset.busy === '1' || el.disabled) return Promise.resolve(null);
      var endpoint = el.dataset.endpoint;
      var action = el.dataset.action || '';
      if (!endpoint) return Promise.resolve(null);
      if (el.dataset.confirm && !window.confirm(el.dataset.confirm)) return Promise.resolve(null);

      var params = collectParams(el);
      var target = null;
      if (el.dataset.optimisticTarget) {
        target = el.closest(el.dataset.optimisticTarget) || $(el.dataset.optimisticTarget);
      }

      // Optimistic UI: flip status + class immediately, remember how to undo it
      var rollback = null;
      if (target) {
        var newStatus = params.status;
        var cls = el.dataset.optimisticClass;
        var prevStatus = target.getAttribute('data-status');
        var pill = $('[data-status-pill].ui-pill', target);
        var prevPill = pill ? { className: pill.className, text: pill.textContent, status: pill.getAttribute('data-status') } : null;
        if (newStatus) {
          target.setAttribute('data-status', newStatus);
          if (pill) App.status.applyPill(pill, newStatus, false);
        }
        if (cls) target.classList.add(cls);
        target.classList.add('is-busy');
        rollback = function () {
          if (newStatus) {
            if (prevStatus === null) target.removeAttribute('data-status'); else target.setAttribute('data-status', prevStatus);
            if (pill && prevPill) { pill.className = prevPill.className; pill.textContent = prevPill.text; if (prevPill.status !== null) pill.setAttribute('data-status', prevPill.status); }
          }
          if (cls) target.classList.remove(cls);
        };
      }

      el.dataset.busy = '1';
      el.setAttribute('aria-busy', 'true');

      return App.post(endpoint, params).then(function (result) {
        delete el.dataset.busy;
        el.removeAttribute('aria-busy');
        if (target) target.classList.remove('is-busy');

        if (!result.ok) {
          if (rollback) rollback();
          App.toast(result.error || 'Something went wrong', { kind: 'error' });
        } else if (el.dataset.toast) {
          App.toast(el.dataset.toast, { kind: 'success' });
        }

        el.dispatchEvent(new CustomEvent('app:action', {
          bubbles: true,
          detail: { el: el, action: action, endpoint: endpoint, id: params.id, params: params,
                    ok: result.ok, status: result.status, data: result.data, error: result.error,
                    target: target, rolledBack: !result.ok && !!rollback }
        }));

        if (result.ok) {
          if (el.dataset.remove !== undefined && target) App.remove(target);
          if (el.dataset.href) { window.location.href = el.dataset.href; }
          else if (el.dataset.reload !== undefined) { window.location.reload(); }
        }
        return result;
      });
    }
  };

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-action][data-endpoint]');
    if (!el) return;
    e.preventDefault();
    App.actions.run(el);
  });

  /** Animate an element out (scale 0.9 + fade, 220ms) then remove it so the layout reflows. */
  App.remove = function (el, cb) {
    if (!el) return;
    el.classList.add('ui-leave');
    var done = false;
    var finish = function () { if (done) return; done = true; if (el.parentNode) el.parentNode.removeChild(el); if (cb) cb(); };
    el.addEventListener('animationend', finish, { once: true });
    setTimeout(finish, App.reducedMotion() ? 200 : 300);
  };

  /* ---------------------------------------------------------------- */
  /* Segmented control enhancement                                     */
  /*   Links navigate as usual. Buttons toggle .is-active and fire      */
  /*   'segmented:change' {value} on the control. Arrow keys move.      */
  /* ---------------------------------------------------------------- */
  App.segmented = {
    select: function (item) {
      var control = item.closest('.ui-segmented');
      if (!control) return;
      $$('.ui-segmented-item', control).forEach(function (it) {
        var on = it === item;
        it.classList.toggle('is-active', on);
        if (it.getAttribute('role') === 'tab') it.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      control.dispatchEvent(new CustomEvent('segmented:change', { bubbles: true, detail: { item: item, value: item.dataset.value || item.textContent.trim() } }));
    }
  };
  document.addEventListener('click', function (e) {
    var item = e.target.closest('.ui-segmented-item');
    if (!item || item.tagName === 'A') return;
    App.segmented.select(item);
  });
  // Scrollable controls (.ui-segmented--scroll, phones): fade the side that has more segments,
  // and bring the active segment into view on load.
  function segEdges(control) {
    var max = control.scrollWidth - control.clientWidth;
    control.classList.toggle('is-overflow-start', max > 1 && control.scrollLeft > 1);
    control.classList.toggle('is-overflow-end', max > 1 && control.scrollLeft < max - 1);
  }
  function initSegScroll() {
    $$('.ui-segmented--scroll').forEach(function (control) {
      if (control._segScroll) { segEdges(control); return; }
      control._segScroll = true;
      // Scroll just far enough to show the active segment (not centred): the leading segments — the admin's own
      // Draft · Needs changes — stay on screen whenever the active one fits beside them.
      var active = $('.ui-segmented-item.is-active', control);
      if (active && control.scrollWidth > control.clientWidth) {
        var cr = control.getBoundingClientRect(), ar = active.getBoundingClientRect();
        var left = ar.left - cr.left + control.scrollLeft;                 // the active item's x inside the scroller
        var over = left + ar.width + 12 - control.clientWidth;
        control.scrollLeft = over > 0 ? Math.min(over, left) : 0;
      }
      control.addEventListener('scroll', function () { segEdges(control); }, { passive: true });
      segEdges(control);
    });
  }
  App.segmented.refresh = initSegScroll;
  window.addEventListener('resize', function () { $$('.ui-segmented--scroll').forEach(segEdges); });
  document.addEventListener('focusin', function (e) {
    var item = e.target.closest && e.target.closest('.ui-segmented--scroll .ui-segmented-item');
    if (item && item.scrollIntoView) item.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  });
  document.addEventListener('keydown', function (e) {
    var item = e.target.closest && e.target.closest('.ui-segmented-item');
    if (!item || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
    var items = $$('.ui-segmented-item', item.closest('.ui-segmented'));
    var idx = items.indexOf(item);
    var next = items[(idx + (e.key === 'ArrowRight' ? 1 : items.length - 1)) % items.length];
    if (next) { e.preventDefault(); next.focus(); if (next.tagName !== 'A') App.segmented.select(next); }
  });

  /* ---------------------------------------------------------------- */
  /* Appearance — Light / Dark / Auto                                  */
  /*   <html data-theme="light|dark">, absent = Auto (system). The     */
  /*   inline boot script (themeBootScript(), helpers.php) applied the */
  /*   stored choice before first paint; this keeps everything in step */
  /*   afterwards. Pages that pin their own theme (data-theme-pinned)  */
  /*   still persist the choice but are never restyled.                */
  /* ---------------------------------------------------------------- */
  var THEME_KEY = 'portal.theme';
  var THEME_COLOR = { light: '#F2F2F7', dark: '#000000' };
  var darkMQ = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  App.theme = {
    KEY: THEME_KEY,
    labels: { light: 'Light mode', dark: 'Dark mode', auto: 'Auto (follows your device)' },
    order: ['light', 'dark', 'auto'],
    pinned: function () { return document.documentElement.hasAttribute('data-theme-pinned'); },
    get: function () {
      var t = null;
      try { t = localStorage.getItem(THEME_KEY); } catch (e) {}
      return (t === 'light' || t === 'dark') ? t : 'auto';
    },
    effective: function () {
      var t = this.get();
      if (t !== 'auto') return t;
      return (darkMQ && darkMQ.matches) ? 'dark' : 'light';
    },
    set: function (mode, opts) {
      opts = opts || {};
      mode = (mode === 'light' || mode === 'dark') ? mode : 'auto';
      try { if (mode === 'auto') localStorage.removeItem(THEME_KEY); else localStorage.setItem(THEME_KEY, mode); } catch (e) {}
      this.apply();
      if (opts.toast !== false) App.toast(this.labels[mode]);
      document.dispatchEvent(new CustomEvent('theme:change', { detail: { theme: mode, effective: this.effective() } }));
      return mode;
    },
    cycle: function () {
      var i = this.order.indexOf(this.get());
      return this.set(this.order[(i + 1) % this.order.length]);
    },
    /** Reflect the stored choice on <html>, the theme-color metas and every control. */
    apply: function () {
      var mode = this.get();
      var root = document.documentElement;
      if (!this.pinned()) {
        if (mode === 'auto') root.removeAttribute('data-theme'); else root.setAttribute('data-theme', mode);
        $$('meta[name="theme-color"]').forEach(function (m) {
          if (mode === 'auto') {
            var media = m.getAttribute('media') || '';
            m.setAttribute('content', media.indexOf('dark') >= 0 ? THEME_COLOR.dark : THEME_COLOR.light);
          } else {
            m.setAttribute('content', THEME_COLOR[mode]);
          }
        });
      }
      var self = this;
      var next = this.order[(this.order.indexOf(mode) + 1) % this.order.length];
      var word = { light: 'Light', dark: 'Dark', auto: 'Auto' };
      $$('[data-theme-toggle]').forEach(function (btn) {
        btn.setAttribute('aria-label', 'Appearance: ' + self.labels[mode] + '. Switch to ' + word[next].toLowerCase());
        btn.setAttribute('title', 'Appearance: ' + word[mode] + ' — click for ' + word[next]);
        btn.setAttribute('data-theme-state', mode);
      });
      $$('.ui-theme-control').forEach(function (control) {
        $$('.ui-segmented-item', control).forEach(function (it) {
          var on = (it.dataset.themeValue || it.dataset.value) === mode;
          it.classList.toggle('is-active', on);
          if (it.getAttribute('role') === 'tab') it.setAttribute('aria-selected', on ? 'true' : 'false');
        });
      });
    }
  };
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-theme-toggle]');
    if (!btn) return;
    e.preventDefault();
    App.theme.cycle();
  });
  document.addEventListener('segmented:change', function (e) {
    var control = e.target.closest && e.target.closest('.ui-theme-control');
    if (!control) return;
    var item = e.detail && e.detail.item;
    var value = item ? (item.dataset.themeValue || item.dataset.value) : (e.detail && e.detail.value);
    if (value && value !== App.theme.get()) App.theme.set(value);
    else App.theme.apply();
  });
  if (darkMQ && darkMQ.addEventListener) {
    darkMQ.addEventListener('change', function () {
      if (App.theme.get() === 'auto') document.dispatchEvent(new CustomEvent('theme:change', { detail: { theme: 'auto', effective: App.theme.effective() } }));
    });
  }

  /* ---------------------------------------------------------------- */
  /* Nav bar hairline once scrolled                                    */
  /* ---------------------------------------------------------------- */
  function initNav() {
    var nav = $('.ui-nav');
    if (!nav) return;
    var ticking = false;
    var update = function () { nav.classList.toggle('is-scrolled', (window.scrollY || 0) > 6); ticking = false; };
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
    update();
  }

  /* ---------------------------------------------------------------- */
  /* "+ New" create menu (admin; partials/components/new-menu.php)     */
  /*   App.newMenu.open() / close() / toggle()                          */
  /*   App.newMenu.handle(action, fn)  fn(detail) → true = handled      */
  /*     (the item's href is then NOT followed). detail = {action, href, */
  /*     item, client}. Every item click also dispatches a cancelable    */
  /*     `app:new` event on document (preventDefault() = handled).      */
  /*   Built-in: action "post" → App.newPost.open(detail) when defined. */
  /* ---------------------------------------------------------------- */
  App.newMenu = (function () {
    var handlers = {};
    function root() { return $('[data-new-menu]'); }
    function panel() { var r = root(); return r ? $('[data-new-menu-panel]', r) : null; }
    function btn() { var r = root(); return r ? $('[data-new-menu-toggle]', r) : null; }
    function items() { var p = panel(); return p ? $$('[data-new-action]', p) : []; }
    var api = {
      isOpen: function () { var p = panel(); return !!(p && !p.hidden); },
      open: function (focusFirst) {
        var p = panel(), b = btn(); if (!p) return;
        p.hidden = false; p.classList.add('is-open');
        if (b) b.setAttribute('aria-expanded', 'true');
        if (focusFirst) { var first = items()[0]; if (first) first.focus(); }
      },
      close: function (refocus) {
        var p = panel(), b = btn(); if (!p || p.hidden) return;
        p.hidden = true; p.classList.remove('is-open');
        if (b) { b.setAttribute('aria-expanded', 'false'); if (refocus) b.focus(); }
      },
      toggle: function () { if (api.isOpen()) api.close(); else api.open(); },
      handle: function (action, fn) { handlers[action] = fn; return api; },
      /** Run an action as if its item was clicked (true = handled in-page, false = caller should navigate). */
      run: function (action, item) {
        item = item || $('[data-new-action="' + action + '"]', panel() || document);
        var r = root();
        var detail = { action: action, href: item ? item.getAttribute('href') : '', item: item || null, client: r ? (r.getAttribute('data-client') || '') : '' };
        var ev = new CustomEvent('app:new', { detail: detail, cancelable: true });
        var handled = !document.dispatchEvent(ev);
        if (!handled && handlers[action]) handled = handlers[action](detail) === true;
        if (!handled && action === 'post' && App.newPost && typeof App.newPost.open === 'function') { App.newPost.open(detail); handled = true; }
        return handled;
      }
    };
    document.addEventListener('click', function (e) {
      var r = root(); if (!r) return;
      if (e.target.closest('[data-new-menu-toggle]')) { e.preventDefault(); api.toggle(); return; }
      var item = e.target.closest('[data-new-action]');
      if (item && r.contains(item)) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) { api.close(); return; }   // new tab: plain link
        if (api.run(item.getAttribute('data-new-action'), item)) e.preventDefault();
        api.close();
        return;
      }
      if (api.isOpen() && !r.contains(e.target)) api.close();
    });
    document.addEventListener('keydown', function (e) {
      if (!api.isOpen()) {
        var b = btn();
        if (b && document.activeElement === b && (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); api.open(true); }
        return;
      }
      if (e.key === 'Escape') { e.preventDefault(); api.close(true); return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        var list = items(), i = list.indexOf(document.activeElement);
        if (!list.length) return;
        e.preventDefault();
        i = e.key === 'ArrowDown' ? (i + 1) % list.length : (i <= 0 ? list.length - 1 : i - 1);
        list[i].focus();
      }
      if (e.key === 'Tab') api.close();
    });
    return api;
  })();

  /* ---------------------------------------------------------------- */
  /* Preview placeholders: while every preview generator slot on the   */
  /* host is busy, preview.php answers a tiny no-store SVG with        */
  /* Server-Timing / X-Preview: pv-pending. Those <img> are queued and */
  /* re-asked two at a time (fetch: its headers are readable) with a   */
  /* backoff that grows while the server stays busy; once a fetch      */
  /* follows the 302 to the static preview, the <img> is pointed at    */
  /* that final URL (already in the cache — the URL every later page   */
  /* prints). pv-failed tiles are left alone. Detection of the first   */
  /* placeholder: Resource Timing serverTiming, else a ≤1×1 natural    */
  /* size on a preview.php image.                                      */
  /* ---------------------------------------------------------------- */
  App.previewRetry = (function () {
    var PV = /\/preview\.php\?/, PAR = 2, MAX_TRIES = 60, MIN_DELAY = 250, MAX_DELAY = 6000;
    var timing = !!(window.PerformanceObserver && window.PerformanceServerTiming && window.fetch);
    var queue = [], inflight = 0, delay = MIN_DELAY, timer = null;
    function bump(u, n) { return /([?&])r=\d+/.test(u) ? u.replace(/([?&])r=\d+/, '$1r=' + n) : u + '&r=' + n; }
    function enqueue(img) {
      if (!img || img.__pvQueued || !window.fetch) return;
      img.__pvQueued = true;
      img.setAttribute('data-pv-pending', '');
      queue.push(img);
      schedule();
    }
    function schedule() {
      if (timer || !queue.length || inflight >= PAR) return;
      timer = setTimeout(function () { timer = null; pump(); }, delay);
    }
    function pump() {
      while (inflight < PAR && queue.length) {
        var img = queue.shift();
        if (img.isConnected) ask(img);
        else img.__pvQueued = false;
      }
    }
    function done(img) { img.__pvQueued = false; img.removeAttribute('data-pv-pending'); }
    function ask(img) {
      var u = img.currentSrc || img.src || '';
      if (!PV.test(u)) { done(img); return; }
      var n = (parseInt(img.getAttribute('data-pv-retry'), 10) || 0) + 1;
      img.setAttribute('data-pv-retry', String(n));
      inflight++;
      fetch(bump(u, n), { credentials: 'same-origin' }).then(function (res) {
        var state = res.headers.get('X-Preview');
        if (state === 'pending' || !res.ok) {
          if (res.body && res.body.cancel) { try { res.body.cancel(); } catch (e) {} }
          delay = Math.min(MAX_DELAY, delay + 250 + Math.round(delay * 0.15));   // the host is busy: ask a little less often
          if (n < MAX_TRIES) { img.__pvQueued = false; enqueue(img); } else done(img);
          return null;
        }
        if (state === 'failed') { done(img); return null; }
        return res.arrayBuffer().then(function () {   // the static preview is now in the HTTP cache
          delay = MIN_DELAY;
          var fin = res.url || bump(u, n);
          var set = img.getAttribute('srcset');
          if (set) img.setAttribute('srcset', set.split(',').map(function (part) { var p = part.trim().split(/\s+/); if (p[0] && new URL(p[0], location.href).href === u) p[0] = fin; return p.join(' '); }).join(', '));
          if (new URL(img.getAttribute('src') || '', location.href).href === u || !set) img.setAttribute('src', fin);
          done(img);
        });
      }).catch(function () {
        delay = Math.min(MAX_DELAY, delay * 2);
        if (n < MAX_TRIES) { img.__pvQueued = false; enqueue(img); } else done(img);
      }).then(function () { inflight--; schedule(); });
    }
    /** A placeholder was answered for this absolute URL: queue the <img> showing it (its load may not have fired yet). */
    function pendingUrl(u, tries) {
      var hit = $$('img').filter(function (img) { return img.currentSrc === u || img.src === u; });
      hit.forEach(enqueue);
      if (!hit.length && (tries || 0) < 20) setTimeout(function () { pendingUrl(u, (tries || 0) + 1); }, 150);
    }
    function onLoad(img) {
      if (timing || !img || img.tagName !== 'IMG') return;
      if (PV.test(img.currentSrc || img.src || '') && img.naturalWidth <= 1 && img.naturalHeight <= 1) enqueue(img);
    }
    if (timing) {
      try {
        if (performance.setResourceTimingBufferSize) performance.setResourceTimingBufferSize(2000);
        new PerformanceObserver(function (list) {
          list.getEntries().forEach(function (e) {
            if (e.initiatorType !== 'img' || !PV.test(e.name) || !e.serverTiming) return;
            for (var i = 0; i < e.serverTiming.length; i++) if (e.serverTiming[i].name === 'pv-pending') { pendingUrl(e.name, 0); return; }
          });
        }).observe({ type: 'resource', buffered: true });
      } catch (err) { timing = false; }
    }
    // load does not bubble — listen in the capture phase for every <img> on the page, now and later.
    document.addEventListener('load', function (e) { onLoad(e.target); }, true);
    function scan(root) { $$('img', root || document).forEach(function (img) { if (img.complete) onLoad(img); }); }
    return { scan: scan };
  })();

  /* ---------------------------------------------------------------- */
  /* Comment editing (comment-edit-lib.php → comment-edit.php)          */
  /*   Every thread renders editable comments with data-comment-id,     */
  /*   data-comment-can="edit", data-comment-raw (the stored text) and  */
  /*   a ⋯ button ([data-comment-more]); long-press opens the same menu */
  /*   on touch. Edit is inline (Save / Cancel, Enter saves, Shift+Enter */
  /*   = new line, Esc cancels); Delete asks first and leaves "Comment  */
  /*   deleted" in place; History (admin) lists every revision inline.  */
  /*   App.comments.adopt(msgEl, id, rawText) makes a just-sent bubble  */
  /*   editable. Fires 'comment:changed' {id, deleted, el} on document. */
  /* ---------------------------------------------------------------- */
  App.comments = (function () {
    var ENDPOINT = 'comment-edit.php';
    var MORE_SVG = '<svg class="ui-icon" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg>';
    var menu = null, menuHost = null, seq = 0, press = null, swallowClick = false;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function hostOf(el) { return el && el.closest ? el.closest('[data-comment-id]') : null; }
    function canEdit(h) { return !!h && h.getAttribute('data-comment-can') === 'edit' && !h.hasAttribute('data-comment-deleted'); }
    function hasHistory(h) { return !!h && App.role === 'admin' && h.hasAttribute('data-comment-history'); }
    function isNote(h) { return h.getAttribute('data-comment-host') === 'note'; }
    function bodyOf(h) { return $('[data-comment-body]', h); }
    function splitSlide(raw) { var m = /^\[Slide (\d{1,2})\]\s*/.exec(raw || ''); return m ? [parseInt(m[1], 10), raw.slice(m[0].length)] : [0, raw || '']; }
    function sheetScope(h) { return h.closest('.ui-sheet-root') || h.closest('.ui-viewer') || document; }
    function emit(h, id, deleted) { document.dispatchEvent(new CustomEvent('comment:changed', { detail: { id: id, deleted: !!deleted, el: h } })); }

    /* ---- the ⋯ menu ---- */
    function closeMenu(refocus) {
      if (!menu) return;
      var h = menuHost; menu.remove(); menu = null; menuHost = null;
      var btn = h && $('[data-comment-more]', h);
      if (btn) { btn.setAttribute('aria-expanded', 'false'); if (refocus) try { btn.focus({ preventScroll: true }); } catch (e) {} }
      if (h) h.classList.remove('is-menu-open');
    }
    function openMenu(h, viaKeyboard) {
      closeMenu(false);
      var items = [];
      if (canEdit(h)) { items.push(['edit', 'Edit']); items.push(['delete', 'Delete']); }
      if (hasHistory(h)) items.push(['history', 'History']);
      if (!items.length) return;
      menu = document.createElement('div');
      menu.className = 'pd-menu pd-comment-menu';
      menu.setAttribute('role', 'menu');
      menu.setAttribute('data-comment-menu', '');
      menu.innerHTML = items.map(function (it) {
        return '<button type="button" role="menuitem" data-comment-menu-item="' + it[0] + '"' + (it[0] === 'delete' ? ' class="is-destructive"' : '') + '>' + it[1] + '</button>';
      }).join('');
      menuHost = h;
      h.classList.add('is-menu-open');
      h.appendChild(menu);
      var btn = $('[data-comment-more]', h); if (btn) btn.setAttribute('aria-expanded', 'true');
      try { menu.scrollIntoView({ block: 'nearest', behavior: App.reducedMotion() ? 'auto' : 'smooth' }); } catch (e) {}
      var first = $('[role="menuitem"]', menu);
      if (first && viaKeyboard !== false) try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
    }

    /* ---- inline edit ---- */
    function slideCount(h) {
      var t = h.closest('[data-thread]'); var n = t ? parseInt(t.getAttribute('data-slides'), 10) || 0 : 0;
      if (!n) { var scope = sheetScope(h); n = scope && scope.querySelectorAll ? scope.querySelectorAll('[data-carousel] [data-slide]').length : 0; }
      return n;
    }
    function startEdit(h) {
      if (!canEdit(h) || $('[data-comment-editor]', h)) return;
      // data-comment-raw = the message without its "[Slide N] " tag; data-comment-on-slide = the tag's N
      var parts = splitSlide(h.getAttribute('data-comment-raw') || '');
      var slide = parseInt(h.getAttribute('data-comment-on-slide'), 10) || parts[0], text = parts[1];
      var n = Math.max(slideCount(h), slide);
      var id = 'commentEdit' + (++seq);
      var form = document.createElement('form');
      form.className = 'pd-msg-editor';
      form.setAttribute('data-comment-editor', '');
      form.setAttribute('autocomplete', 'off');
      var pick = '';
      if (n >= 2 || slide > 0) {
        pick = '<label class="ui-visually-hidden" for="' + id + 's">About slide</label><select class="pd-composer-slide pd-msg-editor-slide" id="' + id + 's" data-comment-edit-slide><option value="0">All slides</option>';
        for (var i = 1; i <= Math.max(n, slide); i++) pick += '<option value="' + i + '"' + (i === slide ? ' selected' : '') + '>Slide ' + i + '</option>';
        pick += '</select>';
      }
      form.innerHTML = '<label class="ui-visually-hidden" for="' + id + '">Edit comment</label>'
        + '<textarea class="ui-textarea pd-msg-editor-input" id="' + id + '" data-comment-edit-input maxlength="2000" rows="2">' + esc(text) + '</textarea>'
        + '<div class="pd-msg-editor-bar">' + pick
        + '<span class="pd-msg-editor-hint">Esc to cancel · Enter to save</span>'
        + '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-comment-edit-cancel>Cancel</button>'
        + '<button type="submit" class="ui-btn ui-btn--filled ui-btn--sm" data-comment-edit-save>Save</button></div>'
        + '<p class="pd-msg-editor-error" data-comment-edit-error role="alert" hidden></p>';
      var body = bodyOf(h);
      if (body) body.hidden = true;
      h.classList.add('is-editing');
      if (body && body.nextSibling) h.insertBefore(form, body.nextSibling); else h.appendChild(form);
      var ta = $('[data-comment-edit-input]', form);
      var fit = function () { ta.style.height = 'auto'; ta.style.height = Math.min(220, Math.max(44, ta.scrollHeight)) + 'px'; };
      fit();
      ta.addEventListener('input', function () {
        fit();
        var save = $('[data-comment-edit-save]', form); if (save) save.disabled = !ta.value.trim();
      });
      form.addEventListener('submit', function (e) { e.preventDefault(); e.stopPropagation(); save(h, form); });
      form.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancelEdit(h, true); return; }
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.target === ta) { e.preventDefault(); e.stopPropagation(); save(h, form); }
      });
      $('[data-comment-edit-cancel]', form).addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); cancelEdit(h, true); });
      try { ta.focus({ preventScroll: true }); } catch (e) { ta.focus(); }
      ta.setSelectionRange(ta.value.length, ta.value.length);
      try { form.scrollIntoView({ block: 'nearest', behavior: App.reducedMotion() ? 'auto' : 'smooth' }); } catch (e) {}
    }
    function cancelEdit(h, refocus) {
      var form = $('[data-comment-editor]', h); if (form) form.remove();
      var body = bodyOf(h); if (body) body.hidden = false;
      h.classList.remove('is-editing');
      var btn = $('[data-comment-more]', h);
      if (refocus && btn) try { btn.focus({ preventScroll: true }); } catch (e) {}
    }
    function fillChipThumbs(el) {
      $$('.pd-slide-chip', el).forEach(function (chip) {
        if ($('img', chip)) return;
        var n = parseInt(chip.getAttribute('data-goto-slide'), 10);
        var fig = $('[data-carousel] [data-slide="' + n + '"]', sheetScope(el));
        var thumb = fig ? (fig.getAttribute('data-thumb') || '') : '';
        if (!thumb) return;
        var blank = $('.pd-slide-chip-blank', chip);
        var img = document.createElement('img'); img.src = thumb; img.alt = ''; img.decoding = 'async';
        if (blank) chip.replaceChild(img, blank); else chip.insertBefore(img, chip.firstChild);
      });
    }
    function swap(h, html) {
      var tpl = document.createElement('div'); tpl.innerHTML = String(html || '').trim();
      var next = tpl.firstElementChild;
      if (!next) return h;
      h.parentNode.replaceChild(next, h);
      fillChipThumbs(next);
      return next;
    }
    function save(h, form) {
      var ta = $('[data-comment-edit-input]', form), err = $('[data-comment-edit-error]', form);
      var text = ta ? ta.value.trim() : '';
      if (!text) { if (err) { err.textContent = 'A comment can’t be empty — delete it instead.'; err.hidden = false; } return; }
      var btn = $('[data-comment-edit-save]', form); if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
      var params = { action: 'edit', id: h.getAttribute('data-comment-id'), text: text };
      var pick = $('[data-comment-edit-slide]', form); if (pick) params.slide = pick.value;
      App.post(ENDPOINT, params).then(function (res) {
        if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); }
        if (!res.ok) {
          if (err) { err.textContent = res.error || 'Could not save'; err.hidden = false; }
          App.toast(res.error || 'Could not save', { kind: 'error' });
          return;
        }
        var d = res.data || {}, id = h.getAttribute('data-comment-id'), el;
        if (isNote(h)) {
          cancelEdit(h, false);
          var body = bodyOf(h); if (body) body.innerHTML = d.body_html || esc(d.text || '');
          h.setAttribute('data-comment-raw', d.text || '');
          if (d.slide) h.setAttribute('data-comment-on-slide', String(d.slide)); else h.removeAttribute('data-comment-on-slide');
          if (d.edited && !$('[data-comment-edited-tag]', h)) {
            var cap = $('figcaption', h), more = cap && $('[data-comment-more]', cap);
            if (cap) { var tag = document.createElement('span'); tag.className = 'pd-msg-edited-tag'; tag.setAttribute('data-comment-edited-tag', ''); tag.textContent = 'edited'; cap.insertBefore(document.createTextNode(' · '), more); cap.insertBefore(tag, more); }
          }
          el = h;
          var mb = $('[data-comment-more]', h); if (mb) try { mb.focus({ preventScroll: true }); } catch (e) {}
        } else {
          el = swap(h, d.html);
          var nb = $('[data-comment-more]', el); if (nb) try { nb.focus({ preventScroll: true }); } catch (e) {}
        }
        if (d.changed) App.toast('Comment updated', { kind: 'success' });
        emit(el, id, false);
      });
    }

    /* ---- delete ---- */
    function bumpCounts(h, delta) {
      var scope = sheetScope(h);
      var t = h.closest('[data-thread]');
      if (t) t.setAttribute('data-count', String(Math.max(0, (parseInt(t.getAttribute('data-count'), 10) || 0) + delta)));
      $$('[data-comment-count]', scope === document ? document : scope).forEach(function (c) {
        var n = parseInt(c.textContent, 10); if (!isNaN(n)) c.textContent = String(Math.max(0, n + delta));
      });
    }
    function remove(h) {
      var id = h.getAttribute('data-comment-id');
      App.confirmInline(h, { title: 'Delete this comment?', text: isNote(h) ? 'Joust keeps a record of what it said.' : 'It shows as “Comment deleted” in the thread.', ok: 'Delete', kind: 'deny', name: 'comment-delete' })
        .then(function (yes) {
          if (!yes) { var b = $('[data-comment-more]', h); if (b) try { b.focus({ preventScroll: true }); } catch (e) {} return; }
          h.classList.add('is-busy');
          App.post(ENDPOINT, { action: 'delete', id: id }).then(function (res) {
            h.classList.remove('is-busy');
            if (!res.ok) { App.toast(res.error || 'Could not delete', { kind: 'error' }); return; }
            var d = res.data || {}, el;
            if (isNote(h)) {
              el = document.createElement('p'); el.className = 'pd-hidden-note-gone text-tertiary'; el.setAttribute('data-comment-deleted', '1'); el.textContent = 'Note deleted.';
              h.parentNode.replaceChild(el, h);
            } else {
              el = swap(h, d.html);
              if (d.changed) bumpCounts(el, -1);
            }
            App.toast('Comment deleted', { kind: 'success' });
            emit(el, id, true);
          });
        });
    }

    /* ---- history (admin) ---- */
    function history(h) {
      var open = $('[data-comment-history-list]', h);
      if (open) { open.remove(); return; }
      App.post(ENDPOINT, { action: 'history', id: h.getAttribute('data-comment-id') }).then(function (res) {
        if (!res.ok) { App.toast(res.error || 'Could not load the history', { kind: 'error' }); return; }
        var items = (res.data && res.data.items) || [];
        var label = { posted: 'Posted', edit: 'Edited', 'delete': 'Deleted' };
        var box = document.createElement('div');
        box.className = 'pd-msg-history';
        box.setAttribute('data-comment-history-list', '');
        box.setAttribute('role', 'region');
        box.setAttribute('aria-label', 'Comment history');
        box.innerHTML = '<div class="pd-msg-history-head"><strong>History</strong><button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-comment-history-close>Close</button></div>'
          + '<ol>' + items.map(function (it) {
            return '<li data-history-kind="' + esc(it.kind) + '"><span class="pd-msg-history-meta">' + esc(label[it.kind] || it.kind) + ' by ' + esc(it.who) + ' · ' + esc(it.at_label || it.at) + '</span>'
              + (it.kind === 'delete' ? '<span class="pd-msg-history-text is-deleted">' + esc(it.old || '') + '</span>' : '<span class="pd-msg-history-text">' + esc(it.text || '').replace(/\n/g, '<br>') + '</span>')
              + '</li>';
          }).join('') + '</ol>';
        h.appendChild(box);
        $('[data-comment-history-close]', box).addEventListener('click', function (e) { e.stopPropagation(); box.remove(); var b = $('[data-comment-more]', h); if (b) b.focus(); });
        try { box.scrollIntoView({ block: 'nearest', behavior: App.reducedMotion() ? 'auto' : 'smooth' }); } catch (e) {}
      });
    }

    /* ---- events (capture: the viewer / sheets never see these taps) ---- */
    document.addEventListener('click', function (e) {
      if (swallowClick) { swallowClick = false; if (hostOf(e.target)) { e.preventDefault(); e.stopPropagation(); return; } }
      var more = e.target.closest && e.target.closest('[data-comment-more]');
      if (more) {
        e.preventDefault(); e.stopPropagation();
        var h = hostOf(more);
        if (menu && menuHost === h) closeMenu(true); else openMenu(h, true);
        return;
      }
      var item = e.target.closest && e.target.closest('[data-comment-menu-item]');
      if (item) {
        e.preventDefault(); e.stopPropagation();
        var host = menuHost, what = item.getAttribute('data-comment-menu-item');
        closeMenu(false);
        if (!host) return;
        if (what === 'edit') startEdit(host);
        else if (what === 'delete') remove(host);
        else if (what === 'history') history(host);
        return;
      }
      var ed = e.target.closest && e.target.closest('[data-comment-edited]');
      if (ed && ed.tagName === 'BUTTON') {
        e.preventDefault(); e.stopPropagation();
        var detail = ed.parentNode && $('[data-comment-edited-detail]', ed.parentNode);
        if (detail) { detail.hidden = !detail.hidden; ed.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true'); }
        return;
      }
      if (menu && !(e.target.closest && e.target.closest('[data-comment-menu]'))) closeMenu(false);
    }, true);
    document.addEventListener('keydown', function (e) {
      if (!menu) return;
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(true); return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        var list = $$('[role="menuitem"]', menu), i = list.indexOf(document.activeElement);
        e.preventDefault();
        i = e.key === 'ArrowDown' ? (i + 1) % list.length : (i <= 0 ? list.length - 1 : i - 1);
        list[i].focus();
        e.stopPropagation();
        return;
      }
      if (e.key === 'Tab') closeMenu(false);
    }, true);
    // Long-press (touch): the same menu, without the ⋯ hunt.
    document.addEventListener('touchstart', function (e) {
      if (e.touches.length !== 1) return;
      var t = e.target;
      if (t.closest('a, button, textarea, input, select, [data-comment-editor], summary')) return;
      var h = hostOf(t);
      if (!h || isNote(h) && !t.closest('[data-comment-body]') || !(canEdit(h) || hasHistory(h))) return;
      var x = e.touches[0].clientX, y = e.touches[0].clientY;
      press = { h: h, x: x, y: y, timer: setTimeout(function () {
        if (!press) return;
        swallowClick = true;
        if (navigator.vibrate) try { navigator.vibrate(10); } catch (err) {}
        openMenu(press.h, false);
        press = null;
      }, 480) };
    }, { passive: true });
    function cancelPress() { if (press) { clearTimeout(press.timer); press = null; } }
    document.addEventListener('touchmove', function (e) {
      if (!press) return;
      var t = e.touches[0];
      if (Math.abs(t.clientX - press.x) > 8 || Math.abs(t.clientY - press.y) > 8) cancelPress();
    }, { passive: true });
    document.addEventListener('touchend', function () { cancelPress(); setTimeout(function () { swallowClick = false; }, 450); }, { passive: true });
    document.addEventListener('touchcancel', cancelPress, { passive: true });
    document.addEventListener('contextmenu', function (e) { if (menu && hostOf(e.target) === menuHost) e.preventDefault(); });

    return {
      endpoint: ENDPOINT,
      /** A bubble the page just appended (posts.js / emails.js / pages.js / assets.js): editable in place now. */
      adopt: function (msg, id, raw) {
        id = parseInt(id, 10);
        if (!msg || !id) return msg;
        msg.setAttribute('data-comment-id', String(id));
        msg.setAttribute('data-comment-can', 'edit');
        var parts = splitSlide(String(raw || ''));
        msg.setAttribute('data-comment-raw', parts[1]);
        if (parts[0]) msg.setAttribute('data-comment-on-slide', String(parts[0]));
        var bubble = $('.ui-bubble', msg); if (bubble) bubble.setAttribute('data-comment-body', '');
        if (!$('[data-comment-more]', msg)) {
          var b = document.createElement('button');
          b.type = 'button'; b.className = 'pd-msg-more'; b.setAttribute('data-comment-more', '');
          b.setAttribute('aria-haspopup', 'menu'); b.setAttribute('aria-expanded', 'false');
          b.setAttribute('aria-label', 'Comment options'); b.title = 'Comment options';
          b.innerHTML = MORE_SVG;
          var meta = $('.ui-bubble-meta', msg);
          (meta || msg).appendChild(b);
        }
        return msg;
      },
      edit: startEdit, remove: remove, history: history, open: openMenu, close: closeMenu
    };
  })();

  /* ---------------------------------------------------------------- */
  /* Init                                                              */
  /* ---------------------------------------------------------------- */
  App.init = function () {
    if (App._inited) return; App._inited = true;
    App.role  = (document.body && document.body.dataset.role)  || App.role;
    App.actor = (document.body && document.body.dataset.actor) || App.role;
    initNav();
    initSegScroll();
    App.theme.apply();
    App.previewRetry.scan();   // placeholders that loaded before this script ran
    // One-shot flash from a save elsewhere (<body data-flash>, layout-top.php $pageFlash): toast it once, drop msg= from the URL.
    var flash = document.body && document.body.getAttribute('data-flash');
    if (flash) {
      document.body.removeAttribute('data-flash');
      try { var u = new URL(window.location.href); u.searchParams.delete('msg'); history.replaceState(history.state, '', u.pathname + u.search + u.hash); } catch (e) {}
      setTimeout(function () { App.toast(flash, { kind: 'success' }); }, 0);
    }
    document.dispatchEvent(new CustomEvent('app:ready', { detail: { App: App } }));
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', App.init);
  else App.init();

})(window, document);
