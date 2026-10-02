<?php
/**
 * iMessage-style comment thread (spec §4.3 item 4).
 *
 *   commentBubble(array $row): string
 *     $row = ['actor' => 'client'|'admin'|'unknown', 'detail' => text, 'created_at' => datetime]
 *     (the shape commentThread() in helpers.php returns — one activity_log 'commented' row).
 *     Client bubbles sit right in --accent, Joust (admin) bubbles sit left in gray.
 *
 *   commentThreadHtml(array $rows, array $opts = []): string
 *     Renders the whole thread: <div class="ui-thread pd-thread" data-thread>…</div>.
 *     $opts: 'empty' (text shown when there are no rows; '' → nothing),
 *            'attrs' (extra attributes on the wrapper), 'class' (extra classes).
 *
 *   commentComposer(int $postId, array $opts = []): string
 *     The pinned input row: textarea + Send button wired for static/js/posts.js
 *     ([data-comment-form], [data-comment-input], [data-comment-send]), plus the
 *     hidden timestamp chip ([data-video-stamp], spec §6) that App.video reveals
 *     when the surrounding detail contains a video; clicking it inserts "m:ss — "
 *     at the caret. $opts: 'placeholder', 'endpoint' (default 'status.php'),
 *     'entity' ('post'), 'stamp' (default true), 'slides' (slide count — ≥ 2 adds the
 *     optional [data-comment-slide] picker; the comment is stored as "[Slide N] text").
 *
 *   commentSlideSplit(string $text): [int $slide, string $rest] — parse the "[Slide N] " prefix (helpers.php).
 *   commentSlideChip(int $n, string $thumb): string — the chip a slide comment shows in the thread
 *     (commentThreadHtml($rows, ['slides' => pdSlideThumbs($images)])).
 *
 * Actor → side mapping is the only role logic here; whether a viewer may post
 * is decided by the including page (server-side), not by this partial.
 */
if (!function_exists('commentActorLabel')) {
    function commentActorLabel(string $actor): string
    {
        $a = strtolower(trim($actor));
        if ($a === 'admin')  return 'Joust';
        if ($a === 'client') return 'You';
        return 'Note';
    }
}

// commentSlideSplit() lives in helpers.php (the activity feed, digest and Home notes use it too).

if (!function_exists('commentSlideChip')) {
    /** The chip above a slide comment: the slide's sm thumb + "Slide N"; posts.js scrolls the carousel to it. */
    function commentSlideChip(int $n, string $thumb = ''): string
    {
        $esc = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        return '<button type="button" class="pd-slide-chip" data-goto-slide="' . ($n - 1) . '" aria-label="' . $esc('Show slide ' . $n) . '">'
             . ($thumb !== '' ? '<img src="' . $esc($thumb) . '" alt="" loading="lazy" decoding="async">' : '<span class="pd-slide-chip-blank" aria-hidden="true"></span>')
             . '<span>Slide ' . $n . '</span></button>';
    }
}

if (!function_exists('commentBubble')) {
    /** $opts['slides']: sm thumbs per slide (renderPostDetail) → "[Slide N] …" comments get a slide chip. */
    function commentBubble(array $row, array $opts = []): string
    {
        $esc   = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $actor = strtolower(trim((string)($row['actor'] ?? 'unknown')));
        $side  = $actor === 'client' ? 'client' : 'joust';
        $text  = (string)($row['detail'] ?? '');
        $chip  = '';
        if (isset($opts['slides']) && is_array($opts['slides'])) {
            [$slideNo, $rest] = commentSlideSplit($text);
            if ($slideNo > 0) { $chip = commentSlideChip($slideNo, (string)($opts['slides'][$slideNo - 1] ?? '')); $text = $rest; }
        }
        $when  = (string)($row['created_at'] ?? '');
        $rel   = function_exists('relativeTime') ? relativeTime($when) : '';
        $abs   = function_exists('absoluteTime') ? absoluteTime($when) : $when;

        // Actor avatar in the meta line: the Joust mark for admin bubbles, the client's logo /
        // initials for client bubbles (actorAvatar(), helpers.php; '' for 'unknown' notes).
        $avatar = function_exists('actorAvatar') ? actorAvatar($actor, $GLOBALS['client'] ?? null, 'ui-avatar--xs pd-msg-avatar') : '';

        $out  = '<div class="pd-msg pd-msg--' . $side . '" data-actor="' . $esc($actor) . '">';
        $out .= '<div class="ui-bubble ui-bubble--' . $side . '">' . $chip . nl2br($esc($text)) . '</div>';
        $out .= '<div class="ui-bubble-meta">' . $avatar . $esc(commentActorLabel($actor));
        if ($rel !== '') { $out .= ' · <time title="' . $esc($abs) . '">' . $esc($rel) . '</time>'; }
        $out .= '</div></div>';
        return $out;
    }
}

