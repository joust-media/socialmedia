<?php
/**
 * LEGACY — the pre-redesign image library, RETIRED: Assets → Library (assets.php?view=library) reviews,
 * comments, downloads and uploads the same images. Old bookmarks 301 there, scope kept.
 *
 * Runs from the legacy/ subfolder: SCRIPT_NAME is normalised first so clientUrl() points at the app root.
 */
if (!empty($_SERVER['SCRIPT_NAME']) && preg_match('#/legacy/[^/]+$#', $_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = preg_replace('#/legacy/([^/]+)$#', '/$1', $_SERVER['SCRIPT_NAME']);
}
require __DIR__ . '/../library.php';   // the root shim maps the old parameters and 301s
