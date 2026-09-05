/* =====================================================================
   Joust client portal — assets.js  (Assets phase; extends window.App)

   App.viewer  — full-screen media viewer (partials/components/media-viewer.php)
     .open(items, index, {mode:'review'|'browse', onClose})
     .close() .next() .prev() .goTo(i) .current()
     .approve() .deny(note) .decide(status, comment)
     items[] = {id, kind:'library'|'tire', status, src, type:'image'|'video',
                mime, label, download, endpoint, manage, tile?}
     Events on the viewer root (bubble): 'viewer:decision' {item, status, prev,
       optimistic|ok|rolledBack, error}, 'viewer:navigate' {item, index},
       'viewer:replaced' {item, src}, 'viewer:close'.
     Swipe / ← → to navigate, pinch or double-tap to zoom, Esc to close.
     After Approve or Deny the viewer auto-advances to the next pending item
     (review mode) or the next remaining item (browse mode); at the end it
     shows "All caught up" and closes on tap. Deny requires a note >= 3 chars,
     sent in ONE request (status=denied + comment) to the item's endpoint.

   App.assets  — the Assets page: grid → viewer, optimistic tile updates with
     rollback, denied tiles leave on the spot (scale .9 + fade, FLIP reflow
     with a spring; reduced motion → crossfade), live filter/badge counts,
     multi-select batch Approve (sequential queue with progress), deep links
     (?asset=&kind= or #lib-<id> / #image-<id>).

   Loads with `defer` before app.js, so nothing here touches App.* until
   'app:ready' (or immediately if App has already initialised).
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function reduced() {
    if (App.reducedMotion) return App.reducedMotion();
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }
  function toast(msg, opts) { if (App.toast) App.toast(msg, opts); }
  function emit(el, name, detail) { el.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail || {} })); }
  function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }
  function afterMs(ms, fn) { return setTimeout(fn, reduced() ? Math.min(ms, 160) : ms); }

  /* ================================================================ */
  /* Viewer                                                            */
  /* ================================================================ */
  var viewer = App.viewer = {
    root: null, refs: {}, items: [], index: -1, opts: {},
    isOpen: false, _closing: false, _slide: null, _lastFocus: null,
    _zoom: { scale: 1, tx: 0, ty: 0 },

    init: function (root) {
      root = root || $('[data-viewer]');
      if (!root || root._viewerInit) return this;
      root._viewerInit = true;
      this.root = root;
      root.tabIndex = -1;
      var r = this.refs = {
        stage: $('[data-viewer-stage]', root), track: $('[data-viewer-track]', root),
        title: $('[data-viewer-title]', root), count: $('[data-viewer-count]', root), status: $('[data-viewer-status]', root),
        prev: $('[data-viewer-prev]', root), next: $('[data-viewer-next]', root),
        done: $('[data-viewer-done]', root), bar: $('[data-viewer-bar]', root), actions: $('[data-viewer-actions]', root),
        deny: $('[data-viewer-deny]', root), denyLabel: $('[data-viewer-deny-label]', root),
        approve: $('[data-viewer-approve]', root), approveLabel: $('[data-viewer-approve-label]', root),
        more: $('[data-viewer-more]', root), menu: $('[data-viewer-menu]', root),
        note: $('[data-viewer-note]', root), noteInput: $('[data-viewer-note-input]', root),
        noteHint: $('[data-viewer-note-hint]', root), noteSend: $('[data-viewer-note-send]', root), noteCancel: $('[data-viewer-note-cancel]', root),
        replace: $('[data-viewer-replace]', root), replaceInput: $('[data-viewer-replace-input]', root), manage: $('[data-viewer-manage]', root)
      };
      var self = this;

      $('[data-viewer-close]', root).addEventListener('click', function () { self.close(); });
      if (r.prev) r.prev.addEventListener('click', function () { self.prev(); });
      if (r.next) r.next.addEventListener('click', function () { self.next(); });
      r.approve.addEventListener('click', function () { self.approve(); });
      r.deny.addEventListener('click', function () { self.showNote(); });
      r.more.addEventListener('click', function (e) { e.stopPropagation(); self.toggleMenu(); });
      r.done.addEventListener('click', function () { self.close(); });

      // Deny note: required, >= 3 characters (the server enforces the same rule)
      r.noteInput.addEventListener('input', function () { self._validateNote(); });
      r.noteCancel.addEventListener('click', function () { self.hideNote(); });
      r.note.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = r.noteInput.value.trim();
        if (text.length < 3) { self._validateNote(true); r.noteInput.focus(); return; }
        self.deny(text);
      });

      // More menu
      $$('[data-viewer-download]', root).forEach(function (b) { b.addEventListener('click', function () { self.closeMenu(); self.download(); }); });
      if (r.replace && r.replaceInput) {
        r.replace.addEventListener('click', function () { self.closeMenu(); r.replaceInput.value = ''; r.replaceInput.click(); });
        r.replaceInput.addEventListener('change', function () { if (r.replaceInput.files && r.replaceInput.files[0]) self.replace(r.replaceInput.files[0]); });
      }
      if (r.manage) r.manage.addEventListener('click', function () { self.closeMenu(); });
      document.addEventListener('click', function (e) { if (self.isOpen && !r.menu.hidden && !e.target.closest('[data-viewer-menu]')) self.closeMenu(); });

      this._bindGestures();
      this._onKey = this._onKey.bind(this);
      return this;
    },

    /* ---------------- open / close ---------------- */
    open: function (items, index, opts) {
      this.init();
      if (!this.root || !items || !items.length) return null;
      this.items = items.slice();
      this.opts = opts || {};
      this.isOpen = true; this._closing = false;
      this._lastFocus = document.activeElement;
      if (App.lockScroll) App.lockScroll();
      var root = this.root;
      root.hidden = false;
      root.setAttribute('aria-hidden', 'false');
      this.hideDone(); this.hideNote(); this.closeMenu(); this.hideFallback();
      this.refs.bar.hidden = false;
      this.goTo(clamp(index || 0, 0, this.items.length - 1));
      requestAnimationFrame(function () { requestAnimationFrame(function () { root.classList.add('is-visible'); }); });
      document.addEventListener('keydown', this._onKey);
      try { root.focus({ preventScroll: true }); } catch (e) { root.focus(); }
      return root;
    },

    close: function () {
      if (!this.isOpen || this._closing) return;
      this._closing = true;
      var self = this, root = this.root;
      this._pauseVideo();
      root.classList.remove('is-visible');
      afterMs(230, function () {
        root.hidden = true;
        root.setAttribute('aria-hidden', 'true');
        self.refs.track.innerHTML = '';
        self._slide = null;
        self.isOpen = false; self._closing = false;
        document.removeEventListener('keydown', self._onKey);
        if (App.unlockScroll) App.unlockScroll();
        var back = self._lastFocus; self._lastFocus = null;
        if (back && back.focus && document.contains(back)) { try { back.focus({ preventScroll: true }); } catch (e) {} }
        emit(root, 'viewer:close', {});
        if (self.opts.onClose) self.opts.onClose();
      });
    },

    current: function () { return this.items[this.index] || null; },
    hasNext: function () { return this.index < this.items.length - 1; },
    hasPrev: function () { return this.index > 0; },
    next: function () { if (this.hasNext()) this.goTo(this.index + 1, 1); },
    prev: function () { if (this.hasPrev()) this.goTo(this.index - 1, -1); },

    /* ---------------- rendering ---------------- */
    goTo: function (i, dir) {
      if (i < 0 || i >= this.items.length) return;
      var item = this.items[i], r = this.refs, self = this;
      this.hideNote(); this.closeMenu(); this.hideFallback(); this.hideDone();
      r.bar.hidden = false;
      var old = this._slide;
      this._pauseVideo();
      var slide = this._buildSlide(item);
      this._slide = slide; this.index = i;
      this._resetZoom(false);

      if (old && dir) slide.classList.add(dir > 0 ? 'ui-viewer-slide--enter-next' : 'ui-viewer-slide--enter-prev');
      r.track.appendChild(slide);
      if (old) {
        void slide.offsetWidth;   // commit the start position, then animate in
        slide.classList.remove('ui-viewer-slide--enter-next', 'ui-viewer-slide--enter-prev');
        old.classList.remove('is-dragging');
        old.classList.add(dir < 0 ? 'ui-viewer-slide--leave-prev' : 'ui-viewer-slide--leave-next');
        afterMs(380, function () { if (old.parentNode) old.parentNode.removeChild(old); });
      }
      this.updateChrome();
      this._preload(i + 1); this._preload(i - 1);
      emit(this.root, 'viewer:navigate', { item: item, index: i });
    },

    _buildSlide: function (item) {
      var slide = document.createElement('div');
      slide.className = 'ui-viewer-slide';
      var media, self = this;
      if (item.type === 'video') {
        // spec §6 via App.video.build(): autoplay muted + tap-to-unmute pill, and the
        // "Open video / Download" card inside the slide when the browser can't decode it.
        var box = App.video
          ? App.video.build(item.src, { mime: item.mime, poster: item.poster || App.video.getPoster(item.src), autoplay: true, unmute: true,
                                        cls: 'ui-viewer-video', download: item.download, label: item.label, twin: item.twin || '' })
          : null;
        if (box) {
          media = $('video', box);
          media.classList.add('ui-viewer-media');
          media.addEventListener('loadedmetadata', function () { item.duration = media.duration; });
          box.addEventListener('video:fallback', function () { self.showFallback(item); });
          slide.appendChild(box);
          slide._media = media;
          return slide;
        }
        media = document.createElement('video');
        media.className = 'ui-viewer-media';
        media.setAttribute('playsinline', ''); media.muted = true; media.controls = true; media.preload = 'metadata';
        var source = document.createElement('source');
        source.src = item.src; if (item.mime) source.type = item.mime;
        media.appendChild(source);
      } else {
        var spinner = document.createElement('div');
        spinner.className = 'ui-viewer-loading';
        slide.appendChild(spinner);
        media = document.createElement('img');
        media.className = 'ui-viewer-media';
        media.alt = item.label || ''; media.draggable = false; media.decoding = 'async';
        var clear = function () { if (spinner.parentNode) spinner.parentNode.removeChild(spinner); };
        media.addEventListener('load', clear); media.addEventListener('error', clear);
        media.src = item.src;
        if (media.complete && media.naturalWidth) clear();
      }
      slide.appendChild(media);
      slide._media = media;
      return slide;
    },

    _preload: function (i) {
      var it = this.items[i];
      if (!it || it.type !== 'image' || it._preloaded) return;
      it._preloaded = true;
      var im = new Image(); im.decoding = 'async'; im.src = it.src;
    },

    _pauseVideo: function () {
      var m = this._slide && this._slide._media;
      if (m && m.tagName === 'VIDEO') { try { m.pause(); } catch (e) {} }
    },

    updateChrome: function () {
      var item = this.current(), r = this.refs;
      if (!item) return;
      r.title.textContent = item.label || '';
      r.count.textContent = (this.index + 1) + ' of ' + this.items.length;
      if (App.status) App.status.applyPill(r.status, item.status, false);
      var approved = item.status === 'approved', denied = item.status === 'denied';
      r.approve.classList.toggle('is-done', approved);
      r.approveLabel.textContent = approved ? 'Approved' : 'Approve';
      r.approve.setAttribute('aria-pressed', approved ? 'true' : 'false');
      r.deny.classList.toggle('is-done', denied);
      r.denyLabel.textContent = denied ? 'Needs changes' : 'Deny';
      r.deny.setAttribute('aria-pressed', denied ? 'true' : 'false');
      if (r.prev) r.prev.disabled = !this.hasPrev();
      if (r.next) r.next.disabled = !this.hasNext();
      $$('[data-tire-only]', r.menu).forEach(function (el) { el.hidden = item.kind !== 'tire'; });
      if (r.manage) { r.manage.href = item.manage || '#'; if (!item.manage) r.manage.hidden = true; }
    },

    /* ---------------- decisions ---------------- */
    approve: function () { return this.decide('approved'); },

    deny: function (note) {
      note = (note || '').trim();
      if (note.length < 3) { this.showNote(); this._validateNote(true); return Promise.resolve(null); }
      this.hideNote();
      return this.decide('denied', note);
    },

    /** Optimistic: flip the item now, POST, roll back + toast on any non-2xx / ok:false. */
    decide: function (status, comment) {
      var item = this.current(), self = this, root = this.root;
      if (!item || item._busy || !item.endpoint) return Promise.resolve(null);
      var prev = item.status;
      item.status = status; item._busy = true;
      this.updateChrome();
      emit(root, 'viewer:decision', { item: item, status: status, prev: prev, optimistic: true });

      var params = { id: item.id, status: status, actor: App.actor };
      if (comment) params.comment = comment;
      var p = App.post(item.endpoint, params).then(function (res) {
        item._busy = false;
        if (!res.ok) {
          item.status = prev;
          if (self.current() === item) self.updateChrome();
          toast(res.error || 'Something went wrong', { kind: 'error' });
          emit(root, 'viewer:decision', { item: item, status: prev, prev: status, rolledBack: true, error: res.error });
        } else {
          emit(root, 'viewer:decision', { item: item, status: status, prev: prev, ok: true, data: res.data });
        }
        return res;
      });

      var idx = this.index;
      afterMs(180, function () { if (self.isOpen && self.index === idx) self.advance(); });   // a beat so the state flip is seen
      return p;
    },

    /** Next pending item (review), else next remaining item (browse), else "All caught up". */
    advance: function () {
      var items = this.items, idx = this.index, mode = this.opts.mode || 'review', n = -1, i;
      var isTarget = mode === 'review'
        ? function (it) { return it.status === 'pending'; }
        : function (it) { return it.status !== 'denied'; };
      for (i = idx + 1; i < items.length; i++) if (isTarget(items[i])) { n = i; break; }
      if (n < 0 && mode === 'review') for (i = 0; i < idx; i++) if (isTarget(items[i])) { n = i; break; }
      if (n < 0) { this.showDone(); return; }
      this.goTo(n, n > idx ? 1 : -1);
    },

    showDone: function () {
      this._pauseVideo();
      this.hideNote(); this.closeMenu();
      this.refs.bar.hidden = true;
      this.refs.done.hidden = false;
      try { this.refs.done.focus({ preventScroll: true }); } catch (e) {}
    },
    hideDone: function () { this.refs.done.hidden = true; },

    /* ---------------- deny note ---------------- */
    showNote: function () {
      var r = this.refs;
      this.closeMenu();
      r.actions.hidden = true;
      r.note.hidden = false;
      r.noteInput.value = '';
      this._validateNote();
      setTimeout(function () { r.noteInput.focus(); }, 30);
    },
    hideNote: function () {
      var r = this.refs;
      if (r.note.hidden) return;
      r.note.hidden = true;
      r.actions.hidden = false;
    },
    _validateNote: function (showError) {
      var r = this.refs, len = r.noteInput.value.trim().length, ok = len >= 3;
      r.noteSend.disabled = !ok;
      r.noteInput.classList.toggle('is-invalid', !!showError && !ok);
      r.noteHint.textContent = ok
        ? 'Sent to Joust with your decision.'
        : (showError ? 'Please write at least 3 characters.' : 'A short note is required (at least 3 characters).');
    },

    /* ---------------- more menu ---------------- */
    toggleMenu: function () { if (this.refs.menu.hidden) this.openMenu(); else this.closeMenu(); },
    openMenu: function () {
      var r = this.refs;
      r.menu.hidden = false;
      r.more.setAttribute('aria-expanded', 'true');
      var first = $('[role="menuitem"]:not([hidden])', r.menu);
      if (first) { try { first.focus({ preventScroll: true }); } catch (e) {} }
    },
    closeMenu: function () {
      var r = this.refs;
      if (!r.menu || r.menu.hidden) return;
      r.menu.hidden = true;
      r.more.setAttribute('aria-expanded', 'false');
    },

    download: function () {
      var item = this.current();
      if (!item) return;
      var name = item.download || 'image';
      fetch(item.src, { credentials: 'same-origin' })
        .then(function (res) { if (!res.ok) throw new Error('fetch'); return res.blob(); })
        .then(function (blob) {
          var url = URL.createObjectURL(blob), a = document.createElement('a');
          a.href = url; a.download = name; a.rel = 'noopener';
          document.body.appendChild(a); a.click(); document.body.removeChild(a);
          setTimeout(function () { URL.revokeObjectURL(url); }, 3000);
          toast('Saved to downloads', { kind: 'success' });
        })
        .catch(function () { window.open(item.src, '_blank', 'noopener'); });
    },

    /** Admin only (the input exists only when the server rendered it). replace-image.php contract: image_id, image, type=tire. */
    replace: function (file) {
      var item = this.current(), self = this, root = this.root;
      if (!item || item.kind !== 'tire' || !file) return;
      var endpoint = root.getAttribute('data-replace-endpoint') || 'replace-image.php';
      var fd = new FormData();
      fd.append('image_id', item.id); fd.append('image', file); fd.append('type', 'tire'); fd.append('actor', App.actor || 'admin');
      toast('Replacing…');
      fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (res) { return res.text().then(function (t) { var d = null; try { d = JSON.parse(t); } catch (e) {} return { ok: res.ok && !!d && d.ok !== false, data: d, status: res.status }; }); })
        .then(function (res) {
          if (!res.ok) throw new Error((res.data && res.data.error) || ('Replace failed (' + res.status + ')'));
          var base = item.src.indexOf('/uploads/') > 0 ? item.src.slice(0, item.src.indexOf('/uploads/')) : '';
          var url = base + '/' + res.data.image_url + '?t=' + Date.now();
          var meta = /\.(mp4|webm|mov|m4v)(\?|$)/i.test(res.data.image_url);
          item.src = url; item.type = (res.data.media_type === 'video' || meta) ? 'video' : 'image';
          item._preloaded = false;
          if (self.current() === item) self.goTo(self.index);
          emit(root, 'viewer:replaced', { item: item, src: url });
          toast('Image replaced', { kind: 'success' });
        })
        .catch(function (err) { toast(err.message || 'Replace failed', { kind: 'error' }); });
    },

    /* ---------------- video fallback (§6) — the card lives inside the slide (App.video) ---------------- */
    showFallback: function (item) {
      if (item) item.fallback = true;
      emit(this.root, 'viewer:fallback', { item: item });
    },
    hideFallback: function () {},

    /* ---------------- keyboard ---------------- */
    _onKey: function (e) {
      if (!this.isOpen) return;
      var inField = e.target && (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT');
      if (e.key === 'Escape') {
        e.preventDefault();
        if (!this.refs.menu.hidden) this.closeMenu();
        else if (!this.refs.note.hidden) this.hideNote();
        else this.close();
        return;
      }
      if (inField) return;
      if (e.key === 'ArrowRight') { e.preventDefault(); this.next(); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); this.prev(); }
      else if (e.key === 'Tab') this._trapFocus(e);
    },
    _trapFocus: function (e) {
      var items = $$('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])', this.root)
        .filter(function (el) { return !el.hidden && el.offsetParent !== null; });
      if (!items.length) { e.preventDefault(); return; }
      var first = items[0], last = items[items.length - 1];
      if (e.shiftKey && (document.activeElement === first || !this.root.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    },

    /* ---------------- gestures: swipe, pull-down, pinch, double-tap ---------------- */
    _resetZoom: function (animate) {
      var z = this._zoom; z.scale = 1; z.tx = 0; z.ty = 0;
      var m = this._slide && this._slide._media;
      if (m) { m.classList.toggle('is-zooming', animate === false); m.classList.remove('is-zoomed'); m.style.transform = ''; if (animate === false) requestAnimationFrame(function () { m.classList.remove('is-zooming'); }); }
    },
    _applyZoom: function (immediate) {
      var z = this._zoom, m = this._slide && this._slide._media;
      if (!m) return;
      m.classList.toggle('is-zooming', !!immediate);
      m.classList.toggle('is-zoomed', z.scale > 1);
      m.style.transform = z.scale > 1 ? 'translate(' + z.tx + 'px, ' + z.ty + 'px) scale(' + z.scale + ')' : '';
    },
    _clampPan: function () {
      var z = this._zoom, m = this._slide && this._slide._media;
      if (!m || z.scale <= 1) return;
      var stage = this.refs.stage.getBoundingClientRect(), rect = m.getBoundingClientRect();
      var baseW = rect.width / z.scale, baseH = rect.height / z.scale;
      var baseLeft = rect.left - z.tx - stage.left, baseTop = rect.top - z.ty - stage.top;
      var W = baseW * z.scale, H = baseH * z.scale;
      z.tx = W <= stage.width ? (stage.width - W) / 2 - baseLeft : clamp(z.tx, stage.width - W - baseLeft, -baseLeft);
      z.ty = H <= stage.height ? (stage.height - H) / 2 - baseTop : clamp(z.ty, stage.height - H - baseTop, -baseTop);
    },
    _zoomAt: function (scale, cx, cy) {
      var z = this._zoom, m = this._slide && this._slide._media;
      if (!m) return;
      var rect = m.getBoundingClientRect();
      var baseLeft = rect.left - z.tx, baseTop = rect.top - z.ty;
      var px = (cx - rect.left) / z.scale, py = (cy - rect.top) / z.scale;   // point under the finger, in media units
      z.scale = clamp(scale, 1, 4);
      z.tx = cx - baseLeft - px * z.scale;
      z.ty = cy - baseTop - py * z.scale;
      if (z.scale === 1) { z.tx = 0; z.ty = 0; }
      this._clampPan();
      this._applyZoom(false);
    },

    _bindGestures: function () {
      var self = this, r = this.refs, stage = r.stage;
      var pointers = {}, count = 0, swipe = null, pinch = null, pan = null, lastTap = 0, tapPos = null;

      function keys() { return Object.keys(pointers); }
      function mid() { var k = keys(), a = pointers[k[0]], b = pointers[k[1]]; return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, d: Math.hypot(a.x - b.x, a.y - b.y) }; }

      stage.addEventListener('pointerdown', function (e) {
        if (!self.isOpen) return;
        if (e.target.closest('button, a, video, [data-viewer-done], [data-video-fallback]')) return;
        if (!r.menu.hidden) self.closeMenu();
        pointers[e.pointerId] = { x: e.clientX, y: e.clientY }; count = keys().length;
        try { stage.setPointerCapture(e.pointerId); } catch (err) {}
        var media = self._slide && self._slide._media;
        if (count === 2) {
          swipe = null; pan = null;
          var m = mid();
          pinch = { d0: m.d, s0: self._zoom.scale, x: m.x, y: m.y };
          if (media) media.classList.add('is-zooming');
          if (self._slide) self._slide.classList.remove('is-dragging');
          if (self._slide) self._slide.style.transform = '';
        } else if (self._zoom.scale > 1) {
          pan = { x: e.clientX, y: e.clientY, tx: self._zoom.tx, ty: self._zoom.ty };
          if (media) media.classList.add('is-zooming');
        } else {
          swipe = { x0: e.clientX, y0: e.clientY, t0: Date.now(), dx: 0, dy: 0, axis: null };
        }
        tapPos = { x: e.clientX, y: e.clientY, t: Date.now() };
      });

      stage.addEventListener('pointermove', function (e) {
        if (!pointers[e.pointerId]) return;
        pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
        if (pinch && count >= 2) {
          var m = mid();
          self._zoomAt(pinch.s0 * (m.d / (pinch.d0 || 1)), m.x, m.y);
          self._applyZoom(true);
          return;
        }
        if (pan) {
          self._zoom.tx = pan.tx + (e.clientX - pan.x);
          self._zoom.ty = pan.ty + (e.clientY - pan.y);
          self._applyZoom(true);
          return;
        }
        if (swipe && self._slide) {
          swipe.dx = e.clientX - swipe.x0; swipe.dy = e.clientY - swipe.y0;
          if (!swipe.axis && (Math.abs(swipe.dx) > 8 || Math.abs(swipe.dy) > 8)) swipe.axis = Math.abs(swipe.dx) >= Math.abs(swipe.dy) ? 'x' : 'y';
          if (!swipe.axis) return;
          self._slide.classList.add('is-dragging');
          if (swipe.axis === 'x') {
            var dx = swipe.dx;
            if ((dx < 0 && !self.hasNext()) || (dx > 0 && !self.hasPrev())) dx *= 0.32;   // rubber-band at the ends
            self._slide.style.transform = 'translateX(' + dx + 'px)';
          } else if (swipe.dy > 0) {
            self._slide.style.transform = 'translateY(' + swipe.dy * 0.85 + 'px) scale(' + (1 - Math.min(swipe.dy, 300) / 1500) + ')';
            self._slide.style.opacity = String(1 - Math.min(swipe.dy, 300) / 600);
          }
        }
      });

      function end(e) {
        if (!pointers[e.pointerId]) return;
        delete pointers[e.pointerId]; count = keys().length;
        try { stage.releasePointerCapture(e.pointerId); } catch (err) {}
        var media = self._slide && self._slide._media;
        if (pinch) {
          if (count < 2) {
            pinch = null;
            if (media) media.classList.remove('is-zooming');
            if (self._zoom.scale < 1.05) self._resetZoom(true); else { self._clampPan(); self._applyZoom(false); }
          }
          return;
        }
        if (pan) { pan = null; if (media) media.classList.remove('is-zooming'); self._clampPan(); self._applyZoom(false); return; }
        if (swipe) {
          var s = swipe; swipe = null;
          var slide = self._slide;
          if (!slide) return;
          var dt = Math.max(1, Date.now() - s.t0), vx = s.dx / dt, moved = Math.abs(s.dx) > 6 || Math.abs(s.dy) > 6;
          if (s.axis === 'x' && ((s.dx < -60 || vx < -0.45) && self.hasNext())) { self.next(); return; }
          if (s.axis === 'x' && ((s.dx > 60 || vx > 0.45) && self.hasPrev())) { self.prev(); return; }
          if (s.axis === 'y' && s.dy > 140) { self.close(); return; }
          slide.classList.remove('is-dragging');
          slide.style.transform = ''; slide.style.opacity = '';
          // double-tap to zoom (images only)
          if (!moved && tapPos && media && media.tagName === 'IMG') {
            var now = Date.now();
            if (now - lastTap < 320) {
              lastTap = 0;
              if (self._zoom.scale > 1) self._resetZoom(true); else self._zoomAt(2.5, e.clientX, e.clientY);
            } else lastTap = now;
          }
        }
      }
      stage.addEventListener('pointerup', end);
      stage.addEventListener('pointercancel', end);

      // Trackpad / mouse wheel with ctrl (browser pinch) zooms; plain horizontal wheel navigates.
      stage.addEventListener('wheel', function (e) {
        if (!self.isOpen) return;
        if (e.ctrlKey) { e.preventDefault(); self._zoomAt(self._zoom.scale * (e.deltaY < 0 ? 1.12 : 0.9), e.clientX, e.clientY); }
      }, { passive: false });
    }
  };

  /* ================================================================ */
  /* Assets page controller                                            */
  /* ================================================================ */
  var assets = App.assets = {
    cfg: {}, grid: null, selecting: false, _busyBatch: false,

    init: function () {
      this.cfg = window.AssetsPage || {};
      this.grid = $('#assetsGrid');
      var self = this, cfg = this.cfg;
      if (cfg.notice) toast(cfg.notice);
      if (!this.grid) return;

      // Tap → viewer (or toggle selection in select mode)
      this.grid.addEventListener('click', function (e) {
        var tile = e.target.closest('[data-asset]');
        if (!tile || !self.grid.contains(tile)) return;
        e.preventDefault();
        if (self.selecting) self.toggleTile(tile); else self.openAt(tile);
      });

      // Viewer decisions → tiles, counts, removal
      document.addEventListener('viewer:decision', function (e) { self.onDecision(e.detail); });
      document.addEventListener('viewer:replaced', function (e) {
        var tile = e.detail.item.tile || self.findTile(e.detail.item.kind, e.detail.item.id);
        var img = tile && tile.querySelector('img'); if (img) img.src = e.detail.src;
      });

      // Select mode (batch Approve only — denials always need a note)
      var selBtn = $('[data-assets-select]');
      if (selBtn) selBtn.addEventListener('click', function () { self.setSelecting(!self.selecting); });
      var approveBtn = $('[data-select-approve]');
      if (approveBtn) approveBtn.addEventListener('click', function () { self.approveSelected(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && self.selecting && !viewer.isOpen) self.setSelecting(false); });

      // Video tiles: posters + duration badges are filled by App.video (video.js) from its probe/cache.

      // Deep link: ?asset=&kind= (resolved server-side into cfg.open) or the legacy #lib-<id> / #image-<id> anchors
      var open = cfg.open;
      if (!open && location.hash) {
        var m = /^#(lib|image)-(\d+)$/.exec(location.hash);
        if (m) open = { kind: m[1] === 'lib' ? 'library' : 'tire', id: parseInt(m[2], 10) };
      }
      if (open) {
        var tile = this.findTile(open.kind, open.id);
        if (tile) setTimeout(function () { self.openAt(tile); }, 60);
        else if (cfg.open) toast('That image is not in this list.');
      }
    },

    tiles: function () { return $$('[data-asset]', this.grid); },
    findTile: function (kind, id) { return this.grid ? $('[data-asset][data-kind="' + kind + '"][data-id="' + id + '"]', this.grid) : null; },
    tileToItem: function (tile) {
      var d = tile.dataset;
      return { id: parseInt(d.id, 10), kind: d.kind, status: d.status, src: d.src, type: d.type || 'image', mime: d.mime || '',
               label: d.label || '', download: d.download || '', endpoint: d.endpoint, manage: d.manage || '', twin: d.twin || '', tile: tile };
    },
    openAt: function (tile) {
      var self = this, items = this.tiles().map(function (t) { return self.tileToItem(t); });
      var idx = items.findIndex(function (it) { return it.tile === tile; });
      viewer.open(items, idx < 0 ? 0 : idx, { mode: this.cfg.mode || 'review' });
    },

    /* ---------------- tile state ---------------- */
    applyStatus: function (tile, status) {
      tile.dataset.status = status;
      tile.classList.toggle('ui-thumb--approved', status === 'approved');
      var dot = tile.querySelector('[data-status-dot]');
      if (dot) dot.className = 'ui-dot ui-dot--' + status;
    },
    shouldLeave: function (status) {
      var filter = this.grid.dataset.filter;
      if (status === 'denied') return filter !== 'denied';      // gone from the client's view on the spot
      if (status === 'approved') return filter === 'denied';    // admin re-approving from "Needs changes"
      return false;
    },
    onDecision: function (d) {
      var tile = d.item.tile || this.findTile(d.item.kind, d.item.id);
      if (!tile) return;
      if (d.rolledBack) { this.restoreTile(tile, d.status); this.adjustCounts(d.prev, d.status); return; }
      if (d.optimistic) {
        this.applyStatus(tile, d.status);
        this.adjustCounts(d.prev, d.status);
        if (this.shouldLeave(d.status)) this.leaveTile(tile);
      }
    },

    /** Scale .9 + fade (motion.css .ui-leave, 220ms), then remove and FLIP the neighbours into place with a spring. */
    leaveTile: function (tile) {
      var self = this;
      if (!tile.isConnected || tile._leaving) return;
      tile._leaving = true;
      tile._restore = { parent: tile.parentNode, next: tile.nextElementSibling };
      tile.classList.add('ui-leave');
      var done = false;
      var finish = function () {
        if (done) return; done = true;
        if (!tile.isConnected || !tile._leaving) return;   // rolled back (restoreTile) before the animation ended
        var siblings = self.tiles().filter(function (t) { return t !== tile; });
        var before = siblings.map(function (t) { return t.getBoundingClientRect(); });
        tile.parentNode.removeChild(tile);
        tile._leaving = false;
        if (reduced()) return;
        siblings.forEach(function (t, i) {
          var a = t.getBoundingClientRect(), dx = before[i].left - a.left, dy = before[i].top - a.top;
          if (!dx && !dy) return;
          t.style.transition = 'none';
          t.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';
          t._flip = true;
        });
        void self.grid.offsetWidth;
        siblings.forEach(function (t) {
          if (!t._flip) return; t._flip = false;
          t.style.transition = '';
          t.classList.add('is-flipping');
          t.style.transform = '';
          var clear = function () { t.classList.remove('is-flipping'); t.removeEventListener('transitionend', clear); };
          t.addEventListener('transitionend', clear);
          setTimeout(clear, 500);
        });
        if (!self.tiles().length) self.showEmpty();
      };
      tile.addEventListener('animationend', finish, { once: true });
      setTimeout(finish, reduced() ? 200 : 320);
    },
    restoreTile: function (tile, status) {
      this.applyStatus(tile, status);
      tile.classList.remove('ui-leave'); tile._leaving = false;
      if (!tile.isConnected && tile._restore) {
        var p = tile._restore.parent, n = tile._restore.next;
        if (n && n.parentNode === p) p.insertBefore(tile, n); else p.appendChild(tile);
        tile.classList.add('ui-enter');
        var empty = $('[data-assets-empty]'); if (empty) empty.remove();
      }
    },
    showEmpty: function () {
      if ($('[data-assets-empty]')) return;
      var filter = this.grid.dataset.filter, el = document.createElement('div');
      el.className = 'ui-empty as-empty ui-enter'; el.setAttribute('data-assets-empty', '');
      el.innerHTML = filter === 'pending'
        ? '<p class="as-empty-title">All caught up</p><p>Nothing to review here right now.</p>'
        : (filter === 'approved' ? '<p>No approved images yet.</p>' : '<p>Nothing needs changes.</p>');
      this.grid.parentNode.insertBefore(el, this.grid.nextSibling);
    },

    /* ---------------- live counts (filter chips, segmented control, tab badge) ---------------- */
    adjustCounts: function (from, to) {
      if (from === to) return;
      var bump = function (sel, delta) {
        $$(sel).forEach(function (el) { el.textContent = String(Math.max(0, (parseInt(el.textContent, 10) || 0) + delta)); });
      };
      if (from) bump('[data-count="' + from + '"]', -1);
      if (to)   bump('[data-count="' + to + '"]', 1);
      var delta = (to === 'pending' ? 1 : 0) - (from === 'pending' ? 1 : 0);
      if (!delta) return;
      var seg = $('.ui-segmented-item.is-active .ui-segmented-count');
      if (seg) { var v = Math.max(0, (parseInt(seg.textContent, 10) || 0) + delta); if (v > 0) seg.textContent = String(v); else seg.remove(); }
      else if (delta > 0) { var act = $('.ui-segmented-item.is-active'); if (act) act.insertAdjacentHTML('beforeend', ' <span class="ui-segmented-count">1</span>'); }
      var tab = $('.ui-tab--assets');
      if (tab) {
        var badge = $('.ui-badge', tab), n = Math.max(0, (badge ? (parseInt(badge.textContent, 10) || 0) : 0) + delta);
        if (n > 0) { if (!badge) { badge = document.createElement('span'); badge.className = 'ui-badge'; tab.appendChild(badge); } badge.textContent = n > 99 ? '99+' : String(n); badge.setAttribute('aria-label', n + ' to review'); }
        else if (badge) badge.remove();
      }
    },

    /* ---------------- multi-select: batch Approve only ---------------- */
    setSelecting: function (on) {
      if (this._busyBatch) return;
      this.selecting = !!on;
      var grid = this.grid, bar = $('[data-assets-selectbar]'), btn = $('[data-assets-select]');
      grid.classList.toggle('is-selecting', this.selecting);
      if (btn) { btn.textContent = this.selecting ? 'Cancel' : 'Select'; btn.setAttribute('aria-pressed', this.selecting ? 'true' : 'false'); }
      if (!this.selecting) this.tiles().forEach(function (t) { t.removeAttribute('aria-pressed'); });
      if (bar) {
        if (this.selecting) { bar.hidden = false; requestAnimationFrame(function () { bar.classList.add('is-visible'); }); }
        else { bar.classList.remove('is-visible'); afterMs(230, function () { if (!assets.selecting) bar.hidden = true; }); }
      }
      this.updateSelection();
    },
    toggleTile: function (tile) {
      if (tile.dataset.status === 'approved') return;   // nothing to approve
      tile.setAttribute('aria-pressed', tile.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
      this.updateSelection();
    },
    selectedTiles: function () { return this.tiles().filter(function (t) { return t.getAttribute('aria-pressed') === 'true'; }); },
    updateSelection: function () {
      var n = this.selectedTiles().length, count = $('[data-select-count]'), btn = $('[data-select-approve]');
      if (count) count.textContent = n === 1 ? '1 selected' : n + ' selected';
      if (btn && !this._busyBatch) { btn.disabled = n === 0; btn.textContent = n > 0 ? 'Approve ' + n : 'Approve'; }
    },
    /** One request per item, sequentially, with progress; optimistic per tile with rollback. */
    approveSelected: function () {
      var self = this, tiles = this.selectedTiles(), btn = $('[data-select-approve]');
      if (!tiles.length || this._busyBatch) return;
      this._busyBatch = true;
      if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
      var i = 0, ok = 0, fail = 0;
      var step = function () {
        if (i >= tiles.length) { finish(); return; }
        var tile = tiles[i++], item = self.tileToItem(tile), prev = item.status;
        if (btn) btn.textContent = 'Approving ' + i + ' of ' + tiles.length + '…';
        tile.setAttribute('aria-pressed', 'false');
        tile.classList.add('is-queued');
        self.applyStatus(tile, 'approved');
        App.post(item.endpoint, { id: item.id, status: 'approved', actor: App.actor }).then(function (res) {
          tile.classList.remove('is-queued');
          if (res.ok) { ok++; self.adjustCounts(prev, 'approved'); if (self.shouldLeave('approved')) self.leaveTile(tile); }
          else { fail++; self.applyStatus(tile, prev); }
          step();
        });
      };
      var finish = function () {
        self._busyBatch = false;
        if (btn) btn.removeAttribute('aria-busy');
        toast(ok + ' approved' + (fail ? ' · ' + fail + ' failed' : ''), { kind: fail ? 'error' : 'success' });
        self.setSelecting(false);
      };
      step();
    }
  };

  /* ================================================================ */
  /* Boot — after app.js (defer order puts this file first)            */
  /* ================================================================ */
  function boot() {
    if (boot._done) return; boot._done = true;
    viewer.init();
    assets.init();
  }
  if (App._inited) boot();
  else document.addEventListener('app:ready', boot);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { if (App._inited) boot(); });
  else if (App._inited) boot();

})(window, document);
