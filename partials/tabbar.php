<?php
// Not a page: only meaningful when included from a page that loaded helpers.php.
if (!function_exists('esc')) { http_response_code(404); exit; }
/**
 * Role-aware tab bar — fixed bottom on mobile, left sidebar at ≥1024px.
 *
 *   Client: Home · Assets · [Tires] · Posts · [Emails] · Projects
 *   Admin:  + Studio (Joust orange). The Studio tab is never rendered for a
 *           client — the role check is server-side (isAdmin()), not CSS.
 *   Tires:  only for companies with the tires module enabled or at least one
 *           tires row (companyHasTires(), helpers.php). Labelled with the
 *           company's own word (companies.feature_label, "Tires" for Kenda;
 *           "Collections" when unset) and linking to assets.php?view=collections.
 *   Emails: only for companies with the emails module enabled or at least one
 *           email row (companyHasEmails(), emails-lib.php) — same for both roles.
 *
 * Reads from the including scope: $client, $pdo (helpers.php globals) and an
 * optional $activeTab override ('home'|'assets'|'tires'|'posts'|'emails'|'projects'|'studio').
 * When $activeTab is not set the active tab is derived from SCRIPT_NAME; on
 * assets.php, ?view=collections (or a tire deep link, kind=tire) → Tires.
 * 'tires' falls back to Assets when the company has no Tires tab.
 *
 * Badges on Assets, Tires, Posts and Emails = items awaiting the client's action
 * (pending), scoped to the current client. With a Tires tab present the Assets
 * badge is pending library images only and Tires is pending tire images, so a
 * render never counts twice; without it Assets carries both. A DB hiccup can
 * never break the nav. Every tab carries data-tab="<key>" for the page scripts
 * (assets.js keeps the right badge live after a decision).
 *
 * ONE place to update when later phases ship the new pages:
 * change 'page' (and the 'scripts' aliases) below.
 */
$uiTiresLabel = trim((string)($client['feature_label'] ?? ''));
if ($uiTiresLabel === '') { $uiTiresLabel = 'Collections'; }

$uiTabs = [
    'home'     => ['label' => 'Home',     'icon' => 'house',     'page' => 'index.php',
                   'scripts' => ['index']],
    'assets'   => ['label' => 'Assets',   'icon' => 'photo',     'page' => 'assets.php',
                   'scripts' => ['library', 'features', 'tires', 'assets']],
    'tires'    => ['label' => $uiTiresLabel, 'icon' => 'tire',   'page' => 'assets.php',
                   'query' => ['view' => 'collections'], 'scripts' => [],
                   'module' => 'tires'],
    'posts'    => ['label' => 'Posts',    'icon' => 'grid',      'page' => 'posts.php',
                   'scripts' => ['feed', 'posts']],
    'emails'   => ['label' => 'Emails',   'icon' => 'mail',      'page' => 'emails.php',
                   'scripts' => ['emails', 'email-status', 'flows', 'flow-status'],
                   'module' => 'emails'],
    'pages'    => ['label' => 'Pages',    'icon' => 'page',      'page' => 'pages.php',
                   'scripts' => ['pages', 'page-status'],
                   'module' => 'pages'],
    'projects' => ['label' => 'Projects', 'icon' => 'checklist', 'page' => 'projects.php',
                   'scripts' => ['projects', 'add-project']],
    'studio'   => ['label' => 'Studio',   'icon' => 'wand',      'page' => 'studio.php',
                   'scripts' => ['admin', 'studio', 'add-post', 'add-feature', 'add-tire', 'batch', 'build',
                                 'prompts', 'add-prompt', 'vehicles', 'add-vehicle', 'add-email', 'emails-io', 'add-page', 'drive'],
                   'admin' => true],
];

$uiIsAdmin = function_exists('isAdmin') && isAdmin();

// Module-gated tabs (Tires, Emails): shown only when the scoped company has the module / any rows.
$uiHasEmails = false; $uiHasTires = false;
if (!empty($client['id']) && isset($pdo) && $pdo instanceof PDO) {
    if (function_exists('companyHasEmails')) {
        try { $uiHasEmails = companyHasEmails($client, $pdo); } catch (Throwable $uiErr) { $uiHasEmails = false; }
    }
    if (function_exists('companyHasTires')) {
        try { $uiHasTires = companyHasTires($client, $pdo); } catch (Throwable $uiErr) { $uiHasTires = false; }
    }
}
$uiHasPages = false;   // Pages module (pages-lib.php): module enabled or at least one pages row
if (!empty($client['id']) && isset($pdo) && $pdo instanceof PDO && function_exists('companyHasPages')) {
    try { $uiHasPages = companyHasPages($client, $pdo); } catch (Throwable $uiErr) { $uiHasPages = false; }
}
$uiModules = ['emails' => $uiHasEmails, 'tires' => $uiHasTires, 'pages' => $uiHasPages];

