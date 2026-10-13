<?php
/**
 * Posts → Calendar: the month overview of SCHEDULED posts (posts.php?view=calendar&month=YYYY-MM; clean links:
 * /portal/<client>/posts/calendar?month=YYYY-MM). Data + small helpers only; partials/posts-calendar.php renders it.
 *
 *   Scheduled  = posts.posted = 1 (the sheet's "Mark scheduled", transitions-lib.php transitionPostScheduled — only an
 *                approved post can get there), placed on posts.scheduled_date (the sheet's "Post date"). Never a draft,
 *                never a trashed post (trashed_at), never To Review / Needs changes / approved-but-not-scheduled —
 *                except Joust's optional "Show approved, not yet scheduled" (admin only, off by default, faded).
 *   Dates      = the portal's own wall clock (db.php pins PHP + MySQL to America/New_York; scheduled_date is stored
 *                as that local time), so a post sits on the same day the list and the sheet show.
 *   Week start = Sunday (US portal, en-US dates); config.php 'calendar_week_start' => 'mon' switches to Monday.
 *   One query per month (calendarPosts()): the posts of the whole visible grid (leading / trailing days included)
 *   with the first media URL + type and the media count as subqueries — no per-post queries.
 *   Seats      = the client: its own company only (portalAccessGate + company_id) and postsClientVisibleSql().
 *                The admin: the scoped client, or every client when unscoped ("All clients").
 *
 * Function definitions only (function_exists-guarded); no output.
 */

if (!defined('CALENDAR_MAX_POSTS')) define('CALENDAR_MAX_POSTS', 1200);   // one month grid's ceiling (keeps the page well under ModSecurity's ~512 KB)
if (!defined('CALENDAR_INLINE_MAX')) define('CALENDAR_INLINE_MAX', 150);   // up to this many posts the page carries every day's list inline
if (!defined('CALENDAR_VIEW_COOKIE')) define('CALENDAR_VIEW_COOKIE', 'portal_posts_view');

if (!function_exists('calendarWeekStart')) {
    /** 0 = Sunday (default, the portal's en-US / New York locale), 1 = Monday (config 'calendar_week_start' => 'mon'). */
    function calendarWeekStart(): int {
        $v = strtolower(trim((string)(function_exists('portalConfig') ? portalConfig('calendar_week_start', 'sun') : 'sun')));
        return in_array($v, ['mon', 'monday', '1'], true) ? 1 : 0;
    }
}

if (!function_exists('calendarMonth')) {
    /** A real 'YYYY-MM' (2000–2099) or this month. */
    function calendarMonth($raw): string {
        $raw = is_string($raw) ? trim($raw) : '';
        if (preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $raw)) return $raw;
        return date('Y-m');
    }
}

if (!function_exists('calendarShiftMonth')) {
    /** 'YYYY-MM' ± $n months. */
    function calendarShiftMonth(string $ym, int $n): string {
        return (new DateTimeImmutable($ym . '-01'))->modify(($n >= 0 ? '+' : '') . $n . ' month')->format('Y-m');
    }
}

if (!function_exists('calendarGridDays')) {
    /**
     * The visible days of a month grid: whole weeks from the week holding the 1st to the week holding the last day.
     * → ['start' => 'Y-m-d', 'end' => 'Y-m-d' (exclusive), 'days' => ['Y-m-d', …] (a multiple of 7), 'weekdays' => [0..6 order]]
     */
    function calendarGridDays(string $ym, ?int $weekStart = null): array {
        $weekStart = $weekStart === null ? calendarWeekStart() : ($weekStart === 1 ? 1 : 0);
        $first = new DateTimeImmutable($ym . '-01');
        $last  = $first->modify('last day of this month');
        $lead  = ((int)$first->format('w') - $weekStart + 7) % 7;
        $start = $first->modify('-' . $lead . ' day');
        $trail = (6 - (((int)$last->format('w') - $weekStart + 7) % 7));
        $end   = $last->modify('+' . ($trail + 1) . ' day');
        $days = [];
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) $days[] = $d->format('Y-m-d');
        $order = [];
        for ($i = 0; $i < 7; $i++) $order[] = ($weekStart + $i) % 7;
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'days' => $days, 'weekdays' => $order];
    }
}

