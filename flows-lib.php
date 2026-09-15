<?php
/**
 * Flows — shared helpers (loaded by emails-lib.php; never include directly).
 *
 * A Flow is a named, ordered sequence of a client's emails ("Free" = F1 → F4 →
 * C2 …; series may mix). Tables: email_flows + email_flow_steps (migrate.php
 * steps 23–24). Admin edits flows through flow-status.php; clients view them on
 * flows.php. Every query is gated on hasEmailFlowsTable() so a deploy that has
 * not run migrate.php renders "no flows" instead of a 500.
 *
 * Step positions are 0-based and renumbered 0..n-1 after every write, so a
 * flow's steps are always contiguous.
 *
 * All functions are function_exists-guarded and do no work at load. $pdo
 * arguments that default to null fall back to the global $pdo.
 * Contract: scratchpad/flows-design.md.
 */

if (!function_exists('hasEmailFlowsTable')) {
    /** Do the flow tables exist yet? (migrate.php step 23 may not have run.) Cached per request. */
    function hasEmailFlowsTable(?PDO $pdo = null): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        $pdo = emailsPdo($pdo);
        if ($pdo === null) return false;   // not cached: a later call may have a PDO
        try {
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_flows'
            ");
            $s->execute();
            return $cached = (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return $cached = false;
        }
    }
}

// ---------------------------------------------------------------------
// Series — the client's ID-prefix model (F1, R3, CX-017 → F, R, CX)
// ---------------------------------------------------------------------

if (!function_exists('emailSeriesPrefix')) {
    /** Letters before the first digit / dash, uppercased: 'CX-017' → 'CX', 'f10' → 'F', '12' → ''. */
    function emailSeriesPrefix(string $code): string {
        return preg_match('/^\s*([A-Za-z]+)/', $code, $m) ? strtoupper($m[1]) : '';
    }
}

if (!function_exists('emailSeriesNumber')) {
    /** First digit run of the code as an int ('CX-017' → 17, 'F10' → 10); 999999 when there is none. */
    function emailSeriesNumber(string $code): int {
        return preg_match('/(\d+)/', $code, $m) ? (int)$m[1] : 999999;
    }
}

if (!function_exists('emailSeriesOrder')) {
    /** The client's curated series order (their Email Manager's GROUP_ORDER). */
    function emailSeriesOrder(): array {
        return ['F', 'N', 'P', 'G', 'L', 'R', 'S', 'D', 'E', 'T', 'C', 'CX', 'H', 'A', 'B', 'W', 'I', 'J'];
    }
}

if (!function_exists('emailSeriesTitle')) {
    /** Human title per series prefix (their GROUP_TITLES without the "F · " lead); unknown → the prefix itself. */
    function emailSeriesTitle(string $prefix): string {
        static $map = [
            'F' => 'Free', 'N' => 'Essentials', 'P' => 'Pro', 'G' => 'Signature', 'L' => 'Lead',
            'R' => 'Renewal', 'S' => 'System', 'D' => 'Data Exposure', 'E' => 'Business',
            'T' => 'CISO Saturation', 'C' => 'Campaigns', 'CX' => 'Newsletter', 'H' => 'Holidays',
            'A' => 'Alerts', 'B' => 'Breaches & Billing', 'W' => 'Win-Back', 'I' => 'Hive', 'J' => 'Publisher',
        ];
        $p = strtoupper(trim($prefix));
        return $map[$p] ?? $p;
    }
}

// ---------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------

if (!function_exists('emailFlowNormalizeRow')) {
    /** (internal) Cast a flows row; $counts = [flow_id => step_count]. */
    function emailFlowNormalizeRow(array $f, array $counts = []): array {
        $id = (int)($f['id'] ?? 0);
        return [
            'id'          => $id,
            'company_id'  => (int)($f['company_id'] ?? 0),
            'name'        => (string)($f['name'] ?? ''),
            'slug'        => (string)($f['slug'] ?? ''),
            'description' => ($f['description'] ?? null) === null || $f['description'] === '' ? null : (string)$f['description'],
            'sort_order'  => (int)($f['sort_order'] ?? 0),
            'step_count'  => (int)($counts[$id] ?? 0),
            'created_at'  => $f['created_at'] ?? null,
            'updated_at'  => $f['updated_at'] ?? null,
        ];
    }
}

