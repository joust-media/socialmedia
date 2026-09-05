<?php
/**
 * Studio → Composer (spec §4.5). Admin only.
 *
 * Creates / edits a post + its post_images. Media can come from:
 *   1. the Approved Pool — assets[] = "library:<id>" | "tire:<id>" in carousel order.
 *      Each is validated server-side (this company AND status='approved'), the
 *      file is COPIED (never moved) into uploads/ under a fresh img_/vid_ name,
 *      and a post_images row is written with the next sort_order — exactly like
 *      a direct upload. No schema change. (spec §4.5 "endpoint addition")
 *   2. direct upload for one-offs — images[] (unchanged contract).
 *
 * Form POST actions (unchanged): delete (id) · create / update (name, caption*,
 * hashtags, scheduled_date*, status, post_type, categories[], remove_images[],
 * images[], assets[]) · batch_create (spacing_days, batch_images[]).
 * Add format=json to any action for a JSON reply instead of the redirect.
 * Successful saves redirect to studio?client=…&msg=….
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

require_once __DIR__ . '/partials/components/comment-thread.php';
require_once __DIR__ . '/partials/components/post-detail.php';
require_once __DIR__ . '/partials/components/asset-pool.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$wantsJson = (($_POST['format'] ?? $_GET['format'] ?? '') === 'json')
          || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
              && stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') === false);

/** JSON reply (used by studio.js) or a redirect with a flash message. */
function composerDone(bool $ok, string $msg, array $extra = [], int $code = 200): void {
    global $wantsJson;
    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code($ok ? 200 : $code);
        echo json_encode(array_merge(['ok' => $ok, ($ok ? 'message' : 'error') => $msg], $extra));
        exit;
    }
    if ($ok) {
        header('Location: ' . clientUrl('studio.php', array_merge(['tab' => 'posts', 'msg' => $msg], $extra['redirect'] ?? [])));
        exit;
    }
}

// -------------------------------------------------------------
// Require a client in scope
// -------------------------------------------------------------
if (!$client) {
    if ($wantsJson) { composerDone(false, 'Pick a client first.', [], 400); }
    header('Location: ' . clientUrl('studio.php', ['msg' => 'Pick a client first.']));
    exit;
}
$clientQs = 'client=' . urlencode($client['slug']);

// -------------------------------------------------------------
// Config
// -------------------------------------------------------------
$uploadsDir  = __DIR__ . '/uploads';
$uploadsUrl  = 'uploads';
$allowedExt  = array_merge(imageExts(), videoExts()); // jpg/png/gif/webp + mp4/webm/mov (spec §6)
$rejectedExt = ['m4v', 'avi', 'mkv'];        // common but unsupported by web browsers
$maxFileSize = 25 * 1024 * 1024; // 25 MB (videos are bigger than images)
$maxImages   = 10;               // applies to combined images + videos + pool picks per post

