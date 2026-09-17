<?php
/**
 * Page detail — the markup that fills the detail sheet on pages.php
 * (mirrors partials/components/email-detail.php; see scratchpad pages-design.md).
 *
 *   renderPageDetail(array $page, array $opts = []): string
 *     $page:  a pages row (+ 'company_slug' from pages-lib.php) with optional
 *             comments    => activity_log 'commented' rows [['actor','detail','created_at'], …]
 *             approved_at => datetime for the "Approved Sep 5" line
 *             files       => page_files rows (pageFilesFor) for upload-sourced pages
 *     $opts:  'admin'     bool   — default isAdmin(). Admin-only markup is NEVER emitted otherwise.
 *             'endpoint'  string — status endpoint (default 'page-status.php', resolved against basePath())
 *             'editUrl'   string — admin Edit link (default add-page.php?client=…&edit=ID)
 *             'company'   array  — the tenant (slug) for pageViewUrl(); default $page['company_slug']
 *     Output: <article class="pd pg" data-page-detail="ID" data-status data-live data-key>
 *               <div class="pd-body" data-pd-body>…</div>       preview frame · meta list · files · thread
 *               <div class="pd-footer" data-pd-footer>…</div>   composer · deny note · state rows · actions
 *             </article>
 *     static/js/pages.js splits body/footer into the sheet's scroll area and sticky footer
 *     and toggles every [data-state] block from data-status / data-live.
 *
 *   The page itself is shown through a sandboxed <iframe>. Landing pages need
 *   their scripts, so the sandbox is "allow-scripts allow-same-origin allow-forms
 *   allow-popups" (no top-navigation, no downloads without a gesture, no modals).
 *   Uploaded pages live under /media/pages/ — a static folder with PHP switched
 *   off and no session — so the same-origin grant exposes nothing the admin did
 *   not upload; external URLs get their own origin anyway.
 */

if (!function_exists('pgEsc')) {
    function pgEsc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('pgUrl')) {
    /** Root-rooted URL for an endpoint / page name (mirrors edUrl). */
    function pgUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('#^(https?:)?//#i', $url) || $url[0] === '/') return $url;
        return (function_exists('basePath') ? basePath() : '') . '/' . ltrim($url, '/');
    }
}

