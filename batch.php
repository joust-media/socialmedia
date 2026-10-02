<?php
/**
 * Studio → Batch — RETIRED. The New post pop-up (static/js/newpost.js → post-compose.php) builds posts from
 * approved assets and fresh uploads; "+ New → Upload" (Studio → Uploads, batch-process.php) turns files into
 * one Draft post each. Old links land there:
 *
 *   batch.php?client=<slug>   → 302 studio.php?client=<slug>&tab=uploads
 *   batch.php (no client)     → 302 studio.php (client chooser)
 *
 * batch-process.php (the endpoint the Uploads zone posts claimed[] tokens to) is unchanged.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

header('Location: ' . ($client ? clientUrl('studio.php', ['tab' => 'uploads']) : pagePath('studio')), true, 302);
exit;
