<?php
/**
 * Navigation + Manage (the Studio redesign's last phase): the admin tab bar (≤ 6 items, Manage instead of
 * Studio, Projects moved under Manage, Emails + Pages merged only when all three modules are on), every
 * retired Studio / Classic admin URL landing on a working page, the Manage sections, the seat gates, the
 * module toggle living ONLY in Manage → Clients, client settings, series management on the tire, the
 * Classic admin parity bits (Download media) and the Posts header.
 * Seed: kenda (1, tires) · privacybee (2, emails + pages) · hmf (3, nothing). Tires 1–3 belong to kenda.
 */
require __DIR__ . '/lib.php';

/** Follow up to 5 redirects; returns [final response, [location, …]]. */
function follow(string $path, string $role = 'admin'): array {
    $chain = [];
    $r = get($path, $role);
    for ($i = 0; $i < 5 && in_array($r['code'], [301, 302, 303], true); $i++) {
        $chain[] = $r['location'];
        $r = get(preg_replace('#^(https?://[^/]+)?/portal/?#', '', $r['location']), $role);
    }
    return [$r, $chain];
}
/** data-tab="…" keys of the tab bar, in order. */
function tabs(string $html): array {
    if (!preg_match('#<nav class="ui-tabbar[^"]*"[^>]*>(.*?)</nav>#s', $html, $m)) return [];
    preg_match_all('#data-tab="([a-z]+)"#', $m[1], $t);
    return $t[1];
}

// ---- tab bar ---------------------------------------------------------------------------------------
test('admin tab bar: Home · Assets · Tires · Posts · Manage (no Studio, no Projects)', function () {
    $b = status(get('posts.php?client=kenda'), 200)['body'];
    is(tabs($b), ['home', 'assets', 'tires', 'posts', 'manage']);
    has($b, '<span class="ui-tab-label">Manage</span>');
    hasNot($b, 'ui-tab--studio');
    is(tabs(status(get('emails.php?client=privacybee'), 200)['body']), ['home', 'assets', 'posts', 'emails', 'pages', 'manage'], 'privacybee: 6');
    is(tabs(status(get(''), 200)['body']), ['home', 'assets', 'posts', 'manage'], 'unscoped');
});
test('unscoped Assets renders a client chooser in place (no bounce to Home)', function () {
    $b = status(get('assets.php'), 200)['body'];
    is(tabs($b), ['home', 'assets', 'posts', 'manage']);
    ok(preg_match('#class="ui-tab ui-tab--assets is-active"#', $b) === 1, 'Assets lit');
    has($b, 'data-assets-clients="library"');
});
test('client tab bar is unchanged (Projects, never Manage)', function () {
    is(tabs(status(get('posts.php?client=kenda', 'client'), 200)['body']), ['home', 'assets', 'tires', 'posts', 'projects']);
    is(tabs(status(get('emails.php?client=privacybee', 'client'), 200)['body']), ['home', 'assets', 'posts', 'emails', 'pages', 'projects']);
});
test('active tab: Manage for manage / drive / tools pages; Tires for the tire form; Emails for its form', function () {
    foreach (['manage.php?client=kenda', 'manage.php?client=kenda&section=tools', 'drive.php', 'prompts.php', 'vehicles.php', 'build.php?client=kenda', 'projects.php?client=kenda'] as $p) {
        ok(preg_match('#class="ui-tab ui-tab--manage is-active"#', status(get($p), 200)['body']) === 1, "$p → Manage active");
    }
    ok(preg_match('#class="ui-tab ui-tab--tires is-active"#', status(get('add-feature.php?client=kenda&module=tires&edit_item=1'), 200)['body']) === 1, 'tire form → Tires');
    ok(preg_match('#class="ui-tab ui-tab--emails is-active"#', status(get('add-email.php?client=privacybee&edit=1'), 200)['body']) === 1, 'email form → Emails');
    ok(preg_match('#class="ui-tab ui-tab--pages is-active"#', status(get('add-page.php?client=privacybee'), 200)['body']) === 1, 'page form → Pages');
});
test('all three modules on → Emails/Pages share one tab (≤ 6), with an Emails · Pages switch; the client keeps both', function () {
    $mid = (int)q1("SELECT id FROM modules WHERE slug = 'tires'");
    db()->prepare("INSERT IGNORE INTO company_modules (company_id, module_id, sort_order) VALUES (2, ?, 1)")->execute([$mid]);
    try {
        $b = status(get('pages.php?client=privacybee'), 200)['body'];
        is(tabs($b), ['home', 'assets', 'tires', 'posts', 'emails', 'manage']);
        has($b, '<span class="ui-tab-label ui-tab-label--long">Emails/Pages</span>');
        ok(preg_match('#class="ui-tab ui-tab--emails is-active"#', $b) === 1, 'Pages lights the merged tab');
        has($b, 'data-mail-switch="pages"'); has($b, 'data-mail-switch="emails"');
        has(status(get('emails.php?client=privacybee'), 200)['body'], 'data-mail-switch');
        is(tabs(status(get('pages.php?client=privacybee', 'client'), 200)['body']), ['home', 'assets', 'tires', 'posts', 'emails', 'pages', 'projects'], 'client nav unchanged');
        hasNot(status(get('pages.php?client=privacybee', 'client'), 200)['body'], 'data-mail-switch');
    } finally {
        db()->prepare("DELETE FROM company_modules WHERE company_id = 2 AND module_id = ?")->execute([$mid]);
    }
    hasNot(status(get('emails.php?client=privacybee'), 200)['body'], 'data-mail-switch', 'separate tabs again');
});

