<?php
// Not a page: only meaningful when included from a page that loaded helpers.php.
if (!function_exists('esc')) { http_response_code(404); exit; }
/**
 * Role-aware tab bar — fixed bottom on mobile, left sidebar at ≥1024px.
 *
 *   Client: Home · Assets · Posts · Projects
 *   Admin:  + Studio (Joust orange). The Studio tab is never rendered for a
 *           client — the role check is server-side (isAdmin()), not CSS.
 *
 * Reads from the including scope: $client, $pdo (helpers.php globals) and an
 * optional $activeTab override ('home'|'assets'|'posts'|'projects'|'studio').
 * When $activeTab is not set the active tab is derived from SCRIPT_NAME.
 *
 * Badges on Assets and Posts = items awaiting the client's action (pending),
 * scoped to the current client. A DB hiccup can never break the nav.
 *
 * ONE place to update when later phases ship the new pages:
 * change 'page' (and the 'scripts' aliases) below.
 */
$uiTabs = [
    'home'     => ['label' => 'Home',     'icon' => 'house',     'page' => 'index.php',
                   'scripts' => ['index']],
    'assets'   => ['label' => 'Assets',   'icon' => 'photo',     'page' => 'assets.php',
                   'scripts' => ['library', 'features', 'tires', 'assets']],
    'posts'    => ['label' => 'Posts',    'icon' => 'grid',      'page' => 'posts.php',
                   'scripts' => ['feed', 'posts']],
    'projects' => ['label' => 'Projects', 'icon' => 'checklist', 'page' => 'projects.php',
                   'scripts' => ['projects', 'add-project']],
    'studio'   => ['label' => 'Studio',   'icon' => 'wand',      'page' => 'studio.php',
                   'scripts' => ['admin', 'studio', 'add-post', 'add-feature', 'add-tire', 'batch', 'build',
                                 'prompts', 'add-prompt', 'vehicles', 'add-vehicle'],
                   'admin' => true],
];

$uiIsAdmin = function_exists('isAdmin') && isAdmin();

// Active tab: explicit override, else the current script name.
$uiActive = isset($activeTab) && $activeTab !== null ? (string)$activeTab : null;
if ($uiActive === null) {
    $uiScript = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php'));
    foreach ($uiTabs as $uiKey => $uiTab) {
        if (in_array($uiScript, $uiTab['scripts'], true)) { $uiActive = $uiKey; break; }
    }
}

// Badge counts — pending items only, scoped to the client, never fatal.
$uiBadges = ['assets' => 0, 'posts' => 0];
if (!empty($client['id']) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $uiCid = (int)$client['id'];

        $uiSt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE company_id = ? AND status = 'pending'");
        $uiSt->execute([$uiCid]);
        $uiBadges['posts'] = (int)$uiSt->fetchColumn();

        $uiSt = $pdo->prepare("
            SELECT COUNT(*) FROM tire_images ti
            INNER JOIN tires t ON t.id = ti.tire_id
            WHERE t.company_id = ? AND ti.status = 'pending'
        ");
        $uiSt->execute([$uiCid]);
        $uiBadges['assets'] = (int)$uiSt->fetchColumn();

        if (function_exists('hasLibraryImagesTable') && hasLibraryImagesTable($pdo)) {
            $uiSt = $pdo->prepare("SELECT COUNT(*) FROM library_images WHERE company_id = ? AND status = 'pending'");
            $uiSt->execute([$uiCid]);
            $uiBadges['assets'] += (int)$uiSt->fetchColumn();
        }
    } catch (Throwable $uiErr) {
        error_log('tabbar badge query failed: ' . $uiErr->getMessage());
        $uiBadges = ['assets' => 0, 'posts' => 0];
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
      $uiIsActive = ($uiKey === $uiActive);
      $uiCount    = $uiBadges[$uiKey] ?? 0;
      $uiCls      = 'ui-tab ui-tab--' . $uiKey . ($uiIsActive ? ' is-active' : '');
    ?>
      <li>
        <a class="<?= esc($uiCls) ?>" href="<?= esc(clientUrl($uiTab['page'])) ?>"<?= $uiIsActive ? ' aria-current="page"' : '' ?>>
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
    <div class="ui-tabbar-footer">Signed in as Joust · <a href="<?= esc(basePath() . '/logout') ?>">Sign out</a></div>
  <?php endif; ?>
</nav>
<?php unset($uiTabs, $uiIsAdmin, $uiActive, $uiScript, $uiKey, $uiTab, $uiBadges, $uiCid, $uiSt, $uiErr, $uiBrandName, $uiBrandHref, $uiIsActive, $uiCount, $uiCls); ?>
