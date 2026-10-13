<?php
// Not a page: posts.php includes it for ?view=calendar (calendar-lib.php: what appears, dates, week start, seats).
if (!function_exists('esc') || !isset($pdo)) { http_response_code(404); exit; }
/**
 * Posts → Calendar (month overview of scheduled posts).
 *
 *   posts.php?view=calendar[&month=YYYY-MM][&post=<id>][&approved=1 (admin)]      the page
 *   …&partial=calendar                                                           the <section data-cal> only (static/js/
 *                                                                                posts-calendar.js: month arrows, Today,
 *                                                                                the admin toggle, live updates)
 *   …&partial=day&date=YYYY-MM-DD                                                one day's <template> (a month with more than
 *                                                                                CALENDAR_INLINE_MAX posts does not inline them)
 * Reads from posts.php: $pdo, $client (null = the admin's "All clients"), $admin.
 * Desktop: a 7-column month grid, up to 3 chips a day (sm thumb, title, Reel / Carousel / Image / Video / Story icon,
 * the client's avatar when unscoped) + "+N more" → the day sheet (#calDaySheet). Phones (< 760px): the same grid with
 * dots, and the selected day's posts listed below it. A post opens the usual post sheet (posts.js App.posts.open).
 */
require_once __DIR__ . '/../calendar-lib.php';

$calPartial = (($_GET['partial'] ?? '') === 'calendar');
if (!$client && !$admin) {   // a client seat always has its own scope (portalAccessGate); keep posts.php's missing-client answer
    http_response_code(400);
    if ($calPartial) { echo '<div class="ui-empty">This link is missing its client.</div>'; exit; }
    $pageTitle = 'Posts'; $navTrailing = ''; $showTabs = false; $includeSheet = false;
    include __DIR__ . '/layout-top.php';
    echo '<div class="ui-empty">This link is missing its client. Please use the review link Joust sent you.</div>';
    include __DIR__ . '/layout-bottom.php';
    exit;
}

$calAll      = !$client;                                   // admin only (above): every client, each chip tagged
$calApproved = $admin && !empty($_GET['approved']);        // admin: + approved, not yet scheduled (faded)
$calPost     = max(0, (int)($_GET['post'] ?? 0));
$calPostSlug = '';
$calMonthRaw = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : null;
if ($calPost > 0) {
    // A deep link to one post (…/posts/12?view=calendar, history after opening a post): its month when none is given,
    // and (All clients) the client whose partial the sheet loads. The sheet's own fetch still applies every rule.
    $st = $pdo->prepare("SELECT p.scheduled_date, c.slug FROM posts p INNER JOIN companies c ON c.id = p.company_id WHERE p.id = ?"
        . ($calAll ? '' : ' AND p.company_id = ' . (int)$client['id']));
    $st->execute([$calPost]);
    if ($row = $st->fetch()) {
        $calPostSlug = (string)$row['slug'];
        $ts = strtotime((string)$row['scheduled_date']);
        if ($calMonthRaw === null && $ts) $calMonthRaw = date('Y-m', $ts);
    }
}
$calMonth = calendarMonth($calMonthRaw);
$calGrid  = calendarGridDays($calMonth);
$calRows  = ($_GET['partial'] ?? '') === 'day' ? []   // one day only (below)
          : calendarPosts($pdo, $calAll ? null : (int)$client['id'], $admin, $calGrid['start'], $calGrid['end'], $calApproved);
$calByDay = calendarByDay($calRows);
$calToday = date('Y-m-d');
$calFirst = new DateTimeImmutable($calMonth . '-01');
$calPrev  = calendarShiftMonth($calMonth, -1);
$calNext  = calendarShiftMonth($calMonth, 1);
$calNow   = date('Y-m');
$calInMonth = 0;
foreach ($calRows as $r) { if (substr($r['day'], 0, 7) === $calMonth && !$r['tentative']) $calInMonth++; }

/** This view's URL for a month (keeps the scope and the admin toggle). */
$calUrl = static function (string $ym, array $extra = []) use ($calApproved): string {
    return clientUrl('posts.php', array_merge(['view' => 'calendar', 'month' => $ym, 'approved' => $calApproved ? 1 : null], $extra));
};

