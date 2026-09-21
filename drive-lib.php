<?php
/**
 * Google Drive storage view — shared helpers (loaded from the end of helpers.php; never include directly).
 *
 * The agency's Google Drive is measured once a night by an Apps Script (docs/drive-collector/)
 * that POSTs a snapshot to drive-ingest.php in parts. The portal never talks to Google itself:
 * drive.php reads only what these helpers return from the five drive_* tables (migrate.php 30–34).
 *
 * Who computes what (contract in scratchpad drive-design.md §0):
 *   script  quota numbers (Drive.About), folder rollups (bytes / staleBytes / fileCount / lastActivity),
 *           folder → client assignment, the client rollup and the trimmed tree — it is the only side
 *           that sees every file.
 *   server  (drive-ingest.php ?part=finish, using the pure helpers below) type buckets, idle days,
 *           score, candidates, by-type totals, duplicate + old-version groups, burn rate / days-to-full
 *           from the snapshot history, per-client growth, which threshold alerts are due, retention.
 *   The script streams every owned, non-trashed, non-folder file that uses quota (quotaBytesUsed > 0),
 *   so the server's recomputation covers the whole account; drive_files is pruned at finish to the
 *   offboard candidates + the 1,000 largest files + the 50 largest per client.
 *
 * Definitions (spec): idleDays = days since the later of modifiedTime / viewedByMeTime · stale = idle ≥ 180 d ·
 * candidate = owned, ≥ 100 MB, idle ≥ 180 d · raw = bytes × min(idle, 730) · score = round(100·raw / maxRaw) ·
 * burn rate = (usage now − usage ≥ 30 days ago) / days (else the oldest snapshot, labelled) ·
 * daysToFull = free / burn ("not growing" when ≤ 0) · duplicates = same md5 + size, count > 1, native Docs skipped ·
 * old versions = ^(.*?)[ _-]v(\d+)(\.[^.]+)$ per folder, every version but the highest reclaimable.
 *
 * All functions are function_exists-guarded and do no work at load. $pdo arguments that default to
 * null fall back to the global $pdo. Every read is gated on hasDriveTables().
 */

if (!defined('DRIVE_STALE_DAYS'))            define('DRIVE_STALE_DAYS', 180);              // idle days after which a file / folder bytes count as stale
if (!defined('DRIVE_CANDIDATE_MIN_BYTES'))   define('DRIVE_CANDIDATE_MIN_BYTES', 100 * 1024 * 1024);   // 100 MB
if (!defined('DRIVE_IDLE_CAP_DAYS'))         define('DRIVE_IDLE_CAP_DAYS', 730);            // idle days are capped here for the score
if (!defined('DRIVE_HISTORY_BASIS_DAYS'))    define('DRIVE_HISTORY_BASIS_DAYS', 30);        // burn rate looks this far back when it can
if (!defined('DRIVE_STALE_SNAPSHOT_HOURS'))  define('DRIVE_STALE_SNAPSHOT_HOURS', 48);      // older than this = "the nightly check may have failed"
if (!defined('DRIVE_KEEP_SNAPSHOTS'))        define('DRIVE_KEEP_SNAPSHOTS', 7);             // complete snapshots that keep folder / file detail
if (!defined('DRIVE_KEEP_TOP_FILES'))        define('DRIVE_KEEP_TOP_FILES', 1000);          // largest files kept per snapshot (plus every candidate)
if (!defined('DRIVE_KEEP_PER_CLIENT'))       define('DRIVE_KEEP_PER_CLIENT', 50);           // largest files kept per client per snapshot
if (!defined('DRIVE_KEEP_QUICK_WIN_GROUPS')) define('DRIVE_KEEP_QUICK_WIN_GROUPS', 200);    // duplicate / old-version groups stored per kind
if (!defined('DRIVE_MAX_BODY'))              define('DRIVE_MAX_BODY', 8 * 1024 * 1024);     // ingest JSON body cap (413 above)
if (!defined('DRIVE_ALERT_DAYS'))            define('DRIVE_ALERT_DAYS', 14);                // "days14" alert when daysToFull < this

if (!function_exists('drivePdo')) {
    /** (internal) Resolve the PDO to use: the argument, else the global. */
    function drivePdo(?PDO $pdo = null): ?PDO {
        if ($pdo instanceof PDO) return $pdo;
        $g = $GLOBALS['pdo'] ?? null;
        return $g instanceof PDO ? $g : null;
    }
}

if (!function_exists('driveTables')) {
    /** The five tables migrate.php 30–34 create. */
    function driveTables(): array {
        return ['drive_snapshots', 'drive_folders', 'drive_files', 'drive_quick_wins', 'drive_alerts'];
    }
}

if (!function_exists('hasDriveTables')) {
    /** Do all drive_* tables exist yet? (migrate.php may not have run.) Cached per request. */
    function hasDriveTables(?PDO $pdo = null): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        $pdo = drivePdo($pdo);
        if ($pdo === null) return false;   // not cached: a later call may have a PDO
        try {
            foreach (driveTables() as $t) {
                // Literal table name on purpose: the QA fakes answer INFORMATION_SCHEMA probes by regex on the SQL text.
                $s = $pdo->prepare("
                    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'
                ");
                $s->execute();
                if ((int)$s->fetchColumn() < 1) return $cached = false;
            }
            return $cached = true;
        } catch (Throwable $e) {
            return $cached = false;
        }
    }
}

if (!function_exists('driveConfig')) {
    /**
     * Portal-side settings from config.php: drive_ingest_secret (the bearer the collector sends; ≥ 24
     * chars or the endpoint answers 503 "not configured") and the optional drive_clients_root_folder_id
     * (documentation only — the script owns CLIENTS_ROOT_FOLDER_ID; drive.php may show it as a hint).
     */
    function driveConfig(): array {
        static $cfg = null;
        if ($cfg !== null) return $cfg;
        $c = $GLOBALS['config'] ?? null;
        if (!is_array($c)) {
            $file = __DIR__ . '/config.php';
            $c = is_file($file) ? (require $file) : [];
            if (!is_array($c)) $c = [];
        }
        $secret = trim((string)($c['drive_ingest_secret'] ?? ''));
        return $cfg = [
            'ingest_secret'          => $secret,
            'clients_root_folder_id' => trim((string)($c['drive_clients_root_folder_id'] ?? '')),
            'configured'             => strlen($secret) >= 24,
        ];
    }
}

// ---------------------------------------------------------------------------------------------
// Pure helpers (no DB) — used by the ingest endpoint and by drive.php
// ---------------------------------------------------------------------------------------------

