<?php
/**
 * Posts — Stage 3 review (spec §4.3). Replaces feed.php.
 *
 *   ?client=kenda                 scope (helpers.php)
 *   &status=pending|approved|scheduled   segment (admin also: draft, denied) — default pending
 *   &month=YYYY-MM|all            default: current month if it has posts, else all (feed.php semantics)
 *   &post=<id>                    open that post's detail on load (segment/month follow the post)
 *   &newpost=1|upload|edit        (admin) open the New post pop-up on load (newpost.js); edit needs &post=<id>
 *   &post=<id>&partial=1          return ONLY the detail partial HTML (for lists > 40 items)
 *   &post=<id>&partial=row        ONE list row as it renders in the post's own segment / month (headers X-Post-Segment,
 *                                 X-Post-Month) — posts.js App.posts.refresh() after a New post pop-up save, no reload
 *   &partial=list&offset=<n>      the next POSTS_PAGE rows of the list (same status/month) as markup — "Load more";
 *                                 headers X-Posts-Total / X-Posts-Next ('' when done). The page renders the first
 *                                 POSTS_PAGE rows; a deep link past them still opens its sheet (standalone template).
 *
 * Images: list rows show the sm preview (pvImg(), preview-ui.php), the detail carousel the lg one.
 *
 * Segments (DB strings never change):
 *   To Review = status pending  AND posted = 0
 *   Approved  = status approved AND posted = 0
 *   Scheduled = posted = 1                      (label only; DB value stays `posted`)
 *   Needs changes (admin only) = status denied AND posted = 0
 *   Drafts (admin only, first) = status draft (migrate.php step 35; postsHaveDraft())
 * Clients never receive denied or draft rows — filtered in SQL (postsClientVisibleSql(): status
 * IN pending / approved), so a deep link to a draft is a 404 partial / a plain list for them.
 *
 * The Needs changes segment is Joust's work queue: each row also carries the
 * client's latest note (deny note or newest comment — both are activity_log
 * 'commented' rows), a client-comment count, and Open / Edit & resubmit
 * actions (the New post pop-up in edit mode; its primary is Send for review).
 * Sorted by most recent client activity. No schema changes.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/partials/components/comment-thread.php';
require_once __DIR__ . '/partials/components/post-detail.php';

/** Escape helper (page-local by convention; partials use esc()). */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// hasPostedColumn() (posts.posted is migration-gated) lives in helpers.php.

$admin     = isAdmin();
$isListPartial = (($_GET['partial'] ?? '') === 'list');                 // "Load more" rows
$isRowPartial  = (($_GET['partial'] ?? '') === 'row');                  // one row (App.posts.refresh)
$isPartial = !empty($_GET['partial']) && !$isListPartial && !$isRowPartial;   // one post's detail
if (!defined('POSTS_PAGE')) { define('POSTS_PAGE', 30); }             // list rows per page
$listOffset = max(0, (int)($_GET['offset'] ?? 0));
$postParam = (int)($_GET['post'] ?? 0);

