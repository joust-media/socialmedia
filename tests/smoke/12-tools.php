<?php
/**
 * UX re-score fixes (wt/fixb): the admin tool pages in the shared shell (no Sign out rows, no pinned theme, "New tire"),
 * their forms still posting, one name for tire content (Tires / series / images — never Collections / Renders),
 * Assets = the Library and Tires = the tire content (no Library · Tires switch, old links redirect), "New tire" in
 * "+ New" and on the Tires list, the admin Home copy, and one page frame (width + title colour) for the top-level pages.
 * Seed: kenda (1, tires module) · privacybee (2, emails + pages) · hmf (3, nothing). Tires 1–3 belong to kenda.
 */
require __DIR__ . '/lib.php';

/** The nav bar (<header class="ui-nav…">) and the page body (<main>…</main>) — where page chrome used to carry "Sign out". */
function chromeAndMain(string $html): string {
    $out = '';
    if (preg_match('#<header class="ui-nav.*?</header>#s', $html, $m)) $out .= $m[0];
    if (preg_match('#<main\b.*?</main>#s', $html, $m)) $out .= $m[0];
    return $out;
}
/** What a person can read on the page: text nodes + title / aria-label / placeholder (no scripts, styles, comments, URLs). */
function visibleText(string $html): string {
    $html = preg_replace('#<(script|style|template)\b.*?</\1>#si', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);
    preg_match_all('#\b(?:title|aria-label|placeholder|data-confirm)="([^"]*)"#', $html, $attrs);
    return html_entity_decode(strip_tags($html) . ' ' . implode(' ', $attrs[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function follow1(string $path, string $role = 'admin'): array {
    $r = get($path, $role);
    ok(in_array($r['code'], [301, 302, 303], true), "$path redirects (got {$r['code']})");
    $next = get(preg_replace('#^(https?://[^/]+)?/portal/?#', '', $r['location']), $role);
    return [$r['location'], $next];
}

$toolPages = ['build.php?client=kenda', 'add-feature.php?client=kenda&module=tires', 'add-feature.php?client=kenda&module=tires&edit_item=1',
              'prompts.php', 'prompts.php?cat=camera&q=mm', 'add-prompt.php', 'add-prompt.php?edit=1', 'vehicles.php', 'add-vehicle.php'];

// ---- tool pages: shared shell ---------------------------------------------------------------------------
foreach ($toolPages as $p) {
    test("tool page in the shared shell, no Sign out row: /{$p}", function () use ($p) {
        $b = status(get($p), 200)['body'];
        has($b, '<html lang="en" class="ui-shell">', 'layout-top.php shell');
        hasNot($b, 'data-theme="dark"', 'no forced dark theme');
        hasNot($b, 'data-theme="light"', 'no pinned light theme');
        has($b, 'class="ui-back"', 'a back link');
        has($b, 'class="ui-tabbar', 'the tab bar');
        has($b, 'css/tools.css');
        $c = chromeAndMain($b);
        hasNot($c, 'Sign out', 'no Sign out in the nav bar or the page');
        hasNot($c, 'logout', 'no logout link in the nav bar or the page');
        hasNot($b, 'class="topbar', 'old top bar gone');
        hasNot($b, 'class="wrap"', 'old .wrap container gone');
        is(substr_count($b, '<h1'), 1, 'one H1 (no duplicate page heading)');
    });
}
test('AI Builder: title, client eyebrow, back to Manage → Tools, the three steps', function () {
    $b = status(get('build.php?client=kenda'), 200)['body'];
    has($b, '<h1 class="ui-nav-title">AI Builder</h1>');
    has($b, '<p class="ui-nav-eyebrow">Kenda Tires</p>');
    ok(preg_match('#class="ui-back" href="[^"]*/manage\.php\?client=kenda&amp;section=tools"#', $b) === 1, 'back → Manage → Tools');
    foreach (['refs', 'compose', 'final'] as $k) has($b, 'data-builder-step="' . $k . '"');
    has($b, 'id="copyBtn"'); has($b, 'id="downloadBtn"'); has($b, 'id="sel-camera"'); has($b, 'const PROMPTS');
    hasNot($b, '__PROMPTS__', 'server data filled in');
});
test('"New tire" (sentence case) — never "Add Tire"; edit is "Edit tire" with the tire as its parent', function () {
    $b = status(get('add-feature.php?client=kenda&module=tires'), 200)['body'];
    has($b, '<h1 class="ui-nav-title">New tire</h1>');
    hasNot($b, 'Add Tire'); hasNot($b, 'Add tire');
    ok(preg_match('#class="ui-back" href="[^"]*assets\.php\?client=kenda&amp;view=collections"#', $b) === 1, 'back → the Tires list');
    $e = status(get('add-feature.php?client=kenda&module=tires&edit_item=1'), 200)['body'];
    has($e, '<h1 class="ui-nav-title">Edit tire</h1>');
    has($e, 'data-status-set="approved"'); has($e, 'data-replace-tire-img'); has($e, 'data-tire-delete');
    hasNot($e, 'All tires (', 'no second tire list (the Tires tab is the one list)');
    $u = get('add-feature.php');
    is($u['code'], 302, 'unscoped → the Tires chooser'); has($u['location'], 'assets.php?view=collections');
    is(get('add-feature.php?client=privacybee&module=tires')['code'], 403, 'module off → 403 (in the shell)');
    has(get('add-feature.php?client=privacybee&module=tires')['body'], 'class="ui-tabbar');
});

// ---- the forms still post ---------------------------------------------------------------------------------
test('tire form: create → update (name + category) → delete', function () {
    $r = post('add-feature.php?client=kenda&module=tires', ['action' => 'item_create', 'item_name' => 'Smoke Trail']);
    is($r['code'], 302); has($r['location'], 'add-feature.php?client=kenda&module=tires&msg=');
    $id = (int)q1("SELECT id FROM tires WHERE company_id = 1 AND name = 'Smoke Trail'");
    ok($id > 0, 'created');
    has(status(get('assets.php?client=kenda&view=collections'), 200)['body'], 'Smoke Trail', 'listed on the Tires tab');
    $cat = (int)q1("SELECT id FROM categories ORDER BY id LIMIT 1");
    $r = post('add-feature.php?client=kenda&module=tires', ['action' => 'item_update', 'id' => $id, 'item_name' => 'Smoke Trail II', 'item_categories' => [$cat]]);
    is($r['code'], 302); has($r['location'], 'edit_item=' . $id);
    is(q1("SELECT name FROM tires WHERE id = ?", [$id]), 'Smoke Trail II');
    is((int)q1("SELECT COUNT(*) FROM tire_categories WHERE tire_id = ? AND category_id = ?", [$id, $cat]), 1, 'category saved');
    has(status(get('add-feature.php?client=kenda&module=tires&edit_item=' . $id), 200)['body'], 'value="' . $cat . '" checked', 'category chip on');
    $r = post('add-feature.php?client=kenda&module=tires', ['action' => 'item_delete', 'id' => $id]);
    is($r['code'], 302);
    is((int)q1("SELECT COUNT(*) FROM tires WHERE id = ?", [$id]), 0, 'deleted');
    is(post('add-feature.php?client=kenda&module=tires', ['action' => 'item_create', 'item_name' => ''])['code'], 200, 'empty name → the form again with the error');
});
test('tire form: captions / display names / remove a reference image', function () {
    $img = (int)q1("SELECT id FROM tire_images WHERE tire_id = 1 AND series_id IS NULL ORDER BY sort_order LIMIT 1");
    $r = post('add-feature.php?client=kenda&module=tires', ['action' => 'item_update', 'id' => 1, 'item_name' => 'Klever AT2',
        'captions' => [$img => 'Front view'], 'display_names' => [$img => 'klever-front']]);
    is($r['code'], 302);
    is(q1("SELECT caption FROM tire_images WHERE id = ?", [$img]), 'Front view');
    is(q1("SELECT display_name FROM tire_images WHERE id = ?", [$img]), 'klever-front');
});
test('prompt form: create → update → delete; search filters the list', function () {
    $r = post('add-prompt.php', ['action' => 'create', 'category' => 'camera', 'name' => 'Smoke dolly shot', 'prompt_text' => 'Slow dolly in on {{product_name}}', 'tags' => 'smoke, test', 'compatible_models' => []]);
    is($r['code'], 302); has($r['location'], 'prompts.php?msg=');
    $id = (int)q1("SELECT id FROM prompts WHERE name = 'Smoke dolly shot'");
    ok($id > 0, 'created');
    $l = status(get('prompts.php?q=dolly'), 200)['body'];
    has($l, 'Smoke dolly shot'); has($l, 'data-prompt="' . $id . '"');
    hasNot(status(get('prompts.php?q=zzzz-nothing'), 200)['body'], 'data-prompt="', 'search narrows the list');
    has(status(get('prompts.php?tag=smoke'), 200)['body'], 'Smoke dolly shot', 'tag filter');
    $e = status(get('add-prompt.php?edit=' . $id), 200)['body'];
    has($e, 'value="Smoke dolly shot"'); has($e, '<h1 class="ui-nav-title">Edit prompt</h1>');
    $r = post('add-prompt.php?edit=' . $id, ['action' => 'update', 'id' => $id, 'category' => 'lighting', 'name' => 'Smoke dolly shot', 'prompt_text' => 'Soft light', 'tags' => '']);
    is($r['code'], 302);
    is(q1("SELECT category FROM prompts WHERE id = ?", [$id]), 'lighting');
    $bad = post('add-prompt.php', ['action' => 'create', 'category' => 'camera', 'name' => 'Bad', 'prompt_text' => 'Uses {{nope}}']);
    is($bad['code'], 200, 'unknown variable → the form again');
    has($bad['body'], 'Unknown variable');
    has($bad['body'], 'studio-alert--error');
    is(post('add-prompt.php', ['action' => 'delete', 'id' => $id])['code'], 302);
    is((int)q1("SELECT COUNT(*) FROM prompts WHERE id = ?", [$id]), 0, 'deleted');
});
test('vehicle form: create with an image → listed → delete', function () {
    $r = post('add-vehicle.php', ['action' => 'create', 'manufacturer' => 'Smokeha', 'model' => 'YXZ', 'model_year' => '2024', 'vehicle_type' => 'UTV'], 'admin', ['images' => [tmpImage('veh')]]);
    is($r['code'], 302); has($r['location'], 'vehicles.php?msg=');
    $id = (int)q1("SELECT id FROM vehicles WHERE manufacturer = 'Smokeha'");
    ok($id > 0, 'created');
    is((int)q1("SELECT COUNT(*) FROM vehicle_images WHERE vehicle_id = ?", [$id]), 1, 'image stored');
    $l = status(get('vehicles.php?q=Smokeha'), 200)['body'];
    has($l, '2024 Smokeha YXZ'); has($l, 'data-vehicle="' . $id . '"');
    has(status(get('vehicles.php?make=Smokeha'), 200)['body'], 'Make: Smokeha', 'make filter chip');
    has(status(get('add-vehicle.php?edit=' . $id), 200)['body'], 'data-remove-checkbox');
    has(status(get('build.php?client=kenda'), 200)['body'], '2024 Smokeha YXZ', 'the Builder offers it');
    is(post('add-vehicle.php', ['action' => 'delete', 'id' => $id])['code'], 302);
    is((int)q1("SELECT COUNT(*) FROM vehicles WHERE id = ?", [$id]), 0, 'deleted');
});

// ---- one name for tire content ----------------------------------------------------------------------------
$termPages = ['', '?client=kenda', '?client=privacybee', 'assets.php', 'assets.php?view=collections', 'assets.php?client=kenda',
              'assets.php?client=kenda&view=collections', 'assets.php?client=kenda&view=collections&item=1',
              'assets.php?client=kenda&view=collections&item=1&series=1', 'assets.php?client=kenda&view=collections&item=3',
              'posts.php?client=kenda', 'manage.php?client=kenda', 'manage.php?client=kenda&section=tools', 'manage.php?client=kenda&section=export',
              'manage.php?client=hmf', 'build.php?client=kenda', 'add-feature.php?client=kenda&module=tires&edit_item=1'];
foreach ($termPages as $p) {
    test("no Collections / Renders in what the admin reads: /{$p}", function () use ($p) {
        $t = visibleText(status(get($p), 200)['body']);
        ok(!preg_match('/\b(collections?|renders?)\b/i', $t, $m), "found “" . ($m[0] ?? '') . "”");
    });
}
test('the client reads Tires too (Home card, tab, tire page)', function () {
    foreach (['?client=kenda', 'assets.php?client=kenda&view=collections', 'assets.php?client=kenda&view=collections&item=1&series=1'] as $p) {
        $t = visibleText(status(get($p, 'client'), 200)['body']);
        ok(!preg_match('/\b(collections?|renders?)\b/i', $t, $m), "$p: found “" . ($m[0] ?? '') . "”");
    }
    has(get('?client=kenda', 'client')['body'], '<span class="ui-tab-label">Tires</span>');
});
test('module row in Manage → Clients is named with the client\'s word', function () {
    $c = status(get('manage.php?client=kenda'), 200)['body'];
    has($c, '<div class="ui-row-title">Tires tab</div>');
    has($c, 'Shows the Tires tab and “New tire”');
    $h = status(get('manage.php?client=hmf'), 200)['body'];
    has($h, 'Shows the Tires tab', 'no feature label → "Tires"');
});

// ---- Assets = Library, Tires = tire content --------------------------------------------------------------
test('no Library · Tires switch: Assets is the Library, the Tires tab is the tire list (own large title)', function () {
    $a = status(get('assets.php?client=kenda'), 200)['body'];
    hasNot($a, 'aria-label="Assets view"', 'no segment');
    has($a, '<h1 class="ui-nav-title">Assets</h1>');
    ok(preg_match('#class="ui-tab ui-tab--assets is-active"#', $a) === 1, 'Assets tab lit');
    has($a, 'data-library-upload');
    $t = status(get('assets.php?client=kenda&view=collections'), 200)['body'];
    hasNot($t, 'aria-label="Assets view"');
    has($t, '<h1 class="ui-nav-title">Tires</h1>');
    ok(preg_match('#class="ui-tab ui-tab--tires is-active"#', $t) === 1, 'Tires tab lit');
    has($t, 'data-collection="1"');
    hasNot(status(get('assets.php?client=kenda&view=collections&item=1&series=1'), 200)['body'], 'aria-label="Assets view"', 'tire page: no segment');
    hasNot(status(get('assets.php?client=kenda', 'client'), 200)['body'], 'aria-label="Assets view"', 'client: no segment');
});
test('the selection bar (Create post / Download / Export) is on the tire grid and on the Library', function () {
    foreach (['assets.php?client=kenda&view=collections&item=1&series=1&filter=approved', 'assets.php?client=kenda&filter=approved'] as $p) {
        $b = status(get($p), 200)['body'];
        foreach (['data-assets-selectbar', 'data-select-post', 'data-select-download', 'data-select-export', 'data-assets-select'] as $k) has($b, $k, "$p: $k");
    }
});
$redirects = [
    'assets.php?client=privacybee&view=collections' => 'assets.php?client=privacybee',   // no Tires tab → the Library
    'assets.php?client=hmf&view=collections&filter=approved' => 'assets.php?client=hmf&filter=approved',
    'tires.php?client=kenda'                   => 'assets.php?client=kenda&view=collections',
    'tires.php?client=kenda&tire=2'            => 'assets.php?client=kenda&view=collections&item=2',
    'tires.php'                                => 'assets.php?view=collections',
    'features.php?client=kenda&module=tires&item=1' => 'assets.php?client=kenda&view=collections&item=1',
    'library.php?client=kenda'                 => 'assets.php?client=kenda&view=library',
    'add-tire.php?client=kenda'                => 'add-feature.php?client=kenda&module=tires',
];
foreach ($redirects as $from => $to) {
    test("Assets / Tires redirect /{$from} → {$to}", function () use ($from, $to) {
        [$loc, $next] = follow1($from);
        ok(substr($loc, -strlen($to)) === $to, "landed on $loc");
        status($next, 200, 'the target answers');
    });
}
test('unscoped Assets: a client chooser in place (no bounce to Home); unscoped Tires: the clients with Tires', function () {
    $a = status(get('assets.php'), 200)['body'];
    has($a, 'data-assets-clients="library"');
    foreach (['kenda', 'privacybee', 'hmf'] as $s) has($a, 'href="/portal/assets.php?client=' . $s . '"', $s);
    ok(preg_match('#class="ui-tab ui-tab--assets is-active"#', $a) === 1, 'Assets tab lit');
    has($a, '2 to review', 'Kenda\'s Library count');
    $t = status(get('assets.php?view=collections'), 200)['body'];
    has($t, 'data-assets-clients="tires"');
    has($t, 'assets.php?client=kenda&amp;view=collections');
    hasNot($t, 'client=privacybee', 'privacybee has no Tires');
    is(get('assets.php', 'client')['code'], 400, 'client seat without a client: the missing-client page');
});

// ---- New tire ----------------------------------------------------------------------------------------------
test('"New tire" in + New for a client with the Tires module (only), and on the Tires list', function () {
    $k = status(get('posts.php?client=kenda'), 200)['body'];
    ok(preg_match('#href="[^"]*add-feature\.php\?client=kenda&amp;module=tires" data-new-action="tire">.*?New tire#s', $k) === 1, '+ New → New tire');
    foreach (['emails.php?client=privacybee', '?client=hmf', ''] as $p) hasNot(status(get($p), 200)['body'], 'data-new-action="tire"', "none on /$p");
    hasNot(status(get('posts.php?client=kenda', 'client'), 200)['body'], 'New tire', 'never for the client seat');
    $t = status(get('assets.php?client=kenda&view=collections'), 200)['body'];
    ok(preg_match('#href="[^"]*add-feature\.php\?client=kenda&amp;module=tires" data-new-tire="1"#', $t) === 1, 'Tires list button');
    hasNot(status(get('assets.php?client=kenda&view=collections', 'client'), 200)['body'], 'data-new-tire', 'not for the client');
    hasNot(status(get('assets.php?client=kenda'), 200)['body'], 'data-new-tire', 'not on the Library');
});

// ---- admin Home copy -----------------------------------------------------------------------------------------
test('admin Home speaks from Joust\'s side: Needs your changes first, then Waiting on <client>', function () {
    $b = status(get('?client=kenda'), 200)['body'];
    has($b, 'Needs your changes'); has($b, 'Waiting on Kenda Tires'); has($b, 'waiting for their review');
    hasNot($b, 'ready for your review'); hasNot($b, 'Needs your attention');
    ok(strpos($b, 'id="home-changes"') < strpos($b, 'id="home-attention"'), 'Needs your changes comes first');
    hasNot($b, 'id="home-appearance"', 'no Appearance card (the nav button)');
    hasNot($b, 'class="home-quick"', 'no duplicate New post / Upload tiles (+ New)');
    ok(strpos($b, 'data-home-admin') < strpos($b, 'id="home-activity"'), 'Joust links above Activity');
    $c = status(get('?client=kenda', 'client'), 200)['body'];
    has($c, 'Needs your attention'); has($c, 'ready for your review'); has($c, 'id="home-appearance"');
    hasNot($c, 'Waiting on'); hasNot($c, 'Needs your changes');
});

// ---- one frame -------------------------------------------------------------------------------------------------
$framePages = ['?client=kenda', 'assets.php?client=kenda', 'assets.php?client=kenda&view=collections', 'assets.php?client=kenda&view=collections&item=1',
               'posts.php?client=kenda', 'emails.php?client=privacybee', 'pages.php?client=privacybee', 'manage.php?client=kenda',
               'manage.php?client=kenda&section=export', 'manage.php?client=kenda&section=tools', 'drive.php', 'assets.php', ''];
foreach ($framePages as $p) {
    test("one page frame (720px column, nav aligned): /{$p}", function () use ($p) {
        $b = status(get($p), 200)['body'];
        hasNot($b, 'ui-page--wide', 'no wide main');
        hasNot($b, 'ui-nav--wide', 'no wide nav');
        hasNot($b, '--content-w:', 'no per-page column width');
    });
}
test('large titles are never orange; Manage carries the phone sign-out', function () {
    $css = (string)file_get_contents(dirname(__DIR__, 2) . '/static/css/studio.css');
    ok(!preg_match('/ui-nav-title\s*\{[^}]*--joust/', $css), 'no --joust title rule');
    has(status(get('manage.php?client=kenda'), 200)['body'], 'data-manage-signout');
});

finish();