if (!function_exists('driveFormatBytes')) {
    /** '412.5 GB' / '1.9 TB' / '73 MB': binary units like Google's storage page; one decimal under 100 GB, none above. */
    function driveFormatBytes($bytes, ?int $decimals = null): string {
        $b = max(0.0, (float)$bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($b >= 1024 && $i < count($units) - 1) { $b /= 1024; $i++; }
        if ($decimals === null) {
            $decimals = ($units[$i] === 'TB' || ($units[$i] === 'GB' && $b < 100)) ? 1 : 0;
        }
        $s = number_format($b, $decimals, '.', ',');
        if ($decimals > 0) $s = rtrim(rtrim($s, '0'), '.');
        return $s . ' ' . $units[$i];
    }
}

if (!function_exists('drivePercent')) {
    /** Share of $whole in percent with one decimal (0.0 when $whole is 0). */
    function drivePercent($part, $whole): float {
        $w = (float)$whole;
        if ($w <= 0) return 0.0;
        return round(100 * (float)$part / $w, 1);
    }
}

if (!function_exists('driveTypeBucket')) {
    /** Video | Design | Images | Archives | Other — the extension decides first, then the MIME type. */
    function driveTypeBucket(string $mime, string $name): string {
        static $ext = null;
        if ($ext === null) {
            $ext = [];
            foreach (['mp4', 'mov', 'm4v', 'mkv', 'avi', 'webm', 'wmv', 'flv', 'mxf', 'mts', 'm2ts', 'mpg', 'mpeg', '3gp', 'r3d', 'braw', 'prores'] as $e) $ext[$e] = 'Video';
            foreach (['psd', 'psb', 'ai', 'indd', 'idml', 'fig', 'sketch', 'afdesign', 'afphoto', 'afpub', 'xd', 'eps', 'aep', 'aet', 'prproj', 'drp', 'blend', 'c4d', 'cdr', 'procreate', 'clip', 'kra', 'xcf'] as $e) $ext[$e] = 'Design';
            foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp', 'svg', 'avif', 'raw', 'cr2', 'cr3', 'nef', 'arw', 'dng', 'orf', 'raf', 'rw2'] as $e) $ext[$e] = 'Images';
            foreach (['zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'bz2', 'xz', 'dmg', 'iso', 'zst'] as $e) $ext[$e] = 'Archives';
        }
        $e = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if ($e !== '' && isset($ext[$e])) return $ext[$e];
        $m = strtolower(trim($mime));
        if ($m === 'image/vnd.adobe.photoshop' || $m === 'application/illustrator' || $m === 'application/postscript'
            || $m === 'application/x-indesign' || $m === 'application/x-photoshop' || $m === 'application/photoshop') return 'Design';
        if (strpos($m, 'video/') === 0) return 'Video';
        if (strpos($m, 'image/') === 0) return 'Images';
        if (in_array($m, ['application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed', 'application/vnd.rar',
                          'application/x-7z-compressed', 'application/gzip', 'application/x-gzip', 'application/x-tar',
                          'application/x-bzip2', 'application/x-xz', 'application/x-apple-diskimage'], true)) return 'Archives';
        return 'Other';
    }
}

if (!function_exists('driveTypeBuckets')) {
    function driveTypeBuckets(): array { return ['Video', 'Design', 'Images', 'Archives', 'Other']; }
}

if (!function_exists('driveParseDate')) {
    /** ISO-8601 ('2026-09-21T02:10:00.000Z', with offset) or 'Y-m-d H:i:s' (local) → 'Y-m-d H:i:s' in the portal timezone; null when unparseable. */
    function driveParseDate($value): ?string {
        if (!is_string($value)) return null;
        $v = trim($value);
        if ($v === '' || $v === '0000-00-00 00:00:00') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?)?(Z|[+-]\d{2}:?\d{2})?$/', $v)) return null;
        try {
            $d = new DateTimeImmutable($v);
        } catch (Throwable $e) {
            return null;
        }
        return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}

if (!function_exists('driveIdleDays')) {
    /** Whole days since the later of modified / viewed (never negative; 0 when both are missing). */
    function driveIdleDays(?string $modifiedAt, ?string $viewedAt, int $nowTs): int {
        $ts = 0;
        foreach ([$modifiedAt, $viewedAt] as $d) {
            if ($d === null || $d === '') continue;
            $t = strtotime($d);
            if ($t !== false && $t > $ts) $ts = $t;
        }
        if ($ts === 0) return 0;
        return max(0, (int)floor(($nowTs - $ts) / 86400));
    }
}

if (!function_exists('driveRawScore')) {
    /** bytes × min(idleDays, DRIVE_IDLE_CAP_DAYS). */
    function driveRawScore(int $bytes, int $idleDays): int {
        return max(0, $bytes) * max(0, min($idleDays, DRIVE_IDLE_CAP_DAYS));
    }
}

if (!function_exists('driveScore')) {
    /** round(100 · raw / maxRaw), 0 when there is no maximum. */
    function driveScore(int $raw, int $maxRaw): int {
        if ($maxRaw <= 0 || $raw <= 0) return 0;
        return (int)max(0, min(100, round(100 * $raw / $maxRaw)));
    }
}

if (!function_exists('driveIsCandidate')) {
    /** Offboard candidate: ≥ 100 MB and idle for ≥ 180 days (ownership is guaranteed by the collector's query). */
    function driveIsCandidate(int $bytes, int $idleDays): bool {
        return $bytes >= DRIVE_CANDIDATE_MIN_BYTES && $idleDays >= DRIVE_STALE_DAYS;
    }
}

if (!function_exists('driveIsStale')) {
    function driveIsStale(int $idleDays): bool { return $idleDays >= DRIVE_STALE_DAYS; }
}

if (!function_exists('driveParentLink')) {
    /** https://drive.google.com/drive/folders/<parentId> (null without a parent). */
    function driveParentLink(?string $parentId): ?string {
        $p = trim((string)$parentId);
        if ($p === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $p)) return null;
        return 'https://drive.google.com/drive/folders/' . $p;
    }
}

if (!function_exists('driveRelativeAge')) {
    /** relativeTime() wrapper that never returns '' for a real date ('' only for null). */
    function driveRelativeAge(?string $datetime): string {
        if ($datetime === null || $datetime === '') return '';
        return function_exists('relativeTime') ? (string)relativeTime($datetime) : (string)$datetime;
    }
}

if (!function_exists('driveIsNativeMime')) {
    /** Google-native (Docs / Sheets / Slides / shortcuts …) — never a duplicate, never sized. */
    function driveIsNativeMime(string $mime): bool {
        return strpos(strtolower($mime), 'application/vnd.google-apps.') === 0;
    }
}

if (!function_exists('driveComputeDuplicateGroups')) {
    /**
     * Exact duplicates from file rows [{id,name,path,parentId,clientSlug,bytes,md5,mimeType,webLink}, …]:
     * same md5 + size, count > 1, native Docs skipped. reclaimableBytes = (count − 1) × size. Reclaimable desc.
     */
    function driveComputeDuplicateGroups(array $files): array {
        $groups = [];
        foreach ($files as $f) {
            $md5 = strtolower(trim((string)($f['md5'] ?? '')));
            $bytes = (int)($f['bytes'] ?? 0);
            if ($md5 === '' || $bytes <= 0 || driveIsNativeMime((string)($f['mimeType'] ?? ''))) continue;
            $key = $md5 . ':' . $bytes;
            if (!isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'name' => (string)($f['name'] ?? ''), 'path' => $f['path'] ?? null,
                                 'clientSlug' => $f['clientSlug'] ?? null, 'fileCount' => 0, 'bytes' => 0, 'reclaimableBytes' => 0, 'files' => []];
            }
            $groups[$key]['files'][] = driveGroupMember($f);
            $groups[$key]['fileCount']++;
            $groups[$key]['bytes'] += $bytes;
        }
        $out = [];
        foreach ($groups as $g) {
            if ($g['fileCount'] < 2) continue;
            $g['reclaimableBytes'] = ($g['fileCount'] - 1) * (int)$g['files'][0]['bytes'];
            $out[] = $g;
        }
        usort($out, fn($a, $b) => $b['reclaimableBytes'] <=> $a['reclaimableBytes']);
        return $out;
    }
}

if (!function_exists('driveGroupMember')) {
    /** (internal) The per-file shape stored inside a quick-win group. */
    function driveGroupMember(array $f): array {
        $parent = isset($f['parentId']) && $f['parentId'] !== '' ? (string)$f['parentId'] : null;
        return [
            'id'         => (string)($f['id'] ?? ''),
            'name'       => (string)($f['name'] ?? ''),
            'path'       => isset($f['path']) ? (string)$f['path'] : null,
            'clientSlug' => isset($f['clientSlug']) ? $f['clientSlug'] : null,
            'bytes'      => (int)($f['bytes'] ?? 0),
            'modifiedAt' => $f['modifiedAt'] ?? null,
            'webLink'    => isset($f['webLink']) && $f['webLink'] !== '' ? (string)$f['webLink'] : null,
            'parentId'   => $parent,
            'parentLink' => driveParentLink($parent),
        ];
    }
}

if (!function_exists('driveOldVersionMatch')) {
    /** "report_v3.mp4" → ['stem' => 'report', 'version' => 3, 'ext' => '.mp4'] or null. Case-insensitive. */
    function driveOldVersionMatch(string $name): ?array {
        if (!preg_match('/^(.*?)[ _-]v(\d+)(\.[^.]+)$/i', $name, $m)) return null;
        if (trim($m[1]) === '') return null;
        return ['stem' => $m[1], 'version' => (int)$m[2], 'ext' => $m[3]];
    }
}

if (!function_exists('driveComputeOldVersionGroups')) {
    /**
     * Old versions from file rows (same keys as driveComputeDuplicateGroups): files in one parent folder
     * whose names differ only by the _vN suffix (^(.*?)[ _-]v(\d+)(\.[^.]+)$, case-insensitive). Every
     * version but the highest is reclaimable; members carry 'version' and 'keep'. Reclaimable desc.
     */
    function driveComputeOldVersionGroups(array $files): array {
        $groups = [];
        foreach ($files as $f) {
            $m = driveOldVersionMatch((string)($f['name'] ?? ''));
            if ($m === null) continue;
            $key = (string)($f['parentId'] ?? '') . "\0" . strtolower(trim($m['stem'])) . "\0" . strtolower($m['ext']);
            if (!isset($groups[$key])) {
                $groups[$key] = ['key' => substr(sha1($key), 0, 40), 'name' => trim($m['stem']) . $m['ext'], 'path' => null,
                                 'clientSlug' => $f['clientSlug'] ?? null, 'fileCount' => 0, 'bytes' => 0, 'reclaimableBytes' => 0, 'files' => []];
            }
            $member = driveGroupMember($f) + ['version' => $m['version'], 'keep' => false];
            $groups[$key]['files'][] = $member;
        }
        $out = [];
        foreach ($groups as $g) {
            $versions = [];
            foreach ($g['files'] as $f) $versions[$f['version']] = true;
            if (count($versions) < 2) continue;
            usort($g['files'], fn($a, $b) => $b['version'] <=> $a['version']);
            $g['files'][0]['keep'] = true;
            $keepPath = $g['files'][0]['path'];
            $g['path'] = $keepPath !== null ? (string)preg_replace('#/[^/]*$#', '', $keepPath) : null;
            $g['fileCount'] = count($g['files']);
            foreach ($g['files'] as $f) {
                $g['bytes'] += (int)$f['bytes'];
                if (!$f['keep']) $g['reclaimableBytes'] += (int)$f['bytes'];
            }
            $out[] = $g;
        }
        usort($out, fn($a, $b) => $b['reclaimableBytes'] <=> $a['reclaimableBytes']);
        return $out;
    }
}

if (!function_exists('driveSlugify')) {
    /** Folder name → client slug ([a-z0-9-], ≤ 120 chars); '' when nothing survives. Mirrors the collector's slugify(). */
    function driveSlugify(string $name): string {
        $s = strtolower(trim($name));
        if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if (is_string($t) && $t !== '') $s = $t; }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string)$s, '-');
        return substr($s, 0, 120);
    }
}