// ---- every old route lands on a working page ---------------------------------------------------------
$routes = [
    'studio.php'                                      => 'manage.php',
    'studio.php?tab=clients'                          => 'manage.php?section=clients',
    'studio.php?tab=clients&edit=2'                   => 'manage.php?section=clients&edit=2',
    'studio.php?client=kenda'                         => 'manage.php?client=kenda',
    'studio.php?client=kenda&msg=Saved'               => 'manage.php?client=kenda&msg=Saved',
    'studio.php?client=kenda&tab=posts'               => 'posts.php?client=kenda',
    'studio.php?client=kenda&tab=uploads'             => 'posts.php?client=kenda&upload=1&dest=post&each=1',
    'studio.php?client=kenda&tab=batch'               => 'posts.php?client=kenda&upload=1&dest=post&each=1',
    'studio.php?client=kenda&tab=compose'             => 'posts.php?client=kenda&newpost=1',
    'studio.php?client=kenda&newpost=1'               => 'posts.php?client=kenda&newpost=1',
    'studio.php?client=privacybee&tab=emails'         => 'emails.php?client=privacybee',
    'studio.php?client=privacybee&tab=pages'          => 'pages.php?client=privacybee',
    'studio.php?client=kenda&tab=renders'             => 'assets.php?client=kenda&view=collections',
    'studio.php?client=kenda&tab=renders&tire=1&series=2' => 'assets.php?client=kenda&view=collections&item=1&series=2&manage=series',
    'studio.php?client=kenda&tab=export'              => 'manage.php?client=kenda&section=export',
    'studio.php?client=kenda&tab=export&tire=1&series=2' => 'manage.php?client=kenda&section=export&tire=1&series=2',
    'studio.php?client=kenda&tab=clients'             => 'manage.php?client=kenda&section=clients',
    'studio.php?client=kenda&tab=nonsense'            => 'manage.php?client=kenda',
    'studio.php?client=kenda&upload=1&dest=library'   => 'posts.php?client=kenda&upload=1&dest=library',
    'studio.php?upload=1'                             => 'posts.php?upload=1',
    'studio.php?client=nobody'                        => 'manage.php',
    'admin.php'                                       => 'manage.php',
    'admin.php?client=kenda'                          => 'manage.php?client=kenda',
    'admin.php?client=kenda&tab=posts'                => 'posts.php?client=kenda',
    'legacy/admin.php'                                => 'manage.php',
    'legacy/admin.php?client=kenda'                   => 'manage.php?client=kenda',
    'legacy/feed.php?client=kenda'                    => 'posts.php?client=kenda',
    'legacy/library.php?client=kenda'                 => 'assets.php?client=kenda&view=library',
    'legacy/features.php?client=kenda&module=tires&item=1' => 'assets.php?client=kenda&view=collections&item=1',
    'features.php?client=privacybee&module=emails'    => 'emails.php?client=privacybee',
    'features.php?client=privacybee&module=pages'     => 'pages.php?client=privacybee',
    'tires.php'                                       => 'assets.php?view=collections',   // the unscoped Tires chooser
    'add-tire.php'                                    => '/portal/',
    'batch.php'                                       => 'posts.php?upload=1',
    'manage.php?section=drive'                        => 'drive.php',
    'assets.php?client=privacybee&view=collections'   => 'assets.php?client=privacybee',   // no Tires tab → the Library
    'legacy/library.php'                              => 'assets.php?view=library',        // unscoped → the Assets chooser
];
foreach ($routes as $from => $to) {
    test("old route /{$from} → {$to} (200)", function () use ($from, $to) {
        [$r, $chain] = follow($from);
        ok($chain !== [], 'redirects');
        ok(substr($chain[0], -strlen($to)) === $to || strpos($chain[0], $to . '&') !== false || strpos($chain[0], $to) !== false, "first hop {$chain[0]}");
        is(count($chain), 1, 'one hop: ' . implode(' → ', $chain));
        status($r, 200, 'the target answers');
        hasNot($r['body'], 'Fatal error');
    });
}
test('the client seat: old admin routes and Manage → sign-in; client-admin.php refuses', function () {
    foreach (['studio.php', 'studio.php?client=kenda&tab=posts', 'admin.php?client=kenda', 'legacy/admin.php?client=kenda', 'manage.php',
              'manage.php?client=kenda', 'manage.php?client=kenda&section=export', 'manage.php?client=kenda&section=tools', 'drive.php'] as $p) {
        $r = get($p, 'client');
        is($r['code'], 302, $p);
        has($r['location'], 'login', $p);
    }
    $r = post('client-admin.php?client=kenda', ['action' => 'module_toggle', 'id' => 3, 'module' => 'emails', 'to' => 1], 'client', [], ['Accept' => 'application/json']);
    is($r['code'], 403, 'client-admin: client seat');
    is((int)q1("SELECT COUNT(*) FROM company_modules WHERE company_id = 3"), 0, 'nothing switched on');
});

