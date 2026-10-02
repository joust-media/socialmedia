<?php
/**
 * Vehicle Library — admin list view.
 * Global (not client-scoped). Searchable by manufacturer / model / type,
 * filterable by manufacturer + type. Feeds the AI Builder's vehicle picker.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/prompt-lib.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$flash      = $_GET['msg'] ?? '';
$tableReady = hasVehiclesTable($pdo);

// ---- Filters -------------------------------------------------
$search       = trim($_GET['q'] ?? '');
$filterMake   = trim($_GET['make'] ?? '');
$filterType   = trim($_GET['type'] ?? '');

$vehicles     = [];
$imagesByVeh  = [];

if ($tableReady) {
    $where  = [];
    $params = [];
    if ($search !== '') {
        $where[]  = '(manufacturer LIKE ? OR model LIKE ? OR vehicle_type LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if ($filterMake !== '') { $where[] = 'manufacturer = ?'; $params[] = $filterMake; }
    if ($filterType !== '') { $where[] = 'vehicle_type = ?'; $params[] = $filterType; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("
        SELECT id, manufacturer, model, model_year, vehicle_type
        FROM vehicles
        $whereSql
        ORDER BY manufacturer ASC, model ASC, model_year DESC
    ");
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();

    // Images for the listed vehicles — one query, grouped.
    if ($vehicles) {
        $ids = array_column($vehicles, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $imgStmt = $pdo->prepare("
            SELECT vehicle_id, image_url FROM vehicle_images
            WHERE vehicle_id IN ($ph)
            ORDER BY vehicle_id, sort_order ASC, id ASC
        ");
        $imgStmt->execute($ids);
        foreach ($imgStmt->fetchAll() as $row) {
            $imagesByVeh[$row['vehicle_id']][] = $row['image_url'];
        }
    }
}

/** Build a library URL preserving the other filters. */
function vehUrl($make = null, $type = null, $q = null) {
    global $filterMake, $filterType, $search;
    $make = $make === null ? $filterMake : $make;
    $type = $type === null ? $filterType : $type;
    $q    = $q    === null ? $search     : $q;
    $qs = [];
    if ($make !== '') { $qs['make'] = $make; }
    if ($type !== '') { $qs['type'] = $type; }
    if ($q    !== '') { $qs['q']    = $q; }
    return pagePath('vehicles') . ($qs ? '?' . http_build_query($qs) : '');
}

/** "2024 Yamaha YXZ1000R" style label. */
function vehicleLabel($v) {
    $bits = [];
    if (!empty($v['model_year'])) { $bits[] = (int)$v['model_year']; }
    $bits[] = $v['manufacturer'];
    $bits[] = $v['model'];
    return implode(' ', $bits);
}

// ---- Chrome: the shared shell (large title, back to Manage → Tools, tab bar, Appearance) ----
$pageTitle   = 'Vehicle Library';
$htmlTitle   = 'Vehicle Library — Joust Media';
$navSubtitle = 'Manage · Tools';
$navBack     = ['href' => adminToolsUrl(), 'label' => 'Manage'];
$navTrailing = '';
// Page buttons under the large title (the nav row stays: back · title · + New · Appearance — nothing overflows at 320px)
$navLinks    = [['label' => 'New vehicle', 'href' => pagePath('add-vehicle'), 'tinted' => true, 'attrs' => ['data-new-vehicle' => '1']],
                ['label' => 'Prompt Library', 'href' => pagePath('prompts')]];
$activeTab   = 'manage';
$bodyClass   = 'page-studio page-tool page-vehicles';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/tools.css')) . '">';
$filtered    = $search !== '' || $filterMake !== '' || $filterType !== '';
include __DIR__ . '/partials/layout-top.php';
?>

<p class="tl-intro">Shared vehicle catalog. Pick a vehicle in the AI Builder to pull in its images and details.</p>

<?php if ($flash): ?>
  <div class="studio-alert studio-alert--ok" role="status"><?= h($flash) ?></div>
<?php endif; ?>

