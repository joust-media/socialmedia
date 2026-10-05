<?php
/**
 * App uploads (files that end up in <app>/uploads/) — the pieces shared by upload-chunk.php,
 * add-post.php, batch-process.php, replace-image.php and add-feature.php:
 *
 *   caps            uploadMaxBytes('image'|'video')  → 50 MB / 4 GB (logos in client-admin.php keep 2 MB)
 *   name checks     uploadCheckName($name, $exts)     → [ext, isVideo] or an error string
 *   content checks  uploadCheckContent($path, $ext, $isVideo) → '' or an error string (images must decode
 *                   AND match the extension's format, videos must carry the container magic)
 *   claim tokens    a file uploaded ahead of the form (Compose / Batch) is parked as
 *                   uploads/tmp_<token>.<ext> with a sidecar uploads/.spool/<token>.claim (purpose, client,
 *                   name, size …); the form later posts claimed[] = token and uploadClaimRead() /
 *                   uploadClaimTake() turn it into the final img_ / vid_ / batch_ file. Unclaimed files
 *                   and their sidecars are removed after 24 h (uploadClaimCleanup(), run on probe / init).
 *   replace         uploadReplaceApply(): the one implementation behind replace-image.php and the
 *                   `replace` purpose of upload-chunk.php (post_images / tire_images rows).
 *   feature         uploadFeatureInsert(): a reference image row for a tire (add-feature.php's contract).
 *   library         uploadLibraryStore(): a file for the brand's Library — media/library/<slug>/<name>, a
 *                   'pending' library_images row (the same status syncLibraryImages() gives an FTP drop).
 *
 * Every function is function_exists-guarded and does no work at load.
 */

if (!function_exists('uploadMaxBytes')) {
    /** The total-size cap per media type: images 50 MB, videos 4 GB (chunked; a single request is bounded by php.ini anyway). */
    function uploadMaxBytes(string $type): int {
        return $type === 'video' ? 4 * 1024 * 1024 * 1024 : 50 * 1024 * 1024;
    }
}

if (!function_exists('uploadCapLabel')) {
    /** '50 MB' / '4 GB' for messages. */
    function uploadCapLabel(int $bytes): string {
        if ($bytes >= 1024 * 1024 * 1024) return (int)round($bytes / (1024 * 1024 * 1024)) . ' GB';
        return (int)round($bytes / (1024 * 1024)) . ' MB';
    }
}

if (!function_exists('uploadsDirPath')) {
    function uploadsDirPath(): string {
        return __DIR__ . '/uploads';
    }
}

if (!function_exists('uploadsDirEnsure')) {
    /** uploads/ exists (0755 whatever the umask) → path, or null when it cannot be created / written. */
    function uploadsDirEnsure(): ?string {
        $dir = uploadsDirPath();
        if (!is_dir($dir)) { function_exists('mediaMkdir') ? mediaMkdir($dir) : @mkdir($dir, 0755, true); }
        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }
}

if (!function_exists('uploadRejectedExts')) {
    /** Common video containers browsers cannot play — refused with the "convert to MP4" hint. */
    function uploadRejectedExts(): array { return ['m4v', 'avi', 'mkv']; }
}

if (!function_exists('uploadCheckName')) {
    /**
     * Extension gate. $exts = the allowed list (imageExts() ∪ videoExts() by default).
     * Returns ['ext' => 'jpg', 'video' => false] or ['error' => '…'] (415 material).
     */
    function uploadCheckName(string $origName, ?array $exts = null): array {
        $exts = $exts ?? array_merge(imageExts(), videoExts());
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        if (in_array($ext, uploadRejectedExts(), true)) {
            return ['error' => ".{$ext} isn't web-playable. Convert to MP4 first (QuickTime: File → Export As → 1080p)."];
        }
        if ($ext === '' || !in_array($ext, $exts, true)) {
            $imagesOnly = !array_intersect($exts, videoExts());
            return ['error' => $imagesOnly ? 'Unsupported file type — use JPG, PNG, GIF or WebP.' : 'Unsupported file type — use JPG, PNG, GIF, WebP, MP4, WebM, or MOV.'];
        }
        return ['ext' => $ext, 'video' => isVideoExt($ext)];
    }
}

