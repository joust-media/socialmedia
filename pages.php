<?php
/**
 * Pages — client review + Joust work queue (scratchpad pages-design.md).
 * The Pages twin of emails.php: same chrome, segments, sheet and swipe.
 *
 *   ?client=privacybee                     scope (helpers.php)
 *   &status=pending|approved|live          segment — default pending
 *          |draft|denied                   admin only (client → falls back to pending)
 *          |all                            every row the viewer may see (Studio's "Open pages")
 *   &q=launch                              substring over title / slug / description
 *   &page=<id>                             open that page's detail on load (segment follows the row)
 *   &page=<id>&partial=1                   return ONLY the detail partial HTML (lists > 40 items)
 *
 * Segments (display keys from pages-lib.php — live=1 always wins):
 *   Draft (admin) · To Review · Approved · Live · Needs changes (admin work queue)
 * Clients never receive draft or denied rows — filtered in SQL
 * (pagesForCompany(..., ['visibleTo' => 'client'])), exactly like emails.
 *
 * Needs changes = Joust's work queue: each row carries the client's latest note
 * (deny note or newest comment — both 'commented' activity rows), a client-comment
 * count and Open / Resubmit for review; newest client activity first.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/partials/components/comment-thread.php';
require_once __DIR__ . '/partials/components/page-detail.php';

/** Escape helper (page-local by convention; partials use esc()). */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$admin     = isAdmin();
$isPartial = !empty($_GET['partial']);
$pageParam = (int)($_GET['page'] ?? 0);
$hasTable  = hasPagesTable($pdo);

// ---------------------------------------------------------------------
// No client scope: partial → 404, client seat → 400, admin → chooser.
// ---------------------------------------------------------------------
if (!$client) {
    if ($isPartial) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="ui-empty">This page is no longer available.</div>';
        exit;
    }
    if (!$admin) {
        http_response_code(400);
        $pageTitle    = 'Pages';
        $navTrailing  = '';
        $showTabs     = false;
        $includeSheet = false;
        include __DIR__ . '/partials/layout-top.php';
        echo '<div class="ui-empty">This link is missing its client. Please use the review link Joust sent you.</div>';
        include __DIR__ . '/partials/layout-bottom.php';
        exit;
    }
    $companies = [];
    if ($hasTable) {
        foreach ($pdo->query("SELECT id, name, slug, logo_url FROM companies ORDER BY name ASC")->fetchAll() as $c) {
            if (!companyHasPages($c, $pdo)) continue;
            $c['counts'] = pageCounts($pdo, (int)$c['id']);
            $companies[] = $c;
        }
    }
    $pageTitle   = 'Pages';
    $navSubtitle = 'Choose a client';
    $activeTab   = 'pages';
    $navTrailing = '';
    $headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
                 . '<link rel="stylesheet" href="' . h(staticUrl('css/pages.css')) . '">';
    $bodyClass   = 'page-pages page-pages-chooser';
    include __DIR__ . '/partials/layout-top.php';
    if (!$hasTable) {
        echo '<div class="ui-empty">Pages are not set up yet — run <code>migrate.php</code> first.</div>';
    } elseif (!$companies) {
        echo '<div class="ui-empty">No client has the Pages module yet. Enable it in Studio or add the first page.</div>';
    } else {
        echo insetListOpen('Clients');
        foreach ($companies as $c) {
            $pending = (int)$c['counts']['pending'];
            $total   = (int)$c['counts']['total'];
            echo insetRow([
                'href'     => clientUrl('pages.php', ['client' => $c['slug']]),
                'leading'  => clientAvatar($c, 'ui-avatar--lg'),
                'title'    => $c['name'],
                'subtitle' => $pending > 0 ? $pending . ' to review' : ($total > 0 ? 'Nothing waiting' : 'No pages yet'),
                'trailing' => $pending > 0 ? '<span class="ui-badge">' . $pending . '</span>' : '',
                'chevron'  => true,
                'attrs'    => ['data-client-row' => $c['slug']],
            ]);
        }
        echo insetListClose('Badges show pages still waiting for the client\'s review.');
    }
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

$cid       = (int)$client['id'];
$visibleTo = $admin ? 'admin' : 'client';
$q = isset($_GET['q']) && is_string($_GET['q']) ? trim(mb_substr($_GET['q'], 0, 120)) : '';

// ---------------------------------------------------------------------
// Segments
// ---------------------------------------------------------------------
$segments = $admin
    ? ['draft' => 'Draft', 'pending' => 'To Review', 'approved' => 'Approved', 'live' => 'Live', 'denied' => 'Needs changes']
    : ['pending' => 'To Review', 'approved' => 'Approved', 'live' => 'Live'];
