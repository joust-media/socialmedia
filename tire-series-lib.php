<?php
/**
 * Tire asset series — shared helpers (loaded by helpers.php; never include directly).
 *
 * A series is a folder of generated renders of one tire:
 *   media/tires/<tire-slug>/<series-folder>/<file>
 * at the site docroot (a sibling of this app, next to media/library/<client>/).
 * Files arrive by FTP (syncTireSeries() scans the folder) or through
 * tire-upload.php; each becomes a tire_images row with series_id set and
 * status 'pending', reviewed with the existing tire-image flow. Rows with
 * series_id IS NULL are the tire's reference images (add-feature.php, ≤6).
 *
 * Tables: tire_series + tire_images.series_id (migrate.php steps 25–26). Every
 * query is gated on hasTireSeries() so a deploy that has not run migrate.php
 * behaves exactly as before (no series, every row a reference row, no scan).
 * tire_series.drive_url (step 29, gated on tireSeriesHasDriveUrl()) holds an
 * optional Google Drive share link per series — the client's "Open in Google
 * Drive" button on the series header in Assets → Collections.
 *
 * All functions are function_exists-guarded and do no work at load.
 * Contract: scratchpad/tire-series-design.md.
 */

// ---------------------------------------------------------------------
// Gate
// ---------------------------------------------------------------------

if (!function_exists('hasTireSeries')) {
    /** Do tire_series AND tire_images.series_id exist yet? Cached per request. */
    function hasTireSeries(?PDO $pdo = null): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        if ($pdo === null) { $pdo = $GLOBALS['pdo'] ?? null; }
        if (!$pdo instanceof PDO) return false;   // not cached: a later call may have a PDO
        try {
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tire_series'
            ");
            $s->execute();
            if ((int)$s->fetchColumn() <= 0) return $cached = false;
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'tire_images' AND COLUMN_NAME = 'series_id'
            ");
            $s->execute();
            return $cached = (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return $cached = false;
        }
    }
}

if (!function_exists('tireSeriesHasDriveUrl')) {
    /** tire_series.drive_url exists? (migrate.php 29) Cached per request; false until hasTireSeries(). */
    function tireSeriesHasDriveUrl(?PDO $pdo = null): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        if ($pdo === null) { $pdo = $GLOBALS['pdo'] ?? null; }
        if (!$pdo instanceof PDO) return false;   // not cached: a later call may have a PDO
        if (!hasTireSeries($pdo)) return $cached = false;
        try {
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'tire_series' AND COLUMN_NAME = 'drive_url'
            ");
            $s->execute();
            return $cached = (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return $cached = false;
        }
    }
}

if (!function_exists('tireSeriesDriveHosts')) {
    /** Hosts a series' Drive link may point at (Google Drive / Docs share links + Google Photos albums). */
    function tireSeriesDriveHosts(): array {
        return ['drive.google.com', 'docs.google.com', 'photos.google.com', 'photos.app.goo.gl'];
    }
}

if (!function_exists('tireSeriesValidDriveUrl')) {
    /**
     * A usable Google Drive share link: https:// only, host on the tireSeriesDriveHosts() list
     * (or a www. alias), ≤ 512 chars, no whitespace / control characters / quotes / angle brackets.
     */
    function tireSeriesValidDriveUrl(string $url): bool {
        $url = trim($url);
        if ($url === '' || strlen($url) > 512) return false;
        if (preg_match('/[\s\x00-\x1f\x7f"\'<>\\\\]/', $url)) return false;
        $p = @parse_url($url);
        if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
        if (isset($p['user']) || isset($p['pass']) || isset($p['port'])) return false;
        $host = strtolower((string)($p['host'] ?? ''));
        if (str_starts_with($host, 'www.')) $host = substr($host, 4);
        return in_array($host, tireSeriesDriveHosts(), true);
    }
}

if (!function_exists('tireSeriesDriveUrlError')) {
    /** The message the UI shows for a rejected link (one wording everywhere). */
    function tireSeriesDriveUrlError(): string {
        return 'Enter a Google Drive share link (https://drive.google.com/…)';
    }
}

if (!function_exists('tireImagesHaveDisplayName')) {
    /** tire_images.display_name exists? (migrate.php 11b) Cached per request. */
    function tireImagesHaveDisplayName(PDO $pdo): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $cached = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0;
        } catch (Throwable $e) {
            $cached = false;
        }
        return $cached;
    }
}

// ---------------------------------------------------------------------
// Folders, slugs, URLs
// ---------------------------------------------------------------------

if (!function_exists('mediaRootPath')) {
    /** The docroot's media/ folder — a sibling of this app, resolved exactly like libraryDir(). */
    function mediaRootPath(): string {
        return __DIR__ . '/../media';
    }
}

if (!function_exists('tireMediaRootPath')) {
    /** media/tires on disk. */
    function tireMediaRootPath(): string {
        return mediaRootPath() . '/tires';
    }
}

if (!function_exists('tireSlugify')) {
    /** 'Klever R/T' → 'klever-r-t': lowercase, ASCII-transliterated, [^a-z0-9]+ → '-', trimmed, ≤100 chars; '' → 'tire'. */
    function tireSlugify(string $s): string {
        $s = trim($s);
        if ($s !== '' && function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string)$s, '-');
        if (strlen($s) > 100) $s = rtrim(substr($s, 0, 100), '-');
        return $s === '' ? 'tire' : $s;
    }
}

if (!function_exists('tireSlug')) {
    /** The tire's folder slug: the row's resolved 'slug' when present (tiresWithSlugs / tireWithSlug), else tireSlugify(name). */
    function tireSlug(array $tire): string {
        $slug = trim((string)($tire['slug'] ?? ''));
        if ($slug !== '' && preg_match('/^[a-z0-9\-]+$/', $slug)) return $slug;
        return tireSlugify((string)($tire['name'] ?? ''));
    }
}

if (!function_exists('tireResolveSlugs')) {
    /**
     * (internal) Collision rule over a list of {id, name} rows (whole tires table,
     * ordered by id): the lowest id keeps tireSlugify(name); every later tire that
     * slugifies to the same string gets '-<id>' appended. Deterministic, so the
     * folder the admin must create is always tireFolderRel().
     */
    function tireResolveSlugs(array $tires): array {
        usort($tires, static function ($a, $b) { return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0); });
        $seen = [];
        $out = [];
        foreach ($tires as $t) {
            $base = tireSlugify((string)($t['name'] ?? ''));
            $slug = isset($seen[$base]) ? $base . '-' . (int)($t['id'] ?? 0) : $base;
            $seen[$base] = true;
            $t['slug'] = $slug;
            $out[] = $t;
        }
        return $out;
    }
}

