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
// The Joust mark (static/brand/joust*.png) — favicon, touch icon and the brand row; '' when a file is missing.
$brandUrl = static function (string $name) use ($base): string {
    $file = __DIR__ . '/static/brand/' . $name;
    return is_file($file) ? $base . '/static/brand/' . $name . '?v=' . filemtime($file) : '';
};
$joustLogo = $brandUrl('joust.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<?php // Appearance boot — a copy of themeBootScript() (helpers.php): the stored Light/Dark choice before first paint. ?>
<script data-theme-boot>(function(){var d=document.documentElement;if(d.hasAttribute("data-theme")){d.setAttribute("data-theme-pinned","");return;}try{var t=localStorage.getItem("portal.theme");if(t==="light"||t==="dark"){d.setAttribute("data-theme",t);var c=t==="dark"?"#000000":"#F2F2F7",m=document.querySelectorAll('meta[name="theme-color"]');for(var i=0;i<m.length;i++)m[i].setAttribute("content",c);}}catch(e){}})();</script>
<title>Sign in — Joust Admin</title>
<?php if ($brandUrl('joust-32.png') !== ''): ?><link rel="icon" type="image/png" sizes="32x32" href="<?= h($brandUrl('joust-32.png')) ?>">
<?php endif; ?><?php if ($joustLogo !== ''): ?><link rel="icon" type="image/png" sizes="512x512" href="<?= h($joustLogo) ?>">
<?php endif; ?><?php if ($brandUrl('joust-180.png') !== ''): ?><link rel="apple-touch-icon" sizes="180x180" href="<?= h($brandUrl('joust-180.png')) ?>">
<?php endif; ?><link rel="stylesheet" href="<?= h($cssUrl('tokens.css')) ?>">
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
  .brand-mark--img { object-fit: cover; background: transparent; box-shadow: inset 0 0 0 0.5px var(--separator); }
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
  /* Appearance toggle (same button as the portal nav bar; components.css is not loaded here) */
  .theme-toggle {
    position: fixed; top: max(14px, env(safe-area-inset-top)); right: max(14px, env(safe-area-inset-right)); z-index: 10;
    width: 36px; height: 36px; padding: 0; border: 0; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--fill-tertiary); color: var(--label); cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .theme-toggle:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .theme-toggle svg { display: none; width: 20px; height: 20px; }
  html:not([data-theme]) .theme-toggle .ui-icon--sun-moon,
  html[data-theme="light"] .theme-toggle .ui-icon--sun,
  html[data-theme="dark"]  .theme-toggle .ui-icon--moon { display: block; }
  .theme-toast {
    position: fixed; left: 50%; bottom: 24px; transform: translate(-50%, 8px);
    padding: 10px 16px; border-radius: var(--radius-pill);
    background: var(--label); color: var(--bg-elevated);
    font-size: var(--text-subhead); font-weight: 600; letter-spacing: var(--ls-subhead);
    opacity: 0; pointer-events: none; transition: opacity var(--dur-base) var(--ease-out), transform var(--dur-base) var(--ease-out);
  }
  .theme-toast.is-visible { opacity: 1; transform: translate(-50%, 0); }
  @media (prefers-reduced-motion: reduce) { button[type="submit"]:active { transform: none; } .theme-toast { transition: none; } }
</style>
</head>
<body>
<button type="button" class="theme-toggle" data-theme-toggle aria-label="Appearance" title="Appearance: Light, Dark or Auto">
  <svg class="ui-icon ui-icon--sun" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2.6v2.3M12 19.1v2.3M2.6 12h2.3M19.1 12h2.3M5.35 5.35l1.65 1.65M17 17l1.65 1.65M5.35 18.65 7 17M17 7l1.65-1.65"/></svg>
  <svg class="ui-icon ui-icon--moon" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.3 14.4A8.4 8.4 0 0 1 9.6 3.7a8.4 8.4 0 1 0 10.7 10.7Z"/></svg>
  <svg class="ui-icon ui-icon--sun-moon" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.4"/><path d="M12 3.6a8.4 8.4 0 0 0 0 16.8Z" fill="currentColor" stroke="none"/></svg>
</button>
<div class="theme-toast" id="themeToast" role="status" aria-live="polite"></div>
<script>
  // Light → Dark → Auto, persisted the same way as App.theme (app.js) so the portal follows.
  (function () {
    var KEY = 'portal.theme', order = ['light', 'dark', 'auto'], word = { light: 'Light', dark: 'Dark', auto: 'Auto' };
    var labels = { light: 'Light mode', dark: 'Dark mode', auto: 'Auto (follows your device)' };
    var colors = { light: '#F2F2F7', dark: '#000000' };
    var btn = document.querySelector('[data-theme-toggle]'), toast = document.getElementById('themeToast'), timer = null;
    function get() { var t = null; try { t = localStorage.getItem(KEY); } catch (e) {} return (t === 'light' || t === 'dark') ? t : 'auto'; }
    function apply() {
      var mode = get(), root = document.documentElement, next = order[(order.indexOf(mode) + 1) % order.length];
      if (mode === 'auto') root.removeAttribute('data-theme'); else root.setAttribute('data-theme', mode);
      Array.prototype.forEach.call(document.querySelectorAll('meta[name="theme-color"]'), function (m) {
        if (mode === 'auto') m.setAttribute('content', (m.getAttribute('media') || '').indexOf('dark') >= 0 ? colors.dark : colors.light);
        else m.setAttribute('content', colors[mode]);
      });
      btn.setAttribute('aria-label', 'Appearance: ' + labels[mode] + '. Switch to ' + word[next].toLowerCase());
      btn.setAttribute('title', 'Appearance: ' + word[mode] + ' — click for ' + word[next]);
      btn.setAttribute('data-theme-state', mode);
    }
    btn.addEventListener('click', function () {
      var mode = order[(order.indexOf(get()) + 1) % order.length];
      try { if (mode === 'auto') localStorage.removeItem(KEY); else localStorage.setItem(KEY, mode); } catch (e) {}
      apply();
      toast.textContent = labels[mode];
      toast.classList.remove('is-visible'); void toast.offsetWidth; toast.classList.add('is-visible');
      clearTimeout(timer); timer = setTimeout(function () { toast.classList.remove('is-visible'); }, 2200);
    });
    apply();
  })();
</script>
<div class="wrap">
  <form class="card" method="POST" action="<?= h($base . '/login' . $ext . ($returnRaw ? '?return=' . urlencode($returnUrl) : '')) ?>">
    <div class="brand">
      <?php if ($joustLogo !== ''): ?>
        <img class="brand-mark brand-mark--img" src="<?= h($joustLogo) ?>" alt="" width="36" height="36">
      <?php else: ?>
        <div class="brand-mark">J</div>
      <?php endif; ?>
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
