<?php
/**
 * Drive — Google Drive storage view (admin only, unscoped; scratchpad drive-design.md §7).
 * How full the shared Drive is, which clients hold the space, and what to offboard first.
 * Read-only: nothing here writes to Drive or to the database; the nightly Apps Script →
 * drive-ingest.php run fills the drive_* tables that drive-lib.php reads.
 *
 *   ?view=overview (default)   capacity strip · clients treemap · offboard top 5 · 90-day line · by type · quick wins
 *   ?view=client&slug=kenda    one client: stats, folders treemap + list, largest files
 *          [&folder=<id>]        drill into a folder (breadcrumb)
 *   ?view=offboard             every candidate (cap 2,000), client-side filters / sort / paging / CSV
 *
 * States: no tables or no snapshot yet → setup pointer; stale (> 48 h) or partial snapshot → banner;
 * no limit → usage only; < 30 days of history → "based on N days"; zero candidates; empty client.
 * Chrome like studio.php (Studio tab active, Joust mark, back to Studio). Read model: drive-lib.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
if (is_file(__DIR__ . '/drive-lib.php')) { require_once __DIR__ . '/drive-lib.php'; }
requireAdmin();

require_once __DIR__ . '/partials/components/drive-ui.php';
require_once __DIR__ . '/partials/components/drive-treemap.php';
require_once __DIR__ . '/partials/components/drive-trendline.php';
require_once __DIR__ . '/partials/components/drive-bars.php';
require_once __DIR__ . '/partials/components/drive-capacity.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Filter key for a type bucket (drive-lib.php's typeBucket vocabulary → the five offboard filters). */
function dvTypeKey($bucket): string {
    $k = strtolower(trim((string)$bucket));
    if (in_array($k, ['video', 'videos', 'movie'], true)) return 'video';
    if (in_array($k, ['design', 'designs', 'psd', 'ai', 'indd', 'sketch', 'figma'], true)) return 'design';
    if (in_array($k, ['image', 'images', 'photo', 'photos', 'picture', 'pictures'], true)) return 'images';
    if (in_array($k, ['archive', 'archives', 'zip', 'compressed'], true)) return 'archives';
    return 'other';
}
function dvUrl(array $q = []): string { return clientUrl('drive.php', $q); }
function dvClientUrl(string $slug, string $folder = ''): string { return dvUrl(['view' => 'client', 'slug' => $slug, 'folder' => $folder]); }
function dvDaysSince($iso): ?int {
    $t = $iso ? strtotime((string)$iso) : false;
    return $t ? max(0, (int)floor((time() - $t) / 86400)) : null;
}
/** One candidate / largest-file row → the shape drive.js renders (and the CSV exports). */
function dvRow(array $r, array $clientNames): array {
    $slug = (string)($r['clientSlug'] ?? '');
    if ($slug === '' || $slug === 'unfiled') $slug = 'unfiled';   // files outside every client folder
    $idle = isset($r['idleDays']) ? (int)$r['idleDays'] : (dvDaysSince($r['viewedAt'] ?? ($r['modifiedAt'] ?? null)) ?? 0);
    return [
        'id'         => (string)($r['id'] ?? ''),
        'name'       => (string)($r['name'] ?? ''),
        'path'       => (string)($r['path'] ?? ''),
        'client'     => $slug,
        'clientName' => $slug === 'unfiled' ? '(unfiled)' : ($clientNames[$slug] ?? $slug),
        'type'       => dvTypeKey($r['typeBucket'] ?? ''),
        'typeLabel'  => driveUiTypeLabel(dvTypeKey($r['typeBucket'] ?? '')),
        'bytes'      => (int)round((float)($r['bytes'] ?? 0)),
        'size'       => driveUiBytes($r['bytes'] ?? 0),
        'modified'   => driveUiDate($r['modifiedAt'] ?? ''),
        'viewed'     => driveUiDate($r['viewedAt'] ?? ''),
        'idleDays'   => $idle,
        'idle'       => driveUiIdle($idle),
        'score'      => round((float)($r['score'] ?? 0), 1),
        'link'       => driveUiSafeLink($r['parentLink'] ?? ''),
    ];
}
function dvRowHtml(array $row, int $rank, float $scoreMax, bool $withClient = true, bool $withScore = true, string $extraCls = ''): string {
    $w = $scoreMax > 0 ? min(100, 100 * $row['score'] / $scoreMax) : 0;
    $out  = '<tr class="drive-row' . ($extraCls !== '' ? ' ' . h($extraCls) : '') . '" data-drive-row="' . h($row['id']) . '" data-type="' . h($row['type']) . '" data-client="' . h($row['client']) . '" data-idle="' . (int)$row['idleDays'] . '" data-bytes="' . (int)$row['bytes'] . '" data-score="' . h((string)$row['score']) . '">';
    $out .= '<td class="drive-td-rank">' . $rank . '</td>';
    $out .= '<td class="drive-td-file"><span class="drive-file-name">' . h($row['name']) . '</span><span class="drive-file-path">' . h($row['path']) . '</span></td>';
    if ($withClient) $out .= '<td class="drive-td-client">' . h($row['clientName']) . '</td>';
    $out .= '<td class="drive-td-type">' . h($row['typeLabel']) . '</td>';
    $out .= '<td class="drive-td-size">' . h($row['size']) . '</td>';
    $out .= '<td class="drive-td-date">' . h($row['modified']) . '</td>';
    $out .= '<td class="drive-td-date">' . ($row['viewed'] !== '' ? h($row['viewed']) : '<span class="text-tertiary">never</span>') . '<span class="drive-file-idle">' . h($row['idle']) . ' idle</span></td>';
    if ($withScore) $out .= '<td class="drive-td-score"><span class="drive-score" aria-hidden="true"><i style="width:' . h(number_format($w, 1, '.', '')) . '%"></i></span><span class="drive-score-n">' . h(rtrim(rtrim(number_format($row['score'], 1), '0'), '.')) . '</span></td>';
    $out .= '<td class="drive-td-open">' . ($row['link'] !== '' ? '<a class="ui-btn ui-btn--sm ui-btn--gray" href="' . h($row['link']) . '" target="_blank" rel="noopener noreferrer">Open folder</a>' : '<span class="text-tertiary">—</span>') . '</td>';
    return $out . '</tr>';
}

