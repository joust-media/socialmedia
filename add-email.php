<?php
/**
 * Studio → Emails: create / edit one email, plus the small admin actions the
 * Studio Emails tab posts here (groups, Emails-tab toggle, delete). Admin only —
 * requireAdmin() redirects a client session to login before any output.
 *
 *   GET  add-email.php?client=<slug>             new email form
 *   GET  add-email.php?client=<slug>&edit=<id>   edit form (+ comment thread, delete)
 *
 *   POST (requireSameSiteFetch on every action; hidden `action` + `id` like add-post.php)
 *     create | update   code*, title, html_url, subject, preview_text, trigger_text, send_at,
 *                       priority, status, live, groups[], new_groups, notes
 *                       → create: emails.php?client&email=<id> · update: studio?tab=emails&msg=
 *     delete            id → map rows + row removed, 'deleted' logged
 *     group_add         name            (ensureEmailGroup)
 *     group_rename      id, name
 *     group_delete      id              (removes email_group_map rows too)
 *     module_toggle     to=1|0          (company_modules row for the 'emails' module)
 *   Non-form actions always redirect to studio.php?client=…&tab=emails&msg=….
 *
 * Rules mirrored from email-status.php: live=1 only when status=approved (the 409 rule);
 * the code is unique per company (case-insensitive, normalised via emailNormalizeCode()).
 * Activity: 'created' on create; one batch of edited_<field> rows per save
 * (+ marked_live / unmarked_live when the flag flips); 'deleted' on delete.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') { requireSameSiteFetch(); }   // cross-site POSTs → 403 (helpers.php)

require_once __DIR__ . '/partials/components/comment-thread.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!$client) {
    header('Location: ' . clientUrl('studio.php', ['msg' => 'Pick a client first.']));
    exit;
}
$cid = (int)$client['id'];

/** Back to the Studio Emails tab with a flash. */
function emailsStudioRedirect(string $msg, array $extra = []): void {
    header('Location: ' . clientUrl('studio.php', ['tab' => 'emails', 'msg' => $msg] + $extra));
    exit;
}

if (!hasEmailsTable($pdo)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') emailsStudioRedirect('The emails tables are missing — run migrate.php first.');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The emails tables are missing - run migrate.php first.";
    exit;
}

