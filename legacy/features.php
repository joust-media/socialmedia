<?php
/**
 * Generic client-scoped feature gallery.
 * URL: features.php?client=<slug>&module=<slug>
 *
 * Accepts legacy ?tire=<id> (for old bookmarks) and redirects to ?item=<id>.
 */

// Moved to legacy/ by the Assets phase. The root features.php is now a router:
// module=tires 301s to assets.php?view=collections; every other module is
// served by require-ing this file, so SCRIPT_NAME stays /features.php and all
// relative URLs keep working. If this file is ever served directly from
// /legacy/, normalise SCRIPT_NAME so basePath()/clientUrl()/staticUrl() and the
// tab-bar active detection still resolve against the app root.
if (!empty($_SERVER['SCRIPT_NAME']) && preg_match('#/legacy/[^/]+$#', $_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = preg_replace('#/legacy/([^/]+)$#', '/$1', $_SERVER['SCRIPT_NAME']);
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

// The tires module (module=tires, no module, or the legacy ?tire= alias) is the
// Collections view of the unified Assets page — 301 there for everyone, exactly
// like the root features.php router does. Only other modules render below.
$moduleSlugEarly = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_GET['module'] ?? 'tires')));
if ($moduleSlugEarly === '' || $moduleSlugEarly === 'tires') {
    $extra = ['view' => 'collections'];
    $item  = isset($_GET['item']) ? (int)$_GET['item'] : (isset($_GET['tire']) ? (int)$_GET['tire'] : 0);
    if ($item > 0) { $extra['item'] = $item; }
    header('Location: ' . clientUrl('assets.php', $extra), true, 301);
    exit;
}
unset($moduleSlugEarly);

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Role-safe rendering (spec §7/§9): a client seat never receives denied items.
$legacyDeniedFilter = isAdmin() ? '' : " AND status <> 'denied'";

// --- Require a client ---------------------------------------------
if (!$client) {
    http_response_code(400);
    echo '<p style="font:15px sans-serif;padding:40px;">
            Missing <code>?client=&lt;slug&gt;</code>. Go to
            <a href="' . h(basePath()) . '/admin">the admin picker</a>.
          </p>';
    exit;
}

// --- Resolve the module ------------------------------------------
$moduleSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_GET['module'] ?? 'tires')));
if ($moduleSlug === '') { $moduleSlug = 'tires'; }

$stmt = $pdo->prepare("SELECT id, slug, singular_label, plural_label, icon FROM modules WHERE slug = ?");
$stmt->execute([$moduleSlug]);
$module = $stmt->fetch();
if (!$module) {
    http_response_code(404);
    echo '<p style="font:15px sans-serif;padding:40px;">
            Module <code>' . h($moduleSlug) . '</code> does not exist.
          </p>';
    exit;
}