if (!function_exists('calendarPosts')) {
    /**
     * The posts of one grid range [$from, $to) — ONE query. $companyId null = every client (admin only: the caller
     * decides). $withApproved (admin only) adds approved, not yet scheduled posts (marked 'tentative').
     * Rows: id, company_id, title, caption, scheduled_date, day ('Y-m-d'), time ('g:i A'), status, posted, tentative,
     * kind (reel | story | carousel | video | image), kind_label, first_url, first_video, n_media,
     * company_name, company_slug, company_logo.
     */
    function calendarPosts(PDO $pdo, ?int $companyId, bool $admin, string $from, string $to, bool $withApproved = false): array {
        if (!function_exists('hasPostedColumn') || !hasPostedColumn($pdo)) return [];
        $nameSel  = function_exists('hasPostsNameColumn') && hasPostsNameColumn($pdo) ? 'p.name' : "''";
        $typeSel  = function_exists('hasPostTypeColumn') && hasPostTypeColumn($pdo) ? 'p.post_type' : "'post'";
        $mediaSel = function_exists('hasMediaTypeColumn') && hasMediaTypeColumn($pdo)
            ? "(SELECT pi.media_type FROM post_images pi WHERE pi.post_id = p.id ORDER BY pi.sort_order ASC, pi.id ASC LIMIT 1)"
            : 'NULL';
        $where = ['p.scheduled_date >= ?', 'p.scheduled_date < ?'];
        $params = [$from . ' 00:00:00', $to . ' 00:00:00'];
        // Never a draft — for either seat (postsClientVisibleSql: pending / approved / denied only).
        $where[] = function_exists('postsClientVisibleSql') ? postsClientVisibleSql('p') : "p.status IN ('pending','approved','denied')";
        $where[] = ($admin && $withApproved) ? "(p.posted = 1 OR p.status = 'approved')" : 'p.posted = 1';
        if (function_exists('trashLive')) $where[] = trashLive($pdo, 'post', 'p');
        if ($companyId !== null) { $where[] = 'p.company_id = ?'; $params[] = $companyId; }
        $sql = "SELECT p.id, p.company_id, {$nameSel} AS name, p.caption, p.scheduled_date, p.status, p.posted, {$typeSel} AS post_type,
                       c.name AS company_name, c.slug AS company_slug, c.logo_url AS company_logo,
                       (SELECT pi.image_url FROM post_images pi WHERE pi.post_id = p.id ORDER BY pi.sort_order ASC, pi.id ASC LIMIT 1) AS first_url,
                       {$mediaSel} AS first_type,
                       (SELECT COUNT(*) FROM post_images pi WHERE pi.post_id = p.id) AS n_media
                FROM posts p
                INNER JOIN companies c ON c.id = p.company_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.scheduled_date ASC, p.id ASC
                LIMIT " . (int)CALENDAR_MAX_POSTS;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $ts = strtotime((string)$r['scheduled_date']);
            if (!$ts) continue;
            $first = trim((string)($r['first_url'] ?? ''));
            $isVid = $first !== '' && ($r['first_type'] !== null ? strtolower((string)$r['first_type']) === 'video'
                                                                 : (function_exists('mediaTypeFromUrl') && mediaTypeFromUrl($first) === 'video'));
            $n = (int)$r['n_media'];
            $type = strtolower((string)$r['post_type']);
            $kind = $type === 'reel' ? 'reel' : ($type === 'story' ? 'story' : ($n > 1 ? 'carousel' : ($isVid ? 'video' : 'image')));
            $out[] = [
                'id'           => (int)$r['id'],
                'company_id'   => (int)$r['company_id'],
                'title'        => calendarPostTitle($r),
                'scheduled_date' => (string)$r['scheduled_date'],
                'day'          => date('Y-m-d', $ts),
                'time'         => date('g:i A', $ts),
                'status'       => (string)$r['status'],
                'posted'       => (int)$r['posted'] === 1,
                'tentative'    => (int)$r['posted'] !== 1,
                'kind'         => $kind,
                'kind_label'   => ['reel' => 'Reel', 'story' => 'Story', 'carousel' => 'Carousel', 'video' => 'Video', 'image' => 'Image'][$kind],
                'first_url'    => $first,
                'first_video'  => $isVid,
                'n_media'      => $n,
                'company_name' => (string)$r['company_name'],
                'company_slug' => (string)$r['company_slug'],
                'company_logo' => (string)($r['company_logo'] ?? ''),
            ];
        }
        return $out;
    }
}

