/* =====================================================================
   Manage → Notifications (partials/manage-notifications.php) — extends the Foundation `App`.

   App.notify.init(root)   [data-notify-form="<action>"] forms and [data-notify-action="<action>"] buttons post to
                           notify-admin.php (App.post: same-site, admin session); success → toast + reload of the
                           section (the delivery log / config state are server-rendered), error → toast, nothing
                           changes. Buttons carry data-company / data-id as company_id / id.
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};
  var toast = function (msg, opts) { if (App.toast) App.toast(msg, opts); else if (msg) window.alert(msg); };

  function send(endpoint, params, el) {
    if (el) el.disabled = true;
    return App.post(endpoint, params).then(function (res) {
      if (el) el.disabled = false;
      if (!res.ok) { toast(res.error || 'Something went wrong', { kind: 'error', duration: 4200 }); return res; }
      toast((res.data && res.data.message) || 'Done', { kind: 'success' });
      window.setTimeout(function () { window.location.reload(); }, 700);
      return res;
    });
  }

  App.notify = {
    init: function (root) {
      if (!root || root.__notifyInit) return;
      root.__notifyInit = true;
      var endpoint = root.getAttribute('data-endpoint');
      root.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-notify-form]');
        if (!form) return;
        e.preventDefault();
        var params = { action: form.getAttribute('data-notify-form') };
        new FormData(form).forEach(function (v, k) { params[k] = v; });
        // an unchecked box sends nothing: say so explicitly ("Active", the allow-mail() switch, My notifications)
        Array.prototype.forEach.call(form.querySelectorAll('input[type="checkbox"][name]'), function (cb) { if (!cb.checked) params[cb.name] = '0'; });
        send(endpoint, params, form.querySelector('[type="submit"]'));
      });
      // a switch that saves by itself ([data-autosubmit] inside a [data-notify-form])
      root.addEventListener('change', function (e) {
        if (!e.target.matches || !e.target.matches('[data-autosubmit]')) return;
        var form = e.target.closest('[data-notify-form]');
        if (!form) return;
        if (form.requestSubmit) form.requestSubmit(); else form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
      });
      root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-notify-action]');
        if (!btn || btn.disabled) return;
        e.preventDefault();
        var params = { action: btn.getAttribute('data-notify-action') };
        if (btn.hasAttribute('data-company')) params.company_id = btn.getAttribute('data-company');
        if (btn.hasAttribute('data-id')) params.id = btn.getAttribute('data-id');
        send(endpoint, params, btn);
      });
    }
  };

  function boot() { Array.prototype.forEach.call(document.querySelectorAll('[data-notify]'), App.notify.init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})(window, document);
