/* =====================================================================
   Studio (admin only) — extends the Foundation `App` (app.js loads first).

   App.studio.picker(root)   Approved Pool picker: multi-select up to N,
                             drag-to-reorder strip (Pointer Events) + arrows,
                             hidden inputs assets[] = "kind:id" in order.
   App.studio.preview(form, previewEl, picker)
                             Live Instagram-style preview. Mirrors the markup
                             renderCaptionPreview()/renderPostMedia() emit
                             (partials/components/post-detail.php) so what the
                             admin sees is exactly what the client sees.
   App.studio.uploads(zone)  Uploads tab: every file goes to upload-chunk.php
                             (purpose=batch — one request when small, pieces
                             through App.chunkUpload when large) and its token
                             is posted to batch-process.php as claimed[] → one
                             draft post per file; .MOV shows the Safari-only
                             warning before upload. Compose (composer) and Batch
                             use the same UploadQueue: files go up as they are
                             picked (progress / Cancel / Remove, "Resume N
                             unfinished uploads" after a reload) and the form
                             submits claimed[] tokens; Publish waits for them.
   App.studio.renders(root)  Renders tab (tire series): tire + series pickers,
                             sequential queue to tire-upload.php — one request
                             per small file, files above the server's chunk_size
                             in pieces through App.chunkUpload (chunk-upload.js:
                             progress / speed / ETA, per-piece retry, Cancel,
                             "Resume N unfinished uploads" after a reload); one
                             batch id per drop, "New series…" created by the
                             first file; retry, rescan, and the series list
                             (rename / reorder / delete → tire-status.php).
   App.studio.batch(root)    Batch builder rows → batch-process.php.
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
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function toLocalIso(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  function typeLabel(t) { t = String(t || 'post'); return t.charAt(0).toUpperCase() + t.slice(1); }
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
    drive: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3.5h6l6.5 11.5-3 5.5H5.5l-3-5.5z"/><path d="M2.5 15h19M15 3.5 8.5 15"/></svg>'
  };

  /* ================================================================== */
  /* Approved Pool picker                                               */
  /* ================================================================== */
  function Picker(root) {
    this.root     = root;
    this.max      = parseInt(root.dataset.max, 10) || 10;
    this.name     = root.dataset.name != null ? root.dataset.name : 'assets[]';
    this.grid     = $('[data-pool-grid]', root);
    this.strip    = $('[data-pick-strip]', root);
    this.list     = $('[data-pick-list]', root);
    this.inputs   = $('[data-pick-inputs]', root);
    this.countEl  = $('[data-pick-count]', root);
    this.clearBtn = $('[data-pick-clear]', root);
    this.emptyEl  = $('[data-pool-empty]', root);
    this.selected = [];
    this.handlers = [];
    this.filter   = 'all';
    this.media    = 'all';      // 'all' | 'image' | 'video' (the Photos / Videos chips)
    this.videosShown = false;   // 'all' starts with the videos collapsed behind "Show N videos" (no posters fetched)
    this.mediaBar = $('[data-pool-media]', root);
    root._picker  = this;
    this.bind();
    var initial = [];
    try { initial = JSON.parse(root.dataset.selected || '[]'); } catch (e) { initial = []; }
    this.set(initial, true);
    this.render();
    if (this.mediaBar) this.applyVisibility();
  }

  Picker.prototype.assetFromButton = function (btn) {
    var ds = btn.dataset;
    return { key: ds.assetKey, kind: ds.assetKind, id: parseInt(ds.assetId, 10), src: ds.assetSrc,
             label: ds.assetLabel || '', media: ds.assetMedia || 'image', group: ds.assetGroup || '',
             groupLabel: ds.assetGroupLabel || '' };
  };
  Picker.prototype.button = function (key) { return this.grid ? $('[data-asset-key="' + key + '"]', this.grid) : null; };
  Picker.prototype.onChange = function (fn) { this.handlers.push(fn); return this; };
  Picker.prototype.emit = function () {
    var self = this;
    this.handlers.forEach(function (fn) { try { fn(self.selected.slice(), self); } catch (e) { if (window.console) console.error(e); } });
    this.root.dispatchEvent(new CustomEvent('picker:change', { bubbles: true, detail: { selection: this.selected.slice(), picker: this } }));
  };
  Picker.prototype.getSelection = function () { return this.selected.slice(); };
  Picker.prototype.setMax = function (n) {
    this.max = Math.max(0, n | 0);
    if (this.selected.length > this.max) { this.selected = this.selected.slice(0, this.max); this.emit(); }
    this.render();
  };
  Picker.prototype.set = function (keys, silent) {
    var self = this;
    this.selected = [];
    (keys || []).forEach(function (k) {
      var btn = self.button(k);
      if (btn && self.selected.length < self.max) self.selected.push(self.assetFromButton(btn));
    });
    if (!silent) { this.render(); this.emit(); }
  };
  Picker.prototype.index = function (key) {
    for (var i = 0; i < this.selected.length; i++) if (this.selected[i].key === key) return i;
    return -1;
  };
  Picker.prototype.toggle = function (key) {
    var i = this.index(key);
    if (i >= 0) { this.selected.splice(i, 1); }
    else {
      if (this.selected.length >= this.max) {
        toast(this.max > 0 ? 'Up to ' + this.max + ' media per post.' : 'No media slots left on this post.');
        return;
      }
      var btn = this.button(key);
      if (!btn) return;
      this.selected.push(this.assetFromButton(btn));
    }
    this.render(); this.emit();
  };
  Picker.prototype.remove = function (key) {
    var i = this.index(key);
    if (i < 0) return;
    this.selected.splice(i, 1);
    this.render(); this.emit();
  };
  Picker.prototype.move = function (from, to) {
    if (from === to || from < 0 || to < 0 || from >= this.selected.length || to >= this.selected.length) return;
    var item = this.selected.splice(from, 1)[0];
    this.selected.splice(to, 0, item);
    this.render(); this.emit();
  };
  Picker.prototype.clear = function () { this.selected = []; this.render(); this.emit(); };

  Picker.prototype.render = function () {
    var self = this, n = this.selected.length, full = n >= this.max;
    if (this.grid) {
      $$('[data-asset-key]', this.grid).forEach(function (btn) {
        var idx = self.index(btn.dataset.assetKey), on = idx >= 0;
        btn.classList.toggle('is-selected', on);
        btn.classList.toggle('ui-thumb--selected', on);
        btn.classList.toggle('is-disabled', !on && full);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
        var badge = $('[data-asset-order]', btn);
        if (badge) badge.textContent = on ? String(idx + 1) : '';
      });
    }
    if (this.countEl) this.countEl.textContent = String(n);
    if (this.clearBtn) this.clearBtn.hidden = n === 0;
    if (this.strip) this.strip.hidden = n === 0;
    if (this.list) {
      this.list.innerHTML = this.selected.map(function (a, i) {
        var media = a.media === 'video'
          ? '<video src="' + esc(a.src) + '" muted playsinline preload="metadata"></video>'
          : '<img src="' + esc(a.src) + '" alt="" draggable="false">';
        return '<li class="studio-strip-item" data-strip-key="' + esc(a.key) + '" data-index="' + i + '">'
          + '<div class="ui-thumb" data-strip-handle>' + media + '<span class="studio-strip-num">' + (i + 1) + '</span></div>'
          + '<div class="studio-strip-ctl">'
          + '<button type="button" data-strip-up aria-label="Move earlier"' + (i === 0 ? ' disabled' : '') + '>' + ICON.left + '</button>'
          + '<button type="button" data-strip-remove aria-label="Remove ' + esc(a.label) + '">' + ICON.x + '</button>'
          + '<button type="button" data-strip-down aria-label="Move later"' + (i === n - 1 ? ' disabled' : '') + '>' + ICON.right + '</button>'
          + '</div>'
          + '<div class="studio-strip-label" title="' + esc(a.label + ' — ' + a.groupLabel) + '">' + esc(a.label) + '</div>'
          + '</li>';
      }).join('');
    }
    if (this.inputs) {
      this.inputs.innerHTML = this.name
        ? this.selected.map(function (a) { return '<input type="hidden" name="' + esc(self.name) + '" value="' + esc(a.key) + '">'; }).join('')
        : '';
    }
  };

  /* Pool filter = one collection (or Library / All) via the top chips; inside a tire group the
     series chips (data-series-filter, per group) narrow it further. Both are client-side only. */
  Picker.prototype.applyFilter = function (value) {
    this.filter = value || 'all';
    var self = this;
    $$('[data-pool-filter]', this.root).forEach(function (chip) {
      var on = chip.dataset.poolFilter === self.filter;
      chip.classList.toggle('is-active', on);
      chip.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    this.applyVisibility();
  };
  Picker.prototype.applySeriesFilter = function (group, value) {
    group.dataset.seriesActive = value || 'all';
    $$('[data-series-filter]', group).forEach(function (chip) {
      var on = chip.dataset.seriesFilter === group.dataset.seriesActive;
      chip.classList.toggle('is-active', on);
      chip.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    this.applyVisibility();
  };
  /* Photos / Videos chips: 'image' | 'video' narrows to that type (the active chip again → both);
     the "Show N videos" toggle reveals the collapsed videos while both types are shown. A video tile that
     becomes visible for the first time is woken (App.video.wake → its poster / duration may be probed now). */
  Picker.prototype.applyMediaFilter = function (value) {
    this.media = value === 'image' || value === 'video' ? value : 'all';
    var self = this;
    $$('[data-media-filter]', this.root).forEach(function (chip) {
      var on = chip.dataset.mediaFilter === self.media;
      chip.classList.toggle('is-active', on);
      chip.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    this.applyVisibility();
  };
  Picker.prototype.toggleVideos = function (show) {
    this.videosShown = typeof show === 'boolean' ? show : !this.videosShown;
    this.applyVisibility();
  };
  Picker.prototype.applyVisibility = function () {
    var self = this, visible = 0, hiddenVideos = 0;
    var showVideos = this.media === 'video' || this.videosShown;
    if (this.grid) {
      $$('[data-asset-key]', this.grid).forEach(function (btn) {
        var group = btn.closest('[data-pool-group]');
        var series = group ? (group.dataset.seriesActive || 'all') : 'all';
        var media = btn.dataset.assetMedia || 'image';
        var show = (self.filter === 'all' || btn.dataset.assetGroup === self.filter)
                && (series === 'all' || (btn.dataset.series || 'ref') === series)
                && (self.media === 'all' || media === self.media);
        if (show && media === 'video' && !showVideos && self.index(btn.dataset.assetKey) < 0) { show = false; hiddenVideos++; }   // collapsed (a picked one stays)
        btn.hidden = !show;
        if (show) {
          visible++;
          if (media === 'video' && btn.hasAttribute('data-pool-collapsed')) {
            btn.removeAttribute('data-pool-collapsed');
            if (window.App && App.video && App.video.wake) $$('[data-video-noprobe]', btn).forEach(App.video.wake);
          }
        }
      });
      $$('[data-pool-group]', this.grid).forEach(function (group) {
        group.hidden = $$('[data-asset-key]:not([hidden])', group).length === 0;
      });
    }
    var toggle = $('[data-pool-videos-toggle]', this.root);
    if (toggle) {
      var n = parseInt(toggle.dataset.count, 10) || 0;
      toggle.hidden = this.media !== 'all' || (!this.videosShown && hiddenVideos === 0);
      toggle.setAttribute('aria-pressed', this.videosShown ? 'true' : 'false');
      toggle.textContent = this.videosShown ? 'Hide videos' : 'Show ' + (hiddenVideos || n) + (hiddenVideos === 1 || (!hiddenVideos && n === 1) ? ' video' : ' videos');
    }
    if (this.emptyEl) this.emptyEl.hidden = visible > 0;
  };

  Picker.prototype.bind = function () {
    var self = this;
    this.root.addEventListener('click', function (e) {
      var chip = e.target.closest('[data-pool-filter]');
      if (chip) { self.applyFilter(chip.dataset.poolFilter); return; }
      var mchip = e.target.closest('[data-media-filter]');
      if (mchip) { self.applyMediaFilter(self.media === mchip.dataset.mediaFilter ? 'all' : mchip.dataset.mediaFilter); return; }
      if (e.target.closest('[data-pool-videos-toggle]')) { self.toggleVideos(); return; }
      var schip = e.target.closest('[data-series-filter]');
      if (schip) { var g = schip.closest('[data-pool-group]'); if (g) self.applySeriesFilter(g, schip.dataset.seriesFilter); return; }
      var btn = e.target.closest('[data-asset-key]');
      if (btn && self.grid && self.grid.contains(btn)) { e.preventDefault(); self.toggle(btn.dataset.assetKey); return; }
      if (e.target.closest('[data-pick-clear]')) { self.clear(); return; }
      var item = e.target.closest('[data-strip-key]');
      if (!item) return;
      var idx = self.index(item.dataset.stripKey);
      if (e.target.closest('[data-strip-remove]')) { self.remove(item.dataset.stripKey); }
      else if (e.target.closest('[data-strip-up]'))   { self.move(idx, idx - 1); focusStrip(self, idx - 1, '[data-strip-up]'); }
      else if (e.target.closest('[data-strip-down]')) { self.move(idx, idx + 1); focusStrip(self, idx + 1, '[data-strip-down]'); }
    });
    // Keyboard on the grid: Enter/Space toggles (buttons do this natively); arrows move focus.
    this.root.addEventListener('keydown', function (e) {
      var btn = e.target.closest && e.target.closest('[data-asset-key]');
      if (!btn || ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) === -1) return;
      var items = $$('[data-asset-key]:not([hidden])', self.grid), i = items.indexOf(btn);
      if (i < 0) return;
      var cols = Math.max(1, Math.round(self.grid.clientWidth / (btn.offsetWidth + 8)));
      var next = { ArrowLeft: i - 1, ArrowRight: i + 1, ArrowUp: i - cols, ArrowDown: i + cols }[e.key];
      if (items[next]) { e.preventDefault(); items[next].focus(); }
    });
    if (this.list) this.bindDrag();
  };
  function focusStrip(picker, idx, sel) {
    var item = picker.list && picker.list.children[idx];
    var btn = item && $(sel, item);
    if (btn && !btn.disabled) btn.focus(); else if (item) { var alt = $('[data-strip-remove]', item); if (alt) alt.focus(); }
  }

  /* Drag to reorder — Pointer Events, transform only, interruptible. */
  Picker.prototype.bindDrag = function () {
    var self = this, list = this.list, drag = null;
    list.addEventListener('pointerdown', function (e) {
      var handle = e.target.closest('[data-strip-handle]');
      var item = e.target.closest('[data-strip-key]');
      if (!handle || !item || (e.pointerType === 'mouse' && e.button !== 0)) return;
      e.preventDefault();
      var rects = $$('.studio-strip-item', list).map(function (el) { return el.getBoundingClientRect(); });
      drag = { item: item, from: parseInt(item.dataset.index, 10), to: parseInt(item.dataset.index, 10),
               startX: e.clientX, startY: e.clientY, rects: rects, id: e.pointerId, moved: false };
      item.classList.add('is-dragging');
      try { item.setPointerCapture(e.pointerId); } catch (err) {}
    });
    list.addEventListener('pointermove', function (e) {
      if (!drag || e.pointerId !== drag.id) return;
      var dx = e.clientX - drag.startX, dy = e.clientY - drag.startY;
      if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 4) return;
      drag.moved = true;
      var thumb = $('[data-strip-handle]', drag.item);
      if (thumb) thumb.style.transform = 'translate(' + dx + 'px,' + dy + 'px) scale(1.06)';
      // Target index = the slot whose centre the pointer has crossed.
      var to = drag.from;
      for (var i = 0; i < drag.rects.length; i++) {
        var r = drag.rects[i], cx = r.left + r.width / 2;
        if (i < drag.from && e.clientX < cx) { to = i; break; }
        if (i > drag.from && e.clientX > cx) { to = i; }
      }
      drag.to = to;
      $$('.studio-strip-item', list).forEach(function (el, i) {
        el.classList.toggle('is-shift-right', i >= to && i < drag.from);
        el.classList.toggle('is-shift-left', i <= to && i > drag.from);
      });
    });
    function end(e) {
      if (!drag || e.pointerId !== drag.id) return;
      var thumb = $('[data-strip-handle]', drag.item);
      if (thumb) thumb.style.transform = '';
      drag.item.classList.remove('is-dragging');
      $$('.studio-strip-item', list).forEach(function (el) { el.classList.remove('is-shift-left', 'is-shift-right'); });
      var from = drag.from, to = drag.to, moved = drag.moved;
      drag = null;
      if (moved && e.type !== 'pointercancel' && from !== to) self.move(from, to);
    }
    list.addEventListener('pointerup', end);
    list.addEventListener('pointercancel', end);
  };

  /* ================================================================== */
  /* Live preview — mirrors post-detail.php markup                      */
  /* ================================================================== */
  function Preview(form, root, picker) {
    this.form = form; this.root = root; this.picker = picker;
    this.brand = { name: root.dataset.brandName || (cfg.brand && cfg.brand.name) || '', logo: root.dataset.brandLogo || '' };
    this.captionEl = $('.ig-caption', root);
    this.tagsEl    = $('.ig-tags', root);
    this.mediaEl   = $('[data-preview-media]', root);
    this.dateEl    = $('[data-preview-date]', root);
    this.typeEl    = $('[data-preview-type]', root);
    this.statusEl  = $('[data-preview-status]', root);
    this.files     = [];      // local one-off files [{file, url, media}]
    this.existing  = $$('[data-existing-item]', form).map(function (el) {
      return { el: el, src: el.dataset.src, media: el.dataset.media || 'image' };
    });
    var self = this;
    form.addEventListener('input',  function (e) { if (e.target.matches('[data-field]')) self.update(); });
    form.addEventListener('change', function (e) { if (e.target.matches('[data-field], [data-remove-image]')) self.update(); });
    if (picker) picker.onChange(function () { self.update(); });
    this.update();
  }
  Preview.prototype.setFiles = function (files) {
    this.files.forEach(function (f) { if (f.url) URL.revokeObjectURL(f.url); });
    this.files = files.map(function (file) {
      var vid = isVideoFile(file);
      // A local video is never decoded for the preview (it may be gigabytes): a poster-less tile with name + size.
      return { file: file, url: vid ? '' : URL.createObjectURL(file), media: vid ? 'video' : 'image', type: file.type || '', local: true, name: file.name, size: file.size };
    });
    this.update();
  };
  Preview.prototype.value = function (name) {
    var el = $('[data-field="' + name + '"]', this.form);
    return el ? el.value : '';
  };
  Preview.prototype.items = function () {
    var out = [];
    this.existing.forEach(function (x) {
      var cb = $('[data-remove-image]', x.el);
      if (cb && cb.checked) return;
      out.push({ src: x.src, media: x.media });
    });
    if (this.picker) this.picker.getSelection().forEach(function (a) { out.push({ src: a.src, media: a.media }); });
    this.files.forEach(function (f) { out.push({ src: f.url, media: f.media, type: f.type, local: f.local, name: f.name, size: f.size }); });
    return out.slice(0, 10);
  };
  Preview.prototype.update = function () {
    var name = this.brand.name, caption = this.value('caption'), tags = (this.value('hashtags') || '').trim();
    if (this.captionEl) {
      this.captionEl.innerHTML = '<span class="ig-name ig-name--inline">' + esc(name) + '</span> ' + linkTags(caption);
      this.captionEl.setAttribute('data-raw', caption);
    }
    if (this.tagsEl) {
      this.tagsEl.innerHTML = linkTags(tags);
      this.tagsEl.setAttribute('data-raw', tags);
      this.tagsEl.hidden = tags === '';
    }
    if (this.dateEl) this.dateEl.textContent = formatWhen(this.value('scheduled_date'));
    if (this.typeEl) this.typeEl.textContent = typeLabel(this.value('post_type') || 'post');
    if (this.statusEl && App.status && App.status.applyPill) App.status.applyPill(this.statusEl, this.value('status') || 'pending', false);
    this.renderMedia(this.items());
  };
  Preview.prototype.renderMedia = function (items) {
    if (!this.mediaEl) return;
    var n = items.length;
    if (!n) {
      this.mediaEl.innerHTML = '<div class="pd-media pd-media--empty"><span class="text-tertiary">No media yet</span></div>';
      return;
    }
    var label = esc(this.brand.name + ' post');
    var html = '<div class="pd-media" data-carousel data-count="' + n + '" aria-roledescription="carousel" aria-label="' + label + '"><div class="pd-track" data-carousel-track>';
    items.forEach(function (it, i) {
      var isVid = it.media === 'video';
      html += '<figure class="pd-slide" data-slide="' + i + '" data-media-type="' + (isVid ? 'video' : 'image') + '" data-src="' + esc(it.src) + '">';
      if (isVid && it.local) {
        html += '<div class="pd-video pd-video--local" role="img" aria-label="' + esc(it.name || 'video') + '">' + ICON.play
              + '<span class="pd-video-local-name">' + esc(it.name || 'video') + '</span><span class="pd-video-local-size">' + esc(mb(it.size || 0)) + ' · uploads in pieces</span></div>';
      } else if (isVid) {
        // spec §6 markup via App.video (the JS twin of renderVideoElement()); blob: previews keep their File type
        var type = it.type || (fileExt(it.src) === 'webm' ? 'video/webm' : (fileExt(it.src) === 'mov' ? 'video/quicktime' : 'video/mp4'));
        html += App.video
          ? App.video.markup(it.src, { mime: type, cls: 'pd-video', autoplay: i === 0, unmute: true })
          : '<video playsinline muted controls preload="metadata"><source src="' + esc(it.src) + '" type="' + esc(type) + '"></video>';
      } else {
        html += '<img src="' + esc(it.src) + '" alt="' + label + ' ' + (i + 1) + '" loading="' + (i === 0 ? 'eager' : 'lazy') + '" decoding="async">';
      }
      html += '</figure>';
    });
    html += '</div>';
    if (n > 1) {
      html += '<div class="pd-dots" role="tablist" aria-label="Slides">';
      for (var d = 0; d < n; d++) html += '<button type="button" class="pd-dot' + (d === 0 ? ' is-active' : '') + '" data-carousel-dot="' + d + '" role="tab" aria-selected="' + (d === 0 ? 'true' : 'false') + '" aria-label="Slide ' + (d + 1) + '"></button>';
      html += '</div><span class="ui-pill ui-pill--glass ui-pill--nodot pd-counter" data-carousel-counter>1/' + n + '</span>';
    }
    html += '</div>';
    this.mediaEl.innerHTML = html;
    bindCarousel($('[data-carousel]', this.mediaEl));
  };
  function bindCarousel(root) {
    if (!root) return;
    var track = $('[data-carousel-track]', root), dots = $$('[data-carousel-dot]', root), counter = $('[data-carousel-counter]', root);
    var n = parseInt(root.dataset.count, 10) || 1, ticking = false;
    function sync() {
      var i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
      dots.forEach(function (d, k) { d.classList.toggle('is-active', k === i); d.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
      if (counter) counter.textContent = (i + 1) + '/' + n;
      ticking = false;
    }
    track.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(sync); } }, { passive: true });
    dots.forEach(function (d, k) { d.addEventListener('click', function () { track.scrollTo({ left: k * track.clientWidth, behavior: App.reducedMotion && App.reducedMotion() ? 'auto' : 'smooth' }); }); });
    if (App.video) App.video.enhance(root);   // fallback card / unmute pill / posters (spec §6)
  }

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
  function assignFiles(input, files) {
    try {
      var dt = new DataTransfer();
      files.forEach(function (f) { dt.items.add(f); });
      input.files = dt.files;
      return true;
    } catch (e) { return false; }
  }
  function fileRowHtml(file, note) {
    var url = URL.createObjectURL(file), vid = isVideoFile(file);
    var meta = note || (isQuickTime(file) ? mb(file.size) + ' · ' + MOV_NOTE : mb(file.size));
    return '<li data-file-name="' + esc(file.name) + '">'
      + (vid ? '<video class="studio-file-thumb" src="' + esc(url) + '" muted playsinline preload="metadata"></video>' : '<img class="studio-file-thumb" src="' + esc(url) + '" alt="">')
      + '<span class="studio-file-name">' + esc(file.name) + '</span>'
      + '<span class="studio-file-meta' + (note ? ' is-rejected' : '') + '">' + esc(meta) + '</span>'
      + '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-file-remove aria-label="Remove ' + esc(file.name) + '">' + ICON.x + '</button>'
      + '</li>';
  }
  // spec §6: .MOV is accepted and kept as-is — Safari plays it inline, other browsers get the download card.
  var MOV_NOTE = '.MOV — plays in Safari; Chrome and Firefox get an Open / Download card';
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
  /* Composer                                                           */
  /* ================================================================== */
  function Composer(form) {
    var self = this;
    this.form = form;
    this.max = parseInt(form.dataset.max, 10) || 10;
    this.slots = parseInt(form.dataset.slots, 10);
    if (isNaN(this.slots)) this.slots = this.max;
    var pickerRoot = $('[data-picker]', form);
    this.picker  = pickerRoot ? (pickerRoot._picker || new Picker(pickerRoot)) : null;
    var previewRoot = $('[data-preview]', form);
    this.preview = previewRoot ? new Preview(form, previewRoot, this.picker) : null;
    this.files   = [];
    this.fileInput = $('[data-composer-files]', form);
    this.fileList  = $('[data-composer-filelist]', form);
    this.submitBtn = $('[data-composer-submit]', form);
    var upRoot = $('[data-composer-uploads]', form);
    this.maxMb = { image: parseInt(upRoot && upRoot.dataset.maxImageMb, 10) || parseInt(cfg.maxImageMb, 10) || 50,
                   video: parseInt(upRoot && upRoot.dataset.maxVideoMb, 10) || parseInt(cfg.maxVideoMb, 10) || 4096 };
    // With chunk-upload.js the picked files go up right away (upload-chunk.php purpose=post) and the form submits
    // claimed[] tokens; without it (legacy) they stay in the images[] input and travel with the form.
    var tpl = $('[data-composer-item-template]', form);
    this.queue = (App.chunkUpload && App.chunkUpload.upload && tpl && this.fileList) ? new UploadQueue({
      endpoint: cfg.upload, purpose: 'post', kind: 'post', list: this.fileList, tpl: tpl, maxMb: this.maxMb, maxFiles: this.max, cancelSel: '[data-composer-cancel]',
      label: 'Compose',
      resume: { box: $('[data-composer-resume]', form), text: $('[data-composer-resume-text]', form), input: $('[data-composer-resume-input]', form), discard: $('[data-composer-resume-discard]', form) },
      onDone: function (job, data) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'claimed[]'; inp.value = data.token || '';
        job.item.appendChild(inp);
        var status = $('[data-upload-status]', job.item);
        var label = self.submitBtn ? (self.submitBtn.dataset.label || self.submitBtn.textContent) : 'save';   // the button may read "Uploading…" right now
        if (status) status.textContent = 'Uploaded — added when you ' + label.trim().toLowerCase().replace(/…$/, '');
      },
      onChange: function () { self.syncFiles(); }
    }) : null;

    var drop = $('[data-file-drop]', form);
    if (drop && this.fileInput) {
      bindDrop(drop, this.fileInput, function (files, dropped) {
        if (self.queue) {
          self.fileInput.value = '';   // the queue owns them now: nothing may travel with the form as images[]
          files.forEach(function (f) { if (isQuickTime(f)) toast(f.name + ': .MOV plays inline in Safari; Chrome and Firefox will show an Open / Download card instead.', { duration: 6000 }); self.queue.add(f); });
          return;
        }
        if (dropped) { self.files = self.files.concat(files); assignFiles(self.fileInput, self.files); }
        else { self.files = files; }
        self.syncFiles();
      });
    }
    form.addEventListener('click', function (e) {
      var cancel = e.target.closest('[data-composer-cancel]');
      if (cancel && self.queue) { var ci = cancel.closest('[data-composer-item]'); if (ci && ci._job) self.queue.remove(ci._job); return; }
      var rm = e.target.closest('[data-file-remove]');
      if (rm) {
        var li = rm.closest('[data-file-name]');
        if (self.queue) { if (li && li._job) self.queue.remove(li._job); return; }
        var name = li.dataset.fileName;
        self.files = self.files.filter(function (f) { return f.name !== name; });
        if (!assignFiles(self.fileInput, self.files)) self.fileInput.value = '';
        self.syncFiles();
        return;
      }
      var apply = e.target.closest('[data-apply-defaults]');
      if (apply) { self.applyDefaults(apply.dataset.defaults || ''); return; }
    });
    form.addEventListener('change', function (e) {
      var chip = e.target.closest('[data-cat-chip]');
      if (chip) chip.classList.toggle('is-active', e.target.checked);
      var rm = e.target.closest('[data-remove-image]');
      if (rm) { rm.closest('[data-existing-item]').classList.toggle('is-marked', rm.checked); self.syncSlots(); }
    });
    if (this.picker) this.picker.onChange(function () { self.syncSlots(); });
    form.addEventListener('submit', function (e) { self.onSubmit(e); });
    this.syncSlots();
  }
  Composer.prototype.keptExisting = function () {
    return $$('[data-existing-item]', this.form).filter(function (el) { var cb = $('[data-remove-image]', el); return !(cb && cb.checked); }).length;
  };
  /** The one-off files that count: queue rows not failed / removed (chunk mode), else the accepted picks in the input. */
  Composer.prototype.validFiles = function () {
    if (this.queue) return this.queue.active().map(function (j) { return j.file; });
    var m = this.maxMb; return this.files.filter(function (f) { return !fileNote(f, m); });
  };
  Composer.prototype.uploading = function () { return !!(this.queue && this.queue.inFlight()); };
  Composer.prototype.syncSlots = function () {
    var free = Math.max(0, this.max - this.keptExisting() - this.validFiles().length);
    if (this.picker) this.picker.setMax(free);
  };
  /** Publish waits for the uploads: disabled + "Uploading…" while a file is still going up. */
  Composer.prototype.syncSubmit = function () {
    var btn = this.submitBtn; if (!btn) return;
    if (!btn.dataset.label) btn.dataset.label = btn.textContent;
    var busy = this.uploading();
    btn.disabled = busy;
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    btn.textContent = busy ? 'Uploading…' : btn.dataset.label;
  };
  Composer.prototype.syncFiles = function () {
    var self = this;
    if (!this.queue) {
      if (this.fileList) this.fileList.innerHTML = this.files.map(function (f) { return fileRowHtml(f, fileNote(f, self.maxMb)); }).join('');
      this.files.forEach(function (f) { if (isQuickTime(f)) toast(f.name + ': .MOV plays inline in Safari; Chrome and Firefox will show an Open / Download card instead.', { duration: 6000 }); });
    }
    if (this.preview) this.preview.setFiles(this.validFiles());
    this.syncSlots();
    this.syncSubmit();
  };
  Composer.prototype.applyDefaults = function (defaults) {
    var ta = $('[data-field="hashtags"]', this.form);
    if (!ta || !defaults) return;
    var have = ta.value.trim(), norm = function (s) { return s.toLowerCase(); };
    var haveTags = {}; have.split(/\s+/).filter(Boolean).forEach(function (t) { haveTags[norm(t)] = 1; });
    var add = defaults.split(/\s+/).filter(function (t) { return t && !haveTags[norm(t)]; });
    if (!add.length) { toast('Client defaults are already there.'); return; }
    ta.value = (have ? have + ' ' : '') + add.join(' ');
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.focus();
  };
  Composer.prototype.onSubmit = function (e) {
    if (this.uploading()) {
      e.preventDefault();
      toast('Wait for the uploads to finish (or cancel them) before saving.', { kind: 'error' });
      return;
    }
    var picks = this.picker ? this.picker.getSelection().length : 0;
    var total = this.keptExisting() + picks + this.validFiles().length;
    if (total > this.max) {
      e.preventDefault();
      toast('Up to ' + this.max + ' media per post — remove ' + (total - this.max) + '.', { kind: 'error' });
      return;
    }
    if (this.queue && this.fileInput) this.fileInput.value = '';   // belt and braces: only claimed[] tokens travel
    var btn = $('[data-composer-submit]', this.form);
    if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Saving…'; }
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
  /** The token is in: one pending post for it (batch-process.php keeps its per-row contract: created[0] / errors[0]). */
  Uploads.prototype.createPost = function (job, data) {
    var status = $('[data-upload-status]', job.item), prog = $('[data-upload-progress]', job.item);
    status.textContent = 'Creating the draft post…';
    App.post(this.endpoint, { 'claimed[]': data.token, client: cfg.client || '' }).then(function (res) {
      var d = res.data || {}, created = d.created && d.created[0], err = null;
      if (!res.ok || d.ok === false) err = res.error || d.error || 'Request failed';
      else if (!created) err = (d.errors && d.errors[0]) || 'Not accepted';
      if (err) { status.textContent = err; status.classList.remove('is-ok'); status.classList.add('is-error'); if (prog) prog.hidden = true; return; }
      var when = created.date ? formatWhen(String(created.date).replace(' ', 'T')) : '';
      status.innerHTML = 'Draft post #' + esc(created.post_id) + (when ? ' · ' + esc(when) : '')
        + ' — <a href="' + esc(postUrl(cfg, created.post_id)) + '">finish it in Posts</a>';
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
  /* Batch builder                                                      */
  /* ================================================================== */
  function Batch(root) {
    var self = this;
    this.root = root;
    this.maxRows = parseInt(root.dataset.maxRows, 10) || 20;
    var pickerRoot = $('[data-picker]', root);
    this.picker = pickerRoot ? (pickerRoot._picker || new Picker(pickerRoot)) : null;
    this.rowsEl = $('[data-batch-rows]', root);
    this.tpl = $('[data-batch-row-template]', root);
    this.emptyEl = $('[data-batch-empty]', root);
    this.countEl = $('[data-batch-count]', root);
    this.submitBtn = $('[data-batch-submit]', root);
    this.addRowBtn = $('[data-batch-add-row]', root);
    this.addEachBtn = $('[data-batch-add-each]', root);
    this.spacingEl = $('[data-batch-spacing]', root);
    this.fileInput = $('[data-batch-files]', root);
    this.fileList = $('[data-batch-filelist]', root);
    this.rows = []; this.files = [];
    this.maxFiles = parseInt(root.dataset.maxFiles, 10) || parseInt(cfg.maxBatchFiles, 10) || 50;
    this.maxMb = { image: parseInt(cfg.maxImageMb, 10) || 50, video: parseInt(cfg.maxVideoMb, 10) || 4096 };
    // Direct files go up as they are picked (upload-chunk.php purpose=batch — pieces when large) and the
    // submit posts their claimed[] tokens; without chunk-upload.js they travel with the request as images[].
    var tpl = $('[data-batch-item-template]', root);
    this.queue = (App.chunkUpload && App.chunkUpload.upload && tpl && this.fileList) ? new UploadQueue({
      endpoint: root.dataset.uploadEndpoint || cfg.upload, purpose: 'batch', kind: 'batch', list: this.fileList, tpl: tpl, maxMb: this.maxMb, maxFiles: this.maxFiles, cancelSel: '[data-batch-cancel]',
      label: 'Batch',
      resume: { box: $('[data-batch-resume]', root), text: $('[data-batch-resume-text]', root), input: $('[data-batch-resume-input]', root), discard: $('[data-batch-resume-discard]', root) },
      onDone: function (job) { var s = $('[data-upload-status]', job.item); if (s) s.textContent = 'Uploaded — becomes a post when you create the batch'; },
      onChange: function () { self.sync(); }
    }) : null;

    if (this.picker) this.picker.onChange(function (sel) { self.syncPickButtons(sel); });
    if (this.addRowBtn) this.addRowBtn.addEventListener('click', function () { self.addRow(self.picker.getSelection()); self.picker.clear(); });
    if (this.addEachBtn) this.addEachBtn.addEventListener('click', function () { self.picker.getSelection().forEach(function (a) { self.addRow([a]); }); self.picker.clear(); });
    if (this.spacingEl) this.spacingEl.addEventListener('change', function () { self.redate(); });
    var drop = $('[data-file-drop]', root);
    if (drop && this.fileInput) bindDrop(drop, this.fileInput, function (files) {
      if (self.queue) { files.forEach(function (f) { if (isQuickTime(f)) toast(f.name + ': .MOV plays inline in Safari; Chrome and Firefox will show an Open / Download card instead.', { duration: 6000 }); self.queue.add(f); }); }
      else { self.files = self.files.concat(files); self.syncFiles(); }
      if (self.fileInput) self.fileInput.value = '';
    });
    root.addEventListener('click', function (e) {
      var cancel = e.target.closest('[data-batch-cancel]');
      if (cancel && self.queue) { var ci = cancel.closest('[data-batch-item]'); if (ci && ci._job) self.queue.remove(ci._job); return; }
      var rm = e.target.closest('[data-file-remove]');
      if (rm) {
        var li = rm.closest('[data-file-name]');
        if (self.queue) { if (li && li._job) self.queue.remove(li._job); return; }
        var name = li.dataset.fileName; self.files = self.files.filter(function (f) { return f.name !== name; }); self.syncFiles(); return;
      }
      var rr = e.target.closest('[data-row-remove]');
      if (rr) { var li2 = rr.closest('[data-batch-row]'); self.removeRow(li2); }
    });
    root.addEventListener('change', function (e) {
      if (e.target.matches('[data-row-date]')) e.target.closest('[data-batch-row]')._row.dateTouched = true;
    });
    if (this.submitBtn) this.submitBtn.addEventListener('click', function () { self.submit(); });
    this.syncPickButtons([]);
    this.sync();
  }
  Batch.prototype.syncPickButtons = function (sel) {
    var n = sel.length, full = this.rows.length >= this.maxRows;
    if (this.addRowBtn) { this.addRowBtn.disabled = n === 0 || full; this.addRowBtn.textContent = n > 1 ? 'Add as one post (' + n + ' media)' : 'Add as one post'; }
    if (this.addEachBtn) { this.addEachBtn.disabled = n < 2 || full; this.addEachBtn.textContent = n > 1 ? 'One post per asset (' + n + ')' : 'One post per asset'; }
  };
  Batch.prototype.dateFor = function (index) {
    var spacing = Math.max(1, Math.min(30, parseInt(this.spacingEl && this.spacingEl.value, 10) || cfg.spacing || 3));
    var base = cfg.latest ? new Date(cfg.latest.replace('T', ' ').replace(/-/g, '/')) : new Date();
    if (isNaN(base.getTime())) base = new Date();
    base.setDate(base.getDate() + spacing * (index + 1));
    return toLocalIso(base);
  };
  Batch.prototype.addRow = function (assets) {
    if (!assets.length || this.rows.length >= this.maxRows) { if (this.rows.length >= this.maxRows) toast('Up to ' + this.maxRows + ' posts per batch.'); return; }
    var li = this.tpl.content.firstElementChild.cloneNode(true);
    var row = { el: li, assets: assets.slice(0, 10), dateTouched: false };
    li._row = row;
    var media = $('[data-row-media]', li), shown = row.assets.slice(0, 4);
    media.innerHTML = shown.map(function (a, i) {
      if (i === 3 && row.assets.length > 4) return '<div class="studio-row-thumb studio-row-more">+' + (row.assets.length - 3) + '</div>';
      return '<div class="studio-row-thumb">' + (a.media === 'video' ? '<video src="' + esc(a.src) + '" muted playsinline preload="metadata"></video>' : '<img src="' + esc(a.src) + '" alt="">') + '</div>';
    }).join('');
    media.title = row.assets.map(function (a) { return a.label; }).join(', ');
    var caption = $('[data-row-caption]', li);
    if (caption && cfg.defaults) caption.placeholder = 'Please insert caption here';
    this.rows.push(row);
    this.rowsEl.appendChild(li);
    li.classList.add('ui-enter');
    this.redate();
    this.sync();
    this.syncPickButtons(this.picker ? this.picker.getSelection() : []);
  };
  Batch.prototype.removeRow = function (li) {
    var self = this;
    this.rows = this.rows.filter(function (r) { return r.el !== li; });
    var done = function () { if (li.parentNode) li.parentNode.removeChild(li); self.redate(); self.sync(); self.syncPickButtons(self.picker ? self.picker.getSelection() : []); };
    if (App.remove) App.remove(li, done); else done();
  };
  Batch.prototype.redate = function () {
    var self = this;
    this.rows.forEach(function (r, i) {
      var input = $('[data-row-date]', r.el);
      if (input && !r.dateTouched) input.value = self.dateFor(i);
    });
  };
  Batch.prototype.syncFiles = function () {
    var self = this;
    if (this.fileList) this.fileList.innerHTML = this.files.map(function (f) { return fileRowHtml(f, fileNote(f, self.maxMb)); }).join('');
    this.files.forEach(function (f) { if (isQuickTime(f)) toast(f.name + ': .MOV plays inline in Safari; Chrome and Firefox will show an Open / Download card instead.', { duration: 6000 }); });
    this.sync();
  };
  /** Files that will make posts: finished uploads (tokens) in chunk mode, else the accepted picks. */
  Batch.prototype.validFiles = function () {
    var self = this;
    if (this.queue) return this.queue.done().map(function (j) { return j.file; });
    return this.files.filter(function (f) { return !fileNote(f, self.maxMb); });
  };
  Batch.prototype.uploading = function () { return !!(this.queue && this.queue.inFlight()); };
  Batch.prototype.sync = function () {
    var n = this.rows.length, files = this.validFiles().length, busy = this.uploading();
    if (this.countEl) this.countEl.textContent = String(n + files);
    if (this.emptyEl) this.emptyEl.hidden = n > 0;
    if (this.submitBtn) {
      this.submitBtn.disabled = busy || n + files === 0;
      this.submitBtn.textContent = busy ? 'Uploading…' : (n + files > 0 ? 'Create ' + (n + files) + ' post' + (n + files === 1 ? '' : 's') : 'Create posts');
    }
  };
  Batch.prototype.submit = function () {
    var self = this, rows = this.rows.map(function (r) {
      var type = $('[data-row-type]', r.el);
      return {
        caption: ($('[data-row-caption]', r.el).value || '').trim(),
        scheduled_date: $('[data-row-date]', r.el).value || '',
        post_type: type ? type.value : 'post',
        assets: r.assets.map(function (a) { return a.key; })
      };
    });
    var files = this.validFiles();
    if (this.uploading()) { toast('Wait for the uploads to finish (or cancel them) first.', { kind: 'error' }); return; }
    if (!rows.length && !files.length) return;
    var fd = new FormData();
    if (rows.length) fd.append('rows', JSON.stringify(rows));
    fd.append('spacing_days', (this.spacingEl && this.spacingEl.value) || '3');
    fd.append('client', cfg.client || '');
    var doneJobs = this.queue ? this.queue.done() : [];
    if (this.queue) doneJobs.forEach(function (j) { fd.append('claimed[]', j.token); });
    else files.forEach(function (f) { fd.append('images[]', f, f.name); });

    var prog = $('[data-batch-progress]', this.root), fill = $('[data-batch-progress-fill]', this.root), text = $('[data-batch-progress-text]', this.root);
    var results = $('[data-batch-results]', this.root), list = $('[data-batch-results-list]', this.root);
    this.submitBtn.disabled = true; this.submitBtn.setAttribute('aria-busy', 'true');
    prog.hidden = false; fill.style.transform = 'translateX(-100%)'; text.textContent = files.length ? 'Uploading…' : 'Creating posts…';

    var xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', function (e) {
      if (!e.lengthComputable) return;
      var pct = Math.round(e.loaded / e.total * 100);
      fill.style.transform = 'translateX(' + (pct - 100) + '%)';
      text.textContent = 'Uploading… ' + pct + '%';
    });
    xhr.onload = function () {
      var data = null; try { data = JSON.parse(xhr.responseText); } catch (e) {}
      fill.style.transform = 'translateX(0)';
      self.submitBtn.removeAttribute('aria-busy');
      if (!data || data.ok === false) {
        text.textContent = (data && data.error) || 'Request failed (' + xhr.status + ')';
        toast(text.textContent, { kind: 'error' });
        self.submitBtn.disabled = false;
        return;
      }
      text.textContent = data.count + ' post' + (data.count === 1 ? '' : 's') + ' created';
      results.hidden = false;
      list.innerHTML = (data.created || []).map(function (c) {
        var when = c.date ? formatWhen(String(c.date).replace(' ', 'T')) : '';
        return '<li><a class="ui-row ui-row--leading-sm studio-result-ok" href="' + esc(postUrl(cfg, c.post_id)) + '">'
          + '<div class="ui-row-leading ui-row-leading--icon">' + ICON.check + '</div>'
          + '<div class="ui-row-body"><div class="ui-row-title">Post #' + esc(c.post_id) + ' — ' + esc(c.filename || '') + '</div><div class="ui-row-subtitle">' + esc(when) + (c.assets ? ' · ' + c.assets + ' media' : '') + '</div></div>'
          + '</a></li>';
      }).join('') + (data.errors || []).map(function (err) {
        return '<li><div class="ui-row ui-row--leading-sm studio-result-err"><div class="ui-row-leading ui-row-leading--icon">' + ICON.x + '</div><div class="ui-row-body"><div class="ui-row-title ui-row-title--wrap">' + esc(err) + '</div></div></div></li>';
      }).join('');
      toast(text.textContent, { kind: 'success' });
      if (data.count > 0) {
        self.rows.forEach(function (r) { if (r.el.parentNode) r.el.parentNode.removeChild(r.el); });
        self.rows = []; self.files = [];
        if (self.queue) { doneJobs.forEach(function (j) { j.state = 'removed'; j.token = null; if (j.item.parentNode) j.item.parentNode.removeChild(j.item); }); self.queue.jobs = self.queue.jobs.filter(function (j) { return j.state !== 'removed'; }); }
        else if (self.fileList) self.fileList.innerHTML = '';
        if (cfg.latest !== undefined && data.created && data.created.length) {
          var last = data.created[data.created.length - 1].date;
          if (last) cfg.latest = String(last).replace(' ', 'T').slice(0, 16);
        }
      }
      self.sync();
    };
    xhr.onerror = function () { text.textContent = 'Network error — try again.'; self.submitBtn.disabled = false; self.submitBtn.removeAttribute('aria-busy'); };
    xhr.open('POST', cfg.batch || (this.root.dataset.endpoint || ''));
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(fd);
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
    picker:   function (root) { return root._picker || new Picker(root); },
    preview:  function (form, root, picker) { return new Preview(form, root, picker); },
    composer: function (form) { return new Composer(form); },
    uploads:  function (zone) { return new Uploads(zone); },
    renders:  function (root) { return new Renders(root); },
    batch:    function (root) { return new Batch(root); },
    linkTags: linkTags,
    formatWhen: formatWhen,
    instances: {}
  };

  function init() {
    $$('[data-composer]').forEach(function (form) { App.studio.instances.composer = new Composer(form); });
    $$('[data-batch]').forEach(function (root) { App.studio.instances.batch = new Batch(root); });
    $$('[data-picker]').forEach(function (root) { if (!root._picker) new Picker(root); });
    $$('[data-upload-zone]').forEach(function (zone) { App.studio.instances.uploads = new Uploads(zone); });
    $$('[data-renders]').forEach(function (root) { App.studio.instances.renders = new Renders(root); });
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
