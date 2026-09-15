<?php
/**
 * Flows — a client's emails as named, ordered sequences ("Free" = F1 → F4 → F3 …), each
 * step a card on a vertical timeline with the timing on the connector between cards.
 * Admin edits (drag / up-down reorder, add, remove, timing overrides, rename, delete);
 * clients view. Backend: flows-lib.php (loaded by emails-lib.php) + flow-status.php — scratchpad
 * flows-design.md. Step positions are 0-based server-side; cards show 1-based numbers.
 *
 *   ?client=privacybee                scope (helpers.php)
 *   &flow=<slug>                      which flow (default: the first by sort_order)
 *   &email=<id>                       open that email's detail sheet on load (emails.js)
 *   &edit=1                           admin: start in edit mode
 *
 * Clients never see draft / Needs-changes steps (emailFlowSteps(..., ['visibleTo' => 'client'])).
 * The detail sheet is the very same partial as emails.php (renderEmailDetail); further
 * emails are fetched from emails.php?…&email=ID&partial=1 by emails.js.
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/partials/components/comment-thread.php';
require_once __DIR__ . '/partials/components/email-detail.php';
require_once __DIR__ . '/partials/components/flow-card.php';

/** Escape helper (page-local by convention; partials use esc()). */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$admin      = isAdmin();
$emailParam = (int)($_GET['email'] ?? 0);
$flowParam  = isset($_GET['flow']) && is_string($_GET['flow']) ? strtolower(trim($_GET['flow'])) : '';
$flowParam  = preg_replace('/[^a-z0-9\-]/', '', $flowParam);
$wantEdit   = $admin && !empty($_GET['edit']);
$hasTable   = hasEmailsTable($pdo);
$hasFlows   = $hasTable && function_exists('hasEmailFlowsTable') && hasEmailFlowsTable($pdo);
$flowsUrl   = static function (array $params = []) { return clientUrl('flows.php', $params); };

