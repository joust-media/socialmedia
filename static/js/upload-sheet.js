/* =====================================================================
   App.uploadSheet — the one uploader (admin). Files → Destination → Upload.
   Server: upload-sheet.php (clients / init: tires, series, limits, URLs);
   the files go to the endpoints each destination always had, through
   App.chunkUpload (chunk-upload.js: one request when small, resumable
   pieces when large — videos up to 4 GB):

     series     tire-upload.php            tire_id + series_id | new_series   → tire_images 'pending'
     reference  upload-chunk.php feature   feature_id (images only, 6 / tire) → tire_images (default 'pending')
     library    upload-chunk.php library                                      → library_images 'pending'
     post       upload-chunk.php post      claim tokens → App.newPost.open({preselect}) → one Draft post (carousel)
     post each  upload-chunk.php batch     → batch-process.php claimed[]      → one Draft post per file

   App.uploadSheet.open({client, dest: 'series'|'reference'|'library'|'post', tire, series ('new' | id),
                         each (post: a draft per file), files: [File]}) → Promise
   App.uploadSheet.close(force)   App.uploadSheet.isOpen()

   Entry points (delegated, any admin page that loads this file):
     "+ New → Upload"            App.newMenu.handle('upload', …)
     [data-upload-open]          a contextual button: data-upload-dest, data-upload-tire, data-upload-series,
                                 data-upload-each, data-client (the href is the no-JS deep link)
     [data-upload-drop]          the same, and files dropped on it come along
     ?upload=1[&dest=…&tire=…&series=…&each=1]   opens on load (upload / dest / each / tire are removed from
                                 the address bar); retired upload routes redirect here.
   Done: the sheet closes with a toast + a link to where the files landed (already on that page → it reloads
   onto the destination's To Review list and toasts there).
   Events: upload:open {client, dest}; upload:done {dest, ok, failed, url, created (a new series)} once a run settles;
   upload:close.
   Dialog: role=dialog + aria-modal, focus trap, Esc, "stop uploading?" guard (also beforeunload).
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  if (App.uploadSheet) return;

  var cfg = window.UploadSheetConfig || {};
  var EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov'];
  var IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
  var MAX_FILES = 50;
  var ACCEPT = 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov';
  var DRIVE_RE = /^https:\/\/(www\.)?(drive\.google\.com|docs\.google\.com|photos\.google\.com|photos\.app\.goo\.gl)\//i;   // the hosts tire-status.php accepts for a series link

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function toast(msg, kind, dur, link) { if (App.toast) App.toast(msg, { kind: kind, duration: dur, link: link }); }
  function fileExt(name) { var m = String(name || '').toLowerCase().match(/\.([a-z0-9]+)$/); return m ? m[1] : ''; }
  function isVideo(f) { var e = fileExt(f.name); return /^video\//i.test(f.type || '') || ['mp4', 'webm', 'mov', 'm4v'].indexOf(e) !== -1; }
  function fmtBytes(n) { return App.chunkUpload && App.chunkUpload.fmt ? App.chunkUpload.fmt.bytes(n) : Math.round(n / 1048576) + ' MB'; }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }

  var I = {
    x: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    play: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>',
    up: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15.5V4"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/><path d="M4.5 16v2a2.5 2.5 0 0 0 2.5 2.5h10a2.5 2.5 0 0 0 2.5-2.5v-2"/></svg>',
    tire: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.25"/><path d="M12 8.75V3M14.81 10.38l4.98-2.88M14.81 13.62l4.98 2.88M12 15.25V21M9.19 13.62l-4.98 2.88M9.19 10.38 4.21 7.5"/></svg>',
    ref: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-6"/></svg>',
    photo: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="6.5" width="14" height="13" rx="2.5"/><path d="M8 3.5h10A2.5 2.5 0 0 1 20.5 6v9.5"/><circle cx="8.2" cy="10.8" r="1.5"/><path d="m4 17 3.6-3.6a1 1 0 0 1 1.4 0l2.2 2.2 2.4-2.4a1 1 0 0 1 1.4 0l2.5 2.5"/></svg>',
    grid: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.8"/></svg>'
  };

  /* ------------------------------------------------------------------ */
  /* Server                                                              */
  /* ------------------------------------------------------------------ */
  function endpoint() { return cfg.endpoint || ((cfg.base || '') + '/upload-sheet.php'); }
  function getJson(action, slug) {
    var url = endpoint() + '?action=' + encodeURIComponent(action) + (slug ? '&client=' + encodeURIComponent(slug) : '');
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (d) { return { ok: r.ok && d && d.ok !== false, status: r.status, data: d || {} }; }); })
      .catch(function () { return { ok: false, status: 0, data: { error: 'Network error' } }; });
  }
  function postForm(url, params) {
    var body = new URLSearchParams();
    Object.keys(params).forEach(function (k) { var v = params[k]; if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', String(x)); }); else if (v !== undefined && v !== null) body.append(k, String(v)); });
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json' }, body: body.toString() })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (d) { return { ok: r.ok && d && d.ok !== false, status: r.status, data: d || {} }; }); })
      .catch(function () { return { ok: false, status: 0, data: { error: 'Network error' } }; });
  }
  function ensureCss() {
    (cfg.css || []).forEach(function (href) {
      var key = href.split('?')[0];
      if ($$('link[rel="stylesheet"]').some(function (l) { return (l.getAttribute('href') || '').split('?')[0] === key; })) return;
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l);
    });
  }

  /* ------------------------------------------------------------------ */
  /* State + DOM                                                         */
  /* ------------------------------------------------------------------ */
  var S = null, R = null, uid = 0;

  function fresh(opts) {
    return {
      opts: opts, slug: '', init: null, step: 'files',
      files: [],                                   // {id, file, url, video, error}
      dest: { kind: opts.dest || '', tire: opts.tire ? parseInt(opts.tire, 10) || 0 : 0, series: opts.series || '', newSeries: '', newDrive: '', each: !!opts.each },
      jobs: [], busy: false, batch: '', created: null, started: false, handedOff: false, confirming: false,
      resume: [], lastFocus: document.activeElement
    };
  }

  function shell() {
    var el = document.createElement('div');
    el.className = 'us-root';
    el.id = 'usSheet';
    el.innerHTML =
      '<div class="us-backdrop" data-us-backdrop></div>' +
      '<div class="us-panel" role="dialog" aria-modal="true" aria-labelledby="usTitle" tabindex="-1">' +
        '<header class="us-head">' +
          '<div class="us-head-client" data-us-client></div>' +
          '<h2 class="us-title" id="usTitle">Upload</h2>' +
          '<button type="button" class="us-close" data-us-close aria-label="Close">' + I.x + '</button>' +
        '</header>' +
        '<ol class="us-steps" aria-label="Steps" data-us-steps>' +
          '<li><button type="button" class="us-step" data-us-step="files"><span class="us-step-n">1</span>Files</button></li>' +
          '<li><button type="button" class="us-step" data-us-step="dest"><span class="us-step-n">2</span>Destination</button></li>' +
          '<li><button type="button" class="us-step" data-us-step="upload"><span class="us-step-n">3</span>Upload</button></li>' +
        '</ol>' +
        '<div class="us-body" data-us-body>' +
          '<section class="us-pane" data-us-pane="files" aria-label="Files">' +
            '<div class="us-resume" data-us-resume hidden role="status"><span class="us-resume-text" data-us-resume-text></span>' +
              '<label class="ui-btn ui-btn--filled ui-btn--sm">Pick the files<input type="file" class="ui-visually-hidden" data-us-resume-input multiple accept="' + ACCEPT + '"></label>' +
              '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-us-resume-discard>Discard</button></div>' +
            '<label class="us-drop" data-us-drop>' +
              '<input type="file" class="ui-visually-hidden" data-us-file multiple accept="' + ACCEPT + '">' +
              '<span class="us-drop-icon">' + I.up + '</span><span class="us-drop-label">Choose files</span>' +
              '<span class="us-drop-hint text-secondary" data-us-drop-hint>or drop them here · images up to 50 MB, videos up to 4 GB (large files go up in pieces and can resume)</span>' +
            '</label>' +
            '<ul class="us-list" role="list" aria-label="Chosen files" data-us-files></ul>' +
          '</section>' +
          '<section class="us-pane" data-us-pane="dest" aria-label="Destination" hidden>' +
            '<div class="us-summary" data-us-summary></div>' +
            '<fieldset class="us-cards" data-us-cards><legend>Where do these files go?</legend></fieldset>' +
            '<p class="us-note" data-us-dest-note role="status"></p>' +
          '</section>' +
          '<section class="us-pane" data-us-pane="upload" aria-label="Upload" hidden>' +
            '<p class="us-target" data-us-target></p><p class="us-target-sub" data-us-target-sub></p>' +
            '<ul class="us-list" role="list" aria-label="Uploads" data-us-jobs></ul>' +
          '</section>' +
        '</div>' +
        '<footer class="us-foot ui-glass ui-glass--top">' +
          '<div class="us-foot-info" data-us-info aria-live="polite"></div>' +
          '<div class="us-foot-actions" data-us-actions></div>' +
        '</footer>' +
        '<div class="us-confirm" data-us-confirm hidden role="alertdialog" aria-modal="true" aria-labelledby="usConfirmTitle" aria-describedby="usConfirmText">' +
          '<div class="us-confirm-card"><h3 class="us-confirm-title" id="usConfirmTitle">Stop uploading?</h3>' +
          '<p class="us-confirm-text text-secondary" id="usConfirmText" data-us-confirm-text></p>' +
          '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-us-keep>Keep uploading</button>' +
          '<button type="button" class="ui-btn ui-btn--deny" data-us-stop>Stop</button></div></div></div>' +
        '<div class="us-chooser" data-us-chooser hidden></div>' +
      '</div>';
    return el;
  }

  /* ------------------------------------------------------------------ */
  /* Open / close                                                        */
  /* ------------------------------------------------------------------ */
  function open(opts) {
    opts = opts || {};
    if (S) {   // already open: dropped files join the list, a new destination is preselected
      if (opts.files && opts.files.length && !S.started) { addFiles(opts.files); }
      return Promise.resolve(R);
    }
    ensureCss();
    S = fresh(opts);
    R = shell();
    document.body.appendChild(R);
    if (App.lockScroll) App.lockScroll();
    bind();
    requestAnimationFrame(function () { requestAnimationFrame(function () { if (R) R.classList.add('is-visible'); }); });
    setTimeout(function () { var p = R && $('.us-panel', R); if (p) try { p.focus({ preventScroll: true }); } catch (e) { p.focus(); } }, 40);
    var slug = opts.client || cfg.client || (document.body && document.body.dataset.client) || '';
    if (!slug) return chooseClient();
    return start(slug);
  }

  function chooseClient() {
    var box = $('[data-us-chooser]', R);
    box.hidden = false;
    box.innerHTML = '<p class="text-secondary us-lead">Which client are these files for?</p><ul class="ui-list us-chooser-list" role="list"><li class="ui-row"><span class="text-secondary">Loading…</span></li></ul>';
    renderFooter();
    return getJson('clients').then(function (res) {
      if (!S) return null;
      var list = $('.us-chooser-list', box);
      if (!res.ok) { list.innerHTML = '<li class="ui-row text-secondary">' + esc(res.data.error || 'Could not load clients') + '</li>'; return null; }
      list.innerHTML = res.data.clients.map(function (c) {
        return '<li><button type="button" class="ui-row us-chooser-row" data-us-pick-client="' + esc(c.slug) + '">' + avatarHtml(c, 'ui-avatar--sm')
          + '<span class="ui-row-body"><span class="ui-row-title">' + esc(c.name) + '</span></span></button></li>';
      }).join('');
      var first = $('[data-us-pick-client]', list); if (first) first.focus();
      return R;
    });
  }

  function avatarHtml(c, cls) {
    var name = (c && c.name) || 'Joust Media';
    if (c && c.logo) return '<img class="ui-avatar ' + cls + '" src="' + esc(c.logo) + '" alt="" width="36" height="36">';
    return '<span class="ui-avatar ' + cls + ' ui-avatar--initial" aria-hidden="true">' + esc(name.charAt(0).toUpperCase()) + '</span>';
  }

  function start(slug) {
    S.slug = slug;
    $('[data-us-chooser]', R).hidden = true;
    R.classList.add('is-loading');
    return getJson('init', slug).then(function (res) {
      if (!S) return null;
      R.classList.remove('is-loading');
      if (!res.ok) { toast(res.data.error || 'Could not open the uploader', 'error'); forceClose(); return null; }
      S.init = res.data;
      var c = S.init.client;
      $('[data-us-client]', R).innerHTML = avatarHtml(c, 'ui-avatar--sm') + '<span class="us-client-name">' + esc(c.name) + '</span>';
      var lim = S.init.limits || {};
      $('[data-us-drop-hint]', R).textContent = 'or drop them here · images up to ' + fmtBytes(lim.image || 52428800) + ', videos up to ' + fmtBytes(lim.video || 4294967296) + ' (large files go up in pieces and can resume)';
      normalizeDest();
      offerResume();
      if (S.opts.files && S.opts.files.length) addFiles(S.opts.files, true);
      setStep(S.files.length ? 'dest' : 'files');
      var focusEl = S.step === 'files' ? $('[data-us-file]', R) : $('[data-us-cards] input:checked', R) || $('[data-us-close]', R);
      if (focusEl) try { focusEl.focus({ preventScroll: true }); } catch (e) {}
      document.dispatchEvent(new CustomEvent('upload:open', { detail: { client: slug, dest: S.dest.kind } }));
      return R;
    });
  }

  /** The preselected destination must exist for this client (a tire of theirs, a series of that tire). */
  function normalizeDest() {
    var d = S.dest, avail = available();
    if (d.kind && avail.indexOf(d.kind) === -1) d.kind = '';
    // No (valid) tire asked for: the first tire that already has series, else the first tire.
    if (!tireById(d.tire)) {
      var withSeries = (S.init.tires || []).filter(function (t) { return t.series.length; })[0];
      d.tire = withSeries ? withSeries.id : (S.init.tires.length ? S.init.tires[0].id : 0);
    }
    if (d.series !== 'new') {
      var tt = tireById(d.tire), sid = parseInt(d.series, 10) || 0;
      if (!tt || !tt.series.some(function (s) { return s.id === sid; })) d.series = latestSeries(tt);
    }
    if (d.series === 'new' && !d.newSeries) d.newSeries = defaultSeriesName(tireById(d.tire));
  }
  function available() {
    var f = S.init.features || {}, out = [], hasTires = S.init.tires && S.init.tires.length > 0;
    if (f.series && hasTires) out.push('series');
    if (hasTires) out.push('reference');
    if (f.library) out.push('library');
    out.push('post');
    return out;
  }
  function tireById(id) { id = parseInt(id, 10) || 0; for (var i = 0; i < (S.init.tires || []).length; i++) if (S.init.tires[i].id === id) return S.init.tires[i]; return null; }
  function seriesById(t, id) { id = parseInt(id, 10) || 0; if (!t) return null; for (var i = 0; i < t.series.length; i++) if (t.series[i].id === id) return t.series[i]; return null; }
  function defaultSeriesName(t) { return 'Series ' + ((t && t.series ? t.series.length : 0) + 1); }
  /** The tire's newest series (the last in series order — where new renders usually go), else 'new'. */
  function latestSeries(t) { return t && t.series.length ? String(t.series[t.series.length - 1].id) : 'new'; }

  function inFlight() { return S && S.jobs.some(function (j) { return j.state === 'uploading' || j.state === 'queued'; }); }
  function requestClose() {
    if (!S) return;
    if (inFlight()) { showConfirm(true); return; }
    finishAndClose();
  }
  /** Closing after a run: say where the files went (toast + link) before the sheet goes. */
  function finishAndClose() {
    var sum = S && S.started && !S.handedOff ? summary() : null;
    forceClose();
    if (!sum || !sum.ok) return;
    var kind = sum.failed ? 'error' : 'success';
    // Already on that page (the same Assets view / tire): show the new files right away — reload onto the
    // destination's To Review list and toast there.
    if (sum.url && landsHere(sum.url)) {
      try { sessionStorage.setItem('us.toast', JSON.stringify({ m: sum.message, k: kind })); } catch (e) {}
      window.location.href = sum.url;
      return;
    }
    toast(sum.message, kind, 7000, sum.url ? { href: sum.url, label: sum.linkLabel } : null);
  }
  /** Is the destination URL the page we are on (same script, client, view and tire)? */
  function landsHere(u) {
    var a, b;
    try { a = new URL(u, window.location.href); b = new URL(window.location.href); } catch (e) { return false; }
    if (a.pathname !== b.pathname) return false;
    return ['client', 'view', 'item'].every(function (k) { return (a.searchParams.get(k) || '') === (b.searchParams.get(k) || ''); });
  }
  function showConfirm(on) {
    var c = $('[data-us-confirm]', R); if (!c) return;
    S.confirming = on;
    c.hidden = !on;
    if (on) {
      var left = S.jobs.filter(function (j) { return j.state === 'uploading' || j.state === 'queued'; }).length;
      $('[data-us-confirm-text]', R).textContent = plural(left, 'file has', 'files have') + ' not finished uploading. Files already uploaded stay where they landed.';
      $('[data-us-keep]', c).focus();
    } else { var b = $('[data-us-close]', R); if (b) b.focus(); }
  }
  function forceClose() {
    if (!S || !R) return;
    S.jobs.forEach(function (j) {
      if (j.ctl && j.state === 'uploading') { try { j.ctl.abort(); } catch (e) {} }
      if (j.state === 'uploading' || j.state === 'queued') j.state = 'cancelled';
      // A New post (one post) run that never reached the pop-up: its parked files would wait 24 h for nobody.
      if (j.token && !S.handedOff && j.dest.kind === 'post' && !j.dest.each && S.init) postForm(S.init.urls.upload, { action: 'claim_discard', token: j.token, client: S.slug });
    });
    S.files.forEach(function (f) { if (f.url) try { URL.revokeObjectURL(f.url); } catch (e) {} });
    var root = R, back = S.lastFocus;
    S = null; R = null;
    root.classList.remove('is-visible');
    setTimeout(function () {
      if (root.parentNode) root.parentNode.removeChild(root);
      if (App.unlockScroll) App.unlockScroll();
      if (back && back.focus && document.contains(back)) { try { back.focus({ preventScroll: true }); } catch (e) {} }
      document.dispatchEvent(new CustomEvent('upload:close'));
    }, App.reducedMotion && App.reducedMotion() ? 0 : 220);
  }

  /* ------------------------------------------------------------------ */
  /* Events                                                              */
  /* ------------------------------------------------------------------ */
  function bind() {
    R.addEventListener('click', onClick);
    R.addEventListener('change', onChange);
    R.addEventListener('input', onInput);
    var input = $('[data-us-file]', R);
    input.addEventListener('change', function () { addFiles(Array.prototype.slice.call(input.files || [])); input.value = ''; });
    var resumeInput = $('[data-us-resume-input]', R);
    resumeInput.addEventListener('change', function () { resumeFiles(Array.prototype.slice.call(resumeInput.files || [])); resumeInput.value = ''; });
    // The whole sheet is a drop target while files can still be added.
    var panel = $('.us-panel', R), depth = 0;
    panel.addEventListener('dragenter', function (e) { if (!hasFiles(e)) return; e.preventDefault(); depth++; if (S && !S.started) panel.classList.add('is-dragover'); });
    panel.addEventListener('dragover', function (e) { if (!hasFiles(e)) return; e.preventDefault(); });
    panel.addEventListener('dragleave', function () { depth = Math.max(0, depth - 1); if (!depth) panel.classList.remove('is-dragover'); });
    panel.addEventListener('drop', function (e) {
      if (!hasFiles(e)) return;
      e.preventDefault(); depth = 0; panel.classList.remove('is-dragover');
      if (!S || S.started || !S.init) return;
      addFiles(Array.prototype.slice.call(e.dataTransfer.files || []));
    });
  }
  function hasFiles(e) { var t = e.dataTransfer && e.dataTransfer.types; return !!t && Array.prototype.indexOf.call(t, 'Files') !== -1; }

  window.addEventListener('keydown', function (e) {
    if (!S || !R) return;
    if (e.key === 'Escape') {
      e.preventDefault(); e.stopImmediatePropagation();
      if (S.confirming) { showConfirm(false); return; }
      requestClose();
      return;
    }
    if (e.key === 'Tab') {
      e.stopImmediatePropagation();
      var scope = S.confirming ? $('[data-us-confirm]', R) : $('.us-panel', R);
      var items = $$('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', scope)
        .filter(function (el) { return el.offsetParent !== null || el === document.activeElement || (el.type === 'radio' && el.closest('.us-card') && el.closest('.us-card').offsetParent !== null); });
      if (!items.length) { e.preventDefault(); return; }
      var first = items[0], last = items[items.length - 1];
      if (!scope.contains(document.activeElement)) { e.preventDefault(); first.focus(); return; }
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }, true);
  window.addEventListener('beforeunload', function (e) {
    if (inFlight()) { e.preventDefault(); e.returnValue = ''; return ''; }
  });

  function onClick(e) {
    var t = e.target;
    if (t.closest('[data-us-backdrop]') || t.closest('[data-us-close]')) { requestClose(); return; }
    if (t.closest('[data-us-keep]')) { showConfirm(false); return; }
    if (t.closest('[data-us-stop]')) { showConfirm(false); stopAll(); finishAndClose(); return; }
    var pc = t.closest('[data-us-pick-client]'); if (pc) { start(pc.getAttribute('data-us-pick-client')); return; }
    var st = t.closest('[data-us-step]'); if (st) { var to = st.getAttribute('data-us-step'); if (canGo(to)) setStep(to); return; }
    var rm = t.closest('[data-us-file-remove]'); if (rm) { removeFile(rm.getAttribute('data-us-file-remove')); return; }
    var act = t.closest('[data-us-act]'); if (act && !act.disabled) { action(act.getAttribute('data-us-act')); return; }
    var jc = t.closest('[data-us-job-cancel]'); if (jc) { cancelJob(jobById(jc.getAttribute('data-us-job-cancel'))); return; }
    var jr = t.closest('[data-us-job-retry]'); if (jr) { retryJob(jobById(jr.getAttribute('data-us-job-retry'))); return; }
    if (t.closest('[data-us-resume-discard]')) { discardResume(); return; }
  }
  function onChange(e) {
    var t = e.target;
    if (t.name === 'usDest') { S.dest.kind = t.value; renderDest(); renderFooter(); return; }
    if (t.matches('[data-us-tire]')) {
      S.dest.tire = parseInt(t.value, 10) || 0;
      var tt = tireById(S.dest.tire);
      S.dest.series = latestSeries(tt);
      S.dest.newSeries = defaultSeriesName(tt);
      renderDest(); renderFooter(); return;
    }
    if (t.matches('[data-us-series]')) { S.dest.series = t.value; if (t.value === 'new' && !S.dest.newSeries) S.dest.newSeries = defaultSeriesName(tireById(S.dest.tire)); renderDest(); renderFooter(); return; }
    if (t.name === 'usEach') { S.dest.each = t.value === 'each'; renderNote(); renderFooter(); }
  }
  function onInput(e) {
    if (e.target.matches('[data-us-new-series]')) { S.dest.newSeries = e.target.value; renderNote(); renderFooter(); }
    if (e.target.matches('[data-us-new-drive]')) { S.dest.newDrive = e.target.value; renderFooter(); }
  }

  /* ------------------------------------------------------------------ */
  /* Steps                                                               */
  /* ------------------------------------------------------------------ */
  function canGo(step) {
    if (!S || !S.init) return false;
    if (S.started) return step === 'upload';
    if (step === 'files') return true;
    if (step === 'dest') return validFiles().length > 0;
    return false;
  }
  function setStep(step) {
    S.step = step;
    ['files', 'dest', 'upload'].forEach(function (k) { $('[data-us-pane="' + k + '"]', R).hidden = k !== step; });
    var order = ['files', 'dest', 'upload'], at = order.indexOf(step);
    $$('[data-us-step]', R).forEach(function (b) {
      var k = b.getAttribute('data-us-step'), i = order.indexOf(k);
      if (i === at) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
      b.classList.toggle('is-done', i < at);
      b.disabled = !(i < at && canGo(k));
    });
    if (step === 'files') renderFiles();
    if (step === 'dest') renderDest();
    if (step === 'upload') renderJobs();
    renderFooter();
    var body = $('[data-us-body]', R); if (body) body.scrollTop = 0;
  }

  /* ---- step 1: files ---- */
  function checkFile(f) {
    var ext = fileExt(f.name), lim = (S.init && S.init.limits) || {};
    if (['m4v', 'avi', 'mkv'].indexOf(ext) !== -1) return '.' + ext + ' isn\'t web-playable — convert it to MP4 first';
    if (EXTS.indexOf(ext) === -1) return 'Unsupported type — use JPG, PNG, GIF, WebP, MP4, WebM or MOV';
    var vid = isVideo(f), cap = vid ? (lim.video || 4294967296) : (lim.image || 52428800);
    if (f.size > cap) return (vid ? 'Videos' : 'Images') + ' must be under ' + fmtBytes(cap);
    if (!f.size) return 'Empty file';
    return '';
  }
  function addFiles(list, quiet) {
    if (!S || S.started || !list.length) return;
    var room = MAX_FILES - S.files.length, skipped = 0;
    list.forEach(function (f) {
      if (room <= 0) { skipped++; return; }
      if (S.files.some(function (x) { return x.file.name === f.name && x.file.size === f.size && x.file.lastModified === f.lastModified; })) return;   // the same file twice
      room--;
      var vid = isVideo(f), err = checkFile(f);
      S.files.push({ id: 'f' + (++uid), file: f, video: vid, error: err, url: !vid && !err && /^image\//.test(f.type || '') ? URL.createObjectURL(f) : '' });
    });
    if (skipped) toast(plural(skipped, 'file') + ' left out — up to ' + MAX_FILES + ' at a time', 'error', 4000);
    if (quiet || !S.init) return;   // still loading: start() moves on to Destination once the client's data is in
    // Picking files is the whole of step 1: go straight on to the destination (the list stays one tap away).
    if (validFiles().length && S.step === 'files') setStep('dest');
    else if (S.step === 'dest') { renderDest(); renderFooter(); }
    else { renderFiles(); renderFooter(); }
    if (!validFiles().length && S.files.length) { renderFiles(); renderFooter(); }
  }
  function removeFile(id) {
    S.files = S.files.filter(function (f) { if (f.id === id && f.url) try { URL.revokeObjectURL(f.url); } catch (e) {} return f.id !== id; });
    renderFiles(); renderFooter();
  }
  function validFiles() { return S ? S.files.filter(function (f) { return !f.error; }) : []; }
  function thumbHtml(f) { return f.url ? '<img src="' + esc(f.url) + '" alt="">' : (f.video ? I.play : I.photo); }
  function renderFiles() {
    var ul = $('[data-us-files]', R);
    ul.innerHTML = S.files.map(function (f) {
      return '<li class="us-row' + (f.error ? ' is-invalid' : '') + '" data-us-file-row="' + f.id + '">'
        + '<span class="us-row-thumb">' + thumbHtml(f) + '</span>'
        + '<span class="us-row-body"><span class="us-row-name">' + esc(f.file.name) + '</span>'
        + '<span class="us-row-meta">' + esc(f.error || (fmtBytes(f.file.size) + ' · ' + (f.video ? 'video' : 'image'))) + '</span></span>'
        + '<button type="button" class="us-x" data-us-file-remove="' + f.id + '" aria-label="Remove ' + esc(f.file.name) + '">' + I.x + '</button></li>';
    }).join('');
  }

  /* ---- step 2: destination ---- */
  var DEST = {
    series:    { icon: 'tire',  title: 'Tire series', sub: function () { return 'Renders the client reviews in Assets → ' + featureLabel() + ' · images and video'; } },
    reference: { icon: 'ref',   title: 'Tire reference', sub: function () { return 'Photos of the real tire that renders are compared with · images only, ' + ((S.init.limits || {}).reference || 6) + ' per tire'; } },
    library:   { icon: 'photo', title: 'Library', sub: function () { return 'Brand images and video for ' + S.init.client.name + ', reviewed in Assets → Library'; } },
    post:      { icon: 'grid',  title: 'New post', sub: function () { return 'Make a post from these files — saved as a Draft only you can see'; } }
  };
  function featureLabel() { return (S.init.client && S.init.client.label) || 'Tires'; }
  function renderDest() {
    var files = validFiles(), vids = files.filter(function (f) { return f.video; }).length, bytes = files.reduce(function (n, f) { return n + f.file.size; }, 0);
    var sum = $('[data-us-summary]', R);
    sum.innerHTML = '<span class="us-summary-thumbs" aria-hidden="true">' + files.slice(0, 4).map(function (f) { return '<span>' + thumbHtml(f) + '</span>'; }).join('') + '</span>'
      + '<span class="us-summary-text"><strong>' + plural(files.length, 'file') + '</strong> · ' + esc(fmtBytes(bytes)) + (vids ? ' · ' + plural(vids, 'video') : '') + '</span>'
      + '<label class="ui-btn ui-btn--plain ui-btn--sm">Add files<input type="file" class="ui-visually-hidden" data-us-add-more multiple accept="' + ACCEPT + '"></label>';
    var more = $('[data-us-add-more]', sum);
    more.addEventListener('change', function () { addFiles(Array.prototype.slice.call(more.files || [])); more.value = ''; });
    var box = $('[data-us-cards]', R), d = S.dest;
    var keepFocus = document.activeElement && box.contains(document.activeElement) ? (document.activeElement.getAttribute('data-us-tire') !== null ? '[data-us-tire]' : document.activeElement.matches('[data-us-series]') ? '[data-us-series]' : document.activeElement.matches('[data-us-new-series]') ? '[data-us-new-series]' : document.activeElement.name === 'usDest' ? 'input[name="usDest"][value="' + document.activeElement.value + '"]' : document.activeElement.name === 'usEach' ? 'input[name="usEach"]:checked' : null) : null;
    var caret = keepFocus === '[data-us-new-series]' ? document.activeElement.selectionStart : null;
    box.innerHTML = '<legend>Where do these files go?</legend>' + available().map(function (k) {
      var on = d.kind === k, def = DEST[k];
      return '<div class="us-card' + (on ? ' is-checked' : '') + '" data-us-card="' + k + '">'
        + '<input class="us-card-radio" type="radio" name="usDest" id="usDest_' + k + '" value="' + k + '"' + (on ? ' checked' : '') + '>'
        + '<label class="us-card-label" for="usDest_' + k + '"><span class="us-card-icon">' + I[def.icon] + '</span>'
        + '<span class="us-card-text"><span class="us-card-title">' + esc(def.title) + '</span><span class="us-card-sub">' + esc(def.sub()) + '</span></span>'
        + '<span class="us-card-dot" aria-hidden="true"></span></label>'
        + (on ? moreHtml(k) : '')
        + '</div>';
    }).join('');
    if (keepFocus) { var f = $(keepFocus, box); if (f) { f.focus(); if (caret !== null && f.setSelectionRange) try { f.setSelectionRange(caret, caret); } catch (e) {} } }
    renderNote();
  }
  function tireOptions(sel, withRefs) {
    var lim = (S.init.limits || {}).reference || 6;
    return S.init.tires.map(function (t) {
      return '<option value="' + t.id + '"' + (t.id === sel ? ' selected' : '') + '>' + esc(t.name)
        + (withRefs ? ' · ' + t.refs + ' of ' + lim + ' used' : (t.series.length ? ' · ' + plural(t.series.length, 'series', 'series') : '')) + '</option>';
    }).join('');
  }
  function moreHtml(k) {
    var d = S.dest;
    if (k === 'series') {
      var t = tireById(d.tire);
      return '<div class="us-card-more">'
        + '<div class="us-field"><label class="us-label" for="usTire">Tire</label><select class="ui-select" id="usTire" data-us-tire>' + tireOptions(d.tire, false) + '</select></div>'
        + '<div class="us-field"><label class="us-label" for="usSeries">Series</label><select class="ui-select" id="usSeries" data-us-series>'
        + (t ? t.series.map(function (s) { return '<option value="' + s.id + '"' + (String(s.id) === String(d.series) ? ' selected' : '') + '>' + esc(s.name) + ' · ' + plural(s.total, 'file') + '</option>'; }).join('') : '')
        + '<option value="new"' + (d.series === 'new' ? ' selected' : '') + '>New series…</option></select></div>'
        + (d.series === 'new' ? '<div class="us-field"><label class="us-label" for="usNewSeries">New series name</label><input class="ui-input" type="text" id="usNewSeries" maxlength="80" data-us-new-series value="' + esc(d.newSeries) + '" placeholder="e.g. Series 3"></div>' : '')
        + (d.series === 'new' && (S.init.features || {}).seriesDrive ? '<div class="us-field"><label class="us-label" for="usNewDrive">Google Drive link <span class="us-optional">optional</span></label><input class="ui-input" type="url" id="usNewDrive" maxlength="512" inputmode="url" autocomplete="off" spellcheck="false" data-us-new-drive value="' + esc(d.newDrive) + '" placeholder="https://drive.google.com/drive/folders/…"></div>' : '')
        + '</div>';
    }
    if (k === 'reference') {
      return '<div class="us-card-more"><div class="us-field"><label class="us-label" for="usRefTire">Tire</label><select class="ui-select" id="usRefTire" data-us-tire>' + tireOptions(d.tire, true) + '</select></div></div>';
    }
    if (k === 'post') {
      var max = (S.init.limits || {}).post || 20;
      return '<div class="us-card-more"><div class="us-choice" role="radiogroup" aria-label="How many posts">'
        + '<label><input type="radio" name="usEach" value="one"' + (d.each ? '' : ' checked') + '> One post — the files become its slides (up to ' + max + ')</label>'
        + '<label><input type="radio" name="usEach" value="each"' + (d.each ? ' checked' : '') + '> A draft post per file</label></div></div>';
    }
    return '';
  }
  /** Which of the chosen files the destination takes (+ why the others are left out). */
  function plan() {
    var d = S.dest, files = validFiles(), take = [], skip = [], lim = S.init.limits || {};
    if (d.kind === 'reference') {
      var t = tireById(d.tire), room = Math.max(0, (lim.reference || 6) - (t ? t.refs : 0));
      files.forEach(function (f) {
        if (f.video || IMAGE_EXTS.indexOf(fileExt(f.file.name)) === -1) skip.push({ f: f, why: 'Reference takes images only' });
        else if (room <= 0) skip.push({ f: f, why: 'Over the ' + (lim.reference || 6) + '-image limit for ' + (t ? t.name : 'this tire') });
        else { take.push(f); room--; }
      });
    } else if (d.kind === 'post' && !d.each) {
      var max = lim.post || 20;
      files.forEach(function (f, i) { if (i < max) take.push(f); else skip.push({ f: f, why: 'Over ' + max + ' slides per post' }); });
    } else {
      take = files.slice();
    }
    return { take: take, skip: skip };
  }
  function destReady() {
    var d = S.dest;
    if (!d.kind) return 'Choose where the files go';
    if ((d.kind === 'series' || d.kind === 'reference') && !tireById(d.tire)) return 'Choose a tire';
    if (d.kind === 'series' && d.series === 'new' && !String(d.newSeries || '').trim()) return 'Name the new series';
    if (d.kind === 'series' && d.series === 'new' && String(d.newDrive || '').trim() && !DRIVE_RE.test(String(d.newDrive).trim())) return 'Enter a Google Drive share link';
    if (!plan().take.length) return 'None of these files can go there';
    return '';
  }
  function destLabel(d) {
    d = d || S.dest;
    var t = tireById(d.tire);
    if (d.kind === 'series') {
      var s = d.series === 'new' ? null : seriesById(t, d.series);
      return (t ? t.name : 'Tire') + ' · ' + (s ? s.name : (String(d.newSeries || '').trim() || 'new series') + (d.series === 'new' ? ' (new)' : ''));
    }
    if (d.kind === 'reference') return (t ? t.name : 'Tire') + ' · Reference';
    if (d.kind === 'library') return 'Library';
    if (d.kind === 'post') return d.each ? 'A draft post per file' : 'A new post';
    return '';
  }
  function renderNote() {
    var note = $('[data-us-dest-note]', R); if (!note) return;
    if (!S.dest.kind) { note.textContent = ''; note.classList.remove('is-warn'); return; }
    var p = plan();
    if (p.skip.length) {
      var why = {}; p.skip.forEach(function (s) { why[s.why] = (why[s.why] || 0) + 1; });
      note.textContent = plural(p.skip.length, 'file') + ' will be left out: ' + Object.keys(why).map(function (k) { return why[k] + ' — ' + k.charAt(0).toLowerCase() + k.slice(1); }).join('; ') + '.';
      note.classList.add('is-warn');
    } else {
      var k = S.dest.kind;
      note.textContent = k === 'post' ? (S.dest.each ? 'Each file becomes its own Draft post, spaced 3 days apart — add captions in Posts → Draft.' : 'Next: the New post pop-up opens with these files as slides; add a caption and save it as a Draft.')
        : 'Everything lands as To Review for ' + S.init.client.name + '.';
      note.classList.remove('is-warn');
    }
  }

  /* ---- footer ---- */
  function renderFooter() {
    if (!R) return;
    var info = $('[data-us-info]', R), acts = $('[data-us-actions]', R);
    if (!S || !S.init) { info.textContent = ''; acts.innerHTML = '<button type="button" class="ui-btn ui-btn--gray" data-us-act="cancel">Cancel</button>'; return; }
    var n = validFiles().length;
    if (S.step === 'files') {
      var bad = S.files.length - n;
      info.innerHTML = n ? '<strong>' + plural(n, 'file') + '</strong> ready' + (bad ? ' · ' + bad + ' can\'t be uploaded' : '') : (bad ? plural(bad, 'file') + ' can\'t be uploaded' : 'Images and video, up to ' + MAX_FILES + ' at a time');
      acts.innerHTML = '<button type="button" class="ui-btn ui-btn--gray" data-us-act="cancel">Cancel</button>'
        + '<button type="button" class="ui-btn ui-btn--filled" data-us-act="next"' + (n ? '' : ' disabled') + '>Next</button>';
      return;
    }
    if (S.step === 'dest') {
      var why = destReady(), p = S.dest.kind ? plan() : { take: [] }, k = p.take.length;
      info.innerHTML = why ? esc(why) : '<strong>' + plural(k, 'file') + '</strong> → ' + esc(destLabel());
      var label = S.dest.kind === 'post' && !S.dest.each ? 'Upload & make post' : 'Upload ' + plural(k, 'file');
      acts.innerHTML = '<button type="button" class="ui-btn ui-btn--gray" data-us-act="back">Back</button>'
        + '<button type="button" class="ui-btn ui-btn--filled" data-us-act="upload"' + (why ? ' disabled' : '') + '>' + esc(why ? 'Upload' : label) + '</button>';
      return;
    }
    // upload
    var c = counts(), running = c.queued + c.uploading > 0;
    info.innerHTML = '<strong>' + c.done + ' of ' + c.total + '</strong> uploaded' + (c.failed ? ' · <span class="text-deny">' + c.failed + ' failed</span>' : '') + (c.cancelled ? ' · ' + c.cancelled + ' cancelled' : '');
    var h = '';
    if (running) h = '<button type="button" class="ui-btn ui-btn--gray" data-us-act="stop">Stop</button>';
    else {
      if (c.retryable) h += '<button type="button" class="ui-btn ui-btn--gray" data-us-act="retry-all">Retry ' + (c.retryable === 1 ? 'failed' : 'all ' + c.retryable) + '</button>';
      var d = S.jobs.length ? S.jobs[0].dest : S.dest;
      if (d.kind === 'post' && !d.each && c.done) h += '<button type="button" class="ui-btn ui-btn--filled" data-us-act="handoff">Continue to post</button>';
      else h += '<button type="button" class="ui-btn ui-btn--filled" data-us-act="done">Done</button>';
    }
    acts.innerHTML = h;
  }
  function action(a) {
    if (a === 'cancel') { requestClose(); return; }
    if (a === 'next') { if (canGo('dest')) setStep('dest'); return; }
    if (a === 'back') { setStep('files'); return; }
    if (a === 'upload') { startUpload(); return; }
    if (a === 'stop') { showConfirm(true); return; }
    if (a === 'retry-all') { S.jobs.forEach(function (j) { if ((j.state === 'failed' && j.retryable) || j.state === 'cancelled') retryJob(j, true); }); pump(); return; }
    if (a === 'handoff') { handOff(); return; }
    if (a === 'done') { finishAndClose(); }
  }

  /* ------------------------------------------------------------------ */
  /* Step 3: upload — one file at a time through App.chunkUpload          */
  /* ------------------------------------------------------------------ */
  function jobById(id) { for (var i = 0; i < S.jobs.length; i++) if (S.jobs[i].id === id) return S.jobs[i]; return null; }
  function counts() {
    var c = { total: S.jobs.length, done: 0, failed: 0, cancelled: 0, queued: 0, uploading: 0, retryable: 0 };
    S.jobs.forEach(function (j) { c[j.state] = (c[j.state] || 0) + 1; if ((j.state === 'failed' && j.retryable) || j.state === 'cancelled') c.retryable++; });
    return c;
  }
  /** Where a destination's files go: endpoint + the form fields chunk_init / upload need. */
  function target(d) {
    var u = S.init.urls, f = { client: S.slug, actor: 'admin' };
    if (d.kind === 'series') {
      f.tire_id = d.tire; f.batch = S.batch;
      if (d.series === 'new') {
        f.new_series = String(d.newSeries || '').trim();
        if (String(d.newDrive || '').trim()) f.new_series_drive = String(d.newDrive).trim();   // the new series' Google Drive link (tire-upload.php)
      } else f.series_id = parseInt(d.series, 10);
      return { endpoint: u.tire, fields: f };
    }
    if (d.kind === 'reference') { f.purpose = 'feature'; f.feature_id = d.tire; return { endpoint: u.upload, fields: f }; }
    if (d.kind === 'library') { f.purpose = 'library'; return { endpoint: u.upload, fields: f }; }
    f.purpose = d.each ? 'batch' : 'post';
    return { endpoint: u.upload, fields: f };
  }
  function startUpload() {
    if (destReady() || S.started) return;
    if (!App.chunkUpload || !App.chunkUpload.upload) { toast('Uploads need chunk-upload.js — reload the page', 'error'); return; }
    var d = Object.assign({}, S.dest), p = plan();
    S.batch = 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);   // one activity line per drop (tire series)
    S.started = true;
    var tg = target(d);
    S.jobs = p.take.map(function (f) { return { id: 'j' + (++uid), f: f, dest: d, endpoint: tg.endpoint, fields: Object.assign({}, tg.fields), state: 'queued', pct: 0, text: '', error: '', retryable: false, uploadId: null, ctl: null, result: null, token: null, note: '' }; });
    p.skip.forEach(function (s) { S.jobs.push({ id: 'j' + (++uid), f: s.f, dest: d, state: 'skipped', note: s.why, pct: 0 }); });
    setStep('upload');
    pump();
  }
  function pump() {
    if (!S || S.busy) return;
    var j = S.jobs.filter(function (x) { return x.state === 'queued'; })[0];
    if (!j) { renderFooter(); settle(); return; }
    S.busy = true; j.state = 'uploading'; j.error = ''; j.text = '';
    var slug = S.slug, file = j.f.file;
    if (j.dest.kind === 'series' && S.created) { delete j.fields.new_series; delete j.fields.new_series_drive; j.fields.series_id = S.created.id; }   // the first file created the series
    var label = destLabel(j.dest);
    j.ctl = App.chunkUpload.upload({
      endpoint: j.endpoint, file: file, fields: j.fields, uploadId: j.uploadId,
      onInit: function (d) {
        j.uploadId = d.upload_id;
        if (d.series && d.series.id && j.dest.kind === 'series') { noteSeries(d.series); j.fields.series_id = d.series.id; delete j.fields.new_series; delete j.fields.new_series_drive; }
        App.chunkUpload.remember({ id: d.upload_id, kind: 'sheet', endpoint: j.endpoint, client: slug, name: file.name, size: file.size, type: file.type || '',
                                   fields: j.fields, dest: j.dest, label: label });
      },
      onProgress: function (p) { j.pct = p.pct; j.text = p.text; syncJob(j); },
      onRetry: function (r) { j.text = 'Connection hiccup — retrying that piece (' + r.attempt + ' of ' + r.max + ')…'; syncJob(j); }
    });
    renderJobs(); renderFooter();
    j.ctl.promise.then(function (data) {
      if (!S || S.slug !== slug) return;
      if (j.uploadId) App.chunkUpload.forget(j.uploadId);
      j.uploadId = null; j.pct = 100; j.result = data;
      if (data.series && data.series.id && j.dest.kind === 'series') noteSeries(data.series);
      if (j.dest.kind === 'reference') { var t = tireById(j.dest.tire); if (t) t.refs = data.count || t.refs + 1; }
      if (j.dest.kind === 'post') {
        j.token = data.token;
        if (j.dest.each) return makeDraft(j, data);
      }
      j.state = 'done';
    }, function (e) {
      if (!S) return;
      if (e && e.aborted) { if (j.uploadId) App.chunkUpload.forget(j.uploadId); j.uploadId = null; j.state = 'cancelled'; return; }
      j.state = 'failed'; j.error = (e && e.error) || 'Upload failed'; j.retryable = !!(e && e.retryable) || !e || e.status === 0;
      if (!j.retryable || (e && e.expired)) { if (j.uploadId) App.chunkUpload.forget(j.uploadId); j.uploadId = null; }
    }).then(function () {
      if (!S) return;
      S.busy = false; j.ctl = null;
      renderJobs(); renderFooter(); pump();
    });
  }
  function noteSeries(series) {
    if (S.created && S.created.id === series.id) return;
    var t = tireById(S.dest.tire) || tireById(series.tire_id);
    if (S.dest.series === 'new' && !S.created) S.created = { id: series.id, name: series.name };
    if (t && !seriesById(t, series.id)) t.series.push({ id: series.id, name: series.name || '', total: 0, pending: 0 });
  }
  /** "A draft post per file": the parked file becomes one Draft post (batch-process.php's claimed[] contract). */
  function makeDraft(j, data) {
    j.text = 'Creating the draft post…'; syncJob(j);
    return postForm(S.init.urls.batch, { claimed: [data.token], client: S.slug }).then(function (res) {
      var d = res.data || {}, created = d.created && d.created[0];
      if (!res.ok || !created) { j.state = 'failed'; j.retryable = false; j.error = (d.errors && d.errors[0]) || d.error || 'The post was not created'; return; }
      j.token = null; j.state = 'done'; j.post = created;
    });
  }
  function cancelJob(j) {
    if (!j) return;
    if (j.state === 'queued') { j.state = 'cancelled'; renderJobs(); renderFooter(); settle(); return; }
    if (j.state === 'uploading' && j.ctl) j.ctl.abort();
  }
  function retryJob(j, noPump) {
    if (!j || !((j.state === 'failed' && j.retryable) || j.state === 'cancelled')) return;
    j.state = 'queued'; j.error = ''; j.pct = 0; S.handedOff = false;
    renderJobs(); renderFooter();
    if (!noPump) pump();
  }
  function stopAll() {
    S.jobs.forEach(function (j) {
      if (j.state === 'queued') j.state = 'cancelled';
      if (j.state === 'uploading' && j.ctl) { try { j.ctl.abort(); } catch (e) {} j.state = 'cancelled'; }
    });
  }
  /** Everything settled: a New post run hands its files to the pop-up; any other run reports where the files landed. */
  function settle() {
    if (!S || S.busy || inFlight() || !S.jobs.length) return;
    var c = counts(), d = S.jobs[0].dest, fresh = c.done - (S.reported || 0);
    S.reported = c.done;   // a retry round reports only the files it added
    document.dispatchEvent(new CustomEvent('upload:done', { detail: { dest: d, ok: fresh, failed: c.failed, url: destUrl(d), created: S.created } }));
    if (d.kind === 'post' && !d.each) { if (c.done && !c.failed && !c.cancelled) handOff(); return; }
    if (c.done && !c.failed && !c.cancelled) finishAndClose();   // all in: close, toast with the link
  }
  function destUrl(d) {
    var u = S.init.urls;
    if (d.kind === 'series') { var sid = d.series === 'new' ? (S.created ? S.created.id : '') : d.series; return u.series.replace('__TIRE__', encodeURIComponent(d.tire)).replace('__SERIES__', encodeURIComponent(sid)); }
    if (d.kind === 'reference') return u.reference.replace('__TIRE__', encodeURIComponent(d.tire));
    if (d.kind === 'library') return u.library;
    if (d.kind === 'post' && d.each) return u.drafts;
    return '';
  }
  function summary() {
    if (!S || !S.jobs.length) return null;
    var c = counts(), d = S.jobs[0].dest;
    if (!c.done) return { ok: 0, failed: c.failed };
    var where = d.kind === 'post' ? (d.each ? 'Draft' : 'the post') : destLabel(d);
    var msg = d.kind === 'post' && d.each ? plural(c.done, 'draft post') + ' created' : plural(c.done, 'file') + ' uploaded to ' + where;
    if (c.failed) msg += ' · ' + c.failed + ' failed';
    return { ok: c.done, failed: c.failed, message: msg, url: destUrl(d), linkLabel: d.kind === 'post' ? 'Open Draft' : 'View' };
  }
  /** New post (one post): the parked files → the New post pop-up as slides, in the order they were chosen. */
  function handOff() {
    if (!S || S.handedOff) return;
    var pre = S.jobs.filter(function (j) { return j.state === 'done' && j.token; }).map(function (j) {
      var r = j.result || {};
      return { ref: 'upload:' + j.token, media: r.type === 'video' ? 'video' : 'image', thumb: r.type === 'video' ? '' : (r.preview_url || ''), name: j.f.file.name };
    });
    if (!pre.length) return;
    if (!App.newPost || !App.newPost.open) { toast('The New post pop-up is not available on this page — reload and try again', 'error', 5000); return; }
    var slug = S.slug;
    S.handedOff = true;
    forceClose();
    setTimeout(function () { App.newPost.open({ client: slug, preselect: pre }); }, App.reducedMotion && App.reducedMotion() ? 0 : 240);
  }
  function syncJob(j) {
    var row = R && $('[data-us-job="' + j.id + '"]', R);
    if (!row) return;
    var fill = $('.us-row-fill', row); if (fill) fill.style.transform = 'translateX(' + (j.pct - 100) + '%)';
    var meta = $('.us-row-meta', row); if (meta) meta.textContent = j.text ? 'Uploading… ' + j.text : 'Uploading…';
  }
  function jobMeta(j) {
    if (j.state === 'skipped') return esc('Left out — ' + j.note);
    if (j.state === 'queued') return 'Waiting…';
    if (j.state === 'uploading') return esc(j.text ? (j.text.indexOf('Creating') === 0 || j.text.indexOf('Connection') === 0 ? j.text : 'Uploading… ' + j.text) : 'Uploading…');
    if (j.state === 'cancelled') return 'Cancelled';
    if (j.state === 'failed') return esc(j.error) + (j.uploadId && j.retryable ? ' — Retry continues where it stopped.' : '');
    if (j.state === 'done') {
      var d = j.dest;
      if (d.kind === 'post' && d.each && j.post) return 'Draft post #' + esc(j.post.post_id) + ' created';
      if (d.kind === 'post') return 'Uploaded — ready for the post';
      return 'Uploaded · To Review';
    }
    return '';
  }
  function renderJobs() {
    if (!S.jobs.length) return;
    var d = S.jobs[0].dest;
    $('[data-us-target]', R).textContent = d.kind === 'post' ? (d.each ? 'A draft post per file' : 'Files for a new post') : 'Uploading to ' + destLabel(d);
    $('[data-us-target-sub]', R).textContent = d.kind === 'post' ? (d.each ? 'Each file becomes a Draft only you can see.' : 'When they are in, the New post pop-up opens with them as slides.') : 'Files land as To Review for ' + S.init.client.name + '.';
    $('[data-us-jobs]', R).innerHTML = S.jobs.map(function (j) {
      var cls = j.state === 'failed' ? ' is-failed' : j.state === 'done' ? ' is-done' : (j.state === 'skipped' || j.state === 'cancelled') ? ' is-skipped' : '';
      var btns = '';
      if (j.state === 'queued' || j.state === 'uploading') btns = '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-us-job-cancel="' + j.id + '" aria-label="Cancel ' + esc(j.f.file.name) + '">Cancel</button>';
      else if ((j.state === 'failed' && j.retryable) || j.state === 'cancelled') btns = '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-us-job-retry="' + j.id + '" aria-label="Retry ' + esc(j.f.file.name) + '">Retry</button>';
      return '<li class="us-row' + cls + '" data-us-job="' + j.id + '" data-state="' + j.state + '">'
        + '<span class="us-row-thumb">' + thumbHtml(j.f) + '</span>'
        + '<span class="us-row-body"><span class="us-row-name">' + esc(j.f.file.name) + '</span>'
        + '<span class="us-row-bar"' + (j.state === 'uploading' || j.state === 'queued' ? '' : ' hidden') + '><span class="us-row-fill" style="transform:translateX(' + (j.pct - 100) + '%)"></span></span>'
        + '<span class="us-row-meta">' + jobMeta(j) + '</span></span>'
        + '<span class="us-row-actions">' + btns + '</span></li>';
    }).join('');
  }

  /* ---- resume after a reload: the localStorage ledger names unfinished uploads; the same files re-picked continue ---- */
  function offerResume() {
    var box = $('[data-us-resume]', R);
    if (!App.chunkUpload || !App.chunkUpload.list) { box.hidden = true; return; }
    S.resume = App.chunkUpload.list({ kind: 'sheet', client: S.slug });
    var n = S.resume.length;
    box.hidden = n === 0;
    if (!n) return;
    $('[data-us-resume-text]', R).textContent = 'Resume ' + plural(n, 'unfinished upload') + ': ' + S.resume.map(function (e) { return e.name + ' → ' + (e.label || 'upload'); }).join(', ') + '. Pick the same file' + (n === 1 ? '' : 's') + ' and they continue where they stopped.';
  }
  function resumeFiles(files) {
    var matched = [], unmatched = [];
    files.forEach(function (f) {
      var e = S.resume.filter(function (p) { return p.name === f.name && Number(p.size) === f.size && matched.indexOf(p) === -1; })[0];
      if (!e) { unmatched.push(f.name); return; }
      matched.push(e);
      var vid = isVideo(f);
      var fo = { id: 'f' + (++uid), file: f, video: vid, error: '', url: !vid && /^image\//.test(f.type || '') ? URL.createObjectURL(f) : '' };
      S.files.push(fo);
      S.jobs.push({ id: 'j' + (++uid), f: fo, dest: e.dest || { kind: 'library' }, endpoint: e.endpoint, fields: e.fields || {}, state: 'queued', pct: 0, text: '', error: '', retryable: false, uploadId: e.id, ctl: null, result: null, token: null });
    });
    if (unmatched.length) toast(plural(unmatched.length, 'file does', 'files do') + ' not match an unfinished upload (same name and size needed): ' + unmatched.join(', '), 'error', 6000);
    if (!matched.length) return;
    S.resume = S.resume.filter(function (e) { return matched.indexOf(e) === -1; });
    S.started = true;
    S.dest = Object.assign({}, S.jobs[0].dest);
    setStep('upload');
    pump();
  }
  function discardResume() {
    if (!S.resume.length) return;
    S.resume.forEach(function (e) { App.chunkUpload.abortStored(e); });
    S.resume = [];
    $('[data-us-resume]', R).hidden = true;
  }

  /* ------------------------------------------------------------------ */
  /* Public API + entry points                                           */
  /* ------------------------------------------------------------------ */
  App.uploadSheet = {
    open: open,
    close: function (force) { if (force) forceClose(); else requestClose(); },
    isOpen: function () { return !!S; },
    _state: function () { return S; }
  };

  function optsFrom(el) {
    var o = { client: el.getAttribute('data-client') || undefined, dest: el.getAttribute('data-upload-dest') || undefined };
    if (el.hasAttribute('data-upload-tire')) o.tire = el.getAttribute('data-upload-tire');
    if (el.hasAttribute('data-upload-series')) o.series = el.getAttribute('data-upload-series');
    if (el.getAttribute('data-upload-each') === '1') o.each = true;
    return o;
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-upload-open], [data-upload-drop]');
    if (!t || (R && R.contains(t))) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    open(optsFrom(t));
  });
  // Contextual drop targets: files dropped on them open the sheet with those files.
  ['dragenter', 'dragover'].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      var t = e.target.closest && e.target.closest('[data-upload-drop]');
      if (!t || S || !hasFiles(e)) return;
      e.preventDefault(); t.classList.add('is-dragover');
    });
  });
  document.addEventListener('dragleave', function (e) { var t = e.target.closest && e.target.closest('[data-upload-drop]'); if (t && !t.contains(e.relatedTarget)) t.classList.remove('is-dragover'); });
  document.addEventListener('drop', function (e) {
    var t = e.target.closest && e.target.closest('[data-upload-drop]');
    if (!t || S || !hasFiles(e)) return;
    e.preventDefault(); t.classList.remove('is-dragover');
    var o = optsFrom(t); o.files = Array.prototype.slice.call(e.dataTransfer.files || []);
    open(o);
  });

  function hookMenu() { if (App.newMenu && App.newMenu.handle) App.newMenu.handle('upload', function (detail) { open({ client: detail.client || undefined }); return true; }); }

  function boot() {
    ensureCss();
    hookMenu();
    try { var t = JSON.parse(sessionStorage.getItem('us.toast') || 'null'); if (t) { sessionStorage.removeItem('us.toast'); setTimeout(function () { toast(t.m, t.k, 6000); }, 300); } } catch (e) {}
    var u; try { u = new URL(window.location.href); } catch (e) { return; }
    if (u.searchParams.get('upload') !== '1') return;
    var o = { dest: u.searchParams.get('dest') || undefined, tire: u.searchParams.get('tire') || undefined, series: u.searchParams.get('series') || undefined, each: u.searchParams.get('each') === '1' };
    ['upload', 'dest', 'each', 'tire'].forEach(function (k) { u.searchParams.delete(k); });
    try { history.replaceState(history.state, '', u.pathname + u.search + u.hash); } catch (e) {}
    setTimeout(function () { open(o); }, 0);
  }
  if (App._inited) boot(); else document.addEventListener('app:ready', boot, { once: true });
})(window, document);
