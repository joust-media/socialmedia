<?php
// Not a page: only meaningful when included from a page that loaded helpers.php.
if (!function_exists('esc')) { http_response_code(404); exit; }
/**
 * Full-screen media viewer (spec §4.2 "Viewer sheet"). Rendered hidden once per
 * page; static/js/assets.js drives it as App.viewer:
 *
 *   App.viewer.open(items, index, { mode: 'review'|'browse', onDecision, onClose })
 *     items[] = { id, kind: 'library'|'tire', status, src, type: 'image'|'video',
 *                 mime, label, download, endpoint, manage }
 *   App.viewer.close() / .next() / .prev() / .approve() / .deny(note) / .current()
 *   Events (bubble from the viewer root): 'viewer:decision' {item, status, prev,
 *   ok, rolledBack, error}, 'viewer:navigate' {item, index}, 'viewer:close'.
 *
 * Toolbar = exactly three controls: Deny (red, secondary) · Approve (green,
 * primary, ~60% width) · More (Download for everyone; Replace + "Manage in
 * Studio" for admin — rendered here only when the server says so).
 *
 * Deny opens the inline note ("What should change?", required, >= 3 chars); the
 * note is sent in the SAME request as status=denied to tire-status.php /
 * library-status.php, which enforce the minimum server-side too.
 *
 * Variables from the including scope (all optional, unset afterwards):
 *   $viewerId     default 'uiViewer'
 *   $viewerAdmin  bool — default isAdmin(); admin-only menu items are NOT rendered otherwise
 *   $viewerReplaceEndpoint  default basePath() . '/replace-image.php'
 */
$viewerId    = isset($viewerId) && $viewerId !== '' ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$viewerId) : 'uiViewer';
$viewerAdmin = isset($viewerAdmin) ? (bool)$viewerAdmin : (function_exists('isAdmin') && isAdmin());
$viewerReplaceEndpoint = isset($viewerReplaceEndpoint) ? (string)$viewerReplaceEndpoint : basePath() . '/replace-image.php';
?>
<div class="ui-viewer" id="<?= esc($viewerId) ?>" data-viewer hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Review image"
     data-replace-endpoint="<?= esc($viewerReplaceEndpoint) ?>">
  <header class="ui-viewer-top">
    <button type="button" class="ui-viewer-close" data-viewer-close aria-label="Close"><?= icon('xmark') ?></button>
    <div class="ui-viewer-heading">
      <div class="ui-viewer-title" data-viewer-title></div>
      <div class="ui-viewer-count" data-viewer-count aria-live="polite"></div>
    </div>
    <span class="ui-pill ui-pill--pending ui-viewer-status" data-viewer-status data-status-pill data-status="pending">To Review</span>
  </header>

  <div class="ui-viewer-stage" data-viewer-stage>
    <div class="ui-viewer-track" data-viewer-track></div>

    <button type="button" class="ui-viewer-arrow ui-viewer-arrow--prev" data-viewer-prev aria-label="Previous"><?= icon('arrow-left') ?></button>
    <button type="button" class="ui-viewer-arrow ui-viewer-arrow--next" data-viewer-next aria-label="Next"><?= icon('arrow-left') ?></button>

    <div class="ui-viewer-fallback" data-viewer-fallback hidden>
      <div class="ui-viewer-fallback-card">
        <p class="ui-viewer-fallback-title">Preview not supported in this browser</p>
        <p class="ui-viewer-fallback-text">This video plays in Safari on iPhone, iPad and Mac.</p>
        <div class="ui-btn-group">
          <a class="ui-btn ui-btn--filled" data-viewer-fallback-open href="#" target="_blank" rel="noopener">Open video</a>
          <button type="button" class="ui-btn ui-btn--gray" data-viewer-download>Download</button>
        </div>
      </div>
    </div>

    <button type="button" class="ui-viewer-done" data-viewer-done hidden>
      <span class="ui-viewer-done-ring"><?= icon('checkmark') ?></span>
      <span class="ui-viewer-done-title">All caught up</span>
      <span class="ui-viewer-done-text">Nothing left to review here.</span>
      <span class="ui-viewer-done-hint">Tap to close</span>
    </button>
  </div>

  <div class="ui-viewer-bar" data-viewer-bar>
    <div class="ui-viewer-actions" data-viewer-actions>
      <button type="button" class="ui-btn ui-btn--large ui-btn--deny ui-btn--tinted ui-viewer-deny" data-viewer-deny>
        <?= icon('xmark') ?><span data-viewer-deny-label>Deny</span>
      </button>
      <button type="button" class="ui-btn ui-btn--large ui-btn--approve ui-btn--primary ui-viewer-approve" data-viewer-approve>
        <?= icon('checkmark') ?><span data-viewer-approve-label>Approve</span>
      </button>
      <button type="button" class="ui-btn ui-btn--large ui-btn--gray ui-viewer-more" data-viewer-more aria-haspopup="menu" aria-expanded="false" aria-label="More">
        <?= icon('ellipsis') ?>
      </button>
    </div>

    <form class="ui-viewer-note" data-viewer-note hidden novalidate>
      <label class="ui-visually-hidden" for="<?= esc($viewerId) ?>Note">What should change?</label>
      <textarea class="ui-textarea ui-viewer-note-input" id="<?= esc($viewerId) ?>Note" data-viewer-note-input
                placeholder="What should change?" rows="2" minlength="3" maxlength="2000" required></textarea>
      <div class="ui-viewer-note-row">
        <p class="ui-viewer-note-hint" data-viewer-note-hint>A short note is required (at least 3 characters).</p>
        <button type="button" class="ui-btn ui-btn--gray" data-viewer-note-cancel>Cancel</button>
        <button type="submit" class="ui-btn ui-btn--deny" data-viewer-note-send disabled>Send &amp; deny</button>
      </div>
    </form>
  </div>

  <div class="ui-viewer-menu" data-viewer-menu role="menu" hidden>
    <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-download><?= icon('download') ?>Download</button>
    <?php if ($viewerAdmin): // admin-only: never rendered for clients ?>
      <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-replace data-tire-only><?= icon('photo') ?>Replace image…</button>
      <a class="ui-viewer-menu-item" role="menuitem" data-viewer-manage data-tire-only href="#"><?= icon('wand') ?>Manage in Studio</a>
    <?php endif; ?>
  </div>
  <?php if ($viewerAdmin): ?>
    <input type="file" class="ui-visually-hidden" data-viewer-replace-input accept="image/jpeg,image/png,image/gif,image/webp" tabindex="-1" aria-hidden="true">
  <?php endif; ?>
</div>
<?php unset($viewerId, $viewerAdmin, $viewerReplaceEndpoint); ?>
