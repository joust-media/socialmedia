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

// =====================================================================
// Admin helpers (Studio → Emails, add-email.php, emails-io.php).
// Additive, function_exists-guarded, no work at load. See emails-design.md §7.
// =====================================================================

if (!function_exists('emailImportFields')) {
    /** Scalar email columns the importer / editor may set (status, live and groups are handled separately). */
    function emailImportFields(): array {
        return ['title', 'html_url', 'subject', 'preview_text', 'trigger_text', 'send_at', 'priority', 'notes'];
    }
}

if (!function_exists('emailFieldLabel')) {
    /** Human label for a field / change key ('preview_text' → 'Preview text'). */
    function emailFieldLabel(string $key): string {
        static $map = [
            'code' => 'ID', 'title' => 'Title', 'html_url' => 'HTML URL', 'subject' => 'Subject line',
            'preview_text' => 'Preview text', 'trigger_text' => 'Trigger', 'send_at' => 'Send date',
            'priority' => 'Priority', 'notes' => 'Notes', 'status' => 'Status', 'groups' => 'Groups', 'live' => 'Live',
        ];
        return $map[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}

if (!function_exists('emailActivityFieldKey')) {
    /** activity_log field token for edited_<field> / imported detail ('preview_text' → 'preview'). */
    function emailActivityFieldKey(string $key): string {
        static $map = ['preview_text' => 'preview', 'trigger_text' => 'trigger'];
        return $map[$key] ?? $key;
    }
}

if (!function_exists('emailCsvHeaderMap')) {
    /**
     * Normalised CSV header → canonical import key. null = column is ignored on import
     * (View Email, Latest Note, Updated are export-only). Aliases cover the JSON field names
     * so a hand-made sheet with "html_url" / "trigger_text" headers also imports.
     */
    function emailCsvHeaderMap(): array {
        return [
            'status' => 'status',
            'id' => 'code', 'code' => 'code',
            'title' => 'title',
            'sequence' => 'sequence',
            'trigger' => 'trigger_text', 'trigger text' => 'trigger_text', 'trigger_text' => 'trigger_text',
            'subject line' => 'subject', 'subject' => 'subject',
            'preview text' => 'preview_text', 'preview' => 'preview_text', 'preview_text' => 'preview_text',
            'view email' => null,
            'url' => 'html_url', 'html url' => 'html_url', 'html_url' => 'html_url',
            'priority' => 'priority',
            'groups' => 'groups',
            'latest note' => null, 'updated' => null,
            'send date' => 'send_at', 'send_at' => 'send_at', 'send at' => 'send_at',
            'notes' => 'notes',
        ];
    }
}

if (!function_exists('emailImportParseCsv')) {
    /**
     * Parse CSV text (BOM, CRLF and quoted multi-line cells handled by fgetcsv).
     * Returns ['records' => [['line' => n, 'cells' => [key => raw]], …], 'columns' => [keys present], 'error' => ?string].
     */
    function emailImportParseCsv(string $text): array {
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $text);
        rewind($h);
        $map = emailCsvHeaderMap();
        $columns = null;        // index → canonical key (null = ignored)
        $present = [];
        $records = [];
        $line = 0;
        while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $line++;
            if ($row === [null] || $row === [''] || !array_filter($row, static function ($c) { return trim((string)$c) !== ''; })) continue;
            if ($columns === null) {
                $columns = [];
                foreach ($row as $i => $head) {
                    $k = strtolower(trim(preg_replace('/\s+/', ' ', (string)$head)));
                    $k = trim($k, "\xEF\xBB\xBF \t");
                    $canon = array_key_exists($k, $map) ? $map[$k] : null;
                    $columns[$i] = $canon;
                    if ($canon !== null && !in_array($canon, $present, true)) $present[] = $canon;
                }
                if (!in_array('code', $present, true)) {
                    fclose($h);
                    return ['records' => [], 'columns' => $present, 'error' => 'The file has no "ID" column — is the header row missing?'];
                }
                continue;
            }
            $cells = [];
            foreach ($columns as $i => $canon) {
                if ($canon === null) continue;
                $cells[$canon] = (string)($row[$i] ?? '');
            }
            $records[] = ['line' => $line, 'cells' => $cells];
        }
        fclose($h);
        if ($columns === null) return ['records' => [], 'columns' => [], 'error' => 'The file is empty.'];
        return ['records' => $records, 'columns' => $present, 'error' => null];
    }
}

