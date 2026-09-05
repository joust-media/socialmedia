<?php
/**
 * Social Media Approval Feed — public view.
 * Optional ?client=hmf scopes the view to one company.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

// Where the "Post" CTA opens. Mirrors the admin tile — same target for every client for now.
$postCtaUrl = 'https://www.facebook.com/kendapowersports';

/** Does the posts.posted column exist yet? (migrate.php may not have run) */
function hasPostedColumn(PDO $pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'posts'
          AND COLUMN_NAME = 'posted'
    ");
    $s->execute();
    return $cached = (int)$s->fetchColumn() > 0;
}

// Available months (data-driven, populates the dropdown) — scoped to client if provided
$monthSql    = "SELECT DISTINCT DATE_FORMAT(p.scheduled_date, '%Y-%m') AS ym FROM posts p";
$monthParams = [];
if ($client) {
    $monthSql     .= " WHERE p.company_id = ?";
    $monthParams[] = $client['id'];
}
$monthSql .= " ORDER BY ym ASC";
$monthsStmt = $pdo->prepare($monthSql);
$monthsStmt->execute($monthParams);
$availableMonths = array_column($monthsStmt->fetchAll(), 'ym');

// All categories removed from feed — managed on tires page only
$selectedCats = [];  // kept empty for URL builder compatibility

// Selected statuses (multi, comma-separated in URL)
$allStatuses = ['pending', 'approved', 'denied'];
$selectedStatuses = [];
if (!empty($_GET['status'])) {
    $requested = array_map('trim', explode(',', $_GET['status']));
    $selectedStatuses = array_values(array_intersect($requested, $allStatuses));
}

// Selected month — with a sentinel value 'all' meaning "explicitly show all months".
// If no ?month= param is present at all, default to the current month (if it has posts).
$monthParam = $_GET['month'] ?? null;
if ($monthParam === 'all') {
    $selectedMonth = '';   // explicit "show everything"
} elseif ($monthParam === null) {
    // First visit / no month in URL — default to current month if it has posts
    $currentMonth = date('Y-m');
    $selectedMonth = in_array($currentMonth, $availableMonths, true) ? $currentMonth : '';
} else {
    $selectedMonth = in_array($monthParam, $availableMonths, true) ? $monthParam : '';
}

