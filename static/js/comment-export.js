/* =====================================================================
   Joust client portal — comment-export.js  ("Export open comments", admin only)

   App.commentExport
     .open({scope: 'client' | 'all', filter: 'open' | 'waiting' | 'all', format: 'botpack' | 'csv' | 'zip' | 'html',
            client, clientName (another client than the page's), tire, series (id | 'ref'), area (tires | library | posts)})
                       the export sheet (its own root, #cexpSheet — it opens on every admin page, above the viewer):
                       Clients (this client · All clients) — or, narrowed to a tire / series / the Library, its path
                       ("Kenda Tires › Klever AT2 › Series 1") with "Whole client"; Which comments (Open · Waiting on
                       Joust · All comments), Include internal notes, Dates (optional), Only since last export, Format
                       (Bot pack (ZIP) · CSV · Zip with images · Printable) + image size for the zips; a live count
                       (comment-export.php action=count); for the bot pack, one pack per tire (per series on a tire).
     Export:  Bot pack → zip_start pack=bot, zip_step until done, GET action=download (images + .comments.md,
              manifest.json, comments.csv, REVIEW_INSTRUCTIONS.md) · CSV → GET action=csv (a download) · Printable →
              GET action=html in a new tab · Zip → zip_start, zip_step until done, then GET action=download.
   Any [data-cexp-open] opens it (data-cexp-scope="all" / data-cexp-filter="waiting" / data-cexp-format="botpack" /
   data-cexp-client / data-cexp-tire / data-cexp-series / data-cexp-area preselect) — the "Export N open" buttons
   (commentExportPackButton()) open it on the Bot pack with a big "Download bot pack".
   Config: window.CommentExportConfig {endpoint, client, clientName, css, close} (partials/components/comment-export.php).
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var cfg = window.CommentExportConfig || null;
  if (!cfg) return;
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(msg, opts) { if (App.toast) App.toast(msg, opts); }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

  function ensureCss() {
    (cfg.css || []).forEach(function (href) {
      var key = href.split('?')[0];
      if ($$('link[rel="stylesheet"]').some(function (l) { return (l.getAttribute('href') || '').split('?')[0] === key; })) return;
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l);
    });
  }

  /** The sheet's own root (the generic #uiSheet is not on every page, and the Assets viewer sits above it). */
  function sheetRoot() {
    var root = document.getElementById('cexpSheet');
    if (root) return root;
    root = document.createElement('div');
    root.className = 'ui-sheet-root cexp-sheet-root';
    root.id = 'cexpSheet';
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = '<button type="button" class="ui-sheet-backdrop" tabindex="-1" aria-label="Close"></button>'
      + '<div class="ui-sheet cexp-panel" role="dialog" aria-modal="true" aria-labelledby="cexpSheetTitle" tabindex="-1">'
      + '<div class="ui-sheet-grabber" aria-hidden="true"></div>'
      + '<header class="ui-sheet-header"><div class="ui-sheet-leading"></div><h2 class="ui-sheet-title" id="cexpSheetTitle" data-sheet-title>Export comments</h2>'
      + '<button type="button" class="ui-sheet-close" data-sheet-close aria-label="Close">' + (cfg.close || '×') + '</button></header>'
      + '<div class="ui-sheet-body" data-sheet-body></div>'
      + '<footer class="ui-sheet-footer ui-glass ui-glass--top" data-sheet-footer hidden></footer></div>';
    document.body.appendChild(root);
    return root;
  }

  function seg(name, items, value, label) {
    return '<div class="ui-segmented cexp-seg" role="tablist" aria-label="' + esc(label) + '" data-cexp-seg="' + name + '">'
      + items.map(function (it) {
        var on = it[0] === value;
        return '<button type="button" class="ui-segmented-item' + (on ? ' is-active' : '') + '" role="tab" aria-selected="' + (on ? 'true' : 'false') + '" data-value="' + esc(it[0]) + '" data-cexp-' + name + '="' + esc(it[0]) + '">' + esc(it[1]) + '</button>';
      }).join('') + '</div>';
  }

  function formHtml(o) {
    var narrowed = !!(o.tire || o.series || o.area);
    var scopeHtml = narrowed
      ? '<div class="cexp-path" data-cexp-path><span class="cexp-path-text" data-cexp-path-text>' + esc(o.clientName || 'This client') + '…</span>'
        + '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm cexp-widen" data-cexp-widen>Whole client</button></div>'
      : (o.client
        ? seg('scope', [['client', o.clientName || 'This client'], ['all', 'All clients']], o.scope, 'Clients')
        : '<p class="cexp-static" data-cexp-scope-all>All clients</p>');
    var filters = [
      ['open', 'Open', 'Not approved yet (To Review, Needs changes, in the Redo queue) and not resolved — plus anything the client is waiting on Joust for.'],
      ['waiting', 'Waiting on Joust only', 'The client spoke last and nobody at Joust has replied or resolved it.'],
      ['all', 'All comments on these images', 'Every comment, answered and resolved ones included.']
    ];
    return '<form class="cexp" data-cexp-form novalidate>'
      + '<p class="cexp-lead">Comments on images — tire series images, reference images, whole series, the Library and post images (slide comments go with their slide).</p>'
      + '<div class="cexp-field"><span class="cexp-label">' + (narrowed ? 'Scope' : 'Clients') + '</span>' + scopeHtml + '</div>'
      + '<div class="cexp-field"><span class="cexp-label">Format</span>'
      + seg('format', [['botpack', 'Bot pack (ZIP)'], ['csv', 'CSV'], ['zip', 'Zip with images'], ['html', 'Printable']], o.format, 'Format')
      + '<p class="cexp-help" data-cexp-format-help></p></div>'
      + '<div class="cexp-field" data-cexp-zipopts hidden><span class="cexp-label">Images in the zip</span>'
      + seg('size', [['original', 'Original files'], ['lg', '1600 px previews']], 'original', 'Image size') + '</div>'
      // the finer options: open for the CSV / zip / page, folded away under the bot pack's big button (its defaults —
      // Open, no internal notes, any date — are what a review bot wants)
      + '<details class="cexp-more" data-cexp-more' + (o.format === 'botpack' ? '' : ' open') + '><summary class="cexp-more-sum">Options <span class="cexp-dim" data-cexp-more-sum></span></summary><div class="cexp-more-body">'
      + '<fieldset class="cexp-field"><legend class="cexp-label">Which comments</legend><div class="cexp-radios">'
      + filters.map(function (f) {
        return '<label class="cexp-radio"><input type="radio" name="cexpFilter" value="' + f[0] + '" data-cexp-filter="' + f[0] + '"' + (f[0] === o.filter ? ' checked' : '') + '>'
          + '<span class="cexp-radio-text"><span class="cexp-radio-title">' + esc(f[1]) + '</span><span class="cexp-radio-help">' + esc(f[2]) + '</span></span></label>';
      }).join('') + '</div></fieldset>'
      + '<label class="cexp-check"><input type="checkbox" data-cexp-internal> <span>Include internal notes <span class="cexp-dim">(Joust only)</span></span></label>'
      + '<fieldset class="cexp-field"><legend class="cexp-label">Dates <span class="cexp-dim">optional</span></legend><div class="cexp-dates">'
      + '<label class="cexp-date"><span>From</span><input type="date" class="ui-input" data-cexp-from></label>'
      + '<label class="cexp-date"><span>To</span><input type="date" class="ui-input" data-cexp-to></label></div></fieldset>'
      + '<label class="cexp-check"><input type="checkbox" data-cexp-since> <span>Only since last export <span class="cexp-dim" data-cexp-last></span></span></label>'
      + '</div></details>'
      + '<p class="cexp-count" data-cexp-count aria-live="polite">Counting…</p>'
      + '<p class="cexp-file" data-cexp-file hidden></p>'
      + '<div class="cexp-progress" data-cexp-progress hidden role="status"><div class="cexp-bar"><div class="cexp-fill" data-cexp-fill></div></div><span data-cexp-progress-text>Starting…</span></div>'
      + '<div class="cexp-actions"><button type="button" class="ui-btn ui-btn--gray" data-sheet-close>Cancel</button>'
      + '<button type="submit" class="ui-btn ui-btn--filled" data-cexp-submit disabled>Download CSV</button></div>'
      + '<div class="cexp-field cexp-parts" data-cexp-parts hidden><span class="cexp-label" data-cexp-parts-title>One pack per tire</span>'
      + '<ul class="cexp-parts-list" data-cexp-parts-list role="list"></ul></div>'
      + '</form>';
  }

  var HELP = {
    botpack: 'For a review bot: every image with open comments (Client / Tire / Series folders), a .comments.md beside each, manifest.json (ids, paths, comments), comments.csv and REVIEW_INSTRUCTIONS.md.',
    csv: 'One row per comment — opens in Excel and Google Sheets (UTF-8).',
    zip: 'The CSV plus every image with comments in Client / Tire / Series folders, each with a .txt of its comments.',
    html: 'A page with each image and its comments — print it or save it as a PDF.'
  };
  var SUBMIT = { botpack: 'Download bot pack', csv: 'Download CSV', zip: 'Build zip', html: 'Open printable page' };
  var FORMATS = ['botpack', 'csv', 'zip', 'html'];

  var api = App.commentExport = {
    root: null, form: null, ctx: null, _t: null, _seq: 0, _busy: false, _n: 0,

    open: function (o) {
      o = o || {};
      ensureCss();
      var root = sheetRoot();
      var client = o.client || cfg.client || '';
      var clientName = o.client ? (o.clientName || o.client) : (cfg.clientName || '');
      var narrowed = !!(client && (o.tire || o.series || o.area));
      var scope = !client ? 'all' : (narrowed || o.client ? 'client' : (o.scope === 'all' ? 'all' : 'client'));
      var filter = ['open', 'waiting', 'all'].indexOf(o.filter) >= 0 ? o.filter : 'open';
      var format = FORMATS.indexOf(o.format) >= 0 ? o.format : 'csv';
      this.ctx = { client: client, clientName: clientName, tire: narrowed ? (o.tire || '') : '', series: narrowed ? (o.series || '') : '', area: narrowed ? (o.area || '') : '' };
      this._busy = false;
      App.sheet.open(root, { title: format === 'botpack' ? 'Export open comments' : 'Export comments',
        html: formHtml({ scope: scope, filter: filter, format: format, client: client, clientName: clientName, tire: this.ctx.tire, series: this.ctx.series, area: this.ctx.area }), footer: '' });
      this.root = root;
      this.form = $('[data-cexp-form]', root);
      this.form.setAttribute('data-scope', scope);
      var self = this;
      this.form.addEventListener('change', function () { self.sync(); self.count(); });
      this.form.addEventListener('input', function (e) { if (e.target.matches('[type="date"]')) { self.count(); } });
      this.form.addEventListener('segmented:change', function () { self.sync(); self.count(); });
      this.form.addEventListener('submit', function (e) { e.preventDefault(); self.run(); });
      this.form.addEventListener('click', function (e) {
        var w = e.target.closest('[data-cexp-widen]');
        if (w) { e.preventDefault(); self.widen(); return; }
        var p = e.target.closest('[data-cexp-part]');
        if (p) { e.preventDefault(); self.zip({ tire: p.getAttribute('data-tire') || '', series: p.getAttribute('data-series') || '', area: p.getAttribute('data-area') || '' }, p); }
      });
      this.sync();
      this.count(true);
      return root;
    },

    /** "Whole client": drop the tire / series / area, keep every other option. */
    widen: function () {
      this.ctx.tire = ''; this.ctx.series = ''; this.ctx.area = '';
      var path = $('[data-cexp-path]', this.form);
      if (path) path.outerHTML = '<p class="cexp-static" data-cexp-path-whole>' + esc(this.ctx.clientName || 'This client') + '</p>';
      this.form.removeAttribute('data-narrowed');
      this.sync(); this.count(true);
    },

    state: function () {
      var f = this.form, segVal = function (name, def) { var a = $('[data-cexp-seg="' + name + '"] .is-active', f); return a ? a.getAttribute('data-value') : def; };
      var filt = $('[data-cexp-filter]:checked', f);
      var c = this.ctx || {};
      return {
        scope: c.client ? segVal('scope', 'client') : 'all',
        filter: filt ? filt.value : 'open',
        internal: $('[data-cexp-internal]', f).checked ? 1 : 0,
        since: $('[data-cexp-since]', f).checked ? 1 : 0,
        from: $('[data-cexp-from]', f).value || '',
        to: $('[data-cexp-to]', f).value || '',
        format: segVal('format', 'csv'),
        size: segVal('size', 'original'),
        tire: c.tire || '', series: c.series || '', area: c.area || ''
      };
    },
    params: function (action, over) {
      var s = this.state(), c = this.ctx || {};
      var p = { action: action, client: s.scope === 'client' ? (c.client || '') : '', scope: s.scope, filter: s.filter, internal: s.internal,
                since: s.since, from: s.from, to: s.to, size: s.size, tire: s.tire, series: s.series, area: s.area };
      if (over) Object.keys(over).forEach(function (k) { p[k] = over[k]; });
      return p;
    },
    url: function (action, extra) {
      var p = this.params(action), q = new URLSearchParams();
      Object.keys(p).forEach(function (k) { if (p[k] !== '' && p[k] !== null && p[k] !== undefined) q.append(k, String(p[k])); });
      Object.keys(extra || {}).forEach(function (k) { q.append(k, String(extra[k])); });
      return cfg.endpoint + '?' + q.toString();
    },

    /** Format → the zip options, the help line, the button label (the bot pack's is the big primary one). */
    sync: function () {
      var s = this.state(), f = this.form;
      f.setAttribute('data-scope', s.scope);
      f.setAttribute('data-format', s.format);
      if (s.tire || s.series || s.area) f.setAttribute('data-narrowed', '1');
      $('[data-cexp-zipopts]', f).hidden = s.format !== 'zip' && s.format !== 'botpack';
      $('[data-cexp-format-help]', f).textContent = HELP[s.format] || '';
      var btn = $('[data-cexp-submit]', f);
      btn.classList.toggle('cexp-submit--big', s.format === 'botpack');
      if (!this._busy) btn.textContent = SUBMIT[s.format] || 'Export';
      this.renderParts();
      var sum = $('[data-cexp-more-sum]', f);
      if (sum) {
        var bits = [{ open: 'Open comments', waiting: 'Waiting on Joust', all: 'All comments' }[s.filter], s.internal ? 'internal notes in' : 'no internal notes'];
        if (s.from || s.to) bits.push((s.from || '…') + ' – ' + (s.to || '…'));
        if (s.since) bits.push('since last export');
        sum.textContent = '· ' + bits.join(' · ');
      }
      var file = $('[data-cexp-file]', f);
      file.hidden = s.format !== 'botpack' || !this._file;
      if (this._file) file.textContent = this._file;
    },

    /** One pack per tire (a client) / per series (a tire): a Download each — the bot pack only, two or more parts. */
    renderParts: function () {
      var f = this.form, s = this.state(), box = $('[data-cexp-parts]', f), list = $('[data-cexp-parts-list]', f);
      var parts = (this._parts || []).filter(function (p) { return p.comments > 0; });
      var busy = this._busy;
      var show = s.format === 'botpack' && s.scope === 'client' && !s.series && parts.length > 1;
      box.hidden = !show;
      if (!show) { list.innerHTML = ''; return; }
      $('[data-cexp-parts-title]', f).textContent = s.tire ? 'Or one pack per series' : 'Or one pack per tire';
      list.innerHTML = parts.map(function (p) {
        return '<li class="cexp-part" data-cexp-part-row="' + esc(p.area + ':' + (p.tire || '') + ':' + (p.series || '')) + '">'
          + '<span class="cexp-part-name">' + esc(p.label) + '</span>'
          + '<span class="cexp-part-n">' + esc(plural(p.comments, 'comment', 'comments')) + ' · ' + esc(plural(p.images, 'image', 'images')) + '</span>'
          + '<button type="button" class="ui-btn ui-btn--sm ui-btn--tinted" data-cexp-part' + (busy ? ' disabled' : '') + ' data-tire="' + esc(p.tire || '') + '" data-series="' + esc(p.series || '') + '" data-area="' + esc(p.area || '') + '"'
          + ' aria-label="' + esc('Download the bot pack of ' + p.label) + '">' + (cfg.download || '') + '<span>Download</span></button></li>';
      }).join('');
    },

    /** The live count (debounced; a stale answer never overwrites a newer one). */
    count: function (now) {
      var self = this;
      clearTimeout(this._t);
      var go = function () {
        var seq = ++self._seq, f = self.form, out = $('[data-cexp-count]', f), btn = $('[data-cexp-submit]', f);
        out.textContent = 'Counting…';
        App.post(cfg.endpoint, self.params('count')).then(function (r) {
          if (seq !== self._seq || !self.form || !document.contains(self.form)) return;
          if (!r.ok) { out.textContent = r.error || 'Could not count'; btn.disabled = true; return; }
          var d = r.data || {};
          self._n = d.comments || 0;
          self._parts = d.parts || [];
          self._file = d.filename ? 'File: ' + d.filename : '';
          out.setAttribute('data-comments', String(d.comments || 0));
          out.setAttribute('data-images', String(d.images || 0));
          out.textContent = d.comments
            ? plural(d.comments, 'comment', 'comments') + ' on ' + plural(d.items, 'item', 'items') + ' (' + plural(d.images, 'image', 'images') + ')'
              + (self.state().scope === 'all' ? ' · ' + plural(d.clients, 'client', 'clients') : '')
            : 'No comments match';
          var path = $('[data-cexp-path-text]', f);
          if (path && d.scope_path) path.textContent = d.scope_path.join(' › ');
          var last = $('[data-cexp-last]', f);
          if (last) last.textContent = d.last_export ? '· last ' + d.last_export_label : '· never exported';
          if (!self._busy) btn.disabled = !d.comments;
          self.sync();
        });
      };
      if (now) go(); else this._t = setTimeout(go, 220);
    },

    run: function () {
      if (this._busy) return;
      var s = this.state();
      if (s.format === 'csv') {
        var a = document.createElement('a');
        a.href = this.url('csv'); a.setAttribute('download', ''); a.rel = 'noopener'; a.style.display = 'none';
        document.body.appendChild(a); a.click(); a.remove();
        toast('Downloading ' + plural(this._n, 'comment', 'comments') + ' as CSV', { kind: 'success', duration: 3500 });
        var last = $('[data-cexp-last]', this.form); if (last) last.textContent = '· last just now';
        return;
      }
      if (s.format === 'html') {
        window.open(this.url('html'), '_blank', 'noopener');
        return;
      }
      this.zip();
    },

    /** Zip / bot pack: zip_start, zip_step until done, then the download. over = a part's tire / series / area. */
    zip: function (over, partBtn) {
      if (this._busy) return;
      var self = this, f = this.form, btn = $('[data-cexp-submit]', f), prog = $('[data-cexp-progress]', f);
      var fill = $('[data-cexp-fill]', f), text = $('[data-cexp-progress-text]', f);
      var bot = this.state().format === 'botpack' || !!over;
      var done = function (msg, kind) {
        self._busy = false; btn.disabled = false; btn.removeAttribute('aria-busy'); self.sync();
        $$('[data-cexp-part]', f).forEach(function (b) { b.disabled = false; b.removeAttribute('aria-busy'); });
        if (msg) toast(msg, { kind: kind || 'success', duration: 4000 });
      };
      this._busy = true; btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Preparing…';
      $$('[data-cexp-part]', f).forEach(function (b) { b.disabled = true; });
      if (partBtn) partBtn.setAttribute('aria-busy', 'true');
      prog.hidden = false; fill.style.width = '0%'; text.textContent = 'Starting…';
      var p = this.params('zip_start', over || null);
      if (bot) p.pack = 'bot';
      App.post(cfg.endpoint, p).then(function (res) {
        if (!res.ok) { prog.hidden = true; done(res.error || 'Could not start the zip', 'error'); return; }
        var job = res.data.job, files = res.data.files, name = res.data.filename || '', tries = 0;
        var step = function () {
          App.post(cfg.endpoint, { action: 'zip_step', job: job, client: '' }).then(function (r) {
            if (!r.ok) { if (r.status === 409 && tries++ < 5) { setTimeout(step, 800); return; } prog.hidden = true; done(r.error || 'The zip failed', 'error'); return; }
            var d = r.data || {};
            var pct = d.bytes ? Math.min(99, Math.floor((d.bytes_done || 0) / d.bytes * 100)) : 50;
            fill.style.width = (d.done ? 100 : pct) + '%';
            text.textContent = d.done ? 'Ready' + (name ? ' — ' + name : '') : 'Zipping… ' + pct + '%';
            if (!d.done) { step(); return; }
            f.setAttribute('data-cexp-job', job);
            var a = document.createElement('a');
            a.href = cfg.endpoint + '?action=download&job=' + encodeURIComponent(job); a.setAttribute('download', ''); a.style.display = 'none';
            document.body.appendChild(a); a.click(); a.remove();
            var last = $('[data-cexp-last]', f); if (last) last.textContent = '· last just now';
            done((bot ? 'Downloading the bot pack (' : 'Downloading the zip (') + plural(files, 'image', 'images') + ')');
          });
        };
        step();
      });
    }
  };

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-cexp-open]');
    if (!t) return;
    e.preventDefault();
    // from the series ⋯ menu (assets.js): close it first (the [data-asg-menu] ⋯ menus close themselves — assign.js)
    var mr = t.closest('[data-series-menu-root]');
    if (mr) { var l = $('[data-series-menu-list]', mr), b = $('[data-series-menu]', mr); if (l) l.hidden = true; if (b) b.setAttribute('aria-expanded', 'false'); }
    api.open({ scope: t.getAttribute('data-cexp-scope') || '', filter: t.getAttribute('data-cexp-filter') || '', format: t.getAttribute('data-cexp-format') || '',
               client: t.getAttribute('data-cexp-client') || '', clientName: t.getAttribute('data-cexp-client-name') || '',
               tire: t.getAttribute('data-cexp-tire') || '', series: t.getAttribute('data-cexp-series') || '', area: t.getAttribute('data-cexp-area') || '' });
  });
})(window, document);