// ---------------------------------------------------------------------------------------------
// Snapshot rows
// ---------------------------------------------------------------------------------------------

if (!function_exists('driveDecodeSnapshotRow')) {
    /** (internal) DB row → the snapshot shape drive.php receives (derived fields + decoded JSON). */
    function driveDecodeSnapshotRow(?array $r): ?array {
        if (!$r) return null;
        $limit  = ($r['quota_limit'] === null || $r['quota_limit'] === '') ? null : (int)$r['quota_limit'];
        $usage  = (int)($r['usage_bytes'] ?? 0);
        $drive  = (int)($r['drive_bytes'] ?? 0);
        $trash  = (int)($r['trash_bytes'] ?? 0);
        $other  = (int)($r['other_bytes'] ?? 0);
        $json = static function ($v, $default) {
            if (!is_string($v) || $v === '') return $default;
            $d = json_decode($v, true);
            return is_array($d) ? $d : $default;
        };
        $byType = $json($r['by_type_json'] ?? null, []);
        $quick  = $json($r['quick_wins_json'] ?? null, []);
        $quick += ['trash' => ['bytes' => $trash], 'duplicates' => ['bytes' => 0, 'files' => 0, 'groups' => 0], 'oldVersions' => ['bytes' => 0, 'files' => 0, 'groups' => 0]];
        return [
            'id'                => (int)$r['id'],
            'taken_at'          => (string)$r['taken_at'],
            'status'            => (string)$r['status'],
            'quota_limit'       => $limit,
            'usage_bytes'       => $usage,
            'drive_bytes'       => $drive,
            'trash_bytes'       => $trash,
            'active_bytes'      => max(0, $drive - $trash),
            'other_bytes'       => $other,
            'free_bytes'        => $limit === null ? null : max(0, $limit - $usage),
            'pct_used'          => $limit === null ? null : drivePercent($usage, $limit),
            'quota_note'        => ($r['quota_note'] ?? null) !== null && $r['quota_note'] !== '' ? (string)$r['quota_note'] : null,
            'account_email'     => ($r['account_email'] ?? null) !== null && $r['account_email'] !== '' ? (string)$r['account_email'] : null,
            'script_version'    => ($r['script_version'] ?? null) !== null && $r['script_version'] !== '' ? (string)$r['script_version'] : null,
            'file_count'        => (int)($r['file_count'] ?? 0),
            'folder_count'      => (int)($r['folder_count'] ?? 0),
            'candidate_count'   => (int)($r['candidate_count'] ?? 0),
            'listed_bytes'      => (int)($r['listed_bytes'] ?? 0),
            'burn_rate_per_day' => ($r['burn_rate_per_day'] === null || $r['burn_rate_per_day'] === '') ? null : (int)$r['burn_rate_per_day'],
            'days_to_full'      => ($r['days_to_full'] === null || $r['days_to_full'] === '') ? null : (int)$r['days_to_full'],
            'basis_days'        => ($r['basis_days'] === null || $r['basis_days'] === '') ? null : (int)$r['basis_days'],
            'by_type'           => $byType,
            'quick_wins'        => $quick,
            'clients'           => $json($r['clients_json'] ?? null, []),
            'has_tree'          => is_string($r['tree_json'] ?? null) && $r['tree_json'] !== '',
            'duration_ms'       => ($r['duration_ms'] === null || $r['duration_ms'] === '') ? null : (int)$r['duration_ms'],
            'created_at'        => (string)($r['created_at'] ?? ''),
            'finished_at'       => ($r['finished_at'] ?? null) !== null && $r['finished_at'] !== '' ? (string)$r['finished_at'] : null,
        ];
    }
}