// Build the posts query with optional filters
$where  = [];
$params = [];
if ($client) {
    $where[]  = "p.company_id = ?";
    $params[] = $client['id'];
}
if ($selectedMonth !== '') {
    $where[]  = "DATE_FORMAT(p.scheduled_date, '%Y-%m') = ?";
    $params[] = $selectedMonth;
}
if (!empty($selectedStatuses)) {
    $ph = implode(',', array_fill(0, count($selectedStatuses), '?'));
    $where[] = "p.status IN ($ph)";
    $params = array_merge($params, $selectedStatuses);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$postedSel = hasPostedColumn($pdo) ? 'p.posted,' : '0 AS posted,';
$typeSel   = hasPostTypeColumn($pdo) ? 'p.post_type,' : "'post' AS post_type,";
$hasPostUpdated  = $pdo->query("SHOW COLUMNS FROM posts LIKE 'updated_at'")->rowCount() > 0;
$hasImageUpdated = $pdo->query("SHOW COLUMNS FROM post_images LIKE 'updated_at'")->rowCount() > 0;
$postUpdatedSel  = $hasPostUpdated ? 'p.updated_at,' : 'NULL AS updated_at,';
$postsStmt = $pdo->prepare("
    SELECT p.id, p.caption, p.hashtags, p.scheduled_date, p.status, p.client_comment,
           $postedSel
           $typeSel
           $postUpdatedSel
           c.name AS company_name, c.logo_url AS company_logo
    FROM posts p
    INNER JOIN companies c ON c.id = p.company_id
    $whereSql
    ORDER BY p.scheduled_date ASC
");
$postsStmt->execute($params);
$posts = $postsStmt->fetchAll();

// Status counts — respect client + month + category filters, but IGNORE status filter,
// so each pill shows how many posts are in that status within the current view.
$countWhere  = [];
$countParams = [];
if ($client) {
    $countWhere[]  = "p.company_id = ?";
    $countParams[] = $client['id'];
}
if ($selectedMonth !== '') {
    $countWhere[]  = "DATE_FORMAT(p.scheduled_date, '%Y-%m') = ?";
    $countParams[] = $selectedMonth;
}
$countWhereSql = $countWhere ? ('WHERE ' . implode(' AND ', $countWhere)) : '';

$statusCounts = ['pending' => 0, 'approved' => 0, 'denied' => 0];
$countStmt = $pdo->prepare("
    SELECT p.status, COUNT(*) AS n
    FROM posts p
    $countWhereSql
    GROUP BY p.status
");
$countStmt->execute($countParams);
foreach ($countStmt->fetchAll() as $row) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = (int)$row['n'];
    }
}
$totalViewCount  = array_sum($statusCounts);  // total ignoring status filter
$filteredCount   = count($posts);              // currently displayed

if ($posts) {
    $postIds = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $imgUpdatedCol = $hasImageUpdated ? ', updated_at' : '';
    $hasMediaType  = $pdo->query("SHOW COLUMNS FROM post_images LIKE 'media_type'")->rowCount() > 0;
    $mediaTypeCol  = $hasMediaType ? ', media_type' : '';
    $imgStmt = $pdo->prepare("
        SELECT id, post_id, image_url{$mediaTypeCol}{$imgUpdatedCol}
        FROM post_images
        WHERE post_id IN ($placeholders)
        ORDER BY post_id, sort_order ASC
    ");
    $imgStmt->execute($postIds);
    $imagesByPost = [];
    $maxImgUpdatedByPost = [];
    foreach ($imgStmt->fetchAll() as $row) {
        $imagesByPost[$row['post_id']][] = [
            'id'    => (int)$row['id'],
            'url'   => $row['image_url'],
            'type'  => $row['media_type'] ?? mediaTypeFromUrl($row['image_url']),
        ];
        $u = $row['updated_at'] ?? null;
        if ($u && (!isset($maxImgUpdatedByPost[$row['post_id']]) || $u > $maxImgUpdatedByPost[$row['post_id']])) {
            $maxImgUpdatedByPost[$row['post_id']] = $u;
        }
    }
    foreach ($posts as &$p) {
        $p['images'] = $imagesByPost[$p['id']] ?? [];
        // Effective "last updated" = max(post.updated_at, latest image edit)
        $imgMax = $maxImgUpdatedByPost[$p['id']] ?? null;
        $postMax = $p['updated_at'] ?? null;
        if ($imgMax && (!$postMax || $imgMax > $postMax)) {
            $p['updated_at'] = $imgMax;
        }
    }
    unset($p);
}

$navItems = clientNavItems($pdo, $client);

/** Escape helper */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format a DATETIME like "April 14, 2026 at 9:30 AM" */
function formatDate($dt) {
    $ts = strtotime($dt);
    return $ts ? date('F j, Y \a\t g:i A', $ts) : h($dt);
}

/** Convert "2026-04" -> "April 2026" */
function formatMonth($ym) {
    $ts = strtotime($ym . '-01');
    return $ts ? date('F Y', $ts) : h($ym);
}

/** Build a filter URL preserving the other filter dimensions */
function buildFilterUrl($cats, $month, $statuses = null) {
    global $clientSlug, $selectedStatuses;
    $statuses = $statuses === null ? $selectedStatuses : $statuses;
    $qs = [];
    if ($clientSlug !== '')   { $qs['client'] = $clientSlug; }
    if (!empty($cats))        { $qs['cats'] = implode(',', $cats); }
    if ($month !== '')        { $qs['month'] = $month; }
    if (!empty($statuses))    { $qs['status'] = implode(',', $statuses); }
    return 'feed' . ($qs ? '?' . http_build_query($qs) : '');
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $client ? h($client['name']) . ' — Posts' : 'Posts' ?></title>
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

  .feed { max-width: 680px; margin: 0 auto; padding: 20px 16px 60px; }

  /* Month filter */
  .month-filter {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 14px 16px;
    margin-bottom: 20px;
  }
  .month-filter-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
    margin-bottom: 8px;
    display: block;
  }
  .month-filter select {
    width: 100%;
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
  }
  .month-filter select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }

  /* Category chip filter */
  .cat-filter {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 14px 16px;
    margin-bottom: 20px;
  }
  .cat-filter-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .cat-filter-clear {
    font-size: 11px;
    color: var(--accent);
    text-decoration: none;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
  }
  .cat-filter-clear:hover { text-decoration: underline; }
  .cat-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .cat-chip {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    text-decoration: none;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
    user-select: none;
  }
  .cat-chip:hover { background: var(--border); }
  .cat-chip.active {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
  }
  .cat-chip.active::before {
    content: '✓ ';
    font-weight: 700;
  }

  /* Status filter pills — colored to match post status pills */
  .status-pill {
    display: inline-flex;
    align-items: center;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-decoration: none;
    cursor: pointer;
    border: 1px solid transparent;
    transition: transform 0.1s, box-shadow 0.15s, opacity 0.15s;
    user-select: none;
    opacity: 0.55;
  }
  .status-pill:hover { opacity: 0.85; }
  .status-pill.active {
    opacity: 1;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
  }
  .status-pill.active::before {
    content: '✓ ';
    font-weight: 800;
    margin-right: 2px;
  }
  .status-pill.pending  { background: #78350f; color: #fde68a; border-color: #a16207; }
  .status-pill.approved { background: #14532d; color: #bbf7d0; border-color: #166534; }
  .status-pill.denied   { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }

  .status-pill-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 6px;
    padding: 0 7px;
    min-width: 20px;
    height: 18px;
    border-radius: 9px;
    background: rgba(255,255,255,0.15);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0;
  }
  .status-pill.active .status-pill-count {
    background: rgba(255,255,255,0.22);
  }

  /* Total count summary at top of feed */
  .total-summary {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 14px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: baseline;
    gap: 10px;
    flex-wrap: wrap;
  }
  .total-summary-main {
    font-size: 15px;
    color: var(--text);
  }
  .total-summary-main strong {
    font-size: 22px;
    font-weight: 800;
    color: var(--accent);
    letter-spacing: -0.5px;
    margin-right: 2px;
  }
  .total-summary-sub {
    margin-left: 6px;
    color: var(--text-muted);
    font-size: 14px;
  }

  /* Editable date */
  .post-date {
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }
  .post-updated {
    font-size: 12px;
    color: var(--text-muted);
    opacity: 0.85;
    white-space: nowrap;
  }
  .date-display {
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
    border: 1px solid transparent;
    transition: background 0.15s, border-color 0.15s;
  }
  .date-display:hover {
    background: var(--surface-2);
    border-color: var(--border);
  }
  .date-display::after {
    content: ' ✎';
    opacity: 0.4;
    font-size: 11px;
  }
  .date-input {
    background: var(--surface-2);
    border: 1px solid var(--accent);
    color: var(--text);
    padding: 4px 8px;
    border-radius: 4px;
    font: inherit;
    font-size: 13px;
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .date-input:focus { outline: none; }

  .empty {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    color: var(--text-muted);
  }

  .post {
    background: var(--surface);
    border-radius: 12px;
    box-shadow: var(--shadow);
    margin-bottom: 20px;
    overflow: hidden;
    border: 1px solid var(--border);
    transition: opacity 0.25s, filter 0.25s;
  }
  /* Posted = greyed but still visible */
  .post.is-posted {
    opacity: 0.5;
    filter: grayscale(0.6);
  }
  .post.is-posted:hover { opacity: 0.9; filter: grayscale(0); }
  .post-status.posted   { background: #312e81; color: #c7d2fe; }
  /* Briefly highlight the targeted post when arriving via #post-X anchor */
  .post:target {
    animation: target-pulse 2.4s ease-out;
    scroll-margin-top: 72px;
  }
  @keyframes target-pulse {
    0%   { box-shadow: 0 0 0 0 rgba(24,119,242,0.6); }
    30%  { box-shadow: 0 0 0 6px rgba(24,119,242,0.25); }
    100% { box-shadow: 0 0 0 0 rgba(24,119,242,0); }
  }
  .post-header {
    display: flex; align-items: center;
    padding: 14px 16px 10px; gap: 12px;
  }
  .post-logo {
    width: 44px; height: 44px; border-radius: 50%;
    flex-shrink: 0; object-fit: cover;
    background: var(--surface-2); border: 1px solid var(--border);
  }
  .post-meta { flex: 1; min-width: 0; }
  .post-name { font-weight: 600; font-size: 15px; color: var(--text); }
  .post-status {
    font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.5px;
    padding: 4px 10px; border-radius: 12px;
    background: var(--surface-2); color: var(--text-muted);
    transition: background 0.2s, color 0.2s;
  }
  .post-status.pending  { background: #78350f; color: #fde68a; }
  .post-status.approved { background: #14532d; color: #bbf7d0; }
  .post-status.denied   { background: #7f1d1d; color: #fecaca; }

  /* Post-type badge (Post / Story / Reel) — sits in post header, click to change. */
  .post-type-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    padding: 4px 10px; border-radius: 12px;
    background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text-muted);
    cursor: pointer; user-select: none;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
    white-space: nowrap;
  }
  .post-type-badge:hover { background: var(--border); color: var(--text); }
  .post-type-badge::after { content: ' ▾'; opacity: 0.55; font-size: 9px; }
  .post-type-badge.type-post  { background: #1e293b; color: #cbd5e1; border-color: #334155; }
  .post-type-badge.type-story { background: #4a044e; color: #f5d0fe; border-color: #6b21a8; }
  .post-type-badge.type-reel  { background: #134e4a; color: #99f6e4; border-color: #115e59; }

  .post-type-picker {
    position: absolute;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.35);
    padding: 6px;
    z-index: 50;
    display: flex; flex-direction: column;
    min-width: 140px;
  }
  .post-type-picker button {
    background: transparent;
    border: 1px solid transparent;
    color: var(--text);
    font: inherit; font-size: 13px; font-weight: 600;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    text-align: left;
    display: flex; align-items: center; gap: 8px;
  }
  .post-type-picker button:hover { background: var(--surface-2); }
  .post-type-picker button.current {
    background: var(--surface-2); border-color: var(--border);
  }
  .post-type-picker button.current::after {
    content: '✓'; margin-left: auto; color: var(--accent);
  }

  .post-decision {
    display: flex; gap: 8px;
    padding: 12px 16px;
  }
  .decision-btn {
    flex: 1;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    font-size: 14px; font-weight: 600;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: background 0.15s, border-color 0.15s, color 0.15s, transform 0.1s;
  }
  .decision-btn:hover { background: var(--border); }
  .decision-btn:active { transform: scale(0.98); }
  .decision-btn:disabled { opacity: 0.6; cursor: wait; }
  .decision-btn.approve.active {
    background: #16a34a; border-color: #16a34a; color: #fff;
  }
  .decision-btn.deny.active {
    background: #dc2626; border-color: #dc2626; color: #fff;
  }
  .decision-btn.approve.active:hover { background: #15803d; }
  .decision-btn.deny.active:hover    { background: #b91c1c; }

  /* Reset-to-pending button — only visible when post isn't pending */
  .decision-btn.reset {
    flex: 0 0 auto;
    padding: 10px 14px;
    color: var(--text-muted);
    background: transparent;
    display: none;
  }
  .post[data-status="approved"] .decision-btn.reset,
  .post[data-status="denied"]   .decision-btn.reset {
    display: flex;
  }
  .decision-btn.reset:hover {
    background: var(--surface-2);
    color: var(--text);
    border-color: var(--text-muted);
  }

  .post-comment {
    padding: 0 16px 14px;
  }
  .comment-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    margin-bottom: 6px;
  }
  .comment-textarea {
    width: 100%;
    min-height: 60px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 10px 12px;
    border-radius: 8px;
    font: inherit;
    font-size: 14px;
    line-height: 1.4;
    resize: vertical;
    font-family: inherit;
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  .comment-textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .comment-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 8px;
    font-size: 12px;
    color: var(--text-muted);
    min-height: 28px;
  }
  .comment-status { font-style: italic; }
  .comment-status.dirty  { color: var(--text-muted); }
  .comment-status.saving { color: var(--text-muted); }
  .comment-status.saved  { color: #16a34a; }
  .comment-status.error  { color: #dc2626; }
  .comment-save-btn {
    background: var(--accent);
    color: #fff;
    border: 1px solid var(--accent);
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s, opacity 0.15s;
  }
  .comment-save-btn:hover { background: var(--accent-hover); }
  .comment-save-btn:disabled {
    background: var(--surface-2);
    color: var(--text-muted);
    border-color: var(--border);
    cursor: not-allowed;
  }
  /* Chat-style thread bubbles inside posts */
  .comment-thread { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
  .comment-msg {
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: 10px; padding: 10px 14px;
  }
  .comment-msg-client { border-left: 3px solid var(--accent); }
  .comment-msg-admin  { border-left: 3px solid #a855f7; }
  .comment-msg-head {
    display: flex; gap: 8px; align-items: baseline; margin-bottom: 4px;
  }
  .comment-msg-actor {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.4px; color: var(--text-muted);
  }
  .comment-msg-actor::before { content: '@'; opacity: 0.5; }
  .comment-msg-time { font-size: 11px; color: var(--text-muted); margin-left: auto; }
  .comment-msg-body {
    font-size: 13px; color: var(--text);
    white-space: pre-wrap; word-wrap: break-word;
  }
  .comment-empty {
    padding: 10px; text-align: center;
    color: var(--text-muted); font-size: 12px; font-style: italic;
    background: var(--surface-2); border-radius: 6px; margin-bottom: 8px;
  }

  .post-body { padding: 0 16px 12px; }
  .post-caption {
    white-space: pre-wrap; word-wrap: break-word;
    font-size: 15px; color: var(--text); margin-bottom: 8px;
    cursor: pointer;
    padding: 4px 8px;
    margin-left: -8px;
    margin-right: -8px;
    border-radius: 6px;
    border: 1px solid transparent;
    transition: background 0.15s, border-color 0.15s;
  }
  .post-caption:hover {
    background: var(--surface-2);
    border-color: var(--border);
  }
  .post-caption:hover::after {
    content: ' ✎';
    opacity: 0.4;
    font-size: 12px;
  }
  .post-caption-edit {
    width: 100%;
    min-height: 80px;
    background: var(--surface-2);
    border: 1px solid var(--accent);
    color: var(--text);
    padding: 10px 12px;
    border-radius: 6px;
    font: inherit;
    font-size: 15px;
    line-height: 1.4;
    resize: vertical;
    font-family: inherit;
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
    box-sizing: border-box;
  }
  .post-caption-edit:focus { outline: none; }
  .post-caption-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    margin-bottom: 8px;
    font-size: 12px;
    color: var(--text-muted);
  }
  .post-caption-actions button {
    padding: 4px 10px;
    border-radius: 4px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
  }
  .post-caption-actions button.primary {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
  }
  .post-hashtags { color: var(--accent); font-size: 14px; word-wrap: break-word; }

  .post-actions-top { padding: 0 16px 12px; display: flex; gap: 8px; }
  .post-cta-btn {
    flex: 1;
    background: var(--accent); border: 1px solid var(--accent); color: #fff;
    padding: 10px 14px; border-radius: 8px;
    cursor: pointer; font-size: 14px; font-weight: 700;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: background 0.15s, transform 0.1s;
  }
  .post-cta-btn:hover { background: var(--accent-hover); }
  .post-cta-btn:active { transform: scale(0.98); }
  .copy-btn {
    flex: 1;
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 10px 14px; border-radius: 8px;
    cursor: pointer; font-size: 14px; font-weight: 600;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: background 0.15s, transform 0.1s;
  }
  .copy-btn:hover { background: var(--border); }
  .copy-btn:active { transform: scale(0.98); }
  .delete-post-btn {
    flex: 0 0 auto;
    background: none;
    border: 1px solid var(--border);
    color: var(--text-muted);
    padding: 10px 14px; border-radius: 8px;
    cursor: pointer; font-size: 14px; font-weight: 600;
    display: flex; align-items: center; gap: 6px;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
  }
  .delete-post-btn:hover {
    background: #7f1d1d; color: #fecaca; border-color: #991b1b;
  }
  .delete-post-btn:disabled { opacity: 0.5; cursor: wait; }

  /* Mark as Posted button */
  .mark-posted-btn {
    flex: 1;
    background: #14532d; border: 1px solid #166534; color: #bbf7d0;
    padding: 10px 14px; border-radius: 8px;
    cursor: pointer; font-size: 14px; font-weight: 700;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: background 0.15s, transform 0.1s;
  }
  .mark-posted-btn:hover { background: #166534; color: #fff; }
  .mark-posted-btn:active { transform: scale(0.98); }
  .mark-posted-btn:disabled { opacity: 0.6; cursor: wait; }
  .unmark-posted-btn {
    flex: 1;
    background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);
    padding: 10px 14px; border-radius: 8px;
    cursor: pointer; font-size: 14px; font-weight: 600;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
  }
  .unmark-posted-btn:hover {
    background: var(--surface-2); color: var(--text); border-color: var(--text-muted);
  }
  .unmark-posted-btn:disabled { opacity: 0.5; cursor: wait; }

  .post-media {
    position: relative;
    background: var(--border);
  }
  /* The scrolling row. Single-image posts render exactly as before;
     multi-image posts become a side-swiping carousel. */
  .post-media-track {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .post-media.is-carousel .post-media-track {
    flex-direction: row;
    gap: 0;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
  }
  .post-media.is-carousel .post-media-track::-webkit-scrollbar { display: none; }
  .media-item {
    position: relative;
    background: var(--surface-2);
    overflow: hidden;
    width: 100%;
  }
  .post-media.is-carousel .media-item {
    flex: 0 0 100%;
    scroll-snap-align: center;
  }
  .media-item img {
    width: 100%;
    height: auto;
    display: block;
  }

  /* Carousel controls */
  .carousel-nav {
    position: absolute; top: 50%; transform: translateY(-50%);
    width: 36px; height: 36px; border-radius: 50%;
    background: rgba(0,0,0,0.5); color: #fff; border: none;
    font-size: 22px; line-height: 1; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    z-index: 5; opacity: 0;
    transition: opacity 0.2s, background 0.15s;
    backdrop-filter: blur(2px);
  }
  .carousel-nav.prev { left: 10px; }
  .carousel-nav.next { right: 10px; }
  .post-media.is-carousel:hover .carousel-nav:not([disabled]) { opacity: 1; }
  .carousel-nav:hover { background: rgba(0,0,0,0.75); }
  .carousel-nav[disabled] { opacity: 0 !important; pointer-events: none; }
  @media (hover: none) {
    .post-media.is-carousel .carousel-nav:not([disabled]) { opacity: 0.85; }
  }
  .carousel-dots {
    position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
    display: flex; gap: 6px; z-index: 5;
    padding: 5px 9px; border-radius: 12px;
    background: rgba(0,0,0,0.35); backdrop-filter: blur(4px);
  }
  .carousel-dot {
    width: 7px; height: 7px; padding: 0; border: none; border-radius: 50%;
    background: rgba(255,255,255,0.5); cursor: pointer;
    transition: background 0.15s, transform 0.15s;
  }
  .carousel-dot.active { background: #fff; transform: scale(1.25); }
  .carousel-counter {
    position: absolute; top: 10px; left: 50%; transform: translateX(-50%);
    z-index: 4; background: rgba(0,0,0,0.6); color: #fff;
    font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 12px;
    backdrop-filter: blur(4px); pointer-events: none;
  }
  .save-img-btn {
    position: absolute; top: 10px; right: 10px;
    background: rgba(0,0,0,0.65); color: #fff; border: none;
    padding: 7px 11px; border-radius: 20px;
    cursor: pointer; font-size: 12px; font-weight: 600;
    display: flex; align-items: center; gap: 5px;
    opacity: 0; transform: translateY(-4px);
    transition: opacity 0.2s, transform 0.2s, background 0.15s;
    backdrop-filter: blur(4px);
  }
  .media-item:hover .save-img-btn,
  .save-img-btn:focus { opacity: 1; transform: translateY(0); }
  .save-img-btn:hover { background: rgba(0,0,0,0.85); }
  @media (hover: none) { .save-img-btn { opacity: 1; transform: none; } }

  .replace-img-btn {
    position: absolute; top: 10px; left: 10px;
    background: rgba(24,119,242,0.85); color: #fff; border: none;
    padding: 7px 11px; border-radius: 20px;
    cursor: pointer; font-size: 12px; font-weight: 600;
    display: flex; align-items: center; gap: 5px;
    opacity: 0; transform: translateY(-4px);
    transition: opacity 0.2s, transform 0.2s, background 0.15s;
    backdrop-filter: blur(4px);
  }
  .media-item:hover .replace-img-btn,
  .replace-img-btn:focus { opacity: 1; transform: translateY(0); }
  .replace-img-btn:hover { background: rgba(24,119,242,1); }
  .replace-img-btn:disabled { opacity: 0.8; cursor: wait; }
  @media (hover: none) { .replace-img-btn { opacity: 1; transform: none; } }
  .media-item.replacing img { opacity: 0.5; filter: blur(1px); transition: opacity 0.2s, filter 0.2s; }

  .post-hashtags[data-hashtags-display] {
    cursor: pointer;
    padding: 2px 4px;
    margin: 0 -4px;
    border-radius: 4px;
    transition: background 0.15s;
  }
  .post-hashtags[data-hashtags-display]:hover,
  .post-hashtags[data-hashtags-display]:focus {
    background: var(--surface-2);
    outline: none;
  }
  .post-hashtags[data-hashtags-display]:empty::before {
    content: 'Click to add hashtags';
    color: var(--text-muted);
    font-style: italic;
  }

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

  @media (max-width: 520px) {
    .feed { padding: 12px 0 60px; }
    .post { border-radius: 0; border-left: none; border-right: none; margin-bottom: 12px; }
  }
</style>
</head>
<body>

<?= renderClientNav($navItems, 'feed') ?>

<main class="feed" id="feed">

<div class="total-summary">
  <div class="total-summary-main">
    <?php if ($filteredCount === $totalViewCount || empty($selectedStatuses)): ?>
      <strong><?= $filteredCount ?></strong> <?= $filteredCount === 1 ? 'post' : 'posts' ?>
    <?php else: ?>
      <strong><?= $filteredCount ?></strong> of <?= $totalViewCount ?> <?= $totalViewCount === 1 ? 'post' : 'posts' ?>
    <?php endif; ?>
    <?php if ($selectedMonth !== ''): ?>
      <span class="total-summary-sub">in <?= h(formatMonth($selectedMonth)) ?></span>
    <?php endif; ?>
  </div>
</div>

<div class="cat-filter">
  <div class="cat-filter-label">
    <span>Filter by status</span>
    <?php if (!empty($selectedStatuses)): ?>
      <a class="cat-filter-clear" href="<?= h(buildFilterUrl($selectedCats, $selectedMonth, [])) ?>">Clear</a>
    <?php endif; ?>
  </div>
  <div class="cat-chips">
    <?php foreach ($allStatuses as $statusKey):
      $active = in_array($statusKey, $selectedStatuses, true);
      $newStatuses = $active
          ? array_values(array_diff($selectedStatuses, [$statusKey]))
          : array_merge($selectedStatuses, [$statusKey]);
      $href = buildFilterUrl($selectedCats, $selectedMonth, $newStatuses);
    ?>
      <a class="status-pill <?= h($statusKey) ?> <?= $active ? 'active' : '' ?>"
         href="<?= h($href) ?>">
        <?= h(ucfirst($statusKey)) ?>
        <span class="status-pill-count"><?= (int)$statusCounts[$statusKey] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!empty($availableMonths)): ?>
  <div class="month-filter">
    <label class="month-filter-label" for="monthSelect">Filter by month</label>
    <select id="monthSelect"
            data-cats="<?= h(implode(',', $selectedCats)) ?>"
            data-statuses="<?= h(implode(',', $selectedStatuses)) ?>"
            data-client="<?= h($clientSlug) ?>">
      <option value="all" <?= $selectedMonth === '' ? 'selected' : '' ?>>All months</option>
      <?php foreach ($availableMonths as $ym): ?>
        <option value="<?= h($ym) ?>" <?= $selectedMonth === $ym ? 'selected' : '' ?>>
          <?= h(formatMonth($ym)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
<?php endif; ?>

<?php if (empty($posts)): ?>
  <div class="empty">
    <?php if (!empty($selectedCats) || $selectedMonth !== '' || !empty($selectedStatuses)): ?>
      No posts match the current filters.
    <?php else: ?>
      No posts yet. Add some in phpMyAdmin or via an admin tool to see them here.
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php foreach ($posts as $post):
      $count = min(count($post['images']), 10);
      $fullText = $post['caption'] . "\n\n" . $post['hashtags'];
  ?>
    <?php
      $isPosted = !empty($post['posted']);
      $postType = strtolower((string)($post['post_type'] ?? 'post'));
      if (!in_array($postType, allowedPostTypes(), true)) { $postType = 'post'; }
    ?>
    <article class="post <?= $isPosted ? 'is-posted' : '' ?>"
             id="post-<?= (int)$post['id'] ?>"
             data-id="<?= (int)$post['id'] ?>"
             data-status="<?= h($post['status']) ?>"
             data-posted="<?= $isPosted ? '1' : '0' ?>"
             data-post-type="<?= h($postType) ?>">
      <div class="post-header">
        <img class="post-logo"
             src="<?= h($post['company_logo']) ?>"
             alt="<?= h($post['company_name']) ?> logo">
        <div class="post-meta">
          <div class="post-name"><?= h($post['company_name']) ?></div>
          <div class="post-date">
            <?php if (isAdmin()): // inline date editing is admin-only ?>
            <span class="date-display"
                  data-date-display
                  data-iso="<?= h(date('Y-m-d\TH:i', strtotime($post['scheduled_date']))) ?>"
                  title="Click to edit"><?= h(formatDate($post['scheduled_date'])) ?></span>
            <?php else: ?>
            <span class="date-display"><?= h(formatDate($post['scheduled_date'])) ?></span>
            <?php endif; ?>
            <?php if (!empty($post['updated_at'])): ?>
              <span class="post-updated" title="Last edited: <?= h(absoluteTime($post['updated_at'])) ?>">
                · edited <?= h(relativeTime($post['updated_at'])) ?>
              </span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (hasPostTypeColumn($pdo)): ?>
          <?php if (isAdmin()): // changing the content type is admin-only ?>
            <button class="post-type-badge type-<?= h($postType) ?>"
                    data-post-type-badge
                    type="button"
                    title="Click to change content type">
              <?= h(postTypeLabel($postType)) ?>
            </button>
          <?php else: ?>
            <span class="post-type-badge type-<?= h($postType) ?>"><?= h(postTypeLabel($postType)) ?></span>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($isPosted): ?>
          <div class="post-status posted" data-status-pill title="This post has been posted">Posted</div>
        <?php else: ?>
          <div class="post-status <?= h($post['status']) ?>" data-status-pill><?= h(ucfirst($post['status'])) ?></div>
        <?php endif; ?>
      </div>

      <div class="post-body">
        <?php if (isAdmin()): // inline caption / hashtag editing is admin-only ?>
          <div class="post-caption"
               data-caption-display
               data-raw="<?= h($post['caption']) ?>"
               title="Click to edit"
               role="textbox"
               tabindex="0"><?= h($post['caption']) ?></div>
          <div class="post-hashtags"
               data-hashtags-display
               data-raw="<?= h($post['hashtags']) ?>"
               title="Click to edit"
               role="textbox"
               tabindex="0"><?= h($post['hashtags']) ?></div>
        <?php else: ?>
          <div class="post-caption" data-raw="<?= h($post['caption']) ?>"><?= h($post['caption']) ?></div>
          <div class="post-hashtags" data-raw="<?= h($post['hashtags']) ?>"><?= h($post['hashtags']) ?></div>
        <?php endif; ?>
      </div>

      <div class="post-actions-top">
        <?php if (isAdmin() && !$isPosted): // "Post" is Joust's step, not the client's ?>
          <button class="post-cta-btn"
                  data-post-cta
                  data-caption="<?= h($post['caption']) ?>"
                  data-hashtags="<?= h($post['hashtags']) ?>"
                  type="button"
                  title="Copies caption + hashtags and opens Facebook in a new tab">
            🚀 Post
          </button>
        <?php endif; ?>
        <button class="copy-btn" data-copy-post type="button">
          📋 Copy caption &amp; hashtags
        </button>
        <?php if (isAdmin()): // Mark as Posted / Delete: admin-only, never rendered for clients ?>
          <?php if (hasPostedColumn($pdo)): ?>
            <?php if (!$isPosted): ?>
              <button class="mark-posted-btn" data-toggle-posted data-to="1" type="button"
                      title="Mark this post as posted">
                ✓ Mark as Posted
              </button>
            <?php else: ?>
              <button class="unmark-posted-btn" data-toggle-posted data-to="0" type="button"
                      title="Unmark this post">
                ↺ Unmark
              </button>
            <?php endif; ?>
          <?php endif; ?>
          <button class="delete-post-btn" data-delete-post type="button">
            🗑 Delete
          </button>
        <?php endif; ?>
      </div>

      <?php if ($count > 0): ?>
        <?php $isCarousel = $count > 1; ?>
        <div class="post-media count-<?= $count ?> <?= $isCarousel ? 'is-carousel' : '' ?>"
             <?= $isCarousel ? 'data-carousel' : '' ?>>
          <div class="post-media-track">
          <?php foreach (array_slice($post['images'], 0, 10) as $i => $img):
              $src      = $img['url'];
              $imgId    = $img['id'];
              $mtype    = $img['type'] ?? mediaTypeFromUrl($src);
              $isVid    = ($mtype === 'video');
              $ext      = strtolower(pathinfo($src, PATHINFO_EXTENSION));
              $filename = preg_replace('/[^a-zA-Z0-9\-]/', '-', $post['company_name'])
                          . '-' . $post['id'] . '-' . ($i + 1);
          ?>
            <div class="media-item" data-image-id="<?= (int)$imgId ?>" data-media-type="<?= h($mtype) ?>">
              <?php if ($isVid): ?>
                <video src="<?= h($src) ?>"
                       controls playsinline preload="metadata"
                       data-image-el
                       style="width:100%;height:100%;object-fit:contain;background:#000">
                  Your browser can't play this video.
                </video>
              <?php else: ?>
                <img src="<?= h($src) ?>" alt="Post image <?= $i + 1 ?>" loading="lazy" data-image-el>
              <?php endif; ?>
              <button class="save-img-btn"
                      data-src="<?= h($src) ?>"
                      data-filename="<?= h($filename) ?>"
                      data-ext="<?= h($ext) ?>">
                ⬇ Save
              </button>
              <?php if (isAdmin()): // Replace is admin-only, never rendered for clients ?>
                <button class="replace-img-btn" data-replace-img type="button"
                        title="Replace this <?= $isVid ? 'video' : 'image' ?>">
                  🔄 Replace
                </button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div><!-- /.post-media-track -->
          <?php if ($isCarousel): ?>
            <button class="carousel-nav prev" data-carousel-prev type="button" aria-label="Previous image" disabled>&#8249;</button>
            <button class="carousel-nav next" data-carousel-next type="button" aria-label="Next image">&#8250;</button>
            <div class="carousel-counter" data-carousel-counter>1/<?= $count ?></div>
            <div class="carousel-dots">
              <?php for ($d = 0; $d < $count; $d++): ?>
                <button class="carousel-dot <?= $d === 0 ? 'active' : '' ?>"
                        data-carousel-dot="<?= $d ?>" type="button"
                        aria-label="Go to image <?= $d + 1 ?>"></button>
              <?php endfor; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="post-decision">
        <button class="decision-btn approve <?= $post['status'] === 'approved' ? 'active' : '' ?>"
                data-decide="approved" type="button">
          ✓ Approve
        </button>
        <button class="decision-btn deny <?= $post['status'] === 'denied' ? 'active' : '' ?>"
                data-decide="denied" type="button">
          ✕ Deny
        </button>
        <button class="decision-btn reset"
                data-decide="pending" type="button"
                title="Reset to pending">
          ↺ Reset
        </button>
      </div>

      <div class="post-comment">
        <label class="comment-label" for="comment-<?= (int)$post['id'] ?>">
          💬 Comments
        </label>
        <?php $threadHtml = renderCommentThread($pdo, 'post', (int)$post['id']); ?>
        <?php if ($threadHtml): ?>
          <?= $threadHtml ?>
        <?php else: ?>
          <div class="comment-empty">No messages yet — start the thread below.</div>
        <?php endif; ?>
        <textarea class="comment-textarea"
                  id="comment-<?= (int)$post['id'] ?>"
                  data-comment-input
                  placeholder="Type a message — required if denying, optional otherwise."
                  maxlength="2000"></textarea>
        <div class="comment-meta">
          <span class="comment-status" data-comment-status></span>
          <button class="comment-save-btn" data-comment-save type="button" disabled>
            Send message
          </button>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
<?php endif; ?>

</main>

<div class="toast" id="toast">Copied!</div>

<script>
  const toastEl = document.getElementById('toast');
  let toastTimer;
  function showToast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1800);
  }

  const POST_CTA_URL = <?= json_encode($postCtaUrl) ?>;

  async function copyTextFallback(text) {
    if (navigator.clipboard && window.isSecureContext) {
      try { await navigator.clipboard.writeText(text); return true; } catch {}
    }
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
    document.body.appendChild(ta); ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch {}
    document.body.removeChild(ta);
    return ok;
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-post-cta]');
    if (!btn) return;
    e.preventDefault();
    const caption  = btn.getAttribute('data-caption')  || '';
    const hashtags = btn.getAttribute('data-hashtags') || '';
    const text = (caption + (hashtags ? '\n\n' + hashtags : '')).trim();
    window.open(POST_CTA_URL, '_blank', 'noopener');
    const ok = await copyTextFallback(text);
    showToast(ok ? '📋 Caption + hashtags copied' : 'Copy failed — please copy manually');
  });

  document.getElementById('feed').addEventListener('click', async (e) => {
    // Approve / Deny buttons
    const decideBtn = e.target.closest('.decision-btn');
    if (decideBtn) {
      const post = decideBtn.closest('.post');
      const postId = post.getAttribute('data-id');
      const newStatus = decideBtn.getAttribute('data-decide');

      // Disable both buttons during request
      const allBtns = post.querySelectorAll('.decision-btn');
      allBtns.forEach(b => b.disabled = true);

      try {
        const formData = new FormData();
        formData.append('id', postId);
        formData.append('status', newStatus);
        formData.append('actor', 'client');
        const res = await fetch('status.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');

        // Update active state
        allBtns.forEach(b => b.classList.toggle(
          'active', b.getAttribute('data-decide') === newStatus
        ));
        // Update pill
        const pill = post.querySelector('[data-status-pill]');
        pill.className = 'post-status ' + newStatus;
        pill.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
        post.setAttribute('data-status', newStatus);

        showToast(
          newStatus === 'approved' ? '✓ Approved' :
          newStatus === 'denied'   ? '✕ Denied'   :
                                     '↺ Reset to pending'
        );
      } catch (err) {
        showToast('Update failed — try again');
      } finally {
        allBtns.forEach(b => b.disabled = false);
      }
      return;
    }
    const copyBtn = e.target.closest('[data-copy-post]');
    if (copyBtn) {
      const post = copyBtn.closest('.post');
      const capEl = post.querySelector('.post-caption');
      const hashEl = post.querySelector('.post-hashtags');
      const caption = capEl ? capEl.getAttribute('data-raw') : '';
      const hashtags = hashEl ? hashEl.textContent.trim() : '';
      const text = caption + (hashtags ? '\n\n' + hashtags : '');
      try {
        await navigator.clipboard.writeText(text);
        showToast('✓ Caption copied');
      } catch {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('✓ Caption copied');
      }
      return;
    }

    // Post-type badge — open inline picker
    const typeBadge = e.target.closest('[data-post-type-badge]');
    if (typeBadge) {
      // If a picker is already open for this badge, close it instead.
      const existing = document.querySelector('.post-type-picker');
      if (existing) {
        existing.remove();
        if (existing.dataset.badgeId === typeBadge.id) return;
      }
      const post = typeBadge.closest('.post');
      const currentType = (post.getAttribute('data-post-type') || 'post').toLowerCase();
      const opts = [
        { v: 'post',  label: '📄 Post'  },
        { v: 'story', label: '⭕ Story' },
        { v: 'reel',  label: '🎬 Reel'  },
      ];
      const picker = document.createElement('div');
      picker.className = 'post-type-picker';
      opts.forEach(o => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = o.label;
        b.dataset.value = o.v;
        if (o.v === currentType) b.classList.add('current');
        picker.appendChild(b);
      });

      // Position picker just below the badge.
      document.body.appendChild(picker);
      const r = typeBadge.getBoundingClientRect();
      picker.style.top  = (window.scrollY + r.bottom + 6) + 'px';
      picker.style.left = (window.scrollX + r.left) + 'px';

      const close = () => { picker.remove(); document.removeEventListener('click', onDocClick, true); };
      const onDocClick = (ev) => {
        if (picker.contains(ev.target) || ev.target === typeBadge) return;
        close();
      };
      // Defer so the current click doesn't immediately close it.
      setTimeout(() => document.addEventListener('click', onDocClick, true), 0);

      picker.addEventListener('click', async (pev) => {
        const choice = pev.target.closest('button[data-value]');
        if (!choice) return;
        const newType = choice.dataset.value;
        close();
        if (newType === currentType) return;

        try {
          const fd = new FormData();
          fd.append('id', post.getAttribute('data-id'));
          fd.append('post_type', newType);
          fd.append('actor', 'client');
          const res = await fetch('status.php', { method: 'POST', body: fd });
          const data = await res.json();
          if (!data.ok) throw new Error(data.error || 'Failed');

          // Update DOM
          post.setAttribute('data-post-type', newType);
          typeBadge.className = 'post-type-badge type-' + newType;
          const icons = { post: '📄', story: '⭕', reel: '🎬' };
          const label = newType.charAt(0).toUpperCase() + newType.slice(1);
          typeBadge.textContent = (icons[newType] || '📄') + ' ' + label;
          showToast('✓ Type set to ' + label);
        } catch (err) {
          showToast('Type save failed: ' + (err.message || 'try again'));
        }
      });
      return;
    }

    // Toggle "posted" flag (Mark as Posted / Unmark)
    const togglePostedBtn = e.target.closest('[data-toggle-posted]');
    if (togglePostedBtn) {
      const post = togglePostedBtn.closest('.post');
      const postId = post.getAttribute('data-id');
      const to = togglePostedBtn.getAttribute('data-to') === '1' ? '1' : '0';
      togglePostedBtn.disabled = true;
      const originalLabel = togglePostedBtn.textContent;
      togglePostedBtn.textContent = to === '1' ? 'Marking…' : 'Unmarking…';
      try {
        const fd = new FormData();
        fd.append('action', 'toggle_posted');
        fd.append('id', postId);
        fd.append('to', to);
        fd.append('actor', 'client');
        const res = await fetch('status.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');

        // Flip the post's posted state in the DOM
        const isNowPosted = to === '1';
        post.setAttribute('data-posted', isNowPosted ? '1' : '0');
        post.classList.toggle('is-posted', isNowPosted);

        // Swap status pill
        const pill = post.querySelector('[data-status-pill]');
        if (pill) {
          if (isNowPosted) {
            pill.className = 'post-status posted';
            pill.textContent = 'Posted';
            pill.setAttribute('title', 'This post has been posted');
          } else {
            const currStatus = post.getAttribute('data-status') || 'pending';
            pill.className = 'post-status ' + currStatus;
            pill.textContent = currStatus.charAt(0).toUpperCase() + currStatus.slice(1);
            pill.removeAttribute('title');
          }
        }

        // Swap the action button itself: Mark <-> Unmark
        if (isNowPosted) {
          togglePostedBtn.className = 'unmark-posted-btn';
          togglePostedBtn.setAttribute('data-to', '0');
          togglePostedBtn.setAttribute('title', 'Unmark this post');
          togglePostedBtn.textContent = '↺ Unmark';
          // Hide the 🚀 Post CTA now that it's posted
          const cta = post.querySelector('[data-post-cta]');
          if (cta) cta.style.display = 'none';
        } else {
          togglePostedBtn.className = 'mark-posted-btn';
          togglePostedBtn.setAttribute('data-to', '1');
          togglePostedBtn.setAttribute('title', 'Mark this post as posted');
          togglePostedBtn.textContent = '✓ Mark as Posted';
          // Reveal the 🚀 Post CTA again
          const cta = post.querySelector('[data-post-cta]');
          if (cta) cta.style.display = '';
        }

        showToast(isNowPosted ? '✓ Marked as posted' : '↺ Unmarked');
      } catch (err) {
        togglePostedBtn.textContent = originalLabel;
        showToast('Update failed — try again');
      } finally {
        togglePostedBtn.disabled = false;
      }
      return;
    }

    // Delete post
    const deleteBtn = e.target.closest('[data-delete-post]');
    if (deleteBtn) {
      if (!confirm('Delete this post and all its images? This cannot be undone.')) return;
      const post = deleteBtn.closest('.post');
      const postId = post.getAttribute('data-id');
      deleteBtn.disabled = true;
      deleteBtn.textContent = 'Deleting…';
      try {
        const fd = new FormData();
        fd.append('action', 'delete_post');
        fd.append('id', postId);
        fd.append('actor', 'client');
        const res = await fetch('status.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        post.style.transition = 'opacity 0.3s, transform 0.3s';
        post.style.opacity = '0';
        post.style.transform = 'scale(0.98)';
        setTimeout(() => post.remove(), 300);
        showToast('✓ Post deleted');
      } catch (err) {
        deleteBtn.disabled = false;
        deleteBtn.textContent = '🗑 Delete';
        showToast('Delete failed');
      }
      return;
    }

    const saveBtn = e.target.closest('.save-img-btn');
    if (saveBtn) {
      const src      = saveBtn.getAttribute('data-src');
      // Pick the right extension — videos shouldn't end in .jpg.
      const ext      = (saveBtn.getAttribute('data-ext') || '').toLowerCase()
                       || (src.match(/\.([a-z0-9]+)(\?|$)/i) || [, 'jpg'])[1].toLowerCase();
      const filename = saveBtn.getAttribute('data-filename') + '.' + ext;
      const isVideo  = ['mp4', 'webm', 'mov', 'm4v'].includes(ext);
      try {
        const res = await fetch(src, { mode: 'cors' });
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast(isVideo ? '✓ Video saved' : '✓ Image saved');
      } catch {
        window.open(src, '_blank');
        showToast('Opened in new tab');
      }
    }
  });

  // ---- Comments (chat-style send) -------------------------------
  document.querySelectorAll('[data-comment-input]').forEach(ta => {
    ta.addEventListener('input', () => {
      const post = ta.closest('.post');
      const saveBtn = post.querySelector('[data-comment-save]');
      const status  = post.querySelector('[data-comment-status]');
      const hasText = ta.value.trim().length > 0;
      saveBtn.disabled = !hasText;
      status.className = 'comment-status' + (hasText ? ' dirty' : '');
      status.textContent = hasText ? 'Press send to post' : '';
    });
  });

  document.getElementById('feed').addEventListener('click', async (e) => {
    const saveBtn = e.target.closest('[data-comment-save]');
    if (!saveBtn) return;

    const post   = saveBtn.closest('.post');
    const postId = post.getAttribute('data-id');
    const ta     = post.querySelector('[data-comment-input]');
    const status = post.querySelector('[data-comment-status]');
    const text   = ta.value.trim();
    if (!text) { ta.focus(); return; }

    saveBtn.disabled = true;
    status.className = 'comment-status saving';
    status.textContent = 'Sending…';

    try {
      const formData = new FormData();
      formData.append('id', postId);
      formData.append('comment', text);
      formData.append('actor', 'client');
      const res  = await fetch('status.php', { method: 'POST', body: formData });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      showToast('✓ Message sent');
      // Reload so the thread above the input picks up the new bubble
      setTimeout(() => window.location.reload(), 300);
    } catch (err) {
      status.className = 'comment-status error';
      status.textContent = 'Send failed — try again';
      saveBtn.disabled = false;
    }
  });

  // ---- Inline editable date --------------------------------------
  function formatDateLabel(iso) {
    // iso looks like "2026-04-14T09:30"
    const d = new Date(iso);
    if (isNaN(d)) return iso;
    const opts = { month: 'long', day: 'numeric', year: 'numeric',
                   hour: 'numeric', minute: '2-digit', hour12: true };
    // Build a "Month D, YYYY at H:MM AM/PM" string
    const parts = new Intl.DateTimeFormat('en-US', opts).formatToParts(d);
    const get = t => (parts.find(p => p.type === t) || {}).value || '';
    return `${get('month')} ${get('day')}, ${get('year')} at `
         + `${get('hour')}:${get('minute')} ${get('dayPeriod')}`;
  }

  document.getElementById('feed').addEventListener('click', (e) => {
    const display = e.target.closest('[data-date-display]');
    if (!display || display.dataset.editing === '1') return;

    display.dataset.editing = '1';
    const iso = display.getAttribute('data-iso');
    const post = display.closest('.post');
    const postId = post.getAttribute('data-id');

    const input = document.createElement('input');
    input.type = 'datetime-local';
    input.className = 'date-input';
    input.value = iso;

    display.replaceWith(input);
    input.focus();
    input.showPicker?.();

    let saving = false;
    const finish = async (commit) => {
      if (saving) return;
      saving = true;

      const newIso = input.value;
      const restore = () => {
        const span = document.createElement('span');
        span.className = 'date-display';
        span.setAttribute('data-date-display', '');
        span.setAttribute('data-iso', commit && newIso ? newIso : iso);
        span.setAttribute('title', 'Click to edit');
        span.textContent = formatDateLabel(commit && newIso ? newIso : iso);
        input.replaceWith(span);
      };

      if (!commit || !newIso || newIso === iso) {
        restore();
        return;
      }

      try {
        const formData = new FormData();
        formData.append('id', postId);
        formData.append('scheduled_date', newIso.replace('T', ' ') + ':00');
        formData.append('actor', 'client');
        const res  = await fetch('status.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        restore();
        showToast('✓ Date updated');
      } catch (err) {
        // Roll back to original if save failed
        input.value = iso;
        restore();
        showToast('Date save failed');
      }
    };

    input.addEventListener('blur',   () => finish(true));
    input.addEventListener('change', () => finish(true));
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter')  { ev.preventDefault(); finish(true); }
      if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
    });
  });

  // ---- Inline editable caption -----------------------------------
  document.getElementById('feed').addEventListener('click', (e) => {
    const display = e.target.closest('[data-caption-display]');
    if (!display || display.dataset.editing === '1') return;
    display.dataset.editing = '1';

    const raw = display.getAttribute('data-raw');
    const post = display.closest('.post');
    const postId = post.getAttribute('data-id');

    const wrap = document.createElement('div');
    const ta = document.createElement('textarea');
    ta.className = 'post-caption-edit';
    ta.value = raw;
    wrap.appendChild(ta);

    const actions = document.createElement('div');
    actions.className = 'post-caption-actions';
    const saveBtn = document.createElement('button');
    saveBtn.textContent = 'Save';
    saveBtn.className = 'primary';
    saveBtn.type = 'button';
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Cancel';
    cancelBtn.type = 'button';
    actions.appendChild(saveBtn);
    actions.appendChild(cancelBtn);
    wrap.appendChild(actions);

    display.replaceWith(wrap);
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);

    const restore = (newRaw) => {
      const div = document.createElement('div');
      div.className = 'post-caption';
      div.setAttribute('data-caption-display', '');
      div.setAttribute('data-raw', newRaw);
      div.setAttribute('title', 'Click to edit');
      div.setAttribute('role', 'textbox');
      div.setAttribute('tabindex', '0');
      div.textContent = newRaw;
      wrap.replaceWith(div);
    };

    const cancel = () => restore(raw);

    const save = async () => {
      const newVal = ta.value;
      if (newVal === raw) { cancel(); return; }
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      try {
        const fd = new FormData();
        fd.append('id', postId);
        fd.append('caption', newVal);
        fd.append('actor', 'client');
        const res = await fetch('status.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        restore(newVal);
        showToast('✓ Caption updated');
      } catch (err) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        showToast('Caption save failed');
      }
    };

    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', cancel);
    ta.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') { ev.preventDefault(); cancel(); }
      if (ev.key === 'Enter' && (ev.metaKey || ev.ctrlKey)) { ev.preventDefault(); save(); }
    });
  });

  // ---- Inline editable hashtags ----------------------------------
  document.getElementById('feed').addEventListener('click', (e) => {
    const display = e.target.closest('[data-hashtags-display]');
    if (!display || display.dataset.editing === '1') return;
    display.dataset.editing = '1';

    const raw = display.getAttribute('data-raw');
    const post = display.closest('.post');
    const postId = post.getAttribute('data-id');

    const wrap = document.createElement('div');
    const ta = document.createElement('textarea');
    ta.className = 'post-caption-edit';
    ta.value = raw;
    ta.placeholder = '#Brand #Campaign #Keyword';
    wrap.appendChild(ta);

    const actions = document.createElement('div');
    actions.className = 'post-caption-actions';
    const saveBtn = document.createElement('button');
    saveBtn.textContent = 'Save';
    saveBtn.className = 'primary';
    saveBtn.type = 'button';
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Cancel';
    cancelBtn.type = 'button';
    actions.appendChild(saveBtn);
    actions.appendChild(cancelBtn);
    wrap.appendChild(actions);

    display.replaceWith(wrap);
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);

    const restore = (newRaw) => {
      const div = document.createElement('div');
      div.className = 'post-hashtags';
      div.setAttribute('data-hashtags-display', '');
      div.setAttribute('data-raw', newRaw);
      div.setAttribute('title', 'Click to edit');
      div.setAttribute('role', 'textbox');
      div.setAttribute('tabindex', '0');
      div.textContent = newRaw;
      wrap.replaceWith(div);
    };

    const cancel = () => restore(raw);

    const save = async () => {
      const newVal = ta.value.trim();
      if (newVal === raw) { cancel(); return; }
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      try {
        const fd = new FormData();
        fd.append('id', postId);
        fd.append('hashtags', newVal);
        fd.append('actor', 'client');
        const res = await fetch('status.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        restore(newVal);
        showToast('✓ Hashtags updated');
      } catch (err) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        showToast('Hashtags save failed');
      }
    };

    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', cancel);
    ta.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') { ev.preventDefault(); cancel(); }
      if (ev.key === 'Enter' && (ev.metaKey || ev.ctrlKey)) { ev.preventDefault(); save(); }
    });
  });

  // ---- Image / video replacement ---------------------------------
  // Hidden file input shared by all replace buttons
  const replaceInput = document.createElement('input');
  replaceInput.type = 'file';
  replaceInput.accept = 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm';
  replaceInput.style.display = 'none';
  document.body.appendChild(replaceInput);

  let pendingReplaceBtn = null;

  document.getElementById('feed').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-replace-img]');
    if (!btn) return;
    pendingReplaceBtn = btn;
    replaceInput.value = '';
    replaceInput.click();
  });

  replaceInput.addEventListener('change', async () => {
    if (!replaceInput.files.length || !pendingReplaceBtn) return;
    const file = replaceInput.files[0];
    if (file.size > 25 * 1024 * 1024) {
      showToast('File exceeds 25 MB');
      return;
    }

    const mediaItem = pendingReplaceBtn.closest('.media-item');
    const imageId   = mediaItem.getAttribute('data-image-id');
    const oldEl     = mediaItem.querySelector('[data-image-el]');
    const saveBtn   = mediaItem.querySelector('.save-img-btn');

    mediaItem.classList.add('replacing');
    pendingReplaceBtn.disabled = true;
    pendingReplaceBtn.textContent = '⏳ Uploading…';

    try {
      const fd = new FormData();
      fd.append('image_id', imageId);
      fd.append('image', file);
      const res = await fetch('replace-image.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');

      // Swap media — add cache-bust param in case filename reused.
      // If the new file is a video and the old was an image (or vice versa),
      // swap the element type entirely.
      const bust   = data.image_url + (data.image_url.includes('?') ? '&' : '?') + 't=' + Date.now();
      const newType = data.media_type || (oldEl.tagName === 'VIDEO' ? 'video' : 'image');
      const sameType = (newType === 'video' && oldEl.tagName === 'VIDEO')
                    || (newType === 'image' && oldEl.tagName === 'IMG');
      if (sameType) {
        oldEl.src = bust;
      } else {
        const fresh = document.createElement(newType === 'video' ? 'video' : 'img');
        fresh.setAttribute('data-image-el', '');
        fresh.src = bust;
        if (newType === 'video') {
          fresh.controls = true;
          fresh.playsInline = true;
          fresh.preload = 'metadata';
          fresh.style.cssText = 'width:100%;height:100%;object-fit:contain;background:#000';
        } else {
          fresh.loading = 'lazy';
          fresh.alt = '';
        }
        oldEl.replaceWith(fresh);
      }
      if (saveBtn) {
        saveBtn.setAttribute('data-src', data.image_url);
        const newExt = (data.image_url.match(/\.([a-z0-9]+)(\?|$)/i) || [, ''])[1].toLowerCase();
        if (newExt) saveBtn.setAttribute('data-ext', newExt);
      }
      mediaItem.setAttribute('data-media-type', newType);
      showToast(newType === 'video' ? '✓ Video replaced' : '✓ Image replaced');
    } catch (err) {
      showToast('Replace failed: ' + (err.message || 'unknown'));
    } finally {
      mediaItem.classList.remove('replacing');
      pendingReplaceBtn.disabled = false;
      pendingReplaceBtn.textContent = '🔄 Replace';
      pendingReplaceBtn = null;
    }
  });

  // Month dropdown that preserves category + status selection
  const monthSelect = document.getElementById('monthSelect');
  if (monthSelect) {
    monthSelect.addEventListener('change', () => {
      const cats     = monthSelect.dataset.cats;
      const statuses = monthSelect.dataset.statuses;
      const client   = monthSelect.dataset.client;
      const params = new URLSearchParams();
      if (client)   params.set('client', client);
      if (cats)     params.set('cats', cats);
      if (statuses) params.set('status', statuses);
      // Always set the month so 'all' is respected over the default
      if (monthSelect.value) params.set('month', monthSelect.value);
      const qs = params.toString();
      window.location.href = 'feed' + (qs ? '?' + qs : '');
    });
  }

