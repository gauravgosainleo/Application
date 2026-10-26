<?php
/*
 * G VI Fan Hub - account deletion request page (public, no app or password needed).
 * Players prove the account is theirs with a one-time code sent to its email, then the
 * account and its data are deleted at once (api: auth/delete-request, auth/delete-confirm).
 * This is the "delete account" link for the Google Play listing.
 */

require __DIR__ . '/app/bootstrap.php';

function pp_e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$appName = cfg('app.name', 'G VI Fan Hub');
$contact = privacy_contact_email();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Delete your account · <?= pp_e($appName) ?></title>
<meta name="description" content="Request deletion of your <?= pp_e($appName) ?> account and all associated data.">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#07030f">
<meta name="color-scheme" content="dark">
<link rel="icon" href="assets/img/icons/favicon-32.png" sizes="32x32" type="image/png">
<link rel="stylesheet" href="assets/css/legal.css">
</head>
<body>
<header class="top">
  <a class="brand" href="./"><img src="assets/img/logo.svg" alt=""><span><?= pp_e($appName) ?></span></a>
  <a class="back" href="./">Back to the app</a>
</header>
<main>
  <h1>Delete your account</h1>
  <p class="meta">Request deletion of your <?= pp_e($appName) ?> account and all associated data. You don't need the app or your password.</p>

  <section class="card">
    <h2 class="first">What is deleted</h2>
    <p>Deletion is permanent and happens immediately. It removes:</p>
    <ul>
      <li>your account, email address, password and profile (photo, cover, bio, gamer IDs and settings);</li>
      <li>your friends, friend requests and blocked list;</li>
      <li>your statuses, discussions, comments, likes, gallery uploads and map markers;</li>
      <li>your poll votes, event sign-ups, rewards, XP, saved catalogue items and notifications;</li>
      <li>your signed-in devices (with their IP addresses) and pending email codes;</li>
      <li>the text and photos of chat messages you sent. Chats show "Message deleted" in their place.</li>
    </ul>
    <p>What remains: reports you sent about other players stay for moderation without your name, and copies in our hosting provider's backups are overwritten after a limited period. See the <a href="privacy.php#retention">Privacy Policy</a> for details.</p>
  </section>

  <h2>Can you sign in?</h2>
  <p>Open the app and go to <strong>Profile → Security → Delete account</strong>, then confirm with your password.</p>
  <p><a class="back inline" href="./#/profile?tab=security">Open Profile → Security</a></p>

  <h2>Can't sign in, or don't have the app?</h2>
  <p>Request deletion here. We email a one-time code to your account's address to make sure the request comes from you.</p>

  <form class="card form" id="del-request" novalidate>
    <label class="field"><span>Email address of your account</span>
      <input type="email" name="email" autocomplete="email" required maxlength="190" placeholder="you@example.com"></label>
    <button class="btn" type="submit">Email me a deletion code</button>
  </form>

  <form class="card form" id="del-confirm" hidden novalidate>
    <p class="sent">If an account uses <strong data-email></strong>, we've sent it a 6-digit code. It is valid for 10 minutes; check your spam folder too.</p>
    <label class="field"><span>Deletion code</span>
      <input name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required placeholder="6-digit code"></label>
    <label class="check"><input type="checkbox" name="confirm"><span>I understand that my account and its data will be permanently deleted. This can't be undone.</span></label>
    <div class="actions">
      <button class="btn danger" type="submit">Delete my account permanently</button>
      <button class="link" type="button" data-act="restart">Use a different email</button>
      <button class="link" type="button" data-act="resend">Send a new code</button>
    </div>
  </form>

  <section class="card done" id="del-done" hidden>
    <h2 class="first">Your account has been deleted</h2>
    <p>Your account and its data have been permanently removed, and we've emailed a confirmation. You can create a new account at any time.</p>
  </section>

  <p class="alert" id="del-alert" role="alert" hidden></p>

  <h2>Need help?</h2>
  <p>If you no longer have access to your email address, contact us<?php if ($contact !== ''): ?> at <a href="mailto:<?= pp_e($contact) ?>?subject=<?= rawurlencode($appName . ' account deletion request') ?>"><strong><?= pp_e($contact) ?></strong></a><?php endif; ?> with your username and any details that show the account is yours. We reply within 30 days.</p>

  <footer>
    <p>© <?= gmdate('Y') ?> <?= pp_e($appName) ?> · Unofficial fan app · <a href="privacy.php">Privacy Policy</a> · <a href="./">Open the app</a></p>
  </footer>
</main>
<script>
(function () {
  var request = document.getElementById('del-request');
  var confirmForm = document.getElementById('del-confirm');
  var done = document.getElementById('del-done');
  var alertBox = document.getElementById('del-alert');
  var email = '';

  function say(msg, ok) {
    alertBox.hidden = !msg;
    alertBox.textContent = msg || '';
    alertBox.className = 'alert' + (ok ? ' ok' : '');
    if (msg) alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function post(route, body) {
    return fetch('api/index.php?r=' + route, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'GVI' },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error('Server error (' + res.status + '). Please try again.');
      });
    }).then(function (json) {
      if (!json.ok) throw new Error(json.error || 'Something went wrong. Please try again.');
      return json.data;
    }, function (err) {
      throw err instanceof Error && err.message ? err : new Error('Network error. Check your connection and try again.');
    });
  }

  function busy(btn, on) {
    btn.disabled = on;
    if (on) { btn.dataset.label = btn.textContent; btn.textContent = 'Please wait…'; }
    else if (btn.dataset.label) { btn.textContent = btn.dataset.label; }
  }

  function sendCode(btn) {
    say('');
    busy(btn, true);
    return post('auth/delete-request', { email: email }).then(function () {
      confirmForm.querySelector('[data-email]').textContent = email;
      request.hidden = true;
      confirmForm.hidden = false;
      confirmForm.otp.focus();
    }).catch(function (err) { say(err.message); }).then(function () { busy(btn, false); });
  }

  request.addEventListener('submit', function (e) {
    e.preventDefault();
    email = request.email.value.trim().toLowerCase();
    if (!/^\S+@\S+\.\S+$/.test(email)) return say('Enter the email address of your account.');
    sendCode(request.querySelector('button'));
  });

  confirmForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var otp = confirmForm.otp.value.replace(/\D/g, '');
    if (otp.length !== 6) return say('Enter the 6-digit code from the email.');
    if (!confirmForm.confirm.checked) return say('Tick the box to confirm the permanent deletion.');
    var btn = confirmForm.querySelector('button[type=submit]');
    say('');
    busy(btn, true);
    post('auth/delete-confirm', { email: email, otp: otp, confirm: true }).then(function () {
      confirmForm.hidden = true;
      done.hidden = false;
      done.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }).catch(function (err) { say(err.message); busy(btn, false); });
  });

  confirmForm.addEventListener('click', function (e) {
    var act = e.target.getAttribute('data-act');
    if (act === 'restart') {
      say('');
      confirmForm.hidden = true;
      request.hidden = false;
      request.email.focus();
    } else if (act === 'resend') {
      sendCode(e.target).then(function () { if (alertBox.hidden) say('A new code is on its way.', true); });
    }
  });
})();
</script>
</body>
</html>
