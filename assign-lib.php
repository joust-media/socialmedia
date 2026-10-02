<?php
/**
 * Assign — move emails / pages between clients, safely (assign.php is the only caller; never include
 * from a page). Files on disk move by COPY → VERIFY → (database commit) → DELETE the source:
 *
 *   1. every file is copied to the target client's folder (symlinks are never followed or copied),
 *   2. each copy is verified against its source (size + SHA-1; files over 256 MB compare size + the
 *      SHA-1 of their first and last MiB),
 *   3. only then do the rows change, in ONE transaction for the whole selection (company, a unique
 *      code / slug in the target, Audiences carried over by name, the email leaves the source
 *      client's flows, its activity rows follow it, a 'moved' row is logged),
 *   4. after the commit the source files are deleted. Any failure before the commit removes the
 *      copies again and leaves everything as it was.
 *
 *   assignIds($raw): int[]                          "3,5" | [3, 5] → unique positive ids, in order
 *   assignHtmlProblem(string $html): string         '' or why the HTML cannot be stored
 *   assignUniqueEmailCode(PDO, int $cid, string $code, array &$taken): string    W1 → W1-2 …
 *   assignUniquePageSlug(PDO, array $company, string $slug, array &$taken): string  pricing → pricing-2 …
 *   assignCopyTree(string $from, string $to, ?string $within): array  manifest [rel => bytes]
 *   assignVerifyTree(string $from, string $to, array $manifest): bool
 *   assignSameFile(string $a, string $b): bool
 *   assignRemoveTree(string $dir): int              files removed (symlinks unlinked, never followed)
 *   assignMoveEmails(PDO, array $from, array $to, array $emails, string $actor): array
 *   assignMovePages(PDO, array $from, array $to, array $pages, string $actor): array
 *     → ['moved' => n, 'items' => [{id, label, code|slug, renamed_from?}], 'renamed' => n,
 *        'files' => n copied, 'flows_left' => n (emails), 'cleanup_failed' => n]
 *     Throws RuntimeException (nothing changed) on a copy / verify / database failure.
 */

if (!function_exists('assignIds')) {
    function assignIds($raw): array {
        if (is_string($raw)) $raw = preg_split('/[,\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $v) {
            if (!is_scalar($v)) continue;
            $i = (int)$v;
            if ($i > 0 && !in_array($i, $out, true)) $out[] = $i;
        }
        return array_slice($out, 0, 200);
    }
}

if (!function_exists('assignHtmlProblem')) {
    function assignHtmlProblem(string $html, int $maxBytes = 10485760): string {
        if (trim($html) === '') return 'The HTML is empty.';
        if (strlen($html) > $maxBytes) return 'The HTML is over ' . (int)($maxBytes / 1048576) . ' MB — use the full form (chunked upload) instead.';
        if (preg_match('/<\?php|<\?=/i', $html)) return 'PHP code is not allowed in HTML files.';
        return '';
    }
}

if (!function_exists('assignUniqueEmailCode')) {
    /** $code when free in the company (case-insensitive), else $code-2, $code-3 … (≤ 32 chars). $taken = upper-cased codes, updated. */
    function assignUniqueEmailCode(PDO $pdo, int $companyId, string $code, array &$taken): string {
        if (!$taken) {
            $s = $pdo->prepare("SELECT code FROM emails WHERE company_id = ?");
            $s->execute([$companyId]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $c) $taken[strtoupper((string)$c)] = true;
            $taken['__loaded'] = true;
        }
        $cand = $code !== '' ? $code : 'E1';
        $base = $cand; $n = 2;
        if (preg_match('/^(.+)-(\d+)$/', $cand, $m)) { $base = $m[1]; $n = (int)$m[2] + 1; }   // W2-2 taken → W2-3, not W2-2-2
        $base = mb_substr($base, 0, 28);
        for (; isset($taken[strtoupper($cand)]); $n++) $cand = $base . '-' . $n;
        $taken[strtoupper($cand)] = true;
        return $cand;
    }
}

if (!function_exists('assignNextEmailCode')) {
    /** The first free E<n> code for a new email in the company (E1, E2 …). */
    function assignNextEmailCode(PDO $pdo, int $companyId): string {
        $s = $pdo->prepare("SELECT code FROM emails WHERE company_id = ?");
        $s->execute([$companyId]);
        $taken = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $c) $taken[strtoupper((string)$c)] = true;
        for ($n = 1; isset($taken['E' . $n]); $n++);
        return 'E' . $n;
    }
}

