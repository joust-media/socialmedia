<?php
/**
 * Post detail (spec §4.3 "Post detail") — the markup that fills the detail
 * sheet on posts.php, and the Instagram-style caption preview Studio reuses.
 *
 *   renderPostDetail(array $post, array $opts = []): string
 *     $post: id, caption, hashtags, scheduled_date, status, posted (0/1),
 *            post_type, company_name, company_logo, updated_at,
 *            images  => [['id' => 901, 'url' => 'uploads/x.jpg', 'type' => 'image'|'video'], …]
 *            comments => activity_log 'commented' rows [['actor','detail','created_at'], …]
 *            approved_at (optional datetime for the "Approved Sep 5" row)
 *            last_edit (optional ['actor','created_at'] of the newest edited_caption / edited_hashtags
 *                       row → "Edited by <client> · 5m ago" under the caption when actor = client)
 *     $opts: 'admin'     bool  — default isAdmin(). Admin-only markup (⋯ menu, date editor, Replace,
 *                        the admin footer rows, the Needs changes note banner) is NEVER emitted otherwise.
 *                        The caption / hashtags editor is shared by both seats (hidden once Scheduled).
 *     Footer, one primary per state — client: To Review → Needs changes · Approve. Admin (Joust's own next step):
 *       Draft → Edit post… · Send for review   To Review → Edit post…   Needs changes → Edit & resubmit
 *       Approved → Edit post… · Mark scheduled   Scheduled → Unmark scheduled
 *     The client's decisions reach the admin only through ⋯ (Approve for client… asks first, Needs changes…,
 *     Send for review on Needs changes). Needs changes pins the client's latest note above the media.
 *            'hasPosted' bool  — posts.posted exists (default true) → Mark Scheduled is offered
 *            'endpoint'  string — status endpoint (default 'status.php', resolved against basePath())
 *     Output: <article class="pd" data-post-detail="ID" data-status data-posted>
 *               <div class="pd-body" data-pd-body>…</div>
 *               <div class="pd-footer" data-pd-footer>…</div>
 *             </article>
 *     posts.js splits body/footer into the sheet's scroll area and sticky footer.
 *
 *   renderCaptionPreview(array $post, array $brand, array $opts = []): string
 *     Instagram caption preview: avatar + display name, caption with inline
 *     #hashtags in --accent, the hashtag block, and (unless 'copy' => false)
 *     the "Copy caption" ghost button. $brand = ['name' => …, 'logo_url' => …].
 *     Studio must render exactly this so what Lance sees is what the client sees.
 *
 *   renderPostMedia(array $images, array $opts = []): string
 *     Swipeable carousel (up to 20 slides; static/js/carousel.js): scroll-snap track, dots,
 *     "2 / 7" counter, prev / next arrows on hover-capable pointers, ←/→ on the focused track,
 *     lg previews, each slide carries data-thumb (sm); only the visible slide's video plays.
 *     Video through renderVideoElement() (spec §6: autoplay muted, tap-to-unmute pill, App.video
 *     fallback card).
 *   pdSlideThumbs(array $images): array — sm URL per slide ('' for video), for comment slide chips.
 *     $opts: 'admin' (adds nothing by itself — Replace lives in the ⋯ menu), 'label',
 *            'autoplay' (default true).
 *
 *   pdMediaUrl(string $url): string — root-rooted URL for an image_url value.
 *   pdVideoMime(string $ext): string — helpers' videoMime() (video/quicktime for mov).
 *   pdFormatWhen(string $dt): string — "Wednesday, Sep 2 · 10:35 PM" (America/New_York per db.php).
 */

if (!function_exists('pdEsc')) {
    function pdEsc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('pdMediaUrl')) {
    function pdMediaUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('#^(https?:)?//#i', $url) || $url[0] === '/') return $url;
        return (function_exists('basePath') ? basePath() : '') . '/' . ltrim($url, '/');
    }
}

