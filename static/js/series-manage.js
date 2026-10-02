/* =====================================================================
   Manage series (admin only) — Assets → a tire → ⋯ → Manage series.
   The sheet #seriesManageSheet (assets.php) holds everything that used to live in the Studio Renders
   tab, for the tire on screen:
     - the series list: rename · Google Drive link · move up / down · delete (tire-status.php
       series_rename / series_drive / series_reorder / series_delete)
     - Add a series: name + optional Google Drive link (tire-status.php series_create)
     - the FTP folder (copy), Rescan folders (tire-status.php rescan) and Repair server rules
       (tire-upload.php action=repair_media)
   Config: window.SeriesManageConfig = {tire:{id,name,folder}, series:[{id,name,slug,folder,drive_url,counts}],
   status (tire-status.php), repair (tire-upload.php?client=…), driveOn, seriesUrl (…&series=__SERIES__), open}.
   Any change marks the page dirty; closing the sheet then reloads it so the chips and counts follow.
   Opens on load with &manage=series (the studio.php?tab=renders redirect and Manage → Tools links).
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var cfg = window.SeriesManageConfig;
  if (!cfg || !cfg.tire) return;
  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var toast = function (msg, kind, ms) { if (App.toast) App.toast(msg, { kind: kind, duration: ms }); };
  var DRIVE_RE = /^https:\/\/(www\.)?(drive\.google\.com|docs\.google\.com|photos\.google\.com|photos\.app\.goo\.gl)\//i;
  var ICON = {
    up:   '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 15 7-7 7 7"/></svg>',
    down: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 9 7 7 7-7"/></svg>'
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function busy(btn, on, label) {
    if (!btn) return;
    btn.disabled = !!on;
    if (on) { btn.setAttribute('aria-busy', 'true'); if (label) { btn.dataset.label = btn.textContent; btn.textContent = label; } }
    else { btn.removeAttribute('aria-busy'); if (btn.dataset.label) { btn.textContent = btn.dataset.label; delete btn.dataset.label; } }
  }

  var SM = {
    sheet: null, list: null, dirty: false,
    tire: cfg.tire, series: cfg.series || [],

    init: function () {
      this.sheet = document.getElementById('seriesManageSheet');
      if (!this.sheet) return;
      this.list = $('[data-sm-list]', this.sheet);
      var self = this;
      this.sheet.addEventListener('click', function (e) {
        var t = e.target;
        if (t.closest('[data-sm-copy]')) { self.copyFolder(); return; }
        if (t.closest('[data-sm-rescan]')) { self.rescan(t.closest('[data-sm-rescan]')); return; }
        if (t.closest('[data-sm-repair]')) { self.repair(t.closest('[data-sm-repair]')); return; }
        var row = t.closest('[data-sm-row]');
        if (!row) return;
        if (t.closest('[data-sm-up]')) self.move(row, -1);
        else if (t.closest('[data-sm-down]')) self.move(row, 1);
        else if (t.closest('[data-sm-delete]')) self.remove(row);
        else if (t.closest('[data-sm-rename]')) self.rename(row);
        else if (t.closest('[data-sm-drive-save]')) self.saveDrive(row);
      });
      this.sheet.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var row = e.target.closest('[data-sm-row]');
        if (row && e.target.matches('[data-sm-name]')) { e.preventDefault(); self.rename(row); }
        if (row && e.target.matches('[data-sm-drive]')) { e.preventDefault(); self.saveDrive(row); }
      });
      this.sheet.addEventListener('input', function (e) { if (e.target.matches('[data-sm-drive], [data-sm-new-drive]')) e.target.removeAttribute('aria-invalid'); });
      var form = $('[data-sm-add]', this.sheet);
      if (form) form.addEventListener('submit', function (e) { e.preventDefault(); self.add(form); });
      this.sheet.addEventListener('sheet:close', function () { if (self.dirty) window.location.reload(); });
      document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-series-manage]');
        if (!opener) return;
        e.preventDefault();
        var menu = opener.closest('[data-series-menu-root]');
        if (menu) { var l = $('[data-series-menu-list]', menu), b = $('[data-series-menu]', menu); if (l) l.hidden = true; if (b) b.setAttribute('aria-expanded', 'false'); }
        self.open();
      });
      this.render();
      if (cfg.open) {
        try { var u = new URL(window.location.href); u.searchParams.delete('manage'); history.replaceState(history.state, '', u.pathname + u.search + u.hash); } catch (err) {}
        var go = function () { self.open(); };
        if (App._inited) go(); else document.addEventListener('app:ready', go, { once: true });
      }
    },

    open: function () { if (App.sheet) App.sheet.open(this.sheet); },
    changed: function () { this.dirty = true; },
    byId: function (id) { for (var i = 0; i < this.series.length; i++) if (this.series[i].id === id) return this.series[i]; return null; },

    render: function () {
      var self = this, n = this.series.length;
      this.list.innerHTML = this.series.map(function (s, i) {
        var c = s.counts || {};
        var line = (c.pending || 0) + ' to review · ' + (c.approved || 0) + ' approved' + ((c.denied || 0) ? ' · ' + c.denied + ' needs changes' : '') + ' · ' + (c.total || 0) + (c.total === 1 ? ' file' : ' files');
        var drive = cfg.driveOn
          ? '<div class="sm-drive' + (s.drive_url ? ' is-set' : '') + '">'
            + '<input class="ui-input" type="url" maxlength="512" inputmode="url" autocomplete="off" spellcheck="false" value="' + esc(s.drive_url || '') + '" placeholder="Google Drive link (optional)" data-sm-drive aria-label="Google Drive link for ' + esc(s.name) + '">'
            + '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-sm-drive-save>Save link</button>'
            + (s.drive_url ? '<a class="ui-btn ui-btn--plain ui-btn--sm" href="' + esc(s.drive_url) + '" target="_blank" rel="noopener noreferrer">Open</a>' : '')
            + '</div>'
          : '';
        return '<li class="sm-row" data-sm-row="' + s.id + '">'
          + '<div class="sm-main"><input class="ui-input" type="text" maxlength="80" value="' + esc(s.name) + '" data-sm-name aria-label="Series name">'
          + '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-sm-rename>Rename</button></div>'
          + '<div class="sm-meta text-secondary">' + esc(line) + (s.folder ? ' · <code>' + esc(s.folder) + '/</code>' : '') + '</div>'
          + drive
          + '<div class="sm-ctl">'
          + '<a class="ui-btn ui-btn--plain ui-btn--sm" href="' + esc(String(cfg.seriesUrl || '').replace('__SERIES__', String(s.id))) + '">Open</a>'
          + '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-sm-up aria-label="Move ' + esc(s.name) + ' up"' + (i === 0 ? ' disabled' : '') + '>' + ICON.up + '</button>'
          + '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-sm-down aria-label="Move ' + esc(s.name) + ' down"' + (i === n - 1 ? ' disabled' : '') + '>' + ICON.down + '</button>'
          + '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm sm-danger" data-sm-delete>Delete</button>'
          + '</div></li>';
      }).join('');
      var empty = $('[data-sm-empty]', this.sheet); if (empty) empty.hidden = n > 0;
      var count = $('[data-sm-count]', this.sheet); if (count) count.textContent = n + (n === 1 ? ' series' : ' series');
    },

    rename: function (row) {
      var self = this, id = parseInt(row.getAttribute('data-sm-row'), 10), s = this.byId(id);
      var input = $('[data-sm-name]', row), name = (input.value || '').trim();
      if (!s || !name || name === s.name) return;
      var btn = $('[data-sm-rename]', row); busy(btn, true);
      App.post(cfg.status, { action: 'series_rename', series_id: id, name: name, actor: App.actor }).then(function (res) {
        busy(btn, false);
        if (!res.ok) { toast(res.error || 'Could not rename', 'error'); input.value = s.name; return; }
        s.name = (res.data && res.data.series && res.data.series.name) || name;
        self.changed(); self.render();
        toast('Series renamed', 'success');
      });
    },

    /** tire-status.php series_drive: '' removes the link; the server validates the host too. */
    saveDrive: function (row) {
      var self = this, id = parseInt(row.getAttribute('data-sm-row'), 10), s = this.byId(id);
      var input = $('[data-sm-drive]', row), url = input ? (input.value || '').trim() : '';
      if (!s || !input || url === (s.drive_url || '')) return;
      if (url && !DRIVE_RE.test(url)) { input.setAttribute('aria-invalid', 'true'); input.focus(); toast('Enter a Google Drive share link (https://drive.google.com/…)', 'error', 5000); return; }
      var btn = $('[data-sm-drive-save]', row); busy(btn, true);
      App.post(cfg.status, { action: 'series_drive', series_id: id, drive_url: url, actor: App.actor }).then(function (res) {
        busy(btn, false);
        if (!res.ok) { input.setAttribute('aria-invalid', 'true'); input.focus(); toast(res.error || 'Could not save the Google Drive link', 'error', 6000); return; }
        s.drive_url = (res.data && res.data.series && res.data.series.drive_url) || (url || null);
        self.changed(); self.render();
        toast(s.drive_url ? 'Google Drive link saved' : 'Google Drive link removed', 'success');
      });
    },

    move: function (row, dir) {
      var self = this, id = parseInt(row.getAttribute('data-sm-row'), 10), i = -1;
      this.series.forEach(function (s, k) { if (s.id === id) i = k; });
      var j = i + dir;
      if (i < 0 || j < 0 || j >= this.series.length) return;
      var moved = this.series.splice(i, 1)[0]; this.series.splice(j, 0, moved);
      this.render();
      var params = { action: 'series_reorder', tire_id: this.tire.id, actor: App.actor };
      this.series.forEach(function (s, k) { params['ids[' + k + ']'] = s.id; });
      App.post(cfg.status, params).then(function (res) {
        if (res.ok) { self.changed(); var b = $('[data-sm-row="' + id + '"] ' + (dir < 0 ? '[data-sm-up]' : '[data-sm-down]'), self.list); if (b && !b.disabled) b.focus(); return; }
        var back = self.series.splice(j, 1)[0]; self.series.splice(i, 0, back);   // roll back
        self.render();
        toast(res.error || 'Could not reorder', 'error');
      });
    },

    remove: function (row) {
      var self = this, id = parseInt(row.getAttribute('data-sm-row'), 10), s = this.byId(id);
      if (!s) return;
      if (!window.confirm('Remove “' + s.name + '” (' + ((s.counts && s.counts.total) || 0) + ' files) from ' + this.tire.name + '? The client will no longer see it.')) return;
      var files = window.confirm('Also delete the files on disk?\n\nOK = delete the files too · Cancel = keep them in ' + (this.tire.folder || 'the tire folder') + '/' + (s.folder || s.slug || ''));
      App.post(cfg.status, { action: 'series_delete', series_id: id, delete_files: files ? 1 : 0, actor: App.actor }).then(function (res) {
        if (!res.ok) { toast(res.error || 'Could not delete', 'error'); return; }
        self.series = self.series.filter(function (x) { return x.id !== id; });
        self.changed(); self.render();
        toast('Series deleted', 'success');
      });
    },

    /** Add a series (name + optional Google Drive link) → tire-status.php series_create. */
    add: function (form) {
      var self = this, nameEl = $('[data-sm-new-name]', form), driveEl = $('[data-sm-new-drive]', form);
      var name = (nameEl.value || '').trim(), url = driveEl ? (driveEl.value || '').trim() : '';
      if (!name) { nameEl.focus(); toast('Name the new series', 'error'); return; }
      if (url && !DRIVE_RE.test(url)) { driveEl.setAttribute('aria-invalid', 'true'); driveEl.focus(); toast('Enter a Google Drive share link (https://drive.google.com/…)', 'error', 5000); return; }
      var btn = $('button[type="submit"]', form); busy(btn, true);
      var params = { action: 'series_create', tire_id: this.tire.id, name: name, actor: App.actor };
      if (url) params.drive_url = url;
      App.post(cfg.status, params).then(function (res) {
        busy(btn, false);
        if (!res.ok) { toast(res.error || 'Could not add the series', 'error', 5000); return; }
        var s = (res.data && res.data.series) || {};
        self.series.push({ id: parseInt(s.id, 10), name: s.name || name, slug: s.slug || '', folder: s.folder || '', drive_url: s.drive_url || null,
                           counts: { pending: 0, approved: 0, denied: 0, total: 0 } });
        form.reset();
        self.changed(); self.render();
        toast('Series added', 'success');
      });
    },

    copyFolder: function () {
      var code = $('[data-sm-folder]', this.sheet), text = code ? code.textContent : '';
      if (!text) return;
      var done = function () { toast('Folder path copied', 'success'); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy the folder path', text); });
      else window.prompt('Copy the folder path', text);
    },

    /** Rescan media/tires/<tire>/ (new sub-folders → series, new files → To Review): tire-status.php action=rescan. */
    rescan: function (btn) {
      var self = this;
      busy(btn, true, 'Rescanning…');
      App.post(cfg.status, { action: 'rescan', tire_id: this.tire.id, actor: App.actor }).then(function (res) {
        busy(btn, false);
        if (!res.ok) { toast(res.error || 'Rescan failed', 'error'); return; }
        var d = res.data || {}, added = parseInt(d.added, 10) || 0, made = parseInt(d.new_series, 10) || 0;
        toast(added + ' new file' + (added === 1 ? '' : 's') + (made ? ' · ' + made + ' new series' : '') + ' found', 'success');
        if (added || made) self.changed();
      });
    },

    /** tire-upload.php action=repair_media: media/tires/.htaccess rewritten when old / missing, every render readable. */
    repair: function (btn) {
      busy(btn, true, 'Repairing…');
      App.post(cfg.repair, { action: 'repair_media', actor: App.actor }).then(function (res) {
        busy(btn, false);
        var d = res.data || {};
        if (res.ok) toast(d.summary || 'Server rules repaired', 'success');
        else toast(res.error || 'Repair failed', 'error');
      });
    }
  };

  App.seriesManage = SM;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { SM.init(); });
  else SM.init();
})(window, document);
