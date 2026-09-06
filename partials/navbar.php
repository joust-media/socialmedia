<?php
// Not a page: only meaningful when included from a page that loaded helpers.php.
if (!function_exists('esc')) { http_response_code(404); exit; }
/**
 * Large-title navigation bar.
 *
 * Include at page top level (after helpers.php) or via renderAppChrome().
 * Reads from the including scope:
 *   $pageTitle    string      large title (required)
 *   $navSubtitle  string      small eyebrow above the title (optional)
 *   $navBack      array|null  ['href' => …, 'label' => …] → leading back button (optional)
 *   $navLeading   string      raw HTML for the leading slot (used when $navBack is not set)
 *   $navTrailing  string|null raw HTML for the top-right slot; null → client avatar; '' → nothing
 *   $navLinks     array       [['label' => …, 'href' => …, 'primary' => bool, 'attrs' => []], …] (optional)
 *   $navWide      bool        match a wide (1200px) content column
 *   $navWidth     string      exact content column to align with, e.g. '900px' (sets --content-w)
 *   $client       array|null  from helpers.php
 *
 * Client branding is limited to the avatar + display name; everything else is system gray/blue.
 */
$pageTitle   = isset($pageTitle) ? (string)$pageTitle : (($client['name'] ?? null) ?: 'Joust');
$navSubtitle = isset($navSubtitle) ? (string)$navSubtitle : '';
$navBack     = isset($navBack) && is_array($navBack) ? $navBack : null;
$navLeading  = isset($navLeading) ? (string)$navLeading : '';
$navLinks    = isset($navLinks) && is_array($navLinks) ? $navLinks : [];
$navWide     = !empty($navWide);
$navWidth    = isset($navWidth) && preg_match('/^\d{2,4}px$/', (string)$navWidth) ? (string)$navWidth : '';
if (!isset($navTrailing)) {
    $navTrailing = null;
}
if ($navTrailing === null) {
    $navTrailing = !empty($client) && function_exists('clientAvatar') ? clientAvatar($client) : '';
}
?>
<header class="ui-nav<?= $navWide ? ' ui-nav--wide' : '' ?>" role="banner"<?= $navWidth !== '' ? ' style="--content-w:' . esc($navWidth) . '"' : '' ?>>
  <div class="ui-nav-inner">
    <div class="ui-nav-row">
      <?php if ($navBack): ?>
        <div class="ui-nav-leading">
          <a class="ui-back" href="<?= esc($navBack['href'] ?? '#') ?>">
            <?= icon('arrow-left') ?><span><?= esc($navBack['label'] ?? 'Back') ?></span>
          </a>
        </div>
      <?php elseif ($navLeading !== ''): ?>
        <div class="ui-nav-leading"><?= $navLeading ?></div>
      <?php endif; ?>
      <div class="ui-nav-heading">
        <?php if ($navSubtitle !== ''): ?>
          <p class="ui-nav-eyebrow"><?= esc($navSubtitle) ?></p>
        <?php endif; ?>
        <h1 class="ui-nav-title"><?= esc($pageTitle) ?></h1>
      </div>
      <?php if ($navTrailing !== ''): ?>
        <div class="ui-nav-trailing"><?= $navTrailing ?></div>
      <?php endif; ?>
    </div>
    <?php if ($navLinks): ?>
      <nav class="ui-nav-links" aria-label="Page links">
        <?php foreach ($navLinks as $lnk):
          if (empty($lnk['label']) || empty($lnk['href'])) continue;
          $lcls  = 'ui-btn ui-btn--sm ' . (!empty($lnk['primary']) ? 'ui-btn--filled' : 'ui-btn--gray');
          if (!empty($lnk['class'])) $lcls .= ' ' . $lnk['class'];
          $lattr = '';
          foreach (($lnk['attrs'] ?? []) as $k => $v) {
              $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
              if ($k !== '') $lattr .= ' ' . $k . '="' . esc($v) . '"';
          }
        ?>
          <a class="<?= esc($lcls) ?>" href="<?= esc($lnk['href']) ?>"<?= $lattr ?>><?= esc($lnk['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </div>
</header>