$segment = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if ($segment !== 'all' && !isset($segments[$segment])) { $segment = 'pending'; }

/** Comments (activity_log 'commented') + approved_at + files for a set of rows — batched; oldest first. */
function pagesAttachRelations(PDO $pdo, array &$rows): void {
    if (!$rows) return;
    $byId = [];
    foreach ($rows as &$r) { $r['comments'] = []; $r['approved_at'] = null; $r['files'] = []; $byId[(int)$r['id']] = &$r; }
    unset($r);
    $ids = array_keys($byId);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT id, page_id, filename, size, created_at FROM page_files WHERE page_id IN ($ph) ORDER BY filename ASC");
        $st->execute($ids);
        foreach ($st->fetchAll() as $f) {
            $pid = (int)$f['page_id'];
            if (isset($byId[$pid])) $byId[$pid]['files'][] = ['id' => (int)$f['id'], 'page_id' => $pid, 'filename' => (string)$f['filename'], 'size' => (int)$f['size'], 'created_at' => $f['created_at']];
        }
        foreach ($byId as &$r) {
            usort($r['files'], static function ($a, $b) { return strnatcasecmp((string)$a['filename'], (string)$b['filename']); });
        }
        unset($r);
    } catch (Throwable $e) {
        error_log('pages files query failed: ' . $e->getMessage());
    }
    if (!hasActivityLog($pdo)) return;
    try {
        $st = $pdo->prepare("
            SELECT entity_id, actor, detail, created_at FROM activity_log
            WHERE entity_type = 'page' AND action = 'commented' AND entity_id IN ($ph)
              AND detail IS NOT NULL AND detail <> ''
            ORDER BY created_at ASC, id ASC
        ");
        $st->execute($ids);
        $all = $st->fetchAll();
        usort($all, static function ($a, $b) {
            return strcmp((string)$a['created_at'], (string)$b['created_at']);
        });
        foreach ($all as $row) {
            $eid = (int)$row['entity_id'];
            if (isset($byId[$eid])) $byId[$eid]['comments'][] = ['actor' => $row['actor'], 'detail' => $row['detail'], 'created_at' => $row['created_at']];
        }
        $st = $pdo->prepare("
            SELECT entity_id, MAX(created_at) AS at FROM activity_log
            WHERE entity_type = 'page' AND action = 'approved' AND entity_id IN ($ph)
            GROUP BY entity_id
        ");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) {
            $eid = (int)$row['entity_id'];
            if (isset($byId[$eid])) $byId[$eid]['approved_at'] = $row['at'];
        }
    } catch (Throwable $e) {
        error_log('pages comments query failed: ' . $e->getMessage());
    }
}

/** May this viewer open the row? (SQL already hides them from lists; this guards deep links.) */
function pageVisibleTo(array $page, bool $admin): bool {
    if ($admin) return true;
    return !empty($page['live']) || in_array((string)$page['status'], ['pending', 'approved'], true);
}

// ---------------------------------------------------------------------
// Direct page (deep link or partial): the segment follows the row.
// ---------------------------------------------------------------------
$directPage = null;
if ($pageParam > 0 && $hasTable) {
    $row = pageById($pdo, $pageParam);
    if ($row && (int)$row['company_id'] === $cid && pageVisibleTo($row, $admin)) {
        $directPage = $row;
    }
}

if ($isPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    if (!$directPage) {
        http_response_code(404);
        echo '<div class="ui-empty">This page is no longer available.</div>';
        exit;
    }
    $one = [$directPage];
    pagesAttachRelations($pdo, $one);
    echo renderPageDetail($one[0], ['admin' => $admin, 'company' => $client]);
    exit;
}

if ($directPage) {
    $segment = pageStatusKey($directPage);
    if (!isset($segments[$segment])) { $segment = 'pending'; }
}

// ---------------------------------------------------------------------
// Rows: one query under the search filter, then counts + the segment in PHP
// ---------------------------------------------------------------------
$filtered = $hasTable ? pagesForCompany($pdo, $cid, ['q' => $q, 'visibleTo' => $visibleTo]) : [];
$counts   = ['draft' => 0, 'pending' => 0, 'approved' => 0, 'live' => 0, 'denied' => 0, 'all' => 0];
foreach ($filtered as $r) { $counts[pageStatusKey($r)]++; $counts['all']++; }

$pages = $segment === 'all' ? $filtered : array_values(array_filter($filtered, static function ($r) use ($segment) {
    return pageStatusKey($r) === $segment;
}));
pagesAttachRelations($pdo, $pages);

