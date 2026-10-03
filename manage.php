<?php
/**
 * Manage — the admin's settings hub (replaces Studio). Admin only, enforced server-side: a client
 * session is redirected to sign-in by requireAdmin() before any output.
 *
 *   ?client=<slug>          scope (helpers.php); optional — every section works unscoped too
 *   &section=clients        (default) Clients: list, New client, the edit card (name / slug / feature label,
 *                           logo, Settings = default hashtags + AI Builder profile, module toggles — the ONLY
 *                           place a client's Tires / Emails / Pages tabs are turned on or off). Scoped → that
 *                           client's card opens; &edit=<id> another one; &edit=0 the list.
 *   &section=export         Export: approved-asset zip for the scoped client (export.php;
 *                           &tire=<id>[&series=<id>] preselects — the Assets "Export approved…" shortcut) and
 *                           Recent exports. Unscoped: a client list.
 *   &section=drive          → drive.php (the Drive storage view; it carries the same Manage switch)
 *   &section=tools          Tools: New tire, the client's tires (→ Manage series), AI Builder, Prompt / Vehicle
 *                           Library, Projects, Emails import / export + Audiences, and Maintenance: Pages server rules,
 *                           Send digest, Image previews (preview-job.php; #previews — every client when unscoped).
 *   &section=notifications  Notifications (partials/manage-notifications.php → notify-admin.php): config status (set /
 *                           not set, never values), Slack + cron URLs, test message, reminder thresholds + Morning
 *                           summary hour, Slack channel + owner per client, Team (named admins), delivery log + Retry.
 *   &msg=…                  flash after a save (shown once)
 *
 * Old Studio / Classic admin URLs land here through legacyAdminTarget() (helpers.php).
 * Tire series management (rename / reorder / delete / Drive link / rescan / repair / FTP folder) lives on
 * the tire itself: Assets → a tire → ⋯ → Manage series (assets.php &manage=series, static/js/series-manage.js).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$section = strtolower(trim(is_string($_GET['section'] ?? null) ? $_GET['section'] : ''));
if (!isset(MANAGE_SECTIONS[$section])) $section = 'clients';
if ($section === 'drive') { header('Location: ' . manageUrl('drive'), true, 302); exit; }

$flash = trim(is_string($_GET['msg'] ?? null) ? $_GET['msg'] : '');
$flashErr = $section !== 'clients' ? trim(is_string($_GET['err'] ?? null) ? $_GET['err'] : '') : '';   // Clients shows its own err=

/** A client list whose rows open this section for that client (the unscoped Export / Tools). */
$clientPicker = static function (PDO $pdo, string $section, string $footnote): string {
    $rows = [];
    try { $rows = $pdo->query("SELECT id, name, slug, logo_url FROM companies ORDER BY name ASC")->fetchAll(); }
    catch (Throwable $e) { error_log('manage client list failed: ' . $e->getMessage()); }
    $out = insetListOpen('Choose a client', ['attrs' => ['data-manage-clients' => $section]]);
    if (!$rows) $out .= '<li><div class="ui-row"><div class="ui-row-body"><div class="ui-row-subtitle">No clients yet — add one in Clients.</div></div></div></li>';
    foreach ($rows as $c) {
        $out .= insetRow([
            'href'    => clientUrl('manage.php', ['client' => $c['slug'], 'section' => $section]),
            'leading' => clientAvatar($c, 'ui-avatar--lg'),
            'title'   => $c['name'],
            'chevron' => true,
            'attrs'   => ['data-client-row' => $c['slug']],
        ]);
    }
    return $out . insetListClose($footnote);
};

