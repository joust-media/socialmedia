/* =====================================================================
   Joust client portal — comment-export.js  ("Export open comments", admin only)

   App.commentExport
     .open({scope: 'client' | 'all', filter: 'open' | 'waiting' | 'all'})
                       the export sheet (its own root, #cexpSheet — it opens on every admin page, above the viewer):
                       Clients (this client · All clients), Which comments (Open · Waiting on Joust · All comments),
                       Include internal notes, Dates (optional), Only since last export, Format (CSV · Zip with images ·
                       Printable) + image size for the zip; a live count (comment-export.php action=count).
     Export:  CSV → GET action=csv (a download) · Printable → GET action=html in a new tab · Zip → zip_start, zip_step
              until done (the stepwise zip of Manage → Export / the redo pack), then GET action=download.
   Any [data-cexp-open] opens it (data-cexp-scope="all" / data-cexp-filter="waiting" preselect).
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
    var scopeHtml = cfg.client
      ? seg('scope', [['client', cfg.clientName || 'This client'], ['all', 'All clients']], o.scope, 'Clients')
      : '<p class="cexp-static" data-cexp-scope-all>All clients</p>';
    var filters = [
      ['open', 'Open', 'Not approved yet (To Review, Needs changes, in the Redo queue) and not resolved — plus anything the client is waiting on Joust for.'],
      ['waiting', 'Waiting on Joust only', 'The client spoke last and nobody at Joust has replied or resolved it.'],
      ['all', 'All comments on these images', 'Every comment, answered and resolved ones included.']
    ];
    return '<form class="cexp" data-cexp-form novalidate>'
      + '<p class="cexp-lead">Comments on images — tire series images, reference images, whole series, the Library and post images (slide comments go with their slide).</p>'
      + '<div class="cexp-field"><span class="cexp-label">Clients</span>' + scopeHtml + '</div>'
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
      + '<div class="cexp-field"><span class="cexp-label">Format</span>'
      + seg('format', [['csv', 'CSV'], ['zip', 'Zip with images'], ['html', 'Printable']], 'csv', 'Format')
      + '<p class="cexp-help" data-cexp-format-help></p></div>'
      + '<div class="cexp-field" data-cexp-zipopts hidden><span class="cexp-label">Images in the zip</span>'
      + seg('size', [['original', 'Original files'], ['lg', 'Large previews']], 'original', 'Image size') + '</div>'
      + '<p class="cexp-count" data-cexp-count aria-live="polite">Counting…</p>'
      + '<div class="cexp-progress" data-cexp-progress hidden role="status"><div class="cexp-bar"><div class="cexp-fill" data-cexp-fill></div></div><span data-cexp-progress-text>Starting…</span></div>'
      + '<div class="cexp-actions"><button type="button" class="ui-btn ui-btn--gray" data-sheet-close>Cancel</button>'
      + '<button type="submit" class="ui-btn ui-btn--filled" data-cexp-submit disabled>Download CSV</button></div>'
      + '</form>';
  }

  var HELP = {
    csv: 'One row per comment — opens in Excel and Google Sheets (UTF-8).',
    zip: 'The CSV plus every image with comments in Client / Tire / Series folders, each with a .txt of its comments.',
    html: 'A page with each image and its comments — print it or save it as a PDF.'
  };
  var SUBMIT = { csv: 'Download CSV', zip: 'Build zip', html: 'Open printable page' };

  var api = App.commentExport = {
    root: null, form: null, _t: null, _seq: 0, _busy: false, _n: 0,

    open: function (o) {
      o = o || {};
      ensureCss();
      var root = sheetRoot();
      var scope = !cfg.client ? 'all' : (o.scope === 'all' ? 'all' : 'client');
      var filter = ['open', 'waiting', 'all'].indexOf(o.filter) >= 0 ? o.filter : 'open';
      this._busy = false;
      App.sheet.open(root, { title: 'Export comments', html: formHtml({ scope: scope, filter: filter }), footer: '' });
      this.root = root;
      this.form = $('[data-cexp-form]', root);
      this.form.setAttribute('data-scope', scope);
      var self = this;
      this.form.addEventListener('change', function () { self.sync(); self.count(); });
      this.form.addEventListener('input', function (e) { if (e.target.matches('[type="date"]')) { self.count(); } });
      this.form.addEventListener('segmented:change', function () { self.sync(); self.count(); });
      this.form.addEventListener('submit', function (e) { e.preventDefault(); self.run(); });
      this.sync();
      this.count(true);
      return root;
    },

    state: function () {
      var f = this.form, segVal = function (name, def) { var a = $('[data-cexp-seg="' + name + '"] .is-active', f); return a ? a.getAttribute('data-value') : def; };
      var filt = $('[data-cexp-filter]:checked', f);
      return {
        scope: cfg.client ? segVal('scope', 'client') : 'all',
        filter: filt ? filt.value : 'open',
        internal: $('[data-cexp-internal]', f).checked ? 1 : 0,
        since: $('[data-cexp-since]', f).checked ? 1 : 0,
        from: $('[data-cexp-from]', f).value || '',
        to: $('[data-cexp-to]', f).value || '',
        format: segVal('format', 'csv'),
        size: segVal('size', 'original')
      };
    },
    params: function (action) {
      var s = this.state();
      return { action: action, client: s.scope === 'client' ? cfg.client : '', scope: s.scope, filter: s.filter, internal: s.internal,
               since: s.since, from: s.from, to: s.to, size: s.size };
    },
    url: function (action, extra) {
      var p = this.params(action), q = new URLSearchParams();
      Object.keys(p).forEach(function (k) { if (p[k] !== '' && p[k] !== null && p[k] !== undefined) q.append(k, String(p[k])); });
      Object.keys(extra || {}).forEach(function (k) { q.append(k, String(extra[k])); });
      return cfg.endpoint + '?' + q.toString();
    },

    /** Format → the zip options, the help line, the button label. */
    sync: function () {
      var s = this.state(), f = this.form;
      this.form.setAttribute('data-scope', s.scope);
      this.form.setAttribute('data-format', s.format);
      $('[data-cexp-zipopts]', f).hidden = s.format !== 'zip';
      $('[data-cexp-format-help]', f).textContent = HELP[s.format] || '';
      if (!this._busy) $('[data-cexp-submit]', f).textContent = SUBMIT[s.format] || 'Export';
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
          out.setAttribute('data-comments', String(d.comments || 0));
          out.setAttribute('data-images', String(d.images || 0));
          out.textContent = d.comments
            ? plural(d.comments, 'comment', 'comments') + ' on ' + plural(d.items, 'item', 'items') + ' (' + plural(d.images, 'image', 'images') + ')'
              + (self.state().scope === 'all' ? ' · ' + plural(d.clients, 'client', 'clients') : '')
            : 'No comments match';
          var last = $('[data-cexp-last]', f);
          if (last) last.textContent = d.last_export ? '· last ' + d.last_export_label : '· never exported';
          if (!self._busy) btn.disabled = !d.comments;
        });
      };
      if (now) go(); else this._t = setTimeout(go, 220);
    },

    run: function () {
      if (this._busy) return;
      var s = this.state(), self = this;
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

    zip: function () {
      var self = this, f = this.form, btn = $('[data-cexp-submit]', f), prog = $('[data-cexp-progress]', f);
      var fill = $('[data-cexp-fill]', f), text = $('[data-cexp-progress-text]', f);
      var done = function (msg, kind) {
        self._busy = false; btn.disabled = false; btn.removeAttribute('aria-busy'); self.sync();
        if (msg) toast(msg, { kind: kind || 'success', duration: 4000 });
      };
      this._busy = true; btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Preparing…';
      prog.hidden = false; fill.style.width = '0%'; text.textContent = 'Starting…';
      App.post(cfg.endpoint, this.params('zip_start')).then(function (res) {
        if (!res.ok) { prog.hidden = true; done(res.error || 'Could not start the zip', 'error'); return; }
        var job = res.data.job, files = res.data.files, tries = 0;
        var step = function () {
          App.post(cfg.endpoint, { action: 'zip_step', job: job, client: '' }).then(function (r) {
            if (!r.ok) { if (r.status === 409 && tries++ < 5) { setTimeout(step, 800); return; } prog.hidden = true; done(r.error || 'The zip failed', 'error'); return; }
            var d = r.data || {};
            var pct = d.bytes ? Math.min(99, Math.floor((d.bytes_done || 0) / d.bytes * 100)) : 50;
            fill.style.width = (d.done ? 100 : pct) + '%';
            text.textContent = d.done ? 'Ready' : 'Zipping… ' + pct + '%';
            if (!d.done) { step(); return; }
            f.setAttribute('data-cexp-job', job);
            var a = document.createElement('a');
            a.href = cfg.endpoint + '?action=download&job=' + encodeURIComponent(job); a.setAttribute('download', ''); a.style.display = 'none';
            document.body.appendChild(a); a.click(); a.remove();
            var last = $('[data-cexp-last]', f); if (last) last.textContent = '· last just now';
            done('Downloading the zip (' + plural(files, 'image', 'images') + ')');
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
    api.open({ scope: t.getAttribute('data-cexp-scope') || '', filter: t.getAttribute('data-cexp-filter') || '' });
  });
})(window, document);
