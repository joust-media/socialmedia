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
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $isEdit ? 'Edit prompt' : 'Add a prompt' ?> — Joust Admin</title>
<?= renderAppHead() ?>
<style>
  :root {
    --bg: #f0f2f5; --surface: #ffffff; --surface-2: #f7f8fa;
    --border: #dadde1; --text: #050505; --text-muted: #65676b;
    --accent: #1877f2; --accent-hover: #166fe5;
    --danger: #dc2626; --success: #16a34a;
    --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04);
  }
  [data-theme="dark"] {
    --bg: #18191a; --surface: #242526; --surface-2: #3a3b3c;
    --border: #3e4042; --text: #e4e6eb; --text-muted: #b0b3b8;
    --accent: #2d88ff; --accent-hover: #4599ff;
    --danger: #ef4444; --success: #16a34a;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 15px; line-height: 1.4; min-height: 100vh;
  }
  .topbar { position: sticky; top: 0; z-index: 100;
            background: var(--surface); border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow); }
  .topbar-inner { max-width: 860px; margin: 0 auto; padding: 12px 20px;
                  display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .brand { display: flex; align-items: center; gap: 10px;
           font-weight: 700; font-size: 20px; color: var(--accent); letter-spacing: -0.5px; }
  .brand-mark { width: 32px; height: 32px; border-radius: 8px;
                background: var(--accent); color: #fff;
                display: flex; align-items: center; justify-content: center; font-weight: 800; }
  .brand-sub { font-size: 12px; font-weight: 600; color: var(--text-muted);
               text-transform: uppercase; letter-spacing: 1px;
               padding: 3px 8px; border-radius: 4px;
               background: var(--surface-2); border: 1px solid var(--border); }
  .top-actions { display: flex; gap: 8px; align-items: center; }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px;
         padding: 8px 14px; border-radius: 8px; font-size: 14px; font-weight: 600;
         cursor: pointer; border: 1px solid var(--border);
         background: var(--surface-2); color: var(--text);
         text-decoration: none; transition: background 0.15s, transform 0.1s; }
  .btn:hover { background: var(--border); }
  .btn:active { transform: scale(0.98); }
  .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .btn.primary:hover { background: var(--accent-hover); }
  .btn.primary:disabled { opacity: 0.5; cursor: not-allowed; }
  .btn.danger { background: var(--danger); color: #fff; border-color: var(--danger); }
  .btn.sm { padding: 6px 10px; font-size: 13px; }

  .wrap { max-width: 860px; margin: 0 auto; padding: 24px 20px 80px; }
  .flash, .errors { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
                    font-size: 14px; font-weight: 500; }
  .flash  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .errors { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  [data-theme="dark"] .flash  { background: #14532d; color: #bbf7d0; border-color: #166534; }
  [data-theme="dark"] .errors { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }

  .card { background: var(--surface); border: 1px solid var(--border);
          border-radius: 12px; box-shadow: var(--shadow);
          margin-bottom: 24px; overflow: hidden; }
  .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border);
                 display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .card-title { font-size: 17px; font-weight: 700; margin: 0; }
  .card-body { padding: 20px; }

  .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
  .field label { font-size: 13px; font-weight: 600; color: var(--text-muted);
                 text-transform: uppercase; letter-spacing: 0.5px; }
  .field input[type="text"], .field select, .field textarea {
    background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text); padding: 10px 12px; border-radius: 8px;
    font: inherit; width: 100%; font-size: 15px;
  }
  .field textarea { resize: vertical; min-height: 90px; font-family: inherit; }
  .field textarea.prompt-text { min-height: 130px; }
  .field input:focus, .field select:focus, .field textarea:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .field .help { font-size: 12px; color: var(--text-muted); }
  .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 640px) { .field-row { grid-template-columns: 1fr; } }

  /* Variable picker */
  .var-picker { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
  .var-chip { font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
              font-size: 12px; padding: 5px 9px; border-radius: 6px;
              background: var(--surface-2); border: 1px solid var(--border);
              color: var(--accent); cursor: pointer; font-weight: 600; }
  .var-chip:hover { background: var(--accent); color: #fff; border-color: var(--accent); }
  .var-detected { margin-top: 8px; font-size: 12px; }
  .var-detected .ok   { color: var(--success); }
  .var-detected .bad  { color: var(--danger); font-weight: 700; }

  /* Model checkboxes */
  .model-group { display: flex; flex-wrap: wrap; gap: 8px; }
  .model-chip { display: inline-flex; align-items: center; gap: 6px;
                padding: 7px 12px; background: var(--surface-2);
                border: 1px solid var(--border); border-radius: 20px;
                font-size: 13px; font-weight: 600; cursor: pointer; user-select: none;
                transition: background 0.15s, border-color 0.15s, color 0.15s; }
  .model-chip input { display: none; }
  .model-chip:hover { background: var(--border); }
  .model-chip.checked { background: var(--accent); border-color: var(--accent); color: #fff; }
  .model-chip.checked::before { content: '✓ '; font-weight: 700; }
  .model-type-tag { font-size: 10px; opacity: 0.7; text-transform: uppercase; }

  .form-actions { display: flex; gap: 10px; justify-content: flex-end;
                  margin-top: 6px; padding-top: 20px; border-top: 1px solid var(--border); }
</style>
</head>
<body>

<?= renderAppChrome($isEdit ? 'Edit prompt' : 'New prompt', [
      'subtitle' => 'Prompt Library',
      'active'   => 'studio',
      'width'    => '860px',
      'trailing' => '',
      'back'     => ['href' => 'prompts.php', 'label' => 'Prompts'],
      'links'    => [
        ['label' => 'Sign out', 'href' => 'logout.php', 'attrs' => ['title' => 'Signed in as ' . currentAdmin()]],
      ],
    ]) ?>

<div class="wrap">

  <?php if ($flash): ?>
    <div class="flash">✓ <?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errors">
      <?php foreach ($errors as $err): ?><div>⚠ <?= h($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><?= h($formTitle) ?></h2>
      <?php if ($isEdit): ?>
        <a class="btn sm" href="add-prompt.php">+ New instead</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" action="add-prompt.php<?= $isEdit ? '?edit=' . (int)$editPrompt['id'] : '' ?>" id="promptForm">
        <input type="hidden" name="action" value="<?= h($formAction) ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$editPrompt['id'] ?>">
        <?php endif; ?>

        <div class="field-row">
          <div class="field">
            <label for="category">Category</label>
            <select name="category" id="category" required>
              <option value="">— Pick a category —</option>
              <?php foreach (promptCategories() as $slug => $meta): ?>
                <option value="<?= h($slug) ?>" <?= $val_category === $slug ? 'selected' : '' ?>>
                  <?= h($meta['icon'] . ' ' . $meta['label']) ?><?= $meta['required'] ? '' : ' (optional)' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="name">Name</label>
            <input type="text" name="name" id="name" maxlength="150" required
                   value="<?= h($val_name) ?>"
                   placeholder="e.g. Low-angle hero shot">
            <span class="help">Short admin label — shown in the library and Builder dropdowns.</span>
          </div>
        </div>

        <div class="field">
          <label for="prompt_text">Prompt text</label>
          <textarea name="prompt_text" id="prompt_text" class="prompt-text" required
                    placeholder="The literal prompt text. Use {{variables}} where client data should be injected."><?= h($val_text) ?></textarea>
          <span class="help">Insert a dynamic variable:</span>
          <div class="var-picker">
            <?php foreach (promptVariables() as $vName => $vMeta): ?>
              <button type="button" class="var-chip" data-var="<?= h($vName) ?>"
                      title="<?= h($vMeta['source']) ?>">{{<?= h($vName) ?>}}</button>
            <?php endforeach; ?>
          </div>
          <div class="var-detected" id="varDetected"></div>
        </div>

        <div class="field">
          <label for="tags">Tags</label>
          <input type="text" name="tags" id="tags"
                 value="<?= h($val_tags) ?>"
                 placeholder="lifestyle, studio, outdoor">
          <span class="help">Comma-separated. Used to filter the library.</span>
        </div>

        <div class="field">
          <label>Compatible models</label>
          <div class="model-group">
            <?php foreach ($models as $slug => $meta):
              $checked = in_array($slug, (array)$val_models, true);
            ?>
              <label class="model-chip <?= $checked ? 'checked' : '' ?>" data-model-chip>
                <input type="checkbox" name="compatible_models[]" value="<?= h($slug) ?>"
                       <?= $checked ? 'checked' : '' ?>>
                <?= h($meta['label']) ?>
                <span class="model-type-tag"><?= h($meta['type']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <span class="help">Leave all unchecked = works with every model.</span>
        </div>

        <div class="form-actions">
          <a class="btn" href="prompts.php">Cancel</a>
          <button type="submit" class="btn primary" id="submitBtn"><?= h($formSubmitText) ?></button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($isEdit): ?>
    <form method="POST" action="add-prompt.php"
          onsubmit="return confirm('Delete this prompt permanently?');"
          style="text-align:right;">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$editPrompt['id'] ?>">
      <button type="submit" class="btn danger sm">🗑 Delete this prompt</button>
    </form>
  <?php endif; ?>

</div>

<script>
  const KNOWN_VARS = <?= json_encode(promptVariableNames()) ?>;
  const ta = document.getElementById('prompt_text');
  const detected = document.getElementById('varDetected');
  const submitBtn = document.getElementById('submitBtn');

  // Insert {{var}} at the cursor position in the prompt textarea.
  document.querySelectorAll('.var-chip').forEach(chip => {
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
        : '<span class="bad">{{' + v + '}} ✗ unknown</span>'
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
    cb.addEventListener('change', () => chip.classList.toggle('checked', cb.checked));
  });
</script>

</body>
</html>
