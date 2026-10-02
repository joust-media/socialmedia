<?php
/** Every app page answers 200 for the seat that may see it, and the admin-only pages bounce the client seat. */
require __DIR__ . '/lib.php';

$adminPages = [
    '', '?client=kenda', '?client=privacybee', '?client=hmf',
    'manage.php', 'manage.php?section=clients', 'manage.php?section=export', 'manage.php?section=tools', 'manage.php?section=clients&edit=2',
    'manage.php?client=kenda', 'manage.php?client=kenda&section=export', 'manage.php?client=kenda&section=export&tire=1&series=2',
    'manage.php?client=kenda&section=tools', 'manage.php?client=kenda&section=clients&edit=0', 'manage.php?client=privacybee&section=tools',
    'manage.php?client=hmf&section=tools', 'manage.php?client=hmf&section=export',
    'assets.php?client=kenda', 'assets.php?client=kenda&view=collections', 'assets.php?client=kenda&view=collections&item=1',
    'assets.php?client=kenda&view=collections&item=1&series=1', 'assets.php?client=kenda&view=collections&item=3&manage=series',
    'posts.php', 'posts.php?client=kenda', 'posts.php?client=kenda&status=draft', 'posts.php?client=kenda&status=approved',
    'posts.php?client=kenda&status=scheduled', 'posts.php?client=kenda&status=denied', 'posts.php?client=kenda&post=2',
    'emails.php?client=privacybee', 'add-email.php?client=privacybee', 'add-email.php?client=privacybee&edit=2',
    'pages.php?client=privacybee', 'add-page.php?client=privacybee', 'flows.php?client=privacybee',
    'projects.php?client=kenda', 'drive.php',
    'add-feature.php?client=kenda&module=tires', 'add-feature.php?client=kenda&module=tires&edit_item=1',
    'prompts.php', 'vehicles.php', 'build.php?client=kenda',
];
foreach ($adminPages as $p) {
    test("admin 200 /{$p}", function () use ($p) {
        $r = status(get($p, 'admin'), 200);
        hasNot($r['body'], 'Fatal error', 'no PHP fatal in the page');
        hasNot($r['body'], 'Warning:', 'no PHP warning in the page');
    });
}

$clientPages = [
    '?client=kenda', '?client=privacybee', 'assets.php?client=kenda', 'assets.php?client=kenda&view=collections',
    'assets.php?client=kenda&view=collections&item=1', 'posts.php?client=kenda', 'posts.php?client=kenda&status=approved',
    'posts.php?client=kenda&status=scheduled', 'projects.php?client=kenda', 'emails.php?client=privacybee',
    'pages.php?client=privacybee', 'flows.php?client=privacybee', 'posts.php?client=kenda&post=2',
];
foreach ($clientPages as $p) {
    test("client 200 /{$p}", function () use ($p) {
        $r = status(get($p, 'client'), 200);
        hasNot($r['body'], 'Fatal error');
        hasNot($r['body'], 'Warning:');
    });
}

// Retired composer / upload routes (the New post pop-up and the Upload sheet replaced them): every old link lands somewhere
// that works. (Every retired Studio / Classic admin route is in smoke/10-nav.php.)
$retired = [
    'studio.php?client=kenda&tab=compose'  => 'posts.php?client=kenda&newpost=1',
    'studio.php?client=kenda&tab=batch'    => 'posts.php?client=kenda&upload=1&dest=post&each=1',
    'batch.php?client=kenda'               => 'posts.php?client=kenda&upload=1&dest=post&each=1',
    'add-post.php?client=kenda'            => 'posts.php?client=kenda&newpost=1',
    'add-post.php?client=kenda&edit=2'     => 'posts.php?client=kenda&post=2&newpost=edit',
    'admin.php?client=kenda&tab=compose'   => 'posts.php?client=kenda&newpost=1',
];
foreach ($retired as $from => $to) {
    test("admin retired /{$from} → {$to}", function () use ($from, $to) {
        $r = get($from, 'admin');
        ok(in_array($r['code'], [301, 302], true), "redirect (got {$r['code']})");
        has($r['location'], $to);
        status(get(preg_replace('#^(https?://[^/]+)?/portal/#', '', $r['location']), 'admin'), 200, 'the target answers');
    });
}

