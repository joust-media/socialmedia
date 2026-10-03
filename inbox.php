<?php
/**
 * Joust Inbox (admin) — every conversation across clients, by whose turn it is (tracking-lib.php):
 *
 *   ?tab=joust      (default) Waiting on Joust: the client's message is the last word, or the item is in Needs
 *                   changes — oldest wait first, with the age (orange after 4 h, red after 24 h), client, thumbnail,
 *                   the last client message, a deep link, an unread dot, and Resolve.
 *   ?tab=client     Waiting on client: To Review items nobody at the client has answered yet, oldest first.
 *   ?tab=resolved   Resolved recently: client messages answered in the last 14 days — by whom, how fast.
 *   &client=<slug>  one client only (the scoped Home links here).
 * Reached from Home (the "Joust Inbox" row) and the Home tab's badge (= Waiting on Joust across clients) — the admin
 * bar keeps its ≤ 6 tabs (partials/tabbar.php).
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$tab = (string)($_GET['tab'] ?? 'joust');
if (!in_array($tab, ['joust', 'client', 'resolved'], true)) $tab = 'joust';
$ready = trackingReady($pdo);
$cid = $client ? (int)$client['id'] : null;
$joust = $ready ? trackingWaitingOnJoust($pdo, $cid) : [];
$theirs = $ready ? trackingWaitingOnClient($pdo, $cid) : [];
$resolved = $ready ? trackingResolvedRecently($pdo, 14, $cid) : [];
$rows = $tab === 'joust' ? $joust : ($tab === 'client' ? $theirs : $resolved);

$companies = [];
foreach ($pdo->query("SELECT id, name, slug, logo_url FROM companies") as $c) $companies[(int)$c['id']] = $c;
$viewer = trackingViewer($pdo);
$unread = [];
if ($viewer) {
    $byType = [];
    foreach ($rows as $r) $byType[$r['entity_type']][] = (int)$r['entity_id'];
    foreach ($byType as $t => $ids) foreach (trackingUnreadIds($pdo, $viewer, $t, $ids) as $i) $unread[$t . ':' . $i] = true;
}
$typeIcon = ['post' => 'grid', 'email' => 'mail', 'page' => 'page', 'tire_image' => 'tire', 'tire_series' => 'tire', 'library_image' => 'photo'];

$pageTitle   = 'Inbox';
$navSubtitle = $client ? $client['name'] : 'All clients';
$htmlTitle   = 'Joust Inbox' . ($client ? ' — ' . $client['name'] : '');
$activeTab   = 'home';
$navTrailing = $client ? clientAvatar($client) : joustAvatar();
$bodyClass   = 'page-inbox';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/notify.css')) . '">';
include __DIR__ . '/partials/layout-top.php';

$segUrl = static function (string $t) use ($client): string { return portalUrl('inbox', array_filter(['client' => $client['slug'] ?? null, 'tab' => $t === 'joust' ? null : $t])); };
echo '<div class="ibx-head">' . segmented([
    ['label' => 'Waiting on Joust', 'href' => $segUrl('joust'), 'active' => $tab === 'joust', 'count' => count($joust), 'attrs' => ['data-inbox-tab' => 'joust']],
    ['label' => 'Waiting on client', 'href' => $segUrl('client'), 'active' => $tab === 'client', 'count' => count($theirs), 'attrs' => ['data-inbox-tab' => 'client']],
    ['label' => 'Resolved', 'href' => $segUrl('resolved'), 'active' => $tab === 'resolved', 'count' => count($resolved), 'attrs' => ['data-inbox-tab' => 'resolved']],
], ['label' => 'Inbox view', 'scroll' => true]) . '</div>';

if (!$ready) {
    echo '<div class="studio-alert studio-alert--error" role="alert">The Inbox needs the database update: open <a href="' . h(basePath() . '/migrate.php') . '">migrate.php</a> once (steps 45–49).</div>';
}
$header = ['joust' => 'Oldest first — the client is waiting on a reply or on changes', 'client' => 'Sent for review, no answer from the client yet',
           'resolved' => 'Answered in the last 14 days'][$tab];
echo insetListOpen($header, ['class' => 'ibx-group', 'attrs' => ['data-inbox' => $tab]]);
if (!$rows) {
    $empty = ['joust' => 'Nothing is waiting on Joust. Nice.', 'client' => 'Nothing is waiting on a client.', 'resolved' => 'Nothing answered in the last 14 days.'][$tab];
    echo '<li><div class="ui-empty ibx-empty" data-inbox-empty>' . h($empty) . '</div></li>';
}
foreach ($rows as $r) {
    $type = (string)$r['entity_type']; $id = (int)$r['entity_id']; $key = $type . ':' . $id;
    $info = notifyItemInfo($pdo, $type, $id);
    if (!$info['exists']) continue;
    $co = $companies[(int)$r['company_id']] ?? ['name' => $info['company_name'], 'slug' => $info['company_slug']];
    $href = function_exists('activityDeepLink')
        ? activityDeepLink(['entity_type' => $type, 'entity_id' => $id, 'company_slug' => $info['company_slug'], '_meta' => $info['meta']]) : '#';
    $thumb = $info['thumb'] !== '' ? pvImg(trackingMediaUrl((string)$info['thumb']), 'sm', ['alt' => '']) : icon($typeIcon[$type] ?? 'bubble');
    $since = $tab === 'resolved' ? (string)$r['opened_at'] : (string)($r['since'] ?? '');
    $ageCls = $tab === 'joust' ? trackingAgeClass($since) : '';
    $snippet = '';
    if ($tab === 'joust') {
        [$slide, $body] = commentSlideSplit((string)$r['last_detail']);
        $snippet = $body !== '' ? ($slide > 0 ? 'Slide ' . $slide . ': ' : '') . '“' . (mb_strlen($body) > 160 ? rtrim(mb_substr($body, 0, 159)) . '…' : $body) . '”'
                                : ($r['reason'] === 'changes' ? 'Needs changes (no note)' : '');
    } elseif ($tab === 'resolved') {
        $u = adminUserById($pdo, (int)($r['closed_by'] ?? 0));
        $how = $r['closed_action'] === 'resolved' ? 'Marked resolved' : ($r['closed_action'] === 'commented' ? 'Replied' : ucfirst(actionLabel((string)$r['closed_action'])));
        $snippet = $how . ($u ? ' by ' . adminUserFirstName($u) : '') . ' after ' . notifyAgeLabel((int)$r['minutes']);
    } else {
        $snippet = 'Sent for review' . ($since !== '' ? ' ' . relativeTime($since) : '');
    }
    $status = $tab === 'joust' && $r['reason'] === 'changes' ? 'Needs changes' : $info['status_label'];
    $ageLabel = $tab === 'resolved' ? relativeTime((string)$r['closed_at']) : ($since !== '' ? trackingAge($since) : '—');
    ?>
    <li class="ibx-row" data-inbox-row="<?= h($key) ?>" data-company="<?= h($co['slug']) ?>"<?= $since !== '' ? ' data-since="' . h($since) . '"' : '' ?>>
      <a class="ui-row ui-row--leading ibx-link" href="<?= h($href) ?>">
        <div class="ui-row-leading ibx-thumb"><?= $thumb ?></div>
        <div class="ui-row-body">
          <div class="ui-row-title ibx-title"><?php if (isset($unread[$key])): ?><span class="ui-unread-dot" data-unread-for="<?= h($key) ?>" role="img" aria-label="New messages"></span><?php endif; ?><?= h($info['title']) ?></div>
          <div class="ui-row-subtitle ibx-meta"><?= h($co['name']) ?> · <?= h($info['type_label']) ?> · <?= h($status) ?><?= $tab === 'joust' && (int)$r['n'] > 1 ? ' · ' . (int)$r['n'] . ' messages' : '' ?></div>
          <?php if ($snippet !== ''): ?><div class="ibx-snippet" data-inbox-snippet><?= h($snippet) ?></div><?php endif; ?>
        </div>
      </a>
      <div class="ibx-trailing">
        <span class="ibx-age<?= $ageCls !== '' ? ' ibx-age--' . h($ageCls) : '' ?>" data-inbox-age title="<?= h($since !== '' ? absoluteTime($since) : '') ?>"><?= h($ageLabel) ?></span>
        <?php if ($tab === 'joust'): ?><button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-thread-resolve="<?= h($key) ?>" data-inbox-resolve>Resolve</button><?php endif; ?>
      </div>
    </li>
    <?php
}
echo insetListClose($tab === 'joust' ? 'Resolve clears an item without a reply (the same as Resolve in Slack). A reply, a decision or sending it for review clears it too.' : '');
include __DIR__ . '/partials/layout-bottom.php';
