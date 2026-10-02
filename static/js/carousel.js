/* =====================================================================
   App.carousel — the post media carousel (both seats: the Posts detail
   sheet and the New post pop-up preview). Markup: renderPostMedia()
   (partials/components/post-detail.php) or App.carousel.markup().

     [data-carousel] .pd-media     data-count, data-autoplay ("1" = a muted
                                   video starts when its slide is shown)
       [data-carousel-track]       horizontal scroll-snap track (swipe)
         .pd-slide[data-slide=i]   data-thumb (sm preview, used by the comment
                                   slide chips), data-media-type
       [data-carousel-dot=i]       dots
       [data-carousel-counter]     "2 / 7"
       [data-carousel-prev|next]   arrows (shown on hover-capable pointers)

   App.carousel.init(root)        wire every [data-carousel] under root (idempotent)
   App.carousel.go(car, i, smooth) scroll to slide i
   App.carousel.index(car)        current slide index
   App.carousel.markup(items, opts) HTML for [{src, large, thumb, media, local, name}]
                                  opts: label, autoplay, empty
   Keyboard: ←/→ (and Home/End) while the track has focus.
   Videos: only the visible slide plays; the others are paused.
   Event: 'carousel:change' {index, count} (bubbles) on the [data-carousel].
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  if (App.carousel) return;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function reduced() { return !!(App.reducedMotion && App.reducedMotion()); }

  var CHEV_L = '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>';
  var CHEV_R = '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>';
  var PLAY = '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';

  function count(car) { return parseInt(car.getAttribute('data-count') || '0', 10) || $$('[data-slide]', car).length || 1; }
  function track(car) { return $('[data-carousel-track]', car); }
  function index(car) {
    var t = track(car); if (!t) return 0;
    var i = Math.round(t.scrollLeft / Math.max(1, t.clientWidth));
    return Math.max(0, Math.min(count(car) - 1, i));
  }
  function go(car, i, smooth) {
    var t = track(car); if (!t) return;
    i = Math.max(0, Math.min(count(car) - 1, i));
    t.scrollTo({ left: i * t.clientWidth, behavior: smooth === false || reduced() ? 'auto' : 'smooth' });
  }

  function sync(car) {
    var n = count(car), i = index(car);
    var prev = car.getAttribute('data-index');
    car.setAttribute('data-index', String(i));
    $$('[data-carousel-dot]', car).forEach(function (d, k) {
      d.classList.toggle('is-active', k === i);
      d.setAttribute('aria-selected', k === i ? 'true' : 'false');
      d.tabIndex = k === i ? 0 : -1;
    });
    var counter = $('[data-carousel-counter]', car);
    if (counter) counter.textContent = (i + 1) + ' / ' + n;
    var p = $('[data-carousel-prev]', car), nx = $('[data-carousel-next]', car);
    if (p) p.disabled = i === 0;
    if (nx) nx.disabled = i >= n - 1;
    $$('[data-slide]', car).forEach(function (s, k) {
      s.setAttribute('aria-hidden', k === i ? 'false' : 'true');
      s.classList.toggle('is-active', k === i);
      // nothing focusable inside a hidden slide (the track itself takes ←/→)
      $$('button, a[href], video[controls]', s).forEach(function (el) { if (k === i) el.removeAttribute('tabindex'); else el.setAttribute('tabindex', '-1'); });
    });
    // Only the visible slide plays; a muted autoplay video resumes when its slide comes back.
    $$('[data-slide]', car).forEach(function (s, k) {
      var v = $('video', s); if (!v) return;
      if (k !== i) { if (!v.paused) v.pause(); return; }
      if (car.getAttribute('data-autoplay') === '1' && v.muted && v.paused && String(prev) !== String(i)) {
        var pr = v.play(); if (pr && pr.catch) pr.catch(function () {});
      }
    });
    if (String(prev) !== String(i)) car.dispatchEvent(new CustomEvent('carousel:change', { bubbles: true, detail: { index: i, count: n } }));
  }

  function wire(car) {
    var t = track(car);
    if (!t || car.__carousel) return;
    car.__carousel = true;
    if (!t.hasAttribute('tabindex')) t.tabIndex = 0;
    t.setAttribute('aria-live', 'polite');
    var ticking = false;
    t.addEventListener('scroll', function () {
      if (ticking) return; ticking = true;
      requestAnimationFrame(function () { ticking = false; sync(car); });
    }, { passive: true });
    car.addEventListener('click', function (e) {
      var d = e.target.closest('[data-carousel-dot]');
      if (d && car.contains(d)) { go(car, parseInt(d.getAttribute('data-carousel-dot'), 10)); return; }
      if (e.target.closest('[data-carousel-prev]')) { go(car, index(car) - 1); return; }
      if (e.target.closest('[data-carousel-next]')) { go(car, index(car) + 1); return; }
    });
    car.addEventListener('keydown', function (e) {
      if (e.target.closest('input, textarea, select, video')) return;
      var i = index(car), n = count(car), to = null;
      if (e.key === 'ArrowLeft') to = i - 1;
      else if (e.key === 'ArrowRight') to = i + 1;
      else if (e.key === 'Home') to = 0;
      else if (e.key === 'End') to = n - 1;
      if (to === null || n < 2) return;
      e.preventDefault();
      go(car, to);
      var dot = $('[data-carousel-dot="' + Math.max(0, Math.min(n - 1, to)) + '"]', car);
      if (dot && e.target.closest('[data-carousel-dot]')) dot.focus();
    });
    // Keep the slide in place when the box resizes (rotation, sheet width change)
    if (window.ResizeObserver) {
      var last = car.getAttribute('data-index');
      new ResizeObserver(function () { var i = parseInt(car.getAttribute('data-index') || last || '0', 10) || 0; go(car, i, false); }).observe(t);
    }
    sync(car);
  }

  function init(root) {
    root = root || document;
    var cars = $$('[data-carousel]', root);
    if (root !== document && root.matches && root.matches('[data-carousel]')) cars.unshift(root);
    cars.forEach(wire);
    return cars;
  }

  /** Markup for local / picked media (the New post preview). Same classes as renderPostMedia(). */
  function markup(items, opts) {
    opts = opts || {};
    var n = items.length;
    if (!n) return '<div class="pd-media pd-media--empty"><span class="text-tertiary">' + esc(opts.empty || 'No media yet') + '</span></div>';
    var label = esc(opts.label || 'Post media');
    var html = '<div class="pd-media" data-carousel data-count="' + n + '" data-autoplay="' + (opts.autoplay === false ? '0' : '1') + '" aria-roledescription="carousel" aria-label="' + label + '">'
             + '<div class="pd-track" data-carousel-track tabindex="0">';
    items.forEach(function (it, i) {
      var isVid = it.media === 'video';
      html += '<figure class="pd-slide" data-slide="' + i + '" data-media-type="' + (isVid ? 'video' : 'image') + '" data-thumb="' + esc(it.thumb || '') + '" aria-roledescription="slide" aria-label="' + (i + 1) + ' of ' + n + '">';
      if (isVid && (it.local || !it.src)) {
        html += '<div class="pd-video pd-video--local" role="img" aria-label="' + esc(it.name || 'video') + '">' + PLAY + '<span class="pd-video-local-name">' + esc(it.name || 'Video') + '</span></div>';
      } else if (isVid) {
        html += App.video ? App.video.markup(it.src, { cls: 'pd-video', autoplay: false, unmute: true })
                          : '<video playsinline muted controls preload="metadata" src="' + esc(it.src) + '"></video>';
      } else {
        html += '<img src="' + esc(it.large || it.src) + '" alt="' + label + ' ' + (i + 1) + '" loading="' + (i === 0 ? 'eager' : 'lazy') + '" decoding="async">';
      }
      html += '</figure>';
    });
    html += '</div>';
    if (n > 1) {
      html += '<button type="button" class="pd-arrow pd-arrow--prev" data-carousel-prev aria-label="Previous slide">' + CHEV_L + '</button>'
            + '<button type="button" class="pd-arrow pd-arrow--next" data-carousel-next aria-label="Next slide">' + CHEV_R + '</button>'
            + '<div class="pd-dots" role="tablist" aria-label="Slides">';
      for (var d = 0; d < n; d++) html += '<button type="button" class="pd-dot' + (d === 0 ? ' is-active' : '') + '" data-carousel-dot="' + d + '" role="tab" aria-selected="' + (d === 0 ? 'true' : 'false') + '" aria-label="Slide ' + (d + 1) + '"></button>';
      html += '</div><span class="ui-pill ui-pill--glass ui-pill--nodot pd-counter" data-carousel-counter aria-hidden="true">1 / ' + n + '</span>';
    }
    return html + '</div>';
  }

  App.carousel = { init: init, go: go, index: index, sync: sync, markup: markup };

  // Server-rendered carousels outside a sheet (rare) — the sheets call init() themselves after filling.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); });
  else init(document);
})(window, document);
