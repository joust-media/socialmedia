<?php
/**
 * Review actions shared by the post / email / page detail sheets (admin-first footers).
 *
 *   reviewLatestNote(array $comments, string $clientName): ?array
 *       The note behind a Needs changes item: the client's newest comment (a Needs changes note is a
 *       'commented' row in the same batch), else the newest comment of anyone. null for an empty thread.
 *       → ['who' => 'Kenda Tires'|'Joust'|'Note', 'text', 'slide' (0|N, from "[Slide N] "), 'at']
 *
 *   reviewNoteBanner(?array $note, bool $show): string
 *       "Kenda Tires asked for changes · 2h ago" + the note, pinned at the TOP of the sheet (above the media /
 *       preview) for the admin seat. Always rendered (hidden unless the item needs changes) so the page JS can
 *       fill it after an in-place "Needs changes…" ([data-state="note"], [data-pd-note-who|when|text]).
 *
 *   reviewAdminFooterHtml(string $kind, string $key, string $editUrl): string        (emails / pages)
 *       One button row per state, one primary each — the same shape as the post sheet:
 *         draft    → Edit · Send for review          pending → Edit (primary; the decision is the client's)
 *         denied   → Edit · Send for review          approved → Edit · Mark live          live → Unmark live
 *       [data-state="admin-…"] rows; the page JS toggles them from data-status / data-live.
 *
 *   reviewMenuItemsHtml(string $kind, string $key, string $editUrl, string $deleteAttr): string   (emails / pages)
 *       The ⋯ items after Move / Add to flow / Audiences: Edit … (live only — every other state has Edit in the
 *       footer), then "For the client" (Approve for client… —
 *       asks first —, Needs changes…), Move to Draft, Delete. There is no direct status override:
 *       Joust approves on the client's behalf only through Approve for client…, like posts.
 */

if (!function_exists('reviewEsc')) {
    function reviewEsc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('reviewLatestNote')) {
    function reviewLatestNote(array $comments, string $clientName): ?array
    {
        $pick = null;
        foreach ($comments as $c) {
            if (strtolower(trim((string)($c['actor'] ?? ''))) === 'client') $pick = $c;
        }
        if (!$pick && $comments) $pick = $comments[count($comments) - 1];
        if (!$pick) return null;
        $raw = trim((string)($pick['detail'] ?? ''));
        [$slide, $text] = function_exists('commentSlideSplit') ? commentSlideSplit($raw) : [0, $raw];
        $actor = strtolower(trim((string)($pick['actor'] ?? '')));
        return [
            'who'   => $actor === 'client' ? (trim($clientName) !== '' ? $clientName : 'The client') : ($actor === 'admin' ? 'Joust' : 'Note'),
            'text'  => trim((string)$text),
            'slide' => (int)$slide,
            'at'    => (string)($pick['created_at'] ?? ''),
        ];
    }
}

if (!function_exists('reviewNoteBanner')) {
    function reviewNoteBanner(?array $note, bool $show): string
    {
        $who  = $note ? $note['who'] : '';
        $when = $note && $note['at'] !== '' && function_exists('relativeTime') ? relativeTime($note['at']) : '';
        $out  = '<section class="pd-note" data-state="note" data-pd-note aria-label="What should change"' . ($show ? '' : ' hidden') . '>';
        $out .= '<div class="pd-note-head">' . (function_exists('icon') ? icon('xmark', 'pd-note-icon') : '')
              . '<span data-pd-note-who>' . ($who !== '' ? reviewEsc($who) . ' asked for changes' : 'Needs changes') . '</span>'
              . '<span class="pd-note-when text-tertiary" data-pd-note-when>' . ($when !== '' ? ' · ' . reviewEsc($when) : '') . '</span></div>';
        $text = $note ? $note['text'] : '';
        $out .= '<p class="pd-note-text" data-pd-note-text>'
              . ($note && $note['slide'] > 0 ? '<span class="pd-note-slide">On slide ' . (int)$note['slide'] . ':</span> ' : '')
              . ($text !== '' ? reviewEsc($text) : '<span class="text-tertiary">No note left.</span>') . '</p>';
        return $out . '</section>';
    }
}

if (!function_exists('reviewAdminFooterHtml')) {
    function reviewAdminFooterHtml(string $kind, string $key, string $editUrl): string
    {
        $noun = $kind === 'page' ? 'page' : 'email';
        $edit = static function (string $cls) use ($editUrl, $noun) {
            return '<a class="ui-btn ui-btn--large ' . $cls . '" href="' . reviewEsc($editUrl) . '" data-edit-' . $noun . '>Edit</a>';
        };
        $row = static function (string $state, string $html, bool $on) {
            return '<div class="ui-btn-group pd-' . $state . '" data-state="' . $state . '"' . ($on ? '' : ' hidden') . '>' . $html . '</div>';
        };
        $send = '<button type="button" class="ui-btn ui-btn--large ui-btn--filled ui-btn--primary" data-submit>Send for review</button>';
        return $row('admin-draft', $edit('ui-btn--gray') . $send, $key === 'draft')
             . $row('admin-pending', $edit('ui-btn--filled ui-btn--primary'), $key === 'pending')
             . $row('admin-denied', $edit('ui-btn--gray') . str_replace('data-submit>', 'data-resubmit-detail>', $send), $key === 'denied')
             . $row('admin-approved', $edit('ui-btn--gray') . '<button type="button" class="ui-btn ui-btn--large ui-btn--filled ui-btn--primary" data-toggle-live="1">Mark live</button>', $key === 'approved')
             . $row('admin-live', '<button type="button" class="ui-btn ui-btn--large ui-btn--gray" data-toggle-live="0">Unmark live</button>', $key === 'live');
    }
}

if (!function_exists('reviewMenuItemsHtml')) {
    function reviewMenuItemsHtml(string $kind, string $key, string $editUrl, string $deleteAttr): string
    {
        $noun = $kind === 'page' ? 'page' : 'email';
        $live = $key === 'live';
        $vis  = static function (bool $on) { return $on ? '' : ' hidden'; };
        // Edit …: only while the footer has no Edit button (a live item: Unmark live only) — one entry per editor
        $out  = '<div class="pd-menu-group" role="group" data-state="menu-edit"' . $vis($live) . '>'
              . '<div class="pd-menu-sep" role="separator"></div>'
              . '<a role="menuitem" class="pd-menu-link" href="' . reviewEsc($editUrl) . '">Edit ' . $noun . '…</a></div>';
        $out .= '<div class="pd-menu-group" role="group" aria-label="For the client" data-state="menu-decide"' . $vis(!$live && $key !== 'draft') . '>'
              . '<div class="pd-menu-sep" role="separator"></div>'
              . '<button type="button" role="menuitem" data-approve-for-client data-state="menu-approve"' . $vis(in_array($key, ['pending', 'denied'], true)) . '>Approve for client…</button>'
              . '<button type="button" role="menuitem" data-decide="denied" data-state="menu-deny"' . $vis(in_array($key, ['pending', 'approved'], true)) . '>Needs changes…</button>'
              . '<button type="button" role="menuitem" data-set-draft data-state="menu-draft"' . $vis(!$live && $key !== 'draft') . '>Move to Draft</button>'
              . '</div>';
        $out .= '<div class="pd-menu-sep" role="separator"></div>'
              . '<button type="button" role="menuitem" class="is-destructive" ' . $deleteAttr . '>Delete</button>';
        return $out;
    }
}
