<?php
/**
 * Home — "Today" (spec §4.1).
 *
 *   ?client=kenda   → the client's Today screen:
 *                     1. Needs your attention — up to four stacked action cards
 *                        (pending posts / emails to review / Library images /
 *                        tires with new images), or one quiet "all caught up" card.
 *                     2. Coming up — the next three approved or scheduled posts, merged
 *                        with Live emails that have a future send date.
 *                     3. Activity — humanized, run-collapsed, never a filename.
 *                     4. Appearance (Light · Dark · Auto).
 *                     Admin (role enforced with isAdmin()) reads the same data from Joust's side:
 *                     1. "Needs your changes" first (denied posts / emails / assets waiting on
 *                        Joust, with the latest client notes, linking to the work queues),
 *                     2. "Waiting on <client>" (the client's To Review items, in admin words),
 *                     3. Coming up, 4. the Joust links (Manage, Projects, Tools), 5. Activity.
 *                     No Appearance card (the nav bar's button) and no New post / Upload tiles
 *                     (the nav bar's "+ New" is the one place to create).
 *   (no client)     → a client chooser; admin also sees cross-client activity.
 *
 * No database changes. Counts use the same queries as the tab-bar badges
 * (partials/tabbar.php) so the numbers always agree. Everything email-related
 * is gated on companyHasEmails() (emails-lib.php): a company without the
 * Emails module renders exactly as before.
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
    $navTrailing = $isAdmin ? joustAvatar() : '';   // the admin chooser carries the Joust mark (no client is scoped)
    $activeTab  = 'home';
    $headExtra  = '<link rel="stylesheet" href="' . h(staticUrl('css/home.css')) . '">' . "\n";
    include __DIR__ . '/partials/layout-top.php';
    ?>
    <?= $isAdmin && function_exists('trackingInboxHomeHtml') ? trackingInboxHomeHtml($pdo, null) : '' ?>
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
    <?php if ($isAdmin): ?>
      <section class="home-section">
        <?= insetListOpen('Manage', ['attrs' => ['data-home-manage' => '1']]) ?>
        <?= insetRow([
            'href'     => manageUrl('clients'),
            'icon'     => 'sliders',
            'title'    => 'Clients',
            'subtitle' => 'Add a client, logos, settings, which tabs each one sees',
            'attrs'    => ['data-home-link' => 'clients'],
        ]) ?>
        <?= insetRow([
            'href'     => pagePath('drive'),
            'icon'     => 'drive',
            'title'    => 'Google Drive',
            'subtitle' => 'Capacity, biggest clients, what to offboard first',
            'attrs'    => ['data-drive-link' => '1'],
        ]) ?>
        <?= insetListClose() ?>
      </section>
    <?php endif; ?>
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

// Emails (module-gated; emails-lib.php). pending = awaiting the client, the
// same rule as the Emails tab badge. Live emails with a future send date join
// "Coming up". Nothing here runs for a company without the module.
$hasEmails      = function_exists('companyHasEmails') && companyHasEmails($client, $pdo);
$emailCounts    = ['draft' => 0, 'pending' => 0, 'approved' => 0, 'denied' => 0, 'live' => 0, 'total' => 0];
$upcomingEmails = [];
if ($hasEmails) {
    try {
        $emailCounts = emailCounts($pdo, $cid);
        $today = date('Y-m-d');
        foreach (emailsForCompany($pdo, $cid, ['status' => 'live']) as $e) {
            $raw = trim((string)($e['send_at'] ?? ''));
            if ($raw === '' || $raw === '0000-00-00' || $raw < $today) continue;
            $ts = strtotime($raw);
            if ($ts === false) continue;
            $upcomingEmails[] = ['kind' => 'email', 'ts' => $ts, 'id' => (int)$e['id'], 'row' => $e];
        }
    } catch (Throwable $e) {
        error_log('index emails query failed: ' . $e->getMessage());
        $emailCounts    = ['draft' => 0, 'pending' => 0, 'approved' => 0, 'denied' => 0, 'live' => 0, 'total' => 0];
        $upcomingEmails = [];
    }
}
$pendingEmails = (int)$emailCounts['pending'];

// Pages (module-gated; pages-lib.php): pending = awaiting the client, the same
// rule as the Pages tab badge. Nothing here runs for a company without the module.
$hasPages   = function_exists('companyHasPages') && companyHasPages($client, $pdo);
$pageCounts = ['draft' => 0, 'pending' => 0, 'approved' => 0, 'denied' => 0, 'live' => 0, 'total' => 0];
if ($hasPages) {
    try { $pageCounts = pageCounts($pdo, $cid); } catch (Throwable $e) { error_log('index pages query failed: ' . $e->getMessage()); }
}
$pendingPages = (int)$pageCounts['pending'];

// ---------------------------------------------------------------------
// Coming up — next 3 approved or scheduled posts from today onwards,
// merged with the Live emails above (date order; an email's send date is
// all-day, so it sorts ahead of posts on the same day).
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
$upcoming = [];
foreach (array_slice($st->fetchAll(), 0, 3) as $p) {
    $ts = $p['scheduled_date'] ? strtotime((string)$p['scheduled_date']) : false;
    $upcoming[] = ['kind' => 'post', 'ts' => $ts === false ? PHP_INT_MAX : $ts, 'id' => (int)$p['id'], 'row' => $p];
}
if ($upcomingEmails) {
    $upcoming = array_merge($upcoming, $upcomingEmails);
    usort($upcoming, static function ($a, $b) {
        return [$a['ts'], $a['kind'] === 'post' ? 0 : 1, $a['id']] <=> [$b['ts'], $b['kind'] === 'post' ? 0 : 1, $b['id']];
    });
    $upcoming = array_slice($upcoming, 0, 3);
}

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
// queue; emails = Needs changes (denied, not live), the emails.php queue;
// assets = denied tire / library images (assets.php filter=denied).
// The latest client notes are activity_log 'commented' rows on those
// posts / emails (a deny note is stored as one of these) plus the client's
// comments on tire / library images from the last 7 days (any status, each
// linking to the viewer), newest first, one per item, merged and capped at three.
// ---------------------------------------------------------------------
$needsPosts  = 0;
$needsEmails = 0;
$needsPages  = 0;
$needsAssets = ['tire' => 0, 'library' => 0];
$needsNotes  = [];
$unansweredTotal = 0;
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

        // Emails / pages in Needs changes (the client notes for every item come from notifyUnanswered() below).
        if ($hasEmails) {
            $needsEmails = (int)$emailCounts['denied'];
        }

        if ($hasPages) {
            $needsPages = (int)$pageCounts['denied'];
        }

        // Latest notes: EVERY item (post, email, page, tire / library image, series) whose newest client comments
        // have no answer from Joust yet — an admin comment (not an internal note), a decision / status move, or a
        // Slack "Resolve" after them (notifyUnanswered(), notify-lib.php; the same rule the escalations use).
        // Whatever the item's status: a question on a To Review post or an approved email is still waiting on Joust.
        // One row per item (its newest message, "+N more" when there are several), oldest wait first in the query,
        // shown newest first; the 60-day window keeps ancient history out.
        if ($hasLog) {
            $waitingRows = function_exists('notifyUnanswered')
                ? notifyUnanswered($pdo, $cid, date('Y-m-d H:i:s', time() - 60 * 86400), 100) : [];
            foreach ($waitingRows as $w) {
                $info = notifyItemInfo($pdo, (string)$w['entity_type'], (int)$w['entity_id']);
                if (!$info['exists'] || (int)$info['company_id'] !== $cid) continue;
                $unansweredTotal++;
                [$slideNo, $noteText] = commentSlideSplit(trim((string)$w['last_detail']));
                $on = (string)$info['title'];
                if ($w['entity_type'] === 'tire_image' && activityLooksLikeFilename($on)) $on = 'an image';
                $needsNotes[] = [
                    'text' => trim($noteText),
                    'lead' => $slideNo > 0 ? 'On slide ' . $slideNo : '',
                    'on'   => $on,
                    'when' => relativeTime($w['last_at']),
                    'more' => max(0, (int)$w['n'] - 1),
                    'href' => activityDeepLink(['entity_type' => $w['entity_type'], 'entity_id' => (int)$w['entity_id'],
                                                'company_slug' => $client['slug'], '_meta' => $info['meta']]),
                    'ts'   => (int)strtotime((string)$w['last_at']),
                    'kind' => 'waiting',
                ];
            }
            usort($needsNotes, static function ($a, $b) { return $b['ts'] <=> $a['ts']; });

            // Copy edits by the client from the last 7 days (any status — a client may edit a caption
            // until the post is scheduled): a note-like row "Edited the caption: '<new text>'" per post,
            // newest first, so Joust notices copy it did not write. Merged with the notes by time.
            $editNameSel = $hasName ? 'p.name AS post_name' : "'' AS post_name";
            $st = $pdo->prepare("
                SELECT c.entity_id, c.action, c.created_at, p.caption AS post_caption, p.hashtags AS post_hashtags, {$editNameSel}
                  FROM activity_log c
                 INNER JOIN posts p ON p.id = c.entity_id
                 WHERE c.company_id = ? AND c.entity_type = 'post' AND c.actor = 'client'
                   AND c.action IN ('edited_caption', 'edited_hashtags') AND c.created_at >= ?
                 ORDER BY c.created_at DESC, c.id DESC
                 LIMIT 12
            ");
            $st->execute([$cid, date('Y-m-d H:i:s', time() - 7 * 86400)]);
            $seen = []; $editNotes = [];
            foreach ($st->fetchAll() as $r) {
                $pid = (int)$r['entity_id'];
                if (isset($seen[$pid])) continue;                   // one row per post — the newest edit
                $seen[$pid] = true;
                $isTags = ($r['action'] ?? '') === 'edited_hashtags';
                $text   = trim(preg_replace('/\s+/u', ' ', (string)($isTags ? ($r['post_hashtags'] ?? '') : ($r['post_caption'] ?? ''))));
                $name   = trim((string)($r['post_name'] ?? ''));
                if ($name === '' || activityLooksLikeFilename($name)) $name = homeFirstLine($r['post_caption'] ?? '', 60);
                $editNotes[] = [
                    'lead' => $isTags ? 'Edited the hashtags' : 'Edited the caption',
                    'text' => $text !== '' ? $text : '(empty)',
                    'on'   => $name !== '' ? $name : 'Post #' . $pid,
                    'when' => relativeTime($r['created_at']),
                    'href' => clientUrl('posts', ['post' => $pid]),
                    'ts'   => (int)strtotime((string)$r['created_at']),
                ];
                if (count($editNotes) >= 3) break;
            }
            if ($editNotes) {
                // copy edits follow the waiting notes (they are FYI, not questions)
                $needsNotes = array_merge($needsNotes, $editNotes);
            }
        }
    } catch (Throwable $e) {
        error_log('index needs-changes query failed: ' . $e->getMessage());
        $needsPosts  = 0;
        $needsEmails = 0;
        $needsPages  = 0;
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

// --- 1. To review ---------------------------------------------------------
// Client seat: "Needs your attention" — what is ready for THEIR review.
// Admin seat: the same items are waiting on the client ("Waiting on Kenda Tires"); Joust's own queue
// ("Needs your changes", 1b) comes first.
$tiresWord = tiresLabel($client);
$waitOne   = $isAdmin ? 'is waiting for their review' : 'is ready for your review';
$waitMany  = $isAdmin ? 'waiting for their review' : 'ready for your review';
$cards = [];
if ($pendingPosts > 0) {
    $cards[] = actionCard([
        'count' => $pendingPosts, 'noun' => 'post',
        'one'   => $waitOne, 'many' => $waitMany,
        'href'  => clientUrl('posts', ['status' => 'pending']),
        'icon'  => 'grid', 'subtitle' => 'Posts · To Review', 'tone' => 'accent',
        'index' => count($cards),
    ]);
}
if ($pendingEmails > 0) {
    $cards[] = actionCard([
        'count' => $pendingEmails, 'noun' => 'email',
        'one'   => $waitOne, 'many' => $waitMany,
        'href'  => emailsUrl(['status' => 'pending']),
        'icon'  => 'mail', 'subtitle' => 'Emails · To Review', 'tone' => 'accent',
        'index' => count($cards),
    ]);
}
if ($pendingPages > 0) {
    $cards[] = actionCard([
        'count' => $pendingPages, 'noun' => 'page',
        'one'   => $waitOne, 'many' => $waitMany,
        'href'  => pagesUrl(['status' => 'pending']),
        'icon'  => 'page', 'subtitle' => 'Pages · To Review', 'tone' => 'accent',
        'index' => count($cards),
    ]);
}
if ($pendingLibrary > 0) {
    $cards[] = actionCard([
        'count' => $pendingLibrary, 'noun' => 'image',
        'one'   => $isAdmin ? 'is waiting for their review' : 'to approve in Library', 'many' => $isAdmin ? 'waiting for their review' : 'to approve in Library',
        'href'  => clientUrl('assets', ['view' => 'library', 'filter' => 'pending']),
        'icon'  => 'photo', 'subtitle' => 'Assets · Library', 'tone' => 'accent',
        'index' => count($cards),
    ]);
}
if ($pendingCollections > 0) {
    $cards[] = actionCard([
        'count' => $pendingCollections, 'noun' => 'tire',
        'one'   => $isAdmin ? 'has new images waiting for their review' : 'has new images', 'many' => $isAdmin ? 'have new images waiting for their review' : 'have new images',
        'href'  => clientUrl('assets', ['view' => 'collections']),
        'icon'  => 'tire',
        'subtitle' => $tiresWord . ' · ' . $pendingTireImages . ' ' . ($pendingTireImages === 1 ? 'image' : 'images') . ' to review',
        'tone'  => 'accent',
        'index' => count($cards),
    ]);
}
ob_start();
?>
<section class="home-section" aria-labelledby="home-attention" data-home-review="<?= $isAdmin ? 'waiting' : 'yours' ?>">
  <h2 class="ui-list-header" id="home-attention"><?= $isAdmin ? 'Waiting on ' . h($client['name']) : 'Needs your attention' ?></h2>
  <?php if ($cards): ?>
    <?= actionCardStack(array_slice($cards, 0, 4)) ?>
  <?php elseif ($isAdmin): ?>
    <?= actionCardCaughtUp('Nothing waiting on ' . $client['name'], 'Everything sent for review has been answered.') ?>
  <?php else: ?>
    <?= actionCardCaughtUp() ?>
  <?php endif; ?>
</section>
<?php
$reviewSectionHtml = (string)ob_get_clean();
if (!$isAdmin) echo $reviewSectionHtml;   // the client's Home opens with it; the admin's comes after "Needs your changes"
?>

<?php // --- 1b. Admin: Needs your changes (Joust's own queue — first on the admin's Home) ---------- ?>
<?php if ($isAdmin): ?>
<?php
  $queueUrl      = clientUrl('posts', ['status' => 'denied', 'month' => 'all']);
  $emailQueueUrl = $needsEmails > 0 ? emailsUrl(['status' => 'denied']) : '';
  $pageQueueUrl  = $needsPages > 0 ? pagesUrl(['status' => 'denied']) : '';
  $assetsTotal   = $needsAssets['tire'] + $needsAssets['library'];
  $assetsUrl     = clientUrl('assets', ['view' => $needsAssets['library'] > 0 ? 'library' : 'collections', 'filter' => 'denied']);
?>
<section class="home-section" aria-labelledby="home-changes" data-needs-changes="<?= (int)$needsPosts ?>"<?= $needsEmails > 0 ? ' data-needs-changes-emails="' . (int)$needsEmails . '"' : '' ?><?= $needsPages > 0 ? ' data-needs-changes-pages="' . (int)$needsPages . '"' : '' ?>>
  <div class="home-section-head">
    <h2 class="ui-list-header" id="home-changes">Needs your changes</h2>
    <?php if ($needsPosts > 0): ?><a href="<?= h($queueUrl) ?>">Open queue</a><?php elseif ($needsEmails > 0): ?><a href="<?= h($emailQueueUrl) ?>">Open queue</a><?php elseif ($needsPages > 0): ?><a href="<?= h($pageQueueUrl) ?>">Open queue</a><?php endif; ?>
  </div>
  <?php
    if ($needsPosts + $needsEmails + $needsPages + $assetsTotal === 0) {
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
        if ($needsEmails > 0) {
            $changeCards[] = actionCard([
                'count' => $needsEmails, 'noun' => 'email',
                'one'   => 'needs changes', 'many' => 'need changes',
                'href'  => $emailQueueUrl,
                'icon'  => 'mail', 'subtitle' => 'Emails · Needs changes · client notes inside', 'tone' => 'deny',
                'index' => count($changeCards),
            ]);
        }
        if ($needsPages > 0) {
            $changeCards[] = actionCard([
                'count' => $needsPages, 'noun' => 'page',
                'one'   => 'needs changes', 'many' => 'need changes',
                'href'  => $pageQueueUrl,
                'icon'  => 'page', 'subtitle' => 'Pages · Needs changes · client notes inside', 'tone' => 'deny',
                'index' => count($changeCards),
            ]);
        }
        if ($assetsTotal > 0) {
            $assetParts = [];
            if ($needsAssets['library'] > 0) $assetParts[] = $needsAssets['library'] . ' in Assets';
            if ($needsAssets['tire'] > 0)    $assetParts[] = $needsAssets['tire'] . ' in ' . $tiresWord;
            $changeCards[] = actionCard([
                'count' => $assetsTotal, 'noun' => 'image',
                'one'   => 'needs changes', 'many' => 'need changes',
                'href'  => $assetsUrl,
                'icon'  => 'photo', 'subtitle' => implode(' · ', $assetParts), 'tone' => 'deny',
                'index' => count($changeCards),
            ]);
        }
        echo actionCardStack($changeCards);
    }
    // Latest client notes: every unanswered client comment, on any item and in any status (notifyUnanswered()),
    // then the client's recent copy edits.
    if ($needsNotes) {
        $notesHtml = '<ul class="home-notes" role="list">';
        foreach ($needsNotes as $n) {
            $q = mb_strlen($n['text']) > 160 ? rtrim(mb_substr($n['text'], 0, 159)) . '…' : $n['text'];
            // Copy edits lead with what changed ("Edited the caption:") before the quoted new text.
            $body = !empty($n['lead'])
                ? '<span class="home-note-text"><span class="home-note-lead">' . h($n['lead']) . ':</span> <q>' . h($q) . '</q></span>'
                : '<q>' . h($q) . '</q>';
            $more = !empty($n['more']) ? ' · +' . (int)$n['more'] . ' more' : '';
            $notesHtml .= '<li><a class="home-note' . (($n['kind'] ?? '') === 'waiting' ? ' home-note--waiting' : '') . '" href="' . h($n['href']) . '"'
                        . (($n['kind'] ?? '') === 'waiting' ? ' data-note-waiting' : '') . '>' . $body
                        . '<span class="home-note-meta">on ' . h($n['on']) . ' · ' . h($n['when']) . h($more) . '</span></a></li>';
        }
        $notesHtml .= '</ul>';
        echo card($notesHtml, ['subtitle' => 'Latest notes from ' . $client['name'] . ($unansweredTotal > 0 ? ' · ' . $unansweredTotal . ' waiting on you' : ''), 'class' => 'home-changes-notes']);
    }
  ?>
</section>
<?= $reviewSectionHtml // admin: "Waiting on <client>" after Joust's own queue ?>
<?php endif; ?>

<?php // --- 2. Coming up ----------------------------------------------- ?>
<?php if ($upcoming): ?>
<section class="home-section" aria-labelledby="home-upcoming">
  <div class="home-section-head">
    <h2 class="ui-list-header" id="home-upcoming">Coming up</h2>
    <a href="<?= h(clientUrl('posts', ['status' => 'approved'])) ?>">See all</a>
  </div>
  <div class="home-scroller" role="list">
    <?php foreach ($upcoming as $u): if ($u['kind'] === 'email'):
      $e       = $u['row'];
      $whenDay = date('D, M j', $u['ts']);
    ?>
      <a class="home-upcoming home-upcoming--email" role="listitem" href="<?= h(emailUrl($e)) ?>">
        <span class="home-upcoming-thumb home-upcoming-thumb--email">
          <?= icon('mail') ?>
          <?= emailStatusPill($e, ['class' => 'ui-pill--glass']) ?>
        </span>
        <span class="home-upcoming-body">
          <span class="home-upcoming-date"><?= icon('calendar') ?><span><?= h($whenDay) ?></span></span>
          <span class="home-upcoming-caption"><?= h(emailDisplayLabel($e)) ?></span>
        </span>
      </a>
    <?php else:
      $p        = $u['row'];
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
            <?= pvImg($thumbSrc, 'sm', ['sizes' => pvSizes('card')]) ?>
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
    <?php endif; endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php // --- 3. Admin: Joust links (server-side gated) — above Activity; "+ New" in the nav bar is the one
      //     place to create (New post / Upload / New tire …), so no duplicate New post / Upload tiles here ---- ?>
<?php if ($isAdmin): ?>
<?= function_exists('trackingInboxHomeHtml') ? trackingInboxHomeHtml($pdo, $client) : '' ?>
<section class="home-section" aria-labelledby="home-manage" data-home-admin>
  <h2 class="ui-list-header" id="home-manage">Joust</h2>
  <?= insetListOpen('', ['class' => 'home-manage', 'attrs' => ['data-home-manage' => '1']]) ?>
    <?= insetRow(['href' => manageUrl('clients'), 'icon' => 'sliders', 'title' => 'Client settings', 'subtitle' => 'Logo, default hashtags, which tabs ' . $client['name'] . ' sees', 'attrs' => ['data-home-link' => 'clients']]) ?>
    <?= insetRow(['href' => manageUrl('export'), 'icon' => 'download', 'title' => 'Export approved assets', 'subtitle' => 'One zip, a folder per tire', 'attrs' => ['data-home-link' => 'export']]) ?>
    <?= insetRow(['href' => clientUrl('projects.php'), 'icon' => 'checklist', 'title' => 'Projects', 'subtitle' => 'Tasks shared with ' . $client['name'], 'attrs' => ['data-home-link' => 'projects']]) ?>
    <?= insetRow(['href' => manageUrl('tools'), 'icon' => 'wand', 'title' => 'Tools', 'subtitle' => 'AI Builder, prompts, vehicles, email import', 'attrs' => ['data-home-link' => 'tools']]) ?>
  <?= insetListClose() ?>
</section>
<?php endif; ?>

<?php // --- 4. Activity ------------------------------------------------ ?>
<?php if ($hasLog): ?>
  <?= activityFeed($activityRows, ['header' => 'Activity', 'limit' => 20, 'id' => 'home-activity']) ?>
<?php endif; ?>

<?php // --- 5. Appearance (client seat; the admin uses the nav bar's sun / moon button, which cycles the same choice) ?>
<?php if (!$isAdmin): ?>
<section class="home-section home-appearance" aria-labelledby="home-appearance" id="home-appearance-section">
  <h2 class="ui-list-header" id="home-appearance">Appearance</h2>
  <div class="ui-card home-appearance-card">
    <?= appearanceControl() ?>
    <p class="t-footnote text-secondary home-appearance-note">Auto follows your device's light or dark setting. Your choice is remembered on this device.</p>
  </div>
</section>
<?php if ($homeSess = currentClientSession($pdo)): // phones: the sidebar's sign-out line is not there ?>
<p class="home-signout t-footnote text-secondary" data-client-signout>Signed in as <?= h($homeSess['email']) ?> · <a href="<?= h(pagePath('sign-out')) ?>">Sign out</a></p>
<?php if (function_exists('clientEmailReady') && clientEmailReady($pdo)): ?><p class="home-email-settings t-footnote text-secondary"><span><a href="<?= h(notifyMachineUrl('email-prefs')) ?>" data-email-settings-home>Email settings</a></span></p><?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
