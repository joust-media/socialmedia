<?php
// Not a page: only meaningful when included from a page that loaded helpers.php.
if (!function_exists('esc')) { http_response_code(404); exit; }
/**
 * Role-aware tab bar — fixed bottom on mobile, left sidebar at ≥1024px.
 *
 *   Client: Home · Assets · [Tires] · Posts · [Emails] · [Pages] · Projects   (unchanged; footer: Email settings · Sign out)
 *   Admin:  Home · Assets · [Tires] · Posts · [Emails] · [Pages] · Manage — never more than 6
 *           (UI_TABS_MAX_ADMIN): when Tires, Emails and Pages are all on, Emails and Pages share one
 *           "Emails/Pages" destination (short label "Emails" in the phone bar; emails.php / pages.php
 *           carry an Emails · Pages switch). Projects lives in Manage → Tools for the admin.
 *           The Manage tab is never rendered for a client — the role check is server-side
 *           (isAdmin()), not CSS.
 *   Tires:  only for companies with the tires module enabled or at least one
 *           tires row (companyHasTires(), helpers.php). Labelled with the
 *           company's own word (companies.feature_label; "Tires" when unset — tiresLabel())
 *           and linking to assets.php?view=collections. Tire content lives ONLY there: Assets
 *           is the Library (assets.php without view=collections), with no Library · Tires switch.
 *   Emails / Pages: only for companies with the module enabled or at least one
 *           row (companyHasEmails() / companyHasPages()) — same for both roles.
 *
 * Reads from the including scope: $client, $pdo (helpers.php globals) and an
 * optional $activeTab override ('home'|'assets'|'tires'|'posts'|'emails'|'pages'|'projects'|'manage';
 * 'studio' is the old name of 'manage'). When $activeTab is not set the active tab is derived from
 * SCRIPT_NAME; on assets.php, ?view=collections (or a tire deep link, kind=tire) → Tires.
 * 'tires' falls back to Assets when the company has no Tires tab; 'pages' → the merged Emails/Pages tab.
 *
 * Client badges = To Review + items with Joust replies the contact has not read (trackingClientTabReplies(); an item
 * already To Review is not counted twice; aria-label "3 to review, 1 new reply"). Admin badges are unchanged.
 * Admin Home badge = conversations waiting on Joust across clients (the Joust Inbox, inbox.php — reached from Home,
 * so the Inbox costs no tab). Badges on Assets, Tires, Posts, Emails and Pages = items awaiting the client's action
 * (pending), scoped to the current client. With a Tires tab present the Assets
 * badge is pending library images only and Tires is pending tire images, so a
 * render never counts twice; without it Assets carries both. A DB hiccup can
 * never break the nav. Every tab carries data-tab="<key>" for the page scripts
 * (assets.js keeps the right badge live after a decision).
 *
 * ONE place to update when later phases ship the new pages:
 * change 'page' (and the 'scripts' aliases) below.
 */
if (!defined('UI_TABS_MAX_ADMIN')) { define('UI_TABS_MAX_ADMIN', 6); }
$uiTiresLabel = function_exists('tiresLabel') ? tiresLabel($client ?? null) : 'Tires';

