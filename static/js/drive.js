/* =====================================================================
   Drive — storage view (drive.php)

   Overview:  the "N smaller clients" treemap tile opens the grouped list in a
              sheet (App.sheet); without JS the same list is a <details> below.
   Offboard:  window.DriveOffboard = {rows, clients, total, capped, perPage, scoreMax}
              → client-side filters (type / idle / client), sort (score default,
              size, idle), 50-per-page paging, "Export CSV" built from the
              rendered rows (name, path, client, size, parent link). The first
              page is server-rendered, so the table reads without JS.
   Nothing here posts anywhere — the page is read-only.
   ===================================================================== */
(function (window, document) {
  'use strict';
  // app.js loads after this file (both deferred): resolve App at call time, never at load
  function app() { return window.App || {}; }

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
    });
  }
  function fmtScore(n) {
    n = Math.round((+n || 0) * 10) / 10;
    return String(n);
  }

  /* ---------------------------------------------------------------- */
  /* Overview: smaller clients → sheet                                 */
  /* ---------------------------------------------------------------- */
  function initSmaller() {
    var tile = $('.drive-tile--group');
    var details = $('[data-drive-smaller]');
    if (!tile || !details) return;
    tile.addEventListener('click', function (e) {
      var App = app();
      if (!App.sheet || typeof App.sheet.open !== 'function') return;   // no sheet → the anchor jumps to the <details>
      e.preventDefault();
      var list = $('.drive-smaller-list', details);
      var title = $('summary', details);
      App.sheet.open('#uiSheet', {
        title: title ? title.textContent.trim() : 'Smaller clients',
        html: '<ul class="drive-smaller-list drive-smaller-list--sheet">' + (list ? list.innerHTML : '') + '</ul>'
      });
    });
  }

  /* ---------------------------------------------------------------- */
  /* Offboard list                                                     */
  /* ---------------------------------------------------------------- */
  function initOffboard() {
    var cfg = window.DriveOffboard;
    var root = $('[data-drive-offboard]');
    if (!cfg || !root || !Array.isArray(cfg.rows)) return;
    var tbody = $('[data-drive-tbody]', root);
    if (!tbody) return;

    var state = { type: 'all', idle: 180, client: '', sort: 'score', dir: 'desc', page: 1 };
    var perPage = cfg.perPage || 50;
    var scoreMax = +cfg.scoreMax || 0;
    var summary = $('[data-drive-summary]', root);
    var nomatch = $('[data-drive-nomatch]', root);
    var table = $('[data-drive-offboard-table]', root);
    var status = $('[data-drive-page-status]', root);
    var prev = $('[data-drive-page="prev"]', root);
    var next = $('[data-drive-page="next"]', root);
    var clientSel = $('[data-drive-filter="client"]', root);
    var filtered = cfg.rows.slice();

    function bytes(n) {
      n = +n || 0;
      var u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'], i = 0;
      while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
      if (i === 0) return Math.round(n) + ' B';
      var d = i >= 4 ? 2 : (n < 10 ? 1 : 0);
      var s = n.toFixed(d);
      if (d > 0) s = s.replace(/\.?0+$/, '');            // 1.50 → 1.5, never 940 → 94
      return s.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' ' + u[i];
    }

    function apply() {
      filtered = cfg.rows.filter(function (r) {
        if (state.type !== 'all' && r.type !== state.type) return false;
        if ((+r.idleDays || 0) < state.idle) return false;
        if (state.client !== '' && r.client !== state.client) return false;
        return true;
      });
      var key = state.sort === 'size' ? 'bytes' : (state.sort === 'idle' ? 'idleDays' : 'score');
      var dir = state.dir === 'asc' ? 1 : -1;
      filtered.sort(function (a, b) {
        var d = ((+a[key] || 0) - (+b[key] || 0)) * dir;
        if (d !== 0) return d;
        return ((+b.score || 0) - (+a.score || 0)) || String(a.name).localeCompare(String(b.name));
      });
      var pages = Math.max(1, Math.ceil(filtered.length / perPage));
      if (state.page > pages) state.page = pages;
      render(pages);
    }

    function rowHtml(r, rank) {
      var w = scoreMax > 0 ? Math.min(100, 100 * (+r.score || 0) / scoreMax) : 0;
      return '<tr class="drive-row" data-drive-row="' + esc(r.id) + '" data-type="' + esc(r.type) + '" data-client="' + esc(r.client) + '" data-idle="' + (+r.idleDays || 0) + '" data-bytes="' + (+r.bytes || 0) + '" data-score="' + esc(r.score) + '">'
        + '<td class="drive-td-rank">' + rank + '</td>'
        + '<td class="drive-td-file"><span class="drive-file-name">' + esc(r.name) + '</span><span class="drive-file-path">' + esc(r.path) + '</span></td>'
        + '<td class="drive-td-client">' + esc(r.clientName) + '</td>'
        + '<td class="drive-td-type">' + esc(r.typeLabel) + '</td>'
        + '<td class="drive-td-size">' + esc(r.size) + '</td>'
        + '<td class="drive-td-date">' + esc(r.modified) + '</td>'
        + '<td class="drive-td-date">' + (r.viewed ? esc(r.viewed) : '<span class="text-tertiary">never</span>') + '<span class="drive-file-idle">' + esc(r.idle) + ' idle</span></td>'
        + '<td class="drive-td-score"><span class="drive-score" aria-hidden="true"><i style="width:' + w.toFixed(1) + '%"></i></span><span class="drive-score-n">' + esc(fmtScore(r.score)) + '</span></td>'
        + '<td class="drive-td-open">' + (r.link && /^https:\/\/drive\.google\.com\//.test(r.link) ? '<a class="ui-btn ui-btn--sm ui-btn--gray" href="' + esc(r.link) + '" target="_blank" rel="noopener noreferrer">Open folder</a>' : '<span class="text-tertiary">—</span>') + '</td>'
        + '</tr>';
    }

    function render(pages) {
      var start = (state.page - 1) * perPage;
      var slice = filtered.slice(start, start + perPage);
      var html = '';
      for (var i = 0; i < slice.length; i++) html += rowHtml(slice[i], start + i + 1);
      tbody.innerHTML = html;
      var total = 0;
      for (var j = 0; j < filtered.length; j++) total += (+filtered[j].bytes || 0);
      if (summary) {
        summary.innerHTML = '<strong>' + filtered.length.toLocaleString() + '</strong> file' + (filtered.length === 1 ? '' : 's') + ' · <strong>' + esc(bytes(total)) + '</strong> you could get back'
          + (cfg.capped ? ' <span class="text-tertiary">(top ' + (+cfg.rows.length).toLocaleString() + ' of ' + (+cfg.total).toLocaleString() + ')</span>' : '');
      }
      if (nomatch) nomatch.hidden = filtered.length > 0;
      if (table) table.hidden = filtered.length === 0;
      if (status) status.textContent = 'Page ' + state.page + ' of ' + pages;
      if (prev) prev.disabled = state.page <= 1;
      if (next) next.disabled = state.page >= pages;
      table && table.setAttribute('data-drive-shown', String(slice.length));
      root.setAttribute('data-drive-filtered', String(filtered.length));
    }

    // Filters: segmented buttons (App.segmented fires segmented:change) + client select
    $$('.drive-filter-seg', root).forEach(function (seg, idx) {
      var which = idx === 0 ? 'type' : 'idle';
      seg.setAttribute('data-drive-filter', which);
      seg.addEventListener('segmented:change', function (e) {
        var v = e.detail && e.detail.value != null ? String(e.detail.value) : '';
        if (which === 'type') state.type = v || 'all'; else state.idle = parseInt(v, 10) || 180;
        state.page = 1; apply();
      });
      // Fallback when app.js is absent: toggle the active class ourselves
      seg.addEventListener('click', function (e) {
        var item = e.target.closest('.ui-segmented-item');
        if (!item || (app().segmented && app().segmented.select)) return;
        $$('.ui-segmented-item', seg).forEach(function (it) { it.classList.toggle('is-active', it === item); it.setAttribute('aria-selected', it === item ? 'true' : 'false'); });
        var v = item.dataset.value || '';
        if (which === 'type') state.type = v || 'all'; else state.idle = parseInt(v, 10) || 180;
        state.page = 1; apply();
      });
    });
    if (clientSel) clientSel.addEventListener('change', function () { state.client = clientSel.value; state.page = 1; apply(); });

    // Sort
    $$('[data-drive-sort]', root).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.dataset.driveSort;
        if (state.sort === key) state.dir = state.dir === 'desc' ? 'asc' : 'desc';
        else { state.sort = key; state.dir = 'desc'; }
        $$('[data-drive-sort]', root).forEach(function (b) {
          var active = b === btn;
          b.classList.toggle('is-active', active);
          b.setAttribute('aria-sort', active ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none');
        });
        state.page = 1; apply();
      });
    });

    // Paging
    if (prev) prev.addEventListener('click', function () { if (state.page > 1) { state.page--; apply(); window.scrollTo({ top: root.offsetTop - 12, behavior: app().reducedMotion && app().reducedMotion() ? 'auto' : 'smooth' }); } });
    if (next) next.addEventListener('click', function () { state.page++; apply(); window.scrollTo({ top: root.offsetTop - 12, behavior: app().reducedMotion && app().reducedMotion() ? 'auto' : 'smooth' }); });

    // CSV from the rendered (filtered + sorted) rows — every page, not just the visible one
    var exportBtn = $('[data-drive-export]', root);
    if (exportBtn) exportBtn.addEventListener('click', function () {
      var q = function (v) { v = String(v == null ? '' : v); return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
      var lines = ['name,path,client,size,parent_link'];
      for (var i = 0; i < filtered.length; i++) {
        var r = filtered[i];
        lines.push([q(r.name), q(r.path), q(r.clientName), q(r.size), q(r.link || '')].join(','));
      }
      var blob = new Blob(['﻿' + lines.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' });
      var a = document.createElement('a');
      var stamp = (cfg.snapshot || '').replace(/[^0-9]/g, '').slice(0, 8) || 'snapshot';
      a.href = URL.createObjectURL(blob);
      a.download = 'drive-offboard-' + stamp + '.csv';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      if (app().toast) app().toast(filtered.length.toLocaleString() + ' rows exported');
    });

    root.setAttribute('data-drive-js', '1');
    apply();
  }

  function init() {
    initSmaller();
    initOffboard();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})(window, document);
