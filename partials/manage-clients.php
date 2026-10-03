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
 *     ONE form + ONE "Save changes" for the fields and Settings (default hashtags; AI Builder product type +
 *     industry — once their migration-gated columns exist), then replace / remove logo, module toggles.
 *     No file paths in the copy (they sit in a tooltip on the logo help line).
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
            <p class="ui-card-subtitle">Portal address <code><?= esc(portalUrl('index', ['client' => $scEdit['slug']])) ?></code> — clients sign in with an email on the Contacts list below. Changing the slug changes every link already sent.</p>
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
      <?php if ($scSettingCols): ?>
        <div class="studio-client-settings" data-client-settings>
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
        </div>
      <?php endif; ?>
        <div class="studio-client-actions">
          <button type="submit" class="ui-btn ui-btn--filled" data-client-save>Save changes</button>
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
        <p class="studio-help" title="<?= esc('Saved as uploads/logo_' . $scEdit['slug'] . '.png (or .jpg); the bundled mark is static/brand/' . $scEdit['slug'] . '.png') ?>">PNG, JPG, GIF or WebP up to 2 MB, resized to 512px.
          <?php if (brandStaticLogoUrl((string)$scEdit['slug']) !== '' && trim((string)$scEdit['logo_url']) === ''): ?>Showing the logo that ships with the portal until one is uploaded.<?php endif; ?></p>
      </div>


      <div class="studio-client-modules" data-client-modules>
        <div class="studio-label">Modules</div>
        <ul class="studio-client-modlist" role="list">
          <?php foreach ($scModules as $scKey => $scLabel):
              $scOn = !empty($scEnabled[(int)$scEdit['id']][$scKey]);
              $scHas = isset($scModuleIds[$scKey]);
          ?>
            <li class="studio-client-mod-row" data-client-module="<?= esc($scKey) ?>">
              <div class="studio-client-mod-body">
                <div class="ui-row-title"><?= esc($scKey === 'tires' ? tiresLabel($scEdit) . ' tab' : $scLabel) ?></div>
                <div class="ui-row-subtitle"><?= $scKey === 'tires'
                    ? 'Shows the ' . esc(tiresLabel($scEdit)) . ' tab and “New tire”. The tab also appears on its own once the client has a tire.'
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

      <?php // ---- Client emails (client-notify-lib.php): which kinds this client's contacts get ----
        $scEmailReady = function_exists('clientEmailReady') && clientEmailReady($pdo);
        $scSwitches = $scEmailReady ? clientEmailClientSwitches($pdo, (int)$scEdit['id']) : [];
      ?>
      <div class="studio-client-emails" data-client-emails id="client-emails">
        <div class="studio-label">Client emails <span class="text-tertiary">to the contacts below</span></div>
        <?php if (!$scEmailReady): ?>
          <p class="text-tertiary">Run migrate.php first (steps 45–49 add client emails).</p>
        <?php else: ?>
        <ul class="studio-client-modlist" role="list">
          <?php foreach (clientEmailKinds() as $scKey => [$scLabel, , $scHelp]): $scOn = !empty($scSwitches[$scKey]); ?>
            <li class="studio-client-mod-row" data-client-email-kind="<?= esc($scKey) ?>">
              <div class="studio-client-mod-body">
                <div class="ui-row-title"><?= esc($scLabel) ?></div>
                <div class="ui-row-subtitle"><?= esc($scHelp) ?></div>
              </div>
              <?= statusPill($scOn ? 'approved' : 'neutral', false, ['label' => $scOn ? 'On' : 'Off', 'attrs' => ['data-client-email-state' => $scOn ? 'on' : 'off']]) ?>
              <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-inline-form" data-client-form>
                <input type="hidden" name="action" value="email_toggle">
                <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
                <input type="hidden" name="kind" value="<?= esc($scKey) ?>">
                <input type="hidden" name="to" value="<?= $scOn ? 0 : 1 ?>">
                <button type="submit" class="ui-btn ui-btn--sm <?= $scOn ? 'ui-btn--gray' : 'ui-btn--tinted' ?>"><?= $scOn ? 'Turn off' : 'Turn on' ?></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="studio-help">Each contact can also turn kinds off (or unsubscribe) from the link at the bottom of every email. Previews: Manage → Notifications → Client emails.</p>
        <?php endif; ?>
      </div>

      <?php // ---- Sign-in: contacts + signed-in devices (client-auth-lib.php) ----
        $scAuthReady = clientAuthReady($pdo);
        $scContacts  = $scAuthReady ? clientContacts($pdo, (int)$scEdit['id']) : [];
        $scSessions  = $scAuthReady ? clientSessionsForCompany($pdo, (int)$scEdit['id']) : [];
      ?>
      <div class="studio-client-contacts" data-client-contacts id="contacts">
        <div class="studio-label">Contacts <span class="text-tertiary">who can sign in</span></div>
        <?php if (!$scAuthReady): ?>
          <p class="text-tertiary">Run migrate.php first (steps 40–43 add client sign-in).</p>
        <?php else: ?>
        <ul class="studio-client-modlist" role="list" data-contact-list>
          <?php if (!$scContacts): ?>
            <li class="studio-client-mod-row" data-contact-empty><div class="studio-client-mod-body"><div class="ui-row-subtitle">No contacts yet — <?= esc($scEdit['name']) ?> can’t sign in until you add an email.</div></div></li>
          <?php endif; ?>
          <?php foreach ($scContacts as $scP): ?>
            <li class="studio-client-mod-row" data-contact="<?= (int)$scP['id'] ?>">
              <div class="studio-client-mod-body">
                <div class="ui-row-title"><?= esc(trim((string)$scP['name']) !== '' ? $scP['name'] : $scP['email']) ?></div>
                <div class="ui-row-subtitle"><?= trim((string)$scP['name']) !== '' ? esc($scP['email']) . ' · ' : '' ?><?= $scP['last_login_at'] ? 'last signed in ' . esc(relativeTime($scP['last_login_at'])) : 'never signed in' ?><?= (int)$scP['active_sessions'] > 0 ? ' · ' . (int)$scP['active_sessions'] . ' device' . ((int)$scP['active_sessions'] === 1 ? '' : 's') : '' ?></div>
              </div>
              <?php if ((int)$scP['active_sessions'] > 0): ?>
              <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-inline-form" data-client-form data-confirm-submit="Sign <?= esc($scP['email']) ?> out on every device and void the links already emailed to them?">
                <input type="hidden" name="action" value="sessions_revoke_all">
                <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
                <input type="hidden" name="contact_id" value="<?= (int)$scP['id'] ?>">
                <input type="hidden" name="links" value="1">
                <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm" data-contact-signout>Sign out everywhere</button>
              </form>
              <?php endif; ?>
              <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-inline-form" data-client-form data-confirm-submit="Remove <?= esc($scP['email']) ?>? They are signed out at once and their links stop working.">
                <input type="hidden" name="action" value="contact_remove">
                <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
                <input type="hidden" name="contact_id" value="<?= (int)$scP['id'] ?>">
                <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn" data-contact-remove>Remove</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-client-form studio-contact-add" data-client-form data-contact-add autocomplete="off">
          <input type="hidden" name="action" value="contact_add">
          <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
          <div class="studio-field-row">
            <div class="studio-field"><label class="studio-label" for="contactEmail<?= (int)$scEdit['id'] ?>">Email</label>
              <input class="ui-input" type="email" name="email" id="contactEmail<?= (int)$scEdit['id'] ?>" maxlength="190" required placeholder="name@<?= esc($scEdit['slug']) ?>.com" data-contact-email></div>
            <div class="studio-field"><label class="studio-label" for="contactName<?= (int)$scEdit['id'] ?>">Name <span class="text-tertiary">optional</span></label>
              <input class="ui-input" type="text" name="contact_name" id="contactName<?= (int)$scEdit['id'] ?>" maxlength="120" placeholder="Sarah Lee"></div>
          </div>
          <div class="studio-client-actions">
            <button type="submit" class="ui-btn ui-btn--tinted">Add contact</button>
            <span class="studio-client-status" data-client-status aria-live="polite"></span>
          </div>
        </form>
        <p class="studio-help">Contacts sign in with a one-time link emailed to them (no password) and stay signed in for 30 days on that device. Removing a contact signs them out everywhere.</p>
        <?php endif; ?>
      </div>

      <?php if ($scAuthReady): ?>
      <div class="studio-client-sessions" data-client-sessions id="sessions">
        <div class="studio-label">Signed in <span class="text-tertiary"><?= count($scSessions) ?> device<?= count($scSessions) === 1 ? '' : 's' ?></span></div>
        <ul class="studio-client-modlist" role="list">
          <?php if (!$scSessions): ?>
            <li class="studio-client-mod-row" data-session-empty><div class="studio-client-mod-body"><div class="ui-row-subtitle">Nobody from <?= esc($scEdit['name']) ?> is signed in right now.</div></div></li>
          <?php endif; ?>
          <?php foreach ($scSessions as $scS): ?>
            <li class="studio-client-mod-row" data-session="<?= (int)$scS['id'] ?>">
              <div class="studio-client-mod-body">
                <div class="ui-row-title"><?= esc(trim((string)$scS['name']) !== '' ? $scS['name'] . ' · ' . $scS['email'] : $scS['email']) ?></div>
                <div class="ui-row-subtitle" title="<?= esc((string)$scS['user_agent']) ?>"><?= esc(clientDeviceLabel((string)$scS['user_agent'])) ?> · signed in <?= esc(relativeTime($scS['created_at'])) ?> <?= $scS['via'] === 'link' ? 'from an emailed link' : ($scS['via'] === 'test' ? '(test)' : 'with a sign-in email') ?> · active <?= esc(relativeTime($scS['last_seen_at'] ?: $scS['created_at'])) ?> · until <?= esc(date('M j', strtotime((string)$scS['expires_at']))) ?></div>
              </div>
              <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-inline-form" data-client-form>
                <input type="hidden" name="action" value="session_revoke">
                <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
                <input type="hidden" name="session_id" value="<?= (int)$scS['id'] ?>">
                <button type="submit" class="ui-btn ui-btn--gray ui-btn--sm" data-session-revoke>Sign out</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="studio-client-actions">
          <?php if ($scSessions): ?>
          <form method="POST" action="<?= esc($scEndpoint) ?>" class="studio-inline-form" data-client-form data-confirm-submit="Sign everyone at <?= esc($scEdit['name']) ?> out on every device?">
            <input type="hidden" name="action" value="sessions_revoke_all">
            <input type="hidden" name="id" value="<?= (int)$scEdit['id'] ?>">
            <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn" data-sessions-revoke-all>Sign everyone out</button>
          </form>
          <?php endif; ?>
          <form method="POST" action="<?= esc(pagePath('view-as')) ?>" class="studio-inline-form" data-view-as-form>
            <input type="hidden" name="client" value="<?= esc($scEdit['slug']) ?>">
            <button type="submit" class="ui-btn ui-btn--gray ui-btn--sm" data-view-as-start>View as client</button>
          </form>
        </div>
        <p class="studio-help">“View as client” shows you <?= esc($scEdit['name']) ?>’s portal exactly as they see it — no sign-in email needed. A banner on top takes you back.</p>
      </div>
      <?php endif; ?>
    </div>
  </section>
  <?php else: ?>
  <!-- New client card -->
  <section class="ui-card studio-client-card" data-client-new>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">New client</h3>
      <p class="ui-card-subtitle">The slug becomes the portal address (<code><?= esc(cleanUrlsOn() ? basePath() . '/slug/' : basePath() . '/?client=slug') ?></code>) and the client's folder names — pick it once.</p></div></div>
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
        <p class="studio-help" title="Bundled marks live in static/brand/&lt;slug&gt;.png">No logo yet? If a logo ships with the portal for this client it shows automatically; otherwise the client's initial does.</p>
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
  <?= insetListClose('Logos come from the uploaded file, else the logo that ships with the portal, else the initial. Clients cannot be deleted here.') ?>
</div>
<?php unset($scEmailReady, $scSwitches, $scHelp, $scAuthReady, $scContacts, $scSessions, $scP, $scS, $scSettingCols, $scCompanies, $scModules, $scModuleIds, $scEnabled, $scEditId, $scEdit, $scEndpoint, $scListUrl, $scErrFlash, $scFmt, $scC, $scM, $scR, $scSt, $scErr, $scKey, $scLabel, $scOn, $scHas); ?>
