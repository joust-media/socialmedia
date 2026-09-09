<?php
/**
 * Emails module — shared helpers (loaded by helpers.php; never include directly).
 *
 * Tables: emails, email_groups, email_group_map (migrate.php steps 19–21) plus the
 * 'emails' row in modules (step 22) that company_modules points at to enable the
 * Emails tab per client. Every query is gated on hasEmailsTable() so a deploy
 * that has not run migrate.php renders "no emails" instead of a 500.
 *
 * Status vocabulary (display key = what pages branch on):
 *   draft | pending | approved | denied  — emails.status while live = 0
 *   live                                 — emails.live = 1, whatever the status
 * Labels: Draft / To Review / Approved / Needs changes / Live.
 * Export labels (the client's spreadsheet): ⚪ Not Started / 🔴 Waiting Approval /
 * 🔵 Ready for Dev / 🟣 Update Design / 🟢 Active.
 *
 * All functions are function_exists-guarded and do no work at load. $pdo
 * arguments that default to null fall back to the global $pdo.
 */

if (!function_exists('emailsPdo')) {
    /** (internal) Resolve the PDO to use: the argument, else the global. */
    function emailsPdo(?PDO $pdo = null): ?PDO {
        if ($pdo instanceof PDO) return $pdo;
        $g = $GLOBALS['pdo'] ?? null;
        return $g instanceof PDO ? $g : null;
    }
}

if (!function_exists('hasEmailsTable')) {
    /** Does the emails table exist yet? (migrate.php may not have run.) Cached per request. */
    function hasEmailsTable(?PDO $pdo = null): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        $pdo = emailsPdo($pdo);
        if ($pdo === null) return false;   // not cached: a later call may have a PDO
        try {
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emails'
            ");
            $s->execute();
            return $cached = (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return $cached = false;
        }
    }
}

