<?php
/**
 * Lazy image-preview endpoint (preview-lib.php). Render sites point <img> at
 *
 *   GET preview.php?f=<token>&s=sm|lg[&v=<mtime of the original>][&r=<retry n>]
 *
 * when the derivative does not exist yet. token = base64url(ref) . '.' . HMAC — only paths the portal itself
 * printed can be requested, and the ref is resolved again through the containment helpers (uploads/,
 * media/tires/, media/library/, media/pages/).
 *
 *   302 → the static derivative <dir>/.thumbs/<stem>.<size>.<fmt>?v=<mtime> — the very URL the next page render
 *         prints — with a year of immutable caching, so each preview is downloaded once. A missing derivative is
 *         made first: every stale size of that original in ONE decode, inside one of the host-wide generator
 *         slots (preview-lib.php previewSlotAcquire, default 2) — a page's first request never waits for one; a
 *         retry (&r=n, at most two per page at a time) waits up to 2 s. An `sm` request is answered as soon as sm
 *         is written; `lg` of the same decode is finished after the response.
 *   200 placeholder (image/svg+xml, no-store, X-Preview + Server-Timing: pv-pending | pv-failed) when every slot is busy / the original
 *         is being made by another request (pending: 1×1 — static/js/app.js App.previewRetry retries with backoff),
 *         or when no preview can be made (failed: 2×2; logged once, remembered in <stem>.fail.json until the
 *         original changes or Build previews succeeds). The 4 MB original is NEVER sent into a tile.
 *   302 → the original only when that size needs no derivative at all (it is already small).
 *   400 bad size / malformed token · 403 signature mismatch · 404 unknown or missing file · 405 method
 *
 * No DB, no helpers.php: media-lib / tire-series-lib / pages-lib / preview-lib are function definitions only;
 * no session is opened (and any open one is released before work). Env PREVIEW_QA_FAIL=1 forces the failure branch.
 */
require_once __DIR__ . '/media-lib.php';
require_once __DIR__ . '/tire-series-lib.php';
if (is_file(__DIR__ . '/pages-lib.php')) require_once __DIR__ . '/pages-lib.php';
require_once __DIR__ . '/preview-lib.php';

function previewEndpointFail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $msg;
    exit;
}

/** The placeholder tile (no-store): pending → the page retries; failed → it stays. */
function previewEndpointPlaceholder(string $kind): void {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-store');
    header('X-Preview: ' . $kind);
    header('Server-Timing: pv-' . $kind);   // readable from JS (PerformanceResourceTiming.serverTiming): App.previewRetry
    if ($kind === 'pending') header('Retry-After: 2');
    header('X-Content-Type-Options: nosniff');
    echo previewPlaceholderSvg($kind);
    exit;
}

/**
 * Answer the tile NOW (302 to the static sm that was just written) and keep running to finish the other size of the
 * same decode: fastcgi_finish_request() (PHP-FPM) / litespeed_finish_request() (LSAPI), else an empty body with
 * Content-Length: 0 + Connection: close, flushed. Nothing may be sent after this.
 */
function previewEndpointFinishEarly(string $url): void {
    @ignore_user_abort(true);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Location: ' . $url, true, 302);
    header('Content-Length: 0');
    header('Connection: close');
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
    if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); return; }
    while (ob_get_level() > 0) { @ob_end_flush(); }
    flush();
}

/** 302 with a year of caching (the target URL carries ?v=<mtime of the original>). */
function previewEndpointRedirect(string $url, int $maxAge): void {
    header('Cache-Control: public, max-age=' . $maxAge . ($maxAge >= 31536000 ? ', immutable' : ''));
    header('Location: ' . $url, true, 302);
    exit;
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') previewEndpointFail(405, 'Method not allowed');
$size = (string)($_GET['s'] ?? 'sm');
if (!isset(previewSizes()[$size])) previewEndpointFail(400, 'Unknown size');
$token = (string)($_GET['f'] ?? '');
$why = '';
$ref = previewTokenRef($token, $why);
if ($ref === null) previewEndpointFail($why === 'forged' ? 403 : 400, $why === 'forged' ? 'Bad signature' : 'Bad token');
$abs = previewRefPath($ref);
if ($abs === null || !is_file($abs)) previewEndpointFail(404, 'Not found');

if (function_exists('previewReleaseSession')) previewReleaseSession();
previewGdHeader();

if (!previewIsImage($abs) || previewDims($abs) === null) {
    error_log('preview.php: ' . $ref . ' is not a decodable image');
    previewEndpointPlaceholder('failed');
}
if (!previewNeeds($abs, $size)) {
    previewEndpointRedirect(previewRefUrl($ref), 86400);   // already small: the original IS the preview
}
if (!previewIsFresh($abs, $size)) {
    if (previewFailed($abs)) previewEndpointPlaceholder('failed');
    $stale = array_values(array_filter(array_keys(previewSizes()), static function ($s) use ($abs) { return !previewIsFresh($abs, $s); }));
    $status = '';
    $answered = false;
    // A tile (sm) is answered as soon as sm exists; lg of the same decode is finished after the response.
    $early = $size !== 'sm' ? null : static function (string $s, string $p) use ($ref, $abs, &$answered): void {
        if ($answered || $s !== 'sm') return;
        clearstatcache(true, $p);
        $answered = true;
        previewEndpointFinishEarly(previewStaticUrl($ref, $abs, 'sm'));
    };
    try {
        // The page's first burst of tile requests never waits for a slot (placeholder at once — no pile-up of PHP
        // workers). A retry (&r=n) comes from App.previewRetry, which sends at most two at a time per page: it may
        // wait up to 2 s for a slot, so the slots never sit idle between a finished preview and the next retry.
        $wait = isset($_GET['r']) ? 2.0 : 0.0;
        previewGenerate($abs, $stale, ['wait' => $wait, 'early' => $early], $status);
    } catch (Throwable $e) {
        $status = 'failed';
        error_log('preview.php: ' . $e->getMessage());
    }
    if ($answered) exit;
    clearstatcache();
    if ($status === 'busy') previewEndpointPlaceholder('pending');
    if (!previewIsFresh($abs, $size)) {
        error_log('preview.php: could not make the ' . $size . ' preview of ' . $ref . ' — placeholder served');
        previewMarkFailed($abs, 'lazy ' . $size);
        previewEndpointPlaceholder('failed');
    }
}
previewEndpointRedirect(previewStaticUrl($ref, $abs, $size), 31536000);
