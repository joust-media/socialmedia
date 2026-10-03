<?php
/**
 * Test harness only — deterministic fixtures for the local stack (tests/bootstrap.sh).
 *
 *   php tests/seed.php <app dir> <media dir>
 *
 * Runs after schema.sql + migrate.php. Same rows (and ids) on every run:
 *
 *   companies   1 kenda       Kenda Tires — tires module; feature label "Tires"
 *               2 privacybee  Privacy Bee — emails + pages modules (no tires)
 *               3 hmf         Hollow Mill Farm — no modules, no posts
 *   tires       1 Klever AT2 (refs 3; Series 1 = 8 renders, Series 2 = 6 renders)
 *               2 Klever RT  (refs 2; Series 1 = 5 renders)
 *               3 Kenetica Sport (refs 2; no series)
 *               render statuses per series: 1–5 approved, 6 denied, rest pending
 *   library     kenda: lib_01..lib_08.jpg (01–06 approved, 07–08 pending) + clip_01.mp4 (approved)
 *   posts       kenda  1 pending  "Spring launch hero"   1 image
 *                      2 pending  "AT2 carousel"          3 images (sort 1,2,3 → slide labels A,B,C)
 *                      3 approved "Trail day"
 *                      4 denied   "Winter promo"          client deny note
 *                      5 approved + posted (Scheduled) "Summer reel"
 *                      6 draft    "Behind the scenes"     caption set
 *                      7 draft    (no name)                empty caption — Send for review must 422
 *               privacybee 8 pending "Privacy week"
 *   emails      privacybee: W1 draft, W2 pending, W3 approved, R1 denied, R2 approved + live; groups Free / Renewal;
 *               flow "Welcome" = W1 → W2 → W3
 *   pages       privacybee: 1 pending (url), 2 approved + live (url), 3 draft (url)
 *   tasks       kenda: 2
 *   contacts    1 jane@kenda.example (Jane Kenda) · 2 ops@kenda.example · 3 pat@privacybee.example · 4 farm@hmf.example
 *               (no sessions, tokens or rate-limit rows; clean links off — <app>/.htaccess removed)
 *   activity    a client approve / deny, an admin "created" row for draft post 6 (must never reach the client feed)
 *   email       Google disconnected, no inbound mail / client email queue / seen markers; every client email switch on,
 *               every contact subscribed; the fake Google (tests/google-stub.php) emptied
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$_, $app, $media] = $argv + [null, null, null];
if (!$app || !$media || !is_file($app . '/config.php')) {
    fwrite(STDERR, "usage: php tests/seed.php <app dir> <media dir>\n");
    exit(2);
}
$app = rtrim($app, '/');
$media = rtrim($media, '/');
date_default_timezone_set('America/New_York');
$cfg = require $app . '/config.php';
$pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4", $cfg['username'], $cfg['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET time_zone = '" . date('P') . "'");

/** A labelled solid-colour JPEG / PNG (GD) — big enough for the preview pipeline, small on disk. */
function fixtureImage(string $path, string $label, array $rgb, int $w = 800, int $h = 800): void {
    @mkdir(dirname($path), 0777, true);
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledellipse($im, (int)($w / 2), (int)($h / 2), (int)($w * .6), (int)($h * .6), imagecolorallocate($im, 25, 25, 25));
    $tw = max(1, strlen($label) * 9);
    $t = imagecreatetruecolor($tw, 16);
    imagefill($t, 0, 0, imagecolorallocate($t, $rgb[0], $rgb[1], $rgb[2]));
    imagestring($t, 5, 0, 0, $label, imagecolorallocate($t, 255, 255, 255));
    imagecopyresized($im, $t, 30, 30, 0, 0, min($w - 60, $tw * 4), 64, $tw, 16);
    imagestring($im, 5, 30, $h - 40, 'fixture', $white);
    if (str_ends_with($path, '.png')) imagepng($im, $path); else imagejpeg($im, $path, 82);
}