</script>

<!-- Image carousels (multi-image posts) -->
<script>
(function() {
  function initCarousel(carousel) {
    const track = carousel.querySelector('.post-media-track');
    if (!track) return;
    const items   = Array.from(track.querySelectorAll('.media-item'));
    if (items.length < 2) return;
    const dots    = Array.from(carousel.querySelectorAll('[data-carousel-dot]'));
    const prevBtn = carousel.querySelector('[data-carousel-prev]');
    const nextBtn = carousel.querySelector('[data-carousel-next]');
    const counter = carousel.querySelector('[data-carousel-counter]');

    function indexFromScroll() {
      const w = track.clientWidth || 1;
      return Math.max(0, Math.min(items.length - 1, Math.round(track.scrollLeft / w)));
    }
    function update() {
      const idx = indexFromScroll();
      dots.forEach((d, i) => d.classList.toggle('active', i === idx));
      if (counter) counter.textContent = (idx + 1) + '/' + items.length;
      if (prevBtn) prevBtn.disabled = idx <= 0;
      if (nextBtn) nextBtn.disabled = idx >= items.length - 1;
    }
    function goTo(i) {
      i = Math.max(0, Math.min(items.length - 1, i));
      track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' });
    }
    if (prevBtn) prevBtn.addEventListener('click', () => goTo(indexFromScroll() - 1));
    if (nextBtn) nextBtn.addEventListener('click', () => goTo(indexFromScroll() + 1));
    dots.forEach((d, i) => d.addEventListener('click', () => goTo(i)));

    let raf = null;
    track.addEventListener('scroll', () => {
      if (raf) cancelAnimationFrame(raf);
      raf = requestAnimationFrame(update);
    }, { passive: true });
    window.addEventListener('resize', update);
    update();
  }
  document.querySelectorAll('[data-carousel]').forEach(initCarousel);
})();
</script>

