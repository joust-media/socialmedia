<?php
/**
 * Studio — admin hub (spec §4.5). Admin only, enforced server-side: a client
 * session is redirected to login by requireAdmin() before any output.
 *
 *   ?client=<slug>        scope (helpers.php); without it → client chooser
 *   &tab=compose|batch|uploads|posts   initial segment (default compose)
 *   &msg=…                flash after a save
 *
 * Sections (scoped):
 *   Compose  — the composer (Approved Pool picker + form + live preview), posts to add-post.php
 *   Batch    — summary + the batch builder (batch.php)
 *   Uploads  — drag-drop zone → batch-process.php (one pending post per file into uploads/)
 *   Posts    — segment counts into posts.php + recent client responses (renderActivityFeed)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

require_once __DIR__ . '/partials/components/post-detail.php';
require_once __DIR__ . '/partials/components/asset-pool.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// hasPostedColumn() (posts.posted is migration-gated) lives in helpers.php.

$flash    = trim((string)($_GET['msg'] ?? ''));
$navLinks = [
    ['label' => 'Prompt Library',  'href' => pagePath('prompts')],
    ['label' => 'Vehicle Library', 'href' => pagePath('vehicles')],
    ['label' => 'Classic admin',   'href' => basePath() . '/legacy/admin.php' . ($client ? '?client=' . rawurlencode($client['slug']) : '')],
    ['label' => 'Sign out',        'href' => pagePath('logout'), 'attrs' => ['title' => 'Signed in as ' . currentAdmin()]],
];

// =============================================================
// MODE A — no client selected: chooser
// =============================================================
if (!$client) {
    $companies = $pdo->query("
        SELECT c.id, c.name, c.slug, c.logo_url,
               (SELECT COUNT(*) FROM posts WHERE posts.company_id = c.id AND posts.status = 'pending') AS pending_count,
               (SELECT COUNT(*) FROM posts WHERE posts.company_id = c.id) AS post_count,
               (SELECT COUNT(*) FROM tires WHERE tires.company_id = c.id) AS feature_count
        FROM companies c
        ORDER BY c.name ASC
    ")->fetchAll();

    $pageTitle   = 'Studio';
    $navSubtitle = 'Choose a client';
    $activeTab   = 'studio';
    $navTrailing = '';
    $bodyClass   = 'page-studio page-studio-chooser';
    $headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">';
    include __DIR__ . '/partials/layout-top.php';
    ?>
    <?php if ($flash): ?><div class="studio-alert studio-alert--ok" role="status"><?= h($flash) ?></div><?php endif; ?>

    <?php if (!$companies): ?>
      <div class="ui-empty">No clients in the <code>companies</code> table yet.</div>
    <?php else: ?>
      <?= insetListOpen('Clients') ?>
      <?php foreach ($companies as $c):
          $pending = (int)$c['pending_count'];
          $sub = (int)$c['post_count'] . ' posts · ' . (int)$c['feature_count'] . ' collection items';
      ?>
        <?= insetRow([
            'href'     => clientUrl('studio.php', ['client' => $c['slug']]),
            'leading'  => clientAvatar($c, 'ui-avatar--lg'),
            'title'    => $c['name'],
            'subtitle' => $sub,
            'trailing' => $pending > 0 ? '<span class="ui-badge">' . $pending . '</span>' : '',
            'attrs'    => ['data-client-row' => $c['slug']],
        ]) ?>
      <?php endforeach; ?>
      <?= insetListClose('Badges show posts still waiting for the client\'s review.') ?>
    <?php endif; ?>

    <?php if (hasActivityLog($pdo)): ?>
      <section class="ui-card studio-activity">
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Recent activity — all clients</h3></div>
          <div class="ui-card-aside">
            <form method="POST" action="<?= h(basePath() . '/digest.php') ?>" target="digest_iframe" data-digest-form>
              <input type="hidden" name="source" value="manual">
              <button type="submit" class="ui-btn ui-btn--gray ui-btn--sm" title="Email the activity digest now">Send digest</button>
            </form>
          </div></div>
        <div class="ui-card-body studio-activity-list"><?= renderActivityFeed($pdo, null, 20) ?></div>
      </section>
      <iframe name="digest_iframe" class="ui-visually-hidden" aria-hidden="true" tabindex="-1"></iframe>
    <?php endif; ?>
    <?php
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

// =============================================================
// MODE B — client selected: the hub
// =============================================================
$tabs = ['compose' => 'Compose', 'batch' => 'Batch', 'uploads' => 'Uploads', 'posts' => 'Posts'];
$tab  = strtolower(trim((string)($_GET['tab'] ?? 'compose')));
if (!isset($tabs[$tab])) $tab = 'compose';

$pool         = studioApprovedPool($pdo, $client);
$supportsType = hasPostTypeColumn($pdo);
$hasPosted    = hasPostedColumn($pdo);
$postedExpr   = $hasPosted ? 'p.posted' : '0';
$defaultTags  = trim((string)($client['default_hashtags'] ?? ''));
$categories   = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order, name")->fetchAll();

// Posts segment counts (same rules as posts.php)
$counts = ['pending' => 0, 'approved' => 0, 'scheduled' => 0, 'denied' => 0];
$st = $pdo->prepare("SELECT p.status, ($postedExpr) AS posted, COUNT(*) AS n FROM posts p WHERE p.company_id = ? GROUP BY p.status" . ($hasPosted ? ', p.posted' : ''));
$st->execute([(int)$client['id']]);
foreach ($st->fetchAll() as $row) {
    $n = (int)$row['n'];
    if (!empty($row['posted'])) { $counts['scheduled'] += $n; }
    elseif (isset($counts[$row['status']])) { $counts[$row['status']] += $n; }
}

// Latest scheduled post (batch spacing starts there)
$st = $pdo->prepare('SELECT MAX(scheduled_date) FROM posts WHERE company_id = ?');
$st->execute([(int)$client['id']]);
$latestDate = $st->fetchColumn();

$composerHtml = studioComposerHtml([
    'client'          => $client,
    'pool'            => $pool,
    'action'          => clientUrl('add-post.php'),
    'isEdit'          => false,
    'post'            => [
        'name' => '', 'caption' => '', 'hashtags' => $defaultTags, 'status' => 'pending',
        'post_type' => 'post', 'scheduled' => date('Y-m-d\TH:i'), 'categories' => [],
    ],
    'editImages'      => [],
    'categories'      => $categories,
    'supportsType'    => $supportsType,
    'maxImages'       => 10,
    'maxFileMb'       => 25,
    'submitText'      => 'Create post',
    'cancelUrl'       => '',
    'assetsUrl'       => clientUrl('assets.php', ['view' => 'library', 'filter' => 'approved']),
    'selected'        => [],
    'errors'          => [],
    'defaultHashtags' => $defaultTags,
    'formId'          => 'composer',
]);

$segItems = [];
foreach ($tabs as $key => $label) {
    $segItems[] = [
        'label'  => $label,
        'href'   => clientUrl('studio.php', ['tab' => $key]),
        'active' => $key === $tab,
        'value'  => $key,
        'attrs'  => ['data-studio-tab' => $key],
    ];
}

$studioConfig = [
    'base'      => basePath(),
    'endpoint'  => basePath() . '/status.php',
    'batch'     => basePath() . '/batch-process.php?client=' . rawurlencode($client['slug']),
    'client'    => $client['slug'],
    'brand'     => ['name' => $client['name'], 'logo' => (string)($client['logo_url'] ?? '')],
    'maxImages' => 10,
    'maxFileMb' => 25,
    'tab'       => $tab,
    'tabUrl'    => clientUrl('studio.php', ['tab' => '__TAB__']),
    'postUrl'   => clientUrl('posts.php', ['post' => '__ID__']),   // studio.js: "finish it in Posts" links
];

$pageTitle   = 'Studio';
$navSubtitle = $client['name'];
$activeTab   = 'studio';
$pageWide    = true;
$navWide     = true;        // header column matches the 1200px body (as assets.php)
$bodyClass   = 'page-studio page-studio-hub';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">';
$footExtra   = '<script>window.StudioConfig = ' . json_encode($studioConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . h(staticUrl('js/studio.js')) . '" defer></script>';

include __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash): ?>
  <div class="studio-alert studio-alert--ok" role="status"><?= h($flash) ?></div>
<?php endif; ?>

<div class="studio-toolbar">
  <?= segmented($segItems, ['label' => 'Studio sections', 'class' => 'studio-segmented']) ?>
</div>

<!-- Compose ------------------------------------------------------------ -->
<section class="studio-section" data-studio-section="compose"<?= $tab === 'compose' ? '' : ' hidden' ?>>
  <?= $composerHtml ?>
</section>

<!-- Batch -------------------------------------------------------------- -->
<section class="studio-section" data-studio-section="batch"<?= $tab === 'batch' ? '' : ' hidden' ?>>
  <?= card(
        '<p>Pick several approved assets, turn them into posts in one go, and set captions, dates and types in an editable list. Rows without a date are spaced from the latest scheduled post'
        . ($latestDate ? ' (<strong>' . h(date('M j, Y', strtotime($latestDate))) . '</strong>)' : '') . '.</p>'
        . '<p class="text-secondary">' . count($pool['assets']) . ' approved asset' . (count($pool['assets']) === 1 ? '' : 's') . ' available · '
        . (int)$pool['counts']['library'] . ' in Library · ' . (int)$pool['counts']['tire'] . ' in ' . count($pool['collections']) . ' collection' . (count($pool['collections']) === 1 ? '' : 's') . '</p>',
        [
          'title'  => 'Batch builder',
          'footer' => '<a class="ui-btn ui-btn--filled" href="' . h(clientUrl('batch.php')) . '">Open batch builder</a>',
        ]) ?>
</section>

<!-- Uploads ------------------------------------------------------------ -->
<section class="studio-section" data-studio-section="uploads"<?= $tab === 'uploads' ? '' : ' hidden' ?>>
  <div class="studio-uploads" data-upload-zone data-endpoint="<?= h($studioConfig['batch']) ?>" data-max-mb="10">
    <label class="studio-dropzone studio-dropzone--lg" data-file-drop>
      <input type="file" data-upload-input accept="image/*,video/mp4,video/quicktime,.mov" multiple>
      <span class="studio-dropzone-icon"><?= icon('download') ?></span>
      <span class="studio-dropzone-label">Drop images or video here</span>
      <span class="studio-dropzone-hint">image/*, MP4, QuickTime · up to 10 MB each here (Compose takes single files up to 25 MB) · each file becomes a draft post in <?= h($client['name']) ?>'s queue (placeholder caption, spaced 3 days apart) that you can finish in Compose or Posts.</span>
    </label>
    <ul class="studio-uploadlist" data-upload-list role="list"></ul>
    <template data-upload-item-template>
      <li class="studio-upload-item" data-upload-item>
        <div class="studio-upload-thumb" data-upload-thumb></div>
        <div class="studio-upload-body">
          <div class="studio-upload-name" data-upload-name></div>
          <div class="studio-upload-meta text-secondary" data-upload-meta></div>
          <div class="studio-upload-warning" data-upload-warning hidden>
            <strong>.MOV plays in Safari only.</strong> Chrome and Firefox will show a download fallback instead of the video. Convert to MP4 for everyone, or upload anyway.
            <div class="ui-btn-group studio-upload-warning-actions">
              <button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-upload-skip>Skip</button>
              <button type="button" class="ui-btn ui-btn--tinted ui-btn--sm" data-upload-anyway>Upload anyway</button>
            </div>
          </div>
          <div class="studio-progress" data-upload-progress hidden>
            <div class="studio-progress-bar"><div class="studio-progress-fill" data-upload-fill></div></div>
          </div>
          <div class="studio-upload-status" data-upload-status></div>
        </div>
      </li>
    </template>
  </div>
</section>

<!-- Posts -------------------------------------------------------------- -->
<section class="studio-section" data-studio-section="posts"<?= $tab === 'posts' ? '' : ' hidden' ?>>
  <?= insetListOpen(h($client['name']) . '\'s posts', ['raw' => true]) ?>
    <?= insetRow(['href' => clientUrl('posts.php', ['status' => 'pending', 'month' => 'all']),  'icon' => 'grid',      'iconStyle' => 'color:var(--pending)',   'title' => 'To Review', 'subtitle' => 'Waiting for the client', 'trailing' => '<span class="studio-count">' . $counts['pending'] . '</span>']) ?>
    <?= insetRow(['href' => clientUrl('posts.php', ['status' => 'approved', 'month' => 'all']), 'icon' => 'checkmark', 'iconStyle' => 'color:var(--approve)',   'title' => 'Approved',  'subtitle' => 'Ready to schedule',       'trailing' => '<span class="studio-count">' . $counts['approved'] . '</span>']) ?>
    <?= insetRow(['href' => clientUrl('posts.php', ['status' => 'scheduled', 'month' => 'all']),'icon' => 'calendar',  'iconStyle' => 'color:var(--scheduled)', 'title' => 'Scheduled', 'subtitle' => 'Pushed to the scheduler', 'trailing' => '<span class="studio-count">' . $counts['scheduled'] . '</span>']) ?>
    <?= insetRow(['href' => clientUrl('posts.php', ['status' => 'denied', 'month' => 'all']),   'icon' => 'xmark',     'iconStyle' => 'color:var(--deny)',      'title' => 'Needs changes', 'subtitle' => 'Rework these', 'trailing' => '<span class="studio-count">' . $counts['denied'] . '</span>']) ?>
  <?= insetListClose() ?>

  <?php if (hasActivityLog($pdo)): ?>
    <section class="ui-card studio-activity">
      <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Recent client responses</h3>
        <p class="ui-card-subtitle">Approvals, notes and change requests on <?= h($client['name']) ?>'s work.</p></div></div>
      <div class="ui-card-body studio-activity-list"><?= renderActivityFeed($pdo, (int)$client['id'], 12) ?></div>
    </section>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