if (!function_exists('tiresWithSlugs')) {
    /** tires rows {id, company_id, module_id, name, slug} — all tires when $companyId is null. */
    function tiresWithSlugs(PDO $pdo, ?int $companyId = null): array {
        try {
            $s = $pdo->prepare("SELECT id, company_id, module_id, name FROM tires ORDER BY id ASC");
            $s->execute();
            $rows = $s->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        if (!is_array($rows) || !$rows) return [];
        foreach ($rows as &$r) {
            $r['id'] = (int)($r['id'] ?? 0); $r['company_id'] = (int)($r['company_id'] ?? 0); $r['module_id'] = (int)($r['module_id'] ?? 0);
            $r['name'] = (string)($r['name'] ?? '');
        }
        unset($r);
        $rows = tireResolveSlugs($rows);
        if ($companyId !== null) {
            $rows = array_values(array_filter($rows, static function ($t) use ($companyId) {
                return (int)($t['company_id'] ?? 0) === $companyId;
            }));
        }
        return $rows;
    }
}

if (!function_exists('tireWithSlug')) {
    /** One tire row {id, company_id, module_id, name, slug} or null. */
    function tireWithSlug(PDO $pdo, int $tireId): ?array {
        if ($tireId <= 0) return null;
        foreach (tiresWithSlugs($pdo) as $t) {
            if ((int)$t['id'] === $tireId) return $t;
        }
        return null;
    }
}

if (!function_exists('tireFolderPath')) {
    /** Filesystem path of the tire's folder: media/tires/<slug>. ($company accepted for symmetry with libraryDir(); not part of the path.) */
    function tireFolderPath(array $company, array $tire): string {
        return tireMediaRootPath() . '/' . tireSlug($tire);
    }
}

if (!function_exists('tireFolderRel')) {
    /** 'media/tires/<slug>' — what a series row's image_url starts with. */
    function tireFolderRel(array $company, array $tire): string {
        return 'media/tires/' . tireSlug($tire);
    }
}

if (!function_exists('tireSeriesFolderName')) {
    /** The on-disk subfolder of a series: its stored folder, else its slug. */
    function tireSeriesFolderName(array $series): string {
        $f = trim((string)($series['folder'] ?? ''));
        if ($f === '' || $f === '.' || $f === '..' || $f[0] === '.' || strpbrk($f, "/\\\0") !== false) {
            $f = tireSlugify((string)($series['slug'] ?? ($series['name'] ?? 'series')));
        }
        return $f;
    }
}

if (!function_exists('tireSeriesFolderPath')) {
    /** Filesystem path of a series folder: media/tires/<tire-slug>/<series-folder>. */
    function tireSeriesFolderPath(array $company, array $tire, array $series): string {
        return tireFolderPath($company, $tire) . '/' . tireSeriesFolderName($series);
    }
}

if (!function_exists('tireImageUrlParts')) {
    /**
     * (internal) Validate a stored image_url and split it:
     *   ['kind' => 'uploads', 'name' => 'feat_x.jpg']                       for 'uploads/<file>'
     *   ['kind' => 'media', 'segs' => ['<tire>', '<series>', '<file>']]     for 'media/tires/<a>/<b>/<file>'
     * null for anything else (absolute URLs, traversal, dot segments, extra depth, NUL).
     */
    function tireImageUrlParts($url): ?array {
        $url = ltrim(trim((string)$url), '/');
        if ($url === '' || strpos($url, "\0") !== false || strpos($url, '\\') !== false) return null;
        if (preg_match('#^(https?:)?//#i', $url)) return null;
        $okSeg = static function (string $s): bool {
            return $s !== '' && $s !== '.' && $s !== '..' && $s[0] !== '.';
        };
        if (strpos($url, 'uploads/') === 0) {
            $name = substr($url, 8);
            if (!$okSeg($name) || strpos($name, '/') !== false) return null;
            return ['kind' => 'uploads', 'name' => $name];
        }
        if (strpos($url, 'media/tires/') === 0) {
            $segs = explode('/', substr($url, 12));
            if (count($segs) !== 3) return null;
            foreach ($segs as $s) { if (!$okSeg($s)) return null; }
            return ['kind' => 'media', 'segs' => $segs];
        }
        return null;
    }
}

if (!function_exists('tireImageSrc')) {
    /**
     * URL for any tire image row. 'uploads/…' → basePath() . '/uploads/…' (as
     * today); 'media/tires/…' → root-relative '/media/tires/<a>/<b>/<file>',
     * rawurlencoded per segment exactly like libraryFileUrl(); absolute or
     * root-rooted URLs pass through. Accepts a row or a bare URL string.
     */
    function tireImageSrc($row): string {
        $url = is_array($row) ? (string)($row['image_url'] ?? '') : (string)$row;
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('#^(https?:)?//#i', $url) || $url[0] === '/') return $url;
        $url = ltrim($url, '/');
        if (strpos($url, 'media/tires/') === 0) {
            return '/' . implode('/', array_map('rawurlencode', explode('/', $url)));
        }
        return (function_exists('basePath') ? basePath() : '') . '/' . $url;
    }
}

if (!function_exists('tireImagePath')) {
    /**
     * Absolute filesystem path of a tire image, or null when the URL is not one
     * of ours, the file is missing, or it resolves outside <app>/uploads/ (file
     * directly inside, like uploadsPathOrNull) / media/tires/<a>/<b>/. Segments
     * are validated textually (no '.', '..', empty or dot-prefixed parts) and,
     * when realpath() can resolve them, by realpath containment as well.
     */
    function tireImagePath($row): ?string {
        $parts = tireImageUrlParts(is_array($row) ? ($row['image_url'] ?? '') : $row);
        if ($parts === null) return null;
        if ($parts['kind'] === 'uploads') {
            $root = __DIR__ . '/uploads';
            $path = $root . '/' . $parts['name'];
        } else {
            $root = tireMediaRootPath();
            $path = $root . '/' . implode('/', $parts['segs']);
        }
        if (!is_file($path)) return null;
        $real = realpath($path);
        $rootReal = realpath($root);
        if ($real !== false && $rootReal !== false) {
            $rootReal = rtrim($rootReal, '/');
            if (strpos($real, $rootReal . '/') !== 0) return null;                       // symlink escape
            if ($parts['kind'] === 'uploads' && dirname($real) !== $rootReal) return null;
            return $real;
        }
        return $path;   // realpath unavailable (stream-wrapped harness): the validated string path
    }
}

