<?php
/**
 * Posts → the old composer endpoint (spec §4.5). Admin only. The composer PAGE is retired — the New post pop-up
 * (static/js/newpost.js → post-compose.php) creates and edits posts now:
 *   GET add-post.php?client=…            → 302 posts.php?client=…&newpost=1            (pop-up open)
 *   GET add-post.php?client=…&edit=<id>  → 302 posts.php?client=…&post=<id>&newpost=edit (detail + pop-up in edit mode)
 * The POST handler below stays: it is the documented format=json posting API (README "media[]" — scripts and
 * the test suite post here; the retired Classic admin's Delete did too); a non-JSON POST lands on the post
 * (or back on Posts with its errors as the flash). The portal UI itself saves through post-compose.php.
 *
 * Creates / edits a post + its post_images. Media can come from:
 *   1. the Approved Pool — assets[] = "library:<id>" | "tire:<id>" in carousel order.
 *      Each is validated server-side (this company AND status='approved'), the
 *      file is COPIED (never moved) into uploads/ under a fresh img_/vid_ name,
 *      and a post_images row is written with the next sort_order — exactly like
 *      a direct upload. No schema change. (spec §4.5 "endpoint addition")
 *   2. direct upload for one-offs — images[] (unchanged contract; the no-JS path).
 *   3. claimed[] = tokens of files the composer already sent to upload-chunk.php
 *      (purpose=post — in pieces when large, so videos up to 4 GB / images up to
 *      50 MB work on shared hosting). Each token is validated (format, sidecar
 *      purpose + client, file inside uploads/, younger than 24 h — else 400 and
 *      nothing is saved), the parked uploads/tmp_<token>.<ext> is renamed to its
 *      final img_ / vid_ name and a post_images row is written exactly as for a
 *      direct upload. Unclaimed files are swept after 24 h.
 *
 * Form POST actions (unchanged): delete (id) · create / update (name, caption*,
 * hashtags, scheduled_date*, status, post_type, categories[], remove_images[],
 * images[], claimed[], assets[], media[]) · batch_create (spacing_days, batch_images[]).
 *
 * media[] (optional) — ONE ordered list for the whole carousel, so approved images, new uploads and
 * (on update) the post's current media can be interleaved and reordered freely:
 *     image:<post_images.id>   keep this current media item (update only; must belong to the post)
 *     library:<id> | tire:<id> an Approved Pool pick (validated + copied like assets[])
 *     claim:<token>            a file already sent to upload-chunk.php (purpose=post), like claimed[]
 * When media[] is sent it is authoritative: assets[] / claimed[] / remove_images[] are ignored, every
 * current item NOT listed is removed, and sort_order = the list order (1…n). More than POST_MAX_MEDIA
 * items, an unknown image id or a bad token → 422 / 400 and nothing is saved. images[] (no-JS
 * direct files) still append after the list.
 * Add format=json to any action for a JSON reply instead of the redirect.
 * Successful saves land on the post itself: posts.php?client=…&post=<id>&msg=… (a delete → the Posts list).
 * status may be 'draft' once migrate.php step 35 ran (postsHaveDraft()): a draft may be saved without a
 * caption; every other status needs one. Up to POST_MAX_MEDIA media per post (helpers.php).
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/chunk-upload-lib.php';
require_once __DIR__ . '/upload-lib.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') { requireSameSiteFetch(); }   // cross-site POSTs → 403 (helpers.php)

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
        // Land on the post that was just saved (its sheet opens on load); a delete goes to the list.
        $pid = (int)($extra['post_id'] ?? 0);
        $to  = !empty($extra['deleted']) || $pid <= 0
            ? clientUrl('posts.php', ['msg' => $msg])
            : clientUrl('posts.php', ['post' => $pid, 'msg' => $msg]);
        header('Location: ' . $to);
        exit;
    }
}

// -------------------------------------------------------------
// Require a client in scope
// -------------------------------------------------------------
if (!$client) {
    if ($wantsJson) { composerDone(false, 'Pick a client first.', [], 400); }
    // ?edit=<id> knows its client: that post's sheet with the pop-up in edit mode.
    $unscopedEdit = (int)($_GET['edit'] ?? 0);
    if ($unscopedEdit > 0) {
        $st = $pdo->prepare("SELECT c.slug FROM posts p INNER JOIN companies c ON c.id = p.company_id WHERE p.id = ?");
        $st->execute([$unscopedEdit]);
        $slug = (string)($st->fetchColumn() ?: '');
        if ($slug !== '') {
            header('Location: ' . clientUrl('posts.php', ['client' => $slug, 'post' => $unscopedEdit, 'newpost' => 'edit']), true, 302);
            exit;
        }
    }
    // Otherwise the New post pop-up, which asks "Which client is this post for?" first (newpost.js).
    header('Location: ' . pagePath('posts') . '?newpost=1', true, 302);
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
$maxImageMb  = (int)(uploadMaxBytes('image') / (1024 * 1024));          // 50 MB (upload-lib.php)
$maxVideoGb  = (int)(uploadMaxBytes('video') / (1024 * 1024 * 1024));   // 4 GB — large files arrive through upload-chunk.php in pieces
$maxImages   = POST_MAX_MEDIA;   // applies to combined images + videos + pool picks per post (helpers.php)
$hasDraft    = postsHaveDraft($pdo);

/** The size cap for a direct (single-request) upload of this type — the same numbers upload-chunk.php enforces. */
function composerMaxBytes(bool $isVideo): int { return uploadMaxBytes($isVideo ? 'video' : 'image'); }

