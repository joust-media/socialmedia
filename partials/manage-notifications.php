<?php
// Not a page: only meaningful when included from manage.php (helpers.php loaded, admin verified).
if (!function_exists('esc') || !function_exists('isAdmin') || !isAdmin()) { http_response_code(404); exit; }
/**
 * Manage → Notifications (notify-lib.php; actions → notify-admin.php via static/js/notifications.js):
 *   Setup         which config.php keys are set (never their values), PHP curl, migration, last cron run, and the
 *                 URLs to paste into Slack / cPanel; "Send test to Slack" (a DM to me).
 *   Reminders     the escalation thresholds (Slack re-ping + DM, then email) and the Morning summary hour.
 *   Channels      per client: its Slack channel ID (or Find #portal-<slug>), the owner to @mention, a test message.
 *   Team          the named Joust people (name, email, Slack user ID; Find by email).
 *   Delivery log  the outbox (filter by status), Retry one, Retry all failed.
 * Reads from the including scope: $pdo.
 */
$nfReady = notifyReady($pdo);
$nfH = static function ($s) { return esc($s); };
$nfKeys = [
    ['slack_bot_token',      'Slack bot token',          'xoxb-… from the Slack app (OAuth & Permissions)'],
    ['slack_signing_secret', 'Slack signing secret',     'Basic Information → App Credentials'],
    ['notify_cron_token',    'Cron token',               'a random string (16+ characters) for the cPanel cron URL'],
    ['portal_base_url',      'Portal address',           'https://joustmedia.com/portal — used in every link Slack and email show'],
    ['notify_to',            'Morning summary goes to',  'your email address'],
    ['notify_from',          'Emails come from',         'e.g. Joust Portal <portal@joustmedia.com>'],
];
$nfCfgSet = static function (string $k): bool { return notifyCfg($k) !== ''; };
$nfSettings = $nfReady ? notifySettings($pdo) : ['t1' => 60, 't2' => 240, 'summary_hour' => 8];
$nfCronLast = $nfReady ? notifyMeta($pdo, 'notify_cron_last', '') : '';
$nfCronOk = $nfCronLast !== '' && (time() - (int)strtotime($nfCronLast)) < 15 * 60;
$nfUsers = adminUsers($pdo);
$nfMe = currentAdminUserId($pdo);
$nfCompanies = [];
try { $nfCompanies = $pdo->query("SELECT id, name, slug, logo_url FROM companies ORDER BY name ASC")->fetchAll(); } catch (Throwable $e) {}
$nfMap = [];
if ($nfReady) {
    foreach ($pdo->query("SELECT * FROM notify_clients") as $r) $nfMap[(int)$r['company_id']] = $r;
}
$nfFilters = ['all' => 'All', 'pending' => 'Queued', 'failed' => 'Failed', 'sent' => 'Sent', 'skipped' => 'Skipped'];
$nfFilter = (string)($_GET['log'] ?? 'all');
if (!isset($nfFilters[$nfFilter])) $nfFilter = 'all';
$nfCounts = array_fill_keys(array_keys($nfFilters), 0);
$nfLog = [];
if ($nfReady) {
    foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM notify_outbox GROUP BY status") as $r) {
        $k = $r['status'] === 'sending' ? 'pending' : (string)$r['status'];
        if (isset($nfCounts[$k])) $nfCounts[$k] += (int)$r['n'];
        $nfCounts['all'] += (int)$r['n'];
    }
    $where = $nfFilter === 'all' ? '' : ($nfFilter === 'pending' ? "WHERE o.status IN ('pending','sending')" : 'WHERE o.status = ' . $pdo->quote($nfFilter));
    $nfLog = $pdo->query("SELECT o.id, o.channel, o.kind, o.company_id, o.entity_type, o.entity_id, o.target, o.status, o.attempts,
                                 o.next_attempt_at, o.last_error, o.created_at, o.sent_at, c.name AS company_name
                            FROM notify_outbox o LEFT JOIN companies c ON c.id = o.company_id {$where}
                           ORDER BY o.id DESC LIMIT 60")->fetchAll();
}
$nfItemTitle = static function (array $r) use ($pdo): string {
    static $cache = [];
    if (!$r['entity_type']) return '';
    $k = $r['entity_type'] . ':' . (int)$r['entity_id'];
    if (!isset($cache[$k])) $cache[$k] = notifyItemInfo($pdo, (string)$r['entity_type'], (int)$r['entity_id'])['title'];
    return $cache[$k];
};
$nfStatusPill = static function (string $st): string {
    $map = ['sent' => ['approved', 'Sent'], 'pending' => ['pending', 'Queued'], 'sending' => ['pending', 'Sending'],
            'failed' => ['denied', 'Failed'], 'skipped' => ['', 'Skipped']];
    [$cls, $label] = $map[$st] ?? ['', ucfirst($st)];
    return '<span class="ui-pill' . ($cls !== '' ? ' ui-pill--' . $cls : '') . '" data-log-status="' . esc($st) . '">' . esc($label) . '</span>';
};
$nfCheck = static function (bool $ok, string $label, string $help = '', string $okWord = 'Set', string $missWord = 'Not set'): string {
    return '<li class="nf-check' . ($ok ? ' is-ok' : ' is-missing') . '" data-config-check="' . ($ok ? 'set' : 'missing') . '">'
         . '<span class="nf-check-mark" aria-hidden="true">' . ($ok ? icon('checkmark') : '–') . '</span>'
         . '<span class="nf-check-body"><span class="nf-check-label">' . esc($label) . '</span>'
         . '<span class="nf-check-state">' . ($ok ? $okWord : $missWord) . ($help !== '' && !$ok ? ' — ' . esc($help) : '') . '</span></span></li>';
};
$nfEndpoint = basePath() . '/notify-admin.php';
?>
<div class="nf" data-notify data-endpoint="<?= $nfH($nfEndpoint) ?>">
<?php if (!$nfReady): ?>
  <div class="studio-alert studio-alert--error" role="alert">Notifications need the database update: open <a href="<?= $nfH(basePath() . '/migrate.php') ?>">migrate.php</a> once (steps 36–39).</div>
