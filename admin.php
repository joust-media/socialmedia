<?php
/**
 * admin.php → 301 shim (spec §3: old filenames keep working). The admin dashboard that lived here became
 * Studio and then Manage (manage.php); the old ?tab= values follow legacyAdminTarget() (helpers.php), exactly
 * like studio.php. Auth behaves as it always did on this URL: a visitor without the admin session is sent to
 * login before any redirect or output.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';      // resolves $client from ?client=
require_once __DIR__ . '/auth.php';
requireAdmin();

header('Location: ' . (legacyAdminModuleTarget($pdo, $client ?? null, $_GET) ?? legacyAdminTarget(['client' => $clientSlug] + $_GET)), true, 301);   // the resolved scope (an unknown slug → unscoped)
exit;
