<?php
/**
 * Posts — Stage 3 review (spec §4.3). Replaces feed.php.
 *
 *   ?client=kenda                 scope (helpers.php)
 *   &status=pending|approved|scheduled   segment (admin also: denied) — default pending
 *   &month=YYYY-MM|all            default: current month if it has posts, else all (feed.php semantics)
 *   &post=<id>                    open that post's detail on load (segment/month follow the post)
 *   &post=<id>&partial=1          return ONLY the detail partial HTML (for lists > 40 items)
 *
 * Segments (DB strings never change):
 *   To Review = status pending  AND posted = 0
 *   Approved  = status approved AND posted = 0
 *   Scheduled = posted = 1                      (label only; DB value stays `posted`)
 *   Needs changes (admin only) = status denied AND posted = 0
 * Clients never receive denied rows — filtered in SQL (AND p.status <> 'denied').
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/partials/components/comment-thread.php';
require_once __DIR__ . '/partials/components/post-detail.php';

/** Escape helper (page-local by convention; partials use esc()). */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Does the posts.posted column exist yet? (migrate.php may not have run) */
if (!function_exists('hasPostedColumn')) {
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
}

$admin     = isAdmin();
$isPartial = !empty($_GET['partial']);
$postParam = (int)($_GET['post'] ?? 0);