// ---------------------------------------------------------------------
// Export & previews (scoped): the tires (+ series with approved counts) the scope pickers offer
// ---------------------------------------------------------------------
$exportTires = []; $exportTire = 0; $exportSeries = 0; $exportZipOn = false; $exportConfig = null;
if ($section === 'export' && $client) {
    require_once __DIR__ . '/export-lib.php';
    $seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);
    $st = $pdo->prepare("SELECT id, name FROM tires WHERE company_id = ? ORDER BY name ASC");
    $st->execute([(int)$client['id']]);
    foreach ($st->fetchAll() as $t) {
        $series = [];
        if ($seriesOn) {
            foreach (tireSeriesForTire($pdo, (int)$t['id']) as $sr) {
                $series[] = ['id' => (int)$sr['id'], 'name' => (string)$sr['name'], 'approved' => (int)($sr['counts']['approved'] ?? 0)];
            }
        }
        $exportTires[] = ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'series' => $series];
    }
    $exportTire = max(0, (int)($_GET['tire'] ?? 0));
    if ($exportTire && !in_array($exportTire, array_column($exportTires, 'id'), true)) $exportTire = 0;
    $exportSeries = $exportTire ? max(0, (int)($_GET['series'] ?? 0)) : 0;
    $exportZipOn  = exportZipSupported();
    $exportConfig = [
        'endpoint' => basePath() . '/export.php?client=' . rawurlencode($client['slug']),   // estimate / start / step / status / cancel / list (POST), download / manifest (GET)
        'tires'    => $exportTires,
        'tire'     => $exportTire,
        'series'   => $exportSeries,
        'zip'      => $exportZipOn,             // false on a 32-bit PHP build → manifest CSV only
        'cap'      => EXPORT_MAX_BYTES,
    ];
}

// ---------------------------------------------------------------------
// Tools: what this client has
// ---------------------------------------------------------------------
$toolTires = []; $toolHasTires = false; $toolHasEmails = false; $toolHasPages = false;
if ($section === 'tools' && $client) {
    $toolHasTires = companyHasTires($client, $pdo);
    if ($toolHasTires) {
        $seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);
        $st = $pdo->prepare("SELECT id, name FROM tires WHERE company_id = ? ORDER BY name ASC");
        $st->execute([(int)$client['id']]);
        foreach ($st->fetchAll() as $t) {
            $toolTires[] = ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'series' => $seriesOn ? count(tireSeriesForTire($pdo, (int)$t['id'])) : 0];
        }
    }
    $toolHasEmails = hasEmailsTable($pdo) && companyHasEmails($client, $pdo);
    $toolHasPages  = function_exists('hasPagesTable') && hasPagesTable($pdo) && companyHasPages($client, $pdo);
}

// ---------------------------------------------------------------------
// Chrome
// ---------------------------------------------------------------------
$pageTitle   = 'Manage';
$navSubtitle = $client ? $client['name'] : 'All clients';
$htmlTitle   = 'Manage — ' . MANAGE_SECTIONS[$section] . ($client ? ' — ' . $client['name'] : '');
$activeTab   = 'manage';
$navTrailing = $client ? clientAvatar($client) : joustAvatar();
// One frame for every top-level page (the default 720px column, titles in the label colour) — Export included.
$bodyClass   = 'page-studio page-manage page-manage--' . $section;
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">'
             . ($section === 'notifications' ? '<link rel="stylesheet" href="' . h(staticUrl('css/notify.css')) . '">' : '');
$studioConfig = ['base' => basePath(), 'client' => $client['slug'] ?? '', 'clientAdmin' => basePath() . '/client-admin.php',
                 'clientsUrl' => manageUrl('clients')];
