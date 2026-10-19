// Player profile: view (own or others), edit, settings, privacy, security & account.
import { api } from '../core/api.js';
import { state, updateMe, setState, subscribe } from '../core/state.js';
import { esc, $, $$, icon, avatar, levelPill, timeAgo, fmtDate, asset, presenceLabel, openModal, confirmBox, toast, toastError, setBusy, loader, errorState, reportDialog, richText } from '../core/ui.js';
import { navigate, setQuery } from '../core/router.js';
import { pickAndUploadAvatar } from '../shell.js';
import { applyTheme } from '../app.js';
import { rewardIcon } from './events.js';
import { relationButton } from './friends.js';

const THEMES = [['vice', 'Vice City', '#ff3d8b', '#ff8a3d'], ['leonida', 'Leonida Keys', '#19d3c5', '#3b82f6'], ['sunset', 'Sunset Strip', '#ff8a3d', '#ffd23f'], ['midnight', 'Midnight', '#8b5cf6', '#3b82f6']];

export async function render({ el, params, query }) {
  const ownId = state.me.id;
  const id = params.id ? Number(params.id) : ownId;
  if (params.id && id === ownId) {
    navigate('#/profile', true);
    return;
  }
  el.innerHTML = loader();
  let data;
  try {
    data = await api.get('profile/get', { id });
  } catch (err) {
    el.innerHTML = '';
    el.appendChild(errorState(err));
    return;
  }
  const own = data.profile.relation === 'self';
  draw();
  // Keep the header in sync when the avatar/name changes from the profile menu.
  const unsubscribe = subscribe((s, patch) => {
    if (own && patch.me && $('.ph-avatar .avatar', el)) {
      $('.ph-avatar .avatar', el).outerHTML = avatar({ ...s.me, presence: s.me.presence === 'invisible' ? 'offline' : s.me.presence }, 'xxl');
    }
  });

  function draw() {
    const p = own ? { ...data.profile, ...state.me, level_info: state.me.level, level: state.me.level.level, rank: state.me.level.rank } : data.profile;
    const lv = p.level_info;
    const stats = p.stats;
    el.innerHTML = `
      <section class="profile-hero card">
        <div class="ph-cover" ${p.cover ? `style="background-image:url('${esc(asset(p.cover))}')"` : ''}>
          ${own ? `<div class="ph-cover-actions"><label class="btn btn-glass btn-sm">${icon('camera')} Change cover<input type="file" accept="image/*" hidden data-up="cover"></label>${p.cover ? `<button class="btn btn-glass btn-sm" data-act="rm-cover">${icon('trash')}</button>` : ''}</div>` : ''}
        </div>
        <div class="ph-main">
          <div class="ph-avatar">${avatar({ ...p, presence: p.presence === 'invisible' && own ? 'offline' : p.presence }, 'xxl')}
            ${own ? `<button class="ph-avatar-btn" data-act="avatar" title="Change profile picture">${icon('camera')}</button>` : ''}</div>
          <div class="ph-id">
            <h1>${esc(p.name)} ${p.is_banned ? `<span class="tag tag-danger">${icon('ban')} Blocked</span>` : ''}</h1>
            <div class="ph-sub"><span class="muted">@${esc(p.username)}</span>${p.title ? `<span class="user-title big">${esc(p.title)}</span>` : ''}</div>
            <div class="ph-presence"><span class="presence-dot inline ${esc(own && p.presence === 'invisible' ? 'offline' : (own ? p.presence : p.presence))}"></span>${own ? esc({ online: 'Online', away: 'Away', busy: 'Busy', invisible: 'Invisible (appear offline)' }[p.presence] || 'Online') : esc(presenceLabel(p))}${p.presence_text ? ` · <em>“${esc(p.presence_text)}”</em>` : ''}</div>
          </div>
          <div class="ph-actions">${own ? `<button class="btn btn-primary" data-tabgo="edit">${icon('edit')} Edit profile</button>` : otherActions(p)}</div>
        </div>
        <div class="ph-level">
          <div class="lc-ring small" style="--p:${Math.round(lv.progress * 100)}"><span>${lv.level}</span></div>
          <div class="ph-level-text"><strong>${esc(lv.rank)}</strong><span class="muted small">${lv.xp.toLocaleString()} XP · ${(lv.next_xp - lv.xp).toLocaleString()} XP to level ${lv.level + 1}</span><div class="xp-bar"><span style="width:${Math.round(lv.progress * 100)}%"></span></div></div>
          ${stats ? `<div class="ph-stats">
            <a href="${own ? '#/friends?tab=friends' : '#'}"><b>${stats.friends}</b><span>Friends</span></a>
            <span><b>${stats.discussions}</b><span>Posts</span></span>
            <span><b>${stats.comments}</b><span>Comments</span></span>
            <span><b>${stats.markers}</b><span>Pins</span></span>
            <span><b>${stats.rewards}</b><span>Rewards</span></span>
          </div>` : ''}
        </div>
        ${own ? `<div class="tabs ph-tabs">
          ${[['overview', 'user', 'Overview'], ['edit', 'edit', 'Edit'], ['settings', 'settings', 'Settings'], ['security', 'shield', 'Security']].map(([k, ic, l]) => `<button class="tab" data-tab="${k}">${icon(ic)}<span>${l}</span></button>`).join('')}
        </div>` : ''}
      </section>
      <div class="profile-body"></div>`;
    if (own) {
      const tab = ['overview', 'edit', 'settings', 'security'].includes(query.tab) ? query.tab : 'overview';
      showTab(tab);
    } else {
      $('.profile-body', el).innerHTML = overviewHTML(p);
    }
  }

  function otherActions(p) {
    if (p.relation === 'blocked') return `<button class="btn btn-ghost" data-rel="unblock">Unblock</button>`;
    let html = '';
    if (p.relation !== 'friends') html += relationButton({ relation: p.relation }).replace('data-act=', 'data-rel=');
    if (p.can_message) html += `<a class="btn ${p.relation === 'friends' ? 'btn-primary' : 'btn-ghost'}" href="#/friends?tab=chats&user=${p.id}">${icon('chat')} Message</a>`;
    html += `<button class="icon-btn" data-act="more" aria-label="More">${icon('more')}</button>`;
    return html;
  }

  function overviewHTML(p) {
    if (p.is_private) {
      return `<div class="card empty">${icon('lock', 'empty-icon')}<h3>This profile is private</h3><p>Only ${esc(p.name)}'s friends can see their details.</p></div>`;
    }
    const chips = [
      p.platform ? `${icon('gamepad')} ${esc(p.platform)}` : '',
      p.fav_character ? `${icon('star')} Team ${esc(p.fav_character)}` : '',
      p.country ? `${icon('globe')} ${esc(p.country)}` : '',
      p.created_at ? `${icon('calendar')} Joined ${esc(fmtDate(p.created_at, { month: 'long', year: 'numeric' }))}` : '',
    ].filter(Boolean);
    const ids = [['Rockstar Social Club', p.rockstar_id], ['PlayStation Network', p.psn_id], ['Xbox gamertag', p.xbox_id]].filter((x) => x[1]);
    return `
      <div class="profile-grid">
        <div class="stack">
          <section class="card">
            <h3>${icon('user')} About</h3>
            ${p.bio ? `<div class="prose">${richText(p.bio)}</div>` : `<p class="muted">${own ? 'Tell other fans about yourself — edit your profile to add a bio.' : 'No bio yet.'}</p>`}
            ${chips.length ? `<div class="chip-row wrap">${chips.map((c) => `<span class="chip static">${c}</span>`).join('')}</div>` : ''}
          </section>
          ${ids.length ? `<section class="card"><h3>${icon('gamepad')} Gamer IDs</h3><div class="id-list">${ids.map(([l, v]) => `<div class="id-row"><span class="muted">${l}</span><strong>${esc(v)}</strong></div>`).join('')}</div></section>` : ''}
          <section class="card">
            <h3>${icon('discuss')} Recent discussions</h3>
            ${data.recent_discussions.length ? `<div class="list-card">${data.recent_discussions.map((d) => `<a class="list-row" href="#/discussions/${d.id}"><div class="lr-main"><strong>${esc(d.title)}</strong><span class="muted small">${esc(d.category)} · ${timeAgo(d.created_at)}</span></div><span class="lr-meta">${icon('heart')} ${d.likes_count} ${icon('discuss')} ${d.comments_count}</span></a>`).join('')}</div>` : '<p class="muted">No discussions yet.</p>'}
          </section>
        </div>
        <section class="card badges-card">
          <h3>${icon('trophy')} Badges & rewards</h3>
          ${data.badges.length ? `<div class="badge-grid">${data.badges.map((b) => `<div class="badge-item" title="${esc(b.description || '')}">${rewardIcon(b)}<strong>${esc(b.title)}</strong><small class="muted">${timeAgo(b.claimed_at)}</small></div>`).join('')}</div>`
            : `<p class="muted">${own ? 'No rewards yet — level up and claim them in Events & Rewards.' : 'No rewards yet.'}</p>`}
          ${own ? `<a class="btn btn-ghost btn-block" href="#/events?tab=rewards">${icon('gift')} Rewards</a>` : ''}
        </section>
      </div>`;
  }

  function showTab(tab) {
    $$('.ph-tabs .tab', el).forEach((t) => t.classList.toggle('active', t.dataset.tab === tab));
    setQuery({ tab: tab === 'overview' ? null : tab });
    const body = $('.profile-body', el);
    const me = state.me;
    if (tab === 'overview') {
      body.innerHTML = overviewHTML({ ...data.profile, ...me });
    } else if (tab === 'edit') {
      const platforms = (state.config && state.config.platforms) || [];
      body.innerHTML = `
        <form class="card form-card" data-form="profile">
          <h3>${icon('edit')} Edit profile</h3>
          <div class="form-grid">
            <label class="field"><span>Display name</span><input class="input" name="name" maxlength="80" required value="${esc(me.name)}"></label>
            <label class="field"><span>Username</span><div class="input-prefix"><span>@</span><input class="input" name="username" maxlength="30" pattern="[A-Za-z0-9_]{3,30}" required value="${esc(me.username)}"></div></label>
            <label class="field span-2"><span>Bio</span><textarea class="input" name="bio" rows="3" maxlength="300" placeholder="Favourite GTA moments, crew name, play style…">${esc(me.bio || '')}</textarea></label>
            <label class="field"><span>Platform</span><select class="input select" name="platform"><option value="">—</option>${platforms.map((pl) => `<option ${pl === me.platform ? 'selected' : ''}>${esc(pl)}</option>`).join('')}</select></label>
            <label class="field"><span>Favourite protagonist</span><select class="input select" name="fav_character">${['', 'Lucia', 'Jason', 'Both'].map((c) => `<option value="${c}" ${c === (me.fav_character || '') ? 'selected' : ''}>${c || '—'}</option>`).join('')}</select></label>
            <label class="field"><span>Country</span><input class="input" name="country" maxlength="60" value="${esc(me.country || '')}"></label>
            <label class="field"><span>Activity status</span><input class="input" name="presence_text" maxlength="80" placeholder="What are you up to?" value="${esc(me.presence_text || '')}"></label>
            <label class="field"><span>Rockstar Social Club ID</span><input class="input" name="rockstar_id" maxlength="40" value="${esc(me.rockstar_id || '')}"></label>
            <label class="field"><span>PlayStation Network ID</span><input class="input" name="psn_id" maxlength="40" value="${esc(me.psn_id || '')}"></label>
            <label class="field"><span>Xbox gamertag</span><input class="input" name="xbox_id" maxlength="40" value="${esc(me.xbox_id || '')}"></label>
          </div>
          <div class="form-actions"><button class="btn btn-primary" type="submit">${icon('check')} Save profile</button></div>
        </form>`;
    } else if (tab === 'settings') {
      body.innerHTML = `
        <div class="stack">
          <section class="card form-card">
            <h3>${icon('palette')} App theme</h3>
            <div class="theme-pick">${THEMES.map(([k, l, a, b]) => `<button class="theme-opt ${me.theme === k ? 'active' : ''}" data-theme-set="${k}"><span style="background:linear-gradient(135deg,${a},${b})"></span>${l}</button>`).join('')}</div>
          </section>
          <form class="card form-card" data-form="privacy">
            <h3>${icon('lock')} Privacy</h3>
            <div class="field"><span>Who can see my profile details?</span><div class="segmented">
              <label><input type="radio" name="profile_privacy" value="public" ${me.profile_privacy === 'public' ? 'checked' : ''}><span>${icon('globe')} Everyone</span></label>
              <label><input type="radio" name="profile_privacy" value="friends" ${me.profile_privacy === 'friends' ? 'checked' : ''}><span>${icon('users')} Friends only</span></label></div></div>
            <div class="field"><span>Who can send me direct messages?</span><div class="segmented">
              <label><input type="radio" name="dm_privacy" value="everyone" ${me.dm_privacy === 'everyone' ? 'checked' : ''}><span>${icon('globe')} Everyone</span></label>
              <label><input type="radio" name="dm_privacy" value="friends" ${me.dm_privacy === 'friends' ? 'checked' : ''}><span>${icon('users')} Friends only</span></label></div></div>
            <div class="field"><span>Online status</span><div class="segmented">
              ${[['online', 'Online'], ['away', 'Away'], ['busy', 'Busy'], ['invisible', 'Invisible']].map(([k, l]) => `<label><input type="radio" name="presence" value="${k}" ${me.presence === k ? 'checked' : ''}><span><span class="presence-dot inline ${k === 'invisible' ? 'offline' : k}"></span>${l}</span></label>`).join('')}</div>
              <small class="muted">“Invisible” hides your online status from other players.</small></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">${icon('check')} Save settings</button></div>
          </form>
          <section class="card form-card">
            <h3>${icon('gift')} Equipped cosmetics</h3>
            <p class="muted small">Titles and avatar frames you unlock in Events & Rewards.</p>
            <div class="equip-row"></div>
          </section>
        </div>`;
      loadEquip($('.equip-row', body));
    } else if (tab === 'security') {
      const otpMode = state.config ? state.config.login_otp_mode : 'optional';
      body.innerHTML = `
        <div class="stack">
          <form class="card form-card" data-form="password">
            <h3>${icon('key')} Change password</h3>
            <div class="form-grid">
              <label class="field span-2"><span>Current password</span><input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
              <label class="field"><span>New password</span><input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required></label>
              <label class="field"><span>Confirm new password</span><input class="input" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label>
            </div>
            <p class="muted tiny">Forgot your current password? Log out and use “Forgot password” on the login screen.</p>
            <div class="form-actions"><button class="btn btn-primary" type="submit">${icon('check')} Update password</button></div>
          </form>
          <section class="card form-card">
            <h3>${icon('shield')} 2-step login (email OTP)</h3>
            ${otpMode === 'always' ? `<p>${icon('check')} Your admins require an email code on every login for all players.</p>`
              : otpMode === 'off' ? '<p class="muted">2-step login is currently disabled by the admins.</p>'
              : `<label class="switch"><input type="checkbox" data-act="otp" ${me.login_otp_enabled ? 'checked' : ''}><span class="slider"></span><span>Ask for a code sent to <strong>${esc(me.email)}</strong> every time I log in</span></label>`}
          </section>
          <section class="card form-card">
            <h3>${icon('monitor')} Active sessions</h3>
            <div class="sessions">${loader()}</div>
            <button class="btn btn-ghost" data-act="logout-others">${icon('logout')} Log out all other devices</button>
          </section>
          <section class="card form-card danger-zone">
            <h3>${icon('alert')} Delete account</h3>
            <p class="muted">Permanently deletes your profile, messages, markers, discussions and uploads. This cannot be undone.</p>
            <button class="btn btn-danger" data-act="delete-account">${icon('trash')} Delete my account</button>
          </section>
        </div>`;
      loadSessions($('.sessions', body));
    }
  }

  async function loadEquip(box) {
    try {
      const r = await api.get('rewards/list');
      const owned = r.rewards.filter((x) => x.claimed);
      const titles = owned.filter((x) => x.reward_type === 'title');
      const frames = owned.filter((x) => x.reward_type === 'frame');
      box.innerHTML = `
        <label class="field"><span>Profile title</span><select class="input select" data-equip="title_id"><option value="0">None</option>${titles.map((t) => `<option value="${t.id}" ${r.equipped.title_id === t.id ? 'selected' : ''}>${esc(t.title)}</option>`).join('')}</select></label>
        <label class="field"><span>Avatar frame</span><select class="input select" data-equip="frame_id"><option value="0">None</option>${frames.map((t) => `<option value="${t.id}" ${r.equipped.frame_id === t.id ? 'selected' : ''}>${esc(t.title)}</option>`).join('')}</select></label>
        ${!titles.length && !frames.length ? '<p class="muted small">Nothing unlocked yet — claim titles and frames in <a href="#/events?tab=rewards">Rewards</a>.</p>' : ''}`;
      $$('[data-equip]', box).forEach((s) => s.addEventListener('change', async () => {
        try {
          const res = await api.post('profile/equip', { [s.dataset.equip]: Number(s.value) });
          setState({ me: res.user });
          toast('Saved', 'success');
        } catch (err) {
          toastError(err);
        }
      }));
    } catch (err) {
      box.innerHTML = `<p class="muted">${esc(err.message)}</p>`;
    }
  }

  async function loadSessions(box) {
    try {
      const list = await api.get('profile/sessions');
      box.innerHTML = list.map((s) => `<div class="session-row">${icon(/Android|iPhone|Mobile/i.test(s.device || '') ? 'phone' : 'monitor')}
        <div><strong>${esc(describeDevice(s.device))}</strong>${s.current ? ' <span class="chip chip-ok">This device</span>' : ''}<small class="muted">IP ${esc(s.ip || '?')} · last active ${timeAgo(s.last_used_at)}</small></div></div>`).join('');
    } catch (err) {
      box.innerHTML = `<p class="muted">${esc(err.message)}</p>`;
    }
  }

  el.addEventListener('click', async (e) => {
    const tabBtn = e.target.closest('[data-tab]');
    if (tabBtn && own) return showTab(tabBtn.dataset.tab);
    const go = e.target.closest('[data-tabgo]');
    if (go) return showTab(go.dataset.tabgo);
    const theme = e.target.closest('[data-theme-set]');
    if (theme) {
      try {
        const r = await api.post('profile/update', { theme: theme.dataset.themeSet });
        setState({ me: r.user });
        applyTheme(r.user.theme);
        $$('[data-theme-set]', el).forEach((x) => x.classList.toggle('active', x === theme));
        toast('Theme updated', 'success');
      } catch (err) {
        toastError(err);
      }
      return;
    }
    const rel = e.target.closest('[data-rel]');
    if (rel) return relationAction(rel.dataset.rel, rel);
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    try {
      if (act === 'avatar') {
        pickAndUploadAvatar();
      } else if (act === 'rm-cover') {
        await api.post('profile/cover-remove');
        updateMe({ cover: null });
        draw();
      } else if (act === 'more') {
        const p = data.profile;
        const m = openModal({
          title: p.name,
          size: 'sm',
          body: `<div class="menu-list">
            ${p.relation === 'friends' ? `<button class="menu-item" data-m="remove">${icon('minus')} Remove friend</button>` : ''}
            <button class="menu-item" data-m="report">${icon('flag')} Report player</button>
            <button class="menu-item danger" data-m="block">${icon('ban')} Block player</button></div>`,
        });
        m.el.addEventListener('click', async (ev) => {
          const mm = ev.target.closest('[data-m]')?.dataset.m;
          if (!mm) return;
          m.close();
          if (mm === 'report') reportDialog(api, 'user', p.id);
          if (mm === 'remove') relationAction('remove');
          if (mm === 'block') relationAction('block');
        });
      } else if (act === 'logout-others') {
        const r = await api.post('profile/logout-others');
        toast(`Logged out of ${r.signed_out} other device(s)`, 'success');
        loadSessions($('.sessions', el));
      } else if (act === 'delete-account') {
        deleteAccount();
      }
    } catch (err) {
      toastError(err);
    }
  });

  el.addEventListener('change', async (e) => {
    const up = e.target.closest('[data-up]');
    if (up && up.files[0]) {
      toast('Uploading cover…');
      try {
        const r = await api.upload('profile/cover', { file: up.files[0] });
        updateMe({ cover: r.cover });
        draw();
        toast('Cover updated!', 'success');
      } catch (err) {
        toastError(err);
      }
    }
    if (e.target.dataset.act === 'otp') {
      try {
        const r = await api.post('profile/security', { login_otp_enabled: e.target.checked });
        updateMe({ login_otp_enabled: r.login_otp_enabled });
        toast(r.login_otp_enabled ? '2-step login enabled — we will email you a code at every login.' : '2-step login disabled', 'success');
      } catch (err) {
        e.target.checked = !e.target.checked;
        toastError(err);
      }
    }
  });

  el.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = $('button[type=submit]', form);
    const fd = Object.fromEntries(new FormData(form).entries());
    setBusy(btn, true);
    try {
      if (form.dataset.form === 'profile') {
        const r = await api.post('profile/update', fd);
        setState({ me: r.user });
        data.profile = { ...data.profile, ...r.user };
        toast('Profile saved', 'success');
        draw();
        showTab('overview');
      } else if (form.dataset.form === 'privacy') {
        const r = await api.post('profile/update', { profile_privacy: fd.profile_privacy, dm_privacy: fd.dm_privacy, presence: fd.presence });
        setState({ me: r.user });
        toast('Settings saved', 'success');
      } else if (form.dataset.form === 'password') {
        if (fd.password !== fd.confirm_password) throw new Error('New passwords do not match.');
        const r = await api.post('profile/password', fd);
        form.reset();
        toast(`Password changed${r.signed_out_sessions ? ` — ${r.signed_out_sessions} other session(s) logged out` : ''}.`, 'success');
      }
    } catch (err) {
      toastError(err);
    } finally {
      setBusy(btn, false);
    }
  });

  async function relationAction(action, btn) {
    const p = data.profile;
    try {
      let r;
      if (action === 'add') r = await api.post('friends/request', { user_id: p.id });
      else if (action === 'cancel') r = await api.post('friends/cancel', { user_id: p.id });
      else if (action === 'accept') r = await api.post('friends/respond', { user_id: p.id, action: 'accept' });
      else if (action === 'unblock') r = await api.post('friends/unblock', { user_id: p.id });
      else if (action === 'message') return navigate(`#/friends?tab=chats&user=${p.id}`);
      else if (action === 'remove') {
        if (!(await confirmBox(`Remove ${p.name} from your friends?`, { confirmText: 'Remove', danger: true }))) return;
        r = await api.post('friends/remove', { user_id: p.id });
      } else if (action === 'block') {
        if (!(await confirmBox(`Block ${p.name}? You won't see each other's content or messages.`, { confirmText: 'Block', danger: true }))) return;
        r = await api.post('friends/block', { user_id: p.id });
      }
      if (r) {
        const fresh = await api.get('profile/get', { id: p.id });
        data = fresh;
        draw();
        const msg = { outgoing: 'Friend request sent', friends: 'You are now friends!', none: 'Done', blocked: 'Player blocked' }[r.relation];
        if (msg) toast(msg, 'success');
      }
    } catch (err) {
      toastError(err);
    }
  }

  function deleteAccount() {
    const m = openModal({
      title: 'Delete your account?',
      size: 'sm',
      body: `<p>This permanently removes your account and everything you posted. Enter your password to confirm.</p>
        <label class="field"><span>Password</span><input class="input" type="password" autocomplete="current-password"></label>`,
      footer: `<button class="btn btn-ghost" data-x="cancel">Cancel</button><button class="btn btn-danger" data-x="ok">${icon('trash')} Delete forever</button>`,
    });
    $('[data-x="cancel"]', m.el).addEventListener('click', () => m.close());
    $('[data-x="ok"]', m.el).addEventListener('click', async (ev) => {
      const btn = ev.target.closest('button');
      setBusy(btn, true);
      try {
        await api.post('profile/delete', { password: $('input', m.el).value });
        m.close();
        toast('Your account was deleted. Goodbye!', 'success');
        setTimeout(() => location.reload(), 1200);
      } catch (err) {
        toastError(err);
        setBusy(btn, false);
      }
    });
  }

  return unsubscribe;
}

function describeDevice(ua) {
  if (!ua) return 'Unknown device';
  if (/GVIFanHubApp/i.test(ua)) return 'G VI Fan Hub Android app';
  const os = /Windows/i.test(ua) ? 'Windows' : /Android/i.test(ua) ? 'Android' : /iPhone|iPad/i.test(ua) ? 'iOS' : /Mac OS/i.test(ua) ? 'macOS' : /Linux/i.test(ua) ? 'Linux' : 'Unknown OS';
  const br = /Edg\//i.test(ua) ? 'Edge' : /OPR\//i.test(ua) ? 'Opera' : /Chrome\//i.test(ua) ? 'Chrome' : /Firefox\//i.test(ua) ? 'Firefox' : /Safari\//i.test(ua) ? 'Safari' : 'Browser';
  return `${br} on ${os}`;
}