if (!function_exists('uploadCheckContent')) {
    /**
     * Content check — the extension alone is never trusted (same rules as tire-upload.php): images must
     * decode AND be the format their extension claims (a PHP / HTML file renamed .jpg fails here), videos
     * must carry the container magic (EBML / ftyp). Returns '' when fine, else the error message (422).
     */
    function uploadCheckContent(string $path, string $ext, bool $isVideo): string {
        if ($isVideo) return videoFileLooksValid($path, $ext) ? '' : 'Not a valid video file';
        $info = @getimagesize($path);
        if ($info === false || (int)($info[0] ?? 0) <= 0 || (int)($info[1] ?? 0) <= 0) return 'Not a valid image';
        $byType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
        if (defined('IMAGETYPE_WEBP')) $byType[IMAGETYPE_WEBP] = 'webp';
        $detected = $byType[(int)($info[2] ?? 0)] ?? '';
        if ($detected === '') return 'Unsupported image format — use JPG, PNG, GIF or WebP.';
        if ($detected !== $ext) return "The file is a {$detected} image, not .{$ext} — rename it and try again.";
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            $mime = $fi ? (string)@finfo_file($fi, $path) : '';
            if ($fi) @finfo_close($fi);
            if ($mime !== '' && strpos($mime, 'image/') !== 0) return 'Not a valid image';
        }
        return '';
    }
}

if (!function_exists('uploadFreshName')) {
    /** uniqid('<prefix>', true) + ext, [A-Za-z0-9_.-] only — the naming add-post.php has always used. */
    function uploadFreshName(string $prefix, string $ext): string {
        return preg_replace('/[^a-zA-Z0-9_.\-]/', '', uniqid($prefix, true) . '.' . $ext);
    }
}

if (!function_exists('uploadMoveInto')) {
    /** move_uploaded_file() for a PHP upload, rename (copy + unlink fallback) for a spool / tmp file; 0644 afterwards. */
    function uploadMoveInto(string $src, string $dest, bool $uploaded): bool {
        $ok = $uploaded ? @move_uploaded_file($src, $dest) : (@rename($src, $dest) || (@copy($src, $dest) && @unlink($src)));
        if ($ok) { function_exists('mediaChmodPath') ? mediaChmodPath($dest) : @chmod($dest, 0644); }
        return $ok;
    }
}

// ---------------------------------------------------------------------
// Claim tokens — uploads/tmp_<token>.<ext> + uploads/.spool/<token>.claim
// ---------------------------------------------------------------------

if (!function_exists('uploadClaimTtl')) {
    function uploadClaimTtl(): int { return 86400; }
}

if (!function_exists('uploadClaimValidToken')) {
    function uploadClaimValidToken($t): bool {
        return is_string($t) && preg_match('/^[a-f0-9]{32}$/', $t) === 1;
    }
}

if (!function_exists('uploadClaimSidecarDir')) {
    /** uploads/.spool (deny-all .htaccess, chunk-upload-lib.php) — the sidecars live next to the chunk spool. */
    function uploadClaimSidecarDir(bool $create = true): ?string {
        $up = uploadsDirEnsure();
        if ($up === null) return null;
        return function_exists('chunkSpoolDir') ? chunkSpoolDir($up, $create) : null;
    }
}

if (!function_exists('uploadClaimStore')) {
    /**
     * Park a validated file as uploads/tmp_<token>.<ext> and write its sidecar. $meta: purpose, client,
     * name (original), ext, video (bool), size, mime. Returns the claim reply
     * ['token', 'name', 'size', 'type' => image|video, 'mime', 'ext', 'preview_url', 'file' => tmp name]
     * or null when uploads/ is unusable or the move failed.
     */
    function uploadClaimStore(string $src, bool $uploaded, array $meta): ?array {
        $up = uploadsDirEnsure();
        $dir = uploadClaimSidecarDir(true);
        if ($up === null || $dir === null) return null;
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower((string)($meta['ext'] ?? '')));
        if ($ext === '') return null;
        for ($try = 0; $try < 5; $try++) {
            $token = bin2hex(random_bytes(16));
            $file  = 'tmp_' . $token . '.' . $ext;
            $dest  = $up . '/' . $file;
            if (file_exists($dest) || file_exists($dir . '/' . $token . '.claim')) continue;
            if (!uploadMoveInto($src, $dest, $uploaded)) return null;
            clearstatcache(true, $dest);
            $side = [
                'token'      => $token,
                'purpose'    => (string)($meta['purpose'] ?? ''),
                'client'     => (string)($meta['client'] ?? ''),
                'name'       => (string)($meta['name'] ?? ''),
                'ext'        => $ext,
                'video'      => !empty($meta['video']),
                'size'       => (int)@filesize($dest),
                'mime'       => (string)($meta['mime'] ?? ''),
                'file'       => $file,
                'created_at' => time(),
            ];
            // no LOCK_EX: the name is unique to this request (no second writer) and stream-wrapped harnesses refuse it
            if (@file_put_contents($dir . '/' . $token . '.claim', json_encode($side, JSON_UNESCAPED_SLASHES)) === false) { @unlink($dest); return null; }
            @chmod($dir . '/' . $token . '.claim', 0600);
            // preview_url / thumb / large: the sm / lg PREVIEWS of the parked file (lazy preview.php URLs until they
            // exist — the browser's own previews usually arrive right after, upload-chunk.php action=previews),
            // never the multi-MB original itself; a video keeps its one URL.
            $url = (function_exists('basePath') ? basePath() : '') . '/uploads/' . $file;
            $pv  = !$side['video'] && function_exists('pvUrls') ? pvUrls($url) : ['thumb' => $url, 'large' => $url];
            return [
                'token'       => $token,
                'name'        => $side['name'],
                'size'        => $side['size'],
                'type'        => $side['video'] ? 'video' : 'image',
                'mime'        => $side['mime'],
                'ext'         => $ext,
                'preview_url' => (string)$pv['thumb'],
                'thumb'       => (string)$pv['thumb'],
                'large'       => (string)$pv['large'],
                'file'        => $file,
            ];
        }
        return null;
    }
}

