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
requireAdmin();

$uploadsDir    = __DIR__ . '/uploads';
$uploadsUrl    = 'uploads';
$allowedExt    = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxFileSize   = 10 * 1024 * 1024;
$maxItemImages = 6;

$errors = [];
$flash  = $_GET['msg'] ?? '';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// --- Require client ---
if (!$client) {
    http_response_code(400);
    echo 'Missing ?client= — go to <a href="admin.php">admin</a>.';
    exit;
}

// --- Resolve module ---
$moduleSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_GET['module'] ?? 'tires')));
if ($moduleSlug === '') { $moduleSlug = 'tires'; }
$stmt = $pdo->prepare("SELECT id, slug, singular_label, plural_label, icon FROM modules WHERE slug = ?");
$stmt->execute([$moduleSlug]);
$module = $stmt->fetch();
if (!$module) { http_response_code(404); echo 'Unknown module.'; exit; }

// Confirm client has this module enabled
$chk = $pdo->prepare("SELECT 1 FROM company_modules WHERE company_id = ? AND module_id = ? LIMIT 1");
$chk->execute([$client['id'], $module['id']]);
if (!$chk->fetchColumn()) {
    http_response_code(403);
    echo h($client['name']) . ' does not have the ' . h($module['plural_label']) . ' module enabled.';
    exit;
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
    header('Location: add-feature.php?' . http_build_query(array_filter(
        $qs, fn($v) => $v !== null && $v !== ''
    )));
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
                        if (is_file($path)) { @unlink($path); }
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
                                    if (is_file($path)) { @unlink($path); }
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

                if (!empty($_FILES['item_images']) && is_array($_FILES['item_images']['name'])) {
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM tire_images WHERE tire_id = ?");
                    $cnt->execute([$itemId]);
                    $existing = (int)$cnt->fetchColumn();

                    $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM tire_images WHERE tire_id = ?");
                    $sortQ->execute([$itemId]);
                    $sortOrder = (int)$sortQ->fetchColumn();

                    $slots = $maxItemImages - $existing;
                    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

                    // Cache once: do we have the display_name column to seed?
                    $hasDisplayName = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0;

                    $uploadedCount = 0;
                    foreach ($_FILES['item_images']['name'] as $i => $origName) {
                        if ($uploadedCount >= $slots) {
                            $errors[] = "Max {$maxItemImages} images per {$sLower} — some were skipped.";
                            break;
                        }
                        $err = $_FILES['item_images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                        if ($err === UPLOAD_ERR_NO_FILE) { continue; }
                        if ($err !== UPLOAD_ERR_OK) {
                            $errors[] = "Upload error on '{$origName}' (code {$err}).";
                            continue;
                        }
                        if ($_FILES['item_images']['size'][$i] > $maxFileSize) {
                            $errors[] = "'{$origName}' exceeds 10 MB.";
                            continue;
                        }
                        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowedExt, true)) {
                            $errors[] = "'{$origName}' has an unsupported file type.";
                            continue;
                        }
                        $finfo = @getimagesize($_FILES['item_images']['tmp_name'][$i]);
                        if ($finfo === false) {
                            $errors[] = "'{$origName}' is not a valid image.";
                            continue;
                        }

                        // On-disk we still use a uniqid to dodge collisions, but seed
                        // display_name from the original upload filename's stem so the
                        // admin/user sees their own name and downloads recover it.
                        $newName = uniqid('feat_', true) . '.' . $ext;
                        $newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $newName);
                        $dest    = $uploadsDir . '/' . $newName;
                        $seedName = safeFilenameStem(pathinfo($origName, PATHINFO_FILENAME));
                        if ($seedName === '') { $seedName = null; }

                        if (move_uploaded_file($_FILES['item_images']['tmp_name'][$i], $dest)) {
                            $sortOrder++;
                            if ($hasDisplayName) {
                                $ins = $pdo->prepare("
                                    INSERT INTO tire_images (tire_id, image_url, caption, sort_order, display_name)
                                    VALUES (?, ?, '', ?, ?)
                                ");
                                $ins->execute([$itemId, $uploadsUrl . '/' . $newName, $sortOrder, $seedName]);
                            } else {
                                $ins = $pdo->prepare("
                                    INSERT INTO tire_images (tire_id, image_url, caption, sort_order)
                                    VALUES (?, ?, '', ?)
                                ");
                                $ins->execute([$itemId, $uploadsUrl . '/' . $newName, $sortOrder]);
                            }
                            $uploadedCount++;
                        } else {
                            $errors[] = "Failed to save '{$origName}'. Check uploads/ permissions.";
                        }
                    }
                }

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

$allItems = $pdo->prepare("
    SELECT t.id, t.name,
           (SELECT COUNT(*) FROM tire_images ti WHERE ti.tire_id = t.id) AS image_count,
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
            WHERE tire_id = ? ORDER BY sort_order ASC
        ");
        $imgStmt->execute([$editId]);
        $editImages = $imgStmt->fetchAll();

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
    return 'add-feature.php?' . http_build_query(array_filter($qs, fn($v) => $v !== null && $v !== ''));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $isEdit ? 'Edit' : 'Add' ?> <?= h($sLabel) ?> — <?= h($client['name']) ?></title>
<?= renderAppHead() ?>
<style>
  :root {
    --bg: #f0f2f5; --surface: #ffffff; --surface-2: #f7f8fa;
    --border: #dadde1; --text: #050505; --text-muted: #65676b;
    --accent: #1877f2; --accent-hover: #166fe5;
    --danger: #dc2626; --danger-hover: #b91c1c;
    --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04);
  }
  [data-theme="dark"] {
    --bg: #18191a; --surface: #242526; --surface-2: #3a3b3c;
    --border: #3e4042; --text: #e4e6eb; --text-muted: #b0b3b8;
    --accent: #2d88ff; --accent-hover: #4599ff;
    --danger: #ef4444; --danger-hover: #dc2626;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
  }
  * { box-sizing: border-box; } html, body { margin: 0; padding: 0; }
  body {
    background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 15px; line-height: 1.4; min-height: 100vh;
  }
  .topbar { position: sticky; top: 0; z-index: 100; background: var(--surface);
            border-bottom: 1px solid var(--border); box-shadow: var(--shadow); }
  .topbar-inner { max-width: 900px; margin: 0 auto; padding: 12px 20px;
                  display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .brand { display: flex; align-items: center; gap: 10px;
           font-weight: 700; font-size: 20px; color: var(--accent); letter-spacing: -0.5px; }
  .brand-mark { width: 32px; height: 32px; border-radius: 8px; background: var(--accent);
                color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; }
  .brand-sub { font-size: 12px; font-weight: 600; color: var(--text-muted);
               text-transform: uppercase; letter-spacing: 1px; padding: 3px 8px; border-radius: 4px;
               background: var(--surface-2); border: 1px solid var(--border); }
  .top-actions { display: flex; gap: 8px; align-items: center; }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px;
         padding: 8px 14px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;
         border: 1px solid var(--border); background: var(--surface-2); color: var(--text);
         text-decoration: none; transition: background 0.15s, transform 0.1s; }
  .btn:hover { background: var(--border); } .btn:active { transform: scale(0.98); }
  .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .btn.primary:hover { background: var(--accent-hover); }
  .btn.danger { background: var(--danger); color: #fff; border-color: var(--danger); }
  .btn.ghost { background: transparent; } .btn.sm { padding: 6px 10px; font-size: 13px; }

  .wrap { max-width: 900px; margin: 0 auto; padding: 24px 20px 80px; }
  .flash, .errors { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
                    font-size: 14px; font-weight: 500; }
  .flash  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .errors { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  [data-theme="dark"] .flash  { background: #14532d; color: #bbf7d0; border-color: #166534; }
  [data-theme="dark"] .errors { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }

  .card { background: var(--surface); border: 1px solid var(--border);
          border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 24px; overflow: hidden; }
  .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border);
                 display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .card-title { font-size: 17px; font-weight: 700; margin: 0; }
  .card-body { padding: 20px; }

  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .form-grid .full { grid-column: 1 / -1; }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field label { font-size: 13px; font-weight: 600; color: var(--text-muted);
                 text-transform: uppercase; letter-spacing: 0.5px; }
  .field input[type="text"], .field select, .field textarea {
    background: var(--surface-2); border: 1px solid var(--border); color: var(--text);
    padding: 10px 12px; border-radius: 8px; font: inherit; width: 100%; font-size: 15px;
  }
  .field input:focus, .field textarea:focus {
    outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .field .help { font-size: 12px; color: var(--text-muted); }

  .file-drop { border: 2px dashed var(--border); border-radius: 8px;
               padding: 24px; text-align: center; background: var(--surface-2); cursor: pointer;
               transition: border-color 0.15s, background 0.15s; }
  .file-drop:hover { border-color: var(--accent); background: var(--surface); }
  .file-drop input[type="file"] { display: none; }
  .file-drop-label { font-weight: 600; color: var(--accent); display: block; margin-bottom: 4px; }
  .file-drop-hint { font-size: 12px; color: var(--text-muted); }
  .file-list { margin-top: 10px; font-size: 13px; color: var(--text-muted); }

  .form-actions { display: flex; gap: 10px; justify-content: flex-end;
                  margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }

  .cat-group { display: flex; flex-wrap: wrap; gap: 8px; padding: 4px 0; }
  .cat-chip { display: inline-flex; align-items: center; gap: 6px;
              padding: 6px 12px; background: var(--surface-2);
              border: 1px solid var(--border); border-radius: 20px;
              font-size: 13px; font-weight: 600; cursor: pointer; user-select: none; }
  .cat-chip input { display: none; }
  .cat-chip:hover { background: var(--border); }
  .cat-chip.checked { background: var(--accent); border-color: var(--accent); color: #fff; }
  .cat-chip.checked::before { content: '✓ '; font-weight: 700; }

  .tire-edit-images { display: flex; flex-direction: column; gap: 12px; margin-top: 8px; }
  .tire-edit-row { display: grid; grid-template-columns: 110px 1fr auto;
                   gap: 12px; align-items: flex-start; padding: 12px;
                   background: var(--surface-2); border: 1px solid var(--border);
                   border-radius: 8px; transition: opacity 0.15s, border-color 0.15s; }
  .tire-edit-row.marked { opacity: 0.4; border-color: var(--danger); }
  .tire-edit-thumb { display: flex; flex-direction: column; gap: 6px; align-items: center; }
  .tire-edit-thumb-frame { position: relative; width: 100px; height: 100px;
                           border-radius: 6px; overflow: hidden;
                           background: var(--border); }
  .tire-edit-thumb-frame img { width: 100%; height: 100%; object-fit: cover;
                               display: block; transition: opacity 0.2s, filter 0.2s; }
  .tire-edit-thumb-frame.replacing img { opacity: 0.4; filter: blur(1px); }
  .tire-edit-replace-btn {
    position: absolute; left: 50%; bottom: 6px; transform: translateX(-50%);
    background: rgba(0,0,0,0.72); color: #fff; border: none;
    padding: 5px 9px; border-radius: 14px;
    cursor: pointer; font-size: 11px; font-weight: 700;
    letter-spacing: 0.3px; white-space: nowrap;
    opacity: 0; transition: opacity 0.18s, background 0.15s;
    backdrop-filter: blur(4px);
  }
  .tire-edit-thumb-frame:hover .tire-edit-replace-btn,
  .tire-edit-replace-btn:focus { opacity: 1; }
  .tire-edit-replace-btn:hover { background: rgba(0,0,0,0.9); }
  .tire-edit-replace-btn:disabled { opacity: 1; cursor: wait; background: rgba(0,0,0,0.85); }
  @media (hover: none) { .tire-edit-replace-btn { opacity: 1; } }
  .tire-edit-status {
    display: inline-block; width: 100%; text-align: center;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    padding: 4px 8px; border-radius: 12px; white-space: nowrap;
    background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);
  }
  .tire-edit-status.pending  { background: #fef3c7; color: #92400e; border-color: transparent; }
  .tire-edit-status.approved { background: #dcfce7; color: #166534; border-color: transparent; }
  .tire-edit-status.denied   { background: #fee2e2; color: #991b1b; border-color: transparent; }
  [data-theme="dark"] .tire-edit-status.pending  { background: #78350f; color: #fde68a; }
  [data-theme="dark"] .tire-edit-status.approved { background: #14532d; color: #bbf7d0; }
  [data-theme="dark"] .tire-edit-status.denied   { background: #7f1d1d; color: #fecaca; }
  .tire-edit-body { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
  .tire-edit-row input[type="text"] { background: var(--surface); border: 1px solid var(--border);
                                      color: var(--text); padding: 10px 12px; border-radius: 6px;
                                      font: inherit; width: 100%; }
  .tire-edit-meta { font-size: 11px; color: var(--text-muted); }
  /* Display-name input + ".jpg" suffix preview */
  .tire-edit-name { position: relative; display: flex; align-items: stretch; }
  .tire-edit-name input[type="text"] {
    flex: 1; padding-right: 56px !important;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 13px !important;
  }
  .tire-edit-name-suffix {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    font-size: 12px; color: var(--text-muted);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    pointer-events: none;
  }
  .tire-edit-status-row {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  }
  .tire-edit-status-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    color: var(--text-muted);
  }
  .tire-edit-status-buttons { display: inline-flex; gap: 4px; }
  .status-btn {
    background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);
    padding: 5px 10px; border-radius: 14px;
    font-size: 12px; font-weight: 700; letter-spacing: 0.3px; cursor: pointer;
    transition: background 0.15s, color 0.15s, border-color 0.15s, transform 0.1s;
  }
  .status-btn:hover { background: var(--border); color: var(--text); }
  .status-btn:active { transform: scale(0.97); }
  .status-btn:disabled { opacity: 0.6; cursor: wait; }
  .status-btn.pending.active  { background: #f59e0b; color: #fff; border-color: #f59e0b; }
  .status-btn.approved.active { background: #16a34a; color: #fff; border-color: #16a34a; }
  .status-btn.denied.active   { background: #dc2626; color: #fff; border-color: #dc2626; }
  .tire-edit-status-hint {
    font-size: 11px; color: var(--text-muted); font-style: italic;
    transition: color 0.15s;
  }
  .tire-edit-status-hint.saved { color: #16a34a; font-style: normal; font-weight: 600; }
  .tire-edit-status-hint.error { color: var(--danger); font-style: normal; font-weight: 600; }
  .tire-edit-comments {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 8px; padding: 10px 12px;
  }
  .tire-edit-comments-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;
    color: var(--text-muted); margin-bottom: 6px;
  }
  .tire-edit-no-comments {
    font-size: 12px; color: var(--text-muted); font-style: italic;
    padding: 6px 0;
  }
  /* Comment thread bubbles (admin theme — adapts to light + dark via tokens) */
  .tire-edit-comments .comment-thread { display: flex; flex-direction: column; gap: 6px; }
  .tire-edit-comments .comment-msg {
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: 8px; padding: 8px 12px;
  }
  .tire-edit-comments .comment-msg-client { border-left: 3px solid var(--accent); }
  .tire-edit-comments .comment-msg-admin  { border-left: 3px solid #a855f7; }
  .tire-edit-comments .comment-msg-head {
    display: flex; gap: 8px; align-items: baseline; margin-bottom: 2px;
  }
  .tire-edit-comments .comment-msg-actor {
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
    color: var(--text-muted);
  }
  .tire-edit-comments .comment-msg-actor::before { content: '@'; opacity: 0.5; }
  .tire-edit-comments .comment-msg-time {
    font-size: 11px; color: var(--text-muted); margin-left: auto;
  }
  .tire-edit-comments .comment-msg-body {
    font-size: 13px; color: var(--text); white-space: pre-wrap; word-wrap: break-word;
  }

  .tire-edit-row label.remove { display: flex; flex-direction: column; align-items: center; gap: 4px;
                                font-size: 11px; font-weight: 600; color: var(--text-muted);
                                text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; }
  .tire-edit-row input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
  @media (max-width: 600px) {
    .tire-edit-row { grid-template-columns: 90px 1fr; grid-template-rows: auto auto; }
    .tire-edit-thumb-frame { width: 80px; height: 80px; }
    .tire-edit-row label.remove { grid-column: 1 / -1; flex-direction: row; justify-content: flex-start; }
  }

  .post-list { display: flex; flex-direction: column; }
  .post-row { display: grid; grid-template-columns: 1fr auto auto;
              gap: 16px; padding: 14px 20px; border-top: 1px solid var(--border); align-items: center; }
  .post-row:first-child { border-top: none; }
  .meta-company { font-weight: 600; }
  .cat-list { font-size: 12px; color: var(--text-muted); margin-top: 3px; font-style: italic; }
  .img-count { font-size: 12px; color: var(--text-muted); white-space: nowrap; }
  .row-actions { display: flex; gap: 6px; }
  .inline-form { display: inline; }
  .empty { padding: 40px 20px; text-align: center; color: var(--text-muted); }

  @media (max-width: 720px) {
    .form-grid { grid-template-columns: 1fr; }
    .post-row { grid-template-columns: 1fr; gap: 8px; }
    .row-actions { justify-content: flex-start; }
  }
</style>
</head>
<body>

<?php
  // When editing a specific tire, point at its review page (detail view) instead of the gallery.
  $viewQs = ['client' => $client['slug'], 'module' => $module['slug']];
  if ($isEdit) { $viewQs['item'] = (int)$editItem['id']; }
  $viewLabel = $isEdit ? 'Review on site' : 'View ' . $pLower;
?>
<?= renderAppChrome(($isEdit ? 'Edit ' : 'Add ') . $sLabel, [
      'subtitle' => $client['name'],
      'active'   => 'studio',
      'width'    => '900px',
      'back'     => ['href' => 'admin.php?client=' . rawurlencode($client['slug']), 'label' => 'Studio'],
      'links'    => [
        ['label' => $viewLabel, 'href' => 'features.php?' . http_build_query($viewQs), 'attrs' => ['target' => '_blank']],
        ['label' => 'Sign out', 'href' => 'logout.php', 'attrs' => ['title' => 'Signed in as ' . currentAdmin()]],
      ],
    ]) ?>

<div class="wrap">

  <?php if ($flash): ?><div class="flash">✓ <?= h($flash) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="errors">
      <?php foreach ($errors as $err): ?><div>⚠ <?= h($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><?= h($formTitle) ?></h2>
      <?php if ($isEdit): ?>
        <a class="btn sm ghost" href="<?= h(selfUrl()) ?>">Cancel edit</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" action="<?= h(selfUrl()) ?>" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?= h($formAction) ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
          <div class="field full">
            <label for="item_name"><?= h($sLabel) ?> name</label>
            <input type="text" name="item_name" id="item_name"
                   value="<?= h($val_item_name) ?>"
                   placeholder="e.g. Kenda Klever R/T" required>
          </div>

          <div class="field full">
            <label>Categories</label>
            <div class="cat-group">
              <?php foreach ($allCategories as $cat):
                $checked = in_array((int)$cat['id'], $editCategories, true);
              ?>
                <label class="cat-chip <?= $checked ? 'checked' : '' ?>" data-cat-chip>
                  <input type="checkbox" name="item_categories[]" value="<?= (int)$cat['id'] ?>"
                         <?= $checked ? 'checked' : '' ?>>
                  <?= h($cat['name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <span class="help">Click chips to toggle. Multiple categories allowed.</span>
          </div>

          <?php if ($isEdit && $editImages): ?>
            <div class="field full">
              <label>Existing images — review status, comments, and captions</label>
              <div class="tire-edit-images">
                <?php foreach ($editImages as $img):
                  $imgStatus = $img['status'] ?? 'pending';
                  $threadHtml = renderCommentThread($pdo, 'tire_image', (int)$img['id']);
                ?>
                  <div class="tire-edit-row" data-tire-row data-image-id="<?= (int)$img['id'] ?>" data-status="<?= h($imgStatus) ?>">
                    <div class="tire-edit-thumb">
                      <div class="tire-edit-thumb-frame">
                        <img src="<?= h($img['image_url']) ?>" alt="" data-thumb-img>
                        <button type="button" class="tire-edit-replace-btn"
                                data-replace-tire-img
                                title="Replace this image">
                          🔄 Replace
                        </button>
                      </div>
                    </div>
                    <div class="tire-edit-body">
                      <?php
                        $imgExt = strtolower(pathinfo($img['image_url'], PATHINFO_EXTENSION) ?: 'jpg');
                      ?>
                      <div class="tire-edit-name">
                        <input type="text"
                               name="display_names[<?= (int)$img['id'] ?>]"
                               value="<?= h($img['display_name'] ?? '') ?>"
                               placeholder="Image name (used when downloading)"
                               data-display-name>
                        <span class="tire-edit-name-suffix">.<?= h($imgExt) ?></span>
                      </div>
                      <input type="text"
                             name="captions[<?= (int)$img['id'] ?>]"
                             value="<?= h($img['caption']) ?>"
                             placeholder="Caption for this image">
                      <div class="tire-edit-status-row">
                        <span class="tire-edit-status-label">Status</span>
                        <div class="tire-edit-status-buttons" role="group" aria-label="Approval status">
                          <button type="button" class="status-btn pending <?= $imgStatus === 'pending' ? 'active' : '' ?>"
                                  data-status-set="pending" title="Mark as pending review">⏳ Pending</button>
                          <button type="button" class="status-btn approved <?= $imgStatus === 'approved' ? 'active' : '' ?>"
                                  data-status-set="approved" title="Approve this image">✓ Approve</button>
                          <button type="button" class="status-btn denied <?= $imgStatus === 'denied' ? 'active' : '' ?>"
                                  data-status-set="denied" title="Deny this image">✕ Deny</button>
                        </div>
                        <span class="tire-edit-status-hint" data-status-hint></span>
                      </div>
                      <?php if (!empty($img['updated_at'])): ?>
                        <div class="tire-edit-meta" title="<?= h(absoluteTime($img['updated_at'])) ?>" data-updated-meta>
                          Last updated <?= h(relativeTime($img['updated_at'])) ?>
                        </div>
                      <?php endif; ?>
                      <?php if ($threadHtml): ?>
                        <div class="tire-edit-comments">
                          <div class="tire-edit-comments-label">💬 Comments</div>
                          <?= $threadHtml ?>
                        </div>
                      <?php else: ?>
                        <div class="tire-edit-no-comments">No comments on this image yet.</div>
                      <?php endif; ?>
                    </div>
                    <label class="remove">
                      <input type="checkbox" name="remove_item_images[]"
                             value="<?= (int)$img['id'] ?>" data-tire-remove>
                      Remove
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
              <span class="help">
                Currently <?= count($editImages) ?> of <?= $maxItemImages ?> slots used.
                Status changes save instantly. To reply to comments, open the
                <a href="<?= h('features.php?' . http_build_query(['client' => $client['slug'], 'module' => $module['slug'], 'item' => (int)$editItem['id']])) ?>"
                   target="_blank">review page</a>.
              </span>
            </div>
          <?php endif; ?>

          <?php if ($isEdit): ?>
            <div class="field full">
              <label for="item_images">Add more images</label>
              <label class="file-drop">
                <input type="file" name="item_images[]" id="item_images"
                       accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                <span class="file-drop-label">Click to choose files</span>
                <span class="file-drop-hint">
                  Max <?= $maxItemImages ?> per <?= h($sLower) ?>, 10 MB each. Captions editable after upload.
                </span>
              </label>
              <div class="file-list" id="itemFileList"></div>
            </div>
          <?php else: ?>
            <div class="field full">
              <span class="help">Create the <?= h($sLower) ?> first, then add images on the edit screen.</span>
            </div>
          <?php endif; ?>
        </div>

        <div class="form-actions">
          <a class="btn" href="admin.php?client=<?= h($client['slug']) ?>">Cancel</a>
          <button type="submit" class="btn primary"><?= h($formSubmit) ?></button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h2 class="card-title">All <?= h($pLower) ?> (<?= count($allItems) ?>)</h2>
    </div>
    <?php if (empty($allItems)): ?>
      <div class="empty">No <?= h($pLower) ?> yet. Create your first one above.</div>
    <?php else: ?>
      <div class="post-list">
        <?php foreach ($allItems as $t): ?>
          <div class="post-row">
            <div>
              <div class="meta-company"><?= h($t['name']) ?></div>
              <div class="cat-list"><?= h($t['category_names'] ?? '') ?></div>
            </div>
            <div class="img-count">🖼 <?= (int)$t['image_count'] ?> / <?= $maxItemImages ?></div>
            <div class="row-actions">
              <a class="btn sm" href="<?= h(selfUrl(['edit_item' => (int)$t['id']])) ?>">Edit</a>
              <a class="btn sm"
                 href="<?= h('features.php?' . http_build_query(['client' => $client['slug'], 'module' => $module['slug'], 'item' => (int)$t['id']])) ?>"
                 target="_blank">Review ↗</a>
              <form method="POST" action="<?= h(selfUrl()) ?>" class="inline-form"
                    onsubmit="return confirm('Delete this <?= h($sLower) ?> and all its images? This cannot be undone.');">
                <input type="hidden" name="action" value="item_delete">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="btn sm danger">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
  const itemFileInput = document.getElementById('item_images');
  const itemFileList  = document.getElementById('itemFileList');
  if (itemFileInput && itemFileList) {
    itemFileInput.addEventListener('change', () => {
      if (!itemFileInput.files.length) { itemFileList.innerHTML = ''; return; }
      itemFileList.innerHTML = '<strong>Selected:</strong>';
      [...itemFileInput.files].forEach(f => {
        const div = document.createElement('div');
        div.textContent = '• ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
        itemFileList.appendChild(div);
      });
    });
  }

  document.querySelectorAll('.file-drop').forEach(drop => {
    const input = drop.querySelector('input[type="file"]');
    if (!input) return;
    ['dragenter','dragover'].forEach(ev =>
      drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = 'var(--accent)'; })
    );
    ['dragleave','drop'].forEach(ev =>
      drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = ''; })
    );
    drop.addEventListener('drop', e => {
      if (e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        input.dispatchEvent(new Event('change'));
      }
    });
  });

  document.querySelectorAll('[data-tire-remove]').forEach(cb => {
    cb.addEventListener('change', () => {
      cb.closest('[data-tire-row]').classList.toggle('marked', cb.checked);
    });
  });

  document.querySelectorAll('[data-cat-chip]').forEach(chip => {
    const cb = chip.querySelector('input[type="checkbox"]');
    if (!cb) return;
    cb.addEventListener('change', () => { chip.classList.toggle('checked', cb.checked); });
  });

  // ---- Inline status change (Pending / Approve / Deny) -------------
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-status-set]');
    if (!btn) return;
    const row     = btn.closest('[data-tire-row]');
    const imageId = row.getAttribute('data-image-id');
    const next    = btn.getAttribute('data-status-set');
    const current = row.getAttribute('data-status');
    if (next === current) return;

    const allBtns = row.querySelectorAll('.status-btn');
    const hint    = row.querySelector('[data-status-hint]');
    allBtns.forEach(b => b.disabled = true);
    if (hint) { hint.className = 'tire-edit-status-hint'; hint.textContent = 'Saving…'; }

    try {
      const fd = new FormData();
      fd.append('id', imageId);
      fd.append('status', next);
      fd.append('actor', 'admin');
      const res  = await fetch('tire-status.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');

      row.setAttribute('data-status', next);
      allBtns.forEach(b => b.classList.toggle('active', b.getAttribute('data-status-set') === next));
      if (hint) {
        hint.className = 'tire-edit-status-hint saved';
        hint.textContent = '✓ Saved';
        setTimeout(() => {
          if (hint.textContent === '✓ Saved') {
            hint.className = 'tire-edit-status-hint';
            hint.textContent = '';
          }
        }, 2000);
      }
      // Bump the "Last updated" stamp in place — server set updated_at to NOW().
      const meta = row.querySelector('[data-updated-meta]');
      if (meta) meta.textContent = 'Last updated just now';
    } catch (err) {
      if (hint) {
        hint.className = 'tire-edit-status-hint error';
        hint.textContent = 'Save failed — try again';
      }
    } finally {
      allBtns.forEach(b => b.disabled = false);
    }
  });

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
    if (file.size > 10 * 1024 * 1024) {
      alert('Image exceeds 10 MB');
      pendingReplaceBtn = null;
      return;
    }

    const row     = pendingReplaceBtn.closest('[data-tire-row]');
    const frame   = pendingReplaceBtn.closest('.tire-edit-thumb-frame');
    const imgEl   = row.querySelector('[data-thumb-img]');
    const imageId = row.getAttribute('data-image-id');
    const original = pendingReplaceBtn.textContent;

    frame.classList.add('replacing');
    pendingReplaceBtn.disabled = true;
    pendingReplaceBtn.textContent = '⏳ Uploading…';

    try {
      const fd = new FormData();
      fd.append('image_id', imageId);
      fd.append('image', file);
      fd.append('type', 'tire');
      const res  = await fetch('replace-image.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      // Cache-bust in case the same filename gets reused
      const bust = data.image_url + (data.image_url.includes('?') ? '&' : '?') + 't=' + Date.now();
      imgEl.src = bust;
    } catch (err) {
      alert('Replace failed: ' + (err.message || 'unknown'));
    } finally {
      frame.classList.remove('replacing');
      pendingReplaceBtn.disabled = false;
      pendingReplaceBtn.textContent = original;
      pendingReplaceBtn = null;
    }
  });
</script>

</body>
</html>
