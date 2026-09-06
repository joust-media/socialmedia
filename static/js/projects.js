/* =====================================================================
   Joust client portal — projects.js  (Projects page, spec §4.4)

   Extends the global App object from app.js (loaded after this file, both
   deferred; everything here waits for 'app:ready').

   App.swipeTask
     .threshold          px of rightward travel that commits (default 88)
     .init(container)    bind swipe + tap handlers inside container
     .toggle(row)        optimistic done <-> open via task.php action=toggle;
                         moves the row between the Open / Done lists at once,
                         rolls back with an error toast on a non-2xx / ok:false
     .move(row, status)  DOM-only: restyle the row and move it to the list
                         for `status` (returns an undo function)
     .applyStatus(row, status)  restyle only

   App.projects
     .open(row)          task detail / edit sheet (#taskSheet)
     .save(row, fields)  task.php action=update
     .remove(row)        task.php action=delete (confirm first)
     .create(fields)     task.php action=create → new row from #taskRowTemplate
     .refresh()          section counts + empty states
     .can                {create, edit, toggle, delete} from the server-rendered flags

   Rows: <li class="pj-item" data-task-item>
           <div class="pj-swipe-bg">…</div>
           <div class="ui-row pj-row" data-task-row data-task-id data-status data-priority …>
   ===================================================================== */
