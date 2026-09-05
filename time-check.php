<?php
/**
 * One-shot diagnostic for the "everything says 7 hours ago" symptom.
 * Visit /time-check in a browser. Compares PHP's idea of "now" to MySQL's,
 * so you can see at a glance which side is offset and by how much.
 *
 * Safe to delete after diagnosis — it makes no DB writes.
 */
require __DIR__ . '/db.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$phpTz       = date_default_timezone_get();
$phpUnix     = time();                                           // timezone-agnostic
$phpLocalNow = date('Y-m-d H:i:s');                              // PHP-tz wall clock
$phpUtcNow   = gmdate('Y-m-d H:i:s');                            // UTC wall clock per PHP

$myNow       = $pdo->query("SELECT NOW()")->fetchColumn();        // MySQL's wall clock
$myUtcNow    = $pdo->query("SELECT UTC_TIMESTAMP()")->fetchColumn();
$mySession   = $pdo->query("SELECT @@session.time_zone")->fetchColumn();
$myGlobal    = $pdo->query("SELECT @@global.time_zone")->fetchColumn();
$mySystemTz  = $pdo->query("SELECT @@system_time_zone")->fetchColumn();
$myUnix      = (int)$pdo->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();

// Round-trip: parse MySQL's NOW() string with PHP's strtotime — the same path
// relativeTime() uses for every activity_log row.
$parsedFromMysql = strtotime((string)$myNow);
$relativeDiff    = $phpUnix - $parsedFromMysql;
$mysqlVsPhpUnix  = $phpUnix - $myUnix;

// Pull a recent activity_log row, if any, so we can show what the feed actually displays.
$row = null;
try {
    $row = $pdo->query("SELECT id, action, created_at FROM activity_log ORDER BY created_at DESC LIMIT 1")->fetch();
} catch (Throwable $e) { /* table may not exist yet */ }

?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>Time check</title>
<style>
  body{font:14px/1.5 -apple-system, BlinkMacSystemFont, sans-serif; padding:30px; max-width:760px; margin:0 auto; color:#222;}
  h1{margin-top:0;} h2{margin-top:24px; font-size:16px; border-bottom:1px solid #ddd; padding-bottom:6px;}
  table{border-collapse:collapse; width:100%; margin-top:8px;}
  td{padding:6px 10px; border-bottom:1px solid #eee; vertical-align:top;}
  td:first-child{color:#666; width:240px;}
  td:last-child{font-family:ui-monospace,Menlo,monospace; font-size:13px;}
  .verdict{padding:14px 18px; border-radius:8px; margin-top:20px; font-weight:600;}
  .ok{background:#dcfce7;color:#166534;border:1px solid #86efac}
  .bad{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
</style></head><body>

<h1>What time does the site think it is?</h1>

<h2>PHP</h2>
<table>
  <tr><td>date_default_timezone_get()</td><td><?= h($phpTz) ?></td></tr>
  <tr><td>date('Y-m-d H:i:s')</td><td><?= h($phpLocalNow) ?></td></tr>
  <tr><td>gmdate(...) — UTC</td><td><?= h($phpUtcNow) ?></td></tr>
  <tr><td>time() — Unix</td><td><?= h($phpUnix) ?></td></tr>
</table>

<h2>MySQL</h2>
<table>
  <tr><td>@@session.time_zone</td><td><?= h($mySession) ?></td></tr>
  <tr><td>@@global.time_zone</td><td><?= h($myGlobal) ?></td></tr>
  <tr><td>@@system_time_zone</td><td><?= h($mySystemTz) ?></td></tr>
  <tr><td>NOW()</td><td><?= h($myNow) ?></td></tr>
  <tr><td>UTC_TIMESTAMP()</td><td><?= h($myUtcNow) ?></td></tr>
  <tr><td>UNIX_TIMESTAMP()</td><td><?= h($myUnix) ?></td></tr>
</table>

<h2>The actual bug — round-trip through relativeTime()</h2>
<table>
  <tr><td>strtotime(MySQL NOW()) using PHP tz</td><td><?= h(date('Y-m-d H:i:s', $parsedFromMysql)) ?> (<?= h($parsedFromMysql) ?>)</td></tr>
  <tr><td>time() − strtotime(NOW())</td><td><?= h($relativeDiff) ?> seconds (<?= h(round($relativeDiff / 3600, 2)) ?> h)</td></tr>
  <tr><td>UNIX_TIMESTAMP() vs PHP time()</td><td><?= h($mysqlVsPhpUnix) ?> seconds (<?= h(round($mysqlVsPhpUnix / 3600, 2)) ?> h)</td></tr>
  <?php if ($row): ?>
    <tr><td>Latest activity_log row</td>
        <td>#<?= h($row['id']) ?> <?= h($row['action']) ?> @ <?= h($row['created_at']) ?></td></tr>
    <tr><td>relativeTime() of that row</td>
        <td><?= h(function_exists('relativeTime') ? relativeTime($row['created_at']) : '(helper not loaded)') ?></td></tr>
  <?php endif; ?>
</table>

<?php
$verdict = '';
$class   = 'ok';
if (abs($mysqlVsPhpUnix) > 60) {
    $class = 'bad';
    $verdict = 'PHP and MySQL disagree on the actual moment by '
             . round($mysqlVsPhpUnix / 3600, 2) . 'h. The server clocks themselves are out of sync.';
} elseif (abs($relativeDiff) > 60) {
    $class = 'bad';
    $verdict = "PHP and MySQL agree on the moment, but PHP's timezone (<code>{$phpTz}</code>) "
             . "differs from MySQL's session zone (<code>" . h($mySession) . "</code>) — that's why "
             . 'a fresh row appears to be ' . round($relativeDiff / 3600, 2) . 'h old. '
             . 'Fix: pin both layers to the same timezone (most direct: set PHP and MySQL to UTC).';
} else {
    $verdict = 'PHP and MySQL agree. Timestamps should display correctly.';
}
?>
<div class="verdict <?= $class ?>"><?= $verdict ?></div>

<p style="margin-top:24px;font-size:13px;color:#666">
  Once you've read the verdict above, you can delete <code>time-check.php</code>.
</p>
</body></html>
