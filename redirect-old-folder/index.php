<?php
/**
 * PHP fallback for the old-folder redirect (see .htaccess next to this file).
 *
 * Runs only when Apache serves /socialmedia/ (DirectoryIndex) without honouring
 * the RedirectMatch in .htaccess. Sends a permanent redirect to the same path
 * under the new folder, keeping the query string:
 *
 *   /socialmedia/?client=kenda            -> /portal/?client=kenda
 *   /socialmedia/index.php?client=kenda   -> /portal/index.php?client=kenda
 *
 * Deep links such as /socialmedia/posts.php cannot reach this file when
 * .htaccess is ignored (the script no longer exists in the old folder), which
 * is why the .htaccess rule is the primary mechanism and this is the backstop.
 */

const OLD_FOLDER = '/socialmedia';
const NEW_FOLDER = '/portal';

$uri   = (string)($_SERVER['REQUEST_URI'] ?? OLD_FOLDER . '/');
$path  = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
$query = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');

// Everything after the old folder name; '' for /socialmedia and /socialmedia/.
$rest = '';
if ($path === OLD_FOLDER || $path === OLD_FOLDER . '/') {
    $rest = '';
} elseif (strpos($path, OLD_FOLDER . '/') === 0) {
    $rest = substr($path, strlen(OLD_FOLDER) + 1);
} else {
    // Served from an unexpected location: fall back to whatever follows this
    // script's own directory so the redirect still lands somewhere sane.
    $dir  = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $rest = ($dir !== '' && strpos($path, $dir . '/') === 0) ? substr($path, strlen($dir) + 1) : ltrim($path, '/');
}

// Never emit a scheme-relative (//host) or backslash target.
$rest = ltrim($rest, '/\\');

$target = NEW_FOLDER . '/' . $rest . ($query !== '' ? '?' . $query : '');

header('Location: ' . $target, true, 301);
header('Cache-Control: max-age=3600');
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><title>Moved</title><p>This page has moved to <a href="'
   . htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
   . '">' . htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>.</p>';
exit;
