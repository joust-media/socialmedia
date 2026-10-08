<?php
// Not a page: only meaningful when included from a page that loaded helpers.php.
if (!function_exists('esc')) { http_response_code(404); exit; }
/**
 * Full-screen media viewer (spec §4.2 "Viewer sheet"). Rendered hidden once per
 * page; static/js/assets.js drives it as App.viewer:
 *
 *   App.viewer.open(items, index, { mode: 'review'|'browse', onDecision, onClose })
 *     items[] = { id, kind: 'library'|'tire', status, src, original, thumb, type: 'image'|'video',
 *                 mime, label, download, endpoint, manage }
 *     src = what the slide shows (the lg preview for images), original = the file itself
 *     (Download + the "View original" menu row), thumb = the sm preview (tile swaps).
 *   App.viewer.close() / .next() / .prev() / .approve() / .deny(note) / .current()
 *   Events (bubble from the viewer root): 'viewer:decision' {item, status, prev,
 *   ok, rolledBack, error}, 'viewer:navigate' {item, index}, 'viewer:close'.
 *
 * Toolbar = exactly three controls: Needs changes (red, secondary) · Approve (green,
 * primary, ~60% width) · More (Download for everyone — a blob save for images,
 * a direct `download` link for videos; Replace (tire + library images), "Mark for redo…" / "Remove from redo"
 * (redo-lib.php), "Move to tire…" (library images), "Set as
 * reference", "Manage in Studio" and "Delete image…" for admin — rendered here
 * only when the server says so; the two tire-only actions post set_reference /
 * delete_image to tire-status.php, which gates them again).
 *
 * Needs changes opens the inline note ("What should change?", required, >= 3 chars); the
 * note is sent in the SAME request as status=denied to tire-status.php /
 * library-status.php, which enforce the minimum server-side too.
 *
 * The client's own sent-back image (status denied — its "Sent back" list, sentback-lib.php): the bar reads
 * "Sent back · Joust is reworking this" (or "Being reworked"), the two buttons become Add a comment (opens the
 * Comments panel) · Approve instead, which asks first ([data-viewer-confirm], client seat only).
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
$viewerUploadEndpoint   = isset($viewerUploadEndpoint) ? (string)$viewerUploadEndpoint : basePath() . '/upload-chunk.php';   // purpose=replace: large replacements in pieces (chunk-upload.js)
$viewerCommentsEndpoint = isset($viewerCommentsEndpoint) ? (string)$viewerCommentsEndpoint : clientUrl('assets.php', ['partial' => 'comments']);
?>
<div class="ui-viewer" id="<?= esc($viewerId) ?>" data-viewer hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Review image"
     data-replace-endpoint="<?= esc($viewerReplaceEndpoint) ?>" data-upload-endpoint="<?= esc($viewerUploadEndpoint) ?>" data-comments-endpoint="<?= esc($viewerCommentsEndpoint) ?>">
  <header class="ui-viewer-top">
    <button type="button" class="ui-viewer-close" data-viewer-close aria-label="Close"><?= icon('xmark') ?></button>
    <div class="ui-viewer-heading">
      <div class="ui-viewer-title" data-viewer-title></div>
      <div class="ui-viewer-count" data-viewer-count aria-live="polite"></div>
    </div>
    <span class="ui-viewer-pills">
      <span class="ui-pill ui-pill--nodot ui-viewer-redo" data-viewer-redo-pill hidden><?= esc(function_exists('redoLabel') ? redoLabel($viewerAdmin) : 'Redo') ?></span>
      <span class="ui-pill ui-pill--pending ui-viewer-status" data-viewer-status data-status-pill data-status="pending">To Review</span>
    </span>
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
    <?php if (!$viewerAdmin): // the client's Sent back: what Joust is doing with this image (assets.js fills it) ?>
      <p class="ui-viewer-sentback" data-viewer-sentback hidden></p>
    <?php endif; ?>
    <div class="ui-viewer-actions" data-viewer-actions>
      <button type="button" class="ui-btn ui-btn--large ui-btn--deny ui-btn--tinted ui-viewer-deny" data-viewer-deny>
        <?= icon('xmark') ?><span data-viewer-deny-label>Needs changes</span>
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
        <button type="submit" class="ui-btn ui-btn--deny" data-viewer-note-send disabled>Send</button>
      </div>
    </form>

    <?php if (!$viewerAdmin): // Sent back → Approve instead asks first (the client's change of mind; assets.js) ?>
      <div class="ui-viewer-note ui-viewer-confirm" data-viewer-confirm role="alertdialog" aria-labelledby="<?= esc($viewerId) ?>ConfirmTitle" aria-describedby="<?= esc($viewerId) ?>ConfirmText" hidden>
        <p class="ui-viewer-confirm-title" id="<?= esc($viewerId) ?>ConfirmTitle">Approve this image instead?</p>
        <p class="ui-viewer-note-hint" id="<?= esc($viewerId) ?>ConfirmText">You sent it back for changes. Approving tells Joust to go ahead with it as it is — your note stays in the thread.</p>
        <div class="ui-viewer-note-row ui-viewer-confirm-row">
          <button type="button" class="ui-btn ui-btn--gray" data-viewer-confirm-cancel>Cancel</button>
          <button type="button" class="ui-btn ui-btn--approve ui-btn--primary" data-viewer-confirm-ok>Approve</button>
        </div>
      </div>
    <?php endif; ?>

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
    <?php // videos: a direct link (the browser streams it to disk — no blob copy of a multi-GB file in memory) ?>
    <a class="ui-viewer-menu-item" role="menuitem" data-viewer-download-link href="#" download hidden><?= icon('download') ?>Download video</a>
    <?php // images: the viewer shows the lg preview; this opens the untouched file (item.original) in a new tab ?>
    <a class="ui-viewer-menu-item" role="menuitem" data-viewer-original href="#" target="_blank" rel="noopener" hidden><?= icon('photo') ?>View original</a>
    <a class="ui-viewer-menu-item" role="menuitem" data-viewer-drive href="#" target="_blank" rel="noopener noreferrer" hidden><?= icon('drive') ?>Open series in Google Drive</a>
    <?php if ($viewerAdmin): // admin-only: never rendered for clients ?>
      <?php // approved items only (assets.js shows / hides it): opens the New post pop-up with this image as slide 1 ?>
      <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-use-in-post hidden><?= icon('plus') ?>Use in post</button>
      <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-replace><?= icon('photo') ?>Replace image…</button>
      <?php if (function_exists('redoReady') && redoReady($GLOBALS['pdo'] ?? null)): // the Redo queue (redo.php): assets.js shows one of the two ?>
        <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-redo hidden><?= icon('wand') ?>Mark for redo…</button>
        <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-unredo hidden><?= icon('checkmark') ?>Remove from redo</button>
      <?php endif; ?>
      <?php // Library images only (assets.js, when AssetsPage.move.on): into a tire series (library-move.php) ?>
      <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-move hidden><?= icon('tire') ?>Move to tire…</button>
      <button type="button" class="ui-viewer-menu-item" role="menuitem" data-viewer-set-reference data-tire-only><?= icon('checkmark') ?>Set as reference</button>
      <a class="ui-viewer-menu-item" role="menuitem" data-viewer-manage data-tire-only href="#"><?= icon('wand') ?>Edit tire…</a>
      <button type="button" class="ui-viewer-menu-item is-destructive" role="menuitem" data-viewer-delete data-tire-only><?= icon('xmark') ?>Delete image…</button>
    <?php endif; ?>
  </div>
  <?php if ($viewerAdmin): ?>
    <input type="file" class="ui-visually-hidden" data-viewer-replace-input accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov" tabindex="-1" aria-hidden="true">
  <?php endif; ?>
</div>
<?php unset($viewerId, $viewerAdmin, $viewerReplaceEndpoint, $viewerCommentsEndpoint); ?>
