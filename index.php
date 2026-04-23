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
$pendingPosts = 0;
$openTasks    = 0;
$featureCount = 0;

if ($client) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE company_id = ? AND status = 'pending'");
    $stmt->execute([$client['id']]);
    $pendingPosts = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE company_id = ? AND status <> 'done'");
    $stmt->execute([$client['id']]);
    $openTasks = (int)$stmt->fetchColumn();

    // For now, feature count = total tire images across all tires (placeholder
    // until per-client feature tables exist)
    $featureCount = (int)$pdo->query("SELECT COUNT(*) FROM tire_images")->fetchColumn();
} else {
    $pendingPosts = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'pending'")->fetchColumn();
    $openTasks    = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status <> 'done'")->fetchColumn();
    $featureCount = (int)$pdo->query("SELECT COUNT(*) FROM tire_images")->fetchColumn();
}

$featureLabel = $client ? $client['feature_label'] : 'Tires';

// Total post count for fallback tile text
$postCountSql  = "SELECT COUNT(*) FROM posts" . ($client ? " WHERE company_id = ?" : '');
$postCountStmt = $pdo->prepare($postCountSql);
$postCountStmt->execute($client ? [$client['id']] : []);
$totalPosts = (int)$postCountStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $client ? h($client['name']) . ' — Dashboard' : 'Dashboard' ?></title>
<style>
  :root {
    --bg: #f0f2f5;
    --surface: #ffffff;
    --surface-2: #f7f8fa;
    --border: #dadde1;
    --text: #050505;
    --text-muted: #65676b;
    --accent: #1877f2;
    --accent-hover: #166fe5;
    --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04);
  }
  [data-theme="dark"] {
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
    max-width: 900px; margin: 0 auto;
    padding: 12px 20px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
  }
  .brand {
    display: flex; align-items: center; gap: 10px;
    font-weight: 700; font-size: 20px;
    color: var(--accent); letter-spacing: -0.5px;
  }
  .brand-mark {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--accent); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
  }
  .theme-toggle {
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 14px; border-radius: 20px;
    cursor: pointer; font-size: 14px; font-weight: 600;
    display: flex; align-items: center; gap: 6px;
    transition: background 0.15s;
  }
  .theme-toggle:hover { background: var(--border); }

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
    box-shadow: 0 4px 12px rgba(0,0,0,0.12), 0 2px 6px rgba(0,0,0,0.08);
    border-color: var(--accent);
  }
  [data-theme="dark"] .tile:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.5), 0 2px 6px rgba(0,0,0,0.3);
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
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <div class="brand-mark">J</div>
      <span><?= $client ? h($client['name']) : 'Joust Media' ?></span>
    </div>
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
      <span id="themeIcon">🌙</span>
      <span id="themeLabel">Dark</span>
    </button>
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

    <a class="tile" href="<?= h(clientUrl('tires.php')) ?>">
      <div class="tile-badge <?= $featureCount === 0 ? 'zero' : '' ?>"><?= $featureCount ?></div>
      <div class="tile-icon">🛞</div>
      <div>
        <div class="tile-title"><?= h($featureLabel) ?></div>
        <div class="tile-meta">
          <?= $featureCount === 0 ? 'Nothing here yet' : ($featureCount . ' items') ?>
        </div>
      </div>
    </a>

  </div>

</div>

<script>
  const themeBtn = document.getElementById('themeToggle');
  const themeIcon = document.getElementById('themeIcon');
  const themeLabel = document.getElementById('themeLabel');
  let isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  function applyTheme() {
    document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
    themeIcon.textContent = isDark ? '☀️' : '🌙';
    themeLabel.textContent = isDark ? 'Light' : 'Dark';
  }
  applyTheme();
  themeBtn.addEventListener('click', () => { isDark = !isDark; applyTheme(); });
</script>

</body>
</html>
