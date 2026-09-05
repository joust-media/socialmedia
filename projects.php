<?php
/**
 * Projects / Tasks page (spec §4.4).
 *
 * ?client=<slug> scopes to one company; omitted = every company (unscoped view).
 *
 * Grouped inset lists — "Open" (open + in_progress) and "Done" — with the
 * priority as a colored leading dot, swipe-to-complete (touch) plus a tap
 * target, a task detail/edit sheet, and a nav-bar "+" that opens the add-task
 * sheet. Every action goes through the existing task.php contract
 * (create / update / toggle / delete). No database changes.
 *
 * Permissions — unchanged from the pre-redesign page (analysis §E): the
 * task list has never distinguished client from admin; everyone who can reach
 * the page can add, edit, toggle and delete. The only role-aware piece is the
 * actor / created_by stamp, which the Foundation phase made session-based
 * (isAdmin()) instead of "is ?client= present". The four $can* flags below are
 * the single place to tighten this later; anything they hide is not rendered
 * (server-side), never hidden with CSS.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------
// Permission matrix (see header comment). Today: everyone, for every verb.
// ---------------------------------------------------------------------
$canCreate = true;
$canEdit   = true;
$canToggle = true;
$canDelete = true;

// ---------------------------------------------------------------------
// Tasks — one query, all statuses; the page splits Open / Done itself.
// (The legacy ?done=1 filter is no longer needed and is ignored.)
// ---------------------------------------------------------------------
$where  = [];
$params = [];
if ($client) {
    $where[]  = "t.company_id = ?";
    $params[] = $client['id'];
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

$openTasks = [];
$doneTasks = [];
foreach ($tasks as $t) {
    if (($t['status'] ?? '') === 'done') $doneTasks[] = $t;
    else                                 $openTasks[] = $t;   // open + in_progress
}
$cntOpen = count($openTasks);
$cntDone = count($doneTasks);

// Companies for the add-task sheet (unscoped view: the user picks one)
$allCompanies = (!$client && $canCreate)
    ? $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll()
    : [];

// ---------------------------------------------------------------------
// Row helpers (page-local, guarded so a future shared copy can't collide)
// ---------------------------------------------------------------------
if (!function_exists('pjPriorityDot')) {
    /** priority → colored dot. high→--deny, normal→--pending, low→--label-tertiary */
    function pjPriorityDot(string $priority): string {
        $p = in_array($priority, ['high', 'normal', 'low'], true) ? $priority : 'normal';
        static $labels = ['high' => 'High priority', 'normal' => 'Normal priority', 'low' => 'Low priority'];
        return '<span class="ui-dot pj-dot pj-dot--' . $p . '" data-priority-dot data-priority="' . $p . '"'
             . ' role="img" aria-label="' . h($labels[$p]) . '"></span>';
    }
}

if (!function_exists('pjTaskSubtitle')) {
    /** "Added Sep 1 · From client · Kenda Tires" (+ description snippet) */
    function pjTaskSubtitle(array $t, bool $scoped): string {
        $parts = [];
        if (!empty($t['description'])) {
            $d = preg_replace('/\s+/u', ' ', trim((string)$t['description']));
            if (mb_strlen($d) > 70) $d = rtrim(mb_substr($d, 0, 70)) . '…';
            $parts[] = $d;
        }
        if (($t['status'] ?? '') === 'done' && !empty($t['completed_at'])) {
            $parts[] = 'Done ' . date('M j', strtotime($t['completed_at']));
        } elseif (!empty($t['created_at'])) {
            $parts[] = 'Added ' . date('M j', strtotime($t['created_at']));
        }
        if (($t['created_by'] ?? '') === 'client') $parts[] = 'From client';
        if (!$scoped && !empty($t['company_name'])) $parts[] = (string)$t['company_name'];
        return implode(' · ', $parts);
    }
}

