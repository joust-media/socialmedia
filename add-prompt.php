<?php
/**
 * Prompt Library — create / edit / delete a single prompt.
 * Global (not client-scoped). Redirects back to prompts.php after a save.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/prompt-lib.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// The prompts table must exist before this page can do anything useful.
if (!hasPromptsTable($pdo)) {
    header('Location: prompts.php?msg=' . urlencode('Run migrate first — the prompts table is missing.'));
    exit;
}

$errors = [];
$flash  = $_GET['msg'] ?? '';

$categorySlugs = promptCategorySlugs();
$models        = promptModels();

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Delete -----------------------------------------------
    if ($action === 'delete') {
        $promptId = (int)($_POST['id'] ?? 0);
        if ($promptId > 0) {
            try {
                $pdo->prepare("DELETE FROM prompts WHERE id = ?")->execute([$promptId]);
                header('Location: prompts.php?msg=' . urlencode('Prompt deleted.'));
                exit;
            } catch (Exception $e) {
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Invalid prompt id.';
        }
    }

    // ---- Create / Update --------------------------------------
    if ($action === 'create' || $action === 'update') {
        $category   = strtolower(trim($_POST['category'] ?? ''));
        $name       = trim($_POST['name'] ?? '');
        $promptText = trim($_POST['prompt_text'] ?? '');
        $tagsRaw    = trim($_POST['tags'] ?? '');

        // compatible_models arrives as an array of checked slugs.
        $modelsPicked = [];
        if (!empty($_POST['compatible_models']) && is_array($_POST['compatible_models'])) {
            foreach ($_POST['compatible_models'] as $slug) {
                $slug = trim((string)$slug);
                if (isset($models[$slug]) && !in_array($slug, $modelsPicked, true)) {
                    $modelsPicked[] = $slug;
                }
            }
        }

        // Validation (spec 6.2)
        if ($name === '')                                  { $errors[] = 'Name is required.'; }
        if (mb_strlen($name) > 150)                        { $name = mb_substr($name, 0, 150); }
        if (!in_array($category, $categorySlugs, true))    { $errors[] = 'Pick a valid category.'; }
        if ($promptText === '')                            { $errors[] = 'Prompt text is required.'; }

        $badVars = invalidPromptVariables($promptText);
        if ($badVars) {
            $errors[] = 'Unknown variable' . (count($badVars) > 1 ? 's' : '')
                      . ': {{' . implode('}}, {{', $badVars) . '}}. '
                      . 'Only registered variables are allowed — see the Variables Reference.';
        }

        // Normalise the comma lists.
        $tagsClean   = implode(', ', splitCommaList($tagsRaw));
        $modelsClean = implode(',', $modelsPicked);

        if (!$errors) {
            try {
                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO prompts (category, name, prompt_text, tags, compatible_models)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $category, $name, $promptText,
                        $tagsClean   === '' ? null : $tagsClean,
                        $modelsClean === '' ? null : $modelsClean,
                    ]);
                    header('Location: prompts.php?msg=' . urlencode('Prompt created.'));
                    exit;
                } else {
                    $promptId = (int)($_POST['id'] ?? 0);
                    if ($promptId <= 0) { throw new Exception('Invalid prompt id.'); }
                    $stmt = $pdo->prepare("
                        UPDATE prompts
                        SET category = ?, name = ?, prompt_text = ?, tags = ?, compatible_models = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $category, $name, $promptText,
                        $tagsClean   === '' ? null : $tagsClean,
                        $modelsClean === '' ? null : $modelsClean,
                        $promptId,
                    ]);
                    header('Location: prompts.php?msg=' . urlencode('Prompt updated.'));
                    exit;
                }
            } catch (Exception $e) {
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// Load for display (edit mode) — or repopulate after a failed POST.
// -------------------------------------------------------------
$editPrompt = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM prompts WHERE id = ?");
    $stmt->execute([$editId]);
    $editPrompt = $stmt->fetch();
}

$isEdit     = (bool)$editPrompt;
$formAction = $isEdit ? 'update' : 'create';

// Values: failed POST repopulates from $_POST, otherwise from the row (edit) or blank.
$postedBack    = ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors);
$val_category  = $postedBack ? ($_POST['category'] ?? '')
               : ($isEdit ? $editPrompt['category'] : '');
$val_name      = $postedBack ? ($_POST['name'] ?? '')
               : ($isEdit ? $editPrompt['name'] : '');
$val_text      = $postedBack ? ($_POST['prompt_text'] ?? '')
               : ($isEdit ? $editPrompt['prompt_text'] : '');
$val_tags      = $postedBack ? ($_POST['tags'] ?? '')
               : ($isEdit ? (string)$editPrompt['tags'] : '');
$val_models    = $postedBack
               ? (is_array($_POST['compatible_models'] ?? null) ? $_POST['compatible_models'] : [])
               : ($isEdit ? splitCommaList($editPrompt['compatible_models'] ?? '') : []);

$formTitle      = $isEdit ? 'Edit prompt' : 'New prompt';
$formSubmitText = $isEdit ? 'Save changes' : 'Create prompt';

$script = <<<'JS'
<script>
  const KNOWN_VARS = __KNOWN_VARS__;
  const ta = document.getElementById('prompt_text');
  const detected = document.getElementById('varDetected');
  const submitBtn = document.getElementById('submitBtn');

  // Insert {{var}} at the cursor position in the prompt textarea.
  document.querySelectorAll('[data-var]').forEach(chip => {
    chip.addEventListener('click', () => {
      const token = '{{' + chip.getAttribute('data-var') + '}}';
      const start = ta.selectionStart ?? ta.value.length;
      const end   = ta.selectionEnd ?? ta.value.length;
      ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
      const pos = start + token.length;
      ta.focus();
      ta.setSelectionRange(pos, pos);
      refreshDetected();
    });
  });

  // Scan the prompt text for {{...}} and report known vs. unknown variables.
  function refreshDetected() {
    const found = [];
    const re = /\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g;
    let m;
    while ((m = re.exec(ta.value)) !== null) {
      const name = m[1].toLowerCase();
      if (!found.includes(name)) found.push(name);
    }
    if (!found.length) {
      detected.innerHTML = '';
      submitBtn.disabled = false;
      return;
    }
    const bad = found.filter(v => !KNOWN_VARS.includes(v));
    const parts = found.map(v =>
      KNOWN_VARS.includes(v)
        ? '<span class="ok">{{' + v + '}}</span>'
        : '<span class="bad">{{' + v + '}} — unknown</span>'
    );
    detected.innerHTML = 'Variables used: ' + parts.join(', ')
      + (bad.length ? ' — fix unknown variables before saving.' : '');
    submitBtn.disabled = bad.length > 0;
  }
  ta.addEventListener('input', refreshDetected);
  refreshDetected();

  // Model chip toggle visual state.
  document.querySelectorAll('[data-model-chip]').forEach(chip => {
    const cb = chip.querySelector('input[type="checkbox"]');
    cb.addEventListener('change', () => chip.classList.toggle('is-active', cb.checked));
  });
</script>
JS;
$script = str_replace('__KNOWN_VARS__', json_encode(promptVariableNames()), $script);

// ---- Chrome: the shared shell (back to the Prompt Library) ----
$pageTitle   = $isEdit ? 'Edit prompt' : 'New prompt';
$htmlTitle   = $pageTitle . ' — Prompt Library';
$navSubtitle = 'Prompt Library';
$navBack     = ['href' => pagePath('prompts'), 'label' => 'Prompts'];
$navTrailing = '';
$activeTab   = 'manage';
$bodyClass   = 'page-studio page-tool page-prompt-form';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/tools.css')) . '">' . "\n"
             . '<style>.tl-vars { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; } .tl-vars .studio-chip { font-family: var(--font-mono); color: var(--accent); }'
             . ' .tl-detected { margin-top: 8px; font-size: var(--text-footnote); color: var(--label-secondary); } .tl-detected .ok { color: var(--approve); } .tl-detected .bad { color: var(--deny); font-weight: 600; }</style>';
$footExtra   = $script;
include __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash): ?>
  <div class="studio-alert studio-alert--ok" role="status"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="studio-alert studio-alert--error" role="alert">
    <?php foreach ($errors as $err): ?><div><?= h($err) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" action="<?= h(pagePath('add-prompt') . ($isEdit ? '?edit=' . (int)$editPrompt['id'] : '')) ?>" id="promptForm" class="ui-card tl-card">
  <input type="hidden" name="action" value="<?= h($formAction) ?>">
  <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?= (int)$editPrompt['id'] ?>">
  <?php endif; ?>
  <div class="ui-card-header"><div class="ui-card-heading">
    <h3 class="ui-card-title"><?= h($formTitle) ?></h3>
    <p class="ui-card-subtitle">One building block for the AI Builder. Every client's Builder uses the same library.</p>
  </div>
  <?php if ($isEdit): ?><div class="tl-card-actions"><a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h(pagePath('add-prompt')) ?>">New prompt instead</a></div><?php endif; ?>
  </div>
  <div class="ui-card-body tl-fields">
    <div class="tl-grid">
      <div class="studio-field">
        <label class="studio-label" for="category">Category</label>
        <select class="ui-select" name="category" id="category" required>
          <option value="">Pick a category</option>
          <?php foreach (promptCategories() as $slug => $meta): ?>
            <option value="<?= h($slug) ?>" <?= $val_category === $slug ? 'selected' : '' ?>><?= h($meta['label']) ?><?= $meta['required'] ? '' : ' (optional)' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="studio-field">
        <label class="studio-label" for="name">Name</label>
        <input class="ui-input" type="text" name="name" id="name" maxlength="150" required value="<?= h($val_name) ?>" placeholder="e.g. Low-angle hero shot">
        <p class="studio-help">Short label — shown in the library and the Builder's menus.</p>
      </div>
    </div>

    <div class="studio-field">
      <label class="studio-label" for="prompt_text">Prompt text</label>
      <textarea class="ui-textarea" name="prompt_text" id="prompt_text" rows="6" required
                placeholder="The literal prompt text. Use {{variables}} where client data should go."><?= h($val_text) ?></textarea>
      <p class="studio-help">Insert a variable at the cursor:</p>
      <div class="tl-vars">
        <?php foreach (promptVariables() as $vName => $vMeta): ?>
          <button type="button" class="studio-chip" data-var="<?= h($vName) ?>" title="<?= h($vMeta['source']) ?>">{{<?= h($vName) ?>}}</button>
        <?php endforeach; ?>
      </div>
      <div class="tl-detected" id="varDetected" aria-live="polite"></div>
    </div>

    <div class="studio-field">
      <label class="studio-label" for="tags">Tags</label>
      <input class="ui-input" type="text" name="tags" id="tags" value="<?= h($val_tags) ?>" placeholder="lifestyle, studio, outdoor">
      <p class="studio-help">Comma-separated. Used to filter the library.</p>
    </div>

    <div class="studio-field">
      <span class="studio-label">Compatible models</span>
      <div class="studio-chips studio-chips--wrap">
        <?php foreach ($models as $slug => $meta): $checked = in_array($slug, (array)$val_models, true); ?>
          <label class="studio-chip<?= $checked ? ' is-active' : '' ?>" data-model-chip>
            <input type="checkbox" name="compatible_models[]" value="<?= h($slug) ?>"<?= $checked ? ' checked' : '' ?>>
            <?= h($meta['label']) ?> <span class="studio-chip-n"><?= h($meta['type']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="studio-help">Leave all off = works with every model.</p>
    </div>

    <div class="tl-actions">
      <a class="ui-btn ui-btn--gray" href="<?= h(pagePath('prompts')) ?>">Cancel</a>
      <button type="submit" class="ui-btn ui-btn--filled" id="submitBtn"><?= h($formSubmitText) ?></button>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
  <div class="tl-danger-zone">
    <form method="POST" action="<?= h(pagePath('add-prompt')) ?>" onsubmit="return confirm('Delete this prompt permanently?');">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$editPrompt['id'] ?>">
      <button type="submit" class="ui-btn ui-btn--plain studio-danger-btn">Delete this prompt</button>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