// ---- Manage sections ---------------------------------------------------------------------------------
test('Manage: four sections, each one renders', function () {
    $c = status(get('manage.php?client=kenda'), 200)['body'];
    foreach (['clients', 'export', 'drive', 'tools'] as $k) has($c, 'data-manage-section-link="' . $k . '"');
    has($c, 'data-manage-section="clients"');
    has($c, 'data-client-edit="1"', 'scoped → that client\'s card');
    has($c, 'data-client-settings', 'Settings form');
    has($c, 'data-client-modules', 'module toggles');
    hasNot(status(get('manage.php?client=kenda&section=clients&edit=0'), 200)['body'], 'data-client-edit=', 'edit=0 → the list');
    has(status(get('manage.php'), 200)['body'], 'data-client-new', 'unscoped: New client');
    $e = status(get('manage.php?client=kenda&section=export&tire=1&series=2'), 200)['body'];
    has($e, 'data-manage-section="export"'); has($e, 'data-export-form'); hasNot($e, 'data-previews ', 'Image previews moved to Tools'); has($e, 'data-previews-moved');
    has($e, '<option value="1" selected>Klever AT2</option>', 'tire preselected');
    has($e, '"series":2', 'series preselected (StudioConfig.export)');
    $u = status(get('manage.php?section=export'), 200)['body'];
    has($u, 'data-manage-clients="export"', 'unscoped: pick a client'); hasNot($u, 'data-export-form');
    $d = status(get('drive.php'), 200)['body'];
    ok(preg_match('#class="ui-segmented-item is-active"[^>]*aria-current="page" data-manage-section-link="drive"#', $d) === 1, 'Drive carries the Manage switch, Drive active');
    $t = status(get('manage.php?client=kenda&section=tools'), 200)['body'];
    has($t, 'data-tool="new-tire"');
    has($t, 'add-feature.php?client=kenda&amp;module=tires');
    has($t, 'assets.php?client=kenda&amp;view=collections&amp;item=1&amp;manage=series', 'Manage series per tire');
    foreach (['builder', 'prompts', 'vehicles', 'projects', 'digest'] as $k) has($t, 'data-tool="' . $k . '"', $k);
    hasNot($t, 'data-emails-import', 'kenda has no Emails');
    $p = status(get('manage.php?client=privacybee&section=tools'), 200)['body'];
    has($p, 'data-emails-import'); has($p, 'data-emails-groups'); has($p, 'data-tool="pages-repair"'); hasNot($p, 'data-tool="new-tire"');
    $h = status(get('manage.php?client=hmf&section=tools'), 200)['body'];
    hasNot($h, 'data-emails-import'); hasNot($h, 'data-tool="new-tire"'); hasNot($h, 'data-tool="pages-repair"');
    has(status(get('manage.php?section=tools'), 200)['body'], 'data-manage-clients="tools"', 'unscoped Tools: pick a client');
});