if (!function_exists('driveSnapshotColumns')) {
    /** (internal) Every drive_snapshots column except the big blobs (tree_json / state_json / part_hashes). */
    function driveSnapshotColumns(): string {
        return 'id, taken_at, status, quota_limit, usage_bytes, drive_bytes, trash_bytes, other_bytes, quota_note, account_email, script_version,
                file_count, folder_count, candidate_count, listed_bytes, burn_rate_per_day, days_to_full, basis_days,
                clients_json, by_type_json, quick_wins_json, duration_ms, created_at, finished_at,
                CASE WHEN tree_json IS NULL OR tree_json = \'\' THEN \'\' ELSE \'1\' END AS tree_json';
    }
}

if (!function_exists('driveLatestSnapshot')) {
    /** Newest snapshot (complete only by default) or null = "no snapshot yet". */
    function driveLatestSnapshot(PDO $pdo, bool $completeOnly = true): ?array {
        if (!hasDriveTables($pdo)) return null;
        $sql = 'SELECT ' . driveSnapshotColumns() . ' FROM drive_snapshots'
             . ($completeOnly ? " WHERE status = 'complete'" : '')
             . ' ORDER BY taken_at DESC, id DESC LIMIT 1';
        $s = $pdo->prepare($sql);
        $s->execute();
        $r = $s->fetch();
        return driveDecodeSnapshotRow($r ?: null);
    }
}

