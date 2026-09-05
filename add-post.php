<?php
/**
 * Posts module — create / edit / batch upload / delete.
 * Redirects back to admin.php after a successful save.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

// -------------------------------------------------------------
// Require a client in scope
// -------------------------------------------------------------
if (!$client) {
    header('Location: admin?msg=' . urlencode('Pick a client first.'));
    exit;
}
$clientQs = 'client=' . urlencode($client['slug']);

// -------------------------------------------------------------
// Config
// -------------------------------------------------------------
$uploadsDir  = __DIR__ . '/uploads';
$uploadsUrl  = 'uploads';
$allowedExt  = array_merge(imageExts(), videoExts()); // jpg/png/gif/webp + mp4/webm
$rejectedExt = ['mov', 'm4v', 'avi', 'mkv']; // common but unsupported by web browsers
$maxFileSize = 25 * 1024 * 1024; // 25 MB (videos are bigger than images)
$maxImages   = 10;               // applies to combined images + videos per post

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
                // Scope guard — only delete if the post belongs to this client
                $chk = $pdo->prepare("SELECT 1 FROM posts WHERE id = ? AND company_id = ? LIMIT 1");
                $chk->execute([$postId, $client['id']]);
                if (!$chk->fetchColumn()) {
                    throw new Exception('That post does not belong to ' . $client['name'] . '.');
                }
                $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
                logActivity($pdo, (int)$client['id'], 'post', $postId,
                    'deleted', 'admin', "Deleted post #{$postId}");
                $pdo->commit();
                header('Location: admin?' . $clientQs . '&msg=' . urlencode('Post deleted.'));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        }
    }

    // ---- Create / Update --------------------------------------
    if ($action === 'create' || $action === 'update') {
        // Company is always the active client — never trust the form for this
        $company_id     = (int)$client['id'];
        $postName       = trim($_POST['name'] ?? '');
        if (mb_strlen($postName) > 150) { $postName = mb_substr($postName, 0, 150); }
        $caption        = trim($_POST['caption'] ?? '');
        $hashtags       = trim($_POST['hashtags'] ?? '');
        $scheduled_date = trim($_POST['scheduled_date'] ?? '');
        $status         = $_POST['status'] ?? 'pending';
        $postType       = strtolower(trim($_POST['post_type'] ?? 'post'));

        if ($caption === '')       { $errors[] = 'Caption is required.'; }
        if ($scheduled_date === ''){ $errors[] = 'Scheduled date is required.'; }
        if (!in_array($status, ['pending', 'approved', 'denied'], true)) {
            $status = 'pending';
        }
        if (!in_array($postType, allowedPostTypes(), true)) {
            $postType = 'post';
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
                $supportsName = hasPostsNameColumn($pdo);
                $supportsType = hasPostTypeColumn($pdo);
                $nameForDb    = $postName === '' ? null : $postName;

                if ($action === 'create') {
                    // Build column list dynamically based on what's been migrated.
                    $cols = ['company_id'];
                    $vals = [$company_id];
                    if ($supportsName) { $cols[] = 'name';     $vals[] = $nameForDb; }
                    $cols[] = 'caption';        $vals[] = $caption;
                    $cols[] = 'hashtags';       $vals[] = $hashtags;
                    $cols[] = 'scheduled_date'; $vals[] = $dtFormatted;
                    $cols[] = 'status';         $vals[] = $status;
                    if ($supportsType) { $cols[] = 'post_type'; $vals[] = $postType; }
                    $placeholders = implode(',', array_fill(0, count($vals), '?'));
                    $sql = "INSERT INTO posts (" . implode(',', $cols) . ") VALUES ({$placeholders})";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($vals);
                    $postId = (int)$pdo->lastInsertId();
                    $createdLabel = $postName !== '' ? $postName : mb_substr($caption, 0, 200);
                    logActivity($pdo, $company_id, 'post', $postId, 'created', 'admin',
                        "Created post #{$postId}: " . $createdLabel);
                } else {
                    $postId = (int)($_POST['id'] ?? 0);
                    if ($postId <= 0) { throw new Exception('Invalid post id.'); }
                    // Scope guard — post must belong to this client
                    $chk = $pdo->prepare("SELECT 1 FROM posts WHERE id = ? AND company_id = ? LIMIT 1");
                    $chk->execute([$postId, $client['id']]);
                    if (!$chk->fetchColumn()) {
                        throw new Exception('That post does not belong to ' . $client['name'] . '.');
                    }
                    // Capture before-values for diff logging.
                    $nameSel = $supportsName ? 'name' : "'' AS name";
                    $typeSel = $supportsType ? 'post_type' : "'post' AS post_type";
                    $pre = $pdo->prepare("SELECT {$nameSel}, caption, hashtags, scheduled_date, status, {$typeSel} FROM posts WHERE id = ?");
                    $pre->execute([$postId]);
                    $prev = $pre->fetch();

                    // Build the UPDATE SET clause dynamically — only includes columns the schema actually has.
                    $setCols = ['company_id = ?'];
                    $setVals = [$company_id];
                    if ($supportsName) { $setCols[] = 'name = ?'; $setVals[] = $nameForDb; }
                    $setCols[] = 'caption = ?';        $setVals[] = $caption;
                    $setCols[] = 'hashtags = ?';       $setVals[] = $hashtags;
                    $setCols[] = 'scheduled_date = ?'; $setVals[] = $dtFormatted;
                    $setCols[] = 'status = ?';         $setVals[] = $status;
                    if ($supportsType) { $setCols[] = 'post_type = ?'; $setVals[] = $postType; }
                    $setVals[] = $postId;
                    $sql = "UPDATE posts SET " . implode(', ', $setCols) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($setVals);

                    if ($prev) {
                        $batchId = newBatchId();
                        if ($supportsName && (string)($prev['name'] ?? '') !== (string)$postName) {
                            $oldLabel = ($prev['name'] ?? '') === '' ? '(unnamed)' : $prev['name'];
                            $newLabel = $postName === '' ? '(unnamed)' : $postName;
                            logActivity($pdo, $company_id, 'post', $postId,
                                'renamed_post', 'admin',
                                "Renamed post #{$postId}",
                                $oldLabel . ' → ' . $newLabel,
                                $batchId);
                        }
                        if ((string)$prev['caption'] !== (string)$caption) {
                            logActivity($pdo, $company_id, 'post', $postId,
                                'edited_caption', 'admin',
                                "Edited caption on post #{$postId}",
                                mb_substr((string)$prev['caption'], 0, 200) . ' → ' . mb_substr($caption, 0, 200),
                                $batchId);
                        }
                        if ((string)$prev['hashtags'] !== (string)$hashtags) {
                            logActivity($pdo, $company_id, 'post', $postId,
                                'edited_hashtags', 'admin',
                                "Edited hashtags on post #{$postId}",
                                mb_substr((string)$prev['hashtags'], 0, 200) . ' → ' . mb_substr($hashtags, 0, 200),
                                $batchId);
                        }
                        if ((string)$prev['scheduled_date'] !== (string)$dtFormatted) {
                            logActivity($pdo, $company_id, 'post', $postId,
                                'edited_schedule', 'admin',
                                "Rescheduled post #{$postId}",
                                ($prev['scheduled_date'] ?? '') . ' → ' . ($dtFormatted ?? ''),
                                $batchId);
                        }
                        if ($prev['status'] !== $status) {
                            $stAction = ($status === 'approved') ? 'approved'
                                      : (($status === 'denied')  ? 'denied' : 'reset_pending');
                            logActivity($pdo, $company_id, 'post', $postId,
                                $stAction, 'admin',
                                "Post #{$postId} " . actionLabel($stAction),
                                null, $batchId);
                        }
                        if ($supportsType && (string)($prev['post_type'] ?? 'post') !== (string)$postType) {
                            logActivity($pdo, $company_id, 'post', $postId,
                                'edited_type', 'admin',
                                "Changed type on post #{$postId}",
                                ($prev['post_type'] ?? 'post') . ' → ' . $postType,
                                $batchId);
                        }
                    }
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
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM post_images WHERE post_id = ?");
                    $cnt->execute([$postId]);
                    $existing = (int)$cnt->fetchColumn();

                    $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM post_images WHERE post_id = ?");
                    $sortQ->execute([$postId]);
                    $sortOrder = (int)$sortQ->fetchColumn();

                    $slots = $maxImages - $existing;
                    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

                    $uploadedCount = 0;
                    foreach ($_FILES['images']['name'] as $i => $origName) {
                        if ($uploadedCount >= $slots) {
                            $errors[] = "Max {$maxImages} files per post — some were skipped.";
                            break;
                        }
                        $err = $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                        if ($err === UPLOAD_ERR_NO_FILE) { continue; }
                        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                            $iniMax = ini_get('upload_max_filesize') ?: '?';
                            $errors[] = "'{$origName}' is too large for this server (PHP limit: {$iniMax}). "
                                      . "Ask hosting to raise upload_max_filesize and post_max_size.";
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
                        if (in_array($ext, $rejectedExt, true)) {
                            $errors[] = "'{$origName}' is a .{$ext} file — please convert to MP4 first "
                                      . "(QuickTime: File → Export As → 1080p). Chrome and Firefox can't play .{$ext}.";
                            continue;
                        }
                        if (!in_array($ext, $allowedExt, true)) {
                            $errors[] = "'{$origName}' has an unsupported file type. "
                                      . "Allowed: JPG, PNG, GIF, WebP, MP4, WebM.";
                            continue;
                        }
                        $isVideo = isVideoExt($ext);
                        if ($isVideo) {
                            // For videos we can't use getimagesize. Just verify the
                            // temp file exists and is non-empty.
                            if (!is_file($_FILES['images']['tmp_name'][$i])
                                || filesize($_FILES['images']['tmp_name'][$i]) === 0) {
                                $errors[] = "'{$origName}' appears to be empty.";
                                continue;
                            }
                        } else {
                            $finfo = @getimagesize($_FILES['images']['tmp_name'][$i]);
                            if ($finfo === false) {
                                $errors[] = "'{$origName}' is not a valid image.";
                                continue;
                            }
                        }

                        $prefix  = $isVideo ? 'vid_' : 'img_';
                        $newName = uniqid($prefix, true) . '.' . $ext;
                        $newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $newName);
                        $dest    = $uploadsDir . '/' . $newName;

                        if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $dest)) {
                            $sortOrder++;
                            if (hasMediaTypeColumn($pdo)) {
                                $ins = $pdo->prepare("
                                    INSERT INTO post_images (post_id, image_url, media_type, sort_order)
                                    VALUES (?, ?, ?, ?)
                                ");
                                $ins->execute([
                                    $postId,
                                    $uploadsUrl . '/' . $newName,
                                    $isVideo ? 'video' : 'image',
                                    $sortOrder,
                                ]);
                            } else {
                                $ins = $pdo->prepare("
                                    INSERT INTO post_images (post_id, image_url, sort_order)
                                    VALUES (?, ?, ?)
                                ");
                                $ins->execute([
                                    $postId,
                                    $uploadsUrl . '/' . $newName,
                                    $sortOrder,
                                ]);
                            }
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
                header('Location: admin?' . $clientQs . '&msg=' . urlencode($msg));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }

    // ---- Batch create -----------------------------------------
    if ($action === 'batch_create') {
        $company_id  = (int)$client['id'];
        $spacingDays = max(1, min(30, (int)($_POST['spacing_days'] ?? 3)));

        if (empty($_FILES['batch_images']) || !is_array($_FILES['batch_images']['name'])) {
            $errors[] = 'No images uploaded.';
        }

        if (!$errors) {
            $dateStmt = $pdo->prepare("SELECT MAX(scheduled_date) FROM posts WHERE company_id = ?");
            $dateStmt->execute([$company_id]);
            $latest = $dateStmt->fetchColumn();
            $baseDate = $latest ? new DateTime($latest) : new DateTime();

            $catRows = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order")->fetchAll();
            $catMap = [];
            foreach ($catRows as $cat) {
                $catMap[strtolower($cat['name'])] = (int)$cat['id'];
            }
            uksort($catMap, function($a, $b) { return strlen($b) - strlen($a); });

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
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    $iniMax = ini_get('upload_max_filesize') ?: '?';
                    $errors[] = "'{$origName}' is too large for this server (PHP limit: {$iniMax}).";
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    $errors[] = "Upload error on '{$origName}' (code {$err}).";
                    continue;
                }
                if ($_FILES['batch_images']['size'][$i] > $maxFileSize) {
                    $mb = number_format($maxFileSize / (1024 * 1024), 0);
                    $errors[] = "'{$origName}' exceeds {$mb} MB.";
                    continue;
                }
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (in_array($ext, $rejectedExt, true)) {
                    $errors[] = "'{$origName}' is a .{$ext} file — please convert to MP4 first.";
                    continue;
                }
                if (!in_array($ext, $allowedExt, true)) {
                    $errors[] = "'{$origName}' has an unsupported file type.";
                    continue;
                }
                $isVideo = isVideoExt($ext);
                if ($isVideo) {
                    if (!is_file($_FILES['batch_images']['tmp_name'][$i])
                        || filesize($_FILES['batch_images']['tmp_name'][$i]) === 0) {
                        $errors[] = "'{$origName}' appears to be empty.";
                        continue;
                    }
                } else {
                    $finfo = @getimagesize($_FILES['batch_images']['tmp_name'][$i]);
                    if ($finfo === false) {
                        $errors[] = "'{$origName}' is not a valid image.";
                        continue;
                    }
                }

                $prefix  = $isVideo ? 'batch_vid_' : 'batch_';
                $newName = uniqid($prefix, true) . '.' . $ext;
                $newName = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $newName);
                $dest    = $uploadsDir . '/' . $newName;

                if (!move_uploaded_file($_FILES['batch_images']['tmp_name'][$i], $dest)) {
                    $errors[] = "Failed to save '{$origName}'.";
                    continue;
                }

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

                $baseDate->modify('+' . $spacingDays . ' days');
                $scheduledDate = $baseDate->format('Y-m-d H:i:s');

                try {
                    $pdo->beginTransaction();
                    $defaultHashtags = trim((string)($client['default_hashtags'] ?? ''));
                    $ins = $pdo->prepare("
                        INSERT INTO posts (company_id, caption, hashtags, scheduled_date, status)
                        VALUES (?, 'Please insert caption here', ?, ?, 'pending')
                    ");
                    $ins->execute([$company_id, $defaultHashtags, $scheduledDate]);
                    $postId = (int)$pdo->lastInsertId();
                    logActivity($pdo, $company_id, 'post', $postId, 'created', 'admin',
                        "Created post #{$postId} via batch upload");

                    if (hasMediaTypeColumn($pdo)) {
                        $imgIns = $pdo->prepare("
                            INSERT INTO post_images (post_id, image_url, media_type, sort_order)
                            VALUES (?, ?, ?, 1)
                        ");
                        $imgIns->execute([
                            $postId,
                            $uploadsUrl . '/' . $newName,
                            $isVideo ? 'video' : 'image',
                        ]);
                    } else {
                        $imgIns = $pdo->prepare("
                            INSERT INTO post_images (post_id, image_url, sort_order)
                            VALUES (?, ?, 1)
                        ");
                        $imgIns->execute([
                            $postId,
                            $uploadsUrl . '/' . $newName,
                        ]);
                    }

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
            if ($errors) { $msg .= ' (Warnings: ' . implode(' ', $errors) . ')'; }
            header('Location: admin?' . $clientQs . '&msg=' . urlencode($msg));
            exit;
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
$editPostCategories = [];
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ? AND company_id = ?");
    $stmt->execute([$editId, $client['id']]);
    $editPost = $stmt->fetch();
    if ($editPost) {
        // media_type only lands in this SELECT once migrate.php has added the column.
        // Otherwise we fall back to a derived 'image'/'video' from the file extension.
        $mediaTypeSel = hasMediaTypeColumn($pdo)
            ? ', media_type'
            : ", '' AS media_type";
        $imgStmt = $pdo->prepare("
            SELECT id, image_url{$mediaTypeSel} FROM post_images
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

$isEdit         = (bool)$editPost;
$formAction     = $isEdit ? 'update' : 'create';
$formTitle      = $isEdit
    ? 'Edit ' . (!empty($editPost['name'])
                    ? '"' . $editPost['name'] . '"'
                    : 'post #' . (int)$editPost['id'])
    : 'New post';
$formSubmitText = $isEdit ? 'Save changes' : 'Create post';

$clientDefaultHashtags = trim((string)($client['default_hashtags'] ?? ''));

$val_company_id = $isEdit ? (int)$editPost['company_id'] : '';
$val_name       = $isEdit ? (string)($editPost['name'] ?? '') : '';
$val_caption    = $isEdit ? $editPost['caption']          : '';
// New posts pre-fill from the client's default hashtags. Edits leave the existing
// value untouched — admins use the "Append client defaults" button below if they
// want to merge them in.
$val_hashtags   = $isEdit ? $editPost['hashtags']         : $clientDefaultHashtags;
$val_status     = $isEdit ? $editPost['status']           : 'pending';
$val_post_type  = $isEdit ? strtolower((string)($editPost['post_type'] ?? 'post')) : 'post';
if (!in_array($val_post_type, allowedPostTypes(), true)) { $val_post_type = 'post'; }
$val_datetime   = $isEdit
    ? date('Y-m-d\TH:i', strtotime($editPost['scheduled_date']))
    : date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $isEdit ? 'Edit post' : 'Add a post' ?> — Joust Admin</title>
<?= renderAppHead() ?>
<style>
  :root {
    --bg: #f0f2f5; --surface: #ffffff; --surface-2: #f7f8fa;
    --border: #dadde1; --text: #050505; --text-muted: #65676b;
    --accent: #1877f2; --accent-hover: #166fe5;
    --danger: #dc2626; --danger-hover: #b91c1c;
    --success: #16a34a; --warn: #f59e0b;
    --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04);
  }
  [data-theme="dark"] {
    --bg: #18191a; --surface: #242526; --surface-2: #3a3b3c;
    --border: #3e4042; --text: #e4e6eb; --text-muted: #b0b3b8;
    --accent: #2d88ff; --accent-hover: #4599ff;
    --danger: #ef4444; --danger-hover: #dc2626;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 15px; line-height: 1.4; min-height: 100vh;
  }
  .topbar {
    position: sticky; top: 0; z-index: 100;
    background: var(--surface); border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow);
  }
  .topbar-inner {
    max-width: 900px; margin: 0 auto;
    padding: 12px 20px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
  }
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
  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 8px 14px; border-radius: 8px;
    font-size: 14px; font-weight: 600; cursor: pointer;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text);
    text-decoration: none; transition: background 0.15s, transform 0.1s;
  }
  .btn:hover { background: var(--border); }
  .btn:active { transform: scale(0.98); }
  .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .btn.primary:hover { background: var(--accent-hover); }
  .btn.danger { background: var(--danger); color: #fff; border-color: var(--danger); }
  .btn.ghost { background: transparent; }
  .btn.sm { padding: 6px 10px; font-size: 13px; }

  .wrap { max-width: 900px; margin: 0 auto; padding: 24px 20px 80px; }
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

  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .form-grid .full { grid-column: 1 / -1; }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field label { font-size: 13px; font-weight: 600; color: var(--text-muted);
                 text-transform: uppercase; letter-spacing: 0.5px; }
  .field input[type="text"],
  .field input[type="number"],
  .field input[type="datetime-local"],
  .field select,
  .field textarea {
    background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text); padding: 10px 12px; border-radius: 8px;
    font: inherit; width: 100%; font-size: 15px;
  }
  .field textarea { resize: vertical; min-height: 80px; font-family: inherit; }
  .field textarea.caption { min-height: 120px; }
  .field input:focus, .field select:focus, .field textarea:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .field .help { font-size: 12px; color: var(--text-muted); }

  .existing-images {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px; margin-top: 8px;
  }
  .existing-img {
    position: relative; aspect-ratio: 1/1;
    border-radius: 8px; overflow: hidden;
    border: 2px solid var(--border); background: var(--surface-2);
  }
  .existing-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .existing-img.marked { opacity: 0.35; border-color: var(--danger); }
  .existing-img input[type="checkbox"] {
    position: absolute; top: 8px; right: 8px;
    width: 20px; height: 20px; cursor: pointer;
  }

  .file-drop {
    border: 2px dashed var(--border); border-radius: 8px;
    padding: 24px; text-align: center;
    background: var(--surface-2); cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
  }
  .file-drop:hover { border-color: var(--accent); background: var(--surface); }
  .file-drop input[type="file"] { display: none; }
  .file-drop-label { font-weight: 600; color: var(--accent); display: block; margin-bottom: 4px; }
  .file-drop-hint { font-size: 12px; color: var(--text-muted); }
  .file-list { margin-top: 10px; font-size: 13px; color: var(--text-muted); }
  .file-list-item { padding: 2px 0; }

  .form-actions { display: flex; gap: 10px; justify-content: flex-end;
                  margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }

  /* Comment thread */
  .comment-thread {
    display: flex; flex-direction: column; gap: 10px;
    margin-bottom: 12px;
  }
  .comment-msg {
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: 10px; padding: 10px 14px;
  }
  .comment-msg-client { border-left: 3px solid #2d88ff; }
  .comment-msg-admin  { border-left: 3px solid #a855f7; }
  .comment-msg-head {
    display: flex; gap: 8px; align-items: baseline; margin-bottom: 4px;
  }
  .comment-msg-actor {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.4px;
    color: var(--text-muted);
  }
  .comment-msg-actor::before { content: '@'; opacity: 0.5; }
  .comment-msg-time {
    font-size: 11px; color: var(--text-muted);
    margin-left: auto;
  }
  .comment-msg-body {
    font-size: 14px; color: var(--text);
    white-space: pre-wrap; word-wrap: break-word;
  }
  .comment-empty {
    padding: 16px; text-align: center;
    color: var(--text-muted); font-size: 13px; font-style: italic;
    background: var(--surface-2); border-radius: 8px;
  }
  .comment-form {
    display: flex; flex-direction: column; gap: 8px;
    padding-top: 12px; border-top: 1px solid var(--border);
  }
  .comment-form textarea {
    width: 100%; min-height: 70px;
    background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text); padding: 10px 12px; border-radius: 8px;
    font: inherit; font-size: 14px; resize: vertical;
  }
  .comment-form-actions {
    display: flex; justify-content: space-between; align-items: center; gap: 8px;
  }
  .comment-actor-pick { display: flex; gap: 4px; }
  .comment-actor-pick label {
    font-size: 11px; padding: 4px 8px; border-radius: 6px;
    cursor: pointer; user-select: none; background: var(--surface-2);
    border: 1px solid var(--border);
  }
  .comment-actor-pick input { display: none; }
  .comment-actor-pick label.checked {
    background: var(--accent); color: #fff; border-color: var(--accent);
  }

  /* Default-hashtags hint under the post hashtags textarea */
  .hashtag-defaults-hint {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    margin-top: 8px; padding: 8px 10px;
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: 8px;
  }
  .hashtag-defaults-snippet {
    flex: 1; min-width: 0;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12px; color: var(--text);
    background: transparent; padding: 0;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }

  .cat-group { display: flex; flex-wrap: wrap; gap: 8px; padding: 4px 0; }
  .cat-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; background: var(--surface-2);
    border: 1px solid var(--border); border-radius: 20px;
    font-size: 13px; font-weight: 600; cursor: pointer; user-select: none;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
  }
  .cat-chip input { display: none; }
  .cat-chip:hover { background: var(--border); }
  .cat-chip.checked { background: var(--accent); border-color: var(--accent); color: #fff; }
  .cat-chip.checked::before { content: '✓ '; font-weight: 700; }

  @media (max-width: 720px) { .form-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<?= renderAppChrome($isEdit ? 'Edit post' : 'Add a post', [
      'subtitle' => $client['name'],
      'active'   => 'studio',
      'width'    => '900px',
      'back'     => ['href' => 'admin?' . $clientQs, 'label' => 'Studio'],
      'links'    => [
        ['label' => 'Sign out', 'href' => 'logout', 'attrs' => ['title' => 'Signed in as ' . currentAdmin()]],
      ],
    ]) ?>

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
        <a class="btn sm ghost" href="add-post?<?= h($clientQs) ?>">Cancel edit</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" action="add-post?<?= h($clientQs) ?>" enctype="multipart/form-data" id="postForm">
        <input type="hidden" name="action" value="<?= h($formAction) ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$editPost['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
          <div class="field">
            <label>Company</label>
            <input type="text" value="<?= h($client['name']) ?>" disabled
                   style="background:var(--surface);color:var(--text-muted);cursor:not-allowed;">
            <span class="help">
              Locked — <a href="admin">switch client</a> to change.
            </span>
          </div>

          <div class="field">
            <label for="scheduled_date">Scheduled date &amp; time</label>
            <input type="datetime-local" name="scheduled_date" id="scheduled_date"
                   value="<?= h($val_datetime) ?>" required>
          </div>

          <div class="field full">
            <label for="name">
              Reference name
              <span style="font-weight: 500; text-transform: none; letter-spacing: 0; color: var(--text-muted);">
                — internal only, used in admin lists & activity log
              </span>
            </label>
            <input type="text" name="name" id="name" maxlength="150"
                   value="<?= h($val_name) ?>"
                   placeholder="e.g. Spring launch — hero shot">
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
            <?php if ($clientDefaultHashtags !== ''): ?>
              <div class="hashtag-defaults-hint">
                <span class="help">
                  <?= $isEdit ? "Client defaults available:" : "Pre-filled from client defaults." ?>
                </span>
                <code class="hashtag-defaults-snippet"
                      data-client-defaults="<?= h($clientDefaultHashtags) ?>"><?= h($clientDefaultHashtags) ?></code>
                <button type="button" class="btn sm" data-apply-defaults>
                  Append to hashtags
                </button>
                <a class="help" href="admin?<?= h($clientQs) ?>" style="margin-left:auto;">
                  Edit defaults ↗
                </a>
              </div>
            <?php else: ?>
              <span class="help">
                Tip: <a href="admin?<?= h($clientQs) ?>">set default hashtags</a> for <?= h($client['name']) ?>
                so every new post starts with them.
              </span>
            <?php endif; ?>
          </div>

          <div class="field">
            <label for="status">Status</label>
            <select name="status" id="status">
              <option value="pending"  <?= $val_status === 'pending'  ? 'selected' : '' ?>>Pending</option>
              <option value="approved" <?= $val_status === 'approved' ? 'selected' : '' ?>>Approved</option>
              <option value="denied"   <?= $val_status === 'denied'   ? 'selected' : '' ?>>Denied</option>
            </select>
          </div>

          <div class="field">
            <label for="post_type">Type</label>
            <select name="post_type" id="post_type">
              <option value="post"  <?= $val_post_type === 'post'  ? 'selected' : '' ?>>📄 Post</option>
              <option value="story" <?= $val_post_type === 'story' ? 'selected' : '' ?>>⭕ Story</option>
              <option value="reel"  <?= $val_post_type === 'reel'  ? 'selected' : '' ?>>🎬 Reel</option>
            </select>
            <span class="help">What kind of content is this? Defaults to Post.</span>
          </div>

          <div class="field full">
            <label>Categories</label>
            <div class="cat-group">
              <?php foreach ($allCategories as $cat):
                $checked = in_array((int)$cat['id'], $editPostCategories, true);
              ?>
                <label class="cat-chip <?= $checked ? 'checked' : '' ?>" data-cat-chip>
                  <input type="checkbox" name="categories[]" value="<?= (int)$cat['id'] ?>"
                         <?= $checked ? 'checked' : '' ?>>
                  <?= h($cat['name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <span class="help">Click chips to toggle. Multiple categories allowed.</span>
          </div>

          <div class="field">
            <label>Images &amp; videos</label>
            <span class="help">
              Max <?= $maxImages ?> per post, up to <?= (int)($maxFileSize / (1024*1024)) ?> MB each.
              Images: JPG, PNG, GIF, WebP. Videos: <strong>MP4</strong> or WebM only —
              <em>not</em> .mov (convert with QuickTime: File → Export As → 1080p).
            </span>
          </div>

          <?php if ($isEdit && $editImages): ?>
            <div class="field full">
              <label>Existing media — tick to remove on save</label>
              <div class="existing-images">
                <?php foreach ($editImages as $img):
                    $mt = $img['media_type'] ?? mediaTypeFromUrl($img['image_url']);
                ?>
                  <div class="existing-img" data-img-wrap>
                    <?php if ($mt === 'video'): ?>
                      <video src="<?= h($img['image_url']) ?>"
                             muted playsinline preload="metadata"
                             style="width:100%;height:100%;object-fit:cover;background:#000"></video>
                    <?php else: ?>
                      <img src="<?= h($img['image_url']) ?>" alt="">
                    <?php endif; ?>
                    <input type="checkbox" name="remove_images[]"
                           value="<?= (int)$img['id'] ?>"
                           data-remove-checkbox
                           title="Remove this <?= $mt === 'video' ? 'video' : 'image' ?>">
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
                     accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" multiple>
              <span class="file-drop-label">Click to choose files</span>
              <span class="file-drop-hint">or drag &amp; drop them here</span>
            </label>
            <div class="file-list" id="fileList"></div>
          </div>
        </div>

        <div class="form-actions">
          <a class="btn" href="admin?<?= h($clientQs) ?>">Cancel</a>
          <button type="submit" class="btn primary"><?= h($formSubmitText) ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- COMMENTS / CHAT (edit only) ------------------------------------ -->
  <?php if ($isEdit): ?>
    <?php $threadHtml = renderCommentThread($pdo, 'post', (int)$editPost['id']); ?>
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">💬 Comments</h2>
        <span class="brand-sub">Chat thread</span>
      </div>
      <div class="card-body">
        <?php if ($threadHtml): ?>
          <?= $threadHtml ?>
        <?php else: ?>
          <div class="comment-empty">No comments yet — start the thread below.</div>
        <?php endif; ?>
        <div class="comment-form">
          <textarea id="newCommentInput" placeholder="Reply to this post…" maxlength="2000"></textarea>
          <div class="comment-form-actions">
            <div class="comment-actor-pick" id="commentActorPick">
              <label class="checked"><input type="radio" name="comment_actor" value="admin" checked> Admin</label>
              <label><input type="radio" name="comment_actor" value="client"> Client</label>
            </div>
            <button type="button" class="btn primary" id="postCommentBtn"
                    data-post-id="<?= (int)$editPost['id'] ?>">
              Send message
            </button>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- BATCH UPLOAD --------------------------------------------------- -->
  <?php if (!$isEdit): ?>
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">⚡ Batch upload</h2>
      <span class="brand-sub">Quick post creator</span>
    </div>
    <div class="card-body">
      <p class="help" style="margin: 0 0 16px; font-size: 13px; line-height: 1.5;">
        Upload multiple images to create one post per image, spaced <strong>3 days apart</strong> from the latest scheduled post for the selected company.
        Caption defaults to <code>Please insert caption here</code> — edit later.
        Name files with category keywords (e.g. <code>yamaha_atv_trail.jpg</code>) to auto-tag.
      </p>
      <form method="POST" action="add-post?<?= h($clientQs) ?>" enctype="multipart/form-data" id="batchForm">
        <input type="hidden" name="action" value="batch_create">

        <div class="form-grid">
          <div class="field">
            <label>Company</label>
            <input type="text" value="<?= h($client['name']) ?>" disabled
                   style="background:var(--surface);color:var(--text-muted);cursor:not-allowed;">
            <span class="help">Batch posts will be created for this client.</span>
          </div>

          <div class="field">
            <label for="spacing_days">Days between posts</label>
            <input type="number" name="spacing_days" id="spacing_days"
                   value="3" min="1" max="30">
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
                     accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" multiple>
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

  // Drag & drop for any .file-drop
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

  // Visual feedback on "remove image" checkboxes
  document.querySelectorAll('[data-remove-checkbox]').forEach(cb => {
    cb.addEventListener('change', () => {
      cb.closest('[data-img-wrap]').classList.toggle('marked', cb.checked);
    });
  });

  // Batch uploader — live file preview with category matching
  const batchFileInput = document.getElementById('batch_images');
  const batchFileList  = document.getElementById('batchFileList');
  const allCatNames = <?= json_encode(array_map(fn($c) => strtolower($c['name']), $allCategories)) ?>;

  function matchCategoriesFromFilename(filename) {
    const base = ' ' + filename.replace(/\.[^.]+$/, '').toLowerCase().replace(/[-_.]+/g, ' ').replace(/\s+/g, ' ').trim() + ' ';
    const matched = new Set();
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
      if (!batchFileInput.files.length) { batchFileList.innerHTML = ''; return; }
      const count = batchFileInput.files.length;
      batchFileList.innerHTML = '<strong>Selected ' + count + ' image' + (count !== 1 ? 's' : '') + ':</strong>';
      [...batchFileInput.files].forEach(f => {
        const cats = matchCategoriesFromFilename(f.name);
        const div = document.createElement('div');
        div.className = 'file-list-item';
        const sizeStr = (f.size / 1024 / 1024).toFixed(2) + ' MB';
        const catsStr = cats.length ? ' → ' + cats.join(', ') : ' → no category match';
        div.textContent = '• ' + f.name + ' (' + sizeStr + ')' + catsStr;
        if (!cats.length) { div.style.opacity = '0.7'; }
        batchFileList.appendChild(div);
      });
    });
  }

  const batchForm   = document.getElementById('batchForm');
  const batchSubmit = document.getElementById('batchSubmit');
  if (batchForm && batchSubmit) {
    batchForm.addEventListener('submit', () => {
      batchSubmit.disabled = true;
      batchSubmit.textContent = 'Uploading…';
    });
  }

  // Category chip toggle
  document.querySelectorAll('[data-cat-chip]').forEach(chip => {
    const cb = chip.querySelector('input[type="checkbox"]');
    if (!cb) return;
    cb.addEventListener('change', () => {
      chip.classList.toggle('checked', cb.checked);
    });
  });

  // Append client default hashtags into the post hashtags textarea (no dupes).
  document.querySelectorAll('[data-apply-defaults]').forEach(btn => {
    btn.addEventListener('click', () => {
      const wrap = btn.closest('.hashtag-defaults-hint');
      const snippet = wrap && wrap.querySelector('[data-client-defaults]');
      const ta = document.getElementById('hashtags');
      if (!snippet || !ta) return;
      const defaults = (snippet.getAttribute('data-client-defaults') || '').trim();
      if (!defaults) return;
      const have = ta.value.trim();
      // De-dupe at the tag level so repeat clicks don't stack #Brand four times.
      const norm = s => s.toLowerCase();
      const haveTags = new Set(have.split(/\s+/).filter(Boolean).map(norm));
      const toAdd = defaults.split(/\s+/).filter(t => t && !haveTags.has(norm(t)));
      if (!toAdd.length) {
        btn.textContent = '✓ Already there';
        setTimeout(() => { btn.textContent = 'Append to hashtags'; }, 1500);
        return;
      }
      ta.value = (have ? have + ' ' : '') + toAdd.join(' ');
      ta.focus();
      ta.setSelectionRange(ta.value.length, ta.value.length);
    });
  });

  // Comment thread — post a new message
  const postCommentBtn = document.getElementById('postCommentBtn');
  const newCommentTa   = document.getElementById('newCommentInput');
  const actorPick      = document.getElementById('commentActorPick');
  if (actorPick) {
    actorPick.addEventListener('change', () => {
      actorPick.querySelectorAll('label').forEach(l => {
        const cb = l.querySelector('input');
        l.classList.toggle('checked', cb && cb.checked);
      });
    });
  }
  if (postCommentBtn && newCommentTa) {
    postCommentBtn.addEventListener('click', async () => {
      const text = newCommentTa.value.trim();
      if (!text) {
        newCommentTa.focus();
        return;
      }
      const postId = postCommentBtn.getAttribute('data-post-id');
      const actor = (document.querySelector('input[name="comment_actor"]:checked') || {}).value || 'admin';
      postCommentBtn.disabled = true;
      const original = postCommentBtn.textContent;
      postCommentBtn.textContent = 'Sending…';
      try {
        const fd = new FormData();
        fd.append('id', postId);
        fd.append('comment', text);
        fd.append('actor', actor);
        const res  = await fetch('status.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        // Reload to pick up the new thread row from the server
        window.location.reload();
      } catch (err) {
        postCommentBtn.disabled = false;
        postCommentBtn.textContent = original;
        alert('Failed to send: ' + (err.message || 'unknown'));
      }
    });
  }
</script>

</body>
</html>
