<?php
/**
 * Approved-asset export (Studio → Export) — the helpers export.php and studio.php share.
 *
 * The admin picks a scope (everything approved for the client, one tire, or one series) and
 * what to include (photos, videos, reference images, library images) and gets ONE zip:
 *
 *   <Client Name>/
 *     manifest.csv / manifest.json
 *     <Tire Name>/Reference/<file>          reference images (tire_images.series_id IS NULL)
 *     <Tire Name>/<Series Name>/<file>      series renders
 *     Library/<file>                        approved library_images (scope "all" only)
 *
 * Shared hosting has no long requests, so a build is a stepwise job:
 *   start → exportEnumerate() lists every file (one filesize() stat each) into a sidecar
 *           uploads/.exports/<job>.json (job = 32 hex; the folder is dot-prefixed and carries a
 *           deny-all .htaccess, so nothing in it is web-reachable);
 *   step  → exportStep() appends the next ~64 MB / ~15 s worth of bytes to uploads/.exports/<job>.zip
 *           and records the progress; a file larger than the budget is continued across steps
 *           (its CRC is combined range by range — exportCrc32Combine, zlib's algorithm);
 *   finish (last step) → manifest.csv + manifest.json entries, central directory, end records.
 *
 * The zip is written by this file, not ZipArchive: libzip rewrites the whole archive on every
 * close(), which would make a 5 GB export copy itself dozens of times. Entries are STORED (media
 * is already compressed — no CPU spent), names are UTF-8 (general purpose bit 11), and the
 * zip64 records / extra fields are emitted whenever an entry or offset needs them, so exports
 * above 4 GB open in macOS Archive Utility, Windows Explorer, 7-Zip and `unzip`. 64-bit PHP is
 * required for the offsets (exportZipSupported()); a 32-bit build only gets the manifest CSV.
 *
 * Every function is function_exists-guarded and does no work at load.
 */

if (!defined('EXPORT_MAX_BYTES'))     define('EXPORT_MAX_BYTES', 20 * 1024 * 1024 * 1024);   // one export: 20 GB — split by tire above that
if (!defined('EXPORT_STEP_BYTES'))    define('EXPORT_STEP_BYTES', 64 * 1024 * 1024);          // per step: 64 MB …
if (!defined('EXPORT_STEP_SECONDS'))  define('EXPORT_STEP_SECONDS', 15);                      // … or 15 s, whichever first
if (!defined('EXPORT_JOB_MAX_AGE'))   define('EXPORT_JOB_MAX_AGE', 86400);                    // jobs + zips older than 24 h are removed on start
if (!defined('EXPORT_IO_CHUNK'))      define('EXPORT_IO_CHUNK', 1024 * 1024);                 // read / write / stream in 1 MB pieces

// ---------------------------------------------------------------------
// Folder + job files
// ---------------------------------------------------------------------

if (!function_exists('exportsRoot')) {
    /** <app>/uploads — the only folder the portal already writes to on every host. */
    function exportsRoot(): string {
        return __DIR__ . '/uploads';
    }
}

if (!function_exists('exportHtaccessText')) {
    function exportHtaccessText(): string {
        return "# Written by the portal (export-lib.php): export jobs and zips. Served only through export.php.\n"
             . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    }
}

if (!function_exists('exportsDir')) {
    /**
     * uploads/.exports — created 0700 on demand with a deny-all .htaccess. Null when uploads/ is
     * missing / not writable or when the resolved folder is not directly inside it (a symlinked
     * .exports left on the server must not redirect writes elsewhere).
     */
    function exportsDir(bool $create = true): ?string {
        $root = rtrim(exportsRoot(), '/');
        if ($root === '' || !is_dir($root)) return null;
        $dir = $root . '/.exports';
        if (!is_dir($dir)) {
            if (!$create || !is_writable($root)) return null;
            if (!@mkdir($dir, 0700) && !is_dir($dir)) return null;
        }
        if (is_link($dir) || !is_writable($dir)) return null;
        $rootReal = realpath($root); $dirReal = realpath($dir);
        if ($rootReal !== false && $dirReal !== false && $dirReal !== rtrim($rootReal, '/') . '/.exports') return null;
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) { @file_put_contents($ht, exportHtaccessText()); @chmod($ht, 0644); }
        return $dir;
    }
}

if (!function_exists('exportValidJobId')) {
    function exportValidJobId($id): bool {
        return is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1;
    }
}

if (!function_exists('exportJobPaths')) {
    /** ['dir', 'meta' => …/<job>.json, 'zip' => …/<job>.zip] for a valid id; null otherwise. */
    function exportJobPaths($id, bool $create = false): ?array {
        if (!exportValidJobId($id)) return null;
        $dir = exportsDir($create);
        if ($dir === null) return null;
        return ['dir' => $dir, 'meta' => $dir . '/' . $id . '.json', 'zip' => $dir . '/' . $id . '.zip'];
    }
}

if (!function_exists('exportReadJob')) {
    /** The decoded sidecar or null (unknown id, unreadable, or the sidecar names another job). */
    function exportReadJob($id): ?array {
        $p = exportJobPaths($id);
        if ($p === null || !is_file($p['meta']) || is_link($p['meta'])) return null;
        $job = json_decode((string)@file_get_contents($p['meta']), true);
        if (!is_array($job) || ($job['job'] ?? '') !== $id) return null;
        return $job;
    }
}

if (!function_exists('exportSaveJob')) {
    /** Atomic sidecar write (temp file + rename) so a step that dies mid-write leaves the old state intact. */
    function exportSaveJob(array $job): bool {
        $p = exportJobPaths($job['job'] ?? null, true);
        if ($p === null) return false;
        $job['updated_at'] = time();
        $tmp = $p['meta'] . '.tmp';
        if (@file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) { @unlink($tmp); return false; }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $p['meta'])) { @unlink($tmp); return false; }
        return true;
    }
}

if (!function_exists('exportDeleteJob')) {
    /** Remove sidecar + zip (+ a stray temp file). Idempotent. */
    function exportDeleteJob($id): void {
        $p = exportJobPaths($id);
        if ($p === null) return;
        foreach ([$p['zip'], $p['meta'], $p['meta'] . '.tmp'] as $f) { if (is_file($f) || is_link($f)) @unlink($f); }
    }
}

