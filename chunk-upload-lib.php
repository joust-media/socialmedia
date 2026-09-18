<?php
/**
 * Chunked, resumable uploads — the spool helpers shared by tire-upload.php (Studio → Renders)
 * and page-upload.php (Studio → Pages). Shared hosting caps a single request at
 * upload_max_filesize / post_max_size (often 64 MB or less), so a large video is sent as a
 * sequence of small requests that are appended to ONE spool file, then finalized:
 *
 *   action=probe          → {chunk_size, max_file_bytes, ini_max, exts}         (what the client should send)
 *   action=chunk_init     → allocates <upload_id> (32 hex) + <root>/.spool/<upload_id>.part (0600) and a
 *                           sidecar <upload_id>.json (owner / target / name / size / mime / received)
 *   action=chunk_put      → appends the chunk at `offset` (must equal `received`; else 409 with the current
 *                           `received` so the client resumes); an already-received range is a 200 no-op
 *   action=chunk_status   → {received, size}                                    (resume after a reload)
 *   action=chunk_finish   → the endpoint validates the spooled file exactly like a single-request upload
 *                           and moves it into place (rename), then deletes the sidecar
 *   action=chunk_abort    → deletes spool + sidecar
 *
 * The spool folder is dot-prefixed (`.spool`), so the tire folder scan (dot-folders skipped, `.part` is
 * not a media extension) and Pages (DB-driven) never see it; it carries a deny-all .htaccess on top of
 * the media/ hardening. Spool files older than 24 h are removed on probe / chunk_init (cheap, capped).
 *
 * Every function is function_exists-guarded and does no work at load.
 */

if (!function_exists('chunkUploadShorthandBytes')) {
    /** php.ini shorthand ('64M', '2G', '512K', '1048576') → bytes; '' / '-1' / '0' → 0 (unlimited). */
    function chunkUploadShorthandBytes($v): int {
        $v = trim((string)$v);
        if ($v === '' || $v === '-1' || $v === '0') return 0;
        if (!preg_match('/^(\d+)\s*([kmgKMG]?)$/', $v, $m)) return 0;
        $n = (int)$m[1];
        switch (strtolower($m[2])) {
            case 'g': $n *= 1024;   // fall through
            case 'm': $n *= 1024;   // fall through
            case 'k': $n *= 1024;
        }
        return $n;
    }
}

if (!function_exists('chunkUploadIniBytes')) {
    /** The smaller of upload_max_filesize and post_max_size in bytes (0 when both are unlimited). */
    function chunkUploadIniBytes(): int {
        $u = chunkUploadShorthandBytes(ini_get('upload_max_filesize'));
        $p = chunkUploadShorthandBytes(ini_get('post_max_size'));
        if ($u > 0 && $p > 0) return min($u, $p);
        return max($u, $p);
    }
}

if (!function_exists('chunkUploadChunkSize')) {
    /** min(8 MiB, 80 % of the request cap) — never below 256 KiB so a tiny cap still moves. */
    function chunkUploadChunkSize(): int {
        $cap = chunkUploadIniBytes();
        $size = 8 * 1024 * 1024;
        if ($cap > 0) $size = min($size, (int)floor($cap * 0.8));
        return max(256 * 1024, $size);
    }
}

if (!function_exists('chunkUploadValidId')) {
    function chunkUploadValidId($id): bool {
        return is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1;
    }
}

if (!function_exists('chunkUploadBatchId')) {
    /** The batch-id rule both endpoints use: 16-hex as is, any other short token hashed to 16-hex, else fresh. */
    function chunkUploadBatchId($raw, string $salt): string {
        $raw = is_string($raw) ? $raw : '';
        if (preg_match('/^[0-9a-f]{16}$/', $raw)) return $raw;
        if (preg_match('/^[A-Za-z0-9_\-]{4,40}$/', $raw)) return substr(sha1($salt . ':' . $raw), 0, 16);
        return function_exists('newBatchId') ? newBatchId() : bin2hex(random_bytes(8));
    }
}

if (!function_exists('chunkSpoolHtaccessText')) {
    function chunkSpoolHtaccessText(): string {
        return "# Written by the portal (chunk-upload-lib.php): in-flight upload pieces. Never served.\n"
             . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    }
}

