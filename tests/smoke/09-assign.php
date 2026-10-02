<?php
/**
 * Assign (assign.php): move emails / pages across clients — rows AND files on disk (copy → verify →
 * commit → delete) — add to a flow, set audiences, "+ New → Email / Page" creation, the tenant and
 * seat gates, the ?group= → ?audience= alias and the Audiences wording.
 * Seed: privacybee (2) has emails 1–5 (W1 W2 W3 R1 R2; Free = 1,2,3 · Renewal = 4,5; flow 1 "Welcome" =
 * W1 → W2 → W3) and pages 1–3 (url); kenda (1) and hmf (3) have none.
 */
require __DIR__ . '/lib.php';

$media = (getenv('MEDIA_DIR') ?: ((getenv('PORTAL_TEST_ROOT') ?: '/tmp/portal-test') . '/site/media'));
function A(array $data, string $role = 'admin', array $files = [], array $headers = []): array { return post('assign.php', $data, $role, $files, $headers); }
function opts(string $qs, string $role = 'admin'): array { return get('assign.php?action=options&' . $qs, $role); }
function mediaUrl(string $rel): string { return preg_replace('#/portal$#', '', base()) . '/media/' . ltrim($rel, '/'); }
function htmlFile(string $body, string $name = 'landing.html'): string {
    $p = sys_get_temp_dir() . '/smoke_' . bin2hex(random_bytes(3)) . '_' . $name;
    file_put_contents($p, $body);
    return $p;
}

// ---- seats + tenant -------------------------------------------------------------------------------
test('client seat: every action answers 403 (JSON)', function () {
    is(opts('client=privacybee&kind=email', 'client')['code'], 403);
    $r = status(A(['action' => 'move', 'kind' => 'email', 'client' => 'privacybee', 'ids' => '1', 'to' => 'kenda'], 'client'), 403);
    is($r['json']['ok'] ?? null, false);
    status(A(['action' => 'add_to_flow', 'client' => 'privacybee', 'ids' => '4', 'flow_id' => 1], 'client'), 403);
    status(A(['action' => 'set_audiences', 'client' => 'privacybee', 'ids' => '1', 'add' => '2'], 'client'), 403);
    status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'x'], 'client'), 403);
    status(A(['action' => 'create_page', 'client' => 'privacybee', 'title' => 'x'], 'client'), 403);
    status(A(['action' => 'move', 'kind' => 'page', 'client' => 'privacybee', 'ids' => '1', 'to' => 'kenda'], 'anon'), 403);
    is((int)q1("SELECT company_id FROM emails WHERE id = 1"), 2, 'nothing moved');
});

test('cross-site POST is refused (403)', function () {
    status(A(['action' => 'move', 'kind' => 'email', 'client' => 'privacybee', 'ids' => '1', 'to' => 'kenda'], 'admin', [], ['Sec-Fetch-Site' => 'cross-site']), 403);
    is((int)q1("SELECT company_id FROM emails WHERE id = 1"), 2);
});