if (!function_exists('uploadClaimFilePath')) {
    /**
     * Absolute path of a parked file by its sidecar name (tmp_<32hex>.<ext> — regex-locked, so the textual path
     * cannot leave uploads/), realpath-contained through uploadsPathOrNull(). When realpath() itself cannot
     * resolve (a stream-wrapped harness), the textually validated path stands, as tireImagePath() does.
     */
    function uploadClaimFilePath(string $file): ?string {
        if (!preg_match('/^tmp_[a-f0-9]{32}\.[a-z0-9]{1,8}$/', $file)) return null;
        $path = uploadsPathOrNull('uploads/' . $file);
        if ($path !== null) return $path;
        if (@realpath(uploadsDirPath()) !== false) return null;   // realpath works and the containment check refused → refuse
        $p = uploadsDirPath() . '/' . $file;
        return (!is_link($p) && is_file($p)) ? $p : null;
    }
}

if (!function_exists('uploadClaimRead')) {
    /**
     * Validate a posted token for a purpose + client: token format, sidecar present and matching, the tmp
     * file present directly inside uploads/ (realpath-contained) and younger than 24 h. Returns the sidecar
     * plus 'path' (absolute), or null (callers answer 400 "that upload has expired").
     */
    function uploadClaimRead($token, string $purpose, string $client): ?array {
        if (!uploadClaimValidToken($token)) return null;
        $dir = uploadClaimSidecarDir(false);
        if ($dir === null) return null;
        $sideFile = $dir . '/' . $token . '.claim';
        if (is_link($sideFile) || !is_file($sideFile)) return null;
        $side = json_decode((string)@file_get_contents($sideFile), true);
        if (!is_array($side) || ($side['token'] ?? '') !== $token) return null;
        if ((string)($side['purpose'] ?? '') !== $purpose || (string)($side['client'] ?? '') !== $client) return null;
        $path = uploadClaimFilePath((string)($side['file'] ?? ''));
        if ($path === null) return null;
        clearstatcache(true, $path);
        $mt = @filemtime($path);
        if ($mt === false || $mt < time() - uploadClaimTtl()) return null;
        $side['path'] = $path;
        $side['size'] = (int)@filesize($path);
        return $side;
    }
}

if (!function_exists('uploadClaimTake')) {
    /** Move the parked file to its final place (0644) and drop the sidecar. */
    function uploadClaimTake(array $claim, string $dest): bool {
        if (empty($claim['path']) || !is_file($claim['path'])) return false;
        if (!uploadMoveInto((string)$claim['path'], $dest, false)) return false;
        // Its previews (made by the browser at upload time, or lazily while the post was composed) follow it.
        if (function_exists('previewMoveDerivatives')) previewMoveDerivatives((string)$claim['path'], $dest);
        uploadClaimDiscard((string)($claim['token'] ?? ''), false);
        return true;
    }
}

if (!function_exists('uploadClaimDiscard')) {
    /** Delete a parked file (when $withFile) and its sidecar; idempotent. */
    function uploadClaimDiscard($token, bool $withFile = true): void {
        if (!uploadClaimValidToken($token)) return;
        $dir = uploadClaimSidecarDir(false);
        if ($dir === null) return;
        $sideFile = $dir . '/' . $token . '.claim';
        if ($withFile && is_file($sideFile)) {
            $side = json_decode((string)@file_get_contents($sideFile), true);
            $path = uploadClaimFilePath(is_array($side) ? (string)($side['file'] ?? '') : '');
            if ($path !== null) { if (function_exists('previewDelete')) previewDelete($path); @unlink($path); }
        }
        if (is_file($sideFile) || is_link($sideFile)) @unlink($sideFile);
    }
}

