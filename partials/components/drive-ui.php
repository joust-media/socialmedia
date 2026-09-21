<?php
/**
 * Drive storage view — shared UI helpers (drive.php + the drive-* components).
 * Presentation only: numbers in, escaped strings out. The read model lives in
 * drive-lib.php (driveLatestSnapshot, driveClients, driveTree, driveCandidates …), which
 * helpers.php always loads; driveFormatBytes() is its. Every function is function_exists-guarded.
 *
 *   driveUiBytes($n)                    → "1.7 TB" (delegates to driveFormatBytes)
 *   driveUiPct($x, $digits = 0)         → "83%"   (null → "—")
 *   driveUiIdle($days)                  → "14 months" / "2.3 years" / "12 days"
 *   driveUiDate($iso)                   → "Sep 21, 2026" ('' when empty)
 *   driveUiDateTime($iso)               → "Sep 21, 2026 · 3:04 AM"
 *   driveUiSigned($bytes)               → "+12.4 GB" / "−3 GB" / "0 B"
 *   driveUiSafeLink($url)               → the URL only when it is https://drive.google.com/…, else ''
 *   driveUiOpenLink($url, $label)       → <a target=_blank rel=noopener noreferrer> or ''
 *   driveUiStaleBin($pct)               → 0..3 (0–25 / 25–50 / 50–75 / 75+)
 *   driveUiTileFill($pct)               → ['bin', 'bg', 'ink', 'contrast', 'label']
 *   driveUiContrast($hexA, $hexB)       → WCAG contrast ratio (float)
 */

if (!function_exists('driveUiBytes')) {
    function driveUiBytes($n): string { return driveFormatBytes((float)$n); }
}

if (!function_exists('driveUiPct')) {
    function driveUiPct($x, int $digits = 0): string {
        if ($x === null || $x === '') return '—';
        $x = (float)$x;
        $s = number_format($x, $digits);
        if ($digits > 0) $s = rtrim(rtrim($s, '0'), '.');
        return $s . '%';
    }
}

if (!function_exists('driveUiIdle')) {
    function driveUiIdle($days): string {
        $d = max(0, (int)$days);
        if ($d < 30) return $d . ($d === 1 ? ' day' : ' days');
        if ($d < 365) { $m = (int)round($d / 30.4); return $m . ($m === 1 ? ' month' : ' months'); }
        $y = $d / 365.25;
        $s = $y < 10 ? number_format($y, 1) : number_format($y, 0);
        $s = rtrim(rtrim($s, '0'), '.');
        return $s . ($s === '1' ? ' year' : ' years');
    }
}

if (!function_exists('driveUiDate')) {
    function driveUiDate($iso): string {
        $t = $iso ? strtotime((string)$iso) : false;
        return $t ? date('M j, Y', $t) : '';
    }
}

if (!function_exists('driveUiDateTime')) {
    function driveUiDateTime($iso): string {
        $t = $iso ? strtotime((string)$iso) : false;
        return $t ? date('M j, Y · g:i A', $t) : '';
    }
}

if (!function_exists('driveUiSigned')) {
    function driveUiSigned($bytes): string {
        $b = (float)$bytes;
        if ($b == 0) return '0 B';
        return ($b > 0 ? '+' : '−') . driveFormatBytes(abs($b));
    }
}

if (!function_exists('driveUiSafeLink')) {
    /** Only Google Drive links leave the page; anything else renders as plain text / no link. */
    function driveUiSafeLink($url): string {
        $u = trim((string)$url);
        if ($u === '' || !preg_match('#^https://drive\.google\.com/[^\s"\'<>]*$#i', $u)) return '';
        return $u;
    }
}

if (!function_exists('driveUiOpenLink')) {
    function driveUiOpenLink($url, string $label = 'Open in Drive', string $class = 'ui-btn ui-btn--sm ui-btn--gray'): string {
        $u = driveUiSafeLink($url);
        if ($u === '') return '';
        return '<a class="' . esc($class) . '" href="' . esc($u) . '" target="_blank" rel="noopener noreferrer">' . esc($label) . '</a>';
    }
}

if (!function_exists('driveUiRelLum')) {
    function driveUiRelLum(string $hex): float {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        $c = [];
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}

if (!function_exists('driveUiContrast')) {
    function driveUiContrast(string $a, string $b): float {
        $la = driveUiRelLum($a); $lb = driveUiRelLum($b);
        $hi = max($la, $lb); $lo = min($la, $lb);
        return round(($hi + 0.05) / ($lo + 0.05), 2);
    }
}

if (!function_exists('driveUiStaleBin')) {
    function driveUiStaleBin($pct): int {
        $p = (float)$pct;
        if ($p >= 75) return 3;
        if ($p >= 50) return 2;
        if ($p >= 25) return 1;
        return 0;
    }
}

if (!function_exists('driveUiTileFills')) {
    /**
     * Four steps of the accent (system blue) ramp, light → dark as more of the client sits untouched.
     * Absolute colours (not tokens) so the ink can be computed once, server-side, and hold ≥ 4.5:1 in
     * both themes — the tile is the same in light and dark; only the page around it changes.
     */
    function driveUiTileFills(): array {
        static $fills = null;
        if ($fills !== null) return $fills;
        $steps = [
            0 => ['bg' => '#D9EAFF', 'label' => '0–25% untouched'],
            1 => ['bg' => '#9CC7FF', 'label' => '25–50% untouched'],
            2 => ['bg' => '#3A8BFF', 'label' => '50–75% untouched'],
            3 => ['bg' => '#0A4FB5', 'label' => '75%+ untouched'],
        ];
        $fills = [];
        foreach ($steps as $bin => $s) {
            $black = driveUiContrast($s['bg'], '#000000');
            $white = driveUiContrast($s['bg'], '#FFFFFF');
            $ink   = $black >= $white ? '#000000' : '#FFFFFF';
            $fills[$bin] = ['bin' => $bin, 'bg' => $s['bg'], 'ink' => $ink, 'contrast' => max($black, $white), 'label' => $s['label']];
        }
        return $fills;
    }
}

if (!function_exists('driveUiTileFill')) {
    function driveUiTileFill($stalePct): array {
        return driveUiTileFills()[driveUiStaleBin($stalePct)];
    }
}

if (!function_exists('driveUiTypeLabel')) {
    function driveUiTypeLabel($bucket): string {
        static $map = ['video' => 'Video', 'videos' => 'Video', 'design' => 'Design', 'image' => 'Images', 'images' => 'Images',
                       'archive' => 'Archives', 'archives' => 'Archives', 'other' => 'Other', 'doc' => 'Docs', 'docs' => 'Docs', 'audio' => 'Audio'];
        $k = strtolower(trim((string)$bucket));
        return $map[$k] ?? ($k === '' ? 'Other' : ucfirst($k));
    }
}