(function (window, document) {
  'use strict';

  var App = window.App = window.App || {};

  var root = null;          // [data-projects]
  var endpoint = 'task.php';
  var scoped = true;
  var can = { create: false, edit: false, toggle: false, delete: false };
  var currentRow = null;    // row shown in the detail sheet
  var suppressClickUntil = 0;

  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var PRIORITY_LABEL = { high: 'High priority', normal: 'Normal priority', low: 'Low priority' };
  var STATUS_LABEL = { open: 'Open', in_progress: 'In progress', done: 'Done' };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function $(sel, el) { return (el || document).querySelector(sel); }
  function toast(msg, kind) { if (App.toast) App.toast(msg, kind ? { kind: kind } : undefined); }
  function actor() { return App.actor || (document.body && document.body.dataset.actor) || 'client'; }

  /** 'YYYY-MM-DD HH:MM:SS' → 'Sep 1' (wall-clock, no timezone shifting) */
  function fmtDate(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
    if (!m) return '';
    return MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10);
  }
  function nowStamp() {
    var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }
  function rowOf(el) { return el && el.closest ? el.closest('[data-task-row]') : null; }
  function itemOf(row) { return row ? row.closest('[data-task-item]') : null; }
  function listFor(status) {
    var section = root && root.querySelector('[data-task-section="' + (status === 'done' ? 'done' : 'open') + '"]');
    return section ? section.querySelector('.ui-list') : null;
  }

  /* ---------------------------------------------------------------- */
  /* Row rendering (mirrors pjTaskSubtitle() / pjPriorityDot() in PHP) */
  /* ---------------------------------------------------------------- */
  function subtitleFor(ds) {
    var parts = [];
    if (ds.description) {
      var d = ds.description.replace(/\s+/g, ' ').trim();
      if (d.length > 70) d = d.slice(0, 70).replace(/\s+$/, '') + '…';
      parts.push(d);
    }
    if (ds.status === 'done' && ds.completedAt) parts.push('Done ' + fmtDate(ds.completedAt));
    else if (ds.createdAt) parts.push('Added ' + fmtDate(ds.createdAt));
    if (ds.createdBy === 'client') parts.push('From client');
    if (!scoped && ds.company) parts.push(ds.company);
    return parts.join(' · ');
  }

  function renderRow(row) {
    var ds = row.dataset;
    var text = $('.pj-open-text', row) || $('.ui-row-title', row);
    if (text) text.textContent = ds.title || '';
    var sub = $('.ui-row-subtitle', row);
    var subText = subtitleFor(ds);
    if (!sub && subText) {
      sub = document.createElement('div');
      sub.className = 'ui-row-subtitle';
      var body = $('.ui-row-body', row);
      if (body) body.appendChild(sub);
    }
    if (sub) { sub.textContent = subText; sub.hidden = !subText; }

    var p = ({ high: 1, normal: 1, low: 1 })[ds.priority] ? ds.priority : 'normal';
    var dot = $('[data-priority-dot]', row);
    if (dot) {
      dot.className = 'ui-dot pj-dot pj-dot--' + p;
      dot.setAttribute('data-priority', p);
      dot.setAttribute('aria-label', PRIORITY_LABEL[p]);
    }
    App.swipeTask.applyStatus(row, ds.status);
  }

  /* ---------------------------------------------------------------- */
  /* App.swipeTask                                                     */
  /* ---------------------------------------------------------------- */
  var swipe = App.swipeTask = {
    threshold: 88,
    _drag: null,
    _bound: false,

    applyStatus: function (row, status) {
      if (!row) return;
      status = status === 'done' || status === 'in_progress' ? status : 'open';
      var done = status === 'done';
      row.setAttribute('data-status', status);
      row.classList.toggle('is-done', done);

      var check = $('[data-task-toggle]', row);
      if (check) {
        check.setAttribute('aria-pressed', done ? 'true' : 'false');
        check.setAttribute('aria-label', done ? 'Mark open' : 'Mark done');
      }
      var trailing = $('.ui-row-trailing', row);
      var pill = $('[data-task-pill]', row);
      if (status === 'in_progress') {
        if (!pill && trailing) {
          pill = document.createElement('span');
          pill.className = 'ui-pill ui-pill--accent pj-pill';
          pill.setAttribute('data-task-pill', '');
          pill.setAttribute('data-status-pill', '');
          pill.setAttribute('data-status', 'in_progress');
          pill.textContent = STATUS_LABEL.in_progress;
          trailing.insertBefore(pill, trailing.firstChild);
        }
      } else if (pill) {
        pill.parentNode.removeChild(pill);
      }
      var sub = $('.ui-row-subtitle', row);
      if (sub) { var t = subtitleFor(row.dataset); sub.textContent = t; sub.hidden = !t; }
    },

    /** DOM only. Returns an undo() that restores the previous status + position. */
    move: function (row, status) {
      var item = itemOf(row);
      if (!item) return function () {};
      var prevStatus = row.getAttribute('data-status') || 'open';
      var prevCompleted = row.dataset.completedAt || '';
      var parent = item.parentNode, next = item.nextSibling;

      if (status === 'done') row.dataset.completedAt = row.dataset.completedAt || nowStamp();
      else row.dataset.completedAt = '';
      swipe.applyStatus(row, status);

      var target = listFor(status);
      if (target && target !== parent) {
        target.insertBefore(item, target.firstChild);
        item.classList.remove('pj-enter'); void item.offsetWidth; item.classList.add('pj-enter');
      }
      App.projects.refresh();

      return function undo() {
        row.dataset.completedAt = prevCompleted;
        swipe.applyStatus(row, prevStatus);
        if (parent) parent.insertBefore(item, next && next.parentNode === parent ? next : null);
        App.projects.refresh();
      };
    },

    /** Optimistic toggle through task.php (action=toggle). Resolves to {ok, status}. */
    toggle: function (row) {
      if (!row || !can.toggle || row.dataset.busy === '1') return Promise.resolve({ ok: false });
      var id = row.getAttribute('data-task-id');
      var prev = row.getAttribute('data-status') || 'open';
      var next = prev === 'done' ? 'open' : 'done';   // same rule as task.php

      var undo = swipe.move(row, next);
      row.dataset.busy = '1';
      row.classList.add('is-busy');

      return App.post(endpoint, { action: 'toggle', id: id, actor: actor() }).then(function (r) {
        delete row.dataset.busy;
        row.classList.remove('is-busy');
        if (!r.ok) {
          undo();
          toast(r.error || 'Could not update the task', 'error');
          return { ok: false, status: prev, error: r.error };
        }
        var got = r.data && r.data.status;
        if (got && got !== next) { swipe.move(row, got); next = got; }
        toast(next === 'done' ? 'Completed' : 'Reopened', 'success');
        row.dispatchEvent(new CustomEvent('task:toggle', { bubbles: true, detail: { id: id, status: next, row: row } }));
        return { ok: true, status: next };
      });
    },

    init: function (container) {
      if (swipe._bound || !container) return;
      swipe._bound = true;
      if (!window.PointerEvent) return;   // no swipe; tap targets still work
      container.addEventListener('pointerdown', onPointerDown, { passive: true });
      container.addEventListener('pointermove', onPointerMove, { passive: false });
      container.addEventListener('pointerup', onPointerEnd);
      container.addEventListener('pointercancel', onPointerEnd);
    }
  };

  function onPointerDown(e) {
    if (!can.toggle) return;
    if (e.pointerType !== 'touch' && e.pointerType !== 'pen') return;
    var row = rowOf(e.target);
    if (!row || row.dataset.busy === '1' || swipe._drag) return;
    swipe._drag = { row: row, item: itemOf(row), id: e.pointerId, x0: e.clientX, y0: e.clientY, locked: null, x: 0, armed: false, width: row.offsetWidth || 320 };
  }

  function onPointerMove(e) {
    var d = swipe._drag;
    if (!d || e.pointerId !== d.id) return;
    var dx = e.clientX - d.x0, dy = e.clientY - d.y0;

    if (d.locked === null) {
      if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
      if (Math.abs(dy) > Math.abs(dx)) { swipe._drag = null; return; }   // vertical: let the page scroll
      d.locked = 'x';
      try { d.row.setPointerCapture(d.id); } catch (err) {}
      d.item.setAttribute('data-swipe', d.row.getAttribute('data-status') === 'done' ? 'reopen' : 'done');
      d.row.classList.remove('is-settling');
      d.row.classList.add('is-dragging');
      suppressClickUntil = Date.now() + 600;
    }
    e.preventDefault();

    // Linear until a little past the threshold, then rubber-band; leftward drags resist hard.
    var x;
    if (dx >= 0) {
      var lin = swipe.threshold * 1.15;
      x = dx <= lin ? dx : lin + (dx - lin) * 0.35;
      x = Math.min(x, d.width * 0.6);
    } else {
      x = -Math.pow(-dx, 0.6);
    }
    d.x = x;
    d.row.style.transform = 'translateX(' + x.toFixed(1) + 'px)';
    var armed = x >= swipe.threshold;
    if (armed !== d.armed) { d.armed = armed; d.item.classList.toggle('is-armed', armed); }
  }

  function onPointerEnd(e) {
    var d = swipe._drag;
    if (!d || e.pointerId !== d.id) return;
    swipe._drag = null;
    if (d.locked !== 'x') return;
    try { d.row.releasePointerCapture(d.id); } catch (err) {}
    suppressClickUntil = Date.now() + 400;

    var row = d.row, item = d.item;
    var commit = d.armed && e.type !== 'pointercancel';
    row.classList.remove('is-dragging');
    row.classList.add('is-settling');
    row.style.transform = '';
    var cleanup = function () {
      row.classList.remove('is-settling');
      item.classList.remove('is-armed');
      item.removeAttribute('data-swipe');
    };
    var ms = App.reducedMotion && App.reducedMotion() ? 160 : 380;
    setTimeout(cleanup, ms);
    if (commit) swipe.toggle(row);
  }

  /* ---------------------------------------------------------------- */
  /* App.projects — sheets + create / update / delete                  */
  /* ---------------------------------------------------------------- */
  var projects = App.projects = {
    can: can,

    refresh: function () {
      if (!root) return;
      ['open', 'done'].forEach(function (s) {
        var list = listFor(s);
        var n = list ? list.children.length : 0;
        var count = root.querySelector('[data-count-for="' + s + '"]');
        if (count) count.textContent = String(n);
        var empty = root.querySelector('[data-empty-for="' + s + '"]');
        if (empty) empty.hidden = n > 0;
      });
    },

    open: function (row) {
      if (!row || !App.sheet) return;
      currentRow = row;
      var ds = row.dataset;
      var status = ds.status || 'open';
      var priority = PRIORITY_LABEL[ds.priority] ? ds.priority : 'normal';
      var meta = [];
      if (ds.createdAt) meta.push('Added ' + fmtDate(ds.createdAt));
      if (status === 'done' && ds.completedAt) meta.push('Done ' + fmtDate(ds.completedAt));
      if (ds.createdBy === 'client') meta.push('From client');
      else if (ds.createdBy === 'admin') meta.push('From Joust');
      if (ds.company) meta.push(ds.company);

      var pillKey = status === 'done' ? 'approved' : (status === 'in_progress' ? 'accent' : 'neutral');
      var html = '<div class="pj-form">'
        + '<div class="pj-detail-status">'
        +   '<span class="ui-pill ui-pill--' + pillKey + '" data-detail-pill>' + esc(STATUS_LABEL[status] || status) + '</span>'
        +   '<span class="ui-dot pj-dot pj-dot--' + priority + '" aria-hidden="true"></span>'
        +   '<span class="pj-detail-meta">' + esc(PRIORITY_LABEL[priority]) + '</span>'
        + '</div>';

      if (can.edit) {
        html += '<form id="taskEditForm" autocomplete="off" novalidate class="pj-form">'
          + '<label class="pj-field"><span class="pj-label">Title</span>'
          +   '<input class="ui-input" type="text" name="title" maxlength="300" required value="' + esc(ds.title) + '"></label>'
          + '<label class="pj-field"><span class="pj-label">Details <span class="text-tertiary">(optional)</span></span>'
          +   '<textarea class="ui-textarea" name="description" maxlength="5000" placeholder="Optional details">' + esc(ds.description) + '</textarea></label>'
          + '<label class="pj-field"><span class="pj-label">Priority</span>'
          +   '<select class="ui-select" name="priority">'
          +     ['normal', 'high', 'low'].map(function (p) {
                  return '<option value="' + p + '"' + (p === priority ? ' selected' : '') + '>' + esc(PRIORITY_LABEL[p].replace(' priority', '')) + '</option>';
                }).join('')
          +   '</select></label>'
          + '</form>';
      } else {
        html += '<h3 class="pj-detail-title">' + esc(ds.title) + '</h3>'
          + (ds.description ? '<p class="pj-detail-text">' + esc(ds.description) + '</p>' : '');
      }
      if (meta.length) html += '<p class="pj-detail-meta">' + esc(meta.join(' · ')) + '</p>';
      if (can.delete) {
        html += '<button type="button" class="ui-btn ui-btn--deny ui-btn--tinted ui-btn--block pj-danger" data-detail-delete>Delete task</button>';
      }
      html += '</div>';

      var footer = '';
      if (can.toggle || can.edit) {
        footer = '<div class="pj-sheet-actions">';
        if (can.toggle) {
          footer += '<button type="button" class="ui-btn ui-btn--gray ui-btn--large" data-detail-toggle>'
                  + (status === 'done' ? 'Reopen' : 'Mark done') + '</button>';
        }
        if (can.edit) {
          footer += '<button type="submit" form="taskEditForm" class="ui-btn ui-btn--filled ui-btn--large ui-btn--primary" data-detail-save>Save</button>';
        }
        footer += '</div>';
      }

      App.sheet.open('#taskSheet', { title: 'Task', html: html, footer: footer });
    },

    save: function (row, fields) {
      if (!row || !can.edit) return Promise.resolve({ ok: false });
      var title = (fields.title || '').trim();
      if (!title) { toast('Title required', 'error'); return Promise.resolve({ ok: false }); }
      var params = { action: 'update', id: row.getAttribute('data-task-id'), title: title,
                     description: (fields.description || '').trim(), priority: fields.priority || 'normal', actor: actor() };
      return App.post(endpoint, params).then(function (r) {
        if (!r.ok) { toast(r.error || 'Save failed', 'error'); return r; }
        row.dataset.title = params.title;
        row.dataset.description = params.description;
        row.dataset.priority = params.priority;
        renderRow(row);
        toast('Saved', 'success');
        return r;
      });
    },

    remove: function (row) {
      if (!row || !can.delete) return Promise.resolve({ ok: false });
      if (!window.confirm('Delete this task?')) return Promise.resolve({ ok: false });
      var item = itemOf(row);
      return App.post(endpoint, { action: 'delete', id: row.getAttribute('data-task-id'), actor: actor() }).then(function (r) {
        if (!r.ok) { toast(r.error || 'Delete failed', 'error'); return r; }
        if (item) { App.remove(item, projects.refresh); }
        toast('Deleted', 'success');
        return r;
      });
    },

    create: function (fields) {
      if (!can.create) return Promise.resolve({ ok: false });
      var title = (fields.title || '').trim();
      if (!title) { toast('Title required', 'error'); return Promise.resolve({ ok: false }); }
      if (!fields.company_id) { toast('Pick a client', 'error'); return Promise.resolve({ ok: false }); }
      var params = { action: 'create', company_id: fields.company_id, title: title,
                     description: (fields.description || '').trim(), priority: fields.priority || 'normal',
                     created_by: actor(), actor: actor() };
      return App.post(endpoint, params).then(function (r) {
        if (!r.ok) { toast(r.error || 'Could not add the task', 'error'); return r; }
        var task = (r.data && r.data.task) || {};
        var tpl = document.getElementById('taskRowTemplate');
        var list = listFor(task.status === 'done' ? 'done' : 'open');
        if (tpl && list && tpl.content) {
          var item = tpl.content.firstElementChild.cloneNode(true);
          var row = $('[data-task-row]', item);
          var id = task.id || r.data.id || '';
          item.id = 'task-' + id;
          row.dataset.taskId = id;
          row.dataset.title = task.title || title;
          row.dataset.description = task.description || params.description || '';
          row.dataset.status = task.status || 'open';
          row.dataset.priority = task.priority || params.priority;
          row.dataset.createdBy = task.created_by || params.created_by;
          row.dataset.createdAt = task.created_at || nowStamp();
          row.dataset.completedAt = task.completed_at || '';
          row.dataset.companyId = task.company_id || params.company_id;
          row.dataset.company = fields.company_name || '';
          renderRow(row);
          list.insertBefore(item, list.firstChild);
          item.classList.add('pj-enter');
          projects.refresh();
        } else {
          window.location.reload();
        }
        toast('Task added', 'success');
        return r;
      });
    }
  };

  /* ---------------------------------------------------------------- */
  /* Wiring                                                            */
  /* ---------------------------------------------------------------- */
  function init() {
    root = $('[data-projects]');
    if (!root) return;
    endpoint = root.getAttribute('data-endpoint') || endpoint;
    scoped = root.getAttribute('data-scoped') === '1';
    can.create = root.getAttribute('data-can-create') === '1';
    can.edit   = root.getAttribute('data-can-edit') === '1';
    can.toggle = root.getAttribute('data-can-toggle') === '1';
    can.delete = root.getAttribute('data-can-delete') === '1';

    swipe.init(root);
    projects.refresh();

    // Tap target + row tap
    root.addEventListener('click', function (e) {
      var check = e.target.closest('[data-task-toggle]');
      if (check) { e.preventDefault(); swipe.toggle(rowOf(check)); return; }
      var row = rowOf(e.target);
      if (!row) return;
      if (Date.now() < suppressClickUntil) { e.preventDefault(); return; }
      e.preventDefault();
      projects.open(row);
    });

    // Detail sheet actions
    var taskSheet = document.getElementById('taskSheet');
    if (taskSheet) {
      taskSheet.addEventListener('click', function (e) {
        if (!currentRow) return;
        if (e.target.closest('[data-detail-toggle]')) {
          var row = currentRow;
          App.sheet.close();
          swipe.toggle(row);
        } else if (e.target.closest('[data-detail-delete]')) {
          var r2 = currentRow;
          projects.remove(r2).then(function (r) { if (r && r.ok) App.sheet.close(); });
        }
      });
      taskSheet.addEventListener('submit', function (e) {
        var form = e.target.closest('#taskEditForm');
        if (!form || !currentRow) return;
        e.preventDefault();
        var btn = $('[data-detail-save]', taskSheet);
        if (btn) btn.disabled = true;
        projects.save(currentRow, {
          title: form.elements.title.value,
          description: form.elements.description.value,
          priority: form.elements.priority.value
        }).then(function (r) {
          if (btn) btn.disabled = false;
          if (r && r.ok) App.sheet.close();
        });
      });
      taskSheet.addEventListener('sheet:close', function () { currentRow = null; });
    }

    // Add-task sheet
    var addForm = document.getElementById('addTaskForm');
    if (addForm) {
      addForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('addTaskBtn');
        var companyEl = addForm.elements.company_id;
        var companyName = '';
        if (companyEl && companyEl.tagName === 'SELECT' && companyEl.selectedIndex > 0) {
          companyName = companyEl.options[companyEl.selectedIndex].text;
        }
        if (btn) { btn.disabled = true; btn.textContent = 'Adding…'; }
        projects.create({
          title: addForm.elements.title.value,
          description: addForm.elements.description ? addForm.elements.description.value : '',
          priority: addForm.elements.priority ? addForm.elements.priority.value : 'normal',
          company_id: companyEl ? companyEl.value : '',
          company_name: companyName
        }).then(function (r) {
          if (btn) { btn.disabled = false; btn.textContent = 'Add task'; }
          if (r && r.ok) { addForm.reset(); App.sheet.close(); }
        });
      });
    }
  }

  if (App._inited) init();
  else document.addEventListener('app:ready', init, { once: true });

})(window, document);
