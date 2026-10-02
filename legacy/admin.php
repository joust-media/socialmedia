<?php
/**
 * LEGACY — "Classic admin", RETIRED. Every capability it had lives in the new UI (README "Classic admin →
 * new UI"), so this URL only redirects now (301), admin only like before, client scope and ?tab= kept
 * through legacyAdminTarget() (helpers.php): no tab → Manage, tab=posts → Posts, and so on.
 *
 * Runs from the legacy/ subfolder: SCRIPT_NAME is normalised first so basePath()/pagePath() and
 * requireAdmin()'s login redirect point at the app root rather than at /legacy/.
 */
if (!empty($_SERVER['SCRIPT_NAME']) && preg_match('#/legacy/[^/]+$#', $_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = preg_replace('#/legacy/([^/]+)$#', '/$1', $_SERVER['SCRIPT_NAME']);
}
require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth.php';
requireAdmin();

header('Location: ' . (legacyAdminModuleTarget($pdo, $client ?? null, $_GET) ?? legacyAdminTarget(['client' => $clientSlug] + $_GET)), true, 301);
exit;