$uiTabs = [
    'home'     => ['label' => 'Home',     'icon' => 'house',     'page' => 'index.php',
                   'scripts' => ['index']],
    'assets'   => ['label' => 'Assets',   'icon' => 'photo',     'page' => 'assets.php',
                   'scripts' => ['library', 'features', 'tires', 'assets']],
    'tires'    => ['label' => $uiTiresLabel, 'icon' => 'tire',   'page' => 'assets.php',
                   'query' => ['view' => 'collections'], 'scripts' => ['add-feature', 'add-tire'],
                   'module' => 'tires'],
    'posts'    => ['label' => 'Posts',    'icon' => 'grid',      'page' => 'posts.php',
                   'scripts' => ['feed', 'posts', 'add-post', 'batch']],
    'emails'   => ['label' => 'Emails',   'icon' => 'mail',      'page' => 'emails.php',
                   'scripts' => ['emails', 'email-status', 'flows', 'flow-status', 'add-email', 'emails-io'],
                   'module' => 'emails'],
    'pages'    => ['label' => 'Pages',    'icon' => 'page',      'page' => 'pages.php',
                   'scripts' => ['pages', 'page-status', 'add-page'],
                   'module' => 'pages'],
    'projects' => ['label' => 'Projects', 'icon' => 'checklist', 'page' => 'projects.php',
                   'scripts' => ['projects', 'add-project'],
                   'client' => true],    // the client's tab; the admin reaches Projects from Manage → Tools and Home
    'manage'   => ['label' => 'Manage',   'icon' => 'sliders',   'page' => 'manage.php',
                   'scripts' => ['manage', 'admin', 'studio', 'build', 'prompts', 'add-prompt', 'vehicles', 'add-vehicle',
                                 'drive', 'client-admin'],
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

// The admin bar holds at most UI_TABS_MAX_ADMIN items: with every module on, Emails + Pages share one tab.
$uiMergeMail = false;
if ($uiIsAdmin) {
    $uiCountTabs = 0;
    foreach ($uiTabs as $uiKey => $uiTab) {
        if (!empty($uiTab['client'])) continue;
        if (!empty($uiTab['module']) && empty($uiModules[$uiTab['module']])) continue;
        $uiCountTabs++;
    }
    $uiMergeMail = $uiCountTabs > UI_TABS_MAX_ADMIN && $uiHasEmails && $uiHasPages;   // = navMergesEmailsPages() (helpers.php)
    if ($uiMergeMail) {
        $uiTabs['emails']['label'] = 'Emails/Pages';
        $uiTabs['emails']['short'] = 'Emails';
        $uiTabs['emails']['scripts'] = array_merge($uiTabs['emails']['scripts'], $uiTabs['pages']['scripts']);
        unset($uiTabs['pages']);
    }
}

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
if ($uiActive === 'studio') { $uiActive = 'manage'; }                        // the old name of the admin hub
if ($uiActive === 'tires' && !$uiHasTires) { $uiActive = 'assets'; }   // no Tires tab → collections live under Assets
if ($uiActive === 'pages' && $uiMergeMail) { $uiActive = 'emails'; }   // Pages share the Emails/Pages tab
if ($uiActive === 'projects' && $uiIsAdmin) { $uiActive = 'manage'; }  // the admin's Projects live under Manage

// Badge counts — the viewer's own queue, scoped to the client, never fatal: the client's is To Review ('pending'),
// Joust's is Needs changes ('denied'). The JS that moves a badge after a decision (App.tabBadge, app.js) follows the same rule.
$uiBadges = ['assets' => 0, 'tires' => 0, 'posts' => 0, 'emails' => 0, 'pages' => 0];
$uiQueue  = $uiIsAdmin ? 'denied' : 'pending';
$uiBadgeLabel = $uiIsAdmin ? ' need changes' : ' to review';
if (!empty($client['id']) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $uiCid = (int)$client['id'];

        $uiSt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE company_id = ? AND status = ?");
        $uiSt->execute([$uiCid, $uiQueue]);
        $uiBadges['posts'] = (int)$uiSt->fetchColumn();

        if ($uiHasEmails) {
            $uiSt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE company_id = ? AND status = ? AND live = 0");
            $uiSt->execute([$uiCid, $uiQueue]);
            $uiBadges['emails'] = (int)$uiSt->fetchColumn();
        }
        if ($uiHasPages) {
            $uiSt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE company_id = ? AND status = ? AND live = 0");
            $uiSt->execute([$uiCid, $uiQueue]);
            $uiBadges['pages'] = (int)$uiSt->fetchColumn();
        }

        $uiSt = $pdo->prepare("
            SELECT COUNT(*) FROM tire_images ti
            INNER JOIN tires t ON t.id = ti.tire_id
            WHERE t.company_id = ? AND ti.status = ?
        ");
        $uiSt->execute([$uiCid, $uiQueue]);
        $uiBadges[$uiHasTires ? 'tires' : 'assets'] = (int)$uiSt->fetchColumn();

        if (function_exists('hasLibraryImagesTable') && hasLibraryImagesTable($pdo)) {
            $uiSt = $pdo->prepare("SELECT COUNT(*) FROM library_images WHERE company_id = ? AND status = ?");
            $uiSt->execute([$uiCid, $uiQueue]);
            $uiBadges['assets'] += (int)$uiSt->fetchColumn();
        }
    } catch (Throwable $uiErr) {
        error_log('tabbar badge query failed: ' . $uiErr->getMessage());
        $uiBadges = ['assets' => 0, 'tires' => 0, 'posts' => 0, 'emails' => 0, 'pages' => 0];
    }
    if ($uiMergeMail) { $uiBadges['emails'] += $uiBadges['pages']; }
}
// Client badges also count items with Joust replies this contact has not read yet (thread_seen, tracking-lib.php) —
// an item already counted as To Review is not counted again. The <a> carries data-badge-review (the To Review part) and
// data-badge-replies (the unread items' keys) so App.tabBadge / App.tabBadgeSeen (app.js) keep the number right after a
// decision or once tracking.js marks an item seen.
$uiReview = $uiBadges; $uiReplies = [];
if (!$uiIsAdmin && !empty($client['id']) && isset($pdo) && $pdo instanceof PDO && function_exists('trackingClientTabReplies')) {
    try { $uiReplies = trackingClientTabReplies($pdo, $client, $uiHasTires); } catch (Throwable $uiErr) { $uiReplies = []; }
    foreach ($uiReplies as $uiKey => $uiKeys) { if (isset($uiBadges[$uiKey])) $uiBadges[$uiKey] += count($uiKeys); }
}
// Admin Home badge = conversations waiting on Joust across every client (tracking-lib.php) — the Joust Inbox lives
// under Home (Home → Joust Inbox), so the bar keeps its ≤ UI_TABS_MAX_ADMIN tabs.
if ($uiIsAdmin && isset($pdo) && $pdo instanceof PDO && function_exists('trackingWaitingCount')) {
    $uiBadges['home'] = trackingWaitingCount($pdo);
}

$uiBrandName = !empty($client['name']) ? $client['name'] : 'Joust Media';
$uiBrandHref = clientUrl('index.php');
?>
<nav class="ui-tabbar ui-glass ui-glass--top" aria-label="Main navigation">
  <?php if ($uiIsAdmin && function_exists('joustAvatar')): // the admin's brand is Joust; inside a client's scope that client is the context line ?>
  <a class="ui-tabbar-brand ui-tabbar-brand--joust" href="<?= esc($uiBrandHref) ?>" data-brand="joust">
    <?= joustAvatar('ui-avatar--brand', '') ?>
    <span class="ui-tabbar-brand-text">
      <span class="ui-tabbar-brand-name">Joust Media</span>
      <?php if (!empty($client['name'])): ?>
        <span class="ui-tabbar-brand-sub" data-brand-client="<?= esc((string)($client['slug'] ?? '')) ?>"><?= clientAvatar($client, 'ui-avatar--xs') ?><span><?= esc($client['name']) ?></span></span>
      <?php else: ?>
        <span class="ui-tabbar-brand-sub">All clients</span>
      <?php endif; ?>
    </span>
  </a>
  <?php else: ?>
  <a class="ui-tabbar-brand" href="<?= esc($uiBrandHref) ?>">
    <?= function_exists('clientAvatar') ? clientAvatar($client, 'ui-avatar--sm') : '' ?>
    <span><?= esc($uiBrandName) ?></span>
  </a>
  <?php endif; ?>
  <ul class="ui-tabbar-list">
    <?php foreach ($uiTabs as $uiKey => $uiTab):
      if (!empty($uiTab['admin']) && !$uiIsAdmin) continue;   // admin-only tab: not rendered for clients
      if (!empty($uiTab['client']) && $uiIsAdmin) continue;   // client-only tab (Projects): the admin has it under Manage
      if (!empty($uiTab['module']) && empty($uiModules[$uiTab['module']])) continue; // module-gated tab (Tires / Emails): company has none
      $uiIsActive = ($uiKey === $uiActive);
      $uiCount    = $uiBadges[$uiKey] ?? 0;
      $uiCls      = 'ui-tab ui-tab--' . $uiKey . ($uiIsActive ? ' is-active' : '');
      $uiNRep     = count($uiReplies[$uiKey] ?? []);
      $uiNRev     = (int)($uiReview[$uiKey] ?? 0);
      $uiAria     = $uiKey === 'home' ? $uiCount . ' waiting on Joust'
                  : ($uiIsAdmin ? $uiCount . $uiBadgeLabel : (function_exists('trackingTabBadgeLabel') ? trackingTabBadgeLabel($uiNRev, $uiNRep) : $uiCount . $uiBadgeLabel));
      $uiData     = (!$uiIsAdmin && isset($uiReview[$uiKey])) ? ' data-badge-review="' . $uiNRev . '" data-badge-replies="' . esc(implode(' ', $uiReplies[$uiKey] ?? [])) . '"' : '';
    ?>
      <li>
        <a class="<?= esc($uiCls) ?>" href="<?= esc(clientUrl($uiTab['page'], $uiTab['query'] ?? [])) ?>"<?= $uiIsActive ? ' aria-current="page"' : '' ?> data-tab="<?= esc($uiKey) ?>"<?= $uiData ?>>
          <?= icon($uiTab['icon']) ?>
          <?php if (!empty($uiTab['short'])): // merged tab: full label in the sidebar, the short one in the phone bar ?>
            <span class="ui-tab-label ui-tab-label--long"><?= esc($uiTab['label']) ?></span><span class="ui-tab-label ui-tab-label--short"><?= esc($uiTab['short']) ?></span>
          <?php else: ?>
            <span class="ui-tab-label"><?= esc($uiTab['label']) ?></span>
          <?php endif; ?>
          <?php if ($uiCount > 0): ?>
            <span class="ui-badge ui-tab-badge" aria-label="<?= esc($uiAria) ?>" data-queue="<?= esc($uiKey === 'home' ? 'inbox' : $uiQueue) ?>"><?= $uiCount > 99 ? '99+' : (int)$uiCount ?></span>
          <?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($uiIsAdmin): ?>
    <div class="ui-tabbar-footer">Signed in as Joust · <a href="<?= esc(pagePath('logout')) ?>">Sign out</a></div>
  <?php elseif (function_exists('currentClientSession') && ($uiSess = currentClientSession())): ?>
    <?php if (function_exists('clientEmailReady') && isset($pdo) && clientEmailReady($pdo)): ?><div class="ui-tabbar-footer ui-tabbar-footer--links"><a href="<?= esc(notifyMachineUrl('email-prefs')) ?>" data-email-settings>Email settings</a></div><?php endif; ?>
    <div class="ui-tabbar-footer" data-client-signout>Signed in as <?= esc($uiSess['email']) ?> · <a href="<?= esc(pagePath('sign-out')) ?>">Sign out</a></div>
  <?php endif; ?>
</nav>
<?php unset($uiSess, $uiMergeMail, $uiCountTabs, $uiTabs, $uiTiresLabel, $uiIsAdmin, $uiHasEmails, $uiHasTires, $uiHasPages, $uiModules, $uiActive, $uiScript, $uiKey, $uiTab, $uiBadges, $uiQueue, $uiBadgeLabel, $uiCid, $uiSt, $uiErr, $uiBrandName, $uiBrandHref, $uiIsActive, $uiCount, $uiCls, $uiReview, $uiReplies, $uiKeys, $uiNRep, $uiNRev, $uiAria, $uiData); ?>