if (!function_exists('exportCleanup')) {
    /** Delete jobs (sidecar + zip) whose sidecar is older than $maxAge, plus orphan zips; looks at $cap entries at most. */
    function exportCleanup(int $maxAge = EXPORT_JOB_MAX_AGE, int $cap = 500): int {
        $dir = exportsDir(false);
        if ($dir === null) return 0;
        $dh = @opendir($dir);
        if ($dh === false) return 0;
        $n = 0; $seen = 0; $cut = time() - $maxAge; $zips = [];
        while (($f = readdir($dh)) !== false && $seen < $cap) {
            if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
            $seen++;
            if (preg_match('/^([a-f0-9]{32})\.zip$/', $f, $m)) { $zips[$m[1]] = $dir . '/' . $f; continue; }
            if (!preg_match('/^([a-f0-9]{32})\.json(\.tmp)?$/', $f, $m)) continue;
            $path = $dir . '/' . $f;
            if (is_link($path) || !is_file($path)) continue;
            $mt = @filemtime($path);
            if ($mt !== false && $mt < $cut) { exportDeleteJob($m[1]); $n++; }
        }
        closedir($dh);
        foreach ($zips as $id => $zip) {   // a zip without a sidecar is unreachable — drop it once it is old
            if (is_file($dir . '/' . $id . '.json')) continue;
            $mt = @filemtime($zip);
            if ($mt !== false && $mt < $cut && @unlink($zip)) $n++;
        }
        return $n;
    }
}

if (!function_exists('exportJobSummary')) {
    /** What the UI lists: never the file list or paths. */
    function exportJobSummary(array $job): array {
        $files = is_array($job['files'] ?? null) ? $job['files'] : [];
        $prog  = is_array($job['progress'] ?? null) ? $job['progress'] : [];
        return [
            'job'        => (string)($job['job'] ?? ''),
            'label'      => (string)($job['label'] ?? ''),
            'files'      => count($files),
            'bytes'      => (int)($job['bytes'] ?? 0),
            'done'       => !empty($job['done']),
            'added'      => (int)($prog['done_files'] ?? 0),
            'bytes_done' => (int)($prog['bytes_done'] ?? 0),
            'skipped'    => (int)($prog['skipped'] ?? 0),
            'zip_bytes'  => (int)($job['zip_bytes'] ?? 0),
            'created_at' => (int)($job['created_at'] ?? 0),
            'updated_at' => (int)($job['updated_at'] ?? 0),
            'expires_at' => (int)($job['created_at'] ?? 0) + EXPORT_JOB_MAX_AGE,
            'filename'   => (string)($job['filename'] ?? ''),
            'error'      => (string)($job['error'] ?? ''),
        ];
    }
}

if (!function_exists('exportListJobs')) {
    /** Summaries of this company's jobs (newest first), at most $cap sidecars read. */
    function exportListJobs(int $companyId, int $cap = 200): array {
        $dir = exportsDir(false);
        if ($dir === null || $companyId <= 0) return [];
        $dh = @opendir($dir);
        if ($dh === false) return [];
        $out = []; $seen = 0;
        while (($f = readdir($dh)) !== false && $seen < $cap) {
            if (!preg_match('/^([a-f0-9]{32})\.json$/', $f, $m)) continue;
            $seen++;
            $job = exportReadJob($m[1]);
            if ($job === null || (int)($job['company_id'] ?? 0) !== $companyId) continue;
            $out[] = exportJobSummary($job);
        }
        closedir($dh);
        usort($out, static function ($a, $b) { return $b['created_at'] <=> $a['created_at']; });
        return $out;
    }
}

// ---------------------------------------------------------------------
// Names, options, sizes
// ---------------------------------------------------------------------

if (!function_exists('exportSafeName')) {
    /**
     * A folder / file name that is safe on macOS, Windows and Linux and still reads like the
     * original: control characters dropped, \ / : * ? " < > | → '-', whitespace collapsed,
     * leading / trailing dots, spaces and dashes trimmed, ≤ $max characters; Windows device
     * names (CON, PRN, …) get a suffix. '' → $fallback.
     */
    function exportSafeName($s, string $fallback = 'item', int $max = 100): string {
        $s = (string)$s;
        $s = preg_replace('/[\x00-\x1F\x7F]+/', '', $s);
        $s = preg_replace('#[\\\\/:*?"<>|]+#', '-', (string)$s);
        $s = preg_replace('/\s+/u', ' ', (string)$s);
        $s = trim((string)$s, " .-");
        if ($s === '') return $fallback;
        if (function_exists('mb_substr') && mb_strlen($s, 'UTF-8') > $max) $s = rtrim(mb_substr($s, 0, $max, 'UTF-8'), " .-");
        elseif (strlen($s) > $max) $s = rtrim(substr($s, 0, $max), " .-");
        if ($s === '' || $s === '.' || $s === '..') return $fallback;
        if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $s)) $s .= '-';
        return $s;
    }
}

if (!function_exists('exportUniqueName')) {
    /** "<stem>.<ext>" unique within $used[$folder] (case-insensitive): stem, stem-2, stem-3 … */
    function exportUniqueName(array &$used, string $folder, string $stem, string $ext): string {
        $ext = strtolower(trim($ext, '.'));
        $stem = exportSafeName($stem, 'file', 120);
        $n = 1;
        do {
            $name = $stem . ($n > 1 ? '-' . $n : '') . ($ext !== '' ? '.' . $ext : '');
            $key = $folder . '/' . strtolower($name);
            $n++;
        } while (isset($used[$key]));
        $used[$key] = true;
        return $name;
    }
}

if (!function_exists('exportFormatBytes')) {
    function exportFormatBytes(int $bytes): string {
        if ($bytes >= 1024 * 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 2, '.', ''), '0'), '.') . ' GB';
        if ($bytes >= 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.') . ' MB';
        if ($bytes >= 1024) return (int)round($bytes / 1024) . ' KB';
        return $bytes . ' B';
    }
}

if (!function_exists('exportZipSupported')) {
    /** Offsets above 4 GB need 64-bit integers. EXPORT_QA_FORCE_MANIFEST=1 simulates the unsupported host in the harness. */
    function exportZipSupported(): bool {
        if (getenv('EXPORT_QA_FORCE_MANIFEST')) return false;
        return PHP_INT_SIZE >= 8;
    }
}

