// Admin: broadcast announcements, site settings (countdown, map, email, OTP) and admin password.
import { api } from '../core/api.js';
import { esc, $, icon, asset, loader, errorState, toast, toastError, setBusy, confirmBox } from '../core/ui.js';
import { pageHead, toLocalInput } from './admin.js';

export async function render(ctx) {
  if (ctx.path === '/broadcast') return broadcast(ctx);
  if (ctx.path === '/account') return account(ctx);
  return settings(ctx);
}

/* =============================================================== BROADCAST */
function broadcast({ el }) {
  el.innerHTML = pageHead('bell', 'Broadcast', 'Send an in-app notification to every player.') + `
    <form class="card form-card narrow">
      <label class="field"><span>Title</span><input class="input" name="title" maxlength="160" required placeholder="e.g. GTA VI pre-load is live!"></label>
      <label class="field"><span>Message</span><textarea class="input" name="body" rows="3" maxlength="300" placeholder="Optional details"></textarea></label>
      <label class="field"><span>Open when tapped</span>
        <select class="input select" name="link">
          <option value="">Nothing</option><option value="#/home">Home</option><option value="#/countdown">Countdown</option><option value="#/news">News</option>
          <option value="#/trailers">Trailers</option><option value="#/map">Map</option><option value="#/discussions">Discussions</option><option value="#/polls">Polls</option>
          <option value="#/events">Events</option><option value="#/events?tab=rewards">Rewards</option><option value="#/gallery">Gallery</option>
        </select></label>
      <label class="check"><input type="checkbox" name="banner"><span>Also show it as the banner on the home screen</span></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit">${icon('send')} Send to everyone</button></div>
    </form>`;
  const form = $('form', el);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!form.title.value.trim()) return toast('Add a title.', 'warn');
    if (!(await confirmBox('Send this notification to all players?', { confirmText: 'Send' }))) return;
    const btn = $('button[type=submit]', form);
    setBusy(btn, true);
    try {
      const r = await api.post('admin/broadcast', { title: form.title.value.trim(), body: form.body.value.trim(), link: form.link.value, banner: form.banner.checked });
      toast(`Sent to ${r.sent_to} players`, 'success');
      form.reset();
    } catch (err) {
      toastError(err);
    } finally {
      setBusy(btn, false);
    }
  });
}

