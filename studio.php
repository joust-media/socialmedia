<?php
/**
 * studio.php — RETIRED (the Studio hub became Manage, manage.php). Every old link still lands somewhere
 * that works; the map lives in legacyAdminTarget() (helpers.php), shared with admin.php and legacy/admin.php:
 *
 *   studio.php                                  → manage.php                    (Manage → Clients)
 *   studio.php?tab=clients[&edit=<id>]          → manage.php?section=clients[&edit=<id>]
 *   studio.php?client=…                         → manage.php?client=…
 *   studio.php?client=…&tab=posts               → posts.php?client=…
 *   studio.php?client=…&tab=uploads|batch       → posts.php?client=…&upload=1&dest=post&each=1   (the Upload sheet)
 *   studio.php?client=…&tab=compose | &newpost=1 → posts.php?client=…&newpost=1                    (the New post pop-up)
 *   studio.php?client=…&tab=emails | pages      → emails.php / pages.php?client=…
 *   studio.php?client=…&tab=renders[&tire=…]    → assets.php?client=…&view=collections[&item=…&manage=series] (Manage series)
 *   studio.php?client=…&tab=export[&tire&series] → manage.php?client=…&section=export[&tire&series]
 *   studio.php?…&upload=1[&dest…]               → posts.php?…&upload=1[&dest…]
 * ?msg= is kept. Admin only, like the page it replaces: a client session goes to sign-in first.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

header('Location: ' . (legacyAdminModuleTarget($pdo, $client ?? null, $_GET) ?? legacyAdminTarget(['client' => $clientSlug] + $_GET)), true, 301);   // the resolved scope (an unknown slug → unscoped)
exit;
