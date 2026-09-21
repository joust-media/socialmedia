<?php
/**
 * Treemap (squarified, computed in PHP) — absolute-positioned <a> tiles inside a fixed-aspect box,
 * every geometry a percentage so the map scales with its column. No JS needed: tiles are links, so
 * they are keyboard-focusable and work without a script.
 *
 *   driveSquarify(array $values, float $x, float $y, float $w, float $h): array
 *       $values: positive numbers sorted DESC. Returns [[x, y, w, h], …] in the same order
 *       (units of the box passed in — pass 100 × aspect for percentages).
 *
 *   driveTreemap(array $items, array $opts = []): string
 *       item: ['label' => 'kenda', 'bytes' => 612e9, 'stalePct' => 41, 'href' => '…',
 *              'sub' => 'Kenda Tires' (optional second line), 'pctOfDrive' => 40.2,
 *              'dashed' => bool (unfiled), 'attrs' => [], 'ariaLabel' => '…' (optional)]
 *       opts:  'aspect' => 1.6 (w/h), 'class', 'attrs', 'ariaLabel', 'total' (bytes the shares are of;
 *              default = sum of the items), 'minShare' (%, tiles under it are grouped: default 0 = off),
 *              'groupHref', 'groupLabel' (callable(int $n, float $bytes): string)
 *       Fill = four steps of the accent ramp by untouched share (drive-ui.php driveUiTileFill); the
 *       ink is computed per bin so tile text keeps ≥ 4.5:1 in either theme.
 *
 *   driveTreemapLegend(bool $withUnfiled = false): string
 */

if (!function_exists('driveSquarify')) {
    function driveSquarify(array $values, float $x, float $y, float $w, float $h): array
    {
        $n = count($values);
        if ($n === 0 || $w <= 0 || $h <= 0) return [];
        $total = array_sum($values);
        if ($total <= 0) return array_fill(0, $n, [$x, $y, 0, 0]);
        $scale = ($w * $h) / $total;
        $areas = [];
        foreach ($values as $v) $areas[] = max(0.0, (float)$v) * $scale;

        $rects = array_fill(0, $n, [0.0, 0.0, 0.0, 0.0]);
        $i = 0;
        $worst = static function (array $row, float $side): float {
            $sum = array_sum($row);
            if ($sum <= 0 || $side <= 0) return INF;
            $mx = max($row); $mn = min($row);
            $s2 = $side * $side; $sum2 = $sum * $sum;
            return max(($s2 * $mx) / $sum2, $sum2 / ($s2 * $mn));
        };
        while ($i < $n) {
            $side = min($w, $h);
            $row = []; $rowIdx = [];
            $j = $i;
            while ($j < $n) {
                if ($areas[$j] <= 0) { $rects[$j] = [$x, $y, 0.0, 0.0]; $j++; continue; }
                $cand = $row; $cand[] = $areas[$j];
                if (!$row || $worst($cand, $side) <= $worst($row, $side)) { $row = $cand; $rowIdx[] = $j; $j++; }
                else break;
            }
            if (!$row) { $i = $j; continue; }
            $sum = array_sum($row);
            if ($w >= $h) {                       // vertical strip on the left
                $stripW = $h > 0 ? $sum / $h : 0;
                $cy = $y;
                foreach ($rowIdx as $k => $idx) {
                    $rh = $stripW > 0 ? $row[$k] / $stripW : 0;
                    $rects[$idx] = [$x, $cy, $stripW, $rh];
                    $cy += $rh;
                }
                $x += $stripW; $w -= $stripW;
            } else {                              // horizontal strip on top
                $stripH = $w > 0 ? $sum / $w : 0;
                $cx = $x;
                foreach ($rowIdx as $k => $idx) {
                    $rw = $stripH > 0 ? $row[$k] / $stripH : 0;
                    $rects[$idx] = [$cx, $y, $rw, $stripH];
                    $cx += $rw;
                }
                $y += $stripH; $h -= $stripH;
            }
            $i = $j;
        }
        return $rects;
    }
}

