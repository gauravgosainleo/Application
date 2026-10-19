// Login / Register (email OTP) / Forgot password / 2-step login screen.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { esc, el, $, icon, otpInput, setBusy, toast, countdownParts, pad } from '../core/ui.js';

export function renderAuth(root, { message, onLoggedIn, onBanned }) {
  const cfg = state.config || {};
  root.innerHTML = '';
  const screen = el(`
    <div class="auth-screen">
      <section class="auth-hero">
        <div class="auth-hero-art" style="background-image:url('assets/img/hero.svg')"></div>
        <div class="auth-hero-content">
          <div class="brand big"><img src="assets/img/logo.svg" alt="" class="brand-logo"><span class="brand-text"><strong>G VI</strong><small>FAN HUB</small></span></div>
          <h1 class="hero-title">Your companion<br>for <span class="grad-text">Leonida</span></h1>
          <p class="hero-sub">Countdown, news, trailers, the interactive map, friends & chat, discussions, polls, events and rewards — all in one place.</p>
          <div class="mini-countdown" aria-label="Countdown to release"></div>
          <div class="hero-badges">
            <span>${icon('map')} Interactive map</span><span>${icon('chat')} Crew chat</span><span>${icon('trophy')} Rewards</span>
          </div>
          <a class="apk-link" href="downloads/gvi-fan-hub.apk" download>${icon('phone')} Download the Android app</a>
        </div>
      </section>
      <section class="auth-panel">
        <div class="auth-card">
          <div class="auth-tabs" role="tablist">
            <button class="auth-tab active" data-tab="login" role="tab">Log in</button>
            <button class="auth-tab" data-tab="register" role="tab">Register</button>
          </div>
          <div class="auth-alert" hidden></div>
          <div class="auth-body"></div>
          <div class="auth-foot">
            <a href="admin/" class="admin-link">${icon('shield')} Admin login</a>
            <span class="muted tiny">Unofficial fan app · not affiliated with Rockstar Games</span>
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
    screen.querySelectorAll('.auth-tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === tab));
    clearTimers();
    showAlert(null);
    if (tab === 'login') renderLogin();
    else if (tab === 'register') renderRegister();
  }

  screen.querySelectorAll('.auth-tab').forEach((t) => t.addEventListener('click', () => setTab(t.dataset.tab)));

  // ------------------------------------------------- mini countdown
  const cd = $('.mini-countdown', screen);
  const target = new Date(cfg.release_at || '2026-11-19T00:00:00');
  const tick = () => {
    const p = countdownParts(target);
    cd.innerHTML = p.done
      ? `<div class="cd-out">${esc(cfg.release_title || 'GTA VI')} is OUT NOW!</div>`
      : `<div class="cd-label">${esc(cfg.release_title || 'Grand Theft Auto VI')} launches in</div>
         <div class="cd-row">${[['days', p.days], ['hrs', p.hours], ['min', p.minutes], ['sec', p.seconds]].map(([l, v]) => `<div class="cd-cell"><b>${l === 'days' ? v : pad(v)}</b><span>${l}</span></div>`).join('')}</div>`;
  };
  tick();
  const cdTimer = setInterval(tick, 1000);

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
    return `<label class="field"><span>${label}</span><div class="input-wrap"><input class="input" type="password" name="${name}" autocomplete="${autocomplete}" required minlength="8" maxlength="72"><button type="button" class="pw-toggle" tabindex="-1" aria-label="Show password">${icon('eye')}</button></div></label>`;
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
        <h2>Welcome back</h2>
        <p class="muted">Log in to continue to G VI Fan Hub.</p>
        <label class="field"><span>Email or username</span><input class="input" name="email" type="text" autocomplete="username" required value="${esc(prefillEmail)}"></label>
        ${pwField('password', 'Password', 'current-password')}
        <div class="row-between">
          <label class="check"><input type="checkbox" name="remember" checked><span>Keep me signed in</span></label>
          <button type="button" class="link-btn" data-act="forgot">Forgot password?</button>
        </div>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Log in</button>
        <p class="switch-text">New here? <button type="button" class="link-btn" data-act="to-register">Create an account</button></p>
      </form>`;
    const form = $('form', body);
    passwordToggle(form);
    if (!prefillEmail) form.email.focus();
    else form.password.focus();
    $('[data-act="forgot"]', form).addEventListener('click', () => renderForgot(form.email.value.includes('@') ? form.email.value : ''));
    $('[data-act="to-register"]', form).addEventListener('click', () => setTab('register'));
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
        <h2>2-step verification</h2>
        <p class="muted">We emailed a 6-digit code to <strong>${esc(data.email_hint)}</strong>. Enter it below to finish logging in.</p>
        <div class="otp-slot"></div>
        <button class="btn btn-primary btn-block btn-lg" data-act="verify">Verify & log in</button>
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
        <h2>Create your account</h2>
        <p class="muted">Join the crew — it takes less than a minute.</p>
        <label class="field"><span>Name</span><input class="input" name="name" autocomplete="name" maxlength="80" required></label>
        <label class="field"><span>Email ID</span>
          <div class="input-with-btn">
            <input class="input" name="email" type="email" autocomplete="email" maxlength="190" required>
            <button type="button" class="btn btn-ghost btn-sm" data-act="send-otp">Send OTP</button>
          </div>
        </label>
        <div class="field otp-field" data-disabled="1">
          <span>Email OTP <small class="muted otp-hint">— press “Send OTP” to get a code</small></span>
          <div class="otp-slot"></div>
        </div>
        ${pwField('password', 'Password', 'new-password')}
        <div class="strength" data-score="0"><i></i><i></i><i></i><i></i><i></i><span></span></div>
        ${pwField('confirm_password', 'Confirm password', 'new-password')}
        <p class="muted tiny">At least 8 characters with a letter and a number.</p>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Verify & create account</button>
        <p class="switch-text">Already registered? <button type="button" class="link-btn" data-act="to-login">Log in</button></p>
      </form>`;
    const form = $('form', body);
    passwordToggle(form);
    strengthMeter(form.password, $('.strength', form));
    form.name.focus();
    const otp = otpInput();
    $('.otp-slot', form).appendChild(otp.el);
    otp.setDisabled(true);
    const sendBtn = $('[data-act="send-otp"]', form);
    $('[data-act="to-login"]', form).addEventListener('click', () => setTab('login'));

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
    screen.querySelectorAll('.auth-tab').forEach((t) => t.classList.remove('active'));
    showAlert(null);
    body.innerHTML = `
      <form class="auth-form" novalidate>
        <button type="button" class="link-btn back-link" data-act="back">${icon('chevronLeft')} Back to login</button>
        <h2>Reset your password</h2>
        <p class="muted">Enter your account email. We'll send you a one-time code to set a new password.</p>
        <label class="field"><span>Email ID</span>
          <div class="input-with-btn">
            <input class="input" name="email" type="email" autocomplete="email" required value="${esc(prefill)}">
            <button type="button" class="btn btn-ghost btn-sm" data-act="send">Send code</button>
          </div>
        </label>
        <div class="reset-step" hidden>
          <div class="field"><span>Code from email</span><div class="otp-slot"></div></div>
          ${pwField('password', 'New password', 'new-password')}
          ${pwField('confirm_password', 'Confirm new password', 'new-password')}
          <button class="btn btn-primary btn-block btn-lg" type="submit">Set new password</button>
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
    clearInterval(cdTimer);
    toast(`Welcome, ${data.user.name}!`, 'success');
    onLoggedIn(data);
  }

  renderLogin();
  if (message) showAlert(message, 'info');
}
