/* =====================================================================
   Joust client portal — posts-calendar.js  (Posts → Calendar; partials/posts-calendar.php, calendar-lib.php)
   Extends the global App from app.js; the post sheet itself is posts.js (App.posts.open).

   App.postsCalendar.load(ym, {push, replace, approved, keepSelection})  fetch one month (posts.php &partial=calendar)
                                                                        and swap <section data-cal> in place
   App.postsCalendar.reload()        the same month again (live updates: Mark scheduled / Unmark, the Post date, Trash,
                                     Delete, a decision, a New post pop-up save)
   App.postsCalendar.openDay(date)   the day sheet (#calDaySheet): every post that day ("+N more", a day number)
   App.postsCalendar.select(date)    phones: the day listed under the grid
   App.postsCalendar.afterSave(id, slug, msg)   newpost.js: redraw + open the saved post (true = handled)
   Events: 'calendar:loaded' {month}
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var cfg = window.PostsCalendarConfig || {};
  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var PHONE = window.matchMedia ? window.matchMedia('(max-width: 759.98px)') : { matches: false };

  var C = App.postsCalendar = { _seq: 0 };

  function root() { return $('[data-cal]'); }
  function month() { var r = root(); return r ? r.getAttribute('data-month') : ''; }
  function approvedOn() { var r = root(); return !!r && r.getAttribute('data-approved') === '1'; }
  function toast(msg, kind) { if (App.toast) App.toast(msg, { kind: kind }); }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function urlFor(tpl, ym, appr) {
    var u = String(tpl || '').replace('__MONTH__', encodeURIComponent(ym));
    return appr ? u + (u.indexOf('?') >= 0 ? '&' : '?') + 'approved=1' : u;
  }
  function dayLabel(date) {
    var d = new Date(date + 'T12:00:00');
    return d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
  }
  function dayTpl(date) { var r = root(); return r ? $('template[data-cal-day-tpl="' + date + '"]', r) : null; }
  function dayCount(date) { var r = root(), c = r && $('.cal-day[data-date="' + date + '"]', r); return c ? parseInt(c.getAttribute('data-count'), 10) || 0 : 0; }
  /** The day's <template>: inline, or (a busy month: data-inline="0") fetched once and kept in the section. */
  function dayTemplate(date) {
    var t = dayTpl(date), r = root();
    if (t || !r || r.getAttribute('data-inline') === '1' || !dayCount(date) || !cfg.dayUrl) return Promise.resolve(t);
    var u = String(cfg.dayUrl).replace('__DATE__', encodeURIComponent(date));
    if (approvedOn()) u += (u.indexOf('?') >= 0 ? '&' : '?') + 'approved=1';
    return fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) { if (!res.ok) throw new Error('Could not load this day'); return res.text(); })
      .then(function (html) {
        var box = document.createElement('template'); box.innerHTML = html.trim();
        var got = $('template[data-cal-day-tpl]', box.content);
        if (got && root() === r) r.appendChild(got);
        return got;
      })
      .catch(function (err) { toast(err.message || 'Could not load this day', 'error'); return null; });
  }

  /** All clients: the post sheet loads the partial of the chip's own client (posts.js reads PostsConfig.partialUrl). */
  function pointSheetAt(slug) {
    if (!cfg.all || !slug || !window.PostsConfig || !App.urls) return;
    window.PostsConfig.partialUrl = App.urls.build('posts', { client: slug, post: '__ID__', partial: 1 });
  }

  /** The List link follows the month on screen (scoped: the list opens on the same month). */
  function syncViewSwitch(ym) {
    var a = $('[data-posts-view="list"]');
    var slug = document.body.getAttribute('data-client') || '';
    if (a && slug && App.urls) a.setAttribute('href', App.urls.build('posts', { client: slug, view: 'list', month: ym }));
  }

  /* ---- month fragment ------------------------------------------------- */
  C.load = function (ym, opts) {
    opts = opts || {};
    var appr = opts.approved != null ? !!opts.approved : approvedOn();
    var cur = root(); if (cur) cur.setAttribute('aria-busy', 'true');
    var seq = ++C._seq;
    return fetch(urlFor(cfg.partialUrl, ym, appr), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) {
        if (!res.ok) throw new Error('Could not load the calendar');
        return res.text();
      })
      .then(function (html) {
        if (seq !== C._seq) return null;   // a newer load won
        var tpl = document.createElement('template'); tpl.innerHTML = html.trim();
        var fresh = $('[data-cal]', tpl.content), now = root();
        if (!fresh || !now) throw new Error('Could not load the calendar');
        var keep = opts.keepSelection && now.getAttribute('data-month') === fresh.getAttribute('data-month') ? now.getAttribute('data-selected') : null;
        now.parentNode.replaceChild(fresh, now);
        if (keep) C.select(keep);
        var page = urlFor(cfg.pageUrl, ym, appr);
        try {
          if (opts.push) history.pushState({ calMonth: ym }, '', page);
          else if (opts.replace) history.replaceState({ calMonth: ym }, '', page);
        } catch (e) {}
        syncViewSwitch(ym);
        document.dispatchEvent(new CustomEvent('calendar:loaded', { detail: { month: ym } }));
        return fresh;
      })
      .catch(function (err) {
        toast(err && err.message ? err.message : 'Could not load the calendar', 'error');
        return null;
      })
      .then(function (out) { var r = root(); if (r) r.removeAttribute('aria-busy'); return out; });
  };
  C.reload = function () { return month() ? C.load(month(), { keepSelection: true }) : Promise.resolve(null); };

  /* ---- day sheet (desktop "+N more" / a day number) ------------------- */
  C.openDay = function (date) {
    return dayTemplate(date).then(function (tpl) {
      var sheet = $('#calDaySheet');
      if (!tpl || !sheet || !App.sheet) return null;
      return App.sheet.open(sheet, {
        title: tpl.getAttribute('data-label') || dayLabel(date),
        html: '<p class="cal-daysheet-sub">' + esc(tpl.getAttribute('data-sub') || '') + '</p>' + tpl.innerHTML
      });
    });
  };

  /* ---- phones: the selected day, listed under the grid ---------------- */
  C.select = function (date) {
    var r = root(); if (!r) return;
    r.setAttribute('data-selected', date);
    $$('.cal-day.is-selected', r).forEach(function (el) {
      el.classList.remove('is-selected');
      var b = $('.cal-daynum', el); if (b) b.setAttribute('aria-pressed', 'false');
    });
    var cell = $('.cal-day[data-date="' + date + '"]', r);
    if (cell) { cell.classList.add('is-selected'); var b2 = $('.cal-daynum', cell); if (b2) b2.setAttribute('aria-pressed', 'true'); }
    var title = $('[data-cal-agenda-title]', r), body = $('[data-cal-agenda-body]', r);
    if (title) title.textContent = dayLabel(date);
    return dayTemplate(date).then(function (tpl) {
      if (root() !== r || r.getAttribute('data-selected') !== date) return;   // another day / month since
      if (body) body.innerHTML = tpl ? tpl.innerHTML : '<div class="cal-agenda-empty">Nothing scheduled this day.</div>';
    });
  };

  /* ---- after a New post pop-up save (newpost.js) ---------------------- */
  C.afterSave = function (id, slug, msg) {
    if (!root() || !App.posts || !App.posts.open) return false;
    if (!cfg.all && (document.body.getAttribute('data-client') || '') !== (slug || '')) return false;
    C.reload().then(function () {
      pointSheetAt(slug);
      App.posts.open(id);
      toast(msg || 'Saved', 'success');
    });
    return true;
  };

  /* ---- wiring ---------------------------------------------------------- */
  // All clients: before posts.js opens the sheet (bubbling), point it at the chip's client (capture phase).
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-post-open][data-cal-client]');
    if (a) pointSheetAt(a.getAttribute('data-cal-client'));
  }, true);

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest) return;
    // month arrows + Today
    var go = t.closest('[data-cal-go]');
    if (go && root() && root().contains(go)) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      var ym = go.getAttribute('data-cal-go');
      var which = go.hasAttribute('data-cal-prev') ? '[data-cal-prev]' : (go.hasAttribute('data-cal-next') ? '[data-cal-next]' : '.cal-today');
      var focused = document.activeElement === go;
      C.load(ym, { push: ym !== month() }).then(function (fresh) {
        if (fresh && focused) { var again = $(which, fresh); if (again) again.focus({ preventScroll: true }); }
      });
      return;
    }
    // admin: Show approved, not yet scheduled
    var tog = t.closest('[data-cal-approved]');
    if (tog) {
      if (e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault();
      C.load(month(), { approved: !approvedOn(), replace: true, keepSelection: true }).then(function (fresh) {
        var again = fresh && $('[data-cal-approved]', fresh); if (again) again.focus({ preventScroll: true });
      });
      return;
    }
    var more = t.closest('[data-cal-more]');
    if (more) { e.preventDefault(); C.openDay(more.getAttribute('data-cal-more')); return; }
    var day = t.closest('[data-cal-day]');
    if (day) {
      e.preventDefault();
      var date = day.getAttribute('data-cal-day');
      if (PHONE.matches) {
        if (date.slice(0, 7) !== month()) {   // a muted day of the next / previous month: go there, that day selected
          C.load(date.slice(0, 7), { push: true }).then(function (fresh) { if (fresh) C.select(date); });
        } else C.select(date);
      } else if (dayCount(date)) C.openDay(date);
    }
  });

  // ← / → change the month (not while a sheet, a field or the New post pop-up has the keyboard)
  document.addEventListener('keydown', function (e) {
    if ((e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') || e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) return;
    if (!root() || (App.sheet && App.sheet.current) || (App.newPost && App.newPost.isOpen && App.newPost.isOpen())) return;
    var el = document.activeElement;
    if (el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || (el.closest && el.closest('[role="tablist"]')))) return;
    var btn = $(e.key === 'ArrowLeft' ? '[data-cal-prev]' : '[data-cal-next]', root());
    if (btn) { e.preventDefault(); C.load(btn.getAttribute('data-cal-go'), { push: true }); }
  });

  // Back / forward between months (posts.js owns the ?post= entries; its sheet close goes back to the month's entry).
  window.addEventListener('popstate', function (e) {
    if (!root() || (e.state && e.state.post)) return;
    var ym = e.state && e.state.calMonth;
    if (!ym) {
      var p = App.urls ? App.urls.parse(window.location.href).params : {};
      ym = /^\d{4}-\d{2}$/.test(p.month || '') ? p.month : (root().getAttribute('data-today') || '').slice(0, 7);
    }
    if (ym && ym !== month()) C.load(ym);
  });

  // Live: a change made in the post sheet redraws the month (one request, debounced).
  var timer = null;
  function soon() { clearTimeout(timer); timer = setTimeout(C.reload, 200); }
  ['posts:posted', 'posts:dated', 'posts:removed', 'posts:decided', 'posts:submitted'].forEach(function (name) {
    document.addEventListener(name, function (e) { if (!e.detail || e.detail.ok !== false) soon(); });
  });
})(window, document);