// The selected day (phones list it under the grid): today in this month, else the first day with posts, else the 1st.
$calSelected = substr($calToday, 0, 7) === $calMonth ? $calToday : '';
if ($calSelected === '') {
    foreach ($calByDay as $d => $_) { if (substr($d, 0, 7) === $calMonth) { $calSelected = $d; break; } }
}
if ($calSelected === '') $calSelected = $calMonth . '-01';

/** The client tag (unscoped admin): a tiny avatar, cached per client. */
$calAvatar = static function (array $p): string {
    static $cache = [];
    $k = $p['company_slug'];
    if (!isset($cache[$k])) {
        $cache[$k] = clientAvatar(['name' => $p['company_name'], 'slug' => $p['company_slug'], 'logo_url' => $p['company_logo']], 'ui-avatar--xs cal-avatar');
    }
    return $cache[$k];
};
/** The sm thumbnail (or a glyph for a video / no media). */
$calThumb = static function (array $p, string $cls): string {
    if ($p['first_url'] !== '' && !$p['first_video']) {
        return '<span class="' . $cls . '">' . pvImg(pdMediaUrl($p['first_url']), 'sm', ['alt' => '']) . '</span>';
    }
    return '<span class="' . $cls . ' ' . $cls . '--glyph' . ($p['first_video'] ? ' is-video' : '') . '"><svg class="cal-glyph" aria-hidden="true"><use href="#cal-i-' . ($p['first_video'] ? 'video' : 'image') . '"/></svg></span>';
};
$calHref = static function (array $p): string {
    return portalUrl('posts', ['client' => $p['company_slug'], 'post' => $p['id']]);
};
/** One chip in a day cell (desktop). */
$calChip = static function (array $p) use ($calAll, $calAvatar, $calThumb, $calHref): string {
    $label = $p['title'] . ' · ' . $p['time'] . ' · ' . $p['kind_label'] . ($calAll ? ' · ' . $p['company_name'] : '')
           . ($p['tentative'] ? ' · Approved, not scheduled yet' : '');
    return '<li><a class="cal-chip' . ($p['tentative'] ? ' is-tentative' : '') . '" href="' . esc($calHref($p)) . '" data-post-open="' . $p['id'] . '" data-title="' . esc($p['title']) . '"'
         . ($calAll ? ' data-cal-client="' . esc($p['company_slug']) . '"' : '') . ' data-kind="' . $p['kind'] . '" title="' . esc($label) . '">'
         . $calThumb($p, 'cal-thumb')
         . '<span class="cal-chip-text">' . esc($p['title']) . '</span>'
         . '<svg class="cal-kind" role="img" aria-label="' . esc($p['kind_label']) . '"><use href="#cal-i-' . $p['kind'] . '"/></svg>'
         . ($calAll ? $calAvatar($p) : '')
         . '</a></li>';
};
/** One row in the day sheet / the phone's day list. */
$calRow = static function (array $p) use ($calAll, $calThumb, $calHref): string {
    $sub = [$p['time'], $p['kind_label'] . ($p['kind'] === 'carousel' ? ' · ' . $p['n_media'] . ' slides' : '')];
    if ($calAll) $sub[] = $p['company_name'];
    if ($p['tentative']) $sub[] = 'Approved, not scheduled yet';
    return '<li><a class="ui-row ui-row--leading cal-row' . ($p['tentative'] ? ' is-tentative' : '') . '" href="' . esc($calHref($p)) . '"'
         . ' data-post-open="' . $p['id'] . '" data-title="' . esc($p['title']) . '"' . ($calAll ? ' data-cal-client="' . esc($p['company_slug']) . '"' : '') . ' data-kind="' . $p['kind'] . '">'
         . $calThumb($p, 'ui-row-leading cal-row-thumb')
         . '<span class="ui-row-body"><span class="ui-row-title cal-row-title">' . esc($p['title']) . '</span>'
         . '<span class="ui-row-subtitle cal-row-sub"><svg class="cal-kind" aria-hidden="true"><use href="#cal-i-' . $p['kind'] . '"/></svg><span class="cal-row-subtext">' . esc(implode(' · ', $sub)) . '</span></span></span>'
         . icon('chevron-right', 'ui-row-chevron') . '</a></li>';
};
$calDayLabel = static function (string $d): string { return date('l, F j', strtotime($d)); };
$calCountLabel = static function (array $posts): string {
    $s = 0; $t = 0;
    foreach ($posts as $p) { if ($p['tentative']) $t++; else $s++; }
    $out = $s . ' scheduled ' . ($s === 1 ? 'post' : 'posts');
    if ($t > 0) $out .= ', ' . $t . ' approved';
    return $out;
};
$calDayList = static function (array $posts) use ($calRow): string {
    $h = '';
    foreach ($posts as $p) $h .= $calRow($p);
    return '<ul class="ui-list cal-daylist" role="list">' . $h . '</ul>';
};
$calWeekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
/** One day's <template> (the day sheet / the phone's day list read it): every post that day, in time order. */
$calDayTemplate = static function (string $d, array $posts) use ($calDayList, $calDayLabel, $calCountLabel): string {
    return '<template data-cal-day-tpl="' . $d . '" data-label="' . esc($calDayLabel($d)) . '" data-sub="' . esc($calCountLabel($posts)) . '">'
         . ($posts ? $calDayList($posts) : '<div class="cal-agenda-empty">Nothing scheduled this day.</div>') . '</template>';
};