test('cross-tenant ids → 403, unknown → 404, bad input → 400', function () {
    // email 1 belongs to privacybee, not kenda
    status(A(['action' => 'move', 'kind' => 'email', 'client' => 'kenda', 'ids' => '1', 'to' => 'hmf']), 403);
    status(A(['action' => 'move', 'kind' => 'page', 'client' => 'kenda', 'ids' => '2', 'to' => 'hmf']), 403);
    status(A(['action' => 'set_audiences', 'client' => 'kenda', 'ids' => '1', 'add' => '1']), 403);
    status(opts('client=kenda&kind=email&ids=1'), 403);
    // a flow / audience of another client
    db()->exec("INSERT INTO email_flows (id, company_id, name, slug, sort_order) VALUES (9, 1, 'Kenda flow', 'kenda-flow', 1)");
    db()->exec("INSERT INTO email_groups (id, company_id, name, slug, sort_order) VALUES (9, 1, 'Dealers', 'dealers', 1)");
    status(A(['action' => 'add_to_flow', 'client' => 'privacybee', 'ids' => '4', 'flow_id' => 9]), 403);
    status(A(['action' => 'set_audiences', 'client' => 'privacybee', 'ids' => '4', 'add' => '9']), 403);
    status(A(['action' => 'move', 'kind' => 'email', 'client' => 'privacybee', 'ids' => '999', 'to' => 'kenda']), 404);
    status(A(['action' => 'move', 'kind' => 'email', 'client' => 'privacybee', 'ids' => '1', 'to' => 'nope']), 404);
    status(A(['action' => 'move', 'kind' => 'email', 'client' => 'privacybee', 'ids' => '1', 'to' => 'privacybee']), 400);
    status(A(['action' => 'move', 'kind' => 'email', 'client' => '', 'ids' => '1', 'to' => 'kenda']), 400);
    status(A(['action' => 'nope', 'client' => 'privacybee']), 400);
    is((int)q1("SELECT COUNT(*) FROM email_flow_steps WHERE flow_id = 9"), 0);
    is((int)q1("SELECT COUNT(*) FROM email_group_map WHERE group_id = 9"), 0);
});

test('options: clients, flows with steps, audiences with the selection count', function () {
    $j = status(opts('client=privacybee&kind=email&ids=1,4'), 200)['json'];
    is($j['from']['slug'], 'privacybee');
    is(array_column($j['clients'], 'slug'), ['hmf', 'kenda', 'privacybee']);
    is($j['flows'][0]['name'], 'Welcome');
    is(array_column($j['flows'][0]['steps'], 'email_id'), [1, 2, 3]);
    $aud = array_column($j['audiences'], 'selected', 'name');
    is($aud, ['Free' => 1, 'Renewal' => 1]);
});

// ---- + New → Email / Page -------------------------------------------------------------------------
test('create_email (paste): a Draft with portal-hosted HTML the client cannot see', function () use ($media) {
    $r = status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'Hosted welcome', 'source' => 'paste',
                   'html' => '<!doctype html><html><body><h1>Hello</h1></body></html>']), 200);
    $id = (int)$r['json']['id'];
    is($r['json']['code'], 'E1', 'next free E<n> code');
    has($r['json']['url'], 'emails.php?client=privacybee&email=' . $id);
    $row = rows("SELECT * FROM emails WHERE id = ?", [$id])[0];
    is($row['status'], 'draft');
    is($row['html_url'], '/media/emails/privacybee/e1-' . $id . '.html');
    ok(is_file($media . '/emails/privacybee/e1-' . $id . '.html'), 'file stored');
    has((string)file_get_contents($media . '/emails/.htaccess'), "script-src 'none'");
    has((string)file_get_contents($media . '/emails/.htaccess'), '<IfModule mod_headers.c>');
    is(get(mediaUrl('emails/privacybee/e1-' . $id . '.html'), 'anon')['code'], 200);
    // the admin detail frames it; the client never gets the draft
    has(get('emails.php?client=privacybee&email=' . $id . '&partial=1')['body'], 'src="/media/emails/privacybee/e1-' . $id . '.html"');
    status(get('emails.php?client=privacybee&email=' . $id . '&partial=1', 'client'), 404);
});

test('create_email (file + link + validation)', function () {
    $f = htmlFile('<html><body>file</body></html>', 'promo.html');
    $r = status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'From a file', 'code' => 'p1', 'source' => 'file'], 'admin', ['file' => $f]), 200);
    is($r['json']['code'], 'P1');
    has($r['json']['html_url'], '/media/emails/privacybee/p1-');
    $r = status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'Linked', 'source' => 'url', 'html_url' => 'https://example.com/e.html']), 200);
    is(q1("SELECT html_url FROM emails WHERE id = ?", [$r['json']['id']]), 'https://example.com/e.html');
    status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'PHP', 'source' => 'paste', 'html' => '<?php echo 1; ?>']), 422);
    status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => '', 'source' => 'none']), 422);
    status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'Dup', 'code' => 'W1']), 409);
    status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'Bad link', 'source' => 'url', 'html_url' => 'javascript:alert(1)']), 422);
    $txt = htmlFile('plain', 'notes.txt');
    status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'Txt', 'source' => 'file'], 'admin', ['file' => $txt]), 415);
});

