<?php
/**
 * Studio → Pages: create / edit one page, plus the small admin actions the
 * Studio Pages tab posts here (Pages-tab toggle, delete). Admin only —
 * requireAdmin() redirects a client session to login before any output.
 *
 *   GET  add-page.php?client=<slug>             new page form
 *   GET  add-page.php?client=<slug>&edit=<id>   edit form (+ file uploader for upload pages, comment thread, delete)
 *
 *   POST (requireSameSiteFetch on every action; hidden `action` + `id` like add-email.php)
 *     create | update   title*, slug (auto from the title when blank), source upload|url, url,
 *                       entry, description, status, live, notes
 *                       → create: add-page.php?client&edit=<id> (so files can be uploaded right away)
 *                       · update: studio?tab=pages&msg=
 *     delete            id → folder (contained) + page_files + row removed, 'deleted' logged
 *     module_toggle     to=1|0          (company_modules row for the 'pages' module)
 *   Files are uploaded / removed / promoted to entry through page-upload.php (static/js/pages.js).
 *
 * Rules mirrored from page-status.php: live=1 only when status=approved (the 409 rule);
 * the slug is unique per company ([a-z0-9-], pageSlugify()). Renaming the slug of an upload
 * page moves its media/pages/<client>/<slug>/ folder along (renamePageFolder()).
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

/** Back to the Studio Pages tab with a flash. */
function pagesStudioRedirect(string $msg, array $extra = []): void {
    header('Location: ' . clientUrl('studio.php', ['tab' => 'pages', 'msg' => $msg] + $extra));
    exit;
}

if (!hasPagesTable($pdo)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') pagesStudioRedirect('The pages tables are missing — run migrate.php first.');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The pages tables are missing - run migrate.php first.";
    exit;
}

$errors   = [];
$flash    = trim((string)($_GET['msg'] ?? ''));
$editId   = (int)($_GET['edit'] ?? 0);
$page     = null;
$statuses = ['draft' => 'Draft', 'pending' => 'To Review', 'approved' => 'Approved', 'denied' => 'Needs changes'];
$sources  = ['upload' => 'Upload — HTML + assets in the portal', 'url' => 'URL — hosted somewhere else'];

/** Form values (strings). */
$vals = [
    'title' => '', 'slug' => '', 'source' => 'upload', 'url' => '', 'entry' => 'index.html',
    'description' => '', 'status' => 'draft', 'live' => 0, 'notes' => '',
];

/** Load the row being edited (must belong to this client). */
function loadOwnPage(PDO $pdo, int $id, int $cid): ?array {
    $p = pageById($pdo, $id);
    if (!$p || (int)$p['company_id'] !== $cid) return null;
    return $p;
}