if (!function_exists('pjTaskRow')) {
    /**
     * One task row: <li class="pj-item" …><div class="pj-swipe-bg">…</div><div class="ui-row pj-row">…</div></li>
     * Built with insetRow(); the swipe background layer is injected as the
     * <li>'s first child so the foreground row can slide over it.
     */
    function pjTaskRow(array $t, bool $scoped, array $can, bool $template = false): string {
        $id       = (int)($t['id'] ?? 0);
        $status   = in_array($t['status'] ?? '', ['open', 'in_progress', 'done'], true) ? $t['status'] : 'open';
        $priority = in_array($t['priority'] ?? '', ['high', 'normal', 'low'], true) ? $t['priority'] : 'normal';
        $isDone   = $status === 'done';
        $title    = (string)($t['title'] ?? '');

        // Title is a real button so keyboard users can open the detail sheet.
        $titleHtml = '<button type="button" class="pj-open" data-task-open>'
                   . '<span class="pj-open-text">' . h($title) . '</span></button>';

        $trailing = '';
        if ($status === 'in_progress') {
            $trailing .= statusPill('in_progress', false, ['label' => 'In progress', 'class' => 'ui-pill--accent pj-pill', 'attrs' => ['data-task-pill' => '']]);
        }
        if ($can['toggle']) {
            $trailing .= '<button type="button" class="pj-check" data-task-toggle'
                       . ' aria-pressed="' . ($isDone ? 'true' : 'false') . '"'
                       . ' aria-label="' . ($isDone ? 'Mark open' : 'Mark done') . '">'
                       . icon('checkmark', 'pj-check-icon') . '</button>';
        } elseif ($isDone) {
            $trailing .= '<span class="pj-check is-static" aria-hidden="true">' . icon('checkmark', 'pj-check-icon') . '</span>';
        }

        $attrs = [
            'data-task-row'     => '',
            'data-task-id'      => $id,
            'data-status'       => $status,
            'data-priority'     => $priority,
            'data-title'        => $title,
            'data-description'  => (string)($t['description'] ?? ''),
            'data-created-by'   => (string)($t['created_by'] ?? ''),
            'data-created-at'   => (string)($t['created_at'] ?? ''),
            'data-completed-at' => (string)($t['completed_at'] ?? ''),
            'data-company'      => (string)($t['company_name'] ?? ''),
            'data-company-id'   => (int)($t['company_id'] ?? 0),
        ];

        $html = insetRow([
            'leading'     => pjPriorityDot($priority),
            'title'       => $titleHtml,
            'rawTitle'    => true,
            'subtitle'    => pjTaskSubtitle($t, $scoped),
            'trailing'    => $trailing,
            'chevron'     => false,
            'class'       => 'pj-row' . ($isDone ? ' is-done' : ''),
            'attrs'       => $attrs,
        ]);

        $bg = '<div class="pj-swipe-bg" aria-hidden="true">'
            . '<span class="pj-swipe-action pj-swipe-action--done">' . icon('checkmark') . '<span>Done</span></span>'
            . '<span class="pj-swipe-action pj-swipe-action--reopen">' . icon('arrow-left') . '<span>Reopen</span></span>'
            . '</div>';
        $liAttrs = ' class="pj-item" data-task-item';
        if (!$template) $liAttrs .= ' id="task-' . $id . '"';
        return preg_replace('/^<li>/', '<li' . $liAttrs . '>' . $bg, $html, 1);
    }
}

$scoped = (bool)$client;
$can    = ['create' => $canCreate, 'edit' => $canEdit, 'toggle' => $canToggle, 'delete' => $canDelete];

// ---------------------------------------------------------------------
// Shell
// ---------------------------------------------------------------------
$pageTitle = 'Projects';
$htmlTitle = $client ? ($client['name'] . ' — Projects') : 'Projects';
$activeTab = 'projects';
$headExtra = '<link rel="stylesheet" href="' . h(staticUrl('css/projects.css')) . '">';

$navTrailing = $client ? clientAvatar($client) : '';
if ($canCreate) {
    // Nav-bar "+" (top-right trailing slot) → add-task sheet. Rendered only for whoever can create.
    $navTrailing .= '<button type="button" class="ui-btn ui-btn--tinted ui-btn--icon pj-add"'
                  . ' data-sheet-open="#addTaskSheet" aria-label="New task" title="New task">'
                  . icon('plus') . '</button>';
}

include __DIR__ . '/partials/layout-top.php';
?>