// &partial=day&date=YYYY-MM-DD — one day's template, for a busy month whose page does not inline them (posts-calendar.js)
if (($_GET['partial'] ?? '') === 'day') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $dRaw = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
    if (!preg_match('/^(20\d\d)-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $dRaw) || !checkdate((int)substr($dRaw, 5, 2), (int)substr($dRaw, 8, 2), (int)substr($dRaw, 0, 4))) {
        http_response_code(400);
        exit;
    }
    $dayRows = calendarPosts($pdo, $calAll ? null : (int)$client['id'], $admin, $dRaw, (new DateTimeImmutable($dRaw))->modify('+1 day')->format('Y-m-d'), $calApproved);
    echo $calDayTemplate($dRaw, $dayRows);
    exit;
}
// Day templates inline (every day of the grid) up to CALENDAR_INLINE_MAX posts; a busier month fetches a day on demand,
// so the page stays small whatever the volume (chips: at most 3 a day).
$calInline = count($calRows) <= CALENDAR_INLINE_MAX;

ob_start();
?>
<section class="cal<?= $calAll ? ' cal--all' : '' ?>" data-cal data-month="<?= esc($calMonth) ?>" data-today="<?= esc($calToday) ?>"
         data-selected="<?= esc($calSelected) ?>" data-all="<?= $calAll ? '1' : '0' ?>" data-approved="<?= $calApproved ? '1' : '0' ?>"
         data-count="<?= (int)$calInMonth ?>" data-inline="<?= $calInline ? '1' : '0' ?>" aria-labelledby="calTitle">
  <div class="cal-head">
    <h2 class="cal-title" id="calTitle" data-cal-title><span class="cal-title-long"><?= esc($calFirst->format('F')) ?></span><span class="cal-title-short"><?= esc($calFirst->format('M')) ?></span> <span class="cal-title-year"><?= esc($calFirst->format('Y')) ?></span></h2>
    <div class="cal-nav">
      <a class="ui-btn ui-btn--gray ui-btn--sm cal-today<?= $calMonth === $calNow ? ' is-current' : '' ?>" href="<?= esc($calUrl($calNow)) ?>" data-cal-go="<?= esc($calNow) ?>"<?= $calMonth === $calNow ? ' aria-current="date"' : '' ?>>Today</a>
      <a class="cal-arrow" href="<?= esc($calUrl($calPrev)) ?>" data-cal-go="<?= esc($calPrev) ?>" data-cal-prev aria-label="Previous month, <?= esc(date('F Y', strtotime($calPrev . '-01'))) ?>"><?= icon('chevron-right', 'cal-chev-left') ?></a>
      <a class="cal-arrow" href="<?= esc($calUrl($calNext)) ?>" data-cal-go="<?= esc($calNext) ?>" data-cal-next aria-label="Next month, <?= esc(date('F Y', strtotime($calNext . '-01'))) ?>"><?= icon('chevron-right') ?></a>
    </div>
  </div>

  <div class="cal-board">
    <div class="cal-weekdays" aria-hidden="true">
      <?php foreach ($calGrid['weekdays'] as $w): ?><span data-wd="<?= $w ?>"><span class="cal-wd-long"><?= esc(substr($calWeekdayNames[$w], 0, 3)) ?></span><span class="cal-wd-short"><?= esc(substr($calWeekdayNames[$w], 0, 1)) ?></span></span><?php endforeach; ?>
    </div>
    <ol class="cal-days" role="list" data-cal-days>
      <?php foreach ($calGrid['days'] as $d):
          $posts = $calByDay[$d] ?? [];
          $n     = count($posts);
          $out   = substr($d, 0, 7) !== $calMonth;
          $cls   = 'cal-day' . ($out ? ' is-out' : '') . ($d < $calToday ? ' is-past' : '') . ($d === $calToday ? ' is-today' : '')
                 . ($n ? ' has-posts' : '') . ($d === $calSelected ? ' is-selected' : '');
          $aria  = $calDayLabel($d) . ($d === $calToday ? ', today' : '') . ($n ? ' — ' . $calCountLabel($posts) : ', nothing scheduled');
      ?>
      <li class="<?= $cls ?>" data-date="<?= $d ?>" data-count="<?= $n ?>">
        <button type="button" class="cal-daynum" data-cal-day="<?= $d ?>" aria-label="<?= esc($aria) ?>"<?= $d === $calSelected ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>><span><?= (int)substr($d, 8, 2) ?></span></button>
        <?php if ($n): ?>
          <ul class="cal-chips" role="list"><?php foreach (array_slice($posts, 0, 3) as $p) echo $calChip($p); ?></ul>
          <?php if ($n > 3): ?><button type="button" class="cal-more" data-cal-more="<?= $d ?>" aria-label="Show all <?= $n ?> posts on <?= esc($calDayLabel($d)) ?>">+<?= $n - 3 ?> more</button><?php endif; ?>
          <span class="cal-dots" aria-hidden="true"><?php for ($i = 0; $i < min(3, $n); $i++): ?><i<?= $posts[$i]['tentative'] ? ' class="is-tentative"' : '' ?>></i><?php endfor; ?></span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <section class="ui-list-group cal-agenda" data-cal-agenda aria-live="polite">
    <h3 class="ui-list-header" data-cal-agenda-title><?= esc($calDayLabel($calSelected)) ?></h3>
    <div data-cal-agenda-body><?php if (!empty($calByDay[$calSelected])): echo $calDayList($calByDay[$calSelected]); else: ?><div class="cal-agenda-empty">Nothing scheduled this day.</div><?php endif; ?></div>
  </section>

  <div class="cal-foot">
    <p class="cal-summary" data-cal-summary>
      <?php if (!hasPostedColumn($pdo)): ?>
        Scheduling is not enabled yet.
      <?php elseif ($calInMonth > 0): ?>
        <?= (int)$calInMonth ?> scheduled <?= $calInMonth === 1 ? 'post' : 'posts' ?> in <?= esc($calFirst->format('F')) ?><?= $calAll ? ' across every client' : '' ?>. Times are Eastern (New York).
      <?php elseif ($admin): ?>
        Nothing scheduled in <?= esc($calFirst->format('F')) ?>. Mark an approved post scheduled and it appears on its post date.
      <?php else: ?>
        Nothing scheduled in <?= esc($calFirst->format('F')) ?> yet. Posts appear here once Joust schedules them.
      <?php endif; ?>
    </p>
    <?php if ($admin): ?>
      <div class="cal-admin">
        <a class="cal-toggle" role="switch" aria-checked="<?= $calApproved ? 'true' : 'false' ?>" data-cal-approved
           href="<?= esc(clientUrl('posts.php', ['view' => 'calendar', 'month' => $calMonth, 'approved' => $calApproved ? null : 1])) ?>">
          <span class="cal-toggle-track" aria-hidden="true"></span>Show approved, not yet scheduled
        </a>
        <?php if (!$calAll): ?>
          <a class="cal-alllink" href="<?= esc(portalUrl('posts', ['view' => 'calendar', 'month' => $calMonth])) ?>" data-cal-all-clients>All clients</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($calInline) { foreach ($calByDay as $d => $posts) echo $calDayTemplate($d, $posts); } ?>