/**
 * media[] → normalised tokens in order (image:<id> | library:<id> | tire:<id> | claim:<token>), duplicates
 * dropped. Accepts an array or a JSON array string. Returns [$tokens, $error].
 */
function composerMediaOrder($raw, int $cap): array {
    if (is_string($raw)) {
        $raw = trim($raw);
        $decoded = $raw !== '' && $raw[0] === '[' ? json_decode($raw, true) : null;
        $raw = is_array($decoded) ? $decoded : ($raw === '' ? [] : preg_split('/[\s,]+/', $raw));
    }
    if (!is_array($raw)) return [[], 'media[] must be a list.'];
    $out = []; $seen = [];
    foreach ($raw as $item) {
        if (!is_string($item)) return [[], 'media[] must be a list of strings.'];
        $item = trim($item);
        if ($item === '') continue;
        if (preg_match('/^(image|library|tire):(\d+)$/i', $item, $m) && (int)$m[2] > 0) {
            $tok = strtolower($m[1]) . ':' . (int)$m[2];
        } elseif (preg_match('/^claim:([A-Za-z0-9_\-]{8,128})$/', $item, $m)) {
            $tok = 'claim:' . $m[1];
        } else {
            return [[], 'Unknown media item "' . mb_substr($item, 0, 40) . '".'];
        }
        if (isset($seen[$tok])) continue;
        $seen[$tok] = true;
        $out[] = $tok;
    }
    if (count($out) > $cap) return [[], "Up to {$cap} media per post — remove " . (count($out) - $cap) . '.'];
    return [$out, ''];
}

/**
 * claimed[] tokens → validated claims (upload-lib.php) in posted order. Any bad token (wrong format,
 * another purpose / client, file gone, older than 24 h) is a hard 400: nothing is saved, the admin
 * re-picks the file. Returns [$claims, $error].
 */
