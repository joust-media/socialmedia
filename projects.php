<?php
/**
 * Projects / Tasks page.
 * ?client=hmf scopes to one company; omitted = shows all (admin view).
 *
 * Users can: add, edit inline, toggle done, delete.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Filters
$showDone = isset($_GET['done']) && $_GET['done'] === '1';

// Build task query
$where  = [];
$params = [];
if ($client) {
    $where[]  = "t.company_id = ?";
    $params[] = $client['id'];
}
if (!$showDone) {
    $where[] = "t.status <> 'done'";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$hasTaskUpdated = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'updated_at'")->rowCount() > 0;
$updatedSel = $hasTaskUpdated ? 't.updated_at,' : 'NULL AS updated_at,';
$tasksStmt = $pdo->prepare("
    SELECT t.id, t.company_id, t.title, t.description, t.status, t.priority,
           t.created_by, t.created_at, t.completed_at,
           $updatedSel
           c.name AS company_name
    FROM tasks t
    INNER JOIN companies c ON c.id = t.company_id
    $whereSql
    ORDER BY
        CASE t.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END,
        CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
        t.created_at DESC
");
$tasksStmt->execute($params);
$tasks = $tasksStmt->fetchAll();

// Companies for the add-task form (in admin / unscoped view, user picks one)
$allCompanies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

// Counts for the filter pill
$cntOpen = 0; $cntDone = 0;
$cntSql   = "SELECT status, COUNT(*) AS c FROM tasks";
$cntWhere = [];
$cntParams = [];
if ($client) {
    $cntWhere[] = "company_id = ?";
    $cntParams[] = $client['id'];
}
if ($cntWhere) $cntSql .= ' WHERE ' . implode(' AND ', $cntWhere);
$cntSql .= ' GROUP BY status';
$cntStmt = $pdo->prepare($cntSql);
$cntStmt->execute($cntParams);
foreach ($cntStmt->fetchAll() as $r) {
    if ($r['status'] === 'done') { $cntDone = (int)$r['c']; }
    else                         { $cntOpen += (int)$r['c']; }
}
?>
<?php
$navItems = clientNavItems($pdo, $client);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $client ? h($client['name']) . ' — Projects' : 'Projects' ?></title>
<style>
  :root {
    --bg: #18191a;
    --surface: #242526;
    --surface-2: #3a3b3c;
    --border: #3e4042;
    --text: #e4e6eb;
    --text-muted: #b0b3b8;
    --accent: #2d88ff;
    --accent-hover: #4599ff;
    --danger: #ef4444;
    --success: #16a34a;
    --warn: #f59e0b;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
    --toast-bg: #e4e6eb;
    --toast-text: #050505;
  }

  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 15px;
    line-height: 1.4;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
  }

  .topbar {
    position: sticky; top: 0; z-index: 100;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow);
  }
  .topbar-inner {
    max-width: 1100px; margin: 0 auto;
    padding: 10px 20px;
    display: flex; align-items: center; gap: 16px;
  }
  .brand {
    display: flex; align-items: center; gap: 10px;
    font-weight: 700; font-size: 18px;
    color: var(--text); letter-spacing: -0.3px;
    flex: 0 0 auto;
  }
  .brand-mark {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--accent); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
  }
  .brand-logo {
    width: 36px; height: 36px; border-radius: 8px;
    object-fit: contain;
    background: #fff;
    padding: 4px;
    border: 1px solid var(--border);
  }
  .brand-name { white-space: nowrap; max-width: 240px; overflow: hidden; text-overflow: ellipsis; }

  .client-nav {
    display: flex; align-items: center; gap: 4px;
    flex: 1; min-width: 0;
    overflow-x: auto;
    scrollbar-width: none;
  }
  .client-nav::-webkit-scrollbar { display: none; }
  .nav-link {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 12px; border-radius: 18px;
    background: transparent; border: 1px solid transparent;
    color: var(--text-muted);
    font-size: 13px; font-weight: 600;
    text-decoration: none; white-space: nowrap;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
  }
  .nav-link:hover { background: var(--surface-2); color: var(--text); }
  .nav-link.active {
    background: var(--surface-2); color: var(--text);
    border-color: var(--border);
  }
  .nav-link-icon { font-size: 14px; line-height: 1; }
  @media (max-width: 600px) {
    .nav-link-label { display: none; }
    .nav-link { padding: 7px 10px; }
    .brand-name { max-width: 120px; font-size: 15px; }
  }

  .wrap { max-width: 760px; margin: 0 auto; padding: 20px 16px 80px; }

  .page-title {
    font-size: 24px;
    font-weight: 700;
    letter-spacing: -0.3px;
    margin: 0 0 20px;
  }

  /* Add form */
  .add-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 16px;
    margin-bottom: 20px;
  }
  .add-card h2 {
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    margin: 0 0 12px;
  }
  .add-form {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .add-row {
    display: flex;
    gap: 10px;
  }
  .add-form input[type="text"],
  .add-form textarea,
  .add-form select {
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 10px 12px;
    border-radius: 8px;
    font: inherit;
    font-size: 14px;
    font-family: inherit;
    width: 100%;
  }
  .add-form textarea { resize: vertical; min-height: 50px; }
  .add-form input:focus,
  .add-form textarea:focus,
  .add-form select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .add-row > * { flex: 1; }
  .add-form select { max-width: 220px; flex: 0 1 220px; }
  .btn-primary {
    background: var(--accent);
    color: #fff;
    border: 1px solid var(--accent);
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    align-self: flex-start;
    transition: background 0.15s;
  }
  .btn-primary:hover { background: var(--accent-hover); }
  .btn-primary:disabled { opacity: 0.6; cursor: wait; }

  /* View toggle */
  .view-toggle {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
  }
  .view-pill {
    padding: 6px 14px;
    border-radius: 16px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
  }
  .view-pill:hover { background: var(--border); }
  .view-pill.active {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
  }
  .view-count {
    display: inline-block;
    margin-left: 6px;
    font-weight: 700;
    opacity: 0.85;
  }

  /* Task list */
  .task-list {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    overflow: hidden;
  }
  .task-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 12px;
    align-items: start;
    padding: 14px 16px;
    border-top: 1px solid var(--border);
  }
  .task-item:first-child { border-top: none; }
  .task-item.done { opacity: 0.55; }
  .task-item.done .task-title { text-decoration: line-through; }
  /* Highlight the task that an activity row deep-linked to (via #task-<id>) */
  .task-item { scroll-margin-top: 80px; }
  .task-item:target {
    animation: task-target-pulse 2.4s ease-out;
    box-shadow: inset 3px 0 0 var(--accent);
  }
  @keyframes task-target-pulse {
    0%   { background: rgba(24,119,242,0.18); }
    100% { background: transparent; }
  }
  [data-theme="dark"] .task-item:target {
    box-shadow: inset 3px 0 0 var(--accent);
  }

  .task-check {
    margin-top: 2px;
    width: 22px;
    height: 22px;
    cursor: pointer;
    accent-color: var(--success);
  }

  .task-body { min-width: 0; }
  .task-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    word-wrap: break-word;
    cursor: pointer;
    padding: 2px 6px;
    margin-left: -6px;
    border-radius: 4px;
    transition: background 0.15s;
  }
  .task-title:hover { background: var(--surface-2); }
  .task-title-edit {
    width: 100%;
    background: var(--surface-2);
    border: 1px solid var(--accent);
    color: var(--text);
    padding: 6px 8px;
    border-radius: 4px;
    font: inherit;
    font-size: 15px;
    font-weight: 600;
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .task-title-edit:focus { outline: none; }
  .task-desc {
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 4px;
    white-space: pre-wrap;
    word-wrap: break-word;
  }
  .task-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 6px;
    font-size: 12px;
    color: var(--text-muted);
  }
  .task-meta-pill {
    padding: 2px 8px;
    border-radius: 10px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    font-weight: 600;
  }
  .task-meta-pill.pri-high { background: #7f1d1d; color: #fecaca; border-color: transparent; }
  .task-meta-pill.pri-low  { background: #1e3a8a; color: #bfdbfe; border-color: transparent; }
  .task-meta-pill.creator-client { background: #365314; color: #d9f99d; border-color: transparent; }
  .task-updated { font-size: 12px; color: var(--text-muted); opacity: 0.85; white-space: nowrap; }

  .task-actions {
    display: flex;
    gap: 6px;
    align-items: center;
  }
  .task-actions button {
    background: transparent;
    border: 1px solid transparent;
    color: var(--text-muted);
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
    transition: background 0.15s, color 0.15s;
  }
  .task-actions button:hover { background: var(--surface-2); color: var(--text); }
  .task-actions button.danger:hover { background: #7f1d1d; color: #fca5a5; }

  .empty {
    padding: 60px 20px;
    text-align: center;
    color: var(--text-muted);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
  }
  .empty-icon { font-size: 48px; opacity: 0.4; margin-bottom: 8px; }
  .empty-title { font-size: 16px; font-weight: 600; color: var(--text); margin-bottom: 4px; }

  /* Toast */
  .toast {
    position: fixed; bottom: 24px; left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: var(--toast-bg); color: var(--toast-text);
    padding: 12px 20px; border-radius: 24px;
    font-size: 14px; font-weight: 600;
    opacity: 0; pointer-events: none;
    transition: opacity 0.25s, transform 0.25s;
    z-index: 1000; box-shadow: 0 4px 16px rgba(0,0,0,0.25);
  }
  .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

  @media (max-width: 560px) {
    .add-row { flex-direction: column; }
    .add-form select { max-width: none; flex: 1; }
  }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <?= renderBrand($client) ?>
    <nav class="client-nav"><?= renderClientNav($navItems, 'projects') ?></nav>
  </div>
</header>

<div class="wrap">

  <h1 class="page-title">📋 Projects</h1>

  <div class="add-card">
    <h2>Add new task</h2>
    <form class="add-form" id="addForm">
      <input type="text" name="title" id="newTitle"
             placeholder="What needs to get done?" maxlength="300" required>
      <textarea name="description" id="newDesc"
                placeholder="Optional details…" maxlength="5000"></textarea>
      <div class="add-row">
        <?php if (!$client): ?>
          <select name="company_id" id="newCompany" required>
            <option value="">— Choose client —</option>
            <?php foreach ($allCompanies as $co): ?>
              <option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="hidden" name="company_id" id="newCompany" value="<?= (int)$client['id'] ?>">
        <?php endif; ?>
        <select name="priority" id="newPriority">
          <option value="normal" selected>Normal priority</option>
          <option value="high">High priority</option>
          <option value="low">Low priority</option>
        </select>
      </div>
      <button type="submit" class="btn-primary" id="addBtn">Add task</button>
    </form>
  </div>

  <div class="view-toggle">
    <a class="view-pill <?= !$showDone ? 'active' : '' ?>"
       href="<?= h(clientUrl('projects.php')) ?>">
      Open<span class="view-count"><?= $cntOpen ?></span>
    </a>
    <a class="view-pill <?= $showDone ? 'active' : '' ?>"
       href="<?= h(clientUrl('projects.php', ['done' => '1'])) ?>">
      Done<span class="view-count"><?= $cntDone ?></span>
    </a>
  </div>

  <?php if (empty($tasks)): ?>
    <div class="empty">
      <div class="empty-icon">✓</div>
      <div class="empty-title">
        <?= $showDone ? 'No completed tasks yet.' : 'All caught up!' ?>
      </div>
      <div><?= $showDone ? 'Check back after you finish some.' : 'Add a new task above to get started.' ?></div>
    </div>
  <?php else: ?>
    <div class="task-list" id="taskList">
      <?php foreach ($tasks as $t):
        $isDone = $t['status'] === 'done';
      ?>
        <div class="task-item <?= $isDone ? 'done' : '' ?>"
             id="task-<?= (int)$t['id'] ?>"
             data-task-id="<?= (int)$t['id'] ?>"
             data-status="<?= h($t['status']) ?>">
          <input type="checkbox" class="task-check"
                 <?= $isDone ? 'checked' : '' ?>
                 data-task-toggle
                 title="Mark <?= $isDone ? 'open' : 'done' ?>">
          <div class="task-body">
            <div class="task-title"
                 data-task-title
                 data-raw="<?= h($t['title']) ?>"
                 title="Click to edit"><?= h($t['title']) ?></div>
            <?php if (!empty($t['description'])): ?>
              <div class="task-desc"><?= h($t['description']) ?></div>
            <?php endif; ?>
            <div class="task-meta">
              <?php if (!$client): ?>
                <span class="task-meta-pill"><?= h($t['company_name']) ?></span>
              <?php endif; ?>
              <?php if ($t['priority'] !== 'normal'): ?>
                <span class="task-meta-pill pri-<?= h($t['priority']) ?>">
                  <?= $t['priority'] === 'high' ? '🔥 High' : 'Low' ?>
                </span>
              <?php endif; ?>
              <?php if ($t['created_by'] === 'client'): ?>
                <span class="task-meta-pill creator-client">From client</span>
              <?php endif; ?>
              <span title="Created <?= h(absoluteTime($t['created_at'])) ?>">
                <?= h(date('M j', strtotime($t['created_at']))) ?>
              </span>
              <?php if (!empty($t['updated_at']) && $t['updated_at'] !== $t['created_at']): ?>
                <span class="task-updated"
                      title="Last edited: <?= h(absoluteTime($t['updated_at'])) ?>">
                  · edited <?= h(relativeTime($t['updated_at'])) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
          <div class="task-actions">
            <button type="button" data-task-delete title="Delete">🗑</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<div class="toast" id="toast">Done</div>

<script>
  const toastEl = document.getElementById('toast');
  let toastTimer;
  function showToast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1800);
  }

  const IS_CLIENT_VIEW = <?= $client ? 'true' : 'false' ?>;
  const CREATED_BY     = IS_CLIENT_VIEW ? 'client' : 'admin';

  // ---- Add task ---------------------------------------------------
  document.getElementById('addForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('addBtn');
    const title = document.getElementById('newTitle').value.trim();
    const desc  = document.getElementById('newDesc').value.trim();
    const pri   = document.getElementById('newPriority').value;
    const companyEl = document.getElementById('newCompany');
    const companyId = companyEl ? companyEl.value : '';

    if (!title) { showToast('Title required'); return; }
    if (!companyId) { showToast('Pick a client'); return; }

    btn.disabled = true;
    btn.textContent = 'Adding…';

    try {
      const fd = new FormData();
      fd.append('action', 'create');
      fd.append('company_id', companyId);
      fd.append('title', title);
      fd.append('description', desc);
      fd.append('priority', pri);
      fd.append('created_by', CREATED_BY);
      fd.append('actor', CREATED_BY);
      const res  = await fetch('task.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');

      showToast('✓ Task added');
      // Simplest approach: reload to re-render with server-sorted list
      window.location.reload();
    } catch (err) {
      btn.disabled = false;
      btn.textContent = 'Add task';
      showToast(err.message || 'Add failed');
    }
  });

  // ---- Toggle done ------------------------------------------------
  document.addEventListener('change', async (e) => {
    const cb = e.target.closest('[data-task-toggle]');
    if (!cb) return;
    const item = cb.closest('.task-item');
    const taskId = item.getAttribute('data-task-id');
    cb.disabled = true;

    try {
      const fd = new FormData();
      fd.append('action', 'toggle');
      fd.append('id', taskId);
      fd.append('actor', CREATED_BY);
      const res  = await fetch('task.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');

      item.setAttribute('data-status', data.status);
      item.classList.toggle('done', data.status === 'done');
      showToast(data.status === 'done' ? '✓ Completed' : 'Reopened');
    } catch (err) {
      cb.checked = !cb.checked; // revert
      showToast('Toggle failed');
    } finally {
      cb.disabled = false;
    }
  });

  // ---- Inline edit title -----------------------------------------
  document.addEventListener('click', (e) => {
    const title = e.target.closest('[data-task-title]');
    if (!title || title.dataset.editing === '1') return;
    title.dataset.editing = '1';

    const raw = title.getAttribute('data-raw');
    const item = title.closest('.task-item');
    const taskId = item.getAttribute('data-task-id');

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'task-title-edit';
    input.value = raw;
    input.maxLength = 300;

    title.replaceWith(input);
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);

    let saving = false;
    const finish = async (commit) => {
      if (saving) return;
      saving = true;

      const newVal = input.value.trim();
      const restore = (useVal) => {
        const div = document.createElement('div');
        div.className = 'task-title';
        div.setAttribute('data-task-title', '');
        div.setAttribute('data-raw', useVal);
        div.setAttribute('title', 'Click to edit');
        div.textContent = useVal;
        input.replaceWith(div);
      };

      if (!commit || newVal === '' || newVal === raw) { restore(raw); return; }

      try {
        const fd = new FormData();
        fd.append('action', 'update');
        fd.append('id', taskId);
        fd.append('title', newVal);
        fd.append('actor', CREATED_BY);
        const res  = await fetch('task.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        restore(newVal);
        showToast('✓ Updated');
      } catch (err) {
        restore(raw);
        showToast('Save failed');
      }
    };

    input.addEventListener('blur', () => finish(true));
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter')  { ev.preventDefault(); finish(true); }
      if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
    });
  });

  // ---- Delete -----------------------------------------------------
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-task-delete]');
    if (!btn) return;
    if (!confirm('Delete this task?')) return;

    const item = btn.closest('.task-item');
    const taskId = item.getAttribute('data-task-id');
    btn.disabled = true;

    try {
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', taskId);
      fd.append('actor', CREATED_BY);
      const res  = await fetch('task.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');

      item.style.transition = 'opacity 0.2s';
      item.style.opacity = '0';
      setTimeout(() => item.remove(), 200);
      showToast('✓ Deleted');
    } catch (err) {
      btn.disabled = false;
      showToast('Delete failed');
    }
  });

</script>

</body>
</html>
