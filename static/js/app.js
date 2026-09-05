/* =====================================================================
   Joust client portal — app.js  (vanilla, no dependencies)

   App.toast(message, {kind, duration})
   App.sheet.open(target, {title, html, footer}) / .close() / .current
   App.post(endpoint, params) → {ok, status, data, error}
   App.actions  — delegated poster for [data-action][data-endpoint]
                  (optimistic UI via data-optimistic-target, rollback + toast)
   App.status   — client-facing status labels / pill swapping
   App.segmented — keyboard + button enhancement for .ui-segmented
   Events: 'app:action' (bubbles) with {action, endpoint, id, params, ok,
           status, data, error, el}; 'sheet:open' / 'sheet:close'.

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
    labels: { pending: 'To Review', approved: 'Approved', denied: 'Needs changes', posted: 'Scheduled', scheduled: 'Scheduled' },
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
  /* Fetch helper — application/x-www-form-urlencoded, JSON back      */
  /* ---------------------------------------------------------------- */
  App.post = function (endpoint, params) {
    var body = new URLSearchParams();
    Object.keys(params || {}).forEach(function (k) {
      var v = params[k];
      if (v === undefined || v === null) return;
      body.append(k, String(v));
    });
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
  document.addEventListener('keydown', function (e) {
    var item = e.target.closest && e.target.closest('.ui-segmented-item');
    if (!item || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
    var items = $$('.ui-segmented-item', item.closest('.ui-segmented'));
    var idx = items.indexOf(item);
    var next = items[(idx + (e.key === 'ArrowRight' ? 1 : items.length - 1)) % items.length];
    if (next) { e.preventDefault(); next.focus(); if (next.tagName !== 'A') App.segmented.select(next); }
  });

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
  /* Init                                                              */
  /* ---------------------------------------------------------------- */
  App.init = function () {
    if (App._inited) return; App._inited = true;
    App.role  = (document.body && document.body.dataset.role)  || App.role;
    App.actor = (document.body && document.body.dataset.actor) || App.role;
    initNav();
    document.dispatchEvent(new CustomEvent('app:ready', { detail: { App: App } }));
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', App.init);
  else App.init();

})(window, document);
