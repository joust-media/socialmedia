<?php
/**
 * Home — "Today" (spec §4.1).
 *
 *   ?client=kenda   → the client's Today screen:
 *                     1. Needs your attention — up to three stacked action cards
 *                        (pending posts / Library images / collections with new renders),
 *                        or one quiet "all caught up" card.
 *                     2. Coming up — the next three approved or scheduled posts.
 *                     3. Activity — humanized, run-collapsed, never a filename.
 *                     Admin additionally sees "Needs changes" (denied posts / assets
 *                     waiting on Joust, with the latest client notes, linking to the
 *                     posts.php work queue) and a Studio quick-action row. Role is
 *                     enforced with isAdmin().
 *   (no client)     → a client chooser; admin also sees cross-client activity.
 *
 * No database changes. Counts use the same queries as the tab-bar badges
 * (partials/tabbar.php) so the numbers always agree.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/partials/components/action-card.php';
require_once __DIR__ . '/partials/components/activity-feed.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// hasPostedColumn() (posts.posted is migration-gated) lives in helpers.php.

/** First line of a caption, clipped, for the Coming up cards. */
function homeFirstLine($s, $max = 90) {
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = preg_split('/\r\n|\r|\n/', $s)[0];
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if (mb_strlen($s) > $max) $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
    return $s;
}

$isAdmin    = isAdmin();
$viewerRole = $isAdmin ? 'admin' : 'client';
$hasLog     = hasActivityLog($pdo);
$hasLib     = hasLibraryImagesTable($pdo);