if (!function_exists('pdVideoMime')) {
    function pdVideoMime(string $ext): string
    {
        $ext = strtolower($ext);
        if (function_exists('videoMime')) return videoMime($ext);
        if ($ext === 'mov') return 'video/quicktime';
        return $ext === 'webm' ? 'video/webm' : 'video/mp4';
    }
}

if (!function_exists('pdIsVideo')) {
    /** media_type when the column exists (authoritative), else by extension. */
    function pdIsVideo(array $img): bool
    {
        $type = strtolower((string)($img['type'] ?? ''));
        if ($type === 'video') return true;
        if ($type === 'image') return false;
        $ext = strtolower(pathinfo((string)($img['url'] ?? ''), PATHINFO_EXTENSION));
        if (function_exists('isVideoExt')) return isVideoExt($ext);
        return in_array($ext, ['mp4', 'webm', 'mov'], true);
    }
}

if (!function_exists('pdFormatWhen')) {
    function pdFormatWhen(string $dt): string
    {
        $ts = strtotime($dt);
        if (!$ts) return $dt;
        return date('l, M j', $ts) . ' · ' . date('g:i A', $ts);
    }
}

if (!function_exists('pdLinkHashtags')) {
    /** Escape text and wrap #tags in the accent span. */
    function pdLinkHashtags(string $text): string
    {
        $safe = pdEsc($text);
        return preg_replace('/(^|[\s(])(#[\p{L}\p{N}_]+)/u', '$1<span class="ig-tag">$2</span>', $safe);
    }
}

if (!function_exists('renderCaptionPreview')) {
    function renderCaptionPreview(array $post, array $brand, array $opts = []): string
    {
        $name    = (string)($brand['name'] ?? 'Joust Media');
        $caption = (string)($post['caption'] ?? '');
        $tags    = trim((string)($post['hashtags'] ?? ''));
        $copy    = !array_key_exists('copy', $opts) || $opts['copy'];
        $edit    = !empty($opts['edit']);            // "Edit caption" ghost button (posts.php sheet; Studio never passes it)
        $editOn  = !array_key_exists('editVisible', $opts) || $opts['editVisible'];   // false → rendered hidden (post is Scheduled)
        $full    = trim($caption . ($tags !== '' ? "\n\n" . $tags : ''));

        $avatar = function_exists('clientAvatar')
            ? clientAvatar(['name' => $name, 'logo_url' => $brand['logo_url'] ?? ''], 'ui-avatar--sm ig-avatar')
            : '<span class="ui-avatar ui-avatar--sm ig-avatar"></span>';

        $out  = '<section class="ig" data-caption-preview>';
        $out .= '<header class="ig-head">' . $avatar . '<span class="ig-name">' . pdEsc($name) . '</span></header>';
        $out .= '<div class="ig-caption" data-caption-display data-raw="' . pdEsc($caption) . '">'
              . '<span class="ig-name ig-name--inline">' . pdEsc($name) . '</span> '
              . nl2br(pdLinkHashtags($caption)) . '</div>';
        $out .= '<div class="ig-tags" data-hashtags-display data-raw="' . pdEsc($tags) . '"' . ($tags === '' ? ' hidden' : '') . '>'
              . pdLinkHashtags($tags) . '</div>';
        if ($copy || $edit) {
            $out .= '<div class="ig-actions">';
            if ($copy) {
                $out .= '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm ig-copy" data-copy-caption data-text="' . pdEsc($full) . '">Copy caption</button>';
            }
            if ($edit) {
                $out .= '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm ig-edit" data-edit="caption" data-caption-edit' . ($editOn ? '' : ' hidden') . '>Edit caption</button>';
            }
            $out .= '</div>';
        }
        return $out . '</section>';
    }
}