if (!function_exists('assignUniquePageSlug')) {
    /** $slug when free in the company's rows AND on disk (media/pages/<client>/<slug>), else $slug-2 … (≤ 120 chars). */
    function assignUniquePageSlug(PDO $pdo, array $company, string $slug, array &$taken): string {
        if (!$taken) {
            $s = $pdo->prepare("SELECT slug FROM pages WHERE company_id = ?");
            $s->execute([(int)$company['id']]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $c) $taken[(string)$c] = true;
            $taken['__loaded'] = true;
        }
        $slug = $slug !== '' ? $slug : 'page';
        $cand = $slug;
        $base = $slug; $n = 2;
        if (preg_match('/^(.+)-(\d+)$/', $slug, $m)) { $base = $m[1]; $n = (int)$m[2] + 1; }   // pricing-2 taken → pricing-3
        $base = substr($base, 0, 112);
        for (; ; $n++) {
            $dir = pageFolderPath($company, ['slug' => $cand]);
            if (!isset($taken[$cand]) && ($dir === '' || !file_exists($dir))) break;
            $cand = $base . '-' . $n;
        }
        $taken[$cand] = true;
        return $cand;
    }
}

if (!function_exists('assignSameFile')) {
    function assignSameFile(string $a, string $b): bool {
        clearstatcache(true, $a); clearstatcache(true, $b);
        if (!is_file($a) || !is_file($b) || is_link($b)) return false;
        $size = filesize($a);
        if ($size === false || $size !== filesize($b)) return false;
        if ($size <= 256 * 1048576) return hash_file('sha1', $a) === hash_file('sha1', $b);
        $part = static function (string $p, int $offset): string {
            $h = @fopen($p, 'rb'); if ($h === false) return '';
            fseek($h, $offset); $d = (string)fread($h, 1048576); fclose($h);
            return sha1($d);
        };
        return $part($a, 0) === $part($b, 0) && $part($a, $size - 1048576) === $part($b, $size - 1048576);
    }
}

if (!function_exists('assignRemoveTree')) {
    function assignRemoveTree(string $dir): int {
        if ($dir === '' || is_link($dir)) { return @unlink($dir) ? 1 : 0; }
        if (!is_dir($dir)) return 0;
        $n = 0;
        $walk = static function (string $d) use (&$walk, &$n): void {
            $names = @scandir($d);
            if (!is_array($names)) return;
            foreach ($names as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $d . '/' . $f;
                if (is_link($p) || is_file($p)) { if (@unlink($p)) $n++; continue; }
                if (is_dir($p)) { $walk($p); @rmdir($p); }
            }
        };
        $walk($dir);
        @rmdir($dir);
        return $n;
    }
}

if (!function_exists('assignCopyTree')) {
    /** Copy every regular file under $from into $to (which must not exist yet). Folders 0755, files 0644. */
    function assignCopyTree(string $from, string $to, ?string $within = null): array {
        if (is_link($from) || !is_dir($from)) throw new RuntimeException('Source folder is missing');
        if (file_exists($to) || is_link($to)) throw new RuntimeException('The target folder already exists');
        if (!mediaMkdir($to, $within)) throw new RuntimeException('Could not create the target folder (check permissions)');
        $manifest = [];
        $walk = static function (string $dir, string $rel) use (&$walk, &$manifest, $to): void {
            $names = @scandir($dir);
            if (!is_array($names)) throw new RuntimeException('Could not read ' . ($rel === '' ? 'the source folder' : $rel));
            foreach ($names as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $dir . '/' . $f;
                $r = $rel === '' ? $f : $rel . '/' . $f;
                if (is_link($p)) continue;   // never followed, never copied
                if (is_dir($p)) {
                    if (!mediaMkdir($to . '/' . $r)) throw new RuntimeException('Could not create ' . $r);
                    $walk($p, $r);
                    continue;
                }
                if (!is_file($p)) continue;
                if (!@copy($p, $to . '/' . $r)) throw new RuntimeException('Could not copy ' . $r);
                mediaChmodPath($to . '/' . $r);
                clearstatcache(true, $p);
                $manifest[$r] = (int)filesize($p);
            }
        };
        $walk($from, '');
        return $manifest;
    }
}

