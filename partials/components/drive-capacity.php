<?php
/**
 * Capacity strip — the one hero figure of the overview: percent used (large), "X of Y", a segmented
 * bar Drive · Other · Trash · Free with tick marks at 80 / 90 / 95 %, a legend with sizes, and on the
 * right the countdown ("Full in ~N days" in --warning, burn rate + basis) or "Not growing".
 *
 *   driveCapacityStrip(array $snap, array $proj, array $opts = []): string
 *       $snap: driveLatestSnapshot() row; $proj: driveProjection($snap)
 *   Without a limit (unlimited plan): no percent, no countdown, no Free segment — sizes only.
 */
if (!function_exists('driveCapacityStrip')) {
    function driveCapacityStrip(array $snap, array $proj, array $opts = []): string
    {
        $limitRaw = $snap['quota_limit'] ?? ($snap['limit_bytes'] ?? null);   // drive-design.md §3: quota_limit
        $limit = $limitRaw !== null && (float)$limitRaw > 0 ? (float)$limitRaw : null;
        $usage = max(0.0, (float)($snap['usage_bytes'] ?? 0));
        $drive = max(0.0, (float)($snap['drive_bytes'] ?? 0));
        $trash = max(0.0, (float)($snap['trash_bytes'] ?? 0));
        $other = max(0.0, (float)($snap['other_bytes'] ?? max(0.0, $usage - $drive - $trash)));
        $free  = $limit !== null ? max(0.0, (float)($snap['free_bytes'] ?? ($limit - $usage))) : null;
        $pct   = $limit !== null ? (isset($snap['pct_used']) && $snap['pct_used'] !== null ? (float)$snap['pct_used'] : ($limit > 0 ? 100 * $usage / $limit : null)) : null;
        $denom = $limit !== null ? $limit : max(1.0, $drive + $other + $trash);

        $segs = [
            ['key' => 'drive', 'label' => 'Drive files', 'bytes' => $drive],
            ['key' => 'other', 'label' => 'Gmail & Photos', 'bytes' => $other],
            ['key' => 'trash', 'label' => 'Trash', 'bytes' => $trash],
        ];
        if ($free !== null) $segs[] = ['key' => 'free', 'label' => 'Free', 'bytes' => $free];

        $level = $pct === null ? 'none' : ($pct >= 95 ? 'critical' : ($pct >= 80 ? 'high' : 'ok'));
        $cls = 'drive-capacity drive-capacity--' . $level . ($limit === null ? ' drive-capacity--nolimit' : '') . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
        $out = '<section class="' . esc($cls) . '" aria-label="Storage capacity" data-drive-capacity data-drive-pct="' . esc($pct === null ? '' : number_format($pct, 1, '.', '')) . '">';

        // Left: hero figure
        $out .= '<div class="drive-capacity-main">';
        if ($pct !== null) {
            $out .= '<p class="drive-capacity-pct" data-drive-percent><strong>' . esc(driveUiPct($pct, $pct < 10 ? 1 : 0)) . '</strong><span class="drive-capacity-pct-sub">used</span></p>';
            $out .= '<p class="drive-capacity-of" data-drive-of>' . esc(driveUiBytes($usage)) . ' of ' . esc(driveUiBytes($limit)) . '</p>';
        } else {
            $out .= '<p class="drive-capacity-pct drive-capacity-pct--nolimit" data-drive-usage-only><strong>' . esc(driveUiBytes($usage)) . '</strong><span class="drive-capacity-pct-sub">in use</span></p>';
            $out .= '<p class="drive-capacity-of text-secondary">No storage limit reported for this account.</p>';
        }
        // Bar
        $out .= '<div class="drive-capacity-bar" role="img" aria-label="' . esc(implode(', ', array_map(static fn($s) => $s['label'] . ' ' . driveUiBytes($s['bytes']), $segs))) . '" data-drive-bar>';
        foreach ($segs as $s) {
            $w = $denom > 0 ? 100 * $s['bytes'] / $denom : 0;
            $out .= '<span class="drive-capacity-seg drive-capacity-seg--' . $s['key'] . '" style="width:' . esc(number_format(max(0.0, min(100.0, $w)), 3, '.', '')) . '%" data-drive-seg="' . $s['key'] . '" data-bytes="' . esc((string)(int)round($s['bytes'])) . '"></span>';
        }
        if ($limit !== null) {
            foreach ([80, 90, 95] as $tick) {
                $out .= '<span class="drive-capacity-tick" style="left:' . $tick . '%" data-drive-tick="' . $tick . '" aria-hidden="true"><i>' . $tick . '%</i></span>';
            }
        }
        $out .= '</div>';
        // Legend
        $out .= '<ul class="drive-legend drive-capacity-legend">';
        foreach ($segs as $s) {
            $out .= '<li><span class="drive-swatch drive-swatch--' . $s['key'] . '" aria-hidden="true"></span>' . esc($s['label']) . ' <b>' . esc(driveUiBytes($s['bytes'])) . '</b></li>';
        }
        $out .= '</ul>';
        $out .= '</div>';

        // Right: countdown
        $basis = (int)($proj['basisDays'] ?? ($snap['basis_days'] ?? 0));
        $basisLabel = (string)($proj['basisLabel'] ?? ($basis > 0 ? 'over the last ' . $basis . ' days' : ''));
        $burn = (float)($proj['burnRatePerDay'] ?? ($snap['burn_rate_per_day'] ?? ($snap['burn_rate_bytes_per_day'] ?? 0)));
        $days = isset($proj['daysToFull']) && $proj['daysToFull'] !== null ? (int)ceil((float)$proj['daysToFull']) : null;
        // drive-design.md: 'growing' true | false | null (null = fewer than 2 snapshots a day apart); older sketch: 'notGrowing'
        $growing = array_key_exists('growing', $proj) ? $proj['growing'] : (!empty($proj['notGrowing']) ? false : ($burn > 0 ? true : false));
        $out .= '<div class="drive-capacity-side">';
        if ($growing === null) {
            $out .= '<p class="drive-countdown drive-countdown--nohistory" data-drive-countdown="nohistory"><strong>Not enough history yet</strong><span>' . esc($basisLabel !== '' ? $basisLabel : 'the pace shows after two nightly runs') . '</span></p>';
        } elseif ($growing === false) {
            $out .= '<p class="drive-countdown drive-countdown--flat" data-drive-countdown="flat"><strong>Not growing</strong><span>no net growth' . ($basisLabel !== '' ? ' ' . esc($basisLabel) : '') . '</span></p>';
        } elseif ($limit !== null && $days !== null) {
            $out .= '<p class="drive-countdown" data-drive-countdown="' . $days . '"><strong>Full in ~' . $days . ' ' . ($days === 1 ? 'day' : 'days') . '</strong>'
                  . '<span>' . esc(date('M j, Y', time() + $days * 86400)) . ' at the current pace</span></p>';
            $out .= '<p class="drive-burn text-secondary">Growing <b>' . esc(driveUiBytes($burn)) . '/day</b>' . ($basisLabel !== '' ? ' · ' . esc($basisLabel) : '') . '</p>';
        } else {
            $out .= '<p class="drive-burn text-secondary" data-drive-countdown="none">Growing <b>' . esc(driveUiBytes($burn)) . '/day</b>' . ($basisLabel !== '' ? ' · ' . esc($basisLabel) : '') . '</p>';
        }
        $out .= '</div>';
        if (!empty($snap['quota_note'])) {   // the script's quota self-check (design §0) — a footnote, never a banner
            $out .= '<p class="drive-quota-note text-tertiary" data-drive-quota-note>' . esc((string)$snap['quota_note']) . '</p>';
        }
        return $out . '</section>';
    }
}