if (!function_exists('calendarPostTitle')) {
    /** The post's name, else its caption's first line, else "Post #id" — at most 80 characters. */
    function calendarPostTitle(array $r): string {
        $name = trim((string)($r['name'] ?? ''));
        $line = trim((string)(preg_split('/\r\n|\r|\n/', trim((string)($r['caption'] ?? '')))[0] ?? ''));
        $t = $name !== '' ? $name : ($line !== '' ? $line : 'Post #' . (int)$r['id']);
        return mb_strlen($t) > 80 ? rtrim(mb_substr($t, 0, 79)) . '…' : $t;
    }
}

if (!function_exists('calendarByDay')) {
    /** Rows grouped by their 'day' ('Y-m-d' → [rows in time order]). */
    function calendarByDay(array $rows): array {
        $out = [];
        foreach ($rows as $r) $out[$r['day']][] = $r;
        return $out;
    }
}

if (!function_exists('postsViewChoice')) {
    /**
     * Posts: 'list' or 'calendar' for this request, and remember an explicit choice.
     *   ?view=calendar|list   that view; remembered in the CALENDAR_VIEW_COOKIE cookie (a year, this portal folder)
     *   no view, but a list-only parameter (status, post, partial, offset, newpost, upload, msg) → 'list'
     *   otherwise             the remembered view (default 'list')
     * $q = the query ($_GET). $remember = false for requests that must not set cookies (partials keep it too —
     * harmless; only a real choice sends Set-Cookie).
     */
    function postsViewChoice(array $q, bool $remember = true): string {
        $asked = is_string($q['view'] ?? null) ? strtolower(trim($q['view'])) : '';
        if ($asked === 'calendar' || $asked === 'list') {
            $have = is_string($_COOKIE[CALENDAR_VIEW_COOKIE] ?? null) ? $_COOKIE[CALENDAR_VIEW_COOKIE] : '';
            if ($remember && $have !== $asked && !headers_sent()) {
                $base = function_exists('portalBasePath') ? portalBasePath() : '';
                setcookie(CALENDAR_VIEW_COOKIE, $asked, ['expires' => time() + 365 * 86400, 'path' => ($base === '' ? '/' : $base . '/'),
                    'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => false]);
                $_COOKIE[CALENDAR_VIEW_COOKIE] = $asked;
            }
            return $asked;
        }
        foreach (['status', 'post', 'partial', 'offset', 'newpost', 'upload', 'msg'] as $k) {
            if (isset($q[$k]) && $q[$k] !== '') return 'list';
        }
        return (($_COOKIE[CALENDAR_VIEW_COOKIE] ?? '') === 'calendar') ? 'calendar' : 'list';
    }
}

if (!function_exists('postsViewSwitchHtml')) {
    /** The "List · Calendar" segmented links on Posts (both views). */
    function postsViewSwitchHtml(string $active, string $listHref, string $calHref): string {
        $items = [
            ['label' => 'List',     'href' => $listHref, 'active' => $active === 'list',     'value' => 'list',     'attrs' => ['data-posts-view' => 'list']],
            ['label' => 'Calendar', 'href' => $calHref,  'active' => $active === 'calendar', 'value' => 'calendar', 'attrs' => ['data-posts-view' => 'calendar']],
        ];
        return '<div class="posts-viewbar">' . segmented($items, ['label' => 'Posts view', 'auto' => true, 'class' => 'posts-viewswitch']) . '</div>';
    }
}
