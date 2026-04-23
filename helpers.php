<?php
/**
 * Shared helpers for client-scoped pages.
 * require_once __DIR__ . '/helpers.php';
 *
 * Exposes:
 *   $client         → company row (id, name, slug, feature_label) or null if unscoped
 *   $clientSlug     → string or ''
 *   clientQs()      → 'client=hmf' or '' for URL building
 *   clientUrl($page, $extra = []) → builds "page.php?client=hmf&…"
 */

// $pdo must be included before this file.

$clientSlug = '';
$client     = null;

if (!empty($_GET['client'])) {
    $slugCandidate = strtolower(trim((string)$_GET['client']));
    $slugCandidate = preg_replace('/[^a-z0-9\-]/', '', $slugCandidate);
    if ($slugCandidate !== '' && isset($pdo)) {
        $stmt = $pdo->prepare("SELECT id, name, slug, feature_label FROM companies WHERE slug = ?");
        $stmt->execute([$slugCandidate]);
        $row = $stmt->fetch();
        if ($row) {
            $client     = $row;
            $clientSlug = $row['slug'];
        }
    }
}

/** Return "client=hmf" or "" for building URLs */
function clientQs() {
    global $clientSlug;
    return $clientSlug !== '' ? 'client=' . urlencode($clientSlug) : '';
}

/** Build URL to a page preserving client scope and merging extras */
function clientUrl($page, $extra = []) {
    global $clientSlug;
    $qs = [];
    if ($clientSlug !== '') { $qs['client'] = $clientSlug; }
    foreach ($extra as $k => $v) {
        if ($v !== null && $v !== '') { $qs[$k] = $v; }
    }
    return $page . ($qs ? '?' . http_build_query($qs) : '');
}