/* ================================================================ SETTINGS */
async function settings({ el }) {
  el.innerHTML = pageHead('settings', 'Settings', 'Countdown, announcement, sign-in security, gallery moderation, map image and email.') + loader();
  let s;
  try {
    s = await api.get('admin/settings');
  } catch (err) {
    el.innerHTML = '';
    el.appendChild(errorState(err, () => settings({ el })));
    return;
  }
  draw();

  function draw() {
    const rel = s.release_at || '2026-11-19T00:00:00';
    const hasZone = /(Z|[+-]\d{2}:\d{2})$/.test(rel);
    const zone = hasZone ? (rel.endsWith('Z') ? 'Z' : rel.slice(-6)) : 'local';
    const base = hasZone ? rel.replace(/(Z|[+-]\d{2}:\d{2})$/, '') : rel;
    const zones = [['local', "Each player's own time zone (midnight local)"], ['Z', 'UTC'], ['+05:30', 'India (IST, UTC+05:30)'], ['-05:00', 'US Eastern (UTC-05:00)'], ['-08:00', 'US Pacific (UTC-08:00)'], ['+00:00', 'UK (UTC+00:00)'], ['+01:00', 'Central Europe (UTC+01:00)'], ['+04:00', 'Gulf (UTC+04:00)'], ['+08:00', 'Singapore (UTC+08:00)'], ['+10:00', 'Sydney (UTC+10:00)']];
    const checks = s.checks;
    const ok = (v) => (v ? '<span class="tag tag-ok">OK</span>' : '<span class="tag tag-danger">Missing</span>');
    el.innerHTML = pageHead('settings', 'Settings', 'Countdown, announcement, sign-in security, gallery moderation, map image and email.') + `
      <div class="dash-grid two">
        <form class="card form-card" data-form="general">
          <h3>${icon('clock')} Countdown</h3>
          <div class="form-grid">
            <label class="field"><span>Release date & time</span><input class="input" type="datetime-local" name="release_local" value="${esc(base.slice(0, 16))}"></label>
            <label class="field"><span>Time zone</span><select class="input select" name="release_zone">${zones.map(([v, l]) => `<option value="${v}" ${v === zone ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
            <label class="field"><span>Game title</span><input class="input" name="release_title" value="${esc(s.release_title)}"></label>
            <label class="field"><span>Platforms</span><input class="input" name="release_platforms" value="${esc(s.release_platforms)}"></label>
          </div>
          <h3>${icon('bell')} Home screen announcement</h3>
          <label class="field"><textarea class="input" name="announcement" rows="2" maxlength="500" placeholder="Leave empty to hide the banner">${esc(s.announcement)}</textarea></label>
          <h3>${icon('shield')} Login security (email OTP)</h3>
          <div class="segmented">
            ${[['optional', 'Players choose (2-step in profile)'], ['always', 'Always ask for an email code'], ['off', 'Off']].map(([v, l]) => `<label><input type="radio" name="login_otp_mode" value="${v}" ${s.login_otp_mode === v ? 'checked' : ''}><span>${l}</span></label>`).join('')}
          </div>
          <p class="muted small">Registration always requires an email OTP. This setting controls codes at login.</p>
          <h3>${icon('image')} Gallery</h3>
          <label class="switch"><input type="checkbox" name="gallery_requires_approval" ${s.gallery_requires_approval ? 'checked' : ''}><span class="slider"></span><span>Player uploads need admin approval before they are public</span></label>
          <h3>${icon('lock')} Privacy policy</h3>
          <label class="field"><span>Privacy contact email</span><input class="input" type="email" name="contact_email" maxlength="190" value="${esc(s.contact_email || '')}" placeholder="${esc((s.mail && s.mail.from) || 'privacy@your-domain.com')}"></label>
          <p class="muted small">Shown in the <a href="${esc(s.privacy_url)}" target="_blank" rel="noopener">Privacy Policy</a> for questions and data requests. Leave empty to use the OTP sender address.</p>
          <div class="form-actions"><button class="btn btn-primary" type="submit">${icon('check')} Save settings</button></div>
        </form>
        <div class="stack">
          <section class="card form-card">
            <h3>${icon('map')} Interactive map image</h3>
            <img class="map-thumb" src="${esc(asset(s.map.image))}" alt="Current map">
            <p class="muted small">${s.map.custom ? `Custom map (${s.map.width}×${s.map.height}px).` : 'Using the built-in fan-made Leonida map.'} Upload the official map after launch — player markers keep their relative positions.</p>
            <div class="row gap wrap">
              <label class="btn btn-primary btn-sm">${icon('upload')} Upload map image<input type="file" accept="image/*" hidden data-act="map-file"></label>
              ${s.map.custom ? `<button class="btn btn-ghost btn-sm" data-act="map-reset">${icon('refresh')} Restore fan map</button>` : ''}
            </div>
          </section>
          <section class="card form-card">
            <h3>${icon('mail')} Email (OTP delivery)</h3>
            <p class="small">Transport: <strong>${esc(s.mail.transport)}</strong>${s.mail.transport === 'smtp' ? ` · ${esc(s.mail.host)}:${esc(s.mail.port)}` : ''} · From: <strong>${esc(s.mail.from || '')}</strong> ${s.mail.configured ? '<span class="tag tag-ok">configured</span>' : '<span class="tag tag-danger">password missing in app/config.php</span>'}</p>
            <form class="input-with-btn" data-form="test-email"><input class="input" type="email" name="to" placeholder="your@email.com" required><button class="btn btn-ghost" type="submit">${icon('send')} Send test</button></form>
          </section>
          <section class="card form-card">
            <h3>${icon('monitor')} Server check</h3>
            <table class="table small"><tbody>
              <tr><td>PHP version</td><td>${esc(checks.php_version)}</td></tr>
              <tr><td>MySQL (PDO)</td><td>${ok(checks.pdo_mysql)}</td></tr>
              <tr><td>Image processing (GD)</td><td>${ok(checks.gd)}</td></tr>
              <tr><td>News fetching (cURL + SimpleXML)</td><td>${ok(checks.curl && checks.simplexml)}</td></tr>
              <tr><td>Secure email (OpenSSL)</td><td>${ok(checks.openssl)}</td></tr>
              <tr><td>uploads/ writable</td><td>${ok(checks.uploads_write)}</td></tr>
              <tr><td>app/storage writable</td><td>${ok(checks.storage_write)}</td></tr>
              <tr><td>Max upload size</td><td>${esc(checks.upload_max)} (post ${esc(checks.post_max)})</td></tr>
            </tbody></table>
            <p class="muted small">Cron command (hPanel → Advanced → Cron Jobs, every hour):</p>
            <code class="code-block">${esc(s.cron_command)}</code>
          </section>
        </div>
      </div>`;
  }

  el.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = $('button[type=submit]', form);
    try {
      if (form.dataset.form === 'general') {
        const local = form.release_local.value;
        if (!local) return toast('Choose the release date.', 'warn');
        const zone = form.release_zone.value;
        const release = `${local.length === 16 ? local + ':00' : local}${zone === 'local' ? '' : zone}`;
        setBusy(btn, true);
        s = await api.post('admin/settings-save', {
          release_at: release,
          release_title: form.release_title.value.trim(),
          release_platforms: form.release_platforms.value.trim(),
          announcement: form.announcement.value.trim(),
          login_otp_mode: form.login_otp_mode.value,
          gallery_requires_approval: form.gallery_requires_approval.checked,
          contact_email: form.contact_email.value.trim(),
        });
        toast('Settings saved', 'success');
        draw();
      } else if (form.dataset.form === 'test-email') {
        setBusy(btn, true);
        const r = await api.post('admin/test-email', { to: form.to.value.trim() });
        toast(r.transport === 'log' ? 'Logged to app/storage/mail.log (log mode).' : 'Test email sent — check the inbox (and spam).', 'success', 6000);
        setBusy(btn, false);
      }
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  });

  el.addEventListener('change', async (e) => {
    if (e.target.dataset.act !== 'map-file' || !e.target.files[0]) return;
    toast('Uploading map…');
    try {
      const map = await api.upload('admin/map-upload', { file: e.target.files[0] });
      s.map = map;
      toast('Map updated for all players', 'success');
      draw();
    } catch (err) {
      toastError(err);
    }
  });
  el.addEventListener('click', async (e) => {
    if (e.target.closest('[data-act="map-reset"]')) {
      if (!(await confirmBox('Go back to the built-in fan-made Leonida map?', { confirmText: 'Restore' }))) return;
      try {
        s.map = await api.post('admin/map-reset');
        draw();
      } catch (err) {
        toastError(err);
      }
    }
  });
}

/* ================================================================= ACCOUNT */
function account({ el }) {
  el.innerHTML = pageHead('shield', 'Admin account', 'Change the admin password.') + `
    <form class="card form-card narrow">
      <label class="field"><span>Current password</span><input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
      <label class="field"><span>New password</span><input class="input" type="password" name="password" autocomplete="new-password" minlength="6" required></label>
      <label class="field"><span>Confirm new password</span><input class="input" type="password" name="confirm_password" autocomplete="new-password" minlength="6" required></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit">${icon('check')} Change password</button></div>
    </form>`;
  const form = $('form', el);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('button[type=submit]', form);
    setBusy(btn, true);
    try {
      await api.post('admin/password', Object.fromEntries(new FormData(form).entries()));
      toast('Admin password changed. Other admin sessions were logged out.', 'success');
      form.reset();
    } catch (err) {
      toastError(err);
    } finally {
      setBusy(btn, false);
    }
  });
}

export { toLocalInput };