<?php if (!$tableReady): ?>
  <div class="studio-alert studio-alert--error" role="alert">
    The <code>vehicles</code> table doesn't exist yet. Run <a href="<?= h(pagePath('migrate')) ?>">migrate</a> to create it, then come back.
  </div>
<?php else: ?>

  <form class="tl-search" method="GET" action="<?= h(pagePath('vehicles')) ?>" role="search" data-tool-search>
    <?php if ($filterMake !== ''): ?><input type="hidden" name="make" value="<?= h($filterMake) ?>"><?php endif; ?>
    <?php if ($filterType !== ''): ?><input type="hidden" name="type" value="<?= h($filterType) ?>"><?php endif; ?>
    <label class="ui-visually-hidden" for="vehicleSearch">Search vehicles</label>
    <input class="ui-input" type="search" id="vehicleSearch" name="q" value="<?= h($search) ?>" placeholder="Make, model or type">
    <button type="submit" class="ui-btn ui-btn--gray">Search</button>
    <?php if ($search !== ''): ?>
      <a class="ui-btn ui-btn--plain" href="<?= h(vehUrl(null, null, '')) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <?php if ($filterMake !== '' || $filterType !== ''): ?>
    <div class="tl-active">
      <?php if ($filterMake !== ''): ?>
        <a class="studio-chip is-active" href="<?= h(vehUrl('')) ?>" title="Remove this filter">Make: <?= h($filterMake) ?> <?= icon('xmark') ?></a>
      <?php endif; ?>
      <?php if ($filterType !== ''): ?>
        <a class="studio-chip is-active" href="<?= h(vehUrl(null, '')) ?>" title="Remove this filter">Type: <?= h($filterType) ?> <?= icon('xmark') ?></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?= insetListOpen(count($vehicles) . ' ' . (count($vehicles) === 1 ? 'vehicle' : 'vehicles') . ($filtered ? ' · filtered' : ''), ['attrs' => ['data-vehicle-list' => '1']]) ?>
    <?php if (!$vehicles): ?>
      <li><div class="ui-row"><div class="ui-row-body"><div class="ui-row-subtitle" style="white-space:normal">
        <?= $filtered ? 'No vehicles match the current filters.' : 'No vehicles yet. Use “New vehicle” to add the first one.' ?>
      </div></div></div></li>
    <?php endif; ?>
    <?php foreach ($vehicles as $v):
      $imgs  = $imagesByVeh[$v['id']] ?? [];
      $first = $imgs[0] ?? null;
    ?>
      <li><div class="ui-row ui-row--leading tl-row tl-row--leading" data-vehicle="<?= (int)$v['id'] ?>">
        <div class="ui-row-leading"><?= $first ? pvImg(tireImageSrc((string)$first), 'sm', ['sizes' => '56px', 'alt' => '']) : icon('photo') ?></div>
        <div class="ui-row-body">
          <div class="ui-row-title ui-row-title--wrap"><?= h(vehicleLabel($v)) ?></div>
          <div class="tl-tags">
            <a class="tl-tag" href="<?= h(vehUrl($v['manufacturer'])) ?>"><?= h($v['manufacturer']) ?></a>
            <?php if (!empty($v['vehicle_type'])): ?>
              <a class="tl-tag" href="<?= h(vehUrl(null, $v['vehicle_type'])) ?>"><?= h($v['vehicle_type']) ?></a>
            <?php endif; ?>
            <span class="tl-tag"><?= count($imgs) ?> image<?= count($imgs) === 1 ? '' : 's' ?></span>
          </div>
        </div>
        <div class="ui-row-trailing">
          <a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h(pagePath('add-vehicle') . '?edit=' . (int)$v['id']) ?>">Edit</a>
          <form method="POST" action="<?= h(pagePath('add-vehicle')) ?>" onsubmit="return confirm('Delete this vehicle and all its images permanently?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn">Delete</button>
          </form>
        </div>
      </div></li>
    <?php endforeach; ?>
  <?= insetListClose() ?>

<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