if (!function_exists('driveTreemap')) {
    function driveTreemap(array $items, array $opts = []): string
    {
        $aspect = isset($opts['aspect']) && (float)$opts['aspect'] > 0 ? (float)$opts['aspect'] : 1.6;
        $items  = array_values(array_filter($items, static fn($it) => (float)($it['bytes'] ?? 0) > 0));
        usort($items, static fn($a, $b) => (float)$b['bytes'] <=> (float)$a['bytes']);
        $sum   = array_sum(array_map(static fn($it) => (float)$it['bytes'], $items));
        $total = isset($opts['total']) && (float)$opts['total'] > 0 ? (float)$opts['total'] : $sum;

        // Small tiles → one "N smaller …" tile (keeps the map readable and every name legible)
        $minShare = (float)($opts['minShare'] ?? 0);
        $grouped  = [];
        if ($minShare > 0 && $sum > 0) {
            $keep = [];
            foreach ($items as $it) {
                if (100 * (float)$it['bytes'] / $sum < $minShare) $grouped[] = $it; else $keep[] = $it;
            }
            if (count($grouped) >= 2) {
                $gb = array_sum(array_map(static fn($it) => (float)$it['bytes'], $grouped));
                $gs = 0.0;
                foreach ($grouped as $g) $gs += (float)($g['staleBytes'] ?? ((float)($g['stalePct'] ?? 0) / 100 * (float)$g['bytes']));
                $labelFn = $opts['groupLabel'] ?? null;
                $keep[] = [
                    'label'    => is_callable($labelFn) ? $labelFn(count($grouped), $gb) : count($grouped) . ' smaller clients',
                    'bytes'    => $gb,
                    'stalePct' => $gb > 0 ? 100 * $gs / $gb : 0,
                    'href'     => $opts['groupHref'] ?? '#drive-smaller',
                    'group'    => true,
                    'attrs'    => ['data-drive-group' => (string)count($grouped)],
                    'pctOfDrive' => $total > 0 ? 100 * $gb / $total : null,
                ];
                usort($keep, static fn($a, $b) => (float)$b['bytes'] <=> (float)$a['bytes']);
                $items = $keep;
            } else {
                $grouped = [];
            }
        }

        $W = 100.0 * $aspect; $H = 100.0;
        $rects = driveSquarify(array_map(static fn($it) => (float)$it['bytes'], $items), 0, 0, $W, $H);

        $cls = 'drive-treemap' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
        $attrs = '';
        foreach (($opts['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . '="' . esc($v) . '"';
        }
        $aria = !empty($opts['ariaLabel']) ? ' aria-label="' . esc($opts['ariaLabel']) . '"' : '';
        $out = '<div class="' . esc($cls) . '" role="list" style="--drive-aspect:' . esc(number_format($aspect, 3, '.', '')) . '"' . $aria
             . ' data-drive-tiles="' . count($items) . '" data-drive-total="' . esc((string)(int)round($sum)) . '"' . $attrs . '>';
        foreach ($items as $i => $it) {
            [$rx, $ry, $rw, $rh] = $rects[$i];
            if ($rw <= 0 || $rh <= 0) continue;
            $left = $rx / $W * 100; $top = $ry / $H * 100; $wp = $rw / $W * 100; $hp = $rh / $H * 100;
            $areaPct = $wp * $hp / 100;
            $sizeCls = $areaPct >= 8 ? 'lg' : ($areaPct >= 2.5 ? 'md' : ($areaPct >= 0.8 ? 'sm' : 'xs'));
            $fill = driveUiTileFill($it['stalePct'] ?? 0);
            $pctOfDrive = isset($it['pctOfDrive']) ? (float)$it['pctOfDrive'] : ($total > 0 ? 100 * (float)$it['bytes'] / $total : null);
            $stalePct = (float)($it['stalePct'] ?? 0);
            $label = (string)($it['label'] ?? '');
            $sizeStr = driveUiBytes($it['bytes']);
            $ariaLabel = $it['ariaLabel'] ?? ($label . ' — ' . $sizeStr
                . ($pctOfDrive !== null ? ', ' . driveUiPct($pctOfDrive, 1) . ' of Drive' : '')
                . (empty($it['group']) ? ', ' . driveUiPct($stalePct) . ' untouched 6+ months' : ''));
            $tcls = 'drive-tile drive-tile--' . $sizeCls . ' drive-tile--bin' . $fill['bin']
                  . (!empty($it['dashed']) ? ' drive-tile--dashed' : '') . (!empty($it['group']) ? ' drive-tile--group' : '');
            $style = 'left:' . number_format($left, 3, '.', '') . '%;top:' . number_format($top, 3, '.', '') . '%;width:' . number_format($wp, 3, '.', '') . '%;height:' . number_format($hp, 3, '.', '') . '%;'
                   . '--tile-bg:' . $fill['bg'] . ';--tile-ink:' . $fill['ink'];
            $tattrs = ' data-bytes="' . esc((string)(int)round((float)$it['bytes'])) . '" data-stale-bin="' . $fill['bin'] . '"';
            foreach (($it['attrs'] ?? []) as $k => $v) {
                $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
                if ($k !== '') $tattrs .= ' ' . $k . '="' . esc($v) . '"';
            }
            $href = (string)($it['href'] ?? '');
            $tag = $href !== '' ? 'a' : 'span';
            $out .= '<' . $tag . ($href !== '' ? ' href="' . esc($href) . '"' : ' tabindex="0"') . ' class="' . esc($tcls) . '" role="listitem" style="' . esc($style) . '"'
                  . ' title="' . esc($ariaLabel) . '" aria-label="' . esc($ariaLabel) . '"' . $tattrs . '>';
            $out .= '<span class="drive-tile-in" aria-hidden="true">';
            $out .= '<span class="drive-tile-name">' . esc($label) . '</span>';
            if ($sizeCls !== 'xs') $out .= '<span class="drive-tile-size">' . esc($sizeStr) . '</span>';
            if ($sizeCls === 'lg') {
                $meta = [];
                if ($pctOfDrive !== null) $meta[] = driveUiPct($pctOfDrive, $pctOfDrive < 10 ? 1 : 0) . ' of Drive';
                if (empty($it['group'])) $meta[] = driveUiPct($stalePct) . ' untouched';
                if ($meta) $out .= '<span class="drive-tile-meta">' . esc(implode(' · ', $meta)) . '</span>';
            }
            $out .= '</span></' . $tag . '>';
        }
        $out .= '</div>';
        if ($grouped) {
            // Same rows the "N smaller clients" tile opens in a sheet (drive.js); a plain disclosure without JS.
            $gb = array_sum(array_map(static fn($it) => (float)$it['bytes'], $grouped));
            $out .= '<details class="drive-smaller" id="drive-smaller" data-drive-smaller>'
                  . '<summary>' . esc(count($grouped) . ' smaller clients · ' . driveUiBytes($gb)) . '</summary>'
                  . '<ul class="drive-smaller-list">';
            foreach ($grouped as $g) {
                $href = (string)($g['href'] ?? '');
                $name = (string)($g['sub'] ?? $g['label'] ?? '');
                $row = '<span class="drive-smaller-name">' . esc($name) . ($name !== (string)($g['label'] ?? '') ? ' <span class="text-tertiary">' . esc((string)$g['label']) . '</span>' : '') . '</span>'
                     . '<span class="drive-smaller-size">' . esc(driveUiBytes($g['bytes'])) . ' · ' . esc(driveUiPct($g['stalePct'] ?? 0)) . ' untouched</span>';
                $out .= '<li>' . ($href !== '' ? '<a href="' . esc($href) . '">' . $row . '</a>' : $row) . '</li>';
            }
            $out .= '</ul></details>';
        }
        return $out;
    }
}

if (!function_exists('driveTreemapLegend')) {
    function driveTreemapLegend(bool $withUnfiled = false, string $lead = 'Untouched 6+ months'): string
    {
        $out = '<ul class="drive-legend drive-legend--tiles" aria-label="' . esc($lead) . '">';
        $out .= '<li class="drive-legend-lead">' . esc($lead) . '</li>';
        foreach (driveUiTileFills() as $f) {
            $out .= '<li><span class="drive-swatch" style="--tile-bg:' . $f['bg'] . '" aria-hidden="true"></span>' . esc(str_replace(' untouched', '', $f['label'])) . '</li>';
        }
        if ($withUnfiled) $out .= '<li><span class="drive-swatch drive-swatch--dashed" aria-hidden="true"></span>(unfiled) — not under a client folder</li>';
        return $out . '</ul>';
    }
}