if (!function_exists('tireThumbRel')) {
    /** (internal) The thumb's URL-ish relative path ('uploads/.thumbs/x.jpg' / 'media/tires/a/b/.thumbs/x.jpg'); null when the row can't resolve. */
    function tireThumbRel($row): ?string {
        $parts = tireImageUrlParts(is_array($row) ? ($row['image_url'] ?? '') : $row);
        if ($parts === null) return null;
        if ($parts['kind'] === 'uploads') {
            $stem = pathinfo($parts['name'], PATHINFO_FILENAME);
            return $stem === '' ? null : 'uploads/.thumbs/' . $stem . '.jpg';
        }
        $stem = pathinfo($parts['segs'][2], PATHINFO_FILENAME);
        return $stem === '' ? null : 'media/tires/' . $parts['segs'][0] . '/' . $parts['segs'][1] . '/.thumbs/' . $stem . '.jpg';
    }
}

if (!function_exists('tireThumbPath')) {
    /** Filesystem path the thumb has / would have (no existence check); null when the row can't resolve. */
    function tireThumbPath($row): ?string {
        $rel = tireThumbRel($row);
        if ($rel === null) return null;
        if (strpos($rel, 'uploads/') === 0) return __DIR__ . '/' . $rel;
        return mediaRootPath() . '/' . substr($rel, strlen('media/'));
    }
}

if (!function_exists('tireImageThumb')) {
    /** Thumb URL when <dir>/.thumbs/<stem>.jpg exists, else tireImageSrc(). */
    function tireImageThumb($row): string {
        $path = tireThumbPath($row);
        if ($path !== null && is_file($path)) {
            return tireImageSrc(tireThumbRel($row));
        }
        return tireImageSrc($row);
    }
}

if (!function_exists('tireThumbMemoryOk')) {
    /** (internal) Rough guard: will decoding w×h fit in memory_limit? (5 bytes/px + the JPEG copy.) */
    function tireThumbMemoryOk(int $w, int $h): bool {
        $limit = (string)ini_get('memory_limit');
        if ($limit === '' || $limit === '-1') return true;
        $n = (int)$limit;
        switch (strtolower(substr($limit, -1))) {
            case 'g': $n *= 1024;   // fall through
            case 'm': $n *= 1024;   // fall through
            case 'k': $n *= 1024;
        }
        $need = $w * $h * 5 + 8 * 1024 * 1024;
        return ($n - memory_get_usage()) > $need;
    }
}