// ---- the module toggle lives ONLY in Manage → Clients ------------------------------------------------
test('module toggles: only in Manage → Clients', function () {
    foreach (['emails.php?client=privacybee', 'pages.php?client=privacybee', 'add-email.php?client=privacybee', 'add-page.php?client=privacybee',
              'manage.php?client=privacybee&section=tools', 'manage.php?client=privacybee&section=export', 'posts.php?client=privacybee', '?client=privacybee',
              'assets.php?client=kenda&view=collections&item=1'] as $p) {
        hasNot(status(get($p), 200)['body'], 'value="module_toggle"', $p);
    }
    $c = status(get('manage.php?client=privacybee'), 200)['body'];
    is(substr_count($c, 'value="module_toggle"'), 3, 'Tires / Emails / Pages in the client card');
});
test('module toggle works: on → the tab shows, off → gone (client-admin.php)', function () {
    $r = status(post('client-admin.php?client=hmf', ['action' => 'module_toggle', 'id' => 3, 'module' => 'emails', 'to' => 1], 'admin', [], ['Accept' => 'application/json']), 200);
    is($r['json']['enabled'] ?? null, true);
    has($r['json']['redirect'] ?? '', 'manage.php?client=hmf&section=clients&edit=3');
    ok(in_array('emails', tabs(status(get('?client=hmf', 'client'), 200)['body']), true), 'client sees Emails');
    status(post('client-admin.php?client=hmf', ['action' => 'module_toggle', 'id' => 3, 'module' => 'emails', 'to' => 0], 'admin', [], ['Accept' => 'application/json']), 200);
    ok(!in_array('emails', tabs(status(get('?client=hmf', 'client'), 200)['body']), true), 'gone again');
    // form post (no JS) → back on Manage → Clients
    $f = post('client-admin.php?client=hmf', ['action' => 'module_toggle', 'id' => 3, 'module' => 'pages', 'to' => 1]);
    is($f['code'], 303); has($f['location'], 'manage.php?client=hmf&section=clients&edit=3');
    is((int)q1("SELECT COUNT(*) FROM company_modules cm JOIN modules m ON m.id = cm.module_id WHERE cm.company_id = 3 AND m.slug = 'pages'"), 1);
});
test('the old duplicate toggles are gone: add-email / add-page module_toggle changes nothing', function () {
    $before = (int)q1("SELECT COUNT(*) FROM company_modules WHERE company_id = 2");
    post('add-email.php?client=privacybee', ['action' => 'module_toggle', 'to' => 0]);
    post('add-page.php?client=privacybee', ['action' => 'module_toggle', 'to' => 0]);
    is((int)q1("SELECT COUNT(*) FROM company_modules WHERE company_id = 2"), $before);
});
test('client settings: default hashtags + AI Builder profile (Classic admin parity)', function () {
    $r = status(post('client-admin.php?client=kenda', ['action' => 'settings', 'id' => 1, 'default_hashtags' => '#Kenda #Tires', 'product_type' => 'tires', 'industry' => 'powersports'],
                     'admin', [], ['Accept' => 'application/json']), 200);
    is($r['json']['ok'] ?? null, true);
    is(q1("SELECT default_hashtags FROM companies WHERE id = 1"), '#Kenda #Tires');
    is(q1("SELECT product_type FROM companies WHERE id = 1"), 'tires');
    is(q1("SELECT industry FROM companies WHERE id = 1"), 'powersports');
    $c = status(get('manage.php?client=kenda'), 200)['body'];
    has($c, '#Kenda #Tires</textarea>'); has($c, 'value="powersports"');
    status(post('client-admin.php?client=kenda', ['action' => 'settings', 'id' => 1, 'industry' => str_repeat('x', 200)], 'admin', [], ['Accept' => 'application/json']), 422);
    status(post('client-admin.php?client=kenda', ['action' => 'settings', 'id' => 999, 'industry' => 'x'], 'admin', [], ['Accept' => 'application/json']), 404);
    status(post('client-admin.php?client=kenda', ['action' => 'settings', 'id' => 1, 'industry' => 'x'], 'admin', [], ['Accept' => 'application/json', 'Sec-Fetch-Site' => 'cross-site']), 403);
});
test('audience actions land back on Manage → Tools; email saves on Emails (toasted once)', function () {
    $r = post('add-email.php?client=privacybee', ['action' => 'group_add', 'name' => 'Partners']);
    has($r['location'], 'manage.php?client=privacybee&section=tools'); has($r['location'], '#audiences');
    $r = post('add-email.php?client=privacybee', ['action' => 'delete', 'id' => 4]);
    has($r['location'], 'emails.php?client=privacybee&status=all&msg=');
    has(status(get(preg_replace('#^/portal/#', '', $r['location'])), 200)['body'], 'data-flash="R1');
    has(status(get('add-email.php?client=privacybee&edit=1'), 200)['body'], 'class="ui-back" href="/portal/emails.php?client=privacybee&amp;email=1');
});

