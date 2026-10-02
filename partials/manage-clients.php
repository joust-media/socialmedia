<?php
// Not a page: only meaningful when included from manage.php (helpers.php loaded, admin verified).
if (!function_exists('esc') || !function_exists('isAdmin') || !isAdmin()) { http_response_code(404); exit; }
/**
 * Manage → Clients: company management — the one place the portal creates and edits companies
 * (name, slug, feature label, logo, settings) and the ONLY place a client's Tires / Emails / Pages
 * tabs are turned on or off. Included by manage.php (section=clients, the default).
 *
 *   - "New client" card: name, slug (auto from the name, editable), feature label, logo file
 *   - list of every company: logo (brandLogoUrl() → uploads/ → static/brand/<slug> → initials),
 *     name, slug, feature label, Tires / Emails / Pages module state; a row opens its edit card
 *   - edit card (?edit=<id>; scoped to a client without &edit → that client's card, &edit=0 → the list):
 *     the same fields, replace / remove logo, Settings (default hashtags; AI Builder product type +
 *     industry — once their migration-gated columns exist), module toggles
 *
 * Every form posts to client-admin.php (studio.js submits them with fetch + FormData and
 * follows the JSON `redirect`; without JS the endpoint redirects back here itself).
 * Deleting a company is deliberately not offered.
 *
 * Reads from the including scope: $pdo, $client (the scoped company, may be null), $_GET['edit'].
 */
