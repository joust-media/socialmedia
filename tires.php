<?php
/**
 * Tires Gallery — public view.
 * Categories shown as static group headings with tire pills underneath.
 * Clicking a tire pill shows its image gallery below.
 */

require __DIR__ . '/db.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// All categories that have at least one tire
$allCategories = $pdo->query("
    SELECT c.id, c.name
    FROM categories c
    INNER JOIN tire_categories tc ON tc.category_id = c.id
    GROUP BY c.id, c.name, c.sort_order
    HAVING COUNT(DISTINCT tc.tire_id) > 0
    ORDER BY c.sort_order, c.name
")->fetchAll();

// Build grouped structure: category → [tires]
$tiresByCategory = [];
foreach ($allCategories as $cat) {
    $stmt = $pdo->prepare("
        SELECT t.id, t.name
        FROM tires t
        INNER JOIN tire_categories tc ON tc.tire_id = t.id
        WHERE tc.category_id = ?
        ORDER BY t.name ASC
    ");
    $stmt->execute([$cat['id']]);
    $tiresByCategory[(int)$cat['id']] = $stmt->fetchAll();
}

// Also get uncategorized tires
$uncategorized = $pdo->query("
    SELECT t.id, t.name FROM tires t
    WHERE t.id NOT IN (SELECT DISTINCT tire_id FROM tire_categories)
    ORDER BY t.name ASC
")->fetchAll();

// Selected tire
$selectedId = (int)($_GET['tire'] ?? 0);

// If nothing selected, pick the first tire from the first category
if (!$selectedId) {
    foreach ($tiresByCategory as $catTires) {
        if (!empty($catTires)) { $selectedId = (int)$catTires[0]['id']; break; }
    }
    if (!$selectedId && !empty($uncategorized)) {
        $selectedId = (int)$uncategorized[0]['id'];
    }
}

// Load selected tire data + images
$selectedTire = null;
$images = [];
if ($selectedId > 0) {
    $stmt = $pdo->prepare("SELECT id, name FROM tires WHERE id = ?");
    $stmt->execute([$selectedId]);
    $selectedTire = $stmt->fetch();

    if ($selectedTire) {
        $imgStmt = $pdo->prepare("
            SELECT id, image_url, caption, status, client_comment
            FROM tire_images
            WHERE tire_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $imgStmt->execute([$selectedId]);
        $images = $imgStmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tires<?= $selectedTire ? ' — ' . h($selectedTire['name']) : '' ?></title>
<style>
  :root{--bg:#f0f2f5;--surface:#fff;--surface-2:#f7f8fa;--border:#dadde1;--text:#050505;--text-muted:#65676b;--accent:#1877f2;--accent-hover:#166fe5;--shadow:0 1px 2px rgba(0,0,0,.08),0 1px 3px rgba(0,0,0,.04);--toast-bg:#050505;--toast-text:#fff}
  [data-theme="dark"]{--bg:#18191a;--surface:#242526;--surface-2:#3a3b3c;--border:#3e4042;--text:#e4e6eb;--text-muted:#b0b3b8;--accent:#2d88ff;--accent-hover:#4599ff;--shadow:0 1px 2px rgba(0,0,0,.4),0 1px 3px rgba(0,0,0,.3);--toast-bg:#e4e6eb;--toast-text:#050505}
  *{box-sizing:border-box}html,body{margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.34;-webkit-font-smoothing:antialiased;min-height:100vh}
  .topbar{position:sticky;top:0;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);box-shadow:var(--shadow)}
  .topbar-inner{max-width:680px;margin:0 auto;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
  .brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:20px;color:var(--accent);letter-spacing:-.5px}
  .top-actions{display:flex;gap:8px;align-items:center}
  .nav-link{background:var(--surface-2);border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;transition:background .15s}
  .nav-link:hover{background:var(--border)}
  .theme-toggle{background:var(--surface-2);border:1px solid var(--border);color:var(--text);padding:8px 14px;border-radius:20px;cursor:pointer;font-size:14px;font-weight:600;display:flex;align-items:center;gap:6px;transition:background .15s}
  .theme-toggle:hover{background:var(--border)}
  .feed{max-width:680px;margin:0 auto;padding:20px 16px 60px}

  /* Tire picker card */
  .tire-picker{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:16px 20px;margin-bottom:20px}
  .tire-group{margin-bottom:16px}
  .tire-group:last-child{margin-bottom:0}
  .tire-group-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid var(--border)}
  .tire-pills{display:flex;flex-wrap:wrap;gap:6px}
  .tire-pill{display:inline-flex;align-items:center;padding:6px 14px;background:var(--surface-2);border:1px solid var(--border);border-radius:20px;font-size:13px;font-weight:600;color:var(--text);text-decoration:none;cursor:pointer;transition:background .15s,border-color .15s,color .15s,transform .1s;user-select:none}
  .tire-pill:hover{background:var(--border)}
  .tire-pill:active{transform:scale(.97)}
  .tire-pill.active{background:var(--accent);border-color:var(--accent);color:#fff}

  /* Gallery */
  .gallery{background:var(--surface);border-radius:12px;box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
  .gallery-header{padding:16px 20px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px}
  .gallery-header-text{flex:1;min-width:0}
  .gallery-title{font-size:20px;font-weight:700;margin:0;color:var(--text);letter-spacing:-.3px}
  .gallery-subtitle{font-size:13px;color:var(--text-muted);margin-top:3px}
  .gallery-delete{background:none;border:1px solid var(--border);color:var(--text-muted);padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s,color .15s,border-color .15s}
  .gallery-delete:hover{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
  [data-theme="dark"] .gallery-delete:hover{background:#7f1d1d;color:#fecaca;border-color:#991b1b}

  /* Per-image card */
  .image-card{border-bottom:1px solid var(--border);background:var(--surface)}
  .image-card:last-child{border-bottom:none}
  .image-card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 20px;background:var(--surface-2);border-bottom:1px solid var(--border)}
  .image-card-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted)}
  .image-card-status{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;padding:4px 10px;border-radius:12px;background:var(--surface);color:var(--text-muted);white-space:nowrap;border:1px solid var(--border);transition:background .2s,color .2s,border-color .2s}
  .image-card-status.pending{background:#fef3c7;color:#92400e;border-color:transparent}
  .image-card-status.approved{background:#dcfce7;color:#166534;border-color:transparent}
  .image-card-status.denied{background:#fee2e2;color:#991b1b;border-color:transparent}
  [data-theme="dark"] .image-card-status.pending{background:#78350f;color:#fde68a}
  [data-theme="dark"] .image-card-status.approved{background:#14532d;color:#bbf7d0}
  [data-theme="dark"] .image-card-status.denied{background:#7f1d1d;color:#fecaca}
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
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">Joust</div>
    <div class="top-actions">
      <a class="nav-link" href="index.php">Home</a>
      <a class="nav-link" href="feed.php">Feed</a>
      <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
        <span id="themeIcon">🌙</span>
      </button>
    </div>
  </div>
</header>

<main class="feed">

  <?php if (empty($allCategories) && empty($uncategorized)): ?>
    <div class="empty">No tires yet. Add some in the <a href="admin.php">admin page</a>.</div>
  <?php else: ?>

    <div class="tire-picker">
      <?php foreach ($allCategories as $cat):
        $catTires = $tiresByCategory[(int)$cat['id']] ?? [];
        if (empty($catTires)) continue;
      ?>
        <div class="tire-group">
          <div class="tire-group-label"><?= h($cat['name']) ?></div>
          <div class="tire-pills">
            <?php foreach ($catTires as $t): ?>
              <a class="tire-pill <?= (int)$t['id'] === $selectedId ? 'active' : '' ?>"
                 href="tires.php?tire=<?= (int)$t['id'] ?>">
                <?= h($t['name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (!empty($uncategorized)): ?>
        <div class="tire-group">
          <div class="tire-group-label">Uncategorized</div>
          <div class="tire-pills">
            <?php foreach ($uncategorized as $t): ?>
              <a class="tire-pill <?= (int)$t['id'] === $selectedId ? 'active' : '' ?>"
                 href="tires.php?tire=<?= (int)$t['id'] ?>">
                <?= h($t['name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($selectedTire): ?>
      <div class="gallery" data-tire-id="<?= (int)$selectedTire['id'] ?>">
        <div class="gallery-header">
          <div class="gallery-header-text">
            <h2 class="gallery-title"><?= h($selectedTire['name']) ?></h2>
            <div class="gallery-subtitle"><?= count($images) ?> image<?= count($images) !== 1 ? 's' : '' ?></div>
          </div>
          <button class="gallery-delete" data-delete-tire="<?= (int)$selectedTire['id'] ?>" type="button">🗑 Delete tire</button>
        </div>
        <?php if (empty($images)): ?>
          <div class="empty" style="border:none;border-radius:0;box-shadow:none">No images uploaded for this tire yet.</div>
        <?php else: ?>
          <div class="tire-media">
            <?php foreach ($images as $i => $img):
              $filename = preg_replace('/[^a-zA-Z0-9\-]/', '-', $selectedTire['name']) . '-' . ($i + 1);
            ?>
              <article class="image-card" data-image-id="<?= (int)$img['id'] ?>" data-status="<?= h($img['status']) ?>">
                <div class="image-card-header">
                  <span class="image-card-label">Image <?= $i + 1 ?></span>
                  <span class="image-card-status <?= h($img['status']) ?>" data-status-pill><?= h(ucfirst($img['status'])) ?></span>
                </div>
                <div class="tire-item">
                  <img src="<?= h($img['image_url']) ?>" alt="<?= h($img['caption']) ?>" loading="lazy" data-image-el>
                  <button class="save-img-btn" data-src="<?= h($img['image_url']) ?>" data-filename="<?= h($filename) ?>">⬇ Save</button>
                  <button class="replace-img-btn" data-replace-img type="button" title="Replace this image">🔄 Replace</button>
                </div>
                <?php if (trim($img['caption']) !== ''): ?>
                  <div class="tire-caption"><?= h($img['caption']) ?></div>
                <?php endif; ?>
                <div class="image-decision">
                  <button class="decision-btn approve <?= $img['status'] === 'approved' ? 'active' : '' ?>" data-decide="approved" type="button">✓ Approve</button>
                  <button class="decision-btn deny <?= $img['status'] === 'denied' ? 'active' : '' ?>" data-decide="denied" type="button">✕ Deny</button>
                  <button class="decision-btn reset" data-decide="pending" type="button" title="Reset to pending">↺ Reset</button>
                </div>
                <div class="image-comment">
                  <label class="comment-label" for="img-comment-<?= (int)$img['id'] ?>">Comments / feedback</label>
                  <textarea class="comment-textarea" id="img-comment-<?= (int)$img['id'] ?>" data-comment-input placeholder="Leave a note about this image." maxlength="2000"><?= h($img['client_comment'] ?? '') ?></textarea>
                  <div class="comment-meta">
                    <span class="comment-status" data-comment-status></span>
                    <button class="comment-save-btn" data-comment-save type="button" disabled>Save comment</button>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</main>
<div class="toast" id="toast"></div>
<script>
  const toastEl=document.getElementById('toast');let toastTimer;
  function showToast(m){toastEl.textContent=m;toastEl.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>toastEl.classList.remove('show'),1800)}

  // Save image
  document.addEventListener('click',async e=>{
    const saveBtn=e.target.closest('.save-img-btn');if(!saveBtn)return;
    const src=saveBtn.getAttribute('data-src'),filename=saveBtn.getAttribute('data-filename')+'.jpg';
    try{const r=await fetch(src,{mode:'cors'}),b=await r.blob(),u=URL.createObjectURL(b),a=document.createElement('a');a.href=u;a.download=filename;document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(u);showToast('✓ Image saved')}catch{window.open(src,'_blank');showToast('Opened in new tab')}
  });

  // Replace image
  const replaceInput=document.createElement('input');replaceInput.type='file';replaceInput.accept='image/jpeg,image/png,image/gif,image/webp';replaceInput.style.display='none';document.body.appendChild(replaceInput);
  let pendingReplaceBtn=null;
  document.addEventListener('click',e=>{const btn=e.target.closest('[data-replace-img]');if(!btn)return;pendingReplaceBtn=btn;replaceInput.value='';replaceInput.click()});
  replaceInput.addEventListener('change',async()=>{
    if(!replaceInput.files.length||!pendingReplaceBtn)return;
    const file=replaceInput.files[0];if(file.size>10*1024*1024){showToast('Image exceeds 10 MB');return}
    const tireItem=pendingReplaceBtn.closest('.tire-item'),card=pendingReplaceBtn.closest('.image-card'),imageId=card.getAttribute('data-image-id'),imgEl=tireItem.querySelector('[data-image-el]'),svBtn=tireItem.querySelector('.save-img-btn');
    tireItem.classList.add('replacing');pendingReplaceBtn.disabled=true;pendingReplaceBtn.textContent='⏳ Uploading…';
    try{const fd=new FormData();fd.append('image_id',imageId);fd.append('image',file);fd.append('type','tire');const r=await fetch('replace-image.php',{method:'POST',body:fd}),d=await r.json();if(!d.ok)throw new Error(d.error||'Failed');const bust=d.image_url+(d.image_url.includes('?')?'&':'?')+'t='+Date.now();imgEl.src=bust;if(svBtn)svBtn.setAttribute('data-src',d.image_url);showToast('✓ Image replaced')}catch(err){showToast('Replace failed: '+(err.message||'unknown'))}finally{tireItem.classList.remove('replacing');pendingReplaceBtn.disabled=false;pendingReplaceBtn.textContent='🔄 Replace';pendingReplaceBtn=null}
  });

  // Approve / Deny / Reset
  document.addEventListener('click',async e=>{
    const decideBtn=e.target.closest('.image-decision .decision-btn');if(!decideBtn)return;
    const card=decideBtn.closest('.image-card'),imageId=card.getAttribute('data-image-id'),newStatus=decideBtn.getAttribute('data-decide'),allBtns=card.querySelectorAll('.decision-btn');
    allBtns.forEach(b=>b.disabled=true);
    try{const fd=new FormData();fd.append('id',imageId);fd.append('status',newStatus);const r=await fetch('tire-status.php',{method:'POST',body:fd}),d=await r.json();if(!d.ok)throw new Error(d.error||'Failed');
      allBtns.forEach(b=>b.classList.toggle('active',b.getAttribute('data-decide')===newStatus));
      const pill=card.querySelector('[data-status-pill]');pill.className='image-card-status '+newStatus;pill.textContent=newStatus.charAt(0).toUpperCase()+newStatus.slice(1);card.setAttribute('data-status',newStatus);
      showToast(newStatus==='approved'?'✓ Approved':newStatus==='denied'?'✕ Denied':'↺ Reset to pending');
    }catch{showToast('Update failed — try again')}finally{allBtns.forEach(b=>b.disabled=false)}
  });

  // Delete tire — redirect back to tires.php after
  document.addEventListener('click',async e=>{
    const btn=e.target.closest('[data-delete-tire]');if(!btn)return;
    if(!confirm('Delete this tire and all its images? This cannot be undone.'))return;
    const tireId=btn.getAttribute('data-delete-tire');btn.disabled=true;btn.textContent='Deleting…';
    try{const fd=new FormData();fd.append('action','delete_tire');fd.append('tire_id',tireId);const r=await fetch('tire-status.php',{method:'POST',body:fd}),d=await r.json();if(!d.ok)throw new Error(d.error||'Failed');
      showToast('✓ Tire deleted');setTimeout(()=>window.location.href='tires.php',500);
    }catch(err){btn.disabled=false;btn.textContent='🗑 Delete tire';showToast('Delete failed: '+(err.message||'unknown'))}
  });

  // Comments
  document.querySelectorAll('[data-comment-input]').forEach(ta=>{
    ta.dataset.savedValue=ta.value;
    ta.addEventListener('input',()=>{const card=ta.closest('.image-card'),sb=card.querySelector('[data-comment-save]'),st=card.querySelector('[data-comment-status]'),dirty=ta.value!==ta.dataset.savedValue;sb.disabled=!dirty;st.className='comment-status'+(dirty?' dirty':'');st.textContent=dirty?'Unsaved changes':''});
  });
  document.addEventListener('click',async e=>{
    const saveBtn=e.target.closest('[data-comment-save]');if(!saveBtn)return;
    const card=saveBtn.closest('.image-card'),imageId=card.getAttribute('data-image-id'),ta=card.querySelector('[data-comment-input]'),status=card.querySelector('[data-comment-status]');
    saveBtn.disabled=true;status.className='comment-status saving';status.textContent='Saving…';
    try{const fd=new FormData();fd.append('id',imageId);fd.append('comment',ta.value);const r=await fetch('tire-status.php',{method:'POST',body:fd}),d=await r.json();if(!d.ok)throw new Error(d.error||'Failed');
      ta.dataset.savedValue=ta.value;status.className='comment-status saved';status.textContent='✓ Saved';showToast('✓ Comment saved');
      setTimeout(()=>{if(ta.value===ta.dataset.savedValue){status.textContent='';status.className='comment-status'}},2500);
    }catch{status.className='comment-status error';status.textContent='Save failed — try again';saveBtn.disabled=false}
  });

  const themeBtn=document.getElementById('themeToggle'),themeIcon=document.getElementById('themeIcon');
  let isDark=window.matchMedia('(prefers-color-scheme:dark)').matches;
  function applyTheme(){document.documentElement.setAttribute('data-theme',isDark?'dark':'light');themeIcon.textContent=isDark?'☀️':'🌙'}
  applyTheme();themeBtn.addEventListener('click',()=>{isDark=!isDark;applyTheme()});
</script>
</body>
</html>
