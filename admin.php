<?php
/**
 * Social Media Approval Feed — Admin
 * List, create, edit, delete posts. Uploads images into /uploads/.
 */

require __DIR__ . '/db.php';

// -------------------------------------------------------------
// Config
// -------------------------------------------------------------
$uploadsDir  = __DIR__ . '/uploads';
$uploadsUrl  = 'uploads';
$allowedExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxFileSize    = 10 * 1024 * 1024; // 10 MB
$maxImages      = 5;
$maxTireImages  = 6;

$errors = [];
$flash  = $_GET['msg'] ?? '';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Delete -----------------------------------------------
    if ($action === 'delete') {
        $postId = (int)($_POST['id'] ?? 0);
        if ($postId > 0) {
            try {
                $pdo->beginTransaction();
                $imgs = $pdo->prepare("SELECT image_url FROM post_images WHERE post_id = ?");
                $imgs->execute([$postId]);
                foreach ($imgs->fetchAll() as $row) {
                    if (strpos($row['image_url'], 'uploads/') === 0) {
                        $path = __DIR__ . '/' . $row['image_url'];
                        if (is_file($path)) { @unlink($path); }
                    }
                }
                $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
                $pdo->commit();
                header('Location: admin.php?msg=' . urlencode('Post deleted.'));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        }
    }

    // ---- Create / Update --------------------------------------
    if ($action === 'create' || $action === 'update') {
        $company_id     = (int)($_POST['company_id'] ?? 0);
        $caption        = trim($_POST['caption'] ?? '');
        $hashtags       = trim($_POST['hashtags'] ?? '');
        $scheduled_date = trim($_POST['scheduled_date'] ?? '');
        $status         = $_POST['status'] ?? 'pending';

        if (!$company_id)          { $errors[] = 'Company is required.'; }
        if ($caption === '')       { $errors[] = 'Caption is required.'; }
        if ($scheduled_date === ''){ $errors[] = 'Scheduled date is required.'; }
        if (!in_array($status, ['pending', 'approved', 'denied'], true)) {
            $status = 'pending';
        }

        $dtFormatted = null;
        if ($scheduled_date !== '') {
            $ts = strtotime($scheduled_date);
            if ($ts) { $dtFormatted = date('Y-m-d H:i:s', $ts); }
            else     { $errors[] = 'Invalid date format.'; }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO posts (company_id, caption, hashtags, scheduled_date, status)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$company_id, $caption, $hashtags, $dtFormatted, $status]);
                    $postId = (int)$pdo->lastInsertId();
                } else {
                    $postId = (int)($_POST['id'] ?? 0);
                    if ($postId <= 0) { throw new Exception('Invalid post id.'); }
                    $stmt = $pdo->prepare("
                        UPDATE posts
                        SET company_id = ?, caption = ?, hashtags = ?, scheduled_date = ?, status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$company_id, $caption, $hashtags, $dtFormatted, $status, $postId]);
                }

                // Replace category assignments (works for both create and update)
                $pdo->prepare("DELETE FROM post_categories WHERE post_id = ?")->execute([$postId]);
                if (!empty($_POST['categories']) && is_array($_POST['categories'])) {
                    $insCat = $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)");
                    foreach ($_POST['categories'] as $cid) {
                        $cid = (int)$cid;
                        if ($cid > 0) { $insCat->execute([$postId, $cid]); }
                    }
                }

                if ($action === 'update') {
                    // Remove any images the user ticked for deletion
                    if (!empty($_POST['remove_images']) && is_array($_POST['remove_images'])) {
                        $toRemove = array_values(array_filter(array_map('intval', $_POST['remove_images'])));
                        if ($toRemove) {
                            $ph  = implode(',', array_fill(0, count($toRemove), '?'));
                            $sel = $pdo->prepare("
                                SELECT id, image_url FROM post_images
                                WHERE post_id = ? AND id IN ($ph)
                            ");
                            $sel->execute(array_merge([$postId], $toRemove));
                            foreach ($sel->fetchAll() as $row) {
                                if (strpos($row['image_url'], 'uploads/') === 0) {
                                    $path = __DIR__ . '/' . $row['image_url'];
                                    if (is_file($path)) { @unlink($path); }
                                }
                            }
                            $del = $pdo->prepare("
                                DELETE FROM post_images WHERE post_id = ? AND id IN ($ph)
                            ");
                            $del->execute(array_merge([$postId], $toRemove));
                        }
                    }
                }

                // Handle new image uploads
                if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
                    // How many images already exist on this post?
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM post_images WHERE post_id = ?");
                    $cnt->execute([$postId]);
                    $existing = (int)$cnt->fetchColumn();

                    $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM post_images WHERE post_id = ?");
                    $sortQ->execute([$postId]);
                    $sortOrder = (int)$sortQ->fetchColumn();

                    $slots = $maxImages - $existing;

                    if (!is_dir($uploadsDir)) {
                        @mkdir($uploadsDir, 0755, true);
                    }

                    $uploadedCount = 0;
                    foreach ($_FILES['images']['name'] as $i => $origName) {
                        if ($uploadedCount >= $slots) {
                            $errors[] = "Max {$maxImages} images per post — some were skipped.";
                            break;
                        }
                        $err = $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                        if ($err === UPLOAD_ERR_NO_FILE) { continue; }
                        if ($err !== UPLOAD_ERR_OK) {
                            $errors[] = "Upload error on '{$origName}' (code {$err}).";
                            continue;
                        }
                        if ($_FILES['images']['size'][$i] > $maxFileSize) {
                            $errors[] = "'{$origName}' exceeds 10 MB.";
                            continue;
                        }
                        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowedExt, true)) {
                            $errors[] = "'{$origName}' has an unsupported file type.";
                            continue;
                        }

                        // Real MIME check
                        $finfo = @getimagesize($_FILES['images']['tmp_name'][$i]);
                        if ($finfo === false) {
                            $errors[] = "'{$origName}' is not a valid image.";
                            continue;
                        }

                        $newName = uniqid('img_', true) . '.' . $ext;
                        $newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $newName);
                        $dest    = $uploadsDir . '/' . $newName;

                        if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $dest)) {
                            $sortOrder++;
                            $ins = $pdo->prepare("
                                INSERT INTO post_images (post_id, image_url, sort_order)
                                VALUES (?, ?, ?)
                            ");
                            $ins->execute([$postId, $uploadsUrl . '/' . $newName, $sortOrder]);
                            $uploadedCount++;
                        } else {
                            $errors[] = "Failed to save '{$origName}'. Check uploads/ permissions.";
                        }
                    }
                }

                $pdo->commit();
                $msg = $action === 'create' ? 'Post created.' : 'Post updated.';
                if ($errors) {
                    $msg .= ' (Some warnings: ' . implode(' ', $errors) . ')';
                }
                header('Location: admin.php?msg=' . urlencode($msg));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// Batch upload handler (create multiple posts in one go)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'batch_create') {
        $company_id  = (int)($_POST['company_id'] ?? 0);
        $spacingDays = max(1, min(30, (int)($_POST['spacing_days'] ?? 3)));

        if (!$company_id) {
            $errors[] = 'Company is required for batch upload.';
        }
        if (empty($_FILES['batch_images']) || !is_array($_FILES['batch_images']['name'])) {
            $errors[] = 'No images uploaded.';
        }

        if (!$errors) {
            // Find latest scheduled_date for this company; spacing starts from there
            $dateStmt = $pdo->prepare("SELECT MAX(scheduled_date) FROM posts WHERE company_id = ?");
            $dateStmt->execute([$company_id]);
            $latest = $dateStmt->fetchColumn();
            $baseDate = $latest ? new DateTime($latest) : new DateTime();

            // Load categories for filename matching
            $catRows = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order")->fetchAll();
            $catMap = [];
            foreach ($catRows as $cat) {
                $catMap[strtolower($cat['name'])] = (int)$cat['id'];
            }
            // Sort longest name first so "sport atv" matches before "atv"
            uksort($catMap, function($a, $b) { return strlen($b) - strlen($a); });

            // Common spelling variants → canonical category name
            $aliases = [
                'offroad'    => 'off-road',
                'dualsport'  => 'dual sport',
                'sportatv'   => 'sport atv',
            ];

            if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

            $createdCount = 0;
            $fileNames = $_FILES['batch_images']['name'];

            foreach ($fileNames as $i => $origName) {
                $err = $_FILES['batch_images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($err === UPLOAD_ERR_NO_FILE) { continue; }
                if ($err !== UPLOAD_ERR_OK) {
                    $errors[] = "Upload error on '{$origName}' (code {$err}).";
                    continue;
                }
                if ($_FILES['batch_images']['size'][$i] > $maxFileSize) {
                    $errors[] = "'{$origName}' exceeds 10 MB.";
                    continue;
                }
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    $errors[] = "'{$origName}' has an unsupported file type.";
                    continue;
                }
                $finfo = @getimagesize($_FILES['batch_images']['tmp_name'][$i]);
                if ($finfo === false) {
                    $errors[] = "'{$origName}' is not a valid image.";
                    continue;
                }

                $newName = uniqid('batch_', true) . '.' . $ext;
                $newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $newName);
                $dest    = $uploadsDir . '/' . $newName;

                if (!move_uploaded_file($_FILES['batch_images']['tmp_name'][$i], $dest)) {
                    $errors[] = "Failed to save '{$origName}'.";
                    continue;
                }

                // Match categories from filename.  Pad with spaces so we match
                // whole words, not substrings (e.g. "utv" shouldn't match "cutv").
                $nameKey = strtolower(pathinfo($origName, PATHINFO_FILENAME));
                $nameKey = str_replace(['-', '_', '.'], ' ', $nameKey);
                $nameKey = ' ' . preg_replace('/\s+/', ' ', $nameKey) . ' ';

                $matchedCatIds = [];
                foreach ($catMap as $catName => $catId) {
                    if (strpos($nameKey, ' ' . $catName . ' ') !== false) {
                        $matchedCatIds[$catId] = true;
                    }
                }
                foreach ($aliases as $alias => $catName) {
                    if (strpos($nameKey, ' ' . $alias . ' ') !== false && isset($catMap[$catName])) {
                        $matchedCatIds[$catMap[$catName]] = true;
                    }
                }

                // Advance scheduled date
                $baseDate->modify('+' . $spacingDays . ' days');
                $scheduledDate = $baseDate->format('Y-m-d H:i:s');

                try {
                    $pdo->beginTransaction();

                    $ins = $pdo->prepare("
                        INSERT INTO posts (company_id, caption, hashtags, scheduled_date, status)
                        VALUES (?, 'Please insert caption here', '', ?, 'pending')
                    ");
                    $ins->execute([$company_id, $scheduledDate]);
                    $postId = (int)$pdo->lastInsertId();

                    $imgIns = $pdo->prepare("
                        INSERT INTO post_images (post_id, image_url, sort_order)
                        VALUES (?, ?, 1)
                    ");
                    $imgIns->execute([$postId, $uploadsUrl . '/' . $newName]);

                    if ($matchedCatIds) {
                        $catIns = $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)");
                        foreach (array_keys($matchedCatIds) as $cid) {
                            $catIns->execute([$postId, $cid]);
                        }
                    }

                    $pdo->commit();
                    $createdCount++;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = "DB error on '{$origName}': " . $e->getMessage();
                    if (is_file($dest)) { @unlink($dest); }
                }
            }

            $msg = $createdCount . ' post' . ($createdCount !== 1 ? 's' : '') . ' created via batch upload.';
            if ($errors) {
                $msg .= ' (Warnings: ' . implode(' ', $errors) . ')';
            }
            header('Location: admin.php?msg=' . urlencode($msg));
            exit;
        }
    }
}

