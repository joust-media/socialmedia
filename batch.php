<?php
/**
 * Studio → Batch — RETIRED. The New post pop-up (static/js/newpost.js → post-compose.php) builds posts from
 * approved assets and fresh uploads; the Upload sheet (static/js/upload-sheet.js, "+ New → Upload") turns files
 * into one Draft post each ("New post · a draft post per file", batch-process.php). Old links land there:
 *
 *   batch.php?client=<slug>   → 302 studio.php?client=<slug>&tab=uploads&upload=1&dest=post&each=1 (the sheet opens)
 *   batch.php (no client)     → 302 studio.php?upload=1 (the sheet asks for the client)
 *
 * batch-process.php (the endpoint the Upload sheet posts claimed[] tokens to) is unchanged.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

header('Location: ' . ($client ? uploadSheetUrl('studio.php', ['dest' => 'post', 'each' => true], ['tab' => 'uploads']) : pagePath('studio') . '?upload=1'), true, 302);
exit;
