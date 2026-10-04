<?php
/**
 * My notifications (admin) — the signed-in person's own switches (notify-lib.php adminPrefKinds(); admin_users.notify_prefs,
 * migrate.php 51; defaults in adminUserPrefs()):
 *   summary  the Morning summary — every active teammate with it on gets their own, scoped to the clients they own
 *            (else every client); notify_to (Lance) always gets every client (notifyMemberSummaries())
 *   weekly   the Monday weekly report, scoped the same way
 *   dm       Slack DM reminders when a client of yours waits past the Slack reminder time
 *   email    reminder (escalation) emails when a client of yours waits past the email reminder time
 * Lance's own record: everything on unless he turns it off. A teammate: Morning summary + weekly OFF until turned on.
 * There is one admin login, so Lance sets a teammate's switches in Manage → Notifications → Team (notify-admin.php
 * action=user, pref_*); this page edits only the signed-in person's row.
 * "Yours" = the clients you own (Manage → Notifications → Slack channel per client → @ owner; unowned clients go to the
 * first active teammate). The Inbox's "Mine" filter shows the same clients. Saves through notify-admin.php action=my_prefs
 * (static/js/notifications.js). Signed-in admin only; every teammate sees and changes only their own row.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$ready = notifyReady($pdo) && adminPrefsReady($pdo);
$meId = currentAdminUserId($pdo);
$me = $meId ? adminUserById($pdo, $meId) : null;
$prefs = adminUserPrefs($me);
$owned = [];
if ($ready && $me) {
    foreach ($pdo->query("SELECT id, name, slug, logo_url FROM companies ORDER BY name") as $c) {
        $o = notifyOwnerFor($pdo, (int)$c['id']);
        if ($o && (int)$o['id'] === (int)$me['id']) $owned[] = $c;
    }
}

$pageTitle   = 'My notifications';
$navSubtitle = $me ? (string)$me['name'] : 'Joust';
$htmlTitle   = 'My notifications';
$activeTab   = 'home';
$navTrailing = joustAvatar();
$bodyClass   = 'page-my-notifications';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/notify.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">' . "\n"
             . '<script src="' . h(staticUrl('js/notifications.js')) . '" defer></script>';
include __DIR__ . '/partials/layout-top.php';
?>
<div class="nf nf-me" data-notify data-endpoint="<?= h(basePath() . '/notify-admin.php') ?>" data-my-notifications="<?= $me ? (int)$me['id'] : 0 ?>">
<?php if (!$ready): ?>
  <div class="studio-alert studio-alert--error" role="alert">This needs the database update: open <a href="<?= h(basePath() . '/migrate.php') ?>">migrate.php</a> once (step 51).</div>
<?php elseif (!$me): ?>
  <div class="studio-alert studio-alert--error" role="alert">Your sign-in is not on the Team list yet — add yourself in <a href="<?= h(manageUrl('notifications')) ?>">Manage → Notifications → Team</a>.</div>
<?php else: ?>
  <section class="ui-card nf-card" data-my-prefs>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">What reaches you, <?= h(adminUserFirstName($me)) ?></h3>
      <p class="ui-card-subtitle">Client comments always post to each client’s Slack channel. These switches are only about what comes to you personally.</p></div></div>
    <div class="ui-card-body">
      <form class="nf-form" data-notify-form="my_prefs">
        <ul class="nf-kinds" role="list">
          <?php foreach (adminPrefKinds() as $k => [$label, $help]): ?>
            <li class="nf-kind" data-my-pref="<?= h($k) ?>">
              <div class="nf-kind-body"><div class="nf-check-label"><?= h($label) ?></div><div class="nf-check-state"><?= h($help) ?></div></div>
              <div class="nf-kind-actions"><label class="studio-export-choice"><input type="checkbox" name="<?= h($k) ?>" value="1" id="myPref-<?= h($k) ?>"<?= $prefs[$k] ? ' checked' : '' ?>> <span><?= $prefs[$k] ? 'On' : 'Off' ?></span></label></div>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (trim((string)$me['slack_user_id']) === ''): ?><p class="studio-help" data-my-no-slack>Slack DMs need your Slack user ID on the Team list (Manage → Notifications → Team → Find in Slack by email).</p><?php endif; ?>
        <div class="studio-export-actions"><button type="submit" class="ui-btn ui-btn--filled">Save</button></div>
      </form>
    </div>
  </section>
  <?= insetListOpen('Your clients', ['attrs' => ['data-my-clients' => (string)count($owned)], 'class' => 'nf-list']) ?>
    <?php if (!$owned): ?><li><div class="ui-empty">No clients are yours yet — pick an owner per client in Manage → Notifications.</div></li><?php endif; ?>
    <?php foreach ($owned as $c): ?>
      <?= insetRow(['href' => portalUrl('inbox', ['client' => $c['slug']]), 'leading' => clientAvatar($c), 'title' => $c['name'], 'subtitle' => 'Open its Inbox', 'attrs' => ['data-my-client' => $c['slug']]]) ?>
    <?php endforeach; ?>
  <?= insetListClose('Reminders about these clients come to you. The Inbox’s “Mine” filter shows just them.') ?>
  <p class="studio-help"><a href="<?= h(portalUrl('inbox', ['mine' => 1])) ?>" data-my-inbox-link>Open my Inbox</a> · <a href="<?= h(manageUrl('notifications')) ?>">Manage → Notifications</a></p>
<?php endif; ?>
</div>
<?php include __DIR__ . '/partials/layout-bottom.php';
