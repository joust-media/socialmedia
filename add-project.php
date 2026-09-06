<?php
/**
 * Legacy shim — the "add task" form lives on the Projects page.
 * 301s there, preserving ?client=.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

header('Location: ' . clientUrl('projects.php'), true, 301);
exit;