if (!function_exists('pdSlideThumbs')) {
    /** The sm preview URL of every slide ('' for a video) — the comment thread's "Slide 3" chips. */
    function pdSlideThumbs(array $images): array
    {
        $out = [];
        foreach (array_values($images) as $img) {
            $src = pdMediaUrl((string)($img['url'] ?? ''));
            $out[] = pdIsVideo($img) ? '' : (function_exists('pvUrl') ? pvUrl($src, 'sm') : $src);
        }
        return $out;
    }
}

if (!function_exists('renderPostMedia')) {
    function renderPostMedia(array $images, array $opts = []): string
    {
        $images = array_slice(array_values($images), 0, defined('POST_MAX_MEDIA') ? POST_MAX_MEDIA : 20);   // Instagram's carousel cap
        $n = count($images);
        if ($n === 0) {
            return '<div class="pd-media pd-media--empty"><span class="text-tertiary">No media yet</span></div>';
        }
        $label    = (string)($opts['label'] ?? 'Post media');
        $autoplay = !array_key_exists('autoplay', $opts) || $opts['autoplay'];
        // Swipeable carousel (static/js/carousel.js — App.carousel): scroll-snap track, dots, "2 / 7", arrows on
        // hover-capable pointers, ←/→ on the focused track; only the visible slide's video plays.
        $out  = '<div class="pd-media" data-carousel data-count="' . $n . '" data-autoplay="' . ($autoplay ? '1' : '0') . '" aria-roledescription="carousel" aria-label="' . pdEsc($label) . '">';
        $out .= '<div class="pd-track" data-carousel-track tabindex="0">';
        foreach ($images as $i => $img) {
            $src  = pdMediaUrl((string)($img['url'] ?? ''));
            $ext  = strtolower(pathinfo((string)($img['url'] ?? ''), PATHINFO_EXTENSION));
            $id   = (int)($img['id'] ?? 0);
            $vid  = pdIsVideo($img);
            $thumb = $vid ? '' : (function_exists('pvUrl') ? pvUrl($src, 'sm') : $src);
            $out .= '<figure class="pd-slide" data-slide="' . $i . '" data-image-id="' . $id . '" data-media-type="' . ($vid ? 'video' : 'image') . '" data-src="' . pdEsc($src) . '" data-ext="' . pdEsc($ext) . '" data-thumb="' . pdEsc($thumb) . '"'
                  . ' aria-roledescription="slide" aria-label="' . ($i + 1) . ' of ' . $n . '"' . ($i > 0 ? ' aria-hidden="true"' : '') . '>';
            if ($vid) {
                // spec §6 markup (playsinline muted controls preload=metadata, quicktime source first,
                // mp4 twin when on disk, fallback card) — one renderer for the whole portal.
                $out .= renderVideoElement($src, [
                    'autoplay' => $autoplay && $i === 0,
                    'unmute'   => true,
                    'label'    => $label . ' ' . ($i + 1),
                    'class'    => 'pd-video',
                ]);
            } else {
                // The lg preview (srcset / sizes / width / height, preview-ui.php); the full-screen view keeps "View original" → the file
                $img = function_exists('pvImg')
                    ? pvImg($src, 'lg', ['sizes' => pvSizes('slide'), 'eager' => $i === 0, 'alt' => $label . ' ' . ($i + 1)])
                    : '<img src="' . pdEsc($src) . '" alt="' . pdEsc($label . ' ' . ($i + 1)) . '" loading="' . ($i === 0 ? 'eager' : 'lazy') . '" decoding="async">';
                $out .= '<button type="button" class="pd-slide-btn" data-viewer-open data-original="' . pdEsc($src) . '" aria-label="View slide ' . ($i + 1) . ' full screen">' . $img . '</button>';
            }
            $out .= '</figure>';
        }
        $out .= '</div>';
        if ($n > 1) {
            $chev = static function (string $d): string {
                return '<svg class="ui-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
            };
            $out .= '<button type="button" class="pd-arrow pd-arrow--prev" data-carousel-prev aria-label="Previous slide" disabled>' . $chev('m15 5-7 7 7 7') . '</button>';
            $out .= '<button type="button" class="pd-arrow pd-arrow--next" data-carousel-next aria-label="Next slide">' . $chev('m9 5 7 7-7 7') . '</button>';
            $out .= '<div class="pd-dots" role="tablist" aria-label="Slides">';
            for ($d = 0; $d < $n; $d++) {
                $out .= '<button type="button" class="pd-dot' . ($d === 0 ? ' is-active' : '') . '" data-carousel-dot="' . $d . '" role="tab" aria-selected="' . ($d === 0 ? 'true' : 'false') . '" aria-label="Slide ' . ($d + 1) . '"' . ($d === 0 ? '' : ' tabindex="-1"') . '></button>';
            }
            $out .= '</div>';
            $out .= '<span class="ui-pill ui-pill--glass ui-pill--nodot pd-counter" data-carousel-counter aria-hidden="true">1 / ' . $n . '</span>';
        }
        return $out . '</div>';
    }
}