// ---- tire series management on the tire ---------------------------------------------------------------
test('Manage series: on the tire (admin only), deep link opens it', function () {
    $b = status(get('assets.php?client=kenda&view=collections&item=1&series=1'), 200)['body'];
    has($b, 'data-series-manage'); has($b, 'id="seriesManageSheet"'); has($b, 'window.SeriesManageConfig'); has($b, 'series-manage.js');
    has($b, '"open":false');
    has(status(get('assets.php?client=kenda&view=collections&item=1&manage=series'), 200)['body'], '"open":true');
    has(status(get('assets.php?client=kenda&view=collections&item=3'), 200)['body'], 'data-series-manage', 'a tire without series too');
    has($b, 'manage.php?client=kenda&amp;section=export&amp;tire=1&amp;series=1', 'Export approved… → Manage → Export');
    $c = status(get('assets.php?client=kenda&view=collections&item=1&series=1', 'client'), 200)['body'];
    hasNot($c, 'data-series-manage'); hasNot($c, 'seriesManageSheet'); hasNot($c, 'SeriesManageConfig');
});
test('new series with a Google Drive link: series_create + the Upload sheet path', function () {
    $r = status(post('tire-status.php', ['action' => 'series_create', 'tire_id' => 3, 'name' => 'Launch', 'drive_url' => 'https://drive.google.com/drive/folders/abc', 'client' => 'kenda']), 200);
    is($r['json']['series']['drive_url'] ?? null, 'https://drive.google.com/drive/folders/abc');
    $bad = post('tire-upload.php?client=kenda', ['client' => 'kenda', 'tire_id' => 3, 'new_series' => 'Bad link', 'new_series_drive' => 'https://example.com/x'], 'admin', ['file' => tmpImage('drive')]);
    is($bad['code'], 400, 'not a Drive link → 400');
    is((int)q1("SELECT COUNT(*) FROM tire_series WHERE tire_id = 3 AND name = 'Bad link'"), 0, 'nothing created');
    $ok = status(post('tire-upload.php?client=kenda', ['client' => 'kenda', 'tire_id' => 3, 'new_series' => 'Linked', 'new_series_drive' => 'https://drive.google.com/drive/folders/xyz'], 'admin', ['file' => tmpImage('drive2')]), 200);
    is(q1("SELECT drive_url FROM tire_series WHERE tire_id = 3 AND name = 'Linked'"), 'https://drive.google.com/drive/folders/xyz');
});

