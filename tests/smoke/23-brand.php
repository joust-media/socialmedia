<?php
/**
 * The Joust mark as the admin seat's brand (static/brand/joust.png via joustAvatar(), helpers.php):
 *   - the sidebar brand (partials/tabbar.php): the Joust <img> + "Joust Media", with the scoped client (its avatar +
 *     name) or "All clients" as the second line — on unscoped pages (Home, Manage, Inbox, My notifications) and
 *     inside a client's scope;
 *   - the nav bar (partials/navbar.php): the Joust mark leading the eyebrow (shown below 1024px), the Joust avatar in
 *     the trailing slot on unscoped pages, the client's avatar when scoped;
 *   - comment threads: Joust's messages carry the Joust mark for both seats.
 * Client pages keep the client's own logo / initials and never get the Joust brand block.
 * Seed: kenda (1, no logo file → "K" initials), privacybee (2, static/brand/privacybee.png), hmf (3, static/brand/hmf.png).
 */
require __DIR__ . '/lib.php';

const JB = ['Accept' => 'application/json'];
const JOUST_IMG = '#<img class="ui-avatar ui-avatar--joust ui-avatar--brand" src="[^"]*/static/brand/joust\.png\?v=\d+" alt="" width="36" height="36"[^>]*data-joust-logo>#';

/** The sidebar / tab bar (<nav class="ui-tabbar …">…</nav>). */
function tabbar(string $html): string { return preg_match('#<nav class="ui-tabbar.*?</nav>#s', $html, $m) ? $m[0] : ''; }
/** The sidebar brand link. */
function brandLink(string $html): string { return preg_match('#<a class="ui-tabbar-brand.*?</a>#s', tabbar($html), $m) ? $m[0] : ''; }
/** The nav bar (<header class="ui-nav…">…</header>). */
function navbar(string $html): string { return preg_match('#<header class="ui-nav.*?</header>#s', $html, $m) ? $m[0] : ''; }
/** The nav bar's trailing slot. */
function trailing(string $html): string { return preg_match('#<div class="ui-nav-trailing">.*?(?=<nav class="ui-nav-links"|</header>)#s', navbar($html), $m) ? $m[0] : ''; }

test('the Joust mark ships and is served as a PNG', function () {
    $r = status(get('static/brand/joust.png', 'anon'), 200);
    ok(strlen($r['body']) > 1000, 'a real image');
    is(substr($r['body'], 1, 3), 'PNG', 'PNG bytes');
});

$unscoped = ['index.php' => 'Home', 'manage.php' => 'Manage', 'inbox.php' => 'Inbox', 'my-notifications.php' => 'My notifications'];
foreach ($unscoped as $path => $label) {
    test("admin {$label} (unscoped): Joust brand in the sidebar, the nav eyebrow and the trailing slot", function () use ($path) {
        $b = status(get($path, 'admin'), 200)['body'];
        $brand = brandLink($b);
        ok($brand !== '', 'sidebar brand present');
        has($brand, 'data-brand="joust"');
        ok((bool)preg_match(JOUST_IMG, $brand), 'Joust <img> in the brand slot');
        has($brand, '<span class="ui-tabbar-brand-name">Joust Media</span>');
        has($brand, '<span class="ui-tabbar-brand-sub">All clients</span>');
        hasNot($brand, 'data-brand-client', 'no client context when unscoped');
        $nav = navbar($b);
        has($nav, 'data-nav-brand', 'eyebrow brand row');
        ok((bool)preg_match('#<img class="ui-avatar ui-avatar--joust ui-avatar--xs ui-nav-brandmark" src="[^"]*/static/brand/joust\.png#', $nav), 'Joust mark in the eyebrow');
        ok((bool)preg_match('#<img class="ui-avatar ui-avatar--joust" src="[^"]*/static/brand/joust\.png\?v=\d+" alt="Joust Media"#', trailing($b)), 'Joust avatar trailing');
    });
}

$scoped = ['index.php?client=kenda' => 'kenda', 'posts.php?client=kenda' => 'kenda', 'assets.php?client=kenda' => 'kenda',
           'emails.php?client=privacybee' => 'privacybee', 'manage.php?client=kenda' => 'kenda', 'inbox.php?client=kenda' => 'kenda'];
foreach ($scoped as $path => $slug) {
    test("admin inside a client (/{$path}): Joust brand + the client as context", function () use ($path, $slug) {
        $b = status(get($path, 'admin'), 200)['body'];
        $brand = brandLink($b);
        has($brand, 'data-brand="joust"');
        ok((bool)preg_match(JOUST_IMG, $brand), 'Joust <img> in the brand slot');
        has($brand, 'data-brand-client="' . $slug . '"', 'client context line');
        $name = (string)q1('SELECT name FROM companies WHERE slug = ?', [$slug]);
        has($brand, '<span>' . htmlspecialchars($name) . '</span>', 'client name');
        hasNot(trailing($b), 'ui-avatar--joust', 'the trailing slot keeps the client avatar');
        $nav = navbar($b);
        has($nav, 'ui-nav-brandmark', 'Joust mark leads the eyebrow (phones)');
    });
}