if (!function_exists('exportOptions')) {
    /** Normalise the posted form: scope all|tire|series, tire_id, series_id, photos, videos, reference, library (bools). */
    function exportOptions(array $raw): array {
        $scope = strtolower(trim((string)($raw['scope'] ?? 'all')));
        if (!in_array($scope, ['all', 'tire', 'series'], true)) $scope = 'all';
        $flag = static function ($v, bool $default): bool {
            if ($v === null) return $default;
            if (is_bool($v)) return $v;
            $v = strtolower(trim((string)$v));
            return in_array($v, ['1', 'true', 'on', 'yes'], true);
        };
        $tire   = max(0, (int)($raw['tire_id'] ?? ($raw['tire'] ?? 0)));
        $series = max(0, (int)($raw['series_id'] ?? ($raw['series'] ?? 0)));
        if ($scope === 'all') { $tire = 0; $series = 0; }
        if ($scope === 'tire') { $series = 0; }
        return [
            'scope'     => $scope,
            'tire_id'   => $tire,
            'series_id' => $series,
            'photos'    => $flag($raw['photos'] ?? null, true),
            'videos'    => $flag($raw['videos'] ?? null, false),
            'reference' => $flag($raw['reference'] ?? null, true),
            'library'   => $scope === 'all' && $flag($raw['library'] ?? null, true),
        ];
    }
}

if (!function_exists('exportOptionsLabel')) {
    /** "Klever R/T · Series 2 · photos + videos" — what the Recent exports list shows. */
    function exportOptionsLabel(array $opts, array $ctx = []): string {
        $parts = [];
        if ($opts['scope'] === 'all') $parts[] = 'All approved';
        else {
            $parts[] = (string)($ctx['tire_name'] ?? ('Tire #' . (int)$opts['tire_id']));
            if ($opts['scope'] === 'series') $parts[] = (string)($ctx['series_name'] ?? ('Series #' . (int)$opts['series_id']));
        }
        $inc = [];
        if (!empty($opts['photos'])) $inc[] = 'photos';
        if (!empty($opts['videos'])) $inc[] = 'videos';
        if ($opts['scope'] !== 'series' && !empty($opts['reference'])) $inc[] = 'reference';
        if (!empty($opts['library'])) $inc[] = 'library';
        $parts[] = $inc ? implode(' + ', $inc) : 'nothing';
        return implode(' · ', $parts);
    }
}

if (!function_exists('exportZipFilename')) {
    /** "<slug>-approved-assets-YYYY-MM-DD.zip" (tire / series scopes add their slug). */
    function exportZipFilename(array $client, array $opts = [], array $ctx = []): string {
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)($client['slug'] ?? 'client')));
        $slug = trim((string)$slug, '-') ?: 'client';
        $mid = '';
        if (($opts['scope'] ?? 'all') !== 'all' && isset($ctx['tire_name'])) {
            $mid = '-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$ctx['tire_name'])), '-');
            if (($opts['scope'] ?? '') === 'series' && isset($ctx['series_name'])) $mid .= '-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$ctx['series_name'])), '-');
        }
        return $slug . $mid . '-approved-assets-' . date('Y-m-d') . '.zip';
    }
}

if (!function_exists('exportLibraryPath')) {
    /** Absolute path of an approved library file: a plain name inside media/library/<slug>/ (realpath-contained), else null. */
    function exportLibraryPath(array $client, string $filename): ?string {
        $filename = trim($filename);
        if ($filename === '' || $filename !== basename($filename) || $filename[0] === '.' || strpos($filename, "\0") !== false) return null;
        if (!function_exists('libraryDir')) return null;
        $dir = libraryDir((string)($client['slug'] ?? ''));
        $path = $dir . '/' . $filename;
        if (!is_file($path) || is_link($path)) return null;
        $real = realpath($path); $dirReal = realpath($dir);
        if ($real === false || $dirReal === false) return $path;
        if (dirname($real) !== rtrim($dirReal, '/')) return null;
        return $real;
    }
}

if (!function_exists('exportResolvePath')) {
    /** Re-validate a manifest entry's source at step time through the containment helpers (never the stored path alone). */
    function exportResolvePath(array $file, array $client): ?string {
        $kind = (string)($file['kind'] ?? '');
        $src  = (string)($file['source_path'] ?? '');
        if ($kind === 'tire') {
            if (!function_exists('tireImagePath')) return null;
            return tireImagePath(['image_url' => $src]);
        }
        if ($kind === 'library') {
            return exportLibraryPath($client, basename($src));
        }
        return null;
    }
}

// ---------------------------------------------------------------------
// Enumeration
// ---------------------------------------------------------------------