if (!function_exists('renderPageDetail')) {
    function renderPageDetail(array $page, array $opts = []): string
    {
        $admin    = array_key_exists('admin', $opts) ? (bool)$opts['admin'] : (function_exists('isAdmin') && isAdmin());
        $endpoint = pgUrl((string)($opts['endpoint'] ?? 'page-status.php'));
        $company  = is_array($opts['company'] ?? null) ? $opts['company'] : ['slug' => (string)($page['company_slug'] ?? '')];

        $id       = (int)($page['id'] ?? 0);
        $status   = strtolower(trim((string)($page['status'] ?? 'draft')));
        if (!in_array($status, ['draft', 'pending', 'approved', 'denied'], true)) $status = 'draft';
        $live     = !empty($page['live']);
        $key      = $live ? 'live' : $status;
        $title    = trim((string)($page['title'] ?? ''));
        $slug     = trim((string)($page['slug'] ?? ''));
        $source   = strtolower(trim((string)($page['source'] ?? 'upload'))) === 'url' ? 'url' : 'upload';
        $entry    = trim((string)($page['entry'] ?? 'index.html'));
        $desc     = trim(str_replace(["\r\n", "\r"], "\n", (string)($page['description'] ?? '')));
        $notes    = trim((string)($page['notes'] ?? ''));
        $comments = is_array($opts['comments'] ?? null) ? $opts['comments'] : (is_array($page['comments'] ?? null) ? $page['comments'] : []);
        $files    = is_array($page['files'] ?? null) ? $page['files'] : [];
        $viewUrl  = function_exists('pageViewUrl') ? pageViewUrl($page, $company) : '';
        $folder   = ($source === 'upload' && function_exists('pageFolderRel')) ? pageFolderRel($company, $page) : '';
        $editUrl  = (string)($opts['editUrl'] ?? (function_exists('clientUrl') ? clientUrl('add-page.php', ['edit' => $id]) : 'add-page.php?edit=' . $id));
        $ico      = static function (string $n, string $c = '') { return function_exists('icon') ? icon($n, $c) : ''; };
        $label    = $title !== '' ? $title : ($slug !== '' ? $slug : 'Page');

        // An upload page with no entry file on disk / no files yet: show the placeholder, not a 404 frame.
        $hasEntry = $viewUrl !== '';
        if ($source === 'upload' && $hasEntry && $files) {
            $hasEntry = false;
            foreach ($files as $f) { if ((string)($f['filename'] ?? '') === $entry) { $hasEntry = true; break; } }
        } elseif ($source === 'upload' && !$files) {
            $hasEntry = false;
        }

        $approvedAt   = !empty($page['approved_at']) ? strtotime((string)$page['approved_at']) : false;
        $approvedLine = 'Approved' . ($approvedAt ? ' ' . date('M j', $approvedAt) : '') . ' · Joust will make it live';

        $out  = '<article class="pd pg" data-page-detail="' . $id . '" data-id="' . $id . '" data-status="' . pgEsc($status) . '" data-live="' . ($live ? '1' : '0') . '"'
              . ' data-key="' . pgEsc($key) . '" data-slug="' . pgEsc($slug) . '" data-source="' . pgEsc($source) . '" data-endpoint="' . pgEsc($endpoint) . '">';
        $out .= '<div class="pd-body" data-pd-body>';

        // ---- Top meta row: "PAGE · slug" · status pill · source badge · edited ------------
        $out .= '<div class="pd-meta pg-head">';
        $out .= '<span class="pd-type">Page' . ($slug !== '' ? ' · ' . pgEsc($slug) : '') . '</span>';
        $out .= function_exists('pageStatusPill') ? pageStatusPill($page, ['class' => 'pd-pill']) : '';
        $out .= '<span class="pg-source pg-source--' . $source . '">' . ($source === 'url' ? 'URL' : 'Upload') . '</span>';
        if (!empty($page['updated_at']) && function_exists('relativeTime') && relativeTime($page['updated_at']) !== '') {
            $out .= '<span class="pd-edited text-tertiary" title="' . pgEsc(absoluteTime($page['updated_at'])) . '">edited ' . pgEsc(relativeTime($page['updated_at'])) . '</span>';
        }
        $out .= '</div>';

        // ---- 1. Preview frame -----------------------------------------------------------
        $out .= '<section class="pg-preview" data-preview' . (!$hasEntry ? ' data-preview-empty' : '') . '>';
        $out .= '<div class="pg-preview-bar">';
        $out .= '<div class="ui-segmented ui-segmented--auto pg-preview-toggle" role="group" aria-label="Preview width">'
              . '<button type="button" class="ui-segmented-item" data-preview-width="390" aria-pressed="false">Phone</button>'
              . '<button type="button" class="ui-segmented-item is-active" data-preview-width="1280" aria-pressed="true">Desktop</button>'
              . '</div>';
        if ($hasEntry) {
            $out .= '<a class="ui-btn ui-btn--plain ui-btn--sm pg-preview-open" href="' . pgEsc($viewUrl) . '" target="_blank" rel="noopener noreferrer" data-page-open-tab>Open in new tab</a>';
        }
        $out .= '</div>';
        if ($hasEntry) {
            $out .= '<div class="pg-frame-wrap" data-preview-frame-wrap data-preview-w="1280">'
                  . '<iframe class="pg-frame" data-preview-frame sandbox="allow-scripts allow-same-origin allow-forms allow-popups" referrerpolicy="no-referrer" loading="lazy" src="' . pgEsc($viewUrl) . '"'
                  . ' title="' . pgEsc($label . ' preview') . '" width="1280"></iframe>'
                  . '</div>';
            if ($source === 'url') {
                $out .= '<p class="pg-preview-hint text-tertiary">If the preview stays blank, the host does not allow embedding — use <a href="' . pgEsc($viewUrl) . '" target="_blank" rel="noopener noreferrer">Open in new tab</a>.</p>';
            }
        } else {
            $out .= '<div class="pg-preview-empty" data-preview-placeholder>' . $ico('page', 'pg-preview-empty-icon') . '<span>No page HTML yet</span>'
                  . '<span class="text-tertiary">' . ($admin
                        ? ($source === 'url' ? 'Add the page URL in Edit to show a preview here.' : 'Upload ' . pgEsc($entry) . ' (and its assets) in Edit to show a preview here.')
                        : 'Joust will add the preview shortly.') . '</span></div>';
        }
        $out .= '</section>';

        // ---- 2. Description + meta list -----------------------------------------------------
        if ($desc !== '') {
            $out .= '<section class="pg-desc"><h3 class="pd-section-title">About this page</h3><p class="pg-desc-text">' . nl2br(pgEsc($desc)) . '</p></section>';
        }
        $out .= '<dl class="pg-meta">';
        $row = static function (string $k, string $vHtml, string $extra = '') {
            return '<div class="pg-meta-row' . ($extra !== '' ? ' ' . $extra : '') . '"><dt>' . pgEsc($k) . '</dt><dd>' . $vHtml . '</dd></div>';
        };
        $out .= $row('Slug', $slug !== '' ? '<code class="pg-code">' . pgEsc($slug) . '</code>' : '<span class="text-tertiary">—</span>');
        if ($source === 'url') {
            $out .= $row('URL', $viewUrl !== ''
                ? '<a class="pg-url" href="' . pgEsc($viewUrl) . '" target="_blank" rel="noopener noreferrer">' . pgEsc(preg_replace('#^https?://#i', '', $viewUrl)) . '</a>'
                : '<span class="text-tertiary">No URL yet</span>');
        } else {
            $out .= $row('Folder', $folder !== '' ? '<code class="pg-code">' . pgEsc($folder) . '/</code>' : '<span class="text-tertiary">—</span>');
            $out .= $row('Entry file', '<code class="pg-code" data-page-entry>' . pgEsc($entry) . '</code>'
                  . ($viewUrl !== '' && $hasEntry ? ' <a class="pg-url" href="' . pgEsc($viewUrl) . '" target="_blank" rel="noopener noreferrer">open</a>' : ''));
        }
        if ($admin && $notes !== '') {
            $out .= $row('Notes', '<span class="pg-pre">' . nl2br(pgEsc($notes)) . '</span> <span class="ui-pill ui-pill--joust ui-pill--nodot pg-notes-pill">Joust only</span>', 'pg-meta-row--admin');
        }
        $out .= '</dl>';

        // ---- 3. Files (upload source) ---------------------------------------------------------
        if ($source === 'upload') {
            $out .= '<section class="pg-files" data-page-files-list><h3 class="pd-section-title">Files <span class="pd-comment-count text-tertiary">' . count($files) . '</span></h3>';
            if ($files) {
                $out .= '<ul class="pg-file-list" role="list">';
                foreach ($files as $f) {
                    $name = (string)($f['filename'] ?? '');
                    $isEntry = $name === $entry;
                    $href = '';
                    if ($folder !== '' && function_exists('pageFileRelValid') && pageFileRelValid($name)) {
                        $href = '/' . implode('/', array_map('rawurlencode', array_merge(explode('/', $folder), explode('/', $name))));
                    }
                    $out .= '<li class="pg-file' . ($isEntry ? ' pg-file--entry' : '') . '" data-page-file="' . pgEsc($name) . '">'
                          . ($href !== '' ? '<a class="pg-file-name" href="' . pgEsc($href) . '" target="_blank" rel="noopener noreferrer">' . pgEsc($name) . '</a>' : '<span class="pg-file-name">' . pgEsc($name) . '</span>')
                          . '<span class="pg-file-meta">' . (function_exists('pageFormatBytes') ? pgEsc(pageFormatBytes((int)($f['size'] ?? 0))) : (int)($f['size'] ?? 0))
                          . ($isEntry ? ' · <span class="pg-file-entry-tag">entry</span>' : '') . '</span>'
                          . '</li>';
                }
                $out .= '</ul>';
            } else {
                $out .= '<p class="pg-files-empty text-tertiary">' . ($admin ? 'No files uploaded yet — add them in Edit.' : 'No files yet.') . '</p>';
            }
            $out .= '</section>';
        }

        // ---- 4. Comments thread -------------------------------------------------------------
        $out .= '<section class="pd-comments"><h3 class="pd-section-title">Comments <span class="pd-comment-count text-tertiary" data-comment-count>' . count($comments) . '</span></h3>';
        $out .= commentThreadHtml($comments, ['empty' => 'No messages yet — questions and change requests go here.']);
        $out .= '</section>';

        $out .= '</div>'; // /.pd-body

        // ---- 5. Sticky footer: composer + deny note + state rows + actions ------------------
        $out .= '<div class="pd-footer" data-pd-footer>';
        $out .= commentComposer($id, ['endpoint' => $endpoint, 'entity' => 'page', 'stamp' => false]);

        $out .= '<form class="pd-deny" data-deny-form hidden>'
              . '<label class="pd-editor-label" for="pg-deny-' . $id . '">What should change?</label>'
              . '<textarea class="ui-textarea" id="pg-deny-' . $id . '" data-deny-note placeholder="What should change?" minlength="3" maxlength="2000" rows="2" required></textarea>'
              . '<p class="pd-editor-hint" data-deny-hint>A short note is required so Joust knows what to fix.</p>'
              . '<div class="ui-btn-group"><button type="button" class="ui-btn ui-btn--gray" data-deny-cancel>Cancel</button>'
              . '<button type="submit" class="ui-btn ui-btn--deny ui-btn--primary" data-deny-submit disabled>Send &amp; request changes</button></div>'
              . '</form>';

        // State rows (all rendered; pages.js toggles [data-state] from data-status / data-live)
        $out .= '<div class="pd-state pd-state--approved" data-state="approved"' . ($key === 'approved' ? '' : ' hidden') . '>'
              . $ico('checkmark') . '<span data-approved-line>' . pgEsc($approvedLine) . '</span></div>';
        $out .= '<div class="pd-state pd-state--scheduled" data-state="live"' . ($live ? '' : ' hidden') . '>'
              . $ico('checkmark') . '<span>Live</span></div>';
        if ($admin) {
            $out .= '<div class="pd-state pd-state--denied" data-state="denied"' . ($key === 'denied' ? '' : ' hidden') . '>'
                  . $ico('xmark') . '<span>Needs changes</span></div>';
            $out .= '<div class="pd-state pd-state--draft" data-state="draft"' . ($key === 'draft' ? '' : ' hidden') . '>'
                  . $ico('page') . '<span>Draft · not visible to the client</span></div>';
        }

        // Action bar
        $out .= '<div class="pd-actions" data-actions>';
        // Client + admin: decide while pending
        $out .= '<div class="ui-btn-group pd-decide" data-state="decide"' . ($key === 'pending' ? '' : ' hidden') . '>'
              . '<button type="button" class="ui-btn ui-btn--large ui-btn--deny ui-btn--tinted" data-decide="denied">Needs changes</button>'
              . '<button type="button" class="ui-btn ui-btn--large ui-btn--approve ui-btn--primary" data-decide="approved">Approve</button>'
              . '</div>';
        if ($admin) {
            // Status control (any non-live page): Draft · To Review · Approved · Needs changes
            $out .= '<div class="pg-status-ctl" data-state="admin-status"' . ($live ? ' hidden' : '') . '>'
                  . '<span class="pg-status-ctl-label">Status</span>'
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
            $out .= '<div class="pg-admin-tools">'
                  . '<a class="ui-btn ui-btn--gray ui-btn--sm" href="' . pgEsc($editUrl) . '" data-edit-page>' . $ico('wand') . 'Edit</a>'
                  . '<button type="button" class="ui-btn ui-btn--gray ui-btn--sm pg-delete" data-delete-page>' . $ico('xmark') . 'Delete</button>'
                  . '</div>';
        }
        $out .= '</div>'; // /.pd-actions
        $out .= '</div>'; // /.pd-footer
        $out .= '</article>';
        return $out;
    }
}