/** A tiny file the portal's container sniff accepts as MP4 (ftyp isom) — not playable, never decoded by tests. */
function fixtureVideo(string $path): void {
    @mkdir(dirname($path), 0777, true);
    $ftyp = pack('N', 24) . 'ftyp' . 'isom' . pack('N', 512) . 'isom' . 'mp41';
    $mdat = pack('N', 8 + 1024) . 'mdat' . str_repeat("\0", 1024);
    file_put_contents($path, $ftyp . $mdat);
}

// media/tires/ and media/library/ hold only fixtures: a suite that uploaded into them (the Upload sheet) must not leave
// files behind that the next suite's folder scan (syncLibraryImages / syncTireSeries) would register as new rows.
foreach (['tires', 'library'] as $sub) {
    $dir = $media . '/' . $sub;
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
}

// Start from no image previews (<dir>/.thumbs/) and no export / preview-job state, so preview tests see the lazy path.
foreach ([$app . '/uploads', $media] as $root) {
    if (!is_dir($root)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (strpos($p, '/.thumbs/') !== false || strpos($p, '/.exports/') !== false) { $f->isDir() ? @rmdir($p) : @unlink($p); }
    }
    foreach (['.thumbs', '.exports'] as $d) { foreach (glob($root . '/{,*/,*/*/,*/*/*/}' . $d, GLOB_BRACE | GLOB_ONLYDIR) ?: [] as $dd) @rmdir($dd); }
}

$colors = [[30, 60, 110], [110, 40, 30], [30, 90, 50], [90, 60, 120], [120, 100, 20], [40, 100, 110], [140, 60, 90], [60, 60, 60]];

