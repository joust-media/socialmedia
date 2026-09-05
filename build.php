<?php
/**
 * AI Builder — admin-only prompt composition tool.
 *
 * Client-scoped via ?client=<slug>. Phase 1 scope: compose a prompt from the
 * library, then one-click copy the prompt text and download the selected
 * reference images so the operator can run the generation manually.
 * No AI-service calls — Higgsfield/Leonardo integration is deferred to Phase 2.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/prompt-lib.php';
require __DIR__ . '/auth.php';
requireAdmin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// A client must be in scope.
if (!$client) {
    header('Location: admin?msg=' . urlencode('Pick a client to open the AI Builder.'));
    exit;
}
$clientQs     = 'client=' . urlencode($client['slug']);
$promptsReady = hasPromptsTable($pdo);

// -------------------------------------------------------------
// Load the prompt library, grouped by category + indexed by id.
// -------------------------------------------------------------
$promptsByCat = array_fill_keys(promptCategorySlugs(), []);
$promptIndex  = [];  // id => ['text','models','category','name']
if ($promptsReady) {
    $rows = $pdo->query("
        SELECT id, category, name, prompt_text, compatible_models
        FROM prompts ORDER BY name ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        if (isset($promptsByCat[$r['category']])) {
            $promptsByCat[$r['category']][] = $r;
        }
        $promptIndex[(int)$r['id']] = [
            'text'     => $r['prompt_text'],
            'models'   => splitCommaList($r['compatible_models'] ?? ''),
            'category' => $r['category'],
            'name'     => $r['name'],
        ];
    }
}

// -------------------------------------------------------------
// Load reference images — social feed (post_images) + product feed
// (tire_images). Each entry: key, url, type, label, source.
// -------------------------------------------------------------
$refImages = [];

// Social feed
try {
    $hasPostName  = hasPostsNameColumn($pdo);
    $hasPostMedia = hasMediaTypeColumn($pdo);
    $nameSel  = $hasPostName  ? 'p.name AS post_name' : "'' AS post_name";
    $mediaSel = $hasPostMedia ? 'pi.media_type'       : "'' AS media_type";
    $stmt = $pdo->prepare("
        SELECT pi.id, pi.image_url, {$mediaSel}, {$nameSel}, p.caption, p.id AS post_id
        FROM post_images pi
        INNER JOIN posts p ON p.id = pi.post_id
        WHERE p.company_id = ?
        ORDER BY p.scheduled_date DESC, pi.sort_order ASC
        LIMIT 120
    ");
    $stmt->execute([$client['id']]);
    foreach ($stmt->fetchAll() as $r) {
        $label = trim((string)$r['post_name']);
        if ($label === '') { $label = mb_strimwidth(trim((string)$r['caption']), 0, 40, '…'); }
        if ($label === '') { $label = 'Post #' . (int)$r['post_id']; }
        $type = $r['media_type'] !== '' ? $r['media_type'] : mediaTypeFromUrl($r['image_url']);
        $refImages[] = [
            'key'    => 'post-' . (int)$r['id'],
            'url'    => $r['image_url'],
            'type'   => $type,
            'label'  => $label,
            'source' => 'Social',
        ];
    }
} catch (Exception $e) {
    // post tables missing on this deploy — skip silently.
}

// Product feed (tires module). Guarded — these tables may not exist.
try {
    $stmt = $pdo->prepare("
        SELECT ti.id, ti.image_url, t.name AS tire_name
        FROM tire_images ti
        INNER JOIN tires t ON t.id = ti.tire_id
        WHERE t.company_id = ?
        ORDER BY t.name ASC, ti.sort_order ASC
        LIMIT 120
    ");
    $stmt->execute([$client['id']]);
    foreach ($stmt->fetchAll() as $r) {
        $label = trim((string)$r['tire_name']);
        if ($label === '') { $label = 'Item #' . (int)$r['id']; }
        $refImages[] = [
            'key'    => 'tire-' . (int)$r['id'],
            'url'    => $r['image_url'],
            'type'   => mediaTypeFromUrl($r['image_url']),
            'label'  => $label,
            'source' => 'Product',
        ];
    }
} catch (Exception $e) {
    // tires module not installed / not migrated — skip silently.
}

// -------------------------------------------------------------
// Vehicle library — global. A vehicle selected in the Builder contributes
// its images (as references) and its {{vehicle_*}} variables.
// -------------------------------------------------------------
$vehicles = [];
if (hasVehiclesTable($pdo)) {
    $vRows = $pdo->query("
        SELECT id, manufacturer, model, model_year, vehicle_type
        FROM vehicles
        ORDER BY manufacturer ASC, model ASC, model_year DESC
    ")->fetchAll();
    if ($vRows) {
        $vIds = array_column($vRows, 'id');
        $vPh  = implode(',', array_fill(0, count($vIds), '?'));
        $viStmt = $pdo->prepare("
            SELECT vehicle_id, id, image_url FROM vehicle_images
            WHERE vehicle_id IN ($vPh)
            ORDER BY vehicle_id, sort_order ASC, id ASC
        ");
        $viStmt->execute($vIds);
        $vImgs = [];
        foreach ($viStmt->fetchAll() as $r) {
            $vImgs[$r['vehicle_id']][] = [
                'key'  => 'vehicle-' . (int)$r['id'],
                'url'  => $r['image_url'],
                'type' => mediaTypeFromUrl($r['image_url']),
            ];
        }
        foreach ($vRows as $vr) {
            $label = trim(($vr['model_year'] !== null ? $vr['model_year'] . ' ' : '')
                   . $vr['manufacturer'] . ' ' . $vr['model']);
            $imgs = $vImgs[$vr['id']] ?? [];
            foreach ($imgs as &$im) { $im['label'] = $label; }
            unset($im);
            $vehicles[] = [
                'id'           => (int)$vr['id'],
                'label'        => $label,
                'manufacturer' => (string)$vr['manufacturer'],
                'model'        => (string)$vr['model'],
                'year'         => $vr['model_year'] !== null ? (string)$vr['model_year'] : '',
                'type'         => (string)($vr['vehicle_type'] ?? ''),
                'images'       => $imgs,
            ];
        }
    }
}

// -------------------------------------------------------------
// Variable context for live preview (client profile data).
// product_name is filled in-browser from the selected reference image.
// -------------------------------------------------------------
$ctx = [
    'brand_name'   => trim((string)$client['name']),
    'product_type' => trim((string)($client['product_type'] ?? '')),
    'industry'     => trim((string)($client['industry'] ?? '')),
    'product_name' => '',
];
$profileIncomplete = ($ctx['product_type'] === '' || $ctx['industry'] === '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Builder — <?= h($client['name']) ?></title>
<style>
  :root {
    --bg: #18191a; --surface: #242526; --surface-2: #3a3b3c;
    --border: #3e4042; --text: #e4e6eb; --text-muted: #b0b3b8;
    --accent: #2d88ff; --accent-hover: #4599ff;
    --success: #16a34a; --warn: #f59e0b;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text);
         font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
         font-size: 15px; line-height: 1.4; min-height: 100vh; }

  .topbar { position: sticky; top: 0; z-index: 100;
            background: var(--surface); border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow); }
  .topbar-inner { max-width: 1100px; margin: 0 auto; padding: 12px 20px;
                  display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .brand { display: flex; align-items: center; gap: 10px;
           font-weight: 700; font-size: 19px; letter-spacing: -0.4px; }
  .brand-mark { width: 32px; height: 32px; border-radius: 8px;
                background: var(--accent); color: #fff;
                display: flex; align-items: center; justify-content: center; font-weight: 800; }
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
  .btn:hover { background: var(--border); }
  .btn:active { transform: scale(0.98); }
  .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .btn.primary:hover { background: var(--accent-hover); }
  .btn.primary:disabled { opacity: 0.45; cursor: not-allowed; }
  .btn.sm { padding: 6px 10px; font-size: 13px; }

  .wrap { max-width: 1100px; margin: 0 auto; padding: 22px 20px 90px; }
  h1 { margin: 0 0 4px; font-size: 23px; letter-spacing: -0.3px; }
  .subtitle { color: var(--text-muted); margin: 0 0 18px; }

  .notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
  .notice.warn  { background: #78350f; color: #fde68a; border: 1px solid #a16207; }
  .notice.warn a { color: #fde68a; }

  .card { background: var(--surface); border: 1px solid var(--border);
          border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 18px; }
  .card-header { padding: 14px 18px; border-bottom: 1px solid var(--border);
                 display: flex; align-items: center; justify-content: space-between;
                 gap: 12px; flex-wrap: wrap; }
  .card-title { font-size: 15px; font-weight: 700; margin: 0;
                display: flex; align-items: center; gap: 8px; }
  .step-num { width: 22px; height: 22px; border-radius: 50%;
              background: var(--accent); color: #fff; font-size: 12px; font-weight: 800;
              display: inline-flex; align-items: center; justify-content: center; }
  .card-body { padding: 18px; }

  /* Reference image grid */
  .ref-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
              gap: 10px; }
  .ref-item { position: relative; border: 2px solid var(--border); border-radius: 10px;
              overflow: hidden; cursor: pointer; background: var(--surface-2);
              aspect-ratio: 1/1; transition: border-color 0.15s, transform 0.1s; }
  .ref-item:hover { border-color: var(--text-muted); }
  .ref-item.selected { border-color: var(--accent);
                       box-shadow: 0 0 0 3px rgba(45,136,255,0.25); }
  .ref-item img, .ref-item video { width: 100%; height: 100%; object-fit: cover; display: block; }
  .ref-check { position: absolute; top: 6px; right: 6px;
               width: 22px; height: 22px; border-radius: 50%;
               background: rgba(0,0,0,0.6); color: #fff; font-size: 13px; font-weight: 800;
               display: flex; align-items: center; justify-content: center; opacity: 0; }
  .ref-item.selected .ref-check { opacity: 1; background: var(--accent); }
  .ref-label { position: absolute; left: 0; right: 0; bottom: 0;
               background: linear-gradient(transparent, rgba(0,0,0,0.85));
               color: #fff; font-size: 11px; font-weight: 600;
               padding: 14px 8px 6px; white-space: nowrap;
               overflow: hidden; text-overflow: ellipsis; }
  .ref-src { position: absolute; top: 6px; left: 6px;
             font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;
             padding: 2px 6px; border-radius: 8px;
             background: rgba(0,0,0,0.65); color: #fff; }
  .ref-vid-tag { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
                 font-size: 22px; pointer-events: none; text-shadow: 0 1px 4px rgba(0,0,0,0.8); }

  /* Vehicle picker */
  .ref-section-label { font-size: 12px; font-weight: 700; color: var(--text-muted);
                       text-transform: uppercase; letter-spacing: 0.5px;
                       margin-bottom: 8px; display: block; }
  .vehicle-pick { margin-bottom: 4px; }
  .vehicle-select { width: 100%; background: var(--surface-2); border: 1px solid var(--border);
                    color: var(--text); padding: 10px 12px; border-radius: 8px;
                    font: inherit; font-size: 14px; }
  .vehicle-select:focus { outline: none; border-color: var(--accent);
                          box-shadow: 0 0 0 3px rgba(45,136,255,0.18); }
  .vehicle-pick .help { font-size: 11px; color: var(--text-muted);
                        display: block; margin-top: 5px; }
  .vehicle-pick .help a { color: var(--accent); }
  #vehicleRefGrid { margin-bottom: 6px; }
  #vehicleRefGrid:empty { display: none; }

  /* Composition form */
  .compose-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  @media (max-width: 720px) { .compose-grid { grid-template-columns: 1fr; } }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field.full { grid-column: 1 / -1; }
  .field label { font-size: 12px; font-weight: 700; color: var(--text-muted);
                 text-transform: uppercase; letter-spacing: 0.5px; }
  .field label .req { color: var(--accent); }
  .field label .opt { color: var(--text-muted); font-weight: 500; text-transform: none;
                      letter-spacing: 0; }
  .field select, .field input[type="text"], .field textarea {
    background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text); padding: 10px 12px; border-radius: 8px;
    font: inherit; width: 100%; font-size: 14px;
  }
  .field textarea { resize: vertical; min-height: 64px; font-family: inherit; }
  .field select:focus, .field input:focus, .field textarea:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(45,136,255,0.18);
  }
  .field .help { font-size: 11px; color: var(--text-muted); }
  .subhead { font-size: 12px; font-weight: 700; color: var(--text-muted);
             text-transform: uppercase; letter-spacing: 0.5px;
             margin: 18px 0 10px; padding-top: 14px; border-top: 1px solid var(--border); }

  /* Final prompt */
  .final-text { width: 100%; min-height: 130px; background: var(--surface-2);
                border: 1px solid var(--border); color: var(--text);
                padding: 12px 14px; border-radius: 8px; font: inherit; font-size: 15px;
                line-height: 1.5; resize: vertical; }
  .final-text:focus { outline: none; border-color: var(--accent); }
  .final-actions { display: flex; gap: 10px; align-items: center;
                   flex-wrap: wrap; margin-top: 12px; }
  .gen-reminder { font-size: 13px; color: var(--text-muted); }
  .gen-reminder strong { color: var(--text); }
  .gate-hint { font-size: 12px; color: var(--warn); margin-top: 8px; }

  .context-bar { display: flex; flex-wrap: wrap; gap: 14px; font-size: 13px;
                 color: var(--text-muted); margin-bottom: 8px; }
  .context-bar strong { color: var(--text); }

  .empty { padding: 28px 18px; text-align: center; color: var(--text-muted);
           border: 1px dashed var(--border); border-radius: 10px; }

  .toast { position: fixed; bottom: 24px; left: 50%;
           transform: translateX(-50%) translateY(20px);
           background: #e4e6eb; color: #050505;
           padding: 12px 20px; border-radius: 24px; font-size: 14px; font-weight: 600;
           opacity: 0; pointer-events: none; transition: opacity 0.25s, transform 0.25s;
           z-index: 1000; box-shadow: 0 4px 16px rgba(0,0,0,0.3); }
  .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <div class="brand-mark">J</div>
      <span>AI Builder</span>
      <span class="brand-sub"><?= h($client['name']) ?></span>
    </div>
    <div class="top-actions">
      <a class="btn sm" href="admin?<?= h($clientQs) ?>">← <?= h($client['name']) ?> admin</a>
      <a class="btn sm" href="prompts">Prompt Library</a>
      <a class="btn sm" href="vehicles">Vehicle Library</a>
      <a class="btn sm" href="logout">Sign out</a>
    </div>
  </div>
