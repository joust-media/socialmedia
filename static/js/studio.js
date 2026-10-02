/* =====================================================================
   Studio (admin only) — extends the Foundation `App` (app.js loads first).

   App.studio.uploads(zone)  Uploads tab: every file goes to upload-chunk.php
                             (purpose=batch — one request when small, pieces
                             through App.chunkUpload when large) and its token
                             is posted to batch-process.php as claimed[] → one
                             draft post per file; .MOV shows the Safari-only
                             warning before upload. UploadQueue: files go up as
                             they are picked (progress / Cancel / Remove, "Resume
                             N unfinished uploads" after a reload).
                             (New posts: the New post pop-up, static/js/newpost.js.)
   App.studio.renders(root)  Renders tab (tire series): tire + series pickers,
                             sequential queue to tire-upload.php — one request
                             per small file, files above the server's chunk_size
                             in pieces through App.chunkUpload (chunk-upload.js:
                             progress / speed / ETA, per-piece retry, Cancel,
                             "Resume N unfinished uploads" after a reload); one
                             batch id per drop, "New series…" created by the
                             first file; retry, rescan, and the series list
                             (rename / reorder / delete → tire-status.php).
   App.studio.export(root)   Export tab: scope + include options → live estimate
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

  /* Link to one post in Posts. The server hands us a clientUrl('posts.php', ['post' => '__ID__'])
     template (cfg.postUrl) so the URL shape (.php vs. pretty) is decided in one place (helpers.php);
     the fallback builds the explicit .php form from the base path. */
  function postUrl(c, id) {
    var tpl = c.postUrl || ((c.base || '') + '/posts.php?client=' + encodeURIComponent(c.client || '') + '&post=__ID__');
    return tpl.replace('__ID__', encodeURIComponent(id));
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
  function fileExt(name) { var m = String(name || '').toLowerCase().match(/\.([a-z0-9]+)$/); return m ? m[1] : ''; }
  function isQuickTime(file) { return fileExt(file.name) === 'mov' || /quicktime/i.test(file.type || ''); }
  function isVideoFile(file) { return /^video\//i.test(file.type || '') || ['mp4', 'webm', 'mov', 'm4v'].indexOf(fileExt(file.name)) !== -1; }
  function mb(bytes) { return (bytes / 1024 / 1024).toFixed(bytes > 10 * 1024 * 1024 ? 0 : 1) + ' MB'; }

  var ICON = {
    left:  '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>',
    right: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>',
    x:     '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    play:  '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>',
    check: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L19 7"/></svg>',
    download: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5v11.5"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4.5 16v2a2.5 2.5 0 0 0 2.5 2.5h10a2.5 2.5 0 0 0 2.5-2.5v-2"/></svg>',
    drive: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3.5h6l6.5 11.5-3 5.5H5.5l-3-5.5z"/><path d="M2.5 15h19M15 3.5 8.5 15"/></svg>'
  };

  /* ================================================================== */
  /* Drop zones (shared)                                                */
  /* ================================================================== */
  function bindDrop(zone, input, onFiles) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-dragover'); });
    });
    zone.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (files && files.length) onFiles(Array.prototype.slice.call(files), true);
    });
    if (input) input.addEventListener('change', function () { onFiles(Array.prototype.slice.call(input.files || []), false); });
  }
  function capLabel(mb) { return mb >= 1024 ? (mb / 1024) + ' GB' : mb + ' MB'; }
  /* maxMb may be a number (one cap) or {image, video} (per type: images 50 MB, videos 4 GB). */
  function fileNote(file, maxMb) {
    var ext = fileExt(file.name);
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov'].indexOf(ext) === -1) return 'Unsupported type';
    var cap = typeof maxMb === 'object' ? (isVideoFile(file) ? maxMb.video : maxMb.image) : maxMb;
    if (file.size > cap * 1024 * 1024) return 'Over ' + capLabel(cap);
    return '';
  }

  /* ================================================================== */
  /* UploadQueue — files → upload-chunk.php (purpose=post | batch)      */
  /*   One row per file (template: thumb / name / meta / progress /     */
  /*   status / Cancel / Remove), one upload at a time through          */
  /*   App.chunkUpload.upload (single request when small, pieces when   */
  /*   large), a claimed[] token per finished file, and the "Resume N   */
  /*   unfinished uploads" banner after a reload (localStorage ledger). */
  /*   opts: endpoint, purpose, kind (ledger), list, tpl, resume {box,   */
  /*   text, input, discard}, maxMb {image, video}, maxFiles, onChange, */
  /*   onDone(job, data), cancelSel, itemSel                             */
  /* ================================================================== */
  function UploadQueue(opts) {
    var self = this;
    this.o = opts;
    this.chunk = App.chunkUpload || null;
    this.endpoint = opts.endpoint || cfg.upload || 'upload-chunk.php';
    this.client = opts.client || cfg.client || (document.body.dataset.client || '');
    this.jobs = []; this.queue = []; this.busy = false; this.pending = [];
    if (opts.resume && opts.resume.input) opts.resume.input.addEventListener('change', function () { self.resumeFiles(Array.prototype.slice.call(opts.resume.input.files || [])); opts.resume.input.value = ''; });
    if (opts.resume && opts.resume.discard) opts.resume.discard.addEventListener('click', function () { self.discardResume(); });
    this.offerResume();
  }
  UploadQueue.prototype.available = function () { return !!(this.chunk && this.chunk.upload); };
  UploadQueue.prototype.active = function () { return this.jobs.filter(function (j) { return j.state !== 'failed' && j.state !== 'removed'; }); };
  UploadQueue.prototype.done = function () { return this.jobs.filter(function (j) { return j.state === 'done'; }); };
  UploadQueue.prototype.tokens = function () { return this.done().map(function (j) { return j.token; }).filter(Boolean); };
  UploadQueue.prototype.inFlight = function () { return this.jobs.some(function (j) { return j.state === 'queued' || j.state === 'uploading' || j.state === 'held'; }); };
  UploadQueue.prototype.changed = function () { if (this.o.onChange) this.o.onChange(this); };
  /** A row + job. opts.hold: create the row but do not start (the caller shows a warning first); opts.uploadId: resume. */
  UploadQueue.prototype.add = function (file, opts) {
    opts = opts || {};
    var item = this.o.tpl.content.firstElementChild.cloneNode(true);
    var job = { file: file, item: item, state: 'held', token: null, data: null, uploadId: opts.uploadId || null, ctl: null, retryable: false };
    item._job = job;
    item.setAttribute('data-file-name', file.name);
    $('[data-upload-name]', item).textContent = file.name;
    var meta = $('[data-upload-meta]', item);
    if (meta) meta.textContent = mb(file.size) + (isVideoFile(file) ? ' · video' : (file.type ? ' · ' + file.type.replace(/^image\//, '') : '')) + (job.uploadId ? ' · resuming' : '');
    var thumb = $('[data-upload-thumb]', item);
    if (thumb) {
      if (isVideoFile(file)) { thumb.innerHTML = ICON.play; thumb.classList.add('is-video'); thumb.title = mb(file.size); }   // poster-less: never decode a multi-GB file for a thumbnail
      else if (/^image\//.test(file.type)) { var img = document.createElement('img'); img.alt = ''; img.src = URL.createObjectURL(file); thumb.appendChild(img); }
    }
    this.o.list.appendChild(item);
    this.jobs.push(job);
    var status = $('[data-upload-status]', item);
    var maxFiles = this.o.maxFiles || 0;
    if (maxFiles && this.active().length > maxFiles) { this.fail(job, 'Up to ' + maxFiles + ' files at a time — not uploaded.'); return job; }
    var note = fileNote(file, this.o.maxMb || { image: 50, video: 4096 });
    if (note) { this.fail(job, note + ' — not uploaded.'); return job; }
    if (!this.available()) { this.fail(job, 'Uploads need chunk-upload.js — reload the page.'); return job; }
    if (status) status.textContent = 'Waiting…';
    if (!opts.hold) this.start(job);
    else this.changed();
    return job;
  };
  UploadQueue.prototype.start = function (job) {
    if (job.state !== 'held') return;
    job.state = 'queued';
    this.queue.push(job);
    this.changed();
    this.next();
  };
  UploadQueue.prototype.fail = function (job, msg) {
    job.state = 'failed'; job.token = null;
    var status = $('[data-upload-status]', job.item), prog = $('[data-upload-progress]', job.item), cancel = $(this.o.cancelSel || '[data-upload-cancel]', job.item);
    if (status) { status.textContent = msg; status.classList.remove('is-ok'); status.classList.add('is-error'); }
    if (prog) prog.hidden = true;
    if (cancel) cancel.hidden = true;
    job.item.classList.add('is-failed');
    this.changed();
  };
  /** Cancel a queued / running job (the server drops its spool) or remove a finished / failed row (a parked file is discarded). */
  UploadQueue.prototype.remove = function (job) {
    var self = this;
    if (job.state === 'queued') { this.queue = this.queue.filter(function (j) { return j !== job; }); }
    if (job.state === 'uploading' && job.ctl) { job.ctl.abort(); return; }   // the rejection handler removes the row
    if (job.state === 'done' && job.token && this.o.purpose !== 'replace') {
      try { App.post(this.endpoint, { action: 'claim_discard', token: job.token, client: this.client }); } catch (e) {}
    }
    job.state = 'removed'; job.token = null;
    if (job.item.parentNode) job.item.parentNode.removeChild(job.item);
    this.jobs = this.jobs.filter(function (j) { return j !== job; });
    this.changed();
    if (!this.busy) this.next();
  };
  UploadQueue.prototype.next = function () {
    if (this.busy || !this.queue.length) return;
    var self = this, job = this.queue.shift(), item = job.item, file = job.file;
    var prog = $('[data-upload-progress]', item), fill = $('[data-upload-fill]', item), status = $('[data-upload-status]', item), cancel = $(this.o.cancelSel || '[data-upload-cancel]', item);
    this.busy = true; job.state = 'uploading';
    if (prog) prog.hidden = false;
    if (status) status.textContent = 'Uploading… 0%';
    if (cancel) cancel.hidden = false;
    var fields = Object.assign({ purpose: this.o.purpose, client: this.client, actor: App.actor || 'admin' }, this.o.fields || {});
    var ctl = this.chunk.upload({
      endpoint: this.endpoint, file: file, fields: fields, uploadId: job.uploadId || null,
      onInit: function (d) {
        job.uploadId = d.upload_id;
        self.chunk.remember({ id: d.upload_id, kind: self.o.kind || self.o.purpose, endpoint: self.endpoint, client: self.client, name: file.name, size: file.size, type: file.type || '', fields: { purpose: self.o.purpose }, label: self.o.label || '' });
      },
      onProgress: function (p) { if (fill) fill.style.transform = 'translateX(' + (p.pct - 100) + '%)'; if (status) status.textContent = 'Uploading… ' + p.text; },
      onRetry: function (r) { if (status) status.textContent = 'Connection hiccup — retrying that piece (' + r.attempt + ' of ' + r.max + ')…'; }
    });
    job.ctl = ctl;
    var settle = function () { job.ctl = null; if (cancel) cancel.hidden = true; self.busy = false; self.changed(); self.next(); };
    ctl.promise.then(function (data) {
      if (job.uploadId) self.chunk.forget(job.uploadId);
      job.uploadId = null; job.state = 'done'; job.data = data; job.token = data.token || null;
      if (fill) fill.style.transform = 'translateX(0)';
      if (status) { status.textContent = 'Uploaded'; status.classList.remove('is-error'); status.classList.add('is-ok'); }
      if (self.o.onDone) self.o.onDone(job, data);
      settle();
    }, function (e) {
      if (job.uploadId && (!e || e.aborted || !e.retryable || e.expired)) { self.chunk.forget(job.uploadId); job.uploadId = null; }
      if (e && e.aborted) { job.state = 'removed'; if (job.item.parentNode) job.item.parentNode.removeChild(job.item); self.jobs = self.jobs.filter(function (j) { return j !== job; }); settle(); return; }
      self.fail(job, (e && e.error) || 'Upload failed');
      settle();
    });
  };
  /* ---- resume after a reload: the ledger names the unfinished uploads; the user re-picks the same files ---- */
  UploadQueue.prototype.offerResume = function () {
    var r = this.o.resume;
    if (!this.chunk || !r || !r.box) return;
    var live = {}; this.jobs.forEach(function (j) { if (j.uploadId) live[j.uploadId] = true; });
    this.pending = this.chunk.list({ kind: this.o.kind || this.o.purpose, client: this.client }).filter(function (e) { return !live[e.id]; });
    var n = this.pending.length;
    r.box.hidden = n === 0;
    if (r.text && n) {
      var names = this.pending.map(function (e) { return e.name + ' (' + mb(e.size) + ')'; });
      r.text.textContent = 'Resume ' + n + ' unfinished upload' + (n === 1 ? '' : 's') + ': ' + names.join(', ') + '. Pick the same file' + (n === 1 ? '' : 's') + ' again and the upload continues where it stopped.';
    }
  };
  UploadQueue.prototype.resumeFiles = function (files) {
    var self = this, matched = [], unmatched = [];
    files.forEach(function (f) {
      var e = self.pending.filter(function (p) { return p.name === f.name && Number(p.size) === f.size && matched.indexOf(p) === -1; })[0];
      if (!e) { unmatched.push(f.name); return; }
      matched.push(e);
      self.add(f, { uploadId: e.id });
    });
    if (unmatched.length) toast(unmatched.length + ' file' + (unmatched.length === 1 ? ' does' : 's do') + ' not match an unfinished upload (same name and size needed): ' + unmatched.join(', '), { kind: 'error', duration: 6000 });
    this.offerResume();
  };
  UploadQueue.prototype.discardResume = function () {
    var self = this;
    if (!this.pending.length) return;
    if (!window.confirm('Discard ' + this.pending.length + ' unfinished upload' + (this.pending.length === 1 ? '' : 's') + '? The pieces already sent are deleted from the server.')) return;
    this.pending.forEach(function (e) { self.chunk.abortStored(e); });
    this.offerResume();
  };

  /* ================================================================== */
  /* Uploads zone → batch-process.php (one draft post per file)        */
  /* ================================================================== */
  function Uploads(zone) {
    var self = this;
    this.zone = zone;
    this.endpoint = zone.dataset.endpoint || cfg.batch || '';               // batch-process.php: claimed[] = token → one draft post
    this.uploadEndpoint = zone.dataset.uploadEndpoint || cfg.upload || '';   // upload-chunk.php purpose=batch
    this.maxMb = { image: parseInt(zone.dataset.maxImageMb, 10) || parseInt(cfg.maxImageMb, 10) || 50, video: parseInt(zone.dataset.maxVideoMb, 10) || parseInt(cfg.maxVideoMb, 10) || 4096 };
    this.maxFiles = parseInt(zone.dataset.maxFiles, 10) || parseInt(cfg.maxBatchFiles, 10) || 50;
    this.input = $('[data-upload-input]', zone);
    this.list = $('[data-upload-list]', zone);
    this.tpl = $('[data-upload-item-template]', zone);
    this.queue = new UploadQueue({
      endpoint: this.uploadEndpoint, purpose: 'batch', kind: 'batch', list: this.list, tpl: this.tpl, maxMb: this.maxMb, maxFiles: this.maxFiles, cancelSel: '[data-upload-cancel]',
      label: 'Uploads',
      resume: { box: $('[data-upload-resume]', zone), text: $('[data-upload-resume-text]', zone), input: $('[data-upload-resume-input]', zone), discard: $('[data-upload-resume-discard]', zone) },
      onDone: function (job, data) { self.createPost(job, data); }
    });
    var drop = $('[data-file-drop]', zone);
    if (drop) bindDrop(drop, this.input, function (files) { files.forEach(function (f) { self.add(f); }); if (self.input) self.input.value = ''; });
    zone.addEventListener('click', function (e) {
      var item = e.target.closest('[data-upload-item]');
      if (!item) return;
      if (e.target.closest('[data-upload-anyway]')) { $('[data-upload-warning]', item).hidden = true; if (item._job) self.queue.start(item._job); }
      if (e.target.closest('[data-upload-skip]')) { if (item._job) self.queue.remove(item._job); else item.remove(); }
      if (e.target.closest('[data-upload-cancel]')) { if (item._job) self.queue.remove(item._job); }
    });
  }
  Uploads.prototype.add = function (file) {
    // spec §6: .MOV is warned about before upload ("Safari-only playback"); "Upload anyway" starts it —
    // batch-process.php accepts video/quicktime and keeps the original .mov in uploads/.
    var hold = isQuickTime(file);
    var job = this.queue.add(file, { hold: hold });
    if (hold && job.state === 'held') $('[data-upload-warning]', job.item).hidden = false;
  };
  /** The token is in: one draft post for it (batch-process.php keeps its per-row contract: created[0] / errors[0]). */
  Uploads.prototype.createPost = function (job, data) {
    var status = $('[data-upload-status]', job.item), prog = $('[data-upload-progress]', job.item);
    status.textContent = 'Creating the draft post…';
    App.post(this.endpoint, { 'claimed[]': data.token, client: cfg.client || '' }).then(function (res) {
      var d = res.data || {}, created = d.created && d.created[0], err = null;
      if (!res.ok || d.ok === false) err = res.error || d.error || 'Request failed';
      else if (!created) err = (d.errors && d.errors[0]) || 'Not accepted';
      if (err) { status.textContent = err; status.classList.remove('is-ok'); status.classList.add('is-error'); if (prog) prog.hidden = true; return; }
      var when = created.date ? formatWhen(String(created.date).replace(' ', 'T')) : '';
      var draft = created.status === 'draft';
      status.innerHTML = (draft ? 'Draft' : 'Post') + ' #' + esc(created.post_id) + (when ? ' · ' + esc(when) : '')
        + ' — <a href="' + esc(postUrl(cfg, created.post_id)) + '">' + (draft ? 'add a caption, then Send for review' : 'finish it in Posts') + '</a>';
      status.classList.add('is-ok');
    });
  };

  /* ================================================================== */
  /* Renders (tire series) → tire-upload.php, one XHR per file          */
  /*   cfg.renders = {endpoint, status, assetsUrl, rescanUrl, tires:[{id,name,folder,series:[{id,name,slug,folder,counts}]}], tire, series, maxMb} */
  /* ================================================================== */
  var NEW_SERIES = '__new__';
  function Renders(root) {
    var self = this, rc = cfg.renders || {};
    this.root = root; this.rc = rc;
    this.endpoint = root.dataset.endpoint || rc.endpoint || 'tire-upload.php';
    this.statusEndpoint = root.dataset.statusEndpoint || rc.status || 'tire-status.php';
    this.maxMb = parseInt(root.dataset.maxMb, 10) || rc.maxMb || 10;                    // images
    this.maxVideoMb = parseInt(root.dataset.maxVideoMb, 10) || rc.maxVideoMb || 4096;  // videos (chunked: 4 GB; the server's probe is the authority)
    this.tires = rc.tires || [];
    this.chunk = App.chunkUpload || null; this.info = null; this.infoP = null;          // chunk-upload.js: probe once, then chunk files above chunk_size
    this.tireSel = $('[data-renders-tire]', root); this.seriesSel = $('[data-renders-series]', root); this.newName = $('[data-renders-new-name]', root);
    this.newDrive = $('[data-renders-new-drive]', root);                                  // Google Drive link for a "New series…" (posted as series_drive once the first file created it)
    this.driveOn = !!(rc.driveOn || this.newDrive);
    this.input = $('[data-renders-input]', root); this.list = $('[data-renders-list]', root); this.tpl = $('[data-renders-item-template]', root);
    this.seriesList = $('[data-renders-series-list]', root); this.seriesEmpty = $('[data-renders-series-empty]', root);
    this.summary = $('[data-renders-summary]', root); this.summaryText = $('[data-renders-summary-text]', root);
    this.resumeBox = $('[data-renders-resume]', root); this.resumeInput = $('[data-renders-resume-input]', root);
    this.queue = []; this.busy = false; this.jobs = []; this.createdSeries = {}; this.pending = [];
    if (!this.tireSel || !this.seriesSel) return;
    this.tireSel.addEventListener('change', function () { rc.series = 0; self.syncSeries(); });
    this.seriesSel.addEventListener('change', function () { self.syncNewName(); });
    if (this.newName) this.newName.addEventListener('input', function () { self.syncTarget(); });
    var drop = $('[data-file-drop]', root);
    if (drop && this.input) bindDrop(drop, this.input, function (files) { self.addAll(files); if (self.input) self.input.value = ''; });
    if (this.resumeInput) this.resumeInput.addEventListener('change', function () { self.resumeFiles(Array.prototype.slice.call(self.resumeInput.files || [])); self.resumeInput.value = ''; });
    root.addEventListener('click', function (e) {
      if (e.target.closest('[data-renders-copy]')) { self.copyFolder(); return; }
      if (e.target.closest('[data-renders-rescan]')) { self.rescan(e.target.closest('[data-renders-rescan]')); return; }
      if (e.target.closest('[data-renders-repair]')) { self.repair(e.target.closest('[data-renders-repair]')); return; }
      if (e.target.closest('[data-renders-clear]')) { self.clearList(); return; }
      if (e.target.closest('[data-renders-retry-all]')) { self.retryFailed(); return; }
      if (e.target.closest('[data-renders-resume-discard]')) { self.discardResume(); return; }
      var retry = e.target.closest('[data-renders-retry]');
      if (retry) { var item = retry.closest('[data-renders-item]'); if (item && item._job) self.retry(item._job); return; }
      var cancel = e.target.closest('[data-renders-cancel]');
      if (cancel) { var ci = cancel.closest('[data-renders-item]'); if (ci && ci._job) self.cancel(ci._job); return; }
      var row = e.target.closest('[data-series-row]');
      if (!row) return;
      if (e.target.closest('[data-series-up]'))     { self.moveSeries(row, -1); }
      else if (e.target.closest('[data-series-down]')) { self.moveSeries(row, 1); }
      else if (e.target.closest('[data-series-delete]')) { self.deleteSeries(row); }
      else if (e.target.closest('[data-series-rename-save]')) { self.renameSeries(row); }
      else if (e.target.closest('[data-series-drive-save]')) { self.saveDrive(row); }
    });
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.matches('[data-series-name]')) { e.preventDefault(); self.renameSeries(e.target.closest('[data-series-row]')); }
      if (e.key === 'Enter' && e.target.matches('[data-series-drive]')) { e.preventDefault(); self.saveDrive(e.target.closest('[data-series-row]')); }
    });
    root.addEventListener('input', function (e) {
      if (e.target.matches('[data-series-drive]')) { e.target.removeAttribute('aria-invalid'); var w = e.target.closest('[data-series-drive-wrap]'); if (w) w.classList.toggle('is-dirty', true); }
    });
    this.syncSeries();
    this.offerResume();
  }
  Renders.prototype.tire = function () {
    var id = parseInt(this.tireSel.value, 10);
    for (var i = 0; i < this.tires.length; i++) if (this.tires[i].id === id) return this.tires[i];
    return this.tires[0] || null;
  };
  Renders.prototype.seriesOf = function (tire, id) {
    for (var i = 0; tire && i < tire.series.length; i++) if (tire.series[i].id === id) return tire.series[i];
    return null;
  };
  /** Series <select> for the current tire (+ "New series…"); folder hint, Open link and the series card follow. */
  Renders.prototype.syncSeries = function () {
    var tire = this.tire(), rc = this.rc, want = parseInt(rc.series, 10) || 0, self = this;
    if (!tire) return;
    var html = tire.series.map(function (s) {
      return '<option value="' + s.id + '">' + esc(s.name) + ' · ' + esc(String(s.counts.total)) + (s.counts.total === 1 ? ' file' : ' files') + '</option>';
    }).join('') + '<option value="' + NEW_SERIES + '">New series…</option>';
    this.seriesSel.innerHTML = html;
    var pick = this.seriesOf(tire, want) ? String(want) : (tire.series.length ? String(tire.series[tire.series.length - 1].id) : NEW_SERIES);
    this.seriesSel.value = pick;
    var folder = $('[data-renders-folder]', this.root); if (folder) folder.textContent = (tire.folder || '') + '/';
    var name = $('[data-renders-tire-name]', this.root); if (name) name.textContent = tire.name;
    var open = $('[data-renders-open]', this.root);
    if (open) open.href = (rc.assetsUrl || '').replace('__TIRE__', String(tire.id)).replace(/([&?])series=__SERIES__/, '');
    this.syncNewName();
    this.renderSeriesList();
    $$('[data-renders-tire] option', this.root).forEach(function (o) {
      var t = self.tires.filter(function (x) { return String(x.id) === o.value; })[0];
      if (t) o.textContent = t.name + (t.series.length ? ' · ' + t.series.length + ' series' : '');
    });
  };
  Renders.prototype.syncNewName = function () {
    var isNew = this.seriesSel.value === NEW_SERIES;
    if (this.newName) { this.newName.hidden = !isNew; if (isNew) { var t = this.tire(); if (!this.newName.value) this.newName.placeholder = 'Series ' + ((t ? t.series.length : 0) + 1); } }
    if (this.newDrive) this.newDrive.hidden = !isNew;
    this.syncTarget();
  };
  Renders.prototype.target = function () {
    var tire = this.tire(); if (!tire) return null;
    if (this.seriesSel.value === NEW_SERIES) {
      var n = (this.newName && this.newName.value.trim()) || ('Series ' + (tire.series.length + 1));
      return { tireId: tire.id, tireName: tire.name, seriesId: 0, newSeries: n, label: n + ' (new)' };
    }
    var s = this.seriesOf(tire, parseInt(this.seriesSel.value, 10));
    return s ? { tireId: tire.id, tireName: tire.name, seriesId: s.id, newSeries: '', label: s.name } : null;
  };
  Renders.prototype.syncTarget = function () {
    var t = this.target(), el = $('[data-renders-target]', this.root);
    if (el) el.textContent = t ? (t.tireName + ' · ' + t.label) : 'the chosen series';
  };
  Renders.prototype.copyFolder = function () {
    var code = $('[data-renders-folder]', this.root), text = code ? code.textContent : '';
    if (!text) return;
    var done = function () { toast('Folder path copied', { kind: 'success' }); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy the folder path', text); });
    else window.prompt('Copy the folder path', text);
  };
  /** Rescan media/tires/<tire>/: tire-status.php action=rescan when the backend has it, else the assets.php &rescan=1 GET. */
  Renders.prototype.rescan = function (btn) {
    var self = this, tire = this.tire(), rc = this.rc;
    if (!tire) return;
    if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Rescanning…'; }
    var finish = function (ok, msg) {
      if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); btn.textContent = 'Rescan folders'; }
      if (ok) { toast(msg || 'Folders rescanned', { kind: 'success' }); window.location.href = (cfg.tabUrl || '').replace('__TAB__', 'renders') + '&tire=' + tire.id; }
      else toast(msg || 'Rescan failed', { kind: 'error' });
    };
    App.post(this.statusEndpoint, { action: 'rescan', tire_id: tire.id, actor: App.actor }).then(function (res) {
      if (res.ok) { var d = res.data || {}; finish(true, d.added !== undefined ? (d.added + ' new file' + (d.added === 1 ? '' : 's') + ' found') : ''); return; }
      var url = (rc.rescanUrl || '').replace('__TIRE__', String(tire.id));
      if (!url) { finish(false, res.error); return; }
      fetch(url, { credentials: 'same-origin' }).then(function (r) { finish(r.ok, r.ok ? '' : 'Rescan failed (' + r.status + ')'); }, function () { finish(false, 'Network error'); });
    });
  };
  /** "Repair server rules": tire-upload.php action=repair_media — media/tires/.htaccess rewritten when old / missing,
   *  an old media/.htaccess of ours removed, every render made readable (0644 / 0755). The reply's summary is toasted. */
  Renders.prototype.repair = function (btn) {
    if (btn && btn.disabled) return;
    if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Repairing…'; }
    App.post(this.endpoint, { action: 'repair_media', actor: App.actor }).then(function (res) {
      if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); btn.textContent = 'Repair server rules'; }
      var d = res.data || {};
      if (res.ok) toast(d.summary || 'Server rules repaired', { kind: 'success' });
      else toast(res.error || 'Repair failed', { kind: 'error' });
    });
  };
  /* ---- upload queue ---- */
  Renders.prototype.addAll = function (files) {
    var self = this, t = this.target();
    if (!t) { toast('Pick a tire first.', { kind: 'error' }); return; }
    if (!files.length) return;
    var batch = 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);   // one batch id per drop
    files.forEach(function (f) { self.add(f, t, batch); });
    if (this.summary) this.summary.hidden = false;
    this.syncSummary();
  };
  Renders.prototype.add = function (file, target, batch, opts) {
    var item = this.tpl.content.firstElementChild.cloneNode(true);
    var job = { file: file, item: item, target: target, batch: batch, state: 'queued', tries: 0, uploadId: (opts && opts.uploadId) || null, ctl: null };
    item._job = job;
    $('[data-upload-name]', item).textContent = file.name;
    $('[data-upload-meta]', item).textContent = mb(file.size) + ' · ' + target.tireName + ' · ' + target.label + (job.uploadId ? ' · resuming' : '');
    var thumb = $('[data-upload-thumb]', item);
    if (isVideoFile(file)) { thumb.innerHTML = ICON.play; }
    else if (/^image\//.test(file.type)) { var img = document.createElement('img'); img.alt = ''; img.src = URL.createObjectURL(file); thumb.appendChild(img); }
    this.list.appendChild(item);
    this.jobs.push(job);
    var status = $('[data-upload-status]', item);
    var cap = isVideoFile(file) ? this.maxVideoMb : this.maxMb;   // tire-upload.php: images 10 MB, videos 4 GB (chunked)
    if (file.size > cap * 1024 * 1024) { this.fail(job, 'Over ' + (cap >= 1024 ? (cap / 1024) + ' GB' : cap + ' MB') + ' — not uploaded.', false); return job; }
    if (!/^image\/(jpeg|png|gif|webp)$/.test(file.type) && !/^video\/(mp4|webm|quicktime)$/.test(file.type) && ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov'].indexOf(fileExt(file.name)) === -1) {
      this.fail(job, 'Unsupported type — use JPG, PNG, GIF, WebP, MP4, WebM or MOV.', false); return job;
    }
    status.textContent = 'Waiting…';
    this.queue.push(job);
    this.next();
    return job;
  };
  /** Cancel a queued or in-flight (chunked) job; the server drops its spool. */
  Renders.prototype.cancel = function (job) {
    if (job.state === 'queued') { this.queue = this.queue.filter(function (j) { return j !== job; }); this.fail(job, 'Cancelled', false); return; }
    if (job.state === 'uploading' && job.ctl) job.ctl.abort();
  };
  Renders.prototype.fail = function (job, msg, retryable) {
    job.state = 'failed';
    var status = $('[data-upload-status]', job.item), prog = $('[data-upload-progress]', job.item), retry = $('[data-renders-retry]', job.item), cancel = $('[data-renders-cancel]', job.item);
    status.textContent = msg + (job.uploadId && retryable !== false ? ' — Retry continues where it stopped.' : ''); status.classList.remove('is-ok'); status.classList.add('is-error');
    if (prog) prog.hidden = true;
    if (retry) retry.hidden = retryable === false;
    if (cancel) cancel.hidden = true;
    job.retryable = retryable !== false;
    this.syncSummary();
  };
  Renders.prototype.retry = function (job) {
    if (job.state !== 'failed' || !job.retryable) return;
    job.state = 'queued';
    var status = $('[data-upload-status]', job.item), retry = $('[data-renders-retry]', job.item), fill = $('[data-upload-fill]', job.item);
    status.textContent = 'Waiting…'; status.classList.remove('is-error');
    if (retry) retry.hidden = true;
    if (fill) fill.style.transform = 'translateX(-100%)';
    this.queue.push(job);
    this.syncSummary();
    this.next();
  };
  /** Probe the endpoint once (chunk size + caps). Resolves null when chunking is unavailable → single requests. */
  Renders.prototype.probe = function () {
    if (this.infoP) return this.infoP;
    var self = this;
    this.infoP = (this.chunk ? this.chunk.probe(this.endpoint) : Promise.resolve(null)).then(function (info) { self.info = info; return info; }, function () { return null; });
    return this.infoP;
  };
  /* ---- resume after a reload: the ledger in localStorage names the unfinished uploads; the user re-picks the same files ---- */
  Renders.prototype.offerResume = function () {
    if (!this.chunk || !this.resumeBox) return;
    var client = cfg.client || (document.body.dataset.client || '');
    var live = {}; this.jobs.forEach(function (j) { if (j.uploadId) live[j.uploadId] = true; });
    this.pending = this.chunk.list({ kind: 'tire', client: client }).filter(function (e) { return !live[e.id]; });
    var n = this.pending.length;
    this.resumeBox.hidden = n === 0;
    var t = $('[data-renders-resume-text]', this.resumeBox);
    if (t && n) {
      var names = this.pending.map(function (e) { return e.name + ' (' + mb(e.size) + ' → ' + (e.label || 'series') + ')'; });
      t.textContent = 'Resume ' + n + ' unfinished upload' + (n === 1 ? '' : 's') + ': ' + names.join(', ') + '. Pick the same file' + (n === 1 ? '' : 's') + ' again and the upload continues where it stopped.';
    }
  };
  Renders.prototype.resumeFiles = function (files) {
    var self = this, matched = [], unmatched = [];
    files.forEach(function (f) {
      var e = self.pending.filter(function (p) { return p.name === f.name && Number(p.size) === f.size && matched.indexOf(p) === -1; })[0];
      if (!e) { unmatched.push(f.name); return; }
      matched.push(e);
      var fields = e.fields || {}, tire = null;
      for (var i = 0; i < self.tires.length; i++) if (self.tires[i].id === +fields.tire_id) tire = self.tires[i];
      var s = tire ? self.seriesOf(tire, +fields.series_id) : null;
      var label = (e.label || '').split(' · ');
      var target = { tireId: +fields.tire_id || 0, tireName: tire ? tire.name : (label[0] || 'tire'), seriesId: +fields.series_id || 0, newSeries: '', label: s ? s.name : (label[1] || 'series') };
      self.add(f, target, 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7), { uploadId: e.id });
    });
    if (unmatched.length) toast(unmatched.length + ' file' + (unmatched.length === 1 ? ' does' : 's do') + ' not match an unfinished upload (same name and size needed): ' + unmatched.join(', '), { kind: 'error', duration: 6000 });
    if (matched.length && this.summary) { this.summary.hidden = false; this.syncSummary(); }
    this.offerResume();
  };
  Renders.prototype.discardResume = function () {
    var self = this;
    if (!this.pending.length) return;
    if (!window.confirm('Discard ' + this.pending.length + ' unfinished upload' + (this.pending.length === 1 ? '' : 's') + '? The pieces already sent are deleted from the server.')) return;
    this.pending.forEach(function (e) { self.chunk.abortStored(e); });
    this.offerResume();
  };
  Renders.prototype.retryFailed = function () { var self = this; this.jobs.forEach(function (j) { if (j.state === 'failed' && j.retryable) self.retry(j); }); };
  Renders.prototype.clearList = function () {
    this.jobs = this.jobs.filter(function (j) { return j.state === 'queued' || j.state === 'uploading'; });
    $$('[data-renders-item]', this.list).forEach(function (li) { if (!li._job || (li._job.state !== 'queued' && li._job.state !== 'uploading')) li.remove(); });
    if (this.summary) this.summary.hidden = this.jobs.length === 0;
    this.syncSummary();
  };
  Renders.prototype.syncSummary = function () {
    var ok = 0, fail = 0, left = 0, retry = 0;
    this.jobs.forEach(function (j) { if (j.state === 'done') ok++; else if (j.state === 'failed') { fail++; if (j.retryable) retry++; } else left++; });
    if (this.summaryText) this.summaryText.textContent = ok + ' uploaded' + (fail ? ' · ' + fail + ' failed' : '') + (left ? ' · ' + left + ' to go' : '');
    var ra = $('[data-renders-retry-all]', this.root); if (ra) ra.hidden = retry === 0;
  };
  Renders.prototype.next = function () {
    if (this.busy) return;
    if (!this.queue.length) { this.finishBatch(); return; }
    var self = this, job = this.queue.shift(), item = job.item;
    var prog = $('[data-upload-progress]', item), status = $('[data-upload-status]', item);
    this.busy = true; job.state = 'uploading'; job.tries++;
    prog.hidden = false; status.textContent = 'Uploading… 0%';
    // The server's probe decides: files above chunk_size (and every resumed job) go in pieces, the rest in one request.
    this.probe().then(function (info) {
      if (self.chunk && info && (job.uploadId || job.file.size > info.chunk_size)) self.sendChunked(job, info);
      else self.sendSingle(job);
    });
  };
  /** Shared success handling: the tile turns into an "open in Assets" link, the series picker learns the series. */
  Renders.prototype.done = function (job, data) {
    var t = job.target, item = job.item, status = $('[data-upload-status]', item), fill = $('[data-upload-fill]', item), created = this.createdSeries[job.batch];
    job.state = 'done';
    if (fill) fill.style.transform = 'translateX(0)';
    if (data.series && data.series.id) this.noteSeries(job, data.series);
    var sid = (data.series && data.series.id) || t.seriesId || (created && created.id) || 0;
    var link = (this.rc.assetsUrl || '').replace('__TIRE__', String(t.tireId)).replace('__SERIES__', String(sid)) + '&image=' + encodeURIComponent(data.image.id);
    status.innerHTML = 'Uploaded · To Review — <a href="' + esc(link) + '">open in Assets</a>';
    status.classList.remove('is-error'); status.classList.add('is-ok');
    if (data.image.thumb) { var th = $('[data-upload-thumb]', item); if (th && !isVideoFile(job.file)) th.innerHTML = '<img src="' + esc(data.image.thumb) + '" alt="">'; }
  };
  /** Chunked path (chunk-upload.js): init → pieces with progress / retry → finish; the ledger entry survives a reload. */
  Renders.prototype.sendChunked = function (job, info) {
    var self = this, t = job.target, item = job.item, file = job.file;
    var fill = $('[data-upload-fill]', item), status = $('[data-upload-status]', item), cancel = $('[data-renders-cancel]', item);
    var client = cfg.client || (document.body.dataset.client || '');
    var fields = { client: client, tire_id: t.tireId, batch: job.batch, actor: App.actor || 'admin' };
    var created = this.createdSeries[job.batch];
    if (t.seriesId) fields.series_id = t.seriesId;
    else if (created) fields.series_id = created.id;
    else fields.new_series = t.newSeries;
    if (cancel) cancel.hidden = false;
    var ctl = this.chunk.send({
      endpoint: this.endpoint, file: file, fields: fields, chunkSize: info.chunk_size, uploadId: job.uploadId || null,
      onInit: function (d) {
        job.uploadId = d.upload_id;
        if (d.series && d.series.id) { if (!t.seriesId) self.createdSeries[job.batch] = d.series; fields.series_id = d.series.id; }
        self.chunk.remember({ id: d.upload_id, kind: 'tire', endpoint: self.endpoint, client: client, name: file.name, size: file.size, type: file.type || '',
                              fields: { tire_id: t.tireId, series_id: fields.series_id || 0 }, label: t.tireName + ' · ' + t.label });
      },
      onProgress: function (p) { if (fill) fill.style.transform = 'translateX(' + (p.pct - 100) + '%)'; status.textContent = 'Uploading… ' + p.text; },
      onRetry: function (r) { status.textContent = 'Connection hiccup — retrying that piece (' + r.attempt + ' of ' + r.max + ')…'; }
    });
    job.ctl = ctl;
    var settle = function () { job.ctl = null; if (cancel) cancel.hidden = true; self.busy = false; self.syncSummary(); self.next(); };
    ctl.promise.then(function (data) {
      self.chunk.forget(job.uploadId); job.uploadId = null;
      self.done(job, data);
      settle();
    }, function (e) {
      if (e && e.aborted) { self.chunk.forget(job.uploadId); job.uploadId = null; self.fail(job, 'Cancelled', false); settle(); return; }
      var retryable = !!(e && e.retryable);
      if (!retryable || (e && e.expired)) { self.chunk.forget(job.uploadId); job.uploadId = null; }
      self.fail(job, (e && e.error) || 'Upload failed', retryable);
      settle();
    });
  };
  /** Single-request path (small files): one multipart POST with xhr.upload progress. */
  Renders.prototype.sendSingle = function (job) {
    var self = this, item = job.item, file = job.file, t = job.target;
    var fill = $('[data-upload-fill]', item), status = $('[data-upload-status]', item);
    var fd = new FormData();
    fd.append('client', cfg.client || (document.body.dataset.client || ''));
    fd.append('tire_id', String(t.tireId));
    // A "New series…" drop: the first file creates it; the reply's series.id is reused for the rest of the batch.
    var created = this.createdSeries[job.batch];
    if (t.seriesId) fd.append('series_id', String(t.seriesId));
    else if (created) fd.append('series_id', String(created.id));
    else fd.append('new_series', t.newSeries);
    fd.append('batch', job.batch);
    fd.append('actor', App.actor || 'admin');
    fd.append('file', file, file.name);
    var xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', function (e) {
      if (!e.lengthComputable) return;
      var pct = Math.round(e.loaded / e.total * 100);
      fill.style.transform = 'translateX(' + (pct - 100) + '%)';
      status.textContent = 'Uploading… ' + pct + '%';
    });
    xhr.onload = function () {
      var data = null; try { data = JSON.parse(xhr.responseText); } catch (e) {}
      fill.style.transform = 'translateX(0)';
      if (!data || data.ok === false || xhr.status >= 400 || !data.image) {
        var msg = (data && data.error) || ('Upload failed (' + xhr.status + ')');
        self.fail(job, msg, [400, 403, 404, 409, 413, 415, 422].indexOf(xhr.status) === -1);   // bad request / seat / type / size / not migrated: retrying the same file cannot help
      } else {
        self.done(job, data);
      }
      self.busy = false; self.syncSummary(); self.next();
    };
    xhr.onerror = function () { self.fail(job, 'Network error — try again.', true); self.busy = false; self.next(); };
    xhr.open('POST', this.endpoint);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(fd);
  };
  /** The server created (or resolved) a series: remember it for the batch and show it in the pickers/list. */
  Renders.prototype.noteSeries = function (job, series) {
    var tire = null, t = job.target;
    for (var i = 0; i < this.tires.length; i++) if (this.tires[i].id === t.tireId) tire = this.tires[i];
    if (!tire) return;
    if (!t.seriesId) this.createdSeries[job.batch] = series;
    var s = this.seriesOf(tire, series.id);
    if (!s) { s = { id: series.id, name: series.name || t.newSeries, slug: series.slug || '', folder: series.folder || '', drive_url: series.drive_url || null, counts: { pending: 0, approved: 0, denied: 0, total: 0 } }; tire.series.push(s); }
    s.counts.pending++; s.counts.total++;
    var wasNew = this.seriesSel.value === NEW_SERIES;
    this.rc.series = wasNew ? s.id : parseInt(this.seriesSel.value, 10);
    if (wasNew && this.newName) this.newName.value = '';
    // A "New series…" drop with a Drive link: the first file created the series, now attach the link to it (once).
    if (wasNew && this.newDrive && this.newDrive.value.trim() && !s.drive_url) {
      var url = this.newDrive.value.trim(), self = this;
      this.newDrive.value = '';
      App.post(this.statusEndpoint, { action: 'series_drive', series_id: s.id, drive_url: url, actor: App.actor }).then(function (res) {
        if (!res.ok) { toast(res.error || 'Could not save the Google Drive link', { kind: 'error', duration: 6000 }); self.renderSeriesList(); return; }
        s.drive_url = (res.data && res.data.series && res.data.series.drive_url) || url;
        self.renderSeriesList();
      });
    }
    if (this.tireSel.value === String(tire.id)) this.syncSeries(); else this.renderSeriesList();
  };
  Renders.prototype.finishBatch = function () {
    if (this._toasted === this.jobs.length || !this.jobs.length) return;
    var ok = 0, fail = 0;
    this.jobs.forEach(function (j) { if (j.state === 'done') ok++; else if (j.state === 'failed') fail++; });
    if (ok + fail !== this.jobs.length) return;
    this._toasted = this.jobs.length;
    toast(ok + ' uploaded' + (fail ? ' · ' + fail + ' failed' : ''), { kind: fail ? 'error' : 'success', duration: 4000 });
  };
  /* ---- series list: rename / reorder / delete (tire-status.php) ---- */
  Renders.prototype.renderSeriesList = function () {
    var tire = this.tire(), self = this;
    if (!this.seriesList || !tire) return;
    this.seriesList.innerHTML = tire.series.map(function (s, i) {
      var c = s.counts || {};
      var line = (c.pending || 0) + ' to review · ' + (c.approved || 0) + ' approved' + ((c.denied || 0) ? ' · ' + c.denied + ' needs changes' : '') + ' · ' + (c.total || 0) + (c.total === 1 ? ' file' : ' files');
      var drive = self.driveOn
        ? '<div class="studio-series-drive' + (s.drive_url ? ' is-set' : '') + '" data-series-drive-wrap>' + ICON.drive
          + '<input class="ui-input studio-series-drive-input" type="url" maxlength="512" inputmode="url" autocomplete="off" spellcheck="false" value="' + esc(s.drive_url || '') + '" placeholder="Google Drive link (optional)" data-series-drive aria-label="Google Drive link for ' + esc(s.name) + '">'
          + '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-series-drive-save>Save</button>'
          + '<a class="ui-btn ui-btn--plain ui-btn--sm studio-series-drive-open" href="' + esc(s.drive_url || '#') + '" target="_blank" rel="noopener noreferrer" data-series-drive-open' + (s.drive_url ? '' : ' hidden') + '>Open</a>'
          + '</div>'
        : '';
      return '<li class="studio-series-row' + (drive ? ' studio-series-row--drive' : '') + '" data-series-row="' + s.id + '">'
        + '<div class="studio-series-main"><input class="ui-input studio-series-name" type="text" maxlength="80" value="' + esc(s.name) + '" data-series-name aria-label="Series name">'
        + '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-series-rename-save>Rename</button></div>'
        + '<div class="studio-series-meta text-secondary">' + esc(line) + (s.folder ? ' · <code>' + esc(s.folder) + '/</code>' : '') + '</div>'
        + drive
        + '<div class="studio-series-ctl">'
        + '<a class="ui-btn ui-btn--plain ui-btn--sm" href="' + esc((self.rc.assetsUrl || '').replace('__TIRE__', String(tire.id)).replace('__SERIES__', String(s.id))) + '">Open</a>'
        + '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-series-up aria-label="Move ' + esc(s.name) + ' up"' + (i === 0 ? ' disabled' : '') + '>' + ICON.left + '</button>'
        + '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-series-down aria-label="Move ' + esc(s.name) + ' down"' + (i === tire.series.length - 1 ? ' disabled' : '') + '>' + ICON.right + '</button>'
        + '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn" data-series-delete>Delete</button>'
        + '</div></li>';
    }).join('');
    if (this.seriesEmpty) this.seriesEmpty.hidden = tire.series.length > 0;
  };
  Renders.prototype.renameSeries = function (row) {
    var self = this, tire = this.tire(), id = parseInt(row.dataset.seriesRow, 10), s = this.seriesOf(tire, id);
    var input = $('[data-series-name]', row), name = (input.value || '').trim();
    if (!s || !name || name === s.name) return;
    App.post(this.statusEndpoint, { action: 'series_rename', series_id: id, name: name, actor: App.actor }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Could not rename', { kind: 'error' }); input.value = s.name; return; }
      s.name = (res.data && res.data.series && res.data.series.name) || name;
      toast('Series renamed', { kind: 'success' });
      self.syncSeries();
    });
  };
  /** Google Drive link of a series (tire-status.php series_drive): '' removes it; the server validates the host. */
  Renders.prototype.saveDrive = function (row) {
    var self = this, tire = this.tire(), id = parseInt(row.dataset.seriesRow, 10), s = this.seriesOf(tire, id);
    var input = $('[data-series-drive]', row), url = input ? (input.value || '').trim() : '';
    if (!s || !input || url === (s.drive_url || '')) return;
    if (url && !/^https:\/\/(www\.)?(drive\.google\.com|docs\.google\.com|photos\.google\.com|photos\.app\.goo\.gl)\//i.test(url)) {
      input.setAttribute('aria-invalid', 'true'); input.focus();
      toast('Enter a Google Drive share link (https://drive.google.com/…)', { kind: 'error', duration: 5000 }); return;
    }
    var btn = $('[data-series-drive-save]', row); if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
    App.post(this.statusEndpoint, { action: 'series_drive', series_id: id, drive_url: url, actor: App.actor }).then(function (res) {
      if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); }
      if (!res.ok) { input.setAttribute('aria-invalid', 'true'); input.focus(); toast(res.error || 'Could not save the Google Drive link', { kind: 'error', duration: 6000 }); return; }
      s.drive_url = (res.data && res.data.series && res.data.series.drive_url) || (url || null);
      toast(s.drive_url ? 'Google Drive link saved' : 'Google Drive link removed', { kind: 'success' });
      self.renderSeriesList();
    });
  };
  Renders.prototype.moveSeries = function (row, dir) {
    var self = this, tire = this.tire(), id = parseInt(row.dataset.seriesRow, 10), i = -1;
    tire.series.forEach(function (s, k) { if (s.id === id) i = k; });
    var j = i + dir;
    if (i < 0 || j < 0 || j >= tire.series.length) return;
    var moved = tire.series.splice(i, 1)[0]; tire.series.splice(j, 0, moved);
    this.syncSeries();
    var params = { action: 'series_reorder', tire_id: tire.id, actor: App.actor };
    tire.series.forEach(function (s, k) { params['ids[' + k + ']'] = s.id; });
    App.post(this.statusEndpoint, params).then(function (res) {
      if (res.ok) return;
      var back = tire.series.splice(j, 1)[0]; tire.series.splice(i, 0, back);   // roll back
      self.syncSeries();
      toast(res.error || 'Could not reorder', { kind: 'error' });
    });
  };
  Renders.prototype.deleteSeries = function (row) {
    var self = this, tire = this.tire(), id = parseInt(row.dataset.seriesRow, 10), s = this.seriesOf(tire, id);
    if (!s) return;
    if (!window.confirm('Remove “' + s.name + '” (' + (s.counts.total || 0) + ' files) from ' + tire.name + '? The client will no longer see it.')) return;
    var files = window.confirm('Also delete the files on disk?\n\nOK = delete the files too · Cancel = keep them in ' + (tire.folder || 'the tire folder') + '/' + (s.folder || s.slug || ''));
    App.post(this.statusEndpoint, { action: 'series_delete', series_id: id, delete_files: files ? 1 : 0, actor: App.actor }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Could not delete', { kind: 'error' }); return; }
      tire.series = tire.series.filter(function (x) { return x.id !== id; });
      if (String(self.rc.series) === String(id)) self.rc.series = 0;
      toast('Series deleted', { kind: 'success' });
      self.syncSeries();
    });
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
  /* Hub: segmented sections, reply form, confirm forms                 */
  /* ================================================================== */
  function initHub() {
    var seg = $('.studio-segmented');
    if (seg) {
      seg.addEventListener('click', function (e) {
        var item = e.target.closest('[data-studio-tab]');
        if (!item) return;
        e.preventDefault();
        var tab = item.dataset.studioTab;
        $$('[data-studio-section]').forEach(function (s) { s.hidden = s.dataset.studioSection !== tab; });
        if (App.segmented && App.segmented.select) App.segmented.select(item);
        if (item.href && window.history && history.replaceState) history.replaceState(null, '', item.href);
        var section = $('[data-studio-section="' + tab + '"]');
        if (section) section.classList.add('ui-enter');
      });
    }
    var reply = $('[data-studio-reply]');
    if (reply) {
      reply.addEventListener('submit', function (e) {
        e.preventDefault();
        var input = $('[data-reply-input]', reply), text = (input.value || '').trim();
        if (!text) { input.focus(); return; }
        var actorEl = $('input[name="reply_actor"]:checked', reply);
        var btn = $('[data-reply-send]', reply);
        btn.disabled = true;
        App.post(cfg.endpoint || 'status.php', { id: reply.dataset.id, comment: text, actor: actorEl ? actorEl.value : 'admin' }).then(function (r) {
          if (r.ok) { window.location.reload(); return; }
          btn.disabled = false;
          toast(r.error || 'Could not send', { kind: 'error' });
        });
      });
    }
    $$('[data-confirm-submit]').forEach(function (form) {
      form.addEventListener('submit', function (e) { if (!window.confirm(form.dataset.confirmSubmit)) e.preventDefault(); });
    });
  }

  /* ================================================================== */
  App.studio = {
    uploads:  function (zone) { return new Uploads(zone); },
    renders:  function (root) { return new Renders(root); },
    export:   function (root) { return new Export(root); },
    linkTags: linkTags,
    formatWhen: formatWhen,
    instances: {}
  };

  function init() {
    $$('[data-upload-zone]').forEach(function (zone) { App.studio.instances.uploads = new Uploads(zone); });
    $$('[data-renders]').forEach(function (root) { App.studio.instances.renders = new Renders(root); });
    $$('[data-export]').forEach(function (root) { App.studio.instances.export = new Export(root); });
    initHub();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})(window, document);