// ---- companies + modules ---------------------------------------------------------------
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach (['activity_log', 'company_modules', 'tire_images', 'tire_series', 'tires', 'library_images', 'post_images',
          'post_categories', 'posts', 'emails', 'email_groups', 'email_group_map', 'email_flows', 'email_flow_steps',
          'pages', 'page_files', 'tasks', 'companies',
          'client_contacts', 'client_login_tokens', 'client_sessions', 'auth_attempts'] as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$pdo->exec("INSERT INTO companies (id, name, slug, feature_label, logo_url, default_hashtags, product_type, industry) VALUES
    (1, 'Kenda Tires', 'kenda', 'Tires', '', '#Kenda #KendaTires', 'tires', 'automotive'),
    (2, 'Privacy Bee', 'privacybee', NULL, '', '#PrivacyBee', 'software', 'privacy'),
    (3, 'Hollow Mill Farm', 'hmf', NULL, '', '', '', '')");
// Client contacts (sign-in): the test seat (tests/test-auth.php ?__role=client) signs in as the FIRST contact of a client.
$pdo->exec("INSERT INTO client_contacts (id, company_id, email, name) VALUES
    (1, 1, 'jane@kenda.example', 'Jane Kenda'),
    (2, 1, 'ops@kenda.example', NULL),
    (3, 2, 'pat@privacybee.example', 'Pat Bee'),
    (4, 3, 'farm@hmf.example', NULL)");
// Clean links start OFF in every suite (a suite that installs them writes <app>/.htaccess; the harness router emulates it).
foreach (array_merge([$app . '/.htaccess'], glob($app . '/.htaccess.bak-*') ?: []) as $f) { if (is_file($f)) @unlink($f); }
$mods = $pdo->query("SELECT slug, id FROM modules")->fetchAll(PDO::FETCH_KEY_PAIR);
$cm = $pdo->prepare("INSERT INTO company_modules (company_id, module_id) VALUES (?, ?)");
$cm->execute([1, $mods['tires']]);
$cm->execute([2, $mods['emails']]);
$cm->execute([2, $mods['pages']]);

// ---- tires, series, references, renders -------------------------------------------------
$tires = [
    [1, 'Klever AT2', 'klever-at2', 3, [[1, 'Series 1', 'series-1', 8], [2, 'Series 2', 'series-2', 6]]],
    [2, 'Klever RT', 'klever-rt', 2, [[3, 'Series 1', 'series-1', 5]]],
    [3, 'Kenetica Sport', 'kenetica-sport', 2, []],
];
$ci = 0;
$insTire = $pdo->prepare("INSERT INTO tires (id, company_id, module_id, name) VALUES (?, 1, ?, ?)");
$insImg  = $pdo->prepare("INSERT INTO tire_images (tire_id, series_id, image_url, sort_order, status, display_name) VALUES (?, ?, ?, ?, ?, ?)");
$insSer  = $pdo->prepare("INSERT INTO tire_series (id, tire_id, name, slug, folder, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($tires as [$tid, $name, $slug, $refs, $series]) {
    $insTire->execute([$tid, $mods['tires'], $name]);
    for ($i = 1; $i <= $refs; $i++) {
        $fn = "ref_{$slug}_{$i}.jpg";
        fixtureImage("$app/uploads/$fn", "$name REF $i", $colors[$ci++ % 8]);
        $insImg->execute([$tid, null, "uploads/$fn", $i, 'approved', "$name reference $i"]);
    }
    $so = 0;
    foreach ($series as [$sid, $sname, $sslug, $n]) {
        $insSer->execute([$sid, $tid, $sname, $sslug, $sname, ++$so]);
        for ($i = 1; $i <= $n; $i++) {
            $fn = sprintf('render_%02d.jpg', $i);
            fixtureImage("$media/tires/$slug/$sname/$fn", "$name $sname #$i", $colors[$ci++ % 8]);
            $st = $i <= 5 ? 'approved' : ($i === 6 ? 'denied' : 'pending');
            $insImg->execute([$tid, $sid, "media/tires/$slug/$sname/$fn", $i, $st, "$name $sname $i"]);
        }
    }
}

// ---- library (media/library/<slug>/) -------------------------------------------------------
$insLib = $pdo->prepare("INSERT INTO library_images (company_id, filename, status) VALUES (1, ?, ?)");
for ($i = 1; $i <= 8; $i++) {
    $fn = sprintf('lib_%02d.jpg', $i);
    fixtureImage("$media/library/kenda/$fn", "Library $i", $colors[$i % 8]);
    $insLib->execute([$fn, $i <= 6 ? 'approved' : 'pending']);
}
fixtureVideo("$media/library/kenda/clip_01.mp4");
$insLib->execute(['clip_01.mp4', 'approved']);
@mkdir("$media/library/privacybee", 0777, true);
@mkdir("$media/library/hmf", 0777, true);

// ---- posts ---------------------------------------------------------------------------------
$today = new DateTimeImmutable('today 10:00');
$posts = [
    // id, company, name, caption, status, posted, day offset, type, images
    [1, 1, 'Spring launch hero', 'Built for the trail. The Klever AT2 is here.', 'pending', 0, 2, 'post', ['Hero']],
    [2, 1, 'AT2 carousel', 'Three looks, one tire. Swipe through the Klever AT2.', 'pending', 0, 4, 'post', ['Slide A', 'Slide B', 'Slide C']],
    [3, 1, 'Trail day', 'Saturday on the ridge with the crew.', 'approved', 0, 6, 'post', ['Trail']],
    [4, 1, 'Winter promo', 'Winter is coming — stock up on Klever RT.', 'denied', 0, 8, 'post', ['Winter']],
    [5, 1, 'Summer reel', 'Summer highlights.', 'approved', 1, 1, 'reel', ['Summer']],
    [6, 1, 'Behind the scenes', 'Behind the scenes at the shoot (draft).', 'draft', 0, 10, 'post', ['BTS']],
    [7, 1, null, '', 'draft', 0, 12, 'post', ['Upload']],
    [8, 2, 'Privacy week', 'Your data, your rules.', 'pending', 0, 3, 'post', ['Privacy']],
];
$insPost = $pdo->prepare("INSERT INTO posts (id, company_id, name, caption, hashtags, scheduled_date, status, posted, posted_at, post_type, client_comment)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$insPi = $pdo->prepare("INSERT INTO post_images (post_id, image_url, sort_order, media_type) VALUES (?, ?, ?, 'image')");
foreach ($posts as $k => [$id, $co, $name, $caption, $st, $posted, $days, $type, $imgs]) {
    $when = $today->modify("+{$days} days")->format('Y-m-d H:i:s');
    $insPost->execute([$id, $co, $name, $caption, $co === 1 ? '#Kenda' : '#PrivacyBee', $when, $st, $posted,
        $posted ? $when : null, $type, $st === 'denied' ? 'Please use the darker render' : null]);
    foreach ($imgs as $j => $label) {
        $fn = sprintf('img_post%02d_%d.jpg', $id, $j + 1);
        fixtureImage("$app/uploads/$fn", "Post $id $label", $colors[($k + $j) % 8]);
        $insPi->execute([$id, "uploads/$fn", $j + 1]);
    }
}
$pdo->exec("INSERT INTO post_categories (post_id, category_id) VALUES (1, 1), (2, 1), (2, 4)");

// ---- emails / groups / flows (privacybee) -------------------------------------------------------
$em = [
    [1, 'W1', 'Welcome to Privacy Bee', 'draft', 0],
    [2, 'W2', 'Your first scan', 'pending', 0],
    [3, 'W3', 'Three quick wins', 'approved', 0],
    [4, 'R1', 'Time to renew', 'denied', 0],
    [5, 'R2', 'Renewal offer', 'approved', 1],
];
$insEm = $pdo->prepare("INSERT INTO emails (id, company_id, code, title, subject, status, live, live_at, sort_order, trigger_text, html_url)
                        VALUES (?, 2, ?, ?, ?, ?, ?, ?, ?, 'Day 1 after signup', '')");
foreach ($em as $k => [$id, $code, $title, $st, $live]) {
    $insEm->execute([$id, $code, $title, "Subject: $title", $st, $live, $live ? date('Y-m-d H:i:s') : null, $k]);
}
$pdo->exec("INSERT INTO email_groups (id, company_id, name, slug, sort_order) VALUES (1, 2, 'Free', 'free', 1), (2, 2, 'Renewal', 'renewal', 2)");
$pdo->exec("INSERT INTO email_group_map (email_id, group_id) VALUES (1, 1), (2, 1), (3, 1), (4, 2), (5, 2)");
$pdo->exec("INSERT INTO email_flows (id, company_id, name, slug, sort_order) VALUES (1, 2, 'Welcome', 'welcome', 1)");
$pdo->exec("INSERT INTO email_flow_steps (flow_id, email_id, position, timing_text) VALUES (1, 1, 0, 'At signup'), (1, 2, 1, '1 day after W1'), (1, 3, 2, '3 days after W2')");

// ---- pages (privacybee) ---------------------------------------------------------------------------
$pdo->exec("INSERT INTO pages (id, company_id, title, slug, source, url, status, live, sort_order) VALUES
    (1, 2, 'Spring promo landing', 'spring-promo', 'url', 'https://example.com/spring', 'pending', 0, 1),
    (2, 2, 'Pricing', 'pricing', 'url', 'https://example.com/pricing', 'approved', 1, 2),
    (3, 2, 'Webinar signup', 'webinar', 'url', 'https://example.com/webinar', 'draft', 0, 3)");
@mkdir("$media/pages", 0777, true);
// Page folders + portal-hosted email HTML written by earlier runs (assign.php / page-upload.php) go, so
// slugs and file names come out the same on every run.
foreach (array_merge(glob("$media/pages/*", GLOB_ONLYDIR) ?: [], ["$media/emails"]) as $dir) {
    if (!is_dir($dir) || is_link($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}

// ---- tasks + activity ------------------------------------------------------------------------------
$pdo->exec("INSERT INTO tasks (company_id, title, status, priority, created_by) VALUES (1, 'Q4 render batch', 'open', 'normal', 'admin'), (1, 'Holiday posts', 'in_progress', 'high', 'client')");
$pdo->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail, created_at) VALUES
    (1, 'post', 3, 'approved', 'client', 'Trail day approved', NULL, NOW() - INTERVAL 2 HOUR),
    (1, 'post', 4, 'denied', 'client', 'Winter promo denied', NULL, NOW() - INTERVAL 90 MINUTE),
    (1, 'post', 4, 'commented', 'client', 'Comment on Winter promo', 'Please use the darker render', NOW() - INTERVAL 90 MINUTE),
    (1, 'post', 6, 'created', 'admin', 'Created post #6: Behind the scenes', NULL, NOW() - INTERVAL 30 MINUTE)");

// ---- notifications (migrate.php 36–39) ------------------------------------------------------------
// Lance (admin_users 1, the test admin seat's email) is mapped to Slack user U0LANCE; Kenda and Privacy Bee post to
// channels C0KENDA / C0PBEE on the fake Slack (tests/slack-stub.php); Hollow Mill Farm has no channel. The outbox,
// threads and inbox start empty; the stub's call log and the mail sink are cleared.
$has = static function (string $t) use ($pdo): bool {
    return (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn() > 0;
};
if ($has('notify_outbox')) {
    foreach (['notify_outbox', 'notify_threads', 'slack_inbox', 'notify_clients', 'admin_users'] as $t) $pdo->exec("TRUNCATE TABLE `{$t}`");
    $pdo->exec("INSERT INTO admin_users (id, name, email, slack_user_id, role) VALUES (1, 'Lance', 'lance@joustmedia.com', 'U0LANCE', 'owner')");
    $pdo->exec("INSERT INTO notify_clients (company_id, slack_channel_id, slack_channel_name) VALUES (1, 'C0KENDA', '#portal-kenda'), (2, 'C0PBEE', '#portal-privacybee')");
    $meta = $pdo->prepare("INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    foreach ([['notify_since', date('Y-m-d H:i:s', time() - 2 * 86400)], ['notify_t1_minutes', '60'], ['notify_t2_minutes', '240'],
              ['notify_summary_hour', '8'], ['notify_summary_last', date('Y-m-d')], ['notify_cron_last', ''], ['digest_open_last', '1970-01-01 00:00:00'],
              ['digest_lock_until', '1970-01-01 00:00:00']] as $kv) $meta->execute($kv);
}
// ---- email through Google, client emails, tracking (migrate.php 45–49) ------------------------------------------------
// Google starts disconnected; no inbound mail, no queued client emails, nothing "seen"; Kenda's and Privacy Bee's
// client email switches on (set below — the app's default is off) and every contact subscribed (contacts were re-created above). The fake Google's state
// (tests/google-stub.php: mailbox, sent mail, call log, failure mode) is wiped.
if ($has('google_account')) {
    foreach (['google_account', 'email_inbound', 'notify_email_refs', 'client_email_queue', 'thread_seen'] as $t) $pdo->exec("TRUNCATE TABLE `{$t}`");
    $meta = $pdo->prepare("INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    foreach ([['unread_since', '0'], ['client_live_last', date('Y-m-d')], ['notify_weekly_last', date('Y-m-d')], ['client_email_since', date('Y-m-d H:i:s', time() - 86400)]] as $kv) $meta->execute($kv);
    // Client emails start OFF in the app (migrate.php 47 / 50); the suites that exercise them want Kenda and Privacy Bee
    // on (Hollow Mill Farm has no row → off). Reminders off, mail() not allowed, no quiet hours.
    $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notify_clients'")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('email_review', $cols, true)) $pdo->exec("UPDATE notify_clients SET email_review = 1, email_replies = 1, email_live = 1 WHERE company_id IN (1, 2)");
    if (in_array('email_remind', $cols, true)) $pdo->exec("UPDATE notify_clients SET email_remind = 0, remind_days = 3");
    foreach ([['client_emails_allow_mail', '0'], ['notify_quiet_start', ''], ['notify_quiet_end', ''], ['client_remind_last', date('Y-m-d')]] as $kv) $meta->execute($kv);
}
$root = dirname($app, 2);
$gdir = $root . '/google';
if (is_dir($gdir)) {
    foreach (array_merge(glob($gdir . '/*') ?: [], glob($gdir . '/sent/*') ?: []) as $f) { if (is_file($f)) @unlink($f); }
}
@unlink($root . '/slack-calls.jsonl');
@unlink($root . '/slack-calls.jsonl.fail');
if (!empty($cfg['mail_sink_dir']) && is_dir($cfg['mail_sink_dir'])) {
    foreach (glob(rtrim($cfg['mail_sink_dir'], '/') . '/*') ?: [] as $f) @unlink($f);
}

$n = static fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
printf("  seeded: %d companies, %d tires, %d tire images, %d library, %d posts (%d media), %d emails, %d pages\n",
    $n('companies'), $n('tires'), $n('tire_images'), $n('library_images'), $n('posts'), $n('post_images'), $n('emails'), $n('pages'));
