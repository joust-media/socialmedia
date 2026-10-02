/* =====================================================================
   Manage (admin only; manage.php, add-email.php) — extends the Foundation `App` (app.js loads first).
   The name is historical: this file served the Studio hub, which became Manage. Tire series management
   (the old Renders tab) moved to static/js/series-manage.js (Assets → a tire → Manage series).

   App.studio.export(root)   Manage → Export: scope + include options → live estimate
                             (export.php action=estimate), Build = start then
                             step until done (progress by bytes, ETA, Cancel),
                             Download (GET action=download, resumable), and the
                             Recent exports list (Continue / Download / Delete).
   App.studio.linkTags(text) escape + wrap #tags in .ig-tag (same regex as posts.js).
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var cfg = window.StudioConfig || {};
  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var toast = function (msg, opts) { if (App.toast) App.toast(msg, opts); else if (msg) window.alert(msg); };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  /* Same transformation as posts.js linkTags(): escape → wrap #tags → newlines. */
  function linkTags(text) {
    var safe = esc(text);
    try {
      safe = safe.replace(/(^|[\s(])(#[\p{L}\p{N}_]+)/gu, '$1<span class="ig-tag">$2</span>');
    } catch (e) {
      safe = safe.replace(/(^|[\s(])(#[\w]+)/g, '$1<span class="ig-tag">$2</span>');
    }
    return safe.replace(/\r?\n/g, '<br>');
  }

  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  /* "Wednesday, Sep 2 · 10:35 PM" — same shape as pdFormatWhen(). Treats the value as wall-clock. */
  function formatWhen(iso) {
    if (!iso) return 'Date to be confirmed';
    var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
    if (!m) return 'Date to be confirmed';
    var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    if (isNaN(d.getTime())) return 'Date to be confirmed';
    var h = d.getHours(), ampm = h >= 12 ? 'PM' : 'AM'; h = h % 12; if (h === 0) h = 12;
    var min = String(d.getMinutes()); if (min.length < 2) min = '0' + min;
    return DAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ' · ' + h + ':' + min + ' ' + ampm;
  }

  var ICON = {
    download: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5v11.5"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4.5 16v2a2.5 2.5 0 0 0 2.5 2.5h10a2.5 2.5 0 0 0 2.5-2.5v-2"/></svg>'
  };

  /* ================================================================== */
  /* Export: approved assets → one zip, built stepwise on the server    */
  /* ================================================================== */
  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n >= 1024 * 1024 * 1024) return (Math.round(n / (1024 * 1024 * 1024) * 100) / 100) + ' GB';
    if (n >= 1024 * 1024) return (Math.round(n / (1024 * 1024) * 10) / 10) + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' B';
  }
  function fmtEta(sec) {
    if (!isFinite(sec) || sec < 0) return '';
    if (sec < 60) return Math.max(1, Math.round(sec)) + ' s left';
    if (sec < 3600) return Math.round(sec / 60) + ' min left';
    return (Math.round(sec / 360) / 10) + ' h left';
  }
  function fmtAgo(unix) {
    var d = Math.max(0, Math.round(Date.now() / 1000 - unix));
    if (d < 60) return 'just now';
    if (d < 3600) return Math.round(d / 60) + ' min ago';
    if (d < 86400) return Math.round(d / 3600) + ' h ago';
    return Math.round(d / 86400) + ' d ago';
  }
  /** Studio → Export. Options → live estimate (export.php action=estimate) → Build (start, then step until done,
   *  progress by bytes with an ETA) → Download (GET action=download, streamed with Range support) · Recent exports (list). */
  function Export(root) {
    var self = this, xc = cfg.export || {};
    this.root = root; this.xc = xc;
    this.endpoint = root.dataset.endpoint || xc.endpoint || 'export.php';
    this.zipOn = root.dataset.zip !== '0' && xc.zip !== false;
    this.tires = xc.tires || [];
    this.tireSel = $('[data-export-tire]', root); this.seriesSel = $('[data-export-series]', root);
    this.estimateEl = $('[data-export-estimate]', root); this.videoSize = $('[data-export-video-size]', root);
    this.buildBtn = $('[data-export-build]', root); this.manifestLink = $('[data-export-manifest]', root);
    this.progress = $('[data-export-progress]', root); this.fill = $('[data-export-fill]', root); this.progressText = $('[data-export-progress-text]', root);
    this.done = $('[data-export-done]', root); this.doneText = $('[data-export-done-text]', root); this.downloadLink = $('[data-export-download]', root);
    this.recent = $('[data-export-recent]', root); this.recentEmpty = $('[data-export-recent-empty]', root);
    this.job = null; this.building = false; this.cancelled = false; this.estimateTimer = null; this.estimateSeq = 0; this.lastEstimate = null;
    root.addEventListener('change', function (e) {
      if (e.target.matches('[data-export-scope]')) { self.syncScope(); if (e.target.value !== 'series' && self.seriesSel) self.seriesSel.value = ''; }
      if (e.target.matches('[data-export-tire]')) { self.syncSeries(); }
      if (e.target.closest('[data-export-form]')) self.scheduleEstimate();
    });
    root.addEventListener('click', function (e) {
      if (e.target.closest('[data-export-build]')) { self.build(); return; }
      if (e.target.closest('[data-export-cancel]')) { self.cancel(); return; }
      if (e.target.closest('[data-export-another]')) { self.reset(); return; }
      var del = e.target.closest('[data-export-delete]');
      if (del) { self.deleteJob(del.dataset.exportDelete, del); return; }
      var resume = e.target.closest('[data-export-resume]');
      if (resume) { self.resume(resume.dataset.exportResume); }
    });
    var form = $('[data-export-form]', root);
    if (form) form.addEventListener('submit', function (e) { e.preventDefault(); self.build(); });
    this.syncSeries(true);
    this.syncScope();
    // app.js (App.post) is a deferred script that comes AFTER studio.js, so the first requests wait for DOMContentLoaded.
    var first = function () { self.estimate(); self.loadRecent(); };
    if (App.post) first();
    else if (document.readyState === 'loading' || document.readyState === 'interactive') document.addEventListener('DOMContentLoaded', first, { once: true });
    else setTimeout(first, 0);
  }
  Export.prototype.scope = function () { var r = $('[data-export-scope]:checked', this.root); return r ? r.value : 'all'; };
  Export.prototype.tire = function () {
    var id = this.tireSel ? parseInt(this.tireSel.value, 10) : 0;
    for (var i = 0; i < this.tires.length; i++) if (this.tires[i].id === id) return this.tires[i];
    return this.tires[0] || null;
  };
  /** Series <select> for the chosen tire (approved counts shown); the URL's &series= wins the first time. */
  Export.prototype.syncSeries = function (initial) {
    if (!this.seriesSel) return;
    var tire = this.tire(), want = initial ? (parseInt(this.xc.series, 10) || 0) : 0, list = tire ? (tire.series || []) : [];
    this.seriesSel.innerHTML = list.map(function (s) {
      return '<option value="' + s.id + '">' + esc(s.name) + (s.approved !== undefined ? ' · ' + s.approved + ' approved' : '') + '</option>';
    }).join('') || '<option value="">No series</option>';
    if (want && list.some(function (s) { return s.id === want; })) this.seriesSel.value = String(want);
    var seriesRadio = $('[data-export-scope][value="series"]', this.root);
    if (seriesRadio) { seriesRadio.disabled = !list.length; if (!list.length && seriesRadio.checked) { var tr = $('[data-export-scope][value="tire"]', this.root); if (tr) tr.checked = true; } }
  };
  /** Enable the pickers the scope needs; the Library box only applies to "All approved". */
  Export.prototype.syncScope = function () {
    var scope = this.scope(), lib = $('[data-export-inc="library"]', this.root), ref = $('[data-export-inc="reference"]', this.root);
    if (this.tireSel) this.tireSel.disabled = scope === 'all';
    if (this.seriesSel) this.seriesSel.disabled = scope !== 'series';
    if (lib) lib.disabled = scope !== 'all';
    if (ref) ref.disabled = scope === 'series';
    if (this.manifestLink) this.manifestLink.href = this.endpoint + '&action=manifest&' + this.query();
  };
  Export.prototype.options = function () {
    var o = { scope: this.scope() }, self = this;
    if (o.scope !== 'all' && this.tireSel) o.tire_id = this.tireSel.value;
    if (o.scope === 'series' && this.seriesSel) o.series_id = this.seriesSel.value;
    ['photos', 'videos', 'reference', 'library'].forEach(function (k) { var el = $('[data-export-inc="' + k + '"]', self.root); o[k] = el && el.checked ? 1 : 0; });
    return o;
  };
  Export.prototype.query = function () {
    var o = this.options(), q = [];
    Object.keys(o).forEach(function (k) { if (o[k] !== undefined && o[k] !== '') q.push(encodeURIComponent(k) + '=' + encodeURIComponent(o[k])); });
    return q.join('&');
  };
  Export.prototype.scheduleEstimate = function () {
    var self = this;
    clearTimeout(this.estimateTimer);
    this.estimateTimer = setTimeout(function () { self.estimate(); }, 250);
  };
  Export.prototype.estimate = function () {
    var self = this, seq = ++this.estimateSeq, o = this.options();
    if (this.estimateEl) { this.estimateEl.textContent = 'Counting…'; this.estimateEl.classList.remove('is-error'); }
    o.action = 'estimate';
    App.post(this.endpoint, o).then(function (res) {
      if (seq !== self.estimateSeq) return;
      if (!res.ok) { self.lastEstimate = null; if (self.estimateEl) { self.estimateEl.textContent = res.error || 'Could not count the files'; self.estimateEl.classList.add('is-error'); } if (self.buildBtn) self.buildBtn.disabled = true; return; }
      var d = res.data || {}; self.lastEstimate = d;
      var c = d.counts || {}, parts = [];
      parts.push(d.files + (d.files === 1 ? ' file' : ' files') + ' · ' + fmtBytes(d.bytes));
      var detail = [];
      if (c.photos) detail.push(c.photos + ' photo' + (c.photos === 1 ? '' : 's'));
      if (c.videos) detail.push(c.videos + ' video' + (c.videos === 1 ? '' : 's'));
      if (c.reference) detail.push(c.reference + ' reference');
      if (c.library) detail.push(c.library + ' library');
      if (detail.length) parts.push(detail.join(' · '));
      var text = parts.join(' — ');
      if (d.warnings && d.warnings.length) text += ' · ' + d.warnings.join(' · ');
      if (self.estimateEl) { self.estimateEl.textContent = text; self.estimateEl.classList.toggle('is-error', !!d.over_cap); }
      if (self.buildBtn) self.buildBtn.disabled = !d.files || !!d.over_cap;
      self.videoEstimate();
    });
    // The videos line shows what ticking the box would add — one extra count with videos on (only when they are off).
    this.videoEstimate = function () {
      if (!self.videoSize) return;
      var vo = self.options();
      if (vo.videos) { self.videoSize.textContent = ''; return; }
      vo.videos = 1; vo.photos = 0; vo.action = 'estimate';
      var vseq = seq;
      App.post(self.endpoint, vo).then(function (r) {
        if (vseq !== self.estimateSeq || !r.ok) return;
        var vd = r.data || {}, vc = vd.counts || {};
        self.videoSize.textContent = vc.videos ? '(' + vc.videos + ' · ' + fmtBytes(vd.video_bytes) + ')' : '(none)';
      });
    };
  };
  Export.prototype.setBusy = function (on) {
    this.building = on;
    $$('[data-export-form] input, [data-export-form] select', this.root).forEach(function (el) { if (on) { el.dataset.wasDisabled = el.disabled ? '1' : ''; el.disabled = true; } else if (el.dataset.wasDisabled !== undefined) { el.disabled = el.dataset.wasDisabled === '1'; delete el.dataset.wasDisabled; } });
    if (!on) this.syncScope();
    if (this.buildBtn) { this.buildBtn.disabled = on; this.buildBtn.hidden = on; }
    if (this.manifestLink) this.manifestLink.hidden = on;
  };
  Export.prototype.showProgress = function (bytesDone, bytes, added, files, startedAt) {
    if (this.progress) this.progress.hidden = false;
    var pct = bytes > 0 ? Math.min(100, Math.round(bytesDone / bytes * 100)) : (files ? Math.round(added / files * 100) : 0);
    if (this.fill) this.fill.style.width = pct + '%';
    var text = added + ' of ' + files + ' files · ' + fmtBytes(bytesDone) + ' of ' + fmtBytes(bytes);
    var elapsed = (Date.now() - startedAt) / 1000;
    if (bytesDone > 0 && elapsed > 2 && bytesDone < bytes) text += ' · ' + fmtEta((bytes - bytesDone) / (bytesDone / elapsed));
    if (this.progressText) this.progressText.textContent = text;
  };
  Export.prototype.build = function () {
    var self = this;
    if (this.building || !this.zipOn) return;
    if (this.lastEstimate && this.lastEstimate.over_cap) { toast('Over the size limit — export one tire at a time', { kind: 'error' }); return; }
    this.cancelled = false;
    this.setBusy(true);
    if (this.done) this.done.hidden = true;
    if (this.progress) this.progress.hidden = false;
    if (this.fill) this.fill.style.width = '0%';
    if (this.progressText) this.progressText.textContent = 'Listing the approved files…';
    var o = this.options(); o.action = 'start';
    App.post(this.endpoint, o).then(function (res) {
      if (!res.ok) { self.fail(res.error || 'Could not start the export'); return; }
      var d = res.data || {};
      self.job = { job: d.job, files: d.files, bytes: d.bytes, filename: d.filename, label: d.label, startedAt: Date.now() };
      self.showProgress(0, d.bytes, 0, d.files, self.job.startedAt);
      self.loop();
    });
  };
  /** step → step → … until done (a 409 "already running" or a network blip is retried a few times). */
  Export.prototype.loop = function () {
    var self = this, job = this.job, retries = 0;
    if (!job) return;
    var step = function () {
      if (self.cancelled || self.job !== job) return;
      App.post(self.endpoint, { action: 'step', job: job.job }).then(function (res) {
        if (self.cancelled || self.job !== job) return;
        if (!res.ok) {
          if ((res.status === 409 || res.status === 0 || res.status >= 500) && retries < 5) { retries++; setTimeout(step, 1500 * retries); return; }
          self.fail(res.error || 'The export stopped'); return;
        }
        retries = 0;
        var d = res.data || {};
        self.showProgress(d.bytes_done, d.bytes, d.added, d.files, job.startedAt);
        if (d.done) { self.finish(d); return; }
        setTimeout(step, 50);
      });
    };
    step();
  };
  Export.prototype.finish = function (d) {
    var job = this.job;
    this.setBusy(false);
    if (this.progress) this.progress.hidden = true;
    if (this.done) this.done.hidden = false;
    if (this.downloadLink) { this.downloadLink.href = this.endpoint + '&action=download&job=' + encodeURIComponent(job.job); this.downloadLink.setAttribute('download', d.filename || job.filename || 'export.zip'); }
    if (this.doneText) this.doneText.textContent = (job.label ? job.label + ' — ' : '') + d.added + (d.added === 1 ? ' file' : ' files') + ' · ' + fmtBytes(d.zip_bytes || d.bytes) + (d.skipped ? ' · ' + d.skipped + ' missing on disk, listed in the manifest' : '') + '. Ready for 24 hours.';
    toast('Export ready', { kind: 'success' });
    this.loadRecent();
  };
  Export.prototype.fail = function (msg) {
    this.setBusy(false);
    if (this.progress) this.progress.hidden = true;
    toast(msg, { kind: 'error' });
    this.job = null;
    this.loadRecent();
  };
  Export.prototype.cancel = function () {
    var self = this, job = this.job;
    this.cancelled = true;
    this.setBusy(false);
    if (this.progress) this.progress.hidden = true;
    this.job = null;
    if (job) App.post(this.endpoint, { action: 'cancel', job: job.job }).then(function () { self.loadRecent(); });
  };
  Export.prototype.reset = function () {
    if (this.done) this.done.hidden = true;
    this.job = null;
    this.estimate();
  };
  /** Continue an unfinished job from the Recent list (e.g. after the tab was closed). */
  Export.prototype.resume = function (id) {
    var self = this;
    if (this.building || !id) return;
    App.post(this.endpoint, { action: 'status', job: id }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Export not found', { kind: 'error' }); self.loadRecent(); return; }
      var d = res.data || {};
      self.cancelled = false;
      self.setBusy(true);
      if (self.done) self.done.hidden = true;
      self.job = { job: id, files: d.files, bytes: d.bytes, filename: d.filename, label: d.label, startedAt: Date.now() - 1 };
      self.showProgress(d.bytes_done, d.bytes, d.added, d.files, self.job.startedAt);
      if (d.done) { self.finish(d); return; }
      self.loop();
    });
  };
  Export.prototype.deleteJob = function (id, btn) {
    var self = this;
    if (!id) return;
    if (btn) btn.disabled = true;
    App.post(this.endpoint, { action: 'cancel', job: id }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Could not delete', { kind: 'error' }); if (btn) btn.disabled = false; return; }
      if (self.job && self.job.job === id) { self.job = null; if (self.done) self.done.hidden = true; }
      self.loadRecent();
    });
  };
  Export.prototype.loadRecent = function () {
    var self = this;
    if (!this.recent) return;
    App.post(this.endpoint, { action: 'list' }).then(function (res) {
      if (!res.ok) return;
      var jobs = (res.data && res.data.jobs) || [];
      self.recent.innerHTML = jobs.map(function (j) {
        var status = j.done ? 'Ready · ' + fmtBytes(j.zip_bytes || j.bytes) : (j.error ? 'Failed · ' + j.error : 'Unfinished · ' + j.added + ' of ' + j.files + ' files');
        var actions = j.done
          ? '<a class="ui-btn ui-btn--tinted ui-btn--sm" href="' + esc(self.endpoint + '&action=download&job=' + j.job) + '" download="' + esc(j.filename || 'export.zip') + '" data-export-recent-download>' + ICON.download + '<span>Download</span></a>'
          : (j.error ? '' : '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-export-resume="' + esc(j.job) + '">Continue</button>');
        actions += '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn" data-export-delete="' + esc(j.job) + '" title="Delete this export">Delete</button>';
        return '<li class="studio-export-job" data-export-job="' + esc(j.job) + '" data-export-job-state="' + (j.done ? 'done' : 'building') + '">'
             + '<div class="studio-export-job-main"><div class="studio-export-job-title">' + esc(j.label || 'Export') + '</div>'
             + '<div class="studio-export-job-meta text-secondary">' + esc(status) + ' · ' + j.files + (j.files === 1 ? ' file' : ' files') + ' · ' + esc(fmtAgo(j.created_at)) + '</div></div>'
             + '<div class="studio-export-job-actions">' + actions + '</div></li>';
      }).join('');
      if (self.recentEmpty) self.recentEmpty.hidden = jobs.length > 0;
    });
  };

  /* ================================================================== */
  /* Confirm-first forms                                                 */
  /* ================================================================== */
  /** Forms that ask first (Manage → Tools: delete an audience). */
  function initHub() {
    $$('[data-confirm-submit]').forEach(function (form) {
      if (form.closest('[data-clients]')) return;   // the Clients forms confirm inside their own fetch submit
      form.addEventListener('submit', function (e) { if (!window.confirm(form.dataset.confirmSubmit)) e.preventDefault(); });
    });
  }

  /* ================================================================== */
  App.studio = {
    export:   function (root) { return new Export(root); },
    linkTags: linkTags,
    formatWhen: formatWhen,
    instances: {}
  };

  function init() {
    $$('[data-export]').forEach(function (root) { App.studio.instances.export = new Export(root); });
    initHub();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})(window, document);

/* =====================================================================
   Emails (add-email.php): audience chips + the Live guard.
   Live may only be ticked when the status is Approved (mirrors the
   email-status.php 409 rule); the server enforces it too.
   ===================================================================== */
(function (window, document) {
  'use strict';
  function initEmailForm() {
    var form = document.querySelector('[data-email-form]');
    if (!form) return;
    var status = form.querySelector('[data-email-status]');
    var live   = form.querySelector('[data-email-live]');
    var chip   = form.querySelector('[data-email-live-chip]');
    var help   = form.querySelector('[data-email-live-help]');
    function syncLive() {
      var ok = status && status.value === 'approved';
      if (live) {
        live.disabled = !ok;
        if (!ok) live.checked = false;
      }
      if (chip) chip.classList.toggle('is-active', !!(live && live.checked));
      if (help) help.hidden = ok;
    }
    if (status) status.addEventListener('change', syncLive);
    if (live) live.addEventListener('change', syncLive);
    form.addEventListener('change', function (e) {
      var c = e.target.closest('[data-email-group-chip]');
      if (c) c.classList.toggle('is-active', e.target.checked);
    });
    syncLive();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initEmailForm);
  else initEmailForm();
})(window, document);

/* =====================================================================
   Clients (Manage → Clients, partials/manage-clients.php → client-admin.php)
   - slug auto-fills from the name until the admin edits it by hand
   - every [data-client-form] posts with fetch + FormData (Accept: JSON) and
     follows the reply's `redirect`; errors land in the form's status line +
     a toast. Without JS the same forms post normally and the endpoint
     redirects back on its own (msg= / err=).
   - the logo file input submits on change (2 MB checked client-side too)
   ===================================================================== */
(function (window, document) {
  'use strict';
  var App = window.App = window.App || {};
  var MAX_LOGO = 2 * 1024 * 1024;
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function toast(msg, opts) { if (App.toast) App.toast(msg, opts); else if (msg) window.alert(msg); }

  /* Same rule as caSlugify() in client-admin.php (ASCII letters / digits, dashes, ≤ 40). */
  function slugify(name) {
    var s = String(name || '');
    try { s = s.normalize('NFKD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
    s = s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40).replace(/-+$/, '');
    return s;
  }
  App.slugify = App.slugify || slugify;

  function initClients() {
    var root = $('[data-clients]');
    if (!root) return;

    $$('[data-client-form]', root).forEach(function (form) {
      var name = $('[data-client-name]', form), slug = $('[data-client-slug]', form);
      if (name && slug) {
        name.addEventListener('input', function () { if (!slug.dataset.touched) slug.value = slugify(name.value); });
        slug.addEventListener('input', function () { slug.dataset.touched = slug.value.trim() === '' ? '' : '1'; });
      }
      var file = $('[data-client-logo-input]', form);
      if (file) {
        var manual = $('[data-client-logo-submit]', form);   // the no-JS Upload button: picking a file submits instead
        if (manual) manual.hidden = true;
        file.addEventListener('change', function () {
          var f = file.files && file.files[0];
          var label = $('[data-client-file-label]', form);
          if (f && f.size > MAX_LOGO) { toast('Logos are limited to 2 MB.', { kind: 'error' }); file.value = ''; if (label) label.textContent = 'Add a logo… (optional)'; return; }
          if (label) label.textContent = f ? f.name : 'Add a logo… (optional)';
          // the "Replace logo…" form has nothing else to fill in: upload straight away
          if (f && form.querySelector('input[name="action"][value="logo_upload"]')) submit(form);
        });
      }
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (form.dataset.confirmSubmit && !window.confirm(form.dataset.confirmSubmit)) return;
        submit(form);
      });
    });

    function submit(form) {
      var status = $('[data-client-status]', form);
      var buttons = $$('button[type="submit"]', form);
      var fd = new FormData(form);
      var fileInput = $('input[type="file"]', form);
      if (fileInput && fileInput.files && fileInput.files[0] && fileInput.files[0].size > MAX_LOGO) { toast('Logos are limited to 2 MB.', { kind: 'error' }); return; }
      buttons.forEach(function (b) { b.disabled = true; });
      if (status) status.textContent = 'Saving…';
      fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.text().then(function (t) { var d = null; try { d = t ? JSON.parse(t) : null; } catch (err) {} return { ok: res.ok && d && d.ok !== false, status: res.status, data: d }; }); })
        .then(function (r) {
          if (r.ok && r.data) {
            if (status) status.textContent = r.data.message || 'Saved.';
            if (r.data.redirect) { window.location.href = r.data.redirect; return; }
            window.location.reload();
            return;
          }
          var msg = (r.data && r.data.error) || ('Request failed (' + r.status + ')');
          if (status) status.textContent = msg;
          toast(msg, { kind: 'error' });
          buttons.forEach(function (b) { b.disabled = false; });
        })
        .catch(function () {
          if (status) status.textContent = 'Network error';
          toast('Network error', { kind: 'error' });
          buttons.forEach(function (b) { b.disabled = false; });
        });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initClients);
  else initClients();

  /* Manage → Tools: Pages "Repair" → page-upload.php action=repair_media (media/pages/<client>/:
     .htaccess rewritten when old / missing, files 0644 / folders 0755); the summary is toasted. */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-pages-repair]');
    if (!btn || btn.disabled) return;
    var endpoint = btn.getAttribute('data-endpoint') || 'page-upload.php';
    btn.disabled = true; btn.setAttribute('aria-busy', 'true');
    App.post(endpoint, { action: 'repair_media', actor: App.actor }).then(function (res) {
      btn.disabled = false; btn.removeAttribute('aria-busy');
      var d = res.data || {};
      if (!res.ok) { toast(res.error || 'Repair failed', { kind: 'error' }); return; }
      toast(d.summary || 'Server rules repaired', { kind: 'success' });
    });
  });

})(window, document);
