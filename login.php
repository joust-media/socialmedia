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

// Explicit .php links (unless CLEAN_URLS is on, see helpers.php) so sign-in works on a
// folder with no extension-less rewrite. login.php loads only auth.php, not helpers.php.
$ext = (defined('CLEAN_URLS') && CLEAN_URLS) ? '' : '.php';

// Where to send the user after a successful login. Default = the Studio.
$fallback  = $base . '/studio' . $ext;
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
<?php
// Shared tokens + base (system font, --bg / --bg-elevated, light/dark by system preference) — same
// cache-busting as helpers.php staticUrl(), which this page does not load.
$cssUrl = static function (string $name) use ($base): string {
    $file = __DIR__ . '/static/css/' . $name;
    return $base . '/static/css/' . $name . (is_file($file) ? '?v=' . filemtime($file) : '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title>Sign in — Joust Admin</title>
<link rel="stylesheet" href="<?= h($cssUrl('tokens.css')) ?>">
<link rel="stylesheet" href="<?= h($cssUrl('base.css')) ?>">
<style>
  .wrap {
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh; min-height: 100dvh; padding: 24px;
  }
  .card {
    width: 100%; max-width: 380px;
    background: var(--bg-elevated);
    border-radius: var(--radius-card);
    box-shadow: var(--shadow-card), 0 0 0 0.5px var(--separator);
    padding: 28px 28px 24px;
  }
  .brand {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 18px;
    font-size: var(--text-headline); line-height: var(--lh-headline);
    letter-spacing: var(--ls-headline); font-weight: var(--fw-headline);
  }
  .brand-mark {
    width: 36px; height: 36px; border-radius: 9px;
    background: var(--joust); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 18px; letter-spacing: 0;
  }
  h1 {
    margin: 0 0 4px;
    font-size: var(--text-title2); line-height: var(--lh-title2);
    letter-spacing: var(--ls-title2); font-weight: var(--fw-title2);
  }
  .sub {
    margin: 0 0 18px; color: var(--label-secondary);
    font-size: var(--text-subhead); line-height: var(--lh-subhead); letter-spacing: var(--ls-subhead);
  }
  .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
  label {
    font-size: var(--text-footnote); line-height: var(--lh-footnote);
    font-weight: 600; text-transform: uppercase; letter-spacing: 0.2px;
    color: var(--label-secondary);
  }
  input[type="email"], input[type="password"] {
    width: 100%; min-height: 44px; padding: 10px 12px;
    border: 0; border-radius: var(--radius-ctl);
    background: var(--fill-tertiary); color: var(--label);
    font-size: var(--text-body); line-height: var(--lh-body); letter-spacing: var(--ls-body);
  }
  input:focus-visible { outline: 2px solid var(--accent); outline-offset: 0; border-radius: var(--radius-ctl); }
  button[type="submit"] {
    width: 100%; min-height: 44px; margin-top: 6px; padding: 10px 14px;
    border: 0; border-radius: var(--radius-ctl);
    background: var(--accent); color: #fff;
    font-size: var(--text-body); font-weight: 600; letter-spacing: var(--ls-body);
    transition: opacity var(--dur-fast) var(--ease-out), transform var(--dur-fast) var(--ease-out);
  }
  button[type="submit"]:hover { opacity: .9; }
  button[type="submit"]:active { transform: scale(0.98); }
  .notice {
    margin-bottom: 14px; padding: 10px 12px; border-radius: 12px;
    font-size: var(--text-subhead); line-height: var(--lh-subhead); letter-spacing: var(--ls-subhead); font-weight: 600;
  }
  .notice--error { background: rgba(255,59,48,.14); color: var(--deny); }
  .notice--ok    { background: rgba(52,199,89,.15); color: var(--approve); }
  .footnote {
    margin-top: 18px; padding-top: 14px; text-align: center;
    box-shadow: inset 0 0.5px 0 var(--separator);
    font-size: var(--text-footnote); line-height: var(--lh-footnote); color: var(--label-secondary);
  }
  @media (prefers-reduced-motion: reduce) { button[type="submit"]:active { transform: none; } }
</style>
</head>
<body>
<div class="wrap">
  <form class="card" method="POST" action="<?= h($base . '/login' . $ext . ($returnRaw ? '?return=' . urlencode($returnUrl) : '')) ?>">
    <div class="brand">
      <div class="brand-mark">J</div>
      <span>Joust Media</span>
    </div>
    <h1>Sign in</h1>
    <p class="sub">Admin access only.</p>

    <?php if ($error): ?>
      <div class="notice notice--error" role="alert"><?= h($error) ?></div>
    <?php elseif (!empty($_GET['signed_out'])): ?>
      <div class="notice notice--ok" role="status">Signed out. Sign in again to continue.</div>
      <script>
        // logout.php only redirects (no DOM), so the signed-out state of this page is the first
        // same-origin document after sign-out: drop App.video's cached poster frames / durations
        // (localStorage poster:* nopos:* duration:*) so client media does not linger on a shared device.
        (function () {
          try {
            var drop = [];
            for (var i = 0; i < localStorage.length; i++) {
              var k = localStorage.key(i);
              if (k && /^(poster|nopos|duration):/.test(k)) drop.push(k);
            }
            drop.forEach(function (k) { localStorage.removeItem(k); });
          } catch (e) {}
        })();
      </script>
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