// Confirm this client has access to this module
$hasModule = $pdo->prepare("
    SELECT 1 FROM company_modules WHERE company_id = ? AND module_id = ? LIMIT 1
");
$hasModule->execute([$client['id'], $module['id']]);
if (!$hasModule->fetchColumn()) {
    http_response_code(403);
    echo '<p style="font:15px sans-serif;padding:40px;">
            <strong>' . h($client['name']) . '</strong> does not have the
            <strong>' . h($module['plural_label']) . '</strong> module enabled.
          </p>';
    exit;
}

// Legacy redirect: ?tire=<id>  →  ?item=<id>
if (!isset($_GET['item']) && isset($_GET['tire'])) {
    $tireId = (int)$_GET['tire'];
    $qs = ['client' => $client['slug'], 'module' => $module['slug'], 'item' => $tireId];
    header('Location: ' . basePath() . '/features?' . http_build_query($qs));
    exit;
}

// --- Categories that have at least one item in this module/client ---
$catStmt = $pdo->prepare("
    SELECT c.id, c.name
    FROM categories c
    INNER JOIN tire_categories tc ON tc.category_id = c.id
    INNER JOIN tires t ON t.id = tc.tire_id
    WHERE t.company_id = ? AND t.module_id = ?
    GROUP BY c.id, c.name, c.sort_order
    ORDER BY c.sort_order, c.name
");
$catStmt->execute([$client['id'], $module['id']]);
$allCategories = $catStmt->fetchAll();

// Build per-tire approval map for this module so pills can flag fully-approved tires.
$tireApprovedMap = [];
$apprStmt = $pdo->prepare("
    SELECT t.id AS tire_id,
           SUM(CASE WHEN ti.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
           COUNT(ti.id) AS total_count
      FROM tires t
      LEFT JOIN tire_images ti ON ti.tire_id = t.id
     WHERE t.company_id = ? AND t.module_id = ?
     GROUP BY t.id
");
$apprStmt->execute([$client['id'], $module['id']]);
foreach ($apprStmt->fetchAll() as $r) {
    $total = (int)$r['total_count'];
    $appr  = (int)$r['approved_count'];
    $tireApprovedMap[(int)$r['tire_id']] = ($total > 0 && $appr === $total);
}

// Build grouped structure
$itemsByCategory = [];
foreach ($allCategories as $cat) {
    $s = $pdo->prepare("
        SELECT t.id, t.name
        FROM tires t
        INNER JOIN tire_categories tc ON tc.tire_id = t.id
        WHERE tc.category_id = ?
          AND t.company_id = ?
          AND t.module_id  = ?
        ORDER BY t.name ASC
    ");
    $s->execute([$cat['id'], $client['id'], $module['id']]);
    $itemsByCategory[(int)$cat['id']] = $s->fetchAll();
}

// Uncategorized items for this client+module
$uncatStmt = $pdo->prepare("
    SELECT t.id, t.name FROM tires t
    WHERE t.company_id = ?
      AND t.module_id  = ?
      AND t.id NOT IN (SELECT DISTINCT tire_id FROM tire_categories)
    ORDER BY t.name ASC
");
$uncatStmt->execute([$client['id'], $module['id']]);
$uncategorized = $uncatStmt->fetchAll();

// Selected item — only when ?item=<id> is explicit. Otherwise we render the gallery.
$selectedId = isset($_GET['item']) ? (int)$_GET['item'] : 0;

// View mode: 'gallery' shows everything; 'detail' shows one tire's approval workflow.
$viewMode = $selectedId > 0 ? 'detail' : 'gallery';

// Detect once whether the timestamp columns exist (used by both detail + gallery views).
$hasUpdatedTires  = $pdo->query("SHOW COLUMNS FROM tires LIKE 'updated_at'")->rowCount() > 0;
$hasUpdatedImages = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'updated_at'")->rowCount() > 0;

// Detail mode: load the single selected item + its images
$selectedItem = null;
$images = [];
if ($viewMode === 'detail') {
    $s = $pdo->prepare("
        SELECT id, name FROM tires
        WHERE id = ? AND company_id = ? AND module_id = ?
    ");
    $s->execute([$selectedId, $client['id'], $module['id']]);
    $selectedItem = $s->fetch();

    if ($selectedItem) {
        $updatedColSel = $hasUpdatedImages ? ', updated_at' : '';
        $hasDisplayName = $pdo->query("SHOW COLUMNS FROM tire_images LIKE 'display_name'")->rowCount() > 0;
        $nameSel = $hasDisplayName ? ', display_name' : ", '' AS display_name";
        $imgStmt = $pdo->prepare("
            SELECT id, image_url, caption, status, client_comment{$updatedColSel}{$nameSel}
            FROM tire_images
            WHERE tire_id = ?{$legacyDeniedFilter}
            ORDER BY sort_order ASC, id ASC
        ");
        $imgStmt->execute([$selectedId]);
        $images = $imgStmt->fetchAll();

        // Latest comment date per image — used to label each comment box.
        $imageCommentDates = function_exists('latestCommentDates')
            ? latestCommentDates($pdo, 'tire_image', array_column($images, 'id'))
            : [];
    } else {
        // Item doesn't belong to this client/module — fall back to gallery
        $viewMode = 'gallery';
        $imageCommentDates = [];
    }
} else {
    $imageCommentDates = [];
}

// Gallery mode: pre-load all images for every tire so we can render the catalog
$galleryItems = [];

if ($viewMode === 'gallery') {
    $tireUpdatedCol = $hasUpdatedTires  ? ', updated_at' : '';
    $imgUpdatedCol  = $hasUpdatedImages ? ', updated_at' : '';

    $allTiresStmt = $pdo->prepare("
        SELECT id, name{$tireUpdatedCol} FROM tires
        WHERE company_id = ? AND module_id = ?
        ORDER BY name ASC
    ");
    $allTiresStmt->execute([$client['id'], $module['id']]);
    $allTires = $allTiresStmt->fetchAll();

    if ($allTires) {
        $tireIds = array_column($allTires, 'id');
        $ph = implode(',', array_fill(0, count($tireIds), '?'));
        $imgStmt = $pdo->prepare("
            SELECT id, tire_id, image_url, caption, status{$imgUpdatedCol}
            FROM tire_images
            WHERE tire_id IN ($ph){$legacyDeniedFilter}
            ORDER BY tire_id, sort_order ASC, id ASC
        ");
        $imgStmt->execute($tireIds);

        $imgsByTire = [];
        foreach ($imgStmt->fetchAll() as $img) {
            $imgsByTire[(int)$img['tire_id']][] = $img;
        }
        foreach ($allTires as $t) {
            $imgs = $imgsByTire[(int)$t['id']] ?? [];

            // Per-tire status counts (drives the pill row in the section header)
            $counts = ['approved' => 0, 'denied' => 0, 'pending' => 0];
            $maxUpdated = $t['updated_at'] ?? null;
            foreach ($imgs as $img) {
                if (isset($counts[$img['status']])) $counts[$img['status']]++;
                $imgUpdated = $img['updated_at'] ?? null;
                if ($imgUpdated && (!$maxUpdated || $imgUpdated > $maxUpdated)) {
                    $maxUpdated = $imgUpdated;
                }
            }

            $galleryItems[] = [
                'id'         => (int)$t['id'],
                'name'       => $t['name'],
                'images'     => $imgs,
                'counts'     => $counts,
                'updated_at' => $maxUpdated,
            ];
        }
    }
}

$navItems = clientNavItems($pdo, $client);

/** Build URL preserving client + module + extras */
function featureUrl($extra = []) {
    global $client, $module;
    $qs = array_merge(['client' => $client['slug'], 'module' => $module['slug']], $extra);
    return basePath() . '/features?' . http_build_query(array_filter($qs, fn($v) => $v !== null && $v !== ''));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= h($module['plural_label']) ?><?= $selectedItem ? ' — ' . h($selectedItem['name']) : '' ?></title>
<?= renderAppHead() ?>
<style>
  :root{--bg:#18191a;--surface:#242526;--surface-2:#3a3b3c;--border:#3e4042;--text:#e4e6eb;--text-muted:#b0b3b8;--accent:#2d88ff;--accent-hover:#4599ff;--shadow:0 1px 2px rgba(0,0,0,.4),0 1px 3px rgba(0,0,0,.3);--toast-bg:#e4e6eb;--toast-text:#050505;--accent-red:#e2231a}
  *{box-sizing:border-box}html,body{margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.34;-webkit-font-smoothing:antialiased;min-height:100vh}
  .topbar{position:sticky;top:0;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);box-shadow:var(--shadow)}
  .topbar-inner{max-width:1100px;margin:0 auto;padding:10px 20px;display:flex;align-items:center;gap:16px}
  .brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px;color:var(--text);letter-spacing:-.3px;flex:0 0 auto}
  .brand-mark{width:32px;height:32px;border-radius:8px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}
  .brand-logo{width:36px;height:36px;border-radius:8px;object-fit:contain;background:#fff;padding:4px;border:1px solid var(--border)}
  .brand-name{white-space:nowrap;max-width:240px;overflow:hidden;text-overflow:ellipsis}
  .client-nav{display:flex;align-items:center;gap:4px;flex:1;min-width:0;overflow-x:auto;scrollbar-width:none}
  .client-nav::-webkit-scrollbar{display:none}
  .nav-link{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:18px;background:transparent;border:1px solid transparent;color:var(--text-muted);font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;transition:background .15s,color .15s,border-color .15s}
  .nav-link:hover{background:var(--surface-2);color:var(--text)}
  .nav-link.active{background:var(--surface-2);color:var(--text);border-color:var(--border)}
  .nav-link-icon{font-size:14px;line-height:1}
  @media(max-width:600px){.nav-link-label{display:none}.nav-link{padding:7px 10px}.brand-name{max-width:120px;font-size:15px}}
  .feed{max-width:1100px;margin:0 auto;padding:24px 20px 60px}
  .page-heading{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin:0 0 12px}
  .tire-picker{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:16px 20px;margin-bottom:20px}
  .tire-group{margin-bottom:16px}
  .tire-group:last-child{margin-bottom:0}
  .tire-group-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid var(--border)}
  .tire-pills{display:flex;flex-wrap:wrap;gap:6px}
  .tire-pill{display:inline-flex;align-items:center;padding:6px 14px;background:var(--surface-2);border:1px solid var(--border);border-radius:20px;font-size:13px;font-weight:600;color:var(--text);text-decoration:none;cursor:pointer;transition:background .15s,border-color .15s,color .15s,transform .1s;user-select:none}
  .tire-pill:hover{background:var(--border)}
  .tire-pill:active{transform:scale(.97)}
  .tire-pill.active{background:var(--accent);border-color:var(--accent);color:#fff}
  .tire-pill.all-approved{background:#14532d;border-color:#166534;color:#bbf7d0}
  .tire-pill.all-approved:hover{background:#166534}
  .tire-pill.all-approved.active{background:#22c55e;border-color:#22c55e;color:#0b3d1a}
  .tire-pill.all-approved::before{content:'✓ ';font-weight:800}
  .tire-group.all-approved .tire-group-label{color:#22c55e}
  .tire-group.all-approved .tire-group-label::after{content:' • all approved';font-weight:600}
  .gallery{background:var(--surface);border-radius:12px;box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
  .gallery-header{padding:16px 20px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px}
  .gallery-header-text{flex:1;min-width:0}
  .gallery-title{font-size:20px;font-weight:700;margin:0;color:var(--text);letter-spacing:-.3px}
  .gallery-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
  .gallery-delete{background:none;border:1px solid var(--border);color:var(--text-muted);padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s,color .15s,border-color .15s}
  .gallery-delete:hover{background:#7f1d1d;color:#fecaca;border-color:#991b1b}
  .image-card{border-bottom:1px solid var(--border);background:var(--surface);scroll-margin-top:80px}
  .image-card:last-child{border-bottom:none}
  /* Briefly highlight the targeted image card when arriving via #image-<id> */
  .image-card:target{animation:image-target-pulse 2.4s ease-out;box-shadow:inset 0 0 0 2px var(--accent)}
  @keyframes image-target-pulse{
    0%  { box-shadow: 0 0 0 0 rgba(45,136,255,0.6), inset 0 0 0 2px var(--accent); }
    35% { box-shadow: 0 0 0 8px rgba(45,136,255,0.18), inset 0 0 0 2px var(--accent); }
    100%{ box-shadow: 0 0 0 0 rgba(45,136,255,0), inset 0 0 0 2px var(--accent); }
  }
  .image-card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 20px;background:var(--surface-2);border-bottom:1px solid var(--border)}
  .image-card-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted)}
  .image-card-status{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;padding:4px 10px;border-radius:12px;background:var(--surface);color:var(--text-muted);white-space:nowrap;border:1px solid var(--border);transition:background .2s,color .2s,border-color .2s}
  .image-card-status.pending{background:#78350f;color:#fde68a;border-color:transparent}
  .image-card-status.approved{background:#14532d;color:#bbf7d0;border-color:transparent}
  .image-card-status.denied{background:#7f1d1d;color:#fecaca;border-color:transparent}
  .tire-media{display:flex;flex-direction:column}
  .tire-item{position:relative;background:var(--surface-2);overflow:hidden;width:100%}
  .tire-item img{width:100%;height:auto;display:block}
  .tire-caption{padding:14px 20px;font-size:15px;color:var(--text);background:var(--surface)}
  .tire-caption:empty{display:none}
  .save-img-btn{position:absolute;top:10px;right:10px;background:rgba(0,0,0,.65);color:#fff;border:none;padding:7px 11px;border-radius:20px;cursor:pointer;font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px;opacity:0;transform:translateY(-4px);transition:opacity .2s,transform .2s,background .15s;backdrop-filter:blur(4px)}
  .tire-item:hover .save-img-btn,.save-img-btn:focus{opacity:1;transform:translateY(0)}
  .save-img-btn:hover{background:rgba(0,0,0,.85)}
  @media(hover:none){.save-img-btn{opacity:1;transform:none}}
  .replace-img-btn{position:absolute;top:10px;left:10px;background:rgba(24,119,242,.85);color:#fff;border:none;padding:7px 11px;border-radius:20px;cursor:pointer;font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px;opacity:0;transform:translateY(-4px);transition:opacity .2s,transform .2s,background .15s;backdrop-filter:blur(4px)}
  .tire-item:hover .replace-img-btn,.replace-img-btn:focus{opacity:1;transform:translateY(0)}
  .replace-img-btn:hover{background:rgba(24,119,242,1)}
  .replace-img-btn:disabled{opacity:.8;cursor:wait}
  @media(hover:none){.replace-img-btn{opacity:1;transform:none}}
  .tire-item.replacing img{opacity:.5;filter:blur(1px);transition:opacity .2s,filter .2s}
  .image-decision{display:flex;gap:8px;padding:14px 20px 12px}
  .decision-btn{flex:1;padding:10px 14px;border-radius:8px;border:1px solid var(--border);background:var(--surface-2);color:var(--text);font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .15s,border-color .15s,color .15s,transform .1s}
  .decision-btn:hover{background:var(--border)}
  .decision-btn:active{transform:scale(.98)}
  .decision-btn:disabled{opacity:.6;cursor:wait}
  .decision-btn.approve.active{background:#16a34a;border-color:#16a34a;color:#fff}
  .decision-btn.deny.active{background:#dc2626;border-color:#dc2626;color:#fff}
  .decision-btn.approve.active:hover{background:#15803d}
  .decision-btn.deny.active:hover{background:#b91c1c}
  .decision-btn.reset{flex:0 0 auto;padding:10px 14px;color:var(--text-muted);background:transparent;display:none}
  .image-card[data-status="approved"] .decision-btn.reset,.image-card[data-status="denied"] .decision-btn.reset{display:flex}
  .decision-btn.reset:hover{background:var(--surface-2);color:var(--text);border-color:var(--text-muted)}
  .image-comment{padding:0 20px 18px}
  .comment-label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:6px}
  .comment-textarea{width:100%;min-height:60px;background:var(--surface-2);border:1px solid var(--border);color:var(--text);padding:10px 12px;border-radius:8px;font:inherit;font-size:14px;resize:vertical}
  .comment-textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(24,119,242,.15)}
  .comment-meta{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px}
  .comment-status{font-size:12px;font-weight:600;color:var(--text-muted);transition:color .15s}
  .comment-status.dirty{color:var(--accent)}.comment-status.saved{color:#16a34a}.comment-status.error{color:#dc2626}
  .comment-save-btn{background:var(--accent);color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;transition:background .15s,opacity .15s}
  .comment-save-btn:hover{background:var(--accent-hover)}
  .comment-save-btn:disabled{opacity:.5;cursor:default}
  .empty{text-align:center;padding:40px 20px;color:var(--text-muted);font-size:15px;background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
  .empty a{color:var(--accent)}
  .toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--toast-bg);color:var(--toast-text);padding:12px 20px;border-radius:24px;font-size:14px;font-weight:600;opacity:0;pointer-events:none;transition:opacity .25s,transform .25s;z-index:1000;box-shadow:0 4px 16px rgba(0,0,0,.25)}
  .toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
  @media(max-width:520px){.feed{padding:12px 12px 60px}.gallery-header{flex-direction:column;align-items:flex-start;gap:8px}}

  /* ---------- Gallery view (catalog layout) ---------- */
  .gallery-view{padding:8px 0 40px}
  .gallery-view-intro{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:0 0 28px;padding-bottom:12px;border-bottom:1px solid var(--border)}
  .gallery-view-title{font-size:32px;font-weight:800;letter-spacing:-1px;font-style:italic;text-transform:uppercase;margin:0}
  .gallery-view-title .accent{color:var(--accent-red);margin-left:4px}
  .gallery-view-meta{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted)}
  .tire-section{margin-bottom:48px}
  .tire-section-header{display:flex;align-items:baseline;gap:14px;margin-bottom:18px;flex-wrap:wrap}
  .tire-section-title{font-size:22px;font-weight:800;letter-spacing:-.5px;font-style:italic;text-transform:uppercase;margin:0;color:var(--text);line-height:1}
  .tire-section-title .accent{color:var(--accent-red)}
  .tire-section-title a{color:inherit;text-decoration:none}
  .tire-section-title a:hover .accent{text-decoration:underline}
  .tire-section-meta{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);white-space:nowrap;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .tire-section-meta .sep{opacity:.4}
  .status-counts{display:inline-flex;gap:6px;align-items:center}
  .status-counts .sc{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border)}
  .status-counts .sc.approved{background:#14532d;color:#bbf7d0;border-color:transparent}
  .status-counts .sc.denied{background:#7f1d1d;color:#fecaca;border-color:transparent}
  .status-counts .sc.pending{background:#78350f;color:#fde68a;border-color:transparent}
  .status-counts .sc.zero{background:transparent;color:var(--text-muted);border-color:var(--border);opacity:.55}
  .updated-stamp{font-size:11px;font-weight:600;letter-spacing:.5px;text-transform:none;color:var(--text-muted);white-space:nowrap}
  .updated-stamp.fresh{color:#86efac}
  .tire-section-actions{margin-left:auto;display:flex;gap:8px}
  .tire-section-actions .nav-link{padding:5px 10px;font-size:11px;letter-spacing:.5px;text-transform:uppercase}
  .tire-thumb-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px}
  .tire-thumb{display:flex;flex-direction:column;gap:8px}
  .tire-thumb-frame{position:relative;aspect-ratio:1/1;background:#fff;border:1px solid var(--border);border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;padding:14px;cursor:zoom-in;transition:border-color .15s,transform .15s}
  .tire-thumb-frame:hover{border-color:var(--accent);transform:translateY(-1px)}
  .tire-thumb-frame img{max-width:100%;max-height:100%;object-fit:contain;display:block}
  .tire-thumb-frame .status-dot{position:absolute;top:8px;right:8px;width:9px;height:9px;border-radius:50%;background:var(--text-muted);box-shadow:0 0 0 2px var(--bg)}
  .tire-thumb-frame .status-dot.approved{background:#22c55e}
  .tire-thumb-frame .status-dot.denied{background:#ef4444}
  .tire-thumb-frame .status-dot.pending{background:#f59e0b}
  .tire-thumb-caption{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--text-muted);text-align:center;line-height:1.3;min-height:1.3em}
  .tire-thumb-caption:empty::before{content:'—';opacity:.4}
  .tire-section-empty{font-size:13px;color:var(--text-muted);font-style:italic;padding:8px 0}
  /* Gallery groups split by approval status — adds breathing room between approved / pending / denied. */
  .tire-shot-group{margin-bottom:24px}
  .tire-shot-group:last-child{margin-bottom:0}
  .tire-shot-group-label{font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);margin-bottom:10px;display:flex;align-items:center;gap:8px}
  .tire-shot-group-label::after{content:'';flex:1;height:1px;background:var(--border)}
  .tire-shot-group.approved .tire-shot-group-label{color:#86efac}
  .tire-shot-group.pending  .tire-shot-group-label{color:#fbbf24}
  .tire-shot-group.denied   .tire-shot-group-label{color:#fca5a5}
  /* Delete button on each gallery section */
  .section-delete-btn{margin-left:8px;font-size:11px;padding:4px 10px;background:transparent;border:1px solid var(--border);color:var(--text-muted);border-radius:6px;cursor:pointer;font-weight:600;letter-spacing:.5px;text-transform:uppercase}
  .section-delete-btn:hover{background:#7f1d1d;color:#fecaca;border-color:#991b1b}
  /* Comment thread (mirrors the add-post styling but uses dark theme tokens) */
  .comment-thread{display:flex;flex-direction:column;gap:8px;margin-bottom:10px}
  .comment-msg{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:10px 14px}
  .comment-msg-client{border-left:3px solid var(--accent)}
  .comment-msg-admin{border-left:3px solid #a855f7}
  .comment-msg-head{display:flex;gap:8px;align-items:baseline;margin-bottom:4px}
  .comment-msg-actor{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted)}
  .comment-msg-actor::before{content:'@';opacity:.5}
  .comment-msg-time{font-size:11px;color:var(--text-muted);margin-left:auto}
  .comment-msg-body{font-size:13px;color:var(--text);white-space:pre-wrap;word-wrap:break-word}
  .comment-empty{padding:10px;text-align:center;color:var(--text-muted);font-size:12px;font-style:italic;background:var(--surface-2);border-radius:6px;margin-bottom:8px}
  /* Per-image updated stamp */
  .image-updated-stamp{font-size:11px;color:var(--text-muted);font-weight:600}
  @media(max-width:520px){
    .gallery-view-title{font-size:24px}
    .tire-section-title{font-size:18px}
    .tire-thumb-row{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px}
    .tire-thumb-frame{padding:10px}
  }
</style>
</head>
<body>
<?= renderClientNav($navItems, 'module:' . $module['slug'], $selectedItem
      ? ['title'    => $selectedItem['name'],
         'subtitle' => $module['plural_label'],
         'back'     => ['href' => featureUrl(), 'label' => $module['plural_label']]]
      : []) ?>

<main class="feed">

<?php
/**
 * Split a tire name into a "primary" + "accent" piece so we can render
 * "PATHFINDER /SERIES" with the slash + second word in the brand red.
 *
 * Heuristic: if the name contains a "/" or "-", split on the last one.
 * Otherwise split on the last space. Single-word names render unsplit.
 * The accent is returned without the leading "/" so the template can
 * always prefix it consistently.
 */
$splitName = function($name) {
    $name = trim($name);
    if ($name === '') return ['', ''];
    if (preg_match('/^(.+)\s*[\/\-]\s*(\S.*)$/u', $name, $m)) {
        return [trim($m[1]), trim($m[2])];
    }
    $sp = strrpos($name, ' ');
    if ($sp === false) return [$name, ''];
    return [trim(substr($name, 0, $sp)), trim(substr($name, $sp + 1))];
};
?>

<?php if ($viewMode === 'gallery'): ?>
  <?php
    $totalShots = 0;
    foreach ($galleryItems as $g) { $totalShots += count($g['images']); }
  ?>
  <div class="gallery-view">
    <div class="gallery-view-intro">
      <h1 class="gallery-view-title">
        <?= h(strtoupper($module['plural_label'])) ?> <span class="accent">/GALLERY</span>
      </h1>
      <div class="gallery-view-meta">
        <?= count($galleryItems) ?> <?= count($galleryItems) === 1 ? 'model' : 'models' ?>
        · <?= $totalShots ?> <?= $totalShots === 1 ? 'shot' : 'shots' ?>
      </div>
    </div>

    <?php if (empty($galleryItems)): ?>
      <div class="empty">
        No <?= h(strtolower($module['plural_label'])) ?> yet.<?php if (isAdmin()): ?> Add some on the
        <a href="<?= h(basePath()) ?>/admin?client=<?= h($client['slug']) ?>">admin page</a>.<?php endif; ?>
      </div>
    <?php else: ?>
      <?php foreach ($galleryItems as $g):
        [$nameMain, $nameAccent] = $splitName($g['name']);
        $shotCount = count($g['images']);
        $counts    = $g['counts'];
        $updatedAt = $g['updated_at'];
        $isFresh   = $updatedAt && (time() - strtotime($updatedAt)) < 86400; // < 24h
      ?>
        <section class="tire-section" id="tire-<?= (int)$g['id'] ?>">
          <header class="tire-section-header">
            <h2 class="tire-section-title">
              <a href="<?= h(featureUrl(['item' => (int)$g['id']])) ?>"
                 title="Open <?= h($g['name']) ?> for review">
                <?= h(strtoupper($nameMain)) ?><?php if ($nameAccent !== ''): ?> <span class="accent">/<?= h(strtoupper($nameAccent)) ?></span><?php endif; ?>
              </a>
            </h2>
            <div class="tire-section-meta">
              <span><?= $shotCount ?> <?= $shotCount === 1 ? 'shot' : 'shots' ?></span>
              <?php if ($shotCount > 0): ?>
                <span class="sep">·</span>
                <span class="status-counts">
                  <span class="sc approved <?= $counts['approved'] === 0 ? 'zero' : '' ?>" title="<?= (int)$counts['approved'] ?> approved">
                    ✓ <?= (int)$counts['approved'] ?>
                  </span>
                  <span class="sc denied <?= $counts['denied'] === 0 ? 'zero' : '' ?>" title="<?= (int)$counts['denied'] ?> denied">
                    ✕ <?= (int)$counts['denied'] ?>
                  </span>
                  <span class="sc pending <?= $counts['pending'] === 0 ? 'zero' : '' ?>" title="<?= (int)$counts['pending'] ?> pending">
                    ⏳ <?= (int)$counts['pending'] ?>
                  </span>
                </span>
              <?php endif; ?>
              <?php if ($updatedAt): ?>
                <span class="sep">·</span>
                <span class="updated-stamp <?= $isFresh ? 'fresh' : '' ?>"
                      title="Last edit: <?= h(absoluteTime($updatedAt)) ?>">
                  Updated <?= h(relativeTime($updatedAt)) ?>
                </span>
              <?php endif; ?>
            </div>
            <div class="tire-section-actions">
              <a class="nav-link" href="<?= h(featureUrl(['item' => (int)$g['id']])) ?>">Review</a>
              <?php if (isAdmin()): // admin-only: never rendered for clients ?>
                <button class="section-delete-btn" data-delete-tire="<?= (int)$g['id'] ?>"
                        data-tire-name="<?= h($g['name']) ?>" type="button">Delete</button>
              <?php endif; ?>
            </div>
          </header>

          <?php if (empty($g['images'])): ?>
            <div class="tire-section-empty">No images uploaded yet.</div>
          <?php else:
            // Split images by approval status so each band can render with breathing room.
            $byStatus = ['approved' => [], 'pending' => [], 'denied' => []];
            foreach ($g['images'] as $img) {
                $st = $img['status'] ?? 'pending';
                if (!isset($byStatus[$st])) { $st = 'pending'; }
                $byStatus[$st][] = $img;
            }
            $statusLabels = [
                'approved' => 'Approved',
                'pending'  => 'Awaiting approval',
                'denied'   => 'Denied',
            ];
          ?>
            <?php foreach ($statusLabels as $statusKey => $statusLabel):
              $imgs = $byStatus[$statusKey];
              if (empty($imgs)) continue;
            ?>
              <div class="tire-shot-group <?= h($statusKey) ?>">
                <div class="tire-shot-group-label">
                  <?= h($statusLabel) ?> · <?= count($imgs) ?>
                </div>
                <div class="tire-thumb-row">
                  <?php foreach ($imgs as $img): ?>
                    <div class="tire-thumb">
                      <div class="tire-thumb-frame">
                        <img src="<?= h(basePath() . '/' . $img['image_url']) ?>"
                             alt="<?= h($img['caption'] ?: $g['name']) ?>"
                             loading="lazy"
                             data-image-el>
                        <span class="status-dot <?= h($img['status']) ?>"
                              title="<?= h(ucfirst($img['status'])) ?>"></span>
                      </div>
                      <div class="tire-thumb-caption"><?= h($img['caption']) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php else: /* detail view */ ?>

  <div class="page-heading"><?= h($module['plural_label']) ?></div>

  <?php if (empty($allCategories) && empty($uncategorized)): ?>
    <div class="empty">
      No <?= h(strtolower($module['plural_label'])) ?> yet. Add some on the
      <a href="<?= h(basePath()) ?>/admin?client=<?= h($client['slug']) ?>">admin page</a>.
    </div>
  <?php else: ?>

    <div class="tire-picker">
      <div class="tire-group">
        <div class="tire-group-label">
          <a href="<?= h(featureUrl()) ?>" style="color:var(--accent);text-decoration:none">← Back to gallery</a>
        </div>
      </div>
      <?php foreach ($allCategories as $cat):
        $list = $itemsByCategory[(int)$cat['id']] ?? [];
        if (empty($list)) continue;
        // Whole-category-green when every tire in this category is fully approved.
        $allApprovedInCat = true;
        foreach ($list as $t) {
            if (empty($tireApprovedMap[(int)$t['id']])) { $allApprovedInCat = false; break; }
        }
      ?>
        <div class="tire-group <?= $allApprovedInCat ? 'all-approved' : '' ?>">
          <div class="tire-group-label"><?= h($cat['name']) ?></div>
          <div class="tire-pills">
            <?php foreach ($list as $t):
              $tirePill = !empty($tireApprovedMap[(int)$t['id']]) ? 'all-approved' : '';
            ?>
              <a class="tire-pill <?= $tirePill ?> <?= (int)$t['id'] === $selectedId ? 'active' : '' ?>"
                 href="<?= h(featureUrl(['item' => (int)$t['id']])) ?>">
                <?= h($t['name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (!empty($uncategorized)):
        $allApprovedUncat = true;
        foreach ($uncategorized as $t) {
            if (empty($tireApprovedMap[(int)$t['id']])) { $allApprovedUncat = false; break; }
        }
      ?>
        <div class="tire-group <?= $allApprovedUncat ? 'all-approved' : '' ?>">
          <div class="tire-group-label">Uncategorized</div>
          <div class="tire-pills">
            <?php foreach ($uncategorized as $t):
              $tirePill = !empty($tireApprovedMap[(int)$t['id']]) ? 'all-approved' : '';
            ?>
              <a class="tire-pill <?= $tirePill ?> <?= (int)$t['id'] === $selectedId ? 'active' : '' ?>"
                 href="<?= h(featureUrl(['item' => (int)$t['id']])) ?>">
                <?= h($t['name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($selectedItem): ?>
      <div class="gallery" data-tire-id="<?= (int)$selectedItem['id'] ?>">
        <div class="gallery-header">
          <div class="gallery-header-text">
            <h2 class="gallery-title"><?= h($selectedItem['name']) ?></h2>
            <div class="gallery-subtitle"><?= count($images) ?> image<?= count($images) !== 1 ? 's' : '' ?></div>
          </div>
          <?php if (isAdmin()): // admin-only: never rendered for clients ?>
            <button class="gallery-delete" data-delete-tire="<?= (int)$selectedItem['id'] ?>" type="button">
              Delete <?= h(strtolower($module['singular_label'])) ?>
            </button>
          <?php endif; ?>
        </div>
        <?php if (empty($images)): ?>
          <div class="empty" style="border:none;border-radius:0;box-shadow:none">No images uploaded yet.</div>
        <?php else: ?>
          <div class="tire-media">
            <?php foreach ($images as $i => $img):
              $imgExt   = strtolower(pathinfo($img['image_url'], PATHINFO_EXTENSION) ?: 'jpg');
              $stem     = !empty($img['display_name'])
                            ? safeFilenameStem($img['display_name'])
                            : safeFilenameStem(($selectedItem['name'] ?? 'image') . '-' . ($i + 1));
              if ($stem === '') { $stem = 'image-' . (int)$img['id']; }
              $filename = $stem . '.' . $imgExt;
            ?>
              <article class="image-card" id="image-<?= (int)$img['id'] ?>" data-image-id="<?= (int)$img['id'] ?>" data-status="<?= h($img['status']) ?>">
                <div class="image-card-header">
                  <span class="image-card-label">
                    Image <?= $i + 1 ?>
                    <?php if (!empty($img['updated_at'])): ?>
                      <span class="image-updated-stamp" title="<?= h(absoluteTime($img['updated_at'])) ?>">
                        · updated <?= h(relativeTime($img['updated_at'])) ?>
                      </span>
                    <?php endif; ?>
                  </span>
                  <span class="image-card-status <?= h($img['status']) ?>" data-status-pill><?= h(ucfirst($img['status'])) ?></span>
                </div>
                <div class="tire-item">
                  <img src="<?= h(basePath() . '/' . $img['image_url']) ?>" alt="<?= h($img['caption']) ?>" loading="lazy" data-image-el>
                  <button class="save-img-btn" data-src="<?= h(basePath() . '/' . $img['image_url']) ?>" data-filename="<?= h($filename) ?>">⬇ Save</button>
                  <?php if (isAdmin()): // admin-only: never rendered for clients ?>
                    <button class="replace-img-btn" data-replace-img type="button" title="Replace this image">🔄 Replace</button>
                  <?php endif; ?>
                </div>
                <?php if (trim($img['caption']) !== ''): ?>
                  <div class="tire-caption"><?= h($img['caption']) ?></div>
                <?php endif; ?>
                <div class="image-decision">
                  <button class="decision-btn approve <?= $img['status'] === 'approved' ? 'active' : '' ?>" data-decide="approved" type="button">✓ Approve</button>
                  <button class="decision-btn deny <?= $img['status'] === 'denied' ? 'active' : '' ?>" data-decide="denied" type="button">✕ Deny</button>
                  <?php if (isAdmin()): // admin-only: a client never resets an item to pending ?>
                    <button class="decision-btn reset" data-decide="pending" type="button" title="Reset to pending">↺ Reset</button>
                  <?php endif; ?>
                </div>
                <div class="image-comment">
                  <label class="comment-label" for="img-comment-<?= (int)$img['id'] ?>">
                    💬 Comments
                  </label>
                  <?php $threadHtml = renderCommentThread($pdo, 'tire_image', (int)$img['id']); ?>
                  <?php if ($threadHtml): ?>
                    <?= $threadHtml ?>
                  <?php else: ?>
                    <div class="comment-empty">No messages yet. Start the thread below.</div>
                  <?php endif; ?>
                  <textarea class="comment-textarea" id="img-comment-<?= (int)$img['id'] ?>" data-comment-input placeholder="Type a message…" maxlength="2000"></textarea>
                  <div class="comment-meta">
                    <span class="comment-status" data-comment-status></span>
                    <button class="comment-save-btn" data-comment-save type="button" disabled>Send message</button>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>

<?php endif; /* viewMode */ ?>

</main>
<div class="toast" id="toast"></div>
<script>
  // App root ('' or '/socialmedia') so endpoint fetches work wherever this page is served from.
  const APP_BASE = <?= json_encode(basePath()) ?>;
  // URL to return to after a delete — stays in this module for this client
  const LIST_URL = <?= json_encode(featureUrl()) ?>;
  // Tenant scope + actor for the status endpoint (server-side checks; see helpers.php)
  const CLIENT_SLUG = <?= json_encode((string)$client['slug']) ?>;
  const ACTOR = <?= json_encode(isAdmin() ? 'admin' : 'client') ?>;

  const toastEl=document.getElementById('toast');let toastTimer;
  function showToast(m){toastEl.textContent=m;toastEl.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>toastEl.classList.remove('show'),1800)}

  document.addEventListener('click',async e=>{
    const saveBtn=e.target.closest('.save-img-btn');if(!saveBtn)return;
    const src=saveBtn.getAttribute('data-src'),filename=saveBtn.getAttribute('data-filename');
    try{const r=await fetch(src,{mode:'cors'}),b=await r.blob(),u=URL.createObjectURL(b),a=document.createElement('a');a.href=u;a.download=filename;document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(u);showToast('✓ Image saved')}catch{window.open(src,'_blank');showToast('Opened in new tab')}
  });

  const replaceInput=document.createElement('input');replaceInput.type='file';replaceInput.accept='image/jpeg,image/png,image/gif,image/webp';replaceInput.style.display='none';document.body.appendChild(replaceInput);
  let pendingReplaceBtn=null;
  document.addEventListener('click',e=>{const btn=e.target.closest('[data-replace-img]');if(!btn)return;pendingReplaceBtn=btn;replaceInput.value='';replaceInput.click()});
  replaceInput.addEventListener('change',async()=>{
    if(!replaceInput.files.length||!pendingReplaceBtn)return;
    const file=replaceInput.files[0];if(file.size>10*1024*1024){showToast('Image exceeds 10 MB');return}
    const tireItem=pendingReplaceBtn.closest('.tire-item'),card=pendingReplaceBtn.closest('.image-card'),imageId=card.getAttribute('data-image-id'),imgEl=tireItem.querySelector('[data-image-el]'),svBtn=tireItem.querySelector('.save-img-btn');
    tireItem.classList.add('replacing');pendingReplaceBtn.disabled=true;pendingReplaceBtn.textContent='⏳ Uploading…';
    try{const fd=new FormData();fd.append('image_id',imageId);fd.append('image',file);fd.append('type','tire');const r=await fetch(APP_BASE+'/replace-image.php',{method:'POST',body:fd}),d=await r.json();if(!d.ok)throw new Error(d.error||'Failed');const bust=d.image_url+(d.image_url.includes('?')?'&':'?')+'t='+Date.now();imgEl.src=bust;if(svBtn)svBtn.setAttribute('data-src',d.image_url);showToast('✓ Image replaced')}catch(err){showToast('Replace failed: '+(err.message||'unknown'))}finally{tireItem.classList.remove('replacing');pendingReplaceBtn.disabled=false;pendingReplaceBtn.textContent='🔄 Replace';pendingReplaceBtn=null}
  });

  document.addEventListener('click',async e=>{
    const decideBtn=e.target.closest('.image-decision .decision-btn');if(!decideBtn)return;
    const card=decideBtn.closest('.image-card'),imageId=card.getAttribute('data-image-id'),newStatus=decideBtn.getAttribute('data-decide'),allBtns=card.querySelectorAll('.decision-btn');
    let note='';
    if(newStatus==='denied'){note=(window.prompt('What should change? A short note is required to deny.')||'').trim();if(note.length<3){showToast('A short note is required to deny');return}}
    allBtns.forEach(b=>b.disabled=true);
    try{const fd=new FormData();fd.append('id',imageId);fd.append('status',newStatus);fd.append('actor',ACTOR);fd.append('client',CLIENT_SLUG);if(note)fd.append('comment',note);const r=await fetch(APP_BASE+'/tire-status.php',{method:'POST',body:fd}),d=await r.json();if(!d.ok)throw new Error(d.error||'Failed');
      allBtns.forEach(b=>b.classList.toggle('active',b.getAttribute('data-decide')===newStatus));
      const pill=card.querySelector('[data-status-pill]');pill.className='image-card-status '+newStatus;pill.textContent=newStatus.charAt(0).toUpperCase()+newStatus.slice(1);card.setAttribute('data-status',newStatus);
      showToast(newStatus==='approved'?'✓ Approved':newStatus==='denied'?'✕ Denied':'↺ Reset to pending');
    }catch{showToast('Update failed — try again')}finally{allBtns.forEach(b=>b.disabled=false)}
  });

  document.addEventListener('click',async e=>{
    const btn=e.target.closest('[data-delete-tire]');if(!btn)return;
    if(!confirm('Delete this and all its images? This cannot be undone.'))return;
    const tireId=btn.getAttribute('data-delete-tire');btn.disabled=true;btn.textContent='Deleting…';
    try{const fd=new FormData();fd.append('action','delete_tire');fd.append('tire_id',tireId);fd.append('actor','client');const r=await fetch(APP_BASE+'/tire-status.php',{method:'POST',body:fd}),d=await r.json();if(!d.ok)throw new Error(d.error||'Failed');
      showToast('✓ Deleted');setTimeout(()=>window.location.href=LIST_URL,500);
    }catch(err){btn.disabled=false;btn.textContent='Delete';showToast('Delete failed: '+(err.message||'unknown'))}
  });

  // Comment threads — chat-style send semantics
  document.querySelectorAll('[data-comment-input]').forEach(ta=>{
    ta.addEventListener('input',()=>{
      const card=ta.closest('.image-card'),sb=card.querySelector('[data-comment-save]'),st=card.querySelector('[data-comment-status]');
      const hasText=ta.value.trim().length>0;
      sb.disabled=!hasText;
      st.className='comment-status'+(hasText?' dirty':'');
      st.textContent=hasText?'Press send to post':'';
    });
  });
  document.addEventListener('click',async e=>{
    const saveBtn=e.target.closest('[data-comment-save]');if(!saveBtn)return;
    const card=saveBtn.closest('.image-card'),imageId=card.getAttribute('data-image-id'),ta=card.querySelector('[data-comment-input]'),status=card.querySelector('[data-comment-status]');
    const text=ta.value.trim();
    if(!text){ta.focus();return}
    saveBtn.disabled=true;status.className='comment-status saving';status.textContent='Sending…';
    try{const fd=new FormData();fd.append('id',imageId);fd.append('comment',text);fd.append('actor','client');const r=await fetch(APP_BASE+'/tire-status.php',{method:'POST',body:fd}),d=await r.json();if(!d.ok)throw new Error(d.error||'Failed');
      showToast('✓ Message sent');
      // Reload so the new message appears in the thread above
      setTimeout(()=>window.location.reload(),300);
    }catch{status.className='comment-status error';status.textContent='Send failed — try again';saveBtn.disabled=false}
  });

</script>

<!-- Fullscreen image lightbox -->
<style>
  .lightbox { position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,0.96);
              display: none; align-items: center; justify-content: center; padding: 20px;
              cursor: zoom-out; animation: lb-fade 0.18s ease-out; }
  .lightbox.show { display: flex; }
  .lightbox img { max-width: 100%; max-height: 100%; object-fit: contain;
                  box-shadow: 0 20px 60px rgba(0,0,0,0.6); border-radius: 4px; }
  .lightbox-close { position: absolute; top: 16px; right: 20px;
                    background: rgba(255,255,255,0.15); color: #fff; border: none;
                    width: 40px; height: 40px; border-radius: 50%; font-size: 22px; cursor: pointer;
                    display: flex; align-items: center; justify-content: center; }
  .lightbox-close:hover { background: rgba(255,255,255,0.3); }
  [data-image-el] { cursor: zoom-in; }
  @keyframes lb-fade { from { opacity: 0; } to { opacity: 1; } }
</style>
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Image viewer">
  <button class="lightbox-close" type="button" aria-label="Close">×</button>
  <img id="lightboxImg" alt="">
</div>
<script>
(function() {
  const lb = document.getElementById('lightbox');
  const lbImg = document.getElementById('lightboxImg');
  function open(src, alt) { lbImg.src = src; lbImg.alt = alt || ''; lb.classList.add('show'); document.body.style.overflow = 'hidden'; }
  function close() { lb.classList.remove('show'); lbImg.src = ''; document.body.style.overflow = ''; }
  document.addEventListener('click', (e) => {
    const img = e.target.closest('[data-image-el]');
    if (!img) return;
    e.preventDefault();
    open(img.getAttribute('src'), img.getAttribute('alt'));
  });
  lb.addEventListener('click', close);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && lb.classList.contains('show')) close(); });
})();
</script>
</body>
</html>