if (!function_exists('commentThreadHtml')) {
    function commentThreadHtml(array $rows, array $opts = []): string
    {
        $esc   = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $cls   = 'ui-thread pd-thread' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
        $attrs = ' data-thread data-count="' . count($rows) . '"';
        foreach (($opts['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . '="' . $esc($v) . '"';
        }
        $out = '<div class="' . $esc($cls) . '"' . $attrs . '>';
        foreach ($rows as $row) {
            if (trim((string)($row['detail'] ?? '')) === '') continue;
            $out .= commentBubble($row, isset($opts['slides']) ? ['slides' => (array)$opts['slides']] : []);
        }
        $empty = array_key_exists('empty', $opts) ? (string)$opts['empty'] : 'No messages yet.';
        if ($empty !== '') {
            $out .= '<p class="pd-thread-empty"' . ($rows ? ' hidden' : '') . ' data-thread-empty>' . $esc($empty) . '</p>';
        }
        return $out . '</div>';
    }
}

if (!function_exists('commentComposer')) {
    function commentComposer(int $postId, array $opts = []): string
    {
        $esc         = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $placeholder = $opts['placeholder'] ?? 'Message';
        $endpoint    = $opts['endpoint'] ?? 'status.php';
        $inputId     = 'comment-' . $postId;
        $stamp       = !array_key_exists('stamp', $opts) || $opts['stamp'];
        $nSlides     = (int)($opts['slides'] ?? 0);
        // ≥ 2 slides: an optional "Slide" picker; posts.js sends the comment as "[Slide N] text" (no schema change)
        $slidePick   = '';
        if ($nSlides >= 2) {
            $slidePick = '<label class="ui-visually-hidden" for="' . $esc($inputId) . '-slide">About slide</label>'
                       . '<select class="pd-composer-slide" id="' . $esc($inputId) . '-slide" data-comment-slide title="Comment on one slide">'
                       . '<option value="">All slides</option>';
            for ($i = 1; $i <= $nSlides; $i++) $slidePick .= '<option value="' . $i . '">Slide ' . $i . '</option>';
            $slidePick .= '</select>';
        }
        return '<form class="pd-composer' . ($slidePick !== '' ? ' pd-composer--slides' : '') . '" data-comment-form data-id="' . (int)$postId . '" data-endpoint="' . $esc($endpoint) . '" autocomplete="off">'
             . $slidePick
             . ($stamp
                 ? '<button type="button" class="ui-pill ui-pill--accent ui-pill--nodot pd-composer-stamp" data-video-stamp hidden title="Insert the current video time" aria-label="Insert the current video time">'
                   . (function_exists('icon') ? icon('play') : '') . '<span data-video-stamp-label>0:00</span></button>'
                 : '')
             . '<label class="ui-visually-hidden" for="' . $esc($inputId) . '">Message</label>'
             . '<textarea class="ui-textarea pd-composer-input" id="' . $esc($inputId) . '" data-comment-input rows="1" maxlength="2000" placeholder="' . $esc($placeholder) . '"></textarea>'
             . '<button type="submit" class="ui-btn ui-btn--filled ui-btn--icon pd-composer-send" data-comment-send aria-label="Send" disabled>'
             . '<svg class="ui-icon ui-icon--arrow-up" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V4.5"/><path d="m5 11.5 7-7 7 7"/></svg>'
             . '</button>'
             . '</form>';
    }
}