/* =====================================================================
   Emails (Studio → Emails, add-email.php): group chips + the Live guard.
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
   Clients (Studio → Clients, partials/studio-clients.php → client-admin.php)
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

  /* Studio → Pages: "Repair server rules" → page-upload.php action=repair_media (media/pages/<client>/:
     .htaccess rewritten when old / missing, files 0644 / folders 0755), then a reload so the row glyphs refresh. */
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
      window.setTimeout(function () { window.location.reload(); }, 900);
    });
  });

  /* Studio → Pages: "Extract embedded images" under a page whose HTML is over ~400 KB → page-upload.php
     action=extract_inline (base64 data: URIs → assets/ files, references rewritten), then a reload. */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-pages-extract]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    var endpoint = btn.getAttribute('data-endpoint') || 'page-upload.php';
    btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Extracting…';
    App.post(endpoint, { action: 'extract_inline', page_id: btn.getAttribute('data-pages-extract'), actor: App.actor }).then(function (res) {
      btn.disabled = false; btn.removeAttribute('aria-busy'); btn.textContent = 'Extract embedded images';
      var d = res.data || {};
      if (!res.ok) { toast(res.error || 'Extraction failed', { kind: 'error' }); return; }
      var t = d.totals || {};
      toast(d.summary || 'Done', { kind: t.extracted > 0 || !(t.skipped || t.failed) ? 'success' : 'error' });
      if (t.extracted > 0) window.setTimeout(function () { window.location.reload(); }, 900);
    });
  });
})(window, document);
