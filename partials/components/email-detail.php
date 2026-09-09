<?php
/**
 * Email detail — the markup that fills the detail sheet on emails.php
 * (mirrors partials/components/post-detail.php; see scratchpad emails-design.md).
 *
 *   renderEmailDetail(array $email, array $opts = []): string
 *     $email: an emails row (+ 'groups' from emails-lib.php) with optional
 *             comments    => activity_log 'commented' rows [['actor','detail','created_at'], …]
 *             approved_at => datetime for the "Approved Sep 5" line
 *     $opts:  'admin'     bool   — default isAdmin(). Admin-only markup is NEVER emitted otherwise.
 *             'endpoint'  string — status endpoint (default 'email-status.php', resolved against basePath())
 *             'editUrl'   string — admin Edit link (default add-email.php?client=…&edit=ID)
 *     Output: <article class="pd ed" data-email-detail="ID" data-status data-live data-key data-past>
 *               <div class="pd-body" data-pd-body>…</div>       preview frame · meta list · thread
 *               <div class="pd-footer" data-pd-footer>…</div>   composer · deny note · state rows · actions
 *             </article>
 *     static/js/emails.js splits body/footer into the sheet's scroll area and sticky footer
 *     and toggles every [data-state] block from data-status / data-live.
 *
 *   The email itself is shown through <iframe sandbox="" src=html_url> (progressive
 *   enhancement — the host may refuse framing) with an "Open in new tab" link first.
 */

if (!function_exists('edEsc')) {
    function edEsc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('edUrl')) {
    /** Root-rooted URL for an endpoint / page name (mirrors pdMediaUrl). */
    function edUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('#^(https?:)?//#i', $url) || $url[0] === '/') return $url;
        return (function_exists('basePath') ? basePath() : '') . '/' . ltrim($url, '/');
    }
}

if (!function_exists('edSafeHttpUrl')) {
    /** Only http(s) URLs may be framed / linked; anything else renders the empty placeholder. */
    function edSafeHttpUrl(string $url): string
    {
        $url = trim($url);
        return preg_match('#^https?://[^\s"\'<>]+$#i', $url) ? $url : '';
    }
}

if (!function_exists('edFormatDate')) {
    /** "Saturday, Oct 25" for a DATE column; '' when empty / invalid. */
    function edFormatDate(?string $date): string
    {
        $raw = trim((string)$date);
        if ($raw === '' || $raw === '0000-00-00') return '';
        $ts = strtotime($raw);
        return $ts ? date('l, M j', $ts) . (date('Y', $ts) !== date('Y') ? date(', Y', $ts) : '') : '';
    }
}

