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
 *     $opts: 'admin'     bool  — default isAdmin(). Admin-only markup is NEVER emitted otherwise.
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
 *     Paged carousel with dots; video through renderVideoElement() (spec §6:
 *     autoplay muted, tap-to-unmute pill, App.video fallback card).
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
        if ($copy) {
            $out .= '<button type="button" class="ui-btn ui-btn--plain ui-btn--sm ig-copy" data-copy-caption data-text="' . pdEsc($full) . '">Copy caption</button>';
        }
        return $out . '</section>';
    }
}

if (!function_exists('renderPostMedia')) {
    function renderPostMedia(array $images, array $opts = []): string
    {
        $images = array_slice(array_values($images), 0, 10);
        $n = count($images);
        if ($n === 0) {
            return '<div class="pd-media pd-media--empty"><span class="text-tertiary">No media yet</span></div>';
        }
        $label    = (string)($opts['label'] ?? 'Post media');
        $autoplay = !array_key_exists('autoplay', $opts) || $opts['autoplay'];
        $out  = '<div class="pd-media" data-carousel data-count="' . $n . '" aria-roledescription="carousel" aria-label="' . pdEsc($label) . '">';
        $out .= '<div class="pd-track" data-carousel-track>';
        foreach ($images as $i => $img) {
            $src  = pdMediaUrl((string)($img['url'] ?? ''));
            $ext  = strtolower(pathinfo((string)($img['url'] ?? ''), PATHINFO_EXTENSION));
            $id   = (int)($img['id'] ?? 0);
            $vid  = pdIsVideo($img);
            $out .= '<figure class="pd-slide" data-slide="' . $i . '" data-image-id="' . $id . '" data-media-type="' . ($vid ? 'video' : 'image') . '" data-src="' . pdEsc($src) . '" data-ext="' . pdEsc($ext) . '">';
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
                $out .= '<button type="button" class="pd-slide-btn" data-viewer-open aria-label="View full screen">'
                      . '<img src="' . pdEsc($src) . '" alt="' . pdEsc($label . ' ' . ($i + 1)) . '" loading="' . ($i === 0 ? 'eager' : 'lazy') . '" decoding="async">'
                      . '</button>';
            }
            $out .= '</figure>';
        }
        $out .= '</div>';
        if ($n > 1) {
            $out .= '<div class="pd-dots" role="tablist" aria-label="Slides">';
            for ($d = 0; $d < $n; $d++) {
                $out .= '<button type="button" class="pd-dot' . ($d === 0 ? ' is-active' : '') . '" data-carousel-dot="' . $d . '" role="tab" aria-selected="' . ($d === 0 ? 'true' : 'false') . '" aria-label="Slide ' . ($d + 1) . '"></button>';
            }
            $out .= '</div>';
            $out .= '<span class="ui-pill ui-pill--glass ui-pill--nodot pd-counter" data-carousel-counter>1/' . $n . '</span>';
        }
        return $out . '</div>';
    }
}

