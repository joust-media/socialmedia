<?php
/**
 * Email preferences + unsubscribe for a client contact (client-notify-lib.php) — a session-free machine endpoint
 * (https://joustmedia.com/portal/email-prefs; never routed, never gated) reached two ways:
 *
 *   ?t=<signed token>          from any client email ("Email preferences" / "Unsubscribe" / List-Unsubscribe). The
 *                              token names one contact (HMAC, a year, dead once the contact is removed).
 *   (signed-in client)         from the client portal (tab bar → Email settings): the current contact.
 *
 *   GET                        the three switches (Ready for your review · Joust replied · Live & scheduled) and
 *                              "Unsubscribe from all"; &u=1 leads with the unsubscribe confirmation.
 *   POST List-Unsubscribe=One-Click   RFC 8058 one-click (mail providers POST the List-Unsubscribe URL): stop all,
 *                              answer 200 "Unsubscribed" — no page, no cookie needed.
 *   POST action=save|unsubscribe|resubscribe (+ review / reply / live checkboxes) → 303 back with a note.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token  = is_string($_REQUEST['t'] ?? null) ? (string)$_REQUEST['t'] : '';
$ready  = clientEmailReady($pdo);
$contact = null; $via = '';
if ($ready && $token !== '') {
    $contact = clientPrefsVerify($pdo, $token);
    $via = 'token';
} elseif ($ready && ($cc = currentClientContact()) && !(function_exists('currentAdmin') && currentAdmin())) {
    $s = $pdo->prepare("SELECT c.*, co.name AS company_name, co.slug, co.logo_url FROM client_contacts c INNER JOIN companies co ON co.id = c.company_id WHERE c.id = ?");
    $s->execute([(int)$cc['id']]);
    $contact = $s->fetch() ?: null;
    $via = 'session';
}

// RFC 8058 one-click: the body is exactly List-Unsubscribe=One-Click; the token in the URL is the credential.
if ($method === 'POST' && (string)($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!$contact || $via !== 'token') { http_response_code(404); echo 'Unknown link'; exit; }
    clientContactPrefsSave($pdo, (int)$contact['id'], clientContactPrefs($contact), true);
    echo 'Unsubscribed';
    exit;
}

if (!$contact) {
    if ($token === '' && !(function_exists('currentAdmin') && currentAdmin()) && $ready) {
        header('Location: ' . portalSignInUrl((string)($_SERVER['REQUEST_URI'] ?? ''), 'signin'), true, 302);
        exit;
    }
    http_response_code($ready ? 404 : 503);
}

$notice = ''; $noticeKind = 'ok';
if ($contact && $method === 'POST') {
    requireSameSiteFetch();
    $act = (string)($_POST['action'] ?? 'save');
    $prefs = [];
    foreach (array_keys(clientEmailKinds()) as $k) $prefs[$k] = !empty($_POST[$k]);
    if ($act === 'unsubscribe') clientContactPrefsSave($pdo, (int)$contact['id'], clientContactPrefs($contact), true);
    elseif ($act === 'resubscribe') clientContactPrefsSave($pdo, (int)$contact['id'], array_fill_keys(array_keys(clientEmailKinds()), true), false);
    else clientContactPrefsSave($pdo, (int)$contact['id'], $prefs, array_filter($prefs) ? false : null);
    $done = ['save' => 'saved', 'unsubscribe' => 'unsubscribed', 'resubscribe' => 'resubscribed'][$act] ?? 'saved';
    header('Location: ' . notifyMachineUrl('email-prefs', array_filter(['t' => $via === 'token' ? $token : null, 'done' => $done])), true, 303);
    exit;
}
if ($contact) {
    $s = $pdo->prepare("SELECT notify_prefs, unsubscribed_at FROM client_contacts WHERE id = ?");
    $s->execute([(int)$contact['id']]);
    $contact = array_merge($contact, $s->fetch() ?: []);
    $done = (string)($_GET['done'] ?? '');
    if ($done === 'saved') $notice = 'Saved. You’ll get the emails you left on.';
    if ($done === 'unsubscribed') $notice = 'You’re unsubscribed — Joust won’t email you about ' . $contact['company_name'] . ' anymore.';
    if ($done === 'resubscribed') $notice = 'Welcome back — every email type is on again.';
}
$prefs = $contact ? clientContactPrefs($contact) : [];
$askUnsub = $contact && !empty($_GET['u']) && empty($prefs['unsubscribed']) && $notice === '';
$self = notifyMachineUrl('email-prefs', $via === 'token' ? ['t' => $token] : []);
$joust = joustLogoUrl();
$clientLogo = $contact ? brandLogoUrl((string)($contact['logo_url'] ?? ''), (string)$contact['slug']) : '';
$back = $contact && $via === 'session' ? portalUrl('index', ['client' => (string)$contact['slug']]) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex">
<?= themeBootScript() ?>
<title>Email preferences — Joust Media</title>
<?= appIconTags() ?>
<?= appStylesheets() ?>
<link rel="stylesheet" href="<?= esc(staticUrl('css/sign-in.css')) ?>">
<link rel="stylesheet" href="<?= esc(staticUrl('css/notify.css')) ?>">
</head>
<body class="ui-body signin-body" data-email-prefs="<?= $contact ? (int)$contact['id'] : 0 ?>">
<div class="signin-theme"><?= themeToggleButton() ?></div>
<main class="signin-wrap">
  <div class="signin-card ep-card">
    <div class="signin-brand">
      <span class="signin-pair">
        <?php if ($joust !== ''): ?><img class="signin-mark signin-mark--joust" src="<?= esc($joust) ?>" alt="" width="40" height="40"><?php else: ?><span class="signin-mark signin-mark--initial">J</span><?php endif; ?>
        <?php if ($contact): ?>
          <span class="signin-x" aria-hidden="true">×</span>
          <?php if ($clientLogo !== ''): ?><img class="signin-mark signin-mark--client" src="<?= esc($clientLogo) ?>" alt="" width="40" height="40"><?php else: ?><span class="signin-mark signin-mark--initial"><?= esc(mb_strtoupper(mb_substr((string)$contact['company_name'], 0, 1))) ?></span><?php endif; ?>
        <?php endif; ?>
      </span>
      <span class="signin-brand-name"><?= $contact ? esc($contact['company_name']) : 'Joust Media' ?></span>
    </div>
    <?php if (!$contact): ?>
      <h1>Email preferences</h1>
      <p class="signin-sub" data-ep-invalid>This link is not valid anymore. Open the latest email from Joust and use its “Email preferences” link, or sign in to the portal.</p>
    <?php else: ?>
      <h1>Email preferences</h1>
      <p class="signin-sub">For <strong><?= esc($contact['email']) ?></strong> · <?= esc($contact['company_name']) ?></p>
      <?php if ($notice !== ''): ?><div class="signin-notice signin-notice--<?= esc($noticeKind) ?>" role="status" data-ep-notice><?= esc($notice) ?></div><?php endif; ?>
      <?php if ($askUnsub): ?>
        <form method="POST" action="<?= esc($self) ?>" class="ep-unsub" data-ep-unsub-confirm>
          <input type="hidden" name="action" value="unsubscribe">
          <?php if ($via === 'token'): ?><input type="hidden" name="t" value="<?= esc($token) ?>"><?php endif; ?>
          <p class="ep-unsub-text">Stop all emails from Joust about <?= esc($contact['company_name']) ?>? You can still sign in to the portal any time.</p>
          <button type="submit" class="signin-submit ep-danger">Unsubscribe from all</button>
          <p class="ep-or">Or pick which emails you still want:</p>
        </form>
      <?php endif; ?>
      <?php if (!empty($prefs['unsubscribed'])): ?>
        <div class="signin-notice signin-notice--info" data-ep-state="unsubscribed">You’re unsubscribed from every Joust email.</div>
        <form method="POST" action="<?= esc($self) ?>">
          <input type="hidden" name="action" value="resubscribe">
          <?php if ($via === 'token'): ?><input type="hidden" name="t" value="<?= esc($token) ?>"><?php endif; ?>
          <button type="submit" class="signin-submit" data-ep-resubscribe>Turn emails back on</button>
        </form>
      <?php else: ?>
        <form method="POST" action="<?= esc($self) ?>" class="ep-form" data-ep-form>
          <input type="hidden" name="action" value="save">
          <?php if ($via === 'token'): ?><input type="hidden" name="t" value="<?= esc($token) ?>"><?php endif; ?>
          <ul class="ep-list" role="list">
            <?php foreach (clientEmailKinds() as $k => [$label, , $help]): ?>
              <li class="ep-row">
                <label class="ep-label" for="ep-<?= esc($k) ?>">
                  <span class="ep-title"><?= esc($label) ?></span>
                  <span class="ep-help"><?= esc($help) ?></span>
                </label>
                <input class="ep-switch" type="checkbox" role="switch" id="ep-<?= esc($k) ?>" name="<?= esc($k) ?>" value="1"<?= !empty($prefs[$k]) ? ' checked' : '' ?> data-ep-pref="<?= esc($k) ?>">
              </li>
            <?php endforeach; ?>
          </ul>
          <button type="submit" class="signin-submit">Save</button>
        </form>
        <?php if (!$askUnsub): ?>
          <form method="POST" action="<?= esc($self) ?>" class="ep-unsub-all">
            <input type="hidden" name="action" value="unsubscribe">
            <?php if ($via === 'token'): ?><input type="hidden" name="t" value="<?= esc($token) ?>"><?php endif; ?>
            <button type="submit" class="signin-link-btn ep-danger-link" data-ep-unsubscribe>Unsubscribe from all</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
      <p class="signin-footnote">Sign-in links and anything you ask for still arrive.<?php if ($back !== ''): ?> · <a href="<?= esc($back) ?>">Back to the portal</a><?php endif; ?></p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
