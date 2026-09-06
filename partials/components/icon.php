<?php
/**
 * icon(string $name, string $class = '', array $attrs = []): string
 *
 * Inlines static/icons/<name>.svg so the glyph inherits currentColor.
 * Files are read once per request and cached in a static array.
 * Unknown names render nothing (never a broken image).
 *
 *   <?= icon('house') ?>                       → <svg class="ui-icon ui-icon--house" aria-hidden="true" …>
 *   <?= icon('checkmark', 'ui-row-chevron') ?>
 *   <?= icon('play', '', ['aria-label' => 'Play']) ?>
 */
if (!function_exists('icon')) {
    function icon(string $name, string $class = '', array $attrs = []): string
    {
        static $cache = [];

        $name = preg_replace('/[^a-z0-9\-]/', '', strtolower($name));
        if ($name === '') return '';

        if (!array_key_exists($name, $cache)) {
            $file = dirname(__DIR__, 2) . '/static/icons/' . $name . '.svg';
            $svg  = is_file($file) ? @file_get_contents($file) : false;
            $cache[$name] = ($svg === false) ? '' : trim($svg);
        }
        if ($cache[$name] === '') return '';

        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $cls   = trim('ui-icon ui-icon--' . $name . ' ' . $class);
        $extra = ' class="' . $esc($cls) . '"';
        if (!isset($attrs['aria-label']) && !isset($attrs['role'])) {
            $extra .= ' aria-hidden="true"';
        }
        $extra .= ' focusable="false"';
        foreach ($attrs as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-:]/', '', (string)$k);
            if ($k === '') continue;
            $extra .= ' ' . $k . '="' . $esc($v) . '"';
        }

        return preg_replace('/<svg\b/', '<svg' . $extra, $cache[$name], 1);
    }
}