if (!function_exists('chunkSpoolDir')) {
    /**
     * <root>/.spool — created 0700 on demand with a deny-all .htaccess. Returns null when the root
     * is missing / not writable or when the resolved folder is not directly inside the root
     * (a symlinked .spool left on the server must not redirect writes elsewhere).
     */
    function chunkSpoolDir(string $root, bool $create = true): ?string {
        $root = rtrim($root, '/');
        if ($root === '' || !is_dir($root)) return null;
        $dir = $root . '/.spool';
        if (!is_dir($dir)) {
            if (!$create || !is_writable($root)) return null;
            if (!@mkdir($dir, 0700) && !is_dir($dir)) return null;
        }
        if (is_link($dir) || !is_writable($dir)) return null;
        $rootReal = realpath($root); $dirReal = realpath($dir);
        if ($rootReal !== false && $dirReal !== false && $dirReal !== rtrim($rootReal, '/') . '/.spool') return null;
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) { @file_put_contents($ht, chunkSpoolHtaccessText()); @chmod($ht, 0644); }
        return $dir;
    }
}

if (!function_exists('chunkSpoolPaths')) {
    /** ['part' => …, 'meta' => …] for a valid id inside an existing spool folder; null otherwise. */
    function chunkSpoolPaths(string $root, $id, bool $create = false): ?array {
        if (!chunkUploadValidId($id)) return null;
        $dir = chunkSpoolDir($root, $create);
        if ($dir === null) return null;
        return ['dir' => $dir, 'part' => $dir . '/' . $id . '.part', 'meta' => $dir . '/' . $id . '.json'];
    }
}

if (!function_exists('chunkUploadMeta')) {
    /** The decoded sidecar (with 'received' refreshed from the part file's real size) or null. */
    function chunkUploadMeta(string $root, $id): ?array {
        $p = chunkSpoolPaths($root, $id);
        if ($p === null || !is_file($p['meta']) || !is_file($p['part']) || is_link($p['part'])) return null;
        $meta = json_decode((string)@file_get_contents($p['meta']), true);
        if (!is_array($meta) || ($meta['upload_id'] ?? '') !== $id) return null;
        clearstatcache(true, $p['part']);
        $meta['received'] = (int)@filesize($p['part']);
        return $meta;
    }
}