if ($exportConfig) $studioConfig['export'] = $exportConfig;
$footExtra   = '<script>window.StudioConfig = ' . json_encode($studioConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . h(staticUrl('js/studio.js')) . '" defer></script>'
             . ($section === 'tools' ? "\n" . '<script src="' . h(staticUrl('js/studio-previews.js')) . '" defer></script>' : '')
             . ($section === 'notifications' ? "\n" . '<script src="' . h(staticUrl('js/notifications.js')) . '" defer></script>' : '');   // Tools → Maintenance → Image previews (preview-job.php)

include __DIR__ . '/partials/layout-top.php';
?>

<?= manageNavHtml($section) ?>

<?php if ($flash !== ''): ?>
  <div class="studio-alert studio-alert--ok" role="status" data-manage-flash><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="studio-alert studio-alert--error" role="alert" data-manage-error><?= h($flashErr) ?></div>
<?php endif; ?>

<?php if ($section === 'clients'): ?>
<!-- Clients ------------------------------------------------------------- -->
<section class="studio-section manage-section" data-manage-section="clients">
  <?php include __DIR__ . '/partials/manage-clients.php'; ?>
</section>

<?php elseif ($section === 'export'): ?>
<!-- Export & previews ---------------------------------------------------- -->
<section class="studio-section manage-section" data-manage-section="export">
  <?php if (!$client): ?>
    <?= $clientPicker($pdo, 'export', 'Exports are built per client: everything it has approved, one folder per tire.') ?>
  <?php else: ?>
  <div class="studio-export" data-export data-endpoint="<?= h($exportConfig['endpoint']) ?>" data-zip="<?= $exportZipOn ? '1' : '0' ?>">
    <div class="studio-export-grid">
      <section class="ui-card studio-export-card">
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Export approved assets</h3>
          <p class="ui-card-subtitle">One zip of everything <?= h($client['name']) ?> has approved, organised by tire: <code><?= h(exportSafeName($client['name'], 'Client')) ?>/&lt;Tire&gt;/Reference/</code>, <code>…/&lt;Tire&gt;/&lt;Series&gt;/</code> and <code>…/Library/</code>, plus <code>manifest.csv</code>. Built on the server in small steps, then downloaded once.</p></div></div>
        <div class="ui-card-body">
          <?php if (!$exportZipOn): ?>
            <div class="studio-alert studio-alert--error" role="status" data-export-nozip>This server runs a 32-bit PHP build, which cannot write zips over 2 GB safely, so the portal offers the <strong>manifest CSV</strong> only (every approved file with its tire, series and path — copy the files from <code>media/</code> by FTP).</div>
          <?php endif; ?>
          <?php if (!$exportTires): ?>
            <p class="text-secondary" data-export-notires>No tires yet for <?= h($client['name']) ?> — the export covers approved Library images only.</p>
          <?php endif; ?>
          <form class="studio-export-form" data-export-form>
            <fieldset class="studio-export-fieldset">
              <legend class="studio-label">Scope</legend>
              <div class="studio-export-choices" role="radiogroup">
                <label class="studio-export-choice"><input type="radio" name="scope" value="all" data-export-scope<?= $exportTire ? '' : ' checked' ?>> <span>All approved</span></label>
                <label class="studio-export-choice"><input type="radio" name="scope" value="tire" data-export-scope<?= $exportTire && !$exportSeries ? ' checked' : '' ?><?= $exportTires ? '' : ' disabled' ?>> <span>One tire</span></label>
                <label class="studio-export-choice"><input type="radio" name="scope" value="series" data-export-scope<?= $exportSeries ? ' checked' : '' ?><?= $exportTires ? '' : ' disabled' ?>> <span>One series</span></label>
              </div>
            </fieldset>
            <div class="studio-field-row">
              <div class="studio-field"><label class="studio-label" for="exportTire">Tire</label>
                <select class="ui-select" id="exportTire" data-export-tire<?= $exportTire ? '' : ' disabled' ?>>
                  <?php foreach ($exportTires as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"<?= (int)$t['id'] === $exportTire ? ' selected' : '' ?>><?= h($t['name']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="studio-field"><label class="studio-label" for="exportSeries">Series</label>
                <select class="ui-select" id="exportSeries" data-export-series<?= $exportSeries ? '' : ' disabled' ?>></select></div>
            </div>
            <fieldset class="studio-export-fieldset">
              <legend class="studio-label">Include</legend>
              <div class="studio-export-choices">
                <label class="studio-export-choice"><input type="checkbox" data-export-inc="photos" checked> <span>Photos</span></label>
                <label class="studio-export-choice"><input type="checkbox" data-export-inc="videos"> <span>Videos <span class="text-tertiary" data-export-video-size></span></span></label>
                <label class="studio-export-choice"><input type="checkbox" data-export-inc="reference" checked> <span>Reference images</span></label>
                <label class="studio-export-choice"><input type="checkbox" data-export-inc="library" checked> <span>Library approved images <span class="text-tertiary">(own folder · “All approved” only)</span></span></label>
              </div>
            </fieldset>
            <p class="studio-help">Approved files only — pending and denied never leave the portal. Names use the display name when one is set; duplicates in a folder get “-2”, “-3”. One export is capped at <?= h(exportFormatBytes(EXPORT_MAX_BYTES)) ?> — split by tire above that.</p>
          </form>
          <p class="studio-export-estimate" data-export-estimate aria-live="polite">Counting…</p>
          <div class="studio-export-actions" data-export-actions>
            <?php if ($exportZipOn): ?>
              <button type="button" class="ui-btn ui-btn--filled" data-export-build><?= icon('download') ?><span>Build export</span></button>
            <?php endif; ?>
            <a class="ui-btn ui-btn--gray" data-export-manifest href="<?= h($exportConfig['endpoint'] . '&action=manifest') ?>" title="The file list as a spreadsheet (id, kind, tire, series, filename, media type, bytes, status, approved at, comments, Drive link, source path)"><?= icon('download') ?><span>Manifest CSV only</span></a>
          </div>
          <div class="studio-export-progress" data-export-progress hidden role="status">
            <div class="studio-progress"><div class="studio-progress-bar"><div class="studio-progress-fill" data-export-fill style="width:0%"></div></div></div>
            <div class="studio-export-progress-row">
              <span class="studio-export-progress-text" data-export-progress-text>Starting…</span>
              <button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-export-cancel>Cancel</button>
            </div>
          </div>
          <div class="studio-export-done" data-export-done hidden role="status">
            <p class="studio-export-done-text" data-export-done-text></p>
            <div class="studio-export-actions">
              <a class="ui-btn ui-btn--filled" data-export-download href="#" download><?= icon('download') ?><span>Download ZIP</span></a>
              <button type="button" class="ui-btn ui-btn--gray" data-export-another>Build another</button>
            </div>
          </div>
        </div>
      </section>

      <section class="ui-card studio-export-recent-card" data-export-recent-card>
        <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Recent exports</h3>
          <p class="ui-card-subtitle">Zips built in the last 24 hours for <?= h($client['name']) ?>. They are deleted automatically after that.</p></div></div>
        <div class="ui-card-body">
          <ul class="studio-export-recent" data-export-recent role="list"></ul>
          <p class="text-secondary" data-export-recent-empty>No exports yet.</p>
        </div>
      </section>
    </div>
  </div>
  <?php endif; ?>

  <p class="studio-help manage-moved-note" data-previews-moved>Image previews are in <a href="<?= h(manageUrl('tools') . '#previews') ?>">Tools → Maintenance</a>.</p>
</section>

<?php elseif ($section === 'notifications'): ?>
<!-- Notifications ---------------------------------------------------------- -->
<section class="studio-section manage-section" data-manage-section="notifications">
  <?php include __DIR__ . '/partials/manage-notifications.php'; ?>
</section>

<?php elseif ($section === 'tools'): ?>
<!-- Tools ----------------------------------------------------------------- -->
<section class="studio-section manage-section" data-manage-section="tools">
  <?php if ($client): ?>
    <?php if ($toolHasTires): ?>
      <?= insetListOpen(h(trim((string)($client['feature_label'] ?? '')) !== '' ? $client['feature_label'] : 'Tires'), ['raw' => true, 'attrs' => ['data-tools-tires' => '1']]) ?>
        <?= insetRow(['href' => clientUrl('add-feature.php', ['module' => 'tires']), 'icon' => 'plus', 'title' => 'New tire',
                      'subtitle' => 'Name, description and reference images', 'attrs' => ['data-tool' => 'new-tire']]) ?>
        <?php foreach ($toolTires as $t): ?>
          <?= insetRow(['href' => clientUrl('assets.php', ['view' => 'collections', 'item' => $t['id'], 'manage' => 'series']), 'icon' => 'tire',
                        'title' => $t['name'], 'subtitle' => 'Manage series · ' . $t['series'] . ' series',
                        'attrs' => ['data-tool' => 'series', 'data-tire' => $t['id']]]) ?>
        <?php endforeach; ?>
      <?= insetListClose('Manage series renames, reorders and deletes series, sets their Google Drive links, and rescans the FTP folder — on the tire itself (⋯ → Manage series).') ?>
    <?php endif; ?>
  <?php endif; ?>

  <?= insetListOpen('Content tools', ['attrs' => ['data-tools-content' => '1']]) ?>
    <?php if ($client): ?>
      <?= insetRow(['href' => clientUrl('build.php'), 'icon' => 'wand', 'title' => 'AI Builder', 'subtitle' => 'Compose a prompt for ' . $client['name'] . ' and gather reference images', 'attrs' => ['data-tool' => 'builder']]) ?>
    <?php else: ?>
      <?= insetRow(['icon' => 'wand', 'title' => 'AI Builder', 'subtitle' => 'Choose a client below — the Builder works for one client at a time', 'chevron' => false, 'attrs' => ['data-tool' => 'builder']]) ?>
    <?php endif; ?>
    <?= insetRow(['href' => pagePath('prompts'), 'icon' => 'bubble', 'title' => 'Prompt Library', 'subtitle' => 'Reusable prompt building blocks for every client', 'attrs' => ['data-tool' => 'prompts']]) ?>
    <?= insetRow(['href' => pagePath('vehicles'), 'icon' => 'photo', 'title' => 'Vehicle Library', 'subtitle' => 'Vehicles and their reference photos', 'attrs' => ['data-tool' => 'vehicles']]) ?>
    <?php if ($client): ?>
      <?= insetRow(['href' => clientUrl('projects.php'), 'icon' => 'checklist', 'title' => 'Projects', 'subtitle' => 'Tasks shared with ' . $client['name'], 'attrs' => ['data-tool' => 'projects']]) ?>
    <?php endif; ?>
  <?= insetListClose() ?>

  <?php if ($client && $toolHasEmails):
    $emailGroups = emailGroupsForCompany($pdo, (int)$client['id']);
    $emailGroupN = emailGroupCounts($pdo, (int)$client['id']);
    $emailFormUrl = clientUrl('add-email.php');
  ?>
  <section class="ui-card studio-import-card" data-emails-import id="emails-io">
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Emails: import &amp; export</h3>
      <p class="ui-card-subtitle">CSV (the client's sheet: Status, ID, Title, Sequence, Trigger, Subject Line, Preview Text, URL, Priority, Groups) or a JSON export. Rows match on ID; you get a preview of every change before anything is written.</p></div></div>
    <div class="ui-card-body">
      <form method="POST" action="<?= h(clientUrl('emails-io.php')) ?>" enctype="multipart/form-data" class="studio-import-form">
        <input type="hidden" name="mode" value="preview">
        <input type="file" name="file" accept=".csv,.json,text/csv,application/json" required aria-label="CSV or JSON file">
        <button type="submit" class="ui-btn ui-btn--tinted">Preview import</button>
      </form>
      <p class="studio-help">Blank IDs are skipped, duplicate IDs keep the first row, “#ERROR!” and thumbs-up “Active” placeholder cells are treated as blank, and unknown statuses land in Draft. Sequence + Groups become audiences (created when missing).</p>
      <div class="studio-export-actions">
        <a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h(clientUrl('emails-io.php', ['format' => 'csv'])) ?>" data-emails-export="csv" title="Download the spreadsheet (Status, ID, Title, Sequence, Trigger, …)"><?= icon('download') ?><span>Export CSV</span></a>
        <a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h(clientUrl('emails-io.php', ['format' => 'json'])) ?>" data-emails-export="json" title="Download everything incl. audiences and comment threads"><?= icon('download') ?><span>Export JSON</span></a>
      </div>
    </div>
  </section>

  <section class="ui-card studio-groups-card" data-emails-groups id="audiences">
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Audiences</h3>
      <p class="ui-card-subtitle">Who an email is for (Free, Pro, Renewal…) — the client filters by them. Deleting an audience only unlinks its emails. The order an email sends in lives in Flows.</p></div></div>
    <div class="ui-card-body">
      <?php if (!$emailGroups): ?><p class="text-secondary">No audiences yet.</p><?php endif; ?>
      <ul class="studio-groups" role="list">
        <?php foreach ($emailGroups as $g): $n = (int)($emailGroupN[(int)$g['id']] ?? 0); ?>
          <li class="studio-group" data-email-group="<?= (int)$g['id'] ?>">
            <form method="POST" action="<?= h($emailFormUrl) ?>" class="studio-group-form">
              <input type="hidden" name="action" value="group_rename">
              <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
              <input class="ui-input" type="text" name="name" value="<?= h($g['name']) ?>" maxlength="80" required aria-label="Audience name">
              <span class="studio-group-n"><?= $n ?> email<?= $n === 1 ? '' : 's' ?></span>
              <button type="submit" class="ui-btn ui-btn--gray ui-btn--sm">Rename</button>
            </form>
            <form method="POST" action="<?= h($emailFormUrl) ?>" class="studio-inline-form" data-confirm-submit="Delete the audience “<?= h($g['name']) ?>”? Its <?= $n ?> email<?= $n === 1 ? '' : 's' ?> stay, just without this audience.">
              <input type="hidden" name="action" value="group_delete">
              <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
              <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn">Delete</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="POST" action="<?= h($emailFormUrl) ?>" class="studio-group-add">
        <input type="hidden" name="action" value="group_add">
        <input class="ui-input" type="text" name="name" maxlength="80" required placeholder="New audience, e.g. Leads" aria-label="New audience name">
        <button type="submit" class="ui-btn ui-btn--tinted">Add audience</button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <?= insetListOpen('Maintenance', ['attrs' => ['data-tools-maintenance' => '1']]) ?>
    <?php // Clean links (url-lib.php): writes the guarded rewrite block into the portal's own .htaccess, checks it live, rolls back on failure.
      $clOn = cleanLinksInstalled();
      $clForced = portalConfig('clean_urls', null);
      $clSub = $clOn
          ? 'On — links look like ' . basePath() . '/' . ($client['slug'] ?? 'kenda') . '/posts/12. Old links redirect to them.'
          : 'Off — links look like ' . basePath() . '/posts.php?client=' . ($client['slug'] ?? 'kenda') . '&post=12. Install writes the server rules (.htaccess, backed up first) and checks them live.';
      if ($clForced !== null && $clForced !== '') $clSub .= ' (config.php clean_urls = ' . ($clForced ? 'true' : 'false') . ' overrides this.)';
      $clForm = '<form method="POST" action="' . h(basePath() . '/client-admin.php' . ($client ? '?client=' . rawurlencode($client['slug']) : '')) . '" class="studio-inline-form"'
              . ($clOn ? ' data-confirm-submit="Turn clean links off? The rules are taken out of .htaccess (a backup is kept); every link keeps working in the classic form."' : '') . '>'
              . '<input type="hidden" name="action" value="' . ($clOn ? 'clean_links_remove' : 'clean_links_install') . '">'
              . '<button type="submit" class="ui-btn ' . ($clOn ? 'ui-btn--gray' : 'ui-btn--tinted') . ' ui-btn--sm" data-clean-links-' . ($clOn ? 'remove' : 'install') . '>' . ($clOn ? 'Turn off' : 'Install') . '</button></form>'
              . ($clOn ? '<form method="POST" action="' . h(basePath() . '/client-admin.php' . ($client ? '?client=' . rawurlencode($client['slug']) : '')) . '" class="studio-inline-form"><input type="hidden" name="action" value="clean_links_install"><button type="submit" class="ui-btn ui-btn--plain ui-btn--sm" data-clean-links-repair>Repair</button></form>' : '');
    ?>
    <?= insetRow(['icon' => 'sliders', 'title' => 'Clean links', 'subtitle' => $clSub, 'chevron' => false,
                  'trailing' => '<span class="studio-inline-actions">' . statusPill($clOn ? 'approved' : 'neutral', false, ['label' => $clOn ? 'On' : 'Off', 'attrs' => ['data-clean-links-state' => $clOn ? 'on' : 'off']]) . $clForm . '</span>',
                  'attrs' => ['data-tool' => 'clean-links', 'id' => 'clean-links']]) ?>
    <?php unset($clOn, $clForced, $clSub, $clForm); ?>
    <?php if ($client && $toolHasPages): ?>
      <?= insetRow(['icon' => 'page', 'title' => 'Pages: repair server rules',
                    'subtitle' => 'Rewrite the media/pages/ rules (.htaccess) and make every uploaded file readable (0644 / 0755)', 'chevron' => false,
                    'trailing' => '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-pages-repair data-endpoint="' . h(basePath() . '/page-upload.php?client=' . rawurlencode($client['slug'])) . '">Repair</button>',
                    'attrs' => ['data-tool' => 'pages-repair']]) ?>
    <?php endif; ?>
    <?php if (hasActivityLog($pdo)): ?>
      <?= insetRow(['icon' => 'mail', 'title' => 'Morning summary', 'subtitle' => 'Email the summary of client activity since the last one now (it also goes out daily — Notifications)', 'chevron' => false,
                    'trailing' => '<form method="POST" action="' . h(basePath() . '/digest.php') . '" target="digest_iframe" data-digest-form><input type="hidden" name="source" value="manual"><button type="submit" class="ui-btn ui-btn--gray ui-btn--sm">Send now</button></form>',
                    'attrs' => ['data-tool' => 'digest']]) ?>
    <?php endif; ?>
  <?= insetListClose() ?>
  <!-- Maintenance → Image previews backfill (preview-lib.php / preview-job.php / static/js/studio-previews.js) -->
  <section class="ui-card studio-export-card manage-previews" id="previews" data-previews data-tool="previews" data-endpoint="<?= h(basePath() . '/preview-job.php' . ($client ? '?client=' . rawurlencode($client['slug']) : '')) ?>">
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Image previews</h3>
      <p class="ui-card-subtitle">Small (480 px) and large (1600 px) <?= h(strtoupper(previewFormat())) ?> copies of every image, used by tiles, lists and the viewer instead of the full originals. New uploads get them automatically (made in the browser while the file uploads); this builds them for everything already on the server (tire images and FTP drops, library files incl. FTP / Drive drops, posts), two at a time so pages stay fast. Originals are never changed.</p></div></div>
    <div class="ui-card-body">
      <?php if ($client): ?>
        <label class="studio-export-choice"><input type="checkbox" data-previews-all> <span>All clients (not just <?= h($client['name']) ?>)</span></label>
      <?php else: ?>
        <input type="checkbox" data-previews-all checked hidden aria-hidden="true" tabindex="-1">
        <p class="text-secondary">Every client’s images.</p>
      <?php endif; ?>
      <p class="studio-export-estimate" data-previews-status aria-live="polite">Checking…</p>
      <div class="studio-export-progress" data-previews-progress hidden role="status">
        <div class="studio-progress"><div class="studio-progress-bar"><div class="studio-progress-fill" data-previews-fill style="width:0%"></div></div></div>
      </div>
      <div class="studio-export-actions">
        <button type="button" class="ui-btn ui-btn--filled" data-previews-build><span>Build previews</span></button>
      </div>
    </div>
  </section>
  <iframe name="digest_iframe" class="ui-visually-hidden" aria-hidden="true" tabindex="-1"></iframe>

  <?php if (!$client): ?>
    <?= $clientPicker($pdo, 'tools', 'Tires, AI Builder, Projects and the email tools work per client.') ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php // Phones / tablets: the sidebar (and its "Sign out") is a bottom bar there, so Manage carries the one sign-out link. ?>
<p class="manage-signout" data-manage-signout>Signed in as Joust · <a href="<?= h(pagePath('logout')) ?>">Sign out</a></p>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
