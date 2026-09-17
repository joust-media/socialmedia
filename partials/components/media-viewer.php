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
 * primary, ~60% width) · More (Download for everyone; Replace, "Set as
 * reference", "Manage in Studio" and "Delete image…" for admin — rendered here
 * only when the server says so; the two tire-only actions post set_reference /
 * delete_image to tire-status.php, which gates them again).
 *
 * Deny opens the inline note ("What should change?", required, >= 3 chars); the
 * note is sent in the SAME request as status=denied to tire-status.php /
 * library-status.php, which enforce the minimum server-side too.
 *
 * Video slides are built by App.video.build() (the JS twin of
 * renderVideoElement(), spec §6): autoplay muted, tap-to-unmute pill, and the
 * "Open video / Download" card when the browser can't decode the file
 * (e.g. .mov in Chrome). The card lives inside the slide, not in this shell.
 *
 * Comments panel (below the actions, both seats, every status): "Comments (N)"
 * toggle → the image's thread (commentThreadHtml() markup, fetched lazily from
 * $viewerCommentsEndpoint + &kind=&id= when the panel opens or the image changes)
 * and a composer posting action=comment {id, comment} to the item's endpoint
 * (tire-status.php / library-status.php, which tenant-check and cap at 2000).
 * Enter sends on desktop (pointer: fine), the button on touch; ←/→ keep working
 * while the textarea is not focused.
 *
 * Variables from the including scope (all optional, unset afterwards):
 *   $viewerId     default 'uiViewer'
 *   $viewerAdmin  bool — default isAdmin(); admin-only menu items are NOT rendered otherwise
 *   $viewerReplaceEndpoint   default basePath() . '/replace-image.php'
 *   $viewerCommentsEndpoint  default clientUrl('assets.php', ['partial' => 'comments'])
 */
$viewerId    = isset($viewerId) && $viewerId !== '' ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$viewerId) : 'uiViewer';
$viewerAdmin = isset($viewerAdmin) ? (bool)$viewerAdmin : (function_exists('isAdmin') && isAdmin());
$viewerReplaceEndpoint  = isset($viewerReplaceEndpoint) ? (string)$viewerReplaceEndpoint : basePath() . '/replace-image.php';
$viewerCommentsEndpoint = isset($viewerCommentsEndpoint) ? (string)$viewerCommentsEndpoint : clientUrl('assets.php', ['partial' => 'comments']);
?>
<div class="ui-viewer" id="<?= esc($viewerId) ?>" data-viewer hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Review image"
     data-replace-endpoint="<?= esc($viewerReplaceEndpoint) ?>" data-comments-endpoint="<?= esc($viewerCommentsEndpoint) ?>">
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

    <section class="ui-viewer-comments" data-viewer-comments aria-label="Comments">
      <button type="button" class="ui-viewer-comments-toggle" data-viewer-comments-toggle aria-expanded="false" aria-controls="<?= esc($viewerId) ?>Comments">
        <?= icon('bubble') ?><span class="ui-viewer-comments-label">Comments</span>
        <span class="ui-viewer-comments-count" data-viewer-comments-count hidden>0</span>
        <?= icon('chevron-down', 'ui-viewer-comments-chevron') ?>
      </button>
      <div class="ui-viewer-comments-panel" id="<?= esc($viewerId) ?>Comments" data-viewer-comments-panel hidden>
        <div class="ui-viewer-thread" data-viewer-thread aria-live="polite"></div>
        <form class="ui-viewer-composer" data-viewer-comment-form novalidate>
          <label class="ui-visually-hidden" for="<?= esc($viewerId) ?>Comment">Add a comment</label>
          <textarea class="ui-textarea ui-viewer-composer-input" id="<?= esc($viewerId) ?>Comment" data-viewer-comment-input rows="1" maxlength="2000" placeholder="Add a comment…"></textarea>
          <button type="submit" class="ui-btn ui-btn--filled ui-btn--icon ui-viewer-composer-send" data-viewer-comment-send aria-label="Send" disabled>
            <svg class="ui-icon ui-icon--arrow-up" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V4.5"/><path d="m5 11.5 7-7 7 7"/></svg>
          </button>
        </form>
      </div>
    </section>
  </div>

  <div class="ui-viewer-menu" data-viewer-menu role="menu" hidden>
    <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-download><?= icon('download') ?>Download</button>
    <?php if ($viewerAdmin): // admin-only: never rendered for clients ?>
      <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-replace data-tire-only><?= icon('photo') ?>Replace image…</button>
      <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-set-reference data-tire-only><?= icon('checkmark') ?>Set as reference</button>
      <a class="ui-viewer-menu-item" role="menuitem" data-viewer-manage data-tire-only href="#"><?= icon('wand') ?>Manage in Studio</a>
      <button type="button" class="ui-viewer-menu-item is-destructive" role="menuitem" data-viewer-delete data-tire-only><?= icon('xmark') ?>Delete image…</button>
    <?php endif; ?>
  </div>
  <?php if ($viewerAdmin): ?>
    <input type="file" class="ui-visually-hidden" data-viewer-replace-input accept="image/jpeg,image/png,image/gif,image/webp" tabindex="-1" aria-hidden="true">
  <?php endif; ?>
</div>
<?php unset($viewerId, $viewerAdmin, $viewerReplaceEndpoint, $viewerCommentsEndpoint); ?>
