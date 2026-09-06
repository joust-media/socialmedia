<?php
/**
 * Legacy route — feed.php is now posts.php (spec §3: old filenames 301 to
 * their new homes so nothing bookmarked breaks).
 *
 * Maps the old parameters onto the new ones:
 *   client → client (via clientUrl)
 *   month  → month  (YYYY-MM or 'all', unchanged)
 *   status → status (first of the old comma list; 'denied' only survives for admins)
 *   #post-N anchors can't be seen server-side; ?post=N is passed through when present.
 *
 * The pre-redesign page still exists at legacy/feed.php until parity is verified.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$extra = [];

$month = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
if ($month === 'all' || preg_match('/^\d{4}-\d{2}$/', $month)) {
    $extra['month'] = $month;
}

$status = '';
if (!empty($_GET['status'])) {
    $first  = strtolower(trim(explode(',', (string)$_GET['status'])[0]));
    $status = in_array($first, ['pending', 'approved', 'scheduled', 'denied'], true) ? $first : '';
    if ($status === 'denied' && !isAdmin()) { $status = ''; }
}
if ($status !== '') { $extra['status'] = $status; }

if (!empty($_GET['post']) && (int)$_GET['post'] > 0) {
    $extra['post'] = (int)$_GET['post'];
}

header('Location: ' . clientUrl('posts.php', $extra), true, 301);
exit;