if (!function_exists('companyHasEmails')) {
    /**
     * Should this company see the Emails tab? True when the 'emails' module is
     * enabled for it (company_modules) OR at least one emails row exists, so the
     * tab appears as soon as data does. Cheap (two LIMIT 1 probes, fetch() not
     * COUNT) and cached per company id for the request.
     */
    function companyHasEmails(array $company, ?PDO $pdo = null): bool {
        static $cache = [];
        $cid = (int)($company['id'] ?? 0);
        if ($cid <= 0) return false;
        if (array_key_exists($cid, $cache)) return $cache[$cid];
        $pdo = emailsPdo($pdo);
        if ($pdo === null || !hasEmailsTable($pdo)) return false;
        try {
            $s = $pdo->prepare("
                SELECT cm.company_id
                  FROM company_modules cm
                 INNER JOIN modules m ON m.id = cm.module_id
                 WHERE cm.company_id = ? AND m.slug = 'emails'
                 LIMIT 1
            ");
            $s->execute([$cid]);
            if ($s->fetch()) return $cache[$cid] = true;
            $s = $pdo->prepare("SELECT id FROM emails WHERE company_id = ? LIMIT 1");
            $s->execute([$cid]);
            return $cache[$cid] = (bool)$s->fetch();
        } catch (Throwable $e) {
            error_log('companyHasEmails failed: ' . $e->getMessage());
            return $cache[$cid] = false;
        }
    }
}

// ---------------------------------------------------------------------
// Status vocabulary
// ---------------------------------------------------------------------

if (!function_exists('emailStatusKeys')) {
    function emailStatusKeys(): array {
        return ['draft', 'pending', 'approved', 'denied', 'live'];
    }
}

if (!function_exists('emailStatusKey')) {
    /** Display key for a row: live=1 → 'live', else its status (unknown → 'draft'). */
    function emailStatusKey(array $email): string {
        if (!empty($email['live'])) return 'live';
        $s = strtolower(trim((string)($email['status'] ?? '')));
        return in_array($s, ['draft', 'pending', 'approved', 'denied'], true) ? $s : 'draft';
    }
}

if (!function_exists('emailStatusLabelForKey')) {
    function emailStatusLabelForKey(string $key): string {
        static $map = [
            'draft'    => 'Draft',
            'pending'  => 'To Review',
            'approved' => 'Approved',
            'denied'   => 'Needs changes',
            'live'     => 'Live',
        ];
        return $map[strtolower(trim($key))] ?? 'Draft';
    }
}

if (!function_exists('emailStatusLabel')) {
    function emailStatusLabel(array $email): string {
        return emailStatusLabelForKey(emailStatusKey($email));
    }
}

if (!function_exists('emailStatusPill')) {
    /**
     * statusPill() for an email: live → the green "scheduled" pill (data-status=posted)
     * labelled Live; draft → neutral pill; the other three map 1:1 to the posts pills.
     * $opts pass through to statusPill() ('class', 'dot', 'attrs'; 'label' overrides).
     */
    function emailStatusPill(array $email, array $opts = []): string {
        $key  = emailStatusKey($email);
        $live = $key === 'live';
        $opts['label'] = $opts['label'] ?? emailStatusLabelForKey($key);
        return statusPill($live ? 'approved' : $key, $live, $opts);
    }
}

if (!function_exists('emailExportStatusLabel')) {
    /** The spreadsheet's status cell for a display key (emoji included). */
    function emailExportStatusLabel(string $key): string {
        static $map = [
            'draft'    => '⚪ Not Started',
            'pending'  => '🔴 Waiting Approval',
            'approved' => '🔵 Ready for Dev',
            'denied'   => '🟣 Update Design',
            'live'     => '🟢 Active',
        ];
        return $map[strtolower(trim($key))] ?? $map['draft'];
    }
}

if (!function_exists('emailStatusFromLabel')) {
    /**
     * Parse a status cell from the sheet (emoji optional, case-insensitive, trimmed).
     * Returns ['status' => draft|pending|approved|denied, 'live' => 0|1, 'known' => bool].
     * "Active"/"Live" → approved + live; "Unknown"/blank → draft; anything else → draft, known=false.
     */
    function emailStatusFromLabel(?string $label): array {
        $t = (string)$label;
        // Keep letters/digits only (drops emoji, punctuation, NBSP); collapse to single spaces.
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
        $t = strtolower(trim(preg_replace('/\s+/', ' ', (string)$t)));
        static $map = [
            ''                 => ['draft', 0],
            'not started'      => ['draft', 0],
            'draft'            => ['draft', 0],
            'unknown'          => ['draft', 0],
            'waiting approval' => ['pending', 0],
            'waiting'          => ['pending', 0],
            'to review'        => ['pending', 0],
            'pending'          => ['pending', 0],
            'review'           => ['pending', 0],
            'ready for dev'    => ['approved', 0],
            'ready'            => ['approved', 0],
            'approved'         => ['approved', 0],
            'update design'    => ['denied', 0],
            'needs changes'    => ['denied', 0],
            'changes'          => ['denied', 0],
            'denied'           => ['denied', 0],
            'active'           => ['approved', 1],
            'live'             => ['approved', 1],
        ];
        if (isset($map[$t])) {
            return ['status' => $map[$t][0], 'live' => $map[$t][1], 'known' => true];
        }
        return ['status' => 'draft', 'live' => 0, 'known' => false];
    }
}

if (!function_exists('emailIsPast')) {
    /** Presentational: live and send_at strictly before today (server date). Null date → never past. */
    function emailIsPast(array $email): bool {
        if (empty($email['live'])) return false;
        $raw = trim((string)($email['send_at'] ?? ''));
        if ($raw === '' || $raw === '0000-00-00') return false;
        $ts = strtotime($raw);
        if ($ts === false) return false;
        return date('Y-m-d', $ts) < date('Y-m-d');
    }
}

// ---------------------------------------------------------------------
// Small value helpers
// ---------------------------------------------------------------------

if (!function_exists('emailPriorityLabel')) {
    function emailPriorityLabel(?string $priority): string {
        $p = strtolower(trim((string)$priority));
        return in_array($p, ['low', 'medium', 'high'], true) ? ucfirst($p) : '';
    }
}

if (!function_exists('emailPriorityFromLabel')) {
    /** 'High' / ' medium ' / 'LOW' → enum value; anything else → null. */
    function emailPriorityFromLabel(?string $label): ?string {
        $p = strtolower(trim((string)$label));
        $p = preg_replace('/[^a-z]/', '', $p);
        return in_array($p, ['low', 'medium', 'high'], true) ? $p : null;
    }
}

if (!function_exists('emailNormalizeCode')) {
    /** Canonical form of the sheet's ID: trimmed, inner whitespace collapsed, uppercase. */
    function emailNormalizeCode(string $code): string {
        $code = trim(preg_replace('/\s+/', ' ', $code));
        return function_exists('mb_strtoupper') ? mb_strtoupper($code, 'UTF-8') : strtoupper($code);
    }
}

if (!function_exists('emailSlugify')) {
    /** 'Free trial' → 'free-trial'; '' when nothing survives. Max 80 chars (email_groups.slug). */
    function emailSlugify(string $name): string {
        $s = strtolower(trim($name));
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) $s = $t;
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim(preg_replace('/-+/', '-', $s), '-');
        return substr($s, 0, 80);
    }
}

