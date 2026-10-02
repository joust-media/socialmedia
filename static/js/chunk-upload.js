/* =====================================================================
   App.chunkUpload — chunked, resumable uploads (the browser half of
   chunk-upload-lib.php; tire-upload.php and page-upload.php speak it).

     App.chunkUpload.probe(endpoint)            → Promise({chunk_size, max_file_bytes, …}) — cached per endpoint;
                                                  resolves null when the server has no chunk support.
     App.chunkUpload.upload(opts)               → { promise, abort(), uploadId() } — the one call for any file:
                                                  probes once, then ONE multipart request (action=upload + opts.fields
                                                  + name/size/type + `file`) when the file fits in a piece, else send()
                                                  (init → pieces → finish). opts as send(); opts.uploadId resumes.
                                                  abort() works before the probe has answered too.
                                                  opts.previews: true → an image's sm / lg WebP previews are made
                                                  in the browser meanwhile and sent with action=previews
                                                  (data.previews); the server then decodes nothing.
     App.imagePreview.make(file)                → Promise({sm, lg, …}) browser-made previews (below)
     App.chunkUpload.send(opts)                 → { promise, abort() }
        opts.endpoint   the upload endpoint (may carry ?client=…)
        opts.file       the File
        opts.fields     extra form fields for chunk_init (client, tire_id, series_id | new_series, batch, …)
        opts.chunkSize  from probe()
        opts.uploadId   resume an earlier upload (chunk_status decides where to continue)
        opts.onInit(data)                        chunk_init reply (upload_id, series …) — persist it here
        opts.onProgress({loaded,total,pct,speed,eta,index,count,text})
        → resolves with the chunk_finish reply; rejects {error, status, retryable, aborted}
        A failed piece is retried up to 3 times (1 s · 2 s · 4 s); a 409 re-syncs to the server's `received`.
     App.chunkUpload.remember(entry) / forget(id) / list(filter)   localStorage ledger of in-flight uploads
                                                  ({id, kind, endpoint, client, name, size, type, fields, label, ts}), 24 h.
     App.chunkUpload.fmt.bytes(n) / speed(bps) / eta(sec)
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  if (App.chunkUpload) return;

  var STORE_KEY = 'portal.chunkUploads', STORE_TTL = 24 * 60 * 60 * 1000, RETRIES = 3;

  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n >= 1073741824) return (n / 1073741824).toFixed(2).replace(/\.?0+$/, '') + ' GB';
    if (n >= 1048576) return (n / 1048576).toFixed(n >= 104857600 ? 0 : 1).replace(/\.0$/, '') + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' B';
  }
  function fmtSpeed(bps) { return bps > 0 ? fmtBytes(bps) + '/s' : ''; }
  function fmtEta(sec) {
    if (!(sec > 0) || !isFinite(sec)) return '';
    sec = Math.round(sec);
    if (sec < 60) return sec + ' s left';
    if (sec < 3600) return Math.floor(sec / 60) + ' min ' + (sec % 60) + ' s left';
    return Math.floor(sec / 3600) + ' h ' + Math.floor((sec % 3600) / 60) + ' min left';
  }

  function clientSlug(fields) {
    if (fields && fields.client) return fields.client;
    return (document.body && document.body.dataset.client) || '';
  }

  /* One multipart request → Promise({status, data}). onProgress(loadedBytes) for the upload body. */
  function request(endpoint, fields, blob, blobName, onProgress) {
    var xhr = new XMLHttpRequest();
    var p = new Promise(function (resolve, reject) {
      var fd = new FormData();
      Object.keys(fields || {}).forEach(function (k) { if (fields[k] !== undefined && fields[k] !== null) fd.append(k, String(fields[k])); });
      if (!fd.has('client')) { var c = clientSlug(fields); if (c) fd.append('client', c); }
      if (blob) fd.append('file', blob, blobName || 'chunk.bin');
      if (onProgress && xhr.upload) xhr.upload.addEventListener('progress', function (e) { if (e.lengthComputable) onProgress(e.loaded, e.total); });
      xhr.onload = function () {
        var data = null; try { data = JSON.parse(xhr.responseText); } catch (e) {}
        resolve({ status: xhr.status, data: data });
      };
      xhr.onerror = function () { reject({ status: 0, error: 'Network error', network: true }); };
      xhr.onabort = function () { reject({ status: 0, error: 'Cancelled', aborted: true }); };
      xhr.ontimeout = function () { reject({ status: 0, error: 'Timed out', network: true }); };
      xhr.open('POST', endpoint);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.send(fd);
    });
    return { xhr: xhr, promise: p };
  }

  var probes = {};
  function probe(endpoint) {
    if (probes[endpoint]) return probes[endpoint];
    probes[endpoint] = request(endpoint, { action: 'probe' }, null).promise.then(function (r) {
      if (r.status === 200 && r.data && r.data.ok && r.data.chunk_size > 0) return r.data;
      return null;
    }, function () { return null; });
    return probes[endpoint];
  }

  /* Progress reporter shared by the chunked and the single-request path: bytes, %, speed, ETA, "piece n of m". */
  function makeProgress(total, cb, getCount) {
    var startedAt = 0, startedBytes = 0;
    return function (loaded, index) {
      if (!cb) return;
      var count = getCount ? getCount() : 1, now = Date.now();
      if (!startedAt) { startedAt = now; startedBytes = loaded; }
      var elapsed = (now - startedAt) / 1000, speed = elapsed >= 1 ? (loaded - startedBytes) / elapsed : 0;
      var eta = speed > 0 ? (total - loaded) / speed : 0;
      var pct = total ? Math.min(100, Math.floor(loaded / total * 100)) : 100;
      var text = pct + '% · ' + fmtBytes(loaded) + ' of ' + fmtBytes(total);
      if (speed > 0) text += ' · ' + fmtSpeed(speed);
      if (eta > 0 && loaded < total) text += ' · ' + fmtEta(eta);
      if (count > 1) text += ' · piece ' + Math.min(count, index + 1) + ' of ' + count;
      cb({ loaded: loaded, total: total, pct: pct, speed: speed, eta: eta, index: index, count: count, text: text });
    };
  }

  function send(opts) {
    var endpoint = opts.endpoint, file = opts.file, fields = opts.fields || {}, chunkSize = Math.max(65536, opts.chunkSize || 0);
    var aborted = false, current = null, uploadId = opts.uploadId || null, total = file.size;
    var count = Math.max(1, Math.ceil(total / chunkSize));
    var progress = makeProgress(total, opts.onProgress, function () { return count; });
    var fail = function (e) { return Promise.reject(e); };
    var run = function (fn) { if (aborted) return Promise.reject({ error: 'Cancelled', aborted: true }); current = fn(); return current.promise; };
    var base = { client: clientSlug(fields) };
    if (fields.actor) base.actor = fields.actor;

    var init = function () {
      if (uploadId) {
        return run(function () { return request(endpoint, Object.assign({}, base, { action: 'chunk_status', upload_id: uploadId }), null); }).then(function (r) {
          if (r.status === 200 && r.data && r.data.ok) { if (r.data.chunk_size > 0) { chunkSize = r.data.chunk_size; count = Math.max(1, Math.ceil(total / chunkSize)); } return Number(r.data.received) || 0; }
          if (r.status === 404) { uploadId = null; return init(); }   // expired on the server → start over
          return fail({ error: (r.data && r.data.error) || ('Could not resume (' + r.status + ')'), status: r.status, retryable: r.status >= 500 || r.status === 0 });
        });
      }
      var f = Object.assign({}, fields, base, { action: 'chunk_init', name: file.name, size: total, type: file.type || '' });
      return run(function () { return request(endpoint, f, null); }).then(function (r) {
        if (r.status === 200 && r.data && r.data.ok && r.data.upload_id) {
          uploadId = r.data.upload_id;
          if (r.data.chunk_size > 0) { chunkSize = r.data.chunk_size; count = Math.max(1, Math.ceil(total / chunkSize)); }
          if (opts.onInit) opts.onInit(r.data);
          return Number(r.data.received) || 0;
        }
        return fail({ error: (r.data && r.data.error) || ('Upload refused (' + r.status + ')'), status: r.status, data: r.data, retryable: r.status >= 500 || r.status === 0 });
      });
    };

    var putFrom = function (offset, attempt) {
      if (offset >= total) return Promise.resolve();
      var end = Math.min(total, offset + chunkSize), index = Math.floor(offset / chunkSize);
      var blob = file.slice(offset, end);
      var f = Object.assign({}, base, { action: 'chunk_put', upload_id: uploadId, index: index, offset: offset });
      // e.loaded counts the whole multipart body (fields + piece): scale it onto the piece's byte range.
      return run(function () { return request(endpoint, f, blob, file.name, function (loaded, sent) { progress(offset + Math.min(end - offset, Math.round(loaded / Math.max(1, sent) * (end - offset))), index); }); })
        .then(function (r) {
          if (r.status === 200 && r.data && r.data.ok) { var got = Number(r.data.received) || end; progress(got, index); return putFrom(got, 0); }
          if (r.status === 409 && r.data && typeof r.data.received === 'number') { progress(r.data.received, index); return putFrom(r.data.received, 0); }   // re-sync
          if (r.status === 404) return fail({ error: (r.data && r.data.error) || 'Upload expired — start it again.', status: 404, retryable: false, expired: true });
          if (r.status >= 400 && r.status < 500 && r.status !== 408 && r.status !== 429) return fail({ error: (r.data && r.data.error) || ('Upload refused (' + r.status + ')'), status: r.status, retryable: false });
          return retry({ error: (r.data && r.data.error) || ('Server error (' + r.status + ')'), status: r.status }, offset, attempt);
        }, function (e) {
          if (e && e.aborted) return fail(e);
          return retry(e, offset, attempt);
        });
    };
    var retry = function (e, offset, attempt) {
      if (aborted) return fail({ error: 'Cancelled', aborted: true });
      if (attempt >= RETRIES) return fail({ error: (e && e.error) || 'Upload failed', status: e && e.status, retryable: true, uploadId: uploadId });
      var wait = 1000 * Math.pow(2, attempt);
      if (opts.onRetry) opts.onRetry({ attempt: attempt + 1, max: RETRIES, wait: wait, error: e && e.error });
      return new Promise(function (res) { setTimeout(res, wait); }).then(function () {
        // Ask the server where it is (a piece may have landed even though the reply was lost).
        return run(function () { return request(endpoint, Object.assign({}, base, { action: 'chunk_status', upload_id: uploadId }), null); }).then(function (r) {
          var from = (r.status === 200 && r.data && r.data.ok) ? (Number(r.data.received) || 0) : offset;
          return putFrom(from, attempt + 1);
        }, function () { return putFrom(offset, attempt + 1); });
      });
    };
    var finish = function (attempt) {
      return run(function () { return request(endpoint, Object.assign({}, base, opts.finishFields || {}, { action: 'chunk_finish', upload_id: uploadId }), null); }).then(function (r) {
        if (r.status === 200 && r.data && r.data.ok) return r.data;
        if (r.status === 409 && r.data && typeof r.data.received === 'number' && r.data.received < total) return putFrom(r.data.received, 0).then(function () { return finish(0); });
        if (r.status >= 500 && attempt < RETRIES) return new Promise(function (res) { setTimeout(res, 1000 * Math.pow(2, attempt)); }).then(function () { return finish(attempt + 1); });
        return fail({ error: (r.data && r.data.error) || ('Upload failed (' + r.status + ')'), status: r.status, data: r.data, retryable: r.status >= 500, uploadId: r.status >= 500 ? uploadId : null });
      }, function (e) {
        if (e && e.aborted) return fail(e);
        if (attempt < RETRIES) return new Promise(function (res) { setTimeout(res, 1000 * Math.pow(2, attempt)); }).then(function () { return finish(attempt + 1); });
        return fail({ error: e.error || 'Network error', status: 0, retryable: true, uploadId: uploadId });
      });
    };

    var promise = init().then(function (received) { progress(received, Math.floor(received / chunkSize)); return putFrom(received, 0); }).then(function () { return finish(0); });
    return {
      promise: promise,
      uploadId: function () { return uploadId; },
      abort: function () {
        if (aborted) return;
        aborted = true;
        try { if (current && current.xhr) current.xhr.abort(); } catch (e) {}
        if (uploadId) { try { request(endpoint, Object.assign({}, base, { action: 'chunk_abort', upload_id: uploadId }), null); } catch (e) {} }
      }
    };
  }

  /* One file, the right way: a single request when it fits in one piece (or the server has no chunk support),
     pieces otherwise (always pieces when resuming an upload_id). Same opts / callbacks / rejection shape as send().
     opts.previews: true → for an image the browser makes the sm / lg WebP previews (App.imagePreview) WHILE the
     original uploads, the upload says client_previews=1 (the server then decodes nothing), and once the reply
     carries a preview_key they go up in one small action=previews request; its reply lands on data.previews
     ({accepted, rejected, generated, thumb, large}). No WebP encoder → nothing is said, the server makes them. */
  function upload(opts) {
    var inner = null, aborted = false, uploadId = opts.uploadId || null, file = opts.file;
    var wantPv = !!opts.previews && App.imagePreview && App.imagePreview.eligible(file);
    var pvReady = wantPv ? App.imagePreview.supported() : Promise.resolve(false);
    var promise = Promise.all([probe(opts.endpoint), pvReady]).then(function (both) {
      var info = both[0], pvOn = both[1];
      if (aborted) return Promise.reject({ error: 'Cancelled', aborted: true });
      var pv = pvOn ? App.imagePreview.make(file) : null;   // cached per File: the sheet may have started it already
      var extra = pvOn ? { client_previews: '1' } : {};
      var after = function (data) { return pv && data && data.preview_key && !aborted ? sendPreviews(opts, data, pv) : data; };
      if (info && !opts.single && (uploadId || file.size > info.chunk_size)) {
        inner = send(Object.assign({}, opts, { chunkSize: info.chunk_size, uploadId: uploadId, finishFields: extra }));
        return inner.promise.then(after);
      }
      var fields = Object.assign({}, opts.fields || {}, extra, { action: 'upload', name: file.name, size: file.size, type: file.type || '' });
      var progress = makeProgress(file.size, opts.onProgress, null);
      inner = request(opts.endpoint, fields, file, file.name, function (loaded, sent) { progress(Math.min(file.size, Math.round(loaded / Math.max(1, sent) * file.size)), 0); });
      return inner.promise.then(function (r) {
        if (r.status === 200 && r.data && r.data.ok) { progress(file.size, 0); return r.data; }
        return Promise.reject({ error: (r.data && r.data.error) || ('Upload failed (' + r.status + ')'), status: r.status, data: r.data, retryable: r.status >= 500 || r.status === 0 });
      }).then(after);
    });
    return {
      promise: promise,
      uploadId: function () { return inner && inner.uploadId ? inner.uploadId() : uploadId; },
      abort: function () {
        if (aborted) return;
        aborted = true;
        if (!inner) return;
        if (inner.abort) inner.abort();
        else if (inner.xhr) { try { inner.xhr.abort(); } catch (e) {} }
      }
    };
  }

  /* action=previews: the browser-made WebP files for the upload whose reply carried preview_key. Never fails the
     upload — any problem leaves the previews to the server (it makes them itself when it refuses what arrives). */
  function sendPreviews(opts, data, pv) {
    var client = clientSlug(opts.fields);
    return pv.then(null, function () { return null; }).then(function (res) {
      var fd = new FormData();
      fd.append('action', 'previews');
      fd.append('preview_key', data.preview_key);
      if (client) fd.append('client', client);
      if (opts.fields && opts.fields.actor) fd.append('actor', opts.fields.actor);
      if (res && res.sm && res.smNeeded) fd.append('sm', res.sm, 'sm.webp');
      if (res && res.lg) fd.append('lg', res.lg, 'lg.webp');
      return fetch(opts.endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return null; }); });
    }).then(function (reply) { if (reply) data.previews = reply; return data; }, function () { return data; });
  }

  /* =====================================================================
     App.imagePreview — sm (480) / lg (1600) WebP previews made in the browser, the same rules as preview-lib.php
     (long edge, never upscaled; lg also for an original ≤ 1600 px over 500 KB, GIFs excepted), EXIF orientation
     applied (createImageBitmap imageOrientation 'from-image'), q 0.78 (0.6 when over the server's caps).
       App.imagePreview.eligible(file)   JPG / PNG / WebP / GIF
       App.imagePreview.supported()      → Promise(bool) — the canvas really encodes image/webp
       App.imagePreview.make(file)       → Promise({sm, lg, smNeeded, lgNeeded, w, h, smW, smH} | null); memoised
                                           per File, two at a time (a 12 MP decode is ~48 MB of bitmap)
     `sm` always exists when make() succeeds (the screen thumbnail too); smNeeded says whether the server wants it.
     ===================================================================== */
  App.imagePreview = (function () {
    var SM = 480, LG = 1600, Q = 0.78, HEAVY = 500 * 1024, CAPS = { sm: 300 * 1024, lg: 1536 * 1024 }, PAR = 2;
    var memo = typeof WeakMap === 'function' ? new WeakMap() : null, queue = [], active = 0, support = null;
    function eligible(f) {
      if (!f) return false;
      return /^image\/(jpeg|png|webp|gif)$/i.test(f.type || '') || /\.(jpe?g|png|webp|gif)$/i.test(f.name || '');
    }
    function supported() {
      if (support) return support;
      support = new Promise(function (resolve) {
        try {
          if (typeof window.createImageBitmap !== 'function') { resolve(false); return; }
          var c = document.createElement('canvas'); c.width = 2; c.height = 2;
          if (!c.toBlob) { resolve(false); return; }
          c.toBlob(function (b) { resolve(!!b && b.type === 'image/webp'); }, 'image/webp', 0.8);
        } catch (e) { resolve(false); }
      });
      return support;
    }
    function fit(w, h, max) {
      if (Math.max(w, h) <= max) return null;
      return w >= h ? [max, Math.max(1, Math.round(h * max / w))] : [Math.max(1, Math.round(w * max / h)), max];
    }
    function canvas(w, h) {
      if (typeof window.OffscreenCanvas === 'function') { try { return new window.OffscreenCanvas(w, h); } catch (e) { /* fall through */ } }
      var c = document.createElement('canvas'); c.width = w; c.height = h; return c;
    }
    function draw(src, w, h) {
      var c = canvas(w, h), ctx = c.getContext('2d');
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(src, 0, 0, w, h);
      return c;
    }
    function encode(c, q) {
      if (c.convertToBlob) return c.convertToBlob({ type: 'image/webp', quality: q });
      return new Promise(function (resolve) { c.toBlob(resolve, 'image/webp', q); });
    }
    function encodeCapped(c, cap) {
      var okBlob = function (b) { return !!b && b.type === 'image/webp' && b.size <= cap; };
      return encode(c, Q).then(function (b) { return okBlob(b) ? b : encode(c, 0.6).then(function (b2) { return okBlob(b2) ? b2 : null; }); });
    }
    function decode(file) {
      var plain = function () { return window.createImageBitmap(file); };
      try { return window.createImageBitmap(file, { imageOrientation: 'from-image' }).then(null, plain); } catch (e) { return plain(); }
    }
    function build(file) {
      return supported().then(function (ok) {
        if (!ok) return null;
        return decode(file).then(function (bmp) {
          var w = bmp.width, h = bmp.height;
          var gif = /gif$/i.test(file.type || '') || /\.gif$/i.test(file.name || '');
          var lgT = fit(w, h, LG) || (!gif && file.size > HEAVY ? [w, h] : null);
          var smT = fit(w, h, SM), smNeeded = !!smT;
          smT = smT || [w, h];
          var lgC = lgT ? draw(bmp, lgT[0], lgT[1]) : null;
          var smC = draw(lgC || bmp, smT[0], smT[1]);   // sm from lg: cheaper and sharper than straight from 12 MP
          if (bmp.close) bmp.close();
          return Promise.all([encodeCapped(smC, smNeeded ? CAPS.sm : 4 * CAPS.lg), lgC ? encodeCapped(lgC, CAPS.lg) : Promise.resolve(null)]).then(function (r) {
            if (!r[0]) return null;
            return { sm: r[0], lg: lgT ? r[1] : null, smNeeded: smNeeded, lgNeeded: !!lgT, w: w, h: h, smW: smT[0], smH: smT[1] };
          });
        });
      }).then(null, function () { return null; });
    }
    function next() {
      while (active < PAR && queue.length) start(queue.shift());
    }
    function start(job) {
      active++;
      build(job.file).then(function (r) { active--; job.resolve(r); next(); });
    }
    function make(file) {
      if (!eligible(file)) return Promise.resolve(null);
      if (memo && memo.has(file)) return memo.get(file);
      var p = new Promise(function (resolve) { queue.push({ file: file, resolve: resolve }); next(); });
      if (memo) memo.set(file, p);
      return p;
    }
    return { eligible: eligible, supported: supported, make: make };
  })();

  /* ---- localStorage ledger: what to offer after a reload ---- */
  function readStore() {
    var list = [];
    try { list = JSON.parse(window.localStorage.getItem(STORE_KEY) || '[]'); } catch (e) { list = []; }
    if (!Array.isArray(list)) list = [];
    var cut = Date.now() - STORE_TTL;
    return list.filter(function (e) { return e && e.id && e.ts > cut; });
  }
  function writeStore(list) { try { window.localStorage.setItem(STORE_KEY, JSON.stringify(list)); } catch (e) {} }
  function remember(entry) {
    var list = readStore().filter(function (e) { return e.id !== entry.id; });
    entry.ts = Date.now();
    list.push(entry);
    writeStore(list);
  }
  function forget(id) { writeStore(readStore().filter(function (e) { return e.id !== id; })); }
  function list(filter) {
    return readStore().filter(function (e) {
      if (!filter) return true;
      return Object.keys(filter).every(function (k) { return filter[k] === undefined || String(e[k]) === String(filter[k]); });
    });
  }
  function abortStored(entry) {
    if (!entry || !entry.id) return;
    try { request(entry.endpoint, { action: 'chunk_abort', upload_id: entry.id, client: entry.client }, null); } catch (e) {}
    forget(entry.id);
  }

  App.chunkUpload = { probe: probe, upload: upload, send: send, remember: remember, forget: forget, list: list, abortStored: abortStored, fmt: { bytes: fmtBytes, speed: fmtSpeed, eta: fmtEta } };
})(window, document);
