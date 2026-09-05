<?php
/**
 * Landing page — square tile dashboard.
 * Shows Projects / Feed / [Feature] tiles with count badges.
 *
 * ?client=hmf scopes counts + tile labels to one company.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Count fetchers (scoped to client if provided)
$pendingPosts   = 0;
$openTasks      = 0;
$pendingLibrary = 0;
$totalLibrary   = 0;
$libTableReady  = hasLibraryImagesTable($pdo);

if ($client) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE company_id = ? AND status = 'pending'");
    $stmt->execute([$client['id']]);
    $pendingPosts = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE company_id = ? AND status <> 'done'");
    $stmt->execute([$client['id']]);
    $openTasks = (int)$stmt->fetchColumn();

    if ($libTableReady) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM library_images WHERE company_id = ?");
        $stmt->execute([$client['id']]);
        $totalLibrary = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM library_images WHERE company_id = ? AND status = 'pending'");
        $stmt->execute([$client['id']]);
        $pendingLibrary = (int)$stmt->fetchColumn();
    }
} else {
    $pendingPosts = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'pending'")->fetchColumn();
    $openTasks    = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status <> 'done'")->fetchColumn();
    if ($libTableReady) {
        $totalLibrary   = (int)$pdo->query("SELECT COUNT(*) FROM library_images")->fetchColumn();
        $pendingLibrary = (int)$pdo->query("SELECT COUNT(*) FROM library_images WHERE status = 'pending'")->fetchColumn();
    }
}

// Total post count for fallback tile text
$postCountSql  = "SELECT COUNT(*) FROM posts" . ($client ? " WHERE company_id = ?" : '');
$postCountStmt = $pdo->prepare($postCountSql);
$postCountStmt->execute($client ? [$client['id']] : []);
$totalPosts = (int)$postCountStmt->fetchColumn();

// Modules enabled on the scoped client (empty array if unscoped)
$clientModules = [];
if ($client) {
    $s = $pdo->prepare("
        SELECT m.id, m.slug, m.singular_label, m.plural_label, m.icon,
               (SELECT COUNT(*) FROM tires t
                WHERE t.company_id = ? AND t.module_id = m.id) AS item_count
        FROM company_modules cm
        INNER JOIN modules m ON m.id = cm.module_id
        WHERE cm.company_id = ?
        ORDER BY cm.sort_order, m.plural_label
    ");
    $s->execute([$client['id'], $client['id']]);
    $clientModules = $s->fetchAll();
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
<title><?= $client ? h($client['name']) . ' — Dashboard' : 'Dashboard' ?></title>
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
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
  }

  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 15px;
    line-height: 1.34;
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

  .wrap { max-width: 900px; margin: 0 auto; padding: 28px 20px 80px; }

  .welcome { margin-bottom: 24px; text-align: center; }
  .welcome h1 {
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.5px;
    margin: 0 0 6px;
    color: var(--text);
  }
  .welcome p {
    font-size: 15px;
    color: var(--text-muted);
    margin: 0;
  }

  .tile-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
  }
  @media (min-width: 720px) {
    .tile-grid { grid-template-columns: repeat(3, 1fr); }
  }

  .tile {
    aspect-ratio: 1 / 1;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow);
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    text-decoration: none;
    color: var(--text);
    position: relative;
    overflow: hidden;
    transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
  }
  .tile:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.5), 0 2px 6px rgba(0,0,0,0.3);
    border-color: var(--accent);
  }
  .tile:active { transform: translateY(0); }

  .tile-icon { font-size: 40px; line-height: 1; }
  .tile-title {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.3px;
    margin: 0 0 4px;
  }
  .tile-meta { font-size: 13px; color: var(--text-muted); }
  .tile-badge {
    position: absolute;
    top: 16px; right: 16px;
    min-width: 26px;
    height: 26px;
    padding: 0 8px;
    border-radius: 13px;
    background: var(--accent);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .tile-badge.zero {
    background: var(--surface-2);
    color: var(--text-muted);
    border: 1px solid var(--border);
  }

  @media (max-width: 480px) {
    .tile { padding: 16px; }
    .tile-title { font-size: 18px; }
    .tile-icon { font-size: 32px; }
    .welcome h1 { font-size: 22px; }
  }

  /* Recent activity card — placed above the tile grid */
  .activity-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow);
    margin-bottom: 24px;
    overflow: hidden;
  }
  .activity-card-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
  }
  .activity-card-title {
    font-size: 15px; font-weight: 700; margin: 0;
    letter-spacing: -0.2px;
  }
  .activity-card-meta {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.6px;
    color: var(--text-muted);
  }
  .activity-list {
    max-height: 320px; overflow-y: auto;
  }
  .activity-day {
    padding: 8px 18px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    color: var(--text-muted);
    background: var(--surface-2);
    border-top: 1px solid var(--border);
    position: sticky; top: 0;
  }
  .activity-day:first-child { border-top: none; }
  .activity-row {
    display: grid; grid-template-columns: auto 1fr auto;
    gap: 12px; padding: 12px 18px;
    border-top: 1px solid var(--border);
    align-items: start;
    text-decoration: none; color: var(--text);
    transition: background 0.15s;
  }
  .activity-row:hover { background: var(--surface-2); }
  .activity-actor {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.4px;
    padding: 3px 7px; border-radius: 10px;
    background: var(--surface-2); color: var(--text-muted);
    border: 1px solid var(--border);
    white-space: nowrap;
  }
  .activity-actor-client { background: #1e3a8a; color: #bfdbfe; border-color: #1e40af; }
  .activity-actor-admin  { background: #581c87; color: #e9d5ff; border-color: #6b21a8; }
  .activity-text { font-size: 13px; line-height: 1.45; min-width: 0; }
  .activity-company { font-weight: 700; }
  .activity-verb    { color: var(--text-muted); }
  .activity-target  { color: var(--accent); }
  .activity-detail {
    margin-top: 4px; font-size: 12px;
    color: var(--text-muted); font-style: italic;
    overflow: hidden;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
  }
  .activity-time {
    font-size: 11px; color: var(--text-muted); white-space: nowrap;
  }
  .activity-empty {
    padding: 20px 18px; text-align: center;
    color: var(--text-muted); font-size: 13px;
  }
  @media (max-width: 480px) {
    .activity-row { grid-template-columns: auto 1fr; }
    .activity-time { grid-column: 1 / -1; padding-left: 36px; }
  }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <?= renderBrand($client) ?>
    <nav class="client-nav"><?= renderClientNav($navItems, 'index') ?></nav>
  </div>
</header>

<div class="wrap">

  <div class="welcome">
    <?php if ($client): ?>
      <h1>Welcome, <?= h($client['name']) ?></h1>
      <p>Choose where you'd like to go.</p>
    <?php else: ?>
      <h1>Dashboard</h1>
      <p>Showing totals across all clients.</p>
    <?php endif; ?>
  </div>

  <?php if (hasActivityLog($pdo)): ?>
    <div class="activity-card">
      <div class="activity-card-header">
        <h2 class="activity-card-title">📡 Recent activity</h2>
        <span class="activity-card-meta">
          <?= $client ? h($client['name']) : 'All clients' ?>
        </span>
      </div>
      <div class="activity-list">
        <?= renderActivityFeed($pdo, $client ? (int)$client['id'] : null, 15) ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="tile-grid">

    <a class="tile" href="<?= h(clientUrl('projects.php')) ?>">
      <div class="tile-badge <?= $openTasks === 0 ? 'zero' : '' ?>"><?= $openTasks ?></div>
      <div class="tile-icon">📋</div>
      <div>
        <div class="tile-title">Projects</div>
        <div class="tile-meta">
          <?= $openTasks === 0 ? 'No open tasks' : ($openTasks . ' open ' . ($openTasks === 1 ? 'task' : 'tasks')) ?>
        </div>
      </div>
    </a>

    <a class="tile" href="<?= h(clientUrl('feed.php')) ?>">
      <div class="tile-badge <?= $pendingPosts === 0 ? 'zero' : '' ?>"><?= $pendingPosts ?></div>
      <div class="tile-icon">📰</div>
      <div>
        <div class="tile-title">Feed</div>
        <div class="tile-meta">
          <?= $pendingPosts === 0
              ? $totalPosts . ' ' . ($totalPosts === 1 ? 'post' : 'posts')
              : $pendingPosts . ' pending approval' ?>
        </div>
      </div>
    </a>

    <a class="tile" href="<?= h(clientUrl('library.php')) ?>">
      <div class="tile-badge <?= $pendingLibrary === 0 ? 'zero' : '' ?>"><?= $pendingLibrary ?></div>
      <div class="tile-icon">🖼️</div>
      <div>
        <div class="tile-title">Library</div>
        <div class="tile-meta">
          <?= $pendingLibrary === 0
              ? $totalLibrary . ' ' . ($totalLibrary === 1 ? 'image' : 'images')
              : $pendingLibrary . ' pending review' ?>
        </div>
      </div>
    </a>

    <?php foreach ($clientModules as $mod): ?>
      <a class="tile" href="<?= h(clientUrl('features.php', ['module' => $mod['slug']])) ?>">
        <div class="tile-badge <?= (int)$mod['item_count'] === 0 ? 'zero' : '' ?>">
          <?= (int)$mod['item_count'] ?>
        </div>
        <div class="tile-icon"><?= h($mod['icon']) ?></div>
        <div>
          <div class="tile-title"><?= h($mod['plural_label']) ?></div>
          <div class="tile-meta">
            <?= (int)$mod['item_count'] === 0
                  ? 'Nothing here yet'
                  : (int)$mod['item_count'] . ' ' . h(strtolower(
                      (int)$mod['item_count'] === 1 ? $mod['singular_label'] : $mod['plural_label']
                    )) ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>

  </div>

</div>

</body>
</html>