if (!function_exists('renderPostDetail')) {
    function renderPostDetail(array $post, array $opts = []): string
    {
        $admin     = array_key_exists('admin', $opts) ? (bool)$opts['admin'] : (function_exists('isAdmin') && isAdmin());
        $hasPosted = !array_key_exists('hasPosted', $opts) || $opts['hasPosted'];
        $endpoint  = pdMediaUrl((string)($opts['endpoint'] ?? 'status.php'));
        $replaceEp = pdMediaUrl('replace-image.php');

        $id       = (int)($post['id'] ?? 0);
        $status   = strtolower((string)($post['status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'approved', 'denied'], true)) $status = 'pending';
        $posted   = !empty($post['posted']);
        $images   = is_array($post['images'] ?? null) ? $post['images'] : [];
        $comments = is_array($post['comments'] ?? null) ? $post['comments'] : [];
        $when     = (string)($post['scheduled_date'] ?? '');
        $whenTs   = $when !== '' ? strtotime($when) : false;
        $brand    = ['name' => $post['company_name'] ?? '', 'logo_url' => $post['company_logo'] ?? ''];
        $typeLbl  = function_exists('postTypeLabel') ? postTypeLabel((string)($post['post_type'] ?? 'post')) : 'Post';

        // Client-facing status row copy (spec §4.3 item 5 / §9)
        $approvedAt = !empty($post['approved_at']) ? strtotime((string)$post['approved_at']) : false;
        $approvedLine = 'Approved' . ($approvedAt ? ' ' . date('M j', $approvedAt) : '') . ' · Joust will schedule this';

        $out  = '<article class="pd" data-post-detail="' . $id . '" data-id="' . $id . '" data-status="' . pdEsc($status) . '" data-posted="' . ($posted ? '1' : '0') . '" data-endpoint="' . pdEsc($endpoint) . '">';
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
                  . '<button type="button" role="menuitem" data-edit="caption">Edit caption</button>'
                  . '<button type="button" role="menuitem" data-edit="date">Edit date</button>'
                  . '<button type="button" role="menuitem" data-replace-image' . ($images ? '' : ' disabled') . '>Replace image</button>'
                  . '<button type="button" role="menuitem" class="is-destructive" data-delete-post>Delete</button>'
                  . '</div></div>';
        }
        $out .= '</div>';

        // ---- 1. Media carousel -------------------------------------------
        $out .= renderPostMedia($images, ['admin' => $admin, 'label' => (string)$brand['name'] . ' post']);
        if ($admin) {
            $out .= '<input type="file" class="ui-visually-hidden" data-replace-input accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov" tabindex="-1" data-replace-endpoint="' . pdEsc($replaceEp) . '">';
        }

        // ---- 2. Caption preview ----------------------------------------------
        $out .= renderCaptionPreview($post, $brand);
        if ($admin) {
            $out .= '<form class="pd-editor" data-edit-form="caption" hidden>'
                  . '<label class="pd-editor-label" for="pd-caption-' . $id . '">Caption</label>'
                  . '<textarea class="ui-textarea" id="pd-caption-' . $id . '" name="caption" maxlength="10000" required>' . pdEsc((string)($post['caption'] ?? '')) . '</textarea>'
                  . '<label class="pd-editor-label" for="pd-hashtags-' . $id . '">Hashtags</label>'
                  . '<textarea class="ui-textarea pd-editor-tags" id="pd-hashtags-' . $id . '" name="hashtags" maxlength="2000">' . pdEsc((string)($post['hashtags'] ?? '')) . '</textarea>'
                  . '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-edit-cancel>Cancel</button><button type="submit" class="ui-btn ui-btn--filled ui-btn--primary">Save</button></div>'
                  . '</form>';
        }

        // ---- 3. Scheduled date row -------------------------------------------
        $out .= '<div class="pd-when" data-when>';
        $out .= '<button type="button" class="pd-when-row" data-when-toggle aria-expanded="false">'
              . (function_exists('icon') ? icon('calendar', 'pd-when-icon') : '')
              . '<span class="pd-when-body"><span class="pd-when-label">' . ($posted ? 'Scheduled for' : 'Planned for') . '</span>'
              . '<span class="pd-when-date" data-when-display data-iso="' . pdEsc($whenTs ? date('Y-m-d\TH:i', $whenTs) : '') . '">' . pdEsc($whenTs ? pdFormatWhen($when) : 'Date to be confirmed') . '</span></span>'
              . '<span class="pd-when-cta">' . ($admin ? 'Edit' : 'Request a change') . '</span>'
              . '</button>';
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
        $out .= commentThreadHtml($comments, ['empty' => 'No messages yet — questions and change requests go here.']);
        $out .= '</section>';

        $out .= '</div>'; // /.pd-body

        // ---- 5. Sticky footer: composer + action bar / status row ------------
        $out .= '<div class="pd-footer" data-pd-footer>';
        $out .= commentComposer($id, ['endpoint' => $endpoint]);

        // Deny note (required, min 3) — the same form for client and admin
        $out .= '<form class="pd-deny" data-deny-form hidden>'
              . '<label class="pd-editor-label" for="pd-deny-' . $id . '">What should change?</label>'
              . '<textarea class="ui-textarea" id="pd-deny-' . $id . '" data-deny-note placeholder="What should change?" minlength="3" maxlength="2000" rows="2" required></textarea>'
              . '<p class="pd-editor-hint" data-deny-hint>A short note is required so Joust knows what to fix.</p>'
              . '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-deny-cancel>Cancel</button>'
              . '<button type="submit" class="ui-btn ui-btn--deny ui-btn--primary" data-deny-submit disabled>Send &amp; deny</button></div>'
              . '</form>';

        // State rows (all rendered; posts.js toggles [data-state] by data-status/data-posted)
        $out .= '<div class="pd-state pd-state--approved" data-state="approved"' . (($status === 'approved' && !$posted) ? '' : ' hidden') . '>'
              . (function_exists('icon') ? icon('checkmark') : '') . '<span data-approved-line>' . pdEsc($approvedLine) . '</span></div>';
        $out .= '<div class="pd-state pd-state--scheduled" data-state="scheduled"' . ($posted ? '' : ' hidden') . '>'
              . (function_exists('icon') ? icon('checkmark') : '') . '<span>Scheduled</span></div>';
        if ($admin) {
            $out .= '<div class="pd-state pd-state--denied" data-state="denied"' . (($status === 'denied' && !$posted) ? '' : ' hidden') . '>'
                  . (function_exists('icon') ? icon('xmark') : '') . '<span>Needs changes</span></div>';
        }

        // Action bar
        $out .= '<div class="pd-actions" data-actions>';
        // Deny · Approve — for pending (client + admin); admin also gets them on approved/denied to re-route work
        $out .= '<div class="ui-btn-group pd-decide" data-state="decide"' . (($status === 'pending' && !$posted) ? '' : ' hidden') . '>'
              . '<button type="button" class="ui-btn ui-btn--large ui-btn--deny ui-btn--tinted" data-decide="denied">Deny</button>'
              . '<button type="button" class="ui-btn ui-btn--large ui-btn--approve ui-btn--primary" data-decide="approved">Approve</button>'
              . '</div>';
        if ($admin) {
            // Approved + not scheduled: Needs changes · Mark Scheduled (primary)
            $out .= '<div class="ui-btn-group pd-admin-approved" data-state="admin-approved"' . (($status === 'approved' && !$posted) ? '' : ' hidden') . '>'
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--deny ui-btn--tinted" data-decide="denied">Needs changes</button>'
                  . ($hasPosted ? '<button type="button" class="ui-btn ui-btn--large ui-btn--filled ui-btn--primary" data-toggle-posted="1">Mark Scheduled</button>' : '')
                  . '</div>';
            // Denied: back to review · Approve
            $out .= '<div class="ui-btn-group pd-admin-denied" data-state="admin-denied"' . (($status === 'denied' && !$posted) ? '' : ' hidden') . '>'
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--gray" data-decide="pending">Back to review</button>'
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--approve ui-btn--primary" data-decide="approved">Approve</button>'
                  . '</div>';
            // Scheduled: Unmark
            if ($hasPosted) {
                $out .= '<div class="ui-btn-group pd-admin-scheduled" data-state="admin-scheduled"' . ($posted ? '' : ' hidden') . '>'
                      . '<button type="button" class="ui-btn ui-btn--gray" data-toggle-posted="0">Unmark Scheduled</button>'
                      . '</div>';
            }
        }
        $out .= '</div>'; // /.pd-actions
        $out .= '</div>'; // /.pd-footer
        $out .= '</article>';
        return $out;
    }
}