<?php else: ?>

  <!-- Setup ------------------------------------------------------------------ -->
  <section class="ui-card nf-card" data-notify-setup>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Setup</h3>
      <p class="ui-card-subtitle">Secrets live only in <code>config.php</code> on the server — this page shows whether each one is set, never its value.</p></div></div>
    <div class="ui-card-body">
      <ul class="nf-checks" role="list">
        <?php foreach ($nfKeys as [$k, $label, $help]) echo $nfCheck($nfCfgSet($k), $label, $help); ?>
        <?= $nfCheck(function_exists('curl_init'), 'PHP curl', 'ask the host to enable the curl extension', 'Available', 'Missing') ?>
        <?= $nfCheck($nfCronOk, 'Cron', $nfCronLast !== '' ? 'last run ' . relativeTime($nfCronLast) . ', expected every 5 minutes' : 'add the cPanel cron below',
                     'Ran ' . ($nfCronLast !== '' ? relativeTime($nfCronLast) : ''), $nfCronLast !== '' ? 'Late' : 'Never ran') ?>
      </ul>
      <dl class="nf-urls">
        <dt>Slack Events URL</dt><dd><code data-url="events"><?= $nfH(notifyMachineUrl('slack-events')) ?></code></dd>
        <dt>Slack Interactivity URL</dt><dd><code data-url="actions"><?= $nfH(notifyMachineUrl('slack-actions')) ?></code></dd>
        <dt>cPanel cron (every 5 minutes)</dt><dd><code data-url="cron">curl -fsS -H "X-Notify-Token: &lt;notify_cron_token&gt;" "<?= $nfH(notifyMachineUrl('notify-cron')) ?>" &gt;/dev/null 2&gt;&amp;1</code></dd>
      </dl>
      <div class="studio-export-actions">
        <button type="button" class="ui-btn ui-btn--tinted" data-notify-action="test" data-company="0"<?= $nfCfgSet('slack_bot_token') ? '' : ' disabled' ?>><?= icon('bell') ?><span>Send test to Slack</span></button>
        <span class="text-secondary nf-hint">A direct message to you (set your Slack user ID under Team).</span>
      </div>
    </div>
  </section>

  <!-- Reminders ---------------------------------------------------------------- -->
  <section class="ui-card nf-card" data-notify-settings>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Reminders</h3>
      <p class="ui-card-subtitle">Every client comment and decision posts to Slack right away (Joust’s own actions don’t). A client message nobody has answered gets a nudge — around the clock.</p></div></div>
    <div class="ui-card-body">
      <form class="nf-form" data-notify-form="settings">
        <div class="studio-field-row">
          <div class="studio-field"><label class="studio-label" for="nfT1">Slack nudge + DM after</label>
            <div class="nf-unit"><input class="ui-input" id="nfT1" name="t1" type="number" min="5" max="10080" step="5" value="<?= (int)$nfSettings['t1'] ?>" required><span>minutes</span></div></div>
          <div class="studio-field"><label class="studio-label" for="nfT2">Email after</label>
            <div class="nf-unit"><input class="ui-input" id="nfT2" name="t2" type="number" min="10" max="10080" step="5" value="<?= (int)$nfSettings['t2'] ?>" required><span>minutes</span></div></div>
          <div class="studio-field"><label class="studio-label" for="nfHour">Morning summary at</label>
            <select class="ui-select" id="nfHour" name="summary_hour">
              <?php for ($i = 0; $i < 24; $i++): ?><option value="<?= $i ?>"<?= $i === (int)$nfSettings['summary_hour'] ? ' selected' : '' ?>><?= date('g A', mktime($i, 0, 0)) ?></option><?php endfor; ?>
            </select></div>
        </div>
        <p class="studio-help">“Answered” means a comment or decision from Joust on that item after the client’s message (an internal note doesn’t count), or Resolve in Slack. Times are New York time.</p>
        <div class="studio-export-actions"><button type="submit" class="ui-btn ui-btn--filled">Save</button></div>
      </form>
    </div>
  </section>

  <!-- Channels ------------------------------------------------------------------ -->
  <?= insetListOpen('Slack channel per client', ['attrs' => ['data-notify-channels' => '1'], 'class' => 'nf-list']) ?>
    <?php foreach ($nfCompanies as $c): $m = $nfMap[(int)$c['id']] ?? null; $ch = (string)($m['slack_channel_id'] ?? ''); ?>
      <li><form class="ui-row ui-row--leading nf-row" data-notify-form="client" data-client-row="<?= $nfH($c['slug']) ?>">
        <div class="ui-row-leading"><?= clientAvatar($c, 'ui-avatar--lg') ?></div>
        <div class="ui-row-body">
          <div class="ui-row-title"><?= $nfH($c['name']) ?></div>
          <div class="ui-row-subtitle" data-channel-state><?= $ch !== '' ? $nfH(trim(($m['slack_channel_name'] ?? '') . ' ' . $ch)) : 'No channel yet — expected <code>#portal-' . $nfH($c['slug']) . '</code>' ?></div>
          <input type="hidden" name="company_id" value="<?= (int)$c['id'] ?>">
          <div class="nf-row-fields">
            <input class="ui-input" name="slack_channel_id" value="<?= $nfH($ch) ?>" placeholder="Channel ID, e.g. C0123ABCD" aria-label="<?= $nfH($c['name']) ?> Slack channel ID" autocomplete="off" spellcheck="false">
            <select class="ui-select" name="owner_user_id" aria-label="Who gets @mentioned">
              <option value="0">@ default (<?= $nfH($nfUsers ? adminUserFirstName(reset($nfUsers)) : 'owner') ?>)</option>
              <?php foreach ($nfUsers as $u): if (empty($u['active'])) continue; ?>
                <option value="<?= (int)$u['id'] ?>"<?= (int)($m['owner_user_id'] ?? 0) === (int)$u['id'] ? ' selected' : '' ?>>@ <?= $nfH($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="nf-row-actions">
            <button type="submit" class="ui-btn ui-btn--gray ui-btn--sm">Save</button>
            <button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-notify-action="find_channel" data-company="<?= (int)$c['id'] ?>">Find #portal-<?= $nfH($c['slug']) ?></button>
            <button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-notify-action="test" data-company="<?= (int)$c['id'] ?>"<?= $ch !== '' ? '' : ' disabled' ?>>Send test</button>
          </div>
        </div>
      </form></li>
    <?php endforeach; ?>
  <?= insetListClose('Create one channel per client (e.g. #portal-kenda), invite the bot, then Find it here or paste its ID. Each portal item gets its own thread there.') ?>

  <!-- Team ------------------------------------------------------------------------ -->
  <?= insetListOpen('Team', ['attrs' => ['data-notify-team' => '1'], 'class' => 'nf-list']) ?>
    <?php foreach ($nfUsers as $u): ?>
      <li><form class="ui-row nf-row" data-notify-form="user" data-user-row="<?= (int)$u['id'] ?>">
        <div class="ui-row-body">
          <div class="ui-row-title"><?= $nfH($u['name']) ?><?= (int)$u['id'] === (int)$nfMe ? ' <span class="text-tertiary">(you)</span>' : '' ?><?= empty($u['active']) ? ' <span class="ui-pill ui-pill--nodot">Inactive</span>' : '' ?></div>
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <div class="nf-row-fields nf-row-fields--3">
            <input class="ui-input" name="name" value="<?= $nfH($u['name']) ?>" aria-label="Name" maxlength="80" required>
            <input class="ui-input" name="email" type="email" value="<?= $nfH($u['email']) ?>" aria-label="Email" required>
            <input class="ui-input" name="slack_user_id" value="<?= $nfH((string)$u['slack_user_id']) ?>" placeholder="Slack ID, e.g. U0123ABCD" aria-label="Slack user ID" autocomplete="off" spellcheck="false">
          </div>
          <div class="nf-row-actions">
            <label class="studio-export-choice"><input type="checkbox" name="active" value="1"<?= !empty($u['active']) ? ' checked' : '' ?>> <span>Active</span></label>
            <button type="submit" class="ui-btn ui-btn--gray ui-btn--sm">Save</button>
            <button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-notify-action="find_user" data-id="<?= (int)$u['id'] ?>">Find in Slack by email</button>
          </div>
        </div>
      </form></li>
    <?php endforeach; ?>
    <li><form class="ui-row nf-row" data-notify-form="user" data-user-row="new">
      <div class="ui-row-body">
        <div class="ui-row-title">Add a teammate</div>
        <input type="hidden" name="id" value="0"><input type="hidden" name="active" value="1">
        <div class="nf-row-fields nf-row-fields--3">
          <input class="ui-input" name="name" placeholder="Name" aria-label="New teammate name" maxlength="80" required>
          <input class="ui-input" name="email" type="email" placeholder="name@joustmedia.com" aria-label="New teammate email" required>
          <input class="ui-input" name="slack_user_id" placeholder="Slack ID (optional)" aria-label="New teammate Slack user ID" autocomplete="off">
        </div>
        <div class="nf-row-actions"><button type="submit" class="ui-btn ui-btn--tinted ui-btn--sm">Add</button></div>
      </div>
    </form></li>
  <?= insetListClose('Names show on comments (“Lance at Joust” for clients). A Slack reply or button press counts as that person; replies starting with !internal stay internal notes.') ?>

  <!-- Delivery log ------------------------------------------------------------------ -->
  <section class="ui-card nf-card nf-log-card" data-notify-log>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Delivery log</h3>
      <p class="ui-card-subtitle">Every Slack message and email the portal sent or tried to send. Failures retry by themselves (1, 5, 15, 60, 180 minutes), then stop here.</p></div></div>
    <div class="ui-card-body">
      <div class="nf-log-head">
        <?php
          $segItems = [];
          foreach ($nfFilters as $k => $label) {
              $segItems[] = ['label' => $label . ' ' . $nfCounts[$k], 'href' => manageUrl('notifications', ['log' => $k === 'all' ? null : $k]) . '#log',
                             'active' => $k === $nfFilter, 'value' => $k, 'attrs' => ['data-log-filter' => $k]];
          }
          echo segmented($segItems, ['label' => 'Delivery status']);
        ?>
        <button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-notify-action="retry_all"<?= $nfCounts['failed'] > 0 ? '' : ' disabled' ?>>Retry all failed</button>
      </div>
      <ul class="nf-log" id="log" role="list">
        <?php if (!$nfLog): ?><li class="nf-log-empty text-secondary" data-log-empty>Nothing here yet.</li><?php endif; ?>
        <?php foreach ($nfLog as $r): $title = $nfItemTitle($r); ?>
          <li class="nf-log-row" data-log-row="<?= (int)$r['id'] ?>" data-log-kind="<?= $nfH($r['kind']) ?>">
            <div class="nf-log-icon" aria-hidden="true"><?= icon($r['channel'] === 'email' ? 'mail' : 'bubble') ?></div>
            <div class="nf-log-body">
              <div class="nf-log-title"><?= $nfH(notifyKindLabel((string)$r['kind'])) ?><?= $r['company_name'] ? ' · ' . $nfH($r['company_name']) : '' ?><?= $title !== '' ? ' · <span class="text-secondary">' . $nfH($title) . '</span>' : '' ?></div>
              <div class="nf-log-meta"><?= $nfH(ucfirst((string)$r['channel'])) ?> · <time title="<?= $nfH(absoluteTime($r['created_at'])) ?>"><?= $nfH(relativeTime($r['created_at'])) ?></time>
                <?= (int)$r['attempts'] > 1 ? ' · ' . (int)$r['attempts'] . ' tries' : '' ?>
                <?= $r['status'] === 'pending' && (int)$r['attempts'] > 0 ? ' · next try ' . $nfH(relativeTime($r['next_attempt_at']) === 'just now' ? 'soon' : date('g:ia', (int)strtotime((string)$r['next_attempt_at']))) : '' ?></div>
              <?php if ((string)$r['last_error'] !== '' && $r['status'] !== 'sent'): ?><div class="nf-log-error" data-log-error><?= $nfH($r['last_error']) ?></div><?php endif; ?>
            </div>
            <div class="nf-log-trailing"><?= $nfStatusPill((string)$r['status']) ?>
              <?php if (in_array($r['status'], ['failed', 'pending', 'skipped'], true)): ?>
                <button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-notify-action="retry" data-id="<?= (int)$r['id'] ?>">Retry</button>
              <?php endif; ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
<?php endif; ?>
</div>