if (!function_exists('exportEnumerate')) {
    /**
     * Every approved file the options select, with its zip path, in zip order (tires by name,
     * Reference before series, series in sort order, files in sort order; Library last).
     *   ['files' => [{id, kind, tire, series, folder, name, filename, media_type, bytes, status, approved_at,
     *                 comments_count, drive_url, source_path}], 'bytes', 'video_bytes',
     *    'counts' => {photos, videos, reference, series, library, missing}, 'folder' => <Client Name>,
     *    'tire_name', 'series_name', 'label', 'warnings' => [..]]
     * Throws InvalidArgumentException for a tire / series that is not this client's.
     */
    function exportEnumerate(PDO $pdo, array $client, array $opts): array {
        $cid = (int)($client['id'] ?? 0);
        $opts = exportOptions($opts);
        $res = ['files' => [], 'bytes' => 0, 'video_bytes' => 0, 'counts' => ['photos' => 0, 'videos' => 0, 'reference' => 0, 'series' => 0, 'library' => 0, 'missing' => 0],
                'folder' => exportSafeName((string)($client['name'] ?? ''), 'Client'), 'tire_name' => null, 'series_name' => null, 'label' => '', 'warnings' => [], 'options' => $opts];
        if ($cid <= 0) return $res;

        // Tires of this client (names → folders; a duplicate name gets " (<id>)").
        $tires = []; $tireFolders = []; $usedTireFolders = [];
        $st = $pdo->prepare("SELECT id, name FROM tires WHERE company_id = ? ORDER BY name ASC, id ASC");
        $st->execute([$cid]);
        foreach ((array)$st->fetchAll() as $t) {
            $tid = (int)$t['id'];
            $tires[$tid] = ['id' => $tid, 'name' => (string)$t['name']];
            $folder = exportSafeName((string)$t['name'], 'Tire ' . $tid);
            if (isset($usedTireFolders[strtolower($folder)])) $folder = exportSafeName($folder . ' (' . $tid . ')', 'Tire ' . $tid);
            $usedTireFolders[strtolower($folder)] = true;
            $tireFolders[$tid] = $folder;
        }
        if ($opts['scope'] !== 'all') {
            if (!isset($tires[$opts['tire_id']])) throw new InvalidArgumentException('That tire does not belong to ' . (string)($client['name'] ?? 'this client'));
            $res['tire_name'] = $tires[$opts['tire_id']]['name'];
        }

        $seriesOn = function_exists('hasTireSeries') && hasTireSeries($pdo);
        if ($opts['scope'] === 'series' && !$seriesOn) throw new InvalidArgumentException('Render series are not set up yet — run migrate.php.');

        // Series per tire: name, folder, drive_url (one lib call per tire that has approved rows — resolved lazily).
        $seriesByTire = [];
        $seriesFor = static function (int $tid) use (&$seriesByTire, $pdo, $seriesOn): array {
            if (isset($seriesByTire[$tid])) return $seriesByTire[$tid];
            $map = [];
            if ($seriesOn && function_exists('tireSeriesForTire')) {
                $used = ['reference' => true];
                foreach (tireSeriesForTire($pdo, $tid) as $sr) {
                    $folder = exportSafeName((string)$sr['name'], 'Series ' . (int)$sr['id']);
                    if (isset($used[strtolower($folder)])) $folder = exportSafeName($folder . ' (' . (int)$sr['id'] . ')', 'Series ' . (int)$sr['id']);
                    $used[strtolower($folder)] = true;
                    $map[(int)$sr['id']] = ['id' => (int)$sr['id'], 'name' => (string)$sr['name'], 'folder' => $folder, 'drive_url' => !empty($sr['drive_url']) ? (string)$sr['drive_url'] : ''];
                }
            }
            return $seriesByTire[$tid] = $map;
        };
        if ($opts['scope'] === 'series') {
            $sm = $seriesFor($opts['tire_id']);
            if (!isset($sm[$opts['series_id']])) throw new InvalidArgumentException('That series does not belong to the chosen tire');
            $res['series_name'] = $sm[$opts['series_id']]['name'];
        }
        $res['label'] = exportOptionsLabel($opts, ['tire_name' => $res['tire_name'], 'series_name' => $res['series_name']]);

        // Approved tire rows, scoped through the tires JOIN. Order = the zip order.
        $sql = "SELECT ti.*, t.name AS tire_name FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE t.company_id = ? AND ti.status = 'approved'";
        $params = [$cid];
        if ($opts['scope'] !== 'all') { $sql .= " AND ti.tire_id = ?"; $params[] = $opts['tire_id']; }
        if ($opts['scope'] === 'series') { $sql .= " AND ti.series_id = ?"; $params[] = $opts['series_id']; }
        $sql .= " ORDER BY t.name ASC, t.id ASC, ti.sort_order ASC, ti.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = (array)$st->fetchAll();

        // Library rows (scope all only).
        $libRows = [];
        if ($opts['library'] && function_exists('hasLibraryImagesTable') && hasLibraryImagesTable($pdo)) {
            $st = $pdo->prepare("SELECT id, filename, status, created_at, updated_at FROM library_images WHERE company_id = ? AND status = 'approved' ORDER BY filename ASC");
            $st->execute([$cid]);
            $libRows = (array)$st->fetchAll();
        }

        // approved_at = the latest 'approved' activity row per image (one grouped query), else updated_at, else created_at.
        $approvedAt = ['tire_image' => [], 'library_image' => []];
        $comments   = ['tire_image' => [], 'library_image' => []];
        if (($rows || $libRows) && function_exists('hasActivityLog') && hasActivityLog($pdo)) {
            try {
                $st = $pdo->prepare("SELECT entity_type, entity_id, MAX(created_at) AS at FROM activity_log WHERE company_id = ? AND action = 'approved' AND entity_type IN ('tire_image', 'library_image') GROUP BY entity_type, entity_id");
                $st->execute([$cid]);
                foreach ((array)$st->fetchAll() as $r) { $approvedAt[(string)$r['entity_type']][(int)$r['entity_id']] = (string)$r['at']; }
            } catch (Throwable $e) { /* fall back to updated_at */ }
            try {
                if ($rows && function_exists('commentCounts'))    $comments['tire_image']    = commentCounts($pdo, 'tire_image', array_map('intval', array_column($rows, 'id')));
                if ($libRows && function_exists('commentCounts')) $comments['library_image'] = commentCounts($pdo, 'library_image', array_map('intval', array_column($libRows, 'id')));
            } catch (Throwable $e) { /* counts stay 0 */ }
        }

        $used = [];   // "<folder>/<lowercase name>" → true (de-dup within a folder)
        $root = $res['folder'];
        // Group tire rows per tire → Reference first, then series in their sort order, then unknown series ids.
        $byTire = [];
        foreach ($rows as $r) { $byTire[(int)$r['tire_id']][] = $r; }
        foreach ($byTire as $tid => $trows) {
            if (!isset($tires[$tid])) continue;   // cannot happen (JOIN), belt and braces
            $tireFolder = $tireFolders[$tid];
            $series = $seriesFor($tid);
            $buckets = ['ref' => []];
            foreach ($series as $sid => $s) $buckets[$sid] = [];
            foreach ($trows as $r) {
                $sid = $seriesOn && isset($r['series_id']) && $r['series_id'] !== null ? (int)$r['series_id'] : 0;
                if ($sid <= 0) { if ($opts['scope'] === 'series') continue; $buckets['ref'][] = $r; }
                else { $buckets[$sid][] = $r; }
            }
            foreach ($buckets as $key => $brows) {
                if (!$brows) continue;
                $isRef = $key === 'ref';
                if ($isRef && !$opts['reference']) continue;
                if ($isRef) { $sub = 'Reference'; $sname = ''; $drive = ''; }
                elseif (isset($series[$key])) { $sub = $series[$key]['folder']; $sname = $series[$key]['name']; $drive = $series[$key]['drive_url']; }
                else { $sub = 'Series ' . (int)$key; $sname = $sub; $drive = ''; }
                $folder = $root . '/' . $tireFolder . '/' . $sub;
                foreach ($brows as $r) {
                    $url  = (string)($r['image_url'] ?? '');
                    $ext  = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                    $isV  = function_exists('isVideoExt') && isVideoExt($ext);
                    if ($isV && !$opts['videos']) continue;
                    if (!$isV && !$opts['photos']) continue;
                    $path = function_exists('tireImagePath') ? tireImagePath($r) : null;
                    if ($path === null) { $res['counts']['missing']++; continue; }
                    $bytes = (int)@filesize($path);
                    $stem  = trim((string)($r['display_name'] ?? ''));
                    if ($stem === '') $stem = pathinfo(basename($url), PATHINFO_FILENAME);
                    $name  = exportUniqueName($used, $folder, $stem, $ext);
                    $id    = (int)$r['id'];
                    $res['files'][] = [
                        'id' => $id, 'kind' => 'tire', 'tire' => $tires[$tid]['name'], 'series' => $isRef ? '' : $sname,
                        'folder' => $folder, 'name' => $folder . '/' . $name, 'filename' => $name,
                        'media_type' => $isV ? 'video' : 'image', 'bytes' => $bytes, 'status' => 'approved',
                        'approved_at' => (string)($approvedAt['tire_image'][$id] ?? ($r['updated_at'] ?? ($r['created_at'] ?? ''))),
                        'comments_count' => (int)($comments['tire_image'][$id] ?? 0), 'drive_url' => $drive,
                        'source_path' => ltrim($url, '/'),
                    ];
                    $res['bytes'] += $bytes;
                    if ($isV) { $res['video_bytes'] += $bytes; $res['counts']['videos']++; } else { $res['counts']['photos']++; }
                    if ($isRef) $res['counts']['reference']++; else $res['counts']['series']++;
                }
            }
        }
        foreach ($libRows as $r) {
            $file = (string)$r['filename'];
            $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $isV  = function_exists('isVideoExt') && isVideoExt($ext);
            if ($isV && !$opts['videos']) continue;
            if (!$isV && !$opts['photos']) continue;
            $path = exportLibraryPath($client, $file);
            if ($path === null) { $res['counts']['missing']++; continue; }
            $bytes = (int)@filesize($path);
            $folder = $root . '/Library';
            $name = exportUniqueName($used, $folder, pathinfo($file, PATHINFO_FILENAME), $ext);
            $id = (int)$r['id'];
            $res['files'][] = [
                'id' => $id, 'kind' => 'library', 'tire' => '', 'series' => '',
                'folder' => $folder, 'name' => $folder . '/' . $name, 'filename' => $name,
                'media_type' => $isV ? 'video' : 'image', 'bytes' => $bytes, 'status' => 'approved',
                'approved_at' => (string)($approvedAt['library_image'][$id] ?? ($r['updated_at'] ?? ($r['created_at'] ?? ''))),
                'comments_count' => (int)($comments['library_image'][$id] ?? 0), 'drive_url' => '',
                'source_path' => 'media/library/' . (string)($client['slug'] ?? '') . '/' . $file,
            ];
            $res['bytes'] += $bytes;
            if ($isV) { $res['video_bytes'] += $bytes; $res['counts']['videos']++; } else { $res['counts']['photos']++; }
            $res['counts']['library']++;
        }
        if ($res['counts']['missing'] > 0) $res['warnings'][] = $res['counts']['missing'] . ' approved file' . ($res['counts']['missing'] === 1 ? ' is' : 's are') . ' missing on disk and will be left out';
        if ($res['bytes'] > EXPORT_MAX_BYTES) $res['warnings'][] = 'Over the ' . exportFormatBytes(EXPORT_MAX_BYTES) . ' limit for one export — export one tire (or one series) at a time';
        return $res;
    }
}