function composerClaims($raw, string $slug, int $cap): array {
    $claims = [];
    if (!is_array($raw)) return [[], ''];
    foreach ($raw as $token) {
        if (!is_string($token) || $token === '') continue;
        if (count($claims) >= $cap) break;
        $c = uploadClaimRead($token, 'post', $slug);
        if ($c === null) return [[], 'One of the uploaded files has expired or could not be found — please add it again.'];
        $claims[] = $c;
    }
    return [$claims, ''];
}

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
                    $path = uploadsPathOrNull((string)$row['image_url']);   // realpath-contained in uploads/
                    if ($path !== null) { if (function_exists('previewDelete')) previewDelete($path); @unlink($path); }
                }
                $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
                logActivity($pdo, (int)$client['id'], 'post', $postId,
                    'deleted', 'admin', "Deleted post #{$postId}");
                $pdo->commit();
                composerDone(true, 'Post deleted.', ['post_id' => $postId, 'deleted' => true]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('add-post delete: ' . $e->getMessage());
                $errors[] = 'Delete failed: database error.';
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
        [$claims, $claimErr] = composerClaims($_POST['claimed'] ?? [], (string)$client['slug'], $maxImages);
        if ($claimErr !== '') { $errors[] = $claimErr; $errorCode = 400; }

        // media[]: one ordered list (see the header) — it replaces assets[] / claimed[] / remove_images[].
        $mediaOrder = null;
        if (array_key_exists('media', $_POST)) {
            [$mediaOrder, $mediaErr] = composerMediaOrder($_POST['media'], $maxImages);
            $errors = array_values(array_filter($errors, static fn($e) => $e !== $claimErr));   // claimed[] is not used
            if ($mediaErr !== '') {
                $errors[] = $mediaErr; $errorCode = 422; $mediaOrder = [];
            }
            $picks = studioParsePicks(array_values(array_filter($mediaOrder, static fn($t) => strpos($t, 'library:') === 0 || strpos($t, 'tire:') === 0)), $maxImages);
            $claimTokens = array_map(static fn($t) => substr($t, 6), array_values(array_filter($mediaOrder, static fn($t) => strpos($t, 'claim:') === 0)));
            [$claims, $claimErr] = composerClaims($claimTokens, (string)$client['slug'], $maxImages);
            if ($claimErr !== '') { $errors[] = $claimErr; $errorCode = 400; }
            if ($action === 'create' && array_filter($mediaOrder, static fn($t) => strpos($t, 'image:') === 0)) {
                $errors[] = 'A new post has no current media to keep.'; $errorCode = 422;
            }
        }

        $allowedStatus = $hasDraft ? ['draft', 'pending', 'approved', 'denied'] : ['pending', 'approved', 'denied'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'pending';
        }
        // A draft may wait for its caption; anything the client can see needs one.
        if ($caption === '' && $status !== 'draft') {
            $errors[] = $hasDraft ? 'Add a caption first (or save it as a Draft).' : 'Caption is required.';
            $errorCode = 422;
        }
        if ($scheduled_date === ''){ $errors[] = 'Scheduled date is required.'; }
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
            $previewQueue = [];   // stored image paths → previewAfterStore() after the commit
            $mediaIds = [];       // media[] token → post_images.id (for the final ordering)
            $unlinkAfter = [];    // removed media files — unlinked only once the transaction has committed
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
                    logActivity($pdo, $company_id, 'post', $postId, $status === 'draft' ? 'drafted' : 'created', 'admin',
                        ($status === 'draft' ? "Started draft post #{$postId}" : "Created post #{$postId}") . ($createdLabel !== '' ? ': ' . $createdLabel : ''));
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
                                      : (($status === 'denied')  ? 'denied'
                                      : (($status === 'draft')   ? 'moved_to_draft'
                                      : ($prev['status'] === 'draft' ? 'submitted' : 'reset_pending')));
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
                    $removeIds = !empty($_POST['remove_images']) && is_array($_POST['remove_images']) ? $_POST['remove_images'] : [];
                    if ($mediaOrder !== null) {
                        // media[] is authoritative: keep exactly the listed current items, remove the rest.
                        $cur = $pdo->prepare("SELECT id FROM post_images WHERE post_id = ?");
                        $cur->execute([$postId]);
                        $currentIds = array_map('intval', $cur->fetchAll(PDO::FETCH_COLUMN));
                        $keepIds = array_map(static fn($t) => (int)substr($t, 6), array_values(array_filter($mediaOrder, static fn($t) => strpos($t, 'image:') === 0)));
                        $unknown = array_diff($keepIds, $currentIds);
                        if ($unknown) {
                            throw new StudioAssetException('Media item #' . reset($unknown) . ' is not part of this post.', 422);
                        }
                        $removeIds = array_values(array_diff($currentIds, $keepIds));
                    }
                    if ($removeIds) {
                        $toRemove = array_values(array_filter(array_map('intval', $removeIds)));
                        if ($toRemove) {
                            $ph  = implode(',', array_fill(0, count($toRemove), '?'));
                            $sel = $pdo->prepare("
                                SELECT id, image_url FROM post_images
                                WHERE post_id = ? AND id IN ($ph)
                            ");
                            $sel->execute(array_merge([$postId], $toRemove));
                            foreach ($sel->fetchAll() as $row) {
                                $path = uploadsPathOrNull((string)$row['image_url']);   // realpath-contained in uploads/
                                if ($path !== null) { $unlinkAfter[] = $path; }   // deleted after the commit (a rollback keeps the files)
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
                    foreach ($attached as $att) { $mediaIds[(string)$att['key']] = (int)$att['id']; }
                    foreach ($attached as $att) {   // copies whose source had no fresh previews yet
                        $attPath = uploadsPathOrNull((string)($att['image_url'] ?? ''));
                        if ($attPath !== null) $previewQueue[] = $attPath;
                    }
                    $slots -= count($attached);
                }

                // ---- Files the composer already uploaded (claimed[] tokens → img_/vid_ + rows) ----
                if ($claims) {
                    $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM post_images WHERE post_id = ?");
                    $sortQ->execute([$postId]);
                    $sortOrder = (int)$sortQ->fetchColumn();
                    if (!is_dir($uploadsDir)) { mediaMkdir($uploadsDir); }
                    $claimedCount = 0;
                    foreach ($claims as $claim) {
                        if ($claimedCount >= $slots) {
                            $errors[] = "Max {$maxImages} media per post — some uploaded files were skipped.";
                            break;
                        }
                        $isVideo = !empty($claim['video']);
                        $newName = uploadFreshName($isVideo ? 'vid_' : 'img_', (string)$claim['ext']);
                        $dest    = $uploadsDir . '/' . $newName;
                        if (!uploadClaimTake($claim, $dest)) {
                            $errors[] = "Failed to save '" . (string)$claim['name'] . "'. Check uploads/ permissions.";
                            continue;
                        }
                        $sortOrder++;
                        if (hasMediaTypeColumn($pdo)) {
                            $ins = $pdo->prepare("INSERT INTO post_images (post_id, image_url, media_type, sort_order) VALUES (?, ?, ?, ?)");
                            $ins->execute([$postId, $uploadsUrl . '/' . $newName, $isVideo ? 'video' : 'image', $sortOrder]);
                        } else {
                            $ins = $pdo->prepare("INSERT INTO post_images (post_id, image_url, sort_order) VALUES (?, ?, ?)");
                            $ins->execute([$postId, $uploadsUrl . '/' . $newName, $sortOrder]);
                        }
                        $mediaIds['claim:' . (string)($claim['token'] ?? '')] = (int)$pdo->lastInsertId();
                        if (!$isVideo) $previewQueue[] = $dest;   // sm + lg previews, made after the commit
                        $claimedCount++;
                    }
                    $slots -= $claimedCount;
                }

                // ---- media[]: the final order of everything (current items, picks, uploads) ----
                if ($mediaOrder) {
                    $setSort = $pdo->prepare("UPDATE post_images SET sort_order = ? WHERE id = ? AND post_id = ?");
                    $pos = 0;
                    foreach ($mediaOrder as $tok) {
                        $imgId = strpos($tok, 'image:') === 0 ? (int)substr($tok, 6) : (int)($mediaIds[$tok] ?? 0);
                        if ($imgId > 0) { $setSort->execute([++$pos, $imgId, $postId]); }
                    }
                }

                // ---- Direct uploads (one-offs; the no-JS path) ---------------------
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
                        if ($_FILES['images']['size'][$i] > composerMaxBytes($isVideo)) {
                            $errors[] = "'{$origName}' exceeds " . uploadCapLabel(composerMaxBytes($isVideo)) . '.';
                            continue;
                        }
                        if ($isVideo) {
                            // For videos we can't use getimagesize: non-empty + container sniff.
                            if (!videoFileLooksValid((string)$_FILES['images']['tmp_name'][$i], $ext)) {
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
                            if (!$isVideo) $previewQueue[] = $dest;   // sm + lg previews, made after the commit
                            $uploadedCount++;
                        } else {
                            $errors[] = "Failed to save '{$origName}'. Check uploads/ permissions.";
                        }
                    }
                }

                $pdo->commit();
                foreach ($unlinkAfter as $gone) { if (function_exists('previewDelete')) previewDelete($gone); @unlink($gone); }
                // Previews outside the transaction: sm + lg within the request budget (preview-lib.php), the rest lazily.
                if (function_exists('previewAfterStore') && $previewQueue) { previewReleaseSession(); foreach ($previewQueue as $pq) previewAfterStore($pq); }
                $msg = $action === 'create' ? ($status === 'draft' ? 'Draft saved — only you can see it.' : 'Post created.') : 'Post updated.';
                if ($errors) {
                    $msg .= ' (Some warnings: ' . implode(' ', $errors) . ')';
                }
                // JSON callers get the saved carousel in order (id / url / type) and the post's link.
                $mediaOut = [];
                if ($wantsJson) {
                    $mt = hasMediaTypeColumn($pdo) ? 'media_type' : "'' AS media_type";
                    $ms = $pdo->prepare("SELECT id, image_url, {$mt} FROM post_images WHERE post_id = ? ORDER BY sort_order ASC, id ASC");
                    $ms->execute([$postId]);
                    foreach ($ms->fetchAll() as $m) {
                        $mediaOut[] = ['id' => (int)$m['id'], 'url' => (string)$m['image_url'],
                                       'type' => ($m['media_type'] ?? '') !== '' ? (string)$m['media_type'] : mediaTypeFromUrl((string)$m['image_url'])];
                    }
                }
                composerDone(true, $msg, ['post_id' => $postId, 'status' => $status, 'warnings' => $errors,
                                          'media' => $mediaOut, 'post_url' => clientUrl('posts.php', ['post' => $postId])]);
            } catch (StudioAssetException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errorCode = $e->getCode() >= 400 ? (int)$e->getCode() : 400;
                $errors[]  = 'Save failed: ' . $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('add-post save: ' . $e->getMessage());
                $errors[] = 'Save failed: database error.';
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
                if ($_FILES['batch_images']['size'][$i] > composerMaxBytes($isVideo)) {
                    $errors[] = "'{$origName}' exceeds " . uploadCapLabel(composerMaxBytes($isVideo)) . '.';
                    continue;
                }
                if ($isVideo) {
                    if (!videoFileLooksValid((string)$_FILES['batch_images']['tmp_name'][$i], $ext)) {
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
                    // Draft with no caption once posts have Draft (migrate.php step 35); before that the old
                    // client-visible placeholder.
                    $ins = $pdo->prepare("
                        INSERT INTO posts (company_id, caption, hashtags, scheduled_date, status)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$company_id, $hasDraft ? '' : 'Please insert caption here', $defaultHashtags, $scheduledDate, $hasDraft ? 'draft' : 'pending']);
                    $postId = (int)$pdo->lastInsertId();
                    logActivity($pdo, $company_id, 'post', $postId, $hasDraft ? 'drafted' : 'created', 'admin',
                        ($hasDraft ? 'Started draft post #' : 'Created post #') . $postId . ' via batch upload');

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
                    if (!$isVideo && function_exists('previewAfterStore')) { previewReleaseSession(); previewAfterStore($dest); }   // sm + lg previews (per-request budget; the rest lazily)
                    $createdCount++;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('add-post batch ' . $origName . ': ' . $e->getMessage());
                    $errors[] = "Database error on '{$origName}'.";
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
// The composer page is retired: create / edit happen in the New post pop-up (static/js/newpost.js →
// post-compose.php). Every GET — and a non-JSON POST that did not finish above — lands on Posts with the
// pop-up open (edit mode for ?edit=<id>); a failed form POST carries its errors as the flash.
// -------------------------------------------------------------
$editId = (int)($_GET['edit'] ?? ($_POST['id'] ?? 0));
$dest   = $editId > 0 ? ['post' => $editId, 'newpost' => 'edit'] : ['newpost' => 1];
if ($errors) { $dest['msg'] = implode(' ', $errors); }
elseif ($flash !== '') { $dest['msg'] = $flash; }
header('Location: ' . clientUrl('posts.php', $dest), true, 302);
exit;
