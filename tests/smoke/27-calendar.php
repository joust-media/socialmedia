<?php
/**
 * Posts → Calendar (calendar-lib.php, partials/posts-calendar.php; no migration): the month overview of SCHEDULED posts.
 *   - what appears: posts.posted = 1 on their scheduled_date (the sheet's Post date), the portal's New York wall clock
 *     (23:30 stays on its day, 00:15 on the 1st); never a draft (even one flagged posted), a trashed post, To Review,
 *     Needs changes, or approved-but-not-scheduled — except Joust's "Show approved, not yet scheduled" (admin, faded)
 *   - the grid: Sunday start (config 'calendar_week_start' => 'mon' → Monday, lib-level), whole weeks, muted days of the
 *     neighbouring months, today marked, past days faded; chips (sm preview, title, Reel / Carousel / Image / Video icon),
 *     "+N more" → the day's template (every post, in time order); the phone day list
 *   - month navigation: prev / next / Today links, the year rollover, a bad month → this month, &partial=calendar
 *   - seats: the client sees its own company only (another client's calendar is refused); the admin sees the scoped
 *     client, or every client unscoped ("All clients", each chip tagged); anon → sign-in
 *   - List · Calendar: the switch on both views (and the admin's client chooser), the choice remembered in a cookie,
 *     list-only parameters keep the list; clean links /portal/<client>/posts/calendar both ways
 *   - live: Unmark / Mark scheduled and a Post date change through status.php move the post on the next fetch
 *   - size: a month with 400 scheduled posts stays far under ModSecurity's ~512 KB
 * Every test starts from the seed + the fixture below.
 */
require __DIR__ . '/lib.php';

date_default_timezone_set('America/New_York');
db()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/New_York')))->format('P') . "'");

