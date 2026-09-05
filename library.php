<?php
/**
 * Legacy shim — the Library moved into the unified Assets page.
 * 301 → assets.php?view=library (preserves ?client=). The previous page
 * lives on as legacy/library.php until parity is verified.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';   // resolves $client from ?client=

header('Location: ' . clientUrl('assets.php', ['view' => 'library']), true, 301);
exit;
