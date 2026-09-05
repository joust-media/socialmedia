<?php
/**
 * LEGACY — the pre-redesign admin dashboard, kept under legacy/ until Studio
 * (studio.php) reaches parity. The root admin.php now 301s to studio.php.
 *
 * Runs from the legacy/ subfolder: includes are resolved against the app
 * root, and SCRIPT_NAME is normalised first so basePath()/clientUrl()/the
 * shell's static asset URLs, requireAdmin()'s login redirect and the
 * active-tab detection still point at the app root rather than at /legacy/.
 *
 * Admin — client-scoped dashboard.
 *
 * No ?client= → client picker (list of all companies).
 * With ?client=<slug> → dashboard for that client only.
 *   Tiles: Add a Post + Add a Project (universal), plus one tile per
 *   module that the client has enabled in company_modules.
 *   Post list (oldest → newest) scoped to the client, with Mark Posted,
 *   🚀 Post, ⬇ Save, 👁 View, Edit, Delete actions.
 */

if (!empty($_SERVER['SCRIPT_NAME']) && preg_match('#/legacy/[^/]+$#', $_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = preg_replace('#/legacy/([^/]+)$#', '/$1', $_SERVER['SCRIPT_NAME']);
}

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';
require __DIR__ . '/../prompt-lib.php';
require_once __DIR__ . '/../auth.php';

/** Root-rooted URL for an app-relative path ('digest.php', 'legacy/admin.php'). */
if (!function_exists('legacyUrl')) {
    function legacyUrl($path) {
        return basePath() . '/' . ltrim((string)$path, '/');
    }
}

// Single admin gate — everything below assumes a signed-in admin.
requireAdmin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Where the "Post" CTA opens. Same for all clients for now.
$postCtaUrl = 'https://www.facebook.com/kendapowersports';

$errors = [];
$flash  = $_GET['msg'] ?? '';

if (!function_exists('hasPostedColumn')) {   // shared copy lives in helpers.php
    function hasPostedColumn(PDO $pdo) {
        static $cached = null;
        if ($cached !== null) return $cached;
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'posted'
        ");
        $s->execute();
        return $cached = (int)$s->fetchColumn() > 0;
    }
}

// hasActivityLog() lives in helpers.php so other pages (index.php) can share it.

// -------------------------------------------------------------
// Opportunistic digest trigger — fires AFTER the response is
// flushed so the user-visible page render is never blocked.
// digest.php gates itself with a 36h floor + lock, so this is
// a no-op most of the time.
// -------------------------------------------------------------
if (hasActivityLog($pdo)) {
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $scheme  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir     = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
        $url     = $scheme . '://' . $host . $dir . '/digest.php?source=opportunistic';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            @curl_exec($ch);
            curl_close($ch);
        } else {
            @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 30, 'ignore_errors' => true],
            ]));
        }
    });
}

// -------------------------------------------------------------
// POST handler — toggle_posted / save_default_hashtags
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_default_hashtags' && $client) {
        $newTags = trim((string)($_POST['default_hashtags'] ?? ''));
        if (mb_strlen($newTags) > 4000) { $newTags = mb_substr($newTags, 0, 4000); }
        try {
            $pdo->prepare("UPDATE companies SET default_hashtags = ? WHERE id = ?")
                ->execute([$newTags === '' ? null : $newTags, (int)$client['id']]);
            $back = legacyUrl('legacy/admin.php') . '?client=' . urlencode($client['slug'])
                  . '&msg=' . urlencode('Default hashtags saved.');
            header('Location: ' . $back);
            exit;
        } catch (Exception $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }

    if ($action === 'save_client_profile' && $client) {
        if (!hasClientProfileColumns($pdo)) {
            header('Location: ' . legacyUrl('legacy/admin.php') . '?client=' . urlencode($client['slug'])
                . '&msg=' . urlencode('Run migrate first to enable client profile fields.'));
            exit;
        }
        $productType = trim((string)($_POST['product_type'] ?? ''));
        $industry    = trim((string)($_POST['industry'] ?? ''));
        if (mb_strlen($productType) > 120) { $productType = mb_substr($productType, 0, 120); }
        if (mb_strlen($industry) > 120)    { $industry    = mb_substr($industry, 0, 120); }
        try {
            $pdo->prepare("UPDATE companies SET product_type = ?, industry = ? WHERE id = ?")
                ->execute([
                    $productType === '' ? null : $productType,
                    $industry    === '' ? null : $industry,
                    (int)$client['id'],
                ]);
            header('Location: ' . legacyUrl('legacy/admin.php') . '?client=' . urlencode($client['slug'])
                . '&msg=' . urlencode('Client profile saved.'));
            exit;
        } catch (Exception $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }

    if ($action === 'toggle_posted') {
        $postId = (int)($_POST['id'] ?? 0);
        $target = ($_POST['to'] ?? '1') === '1' ? 1 : 0;
        $returnSlug = trim((string)($_POST['return_client'] ?? ''));
        $returnSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($returnSlug));

        if (!hasPostedColumn($pdo)) {
            $back = legacyUrl('legacy/admin.php') . ($returnSlug ? '?client=' . urlencode($returnSlug) : '');
            header('Location: ' . $back . (strpos($back, '?') === false ? '?' : '&')
                . 'msg=' . urlencode('Run migrate first to enable this.'));
            exit;
        }
        if ($postId > 0) {
            try {
                if ($target === 1) {
                    $stmt = $pdo->prepare("UPDATE posts SET posted = 1, posted_at = NOW() WHERE id = ?");
                } else {
                    $stmt = $pdo->prepare("UPDATE posts SET posted = 0, posted_at = NULL WHERE id = ?");
                }
                $stmt->execute([$postId]);

                // Log activity (look up the company so the row is correctly scoped).
                $coStmt = $pdo->prepare("SELECT company_id FROM posts WHERE id = ?");
                $coStmt->execute([$postId]);
                $coId = (int)$coStmt->fetchColumn();
                if ($coId > 0) {
                    logActivity($pdo, $coId, 'post', $postId,
                        $target === 1 ? 'posted' : 'unposted', 'admin',
                        $target === 1 ? "Marked post #{$postId} as posted"
                                      : "Unmarked post #{$postId}");
                }

                $msg = $target === 1 ? 'Marked as posted.' : 'Unmarked as posted.';
                $back = legacyUrl('legacy/admin.php') . ($returnSlug ? '?client=' . urlencode($returnSlug) : '');
                header('Location: ' . $back . (strpos($back, '?') === false ? '?' : '&')
                    . 'msg=' . urlencode($msg));
                exit;
            } catch (Exception $e) {
                $errors[] = 'Toggle failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'set_post_type') {
        $postId     = (int)($_POST['id'] ?? 0);
        $newType    = strtolower(trim((string)($_POST['post_type'] ?? '')));
        $returnSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($_POST['return_client'] ?? ''))));
        $back = legacyUrl('legacy/admin.php') . ($returnSlug ? '?client=' . urlencode($returnSlug) : '');
        $sep  = strpos($back, '?') === false ? '?' : '&';

        if (!hasPostTypeColumn($pdo)) {
            header('Location: ' . $back . $sep . 'msg=' . urlencode('Run migrate first to enable content types.'));
            exit;
        }
        if (!in_array($newType, allowedPostTypes(), true)) {
            header('Location: ' . $back . $sep . 'msg=' . urlencode('Invalid content type.'));
            exit;
        }
        if ($postId > 0) {
            try {
                // Capture previous value for the activity log.
                $prevStmt = $pdo->prepare("SELECT company_id, post_type FROM posts WHERE id = ?");
                $prevStmt->execute([$postId]);
                $prev = $prevStmt->fetch();

                $stmt = $pdo->prepare("UPDATE posts SET post_type = ? WHERE id = ?");
                $stmt->execute([$newType, $postId]);

                $coId = (int)($prev['company_id'] ?? 0);
                if ($coId > 0) {
                    logActivity($pdo, $coId, 'post', $postId, 'type_changed', 'admin',
                        'Content type: ' . ($prev['post_type'] ?? 'post') . ' → ' . $newType);
                }

                header('Location: ' . $back . $sep . 'msg=' . urlencode('Content type set to ' . postTypeLabel($newType) . '.'));
                exit;
            } catch (Exception $e) {
                $errors[] = 'Type change failed: ' . $e->getMessage();
            }
        }
    }
}

