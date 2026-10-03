/* =====================================================================
   Unread markers + "Mark resolved" (tracking-lib.php → thread-action.php). Loaded by layout-bottom.php for the
   admin and for signed-in client contacts only.

   Seen:    an item's detail ([data-seen-entity="post:12"], post / email / page sheets) showing in an open sheet →
            POST action=seen once per page view; its unread dots ([data-unread-for="post:12"]) disappear.
   Resolve: [data-thread-resolve="post:12"] (the sheet ⋯ menu, the Inbox) → POST action=resolve → toast; an Inbox
            row fades out.
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var script = document.currentScript;
  var endpoint = script ? script.getAttribute('data-endpoint') : '';
  var done = {};
  var toast = function (msg, opts) { if (App.toast) App.toast(msg, opts); };

  function send(params) {
    if (App.post) return App.post(endpoint, params);
    var body = new URLSearchParams(params);
    return fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.ok, data: j, error: j.error }; }); });
  }

  function clearDots(key) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-unread-for="' + key + '"]'), function (d) { d.remove(); });
  }

  function markSeen(root) {
    if (!root || !root.querySelectorAll) return;
    var nodes = root.matches && root.matches('[data-seen-entity]') ? [root] : [];
    nodes = nodes.concat(Array.prototype.slice.call(root.querySelectorAll('[data-seen-entity]')));
    nodes.forEach(function (n) {
      var key = n.getAttribute('data-seen-entity');
      if (!key || done[key]) return;
      done[key] = true;
      send({ action: 'seen', entity: key }).then(function (res) { if (res && res.ok) clearDots(key); });
    });
  }

  function isOpen(sheetRoot) {
    return sheetRoot && sheetRoot.getAttribute('aria-hidden') === 'false';
  }

  var pending = null;
  function soon(root) {
    if (pending) window.clearTimeout(pending);
    pending = window.setTimeout(function () { pending = null; markSeen(root); }, 350);
  }

  document.addEventListener('sheet:open', function (e) {
    soon((e.detail && e.detail.sheet) || document);
  });

  // A sheet whose body arrives after it opened (partial fetch): watch every sheet shell.
  function watchSheets() {
    if (!window.MutationObserver) return;
    Array.prototype.forEach.call(document.querySelectorAll('.ui-sheet-root'), function (root) {
      new MutationObserver(function () { if (isOpen(root)) soon(root); }).observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-hidden'] });
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-thread-resolve]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    var key = btn.getAttribute('data-thread-resolve');
    btn.disabled = true;
    send({ action: 'resolve', entity: key }).then(function (res) {
      btn.disabled = false;
      if (!res || !res.ok) { toast((res && res.error) || 'Could not mark it resolved', { kind: 'error' }); return; }
      toast((res.data && res.data.message) || 'Marked resolved', { kind: 'success' });
      var row = btn.closest('[data-inbox-row]');
      if (row) {
        row.classList.add('is-resolved');
        window.setTimeout(function () { row.remove(); }, 400);
      }
      var menu = btn.closest('[role="menu"]');
      if (menu) menu.hidden = true;
    });
  });

  function boot() { watchSheets(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})(window, document);