// =====================================================================
// Unscoped — the admin chooses a client (and gets the cross-client feed).
// A client seat never sees the client list (slug = tenant key): same
// "missing client" state assets.php uses, HTTP 400.
// =====================================================================
if (!$client && !$isAdmin) {
    http_response_code(400);
    $pageTitle    = 'Joust';
    $htmlTitle    = 'Joust Media — Client portal';
    $navTrailing  = '';
    $showTabs     = false;
    $includeSheet = false;
    $activeTab    = 'home';
    include __DIR__ . '/partials/layout-top.php';
    echo '<div class="ui-empty">This link is missing its client. Please use the review link Joust sent you.</div>';
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}
if (!$client) {
    $companies = [];
    try {
        $companies = $pdo->query("SELECT id, name, slug, logo_url FROM companies ORDER BY name")->fetchAll();
    } catch (Throwable $e) {
        error_log('index chooser query failed: ' . $e->getMessage());
    }
    $allRows = [];
    if ($isAdmin && $hasLog) {
        $allRows = collapseActivityRuns(humanizeActivityRows(recentActivity($pdo, null, 40), $viewerRole, null));
    }

    $pageTitle  = $isAdmin ? 'Today' : 'Joust';
    $htmlTitle  = 'Joust Media — Client portal';
    $navSubtitle = $isAdmin ? 'All clients' : '';
    $activeTab  = 'home';
    $headExtra  = '<link rel="stylesheet" href="' . h(staticUrl('css/home.css')) . '">' . "\n";
    include __DIR__ . '/partials/layout-top.php';
    ?>
    <section class="home-section home-chooser">
      <?= insetListOpen('Choose a client') ?>
      <?php foreach ($companies as $co): ?>
        <?= insetRow([
            'href'    => clientUrl('index.php', ['client' => $co['slug']]),
            'leading' => clientAvatar($co, 'ui-avatar--lg'),
            'title'   => $co['name'],
            'subtitle'=> $isAdmin ? 'Open Today for ' . $co['name'] : 'Open portal',
            'chevron' => true,
        ]) ?>
      <?php endforeach; ?>
      <?php if (!$companies): ?>
        <li><div class="ui-empty">No clients yet.</div></li>
      <?php endif; ?>
      <?= insetListClose() ?>
    </section>
    <?php if ($isAdmin && $hasLog): ?>
      <?= activityFeed($allRows, ['header' => 'Activity across clients', 'limit' => 20, 'showCompany' => true]) ?>
    <?php endif; ?>
    <?php
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

// =====================================================================
// Scoped — counts (identical to the tab-bar badge queries)
// =====================================================================
$cid = (int)$client['id'];

$st = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE company_id = ? AND status = 'pending'");
$st->execute([$cid]);
$pendingPosts = (int)$st->fetchColumn();

$st = $pdo->prepare("
    SELECT COUNT(*) AS images, COUNT(DISTINCT t.id) AS collections
      FROM tire_images ti
     INNER JOIN tires t ON t.id = ti.tire_id
     WHERE t.company_id = ? AND ti.status = 'pending'
");
$st->execute([$cid]);
$tireRow = $st->fetch();
$pendingTireImages  = (int)($tireRow['images'] ?? 0);
$pendingCollections = (int)($tireRow['collections'] ?? 0);

$pendingLibrary = 0;
if ($hasLib) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM library_images WHERE company_id = ? AND status = 'pending'");
    $st->execute([$cid]);
    $pendingLibrary = (int)$st->fetchColumn();
}

// ---------------------------------------------------------------------
// Coming up — next 3 approved or scheduled posts from today onwards
// ---------------------------------------------------------------------
$hasPosted = hasPostedColumn($pdo);
$hasName   = hasPostsNameColumn($pdo);
$hasMedia  = hasMediaTypeColumn($pdo);
$postedSel = $hasPosted ? 'p.posted' : '0 AS posted';
$nameSel   = $hasName ? 'p.name' : "'' AS name";
$thumbType = $hasMedia
    ? "(SELECT pi2.media_type FROM post_images pi2 WHERE pi2.post_id = p.id ORDER BY pi2.sort_order ASC, pi2.id ASC LIMIT 1) AS thumb_type"
    : "'image' AS thumb_type";
$readyWhere = $hasPosted ? "(p.status = 'approved' OR p.posted = 1)" : "p.status = 'approved'";
$st = $pdo->prepare("
    SELECT p.id, p.caption, p.scheduled_date, p.status, {$postedSel}, {$nameSel},
           (SELECT pi.image_url FROM post_images pi WHERE pi.post_id = p.id ORDER BY pi.sort_order ASC, pi.id ASC LIMIT 1) AS thumb_url,
           {$thumbType}
      FROM posts p
     WHERE p.company_id = ? AND {$readyWhere} AND p.scheduled_date >= CURDATE()
     ORDER BY p.scheduled_date ASC, p.id ASC
     LIMIT 3
");
$st->execute([$cid]);
$upcoming = array_slice($st->fetchAll(), 0, 3);

// ---------------------------------------------------------------------
// Activity — humanized + run-collapsed (helpers.php)
// ---------------------------------------------------------------------
$activityRows = [];
if ($hasLog) {
    $activityRows = collapseActivityRuns(humanizeActivityRows(recentActivity($pdo, $cid, 40), $viewerRole, $client));
}

// ---------------------------------------------------------------------
// Admin only — "Needs changes": what is waiting on Joust right now.
// Posts = status denied (and not scheduled), same rule as the posts.php
// queue; assets = denied tire / library images (assets.php filter=denied).
// The latest client notes are activity_log 'commented' rows on those
// posts (a deny note is stored as one of these), newest first, one per post.
// ---------------------------------------------------------------------
$needsPosts  = 0;
$needsAssets = ['tire' => 0, 'library' => 0];
$needsNotes  = [];
if ($isAdmin) {
    try {
        $deniedPostWhere = $hasPosted ? "status = 'denied' AND posted = 0" : "status = 'denied'";
        $st = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE company_id = ? AND {$deniedPostWhere}");
        $st->execute([$cid]);
        $needsPosts = (int)$st->fetchColumn();

        $st = $pdo->prepare("
            SELECT COUNT(*) FROM tire_images ti
             INNER JOIN tires t ON t.id = ti.tire_id
             WHERE t.company_id = ? AND ti.status = 'denied'
        ");
        $st->execute([$cid]);
        $needsAssets['tire'] = (int)$st->fetchColumn();

        if ($hasLib) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM library_images WHERE company_id = ? AND status = 'denied'");
            $st->execute([$cid]);
            $needsAssets['library'] = (int)$st->fetchColumn();
        }

        if ($hasLog && $needsPosts > 0) {
            $noteNameSel = $hasName ? 'p.name AS post_name' : "'' AS post_name";
            $pDenied     = $hasPosted ? "p.status = 'denied' AND p.posted = 0" : "p.status = 'denied'";
            $st = $pdo->prepare("
                SELECT c.entity_id, c.detail, c.created_at, p.caption AS post_caption, {$noteNameSel}
                  FROM activity_log c
                 INNER JOIN posts p ON p.id = c.entity_id
                 WHERE c.company_id = ? AND c.entity_type = 'post' AND c.action = 'commented' AND c.actor = 'client'
                   AND c.detail IS NOT NULL AND c.detail <> '' AND {$pDenied}
                 ORDER BY c.created_at DESC, c.id DESC
                 LIMIT 12
            ");
            $st->execute([$cid]);
            $seen = [];
            foreach ($st->fetchAll() as $r) {
                $pid = (int)$r['entity_id'];
                if (isset($seen[$pid])) continue;           // one note per post — the newest
                $seen[$pid] = true;
                $name = trim((string)($r['post_name'] ?? ''));
                if ($name === '' || activityLooksLikeFilename($name)) $name = homeFirstLine($r['post_caption'] ?? '', 60);
                $needsNotes[] = [
                    'text' => trim((string)$r['detail']),
                    'on'   => $name !== '' ? $name : 'Post #' . $pid,
                    'when' => relativeTime($r['created_at']),
                    'href' => clientUrl('posts', ['post' => $pid]),
                ];
                if (count($needsNotes) >= 3) break;
            }
        }
    } catch (Throwable $e) {
        error_log('index needs-changes query failed: ' . $e->getMessage());
        $needsPosts  = 0;
        $needsAssets = ['tire' => 0, 'library' => 0];
        $needsNotes  = [];
    }
}

// =====================================================================
// Render
// =====================================================================
$pageTitle   = $client['name'];
$htmlTitle   = $client['name'] . ' — Today';
$navSubtitle = date('l, F j');
$activeTab   = 'home';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/home.css')) . '">' . "\n";
include __DIR__ . '/partials/layout-top.php';

// --- 1. Needs your attention -------------------------------------------
$cards = [];
if ($pendingPosts > 0) {
    $cards[] = actionCard([
        'count' => $pendingPosts, 'noun' => 'post',
        'one'   => 'is ready for your review', 'many' => 'ready for your review',
        'href'  => clientUrl('posts', ['status' => 'pending']),
        'icon'  => 'grid', 'subtitle' => 'Posts · To Review', 'tone' => 'accent',
        'index' => count($cards),
    ]);
}
if ($pendingLibrary > 0) {
    $cards[] = actionCard([
        'count' => $pendingLibrary, 'noun' => 'image',
        'one'   => 'to approve in Library', 'many' => 'to approve in Library',
        'href'  => clientUrl('assets', ['view' => 'library', 'filter' => 'pending']),
        'icon'  => 'photo', 'subtitle' => 'Assets · Library', 'tone' => 'accent',
        'index' => count($cards),
    ]);
}
if ($pendingCollections > 0) {
    $cards[] = actionCard([
        'count' => $pendingCollections, 'noun' => 'collection',
        'one'   => 'has new renders', 'many' => 'have new renders',
        'href'  => clientUrl('assets', ['view' => 'collections']),
        'icon'  => 'photo',
        'subtitle' => 'Assets · Collections · ' . $pendingTireImages . ' ' . ($pendingTireImages === 1 ? 'image' : 'images') . ' to review',
        'tone'  => 'accent',
        'index' => count($cards),
    ]);
}
?>
<section class="home-section" aria-labelledby="home-attention">
  <h2 class="ui-list-header" id="home-attention">Needs your attention</h2>
  <?= $cards ? actionCardStack(array_slice($cards, 0, 3)) : actionCardCaughtUp() ?>
</section>

<?php // --- 1b. Admin: Needs changes (what is waiting on Joust) ---------- ?>
<?php if ($isAdmin): ?>
<?php
  $queueUrl      = clientUrl('posts', ['status' => 'denied', 'month' => 'all']);
  $assetsTotal   = $needsAssets['tire'] + $needsAssets['library'];
  $assetsUrl     = clientUrl('assets', ['view' => $needsAssets['library'] > 0 ? 'library' : 'collections', 'filter' => 'denied']);
?>
<section class="home-section" aria-labelledby="home-changes" data-needs-changes="<?= (int)$needsPosts ?>">
  <div class="home-section-head">
    <h2 class="ui-list-header" id="home-changes">Needs changes</h2>
    <?php if ($needsPosts > 0): ?><a href="<?= h($queueUrl) ?>">Open queue</a><?php endif; ?>
  </div>
  <?php
    if ($needsPosts + $assetsTotal === 0) {
        echo actionCardCaughtUp('Nothing waiting on you', 'No change requests from ' . $client['name'] . ' right now.');
    } else {
        $changeCards = [];
        if ($needsPosts > 0) {
            $changeCards[] = actionCard([
                'count' => $needsPosts, 'noun' => 'post',
                'one'   => 'needs changes', 'many' => 'need changes',
                'href'  => $queueUrl,
                'icon'  => 'xmark', 'subtitle' => 'Posts · Needs changes · client notes inside', 'tone' => 'deny',
                'index' => 0,
            ]);
        }
        if ($assetsTotal > 0) {
            $assetParts = [];
            if ($needsAssets['library'] > 0) $assetParts[] = $needsAssets['library'] . ' in Library';
            if ($needsAssets['tire'] > 0)    $assetParts[] = $needsAssets['tire'] . ' in Collections';
            $changeCards[] = actionCard([
                'count' => $assetsTotal, 'noun' => 'asset',
                'one'   => 'needs changes', 'many' => 'need changes',
                'href'  => $assetsUrl,
                'icon'  => 'photo', 'subtitle' => 'Assets · ' . implode(' · ', $assetParts), 'tone' => 'deny',
                'index' => count($changeCards),
            ]);
        }
        echo actionCardStack($changeCards);
        if ($needsNotes) {
            $notesHtml = '<ul class="home-notes" role="list">';
            foreach ($needsNotes as $n) {
                $q = mb_strlen($n['text']) > 160 ? rtrim(mb_substr($n['text'], 0, 159)) . '…' : $n['text'];
                $notesHtml .= '<li><a class="home-note" href="' . h($n['href']) . '"><q>' . h($q) . '</q>'
                            . '<span class="home-note-meta">on ' . h($n['on']) . ' · ' . h($n['when']) . '</span></a></li>';
            }
            $notesHtml .= '</ul>';
            echo card($notesHtml, ['subtitle' => 'Latest notes from ' . $client['name'], 'class' => 'home-changes-notes']);
        }
    }
  ?>
</section>
<?php endif; ?>

<?php // --- 2. Coming up ----------------------------------------------- ?>
<?php if ($upcoming): ?>
<section class="home-section" aria-labelledby="home-upcoming">
  <div class="home-section-head">
    <h2 class="ui-list-header" id="home-upcoming">Coming up</h2>
    <a href="<?= h(clientUrl('posts', ['status' => 'approved'])) ?>">See all</a>
  </div>
  <div class="home-scroller" role="list">
    <?php foreach ($upcoming as $p):
      $ts       = $p['scheduled_date'] ? strtotime((string)$p['scheduled_date']) : false;
      $whenDay  = $ts ? date('D, M j', $ts) : 'Unscheduled';
      $whenTime = $ts ? date('g:i A', $ts) : '';
      $isSched  = (int)($p['posted'] ?? 0) === 1;
      $thumb    = (string)($p['thumb_url'] ?? '');
      $isVideo  = (($p['thumb_type'] ?? 'image') === 'video') || ($thumb !== '' && mediaTypeFromUrl($thumb) === 'video');
      $thumbSrc = $thumb !== '' ? (preg_match('#^(https?:)?//#', $thumb) ? $thumb : basePath() . '/' . ltrim($thumb, '/')) : '';
      $line     = homeFirstLine($p['caption'] ?? '');
      $title    = trim((string)($p['name'] ?? '')) ?: $line;
    ?>
      <a class="home-upcoming" role="listitem" href="<?= h(clientUrl('posts', ['post' => (int)$p['id']])) ?>">
        <span class="home-upcoming-thumb">
          <?php if ($thumbSrc !== '' && !$isVideo): ?>
            <img src="<?= h($thumbSrc) ?>" alt="" loading="lazy">
          <?php elseif ($isVideo && $thumbSrc !== ''): ?>
            <?= videoTile($thumbSrc, ['badge' => false, 'class' => 'home-upcoming-video']) ?>
          <?php else: ?>
            <?= icon('photo') ?>
          <?php endif; ?>
          <?= statusPill('approved', $isSched, ['class' => 'ui-pill--glass']) ?>
        </span>
        <span class="home-upcoming-body">
          <span class="home-upcoming-date"><?= icon('calendar') ?><span><?= h($whenDay) ?></span><?php if ($whenTime !== ''): ?><span class="home-upcoming-time"><?= h($whenTime) ?></span><?php endif; ?></span>
          <span class="home-upcoming-caption"><?= h($title !== '' ? $title : 'Untitled post') ?></span>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php // --- 3. Activity ------------------------------------------------ ?>
<?php if ($hasLog): ?>
  <?= activityFeed($activityRows, ['header' => 'Activity', 'limit' => 20, 'id' => 'home-activity']) ?>
<?php endif; ?>

<?php // --- 4. Admin variant (server-side gated) ------------------------ ?>
<?php if ($isAdmin): ?>
<section class="home-section" aria-labelledby="home-studio">
  <h2 class="ui-list-header" id="home-studio">Studio</h2>
  <div class="home-quick">
    <a class="ui-btn ui-btn--gray" href="<?= h(clientUrl('add-post.php')) ?>"><?= icon('plus') ?><span>Compose</span></a>
    <a class="ui-btn ui-btn--gray" href="<?= h(clientUrl('batch.php')) ?>"><?= icon('grid') ?><span>Batch</span></a>
    <a class="ui-btn ui-btn--gray" href="<?= h(clientUrl('admin.php')) ?>"><?= icon('photo') ?><span>Upload</span></a>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