$errors   = [];
$flash    = trim((string)($_GET['msg'] ?? ''));
$editId   = (int)($_GET['edit'] ?? 0);
$email    = null;
$statuses = ['draft' => 'Draft', 'pending' => 'To Review', 'approved' => 'Approved', 'denied' => 'Needs changes'];
$priorities = ['' => '—', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

/** Form values (strings; groups = ids). */
$vals = [
    'code' => '', 'title' => '', 'html_url' => '', 'subject' => '', 'preview_text' => '', 'trigger_text' => '',
    'send_at' => '', 'priority' => '', 'status' => 'draft', 'live' => 0, 'notes' => '', 'groups' => [], 'new_groups' => '',
];

/** Load the row being edited (must belong to this client). */
function loadOwnEmail(PDO $pdo, int $id, int $cid): ?array {
    $e = emailById($pdo, $id);
    if (!$e || (int)$e['company_id'] !== $cid) return null;
    return $e;
}

// -------------------------------------------------------------------
// POST
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // ---- Emails tab toggle ------------------------------------------
    if ($action === 'module_toggle') {
        $on = (int)($_POST['to'] ?? 0) === 1;
        if (!setEmailsModuleEnabled($pdo, $cid, $on)) emailsStudioRedirect('The emails module row is missing — run migrate.php first.');
        emailsStudioRedirect($on ? 'Emails tab enabled for ' . $client['name'] . '.' : 'Emails tab disabled for ' . $client['name'] . '.');
    }

    // ---- Groups -------------------------------------------------------
    if ($action === 'group_add') {
        $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
        if ($name === '' || emailSlugify($name) === '') emailsStudioRedirect('Group name is required.');
        $before = count(emailGroupsForCompany($pdo, $cid));
        $gid = ensureEmailGroup($pdo, $cid, $name);
        $after = count(emailGroupsForCompany($pdo, $cid));
        emailsStudioRedirect($gid && $after > $before ? 'Group "' . $name . '" added.' : 'Group "' . $name . '" already exists.');
    }
    if ($action === 'group_rename') {
        $err = renameEmailGroup($pdo, $cid, (int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''));
        emailsStudioRedirect($err !== '' ? $err : 'Group renamed.');
    }
    if ($action === 'group_delete') {
        $gid = (int)($_POST['id'] ?? 0);
        $g   = emailGroupById($pdo, $cid, $gid);
        if (!$g || !deleteEmailGroup($pdo, $cid, $gid)) emailsStudioRedirect('Group not found.');
        emailsStudioRedirect('Group "' . $g['name'] . '" deleted.');
    }

    // ---- Delete -------------------------------------------------------
    if ($action === 'delete') {
        $e = loadOwnEmail($pdo, (int)($_POST['id'] ?? 0), $cid);
        if (!$e) { emailsStudioRedirect('That email does not belong to ' . $client['name'] . '.'); }
        try {
            $pdo->beginTransaction();
            deleteEmail($pdo, $e, 'admin');
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('add-email delete: ' . $ex->getMessage());
            emailsStudioRedirect('Delete failed: database error.');
        }
        emailsStudioRedirect(emailDisplayLabel($e) . ' deleted.');
    }

    // ---- Create / Update ----------------------------------------------
    if ($action === 'create' || $action === 'update') {
        if ($action === 'update') {
            $editId = (int)($_POST['id'] ?? 0);
            $email  = loadOwnEmail($pdo, $editId, $cid);
            if (!$email) { emailsStudioRedirect('That email does not belong to ' . $client['name'] . '.'); }
        }
        foreach (['code', 'title', 'html_url', 'subject', 'preview_text', 'trigger_text', 'send_at', 'priority', 'status', 'notes', 'new_groups'] as $k) {
            $vals[$k] = str_replace(["\r\n", "\r"], "\n", trim((string)($_POST[$k] ?? '')));
        }
        $vals['live']   = !empty($_POST['live']) ? 1 : 0;
        $vals['groups'] = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['groups'] ?? [])), static function ($i) { return $i > 0; })));

        $code = emailNormalizeCode($vals['code']);
        $vals['code'] = $code;
        if ($code === '') $errors[] = 'ID is required (e.g. C1, R3).';
        elseif (mb_strlen($code) > 32) $errors[] = 'ID must be 32 characters or fewer.';
        else {
            $dup = emailByCode($pdo, $cid, $code);
            if ($dup && ($action === 'create' || (int)$dup['id'] !== $editId)) {
                $errors[] = 'ID "' . $code . '" is already used by ' . emailDisplayLabel($dup) . ' — pick another.';
            }
        }
        if (!emailValidUrl($vals['html_url'])) $errors[] = 'HTML URL must be a full http:// or https:// address.';
        if (mb_strlen($vals['html_url']) > 512) $errors[] = 'HTML URL is too long (512 characters max).';
        if (mb_strlen($vals['title']) > 255)    $errors[] = 'Title is too long (255 characters max).';
        if (mb_strlen($vals['subject']) > 255)  $errors[] = 'Subject line is too long (255 characters max).';
        $sendAt = null;
        if ($vals['send_at'] !== '') {
            $ts = strtotime($vals['send_at']);
            if ($ts === false) $errors[] = 'Send date is not a valid date.';
            else { $sendAt = date('Y-m-d', $ts); $vals['send_at'] = $sendAt; }
        }
        if (!array_key_exists($vals['priority'], $priorities)) $errors[] = 'Priority must be Low, Medium or High.';
        if (!isset($statuses[$vals['status']])) { $errors[] = 'Unknown status.'; $vals['status'] = 'draft'; }
        if ($vals['live'] && $vals['status'] !== 'approved') $errors[] = 'Only an approved email can be marked live — set the status to Approved first.';

        $known = [];
        foreach (emailGroupsForCompany($pdo, $cid) as $g) $known[(int)$g['id']] = $g;
        $vals['groups'] = array_values(array_filter($vals['groups'], static function ($i) use ($known) { return isset($known[$i]); }));
        $newGroupNames = array_values(array_filter(array_map(static function ($n) {
            return trim(preg_replace('/\s+/', ' ', $n));
        }, preg_split('/[,|\n]+/', $vals['new_groups']) ?: []), static function ($n) { return $n !== '' && emailSlugify($n) !== ''; }));

        if (!$errors) {
            $now    = date('Y-m-d H:i:s');
            $live   = $vals['live'] ? 1 : 0;
            $params = [
                mb_substr($vals['title'], 0, 255), mb_substr($vals['html_url'], 0, 512), mb_substr($vals['subject'], 0, 255),
                $vals['preview_text'] === '' ? null : $vals['preview_text'],
                $vals['trigger_text'] === '' ? null : $vals['trigger_text'],
                $sendAt, $vals['priority'] === '' ? null : $vals['priority'],
                $vals['status'], $live,
            ];
            try {
                $pdo->beginTransaction();
                $groupIds = $vals['groups'];
                foreach ($newGroupNames as $n) { $gid = ensureEmailGroup($pdo, $cid, $n); if ($gid) $groupIds[] = $gid; }
                $groupIds = array_values(array_unique($groupIds));

                if ($action === 'create') {
                    $ins = $pdo->prepare("
                        INSERT INTO emails (company_id, code, title, html_url, subject, preview_text, trigger_text, send_at, priority, status, live, live_at, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute(array_merge([$cid, $code], $params, [$live ? $now : null, $vals['notes'] === '' ? null : $vals['notes']]));
                    $newId = (int)$pdo->lastInsertId();
                    setEmailGroups($pdo, $newId, $groupIds);
                    $label = emailDisplayLabel(['code' => $code, 'title' => $vals['title']]);
                    logEmailActivity($pdo, 'admin', 'created', $newId, 'Email ' . $label . ' created', null, null, $cid);
                    if ($live) logEmailActivity($pdo, 'admin', 'marked_live', $newId, 'Email ' . $label . ' marked live', null, null, $cid);
                    $pdo->commit();
                    header('Location: ' . emailUrl(['id' => $newId]));
                    exit;
                }

                // update: diff first so the activity feed gets one edited_<field> row per real change
                $oldLabel = emailDisplayLabel($email);
                $changes  = [];
                $compare  = [
                    'code' => [$email['code'], $code],
                    'title' => [$email['title'], $vals['title']],
                    'html_url' => [$email['html_url'], $vals['html_url']],
                    'subject' => [$email['subject'], $vals['subject']],
                    'preview_text' => [$email['preview_text'], $vals['preview_text']],
                    'trigger_text' => [$email['trigger_text'], $vals['trigger_text']],
                    'send_at' => [($email['send_at'] ?? '') === '0000-00-00' ? '' : $email['send_at'], $sendAt],
                    'priority' => [$email['priority'], $vals['priority']],
                    'notes' => [$email['notes'], $vals['notes']],
                ];
                foreach ($compare as $f => [$old, $new]) {
                    $old = str_replace(["\r\n", "\r"], "\n", (string)($old ?? ''));
                    $new = (string)($new ?? '');
                    if ($old !== $new) $changes[$f] = [$old, $new];
                }
                $oldKey = emailStatusKey($email);
                $newKey = $live ? 'live' : $vals['status'];
                if ($oldKey !== $newKey) $changes['status'] = [emailStatusLabelForKey($oldKey), emailStatusLabelForKey($newKey)];
                $oldGroupIds = array_map(static function ($g) { return (int)$g['id']; }, $email['groups'] ?? []);
                sort($oldGroupIds);
                $sortedNew = $groupIds; sort($sortedNew);
                if ($oldGroupIds !== $sortedNew) {
                    $names = static function (array $ids) use ($known, $pdo, $cid) {
                        $all = $known; foreach (emailGroupsForCompany($pdo, $cid) as $g) $all[(int)$g['id']] = $g;
                        return implode('|', array_map(static function ($i) use ($all) { return $all[$i]['name'] ?? ('#' . $i); }, $ids));
                    };
                    $changes['groups'] = [$names($oldGroupIds), $names($sortedNew)];
                }

                $wasLive = !empty($email['live']);
                $liveAt  = $live ? ($wasLive ? ($email['live_at'] ?? $now) : $now) : null;
                $upd = $pdo->prepare("
                    UPDATE emails
                       SET code = ?, title = ?, html_url = ?, subject = ?, preview_text = ?, trigger_text = ?, send_at = ?, priority = ?, status = ?, live = ?, live_at = ?, notes = ?
                     WHERE id = ? AND company_id = ?
                ");
                $upd->execute(array_merge([$code], $params, [$liveAt, $vals['notes'] === '' ? null : $vals['notes'], $editId, $cid]));
                setEmailGroups($pdo, $editId, $groupIds);

                if ($changes) {
                    $batch = newBatchId();
                    $newLabel = emailDisplayLabel(['code' => $code, 'title' => $vals['title']]);
                    foreach ($changes as $f => [$old, $new]) {
                        logEmailActivity($pdo, 'admin', 'edited_' . emailActivityFieldKey($f), $editId,
                            emailFieldLabel($f) . ' edited on ' . $newLabel,
                            mb_substr($old, 0, 200) . ' → ' . mb_substr($new, 0, 200), $batch, $cid);
                    }
                    if ($wasLive !== (bool)$live) {
                        logEmailActivity($pdo, 'admin', $live ? 'marked_live' : 'unmarked_live', $editId,
                            'Email ' . $newLabel . ($live ? ' marked live' : ' unmarked live'), null, $batch, $cid);
                    }
                }
                $pdo->commit();
                emailsStudioRedirect(emailDisplayLabel(['code' => $code, 'title' => $vals['title']]) . ($changes ? ' saved (' . count($changes) . ' change' . (count($changes) === 1 ? '' : 's') . ').' : ' saved — no changes.'));
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('add-email save: ' . $ex->getMessage());
                $errors[] = 'Save failed: database error.';
            }
        }
    }
}

// -------------------------------------------------------------------
// GET: load the row for editing, seed the form
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editId > 0) {
    $email = loadOwnEmail($pdo, $editId, $cid);
    if (!$email) {
        emailsStudioRedirect('That email does not belong to ' . $client['name'] . '.');
    }
    foreach (['code', 'title', 'html_url', 'subject', 'preview_text', 'trigger_text', 'send_at', 'priority', 'status', 'notes'] as $k) {
        $vals[$k] = (string)($email[$k] ?? '');
    }
    if ($vals['send_at'] === '0000-00-00') $vals['send_at'] = '';
    if (!isset($statuses[$vals['status']])) $vals['status'] = 'draft';
    $vals['live']   = !empty($email['live']) ? 1 : 0;
    $vals['groups'] = array_map(static function ($g) { return (int)$g['id']; }, $email['groups'] ?? []);
}