if (!function_exists('assignVerifyTree')) {
    function assignVerifyTree(string $from, string $to, array $manifest): bool {
        foreach ($manifest as $rel => $bytes) {
            $b = $to . '/' . $rel;
            clearstatcache(true, $b);
            if (!is_file($b) || (int)filesize($b) !== (int)$bytes) return false;
            if (!assignSameFile($from . '/' . $rel, $b)) return false;
        }
        return true;
    }
}

if (!function_exists('assignRepointActivity')) {
    /** The item's activity rows (thread, feed) follow it to the new client. */
    function assignRepointActivity(PDO $pdo, string $entityType, int $entityId, int $fromCid, int $toCid): void {
        if (!function_exists('hasActivityLog') || !hasActivityLog($pdo)) return;
        $pdo->prepare("UPDATE activity_log SET company_id = ? WHERE entity_type = ? AND entity_id = ? AND company_id = ?")
            ->execute([$toCid, $entityType, $entityId, $fromCid]);
    }
}

if (!function_exists('assignMoveEmails')) {
    function assignMoveEmails(PDO $pdo, array $from, array $to, array $emails, string $actor = 'admin'): array {
        $fromCid = (int)$from['id']; $toCid = (int)$to['id'];
        $out = ['moved' => 0, 'items' => [], 'renamed' => 0, 'files' => 0, 'flows_left' => 0, 'cleanup_failed' => 0];
        $taken = [];
        $plan = [];      // per email: [email, newCode, newUrl, src|null, dst|null]
        $copies = [];    // dst paths written so far (removed again on failure)
        try {
            foreach ($emails as $e) {
                $code    = (string)$e['code'];
                $newCode = assignUniqueEmailCode($pdo, $toCid, $code, $taken);
                $url     = (string)$e['html_url'];
                $newUrl  = $url; $src = null; $dst = null;
                $srcPath = emailHostedPath($url);
                if ($srcPath !== null && is_file($srcPath)) {
                    $newUrl = '/media/emails/' . $to['slug'] . '/' . emailHostedFileName($newCode, (int)$e['id']);
                    $dst = emailHostedPath($newUrl);
                    if ($dst === null) throw new RuntimeException('The target email folder resolves outside media/emails/');
                    if (!mediaMkdir(dirname($dst), mediaRootPath())) throw new RuntimeException('Could not create media/emails/' . $to['slug'] . ' (check permissions)');
                    mediaEnsureHtaccess(emailHtmlRootPath(), 'emails-lib.php', emailHtmlHtaccessText());
                    if (file_exists($dst)) throw new RuntimeException('A file is already at ' . $newUrl);
                    if (!@copy($srcPath, $dst)) throw new RuntimeException('Could not copy the email HTML');
                    $copies[] = $dst;
                    mediaChmodPath($dst);
                    if (!assignSameFile($srcPath, $dst)) throw new RuntimeException('The copied email HTML did not verify');
                    $src = $srcPath;
                    $out['files']++;
                }
                $plan[] = [$e, $newCode, $newUrl, $src];
            }

            $batch = newBatchId();
            $pdo->beginTransaction();
            foreach ($plan as [$e, $newCode, $newUrl]) {
                $id = (int)$e['id'];
                // flows are per client: the email leaves the source client's flows (steps renumbered)
                if (function_exists('emailFlowsForEmail') && hasEmailFlowsTable($pdo)) {
                    foreach (emailFlowsForEmail($pdo, $id) as $f) {
                        removeEmailFlowStep($pdo, (int)$f['flow_id'], $id);
                        $out['flows_left']++;
                    }
                }
                $names = array_map(static function ($g) { return (string)$g['name']; }, $e['groups'] ?? []);
                $upd = $pdo->prepare("UPDATE emails SET company_id = ?, code = ?, html_url = ? WHERE id = ? AND company_id = ?");
                $upd->execute([$toCid, $newCode, $newUrl, $id, $fromCid]);
                if ($upd->rowCount() !== 1) throw new RuntimeException('The email changed while it was being moved');
                // Audiences carry over by name (created in the target client when missing)
                $gids = [];
                foreach ($names as $n) { $gid = ensureEmailGroup($pdo, $toCid, $n); if ($gid) $gids[] = $gid; }
                setEmailGroups($pdo, $id, $gids);
                assignRepointActivity($pdo, 'email', $id, $fromCid, $toCid);
                $label = emailDisplayLabel(['code' => $newCode, 'title' => $e['title'], 'id' => $id]);
                logEmailActivity($pdo, $actor, 'moved', $id, "Email {$label} moved from {$from['name']} to {$to['name']}",
                    $from['slug'] . ' → ' . $to['slug'] . ($newCode !== $e['code'] ? ' · ID ' . $e['code'] . ' → ' . $newCode : ''), $batch, $toCid);
                $item = ['id' => $id, 'code' => $newCode, 'label' => $label];
                if ($newCode !== (string)$e['code']) { $item['renamed_from'] = (string)$e['code']; $out['renamed']++; }
                $out['items'][] = $item;
                $out['moved']++;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($copies as $c) @unlink($c);
            throw new RuntimeException($ex->getMessage(), 0, $ex);
        }
        // committed: the copies are authoritative now — remove the sources
        foreach ($plan as [, , , $src]) {
            if ($src !== null && !@unlink($src)) { $out['cleanup_failed']++; error_log('assign: could not remove moved email file ' . $src); }
        }
        return $out;
    }
}

if (!function_exists('assignMovePages')) {
    function assignMovePages(PDO $pdo, array $from, array $to, array $pages, string $actor = 'admin'): array {
        $fromCid = (int)$from['id']; $toCid = (int)$to['id'];
        $out = ['moved' => 0, 'items' => [], 'renamed' => 0, 'files' => 0, 'cleanup_failed' => 0];
        $taken = [];
        $plan = [];     // [page, newSlug, srcDir|null]
        $copies = [];   // target folders created so far
        try {
            foreach ($pages as $p) {
                $newSlug = assignUniquePageSlug($pdo, $to, (string)$p['slug'], $taken);
                $srcDir = null;
                if (strtolower((string)($p['source'] ?? 'upload')) !== 'url') {
                    $src = pageFolderContained($from, $p);
                    if ($src === null) throw new RuntimeException('The folder of ' . pageDisplayLabel($p) . ' resolves outside media/pages/');
                    if (is_dir($src)) {
                        $dst = pageFolderContained($to, ['slug' => $newSlug] + $p);
                        if ($dst === null) throw new RuntimeException('The target folder resolves outside media/pages/');
                        ensurePagesMediaHtaccess();
                        $manifest = assignCopyTree($src, $dst, mediaRootPath());
                        $copies[] = $dst;
                        if (!assignVerifyTree($src, $dst, $manifest)) throw new RuntimeException('The copied files of ' . pageDisplayLabel($p) . ' did not verify');
                        $out['files'] += count($manifest);
                        $srcDir = $src;
                    }
                }
                $plan[] = [$p, $newSlug, $srcDir];
            }

            $batch = newBatchId();
            $pdo->beginTransaction();
            foreach ($plan as [$p, $newSlug]) {
                $id = (int)$p['id'];
                $upd = $pdo->prepare("UPDATE pages SET company_id = ?, slug = ? WHERE id = ? AND company_id = ?");
                $upd->execute([$toCid, $newSlug, $id, $fromCid]);
                if ($upd->rowCount() !== 1) throw new RuntimeException('The page changed while it was being moved');
                assignRepointActivity($pdo, 'page', $id, $fromCid, $toCid);
                $label = pageDisplayLabel($p);
                logPageActivity($pdo, $actor, 'moved', $id, "Page {$label} moved from {$from['name']} to {$to['name']}",
                    $from['slug'] . ' → ' . $to['slug'] . ($newSlug !== $p['slug'] ? ' · /' . $p['slug'] . ' → /' . $newSlug : ''), $batch, $toCid);
                $item = ['id' => $id, 'slug' => $newSlug, 'label' => $label];
                if ($newSlug !== (string)$p['slug']) { $item['renamed_from'] = (string)$p['slug']; $out['renamed']++; }
                $out['items'][] = $item;
                $out['moved']++;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($copies as $c) assignRemoveTree($c);
            throw new RuntimeException($ex->getMessage(), 0, $ex);
        }
        foreach ($plan as [$p, , $srcDir]) {
            if ($srcDir === null) continue;
            $n = deletePageFolder($from, $p);
            if ($n < 0 || is_dir($srcDir)) { $out['cleanup_failed']++; error_log('assign: could not remove moved page folder ' . $srcDir); }
        }
        return $out;
    }
}