// A client seat must be scoped; only the admin may browse every company at once.
if (!$client && !$admin) {
    http_response_code(404);
    $pageTitle = 'Posts';
    $showTabs  = false;
    include __DIR__ . '/partials/layout-top.php';
    echo '<div class="ui-empty">This page needs a client link. Ask Joust for yours.</div>';
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

$hasPosted   = hasPostedColumn($pdo);
$postedExpr  = $hasPosted ? 'p.posted' : '0';
$postedSel   = $hasPosted ? 'p.posted,' : '0 AS posted,';
$typeSel     = hasPostTypeColumn($pdo) ? 'p.post_type,' : "'post' AS post_type,";
$nameSel     = hasPostsNameColumn($pdo) ? 'p.name,' : "'' AS name,";
$hasUpdated  = $pdo->query("SHOW COLUMNS FROM posts LIKE 'updated_at'")->rowCount() > 0;
$updatedSel  = $hasUpdated ? 'p.updated_at,' : 'NULL AS updated_at,';
$hasMedia    = hasMediaTypeColumn($pdo);
$hasLog      = hasActivityLog($pdo);

$selectSql = "
    SELECT p.id, p.company_id, p.caption, p.hashtags, p.scheduled_date, p.status,
           $postedSel $typeSel $nameSel $updatedSel
           c.name AS company_name, c.logo_url AS company_logo, c.slug AS company_slug
    FROM posts p
    INNER JOIN companies c ON c.id = p.company_id
";

// Base scope shared by every query: client + role.
$scopeWhere  = [];
$scopeParams = [];
if ($client) {
    $scopeWhere[]  = 'p.company_id = ?';
    $scopeParams[] = (int)$client['id'];
}
if (!$admin) {
    $scopeWhere[] = "p.status <> 'denied'";     // clients never see denied work (SQL, not CSS)
}

/** Load images + comments + approved_at for a set of post rows (3 queries total). */
function postsAttachRelations(PDO $pdo, array &$posts, bool $hasMedia, bool $hasLog): void {
    if (!$posts) return;
    $ids = array_map('intval', array_column($posts, 'id'));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $byId = [];
    foreach ($posts as &$p) {
        $p['images'] = []; $p['comments'] = []; $p['approved_at'] = null;
        $byId[(int)$p['id']] = &$p;
    }
    unset($p);

    $mediaCol = $hasMedia ? ', media_type' : '';
    $st = $pdo->prepare("SELECT id, post_id, image_url{$mediaCol} FROM post_images WHERE post_id IN ($ph) ORDER BY post_id, sort_order ASC, id ASC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
        $pid = (int)$row['post_id'];
        if (!isset($byId[$pid])) continue;
        $byId[$pid]['images'][] = [
            'id'   => (int)$row['id'],
            'url'  => (string)$row['image_url'],
            'type' => $row['media_type'] ?? mediaTypeFromUrl((string)$row['image_url']),
        ];
    }

    if ($hasLog) {
        $st = $pdo->prepare("
            SELECT entity_id, actor, detail, created_at FROM activity_log
            WHERE entity_type = 'post' AND action = 'commented' AND entity_id IN ($ph)
              AND detail IS NOT NULL AND detail <> ''
            ORDER BY created_at ASC, id ASC
        ");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) {
            $pid = (int)$row['entity_id'];
            if (isset($byId[$pid])) $byId[$pid]['comments'][] = $row;
        }
        $st = $pdo->prepare("
            SELECT entity_id, MAX(created_at) AS at FROM activity_log
            WHERE entity_type = 'post' AND action = 'approved' AND entity_id IN ($ph)
            GROUP BY entity_id
        ");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) {
            $pid = (int)$row['entity_id'];
            if (isset($byId[$pid])) $byId[$pid]['approved_at'] = $row['at'];
        }
    }
}

// ---------------------------------------------------------------------
// Direct post (deep link or partial): fetch it first so the list can
// follow its segment/month, and so partial=1 never renders the page.
// ---------------------------------------------------------------------
$directPost = null;
if ($postParam > 0) {
    $w = array_merge(['p.id = ?'], $scopeWhere);
    $st = $pdo->prepare($selectSql . ' WHERE ' . implode(' AND ', $w) . ' LIMIT 1');
    $st->execute(array_merge([$postParam], $scopeParams));
    $directPost = $st->fetch() ?: null;
}

if ($isPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    if (!$directPost) {
        http_response_code(404);
        echo '<div class="ui-empty">This post is no longer available.</div>';
        exit;
    }
    $one = [$directPost];
    postsAttachRelations($pdo, $one, $hasMedia, $hasLog);
    echo renderPostDetail($one[0], ['admin' => $admin, 'hasPosted' => $hasPosted]);
    exit;
}

// ---------------------------------------------------------------------
// Months with posts (scoped + role-filtered), month param semantics
// ---------------------------------------------------------------------
$monthSql = "SELECT DISTINCT DATE_FORMAT(p.scheduled_date, '%Y-%m') AS ym FROM posts p"
          . ($scopeWhere ? ' WHERE ' . implode(' AND ', $scopeWhere) : '') . ' ORDER BY ym ASC';
$st = $pdo->prepare($monthSql);
$st->execute($scopeParams);
$availableMonths = array_values(array_filter(array_column($st->fetchAll(), 'ym')));

$monthParam = isset($_GET['month']) ? (string)$_GET['month'] : null;
if ($directPost && !empty($directPost['scheduled_date'])) {
    $monthParam = date('Y-m', strtotime($directPost['scheduled_date']));
}
if ($monthParam === 'all') {
    $selectedMonth = '';
} elseif ($monthParam === null) {
    $current = date('Y-m');
    $selectedMonth = in_array($current, $availableMonths, true) ? $current : '';
} else {
    $selectedMonth = preg_match('/^\d{4}-\d{2}$/', $monthParam) ? $monthParam : '';
}

// ---------------------------------------------------------------------
// Segment
// ---------------------------------------------------------------------
$segments = ['pending' => 'To Review', 'approved' => 'Approved', 'scheduled' => 'Scheduled'];
if ($admin) { $segments['denied'] = 'Needs changes'; }

$segment = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if ($directPost) {
    $segment = !empty($directPost['posted']) ? 'scheduled' : (string)$directPost['status'];
}
if (!isset($segments[$segment])) { $segment = 'pending'; }

$segmentWhere = [
    'pending'   => "p.status = 'pending' AND $postedExpr = 0",
    'approved'  => "p.status = 'approved' AND $postedExpr = 0",
    'scheduled' => "$postedExpr = 1",
    'denied'    => "p.status = 'denied' AND $postedExpr = 0",
];

// ---------------------------------------------------------------------
// Counts per segment (client + month + role; ignores the segment itself)
// ---------------------------------------------------------------------
$viewWhere  = $scopeWhere;
$viewParams = $scopeParams;
if ($selectedMonth !== '') {
    $viewWhere[]  = "DATE_FORMAT(p.scheduled_date, '%Y-%m') = ?";
    $viewParams[] = $selectedMonth;
}
$counts = ['pending' => 0, 'approved' => 0, 'scheduled' => 0, 'denied' => 0];
$st = $pdo->prepare("SELECT p.status, ($postedExpr) AS posted, COUNT(*) AS n FROM posts p"
    . ($viewWhere ? ' WHERE ' . implode(' AND ', $viewWhere) : '') . " GROUP BY p.status, ($postedExpr)");
$st->execute($viewParams);
foreach ($st->fetchAll() as $row) {
    $n = (int)$row['n'];
    if (!empty($row['posted'])) { $counts['scheduled'] += $n; }
    elseif (isset($counts[$row['status']])) { $counts[$row['status']] += $n; }
}

// ---------------------------------------------------------------------
// The list
// ---------------------------------------------------------------------
$listWhere  = $viewWhere;
$listWhere[] = $segmentWhere[$segment];
$st = $pdo->prepare($selectSql . ' WHERE ' . implode(' AND ', $listWhere) . ' ORDER BY p.scheduled_date ASC, p.id ASC');
$st->execute($viewParams);
$posts = $st->fetchAll();
postsAttachRelations($pdo, $posts, $hasMedia, $hasLog);

$inList = false;
foreach ($posts as $p) { if ((int)$p['id'] === $postParam) { $inList = true; break; } }
if ($directPost && !$inList) {
    // Should not happen (segment/month follow the post) but keep the deep link working.
    $one = [$directPost];
    postsAttachRelations($pdo, $one, $hasMedia, $hasLog);
    $directPost = $one[0];
} else {
    $directPost = null;
}

// Inline every detail when the list is small; otherwise posts.js fetches partials.
$inlineLimit   = 40;
$inlineDetails = count($posts) <= $inlineLimit;

// ---------------------------------------------------------------------
// URL helpers
// ---------------------------------------------------------------------
$monthUrlParam = $monthParam === null ? null : ($selectedMonth !== '' ? $selectedMonth : 'all');
function postsUrl(array $extra = []) {
    return clientUrl('posts.php', $extra);
}
$segmentUrl = function (string $seg) use ($monthUrlParam) {
    return postsUrl(['status' => $seg, 'month' => $monthUrlParam]);
};
$monthUrl = function (string $ym) use ($segment) {
    return postsUrl(['status' => $segment, 'month' => $ym]);
};

$monthLabel = $selectedMonth !== '' ? date('M Y', strtotime($selectedMonth . '-01')) : 'All months';
$monthIdx   = $selectedMonth !== '' ? array_search($selectedMonth, $availableMonths, true) : false;
$prevMonth  = ($monthIdx !== false && $monthIdx > 0) ? $availableMonths[$monthIdx - 1] : null;
$nextMonth  = ($monthIdx !== false && $monthIdx < count($availableMonths) - 1) ? $availableMonths[$monthIdx + 1] : null;

$segItems = [];
foreach ($segments as $key => $label) {
    $segItems[] = [
        'label'  => $label,
        'href'   => $segmentUrl($key),
        'active' => $key === $segment,
        'count'  => $counts[$key],
        'value'  => $key,
        'attrs'  => ['data-segment' => $key],
    ];
}

$emptyCopy = [
    'pending'   => 'Nothing to review' . ($selectedMonth !== '' ? ' in ' . date('F', strtotime($selectedMonth . '-01')) : '') . '.',
    'approved'  => 'No approved posts waiting to be scheduled.',
    'scheduled' => $hasPosted ? 'Nothing scheduled yet.' : 'Scheduling is not enabled yet.',
    'denied'    => 'Nothing needs changes.',
];

// ---------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------
$pageTitle   = 'Posts';
$activeTab   = 'posts';
$navTrailing = '<button type="button" class="ui-btn ui-btn--tinted ui-btn--sm posts-month-pill" data-sheet-open="#postMonthSheet" aria-haspopup="dialog">'
             . h($monthLabel) . icon('chevron-down', 'posts-month-chevron') . '</button>'
             . (!empty($client) ? clientAvatar($client) : '');
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">';
$bodyClass   = 'page-posts';

$postsConfig = [
    'base'        => basePath(),
    'endpoint'    => basePath() . '/status.php',
    'replace'     => basePath() . '/replace-image.php',
    'partialUrl'  => postsUrl(['post' => '__ID__', 'partial' => 1]),
    'segment'     => $segment,
    'counts'      => $counts,
    'inline'      => $inlineDetails,
    'admin'       => $admin,
    'hasPosted'   => $hasPosted,
    'openPost'    => $postParam > 0 ? $postParam : 0,
    'segmentUrls' => array_combine(array_keys($segments), array_map($segmentUrl, array_keys($segments))),
];
$footExtra = '<script>window.PostsConfig = ' . json_encode($postsConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
           . '<script src="' . h(staticUrl('js/posts.js')) . '" defer></script>';

include __DIR__ . '/partials/layout-top.php';
?>

<div class="posts-toolbar">
  <?= segmented($segItems, ['label' => 'Post status']) ?>
</div>

<?php if (!$posts): ?>
  <div class="ui-empty posts-empty" data-posts-empty>
    <?= h($emptyCopy[$segment]) ?>
    <?php if ($segment === 'pending' && $counts['approved'] + $counts['scheduled'] > 0): ?>
      <div class="posts-empty-sub">You're caught up.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<section class="ui-list-group posts-group" data-posts-list data-segment="<?= h($segment) ?>"<?= !$posts ? ' hidden' : '' ?>>
  <h2 class="ui-list-header">
    <?= h($monthLabel) ?> · <span data-segment-count><?= (int)$counts[$segment] ?></span> <?= h(strtolower($segments[$segment])) ?>
  </h2>
  <ul class="ui-list posts-list" role="list" data-posts-items>
    <?php foreach ($posts as $post):
        $pid      = (int)$post['id'];
        $posted   = !empty($post['posted']);
        $first    = $post['images'][0] ?? null;
        $isVid    = $first ? pdIsVideo($first) : false;
        $nImg     = count($post['images']);
        $nCmt     = count($post['comments']);
        $caption  = trim((string)$post['caption']);
        $firstLn  = trim(preg_split('/\r\n|\r|\n/', $caption)[0] ?? '');
        $name     = trim((string)($post['name'] ?? ''));
        $title    = $name !== '' ? $name : ($firstLn !== '' ? $firstLn : 'Post #' . $pid);
        $subtitle = $name !== '' ? $firstLn : trim((string)$post['hashtags']);
        if (!$client) { $subtitle = $post['company_name'] . ($subtitle !== '' ? ' — ' . $subtitle : ''); }
        $ts       = strtotime((string)$post['scheduled_date']);
        $dateLbl  = $ts ? date('M j', $ts) : '';
        $href     = postsUrl(['status' => $segment, 'month' => $monthUrlParam, 'post' => $pid]);
    ?>
      <li class="pl-item" id="post-<?= $pid ?>" data-post-item="<?= $pid ?>" data-id="<?= $pid ?>"
          data-status="<?= h($post['status']) ?>" data-posted="<?= $posted ? '1' : '0' ?>"
          data-title="<?= h($title) ?>" data-swipe>
        <div class="pl-swipe pl-swipe--approve" aria-hidden="true"><?= icon('checkmark') ?><span>Approve</span></div>
        <div class="pl-swipe pl-swipe--deny" aria-hidden="true"><?= icon('xmark') ?><span>Deny</span></div>
        <a class="ui-row ui-row--leading pl-card" href="<?= h($href) ?>" data-post-open="<?= $pid ?>">
          <div class="ui-row-leading pl-thumb<?= $isVid ? ' pl-thumb--video' : '' ?>">
            <?php if ($first && !$isVid): ?>
              <img src="<?= h(pdMediaUrl($first['url'])) ?>" alt="" loading="lazy" decoding="async">
            <?php elseif ($first): ?>
              <?= icon('play', 'pl-thumb-play') ?>
            <?php else: ?>
              <?= icon('photo') ?>
            <?php endif; ?>
          </div>
          <div class="ui-row-body">
            <div class="pl-top">
              <div class="ui-row-title pl-title"><?= h($title) ?></div>
              <time class="pl-date" datetime="<?= h($ts ? date('Y-m-d\TH:i', $ts) : '') ?>"><?= h($dateLbl) ?></time>
            </div>
            <?php if ($subtitle !== ''): ?>
              <div class="pl-caption"><?= h($subtitle) ?></div>
            <?php endif; ?>
            <div class="pl-meta">
              <?= statusPill($post['status'], $posted) ?>
              <span class="pl-meta-sep">·</span>
              <span><?= $nImg ?> <?= $nImg === 1 ? ($isVid ? 'video' : 'image') : 'media' ?></span>
              <span class="pl-meta-sep">·</span>
              <span data-comment-count-for="<?= $pid ?>"><?= $nCmt ?> <?= $nCmt === 1 ? 'comment' : 'comments' ?></span>
            </div>
          </div>
          <?= icon('chevron-right', 'ui-row-chevron') ?>
        </a>
        <?php if ($inlineDetails): ?>
          <template data-post-template="<?= $pid ?>"><?= renderPostDetail($post, ['admin' => $admin, 'hasPosted' => $hasPosted]) ?></template>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="ui-list-footer posts-hint">Swipe right to approve, left to deny. Tap a post for the full preview.</p>
</section>

<?php if ($directPost): ?>
  <template data-post-template="<?= (int)$directPost['id'] ?>"><?= renderPostDetail($directPost, ['admin' => $admin, 'hasPosted' => $hasPosted]) ?></template>
<?php endif; ?>

<?php
// ---- Month picker sheet ---------------------------------------------
ob_start();
?>
  <div class="posts-month-nav">
    <?php if ($prevMonth): ?>
      <a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h($monthUrl($prevMonth)) ?>"><?= icon('chevron-right', 'posts-chevron-left') ?> <?= h(date('M Y', strtotime($prevMonth . '-01'))) ?></a>
    <?php else: ?><span></span><?php endif; ?>
    <?php if ($nextMonth): ?>
      <a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h($monthUrl($nextMonth)) ?>"><?= h(date('M Y', strtotime($nextMonth . '-01'))) ?> <?= icon('chevron-right') ?></a>
    <?php else: ?><span></span><?php endif; ?>
  </div>
  <ul class="ui-list posts-month-list" role="list">
    <?php foreach (array_reverse($availableMonths) as $ym):
        $on = $ym === $selectedMonth; ?>
      <li><a class="ui-row<?= $on ? ' is-active' : '' ?>" href="<?= h($monthUrl($ym)) ?>"<?= $on ? ' aria-current="true"' : '' ?>>
        <span class="ui-row-body"><span class="ui-row-title"><?= h(date('F Y', strtotime($ym . '-01'))) ?></span></span>
        <?php if ($on): ?><span class="ui-row-trailing text-accent"><?= icon('checkmark') ?></span><?php endif; ?>
      </a></li>
    <?php endforeach; ?>
    <li><a class="ui-row<?= $selectedMonth === '' ? ' is-active' : '' ?>" href="<?= h($monthUrl('all')) ?>">
      <span class="ui-row-body"><span class="ui-row-title">All months</span></span>
      <?php if ($selectedMonth === ''): ?><span class="ui-row-trailing text-accent"><?= icon('checkmark') ?></span><?php endif; ?>
    </a></li>
    <?php if (!$availableMonths): ?>
      <li><div class="ui-row"><span class="ui-row-body text-secondary">No posts yet.</span></div></li>
    <?php endif; ?>
  </ul>
<?php
$sheetId    = 'postMonthSheet';
$sheetTitle = 'Month';
$sheetBody  = ob_get_clean();
include __DIR__ . '/partials/sheet.php';

include __DIR__ . '/partials/layout-bottom.php';