$isEdit     = $email !== null;
$groups     = emailGroupsForCompany($pdo, $cid);
$formAction = $isEdit ? 'update' : 'create';
$formTitle  = $isEdit ? 'Edit ' . emailDisplayLabel($email) : 'New email';
$selfUrl    = clientUrl('add-email.php', $isEdit ? ['edit' => (int)$email['id']] : []);
$studioUrl  = clientUrl('studio.php', ['tab' => 'emails']);
$thread     = $isEdit && hasActivityLog($pdo) ? commentThread($pdo, 'email', (int)$email['id']) : [];

$pageTitle   = $formTitle;
$navSubtitle = 'Studio · ' . $client['name'] . ' · Emails';
$activeTab   = 'studio';
$pageWide    = true;
$navWide     = true;
$navBack     = ['href' => $studioUrl, 'label' => 'Studio'];
$navLinks    = [];
if ($isEdit) $navLinks[] = ['label' => 'Open in Emails', 'href' => emailUrl($email)];
if ($vals['html_url'] !== '' && emailValidUrl($vals['html_url'])) $navLinks[] = ['label' => 'Open HTML', 'href' => $vals['html_url'], 'attrs' => ['target' => '_blank', 'rel' => 'noopener']];
$bodyClass   = 'page-studio page-email-form';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">';
$footExtra   = '<script>window.StudioConfig = ' . json_encode(['base' => basePath(), 'client' => $client['slug']], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . h(staticUrl('js/studio.js')) . '" defer></script>';

include __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash): ?>
  <div class="studio-alert studio-alert--ok" role="status"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="studio-alert studio-alert--error" role="alert" data-form-errors>
    <?php foreach ($errors as $err): ?><div><?= h($err) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" action="<?= h($selfUrl) ?>" class="ui-card studio-email-form" data-email-form autocomplete="off">
  <input type="hidden" name="action" value="<?= h($formAction) ?>">
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$email['id'] ?>"><?php endif; ?>
  <div class="ui-card-header"><div class="ui-card-heading">
    <h3 class="ui-card-title"><?= h($formTitle) ?></h3>
    <p class="ui-card-subtitle"><?= $isEdit ? 'Changes are logged to the activity feed. ' : '' ?>The client sees To Review and Approved emails; Draft and Needs changes stay admin-only.</p>
  </div>
  <?php if ($isEdit): ?><div class="ui-card-aside"><?= emailStatusPill($email) ?></div><?php endif; ?>
  </div>
  <div class="ui-card-body">
    <section class="studio-fields">
      <div class="studio-field-row">
        <div class="studio-field studio-field--inline">
          <label class="studio-label" for="email-code">ID <span class="text-tertiary">— the sheet's code</span></label>
          <input class="ui-input" type="text" id="email-code" name="code" maxlength="32" required value="<?= h($vals['code']) ?>" placeholder="C1">
        </div>
        <div class="studio-field">
          <label class="studio-label" for="email-status">Status</label>
          <select class="ui-select" id="email-status" name="status" data-email-status>
            <?php foreach ($statuses as $k => $label): ?>
              <option value="<?= h($k) ?>"<?= $vals['status'] === $k ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="studio-field">
          <span class="studio-label">Live</span>
          <label class="studio-chip<?= $vals['live'] ? ' is-active' : '' ?>" data-email-live-chip title="Only an approved email can go live">
            <input type="checkbox" name="live" value="1" data-email-live<?= $vals['live'] ? ' checked' : '' ?><?= $vals['status'] === 'approved' ? '' : ' disabled' ?>> Live in production
          </label>
          <p class="studio-help" data-email-live-help<?= $vals['status'] === 'approved' ? ' hidden' : '' ?>>Set the status to Approved to mark this email live.</p>
        </div>
      </div>

      <div class="studio-field">
        <label class="studio-label" for="email-title">Title</label>
        <input class="ui-input" type="text" id="email-title" name="title" maxlength="255" value="<?= h($vals['title']) ?>" placeholder="Welcome to Privacy Bee">
      </div>
      <div class="studio-field">
        <label class="studio-label" for="email-url">HTML URL <span class="text-tertiary">— the hosted email the client reviews</span></label>
        <input class="ui-input" type="url" id="email-url" name="html_url" maxlength="512" value="<?= h($vals['html_url']) ?>" placeholder="https://assets.privacybee.com/emails/c1.html" pattern="https?://.*">
      </div>
      <div class="studio-field">
        <label class="studio-label" for="email-subject">Subject line</label>
        <input class="ui-input" type="text" id="email-subject" name="subject" maxlength="255" value="<?= h($vals['subject']) ?>">
      </div>
      <div class="studio-field">
        <label class="studio-label" for="email-preview">Preview text</label>
        <textarea class="ui-textarea" id="email-preview" name="preview_text" rows="2" maxlength="2000"><?= h($vals['preview_text']) ?></textarea>
      </div>
      <div class="studio-field">
        <label class="studio-label" for="email-trigger">Trigger <span class="text-tertiary">— when it sends; several lines are fine</span></label>
        <textarea class="ui-textarea" id="email-trigger" name="trigger_text" rows="3" maxlength="4000" placeholder="3 Days Post Signup"><?= h($vals['trigger_text']) ?></textarea>
      </div>

      <div class="studio-field-row">
        <div class="studio-field">
          <label class="studio-label" for="email-send">Send date <span class="text-tertiary">— optional</span></label>
          <input class="ui-input" type="date" id="email-send" name="send_at" value="<?= h($vals['send_at']) ?>">
        </div>
        <div class="studio-field">
          <label class="studio-label" for="email-priority">Priority</label>
          <select class="ui-select" id="email-priority" name="priority">
            <?php foreach ($priorities as $k => $label): ?>
              <option value="<?= h($k) ?>"<?= $vals['priority'] === $k ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="studio-field">
        <span class="studio-label">Groups <span class="text-tertiary">— sequences the client can filter by</span></span>
        <div class="studio-chips studio-chips--wrap" data-email-groups>
          <?php foreach ($groups as $g): $on = in_array((int)$g['id'], $vals['groups'], true); ?>
            <label class="studio-chip<?= $on ? ' is-active' : '' ?>" data-email-group-chip><input type="checkbox" name="groups[]" value="<?= (int)$g['id'] ?>"<?= $on ? ' checked' : '' ?>><?= h($g['name']) ?></label>
          <?php endforeach; ?>
          <?php if (!$groups): ?><span class="studio-chip studio-chip--static">No groups yet</span><?php endif; ?>
        </div>
        <input class="ui-input" type="text" name="new_groups" maxlength="400" value="<?= h($vals['new_groups']) ?>" placeholder="New group — comma-separate several (e.g. Leads, Renewal)" aria-label="New group">
      </div>

      <div class="studio-field">
        <label class="studio-label" for="email-notes">Notes <span class="text-tertiary">— admin only, never shown to the client</span></label>
        <textarea class="ui-textarea" id="email-notes" name="notes" rows="2" maxlength="4000"><?= h($vals['notes']) ?></textarea>
      </div>
    </section>

    <div class="studio-actions">
      <a class="ui-btn ui-btn--gray" href="<?= h($studioUrl) ?>">Cancel</a>
      <button type="submit" class="ui-btn ui-btn--filled"><?= $isEdit ? 'Save changes' : 'Create email' ?></button>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
  <section class="studio-thread ui-card" data-thread-card>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Comments</h3>
      <p class="ui-card-subtitle">The thread the client sees on this email. Reply from <a href="<?= h(emailUrl($email)) ?>">Emails</a>.</p></div></div>
    <div class="ui-card-body">
      <?= commentThreadHtml($thread, ['empty' => 'No messages yet.']) ?>
    </div>
  </section>
  <form class="studio-danger" method="POST" action="<?= h(clientUrl('add-email.php')) ?>" data-confirm-submit="Delete <?= h(emailDisplayLabel($email)) ?>? Its comments stay in the activity log. This cannot be undone.">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$email['id'] ?>">
    <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn">Delete this email</button>
  </form>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
