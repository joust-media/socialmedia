<?php
/**
 * Prompt Library — admin list view.
 * Global (not client-scoped). Filterable by category + tag, searchable by
 * name + prompt text. Also renders the read-only Variables Reference.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/prompt-lib.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$flash       = $_GET['msg'] ?? '';
$tableReady  = hasPromptsTable($pdo);

// ---- Filters -------------------------------------------------
$categorySlugs = promptCategorySlugs();
$selectedCat   = strtolower(trim($_GET['cat'] ?? ''));
if (!in_array($selectedCat, $categorySlugs, true)) { $selectedCat = ''; }
$selectedTag   = trim($_GET['tag'] ?? '');
$search        = trim($_GET['q'] ?? '');

$prompts        = [];
$categoryCounts = array_fill_keys($categorySlugs, 0);
$totalCount     = 0;

if ($tableReady) {
    // Per-category counts ignore the category filter (so each pill shows its own
    // total within the current tag/search scope), mirroring feed.php's pills.
    $countWhere = [];
    $countParams = [];
    if ($selectedTag !== '') { $countWhere[] = 'tags LIKE ?';        $countParams[] = '%' . $selectedTag . '%'; }
    if ($search !== '')      { $countWhere[] = '(name LIKE ? OR prompt_text LIKE ?)';
                               $countParams[] = '%' . $search . '%';
                               $countParams[] = '%' . $search . '%'; }
    $countWhereSql = $countWhere ? ('WHERE ' . implode(' AND ', $countWhere)) : '';
    $cStmt = $pdo->prepare("SELECT category, COUNT(*) AS n FROM prompts $countWhereSql GROUP BY category");
    $cStmt->execute($countParams);
    foreach ($cStmt->fetchAll() as $row) {
        if (isset($categoryCounts[$row['category']])) {
            $categoryCounts[$row['category']] = (int)$row['n'];
        }
    }
    $totalCount = array_sum($categoryCounts);

    // Main list query.
    $where  = [];
    $params = [];
    if ($selectedCat !== '') { $where[] = 'category = ?';   $params[] = $selectedCat; }
    if ($selectedTag !== '') { $where[] = 'tags LIKE ?';    $params[] = '%' . $selectedTag . '%'; }
    if ($search !== '') {
        $where[]  = '(name LIKE ? OR prompt_text LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $stmt = $pdo->prepare("
        SELECT id, category, name, prompt_text, tags, compatible_models, updated_at
        FROM prompts
        $whereSql
        ORDER BY FIELD(category, 'camera','lighting','environment','product','character','references','custom'), name ASC
    ");
    $stmt->execute($params);
    $prompts = $stmt->fetchAll();
}

/** Build a library URL preserving the other filters. */
function libUrl($cat = null, $tag = null, $q = null) {
    global $selectedCat, $selectedTag, $search;
    $cat = $cat === null ? $selectedCat : $cat;
    $tag = $tag === null ? $selectedTag : $tag;
    $q   = $q   === null ? $search      : $q;
    $qs = [];
    if ($cat !== '') { $qs['cat'] = $cat; }
    if ($tag !== '') { $qs['tag'] = $tag; }
    if ($q   !== '') { $qs['q']   = $q; }
    return 'prompts' . ($qs ? '?' . http_build_query($qs) : '');
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Prompt Library — Joust Admin</title>
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
  body { background: var(--bg); color: var(--text);
         font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
         font-size: 15px; line-height: 1.4; min-height: 100vh; }
  .topbar { position: sticky; top: 0; z-index: 100;
            background: var(--surface); border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow); }
  .topbar-inner { max-width: 980px; margin: 0 auto; padding: 12px 20px;
                  display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .brand { display: flex; align-items: center; gap: 10px;
           font-weight: 700; font-size: 20px; color: var(--accent); letter-spacing: -0.5px; }
  .brand-mark { width: 32px; height: 32px; border-radius: 8px;
                background: var(--accent); color: #fff;
                display: flex; align-items: center; justify-content: center; font-weight: 800; }
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
  .btn.sm { padding: 6px 10px; font-size: 13px; }

  .wrap { max-width: 980px; margin: 0 auto; padding: 24px 20px 80px; }
  h1 { margin: 0 0 4px; font-size: 24px; letter-spacing: -0.3px; }
  .subtitle { color: var(--text-muted); margin: 0 0 22px; }
  .flash, .errors { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
                    font-size: 14px; font-weight: 500; }
  .flash  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .errors { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  [data-theme="dark"] .flash  { background: #14532d; color: #bbf7d0; border-color: #166534; }
  [data-theme="dark"] .errors { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }

  .card { background: var(--surface); border: 1px solid var(--border);
          border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 20px; overflow: hidden; }
  .card-header { padding: 14px 18px; border-bottom: 1px solid var(--border);
                 display: flex; align-items: center; justify-content: space-between;
                 gap: 12px; flex-wrap: wrap; }
  .card-title { font-size: 16px; font-weight: 700; margin: 0; }
  .card-body { padding: 18px; }

  /* Filter bar */
  .filters { display: flex; flex-direction: column; gap: 12px;
             background: var(--surface); border: 1px solid var(--border);
             border-radius: 12px; box-shadow: var(--shadow);
             padding: 14px 16px; margin-bottom: 18px; }
  .cat-pills { display: flex; flex-wrap: wrap; gap: 8px; }
  .cat-pill { display: inline-flex; align-items: center; gap: 6px;
              padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: 600;
              text-decoration: none; cursor: pointer;
              background: var(--surface-2); border: 1px solid var(--border);
              color: var(--text); transition: background 0.15s, border-color 0.15s; }
  .cat-pill:hover { background: var(--border); }
  .cat-pill.active { background: var(--accent); border-color: var(--accent); color: #fff; }
  .cat-pill-count { display: inline-flex; align-items: center; justify-content: center;
                    min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px;
                    background: rgba(0,0,0,0.08); font-size: 11px; font-weight: 800; }
  .cat-pill.active .cat-pill-count { background: rgba(255,255,255,0.25); }
  [data-theme="dark"] .cat-pill-count { background: rgba(255,255,255,0.12); }
  .filter-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .search-box { flex: 1; min-width: 200px; display: flex; gap: 8px; }
  .search-box input { flex: 1; background: var(--surface-2); border: 1px solid var(--border);
                      color: var(--text); padding: 9px 12px; border-radius: 8px; font: inherit; }
  .search-box input:focus { outline: none; border-color: var(--accent);
                            box-shadow: 0 0 0 3px rgba(24,119,242,0.15); }
  .active-tag { font-size: 13px; color: var(--text-muted); }
  .active-tag a { color: var(--accent); text-decoration: none; font-weight: 600; }

  /* Prompt rows */
  .prompt-row { display: grid; grid-template-columns: 130px 1fr auto;
                gap: 14px; padding: 16px 18px; border-top: 1px solid var(--border);
                align-items: start; }
  .prompt-row:first-child { border-top: none; }
  .cat-tag { display: inline-flex; align-items: center; gap: 5px;
             font-size: 11px; font-weight: 700; text-transform: uppercase;
             letter-spacing: 0.5px; padding: 4px 9px; border-radius: 10px;
             background: var(--surface-2); border: 1px solid var(--border);
             color: var(--text-muted); white-space: nowrap; }
  .prompt-name { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
  .prompt-text-preview { font-size: 13px; color: var(--text-muted); line-height: 1.5;
                         word-wrap: break-word; }
  .prompt-text-preview code { background: var(--surface-2); border-radius: 3px;
                              padding: 1px 4px; color: var(--accent);
                              font-size: 12px; }
  .prompt-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
  .tag-chip { font-size: 11px; padding: 3px 8px; border-radius: 10px;
              background: var(--surface-2); border: 1px solid var(--border);
              color: var(--text-muted); text-decoration: none; }
  .tag-chip:hover { border-color: var(--accent); color: var(--accent); }
  .model-tag { font-size: 11px; padding: 3px 8px; border-radius: 10px;
               background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; }
  [data-theme="dark"] .model-tag { background: #312e81; color: #c7d2fe; border-color: #4338ca; }
  .row-actions { display: flex; gap: 6px; }
  .empty { padding: 40px 20px; text-align: center; color: var(--text-muted);
           background: var(--surface); border: 1px dashed var(--border); border-radius: 12px; }

  /* Variables Reference table */
  .var-table { width: 100%; border-collapse: collapse; }
  .var-table th, .var-table td { text-align: left; padding: 10px 12px;
                                 border-bottom: 1px solid var(--border); font-size: 13px; }
  .var-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
                  color: var(--text-muted); }
  .var-table tr:last-child td { border-bottom: none; }
  .var-table code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                    background: var(--surface-2); border-radius: 4px; padding: 2px 6px;
                    color: var(--accent); font-size: 12px; }
</style>
</head>
<body>

<?= renderAppChrome('Prompt Library', [
      'active'   => 'studio',
      'width'    => '980px',
      'trailing' => '',
      'back'     => ['href' => 'admin', 'label' => 'Studio'],
      'links'    => [
        ['label' => 'New prompt', 'href' => 'add-prompt', 'primary' => true],
        ['label' => 'Sign out',   'href' => 'logout', 'attrs' => ['title' => 'Signed in as ' . currentAdmin()]],
      ],
    ]) ?>

<div class="wrap">

  <h1>Prompt Library</h1>
  <p class="subtitle">Reusable prompt building blocks. One library powers the Builder for every client.</p>

  <?php if ($flash): ?>
    <div class="flash">✓ <?= h($flash) ?></div>
  <?php endif; ?>

  <?php if (!$tableReady): ?>
    <div class="errors">
      ⚠ The <code>prompts</code> table doesn't exist yet. Visit
      <a href="migrate" style="color:inherit;text-decoration:underline;">migrate</a>
      to create it, then come back.
    </div>
  <?php else: ?>

    <!-- Filters -->
    <div class="filters">
      <div class="cat-pills">
        <a class="cat-pill <?= $selectedCat === '' ? 'active' : '' ?>" href="<?= h(libUrl('')) ?>">
          All <span class="cat-pill-count"><?= (int)$totalCount ?></span>
        </a>
        <?php foreach (promptCategories() as $slug => $meta): ?>
          <a class="cat-pill <?= $selectedCat === $slug ? 'active' : '' ?>" href="<?= h(libUrl($slug)) ?>">
            <?= h($meta['icon'] . ' ' . $meta['label']) ?>
            <span class="cat-pill-count"><?= (int)$categoryCounts[$slug] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="filter-row">
        <form class="search-box" method="GET" action="prompts">
          <?php if ($selectedCat !== ''): ?><input type="hidden" name="cat" value="<?= h($selectedCat) ?>"><?php endif; ?>
          <?php if ($selectedTag !== ''): ?><input type="hidden" name="tag" value="<?= h($selectedTag) ?>"><?php endif; ?>
          <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search name or prompt text…">
          <button type="submit" class="btn sm">Search</button>
          <?php if ($search !== ''): ?>
            <a class="btn sm" href="<?= h(libUrl(null, null, '')) ?>">Clear</a>
          <?php endif; ?>
        </form>
        <?php if ($selectedTag !== ''): ?>
          <span class="active-tag">
            Tag: <strong><?= h($selectedTag) ?></strong> ·
            <a href="<?= h(libUrl(null, '')) ?>">remove</a>
          </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Prompt list -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">
          <?= count($prompts) ?> <?= count($prompts) === 1 ? 'prompt' : 'prompts' ?>
          <?php if ($selectedCat !== '' || $selectedTag !== '' || $search !== ''): ?>
            <span style="font-weight:500;color:var(--text-muted);">(filtered)</span>
          <?php endif; ?>
        </h2>
      </div>
      <?php if (empty($prompts)): ?>
        <div class="card-body">
          <div class="empty">
            <?php if ($totalCount === 0): ?>
              No prompts yet. Click <strong>+ New prompt</strong> to add the first one.
            <?php else: ?>
              No prompts match the current filters.
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <?php foreach ($prompts as $p):
          $cat       = $p['category'];
          $previewRaw = mb_strimwidth((string)$p['prompt_text'], 0, 220, '…');
          // Highlight {{variables}} in the preview.
          $preview = preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', '<code>{{$1}}</code>', h($previewRaw));
          $tags    = splitCommaList($p['tags'] ?? '');
          $pModels = splitCommaList($p['compatible_models'] ?? '');
        ?>
          <div class="prompt-row">
            <div>
              <span class="cat-tag"><?= h(promptCategoryIcon($cat) . ' ' . promptCategoryLabel($cat)) ?></span>
            </div>
            <div>
              <div class="prompt-name"><?= h($p['name']) ?></div>
              <div class="prompt-text-preview"><?= $preview ?></div>
              <div class="prompt-meta">
                <?php foreach ($tags as $t): ?>
                  <a class="tag-chip" href="<?= h(libUrl(null, $t)) ?>">#<?= h($t) ?></a>
                <?php endforeach; ?>
                <?php if ($pModels): ?>
                  <?php foreach ($pModels as $ms): ?>
                    <span class="model-tag"><?= h(promptModelLabel($ms)) ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="model-tag">All models</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="row-actions">
              <a class="btn sm" href="add-prompt?edit=<?= (int)$p['id'] ?>">Edit</a>
              <form method="POST" action="add-prompt"
                    onsubmit="return confirm('Delete &quot;<?= h(addslashes($p['name'])) ?>&quot; permanently?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn sm" title="Delete">🗑</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  <?php endif; ?>

  <!-- Variables Reference -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">🔣 Variables Reference</h2>
      <span style="font-size:12px;color:var(--text-muted);">Read-only</span>
    </div>
    <div class="card-body" style="padding:0;">
      <table class="var-table">
        <thead>
          <tr><th>Variable</th><th>Pulls from</th></tr>
        </thead>
        <tbody>
          <?php foreach (promptVariables() as $vName => $vMeta): ?>
            <tr>
              <td><code>{{<?= h($vName) ?>}}</code></td>
              <td><?= h($vMeta['source']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body" style="border-top:1px solid var(--border);font-size:12px;color:var(--text-muted);">
      Use these inside prompt text with double braces. If a client has no value for a
      variable, the placeholder is removed and surrounding punctuation is tidied up.
    </div>
  </div>

</div>

</body>
</html>
