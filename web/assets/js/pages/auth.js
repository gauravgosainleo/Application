// Login / Sign up (email OTP) / Forgot password / 2-step login screen.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { esc, el, $, icon, otpInput, setBusy, toast, countdownParts } from '../core/ui.js';
import { heroFx } from '../core/gvi-background.js';
import { mountCountdown } from '../core/gvi-countdown.js';
import { logoSvg } from '../core/gvi-nav.js';
import { releaseDate } from './countdown.js';

function logoBig() {
  return `<div class="gvi-auth-logo"><span class="gvi-logo3d lg"><span data-tilt="global"><span class="gvi-logo-halo"></span><span class="gvi-logo-base"></span><span class="gvi-logo-rim"></span>${logoSvg(44)}<span class="gvi-logo-gloss"></span></span></span></div>`;
}

function logoSmall() {
  return `<span class="gvi-logo3d sm"><span data-tilt="global"><span class="gvi-logo-base"></span>${logoSvg(44)}</span></span>`;
}

export function renderAuth(root, { message, onLoggedIn, onBanned }) {
  const cfg = state.config || {};
  const target = releaseDate(cfg.release_at);
  const daysLeft = countdownParts(target).days;
  root.innerHTML = '';
  const screen = el(`
    <div class="gvi-auth">
      <section class="gvi-auth-hero">
        <div class="gvi-auth-bg"></div>
        ${heroFx()}
        <div class="gvi-auth-shade"></div>
        ${logoBig()}
        <div class="gvi-auth-copy">
          <span class="gvi-eyebrow">${icon('sparkles')}Unofficial fan companion</span>
          <h1 class="gvi-auth-title">Welcome to Leonida</h1>
          <p class="gvi-auth-pitch">Countdown, news, trailers, the fan map, friends and chat — everything for the wait, in one place.</p>
          <div class="gvi-auth-cd"><div class="gvi-micro">Launch in</div><div class="mini-countdown" aria-label="Countdown to release"></div></div>
          <div class="gvi-auth-chips">
            <span class="gvi-chip static">${icon('map')}Interactive Leonida map</span>
            <span class="gvi-chip static">${icon('chat')}Friends &amp; chat</span>
            <span class="gvi-chip static">${icon('zap')}XP, levels &amp; rewards</span>
          </div>
        </div>
      </section>
      <section class="gvi-auth-panel">
        <div class="gvi-auth-card">
          <div class="gvi-auth-brand">${logoSmall()}<span class="gvi-brand-text"><strong class="gvi-wordmark big">G VI</strong><small class="gvi-wordmark-sub">FAN HUB</small></span></div>
          <div class="gvi-auth-tabs" role="tablist">
            <button class="auth-tab is-active" data-tab="login" role="tab" aria-selected="true">Log in</button>
            <button class="auth-tab" data-tab="register" role="tab" aria-selected="false">Sign up</button>
          </div>
          <div class="auth-alert" hidden></div>
          <div class="auth-body"></div>
          <div class="gvi-auth-foot">
            <span>Unofficial fan app. Not affiliated with Rockstar Games or Take-Two Interactive.</span>
            <span class="gvi-auth-links"><a href="admin/" class="admin-link">${icon('shield')}Admin login</a><a class="apk-link" href="downloads/gvi-fan-hub.apk" download>${icon('phone')}Android app</a></span>
          </div>
        </div>
      </section>
    </div>`);
  root.appendChild(screen);

  const body = $('.auth-body', screen);
  const alertBox = $('.auth-alert', screen);
  let timers = [];

  function clearTimers() {
    timers.forEach((t) => clearInterval(t));
    timers = [];
  }

  function showAlert(text, type = 'error') {
    if (!text) {
      alertBox.hidden = true;
      return;
    }
    alertBox.className = `auth-alert ${type}`;
    alertBox.innerHTML = `${icon(type === 'success' ? 'check' : type === 'info' ? 'info' : 'alert')}<span>${esc(text)}</span>`;
    alertBox.hidden = false;
  }

  function setTab(tab) {
    screen.querySelectorAll('.auth-tab').forEach((t) => {
      const on = t.dataset.tab === tab;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', String(on));
    });
    clearTimers();
    showAlert(null);
    if (tab === 'login') renderLogin();
    else if (tab === 'register') renderRegister();
  }

  screen.querySelectorAll('.auth-tab').forEach((t) => t.addEventListener('click', () => setTab(t.dataset.tab)));

  // ------------------------------------------------- mini countdown
  const cdEl = $('.mini-countdown', screen);
  let stopCd = () => {};
  if (countdownParts(target).done) {
    cdEl.innerHTML = `<div class="gvi-out-now">${esc(cfg.release_title || 'GTA VI')} is OUT NOW!</div>`;
  } else {
    stopCd = mountCountdown(cdEl, target, { size: 'mini', labels: ['Days', 'Hrs', 'Min', 'Sec'] });
  }

  const intro = (title) => `<h2 class="gvi-auth-h">${title}</h2><p class="gvi-auth-sub">${daysLeft > 0 ? `${daysLeft} day${daysLeft === 1 ? '' : 's'} to go. Your streak is waiting.` : 'Leonida is open. Your crew is waiting.'}</p>`;

  function passwordToggle(container) {
    container.querySelectorAll('.pw-toggle').forEach((btn) => {
      btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('input');
        input.type = input.type === 'password' ? 'text' : 'password';
        btn.innerHTML = icon(input.type === 'password' ? 'eye' : 'eyeOff');
      });
    });
  }

  function pwField(name, label, autocomplete) {
    return `<label class="gvi-field"><span>${label}</span><div class="input-wrap"><input class="gvi-input" type="password" name="${name}" placeholder="••••••••" autocomplete="${autocomplete}" required minlength="8" maxlength="72"><button type="button" class="pw-toggle" tabindex="-1" aria-label="Show password">${icon('eye')}</button></div></label>`;
  }

  function strengthMeter(input, meter) {
    input.addEventListener('input', () => {
      const v = input.value;
      let score = 0;
      if (v.length >= 8) score++;
      if (v.length >= 12) score++;
      if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      const labels = ['Too short', 'Weak', 'Okay', 'Good', 'Strong', 'Excellent'];
      meter.dataset.score = String(score);
      meter.querySelector('span').textContent = v ? labels[score] : '';
    });
  }

  function cooldownButton(btn, seconds, label) {
    let left = seconds;
    btn.disabled = true;
    btn.textContent = `${label} (${left}s)`;
    const t = setInterval(() => {
      left--;
      if (left <= 0) {
        clearInterval(t);
        btn.disabled = false;
        btn.textContent = label;
      } else {
        btn.textContent = `${label} (${left}s)`;
      }
    }, 1000);
    timers.push(t);
  }

  function handleError(err) {
    if (err.code === 'banned') {
      onBanned(err.payload.ban, err.message);
      return;
    }
    showAlert(err.message);
  }

  // ------------------------------------------------------------ login
  function renderLogin(prefillEmail = '') {
    body.innerHTML = `
      <form class="auth-form" novalidate>
        ${intro('Welcome back')}
        <label class="gvi-field"><span>Email or username</span><input class="gvi-input" name="email" type="text" placeholder="you@example.com" autocomplete="username" required value="${esc(prefillEmail)}"></label>
        ${pwField('password', 'Password', 'current-password')}
        <div class="gvi-auth-row">
          <label class="check"><input type="checkbox" name="remember" checked><span>Keep me signed in</span></label>
          <button type="button" class="link-btn" data-act="forgot">Forgot password?</button>
        </div>
        <button class="gvi-cta" type="submit">Enter the hub${icon('chevronRight')}</button>
      </form>`;
    const form = $('form', body);
    passwordToggle(form);
    if (!prefillEmail) form.email.focus();
    else form.password.focus();
    $('[data-act="forgot"]', form).addEventListener('click', () => renderForgot(form.email.value.includes('@') ? form.email.value : ''));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      showAlert(null);
      if (!form.email.value.trim() || !form.password.value) {
        showAlert('Enter your email (or username) and password.');
        return;
      }
      const btn = $('button[type=submit]', form);
      setBusy(btn, true, 'Logging in…');
      try {
        const data = await api.post('auth/login', { email: form.email.value.trim(), password: form.password.value, remember: form.remember.checked });
        if (data.otp_required) {
          renderLoginOtp(data, form.remember.checked);
        } else {
          finish(data);
        }
      } catch (err) {
        handleError(err);
      } finally {
        setBusy(btn, false);
      }
    });
  }

  function renderLoginOtp(data, remember) {
    body.innerHTML = `
      <div class="auth-form">
        <button class="link-btn back-link" data-act="back">${icon('chevronLeft')} Back</button>
        <h2 class="gvi-auth-h">2-step verification</h2>
        <p class="gvi-auth-sub">We emailed a 6-digit code to <strong>${esc(data.email_hint)}</strong>. Enter it below to finish logging in.</p>
        <div class="otp-slot"></div>
        <button class="gvi-cta" data-act="verify">Verify &amp; enter${icon('chevronRight')}</button>
        <p class="switch-text">Didn't get it? <button class="link-btn" data-act="resend">Resend code</button></p>
      </div>`;
    const otp = otpInput((code) => verify(code));
    $('.otp-slot', body).appendChild(otp.el);
    otp.focus();
    const resendBtn = $('[data-act="resend"]', body);
    cooldownButton(resendBtn, data.resend_in || 60, 'Resend code');
    $('[data-act="back"]', body).addEventListener('click', () => renderLogin());
    const verifyBtn = $('[data-act="verify"]', body);
    async function verify(code) {
      code = code || otp.value();
      if (code.length !== 6) {
        showAlert('Enter the 6-digit code from your email.');
        return;
      }
      setBusy(verifyBtn, true, 'Verifying…');
      try {
        finish(await api.post('auth/login-otp', { otp_token: data.otp_token, otp: code, remember }));
      } catch (err) {
        otp.clear();
        otp.focus();
        handleError(err);
      } finally {
        setBusy(verifyBtn, false);
      }
    }
    verifyBtn.addEventListener('click', () => verify());
    resendBtn.addEventListener('click', async () => {
      try {
        const r = await api.post('auth/resend-login-otp', { otp_token: data.otp_token });
        showAlert('A new code is on its way.', 'success');
        cooldownButton(resendBtn, r.resend_in || 60, 'Resend code');
      } catch (err) {
        handleError(err);
      }
    });
  }

  // --------------------------------------------------------- register
  function renderRegister() {
    body.innerHTML = `
      <form class="auth-form" novalidate>
        ${intro('Create your account')}
        <label class="gvi-field"><span>Display name</span><input class="gvi-input" name="name" placeholder="How should we call you?" autocomplete="name" maxlength="80" required></label>
        <label class="gvi-field"><span>Email</span>
          <div class="input-with-btn">
            <input class="gvi-input" name="email" type="email" placeholder="you@example.com" autocomplete="email" maxlength="190" required>
            <button type="button" class="gvi-btn ghost sm" data-act="send-otp">Send OTP</button>
          </div>
        </label>
        <div class="gvi-field otp-field" data-disabled="1">
          <span>Email OTP <small class="muted otp-hint">— press “Send OTP” to get a code</small></span>
          <div class="otp-slot"></div>
        </div>
        ${pwField('password', 'Password', 'new-password')}
        <div class="strength" data-score="0"><i></i><i></i><i></i><i></i><i></i><span></span></div>
        ${pwField('confirm_password', 'Confirm password', 'new-password')}
        <p class="muted tiny">At least 8 characters with a letter and a number.</p>
        <button class="gvi-cta" type="submit">Create account${icon('chevronRight')}</button>
      </form>`;
    const form = $('form', body);
    passwordToggle(form);
    strengthMeter(form.password, $('.strength', form));
    form.name.focus();
    const otp = otpInput();
    $('.otp-slot', form).appendChild(otp.el);
    otp.setDisabled(true);
    const sendBtn = $('[data-act="send-otp"]', form);

    sendBtn.addEventListener('click', async () => {
      showAlert(null);
      const name = form.name.value.trim();
      const email = form.email.value.trim();
      if (name.length < 2) {
        showAlert('Enter your name first.');
        form.name.focus();
        return;
      }
      if (!/^\S+@\S+\.\S+$/.test(email)) {
        showAlert('Enter a valid email address.');
        form.email.focus();
        return;
      }
      setBusy(sendBtn, true);
      try {
        const r = await api.post('auth/register-otp', { name, email });
        otp.setDisabled(false);
        otp.focus();
        $('.otp-field', form).dataset.disabled = '0';
        $('.otp-hint', form).textContent = `— sent to ${r.email}, valid for 10 minutes`;
        showAlert(`We sent a 6-digit code to ${r.email}. Check your inbox (and spam folder).`, 'success');
        setBusy(sendBtn, false);
        cooldownButton(sendBtn, r.resend_in || 60, 'Resend');
      } catch (err) {
        setBusy(sendBtn, false);
        if (err.payload && err.payload.retry_after) cooldownButton(sendBtn, err.payload.retry_after, 'Resend');
        handleError(err);
      }
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      showAlert(null);
      const payload = {
        name: form.name.value.trim(),
        email: form.email.value.trim(),
        password: form.password.value,
        confirm_password: form.confirm_password.value,
        otp: otp.value(),
      };
      if (payload.name.length < 2) return showAlert('Enter your name.');
      if (!/^\S+@\S+\.\S+$/.test(payload.email)) return showAlert('Enter a valid email address.');
      if (payload.otp.length !== 6) return showAlert('Press “Send OTP” and enter the 6-digit code from your email.');
      if (payload.password.length < 8) return showAlert('Password must be at least 8 characters.');
      if (payload.password !== payload.confirm_password) return showAlert('Password and confirm password do not match.');
      const btn = $('button[type=submit]', form);
      setBusy(btn, true, 'Creating account…');
      try {
        await api.post('auth/register', payload);
        setTab('login');
        renderLogin(payload.email);
        showAlert('Your email is verified and your account is ready. Log in to start!', 'success');
      } catch (err) {
        handleError(err);
      } finally {
        setBusy(btn, false);
      }
    });
  }

  // ---------------------------------------------------------- forgot
  function renderForgot(prefill = '') {
    screen.querySelectorAll('.auth-tab').forEach((t) => t.classList.remove('is-active'));
    showAlert(null);
    body.innerHTML = `
      <form class="auth-form" novalidate>
        <button type="button" class="link-btn back-link" data-act="back">${icon('chevronLeft')} Back to login</button>
        <h2 class="gvi-auth-h">Reset your password</h2>
        <p class="gvi-auth-sub">Enter your account email. We'll send you a one-time code to set a new password.</p>
        <label class="gvi-field"><span>Email</span>
          <div class="input-with-btn">
            <input class="gvi-input" name="email" type="email" placeholder="you@example.com" autocomplete="email" required value="${esc(prefill)}">
            <button type="button" class="gvi-btn ghost sm" data-act="send">Send code</button>
          </div>
        </label>
        <div class="reset-step" hidden>
          <div class="gvi-field"><span>Code from email</span><div class="otp-slot"></div></div>
          ${pwField('password', 'New password', 'new-password')}
          ${pwField('confirm_password', 'Confirm new password', 'new-password')}
          <button class="gvi-cta" type="submit">Set new password${icon('chevronRight')}</button>
        </div>
      </form>`;
    const form = $('form', body);
    passwordToggle(form);
    const otp = otpInput();
    $('.otp-slot', form).appendChild(otp.el);
    const sendBtn = $('[data-act="send"]', form);
    $('[data-act="back"]', form).addEventListener('click', () => setTab('login'));
    form.email.focus();
    sendBtn.addEventListener('click', async () => {
      const email = form.email.value.trim();
      if (!/^\S+@\S+\.\S+$/.test(email)) return showAlert('Enter a valid email address.');
      setBusy(sendBtn, true);
      try {
        const r = await api.post('auth/forgot', { email });
        $('.reset-step', form).hidden = false;
        otp.focus();
        showAlert(`If an account exists for ${r.email}, a 6-digit code is on its way.`, 'success');
        setBusy(sendBtn, false);
        cooldownButton(sendBtn, r.resend_in || 60, 'Resend');
      } catch (err) {
        setBusy(sendBtn, false);
        if (err.payload && err.payload.retry_after) cooldownButton(sendBtn, err.payload.retry_after, 'Resend');
        handleError(err);
      }
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const payload = { email: form.email.value.trim(), otp: otp.value(), password: form.password.value, confirm_password: form.confirm_password.value };
      if (payload.otp.length !== 6) return showAlert('Enter the 6-digit code from your email.');
      if (payload.password !== payload.confirm_password) return showAlert('Passwords do not match.');
      const btn = $('button[type=submit]', form);
      setBusy(btn, true, 'Saving…');
      try {
        await api.post('auth/reset', payload);
        setTab('login');
        renderLogin(payload.email);
        showAlert('Password changed! Log in with your new password.', 'success');
      } catch (err) {
        handleError(err);
      } finally {
        setBusy(btn, false);
      }
    });
  }

  function finish(data) {
    clearTimers();
    stopCd();
    toast(`Welcome back, ${data.user.name}${data.user.login_streak > 1 ? ` · ${data.user.login_streak}-day streak` : ''}`, 'success');
    onLoggedIn(data);
  }

  renderLogin();
  if (message) showAlert(message, 'info');
}
