<?php
/**
 * iMessage-style comment thread (spec §4.3 item 4).
 *
 *   commentBubble(array $row): string
 *     $row = ['actor' => 'client'|'admin'|'unknown', 'detail' => text, 'created_at' => datetime]
 *     (the shape commentThread() in helpers.php returns — one activity_log 'commented' row).
 *     Drawn from the VIEWER's side (commentViewerRole(): the admin seat or the client seat): the viewer's own
 *     bubbles sit right in --accent as "You" (.pd-msg--mine); the other party's sit left in gray, named —
 *     "Joust" for the client, the client's name for the admin (.pd-msg--theirs). $opts['viewer'] overrides.
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
 *     optional [data-comment-slide] picker; the comment is stored as "[Slide N] text"), 'internal' (default: the
 *     admin seat) — the "Internal (Joust only)" switch ([data-comment-internal]; sent as internal=1).
 *
 *   commentSlideSplit(string $text): [int $slide, string $rest] — parse the "[Slide N] " prefix (helpers.php).
 *   commentSlideChip(int $n, string $thumb): string — the chip a slide comment shows in the thread
 *     (commentThreadHtml($rows, ['slides' => pdSlideThumbs($images)])).
 *
 * Actor → side mapping (relative to the viewer) is the only role logic here; whether a viewer may post
 * is decided by the including page (server-side), not by this partial. static/js/app.js App.bubbleWho()
 * mirrors it for freshly sent bubbles.
 */
if (!function_exists('commentViewerRole')) {
    /** Whose bubbles are "mine" on this request: 'admin' (Joust seat) or 'client'. */
    function commentViewerRole(): string
    {
        return function_exists('isAdmin') && isAdmin() ? 'admin' : 'client';
    }
}

