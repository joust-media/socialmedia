<?php
/**
 * Generic client-scoped module admin form.
 * URL: add-feature.php?client=<slug>&module=<slug>[&edit_item=<id>]
 *
 * Uses the existing `tires` / `tire_images` / `tire_categories` tables
 * but scopes everything by company_id + module_id so each client + module
 * is its own sandbox.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/upload-lib.php';
requireAdmin();

$maxFileSize   = uploadMaxBytes('image');   // 50 MB (upload-lib.php) — Replace goes through upload-chunk.php purpose=replace; new reference images through the Upload sheet
$maxFileMb     = (int)($maxFileSize / (1024 * 1024));
$maxItemImages = uploadFeatureMaxImages();  // 6 reference images per item (renders in a series never count)

// Series renders (tire_images.series_id set — tire-series-lib.php) are reviewed in Assets and never
// count against the reference-image slots; every query here is scoped to series_id IS NULL.
$refOnly = hasTireSeries($pdo) ? ' AND series_id IS NULL' : '';

$errors = [];
$flash  = $_GET['msg'] ?? '';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// --- Require client --- (unscoped: the Tires chooser, where every client's tires — and "New tire" — live)
if (!$client) {
    header('Location: ' . pagePath('assets') . '?view=collections', true, 302);
    exit;
}

/** A dead end inside the shared shell (unknown module / module switched off) instead of a bare text page. */
function featureFailPage(int $code, string $title, string $message, array $back): void {
    global $client, $pdo, $clientSlug;
    http_response_code($code);
    $pageTitle   = $title;
    $navSubtitle = $client['name'] ?? '';
    $navBack     = $back;
    $activeTab   = 'manage';
    include __DIR__ . '/partials/layout-top.php';
    echo '<div class="ui-empty">' . h($message) . '</div>';
    include __DIR__ . '/partials/layout-bottom.php';
    exit;
}

// --- Resolve module ---
$moduleSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_GET['module'] ?? 'tires')));
if ($moduleSlug === '') { $moduleSlug = 'tires'; }
$stmt = $pdo->prepare("SELECT id, slug, singular_label, plural_label, icon FROM modules WHERE slug = ?");
$stmt->execute([$moduleSlug]);
$module = $stmt->fetch();
if (!$module) { featureFailPage(404, 'Not found', 'Unknown module.', ['href' => adminToolsUrl(), 'label' => 'Manage']); }

// Confirm client has this module enabled
$chk = $pdo->prepare("SELECT 1 FROM company_modules WHERE company_id = ? AND module_id = ? LIMIT 1");
$chk->execute([$client['id'], $module['id']]);
if (!$chk->fetchColumn()) {
    featureFailPage(403, 'Module off', $client['name'] . ' does not have the ' . $module['plural_label'] . ' module switched on. Turn it on in Manage → Clients.',
                    ['href' => manageUrl('clients', ['edit' => (int)$client['id']]), 'label' => 'Manage']);
}

$sLabel = $module['singular_label'];   // e.g. "Tire"
$pLabel = $module['plural_label'];     // e.g. "Tires"
$sLower = strtolower($sLabel);         // e.g. "tire"
$pLower = strtolower($pLabel);

function redirectHere($extra = [], $msg = null) {
    global $client, $module;
    $qs = array_merge([
        'client' => $client['slug'],
        'module' => $module['slug'],
    ], $extra);
    if ($msg !== null) { $qs['msg'] = $msg; }
    header('Location: ' . portalUrl('add-feature', $qs));   // root-rooted (clean links: /portal/<client>/tires/<id>/edit)
    exit;
}

