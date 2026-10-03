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
 *   Email         (#google) the transport in use, Google (Gmail API) connection: Connect / Disconnect, connected as,
 *                 last success / last error, the redirect URI to register, inbound replies (lance+ai@…) status,
 *                 Send test email, Check replies now (gmail-lib.php; google-oauth.php).
 *   Client emails what clients get and when (client-notify-lib.php) + Preview links for every template (email-preview.php).
 *   Unmatched email replies (#unmatched) replies the cron could not place: Assign to item / Dismiss.
 * Reads from the including scope: $pdo.
 */
$nfReady = notifyReady($pdo);
$nfH = static function ($s) { return esc($s); };
$nfKeys = [
    ['slack_bot_token',      'Slack bot token',          'xoxb-… from the Slack app (OAuth & Permissions)'],
    ['slack_signing_secret', 'Slack signing secret',     'Basic Information → App Credentials'],
    ['notify_cron_token',    'Cron token',               'a random string (16+ characters) for the cPanel cron URL'],
    ['portal_url',           'Portal address',           'https://joustmedia.com/portal — used in every link Slack and email show (old name: portal_base_url)'],
    ['notify_to',            'Morning summary goes to',  'your email address'],
    ['notify_from',          'Emails come from',         'lance@joustmedia.com (the default; display name notify_from_name, default Joust Media) — sign-in links too'],
    ['google_client_id',     'Google OAuth client ID',   'Google Cloud → Credentials → your Web client (docs/google-setup.md)'],
    ['google_client_secret', 'Google OAuth client secret', 'shown next to the client ID in Google Cloud'],
    ['google_token_key',     'Google token key',         'make one up: 32+ random characters (encrypts the stored Google token)'],
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
// Email: transport, Google connection, inbound replies, unmatched list (gmail-lib.php)
$nfGReady = $nfReady && function_exists('googleReady') && googleReady($pdo);
$nfGAcc = $nfGReady ? googleAccount($pdo, true) : null;
$nfGConfigured = function_exists('googleConfigured') && googleConfigured();
$nfTransport = function_exists('notifyMailTransport') ? notifyMailTransport() : 'mail';
$nfTransportSet = strtolower(notifyCfg('mail_transport'));
$nfTransportSet = ($nfTransportSet === 'auto') ? '' : $nfTransportSet;
$nfTransportLine = [
    'gmail' => 'Gmail API as ' . ($nfGAcc['account_email'] ?? googleExpectedAccount()),
    'mail'  => 'PHP mail() on this server' . ($nfTransportSet === '' ? ' — the fallback until Google is connected' : ''),
    'sink'  => 'the test mail sink (nothing is really sent)',
][$nfTransport] ?? $nfTransport;
$nfUnmatched = $nfGReady ? inboundUnmatched($pdo, 30) : [];
$nfInboundToday = 0;
if ($nfGReady) {
    try { $nfInboundToday = (int)$pdo->query("SELECT COUNT(*) FROM email_inbound WHERE status IN ('posted','assigned') AND created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn(); } catch (Throwable $e) {}
}
/** Items an unmatched reply can be assigned to: per client, the 40 newest posts / emails / pages (value "<type>:<id>"). */
$nfItemOptions = static function (int $companyId) use ($pdo): array {
    static $cache = [];
    if (isset($cache[$companyId])) return $cache[$companyId];
    $out = [];
    try {
        $nameSel = function_exists('hasPostsNameColumn') && hasPostsNameColumn($pdo) ? 'name' : "'' AS name";
        $s = $pdo->prepare("SELECT id, caption, {$nameSel} FROM posts WHERE company_id = ? AND status <> 'draft' ORDER BY id DESC LIMIT 40");
        $s->execute([$companyId]);
        foreach ($s->fetchAll() as $r) $out['post:' . (int)$r['id']] = 'Post · ' . postDisplayLabel($r);
        if (function_exists('hasEmailsTable') && hasEmailsTable($pdo)) {
            $s = $pdo->prepare("SELECT * FROM emails WHERE company_id = ? ORDER BY id DESC LIMIT 40");
            $s->execute([$companyId]);
            foreach ($s->fetchAll() as $r) $out['email:' . (int)$r['id']] = 'Email · ' . emailDisplayLabel($r);
        }
        if (function_exists('hasPagesTable') && hasPagesTable($pdo) && function_exists('pageDisplayLabel')) {
            $s = $pdo->prepare("SELECT * FROM pages WHERE company_id = ? ORDER BY id DESC LIMIT 40");
            $s->execute([$companyId]);
            foreach ($s->fetchAll() as $r) $out['page:' . (int)$r['id']] = 'Page · ' . pageDisplayLabel($r);
        }
    } catch (Throwable $e) {
        error_log('manage-notifications item options: ' . $e->getMessage());
    }
    return $cache[$companyId] = $out;
};
$nfWhen = static function (?string $at): string { return $at ? relativeTime($at) : 'never'; };
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

  <!-- Email: transport + Google -------------------------------------------------- -->
  <section class="ui-card nf-card" id="google" data-notify-google="<?= $nfGAcc ? 'connected' : ($nfGConfigured ? 'not-connected' : 'not-configured') ?>">
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Email</h3>
      <p class="ui-card-subtitle">Every portal email (sign-in links, client emails, reminders, the Morning summary) goes out from “<?= $nfH(notifyMailSender()[1]) ?>” &lt;<?= $nfH(notifyMailSender()[0]) ?>&gt;. Client emails say “reply to this email” — replies go to <strong><?= $nfH(function_exists('inboundAddress') ? inboundAddress() : '') ?></strong> and land on the item in the portal.</p></div></div>
    <div class="ui-card-body">
      <ul class="nf-checks" role="list">
        <?= $nfCheck($nfTransport === 'gmail' || $nfTransport === 'sink', 'Sending with', '', $nfTransportLine, $nfTransportLine) ?>
        <?= $nfCheck((bool)$nfGAcc, 'Google (Gmail API)', $nfGConfigured ? 'press Connect Google' : 'add the three google_* keys to config.php, then Connect',
                     'Connected as ' . ($nfGAcc['account_email'] ?? ''), $nfGConfigured ? 'Not connected' : 'Not set up') ?>
        <?= $nfCheck((bool)$nfGAcc, 'Replies to ' . (function_exists('inboundAddress') ? inboundAddress() : ''), 'needs Google',
                     'Checked ' . $nfWhen($nfGAcc['last_poll_at'] ?? null) . ' · ' . $nfInboundToday . ' imported this week', 'Not connected') ?>
      </ul>
      <?php if ($nfTransportSet !== '' && $nfTransportSet !== 'sink'): ?>
        <p class="studio-help" data-transport-override>config.php sets <code>mail_transport</code> = '<?= $nfH($nfTransportSet) ?>', which overrides the automatic choice. Set it to '' to send with Gmail once Google is connected.</p>
      <?php endif; ?>
      <?php if ($nfGAcc): ?>
        <dl class="nf-urls" data-google-status>
          <dt>Connected</dt><dd><?= $nfH(absoluteTime((string)$nfGAcc['connected_at'])) ?><?= $nfGAcc['connected_by'] ? ' by ' . $nfH($nfGAcc['connected_by']) : '' ?></dd>
          <dt>Last success</dt><dd data-google-last-success><?= $nfH($nfWhen($nfGAcc['last_success_at'])) ?></dd>
          <dt>Last error</dt><dd data-google-last-error><?= $nfGAcc['last_error'] ? '<span class="nf-err">' . $nfH($nfGAcc['last_error']) . '</span> · ' . $nfH($nfWhen($nfGAcc['last_error_at'])) : 'none' ?></dd>
        </dl>
      <?php endif; ?>
      <dl class="nf-urls">
        <dt>Google redirect URI</dt><dd><code data-url="google-redirect"><?= $nfH(function_exists('googleRedirectUri') ? googleRedirectUri() : '') ?></code></dd>
      </dl>
      <div class="studio-export-actions">
        <form method="POST" action="<?= $nfH(basePath() . '/google-oauth.php') ?>" class="studio-inline-form" data-google-connect-form>
          <input type="hidden" name="action" value="start">
          <button type="submit" class="ui-btn <?= $nfGAcc ? 'ui-btn--gray' : 'ui-btn--filled' ?>" data-google-connect<?= $nfGConfigured && $nfGReady ? '' : ' disabled' ?>><?= icon('mail') ?><span><?= $nfGAcc ? 'Reconnect Google' : 'Connect Google' ?></span></button>
        </form>
        <?php if ($nfGAcc): ?>
          <button type="button" class="ui-btn ui-btn--gray" data-notify-action="google_poll">Check replies now</button>
          <button type="button" class="ui-btn ui-btn--plain studio-danger-btn" data-notify-action="google_disconnect" data-google-disconnect>Disconnect</button>
        <?php endif; ?>
        <button type="button" class="ui-btn ui-btn--plain" data-notify-action="email_test">Send test email</button>
      </div>
      <p class="studio-help">Connect signs in to Google as <?= $nfH(function_exists('googleExpectedAccount') ? googleExpectedAccount() : '') ?> and asks for two permissions: “send email”, and “read, label and organise mail” (to pick up client replies and label them portal-processed). The token is stored encrypted. Step by step: docs/google-setup.md in the portal’s files.</p>
    </div>
  </section>

  <!-- Client emails ---------------------------------------------------------------- -->
  <section class="ui-card nf-card" id="client-emails" data-notify-client-emails>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Client emails</h3>
      <p class="ui-card-subtitle">Sent to each client’s contacts (Manage → Clients → Contacts), each with their own sign-in links. Turn a kind off per client in Manage → Clients; each contact can opt out from the link in every email.</p></div></div>
    <div class="ui-card-body">
      <ul class="nf-kinds" role="list">
        <?php
          $nfKindWhen = ['review' => '15 minutes after the last item is sent for review — one email listing them all', 'reply' => '10 minutes after a reply from Joust (internal notes never) — threaded on the item',
                         'live' => 'once a day at the Morning summary hour', 'weekly' => 'Mondays — to you, not clients: response times, approvals, what’s waiting'];
          $nfKindName = ['review' => 'Ready for your review', 'reply' => 'Joust replied', 'live' => 'Live & scheduled', 'weekly' => 'Weekly owner report'];
          foreach ($nfKindName as $k => $label):
            $pv = basePath() . '/email-preview.php?type=' . $k;
        ?>
          <li class="nf-kind" data-email-kind="<?= $nfH($k) ?>">
            <div class="nf-kind-body"><div class="nf-check-label"><?= $nfH($label) ?></div><div class="nf-check-state"><?= $nfH($nfKindWhen[$k]) ?></div></div>
            <div class="nf-kind-actions">
              <a class="ui-btn ui-btn--plain ui-btn--sm" href="<?= $nfH($pv) ?>" target="_blank" rel="noopener" data-email-preview="<?= $nfH($k) ?>">Preview</a>
              <a class="ui-btn ui-btn--plain ui-btn--sm" href="<?= $nfH($pv . '&format=text') ?>" target="_blank" rel="noopener" data-email-preview-text="<?= $nfH($k) ?>">Text</a>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <!-- Unmatched email replies -------------------------------------------------------- -->
  <section class="ui-card nf-card" id="unmatched" data-notify-unmatched="<?= count($nfUnmatched) ?>">
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Unmatched email replies<?= $nfUnmatched ? ' <span class="ui-badge">' . count($nfUnmatched) . '</span>' : '' ?></h3>
      <p class="ui-card-subtitle">Replies to <?= $nfH(function_exists('inboundAddress') ? inboundAddress() : '') ?> the portal could not place on an item by itself — not a reply to a portal email, a reply to an email about several items, or from someone who isn’t a contact of that client. Nothing from them is posted until you assign it.</p></div></div>
    <div class="ui-card-body">
      <?php if (!$nfGAcc && !$nfUnmatched): ?>
        <p class="text-secondary" data-unmatched-state="not-connected">Not connected — replies are picked up once Google is connected.</p>
      <?php elseif (!$nfUnmatched): ?>
        <p class="text-secondary" data-unmatched-state="empty">Nothing waiting.</p>
      <?php endif; ?>
      <ul class="nf-log nf-unmatched" role="list">
        <?php foreach ($nfUnmatched as $u):
          $opts = [];
          $order = $u['company_id'] ? [(int)$u['company_id']] : [];
          foreach ($nfCompanies as $c) if (!in_array((int)$c['id'], $order, true)) $order[] = (int)$c['id'];
          $byId = []; foreach ($nfCompanies as $c) $byId[(int)$c['id']] = $c;
          $snippet = trim((string)$u['body_text']);
          if (mb_strlen($snippet) > 280) $snippet = rtrim(mb_substr($snippet, 0, 279)) . '…';
        ?>
          <li class="nf-log-row nf-unmatched-row" data-unmatched-row="<?= (int)$u['id'] ?>">
            <div class="nf-log-icon" aria-hidden="true"><?= icon('mail') ?></div>
            <div class="nf-log-body">
              <div class="nf-log-title"><?= $nfH(trim((string)$u['from_name']) !== '' ? $u['from_name'] . ' <' . $u['from_email'] . '>' : $u['from_email']) ?><?= $u['company_name'] ? ' · <span class="text-secondary">' . $nfH($u['company_name']) . '</span>' : '' ?></div>
              <div class="nf-log-meta"><?= $nfH((string)$u['subject']) ?> · <time title="<?= $nfH(absoluteTime((string)($u['received_at'] ?: $u['created_at']))) ?>"><?= $nfH(relativeTime((string)($u['received_at'] ?: $u['created_at']))) ?></time><?= !empty($u['has_attachments']) ? ' · attachment not imported' : '' ?></div>
              <?php if ($snippet !== ''): ?><blockquote class="nf-unmatched-body" data-unmatched-body><?= nl2br($nfH($snippet)) ?></blockquote><?php endif; ?>
              <div class="nf-log-error nf-unmatched-why"><?= $nfH((string)$u['reason']) ?></div>
              <form class="nf-unmatched-assign" data-notify-form="inbound_assign">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <select class="ui-select" name="entity" aria-label="Item this reply belongs to" required data-unmatched-item>
                  <option value="">Assign to item…</option>
                  <?php foreach ($order as $ocid): $items = $nfItemOptions($ocid); if (!$items) continue; ?>
                    <optgroup label="<?= $nfH($byId[$ocid]['name'] ?? 'Client') ?>">
                      <?php foreach ($items as $val => $lab): ?>
                        <option value="<?= $nfH($val) ?>"<?= ($u['entity_type'] && $val === $u['entity_type'] . ':' . (int)$u['entity_id']) ? ' selected' : '' ?>><?= $nfH($lab) ?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="ui-btn ui-btn--tinted ui-btn--sm" data-unmatched-assign>Assign</button>
                <button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-notify-action="inbound_dismiss" data-id="<?= (int)$u['id'] ?>" data-unmatched-dismiss>Dismiss</button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
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
