<?php
/**
 * AI Prompt Library — shared registries and helpers.
 *
 * require_once __DIR__ . '/prompt-lib.php';   (after db.php)
 *
 * Phase 1 is admin-only: this file powers the Prompt Library admin pages
 * (prompts.php, add-prompt.php) and the Builder (build.php). It contains no
 * API calls — all AI-service integration is deferred to Phase 2. The
 * composePrompt() helper below is written so Phase 2 can reuse it server-side.
 */

/** Separator placed between composed prompt segments. */
const PROMPT_SEPARATOR = ', ';

/**
 * Fixed prompt categories. Order here is also the composition order for the
 * required categories. 'character' is a single category — the Builder offers
 * up to four character *slots*, all drawing from it.
 */
function promptCategories() {
    return [
        'camera'      => ['label' => 'Camera',      'icon' => '🎥', 'required' => true],
        'lighting'    => ['label' => 'Lighting',    'icon' => '💡', 'required' => true],
        'environment' => ['label' => 'Environment', 'icon' => '🏞', 'required' => true],
        'product'     => ['label' => 'Product',     'icon' => '📦', 'required' => true],
        'character'   => ['label' => 'Character',   'icon' => '🧍', 'required' => false],
        'references'  => ['label' => 'References',   'icon' => '📐', 'required' => false],
        'custom'      => ['label' => 'Custom',      'icon' => '✨', 'required' => false],
    ];
}

/** Just the category slugs, in composition order. */
function promptCategorySlugs() {
    return array_keys(promptCategories());
}

/** Human label for a category slug ('camera' -> 'Camera'). */
function promptCategoryLabel($slug) {
    $cats = promptCategories();
    return $cats[$slug]['label'] ?? ucfirst((string)$slug);
}

/** Icon for a category slug. */
function promptCategoryIcon($slug) {
    $cats = promptCategories();
    return $cats[$slug]['icon'] ?? '🗂';
}

/** How many optional character slots the Builder exposes. */
const PROMPT_CHARACTER_SLOTS = 4;

/**
 * Model registry. Phase 1 stores no API config — `service` is recorded for
 * Phase 2. `max_reference_images` is a sensible default; confirm against the
 * Higgsfield docs when the API is wired up in Phase 2.
 */
function promptModels() {
    return [
        'nano_banana_pro' => [
            'label'   => 'Nano Banana Pro',
            'type'    => 'image',
            'service' => 'higgsfield',
            'max_reference_images' => 3,
        ],
        'kling3_0' => [
            'label'   => 'Kling 3.0',
            'type'    => 'video',
            'service' => 'higgsfield',
            'max_reference_images' => 4,
        ],
        'seedance_2_0' => [
            'label'   => 'Seedance 2.0',
            'type'    => 'video',
            'service' => 'higgsfield',
            'max_reference_images' => 4,
        ],
        'veo_3_1' => [
            'label'   => 'Veo 3.1 (Google)',
            'type'    => 'video',
            'service' => 'higgsfield',
            'max_reference_images' => 3,
        ],
    ];
}

/** Model slugs only. */
function promptModelSlugs() {
    return array_keys(promptModels());
}

/** Human label for a model slug. Falls back to the slug if unknown. */
function promptModelLabel($slug) {
    $m = promptModels();
    return $m[$slug]['label'] ?? (string)$slug;
}

/** Supported aspect ratios → suggested pixel dimensions. */
function promptAspectRatios() {
    return [
        '1:1'  => '1080×1080',
        '9:16' => '1080×1920',
        '4:3'  => '1440×1080',
    ];
}

/**
 * Dynamic-variable registry. Keys are the names used inside {{ }} placeholders.
 * `source` is shown read-only in the admin Variables Reference view.
 *
 * Behaviour when a client has no value for a variable: the placeholder is
 * skipped silently and surrounding punctuation/whitespace is tidied up
 * (see substitutePromptVariables()).
 */
