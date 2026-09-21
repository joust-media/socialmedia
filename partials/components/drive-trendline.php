<?php
/**
 * Usage over time — inline SVG: the history polyline, a dashed projection from today towards the
 * limit (only when there is a limit and Drive is growing), hairline reference lines at the limit
 * and at 80%. Axis labels are minimal (first date · Today · the projected "full" date).
 *
 *   driveTrendline(array $history, ?float $limit, array $proj = [], array $opts = []): string
 *       $history: [['date' => 'Y-m-d', 'usageBytes' => int], …] any order (sorted here)
 *       $proj:    driveProjection() shape — burnRatePerDay, daysToFull|null, notGrowing
 *       $opts:    'class', 'ariaLabel', 'id'
 *   Returns '' + a note when there are fewer than 2 points (the caller shows the empty state).
 */
if (!function_exists('driveTrendline')) {
    function driveTrendline(array $history, ?float $limit, array $proj = [], array $opts = []): string
    {
        $pts = [];
        foreach ($history as $h) {
            $t = isset($h['date']) ? strtotime((string)$h['date']) : false;
            if ($t === false) continue;
            $pts[] = ['t' => (int)floor($t / 86400), 'v' => max(0.0, (float)($h['usageBytes'] ?? 0)), 'date' => (string)$h['date']];
        }
        usort($pts, static fn($a, $b) => $a['t'] <=> $b['t']);
        $n = count($pts);
        if ($n < 2) {
            return '<p class="drive-trend-empty text-secondary" data-drive-trend-empty>Not enough history yet — the line appears after two nightly runs.</p>';
        }
        $limit = $limit !== null && $limit > 0 ? (float)$limit : null;
        $t0 = $pts[0]['t']; $tN = $pts[$n - 1]['t'];
        $span = max(1, $tN - $t0);
        $last = $pts[$n - 1];

        // Projection window: the dashed line runs from today to the "full" day, but never squeezes the
        // history below half the plot (a 400-day countdown would otherwise flatten 90 days of data).
        $daysToFull = isset($proj['daysToFull']) && $proj['daysToFull'] !== null ? (int)ceil((float)$proj['daysToFull']) : null;
        $burn = (float)($proj['burnRatePerDay'] ?? 0);
        $growing = array_key_exists('growing', $proj) ? $proj['growing'] === true : (empty($proj['notGrowing']) && $burn > 0);
        $growing = $growing && $burn > 0;
        $projDays = 0; $reachesLimit = false;
        if ($limit !== null && $growing && $daysToFull !== null && $daysToFull > 0) {
            $projDays = min($daysToFull, max(30, $span));
            $reachesLimit = $projDays >= $daysToFull;
        }
        $tEnd = $tN + $projDays;
        $vMax = max($last['v'], ...array_column($pts, 'v'));
        $yMax = $limit !== null ? $limit * 1.06 : max(1.0, $vMax * 1.15);

        // Plot geometry (viewBox units ≈ CSS px in a third-width card, so 10px labels stay legible)
        $W = 360; $H = 150; $padL = 8; $padR = 8; $padT = 16; $padB = 20;
        $pw = $W - $padL - $padR; $ph = $H - $padT - $padB;
        $xOf = static fn(int $t) => $padL + ($tEnd > $t0 ? ($t - $t0) / ($tEnd - $t0) : 0) * $pw;
        $yOf = static fn(float $v) => $padT + $ph - ($yMax > 0 ? min(1.0, $v / $yMax) : 0) * $ph;
        $f = static fn(float $x) => number_format($x, 1, '.', '');

        $poly = [];
        foreach ($pts as $p) $poly[] = $f($xOf($p['t'])) . ',' . $f($yOf($p['v']));
        $polyStr = implode(' ', $poly);
        $areaStr = $f($xOf($t0)) . ',' . $f($padT + $ph) . ' ' . $polyStr . ' ' . $f($xOf($tN)) . ',' . $f($padT + $ph);

        $cls = 'drive-trend' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
        $id  = !empty($opts['id']) ? preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)$opts['id']) : 'drive-trend';
        $label = $opts['ariaLabel'] ?? ('Drive usage over the last ' . $span . ' days: ' . driveUiBytes($pts[0]['v']) . ' on ' . driveUiDate($pts[0]['date'])
               . ' to ' . driveUiBytes($last['v']) . ' today' . ($limit !== null ? ', limit ' . driveUiBytes($limit) : '')
               . ($projDays > 0 && $daysToFull !== null ? ', projected full in about ' . $daysToFull . ' days' : ''));

        $out  = '<svg class="' . esc($cls) . '" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="' . esc($id) . '-title"'
              . ' data-drive-points="' . $n . '" data-drive-projection="' . ($projDays > 0 ? '1' : '0') . '">';
        $out .= '<title id="' . esc($id) . '-title">' . esc($label) . '</title>';
        // reference lines
        if ($limit !== null) {
            $yL = $yOf($limit); $y80 = $yOf($limit * 0.8);
            $out .= '<line class="drive-trend-ref drive-trend-ref--limit" x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $f($yL) . '" y2="' . $f($yL) . '"/>';
            $out .= '<text class="drive-trend-lbl" x="' . $padL . '" y="' . $f($yL - 5) . '">Limit · ' . esc(driveUiBytes($limit)) . '</text>';
            $out .= '<line class="drive-trend-ref drive-trend-ref--80" x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $f($y80) . '" y2="' . $f($y80) . '"/>';
            $out .= '<text class="drive-trend-lbl" x="' . $padL . '" y="' . $f($y80 - 5) . '">80% · ' . esc(driveUiBytes($limit * 0.8)) . '</text>';
        }
        // baseline
        $out .= '<line class="drive-trend-axis" x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . ($padT + $ph) . '" y2="' . ($padT + $ph) . '"/>';
        // area wash + history line
        $out .= '<polygon class="drive-trend-area" points="' . esc($areaStr) . '"/>';
        $out .= '<polyline class="drive-trend-line" points="' . esc($polyStr) . '"/>';
        // projection
        if ($projDays > 0) {
            $vEnd = $last['v'] + $burn * $projDays;
            if ($limit !== null && $vEnd > $limit) $vEnd = $limit;
            $out .= '<line class="drive-trend-proj" x1="' . $f($xOf($tN)) . '" y1="' . $f($yOf($last['v'])) . '" x2="' . $f($xOf($tEnd)) . '" y2="' . $f($yOf($vEnd)) . '"/>';
            if ($reachesLimit) {
                $out .= '<circle class="drive-trend-full" cx="' . $f($xOf($tEnd)) . '" cy="' . $f($yOf($vEnd)) . '" r="4"/>';
            }
        }
        // today marker
        $out .= '<circle class="drive-trend-dot" cx="' . $f($xOf($tN)) . '" cy="' . $f($yOf($last['v'])) . '" r="4"/>';
        // x labels
        $yLbl = $H - 8;
        $out .= '<text class="drive-trend-lbl" x="' . $padL . '" y="' . $yLbl . '">' . esc(driveUiDate($pts[0]['date'])) . '</text>';
        $todayX = $xOf($tN);
        $roomRight = ($W - $padR) - $todayX;           // space for the "Full ~" label after Today
        $anchor = $projDays > 0 && $roomRight >= 90 ? 'middle' : 'end';
        $out .= '<text class="drive-trend-lbl drive-trend-lbl--today" x="' . $f($todayX) . '" y="' . $yLbl . '" text-anchor="' . $anchor . '">Today · ' . esc(driveUiBytes($last['v'])) . '</text>';
        if ($projDays > 0) {
            $fullDate = date('M j', time() + $daysToFull * 86400);   // same clock as the countdown in the capacity strip
            $fullTxt  = $reachesLimit ? 'Full ~' . $fullDate : '→ full ~' . $fullDate;
            if ($roomRight >= 90) {
                $out .= '<text class="drive-trend-lbl drive-trend-lbl--full" x="' . ($W - $padR) . '" y="' . $yLbl . '" text-anchor="end">' . esc($fullTxt) . '</text>';
            } else {   // too close to Today on the axis: hang it off the projection's end instead
                $vEndLbl = $limit !== null ? min($limit, $last['v'] + $burn * $projDays) : $last['v'];
                $out .= '<text class="drive-trend-lbl drive-trend-lbl--full" x="' . ($W - $padR) . '" y="' . $f(max($padT + 10, $yOf($vEndLbl) + 14)) . '" text-anchor="end">' . esc($fullTxt) . '</text>';
            }
        }
        $out .= '</svg>';
        return $out;
    }
}
