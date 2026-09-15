<?php
/**
 * Flow step card — one email in a Flow's timeline (flows.php; see scratchpad flows-design.md).
 *
 *   renderFlowCard(array $step, array $opts = []): string
 *     $step: an email_flow_steps row from emailFlowSteps():
 *              id, flow_id, email_id, position, timing_text, note, email => emails row (+ 'groups')
 *            A step without an 'email' array renders nothing.
 *     $opts: 'admin'   bool   — default isAdmin(). Edit tools (handle, up/down, remove, timing
 *                               button, insert-before) are NEVER emitted otherwise; when emitted
 *                               they stay hidden until the page root carries .is-editing.
 *            'editing' bool   — the page is already in edit mode (flow-status.php's add_step
 *                               passes the caller's state; affects nothing but is accepted).
 *            'index'   int    — 0-based position in the rendered list (default position - 1)
 *            'total'   int    — steps in the flow (for "Step 3 of 7"; default 0 → "Step 3")
 *            'client'  string — client slug for the Open link (default: the page's $clientSlug)
 *
 *   Output (one list item; flows.js re-numbers [data-flow-num] / data-position after every move):
 *     <li class="fl-step" id="flow-step-<emailId>" data-flow-step=<stepId> data-email-item=<emailId>
 *         data-id=<emailId> data-position=N data-status data-live data-key data-past data-title
 *         data-code data-timing="<override>" data-trigger="<first trigger line>">
 *       <div class="fl-connector" data-flow-connector>   Start marker (CSS: first card only) ·
 *                                                        line · timing pill · line · arrowhead · [insert +]
 *       <article class="fl-card">                        step number · code tile · title · trigger ·
 *                                                        [tools] · subject · preview · meta · Open / View HTML
 *     </li>
 *   data-email-item / data-id / .el-code-tile / [data-status-pill] are the hooks static/js/emails.js
 *   uses to keep the card in sync with decisions taken in the detail sheet.
 */

if (!function_exists('fcEsc')) {
    function fcEsc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('fcSafeHttpUrl')) {
    /** Only http(s) URLs may be linked; anything else drops the View HTML action. */
    function fcSafeHttpUrl(string $url): string
    {
        $url = trim($url);
        return preg_match('#^https?://[^\s"\'<>]+$#i', $url) ? $url : '';
    }
}

if (!function_exists('fcFirstLine')) {
    /** First non-empty line, clipped to $max characters with an ellipsis. */
    function fcFirstLine(string $text, int $max = 90): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $line = '';
        foreach (explode("\n", $text) as $l) { $l = trim($l); if ($l !== '') { $line = $l; break; } }
        if (mb_strlen($line) > $max) { $line = rtrim(mb_substr($line, 0, $max - 1)) . '…'; }
        return $line;
    }
}

if (!function_exists('fcHandleIcon')) {
    /** Grip glyph for the drag handle (no icon file exists for it). */
    function fcHandleIcon(): string
    {
        return '<svg class="ui-icon ui-icon--grip" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M5 8h14M5 12h14M5 16h14"/></svg>';
    }
}

