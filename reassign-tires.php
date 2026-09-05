<?php
/**
 * One-off utility: reassign every tire to a single company.
 * Visit in a browser, pick the target client (Kenda), confirm.
 *
 * Delete this file once you've used it.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
requireAdmin();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$msg    = null;
$errors = [];

// --- POST: do the reassignment ----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = (int)($_POST['company_id'] ?? 0);
    if ($targetId <= 0) {
        $errors[] = 'Please pick a company.';
    } else {
        try {
            // Confirm the target company actually exists
            $chk = $pdo->prepare("SELECT id, name FROM companies WHERE id = ?");
            $chk->execute([$targetId]);
            $target = $chk->fetch();
            if (!$target) { throw new Exception('Unknown company id.'); }

            $pdo->beginTransaction();

            // 1. Move every tire under this company
            $upd = $pdo->prepare("UPDATE tires SET company_id = ?");
            $upd->execute([$targetId]);
            $movedCount = $upd->rowCount();

            // 2. Make sure this company has the tires module enabled
            $pdo->prepare("
                INSERT IGNORE INTO company_modules (company_id, module_id, sort_order)
                SELECT ?, id, 0 FROM modules WHERE slug = 'tires'
            ")->execute([$targetId]);

            // 3. Remove the tires module from any other company that no longer has tires
            $pdo->prepare("
                DELETE FROM company_modules
                WHERE module_id = (SELECT id FROM modules WHERE slug = 'tires')
                  AND company_id NOT IN (SELECT DISTINCT company_id FROM tires)
            ")->execute();

            $pdo->commit();
            $msg = "✓ Moved {$movedCount} tire row(s) to '{$target['name']}', and made sure only that client sees the tires module.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = 'Failed: ' . $e->getMessage();
        }
    }
}

// --- Current state (for display) --------------------------------
$rows = $pdo->query("
    SELECT c.id, c.name, c.slug,
           (SELECT COUNT(*) FROM tires t WHERE t.company_id = c.id) AS tire_count
    FROM companies c
    ORDER BY c.name ASC
")->fetchAll();

$totalTires = (int)$pdo->query("SELECT COUNT(*) FROM tires")->fetchColumn();

// Pre-select Kenda if the slug exists
$preselect = 0;
foreach ($rows as $r) {
    if (stripos($r['slug'], 'kenda') !== false || stripos($r['name'], 'kenda') !== false) {
        $preselect = (int)$r['id'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reassign tires</title>
<style>
  :root {
    --bg:#f0f2f5; --surface:#fff; --surface-2:#f7f8fa; --border:#dadde1;
    --text:#050505; --text-muted:#65676b; --accent:#1877f2; --accent-hover:#166fe5;
    --success:#16a34a; --danger:#dc2626;
  }
  body {
    margin: 0; background: var(--bg); color: var(--text);
    font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    padding: 40px 20px;
  }
  .wrap { max-width: 680px; margin: 0 auto; }
  h1 { margin: 0 0 6px; }
  .sub { color: var(--text-muted); margin: 0 0 24px; }
  .card { background: #fff; border: 1px solid var(--border); border-radius: 12px;
          padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
  .flash { background: #dcfce7; color: #166534; border: 1px solid #86efac;
           padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; }
  .err { color: #991b1b; background: #fee2e2; border: 1px solid #fca5a5;
         padding: 10px; border-radius: 8px; margin-bottom: 10px; }
  .row { display: flex; align-items: center; gap: 14px;
         padding: 12px; border-top: 1px solid var(--border); cursor: pointer;
         border-radius: 8px; transition: background .15s; }
  .row:first-of-type { border-top: none; }
  .row:hover { background: var(--surface-2); }
  .row input[type="radio"] { width: 18px; height: 18px; }
  .row-name { flex: 1; font-weight: 600; }
  .row-slug { font-family: ui-monospace, Menlo, monospace; font-size: 12px;
              color: var(--text-muted); }
  .row-count { font-variant-numeric: tabular-nums; font-size: 14px; font-weight: 600; color: var(--text-muted); }
  .row-count.has { color: var(--success); }
  .actions { display: flex; gap: 10px; justify-content: flex-end;
             margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
  .btn { padding: 10px 18px; border-radius: 8px; font-size: 14px; font-weight: 600;
         border: 1px solid var(--border); background: var(--surface-2); color: var(--text);
         text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
  .btn:hover { background: var(--border); }
  .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .btn.primary:hover { background: var(--accent-hover); }
  .summary { font-size: 13px; color: var(--text-muted); margin-top: 10px; }
  code { background: var(--surface-2); padding: 2px 6px; border-radius: 4px; font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Reassign all tires</h1>
  <p class="sub">One-off tool. Moves every row in <code>tires</code> to whichever client you pick, and tidies up <code>company_modules</code> so only that client sees the tires module.</p>

  <?php if ($msg): ?>
    <div class="flash"><?= h($msg) ?></div>
    <div class="card">
      <p>You can delete this file now — it's not linked from anywhere else.</p>
      <p><a class="btn primary" href="admin.php">→ Back to admin</a></p>
    </div>
  <?php else: ?>

    <?php foreach ($errors as $err): ?>
      <div class="err">⚠ <?= h($err) ?></div>
    <?php endforeach; ?>

    <div class="card">
      <div class="summary">
        <strong><?= $totalTires ?></strong> tire row<?= $totalTires === 1 ? '' : 's' ?> in the database.
        Pick where they should all live:
      </div>

      <form method="POST" action="reassign-tires.php" style="margin-top:12px;">
        <?php foreach ($rows as $r): ?>
          <label class="row">
            <input type="radio" name="company_id" value="<?= (int)$r['id'] ?>"
                   <?= (int)$r['id'] === $preselect ? 'checked' : '' ?>>
            <div class="row-name">
              <?= h($r['name']) ?>
              <div class="row-slug">/<?= h($r['slug']) ?></div>
            </div>
            <div class="row-count <?= (int)$r['tire_count'] > 0 ? 'has' : '' ?>">
              <?= (int)$r['tire_count'] ?> currently
            </div>
          </label>
        <?php endforeach; ?>

        <div class="actions">
          <a class="btn" href="admin.php">Cancel</a>
          <button type="submit" class="btn primary"
                  onclick="return confirm('Move every tire to the selected client? This overwrites existing company assignments.');">
            Reassign all tires →
          </button>
        </div>
      </form>
    </div>

  <?php endif; ?>
</div>
</body>
</html>