if (!function_exists('emailDisplayLabel')) {
    /** 'C1 · Welcome to Privacy Bee' — code alone when the title is blank; 'email #N' when both are. */
    function emailDisplayLabel(array $email): string {
        $code  = trim((string)($email['code'] ?? ''));
        $title = trim((string)($email['title'] ?? ''));
        if ($code !== '' && $title !== '') return $code . ' · ' . $title;
        if ($code !== '') return $code;
        if ($title !== '') return $title;
        return 'email #' . (int)($email['id'] ?? 0);
    }
}

if (!function_exists('emailCleanCell')) {
    /** Import cell hygiene: trim, CRLF → LF, and the sheet's '#ERROR!' / 'Active 👍' placeholders → ''. */
    function emailCleanCell($value): string {
        $v = str_replace(["\r\n", "\r"], "\n", (string)$value);
        $v = trim($v);
        if ($v === '') return '';
        $flat = strtolower(trim(preg_replace('/[^\p{L}\p{N}#!]+/u', ' ', $v)));
        if ($flat === '#error!' || $flat === 'active') return '';
        return $v;
    }
}

// ---------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------

if (!function_exists('emailsAttachGroups')) {
    /** Add 'groups' => [['id','name','slug'], …] to each row (by row['id']) in one query. */
    function emailsAttachGroups(PDO $pdo, array $rows): array {
        $ids = [];
        foreach ($rows as $r) { if (!empty($r['id'])) $ids[] = (int)$r['id']; }
        $ids = array_values(array_unique($ids));
        $byEmail = [];
        if ($ids && hasEmailsTable($pdo)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("
                SELECT gm.email_id, g.id, g.name, g.slug
                  FROM email_group_map gm
                 INNER JOIN email_groups g ON g.id = gm.group_id
                 WHERE gm.email_id IN ($ph)
                 ORDER BY g.sort_order ASC, g.name ASC
            ");
            $s->execute($ids);
            foreach ($s->fetchAll() as $g) {
                $byEmail[(int)$g['email_id']][] = [
                    'id'   => (int)$g['id'],
                    'name' => (string)$g['name'],
                    'slug' => (string)$g['slug'],
                ];
            }
        }
        foreach ($rows as &$r) {
            $r['groups'] = $byEmail[(int)($r['id'] ?? 0)] ?? [];
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('emailsSortRows')) {
    /** (internal) sort_order ASC, then natural code order (C1, C2, C10), then id. Stable. */
    function emailsSortRows(array $rows): array {
        usort($rows, static function ($a, $b) {
            $c = ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
            if ($c !== 0) return $c;
            $c = strnatcasecmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
            if ($c !== 0) return $c;
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });
        return array_values($rows);
    }
}

if (!function_exists('emailsForCompany')) {
    /**
     * Emails for one company with groups attached.
     *   $opts['status']    draft|pending|approved|denied|live|all (default all); the four
     *                      statuses imply live = 0, 'live' means live = 1 whatever the status.
     *   $opts['group']     group slug or list of slugs — rows in ANY of them.
     *   $opts['q']         substring over code / title / subject.
     *   $opts['visibleTo'] 'admin' (default, everything) or 'client' (live = 1 OR status IN
     *                      pending, approved — drafts and Needs-changes rows are hidden in SQL).
     * Ordered by sort_order, then natural code order.
     */
    function emailsForCompany(PDO $pdo, int $companyId, array $opts = []): array {
        if ($companyId <= 0 || !hasEmailsTable($pdo)) return [];
        $where  = ['e.company_id = ?'];
        $params = [$companyId];

        $status = strtolower(trim((string)($opts['status'] ?? 'all')));
        if ($status === 'live') {
            $where[] = 'e.live = 1';
        } elseif (in_array($status, ['draft', 'pending', 'approved', 'denied'], true)) {
            $where[]  = 'e.status = ?';
            $params[] = $status;
            $where[]  = 'e.live = 0';
        }

        if (($opts['visibleTo'] ?? 'admin') === 'client') {
            $where[] = "(e.live = 1 OR e.status IN ('pending','approved'))";
        }

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(e.code LIKE ? OR e.title LIKE ? OR e.subject LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $groups = $opts['group'] ?? null;
        $groups = is_array($groups) ? $groups : ($groups === null || $groups === '' ? [] : [$groups]);
        $groups = array_values(array_filter(array_map(static function ($g) {
            return emailSlugify((string)$g);
        }, $groups), static function ($g) { return $g !== ''; }));
        if ($groups) {
            $ph = implode(',', array_fill(0, count($groups), '?'));
            $where[] = "e.id IN (
                SELECT gm.email_id FROM email_group_map gm
                 INNER JOIN email_groups g ON g.id = gm.group_id
                 WHERE g.company_id = ? AND g.slug IN ($ph)
            )";
            $params[] = $companyId;
            foreach ($groups as $g) $params[] = $g;
        }

        $s = $pdo->prepare("SELECT e.* FROM emails e WHERE " . implode(' AND ', $where)
                         . " ORDER BY e.sort_order ASC, e.code ASC");
        $s->execute($params);
        $rows = emailsSortRows($s->fetchAll());
        return emailsAttachGroups($pdo, $rows);
    }
}

if (!function_exists('emailById')) {
    /** One row (with groups) or null. Callers scope with company_id / clientOwnsCompany() themselves. */
    function emailById(PDO $pdo, int $id): ?array {
        if ($id <= 0 || !hasEmailsTable($pdo)) return null;
        $s = $pdo->prepare("SELECT * FROM emails WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) return null;
        $rows = emailsAttachGroups($pdo, [$row]);
        return $rows[0];
    }
}

if (!function_exists('emailByCode')) {
    /** Row (with groups) matching the normalised code within the company, or null. */
    function emailByCode(PDO $pdo, int $companyId, string $code): ?array {
        $code = emailNormalizeCode($code);
        if ($companyId <= 0 || $code === '' || !hasEmailsTable($pdo)) return null;
        $s = $pdo->prepare("SELECT * FROM emails WHERE company_id = ? AND code = ?");
        $s->execute([$companyId, $code]);
        $row = $s->fetch();
        if (!$row) return null;
        $rows = emailsAttachGroups($pdo, [$row]);
        return $rows[0];
    }
}

if (!function_exists('emailGroupsForCompany')) {
    /** All groups for a company, sort_order then name. */
    function emailGroupsForCompany(PDO $pdo, int $companyId): array {
        if ($companyId <= 0 || !hasEmailsTable($pdo)) return [];
        $s = $pdo->prepare("
            SELECT id, company_id, name, slug, sort_order
              FROM email_groups
             WHERE company_id = ?
             ORDER BY sort_order ASC, name ASC
        ");
        $s->execute([$companyId]);
        $out = [];
        foreach ($s->fetchAll() as $g) {
            $g['id'] = (int)$g['id'];
            $g['company_id'] = (int)$g['company_id'];
            $g['sort_order'] = (int)$g['sort_order'];
            $out[] = $g;
        }
        return $out;
    }
}

if (!function_exists('ensureEmailGroup')) {
    /** Find-or-create a group by name (matched on slug). Returns its id, or 0 when the name slugs to ''. */
    function ensureEmailGroup(PDO $pdo, int $companyId, string $name): int {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $slug = emailSlugify($name);
        if ($companyId <= 0 || $slug === '' || !hasEmailsTable($pdo)) return 0;
        $existing = emailGroupsForCompany($pdo, $companyId);
        $max = 0;
        foreach ($existing as $g) {
            if ($g['slug'] === $slug) return (int)$g['id'];
            $max = max($max, (int)$g['sort_order']);
        }
        $ins = $pdo->prepare("INSERT INTO email_groups (company_id, name, slug, sort_order) VALUES (?, ?, ?, ?)");
        $ins->execute([$companyId, mb_substr($name, 0, 80), $slug, $max + 1]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('setEmailGroups')) {
    /** Replace an email's group set (DELETE + INSERT IGNORE per id). Order is irrelevant (groups sort by sort_order). */
    function setEmailGroups(PDO $pdo, int $emailId, array $groupIds): void {
        if ($emailId <= 0 || !hasEmailsTable($pdo)) return;
        $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static function ($i) { return $i > 0; })));
        $pdo->prepare("DELETE FROM email_group_map WHERE email_id = ?")->execute([$emailId]);
        if (!$ids) return;
        $ins = $pdo->prepare("INSERT IGNORE INTO email_group_map (email_id, group_id) VALUES (?, ?)");
        foreach ($ids as $gid) $ins->execute([$emailId, $gid]);
    }
}

if (!function_exists('emailCounts')) {
    /** Per display key: draft, pending, approved, denied, live (live=1 rows count only as live) + total. */
    function emailCounts(PDO $pdo, int $companyId): array {
        $out = ['draft' => 0, 'pending' => 0, 'approved' => 0, 'denied' => 0, 'live' => 0, 'total' => 0];
        if ($companyId <= 0 || !hasEmailsTable($pdo)) return $out;
        $s = $pdo->prepare("SELECT status, live, COUNT(*) AS n FROM emails WHERE company_id = ? GROUP BY status, live");
        $s->execute([$companyId]);
        foreach ($s->fetchAll() as $r) {
            $n = (int)($r['n'] ?? 0);
            $key = emailStatusKey(['status' => $r['status'] ?? '', 'live' => $r['live'] ?? 0]);
            $out[$key] += $n;
            $out['total'] += $n;
        }
        return $out;
    }
}

if (!function_exists('emailLatestNotes')) {
    /** Newest 'commented' row per email: [email_id => ['detail','actor','created_at']]. */
    function emailLatestNotes(PDO $pdo, array $emailIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $emailIds))));
        if (!$ids || !function_exists('hasActivityLog') || !hasActivityLog($pdo)) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("
            SELECT entity_id, actor, detail, created_at
              FROM activity_log
             WHERE entity_type = 'email' AND action = 'commented'
               AND detail IS NOT NULL AND detail <> ''
               AND entity_id IN ($ph)
             ORDER BY created_at DESC, id DESC
        ");
        $s->execute($ids);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $eid = (int)$r['entity_id'];
            if (isset($out[$eid])) continue;   // rows are newest first
            $out[$eid] = ['detail' => (string)$r['detail'], 'actor' => (string)$r['actor'], 'created_at' => (string)$r['created_at']];
        }
        return $out;
    }
}