// =============================================================
// MODE A — no client selected: show the picker
// =============================================================
if (!$client) {
    // Pull every company + quick counts for display
    $companies = $pdo->query("
        SELECT c.id, c.name, c.slug,
               (SELECT COUNT(*) FROM posts WHERE posts.company_id = c.id) AS post_count,
               (SELECT COUNT(*) FROM tires WHERE tires.company_id = c.id) AS feature_count,
               (SELECT COUNT(*) FROM company_modules cm WHERE cm.company_id = c.id) AS module_count
        FROM companies c
        ORDER BY c.name ASC
    ")->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="light">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Pick a client — Joust Admin</title>
    <?= renderAppHead() ?>
    <style>
      :root { --bg:#f0f2f5; --surface:#fff; --surface-2:#f7f8fa; --border:#dadde1;
              --text:#050505; --text-muted:#65676b; --accent:#1877f2; --accent-hover:#166fe5;
              --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04); }
      [data-theme="dark"] { --bg:#18191a; --surface:#242526; --surface-2:#3a3b3c;
              --border:#3e4042; --text:#e4e6eb; --text-muted:#b0b3b8;
              --accent:#2d88ff; --accent-hover:#4599ff;
              --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3); }
      *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
        font-size:15px;line-height:1.4;min-height:100vh}
      .topbar{position:sticky;top:0;z-index:100;background:var(--surface);
              border-bottom:1px solid var(--border);box-shadow:var(--shadow)}
      .topbar-inner{max-width:900px;margin:0 auto;padding:12px 20px;display:flex;
                    align-items:center;justify-content:space-between;gap:12px}
      .brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:20px;
             color:var(--accent);letter-spacing:-.5px}
      .brand-mark{width:32px;height:32px;border-radius:8px;background:var(--accent);
                  color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}
      .btn{padding:8px 14px;border-radius:8px;font-size:14px;font-weight:600;
           border:1px solid var(--border);background:var(--surface-2);color:var(--text);
           text-decoration:none;display:inline-flex;align-items:center;gap:6px}
      .btn:hover{background:var(--border)}
      .wrap{max-width:900px;margin:0 auto;padding:30px 20px 80px}
      h1{margin:0 0 6px;font-size:26px;letter-spacing:-.3px}
      .subtitle{color:var(--text-muted);margin:0 0 28px}
      .flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;
               background: #dcfce7; color: #166534; border: 1px solid #86efac; }
      [data-theme="dark"] .flash { background: #14532d; color: #bbf7d0; border-color: #166534; }

      .client-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
      .client-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;
                   padding:20px;box-shadow:var(--shadow);text-decoration:none;color:var(--text);
                   transition:transform .15s,border-color .15s,box-shadow .15s;display:flex;
                   flex-direction:column;gap:12px}
      .client-card:hover{transform:translateY(-2px);border-color:var(--accent);
                         box-shadow:0 4px 12px rgba(0,0,0,.12),0 2px 6px rgba(0,0,0,.08)}
      .client-name{font-size:20px;font-weight:700;letter-spacing:-.2px;margin:0}
      .client-slug{font-size:12px;color:var(--text-muted);font-family:ui-monospace,Menlo,monospace}
      .client-counts{display:flex;gap:16px;font-size:13px;color:var(--text-muted);margin-top:auto}
      .client-counts span strong{color:var(--text);font-size:15px;display:block}
      .empty{padding:40px 20px;text-align:center;color:var(--text-muted);
             background:var(--surface);border:1px dashed var(--border);border-radius:12px}

      /* Activity feed (picker mode) */
      .activity-card { background: var(--surface); border: 1px solid var(--border);
                       border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 24px;
                       overflow: hidden; }
      .activity-card-header { padding: 14px 18px; border-bottom: 1px solid var(--border);
                              display: flex; align-items: center; justify-content: space-between;
                              gap: 8px; flex-wrap: wrap; }
      .activity-card-title  { font-size: 16px; font-weight: 700; margin: 0; }
      .activity-list { max-height: 320px; overflow-y: auto; }
      .activity-day  { padding: 8px 18px; font-size: 11px; font-weight: 700;
                       text-transform: uppercase; letter-spacing: 0.5px;
                       color: var(--text-muted); background: var(--surface-2);
                       border-top: 1px solid var(--border); position: sticky; top: 0; }
      .activity-day:first-child { border-top: none; }
      .activity-row  { display: grid; grid-template-columns: auto 1fr auto;
                       gap: 12px; padding: 12px 18px; border-top: 1px solid var(--border);
                       align-items: start; text-decoration: none; color: var(--text); }
      .activity-row:hover { background: var(--surface-2); }
      .activity-actor { font-size: 10px; font-weight: 700; text-transform: uppercase;
                        letter-spacing: 0.4px; padding: 3px 7px; border-radius: 10px;
                        background: var(--surface-2); color: var(--text-muted);
                        border: 1px solid var(--border); white-space: nowrap; }
      .activity-actor-client { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
      .activity-actor-admin  { background: #f3e8ff; color: #6b21a8; border-color: #d8b4fe; }
      [data-theme="dark"] .activity-actor-client { background: #1e3a8a; color: #bfdbfe; border-color: #1e40af; }
      [data-theme="dark"] .activity-actor-admin  { background: #581c87; color: #e9d5ff; border-color: #6b21a8; }
      .activity-text    { font-size: 14px; line-height: 1.5; min-width: 0; }
      .activity-company { font-weight: 700; }
      .activity-verb    { color: var(--text-muted); }
      .activity-target  { color: var(--accent); }
      .activity-detail  { margin-top: 4px; font-size: 13px; color: var(--text-muted);
                          font-style: italic; }
      .activity-time    { font-size: 12px; color: var(--text-muted); white-space: nowrap; }
      .activity-empty   { padding: 24px 18px; text-align: center; color: var(--text-muted); font-size: 14px; }
      .digest-btn       { font-size: 12px; padding: 4px 10px; }
    </style>
    </head>
    <body>
    <?= renderAppChrome('Studio', [
          'subtitle' => 'Choose a client',
          'active'   => 'studio',
          'width'    => '900px',
          'links'    => [
            ['label' => 'Prompt Library',  'href' => legacyUrl('prompts')],
            ['label' => 'Vehicle Library', 'href' => legacyUrl('vehicles')],
            ['label' => 'View site',       'href' => clientUrl('index.php'), 'attrs' => ['target' => '_blank']],
            ['label' => 'Sign out',        'href' => legacyUrl('logout'), 'attrs' => ['title' => 'Signed in as ' . currentAdmin()]],
          ],
        ]) ?>

    <div class="wrap">
      <?php if ($flash): ?><div class="flash">✓ <?= h($flash) ?></div><?php endif; ?>

      <?php if (hasActivityLog($pdo)): ?>
        <div class="activity-card">
          <div class="activity-card-header">
            <h2 class="activity-card-title">📡 Recent activity (all clients)</h2>
            <form method="POST" action="<?= h(legacyUrl('digest.php')) ?>" target="digest_iframe" class="inline-form"
                  onsubmit="setTimeout(()=>this.querySelector('button').textContent='✓ Sent',300);">
              <input type="hidden" name="source" value="manual">
              <button type="submit" class="btn digest-btn" title="Send a digest email to lance@joustmedia.com right now">
                📧 Send digest now
              </button>
            </form>
          </div>
          <div class="activity-list">
            <?= renderActivityFeed($pdo, null, 20) ?>
          </div>
        </div>
        <iframe name="digest_iframe" style="display:none" aria-hidden="true"></iframe>
      <?php endif; ?>

      <h1>Pick a client</h1>
      <p class="subtitle">Each client has their own posts, projects, and modules. Pick one to start.</p>

      <?php if (empty($companies)): ?>
        <div class="empty">
          No clients in the <code>companies</code> table yet. Add one in phpMyAdmin to get started.
        </div>
      <?php else: ?>
        <div class="client-grid">
          <?php foreach ($companies as $c): ?>
            <a class="client-card" href="<?= h(legacyUrl('legacy/admin.php')) ?>?client=<?= h($c['slug']) ?>">
              <h2 class="client-name"><?= h($c['name']) ?></h2>
              <div class="client-slug">/<?= h($c['slug']) ?></div>
              <div class="client-counts">
                <span><strong><?= (int)$c['post_count'] ?></strong>posts</span>
                <span><strong><?= (int)$c['module_count'] ?></strong>modules</span>
                <span><strong><?= (int)$c['feature_count'] ?></strong>items</span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    </body></html>
    <?php
    exit;
}

// =============================================================
// MODE B — client selected: dashboard
// =============================================================

// Quick counts for the Posts tile (scoped)
$s = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE company_id = ?");
$s->execute([$client['id']]);
$totalPosts = (int)$s->fetchColumn();

$s = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE company_id = ? AND status <> 'done'");
$s->execute([$client['id']]);
$openTasks = (int)$s->fetchColumn();

// Modules enabled on this client
$modStmt = $pdo->prepare("
    SELECT m.id, m.slug, m.singular_label, m.plural_label, m.icon,
           (SELECT COUNT(*) FROM tires t
            WHERE t.company_id = ? AND t.module_id = m.id) AS item_count
    FROM company_modules cm
    INNER JOIN modules m ON m.id = cm.module_id
    WHERE cm.company_id = ?
    ORDER BY cm.sort_order, m.plural_label
");
$modStmt->execute([$client['id'], $client['id']]);
$clientModules = $modStmt->fetchAll();

// Posts list — oldest → newest — scoped
$postedCol = hasPostedColumn($pdo) ? 'p.posted, p.posted_at,' : '0 AS posted, NULL AS posted_at,';
// Only reference media_type if the migration has run; otherwise hard-code 0.
$videoCountSubq = hasMediaTypeColumn($pdo)
    ? "(SELECT COUNT(*) FROM post_images pi WHERE pi.post_id = p.id AND pi.media_type = 'video')"
    : "0";
$nameCol = hasPostsNameColumn($pdo) ? 'p.name AS post_name,' : "'' AS post_name,";
$typeCol = hasPostTypeColumn($pdo) ? 'p.post_type,' : "'post' AS post_type,";
$listStmt = $pdo->prepare("
    SELECT p.id, {$nameCol} p.caption, p.hashtags, p.scheduled_date, p.status, p.company_id, p.client_comment,
           $postedCol
           $typeCol
           c.name AS company_name,
           (SELECT COUNT(*) FROM post_images pi WHERE pi.post_id = p.id) AS image_count,
           {$videoCountSubq} AS video_count,
           (SELECT GROUP_CONCAT(image_url ORDER BY sort_order SEPARATOR '|')
            FROM post_images pi WHERE pi.post_id = p.id) AS image_urls,
           (SELECT GROUP_CONCAT(cat.name ORDER BY cat.sort_order SEPARATOR ', ')
            FROM post_categories pc
            INNER JOIN categories cat ON cat.id = pc.category_id
            WHERE pc.post_id = p.id) AS category_names
    FROM posts p
    INNER JOIN companies c ON c.id = p.company_id
    WHERE p.company_id = ?
    ORDER BY p.scheduled_date ASC
");
$listStmt->execute([$client['id']]);
$allPosts = $listStmt->fetchAll();

// Comment dates — one query covers every post on the page.
$commentDates = hasActivityLog($pdo)
    ? latestCommentDates($pdo, 'post', array_column($allPosts, 'id'))
    : [];

$clientQs = 'client=' . urlencode($client['slug']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= h($client['name']) ?> — Studio</title>
<?= renderAppHead() ?>
<style>
  :root { --bg:#f0f2f5; --surface:#fff; --surface-2:#f7f8fa; --border:#dadde1;
          --text:#050505; --text-muted:#65676b;
          --accent:#1877f2; --accent-hover:#166fe5;
          --danger:#dc2626; --success:#16a34a; --warn:#f59e0b;
          --shadow: 0 1px 2px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.04); }
  [data-theme="dark"] { --bg:#18191a; --surface:#242526; --surface-2:#3a3b3c;
          --border:#3e4042; --text:#e4e6eb; --text-muted:#b0b3b8;
          --accent:#2d88ff; --accent-hover:#4599ff;
          --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3); }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text);
         font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         font-size: 15px; line-height: 1.4; min-height: 100vh; }

  .topbar { position: sticky; top: 0; z-index: 100;
            background: var(--surface); border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow); }
  .topbar-inner { max-width: 900px; margin: 0 auto; padding: 12px 20px;
                  display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .brand { display: flex; align-items: center; gap: 10px; font-weight: 700;
           font-size: 20px; color: var(--accent); letter-spacing: -0.5px; }
  .brand-mark { width: 32px; height: 32px; border-radius: 8px; background: var(--accent);
                color: #fff; display: flex; align-items: center; justify-content: center;
                font-weight: 800; }
  .brand-sub { font-size: 12px; font-weight: 600; color: var(--text-muted);
               text-transform: uppercase; letter-spacing: 1px;
               padding: 3px 8px; border-radius: 4px;
               background: var(--surface-2); border: 1px solid var(--border); }
  .top-actions { display: flex; gap: 8px; align-items: center; }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px;
         padding: 8px 14px; border-radius: 8px; font-size: 14px; font-weight: 600;
         cursor: pointer; border: 1px solid var(--border);
         background: var(--surface-2); color: var(--text);
         text-decoration: none; transition: background 0.15s, transform 0.1s; }
  .btn:hover { background: var(--border); } .btn:active { transform: scale(0.98); }
  .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .btn.primary:hover { background: var(--accent-hover); }
  .btn.danger { background: var(--danger); color: #fff; border-color: var(--danger); }
  .btn.success { background: var(--success); color: #fff; border-color: var(--success); }
  .btn.ghost { background: transparent; } .btn.sm { padding: 6px 10px; font-size: 13px; }

  .wrap { max-width: 900px; margin: 0 auto; padding: 24px 20px 80px; }

  .flash, .errors { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
                    font-size: 14px; font-weight: 500; }
  .flash  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .errors { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  [data-theme="dark"] .flash  { background: #14532d; color: #bbf7d0; border-color: #166534; }
  [data-theme="dark"] .errors { background: #7f1d1d; color: #fecaca; border-color: #991b1b; }

  .welcome h1 { font-size: 24px; font-weight: 700; letter-spacing: -0.3px; margin: 0 0 6px; }
  .welcome p  { color: var(--text-muted); margin: 0 0 18px; }
  .tile-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;
               margin-bottom: 36px; }
  @media (min-width: 720px) { .tile-grid { grid-template-columns: repeat(4, 1fr); } }
  .tile { aspect-ratio: 1 / 1; background: var(--surface); border: 1px solid var(--border);
          border-radius: 16px; box-shadow: var(--shadow); padding: 20px;
          display: flex; flex-direction: column; justify-content: space-between;
          text-decoration: none; color: var(--text); position: relative; overflow: hidden;
          transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s; }
  .tile:hover { transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.12), 0 2px 6px rgba(0,0,0,0.08);
                border-color: var(--accent); }
  .tile-icon { font-size: 40px; line-height: 1; }
  .tile-title { font-size: 19px; font-weight: 700; letter-spacing: -0.2px; margin: 0 0 4px; }
  .tile-meta { font-size: 13px; color: var(--text-muted); }
  .tile-badge { position: absolute; top: 14px; right: 14px;
                min-width: 26px; height: 26px; padding: 0 8px;
                border-radius: 13px; background: var(--accent); color: #fff;
                font-size: 13px; font-weight: 700;
                display: flex; align-items: center; justify-content: center; }
  .tile-badge.zero { background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); }

  .card { background: var(--surface); border: 1px solid var(--border);
          border-radius: 12px; box-shadow: var(--shadow);
          margin-bottom: 24px; overflow: hidden; }
  .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border);
                 display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .card-title { font-size: 17px; font-weight: 700; margin: 0; }

  .post-list { display: flex; flex-direction: column; }
  .post-row { display: grid; grid-template-columns: 1.2fr 1.6fr auto auto auto auto;
              gap: 14px; padding: 14px 20px; border-top: 1px solid var(--border);
              align-items: center; transition: opacity 0.2s, background 0.2s; }
  .post-row:first-child { border-top: none; }
  .post-row.is-posted { opacity: 0.45; background: var(--surface-2); }
  .post-row.is-posted .caption-preview { text-decoration: line-through; }
  /* Hide posted rows by default — header toggle reveals them. */
  .post-list.hide-posted .post-row.is-posted { display: none; }
  .toggle-posted-btn {
    font-size: 12px; padding: 4px 10px;
    background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);
    border-radius: 6px; cursor: pointer; font-weight: 600;
  }
  .toggle-posted-btn:hover { background: var(--border); color: var(--text); }
  .toggle-posted-btn.showing { background: var(--accent); color: #fff; border-color: var(--accent); }
  .post-row.is-posted-only-shown {}
  .post-row .meta-date    { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
  .post-row .post-name-tag {
    font-size: 14px; font-weight: 700; color: var(--text);
    letter-spacing: -0.1px; margin-bottom: 4px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .post-row .caption-preview { color: var(--text-muted); font-size: 14px;
                               overflow: hidden; display: -webkit-box;
                               -webkit-line-clamp: 2; -webkit-box-orient: vertical; text-overflow: ellipsis; }
  .cat-list { font-size: 12px; color: var(--text-muted); margin-top: 3px; font-style: italic; }
  .pill { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
          padding: 4px 10px; border-radius: 20px;
          background: var(--surface-2); color: var(--text-muted); white-space: nowrap; }
  .pill.pending  { background: #fef3c7; color: #92400e; }
  .pill.approved { background: #dcfce7; color: #166534; }
  .pill.denied   { background: #fee2e2; color: #991b1b; }
  .pill.posted   { background: #e0e7ff; color: #3730a3; }
  [data-theme="dark"] .pill.pending  { background: #78350f; color: #fde68a; }
  [data-theme="dark"] .pill.approved { background: #14532d; color: #bbf7d0; }
  [data-theme="dark"] .pill.denied   { background: #7f1d1d; color: #fecaca; }
  [data-theme="dark"] .pill.posted   { background: #312e81; color: #c7d2fe; }

  .img-count { font-size: 12px; color: var(--text-muted); white-space: nowrap; }
  .row-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
  .inline-form { display: inline; }
  .empty { padding: 40px 20px; text-align: center; color: var(--text-muted); }

  .toast { position: fixed; left: 50%; bottom: 32px;
           transform: translateX(-50%) translateY(12px);
           background: #050505; color: #fff;
           padding: 10px 18px; border-radius: 24px;
           font-size: 14px; font-weight: 600;
           box-shadow: 0 8px 24px rgba(0,0,0,0.25);
           opacity: 0; pointer-events: none;
           transition: opacity 0.18s, transform 0.18s; z-index: 1000; }
  .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
  [data-theme="dark"] .toast { background: #e4e6eb; color: #050505; }

  .client-switch { font-size: 12px; color: var(--text-muted); }
  .client-switch a { color: var(--accent); text-decoration: none; }
  .client-switch a:hover { text-decoration: underline; }

  /* Collapsible default-hashtags editor */
  .hashtag-card { padding: 0; }
  .hashtag-card summary {
    list-style: none; cursor: pointer; padding: 14px 20px;
    display: flex; gap: 14px; align-items: baseline; flex-wrap: wrap;
    border-bottom: 1px solid transparent;
  }
  .hashtag-card[open] summary { border-bottom-color: var(--border); }
  .hashtag-card summary::-webkit-details-marker { display: none; }
  .hashtag-card summary::after {
    content: '▾'; margin-left: auto; color: var(--text-muted);
    font-size: 13px; transition: transform 0.15s;
  }
  .hashtag-card[open] summary::after { transform: rotate(180deg); }
  .hashtag-summary-title { font-size: 15px; font-weight: 700; }
  .hashtag-summary-meta {
    font-size: 13px; color: var(--text-muted);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    flex: 1; min-width: 200px;
  }
  .hashtag-card .card-body { padding: 16px 20px 20px; }
  .hashtag-card .field { display: flex; flex-direction: column; gap: 6px; }
  .hashtag-card .field label {
    font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    color: var(--text-muted);
  }
  .hashtag-card textarea {
    width: 100%; background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text); padding: 10px 12px; border-radius: 8px;
    font: inherit; font-size: 14px; resize: vertical; line-height: 1.5;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  }
  .hashtag-card textarea:focus {
    outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .hashtag-card input[type="text"] {
    width: 100%; background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text); padding: 10px 12px; border-radius: 8px;
    font: inherit; font-size: 14px;
  }
  .hashtag-card input[type="text"]:focus {
    outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(24,119,242,0.15);
  }
  .hashtag-card .field + .field { margin-top: 14px; }
  .hashtag-card code {
    background: var(--surface-2); border-radius: 4px; padding: 1px 5px;
    font-size: 12px; color: var(--accent);
  }
  .hashtag-card .help { font-size: 12px; color: var(--text-muted); }
  .hashtag-card .form-actions {
    display: flex; justify-content: flex-end; margin-top: 12px;
  }

  /* Activity feed */
  .activity-card { margin-bottom: 24px; }
  .activity-card .card-header { gap: 8px; flex-wrap: wrap; }
  .activity-list { max-height: 360px; overflow-y: auto; }
  .activity-day  { padding: 8px 20px; font-size: 11px; font-weight: 700;
                   text-transform: uppercase; letter-spacing: 0.5px;
                   color: var(--text-muted); background: var(--surface-2);
                   border-top: 1px solid var(--border); position: sticky; top: 0; }
  .activity-day:first-child { border-top: none; }
  .activity-row  { display: grid; grid-template-columns: auto 1fr auto;
                   gap: 12px; padding: 12px 20px; border-top: 1px solid var(--border);
                   align-items: start; text-decoration: none; color: var(--text);
                   transition: background 0.15s; }
  .activity-row:hover { background: var(--surface-2); }
  .activity-actor { font-size: 10px; font-weight: 700; text-transform: uppercase;
                    letter-spacing: 0.4px; padding: 3px 7px; border-radius: 10px;
                    background: var(--surface-2); color: var(--text-muted);
                    border: 1px solid var(--border); white-space: nowrap; }
  .activity-actor-client { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
  .activity-actor-admin  { background: #f3e8ff; color: #6b21a8; border-color: #d8b4fe; }
  [data-theme="dark"] .activity-actor-client { background: #1e3a8a; color: #bfdbfe; border-color: #1e40af; }
  [data-theme="dark"] .activity-actor-admin  { background: #581c87; color: #e9d5ff; border-color: #6b21a8; }
  .activity-text    { font-size: 14px; line-height: 1.5; min-width: 0; }
  .activity-company { font-weight: 700; }
  .activity-verb    { color: var(--text-muted); }
  .activity-target  { color: var(--accent); }
  .activity-detail  { margin-top: 4px; font-size: 13px; color: var(--text-muted);
                      font-style: italic; overflow: hidden;
                      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
  .activity-time    { font-size: 12px; color: var(--text-muted); white-space: nowrap; }
  .activity-empty   { padding: 24px 20px; text-align: center; color: var(--text-muted); font-size: 14px; }
  .digest-btn       { font-size: 12px; padding: 4px 10px; }
  .post-comment     { margin-top: 6px; padding: 8px 10px; background: var(--surface-2);
                      border-left: 3px solid var(--accent); border-radius: 4px;
                      font-size: 13px; color: var(--text); }
  .post-comment-date { font-size: 11px; color: var(--text-muted); font-weight: 600;
                       margin-right: 6px; }

  @media (max-width: 720px) {
    .post-row { grid-template-columns: 1fr; gap: 8px; }
    .row-actions { justify-content: flex-start; }
    .activity-row { grid-template-columns: auto 1fr; }
    .activity-time { grid-column: 1 / -1; padding-left: 32px; }
  }
</style>
</head>
<body>

<?= renderAppChrome('Studio', [
      'subtitle' => $client['name'],
      'active'   => 'studio',
      'width'    => '900px',
      'links'    => [
        ['label' => 'Switch client',   'href' => legacyUrl('legacy/admin.php')],
        ['label' => 'Prompt Library',  'href' => legacyUrl('prompts')],
        ['label' => 'Vehicle Library', 'href' => legacyUrl('vehicles')],
        ['label' => 'View site',       'href' => clientUrl('index.php'), 'attrs' => ['target' => '_blank']],
        ['label' => 'Sign out',        'href' => legacyUrl('logout'), 'attrs' => ['title' => 'Signed in as ' . currentAdmin()]],
      ],
    ]) ?>

<div class="wrap">

  <?php if ($flash): ?>
    <div class="flash">✓ <?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errors">
      <?php foreach ($errors as $err): ?><div>⚠ <?= h($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!hasPostedColumn($pdo)): ?>
    <div class="errors">
      ⚠ Database needs a one-time update. Visit
      <a href="<?= h(legacyUrl('migrate')) ?>" style="color:inherit;text-decoration:underline;">migrate</a>
      to add the new columns, then come back.
    </div>
  <?php endif; ?>

  <?php if (hasActivityLog($pdo)): ?>
    <div class="card activity-card">
      <div class="card-header">
        <h2 class="card-title">📡 Recent activity</h2>
        <form method="POST" action="<?= h(legacyUrl('digest.php')) ?>" target="digest_iframe" class="inline-form"
              onsubmit="setTimeout(()=>this.querySelector('button').textContent='✓ Sent',300);">
          <input type="hidden" name="source" value="manual">
          <button type="submit" class="btn sm digest-btn" title="Send a digest email to lance@joustmedia.com right now">
            📧 Send digest now
          </button>
        </form>
      </div>
      <div class="activity-list">
        <?= renderActivityFeed($pdo, $client['id'], 20) ?>
      </div>
    </div>
    <iframe name="digest_iframe" style="display:none" aria-hidden="true"></iframe>
  <?php endif; ?>

  <div class="welcome">
    <h1>What would you like to do?</h1>
    <p class="client-switch">
      Working in <strong><?= h($client['name']) ?></strong>. Not the right one?
      <a href="<?= h(legacyUrl('legacy/admin.php')) ?>">Switch client</a>.
    </p>
  </div>

  <details class="card hashtag-card" <?= empty($client['default_hashtags']) ? 'open' : '' ?>>
    <summary class="hashtag-summary">
      <span class="hashtag-summary-title">🏷 Default hashtags for <?= h($client['name']) ?></span>
      <span class="hashtag-summary-meta">
        <?= !empty($client['default_hashtags'])
              ? mb_strimwidth((string)$client['default_hashtags'], 0, 80, '…')
              : 'None set yet — click to add' ?>
      </span>
    </summary>
    <div class="card-body">
      <form method="POST" action="<?= h(legacyUrl('legacy/admin.php')) ?>?<?= h($clientQs) ?>">
        <input type="hidden" name="action" value="save_default_hashtags">
        <div class="field">
          <label for="default_hashtags">
            Applied to every new post for this client
          </label>
          <textarea name="default_hashtags" id="default_hashtags"
                    placeholder="#Brand #Campaign #Keyword"
                    rows="3"><?= h($client['default_hashtags'] ?? '') ?></textarea>
          <span class="help">
            New posts pre-fill with these. On existing posts, you'll see a one-click
            "Append client defaults" button on the edit form.
          </span>
        </div>
        <div class="form-actions" style="border-top: none; padding-top: 4px;">
          <button type="submit" class="btn primary">Save defaults</button>
        </div>
      </form>
    </div>
  </details>

  <?php if (hasClientProfileColumns($pdo)): ?>
  <details class="card hashtag-card"
           <?= (empty($client['product_type']) && empty($client['industry'])) ? 'open' : '' ?>>
    <summary class="hashtag-summary">
      <span class="hashtag-summary-title">🎬 AI Builder profile for <?= h($client['name']) ?></span>
      <span class="hashtag-summary-meta">
        <?php
          $profileBits = [];
          if (!empty($client['product_type'])) { $profileBits[] = 'Product: ' . $client['product_type']; }
          if (!empty($client['industry']))     { $profileBits[] = 'Industry: ' . $client['industry']; }
          echo $profileBits
                ? h(implode('  ·  ', $profileBits))
                : 'Not set — feeds the {{product_type}} & {{industry}} prompt variables';
        ?>
      </span>
    </summary>
    <div class="card-body">
      <form method="POST" action="<?= h(legacyUrl('legacy/admin.php')) ?>?<?= h($clientQs) ?>">
        <input type="hidden" name="action" value="save_client_profile">
        <div class="field">
          <label for="product_type">Product type</label>
          <input type="text" name="product_type" id="product_type" maxlength="120"
                 value="<?= h($client['product_type'] ?? '') ?>"
                 placeholder="e.g. tires, apparel, software">
          <span class="help">Fills the <code>{{product_type}}</code> variable in AI prompts.</span>
        </div>
        <div class="field">
          <label for="industry">Industry</label>
          <input type="text" name="industry" id="industry" maxlength="120"
                 value="<?= h($client['industry'] ?? '') ?>"
                 placeholder="e.g. powersports, retail, SaaS">
          <span class="help">Fills the <code>{{industry}}</code> variable in AI prompts.</span>
        </div>
        <div class="form-actions" style="border-top: none; padding-top: 4px;">
          <button type="submit" class="btn primary">Save profile</button>
        </div>
      </form>
    </div>
  </details>
  <?php endif; ?>

  <div class="tile-grid">
    <!-- Universal tiles -->
    <a class="tile" href="<?= h(legacyUrl('add-post')) ?>?<?= h($clientQs) ?>">
      <div class="tile-badge <?= $totalPosts === 0 ? 'zero' : '' ?>"><?= $totalPosts ?></div>
      <div class="tile-icon">📰</div>
      <div>
        <div class="tile-title">Add a Post</div>
        <div class="tile-meta"><?= $totalPosts === 1 ? '1 post' : $totalPosts . ' posts' ?></div>
      </div>
    </a>

    <a class="tile" href="<?= h(clientUrl('projects.php')) ?>">
      <div class="tile-badge <?= $openTasks === 0 ? 'zero' : '' ?>"><?= $openTasks ?></div>
      <div class="tile-icon">📋</div>
      <div>
        <div class="tile-title">Add a Project</div>
        <div class="tile-meta">
          <?= $openTasks === 0 ? 'No open tasks' : ($openTasks === 1 ? '1 open task' : $openTasks . ' open tasks') ?>
        </div>
      </div>
    </a>

    <!-- AI Builder (universal) -->
    <a class="tile" href="<?= h(legacyUrl('build')) ?>?<?= h($clientQs) ?>">
      <div class="tile-icon">🎨</div>
      <div>
        <div class="tile-title">AI Builder</div>
        <div class="tile-meta">Compose a prompt &amp; gather refs</div>
      </div>
    </a>

    <!-- Dynamic module tiles -->
    <?php foreach ($clientModules as $mod): ?>
      <a class="tile" href="<?= h(legacyUrl('add-feature')) ?>?<?= h($clientQs) ?>&module=<?= h($mod['slug']) ?>">
        <div class="tile-badge <?= (int)$mod['item_count'] === 0 ? 'zero' : '' ?>">
          <?= (int)$mod['item_count'] ?>
        </div>
        <div class="tile-icon"><?= h($mod['icon']) ?></div>
        <div>
          <div class="tile-title">Add <?= h(preg_match('/^[aeiouAEIOU]/', $mod['singular_label']) ? 'an' : 'a') ?> <?= h($mod['singular_label']) ?></div>
          <div class="tile-meta">
            <?= (int)$mod['item_count'] === 1
                  ? '1 ' . h(strtolower($mod['singular_label']))
                  : (int)$mod['item_count'] . ' ' . h(strtolower($mod['plural_label'])) ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>

    <?php if (empty($clientModules)): ?>
      <div class="tile" style="cursor:default;opacity:.7">
        <div class="tile-icon">➕</div>
        <div>
          <div class="tile-title">No modules yet</div>
          <div class="tile-meta">
            Add a row to <code>company_modules</code> to enable one.
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php
    $postedCount    = 0;
    $unpostedCount  = 0;
    foreach ($allPosts as $_p) { empty($_p['posted']) ? $unpostedCount++ : $postedCount++; }
  ?>
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">
        <?= h($client['name']) ?>'s posts
        (<span id="postCountVisible"><?= $unpostedCount ?></span><?php if ($postedCount): ?> of <?= count($allPosts) ?><?php endif; ?>)
        — oldest first
      </h2>
      <?php if ($postedCount > 0): ?>
        <button type="button" class="toggle-posted-btn" id="togglePostedBtn"
                data-shown="0"
                data-hidden-count="<?= $postedCount ?>"
                data-total-count="<?= count($allPosts) ?>"
                data-unposted-count="<?= $unpostedCount ?>">
          👁 Show all (+<?= $postedCount ?> posted)
        </button>
      <?php endif; ?>
    </div>
    <?php if (empty($allPosts)): ?>
      <div class="empty">No posts for <?= h($client['name']) ?> yet. Click "Add a Post" to create one.</div>
    <?php else: ?>
      <div class="post-list hide-posted" id="postList">
        <?php foreach ($allPosts as $p):
          $isPosted = !empty($p['posted']);
          $feedMonth = date('Y-m', strtotime($p['scheduled_date']));
        ?>
          <div class="post-row <?= $isPosted ? 'is-posted' : '' ?>" id="post-<?= (int)$p['id'] ?>">
            <div>
              <div class="meta-company"><?= h($p['company_name']) ?></div>
              <div class="meta-date">
                <?= h(date('M j, Y · g:i A', strtotime($p['scheduled_date']))) ?>
                <?php if ($isPosted && !empty($p['posted_at'])): ?>
                  · <span title="Marked posted" style="color:var(--success);">posted <?= h(date('M j', strtotime($p['posted_at']))) ?></span>
                <?php endif; ?>
              </div>
              <div class="cat-list"><?= h($p['category_names'] ?? '') ?></div>
            </div>
            <div>
              <?php if (!empty($p['post_name'])): ?>
                <div class="post-name-tag" title="Reference name"><?= h($p['post_name']) ?></div>
              <?php endif; ?>
              <div class="caption-preview"><?= h($p['caption']) ?></div>
              <?php if (!empty($p['client_comment'])): ?>
                <div class="post-comment">
                  <span class="post-comment-date" title="<?= h(absoluteTime($commentDates[(int)$p['id']] ?? null)) ?>">
                    💬 <?= h(relativeTime($commentDates[(int)$p['id']] ?? null) ?: '—') ?>
                  </span>
                  <?= h($p['client_comment']) ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="img-count">
              <?php
                $imgN = (int)$p['image_count'];
                $vidN = (int)($p['video_count'] ?? 0);
                $picN = $imgN - $vidN;
              ?>
              <?php if ($picN > 0): ?>🖼 <?= $picN ?><?php endif; ?>
              <?php if ($vidN > 0): ?> 🎬 <?= $vidN ?><?php endif; ?>
              <?php if ($imgN === 0): ?>—<?php endif; ?>
            </div>
            <div>
              <?php if ($isPosted): ?>
                <span class="pill posted">Posted</span>
              <?php else: ?>
                <span class="pill <?= h($p['status']) ?>"><?= h($p['status']) ?></span>
              <?php endif; ?>
              <?php if (hasPostTypeColumn($pdo)):
                $pt = strtolower((string)($p['post_type'] ?? 'post'));
              ?>
                <form method="POST" action="<?= h(legacyUrl('legacy/admin.php')) ?>?<?= h($clientQs) ?>" class="inline-form" style="display:inline;margin-left:4px;">
                  <input type="hidden" name="action" value="set_post_type">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="return_client" value="<?= h($client['slug']) ?>">
                  <input type="hidden" name="actor" value="admin">
                  <select name="post_type" title="Change content type" onchange="this.form.submit()"
                          style="font-size:12px;padding:2px 6px;border-radius:10px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-muted);cursor:pointer;">
                    <option value="post"  <?= $pt === 'post'  ? 'selected' : '' ?>>📄 Post</option>
                    <option value="story" <?= $pt === 'story' ? 'selected' : '' ?>>⭕ Story</option>
                    <option value="reel"  <?= $pt === 'reel'  ? 'selected' : '' ?>>🎬 Reel</option>
                  </select>
                </form>
              <?php endif; ?>
            </div>
            <div class="row-actions">
              <?php if (!$isPosted): ?>
                <button type="button" class="btn sm primary"
                        data-post-cta
                        data-caption="<?= h($p['caption']) ?>"
                        data-hashtags="<?= h($p['hashtags']) ?>"
                        title="Copies caption + hashtags and opens Facebook in a new tab">
                  🚀 Post
                </button>
              <?php endif; ?>
              <?php if ((int)$p['image_count'] > 0): ?>
                <button type="button" class="btn sm"
                        data-save-images
                        data-urls="<?= h($p['image_urls'] ?? '') ?>"
                        data-company="<?= h($p['company_name']) ?>"
                        data-post-id="<?= (int)$p['id'] ?>"
                        title="Download <?= (int)$p['image_count'] ?> image<?= (int)$p['image_count'] === 1 ? '' : 's' ?>">
                  ⬇ Save<?= (int)$p['image_count'] > 1 ? ' (' . (int)$p['image_count'] . ')' : '' ?>
                </button>
              <?php endif; ?>
              <a class="btn sm"
                 href="<?= h(clientUrl('posts.php', ['post' => (int)$p['id']])) ?>"
                 target="_blank"
                 title="Open this post in Posts">
                👁 View
              </a>
              <?php if (hasPostedColumn($pdo)): ?>
                <form method="POST" action="<?= h(legacyUrl('legacy/admin.php')) ?>?<?= h($clientQs) ?>" class="inline-form">
                  <input type="hidden" name="action" value="toggle_posted">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="to" value="<?= $isPosted ? '0' : '1' ?>">
                  <input type="hidden" name="return_client" value="<?= h($client['slug']) ?>">
                  <input type="hidden" name="actor" value="admin">
                  <?php if ($isPosted): ?>
                    <button type="submit" class="btn sm">↺ Unmark</button>
                  <?php else: ?>
                    <button type="submit" class="btn sm success">✓ Mark posted</button>
                  <?php endif; ?>
                </form>
              <?php endif; ?>
              <a class="btn sm" href="<?= h(legacyUrl('add-post')) ?>?<?= h($clientQs) ?>&edit=<?= (int)$p['id'] ?>">Edit</a>
              <form method="POST" action="<?= h(legacyUrl('add-post')) ?>?<?= h($clientQs) ?>" class="inline-form"
                    onsubmit="return confirm('Delete this post and all its images? This cannot be undone.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="actor" value="admin">
                <button type="submit" class="btn sm danger">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<div class="toast" id="toast">Copied!</div>

<script>
  // Show / hide posted-rows toggle on the post list
  const toggleBtn = document.getElementById('togglePostedBtn');
  const postList  = document.getElementById('postList');
  if (toggleBtn && postList) {
    toggleBtn.addEventListener('click', () => {
      const showing = toggleBtn.dataset.shown === '1';
      const next    = showing ? '0' : '1';
      toggleBtn.dataset.shown = next;
      postList.classList.toggle('hide-posted', next === '0');
      toggleBtn.classList.toggle('showing', next === '1');
      const hidden    = parseInt(toggleBtn.dataset.hiddenCount, 10) || 0;
      const total     = parseInt(toggleBtn.dataset.totalCount, 10) || 0;
      const unposted  = parseInt(toggleBtn.dataset.unpostedCount, 10) || 0;
      toggleBtn.textContent = next === '1'
        ? '🙈 Hide ' + hidden + ' posted'
        : '👁 Show all (+' + hidden + ' posted)';
      const visibleEl = document.getElementById('postCountVisible');
      if (visibleEl) visibleEl.textContent = next === '1' ? total : unposted;
    });
  }

  // Follow the system appearance; the shared tokens and this page's inline
  // [data-theme="dark"] palette both key off the same attribute.
  const themeMQ = window.matchMedia('(prefers-color-scheme: dark)');
  const applyTheme = () => document.documentElement.setAttribute('data-theme', themeMQ.matches ? 'dark' : 'light');
  applyTheme();
  if (themeMQ.addEventListener) themeMQ.addEventListener('change', applyTheme);

  const POST_CTA_URL = <?= json_encode($postCtaUrl) ?>;
  const toastEl = document.getElementById('toast');
  let toastTimer;
  function showToast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1800);
  }

  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      try { await navigator.clipboard.writeText(text); return true; } catch {}
    }
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
    document.body.appendChild(ta); ta.select();
    let ok = false; try { ok = document.execCommand('copy'); } catch {}
    document.body.removeChild(ta); return ok;
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-post-cta]');
    if (!btn) return;
    e.preventDefault();
    const caption  = btn.getAttribute('data-caption')  || '';
    const hashtags = btn.getAttribute('data-hashtags') || '';
    const text = (caption + (hashtags ? '\n\n' + hashtags : '')).trim();
    window.open(POST_CTA_URL, '_blank', 'noopener');
    const ok = await copyText(text);
    showToast(ok ? '📋 Caption + hashtags copied' : 'Copy failed — please copy manually');
  });

  async function downloadOne(url, filename) {
    try {
      const res  = await fetch(url, { mode: 'cors' });
      const blob = await res.blob();
      const obj  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href = obj; a.download = filename;
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      URL.revokeObjectURL(obj);
      return true;
    } catch {
      window.open(url, '_blank');
      return false;
    }
  }
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-save-images]');
    if (!btn) return;
    e.preventDefault();
    const urls = (btn.getAttribute('data-urls') || '').split('|').filter(Boolean);
    if (!urls.length) { showToast('No images to save'); return; }
    const company = (btn.getAttribute('data-company') || 'post').replace(/[^a-zA-Z0-9\-]/g, '-');
    const postId  = btn.getAttribute('data-post-id') || '0';
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = urls.length > 1 ? `⏳ Saving 0/${urls.length}…` : '⏳ Saving…';
    let ok = 0;
    for (let i = 0; i < urls.length; i++) {
      const url = urls[i];
      const extMatch = url.match(/\.(jpe?g|png|gif|webp|mp4|webm|mov|m4v)(\?|$)/i);
      const ext = extMatch ? extMatch[1].toLowerCase().replace('jpeg', 'jpg') : 'jpg';
      const name = `${company}-${postId}-${i + 1}.${ext}`;
      if (await downloadOne(url, name)) ok++;
      if (urls.length > 1) {
        btn.textContent = `⏳ Saving ${ok}/${urls.length}…`;
        await new Promise(r => setTimeout(r, 250));
      }
    }
    btn.disabled = false;
    btn.textContent = originalText;
    showToast(ok === urls.length
      ? `⬇ Saved ${ok} image${ok === 1 ? '' : 's'}`
      : `Saved ${ok} of ${urls.length} — opened the rest in tabs`);
  });
</script>

</body>
</html>