// ---------------------------------------------------------------------
// Routing + read model
// ---------------------------------------------------------------------
$view   = strtolower(trim((string)($_GET['view'] ?? 'overview')));
if (!in_array($view, ['overview', 'client', 'offboard'], true)) $view = 'overview';
$slug   = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($_GET['slug'] ?? ''))));
$folder = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string)($_GET['folder'] ?? '')));

$snapParam = (int)($_GET['snapshot'] ?? 0);   // activity-feed deep link (drive-design.md §2); falls back to the latest
$libReady  = function_exists('hasDriveTables') && function_exists('driveLatestSnapshot');
$hasTables = false; $snap = null;
try {
    $hasTables = $libReady && hasDriveTables($pdo);
    if ($hasTables && $snapParam > 0 && function_exists('driveSnapshot')) $snap = driveSnapshot($pdo, $snapParam);
    if ($hasTables && !$snap) $snap = driveLatestSnapshot($pdo);
} catch (Throwable $e) {
    error_log('drive.php read model failed: ' . $e->getMessage());
    $snap = null;
}
$sid = $snap ? (int)($snap['id'] ?? 0) : 0;

// Chrome (studio.php's unscoped shape: Studio tab, Joust mark, back button)
$activeTab   = 'studio';
$navTrailing = joustAvatar();
$pageWide    = true;
$bodyClass   = 'page-drive page-drive--' . $view;
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/drive.css')) . '">';
$footExtra   = '';
$htmlTitle   = 'Drive — Joust Media';
$navLinks    = [];
if ($view === 'overview') {
    $pageTitle = 'Drive'; $navSubtitle = 'Google Drive storage';
    $navBack   = ['href' => pagePath('studio'), 'label' => 'Studio'];
} elseif ($view === 'offboard') {
    $pageTitle = 'Offboard first'; $navSubtitle = 'Drive';
    $navBack   = ['href' => dvUrl(), 'label' => 'Drive'];
} else {
    $pageTitle = 'Client'; $navSubtitle = 'Drive';   // replaced with the client's name once known
    $navBack   = ['href' => dvUrl(), 'label' => 'Drive'];
}

$snapLine = ''; $banner = '';
if ($snap) {
    $hist = [];
    try { $hist = driveHistory($pdo, 90); } catch (Throwable $e) { error_log('driveHistory failed: ' . $e->getMessage()); }
    $histDays = count($hist);
    $snapLine = '<p class="drive-snapline text-secondary" data-drive-snapshot="' . h((string)($snap['taken_at'] ?? '')) . '">'
              . 'Snapshot ' . h(driveUiDateTime($snap['taken_at'] ?? '')) . ' · ' . h(number_format((int)($snap['file_count'] ?? 0))) . ' files'
              . ($histDays > 0 ? ' · ' . $histDays . ' ' . ($histDays === 1 ? 'day' : 'days') . ' of history' : '') . '</p>';
    $isStale   = function_exists('driveSnapshotIsStale') ? (bool)driveSnapshotIsStale($snap) : false;
    $isPartial = strtolower((string)($snap['status'] ?? '')) === 'partial';
    if ($isPartial) {
        $banner = '<div class="drive-banner" role="status" data-drive-banner="partial"><strong>Last run was partial.</strong> Some folders were not scanned, so client sizes may read low until the next full run.</div>';
    } elseif ($isStale) {
        $age = dvDaysSince($snap['taken_at'] ?? null);
        $banner = '<div class="drive-banner" role="status" data-drive-banner="stale"><strong>This snapshot is ' . ($age !== null && $age >= 2 ? $age . ' days' : 'more than 48 hours') . ' old.</strong> The nightly run may have failed — check the Apps Script trigger.</div>';
    }
}

