<?php
/**
 * Emails — client review + Joust work queue (scratchpad emails-design.md §6).
 * The email twin of posts.php: same chrome, segments, sheet and swipe.
 *
 *   ?client=privacybee                     scope (helpers.php)
 *   &status=pending|approved|live          segment — default pending
 *          |draft|denied                   admin only (client → falls back to pending)
 *          |all                            every row the viewer may see (Studio's "Open emails")
 *   &group=free,pro  (or group[]=free)     group filter chips (ANY of); persists across segments
 *   &q=welcome                             substring over code / title / subject
 *   &email=<id>                            open that email's detail on load (segment follows the row)
 *   &email=<id>&partial=1                  return ONLY the detail partial HTML (lists > 40 items)
 *
 * Segments (display keys from emails-lib.php — live=1 always wins):
 *   Draft (admin) · To Review · Approved · Live · Needs changes (admin work queue)
 * Clients never receive draft or denied rows — filtered in SQL
 * (emailsForCompany(..., ['visibleTo' => 'client'])), exactly like posts hide denied.
 *
 * Needs changes = Joust's work queue: each row carries the client's latest note
 * (deny note or newest comment — both 'commented' activity rows), a client-comment
 * count and Open / Resubmit for review; newest client activity first.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/partials/components/comment-thread.php';
require_once __DIR__ . '/partials/components/email-detail.php';

/** Escape helper (page-local by convention; partials use esc()). */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$admin      = isAdmin();
$isPartial  = !empty($_GET['partial']);
$emailParam = (int)($_GET['email'] ?? 0);
$hasTable   = hasEmailsTable($pdo);

// ---------------------------------------------------------------------
// No client scope: partial → 404, client seat → 400, admin → chooser.
// ---------------------------------------------------------------------
if (!$client) {
    if ($isPartial) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="ui-empty">This email is no longer available.</div>';
        exit;
    }
    if (!$admin) {
        http_response_code(400);
        $pageTitle    = 'Emails';
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
            if (!companyHasEmails($c, $pdo)) continue;
            $c['counts'] = emailCounts($pdo, (int)$c['id']);
            $companies[] = $c;
        }
    }
    $pageTitle   = 'Emails';
    $navSubtitle = 'Choose a client';
    $activeTab   = 'emails';
    $navTrailing = '';
    $headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
                 . '<link rel="stylesheet" href="' . h(staticUrl('css/emails.css')) . '">';
    $bodyClass   = 'page-emails page-emails-chooser';
    include __DIR__ . '/partials/layout-top.php';
    if (!$hasTable) {
        echo '<div class="ui-empty">Emails are not set up yet — run <code>migrate.php</code> first.</div>';
    } elseif (!$companies) {
        echo '<div class="ui-empty">No client has the Emails module yet. Enable it in Studio or add the first email.</div>';
    } else {
        echo insetListOpen('Clients');
        foreach ($companies as $c) {
            $pending = (int)$c['counts']['pending'];
            $total   = (int)$c['counts']['total'];
            echo insetRow([
                'href'     => clientUrl('emails.php', ['client' => $c['slug']]),
                'leading'  => clientAvatar($c, 'ui-avatar--lg'),
                'title'    => $c['name'],
                'subtitle' => $pending > 0 ? $pending . ' to review' : ($total > 0 ? 'Nothing waiting' : 'No emails yet'),
                'trailing' => $pending > 0 ? '<span class="ui-badge">' . $pending . '</span>' : '',
                'chevron'  => true,
                'attrs'    => ['data-client-row' => $c['slug']],
            ]);
        }
        echo insetListClose('Badges show emails still waiting for the client\'s review.');
    }
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

$cid       = (int)$client['id'];
$visibleTo = $admin ? 'admin' : 'client';

// ---------------------------------------------------------------------
// Filters: group chips (multi, `group=a,b` or repeated) + q search
// ---------------------------------------------------------------------
$allGroups  = $hasTable ? emailGroupsForCompany($pdo, $cid) : [];
$knownSlugs = array_map(static function ($g) { return (string)$g['slug']; }, $allGroups);
$rawGroups  = $_GET['group'] ?? [];
if (!is_array($rawGroups)) $rawGroups = [$rawGroups];
$groupSlugs = [];
foreach ($rawGroups as $g) {
    if (!is_string($g)) continue;
    foreach (explode(',', $g) as $part) {
        $s = emailSlugify($part);
        if ($s !== '' && in_array($s, $knownSlugs, true)) $groupSlugs[] = $s;
    }
}
$groupSlugs = array_values(array_unique($groupSlugs));
$q = isset($_GET['q']) && is_string($_GET['q']) ? trim(mb_substr($_GET['q'], 0, 120)) : '';