test('create_page (paste): row + media/pages/<client>/<slug>/index.html + page_files', function () use ($media) {
    $r = status(A(['action' => 'create_page', 'client' => 'privacybee', 'title' => 'Pricing', 'source' => 'paste',
                   'html' => '<html><body><h1>Pricing</h1></body></html>']), 200);
    $id = (int)$r['json']['id'];
    is($r['json']['slug'], 'pricing-2', 'slug "pricing" is taken by page 2');
    ok(is_file($media . '/pages/privacybee/pricing-2/index.html'));
    is(q1("SELECT filename FROM page_files WHERE page_id = ?", [$id]), 'index.html');
    is(q1("SELECT status FROM pages WHERE id = ?", [$id]), 'draft');
    has(get('pages.php?client=privacybee&page=' . $id . '&partial=1')['body'], '/media/pages/privacybee/pricing-2/index.html');
    status(A(['action' => 'create_page', 'client' => 'privacybee', 'title' => 'Bad', 'source' => 'paste', 'html' => '<?= 1 ?>']), 422);
});

// ---- Move ---------------------------------------------------------------------------------------------
test('move email: row, hosted file (copied → verified → old removed), audiences, flows, activity', function () use ($media) {
    $c = status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'Moves', 'code' => 'W9', 'source' => 'paste', 'html' => '<p>move me</p>']), 200)['json'];
    $id = (int)$c['id'];
    db()->exec("INSERT INTO email_group_map (email_id, group_id) VALUES ($id, 1)");                       // Free
    db()->exec("INSERT INTO email_flow_steps (flow_id, email_id, position) VALUES (1, $id, 3)");        // Welcome step 4
    db()->exec("INSERT INTO activity_log (company_id, entity_type, entity_id, action, actor, summary, detail) VALUES (2, 'email', $id, 'commented', 'client', 'c', 'Looks good')");
    $old = $media . '/emails/privacybee/w9-' . $id . '.html';
    ok(is_file($old));
    $r = status(A(['action' => 'move', 'kind' => 'email', 'client' => 'privacybee', 'ids' => (string)$id, 'to' => 'kenda']), 200)['json'];
    is($r['moved'], 1); is($r['files'], 1); is($r['flows_left'], 1); is($r['cleanup_failed'], 0);
    is($r['to']['slug'], 'kenda');
    $row = rows("SELECT * FROM emails WHERE id = ?", [$id])[0];
    is((int)$row['company_id'], 1);
    is($row['html_url'], '/media/emails/kenda/w9-' . $id . '.html');
    ok(!file_exists($old), 'source file removed');
    is((string)file_get_contents($media . '/emails/kenda/w9-' . $id . '.html'), '<p>move me</p>', 'same bytes');
    // audiences carried by name (created for kenda), the email left privacybee's flow (renumbered 0..2)
    is(array_column(rows("SELECT g.name, g.company_id FROM email_group_map m JOIN email_groups g ON g.id = m.group_id WHERE m.email_id = ?", [$id]), 'company_id', 'name'), ['Free' => 1]);
    is((int)q1("SELECT COUNT(*) FROM email_flow_steps WHERE email_id = ?", [$id]), 0);
    is(array_map('intval', array_column(rows("SELECT position FROM email_flow_steps WHERE flow_id = 1 ORDER BY position"), 'position')), [0, 1, 2]);
    // thread + feed follow it; a 'moved' row on the new client
    is((int)q1("SELECT company_id FROM activity_log WHERE entity_type = 'email' AND entity_id = ? AND action = 'commented'", [$id]), 1);
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'email' AND entity_id = ? AND action = 'moved' AND company_id = 1", [$id]), 1);
    // it opens under kenda now, and no longer under privacybee
    status(get('emails.php?client=kenda&email=' . $id . '&partial=1'), 200);
    status(get('emails.php?client=privacybee&email=' . $id . '&partial=1'), 404);
    has(get('?client=kenda')['body'], 'moved');
});