if (!function_exists('renderFlowCard')) {
    function renderFlowCard(array $step, array $opts = []): string
    {
        $email = is_array($step['email'] ?? null) ? $step['email'] : null;
        if (!$email) return '';

        $admin   = array_key_exists('admin', $opts) ? (bool)$opts['admin'] : (function_exists('isAdmin') && isAdmin());
        $pos     = (int)($step['position'] ?? 0);
        $index   = array_key_exists('index', $opts) ? max(0, (int)$opts['index']) : max(0, $pos - 1);
        $total   = max(0, (int)($opts['total'] ?? 0));
        $num     = $index + 1;
        $stepId  = (int)($step['id'] ?? 0);
        $eid     = (int)($email['id'] ?? 0);
        $ico     = static function (string $n, string $c = '') { return function_exists('icon') ? icon($n, $c) : ''; };

        $status  = strtolower(trim((string)($email['status'] ?? 'draft')));
        if (!in_array($status, ['draft', 'pending', 'approved', 'denied'], true)) $status = 'draft';
        $live    = !empty($email['live']);
        $key     = $live ? 'live' : $status;
        $isPast  = function_exists('emailIsPast') ? emailIsPast($email) : false;
        $code    = trim((string)($email['code'] ?? ''));
        $title   = trim((string)($email['title'] ?? ''));
        $subject = trim((string)($email['subject'] ?? ''));
        $preview = trim((string)($email['preview_text'] ?? ''));
        $trigLn  = fcFirstLine((string)($email['trigger_text'] ?? ''));
        $prio    = strtolower(trim((string)($email['priority'] ?? '')));
        $prioLbl = function_exists('emailPriorityLabel') ? emailPriorityLabel($prio) : (in_array($prio, ['low', 'medium', 'high'], true) ? ucfirst($prio) : '');
        $groups  = is_array($email['groups'] ?? null) ? $email['groups'] : [];
        $htmlUrl = fcSafeHttpUrl((string)($email['html_url'] ?? ''));
        $rowTitle = $title !== '' ? $title : ($code !== '' ? $code : 'Email #' . $eid);
        $stepLabel = 'Step ' . $num . ($total > 0 ? ' of ' . $total : '');
        $stepShort = 'step ' . $num;   // tool labels: flows.js re-labels them after every move ("Move step 3 up")

        // Timing shown on the connector: the step's override, else the email's first trigger line, else unset.
        $override = trim((string)($step['timing_text'] ?? ''));
        $timing   = function_exists('emailFlowTiming') ? trim((string)emailFlowTiming($step)) : ($override !== '' ? $override : $trigLn);
        $source   = $override !== '' ? 'override' : ($timing !== '' ? 'trigger' : 'none');
        $timingLbl = $timing !== '' ? $timing : 'Timing not set';

        // Open: the detail sheet in-page (emails.js), with the list deep link as the no-JS fallback.
        $slug    = (string)($opts['client'] ?? ($GLOBALS['clientSlug'] ?? ''));
        $openUrl = function_exists('clientUrl')
            ? clientUrl('emails.php', ['client' => $slug !== '' ? $slug : null, 'status' => 'all', 'email' => $eid])
            : 'emails.php?email=' . $eid;

        $cls = 'fl-step fl-step--' . $key . ($isPast ? ' fl-step--past' : '');
        $out = '<li class="' . fcEsc($cls) . '" id="flow-step-' . $eid . '" data-flow-step="' . $stepId . '" data-email-item="' . $eid . '" data-id="' . $eid . '"'
             . ' data-email-id="' . $eid . '" data-position="' . $num . '" data-code="' . fcEsc($code) . '" data-title="' . fcEsc($rowTitle) . '"'
             . ' data-status="' . fcEsc($status) . '" data-live="' . ($live ? '1' : '0') . '" data-key="' . fcEsc($key) . '"' . ($isPast ? ' data-past="1"' : '')
             . ' data-timing="' . fcEsc($override) . '" data-trigger="' . fcEsc($trigLn) . '">';

        // ---- Connector: Start marker (first card only, via CSS) · line · timing pill · line · arrowhead
        $out .= '<div class="fl-connector" data-flow-connector>';
        $out .= '<span class="fl-start" aria-hidden="true"><span class="fl-start-dot"></span><span class="fl-start-label">Start</span></span>';
        $out .= '<span class="fl-line" aria-hidden="true"></span>';
        $tCls = 'fl-timing fl-timing--' . $source;
        if ($admin) {
            $out .= '<button type="button" class="' . $tCls . '" data-flow-timing data-timing-source="' . $source . '"'
                  . ' title="Tap to change the timing before this step" aria-label="' . fcEsc('Timing before ' . $stepShort . ': ' . $timingLbl . '. Edit') . '">'
                  . $ico('calendar', 'fl-timing-icon') . '<span data-flow-timing-label>' . fcEsc($timingLbl) . '</span></button>';
        } else {
            $out .= '<span class="' . $tCls . '" data-flow-timing data-timing-source="' . $source . '">' . $ico('calendar', 'fl-timing-icon') . '<span data-flow-timing-label>' . fcEsc($timingLbl) . '</span></span>';
        }
        $out .= '<span class="fl-line" aria-hidden="true"></span>';
        $out .= '<span class="fl-arrow" aria-hidden="true"></span>';
        if ($admin) {
            $out .= '<button type="button" class="fl-insert" data-flow-insert aria-label="' . fcEsc('Insert an email before ' . $stepShort) . '" title="Insert an email here">' . $ico('plus') . '</button>';
        }
        $out .= '</div>';

        // ---- Card
        $out .= '<article class="fl-card" aria-label="' . fcEsc($stepLabel . ': ' . ($code !== '' ? $code . ' · ' : '') . $rowTitle) . '">';
        $out .= '<div class="fl-card-top">';
        $out .= '<span class="fl-num" aria-hidden="true" data-flow-num>' . $num . '</span>';
        $out .= '<span class="ui-visually-hidden" data-flow-num-sr>' . fcEsc($stepLabel) . '</span>';
        $out .= '<div class="el-code-tile el-code-tile--' . fcEsc($key) . ' fl-code-tile" aria-hidden="true"><span class="el-code">' . fcEsc($code !== '' ? $code : '—') . '</span></div>';
        $out .= '<div class="fl-heading">';
        $out .= '<div class="fl-title">' . fcEsc($rowTitle) . '</div>';
        if ($trigLn !== '') {
            $out .= '<div class="el-trigger fl-trigger">' . $ico('calendar', 'el-trigger-icon') . '<span>' . fcEsc($trigLn) . '</span></div>';
        } else {
            $out .= '<div class="el-trigger fl-trigger fl-trigger--empty"><span>No trigger yet</span></div>';
        }
        $out .= '</div>';
        if ($admin) {
            $out .= '<div class="fl-tools" data-flow-tools>'
                  . '<button type="button" class="fl-tool fl-handle" data-flow-handle aria-label="' . fcEsc('Drag to reorder ' . $stepShort) . '" title="Drag to reorder">' . fcHandleIcon() . '</button>'
                  . '<button type="button" class="fl-tool fl-tool--up" data-flow-up aria-label="' . fcEsc('Move ' . $stepShort . ' up') . '" title="Move up"' . ($index === 0 ? ' disabled' : '') . '>' . $ico('chevron-down', 'fl-icon-up') . '</button>'
                  . '<button type="button" class="fl-tool fl-tool--down" data-flow-down aria-label="' . fcEsc('Move ' . $stepShort . ' down') . '" title="Move down"' . ($total > 0 && $num >= $total ? ' disabled' : '') . '>' . $ico('chevron-down') . '</button>'
                  . '<button type="button" class="fl-tool fl-tool--remove" data-flow-remove aria-label="' . fcEsc('Remove ' . ($code !== '' ? $code : $rowTitle) . ' from this flow') . '" title="Remove from flow">' . $ico('xmark') . '</button>'
                  . '</div>';
        }
        $out .= '</div>'; // /.fl-card-top

        $out .= '<div class="fl-card-body">';
        $out .= '<div class="fl-subject' . ($subject === '' ? ' fl-subject--empty' : '') . '"><span class="fl-label">Subject</span><span class="fl-subject-text">' . ($subject !== '' ? fcEsc($subject) : 'No subject yet') . '</span></div>';
        if ($preview !== '') {
            $out .= '<div class="fl-preview"><span class="fl-label">Preview</span><span class="fl-preview-text">' . fcEsc($preview) . '</span></div>';
        }
        $out .= '</div>';

        $out .= '<div class="fl-card-foot">';
        $out .= '<div class="pl-meta fl-meta">';
        $out .= function_exists('emailStatusPill') ? emailStatusPill($email) : '<span class="ui-pill ui-pill--neutral" data-status-pill data-status="' . fcEsc($key) . '">' . fcEsc(ucfirst($key)) . '</span>';
        if ($isPast) $out .= '<span class="pl-meta-item"><span class="pl-meta-sep">·</span><span class="pl-past" title="This email\'s send date has passed">Past</span></span>';
        if ($prioLbl !== '') {
            $out .= '<span class="pl-meta-item el-prio el-prio--' . fcEsc($prio) . '"><span class="pl-meta-sep">·</span><span class="ui-dot ed-dot ed-dot--' . fcEsc($prio) . '"></span><span>' . fcEsc($prioLbl) . '</span></span>';
        }
        if ($groups) {
            $out .= '<span class="pl-meta-item el-groups"><span class="pl-meta-sep">·</span>';
            foreach ($groups as $g) $out .= '<span class="el-tag">' . fcEsc((string)($g['name'] ?? '')) . '</span>';
            $out .= '</span>';
        }
        $out .= '</div>';
        $out .= '<div class="fl-actions">';
        $out .= '<a class="ui-btn ui-btn--gray ui-btn--sm" href="' . fcEsc($openUrl) . '" data-email-open="' . $eid . '">Open</a>';
        if ($htmlUrl !== '') {
            $out .= '<a class="ui-btn ui-btn--plain ui-btn--sm fl-view-html" href="' . fcEsc($htmlUrl) . '" target="_blank" rel="noopener noreferrer">View HTML</a>';
        }
        $out .= '</div>';
        $out .= '</div>'; // /.fl-card-foot
        $out .= '</article>';
        $out .= '</li>';
        return $out;
    }
}
