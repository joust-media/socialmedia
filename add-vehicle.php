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
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $isEdit ? 'Edit vehicle' : 'Add a vehicle' ?> — Joust Admin</title>
<?= renderAppHead() ?>
<style>
  :root {
    --bg: #f0f2f5; --surface: #ffffff; --surface-2: #f7f8fa;
    --border: #dadde1; --text: #050505; --text-muted: #65676b;
    --accent: #1877f2; --accent-hover: #166fe5;
    --danger: #dc2626; --success: #16a34a;
    --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04);
  }
  [data-theme="dark"] {
    --bg: #18191a; --surface: #242526; --surface-2: #3a3b3c;
    --border: #3e4042; --text: #e4e6eb; --text-muted: #b0b3b8;
    --accent: #2d88ff; --accent-hover: #4599ff;
    --danger: #ef4444; --success: #16a34a;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text);
         font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
         font-size: 15px; line-height: 1.4; min-height: 100vh; }
  .topbar { position: sticky; top: 0; z-index: 100;
            background: var(--surface); border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow); }
  .topbar-inner { max-width: 860px; margin: 0 auto; padding: 12px 20px;
                  display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .brand { display: flex; align-items: center; gap: 10px;
           font-weight: 700; font-size: 20px; color: var(--accent); letter-spacing: -0.5px; }
  .brand-mark { width: 32px; height: 32px; border-radius: 8px;
                background: var(--accent); color: #fff;
                display: flex; align-items: center; justify-content: center; font-weight: 800; }
  .brand-sub { font-size: 12px; font-weight: 600; color: var(--text-muted);
               text-transform: uppercase; letter-spacing: 1px;
               padding: 3px 8px; border-radius: 4px;
               background: var(--surface-2); border: 1px solid var(--border); }
  .top-actions { display: flex; gap: 8px; align-items: center; }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px;
         padding: 8px 14px; border-radius: 8px; font-size: 14px; font-weight: 600;
         cursor: pointer; border: 1px solid var(--border);
         background: var(--surface-2); color: var(--text);
         text-decoration: none; transition: background 0.15s, transform 0.1s; }
  .btn:hover { background: var(--border); }
  .btn:active { transform: scale(0.98); }
  .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .btn.primary:hover { background: var(--accent-hover); }
  .btn.danger { background: var(--danger); color: #fff; border-color: var(--danger); }
  .btn.sm { padding: 6px 10px; font-size: 13px; }

  .wrap { max-width: 860px; margin: 0 auto; padding: 24px 20px 80px; }
  .flash, .errors { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
                    font-size: 14px; font-weight: 500; }
  .flash  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .errors { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  [data-theme="dark"] .flash  { background: #14532d; color: #bbf7d0; border-color: #166534; }
  [data-theme="dark"] .errors { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }

  .card { background: var(--surface); border: 1px solid var(--border);
          border-radius: 12px; box-shadow: var(--shadow);
          margin-bottom: 24px; overflow: hidden; }
  .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border);
                 display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .card-title { font-size: 17px; font-weight: 700; margin: 0; }
  .card-body { padding: 20px; }

  .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
  .field label { font-size: 13px; font-weight: 600; color: var(--text-muted);
                 text-transform: uppercase; letter-spacing: 0.5px; }
  .field input[type="text"], .field input[type="number"] {
    background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text); padding: 10px 12px; border-radius: 8px;
    font: inherit; width: 100%; font-size: 15px;
  }
  .field input:focus { outline: none; border-color: var(--accent);
                       box-shadow: 0 0 0 3px rgba(24,119,242,0.15); }
  .field .help { font-size: 12px; color: var(--text-muted); }
  .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 640px) { .field-row { grid-template-columns: 1fr; } }

  .existing-images { display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; margin-top: 8px; }
  .existing-img { position: relative; aspect-ratio: 1/1; border-radius: 8px; overflow: hidden;
                  border: 2px solid var(--border); background: var(--surface-2); }
  .existing-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .existing-img.marked { opacity: 0.35; border-color: var(--danger); }
  .existing-img input[type="checkbox"] { position: absolute; top: 8px; right: 8px;
                                         width: 20px; height: 20px; cursor: pointer; }

  .file-drop { border: 2px dashed var(--border); border-radius: 8px; padding: 24px;
               text-align: center; background: var(--surface-2); cursor: pointer;
               transition: border-color 0.15s, background 0.15s; }
  .file-drop:hover { border-color: var(--accent); background: var(--surface); }
  .file-drop input[type="file"] { display: none; }
  .file-drop-label { font-weight: 600; color: var(--accent); display: block; margin-bottom: 4px; }
  .file-drop-hint { font-size: 12px; color: var(--text-muted); }
  .file-list { margin-top: 10px; font-size: 13px; color: var(--text-muted); }
  .file-list-item { padding: 2px 0; }

  .form-actions { display: flex; gap: 10px; justify-content: flex-end;
                  margin-top: 6px; padding-top: 20px; border-top: 1px solid var(--border); }
</style>
</head>
<body>