// ---------------------------------------------------------------------
// Manifest
// ---------------------------------------------------------------------

if (!function_exists('exportManifestColumns')) {
    function exportManifestColumns(): array {
        return ['id', 'kind', 'tire', 'series', 'filename', 'media_type', 'bytes', 'status', 'approved_at', 'comments_count', 'drive_url', 'source_path'];
    }
}

if (!function_exists('exportManifestRow')) {
    /** One manifest row (in column order) for an enumerated file; files skipped at build time read status "missing". */
    function exportManifestRow(array $f): array {
        return [
            (int)($f['id'] ?? 0), (string)($f['kind'] ?? ''), (string)($f['tire'] ?? ''), (string)($f['series'] ?? ''),
            (string)($f['filename'] ?? ''), (string)($f['media_type'] ?? ''), (int)($f['bytes'] ?? 0),
            !empty($f['skipped']) ? 'missing' : (string)($f['status'] ?? 'approved'),
            (string)($f['approved_at'] ?? ''), (int)($f['comments_count'] ?? 0), (string)($f['drive_url'] ?? ''), (string)($f['source_path'] ?? ''),
        ];
    }
}

if (!function_exists('exportCsvRow')) {
    function exportCsvRow(array $cells): string {
        $out = [];
        foreach ($cells as $c) {
            $c = (string)$c;
            if ($c !== '' && strpbrk($c, "\",\r\n") !== false) $c = '"' . str_replace('"', '""', $c) . '"';
            $out[] = $c;
        }
        return implode(',', $out) . "\r\n";
    }
}

if (!function_exists('exportManifestCsv')) {
    /** UTF-8 BOM + header + one row per file (CRLF), for a job or an enumeration result. */
    function exportManifestCsv(array $job): string {
        $csv = "\xEF\xBB\xBF" . exportCsvRow(exportManifestColumns());
        foreach ((array)($job['files'] ?? []) as $f) $csv .= exportCsvRow(exportManifestRow($f));
        return $csv;
    }
}

