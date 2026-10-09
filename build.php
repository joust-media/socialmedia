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
require_once __DIR__ . '/auth.php';
requireAdmin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * A stored image_url ('uploads/…', 'media/tires/…', root-rooted or absolute) → the URL the browser can load.
 * The raw value used to go straight into src: 'uploads/…' happened to resolve next to this page, but a series
 * render ('media/tires/<tire>/<series>/<file>') lives at the document root (/media/…), so it 404'd.
 * tireImageSrc() (tire-series-lib.php) resolves every one of those shapes.
 */
function buildMediaUrl(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (function_exists('tireImageSrc')) return tireImageSrc($url);
    return preg_match('#^(https?:)?//#i', $url) || $url[0] === '/' ? $url : basePath() . '/' . ltrim($url, '/');
}

// A client must be in scope.
if (!$client) {
    header('Location: ' . manageUrl('tools', ['msg' => 'Pick a client to open the AI Builder.']));
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
        WHERE p.company_id = ?" . trashAnd($pdo, 'post', 'p') . "
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
            'url'    => buildMediaUrl((string)$r['image_url']),
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
        WHERE t.company_id = ?" . trashAnd($pdo, 'tire_image', 'ti') . "
        ORDER BY t.name ASC, ti.sort_order ASC
        LIMIT 120
    ");
    $stmt->execute([$client['id']]);
    foreach ($stmt->fetchAll() as $r) {
        $label = trim((string)$r['tire_name']);
        if ($label === '') { $label = 'Item #' . (int)$r['id']; }
        $refImages[] = [
            'key'    => 'tire-' . (int)$r['id'],
            'url'    => buildMediaUrl((string)$r['image_url']),
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
                'url'  => buildMediaUrl((string)$r['image_url']),
                'thumb' => mediaTypeFromUrl($r['image_url']) === 'video' ? '' : pvUrl(buildMediaUrl((string)$r['image_url']), 'sm'),   // the tile shows the sm preview; url stays the file (download)
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

// The Builder's behaviour (selection, compose, copy, download) — printed after </main> by layout-bottom.php.
$builderScript = <<<'JS'
<script>
  // ---- Data from the server ------------------------------------------------
  // json_encode keeps "/" escaped (default) so prompt text cannot break out of this block.
  const PROMPTS     = __PROMPTS__;
  const BASE_CTX    = __BASE_CTX__;
  const KNOWN_VARS  = __KNOWN_VARS__;
  const SEP         = __SEP__;
  const CLIENT_SLUG = __CLIENT_SLUG__;
  const STORE_KEY   = 'jsm_builder:' + CLIENT_SLUG;
  const VEHICLES    = __VEHICLES__;
  // Vehicle variables — filled when a vehicle is picked, merged into the context.
  let vehicleCtx = { vehicle_manufacturer: '', vehicle_model: '', vehicle_year: '', vehicle_type: '' };

  const PLAY_ICON   = __PLAY_ICON__;
  const CHECK_ICON  = __CHECK_ICON__;

  const SEGMENT_IDS = ['sel-camera','sel-lighting','sel-environment','sel-product',
                       'sel-char-1','sel-char-2','sel-char-3','sel-char-4',
                       'sel-references','sel-custom'];
  const REQUIRED_IDS = ['sel-camera','sel-lighting','sel-environment','sel-product'];

  // ---- Toast (the shared one: App.toast, app.js) ---------------------------
  function showToast(msg) { if (window.App && App.toast) App.toast(msg); }

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
    gateHint.hidden = ready;

    document.getElementById('reminderModel').textContent =
      document.getElementById('selModel').selectedOptions[0].textContent.trim().split(' — ')[0];
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
      item.setAttribute('aria-pressed', 'false');
    } else {
      selected.set(key, {
        url:   item.getAttribute('data-url'),
        type:  item.getAttribute('data-type'),
        label: item.getAttribute('data-label')
      });
      item.classList.add('selected');
      item.setAttribute('aria-pressed', 'true');
    }
    syncSelection();
  });

  // Build a selectable reference-image tile (used for vehicle images).
  function makeRefItem(info, source) {
    const div = document.createElement('button');
    div.type = 'button';
    div.className = 'tl-ref';
    div.setAttribute('data-ref', '');
    div.setAttribute('aria-pressed', 'false');
    div.dataset.key = info.key;
    div.dataset.url = info.url;
    div.dataset.type = info.type;
    div.dataset.label = info.label;
    if (info.type === 'video') {
      const v = document.createElement('video');
      v.src = info.url; v.muted = true; v.preload = 'metadata';
      div.appendChild(v);
      const tag = document.createElement('span');
      tag.className = 'tl-ref-play'; tag.innerHTML = PLAY_ICON;
      div.appendChild(tag);
    } else {
      const img = document.createElement('img');
      img.src = info.thumb || info.url; img.loading = 'lazy'; img.decoding = 'async'; img.alt = '';   // sm preview; info.url = the file (download)
      div.appendChild(img);
    }
    const src = document.createElement('span');
    src.className = 'tl-ref-src'; src.textContent = source;
    div.appendChild(src);
    const chk = document.createElement('span');
    chk.className = 'tl-ref-check'; chk.innerHTML = CHECK_ICON;
    div.appendChild(chk);
    const lbl = document.createElement('span');
    lbl.className = 'tl-ref-label'; lbl.textContent = info.label;
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
    showToast(ok ? 'Prompt copied' : 'Copy failed — select the text manually');
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
    showToast('Downloaded ' + done + ' reference image' + (done === 1 ? '' : 's'));
  });

  // ---- Init -------------------------------------------------------------
  restoreState();
  // Mark product name as touched BEFORE applyVehicle so a restored value
  // is not wiped by the auto-fill in syncSelection().
  if (document.getElementById('productName').value.trim() !== '') { productNameTouched = true; }
  applyVehicle();
  refresh();