// Posts only makes sense for one client. A client seat without a scope gets the
// same "missing client" state assets.php uses (400); the admin picks a client.
if (!$client) {
    if ($isPartial || $isRowPartial) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="ui-empty">This post is no longer available.</div>';
        exit;
    }
    if (!$admin) {
        http_response_code(400);
        $pageTitle    = 'Posts';
        $navTrailing  = '';
        $showTabs     = false;
        $includeSheet = false;
        include __DIR__ . '/partials/layout-top.php';
        echo '<div class="ui-empty">This link is missing its client. Please use the review link Joust sent you.</div>';
        include __DIR__ . '/partials/layout-bottom.php';
        exit;
    }
    $companies = $pdo->query("
        SELECT c.id, c.name, c.slug, c.logo_url,
               (SELECT COUNT(*) FROM posts WHERE posts.company_id = c.id AND posts.status = 'pending') AS pending_count
        FROM companies c
        ORDER BY c.name ASC
    ")->fetchAll();
    $pageTitle   = 'Posts';
    $navSubtitle = 'Choose a client';
    $activeTab   = 'posts';
    $navTrailing = '';
    $headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">';
    $bodyClass   = 'page-posts page-posts-chooser';
    include __DIR__ . '/partials/layout-top.php';
    if (!$companies) {
        echo '<div class="ui-empty">No clients in the <code>companies</code> table yet.</div>';
    } else {
        echo insetListOpen('Clients');
        foreach ($companies as $c) {
            $pending = (int)$c['pending_count'];
            echo insetRow([
                'href'     => clientUrl('posts.php', ['client' => $c['slug']]),
                'leading'  => clientAvatar($c, 'ui-avatar--lg'),
                'title'    => $c['name'],
                'subtitle' => $pending > 0 ? $pending . ' to review' : 'Nothing waiting',
                'trailing' => $pending > 0 ? '<span class="ui-badge">' . $pending . '</span>' : '',
                'chevron'  => true,
                'attrs'    => ['data-client-row' => $c['slug']],
            ]);
        }
        echo insetListClose('Badges show posts still waiting for the client\'s review.');
    }
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
    $scopeWhere[] = postsClientVisibleSql('p');   // clients never see denied work or drafts (SQL, not CSS)
}
$hasDraft = $admin && postsHaveDraft($pdo);