if (!function_exists('uploadClaimCleanup')) {
    /**
     * Remove parked files (uploads/tmp_<32hex>.<ext>) and sidecars (.spool/<32hex>.claim) older than 24 h,
     * plus the chunk spool's own leftovers (chunkSpoolCleanup). At most $cap entries per folder per call.
     */
    function uploadClaimCleanup(int $maxAge = 86400, int $cap = 200): int {
        $up = uploadsDirPath();
        if (!is_dir($up)) return 0;
        $n = 0; $cut = time() - $maxAge;
        if (function_exists('chunkSpoolCleanup')) $n += chunkSpoolCleanup($up, $maxAge, $cap);
        $dir = function_exists('chunkSpoolDir') ? chunkSpoolDir($up, false) : null;
        if ($dir !== null && ($dh = @opendir($dir)) !== false) {
            $seen = 0;
            while (($f = readdir($dh)) !== false && $seen < $cap) {
                if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
                $seen++;
                if (!preg_match('/^[a-f0-9]{32}\.claim$/', $f)) continue;
                $p = $dir . '/' . $f;
                if (is_link($p) || !is_file($p)) continue;
                $mt = @filemtime($p);
                $stale = $mt !== false && $mt < $cut;
                if (!$stale) {   // a sidecar whose parked file is gone (claimed, discarded or swept) is an orphan
                    $side = json_decode((string)@file_get_contents($p), true);
                    $file = is_array($side) ? (string)($side['file'] ?? '') : '';
                    $stale = !preg_match('/^tmp_[a-f0-9]{32}\.[a-z0-9]{1,8}$/', $file) || !is_file($up . '/' . $file);
                }
                if ($stale && @unlink($p)) $n++;
            }
            closedir($dh);
        }
        if (($dh = @opendir($up)) !== false) {
            $seen = 0;
            while (($f = readdir($dh)) !== false && $seen < $cap) {
                if (strpos($f, 'tmp_') !== 0) continue;
                $seen++;
                if (!preg_match('/^tmp_[a-f0-9]{32}\.[a-z0-9]{1,8}$/', $f)) continue;
                $p = $up . '/' . $f;
                if (is_link($p) || !is_file($p)) continue;
                $mt = @filemtime($p);
                if ($mt !== false && $mt < $cut) {
                    if (function_exists('previewDelete')) previewDelete($p);
                    if (@unlink($p)) $n++;
                }
            }
            closedir($dh);
        }
        return $n;
    }
}

// ---------------------------------------------------------------------
// Replace (post_images / tire_images) — replace-image.php and the `replace` purpose
// ---------------------------------------------------------------------

if (!function_exists('uploadReplaceRow')) {
    /** The row to replace, or null. */
    function uploadReplaceRow(PDO $pdo, string $type, int $imageId): ?array {
        if ($imageId <= 0) return null;
        if ($type === 'library') {   // library_images (+ the client's slug: the file lives in media/library/<slug>/)
            if (!function_exists('hasLibraryImagesTable') || !hasLibraryImagesTable($pdo)) return null;
            $sel = $pdo->prepare("SELECT li.id, li.filename, li.company_id, c.slug FROM library_images li INNER JOIN companies c ON c.id = li.company_id WHERE li.id = ?");
            $sel->execute([$imageId]);
            $row = $sel->fetch();
            return $row ?: null;
        }
        $table = $type === 'tire' ? 'tire_images' : 'post_images';
        $sel = $pdo->prepare("SELECT id, image_url FROM {$table} WHERE id = ?");
        $sel->execute([$imageId]);
        $row = $sel->fetch();
        return $row ?: null;
    }
}

