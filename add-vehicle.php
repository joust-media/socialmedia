<?php
/**
 * Vehicle Library — create / edit / delete a single vehicle.
 * Global (not client-scoped). Redirects back to vehicles.php after a save.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/prompt-lib.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!hasVehiclesTable($pdo)) {
    header('Location: vehicles.php?msg=' . urlencode('Run migrate first — the vehicles table is missing.'));
    exit;
}

// -------------------------------------------------------------
// Config
// -------------------------------------------------------------
$uploadsDir  = __DIR__ . '/uploads';
$uploadsUrl  = 'uploads';
$allowedExt  = imageExts();                 // jpg/jpeg/png/gif/webp
$maxFileSize = 25 * 1024 * 1024;            // 25 MB
$maxImages   = 10;                          // per vehicle

$errors = [];
$flash  = $_GET['msg'] ?? '';

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Delete -----------------------------------------------
    if ($action === 'delete') {
        $vehicleId = (int)($_POST['id'] ?? 0);
        if ($vehicleId > 0) {
            try {
                // Remove image files from disk; the DB rows cascade with the vehicle.
                $imgs = $pdo->prepare("SELECT image_url FROM vehicle_images WHERE vehicle_id = ?");
                $imgs->execute([$vehicleId]);
                foreach ($imgs->fetchAll() as $row) {
                    if (strpos($row['image_url'], 'uploads/') === 0) {
                        $path = __DIR__ . '/' . $row['image_url'];
                        if (is_file($path)) { @unlink($path); }
                    }
                }
                $pdo->prepare("DELETE FROM vehicles WHERE id = ?")->execute([$vehicleId]);
                header('Location: vehicles.php?msg=' . urlencode('Vehicle deleted.'));
                exit;
            } catch (Exception $e) {
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Invalid vehicle id.';
        }
    }

    // ---- Create / Update --------------------------------------
    if ($action === 'create' || $action === 'update') {
        $manufacturer = trim($_POST['manufacturer'] ?? '');
        $model        = trim($_POST['model'] ?? '');
        $vehicleType  = trim($_POST['vehicle_type'] ?? '');
        $yearRaw      = trim($_POST['model_year'] ?? '');

        if (mb_strlen($manufacturer) > 120) { $manufacturer = mb_substr($manufacturer, 0, 120); }
        if (mb_strlen($model) > 120)        { $model        = mb_substr($model, 0, 120); }
        if (mb_strlen($vehicleType) > 80)   { $vehicleType  = mb_substr($vehicleType, 0, 80); }

        if ($manufacturer === '') { $errors[] = 'Manufacturer is required.'; }
        if ($model === '')        { $errors[] = 'Model is required.'; }

        $modelYear = null;
        if ($yearRaw !== '') {
            if (!ctype_digit($yearRaw) || (int)$yearRaw < 1900 || (int)$yearRaw > 2100) {
                $errors[] = 'Year must be a number between 1900 and 2100.';
            } else {
                $modelYear = (int)$yearRaw;
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO vehicles (manufacturer, model, model_year, vehicle_type)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $manufacturer, $model, $modelYear,
                        $vehicleType === '' ? null : $vehicleType,
                    ]);
                    $vehicleId = (int)$pdo->lastInsertId();
                } else {
                    $vehicleId = (int)($_POST['id'] ?? 0);
                    if ($vehicleId <= 0) { throw new Exception('Invalid vehicle id.'); }
                    $stmt = $pdo->prepare("
                        UPDATE vehicles
                        SET manufacturer = ?, model = ?, model_year = ?, vehicle_type = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $manufacturer, $model, $modelYear,
                        $vehicleType === '' ? null : $vehicleType,
                        $vehicleId,
                    ]);

                    // Remove ticked existing images.
                    if (!empty($_POST['remove_images']) && is_array($_POST['remove_images'])) {
                        $toRemove = array_values(array_filter(array_map('intval', $_POST['remove_images'])));
                        if ($toRemove) {
                            $ph  = implode(',', array_fill(0, count($toRemove), '?'));
                            $sel = $pdo->prepare("
                                SELECT id, image_url FROM vehicle_images
                                WHERE vehicle_id = ? AND id IN ($ph)
                            ");
                            $sel->execute(array_merge([$vehicleId], $toRemove));
                            foreach ($sel->fetchAll() as $row) {
                                if (strpos($row['image_url'], 'uploads/') === 0) {
                                    $path = __DIR__ . '/' . $row['image_url'];
                                    if (is_file($path)) { @unlink($path); }
                                }
                            }
                            $del = $pdo->prepare("
                                DELETE FROM vehicle_images WHERE vehicle_id = ? AND id IN ($ph)
                            ");
                            $del->execute(array_merge([$vehicleId], $toRemove));
                        }
                    }
                }

                // Handle new image uploads (images only).
                if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM vehicle_images WHERE vehicle_id = ?");
                    $cnt->execute([$vehicleId]);
                    $existing = (int)$cnt->fetchColumn();

                    $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM vehicle_images WHERE vehicle_id = ?");
                    $sortQ->execute([$vehicleId]);
                    $sortOrder = (int)$sortQ->fetchColumn();

                    $slots = $maxImages - $existing;
                    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

                    $uploaded = 0;
                    foreach ($_FILES['images']['name'] as $i => $origName) {
                        if ($uploaded >= $slots) {
                            $errors[] = "Max {$maxImages} images per vehicle — some were skipped.";
                            break;
                        }
                        $err = $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                        if ($err === UPLOAD_ERR_NO_FILE) { continue; }
                        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                            $iniMax = ini_get('upload_max_filesize') ?: '?';
                            $errors[] = "'{$origName}' is too large for this server (PHP limit: {$iniMax}).";
                            continue;
                        }
                        if ($err !== UPLOAD_ERR_OK) {
                            $errors[] = "Upload error on '{$origName}' (code {$err}).";
                            continue;
                        }
                        if ($_FILES['images']['size'][$i] > $maxFileSize) {
                            $mb = number_format($maxFileSize / (1024 * 1024), 0);
                            $errors[] = "'{$origName}' exceeds {$mb} MB.";
                            continue;
                        }
                        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowedExt, true)) {
                            $errors[] = "'{$origName}' is not a supported image (JPG, PNG, GIF, WebP).";
                            continue;
                        }
                        if (@getimagesize($_FILES['images']['tmp_name'][$i]) === false) {
                            $errors[] = "'{$origName}' is not a valid image.";
                            continue;
                        }
                        $newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '',
                                   uniqid('veh_', true) . '.' . $ext);
                        $dest    = $uploadsDir . '/' . $newName;
                        if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $dest)) {
                            $sortOrder++;
                            $pdo->prepare("
                                INSERT INTO vehicle_images (vehicle_id, image_url, sort_order)
                                VALUES (?, ?, ?)
                            ")->execute([$vehicleId, $uploadsUrl . '/' . $newName, $sortOrder]);
                            $uploaded++;
                        } else {
                            $errors[] = "Failed to save '{$origName}'. Check uploads/ permissions.";
                        }
                    }
                }

                $pdo->commit();
                $msg = $action === 'create' ? 'Vehicle created.' : 'Vehicle updated.';
                if ($errors) { $msg .= ' (Warnings: ' . implode(' ', $errors) . ')'; }
                header('Location: vehicles.php?msg=' . urlencode($msg));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// Load for display (edit mode)
// -------------------------------------------------------------
$editVehicle = null;
$editImages  = [];
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmt->execute([$editId]);
    $editVehicle = $stmt->fetch();
    if ($editVehicle) {
        $imgStmt = $pdo->prepare("
            SELECT id, image_url FROM vehicle_images
            WHERE vehicle_id = ? ORDER BY sort_order ASC, id ASC
        ");
        $imgStmt->execute([$editId]);
        $editImages = $imgStmt->fetchAll();
    }
}

$isEdit     = (bool)$editVehicle;
$formAction = $isEdit ? 'update' : 'create';

$postedBack    = ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors);
$val_make = $postedBack ? ($_POST['manufacturer'] ?? '')
          : ($isEdit ? $editVehicle['manufacturer'] : '');
$val_model = $postedBack ? ($_POST['model'] ?? '')
           : ($isEdit ? $editVehicle['model'] : '');
$val_year = $postedBack ? ($_POST['model_year'] ?? '')
          : ($isEdit ? (string)($editVehicle['model_year'] ?? '') : '');
$val_type = $postedBack ? ($_POST['vehicle_type'] ?? '')
          : ($isEdit ? (string)($editVehicle['vehicle_type'] ?? '') : '');

$formTitle      = $isEdit ? 'Edit vehicle' : 'New vehicle';
$formSubmitText = $isEdit ? 'Save changes' : 'Create vehicle';

// ---- Chrome: the shared shell (back to the Vehicle Library) ----
$pageTitle   = $isEdit ? 'Edit vehicle' : 'New vehicle';
$htmlTitle   = $pageTitle . ' — Vehicle Library';
$navSubtitle = 'Vehicle Library';
$navBack     = ['href' => pagePath('vehicles'), 'label' => 'Vehicles'];
$navTrailing = '';
$activeTab   = 'manage';
$bodyClass   = 'page-studio page-tool page-vehicle-form';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/tools.css')) . '">' . "\n"
             . '<style>.studio-dropzone { position: relative; } .tl-files { margin: 8px 0 0; padding: 0; list-style: none; font-size: var(--text-footnote); color: var(--label-secondary); } .tl-files li + li { margin-top: 2px; }</style>';
$footExtra = <<<'JS'
<script>
  // Chosen files, listed under the drop zone
  const fileInput = document.getElementById('images');
  const fileList  = document.getElementById('fileList');
  if (fileInput) {
    fileInput.addEventListener('change', () => {
      fileList.innerHTML = '';
      [...fileInput.files].forEach(f => {
        const li = document.createElement('li');
        li.textContent = f.name + ' · ' + (f.size / 1024 / 1024).toFixed(2) + ' MB';
        fileList.appendChild(li);
      });
    });
  }
  // Drag & drop onto the zone
  document.querySelectorAll('[data-dropzone]').forEach(drop => {
    const input = drop.querySelector('input[type="file"]');
    if (!input) return;
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('is-dragover'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('is-dragover'); }));
    drop.addEventListener('drop', e => {
      if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; input.dispatchEvent(new Event('change')); }
    });
  });
  // Tiles ticked for removal fade
  document.querySelectorAll('[data-remove-checkbox]').forEach(cb => {
    cb.addEventListener('change', () => { cb.closest('[data-img-wrap]').classList.toggle('marked', cb.checked); });
  });
</script>
JS;
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

<form method="POST" action="<?= h(pagePath('add-vehicle') . ($isEdit ? '?edit=' . (int)$editVehicle['id'] : '')) ?>"
      enctype="multipart/form-data" id="vehicleForm" class="ui-card tl-card">
  <input type="hidden" name="action" value="<?= h($formAction) ?>">
  <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?= (int)$editVehicle['id'] ?>">
  <?php endif; ?>
  <div class="ui-card-header"><div class="ui-card-heading">
    <h3 class="ui-card-title"><?= h($formTitle) ?></h3>
    <p class="ui-card-subtitle">Picked in the AI Builder: its photos become reference images and its details fill the vehicle variables.</p>
  </div>
  <?php if ($isEdit): ?><div class="tl-card-actions"><a class="ui-btn ui-btn--gray ui-btn--sm" href="<?= h(pagePath('add-vehicle')) ?>">New vehicle instead</a></div><?php endif; ?>
  </div>
  <div class="ui-card-body tl-fields">
    <div class="tl-grid">
      <div class="studio-field">
        <label class="studio-label" for="manufacturer">Manufacturer</label>
        <input class="ui-input" type="text" name="manufacturer" id="manufacturer" maxlength="120" required value="<?= h($val_make) ?>" placeholder="e.g. Yamaha">
      </div>
      <div class="studio-field">
        <label class="studio-label" for="model">Model</label>
        <input class="ui-input" type="text" name="model" id="model" maxlength="120" required value="<?= h($val_model) ?>" placeholder="e.g. YXZ1000R">
      </div>
      <div class="studio-field">
        <label class="studio-label" for="model_year">Year <span class="text-tertiary">optional</span></label>
        <input class="ui-input" type="number" name="model_year" id="model_year" min="1900" max="2100" value="<?= h($val_year) ?>" placeholder="e.g. 2024">
        <p class="studio-help">Fills <code class="tl-code">{{vehicle_year}}</code>.</p>
      </div>
      <div class="studio-field">
        <label class="studio-label" for="vehicle_type">Vehicle type <span class="text-tertiary">optional</span></label>
        <input class="ui-input" type="text" name="vehicle_type" id="vehicle_type" maxlength="80" value="<?= h($val_type) ?>" placeholder="e.g. UTV, ATV, dirt bike">
        <p class="studio-help">Fills <code class="tl-code">{{vehicle_type}}</code>.</p>
      </div>
    </div>

    <?php if ($isEdit && $editImages): ?>
      <div class="studio-field">
        <span class="studio-label">Images <span class="text-tertiary">— tick to remove on save</span></span>
        <ul class="tl-thumbs" role="list">
          <?php foreach ($editImages as $img): ?>
            <li class="tl-thumb" data-img-wrap>
              <?= pvImg(tireImageSrc((string)$img['image_url']), 'sm', ['sizes' => '120px', 'alt' => '']) ?>
              <label class="tl-thumb-remove"><input type="checkbox" name="remove_images[]" value="<?= (int)$img['id'] ?>" data-remove-checkbox> Remove</label>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="studio-help"><?= count($editImages) ?> of <?= $maxImages ?> image slots used.</p>
      </div>
    <?php endif; ?>

    <div class="studio-field">
      <span class="studio-label"><?= $isEdit ? 'Add more images' : 'Vehicle images' ?></span>
      <label class="studio-dropzone studio-dropzone--sm" data-dropzone>
        <input type="file" name="images[]" id="images" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
        <span class="studio-dropzone-icon" aria-hidden="true"><?= icon('upload') ?></span>
        <span class="studio-dropzone-label">Choose images</span>
        <span class="studio-dropzone-hint">or drop them here — up to <?= $maxImages ?> per vehicle, <?= (int)($maxFileSize / (1024 * 1024)) ?> MB each. JPG, PNG, GIF, WebP.</span>
      </label>
      <ul class="tl-files" id="fileList" aria-live="polite"></ul>
    </div>

    <div class="tl-actions">
      <a class="ui-btn ui-btn--gray" href="<?= h(pagePath('vehicles')) ?>">Cancel</a>
      <button type="submit" class="ui-btn ui-btn--filled"><?= h($formSubmitText) ?></button>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
  <div class="tl-danger-zone">
    <form method="POST" action="<?= h(pagePath('add-vehicle')) ?>" onsubmit="return confirm('Delete this vehicle and all its images? This cannot be undone.');">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$editVehicle['id'] ?>">
      <button type="submit" class="ui-btn ui-btn--plain studio-danger-btn">Delete this vehicle</button>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