// ---------------------------------------------------------------------
// Needs changes = the admin work queue (latest client note, counts, sort)
// ---------------------------------------------------------------------
$isQueue = $admin && $segment === 'denied';
if ($isQueue && $pages) {
    $deniedAt = [];
    if (hasActivityLog($pdo)) {
        $ids = array_map('intval', array_column($pages, 'id'));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare("
                SELECT entity_id, MAX(created_at) AS at FROM activity_log
                WHERE entity_type = 'page' AND action = 'denied' AND entity_id IN ($ph)
                GROUP BY entity_id
            ");
            $st->execute($ids);
            foreach ($st->fetchAll() as $row) { $deniedAt[(int)$row['entity_id']] = (string)$row['at']; }
        } catch (Throwable $e) {
            error_log('pages queue denied_at query failed: ' . $e->getMessage());
        }
    }
    foreach ($pages as &$p) {
        $p['queue'] = pagesQueueInfo($p, $deniedAt[(int)$p['id']] ?? null, $client);
    }
    unset($p);
    usort($pages, static function ($a, $b) {
        return ($b['queue']['activity_ts'] <=> $a['queue']['activity_ts']) ?: ((int)$b['id'] <=> (int)$a['id']);
    });
}

/** Queue facts for one denied page (same shape as emails.php's emailsQueueInfo). */
function pagesQueueInfo(array $page, ?string $deniedAt, ?array $client): array {
    $comments   = is_array($page['comments'] ?? null) ? $page['comments'] : [];
    $clientRows = array_values(array_filter($comments, static function ($c) {
        return strtolower(trim((string)($c['actor'] ?? ''))) === 'client';
    }));
    $latestClient = $clientRows ? $clientRows[count($clientRows) - 1] : null;
    $latestAny    = $comments ? $comments[count($comments) - 1] : null;
    $note         = $latestClient ?: $latestAny;
    $noteActor    = $note ? strtolower(trim((string)($note['actor'] ?? ''))) : '';
    $who          = $noteActor === 'client' ? (string)($client['name'] ?? 'Client')
                  : ($noteActor === 'admin' ? 'Joust' : 'Note');
    $ts = 0;
    foreach ([$latestClient['created_at'] ?? null, $deniedAt, $latestAny['created_at'] ?? null, $page['updated_at'] ?? null] as $cand) {
        $t = $cand ? strtotime((string)$cand) : false;
        if ($t) { $ts = max($ts, $t); }
    }
    return [
        'note'         => $note ? trim((string)$note['detail']) : '',
        'note_who'     => $who,
        'note_at'      => $note ? (string)$note['created_at'] : ($deniedAt ?? ''),
        'client_count' => count($clientRows),
        'denied_at'    => $deniedAt ?? '',
        'activity_ts'  => $ts,
    ];
}

$inList = false;
foreach ($pages as $p) { if ((int)$p['id'] === $pageParam) { $inList = true; break; } }
if ($directPage && !$inList) {
    // The deep-linked row is outside the current search filter: still open it.
    $one = [$directPage];
    pagesAttachRelations($pdo, $one);
    $directPage = $one[0];
} else {
    $directPage = null;
}

$inlineLimit   = 40;
$inlineDetails = count($pages) <= $inlineLimit;

// ---------------------------------------------------------------------
// URL helpers (q persists across segment switches)
// ---------------------------------------------------------------------
$pageUrlFn = function (array $extra = []) use ($q) {
    return pagesUrl(array_merge(['q' => $q !== '' ? $q : null], $extra));
};
$segmentUrl = function (string $seg) use ($pageUrlFn) {
    return $pageUrlFn(['status' => $seg]);
};

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

$filterNote = $q !== '' ? ' matching your search' : '';
$emptyCopy = [
    'pending'  => 'Nothing to review' . $filterNote . '.',
    'approved' => 'No approved pages waiting to go live' . $filterNote . '.',
    'live'     => 'Nothing is live yet' . $filterNote . '.',
    'denied'   => 'Nothing needs changes' . $filterNote . '.',
    'draft'    => 'No drafts' . $filterNote . '.',
    'all'      => 'No pages' . $filterNote . '.',
];
$segLabel = $segment === 'all' ? 'All' : $segments[$segment];

// ---------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------
$pageTitle   = 'Pages';
$activeTab   = 'pages';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/pages.css')) . '">';
$bodyClass   = 'page-pages';