</section>
<?php
$calSection = (string)ob_get_clean();

if ($calPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $calSection;
    exit;
}

// ---------------------------------------------------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------------------------------------------------
$pageTitle   = 'Posts';
$htmlTitle   = 'Calendar — Posts' . (!empty($client['name']) ? ' — ' . $client['name'] : ' — All clients');
$activeTab   = 'posts';
$pageWide    = true;
$navWide     = true;
if ($calAll) $navSubtitle = 'All clients';
$navTrailing = !empty($client) ? clientAvatar($client) : null;
$headExtra   = '<link rel="stylesheet" href="' . esc(staticUrl('css/posts.css')) . '">'
             . '<link rel="stylesheet" href="' . esc(staticUrl('css/calendar.css')) . '">';
$bodyClass   = 'page-posts page-posts-calendar';

$calListHref = $calAll ? portalUrl('posts', ['view' => 'list']) : clientUrl('posts.php', ['view' => 'list', 'month' => $calMonth]);
$postsConfig = [
    'base'       => basePath(),
    'endpoint'   => basePath() . '/status.php',
    // the post sheet (posts.js App.posts.open → posts.php &partial=1); All clients: posts-calendar.js points it at the chip's client
    'partialUrl' => portalUrl('posts', ['client' => $calAll ? ($calPostSlug !== '' ? $calPostSlug : null) : $client['slug'], 'post' => '__ID__', 'partial' => 1]),
    'rowUrl'     => '',
    'month'      => '',
    'clientName' => (string)($client['name'] ?? ''),
    'segment'    => 'scheduled',
    'counts'     => new stdClass(),
    'inline'     => false,
    'admin'      => $admin,
    'hasPosted'  => hasPostedColumn($pdo),
    'openPost'   => $calPost,
    'queue'      => false,
    'maxMedia'   => POST_MAX_MEDIA,
];
$calConfig = [
    // the month fragment: posts-calendar.js swaps <section data-cal> with it (arrows, Today, the admin toggle, live updates)
    'partialUrl' => clientUrl('posts.php', ['view' => 'calendar', 'month' => '__MONTH__', 'partial' => 'calendar']),
    'dayUrl'     => clientUrl('posts.php', ['view' => 'calendar', 'date' => '__DATE__', 'partial' => 'day']),   // a busy month's day
    'pageUrl'    => clientUrl('posts.php', ['view' => 'calendar', 'month' => '__MONTH__']),
    'all'        => $calAll,
    'admin'      => $admin,
];
$footExtra = '<script>window.PostsConfig = ' . json_encode($postsConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
           . 'window.PostsCalendarConfig = ' . json_encode($calConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
           . ($admin ? '<script src="' . esc(staticUrl('js/chunk-upload.js')) . '" defer></script>' . "\n" : '')
           . '<script src="' . esc(staticUrl('js/carousel.js')) . '" defer></script>' . "\n"
           . '<script src="' . esc(staticUrl('js/posts.js')) . '" defer></script>' . "\n"
           . '<script src="' . esc(staticUrl('js/posts-calendar.js')) . '" defer></script>';

include __DIR__ . '/layout-top.php';
?>
<svg class="cal-sprite" aria-hidden="true" focusable="false" width="0" height="0">
  <symbol id="cal-i-image" viewBox="0 0 24 24"><rect x="3.5" y="4.5" width="17" height="15" rx="3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="9" cy="10" r="1.8" fill="currentColor"/><path d="M5 18l5-5 3.5 3.5L16 14l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
  <symbol id="cal-i-carousel" viewBox="0 0 24 24"><rect x="7.5" y="3.5" width="13" height="13" rx="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16.5 20.5h-10a3 3 0 0 1-3-3v-10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
  <symbol id="cal-i-reel" viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="17" height="17" rx="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M10 8.8v6.4l5.2-3.2z" fill="currentColor"/></symbol>
  <symbol id="cal-i-video" viewBox="0 0 24 24"><path d="M8 5.5v13l10.5-6.5z" fill="currentColor"/></symbol>
  <symbol id="cal-i-story" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="4 2.6"/><circle cx="12" cy="12" r="3.5" fill="currentColor"/></symbol>
</svg>
<?= postsViewSwitchHtml('calendar', $calListHref, $calUrl($calMonth)) ?>
<?= $calSection ?>
<?php
// The day sheet: every post of one day ("+N more", a day number on desktop). Rows open the post sheet (#uiSheet).
$sheetId = 'calDaySheet';
$sheetTitle = '';
$sheetBody = '';
include __DIR__ . '/sheet.php';
include __DIR__ . '/layout-bottom.php';