if (!function_exists('renderEmailDetail')) {
    function renderEmailDetail(array $email, array $opts = []): string
    {
        $admin    = array_key_exists('admin', $opts) ? (bool)$opts['admin'] : (function_exists('isAdmin') && isAdmin());
        $endpoint = edUrl((string)($opts['endpoint'] ?? 'email-status.php'));

        $id       = (int)($email['id'] ?? 0);
        $status   = strtolower(trim((string)($email['status'] ?? 'draft')));
        if (!in_array($status, ['draft', 'pending', 'approved', 'denied'], true)) $status = 'draft';
        $live     = !empty($email['live']);
        $key      = $live ? 'live' : $status;
        $label    = function_exists('emailStatusLabelForKey') ? emailStatusLabelForKey($key) : ucfirst($key);
        $code     = trim((string)($email['code'] ?? ''));
        $title    = trim((string)($email['title'] ?? ''));
        $subject  = trim((string)($email['subject'] ?? ''));
        $preview  = trim((string)($email['preview_text'] ?? ''));
        $trigger  = trim(str_replace(["\r\n", "\r"], "\n", (string)($email['trigger_text'] ?? '')));
        $notes    = trim((string)($email['notes'] ?? ''));
        $priority = strtolower(trim((string)($email['priority'] ?? '')));
        $prioLbl  = function_exists('emailPriorityLabel') ? emailPriorityLabel($priority) : ucfirst($priority);
        $groups   = is_array($email['groups'] ?? null) ? $email['groups'] : [];
        $comments = is_array($opts['comments'] ?? null) ? $opts['comments'] : (is_array($email['comments'] ?? null) ? $email['comments'] : []);
        $htmlUrl  = edSafeHttpUrl((string)($email['html_url'] ?? ''));
        $sendRaw  = trim((string)($email['send_at'] ?? ''));
        $sendTs   = ($sendRaw !== '' && $sendRaw !== '0000-00-00') ? strtotime($sendRaw) : false;
        $datePast = $sendTs && date('Y-m-d', $sendTs) < date('Y-m-d');
        $isPast   = $live && $datePast;   // presentational — emails.js re-evaluates on Mark/Unmark live
        $editUrl  = (string)($opts['editUrl'] ?? (function_exists('clientUrl') ? clientUrl('add-email.php', ['edit' => $id]) : 'add-email.php?edit=' . $id));
        $ico      = static function (string $n, string $c = '') { return function_exists('icon') ? icon($n, $c) : ''; };

        $approvedAt   = !empty($email['approved_at']) ? strtotime((string)$email['approved_at']) : false;
        $approvedLine = 'Approved' . ($approvedAt ? ' ' . date('M j', $approvedAt) : '') . ' · Joust will make it live';

        $out  = '<article class="pd ed" data-email-detail="' . $id . '" data-id="' . $id . '" data-status="' . edEsc($status) . '" data-live="' . ($live ? '1' : '0') . '"'
              . ' data-key="' . edEsc($key) . '" data-past="' . ($datePast ? '1' : '0') . '" data-code="' . edEsc($code) . '" data-endpoint="' . edEsc($endpoint) . '">';
        $out .= '<div class="pd-body" data-pd-body>';

        // ---- Top meta row: "EMAIL · C1" · status pill · edited ---------------------
        $out .= '<div class="pd-meta ed-head">';
        $out .= '<span class="pd-type">Email' . ($code !== '' ? ' · ' . edEsc($code) : '') . '</span>';
        $out .= function_exists('emailStatusPill') ? emailStatusPill($email, ['class' => 'pd-pill']) : '';
        if (!empty($email['updated_at']) && function_exists('relativeTime') && relativeTime($email['updated_at']) !== '') {
            $out .= '<span class="pd-edited text-tertiary" title="' . edEsc(absoluteTime($email['updated_at'])) . '">edited ' . edEsc(relativeTime($email['updated_at'])) . '</span>';
        }
        $out .= '</div>';

        // ---- 1. Preview frame -----------------------------------------------------------
        $out .= '<section class="ed-preview" data-preview' . ($htmlUrl === '' ? ' data-preview-empty' : '') . '>';
        $out .= '<div class="ed-preview-bar">';
        $out .= '<div class="ui-segmented ui-segmented--auto ed-preview-toggle" role="group" aria-label="Preview width">'
              . '<button type="button" class="ui-segmented-item" data-preview-width="375" aria-pressed="false">Phone</button>'
              . '<button type="button" class="ui-segmented-item is-active" data-preview-width="650" aria-pressed="true">Desktop</button>'
              . '</div>';
        if ($htmlUrl !== '') {
            $out .= '<a class="ui-btn ui-btn--plain ui-btn--sm ed-preview-open" href="' . edEsc($htmlUrl) . '" target="_blank" rel="noopener noreferrer">Open in new tab</a>';
        }
        $out .= '</div>';
        if ($htmlUrl !== '') {
            $out .= '<div class="ed-frame-wrap" data-preview-frame-wrap data-preview-w="650">'
                  . '<iframe class="ed-frame" data-preview-frame sandbox="" referrerpolicy="no-referrer" loading="lazy" src="' . edEsc($htmlUrl) . '"'
                  . ' title="' . edEsc(($code !== '' ? $code . ' · ' : '') . ($title !== '' ? $title : 'Email') . ' preview') . '" width="650"></iframe>'
                  . '</div>'
                  . '<p class="ed-preview-hint text-tertiary">If the preview stays blank, the host does not allow embedding — use <a href="' . edEsc($htmlUrl) . '" target="_blank" rel="noopener noreferrer">Open in new tab</a>.</p>';
        } else {
            $out .= '<div class="ed-preview-empty" data-preview-placeholder>' . $ico('mail', 'ed-preview-empty-icon') . '<span>No email HTML yet</span>'
                  . '<span class="text-tertiary">' . ($admin ? 'Add the hosted HTML link in Edit to show a preview here.' : 'Joust will add the preview shortly.') . '</span></div>';
        }
        $out .= '</section>';

        // ---- 2. Meta list -----------------------------------------------------------------
        $out .= '<dl class="ed-meta">';
        $row = static function (string $k, string $vHtml, string $extra = '') {
            return '<div class="ed-meta-row' . ($extra !== '' ? ' ' . $extra : '') . '"><dt>' . edEsc($k) . '</dt><dd>' . $vHtml . '</dd></div>';
        };
        $out .= $row('Subject', $subject !== '' ? edEsc($subject) : '<span class="text-tertiary">No subject yet</span>');
        if ($preview !== '') $out .= $row('Preview text', edEsc($preview));
        $out .= $row('Trigger', $trigger !== '' ? '<span class="ed-pre">' . nl2br(edEsc($trigger)) . '</span>' : '<span class="text-tertiary">Not set</span>');
        $sendHtml = $sendTs
            ? '<time datetime="' . edEsc(date('Y-m-d', $sendTs)) . '" data-send-at>' . edEsc(edFormatDate($sendRaw)) . '</time>'
              . '<span class="ed-meta-past text-tertiary" data-send-past' . ($isPast ? '' : ' hidden') . '>This email\'s send date has passed.</span>'
            : '<span class="text-tertiary">No fixed date</span>';
        $out .= $row('Send date', $sendHtml);
        if ($prioLbl !== '') {
            $out .= $row('Priority', '<span class="ed-priority"><span class="ui-dot ed-dot ed-dot--' . edEsc($priority) . '"></span>' . edEsc($prioLbl) . '</span>');
        }
        if ($groups) {
            $chips = '';
            foreach ($groups as $g) {
                $chips .= '<span class="el-tag">' . edEsc((string)($g['name'] ?? '')) . '</span>';
            }
            $out .= $row(count($groups) === 1 ? 'Group' : 'Groups', '<span class="el-groups">' . $chips . '</span>');
        }
        $out .= $row('Code', ($code !== '' ? '<code class="ed-code">' . edEsc($code) . '</code>' : '<span class="text-tertiary">—</span>')
              . ($htmlUrl !== '' ? ' <a class="ed-url" href="' . edEsc($htmlUrl) . '" target="_blank" rel="noopener noreferrer">' . edEsc(preg_replace('#^https?://#i', '', $htmlUrl)) . '</a>' : ''));
        if ($admin && $notes !== '') {
            $out .= $row('Notes', '<span class="ed-pre">' . nl2br(edEsc($notes)) . '</span> <span class="ui-pill ui-pill--joust ui-pill--nodot ed-notes-pill">Joust only</span>', 'ed-meta-row--admin');
        }
        $out .= '</dl>';

        // ---- 3. Comments thread -------------------------------------------------------------
        $out .= '<section class="pd-comments"><h3 class="pd-section-title">Comments <span class="pd-comment-count text-tertiary" data-comment-count>' . count($comments) . '</span></h3>';
        $out .= commentThreadHtml($comments, ['empty' => 'No messages yet — questions and change requests go here.']);
        $out .= '</section>';

        $out .= '</div>'; // /.pd-body

        // ---- 4. Sticky footer: composer + deny note + state rows + actions ------------------
        $out .= '<div class="pd-footer" data-pd-footer>';
        $out .= commentComposer($id, ['endpoint' => $endpoint, 'entity' => 'email', 'stamp' => false]);

        $out .= '<form class="pd-deny" data-deny-form hidden>'
              . '<label class="pd-editor-label" for="ed-deny-' . $id . '">What should change?</label>'
              . '<textarea class="ui-textarea" id="ed-deny-' . $id . '" data-deny-note placeholder="What should change?" minlength="3" maxlength="2000" rows="2" required></textarea>'
              . '<p class="pd-editor-hint" data-deny-hint>A short note is required so Joust knows what to fix.</p>'
              . '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-deny-cancel>Cancel</button>'
              . '<button type="submit" class="ui-btn ui-btn--deny ui-btn--primary" data-deny-submit disabled>Send &amp; request changes</button></div>'
              . '</form>';

        // State rows (all rendered; emails.js toggles [data-state] from data-status / data-live)
        $out .= '<div class="pd-state pd-state--approved" data-state="approved"' . ($key === 'approved' ? '' : ' hidden') . '>'
              . $ico('checkmark') . '<span data-approved-line>' . edEsc($approvedLine) . '</span></div>';
        $out .= '<div class="pd-state pd-state--scheduled" data-state="live"' . ($live ? '' : ' hidden') . '>'
              . $ico('checkmark') . '<span>Live</span></div>';
        if ($admin) {
            $out .= '<div class="pd-state pd-state--denied" data-state="denied"' . ($key === 'denied' ? '' : ' hidden') . '>'
                  . $ico('xmark') . '<span>Needs changes</span></div>';
            $out .= '<div class="pd-state pd-state--draft" data-state="draft"' . ($key === 'draft' ? '' : ' hidden') . '>'
                  . $ico('mail') . '<span>Draft · not visible to the client</span></div>';
        }

        // Action bar
        $out .= '<div class="pd-actions" data-actions>';
        // Client + admin: decide while pending
        $out .= '<div class="ui-btn-group pd-decide" data-state="decide"' . ($key === 'pending' ? '' : ' hidden') . '>'
              . '<button type="button" class="ui-btn ui-btn--large ui-btn--deny ui-btn--tinted" data-decide="denied">Needs changes</button>'
              . '<button type="button" class="ui-btn ui-btn--large ui-btn--approve ui-btn--primary" data-decide="approved">Approve</button>'
              . '</div>';
        if ($admin) {
            // Status control (any non-live email): Draft · To Review · Approved · Needs changes
            $out .= '<div class="ed-status-ctl" data-state="admin-status"' . ($live ? ' hidden' : '') . '>'
                  . '<span class="ed-status-ctl-label">Status</span>'
                  . '<div class="ui-segmented ui-segmented--dense" role="group" aria-label="Set status">';
            foreach (['draft' => 'Draft', 'pending' => 'To Review', 'approved' => 'Approved', 'denied' => 'Needs changes'] as $k => $l) {
                $out .= '<button type="button" class="ui-segmented-item' . ($status === $k ? ' is-active' : '') . '" data-set-status="' . $k . '" aria-pressed="' . ($status === $k ? 'true' : 'false') . '">' . $l . '</button>';
            }
            $out .= '</div></div>';
            // Draft: Send for review (primary)
            $out .= '<div class="ui-btn-group pd-admin-draft" data-state="admin-draft"' . ($key === 'draft' ? '' : ' hidden') . '>'
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--filled ui-btn--primary" data-submit>Send for review</button>'
                  . '</div>';
            // Approved + not live: Mark live (primary)
            $out .= '<div class="ui-btn-group pd-admin-approved" data-state="admin-approved"' . ($key === 'approved' ? '' : ' hidden') . '>'
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--filled ui-btn--primary" data-toggle-live="1">Mark live</button>'
                  . '</div>';
            // Denied: Resubmit for review · Approve
            $out .= '<div class="ui-btn-group pd-admin-denied" data-state="admin-denied"' . ($key === 'denied' ? '' : ' hidden') . '>'
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--gray" data-resubmit-detail>Resubmit for review</button>'
                  . '<button type="button" class="ui-btn ui-btn--large ui-btn--approve ui-btn--primary" data-decide="approved">Approve</button>'
                  . '</div>';
            // Live: Unmark live
            $out .= '<div class="ui-btn-group pd-admin-live" data-state="admin-live"' . ($live ? '' : ' hidden') . '>'
                  . '<button type="button" class="ui-btn ui-btn--gray" data-toggle-live="0">Unmark live</button>'
                  . '</div>';
            // Edit · Delete (always for admin)
            $out .= '<div class="ed-admin-tools">'
                  . '<a class="ui-btn ui-btn--gray ui-btn--sm" href="' . edEsc($editUrl) . '" data-edit-email>' . $ico('wand') . 'Edit</a>'
                  . '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm ed-delete" data-delete-email>' . $ico('xmark') . 'Delete</button>'
                  . '</div>';
        }
        $out .= '</div>'; // /.pd-actions
        $out .= '</div>'; // /.pd-footer
        $out .= '</article>';
        return $out;
    }
}