$errors    = [];
$errorCode = 400;
$flash     = $_GET['msg'] ?? '';

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
                // Scope guard — only delete if the post belongs to this client
                $chk = $pdo->prepare("SELECT 1 FROM posts WHERE id = ? AND company_id = ? LIMIT 1");
                $chk->execute([$postId, $client['id']]);
                if (!$chk->fetchColumn()) {
                    throw new Exception('That post does not belong to ' . $client['name'] . '.');
                }
                $imgs = $pdo->prepare("SELECT image_url FROM post_images WHERE post_id = ?");
                $imgs->execute([$postId]);
                foreach ($imgs->fetchAll() as $row) {
                    if (strpos($row['image_url'], 'uploads/') === 0) {
                        $path = __DIR__ . '/' . $row['image_url'];
                        if (is_file($path)) { @unlink($path); }
                    }
                }
                $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
                logActivity($pdo, (int)$client['id'], 'post', $postId,
                    'deleted', 'admin', "Deleted post #{$postId}");
                $pdo->commit();
                composerDone(true, 'Post deleted.', ['post_id' => $postId]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
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
        $picks          = studioParsePicks($_POST['assets'] ?? [], $maxImages);

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
            $postId = 0;
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

                // How many media slots are left on this post
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM post_images WHERE post_id = ?");
                $cnt->execute([$postId]);
                $existing = (int)$cnt->fetchColumn();
                $slots    = max(0, $maxImages - $existing);

                // ---- Approved Pool picks (copied into uploads/, in the chosen order) ----
                if ($picks) {
                    if (count($picks) > $slots) {
                        $errors[] = "Max {$maxImages} media per post — only the first {$slots} picked assets were added.";
                    }
                    $attached = studioAttachAssetsToPost($pdo, $client, $postId, $picks, ['slots' => $slots, 'uploadsDir' => $uploadsDir]);
                    $slots -= count($attached);
                }

                // ---- Direct uploads (one-offs) -----------------------------------
                if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
                    $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM post_images WHERE post_id = ?");
                    $sortQ->execute([$postId]);
                    $sortOrder = (int)$sortQ->fetchColumn();

                    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

                    $uploadedCount = 0;
                    foreach ($_FILES['images']['name'] as $i => $origName) {
                        $err = $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                        if ($err === UPLOAD_ERR_NO_FILE) { continue; }
                        if ($uploadedCount >= $slots) {
                            $errors[] = "Max {$maxImages} files per post — some were skipped.";
                            break;
                        }
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
                                      . "Allowed: JPG, PNG, GIF, WebP, MP4, WebM, MOV.";
                            continue;
                        }
                        $isVideo = isVideoExt($ext);
                        if ($isVideo) {
                            // For videos we can't use getimagesize: non-empty + container sniff.
                            if (!videoFileLooksValid((string)$_FILES['images']['tmp_name'][$i])) {
                                $errors[] = "'{$origName}' doesn't look like a valid video file.";
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
                composerDone(true, $msg, ['post_id' => $postId, 'warnings' => $errors]);
            } catch (StudioAssetException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errorCode = $e->getCode() >= 400 ? (int)$e->getCode() : 400;
                $errors[]  = 'Save failed: ' . $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }

    // ---- Batch create (legacy add-post contract; batch.php is the new UI) ----
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
                    if (!videoFileLooksValid((string)$_FILES['batch_images']['tmp_name'][$i])) {
                        $errors[] = "'{$origName}' doesn't look like a valid video file.";
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
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = "DB error on '{$origName}': " . $e->getMessage();
                    if (is_file($dest)) { @unlink($dest); }
                }
            }

            $msg = $createdCount . ' post' . ($createdCount !== 1 ? 's' : '') . ' created via batch upload.';
            if ($errors) { $msg .= ' (Warnings: ' . implode(' ', $errors) . ')'; }
            composerDone(true, $msg, ['count' => $createdCount, 'warnings' => $errors]);
        }
    }

    if ($errors && $wantsJson) {
        composerDone(false, implode(' ', $errors), [], $errorCode);
    }
}

// -------------------------------------------------------------
// Fetch for display
// -------------------------------------------------------------
$allCategories = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order, name")->fetchAll();
$pool          = studioApprovedPool($pdo, $client);

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
        foreach ($imgStmt->fetchAll() as $img) {
            $editImages[] = [
                'id'   => (int)$img['id'],
                'url'  => (string)$img['image_url'],
                'type' => ($img['media_type'] ?? '') !== '' ? $img['media_type'] : mediaTypeFromUrl((string)$img['image_url']),
            ];
        }

        $catStmt = $pdo->prepare("SELECT category_id FROM post_categories WHERE post_id = ?");
        $catStmt->execute([$editId]);
        $editPostCategories = array_map('intval', array_column($catStmt->fetchAll(), 'category_id'));
    }
}

$isEdit = (bool)$editPost;
$clientDefaultHashtags = trim((string)($client['default_hashtags'] ?? ''));

// Re-populate from the failed POST so nothing typed is lost.
$posted = ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) ? $_POST : null;
$val = [
    'id'         => $isEdit ? (int)$editPost['id'] : 0,
    'name'       => $posted['name']     ?? ($isEdit ? (string)($editPost['name'] ?? '') : ''),
    'caption'    => $posted['caption']  ?? ($isEdit ? (string)$editPost['caption'] : ''),
    'hashtags'   => $posted['hashtags'] ?? ($isEdit ? (string)$editPost['hashtags'] : $clientDefaultHashtags),
    'status'     => $posted['status']   ?? ($isEdit ? (string)$editPost['status'] : 'pending'),
    'post_type'  => strtolower((string)($posted['post_type'] ?? ($isEdit ? ($editPost['post_type'] ?? 'post') : 'post'))),
    'scheduled'  => $posted['scheduled_date'] ?? ($isEdit ? date('Y-m-d\TH:i', strtotime($editPost['scheduled_date'])) : date('Y-m-d\TH:i')),
    'categories' => isset($posted['categories']) ? array_map('intval', (array)$posted['categories']) : $editPostCategories,
];
if (!in_array($val['post_type'], allowedPostTypes(), true)) { $val['post_type'] = 'post'; }
$selectedKeys = $posted ? array_column(studioParsePicks($posted['assets'] ?? [], $maxImages), 'key') : [];

