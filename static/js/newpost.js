/* =====================================================================
   App.newPost — the New post pop-up (admin). One sheet to build a post
   from approved assets and / or fresh uploads, and to edit an existing
   post's slides, caption, date and type. Server: post-compose.php.

   App.newPost.open({client, preselect: ['tire:12', 'library:3', {ref: 'upload:<token>', media, thumb, name}], postId, pane: 'approved'|'upload'})
                                  → Promise; client defaults to the page's scope (<body data-client>),
                                    no client → a client chooser first. postId → edit mode.
   App.newPost.close(force)       close (asks first when there are unsaved changes, unless force)
   App.newPost.isOpen()

   Entry points (delegated clicks, any admin page that loads this file):
     [data-new-action="post"]     the "+ New" menu (data-client optional)
     [data-newpost]               a "New post" button; data-newpost-preselect="tire:1,library:4",
                                  data-newpost-pane="upload", data-client
     [data-newpost-edit="ID"]     edit an existing post (Posts detail ⋯ → Edit post…)
     ?newpost=1 | upload | edit (&post=ID) [&newpost_assets=tire:1,…]   opens on load (the param is
                                  removed from the address bar); retired routes redirect here.
   After a save the sheet closes and the post's detail opens with a toast. On Posts (same client) nothing
   reloads: App.posts.refresh() swaps / inserts / removes the row and moves the segment counts, then
   App.posts.open(); anywhere else the page goes to posts.php?post=ID (its segment + month).
   Needs changes posts open with the client's latest note pinned above the tray; the primary is Send for review.
   Fewer clicks: the caption takes focus after a pointer pick while it is empty (tiles never steal focus), and
   on entering Details on a phone; with two panes, series chips show directly while there are few of them
   (a series chip also narrows to its tire).

   Layout: header · tray (selected slides: numbered, Cover badge, drag / keyboard reorder, ×,
   "N / 20", shape warning) · body (Media: Approved | Upload; Details: caption, hashtags, type,
   date, live preview — two panes ≥ 900px, a Media → Details step switch below) · sticky footer.
   Dialog: role=dialog + aria-modal, focus trap, Esc, unsaved-changes guard (also beforeunload).
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  if (App.newPost) return;

  var cfg = window.NewPostConfig || {};
  var MAX = 20;
  var SHAPE_TOLERANCE = 0.03;     // aspect ratios within 3 % count as the same shape

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function toast(msg, kind, dur) { if (App.toast) App.toast(msg, { kind: kind, duration: dur }); }
  function debounce(fn, ms) { var t; return function () { var a = arguments, self = this; clearTimeout(t); t = setTimeout(function () { fn.apply(self, a); }, ms); }; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function isoLocal(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function defaultDate() { var d = new Date(); d.setDate(d.getDate() + 1); d.setHours(10, 0, 0, 0); return isoLocal(d); }
  function fileExt(name) { var m = String(name || '').toLowerCase().match(/\.([a-z0-9]+)$/); return m ? m[1] : ''; }
  function isVideoFile(f) { return /^video\//i.test(f.type || '') || ['mp4', 'webm', 'mov', 'm4v'].indexOf(fileExt(f.name)) !== -1; }
  function linkTags(text) {
    var safe = esc(text);
    try { safe = safe.replace(/(^|[\s(])(#[\p{L}\p{N}_]+)/gu, '$1<span class="ig-tag">$2</span>'); }
    catch (e) { safe = safe.replace(/(^|[\s(])(#[\w]+)/g, '$1<span class="ig-tag">$2</span>'); }
    return safe.replace(/\r?\n/g, '<br>');
  }
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function formatWhen(iso) {
    var m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
    if (!m) return 'Date to be confirmed';
    var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    var h = d.getHours(), ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
    return DAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ' · ' + h + ':' + pad(d.getMinutes()) + ' ' + ap;
  }

  var I = {
    x: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    play: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>',
    warn: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4 2.5 20h19z"/><path d="M12 10v4.5M12 17.2v.3"/></svg>',
    up: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15.5V4"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/><path d="M4.5 16v2a2.5 2.5 0 0 0 2.5 2.5h10a2.5 2.5 0 0 0 2.5-2.5v-2"/></svg>',
    search: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>',
    check: '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L19 7"/></svg>'
  };

  /* ------------------------------------------------------------------ */
  /* Server                                                              */
  /* ------------------------------------------------------------------ */
  function endpoint(slug) { return (cfg.endpoint || ((cfg.base || '') + '/post-compose.php')) + '?client=' + encodeURIComponent(slug || ''); }
  function getJson(slug, action, params) {
    var qs = new URLSearchParams(); qs.set('action', action);
    Object.keys(params || {}).forEach(function (k) { var v = params[k]; if (v !== undefined && v !== null && v !== '') qs.set(k, Array.isArray(v) ? v.join(',') : String(v)); });
    return fetch(endpoint(slug) + '&' + qs.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (d) { return { ok: r.ok && d && d.ok !== false, status: r.status, data: d || {} }; }); })
      .catch(function () { return { ok: false, status: 0, data: { error: 'Network error' } }; });
  }
  function postForm(slug, params) {
    var body = new URLSearchParams();
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', String(x)); });
      else if (v !== undefined && v !== null) body.append(k, String(v));
    });
    body.set('client', slug);
    return fetch(endpoint(slug), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json' }, body: body.toString() })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (d) { return { ok: r.ok && d && d.ok !== false, status: r.status, data: d || {} }; }); })
      .catch(function () { return { ok: false, status: 0, data: { error: 'Network error' } }; });
  }

  function ensureCss(only) {
    (cfg.css || []).filter(function (h) { return !only || only.test(h); }).forEach(function (href) {
      var key = href.split('?')[0];
      if ($$('link[rel="stylesheet"]').some(function (l) { return (l.getAttribute('href') || '').split('?')[0] === key; })) return;
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l);
    });
  }

  /* ------------------------------------------------------------------ */
  /* State + DOM                                                         */
  /* ------------------------------------------------------------------ */
  var S = null, R = null;   // state, root element
  var uid = 0;

  function fresh(opts) {
    return {
      opts: opts, slug: '', client: null, init: null,
      mode: opts.postId ? 'edit' : 'create', postId: opts.postId ? parseInt(opts.postId, 10) : 0, post: null,
      slides: [], pane: opts.pane === 'upload' ? 'upload' : 'approved', step: 'media',
      f: { tires: [], lib: false, series: [], media: 'all', q: '' },   // tires = explicit tire chips; a series chip adds its tire (effTires)
      items: [], next: null, total: 0, loading: false, reqId: 0,
      replaceAt: null, menuAt: null, dirty: false, saving: false, confirming: false,
      uploads: [], busyUpload: false, lastFocus: document.activeElement, previewKey: ''
    };
  }

  function shell() {
    var el = document.createElement('div');
    el.className = 'np-root';
    el.id = 'npSheet';
    el.innerHTML =
      '<div class="np-backdrop" data-np-backdrop></div>' +
      '<div class="np-panel" role="dialog" aria-modal="true" aria-labelledby="npTitle" tabindex="-1">' +
        '<header class="np-head">' +
          '<div class="np-head-client" data-np-client></div>' +
          '<h2 class="np-title" id="npTitle" data-np-title>New post</h2>' +
          '<div class="ui-segmented ui-segmented--auto np-steps" role="tablist" aria-label="Steps" data-np-steps hidden>' +
            '<button type="button" class="ui-segmented-item is-active" role="tab" aria-selected="true" data-value="media">Media</button>' +
            '<button type="button" class="ui-segmented-item" role="tab" aria-selected="false" data-value="details">Details</button>' +
          '</div>' +
          '<button type="button" class="np-close" data-np-close aria-label="Close">' + I.x + '</button>' +
        '</header>' +
        '<section class="np-tray" aria-label="Selected slides" data-np-tray>' +
          '<div class="pd-note np-note" data-np-note role="note" aria-label="What should change" hidden>' +
            '<div class="pd-note-head"><span data-np-note-who></span><span class="pd-note-when text-tertiary" data-np-note-when></span></div>' +
            '<p class="pd-note-text" data-np-note-text></p>' +
          '</div>' +
          '<div class="np-tray-head"><span class="np-tray-title">Slides</span><span class="np-tray-count" data-np-count>0 / ' + MAX + '</span>' +
            '<span class="np-tray-hint text-tertiary" data-np-tray-hint>Drag to reorder · slide 1 is the cover</span></div>' +
          '<ol class="np-tray-list" role="listbox" aria-label="Slides in carousel order" aria-orientation="horizontal" data-np-tray-list></ol>' +
          '<p class="np-tray-empty text-secondary" data-np-tray-empty>Tap approved images below — or upload — to add slides. The first one is the cover.</p>' +
          '<div class="np-replace" data-np-replace hidden role="status"><span data-np-replace-text></span><button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-np-replace-cancel>Cancel</button></div>' +
        '</section>' +
        '<div class="np-body" data-np-body>' +
          '<section class="np-pane np-media" data-np-pane="media" aria-label="Media">' +
            '<div class="ui-segmented np-source" role="tablist" aria-label="Media source" data-np-source>' +
              '<button type="button" class="ui-segmented-item is-active" role="tab" aria-selected="true" data-value="approved">Approved</button>' +
              '<button type="button" class="ui-segmented-item" role="tab" aria-selected="false" data-value="upload">Upload</button>' +
            '</div>' +
            '<div class="np-approved" data-np-approved>' +
              '<div class="np-filters" data-np-filters>' +
                '<label class="np-search">' + I.search + '<span class="ui-visually-hidden">Search approved images by name</span>' +
                  '<input type="search" class="np-search-input" data-np-q placeholder="Search by name, tire or series" autocomplete="off" enterkeyhint="search"></label>' +
                '<div class="np-chips" role="group" aria-label="Tires and Library" data-np-groups></div>' +
                '<div class="np-chips np-chips--series" role="group" aria-label="Series" data-np-series hidden></div>' +
                '<div class="np-chips np-chips--media" role="group" aria-label="Media type" data-np-media hidden></div>' +
              '</div>' +
              '<div class="np-grid" role="listbox" aria-multiselectable="true" aria-label="Approved images" data-np-grid></div>' +
              '<p class="np-empty text-secondary" data-np-empty hidden></p>' +
              '<div class="np-more" data-np-more hidden><button type="button" class="ui-btn ui-btn--gray" data-np-more-btn>Load more <span class="text-secondary" data-np-more-n></span></button></div>' +
            '</div>' +
            '<div class="np-upload" data-np-upload hidden>' +
              '<label class="np-drop" data-np-drop>' +
                '<input type="file" class="ui-visually-hidden" data-np-file multiple accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov">' +
                '<span class="np-drop-icon">' + I.up + '</span><span class="np-drop-label">Choose files</span>' +
                '<span class="np-drop-hint text-secondary">or drop them here · images up to ' + (cfg.maxImageMb || 50) + ' MB, videos up to ' + Math.round((cfg.maxVideoMb || 4096) / 1024) + ' GB · each one joins the slides above (this post only)</span>' +
              '</label>' +
              '<ul class="np-uplist" role="list" data-np-uplist></ul>' +
            '</div>' +
          '</section>' +
          '<section class="np-pane np-details" data-np-pane="details" aria-label="Details">' +
            '<div class="np-fields">' +
              '<div class="np-field"><div class="np-label-row"><label class="np-label" for="npCaption">Caption</label><span class="np-counter" data-np-cap-count aria-live="polite">0 / 2,200</span></div>' +
                '<textarea class="ui-textarea np-caption" id="npCaption" data-np-field="caption" rows="5" maxlength="10000" placeholder="What does the post say?"></textarea>' +
                '<p class="np-field-error" data-np-err="caption" hidden></p></div>' +
              '<div class="np-field"><div class="np-label-row"><label class="np-label" for="npTags">Hashtags</label><button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-np-defaults hidden>Add client defaults</button></div>' +
                '<textarea class="ui-textarea np-tags" id="npTags" data-np-field="hashtags" rows="2" maxlength="2000" placeholder="#Brand #Campaign"></textarea></div>' +
              '<div class="np-field-row">' +
                '<div class="np-field"><label class="np-label" for="npDate">Post date</label><input class="ui-input" type="datetime-local" id="npDate" data-np-field="scheduled_date">' +
                  '<p class="np-field-error" data-np-err="scheduled_date" hidden></p></div>' +
                '<div class="np-field" data-np-type-field hidden><span class="np-label" id="npTypeLabel">Type</span><div class="ui-segmented np-type" role="radiogroup" aria-labelledby="npTypeLabel" data-np-type></div></div>' +
              '</div>' +
              '<div class="np-field"><label class="np-label" for="npName">Reference name <span class="text-tertiary">— internal, optional</span></label>' +
                '<input class="ui-input" type="text" id="npName" data-np-field="name" maxlength="150" placeholder="e.g. Spring launch — hero"></div>' +
            '</div>' +
            '<div class="np-preview" aria-label="Preview — what the client sees">' +
              '<div class="np-preview-head"><span class="np-label">Preview</span><span class="text-tertiary np-preview-sub" data-np-preview-sub></span></div>' +
              '<article class="pd np-pd">' +
                '<div class="pd-meta"><span class="pd-type" data-np-format>Image</span><span class="ui-pill ui-pill--neutral pd-pill" data-np-status>Draft</span></div>' +
                '<div data-np-preview-media></div>' +
                '<section class="ig"><header class="ig-head"><span class="ui-avatar ui-avatar--sm ig-avatar" data-np-avatar></span><span class="ig-name" data-np-brand></span></header>' +
                  '<div class="ig-caption" data-np-preview-caption></div><div class="ig-tags" data-np-preview-tags hidden></div></section>' +
                '<div class="pd-when"><div class="pd-when-row"><span class="pd-when-body"><span class="pd-when-label">Post date</span><span class="pd-when-date" data-np-preview-date></span></span></div></div>' +
              '</article>' +
            '</div>' +
          '</section>' +
        '</div>' +
        '<footer class="np-foot ui-glass ui-glass--top" data-np-foot>' +
          '<div class="np-foot-info"><span class="np-foot-count" data-np-foot-count>0 selected</span><span class="np-foot-error" data-np-foot-error role="alert"></span></div>' +
          '<div class="np-foot-actions" data-np-actions></div>' +
        '</footer>' +
        '<div class="np-confirm" data-np-confirm hidden role="alertdialog" aria-modal="true" aria-labelledby="npConfirmTitle" aria-describedby="npConfirmText">' +
          '<div class="np-confirm-card"><h3 class="np-confirm-title" id="npConfirmTitle">Discard this post?</h3>' +
          '<p class="np-confirm-text text-secondary" id="npConfirmText">Your slides and caption have not been saved.</p>' +
          '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-np-keep>Keep editing</button>' +
          '<button type="button" class="ui-btn ui-btn--deny" data-np-discard>Discard</button></div></div></div>' +
        '<div class="np-menu" role="menu" data-np-menu hidden></div>' +
        '<div class="ui-visually-hidden" aria-live="polite" data-np-live></div>' +
        '<div class="np-chooser" data-np-chooser hidden></div>' +
      '</div>';
    return el;
  }

  function live(msg) { var l = R && $('[data-np-live]', R); if (l) { l.textContent = ''; setTimeout(function () { l.textContent = msg; }, 30); } }
  function markDirty() { if (S && !S.dirty) S.dirty = true; }
  function field(name) { return $('[data-np-field="' + name + '"]', R); }
  function val(name) { var el = field(name); return el ? el.value : ''; }

  /* ------------------------------------------------------------------ */
  /* Open / close                                                        */
  /* ------------------------------------------------------------------ */
  function open(opts) {
    opts = opts || {};
    if (S) {   // already open: preselect more / switch to edit is not supported in place — focus it
      if (opts.preselect && opts.preselect.length) addRefs(opts.preselect);
      return Promise.resolve(R);
    }
    ensureCss();
    S = fresh(opts);
    R = shell();
    document.body.appendChild(R);
    if (App.lockScroll) App.lockScroll();
    bind();
    requestAnimationFrame(function () { requestAnimationFrame(function () { if (R) R.classList.add('is-visible'); }); });
    setTimeout(function () { var p = $('.np-panel', R); if (p) try { p.focus({ preventScroll: true }); } catch (e) { p.focus(); } }, 40);
    var slug = opts.client || cfg.client || (document.body && document.body.dataset.client) || '';
    if (!slug) return chooseClient();
    return start(slug);
  }

  function chooseClient() {
    var box = $('[data-np-chooser]', R);
    box.hidden = false;
    box.innerHTML = '<p class="text-secondary np-chooser-hint">Which client is this post for?</p><ul class="ui-list np-chooser-list" role="list"><li class="ui-row"><span class="text-secondary">Loading…</span></li></ul>';
    $('[data-np-title]', R).textContent = 'New post';
    return getJson('', 'clients').then(function (res) {
      if (!S) return null;
      var list = $('.np-chooser-list', box);
      if (!res.ok) { list.innerHTML = '<li class="ui-row text-secondary">' + esc(res.data.error || 'Could not load clients') + '</li>'; return null; }
      list.innerHTML = res.data.clients.map(function (c) {
        return '<li><button type="button" class="ui-row np-chooser-row" data-np-pick-client="' + esc(c.slug) + '">'
          + avatarHtml(c, 'ui-avatar--sm')
          + '<span class="ui-row-body"><span class="ui-row-title">' + esc(c.name) + '</span></span></button></li>';
      }).join('');
      var first = $('[data-np-pick-client]', list); if (first) first.focus();
      return R;
    });
  }

  function start(slug) {
    S.slug = slug;
    $('[data-np-chooser]', R).hidden = true;
    R.classList.add('is-loading');
    var p = getJson(slug, 'init').then(function (res) {
      if (!S) return null;
      if (!res.ok) { toast(res.data.error || 'Could not open the composer', 'error'); forceClose(); return null; }
      S.init = res.data; S.client = res.data.client; MAX = res.data.max || 20;
      applyInit();
      if (S.mode === 'edit') return loadPost();
      field('hashtags').value = S.client.default_hashtags || '';
      field('scheduled_date').value = defaultDate();
      var pre = S.opts.preselect || [];
      return (pre.length ? addRefs(pre) : Promise.resolve()).then(function () { S.dirty = pre.length > 0; });
    }).then(function () {
      if (!S) return null;
      R.classList.remove('is-loading');
      setPane(S.pane);
      fetchItems(false);
      renderAll();
      var focusEl = window.matchMedia && window.matchMedia('(min-width: 900px)').matches && S.pane === 'approved' ? $('[data-np-q]', R) : $('[data-np-close]', R);
      if (focusEl) try { focusEl.focus({ preventScroll: true }); } catch (e) {}
      document.dispatchEvent(new CustomEvent('newpost:open', { detail: { client: slug, mode: S.mode, postId: S.postId } }));
      return R;
    });
    return p;
  }

  /** The same markup as clientAvatar() (helpers.php): the logo, else the initial on a fill. */
  function avatarHtml(c, cls) {
    var name = (c && c.name) || 'Joust Media';
    if (c && c.logo) return '<img class="ui-avatar ' + cls + '" src="' + esc(c.logo) + '" alt="" width="36" height="36">';
    return '<span class="ui-avatar ' + cls + ' ui-avatar--initial" aria-hidden="true">' + esc(name.charAt(0).toUpperCase()) + '</span>';
  }
  function applyInit() {
    var c = S.client;
    $('[data-np-client]', R).innerHTML = avatarHtml(c, 'ui-avatar--sm') + '<span class="np-client-name">' + esc(c.name) + '</span>';
    $('[data-np-title]', R).textContent = S.mode === 'edit' ? 'Edit post' : 'New post';
    $('[data-np-brand]', R).textContent = c.name;
    var av = $('[data-np-avatar]', R); av.outerHTML = avatarHtml(c, 'ui-avatar--sm ig-avatar');
    var def = $('[data-np-defaults]', R); def.hidden = !c.default_hashtags; def.title = c.default_hashtags || '';
    var tf = $('[data-np-type-field]', R), tg = $('[data-np-type]', R);
    if (S.init.supportsType) {
      tf.hidden = false;
      var opts = [{ value: '', label: 'Auto' }].concat(S.init.types || []);
      tg.innerHTML = opts.map(function (t, k) {
        return '<button type="button" class="ui-segmented-item' + (k === 0 ? ' is-active' : '') + '" role="radio" aria-checked="' + (k === 0) + '" data-value="' + esc(t.value) + '">' + esc(t.label) + '</button>';
      }).join('');
    }
    renderGroups();
  }

  function loadPost() {
    return getJson(S.slug, 'load', { id: S.postId }).then(function (res) {
      if (!S) return;
      if (!res.ok) { toast(res.data.error || 'Could not load this post', 'error'); forceClose(); return; }
      var p = res.data.post;
      S.post = p;
      field('caption').value = p.caption || '';
      field('hashtags').value = p.hashtags || '';
      field('scheduled_date').value = p.scheduled || defaultDate();
      field('name').value = p.name || '';
      setType(p.post_type || 'post');
      S.slides = res.data.slides.map(function (s) { return Object.assign({ uid: ++uid, existing: true }, s); });
      S.note = res.data.note || null;
      renderNote();
      S.dirty = false;
    });
  }

  /** Needs changes: "Kenda Tires asked for changes · 2h ago" + the note, above the tray (what to fix, while fixing it). */
  function renderNote() {
    var box = $('[data-np-note]', R); if (!box) return;
    var n = S && S.post && S.post.status === 'denied' ? S.note : null;
    box.hidden = !n;
    if (!n) return;
    $('[data-np-note-who]', box).textContent = (n.who || 'The client') + ' asked for changes';
    $('[data-np-note-when]', box).textContent = n.when ? ' · ' + n.when : '';
    $('[data-np-note-text]', box).innerHTML = (n.slide ? '<span class="pd-note-slide">On slide ' + n.slide + ':</span> ' : '') + (n.text ? esc(n.text) : '<span class="text-tertiary">No note left — see the comments on the post.</span>');
  }

  function requestClose() {
    if (!S) return;
    if (S.saving) return;
    if (S.dirty || S.uploads.some(function (u) { return u.state === 'uploading' || u.state === 'queued'; })) { showConfirm(true); return; }
    forceClose();
  }
  function showConfirm(on) {
    var c = $('[data-np-confirm]', R); if (!c) return;
    S.confirming = on;
    c.hidden = !on;
    if (on) { $('[data-np-keep]', c).focus(); } else { var b = $('[data-np-close]', R); if (b) b.focus(); }
  }
  function forceClose(after) {
    if (!S || !R) return;
    // Abort uploads still running; discard parked files nobody will claim.
    S.uploads.forEach(function (u) {
      if (u.ctl && u.state === 'uploading') { try { u.ctl.abort(); } catch (e) {} }
      if (u.token && !after) { try { postRaw(S.init && S.init.urls ? S.init.urls.upload : '', { action: 'claim_discard', token: u.token, client: S.slug }); } catch (e) {} }
      if (u.url) { try { URL.revokeObjectURL(u.url); } catch (e) {} }
    });
    var root = R, back = S.lastFocus;
    var detail = { postId: S.postId, mode: S.mode };
    S = null; R = null;
    root.classList.remove('is-visible');
    setTimeout(function () {
      if (root.parentNode) root.parentNode.removeChild(root);
      if (App.unlockScroll) App.unlockScroll();
      if (!after && back && back.focus && document.contains(back)) { try { back.focus({ preventScroll: true }); } catch (e) {} }
      document.dispatchEvent(new CustomEvent('newpost:close', { detail: detail }));
      if (after) after();
    }, App.reducedMotion && App.reducedMotion() ? 0 : 220);
  }
  function postRaw(url, params) {
    if (!url) return;
    var body = new URLSearchParams(); Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
    fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, body: body.toString() }).catch(function () {});
  }

  /* ------------------------------------------------------------------ */
  /* Events                                                              */
  /* ------------------------------------------------------------------ */
  function bind() {
    R.addEventListener('click', onClick);
    R.addEventListener('input', onInput);
    R.addEventListener('change', onChange);
    R.addEventListener('segmented:change', onSegmented);
    var q = $('[data-np-q]', R);
    q.addEventListener('input', debounce(function () { if (!S) return; S.f.q = q.value.trim(); fetchItems(false); }, 250));
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    bindTrayDrag($('[data-np-tray-list]', R));
    bindTrayKeys($('[data-np-tray-list]', R));
    bindGridKeys($('[data-np-grid]', R));
    var drop = $('[data-np-drop]', R), input = $('[data-np-file]', R);
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-dragover'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-dragover'); }); });
    drop.addEventListener('drop', function (e) { var f = e.dataTransfer && e.dataTransfer.files; if (f && f.length) addFiles(Array.prototype.slice.call(f)); });
    input.addEventListener('change', function () { addFiles(Array.prototype.slice.call(input.files || [])); input.value = ''; });
    var grid = $('[data-np-grid]', R);
    // A pointer pick keeps the focus where it is (the caption, once it has it) — keyboard users still Tab to tiles.
    grid.addEventListener('mousedown', function (e) { if (e.target.closest('[data-np-ref]')) e.preventDefault(); });
    grid.addEventListener('load', function (e) { if (e.target.tagName === 'IMG') e.target.closest('.np-tile') && e.target.closest('.np-tile').classList.add('is-loaded'); }, true);
  }

  // Esc + Tab are ours while the pop-up is open (capture on window, before app.js's sheet handlers).
  window.addEventListener('keydown', function (e) {
    if (!S || !R) return;
    if (e.key === 'Escape') {
      e.preventDefault(); e.stopImmediatePropagation();
      if (S.menuAt !== null) { closeMenu(true); return; }
      if (S.confirming) { showConfirm(false); return; }
      if (S.replaceAt !== null) { setReplace(null); return; }
      requestClose();
      return;
    }
    if (e.key === 'Tab') {
      e.stopImmediatePropagation();
      var scope = S.confirming ? $('[data-np-confirm]', R) : (S.menuAt !== null ? $('[data-np-menu]', R) : $('.np-panel', R));
      var items = $$('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', scope)
        .filter(function (el) { return el.offsetParent !== null || el === document.activeElement; });
      if (!items.length) { e.preventDefault(); return; }
      var first = items[0], last = items[items.length - 1];
      if (!scope.contains(document.activeElement)) { e.preventDefault(); first.focus(); return; }
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }, true);

  window.addEventListener('beforeunload', function (e) {
    if (S && S.dirty && !S.saving) { e.preventDefault(); e.returnValue = ''; return ''; }
  });

  function onClick(e) {
    var t = e.target;
    if (t.closest('[data-np-backdrop]') || t.closest('[data-np-close]')) { requestClose(); return; }
    if (t.closest('[data-np-keep]')) { showConfirm(false); return; }
    if (t.closest('[data-np-discard]')) { S.dirty = false; showConfirm(false); forceClose(); return; }
    var pc = t.closest('[data-np-pick-client]'); if (pc) { start(pc.getAttribute('data-np-pick-client')); return; }
    if (S && S.menuAt !== null && !t.closest('[data-np-menu]') && !t.closest('[data-np-slide]')) closeMenu(false);

    var tile = t.closest('[data-np-ref]');
    if (tile && tile.closest('[data-np-grid]')) { toggleItem(tile.getAttribute('data-np-ref')); if (e.detail > 0) captionNext(); return; }
    var rm = t.closest('[data-np-remove]');
    if (rm) { e.stopPropagation(); removeSlide(parseInt(rm.closest('[data-np-slide]').getAttribute('data-index'), 10)); return; }
    var mi = t.closest('[data-np-menu-act]'); if (mi) { menuAction(mi.getAttribute('data-np-menu-act')); return; }
    if (t.closest('[data-np-replace-cancel]')) { setReplace(null); return; }
    var chip = t.closest('[data-np-chip]'); if (chip) { onChip(chip); return; }
    if (t.closest('[data-np-more-btn]')) { fetchItems(true); return; }
    if (t.closest('[data-np-defaults]')) { applyDefaults(); return; }
    var act = t.closest('[data-np-save]'); if (act) { save(act.getAttribute('data-np-save')); return; }
    var up = t.closest('[data-np-up-remove]'); if (up) { removeUpload(up.closest('[data-np-up]').getAttribute('data-np-up')); return; }
    var goStep = t.closest('[data-np-goto]'); if (goStep) { setStep(goStep.getAttribute('data-np-goto')); var fe = field(goStep.getAttribute('data-np-focus') || ''); if (fe) fe.focus(); return; }
  }
  function onInput(e) {
    if (e.target.matches('[data-np-field]')) { markDirty(); clearErrors(e.target.getAttribute('data-np-field')); renderPreviewText(); if (e.target.matches('[data-np-field="caption"]')) renderCapCount(); }
  }
  function onChange(e) { if (e.target.matches('[data-np-field]')) { markDirty(); renderPreviewText(); } }
  function onSegmented(e) {
    var ctl = e.target.closest('.ui-segmented'); var v = e.detail && e.detail.item ? e.detail.item.getAttribute('data-value') : '';
    if (!ctl) return;
    if (ctl.matches('[data-np-source]')) setPane(v);
    else if (ctl.matches('[data-np-steps]')) setStep(v);
    else if (ctl.matches('[data-np-type]')) { $$('.ui-segmented-item', ctl).forEach(function (b) { b.setAttribute('aria-checked', b === e.detail.item ? 'true' : 'false'); }); markDirty(); renderPreviewText(); }
  }

  function setPane(p) {
    S.pane = p === 'upload' ? 'upload' : 'approved';
    $('[data-np-approved]', R).hidden = S.pane !== 'approved';
    $('[data-np-upload]', R).hidden = S.pane !== 'upload';
    $$('[data-np-source] .ui-segmented-item', R).forEach(function (b) { var on = b.getAttribute('data-value') === S.pane; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
  }
  function setStep(st) {
    S.step = st === 'details' ? 'details' : 'media';
    R.setAttribute('data-step', S.step);
    $$('[data-np-steps] .ui-segmented-item', R).forEach(function (b) { var on = b.getAttribute('data-value') === S.step; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    var body = $('[data-np-body]', R); if (body) body.scrollTop = 0;
    renderFooter();
    // Phones: entering Details with slides and no caption yet → the caption is the next thing to do
    if (S.step === 'details' && S.slides.length && !val('caption').trim() && !twoPane()) focusCaption();
  }
  function twoPane() { return !!(window.matchMedia && window.matchMedia('(min-width: 900px)').matches); }
  function focusCaption() {
    var c = field('caption'); if (!c || c.offsetParent === null) return;
    try { c.focus({ preventScroll: true }); } catch (e) { c.focus(); }
  }
  /** After a pointer pick (two panes): the caption takes the focus while it is still empty — unless the search box
   *  holds a query the user is still working with. Typing then goes straight into the caption (no extra click). */
  function captionNext() {
    if (!S || S.replaceAt !== null || !S.slides.length || !twoPane() || val('caption').trim()) return;
    var a = document.activeElement, q = $('[data-np-q]', R);
    if (a === q && q.value.trim()) return;
    focusCaption();
  }
  function setType(v) {
    var tg = $('[data-np-type]', R); if (!tg) return;
    $$('.ui-segmented-item', tg).forEach(function (b) { var on = b.getAttribute('data-value') === v; b.classList.toggle('is-active', on); b.setAttribute('aria-checked', on ? 'true' : 'false'); });
  }
  function typeValue() { var b = $('[data-np-type] .ui-segmented-item.is-active', R); return b ? b.getAttribute('data-value') : ''; }
  function applyDefaults() {
    var ta = field('hashtags'), defs = (S.client.default_hashtags || '').split(/\s+/).filter(Boolean);
    var have = {}; ta.value.split(/\s+/).filter(Boolean).forEach(function (x) { have[x.toLowerCase()] = 1; });
    var add = defs.filter(function (x) { return !have[x.toLowerCase()]; });
    if (!add.length) { toast('The client defaults are already there'); return; }
    ta.value = (ta.value.trim() ? ta.value.trim() + ' ' : '') + add.join(' ');
    markDirty(); renderPreviewText();
  }

  /* ------------------------------------------------------------------ */
  /* Picker (Approved)                                                   */
  /* ------------------------------------------------------------------ */
  var DIRECT_SERIES = 8;   // up to this many series chips (every tire together) show without picking a tire first
  function tiresOfSeries() { var out = []; S.f.series.forEach(function (k) { var t = parseInt(k.split(':')[0], 10); if (out.indexOf(t) === -1) out.push(t); }); return out; }
  /** Tires the grid is narrowed to: the tire chips plus the tire of every picked series chip. */
  function effTires() { var out = S.f.tires.slice(); tiresOfSeries().forEach(function (t) { if (out.indexOf(t) === -1) out.push(t); }); return out; }
  function seriesTires(fc) { return fc.tires.filter(function (t) { return t.series && t.series.length >= 2; }); }
  function directSeries(fc) {   // two panes only: on a phone the chip rows would push the grid off the first screen
    var n = 0; seriesTires(fc).forEach(function (t) { n += t.series.length; });
    return n > 0 && n <= DIRECT_SERIES && twoPane();
  }
  function renderGroups() {
    var fc = S.init.facets || { tires: [], library: 0, media: { image: 0, video: 0 } };
    var g = $('[data-np-groups]', R), html = '';
    var eff = effTires();
    var allOn = !eff.length && !S.f.lib;
    html += '<button type="button" class="np-chip" data-np-chip="all" aria-pressed="' + allOn + '">All</button>';
    fc.tires.forEach(function (t) {
      var on = eff.indexOf(t.id) !== -1;
      html += '<button type="button" class="np-chip" data-np-chip="tire" data-id="' + t.id + '" aria-pressed="' + on + '">' + esc(t.name) + ' <span class="np-chip-n">' + t.count + '</span></button>';
    });
    if (fc.library) html += '<button type="button" class="np-chip" data-np-chip="library" aria-pressed="' + S.f.lib + '">Library <span class="np-chip-n">' + fc.library + '</span></button>';
    g.innerHTML = html;
    // Series chips (key "<tire>:<ref|series id>"): every tire's when there are few (direct — one tap narrows to
    // that tire + series), else the selected tires' only.
    var sbox = $('[data-np-series]', R), sh = '', direct = directSeries(fc);
    var shown = seriesTires(fc).filter(function (t) { return direct || eff.indexOf(t.id) !== -1; });
    shown.forEach(function (t) {
      t.series.forEach(function (s) {
        var key = t.id + ':' + s.key, on = S.f.series.indexOf(key) !== -1;
        sh += '<button type="button" class="np-chip np-chip--sm" data-np-chip="series" data-key="' + esc(key) + '" aria-pressed="' + on + '">'
            + (shown.length > 1 ? '<span class="np-chip-pre">' + esc(t.name) + ' · </span>' : '') + esc(s.name) + ' <span class="np-chip-n">' + s.count + '</span></button>';
      });
    });
    sbox.innerHTML = sh; sbox.hidden = !sh;
    var mbox = $('[data-np-media]', R);
    if (fc.media && fc.media.video > 0) {
      mbox.hidden = false;
      mbox.innerHTML = [['all', 'All'], ['image', 'Photos'], ['video', 'Videos']].map(function (m) {
        return '<button type="button" class="np-chip np-chip--sm" data-np-chip="media" data-v="' + m[0] + '" aria-pressed="' + (S.f.media === m[0]) + '">' + m[1] + (m[0] !== 'all' ? ' <span class="np-chip-n">' + fc.media[m[0]] + '</span>' : '') + '</button>';
      }).join('');
    } else mbox.hidden = true;
  }
  function onChip(chip) {
    var kind = chip.getAttribute('data-np-chip');
    if (kind === 'all') { S.f.tires = []; S.f.lib = false; S.f.series = []; }
    else if (kind === 'tire') {
      // pressed (by its chip or one of its series) → off, with its series; else on
      var id = parseInt(chip.getAttribute('data-id'), 10), i = S.f.tires.indexOf(id);
      if (effTires().indexOf(id) === -1) S.f.tires.push(id);
      else { if (i !== -1) S.f.tires.splice(i, 1); S.f.series = S.f.series.filter(function (k) { return k.split(':')[0] !== String(id); }); }
    } else if (kind === 'library') S.f.lib = !S.f.lib;
    else if (kind === 'series') { var k = chip.getAttribute('data-key'), j = S.f.series.indexOf(k); if (j === -1) S.f.series.push(k); else S.f.series.splice(j, 1); }
    else if (kind === 'media') S.f.media = chip.getAttribute('data-v');
    renderGroups();
    var again = $('[data-np-chip="' + kind + '"]' + (chip.getAttribute('data-id') ? '[data-id="' + chip.getAttribute('data-id') + '"]' : '') + (chip.getAttribute('data-key') ? '[data-key="' + chip.getAttribute('data-key') + '"]' : '') + (chip.getAttribute('data-v') ? '[data-v="' + chip.getAttribute('data-v') + '"]' : ''), R);
    if (again) again.focus();
    fetchItems(false);
  }
  function fetchItems(more) {
    if (!S || !S.slug) return Promise.resolve();
    var id = ++S.reqId;
    var params = { tires: effTires(), library: S.f.lib ? 1 : '', series: S.f.series, media: S.f.media === 'all' ? '' : S.f.media, q: S.f.q, offset: more ? (S.next || 0) : 0 };
    S.loading = true;
    var grid = $('[data-np-grid]', R), btn = $('[data-np-more-btn]', R);
    if (!more) grid.setAttribute('aria-busy', 'true');
    if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
    return getJson(S.slug, 'picker', params).then(function (res) {
      if (!S || id !== S.reqId) return;
      S.loading = false;
      grid.removeAttribute('aria-busy');
      if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); }
      if (!res.ok) { toast(res.data.error || 'Could not load images', 'error'); return; }
      if (!more) { S.items = []; grid.innerHTML = ''; S.lastHead = ''; grid.scrollTop = 0; }
      S.items = S.items.concat(res.data.items);
      S.next = res.data.next; S.total = res.data.total;
      grid.insertAdjacentHTML('beforeend', tilesHtml(res.data.items));
      if (App.video && App.video.enhance) App.video.enhance(grid);
      var empty = $('[data-np-empty]', R);
      empty.hidden = S.items.length > 0;
      empty.textContent = S.f.q || effTires().length || S.f.lib || S.f.media !== 'all' ? 'Nothing approved matches — clear a filter or try another name.' : 'No approved images yet. Approve images in Assets, or switch to Upload.';
      var moreBox = $('[data-np-more]', R); moreBox.hidden = S.next === null || S.next === undefined;
      var n = $('[data-np-more-n]', R); if (n) n.textContent = (S.total - S.items.length) + ' more';
      syncTiles();
    });
  }
  function itemByRef(ref) { for (var i = 0; i < S.items.length; i++) if (S.items[i].ref === ref) return S.items[i]; return null; }
  function tilesHtml(items) {
    var out = '';
    items.forEach(function (it) {
      var head = it.group + '|' + it.series;
      if (head !== S.lastHead) {
        S.lastHead = head;
        out += '<div class="np-grid-head" role="presentation">' + esc(it.group_label) + (it.series_label ? ' <span class="text-secondary">· ' + esc(it.series_label) + '</span>' : '') + '</div>';
      }
      var isV = it.media === 'video';
      out += '<button type="button" class="np-tile' + (isV ? ' np-tile--video' : '') + '" role="option" aria-selected="false" data-np-ref="' + esc(it.ref) + '" title="' + esc(it.label + ' — ' + it.group_label + (it.series_label ? ' · ' + it.series_label : '')) + '">'
           + (isV ? (it.thumb && it.thumb !== it.src ? '<img src="' + esc(it.thumb) + '" alt="" loading="lazy" decoding="async">' : '') + '<span class="np-tile-play">' + I.play + '</span>'
                  : '<img src="' + esc(it.thumb) + '" alt="' + esc(it.label) + '" loading="lazy" decoding="async"' + (it.w ? ' width="' + it.w + '" height="' + it.h + '"' : '') + '>')
           + '<span class="np-tile-order" aria-hidden="true"></span>'
           + '<span class="np-tile-label">' + esc(it.label) + '</span>'
           + '</button>';
    });
    return out;
  }
  function slideIndexByRef(ref) { for (var i = 0; i < S.slides.length; i++) if (S.slides[i].ref === ref || S.slides[i].src_ref === ref) return i; return -1; }
  function syncTiles() {
    $$('[data-np-grid] [data-np-ref]', R).forEach(function (tile) {
      var i = slideIndexByRef(tile.getAttribute('data-np-ref'));
      tile.classList.toggle('is-selected', i !== -1);
      tile.setAttribute('aria-selected', i !== -1 ? 'true' : 'false');
      var o = $('.np-tile-order', tile); if (o) o.innerHTML = i === -1 ? '' : (i + 1);
    });
  }
  function bindGridKeys(grid) {
    grid.addEventListener('keydown', function (e) {
      var tile = e.target.closest('[data-np-ref]'); if (!tile) return;
      var tiles = $$('[data-np-ref]', grid), i = tiles.indexOf(tile);
      var cols = Math.max(1, Math.round(grid.clientWidth / Math.max(1, tile.offsetWidth)));
      var next = { ArrowLeft: i - 1, ArrowRight: i + 1, ArrowUp: i - cols, ArrowDown: i + cols }[e.key];
      if (next !== undefined && tiles[next]) { e.preventDefault(); tiles[next].focus(); }
    });
  }

  function slideFromItem(it) {
    return { uid: ++uid, ref: it.ref, src_ref: it.ref, media: it.media, thumb: it.thumb, large: it.large, src: it.src, w: it.w, h: it.h, label: it.label + (it.series_label ? ' · ' + it.series_label : '') };
  }
  function toggleItem(ref) {
    var it = itemByRef(ref); if (!it) return;
    if (S.replaceAt !== null) { replaceSlide(S.replaceAt, slideFromItem(it)); return; }
    var i = slideIndexByRef(ref);
    if (i !== -1) { S.slides.splice(i, 1); live('Removed ' + it.label + '. ' + S.slides.length + ' slides.'); }
    else {
      if (S.slides.length >= MAX) { toast('Up to ' + MAX + ' slides per post', 'error'); return; }
      S.slides.push(slideFromItem(it));
      live('Added ' + it.label + ' as slide ' + S.slides.length + '.');
    }
    markDirty(); clearErrors('slides'); renderAll();
    if (S.slides.length && i === -1) scrollTrayTo(S.slides.length - 1);
  }
  /** Preselected refs (Assets selection, viewer "Use in post", deep links): fetched by ref, added in order.
   *  Files the Upload sheet parked (upload-chunk.php purpose=post) come as {ref: 'upload:<token>', media, thumb, name}
   *  (or the bare 'upload:<token>' string): they join as finished uploads — removing one discards its claim. */
  function addRefs(refs) {
    var list = (refs || []).map(function (r) {
      if (r && typeof r === 'object') return /^(?:upload|claim):[a-f0-9]{32}$/.test(String(r.ref || '')) ? r : null;
      r = String(r || '');
      if (/^(tire|library):\d+$/.test(r)) return r;
      return /^(?:upload|claim):[a-f0-9]{32}$/.test(r) ? { ref: r } : null;
    }).filter(Boolean);
    if (!list.length) return Promise.resolve();
    var assets = list.filter(function (r) { return typeof r === 'string'; });
    var got = assets.length ? getJson(S.slug, 'picker', { refs: assets.join(','), limit: MAX }) : Promise.resolve({ ok: true, data: { items: [] } });
    return got.then(function (res) {
      if (!S) return;
      if (!res.ok) { toast(res.data.error || 'Could not add those images', 'error'); return; }
      var byRef = {}, added = 0, missing = 0;
      res.data.items.forEach(function (it) { byRef[it.ref] = it; });
      list.forEach(function (r) {
        if (S.slides.length >= MAX) return;
        if (typeof r === 'string') {
          var it = byRef[r];
          if (!it) { missing++; return; }
          if (slideIndexByRef(it.ref) !== -1) return;
          S.slides.push(slideFromItem(it)); added++;
          return;
        }
        var token = String(r.ref).split(':')[1], ref = 'upload:' + token;
        if (slideIndexByRef(ref) !== -1) return;
        var media = r.media === 'video' ? 'video' : 'image', name = r.name || (media === 'video' ? 'video' : 'image');
        var u = { id: 'u' + (++uid), file: { name: name }, state: 'done', pct: 100, token: token, url: media === 'image' ? (r.thumb || '') : '', media: media, error: '' };
        S.uploads.push(u);
        S.slides.push({ uid: ++uid, ref: ref, upload: u.id, media: media, thumb: r.thumb || '', large: r.thumb || '', src: r.thumb || '', name: name, label: name });
        added++;
      });
      if (missing) toast(missing + ' of the selected images are not approved — left out', 'error', 4000);
      if (added) markDirty();
      renderAll(); renderUploads();
      if (added) captionNext();   // "Upload & make post" / Assets selection: typing goes straight into the caption
    });
  }

  /* ------------------------------------------------------------------ */
  /* Tray                                                                */
  /* ------------------------------------------------------------------ */
  function aspect(s) { return s && s.w && s.h ? s.w / s.h : null; }
  function shapeOff(i) {
    if (i === 0) return false;
    var a0 = aspect(S.slides[0]), a = aspect(S.slides[i]);
    return !!(a0 && a && Math.abs(a / a0 - 1) > SHAPE_TOLERANCE);
  }
  function renderTray() {
    var list = $('[data-np-tray-list]', R), n = S.slides.length;
    var focusUid = document.activeElement && document.activeElement.closest && document.activeElement.closest('[data-np-slide]') ? document.activeElement.closest('[data-np-slide]').getAttribute('data-uid') : null;
    list.innerHTML = S.slides.map(function (s, i) {
      var off = shapeOff(i), isV = s.media === 'video';
      var thumb = s.thumb && !(isV && s.thumb === s.src) ? '<img src="' + esc(s.thumb) + '" alt="" decoding="async" draggable="false" data-np-measure="' + s.uid + '">' : '';
      return '<li class="np-slide' + (isV ? ' np-slide--video' : '') + (off ? ' is-shape-off' : '') + (s.uploading ? ' is-uploading' : '') + (s.failed ? ' is-failed' : '') + (S.replaceAt === i ? ' is-replacing' : '') + '"'
        + ' role="option" aria-selected="false" tabindex="' + (i === 0 ? '0' : '-1') + '" data-np-slide data-index="' + i + '" data-uid="' + s.uid + '"'
        + ' aria-label="' + esc('Slide ' + (i + 1) + ' of ' + n + (i === 0 ? ', cover' : '') + ', ' + (s.label || (isV ? 'video' : 'image')) + (off ? ', different shape from the cover' : '') + (s.uploading ? ', uploading' : '')) + '. Enter for options, Alt and arrow keys to move, Delete to remove.">'
        + '<span class="np-slide-media">' + thumb + (isV ? '<span class="np-slide-play">' + I.play + '</span>' : '') + '</span>'
        + '<span class="np-slide-badge' + (i === 0 ? ' np-slide-badge--cover' : '') + '">' + (i === 0 ? 'Cover' : (i + 1)) + '</span>'
        + (off ? '<span class="np-slide-warn" title="Different shape from the cover — Instagram crops every slide to slide 1\'s shape">' + I.warn + '</span>' : '')
        + (s.uploading ? '<span class="np-slide-progress" aria-hidden="true"><span style="transform:translateX(' + ((s.pct || 0) - 100) + '%)"></span></span>' : '')
        + '<button type="button" class="np-slide-x" data-np-remove tabindex="-1" aria-label="Remove slide ' + (i + 1) + '">' + I.x + '</button>'
        + '</li>';
    }).join('');
    $('[data-np-tray-empty]', R).hidden = n > 0;
    $('[data-np-tray-hint]', R).hidden = n < 2;
    var c = $('[data-np-count]', R); c.textContent = n + ' / ' + MAX; c.classList.toggle('is-full', n >= MAX);
    // measure images without dims (uploads, older rows) → shape warning
    $$('img[data-np-measure]', list).forEach(function (img) {
      var s = slideByUid(parseInt(img.getAttribute('data-np-measure'), 10));
      if (!s || (s.w && s.h)) return;
      var fn = function () { if (img.naturalWidth && S) { s.w = img.naturalWidth; s.h = img.naturalHeight; renderTray(); } };
      if (img.complete && img.naturalWidth) setTimeout(fn, 0); else img.addEventListener('load', fn, { once: true });
    });
    if (focusUid) { var f = $('[data-uid="' + focusUid + '"]', list); if (f) { f.tabIndex = 0; f.focus(); } }
    var warnN = 0; for (var k = 1; k < n; k++) if (shapeOff(k)) warnN++;
    var hint = $('[data-np-tray-hint]', R);
    if (warnN) { hint.hidden = false; hint.innerHTML = '<span class="np-warn-text">' + I.warn + warnN + ' slide' + (warnN === 1 ? ' has' : 's have') + ' a different shape from the cover — Instagram crops to slide 1</span>'; }
    else hint.textContent = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches ? 'Hold and drag to reorder' : 'Drag to reorder') + ' · slide 1 is the cover';
  }
  function slideByUid(u) { for (var i = 0; i < S.slides.length; i++) if (S.slides[i].uid === u) return S.slides[i]; return null; }
  function scrollTrayTo(i) {
    var list = $('[data-np-tray-list]', R), li = list && list.children[i];
    if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: App.reducedMotion && App.reducedMotion() ? 'auto' : 'smooth' });
  }
  function move(from, to) {
    if (from === to || to < 0 || to >= S.slides.length) return;
    var s = S.slides.splice(from, 1)[0];
    S.slides.splice(to, 0, s);
    markDirty(); renderAll();
    live('Moved to position ' + (to + 1) + (to === 0 ? ', now the cover' : '') + '.');
    var li = $('[data-np-tray-list] [data-uid="' + s.uid + '"]', R); if (li) { $$('[data-np-slide]', R).forEach(function (x) { x.tabIndex = -1; }); li.tabIndex = 0; li.focus(); }
  }
  function removeSlide(i) {
    var s = S.slides[i]; if (!s) return;
    S.slides.splice(i, 1);
    if (s.upload) { var u = uploadById(s.upload); if (u) cancelUpload(u, true); }
    if (S.replaceAt !== null) setReplace(null);
    markDirty(); renderAll();
    live('Removed slide ' + (i + 1) + '. ' + S.slides.length + ' left.');
    var list = $('[data-np-tray-list]', R), next = list.children[Math.min(i, list.children.length - 1)];
    if (next) { next.tabIndex = 0; next.focus(); } else { var q = $('[data-np-q]', R); if (q && !$('[data-np-approved]', R).hidden) q.focus(); }
  }
  function replaceSlide(i, slide) {
    var old = S.slides[i]; if (!old) { setReplace(null); return; }
    if (slideIndexByRef(slide.ref) !== -1 && slide.ref) { toast('That image is already a slide', 'error'); return; }
    S.slides[i] = slide;
    if (old.upload) { var u = uploadById(old.upload); if (u) cancelUpload(u, true); }
    setReplace(null);
    markDirty(); renderAll();
    live('Replaced slide ' + (i + 1) + '.');
  }
  function setReplace(i) {
    S.replaceAt = i;
    var box = $('[data-np-replace]', R);
    box.hidden = i === null;
    if (i !== null) {
      $('[data-np-replace-text]', R).textContent = 'Pick an approved image or upload a file to replace slide ' + (i + 1) + '.';
      if (S.step !== 'media') setStep('media');
    }
    renderTray();
  }

  // Slide menu: Move left / right, Make cover, Replace, Remove
  function openMenu(i) {
    var li = $('[data-np-tray-list]', R).children[i]; if (!li) return;
    S.menuAt = i;
    var m = $('[data-np-menu]', R), n = S.slides.length;
    m.innerHTML = '<button type="button" role="menuitem" data-np-menu-act="left"' + (i === 0 ? ' disabled' : '') + '>Move left</button>'
      + '<button type="button" role="menuitem" data-np-menu-act="right"' + (i >= n - 1 ? ' disabled' : '') + '>Move right</button>'
      + '<button type="button" role="menuitem" data-np-menu-act="cover"' + (i === 0 ? ' disabled' : '') + '>Make cover</button>'
      + '<button type="button" role="menuitem" data-np-menu-act="replace">Replace…</button>'
      + '<button type="button" role="menuitem" class="is-destructive" data-np-menu-act="remove">Remove</button>';
    m.hidden = false;
    var pr = $('.np-panel', R).getBoundingClientRect(), r = li.getBoundingClientRect();
    var left = Math.max(8, Math.min(r.left - pr.left, pr.width - 188));
    m.style.left = left + 'px'; m.style.top = (r.bottom - pr.top + 6) + 'px';
    li.setAttribute('aria-expanded', 'true');
    var first = $('button:not([disabled])', m); if (first) first.focus();
    m.onkeydown = function (e) {
      var items = $$('button:not([disabled])', m), k = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); (items[k + 1] || items[0]).focus(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); (items[k - 1] || items[items.length - 1]).focus(); }
    };
  }
  function closeMenu(refocus) {
    if (S.menuAt === null) return;
    var i = S.menuAt; S.menuAt = null;
    var m = $('[data-np-menu]', R); m.hidden = true; m.innerHTML = '';
    var li = $('[data-np-tray-list]', R).children[i]; if (li) { li.removeAttribute('aria-expanded'); if (refocus) li.focus(); }
  }
  function menuAction(act) {
    var i = S.menuAt; closeMenu(false);
    if (i === null) return;
    if (act === 'left') move(i, i - 1);
    else if (act === 'right') move(i, i + 1);
    else if (act === 'cover') move(i, 0);
    else if (act === 'remove') removeSlide(i);
    else if (act === 'replace') { setReplace(i); var q = $('[data-np-q]', R); if (q && S.pane === 'approved') q.focus(); }
  }

  function bindTrayKeys(list) {
    list.addEventListener('keydown', function (e) {
      var li = e.target.closest('[data-np-slide]'); if (!li) return;
      var i = parseInt(li.getAttribute('data-index'), 10), n = S.slides.length;
      if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && (e.altKey || e.metaKey || e.ctrlKey)) { e.preventDefault(); move(i, i + (e.key === 'ArrowLeft' ? -1 : 1)); return; }
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End') {
        e.preventDefault();
        var to = e.key === 'Home' ? 0 : e.key === 'End' ? n - 1 : i + (e.key === 'ArrowLeft' ? -1 : 1);
        var t = list.children[Math.max(0, Math.min(n - 1, to))];
        if (t) { $$('[data-np-slide]', list).forEach(function (x) { x.tabIndex = -1; }); t.tabIndex = 0; t.focus(); }
        return;
      }
      if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); removeSlide(i); return; }
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openMenu(i); }
    });
  }

  /* Drag to reorder — Pointer Events. Mouse / pen: drag after 5 px; touch: long-press (250 ms) first so a
     swipe still scrolls the tray. A press without a drag opens the slide menu. */
  function bindTrayDrag(list) {
    var d = null, timer = null;
    function slots() { return $$('[data-np-slide]', list).map(function (el) { var r = el.getBoundingClientRect(); return r.left + r.width / 2; }); }
    function begin() {
      d.on = true; d.centers = slots();
      d.li.classList.add('is-dragging'); list.classList.add('is-dragging');
      try { d.li.setPointerCapture(d.id); } catch (e) {}
      if (navigator.vibrate && d.touch) { try { navigator.vibrate(8); } catch (e) {} }
    }
    list.addEventListener('pointerdown', function (e) {
      if (!S || e.target.closest('[data-np-remove]')) return;
      var li = e.target.closest('[data-np-slide]'); if (!li || (e.pointerType === 'mouse' && e.button !== 0)) return;
      d = { li: li, id: e.pointerId, x0: e.clientX, y0: e.clientY, from: parseInt(li.getAttribute('data-index'), 10), to: null, on: false, touch: e.pointerType === 'touch', moved: false };
      d.to = d.from;
      if (d.touch) timer = setTimeout(function () { if (d && !d.moved) begin(); }, 250);
    });
    list.addEventListener('touchmove', function (e) { if (d && d.on) e.preventDefault(); }, { passive: false });
    list.addEventListener('pointermove', function (e) {
      if (!d || e.pointerId !== d.id) return;
      var dx = e.clientX - d.x0, dy = e.clientY - d.y0;
      if (!d.on) {
        if (Math.abs(dx) + Math.abs(dy) > 5) { d.moved = true; if (!d.touch) begin(); else { clearTimeout(timer); d = null; } }
        return;
      }
      e.preventDefault();
      d.li.style.transform = 'translate(' + dx + 'px,' + (dy * 0.25) + 'px) scale(1.06)';
      var to = d.from;
      for (var k = 0; k < d.centers.length; k++) {
        if (k < d.from && e.clientX < d.centers[k]) { to = k; break; }
        if (k > d.from && e.clientX > d.centers[k]) to = k;
      }
      d.to = to;
      $$('[data-np-slide]', list).forEach(function (el, k) {
        el.classList.toggle('is-shift-right', k >= to && k < d.from);
        el.classList.toggle('is-shift-left', k <= to && k > d.from);
      });
      // auto-scroll near the edges
      var r = list.getBoundingClientRect();
      if (e.clientX < r.left + 30) list.scrollLeft -= 12; else if (e.clientX > r.right - 30) list.scrollLeft += 12;
    });
    function end(e) {
      clearTimeout(timer);
      if (!d || e.pointerId !== d.id) return;
      var x = d; d = null;
      x.li.style.transform = '';
      x.li.classList.remove('is-dragging'); list.classList.remove('is-dragging');
      $$('[data-np-slide]', list).forEach(function (el) { el.classList.remove('is-shift-left', 'is-shift-right'); });
      if (x.on) { if (e.type !== 'pointercancel' && x.to !== x.from) move(x.from, x.to); return; }
      if (!x.moved && e.type === 'pointerup' && !e.target.closest('[data-np-remove]')) openMenu(x.from);
    }
    list.addEventListener('pointerup', end);
    list.addEventListener('pointercancel', end);
    list.addEventListener('contextmenu', function (e) { if (e.target.closest('[data-np-slide]')) e.preventDefault(); });
  }

  /* ------------------------------------------------------------------ */
  /* Upload (chunked, purpose=post → claim tokens)                       */
  /* ------------------------------------------------------------------ */
  function uploadById(id) { for (var i = 0; i < S.uploads.length; i++) if (S.uploads[i].id === id) return S.uploads[i]; return null; }
  function addFiles(files) {
    if (!files.length) return;
    var room = MAX - S.slides.length + (S.replaceAt !== null ? 1 : 0);
    if (!App.chunkUpload || !App.chunkUpload.upload) { toast('Uploads need chunk-upload.js — reload the page', 'error'); return; }
    var capImg = (cfg.maxImageMb || 50) * 1048576, capVid = (cfg.maxVideoMb || 4096) * 1048576;
    var skipped = 0;
    files.forEach(function (f) {
      var ext = fileExt(f.name), vid = isVideoFile(f);
      if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov'].indexOf(ext) === -1) { toast(f.name + ': unsupported type (JPG, PNG, GIF, WebP, MP4, WebM, MOV)', 'error', 4000); return; }
      if (f.size > (vid ? capVid : capImg)) { toast(f.name + ' is over ' + (vid ? Math.round(capVid / 1073741824) + ' GB' : Math.round(capImg / 1048576) + ' MB'), 'error', 4000); return; }
      if (room <= 0) { skipped++; return; }
      room--;
      var u = { id: 'u' + (++uid), file: f, state: 'queued', pct: 0, token: null, url: vid ? '' : URL.createObjectURL(f), media: vid ? 'video' : 'image', error: '' };
      S.uploads.push(u);
      var slide = { uid: ++uid, ref: null, upload: u.id, media: u.media, thumb: u.url, large: u.url, src: u.url, local: true, name: f.name, label: f.name, uploading: true, pct: 0 };
      if (S.replaceAt !== null) {
        var old = S.slides[S.replaceAt];
        S.slides[S.replaceAt] = slide;
        if (old && old.upload) { var ou = uploadById(old.upload); if (ou) cancelUpload(ou, true); }
        setReplace(null);
      } else S.slides.push(slide);
      if (vid && /quicktime/i.test(f.type || '') || ext === 'mov') toast(f.name + ': .MOV plays inline in Safari only — Chrome and Firefox show an Open / Download card', null, 5000);
    });
    if (skipped) toast(skipped + ' file' + (skipped === 1 ? '' : 's') + ' left out — up to ' + MAX + ' slides per post', 'error', 4000);
    markDirty(); clearErrors('slides'); renderAll(); renderUploads();
    captionNext();   // an inline upload (two panes): the caption is the next thing to do
    pump();
  }
  function pump() {
    if (!S || S.busyUpload) return;
    var u = S.uploads.filter(function (x) { return x.state === 'queued'; })[0];
    if (!u) { renderFooter(); return; }
    S.busyUpload = true; u.state = 'uploading';
    var slug = S.slug;
    u.ctl = App.chunkUpload.upload({
      endpoint: S.init.urls.upload, file: u.file,
      fields: { purpose: 'post', client: slug, actor: 'admin' },
      onProgress: function (p) { u.pct = p.pct; u.text = p.text; syncUpload(u); }
    });
    renderUploads(); renderFooter();
    u.ctl.promise.then(function (data) {
      if (!S || S.slug !== slug) return;
      u.state = 'done'; u.token = data.token; u.pct = 100;
      var s = slideByUpload(u.id);
      if (s) { s.ref = 'upload:' + data.token; s.uploading = false; if (!s.thumb && data.preview_url && u.media === 'image') { s.thumb = s.large = data.preview_url; } }
    }, function (e) {
      if (!S) return;
      if (e && e.aborted) { u.state = 'removed'; return; }
      u.state = 'failed'; u.error = (e && e.error) || 'Upload failed';
      var s = slideByUpload(u.id); if (s) { s.uploading = false; s.failed = true; }
      toast(u.file.name + ': ' + u.error, 'error', 5000);
    }).then(function () {
      if (!S) return;
      S.busyUpload = false; u.ctl = null;
      renderAll(); renderUploads(); pump();
    });
  }
  function slideByUpload(id) { for (var i = 0; i < S.slides.length; i++) if (S.slides[i].upload === id) return S.slides[i]; return null; }
  function syncUpload(u) {
    var row = $('[data-np-up="' + u.id + '"]', R);
    if (row) { var fill = $('.np-up-fill', row); if (fill) fill.style.transform = 'translateX(' + (u.pct - 100) + '%)'; var st = $('.np-up-status', row); if (st) st.textContent = 'Uploading… ' + (u.text || u.pct + '%'); }
    var s = slideByUpload(u.id); if (s) { s.pct = u.pct; var li = $('[data-np-tray-list] [data-uid="' + s.uid + '"] .np-slide-progress span', R); if (li) li.style.transform = 'translateX(' + (u.pct - 100) + '%)'; }
    renderFooter();
  }
  function cancelUpload(u, fromSlide) {
    if (u.state === 'uploading' && u.ctl) { try { u.ctl.abort(); } catch (e) {} }
    if (u.state === 'done' && u.token) postRaw(S.init.urls.upload, { action: 'claim_discard', token: u.token, client: S.slug });
    if (u.state === 'queued') u.state = 'removed';
    if (u.state !== 'uploading') u.state = 'removed';
    if (!fromSlide) { var s = slideByUpload(u.id); if (s) S.slides.splice(S.slides.indexOf(s), 1); }
    S.uploads = S.uploads.filter(function (x) { return x !== u; });
    if (u.url) { var keep = S.slides.some(function (s) { return s.thumb === u.url; }); if (!keep) try { URL.revokeObjectURL(u.url); } catch (e) {} }
    renderUploads(); renderFooter();
  }
  function removeUpload(id) { var u = uploadById(id); if (!u) return; cancelUpload(u, false); markDirty(); renderAll(); }
  function renderUploads() {
    var ul = $('[data-np-uplist]', R); if (!ul) return;
    ul.innerHTML = S.uploads.map(function (u) {
      var status = u.state === 'done' ? 'Uploaded — in the slides above' : u.state === 'failed' ? u.error : u.state === 'queued' ? 'Waiting…' : 'Uploading… ' + (u.text || u.pct + '%');
      return '<li class="np-up' + (u.state === 'failed' ? ' is-failed' : '') + (u.state === 'done' ? ' is-done' : '') + '" data-np-up="' + u.id + '">'
        + '<span class="np-up-thumb">' + (u.url ? '<img src="' + esc(u.url) + '" alt="">' : I.play) + '</span>'
        + '<span class="np-up-body"><span class="np-up-name">' + esc(u.file.name) + '</span>'
        + '<span class="np-up-bar"' + (u.state === 'uploading' || u.state === 'queued' ? '' : ' hidden') + '><span class="np-up-fill" style="transform:translateX(' + (u.pct - 100) + '%)"></span></span>'
        + '<span class="np-up-status text-secondary">' + esc(status) + '</span></span>'
        + '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-np-up-remove aria-label="' + (u.state === 'uploading' ? 'Cancel' : 'Remove') + ' ' + esc(u.file.name) + '">' + (u.state === 'uploading' || u.state === 'queued' ? 'Cancel' : 'Remove') + '</button></li>';
    }).join('');
  }
  function uploadsBusy() { return S.uploads.some(function (u) { return u.state === 'uploading' || u.state === 'queued'; }); }

  /* ------------------------------------------------------------------ */
  /* Preview + footer                                                    */
  /* ------------------------------------------------------------------ */
  function formatLabel() {
    var n = S.slides.length;
    if (n >= 2) return 'Carousel · ' + n + ' slides';
    if (n === 1 && S.slides[0].media === 'video') return 'Video';
    return 'Image';
  }
  function renderPreviewText() {
    if (!R) return;
    var cap = val('caption'), tags = val('hashtags').trim();
    $('[data-np-preview-caption]', R).innerHTML = '<span class="ig-name ig-name--inline">' + esc(S.client ? S.client.name : '') + '</span> ' + linkTags(cap);
    var t = $('[data-np-preview-tags]', R); t.innerHTML = linkTags(tags); t.hidden = !tags;
    $('[data-np-preview-date]', R).textContent = formatWhen(val('scheduled_date'));
    var tv = typeValue(), lbl = formatLabel();
    if (tv) { var tt = (S.init.types || []).filter(function (x) { return x.value === tv; })[0]; lbl = (tt ? tt.label : tv) + (S.slides.length >= 2 ? ' · ' + S.slides.length + ' slides' : ''); }
    $('[data-np-format]', R).textContent = lbl;
    var st = $('[data-np-status]', R), status = S.post ? S.post.status : 'draft';
    st.className = 'ui-pill pd-pill ' + (status === 'draft' ? 'ui-pill--neutral' : 'ui-pill--' + (S.post && S.post.posted ? 'scheduled' : status));
    st.textContent = status === 'draft' ? 'Draft' : (App.status ? App.status.label(status, S.post && S.post.posted) : status);
    $('[data-np-preview-sub]', R).textContent = 'what ' + (S.client ? S.client.name : 'the client') + ' will see';
  }
  function renderCapCount() {
    var n = val('caption').length, el = $('[data-np-cap-count]', R);
    el.textContent = n.toLocaleString() + ' / 2,200';
    el.classList.toggle('is-over', n > 2200);
    el.title = n > 2200 ? 'Instagram cuts captions after 2,200 characters' : '';
  }
  function renderPreviewMedia() {
    var key = S.slides.map(function (s) { return s.uid + ':' + (s.large || '') + (s.uploading ? 'u' : ''); }).join('|');
    if (key === S.previewKey) return;
    S.previewKey = key;
    var box = $('[data-np-preview-media]', R), car = $('[data-carousel]', box);
    var at = car && App.carousel ? App.carousel.index(car) : 0;
    var items = S.slides.map(function (s) { return { src: s.src, large: s.large || s.src, thumb: s.thumb, media: s.media, local: s.local, name: s.name }; });
    box.innerHTML = App.carousel ? App.carousel.markup(items, { label: (S.client ? S.client.name : '') + ' post', empty: 'Slides you pick show here', autoplay: false })
                                 : '<div class="pd-media pd-media--empty"><span class="text-tertiary">Preview</span></div>';
    if (App.carousel) { App.carousel.init(box); var nc = $('[data-carousel]', box); if (nc && at) App.carousel.go(nc, Math.min(at, items.length - 1), false); }
    if (App.video && App.video.enhance) App.video.enhance(box);
  }
  function renderFooter() {
    if (!R) return;
    var n = S.slides.length;
    $('[data-np-foot-count]', R).textContent = n ? n + (n === 1 ? ' slide' : ' slides') : 'No slides yet';
    var busy = uploadsBusy(), acts = $('[data-np-actions]', R);
    var st = S.post ? S.post.status : null, draftOk = S.init && S.init.draft;
    var html = '';
    if (S.mode === 'create' || st === 'draft') {
      // "Save draft" only where the Draft state exists (migrate.php step 35) — never a client-visible fallback
      if (draftOk) html += '<button type="button" class="ui-btn ui-btn--tinted" data-np-save="draft"' + (busy || S.saving ? ' disabled' : '') + '>Save draft</button>';
      html += '<button type="button" class="ui-btn ui-btn--filled" data-np-save="review"' + (busy || S.saving ? ' disabled' : '') + '>' + (busy ? 'Uploading…' : 'Send for review') + '</button>';
    } else if (st === 'denied') {
      html += '<button type="button" class="ui-btn ui-btn--tinted" data-np-save="keep"' + (busy || S.saving ? ' disabled' : '') + '>Save</button>';
      html += '<button type="button" class="ui-btn ui-btn--filled" data-np-save="review"' + (busy || S.saving ? ' disabled' : '') + '>' + (busy ? 'Uploading…' : 'Send for review') + '</button>';
    } else {
      html += '<button type="button" class="ui-btn ui-btn--filled" data-np-save="keep"' + (busy || S.saving ? ' disabled' : '') + '>' + (busy ? 'Uploading…' : 'Save changes') + '</button>';
    }
    if (acts.getAttribute('data-html') !== html) { acts.innerHTML = html; acts.setAttribute('data-html', html); }
    $('[data-np-steps]', R).hidden = false;
  }
  function renderAll() {
    if (!R || !S) return;
    renderTray(); syncTiles(); renderPreviewMedia(); renderPreviewText(); renderCapCount(); renderFooter();
  }

  /* ------------------------------------------------------------------ */
  /* Validation + save                                                   */
  /* ------------------------------------------------------------------ */
  function clearErrors(name) {
    if (!R) return;
    var list = name ? [name] : ['caption', 'scheduled_date', 'slides'];
    list.forEach(function (k) {
      var el = $('[data-np-err="' + k + '"]', R); if (el) { el.hidden = true; el.textContent = ''; }
      var f = field(k); if (f) f.removeAttribute('aria-invalid');
    });
    if (name === 'slides') $('[data-np-tray]', R).classList.remove('is-invalid');
    var fe = $('[data-np-foot-error]', R); if (fe && fe.textContent) fe.textContent = '';
  }
  function showErrors(errs) {
    var order = ['slides', 'caption', 'scheduled_date'], first = null, msgs = [];
    order.forEach(function (k) {
      if (!errs[k]) return;
      msgs.push(errs[k]);
      if (k === 'slides') { $('[data-np-tray]', R).classList.add('is-invalid'); if (!first) first = 'slides'; return; }
      var el = $('[data-np-err="' + k + '"]', R); if (el) { el.textContent = errs[k]; el.hidden = false; }
      var f = field(k); if (f) f.setAttribute('aria-invalid', 'true');
      if (!first) first = k;
    });
    Object.keys(errs).forEach(function (k) { if (order.indexOf(k) === -1) msgs.push(errs[k]); });
    $('[data-np-foot-error]', R).textContent = msgs.join(' ');
    if (first === 'slides') { setStep('media'); var t = $('[data-np-tray]', R); if (t) t.scrollIntoView({ block: 'nearest' }); }
    else if (first) { setStep('details'); var f2 = field(first); if (f2) f2.focus(); }
  }
  function validate(intent) {
    var e = {};
    if (S.slides.length > MAX) e.slides = 'Up to ' + MAX + ' slides per post.';
    if (S.slides.some(function (s) { return s.failed; })) e.slides = 'Remove the slides that failed to upload.';
    var visible = intent === 'review' || (intent === 'keep' && S.post && S.post.status !== 'draft');   // the client sees it after this save
    if (visible) {
      if (!S.slides.length) e.slides = 'Add at least one image or video.';
      if (!val('caption').trim()) e.caption = 'Write a caption before sending it for review.';
      if (!val('scheduled_date')) e.scheduled_date = 'Pick a date.';
    } else if (S.mode === 'create' && !S.slides.length && !val('caption').trim()) e.slides = 'Add an image or a caption first.';
    return e;
  }
  function save(intent) {
    if (!S || S.saving) return;
    clearErrors();
    if (uploadsBusy()) { toast('Wait for the uploads to finish', 'error'); return; }
    var errs = validate(intent);
    if (Object.keys(errs).length) { showErrors(errs); return; }
    S.saving = true; renderFooter();
    var btn = $('[data-np-save="' + intent + '"]', R); if (btn) { btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Saving…'; }
    var params = {
      action: S.mode === 'edit' ? 'update' : 'create', intent: intent,
      slides: S.slides.map(function (s) { return s.ref; }),
      caption: val('caption'), hashtags: val('hashtags'), name: val('name'),
      scheduled_date: val('scheduled_date') ? val('scheduled_date').replace('T', ' ') + ':00' : '',
      post_type: typeValue()
    };
    if (S.mode === 'edit') params.id = S.postId;
    var slug = S.slug;
    postForm(slug, params).then(function (res) {
      if (!S) return;
      S.saving = false;
      if (!res.ok) {
        renderFooter();
        if (res.status === 422 && res.data.errors) { showErrors(res.data.errors); return; }
        $('[data-np-foot-error]', R).textContent = res.data.error || 'Could not save';
        toast(res.data.error || 'Could not save', 'error', 4000);
        return;
      }
      var d = res.data, msg = d.message || 'Saved';
      S.dirty = false;
      S.uploads.forEach(function (u) { u.token = null; });   // claimed now — never discard
      var url = d.url, mode = S.mode;
      forceClose(function () { afterSave(d.post_id, url, msg, mode, slug); });
    });
  }
  /* On Posts for the same client — every save (create, draft, send for review, edit, Edit & resubmit): the row is
     refreshed in place (App.posts.refresh: swapped, inserted, or it leaves this segment) with the segment counts,
     then the post's detail opens; the toast names the segment when the post is not in this one (+ a View link).
     Anywhere else — or if the refresh fails — the page goes to posts.php?post=ID (segment / month follow the post). */
  function afterSave(id, url, msg, mode, slug) {
    document.dispatchEvent(new CustomEvent('newpost:saved', { detail: { id: id, url: url, mode: mode } }));
    var onPosts = !!(App.posts && App.posts.refresh && App.posts.open && $('.page-posts [data-posts-list]'))
               && (document.body.getAttribute('data-client') || '') === (slug || '');
    var go = function () { try { sessionStorage.setItem('np.toast', msg); } catch (e) {} window.location.href = url; };
    if (!onPosts) { go(); return; }
    App.posts.refresh(id).then(function (r) {
      App.posts.open(id);
      if (r && !r.inView && App.toast) App.toast(msg + ' · in ' + r.label, { kind: 'success', link: { href: url, label: 'View' } });
      else toast(msg, 'success');
    }, go);
  }

  /* ------------------------------------------------------------------ */
  /* Public API + entry points                                           */
  /* ------------------------------------------------------------------ */
  App.newPost = {
    open: open,
    close: function (force) { if (force) forceClose(); else requestClose(); },
    isOpen: function () { return !!S; },
    _state: function () { return S; }
  };

  function refsFrom(attr) { return String(attr || '').split(/[\s,]+/).filter(function (r) { return /^(tire|library):\d+$/.test(r); }); }
  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-new-action="post"], [data-newpost], [data-newpost-edit]');
    if (!t || (R && R.contains(t))) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey) return;
    if (t.hasAttribute('data-new-action') && App.newMenu) return;   // the "+ New" menu (app.js App.newMenu) calls App.newPost.open(detail) itself
    e.preventDefault();
    if (t.hasAttribute('data-newpost-edit')) { open({ postId: t.getAttribute('data-newpost-edit'), client: t.getAttribute('data-client') || undefined }); return; }
    open({ client: t.getAttribute('data-client') || undefined, preselect: refsFrom(t.getAttribute('data-newpost-preselect')), pane: t.getAttribute('data-newpost-pane') || undefined });
  });

  function boot() {
    ensureCss(/newpost\.css/);   // the sheet's own styles up front (no flash on first open); posts.css (the preview card) on open
    try { var m = sessionStorage.getItem('np.toast'); if (m) { sessionStorage.removeItem('np.toast'); setTimeout(function () { toast(m, 'success'); }, 300); } } catch (e) {}
    var u; try { u = new URL(window.location.href); } catch (e) { return; }
    var np = u.searchParams.get('newpost');
    if (!np) return;
    var post = u.searchParams.get('post'), refs = refsFrom(u.searchParams.get('newpost_assets'));
    u.searchParams.delete('newpost'); u.searchParams.delete('newpost_assets');
    if (np === 'edit') u.searchParams.delete('post');
    try { history.replaceState(history.state, '', u.pathname + u.search + u.hash); } catch (e) {}
    var go = function () {
      if (np === 'edit' && post) open({ postId: post });
      else open({ pane: np === 'upload' ? 'upload' : 'approved', preselect: refs });
    };
    if (App._inited) setTimeout(go, 0); else document.addEventListener('app:ready', function () { setTimeout(go, 0); }, { once: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})(window, document);
