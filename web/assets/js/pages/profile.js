// Player profile: view (own or others), edit, settings, privacy, security & account.
import { api } from '../core/api.js';
import { state, updateMe, setState, subscribe } from '../core/state.js';
import { esc, $, $$, icon, timeAgo, fmtDate, asset, presenceLabel, openModal, confirmBox, toast, toastError, setBusy, loader, errorState, reportDialog, richText, shareLink, appLink } from '../core/ui.js';
import { navigate, setQuery } from '../core/router.js';
import { pickAndUploadAvatar, setTheme, THEMES, PRESENCES } from '../shell.js';
import { logout } from '../app.js';
import { isReduced, setReducedMotion } from '../core/gvi-motion.js';
import { ringAvatar, tint, glare } from '../core/gvi-ui.js';
import { rewardIcon } from './events.js';
import { relationButton } from './friends.js';

const TABS = [['overview', 'Overview'], ['badges', 'Badges'], ['settings', 'Settings'], ['security', 'Security']];
const TAB_KEYS = [...TABS.map((t) => t[0]), 'edit'];

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
    if (own && patch.me && $('.ph-avatar .gvi-ring-av', el)) {
      $('.ph-avatar .gvi-ring-av', el).outerHTML = ringAvatar(s.me, 120, { presence: false });
    }
  });

  function draw() {
    const p = own ? { ...data.profile, ...state.me, level_info: state.me.level, level: state.me.level.level, rank: state.me.level.rank } : data.profile;
    const lv = p.level_info;
    const stats = p.stats;
    const sub = [`@${esc(p.username)}`, esc(lv.rank),
      p.created_at ? `Joined ${esc(fmtDate(p.created_at, { month: 'short', year: 'numeric' }))}` : '',
      own && p.login_streak ? `${p.login_streak}-day streak` : ''].filter(Boolean).join(' · ');
    el.innerHTML = `
      <section class="gvi-profile-hero">
        <div class="ph-cover" ${p.cover ? `style="background-image:url('${esc(asset(p.cover))}')"` : ''}>
          ${own ? `<div class="ph-cover-actions"><label class="gvi-btn ghost sm glass">${icon('camera')}Change cover<input type="file" accept="image/*" hidden data-up="cover"></label>${p.cover ? `<button class="gvi-btn ghost sm glass" data-act="rm-cover" aria-label="Remove cover">${icon('trash')}</button>` : ''}</div>` : ''}
        </div>
        <div class="ph-main">
          <div class="ph-avatar">${ringAvatar({ ...p, presence: p.presence === 'invisible' ? 'offline' : p.presence }, 120, { presence: !own })}
            ${own ? `<button class="ph-avatar-btn" data-act="avatar" title="Change profile picture" aria-label="Change profile picture">${icon('camera')}</button>` : ''}</div>
          <div class="ph-id">
            <h1>${esc(p.name)}${p.title ? `<span class="ph-title">${esc(p.title)}</span>` : ''}<span class="ph-lvl">LVL ${lv.level}</span>${p.is_banned ? `<span class="tag tag-danger">${icon('ban')} Blocked</span>` : ''}</h1>
            <div class="ph-sub">${sub}</div>
            <div class="ph-presence"><span class="pdot ${esc(p.presence === 'invisible' ? 'offline' : p.presence)}"></span>${own ? esc({ online: 'Online', away: 'Away', busy: 'Busy', invisible: 'Invisible (appear offline)' }[p.presence] || 'Online') : esc(presenceLabel(p))}${p.presence_text ? ` · <em>“${esc(p.presence_text)}”</em>` : ''}</div>
          </div>
          <div class="ph-actions">${own ? `<button class="gvi-btn ghost" data-tabgo="edit">${icon('edit')}Edit profile</button><button class="gvi-btn" data-act="share">${icon('share')}Share</button>` : otherActions(p)}</div>
        </div>
        ${stats ? `<div class="ph-stats">
          <div><b>${lv.xp.toLocaleString()} / ${lv.next_xp.toLocaleString()} XP</b><span>XP</span><i class="ph-xpbar"><i style="width:${Math.round(lv.progress * 100)}%"></i></i></div>
          <a href="${own ? '#/friends?tab=friends' : `#/profile/${p.id}`}"><b>${stats.friends}</b><span>Friends</span></a>
          <div><b>${stats.discussions}</b><span>Posts</span></div>
          <div><b>${stats.rewards}</b><span>Badges</span></div>
          <div><b>${stats.markers}</b><span>Markers</span></div>
        </div>` : ''}
      </section>
      ${own ? `<div class="gvi-tabs ph-tabs" role="tablist">${TABS.map(([k, l]) => `<button class="tab" data-tab="${k}" role="tab">${l}</button>`).join('')}</div>` : ''}
      <div class="profile-body"></div>`;
    if (own) {
      showTab(TAB_KEYS.includes(query.tab) ? query.tab : 'overview');
    } else {
      $('.profile-body', el).innerHTML = overviewHTML(p);
    }
  }

  function otherActions(p) {
    if (p.relation === 'blocked') return `<button class="gvi-btn ghost" data-rel="unblock">Unblock</button>`;
    let html = '';
    if (p.relation !== 'friends') html += relationButton({ relation: p.relation }).replace('data-act=', 'data-rel=').replace(' sm"', '"');
    if (p.can_message) html += `<a class="gvi-btn ${p.relation === 'friends' ? '' : 'ghost'}" href="#/friends?tab=chats&user=${p.id}">${icon('chat')}Message</a>`;
    html += `<button class="gvi-btn ghost icon-only" data-act="more" aria-label="More">${icon('more')}</button>`;
    return html;
  }

  function overviewHTML(p) {
    if (p.is_private) {
      return `<div class="gvi-panel empty">${icon('lock', 'empty-icon')}<h3>This profile is private</h3><p>Only ${esc(p.name)}'s friends can see their details.</p></div>`;
    }
    const chips = [
      p.platform ? `${icon('gamepad')}${esc(p.platform)}` : '',
      p.fav_character ? `${icon('star')}Team ${esc(p.fav_character)}` : '',
      p.country ? `${icon('globe')}${esc(p.country)}` : '',
    ].filter(Boolean);
    const ids = [['Rockstar Social Club', p.rockstar_id], ['PlayStation Network', p.psn_id], ['Xbox gamertag', p.xbox_id]].filter((x) => x[1]);
    return `
      <div class="gvi-profile-grid">
        <section class="gvi-panel pcard">
          <h3>About</h3>
          ${p.bio ? `<div class="prose">${richText(p.bio)}</div>` : `<p class="muted">${own ? 'Tell other fans about yourself — use Edit profile to add a bio.' : 'No bio yet.'}</p>`}
          ${chips.length ? `<div class="gvi-chip-row">${chips.map((c) => `<span class="gvi-chip static sm">${c}</span>`).join('')}</div>` : ''}
          ${ids.length ? `<div class="id-list">${ids.map(([l, v]) => `<div class="id-row"><span>${l}</span><strong>${esc(v)}</strong></div>`).join('')}</div>` : ''}
        </section>
        <section class="gvi-panel pcard">
          <h3>Recent activity</h3>
          ${data.recent_discussions.length ? `<div class="activity-list">${data.recent_discussions.map((d) => `<a href="#/discussions/${d.id}">Posted <b>${esc(d.title)}</b><small> · ${timeAgo(d.created_at)}</small></a>`).join('')}</div>` : '<p class="muted">No discussions yet.</p>'}
        </section>
        <section class="gvi-panel pcard">
          <h3>${own ? 'Garage' : 'Badges'}</h3>
          <div class="pcard-side">${own ? loader() : badgeMini(data.badges)}</div>
        </section>
      </div>`;
  }

  function badgeMini(list) {
    if (!list.length) return '<p class="muted">No badges yet.</p>';
    return `<div class="activity-list">${list.slice(0, 6).map((b) => `<span class="badge-line">${rewardIcon(b, 'xs')}<b>${esc(b.title)}</b><small> · ${timeAgo(b.claimed_at)}</small></span>`).join('')}</div>`;
  }

  async function loadGarage(box) {
    try {
      const r = await api.get('catalogue/items', { favorites: 1, sort: 'name' });
      box.innerHTML = r.items.length
        ? `<div class="garage-list">${r.items.slice(0, 6).map((i) => `<a href="#/catalogue?item=${i.id}"><span>${esc(i.name)}</span><small>${esc(i.subtype || i.category)}</small></a>`).join('')}</div>`
        : `<p class="muted">Nothing saved yet. Save vehicles and gear from the <a href="#/catalogue">Catalogue</a>.</p>`;
    } catch (err) {
      box.innerHTML = `<p class="muted">${esc(err.message)}</p>`;
    }
  }

  async function loadBadges(box) {
    box.innerHTML = loader();
    try {
      const r = await api.get('rewards/list');
      const list = [...r.rewards].sort((a, b) => Number(b.claimed) - Number(a.claimed));
      box.innerHTML = list.length ? `<div class="gvi-badge-wall">${list.map((b, i) => `
        <a class="gvi-badge-big ${b.claimed ? 'earned' : 'locked'}" href="#/events?tab=rewards" style="${tint(i)}" data-tilt="card">
          ${rewardIcon(b, 'lg')}<strong>${esc(b.title)}</strong>
          <small>${esc(b.description || (b.required_level > 1 ? `Reach level ${b.required_level}` : 'Community reward'))}</small>
          <em>${b.claimed ? 'Earned' : 'Locked'}</em>${glare}
        </a>`).join('')}</div>` : '<p class="muted">Admins will add badges soon.</p>';
    } catch (err) {
      box.innerHTML = '';
      box.appendChild(errorState(err, () => loadBadges(box)));
    }
  }

  function showTab(tab) {
    $$('.ph-tabs .tab', el).forEach((t) => {
      t.classList.toggle('active', t.dataset.tab === tab);
      t.setAttribute('aria-selected', String(t.dataset.tab === tab));
    });
    setQuery({ tab: tab === 'overview' ? null : tab });
    const body = $('.profile-body', el);
    const me = state.me;
    if (tab === 'overview') {
      body.innerHTML = overviewHTML({ ...data.profile, ...me });
      loadGarage($('.pcard-side', body));
    } else if (tab === 'badges') {
      loadBadges(body);
    } else if (tab === 'edit') {
      const platforms = (state.config && state.config.platforms) || [];
      body.innerHTML = `
        <form class="gvi-panel form-card" data-form="profile">
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
          <div class="form-actions"><button class="gvi-btn ghost" type="button" data-tabgo="overview">Cancel</button><button class="gvi-btn" type="submit">${icon('check')}Save profile</button></div>
        </form>`;
    } else if (tab === 'settings') {
      const reduced = isReduced();
      body.innerHTML = `
        <div class="gvi-settings-grid">
          <section class="gvi-panel pcard">
            <h3>Appearance</h3>
            <div class="set-label">Theme</div>
            <div class="theme-pick">${THEMES.map(([k, l, a, b]) => `<button class="theme-opt ${(me.theme || 'vice') === k ? 'active' : ''}" data-theme-set="${k}" aria-label="${l} theme"><span style="background:linear-gradient(135deg,${a},${b})"></span>${l}</button>`).join('')}</div>
            <button class="set-toggle" data-act="motion" aria-pressed="${reduced}"><span><strong>Reduce motion</strong><small>Stops the background, 3D tilt and flips</small></span><span class="gvi-toggle ${reduced ? 'is-on' : ''}"></span></button>
          </section>
          <form class="gvi-panel pcard" data-form="privacy">
            <h3>Presence &amp; privacy</h3>
            <div class="gvi-seg presence-seg" role="radiogroup" aria-label="Online status">${PRESENCES.map(([k, l]) => `<label class="${me.presence === k ? 'is-active' : ''}"><input type="radio" name="presence" value="${k}" ${me.presence === k ? 'checked' : ''}><span class="pdot ${k === 'invisible' ? 'offline' : k}"></span>${l}</label>`).join('')}</div>
            <label class="set-toggle"><span><strong>Friends-only profile</strong><small>Only friends see your bio, IDs and activity</small></span><input type="checkbox" name="profile_privacy" value="friends" ${me.profile_privacy === 'friends' ? 'checked' : ''} hidden><span class="gvi-toggle ${me.profile_privacy === 'friends' ? 'is-on' : ''}"></span></label>
            <label class="set-toggle"><span><strong>Only friends can message me</strong><small>Everyone else is blocked from direct messages</small></span><input type="checkbox" name="dm_privacy" value="friends" ${me.dm_privacy === 'friends' ? 'checked' : ''} hidden><span class="gvi-toggle ${me.dm_privacy === 'friends' ? 'is-on' : ''}"></span></label>
            <small class="muted">“Invisible” hides your online status from other players.</small>
            <div class="form-actions"><button class="gvi-btn" type="submit">${icon('check')}Save settings</button></div>
          </form>
          <section class="gvi-panel pcard">
            <h3>Account</h3>
            <div class="acct-row"><small>Display name</small><strong>${esc(me.name)}</strong></div>
            <div class="acct-row"><small>Email</small><strong>${esc(me.email)}</strong></div>
            <div class="set-label">Equipped cosmetics</div>
            <div class="equip-row"></div>
            <div class="form-actions start"><button class="gvi-btn ghost" data-tabgo="edit">${icon('edit')}Edit profile</button><button class="gvi-btn danger" data-act="logout">${icon('logout')}Log out</button></div>
          </section>
        </div>`;
      loadEquip($('.equip-row', body));
    } else if (tab === 'security') {
      const otpMode = state.config ? state.config.login_otp_mode : 'optional';
      body.innerHTML = `
        <div class="gvi-settings-grid security">
          <form class="gvi-panel pcard form-card" data-form="password">
            <h3>${icon('key')} Change password</h3>
            <div class="form-grid">
              <label class="field span-2"><span>Current password</span><input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
              <label class="field"><span>New password</span><input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required></label>
              <label class="field"><span>Confirm new password</span><input class="input" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label>
            </div>
            <p class="muted tiny">Forgot your current password? Log out and use “Forgot password” on the login screen.</p>
            <div class="form-actions"><button class="gvi-btn" type="submit">${icon('check')}Update password</button></div>
          </form>
          <section class="gvi-panel pcard form-card">
            <h3>${icon('shield')} 2-step login (email OTP)</h3>
            ${otpMode === 'always' ? `<p>${icon('check')} Your admins require an email code on every login for all players.</p>`
              : otpMode === 'off' ? '<p class="muted">2-step login is currently disabled by the admins.</p>'
              : `<label class="switch"><input type="checkbox" data-act="otp" ${me.login_otp_enabled ? 'checked' : ''}><span class="slider"></span><span>Ask for a code sent to <strong>${esc(me.email)}</strong> every time I log in</span></label>`}
          </section>
          <section class="gvi-panel pcard form-card">
            <h3>${icon('monitor')} Active sessions</h3>
            <div class="sessions">${loader()}</div>
            <button class="gvi-btn ghost" data-act="logout-others">${icon('logout')}Log out all other devices</button>
          </section>
          <section class="gvi-panel pcard form-card danger-zone">
            <h3>${icon('alert')} Delete account</h3>
            <p class="muted">Permanently deletes your profile, messages, markers, discussions and uploads. This cannot be undone.</p>
            <button class="gvi-btn danger" data-act="delete-account">${icon('trash')}Delete my account</button>
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
      $$('[data-theme-set]', el).forEach((x) => x.classList.toggle('active', x === theme));
      await setTheme(theme.dataset.themeSet);
      return;
    }
    const rel = e.target.closest('[data-rel]');
    if (rel) return relationAction(rel.dataset.rel, rel);
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    try {
      if (act === 'motion') {
        const on = !isReduced();
        setReducedMotion(on);
        const b = e.target.closest('[data-act]');
        b.setAttribute('aria-pressed', String(on));
        $('.gvi-toggle', b).classList.toggle('is-on', on);
        toast(on ? 'Reduced motion on' : 'Full motion on', 'success');
      } else if (act === 'logout') {
        logout();
      } else if (act === 'share') {
        shareLink(`${state.me.name} on G VI Fan Hub`, appLink('#/profile/' + state.me.id));
      } else if (act === 'avatar') {
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
    if (e.target.closest('.set-toggle') && e.target.type === 'checkbox') {
      $('.gvi-toggle', e.target.closest('.set-toggle')).classList.toggle('is-on', e.target.checked);
    }
    if (e.target.name === 'presence' && e.target.closest('.presence-seg')) {
      $$('.presence-seg label', el).forEach((l) => l.classList.toggle('is-active', l.contains(e.target)));
    }
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
        const r = await api.post('profile/update', { profile_privacy: fd.profile_privacy ? 'friends' : 'public', dm_privacy: fd.dm_privacy ? 'friends' : 'everyone', presence: fd.presence });
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
