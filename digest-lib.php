<?php
/**
 * Morning summary renderer (the daily digest's email body) — used by notifyMorningSummary() (notify-lib.php).
 *
 *   render_summary(array $rows, int $leftover, array $config, array $waiting = []): ['text', 'html', 'company_count']
 *
 * $rows are CLIENT activity rows only (Joust's own actions and internal notes are filtered out before this), grouped
 * by client → item → batch. Every item links to the portal with an absolute deep link (portalItemUrl()); $waiting =
 * notifyUnanswered() rows → a "Waiting on Joust" section first (oldest wait first, with its age). No output at load.
 */

if (!function_exists('render_summary')) {
    function render_summary(array $rows, int $leftover, array $config, array $waiting = []): array {
        global $pdo;
        $companies = [];
        foreach ($rows as $r) {
            $cid = (int)$r['company_id'];
            if (!isset($companies[$cid])) {
                $companies[$cid] = ['name' => $r['company_name'] ?? ('Company #' . $cid), 'slug' => (string)($r['company_slug'] ?? ''), 'entries' => [], 'batched' => []];
            }
            $key = $r['batch_id'] ?: ('id:' . $r['id']);
            if (!isset($companies[$cid]['batched'][$key])) {
                $companies[$cid]['batched'][$key] = [
                    'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'], 'actor' => $r['actor'],
                    'created_at' => $r['created_at'], 'actions' => [], 'details' => [],
                ];
                $companies[$cid]['entries'][] =& $companies[$cid]['batched'][$key];
            }
            $companies[$cid]['batched'][$key]['actions'][] = $r['action'];
            if ($r['detail'] !== null && $r['detail'] !== '') {
                $companies[$cid]['batched'][$key]['details'][] = ['action' => $r['action'], 'text' => $r['detail']];
            }
        }
        $h = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };

        // ---- labels (batched per type) ------------------------------------------------------------------------
        $ids = ['post' => [], 'email' => [], 'page' => [], 'email_flow' => [], 'tire_image' => [], 'tire_series' => []];
        foreach ($companies as $co) {
            foreach ($co['entries'] as $e) {
                if (isset($ids[$e['entity_type']])) $ids[$e['entity_type']][] = (int)$e['entity_id'];
            }
        }
        $labels = []; $meta = [];
        $in = static function (array $list): array {
            $list = array_values(array_unique(array_map('intval', $list)));
            return [$list, implode(',', array_fill(0, count($list), '?'))];
        };
        $withSeries = function_exists('hasTireSeries') && hasTireSeries($pdo);
        $seriesNames = [];
        if ($ids['tire_image']) {
            [$list, $ph] = $in($ids['tire_image']);
            try {
                $hasName   = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0;
                $s = $pdo->prepare("SELECT ti.id, ti.tire_id, ti.caption, " . ($hasName ? 'ti.display_name' : "'' AS display_name") . ", "
                    . ($withSeries ? 'ti.series_id' : 'NULL AS series_id') . ", t.name AS tire_name
                      FROM tire_images ti INNER JOIN tires t ON t.id = ti.tire_id WHERE ti.id IN ($ph)");
                $s->execute($list);
                foreach ($s->fetchAll() as $r) {
                    $sid = isset($r['series_id']) && $r['series_id'] !== null ? (int)$r['series_id'] : 0;
                    if ($sid > 0) $ids['tire_series'][] = $sid;
                    $labels['tire_image'][(int)$r['id']] = ['name' => imageDisplayLabel(['display_name' => $r['display_name'] ?? '', 'caption' => $r['caption'] ?? '', 'id' => (int)$r['id']]),
                                                          'tire' => (string)$r['tire_name'], 'series_id' => $sid];
                    $meta['tire_image'][(int)$r['id']] = ['tire_id' => (int)$r['tire_id'], 'series_id' => $sid];
                }
            } catch (Throwable $e) { $labels['tire_image'] = []; }
        }
        if ($ids['tire_series'] && $withSeries) {
            [$list, $ph] = $in($ids['tire_series']);
            try {
                $s = $pdo->prepare("SELECT s.id, s.name, s.tire_id, t.name AS tire_name FROM tire_series s INNER JOIN tires t ON t.id = s.tire_id WHERE s.id IN ($ph)");
                $s->execute($list);
                foreach ($s->fetchAll() as $r) {
                    $seriesNames[(int)$r['id']] = (string)$r['name'];
                    $labels['tire_series'][(int)$r['id']] = trim((string)$r['tire_name']) . ' · ' . (string)$r['name'];
                    $meta['tire_series'][(int)$r['id']] = ['tire_id' => (int)$r['tire_id']];
                }
            } catch (Throwable $e) {}
        }
        foreach ((array)($labels['tire_image'] ?? []) as $iid => $info) {
            $prefix = ($info['series_id'] > 0 && isset($seriesNames[$info['series_id']])) ? $seriesNames[$info['series_id']] : $info['tire'];
            $labels['tire_image'][$iid] = ($prefix !== '' ? $prefix . ' · ' : '') . $info['name'];
        }
        if ($ids['email_flow'] && function_exists('hasEmailFlowsTable') && hasEmailFlowsTable($pdo)) {
            [$list, $ph] = $in($ids['email_flow']);
            try {
                $s = $pdo->prepare("SELECT id, name, slug FROM email_flows WHERE id IN ($ph)");
                $s->execute($list);
                foreach ($s->fetchAll() as $r) { $labels['email_flow'][(int)$r['id']] = 'Flow ' . (string)$r['name']; $meta['email_flow'][(int)$r['id']] = ['slug' => (string)$r['slug']]; }
            } catch (Throwable $e) {}
        }
        if ($ids['page'] && function_exists('hasPagesTable') && hasPagesTable($pdo)) {
            [$list, $ph] = $in($ids['page']);
            try {
                $s = $pdo->prepare("SELECT id, title, slug FROM pages WHERE id IN ($ph)");
                $s->execute($list);
                foreach ($s->fetchAll() as $r) $labels['page'][(int)$r['id']] = 'Page ' . pageDisplayLabel($r);
            } catch (Throwable $e) {}
        }
        if ($ids['email'] && function_exists('hasEmailsTable') && hasEmailsTable($pdo)) {
            [$list, $ph] = $in($ids['email']);
            try {
                $s = $pdo->prepare("SELECT id, code, title FROM emails WHERE id IN ($ph)");
                $s->execute($list);
                foreach ($s->fetchAll() as $r) $labels['email'][(int)$r['id']] = emailDisplayLabel($r);
            } catch (Throwable $e) {}
        }
        if ($ids['post']) {
            [$list, $ph] = $in($ids['post']);
            $nameSel = hasPostsNameColumn($pdo) ? 'name' : "'' AS name";
            $s = $pdo->prepare("SELECT id, {$nameSel}, caption FROM posts WHERE id IN ($ph)");
            $s->execute($list);
            foreach ($s->fetchAll() as $r) {
                $labels['post'][(int)$r['id']] = postDisplayLabel(['name' => $r['name'] ?? '', 'caption' => $r['caption'] ?? '', 'id' => (int)$r['id']]);
            }
        }
        $label = static function (string $type, int $id) use ($labels): string {
            if (isset($labels[$type][$id])) return (string)$labels[$type][$id];
            switch ($type) {
                case 'post': return 'Post #' . $id;
                case 'tire_image': return 'Image #' . $id;
                case 'tire_series': return 'Series #' . $id;
                case 'library_image': return 'Library image #' . $id;   // never the on-disk filename
                case 'task': return 'Task #' . $id;
                case 'email': return 'Email #' . $id;
                case 'email_flow': return 'Flow #' . $id;
                case 'page': return 'Page #' . $id;
            }
            return ucfirst(str_replace('_', ' ', $type)) . ' #' . $id;
        };
        $link = static function (string $type, int $id, string $slug) use ($meta): string {
            return function_exists('portalItemUrl') ? portalItemUrl($type, $id, $slug, $meta[$type][$id] ?? []) : '';
        };
        $btn = 'color:#007aff;text-decoration:none;font-weight:600';

        $textOut  = "Morning summary — " . date('l, F j') . "\n" . str_repeat('=', 40) . "\n\n";
        $htmlOut  = '<div style="font:15px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#1c1c1e;max-width:640px;margin:0 auto;padding:20px">';
        $htmlOut .= '<h1 style="font-size:22px;margin:0 0 2px;letter-spacing:-.3px">Morning summary</h1>';
        $htmlOut .= '<div style="color:#8e8e93;font-size:13px;margin-bottom:20px">' . $h(date('l, F j, Y')) . '</div>';

        // ---- Waiting on Joust ---------------------------------------------------------------------------------
        if ($waiting) {
            $textOut .= "## Waiting on Joust (" . count($waiting) . ")\n";
            $htmlOut .= '<h2 style="font-size:16px;margin:0 0 8px;color:#ff9500">Waiting on Joust · ' . count($waiting) . '</h2>';
            foreach ($waiting as $w) {
                $info = notifyItemInfo($pdo, (string)$w['entity_type'], (int)$w['entity_id']);
                if (!$info['exists']) continue;
                $mins = max(0, (int)floor((time() - (int)strtotime((string)$w['first_at'])) / 60));
                [$slide, $body] = commentSlideSplit((string)$w['last_detail']);
                $ex = mb_substr(trim($body), 0, 200) . (mb_strlen(trim($body)) > 200 ? '…' : '');
                $textOut .= "  • {$info['company_name']} — {$info['title']} (waiting " . notifyAgeLabel($mins) . ")\n"
                          . "      " . ($slide > 0 ? "on slide {$slide}: " : '') . '"' . str_replace("\n", ' ', $ex) . "\"\n      {$info['url']}\n";
                $htmlOut .= '<div style="padding:10px 12px;margin:0 0 8px;border-radius:12px;background:#fff4e5">'
                          . '<a href="' . $h($info['url']) . '" style="' . $btn . '">' . $h($info['title']) . '</a>'
                          . ' <span style="color:#8e8e93;font-size:13px">· ' . $h($info['company_name']) . ' · waiting ' . $h(notifyAgeLabel($mins)) . '</span>'
                          . '<div style="margin-top:4px;color:#3a3a3c;font-style:italic">' . ($slide > 0 ? '<span style="font-style:normal;color:#8e8e93">on slide ' . $slide . ':</span> ' : '') . '“' . $h($ex) . '”</div></div>';
            }
            $textOut .= "\n";
        }

        // ---- What clients did since the last summary ----------------------------------------------------------
        foreach ($companies as $co) {
            $textOut .= "## " . $co['name'] . "\n";
            $htmlOut .= '<h2 style="font-size:16px;margin:24px 0 8px;padding-bottom:4px;border-bottom:1px solid #e5e5ea">' . $h($co['name']) . '</h2>';
            foreach ($co['entries'] as $e) {
                $actions = array_values(array_unique($e['actions']));
                $type = (string)$e['entity_type']; $eid = (int)$e['entity_id'];
                $entityLabel = $label($type, $eid);
                $url = $link($type, $eid, $co['slug']);
                $verb = count($actions) > 1 ? 'edits (' . implode(', ', array_map('actionLabel', $actions)) . ')' : actionLabel($actions[0]);
                if (count($actions) > 1 && function_exists('activityPrimaryAction')) {
                    $p = activityPrimaryAction($actions);
                    if (in_array($p, ['approved', 'denied'], true)) $verb = actionLabel($p);
                }
                $when = date('M j g:ia', strtotime($e['created_at']));
                $textOut .= "  • {$entityLabel} — {$verb} ({$when})\n";
                foreach ($e['details'] as $d) {
                    if ($d['action'] === 'commented') {
                        [$slideNo, $body] = commentSlideSplit((string)$d['text']);
                        $textOut .= "      " . ($slideNo > 0 ? "on slide {$slideNo}: " : '') . '"' . str_replace("\n", ' ', mb_substr($body, 0, 240)) . (mb_strlen($body) > 240 ? '…' : '') . "\"\n";
                    } elseif (strpos($d['action'], 'edited_') === 0) {
                        $textOut .= "      " . str_replace("\n", ' ', mb_substr($d['text'], 0, 640)) . "\n";
                    }
                }
                if ($url !== '') $textOut .= "      {$url}\n";
                $htmlOut .= '<div style="padding:10px 0;border-bottom:1px solid #f2f2f7">';
                $htmlOut .= ($url !== '' ? '<a href="' . $h($url) . '" style="' . $btn . '">' . $h($entityLabel) . '</a>' : '<strong>' . $h($entityLabel) . '</strong>')
                          . ' <span style="color:#3a3a3c">' . $h($verb) . '</span> <span style="color:#8e8e93;font-size:12px">· ' . $h($when) . '</span>';
                foreach ($e['details'] as $d) {
                    if ($d['action'] === 'commented') {
                        [$slideNo, $body] = commentSlideSplit((string)$d['text']);
                        $htmlOut .= '<div style="margin-top:6px;padding:8px 10px;background:#f2f2f7;border-left:3px solid #007aff;border-radius:4px;font-style:italic;color:#3a3a3c">'
                                  . ($slideNo > 0 ? '<span style="font-style:normal;color:#8e8e93">on slide ' . $slideNo . ':</span> ' : '')
                                  . '“' . $h(mb_substr($body, 0, 240)) . (mb_strlen($body) > 240 ? '…' : '') . '”</div>';
                    } elseif (strpos($d['action'], 'edited_') === 0) {
                        $htmlOut .= '<div style="margin-top:4px;font-size:12px;color:#8e8e93">' . $h(mb_substr($d['text'], 0, 640)) . '</div>';
                    }
                }
                $htmlOut .= '</div>';
            }
            $textOut .= "\n";
        }

        $home = function_exists('portalUrl') ? portalUrl('index') : '';
        if ($leftover > 0) {
            $textOut .= "…and {$leftover} more older update(s) — they are in the portal's Activity feed: {$home}\n\n";
            $htmlOut .= '<p style="color:#8e8e93;font-size:13px;margin-top:16px">…and ' . $leftover . ' more older update(s) — they are in the portal’s '
                      . '<a href="' . $h($home) . '" style="color:#007aff">Activity feed</a>.</p>';
        }
        $textOut .= "Joust portal: {$home}\n";
        $htmlOut .= '<hr style="border:none;border-top:1px solid #e5e5ea;margin:24px 0 12px">';
        $htmlOut .= '<div style="color:#8e8e93;font-size:12px">Your Morning summary from the Joust portal — client activity since the last one; your own actions are left out. '
                  . '<a href="' . $h($home) . '" style="color:#007aff">Open the portal</a></div></div>';

        return [
            'text'          => $textOut,
            'html'          => '<!doctype html><html><body style="margin:0;background:#ffffff">' . $htmlOut . '</body></html>',
            'company_count' => count($companies),
        ];
    }
}