if (!function_exists('emailFlowStepCounts')) {
    /** (internal) [flow_id => number of steps] for the given flow ids. */
    function emailFlowStepCounts(PDO $pdo, array $flowIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $flowIds), static function ($i) { return $i > 0; })));
        $out = [];
        if (!$ids || !hasEmailFlowsTable($pdo)) return $out;
        foreach ($ids as $i) $out[$i] = 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT flow_id, COUNT(*) AS n FROM email_flow_steps WHERE flow_id IN ($ph) GROUP BY flow_id");
        $s->execute($ids);
        foreach ($s->fetchAll() as $r) $out[(int)$r['flow_id']] = (int)$r['n'];
        return $out;
    }
}

if (!function_exists('emailFlowsForCompany')) {
    /** All flows of a company, sort_order then name: {id, company_id, name, slug, description, sort_order, step_count, created_at, updated_at}. */
    function emailFlowsForCompany(PDO $pdo, int $companyId): array {
        if ($companyId <= 0 || !hasEmailFlowsTable($pdo)) return [];
        $s = $pdo->prepare("SELECT * FROM email_flows WHERE company_id = ? ORDER BY sort_order ASC, name ASC");
        $s->execute([$companyId]);
        $rows = $s->fetchAll();
        if (!$rows) return [];
        $counts = emailFlowStepCounts($pdo, array_map(static function ($r) { return (int)$r['id']; }, $rows));
        $out = [];
        foreach ($rows as $r) $out[] = emailFlowNormalizeRow($r, $counts);
        return $out;
    }
}

