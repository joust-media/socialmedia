<?php
/**
 * Studio → Batch (spec §4.5). Admin only.
 *
 * Build several posts at once: pick approved assets from the Approved Pool,
 * turn the selection into rows (one post per row), edit caption / date / type
 * inline in a grouped inset list, then submit everything to batch-process.php
 * in one request. Direct file uploads (one post per file) still work.
 *
 * Client scoping comes from helpers.php ($client from ?client=) — the previous
 * resolveClient() call did not exist and fataled on every request.
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

if (!$client) {
    header('Location: ' . clientUrl('studio.php', ['msg' => 'Pick a client first.']));
    exit;
}

// Categories for the filename auto-tag legend (direct uploads only)
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);

// Latest scheduled date for this client — spacing starts there
$dateStmt = $pdo->prepare('SELECT MAX(scheduled_date) FROM posts WHERE company_id = ?');
$dateStmt->execute([$client['id']]);
$latestDate = $dateStmt->fetchColumn();

$pool         = studioApprovedPool($pdo, $client);
$supportsType = hasPostTypeColumn($pdo);
$types        = allowedPostTypes();
$defaultTags  = trim((string)($client['default_hashtags'] ?? ''));

$studioConfig = [
    'base'       => basePath(),
    'endpoint'   => basePath() . '/status.php',
    'batch'      => basePath() . '/batch-process.php?client=' . rawurlencode($client['slug']),
    'upload'     => basePath() . '/upload-chunk.php?client=' . rawurlencode($client['slug']),   // direct files go up as they are picked (purpose=batch, in pieces when large) → claimed[] tokens
    'client'     => $client['slug'],
    'brand'      => ['name' => $client['name'], 'logo' => brandLogoUrl($client['logo_url'] ?? '')],
    'maxImages'  => 10,
    'maxRows'    => 20,
    'maxBatchFiles' => 50,
    'maxImageMb' => 50,
    'maxVideoMb' => 4096,
    'types'      => $supportsType ? $types : [],
    'latest'     => $latestDate ? date('Y-m-d\TH:i', strtotime($latestDate)) : null,
    'spacing'    => 3,
    'defaults'   => $defaultTags,
    'categories' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $categories),
    'postsUrl'   => clientUrl('posts.php', ['status' => 'pending', 'month' => 'all']),
    'postUrl'    => clientUrl('posts.php', ['post' => '__ID__']),   // studio.js: per-post result links
];

$pageTitle   = 'Batch';
$navSubtitle = 'Studio · ' . $client['name'];
$activeTab   = 'studio';
$pageWide    = true;
$navWide     = true;
$navBack     = ['href' => clientUrl('studio.php', ['tab' => 'batch']), 'label' => 'Studio'];
$bodyClass   = 'page-studio page-batch';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">';
$footExtra   = '<script>window.StudioConfig = ' . json_encode($studioConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . h(staticUrl('js/chunk-upload.js')) . '" defer></script>' . "\n"   // App.chunkUpload (direct files in pieces, resumable)
             . '<script src="' . h(staticUrl('js/studio.js')) . '" defer></script>';

include __DIR__ . '/partials/layout-top.php';
?>

<div class="studio-batch" data-batch data-max-rows="20" data-max-files="50" data-upload-endpoint="<?= h($studioConfig['upload']) ?>">

  <div class="studio-batch-pool">
    <?= studioPickerHtml($pool, ['max' => 10, 'id' => 'batchPicker', 'name' => '', 'title' => 'Approved Pool',
                                 'assetsUrl' => clientUrl('assets.php', ['view' => 'library', 'filter' => 'approved'])]) ?>
    <div class="studio-batch-pickactions">
      <button type="button" class="ui-btn ui-btn--tinted" data-batch-add-row disabled>Add as one post</button>
      <button type="button" class="ui-btn ui-btn--gray" data-batch-add-each disabled>One post per asset</button>
    </div>

    <section class="studio-upload-oneoff">
      <h3 class="studio-section-title studio-section-title--sm">Or upload files — one post per file</h3>
      <label class="studio-dropzone studio-dropzone--sm" data-file-drop>
        <input type="file" data-batch-files accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov" multiple>
        <span class="studio-dropzone-label">Choose files</span>
        <span class="studio-dropzone-hint">up to 4 GB per video, 50 MB per image · up to 50 files · large files go up in pieces as you pick them · name files with a category keyword to auto-tag</span>
      </label>
      <div class="studio-resume" data-batch-resume hidden role="status">
        <span class="studio-resume-text" data-batch-resume-text>Resume unfinished uploads</span>
        <label class="ui-btn ui-btn--filled ui-btn--sm studio-resume-pick">Pick the files<input type="file" data-batch-resume-input accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov" multiple hidden></label>
        <button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-batch-resume-discard>Discard</button>
      </div>
      <ul class="studio-uploadlist studio-filelist" data-batch-filelist role="list"></ul>
      <template data-batch-item-template>
        <li class="studio-upload-item" data-batch-item data-file-name="">
          <div class="studio-upload-thumb" data-upload-thumb></div>
          <div class="studio-upload-body">
            <div class="studio-upload-name" data-upload-name></div>
            <div class="studio-upload-meta text-secondary" data-upload-meta></div>
            <div class="studio-progress" data-upload-progress hidden><div class="studio-progress-bar"><div class="studio-progress-fill" data-upload-fill></div></div></div>
            <div class="studio-upload-status" data-upload-status></div>
          </div>
          <button type="button" class="ui-btn ui-btn--plain ui-btn--sm studio-upload-retry studio-upload-cancel" data-batch-cancel hidden>Cancel</button>
          <button type="button" class="ui-btn ui-btn--plain ui-btn--sm studio-upload-retry" data-file-remove aria-label="Remove"><?= icon('xmark') ?></button>
        </li>
      </template>
      <?php if ($categories): ?>
        <details class="studio-legend">
          <summary>Category keywords</summary>
          <div class="studio-chips studio-chips--wrap">
            <?php foreach ($categories as $cat): ?>
              <span class="studio-chip studio-chip--static"><?= h(strtolower($cat['name'])) ?></span>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>
    </section>
  </div>

  <div class="studio-batch-side">
    <section class="ui-card studio-batch-info">
      <div class="ui-card-body">
        <div class="studio-batch-meta">
          <div>
            <span class="studio-label">Latest scheduled post</span>
            <strong data-batch-latest><?= $latestDate ? h(date('M j, Y · g:i A', strtotime($latestDate))) : 'None — starts today' ?></strong>
          </div>
          <div class="studio-field studio-field--inline">
            <label class="studio-label" for="batchSpacing">Days between posts</label>
            <input class="ui-input" type="number" id="batchSpacing" data-batch-spacing value="3" min="1" max="30">
          </div>
        </div>
        <p class="studio-help">Rows without a date are spaced from the latest post. Captions default to a placeholder you can finish in Compose.</p>
      </div>
    </section>

    <section class="ui-list-group studio-rows" data-batch-rows-group>
      <h2 class="ui-list-header">Posts in this batch · <span data-batch-count>0</span></h2>
      <ol class="ui-list studio-rows-list" data-batch-rows role="list"></ol>
      <p class="ui-empty studio-rows-empty" data-batch-empty>Select assets on the left, then add them as posts.</p>
      <template data-batch-row-template>
        <li class="studio-row" data-batch-row>
          <div class="studio-row-media" data-row-media></div>
          <div class="studio-row-body">
            <textarea class="ui-textarea studio-row-caption" rows="2" maxlength="10000" placeholder="Please insert caption here" data-row-caption aria-label="Caption"></textarea>
            <div class="studio-row-fields">
              <input class="ui-input" type="datetime-local" data-row-date aria-label="Scheduled date">
              <?php if ($supportsType): ?>
                <select class="ui-select" data-row-type aria-label="Type">
                  <?php foreach ($types as $t): ?>
                    <option value="<?= h($t) ?>"><?= h(postTypeLabel($t)) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
              <button type="button" class="ui-btn ui-btn--plain ui-btn--sm studio-row-remove" data-row-remove aria-label="Remove this post">Remove</button>
            </div>
          </div>
        </li>
      </template>
    </section>

    <div class="studio-progress" data-batch-progress hidden>
      <div class="studio-progress-bar"><div class="studio-progress-fill" data-batch-progress-fill></div></div>
      <p class="studio-progress-text" data-batch-progress-text>Uploading…</p>
    </div>

    <div class="studio-actions studio-actions--batch">
      <a class="ui-btn ui-btn--gray" href="<?= h(clientUrl('studio.php', ['tab' => 'batch'])) ?>">Cancel</a>
      <button type="button" class="ui-btn ui-btn--filled ui-btn--large" data-batch-submit disabled>Create posts</button>
    </div>

    <section class="studio-results" data-batch-results hidden>
      <h2 class="studio-section-title">Results</h2>
      <ul class="ui-list" data-batch-results-list role="list"></ul>
      <p class="studio-help"><a href="<?= h(clientUrl('posts.php', ['status' => 'pending', 'month' => 'all'])) ?>">Open Posts to finish captions</a></p>
    </section>
  </div>
</div>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
