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
  /* Tab-bar badges: the viewer's own queue (partials/tabbar.php)      */
  /* ---------------------------------------------------------------- */
  /** The status the viewer's tab badges count: the client's To Review ('pending'), Joust's Needs changes ('denied'). */
  App.queueStatus = function () { return App.role === 'admin' ? 'denied' : 'pending'; };
  /** Set the badge of a tab (data-tab key or a .ui-tab element) to n — created when missing, removed at 0. */
  App.tabBadge = function (tab, n) {
    var el = typeof tab === 'string' ? ($('.ui-tab[data-tab="' + tab + '"]') || $('.ui-tab--' + tab)) : tab;
    if (!el) return;
    n = Math.max(0, parseInt(n, 10) || 0);
    var badge = $('.ui-badge', el);
    if (!n) { if (badge) badge.remove(); return; }
    if (!badge) { badge = document.createElement('span'); badge.className = 'ui-badge ui-tab-badge'; el.appendChild(badge); }
    badge.hidden = false;
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.setAttribute('aria-label', n + (App.queueStatus() === 'denied' ? ' need changes' : ' to review'));
    badge.setAttribute('data-queue', App.queueStatus());
  };
  /** Move a tab badge by delta (its current number + delta). */
  App.bumpTabBadge = function (tab, delta) {
    var el = typeof tab === 'string' ? ($('.ui-tab[data-tab="' + tab + '"]') || $('.ui-tab--' + tab)) : tab;
    if (!el || !delta) return;
    var badge = $('.ui-badge', el), cur = badge && !badge.hidden ? (parseInt(badge.textContent, 10) || 0) : 0;
    App.tabBadge(el, cur + delta);
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
    label: function (status, posted) {
      if (posted) return this.labels.posted;
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
    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
      body: body.toString()
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
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