// Active tab: explicit override, else the current script name (+ the assets.php view).
$uiActive = isset($activeTab) && $activeTab !== null ? (string)$activeTab : null;
if ($uiActive === null) {
    $uiScript = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php'));
    foreach ($uiTabs as $uiKey => $uiTab) {
        if (in_array($uiScript, $uiTab['scripts'], true)) { $uiActive = $uiKey; break; }
    }
    if ($uiActive === 'assets' && $uiScript === 'assets'
        && ((($_GET['view'] ?? '') === 'collections') || (($_GET['kind'] ?? '') === 'tire') || (int)($_GET['image'] ?? 0) > 0)) {
        $uiActive = 'tires';
    }
}
if ($uiActive === 'tires' && !$uiHasTires) { $uiActive = 'assets'; }   // no Tires tab → collections live under Assets

// Badge counts — pending items only, scoped to the client, never fatal.
$uiBadges = ['assets' => 0, 'tires' => 0, 'posts' => 0, 'emails' => 0];
if (!empty($client['id']) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $uiCid = (int)$client['id'];

        $uiSt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE company_id = ? AND status = 'pending'");
        $uiSt->execute([$uiCid]);
        $uiBadges['posts'] = (int)$uiSt->fetchColumn();

        if ($uiHasEmails) {
            $uiSt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE company_id = ? AND status = 'pending' AND live = 0");
            $uiSt->execute([$uiCid]);
            $uiBadges['emails'] = (int)$uiSt->fetchColumn();
        }
        if ($uiHasPages) {
            $uiSt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE company_id = ? AND status = 'pending' AND live = 0");
            $uiSt->execute([$uiCid]);
            $uiBadges['pages'] = (int)$uiSt->fetchColumn();
        }

        $uiSt = $pdo->prepare("
            SELECT COUNT(*) FROM tire_images ti
            INNER JOIN tires t ON t.id = ti.tire_id
            WHERE t.company_id = ? AND ti.status = 'pending'
        ");
        $uiSt->execute([$uiCid]);
        $uiBadges[$uiHasTires ? 'tires' : 'assets'] = (int)$uiSt->fetchColumn();

        if (function_exists('hasLibraryImagesTable') && hasLibraryImagesTable($pdo)) {
            $uiSt = $pdo->prepare("SELECT COUNT(*) FROM library_images WHERE company_id = ? AND status = 'pending'");
            $uiSt->execute([$uiCid]);
            $uiBadges['assets'] += (int)$uiSt->fetchColumn();
        }
    } catch (Throwable $uiErr) {
        error_log('tabbar badge query failed: ' . $uiErr->getMessage());
        $uiBadges = ['assets' => 0, 'tires' => 0, 'posts' => 0, 'emails' => 0];
    }
}

$uiBrandName = !empty($client['name']) ? $client['name'] : 'Joust Media';
$uiBrandHref = clientUrl($uiIsAdmin && empty($client) ? 'admin.php' : 'index.php');
?>
<nav class="ui-tabbar ui-glass ui-glass--top" aria-label="Main navigation">
  <a class="ui-tabbar-brand" href="<?= esc($uiBrandHref) ?>">
    <?= function_exists('clientAvatar') ? clientAvatar($client, 'ui-avatar--sm') : '' ?>
    <span><?= esc($uiBrandName) ?></span>
  </a>
  <ul class="ui-tabbar-list">
    <?php foreach ($uiTabs as $uiKey => $uiTab):
      if (!empty($uiTab['admin']) && !$uiIsAdmin) continue;   // admin-only tab: not rendered for clients
      if (!empty($uiTab['module']) && empty($uiModules[$uiTab['module']])) continue; // module-gated tab (Tires / Emails): company has none
      $uiIsActive = ($uiKey === $uiActive);
      $uiCount    = $uiBadges[$uiKey] ?? 0;
      $uiCls      = 'ui-tab ui-tab--' . $uiKey . ($uiIsActive ? ' is-active' : '');
    ?>
      <li>
        <a class="<?= esc($uiCls) ?>" href="<?= esc(clientUrl($uiTab['page'], $uiTab['query'] ?? [])) ?>"<?= $uiIsActive ? ' aria-current="page"' : '' ?> data-tab="<?= esc($uiKey) ?>">
          <?= icon($uiTab['icon']) ?>
          <span class="ui-tab-label"><?= esc($uiTab['label']) ?></span>
          <?php if ($uiCount > 0): ?>
            <span class="ui-badge ui-tab-badge" aria-label="<?= esc($uiCount . ' to review') ?>"><?= $uiCount > 99 ? '99+' : (int)$uiCount ?></span>
          <?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($uiIsAdmin): ?>
    <div class="ui-tabbar-footer">Signed in as Joust · <a href="<?= esc(pagePath('logout')) ?>">Sign out</a></div>
  <?php endif; ?>
</nav>
<?php unset($uiTabs, $uiTiresLabel, $uiIsAdmin, $uiHasEmails, $uiHasTires, $uiHasPages, $uiModules, $uiActive, $uiScript, $uiKey, $uiTab, $uiBadges, $uiCid, $uiSt, $uiErr, $uiBrandName, $uiBrandHref, $uiIsActive, $uiCount, $uiCls); ?>