test('move emails in bulk: a taken code gets a suffix, everything in one go', function () {
    db()->exec("INSERT INTO emails (id, company_id, code, title, status) VALUES (50, 1, 'W2', 'Kenda W2', 'draft')");
    $r = status(A(['action' => 'move', 'kind' => 'email', 'client' => 'privacybee', 'ids' => '2,3', 'to' => 'kenda']), 200)['json'];
    is($r['moved'], 2); is($r['renamed'], 1);
    is($r['items'][0]['renamed_from'] ?? null, 'W2');
    is(q1("SELECT code FROM emails WHERE id = 2"), 'W2-2');
    is(q1("SELECT code FROM emails WHERE id = 3"), 'W3');
    is((int)q1("SELECT COUNT(*) FROM emails WHERE company_id = 1"), 4, 'W9 + kenda W2 + W2-2 + W3');
    // W1 is now the only step of Welcome
    is(array_map('intval', array_column(rows("SELECT email_id FROM email_flow_steps WHERE flow_id = 1 ORDER BY position"), 'email_id')), [1]);
});

test('move page: folder copied to media/pages/<new client>/, verified, old folder removed', function () use ($media) {
    $c = status(A(['action' => 'create_page', 'client' => 'privacybee', 'title' => 'Webinar', 'source' => 'paste', 'html' => '<h1>Webinar</h1>']), 200)['json'];
    $id = (int)$c['id']; $slug = $c['slug'];
    is($slug, 'webinar-2');
    // a second file in a subfolder, registered like page-upload.php does
    @mkdir($media . "/pages/privacybee/$slug/img", 0777, true);
    file_put_contents($media . "/pages/privacybee/$slug/img/a.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    db()->exec("INSERT INTO page_files (page_id, filename, size) VALUES ($id, 'img/a.png', 70)");
    // hmf already has a stale folder with this slug on disk → the move picks webinar-3
    @mkdir($media . "/pages/hmf/$slug", 0777, true);
    $r = status(A(['action' => 'move', 'kind' => 'page', 'client' => 'privacybee', 'ids' => (string)$id, 'to' => 'hmf']), 200)['json'];
    is($r['moved'], 1); is($r['files'], 2); is($r['cleanup_failed'], 0);
    is($r['items'][0]['slug'], 'webinar-3');
    $row = rows("SELECT * FROM pages WHERE id = ?", [$id])[0];
    is((int)$row['company_id'], 3); is($row['slug'], 'webinar-3');
    ok(is_file($media . '/pages/hmf/webinar-3/index.html') && is_file($media . '/pages/hmf/webinar-3/img/a.png'), 'files copied');
    is((string)file_get_contents($media . '/pages/hmf/webinar-3/index.html'), '<h1>Webinar</h1>');
    ok(!is_dir($media . "/pages/privacybee/$slug"), 'old folder removed');
    is((int)q1("SELECT COUNT(*) FROM page_files WHERE page_id = ?", [$id]), 2);
    is(get(mediaUrl('pages/hmf/webinar-3/index.html'), 'anon')['code'], 200);
    has(get('pages.php?client=hmf&page=' . $id . '&partial=1')['body'], '/media/pages/hmf/webinar-3/index.html');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'page' AND entity_id = ? AND action = 'moved' AND company_id = 3", [$id]), 1);
});

test('move url pages in bulk', function () {
    $r = status(A(['action' => 'move', 'kind' => 'page', 'client' => 'privacybee', 'ids' => '1,3', 'to' => 'kenda']), 200)['json'];
    is($r['moved'], 2); is($r['files'], 0);
    is(array_map('intval', array_column(rows("SELECT company_id FROM pages WHERE id IN (1, 3)"), 'company_id')), [1, 1]);
    has(get('pages.php?client=kenda&status=all')['body'], 'Spring promo landing');
});

// ---- Add to flow ----------------------------------------------------------------------------------------
test('add to flow: at a position, in selection order; already-in → 409; new flow', function () {
    // Welcome is W1 alone by now (W2 / W3 moved to kenda above)
    db()->exec("INSERT INTO email_flow_steps (flow_id, email_id, position) VALUES (1, 6, 1)");   // + E1 (created above)
    $r = status(A(['action' => 'add_to_flow', 'client' => 'privacybee', 'ids' => '5,4', 'flow_id' => 1, 'position' => 1]), 200)['json'];
    is(array_column($r['added'], 'position'), [1, 2]);
    is($r['flow']['step_count'], 4);
    is(array_map('intval', array_column(rows("SELECT email_id FROM email_flow_steps WHERE flow_id = 1 ORDER BY position"), 'email_id')), [1, 5, 4, 6]);
    status(A(['action' => 'add_to_flow', 'client' => 'privacybee', 'ids' => '4', 'flow_id' => 1]), 409);
    $r = status(A(['action' => 'add_to_flow', 'client' => 'privacybee', 'ids' => '4,1', 'flow_id' => 1]), 409);
    $r = status(A(['action' => 'add_to_flow', 'client' => 'privacybee', 'ids' => '4,5', 'new_flow' => 'Renewal']), 200)['json'];
    is($r['flow']['name'], 'Renewal');
    is((int)q1("SELECT company_id FROM email_flows WHERE id = ?", [$r['flow']['id']]), 2);
    is(array_map('intval', array_column(rows("SELECT email_id FROM email_flow_steps WHERE flow_id = ? ORDER BY position", [$r['flow']['id']]), 'email_id')), [4, 5]);
    status(A(['action' => 'add_to_flow', 'client' => 'privacybee', 'ids' => '1']), 400);
    status(A(['action' => 'add_to_flow', 'kind' => 'page', 'client' => 'privacybee', 'ids' => '1', 'flow_id' => 1]), 400);
});

// ---- Audiences ------------------------------------------------------------------------------------------
test('set audiences: add / remove across a selection, new audiences created', function () {
    $r = status(A(['action' => 'set_audiences', 'client' => 'privacybee', 'ids' => '1,4', 'add' => '2', 'remove' => '1', 'new' => 'Leads, VIP']), 200)['json'];
    is($r['changed'], 2);
    $names = static function (int $id): array {
        return array_column(rows("SELECT g.name FROM email_group_map m JOIN email_groups g ON g.id = m.group_id WHERE m.email_id = ? ORDER BY g.name", [$id]), 'name');
    };
    is($names(1), ['Leads', 'Renewal', 'VIP']);
    is($names(4), ['Leads', 'Renewal', 'VIP']);
    is($names(2), ['Free'], 'others untouched');
    is((int)q1("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'email' AND action = 'edited_groups' AND entity_id IN (1, 4)"), 2);
    has((string)q1("SELECT summary FROM activity_log WHERE action = 'edited_groups' ORDER BY id DESC LIMIT 1"), 'Audiences edited on');
    $r = status(A(['action' => 'set_audiences', 'client' => 'privacybee', 'ids' => '1', 'add' => '2']), 200)['json'];
    is($r['changed'], 0, 'no-op');
});

// ---- UI: menus, bulk bar hooks, wording, alias ----------------------------------------------------------
test('admin lists carry the ⋯ menu + Select; the client seat gets none of it', function () {
    $a = status(get('emails.php?client=privacybee&status=all'), 200)['body'];
    has($a, 'data-asg-select="email"');
    has($a, 'data-assign="move" data-kind="email" data-ids="1"');
    has($a, 'data-assign="flow"');
    has($a, 'data-assign="audiences"');
    has($a, 'Move to client…');
    $p = status(get('pages.php?client=privacybee&status=all'), 200)['body'];
    has($p, 'data-asg-select="page"');
    has($p, 'data-assign="move" data-kind="page"');
    hasNot($p, 'data-assign="flow"');
    foreach (['emails.php?client=privacybee&status=approved', 'pages.php?client=privacybee&status=pending',
              'emails.php?client=privacybee&email=5&partial=1', 'pages.php?client=privacybee&page=2&partial=1'] as $u) {
        $c = status(get($u, 'client'), 200)['body'];
        hasNot($c, 'data-assign', $u); hasNot($c, 'asg-', $u); hasNot($c, 'AssignConfig', $u);
    }
    has(get('emails.php?client=privacybee&email=5&partial=1')['body'], 'data-asg-menu-toggle');
    has(get('pages.php?client=privacybee&page=2&partial=1')['body'], 'data-asg-menu-toggle');
    has(get('?client=privacybee')['body'], 'window.AssignConfig');
});

test('Audiences wording: filter, detail, Manage → Tools, form; ?group= still works as an alias', function () {
    $c = status(get('emails.php?client=privacybee&status=all', 'client'), 200)['body'];
    has($c, 'aria-label="Filter by audience"');
    has($c, '&amp;audience=free');
    hasNot($c, 'Filter by group');
    // alias: the old ?group= URL filters exactly like ?audience=
    $old = status(get('emails.php?client=privacybee&status=all&group=renewal', 'client'), 200)['body'];
    $new = status(get('emails.php?client=privacybee&status=all&audience=renewal', 'client'), 200)['body'];
    foreach ([$old, $new] as $b) {
        has($b, 'data-email-item="5"'); hasNot($b, 'data-email-item="2"');
        has($b, 'data-audience-chip="renewal" aria-pressed="true"');
    }
    has(get('emails.php?client=privacybee&email=5&partial=1')['body'], '<dt>Audience</dt>');
    has(get('emails.php?client=privacybee&email=1&partial=1')['body'], '<dt>Audiences</dt>');
    $s = status(get('manage.php?client=privacybee&section=tools'), 200)['body'];
    has($s, '<h3 class="ui-card-title">Audiences</h3>');
    has($s, 'Add audience');
    hasNot($s, '>Groups</h3>');
    $f = status(get('add-email.php?client=privacybee&edit=1'), 200)['body'];
    has($f, 'Audiences <span');
    hasNot($f, 'No groups yet');
    $r = post('add-email.php?client=privacybee', ['action' => 'group_add', 'name' => 'Partners']);
    has(urldecode($r['location']), 'Audience "Partners" added.');
    has($r['location'], 'manage.php?client=privacybee&section=tools', 'back to Manage → Tools');
});

test('hosted email links pass the edit form; "+ New" items keep their fallback links', function () {
    $c = status(A(['action' => 'create_email', 'client' => 'privacybee', 'title' => 'Form', 'source' => 'paste', 'html' => '<p>x</p>']), 200)['json'];
    $b = status(get('add-email.php?client=privacybee&edit=' . $c['id']), 200)['body'];
    has($b, 'value="/media/emails/privacybee/');
    $r = post('add-email.php?client=privacybee', ['action' => 'update', 'id' => $c['id'], 'code' => $c['code'], 'title' => 'Form 2',
              'html_url' => $c['html_url'], 'status' => 'draft']);
    is($r['code'], 302, 'saved (redirect), not a validation error');
    is(q1("SELECT title FROM emails WHERE id = ?", [$c['id']]), 'Form 2');
    $home = get('?client=privacybee')['body'];
    has($home, 'href="/portal/add-email.php?client=privacybee" data-new-action="email"');
    has($home, 'href="/portal/add-page.php?client=privacybee" data-new-action="page"');
});

finish();