if (!function_exists('logEmailActivity')) {
    /**
     * activity_log row with entity_type 'email'. company_id is read from the row
     * when not supplied. Never throws (logActivity() swallows failures).
     */
    function logEmailActivity(PDO $pdo, string $actor, string $action, int $emailId, string $summary,
                              ?string $detail = null, ?string $batchId = null, ?int $companyId = null): void {
        if ($companyId === null || $companyId <= 0) {
            $companyId = 0;
            try {
                $s = $pdo->prepare("SELECT company_id FROM emails WHERE id = ?");
                $s->execute([$emailId]);
                $companyId = (int)$s->fetchColumn();
            } catch (Throwable $e) {
                $companyId = 0;
            }
        }
        logActivity($pdo, $companyId, 'email', $emailId, $action, $actor, $summary, $detail, $batchId);
    }
}

// ---------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------

if (!function_exists('emailsUrl')) {
    /** emails.php URL in the current client scope (pass 'client' => slug to override). */
    function emailsUrl(array $params = []): string {
        return clientUrl('emails.php', $params);
    }
}

if (!function_exists('emailUrl')) {
    /** Deep link to one email's detail (emails.php?client=…&email=ID). */
    function emailUrl(array $email, array $params = []): string {
        return emailsUrl(['email' => (int)($email['id'] ?? 0)] + $params);
    }
}

