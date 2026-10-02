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
    return pagePath('prompts') . ($qs ? '?' . http_build_query($qs) : '');
}

// ---- Chrome: the shared shell (large title, back to Manage → Tools, tab bar, Appearance) ----
$pageTitle   = 'Prompt Library';
$htmlTitle   = 'Prompt Library — Joust Media';
$navSubtitle = 'Manage · Tools';
$navBack     = ['href' => adminToolsUrl(), 'label' => 'Manage'];
$navTrailing = '';
// Page button under the large title (the nav row stays: back · title · + New · Appearance — nothing overflows at 320px)
$navLinks    = [['label' => 'New prompt', 'href' => pagePath('add-prompt'), 'tinted' => true, 'attrs' => ['data-new-prompt' => '1']],
                ['label' => 'Vehicle Library', 'href' => pagePath('vehicles')]];
$activeTab   = 'manage';
$bodyClass   = 'page-studio page-tool page-prompts';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/tools.css')) . '">';
$filtered    = $selectedCat !== '' || $selectedTag !== '' || $search !== '';
include __DIR__ . '/partials/layout-top.php';
?>

<p class="tl-intro">Reusable prompt building blocks. One library powers the AI Builder for every client.</p>

<?php if ($flash): ?>
  <div class="studio-alert studio-alert--ok" role="status"><?= h($flash) ?></div>
<?php endif; ?>

<?php if (!$tableReady): ?>
  <div class="studio-alert studio-alert--error" role="alert">
    The <code>prompts</code> table doesn't exist yet. Run <a href="<?= h(pagePath('migrate')) ?>">migrate</a> to create it, then come back.
  </div>
<?php else: ?>

  <nav class="studio-chips tl-filters" aria-label="Category">
    <a class="studio-chip<?= $selectedCat === '' ? ' is-active' : '' ?>" href="<?= h(libUrl('')) ?>"<?= $selectedCat === '' ? ' aria-current="page"' : '' ?>>All <span class="studio-chip-n"><?= (int)$totalCount ?></span></a>
    <?php foreach (promptCategories() as $slug => $meta): $on = $selectedCat === $slug; ?>
      <a class="studio-chip<?= $on ? ' is-active' : '' ?>" href="<?= h(libUrl($slug)) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= h($meta['label']) ?> <span class="studio-chip-n"><?= (int)$categoryCounts[$slug] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <form class="tl-search" method="GET" action="<?= h(pagePath('prompts')) ?>" role="search" data-tool-search>
    <?php if ($selectedCat !== ''): ?><input type="hidden" name="cat" value="<?= h($selectedCat) ?>"><?php endif; ?>
    <?php if ($selectedTag !== ''): ?><input type="hidden" name="tag" value="<?= h($selectedTag) ?>"><?php endif; ?>
    <label class="ui-visually-hidden" for="promptSearch">Search prompts</label>
    <input class="ui-input" type="search" id="promptSearch" name="q" value="<?= h($search) ?>" placeholder="Search name or text">
    <button type="submit" class="ui-btn ui-btn--gray">Search</button>
    <?php if ($search !== ''): ?>
      <a class="ui-btn ui-btn--plain" href="<?= h(libUrl(null, null, '')) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <?php if ($selectedTag !== ''): ?>
    <div class="tl-active">
      <a class="studio-chip is-active" href="<?= h(libUrl(null, '')) ?>" title="Remove this filter">#<?= h($selectedTag) ?> <?= icon('xmark') ?></a>
    </div>
  <?php endif; ?>

  <?= insetListOpen(count($prompts) . ' ' . (count($prompts) === 1 ? 'prompt' : 'prompts') . ($filtered ? ' · filtered' : ''), ['attrs' => ['data-prompt-list' => '1']]) ?>
    <?php if (!$prompts): ?>
      <li><div class="ui-row"><div class="ui-row-body"><div class="ui-row-subtitle" style="white-space:normal">
        <?= $totalCount === 0 ? 'No prompts yet. Use “New prompt” to add the first one.' : 'No prompts match the current filters.' ?>
      </div></div></div></li>
    <?php endif; ?>
    <?php foreach ($prompts as $p):
      $cat        = $p['category'];
      $previewRaw = mb_strimwidth((string)$p['prompt_text'], 0, 220, '…');
      // Highlight {{variables}} in the preview.
      $preview = preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', '<code class="tl-code">{{$1}}</code>', h($previewRaw));
      $tags    = splitCommaList($p['tags'] ?? '');
      $pModels = splitCommaList($p['compatible_models'] ?? '');
    ?>
      <li><div class="ui-row tl-row" data-prompt="<?= (int)$p['id'] ?>">
        <div class="ui-row-body">
          <p class="tl-kicker"><?= h(promptCategoryLabel($cat)) ?></p>
          <div class="ui-row-title ui-row-title--wrap"><?= h($p['name']) ?></div>
          <div class="tl-preview"><?= $preview ?></div>
          <div class="tl-tags">
            <?php foreach ($tags as $t): ?>
              <a class="tl-tag" href="<?= h(libUrl(null, $t)) ?>">#<?= h($t) ?></a>
            <?php endforeach; ?>
            <?php if ($pModels): foreach ($pModels as $ms): ?>
              <span class="tl-tag tl-tag--accent"><?= h(promptModelLabel($ms)) ?></span>
            <?php endforeach; else: ?>
              <span class="tl-tag tl-tag--accent">All models</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="ui-row-trailing">
          <a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h(pagePath('add-prompt') . '?edit=' . (int)$p['id']) ?>">Edit</a>
          <form method="POST" action="<?= h(pagePath('add-prompt')) ?>" onsubmit="return confirm(<?= h(json_encode('Delete “' . $p['name'] . '” permanently?')) ?>);">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn">Delete</button>
          </form>
        </div>
      </div></li>
    <?php endforeach; ?>
  <?= insetListClose() ?>

<?php endif; ?>

<section class="ui-card tl-card" data-variables>
  <div class="ui-card-header"><div class="ui-card-heading">
    <h3 class="ui-card-title">Variables reference</h3>
    <p class="ui-card-subtitle">Use these inside prompt text with double braces. If a client has no value for a variable, the placeholder is removed and the punctuation around it is tidied up.</p>
  </div></div>
  <div class="ui-card-body tl-table-wrap">
    <table class="studio-table">
      <thead><tr><th>Variable</th><th>Pulls from</th></tr></thead>
      <tbody>
        <?php foreach (promptVariables() as $vName => $vMeta): ?>
          <tr><td><code class="tl-code">{{<?= h($vName) ?>}}</code></td><td><?= h($vMeta['source']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
