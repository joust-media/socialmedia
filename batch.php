<?php
require_once 'db.php';
require_once 'helpers.php';

$client = resolveClient($pdo);
$clientSlug = $_GET['client'] ?? '';
$clientName = $client ? $client['name'] : 'Unknown';

// Get categories for display
$catStmt = $pdo->query('SELECT id, name FROM categories ORDER BY sort_order');
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Get latest scheduled date for this client
$latestDate = null;
if ($client) {
    $dateStmt = $pdo->prepare('SELECT MAX(scheduled_date) FROM posts WHERE company_id = ?');
    $dateStmt->execute([$client['id']]);
    $latestDate = $dateStmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Upload — <?= htmlspecialchars($clientName) ?></title>
    <style>
        :root {
            --bg: #ffffff;
            --bg-card: #f8f9fa;
            --bg-hover: #e9ecef;
            --text: #212529;
            --text-muted: #6c757d;
            --border: #dee2e6;
            --accent: #0d6efd;
            --accent-hover: #0b5ed7;
            --success: #198754;
            --danger: #dc3545;
            --warning: #fd7e14;
            --radius: 8px;
            --shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        [data-theme="dark"] {
            --bg: #1a1a2e;
            --bg-card: #16213e;
            --bg-hover: #0f3460;
            --text: #e0e0e0;
            --text-muted: #a0a0a0;
            --border: #2a2a4a;
            --accent: #4dabf7;
            --accent-hover: #339af0;
            --success: #51cf66;
            --danger: #ff6b6b;
            --warning: #ffa94d;
            --shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            padding: 20px;
            max-width: 720px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .topbar h1 {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .topbar-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text);
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.15s;
        }

        .btn:hover { background: var(--bg-hover); }

        .btn-primary {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .theme-toggle {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 4px;
        }

        /* Info box */
        .info-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .info-box strong { color: var(--accent); }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Category legend */
        .cat-legend {
            margin-bottom: 20px;
        }

        .cat-legend summary {
            cursor: pointer;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .cat-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .cat-chip {
            font-size: 0.75rem;
            padding: 3px 10px;
            border-radius: 20px;
            background: var(--bg-hover);
            color: var(--text-muted);
            border: 1px solid var(--border);
        }

        /* Drop zone */
        .drop-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 48px 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            margin-bottom: 20px;
        }

        .drop-zone:hover,
        .drop-zone.dragover {
            border-color: var(--accent);
            background: color-mix(in srgb, var(--accent) 5%, transparent);
        }

        .drop-zone-icon {
            font-size: 2.5rem;
            margin-bottom: 8px;
        }

        .drop-zone-text {
            font-size: 0.95rem;
            color: var(--text-muted);
        }

        .drop-zone-text strong {
            color: var(--accent);
            cursor: pointer;
        }

        .drop-zone-hint {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* File preview list */
        .file-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
        }

        .file-thumb {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .file-info {
            flex: 1;
            min-width: 0;
        }

        .file-name {
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .file-cats {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
        }

        .file-cat {
            font-size: 0.7rem;
            padding: 1px 8px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--accent) 15%, transparent);
            color: var(--accent);
        }

        .file-remove {
            background: none;
            border: none;
            color: var(--danger);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 4px;
            opacity: 0.6;
            transition: opacity 0.15s;
        }

        .file-remove:hover { opacity: 1; }

        /* Results */
        .results {
            margin-top: 20px;
        }

        .result-item {
            padding: 10px 14px;
            margin-bottom: 8px;
            border-radius: var(--radius);
            font-size: 0.85rem;
        }

        .result-ok {
            background: color-mix(in srgb, var(--success) 10%, transparent);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .result-err {
            background: color-mix(in srgb, var(--danger) 10%, transparent);
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        /* Progress bar */
        .progress-wrap {
            display: none;
            margin-bottom: 20px;
        }

        .progress-bar {
            height: 6px;
            background: var(--bg-hover);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--accent);
            width: 0%;
            transition: width 0.3s;
        }

        .progress-text {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 6px;
            text-align: center;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--text);
            color: var(--bg);
            padding: 10px 20px;
            border-radius: var(--radius);
            font-size: 0.85rem;
            opacity: 0;
            transition: transform 0.3s, opacity 0.3s;
            z-index: 1000;
        }

        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        input[type="file"] { display: none; }
    </style>
</head>
<body>
    <div class="topbar">
        <h1>⚡ Batch Upload — <?= htmlspecialchars($clientName) ?></h1>
        <div class="topbar-actions">
            <a href="admin.php<?= $clientSlug ? '?client=' . urlencode($clientSlug) : '' ?>" class="btn">← Admin</a>
            <a href="feed.php<?= $clientSlug ? '?client=' . urlencode($clientSlug) : '' ?>" class="btn">Feed</a>
            <button class="theme-toggle" onclick="toggleTheme()">🌙</button>
        </div>
    </div>

    <div class="info-box">
        Upload images and each one becomes a post, spaced <strong>3 days apart</strong> starting from the latest scheduled post.
        Captions default to placeholder text — edit them later in the feed.
        <strong>Name your files</strong> with category keywords to auto-tag (e.g. <code>yamaha_atv_trail.jpg</code>).
        <div class="info-row">
            <span>Latest post: <strong><?= $latestDate ? date('M j, Y', strtotime($latestDate)) : 'None — starts today' ?></strong></span>
            <span>Max 20 images per batch</span>
        </div>
    </div>

    <details class="cat-legend">
        <summary>Category keywords for filenames</summary>
        <div class="cat-chips">
            <?php foreach ($categories as $cat): ?>
                <span class="cat-chip"><?= htmlspecialchars(strtolower($cat['name'])) ?></span>
            <?php endforeach; ?>
        </div>
    </details>

    <div class="drop-zone" id="dropZone">
        <div class="drop-zone-icon">📁</div>
        <div class="drop-zone-text"><strong>Click to browse</strong> or drag images here</div>
        <div class="drop-zone-hint">JPG, PNG, WebP — up to 10 MB each</div>
    </div>
    <input type="file" id="fileInput" multiple accept="image/*">

    <ul class="file-list" id="fileList"></ul>

    <div class="progress-wrap" id="progressWrap">
        <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
        <div class="progress-text" id="progressText">Uploading…</div>
    </div>

    <button class="btn btn-primary" id="uploadBtn" disabled style="width:100%;justify-content:center;padding:12px;">
        🚀 Create Posts
    </button>

    <div class="results" id="results"></div>

    <div class="toast" id="toast"></div>

    <script>
    // Category list for client-side preview
    const categories = <?= json_encode(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $categories)) ?>;
    const clientSlug = '<?= htmlspecialchars($clientSlug) ?>';

    let selectedFiles = [];

    const dropZone   = document.getElementById('dropZone');
    const fileInput   = document.getElementById('fileInput');
    const fileList    = document.getElementById('fileList');
    const uploadBtn   = document.getElementById('uploadBtn');
    const progressWrap = document.getElementById('progressWrap');
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    const resultsDiv  = document.getElementById('results');

    // Theme
    function toggleTheme() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        document.documentElement.setAttribute('data-theme', isDark ? '' : 'dark');
        document.querySelector('.theme-toggle').textContent = isDark ? '🌙' : '☀️';
        localStorage.setItem('theme', isDark ? 'light' : 'dark');
    }
    if (localStorage.getItem('theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.querySelector('.theme-toggle').textContent = '☀️';
    }

    // Drop zone events
    dropZone.addEventListener('click', () => fileInput.click());
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        addFiles(e.dataTransfer.files);
    });
    fileInput.addEventListener('change', () => {
        addFiles(fileInput.files);
        fileInput.value = '';
    });

    function addFiles(fileListInput) {
        for (const f of fileListInput) {
            if (!f.type.startsWith('image/')) continue;
            if (f.size > 10 * 1024 * 1024) { showToast(`${f.name} exceeds 10 MB`); continue; }
            if (selectedFiles.length >= 20) { showToast('Max 20 images'); break; }
            if (selectedFiles.some(sf => sf.name === f.name && sf.size === f.size)) continue;
            selectedFiles.push(f);
        }
        renderFileList();
    }

    function matchCategories(filename) {
        const base = filename.replace(/\.[^.]+$/, '').toLowerCase().replace(/[-_.]/g, ' ');
        const matched = [];

        // Sort categories by name length (longest first) to avoid partial matches
        const sorted = [...categories].sort((a, b) => b.name.length - a.name.length);

        for (const cat of sorted) {
            if (base.includes(cat.name.toLowerCase())) {
                matched.push(cat);
            }
        }

        // Also check aliases
        const aliases = {
            'offroad': 'off-road',
            'off road': 'off-road',
            'dualsport': 'dual sport',
            'sportatv': 'sport atv',
        };

        for (const [alias, catName] of Object.entries(aliases)) {
            if (base.includes(alias)) {
                const cat = categories.find(c => c.name.toLowerCase() === catName);
                if (cat && !matched.find(m => m.id === cat.id)) {
                    matched.push(cat);
                }
            }
        }

        return matched;
    }

    function renderFileList() {
        fileList.innerHTML = '';
        selectedFiles.forEach((f, i) => {
            const li = document.createElement('li');
            li.className = 'file-item';

            const thumb = document.createElement('img');
            thumb.className = 'file-thumb';
            thumb.src = URL.createObjectURL(f);

            const info = document.createElement('div');
            info.className = 'file-info';

            const name = document.createElement('div');
            name.className = 'file-name';
            name.textContent = f.name;

            const meta = document.createElement('div');
            meta.className = 'file-meta';
            meta.textContent = (f.size / 1024 / 1024).toFixed(1) + ' MB';

            const cats = matchCategories(f.name);
            const catsDiv = document.createElement('div');
            catsDiv.className = 'file-cats';
            if (cats.length) {
                cats.forEach(c => {
                    const span = document.createElement('span');
                    span.className = 'file-cat';
                    span.textContent = c.name;
                    catsDiv.appendChild(span);
                });
            } else {
                const span = document.createElement('span');
                span.className = 'file-meta';
                span.textContent = 'No category matched';
                catsDiv.appendChild(span);
            }

            info.appendChild(name);
            info.appendChild(meta);
            info.appendChild(catsDiv);

            const removeBtn = document.createElement('button');
            removeBtn.className = 'file-remove';
            removeBtn.textContent = '✕';
            removeBtn.onclick = () => {
                selectedFiles.splice(i, 1);
                renderFileList();
            };

            li.appendChild(thumb);
            li.appendChild(info);
            li.appendChild(removeBtn);
            fileList.appendChild(li);
        });

        uploadBtn.disabled = selectedFiles.length === 0;
    }

    // Upload
    uploadBtn.addEventListener('click', async () => {
        if (!selectedFiles.length) return;

        uploadBtn.disabled = true;
        progressWrap.style.display = 'block';
        progressFill.style.width = '0%';
        progressText.textContent = `Uploading ${selectedFiles.length} image${selectedFiles.length > 1 ? 's' : ''}…`;
        resultsDiv.innerHTML = '';

        const formData = new FormData();
        selectedFiles.forEach(f => formData.append('images[]', f));

        try {
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    progressFill.style.width = pct + '%';
                    progressText.textContent = `Uploading… ${pct}%`;
                }
            });

            const response = await new Promise((resolve, reject) => {
                xhr.onload = () => {
                    try { resolve(JSON.parse(xhr.responseText)); }
                    catch { reject(new Error('Invalid response')); }
                };
                xhr.onerror = () => reject(new Error('Network error'));
                xhr.open('POST', 'batch-process.php?client=' + encodeURIComponent(clientSlug));
                xhr.send(formData);
            });

            progressFill.style.width = '100%';
            progressText.textContent = 'Done!';

            if (response.ok) {
                // Show results
                if (response.created && response.created.length) {
                    response.created.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'result-item result-ok';
                        const date = new Date(item.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                        div.textContent = `✓ ${item.filename} → Post #${item.post_id} scheduled for ${date}`;
                        resultsDiv.appendChild(div);
                    });
                }

                if (response.errors && response.errors.length) {
                    response.errors.forEach(err => {
                        const div = document.createElement('div');
                        div.className = 'result-item result-err';
                        div.textContent = `✗ ${err}`;
                        resultsDiv.appendChild(div);
                    });
                }

                showToast(`${response.count} post${response.count !== 1 ? 's' : ''} created`);
                selectedFiles = [];
                renderFileList();
            } else {
                showToast(response.error || 'Upload failed');
            }
        } catch (err) {
            progressText.textContent = 'Error';
            showToast('Upload failed: ' + err.message);
        }

        uploadBtn.disabled = selectedFiles.length === 0;
    });

    // Toast
    function showToast(msg) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3000);
    }
    </script>
</body>
</html>