<?= renderAppChrome($isEdit ? 'Edit vehicle' : 'New vehicle', [
      'subtitle' => 'Vehicle Library',
      'active'   => 'studio',
      'width'    => '860px',
      'trailing' => '',
      'back'     => ['href' => 'vehicles.php', 'label' => 'Vehicles'],
      'links'    => [
        ['label' => 'Sign out', 'href' => 'logout.php', 'attrs' => ['title' => 'Signed in as ' . currentAdmin()]],
      ],
    ]) ?>

<div class="wrap">

  <?php if ($flash): ?>
    <div class="flash">✓ <?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errors">
      <?php foreach ($errors as $err): ?><div>⚠ <?= h($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><?= h($formTitle) ?></h2>
      <?php if ($isEdit): ?>
        <a class="btn sm" href="add-vehicle.php">+ New instead</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" action="add-vehicle.php<?= $isEdit ? '?edit=' . (int)$editVehicle['id'] : '' ?>"
            enctype="multipart/form-data" id="vehicleForm">
        <input type="hidden" name="action" value="<?= h($formAction) ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$editVehicle['id'] ?>">
        <?php endif; ?>

        <div class="field-row">
          <div class="field">
            <label for="manufacturer">Manufacturer</label>
            <input type="text" name="manufacturer" id="manufacturer" maxlength="120" required
                   value="<?= h($val_make) ?>" placeholder="e.g. Yamaha">
          </div>
          <div class="field">
            <label for="model">Model</label>
            <input type="text" name="model" id="model" maxlength="120" required
                   value="<?= h($val_model) ?>" placeholder="e.g. YXZ1000R">
          </div>
        </div>

        <div class="field-row">
          <div class="field">
            <label for="model_year">Year</label>
            <input type="number" name="model_year" id="model_year" min="1900" max="2100"
                   value="<?= h($val_year) ?>" placeholder="e.g. 2024">
            <span class="help">Optional. Fills <code>{{vehicle_year}}</code>.</span>
          </div>
          <div class="field">
            <label for="vehicle_type">Vehicle type</label>
            <input type="text" name="vehicle_type" id="vehicle_type" maxlength="80"
                   value="<?= h($val_type) ?>" placeholder="e.g. UTV, ATV, dirt bike">
            <span class="help">Optional. Fills <code>{{vehicle_type}}</code>.</span>
          </div>
        </div>

        <?php if ($isEdit && $editImages): ?>
          <div class="field">
            <label>Existing images — tick to remove on save</label>
            <div class="existing-images">
              <?php foreach ($editImages as $img): ?>
                <div class="existing-img" data-img-wrap>
                  <img src="<?= h($img['image_url']) ?>" alt="">
                  <input type="checkbox" name="remove_images[]" value="<?= (int)$img['id'] ?>"
                         data-remove-checkbox title="Remove this image">
                </div>
              <?php endforeach; ?>
            </div>
            <span class="help"><?= count($editImages) ?> of <?= $maxImages ?> image slots used.</span>
          </div>
        <?php endif; ?>

        <div class="field">
          <label for="images"><?= $isEdit ? 'Add more images' : 'Vehicle images' ?></label>
          <label class="file-drop">
            <input type="file" name="images[]" id="images"
                   accept="image/jpeg,image/png,image/gif,image/webp" multiple>
            <span class="file-drop-label">Click to choose images</span>
            <span class="file-drop-hint">
              or drag &amp; drop — up to <?= $maxImages ?> per vehicle,
              <?= (int)($maxFileSize / (1024*1024)) ?> MB each. JPG, PNG, GIF, WebP.
            </span>
          </label>
          <div class="file-list" id="fileList"></div>
        </div>

        <div class="form-actions">
          <a class="btn" href="vehicles.php">Cancel</a>
          <button type="submit" class="btn primary"><?= h($formSubmitText) ?></button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($isEdit): ?>
    <form method="POST" action="add-vehicle.php"
          onsubmit="return confirm('Delete this vehicle and all its images? This cannot be undone.');"
          style="text-align:right;">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$editVehicle['id'] ?>">
      <button type="submit" class="btn danger sm">🗑 Delete this vehicle</button>
    </form>
  <?php endif; ?>

</div>

<script>
  // File list preview
  const fileInput = document.getElementById('images');
  const fileList  = document.getElementById('fileList');
  if (fileInput) {
    fileInput.addEventListener('change', () => {
      if (!fileInput.files.length) { fileList.innerHTML = ''; return; }
      fileList.innerHTML = '<strong>Selected:</strong>';
      [...fileInput.files].forEach(f => {
        const div = document.createElement('div');
        div.className = 'file-list-item';
        div.textContent = '• ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
        fileList.appendChild(div);
      });
    });
  }

  // Drag & drop
  document.querySelectorAll('.file-drop').forEach(drop => {
    const input = drop.querySelector('input[type="file"]');
    if (!input) return;
    ['dragenter','dragover'].forEach(ev =>
      drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = 'var(--accent)'; }));
    ['dragleave','drop'].forEach(ev =>
      drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = ''; }));
    drop.addEventListener('drop', e => {
      if (e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        input.dispatchEvent(new Event('change'));
      }
    });
  });

  // Visual feedback on remove checkboxes
  document.querySelectorAll('[data-remove-checkbox]').forEach(cb => {
    cb.addEventListener('change', () => {
      cb.closest('[data-img-wrap]').classList.toggle('marked', cb.checked);
    });
  });
</script>

</body>
</html>