// ---- Classic admin parity + Posts header + Home -----------------------------------------------------
test('post sheet (admin): Edit post…, Mark Scheduled, Copy caption, Download media; none for the client', function () {
    $a = status(get('posts.php?client=kenda&post=3&partial=1'), 200)['body'];
    foreach (['data-newpost-edit="3"', 'data-toggle-posted="1"', 'data-copy-caption', 'data-download-media', 'data-delete-post'] as $k) has($a, $k);
    hasNot(status(get('posts.php?client=kenda&post=3&partial=1', 'client'), 200)['body'], 'data-download-media');
});
test('Posts header: the global + New only (no second New post), short month pill', function () {
    $b = status(get('posts.php?client=kenda&month=all'), 200)['body'];
    preg_match('#<header class="ui-nav.*?</header>#s', $b, $m);
    has($m[0], 'data-new-menu');
    hasNot($m[0], 'data-newpost', 'no "New post" button');
    hasNot($m[0], 'posts-new');
    has($m[0], '<span class="posts-month-long">All months</span><span class="posts-month-short" aria-hidden="true">All</span>');
});
test('Home: the admin card links to Manage, Projects and the sheets; no Studio / Classic admin link anywhere', function () {
    $h = status(get('?client=kenda'), 200)['body'];
    has($h, '<a href="/portal/manage.php?client=kenda&amp;section=clients" class="ui-row ui-row--leading-sm" data-home-link="clients">', 'Client settings');
    has($h, 'manage.php?client=kenda&amp;section=export'); has($h, 'projects.php?client=kenda'); has($h, 'manage.php?client=kenda&amp;section=tools');
    has(status(get(''), 200)['body'], 'data-home-link="clients"', 'unscoped Home: Manage → Clients');
    foreach (['', '?client=kenda', 'posts.php?client=kenda', 'assets.php?client=kenda&view=collections&item=1', 'emails.php?client=privacybee',
              'pages.php?client=privacybee', 'flows.php?client=privacybee', 'manage.php?client=kenda', 'manage.php?client=privacybee&section=tools',
              'manage.php?section=export', 'drive.php', 'prompts.php', 'vehicles.php', 'build.php?client=kenda', 'add-feature.php?client=kenda&module=tires',
              'add-email.php?client=privacybee', 'add-page.php?client=privacybee', 'projects.php?client=kenda'] as $p) {
        $b = status(get($p), 200)['body'];
        hasNot($b, 'studio.php', "$p: no studio.php link");
        hasNot($b, 'legacy/admin', "$p: no Classic admin link");
        hasNot($b, 'Classic admin', $p);
    }
});

finish();