<div class="pj" id="projects"
     data-projects
     data-endpoint="task.php"
     data-scoped="<?= $scoped ? '1' : '0' ?>"
     data-can-create="<?= $canCreate ? '1' : '0' ?>"
     data-can-edit="<?= $canEdit ? '1' : '0' ?>"
     data-can-toggle="<?= $canToggle ? '1' : '0' ?>"
     data-can-delete="<?= $canDelete ? '1' : '0' ?>">

  <?php
  // ---- Open ---------------------------------------------------------
  echo insetListOpen(
      'Open <span class="pj-count" data-count-for="open">' . $cntOpen . '</span>',
      ['raw' => true, 'id' => 'taskListOpen', 'class' => 'pj-group', 'attrs' => ['data-task-section' => 'open']]
  );
  foreach ($openTasks as $t) echo pjTaskRow($t, $scoped, $can);
  echo insetListClose();
  echo card('<p class="pj-empty-text">No open tasks</p>', [
      'variant' => 'quiet', 'class' => 'pj-empty',
      'attrs'   => ['data-empty-for' => 'open'] + ($cntOpen > 0 ? ['hidden' => ''] : []),
  ]);

  // ---- Done ---------------------------------------------------------
  echo insetListOpen(
      'Done <span class="pj-count" data-count-for="done">' . $cntDone . '</span>',
      ['raw' => true, 'id' => 'taskListDone', 'class' => 'pj-group pj-group--done', 'attrs' => ['data-task-section' => 'done']]
  );
  foreach ($doneTasks as $t) echo pjTaskRow($t, $scoped, $can);
  echo insetListClose();
  echo card('<p class="pj-empty-text">Nothing completed yet</p>', [
      'variant' => 'quiet', 'class' => 'pj-empty',
      'attrs'   => ['data-empty-for' => 'done'] + ($cntDone > 0 ? ['hidden' => ''] : []),
  ]);
  ?>

  <?php if ($canToggle): ?>
    <p class="pj-hint ui-list-footer">Swipe a task to the right to complete it, or tap the circle.</p>
  <?php endif; ?>
</div>

<?php if ($canCreate): ?>
  <!-- Blank row cloned by projects.js when a task is created -->
  <template id="taskRowTemplate">
    <?= pjTaskRow([
        'id' => 0, 'title' => '', 'description' => '', 'status' => 'open', 'priority' => 'normal',
        'created_by' => '', 'created_at' => '', 'completed_at' => null, 'company_name' => '', 'company_id' => 0,
    ], $scoped, $can, true) ?>
  </template>
<?php endif; ?>

<?php
// ---------------------------------------------------------------------
// Sheets (rendered after <main>, before app.js) + page script
// ---------------------------------------------------------------------
ob_start();

if ($canCreate) {
    $sheetId    = 'addTaskSheet';
    $sheetTitle = 'New task';
    $sheetClass = 'pj-sheet';
    ob_start(); ?>
    <form class="pj-form" id="addTaskForm" autocomplete="off" novalidate>
      <label class="pj-field">
        <span class="pj-label">Title</span>
        <input class="ui-input" type="text" name="title" id="newTitle" maxlength="300" required
               placeholder="What needs to get done?" data-sheet-autofocus>
      </label>
      <label class="pj-field">
        <span class="pj-label">Details <span class="text-tertiary">(optional)</span></span>
        <textarea class="ui-textarea" name="description" id="newDesc" maxlength="5000"
                  placeholder="Optional details"></textarea>
      </label>
      <div class="pj-field-row">
        <?php if (!$client): ?>
          <label class="pj-field">
            <span class="pj-label">Client</span>
            <select class="ui-select" name="company_id" id="newCompany" required>
              <option value="">Choose a client</option>
              <?php foreach ($allCompanies as $co): ?>
                <option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php else: ?>
          <input type="hidden" name="company_id" id="newCompany" value="<?= (int)$client['id'] ?>">
        <?php endif; ?>
        <label class="pj-field">
          <span class="pj-label">Priority</span>
          <select class="ui-select" name="priority" id="newPriority">
            <option value="normal" selected>Normal</option>
            <option value="high">High</option>
            <option value="low">Low</option>
          </select>
        </label>
      </div>
    </form>
    <?php
    $sheetBody   = ob_get_clean();
    $sheetFooter = '<button type="submit" form="addTaskForm" class="ui-btn ui-btn--filled ui-btn--large ui-btn--block" id="addTaskBtn">Add task</button>';
    include __DIR__ . '/partials/sheet.php';
}

// Task detail / edit sheet — body and footer are filled by projects.js.
$sheetId    = 'taskSheet';
$sheetTitle = 'Task';
$sheetClass = 'pj-sheet';
include __DIR__ . '/partials/sheet.php';
?>
<script src="<?= h(staticUrl('js/projects.js')) ?>" defer></script>
<?php
$footExtra = ob_get_clean();

include __DIR__ . '/partials/layout-bottom.php';