// ---------------------------------------------------------------------
// Segments
// ---------------------------------------------------------------------
$segments = $admin
    ? ['draft' => 'Draft', 'pending' => 'To Review', 'approved' => 'Approved', 'live' => 'Live', 'denied' => 'Needs changes']
    : ['pending' => 'To Review', 'approved' => 'Approved', 'live' => 'Live'];
$segment = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if ($segment !== 'all' && !isset($segments[$segment])) { $segment = 'pending'; }

/** Comments (activity_log 'commented') for a set of rows — one query; oldest first. */
function emailsAttachComments(PDO $pdo, array &$rows): void {
    if (!$rows) return;
    $byId = [];
    foreach ($rows as &$r) { $r['comments'] = []; $r['approved_at'] = null; $byId[(int)$r['id']] = &$r; }
    unset($r);
    if (!hasActivityLog($pdo)) return;
    $ids = array_keys($byId);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("
            SELECT entity_id, actor, detail, created_at FROM activity_log
            WHERE entity_type = 'email' AND action = 'commented' AND entity_id IN ($ph)
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
            WHERE entity_type = 'email' AND action = 'approved' AND entity_id IN ($ph)
            GROUP BY entity_id
        ");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) {
            $eid = (int)$row['entity_id'];
            if (isset($byId[$eid])) $byId[$eid]['approved_at'] = $row['at'];
        }
    } catch (Throwable $e) {
        error_log('emails comments query failed: ' . $e->getMessage());
    }
}

/** May this viewer open the row? (SQL already hides them from lists; this guards deep links.) */
function emailVisibleTo(array $email, bool $admin): bool {
    if ($admin) return true;
    return !empty($email['live']) || in_array((string)$email['status'], ['pending', 'approved'], true);
}

// ---------------------------------------------------------------------
// Direct email (deep link or partial): the segment follows the row.
// ---------------------------------------------------------------------
$directEmail = null;
if ($emailParam > 0 && $hasTable) {
    $row = emailById($pdo, $emailParam);
    if ($row && (int)$row['company_id'] === $cid && emailVisibleTo($row, $admin)) {
        $directEmail = $row;
    }
}

if ($isPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    if (!$directEmail) {
        http_response_code(404);
        echo '<div class="ui-empty">This email is no longer available.</div>';
        exit;
    }
    $one = [$directEmail];
    emailsAttachComments($pdo, $one);
    echo renderEmailDetail($one[0], ['admin' => $admin]);
    exit;
}

if ($directEmail) {
    $segment = emailStatusKey($directEmail);
    if (!isset($segments[$segment])) { $segment = 'pending'; }   // never happens for the client (SQL-hidden keys) but keep the link safe
}

// ---------------------------------------------------------------------
// Rows: one query under the chip/search filter, then counts + the segment in PHP
// ---------------------------------------------------------------------
$filtered = $hasTable ? emailsForCompany($pdo, $cid, ['group' => $groupSlugs, 'q' => $q, 'visibleTo' => $visibleTo]) : [];
$counts   = ['draft' => 0, 'pending' => 0, 'approved' => 0, 'live' => 0, 'denied' => 0, 'all' => 0];
foreach ($filtered as $r) { $counts[emailStatusKey($r)]++; $counts['all']++; }

$emails = $segment === 'all' ? $filtered : array_values(array_filter($filtered, static function ($r) use ($segment) {
    return emailStatusKey($r) === $segment;
}));
emailsAttachComments($pdo, $emails);

// ---------------------------------------------------------------------
// Needs changes = the admin work queue (latest client note, counts, sort)
// ---------------------------------------------------------------------
$isQueue = $admin && $segment === 'denied';
if ($isQueue && $emails) {
    $deniedAt = [];
    if (hasActivityLog($pdo)) {
        $ids = array_map('intval', array_column($emails, 'id'));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare("
                SELECT entity_id, MAX(created_at) AS at FROM activity_log
                WHERE entity_type = 'email' AND action = 'denied' AND entity_id IN ($ph)
                GROUP BY entity_id
            ");
            $st->execute($ids);
            foreach ($st->fetchAll() as $row) { $deniedAt[(int)$row['entity_id']] = (string)$row['at']; }
        } catch (Throwable $e) {
            error_log('emails queue denied_at query failed: ' . $e->getMessage());
        }
    }
    foreach ($emails as &$e) {
        $e['queue'] = emailsQueueInfo($e, $deniedAt[(int)$e['id']] ?? null, $client);
    }
    unset($e);
    usort($emails, static function ($a, $b) {
        return ($b['queue']['activity_ts'] <=> $a['queue']['activity_ts']) ?: ((int)$b['id'] <=> (int)$a['id']);
    });
}