if (!function_exists('emailFlowById')) {
    /** One flow (with step_count) or null. Callers scope with company_id themselves. */
    function emailFlowById(PDO $pdo, int $id): ?array {
        if ($id <= 0 || !hasEmailFlowsTable($pdo)) return null;
        $s = $pdo->prepare("SELECT * FROM email_flows WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) return null;
        return emailFlowNormalizeRow($row, emailFlowStepCounts($pdo, [(int)$row['id']]));
    }
}

if (!function_exists('emailFlowBySlug')) {
    /** The company's flow with this slug (slugified first), or null. */
    function emailFlowBySlug(PDO $pdo, int $companyId, string $slug): ?array {
        $slug = emailSlugify($slug);
        if ($companyId <= 0 || $slug === '' || !hasEmailFlowsTable($pdo)) return null;
        $s = $pdo->prepare("SELECT * FROM email_flows WHERE company_id = ? AND slug = ?");
        $s->execute([$companyId, $slug]);
        $row = $s->fetch();
        if (!$row) return null;
        return emailFlowNormalizeRow($row, emailFlowStepCounts($pdo, [(int)$row['id']]));
    }
}

if (!function_exists('emailFlowStepRows')) {
    /** (internal) Raw step rows of a flow ordered by position (no email attached). */
    function emailFlowStepRows(PDO $pdo, int $flowId): array {
        if ($flowId <= 0 || !hasEmailFlowsTable($pdo)) return [];
        $s = $pdo->prepare("SELECT * FROM email_flow_steps WHERE flow_id = ? ORDER BY position ASC, id ASC");
        $s->execute([$flowId]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $out[] = [
                'id'          => (int)$r['id'],
                'flow_id'     => (int)$r['flow_id'],
                'email_id'    => (int)$r['email_id'],
                'position'    => (int)$r['position'],
                'timing_text' => ($r['timing_text'] ?? null) === null || $r['timing_text'] === '' ? null : (string)$r['timing_text'],
                'note'        => ($r['note'] ?? null) === null || $r['note'] === '' ? null : (string)$r['note'],
            ];
        }
        return $out;
    }
}

if (!function_exists('emailFlowStepByEmail')) {
    /** (internal) The raw step for one email inside a flow, or null. */
    function emailFlowStepByEmail(PDO $pdo, int $flowId, int $emailId): ?array {
        foreach (emailFlowStepRows($pdo, $flowId) as $st) if ($st['email_id'] === $emailId) return $st;
        return null;
    }
}

if (!function_exists('emailFlowAttachEmails')) {
    /** (internal) Add 'email' (emails.* + groups) to raw steps; steps whose email is gone are dropped. */
    function emailFlowAttachEmails(PDO $pdo, array $steps, string $visibleTo = 'admin'): array {
        if (!$steps) return [];
        $ids = array_values(array_unique(array_map(static function ($s) { return (int)$s['email_id']; }, $steps)));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT * FROM emails WHERE id IN ($ph)");
        $q->execute($ids);
        $byId = [];
        foreach (emailsAttachGroups($pdo, $q->fetchAll()) as $e) $byId[(int)$e['id']] = $e;
        $out = [];
        foreach ($steps as $st) {
            $e = $byId[(int)$st['email_id']] ?? null;
            if ($e === null) continue;
            if ($visibleTo === 'client' && empty($e['live']) && !in_array((string)($e['status'] ?? ''), ['pending', 'approved'], true)) continue;
            $st['email'] = $e;
            $out[] = $st;
        }
        return $out;
    }
}

if (!function_exists('emailFlowSteps')) {
    /**
     * A flow's steps in order, each {id, flow_id, email_id, position, timing_text, note, email}.
     *   $opts['visibleTo'] 'admin' (default) | 'client' — drops steps whose email is draft or denied
     *   (same rule as emailsForCompany: live = 1 OR status IN pending, approved). Stored positions are kept.
     */
    function emailFlowSteps(PDO $pdo, int $flowId, array $opts = []): array {
        $rows = emailFlowStepRows($pdo, $flowId);
        if (!$rows) return [];
        return emailFlowAttachEmails($pdo, $rows, ($opts['visibleTo'] ?? 'admin') === 'client' ? 'client' : 'admin');
    }
}

if (!function_exists('emailFlowTiming')) {
    /** The card's timing line: timing_text, else the email's first trigger_text line, else ''. */
    function emailFlowTiming(array $step): string {
        $t = trim((string)($step['timing_text'] ?? ''));
        if ($t !== '') return $t;
        $trig = trim((string)($step['email']['trigger_text'] ?? ''));
        if ($trig === '') return '';
        $first = preg_split('/\r\n|\r|\n/', $trig)[0];
        return trim(preg_replace('/\s+/', ' ', (string)$first));
    }
}

// ---------------------------------------------------------------------
// Writes — flows
// ---------------------------------------------------------------------

if (!function_exists('emailFlowCleanName')) {
    /** (internal) Trim, collapse whitespace, cut to 120. */
    function emailFlowCleanName(string $name): string {
        $n = trim(preg_replace('/\s+/u', ' ', $name));
        return mb_substr($n, 0, 120);
    }
}

if (!function_exists('emailFlowCleanText')) {
    /** (internal) Trim + CRLF → LF; '' → null. $max cuts the result (0 = no limit). */
    function emailFlowCleanText(?string $text, int $max = 0): ?string {
        $t = trim(str_replace(["\r\n", "\r"], "\n", (string)$text));
        if ($t === '') return null;
        return $max > 0 ? mb_substr($t, 0, $max) : $t;
    }
}

if (!function_exists('createEmailFlow')) {
    /** Create a flow; slug = emailSlugify(name) ('flow' when empty), de-duplicated -2/-3…; sort_order = max + 1. Returns the id. */
    function createEmailFlow(PDO $pdo, int $companyId, string $name, ?string $description = null): int {
        if ($companyId <= 0 || !hasEmailFlowsTable($pdo)) return 0;
        $name = emailFlowCleanName($name);
        $base = emailSlugify($name);
        if ($base === '') $base = 'flow';
        $base = substr($base, 0, 110);
        $taken = []; $max = 0;
        foreach (emailFlowsForCompany($pdo, $companyId) as $f) {
            $taken[$f['slug']] = true;
            $max = max($max, (int)$f['sort_order']);
        }
        $slug = $base;
        for ($n = 2; isset($taken[$slug]); $n++) $slug = $base . '-' . $n;
        $ins = $pdo->prepare("INSERT INTO email_flows (company_id, name, slug, description, sort_order) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$companyId, $name, $slug, emailFlowCleanText($description), $max + 1]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('renameEmailFlow')) {
    /** New name / description (slug unchanged). */
    function renameEmailFlow(PDO $pdo, int $flowId, string $name, ?string $description): void {
        if ($flowId <= 0 || !hasEmailFlowsTable($pdo)) return;
        $pdo->prepare("UPDATE email_flows SET name = ?, description = ? WHERE id = ?")
            ->execute([emailFlowCleanName($name), emailFlowCleanText($description), $flowId]);
    }
}

if (!function_exists('deleteEmailFlow')) {
    /** Remove a flow and its steps. */
    function deleteEmailFlow(PDO $pdo, int $flowId): void {
        if ($flowId <= 0 || !hasEmailFlowsTable($pdo)) return;
        $pdo->prepare("DELETE FROM email_flow_steps WHERE flow_id = ?")->execute([$flowId]);
        $pdo->prepare("DELETE FROM email_flows WHERE id = ?")->execute([$flowId]);
    }
}

if (!function_exists('reorderEmailFlows')) {
    /** Listed ids (belonging to the company) get 0..n-1 in that order; the company's other flows follow in their old order. */
    function reorderEmailFlows(PDO $pdo, int $companyId, array $flowIds): void {
        if ($companyId <= 0 || !hasEmailFlowsTable($pdo)) return;
        $current = emailFlowsForCompany($pdo, $companyId);
        if (!$current) return;
        $own = [];
        foreach ($current as $f) $own[(int)$f['id']] = $f;
        $order = [];
        foreach ($flowIds as $id) { $id = (int)$id; if (isset($own[$id]) && !in_array($id, $order, true)) $order[] = $id; }
        foreach ($current as $f) if (!in_array((int)$f['id'], $order, true)) $order[] = (int)$f['id'];
        $upd = $pdo->prepare("UPDATE email_flows SET sort_order = ? WHERE id = ? AND company_id = ?");
        foreach ($order as $pos => $id) {
            if ((int)$own[$id]['sort_order'] !== $pos) $upd->execute([$pos, $id, $companyId]);
        }
    }
}

// ---------------------------------------------------------------------
// Writes — steps
// ---------------------------------------------------------------------

if (!function_exists('emailFlowRenumber')) {
    /** (internal) Write positions 0..n-1 for the raw steps given in their new order (only changed rows are updated). */
    function emailFlowRenumber(PDO $pdo, array $orderedSteps): void {
        $upd = $pdo->prepare("UPDATE email_flow_steps SET position = ? WHERE id = ?");
        foreach (array_values($orderedSteps) as $pos => $st) {
            if ((int)$st['position'] !== $pos) $upd->execute([$pos, (int)$st['id']]);
        }
    }
}

if (!function_exists('addEmailFlowStep')) {
    /**
     * Append (position null) or insert at the clamped position, renumber 0..n-1 and return the
     * step with its email. Throws InvalidArgumentException when the email is already in the flow.
     */
    function addEmailFlowStep(PDO $pdo, int $flowId, int $emailId, ?int $position = null): array {
        if ($flowId <= 0 || $emailId <= 0 || !hasEmailFlowsTable($pdo)) return [];
        $rows = emailFlowStepRows($pdo, $flowId);
        foreach ($rows as $st) if ($st['email_id'] === $emailId) throw new InvalidArgumentException('That email is already in this flow');
        $n   = count($rows);
        $pos = $position === null ? $n : max(0, min($n, $position));
        $ins = $pdo->prepare("INSERT INTO email_flow_steps (flow_id, email_id, position, timing_text, note) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$flowId, $emailId, $pos, null, null]);
        $newId = (int)$pdo->lastInsertId();
        $new = ['id' => $newId, 'flow_id' => $flowId, 'email_id' => $emailId, 'position' => $pos, 'timing_text' => null, 'note' => null];
        array_splice($rows, $pos, 0, [$new]);
        emailFlowRenumber($pdo, $rows);
        $new['position'] = $pos;
        $with = emailFlowAttachEmails($pdo, [$new]);
        return $with[0] ?? $new;
    }
}

if (!function_exists('removeEmailFlowStep')) {
    /** Drop one email from the flow and renumber. */
    function removeEmailFlowStep(PDO $pdo, int $flowId, int $emailId): void {
        if ($flowId <= 0 || $emailId <= 0 || !hasEmailFlowsTable($pdo)) return;
        $rows = emailFlowStepRows($pdo, $flowId);
        $keep = [];
        foreach ($rows as $st) {
            if ($st['email_id'] === $emailId) $pdo->prepare("DELETE FROM email_flow_steps WHERE id = ?")->execute([(int)$st['id']]);
            else $keep[] = $st;
        }
        emailFlowRenumber($pdo, $keep);
    }
}

if (!function_exists('moveEmailFlowStep')) {
    /** Move one email to a new (clamped) position and renumber. */
    function moveEmailFlowStep(PDO $pdo, int $flowId, int $emailId, int $newPosition): void {
        if ($flowId <= 0 || $emailId <= 0 || !hasEmailFlowsTable($pdo)) return;
        $rows = emailFlowStepRows($pdo, $flowId);
        $moving = null; $rest = [];
        foreach ($rows as $st) { if ($st['email_id'] === $emailId) $moving = $st; else $rest[] = $st; }
        if ($moving === null) return;
        $pos = max(0, min(count($rest), $newPosition));
        array_splice($rest, $pos, 0, [$moving]);
        emailFlowRenumber($pdo, $rest);
    }
}

if (!function_exists('setEmailFlowStepTiming')) {
    /** Replace timing_text (≤ 255) and note; blank → NULL. */
    function setEmailFlowStepTiming(PDO $pdo, int $flowId, int $emailId, ?string $timingText, ?string $note): void {
        $st = emailFlowStepByEmail($pdo, $flowId, $emailId);
        if ($st === null) return;
        $pdo->prepare("UPDATE email_flow_steps SET timing_text = ?, note = ? WHERE id = ?")
            ->execute([emailFlowCleanText($timingText, 255), emailFlowCleanText($note), (int)$st['id']]);
    }
}

// ---------------------------------------------------------------------
// Cross-references + seeding
// ---------------------------------------------------------------------

if (!function_exists('emailFlowsForEmail')) {
    /** Flows containing this email: [{flow_id, name, slug, position, step_count}] ordered by flow sort_order, name. */
    function emailFlowsForEmail(PDO $pdo, int $emailId): array {
        if ($emailId <= 0 || !hasEmailFlowsTable($pdo)) return [];
        $s = $pdo->prepare("
            SELECT s.flow_id, s.position, f.name, f.slug, f.sort_order
              FROM email_flow_steps s
             INNER JOIN email_flows f ON f.id = s.flow_id
             WHERE s.email_id = ?
             ORDER BY f.sort_order ASC, f.name ASC
        ");
        $s->execute([$emailId]);
        $rows = $s->fetchAll();
        if (!$rows) return [];
        $counts = emailFlowStepCounts($pdo, array_map(static function ($r) { return (int)$r['flow_id']; }, $rows));
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'flow_id'    => (int)$r['flow_id'],
                'name'       => (string)$r['name'],
                'slug'       => (string)$r['slug'],
                'position'   => (int)$r['position'],
                'step_count' => (int)($counts[(int)$r['flow_id']] ?? 0),
            ];
        }
        return $out;
    }
}

if (!function_exists('emailSeriesSortCodes')) {
    /** (internal) Sort email rows the way the client's Email Manager does inside a series: number in the ID, then natural code, then id. */
    function emailSeriesSortCodes(array $rows): array {
        usort($rows, static function ($a, $b) {
            $c = emailSeriesNumber((string)($a['code'] ?? '')) <=> emailSeriesNumber((string)($b['code'] ?? ''));
            if ($c !== 0) return $c;
            $c = strnatcasecmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
            if ($c !== 0) return $c;
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });
        return array_values($rows);
    }
}

if (!function_exists('seedEmailFlowsFromSeries')) {
    /**
     * One flow per series prefix present among the company's emails, in emailSeriesOrder() order
     * (other prefixes follow alphabetically), named emailSeriesTitle(prefix); a series whose slug
     * already exists is skipped; steps = the series' emails by ID number (every status).
     * Returns ['created' => n, 'skipped' => n, 'flows' => [{id, name, slug, step_count}]]. Does not log.
     */
    function seedEmailFlowsFromSeries(PDO $pdo, int $companyId): array {
        $out = ['created' => 0, 'skipped' => 0, 'flows' => []];
        if ($companyId <= 0 || !hasEmailFlowsTable($pdo)) return $out;
        $bySeries = [];
        foreach (emailsForCompany($pdo, $companyId) as $e) {
            $p = emailSeriesPrefix((string)$e['code']);
            if ($p === '') continue;
            $bySeries[$p][] = $e;
        }
        if (!$bySeries) return $out;
        $order = [];
        foreach (emailSeriesOrder() as $p) if (isset($bySeries[$p])) $order[] = $p;
        $rest = array_diff(array_keys($bySeries), $order);
        sort($rest, SORT_STRING);
        $order = array_merge($order, $rest);
        $ins = $pdo->prepare("INSERT INTO email_flow_steps (flow_id, email_id, position, timing_text, note) VALUES (?, ?, ?, ?, ?)");
        foreach ($order as $p) {
            $name = emailSeriesTitle($p);
            if (emailFlowBySlug($pdo, $companyId, $name) !== null) { $out['skipped']++; continue; }
            $flowId = createEmailFlow($pdo, $companyId, $name, null);
            $rows = emailSeriesSortCodes($bySeries[$p]);
            foreach ($rows as $pos => $e) $ins->execute([$flowId, (int)$e['id'], $pos, null, null]);
            $out['created']++;
            $out['flows'][] = ['id' => $flowId, 'name' => $name, 'slug' => emailSlugify($name), 'step_count' => count($rows), 'series' => $p];
        }
        return $out;
    }
}

// ---------------------------------------------------------------------
// URLs + activity
// ---------------------------------------------------------------------

if (!function_exists('emailFlowsUrl')) {
    /** flows.php URL in the current client scope (pass 'client' => slug to override). */
    function emailFlowsUrl(array $params = []): string {
        return clientUrl('flows.php', $params);
    }
}

if (!function_exists('emailFlowUrl')) {
    /** Deep link to one flow (flows.php?client=…&flow=<slug>). */
    function emailFlowUrl(array $flow, array $params = []): string {
        return emailFlowsUrl(['flow' => (string)($flow['slug'] ?? '')] + $params);
    }
}

if (!function_exists('logEmailFlowActivity')) {
    /**
     * activity_log row with entity_type 'email_flow'. company_id is read from the flow row
     * when not supplied (pass it explicitly for 'deleted'). Never throws.
     */
    function logEmailFlowActivity(PDO $pdo, string $actor, string $action, int $flowId, string $summary,
                                  ?string $detail = null, ?string $batchId = null, ?int $companyId = null): void {
        if ($companyId === null || $companyId <= 0) {
            $companyId = 0;
            try {
                $s = $pdo->prepare("SELECT company_id FROM email_flows WHERE id = ?");
                $s->execute([$flowId]);
                $companyId = (int)$s->fetchColumn();
            } catch (Throwable $e) {
                $companyId = 0;
            }
        }
        logActivity($pdo, $companyId, 'email_flow', $flowId, $action, $actor, $summary, $detail, $batchId);
    }
}

// ---------------------------------------------------------------------
// Export / import (emails-io.php; flows-design.md §4)
// ---------------------------------------------------------------------

if (!function_exists('emailFlowsExport')) {
    /** [{name, slug, description, sort_order, steps: [{code, position, timing_text, note}]}] for the JSON export. */
    function emailFlowsExport(PDO $pdo, int $companyId): array {
        $out = [];
        foreach (emailFlowsForCompany($pdo, $companyId) as $f) {
            $steps = [];
            foreach (emailFlowSteps($pdo, (int)$f['id']) as $st) {
                $steps[] = [
                    'code'        => (string)($st['email']['code'] ?? ''),
                    'position'    => (int)$st['position'],
                    'timing_text' => $st['timing_text'],
                    'note'        => $st['note'],
                ];
            }
            $out[] = ['name' => $f['name'], 'slug' => $f['slug'], 'description' => $f['description'], 'sort_order' => (int)$f['sort_order'], 'steps' => $steps];
        }
        return $out;
    }
}

if (!function_exists('emailFlowsCsvColumns')) {
    function emailFlowsCsvColumns(): array {
        return ['Flow', 'Position', 'ID', 'Title', 'Timing', 'Trigger', 'Subject Line', 'Preview Text', 'Status', 'URL'];
    }
}

if (!function_exists('emailFlowsExportCsv')) {
    /** One row per step (flows in sort order, Position 1-based); BOM + CRLF + the emails CSV encoder's formula guard. */
    function emailFlowsExportCsv(PDO $pdo, int $companyId): string {
        $out = "\xEF\xBB\xBF" . emailCsvEncodeRow(emailFlowsCsvColumns()) . "\r\n";
        foreach (emailFlowsForCompany($pdo, $companyId) as $f) {
            foreach (emailFlowSteps($pdo, (int)$f['id']) as $i => $st) {
                $e = $st['email'];
                $out .= emailCsvEncodeRow([
                    $f['name'],
                    (string)($i + 1),
                    (string)($e['code'] ?? ''),
                    (string)($e['title'] ?? ''),
                    emailFlowTiming($st),
                    str_replace(["\r\n", "\r"], "\n", (string)($e['trigger_text'] ?? '')),
                    (string)($e['subject'] ?? ''),
                    (string)($e['preview_text'] ?? ''),
                    emailExportStatusLabel(emailStatusKey($e)),
                    (string)($e['html_url'] ?? ''),
                ]) . "\r\n";
            }
        }
        return $out;
    }
}

if (!function_exists('emailFlowsImportNormalize')) {
    /** Validate the JSON `flows` list into [{name, slug, description, steps: [{code, timing_text, note}]}] (bad entries dropped). */
    function emailFlowsImportNormalize($flows): array {
        $out = [];
        if (!is_array($flows)) return $out;
        foreach ($flows as $f) {
            if (!is_array($f)) continue;
            $name = emailFlowCleanName((string)($f['name'] ?? ''));
            $slug = emailSlugify((string)($f['slug'] ?? ''));
            if ($slug === '') $slug = emailSlugify($name);
            if ($name === '' || $slug === '') continue;
            $steps = [];
            $rawSteps = is_array($f['steps'] ?? null) ? $f['steps'] : [];
            uasort($rawSteps, static function ($a, $b) {
                return ((int)(is_array($a) ? ($a['position'] ?? 0) : 0)) <=> ((int)(is_array($b) ? ($b['position'] ?? 0) : 0));
            });
            $seen = [];
            foreach ($rawSteps as $s) {
                $code = emailNormalizeCode(is_array($s) ? (string)($s['code'] ?? '') : (string)$s);
                if ($code === '' || isset($seen[$code])) continue;
                $seen[$code] = true;
                $steps[] = [
                    'code'        => $code,
                    'timing_text' => is_array($s) ? emailFlowCleanText(isset($s['timing_text']) ? (string)$s['timing_text'] : null, 255) : null,
                    'note'        => is_array($s) ? emailFlowCleanText(isset($s['note']) ? (string)$s['note'] : null) : null,
                ];
            }
            $out[] = ['name' => $name, 'slug' => $slug, 'description' => emailFlowCleanText(isset($f['description']) ? (string)$f['description'] : null), 'steps' => $steps];
        }
        return $out;
    }
}

if (!function_exists('emailFlowsImport')) {
    /**
     * Upsert flows by slug (name/description updated), replace each flow's steps by email code
     * (unknown codes reported in 'missing'), log 'created' (via import) / 'imported' per changed flow
     * under one batch id. Returns ['created', 'updated', 'unchanged', 'steps', 'missing' => [codes], 'batch_id'].
     */
    function emailFlowsImport(PDO $pdo, int $companyId, array $flows, string $actor = 'admin', ?string $batchId = null): array {
        $res = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'steps' => 0, 'missing' => [], 'batch_id' => $batchId ?: newBatchId()];
        $flows = emailFlowsImportNormalize($flows);
        if (!$flows || $companyId <= 0 || !hasEmailFlowsTable($pdo)) return $res;
        $ins = $pdo->prepare("INSERT INTO email_flow_steps (flow_id, email_id, position, timing_text, note) VALUES (?, ?, ?, ?, ?)");
        foreach ($flows as $f) {
            $existing = emailFlowBySlug($pdo, $companyId, $f['slug']);
            // Resolve steps first so an unchanged flow is not rewritten.
            $resolved = [];
            foreach ($f['steps'] as $s) {
                $e = emailByCode($pdo, $companyId, $s['code']);
                if ($e === null) { $res['missing'][] = $s['code']; continue; }
                $resolved[] = ['email_id' => (int)$e['id'], 'timing_text' => $s['timing_text'], 'note' => $s['note']];
            }
            if ($existing === null) {
                $flowId = createEmailFlow($pdo, $companyId, $f['name'], $f['description']);
                foreach ($resolved as $pos => $s) $ins->execute([$flowId, $s['email_id'], $pos, $s['timing_text'], $s['note']]);
                $res['created']++;
                $res['steps'] += count($resolved);
                logEmailFlowActivity($pdo, $actor, 'created', $flowId, 'Flow ' . $f['name'] . ' created', 'via import', $res['batch_id'], $companyId);
                continue;
            }
            $flowId = (int)$existing['id'];
            $current = array_map(static function ($st) {
                return ['email_id' => $st['email_id'], 'timing_text' => $st['timing_text'], 'note' => $st['note']];
            }, emailFlowStepRows($pdo, $flowId));
            $metaSame = $existing['name'] === $f['name'] && ($existing['description'] ?? null) === $f['description'];
            if ($metaSame && $current === $resolved) { $res['unchanged']++; continue; }
            if (!$metaSame) renameEmailFlow($pdo, $flowId, $f['name'], $f['description']);
            if ($current !== $resolved) {
                $pdo->prepare("DELETE FROM email_flow_steps WHERE flow_id = ?")->execute([$flowId]);
                foreach ($resolved as $pos => $s) $ins->execute([$flowId, $s['email_id'], $pos, $s['timing_text'], $s['note']]);
                $res['steps'] += count($resolved);
            }
            $res['updated']++;
            logEmailFlowActivity($pdo, $actor, 'imported', $flowId, 'Flow ' . $f['name'] . ' imported (' . count($resolved) . ' step' . (count($resolved) === 1 ? '' : 's') . ')', null, $res['batch_id'], $companyId);
        }
        $res['missing'] = array_values(array_unique($res['missing']));
        return $res;
    }
}
