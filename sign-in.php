<?php
/**
 * Client sign-in by magic link (the Joust team signs in at login.php).
 *
 *   GET                       the form: "Your email" → Email me a sign-in link
 *       ?client=<slug>        the client's logo next to the Joust mark (arriving through a client link)
 *       ?return=<path>        where to land after signing in (same-app paths only, clientSafeReturn())
 *       ?reason=signin|other|expired|signed_out   the friendly line above the form
 *   POST action=request       email → clientMagicRequest(): the SAME "check your email" answer whether or not the
 *                             address is on a client's list (429 + "try again in a few minutes" when the address or
 *                             IP asked too often — counted for unknown addresses too). The email goes out after the
 *                             response is flushed, so the timing does not tell either.
 *   GET  ?t=<token>           the link from the email: "Continue as …" (a button, so mail scanners that open links
 *                             cannot spend the single-use token); a dead link → the form with "expired"
 *   POST action=consume t=…   use the token once → 30-day session (client-auth-lib.php clientSessionStart) → return path
 *                             (or the client's Home)
 * Already signed in (and not sent here because the link is for another client) → straight to the return path / Home.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$ip      = clientRequestIp();
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action  = $method === 'POST' ? (string)($_POST['action'] ?? '') : '';
$return  = clientSafeReturn(is_string($_REQUEST['return'] ?? null) ? $_REQUEST['return'] : '');
$reason  = is_string($_GET['reason'] ?? null) ? $_GET['reason'] : (!empty($_GET['signed_out']) ? 'signed_out' : '');
$hint    = $client;   // ?client= (helpers.php) — only for the logo / wording
$ready   = clientAuthReady($pdo);
$state   = 'form';    // form | sent | confirm | limited
$error   = '';
$emailIn = '';
$peek    = null;
$token   = '';
$deferred = null;

if ($method === 'POST') {
    requireSameSiteFetch();
}

// Signed in already: go on (unless this link is for another client — then offer to switch).
$sess = currentClientSession($pdo);
if ($sess && $method === 'GET' && $reason !== 'other' && empty($_GET['t'])) {
    $dest = $return !== '' ? $return : portalUrl('index', ['client' => (string)$sess['slug']]);
    header('Location: ' . $dest, true, 302);
    exit;
}

if ($action === 'request') {
    $emailIn = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    if (!$ready) {
        $error = 'Sign-in isn’t switched on yet. Please contact Joust.';
    } else {
        $res = clientMagicRequest($pdo, $emailIn, $ip, $return, (string)($hint['slug'] ?? ''));
        if ($res['status'] === 'invalid') {
            $error = 'Enter the email address Joust has on file for you.';
        } elseif ($res['status'] === 'limited') {
            http_response_code(429);
            header('Retry-After: 900');
            $state = 'limited';
        } else {
            $state = 'sent';           // known and unknown addresses get exactly this
            $deferred = $res['send'];  // null for an unknown address
        }
    }
} elseif ($action === 'consume') {
    $token = is_string($_POST['t'] ?? null) ? $_POST['t'] : '';
    $row = $ready ? clientMagicConsume($pdo, $token) : null;
    if ($row) {
        clientSessionStart($pdo, $row, 'magic');
        $dest = clientSafeReturn((string)($row['return_path'] ?? ''));
        if ($dest === '') $dest = portalUrl('index', ['client' => (string)$row['slug']]);
        header('Cache-Control: no-store');
        header('Location: ' . $dest, true, 303);
        exit;
    }
    $error = 'That sign-in link has expired or was already used. Enter your email for a new one.';
} elseif ($method === 'GET' && is_string($_GET['t'] ?? null) && $_GET['t'] !== '') {
    $token = $_GET['t'];
    $peek = $ready ? clientMagicPeek($pdo, $token) : null;
    if ($peek) {
        $state = 'confirm';
        $hint = ['name' => $peek['company_name'], 'slug' => $peek['slug'], 'logo_url' => $peek['logo_url']];
    } else {
        $error = 'That sign-in link has expired or was already used. Enter your email for a new one.';
    }
}

// ---- the page ---------------------------------------------------------------------------------------------
$hintName = (string)($hint['name'] ?? '');
$hintLogo = $hint ? brandLogoUrl($hint['logo_url'] ?? '', (string)($hint['slug'] ?? '')) : '';
$joust    = joustLogoUrl();
$notice   = '';
$noticeKind = 'info';
if ($error !== '') {
    $notice = $error; $noticeKind = 'error';
} elseif ($state === 'form') {
    if ($reason === 'other' && $sess) {
        $notice = 'You’re signed in for ' . $sess['company_name'] . '. ' . ($hintName !== '' ? 'This link is for ' . $hintName . ' — sign in with an email on their list.' : 'This link is for another client.');
    } elseif ($reason === 'expired') {
        $notice = 'That link has expired or is no longer valid. Enter your email and we’ll send a fresh one.';
    } elseif ($reason === 'signed_out') {
        $notice = 'You’re signed out.'; $noticeKind = 'ok';
    } elseif ($reason === 'signin' || $reason === 'other') {
        $notice = $hintName !== '' ? 'Please sign in to open the ' . $hintName . ' review portal.' : 'Please sign in to continue.';
    }
}
$self = portalUrl('sign-in', array_filter(['client' => $hint['slug'] ?? null, 'return' => $return !== '' ? $return : null]));

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');   // the ?t= token never leaks to another site
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="robots" content="noindex">
<?= themeBootScript() ?>
<title>Sign in<?= $hintName !== '' ? ' — ' . esc($hintName) : '' ?> — Joust Media</title>
<?= appIconTags() ?>
<?= appStylesheets() ?>
<link rel="stylesheet" href="<?= esc(staticUrl('css/sign-in.css')) ?>">
<?= portalUrlsScript() ?>
<?= appScript() ?>
</head>
<body class="ui-body signin-body" data-signin-state="<?= esc($state) ?>">
<div class="signin-theme"><?= themeToggleButton() ?></div>
<main class="signin-wrap">
  <div class="signin-card" data-signin>
    <div class="signin-brand">
      <span class="signin-pair">
        <?php if ($joust !== ''): ?><img class="signin-mark" src="<?= esc($joust) ?>" alt="" width="40" height="40"><?php else: ?><span class="signin-mark signin-mark--initial">J</span><?php endif; ?>
        <?php if ($hintName !== ''): ?>
          <span class="signin-x" aria-hidden="true">×</span>
          <?php if ($hintLogo !== ''): ?><img class="signin-mark signin-mark--client" src="<?= esc($hintLogo) ?>" alt="" width="40" height="40" data-signin-client-logo><?php else: ?><span class="signin-mark signin-mark--initial" data-signin-client-logo><?= esc(mb_strtoupper(mb_substr($hintName, 0, 1))) ?></span><?php endif; ?>
        <?php endif; ?>
      </span>
      <span class="signin-brand-name"><?= $hintName !== '' ? esc($hintName) : 'Joust Media' ?></span>
    </div>

    <?php if ($state === 'sent'): ?>
      <div class="signin-sent" data-signin-sent>
        <div class="signin-sent-icon"><?= icon('mail') ?></div>
        <h1>Check your email</h1>
        <p class="signin-sub">If <strong><?= esc($emailIn) ?></strong> is on Joust’s list<?= $hintName !== '' ? ' for ' . esc($hintName) : '' ?>, a sign-in link is on its way. It works once and expires in 15 minutes.</p>
        <a class="signin-link-btn" href="<?= esc($self) ?>">Use a different email</a>
      </div>
    <?php elseif ($state === 'limited'): ?>
      <div class="signin-notice signin-notice--error" role="alert" data-signin-limited>Too many sign-in requests. Please wait a few minutes and try again.</div>
      <h1>Sign in</h1>
      <a class="signin-link-btn" href="<?= esc($self) ?>">Back</a>
    <?php elseif ($state === 'confirm'): ?>
      <h1>Welcome back</h1>
      <p class="signin-sub">Continue to the <?= esc($peek['company_name']) ?> review portal.</p>
      <div class="signin-who">
        <?= clientAvatar(['name' => $peek['company_name'], 'slug' => $peek['slug'], 'logo_url' => $peek['logo_url']]) ?>
        <div class="signin-who-text">
          <div class="signin-who-name"><?= esc(trim((string)$peek['name']) !== '' ? $peek['name'] : $peek['email']) ?></div>
          <div class="signin-who-sub"><?= esc($peek['email']) ?></div>
        </div>
      </div>
      <form method="POST" action="<?= esc(portalUrl('sign-in')) ?>" data-signin-confirm>
        <input type="hidden" name="action" value="consume">
        <input type="hidden" name="t" value="<?= esc($token) ?>">
        <button type="submit" class="signin-submit" autofocus>Continue</button>
      </form>
      <p class="signin-footnote">You’ll stay signed in on this device for 30 days.</p>
    <?php else: ?>
      <?php if ($notice !== ''): ?>
        <div class="signin-notice signin-notice--<?= esc($noticeKind) ?>" role="<?= $noticeKind === 'error' ? 'alert' : 'status' ?>" data-signin-notice="<?= esc($error !== '' ? 'error' : $reason) ?>"><?= esc($notice) ?></div>
        <?php if ($reason === 'signed_out'): ?>
          <script>
            // Signed out on a shared device: drop App.video's cached poster frames / durations, as login.php does.
            (function () { try { var d = []; for (var i = 0; i < localStorage.length; i++) { var k = localStorage.key(i); if (k && /^(poster|nopos|duration):/.test(k)) d.push(k); } d.forEach(function (k) { localStorage.removeItem(k); }); } catch (e) {} })();
          </script>
        <?php endif; ?>
      <?php endif; ?>
      <h1>Sign in</h1>
      <p class="signin-sub">Enter the email Joust has on file for you and we’ll email you a link to sign in. No password needed.</p>
      <form method="POST" action="<?= esc($self) ?>" data-signin-form novalidate>
        <input type="hidden" name="action" value="request">
        <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= esc($return) ?>"><?php endif; ?>
        <div class="signin-field">
          <label for="signinEmail">Email</label>
          <input type="email" name="email" id="signinEmail" required autofocus autocomplete="email" inputmode="email"
                 value="<?= esc($emailIn) ?>" placeholder="you@company.com">
        </div>
        <button type="submit" class="signin-submit">Email me a sign-in link</button>
      </form>
      <p class="signin-footnote">Joust team? <a href="<?= esc(pagePath('login') . ($return !== '' ? '?return=' . rawurlencode($return) : '')) ?>">Sign in here</a></p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
<?php
// Send the link only now, after the page is out, so a known and an unknown address take the same time.
if ($deferred) {
    @ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
    else { @flush(); }
    try { $deferred(); } catch (Throwable $e) { error_log('sign-in email: ' . $e->getMessage()); }
}