// --- POST handlers -----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'item_delete') {
        $itemId = (int)($_POST['id'] ?? 0);
        if ($itemId > 0) {
            try {
                $pdo->beginTransaction();
                // Only delete if it belongs to this client+module
                $chk = $pdo->prepare("
                    SELECT id FROM tires
                    WHERE id = ? AND company_id = ? AND module_id = ?
                ");
                $chk->execute([$itemId, $client['id'], $module['id']]);
                if (!$chk->fetchColumn()) { throw new Exception('Not found / wrong scope.'); }

                $imgs = $pdo->prepare("SELECT image_url FROM tire_images WHERE tire_id = ?");
                $imgs->execute([$itemId]);
                foreach ($imgs->fetchAll() as $row) {
                    if (strpos($row['image_url'], 'uploads/') === 0) {
                        $path = __DIR__ . '/' . $row['image_url'];
                        if (is_file($path)) { if (function_exists('previewDelete')) previewDelete($path); @unlink($path); }
                    }
                }
                $pdo->prepare("DELETE FROM tires WHERE id = ?")->execute([$itemId]);
                $pdo->commit();
                redirectHere([], $sLabel . ' deleted.');
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'item_create' || $action === 'item_update') {
        $name = trim($_POST['item_name'] ?? '');
        if ($name === '') { $errors[] = $sLabel . ' name is required.'; }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($action === 'item_create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tires (name, company_id, module_id)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$name, $client['id'], $module['id']]);
                    $itemId = (int)$pdo->lastInsertId();
                    logActivity($pdo, (int)$client['id'], 'tire', $itemId,
                        'created', 'admin',
                        "Created {$sLower} #{$itemId}: " . mb_substr($name, 0, 200));
                } else {
                    $itemId = (int)($_POST['id'] ?? 0);
                    if ($itemId <= 0) { throw new Exception('Invalid id.'); }
                    // Guard scope
                    $chk = $pdo->prepare("
                        SELECT 1 FROM tires
                        WHERE id = ? AND company_id = ? AND module_id = ? LIMIT 1
                    ");
                    $chk->execute([$itemId, $client['id'], $module['id']]);
                    if (!$chk->fetchColumn()) { throw new Exception('Not found / wrong scope.'); }

                    $stmt = $pdo->prepare("UPDATE tires SET name = ? WHERE id = ?");
                    $stmt->execute([$name, $itemId]);

                    if (!empty($_POST['captions']) && is_array($_POST['captions'])) {
                        $capPre  = $pdo->prepare("SELECT caption FROM tire_images WHERE id = ? AND tire_id = ?");
                        $capStmt = $pdo->prepare("
                            UPDATE tire_images SET caption = ?
                            WHERE id = ? AND tire_id = ?
                        ");
                        foreach ($_POST['captions'] as $imgId => $cap) {
                            $newCap = trim((string)$cap);
                            $capPre->execute([(int)$imgId, $itemId]);
                            $oldCap = (string)$capPre->fetchColumn();
                            $capStmt->execute([$newCap, (int)$imgId, $itemId]);
                            if ($oldCap !== $newCap) {
                                logActivity($pdo, (int)$client['id'], 'tire_image', (int)$imgId,
                                    'edited_image_caption', 'admin',
                                    "Edited caption on image #{$imgId}",
                                    mb_substr($oldCap, 0, 200) . ' → ' . mb_substr($newCap, 0, 200));
                            }
                        }
                    }

                    if (!empty($_POST['display_names']) && is_array($_POST['display_names'])) {
                        $namePre  = $pdo->prepare("SELECT display_name FROM tire_images WHERE id = ? AND tire_id = ?");
                        $nameStmt = $pdo->prepare("
                            UPDATE tire_images SET display_name = ?
                            WHERE id = ? AND tire_id = ?
                        ");
                        foreach ($_POST['display_names'] as $imgId => $rawName) {
                            $stem = safeFilenameStem($rawName);
                            $newName = $stem === '' ? null : $stem;
                            $namePre->execute([(int)$imgId, $itemId]);
                            $oldName = $namePre->fetchColumn();
                            if ($oldName === false) continue;
                            $oldName = $oldName === null ? null : (string)$oldName;
                            if (($oldName ?? '') === ($newName ?? '')) continue;
                            $nameStmt->execute([$newName, (int)$imgId, $itemId]);
                            $oldLabel = $oldName ?? '(unnamed)';
                            $newLabel = $newName ?? '(unnamed)';
                            $renamedFor = $newName ?? ('image #' . (int)$imgId);
                            logActivity($pdo, (int)$client['id'], 'tire_image', (int)$imgId,
                                'renamed_image', 'admin',
                                "Renamed {$renamedFor}",
                                $oldLabel . ' → ' . $newLabel);
                        }
                    }

                    if (!empty($_POST['remove_item_images']) && is_array($_POST['remove_item_images'])) {
                        $toRemove = array_values(array_filter(array_map('intval', $_POST['remove_item_images'])));
                        if ($toRemove) {
                            $ph  = implode(',', array_fill(0, count($toRemove), '?'));
                            $sel = $pdo->prepare("
                                SELECT id, image_url FROM tire_images
                                WHERE tire_id = ? AND id IN ($ph)
                            ");
                            $sel->execute(array_merge([$itemId], $toRemove));
                            foreach ($sel->fetchAll() as $row) {
                                if (strpos($row['image_url'], 'uploads/') === 0) {
                                    $path = __DIR__ . '/' . $row['image_url'];
                                    if (is_file($path)) { if (function_exists('previewDelete')) previewDelete($path); @unlink($path); }
                                }
                            }
                            $del = $pdo->prepare("
                                DELETE FROM tire_images WHERE tire_id = ? AND id IN ($ph)
                            ");
                            $del->execute(array_merge([$itemId], $toRemove));
                        }
                    }
                }

                // Replace category assignments
                $pdo->prepare("DELETE FROM tire_categories WHERE tire_id = ?")->execute([$itemId]);
                if (!empty($_POST['item_categories']) && is_array($_POST['item_categories'])) {
                    $insCat = $pdo->prepare("INSERT IGNORE INTO tire_categories (tire_id, category_id) VALUES (?, ?)");
                    foreach ($_POST['item_categories'] as $cid) {
                        $cid = (int)$cid;
                        if ($cid > 0) { $insCat->execute([$itemId, $cid]); }
                    }
                }

                // Reference images are uploaded through the Upload sheet (Assets → this tire → Upload; upload-chunk.php purpose=feature).

                $pdo->commit();
                $msg = $action === 'item_create' ? $sLabel . ' created.' : $sLabel . ' updated.';
                if ($errors) { $msg .= ' (Warnings: ' . implode(' ', $errors) . ')'; }
                $extra = $action === 'item_update' ? ['edit_item' => $itemId] : [];
                redirectHere($extra, $msg);
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

// --- Fetch for display -------------------------------------------
$allCategories = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order, name")->fetchAll();

$refOnlyTi = $refOnly !== '' ? ' AND ti.series_id IS NULL' : '';
$allItems = $pdo->prepare("
    SELECT t.id, t.name,
           (SELECT COUNT(*) FROM tire_images ti WHERE ti.tire_id = t.id{$refOnlyTi}) AS image_count,
           (SELECT GROUP_CONCAT(cat.name ORDER BY cat.sort_order SEPARATOR ', ')
            FROM tire_categories tc
            INNER JOIN categories cat ON cat.id = tc.category_id
            WHERE tc.tire_id = t.id) AS category_names
    FROM tires t
    WHERE t.company_id = ? AND t.module_id = ?
    ORDER BY t.name ASC
");
$allItems->execute([$client['id'], $module['id']]);
$allItems = $allItems->fetchAll();

$editItem      = null;
$editImages    = [];
$editCategories = [];
$editId        = (int)($_GET['edit_item'] ?? 0);
if ($editId > 0) {
    $s = $pdo->prepare("SELECT * FROM tires WHERE id = ? AND company_id = ? AND module_id = ?");
    $s->execute([$editId, $client['id'], $module['id']]);
    $editItem = $s->fetch();
    if ($editItem) {
        $hasUpdatedCol = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'updated_at'")->rowCount() > 0;
        $hasDisplayName = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0;
        $updatedSel = $hasUpdatedCol ? ', updated_at' : '';
        $nameSel    = $hasDisplayName ? ', display_name' : ", '' AS display_name";
        $imgStmt = $pdo->prepare("
            SELECT id, image_url, caption, status, client_comment{$updatedSel}{$nameSel}
            FROM tire_images
            WHERE tire_id = ?{$refOnly} ORDER BY sort_order ASC
        ");
        $imgStmt->execute([$editId]);
        $editImages = $imgStmt->fetchAll();
        // Render series of this item (folders under media/tires/<slug>/ or uploads) — reviewed in Assets, summarised here.
        $editSeries = hasTireSeries($pdo) ? tireSeriesCounts($pdo, $editId) : null;

        $catStmt = $pdo->prepare("SELECT category_id FROM tire_categories WHERE tire_id = ?");
        $catStmt->execute([$editId]);
        $editCategories = array_map('intval', array_column($catStmt->fetchAll(), 'category_id'));
    }
}

$isEdit        = (bool)$editItem;
$formAction    = $isEdit ? 'item_update' : 'item_create';
$formTitle     = $isEdit ? 'Edit ' . $sLower . ' — ' . $editItem['name'] : 'New ' . $sLower;
$formSubmit    = $isEdit ? 'Save changes' : 'Create ' . $sLower;
$val_item_name = $isEdit ? $editItem['name'] : '';

function selfUrl($extra = []) {
    global $client, $module;
    $qs = array_merge(['client' => $client['slug'], 'module' => $module['slug']], $extra);
    return portalUrl('add-feature', $qs);
}

// Inline behaviour (status chips, Replace, Remove, category chips) — printed after </main> by layout-bottom.php.
$tireScript = <<<'JS'
<script>
  // Replace goes through upload-chunk.php (purpose=replace): one request for a small file, pieces for a large one
  // (chunk-upload.js), so the host's upload_max_filesize no longer caps it. New reference images: the Upload sheet (Assets).
  const UPLOAD_ENDPOINT = __UPLOAD_ENDPOINT__;
  const CLIENT_SLUG     = __CLIENT_SLUG__;
  const MAX_IMAGE_MB    = __MAX_IMAGE_MB__;
  const chunkUp = window.App && window.App.chunkUpload;
  function uploadOne(file, fields, onProgress) {
    if (!chunkUp || !chunkUp.upload) {
      return { promise: Promise.reject({ error: 'Uploads need chunk-upload.js' }), abort: function () {} };
    }
    return chunkUp.upload({ endpoint: UPLOAD_ENDPOINT, file: file, fields: Object.assign({ client: CLIENT_SLUG, actor: 'admin' }, fields), onProgress: onProgress });
  }

  document.querySelectorAll('[data-tire-remove]').forEach(cb => {
    cb.addEventListener('change', () => {
      cb.closest('[data-tire-row]').classList.toggle('marked', cb.checked);
    });
  });

  document.querySelectorAll('[data-cat-chip]').forEach(chip => {
    const cb = chip.querySelector('input[type="checkbox"]');
    if (!cb) return;
    cb.addEventListener('change', () => { chip.classList.toggle('is-active', cb.checked); });
  });

  // ---- Inline status change (To Review / Approved / Needs changes) -------------
  // "Needs changes" asks for the note first (tire-status.php requires >= 3 characters), in the shared sheet —
  // the same rule and wording as the post / email / page "Needs changes…" flows.
  const NOTE_MIN = 3;
  let noteRow = null;
  function noteEsc(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function askNote(row) {
    const App = window.App;
    if (!App || !App.sheet || !document.getElementById('uiSheet')) {   // no sheet shell: a plain prompt
      const t = window.prompt('What should change? (at least ' + NOTE_MIN + ' characters)');
      if (t !== null && t.trim().length >= NOTE_MIN) saveStatus(row, 'denied', t.trim());
      return;
    }
    noteRow = row;
    const name = (row.querySelector('[data-display-name]') || {}).value || '';
    const html = '<form class="tl-note" data-tl-note-form novalidate>'
      + '<label class="studio-label" for="tlNote">What should change' + (name ? ' in <strong>' + noteEsc(name) + '</strong>' : '') + '?</label>'
      + '<textarea class="ui-textarea" id="tlNote" data-tl-note data-sheet-autofocus rows="3" minlength="' + NOTE_MIN + '" maxlength="2000" placeholder="What should change?" required></textarea>'
      + '<p class="studio-help" data-tl-note-hint>A short note is required (at least ' + NOTE_MIN + ' characters). It is added to the image\'s comments.</p>'
      + '</form>';
    const footer = '<div class="ui-btn-group">'
      + '<button type="button" class="ui-btn ui-btn--large ui-btn--gray" data-sheet-close>Cancel</button>'
      + '<button type="button" class="ui-btn ui-btn--large ui-btn--deny ui-btn--primary" data-tl-note-submit disabled>Needs changes</button></div>';
    App.sheet.open('#uiSheet', { title: 'Needs changes', html: html, footer: footer });
  }
  function noteValid() {
    const ta = document.querySelector('[data-tl-note]'), btn = document.querySelector('[data-tl-note-submit]'), hint = document.querySelector('[data-tl-note-hint]');
    const len = ta ? ta.value.trim().length : 0, ok = len >= NOTE_MIN;
    if (btn) btn.disabled = !ok;
    if (hint) hint.classList.toggle('is-error', !ok && len > 0);
    return ok;
  }
  function sendNote() {
    if (!noteRow || !noteValid()) { const ta = document.querySelector('[data-tl-note]'); if (ta) ta.focus(); return; }
    const row = noteRow, text = document.querySelector('[data-tl-note]').value.trim();
    noteRow = null;
    window.App.sheet.close();
    saveStatus(row, 'denied', text);
  }
  document.addEventListener('input', (e) => { if (e.target.matches && e.target.matches('[data-tl-note]')) noteValid(); });
  document.addEventListener('submit', (e) => { if (e.target.matches && e.target.matches('[data-tl-note-form]')) { e.preventDefault(); sendNote(); } });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && e.target.matches && e.target.matches('[data-tl-note]')) { e.preventDefault(); sendNote(); }
  });
  document.addEventListener('click', (e) => { if (e.target.closest('[data-tl-note-submit]')) { e.preventDefault(); sendNote(); } });
  document.addEventListener('sheet:close', () => { noteRow = null; });

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-status-set]');
    if (!btn) return;
    const row     = btn.closest('[data-tire-row]');
    const next    = btn.getAttribute('data-status-set');
    if (next === row.getAttribute('data-status')) return;
    if (next === 'denied') { askNote(row); return; }
    saveStatus(row, next, '');
  });

  async function saveStatus(row, next, note) {
    const imageId = row.getAttribute('data-image-id');
    const allBtns = row.querySelectorAll('[data-status-set]');
    const hint    = row.querySelector('[data-status-hint]');
    allBtns.forEach(b => b.disabled = true);
    if (hint) { hint.className = 'tl-status-hint'; hint.textContent = 'Saving…'; }

    try {
      const fd = new FormData();
      fd.append('id', imageId);
      fd.append('status', next);
      fd.append('actor', 'admin');
      if (note) fd.append('comment', note);   // Needs changes: the note (also the image's latest comment)
      const res  = await fetch('tire-status.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');

      row.setAttribute('data-status', next);
      allBtns.forEach(b => { const on = b.getAttribute('data-status-set') === next; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      if (hint) {
        hint.className = 'tl-status-hint saved';
        hint.textContent = 'Saved';
        setTimeout(() => {
          if (hint.textContent === 'Saved') {
            hint.className = 'tl-status-hint';
            hint.textContent = '';
          }
        }, 2000);
      }
      // Bump the "Last updated" stamp in place — server set updated_at to NOW().
      const meta = row.querySelector('[data-updated-meta]');
      if (meta) meta.textContent = 'Last updated just now';
    } catch (err) {
      if (hint) {
        hint.className = 'tl-status-hint error';
        hint.textContent = 'Save failed — try again';
      }
    } finally {
      allBtns.forEach(b => b.disabled = false);
    }
  }

  // ---- Image replacement (admin tire-edit) -------------------------
  // One hidden file input shared by every per-row Replace button.
  const replaceInput = document.createElement('input');
  replaceInput.type = 'file';
  replaceInput.accept = 'image/jpeg,image/png,image/gif,image/webp';
  replaceInput.style.display = 'none';
  document.body.appendChild(replaceInput);
  let pendingReplaceBtn = null;

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-replace-tire-img]');
    if (!btn) return;
    pendingReplaceBtn = btn;
    replaceInput.value = '';
    replaceInput.click();
  });

  replaceInput.addEventListener('change', async () => {
    if (!replaceInput.files.length || !pendingReplaceBtn) return;
    const file = replaceInput.files[0];
    if (file.size > MAX_IMAGE_MB * 1024 * 1024) {
      alert('Image exceeds ' + MAX_IMAGE_MB + ' MB');
      pendingReplaceBtn = null;
      return;
    }

    const row     = pendingReplaceBtn.closest('[data-tire-row]');
    const frame   = pendingReplaceBtn.closest('[data-thumb-frame]');
    const imgEl   = row.querySelector('[data-thumb-img]');
    const imageId = row.getAttribute('data-image-id');
    const original = pendingReplaceBtn.textContent;
    const btn = pendingReplaceBtn;

    frame.classList.add('replacing');
    btn.disabled = true;
    btn.textContent = 'Uploading…';

    try {
      let data;
      if (chunkUp && chunkUp.upload) {
        // upload-chunk.php purpose=replace: one request for a small file, pieces for a large one; same reply as replace-image.php
        data = await uploadOne(file, { purpose: 'replace', replace_kind: 'tire', replace_id: imageId }, p => { btn.textContent = p.pct + '%'; }).promise;
      } else {
        const fd = new FormData();
        fd.append('image_id', imageId);
        fd.append('image', file);
        fd.append('type', 'tire');
        const res = await fetch('replace-image.php', { method: 'POST', body: fd });
        data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
      }
      // Cache-bust in case the same filename gets reused
      const fresh = data.thumb || data.src || data.image_url;   // thumb: the sm preview of the new file; src: ready-to-use URL (series renders live under /media/tires/)
      const bust = fresh + (fresh.includes('?') ? '&' : '?') + 't=' + Date.now();
      imgEl.removeAttribute('srcset'); imgEl.removeAttribute('sizes');   // else the browser keeps the old candidates
      imgEl.src = bust;
    } catch (err) {
      alert('Replace failed: ' + ((err && (err.error || err.message)) || 'unknown'));
    } finally {
      frame.classList.remove('replacing');
      btn.disabled = false;
      btn.textContent = original;
      pendingReplaceBtn = null;
    }
  });
</script>
JS;

// ---- Chrome: the shared shell. Parent = the Tires tab (this tire when editing) ----
$tiresWord   = tiresLabel($client);
$tiresUrl    = clientUrl('assets.php', ['view' => 'collections']);
$tireUrl     = $isEdit ? clientUrl('assets.php', ['view' => 'collections', 'item' => (int)$editItem['id']]) : $tiresUrl;
$pageTitle   = $isEdit ? 'Edit ' . $sLower : 'New ' . $sLower;
$htmlTitle   = ($isEdit ? $editItem['name'] . ' — Edit' : 'New ' . $sLower) . ' — ' . $client['name'];
$navSubtitle = $client['name'] . ' · ' . $tiresWord;
$navBack     = $isEdit ? ['href' => $tireUrl, 'label' => mb_strimwidth((string)$editItem['name'], 0, 28, '…')]
                       : ['href' => $tiresUrl, 'label' => $tiresWord];
$activeTab   = 'tires';
$bodyClass   = 'page-studio page-tool page-tire-form';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/tools.css')) . '">';
$footExtra   = '<script src="' . h(staticUrl('js/chunk-upload.js')) . '"></script>' . "\n" . str_replace(
    ['__UPLOAD_ENDPOINT__', '__CLIENT_SLUG__', '__MAX_IMAGE_MB__'],
    [json_encode(basePath() . '/upload-chunk.php?client=' . rawurlencode($client['slug'])), json_encode($client['slug']), (string)(int)$maxFileMb],
    $tireScript);
include __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash): ?>
  <div class="studio-alert studio-alert--ok" role="status"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="studio-alert studio-alert--error" role="alert">
    <?php foreach ($errors as $err): ?><div><?= h($err) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" action="<?= h(clientUrl('add-feature.php', ['module' => $module['slug']])) ?>" enctype="multipart/form-data" class="ui-card tl-card" data-tire-form>
  <input type="hidden" name="action" value="<?= h($formAction) ?>">
  <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
  <?php endif; ?>
  <div class="ui-card-header"><div class="ui-card-heading">
    <h3 class="ui-card-title"><?= $isEdit ? h($editItem['name']) : 'New ' . h($sLower) ?></h3>
    <p class="ui-card-subtitle"><?= $isEdit
        ? 'Name, categories and the reference photos of the real ' . h($sLower) . '. Series images are reviewed in ' . h($tiresWord) . '.'
        : 'Name it first. Then add reference photos and series images from its page in ' . h($tiresWord) . '.' ?></p>
  </div></div>
  <div class="ui-card-body tl-fields">
    <div class="studio-field">
      <label class="studio-label" for="item_name"><?= h($sLabel) ?> name</label>
      <input class="ui-input" type="text" name="item_name" id="item_name" value="<?= h($val_item_name) ?>" placeholder="e.g. Kenda Klever R/T" required<?= $isEdit ? '' : ' autofocus' ?>>
    </div>

    <div class="studio-field">
      <span class="studio-label">Categories <span class="text-tertiary">— any number</span></span>
      <div class="studio-chips studio-chips--wrap">
        <?php foreach ($allCategories as $cat): $checked = in_array((int)$cat['id'], $editCategories, true); ?>
          <label class="studio-chip<?= $checked ? ' is-active' : '' ?>" data-cat-chip>
            <input type="checkbox" name="item_categories[]" value="<?= (int)$cat['id'] ?>"<?= $checked ? ' checked' : '' ?>>
            <?= h($cat['name']) ?>
          </label>
        <?php endforeach; ?>
        <?php if (!$allCategories): ?><span class="text-secondary">No categories yet.</span><?php endif; ?>
      </div>
    </div>

    <?php if ($isEdit && $editImages): ?>
      <div class="studio-field" data-tire-images>
        <span class="studio-label">Reference photos <span class="text-tertiary">— <?= count($editImages) ?> of <?= $maxItemImages ?> · status saves instantly</span></span>
        <ul class="tl-images" role="list">
          <?php foreach ($editImages as $img):
            $imgStatus  = $img['status'] ?? 'pending';
            $threadHtml = renderCommentThread($pdo, 'tire_image', (int)$img['id']);
            $imgExt     = strtolower(pathinfo($img['image_url'], PATHINFO_EXTENSION) ?: 'jpg');
          ?>
            <li class="tl-image" data-tire-row data-image-id="<?= (int)$img['id'] ?>" data-status="<?= h($imgStatus) ?>">
              <div class="tl-image-thumb" data-thumb-frame>
                <?= pvImg(tireImageSrc($img), 'sm', ['sizes' => '96px', 'attrs' => ['data-thumb-img' => '']]) ?>
                <button type="button" class="tl-image-replace" data-replace-tire-img title="Replace this image">Replace</button>
              </div>
              <div class="tl-image-body">
                <div class="tl-image-name">
                  <input class="ui-input" type="text" name="display_names[<?= (int)$img['id'] ?>]" value="<?= h($img['display_name'] ?? '') ?>"
                         placeholder="File name for downloads" aria-label="File name for downloads" data-display-name>
                  <span class="tl-image-ext">.<?= h($imgExt) ?></span>
                </div>
                <input class="ui-input" type="text" name="captions[<?= (int)$img['id'] ?>]" value="<?= h($img['caption']) ?>" placeholder="Caption" aria-label="Caption">
                <div class="tl-image-status" role="group" aria-label="Review status">
                  <?php foreach (['pending' => 'To Review', 'approved' => 'Approved', 'denied' => 'Needs changes'] as $sk => $sl): $on = $imgStatus === $sk; ?>
                    <button type="button" class="studio-chip<?= $on ? ' is-active' : '' ?>" data-status-set="<?= $sk ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= $sl ?></button>
                  <?php endforeach; ?>
                  <span class="tl-status-hint" data-status-hint aria-live="polite"></span>
                </div>
                <?php if (!empty($img['updated_at'])): ?>
                  <div class="tl-image-meta" title="<?= h(absoluteTime($img['updated_at'])) ?>" data-updated-meta>Last updated <?= h(relativeTime($img['updated_at'])) ?></div>
                <?php endif; ?>
                <?php if ($threadHtml): ?>
                  <div class="tl-comments"><?= $threadHtml ?></div>
                <?php endif; ?>
              </div>
              <label class="tl-image-remove">
                <input type="checkbox" name="remove_item_images[]" value="<?= (int)$img['id'] ?>" data-tire-remove>
                Remove
              </label>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="studio-help">To reply to a comment, open the image on <a href="<?= h(clientUrl('assets.php', ['view' => 'collections', 'item' => (int)$editItem['id'], 'series' => 'ref'])) ?>">the <?= h($sLower) ?>'s page</a>.</p>
      </div>
    <?php endif; ?>

    <?php if ($isEdit): ?>
      <div class="studio-field">
        <span class="studio-label">Add reference photos</span>
        <?php // The Upload sheet (upload-sheet.js) is the one uploader: the tire page opens it on this tire's Reference ?>
        <div class="tl-actions tl-actions--start" style="margin-top:0">
          <a class="ui-btn ui-btn--tinted" href="<?= h(uploadSheetUrl('assets.php', ['dest' => 'reference', 'tire' => (int)$editItem['id']], ['view' => 'collections', 'item' => (int)$editItem['id'], 'series' => 'ref'])) ?>" data-reference-upload><?= icon('upload') ?><span>Upload reference photos</span></a>
        </div>
        <p class="studio-help">Opens the Upload sheet on this <?= h($sLower) ?>'s Reference · up to <?= $maxItemImages ?> per <?= h($sLower) ?>, <?= $maxFileMb ?> MB each (large files go up in pieces). Save your changes here first.</p>
      </div>
    <?php endif; ?>

    <?php if ($isEdit && !empty($editSeries)):
      // Series images are reviewed on the tire's page (approve / deny per image or per series), not here.
      $editTireRow = tireWithSlug($pdo, (int)$editItem['id']);
      $hasRenders  = $editSeries['series_count'] > 0 || $editSeries['render_count'] > 0;
    ?>
      <div class="studio-field" data-tire-series-summary>
        <span class="studio-label">Series</span>
        <p class="studio-help">
          <?php if ($hasRenders): ?>
            <?= (int)$editSeries['series_count'] ?> series · <?= (int)$editSeries['render_count'] ?> images
            (<?= (int)array_sum(array_column($editSeries['series'], 'pending')) ?> to review) —
            <a href="<?= h($tireUrl) ?>">review them on the <?= h($sLower) ?>'s page</a>.
          <?php else: ?>
            No series yet.
          <?php endif; ?>
          <?php if ($editTireRow): ?>
            Drop folders into <code class="tl-code"><?= h(tireFolderRel($client, $editTireRow)) ?>/&lt;series&gt;/</code> or upload from the <?= h($sLower) ?>'s page.
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <div class="tl-actions">
      <a class="ui-btn ui-btn--gray" href="<?= h($tireUrl) ?>">Cancel</a>
      <button type="submit" class="ui-btn ui-btn--filled"><?= h($formSubmit) ?></button>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
  <div class="tl-danger-zone">
    <form method="POST" action="<?= h(clientUrl('add-feature.php', ['module' => $module['slug']])) ?>"
          onsubmit="return confirm(<?= h(json_encode('Delete “' . $editItem['name'] . '” and all of its images? This cannot be undone.')) ?>);" data-tire-delete>
      <input type="hidden" name="action" value="item_delete">
      <input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
      <button type="submit" class="ui-btn ui-btn--plain studio-danger-btn">Delete this <?= h($sLower) ?></button>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