if (!function_exists('chunkUploadSaveMeta')) {
    function chunkUploadSaveMeta(string $root, $id, array $meta): bool {
        $p = chunkSpoolPaths($root, $id);
        if ($p === null) return false;
        $meta['updated_at'] = time();
        $ok = @file_put_contents($p['meta'], json_encode($meta, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
        if ($ok) @chmod($p['meta'], 0600);
        return $ok;
    }
}

if (!function_exists('chunkUploadInit')) {
    /**
     * Allocate an upload: empty part file (0600) + sidecar. $meta carries whatever the endpoint needs
     * to finalize (kind, client, company_id, target ids, name, ext, size, mime, batch). Returns the
     * completed meta (with upload_id, received 0, created_at) or null when the spool is unusable.
     */
    function chunkUploadInit(string $root, array $meta): ?array {
        $dir = chunkSpoolDir($root, true);
        if ($dir === null) return null;
        for ($try = 0; $try < 5; $try++) {
            $id = bin2hex(random_bytes(16));
            $part = $dir . '/' . $id . '.part';
            if (file_exists($part)) continue;
            $fh = @fopen($part, 'xb');   // exclusive create
            if ($fh === false) continue;
            fclose($fh);
            @chmod($part, 0600);
            $meta['upload_id']  = $id;
            $meta['received']   = 0;
            $meta['created_at'] = time();
            if (!chunkUploadSaveMeta($root, $id, $meta)) { @unlink($part); return null; }
            return $meta;
        }
        return null;
    }
}

if (!function_exists('chunkUploadAppend')) {
    /**
     * Append the bytes of $srcPath at $offset. The part file's real size (under an exclusive lock)
     * is the source of truth:
     *   offset + len <= size on disk  → already received (idempotent re-put)   → {ok, received}
     *   offset != size on disk        → out of order                            → {ok:false, code:409, received}
     *   size on disk + len > total    → more than announced                     → {ok:false, code:413}
     */
    function chunkUploadAppend(string $root, $id, string $srcPath, int $offset, int $total): array {
        $p = chunkSpoolPaths($root, $id);
        if ($p === null || !is_file($p['part']) || is_link($p['part'])) return ['ok' => false, 'code' => 404, 'error' => 'Unknown upload', 'received' => 0];
        if (!is_file($srcPath)) return ['ok' => false, 'code' => 400, 'error' => 'No chunk received', 'received' => 0];
        $len = (int)filesize($srcPath);
        $fh = @fopen($p['part'], 'ab');
        if ($fh === false) return ['ok' => false, 'code' => 500, 'error' => 'Spool file is not writable', 'received' => 0];
        if (!flock($fh, LOCK_EX)) { fclose($fh); return ['ok' => false, 'code' => 500, 'error' => 'Could not lock the spool file', 'received' => 0]; }
        $st = fstat($fh);
        $have = (int)($st['size'] ?? 0);
        $done = function (array $r) use ($fh) { flock($fh, LOCK_UN); fclose($fh); return $r; };
        if ($len === 0) return $done(['ok' => false, 'code' => 400, 'error' => 'Empty chunk', 'received' => $have]);
        if ($offset + $len <= $have) return $done(['ok' => true, 'received' => $have, 'duplicate' => true]);
        if ($offset !== $have) return $done(['ok' => false, 'code' => 409, 'error' => 'Out of order — resume from ' . $have, 'received' => $have]);
        if ($have + $len > $total) return $done(['ok' => false, 'code' => 413, 'error' => 'More bytes than announced', 'received' => $have]);
        $in = @fopen($srcPath, 'rb');
        if ($in === false) return $done(['ok' => false, 'code' => 500, 'error' => 'Could not read the chunk', 'received' => $have]);
        $copied = stream_copy_to_stream($in, $fh);
        fclose($in);
        fflush($fh);
        $st = fstat($fh);
        $now = (int)($st['size'] ?? 0);
        if ($copied !== $len || $now !== $have + $len) {
            ftruncate($fh, $have);   // keep the file consistent: the client re-sends this chunk
            return $done(['ok' => false, 'code' => 500, 'error' => 'Short write — try that piece again', 'received' => $have]);
        }
        $r = $done(['ok' => true, 'received' => $now]);
        $meta = chunkUploadMeta($root, $id);
        if ($meta) { $meta['received'] = $now; chunkUploadSaveMeta($root, $id, $meta); }
        return $r;
    }
}

if (!function_exists('chunkUploadDiscard')) {
    /** Delete spool + sidecar (idempotent). */
    function chunkUploadDiscard(string $root, $id): void {
        $p = chunkSpoolPaths($root, $id);
        if ($p === null) return;
        foreach ([$p['part'], $p['meta']] as $f) { if (is_file($f) || is_link($f)) @unlink($f); }
    }
}

if (!function_exists('chunkUploadTake')) {
    /**
     * Move the finished spool file to $dest (rename, falling back to copy + unlink across devices)
     * and remove the sidecar. False when the part is missing or the move failed (spool kept for a retry).
     */
    function chunkUploadTake(string $root, $id, string $dest): bool {
        $p = chunkSpoolPaths($root, $id);
        if ($p === null || !is_file($p['part']) || is_link($p['part'])) return false;
        $ok = @rename($p['part'], $dest);
        if (!$ok && @copy($p['part'], $dest)) { $ok = true; @unlink($p['part']); }
        if (!$ok) return false;
        @unlink($p['meta']);
        return true;
    }
}

if (!function_exists('chunkSpoolCleanup')) {
    /** Remove .part / .json spool files older than $maxAge seconds (mtime); at most $cap directory entries are looked at. */
    function chunkSpoolCleanup(string $root, int $maxAge = 86400, int $cap = 200): int {
        $dir = chunkSpoolDir($root, false);
        if ($dir === null) return 0;
        $dh = @opendir($dir);
        if ($dh === false) return 0;
        $n = 0; $seen = 0; $cut = time() - $maxAge;
        while (($f = readdir($dh)) !== false && $seen < $cap) {
            if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
            $seen++;
            if (!preg_match('/^[a-f0-9]{32}\.(part|json)$/', $f)) continue;
            $path = $dir . '/' . $f;
            if (is_link($path) || !is_file($path)) continue;
            $mt = @filemtime($path);
            if ($mt !== false && $mt < $cut && @unlink($path)) $n++;
        }
        closedir($dh);
        return $n;
    }
}
