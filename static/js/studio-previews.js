/* Studio → Export → Image previews: backfill the sm / lg derivatives (preview-job.php).
 * Status on load; "Build previews" = start (enumerate) then step (~15 s each) until finished; counts + bytes saved.
 * "All clients" switches scope=all. Resumes an unfinished job when the page is reopened (Build continues it). */
(function () {
  'use strict';
  var root = document.querySelector('[data-previews]');
  if (!root) return;
  var endpoint = root.getAttribute('data-endpoint') || 'preview-job.php';
  var statusEl = root.querySelector('[data-previews-status]');
  var progress = root.querySelector('[data-previews-progress]');
  var fill = root.querySelector('[data-previews-fill]');
  var btn = root.querySelector('[data-previews-build]');
  var allBox = root.querySelector('[data-previews-all]');
  var busy = false;

  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n >= 1073741824) return (Math.round(n / 1073741824 * 100) / 100) + ' GB';
    if (n >= 1048576) return (Math.round(n / 1048576 * 10) / 10) + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' B';
  }
  function scope() { return allBox && allBox.checked ? 'all' : 'client'; }
  function call(action) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('scope', scope());
    return fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Server error (' + r.status + ')' }; })
          .then(function (d) { if (!d || !d.ok) throw new Error((d && d.error) || ('Server error (' + r.status + ')')); return d; });
      });
  }
  function render(d) {
    var j = d && d.job;
    if (!j) {
      statusEl.textContent = 'Not built yet for ' + (scope() === 'all' ? 'all clients' : 'this client') + '.';
      progress.hidden = true;
      btn.querySelector('span').textContent = 'Build previews';
      return;
    }
    var pct = j.total ? Math.round(j.processed / j.total * 100) : 100;
    fill.style.width = pct + '%';
    progress.hidden = j.finished && !busy;
    var bits = [j.processed + ' of ' + j.total + ' images checked', j.done + ' made', j.skipped + ' already up to date'];
    if (j.failed) bits.push(j.failed + ' could not be made (the original is shown)');
    if (j.missing) bits.push(j.missing + ' missing on disk');
    var saved = j.bytes_original ? ' · small previews are ' + fmtBytes(j.bytes_sm) + ' instead of ' + fmtBytes(j.bytes_original) + ' (' + fmtBytes(j.bytes_saved) + ' saved per full view)' : '';
    statusEl.textContent = (j.finished ? 'Done: ' : (busy ? 'Building… ' : 'Paused: ')) + bits.join(' · ') + saved + ' · format ' + String(j.format || '').toUpperCase() + '.';
    btn.querySelector('span').textContent = j.finished ? 'Build again' : 'Continue';
  }
  function fail(e) {
    busy = false; btn.disabled = false; if (allBox) allBox.disabled = false;
    statusEl.textContent = 'Stopped: ' + (e && e.message ? e.message : 'network error') + ' — press the button to continue.';
  }
  function loop() {
    call('step').then(function (d) {
      render(d);
      if (d.job && !d.job.finished) { loop(); return; }
      busy = false; btn.disabled = false; if (allBox) allBox.disabled = false; render(d);
    }, fail);
  }
  function refresh() { call('status').then(render, function (e) { statusEl.textContent = e.message; }); }

  btn.addEventListener('click', function () {
    if (busy) return;
    busy = true; btn.disabled = true; if (allBox) allBox.disabled = true;
    progress.hidden = false;
    statusEl.textContent = 'Listing images…';
    var resume = btn.querySelector('span').textContent === 'Continue';
    (resume ? call('status') : call('start')).then(function (d) { render(d); loop(); }, fail);
  });
  if (allBox) allBox.addEventListener('change', refresh);
  refresh();
})();
