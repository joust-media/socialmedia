/* =====================================================================
   Joust client portal — redo.js  (the Redo queue page, redo.php; admin only)

   App.redo
     .exportPack()      "Export redo pack": redo.php export_start {scope, since} → export_step until done → the
                        download (the same stepwise zip job as Manage → Export, export-lib.php)
     .remove(row)       "Remove": redo.php unmark {items: kind:id} — the row leaves, the count drops
     .replaceOne(row, file)   "Replace…": upload-chunk.php purpose=replace (pieces for a big file) or replace-image.php
                        → the image leaves the queue and goes back to To Review
     .replaceMany(files)      "Replace from folder" / "pick files": each image / video file → redo.php replace_match
                        (one request per file, in turn); the folder path (webkitRelativePath = the redo pack's own
                        Client/Tire/Series layout) settles files with the same name; a sheet lists what happened.
   Loads with `defer` before app.js; boots on 'app:ready'.
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function toast(msg, opts) { if (App.toast) App.toast(msg, opts); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var MEDIA = /\.(jpe?g|png|gif|webp|mp4|webm|mov)$/i;

  var redo = App.redo = {
    root: null, ep: '', scope: 'client', client: '', _busy: false, _row: null,

    init: function () {
      var root = $('[data-redo-page]');
      if (!root || root._redoInit) return;
      root._redoInit = true;
      this.root = root;
      this.ep = root.getAttribute('data-endpoint') || (App.urls ? App.urls.abs('redo.php') : 'redo.php');
      this.scope = root.getAttribute('data-scope') || 'client';
      this.client = root.getAttribute('data-client') || '';
      var self = this;
      var exp = $('[data-redo-export]', root); if (exp) exp.addEventListener('click', function () { self.exportPack(exp); });
      var folderBtn = $('[data-redo-folder]', root), folderIn = $('[data-redo-folder-input]', root);
      var filesBtn = $('[data-redo-files]', root), filesIn = $('[data-redo-files-input]', root);
      var oneIn = $('[data-redo-replace-input]', root);
      if (folderBtn && folderIn) folderBtn.addEventListener('click', function () { folderIn.value = ''; folderIn.click(); });
      if (filesBtn && filesIn) filesBtn.addEventListener('click', function () { filesIn.value = ''; filesIn.click(); });
      [folderIn, filesIn].forEach(function (inp) {
        if (inp) inp.addEventListener('change', function () { if (inp.files && inp.files.length) self.replaceMany(Array.prototype.slice.call(inp.files)); });
      });
      if (oneIn) oneIn.addEventListener('change', function () { if (oneIn.files && oneIn.files[0] && self._row) self.replaceOne(self._row, oneIn.files[0]); });
      root.addEventListener('click', function (e) {
        var row = e.target.closest('[data-redo-row]');
        if (!row) return;
        if (e.target.closest('[data-redo-remove]')) { e.preventDefault(); self.remove(row); }
        else if (e.target.closest('[data-redo-replace]') && oneIn) { e.preventDefault(); self._row = row; oneIn.value = ''; oneIn.click(); }
      });
    },

    rows: function () { return $$('[data-redo-row]', this.root); },
    findRow: function (kind, id) { return $('[data-redo-row="' + kind + ':' + id + '"]', this.root); },
    setCount: function (n) {
      var c = $('[data-redo-count]', this.root); if (c) c.textContent = String(Math.max(0, n));
      this.root.setAttribute('data-count', String(Math.max(0, n)));
    },
    /** The row leaves (fade), its group goes when empty, the count drops; the empty state when nothing is left. */
    dropRow: function (row) {
      if (!row || row._gone) return;
      row._gone = true;
      row.classList.add('ui-leave');
      var self = this;
      setTimeout(function () {
        var group = row.closest('[data-redo-group]');
        if (row.parentNode) row.parentNode.removeChild(row);
        if (group && !$('[data-redo-row]', group)) group.remove();
        self.setCount(self.rows().length);
        if (!self.rows().length) {
          var el = document.createElement('div');
          el.className = 'ui-empty rd-empty ui-enter'; el.setAttribute('data-redo-empty', '');
          el.innerHTML = '<p class="as-empty-title">Nothing to redo</p><p>Every image in the queue has been handled.</p>';
          self.root.appendChild(el);
          $$('[data-redo-export], [data-redo-folder]', self.root).forEach(function (b) { b.disabled = true; });
        }
      }, 240);
    },

    /* ---------------- Export redo pack ---------------- */
    exportPack: function (btn) {
      var self = this;
      if (this._busy) return;
      var since = $('[data-redo-since]', this.root);
      var label = $('[data-redo-export-label]', btn), was = label ? label.textContent : '';
      var say = function (t) { if (label) label.textContent = t; };
      var done = function (msg, kind) { self._busy = false; btn.disabled = false; btn.removeAttribute('aria-busy'); say(was); if (msg) toast(msg, { kind: kind || 'success', duration: 4000 }); };
      this._busy = true; btn.disabled = true; btn.setAttribute('aria-busy', 'true'); say('Preparing…');
      App.post(this.ep, { action: 'export_start', scope: this.scope, since: since && since.checked ? 1 : 0, client: this.client }).then(function (res) {
        if (!res.ok) { done(res.error || 'Could not start the export', 'error'); return; }
        var job = res.data.job, files = res.data.files, tries = 0;
        var step = function () {
          App.post(self.ep, { action: 'export_step', job: job, client: self.client }).then(function (r) {
            if (!r.ok) { if (r.status === 409 && tries++ < 5) { setTimeout(step, 800); return; } done(r.error || 'The export failed', 'error'); return; }
            var d = r.data || {};
            if (d.bytes) say('Zipping… ' + Math.min(99, Math.floor((d.bytes_done || 0) / d.bytes * 100)) + '%');
            if (!d.done) { step(); return; }
            self.root.setAttribute('data-exported-job', job);
            window.location.href = self.ep + (self.ep.indexOf('?') < 0 ? '?' : '&') + 'action=download&job=' + encodeURIComponent(job);
            // every row in the pack now reads "exported"; "only new" starts from here
            self.rows().forEach(function (row) {
              var meta = $('.rd-meta', row);
              if (meta && !$('[data-redo-exported]', row)) meta.insertAdjacentHTML('beforeend', '<span class="ui-pill ui-pill--nodot rd-exported" data-redo-exported>exported</span>');
            });
            var last = $('[data-redo-last]', self.root); if (last) last.textContent = '· last just now · 0 new';
            done('Downloading the redo pack (' + files + (files === 1 ? ' file' : ' files') + ')');
          });
        };
        step();
      });
    },

    /* ---------------- Remove from redo ---------------- */
    remove: function (row) {
      var self = this, btn = $('[data-redo-remove]', row);
      if (btn) btn.disabled = true;
      App.post(this.ep, { action: 'unmark', items: row.getAttribute('data-redo-row'), client: row.getAttribute('data-client') || '' }).then(function (res) {
        if (!res.ok) { if (btn) btn.disabled = false; toast(res.error || 'Could not remove', { kind: 'error' }); return; }
        self.dropRow(row);
        toast('Removed from redo', { kind: 'success' });
      });
    },

    /* ---------------- Replace one ---------------- */
    replaceOne: function (row, file) {
      var self = this, kind = row.getAttribute('data-kind'), id = row.getAttribute('data-id'), slug = row.getAttribute('data-client') || '';
      var chunk = App.chunkUpload && App.chunkUpload.upload ? App.chunkUpload : null;
      var ok = function () { self.dropRow(row); toast('Replaced — back to To Review for the client', { kind: 'success' }); };
      var fail = function (err) { toast((err && (err.error || err.message)) || 'Replace failed', { kind: 'error' }); };
      toast('Replacing…');
      if (chunk) {
        chunk.upload({
          endpoint: App.urls.abs('upload-chunk.php'), file: file, previews: true,
          fields: { purpose: 'replace', replace_kind: kind, replace_id: id, client: slug, actor: 'admin' },
          onProgress: function (p) { if (p.count > 1) toast('Replacing… ' + p.text); }
        }).promise.then(ok).catch(fail);
        return;
      }
      var fd = new FormData();
      fd.append('image_id', id); fd.append('image', file); fd.append('type', kind);
      fetch(App.urls.abs('replace-image.php'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.json().then(function (d) { if (!res.ok || !d || d.ok === false) throw (d || {}); return d; }); })
        .then(ok).catch(fail);
    },

    /* ---------------- Replace from folder ---------------- */
    replaceMany: function (files) {
      var self = this;
      if (this._busy) return;
      var list = files.filter(function (f) { return MEDIA.test(f.name) && f.name.charAt(0) !== '.'; });
      if (!list.length) { toast('No images or videos in that selection', { kind: 'error' }); return; }
      this._busy = true;
      var results = [], i = 0;
      var next = function () {
        if (i >= list.length) { self._busy = false; self.report(results); return; }
        var f = list[i++];
        toast('Replacing ' + i + ' of ' + list.length + '…', { duration: 60000 });
        var fd = new FormData();
        fd.append('action', 'replace_match'); fd.append('file', f); fd.append('path', f.webkitRelativePath || f.name);
        fd.append('scope', self.scope); fd.append('client', self.client);
        fetch(self.ep, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (res) { return res.text().then(function (t) { var d = null; try { d = JSON.parse(t); } catch (e) {} return { ok: res.ok && !!d && d.ok !== false, status: res.status, data: d || {} }; }); })
          .catch(function () { return { ok: false, status: 0, data: { error: 'Network error' } }; })
          .then(function (r) {
            results.push({ name: f.webkitRelativePath || f.name, ok: r.ok, data: r.data, status: r.status });
            if (r.ok) { var row = self.findRow(r.data.kind, r.data.id); if (row) self.dropRow(row); }
            next();
          });
      };
      next();
    },
    /** What "Replace from folder" did: a toast and a sheet listing every file (replaced / no match / two matches / error). */
    report: function (results) {
      var ok = results.filter(function (r) { return r.ok; }).length, bad = results.length - ok;
      toast(ok + ' replaced' + (bad ? ' · ' + bad + ' not matched' : ''), { kind: bad && !ok ? 'error' : 'success', duration: 4000 });
      if (!App.sheet) return;
      var html = '<div class="rd-report" data-redo-report><p class="rd-report-sum"><strong>' + ok + '</strong> replaced and back to To Review'
        + (bad ? ' · <strong>' + bad + '</strong> not matched' : '') + '.</p><ul class="rd-report-list" role="list">';
      results.forEach(function (r) {
        var why = r.ok ? 'Replaced' : (r.data && r.data.match === 'none' ? 'No queued image with this name'
                : (r.data && r.data.match === 'ambiguous' ? 'Several queued images have this name — drop the whole redo pack folder' : (r.data && r.data.error) || 'Failed'));
        html += '<li class="rd-report-item rd-report-item--' + (r.ok ? 'ok' : 'err') + '" data-redo-result="' + (r.ok ? 'ok' : (r.data && r.data.match) || 'error') + '">'
              + '<span class="rd-report-name">' + esc(r.name) + '</span><span class="rd-report-why">' + esc(why) + '</span></li>';
      });
      html += '</ul></div>';
      var sheet = $('#uiSheet');
      if (sheet) App.sheet.open(sheet, { title: 'Replace from folder', html: html, footer: '' });
    }
  };

  function boot() { if (boot._done) return; boot._done = true; redo.init(); }
  if (App._inited) boot();
  else document.addEventListener('app:ready', boot);
  if (document.readyState !== 'loading' && App._inited) boot();
})(window, document);
