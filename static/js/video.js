/* =====================================================================
   Joust client portal — video.js  (App.video, spec §6; loads after app.js)

   Enhances every [data-video] container rendered by partials/components/
   video.php (or built here with App.video.build()):
     - Fallback: when the browser can't decode the file (.mov in Chrome /
       Firefox) the <video> is hidden and the "Preview not supported —
       Open video / Download" card is shown. Never a broken player.
     - Posters: on the first decodable frame the current frame is drawn to a
       canvas (≤ 480px wide), set as the poster and cached in localStorage
       under  poster:<absolute url>  (quota / tainted-canvas safe). Every
       [data-video-thumb][data-video-url] tile on the page gets the cached
       poster on load; grid tiles without one are probed (≤ 3 at a time).
     - Duration badges: [data-video-duration] → "m:ss" (cached under
       duration:<absolute url>); "--:--" until known.
     - Tap-to-unmute pill ([data-video-mute]) on autoplaying detail videos.
     - Timestamp chip ([data-video-stamp] next to a comment composer): shows
       the current time of the video in the same sheet; click inserts
       "m:ss — " at the caret of [data-comment-input].

   API
     App.video.enhance(root)            scan root (default document) for videos, thumbs, composers
     App.video.attach(container)        enhance one [data-video] container
     App.video.markup(url, opts)        HTML string — the JS twin of renderVideoElement()
     App.video.build(url, opts)         same, as a DOM element
       opts: { poster, autoplay, unmute, controls, cls, id, label, download,
               fallback, twin, mime, data:{…} }
     App.video.showFallback(container)  force the card (also called on error)
     App.video.setMuted(container, bool)
     App.video.getPoster(url) / setPoster(url, dataUrl)
     App.video.getDuration(url) / format(seconds)
     App.video.probe(url, cb)           duration (+ opportunistic poster) via a hidden probe
     App.video.insertStamp(form)        insert the current time into the composer
   Events (bubble from the container): 'video:fallback', 'video:poster' {url, poster},
     'video:duration' {url, seconds}, 'video:mute' {muted}.
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  if (App.video) return;

  var POSTER_PREFIX = 'poster:', DURATION_PREFIX = 'duration:';
  var POSTER_MAX_W = 480, PROBE_MAX = 3, PROBE_TIMEOUT = 15000, FALLBACK_CHECK_MS = 1500;
  var NETWORK_NO_SOURCE = 3;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function emit(el, name, detail) { try { el.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail || {} })); } catch (e) {} }
  function reducedMotion() { return App.reducedMotion ? App.reducedMotion() : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }

  function absUrl(u) { try { return new URL(u, location.href).href; } catch (e) { return String(u || ''); } }
  function isBlob(u) { return /^(blob|data):/i.test(String(u || '')); }
  function extOf(u) { var m = /\.([a-z0-9]+)(?:[?#]|$)/i.exec(String(u || '').split('#')[0]); return m ? m[1].toLowerCase() : ''; }
  function mimeOf(ext) { return ext === 'webm' ? 'video/webm' : ext === 'mov' ? 'video/quicktime' : 'video/mp4'; }
  function format(sec) {
    if (typeof sec !== 'number' || !isFinite(sec) || sec < 0) return '--:--';
    var s = Math.round(sec), m = Math.floor(s / 60); s = s % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) {
    try { window.localStorage.setItem(k, v); return true; }
    catch (e) {
      // Quota / private mode: drop the oldest posters and retry once.
      try {
        var keys = [];
        for (var i = 0; i < window.localStorage.length; i++) { var kk = window.localStorage.key(i); if (kk && kk.indexOf(POSTER_PREFIX) === 0) keys.push(kk); }
        keys.slice(0, Math.ceil(keys.length / 2)).forEach(function (kk) { window.localStorage.removeItem(kk); });
        window.localStorage.setItem(k, v); return true;
      } catch (e2) { return false; }
    }
  }

  var ICON_PLAY = '<svg class="ui-icon ui-icon--play" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" stroke="none"><path d="M7.5 5.2v13.6a1 1 0 0 0 1.51.86l11.3-6.8a1 1 0 0 0 0-1.72L9.01 4.34a1 1 0 0 0-1.51.86Z"/></svg>';
  var ICON_SPEAKER = '<svg class="ui-icon ui-icon--speaker ui-video-mute-icon" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9.5h3.2L12 5.5v13l-4.8-4H4z" fill="currentColor" stroke="none"/><path d="M15.5 9.2a4 4 0 0 1 0 5.6"/><path d="M18.3 6.6a8 8 0 0 1 0 10.8"/></svg>';
  var ICON_SPEAKER_SLASH = '<svg class="ui-icon ui-icon--speaker-slash ui-video-mute-icon" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9.5h3.2L12 5.5v13l-4.8-4H4z" fill="currentColor" stroke="none"/><path d="m15.5 9.5 5 5"/><path d="m20.5 9.5-5 5"/></svg>';

  var V = App.video = {
    format: format,

    /* ---------------- caches ---------------- */
    posterKey:   function (url) { return POSTER_PREFIX + absUrl(url); },
    durationKey: function (url) { return DURATION_PREFIX + absUrl(url); },
    getPoster:   function (url) { return isBlob(url) ? null : lsGet(V.posterKey(url)); },
    getDuration: function (url) { var v = isBlob(url) ? null : lsGet(V.durationKey(url)); var n = v == null ? NaN : parseFloat(v); return isFinite(n) ? n : null; },
    setPoster: function (url, dataUrl) {
      if (!url || !dataUrl) return false;
      var ok = isBlob(url) ? false : lsSet(V.posterKey(url), dataUrl);
      V.applyPoster(url, dataUrl);
      return ok;
    },
    setDuration: function (url, seconds) {
      if (!url || typeof seconds !== 'number' || !isFinite(seconds)) return;
      if (!isBlob(url)) lsSet(V.durationKey(url), String(seconds));
      V.applyDuration(url, seconds);
    },

    /* Apply a poster to every thumb / player on the page that shows this URL. */
    applyPoster: function (url, dataUrl) {
      var key = absUrl(url);
      $$('[data-video-thumb][data-video-url]').forEach(function (tile) {
        if (absUrl(tile.getAttribute('data-video-url')) !== key) return;
        var img = $('[data-video-poster]', tile);
        if (img) { if (img.getAttribute('src') !== dataUrl) img.src = dataUrl; img.hidden = false; }
        tile.classList.add('has-poster');
      });
      $$('[data-video][data-video-url]').forEach(function (box) {
        if (absUrl(box.getAttribute('data-video-url')) !== key) return;
        var video = $('video', box);
        if (video && !video.poster) video.poster = dataUrl;
        emit(box, 'video:poster', { url: key, poster: dataUrl });
      });
    },
    applyDuration: function (url, seconds) {
      var key = absUrl(url), text = format(seconds);
      $$('[data-video-thumb][data-video-url], [data-video][data-video-url]').forEach(function (el) {
        if (absUrl(el.getAttribute('data-video-url')) !== key) return;
        var badge = $('[data-video-duration]', el) || (el.parentNode && $('[data-video-duration]', el.parentNode));
        if (badge) { var t = $('[data-video-duration-text]', badge); (t || badge).textContent = text; badge.setAttribute('data-seconds', String(seconds)); }
        emit(el, 'video:duration', { url: key, seconds: seconds });
      });
    },

    /* ---------------- markup (JS twin of renderVideoElement) ---------------- */
    markup: function (url, opts) {
      opts = opts || {};
      var src = String(url || ''), ext = extOf(src), mime = opts.mime || mimeOf(ext);
      var autoplay = !!opts.autoplay, unmute = 'unmute' in opts ? !!opts.unmute : autoplay;
      var controls = !('controls' in opts) || !!opts.controls, fallback = !('fallback' in opts) || !!opts.fallback;
      var attrs = ' data-video data-video-url="' + esc(src) + '" data-video-ext="' + esc(ext) + '"' + (autoplay ? ' data-video-autoplay' : '');
      if (opts.data) Object.keys(opts.data).forEach(function (k) {
        var key = String(k).toLowerCase().replace(/[^a-z0-9\-]/g, ''); if (!key) return;
        var v = opts.data[k]; attrs += ' data-' + key + (v === true || v == null ? '' : '="' + esc(v) + '"');
      });
      var out = '<div class="ui-video' + (opts.cls ? ' ' + esc(opts.cls) : '') + '"' + attrs + '>';
      out += '<video playsinline muted' + (controls ? ' controls' : '') + ' preload="metadata"'
           + (opts.poster ? ' poster="' + esc(opts.poster) + '"' : '') + (autoplay ? ' autoplay' : '')
           + (opts.id ? ' id="' + esc(opts.id) + '"' : '') + (opts.label ? ' aria-label="' + esc(opts.label) + '"' : '') + '>';
      out += '<source src="' + esc(src) + '" type="' + esc(mime) + '">';
      if (opts.twin) out += '<source src="' + esc(opts.twin) + '" type="video/mp4">';
      out += '</video>';
      if (unmute) {
        out += '<button type="button" class="ui-pill ui-pill--glass ui-pill--nodot ui-video-mute" data-video-mute aria-pressed="true" hidden>'
             + ICON_SPEAKER_SLASH + '<span data-video-mute-label>Tap to unmute</span></button>';
      }
      if (fallback) {
        out += '<div class="ui-video-fallback" data-video-fallback hidden><div class="ui-video-fallback-card">'
             + '<p class="ui-video-fallback-title">Preview not supported in this browser</p>'
             + '<p class="ui-video-fallback-text">This video plays in Safari on iPhone, iPad and Mac.</p>'
             + '<div class="ui-btn-group">'
             + '<a class="ui-btn ui-btn--filled ui-btn--sm" data-video-open href="' + esc(src) + '" target="_blank" rel="noopener">Open video</a>'
             + '<a class="ui-btn ui-btn--gray ui-btn--sm" data-video-download href="' + esc(src) + '" download' + (opts.download ? '="' + esc(opts.download) + '"' : '') + '>Download</a>'
             + '</div></div></div>';
      }
      return out + '</div>';
    },
    build: function (url, opts) {
      var wrap = document.createElement('div');
      wrap.innerHTML = V.markup(url, opts);
      var box = wrap.firstElementChild;
      V.attach(box);
      return box;
    },

    /* ---------------- enhancement ---------------- */
    enhance: function (root) {
      root = root && root.nodeType ? root : document;
      var boxes = $$('[data-video]', root), thumbs = $$('[data-video-thumb]', root), forms = $$('[data-comment-form]', root);
      if (root !== document && root.matches) {
        if (root.matches('[data-video]')) boxes.unshift(root);
        if (root.matches('[data-video-thumb]')) thumbs.unshift(root);
        if (root.matches('[data-comment-form]')) forms.unshift(root);
      }
      boxes.forEach(V.attach);
      thumbs.forEach(V.attachThumb);
      forms.forEach(V.attachForm);
    },

    attach: function (box) {
      if (!box || box.__video) return;
      var video = $('video', box); if (!video) return;
      box.__video = true;
      var url = box.getAttribute('data-video-url') || (function () { var s = $('source', video); return s ? s.getAttribute('src') : video.getAttribute('src'); })() || '';
      var sources = $$('source', video);
      var state = { posterDone: false };

      // --- fallback: error on the element or its last <source>, or resource selection failed ---
      var fail = function () { V.showFallback(box); };
      video.addEventListener('error', fail);
      if (sources.length) sources[sources.length - 1].addEventListener('error', fail);
      var check = function () {
        if (box.classList.contains('is-fallback')) return;
        if (video.error) { fail(); return; }
        // After the resource-selection algorithm, a video with no playable <source> sits at NETWORK_NO_SOURCE
        // with nothing loaded (Chrome/Firefox skip a type="video/quicktime" source without any event we may still catch).
        if (video.readyState === 0 && video.networkState === NETWORK_NO_SOURCE) fail();
      };
      setTimeout(check, 0);
      setTimeout(check, FALLBACK_CHECK_MS);

      // --- cached poster ---
      var cached = V.getPoster(url);
      if (cached && !video.poster) video.poster = cached;

      // --- metadata: duration badges ---
      var onMeta = function () { if (isFinite(video.duration)) V.setDuration(url, video.duration); };
      if (video.readyState >= 1) onMeta(); else video.addEventListener('loadedmetadata', onMeta);

      // --- poster generation: first decodable frame (provisional), then the first frame past 0.3 s (final) ---
      var capture = function (final) {
        if (state.posterDone) return;
        if (!final && (cached || state.provisional)) return;
        var data = V.capture(video);
        if (!data) return;
        if (final) state.posterDone = true; else state.provisional = true;
        V.setPoster(url, data);
      };
      if (video.readyState >= 2) capture(false); else video.addEventListener('loadeddata', function () { capture(false); }, { once: true });
      video.addEventListener('timeupdate', function onTime() {
        if (video.currentTime > 0.3) { video.removeEventListener('timeupdate', onTime); capture(true); }
      });

      // --- tap-to-unmute pill ---
      var pill = $('[data-video-mute]', box);
      if (pill) {
        var showPill = function () { if (!box.classList.contains('is-fallback')) { pill.hidden = false; V._syncMute(box); } };
        video.addEventListener('play', showPill);
        video.addEventListener('volumechange', function () { V._syncMute(box); });
        video.addEventListener('ended', function () { pill.hidden = true; });
        pill.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          V.setMuted(box, !video.muted);
        });
        if (!video.paused) showPill();
      }

      // --- active video for the timestamp chips ---
      ['play', 'seeked', 'timeupdate'].forEach(function (ev) { video.addEventListener(ev, function () { V._stampFrom(video); }); });
      video.addEventListener('pause', function () { V._stampFrom(video); });
    },

    showFallback: function (box) {
      if (!box || box.classList.contains('is-fallback')) return;
      var video = $('video', box), card = $('[data-video-fallback]', box), pill = $('[data-video-mute]', box);
      box.classList.add('is-fallback');
      if (video) { try { video.pause(); } catch (e) {} video.hidden = true; video.setAttribute('aria-hidden', 'true'); }
      if (pill) pill.hidden = true;
      if (card) { card.hidden = false; if (reducedMotion()) card.classList.add('is-instant'); }
      V._forms.forEach(V._refreshForm);   // a chip pointing at this video hides (or moves to another decodable one)
      emit(box, 'video:fallback', { url: box.getAttribute('data-video-url') || '' });
    },

    setMuted: function (box, muted) {
      var video = $('video', box); if (!video) return;
      video.muted = !!muted;
      if (!muted && video.paused) { var p = video.play(); if (p && p.catch) p.catch(function () {}); }
      V._syncMute(box);
      emit(box, 'video:mute', { muted: video.muted });
    },
    _syncMute: function (box) {
      var video = $('video', box), pill = $('[data-video-mute]', box); if (!video || !pill) return;
      var label = $('[data-video-mute-label]', pill), icon = $('.ui-video-mute-icon', pill);
      pill.setAttribute('aria-pressed', video.muted ? 'true' : 'false');
      pill.classList.toggle('is-unmuted', !video.muted);
      if (label) label.textContent = video.muted ? 'Tap to unmute' : 'Mute';
      if (icon) { var wrap = document.createElement('span'); wrap.innerHTML = video.muted ? ICON_SPEAKER_SLASH : ICON_SPEAKER; icon.parentNode.replaceChild(wrap.firstElementChild, icon); }
    },

    /* Draw the current frame (≤ 480px wide) → JPEG data URL; null when nothing decodable or the canvas is tainted. */
    capture: function (video) {
      if (!video || !video.videoWidth || !video.videoHeight || video.readyState < 2) return null;
      try {
        var scale = Math.min(1, POSTER_MAX_W / video.videoWidth);
        var w = Math.max(1, Math.round(video.videoWidth * scale)), h = Math.max(1, Math.round(video.videoHeight * scale));
        var c = document.createElement('canvas'); c.width = w; c.height = h;
        var ctx = c.getContext('2d'); if (!ctx) return null;
        ctx.drawImage(video, 0, 0, w, h);
        var data = c.toDataURL('image/jpeg', 0.8);          // throws SecurityError when cross-origin-tainted
        return data && data.length > 64 ? data : null;
      } catch (e) { return null; }
    },

    /* ---------------- thumbs (grid / list tiles) ---------------- */
    attachThumb: function (tile) {
      if (!tile || tile.__videoThumb) return;
      tile.__videoThumb = true;
      var url = tile.getAttribute('data-video-url'); if (!url) return;
      var poster = V.getPoster(url);
      if (poster) V.applyPoster(url, poster);
      var dur = V.getDuration(url);
      var badge = $('[data-video-duration]', tile) || (tile.parentNode && $('[data-video-duration]', tile.parentNode));
      if (dur != null) V.applyDuration(url, dur);
      else if (badge) { var t = $('[data-video-duration-text]', badge); (t || badge).textContent = '--:--'; }
      if ((dur == null && badge) || !poster) V.probe(url);
    },

    /* Hidden preload="metadata" probe, ≤ PROBE_MAX at a time. cb(seconds|null). Also captures a poster when it can. */
    _queue: [], _active: 0, _probed: {},
    probe: function (url, cb) {
      var key = absUrl(url);
      if (V._probed[key]) { if (cb) cb(V.getDuration(url)); return; }
      V._probed[key] = true;
      V._queue.push({ url: url, cb: cb });
      V._drain();
    },
    _drain: function () {
      while (V._active < PROBE_MAX && V._queue.length) {
        var job = V._queue.shift(); V._active++;
        V._runProbe(job.url, (function (job) { return function (sec) { V._active--; if (job.cb) job.cb(sec); V._drain(); }; })(job));
      }
    },
    _runProbe: function (url, done) {
      var v = document.createElement('video'), s = document.createElement('source'), finished = false, needPoster = !V.getPoster(url);
      v.muted = true; v.setAttribute('playsinline', ''); v.preload = 'metadata';
      v.setAttribute('aria-hidden', 'true'); v.tabIndex = -1;
      v.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px';
      s.src = url; s.type = mimeOf(extOf(url));
      v.appendChild(s);
      var teardown = function () {
        try { v.pause(); v.removeAttribute('src'); while (v.firstChild) v.removeChild(v.firstChild); v.load(); } catch (e) {}
        if (v.parentNode) v.parentNode.removeChild(v);
      };
      var finish = function (sec) {
        if (finished) return; finished = true;
        clearTimeout(timer);
        done(sec);
        // Release the element only once its range fetch has gone idle — tearing it down mid-fetch
        // aborts the request, which shows up as a failed media load in diagnostics.
        if (v.networkState !== 2 /* NETWORK_LOADING */) { teardown(); return; }
        var t2 = setTimeout(teardown, 8000);
        var idle = function () { clearTimeout(t2); teardown(); };
        v.addEventListener('suspend', idle, { once: true });
        v.addEventListener('stalled', idle, { once: true });
      };
      var timer = setTimeout(function () { finish(null); }, PROBE_TIMEOUT);
      var fail = function () { finish(null); };
      v.addEventListener('error', fail); s.addEventListener('error', fail);
      v.addEventListener('loadedmetadata', function () {
        var sec = isFinite(v.duration) ? v.duration : null;
        if (sec != null) V.setDuration(url, sec);
        if (!needPoster || !v.videoWidth) { finish(sec); return; }
        var grab = function () { var d = V.capture(v); if (d) V.setPoster(url, d); finish(sec); };
        if (v.readyState >= 2) { grab(); return; }
        v.addEventListener('loadeddata', grab, { once: true });
        v.addEventListener('seeked', grab, { once: true });
        try { v.currentTime = Math.min(0.5, sec ? sec / 2 : 0.5); } catch (e) { grab(); }
      });
      setTimeout(function () { if (!finished && v.readyState === 0 && v.networkState === NETWORK_NO_SOURCE) finish(null); }, FALLBACK_CHECK_MS);
      document.body.appendChild(v);
      try { v.load(); } catch (e) {}
    },

    /* ---------------- timestamp chip on comment composers ---------------- */
    _forms: [],
    attachForm: function (form) {
      if (!form || form.__videoStamp) return;
      var chip = $('[data-video-stamp]', form); if (!chip) return;
      form.__videoStamp = true;
      var entry = { form: form, chip: chip, label: $('[data-video-stamp-label]', chip) || chip, video: null };
      V._forms.push(entry);
      chip.addEventListener('click', function (e) { e.preventDefault(); V.insertStamp(form); });
      V._refreshForm(entry);
    },
    _rootOf: function (el) { return el.closest('.ui-sheet-root, [data-viewer], [data-post-detail], .pd, main, body') || document.body; },
    _videosFor: function (entry) { return $$('[data-video]:not(.is-fallback) video', V._rootOf(entry.form)); },
    _refreshForm: function (entry) {
      if (!entry.form.isConnected) return;
      var vids = V._videosFor(entry);
      var video = entry.video && vids.indexOf(entry.video) !== -1 ? entry.video : vids[0] || null;
      entry.video = video;
      entry.chip.hidden = !video;
      if (video) entry.label.textContent = format(video.currentTime || 0);
    },
    _stampFrom: function (video) {
      V._forms = V._forms.filter(function (e) { return e.form.isConnected; });
      V._forms.forEach(function (entry) {
        var root = V._rootOf(entry.form);
        if (!root.contains(video)) return;
        entry.video = video;
        entry.chip.hidden = false;
        entry.label.textContent = format(video.currentTime || 0);
      });
    },
    insertStamp: function (form) {
      var entry = null;
      V._forms.forEach(function (e) { if (e.form === form) entry = e; });
      if (!entry) { V.attachForm(form); V._forms.forEach(function (e) { if (e.form === form) entry = e; }); }
      if (!entry) return;
      V._refreshForm(entry);
      var video = entry.video, input = $('[data-comment-input]', form);
      if (!video || !input) return;
      var text = format(video.currentTime || 0) + ' — ';
      var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
      var end   = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
      if (input.setRangeText) input.setRangeText(text, start, end, 'end');
      else input.value = input.value.slice(0, start) + text + input.value.slice(end);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.focus();
      return text;
    },

    /* ---------------- boot ---------------- */
    _observer: null,
    init: function () {
      if (V._inited) return; V._inited = true;
      V.enhance(document);
      if (window.MutationObserver && document.body) {
        var pending = [], scheduled = false;
        V._observer = new MutationObserver(function (records) {
          records.forEach(function (r) {
            Array.prototype.forEach.call(r.addedNodes, function (n) { if (n.nodeType === 1) pending.push(n); });
          });
          if (scheduled || !pending.length) return;
          scheduled = true;
          requestAnimationFrame(function () {
            scheduled = false;
            var nodes = pending.splice(0);
            nodes.forEach(function (n) { if (n.isConnected) V.enhance(n); });
            V._forms.forEach(V._refreshForm);
          });
        });
        V._observer.observe(document.body, { childList: true, subtree: true });
      }
      document.addEventListener('posts:open', function () { V.enhance(document); V._forms.forEach(V._refreshForm); });
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', V.init);
  else V.init();
})(window, document);