$scCompanies = [];
$scSettingCols = [];   // migration-gated companies columns: default_hashtags (step 11a), product_type + industry
try {
    $scSettingCols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'companies' AND COLUMN_NAME IN ('default_hashtags', 'product_type', 'industry')")->fetchAll(PDO::FETCH_COLUMN);
    $scSettingCols = array_map('strval', $scSettingCols);
    $scCompanies = $pdo->query("SELECT id, name, slug, feature_label, logo_url"
        . ($scSettingCols ? ', ' . implode(', ', $scSettingCols) : '') . " FROM companies ORDER BY name ASC")->fetchAll();
} catch (Throwable $scErr) {
    error_log('manage clients list failed: ' . $scErr->getMessage());
}
$scModules   = ['tires' => 'Tires tab', 'emails' => 'Emails tab', 'pages' => 'Pages tab'];
$scModuleIds = [];
$scEnabled   = [];    // [company_id][module slug] = true
try {
    foreach ($pdo->query("SELECT id, slug FROM modules WHERE slug IN ('tires', 'emails', 'pages')")->fetchAll() as $scM) {
        $scModuleIds[(string)$scM['slug']] = (int)$scM['id'];
    }
    if ($scModuleIds) {
        $scSt = $pdo->query("SELECT cm.company_id, m.slug FROM company_modules cm INNER JOIN modules m ON m.id = cm.module_id WHERE m.slug IN ('tires', 'emails', 'pages')");
        foreach ($scSt->fetchAll() as $scR) { $scEnabled[(int)$scR['company_id']][(string)$scR['slug']] = true; }
    }
} catch (Throwable $scErr) {
    error_log('manage clients modules failed: ' . $scErr->getMessage());
}
// Scoped to a client and no explicit &edit → that client's card ("Client settings"); &edit=0 → the list.
$scEditId  = isset($_GET['edit']) ? (int)$_GET['edit'] : (int)($client['id'] ?? 0);
$scEdit    = null;
foreach ($scCompanies as $scC) { if ((int)$scC['id'] === $scEditId) { $scEdit = $scC; break; } }
$scEndpoint = basePath() . '/client-admin.php' . (!empty($client['slug']) ? '?client=' . rawurlencode($client['slug']) : '');
$scListUrl  = clientUrl('manage.php', ['section' => 'clients', 'edit' => !empty($client['id']) ? '0' : null]);
$scErrFlash = trim((string)($_GET['err'] ?? ''));
$scFmt      = static function (array $co) use ($scEnabled, $scModules): string {
    $bits = ['<code>' . esc($co['slug']) . '</code>'];
    if (trim((string)($co['feature_label'] ?? '')) !== '') { $bits[] = esc($co['feature_label']); }
    foreach ($scModules as $scKey => $scLabel) {
        if (!empty($scEnabled[(int)$co['id']][$scKey])) { $bits[] = '<span class="studio-client-mod">' . esc($scLabel) . '</span>'; }
    }
    return implode(' · ', $bits);
};
?>
<div class="studio-clients" data-clients data-endpoint="<?= esc($scEndpoint) ?>" data-list-url="<?= esc($scListUrl) ?>">
  <?php if ($scErrFlash !== ''): ?>
    <div class="studio-alert studio-alert--error" role="alert"><?= esc($scErrFlash) ?></div>
  <?php endif; ?>

  <?php if ($scEdit): ?>
  <!-- Edit card -->
  <section class="ui-card studio-client-card" data-client-edit="<?= (int)$scEdit['id'] ?>">
    <div class="ui-card-header">
      <div class="ui-card-heading">
        <div class="studio-client-head">
          <?= clientAvatar($scEdit, 'ui-avatar--lg') ?>
          <div>
            <h3 class="ui-card-title"><?= esc($scEdit['name']) ?></h3>
            <p class="ui-card-subtitle">Review links use <code>?client=<?= esc($scEdit['slug']) ?></code>. Changing the slug changes every link already sent.</p>
          </div>
        </div>
      </div>
      <div class="ui-card-aside"><a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= esc($scListUrl) ?>" data-client-close>All clients</a></div>
    </div>
    <div class="ui-card-body">
      <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-client-form" data-client-form autocomplete="off">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
        <div class="studio-field-row">
          <div class="studio-field"><label class="studio-label" for="clientName<?= (int)$scEdit['id'] ?>">Name</label>
            <input class="ui-input" type="text" name="name" id="clientName<?= (int)$scEdit['id'] ?>" value="<?= esc($scEdit['name']) ?>" maxlength="120" required data-client-name></div>
          <div class="studio-field"><label class="studio-label" for="clientSlug<?= (int)$scEdit['id'] ?>">Slug <span class="text-tertiary">a–z, 0–9, dashes</span></label>
            <input class="ui-input" type="text" name="slug" id="clientSlug<?= (int)$scEdit['id'] ?>" value="<?= esc($scEdit['slug']) ?>" pattern="[a-z0-9-]{2,40}" maxlength="40" required data-client-slug data-touched="1"></div>
          <div class="studio-field"><label class="studio-label" for="clientLabel<?= (int)$scEdit['id'] ?>">Feature label <span class="text-tertiary">Tires tab name</span></label>
            <input class="ui-input" type="text" name="feature_label" id="clientLabel<?= (int)$scEdit['id'] ?>" value="<?= esc($scEdit['feature_label'] ?? '') ?>" maxlength="60" placeholder="Tires"></div>
        </div>
        <div class="studio-client-actions">
          <button type="submit" class="ui-btn ui-btn--filled">Save changes</button>
          <span class="studio-client-status" data-client-status aria-live="polite"></span>
        </div>
      </form>

      <div class="studio-client-logo">
        <div class="studio-label">Logo</div>
        <div class="studio-client-logo-row">
          <form method="POST" action="<?= esc($scEndpoint) ?>" enctype="multipart/form-data" class="studio-client-form studio-client-form--inline" data-client-form>
            <input type="hidden" name="action" value="logo_upload">
            <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
            <label class="ui-btn ui-btn--tinted studio-client-file">
              <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required data-client-logo-input>
              <span><?= trim((string)$scEdit['logo_url']) !== '' ? 'Replace logo…' : 'Upload logo…' ?></span>
            </label>
            <button type="submit" class="ui-btn ui-btn--filled" data-client-logo-submit>Upload</button>
            <span class="studio-client-status" data-client-status aria-live="polite"></span>
          </form>
          <?php if (trim((string)$scEdit['logo_url']) !== ''): ?>
            <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-inline-form" data-client-form data-confirm-submit="Remove the logo for <?= esc($scEdit['name']) ?>? The initials avatar (or the bundled mark, if one exists) shows instead.">
              <input type="hidden" name="action" value="logo_remove">
              <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
              <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn">Remove logo</button>
            </form>
          <?php endif; ?>
        </div>
        <p class="studio-help">PNG, JPG, GIF or WebP up to 2 MB; resized to 512px and saved as <code>uploads/logo_<?= esc($scEdit['slug']) ?>.png</code> (or .jpg).
          <?php if (brandStaticLogoUrl((string)$scEdit['slug']) !== '' && trim((string)$scEdit['logo_url']) === ''): ?>Showing the bundled mark <code>static/brand/<?= esc($scEdit['slug']) ?>.png</code> until one is uploaded.<?php endif; ?></p>
      </div>

      <?php if ($scSettingCols): ?>
      <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-client-form studio-client-settings" data-client-form data-client-settings autocomplete="off">
        <input type="hidden" name="action" value="settings">
        <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
        <div class="studio-label">Settings</div>
        <?php if (in_array('default_hashtags', $scSettingCols, true)): ?>
          <div class="studio-field"><label class="studio-label" for="clientTags<?= (int)$scEdit['id'] ?>">Default hashtags <span class="text-tertiary">pre-filled on every new post</span></label>
            <textarea class="ui-textarea" name="default_hashtags" id="clientTags<?= (int)$scEdit['id'] ?>" rows="2" maxlength="4000" placeholder="#Brand #Campaign" data-client-hashtags><?= esc($scEdit['default_hashtags'] ?? '') ?></textarea></div>
        <?php endif; ?>
        <?php if (in_array('product_type', $scSettingCols, true) || in_array('industry', $scSettingCols, true)): ?>
        <div class="studio-field-row">
          <?php if (in_array('product_type', $scSettingCols, true)): ?>
          <div class="studio-field"><label class="studio-label" for="clientProduct<?= (int)$scEdit['id'] ?>">Product type <span class="text-tertiary">AI Builder</span></label>
            <input class="ui-input" type="text" name="product_type" id="clientProduct<?= (int)$scEdit['id'] ?>" maxlength="120" value="<?= esc($scEdit['product_type'] ?? '') ?>" placeholder="e.g. tires, apparel, software"></div>
          <?php endif; ?>
          <?php if (in_array('industry', $scSettingCols, true)): ?>
          <div class="studio-field"><label class="studio-label" for="clientIndustry<?= (int)$scEdit['id'] ?>">Industry <span class="text-tertiary">AI Builder</span></label>
            <input class="ui-input" type="text" name="industry" id="clientIndustry<?= (int)$scEdit['id'] ?>" maxlength="120" value="<?= esc($scEdit['industry'] ?? '') ?>" placeholder="e.g. powersports, retail, SaaS"></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="studio-client-actions">
          <button type="submit" class="ui-btn ui-btn--tinted">Save settings</button>
          <span class="studio-client-status" data-client-status aria-live="polite"></span>
        </div>
      </form>
      <?php endif; ?>

      <div class="studio-client-modules" data-client-modules>
        <div class="studio-label">Modules</div>
        <ul class="studio-client-modlist" role="list">
          <?php foreach ($scModules as $scKey => $scLabel):
              $scOn = !empty($scEnabled[(int)$scEdit['id']][$scKey]);
              $scHas = isset($scModuleIds[$scKey]);
          ?>
            <li class="studio-client-mod-row" data-client-module="<?= esc($scKey) ?>">
              <div class="studio-client-mod-body">
                <div class="ui-row-title"><?= esc($scLabel) ?></div>
                <div class="ui-row-subtitle"><?= $scKey === 'tires'
                    ? 'Shows the ' . esc(trim((string)($scEdit['feature_label'] ?? '')) !== '' ? $scEdit['feature_label'] : 'Collections') . ' tab. It also appears on its own once the client has a tire.'
                    : ($scKey === 'pages'
                        ? 'Shows the Pages tab even with zero pages. It also appears on its own once the client has a page.'
                        : 'Shows the Emails tab even with zero emails. It also appears on its own once the client has an email.') ?></div>
              </div>
              <?php if (!$scHas): ?>
                <span class="text-tertiary">Run migrate.php first</span>
              <?php else: ?>
                <?= statusPill($scOn ? 'approved' : 'neutral', false, ['label' => $scOn ? 'On' : 'Off', 'attrs' => ['data-client-module-state' => $scOn ? 'on' : 'off']]) ?>
                <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-inline-form" data-client-form>
                  <input type="hidden" name="action" value="module_toggle">
                  <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
                  <input type="hidden" name="module" value="<?= esc($scKey) ?>">
                  <input type="hidden" name="to" value="<?= $scOn ? 0 : 1 ?>">
                  <button type="submit" class="ui-btn ui-btn--sm <?= $scOn ? 'ui-btn--gray' : 'ui-btn--tinted' ?>"><?= $scOn ? 'Turn off' : 'Turn on' ?></button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </section>
  <?php else: ?>
  <!-- New client card -->
  <section class="ui-card studio-client-card" data-client-new>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">New client</h3>
      <p class="ui-card-subtitle">The slug becomes the review link (<code>?client=slug</code>) and the folder names under <code>media/</code> — pick it once.</p></div></div>
    <div class="ui-card-body">
      <form method="POST" action="<?= esc($scEndpoint) ?>" enctype="multipart/form-data" class="studio-client-form" data-client-form autocomplete="off">
        <input type="hidden" name="action" value="create">
        <div class="studio-field-row">
          <div class="studio-field"><label class="studio-label" for="clientNewName">Name</label>
            <input class="ui-input" type="text" name="name" id="clientNewName" maxlength="120" required placeholder="Cometic Gasket" data-client-name></div>
          <div class="studio-field"><label class="studio-label" for="clientNewSlug">Slug <span class="text-tertiary">auto from the name</span></label>
            <input class="ui-input" type="text" name="slug" id="clientNewSlug" pattern="[a-z0-9-]{2,40}" maxlength="40" placeholder="cometic" data-client-slug></div>
          <div class="studio-field"><label class="studio-label" for="clientNewLabel">Feature label <span class="text-tertiary">optional</span></label>
            <input class="ui-input" type="text" name="feature_label" id="clientNewLabel" maxlength="60" placeholder="Tires"></div>
        </div>
        <div class="studio-client-actions">
          <label class="ui-btn ui-btn--gray studio-client-file">
            <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" data-client-logo-input>
            <span data-client-file-label>Add a logo… (optional)</span>
          </label>
          <button type="submit" class="ui-btn ui-btn--filled">Create client</button>
          <span class="studio-client-status" data-client-status aria-live="polite"></span>
        </div>
        <p class="studio-help">No logo yet? A bundled mark in <code>static/brand/&lt;slug&gt;.png</code> shows automatically (there is one for <code>privacybee</code>, <code>cometic</code> and <code>hmf</code>); otherwise the client's initial does.</p>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <?= insetListOpen('Clients (' . count($scCompanies) . ')', ['attrs' => ['data-client-list' => '1']]) ?>
    <?php if (!$scCompanies): ?>
      <li><div class="ui-row"><div class="ui-row-body"><div class="ui-row-subtitle">No clients yet — create the first one above.</div></div></div></li>
    <?php endif; ?>
    <?php foreach ($scCompanies as $scC): ?>
      <?= insetRow([
          'href'        => clientUrl('manage.php', ['section' => 'clients', 'edit' => (int)$scC['id']]),
          'leading'     => clientAvatar($scC, 'ui-avatar--lg'),
          'title'       => $scC['name'],
          'subtitle'    => $scFmt($scC),
          'rawSubtitle' => true,
          'trailing'    => '<span class="ui-btn ui-btn--gray ui-btn--sm">Edit</span>',
          'chevron'     => false,
          'class'       => (int)$scC['id'] === $scEditId ? 'is-editing' : '',
          'attrs'       => ['data-client-row' => $scC['slug']],
      ]) ?>
    <?php endforeach; ?>
  <?= insetListClose('Logos come from the uploaded file, else the bundled static/brand mark, else the initial. Clients cannot be deleted here.') ?>
</div>
<?php unset($scSettingCols, $scCompanies, $scModules, $scModuleIds, $scEnabled, $scEditId, $scEdit, $scEndpoint, $scListUrl, $scErrFlash, $scFmt, $scC, $scM, $scR, $scSt, $scErr, $scKey, $scLabel, $scOn, $scHas); ?>