// ---------------------------------------------------------------------
// Not set up / no snapshot yet
// ---------------------------------------------------------------------
if (!$snap) {
    $pageTitle = 'Drive'; $navSubtitle = 'Google Drive storage';
    $navBack   = ['href' => pagePath('studio'), 'label' => 'Studio'];
    include __DIR__ . '/partials/layout-top.php';
    $why = !$libReady ? 'The Drive storage module is not installed on this server yet.'
         : (!$hasTables ? 'The Drive tables have not been created yet — open migrate.php once.' : 'The tables are ready; the Apps Script has not posted a snapshot yet.');
    ?>
    <section class="ui-card ui-card--quiet drive-setup" data-drive-state="<?= !$libReady ? 'nolib' : (!$hasTables ? 'notables' : 'nosnapshot') ?>">
      <h2 class="ui-card-title">The first nightly run hasn't happened yet</h2>
      <p class="text-secondary"><?= h($why) ?></p>
      <ol class="drive-setup-steps">
        <li>Run <code>migrate.php</code> so the <code>drive_*</code> tables exist.</li>
        <li>Deploy the Apps Script and point it at <code>drive-ingest.php</code> with the shared secret from <code>config.php</code>.</li>
        <li>Wait for the nightly trigger (or run the script once by hand) — this page fills in on the next load.</li>
      </ol>
      <p class="text-secondary">Setup details: README › <em>Google Drive storage</em>.</p>
    </section>
    <?php
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

// Read model for the views
$clients = []; $clientNames = [];
try { $clients = driveClients($pdo, $sid) ?: []; } catch (Throwable $e) { error_log('driveClients failed: ' . $e->getMessage()); }
foreach ($clients as $c) { $clientNames[(string)($c['slug'] ?? '')] = (string)($c['name'] ?? $c['slug'] ?? ''); }
$driveBytes = max(0.0, (float)($snap['drive_bytes'] ?? 0));
$proj = [];
try { $proj = function_exists('driveProjection') ? (driveProjection($snap) ?: []) : []; } catch (Throwable $e) { error_log('driveProjection failed: ' . $e->getMessage()); }
$limitRaw = $snap['quota_limit'] ?? ($snap['limit_bytes'] ?? null);   // design §3: quota_limit (null = no limit)
$limit = $limitRaw !== null && (float)$limitRaw > 0 ? (float)$limitRaw : null;
$growing = array_key_exists('growing', $proj) ? $proj['growing'] : (!empty($proj['notGrowing']) ? false : ((float)($proj['burnRatePerDay'] ?? 0) > 0));
/** unfiled flag: design §3 'unfiled', older sketch 'isUnfiled', or the reserved slug */
function dvIsUnfiled(array $c): bool { return !empty($c['unfiled']) || !empty($c['isUnfiled']) || (string)($c['slug'] ?? '') === 'unfiled'; }

$segNav = segmented([
    ['label' => 'Overview', 'href' => dvUrl(), 'active' => $view === 'overview'],
    ['label' => 'Offboard list', 'href' => dvUrl(['view' => 'offboard']), 'active' => $view === 'offboard'],
], ['auto' => true, 'label' => 'Drive views', 'class' => 'drive-segmented']);

// =====================================================================
// OVERVIEW
// =====================================================================
if ($view === 'overview') {
    // Top 5 + the totals for the footer. driveCandidates() defaults to 200 rows, so the count comes from
    // driveCandidateCount() and the bytes from the clients' candidateBytes when the lib provides them.
    $cands = [];
    try { $cands = driveCandidates($pdo, $sid, ['limit' => 2000]) ?: []; } catch (Throwable $e) { error_log('driveCandidates failed: ' . $e->getMessage()); }
    usort($cands, static fn($a, $b) => (float)($b['score'] ?? 0) <=> (float)($a['score'] ?? 0));
    $candCount = count($cands);
    if (function_exists('driveCandidateCount')) { try { $candCount = max($candCount, (int)driveCandidateCount($pdo, $sid)); } catch (Throwable $e) { error_log('driveCandidateCount failed: ' . $e->getMessage()); } }
    $candBytes = array_sum(array_map(static fn($r) => (float)($r['bytes'] ?? 0), $cands));
    $clientCandBytes = 0.0; $haveClientCand = false;
    foreach ($clients as $c) { if (isset($c['candidateBytes'])) { $haveClientCand = true; $clientCandBytes += (float)$c['candidateBytes']; } }
    if ($haveClientCand && $clientCandBytes > $candBytes) $candBytes = $clientCandBytes;
    $top = array_slice($cands, 0, 5);
    $scoreMax = $top ? max(array_map(static fn($r) => (float)($r['score'] ?? 0), $top)) : 0.0;
    $byType = [];
    try { $byType = driveByType($pdo, $sid) ?: []; } catch (Throwable $e) { error_log('driveByType failed: ' . $e->getMessage()); }
    $wins = [];
    try { $wins = driveQuickWins($pdo, $sid) ?: []; } catch (Throwable $e) { error_log('driveQuickWins failed: ' . $e->getMessage()); }

    // Treemap items: slug on the tile, unfiled dashed, tiles < 2 % grouped
    $tiles = []; $hasUnfiled = false;
    foreach ($clients as $c) {
        $isUnfiled = dvIsUnfiled($c);
        if ($isUnfiled) $hasUnfiled = true;
        $tiles[] = [
            'label'      => $isUnfiled ? '(unfiled)' : (string)($c['slug'] ?? ''),
            'sub'        => $isUnfiled ? '(unfiled)' : (string)($c['name'] ?? $c['slug'] ?? ''),
            'bytes'      => (float)($c['bytes'] ?? 0),
            'stalePct'   => (float)($c['stalePct'] ?? 0),
            'staleBytes' => (float)($c['staleBytes'] ?? 0),
            'pctOfDrive' => isset($c['pctOfDrive']) ? (float)$c['pctOfDrive'] : null,
            'href'       => dvClientUrl((string)($c['slug'] ?? '')),
            'dashed'     => $isUnfiled,
            'attrs'      => ['data-drive-client' => (string)($c['slug'] ?? '')],
        ];
    }
    // Phone: the same clients as plain bars (the treemap is hidden under 720px)
    $barRows = [];
    $sortedClients = $clients;
    usort($sortedClients, static fn($a, $b) => (float)($b['bytes'] ?? 0) <=> (float)($a['bytes'] ?? 0));
    foreach (array_slice($sortedClients, 0, 8) as $c) {
        $barRows[] = ['label' => dvIsUnfiled($c) ? '(unfiled)' : (string)($c['name'] ?? $c['slug']), 'bytes' => (float)($c['bytes'] ?? 0),
                      'pct' => isset($c['pctOfDrive']) ? (float)$c['pctOfDrive'] : null, 'href' => dvClientUrl((string)($c['slug'] ?? ''))];
    }
    // By-type bars: the five buckets in a fixed order
    $typeBytes = ['video' => 0.0, 'design' => 0.0, 'images' => 0.0, 'archives' => 0.0, 'other' => 0.0];
    foreach ($byType as $t) { $typeBytes[dvTypeKey($t['bucket'] ?? '')] += (float)($t['bytes'] ?? 0); }
    $typeRows = [];
    foreach ($typeBytes as $k => $b) $typeRows[] = ['label' => driveUiTypeLabel($k), 'bytes' => $b, 'pct' => $driveBytes > 0 ? 100 * $b / $driveBytes : null, 'attrs' => ['data-type' => $k]];

    $trendTitle = $histDays < 90 ? 'Last ' . $histDays . ' ' . ($histDays === 1 ? 'day' : 'days') : 'Last 90 days';
    $trendSub   = $histDays < 30 ? 'based on ' . $histDays . ' ' . ($histDays === 1 ? 'day' : 'days') . ' of history' : 'usage, limit and where the current pace lands';

    include __DIR__ . '/partials/layout-top.php';
    ?>
    <div class="drive-toolbar"><?= $segNav ?><?= $snapLine ?></div>
    <?= $banner ?>
    <?= driveCapacityStrip($snap, $proj) ?>

    <div class="drive-grid drive-grid--main">
      <section class="ui-card drive-card drive-card--clients" aria-labelledby="drive-clients-title">
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title" id="drive-clients-title">Clients by size</h3>
          <p class="ui-card-subtitle">Area = space used · shade = share untouched for 6+ months · tap a client to drill in</p></div></div>
        <div class="ui-card-body">
          <?php if (!$tiles): ?>
            <div class="ui-empty" data-drive-empty="clients">No client folders in this snapshot.</div>
          <?php else: ?>
            <div class="drive-treemap-wrap">
              <?= driveTreemap($tiles, ['total' => $driveBytes > 0 ? $driveBytes : null, 'minShare' => 2, 'aspect' => 1.6, 'ariaLabel' => 'Clients by space used',
                                        'attrs' => ['data-drive-treemap' => 'clients'], 'groupLabel' => static fn(int $n, float $b) => $n . ' smaller clients']) ?>
              <?= driveTreemapLegend($hasUnfiled) ?>
            </div>
            <div class="drive-clientbars">
              <?= driveBars($barRows, ['max' => $barRows ? (float)$barRows[0]['bytes'] : 0, 'ariaLabel' => 'Biggest clients', 'attrs' => ['data-drive-clientbars' => '1']]) ?>
              <?php if (count($clients) > count($barRows)): ?><p class="text-secondary t-footnote drive-clientbars-more"><?= count($clients) - count($barRows) ?> more clients in the full map.</p><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="ui-card drive-card drive-card--top" aria-labelledby="drive-top-title">
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title" id="drive-top-title">Offboard first</h3>
          <p class="ui-card-subtitle">Biggest files nobody has opened in the longest time</p></div></div>
        <div class="ui-card-body">
          <?php if (!$top): ?>
            <div class="ui-empty" data-drive-empty="candidates">Nothing to offboard yet — no large files idle for 6+ months.</div>
          <?php else: ?>
            <ol class="drive-top" data-drive-top="<?= count($top) ?>">
              <?php foreach ($top as $i => $r): $row = dvRow($r, $clientNames); ?>
                <li class="drive-top-row<?= $i >= 3 ? ' drive-phone-hide' : '' ?>" data-drive-row="<?= h($row['id']) ?>" data-bytes="<?= (int)$row['bytes'] ?>">
                  <span class="drive-top-rank"><?= $i + 1 ?></span>
                  <span class="drive-top-body">
                    <span class="drive-file-name"><?= h($row['name']) ?></span>
                    <span class="drive-file-path"><?= h($row['clientName']) ?><?= $row['path'] !== '' ? ' · ' . h($row['path']) : '' ?></span>
                  </span>
                  <span class="drive-top-meta"><b><?= h($row['size']) ?></b><span><?= h($row['idle']) ?> idle</span></span>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
        <?php if ($candCount > 0): ?>
          <div class="ui-card-footer"><a class="drive-seeall" href="<?= h(dvUrl(['view' => 'offboard'])) ?>" data-drive-seeall>See all <?= number_format($candCount) ?> candidate<?= $candCount === 1 ? '' : 's' ?> · <?= h(driveUiBytes($candBytes)) ?><?= icon('chevron-right', 'drive-seeall-chev') ?></a></div>
        <?php endif; ?>
      </section>
    </div>

    <div class="drive-grid drive-grid--three">
      <section class="ui-card drive-card drive-card--trend" aria-labelledby="drive-trend-title">
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title" id="drive-trend-title"><?= h($trendTitle) ?></h3>
          <p class="ui-card-subtitle" data-drive-trend-basis="<?= $histDays ?>"><?= h($trendSub) ?></p></div></div>
        <div class="ui-card-body">
          <?= driveTrendline($hist, $limit, $proj, ['id' => 'drive-trend']) ?>
          <?php if ($histDays >= 2): ?>
          <ul class="drive-legend drive-legend--trend">
            <li><span class="drive-key drive-key--line" aria-hidden="true"></span>Usage</li>
            <?php if ($limit !== null && $growing === true && isset($proj['daysToFull']) && $proj['daysToFull'] !== null): ?><li><span class="drive-key drive-key--proj" aria-hidden="true"></span>Projected</li><?php endif; ?>
            <?php if ($limit !== null): ?><li><span class="drive-key drive-key--ref" aria-hidden="true"></span>Limit · 80%</li><?php endif; ?>
          </ul>
          <?php endif; ?>
        </div>
      </section>

      <section class="ui-card drive-card drive-card--types" aria-labelledby="drive-types-title">
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title" id="drive-types-title">By type</h3>
          <p class="ui-card-subtitle">What the space is made of</p></div></div>
        <div class="ui-card-body">
          <?= driveBars($typeRows, ['max' => max(array_map(static fn($r) => (float)$r['bytes'], $typeRows)), 'ariaLabel' => 'Space by file type', 'attrs' => ['data-drive-bytype' => '1']]) ?>
        </div>
      </section>

      <section class="ui-card drive-card drive-card--wins" aria-labelledby="drive-wins-title">
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title" id="drive-wins-title">Quick wins</h3>
          <p class="ui-card-subtitle">Space you can get back without offboarding anyone</p></div></div>
        <div class="ui-card-body">
          <?php
            $trashB = (float)($wins['trash']['bytes'] ?? ($snap['trash_bytes'] ?? 0));
            $oldB   = (float)($wins['oldVersions']['bytes'] ?? 0); $oldN = (int)($wins['oldVersions']['files'] ?? 0);
            $dupB   = (float)($wins['duplicates']['bytes'] ?? 0);  $dupN = (int)($wins['duplicates']['files'] ?? 0);
            $winRows = [
                ['key' => 'trash', 'label' => 'Trash', 'bytes' => $trashB, 'files' => null, 'why' => 'Already deleted — emptying the Trash in Drive frees it right away.'],
                ['key' => 'old-versions', 'label' => 'Old render versions', 'bytes' => $oldB, 'files' => $oldN, 'why' => 'Earlier exports sitting next to a newer version of the same file.'],
                ['key' => 'duplicates', 'label' => 'Exact duplicates', 'bytes' => $dupB, 'files' => $dupN, 'why' => 'Byte-identical files stored in more than one place.'],
            ];
          ?>
          <ul class="drive-wins" data-drive-wins="<?= count($winRows) ?>">
            <?php foreach ($winRows as $w): ?>
              <li class="drive-win" data-drive-win="<?= h($w['key']) ?>" data-bytes="<?= (int)round($w['bytes']) ?>">
                <span class="drive-win-head"><span class="drive-win-label"><?= h($w['label']) ?></span>
                  <span class="drive-win-size"><b><?= h(driveUiBytes($w['bytes'])) ?></b><?= $w['files'] !== null ? ' · ' . number_format($w['files']) . ' file' . ($w['files'] === 1 ? '' : 's') : '' ?></span></span>
                <span class="drive-win-why"><?= h($w['why']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="ui-card-footer"><p class="text-tertiary t-footnote">Informational — nothing on this page deletes or moves files.</p></div>
      </section>
    </div>
    <?php
    $footExtra = '<script src="' . h(staticUrl('js/drive.js')) . '" defer></script>';
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

// =====================================================================
// CLIENT
// =====================================================================
if ($view === 'client') {
    $cl = null;
    foreach ($clients as $c) { if ((string)($c['slug'] ?? '') === $slug && $slug !== '') { $cl = $c; break; } }
    if (!$cl) {
        $pageTitle = 'Client not found';
        include __DIR__ . '/partials/layout-top.php';
        ?>
        <div class="drive-toolbar"><?= $snapLine ?></div>
        <div class="ui-empty" data-drive-empty="client-unknown">No client called <code><?= h($slug !== '' ? $slug : '(none)') ?></code> in the last snapshot. <a href="<?= h(dvUrl()) ?>">Back to all clients</a></div>
        <?php
        include __DIR__ . '/partials/layout-bottom.php';
        exit;
    }
    $isUnfiled  = dvIsUnfiled($cl);
    $clientName = $isUnfiled ? '(unfiled)' : (string)($cl['name'] ?? $cl['slug']);
    $pageTitle  = $clientName;
    $htmlTitle  = $clientName . ' — Drive';
    $rootId     = (string)($cl['folderId'] ?? '');
    $node = null;
    try { $node = driveTree($pdo, $sid, $folder !== '' ? $folder : ($rootId !== '' ? $rootId : null), 1); } catch (Throwable $e) { error_log('driveTree failed: ' . $e->getMessage()); }
    $inFolder = $folder !== '' && $folder !== $rootId;
    $children = is_array($node) ? (array)($node['children'] ?? []) : [];
    usort($children, static fn($a, $b) => (float)($b['bytes'] ?? 0) <=> (float)($a['bytes'] ?? 0));
    $files = [];
    try { $files = driveLargestFiles($pdo, $sid, $slug, 50) ?: []; } catch (Throwable $e) { error_log('driveLargestFiles failed: ' . $e->getMessage()); }
    $clientBytes = (float)($cl['bytes'] ?? 0);
    $nodeBytes   = is_array($node) ? (float)($node['bytes'] ?? 0) : 0.0;
    $emptyClient = $clientBytes <= 0 && !$children && !$files;

    // Breadcrumb: All clients › client › … › folder. Ancestors when the lib gives them, else the path segments as text.
    $crumbs = [['label' => 'All clients', 'href' => dvUrl()], ['label' => $clientName, 'href' => $inFolder ? dvClientUrl($slug) : '']];
    if ($inFolder && is_array($node)) {
        $ancestors = null;
        if (!empty($node['ancestors']) && is_array($node['ancestors'])) {
            $ancestors = $node['ancestors'];
        } elseif (function_exists('driveFolder')) {
            // design §3: driveFolder() rows carry parentId — walk up to the client's root (bounded)
            $ancestors = [];
            try {
                $cur = driveFolder($pdo, $sid, $folder);
                $pid = is_array($cur) ? (string)($cur['parentId'] ?? '') : '';
                for ($i = 0; $i < 12 && $pid !== '' && $pid !== $rootId; $i++) {
                    $row = driveFolder($pdo, $sid, $pid);
                    if (!is_array($row)) break;
                    array_unshift($ancestors, ['id' => $pid, 'name' => (string)($row['name'] ?? $pid)]);
                    $pid = (string)($row['parentId'] ?? '');
                }
                if ($pid !== $rootId) $ancestors = null;   // never reached the client root → fall back to the path
            } catch (Throwable $e) { error_log('driveFolder failed: ' . $e->getMessage()); $ancestors = null; }
        }
        if ($ancestors !== null) {
            foreach ($ancestors as $a) {
                if ((string)($a['id'] ?? '') === $rootId) continue;
                $crumbs[] = ['label' => (string)($a['name'] ?? ''), 'href' => dvClientUrl($slug, (string)($a['id'] ?? ''))];
            }
        } else {
            $segs = array_values(array_filter(array_map('trim', explode('/', (string)($node['path'] ?? ''))), static fn($s) => $s !== ''));
            $cut = -1;   // drop everything up to and including the client's own folder (e.g. "My Drive/Clients/Kenda")
            foreach ($segs as $i => $sg) { if (strcasecmp($sg, $clientName) === 0 || strcasecmp($sg, $slug) === 0) $cut = $i; }
            if ($cut >= 0) $segs = array_slice($segs, $cut + 1);
            array_pop($segs);   // the current folder is the last crumb below
            foreach ($segs as $s) $crumbs[] = ['label' => $s, 'href' => ''];
        }
        $crumbs[] = ['label' => (string)($node['name'] ?? $folder), 'href' => ''];
    }

    $tiles = [];
    foreach ($children as $ch) {
        $b = (float)($ch['bytes'] ?? 0);
        $tiles[] = ['label' => (string)($ch['name'] ?? ''), 'bytes' => $b, 'staleBytes' => (float)($ch['staleBytes'] ?? 0),
                    'stalePct' => $b > 0 ? 100 * (float)($ch['staleBytes'] ?? 0) / $b : 0,
                    'pctOfDrive' => $driveBytes > 0 ? 100 * $b / $driveBytes : null,
                    'href' => dvClientUrl($slug, (string)($ch['id'] ?? '')), 'attrs' => ['data-drive-folder' => (string)($ch['id'] ?? '')]];
    }
    $rows = array_map(static fn($r) => dvRow($r, $clientNames), $files);
    usort($rows, static fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    $growth = isset($cl['growth30d']) && $cl['growth30d'] !== null ? (float)$cl['growth30d'] : null;   // null = no snapshot ≥ 30 days older yet

    include __DIR__ . '/partials/layout-top.php';
    ?>
    <div class="drive-toolbar">
      <nav class="drive-crumbs" aria-label="Breadcrumb" data-drive-crumbs="<?= count($crumbs) ?>"><ol>
        <?php foreach ($crumbs as $i => $c): $last = $i === count($crumbs) - 1; ?>
          <li<?= $last ? ' aria-current="page"' : '' ?>><?= $c['href'] !== '' && !$last ? '<a href="' . h($c['href']) . '">' . h($c['label']) . '</a>' : '<span>' . h($c['label']) . '</span>' ?></li>
        <?php endforeach; ?>
      </ol></nav>
      <?= $snapLine ?>
    </div>
    <?= $banner ?>

    <?php if ($emptyClient): ?>
      <div class="ui-card ui-card--quiet drive-empty-client" data-drive-empty="client">
        <h3 class="ui-card-title">Nothing in this client's folder yet</h3>
        <p class="text-secondary">The last snapshot found no files under <?= h($clientName) ?>.<?= driveUiSafeLink($cl['webLink'] ?? '') !== '' ? ' ' : '' ?></p>
        <?= driveUiOpenLink($cl['webLink'] ?? '', 'Open folder in Drive') ?>
      </div>
      <?php include __DIR__ . '/partials/layout-bottom.php'; exit; ?>
    <?php endif; ?>

    <section class="drive-stats" aria-label="<?= h($clientName) ?> at a glance" data-drive-stats>
      <div class="drive-stat" data-drive-stat="size"><span class="drive-stat-label">Size</span><span class="drive-stat-value"><?= h(driveUiBytes($clientBytes)) ?></span><span class="drive-stat-sub"><?= isset($cl['fileCount']) ? number_format((int)$cl['fileCount']) . ' files' : 'in Drive' ?></span></div>
      <div class="drive-stat" data-drive-stat="share"><span class="drive-stat-label">Share of Drive</span><span class="drive-stat-value"><?= h(driveUiPct($cl['pctOfDrive'] ?? ($driveBytes > 0 ? 100 * $clientBytes / $driveBytes : null), 1)) ?></span><span class="drive-stat-sub">of <?= h(driveUiBytes($driveBytes)) ?> in files</span></div>
      <div class="drive-stat drive-stat--warning" data-drive-stat="stale"><span class="drive-stat-label">Untouched 6+ months</span><span class="drive-stat-value"><?= h(driveUiBytes($cl['staleBytes'] ?? 0)) ?></span><span class="drive-stat-sub"><?= h(driveUiPct($cl['stalePct'] ?? 0)) ?> of this client</span></div>
      <div class="drive-stat" data-drive-stat="growth"><span class="drive-stat-label">Last 30 days</span><span class="drive-stat-value"><?= $growth === null ? '<span class="text-tertiary">—</span>' : h(driveUiSigned($growth)) ?></span><span class="drive-stat-sub"><?= $growth === null ? 'not enough history yet' : ($growth > 0 ? 'added' : ($growth < 0 ? 'removed' : 'no change')) ?></span></div>
    </section>

    <div class="drive-grid drive-grid--main">
      <section class="ui-card drive-card drive-card--folders" aria-labelledby="drive-folders-title">
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title" id="drive-folders-title"><?= $inFolder ? h((string)($node['name'] ?? 'Folder')) : 'Folders' ?></h3>
          <p class="ui-card-subtitle"><?= $inFolder ? h(driveUiBytes($nodeBytes)) . (isset($node['fileCount']) ? ' · ' . number_format((int)$node['fileCount']) . ' files' : '') . (!empty($node['lastActivityAt']) ? ' · last activity ' . h(driveUiDate($node['lastActivityAt'])) : '') : 'One level at a time — tap a folder to go deeper' ?></p></div>
          <?php if (is_array($node) && driveUiSafeLink($node['webLink'] ?? '') !== ''): ?><div class="ui-card-aside"><?= driveUiOpenLink($node['webLink'], 'Open in Drive') ?></div><?php endif; ?></div>
        <div class="ui-card-body">
          <?php if (!$tiles): ?>
            <div class="ui-empty" data-drive-empty="folders"><?= is_array($node) && (int)($node['fileCount'] ?? 0) > 0 ? 'Only files in here — ' . number_format((int)$node['fileCount']) . ' of them, ' . h(driveUiBytes($nodeBytes)) . '.' : ($folder !== '' && !is_array($node) ? 'That folder is not in the last snapshot.' : 'No subfolders here.') ?></div>
          <?php else: ?>
            <div class="drive-treemap-wrap">
              <?= driveTreemap($tiles, ['total' => $nodeBytes > 0 ? $nodeBytes : null, 'aspect' => 1.6, 'ariaLabel' => 'Folders by space used', 'attrs' => ['data-drive-treemap' => 'folders']]) ?>
              <?= driveTreemapLegend(false) ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="ui-card drive-card drive-card--folderlist" aria-labelledby="drive-folderlist-title">
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title" id="drive-folderlist-title">Largest first</h3>
          <p class="ui-card-subtitle">Idle = time since anything inside changed</p></div></div>
        <div class="ui-card-body">
          <?php if (!$children): ?>
            <div class="ui-empty">No subfolders.</div>
          <?php else: ?>
            <ol class="drive-folderlist" data-drive-folderlist="<?= count($children) ?>">
              <?php foreach ($children as $ch): $idle = dvDaysSince($ch['lastActivityAt'] ?? null); ?>
                <li><a class="drive-folderlist-row" href="<?= h(dvClientUrl($slug, (string)($ch['id'] ?? ''))) ?>" data-drive-folder="<?= h((string)($ch['id'] ?? '')) ?>">
                  <span class="drive-folderlist-name"><?= h((string)($ch['name'] ?? '')) ?></span>
                  <span class="drive-folderlist-idle"><?= $idle !== null ? h(driveUiIdle($idle)) . ' idle' : '<span class="text-tertiary">—</span>' ?></span>
                  <span class="drive-folderlist-size"><?= h(driveUiBytes($ch['bytes'] ?? 0)) ?></span>
                  <?= icon('chevron-right', 'ui-row-chevron') ?>
                </a></li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <section class="ui-card drive-card drive-card--files" aria-labelledby="drive-files-title">
      <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title" id="drive-files-title">Largest files in this client</h3>
        <p class="ui-card-subtitle">Top <?= count($rows) ?> by size · "Open folder" opens the file's folder in Drive</p></div></div>
      <div class="ui-card-body">
        <?php if (!$rows): ?>
          <div class="ui-empty" data-drive-empty="files">No files recorded for this client.</div>
        <?php else: $scoreMax = max(array_map(static fn($r) => (float)$r['score'], $rows)); ?>
          <div class="drive-table-wrap"><table class="drive-table" data-drive-files="<?= count($rows) ?>">
            <thead><tr><th class="drive-td-rank">#</th><th>File</th><th>Type</th><th class="drive-td-size">Size</th><th>Modified</th><th>Last opened</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($rows as $i => $row) echo dvRowHtml($row, $i + 1, $scoreMax, false, false); ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </div>
    </section>
    <?php
    $footExtra = '<script src="' . h(staticUrl('js/drive.js')) . '" defer></script>';
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

// =====================================================================
// OFFBOARD
// =====================================================================
$cands = [];
try { $cands = driveCandidates($pdo, $sid, ['limit' => 2000]) ?: []; } catch (Throwable $e) { error_log('driveCandidates failed: ' . $e->getMessage()); }
usort($cands, static fn($a, $b) => (float)($b['score'] ?? 0) <=> (float)($a['score'] ?? 0));
$totalCands = count($cands);
if (function_exists('driveCandidateCount')) { try { $totalCands = max($totalCands, (int)driveCandidateCount($pdo, $sid)); } catch (Throwable $e) { error_log('driveCandidateCount failed: ' . $e->getMessage()); } }
if (count($cands) > 2000) $cands = array_slice($cands, 0, 2000);
$capped = $totalCands > count($cands);
$rows = array_map(static fn($r) => dvRow($r, $clientNames), $cands);
$sumBytes = array_sum(array_map(static fn($r) => $r['bytes'], $rows));
$scoreMax = $rows ? max(array_map(static fn($r) => (float)$r['score'], $rows)) : 0.0;
$clientOptions = [];
foreach ($rows as $r) { $clientOptions[$r['client']] = $r['clientName']; }
uasort($clientOptions, static fn($a, $b) => strcasecmp($a, $b));
$perPage = 50;
$pages = max(1, (int)ceil(count($rows) / $perPage));
$config = ['rows' => $rows, 'clients' => $clientOptions, 'total' => $totalCands, 'capped' => $capped, 'perPage' => $perPage, 'scoreMax' => $scoreMax, 'snapshot' => (string)($snap['taken_at'] ?? '')];
$footExtra = '<script>window.DriveOffboard = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>' . "\n"
           . '<script src="' . h(staticUrl('js/drive.js')) . '" defer></script>';

include __DIR__ . '/partials/layout-top.php';
?>
<div class="drive-toolbar"><?= $segNav ?><?= $snapLine ?></div>
<?= $banner ?>

<section class="drive-offboard" data-drive-offboard>
  <p class="drive-offboard-intro text-secondary">Ranked by size × idle time: the biggest files nobody has opened in the longest time float to the top. Files idle for less than 6 months are not listed.</p>
  <p class="drive-offboard-summary" data-drive-summary aria-live="polite"><strong><?= number_format(count($rows)) ?></strong> file<?= count($rows) === 1 ? '' : 's' ?> · <strong><?= h(driveUiBytes($sumBytes)) ?></strong> you could get back<?= $capped ? ' <span class="text-tertiary">(top 2,000 of ' . number_format($totalCands) . ')</span>' : '' ?></p>

  <?php if (!$rows): ?>
    <div class="ui-empty" data-drive-empty="candidates">Nothing to offboard yet — no large files idle for 6+ months in the last snapshot.</div>
  <?php else: ?>
    <div class="drive-filters" data-drive-filters>
      <div class="drive-filter"><span class="drive-filter-label" id="drive-filter-type">Type</span>
        <?= segmented([
            ['label' => 'All', 'value' => 'all', 'active' => true], ['label' => 'Video', 'value' => 'video'], ['label' => 'Design', 'value' => 'design'],
            ['label' => 'Images', 'value' => 'images'], ['label' => 'Archives', 'value' => 'archives'],
        ], ['label' => 'Type', 'class' => 'drive-filter-seg', 'auto' => true]) ?>
      </div>
      <div class="drive-filter"><span class="drive-filter-label" id="drive-filter-idle">Idle</span>
        <?= segmented([
            ['label' => '6 mo+', 'value' => '180', 'active' => true], ['label' => '1 yr+', 'value' => '365'], ['label' => '2 yr+', 'value' => '730'],
        ], ['label' => 'Idle for at least', 'class' => 'drive-filter-seg', 'auto' => true]) ?>
      </div>
      <div class="drive-filter drive-filter--client"><label class="drive-filter-label" for="drive-filter-client">Client</label>
        <select class="ui-select" id="drive-filter-client" data-drive-filter="client">
          <option value="">All clients</option>
          <?php foreach ($clientOptions as $s => $n): ?><option value="<?= h($s) ?>"><?= h($n) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="drive-table-wrap"><table class="drive-table drive-table--offboard" data-drive-offboard-table data-drive-rows="<?= count($rows) ?>">
      <thead><tr>
        <th class="drive-td-rank">#</th>
        <th>File</th>
        <th>Client</th>
        <th>Type</th>
        <th class="drive-td-size"><button type="button" class="drive-sort" data-drive-sort="size" aria-sort="none">Size</button></th>
        <th>Modified</th>
        <th><button type="button" class="drive-sort" data-drive-sort="idle" aria-sort="none">Last opened</button></th>
        <th><button type="button" class="drive-sort is-active" data-drive-sort="score" aria-sort="descending">Score</button></th>
        <th></th>
      </tr></thead>
      <tbody data-drive-tbody>
        <?php foreach (array_slice($rows, 0, $perPage) as $i => $row) echo dvRowHtml($row, $i + 1, $scoreMax); ?>
      </tbody>
    </table></div>
    <div class="ui-empty" data-drive-nomatch hidden>No files match these filters.</div>

    <nav class="drive-pager" data-drive-pager aria-label="Pages">
      <button type="button" class="ui-btn ui-btn--sm ui-btn--gray" data-drive-page="prev" disabled>Previous</button>
      <span class="drive-pager-status" data-drive-page-status>Page 1 of <?= $pages ?></span>
      <button type="button" class="ui-btn ui-btn--sm ui-btn--gray" data-drive-page="next"<?= $pages <= 1 ? ' disabled' : '' ?>>Next</button>
    </nav>
  <?php endif; ?>

  <footer class="drive-offboard-foot">
    <p class="text-tertiary t-footnote">Read-only — this list never deletes or moves anything in Drive. Offboarding is a conversation with the client, then a move to their own storage.</p>
    <?php if ($rows): ?><button type="button" class="ui-btn ui-btn--gray" data-drive-export>Export CSV</button><?php endif; ?>
  </footer>
</section>
<?php
include __DIR__ . '/partials/layout-bottom.php';
