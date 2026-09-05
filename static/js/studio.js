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
   App.studio.uploads(zone)  Drag-drop zone with per-file XHR progress; .MOV
                             shows the Safari-only warning before upload.
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
    check: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L19 7"/></svg>'
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
    root._picker  = this;
    this.bind();
    var initial = [];
    try { initial = JSON.parse(root.dataset.selected || '[]'); } catch (e) { initial = []; }
    this.set(initial, true);
    this.render();
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

  Picker.prototype.applyFilter = function (value) {
    this.filter = value || 'all';
    var self = this, visible = 0;
    $$('[data-pool-filter]', this.root).forEach(function (chip) {
      var on = chip.dataset.poolFilter === self.filter;
      chip.classList.toggle('is-active', on);
      chip.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (this.grid) {
      $$('[data-asset-key]', this.grid).forEach(function (btn) {
        var show = self.filter === 'all' || btn.dataset.assetGroup === self.filter;
        btn.hidden = !show;
        if (show) visible++;
      });
    }
    if (this.emptyEl) this.emptyEl.hidden = visible > 0;
  };

  Picker.prototype.bind = function () {
    var self = this;
    this.root.addEventListener('click', function (e) {
      var chip = e.target.closest('[data-pool-filter]');
      if (chip) { self.applyFilter(chip.dataset.poolFilter); return; }
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
      return { file: file, url: URL.createObjectURL(file), media: isVideoFile(file) ? 'video' : 'image', type: file.type || '' };
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
    this.files.forEach(function (f) { out.push({ src: f.url, media: f.media, type: f.type }); });
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
      if (isVid) {
        var type = it.type || (fileExt(it.src) === 'webm' ? 'video/webm' : (fileExt(it.src) === 'mov' ? 'video/quicktime' : 'video/mp4'));
        html += '<video playsinline muted controls preload="metadata" data-video><source src="' + esc(it.src) + '" type="' + esc(type) + '">Your browser can\'t play this video.</video>'
              + '<div class="pd-video-fallback" data-video-fallback hidden><p>Preview not supported in this browser</p></div>';
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
    $$('video[data-video]', root).forEach(function (v) {
      v.addEventListener('error', function () { var fb = v.parentNode && $('[data-video-fallback]', v.parentNode); if (fb) { fb.hidden = false; v.hidden = true; } }, true);
    });
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
    return '<li data-file-name="' + esc(file.name) + '">'
      + (vid ? '<video class="studio-file-thumb" src="' + esc(url) + '" muted playsinline preload="metadata"></video>' : '<img class="studio-file-thumb" src="' + esc(url) + '" alt="">')
      + '<span class="studio-file-name">' + esc(file.name) + '</span>'
      + '<span class="studio-file-meta' + (note ? ' is-rejected' : '') + '">' + esc(note || mb(file.size)) + '</span>'
      + '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-file-remove aria-label="Remove ' + esc(file.name) + '">' + ICON.x + '</button>'
      + '</li>';
  }
  var MOV_NOTE = '.MOV: Safari-only playback — the server needs MP4';
  function fileNote(file, maxMb) {
    if (isQuickTime(file)) return MOV_NOTE;
    if (file.size > maxMb * 1024 * 1024) return 'Over ' + maxMb + ' MB';
    var ext = fileExt(file.name);
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm'].indexOf(ext) === -1) return 'Unsupported type';
    return '';
  }

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
    this.maxMb = parseInt(cfg.maxFileMb, 10) || 25;

    var drop = $('[data-file-drop]', form);
    if (drop && this.fileInput) {
      bindDrop(drop, this.fileInput, function (files, dropped) {
        if (dropped) { self.files = self.files.concat(files); assignFiles(self.fileInput, self.files); }
        else { self.files = files; }
        self.syncFiles();
      });
    }
    form.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-file-remove]');
      if (rm) {
        var name = rm.closest('[data-file-name]').dataset.fileName;
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
  Composer.prototype.validFiles = function () { var m = this.maxMb; return this.files.filter(function (f) { return !fileNote(f, m); }); };
  Composer.prototype.syncSlots = function () {
    var free = Math.max(0, this.max - this.keptExisting() - this.validFiles().length);
    if (this.picker) this.picker.setMax(free);
  };
  Composer.prototype.syncFiles = function () {
    var self = this;
    if (this.fileList) {
      this.fileList.innerHTML = this.files.map(function (f) { return fileRowHtml(f, fileNote(f, self.maxMb)); }).join('');
    }
    this.files.forEach(function (f) { if (isQuickTime(f)) toast(f.name + ': .MOV plays in Safari only and the server needs MP4 — convert first (QuickTime: File → Export As → 1080p).', { kind: 'error', duration: 6000 }); });
    if (this.preview) this.preview.setFiles(this.validFiles());
    this.syncSlots();
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
    var picks = this.picker ? this.picker.getSelection().length : 0;
    var total = this.keptExisting() + picks + this.validFiles().length;
    if (total > this.max) {
      e.preventDefault();
      toast('Up to ' + this.max + ' media per post — remove ' + (total - this.max) + '.', { kind: 'error' });
      return;
    }
    var bad = this.files.filter(function (f) { return isQuickTime(f); });
    if (bad.length) {
      e.preventDefault();
      toast('Remove the .MOV file(s) or convert them to MP4 first.', { kind: 'error' });
      return;
    }
    var btn = $('[data-composer-submit]', this.form);
    if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Saving…'; }
  };

  /* ================================================================== */
  /* Uploads zone → batch-process.php (one draft post per file)        */
  /* ================================================================== */
  function Uploads(zone) {
    var self = this;
    this.zone = zone;
    this.endpoint = zone.dataset.endpoint || cfg.batch || '';
    this.maxMb = parseInt(zone.dataset.maxMb, 10) || 10;
    this.input = $('[data-upload-input]', zone);
    this.list = $('[data-upload-list]', zone);
    this.tpl = $('[data-upload-item-template]', zone);
    this.queue = []; this.busy = false;
    var drop = $('[data-file-drop]', zone);
    if (drop) bindDrop(drop, this.input, function (files) { files.forEach(function (f) { self.add(f); }); if (self.input) self.input.value = ''; });
    zone.addEventListener('click', function (e) {
      var item = e.target.closest('[data-upload-item]');
      if (!item) return;
      if (e.target.closest('[data-upload-anyway]')) { $('[data-upload-warning]', item).hidden = true; self.enqueue(item._file, item); }
      if (e.target.closest('[data-upload-skip]')) { item.remove(); }
    });
  }
  Uploads.prototype.add = function (file) {
    var item = this.tpl.content.firstElementChild.cloneNode(true);
    item._file = file;
    $('[data-upload-name]', item).textContent = file.name;
    $('[data-upload-meta]', item).textContent = mb(file.size) + (file.type ? ' · ' + file.type : '');
    var thumb = $('[data-upload-thumb]', item);
    if (isVideoFile(file)) { thumb.innerHTML = ICON.play; }
    else if (/^image\//.test(file.type)) { var img = document.createElement('img'); img.alt = ''; img.src = URL.createObjectURL(file); thumb.appendChild(img); }
    this.list.appendChild(item);
    var status = $('[data-upload-status]', item);
    if (file.size > this.maxMb * 1024 * 1024) { status.textContent = 'Over ' + this.maxMb + ' MB — not uploaded.'; status.classList.add('is-error'); return; }
    if (isQuickTime(file)) {
      // spec §6: warn before upload. Server acceptance of .mov is the Video phase's call;
      // today batch-process.php rejects it with a "convert to MP4" message that we surface as-is.
      $('[data-upload-warning]', item).hidden = false;
      return;
    }
    if (!/^image\//.test(file.type) && !/^video\/(mp4|webm)$/.test(file.type)) {
      status.textContent = 'Unsupported type — use JPG, PNG, GIF, WebP, MP4 or WebM.'; status.classList.add('is-error'); return;
    }
    this.enqueue(file, item);
  };
  Uploads.prototype.enqueue = function (file, item) {
    this.queue.push({ file: file, item: item });
    $('[data-upload-status]', item).textContent = 'Waiting…';
    this.next();
  };
  Uploads.prototype.next = function () {
    if (this.busy || !this.queue.length) return;
    var self = this, job = this.queue.shift(), item = job.item, file = job.file;
    var prog = $('[data-upload-progress]', item), fill = $('[data-upload-fill]', item), status = $('[data-upload-status]', item);
    this.busy = true; prog.hidden = false; status.textContent = 'Uploading… 0%';
    var xhr = new XMLHttpRequest();
    var fd = new FormData(); fd.append('images[]', file, file.name);
    xhr.upload.addEventListener('progress', function (e) {
      if (!e.lengthComputable) return;
      var pct = Math.round(e.loaded / e.total * 100);
      fill.style.transform = 'translateX(' + (pct - 100) + '%)';
      status.textContent = 'Uploading… ' + pct + '%';
    });
    xhr.onload = function () {
      var data = null; try { data = JSON.parse(xhr.responseText); } catch (e) {}
      fill.style.transform = 'translateX(0)';
      var err = null, created = data && data.created && data.created[0];
      if (!data || data.ok === false || xhr.status >= 400) err = (data && data.error) || ('Upload failed (' + xhr.status + ')');
      else if (!created) err = (data.errors && data.errors[0]) || 'Not accepted';
      if (err) { status.textContent = err; status.classList.add('is-error'); prog.hidden = true; }
      else {
        var when = created.date ? formatWhen(String(created.date).replace(' ', 'T')) : '';
        status.innerHTML = 'Draft post #' + esc(created.post_id) + (when ? ' · ' + esc(when) : '')
          + ' — <a href="' + esc((cfg.base || '') + '/posts?client=' + encodeURIComponent(cfg.client || '') + '&post=' + encodeURIComponent(created.post_id)) + '">finish it in Posts</a>';
        status.classList.add('is-ok');
      }
      self.busy = false; self.next();
    };
    xhr.onerror = function () { status.textContent = 'Network error — try again.'; status.classList.add('is-error'); prog.hidden = true; self.busy = false; self.next(); };
    xhr.open('POST', this.endpoint);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(fd);
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

    if (this.picker) this.picker.onChange(function (sel) { self.syncPickButtons(sel); });
    if (this.addRowBtn) this.addRowBtn.addEventListener('click', function () { self.addRow(self.picker.getSelection()); self.picker.clear(); });
    if (this.addEachBtn) this.addEachBtn.addEventListener('click', function () { self.picker.getSelection().forEach(function (a) { self.addRow([a]); }); self.picker.clear(); });
    if (this.spacingEl) this.spacingEl.addEventListener('change', function () { self.redate(); });
    var drop = $('[data-file-drop]', root);
    if (drop && this.fileInput) bindDrop(drop, this.fileInput, function (files) { self.files = self.files.concat(files); self.syncFiles(); if (self.fileInput) self.fileInput.value = ''; });
    root.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-file-remove]');
      if (rm) { var name = rm.closest('[data-file-name]').dataset.fileName; self.files = self.files.filter(function (f) { return f.name !== name; }); self.syncFiles(); return; }
      var rr = e.target.closest('[data-row-remove]');
      if (rr) { var li = rr.closest('[data-batch-row]'); self.removeRow(li); }
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
    if (this.fileList) this.fileList.innerHTML = this.files.map(function (f) { return fileRowHtml(f, fileNote(f, 10)); }).join('');
    this.files.forEach(function (f) { if (isQuickTime(f)) toast(f.name + ': .MOV plays in Safari only and the server needs MP4.', { kind: 'error', duration: 6000 }); });
    this.sync();
  };
  Batch.prototype.validFiles = function () { return this.files.filter(function (f) { return !fileNote(f, 10); }); };
  Batch.prototype.sync = function () {
    var n = this.rows.length, files = this.validFiles().length;
    if (this.countEl) this.countEl.textContent = String(n + files);
    if (this.emptyEl) this.emptyEl.hidden = n > 0;
    if (this.submitBtn) {
      this.submitBtn.disabled = n + files === 0;
      this.submitBtn.textContent = n + files > 0 ? 'Create ' + (n + files) + ' post' + (n + files === 1 ? '' : 's') : 'Create posts';
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
    if (!rows.length && !files.length) return;
    var fd = new FormData();
    if (rows.length) fd.append('rows', JSON.stringify(rows));
    fd.append('spacing_days', (this.spacingEl && this.spacingEl.value) || '3');
    files.forEach(function (f) { fd.append('images[]', f, f.name); });

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
        return '<li><a class="ui-row ui-row--leading-sm studio-result-ok" href="' + esc((cfg.base || '') + '/posts?client=' + encodeURIComponent(cfg.client || '') + '&post=' + encodeURIComponent(c.post_id)) + '">'
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
        if (self.fileList) self.fileList.innerHTML = '';
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
    initHub();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})(window, document);