/** Load images + comments + approved_at + the latest copy edit for a set of post rows (4 queries total). */
function postsAttachRelations(PDO $pdo, array &$posts, bool $hasMedia, bool $hasLog): void {
    if (!$posts) return;
    $ids = array_map('intval', array_column($posts, 'id'));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $byId = [];
    foreach ($posts as &$p) {
        $p['images'] = []; $p['comments'] = []; $p['approved_at'] = null; $p['last_edit'] = null;
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
            SELECT entity_id, actor, detail, created_at" . activityAuthorCols($pdo) . " FROM activity_log
            WHERE entity_type = 'post' AND action = 'commented' AND entity_id IN ($ph)
              AND detail IS NOT NULL AND detail <> ''" . activityVisibleSql($pdo) . "
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
        // Newest caption / hashtags edit per post → the sheet's "Edited by <client> · 5m ago" line
        // (rendered only when that edit came from the client seat).
        $st = $pdo->prepare("
            SELECT entity_id, actor, created_at FROM activity_log
            WHERE entity_type = 'post' AND action IN ('edited_caption', 'edited_hashtags') AND entity_id IN ($ph)
            ORDER BY created_at DESC, id DESC
        ");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) {
            $pid = (int)$row['entity_id'];
            if (isset($byId[$pid]) && $byId[$pid]['last_edit'] === null) {
                $byId[$pid]['last_edit'] = ['actor' => (string)$row['actor'], 'created_at' => (string)$row['created_at']];
            }
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

// A client following their own "You requested changes on …" link: the post left their view (it is Joust's
// queue now). The sheet says so and shows their note (renderPostHiddenNotice) — never a silent 404 and never
// the work in progress. Drafts and other clients' posts stay "not found".
$hiddenPost = null;
if (!$directPost && !$admin && $postParam > 0 && $client) {
    $st = $pdo->prepare($selectSql . " WHERE p.id = ? AND p.company_id = ? AND p.status = 'denied' LIMIT 1");
    $st->execute([$postParam, (int)$client['id']]);
    $hiddenPost = $st->fetch() ?: null;
}

if ($isPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    if (!$directPost && $hiddenPost) {
        $one = [$hiddenPost];
        postsAttachRelations($pdo, $one, $hasMedia, $hasLog);
        echo renderPostHiddenNotice($one[0], reviewLatestNote($one[0]['comments'], (string)($client['name'] ?? '')));
        exit;
    }
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

$monthParam = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : null;
if ($directPost && !empty($directPost['scheduled_date'])) {
    $dts = strtotime((string)$directPost['scheduled_date']);
    $monthParam = $dts ? date('Y-m', $dts) : 'all';
}
if ($monthParam === 'all') {
    $selectedMonth = '';
} elseif ($monthParam === null) {
    $current = date('Y-m');
    $selectedMonth = in_array($current, $availableMonths, true) ? $current : '';
} else {
    // A real calendar month only (2026-99 is not a month) — anything else means "all".
    $selectedMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam) ? $monthParam : '';
}

// ---------------------------------------------------------------------
// Segment
// ---------------------------------------------------------------------
// Admin: Joust's own work first — Draft · Needs changes — so the queue is on screen at 390 px (the
// segmented control scrolls sideways on phones); then the client's To Review, Approved, Scheduled.
$segments = ($hasDraft ? ['draft' => 'Draft'] : [])
          + ($admin ? ['denied' => 'Needs changes'] : [])
          + ['pending' => 'To Review', 'approved' => 'Approved', 'scheduled' => 'Scheduled'];

$segment = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if ($directPost) {
    $segment = !empty($directPost['posted']) ? 'scheduled' : (string)$directPost['status'];
}
if (!isset($segments[$segment])) { $segment = 'pending'; }

$segmentWhere = [
    'draft'     => "p.status = 'draft'",
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
$counts = ['draft' => 0, 'pending' => 0, 'approved' => 0, 'scheduled' => 0, 'denied' => 0];
$st = $pdo->prepare("SELECT p.status, ($postedExpr) AS posted, COUNT(*) AS n FROM posts p"
    . ($viewWhere ? ' WHERE ' . implode(' AND ', $viewWhere) : '') . ' GROUP BY p.status' . ($hasPosted ? ', p.posted' : ''));
$st->execute($viewParams);
foreach ($st->fetchAll() as $row) {
    $n = (int)$row['n'];
    if (!empty($row['posted'])) { $counts['scheduled'] += $n; }
    elseif (isset($counts[$row['status']])) { $counts[$row['status']] += $n; }
}
// Admin with no explicit segment: open on Joust's own queue (Needs changes) when it has items — the tab badge counts it too.
if ($admin && !$directPost && trim((string)($_GET['status'] ?? '')) === '' && $counts['denied'] > 0) {
    $segment = 'denied';
}

// ---------------------------------------------------------------------
// The list
// ---------------------------------------------------------------------
$listWhere  = $viewWhere;
$listWhere[] = $segmentWhere[$segment];
$isQueue = $admin && $segment === 'denied';
// Paging: POSTS_PAGE rows from $listOffset. The Needs changes queue is sorted in PHP (latest client activity),
// so it loads the (short, admin-only) segment whole and slices after sorting; every other segment pages in SQL.
$listTotal = (int)$counts[$segment];
$st = $pdo->prepare($selectSql . ' WHERE ' . implode(' AND ', $listWhere) . ' ORDER BY p.scheduled_date ASC, p.id ASC'
    . ($isQueue ? '' : ' LIMIT ' . (int)POSTS_PAGE . ' OFFSET ' . (int)$listOffset));
$st->execute($viewParams);
$posts = $st->fetchAll();
postsAttachRelations($pdo, $posts, $hasMedia, $hasLog);

// ---------------------------------------------------------------------
// Needs changes = the admin work queue. Attach the latest client note
// (the deny note is a 'commented' row in the same batch as the deny, so
// the comments already loaded above cover it), the client-comment count
// and the time of the last client activity; newest activity first.
// ---------------------------------------------------------------------
if ($isQueue && $posts) {
    $deniedAt = [];
    if ($hasLog) {
        $ids = array_map('intval', array_column($posts, 'id'));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare("
                SELECT entity_id, MAX(created_at) AS at FROM activity_log
                WHERE entity_type = 'post' AND action = 'denied' AND entity_id IN ($ph)
                GROUP BY entity_id
            ");
            $st->execute($ids);
            foreach ($st->fetchAll() as $row) { $deniedAt[(int)$row['entity_id']] = (string)$row['at']; }
        } catch (Throwable $e) {
            error_log('posts queue denied_at query failed: ' . $e->getMessage());
        }
    }
    foreach ($posts as &$p) {
        $p['queue'] = postsQueueInfo($p, $deniedAt[(int)$p['id']] ?? null, $client);
    }
    unset($p);
    usort($posts, static function ($a, $b) {
        return ($b['queue']['activity_ts'] <=> $a['queue']['activity_ts']) ?: ((int)$b['id'] <=> (int)$a['id']);
    });
}
if ($isQueue) {
    $listTotal = count($posts);
    $posts = array_slice($posts, $listOffset, POSTS_PAGE);
}
$listNext = ($listOffset + count($posts)) < $listTotal ? $listOffset + count($posts) : 0;   // 0 = nothing after this page

/**
 * Queue facts for one denied post: latest client note (else the latest note of
 * any actor), client-comment count and the last-activity timestamp used for sorting.
 */
function postsQueueInfo(array $post, ?string $deniedAt, ?array $client): array {
    $comments   = is_array($post['comments'] ?? null) ? $post['comments'] : [];
    $clientRows = array_values(array_filter($comments, static function ($c) {
        return strtolower(trim((string)($c['actor'] ?? ''))) === 'client';
    }));
    $latestClient = $clientRows ? $clientRows[count($clientRows) - 1] : null;
    $latestAny    = $comments ? $comments[count($comments) - 1] : null;
    $note         = $latestClient ?: $latestAny;
    $noteActor    = $note ? strtolower(trim((string)($note['actor'] ?? ''))) : '';
    $who          = $noteActor === 'client' ? (string)($client['name'] ?? $post['company_name'] ?? 'Client')
                  : ($noteActor === 'admin' ? 'Joust' : 'Note');

    $ts = 0;
    foreach ([$latestClient['created_at'] ?? null, $deniedAt, $latestAny['created_at'] ?? null,
              $post['updated_at'] ?? null, $post['scheduled_date'] ?? null] as $cand) {
        $t = $cand ? strtotime((string)$cand) : false;
        if ($t) { $ts = max($ts, $t); }
    }
    return [
        // "[Slide 3] text" → note_slide 3 + the text (the raw prefix is never shown)
        'note'         => $note ? trim(commentSlideSplit(trim((string)$note['detail']))[1]) : '',
        'note_slide'   => $note ? commentSlideSplit(trim((string)$note['detail']))[0] : 0,
        'note_who'     => $who,
        'note_at'      => $note ? (string)$note['created_at'] : ($deniedAt ?? ''),
        'client_count' => count($clientRows),
        'denied_at'    => $deniedAt ?? '',
        'activity_ts'  => $ts,
    ];
}

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
$inlineDetails = $isRowPartial || count($posts) <= $inlineLimit;

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
// Phones: the pill shrinks to "All" / "Oct" (this year) / "Oct 25" so the large "Posts" title keeps its room.
$monthShort = $selectedMonth === '' ? 'All' : date(substr($selectedMonth, 0, 4) === date('Y') ? 'M' : "M 'y", strtotime($selectedMonth . '-01'));
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
    'draft'     => 'No drafts. Uploads and posts you have not sent to the client yet wait here.',
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
// Admin: new posts come from the global "+ New" menu in this bar (navbar.php) — no second "New post" button here.
$navTrailing = '<button type="button" class="ui-btn ui-btn--tinted ui-btn--sm posts-month-pill" data-sheet-open="#postMonthSheet" aria-haspopup="dialog">'
             . '<span class="posts-month-long">' . h($monthLabel) . '</span><span class="posts-month-short" aria-hidden="true">' . h($monthShort) . '</span>'
             . icon('chevron-down', 'posts-month-chevron') . '</button>'
             . (!empty($client) ? clientAvatar($client) : '');
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">';
$bodyClass   = 'page-posts';

$postsConfig = [
    'base'        => basePath(),
    'endpoint'    => basePath() . '/status.php',
    'partialUrl'  => postsUrl(['post' => '__ID__', 'partial' => 1]),
    'rowUrl'      => postsUrl(['post' => '__ID__', 'partial' => 'row']),   // App.posts.refresh(): one row after a pop-up save
    'month'       => $selectedMonth,                                        // '' = all months (refresh: is the row in this view?)
    'clientName'  => (string)($client['name'] ?? ''),
    'listUrl'     => postsUrl(['status' => $segment, 'month' => $monthUrlParam, 'partial' => 'list', 'offset' => '__OFFSET__']),   // "Load more" rows
    'listTotal'   => $listTotal,
    'segment'     => $segment,
    'counts'      => $counts,
    'inline'      => $inlineDetails,
    'admin'       => $admin,
    'hasPosted'   => $hasPosted,
    'openPost'    => $postParam > 0 ? $postParam : 0,
    'queue'       => $isQueue,
    'segmentUrls' => array_combine(array_keys($segments), array_map($segmentUrl, array_keys($segments))),
    'maxMedia'    => POST_MAX_MEDIA,   // most media one post may carry (helpers.php)
    // One-shot flash after a save elsewhere (add-post.php lands here with &msg=…): posts.js toasts it and drops it from the URL.
    'flash'       => $admin && isset($_GET['msg']) && is_string($_GET['msg']) ? mb_substr(trim($_GET['msg']), 0, 300) : '',
];
$footExtra = '<script>window.PostsConfig = ' . json_encode($postsConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
           . ($admin ? '<script src="' . h(staticUrl('js/chunk-upload.js')) . '" defer></script>' . "\n" : '')   // App.chunkUpload for the New post pop-up's uploads (admin only)
           . '<script src="' . h(staticUrl('js/carousel.js')) . '" defer></script>' . "\n"   // App.carousel: swipe, dots, "2 / 7", arrows, ←/→ (both seats)
           . '<script src="' . h(staticUrl('js/posts.js')) . '" defer></script>';

/** One list row (the page and the "Load more" partial render the same markup). $rowIndex: position in the list (first rows load their thumb eagerly). */
$renderRow = function (array $post, int $rowIndex = 0) use ($client, $segment, $monthUrlParam, $isQueue, $inlineDetails, $admin, $hasPosted): string {
    ob_start();
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
        $isPast   = postIsPast($post);   // Scheduled + date before today → faded row (presentational only)
        $href     = postsUrl(['status' => $segment, 'month' => $monthUrlParam, 'post' => $pid]);
        $queue    = $isQueue ? ($post['queue'] ?? null) : null;
        $qNote    = $queue ? $queue['note'] : '';
        if (mb_strlen($qNote) > 220) { $qNote = rtrim(mb_substr($qNote, 0, 219)) . '…'; }
        $qWhen    = $queue && $queue['note_at'] !== '' ? relativeTime($queue['note_at']) : '';
        $qAbs     = $queue && $queue['note_at'] !== '' ? absoluteTime($queue['note_at']) : '';
        $qCount   = $queue ? (int)$queue['client_count'] : 0;
        $isDraft  = $admin && $post['status'] === 'draft';   // admin-only rows: no swipe, Send for review
        $noCaption = $isDraft && $caption === '';
    ?>
      <li class="pl-item<?= $queue ? ' pl-item--queue' : '' ?><?= $isPast ? ' pl-item--past' : '' ?>" id="post-<?= $pid ?>" data-post-item="<?= $pid ?>" data-id="<?= $pid ?>"
          data-status="<?= h($post['status']) ?>" data-posted="<?= $posted ? '1' : '0' ?>"<?= $isPast ? ' data-past="1"' : '' ?>
          data-title="<?= h($title) ?>"<?= $queue ? ' data-queue' : ($isDraft ? ' data-draft' : ($admin ? '' : ' data-swipe')) ?>>
        <?php if (!$queue && !$isDraft && !$admin): /* swipe = the client's decision; Joust decides for the client only via ⋯ → Approve for client… */ ?>
        <div class="pl-swipe pl-swipe--approve" aria-hidden="true"><?= icon('checkmark') ?><span>Approve</span></div>
        <div class="pl-swipe pl-swipe--deny" aria-hidden="true"><?= icon('xmark') ?><span>Needs changes</span></div>
        <?php endif; ?>
        <a class="ui-row ui-row--leading pl-card" href="<?= h($href) ?>" data-post-open="<?= $pid ?>">
          <div class="ui-row-leading pl-thumb<?= $isVid ? ' pl-thumb--video' : '' ?>">
            <?php if ($first && !$isVid): ?>
              <?= pvImg(pdMediaUrl($first['url']), 'sm', ['sizes' => pvSizes('row'), 'eager' => $rowIndex < 8]) ?>
            <?php elseif ($first): ?>
              <?= videoTile(pdMediaUrl($first['url']), ['class' => 'pl-thumb-video', 'badgeClass' => 'pl-thumb-badge']) ?>
            <?php else: ?>
              <?= icon('photo') ?>
            <?php endif; ?>
          </div>
          <div class="ui-row-body">
            <div class="pl-top">
              <div class="ui-row-title pl-title"><?= h($title) ?></div>
              <span class="pl-when">
                <?php if ($isPast): ?><span class="pl-past" title="This post's scheduled date has passed">Past</span><?php endif; ?>
                <time class="pl-date" datetime="<?= h($ts ? date('Y-m-d\TH:i', $ts) : '') ?>"><?= h($dateLbl) ?></time>
              </span>
            </div>
            <?php if ($subtitle !== ''): ?>
              <div class="pl-caption"><?= h($subtitle) ?></div>
            <?php endif; ?>
            <?php if ($queue): ?>
              <div class="pl-note<?= $qNote === '' ? ' pl-note--empty' : '' ?>" data-queue-note>
                <?php if ($qNote !== ''): ?>
                  <?php if (!empty($queue['note_slide'])): ?>On slide <?= (int)$queue['note_slide'] ?>: <?php endif; ?><q><?= h($qNote) ?></q>
                  <span class="pl-note-meta"><?= h($queue['note_who']) ?><?php if ($qWhen !== ''): ?> · <time title="<?= h($qAbs) ?>"><?= h($qWhen) ?></time><?php endif; ?></span>
                <?php else: ?>
                  <span>No note left<?php if ($qWhen !== ''): ?> · changes requested <time title="<?= h($qAbs) ?>"><?= h($qWhen) ?></time><?php endif; ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="pl-meta">
              <?= statusPill($post['status'], $posted) ?>
              <span class="pl-meta-item"><span class="pl-meta-sep">·</span><span><?= $nImg ?> <?= $nImg === 1 ? ($isVid ? 'video' : 'image') : 'media' ?></span></span>
              <?php if ($queue): ?>
                <span class="pl-meta-item"><span class="pl-meta-sep">·</span><span data-queue-count="<?= $pid ?>"><?= $qCount > 0 ? $qCount . ' client ' . ($qCount === 1 ? 'comment' : 'comments') : 'no client comments' ?></span></span>
              <?php else: ?>
                <span class="pl-meta-item"<?= $nCmt > 0 ? '' : ' hidden' ?>><span class="pl-meta-sep">·</span><span data-comment-count-for="<?= $pid ?>"><?= $nCmt ?> <?= $nCmt === 1 ? 'comment' : 'comments' ?></span></span>
              <?php endif; ?>
            </div>
          </div>
          <?= icon('chevron-right', 'ui-row-chevron') ?>
        </a>
        <?php if ($isDraft): ?>
          <div class="pl-queue-actions pl-draft-actions">
            <?php if ($noCaption): ?><span class="pl-draft-hint text-tertiary">No caption yet</span><?php endif; ?>
            <button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-post-open="<?= $pid ?>">Open</button>
            <button type="button" class="ui-btn ui-btn--tinted ui-btn--sm" data-submit-post="<?= $pid ?>" title="Send this draft to the client's To Review list">Send for review</button>
          </div>
        <?php endif; ?>
        <?php if ($queue): ?>
          <div class="pl-queue-actions">
            <button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-post-open="<?= $pid ?>">Open</button>
            <button type="button" class="ui-btn ui-btn--tinted ui-btn--sm" data-newpost-edit="<?= $pid ?>" data-newpost-resubmit title="Fix it in the New post pop-up, then send it back to the client's To Review list">Edit &amp; resubmit</button>
          </div>
        <?php endif; ?>
        <?php if ($inlineDetails): ?>
          <template data-post-template="<?= $pid ?>"><?= renderPostDetail($post, ['admin' => $admin, 'hasPosted' => $hasPosted]) ?></template>
        <?php endif; ?>
      </li>
<?php
    return (string)ob_get_clean();
};

// One row (&partial=row&post=ID): the post as it renders in its own segment / month (both follow the post above).
if ($isRowPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $rowPost = null;
    foreach ($posts as $p) { if ((int)$p['id'] === $postParam) { $rowPost = $p; break; } }
    if (!$rowPost && $directPost) {   // past the first page of its segment: relations are attached above
        $rowPost = $directPost;
        if ($isQueue) { $rowPost['queue'] = postsQueueInfo($rowPost, null, $client); }
    }
    if (!$rowPost) {
        http_response_code(404);
        exit;
    }
    header('X-Post-Segment: ' . $segment);
    header('X-Post-Month: ' . (!empty($rowPost['scheduled_date']) && strtotime((string)$rowPost['scheduled_date']) ? date('Y-m', strtotime((string)$rowPost['scheduled_date'])) : ''));
    echo $renderRow($rowPost, 0);
    exit;
}

// "Load more" (&partial=list&offset=N): the next rows only.
if ($isListPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Posts-Total: ' . $listTotal);
    header('X-Posts-Next: ' . ($listNext > 0 ? (string)$listNext : ''));
    foreach ($posts as $i => $post) { echo $renderRow($post, $listOffset + $i); }
    exit;
}

include __DIR__ . '/partials/layout-top.php';
?>

<div class="posts-toolbar">
  <?= segmented($segItems, ['label' => 'Post status', 'scroll' => true]) ?>
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
    <?php foreach ($posts as $i => $post) { echo $renderRow($post, $i); } ?>
  </ul>
  <?php if ($listNext > 0): $remaining = $listTotal - $listNext; ?>
    <div class="posts-more" data-posts-more-wrap>
      <button type="button" class="ui-btn ui-btn--gray posts-more-btn" data-posts-more data-offset="<?= (int)$listNext ?>" data-total="<?= (int)$listTotal ?>">
        Load more <span class="posts-more-count" data-posts-more-count><?= (int)$remaining ?> remaining</span>
      </button>
      <noscript><a class="ui-btn ui-btn--gray" href="<?= h(postsUrl(['status' => $segment, 'month' => $monthUrlParam, 'offset' => $listNext])) ?>">Next <?= (int)min(POSTS_PAGE, $remaining) ?></a></noscript>
    </div>
  <?php endif; ?>
  <?php if ($segment === 'pending' && !$admin): ?>
    <p class="ui-list-footer posts-hint">Swipe right to approve, left for needs changes. Tap a post for the full preview.</p>
  <?php elseif ($isQueue): ?>
    <p class="ui-list-footer">Newest client activity first. Open a post for the full thread; Edit &amp; resubmit fixes it and sends it back to the client's To Review list.</p>
  <?php endif; ?>
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
