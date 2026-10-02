<?php
/**
 * LEGACY — the pre-redesign per-module gallery, RETIRED. Old bookmarks 301 to the page that owns the
 * module now, scope kept (the same map as the root features.php router):
 *   module=tires (or none) [&item= | &tire=] → assets.php?view=collections[&item=]
 *   module=emails → emails.php · module=pages → pages.php · anything else → assets.php?view=collections
 *
 * Runs from the legacy/ subfolder: SCRIPT_NAME is normalised first so clientUrl() points at the app root.
 */
if (!empty($_SERVER['SCRIPT_NAME']) && preg_match('#/legacy/[^/]+$#', $_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = preg_replace('#/legacy/([^/]+)$#', '/$1', $_SERVER['SCRIPT_NAME']);
}
require __DIR__ . '/../features.php';
