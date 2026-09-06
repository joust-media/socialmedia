<?php
/**
 * Status language (spec §5) — DB strings never change, only the copy:
 *   pending  → "To Review"
 *   approved → "Approved"
 *   denied   → "Needs changes"
 *   posted=1 → "Scheduled"
 *
 * statusLabel(string $status, bool $posted = false): string
 * statusKey(string $status, bool $posted = false): string   → pending|approved|denied|scheduled|neutral
 * statusPill(string $status, bool $posted = false, array $opts = []): string
 *   $opts: 'label' (override text), 'class' (extra classes), 'dot' (bool, default true),
 *          'attrs' (assoc extra attributes)
 *   Output: <span class="ui-pill ui-pill--pending" data-status-pill data-status="pending">To Review</span>
 */
if (!function_exists('statusLabel')) {
    function statusLabel(string $status, bool $posted = false): string
    {
        if ($posted) return 'Scheduled';
        static $map = [
            'pending'  => 'To Review',
            'approved' => 'Approved',
            'denied'   => 'Needs changes',
            'posted'   => 'Scheduled',
            'scheduled'=> 'Scheduled',
            'open'     => 'Open',
            'in_progress' => 'In progress',
            'done'     => 'Done',
        ];
        $s = strtolower(trim($status));
        return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
    }
}

if (!function_exists('statusKey')) {
    function statusKey(string $status, bool $posted = false): string
    {
        if ($posted) return 'scheduled';
        $s = strtolower(trim($status));
        if ($s === 'posted') return 'scheduled';
        return in_array($s, ['pending', 'approved', 'denied', 'scheduled'], true) ? $s : 'neutral';
    }
}

if (!function_exists('statusPill')) {
    function statusPill(string $status, bool $posted = false, array $opts = []): string
    {
        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $key   = statusKey($status, $posted);
        $label = $opts['label'] ?? statusLabel($status, $posted);
        $cls   = 'ui-pill ui-pill--' . $key;
        if (isset($opts['dot']) && $opts['dot'] === false) $cls .= ' ui-pill--nodot';
        if (!empty($opts['class'])) $cls .= ' ' . $opts['class'];

        $attrs = ' data-status-pill data-status="' . $esc($posted ? 'posted' : strtolower(trim($status))) . '"';
        foreach (($opts['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . '="' . $esc($v) . '"';
        }
        return '<span class="' . $esc($cls) . '"' . $attrs . '>' . $esc($label) . '</span>';
    }
}