</script>
JS;

// ---- Chrome: the shared shell (theme follows Appearance like every page; back to Manage → Tools) ----
$pageTitle   = 'AI Builder';
$htmlTitle   = 'AI Builder — ' . $client['name'];
$navSubtitle = $client['name'];
$navBack     = ['href' => adminToolsUrl(), 'label' => 'Manage'];
$navLinks    = [
    ['label' => 'Prompt Library',  'href' => pagePath('prompts')],
    ['label' => 'Vehicle Library', 'href' => pagePath('vehicles')],
];
$activeTab   = 'manage';
$bodyClass   = 'page-studio page-tool page-builder';
$headExtra   = '<link rel="stylesheet" href="' . h(staticUrl('css/studio.css')) . '">' . "\n"
             . '<link rel="stylesheet" href="' . h(staticUrl('css/tools.css')) . '">';
$footExtra   = str_replace(
    ['__PROMPTS__', '__BASE_CTX__', '__KNOWN_VARS__', '__SEP__', '__CLIENT_SLUG__', '__VEHICLES__', '__PLAY_ICON__', '__CHECK_ICON__'],
    [json_encode($promptIndex ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
     json_encode(promptVariableNames()), json_encode(PROMPT_SEPARATOR), json_encode($client['slug']), json_encode($vehicles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
     json_encode(icon('play')), json_encode(icon('checkmark'))],
    $builderScript);
include __DIR__ . '/partials/layout-top.php';

/** One category <select> (Compose card). */
function renderPromptSelect($id, $catSlug, $promptsByCat, $required) {
    $opts = $promptsByCat[$catSlug] ?? [];
    echo '<select class="ui-select" id="' . h($id) . '" data-segment>';
    echo '<option value="">' . ($required ? 'Required — pick one' : 'None') . '</option>';
    foreach ($opts as $p) {
        echo '<option value="' . (int)$p['id'] . '" data-models="' . h($p['compatible_models'] ?? '') . '">' . h($p['name']) . '</option>';
    }
    echo '</select>';
    if (empty($opts)) {
        echo '<p class="studio-help">No ' . h($catSlug) . ' prompts yet — <a href="' . h(pagePath('add-prompt')) . '" target="_blank">add one</a>.</p>';
    }
}
?>

<p class="tl-intro">Compose a prompt from the library, copy it, and download the reference images to run the generation by hand.</p>

<div class="tl-context" aria-label="Client profile">
  <span class="tl-tag">Brand: <?= h($ctx['brand_name']) ?></span>
  <span class="tl-tag">Product type: <?= $ctx['product_type'] !== '' ? h($ctx['product_type']) : '—' ?></span>
  <span class="tl-tag">Industry: <?= $ctx['industry'] !== '' ? h($ctx['industry']) : '—' ?></span>
</div>

<?php if (!$promptsReady): ?>
  <div class="studio-alert studio-alert--error" role="alert">
    The <code>prompts</code> table doesn't exist yet. Run <a href="<?= h(pagePath('migrate')) ?>">migrate</a>, then add prompts in the
    <a href="<?= h(pagePath('prompts')) ?>">Prompt Library</a>.
  </div>
<?php elseif ($profileIncomplete): ?>
  <div class="studio-alert" role="status">
    This client has no <strong>product type</strong> and/or <strong>industry</strong> yet, so those variables are skipped.
    Set them in <a href="<?= h(manageUrl('clients', ['edit' => (int)$client['id']])) ?>">Manage → Clients</a>.
  </div>
<?php endif; ?>

<!-- Step 1 — Reference images -->
<section class="ui-card tl-card" data-builder-step="refs">
  <div class="ui-card-header">
    <div class="ui-card-heading"><h3 class="ui-card-title"><span class="tl-step">1</span>Reference images</h3>
      <p class="ui-card-subtitle">Tap images to select them. The first one fills <code class="tl-code">{{product_name}}</code>.</p></div>
    <div class="tl-card-actions">
      <button type="button" class="ui-btn ui-btn--sm ui-btn--filled" id="downloadBtn" disabled><?= icon('download') ?><span>Download (<span id="selCount">0</span>)</span></button>
    </div>
  </div>
  <div class="ui-card-body tl-fields">
    <?php if (hasVehiclesTable($pdo)): ?>
      <div class="studio-field">
        <label for="selVehicle" class="studio-label">Vehicle <span class="text-tertiary">optional</span></label>
        <select id="selVehicle" class="ui-select">
          <option value="">No vehicle</option>
          <?php foreach ($vehicles as $v): ?>
            <option value="<?= (int)$v['id'] ?>"><?= h($v['label']) ?><?= $v['type'] !== '' ? '  ·  ' . h($v['type']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <p class="studio-help">
          <?php if (empty($vehicles)): ?>
            No vehicles in the library yet — <a href="<?= h(pagePath('add-vehicle')) ?>" target="_blank">add one</a>.
          <?php else: ?>
            Adds the vehicle's images below and fills the vehicle variables.
          <?php endif; ?>
        </p>
      </div>
      <div class="tl-ref-grid" id="vehicleRefGrid"></div>
      <span class="studio-label" style="margin:0">Feed images</span>
    <?php endif; ?>
    <?php if (empty($refImages)): ?>
      <div class="ui-empty">No feed images for <?= h($client['name']) ?> yet. Add posts or tire images first.</div>
    <?php else: ?>
      <div class="tl-ref-grid" id="refGrid">
        <?php foreach ($refImages as $img): ?>
          <button type="button" class="tl-ref" data-ref aria-pressed="false"
                  data-key="<?= h($img['key']) ?>" data-url="<?= h($img['url']) ?>"
                  data-type="<?= h($img['type']) ?>" data-label="<?= h($img['label']) ?>">
            <?php if ($img['type'] === 'video'): ?>
              <video src="<?= h($img['url']) ?>" muted preload="metadata"></video>
              <span class="tl-ref-play"><?= icon('play') ?></span>
            <?php else: ?>
              <?= pvImg($img['url'], 'sm', ['sizes' => '120px', 'alt' => '']) ?>
            <?php endif; ?>
            <span class="tl-ref-src"><?= h($img['source'] === 'Product' ? 'Tire' : $img['source']) ?></span>
            <span class="tl-ref-check"><?= icon('checkmark') ?></span>
            <span class="tl-ref-label"><?= h($img['label']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Step 2 — Compose -->
<section class="ui-card tl-card" data-builder-step="compose">
  <div class="ui-card-header">
    <div class="ui-card-heading"><h3 class="ui-card-title"><span class="tl-step">2</span>Compose the prompt</h3></div>
    <div class="tl-card-actions"><a class="ui-btn ui-btn--sm ui-btn--gray" href="<?= h(pagePath('prompts')) ?>" target="_blank">Prompt Library</a></div>
  </div>
  <div class="ui-card-body">
    <div class="tl-grid">
      <div class="studio-field"><label class="studio-label" for="sel-camera">Camera</label><?php renderPromptSelect('sel-camera', 'camera', $promptsByCat, true); ?></div>
      <div class="studio-field"><label class="studio-label" for="sel-lighting">Lighting</label><?php renderPromptSelect('sel-lighting', 'lighting', $promptsByCat, true); ?></div>
      <div class="studio-field"><label class="studio-label" for="sel-environment">Environment</label><?php renderPromptSelect('sel-environment', 'environment', $promptsByCat, true); ?></div>
      <div class="studio-field"><label class="studio-label" for="sel-product">Product</label><?php renderPromptSelect('sel-product', 'product', $promptsByCat, true); ?></div>
    </div>

    <h4 class="tl-subhead">Characters <span class="text-tertiary">— optional, up to <?= PROMPT_CHARACTER_SLOTS ?></span></h4>
    <div class="tl-grid">
      <?php for ($i = 1; $i <= PROMPT_CHARACTER_SLOTS; $i++): ?>
        <div class="studio-field"><label class="studio-label" for="sel-char-<?= $i ?>">Character <?= $i ?></label><?php renderPromptSelect('sel-char-' . $i, 'character', $promptsByCat, false); ?></div>
      <?php endfor; ?>
    </div>

    <h4 class="tl-subhead">Rules &amp; extras <span class="text-tertiary">— optional</span></h4>
    <div class="tl-grid">
      <div class="studio-field"><label class="studio-label" for="sel-references">References <span class="text-tertiary">rules the AI must follow</span></label><?php renderPromptSelect('sel-references', 'references', $promptsByCat, false); ?></div>
      <div class="studio-field"><label class="studio-label" for="sel-custom">Custom</label><?php renderPromptSelect('sel-custom', 'custom', $promptsByCat, false); ?></div>
    </div>

    <h4 class="tl-subhead">Settings</h4>
    <div class="tl-grid">
      <div class="studio-field">
        <label class="studio-label" for="productName">Product name <span class="text-tertiary">fills {{product_name}}</span></label>
        <input class="ui-input" type="text" id="productName" placeholder="From the first selected image">
      </div>
      <div class="studio-field">
        <label class="studio-label" for="selModel">Model</label>
        <select class="ui-select" id="selModel">
          <?php foreach (promptModels() as $slug => $meta): ?>
            <option value="<?= h($slug) ?>" data-type="<?= h($meta['type']) ?>"><?= h($meta['label']) ?> — <?= h(ucfirst($meta['type'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="studio-field">
        <label class="studio-label" for="selAspect">Aspect ratio</label>
        <select class="ui-select" id="selAspect">
          <?php foreach (promptAspectRatios() as $ratio => $px): ?>
            <option value="<?= h($ratio) ?>"><?= h($ratio) ?> (<?= h($px) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="studio-field">
        <label class="studio-label" for="modifier">Custom modifier <span class="text-tertiary">optional</span></label>
        <textarea class="ui-textarea" id="modifier" rows="2" placeholder="A one-off tweak added to the end of the prompt"></textarea>
      </div>
    </div>
  </div>
</section>

<!-- Step 3 — Final prompt -->
<section class="ui-card tl-card" data-builder-step="final">
  <div class="ui-card-header"><div class="ui-card-heading"><h3 class="ui-card-title"><span class="tl-step">3</span>Final prompt</h3></div></div>
  <div class="ui-card-body">
    <label class="ui-visually-hidden" for="finalText">Final prompt</label>
    <textarea class="ui-textarea tl-final" id="finalText" readonly placeholder="Pick a Camera, Lighting, Environment and Product prompt above…"></textarea>
    <div class="tl-final-actions">
      <button type="button" class="ui-btn ui-btn--filled" id="copyBtn" disabled>Copy prompt</button>
      <span class="studio-help" style="margin:0">Then generate by hand in <strong id="reminderModel"><?= h(promptModelLabel(array_key_first(promptModels()))) ?></strong> · aspect <strong id="reminderAspect"><?= h(array_key_first(promptAspectRatios())) ?></strong></span>
    </div>
    <p class="tl-gate" id="gateHint" hidden>Pick a Camera, Lighting, Environment and Product prompt to enable Copy.</p>
  </div>
</section>

<?php include __DIR__ . '/partials/layout-bottom.php'; ?>