$pagesConfig = [
    'base'        => basePath(),
    'endpoint'    => basePath() . '/page-status.php',
    'partialUrl'  => $pageUrlFn(['status' => $segment, 'page' => '__ID__', 'partial' => 1]),
    'segment'     => $segment,
    'counts'      => $counts,
    'inline'      => $inlineDetails,
    'admin'       => $admin,
    'openPage'    => $directPage || $inList ? $pageParam : 0,
    'queue'       => $isQueue,
    'segmentUrls' => array_combine(array_keys($segments), array_map($segmentUrl, array_keys($segments))),
];
$footExtra = '<script>window.PagesConfig = ' . json_encode($pagesConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
           . '<script src="' . h(staticUrl('js/pages.js')) . '" defer></script>';

include __DIR__ . '/partials/layout-top.php';
?>

<div class="posts-toolbar pages-toolbar">
  <?= segmented($segItems, ['label' => 'Page status']) ?>
</div>

<?php if ($q !== '' || count($filtered) > 6): ?>
<div class="pages-filters" data-pages-filters>
  <form class="pages-search" method="get" action="<?= h(pagePath('pages')) ?>" role="search" data-pages-search>
    <?php if (!empty($clientSlug)): ?><input type="hidden" name="client" value="<?= h($clientSlug) ?>"><?php endif; ?>
    <input type="hidden" name="status" value="<?= h($segment) ?>">
    <label class="ui-visually-hidden" for="pages-q">Search pages</label>
    <input class="ui-input pages-search-input" type="search" id="pages-q" name="q" value="<?= h($q) ?>" placeholder="Search title or slug" autocomplete="off" enterkeyhint="search">
    <?php if ($q !== ''): ?>
      <a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h(pagesUrl(['status' => $segment])) ?>">Clear</a>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php if (!$hasTable): ?>
  <div class="ui-empty posts-empty" data-pages-empty>Pages are not set up yet.</div>
<?php elseif (!$pages): ?>
  <div class="ui-empty posts-empty" data-pages-empty>
    <?= h($emptyCopy[$segment]) ?>
    <?php if ($segment === 'pending' && $counts['approved'] + $counts['live'] > 0): ?>
      <div class="posts-empty-sub">You're caught up.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<section class="ui-list-group posts-group pages-group" data-pages-list data-segment="<?= h($segment) ?>"<?= !$pages ? ' hidden' : '' ?>>
  <h2 class="ui-list-header">
    <span data-segment-count><?= (int)$counts[$segment] ?></span> <?= h(strtolower($segLabel)) ?><?= $segment === 'all' ? ' pages' : '' ?>
  </h2>
  <ul class="ui-list posts-list pages-list" role="list" data-pages-items>
    <?php foreach ($pages as $page):
        $pid      = (int)$page['id'];
        $key      = pageStatusKey($page);
        $live     = !empty($page['live']);
        $title    = trim((string)$page['title']);
        $slug     = trim((string)$page['slug']);
        $source   = strtolower((string)($page['source'] ?? 'upload')) === 'url' ? 'url' : 'upload';
        $desc     = trim(preg_split('/\r\n|\r|\n/', (string)($page['description'] ?? ''))[0] ?? '');
        if (mb_strlen($desc) > 110) { $desc = rtrim(mb_substr($desc, 0, 109)) . '…'; }
        $nCmt     = count($page['comments']);
        $nFiles   = count($page['files']);
        $updated  = (string)($page['updated_at'] ?? '');
        $href     = $pageUrlFn(['status' => $segment, 'page' => $pid]);
        $queue    = $isQueue ? ($page['queue'] ?? null) : null;
        $qNote    = $queue ? $queue['note'] : '';
        if (mb_strlen($qNote) > 220) { $qNote = rtrim(mb_substr($qNote, 0, 219)) . '…'; }
        $qWhen    = $queue && $queue['note_at'] !== '' ? relativeTime($queue['note_at']) : '';
        $qAbs     = $queue && $queue['note_at'] !== '' ? absoluteTime($queue['note_at']) : '';
        $qCount   = $queue ? (int)$queue['client_count'] : 0;
        $rowTitle = $title !== '' ? $title : ($slug !== '' ? $slug : 'Page #' . $pid);
    ?>
      <li class="pl-item pgl-item<?= $queue ? ' pl-item--queue' : '' ?>" id="page-<?= $pid ?>" data-page-item="<?= $pid ?>" data-id="<?= $pid ?>"
          data-status="<?= h($page['status']) ?>" data-live="<?= $live ? '1' : '0' ?>" data-key="<?= h($key) ?>"
          data-title="<?= h($rowTitle) ?>"<?= $queue ? ' data-queue' : ' data-swipe' ?>>
        <?php if (!$queue): ?>
        <div class="pl-swipe pl-swipe--approve" aria-hidden="true"><?= icon('checkmark') ?><span>Approve</span></div>
        <div class="pl-swipe pl-swipe--deny" aria-hidden="true"><?= icon('xmark') ?><span>Needs changes</span></div>
        <?php endif; ?>
        <a class="ui-row ui-row--leading pl-card pgl-card" href="<?= h($href) ?>" data-page-open="<?= $pid ?>">
          <div class="ui-row-leading pgl-tile pgl-tile--<?= h($key) ?>" aria-hidden="true"><?= icon('page') ?></div>
          <div class="ui-row-body">
            <div class="pl-top">
              <div class="ui-row-title pl-title"><?= h($rowTitle) ?></div>
              <span class="pl-when">
                <?php if ($updated !== '' && relativeTime($updated) !== ''): ?><time class="pl-date" datetime="<?= h(date('Y-m-d', strtotime($updated) ?: time())) ?>" title="<?= h(absoluteTime($updated)) ?>"><?= h(relativeTime($updated)) ?></time><?php endif; ?>
              </span>
            </div>
            <div class="pl-caption pgl-slug"><code><?= h($slug !== '' ? '/' . $slug : '—') ?></code><?php if ($desc !== ''): ?> <span class="pgl-desc"><?= h($desc) ?></span><?php endif; ?></div>
            <?php if ($queue): ?>
              <div class="pl-note<?= $qNote === '' ? ' pl-note--empty' : '' ?>" data-queue-note>
                <?php if ($qNote !== ''): ?>
                  <q><?= h($qNote) ?></q>
                  <span class="pl-note-meta"><?= h($queue['note_who']) ?><?php if ($qWhen !== ''): ?> · <time title="<?= h($qAbs) ?>"><?= h($qWhen) ?></time><?php endif; ?></span>
                <?php else: ?>
                  <span>No note left<?php if ($qWhen !== ''): ?> · flagged <time title="<?= h($qAbs) ?>"><?= h($qWhen) ?></time><?php endif; ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="pl-meta">
              <?= pageStatusPill($page) ?>
              <span class="pl-meta-item"><span class="pl-meta-sep">·</span><span class="pg-source pg-source--<?= $source ?>"><?= $source === 'url' ? 'URL' : 'Upload' ?></span></span>
              <?php if ($source === 'upload'): ?>
                <span class="pl-meta-item"><span class="pl-meta-sep">·</span><span data-file-count-for="<?= $pid ?>"><?= $nFiles ?> <?= $nFiles === 1 ? 'file' : 'files' ?></span></span>
              <?php endif; ?>
              <?php if ($queue): ?>
                <span class="pl-meta-item"><span class="pl-meta-sep">·</span><span data-queue-count="<?= $pid ?>"><?= $qCount > 0 ? $qCount . ' client ' . ($qCount === 1 ? 'comment' : 'comments') : 'no client comments' ?></span></span>
              <?php else: ?>
                <span class="pl-meta-item"><span class="pl-meta-sep">·</span><span data-comment-count-for="<?= $pid ?>"><?= $nCmt ?> <?= $nCmt === 1 ? 'comment' : 'comments' ?></span></span>
              <?php endif; ?>
            </div>
          </div>
          <?= icon('chevron-right', 'ui-row-chevron') ?>
        </a>
        <?php if ($queue): ?>
          <div class="pl-queue-actions">
            <button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-page-open="<?= $pid ?>">Open</button>
            <button type="button" class="ui-btn ui-btn--tinted ui-btn--sm" data-resubmit="<?= $pid ?>" title="Move this page back to the client's To Review list">Resubmit for review</button>
          </div>
        <?php endif; ?>
        <?php if ($inlineDetails): ?>
          <template data-page-template="<?= $pid ?>"><?= renderPageDetail($page, ['admin' => $admin, 'company' => $client]) ?></template>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($segment === 'pending'): ?>
    <p class="ui-list-footer posts-hint">Swipe right to approve, left to request changes. Tap a page for the full preview.</p>
  <?php elseif ($isQueue): ?>
    <p class="ui-list-footer">Newest client activity first. Open a page for the full thread; Resubmit sends it back to the client's To Review list.</p>
  <?php endif; ?>
</section>

<?php if ($directPage): ?>
  <template data-page-template="<?= (int)$directPage['id'] ?>"><?= renderPageDetail($directPage, ['admin' => $admin, 'company' => $client]) ?></template>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