<!-- Fullscreen image lightbox -->
<style>
  .lightbox {
    position: fixed; inset: 0; z-index: 2000;
    background: rgba(0,0,0,0.96);
    display: none;
    align-items: center; justify-content: center;
    padding: 20px;
    cursor: zoom-out;
    animation: lb-fade 0.18s ease-out;
  }
  .lightbox.show { display: flex; }
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
  }
  .lightbox-close:hover { background: rgba(255,255,255,0.3); }
  img[data-image-el] { cursor: zoom-in; }
  video[data-image-el] { cursor: default; }
  @keyframes lb-fade { from { opacity: 0; } to { opacity: 1; } }
</style>
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Image viewer">
  <button class="lightbox-close" type="button" aria-label="Close">×</button>
  <img id="lightboxImg" alt="">
</div>
<script>
(function() {
  const lb    = document.getElementById('lightbox');
  const lbImg = document.getElementById('lightboxImg');
  function open(src, alt) {
    lbImg.src = src;
    lbImg.alt = alt || '';
    lb.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    lb.classList.remove('show');
    lbImg.src = '';
    document.body.style.overflow = '';
  }
  // Click an image element to open the lightbox. Videos are skipped —
  // the native <video controls> should handle play/pause.
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-image-el]');
    if (!el || el.tagName !== 'IMG') return;
    e.preventDefault();
    open(el.getAttribute('src'), el.getAttribute('alt'));
  });
  // Click anywhere on backdrop, or the close button, to dismiss
  lb.addEventListener('click', close);
  // Escape closes
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && lb.classList.contains('show')) close();
  });
})();
</script>

</body>
</html>