// -------------------------------------------------------------
// Tire POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Delete tire ------------------------------------------
    if ($action === 'tire_delete') {
        $tireId = (int)($_POST['id'] ?? 0);
        if ($tireId > 0) {
            try {
                $pdo->beginTransaction();
                $imgs = $pdo->prepare("SELECT image_url FROM tire_images WHERE tire_id = ?");
                $imgs->execute([$tireId]);
                foreach ($imgs->fetchAll() as $row) {
                    if (strpos($row['image_url'], 'uploads/') === 0) {
                        $path = __DIR__ . '/' . $row['image_url'];
                        if (is_file($path)) { @unlink($path); }
                    }
                }
                $pdo->prepare("DELETE FROM tires WHERE id = ?")->execute([$tireId]);
                $pdo->commit();
                header('Location: admin.php?msg=' . urlencode('Tire deleted.'));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Tire delete failed: ' . $e->getMessage();
            }
        }
    }

    // ---- Create / Update tire ---------------------------------
    if ($action === 'tire_create' || $action === 'tire_update') {
        $name = trim($_POST['tire_name'] ?? '');
        if ($name === '') { $errors[] = 'Tire name is required.'; }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($action === 'tire_create') {
                    $stmt = $pdo->prepare("INSERT INTO tires (name) VALUES (?)");
                    $stmt->execute([$name]);
                    $tireId = (int)$pdo->lastInsertId();
                } else {
                    $tireId = (int)($_POST['id'] ?? 0);
                    if ($tireId <= 0) { throw new Exception('Invalid tire id.'); }
                    $stmt = $pdo->prepare("UPDATE tires SET name = ? WHERE id = ?");
                    $stmt->execute([$name, $tireId]);

                    // Update captions on existing images
                    if (!empty($_POST['captions']) && is_array($_POST['captions'])) {
                        $capStmt = $pdo->prepare("
                            UPDATE tire_images SET caption = ?
                            WHERE id = ? AND tire_id = ?
                        ");
                        foreach ($_POST['captions'] as $imgId => $cap) {
                            $capStmt->execute([trim($cap), (int)$imgId, $tireId]);
                        }
                    }

                    // Remove any images the user ticked for deletion
                    if (!empty($_POST['remove_tire_images']) && is_array($_POST['remove_tire_images'])) {
                        $toRemove = array_values(array_filter(array_map('intval', $_POST['remove_tire_images'])));
                        if ($toRemove) {
                            $ph  = implode(',', array_fill(0, count($toRemove), '?'));
                            $sel = $pdo->prepare("
                                SELECT id, image_url FROM tire_images
                                WHERE tire_id = ? AND id IN ($ph)
                            ");
                            $sel->execute(array_merge([$tireId], $toRemove));
                            foreach ($sel->fetchAll() as $row) {
                                if (strpos($row['image_url'], 'uploads/') === 0) {
                                    $path = __DIR__ . '/' . $row['image_url'];
                                    if (is_file($path)) { @unlink($path); }
                                }
                            }
                            $del = $pdo->prepare("
                                DELETE FROM tire_images WHERE tire_id = ? AND id IN ($ph)
                            ");
                            $del->execute(array_merge([$tireId], $toRemove));
                        }
                    }
                }

                // Replace category assignments (works for both create and update)
                $pdo->prepare("DELETE FROM tire_categories WHERE tire_id = ?")->execute([$tireId]);
                if (!empty($_POST['tire_categories']) && is_array($_POST['tire_categories'])) {
                    $insCat = $pdo->prepare("INSERT IGNORE INTO tire_categories (tire_id, category_id) VALUES (?, ?)");
                    foreach ($_POST['tire_categories'] as $cid) {
                        $cid = (int)$cid;
                        if ($cid > 0) { $insCat->execute([$tireId, $cid]); }
                    }
                }

                // Handle new image uploads
                if (!empty($_FILES['tire_images']) && is_array($_FILES['tire_images']['name'])) {
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM tire_images WHERE tire_id = ?");
                    $cnt->execute([$tireId]);
                    $existing = (int)$cnt->fetchColumn();

                    $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM tire_images WHERE tire_id = ?");
                    $sortQ->execute([$tireId]);
                    $sortOrder = (int)$sortQ->fetchColumn();

                    $slots = $maxTireImages - $existing;

                    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

                    $uploadedCount = 0;
                    foreach ($_FILES['tire_images']['name'] as $i => $origName) {
                        if ($uploadedCount >= $slots) {
                            $errors[] = "Max {$maxTireImages} images per tire — some were skipped.";
                            break;
                        }
                        $err = $_FILES['tire_images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                        if ($err === UPLOAD_ERR_NO_FILE) { continue; }
                        if ($err !== UPLOAD_ERR_OK) {
                            $errors[] = "Upload error on '{$origName}' (code {$err}).";
                            continue;
                        }
                        if ($_FILES['tire_images']['size'][$i] > $maxFileSize) {
                            $errors[] = "'{$origName}' exceeds 10 MB.";
                            continue;
                        }
                        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowedExt, true)) {
                            $errors[] = "'{$origName}' has an unsupported file type.";
                            continue;
                        }
                        $finfo = @getimagesize($_FILES['tire_images']['tmp_name'][$i]);
                        if ($finfo === false) {
                            $errors[] = "'{$origName}' is not a valid image.";
                            continue;
                        }

                        $newName = uniqid('tire_', true) . '.' . $ext;
                        $newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $newName);
                        $dest    = $uploadsDir . '/' . $newName;

                        if (move_uploaded_file($_FILES['tire_images']['tmp_name'][$i], $dest)) {
                            $sortOrder++;
                            $ins = $pdo->prepare("
                                INSERT INTO tire_images (tire_id, image_url, caption, sort_order)
                                VALUES (?, ?, '', ?)
                            ");
                            $ins->execute([$tireId, $uploadsUrl . '/' . $newName, $sortOrder]);
                            $uploadedCount++;
                        } else {
                            $errors[] = "Failed to save '{$origName}'. Check uploads/ permissions.";
                        }
                    }
                }

                $pdo->commit();
                $msg = $action === 'tire_create' ? 'Tire created.' : 'Tire updated.';
                if ($errors) {
                    $msg .= ' (Warnings: ' . implode(' ', $errors) . ')';
                }
                header('Location: admin.php?msg=' . urlencode($msg)
                     . ($action === 'tire_update' ? '&edit_tire=' . $tireId : ''));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Tire save failed: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// Fetch for display
// -------------------------------------------------------------
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$allCategories = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order, name")->fetchAll();

$editPost   = null;
$editImages = [];
$editId     = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $stmt->execute([$editId]);
    $editPost = $stmt->fetch();
    if ($editPost) {
        $imgStmt = $pdo->prepare("
            SELECT id, image_url FROM post_images
            WHERE post_id = ?
            ORDER BY sort_order ASC
        ");
        $imgStmt->execute([$editId]);
        $editImages = $imgStmt->fetchAll();

        $catStmt = $pdo->prepare("SELECT category_id FROM post_categories WHERE post_id = ?");
        $catStmt->execute([$editId]);
        $editPostCategories = array_map('intval', array_column($catStmt->fetchAll(), 'category_id'));
    }
}
$editPostCategories = $editPostCategories ?? [];

$listStmt = $pdo->query("
    SELECT p.id, p.caption, p.scheduled_date, p.status, p.company_id,
           c.name AS company_name,
           (SELECT COUNT(*) FROM post_images pi WHERE pi.post_id = p.id) AS image_count,
           (SELECT GROUP_CONCAT(cat.name ORDER BY cat.sort_order SEPARATOR ', ')
            FROM post_categories pc
            INNER JOIN categories cat ON cat.id = pc.category_id
            WHERE pc.post_id = p.id) AS category_names
    FROM posts p
    INNER JOIN companies c ON c.id = p.company_id
    ORDER BY p.scheduled_date DESC
");
$allPosts = $listStmt->fetchAll();

// Tire list + optional edit target
$allTires = $pdo->query("
    SELECT t.id, t.name,
           (SELECT COUNT(*) FROM tire_images ti WHERE ti.tire_id = t.id) AS image_count,
           (SELECT GROUP_CONCAT(cat.name ORDER BY cat.sort_order SEPARATOR ', ')
            FROM tire_categories tc
            INNER JOIN categories cat ON cat.id = tc.category_id
            WHERE tc.tire_id = t.id) AS category_names
    FROM tires t
    ORDER BY t.name ASC
")->fetchAll();

$editTire       = null;
$editTireImages = [];
$editTireId     = (int)($_GET['edit_tire'] ?? 0);
if ($editTireId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM tires WHERE id = ?");
    $stmt->execute([$editTireId]);
    $editTire = $stmt->fetch();
    if ($editTire) {
        $imgStmt = $pdo->prepare("
            SELECT id, image_url, caption FROM tire_images
            WHERE tire_id = ?
            ORDER BY sort_order ASC
        ");
        $imgStmt->execute([$editTireId]);
        $editTireImages = $imgStmt->fetchAll();

        $catStmt = $pdo->prepare("SELECT category_id FROM tire_categories WHERE tire_id = ?");
        $catStmt->execute([$editTireId]);
        $editTireCategories = array_map('intval', array_column($catStmt->fetchAll(), 'category_id'));
    }
}
$editTireCategories = $editTireCategories ?? [];

$isTireEdit         = (bool)$editTire;
$tireFormAction     = $isTireEdit ? 'tire_update' : 'tire_create';
$tireFormTitle      = $isTireEdit ? 'Edit tire — ' . $editTire['name'] : 'New tire';
$tireFormSubmitText = $isTireEdit ? 'Save changes' : 'Create tire';
$val_tire_name      = $isTireEdit ? $editTire['name'] : '';

$isEdit         = (bool)$editPost;
$formAction     = $isEdit ? 'update' : 'create';
$formTitle      = $isEdit ? 'Edit post #' . (int)$editPost['id'] : 'New post';
$formSubmitText = $isEdit ? 'Save changes' : 'Create post';

// Prefill values
$val_company_id = $isEdit ? (int)$editPost['company_id'] : '';
$val_caption    = $isEdit ? $editPost['caption']          : '';
$val_hashtags   = $isEdit ? $editPost['hashtags']         : '';
$val_status     = $isEdit ? $editPost['status']           : 'pending';
$val_datetime   = $isEdit
    ? date('Y-m-d\TH:i', strtotime($editPost['scheduled_date']))
    : date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — Social Approval Feed</title>
<style>
  :root {
    --bg: #f0f2f5;
    --surface: #ffffff;
    --surface-2: #f7f8fa;
    --border: #dadde1;
    --text: #050505;
    --text-muted: #65676b;
    --accent: #1877f2;
    --accent-hover: #166fe5;
    --danger: #dc2626;
    --danger-hover: #b91c1c;
    --success: #16a34a;
    --warn: #f59e0b;
    --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04);
  }
  [data-theme="dark"] {
    --bg: #18191a;
    --surface: #242526;
    --surface-2: #3a3b3c;
    --border: #3e4042;
    --text: #e4e6eb;
    --text-muted: #b0b3b8;
    --accent: #2d88ff;
    --accent-hover: #4599ff;
    --danger: #ef4444;
    --danger-hover: #dc2626;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
  }

  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 15px;
    line-height: 1.4;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
  }

  /* Top bar */
  .topbar {
    position: sticky; top: 0; z-index: 100;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow);
  }
  .topbar-inner {
    max-width: 900px; margin: 0 auto;
    padding: 12px 20px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
  }
  .brand {
    display: flex; align-items: center; gap: 10px;
    font-weight: 700; font-size: 20px;
    color: var(--accent); letter-spacing: -0.5px;
  }
  .brand-mark {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--accent); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
  }
  .brand-sub {
    font-size: 12px; font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 1px;
    padding: 3px 8px; border-radius: 4px;
    background: var(--surface-2); border: 1px solid var(--border);
  }
  .top-actions { display: flex; gap: 8px; align-items: center; }
  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 8px 14px; border-radius: 8px;
    font-size: 14px; font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    text-decoration: none;
    transition: background 0.15s, transform 0.1s;
  }
  .btn:hover { background: var(--border); }
  .btn:active { transform: scale(0.98); }
  .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .btn.primary:hover { background: var(--accent-hover); }
  .btn.danger { background: var(--danger); color: #fff; border-color: var(--danger); }
  .btn.danger:hover { background: var(--danger-hover); }
  .btn.ghost { background: transparent; }
  .btn.sm { padding: 6px 10px; font-size: 13px; }

  .wrap { max-width: 900px; margin: 0 auto; padding: 24px 20px 80px; }

  .flash, .errors {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
    font-weight: 500;
  }
  .flash  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .errors { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  [data-theme="dark"] .flash  { background: #14532d; color: #bbf7d0; border-color: #166534; }
  [data-theme="dark"] .errors { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    margin-bottom: 24px;
    overflow: hidden;
  }
  .card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
  }
  .card-title { font-size: 17px; font-weight: 700; margin: 0; }
  .card-body { padding: 20px; }

  /* Form */
  .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }
  .form-grid .full { grid-column: 1 / -1; }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .field input[type="text"],
  .field input[type="datetime-local"],
  .field select,
  .field textarea {
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 10px 12px;
    border-radius: 8px;
    font: inherit;
    width: 100%;
    font-size: 15px;
  }
  .field textarea { resize: vertical; min-height: 80px; font-family: inherit; }
  .field textarea.caption { min-height: 120px; }
  .field input:focus,
  .field select:focus,
  .field textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .field .help { font-size: 12px; color: var(--text-muted); }

  /* Existing images (edit mode) */
  .existing-images {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
    margin-top: 8px;
  }
  .existing-img {
    position: relative;
    aspect-ratio: 1/1;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid var(--border);
    background: var(--surface-2);
  }
  .existing-img img {
    width: 100%; height: 100%; object-fit: cover; display: block;
  }
  .existing-img.marked { opacity: 0.35; border-color: var(--danger); }
  .existing-img label {
    position: absolute; inset: 0;
    display: flex; align-items: flex-end; justify-content: center;
    padding: 8px; cursor: pointer;
    background: linear-gradient(to top, rgba(0,0,0,0.7), transparent 50%);
    color: #fff; font-size: 12px; font-weight: 600;
    text-transform: uppercase;
  }
  .existing-img input[type="checkbox"] {
    position: absolute; top: 8px; right: 8px;
    width: 20px; height: 20px; cursor: pointer;
  }

  /* File input styled */
  .file-drop {
    border: 2px dashed var(--border);
    border-radius: 8px;
    padding: 24px;
    text-align: center;
    background: var(--surface-2);
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
  }
  .file-drop:hover { border-color: var(--accent); background: var(--surface); }
  .file-drop input[type="file"] { display: none; }
  .file-drop-label {
    font-weight: 600;
    color: var(--accent);
    display: block;
    margin-bottom: 4px;
  }
  .file-drop-hint { font-size: 12px; color: var(--text-muted); }
  .file-list { margin-top: 10px; font-size: 13px; color: var(--text-muted); }
  .file-list-item { padding: 2px 0; }

  .form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
  }

  /* Post list */
  .post-list {
    display: flex; flex-direction: column;
  }
  .post-row {
    display: grid;
    grid-template-columns: 1.2fr 1.6fr auto auto auto;
    gap: 16px;
    padding: 14px 20px;
    border-top: 1px solid var(--border);
    align-items: center;
  }
  .post-row:first-child { border-top: none; }
  .post-row .meta-company { font-weight: 600; }
  .post-row .meta-date    { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
  .post-row .caption-preview {
    color: var(--text-muted);
    font-size: 14px;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    text-overflow: ellipsis;
  }
  .pill {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    padding: 4px 10px; border-radius: 20px;
    background: var(--surface-2);
    color: var(--text-muted);
    white-space: nowrap;
  }
  .pill.pending  { background: #fef3c7; color: #92400e; }
  .pill.approved { background: #dcfce7; color: #166534; }
  .pill.denied   { background: #fee2e2; color: #991b1b; }
  [data-theme="dark"] .pill.pending  { background: #78350f; color: #fde68a; }
  [data-theme="dark"] .pill.approved { background: #14532d; color: #bbf7d0; }
  [data-theme="dark"] .pill.denied   { background: #7f1d1d; color: #fecaca; }
  .img-count {
    font-size: 12px;
    color: var(--text-muted);
    white-space: nowrap;
  }
  .row-actions { display: flex; gap: 6px; }
  .inline-form { display: inline; }

  /* Category checkbox group (used in both post & tire forms) */
  .cat-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 4px 0;
  }
  .cat-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
  }
  .cat-chip input { display: none; }
  .cat-chip:hover { background: var(--border); }
  .cat-chip.checked {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
  }
  .cat-chip.checked::before {
    content: '✓ ';
    font-weight: 700;
  }
  .cat-list {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 3px;
    font-style: italic;
  }
  .cat-list:empty::before {
    content: 'No categories';
    opacity: 0.5;
  }

  /* Section divider */
  .section-divider {
    margin: 40px 0 20px;
    padding-top: 24px;
    border-top: 2px solid var(--border);
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .section-divider h2 {
    margin: 0;
    font-size: 22px;
    letter-spacing: -0.3px;
  }
  .section-divider-sub {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 3px 8px;
    border-radius: 4px;
    background: var(--surface-2);
    border: 1px solid var(--border);
  }

  /* Tire image edit rows */
  .tire-edit-images {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 8px;
  }
  .tire-edit-row {
    display: grid;
    grid-template-columns: 100px 1fr auto;
    gap: 12px;
    align-items: center;
    padding: 10px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    transition: opacity 0.15s, border-color 0.15s;
  }
  .tire-edit-row.marked { opacity: 0.4; border-color: var(--danger); }
  .tire-edit-row img {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 6px;
    display: block;
    background: var(--border);
  }
  .tire-edit-row input[type="text"] {
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 10px 12px;
    border-radius: 6px;
    font: inherit;
    width: 100%;
  }
  .tire-edit-row input[type="text"]:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .tire-edit-row label.remove {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    cursor: pointer;
  }
  .tire-edit-row input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
  }
  @media (max-width: 600px) {
    .tire-edit-row {
      grid-template-columns: 80px 1fr;
      grid-template-rows: auto auto;
    }
    .tire-edit-row img { width: 80px; height: 80px; }
    .tire-edit-row label.remove {
      grid-column: 1 / -1;
      flex-direction: row;
      justify-content: flex-start;
    }
  }

  .empty {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-muted);
  }

  @media (max-width: 720px) {
    .form-grid { grid-template-columns: 1fr; }
    .post-row {
      grid-template-columns: 1fr;
      gap: 8px;
    }
    .row-actions { justify-content: flex-start; }
  }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <div class="brand-mark">J</div>
      <span>Approval Feed</span>
      <span class="brand-sub">Admin</span>
    </div>
    <div class="top-actions">
      <button class="btn ghost sm" id="themeToggle" type="button" aria-label="Toggle theme">
        <span id="themeIcon">🌙</span>
      </button>
      <a class="btn sm" href="index.php" target="_blank">View feed ↗</a>
      <a class="btn sm" href="tires.php" target="_blank">View tires ↗</a>
    </div>
  </div>
</header>

<div class="wrap">

  <?php if ($flash): ?>
    <div class="flash">✓ <?= h($flash) ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="errors">
      <?php foreach ($errors as $err): ?>
        <div>⚠ <?= h($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ADD / EDIT FORM ------------------------------------------------ -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><?= h($formTitle) ?></h2>
      <?php if ($isEdit): ?>
        <a class="btn sm ghost" href="admin.php">Cancel edit</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" action="admin.php" enctype="multipart/form-data" id="postForm">
        <input type="hidden" name="action" value="<?= h($formAction) ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$editPost['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
          <div class="field">
            <label for="company_id">Company</label>
            <select name="company_id" id="company_id" required>
              <option value="">— Select company —</option>
              <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $val_company_id == $c['id'] ? 'selected' : '' ?>>
                  <?= h($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="help">Manage companies in phpMyAdmin → `companies` table.</span>
          </div>

          <div class="field">
            <label for="scheduled_date">Scheduled date &amp; time</label>
            <input type="datetime-local" name="scheduled_date" id="scheduled_date"
                   value="<?= h($val_datetime) ?>" required>
          </div>

          <div class="field full">
            <label for="caption">Caption</label>
            <textarea name="caption" id="caption" class="caption" required
                      placeholder="What's the post say?"><?= h($val_caption) ?></textarea>
          </div>

          <div class="field full">
            <label for="hashtags">Hashtags</label>
            <textarea name="hashtags" id="hashtags"
                      placeholder="#Brand #Campaign #Keyword"><?= h($val_hashtags) ?></textarea>
          </div>

          <div class="field">
            <label for="status">Status</label>
            <select name="status" id="status">
              <option value="pending"  <?= $val_status === 'pending'  ? 'selected' : '' ?>>Pending</option>
              <option value="approved" <?= $val_status === 'approved' ? 'selected' : '' ?>>Approved</option>
              <option value="denied"   <?= $val_status === 'denied'   ? 'selected' : '' ?>>Denied</option>
            </select>
          </div>

          <div class="field full">
            <label>Categories</label>
            <div class="cat-group">
              <?php foreach ($allCategories as $cat):
                $checked = in_array((int)$cat['id'], $editPostCategories, true);
              ?>
                <label class="cat-chip <?= $checked ? 'checked' : '' ?>" data-cat-chip>
                  <input type="checkbox"
                         name="categories[]"
                         value="<?= (int)$cat['id'] ?>"
                         <?= $checked ? 'checked' : '' ?>>
                  <?= h($cat['name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <span class="help">Click chips to toggle. Multiple categories allowed.</span>
          </div>

          <div class="field">
            <label>Images</label>
            <span class="help">
              Max <?= $maxImages ?> per post, up to 10 MB each. JPG, PNG, GIF, or WebP.
            </span>
          </div>

          <?php if ($isEdit && $editImages): ?>
            <div class="field full">
              <label>Existing images — tick to remove on save</label>
              <div class="existing-images">
                <?php foreach ($editImages as $img): ?>
                  <div class="existing-img" data-img-wrap>
                    <img src="<?= h($img['image_url']) ?>" alt="">
                    <input type="checkbox" name="remove_images[]"
                           value="<?= (int)$img['id'] ?>"
                           data-remove-checkbox
                           title="Remove this image">
                  </div>
                <?php endforeach; ?>
              </div>
              <span class="help">Currently <?= count($editImages) ?> of <?= $maxImages ?> slots used.</span>
            </div>
          <?php endif; ?>

          <div class="field full">
            <label for="images">
              <?= $isEdit ? 'Add more images' : 'Upload images' ?>
            </label>
            <label class="file-drop">
              <input type="file" name="images[]" id="images"
                     accept="image/jpeg,image/png,image/gif,image/webp" multiple>
              <span class="file-drop-label">Click to choose files</span>
              <span class="file-drop-hint">or drag &amp; drop them here</span>
            </label>
            <div class="file-list" id="fileList"></div>
          </div>
        </div>

        <div class="form-actions">
          <?php if ($isEdit): ?>
            <a class="btn" href="admin.php">Cancel</a>
          <?php endif; ?>
          <button type="submit" class="btn primary"><?= h($formSubmitText) ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- BATCH UPLOAD --------------------------------------------------- -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">⚡ Batch upload</h2>
      <span class="brand-sub">Quick post creator</span>
    </div>
    <div class="card-body">
      <p class="help" style="margin: 0 0 16px; font-size: 13px; line-height: 1.5;">
        Upload multiple images to create one post per image, spaced <strong>3 days apart</strong> from the latest scheduled post for the selected company.
        Caption defaults to <code>Please insert caption here</code> — edit later in the feed.
        Name files with category keywords (e.g. <code>yamaha_atv_trail.jpg</code>) to auto-tag.
      </p>
      <form method="POST" action="admin.php" enctype="multipart/form-data" id="batchForm">
        <input type="hidden" name="action" value="batch_create">

        <div class="form-grid">
          <div class="field">
            <label for="batch_company_id">Company</label>
            <select name="company_id" id="batch_company_id" required>
              <option value="">— Select company —</option>
              <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="spacing_days">Days between posts</label>
            <input type="number" name="spacing_days" id="spacing_days"
                   value="3" min="1" max="30" style="width:100%;">
            <span class="help">Spacing starts from the selected company's latest scheduled post.</span>
          </div>

          <div class="field full">
            <label>Category keywords for filenames</label>
            <div class="cat-group" style="pointer-events: none;">
              <?php foreach ($allCategories as $cat): ?>
                <span class="cat-chip" style="opacity: 0.75;"><?= h(strtolower($cat['name'])) ?></span>
              <?php endforeach; ?>
            </div>
            <span class="help">
              Include any of these words in the filename and the post will be auto-tagged.
              Also accepts <code>offroad</code>, <code>dualsport</code>, <code>sportatv</code>.
            </span>
          </div>

          <div class="field full">
            <label for="batch_images">Images</label>
            <label class="file-drop">
              <input type="file" name="batch_images[]" id="batch_images"
                     accept="image/jpeg,image/png,image/gif,image/webp" multiple>
              <span class="file-drop-label">Click to choose files</span>
              <span class="file-drop-hint">or drag &amp; drop them here — up to 10 MB each</span>
            </label>
            <div class="file-list" id="batchFileList"></div>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn primary" id="batchSubmit">🚀 Create batch</button>
        </div>
      </form>
    </div>
  </div>

  <!-- POST LIST ------------------------------------------------------ -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">All posts (<?= count($allPosts) ?>)</h2>
    </div>
    <?php if (empty($allPosts)): ?>
      <div class="empty">No posts yet. Create your first one above.</div>
    <?php else: ?>
      <div class="post-list">
        <?php foreach ($allPosts as $p): ?>
          <div class="post-row">
            <div>
              <div class="meta-company"><?= h($p['company_name']) ?></div>
              <div class="meta-date">
                <?= h(date('M j, Y · g:i A', strtotime($p['scheduled_date']))) ?>
              </div>
              <div class="cat-list"><?= h($p['category_names'] ?? '') ?></div>
            </div>
            <div class="caption-preview"><?= h($p['caption']) ?></div>
            <div class="img-count">🖼 <?= (int)$p['image_count'] ?></div>
            <div>
              <span class="pill <?= h($p['status']) ?>"><?= h($p['status']) ?></span>
            </div>
            <div class="row-actions">
              <a class="btn sm" href="admin.php?edit=<?= (int)$p['id'] ?>">Edit</a>
              <form method="POST" action="admin.php" class="inline-form"
                    onsubmit="return confirm('Delete this post and all its images? This cannot be undone.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn sm danger">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ============================================================ -->
  <!-- TIRES SECTION                                                 -->
  <!-- ============================================================ -->
  <div class="section-divider">
    <h2>Tires</h2>
    <span class="section-divider-sub">Gallery content</span>
  </div>

  <!-- TIRE ADD / EDIT FORM ------------------------------------------ -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><?= h($tireFormTitle) ?></h2>
      <?php if ($isTireEdit): ?>
        <a class="btn sm ghost" href="admin.php">Cancel edit</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" action="admin.php" enctype="multipart/form-data" id="tireForm">
        <input type="hidden" name="action" value="<?= h($tireFormAction) ?>">
        <?php if ($isTireEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$editTire['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
          <div class="field full">
            <label for="tire_name">Tire name</label>
            <input type="text" name="tire_name" id="tire_name"
                   value="<?= h($val_tire_name) ?>"
                   placeholder="e.g. Kenda Klever R/T" required>
          </div>

          <div class="field full">
            <label>Categories</label>
            <div class="cat-group">
              <?php foreach ($allCategories as $cat):
                $checked = in_array((int)$cat['id'], $editTireCategories, true);
              ?>
                <label class="cat-chip <?= $checked ? 'checked' : '' ?>" data-cat-chip>
                  <input type="checkbox"
                         name="tire_categories[]"
                         value="<?= (int)$cat['id'] ?>"
                         <?= $checked ? 'checked' : '' ?>>
                  <?= h($cat['name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <span class="help">Click chips to toggle. Multiple categories allowed.</span>
          </div>

          <?php if ($isTireEdit && $editTireImages): ?>
            <div class="field full">
              <label>Existing images — edit captions or tick to remove</label>
              <div class="tire-edit-images">
                <?php foreach ($editTireImages as $img): ?>
                  <div class="tire-edit-row" data-tire-row>
                    <img src="<?= h($img['image_url']) ?>" alt="">
                    <input type="text"
                           name="captions[<?= (int)$img['id'] ?>]"
                           value="<?= h($img['caption']) ?>"
                           placeholder="Caption for this image">
                    <label class="remove">
                      <input type="checkbox"
                             name="remove_tire_images[]"
                             value="<?= (int)$img['id'] ?>"
                             data-tire-remove>
                      Remove
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
              <span class="help">
                Currently <?= count($editTireImages) ?> of <?= $maxTireImages ?> slots used.
              </span>
            </div>
          <?php endif; ?>

          <?php if ($isTireEdit): ?>
            <div class="field full">
              <label for="tire_images">Add more images</label>
              <label class="file-drop">
                <input type="file" name="tire_images[]" id="tire_images"
                       accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                <span class="file-drop-label">Click to choose files</span>
                <span class="file-drop-hint">
                  Max <?= $maxTireImages ?> per tire, 10 MB each. Captions editable after upload.
                </span>
              </label>
              <div class="file-list" id="tireFileList"></div>
            </div>
          <?php else: ?>
            <div class="field full">
              <span class="help">Create the tire first, then add images on the edit screen.</span>
            </div>
          <?php endif; ?>
        </div>

        <div class="form-actions">
          <?php if ($isTireEdit): ?>
            <a class="btn" href="admin.php">Cancel</a>
          <?php endif; ?>
          <button type="submit" class="btn primary"><?= h($tireFormSubmitText) ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- TIRE LIST ------------------------------------------------------ -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">All tires (<?= count($allTires) ?>)</h2>
    </div>
    <?php if (empty($allTires)): ?>
      <div class="empty">No tires yet. Create your first one above.</div>
    <?php else: ?>
      <div class="post-list">
        <?php foreach ($allTires as $t): ?>
          <div class="post-row" style="grid-template-columns: 1fr auto auto;">
            <div>
              <div class="meta-company"><?= h($t['name']) ?></div>
              <div class="cat-list"><?= h($t['category_names'] ?? '') ?></div>
            </div>
            <div class="img-count">🖼 <?= (int)$t['image_count'] ?> / <?= $maxTireImages ?></div>
            <div class="row-actions">
              <a class="btn sm" href="admin.php?edit_tire=<?= (int)$t['id'] ?>">Edit</a>
              <form method="POST" action="admin.php" class="inline-form"
                    onsubmit="return confirm('Delete this tire and all its images? This cannot be undone.');">
                <input type="hidden" name="action" value="tire_delete">
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
  // Theme toggle
  const themeBtn  = document.getElementById('themeToggle');
  const themeIcon = document.getElementById('themeIcon');
  let isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const applyTheme = () => {
    document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
    themeIcon.textContent = isDark ? '☀️' : '🌙';
  };
  applyTheme();
  themeBtn.addEventListener('click', () => { isDark = !isDark; applyTheme(); });

  // File list preview
  const fileInput = document.getElementById('images');
  const fileList  = document.getElementById('fileList');
  if (fileInput) {
    fileInput.addEventListener('change', () => {
      if (!fileInput.files.length) {
        fileList.innerHTML = '';
        return;
      }
      fileList.innerHTML = '<strong>Selected:</strong>';
      [...fileInput.files].forEach(f => {
        const div = document.createElement('div');
        div.className = 'file-list-item';
        div.textContent = '• ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
        fileList.appendChild(div);
      });
    });
  }

  // Drag & drop — wire up every .file-drop on the page
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

  // Tire file list preview
  const tireFileInput = document.getElementById('tire_images');
  const tireFileList  = document.getElementById('tireFileList');
  if (tireFileInput && tireFileList) {
    tireFileInput.addEventListener('change', () => {
      if (!tireFileInput.files.length) {
        tireFileList.innerHTML = '';
        return;
      }
      tireFileList.innerHTML = '<strong>Selected:</strong>';
      [...tireFileInput.files].forEach(f => {
        const div = document.createElement('div');
        div.className = 'file-list-item';
        div.textContent = '• ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
        tireFileList.appendChild(div);
      });
    });
  }

  // Visual feedback on "remove image" checkboxes (posts)
  document.querySelectorAll('[data-remove-checkbox]').forEach(cb => {
    cb.addEventListener('change', () => {
      cb.closest('[data-img-wrap]').classList.toggle('marked', cb.checked);
    });
  });

  // Visual feedback on tire "remove" checkboxes
  document.querySelectorAll('[data-tire-remove]').forEach(cb => {
    cb.addEventListener('change', () => {
      cb.closest('[data-tire-row]').classList.toggle('marked', cb.checked);
    });
  });

  // Batch uploader — live file preview with category matching
  const batchFileInput = document.getElementById('batch_images');
  const batchFileList  = document.getElementById('batchFileList');
  const allCatNames = <?= json_encode(array_map(fn($c) => strtolower($c['name']), $allCategories)) ?>;

  function matchCategoriesFromFilename(filename) {
    const base = ' ' + filename.replace(/\.[^.]+$/, '').toLowerCase().replace(/[-_.]+/g, ' ').replace(/\s+/g, ' ').trim() + ' ';
    const matched = new Set();
    // Sort longest first so "sport atv" matches before "atv"
    const sorted = [...allCatNames].sort((a, b) => b.length - a.length);
    for (const cat of sorted) {
      if (base.includes(' ' + cat + ' ')) { matched.add(cat); }
    }
    const aliases = { 'offroad': 'off-road', 'dualsport': 'dual sport', 'sportatv': 'sport atv' };
    for (const [alias, cat] of Object.entries(aliases)) {
      if (base.includes(' ' + alias + ' ') && allCatNames.includes(cat)) { matched.add(cat); }
    }
    return [...matched];
  }

  if (batchFileInput && batchFileList) {
    batchFileInput.addEventListener('change', () => {
      if (!batchFileInput.files.length) {
        batchFileList.innerHTML = '';
        return;
      }
      const count = batchFileInput.files.length;
      batchFileList.innerHTML = '<strong>Selected ' + count + ' image' + (count !== 1 ? 's' : '') + ':</strong>';
      [...batchFileInput.files].forEach(f => {
        const cats = matchCategoriesFromFilename(f.name);
        const div = document.createElement('div');
        div.className = 'file-list-item';
        const sizeStr = (f.size / 1024 / 1024).toFixed(2) + ' MB';
        const catsStr = cats.length
          ? ' → ' + cats.join(', ')
          : ' → no category match';
        div.textContent = '• ' + f.name + ' (' + sizeStr + ')' + catsStr;
        if (!cats.length) { div.style.opacity = '0.7'; }
        batchFileList.appendChild(div);
      });
    });
  }

  // Disable batch submit button once clicked to prevent double-submits
  const batchForm   = document.getElementById('batchForm');
  const batchSubmit = document.getElementById('batchSubmit');
  if (batchForm && batchSubmit) {
    batchForm.addEventListener('submit', () => {
      batchSubmit.disabled = true;
      batchSubmit.textContent = 'Uploading…';
    });
  }

  // Category chip toggle (visual state)
  document.querySelectorAll('[data-cat-chip]').forEach(chip => {
    const cb = chip.querySelector('input[type="checkbox"]');
    if (!cb) return;
    cb.addEventListener('change', () => {
      chip.classList.toggle('checked', cb.checked);
    });
  });
</script>

</body>
</html>
