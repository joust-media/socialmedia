<?php
/**
 * Videos module — placeholder stub.
 * Build the full table + upload flow here when the Videos feature lands.
 */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add a Video — Joust Admin</title>
<style>
  :root {
    --bg: #f0f2f5; --surface: #ffffff; --surface-2: #f7f8fa;
    --border: #dadde1; --text: #050505; --text-muted: #65676b;
    --accent: #1877f2; --accent-hover: #166fe5;
    --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    font-size: 15px; line-height: 1.4; min-height: 100vh;
  }
  .topbar {
    position: sticky; top: 0; z-index: 100;
    background: var(--surface); border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow);
  }
  .topbar-inner {
    max-width: 900px; margin: 0 auto; padding: 12px 20px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
  }
  .brand { display: flex; align-items: center; gap: 10px;
           font-weight: 700; font-size: 20px; color: var(--accent); letter-spacing: -0.5px; }
  .brand-mark {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--accent); color: #fff;
    display: flex; align-items: center; justify-content: center; font-weight: 800;
  }
  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; border-radius: 8px;
    font-size: 14px; font-weight: 600; cursor: pointer;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text);
    text-decoration: none;
  }
  .btn:hover { background: var(--border); }
  .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .btn.primary:hover { background: var(--accent-hover); }
  .wrap { max-width: 700px; margin: 0 auto; padding: 60px 20px; text-align: center; }
  .card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 16px; padding: 40px 28px;
    box-shadow: var(--shadow);
  }
  .icon { font-size: 64px; line-height: 1; margin-bottom: 12px; }
  h1 { margin: 0 0 10px; font-size: 24px; letter-spacing: -0.3px; }
  p  { color: var(--text-muted); margin: 0 0 24px; }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <div class="brand-mark">J</div>
      <span>Videos</span>
    </div>
    <a class="btn" href="admin.php">← Admin home</a>
  </div>
</header>

<div class="wrap">
  <div class="card">
    <div class="icon">🎬</div>
    <h1>Videos — coming soon</h1>
    <p>This module isn't built yet. When it's ready you'll be able to upload client videos here alongside Posts and Tires.</p>
    <a class="btn primary" href="admin.php">← Back to admin home</a>
  </div>
</div>

</body>
</html>