// -------------------------------------------------------------------
// POST
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // ---- Pages tab toggle -------------------------------------------
    if ($action === 'module_toggle') {
        $on = (int)($_POST['to'] ?? 0) === 1;
        if (!setPagesModuleEnabled($pdo, $cid, $on)) pagesStudioRedirect('The pages module row is missing — run migrate.php first.');
        pagesStudioRedirect($on ? 'Pages tab enabled for ' . $client['name'] . '.' : 'Pages tab disabled for ' . $client['name'] . '.');
    }

    // ---- Delete -------------------------------------------------------
    if ($action === 'delete') {
        $p = loadOwnPage($pdo, (int)($_POST['id'] ?? 0), $cid);
        if (!$p) { pagesStudioRedirect('That page does not belong to ' . $client['name'] . '.'); }
        try {
            $pdo->beginTransaction();
            $res = deletePage($pdo, $p, 'admin');
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('add-page delete: ' . $ex->getMessage());
            pagesStudioRedirect('Delete failed: database error.');
        }
        pagesStudioRedirect(pageDisplayLabel($p) . ' deleted' . ((int)$res['files'] > 0 ? ' (' . (int)$res['files'] . ' file' . ((int)$res['files'] === 1 ? '' : 's') . ' removed)' : '') . '.');
    }

    // ---- Create / Update ----------------------------------------------
    if ($action === 'create' || $action === 'update') {
        if ($action === 'update') {
            $editId = (int)($_POST['id'] ?? 0);
            $page   = loadOwnPage($pdo, $editId, $cid);
            if (!$page) { pagesStudioRedirect('That page does not belong to ' . $client['name'] . '.'); }
        }
        foreach (['title', 'slug', 'source', 'url', 'entry', 'description', 'status', 'notes'] as $k) {
            $vals[$k] = str_replace(["\r\n", "\r"], "\n", trim((string)($_POST[$k] ?? '')));
        }
        $vals['live'] = !empty($_POST['live']) ? 1 : 0;
        if (!isset($sources[$vals['source']])) { $errors[] = 'Unknown source.'; $vals['source'] = 'upload'; }

        if ($vals['title'] === '') $errors[] = 'Title is required.';
        elseif (mb_strlen($vals['title']) > 160) $errors[] = 'Title is too long (160 characters max).';
        $slug = pageSlugify($vals['slug'] !== '' ? $vals['slug'] : $vals['title']);
        $vals['slug'] = $slug;
        if ($slug === '') $errors[] = 'Slug is required — letters, digits and dashes (e.g. spring-launch).';
        else {
            $dup = pageBySlug($pdo, $cid, $slug);
            if ($dup && ($action === 'create' || (int)$dup['id'] !== $editId)) {
                $errors[] = 'Slug "' . $slug . '" is already used by ' . pageDisplayLabel($dup) . ' — pick another.';
            }
        }
        if ($vals['source'] === 'url') {
            if ($vals['url'] === '') $errors[] = 'A page URL is required when the source is URL.';
            elseif (!pageValidUrl($vals['url'])) $errors[] = 'Page URL must be a full http:// or https:// address.';
            elseif (mb_strlen($vals['url']) > 512) $errors[] = 'Page URL is too long (512 characters max).';
        } elseif ($vals['url'] !== '' && !pageValidUrl($vals['url'])) {
            $errors[] = 'Page URL must be a full http:// or https:// address.';
        }
        if ($vals['entry'] === '') $vals['entry'] = 'index.html';
        if (!pageFileRelValid($vals['entry']) || !in_array(strtolower((string)pathinfo($vals['entry'], PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
            $errors[] = 'Entry file must be an .html file name inside the page folder (e.g. index.html or pages/start.html).';
        }
        if (mb_strlen($vals['description']) > 4000) $errors[] = 'Description is too long (4000 characters max).';
        if (!isset($statuses[$vals['status']])) { $errors[] = 'Unknown status.'; $vals['status'] = 'draft'; }
        if ($vals['live'] && $vals['status'] !== 'approved') $errors[] = 'Only an approved page can be marked live — set the status to Approved first.';

        if (!$errors) {
            $now    = date('Y-m-d H:i:s');
            $live   = $vals['live'] ? 1 : 0;
            $url    = $vals['url'] === '' ? null : mb_substr($vals['url'], 0, 512);
            $params = [
                mb_substr($vals['title'], 0, 160), $slug, $vals['source'], $url, mb_substr($vals['entry'], 0, 255),
                $vals['description'] === '' ? null : $vals['description'],
                $vals['status'], $live,
            ];
            try {
                $pdo->beginTransaction();
                if ($action === 'create') {
                    $ins = $pdo->prepare("
                        INSERT INTO pages (company_id, title, slug, source, url, entry, description, status, live, live_at, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute(array_merge([$cid], $params, [$live ? $now : null, $vals['notes'] === '' ? null : $vals['notes']]));
                    $newId = (int)$pdo->lastInsertId();
                    $label = pageDisplayLabel(['title' => $vals['title'], 'slug' => $slug]);
                    logPageActivity($pdo, 'admin', 'created', $newId, 'Page ' . $label . ' created', null, null, $cid);
                    if ($live) logPageActivity($pdo, 'admin', 'marked_live', $newId, 'Page ' . $label . ' marked live', null, null, $cid);
                    $pdo->commit();
                    header('Location: ' . clientUrl('add-page.php', ['edit' => $newId, 'msg' => $label . ' created' . ($vals['source'] === 'upload' ? ' — now upload its files.' : '.')]));
                    exit;
                }

                // update: diff first so the activity feed gets one edited_<field> row per real change
                $changes = [];
                $compare = [
                    'title' => [$page['title'], $vals['title']],
                    'slug' => [$page['slug'], $slug],
                    'source' => [$page['source'], $vals['source']],
                    'url' => [$page['url'], $vals['url']],
                    'entry' => [$page['entry'], $vals['entry']],
                    'description' => [$page['description'], $vals['description']],
                    'notes' => [$page['notes'], $vals['notes']],
                ];
                foreach ($compare as $f => [$old, $new]) {
                    $old = str_replace(["\r\n", "\r"], "\n", (string)($old ?? ''));
                    $new = (string)($new ?? '');
                    if ($old !== $new) $changes[$f] = [$old, $new];
                }
                $oldKey = pageStatusKey($page);
                $newKey = $live ? 'live' : $vals['status'];
                if ($oldKey !== $newKey) $changes['status'] = [pageStatusLabelForKey($oldKey), pageStatusLabelForKey($newKey)];

                // Slug change on an upload page: move the folder along (before the row changes, so a failed rename keeps things consistent).
                if (isset($changes['slug']) && strtolower((string)$page['source']) !== 'url') {
                    $moved = renamePageFolder($client, $page, ['slug' => $slug]);
                    if (!$moved && is_dir((string)pageFolderPath($client, $page))) {
                        throw new RuntimeException('Could not move the page folder to the new slug');
                    }
                }

                $wasLive = !empty($page['live']);
                $liveAt  = $live ? ($wasLive ? ($page['live_at'] ?? $now) : $now) : null;
                $upd = $pdo->prepare("
                    UPDATE pages
                       SET title = ?, slug = ?, source = ?, url = ?, entry = ?, description = ?, status = ?, live = ?, live_at = ?, notes = ?
                     WHERE id = ? AND company_id = ?
                ");
                $upd->execute(array_merge($params, [$liveAt, $vals['notes'] === '' ? null : $vals['notes'], $editId, $cid]));

                if ($changes) {
                    $batch = newBatchId();
                    $newLabel = pageDisplayLabel(['title' => $vals['title'], 'slug' => $slug]);
                    foreach ($changes as $f => [$old, $new]) {
                        logPageActivity($pdo, 'admin', 'edited_' . $f, $editId,
                            pageFieldLabel($f) . ' edited on ' . $newLabel,
                            mb_substr($old, 0, 200) . ' → ' . mb_substr($new, 0, 200), $batch, $cid);
                    }
                    if ($wasLive !== (bool)$live) {
                        logPageActivity($pdo, 'admin', $live ? 'marked_live' : 'unmarked_live', $editId,
                            'Page ' . $newLabel . ($live ? ' marked live' : ' unmarked live'), null, $batch, $cid);
                    }
                }
                $pdo->commit();
                pagesStudioRedirect(pageDisplayLabel(['title' => $vals['title'], 'slug' => $slug]) . ($changes ? ' saved (' . count($changes) . ' change' . (count($changes) === 1 ? '' : 's') . ').' : ' saved — no changes.'));
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('add-page save: ' . $ex->getMessage());
                $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() . '.' : 'Save failed: database error.';
            }
        }
    }
}

// -------------------------------------------------------------------
// GET: load the row for editing, seed the form
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editId > 0) {
    $page = loadOwnPage($pdo, $editId, $cid);
    if (!$page) {
        pagesStudioRedirect('That page does not belong to ' . $client['name'] . '.');
    }
    foreach (['title', 'slug', 'source', 'url', 'entry', 'description', 'status', 'notes'] as $k) {
        $vals[$k] = (string)($page[$k] ?? '');
    }
    if (!isset($statuses[$vals['status']])) $vals['status'] = 'draft';
    if (!isset($sources[$vals['source']])) $vals['source'] = 'upload';
    if ($vals['entry'] === '') $vals['entry'] = 'index.html';
    $vals['live'] = !empty($page['live']) ? 1 : 0;
}

$isEdit     = $page !== null;
$formAction = $isEdit ? 'update' : 'create';
$formTitle  = $isEdit ? 'Edit ' . pageDisplayLabel($page) : 'New page';
$selfUrl    = clientUrl('add-page.php', $isEdit ? ['edit' => (int)$page['id']] : []);
$studioUrl  = clientUrl('studio.php', ['tab' => 'pages']);
$thread     = $isEdit && hasActivityLog($pdo) ? commentThread($pdo, 'page', (int)$page['id']) : [];
$files      = $isEdit ? pageFilesFor($pdo, (int)$page['id']) : [];
$folderRel  = $isEdit ? pageFolderRel($client, $page) : 'media/pages/' . $client['slug'] . '/<slug>';
$viewUrl    = $isEdit ? pageViewUrl($page, $client) : '';

$pageTitle   = $formTitle;
$navSubtitle = 'Studio · ' . $client['name'] . ' · Pages';
$activeTab   = 'studio';
$pageWide    = true;
$navWide     = true;
$navBack     = ['href' => $studioUrl, 'label' => 'Studio'];
$navLinks    = [];
if ($isEdit) $navLinks[] = ['label' => 'Open in Pages', 'href' => pageUrl($page)];
if ($viewUrl !== '') $navLinks[] = ['label' => 'Open page', 'href' => $viewUrl, 'attrs' => ['target' => '_blank', 'rel' => 'noopener']];
$bodyClass   = 'page-studio page-page-form';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/pages.css')) . '">';
$filesConfig = $isEdit ? [
    'endpoint' => basePath() . '/page-upload.php',
    'pageId'   => (int)$page['id'],
    'client'   => $client['slug'],
    'entry'    => (string)$page['entry'],
    'folder'   => $folderRel,
    'maxMb'    => 10,
    'exts'     => pageUploadExts(),
] : null;
$footExtra   = '<script>window.StudioConfig = ' . json_encode(['base' => basePath(), 'client' => $client['slug']], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
             . ($filesConfig ? ' window.PageFilesConfig = ' . json_encode($filesConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';' : '') . '</script>' . "\n"
             . '<script src="' . h(staticUrl('js/chunk-upload.js')) . '" defer></script>' . "\n"   // App.chunkUpload: large videos / assets in pieces, resumable
             . '<script src="' . h(staticUrl('js/studio.js')) . '" defer></script>' . "\n"
             . '<script src="' . h(staticUrl('js/pages.js')) . '" defer></script>';

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

<form method="POST" action="<?= h($selfUrl) ?>" class="ui-card studio-page-form" data-page-form autocomplete="off">
  <input type="hidden" name="action" value="<?= h($formAction) ?>">
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>"><?php endif; ?>
  <div class="ui-card-header"><div class="ui-card-heading">
    <h3 class="ui-card-title"><?= h($formTitle) ?></h3>
    <p class="ui-card-subtitle"><?= $isEdit ? 'Changes are logged to the activity feed. ' : '' ?>The client sees To Review and Approved pages; Draft and Needs changes stay admin-only.</p>
  </div>
  <?php if ($isEdit): ?><div class="ui-card-aside"><?= pageStatusPill($page) ?></div><?php endif; ?>
  </div>
  <div class="ui-card-body">
    <section class="studio-fields">
      <div class="studio-field">
        <label class="studio-label" for="page-title">Title</label>
        <input class="ui-input" type="text" id="page-title" name="title" maxlength="160" required value="<?= h($vals['title']) ?>" placeholder="Spring launch" data-page-title>
      </div>
      <div class="studio-field-row">
        <div class="studio-field">
          <label class="studio-label" for="page-slug">Slug <span class="text-tertiary">— folder name; auto from the title</span></label>
          <input class="ui-input" type="text" id="page-slug" name="slug" maxlength="120" value="<?= h($vals['slug']) ?>" placeholder="spring-launch" pattern="[a-z0-9\-]*" data-page-slug<?= $isEdit ? ' data-page-slug-locked' : '' ?>>
          <p class="studio-help"><code data-page-folder><?= h($folderRel) ?>/</code></p>
        </div>
        <div class="studio-field">
          <label class="studio-label" for="page-status">Status</label>
          <select class="ui-select" id="page-status" name="status" data-email-status data-page-status>
            <?php foreach ($statuses as $k => $label): ?>
              <option value="<?= h($k) ?>"<?= $vals['status'] === $k ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="studio-field">
          <span class="studio-label">Live</span>
          <label class="studio-chip<?= $vals['live'] ? ' is-active' : '' ?>" data-email-live-chip title="Only an approved page can go live">
            <input type="checkbox" name="live" value="1" data-email-live<?= $vals['live'] ? ' checked' : '' ?><?= $vals['status'] === 'approved' ? '' : ' disabled' ?>> Live in production
          </label>
          <p class="studio-help" data-email-live-help<?= $vals['status'] === 'approved' ? ' hidden' : '' ?>>Set the status to Approved to mark this page live.</p>
        </div>
      </div>

      <div class="studio-field">
        <span class="studio-label">Source</span>
        <div class="studio-chips studio-chips--wrap" data-page-sources>
          <?php foreach ($sources as $k => $label): ?>
            <label class="studio-chip<?= $vals['source'] === $k ? ' is-active' : '' ?>" data-page-source-chip="<?= h($k) ?>"><input type="radio" name="source" value="<?= h($k) ?>"<?= $vals['source'] === $k ? ' checked' : '' ?> data-page-source><?= h($label) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="studio-field" data-page-when-source="url"<?= $vals['source'] === 'url' ? '' : ' hidden' ?>>
        <label class="studio-label" for="page-url">Page URL <span class="text-tertiary">— the hosted page the client reviews</span></label>
        <input class="ui-input" type="url" id="page-url" name="url" maxlength="512" value="<?= h($vals['url']) ?>" placeholder="https://www.example.com/landing/spring" pattern="https?://.*">
      </div>
      <div class="studio-field" data-page-when-source="upload"<?= $vals['source'] === 'upload' ? '' : ' hidden' ?>>
        <label class="studio-label" for="page-entry">Entry file <span class="text-tertiary">— the HTML file the preview opens</span></label>
        <input class="ui-input" type="text" id="page-entry" name="entry" maxlength="255" value="<?= h($vals['entry']) ?>" placeholder="index.html" data-page-entry-input>
      </div>

      <div class="studio-field">
        <label class="studio-label" for="page-description">Description <span class="text-tertiary">— shown to the client above the comments</span></label>
        <textarea class="ui-textarea" id="page-description" name="description" rows="3" maxlength="4000" placeholder="What this page is for and what to look at."><?= h($vals['description']) ?></textarea>
      </div>
      <div class="studio-field">
        <label class="studio-label" for="page-notes">Notes <span class="text-tertiary">— admin only, never shown to the client</span></label>
        <textarea class="ui-textarea" id="page-notes" name="notes" rows="2" maxlength="4000"><?= h($vals['notes']) ?></textarea>
      </div>
    </section>

    <div class="studio-actions">
      <a class="ui-btn ui-btn--gray" href="<?= h($studioUrl) ?>">Cancel</a>
      <button type="submit" class="ui-btn ui-btn--filled"><?= $isEdit ? 'Save changes' : 'Create page' ?></button>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
  <section class="ui-card studio-page-files" data-page-files<?= strtolower((string)$page['source']) === 'url' ? ' hidden' : '' ?>>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Files <span class="text-tertiary" data-page-files-count><?= count($files) ?></span></h3>
      <p class="ui-card-subtitle">Everything the page needs — HTML, CSS, JS, images, fonts — goes into <code><?= h($folderRel) ?>/</code>. Uploading a file with the same name replaces it.</p></div></div>
    <div class="ui-card-body">
      <label class="studio-dropzone studio-dropzone--sm" data-page-dropzone>
        <input type="file" multiple data-page-files-input accept=".html,.htm,.css,.js,.json,.png,.jpg,.jpeg,.gif,.webp,.svg,.ico,.woff,.woff2,.ttf,.mp4,.webm">
        <span class="studio-dropzone-icon"><?= icon('plus') ?></span>
        <span class="studio-dropzone-label">Drop files here or tap to choose</span>
        <span class="studio-dropzone-hint">html · css · js · json · png · jpg · gif · webp · svg · ico · woff · woff2 · ttf · mp4 · webm — HTML / CSS / JS / JSON up to 10 MB, other assets up to 100 MB, video up to 4 GB</span>
      </label>
      <p class="studio-help studio-renders-note">Large files are sent in pieces and can resume after a dropped connection or a page reload.</p>
      <div class="studio-resume" data-page-resume hidden role="status">
        <span class="studio-resume-text" data-page-resume-text>Resume unfinished uploads</span>
        <label class="ui-btn ui-btn--filled ui-btn--sm studio-resume-pick">Pick the files<input type="file" multiple data-page-resume-input accept=".html,.htm,.css,.js,.json,.png,.jpg,.jpeg,.gif,.webp,.svg,.ico,.woff,.woff2,.ttf,.mp4,.webm" hidden></label>
        <button type="button" class="ui-btn ui-btn--plain ui-btn--sm" data-page-resume-discard>Discard</button>
      </div>
      <div class="studio-field pg-subfolder">
        <label class="studio-label" for="page-subfolder">Into subfolder <span class="text-tertiary">— optional, e.g. img or assets/fonts</span></label>
        <input class="ui-input" type="text" id="page-subfolder" maxlength="120" placeholder="(page root)" pattern="[a-z0-9_\-/]*" data-page-subfolder>
      </div>
      <ul class="studio-uploadlist" data-page-upload-list hidden></ul>
      <ul class="pg-file-list pg-file-list--admin" role="list" data-page-file-rows>
        <?php foreach ($files as $f): $isEntry = (string)$f['filename'] === (string)$page['entry'];
            $href = pageFileRelValid((string)$f['filename']) ? '/' . implode('/', array_map('rawurlencode', array_merge(explode('/', $folderRel), explode('/', (string)$f['filename'])))) : ''; ?>
          <li class="pg-file<?= $isEntry ? ' pg-file--entry' : '' ?>" data-page-file="<?= h($f['filename']) ?>">
            <?php if ($href !== ''): ?><a class="pg-file-name" href="<?= h($href) ?>" target="_blank" rel="noopener noreferrer"><?= h($f['filename']) ?></a><?php else: ?><span class="pg-file-name"><?= h($f['filename']) ?></span><?php endif; ?>
            <span class="pg-file-meta"><?= h(pageFormatBytes((int)$f['size'])) ?><?php if ($isEntry): ?> · <span class="pg-file-entry-tag">entry</span><?php endif; ?></span>
            <span class="pg-file-actions">
              <?php if (!$isEntry && in_array(strtolower((string)pathinfo((string)$f['filename'], PATHINFO_EXTENSION)), ['html', 'htm'], true)): ?>
                <button type="button" class="ui-btn ui-btn--gray ui-btn--sm" data-page-set-entry="<?= h($f['filename']) ?>">Set as entry</button>
              <?php endif; ?>
              <button type="button" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn" data-page-delete-file="<?= h($f['filename']) ?>">Delete</button>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="studio-help pg-files-empty" data-page-files-empty<?= $files ? ' hidden' : '' ?>>No files yet — upload <code><?= h($page['entry']) ?></code> first; the first HTML file becomes the entry automatically.</p>
    </div>
  </section>

  <section class="studio-thread ui-card" data-thread-card>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Comments</h3>
      <p class="ui-card-subtitle">The thread the client sees on this page. Reply from <a href="<?= h(pageUrl($page)) ?>">Pages</a>.</p></div></div>
    <div class="ui-card-body">
      <?= commentThreadHtml($thread, ['empty' => 'No messages yet.']) ?>
    </div>
  </section>
  <form class="studio-danger" method="POST" action="<?= h(clientUrl('add-page.php')) ?>" data-confirm-submit="Delete <?= h(pageDisplayLabel($page)) ?>?<?= strtolower((string)$page['source']) === 'url' ? '' : ' Its folder and every uploaded file are removed too.' ?> Its comments stay in the activity log. This cannot be undone.">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
    <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn">Delete this page</button>
  </form>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