if (!function_exists('emailImportParseJson')) {
    /** Parse the JSON export shape ({emails: [...]}, or a bare list of email objects). Same return shape as the CSV parser. */
    function emailImportParseJson(string $text): array {
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3);
        $data = json_decode($text, true);
        if (!is_array($data)) return ['records' => [], 'columns' => [], 'error' => 'Not valid JSON.'];
        $list = array_is_list($data) ? $data : ($data['emails'] ?? null);
        if (!is_array($list)) return ['records' => [], 'columns' => [], 'error' => 'JSON has no "emails" list.'];
        $alias = [
            'code' => 'code', 'id' => 'code', 'title' => 'title', 'html_url' => 'html_url', 'url' => 'html_url',
            'subject' => 'subject', 'subject_line' => 'subject', 'preview_text' => 'preview_text', 'preview' => 'preview_text',
            'trigger_text' => 'trigger_text', 'trigger' => 'trigger_text', 'send_at' => 'send_at', 'send_date' => 'send_at',
            'priority' => 'priority', 'status' => 'status', 'live' => 'live', 'notes' => 'notes', 'groups' => 'groups', 'sequence' => 'sequence',
        ];
        $records = [];
        $present = [];
        foreach ($list as $i => $obj) {
            if (!is_array($obj)) continue;
            $cells = [];
            foreach ($obj as $k => $v) {
                $k = strtolower(trim((string)$k));
                if (!isset($alias[$k])) continue;
                $canon = $alias[$k];
                if ($canon === 'groups') {
                    $v = is_array($v) ? implode('|', array_map(static function ($g) { return is_array($g) ? ($g['name'] ?? '') : (string)$g; }, $v)) : (string)$v;
                } elseif ($canon === 'live') {
                    $v = $v ? '1' : '0';
                } elseif (is_array($v)) {
                    continue;
                } else {
                    $v = $v === null ? '' : (string)$v;
                }
                $cells[$canon] = $v;
                if (!in_array($canon, $present, true)) $present[] = $canon;
            }
            $records[] = ['line' => (int)$i + 1, 'cells' => $cells];
        }
        if (!in_array('code', $present, true)) return ['records' => [], 'columns' => $present, 'error' => 'No email has a "code".'];
        return ['records' => $records, 'columns' => $present, 'error' => null];
    }
}