// Admin-only pages: the client seat is sent to the sign-in page.
$adminOnly = ['manage.php', 'manage.php?client=kenda', 'manage.php?client=kenda&section=export', 'manage.php?client=kenda&section=tools',
              'studio.php?client=kenda', 'admin.php?client=kenda', 'legacy/admin.php?client=kenda', 'add-post.php?client=kenda', 'batch.php?client=kenda', 'add-email.php?client=privacybee',
              'add-page.php?client=privacybee', 'drive.php', 'add-feature.php?client=kenda&module=tires', 'prompts.php', 'vehicles.php',
              'build.php?client=kenda', 'migrate.php'];
foreach ($adminOnly as $p) {
    test("client → login /{$p}", function () use ($p) {
        $r = get($p, 'client');
        is($r['code'], 302, 'redirect');
        has($r['location'], 'login', 'to the sign-in page');
    });
}

test('+ New menu: admin, every page, module-aware items', function () {
    $r = status(get('posts.php?client=kenda', 'admin'), 200);
    has($r['body'], 'data-new-menu');
    has($r['body'], 'data-new-action="post"');
    has($r['body'], 'data-new-action="upload"');
    hasNot($r['body'], 'data-new-action="email"', 'Kenda has no Emails');
    hasNot($r['body'], 'data-new-action="page"', 'Kenda has no Pages');
    $r = status(get('emails.php?client=privacybee', 'admin'), 200);
    has($r['body'], 'data-new-action="email"');
    has($r['body'], 'data-new-action="page"');
    ok(preg_match('/data-new-action="email"/', $r['body']) && preg_match('#href="[^"]*add-email\.php\?client=privacybee"[^>]*data-new-action="email"#', $r['body']), 'email item links to add-email');
    ok(preg_match('#href="[^"]*posts\.php\?client=kenda&amp;upload=1"[^>]*data-new-action="upload"#', get('?client=kenda', 'admin')['body']) === 1, 'upload item: no-JS link = Posts with the Upload sheet');
    has(get('', 'admin')['body'], 'data-new-menu', 'unscoped Home too');
});
test('Home "Upload" opens the Upload sheet (no-JS: Posts with the sheet open, not Compose)', function () {
    $r = status(get('?client=kenda', 'admin'), 200);
    ok(preg_match('#href="[^"]*posts\.php\?client=kenda&amp;upload=1" data-upload-open[^>]*>.{0,1500}?<span>Upload</span>#s', $r['body']) === 1);
    hasNot($r['body'], 'admin.php');
    hasNot($r['body'], 'studio.php');
});
test('back links: tire form → Assets, libraries / builder → Manage → Tools', function () {
    $r = status(get('add-feature.php?client=kenda&module=tires&edit_item=1', 'admin'), 200);
    ok(preg_match('#class="ui-back" href="[^"]*assets\.php\?client=kenda&amp;view=collections&amp;item=1"#', $r['body']) === 1, 'tire edit back → its collection');
    foreach (['prompts.php', 'vehicles.php', 'build.php?client=kenda'] as $p) {
        $b = status(get($p, 'admin'), 200)['body'];
        ok(preg_match('#class="ui-back" href="[^"]*/manage\.php\?(client=kenda&amp;)?section=tools"#', $b) === 1, "$p back → manage.php?section=tools");
        hasNot($b, 'href="admin.php', "$p has no admin.php link");
    }
});
test('client seat never sees Studio / Manage chrome', function () {
    $r = status(get('?client=kenda', 'client'), 200);
    hasNot($r['body'], 'studio.php', 'no Studio link');
    hasNot($r['body'], 'manage.php', 'no Manage link');
    hasNot($r['body'], 'data-new-menu', 'no + New menu');
});

finish();