// Short large title; the post's reference name goes in the eyebrow so it never truncates on phones.
$formTitle = $isEdit ? 'Edit post' : 'Compose';
$editLabel = $isEdit ? (!empty($editPost['name']) ? $editPost['name'] : 'Post #' . (int)$editPost['id']) : '';

$composerHtml = studioComposerHtml([
    'client'          => $client,
    'pool'            => $pool,
    'action'          => clientUrl('add-post.php'),
    'isEdit'          => $isEdit,
    'post'            => $val,
    'editImages'      => $editImages,
    'categories'      => $allCategories,
    'supportsType'    => hasPostTypeColumn($pdo),
    'maxImages'       => $maxImages,
    'maxFileMb'       => (int)($maxFileSize / (1024 * 1024)),
    'submitText'      => $isEdit ? 'Save changes' : 'Create post',
    'cancelUrl'       => clientUrl('studio.php'),
    'assetsUrl'       => clientUrl('assets.php', ['view' => 'library', 'filter' => 'approved']),
    'selected'        => $selectedKeys,
    'errors'          => $errors,
    'defaultHashtags' => $clientDefaultHashtags,
    'formId'          => 'composer',
]);

$studioConfig = [
    'base'      => basePath(),
    'endpoint'  => basePath() . '/status.php',
    'batch'     => basePath() . '/batch-process.php',
    'client'    => $client['slug'],
    'brand'     => ['name' => $client['name'], 'logo' => (string)($client['logo_url'] ?? '')],
    'maxImages' => $maxImages,
    'maxFileMb' => (int)($maxFileSize / (1024 * 1024)),
];

// -------------------------------------------------------------
// Render
// -------------------------------------------------------------
$pageTitle   = $formTitle;
$navSubtitle = 'Studio · ' . ($isEdit ? $editLabel : $client['name']);
$activeTab   = 'studio';
$pageWide    = true;
$navBack     = ['href' => clientUrl('studio.php'), 'label' => 'Studio'];
$bodyClass   = 'page-studio page-composer';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/posts.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">';
$footExtra   = '<script>window.StudioConfig = ' . json_encode($studioConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
             . '<script src="' . h(staticUrl('js/studio.js')) . '" defer></script>';

include __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash): ?>
  <div class="studio-alert studio-alert--ok" role="status"><?= h($flash) ?></div>
<?php endif; ?>

<?= $composerHtml ?>

<?php if ($isEdit): ?>
  <section class="studio-thread ui-card" data-thread-card>
    <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title">Comments</h3>
      <p class="ui-card-subtitle">The same thread the client sees on this post.</p></div></div>
    <div class="ui-card-body">
      <?= commentThreadHtml(hasActivityLog($pdo) ? commentThread($pdo, 'post', (int)$editPost['id']) : [], ['empty' => 'No messages yet — start the thread below.']) ?>
      <form class="studio-reply" data-studio-reply data-id="<?= (int)$editPost['id'] ?>" autocomplete="off">
        <label class="ui-visually-hidden" for="studioReply">Reply</label>
        <textarea class="ui-textarea" id="studioReply" rows="2" maxlength="2000" placeholder="Reply as Joust…" data-reply-input></textarea>
        <div class="studio-reply-row">
          <label class="studio-chip"><input type="radio" name="reply_actor" value="admin" checked> As Joust</label>
          <label class="studio-chip"><input type="radio" name="reply_actor" value="client"> As <?= h($client['name']) ?></label>
          <span class="ui-spacer"></span>
          <button type="submit" class="ui-btn ui-btn--filled ui-btn--sm" data-reply-send>Send</button>
        </div>
      </form>
    </div>
  </section>
  <form class="studio-danger" method="POST" action="<?= h(clientUrl('add-post.php')) ?>" data-confirm-submit="Delete this post and all its media? This cannot be undone.">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$editPost['id'] ?>">
    <button type="submit" class="ui-btn ui-btn--plain ui-btn--sm studio-danger-btn">Delete this post</button>
  </form>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