if (!function_exists('uploadReplaceOwner')) {
    /** company_id of the post / tire the image belongs to (0 when unknown) — tenant check for a posted client slug. */
    function uploadReplaceOwner(PDO $pdo, string $type, int $imageId): int {
        try {
            if ($type === 'tire') {
                $st = $pdo->prepare("SELECT t.company_id FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.id = ?");
            } elseif ($type === 'library') {
                $st = $pdo->prepare("SELECT company_id FROM library_images WHERE id = ?");
            } else {
                $st = $pdo->prepare("SELECT p.company_id FROM post_images pi INNER JOIN posts p ON p.id = pi.post_id WHERE pi.id = ?");
            }
            $st->execute([$imageId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('uploadReplaceApply')) {
    /**
     * Replace the file behind a post_images / tire_images row with the validated file at $src
     * ($uploaded: PHP upload tmp → move_uploaded_file, else a spool / tmp file → rename).
     * A series render (media/tires/<tire>/<series>/<file>) is replaced IN PLACE (same folder, same stem,
     * the new extension — old file + thumb removed, thumb regenerated) so a later folder scan doesn't
     * re-import the old name; every other row gets a fresh uploads/img_ | vid_ name and the old
     * uploads/ file is deleted. Returns ['code' => 200|404|500, 'body' => the JSON reply array].
     */
    function uploadReplaceApply(PDO $pdo, string $type, int $imageId, string $src, string $ext, bool $isVideo, bool $uploaded): array {
        if ($type === 'library') return uploadReplaceLibrary($pdo, $imageId, $src, $ext, $isVideo, $uploaded);
        $type  = $type === 'tire' ? 'tire' : 'post';
        $table = $type === 'tire' ? 'tire_images' : 'post_images';
        $row   = uploadReplaceRow($pdo, $type, $imageId);
        if (!$row) return ['code' => 404, 'body' => ['ok' => false, 'error' => 'Image not found']];

        $inPlace = false; $oldPath = null; $oldThumb = null; $dest = ''; $newUrl = '';
        if ($type !== 'tire') {
            $oldPath = uploadsPathOrNull((string)$row['image_url']);   // for previewDelete() once the row points elsewhere
        }
        if ($type === 'tire') {
            $oldPath  = tireImagePath($row);
            $oldThumb = tireThumbPath($row);
            if ($oldPath !== null && strpos(ltrim((string)$row['image_url'], '/'), 'media/tires/') === 0) {
                $inPlace = true;
                $dir     = dirname($oldPath);
                $stem    = pathinfo($oldPath, PATHINFO_FILENAME);
                $newName = $stem . '.' . $ext;
                if (strcasecmp($newName, basename($oldPath)) !== 0) {
                    for ($n = 2; file_exists($dir . '/' . $newName) && $n < 1000; $n++) { $newName = $stem . '-' . $n . '.' . $ext; }
                }
                $dest   = $dir . '/' . $newName;
                $newUrl = rtrim(dirname(ltrim((string)$row['image_url'], '/')), '/') . '/' . $newName;
            }
        }
        if (!$inPlace) {
            $up = uploadsDirEnsure();
            if ($up === null) return ['code' => 500, 'body' => ['ok' => false, 'error' => 'uploads/ is not writable on the server']];
            $newName = uploadFreshName($isVideo ? 'vid_' : 'img_', $ext);
            $dest    = $up . '/' . $newName;
            $newUrl  = 'uploads/' . $newName;
        }

        if (!uploadMoveInto($src, $dest, $uploaded)) {
            return ['code' => 500, 'body' => ['ok' => false, 'error' => 'Failed to save file (check ' . ($inPlace ? 'media/tires/' : 'uploads/') . ' permissions)']];
        }

        try {
            // post_images has a media_type column once migrate.php has run; tire_images doesn't.
            if ($table === 'post_images' && hasMediaTypeColumn($pdo)) {
                $upd = $pdo->prepare("UPDATE post_images SET image_url = ?, media_type = ? WHERE id = ?");
                $upd->execute([$newUrl, $isVideo ? 'video' : 'image', $imageId]);
            } else {
                $upd = $pdo->prepare("UPDATE {$table} SET image_url = ? WHERE id = ?");
                $upd->execute([$newUrl, $imageId]);
            }

            // The old file: inside uploads/ (realpath-contained), or the in-place original when the extension changed; stale thumb too.
            $oldUrl = (string)$row['image_url'];
            if ($inPlace) {
                if ($oldPath !== null && realpath($oldPath) !== realpath($dest) && is_file($oldPath)) @unlink($oldPath);
            } else {
                $old = uploadsPathOrNull($oldUrl);
                if ($old !== null) @unlink($old);
            }
            if ($type === 'tire') {
                if ($oldThumb !== null && is_file($oldThumb)) @unlink($oldThumb);
                $newThumb = tireThumbPath(['image_url' => $newUrl]);
                if ($newThumb !== null && is_file($newThumb)) @unlink($newThumb);
            }
            // Previews: the old original's derivatives go (same stem in place → must be rebuilt), the new file gets fresh ones.
            if ($oldPath !== null && function_exists('previewDelete')) previewDelete($oldPath);
            if (function_exists('previewDelete')) previewDelete($dest);
            if (!$isVideo && function_exists('previewAfterStore')) { previewReleaseSession(); previewAfterStore($dest); }
            if ($type === 'post') {
                $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id = (SELECT post_id FROM post_images WHERE id = ?)")->execute([$imageId]);
            }
            // A queued Redo image: off the queue and back to To Review (the client hears of it) — redo-lib.php.
            $redo = ($type === 'tire' && function_exists('redoAfterReplace')) ? redoAfterReplace($pdo, 'tire', $imageId) : null;
            return ['code' => 200, 'body' => [
                'ok'         => true,
                'image_id'   => $imageId,
                'image_url'  => $newUrl,
                'src'        => $type === 'tire' ? tireImageSrc($newUrl) : (basePath() . '/' . ltrim($newUrl, '/')),   // ready-to-use URL (media/tires rows are root-relative)
                'media_type' => $isVideo ? 'video' : 'image',
            ] + ($redo ? ['redo_cleared' => true, 'status' => $redo['status']] : [])];
        } catch (Throwable $e) {
            if (is_file($dest)) @unlink($dest);
            error_log('upload replace: ' . $e->getMessage());
            return ['code' => 500, 'body' => ['ok' => false, 'error' => 'Database error']];
        }
    }
}

if (!function_exists('uploadReplaceLibrary')) {
    /**
     * Replace the file behind a library_images row IN PLACE (uploadReplaceApply() type 'library'): media/library/<slug>/
     * <same stem>.<new ext> — "-2", "-3" … only when the extension changes and that name is taken on disk or by another
     * row — the row's filename follows, the old file and its previews go, fresh previews are made. A queued Redo image
     * leaves the queue and goes back to To Review (redoAfterReplace()). Reply shape = uploadReplaceApply()'s (image_url /
     * src = the file's root-relative URL, + filename).
     */
    function uploadReplaceLibrary(PDO $pdo, int $imageId, string $src, string $ext, bool $isVideo, bool $uploaded): array {
        $row = uploadReplaceRow($pdo, 'library', $imageId);
        $old = $row ? (string)$row['filename'] : '';
        $slug = $row ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$row['slug'])) : '';
        if (!$row || $slug === '' || $old === '' || $old !== basename($old) || $old[0] === '.') return ['code' => 404, 'body' => ['ok' => false, 'error' => 'Image not found']];
        $dir = libraryDir($slug);
        if (!is_dir($dir) || !is_writable($dir)) return ['code' => 500, 'body' => ['ok' => false, 'error' => 'media/library/' . $slug . '/ is not writable on the server']];
        $real = realpath($dir); $rootReal = realpath(dirname($dir));
        if ($real !== false && $rootReal !== false && $real !== rtrim($rootReal, '/') . '/' . $slug) {
            return ['code' => 500, 'body' => ['ok' => false, 'error' => 'The Library folder resolves outside media/library/']];
        }
        $stem = pathinfo($old, PATHINFO_FILENAME);
        $name = $stem . '.' . $ext;
        if (strcasecmp($name, $old) !== 0) {
            $taken = $pdo->prepare("SELECT 1 FROM library_images WHERE company_id = ? AND filename = ? AND id <> ? LIMIT 1");
            for ($n = 2; $n < 1000; $n++) {
                $taken->execute([(int)$row['company_id'], $name, $imageId]);
                if (!file_exists($dir . '/' . $name) && !$taken->fetchColumn()) break;
                $name = $stem . '-' . $n . '.' . $ext;
            }
        }
        $oldPath = $dir . '/' . $old;
        $dest    = $dir . '/' . $name;
        if (!uploadMoveInto($src, $dest, $uploaded)) return ['code' => 500, 'body' => ['ok' => false, 'error' => 'Failed to save file (check media/library/ permissions)']];
        if (function_exists('mediaChmodPath')) mediaChmodPath($dest);
        try {
            if ($name !== $old) {
                $pdo->prepare("UPDATE library_images SET filename = ? WHERE id = ?")->execute([$name, $imageId]);
                if (is_file($oldPath) && !is_link($oldPath)) @unlink($oldPath);
            }
            if (function_exists('previewDelete')) { previewDelete($oldPath); previewDelete($dest); }
            if (!$isVideo && function_exists('previewAfterStore')) { previewReleaseSession(); previewAfterStore($dest); }
            $redo = function_exists('redoAfterReplace') ? redoAfterReplace($pdo, 'library', $imageId) : null;
            $url = libraryFileUrl($slug, $name);
            return ['code' => 200, 'body' => [
                'ok'         => true,
                'image_id'   => $imageId,
                'image_url'  => $url,
                'src'        => $url,
                'filename'   => $name,
                'media_type' => $isVideo ? 'video' : 'image',
            ] + ($redo ? ['redo_cleared' => true, 'status' => $redo['status']] : [])];
        } catch (Throwable $e) {
            error_log('upload replace library: ' . $e->getMessage());
            return ['code' => 500, 'body' => ['ok' => false, 'error' => 'Database error']];
        }
    }
}

// ---------------------------------------------------------------------
// Tire reference images (add-feature.php's "Add more images")
// ---------------------------------------------------------------------

if (!function_exists('uploadFeatureMaxImages')) {
    function uploadFeatureMaxImages(): int { return 6; }
}

if (!function_exists('uploadFeatureRefOnlySql')) {
    /** ' AND series_id IS NULL' once tire series exist (renders never count against the reference slots). */
    function uploadFeatureRefOnlySql(PDO $pdo): string {
        return (function_exists('hasTireSeries') && hasTireSeries($pdo)) ? ' AND series_id IS NULL' : '';
    }
}

if (!function_exists('uploadFeatureCount')) {
    function uploadFeatureCount(PDO $pdo, int $tireId): int {
        $st = $pdo->prepare("SELECT COUNT(*) FROM tire_images WHERE tire_id = ?" . uploadFeatureRefOnlySql($pdo));
        $st->execute([$tireId]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('uploadFeatureTire')) {
    /** The tire row (id, company_id, name) or null. */
    function uploadFeatureTire(PDO $pdo, int $tireId): ?array {
        if ($tireId <= 0) return null;
        $st = $pdo->prepare("SELECT id, company_id, name FROM tires WHERE id = ?");
        $st->execute([$tireId]);
        $t = $st->fetch();
        return $t ?: null;
    }
}

if (!function_exists('uploadFeatureInsert')) {
    /**
     * Store a validated image as uploads/feat_<uniqid>.<ext> and insert the reference row (sort_order max+1,
     * display_name = the original stem when the column exists, caption ''). The 6-cap is checked here again.
     * Returns ['code' => 200|409|500, 'body' => reply] — the reply carries image {id, tire_id, image_url, src,
     * thumb, display_name, sort_order}, count and max.
     */
    function uploadFeatureInsert(PDO $pdo, array $tire, string $src, string $origName, string $ext, bool $uploaded): array {
        $tireId = (int)$tire['id'];
        $max = uploadFeatureMaxImages();
        $have = uploadFeatureCount($pdo, $tireId);
        if ($have >= $max) return ['code' => 409, 'body' => ['ok' => false, 'error' => "Max {$max} reference images per item — remove one first.", 'count' => $have, 'max' => $max]];
        $up = uploadsDirEnsure();
        if ($up === null) return ['code' => 500, 'body' => ['ok' => false, 'error' => 'uploads/ is not writable on the server']];
        $newName = uploadFreshName('feat_', $ext);
        $dest = $up . '/' . $newName;
        $url  = 'uploads/' . $newName;
        if (!uploadMoveInto($src, $dest, $uploaded)) return ['code' => 500, 'body' => ['ok' => false, 'error' => 'Failed to save the file (check uploads/ permissions)']];
        $seed = safeFilenameStem(pathinfo($origName, PATHINFO_FILENAME));
        if ($seed === '') $seed = null;
        try {
            $pdo->beginTransaction();
            $sortQ = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM tire_images WHERE tire_id = ?" . uploadFeatureRefOnlySql($pdo));
            $sortQ->execute([$tireId]);
            $sortOrder = (int)$sortQ->fetchColumn() + 1;
            $hasDisplay = function_exists('tireImagesHaveDisplayName') ? tireImagesHaveDisplayName($pdo) : ($pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0);
            if ($hasDisplay) {
                $ins = $pdo->prepare("INSERT INTO tire_images (tire_id, image_url, caption, sort_order, display_name) VALUES (?, ?, '', ?, ?)");
                $ins->execute([$tireId, $url, $sortOrder, $seed]);
            } else {
                $ins = $pdo->prepare("INSERT INTO tire_images (tire_id, image_url, caption, sort_order) VALUES (?, ?, '', ?)");
                $ins->execute([$tireId, $url, $sortOrder]);
            }
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            @unlink($dest);
            error_log('upload feature insert: ' . $e->getMessage());
            return ['code' => 500, 'body' => ['ok' => false, 'error' => 'Database error']];
        }
        $row = ['id' => $id, 'tire_id' => $tireId, 'image_url' => $url, 'display_name' => $seed, 'sort_order' => $sortOrder, 'status' => 'pending'];
        $thumb = '';
        if (function_exists('previewAfterStore')) { try { previewReleaseSession(); previewAfterStore($dest); $thumb = tireImageThumb($row); } catch (Throwable $e) { $thumb = ''; } }
        return ['code' => 200, 'body' => [
            'ok'    => true,
            'image' => $row + ['src' => basePath() . '/' . $url, 'thumb' => $thumb !== '' ? $thumb : basePath() . '/' . $url],
            'count' => $have + 1,
            'max'   => $max,
        ]];
    }
}

// ---------------------------------------------------------------------
// Library (the Upload sheet's "Library" destination)
// ---------------------------------------------------------------------

if (!function_exists('uploadLibraryStore')) {
    /**
     * Put a validated file into the brand's Library: media/library/<slug>/<stem>.<ext> (stem = the original
     * name through safeFilenameStem(); "-2", "-3" … when the name is taken on disk OR by a library_images row,
     * so a new file can never inherit an old row's approval), 0644, the row inserted as 'pending' — the status
     * syncLibraryImages() gives a file dropped in by FTP — and the sm / lg previews made (preview-lib.php).
     * Returns ['code' => 200|500, 'body' => reply]; the reply carries image {id, filename, src, thumb, status, type}.
     */
    function uploadLibraryStore(PDO $pdo, array $company, string $src, string $origName, string $ext, bool $isVideo, bool $uploaded): array {
        if (!function_exists('hasLibraryImagesTable') || !hasLibraryImagesTable($pdo)) {
            return ['code' => 500, 'body' => ['ok' => false, 'error' => 'The Library is not set up yet — run migrate.php.']];
        }
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($company['slug'] ?? '')));
        if ($slug === '') return ['code' => 400, 'body' => ['ok' => false, 'error' => 'Pick a client first']];
        $dir = libraryDir($slug);
        if (function_exists('mediaMkdir')) { mediaMkdir($dir, function_exists('mediaRootPath') ? mediaRootPath() : null); } else { @mkdir($dir, 0755, true); }
        if (!is_dir($dir) || !is_writable($dir)) return ['code' => 500, 'body' => ['ok' => false, 'error' => 'media/library/' . $slug . '/ is not writable on the server']];
        // Containment: the folder must resolve to media/library/<slug> (no symlink escape).
        $real = realpath($dir); $rootReal = realpath(dirname($dir));
        if ($real !== false && $rootReal !== false && $real !== rtrim($rootReal, '/') . '/' . $slug) {
            return ['code' => 500, 'body' => ['ok' => false, 'error' => 'The Library folder resolves outside media/library/']];
        }
        $stem = trim((string)safeFilenameStem(pathinfo(basename($origName), PATHINFO_FILENAME)), '.-_ ');
        if ($stem === '' || $stem[0] === '.' || preg_match('/[\/\\\\\0]/', $stem)) $stem = 'upload';
        $taken = $pdo->prepare("SELECT 1 FROM library_images WHERE company_id = ? AND filename = ? LIMIT 1");
        $name = '';
        for ($n = 1; $n < 1000; $n++) {
            $try = $stem . ($n > 1 ? '-' . $n : '') . '.' . $ext;
            if (file_exists($dir . '/' . $try)) continue;
            $taken->execute([(int)$company['id'], $try]);
            if ($taken->fetchColumn()) continue;
            $name = $try;
            break;
        }
        if ($name === '') return ['code' => 500, 'body' => ['ok' => false, 'error' => 'Could not pick a free file name']];
        $dest = $dir . '/' . $name;
        if (!uploadMoveInto($src, $dest, $uploaded)) return ['code' => 500, 'body' => ['ok' => false, 'error' => 'Failed to save the file (check media/library/ permissions)']];
        try {
            $ins = $pdo->prepare("INSERT INTO library_images (company_id, filename, status) VALUES (?, ?, 'pending')");
            $ins->execute([(int)$company['id'], $name]);
            $id = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            @unlink($dest);
            error_log('upload library insert: ' . $e->getMessage());
            return ['code' => 500, 'body' => ['ok' => false, 'error' => 'Database error']];
        }
        // sent for review: the client's "Ready for your review" email (client-notify-lib.php) hears of it
        logActivity($pdo, (int)$company['id'], 'library_image', $id, 'uploaded', (function_exists('isAdmin') && isAdmin()) ? 'admin' : 'client',
            'Uploaded ' . $name . ' to the Library for review', null, newBatchId());
        if (function_exists('ensureLibraryMediaHtaccess')) ensureLibraryMediaHtaccess();   // media/library/.htaccess: static only + 7-day caching
        if (!$isVideo && function_exists('previewAfterStore')) { try { previewReleaseSession(); previewAfterStore($dest); } catch (Throwable $e) { /* previews are best-effort */ } }
        $url = libraryFileUrl($slug, $name);
        $pv  = !$isVideo && function_exists('pvUrls') ? pvUrls($url) : ['thumb' => $url, 'large' => $url];
        return ['code' => 200, 'body' => [
            'ok'    => true,
            'image' => ['id' => $id, 'filename' => $name, 'src' => $url, 'thumb' => (string)$pv['thumb'], 'status' => 'pending', 'type' => $isVideo ? 'video' : 'image'],
        ]];
    }
}