$ROOT  = rtrim((string)(getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test'), '/');
$APP   = rtrim((string)(getenv('APP_DIR') ?: $ROOT . '/site/portal'), '/');
$MEDIA = rtrim((string)(getenv('MEDIA_DIR') ?: $ROOT . '/site/media'), '/');

/** The month under test: next month (no "past" fading in it). */
$M = (new DateTimeImmutable('first day of next month'))->format('Y-m');
function d(string $day): string { global $M; return $M . '-' . $day; }

function reseedNow(): void {
    global $APP, $MEDIA;
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($APP) . ' ' . escapeshellarg($MEDIA) . ' 2>&1', $out, $rc);
    if ($rc !== 0) throw new RuntimeException('seed failed: ' . implode("\n", $out));
}
/** Seed + the calendar fixture (ids 201+). */
function fixture(): void {
    reseedNow();
    $prevLast = (new DateTimeImmutable(d('01')))->modify('-1 day')->format('Y-m-d');
    $rows = [
        // id, company, name, date time, status, posted, type, media [url, type]…, trashed
        [201, 1, 'Cal image',      d('10') . ' 14:30:00', 'approved', 1, 'post', [['uploads/img_post01_1.jpg', 'image']], false],
        [202, 1, 'Cal reel',       d('10') . ' 09:00:00', 'approved', 1, 'reel', [['uploads/img_post05_1.jpg', 'image']], false],
        [203, 1, 'Cal carousel',   d('10') . ' 11:00:00', 'approved', 1, 'post', [['uploads/img_post02_1.jpg', 'image'], ['uploads/img_post02_2.jpg', 'image'], ['uploads/img_post02_3.jpg', 'image']], false],
        [204, 1, 'Cal video',      d('10') . ' 16:00:00', 'approved', 1, 'post', [['uploads/cal_clip.mp4', 'video']], false],
        [205, 1, 'Cal fifth',      d('10') . ' 18:00:00', 'approved', 1, 'post', [], false],
        [206, 1, 'Cal draft',      d('12') . ' 10:00:00', 'draft',    0, 'post', [['uploads/img_post06_1.jpg', 'image']], false],
        [207, 1, 'Cal trashed',    d('12') . ' 11:00:00', 'approved', 1, 'post', [], true],
        [208, 1, 'Cal approved',   d('13') . ' 10:00:00', 'approved', 0, 'post', [], false],
        [209, 1, 'Cal pending',    d('14') . ' 10:00:00', 'pending',  0, 'post', [], false],
        [210, 1, 'Cal denied',     d('14') . ' 11:00:00', 'denied',   0, 'post', [], false],
        [211, 2, 'PB scheduled',   d('15') . ' 10:00:00', 'approved', 1, 'post', [['uploads/img_post08_1.jpg', 'image']], false],
        [212, 1, 'Cal late night', d('20') . ' 23:30:00', 'approved', 1, 'post', [], false],
        [213, 1, 'Cal first',      d('01') . ' 00:15:00', 'approved', 1, 'post', [], false],
        [214, 1, 'Cal prev month', $prevLast . ' 12:00:00', 'approved', 1, 'post', [], false],
        [215, 1, 'Cal draft posted', d('16') . ' 10:00:00', 'draft',  1, 'post', [], false],
    ];
    $ins = db()->prepare("INSERT INTO posts (id, company_id, name, caption, hashtags, scheduled_date, status, posted, posted_at, post_type, trashed_at)
                          VALUES (?, ?, ?, ?, '#cal', ?, ?, ?, ?, ?, ?)");
    $img = db()->prepare("INSERT INTO post_images (post_id, image_url, sort_order, media_type) VALUES (?, ?, ?, ?)");
    foreach ($rows as [$id, $co, $name, $when, $st, $posted, $type, $media, $trashed]) {
        $ins->execute([$id, $co, $name, $name . ' caption', $when, $st, $posted, $posted ? $when : null, $type, $trashed ? date('Y-m-d H:i:s') : null]);
        foreach ($media as $i => [$u, $t]) $img->execute([$id, $u, $i + 1, $t]);
    }
}
function ctest(string $name, callable $fn): void { test($name, static function () use ($fn) { fixture(); $fn(); }); }

/** The page / fragment as a DOM. */
function dom(string $html): DOMXPath {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return new DOMXPath($doc);
}
function cls(string $c): string { return "contains(concat(' ', normalize-space(@class), ' '), ' $c ')"; }
/** Post ids of the chips in one day cell (at most 3 — the rest are in the day's list). */
function chipsOn(DOMXPath $x, string $date): array {
    $out = [];
    foreach ($x->query("//li[" . cls('cal-day') . " and @data-date='$date']//a[" . cls('cal-chip') . "]") as $a) $out[] = (int)$a->getAttribute('data-post-open');
    return $out;
}
/** Post ids of one day's list (its <template>: the day sheet / the phone list). */
function dayList(DOMXPath $x, string $date): array {
    $out = [];
    $tpl = $x->query("//template[@data-cal-day-tpl='$date']")->item(0);
    if (!$tpl) return $out;
    foreach ($x->query(".//a[" . cls('cal-row') . "]", $tpl) as $a) $out[] = (int)$a->getAttribute('data-post-open');
    return $out;
}
/** Every post id on the page (chips + day lists). */
function allChips(DOMXPath $x): array {
    $out = [];
    foreach ($x->query("//a[@data-post-open]") as $a) $out[] = (int)$a->getAttribute('data-post-open');
    return array_values(array_unique($out));
}
function cal(string $q, string $role = 'admin'): array { return status(get('posts.php?' . $q, $role), 200, $q); }
function appPhp(string $code): string {
    global $APP;
    return (string)shell_exec('cd ' . escapeshellarg($APP) . ' && php -r ' . escapeshellarg('require "url-lib.php"; require "calendar-lib.php"; ' . $code) . ' 2>&1');
}

// =====================================================================================================================
// What appears
// =====================================================================================================================
ctest('scheduled posts sit on their post date (time order), drafts / trash / unscheduled never appear', function () {
    global $M;
    $r = cal("client=kenda&view=calendar&month=$M");
    $x = dom($r['body']);
    is($x->query("//section[@data-cal]")->item(0)->getAttribute('data-month'), $M, 'the month');
    is(chipsOn($x, d('10')), [202, 203, 201], 'day 10: the first three chips, by time');
    is(dayList($x, d('10')), [202, 203, 201, 204, 205], 'day 10: every post in its list, by time');
    is(chipsOn($x, d('20')), [212], '23:30 stays on its own day');
    is(chipsOn($x, d('21')), [], '…not the next one');
    is(chipsOn($x, d('01')), [213], '00:15 on the 1st');
    foreach ([206 => 'draft', 207 => 'trashed', 208 => 'approved, not scheduled', 209 => 'To Review', 210 => 'Needs changes', 215 => 'a draft flagged posted', 211 => 'another client'] as $id => $why) {
        ok(!in_array($id, allChips($x), true), "post $id ($why) is not on the calendar");
        hasNot($r['body'], 'data-post-open="' . $id . '"', "post $id ($why) nowhere in the page");
    }
    ok(in_array(5, allChips($x), true) || substr((string)q1('SELECT scheduled_date FROM posts WHERE id = 5'), 0, 7) !== $M, 'the seed\'s Scheduled post 5 shows in its own month');
    is($x->query("//li[" . cls('cal-day') . " and @data-date='" . d('10') . "']")->item(0)->getAttribute('data-count'), '5');
});
ctest('the client sees the same scheduled posts (and never a draft / unscheduled one)', function () {
    global $M;
    $x = dom(cal("client=kenda&view=calendar&month=$M", 'client')['body']);
    is(dayList($x, d('10')), [202, 203, 201, 204, 205]);
    foreach ([206, 207, 208, 209, 210, 211, 215] as $id) ok(!in_array($id, allChips($x), true), "client: no post $id");
    is($x->query("//*[@data-cal-approved]")->length, 0, 'no admin toggle for the client');
    hasNot(cal("client=kenda&view=calendar&month=$M&approved=1", 'client')['body'], 'data-post-open="208"', 'approved=1 is ignored for the client');
});
ctest('admin: Show approved, not yet scheduled — off by default, faded when on, never a draft', function () {
    global $M;
    $off = dom(cal("client=kenda&view=calendar&month=$M")['body']);
    is($off->query("//*[@data-cal-approved]")->item(0)->getAttribute('aria-checked'), 'false');
    $x = dom(cal("client=kenda&view=calendar&month=$M&approved=1")['body']);
    is($x->query("//*[@data-cal-approved]")->item(0)->getAttribute('aria-checked'), 'true');
    is(chipsOn($x, d('13')), [208], 'the approved post on its date');
    ok($x->query("//a[@data-post-open='208' and " . cls('cal-chip') . " and " . cls('is-tentative') . "]")->length === 1, 'faded (is-tentative)');
    ok($x->query("//a[@data-post-open='201' and " . cls('is-tentative') . "]")->length === 0, 'scheduled ones are not');
    foreach ([206, 207, 209, 210, 215] as $id) ok(!in_array($id, allChips($x), true), "still no post $id");
});
ctest('chips: sm preview thumbnail, title, type icon (Reel / Carousel / Image / Video); the day template lists every post', function () {
    global $M;
    $r = cal("client=kenda&view=calendar&month=$M");
    $x = dom($r['body']);
    $kind = static function (int $id) use ($x) { return $x->query("//a[@data-post-open='$id']")->item(0)->getAttribute('data-kind'); };
    is($kind(202), 'reel'); is($kind(203), 'carousel'); is($kind(201), 'image'); is($kind(204), 'video');
    $img = $x->query("//a[" . cls('cal-chip') . " and @data-post-open='201']//img")->item(0);
    ok($img !== null, 'a thumbnail');
    $src = $img->getAttribute('src');
    ok(strpos($src, 'img_post01_1.jpg') === false || strpos($src, 'preview.php') !== false || strpos($src, '.sm.') !== false, 'the sm preview, not the original: ' . $src);
    ok(preg_match('/(s=sm|\.sm\.)/', $src) === 1, 'sm size: ' . $src);
    is($x->query("//a[@data-post-open='201']//*[" . cls('cal-chip-text') . "]")->item(0)->textContent, 'Cal image');
    ok($x->query("//a[@data-post-open='204']//*[" . cls('is-video') . "]")->length >= 1, 'a video gets the play glyph, never a <video>');
    hasNot($r['body'], '<video', 'no video elements on the calendar');
    // +N more
    $more = $x->query("//li[@data-date='" . d('10') . "']//button[@data-cal-more]")->item(0);
    ok($more !== null, '+N more on a 5-post day');
    is(trim($more->textContent), '+2 more');
    is($x->query("//li[@data-date='" . d('01') . "']//button[@data-cal-more]")->length, 0, 'no +N more on a 1-post day');
    $tpl = $x->query("//template[@data-cal-day-tpl='" . d('10') . "']")->item(0);
    ok($tpl !== null, 'the day template');
    is(dayList($x, d('10')), [202, 203, 201, 204, 205], 'every post that day, by time');
    has($tpl->getAttribute('data-label'), date('l, F j', strtotime(d('10'))));
    has($r['body'], '9:00 AM · Reel', 'time + type on the rows');
    has($r['body'], 'Carousel · 3 slides');
    foreach ($x->query("//a[" . cls('cal-chip') . " or " . cls('cal-row') . "]") as $a) ok($a->hasAttribute('data-post-open'), 'every chip / row opens the post sheet');
    has($x->query("//a[@data-post-open='201']")->item(0)->getAttribute('href'), 'post=201', 'the no-JS link is the post itself');
});

// =====================================================================================================================
// The grid
// =====================================================================================================================
ctest('grid: Sunday start, whole weeks, muted neighbour days (with their posts), today marked, past days faded', function () {
    global $M;
    $x = dom(cal("client=kenda&view=calendar&month=$M")['body']);
    $days = [];
    foreach ($x->query("//li[" . cls('cal-day') . "]") as $li) $days[] = $li;
    is(count($days) % 7, 0, 'whole weeks');
    is(date('w', strtotime($days[0]->getAttribute('data-date'))), '0', 'starts on a Sunday');
    is(trim($x->query("//div[" . cls('cal-weekdays') . "]/span[1]")->item(0)->textContent), 'SunS');
    $first = $x->query("//li[@data-date='" . d('01') . "']")->item(0);
    ok(strpos($first->getAttribute('class'), 'is-out') === false, 'the 1st is in the month');
    if ($days[0]->getAttribute('data-date') !== d('01')) {
        ok(strpos($days[0]->getAttribute('class'), 'is-out') !== false, 'leading days are muted');
        $prevLast = (new DateTimeImmutable(d('01')))->modify('-1 day')->format('Y-m-d');
        is(chipsOn($x, $prevLast), [214], 'a muted day still shows its post');
        ok(strpos($x->query("//li[@data-date='$prevLast']")->item(0)->getAttribute('class'), 'is-out') !== false);
    }
    // this month: today + past
    $now = date('Y-m');
    $t = dom(cal("client=kenda&view=calendar&month=$now")['body']);
    $today = $t->query("//li[@data-date='" . date('Y-m-d') . "']")->item(0);
    has($today->getAttribute('class'), 'is-today');
    $yday = date('Y-m-d', strtotime('-1 day'));
    $y = $t->query("//li[@data-date='$yday']")->item(0);
    if ($y) has($y->getAttribute('class'), 'is-past');
    hasNot($today->getAttribute('class'), 'is-past', 'today is not past');
    is($t->query("//section[@data-cal]")->item(0)->getAttribute('data-selected'), date('Y-m-d'), 'phones: today selected');
    is($t->query("//a[" . cls('cal-today') . " and @aria-current='date']")->length, 1, 'Today is current');
});
test('grid (lib): Monday start when configured, February in a leap year, December → January', function () {
    $out = json_decode(appPhp('echo json_encode([calendarGridDays("2026-10", 1), calendarGridDays("2028-02", 0), calendarShiftMonth("2026-12", 1), calendarShiftMonth("2027-01", -1), calendarMonth("2026-13"), calendarMonth("x")]);'), true);
    ok(is_array($out), 'lib output');
    is($out[0]['days'][0], '2026-09-28', 'Monday start: the Monday before Oct 1');
    is($out[0]['weekdays'], [1, 2, 3, 4, 5, 6, 0]);
    is(count($out[0]['days']) % 7, 0);
    ok(in_array('2028-02-29', $out[1]['days'], true), 'leap day');
    is($out[2], '2027-01'); is($out[3], '2026-12');
    is($out[4], date('Y-m'), 'a bad month → this month'); is($out[5], date('Y-m'));
});

// =====================================================================================================================
// Navigation
// =====================================================================================================================
ctest('month navigation: prev / next / Today links, the fragment, a bad month, the year rollover', function () {
    global $M;
    $x = dom(cal("client=kenda&view=calendar&month=$M")['body']);
    $prev = (new DateTimeImmutable("$M-01"))->modify('-1 month')->format('Y-m');
    $next = (new DateTimeImmutable("$M-01"))->modify('+1 month')->format('Y-m');
    is($x->query("//a[@data-cal-prev]")->item(0)->getAttribute('data-cal-go'), $prev);
    is($x->query("//a[@data-cal-next]")->item(0)->getAttribute('data-cal-go'), $next);
    has($x->query("//a[@data-cal-next]")->item(0)->getAttribute('href'), "month=$next");
    has($x->query("//a[@data-cal-next]")->item(0)->getAttribute('href'), 'view=calendar');
    is($x->query("//a[" . cls('cal-today') . "]")->item(0)->getAttribute('data-cal-go'), date('Y-m'));
    $title = trim(preg_replace('/\s+/', ' ', $x->query("//*[@data-cal-title]")->item(0)->textContent));
    has($title, date('F', strtotime("$M-01"))); has($title, date('Y', strtotime("$M-01")));
    // the fragment (posts-calendar.js)
    $f = cal("client=kenda&view=calendar&month=$next&partial=calendar");
    hasNot($f['body'], '<html', 'the section only');
    has($f['body'], 'data-month="' . $next . '"');
    is($f['headers']['cache-control'] ?? '', 'no-store');
    ok(strlen($f['body']) < 60000, 'a small fragment');
    // a bad month → this month; December → January
    has(cal('client=kenda&view=calendar&month=2026-13')['body'], 'data-month="' . date('Y-m') . '"');
    has(cal('client=kenda&view=calendar&month=../../x')['body'], 'data-month="' . date('Y-m') . '"');
    $dec = dom(cal('client=kenda&view=calendar&month=2026-12')['body']);
    is($dec->query("//a[@data-cal-next]")->item(0)->getAttribute('data-cal-go'), '2027-01');
    is($dec->query("//a[@data-cal-prev]")->item(0)->getAttribute('data-cal-go'), '2026-11');
});
ctest('a deep link to a post in the calendar opens on that post\'s month (and the sheet)', function () {
    global $M;
    $r = cal('client=kenda&view=calendar&post=212');
    has($r['body'], 'data-month="' . $M . '"');
    has($r['body'], '"openPost":212');
    status(get('posts.php?client=kenda&post=212&partial=1', 'admin'), 200, 'the sheet partial');
    status(get('posts.php?client=kenda&post=212&partial=1', 'client'), 200, 'the client sheet partial');
});

// =====================================================================================================================
// Seats
// =====================================================================================================================
ctest('client isolation: own company only; another client\'s calendar is refused; anon → sign-in', function () {
    global $M;
    $x = dom(cal("client=privacybee&view=calendar&month=$M", 'client:privacybee')['body']);
    is(allChips($x), [211], 'Privacy Bee sees its own scheduled post only');
    $r = get("posts.php?client=privacybee&view=calendar&month=$M", 'client:kenda');
    ok($r['code'] !== 200 || strpos($r['body'], 'data-post-open="211"') === false, 'Kenda never sees Privacy Bee\'s calendar');
    hasNot($r['body'], 'PB scheduled');
    $r = get("posts.php?client=privacybee&view=calendar&month=$M&partial=calendar", 'client:kenda');
    hasNot($r['body'], 'data-post-open="211"', 'nor its fragment');
    // a client seat without ?client= is scoped to its own company — never "All clients"
    $own = get("posts.php?view=calendar&month=$M", 'client:kenda');
    hasNot($own['body'], 'data-post-open="211"');
    hasNot($own['body'], 'data-all="1"');
    $a = get("posts.php?client=kenda&view=calendar&month=$M", 'anon');
    ok(in_array($a['code'], [302, 303], true), 'anon → sign-in (' . $a['code'] . ')');
    has($a['location'], 'sign-in');
    $a = get("posts.php?view=calendar&month=$M&partial=calendar", 'anon');
    hasNot($a['body'], 'data-post-open', 'anon gets no fragment');
});
ctest('admin All clients (unscoped): every client\'s scheduled posts, each chip tagged with its client', function () {
    global $M;
    $r = cal("view=calendar&month=$M");
    $x = dom($r['body']);
    is($x->query("//section[@data-cal]")->item(0)->getAttribute('data-all'), '1');
    $ids = allChips($x);
    ok(in_array(201, $ids, true) && in_array(211, $ids, true), 'Kenda and Privacy Bee');
    foreach ([206, 207, 208, 209, 210, 215] as $id) ok(!in_array($id, $ids, true), "no post $id");
    ok($x->query("//a[" . cls('cal-chip') . " and @data-post-open='211']//*[" . cls('cal-avatar') . "]")->length === 1, 'the client tag on the chip');
    is($x->query("//a[@data-post-open='211']")->item(0)->getAttribute('data-cal-client'), 'privacybee');
    has($r['body'], 'Privacy Bee', 'the client name (row / tooltip)');
    has($r['body'], 'All clients', 'the eyebrow');
    // a scoped calendar links to All clients; the deep link points the sheet at the post's own client
    has(cal("client=kenda&view=calendar&month=$M")['body'], 'data-cal-all-clients');
    $d = cal('view=calendar&post=211');
    ok(preg_match('/"partialUrl":"[^"]*privacybee[^"]*"/', $d['body']) === 1, 'the sheet loads Privacy Bee\'s partial');
});

// =====================================================================================================================
// List · Calendar
// =====================================================================================================================
ctest('List · Calendar: the switch on both views, the choice remembered, list-only parameters keep the list', function () {
    global $M;
    $l = cal('client=kenda');
    has($l['body'], 'data-posts-view="calendar"', 'the switch on the list');
    has($l['body'], 'data-posts-list');
    hasNot($l['body'], 'data-cal ', 'the list by default');
    $c = cal("client=kenda&view=calendar&month=$M");
    $x = dom($c['body']);
    is($x->query("//a[@data-posts-view='calendar' and @aria-selected='true']")->length, 1, 'Calendar active');
    is($x->query("//a[@data-posts-view='list']")->length, 1);
    ok((bool)array_filter($c['cookies'], static function ($s) { return strpos($s, 'portal_posts_view=calendar') === 0; }), 'remembered: ' . implode(' | ', $c['cookies']));
    $again = get('posts.php?client=kenda', 'admin', ['Cookie' => 'portal_posts_view=calendar']);
    has($again['body'], 'data-cal ', 'the Posts tab opens on the calendar');
    ok(!array_filter($again['cookies'], static function ($s) { return strpos($s, 'portal_posts_view=') === 0; }), 'no new view cookie without a choice');
    foreach (['status=pending', 'post=1', 'month=all&status=approved'] as $q) {
        $b = get("posts.php?client=kenda&$q", 'admin', ['Cookie' => 'portal_posts_view=calendar'])['body'];
        has($b, 'data-posts-list', "$q keeps the list");
    }
    $li = get('posts.php?client=kenda&view=list', 'admin', ['Cookie' => 'portal_posts_view=calendar']);
    has($li['body'], 'data-posts-list');
    ok((bool)array_filter($li['cookies'], static function ($s) { return strpos($s, 'portal_posts_view=list') === 0; }), 'List remembered');
    // the admin's client chooser carries it too; unscoped + remembered calendar = All clients
    has(cal('view=list')['body'], 'data-posts-view="calendar"');
    has(get('posts.php', 'admin', ['Cookie' => 'portal_posts_view=calendar'])['body'], 'data-all="1"');
    // the client seat
    $cl = get('posts.php?client=kenda', 'client', ['Cookie' => 'portal_posts_view=calendar']);
    has($cl['body'], 'data-cal ');
    // no new nav tab
    hasNot($c['body'], 'data-tab="calendar"');
});
ctest('clean links: /portal/<client>/posts/calendar both ways; the old URL 301s there', function () {
    global $M;
    $out = appPhp('$GLOBALS["__portal_clean_override"] = true; echo json_encode([portalRouteMatch("kenda/posts/calendar"), portalUrl("posts", ["client" => "kenda", "view" => "calendar", "month" => "2026-11"]), portalUrl("posts", ["client" => "kenda", "post" => 12]), portalRouteMatch("kenda/posts/12")]);');
    $j = json_decode($out, true);
    ok(is_array($j), 'lib: ' . $out);
    is($j[0]['script'], 'posts'); is($j[0]['params'], ['client' => 'kenda', 'view' => 'calendar']);
    ok(substr($j[1], -strlen('/kenda/posts/calendar?month=2026-11')) === '/kenda/posts/calendar?month=2026-11', 'reverse: ' . $j[1]);
    ok(substr($j[2], -strlen('/kenda/posts/12')) === '/kenda/posts/12', 'post links unchanged');
    is($j[3]['params'], ['client' => 'kenda', 'post' => '12']);
    // live
    $r = post('client-admin.php', ['action' => 'clean_links_install'], 'admin', [], ['Accept' => 'application/json']);
    try {
        is($r['code'], 200, 'clean links installed');
        $p = get("kenda/posts/calendar?month=$M", 'admin');
        status($p, 200, 'the clean calendar URL');
        has($p['body'], 'data-month="' . $M . '"');
        has($p['body'], 'href="/portal/kenda/posts/calendar?month=', 'month links print clean');
        $o = get("posts.php?client=kenda&view=calendar&month=$M", 'admin');
        is($o['code'], 301);
        is(preg_replace('#^https?://[^/]+#', '', $o['location']), "/portal/kenda/posts/calendar?month=$M");
        status(get("kenda/posts/calendar?month=$M&partial=calendar", 'admin'), 200, 'the fragment');
        is(get("kenda/posts/calendar?month=$M", 'client:privacybee')['code'] === 200 ? 'leak' : 'ok', 'ok', 'cross-client on the clean URL');
        has(get('kenda/posts/2', 'admin')['body'], 'data-posts-list', '/posts/<id> is still the list');
    } finally {
        global $APP;
        @unlink($APP . '/.htaccess');
        foreach (glob($APP . '/.htaccess.bak-*') ?: [] as $f) @unlink($f);
    }
});

// =====================================================================================================================
// Live updates (the server side: the next fetch reflects the sheet's change)
// =====================================================================================================================
ctest('Unmark / Mark scheduled and a Post date change move the post on the next fetch', function () {
    global $M;
    $frag = static function () use ($M) { return dom(cal("client=kenda&view=calendar&month=$M&partial=calendar")['body']); };
    status(post('status.php', ['action' => 'toggle_posted', 'id' => 201, 'to' => '0', 'client' => 'kenda'], 'admin', [], ['Accept' => 'application/json']), 200);
    ok(!in_array(201, chipsOn($frag(), d('10')), true), 'unmarked → gone');
    status(post('status.php', ['action' => 'toggle_posted', 'id' => 201, 'to' => '1', 'client' => 'kenda'], 'admin', [], ['Accept' => 'application/json']), 200);
    ok(in_array(201, chipsOn($frag(), d('10')), true), 'marked again → back');
    status(post('status.php', ['id' => 201, 'scheduled_date' => d('11') . ' 08:00:00', 'client' => 'kenda'], 'admin', [], ['Accept' => 'application/json']), 200);
    $x = $frag();
    is(chipsOn($x, d('11')), [201], 'on its new date');
    is(dayList($x, d('10')), [202, 203, 204, 205], 'the old day\'s list without it');
    ok(!in_array(201, chipsOn($x, d('10')), true), 'not on the old one');
    // Trash
    status(post('trash.php', ['action' => 'trash', 'items' => 'post:202', 'client' => 'kenda'], 'admin', [], ['Accept' => 'application/json']), 200);
    ok(!in_array(202, allChips($frag()), true), 'trashed → gone');
});

// =====================================================================================================================
// Size
// =====================================================================================================================
ctest('a busy month (400 scheduled posts) stays far under the ~512 KB ModSecurity limit; one query for the month', function () {
    global $M;
    $ins = db()->prepare("INSERT INTO posts (company_id, name, caption, hashtags, scheduled_date, status, posted, posted_at, post_type) VALUES (1, ?, ?, '#x', ?, 'approved', 1, NOW(), 'post')");
    $img = db()->prepare("INSERT INTO post_images (post_id, image_url, sort_order, media_type) VALUES (?, 'uploads/img_post01_1.jpg', 1, 'image')");
    for ($i = 0; $i < 400; $i++) {
        $day = sprintf('%02d', 1 + ($i % 28));
        $ins->execute(['Busy post ' . $i . ' with a fairly long name to be realistic', str_repeat('Caption text ', 20), d($day) . sprintf(' %02d:%02d:00', 6 + intdiv($i, 28) % 16, $i % 60)]);
        $img->execute([(int)db()->lastInsertId()]);
    }
    $t = microtime(true);
    $r = cal("client=kenda&view=calendar&month=$M");
    $ms = (microtime(true) - $t) * 1000;
    $kb = strlen($r['body']) / 1024;
    ok($kb < 200, sprintf('page %.0f KB', $kb));
    $x = dom($r['body']);
    $total = 0;
    foreach ($x->query("//li[" . cls('cal-day') . "]") as $li) $total += (int)$li->getAttribute('data-count');
    ok($total >= 405, 'every post is counted on its day: ' . $total);
    is($x->query("//section[@data-cal]")->item(0)->getAttribute('data-inline'), '0', 'a busy month does not inline the day lists');
    is($x->query("//template[@data-cal-day-tpl]")->length, 0);
    ok($x->query("//li[@data-date='" . d('05') . "']//a[" . cls('cal-chip') . "]")->length === 3, 'three chips a day');
    // the day list on demand (the day sheet / phone list)
    $day = cal("client=kenda&view=calendar&date=" . d('05') . "&partial=day");
    $dx = dom($day['body']);
    ok(count(dayList($dx, d('05'))) >= 14, 'the busy day\'s whole list');
    ok(strlen(cal("client=kenda&view=calendar&month=$M&partial=calendar")['body']) / 1024 < 400, 'fragment size');
    is(get("posts.php?client=kenda&view=calendar&date=2026-02-30&partial=day", 'admin')['code'], 400, 'a bad date');
    hasNot(cal("client=kenda&view=calendar&date=" . d('15') . "&partial=day")['body'], 'data-post-open="211"', 'the day partial keeps the scope');
    has(cal("client=privacybee&view=calendar&date=" . d('15') . "&partial=day", 'client:privacybee')['body'], 'data-post-open="211"');
    hasNot(get("posts.php?client=kenda&view=calendar&date=" . d('12') . "&partial=day", 'client')['body'], 'data-post-open="206"', 'no draft in a day list');
    ok($ms < 5000, sprintf('rendered in %.0f ms', $ms));
    // one statement for the month's posts (calendar-lib.php calendarPosts): no per-post queries in the page code
    $src = file_get_contents(dirname(__DIR__, 2) . '/partials/posts-calendar.php');
    is(substr_count($src, 'calendarPosts('), 2, 'calendarPosts(): the month grid + the on-demand day list, nothing per post');
    is(preg_match_all('/->(prepare|query)\(/', $src), 1, 'only the deep-link lookup besides it');
});

finish();