// ---------------------------------------------------------------------
// CSV contract (see emails-design.md §7)
// ---------------------------------------------------------------------

if (!function_exists('emailCsvColumns')) {
    function emailCsvColumns(): array {
        return ['Status', 'ID', 'Title', 'Sequence', 'Trigger', 'Subject Line', 'Preview Text',
                'View Email', 'URL', 'Priority', 'Groups', 'Latest Note', 'Updated'];
    }
}

if (!function_exists('emailCsvRow')) {
    /** One export row (values in emailCsvColumns() order). $ctx['latest_note'] optional. */
    function emailCsvRow(array $email, array $ctx = []): array {
        $groups = array_values(array_filter(array_map(static function ($g) {
            return trim((string)(is_array($g) ? ($g['name'] ?? '') : $g));
        }, (array)($email['groups'] ?? [])), static function ($n) { return $n !== ''; }));
        $updated = trim((string)($email['updated_at'] ?? ''));
        $ts = $updated !== '' ? strtotime($updated) : false;
        return [
            emailExportStatusLabel(emailStatusKey($email)),
            (string)($email['code'] ?? ''),
            (string)($email['title'] ?? ''),
            $groups[0] ?? '',
            str_replace(["\r\n", "\r"], "\n", (string)($email['trigger_text'] ?? '')),
            (string)($email['subject'] ?? ''),
            (string)($email['preview_text'] ?? ''),
            'View Email',
            (string)($email['html_url'] ?? ''),
            emailPriorityLabel($email['priority'] ?? null),
            implode('|', $groups),
            (string)($ctx['latest_note'] ?? ''),
            $ts ? date('Y-m-d H:i', $ts) : '',
        ];
    }
}
