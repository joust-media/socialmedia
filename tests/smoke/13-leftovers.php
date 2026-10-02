<?php
/**
 * Integration leftovers after wt/fixa + wt/fixb:
 *   - Edit tire (add-feature.php): the reference-photo status reads To Review · Approved · Needs changes (never Approve / Deny),
 *     and Needs changes needs a note (the page asks in a sheet; the server keeps the >= 3 characters rule).
 *   - Assets: the admin's Library "Upload" is in the header (nav links), not under the filter chips.
 *   - #13 unscoped add-post.php → the New post pop-up (it asks for the client first); ?edit=<id> → that post's client.
 *   - #14 the tire page's series chips count the images in the ACTIVE filter (one meaning per chip).
 *   - #20 Manage → Clients card: one form, one "Save changes" (fields + Settings), no file paths in the copy.
 *   - #21 Image previews live in Manage → Tools → Maintenance (Export points there).
 * Seed: tire 1 Klever AT2 — references 1–3 (approved); Series 1 = ids 4–11 (5 approved, 1 denied, 2 pending);
 *       Series 2 = ids 12–17 (5 approved, 1 denied). Posts: 2 = kenda.
 */
require __DIR__ . '/lib.php';

/** Visible text only (no scripts / styles / comments / attribute values). */
function plainText(string $html): string {
    $html = preg_replace('#<(script|style|template)\b.*?</\1>#si', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
/** The series chips on a tire page → [key => count]. */
function seriesChips(string $html): array {
    preg_match_all('#data-series-chip="([^"]+)".*?data-series-count="\1"[^>]*>(\d+)<#s', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) $out[$x[1]] = (int)$x[2];
    return $out;
}

// ---- 4a: Edit tire → reference photo status ----------------------------------------------------------
test('Edit tire: the reference photo status says Needs changes (no Approve / Deny) and wires the note sheet', function () {
    $b = status(get('add-feature.php?client=kenda&module=tires&edit_item=1'), 200)['body'];
    ok(preg_match('#data-status-set="denied"[^>]*>Needs changes</button>#', $b) === 1, 'Needs changes chip');
    ok(preg_match('#data-status-set="pending"[^>]*>To Review</button>#', $b) === 1, 'To Review chip');
    $t = plainText($b);
    ok(!preg_match('/\bDeny\b/', $t), 'no "Deny" on the page');
    ok(!preg_match('/>\s*Approve\s*</', $b), 'no "Approve" chip');
    has($b, 'data-tl-note-form', 'the note sheet');
    has($b, "fd.append('comment', note)", 'the note travels with the status');
    has($b, 'id="uiSheet"', 'the shared sheet shell is on the page');
});
test('Edit tire: Needs changes without a note → 422; with a note → saved + logged as a comment', function () {
    $r = post('tire-status.php', ['id' => 2, 'status' => 'denied', 'actor' => 'admin'], 'admin', [], ['Accept' => 'application/json']);
    is($r['code'], 422, 'no note');
    $r = post('tire-status.php', ['id' => 2, 'status' => 'denied', 'actor' => 'admin', 'comment' => 'ab'], 'admin', [], ['Accept' => 'application/json']);
    is($r['code'], 422, '2 characters');
    is(q1("SELECT status FROM tire_images WHERE id = 2"), 'approved', 'unchanged');
    $r = status(post('tire-status.php', ['id' => 2, 'status' => 'denied', 'actor' => 'admin', 'comment' => 'Tread is blurry'], 'admin', [], ['Accept' => 'application/json']), 200);
    is($r['json']['ok'] ?? null, true);
    is(q1("SELECT status FROM tire_images WHERE id = 2"), 'denied');
    is(q1("SELECT detail FROM activity_log WHERE entity_type = 'tire_image' AND entity_id = 2 AND action = 'commented' ORDER BY id DESC LIMIT 1"), 'Tread is blurry');
    $b = status(get('add-feature.php?client=kenda&module=tires&edit_item=1'), 200)['body'];
    ok(preg_match('#data-image-id="2" data-status="denied"#', $b) === 1, 'the row reads Needs changes after a reload');
});

// ---- 4b: Assets Upload in the header ---------------------------------------------------------------
test('Assets (Library): the admin Upload is a header action, not under the filter chips', function () {
    $b = status(get('assets.php?client=kenda'), 200)['body'];
    ok(preg_match('#<header class="ui-nav.*?</header>#s', $b, $hdr) === 1, 'header');
    ok(preg_match('#<nav class="ui-nav-links"[^>]*>.*?data-upload-open data-upload-dest="library"[^>]*data-library-upload[^>]*>Upload</a>#s', $hdr[0]) === 1, 'Upload in the header links');
    ok(preg_match('#<div class="as-controls">.*?</div>#s', $b, $ctl) === 1, 'filter chips');
    hasNot($ctl[0], 'data-library-upload', 'not under the chips');
    hasNot(status(get('assets.php?client=kenda', 'client'), 200)['body'], 'data-library-upload', 'client: no Upload');
    hasNot(status(get('assets.php?client=kenda&view=collections'), 200)['body'], 'data-library-upload', 'Tires list: New tire, not Upload');
});

// ---- #13 unscoped add-post ---------------------------------------------------------------------------
test('#13 unscoped add-post.php → the New post pop-up (client chooser first); ?edit=N → that post\'s client', function () {
    $r = get('add-post.php');
    is($r['code'], 302);
    ok(preg_match('#/posts\.php\?newpost=1$#', $r['location']) === 1, 'posts.php?newpost=1: ' . $r['location']);
    $c = status(get('posts.php?newpost=1'), 200)['body'];
    has($c, 'js/newpost.js', 'the pop-up boots on the chooser page');
    has($c, '"client":""', 'unscoped config → it asks which client');
    $e = get('add-post.php?edit=2');
    is($e['code'], 302);
    has($e['location'], 'posts.php?client=kenda&post=2&newpost=edit');
    ok(preg_match('#/posts\.php\?newpost=1$#', get('add-post.php?edit=99999')['location']) === 1, 'unknown post → the chooser');
});

// ---- #14 series chips follow the filter ------------------------------------------------------------
test('#14 series chips count the active filter (To Review / Approved / Needs changes)', function () {
    // expected = the DB's own numbers per filter (an earlier test in this suite may have moved reference image 2)
    $want = static function (string $st): array {
        $n = static fn(string $where) => (int)q1("SELECT COUNT(*) FROM tire_images WHERE tire_id = 1 AND $where AND status = ?", [$st]);
        return ['ref' => $n('series_id IS NULL'), 1 => $n('series_id = 1'), 2 => $n('series_id = 2')];
    };
    is($want('approved')[1], 5, 'seed: Series 1 has 5 approved');
    is(seriesChips(status(get('assets.php?client=kenda&view=collections&item=1&series=1'), 200)['body']), $want('pending'), 'To Review');
    $a = status(get('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved'), 200)['body'];
    is(seriesChips($a), $want('approved'), 'Approved');
    ok(preg_match('#data-series-chip="ref"[^>]*>\s*Reference<span#', $a) === 1, 'the Reference chip has no second number in its label');
    has($a, 'title="5 approved"');
    is(seriesChips(status(get('assets.php?client=kenda&view=collections&item=1&series=2&filter=denied'), 200)['body']), $want('denied'), 'Needs changes');
    is(seriesChips(status(get('assets.php?client=kenda&view=collections&item=1&series=1&filter=approved', 'client'), 200)['body']), $want('approved'), 'client, Approved');
});

// ---- #20 one Save on the client card ------------------------------------------------------------------
test('#20 Manage → Clients card: one Save (fields + Settings), no file paths shown', function () {
    $b = status(get('manage.php?client=kenda'), 200)['body'];
    ok(preg_match('#<section class="ui-card studio-client-card" data-client-edit="1">.*?</section>#s', $b, $card) === 1, 'card');
    is(substr_count($card[0], 'Save settings'), 0, 'no Save settings');
    is(substr_count($card[0], '>Save changes</button>'), 1, 'one Save changes');
    ok(preg_match('#<form[^>]*>\s*<input type="hidden" name="action" value="update">.*?data-client-settings.*?data-client-save.*?</form>#s', $card[0]) === 1, 'Settings inside the one form');
    $t = plainText($card[0]);
    foreach (['uploads/', 'static/brand', '.png'] as $path) ok(strpos($t, $path) === false, "no \"$path\" in the card");
    $list = plainText(status(get('manage.php'), 200)['body']);
    ok(strpos($list, 'static/brand') === false, 'New client card + list footnote: no paths');
});
test('#20 the one Save writes the fields and the settings together', function () {
    $r = status(post('client-admin.php?client=kenda', ['action' => 'update', 'id' => 1, 'name' => 'Kenda Tires', 'slug' => 'kenda', 'feature_label' => 'Tires',
                                                       'default_hashtags' => '#OneSave', 'product_type' => 'tires', 'industry' => 'powersports'],
                     'admin', [], ['Accept' => 'application/json']), 200);
    is($r['json']['ok'] ?? null, true);
    is(q1("SELECT default_hashtags FROM companies WHERE id = 1"), '#OneSave');
    is(q1("SELECT industry FROM companies WHERE id = 1"), 'powersports');
    // a too-long setting stops the whole save (nothing written)
    status(post('client-admin.php?client=kenda', ['action' => 'update', 'id' => 1, 'name' => 'Kenda Renamed', 'slug' => 'kenda', 'feature_label' => 'Tires',
                                                  'industry' => str_repeat('x', 200)], 'admin', [], ['Accept' => 'application/json']), 422);
    is(q1("SELECT name FROM companies WHERE id = 1"), 'Kenda Tires', 'name untouched');
    // the old settings action still answers (API compatibility)
    status(post('client-admin.php?client=kenda', ['action' => 'settings', 'id' => 1, 'industry' => 'tyres'], 'admin', [], ['Accept' => 'application/json']), 200);
    is(q1("SELECT industry FROM companies WHERE id = 1"), 'tyres');
});

// ---- #21 Image previews under Tools ---------------------------------------------------------------------
test('#21 Image previews: Manage → Tools → Maintenance (scoped + unscoped); Export points there', function () {
    $t = status(get('manage.php?client=kenda&section=tools'), 200)['body'];
    ok(preg_match('#data-tools-maintenance.*?id="previews" data-previews data-tool="previews"#s', $t) === 1, 'after Maintenance');
    has($t, 'js/studio-previews.js');
    has($t, 'All clients (not just Kenda Tires)');
    hasNot($t, 'in Export');
    $u = status(get('manage.php?section=tools'), 200)['body'];
    has($u, 'data-previews data-tool="previews"', 'unscoped Tools'); has($u, 'Every client’s images');
    $e = status(get('manage.php?client=kenda&section=export'), 200)['body'];
    hasNot($e, 'data-previews ', 'not in Export'); hasNot($e, 'studio-previews.js');
    ok(preg_match('#data-previews-moved>Image previews are in <a href="[^"]*section=tools\#previews">Tools → Maintenance</a>#', $e) === 1, 'Export links to it');
});

finish();
