<?php
// Not a page: only meaningful when included from a page that loaded helpers.php.
if (!function_exists('esc')) { http_response_code(404); exit; }
/**
 * Generic sheet shell — bottom sheet on mobile, 480px right panel on desktop.
 * Rendered hidden; app.js opens it:
 *
 *   App.sheet.open('#uiSheet', { title: 'Warhawk', html: '…', footer: '…' })
 *   <button data-sheet-open="#uiSheet" data-sheet-title="…">…</button>
 *
 * Optional variables from the including scope (all unset afterwards so the
 * partial can be included more than once with different ids):
 *   $sheetId       default 'uiSheet'
 *   $sheetTitle    initial title text
 *   $sheetBody     initial body HTML
 *   $sheetFooter   initial sticky footer HTML (hidden when empty)
 *   $sheetLeading  HTML for the header's leading slot
 *   $sheetClass    extra classes on .ui-sheet (e.g. 'ui-sheet--full ui-sheet--dark')
 *   $sheetStatic   true → backdrop click does not close
 */
$sheetId      = isset($sheetId) && $sheetId !== '' ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$sheetId) : 'uiSheet';
$sheetTitle   = isset($sheetTitle) ? (string)$sheetTitle : '';
$sheetBody    = isset($sheetBody) ? (string)$sheetBody : '';
$sheetFooter  = isset($sheetFooter) ? (string)$sheetFooter : '';
$sheetLeading = isset($sheetLeading) ? (string)$sheetLeading : '';
$sheetClass   = isset($sheetClass) ? ' ' . (string)$sheetClass : '';
?>
<div class="ui-sheet-root" id="<?= esc($sheetId) ?>" aria-hidden="true"<?= !empty($sheetStatic) ? ' data-sheet-static' : '' ?>>
  <button type="button" class="ui-sheet-backdrop" tabindex="-1" aria-label="Close"></button>
  <div class="ui-sheet<?= esc($sheetClass) ?>" role="dialog" aria-modal="true" aria-labelledby="<?= esc($sheetId) ?>Title" tabindex="-1">
    <div class="ui-sheet-grabber" aria-hidden="true"></div>
    <header class="ui-sheet-header">
      <div class="ui-sheet-leading"><?= $sheetLeading ?></div>
      <h2 class="ui-sheet-title" id="<?= esc($sheetId) ?>Title" data-sheet-title><?= esc($sheetTitle) ?></h2>
      <button type="button" class="ui-sheet-close" data-sheet-close aria-label="Close"><?= icon('xmark') ?></button>
    </header>
    <div class="ui-sheet-body" data-sheet-body><?= $sheetBody ?></div>
    <footer class="ui-sheet-footer ui-glass ui-glass--top" data-sheet-footer<?= $sheetFooter === '' ? ' hidden' : '' ?>><?= $sheetFooter ?></footer>
  </div>
</div>
<?php unset($sheetId, $sheetTitle, $sheetBody, $sheetFooter, $sheetLeading, $sheetClass, $sheetStatic); ?>