if (!function_exists('emailImportNormalizeRows')) {
    /**
     * Turn parsed records into import rows (design §7): trims + emailCleanCell() on every
     * cell except Status, ID normalised, blank ID → skip, duplicate ID → first wins,
     * Status via emailStatusFromLabel() (unknown → draft, warned), Priority via
     * emailPriorityFromLabel(), Sequence + Groups → group names.
     *
     * Row shape: ['line', 'code', 'action' (skip|duplicate|null), 'message', 'fields' => [key => value|null]
     *            (only columns present), 'status' => ['status','live','known']|null, 'status_blank' => bool,
     *            'groups' => [names]|null, 'warnings' => [...]]
     */
    function emailImportNormalizeRows(array $records, array $columns): array {
        $out  = [];
        $seen = [];
        $hasGroups = in_array('groups', $columns, true) || in_array('sequence', $columns, true);
        foreach ($records as $rec) {
            $cells = $rec['cells'] ?? [];
            $row = ['line' => (int)($rec['line'] ?? 0), 'code' => '', 'action' => null, 'message' => '',
                    'fields' => [], 'status' => null, 'status_blank' => false, 'groups' => null, 'warnings' => []];
            $code = emailNormalizeCode(emailCleanCell($cells['code'] ?? ''));
            $row['code'] = $code;
            if ($code === '') {
                $row['action'] = 'skip';
                $row['message'] = 'Blank ID';
                $out[] = $row;
                continue;
            }
            if (isset($seen[$code])) {
                $row['action'] = 'duplicate';
                $row['message'] = 'Duplicate ID — line ' . $seen[$code] . ' wins';
                $out[] = $row;
                continue;
            }
            $seen[$code] = $row['line'];

            foreach (['title', 'html_url', 'subject', 'preview_text', 'trigger_text', 'notes'] as $f) {
                if (array_key_exists($f, $cells)) $row['fields'][$f] = emailCleanCell($cells[$f]);
            }
            if (array_key_exists('send_at', $cells)) {
                $raw = emailCleanCell($cells['send_at']);
                $val = null;
                if ($raw !== '') {
                    $ts = strtotime($raw);
                    if ($ts === false) $row['warnings'][] = 'Unrecognised send date "' . $raw . '" ignored';
                    else $val = date('Y-m-d', $ts);
                }
                $row['fields']['send_at'] = $val;
            }
            if (array_key_exists('priority', $cells)) {
                $raw = emailCleanCell($cells['priority']);
                $p = emailPriorityFromLabel($raw);
                if ($raw !== '' && $p === null) $row['warnings'][] = 'Unknown priority "' . $raw . '" → blank';
                $row['fields']['priority'] = $p;
            }
            if (array_key_exists('status', $cells) || array_key_exists('live', $cells)) {
                $raw = trim((string)($cells['status'] ?? ''));
                $parsed = emailStatusFromLabel($raw);
                if (!$parsed['known']) $row['warnings'][] = 'Unknown status "' . $raw . '" → Draft';
                if (array_key_exists('live', $cells) && $cells['live'] === '1') {
                    $parsed['live'] = 1;
                    if ($raw === '') $parsed['status'] = 'approved';
                }
                $row['status_blank'] = ($raw === '' && !array_key_exists('live', $cells));
                $row['status'] = ['status' => $parsed['status'], 'live' => (int)$parsed['live'], 'known' => (bool)$parsed['known']];
            }
            if ($hasGroups) {
                $names = [];
                if (array_key_exists('groups', $cells)) {
                    foreach (explode('|', emailCleanCell($cells['groups'])) as $g) {
                        $g = trim(preg_replace('/\s+/', ' ', $g));
                        if ($g !== '' && emailSlugify($g) !== '') $names[] = $g;
                    }
                }
                $seq = array_key_exists('sequence', $cells) ? trim(preg_replace('/\s+/', ' ', emailCleanCell($cells['sequence']))) : '';
                if ($seq !== '' && emailSlugify($seq) !== '') {
                    $slugs = array_map('emailSlugify', $names);
                    if (!in_array(emailSlugify($seq), $slugs, true)) array_unshift($names, $seq);
                }
                // de-dupe by slug, keep first spelling
                $uniq = []; $keep = [];
                foreach ($names as $n) { $s = emailSlugify($n); if (isset($uniq[$s])) continue; $uniq[$s] = 1; $keep[] = $n; }
                $row['groups'] = $keep;
            }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('emailImportDiff')) {
    /**
     * Compare normalised rows with this company's emails (matched on code). Adds to each row:
     * 'action' create|update|unchanged|skip|duplicate, 'changes' => [field => [old, new]], 'existing_id', 'label'.
     * Returns ['rows' => …, 'summary' => [create, update, unchanged, skipped, duplicates, unknown_status, warnings]].
     * No writes.
     */
    function emailImportDiff(PDO $pdo, int $companyId, array $rows): array {
        $existing = [];
        foreach (emailsForCompany($pdo, $companyId) as $e) $existing[emailNormalizeCode((string)$e['code'])] = $e;
        $summary = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'skipped' => 0, 'duplicates' => 0, 'unknown_status' => 0, 'warnings' => 0];
        foreach ($rows as &$row) {
            $row['changes'] = [];
            $row['existing_id'] = 0;
            $row['label'] = $row['code'];
            if ($row['action'] === 'skip')      { $summary['skipped']++; continue; }
            if ($row['action'] === 'duplicate') { $summary['duplicates']++; continue; }
            if (!empty($row['status']) && empty($row['status']['known'])) $summary['unknown_status']++;
            if (!empty($row['warnings'])) $summary['warnings'] += count($row['warnings']);
            $e = $existing[$row['code']] ?? null;
            $changes = [];
            if ($e) {
                $row['existing_id'] = (int)$e['id'];
                $row['label'] = emailDisplayLabel($e);
                foreach ($row['fields'] as $f => $new) {
                    $old = (string)($e[$f] ?? '');
                    $new = (string)($new ?? '');
                    if ($f === 'trigger_text' || $f === 'notes' || $f === 'preview_text') $old = str_replace(["\r\n", "\r"], "\n", $old);
                    if ($f === 'send_at' && $old === '0000-00-00') $old = '';
                    if ($f === 'priority') { $old = strtolower($old); $new = strtolower($new); }
                    if ($old !== $new) $changes[$f] = [$old, $new];
                }
                if ($row['status'] !== null && !$row['status_blank']) {
                    $oldKey = emailStatusKey($e);
                    $newKey = $row['status']['live'] ? 'live' : $row['status']['status'];
                    if ($oldKey !== $newKey) $changes['status'] = [emailStatusLabelForKey($oldKey), emailStatusLabelForKey($newKey)];
                }
                if ($row['groups'] !== null) {
                    $oldNames = array_map(static function ($g) { return (string)$g['name']; }, $e['groups'] ?? []);
                    $oldSlugs = array_map('emailSlugify', $oldNames); sort($oldSlugs);
                    $newSlugs = array_map('emailSlugify', $row['groups']); sort($newSlugs);
                    if ($oldSlugs !== $newSlugs) $changes['groups'] = [implode('|', $oldNames), implode('|', $row['groups'])];
                }
                $row['action'] = $changes ? 'update' : 'unchanged';
                if (isset($changes['title']) && $changes['title'][1] !== '') $row['label'] = $row['code'] . ' · ' . $changes['title'][1];   // name it as it will be after the import
            } else {
                foreach ($row['fields'] as $f => $new) {
                    if ((string)($new ?? '') !== '') $changes[$f] = ['', (string)$new];
                }
                $key = $row['status'] ? ($row['status']['live'] ? 'live' : $row['status']['status']) : 'draft';
                $changes['status'] = ['', emailStatusLabelForKey($key)];
                if ($row['groups']) $changes['groups'] = ['', implode('|', $row['groups'])];
                if (!empty($row['fields']['title'])) $row['label'] = $row['code'] . ' · ' . $row['fields']['title'];
                $row['action'] = 'create';
            }
            $row['changes'] = $changes;
            $summary[$row['action']]++;
        }
        unset($row);
        return ['rows' => $rows, 'summary' => $summary];
    }
}

if (!function_exists('emailImportApply')) {
    /**
     * Apply normalised rows in ONE transaction: diff against the live table (again, so a
     * stale preview cannot clobber newer edits), INSERT creates, UPDATE only the changed
     * columns, set groups (creating missing ones), and log 'created' (detail "via import")
     * or 'imported' per changed email under one batch id. Never touches other companies.
     * Returns ['summary' => …, 'batch_id' => …, 'rows' => …].
     */
    function emailImportApply(PDO $pdo, int $companyId, array $rows, string $actor = 'admin', ?string $batchId = null): array {
        $batchId = $batchId ?: newBatchId();
        $diff = emailImportDiff($pdo, $companyId, $rows);
        $now  = date('Y-m-d H:i:s');
        $own  = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            foreach ($diff['rows'] as &$row) {
                if ($row['action'] === 'create') {
                    $f = $row['fields'];
                    $st = $row['status'] ?: ['status' => 'draft', 'live' => 0];
                    $ins = $pdo->prepare("
                        INSERT INTO emails (company_id, code, title, html_url, subject, preview_text, trigger_text, send_at, priority, status, live, live_at, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([
                        $companyId, $row['code'],
                        mb_substr((string)($f['title'] ?? ''), 0, 255), mb_substr((string)($f['html_url'] ?? ''), 0, 512),
                        mb_substr((string)($f['subject'] ?? ''), 0, 255),
                        ($f['preview_text'] ?? '') === '' ? null : $f['preview_text'],
                        ($f['trigger_text'] ?? '') === '' ? null : $f['trigger_text'],
                        $f['send_at'] ?? null, $f['priority'] ?? null,
                        $st['status'], (int)$st['live'], $st['live'] ? $now : null,
                        ($f['notes'] ?? '') === '' ? null : $f['notes'],
                    ]);
                    $id = (int)$pdo->lastInsertId();
                    $row['existing_id'] = $id;
                    if ($row['groups']) {
                        $gids = [];
                        foreach ($row['groups'] as $g) { $gid = ensureEmailGroup($pdo, $companyId, $g); if ($gid) $gids[] = $gid; }
                        setEmailGroups($pdo, $id, $gids);
                    }
                    logEmailActivity($pdo, $actor, 'created', $id, 'Email ' . $row['label'] . ' created', 'via import', $batchId, $companyId);
                } elseif ($row['action'] === 'update') {
                    $id   = (int)$row['existing_id'];
                    $sets = []; $vals = [];
                    foreach ($row['changes'] as $field => $pair) {
                        if ($field === 'groups') continue;
                        if ($field === 'status') {
                            $st = $row['status'];
                            $sets[] = 'status = ?'; $vals[] = $st['status'];
                            $sets[] = 'live = ?';   $vals[] = (int)$st['live'];
                            $sets[] = 'live_at = ?'; $vals[] = $st['live'] ? $now : null;
                            continue;
                        }
                        $new = $row['fields'][$field] ?? null;
                        if (in_array($field, ['preview_text', 'trigger_text', 'notes', 'send_at', 'priority'], true) && ($new === '' || $new === null)) $new = null;
                        if ($field === 'title' || $field === 'subject') $new = mb_substr((string)$new, 0, 255);
                        if ($field === 'html_url') $new = mb_substr((string)$new, 0, 512);
                        $sets[] = $field . ' = ?'; $vals[] = $new;
                    }
                    if ($sets) {
                        $vals[] = $id; $vals[] = $companyId;
                        $pdo->prepare('UPDATE emails SET ' . implode(', ', $sets) . ' WHERE id = ? AND company_id = ?')->execute($vals);
                    }
                    if (isset($row['changes']['groups'])) {
                        $gids = [];
                        foreach ($row['groups'] as $g) { $gid = ensureEmailGroup($pdo, $companyId, $g); if ($gid) $gids[] = $gid; }
                        setEmailGroups($pdo, $id, $gids);
                    }
                    $keys = array_map('emailActivityFieldKey', array_keys($row['changes']));
                    logEmailActivity($pdo, $actor, 'imported', $id,
                        'Imported ' . $row['label'] . ' (' . count($keys) . ' field' . (count($keys) === 1 ? '' : 's') . ')',
                        implode(', ', $keys), $batchId, $companyId);
                }
            }
            unset($row);
            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['summary' => $diff['summary'], 'batch_id' => $batchId, 'rows' => $diff['rows']];
    }
}

if (!function_exists('emailImportSummaryText')) {
    /** One-line flash for an import result. */
    function emailImportSummaryText(array $summary, bool $applied = true): string {
        $bits = [];
        $bits[] = (int)$summary['create'] . ' new';
        $bits[] = (int)$summary['update'] . ' updated';
        $bits[] = (int)$summary['unchanged'] . ' unchanged';
        if (!empty($summary['duplicates'])) $bits[] = (int)$summary['duplicates'] . ' duplicate' . ($summary['duplicates'] === 1 ? '' : 's');
        if (!empty($summary['skipped']))    $bits[] = (int)$summary['skipped'] . ' skipped';
        if (!empty($summary['unknown_status'])) $bits[] = (int)$summary['unknown_status'] . ' unknown status';
        return ($applied ? 'Import finished: ' : 'Preview: ') . implode(' · ', $bits) . '.';
    }
}

if (!function_exists('emailExportRows')) {
    /** All rows for the company with 'latest_note' attached (export context). */
    function emailExportRows(PDO $pdo, int $companyId): array {
        $rows  = emailsForCompany($pdo, $companyId);
        $notes = emailLatestNotes($pdo, array_map(static function ($r) { return (int)$r['id']; }, $rows));
        foreach ($rows as &$r) $r['latest_note'] = $notes[(int)$r['id']]['detail'] ?? '';
        unset($r);
        return $rows;
    }
}

if (!function_exists('emailCsvEncodeRow')) {
    /**
     * One CSV line (no terminator), RFC 4180: cells are quoted only when they contain a
     * comma, a quote or a line break (fputcsv() would also quote every cell with a space,
     * which makes the header read "Subject Line" instead of Subject Line). Quotes doubled.
     *
     * Formula guard: a cell starting with = + - @ (or a tab / CR) would be evaluated by
     * Excel / Sheets when the export is opened, so it is prefixed with a space. The
     * importer trims every cell (emailCleanCell), so a round trip stays diff-free.
     */
    function emailCsvEncodeRow(array $cells): string {
        return implode(',', array_map(static function ($c) {
            $c = (string)$c;
            if ($c !== '' && strpbrk($c[0], "=+-@\t\r") !== false) $c = ' ' . $c;
            return preg_match('/[",\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
        }, $cells));
    }
}

if (!function_exists('emailExportCsv')) {
    /** CSV text (UTF-8 BOM, CRLF rows, cells quoted when needed) per emails-design.md §7. */
    function emailExportCsv(PDO $pdo, int $companyId): string {
        $out = "\xEF\xBB\xBF" . emailCsvEncodeRow(emailCsvColumns()) . "\r\n";
        foreach (emailExportRows($pdo, $companyId) as $r) {
            $out .= emailCsvEncodeRow(emailCsvRow($r, ['latest_note' => $r['latest_note'] ?? ''])) . "\r\n";
        }
        return $out;
    }
}

if (!function_exists('emailExportJson')) {
    /** JSON payload (version 1): groups, emails with groups[] names and the comment thread. */
    function emailExportJson(PDO $pdo, array $company): array {
        $cid = (int)($company['id'] ?? 0);
        $groups = array_map(static function ($g) {
            return ['name' => $g['name'], 'slug' => $g['slug'], 'sort_order' => (int)$g['sort_order']];
        }, emailGroupsForCompany($pdo, $cid));
        $emails = [];
        foreach (emailExportRows($pdo, $cid) as $r) {
            $emails[] = [
                'code' => (string)$r['code'], 'title' => (string)$r['title'], 'html_url' => (string)$r['html_url'],
                'subject' => (string)$r['subject'], 'preview_text' => $r['preview_text'] ?? null, 'trigger_text' => $r['trigger_text'] ?? null,
                'send_at' => ($r['send_at'] ?? null) ?: null, 'priority' => $r['priority'] ?? null,
                'status' => (string)$r['status'], 'live' => (int)$r['live'], 'live_at' => $r['live_at'] ?? null,
                'sort_order' => (int)$r['sort_order'], 'notes' => $r['notes'] ?? null,
                'groups' => array_map(static function ($g) { return $g['name']; }, $r['groups'] ?? []),
                'latest_note' => $r['latest_note'] ?? '',
                'comments' => array_map(static function ($c) {
                    return ['actor' => $c['actor'], 'text' => $c['detail'], 'at' => $c['created_at']];
                }, function_exists('hasActivityLog') && hasActivityLog($pdo) ? commentThread($pdo, 'email', (int)$r['id']) : []),
                'created_at' => $r['created_at'] ?? null, 'updated_at' => $r['updated_at'] ?? null,
            ];
        }
        return ['version' => 1, 'company' => (string)($company['slug'] ?? ''), 'exported_at' => date('c'), 'groups' => $groups, 'emails' => $emails];
    }
}

if (!function_exists('emailGroupCounts')) {
    /** [group_id => number of emails] for the company's groups. */
    function emailGroupCounts(PDO $pdo, int $companyId): array {
        $out = [];
        $groups = emailGroupsForCompany($pdo, $companyId);
        if (!$groups) return $out;
        foreach ($groups as $g) $out[(int)$g['id']] = 0;
        $ids = array_keys($out);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT group_id, COUNT(*) AS n FROM email_group_map WHERE group_id IN ($ph) GROUP BY group_id");
        $s->execute($ids);
        foreach ($s->fetchAll() as $r) $out[(int)$r['group_id']] = (int)$r['n'];
        return $out;
    }
}

if (!function_exists('emailsModuleId')) {
    /** modules.id of the 'emails' row (migrate.php step 22), 0 when not seeded. */
    function emailsModuleId(PDO $pdo): int {
        try {
            $s = $pdo->prepare("SELECT id FROM modules WHERE slug = 'emails'");
            $s->execute();
            return (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('emailsModuleEnabled')) {
    /** Is the 'emails' module switched on for the company (company_modules row)? */
    function emailsModuleEnabled(PDO $pdo, int $companyId): bool {
        $mid = emailsModuleId($pdo);
        if ($mid <= 0 || $companyId <= 0) return false;
        $s = $pdo->prepare("SELECT 1 FROM company_modules WHERE company_id = ? AND module_id = ? LIMIT 1");
        $s->execute([$companyId, $mid]);
        return (bool)$s->fetchColumn();
    }
}

if (!function_exists('setEmailsModuleEnabled')) {
    /** Enable / disable the Emails tab for a company. Returns false when the module row is missing. */
    function setEmailsModuleEnabled(PDO $pdo, int $companyId, bool $on): bool {
        $mid = emailsModuleId($pdo);
        if ($mid <= 0 || $companyId <= 0) return false;
        if ($on) {
            $pdo->prepare("INSERT IGNORE INTO company_modules (company_id, module_id, sort_order) VALUES (?, ?, ?)")->execute([$companyId, $mid, 99]);
        } else {
            $pdo->prepare("DELETE FROM company_modules WHERE company_id = ? AND module_id = ?")->execute([$companyId, $mid]);
        }
        return true;
    }
}

if (!function_exists('emailGroupById')) {
    /** One of the company's groups, or null. */
    function emailGroupById(PDO $pdo, int $companyId, int $groupId): ?array {
        foreach (emailGroupsForCompany($pdo, $companyId) as $g) if ((int)$g['id'] === $groupId) return $g;
        return null;
    }
}

if (!function_exists('renameEmailGroup')) {
    /** Rename (name + slug). Returns '' on success or an error message. */
    function renameEmailGroup(PDO $pdo, int $companyId, int $groupId, string $name): string {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $slug = emailSlugify($name);
        if ($slug === '') return 'Group name is required.';
        if (!emailGroupById($pdo, $companyId, $groupId)) return 'Group not found.';
        foreach (emailGroupsForCompany($pdo, $companyId) as $g) {
            if ($g['slug'] === $slug && (int)$g['id'] !== $groupId) return 'A group called "' . $g['name'] . '" already exists.';
        }
        $pdo->prepare("UPDATE email_groups SET name = ?, slug = ? WHERE id = ? AND company_id = ?")->execute([mb_substr($name, 0, 80), $slug, $groupId, $companyId]);
        return '';
    }
}

if (!function_exists('deleteEmailGroup')) {
    /** Remove a group and its email_group_map rows. False when it is not this company's. */
    function deleteEmailGroup(PDO $pdo, int $companyId, int $groupId): bool {
        if (!emailGroupById($pdo, $companyId, $groupId)) return false;
        $pdo->prepare("DELETE FROM email_group_map WHERE group_id = ?")->execute([$groupId]);
        $pdo->prepare("DELETE FROM email_groups WHERE id = ? AND company_id = ?")->execute([$groupId, $companyId]);
        return true;
    }
}

if (!function_exists('deleteEmail')) {
    /** Delete an email (map rows + row) and log 'deleted' with the company id given explicitly. */
    function deleteEmail(PDO $pdo, array $email, string $actor = 'admin'): void {
        $id  = (int)($email['id'] ?? 0);
        $cid = (int)($email['company_id'] ?? 0);
        if ($id <= 0 || !hasEmailsTable($pdo)) return;
        $pdo->prepare("DELETE FROM email_group_map WHERE email_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM emails WHERE id = ? AND company_id = ?")->execute([$id, $cid]);
        logEmailActivity($pdo, $actor, 'deleted', $id, 'Email ' . emailDisplayLabel($email) . ' deleted', null, null, $cid);
    }
}

if (!function_exists('emailValidUrl')) {
    /** '' or an absolute http(s) URL → true. */
    function emailValidUrl(string $url): bool {
        $url = trim($url);
        if ($url === '') return true;
        return (bool)preg_match('#^https?://[^\s]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