// ---------------------------------------------------------------------
// No client scope: client seat → 400, admin → chooser.
// ---------------------------------------------------------------------
if (!$client) {
    if (!$admin) {
        http_response_code(400);
        $pageTitle    = 'Flows';
        $navTrailing  = '';
        $showTabs     = false;
        $includeSheet = false;
        include __DIR__ . '/partials/layout-top.php';
        echo '<div class="ui-empty">This link is missing its client. Please use the review link Joust sent you.</div>';
        include __DIR__ . '/partials/layout-bottom.php';
        exit;
    }
    $companies = [];
    if ($hasFlows) {
        foreach ($pdo->query("SELECT id, name, slug, logo_url FROM companies ORDER BY name ASC")->fetchAll() as $c) {
            if (!companyHasEmails($c, $pdo)) continue;
            $c['flows'] = emailFlowsForCompany($pdo, (int)$c['id']);
            $companies[] = $c;
        }
    }
    $pageTitle   = 'Flows';
    $navSubtitle = 'Choose a client';
    $activeTab   = 'emails';
    $navTrailing = '';
    $headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
                 . '<link rel="stylesheet" href="' . h(staticUrl('css/emails.css')) . '">' . "\n"
                 . '<link rel="stylesheet" href="' . h(staticUrl('css/flows.css')) . '">';
    $bodyClass   = 'page-emails page-flows page-flows-chooser';
    include __DIR__ . '/partials/layout-top.php';
    if (!$hasFlows) {
        echo '<div class="ui-empty" data-flows-empty="setup">Flows are not set up yet — run <code>migrate.php</code> first.</div>';
    } elseif (!$companies) {
        echo '<div class="ui-empty">No client has the Emails module yet. Enable it in Studio or add the first email.</div>';
    } else {
        echo insetListOpen('Clients');
        foreach ($companies as $c) {
            $n = count($c['flows']);
            echo insetRow([
                'href'     => clientUrl('flows.php', ['client' => $c['slug']]),
                'leading'  => clientAvatar($c, 'ui-avatar--lg'),
                'title'    => $c['name'],
                'subtitle' => $n > 0 ? $n . ' flow' . ($n === 1 ? '' : 's') . ' · ' . implode(', ', array_slice(array_map(static function ($f) { return $f['name']; }, $c['flows']), 0, 4)) : 'No flows yet',
                'chevron'  => true,
                'attrs'    => ['data-client-row' => $c['slug']],
            ]);
        }
        echo insetListClose('Flows are the ordered sequences a client\'s emails are sent in.');
    }
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

$cid       = (int)$client['id'];
$visibleTo = $admin ? 'admin' : 'client';

// ---------------------------------------------------------------------
// Flows + the current one (slug → row, default first)
// ---------------------------------------------------------------------
$flows = $hasFlows ? emailFlowsForCompany($pdo, $cid) : [];
$flow  = null;
if ($flows) {
    if ($flowParam !== '') {
        foreach ($flows as $f) { if ((string)$f['slug'] === $flowParam) { $flow = $f; break; } }
    }
    if (!$flow) $flow = $flows[0];
}
$flowId = $flow ? (int)$flow['id'] : 0;
// Switcher chip counts: admins see every step (step_count); clients see only live / pending / approved
// steps, so their chips use the visible counts (same rule as the timeline below) for EVERY flow.
$chipCounts = [];
if ($flows && $visibleTo === 'client' && function_exists('emailFlowVisibleStepCounts')) {
    try { $chipCounts = emailFlowVisibleStepCounts($pdo, $cid); } catch (Throwable $e) { error_log('flows visible counts failed: ' . $e->getMessage()); $chipCounts = []; }
}
$steps  = $flow ? emailFlowSteps($pdo, $flowId, ['visibleTo' => $visibleTo]) : [];
$steps  = array_values(array_filter($steps, static function ($s) { return is_array($s['email'] ?? null); }));
$total  = count($steps);
$flowUrl = static function (array $f, array $params = []) use ($flowsUrl) {
    if (function_exists('emailFlowUrl')) return emailFlowUrl($f, $params);
    return $flowsUrl(['flow' => (string)($f['slug'] ?? '')] + $params);
};

// ---------------------------------------------------------------------
// Deep link (?email=ID): the row must belong to this client and be visible to the viewer.
// Its detail is inlined as a <template>; every other card fetches emails.php's partial.
// ---------------------------------------------------------------------
$directEmail = null;
if ($emailParam > 0 && $hasTable) {
    $row = emailById($pdo, $emailParam);
    $visible = $row && (int)$row['company_id'] === $cid
             && ($admin || !empty($row['live']) || in_array((string)$row['status'], ['pending', 'approved'], true));
    if ($visible) {
        $row['comments'] = [];
        try { if (hasActivityLog($pdo)) $row['comments'] = commentThread($pdo, 'email', (int)$row['id']); } catch (Throwable $e) { error_log('flows comments query failed: ' . $e->getMessage()); }
        $directEmail = $row;
    }
}

// ---------------------------------------------------------------------
// Admin picker: every email of the client grouped by series (ids already in the flow are hidden by flows.js)
// ---------------------------------------------------------------------
$pickerGroups = [];
if ($admin && $hasFlows) {
    $prefixOf = static function (string $code): string {
        if (function_exists('emailSeriesPrefix')) return (string)emailSeriesPrefix($code);
        return preg_match('/^([A-Za-z]+)/', trim($code), $m) ? strtoupper($m[1]) : '';
    };
    $titleOf = static function (string $prefix): string {
        if ($prefix === '') return 'Other';
        if (function_exists('emailSeriesTitle')) return (string)emailSeriesTitle($prefix);
        return $prefix;
    };
    foreach (emailsForCompany($pdo, $cid) as $e) {
        $p = $prefixOf((string)$e['code']);
        $pickerGroups[$p]['title'] = $titleOf($p);
        $pickerGroups[$p]['emails'][] = $e;
    }
}

// ---------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------
$pageTitle   = $flow ? (string)$flow['name'] : 'Flows';
$navSubtitle = $flow ? 'Email flow · ' . $total . ' step' . ($total === 1 ? '' : 's') : 'Email flows';
$activeTab   = 'emails';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/emails.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/flows.css')) . '">';
$bodyClass   = 'page-emails page-flows' . ($wantEdit ? ' is-editing' : '');

$navTrailing = '<a class="ui-btn ui-btn--gray ui-btn--sm" href="' . h(emailsUrl(['status' => 'all'])) . '" data-flows-list-link>List</a>';
if ($admin && $flow) {
    $navTrailing .= '<button type="button" class="ui-btn ui-btn--sm ' . ($wantEdit ? 'ui-btn--filled' : 'ui-btn--tinted') . '" data-flow-edit-toggle aria-pressed="' . ($wantEdit ? 'true' : 'false') . '">' . ($wantEdit ? 'Done' : 'Edit') . '</button>'
                 . '<div class="fl-more pd-more">'
                 . '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm ui-btn--icon" data-flow-menu aria-haspopup="menu" aria-expanded="false" aria-label="Flow options">' . icon('ellipsis') . '</button>'
                 . '<div class="pd-menu fl-menu" role="menu" data-flow-menu-list hidden>'
                 . '<button type="button" role="menuitem" data-flow-rename>Rename flow…</button>'
                 . '<button type="button" role="menuitem" data-flow-reorder-flows' . (count($flows) < 2 ? ' disabled' : '') . '>Reorder flows…</button>'
                 . '<button type="button" role="menuitem" class="is-destructive" data-flow-delete>Delete flow</button>'
                 . '</div></div>';
}

$emailsConfig = [
    'base'        => basePath(),
    'endpoint'    => basePath() . '/email-status.php',
    'partialUrl'  => emailsUrl(['status' => 'all', 'email' => '__ID__', 'partial' => 1]),
    'segment'     => 'all',
    'counts'      => [],
    'inline'      => false,
    'admin'       => $admin,
    'openEmail'   => $directEmail ? $emailParam : 0,
    'queue'       => false,
    'segmentUrls' => [],
];
$flowsConfig = [
    'endpoint'  => basePath() . '/flow-status.php',
    'flowsUrl'  => $flowsUrl(),
    'flowId'    => $flowId,
    'flowSlug'  => $flow ? (string)$flow['slug'] : '',
    'flowName'  => $flow ? (string)$flow['name'] : '',
    'flowDesc'  => $flow ? (string)($flow['description'] ?? '') : '',
    'flows'     => array_map(static function ($f) use ($flowUrl) { return ['id' => (int)$f['id'], 'name' => (string)$f['name'], 'slug' => (string)$f['slug'], 'url' => $flowUrl($f)]; }, $flows),
    'admin'     => $admin,
    'editing'   => $wantEdit,
    'total'     => $total,
];
$footExtra = '<script>window.EmailsConfig = ' . json_encode($emailsConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
           . 'window.FlowsConfig = ' . json_encode($flowsConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
           . '<script src="' . h(staticUrl('js/emails.js')) . '" defer></script>' . "\n"
           . '<script src="' . h(staticUrl('js/flows.js')) . '" defer></script>';

include __DIR__ . '/partials/layout-top.php';
?>

<div class="fl-page<?= $wantEdit ? ' is-editing' : '' ?>" data-flow-page data-flow-id="<?= $flowId ?>" data-flow-slug="<?= h($flow ? $flow['slug'] : '') ?>">

<?php if (!$hasFlows): ?>
  <div class="ui-empty posts-empty" data-flows-empty="setup">
    Flows are not set up yet<?= $admin ? ' — run <code>migrate.php</code> first.' : '.' ?>
  </div>
<?php elseif (!$flows): ?>
  <div class="ui-empty posts-empty" data-flows-empty="none">
    <?php if ($admin): ?>
      <div class="fl-empty-title">Create your first flow</div>
      <div class="posts-empty-sub">A flow is the order <?= h($client['name']) ?>'s emails go out in — Free, Renewal, Win-back…</div>
      <div class="fl-empty-actions">
        <button type="button" class="ui-btn ui-btn--filled" data-flow-new><?= icon('plus') ?><span>New flow</span></button>
        <button type="button" class="ui-btn ui-btn--gray" data-flow-seed title="One flow per code series (F, R, S…) in the series order">Suggest flows from series</button>
      </div>
    <?php else: ?>
      No flows yet.
      <div class="posts-empty-sub">Joust will lay out the email sequences here.</div>
    <?php endif; ?>
  </div>
<?php else: ?>

  <div class="fl-flows" role="group" aria-label="Flows" data-flow-switcher>
    <?php foreach ($flows as $f):
        $on = (int)$f['id'] === $flowId;
        $n  = $on ? $total : (int)($chipCounts[(int)$f['id']] ?? $f['step_count'] ?? 0); ?>
      <a class="em-chip fl-flow-chip<?= $on ? ' is-active' : '' ?>" href="<?= h($flowUrl($f)) ?>" data-flow-chip="<?= h($f['slug']) ?>" aria-current="<?= $on ? 'page' : 'false' ?>"><span data-flow-chip-name><?= h($f['name']) ?></span> <span class="fl-chip-n" data-flow-count="<?= (int)$f['id'] ?>"><?= $n ?></span></a>
    <?php endforeach; ?>
    <?php if ($admin): ?>
      <button type="button" class="em-chip em-chip--clear fl-flow-new" data-flow-new><?= icon('plus') ?>New flow</button>
    <?php endif; ?>
  </div>

  <?php if (trim((string)($flow['description'] ?? '')) !== ''): ?>
    <p class="fl-desc" data-flow-desc><?= h($flow['description']) ?></p>
  <?php else: ?>
    <p class="fl-desc" data-flow-desc hidden></p>
  <?php endif; ?>

  <div class="fl-toolbar">
    <nav class="fl-map" aria-label="Sequence overview" data-flow-map>
      <?php foreach ($steps as $i => $s): $e = $s['email']; $k = emailStatusKey($e); ?>
        <?php if ($i > 0): ?><span class="fl-map-arrow" aria-hidden="true">→</span><?php endif; ?>
        <a class="fl-map-chip el-code-tile--<?= h($k) ?>" href="#flow-step-<?= (int)$e['id'] ?>" data-flow-map-chip="<?= (int)$e['id'] ?>" title="<?= h('Step ' . ($i + 1) . ' · ' . trim((string)$e['title'])) ?>"><?= h(trim((string)$e['code']) !== '' ? $e['code'] : '—') ?></a>
      <?php endforeach; ?>
    </nav>
    <button type="button" class="ui-btn ui-btn--gray ui-btn--sm fl-compact-toggle" data-flow-compact aria-pressed="false" title="Hide subject and preview text so the whole flow fits on screen">Compact</button>
  </div>

  <div class="ui-empty posts-empty fl-empty" data-flow-empty<?= $total ? ' hidden' : '' ?>>
    <?php if ($admin): ?>
      <div class="fl-empty-title">This flow is empty</div>
      <div class="posts-empty-sub">Add the first email to start the sequence.</div>
      <div class="fl-empty-actions"><button type="button" class="ui-btn ui-btn--filled" data-flow-add><?= icon('plus') ?><span>Add the first email</span></button></div>
    <?php else: ?>
      No emails in this flow yet.
    <?php endif; ?>
  </div>

  <ol class="fl-timeline" data-flow-steps role="list" aria-label="<?= h($flow['name']) ?> sequence">
    <?php foreach ($steps as $i => $s): ?>
      <?= renderFlowCard($s, ['admin' => $admin, 'editing' => $wantEdit, 'index' => $i, 'total' => $total, 'client' => $clientSlug]) ?>
    <?php endforeach; ?>
  </ol>

  <div class="fl-end" data-flow-end<?= $total ? '' : ' hidden' ?>>
    <span class="fl-line" aria-hidden="true"></span>
    <span class="fl-end-dot" aria-hidden="true"></span>
    <?php if ($admin): ?>
      <button type="button" class="ui-btn ui-btn--tinted ui-btn--sm fl-add-end" data-flow-add><?= icon('plus') ?><span>Add email</span></button>
    <?php endif; ?>
    <span class="fl-end-label">End of flow</span>
  </div>

<?php endif; ?>
</div>

<?php if ($admin && $hasFlows): ?>
  <template data-flow-picker>
    <div class="fl-picker" data-flow-picker-root>
      <label class="ui-visually-hidden" for="fl-pick-q">Search emails</label>
      <input class="ui-input fl-picker-search" type="search" id="fl-pick-q" placeholder="Search code, title or subject" autocomplete="off" data-flow-pick-search data-sheet-autofocus>
      <?php if (!$pickerGroups): ?>
        <div class="ui-empty" data-flow-pick-empty>No emails yet — add one in Studio first.</div>
      <?php else: ?>
        <div class="ui-empty" data-flow-pick-empty hidden>Every email is already in this flow.</div>
        <div class="ui-empty" data-flow-pick-nomatch hidden>No email matches.</div>
        <?php foreach ($pickerGroups as $g): ?>
          <section class="ui-list-group fl-pick-group" data-flow-pick-group>
            <h3 class="ui-list-header"><?= h($g['title']) ?></h3>
            <ul class="ui-list" role="list">
              <?php foreach ($g['emails'] as $e):
                  $eid = (int)$e['id']; $k = emailStatusKey($e);
                  $search = strtolower(trim($e['code'] . ' ' . $e['title'] . ' ' . $e['subject'])); ?>
                <li data-flow-pick-item="<?= $eid ?>" data-search="<?= h($search) ?>">
                  <button type="button" class="ui-row ui-row--leading-sm fl-pick-row" data-flow-pick="<?= $eid ?>">
                    <span class="ui-row-leading el-code-tile el-code-tile--<?= h($k) ?> fl-pick-tile" aria-hidden="true"><span class="el-code"><?= h(trim((string)$e['code']) !== '' ? $e['code'] : '—') ?></span></span>
                    <span class="ui-row-body">
                      <span class="ui-row-title"><?= h(trim((string)$e['title']) !== '' ? $e['title'] : $e['code']) ?></span>
                      <span class="ui-row-subtitle"><?= h(trim((string)$e['subject']) !== '' ? $e['subject'] : 'No subject yet') ?></span>
                    </span>
                    <span class="ui-row-trailing"><?= emailStatusPill($e) ?></span>
                  </button>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </template>

  <template data-flow-form-template>
    <form class="fl-form pd-editor" data-flow-form="">
      <label class="pd-editor-label" for="fl-form-name">Name</label>
      <input class="ui-input" type="text" id="fl-form-name" name="name" maxlength="80" required placeholder="e.g. Free onboarding" data-sheet-autofocus>
      <label class="pd-editor-label" for="fl-form-desc">Description <span class="text-tertiary">(optional)</span></label>
      <textarea class="ui-textarea" id="fl-form-desc" name="description" maxlength="500" rows="2" placeholder="What this sequence is for"></textarea>
      <div class="ui-btn-group">
        <button type="button" class="ui-btn ui-btn--gray" data-sheet-close>Cancel</button>
        <button type="submit" class="ui-btn ui-btn--filled ui-btn--primary" data-flow-form-submit>Save</button>
      </div>
    </form>
  </template>
<?php endif; ?>

<?php if ($directEmail): ?>
  <template data-email-template="<?= (int)$directEmail['id'] ?>"><?= renderEmailDetail($directEmail, ['admin' => $admin]) ?></template>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