if (!function_exists('ensureTireThumb')) {
    /**
     * Write <dir>/.thumbs/<stem>.jpg (max 640 px on the long edge, q82, EXIF
     * orientation honoured for JPEGs) for an image row. Idempotent: skipped when
     * the thumb is newer than the source. Videos, rows without a resolvable file,
     * missing GD, unreadable / oversized sources → null. Never fatal.
     * Returns the thumb path (existing or new).
     */
    function ensureTireThumb($row): ?string {
        $src = tireImagePath($row);
        if ($src === null) return null;
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        if (!in_array($ext, function_exists('imageExts') ? imageExts() : ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return null;
        $thumb = tireThumbPath($row);
        if ($thumb === null) return null;
        if (is_file($thumb) && (int)@filemtime($thumb) >= (int)@filemtime($src)) return $thumb;
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale') || !function_exists('imagejpeg')) return null;
        try {
            $info = @getimagesize($src);
            if (!is_array($info) || (int)$info[0] <= 0 || (int)$info[1] <= 0) return null;
            if (!tireThumbMemoryOk((int)$info[0], (int)$info[1])) return null;
            $data = @file_get_contents($src);
            if ($data === false || $data === '') return null;
            $im = @imagecreatefromstring($data);
            unset($data);
            if (!$im) return null;
            if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
                $exif = @exif_read_data($src);
                $o = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
                if ($o === 2 && function_exists('imageflip')) { imageflip($im, IMG_FLIP_HORIZONTAL); }
                elseif ($o === 3) { $r = imagerotate($im, 180, 0); if ($r) { imagedestroy($im); $im = $r; } }
                elseif ($o === 4 && function_exists('imageflip')) { imageflip($im, IMG_FLIP_VERTICAL); }
                elseif ($o === 5 && function_exists('imageflip')) { $r = imagerotate($im, -90, 0); if ($r) { imagedestroy($im); $im = $r; } imageflip($im, IMG_FLIP_HORIZONTAL); }
                elseif ($o === 6) { $r = imagerotate($im, -90, 0); if ($r) { imagedestroy($im); $im = $r; } }
                elseif ($o === 7 && function_exists('imageflip')) { $r = imagerotate($im, 90, 0); if ($r) { imagedestroy($im); $im = $r; } imageflip($im, IMG_FLIP_HORIZONTAL); }
                elseif ($o === 8) { $r = imagerotate($im, 90, 0); if ($r) { imagedestroy($im); $im = $r; } }
            }
            $w = imagesx($im); $h = imagesy($im); $max = 640;
            if ($w > $max || $h > $max) {
                $scaled = $w >= $h ? imagescale($im, $max, -1) : imagescale($im, (int)max(1, round($w * $max / $h)), $max);
                if ($scaled) { imagedestroy($im); $im = $scaled; }
            }
            // Flatten alpha onto white so PNG/WebP/GIF transparency doesn't turn black in the JPEG.
            $canvas = imagecreatetruecolor(imagesx($im), imagesy($im));
            if ($canvas) {
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
                imagecopy($canvas, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
                imagedestroy($im);
                $im = $canvas;
            }
            $dir = dirname($thumb);
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            if (!is_dir($dir)) { imagedestroy($im); return null; }
            $ok = @imagejpeg($im, $thumb, 82);
            imagedestroy($im);
            if ($ok) { @chmod($thumb, 0644); return $thumb; }
            return null;
        } catch (Throwable $e) {
            error_log('ensureTireThumb failed: ' . $e->getMessage());
            return null;
        }
    }
}

// ---------------------------------------------------------------------
// Series rows
// ---------------------------------------------------------------------

if (!function_exists('tireSeriesHumanName')) {
    /** 'series-1' → 'Series 1', 'winter_shoot' → 'Winter shoot', 'Series 2' → 'Series 2'. */
    function tireSeriesHumanName(string $s): string {
        $s = trim($s);
        $s = preg_replace('/[\-_]+/', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', (string)$s));
        if ($s === '') return 'Series';
        $first = function_exists('mb_substr') ? mb_substr($s, 0, 1, 'UTF-8') : substr($s, 0, 1);
        $rest  = function_exists('mb_substr') ? mb_substr($s, 1, null, 'UTF-8') : substr($s, 1);
        $first = function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
        $s = $first . $rest;
        return function_exists('mb_substr') ? mb_substr($s, 0, 120, 'UTF-8') : substr($s, 0, 120);
    }
}

if (!function_exists('tireSeriesNormalizeRow')) {
    /** (internal) Cast a tire_series row; $counts = [series_id => {pending, approved, denied, total}]. */
    function tireSeriesNormalizeRow(array $s, array $counts = []): array {
        $id = (int)($s['id'] ?? 0);
        $c  = $counts[$id] ?? [];
        return [
            'id'         => $id,
            'tire_id'    => (int)($s['tire_id'] ?? 0),
            'name'       => (string)($s['name'] ?? ''),
            'slug'       => (string)($s['slug'] ?? ''),
            'folder'     => ($s['folder'] ?? null) === null || $s['folder'] === '' ? null : (string)$s['folder'],
            'drive_url'  => ($s['drive_url'] ?? null) === null || trim((string)$s['drive_url']) === '' ? null : trim((string)$s['drive_url']),   // NULL before migrate.php 29
            'sort_order' => (int)($s['sort_order'] ?? 0),
            'created_at' => $s['created_at'] ?? null,
            'updated_at' => $s['updated_at'] ?? null,
            'counts'     => [
                'pending'  => (int)($c['pending'] ?? 0),
                'approved' => (int)($c['approved'] ?? 0),
                'denied'   => (int)($c['denied'] ?? 0),
                'total'    => (int)($c['total'] ?? 0),
            ],
        ];
    }
}

if (!function_exists('tireSeriesCounts')) {
    /**
     * Status counts of one tire in one GROUP BY:
     *   {reference: {pending, approved, denied, total}, series: {<id>: {…}}, series_count, render_count}
     * series_count = distinct series that have rows (use tireSeriesForTire() for the full list).
     */
    function tireSeriesCounts(PDO $pdo, int $tireId): array {
        $zero = ['pending' => 0, 'approved' => 0, 'denied' => 0, 'total' => 0];
        $out = ['reference' => $zero, 'series' => [], 'series_count' => 0, 'render_count' => 0];
        if ($tireId <= 0) return $out;
        $withSeries = hasTireSeries($pdo);
        $sel = $withSeries ? 'series_id' : 'NULL AS series_id';
        $grp = $withSeries ? 'series_id, status' : 'status';
        try {
            $s = $pdo->prepare("SELECT {$sel}, status, COUNT(*) AS n FROM tire_images WHERE tire_id = ? GROUP BY {$grp}");
            $s->execute([$tireId]);
            $rows = $s->fetchAll();
        } catch (Throwable $e) {
            return $out;
        }
        foreach ((array)$rows as $r) {
            $st = (string)($r['status'] ?? '');
            $n  = (int)($r['n'] ?? 0);
            $sid = isset($r['series_id']) && $r['series_id'] !== null ? (int)$r['series_id'] : 0;
            if ($sid > 0) {
                if (!isset($out['series'][$sid])) $out['series'][$sid] = $zero;
                if (isset($out['series'][$sid][$st])) $out['series'][$sid][$st] += $n;
                $out['series'][$sid]['total'] += $n;
                $out['render_count'] += $n;
            } else {
                if (isset($out['reference'][$st])) $out['reference'][$st] += $n;
                $out['reference']['total'] += $n;
            }
        }
        $out['series_count'] = count($out['series']);
        return $out;
    }
}

if (!function_exists('tireSeriesForTire')) {
    /** All series of a tire, sort_order then name, each with counts (tireSeriesNormalizeRow shape). */
    function tireSeriesForTire(PDO $pdo, int $tireId): array {
        if ($tireId <= 0 || !hasTireSeries($pdo)) return [];
        $s = $pdo->prepare("SELECT * FROM tire_series WHERE tire_id = ? ORDER BY sort_order ASC, name ASC, id ASC");
        $s->execute([$tireId]);
        $rows = $s->fetchAll();
        if (!$rows) return [];
        $counts = tireSeriesCounts($pdo, $tireId)['series'];
        $out = [];
        foreach ($rows as $r) $out[] = tireSeriesNormalizeRow($r, $counts);
        return $out;
    }
}

if (!function_exists('tireSeriesById')) {
    /** One series (with counts) or null. Callers scope with tire/company themselves. */
    function tireSeriesById(PDO $pdo, int $id): ?array {
        if ($id <= 0 || !hasTireSeries($pdo)) return null;
        $s = $pdo->prepare("SELECT * FROM tire_series WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) return null;
        $counts = tireSeriesCounts($pdo, (int)$row['tire_id'])['series'];
        return tireSeriesNormalizeRow($row, $counts);
    }
}

if (!function_exists('tireSeriesBySlug')) {
    /** One series of a tire by slug (input slugified) or null. */
    function tireSeriesBySlug(PDO $pdo, int $tireId, string $slug): ?array {
        if ($tireId <= 0 || !hasTireSeries($pdo)) return null;
        $slug = tireSlugify($slug);
        $s = $pdo->prepare("SELECT * FROM tire_series WHERE tire_id = ? AND slug = ?");
        $s->execute([$tireId, $slug]);
        $row = $s->fetch();
        if (!$row) return null;
        $counts = tireSeriesCounts($pdo, $tireId)['series'];
        return tireSeriesNormalizeRow($row, $counts);
    }
}

if (!function_exists('ensureTireSeries')) {
    /**
     * Find-or-create a series by slug (tireSlugify($nameOrFolder)). An existing
     * row is returned as is (its folder is filled in when NULL and
     * $opts['folder'] is given). A new row gets name = $opts['name'] ??
     * tireSeriesHumanName($nameOrFolder), folder = $opts['folder'] ?? null,
     * sort_order = max + 1, drive_url = $opts['drive_url'] (validated, only once migrate.php 29
     * ran). Never logs — callers do. Throws on DB errors.
     */
    function ensureTireSeries(PDO $pdo, int $tireId, string $nameOrFolder, array $opts = []): array {
        if ($tireId <= 0) throw new InvalidArgumentException('Invalid tire id');
        if (!hasTireSeries($pdo)) throw new RuntimeException('tire_series is not available (run migrate.php)');
        $slug   = tireSlugify($nameOrFolder);
        $folder = isset($opts['folder']) && trim((string)$opts['folder']) !== '' ? (string)$opts['folder'] : null;
        $drive  = isset($opts['drive_url']) && trim((string)$opts['drive_url']) !== '' ? trim((string)$opts['drive_url']) : null;
        if ($drive !== null && !tireSeriesValidDriveUrl($drive)) throw new InvalidArgumentException(tireSeriesDriveUrlError());
        if ($drive !== null && !tireSeriesHasDriveUrl($pdo)) throw new RuntimeException('tire_series.drive_url is not available (run migrate.php)');
        $s = $pdo->prepare("SELECT * FROM tire_series WHERE tire_id = ? AND slug = ?");
        $s->execute([$tireId, $slug]);
        $row = $s->fetch();
        if ($row) {
            if ($folder !== null && ($row['folder'] === null || $row['folder'] === '')) {
                $pdo->prepare("UPDATE tire_series SET folder = ? WHERE id = ?")->execute([$folder, (int)$row['id']]);
                $row['folder'] = $folder;
            }
            return tireSeriesNormalizeRow($row, tireSeriesCounts($pdo, $tireId)['series']);
        }
        $name = isset($opts['name']) && trim((string)$opts['name']) !== '' ? trim((string)$opts['name']) : tireSeriesHumanName($nameOrFolder);
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 120, 'UTF-8') : substr($name, 0, 120);
        $m = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM tire_series WHERE tire_id = ?");
        $m->execute([$tireId]);
        $sort = (int)$m->fetchColumn() + 1;
        if ($drive !== null) {
            $ins = $pdo->prepare("INSERT INTO tire_series (tire_id, name, slug, folder, drive_url, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([$tireId, $name, $slug, $folder, $drive, $sort]);
        } else {
            $ins = $pdo->prepare("INSERT INTO tire_series (tire_id, name, slug, folder, sort_order) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$tireId, $name, $slug, $folder, $sort]);
        }
        $id = (int)$pdo->lastInsertId();
        $now = date('Y-m-d H:i:s');
        return tireSeriesNormalizeRow([
            'id' => $id, 'tire_id' => $tireId, 'name' => $name, 'slug' => $slug, 'folder' => $folder, 'drive_url' => $drive,
            'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}

if (!function_exists('createTireSeries')) {
    /**
     * ensureTireSeries() for a UI-created series: folder = slug (the upload target), optional Drive
     * link ($driveUrl, validated — InvalidArgumentException when it is not a Google Drive share link).
     * Returns ['created' => bool] + the row.
     */
    function createTireSeries(PDO $pdo, int $tireId, string $name, ?string $driveUrl = null): array {
        $slug = tireSlugify($name);
        $existing = tireSeriesBySlug($pdo, $tireId, $slug);
        if ($existing) return $existing + ['created' => false];
        $opts = ['name' => trim($name), 'folder' => $slug];
        if ($driveUrl !== null && trim($driveUrl) !== '') $opts['drive_url'] = trim($driveUrl);
        $row = ensureTireSeries($pdo, $tireId, $name, $opts);
        return $row + ['created' => true];
    }
}

if (!function_exists('setTireSeriesDriveUrl')) {
    /**
     * Set (or clear with null / '') the Google Drive link of a series. The URL is trimmed and must
     * pass tireSeriesValidDriveUrl() (InvalidArgumentException otherwise); RuntimeException before
     * migrate.php 29. Returns the row (drive_url patched in) or null for an unknown series.
     */
    function setTireSeriesDriveUrl(PDO $pdo, int $seriesId, ?string $url): ?array {
        if ($seriesId <= 0 || !hasTireSeries($pdo)) return null;
        $url = $url === null ? null : trim($url);
        if ($url === '') $url = null;
        if ($url !== null && !tireSeriesValidDriveUrl($url)) throw new InvalidArgumentException(tireSeriesDriveUrlError());
        if (!tireSeriesHasDriveUrl($pdo)) throw new RuntimeException('tire_series.drive_url is not available (run migrate.php)');
        $row = tireSeriesById($pdo, $seriesId);
        if (!$row) return null;
        $pdo->prepare("UPDATE tire_series SET drive_url = ? WHERE id = ?")->execute([$url, $seriesId]);
        $row['drive_url'] = $url;   // harnesses whose UPDATE is a no-op still see the new value
        return $row;
    }
}

if (!function_exists('renameTireSeries')) {
    /** Rename (name only — slug and folder stay, the folder is on disk). Returns the row or null. */
    function renameTireSeries(PDO $pdo, int $id, string $name): ?array {
        if ($id <= 0 || !hasTireSeries($pdo)) return null;
        $name = trim($name);
        if ($name === '') return null;
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 120, 'UTF-8') : substr($name, 0, 120);
        $pdo->prepare("UPDATE tire_series SET name = ? WHERE id = ?")->execute([$name, $id]);
        $row = tireSeriesById($pdo, $id);
        if ($row) $row['name'] = $name;   // harnesses whose UPDATE is a no-op still see the new name
        return $row;
    }
}

if (!function_exists('deleteTireSeries')) {
    /**
     * Delete a series: its tire_images rows go (their reviews with them); with
     * $deleteFiles each file + thumb is unlinked (tireImagePath / tireThumbPath)
     * and the empty folder (+ .thumbs) removed. Without $deleteFiles the folder
     * stays, so the next scan re-creates the series with fresh 'pending' rows.
     * Returns {images, files}.
     */
    function deleteTireSeries(PDO $pdo, int $id, bool $deleteFiles = false): array {
        $out = ['images' => 0, 'files' => 0];
        if ($id <= 0 || !hasTireSeries($pdo)) return $out;
        $series = tireSeriesById($pdo, $id);
        if (!$series) return $out;
        $s = $pdo->prepare("SELECT id, image_url FROM tire_images WHERE series_id = ?");
        $s->execute([$id]);
        $rows = $s->fetchAll();
        $dirs = [];
        foreach ((array)$rows as $r) {
            $out['images']++;
            if (!$deleteFiles) continue;
            $path = tireImagePath($r);
            if ($path !== null) {
                if (@unlink($path)) { $out['files']++; $dirs[dirname($path)] = true; }
            }
            $thumb = tireThumbPath($r);
            if ($thumb !== null && is_file($thumb)) { @unlink($thumb); $dirs[dirname($thumb)] = true; }
        }
        $pdo->prepare("DELETE FROM tire_images WHERE series_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM tire_series WHERE id = ?")->execute([$id]);
        if ($deleteFiles) {
            // The series folder itself (only when it is now empty) + its .thumbs (derived data: wiped whole).
            $tire = tireWithSlug($pdo, (int)$series['tire_id']);
            if ($tire) {
                $folder = tireSeriesFolderPath([], $tire, $series);
                $thumbs = $folder . '/.thumbs';
                if (is_dir($thumbs)) {
                    foreach ((array)@scandir($thumbs) as $f) {
                        if ($f === '.' || $f === '..' || !is_file($thumbs . '/' . $f)) continue;
                        if (preg_match('/\.jpg$/i', $f)) @unlink($thumbs . '/' . $f);
                    }
                }
                $dirs[$thumbs] = true;
                $dirs[$folder] = true;
            }
            // .thumbs first (deeper paths first), then the folders.
            $list = array_keys($dirs);
            usort($list, static function ($a, $b) { return strlen($b) <=> strlen($a); });
            foreach ($list as $d) {
                if (is_dir($d)) { @rmdir($d); }
            }
        }
        return $out;
    }
}

if (!function_exists('reorderTireSeries')) {
    /** sort_order = position (0-based) for the given ids of this tire; others keep theirs. Returns tireSeriesForTire(). */
    function reorderTireSeries(PDO $pdo, int $tireId, array $ids): array {
        if ($tireId <= 0 || !hasTireSeries($pdo)) return [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; })));
        if ($ids) {
            $upd = $pdo->prepare("UPDATE tire_series SET sort_order = ? WHERE id = ? AND tire_id = ?");
            foreach ($ids as $pos => $id) $upd->execute([$pos, $id, $tireId]);
        }
        return tireSeriesForTire($pdo, $tireId);
    }
}

// ---------------------------------------------------------------------
// Image rows
// ---------------------------------------------------------------------

if (!function_exists('tireImageRowMeta')) {
    /**
     * (internal) Presentation fields for a tire_images row: src, thumb, type
     * (image|video), ext, mime, label, download. $ctx: tire_name, index (1-based
     * position for the "<tire> · n" fallback label).
     */
    function tireImageRowMeta(array $row, array $ctx = []): array {
        $url  = (string)($row['image_url'] ?? '');
        $path = parse_url($url, PHP_URL_PATH);
        $ext  = strtolower(pathinfo(is_string($path) && $path !== '' ? $path : $url, PATHINFO_EXTENSION));
        $isV  = function_exists('isVideoExt') && isVideoExt($ext);
        $tireName = trim((string)($ctx['tire_name'] ?? ($row['tire_name'] ?? '')));
        $label = trim((string)(($row['display_name'] ?? '') ?: ($row['caption'] ?? '')));
        if ($label === '') {
            $label = isset($ctx['index']) ? ($tireName !== '' ? $tireName . ' · ' : 'Image ') . (int)$ctx['index'] : 'Image #' . (int)($row['id'] ?? 0);
        }
        $stemSrc = (string)(($row['display_name'] ?? '') ?: (($tireName !== '' ? $tireName : 'image') . (isset($ctx['index']) ? '-' . (int)$ctx['index'] : '-' . (int)($row['id'] ?? 0))));
        $stem = function_exists('safeFilenameStem') ? safeFilenameStem($stemSrc) : preg_replace('/[^A-Za-z0-9._-]+/', '-', $stemSrc);
        if ($stem === '') $stem = 'image';
        return [
            'src'      => tireImageSrc($row),
            'thumb'    => tireImageThumb($row),
            'type'     => $isV ? 'video' : 'image',
            'ext'      => $ext !== '' ? $ext : 'jpg',
            'mime'     => $isV && function_exists('videoMime') ? videoMime($ext) : '',
            'label'    => $label,
            'download' => $stem . '.' . ($ext !== '' ? $ext : 'jpg'),
        ];
    }
}

if (!function_exists('tireImagesForSeries')) {
    /**
     * tire_images rows of one tire: $seriesId null → reference images
     * (series_id IS NULL), else that series. $opts: status, client (bool → AND
     * status <> 'denied'), limit, offset, company_id (tenant check through the
     * tires JOIN). Each row = ti.* + tire_name + tireImageRowMeta() fields.
     * Ordered sort_order ASC, id ASC. Without hasTireSeries() the series filter
     * is dropped (every row is a reference row) and series_id reads null.
     */
    function tireImagesForSeries(PDO $pdo, int $tireId, ?int $seriesId = null, array $opts = []): array {
        if ($tireId <= 0) return [];
        $withSeries = hasTireSeries($pdo);
        // Clause order mirrors the original assets.php collection query (company, tire, client filter,
        // status) so the pinned "client SQL filters denied" shape stays; the series clause comes last.
        $sql = "SELECT ti.*, t.name AS tire_name FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE";
        $params = [];
        if (isset($opts['company_id']) && (int)$opts['company_id'] > 0) { $sql .= " t.company_id = ? AND"; $params[] = (int)$opts['company_id']; }
        $sql .= " ti.tire_id = ?"; $params[] = $tireId;
        if (!$withSeries && $seriesId !== null && $seriesId > 0) return [];
        if (!empty($opts['client'])) { $sql .= " AND ti.status <> 'denied'"; }
        if (isset($opts['status']) && in_array($opts['status'], ['pending', 'approved', 'denied'], true)) { $sql .= " AND ti.status = ?"; $params[] = $opts['status']; }
        if ($withSeries) {
            if ($seriesId === null || $seriesId <= 0) { $sql .= " AND ti.series_id IS NULL"; }
            else { $sql .= " AND ti.series_id = ?"; $params[] = $seriesId; }
        }
        $sql .= " ORDER BY ti.sort_order ASC, ti.id ASC";
        $limit  = isset($opts['limit']) ? (int)$opts['limit'] : 0;
        $offset = isset($opts['offset']) ? max(0, (int)$opts['offset']) : 0;
        if ($limit > 0) { $sql .= " LIMIT " . $limit . ($offset > 0 ? " OFFSET " . $offset : ''); }
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $out = [];
        $n = $offset;
        foreach ((array)$s->fetchAll() as $r) {
            $n++;
            $r['series_id'] = isset($r['series_id']) && $r['series_id'] !== null ? (int)$r['series_id'] : null;
            $out[] = $r + tireImageRowMeta($r, ['tire_name' => (string)($r['tire_name'] ?? ''), 'index' => $n]);
        }
        return $out;
    }
}

if (!function_exists('tireImageById')) {
    /** One tire_images row + tire_id, company_id, tire_name, series_id (null-safe) + meta fields; null when unknown. */
    function tireImageById(PDO $pdo, int $id): ?array {
        if ($id <= 0) return null;
        $s = $pdo->prepare("SELECT ti.*, t.company_id, t.name AS tire_name FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.id = ?");
        $s->execute([$id]);
        $r = $s->fetch();
        if (!$r) return null;
        $r['series_id']  = isset($r['series_id']) && $r['series_id'] !== null ? (int)$r['series_id'] : null;
        $r['company_id'] = (int)($r['company_id'] ?? 0);
        $r['tire_id']    = (int)($r['tire_id'] ?? 0);
        return $r + tireImageRowMeta($r, ['tire_name' => (string)($r['tire_name'] ?? '')]);
    }
}

if (!function_exists('tireSeriesNamesForImages')) {
    /** [series_id => {id, name, slug, tire_id}] for the series referenced by a set of tire_images rows. */
    function tireSeriesNamesForImages(PDO $pdo, array $rows): array {
        if (!hasTireSeries($pdo)) return [];
        $ids = [];
        foreach ($rows as $r) { $sid = (int)($r['series_id'] ?? 0); if ($sid > 0) $ids[$sid] = true; }
        if (!$ids) return [];
        $ids = array_keys($ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        try {
            $s = $pdo->prepare("SELECT id, tire_id, name, slug FROM tire_series WHERE id IN ($ph)");
            $s->execute($ids);
            foreach ((array)$s->fetchAll() as $r) {
                $out[(int)$r['id']] = ['id' => (int)$r['id'], 'tire_id' => (int)$r['tire_id'], 'name' => (string)$r['name'], 'slug' => (string)$r['slug']];
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }
}

// ---------------------------------------------------------------------
// Activity
// ---------------------------------------------------------------------

if (!function_exists('logTireSeriesActivity')) {
    /** activity_log row with entity_type 'tire_series' (actions: created, renamed, deleted, scanned, uploaded, approved, drive_linked, drive_unlinked). company_id looked up when not given. */
    function logTireSeriesActivity(PDO $pdo, string $actor, string $action, int $seriesId, string $summary,
                                   ?string $detail = null, ?string $batchId = null, ?int $companyId = null): void {
        if ($companyId === null || $companyId <= 0) {
            $companyId = 0;
            try {
                $s = $pdo->prepare("SELECT t.company_id FROM tire_series s INNER JOIN tires t ON t.id = s.tire_id WHERE s.id = ?");
                $s->execute([$seriesId]);
                $companyId = (int)$s->fetchColumn();
            } catch (Throwable $e) {
                $companyId = 0;
            }
        }
        if (function_exists('logActivity')) {
            logActivity($pdo, $companyId, 'tire_series', $seriesId, $action, $actor, $summary, $detail, $batchId);
        }
    }
}

// ---------------------------------------------------------------------
// Hardening: media/ is a static folder — never let the web server execute
// anything dropped there (by FTP or by the upload endpoint).
// ---------------------------------------------------------------------

if (!function_exists('tireMediaHtaccessText')) {
    /** The .htaccess written into media/tires/ (and media/ when absent): no PHP/CGI, no directory listing. */
    function tireMediaHtaccessText(): string {
        return "# Written by the portal (tire-series-lib.php): this folder only serves static files.\n"
             . "# Re-created on the next upload / rescan if removed. Same text as media-hardening/htaccess.txt.\n"
             . "Options -Indexes\n"
             . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n"
             . "<IfModule mod_php8.c>\n    php_flag engine off\n</IfModule>\n"
             . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .pht .phar .cgi .pl .py .sh\n"
             . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .pht .phar\n"
             . "<FilesMatch \"(?i)\\.(php\\d?|phtml|phps|pht|phar|cgi|pl|py|sh|htaccess)$\">\n"
             . "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
             . "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n"
             . "</FilesMatch>\n";
    }
}

if (!function_exists('ensureTireMediaHtaccess')) {
    /**
     * Make sure media/tires/.htaccess exists (and media/.htaccess when the parent has none).
     * Called on every upload and scan; writes only when the file is missing, never overwrites
     * a server-managed one. Returns the number of files written. Never fatal.
     */
    function ensureTireMediaHtaccess(): int {
        $n = 0;
        $tires = tireMediaRootPath();
        $media = mediaRootPath();
        foreach ([$media, $tires] as $dir) {
            if (!is_dir($dir)) continue;
            $file = $dir . '/.htaccess';
            if (is_file($file)) continue;
            if (@file_put_contents($file, tireMediaHtaccessText()) !== false) { @chmod($file, 0644); $n++; }
        }
        return $n;
    }
}

// ---------------------------------------------------------------------
// The scan
// ---------------------------------------------------------------------

if (!function_exists('tireSeriesListSubfolders')) {
    /** (internal) Natural-sorted subfolder names of a tire folder, skipping dot-prefixed names (.thumbs, .DS_Store) and non-directories. */
    function tireSeriesListSubfolders(string $dir): array {
        if (!is_dir($dir)) return [];
        $names = @scandir($dir);
        if (!is_array($names)) return [];
        $out = [];
        foreach ($names as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.') continue;
            if (is_link($dir . '/' . $f) || !is_dir($dir . '/' . $f)) continue;   // never follow a symlink out of media/tires/
            $out[] = $f;
        }
        natcasesort($out);
        return array_values($out);
    }
}

if (!function_exists('syncTireSeries')) {
    /**
     * Scan media/tires/<slug>/*\/ for one tire (or every tire of the company)
     * and register new files as pending tire_images rows in their series.
     * Rules (design §4): a series is a subfolder; files use scanLibraryDir()
     * (image + video extensions, dotfiles skipped, .mp4 twin of a .mov dropped,
     * natural sort); rows that already exist for (tire_id, image_url) are left
     * untouched; missing files are counted, never deleted; nothing is created
     * on disk. $opts: thumbs (bool, default true), thumb_cap (int, 40), actor.
     * Returns {scanned_tires, new_series, new_files, thumbs_made, thumbs_pending, missing_files}.
     */
    function syncTireSeries(PDO $pdo, array $company, ?int $tireId = null, array $opts = []): array {
        $out = ['scanned_tires' => 0, 'new_series' => 0, 'new_files' => 0, 'thumbs_made' => 0, 'thumbs_pending' => 0, 'missing_files' => 0];
        if (!hasTireSeries($pdo)) return $out;
        $root = tireMediaRootPath();
        if (!is_dir($root)) return $out;
        $companyId = (int)($company['id'] ?? 0);
        if ($companyId <= 0) return $out;
        ensureTireMediaHtaccess();   // FTP drops land here too: keep the folder non-executable

        $tires = tiresWithSlugs($pdo, $companyId);
        if ($tireId !== null && $tireId > 0) {
            $tires = array_values(array_filter($tires, static function ($t) use ($tireId) { return (int)$t['id'] === $tireId; }));
        }
        if (!$tires) return $out;

        $wantThumbs = array_key_exists('thumbs', $opts) ? (bool)$opts['thumbs'] : true;
        $thumbCap   = isset($opts['thumb_cap']) ? max(0, (int)$opts['thumb_cap']) : 40;
        $actor      = (string)($opts['actor'] ?? 'admin');
        $batchId    = function_exists('newBatchId') ? newBatchId() : null;
        $hasName    = tireImagesHaveDisplayName($pdo);
        $thumbQueue = [];   // rows (image_url) whose thumb should exist

        foreach ($tires as $tire) {
            $tid = (int)$tire['id'];
            $tireDir = tireFolderPath($company, $tire);
            if (!is_dir($tireDir)) continue;
            $out['scanned_tires']++;
            $rel = tireFolderRel($company, $tire);

            // Existing rows of this tire — one query; keyed by image_url.
            $s = $pdo->prepare("SELECT id, image_url, series_id FROM tire_images WHERE tire_id = ?");
            $s->execute([$tid]);
            $existing = [];
            foreach ((array)$s->fetchAll() as $r) $existing[(string)$r['image_url']] = $r;

            $onDisk = [];
            foreach (tireSeriesListSubfolders($tireDir) as $folder) {
                $dir   = $tireDir . '/' . $folder;
                $files = function_exists('scanLibraryDir') ? scanLibraryDir($dir) : [];
                // Was the series known before? (ensureTireSeries returns the same row either way.)
                $known = tireSeriesBySlug($pdo, $tid, $folder) !== null;
                $series = ensureTireSeries($pdo, $tid, $folder, ['folder' => $folder]);
                if (!$known) $out['new_series']++;
                $sid = (int)$series['id'];
                $newHere = 0;
                foreach ($files as $i => $f) {
                    $url = $rel . '/' . $folder . '/' . $f;
                    $onDisk[$url] = true;
                    if (isset($existing[$url])) {
                        $thumbQueue[] = ['image_url' => $url];
                        continue;
                    }
                    $stem = pathinfo($f, PATHINFO_FILENAME);
                    $name = function_exists('safeFilenameStem') ? safeFilenameStem($stem) : $stem;
                    if ($name === '') $name = $stem;
                    $name = function_exists('mb_substr') ? mb_substr($name, 0, 150, 'UTF-8') : substr($name, 0, 150);
                    if ($hasName) {
                        $ins = $pdo->prepare("INSERT INTO tire_images (tire_id, series_id, image_url, caption, sort_order, display_name, status) VALUES (?, ?, ?, '', ?, ?, 'pending')");
                        $ins->execute([$tid, $sid, $url, $i, $name]);
                    } else {
                        $ins = $pdo->prepare("INSERT INTO tire_images (tire_id, series_id, image_url, caption, sort_order, status) VALUES (?, ?, ?, '', ?, 'pending')");
                        $ins->execute([$tid, $sid, $url, $i]);
                    }
                    $existing[$url] = ['id' => (int)$pdo->lastInsertId(), 'image_url' => $url, 'series_id' => $sid];
                    $out['new_files']++;
                    $newHere++;
                    $thumbQueue[] = ['image_url' => $url];
                }
                if ($newHere > 0) {
                    logTireSeriesActivity($pdo, $actor, 'scanned', $sid,
                        "Scanned {$newHere} new render" . ($newHere === 1 ? '' : 's') . " into " . (string)$tire['name'] . " · " . $series['name'],
                        null, $batchId, $companyId);
                }
            }
            // Rows under media/tires/ whose file is gone.
            foreach ($existing as $url => $r) {
                if (strpos((string)$url, $rel . '/') === 0 && !isset($onDisk[$url])) $out['missing_files']++;
            }
        }

        if ($wantThumbs) {
            $imgExts = function_exists('imageExts') ? imageExts() : ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            foreach ($thumbQueue as $row) {
                $ext = strtolower(pathinfo($row['image_url'], PATHINFO_EXTENSION));
                if (!in_array($ext, $imgExts, true)) continue;
                $thumb = tireThumbPath($row);
                if ($thumb === null) continue;
                if (is_file($thumb)) continue;                       // up to date enough for the grid; ensureTireThumb refreshes stale ones lazily
                if ($out['thumbs_made'] >= $thumbCap) { $out['thumbs_pending']++; continue; }
                if (ensureTireThumb($row) !== null) $out['thumbs_made']++;
                else $out['thumbs_pending']++;
            }
        }
        return $out;
    }
}

if (!function_exists('tireSeriesFolderSignature')) {
    /** (internal) "<slug>:<mtime>[/<sub>=<mtime>…];…" over the tire folders that exist — changes whenever a file is added or removed. */
    function tireSeriesFolderSignature(array $company, array $tires): string {
        $parts = [];
        foreach ($tires as $t) {
            $dir = tireFolderPath($company, $t);
            if (!is_dir($dir)) continue;
            $sig = tireSlug($t) . ':' . (int)@filemtime($dir);
            foreach (tireSeriesListSubfolders($dir) as $sub) {
                $sig .= '/' . $sub . '=' . (int)@filemtime($dir . '/' . $sub);
            }
            $parts[] = $sig;
        }
        return implode(';', $parts);
    }
}

if (!function_exists('hasMetaTable')) {
    /** Does the meta key/value table exist? Cached per request. */
    function hasMetaTable(PDO $pdo): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meta'");
            $s->execute();
            $cached = (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            $cached = false;
        }
        return $cached;
    }
}

if (!function_exists('tireSeriesSyncThrottled')) {
    /**
     * The assets.php hook: syncTireSeries() unless the folder signature is
     * unchanged and younger than $maxAge seconds (state in meta
     * 'tire_series_sync_<company_id>' = "<time>|<signature>"; no meta table →
     * always scan). Returns the sync result, or null when skipped / not applicable.
     */
    function tireSeriesSyncThrottled(PDO $pdo, array $company, ?int $tireId = null, int $maxAge = 60): ?array {
        if (!hasTireSeries($pdo)) return null;
        if (!is_dir(tireMediaRootPath())) return null;
        $companyId = (int)($company['id'] ?? 0);
        if ($companyId <= 0) return null;
        $tires = tiresWithSlugs($pdo, $companyId);
        if (!$tires) return null;
        $sig = tireSeriesFolderSignature($company, $tires);
        if ($sig === '') return null;                                    // no tire folder exists → nothing to scan
        $sig = sha1($sig);                                               // meta.v is VARCHAR(255)
        $key = 'tire_series_sync_' . $companyId;
        $useMeta = hasMetaTable($pdo);
        $stored = null;
        if ($useMeta) {
            try {
                $s = $pdo->prepare("SELECT v FROM meta WHERE k = ?");
                $s->execute([$key]);
                $v = $s->fetchColumn();
                $stored = is_string($v) ? $v : null;
            } catch (Throwable $e) {
                $stored = null;
            }
            if ($stored !== null && strpos($stored, '|') !== false) {
                [$at, $prev] = explode('|', $stored, 2);
                if ($prev === $sig && (time() - (int)$at) < $maxAge) return null;
            }
        }
        $result = syncTireSeries($pdo, $company, $tireId);
        if ($useMeta) {
            $val = time() . '|' . $sig;
            try {
                if ($stored !== null) {
                    $pdo->prepare("UPDATE meta SET v = ? WHERE k = ?")->execute([$val, $key]);
                } else {
                    $pdo->prepare("INSERT INTO meta (k, v) VALUES (?, ?)")->execute([$key, $val]);
                }
            } catch (Throwable $e) {
                error_log('tireSeriesSyncThrottled meta write failed: ' . $e->getMessage());
            }
        }
        return $result;
    }
}
