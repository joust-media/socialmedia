/* =====================================================================
   Joust client portal — trash.js  (the Trash page, trash.php; admin only)

   App.trashPage
     .restore(rows)      "Restore" / "Restore selected": trash.php action=restore {items} — back exactly where they were
                         (no client email, no Slack ping); the rows leave, the count drops
     .deleteForever(row) ⋯ → "Delete forever…": a sheet that only enables its button once DELETE is typed →
                         trash.php action=delete {items, confirm: DELETE} — the row AND its files, for good
   Loads with `defer` before app.js; boots on 'app:ready'.
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function toast(msg, opts) { if (App.toast) App.toast(msg, opts); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var page = App.trashPage = {
    root: null, ep: '', client: '',

    init: function () {
      var root = $('[data-trash-page]');
      if (!root || root._trashInit) return;
      root._trashInit = true;
      this.root = root;
      this.ep = root.getAttribute('data-endpoint') || (App.urls ? App.urls.abs('trash.php') : 'trash.php');
      this.client = root.getAttribute('data-client') || '';
      var self = this;
      root.addEventListener('click', function (e) {
        var row = e.target.closest('[data-trash-row]');
        var tog = e.target.closest('[data-trash-menu-toggle]');
        if (tog) {
          e.preventDefault();
          var menu = tog.parentNode.querySelector('[data-trash-menu]');
          var open = menu && menu.hidden;
          self.closeMenus();
          if (menu && open) { menu.hidden = false; tog.setAttribute('aria-expanded', 'true'); }
          return;
        }
        if (row && e.target.closest('[data-trash-restore]')) { e.preventDefault(); self.restore([row]); return; }
        if (row && e.target.closest('[data-trash-delete]')) { e.preventDefault(); self.closeMenus(); self.deleteForever(row); return; }
        if (e.target.closest('[data-trash-restore-selected]')) { e.preventDefault(); self.restore(self.picked()); return; }
      });
      document.addEventListener('click', function (e) { if (!e.target.closest('.tr-more')) self.closeMenus(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') self.closeMenus(); });
      root.addEventListener('change', function (e) {
        if (e.target.matches('[data-trash-all]')) { var on = e.target.checked; self.rows().forEach(function (r) { var c = $('[data-trash-pick]', r); if (c) c.checked = on; }); }
        self.syncPicked();
      });
      this.syncPicked();
    },

    rows: function () { return $$('[data-trash-row]', this.root).filter(function (r) { return !r._gone; }); },
    picked: function () { return this.rows().filter(function (r) { var c = $('[data-trash-pick]', r); return c && c.checked; }); },
    closeMenus: function () {
      $$('[data-trash-menu]', this.root).forEach(function (m) { m.hidden = true; });
      $$('[data-trash-menu-toggle]', this.root).forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    },
    syncPicked: function () {
      var n = this.picked().length, btn = $('[data-trash-restore-selected]', this.root), lbl = $('[data-trash-restore-label]', this.root);
      if (btn) btn.disabled = n === 0;
      if (lbl) lbl.textContent = n > 0 ? 'Restore ' + n : 'Restore selected';
      var all = $('[data-trash-all]', this.root), rows = this.rows().length;
      if (all) { all.checked = rows > 0 && n === rows; all.indeterminate = n > 0 && n < rows; }
    },
    setCount: function (n) {
      var c = $('[data-trash-count]', this.root); if (c) c.textContent = String(Math.max(0, n));
      this.root.setAttribute('data-count', String(Math.max(0, n)));
    },
    /** A row leaves (fade); its group goes when empty; the empty state when nothing is left. */
    dropRow: function (row) {
      if (!row || row._gone) return;
      row._gone = true;
      row.classList.add('ui-leave');
      var self = this;
      setTimeout(function () {
        var group = row.closest('[data-trash-group]');
        if (row.parentNode) row.parentNode.removeChild(row);
        if (group && !$('[data-trash-row]', group)) group.remove();
        self.setCount(self.rows().length);
        self.syncPicked();
        if (!self.rows().length && !$('[data-trash-empty]', self.root)) {
          var el = document.createElement('div');
          el.className = 'ui-empty rd-empty ui-enter'; el.setAttribute('data-trash-empty', '');
          el.innerHTML = '<p class="as-empty-title">The Trash is empty</p><p>Everything here was restored or deleted.</p>';
          self.root.appendChild(el);
          var all = $('.tr-all', self.root); if (all) all.hidden = true;
        }
      }, 240);
    },

    /** Several clients' rows may be picked on the All clients view: one request per client (the scope check). */
    byClient: function (rows) {
      var out = {};
      rows.forEach(function (r) { var c = this.client || r.getAttribute('data-client') || ''; (out[c] = out[c] || []).push(r); }, this);
      return out;
    },

    restore: function (rows) {
      rows = (rows || []).filter(function (r) { return r && !r._gone; });
      if (!rows.length) return;
      var self = this, groups = this.byClient(rows), keys = Object.keys(groups), done = 0, restored = 0, failed = '';
      rows.forEach(function (r) { $$('button', r).forEach(function (b) { b.disabled = true; }); });
      keys.forEach(function (client) {
        var grp = groups[client];
        App.post(self.ep, { action: 'restore', items: grp.map(function (r) { return r.getAttribute('data-trash-row'); }).join(','), client: client }).then(function (res) {
          if (!res.ok) { failed = res.error || 'Could not restore'; grp.forEach(function (r) { $$('button', r).forEach(function (b) { b.disabled = false; }); }); }
          else { restored += grp.length; grp.forEach(function (r) { self.dropRow(r); }); }
          if (++done === keys.length) {
            if (failed) toast(failed, { kind: 'error' });
            else toast(restored === 1 ? 'Restored — back where it was' : restored + ' restored — back where they were', { kind: 'success' });
          }
        });
      });
    },

    /** Delete forever: the sheet's button stays disabled until DELETE is typed (the server checks the word too). */
    deleteForever: function (row) {
      var self = this, title = ($('.tr-title', row) || {}).textContent || 'this item';
      var html = '<form class="tr-del-form" data-trash-delete-form novalidate>'
        + '<p class="tr-del-warn"><strong>This cannot be undone.</strong> “' + esc(title) + '” and its files are removed from the server for good — '
        + 'its comments stay in the activity log. To keep it out of the way instead, just leave it in the Trash.</p>'
        + '<label class="pd-editor-label" for="trDelWord">Type <strong>DELETE</strong> to confirm</label>'
        + '<input class="ui-input tr-del-input" id="trDelWord" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="DELETE" data-trash-delete-word data-sheet-autofocus>'
        + '<div class="ui-btn-group tr-del-actions"><button type="button" class="ui-btn ui-btn--gray" data-sheet-close>Cancel</button>'
        + '<button type="submit" class="ui-btn ui-btn--deny tr-del-submit" data-trash-delete-submit disabled>Delete forever</button></div></form>';
      var root = App.sheet && App.sheet.open('#uiSheet', { title: 'Delete forever?', html: html, footer: '' });
      if (!root) return;
      var form = $('[data-trash-delete-form]', root), word = $('[data-trash-delete-word]', form), btn = $('[data-trash-delete-submit]', form);
      word.addEventListener('input', function () { btn.disabled = word.value.trim() !== 'DELETE'; });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (word.value.trim() !== 'DELETE') { word.focus(); return; }
        btn.disabled = true; btn.setAttribute('aria-busy', 'true');
        App.post(self.ep, { action: 'delete', items: row.getAttribute('data-trash-row'), confirm: word.value.trim(), client: self.client || row.getAttribute('data-client') || '' }).then(function (res) {
          btn.removeAttribute('aria-busy');
          if (!res.ok) { btn.disabled = false; toast(res.error || 'Could not delete', { kind: 'error' }); return; }
          App.sheet.close();
          self.dropRow(row);
          toast(res.data && res.data.kept > 0 ? 'Deleted — the file stays (staging shares media with production)' : 'Deleted forever', { kind: 'success' });
        });
      });
    }
  };

  function boot() { page.init(); }
  if (App._inited) boot(); else document.addEventListener('app:ready', boot, { once: true });
})(window, document);