function promptVariables() {
    return [
        'product_type' => [
            'label'  => 'Product type',
            'source' => 'Client profile — companies.product_type',
        ],
        'brand_name' => [
            'label'  => 'Brand name',
            'source' => 'Client profile — companies.name (display name)',
        ],
        'product_name' => [
            'label'  => 'Product name',
            'source' => 'The specific feed item selected in the Builder (when applicable)',
        ],
        'industry' => [
            'label'  => 'Industry',
            'source' => 'Client profile — companies.industry',
        ],
        'vehicle_manufacturer' => [
            'label'  => 'Vehicle manufacturer',
            'source' => 'Vehicle Library — the vehicle selected in the AI Builder',
        ],
        'vehicle_model' => [
            'label'  => 'Vehicle model',
            'source' => 'Vehicle Library — the vehicle selected in the AI Builder',
        ],
        'vehicle_year' => [
            'label'  => 'Vehicle year',
            'source' => 'Vehicle Library — the vehicle selected in the AI Builder',
        ],
        'vehicle_type' => [
            'label'  => 'Vehicle type',
            'source' => 'Vehicle Library — the vehicle selected in the AI Builder',
        ],
    ];
}

/** Registered variable names only. */
function promptVariableNames() {
    return array_keys(promptVariables());
}

/**
 * Extract every {{variable}} name referenced in a block of prompt text.
 * Returns a de-duplicated, lower-cased list (order preserved by first use).
 */
function extractPromptVariables($text) {
    if (!preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', (string)$text, $m)) {
        return [];
    }
    $seen = [];
    foreach ($m[1] as $name) {
        $name = strtolower($name);
        if (!in_array($name, $seen, true)) { $seen[] = $name; }
    }
    return $seen;
}

/**
 * Return the variable names used in $text that are NOT in the registry.
 * Used by add-prompt.php to block saving prompts with unknown placeholders.
 */
function invalidPromptVariables($text) {
    $known = promptVariableNames();
    return array_values(array_filter(
        extractPromptVariables($text),
        function ($v) use ($known) { return !in_array($v, $known, true); }
    ));
}

/**
 * Substitute {{variables}} in $text from $context (['var' => 'value', ...]).
 * Missing / empty values: the placeholder is removed and any now-dangling
 * separator punctuation or doubled whitespace is cleaned up.
 */
function substitutePromptVariables($text, array $context) {
    $text = (string)$text;

    // Replace known, non-empty values first.
    foreach ($context as $key => $val) {
        $val = trim((string)$val);
        if ($val === '') { continue; }
        $text = preg_replace(
            '/\{\{\s*' . preg_quote((string)$key, '/') . '\s*\}\}/i',
            $val,
            $text
        );
    }

    // Drop any placeholders that had no value.
    $text = preg_replace('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', '', $text);

    // Tidy: collapse whitespace, remove spaces before punctuation, and
    // squash separators left stranded by a removed placeholder.
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\s+([,.;:])/', '$1', $text);
    $text = preg_replace('/([,;:])\s*([,.;:])/', '$2', $text);
    $text = preg_replace('/(^[\s,;:]+|[\s,;:]+$)/', '', $text);

    return trim($text);
}

/**
 * Compose a final prompt from ordered segments + an optional custom modifier.
 *
 * @param array $segments  Ordered list of raw prompt_text strings.
 * @param string $modifier Optional one-off custom modifier appended last.
 * @param array $context   Variable values for substitution.
 * @return string
 */
function composePrompt(array $segments, $modifier, array $context) {
    $parts = [];
    foreach ($segments as $seg) {
        $seg = trim((string)$seg);
        if ($seg !== '') { $parts[] = $seg; }
    }
    $modifier = trim((string)$modifier);
    if ($modifier !== '') { $parts[] = $modifier; }

    $joined = implode(PROMPT_SEPARATOR, $parts);
    return substitutePromptVariables($joined, $context);
}

/** Does the prompts table exist yet? (migrate.php may not have run.) Cached. */
function hasPromptsTable(PDO $pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prompts'
    ");
    $s->execute();
    return $cached = (int)$s->fetchColumn() > 0;
}

/** Does the vehicles table exist yet? (migrate.php may not have run.) Cached. */
function hasVehiclesTable(PDO $pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles'
    ");
    $s->execute();
    return $cached = (int)$s->fetchColumn() > 0;
}

/** Does companies.product_type exist yet? Cached. */
function hasClientProfileColumns(PDO $pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'companies'
          AND COLUMN_NAME IN ('product_type', 'industry')
    ");
    $s->execute();
    return $cached = (int)$s->fetchColumn() >= 2;
}

/**
 * Normalise a comma-separated string into a clean, de-duplicated array.
 * Used for prompts.tags and prompts.compatible_models.
 */
function splitCommaList($str) {
    $out = [];
    foreach (explode(',', (string)$str) as $piece) {
        $piece = trim($piece);
        if ($piece !== '' && !in_array($piece, $out, true)) { $out[] = $piece; }
    }
    return $out;
}