if (!function_exists('exportManifestJson')) {
    function exportManifestJson(array $job): string {
        $files = [];
        foreach ((array)($job['files'] ?? []) as $f) {
            $files[] = array_combine(exportManifestColumns(), exportManifestRow($f)) + ['path' => (string)($f['name'] ?? '')];
        }
        $doc = [
            'client'       => (string)($job['company'] ?? ''),
            'client_slug'  => (string)($job['client'] ?? ''),
            'generated_at' => date('c', (int)($job['created_at'] ?? time())),
            'scope'        => (string)($job['label'] ?? ''),
            'options'      => $job['options'] ?? [],
            'files'        => count($files),
            'bytes'        => (int)($job['bytes'] ?? 0),
            'items'        => $files,
        ];
        return (string)json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

// ---------------------------------------------------------------------
// Zip writer (STORE, zip64 when needed, append-only)
// ---------------------------------------------------------------------

if (!function_exists('exportCrc32Combine')) {
    /** zlib's crc32_combine: the CRC of A.B from crc(A), crc(B) and strlen(B). O(log len2), integer math only. */
    function exportCrc32Combine(int $crc1, int $crc2, int $len2): int {
        if ($len2 <= 0) return $crc1;
        $times = static function (array $mat, int $vec): int {
            $sum = 0; $i = 0;
            while ($vec !== 0) { if ($vec & 1) $sum ^= $mat[$i]; $vec = ($vec >> 1) & 0x7FFFFFFF; $i++; }
            return $sum;
        };
        $square = static function (array $mat) use ($times): array {
            $sq = [];
            for ($n = 0; $n < 32; $n++) $sq[$n] = $times($mat, $mat[$n]);
            return $sq;
        };
        $odd = [0xEDB88320]; $row = 1;
        for ($n = 1; $n < 32; $n++) { $odd[$n] = $row; $row = ($row << 1) & 0xFFFFFFFF; }
        $even = $square($odd);
        $odd  = $square($even);
        $crc1 &= 0xFFFFFFFF; $crc2 &= 0xFFFFFFFF;
        do {
            $even = $square($odd);
            if ($len2 & 1) $crc1 = $times($even, $crc1);
            $len2 >>= 1;
            if ($len2 === 0) break;
            $odd = $square($even);
            if ($len2 & 1) $crc1 = $times($odd, $crc1);
            $len2 >>= 1;
        } while ($len2 !== 0);
        return ($crc1 ^ $crc2) & 0xFFFFFFFF;
    }
}

if (!function_exists('exportZipDosTime')) {
    /** [time, date] words for a unix timestamp (local time, 2-second resolution; the 1980 floor). */
    function exportZipDosTime(int $ts): array {
        $y = (int)date('Y', $ts); $mo = (int)date('n', $ts); $d = (int)date('j', $ts);
        $h = (int)date('G', $ts); $mi = (int)date('i', $ts); $s = (int)date('s', $ts);
        if ($y < 1980) { $y = 1980; $mo = 1; $d = 1; $h = 0; $mi = 0; $s = 0; }
        return [($h << 11) | ($mi << 5) | (int)($s / 2), (($y - 1980) << 9) | ($mo << 5) | $d];
    }
}

if (!function_exists('exportZipLocalHeader')) {
    /** Local file header (STORE, UTF-8 name); the crc slot (offset 14) is patched once the data is through. */
    function exportZipLocalHeader(string $name, int $size, int $crc, int $mtime): string {
        [$t, $d] = exportZipDosTime($mtime);
        $z64 = $size >= 0xFFFFFFFF;
        $extra = $z64 ? pack('vvPP', 0x0001, 16, $size, $size) : '';
        return pack('VvvvvvVVVvv', 0x04034B50, $z64 ? 45 : 20, 0x0800, 0, $t, $d, $crc, $z64 ? 0xFFFFFFFF : $size, $z64 ? 0xFFFFFFFF : $size, strlen($name), strlen($extra))
             . $name . $extra;
    }
}

if (!function_exists('exportZipCentralEntry')) {
    function exportZipCentralEntry(string $name, int $size, int $crc, int $mtime, int $offset): string {
        [$t, $d] = exportZipDosTime($mtime);
        $zSize = $size >= 0xFFFFFFFF; $zOff = $offset >= 0xFFFFFFFF;
        $extra = '';
        if ($zSize || $zOff) {
            $body = '';
            if ($zSize) $body .= pack('PP', $size, $size);
            if ($zOff)  $body .= pack('P', $offset);
            $extra = pack('vv', 0x0001, strlen($body)) . $body;
        }
        return pack('VvvvvvvVVVvvvvvVV', 0x02014B50, ($zSize || $zOff) ? 45 : 20, ($zSize || $zOff) ? 45 : 20, 0x0800, 0, $t, $d, $crc,
                    $zSize ? 0xFFFFFFFF : $size, $zSize ? 0xFFFFFFFF : $size, strlen($name), strlen($extra), 0, 0, 0, 0, $zOff ? 0xFFFFFFFF : $offset)
             . $name . $extra;
    }
}

if (!function_exists('exportZipEnd')) {
    /** Central directory + (zip64 EOCD + locator when needed) + EOCD for the finished entries. */
    function exportZipEnd(array $entries, int $cdOffset): string {
        $cd = ''; $z64 = false;
        foreach ($entries as $e) {
            $cd .= exportZipCentralEntry((string)$e['name'], (int)$e['size'], (int)$e['crc'], (int)$e['mtime'], (int)$e['offset']);
            if ((int)$e['size'] >= 0xFFFFFFFF || (int)$e['offset'] >= 0xFFFFFFFF) $z64 = true;
        }
        $n = count($entries); $cdSize = strlen($cd);
        if ($n >= 0xFFFF || $cdSize >= 0xFFFFFFFF || $cdOffset >= 0xFFFFFFFF) $z64 = true;
        $out = $cd;
        if ($z64) {
            $z64At = $cdOffset + $cdSize;
            $out .= pack('VPvvVVPPPP', 0x06064B50, 44, 45, 45, 0, 0, $n, $n, $cdSize, $cdOffset);   // zip64 end of central directory record
            $out .= pack('VVPV', 0x07064B50, 0, $z64At, 1);                                        // zip64 EOCD locator
        }
        $out .= pack('VvvvvVVv', 0x06054B50, 0, 0, min($n, 0xFFFF), min($n, 0xFFFF), min($cdSize, 0xFFFFFFFF), min($cdOffset, 0xFFFFFFFF), 0);
        return $out;
    }
}

if (!function_exists('exportZipAddString')) {
    /** Append a whole in-memory entry (the manifests); returns the central-directory record data. */
    function exportZipAddString($fh, int &$pos, string $name, string $data, int $mtime): array {
        $crc = crc32($data) & 0xFFFFFFFF;
        $hdr = exportZipLocalHeader($name, strlen($data), $crc, $mtime);
        $at = $pos;
        fwrite($fh, $hdr); fwrite($fh, $data);
        $pos += strlen($hdr) + strlen($data);
        return ['name' => $name, 'size' => strlen($data), 'crc' => $crc, 'mtime' => $mtime, 'offset' => $at];
    }
}

if (!function_exists('exportStartJob')) {
    /**
     * Enumerate and write the sidecar. Returns the job (with 'job' id) or throws
     * InvalidArgumentException (bad scope / nothing to export / over the cap) or RuntimeException (folder not writable).
     */
    function exportStartJob(PDO $pdo, array $client, array $rawOpts): array {
        exportCleanup();
        $enum = exportEnumerate($pdo, $client, $rawOpts);
        if (!$enum['files']) throw new InvalidArgumentException('Nothing to export — no approved files match that selection');
        if ($enum['bytes'] > EXPORT_MAX_BYTES) throw new InvalidArgumentException('That export would be ' . exportFormatBytes($enum['bytes']) . ' — the limit for one zip is ' . exportFormatBytes(EXPORT_MAX_BYTES) . '. Export one tire (or one series) at a time.');
        $dir = exportsDir(true);
        if ($dir === null) throw new RuntimeException('uploads/.exports is not writable on this server');
        for ($try = 0; $try < 5; $try++) {
            $id = bin2hex(random_bytes(16));
            if (is_file($dir . '/' . $id . '.json') || is_file($dir . '/' . $id . '.zip')) continue;
            $job = [
                'job'        => $id,
                'company_id' => (int)$client['id'],
                'client'     => (string)($client['slug'] ?? ''),
                'company'    => (string)($client['name'] ?? ''),
                'folder'     => $enum['folder'],
                'options'    => $enum['options'],
                'label'      => $enum['label'],
                'filename'   => exportZipFilename($client, $enum['options'], ['tire_name' => $enum['tire_name'], 'series_name' => $enum['series_name']]),
                'created_at' => time(),
                'files'      => $enum['files'],
                'bytes'      => $enum['bytes'],
                'video_bytes' => $enum['video_bytes'],
                'counts'     => $enum['counts'],
                'warnings'   => $enum['warnings'],
                'progress'   => ['index' => 0, 'offset' => 0, 'crc' => 0, 'pos' => 0, 'done_files' => 0, 'bytes_done' => 0, 'skipped' => 0],
                'entries'    => [],
                'done'       => false,
                'zip_bytes'  => 0,
                'error'      => '',
            ];
            if (!exportSaveJob($job)) throw new RuntimeException('Could not write the export job');
            return $job;
        }
        throw new RuntimeException('Could not allocate an export job id');
    }
}

if (!function_exists('exportStep')) {
    /**
     * Append the next budget of bytes to the job's zip. Returns
     *   ['ok' => true, 'done' => bool, 'added' => files finished, 'remaining' => files left, 'bytes_done', 'bytes', 'files', 'zip_bytes', 'skipped']
     *   or ['ok' => false, 'code' => 409|500, 'error' => …] (busy = another step is running; unwritable).
     * A step that died halfway is repaired here: the zip is truncated back to the recorded position and
     * the interrupted range is redone, so the archive on disk is always consistent with the sidecar.
     */
    function exportStep(array $job, array $client, array $budget = []): array {
        $p = exportJobPaths($job['job'] ?? null, true);
        if ($p === null) return ['ok' => false, 'code' => 500, 'error' => 'Export folder is not writable'];
        if (!empty($job['done'])) return ['ok' => true] + exportStepReply($job);
        $maxBytes = (int)($budget['bytes'] ?? EXPORT_STEP_BYTES);
        $maxSecs  = (float)($budget['seconds'] ?? EXPORT_STEP_SECONDS);
        $lock = @fopen($p['meta'], 'rb');
        if ($lock === false) return ['ok' => false, 'code' => 500, 'error' => 'Export job is unreadable'];
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['ok' => false, 'code' => 409, 'error' => 'This export is already being built — wait for the running step']; }
        $release = static function () use ($lock) { flock($lock, LOCK_UN); fclose($lock); };
        @set_time_limit((int)max(60, $maxSecs * 4));
        $zip = @fopen($p['zip'], 'c+b');
        if ($zip === false) { $release(); return ['ok' => false, 'code' => 500, 'error' => 'Could not open the zip for writing']; }
        $prog = $job['progress'];
        $pos  = (int)$prog['pos'];
        clearstatcache(true, $p['zip']);
        $have = (int)@filesize($p['zip']);
        if ($have > $pos) { ftruncate($zip, $pos); }                                   // a step died after writing: drop the half range
        elseif ($have < $pos) { $release(); fclose($zip); $job['error'] = 'The zip on disk is shorter than recorded — build it again'; exportSaveJob($job); return ['ok' => false, 'code' => 500, 'error' => $job['error']]; }
        fseek($zip, $pos);
        $files = &$job['files'];
        $count = count($files);
        $t0 = microtime(true); $spent = 0;
        $i = (int)$prog['index']; $offset = (int)$prog['offset']; $crc = (int)$prog['crc'];
        while ($i < $count) {
            $f = &$files[$i];
            if ($offset === 0) {                                                       // starting this file: re-validate + header
                $path = exportResolvePath($f, $client);
                $size = $path !== null ? (int)@filesize($path) : -1;
                if ($path === null || $size < 0) { $f['skipped'] = true; $f['bytes'] = 0; $prog['skipped']++; $i++; unset($f); continue; }
                $f['bytes'] = $size;                                                    // the size now, not at enumeration
                $f['mtime'] = (int)@filemtime($path) ?: time();
                $hdr = exportZipLocalHeader((string)$f['name'], $size, 0, (int)$f['mtime']);
                fwrite($zip, $hdr);
                $f['at'] = $pos; $pos += strlen($hdr); $crc = 0;
                if ($size === 0) { $f['crc'] = 0; $f['ok'] = true; $job['entries'][] = ['name' => $f['name'], 'size' => 0, 'crc' => 0, 'mtime' => $f['mtime'], 'offset' => $f['at']]; $prog['done_files']++; $i++; unset($f); continue; }
            } else {
                $path = exportResolvePath($f, $client);
                if ($path === null) {                                                   // vanished mid-file: pad the entry so the archive stays valid, mark it missing
                    $size = (int)$f['bytes'];
                    $rest = $size - $offset;
                    while ($rest > 0) { $n = min($rest, EXPORT_IO_CHUNK); fwrite($zip, str_repeat("\0", $n)); $rangeCrc = crc32(str_repeat("\0", $n)); $crc = exportCrc32Combine($crc, $rangeCrc, $n); $rest -= $n; $pos += $n; }
                    exportZipPatchCrc($zip, (int)$f['at'], $crc);
                    $f['crc'] = $crc; $f['ok'] = true; $f['skipped'] = true; $prog['skipped']++;
                    $job['entries'][] = ['name' => $f['name'], 'size' => $size, 'crc' => $crc, 'mtime' => (int)$f['mtime'], 'offset' => (int)$f['at']];
                    $prog['bytes_done'] += $size - $offset; $i++; $offset = 0; unset($f); continue;
                }
                $size = (int)$f['bytes'];
            }
            $in = @fopen($path, 'rb');
            if ($in === false) { $release(); fclose($zip); $job['progress'] = ['index' => $i, 'offset' => $offset, 'crc' => $crc, 'pos' => $pos] + $prog; exportSaveJob($job); return ['ok' => false, 'code' => 500, 'error' => 'Could not read ' . basename((string)$f['name'])]; }
            if ($offset > 0) fseek($in, $offset);
            $ctx = hash_init('crc32b'); $rangeLen = 0;
            while ($offset < $size) {
                $want = (int)min(EXPORT_IO_CHUNK, $size - $offset);
                $buf = fread($in, $want);
                if ($buf === false || $buf === '') { break; }
                $len = strlen($buf);
                if ($len > $want) { $buf = substr($buf, 0, $want); $len = $want; }
                if (fwrite($zip, $buf) !== $len) { fclose($in); $release(); fclose($zip); $job['progress'] = ['index' => $i, 'offset' => $offset, 'crc' => $crc, 'pos' => $pos] + $prog; exportSaveJob($job); return ['ok' => false, 'code' => 500, 'error' => 'Short write — the disk may be full']; }
                hash_update($ctx, $buf);
                $offset += $len; $rangeLen += $len; $pos += $len; $spent += $len; $prog['bytes_done'] += $len;
                if ($spent >= $maxBytes || (microtime(true) - $t0) >= $maxSecs) break;
            }
            fclose($in);
            $crc = exportCrc32Combine($crc, (int)hexdec(hash_final($ctx)), $rangeLen);
            if ($offset < $size) {
                if ($spent >= $maxBytes || (microtime(true) - $t0) >= $maxSecs) break;      // budget: resume this file next step
                // the file shrank while we were reading it: finish the entry with zero padding, mark it missing
                $rest = $size - $offset;
                while ($rest > 0) { $n = min($rest, EXPORT_IO_CHUNK); $pad = str_repeat("\0", $n); fwrite($zip, $pad); $crc = exportCrc32Combine($crc, crc32($pad), $n); $rest -= $n; $pos += $n; $prog['bytes_done'] += $n; }
                $offset = $size; $f['skipped'] = true; $prog['skipped']++;
            }
            exportZipPatchCrc($zip, (int)$f['at'], $crc);
            $f['crc'] = $crc; $f['ok'] = true;
            $job['entries'][] = ['name' => $f['name'], 'size' => $size, 'crc' => $crc, 'mtime' => (int)$f['mtime'], 'offset' => (int)$f['at']];
            $prog['done_files']++; $i++; $offset = 0; $crc = 0;
            unset($f);
            if ($spent >= $maxBytes || (microtime(true) - $t0) >= $maxSecs) break;
        }
        unset($files);
        if ($i >= $count) {                                                            // every file is in: manifests + central directory
            fseek($zip, $pos);
            $now = time();
            $job['entries'][] = exportZipAddString($zip, $pos, $job['folder'] . '/manifest.csv', exportManifestCsv($job), $now);
            $job['entries'][] = exportZipAddString($zip, $pos, $job['folder'] . '/manifest.json', exportManifestJson($job), $now);
            fwrite($zip, exportZipEnd($job['entries'], $pos));
            fflush($zip);
            clearstatcache(true, $p['zip']);
            $job['done'] = true; $job['finished_at'] = $now; $job['zip_bytes'] = (int)@filesize($p['zip']);
            @chmod($p['zip'], 0600);
        }
        fflush($zip); fclose($zip);
        $job['progress'] = ['index' => $i, 'offset' => $offset, 'crc' => $crc, 'pos' => $pos] + $prog;
        $saved = exportSaveJob($job);
        $release();
        if (!$saved) return ['ok' => false, 'code' => 500, 'error' => 'Could not save the export progress'];
        return ['ok' => true] + exportStepReply($job);
    }
}

if (!function_exists('exportZipPatchCrc')) {
    /** Write the CRC into a local header written earlier (offset 14) and return to the end. */
    function exportZipPatchCrc($zip, int $headerAt, int $crc): void {
        $end = ftell($zip);
        fseek($zip, $headerAt + 14);
        fwrite($zip, pack('V', $crc & 0xFFFFFFFF));
        fseek($zip, $end);
    }
}

if (!function_exists('exportStepReply')) {
    function exportStepReply(array $job): array {
        $s = exportJobSummary($job);
        return ['done' => $s['done'], 'added' => $s['added'], 'remaining' => max(0, $s['files'] - $s['added'] - $s['skipped']), 'skipped' => $s['skipped'],
                'bytes_done' => $s['bytes_done'], 'bytes' => $s['bytes'], 'files' => $s['files'], 'zip_bytes' => $s['zip_bytes'], 'filename' => $s['filename'], 'job' => $s['job']];
    }
}
