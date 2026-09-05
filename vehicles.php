<?php
/**
 * Vehicle Library — admin list view.
 * Global (not client-scoped). Searchable by manufacturer / model / type,
 * filterable by manufacturer + type. Feeds the AI Builder's vehicle picker.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/prompt-lib.php';
require __DIR__ . '/auth.php';
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
    return 'vehicles' . ($qs ? '?' . http_build_query($qs) : '');
}

/** "2024 Yamaha YXZ1000R" style label. */
function vehicleLabel($v) {
    $bits = [];
    if (!empty($v['model_year'])) { $bits[] = (int)$v['model_year']; }
    $bits[] = $v['manufacturer'];
    $bits[] = $v['model'];
    return implode(' ', $bits);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vehicle Library — Joust Admin</title>
<style>
  :root {
    --bg: #f0f2f5; --surface: #ffffff; --surface-2: #f7f8fa;
    --border: #dadde1; --text: #050505; --text-muted: #65676b;
    --accent: #1877f2; --accent-hover: #166fe5;
    --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04);
  }
  [data-theme="dark"] {
    --bg: #18191a; --surface: #242526; --surface-2: #3a3b3c;
    --border: #3e4042; --text: #e4e6eb; --text-muted: #b0b3b8;
    --accent: #2d88ff; --accent-hover: #4599ff;
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
                 display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .card-title { font-size: 16px; font-weight: 700; margin: 0; }

  .filters { background: var(--surface); border: 1px solid var(--border);
             border-radius: 12px; box-shadow: var(--shadow);
             padding: 14px 16px; margin-bottom: 18px;
             display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .search-box { flex: 1; min-width: 220px; display: flex; gap: 8px; }
  .search-box input { flex: 1; background: var(--surface-2); border: 1px solid var(--border);
                      color: var(--text); padding: 9px 12px; border-radius: 8px; font: inherit; }
  .search-box input:focus { outline: none; border-color: var(--accent);
                            box-shadow: 0 0 0 3px rgba(24,119,242,0.15); }
  .active-filter { font-size: 13px; color: var(--text-muted); }
  .active-filter a { color: var(--accent); text-decoration: none; font-weight: 600; }

  .veh-row { display: grid; grid-template-columns: 96px 1fr auto;
             gap: 14px; padding: 14px 18px; border-top: 1px solid var(--border);
             align-items: center; }
  .veh-row:first-child { border-top: none; }
  .veh-thumb { width: 96px; height: 72px; border-radius: 8px; object-fit: cover;
               background: var(--surface-2); border: 1px solid var(--border); display: block; }
  .veh-thumb-empty { width: 96px; height: 72px; border-radius: 8px;
                     background: var(--surface-2); border: 1px dashed var(--border);
                     display: flex; align-items: center; justify-content: center;
                     color: var(--text-muted); font-size: 22px; }
  .veh-name { font-size: 16px; font-weight: 700; margin-bottom: 5px; }
  .veh-meta { display: flex; flex-wrap: wrap; gap: 6px; }
  .meta-chip { font-size: 11px; padding: 3px 9px; border-radius: 10px;
               background: var(--surface-2); border: 1px solid var(--border);
               color: var(--text-muted); text-decoration: none; }
  .meta-chip:hover { border-color: var(--accent); color: var(--accent); }
  .meta-chip.static { cursor: default; }
  .meta-chip.static:hover { border-color: var(--border); color: var(--text-muted); }
  .row-actions { display: flex; gap: 6px; }
  .empty { padding: 40px 20px; text-align: center; color: var(--text-muted);
           background: var(--surface); border: 1px dashed var(--border); border-radius: 12px; }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand"><div class="brand-mark">J</div><span>Vehicle Library</span></div>
    <div class="top-actions">
      <a class="btn sm" href="admin">← Admin</a>
      <a class="btn sm" href="prompts">🎨 Prompts</a>
      <a class="btn sm primary" href="add-vehicle">+ New vehicle</a>
      <a class="btn sm" href="logout" title="Signed in as <?= h(currentAdmin()) ?>">Sign out</a>
    </div>
  </div>
</header>

<div class="wrap">

  <h1>Vehicle Library</h1>
  <p class="subtitle">Shared vehicle catalog. Pick a vehicle in the AI Builder to pull in its images and details.</p>

  <?php if ($flash): ?>
    <div class="flash">✓ <?= h($flash) ?></div>
  <?php endif; ?>

  <?php if (!$tableReady): ?>
    <div class="errors">
      ⚠ The <code>vehicles</code> table doesn't exist yet. Visit
      <a href="migrate" style="color:inherit;text-decoration:underline;">migrate</a>
      to create it, then come back.
    </div>
  <?php else: ?>

    <div class="filters">
      <form class="search-box" method="GET" action="vehicles">
        <?php if ($filterMake !== ''): ?><input type="hidden" name="make" value="<?= h($filterMake) ?>"><?php endif; ?>
        <?php if ($filterType !== ''): ?><input type="hidden" name="type" value="<?= h($filterType) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search manufacturer, model or type…">
        <button type="submit" class="btn sm">Search</button>
        <?php if ($search !== ''): ?>
          <a class="btn sm" href="<?= h(vehUrl(null, null, '')) ?>">Clear</a>
        <?php endif; ?>
      </form>
      <?php if ($filterMake !== ''): ?>
        <span class="active-filter">Make: <strong><?= h($filterMake) ?></strong> ·
          <a href="<?= h(vehUrl('')) ?>">remove</a></span>
      <?php endif; ?>
      <?php if ($filterType !== ''): ?>
        <span class="active-filter">Type: <strong><?= h($filterType) ?></strong> ·
          <a href="<?= h(vehUrl(null, '')) ?>">remove</a></span>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <h2 class="card-title">
          <?= count($vehicles) ?> <?= count($vehicles) === 1 ? 'vehicle' : 'vehicles' ?>
          <?php if ($search !== '' || $filterMake !== '' || $filterType !== ''): ?>
            <span style="font-weight:500;color:var(--text-muted);">(filtered)</span>
          <?php endif; ?>
        </h2>
      </div>
      <?php if (empty($vehicles)): ?>
        <div style="padding:18px;">
          <div class="empty">
            <?php if ($search !== '' || $filterMake !== '' || $filterType !== ''): ?>
              No vehicles match the current filters.
            <?php else: ?>
              No vehicles yet. Click <strong>+ New vehicle</strong> to add the first one.
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <?php foreach ($vehicles as $v):
          $imgs  = $imagesByVeh[$v['id']] ?? [];
          $first = $imgs[0] ?? null;
        ?>
          <div class="veh-row">
            <div>
              <?php if ($first): ?>
                <img class="veh-thumb" src="<?= h($first) ?>" alt="" loading="lazy">
              <?php else: ?>
                <div class="veh-thumb-empty">🚗</div>
              <?php endif; ?>
            </div>
            <div>
              <div class="veh-name"><?= h(vehicleLabel($v)) ?></div>
              <div class="veh-meta">
                <a class="meta-chip" href="<?= h(vehUrl($v['manufacturer'])) ?>"><?= h($v['manufacturer']) ?></a>
                <?php if (!empty($v['vehicle_type'])): ?>
                  <a class="meta-chip" href="<?= h(vehUrl(null, $v['vehicle_type'])) ?>"><?= h($v['vehicle_type']) ?></a>
                <?php endif; ?>
                <span class="meta-chip static">
                  <?= count($imgs) ?> image<?= count($imgs) === 1 ? '' : 's' ?>
                </span>
              </div>
            </div>
            <div class="row-actions">
              <a class="btn sm" href="add-vehicle?edit=<?= (int)$v['id'] ?>">Edit</a>
              <form method="POST" action="add-vehicle"
                    onsubmit="return confirm('Delete this vehicle and all its images permanently?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <button type="submit" class="btn sm" title="Delete">🗑</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  <?php endif; ?>

</div>

</body>
</html>