test('admin eyebrow: a page without one gets the client name (auto row); a page with one keeps its text', function () {
    $n = navbar(status(get('posts.php?client=kenda', 'admin'), 200)['body']);
    ok((bool)preg_match('#<div class="ui-nav-eyebrow-row ui-nav-eyebrow-row--auto" data-nav-brand>.*?<p class="ui-nav-eyebrow">Kenda Tires</p></div>#s', $n), 'auto eyebrow = client name');
    $n = navbar(status(get('index.php', 'admin'), 200)['body']);
    ok((bool)preg_match('#<div class="ui-nav-eyebrow-row" data-nav-brand>.*?<p class="ui-nav-eyebrow">All clients</p></div>#s', $n), 'Home keeps "All clients"');
});

$clientPages = ['index.php?client=kenda' => 'kenda', 'posts.php?client=kenda' => 'kenda', 'assets.php?client=kenda' => 'kenda',
                'index.php?client=privacybee' => 'privacybee', 'emails.php?client=privacybee' => 'privacybee'];
foreach ($clientPages as $path => $slug) {
    test("client seat (/{$path}): the client's own logo, never the Joust brand block", function () use ($path, $slug) {
        $role = $slug === 'kenda' ? 'client' : 'client:' . $slug;
        $b = status(get($path, $role), 200)['body'];
        $brand = brandLink($b);
        ok($brand !== '', 'sidebar brand present');
        hasNot($brand, 'data-brand="joust"');
        hasNot($brand, 'ui-avatar--joust', 'no Joust mark in the client brand');
        hasNot(navbar($b), 'data-nav-brand', 'no Joust eyebrow mark');
        hasNot(trailing($b), 'ui-avatar--joust', 'no Joust avatar trailing');
        $name = (string)q1('SELECT name FROM companies WHERE slug = ?', [$slug]);
        has($brand, '<span>' . htmlspecialchars($name) . '</span>', 'client name');
        if ($slug === 'privacybee') {
            ok((bool)preg_match('#<img class="ui-avatar ui-avatar--sm" src="[^"]*/static/brand/privacybee\.png#', $brand), 'Privacy Bee logo <img>');
            ok((bool)preg_match('#<img class="ui-avatar" src="[^"]*/static/brand/privacybee\.png#', trailing($b)), 'Privacy Bee logo trailing');
        } else {
            ok((bool)preg_match('#<span class="ui-avatar ui-avatar--sm ui-avatar--initial" aria-label="Kenda Tires">K</span>#', $brand), 'Kenda initials (no logo)');
        }
    });
}

test('a client with only the bundled logo (static/brand/<slug>.png) keeps it in the brand slot (client seat)', function () {
    $b = status(get('index.php?client=hmf', 'client:hmf'), 200)['body'];
    ok((bool)preg_match('#<img class="ui-avatar ui-avatar--sm" src="[^"]*/static/brand/hmf\.png#', brandLink($b)), 'HMF logo <img>');
    hasNot(brandLink($b), 'ui-avatar--joust');
});

test('comment threads: Joust messages carry the Joust mark for the client and the admin; client messages the client avatar', function () {
    status(post('status.php', ['id' => 1, 'comment' => 'Can we try a warmer grade?', 'client' => 'kenda'], 'client', [], JB), 200, 'client comment');
    status(post('status.php', ['id' => 1, 'comment' => 'On it — new grade tomorrow', 'client' => 'kenda'], 'admin', [], JB), 200, 'admin comment');
    foreach (['client', 'admin'] as $seat) {
        $b = status(get('posts.php?client=kenda&post=1&partial=1', $seat), 200)['body'];
        ok((bool)preg_match('#<div class="pd-msg pd-msg--\w+" data-actor="admin"[^>]*>(?:(?!data-actor=).)*?<div class="ui-bubble-meta"><img class="ui-avatar ui-avatar--joust ui-avatar--xs pd-msg-avatar" src="[^"]*/static/brand/joust\.png#s', $b), "{$seat}: Joust mark on the Joust bubble");
        ok((bool)preg_match('#<div class="pd-msg pd-msg--\w+" data-actor="client"[^>]*>(?:(?!data-actor=).)*?<div class="ui-bubble-meta"><span class="ui-avatar ui-avatar--xs pd-msg-avatar ui-avatar--initial" aria-label="Kenda Tires">K</span>#s', $b), "{$seat}: Kenda initials on the client bubble");
    }
    // a post the client sent back (Needs changes, hidden from the client): "Joust replied" carries the mark too
    status(post('status.php', ['id' => 4, 'comment' => 'Darker render coming up', 'client' => 'kenda'], 'admin', [], JB), 200, 'admin reply on post 4');
    $b = status(get('posts.php?client=kenda&post=4&partial=1', 'client'), 200)['body'];
    ok((bool)preg_match('#<figcaption class="pd-hidden-note-head"><img class="ui-avatar ui-avatar--joust ui-avatar--xs pd-msg-avatar" src="[^"]*/static/brand/joust\.png[^>]*>Joust replied#', $b), '"Joust replied" + the mark');
    // freshly sent bubbles (posts.js → App.actorAvatar) use the same markup: window.AppAvatars.admin
    $b = status(get('posts.php?client=kenda', 'client'), 200)['body'];
    ok((bool)preg_match('#<script>window\.AppAvatars = (\{.*?\});</script>#', $b, $m), 'AppAvatars script');
    $av = json_decode($m[1], true);
    ok(str_contains($av['admin'], 'ui-avatar--joust') && str_contains($av['admin'], '/static/brand/joust.png'), 'AppAvatars.admin = the Joust mark');
    hasNot($av['client'], 'joust', 'AppAvatars.client = the client');
});

finish();