/** Queue facts for one denied email (same shape as posts.php's postsQueueInfo). */
function emailsQueueInfo(array $email, ?string $deniedAt, ?array $client): array {
    $comments   = is_array($email['comments'] ?? null) ? $email['comments'] : [];
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
    foreach ([$latestClient['created_at'] ?? null, $deniedAt, $latestAny['created_at'] ?? null, $email['updated_at'] ?? null] as $cand) {
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
foreach ($emails as $e) { if ((int)$e['id'] === $emailParam) { $inList = true; break; } }
if ($directEmail && !$inList) {
    // The deep-linked row is outside the current chip/search filter: still open it.
    $one = [$directEmail];
    emailsAttachComments($pdo, $one);
    $directEmail = $one[0];
} else {
    $directEmail = null;
}

$inlineLimit   = 40;
$inlineDetails = count($emails) <= $inlineLimit;

// ---------------------------------------------------------------------
// URL helpers (chips + q persist across segment switches)
// ---------------------------------------------------------------------
$groupParam = $groupSlugs ? implode(',', $groupSlugs) : null;
$pageUrl = function (array $extra = []) use ($groupParam, $q) {
    return emailsUrl(array_merge(['group' => $groupParam, 'q' => $q !== '' ? $q : null], $extra));
};
$segmentUrl = function (string $seg) use ($pageUrl) {
    return $pageUrl(['status' => $seg]);
};
$chipUrl = function (string $slug) use ($groupSlugs, $segment, $q) {
    $set = in_array($slug, $groupSlugs, true)
        ? array_values(array_diff($groupSlugs, [$slug]))
        : array_merge($groupSlugs, [$slug]);
    return emailsUrl(['status' => $segment, 'group' => $set ? implode(',', $set) : null, 'q' => $q !== '' ? $q : null]);
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

$filterNote = ($groupSlugs || $q !== '') ? ' matching your filters' : '';
$emptyCopy = [
    'pending'  => 'Nothing to review' . $filterNote . '.',
    'approved' => 'No approved emails waiting to go live' . $filterNote . '.',
    'live'     => 'Nothing is live yet' . $filterNote . '.',
    'denied'   => 'Nothing needs changes' . $filterNote . '.',
    'draft'    => 'No drafts' . $filterNote . '.',
    'all'      => 'No emails' . $filterNote . '.',
];
$segLabel = $segment === 'all' ? 'All' : $segments[$segment];

// ---------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------
$pageTitle   = 'Emails';
$activeTab   = 'emails';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/emails.css')) . '">';
$bodyClass   = 'page-emails';

$emailsConfig = [
    'base'        => basePath(),
    'endpoint'    => basePath() . '/email-status.php',
    'partialUrl'  => $pageUrl(['status' => $segment, 'email' => '__ID__', 'partial' => 1]),
    'segment'     => $segment,
    'counts'      => $counts,
    'inline'      => $inlineDetails,
    'admin'       => $admin,
    'openEmail'   => $directEmail || $inList ? $emailParam : 0,
    'queue'       => $isQueue,
    'segmentUrls' => array_combine(array_keys($segments), array_map($segmentUrl, array_keys($segments))),
];
$footExtra = '<script>window.EmailsConfig = ' . json_encode($emailsConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
           . '<script src="' . h(staticUrl('js/emails.js')) . '" defer></script>';

include __DIR__ . '/partials/layout-top.php';
?>

<div class="posts-toolbar emails-toolbar">
  <?= segmented($segItems, ['label' => 'Email status']) ?>
</div>

<?php if ($allGroups || $q !== ''): ?>
<div class="emails-filters" data-emails-filters>
  <?php if ($allGroups): ?>
  <div class="emails-chips" role="group" aria-label="Filter by group" data-group-chips>
    <?php foreach ($allGroups as $g):
        $on = in_array((string)$g['slug'], $groupSlugs, true); ?>
      <a class="em-chip<?= $on ? ' is-active' : '' ?>" href="<?= h($chipUrl((string)$g['slug'])) ?>" data-group-chip="<?= h($g['slug']) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= h($g['name']) ?></a>
    <?php endforeach; ?>
    <?php if ($groupSlugs): ?>
      <a class="em-chip em-chip--clear" href="<?= h(emailsUrl(['status' => $segment, 'q' => $q !== '' ? $q : null])) ?>" data-group-clear><?= icon('xmark') ?>Clear</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <form class="emails-search" method="get" action="<?= h(pagePath('emails')) ?>" role="search" data-emails-search>
    <?php if (!empty($clientSlug)): ?><input type="hidden" name="client" value="<?= h($clientSlug) ?>"><?php endif; ?>
    <input type="hidden" name="status" value="<?= h($segment) ?>">
    <?php if ($groupParam !== null): ?><input type="hidden" name="group" value="<?= h($groupParam) ?>"><?php endif; ?>
    <label class="ui-visually-hidden" for="emails-q">Search emails</label>
    <input class="ui-input emails-search-input" type="search" id="emails-q" name="q" value="<?= h($q) ?>" placeholder="Search code, title or subject" autocomplete="off" enterkeyhint="search">
    <?php if ($q !== ''): ?>
      <a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h(emailsUrl(['status' => $segment, 'group' => $groupParam])) ?>">Clear</a>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php if (!$hasTable): ?>
  <div class="ui-empty posts-empty" data-emails-empty>Emails are not set up yet.</div>
<?php elseif (!$emails): ?>
  <div class="ui-empty posts-empty" data-emails-empty>
    <?= h($emptyCopy[$segment]) ?>
    <?php if ($segment === 'pending' && $counts['approved'] + $counts['live'] > 0): ?>
      <div class="posts-empty-sub">You're caught up.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<section class="ui-list-group posts-group emails-group" data-emails-list data-segment="<?= h($segment) ?>"<?= !$emails ? ' hidden' : '' ?>>
  <h2 class="ui-list-header">
    <span data-segment-count><?= (int)$counts[$segment] ?></span> <?= h(strtolower($segLabel)) ?><?= $segment === 'all' ? ' emails' : '' ?>
    <?php if ($groupSlugs): ?> · <?= h(implode(', ', array_map(static function ($s) use ($allGroups) {
        foreach ($allGroups as $g) if ($g['slug'] === $s) return $g['name'];
        return $s;
    }, $groupSlugs))) ?><?php endif; ?>
  </h2>
  <ul class="ui-list posts-list emails-list" role="list" data-emails-items>
    <?php foreach ($emails as $email):
        $eid      = (int)$email['id'];
        $key      = emailStatusKey($email);
        $live     = !empty($email['live']);
        $code     = trim((string)$email['code']);
        $title    = trim((string)$email['title']);
        $subject  = trim((string)$email['subject']);
        $trigger  = trim((string)($email['trigger_text'] ?? ''));
        $trigLn   = trim(preg_split('/\r\n|\r|\n/', $trigger)[0] ?? '');
        if (mb_strlen($trigLn) > 90) { $trigLn = rtrim(mb_substr($trigLn, 0, 89)) . '…'; }
        $prio     = strtolower(trim((string)($email['priority'] ?? '')));
        $prioLbl  = emailPriorityLabel($prio);
        $nCmt     = count($email['comments']);
        $sendRaw  = trim((string)($email['send_at'] ?? ''));
        $ts       = ($sendRaw !== '' && $sendRaw !== '0000-00-00') ? strtotime($sendRaw) : false;
        $dateLbl  = $ts ? date('M j', $ts) : '';
        $isPast   = emailIsPast($email);
        $href     = $pageUrl(['status' => $segment, 'email' => $eid]);
        $queue    = $isQueue ? ($email['queue'] ?? null) : null;
        $qNote    = $queue ? $queue['note'] : '';
        if (mb_strlen($qNote) > 220) { $qNote = rtrim(mb_substr($qNote, 0, 219)) . '…'; }
        $qWhen    = $queue && $queue['note_at'] !== '' ? relativeTime($queue['note_at']) : '';
        $qAbs     = $queue && $queue['note_at'] !== '' ? absoluteTime($queue['note_at']) : '';
        $qCount   = $queue ? (int)$queue['client_count'] : 0;
        $rowTitle = $title !== '' ? $title : ($code !== '' ? $code : 'Email #' . $eid);
    ?>
      <li class="pl-item el-item<?= $queue ? ' pl-item--queue' : '' ?><?= $isPast ? ' pl-item--past' : '' ?>" id="email-<?= $eid ?>" data-email-item="<?= $eid ?>" data-id="<?= $eid ?>"
          data-status="<?= h($email['status']) ?>" data-live="<?= $live ? '1' : '0' ?>" data-key="<?= h($key) ?>"<?= $isPast ? ' data-past="1"' : '' ?>
          data-title="<?= h($rowTitle) ?>"<?= $queue ? ' data-queue' : ' data-swipe' ?>>
        <?php if (!$queue): ?>
        <div class="pl-swipe pl-swipe--approve" aria-hidden="true"><?= icon('checkmark') ?><span>Approve</span></div>
        <div class="pl-swipe pl-swipe--deny" aria-hidden="true"><?= icon('xmark') ?><span>Needs changes</span></div>
        <?php endif; ?>
        <a class="ui-row ui-row--leading pl-card el-card" href="<?= h($href) ?>" data-email-open="<?= $eid ?>">
          <div class="ui-row-leading el-code-tile el-code-tile--<?= h($key) ?>" aria-hidden="true">
            <span class="el-code"><?= h($code !== '' ? $code : '—') ?></span>
          </div>
          <div class="ui-row-body">
            <div class="pl-top">
              <div class="ui-row-title pl-title"><?= h($rowTitle) ?></div>
              <span class="pl-when">
                <?php if ($isPast): ?><span class="pl-past" title="This email's send date has passed">Past</span><?php endif; ?>
                <?php if ($dateLbl !== ''): ?><time class="pl-date" datetime="<?= h(date('Y-m-d', $ts)) ?>"><?= h($dateLbl) ?></time><?php endif; ?>
              </span>
            </div>
            <div class="pl-caption el-subject<?= $subject === '' ? ' el-subject--empty' : '' ?>"><?= $subject !== '' ? h($subject) : 'No subject yet' ?></div>
            <?php if ($trigLn !== ''): ?>
              <div class="el-trigger"><?= icon('calendar', 'el-trigger-icon') ?><span><?= h($trigLn) ?></span></div>
            <?php endif; ?>
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
              <?= emailStatusPill($email) ?>
              <?php if ($prioLbl !== ''): ?>
                <span class="pl-meta-item el-prio el-prio--<?= h($prio) ?>"><span class="pl-meta-sep">·</span><span class="ui-dot ed-dot ed-dot--<?= h($prio) ?>"></span><span><?= h($prioLbl) ?></span></span>
              <?php endif; ?>
              <?php if ($email['groups']): ?>
                <span class="pl-meta-item el-groups"><span class="pl-meta-sep">·</span><?php foreach ($email['groups'] as $g): ?><span class="el-tag"><?= h($g['name']) ?></span><?php endforeach; ?></span>
              <?php endif; ?>
              <?php if ($queue): ?>
                <span class="pl-meta-item"><span class="pl-meta-sep">·</span><span data-queue-count="<?= $eid ?>"><?= $qCount > 0 ? $qCount . ' client ' . ($qCount === 1 ? 'comment' : 'comments') : 'no client comments' ?></span></span>
              <?php else: ?>
                <span class="pl-meta-item"><span class="pl-meta-sep">·</span><span data-comment-count-for="<?= $eid ?>"><?= $nCmt ?> <?= $nCmt === 1 ? 'comment' : 'comments' ?></span></span>
              <?php endif; ?>
            </div>
          </div>
          <?= icon('chevron-right', 'ui-row-chevron') ?>
        </a>
        <?php if ($queue): ?>
          <div class="pl-queue-actions">
            <button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-email-open="<?= $eid ?>">Open</button>
            <button type="button" class="ui-btn ui-btn--tinted ui-btn--sm" data-resubmit="<?= $eid ?>" title="Move this email back to the client's To Review list">Resubmit for review</button>
          </div>
        <?php endif; ?>
        <?php if ($inlineDetails): ?>
          <template data-email-template="<?= $eid ?>"><?= renderEmailDetail($email, ['admin' => $admin]) ?></template>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($segment === 'pending'): ?>
    <p class="ui-list-footer posts-hint">Swipe right to approve, left to request changes. Tap an email for the full preview.</p>
  <?php elseif ($isQueue): ?>
    <p class="ui-list-footer">Newest client activity first. Open an email for the full thread; Resubmit sends it back to the client's To Review list.</p>
  <?php endif; ?>
</section>

<?php if ($directEmail): ?>
  <template data-email-template="<?= (int)$directEmail['id'] ?>"><?= renderEmailDetail($directEmail, ['admin' => $admin]) ?></template>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
