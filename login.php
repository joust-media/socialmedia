<?php
/**
 * Admin sign-in. POST credentials → adminLogin() → redirect to ?return=.
 * GET = the form. Authenticated users skip straight through.
 */
require __DIR__ . '/auth.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Resolve the URL prefix (matches helpers.php basePath) so links work under a subdir.
$script = $_SERVER['SCRIPT_NAME'] ?? '/login.php';
$base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
if ($base === '.' || $base === '') { $base = ''; }

// Where to send the user after a successful login. Default = the Studio.
$fallback  = $base . '/studio';
$returnRaw = (isset($_GET['return']) && is_string($_GET['return'])) ? $_GET['return'] : $fallback;
$returnUrl = $returnRaw;
// Only allow same-app redirects — never an off-site URL: the path must start with
// this app's prefix, may not begin with "//" or "/\" (browsers normalise both to a
// scheme-relative URL), and may not contain control characters or backslashes.
if (!preg_match('#^/(?![/\\\\])#', $returnUrl)
    || preg_match('#[\x00-\x1f\x7f\\\\]#', $returnUrl)
    || strpos($returnUrl, $base . '/') !== 0) {
    $returnUrl = $fallback;
}

if (currentAdmin()) {
    header('Location: ' . $returnUrl);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email']    ?? '';
    $pw    = $_POST['password'] ?? '';
    define('JSM_FORCE_SESSION', 1);   // the sign-in POST may create the first session (auth.php)
    if (adminLogin($email, $pw)) {
        header('Location: ' . $returnUrl);
        exit;
    }
    $error = 'Email or password is incorrect.';
    // Tiny delay so brute-force attempts aren't free.
    usleep(400 * 1000);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — Joust Admin</title>
<style>
  :root {
    --bg:#18191a; --surface:#242526; --surface-2:#3a3b3c; --border:#3e4042;
    --text:#e4e6eb; --text-muted:#b0b3b8; --accent:#2d88ff; --accent-hover:#4599ff;
    --danger:#ef4444;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 1px 3px rgba(0,0,0,0.3);
  }
  * { box-sizing: border-box; }
  html, body { margin:0; padding:0; background:var(--bg); color:var(--text);
    font: 15px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    min-height: 100vh; }
  .wrap {
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh; padding: 24px;
  }
  .card {
    width: 100%; max-width: 380px;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 16px; box-shadow: var(--shadow);
    padding: 28px 28px 24px;
  }
  .brand {
    display: flex; align-items: center; gap: 10px;
    font-weight: 700; font-size: 18px; color: var(--text);
    margin-bottom: 18px;
  }
  .brand-mark {
    width: 36px; height: 36px; border-radius: 8px;
    background: var(--accent); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 18px;
  }
  h1 {
    font-size: 20px; font-weight: 700; margin: 0 0 6px;
    letter-spacing: -0.3px;
  }
  .sub {
    color: var(--text-muted); font-size: 13px;
    margin: 0 0 18px;
  }
  .field { display:flex; flex-direction:column; gap:6px; margin-bottom:14px; }
  label {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    color: var(--text-muted);
  }
  input[type="email"], input[type="password"] {
    background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text); padding: 11px 12px; border-radius: 8px;
    font: inherit; font-size: 15px;
  }
  input:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(45,136,255,0.15);
  }
  button[type="submit"] {
    width: 100%; padding: 11px 14px;
    background: var(--accent); color: #fff;
    border: 1px solid var(--accent); border-radius: 8px;
    font-size: 15px; font-weight: 700; letter-spacing: 0.1px;
    cursor: pointer; transition: background 0.15s, transform 0.1s;
    margin-top: 6px;
  }
  button[type="submit"]:hover { background: var(--accent-hover); }
  button[type="submit"]:active { transform: scale(0.99); }
  .error {
    background: #7f1d1d; color: #fecaca; border: 1px solid #991b1b;
    padding: 10px 12px; border-radius: 8px;
    font-size: 13px; margin-bottom: 14px;
  }
  .footnote {
    margin-top: 18px; padding-top: 14px;
    border-top: 1px solid var(--border);
    font-size: 12px; color: var(--text-muted);
    text-align: center;
  }
</style>
</head>
<body>
<div class="wrap">
  <form class="card" method="POST" action="<?= h($base . '/login' . ($returnRaw ? '?return=' . urlencode($returnUrl) : '')) ?>">
    <div class="brand">
      <div class="brand-mark">J</div>
      <span>Joust Media</span>
    </div>
    <h1>Sign in</h1>
    <p class="sub">Admin access only.</p>

    <?php if ($error): ?>
      <div class="error">⚠ <?= h($error) ?></div>
    <?php elseif (!empty($_GET['signed_out'])): ?>
      <div class="error" style="background:#14532d;color:#bbf7d0;border-color:#166534;">
        ✓ Signed out. Sign in again to continue.
      </div>
    <?php endif; ?>

    <div class="field">
      <label for="email">Email</label>
      <input type="email" name="email" id="email" required autofocus autocomplete="username"
             value="<?= h($_POST['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" name="password" id="password" required autocomplete="current-password">
    </div>

    <button type="submit">Sign in</button>

    <div class="footnote">Need access? Talk to Lance.</div>
  </form>
</div>
</body>
</html>
