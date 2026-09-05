<?php
/**
 * Image Library — per-brand approval gallery.
 *
 * Reads image files straight off disk from media/library/{slug}/ (a folder
 * you drop files into by hand — Drive, FTP, whatever) and lets the client
 * approve or deny each one. Decisions persist in library_images even if the
 * file is later removed from the folder.
 *
 * ?client=hmf scopes to one brand's gallery. Unscoped shows a picker of
 * every brand with counts.
 */

// Moved to legacy/ by the Assets phase (assets.php replaces this page; the
// root library.php is now a 301 shim). If this file is ever served directly
// from /legacy/, normalise SCRIPT_NAME so basePath()/clientUrl()/staticUrl()
// and the tab-bar active detection still resolve against the app root.
if (!empty($_SERVER['SCRIPT_NAME']) && preg_match('#/legacy/[^/]+$#', $_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = preg_replace('#/legacy/([^/]+)$#', '/$1', $_SERVER['SCRIPT_NAME']);
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

// Admin-only since the redesign: this page shows denied items and has no role
// checks. A client seat is sent to the new Assets page (301), scope preserved.
if (!isAdmin()) {
    header('Location: ' . clientUrl('assets.php', ['view' => 'library']), true, 301);
    exit;
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$libTableReady = hasLibraryImagesTable($pdo);

$images = [];
$counts = ['pending' => 0, 'approved' => 0, 'denied' => 0];
if ($client && $libTableReady) {
    // This legacy page only knows <img> + an image lightbox: leave videos to assets.php.
    $images = array_values(array_filter(syncLibraryImages($pdo, (int)$client['id'], $client['slug']),
        fn($img) => !isVideoExt(pathinfo((string)$img['filename'], PATHINFO_EXTENSION))));
    foreach ($images as $img) {
        if (isset($counts[$img['status']])) { $counts[$img['status']]++; }
    }
}
$total = count($images);

// Unscoped view: every brand, with counts pulled from the DB only (no disk
// scan — that happens when you actually open a brand's gallery).
$brandCards = [];
if (!$client) {
    $companies = $pdo->query("SELECT id, name, slug, logo_url FROM companies ORDER BY name")->fetchAll();
    foreach ($companies as $co) {
        $c = ['pending' => 0, 'approved' => 0, 'denied' => 0];
        if ($libTableReady) {
            $cs = $pdo->prepare("SELECT status, COUNT(*) AS n FROM library_images WHERE company_id = ? GROUP BY status");
            $cs->execute([$co['id']]);
            foreach ($cs->fetchAll() as $r) {
                if (isset($c[$r['status']])) { $c[$r['status']] = (int)$r['n']; }
            }
        }
        $brandCards[] = ['company' => $co, 'counts' => $c, 'total' => array_sum($c)];
    }
}

$navItems = clientNavItems($pdo, $client);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $client ? h($client['name']) . ' — Library' : 'Library' ?></title>
<?= renderAppHead() ?>
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
    --danger: #ef4444;
    --success: #16a34a;
    --warn: #f59e0b;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
    --toast-bg: #e4e6eb;
    --toast-text: #050505;
  }

  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 15px;
    line-height: 1.4;
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
    max-width: 1200px; margin: 0 auto;
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
    object-fit: contain; background: #fff; padding: 4px;
    border: 1px solid var(--border);
  }
  .brand-name { white-space: nowrap; max-width: 240px; overflow: hidden; text-overflow: ellipsis; }

  .client-nav {
    display: flex; align-items: center; gap: 4px;
    flex: 1; min-width: 0; overflow-x: auto; scrollbar-width: none;
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
  .nav-link.active { background: var(--surface-2); color: var(--text); border-color: var(--border); }
  .nav-link-icon { font-size: 14px; line-height: 1; }
  @media (max-width: 600px) {
    .nav-link-label { display: none; }
    .nav-link { padding: 7px 10px; }
    .brand-name { max-width: 120px; font-size: 15px; }
  }

  .wrap { max-width: 1200px; margin: 0 auto; padding: 24px 20px 80px; }

  h1.page-title {
    font-size: 22px; font-weight: 700; letter-spacing: -0.3px;
    margin: 0 0 4px;
  }
  .page-sub { color: var(--text-muted); font-size: 14px; margin: 0 0 20px; }

  /* Summary + filter pills */
  .lib-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px;
    margin-bottom: 18px;
  }
  .lib-summary { color: var(--text-muted); font-size: 14px; }
  .lib-summary strong { color: var(--text); font-weight: 700; }

  .filter-pills { display: flex; gap: 8px; flex-wrap: wrap; }
  .status-pill {
    display: inline-flex; align-items: center;
    padding: 6px 14px; border-radius: 20px;
    font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    cursor: pointer; border: 1px solid transparent;
    background: var(--surface-2); color: var(--text-muted);
    transition: opacity 0.15s, box-shadow 0.15s;
    user-select: none; opacity: 0.6;
  }
  .status-pill:hover { opacity: 0.9; }
  .status-pill.active { opacity: 1; box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
  .status-pill.pending  { background: #78350f; color: #fde68a; border-color: #a16207; }
  .status-pill.approved { background: #14532d; color: #bbf7d0; border-color: #166534; }
  .status-pill.denied   { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }
  .status-pill.all      { background: var(--surface-2); color: var(--text); border-color: var(--border); }
  .status-pill-count {
    display: inline-flex; align-items: center; justify-content: center;
    margin-left: 6px; padding: 0 7px; min-width: 20px; height: 18px;
    border-radius: 9px; background: rgba(255,255,255,0.15);
    font-size: 11px; font-weight: 800;
  }

  /* Grid */
  .lib-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 3px;
  }
  .lib-item {
    position: relative;
    aspect-ratio: 1 / 1;
    background: var(--surface-2);
    overflow: hidden;
    border-radius: 2px;
  }
  .lib-item.hidden-by-filter { display: none; }
  .lib-item img {
    width: 100%; height: 100%; object-fit: cover;
    display: block; cursor: zoom-in;
    transition: opacity 0.25s, filter 0.25s, transform 0.2s;
  }
  .lib-item:hover img { transform: scale(1.03); }

  /* Denied = faded, same treatment as a "posted" post on the feed */
  .lib-item[data-status="denied"] img { opacity: 0.5; filter: grayscale(0.6); }
  .lib-item[data-status="denied"]:hover img { opacity: 0.85; filter: grayscale(0.2); }

  .lib-status-dot {
    position: absolute; top: 8px; right: 8px;
    width: 10px; height: 10px; border-radius: 50%;
    background: var(--text-muted);
    box-shadow: 0 0 0 2px rgba(0,0,0,0.5);
    pointer-events: none; z-index: 2;
  }
  .lib-status-dot.pending  { background: #f59e0b; }
  .lib-status-dot.approved { background: #22c55e; }
  .lib-status-dot.denied   { background: #ef4444; }

  .lib-actions {
    position: absolute; inset: auto 0 0 0;
    display: flex; gap: 3px; padding: 6px;
    opacity: 0; transform: translateY(6px);
    transition: opacity 0.15s, transform 0.15s;
    background: linear-gradient(to top, rgba(0,0,0,0.65), transparent);
    z-index: 3;
  }
  .lib-item:hover .lib-actions,
  .lib-actions:focus-within { opacity: 1; transform: translateY(0); }
  @media (hover: none) { .lib-actions { opacity: 1; transform: none; } }

  .lib-btn {
    flex: 1;
    border: none; border-radius: 6px;
    background: rgba(255,255,255,0.15); color: #fff;
    font-size: 13px; font-weight: 700;
    padding: 6px 0; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
    transition: background 0.15s, transform 0.1s;
  }
  .lib-btn:hover { background: rgba(255,255,255,0.28); }
  .lib-btn:active { transform: scale(0.95); }
  .lib-btn:disabled { opacity: 0.5; cursor: wait; }
  .lib-btn.approve.active { background: #16a34a; }
  .lib-btn.deny.active    { background: #dc2626; }

  .empty {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 48px 24px;
    text-align: center;
    color: var(--text-muted);
  }
  .empty code {
    background: var(--surface-2);
    padding: 3px 8px;
    border-radius: 5px;
    color: var(--text);
    font-size: 13px;
  }

  /* Unscoped brand picker */
  .brand-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 14px;
  }
  .brand-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; box-shadow: var(--shadow);
    padding: 16px; text-decoration: none; color: var(--text);
    display: flex; align-items: center; gap: 12px;
    transition: border-color 0.15s, transform 0.15s;
  }
  .brand-card:hover { border-color: var(--accent); transform: translateY(-1px); }
  .brand-card-logo {
    width: 44px; height: 44px; border-radius: 8px;
    object-fit: contain; background: #fff; padding: 4px;
    border: 1px solid var(--border); flex-shrink: 0;
  }
  .brand-card-mark {
    width: 44px; height: 44px; border-radius: 8px;
    background: var(--accent); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; flex-shrink: 0;
  }
  .brand-card-meta { min-width: 0; }
  .brand-card-name { font-weight: 700; font-size: 15px; margin-bottom: 4px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .brand-card-counts { font-size: 12px; color: var(--text-muted); }
  .brand-card-counts .pending { color: #fde68a; }

  .toast {
    position: fixed; bottom: 24px; left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: var(--toast-bg); color: var(--toast-text);
    padding: 12px 20px; border-radius: 24px;
    font-size: 14px; font-weight: 600;
    opacity: 0; pointer-events: none;
    transition: opacity 0.25s, transform 0.25s;
    z-index: 1000; box-shadow: 0 4px 16px rgba(0,0,0,0.25);
  }
  .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

  /* Lightbox */
  .lightbox {
    position: fixed; inset: 0; z-index: 2000;
    background: rgba(0,0,0,0.96);
    display: none; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 20px;
    animation: lb-fade 0.18s ease-out;
  }
  .lightbox.show { display: flex; }
  .lightbox-stage { flex: 1; display: flex; align-items: center; justify-content: center; min-height: 0; width: 100%; }
  .lightbox img {
    max-width: 100%; max-height: 100%;
    object-fit: contain;
    box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    border-radius: 4px;
  }
  .lightbox-close {
    position: absolute; top: 16px; right: 20px;
    background: rgba(255,255,255,0.15); color: #fff;
    border: none; width: 40px; height: 40px; border-radius: 50%;
    font-size: 22px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    z-index: 2001;
  }
  .lightbox-close:hover { background: rgba(255,255,255,0.3); }
  .lightbox-panel {
    flex-shrink: 0; width: 100%; max-width: 480px;
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    padding-top: 14px;
  }
  .lightbox-filename { color: var(--text-muted); font-size: 13px; word-break: break-all; text-align: center; }
  .lightbox-decision { display: flex; gap: 8px; width: 100%; }
  .decision-btn {
    flex: 1; padding: 10px 14px; border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.1); color: #fff;
    font-size: 14px; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: background 0.15s, transform 0.1s;
  }
  .decision-btn:hover { background: rgba(255,255,255,0.2); }
  .decision-btn:active { transform: scale(0.98); }
  .decision-btn:disabled { opacity: 0.6; cursor: wait; }
  .decision-btn.approve.active { background: #16a34a; border-color: #16a34a; }
  .decision-btn.deny.active { background: #dc2626; border-color: #dc2626; }
  .decision-btn.reset { flex: 0 0 auto; padding: 10px 14px; display: none; }
  .lightbox[data-status="approved"] .decision-btn.reset,
  .lightbox[data-status="denied"]   .decision-btn.reset { display: flex; }
  @keyframes lb-fade { from { opacity: 0; } to { opacity: 1; } }

  @media (max-width: 480px) {
    .lib-grid { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); }
  }
</style>
</head>
<body>

<?= renderClientNav($navItems, 'library') ?>

<div class="wrap">

<?php if (!$client): ?>

  <h1 class="page-title">Library</h1>
  <p class="page-sub">Choose a brand to review its image library.</p>

  <?php if (!$brandCards): ?>
    <div class="empty">No brands yet.</div>
  <?php else: ?>
    <div class="brand-grid">
      <?php foreach ($brandCards as $bc): $co = $bc['company']; $c = $bc['counts']; ?>
        <a class="brand-card" href="<?= h(clientUrl('library.php', ['client' => $co['slug']])) ?>">
          <?php if (!empty($co['logo_url'])): ?>
            <img class="brand-card-logo" src="<?= h($co['logo_url']) ?>" alt="">
          <?php else: ?>
            <div class="brand-card-mark"><?= h(mb_strtoupper(mb_substr($co['name'], 0, 1))) ?></div>
          <?php endif; ?>
          <div class="brand-card-meta">
            <div class="brand-card-name"><?= h($co['name']) ?></div>
            <div class="brand-card-counts">
              <?php if ($bc['total'] === 0): ?>
                No images yet
              <?php else: ?>
                <?= (int)$bc['total'] ?> image<?= $bc['total'] === 1 ? '' : 's' ?>
                <?php if ($c['pending'] > 0): ?>
                  · <span class="pending"><?= (int)$c['pending'] ?> pending</span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>

  <h1 class="page-title">Library</h1>
  <p class="page-sub"><?= h($client['name']) ?> — approve or deny images from the shared folder.</p>

  <?php if (!$libTableReady): ?>
    <div class="empty">Library isn't set up yet — run <code>migrate.php</code> once, then reload this page.</div>
  <?php elseif ($total === 0): ?>
    <div class="empty">
      No images yet.<br><br>
      Drop image files into <code>media/library/<?= h($client['slug']) ?>/</code> on the server, then refresh this page.
    </div>
  <?php else: ?>

    <div class="lib-toolbar">
      <div class="lib-summary">
        <strong><?= $total ?></strong> image<?= $total === 1 ? '' : 's' ?>
      </div>
      <div class="filter-pills">
        <span class="status-pill all active" data-filter="all">All
          <span class="status-pill-count"><?= $total ?></span>
        </span>
        <span class="status-pill pending" data-filter="pending">Pending
          <span class="status-pill-count" data-count="pending"><?= $counts['pending'] ?></span>
        </span>
        <span class="status-pill approved" data-filter="approved">Approved
          <span class="status-pill-count" data-count="approved"><?= $counts['approved'] ?></span>
        </span>
        <span class="status-pill denied" data-filter="denied">Denied
          <span class="status-pill-count" data-count="denied"><?= $counts['denied'] ?></span>
        </span>
      </div>
    </div>

    <div class="lib-grid" id="libGrid">
      <?php foreach ($images as $img): ?>
        <?php $url = libraryFileUrl($client['slug'], $img['filename']); ?>
        <div class="lib-item" id="lib-<?= (int)$img['id'] ?>"
             data-id="<?= (int)$img['id'] ?>" data-status="<?= h($img['status']) ?>">
          <span class="lib-status-dot <?= h($img['status']) ?>" data-status-dot></span>
          <img src="<?= h($url) ?>" alt="<?= h($img['filename']) ?>" loading="lazy" data-lib-open>
          <div class="lib-actions">
            <button class="lib-btn approve <?= $img['status'] === 'approved' ? 'active' : '' ?>"
                    type="button" data-lib-decide="approved" title="Approve">✓</button>
            <button class="lib-btn deny <?= $img['status'] === 'denied' ? 'active' : '' ?>"
                    type="button" data-lib-decide="denied" title="Deny">✕</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

<?php endif; ?>

</div>

<div class="toast" id="toast">Saved</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Image viewer">
  <button class="lightbox-close" type="button" aria-label="Close">×</button>
  <div class="lightbox-stage"><img id="lightboxImg" alt=""></div>
  <div class="lightbox-panel">
    <div class="lightbox-filename" id="lightboxFilename"></div>
    <div class="lightbox-decision">
      <button class="decision-btn approve" type="button" data-decide="approved">✓ Approve</button>
      <button class="decision-btn deny" type="button" data-decide="denied">✕ Deny</button>
      <button class="decision-btn reset" type="button" data-decide="pending" title="Reset to pending">↺ Reset</button>
    </div>
  </div>
</div>

<script>
(function() {
  const toastEl = document.getElementById('toast');
  let toastTimer;
  function showToast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1800);
  }

  async function saveDecision(id, status) {
    const fd = new FormData();
    fd.append('id', id);
    fd.append('status', status);
    fd.append('actor', 'client');
    const res = await fetch(<?= json_encode(basePath() . '/library-status.php') ?>, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed');
    return data;
  }

  function updateCounts() {
    const counts = { pending: 0, approved: 0, denied: 0 };
    document.querySelectorAll('.lib-item').forEach(el => {
      const s = el.getAttribute('data-status');
      if (counts[s] !== undefined) counts[s]++;
    });
    Object.keys(counts).forEach(k => {
      const el = document.querySelector('[data-count="' + k + '"]');
      if (el) el.textContent = counts[k];
    });
  }

  function applyStatus(tile, status) {
    tile.setAttribute('data-status', status);
    const dot = tile.querySelector('[data-status-dot]');
    if (dot) dot.className = 'lib-status-dot ' + status;
    const approveBtn = tile.querySelector('[data-lib-decide="approved"]');
    const denyBtn = tile.querySelector('[data-lib-decide="denied"]');
    if (approveBtn) approveBtn.classList.toggle('active', status === 'approved');
    if (denyBtn) denyBtn.classList.toggle('active', status === 'denied');
    updateCounts();
    applyFilter(currentFilter);
  }

  const grid = document.getElementById('libGrid');
  if (grid) {
    grid.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-lib-decide]');
      if (!btn) return;
      e.preventDefault();
      const tile = btn.closest('.lib-item');
      const id = tile.getAttribute('data-id');
      const requested = btn.getAttribute('data-lib-decide');
      const current = tile.getAttribute('data-status');
      const next = current === requested ? 'pending' : requested;

      const allBtns = tile.querySelectorAll('.lib-btn');
      allBtns.forEach(b => b.disabled = true);
      try {
        await saveDecision(id, next);
        applyStatus(tile, next);
        if (lb.classList.contains('show') && lb.getAttribute('data-id') === id) {
          syncLightbox(next);
        }
        showToast(next === 'approved' ? '✓ Approved' : next === 'denied' ? '✕ Denied' : '↺ Reset to pending');
      } catch (err) {
        showToast('Update failed — try again');
      } finally {
        allBtns.forEach(b => b.disabled = false);
      }
    });
  }

  // Filter pills
  let currentFilter = 'all';
  function applyFilter(filter) {
    currentFilter = filter;
    document.querySelectorAll('.lib-item').forEach(tile => {
      const show = filter === 'all' || tile.getAttribute('data-status') === filter;
      tile.classList.toggle('hidden-by-filter', !show);
    });
    document.querySelectorAll('.status-pill[data-filter]').forEach(p => {
      p.classList.toggle('active', p.getAttribute('data-filter') === filter);
    });
  }
  document.querySelectorAll('.status-pill[data-filter]').forEach(p => {
    p.addEventListener('click', () => applyFilter(p.getAttribute('data-filter')));
  });

  // Lightbox
  const lb = document.getElementById('lightbox');
  const lbImg = document.getElementById('lightboxImg');
  const lbFilename = document.getElementById('lightboxFilename');

  function syncLightbox(status) {
    lb.setAttribute('data-status', status);
    lb.querySelectorAll('.decision-btn').forEach(b => {
      b.classList.toggle('active', b.getAttribute('data-decide') === status);
    });
  }

  function openLightbox(tile) {
    const img = tile.querySelector('img');
    lb.setAttribute('data-id', tile.getAttribute('data-id'));
    lbImg.src = img.getAttribute('src');
    lbImg.alt = img.getAttribute('alt') || '';
    lbFilename.textContent = img.getAttribute('alt') || '';
    syncLightbox(tile.getAttribute('data-status'));
    lb.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeLightbox() {
    lb.classList.remove('show');
    lbImg.src = '';
    document.body.style.overflow = '';
  }

  if (grid) {
    grid.addEventListener('click', (e) => {
      const img = e.target.closest('[data-lib-open]');
      if (!img) return;
      openLightbox(img.closest('.lib-item'));
    });
  }

  lb.addEventListener('click', (e) => {
    if (e.target === lb || e.target.classList.contains('lightbox-close') || e.target.classList.contains('lightbox-stage')) {
      closeLightbox();
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && lb.classList.contains('show')) closeLightbox();
  });

  lb.querySelector('.lightbox-decision').addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-decide]');
    if (!btn) return;
    const id = lb.getAttribute('data-id');
    const tile = document.querySelector('.lib-item[data-id="' + id + '"]');
    if (!tile) return;
    const status = btn.getAttribute('data-decide');

    const allBtns = lb.querySelectorAll('.decision-btn');
    allBtns.forEach(b => b.disabled = true);
    try {
      await saveDecision(id, status);
      applyStatus(tile, status);
      syncLightbox(status);
      showToast(status === 'approved' ? '✓ Approved' : status === 'denied' ? '✕ Denied' : '↺ Reset to pending');
    } catch (err) {
      showToast('Update failed — try again');
    } finally {
      allBtns.forEach(b => b.disabled = false);
    }
  });

  // Deep-link support: /library?client=x#lib-123 opens straight to that image.
  if (window.location.hash) {
    const target = document.querySelector(window.location.hash + '.lib-item') || document.querySelector(window.location.hash);
    if (target && target.classList.contains('lib-item')) {
      target.scrollIntoView({ block: 'center' });
    }
  }
})();
</script>

</body>
</html>