require_once __DIR__ . '/review-actions.php';   // reviewLatestNote() / reviewNoteBanner(): the Needs changes note on top

if (!function_exists('renderPostDetail')) {
    function renderPostDetail(array $post, array $opts = []): string
    {
        $admin     = array_key_exists('admin', $opts) ? (bool)$opts['admin'] : (function_exists('isAdmin') && isAdmin());
        $hasPosted = !array_key_exists('hasPosted', $opts) || $opts['hasPosted'];
        $endpoint  = pdMediaUrl((string)($opts['endpoint'] ?? 'status.php'));
        $replaceEp = pdMediaUrl('replace-image.php');

        $id       = (int)($post['id'] ?? 0);
        $status   = strtolower((string)($post['status'] ?? 'pending'));
        if (!in_array($status, ['draft', 'pending', 'approved', 'denied'], true)) $status = 'pending';
        if ($status === 'draft' && !$admin) $status = 'pending';   // never reached (posts.php filters drafts for clients in SQL)
        $posted   = !empty($post['posted']);
        $images   = is_array($post['images'] ?? null) ? $post['images'] : [];
        $comments = is_array($post['comments'] ?? null) ? $post['comments'] : [];
        $when     = (string)($post['scheduled_date'] ?? '');
        $whenTs   = $when !== '' ? strtotime($when) : false;
        // Date-only comparison (server local date): a post scheduled for later today is not past.
        $datePast = $whenTs && date('Y-m-d', $whenTs) < date('Y-m-d');
        $isPast   = $posted && $datePast;   // presentational only — posts.js re-evaluates on Mark/Unmark
        $brand    = ['name' => $post['company_name'] ?? '', 'logo_url' => $post['company_logo'] ?? ''];
        $typeLbl  = function_exists('postTypeLabel') ? postTypeLabel((string)($post['post_type'] ?? 'post')) : 'Post';

        // Client-facing status row copy (spec §4.3 item 5 / §9)
        $approvedAt = !empty($post['approved_at']) ? strtotime((string)$post['approved_at']) : false;
        $approvedLine = 'Approved' . ($approvedAt ? ' ' . date('M j', $approvedAt) : '') . ' · Joust will schedule this';

        // Admin ⋯ "For the client" group (posts.js syncState re-evaluates the same rules after every change)
        $isDenied   = $status === 'denied' && !$posted;
        $canApprove = !$posted && in_array($status, ['pending', 'denied'], true);
        $canDeny    = !$posted && in_array($status, ['pending', 'approved'], true);
        $menuDecide = $canApprove || $canDeny || $isDenied;
        $clientName = trim((string)($post['company_name'] ?? '')) !== '' ? (string)$post['company_name'] : 'the client';

        $out  = '<article class="pd" data-post-detail="' . $id . '" data-id="' . $id . '" data-status="' . pdEsc($status) . '" data-posted="' . ($posted ? '1' : '0') . '" data-past="' . ($datePast ? '1' : '0') . '" data-endpoint="' . pdEsc($endpoint) . '">';
        $out .= '<div class="pd-body" data-pd-body>';

        // ---- Top meta row: type · status pill · (admin) ⋯ menu -------------
        $out .= '<div class="pd-meta">';
        $out .= '<span class="pd-type">' . pdEsc($typeLbl) . '</span>';
        $out .= function_exists('statusPill') ? statusPill($status, $posted, ['class' => 'pd-pill']) : '';
        if (!empty($post['updated_at']) && function_exists('relativeTime')) {
            $out .= '<span class="pd-edited text-tertiary" title="' . pdEsc(absoluteTime($post['updated_at'])) . '">edited ' . pdEsc(relativeTime($post['updated_at'])) . '</span>';
        }
        if ($admin) {
            $out .= '<div class="pd-more">'
                  . '<button type="button" class="ui-btn ui-btn--gray ui-btn--icon ui-btn--sm" data-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="More actions">' . (function_exists('icon') ? icon('ellipsis') : '&hellip;') . '</button>'
                  . '<div class="pd-menu" role="menu" data-menu hidden>'
                  // Full editor (media add / remove / reorder / replace, caption, date, type): the New post pop-up in edit mode (newpost.js)
                  . '<button type="button" role="menuitem" data-newpost-edit="' . $id . '">Edit post…</button>'
                  . '<button type="button" role="menuitem" data-edit="caption" data-caption-menu' . ($posted ? ' disabled title="Unmark scheduled first"' : '') . '>Edit caption</button>'
                  . '<button type="button" role="menuitem" data-edit="date">Edit date</button>'
                  . '<button type="button" role="menuitem" data-replace-image' . ($images ? '' : ' disabled') . '>Replace image</button>'
                  // Every file of the post, saved one by one (posts.js) — what Classic admin's "Save" button did
                  . '<button type="button" role="menuitem" data-download-media' . ($images ? '' : ' disabled') . '>Download media</button>'
                  // The client's decisions, taken on their behalf only on purpose (never a footer button for the admin):
                  // Approve for client… asks first; Needs changes… opens the note; Send for review resubmits as is.
                  . '<div class="pd-menu-group" role="group" aria-label="For the client" data-state="menu-decide"' . ($menuDecide ? '' : ' hidden') . '>'
                  . '<div class="pd-menu-sep" role="separator"></div>'
                  . '<button type="button" role="menuitem" data-approve-for-client data-state="menu-approve"' . ($canApprove ? '' : ' hidden') . '>Approve for client…</button>'
                  . '<button type="button" role="menuitem" data-decide="denied" data-state="menu-deny"' . ($canDeny ? '' : ' hidden') . '>Needs changes…</button>'
                  . '<button type="button" role="menuitem" data-decide="pending" data-state="menu-resubmit"' . ($isDenied ? '' : ' hidden') . '>Send for review</button>'
                  . '</div>'
                  . '<div class="pd-menu-sep" role="separator"></div>'
                  . '<button type="button" role="menuitem" class="is-destructive" data-delete-post>Delete</button>'
                  . '</div></div>';
        }
        $out .= '</div>';

        // ---- 0. Needs changes (admin): the client's note first, above the media ----------------
        if ($admin) {
            $out .= reviewNoteBanner(reviewLatestNote($comments, (string)$brand['name']), $isDenied);
        }

        // ---- 1. Media carousel -------------------------------------------
        $out .= renderPostMedia($images, ['admin' => $admin, 'label' => (string)$brand['name'] . ' post']);
        if ($admin) {
            $out .= '<input type="file" class="ui-visually-hidden" data-replace-input accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov" tabindex="-1" data-replace-endpoint="' . pdEsc($replaceEp) . '" data-upload-endpoint="' . pdEsc(pdMediaUrl('upload-chunk.php')) . '">';   // upload-chunk.php purpose=replace: large replacements in pieces
        }

        // ---- 2. Caption preview ----------------------------------------------
        // Caption + hashtags are editable by BOTH seats until the post is Scheduled
        // (status.php answers 409 once posted = 1). The same editor serves admin
        // (also reachable from the ⋯ menu) and client: Edit → textareas with
        // counters → Save / Cancel. The Edit button is rendered hidden when posted;
        // posts.js follows data-posted after Mark / Unmark Scheduled.
        $out .= renderCaptionPreview($post, $brand, ['edit' => true, 'editVisible' => !$posted]);
        // "Edited by Kenda · 5m ago" — only when the latest copy edit came from the client seat.
        // Always in the DOM (hidden otherwise) so posts.js can fill it after a client save.
        $lastEdit   = is_array($post['last_edit'] ?? null) ? $post['last_edit'] : null;
        $clientEdit = $lastEdit && (($lastEdit['actor'] ?? '') === 'client');
        $editWho    = trim((string)$brand['name']) !== '' ? (string)$brand['name'] : 'the client';
        $editWhen   = $clientEdit && function_exists('relativeTime') ? relativeTime($lastEdit['created_at'] ?? null) : '';
        $out .= '<p class="pd-edited-by text-tertiary" data-edited-by' . ($clientEdit ? '' : ' hidden') . '>'
              . ($clientEdit ? 'Edited by ' . pdEsc($editWho) . ($editWhen !== '' ? ' · ' . pdEsc($editWhen) : '') : '')
              . '</p>';
        $capLen = function_exists('mb_strlen') ? mb_strlen((string)($post['caption'] ?? '')) : strlen((string)($post['caption'] ?? ''));
        $tagLen = function_exists('mb_strlen') ? mb_strlen((string)($post['hashtags'] ?? '')) : strlen((string)($post['hashtags'] ?? ''));
        $out .= '<form class="pd-editor" data-edit-form="caption" hidden>'
              . '<label class="pd-editor-label" for="pd-caption-' . $id . '">Caption</label>'
              . '<textarea class="ui-textarea" id="pd-caption-' . $id . '" name="caption" maxlength="10000" required>' . pdEsc((string)($post['caption'] ?? '')) . '</textarea>'
              . '<span class="pd-editor-count" data-count-for="pd-caption-' . $id . '" aria-live="polite">' . $capLen . ' / 10000</span>'
              . '<label class="pd-editor-label" for="pd-hashtags-' . $id . '">Hashtags</label>'
              . '<textarea class="ui-textarea pd-editor-tags" id="pd-hashtags-' . $id . '" name="hashtags" maxlength="2000">' . pdEsc((string)($post['hashtags'] ?? '')) . '</textarea>'
              . '<span class="pd-editor-count" data-count-for="pd-hashtags-' . $id . '" aria-live="polite">' . $tagLen . ' / 2000</span>'
              . ($admin ? '' : '<p class="pd-editor-hint">Joust will see this change in the activity feed.</p>')
              . '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-edit-cancel>Cancel</button><button type="submit" class="ui-btn ui-btn--filled ui-btn--primary">Save</button></div>'
              . '</form>';

        // ---- 3. Scheduled date row -------------------------------------------
        $out .= '<div class="pd-when" data-when>';
        $out .= '<button type="button" class="pd-when-row" data-when-toggle aria-expanded="false">'
              . (function_exists('icon') ? icon('calendar', 'pd-when-icon') : '')
              . '<span class="pd-when-body"><span class="pd-when-label">' . ($posted ? 'Scheduled for' : 'Planned for') . '</span>'
              . '<span class="pd-when-date" data-when-display data-iso="' . pdEsc($whenTs ? date('Y-m-d\TH:i', $whenTs) : '') . '">' . pdEsc($whenTs ? pdFormatWhen($when) : 'Date to be confirmed') . '</span></span>'
              . '<span class="pd-when-cta">' . ($admin ? 'Edit' : 'Request a change') . '</span>'
              . '</button>';
        // Muted note for Scheduled posts whose date has passed (hidden otherwise; posts.js toggles it)
        $out .= '<p class="pd-when-past text-tertiary" data-when-past' . ($isPast ? '' : ' hidden') . '>This post\'s date has passed.</p>';
        if ($admin) {
            $out .= '<form class="pd-editor" data-edit-form="date" hidden>'
                  . '<label class="pd-editor-label" for="pd-date-' . $id . '">Scheduled date</label>'
                  . '<input class="ui-input" type="datetime-local" id="pd-date-' . $id . '" name="scheduled_date" value="' . pdEsc($whenTs ? date('Y-m-d\TH:i', $whenTs) : '') . '" required>'
                  . '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-edit-cancel>Cancel</button><button type="submit" class="ui-btn ui-btn--filled ui-btn--primary">Save</button></div>'
                  . '</form>';
        } else {
            $out .= '<form class="pd-editor" data-request-date hidden>'
                  . '<label class="pd-editor-label" for="pd-req-' . $id . '">Move this post to</label>'
                  . '<input class="ui-input" type="date" id="pd-req-' . $id . '" name="date" value="' . pdEsc($whenTs ? date('Y-m-d', $whenTs) : '') . '" required>'
                  . '<p class="pd-editor-hint">Joust will see this as a comment on the post.</p>'
                  . '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-edit-cancel>Cancel</button><button type="submit" class="ui-btn ui-btn--filled ui-btn--primary">Send request</button></div>'
                  . '</form>';
        }
        $out .= '</div>';

        // ---- 4. Comments thread ------------------------------------------------
        $out .= '<section class="pd-comments"><h3 class="pd-section-title">Comments <span class="pd-comment-count text-tertiary" data-comment-count>' . count($comments) . '</span></h3>';
        // "[Slide 3] …" comments render a slide chip (thumb + "Slide 3"; tap → the carousel goes there)
        $out .= commentThreadHtml($comments, ['empty' => 'No messages yet — questions and change requests go here.', 'slides' => pdSlideThumbs($images)]);
        $out .= '</section>';

        $out .= '</div>'; // /.pd-body

        // ---- 5. Sticky footer: composer + action bar / status row ------------
        $out .= '<div class="pd-footer" data-pd-footer>';
        $out .= commentComposer($id, ['endpoint' => $endpoint, 'slides' => count(array_slice($images, 0, defined('POST_MAX_MEDIA') ? POST_MAX_MEDIA : 20))]);   // ≥ 2 slides → the "Slide" picker

        // Needs changes note (required, min 3) — the client's button; the admin reaches it from ⋯ → Needs changes…
        $out .= '<form class="pd-deny" data-deny-form hidden>'
              . '<label class="pd-editor-label" for="pd-deny-' . $id . '">What should change?</label>'
              . '<textarea class="ui-textarea" id="pd-deny-' . $id . '" data-deny-note placeholder="What should change?" minlength="3" maxlength="2000" rows="2" required></textarea>'
              . '<p class="pd-editor-hint" data-deny-hint>A short note is required so Joust knows what to fix.</p>'
              . '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-deny-cancel>Cancel</button>'
              . '<button type="submit" class="ui-btn ui-btn--deny ui-btn--primary" data-deny-submit disabled>Send</button></div>'
              . '</form>';

        // State rows (all rendered; posts.js toggles [data-state] by data-status/data-posted)
        $out .= '<div class="pd-state pd-state--approved" data-state="approved"' . (($status === 'approved' && !$posted) ? '' : ' hidden') . '>'
              . (function_exists('icon') ? icon('checkmark') : '') . '<span data-approved-line>' . pdEsc($approvedLine) . '</span></div>';
        $out .= '<div class="pd-state pd-state--scheduled" data-state="scheduled"' . ($posted ? '' : ' hidden') . '>'
              . (function_exists('icon') ? icon('checkmark') : '') . '<span>Scheduled</span></div>';
        if ($admin) {
            $out .= '<div class="pd-state pd-state--draft" data-state="draft"' . ($status === 'draft' ? '' : ' hidden') . '>'
                  . '<span>Draft — the client can\'t see this yet</span></div>';
            $out .= '<div class="pd-state pd-state--pending" data-state="admin-waiting"' . (($status === 'pending' && !$posted) ? '' : ' hidden') . '>'
                  . '<span>Waiting on ' . pdEsc($clientName) . ' to review</span></div>';
        }

        // Action bar — one primary per state.
        //   Client: To Review → Needs changes · Approve.
        //   Admin (Joust's own next step; the client's decisions live in ⋯):
        //     Draft → Edit post… · Send for review      To Review → Edit post…
        //     Needs changes → Edit & resubmit (the New post pop-up in edit mode; its primary resubmits)
        //     Approved → Edit post… · Mark scheduled     Scheduled → Unmark scheduled
        $out .= '<div class="pd-actions" data-actions>';
        if (!$admin) {
            $out .= '<div class="ui-btn-group pd-decide" data-state="decide"' . (($status === 'pending' && !$posted) ? '' : ' hidden') . '>'
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--deny ui-btn--tinted" data-decide="denied">Needs changes</button>'
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--approve ui-btn--primary" data-decide="approved">Approve</button>'
                  . '</div>';
        } else {
            $editBtn = static function (string $cls, string $label = 'Edit post…', string $extra = '') use ($id) {
                return '<button type="button" class="ui-btn ui-btn--large ' . $cls . '" data-newpost-edit="' . $id . '"' . $extra . '>' . $label . '</button>';
            };
            $out .= '<div class="ui-btn-group pd-admin-draft" data-state="admin-draft"' . ($status === 'draft' ? '' : ' hidden') . '>'
                  . $editBtn('ui-btn--gray')
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--filled ui-btn--primary" data-submit-post="' . $id . '">Send for review</button>'
                  . '</div>';
            $out .= '<div class="ui-btn-group pd-admin-pending" data-state="admin-pending"' . (($status === 'pending' && !$posted) ? '' : ' hidden') . '>'
                  . $editBtn('ui-btn--filled ui-btn--primary')
                  . '</div>';
            $out .= '<div class="ui-btn-group pd-admin-denied" data-state="admin-denied"' . ($isDenied ? '' : ' hidden') . '>'
                  . $editBtn('ui-btn--filled ui-btn--primary', 'Edit &amp; resubmit', ' data-newpost-resubmit')
                  . '</div>';
            $out .= '<div class="ui-btn-group pd-admin-approved" data-state="admin-approved"' . (($status === 'approved' && !$posted) ? '' : ' hidden') . '>'
                  . $editBtn($hasPosted ? 'ui-btn--gray' : 'ui-btn--filled ui-btn--primary')
                  . ($hasPosted ? '<button type="button" class="ui-btn ui-btn--large ui-btn--filled ui-btn--primary" data-toggle-posted="1">Mark scheduled</button>' : '')
                  . '</div>';
            if ($hasPosted) {
                $out .= '<div class="ui-btn-group pd-admin-scheduled" data-state="admin-scheduled"' . ($posted ? '' : ' hidden') . '>'
                      . '<button type="button" class="ui-btn ui-btn--large ui-btn--gray" data-toggle-posted="0">Unmark scheduled</button>'
                      . '</div>';
            }
        }
        $out .= '</div>'; // /.pd-actions
        $out .= '</div>'; // /.pd-footer
        $out .= '</article>';
        return $out;
    }
}
