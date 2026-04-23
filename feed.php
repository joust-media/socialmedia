<?php
/**
 * Social Media Approval Feed — public view.
 * Optional ?client=hmf scopes the view to one company.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

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

$postsStmt = $pdo->prepare("
    SELECT p.id, p.caption, p.hashtags, p.scheduled_date, p.status, p.client_comment,
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
    $imgStmt = $pdo->prepare("
        SELECT id, post_id, image_url
        FROM post_images
        WHERE post_id IN ($placeholders)
        ORDER BY post_id, sort_order ASC
    ");
    $imgStmt->execute($postIds);
    $imagesByPost = [];
    foreach ($imgStmt->fetchAll() as $row) {
        $imagesByPost[$row['post_id']][] = [
            'id'  => (int)$row['id'],
            'url' => $row['image_url'],
        ];
    }
    foreach ($posts as &$p) {
        $p['images'] = $imagesByPost[$p['id']] ?? [];
    }
    unset($p);
}

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
    return 'feed.php' . ($qs ? '?' . http_build_query($qs) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Social Approval Feed</title>
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
    --toast-bg: #050505;
    --toast-text: #ffffff;
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
    max-width: 680px; margin: 0 auto;
    padding: 12px 16px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
  }
  .brand {
    display: flex; align-items: center; gap: 10px;
    font-weight: 700; font-size: 20px;
    color: var(--accent); letter-spacing: -0.5px;
  }
  .brand-mark {
    height: 32px; width: auto;
    display: block;
    object-fit: contain;
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
  .top-actions { display: flex; align-items: center; gap: 8px; }
  .nav-link {
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 14px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.15s;
  }
  .nav-link:hover { background: var(--border); }

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
  .status-pill.pending  { background: #fef3c7; color: #92400e; border-color: #fde68a; }
  .status-pill.approved { background: #dcfce7; color: #166534; border-color: #86efac; }
  .status-pill.denied   { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
  [data-theme="dark"] .status-pill.pending  { background: #78350f; color: #fde68a; border-color: #a16207; }
  [data-theme="dark"] .status-pill.approved { background: #14532d; color: #bbf7d0; border-color: #166534; }
  [data-theme="dark"] .status-pill.denied   { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }

  .status-pill-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 6px;
    padding: 0 7px;
    min-width: 20px;
    height: 18px;
    border-radius: 9px;
    background: rgba(0,0,0,0.12);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0;
  }
  .status-pill.active .status-pill-count {
    background: rgba(0,0,0,0.18);
  }
  [data-theme="dark"] .status-pill-count { background: rgba(255,255,255,0.15); }
  [data-theme="dark"] .status-pill.active .status-pill-count { background: rgba(255,255,255,0.22); }

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
  .post-status.pending  { background: #fef3c7; color: #92400e; }
  .post-status.approved { background: #dcfce7; color: #166534; }
  .post-status.denied   { background: #fee2e2; color: #991b1b; }
  [data-theme="dark"] .post-status.pending  { background: #78350f; color: #fde68a; }
  [data-theme="dark"] .post-status.approved { background: #14532d; color: #bbf7d0; }
  [data-theme="dark"] .post-status.denied   { background: #7f1d1d; color: #fecaca; }

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
    background: #fee2e2; color: #991b1b; border-color: #fca5a5;
  }
  [data-theme="dark"] .delete-post-btn:hover {
    background: #7f1d1d; color: #fecaca; border-color: #991b1b;
  }
  .delete-post-btn:disabled { opacity: 0.5; cursor: wait; }

  .post-media {
    display: flex;
    flex-direction: column;
    gap: 2px;
    background: var(--border);
  }
  .media-item {
    position: relative;
    background: var(--surface-2);
    overflow: hidden;
    width: 100%;
  }
  .media-item img {
    width: 100%;
    height: auto;
    display: block;
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

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <span><?= $client ? 'Joust — ' . h($client['name']) : 'Joust' ?></span>
    </div>
    <div class="top-actions">
      <a class="nav-link" href="<?= h(clientUrl('index.php')) ?>">Home</a>
      <a class="nav-link" href="<?= h(clientUrl('tires.php')) ?>"><?= $client ? h($client['feature_label']) : 'Tires' ?></a>
      <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
        <span id="themeIcon">🌙</span>
        <span id="themeLabel">Dark</span>
      </button>
    </div>
  </div>
</header>

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
      $count = min(count($post['images']), 5);
      $fullText = $post['caption'] . "\n\n" . $post['hashtags'];
  ?>
    <article class="post" data-id="<?= (int)$post['id'] ?>" data-status="<?= h($post['status']) ?>">
      <div class="post-header">
        <img class="post-logo"
             src="<?= h($post['company_logo']) ?>"
             alt="<?= h($post['company_name']) ?> logo">
        <div class="post-meta">
          <div class="post-name"><?= h($post['company_name']) ?></div>
          <div class="post-date">
            <span class="date-display"
                  data-date-display
                  data-iso="<?= h(date('Y-m-d\TH:i', strtotime($post['scheduled_date']))) ?>"
                  title="Click to edit"><?= h(formatDate($post['scheduled_date'])) ?></span>
          </div>
        </div>
        <div class="post-status <?= h($post['status']) ?>" data-status-pill><?= h(ucfirst($post['status'])) ?></div>
      </div>

      <div class="post-body">
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
      </div>

      <div class="post-actions-top">
        <button class="copy-btn" data-copy-post type="button">
          📋 Copy caption &amp; hashtags
        </button>
        <button class="delete-post-btn" data-delete-post type="button">
          🗑 Delete
        </button>
      </div>

      <?php if ($count > 0): ?>
        <div class="post-media count-<?= $count ?>">
          <?php foreach (array_slice($post['images'], 0, 5) as $i => $img):
              $src      = $img['url'];
              $imgId    = $img['id'];
              $filename = preg_replace('/[^a-zA-Z0-9\-]/', '-', $post['company_name'])
                          . '-' . $post['id'] . '-' . ($i + 1);
          ?>
            <div class="media-item" data-image-id="<?= (int)$imgId ?>">
              <img src="<?= h($src) ?>" alt="Post image <?= $i + 1 ?>" loading="lazy" data-image-el>
              <button class="save-img-btn"
                      data-src="<?= h($src) ?>"
                      data-filename="<?= h($filename) ?>">
                ⬇ Save
              </button>
              <button class="replace-img-btn" data-replace-img type="button" title="Replace this image">
                🔄 Replace
              </button>
            </div>
          <?php endforeach; ?>
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
          Comments / feedback
        </label>
        <textarea class="comment-textarea"
                  id="comment-<?= (int)$post['id'] ?>"
                  data-comment-input
                  placeholder="Leave a note for the team — required if denying, optional otherwise."
                  maxlength="2000"><?= h($post['client_comment'] ?? '') ?></textarea>
        <div class="comment-meta">
          <span class="comment-status" data-comment-status></span>
          <button class="comment-save-btn" data-comment-save type="button" disabled>
            Save comment
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
      const capEl = post.querySelector('[data-caption-display]');
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
      const src = saveBtn.getAttribute('data-src');
      const filename = saveBtn.getAttribute('data-filename') + '.jpg';
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
        showToast('✓ Image saved');
      } catch {
        window.open(src, '_blank');
        showToast('Opened in new tab');
      }
    }
  });

  // ---- Comments --------------------------------------------------
  // Track each textarea's "saved" value so we know when it's dirty
  document.querySelectorAll('[data-comment-input]').forEach(ta => {
    ta.dataset.savedValue = ta.value;

    ta.addEventListener('input', () => {
      const post = ta.closest('.post');
      const saveBtn = post.querySelector('[data-comment-save]');
      const status  = post.querySelector('[data-comment-status]');
      const dirty = ta.value !== ta.dataset.savedValue;
      saveBtn.disabled = !dirty;
      status.className = 'comment-status' + (dirty ? ' dirty' : '');
      status.textContent = dirty ? 'Unsaved changes' : '';
    });
  });

  document.getElementById('feed').addEventListener('click', async (e) => {
    const saveBtn = e.target.closest('[data-comment-save]');
    if (!saveBtn) return;

    const post   = saveBtn.closest('.post');
    const postId = post.getAttribute('data-id');
    const ta     = post.querySelector('[data-comment-input]');
    const status = post.querySelector('[data-comment-status]');

    saveBtn.disabled = true;
    status.className = 'comment-status saving';
    status.textContent = 'Saving…';

    try {
      const formData = new FormData();
      formData.append('id', postId);
      formData.append('comment', ta.value);
      const res  = await fetch('status.php', { method: 'POST', body: formData });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');

      ta.dataset.savedValue = ta.value;
      status.className = 'comment-status saved';
      status.textContent = '✓ Saved';
      showToast('✓ Comment saved');

      // Clear the "saved" message after a couple seconds
      setTimeout(() => {
        if (ta.value === ta.dataset.savedValue) {
          status.textContent = '';
          status.className = 'comment-status';
        }
      }, 2500);
    } catch (err) {
      status.className = 'comment-status error';
      status.textContent = 'Save failed — try again';
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

  // ---- Image replacement -----------------------------------------
  // Hidden file input shared by all replace buttons
  const replaceInput = document.createElement('input');
  replaceInput.type = 'file';
  replaceInput.accept = 'image/jpeg,image/png,image/gif,image/webp';
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
    if (file.size > 10 * 1024 * 1024) {
      showToast('Image exceeds 10 MB');
      return;
    }

    const mediaItem = pendingReplaceBtn.closest('.media-item');
    const imageId   = mediaItem.getAttribute('data-image-id');
    const imgEl     = mediaItem.querySelector('[data-image-el]');
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

      // Swap image — add cache-bust param in case filename reused
      const bust = data.image_url + (data.image_url.includes('?') ? '&' : '?') + 't=' + Date.now();
      imgEl.src = bust;
      if (saveBtn) { saveBtn.setAttribute('data-src', data.image_url); }
      showToast('✓ Image replaced');
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
      window.location.href = 'feed.php' + (qs ? '?' + qs : '');
    });
  }

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