</header>

<div class="wrap">

  <h1>AI Builder</h1>
  <p class="subtitle">
    Compose a prompt from the library, copy it, and download the reference images
    to run the generation manually. (Direct AI generation arrives in Phase 2.)
  </p>

  <div class="context-bar">
    <span>Brand: <strong><?= h($ctx['brand_name']) ?></strong></span>
    <span>Product type: <strong><?= $ctx['product_type'] !== '' ? h($ctx['product_type']) : '—' ?></strong></span>
    <span>Industry: <strong><?= $ctx['industry'] !== '' ? h($ctx['industry']) : '—' ?></strong></span>
  </div>

  <?php if (!$promptsReady): ?>
    <div class="notice warn">
      ⚠ The <code>prompts</code> table doesn't exist yet. Visit
      <a href="migrate">migrate</a>, then add prompts in the
      <a href="prompts">Prompt Library</a>.
    </div>
  <?php elseif ($profileIncomplete): ?>
    <div class="notice warn">
      ⚠ This client is missing <strong>product type</strong> and/or <strong>industry</strong>.
      Those variables will be skipped until you set them on the
      <a href="admin?<?= h($clientQs) ?>">client admin page</a>.
    </div>
  <?php endif; ?>

  <!-- STEP 1 — Reference images ------------------------------------ -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><span class="step-num">1</span> Reference images</h2>
      <button type="button" class="btn sm primary" id="downloadBtn" disabled>
        ⬇ Download selected (<span id="selCount">0</span>)
      </button>
    </div>
    <div class="card-body">
      <?php if (hasVehiclesTable($pdo)): ?>
        <div class="vehicle-pick">
          <label for="selVehicle" class="ref-section-label">🚗 Vehicle (optional)</label>
          <select id="selVehicle" class="vehicle-select">
            <option value="">— No vehicle —</option>
            <?php foreach ($vehicles as $v): ?>
              <option value="<?= (int)$v['id'] ?>"><?= h($v['label']) ?><?= $v['type'] !== '' ? '  ·  ' . h($v['type']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="help">
            <?php if (empty($vehicles)): ?>
              No vehicles in the library yet — <a href="add-vehicle" target="_blank">add one</a>.
            <?php else: ?>
              Adds the vehicle's images below and fills the {{vehicle_*}} variables.
            <?php endif; ?>
          </span>
        </div>
        <div class="ref-grid" id="vehicleRefGrid"></div>
        <div class="ref-section-label" style="margin-top:16px;">📰 Feed images</div>
      <?php endif; ?>
      <?php if (empty($refImages)): ?>
        <div class="empty">
          No feed images found for <?= h($client['name']) ?>.
          Add posts or product items first.
        </div>
      <?php else: ?>
        <div class="ref-grid" id="refGrid">
          <?php foreach ($refImages as $img): ?>
            <div class="ref-item" data-ref
                 data-key="<?= h($img['key']) ?>"
                 data-url="<?= h($img['url']) ?>"
                 data-type="<?= h($img['type']) ?>"
                 data-label="<?= h($img['label']) ?>">
              <?php if ($img['type'] === 'video'): ?>
                <video src="<?= h($img['url']) ?>" muted preload="metadata"></video>
                <span class="ref-vid-tag">▶</span>
              <?php else: ?>
                <img src="<?= h($img['url']) ?>" alt="<?= h($img['label']) ?>" loading="lazy">
              <?php endif; ?>
              <span class="ref-src"><?= h($img['source']) ?></span>
              <span class="ref-check">✓</span>
              <span class="ref-label"><?= h($img['label']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="help" style="margin:12px 0 0;color:var(--text-muted);font-size:12px;">
          Click images to select them. The first selected image fills <code>{{product_name}}</code>.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <!-- STEP 2 — Compose --------------------------------------------- -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><span class="step-num">2</span> Compose the prompt</h2>
      <a class="btn sm" href="prompts" target="_blank">Manage library ↗</a>
    </div>
    <div class="card-body">
      <?php
        // Helper to render one category dropdown.
        function renderPromptSelect($id, $catSlug, $promptsByCat, $required) {
            $opts = $promptsByCat[$catSlug] ?? [];
            echo '<select id="' . h($id) . '" data-segment>';
            echo '<option value="">' . ($required ? '— Required —' : '— None —') . '</option>';
            foreach ($opts as $p) {
                echo '<option value="' . (int)$p['id'] . '" '
                   . 'data-models="' . h($p['compatible_models'] ?? '') . '">'
                   . h($p['name']) . '</option>';
            }
            echo '</select>';
            if (empty($opts)) {
                echo '<span class="help">No ' . h($catSlug) . ' prompts yet — '
                   . '<a href="add-prompt" target="_blank">add one</a>.</span>';
            }
        }
      ?>
      <div class="compose-grid">
        <div class="field">
          <label>🎥 Camera <span class="req">*</span></label>
          <?php renderPromptSelect('sel-camera', 'camera', $promptsByCat, true); ?>
        </div>
        <div class="field">
          <label>💡 Lighting <span class="req">*</span></label>
          <?php renderPromptSelect('sel-lighting', 'lighting', $promptsByCat, true); ?>
        </div>
        <div class="field">
          <label>🏞 Environment <span class="req">*</span></label>
          <?php renderPromptSelect('sel-environment', 'environment', $promptsByCat, true); ?>
        </div>
        <div class="field">
          <label>📦 Product <span class="req">*</span></label>
          <?php renderPromptSelect('sel-product', 'product', $promptsByCat, true); ?>
        </div>
      </div>

      <div class="subhead">🧍 Characters <span style="font-weight:500;text-transform:none;letter-spacing:0;">— optional, up to <?= PROMPT_CHARACTER_SLOTS ?></span></div>
      <div class="compose-grid">
        <?php for ($i = 1; $i <= PROMPT_CHARACTER_SLOTS; $i++): ?>
          <div class="field">
            <label>Character <?= $i ?> <span class="opt">(optional)</span></label>
            <?php renderPromptSelect('sel-char-' . $i, 'character', $promptsByCat, false); ?>
          </div>
        <?php endfor; ?>
      </div>

      <div class="subhead">📐 Rules &amp; extras <span style="font-weight:500;text-transform:none;letter-spacing:0;">— optional</span></div>
      <div class="compose-grid">
        <div class="field">
          <label>📐 References <span class="opt">(rules the AI must follow)</span></label>
          <?php renderPromptSelect('sel-references', 'references', $promptsByCat, false); ?>
        </div>
        <div class="field">
          <label>✨ Custom <span class="opt">(optional)</span></label>
          <?php renderPromptSelect('sel-custom', 'custom', $promptsByCat, false); ?>
        </div>
      </div>

      <div class="subhead">Settings</div>
      <div class="compose-grid">
        <div class="field">
          <label for="productName">Product name <span class="opt">— fills {{product_name}}</span></label>
          <input type="text" id="productName" placeholder="Auto-fills from first selected image">
        </div>
        <div class="field">
          <label for="selModel">Model</label>
          <select id="selModel">
            <?php foreach (promptModels() as $slug => $meta): ?>
              <option value="<?= h($slug) ?>" data-type="<?= h($meta['type']) ?>">
                <?= h($meta['label']) ?> — <?= h(ucfirst($meta['type'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="selAspect">Aspect ratio</label>
          <select id="selAspect">
            <?php foreach (promptAspectRatios() as $ratio => $px): ?>
              <option value="<?= h($ratio) ?>"><?= h($ratio) ?> (<?= h($px) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="modifier">Custom modifier <span class="opt">(optional)</span></label>
          <textarea id="modifier" placeholder="One-off tweak appended to the end of the prompt"></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- STEP 3 — Final prompt ---------------------------------------- -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><span class="step-num">3</span> Final prompt</h2>
    </div>
    <div class="card-body">
      <textarea class="final-text" id="finalText" readonly
                placeholder="Pick a Camera, Lighting, Environment and Product prompt above…"></textarea>
      <div class="final-actions">
        <button type="button" class="btn primary" id="copyBtn" disabled>📋 Copy prompt</button>
        <span class="gen-reminder">
          Then generate manually in
          <strong id="reminderModel"><?= h(promptModelLabel(array_key_first(promptModels()))) ?></strong>
          · aspect <strong id="reminderAspect"><?= h(array_key_first(promptAspectRatios())) ?></strong>
        </span>
      </div>
      <div class="gate-hint" id="gateHint" style="display:none;">
        Pick a Camera, Lighting, Environment and Product prompt to enable Copy.
      </div>
    </div>
  </div>

</div>

<div class="toast" id="toast">Copied!</div>

<script>
  // ---- Data from the server ------------------------------------------------
  // json_encode keeps "/" escaped (default) so prompt text cannot break out of this block.
  const PROMPTS     = <?= json_encode($promptIndex, JSON_UNESCAPED_UNICODE) ?>;
  const BASE_CTX    = <?= json_encode($ctx, JSON_UNESCAPED_UNICODE) ?>;
  const KNOWN_VARS  = <?= json_encode(promptVariableNames()) ?>;
  const SEP         = <?= json_encode(PROMPT_SEPARATOR) ?>;
  const CLIENT_SLUG = <?= json_encode($client['slug']) ?>;
  const STORE_KEY   = 'jsm_builder:' + CLIENT_SLUG;
  const VEHICLES    = <?= json_encode($vehicles, JSON_UNESCAPED_UNICODE) ?>;
  // Vehicle variables — filled when a vehicle is picked, merged into the context.
  let vehicleCtx = { vehicle_manufacturer: '', vehicle_model: '', vehicle_year: '', vehicle_type: '' };

  const SEGMENT_IDS = ['sel-camera','sel-lighting','sel-environment','sel-product',
                       'sel-char-1','sel-char-2','sel-char-3','sel-char-4',
                       'sel-references','sel-custom'];
  const REQUIRED_IDS = ['sel-camera','sel-lighting','sel-environment','sel-product'];

  // ---- Toast --------------------------------------------------------------
  const toastEl = document.getElementById('toast');
  let toastTimer;
  function showToast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1900);
  }

  // ---- Variable substitution (mirrors prompt-lib.php) ---------------------
  function substitute(text, ctx) {
    for (const [k, v] of Object.entries(ctx)) {
      const val = (v || '').trim();
      if (!val) continue;
      text = text.replace(new RegExp('\\{\\{\\s*' + k + '\\s*\\}\\}', 'gi'), val);
    }
    text = text.replace(/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/g, '');
    text = text.replace(/[ \t]+/g, ' ')
               .replace(/\s+([,.;:])/g, '$1')
               .replace(/([,;:])\s*([,.;:])/g, '$2')
               .replace(/^[\s,;:]+|[\s,;:]+$/g, '');
    return text.trim();
  }

  function currentContext() {
    return Object.assign({}, BASE_CTX, vehicleCtx, {
      product_name: document.getElementById('productName').value.trim()
    });
  }

  // ---- Compose the final prompt ------------------------------------------
  function compose() {
    const parts = [];
    SEGMENT_IDS.forEach(id => {
      const sel = document.getElementById(id);
      if (sel && sel.value && PROMPTS[sel.value]) {
        const t = (PROMPTS[sel.value].text || '').trim();
        if (t) parts.push(t);
      }
    });
    const modifier = document.getElementById('modifier').value.trim();
    if (modifier) parts.push(modifier);
    return substitute(parts.join(SEP), currentContext());
  }

  function requiredFilled() {
    return REQUIRED_IDS.every(id => {
      const sel = document.getElementById(id);
      return sel && sel.value !== '';
    });
  }

  // ---- Model compatibility: disable incompatible options ----------------
  function applyModelFilter() {
    const model = document.getElementById('selModel').value;
    SEGMENT_IDS.forEach(id => {
      const sel = document.getElementById(id);
      if (!sel) return;
      let clearedCurrent = false;
      [...sel.options].forEach(opt => {
        if (!opt.value) return;
        const models = (opt.getAttribute('data-models') || '').trim();
        const ok = models === '' || models.split(',').map(s => s.trim()).includes(model);
        opt.disabled = !ok;
        if (!ok && opt.selected) clearedCurrent = true;
      });
      if (clearedCurrent) sel.value = '';
    });
  }

  // ---- Refresh everything -----------------------------------------------
  function refresh() {
    const finalEl = document.getElementById('finalText');
    const copyBtn = document.getElementById('copyBtn');
    const gateHint = document.getElementById('gateHint');
    finalEl.value = compose();
    const ready = requiredFilled();
    copyBtn.disabled = !ready;
    gateHint.style.display = ready ? 'none' : 'block';

    document.getElementById('reminderModel').textContent =
      document.getElementById('selModel').selectedOptions[0].textContent.split(' — ')[0];
    document.getElementById('reminderAspect').textContent =
      document.getElementById('selAspect').value;
    saveState();
  }

  // ---- Session persistence ----------------------------------------------
  function saveState() {
    const sv = document.getElementById('selVehicle');
    const state = { modifier: document.getElementById('modifier').value,
                    productName: document.getElementById('productName').value,
                    model: document.getElementById('selModel').value,
                    aspect: document.getElementById('selAspect').value,
                    vehicle: sv ? sv.value : '' };
    SEGMENT_IDS.forEach(id => { state[id] = document.getElementById(id).value; });
    try { sessionStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) {}
  }
  function restoreState() {
    let state;
    try { state = JSON.parse(sessionStorage.getItem(STORE_KEY) || '{}'); } catch (e) { state = {}; }
    if (state.model)  document.getElementById('selModel').value  = state.model;
    if (state.aspect) document.getElementById('selAspect').value = state.aspect;
    // Model filter must run before restoring segment selects.
    applyModelFilter();
    SEGMENT_IDS.forEach(id => {
      if (state[id]) {
        const sel = document.getElementById(id);
        const opt = [...sel.options].find(o => o.value === state[id] && !o.disabled);
        if (opt) sel.value = state[id];
      }
    });
    if (state.modifier)    document.getElementById('modifier').value = state.modifier;
    if (state.productName) document.getElementById('productName').value = state.productName;
    if (state.vehicle) {
      const sv = document.getElementById('selVehicle');
      if (sv) sv.value = state.vehicle;
    }
  }

  // ---- Wire up listeners -------------------------------------------------
  SEGMENT_IDS.forEach(id => document.getElementById(id).addEventListener('change', refresh));
  document.getElementById('modifier').addEventListener('input', refresh);
  document.getElementById('productName').addEventListener('input', () => { productNameTouched = true; refresh(); });
  document.getElementById('selAspect').addEventListener('change', refresh);
  document.getElementById('selModel').addEventListener('change', () => { applyModelFilter(); refresh(); });

  // ---- Reference image selection ----------------------------------------
  let productNameTouched = false;
  const selected = new Map();  // key -> {url, type, label}

  function syncSelection() {
    document.getElementById('selCount').textContent = selected.size;
    document.getElementById('downloadBtn').disabled = selected.size === 0;
    // Auto-fill product name from first selected image, unless manually edited.
    if (!productNameTouched) {
      const first = selected.values().next().value;
      document.getElementById('productName').value = first ? first.label : '';
      refresh();
    }
  }

  // Delegated click — handles server-rendered feed images AND dynamically
  // injected vehicle images.
  document.addEventListener('click', (e) => {
    const item = e.target.closest('[data-ref]');
    if (!item) return;
    const key = item.getAttribute('data-key');
    if (selected.has(key)) {
      selected.delete(key);
      item.classList.remove('selected');
    } else {
      selected.set(key, {
        url:   item.getAttribute('data-url'),
        type:  item.getAttribute('data-type'),
        label: item.getAttribute('data-label')
      });
      item.classList.add('selected');
    }
    syncSelection();
  });

  // Build a selectable reference-image tile (used for vehicle images).
  function makeRefItem(info, source) {
    const div = document.createElement('div');
    div.className = 'ref-item';
    div.setAttribute('data-ref', '');
    div.dataset.key = info.key;
    div.dataset.url = info.url;
    div.dataset.type = info.type;
    div.dataset.label = info.label;
    if (info.type === 'video') {
      const v = document.createElement('video');
      v.src = info.url; v.muted = true; v.preload = 'metadata';
      div.appendChild(v);
      const tag = document.createElement('span');
      tag.className = 'ref-vid-tag'; tag.textContent = '▶';
      div.appendChild(tag);
    } else {
      const img = document.createElement('img');
      img.src = info.url; img.loading = 'lazy'; img.alt = '';
      div.appendChild(img);
    }
    const src = document.createElement('span');
    src.className = 'ref-src'; src.textContent = source;
    div.appendChild(src);
    const chk = document.createElement('span');
    chk.className = 'ref-check'; chk.textContent = '✓';
    div.appendChild(chk);
    const lbl = document.createElement('span');
    lbl.className = 'ref-label'; lbl.textContent = info.label;
    div.appendChild(lbl);
    return div;
  }

  // ---- Vehicle picker ---------------------------------------------------
  const selVehicle  = document.getElementById('selVehicle');
  const vehicleGrid = document.getElementById('vehicleRefGrid');
  function applyVehicle() {
    if (!selVehicle) return;
    // Drop any previously-injected vehicle images from the selection.
    for (const key of [...selected.keys()]) {
      if (key.indexOf('vehicle-') === 0) selected.delete(key);
    }
    if (vehicleGrid) vehicleGrid.innerHTML = '';
    const v = VEHICLES.find(x => String(x.id) === String(selVehicle.value));
    if (v) {
      vehicleCtx = {
        vehicle_manufacturer: v.manufacturer || '',
        vehicle_model:        v.model || '',
        vehicle_year:         v.year || '',
        vehicle_type:         v.type || ''
      };
      if (vehicleGrid) {
        v.images.forEach(info => vehicleGrid.appendChild(makeRefItem(info, 'Vehicle')));
      }
    } else {
      vehicleCtx = { vehicle_manufacturer: '', vehicle_model: '', vehicle_year: '', vehicle_type: '' };
    }
    syncSelection();
    refresh();
  }
  if (selVehicle) selVehicle.addEventListener('change', applyVehicle);

  // ---- Copy prompt ------------------------------------------------------
  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      try { await navigator.clipboard.writeText(text); return true; } catch (e) {}
    }
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
    document.body.appendChild(ta); ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    return ok;
  }
  document.getElementById('copyBtn').addEventListener('click', async () => {
    const text = document.getElementById('finalText').value.trim();
    if (!text) return;
    const ok = await copyText(text);
    showToast(ok ? '📋 Prompt copied' : 'Copy failed — select the text manually');
  });

  // ---- Download selected reference images -------------------------------
  document.getElementById('downloadBtn').addEventListener('click', async () => {
    if (!selected.size) return;
    const btn = document.getElementById('downloadBtn');
    btn.disabled = true;
    const original = btn.innerHTML;
    let done = 0;
    for (const [key, info] of selected) {
      const extMatch = info.url.match(/\.([a-z0-9]+)(\?|$)/i);
      const ext = extMatch ? extMatch[1].toLowerCase() : (info.type === 'video' ? 'mp4' : 'jpg');
      const safe = (info.label || key).replace(/[^a-zA-Z0-9\-]+/g, '-').replace(/^-+|-+$/g, '') || key;
      const filename = CLIENT_SLUG + '-' + safe + '-' + key + '.' + ext;
      btn.textContent = 'Downloading ' + (done + 1) + '/' + selected.size + '…';
      try {
        const res = await fetch(info.url, { mode: 'cors' });
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
      } catch (e) {
        window.open(info.url, '_blank');
      }
      done++;
    }
    btn.innerHTML = original;
    btn.disabled = false;
    document.getElementById('selCount').textContent = selected.size;
    showToast('⬇ Downloaded ' + done + ' reference image' + (done === 1 ? '' : 's'));
  });

  // ---- Init -------------------------------------------------------------
  restoreState();
  // Mark product name as touched BEFORE applyVehicle so a restored value
  // is not wiped by the auto-fill in syncSelection().
  if (document.getElementById('productName').value.trim() !== '') { productNameTouched = true; }
  applyVehicle();
  refresh();
</script>

</body>
</html>