if (!function_exists('driveSnapshot')) {
    /** One snapshot by id (any status) or null. */
    function driveSnapshot(PDO $pdo, int $id): ?array {
        if (!hasDriveTables($pdo) || $id <= 0) return null;
        $s = $pdo->prepare('SELECT ' . driveSnapshotColumns() . ' FROM drive_snapshots WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch();
        return driveDecodeSnapshotRow($r ?: null);
    }
}

if (!function_exists('driveSnapshotIsStale')) {
    /** Not complete, or taken more than DRIVE_STALE_SNAPSHOT_HOURS ago → "the nightly check may have failed". */
    function driveSnapshotIsStale(array $snapshot, ?int $now = null): bool {
        if (($snapshot['status'] ?? '') !== 'complete') return true;
        $ts = strtotime((string)($snapshot['taken_at'] ?? ''));
        if ($ts === false) return true;
        return (($now ?? time()) - $ts) > DRIVE_STALE_SNAPSHOT_HOURS * 3600;
    }
}

if (!function_exists('driveProjection')) {
    /**
     * Burn rate / days-to-full labels from the stored projection fields.
     *   growing   true | false ("not growing") | null (fewer than two snapshots ≥ 1 day apart)
     *   label     'Growing 1.2 GB/day' | 'Shrinking 300 MB/day' | 'Not growing' | 'Not enough history yet'
     *   basisLabel 'over the last 30 days' | 'over the last 12 days (all the history there is)' | ''
     *   fullLabel 'Full in about 41 days' | 'Full in about 2 years' | 'Not growing' | 'No storage limit' | ''
     */
    function driveProjection(array $snapshot): array {
        $burn  = $snapshot['burn_rate_per_day'] ?? null;
        $basis = $snapshot['basis_days'] ?? null;
        $days  = $snapshot['days_to_full'] ?? null;
        $limit = $snapshot['quota_limit'] ?? null;
        $out = ['burnRatePerDay' => $burn === null ? null : (int)$burn, 'daysToFull' => $days === null ? null : (int)$days,
                'basisDays' => $basis === null ? 0 : (int)$basis, 'growing' => null, 'label' => 'Not enough history yet',
                'basisLabel' => '', 'fullLabel' => ''];
        if ($basis === null || $burn === null) {
            if ($limit === null) $out['fullLabel'] = 'No storage limit';
            return $out;
        }
        $basis = (int)$basis; $burn = (int)$burn;
        $out['basisLabel'] = $basis >= DRIVE_HISTORY_BASIS_DAYS
            ? 'over the last ' . $basis . ' days'
            : 'over the last ' . $basis . ' day' . ($basis === 1 ? '' : 's') . ' (all the history there is)';
        if ($burn <= 0) {
            $out['growing'] = false;
            $out['label'] = $burn < 0 ? 'Shrinking ' . driveFormatBytes(-$burn) . '/day' : 'Not growing';
            $out['fullLabel'] = $limit === null ? 'No storage limit' : 'Not growing';
            return $out;
        }
        $out['growing'] = true;
        $out['label'] = 'Growing ' . driveFormatBytes($burn) . '/day';
        if ($limit === null) { $out['fullLabel'] = 'No storage limit'; return $out; }
        if ($days === null) return $out;
        $d = (int)$days;
        if ($d <= 0)        $out['fullLabel'] = 'Full now';
        elseif ($d < 60)    $out['fullLabel'] = 'Full in about ' . $d . ' day' . ($d === 1 ? '' : 's');
        elseif ($d < 730)   $out['fullLabel'] = 'Full in about ' . (int)round($d / 30) . ' months';
        else                $out['fullLabel'] = 'Full in about ' . (int)round($d / 365) . ' years';
        return $out;
    }
}

if (!function_exists('driveHistory')) {
    /** Complete snapshots of the last $days days, one per day (the latest wins), oldest first: {date, snapshot_id, usageBytes, driveBytes, limitBytes}. */
    function driveHistory(PDO $pdo, int $days = 90): array {
        if (!hasDriveTables($pdo)) return [];
        $since = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
        $s = $pdo->prepare("SELECT id, taken_at, usage_bytes, drive_bytes, quota_limit FROM drive_snapshots
                             WHERE status = 'complete' AND taken_at >= ? ORDER BY taken_at ASC, id ASC");
        $s->execute([$since]);
        $byDay = [];
        foreach ($s->fetchAll() as $r) {
            $day = substr((string)$r['taken_at'], 0, 10);
            $byDay[$day] = [
                'date'        => $day,
                'snapshot_id' => (int)$r['id'],
                'usageBytes'  => (int)$r['usage_bytes'],
                'driveBytes'  => (int)$r['drive_bytes'],
                'limitBytes'  => ($r['quota_limit'] === null || $r['quota_limit'] === '') ? null : (int)$r['quota_limit'],
            ];
        }
        ksort($byDay);
        return array_values($byDay);
    }
}

if (!function_exists('driveSnapshotBefore')) {
    /** (internal) Newest complete snapshot taken at or before $cutoff ('Y-m-d H:i:s'), excluding $excludeId; null when none. */
    function driveSnapshotBefore(PDO $pdo, string $cutoff, int $excludeId = 0): ?array {
        $s = $pdo->prepare("SELECT id, taken_at, usage_bytes, drive_bytes, quota_limit, days_to_full, clients_json FROM drive_snapshots
                             WHERE status = 'complete' AND taken_at <= ? AND id <> ? ORDER BY taken_at DESC, id DESC LIMIT 1");
        $s->execute([$cutoff, $excludeId]);
        $r = $s->fetch();
        return $r ?: null;
    }
}

if (!function_exists('driveOldestComplete')) {
    /** (internal) Oldest complete snapshot before $cutoff, excluding $excludeId; null when none. */
    function driveOldestComplete(PDO $pdo, string $cutoff, int $excludeId = 0): ?array {
        $s = $pdo->prepare("SELECT id, taken_at, usage_bytes, drive_bytes, quota_limit, days_to_full, clients_json FROM drive_snapshots
                             WHERE status = 'complete' AND taken_at < ? AND id <> ? ORDER BY taken_at ASC, id ASC LIMIT 1");
        $s->execute([$cutoff, $excludeId]);
        $r = $s->fetch();
        return $r ?: null;
    }
}

if (!function_exists('driveComputeProjection')) {
    /**
     * Burn rate for a snapshot from the history: usage now − usage of the newest complete snapshot
     * ≥ DRIVE_HISTORY_BASIS_DAYS older, else the oldest complete one when it is ≥ 1 day older, else
     * nothing. Returns [burn_rate_per_day|null, days_to_full|null, basis_days|null, basis_snapshot_id|null].
     */
    function driveComputeProjection(PDO $pdo, array $snapshot): array {
        $takenTs = strtotime((string)$snapshot['taken_at']);
        $id = (int)$snapshot['id'];
        $cutoff = date('Y-m-d H:i:s', $takenTs - DRIVE_HISTORY_BASIS_DAYS * 86400);
        $basis = driveSnapshotBefore($pdo, $cutoff, $id);
        if ($basis === null) {
            $basis = driveOldestComplete($pdo, date('Y-m-d H:i:s', $takenTs - 86400 + 1), $id);
        }
        if ($basis === null) return ['burn_rate_per_day' => null, 'days_to_full' => null, 'basis_days' => null, 'basis_snapshot_id' => null];
        $basisTs = strtotime((string)$basis['taken_at']);
        $spanDays = ($takenTs - $basisTs) / 86400;
        if ($spanDays < 1) return ['burn_rate_per_day' => null, 'days_to_full' => null, 'basis_days' => null, 'basis_snapshot_id' => null];
        $delta = (int)$snapshot['usage_bytes'] - (int)$basis['usage_bytes'];
        $burn = (int)round($delta / $spanDays);
        $daysToFull = null;
        $limit = $snapshot['quota_limit'];
        if ($burn > 0 && $limit !== null) {
            $free = max(0, (int)$limit - (int)$snapshot['usage_bytes']);
            $daysToFull = (int)floor($free / $burn);
        }
        return ['burn_rate_per_day' => $burn, 'days_to_full' => $daysToFull, 'basis_days' => (int)max(1, round($spanDays)), 'basis_snapshot_id' => (int)$basis['id']];
    }
}

if (!function_exists('driveAlertsDue')) {
    /**
     * Threshold alerts to mail for a (complete) snapshot: pct80 / pct90 / pct95 when usage is at or over
     * the mark and the previous complete snapshot was under it (or none exists); days14 likewise for
     * daysToFull < DRIVE_ALERT_DAYS. Kinds already recorded in drive_alerts for this snapshot are skipped.
     */
    function driveAlertsDue(PDO $pdo, array $snapshot): array {
        $limit = $snapshot['quota_limit'] ?? null;
        $pct = $limit === null || (int)$limit <= 0 ? null : 100 * (int)$snapshot['usage_bytes'] / (int)$limit;
        $days = $snapshot['days_to_full'] ?? null;
        $prev = driveSnapshotBefore($pdo, (string)$snapshot['taken_at'], (int)$snapshot['id']);
        $prevPct = null; $prevDays = null;
        if ($prev !== null) {
            $pl = $prev['quota_limit'];
            $prevPct = ($pl === null || $pl === '' || (int)$pl <= 0) ? null : 100 * (int)$prev['usage_bytes'] / (int)$pl;
            $prevDays = ($prev['days_to_full'] === null || $prev['days_to_full'] === '') ? null : (int)$prev['days_to_full'];
        }
        $due = [];
        if ($pct !== null) {
            foreach ([80 => 'pct80', 90 => 'pct90', 95 => 'pct95'] as $mark => $kind) {
                if ($pct >= $mark && ($prevPct === null || $prevPct < $mark)) $due[] = $kind;
            }
        }
        if ($days !== null && (int)$days < DRIVE_ALERT_DAYS && ($prevDays === null || $prevDays >= DRIVE_ALERT_DAYS)) $due[] = 'days14';
        if (!$due) return [];
        $s = $pdo->prepare('SELECT kind FROM drive_alerts WHERE snapshot_id = ?');
        $s->execute([(int)$snapshot['id']]);
        $sent = array_map(fn($r) => (string)$r['kind'], $s->fetchAll());
        return array_values(array_diff($due, $sent));
    }
}

// ---------------------------------------------------------------------------------------------
// Clients / tree / folders
// ---------------------------------------------------------------------------------------------

if (!function_exists('driveClients')) {
    /** Client rollup for a snapshot (clients_json, enriched at finish): bytes desc, '(unfiled)' last. See drive-design.md §3. */
    function driveClients(PDO $pdo, int $snapshotId): array {
        if (!hasDriveTables($pdo)) return [];
        $s = $pdo->prepare('SELECT clients_json FROM drive_snapshots WHERE id = ?');
        $s->execute([$snapshotId]);
        $raw = $s->fetchColumn();
        $rows = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $c) {
            if (!is_array($c) || !isset($c['slug'])) continue;
            $slug = (string)$c['slug'];
            $out[] = [
                'slug'            => $slug,
                'name'            => $slug === 'unfiled' ? '(unfiled)' : (string)($c['name'] ?? $slug),
                'folderId'        => isset($c['folderId']) && $c['folderId'] !== '' ? (string)$c['folderId'] : null,
                'bytes'           => (int)($c['bytes'] ?? 0),
                'pctOfDrive'      => (float)($c['pctOfDrive'] ?? 0),
                'staleBytes'      => (int)($c['staleBytes'] ?? 0),
                'stalePct'        => (float)($c['stalePct'] ?? 0),
                'fileCount'       => (int)($c['fileCount'] ?? 0),
                'lastActivityAt'  => isset($c['lastActivityAt']) && $c['lastActivityAt'] !== '' ? (string)$c['lastActivityAt'] : null,
                'growth30d'       => array_key_exists('growth30d', $c) && $c['growth30d'] !== null ? (int)$c['growth30d'] : null,
                'growthBasisDays' => array_key_exists('growthBasisDays', $c) && $c['growthBasisDays'] !== null ? (int)$c['growthBasisDays'] : null,
                'webLink'         => isset($c['webLink']) && $c['webLink'] !== '' ? (string)$c['webLink'] : null,
                'companyId'       => isset($c['companyId']) && $c['companyId'] !== null ? (int)$c['companyId'] : null,
                'companySlug'     => isset($c['companySlug']) && $c['companySlug'] !== '' ? (string)$c['companySlug'] : null,
                'unfiled'         => $slug === 'unfiled',
                'candidateCount'  => (int)($c['candidateCount'] ?? 0),
                'candidateBytes'  => (int)($c['candidateBytes'] ?? 0),
            ];
        }
        usort($out, function ($a, $b) {
            if ($a['unfiled'] !== $b['unfiled']) return $a['unfiled'] ? 1 : -1;
            return $b['bytes'] <=> $a['bytes'] ?: strcmp($a['name'], $b['name']);
        });
        return $out;
    }
}

if (!function_exists('driveTreeNode')) {
    /** (internal) Normalise one tree node (as stored) and trim its children to $depth levels. */
    function driveTreeNode(array $n, int $depth): array {
        $node = [
            'id'             => (string)($n['id'] ?? ''),
            'name'           => (string)($n['name'] ?? ''),
            'path'           => (string)($n['path'] ?? ''),
            'bytes'          => (int)($n['bytes'] ?? 0),
            'staleBytes'     => (int)($n['staleBytes'] ?? 0),
            'fileCount'      => (int)($n['fileCount'] ?? 0),
            'lastActivityAt' => isset($n['lastActivityAt']) && $n['lastActivityAt'] !== '' ? (string)$n['lastActivityAt'] : null,
            'webLink'        => isset($n['webLink']) && $n['webLink'] !== '' ? (string)$n['webLink'] : null,
            'clientSlug'     => isset($n['clientSlug']) && $n['clientSlug'] !== '' ? (string)$n['clientSlug'] : null,
            'truncated'      => (int)($n['truncated'] ?? 0),
            'childCount'     => is_array($n['children'] ?? null) ? count($n['children']) : 0,
            'children'       => [],
        ];
        if ($depth > 0 && is_array($n['children'] ?? null)) {
            foreach ($n['children'] as $c) if (is_array($c)) $node['children'][] = driveTreeNode($c, $depth - 1);
        }
        return $node;
    }
}

if (!function_exists('driveTreeFind')) {
    /** (internal) Depth-first search for a node id inside the stored tree. */
    function driveTreeFind(array $n, string $id): ?array {
        if ((string)($n['id'] ?? '') === $id) return $n;
        foreach ((array)($n['children'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $hit = driveTreeFind($c, $id);
            if ($hit !== null) return $hit;
        }
        return null;
    }
}

if (!function_exists('driveTree')) {
    /** The stored (trimmed) tree: the root when $folderId is null, else that subtree; children cut to $depth levels; null when absent. */
    function driveTree(PDO $pdo, int $snapshotId, ?string $folderId = null, int $depth = 4): ?array {
        if (!hasDriveTables($pdo)) return null;
        $s = $pdo->prepare('SELECT tree_json FROM drive_snapshots WHERE id = ?');
        $s->execute([$snapshotId]);
        $raw = $s->fetchColumn();
        $tree = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($tree)) return null;
        if ($folderId !== null && $folderId !== '' && (string)($tree['id'] ?? '') !== $folderId) {
            $tree = driveTreeFind($tree, $folderId);
            if ($tree === null) return null;
        }
        return driveTreeNode($tree, max(0, $depth));
    }
}

if (!function_exists('driveFolderRow')) {
    /** (internal) drive_folders row → the folder shape. */
    function driveFolderRow(array $r, int $nowTs): array {
        $idle = driveIdleDays($r['last_activity_at'] ?? null, null, $nowTs);
        $bytes = (int)$r['bytes'];
        return [
            'folderId'       => (string)$r['folder_id'],
            'parentId'       => ($r['parent_id'] ?? null) !== null && $r['parent_id'] !== '' ? (string)$r['parent_id'] : null,
            'name'           => (string)$r['name'],
            'path'           => (string)$r['path'],
            'depth'          => (int)$r['depth'],
            'clientSlug'     => ($r['client_slug'] ?? null) !== null && $r['client_slug'] !== '' ? (string)$r['client_slug'] : null,
            'bytes'          => $bytes,
            'staleBytes'     => (int)$r['stale_bytes'],
            'stalePct'       => drivePercent((int)$r['stale_bytes'], $bytes),
            'fileCount'      => (int)$r['file_count'],
            'lastActivityAt' => ($r['last_activity_at'] ?? null) !== null && $r['last_activity_at'] !== '' ? (string)$r['last_activity_at'] : null,
            'idleDays'       => $idle,
            'stale'          => ($r['last_activity_at'] ?? null) !== null && $r['last_activity_at'] !== '' && driveIsStale($idle),
            'webLink'        => ($r['web_link'] ?? null) !== null && $r['web_link'] !== '' ? (string)$r['web_link'] : driveParentLink((string)$r['folder_id']),
        ];
    }
}

if (!function_exists('driveSnapshotTakenTs')) {
    /** (internal) taken_at of a snapshot as a unix time (idle days are measured from the snapshot, not from now). */
    function driveSnapshotTakenTs(PDO $pdo, int $snapshotId): int {
        static $cache = [];
        if (isset($cache[$snapshotId])) return $cache[$snapshotId];
        $s = $pdo->prepare('SELECT taken_at FROM drive_snapshots WHERE id = ?');
        $s->execute([$snapshotId]);
        $t = strtotime((string)$s->fetchColumn());
        return $cache[$snapshotId] = ($t === false ? time() : $t);
    }
}

if (!function_exists('driveFolderChildren')) {
    /** Every stored folder directly under $folderId (the full folder table, deeper than the trimmed tree), bytes desc. */
    function driveFolderChildren(PDO $pdo, int $snapshotId, string $folderId): array {
        if (!hasDriveTables($pdo) || $folderId === '') return [];
        $now = driveSnapshotTakenTs($pdo, $snapshotId);
        $s = $pdo->prepare('SELECT * FROM drive_folders WHERE snapshot_id = ? AND parent_id = ? ORDER BY bytes DESC, name ASC');
        $s->execute([$snapshotId, $folderId]);
        return array_map(fn($r) => driveFolderRow($r, $now), $s->fetchAll());
    }
}

if (!function_exists('driveFolder')) {
    /** One stored folder or null. */
    function driveFolder(PDO $pdo, int $snapshotId, string $folderId): ?array {
        if (!hasDriveTables($pdo) || $folderId === '') return null;
        $s = $pdo->prepare('SELECT * FROM drive_folders WHERE snapshot_id = ? AND folder_id = ?');
        $s->execute([$snapshotId, $folderId]);
        $r = $s->fetch();
        return $r ? driveFolderRow($r, driveSnapshotTakenTs($pdo, $snapshotId)) : null;
    }
}

// ---------------------------------------------------------------------------------------------
// Files: candidates, largest, by type, quick wins
// ---------------------------------------------------------------------------------------------

if (!function_exists('driveFileRow')) {
    /** (internal) drive_files row → the file shape drive.php receives. */
    function driveFileRow(array $r): array {
        $parent = ($r['parent_id'] ?? null) !== null && $r['parent_id'] !== '' ? (string)$r['parent_id'] : null;
        return [
            'id'         => (string)$r['file_id'],
            'name'       => (string)$r['name'],
            'path'       => ($r['path'] ?? null) !== null && $r['path'] !== '' ? (string)$r['path'] : '/' . (string)$r['name'],
            'clientSlug' => ($r['client_slug'] ?? null) !== null && $r['client_slug'] !== '' ? (string)$r['client_slug'] : null,
            'typeBucket' => (string)$r['type_bucket'],
            'mimeType'   => (string)$r['mime_type'],
            'bytes'      => (int)$r['bytes'],
            'modifiedAt' => ($r['modified_at'] ?? null) !== null && $r['modified_at'] !== '' ? (string)$r['modified_at'] : null,
            'viewedAt'   => ($r['viewed_at'] ?? null) !== null && $r['viewed_at'] !== '' ? (string)$r['viewed_at'] : null,
            'idleDays'   => (int)$r['idle_days'],
            'score'      => (int)$r['score'],
            'candidate'  => (int)$r['is_candidate'] === 1,
            'parentId'   => $parent,
            'parentLink' => driveParentLink($parent),
            'webLink'    => ($r['web_link'] ?? null) !== null && $r['web_link'] !== '' ? (string)$r['web_link'] : null,
            'md5'        => ($r['md5'] ?? null) !== null && $r['md5'] !== '' ? (string)$r['md5'] : null,
        ];
    }
}

if (!function_exists('driveFileFilterSql')) {
    /** (internal) WHERE fragments + params for the candidate filters (type, minIdleDays, client). */
    function driveFileFilterSql(array $filters): array {
        $where = []; $params = [];
        $type = trim((string)($filters['type'] ?? ''));
        if ($type !== '' && in_array($type, driveTypeBuckets(), true)) { $where[] = 'type_bucket = ?'; $params[] = $type; }
        $idle = (int)($filters['minIdleDays'] ?? 0);
        if ($idle > 0) { $where[] = 'idle_days >= ?'; $params[] = $idle; }
        $client = trim((string)($filters['client'] ?? ''));
        if ($client === 'unfiled') { $where[] = 'client_slug IS NULL'; }
        elseif ($client !== '' && preg_match('/^[a-z0-9-]{1,120}$/', $client)) { $where[] = 'client_slug = ?'; $params[] = $client; }
        return [$where, $params];
    }
}

if (!function_exists('driveCandidates')) {
    /** Offboard candidates (score desc, bytes desc). $filters: type, minIdleDays, client ('unfiled' = no client), limit (200, max 2000 — the offboard view embeds that many), offset. */
    function driveCandidates(PDO $pdo, int $snapshotId, array $filters = []): array {
        if (!hasDriveTables($pdo)) return [];
        [$where, $params] = driveFileFilterSql($filters);
        $limit  = max(1, min(2000, (int)($filters['limit'] ?? 200)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sql = 'SELECT * FROM drive_files WHERE snapshot_id = ? AND is_candidate = 1'
             . ($where ? ' AND ' . implode(' AND ', $where) : '')
             . ' ORDER BY score DESC, bytes DESC, name ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $s = $pdo->prepare($sql);
        $s->execute(array_merge([$snapshotId], $params));
        return array_map('driveFileRow', $s->fetchAll());
    }
}

if (!function_exists('driveCandidateCount')) {
    function driveCandidateCount(PDO $pdo, int $snapshotId, array $filters = []): int {
        if (!hasDriveTables($pdo)) return 0;
        [$where, $params] = driveFileFilterSql($filters);
        $s = $pdo->prepare('SELECT COUNT(*) FROM drive_files WHERE snapshot_id = ? AND is_candidate = 1' . ($where ? ' AND ' . implode(' AND ', $where) : ''));
        $s->execute(array_merge([$snapshotId], $params));
        return (int)$s->fetchColumn();
    }
}

if (!function_exists('driveLargestFiles')) {
    /** Largest stored files (bytes desc); $clientSlug null = whole Drive, 'unfiled' = files outside every client folder. */
    function driveLargestFiles(PDO $pdo, int $snapshotId, ?string $clientSlug = null, int $limit = 50): array {
        if (!hasDriveTables($pdo)) return [];
        [$where, $params] = driveFileFilterSql(['client' => $clientSlug ?? '']);
        $limit = max(1, min(1000, $limit));
        $s = $pdo->prepare('SELECT * FROM drive_files WHERE snapshot_id = ?' . ($where ? ' AND ' . implode(' AND ', $where) : '')
                           . ' ORDER BY bytes DESC, name ASC LIMIT ' . $limit);
        $s->execute(array_merge([$snapshotId], $params));
        return array_map('driveFileRow', $s->fetchAll());
    }
}

if (!function_exists('driveByType')) {
    /** [{bucket, bytes, pct}] for the five buckets (bytes desc); from the totals computed at finish over every listed file. */
    function driveByType(PDO $pdo, int $snapshotId): array {
        $snap = driveSnapshot($pdo, $snapshotId);
        $bytes = array_fill_keys(driveTypeBuckets(), 0);
        foreach ((array)($snap['by_type'] ?? []) as $row) {
            if (is_array($row) && isset($row['bucket'], $bytes[$row['bucket']])) $bytes[$row['bucket']] = (int)$row['bytes'];
        }
        $total = array_sum($bytes);
        $out = [];
        foreach ($bytes as $b => $n) $out[] = ['bucket' => $b, 'bytes' => $n, 'pct' => drivePercent($n, $total)];
        usort($out, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
        return $out;
    }
}

if (!function_exists('driveQuickWins')) {
    /** ['trash' => ['bytes'], 'duplicates' => ['bytes','files','groups'], 'oldVersions' => ['bytes','files','groups']]. */
    function driveQuickWins(PDO $pdo, int $snapshotId): array {
        $snap = driveSnapshot($pdo, $snapshotId);
        $q = (array)($snap['quick_wins'] ?? []);
        return [
            'trash'       => ['bytes' => (int)($q['trash']['bytes'] ?? ($snap['trash_bytes'] ?? 0))],
            'duplicates'  => ['bytes' => (int)($q['duplicates']['bytes'] ?? 0), 'files' => (int)($q['duplicates']['files'] ?? 0), 'groups' => (int)($q['duplicates']['groups'] ?? 0)],
            'oldVersions' => ['bytes' => (int)($q['oldVersions']['bytes'] ?? 0), 'files' => (int)($q['oldVersions']['files'] ?? 0), 'groups' => (int)($q['oldVersions']['groups'] ?? 0)],
        ];
    }
}

if (!function_exists('driveQuickWinGroups')) {
    /** (internal) Stored groups of one kind, reclaimable desc. */
    function driveQuickWinGroups(PDO $pdo, int $snapshotId, string $kind, int $limit): array {
        if (!hasDriveTables($pdo)) return [];
        $s = $pdo->prepare('SELECT * FROM drive_quick_wins WHERE snapshot_id = ? AND kind = ? ORDER BY reclaimable_bytes DESC, id ASC LIMIT ' . max(1, min(1000, $limit)));
        $s->execute([$snapshotId, $kind]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $files = is_string($r['files_json'] ?? null) ? json_decode($r['files_json'], true) : null;
            $out[] = [
                'key'              => (string)$r['group_key'],
                'name'             => (string)$r['name'],
                'path'             => ($r['path'] ?? null) !== null && $r['path'] !== '' ? (string)$r['path'] : null,
                'clientSlug'       => ($r['client_slug'] ?? null) !== null && $r['client_slug'] !== '' ? (string)$r['client_slug'] : null,
                'fileCount'        => (int)$r['file_count'],
                'bytes'            => (int)$r['bytes'],
                'reclaimableBytes' => (int)$r['reclaimable_bytes'],
                'files'            => is_array($files) ? $files : [],
            ];
        }
        return $out;
    }
}

if (!function_exists('driveDuplicateGroups')) {
    /** Exact-duplicate groups stored for the snapshot (reclaimable desc): {key, name, path, clientSlug, fileCount, bytes, reclaimableBytes, files[]}. */
    function driveDuplicateGroups(PDO $pdo, int $snapshotId, int $limit = 50): array {
        return driveQuickWinGroups($pdo, $snapshotId, 'duplicate', $limit);
    }
}

if (!function_exists('driveOldVersionGroups')) {
    /** Old-version groups stored for the snapshot; files carry 'version' and 'keep' (the highest). */
    function driveOldVersionGroups(PDO $pdo, int $snapshotId, int $limit = 50): array {
        return driveQuickWinGroups($pdo, $snapshotId, 'old_version', $limit);
    }
}

if (!function_exists('driveUrl')) {
    /** drive.php (admin-only, unscoped) with optional params, e.g. driveUrl(['snapshot' => 12]). */
    function driveUrl(array $params = []): string {
        $base = function_exists('pagePath') ? pagePath('drive') : 'drive.php';
        return $base . ($params ? '?' . http_build_query($params) : '');
    }
}