if (!function_exists('commentActorLabel')) {
    /** "You" for the viewer's own messages; otherwise "Joust" / the client's name / "Note". */
    function commentActorLabel(string $actor, ?string $viewer = null, $client = null): string
    {
        $a = strtolower(trim($actor));
        $viewer = $viewer ?? commentViewerRole();
        if (($a === 'admin' || $a === 'client') && $a === $viewer) return 'You';
        if ($a === 'admin')  return 'Joust';
        if ($a === 'client') {
            $client = $client ?? ($GLOBALS['client'] ?? null);
            $name = is_array($client) ? trim((string)($client['name'] ?? '')) : '';
            return $name !== '' ? $name : 'Client';
        }
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

if (!function_exists('commentMoreButton')) {
    /** The unobtrusive ⋯ on an editable comment (static/js/app.js App.comments opens Edit / Delete / History). */
    function commentMoreButton(): string
    {
        return '<button type="button" class="pd-msg-more" data-comment-more aria-haspopup="menu" aria-expanded="false" aria-label="Comment options" title="Comment options">'
             . '<svg class="ui-icon" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="currentColor">'
             . '<circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg></button>';
    }
}

if (!function_exists('commentBubble')) {
    /**
     * $opts['slides']: sm thumbs per slide (renderPostDetail) → "[Slide N] …" comments get a slide chip.
     * $opts['edit']: commentRevisionMeta() of this row (commentThreadHtml() fetches it for the whole thread) — the
     * "edited" label (when + who, in its title and a tap-to-show line) and, for a deleted comment on the admin seat,
     * the original text behind "Show original".
     * Editable rows (commentCanEdit()) carry data-comment-id / -raw / -can and the ⋯ button (App.comments).
     */
    function commentBubble(array $row, array $opts = []): string
    {
        $esc   = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $actor  = strtolower(trim((string)($row['actor'] ?? 'unknown')));
        $viewer = isset($opts['viewer']) ? (string)$opts['viewer'] : commentViewerRole();
        $side   = $actor === $viewer ? 'mine' : 'theirs';
        // Named Joust authors (notify-lib.php): the client reads "Lance at Joust"; on the admin seat a teammate's
        // message sits on the other side under their name. Rows without an author keep "Joust" / "You".
        $named  = ($actor === 'admin' && function_exists('activityAuthorLabel')) ? activityAuthorLabel($row, $viewer) : '';
        if ($named !== '' && $named !== 'You' && $viewer === 'admin') $side = 'theirs';
        $internal = !empty($row['internal']);
        $raw   = (string)($row['detail'] ?? '');
        $text  = $raw;
        $chip  = '';
        $slideNo = 0;
        if (function_exists('commentSlideSplit')) [$slideNo] = commentSlideSplit($raw);
        if (isset($opts['slides']) && is_array($opts['slides'])) {
            [$slideNo, $rest] = commentSlideSplit($text);
            if ($slideNo > 0) { $chip = commentSlideChip($slideNo, (string)($opts['slides'][$slideNo - 1] ?? '')); $text = $rest; }
        }
        $when  = (string)($row['created_at'] ?? '');
        $rel   = function_exists('relativeTime') ? relativeTime($when) : '';
        $abs   = function_exists('absoluteTime') ? absoluteTime($when) : $when;

        // Comment editing (comment-edit-lib.php): who may change it, edited / deleted state.
        $cid     = function_exists('commentRowId') ? commentRowId($row) : 0;
        $deleted = !empty($row['deleted_at']);
        $edited  = !$deleted && !empty($row['edited_at']);
        $canEdit = function_exists('commentCanEdit') && commentCanEdit($row, $viewer);
        $meta    = is_array($opts['edit'] ?? null) ? $opts['edit'] : null;
        $history = $viewer === 'admin' && $cid > 0 && ($edited || $deleted);

        // Actor avatar in the meta line: the Joust mark for admin bubbles, the client's logo /
        // initials for client bubbles (actorAvatar(), helpers.php; '' for 'unknown' notes).
        $avatar = function_exists('actorAvatar') ? actorAvatar($actor, $GLOBALS['client'] ?? null, 'ui-avatar--xs pd-msg-avatar') : '';

        // A client message on the admin seat names the signed-in contact who wrote it ("Jane Kenda (Kenda Tires)").
        if ($named === '' && $actor === 'client' && $viewer === 'admin' && function_exists('activityClientLabel')) $named = activityClientLabel($row);
        $label = $named !== '' ? $named : commentActorLabel($actor, $viewer);
        // Internal notes (Joust only — never rendered for the client seat; the readers filter them out) get a lock pill.
        $out  = '<div class="pd-msg pd-msg--' . $side . ($deleted ? ' pd-msg--deleted' : '') . '" data-actor="' . $esc($actor) . '"' . ($internal ? ' data-internal="1"' : '');
        if ($cid > 0) $out .= ' data-comment-id="' . $cid . '"';
        // the message without its "[Slide N] " tag (the editor's text) + the tag apart (the editor's slide picker)
        if ($canEdit) $out .= ' data-comment-can="edit" data-comment-raw="' . $esc($slideNo > 0 ? commentSlideSplit($raw)[1] : $raw) . '"' . ($slideNo > 0 ? ' data-comment-on-slide="' . $slideNo . '"' : '');
        if ($history) $out .= ' data-comment-history="1"';
        if ($deleted) $out .= ' data-comment-deleted="1"';
        $out .= '>';
        if ($deleted) {
            $out .= '<div class="ui-bubble ui-bubble--' . $side . ' ui-bubble--deleted' . ($internal ? ' ui-bubble--internal' : '') . '" data-comment-body>'
                  . '<span class="pd-msg-deleted-text">Comment deleted</span>';
            // Joust can look at what it said (the text before the deletion lives in comment_revisions)
            if ($viewer === 'admin' && $meta && ($meta['original'] ?? null) !== null && trim((string)$meta['original']) !== '') {
                $out .= '<details class="pd-msg-original" data-comment-original><summary>Show original</summary><div class="pd-msg-original-text">'
                      . nl2br($esc((string)$meta['original'])) . '</div></details>';
            }
            $out .= '</div>';
        } else {
            $out .= '<div class="ui-bubble ui-bubble--' . $side . ($internal ? ' ui-bubble--internal' : '') . '" data-comment-body>' . $chip . nl2br($esc($text)) . '</div>';
        }
        $out .= '<div class="ui-bubble-meta">' . $avatar . $esc($label)
              . ($internal ? ' <span class="ui-pill ui-pill--nodot ui-pill--internal" data-internal-pill>' . (function_exists('icon') ? icon('lock') : '') . 'Internal</span>' : '');
        if ($rel !== '') { $out .= ' · <time title="' . $esc($abs) . '">' . $esc($rel) . '</time>'; }
        if (($edited || $deleted) && $meta && function_exists('commentEditedTitle')) {
            $title = commentEditedTitle($meta, $viewer, $deleted);
            if ($edited) {
                // hover → the title; tap / Enter → the same line under the bubble (phones have no hover)
                $out .= ' · <button type="button" class="pd-msg-edited" data-comment-edited aria-expanded="false" title="' . $esc($title) . '">edited</button>'
                      . '<span class="pd-msg-edited-detail" data-comment-edited-detail hidden>' . $esc($title) . '</span>';
            } elseif ($viewer === 'admin') {
                $out .= ' · <span class="pd-msg-edited-detail">' . $esc($title) . '</span>';
            }
        } elseif ($edited) {
            $out .= ' · <span class="pd-msg-edited" data-comment-edited>edited</span>';
        }
        if ($canEdit || $history) $out .= commentMoreButton();   // in the meta line: next to who / when, never over the text
        $out .= '</div></div>';
        return $out;
    }
}

if (!function_exists('commentThreadHtml')) {
    function commentThreadHtml(array $rows, array $opts = []): string
    {
        $esc   = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        // a deleted comment keeps its place ("Comment deleted"); an empty legacy row is still skipped
        $rows  = array_values(array_filter($rows, static function ($r) {
            return trim((string)($r['detail'] ?? '')) !== '' || !empty($r['deleted_at']);
        }));
        $live  = count(array_filter($rows, static function ($r) { return empty($r['deleted_at']); }));
        $cls   = 'ui-thread pd-thread' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
        $attrs = ' data-thread data-count="' . $live . '"';
        foreach (($opts['attrs'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$k);
            if ($k !== '') $attrs .= ' ' . $k . '="' . $esc($v) . '"';
        }
        if (isset($opts['slides']) && is_array($opts['slides']) && count($opts['slides']) >= 2) $attrs .= ' data-slides="' . count($opts['slides']) . '"';
        // edit history of the edited / deleted rows: one query for the whole thread
        $metaIds = [];
        foreach ($rows as $r) {
            if ((!empty($r['edited_at']) || !empty($r['deleted_at'])) && function_exists('commentRowId')) $metaIds[] = commentRowId($r);
        }
        $meta = $metaIds && function_exists('commentRevisionMeta') ? commentRevisionMeta($GLOBALS['pdo'] ?? null, $metaIds) : [];
        $out = '<div class="' . $esc($cls) . '"' . $attrs . '>';
        foreach ($rows as $row) {
            $o = (isset($opts['slides']) ? ['slides' => (array)$opts['slides']] : []) + (isset($opts['viewer']) ? ['viewer' => (string)$opts['viewer']] : []);
            $id = function_exists('commentRowId') ? commentRowId($row) : 0;
            if ($id > 0 && isset($meta[$id])) $o['edit'] = $meta[$id];
            $out .= commentBubble($row, $o);
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
            // "All slides" until the user moves the carousel; posts.js then follows the slide on screen
            for ($i = 1; $i <= $nSlides; $i++) $slidePick .= '<option value="' . $i . '">Slide ' . $i . '</option>';
            $slidePick .= '</select>';
        }
        // Admins: "Internal (Joust only)" — the message becomes an internal note (never shown to the client, no client
        // email, posted to Slack marked internal). Off by default; the form turns amber while it is on (tracking.js).
        $internalOk  = !array_key_exists('internal', $opts) ? (function_exists('isAdmin') && isAdmin()) : (bool)$opts['internal'];
        $internalTog = $internalOk
            ? '<label class="pd-composer-internal" title="Only Joust sees internal notes">'
              . '<input type="checkbox" name="internal" value="1" data-comment-internal>'
              . (function_exists('icon') ? icon('lock') : '') . '<span>Internal (Joust only)</span></label>'
            : '';
        return '<form class="pd-composer' . ($slidePick !== '' ? ' pd-composer--slides' : '') . ($internalOk ? ' pd-composer--can-internal' : '') . '" data-comment-form data-id="' . (int)$postId . '" data-endpoint="' . $esc($endpoint) . '" autocomplete="off">'
             . $internalTog
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
