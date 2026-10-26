// App layout: glass top bar (search, chat, notifications, profile), floating dock + radial wheel navigation,
// notification and profile popovers.
import { api } from './core/api.js';
import { state, subscribe, updateMe, setState } from './core/state.js';
import { esc, el, $, $$, avatar, icon, toast, toastError, timeAgo, debounce, asset, openModal, closeAllModals, initials } from './core/ui.js';
import { iconBody } from './core/icons.js';
import { navigate, parseHash } from './core/router.js';
import { mountDock, closeWheel, logoSvg } from './core/gvi-nav.js';
import { isReduced, setReducedMotion } from './core/gvi-motion.js';
import { applyTheme } from './app.js';

export const NAV = [
  { key: 'home', label: 'Home', icon: 'home', short: 'Home', desc: 'Countdown, news and what your friends are up to', sub: 'Your dashboard' },
  { key: 'countdown', label: 'Countdown', icon: 'clock', desc: 'Days until launch, hype meter, road to launch', sub: 'Days until launch' },
  { key: 'news', label: 'News & Articles', icon: 'news', short: 'News', desc: 'Latest from trusted sources', sub: 'Latest from trusted sources' },
  { key: 'trailers', label: 'Trailers', icon: 'film', desc: 'Watch every trailer', sub: 'Watch every trailer' },
  { key: 'map', label: 'Leonida Map', icon: 'map', short: 'Map', desc: 'Pin places and notes across the state', sub: 'Pin places & notes' },
  { key: 'friends', label: 'Friends & Chat', icon: 'chat', short: 'Chat', badge: 'social', desc: 'DMs, groups and friend requests', sub: 'Chat and groups' },
  { key: 'status', label: 'Status', icon: 'status', desc: 'Stories and who is online', sub: 'Stories & who is online' },
  { key: 'discussions', label: 'Discussions', icon: 'discuss', desc: 'Theories, articles and hot takes', sub: 'Theories & articles' },
  { key: 'catalogue', label: 'Catalogue', icon: 'grid', desc: 'Vehicles, weapons and gear', sub: 'Vehicles, weapons, gear' },
  { key: 'gallery', label: 'Gallery', icon: 'image', desc: 'Photos and videos from the community', sub: 'Photos & videos' },
  { key: 'polls', label: 'Polls', icon: 'poll', desc: 'Vote and earn XP', sub: 'Vote & earn XP' },
  { key: 'events', label: 'Events & Rewards', icon: 'trophy', desc: 'Join, claim, level up', sub: 'Join, claim, level up' },
];

export const THEMES = [
  ['vice', 'Vice', '#ff3d8b', '#ff8a3d'],
  ['leonida', 'Leonida', '#19d3c5', '#3b82f6'],
  ['sunset', 'Sunset', '#ff7a2f', '#ffcf3f'],
  ['midnight', 'Midnight', '#8b5cf6', '#3b82f6'],
];

export const PRESENCES = [['online', 'Online'], ['away', 'Away'], ['busy', 'Busy'], ['invisible', 'Invisible']];

const NOTIF_ICONS = {
  friend_request: 'userPlus', friend_accepted: 'userCheck', discussion_comment: 'discuss', comment_reply: 'discuss',
  discussion_like: 'heart', level_up: 'zap', reward: 'gift', welcome: 'sparkles', security: 'shield', group_added: 'users',
  announcement: 'bell', news: 'news', poll: 'poll', event: 'calendar', trailer: 'film', gallery: 'image', moderation: 'flag', account: 'user',
};

/** 3D logo tile (top bar / login). */
export function logoTile(size = '') {
  return `<span class="gvi-logo3d ${size}"><span data-tilt="global"><span class="gvi-logo-base"></span>${size === 'lg' ? '<span class="gvi-logo-halo"></span><span class="gvi-logo-rim"></span>' : ''}${logoSvg(44)}${size === 'lg' ? '<span class="gvi-logo-gloss"></span>' : ''}</span></span>`;
}

/** Wordmark "G VI / FAN HUB". */
export function wordmark() {
  return `<span class="gvi-brand-text"><strong class="gvi-wordmark">G VI</strong><small class="gvi-wordmark-sub">FAN HUB</small></span>`;
}

/** Gradient avatar button content: the photo if there is one, otherwise initials. */
function avatarFace(u) {
  return u.avatar ? `<img src="${esc(asset(u.avatar))}" alt="">` : esc(initials(u.name));
}

/** Saves the theme for the account and recolours the app. */
export async function setTheme(key) {
  const label = (THEMES.find((t) => t[0] === key) || THEMES[0])[1];
  applyTheme(key);
  try {
    const r = await api.post('profile/update', { theme: key });
    setState({ me: r.user });
    toast(`${label} theme on`, 'success');
  } catch (err) {
    applyTheme(state.me.theme);
    toastError(err);
  }
}

/** Saves the presence (online / away / busy / invisible). */
export async function setPresence(presence, text) {
  const label = (PRESENCES.find((p) => p[0] === presence) || PRESENCES[0])[1];
  try {
    await api.post('profile/presence', { presence, presence_text: text !== undefined ? text : (state.me.presence_text || '') });
    updateMe({ presence, ...(text !== undefined ? { presence_text: text } : {}) });
    toast(`You're ${label.toLowerCase()}`, 'success');
    return true;
  } catch (err) {
    toastError(err);
    return false;
  }
}

export function mountShell(root, { onLogout }) {
  const shellEl = el(`
    <div class="gvi-shell">
      <header class="gvi-topbar">
        <a class="gvi-brand" href="#/home" aria-label="G VI Fan Hub — home">${logoTile()}${wordmark()}</a>
        <span class="gvi-divider" aria-hidden="true"></span>
        <h1 class="gvi-page-name"></h1>
        <div class="gvi-search-wrap">
          ${icon('search')}
          <input type="search" class="gvi-search" placeholder="Search players, discussions, news, cars…" aria-label="Search">
          <div class="search-results" hidden></div>
        </div>
        <div class="gvi-top-actions">
          <button class="gvi-icon-btn search-toggle" aria-label="Search">${icon('search')}</button>
          <a class="gvi-icon-btn chat-btn" href="#/friends" aria-label="Chat">${icon('chat')}<span class="gvi-badge" data-badge="messages" hidden></span></a>
          <button class="gvi-icon-btn bell-btn" aria-label="Notifications" aria-expanded="false">${icon('bell')}<span class="gvi-badge" data-badge="notifications" hidden></span></button>
          <button class="gvi-avatar-btn avatar-btn" aria-label="Profile menu" aria-expanded="false"></button>
        </div>
      </header>
      <main class="gvi-main" id="outlet"></main>
      <div class="gvi-pop-scrim" hidden></div>
      <div class="gvi-pop notif-pop" hidden></div>
      <div class="gvi-pop profile-pop" hidden></div>
    </div>`);
  root.appendChild(shellEl);

  const outlet = $('#outlet', shellEl);
  const notifPop = $('.notif-pop', shellEl);
  const profilePop = $('.profile-pop', shellEl);
  const scrim = $('.gvi-pop-scrim', shellEl);
  const avatarBtn = $('.avatar-btn', shellEl);
  const bellBtn = $('.bell-btn', shellEl);

  const dock = mountDock({
    items: NAV,
    current: () => parseHash().path,
    onGo: (key) => navigate('#/' + key),
    icon,
    iconBody,
  });

  /* ------------------------------------------------- badges & avatar */
  function renderBadges() {
    const c = state.counts || {};
    const social = (c.messages || 0) + (c.friend_requests || 0);
    const fmt = (n) => (n > 99 ? '99+' : String(n));
    $$('[data-badge="social"]').forEach((b) => { b.hidden = !social; b.textContent = social ? fmt(social) : ''; });
    $$('[data-badge="messages"]', shellEl).forEach((b) => { b.hidden = !c.messages; b.textContent = c.messages ? fmt(c.messages) : ''; });
    $$('[data-badge="notifications"]', shellEl).forEach((b) => { b.hidden = !c.notifications; b.textContent = c.notifications ? fmt(c.notifications) : ''; });
    document.title = (c.notifications || c.messages ? `(${(c.notifications || 0) + (c.messages || 0)}) ` : '') + 'G VI Fan Hub';
  }
  function renderAvatar() {
    if (!state.me) return;
    avatarBtn.innerHTML = avatarFace(state.me);
    avatarBtn.classList.toggle('has-img', !!state.me.avatar);
  }
  renderBadges();
  renderAvatar();
  const unsub = subscribe((s, patch) => {
    if (patch.counts) renderBadges();
    if (patch.me) {
      renderAvatar();
      if (!profilePop.hidden) renderProfilePop();
    }
  });

  /* ------------------------------------------------------- popovers */
  function closePops(except) {
    [notifPop, profilePop].forEach((p) => { if (p !== except) p.hidden = true; });
    scrim.hidden = !except;
    bellBtn.setAttribute('aria-expanded', String(except === notifPop));
    avatarBtn.setAttribute('aria-expanded', String(except === profilePop));
    $('.search-results', shellEl).hidden = true;
  }
  scrim.addEventListener('click', () => closePops());
  const onKey = (e) => {
    if (e.key === 'Escape') {
      closePops();
      $('.gvi-search-wrap', shellEl).classList.remove('open');
    }
  };
  document.addEventListener('keydown', onKey);
  const onDocClick = (e) => {
    if (!e.target.closest('.gvi-search-wrap, .search-toggle')) $('.search-results', shellEl).hidden = true;
  };
  document.addEventListener('click', onDocClick);

  /* --------------------------------------------------- profile menu */
  function renderProfilePop() {
    const me = state.me;
    const lv = me.level;
    const pct = Math.round((lv.progress || 0) * 100);
    const reduced = isReduced();
    profilePop.innerHTML = `
      <div class="pp-cover" ${me.cover ? `style="background-image:url('${esc(asset(me.cover))}')"` : ''}></div>
      <div class="pp-id">
        <span class="pp-avatar">${avatarFace(me)}</span>
        <span class="pp-name"><strong>${esc(me.name)}</strong><span>@${esc(me.username)}${me.title ? ` · <b>${esc(me.title)}</b>` : ''}</span></span>
      </div>
      <div class="pp-level">
        <div class="pp-level-row"><span class="gvi-level">LVL ${lv.level}</span><span class="pp-rank">${esc(lv.rank)}</span><span class="muted">${lv.xp.toLocaleString()} / ${lv.next_xp.toLocaleString()} XP</span></div>
        <div class="gvi-xp" title="${pct}% to level ${lv.level + 1}"><span style="width:${pct}%"></span></div>
      </div>
      <div class="pp-presence" role="group" aria-label="Your status">
        ${PRESENCES.map(([k, l]) => `<button class="${me.presence === k ? 'is-active' : ''}" data-presence="${k}" aria-pressed="${me.presence === k}"><span class="pdot ${k}"></span>${l}</button>`).join('')}
      </div>
      <div class="pp-section">
        <div class="pp-label">Theme</div>
        <div class="pp-swatches">${THEMES.map(([k, l, a, b]) => `<button class="gvi-swatch ${(me.theme || 'vice') === k ? 'is-active' : ''}" data-theme-set="${k}" title="${l}" aria-label="${l} theme" style="background:linear-gradient(135deg,${a},${b})"></button>`).join('')}</div>
        <button class="pp-toggle-row" data-act="motion" aria-pressed="${reduced}"><span>Reduce motion</span><span class="gvi-toggle ${reduced ? 'is-on' : ''}"></span></button>
      </div>
      <div class="pp-links">
        <a href="#/profile">${icon('user')} My profile</a>
        <a href="#/events?tab=rewards">${icon('gift')} Rewards & badges</a>
        <a href="#/profile?tab=settings">${icon('shield')} Settings & privacy</a>
        <a href="privacy.php">${icon('lock')} Privacy policy</a>
        <button data-act="install" class="install-btn">${icon('download')} Install app</button>
        <a href="downloads/gvi-fan-hub.apk" download class="apk-menu-link">${icon('phone')} Get the Android app</a>
        <button data-act="logout" class="pp-logout">${icon('logout')} Log out</button>
      </div>`;
  }

  avatarBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = profilePop.hidden;
    if (open) {
      renderProfilePop();
      closePops(profilePop);
      profilePop.hidden = false;
    } else {
      closePops();
    }
  });

  profilePop.addEventListener('click', async (e) => {
    const presenceBtn = e.target.closest('[data-presence]');
    if (presenceBtn) {
      if (await setPresence(presenceBtn.dataset.presence)) renderProfilePop();
      return;
    }
    const themeBtn = e.target.closest('[data-theme-set]');
    if (themeBtn) {
      $$('[data-theme-set]', profilePop).forEach((b) => b.classList.toggle('is-active', b === themeBtn));
      await setTheme(themeBtn.dataset.themeSet);
      return;
    }
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'motion') {
      const on = !isReduced();
      setReducedMotion(on);
      renderProfilePop();
      toast(on ? 'Reduced motion on' : 'Full motion on', 'success');
      return;
    }
    if (e.target.closest('a')) {
      closePops();
      return;
    }
    if (act === 'logout') {
      closePops();
      onLogout();
    } else if (act === 'install') {
      closePops();
    }
  });

  /* ----------------------------------------------------- install app */
  shellEl.addEventListener('click', async (e) => {
    if (!e.target.closest('[data-act="install"]')) return;
    const prompt = window.__gviInstall;
    if (prompt) {
      prompt.prompt();
      await prompt.userChoice;
      window.__gviInstall = null;
      document.documentElement.classList.remove('can-install');
    } else {
      openModal({
        title: 'Install G VI Fan Hub',
        size: 'sm',
        body: `<div class="install-help">
          <p><strong>${icon('monitor')} Desktop (Chrome / Edge):</strong> click the install icon in the address bar, or ⋮ menu → <em>Install G VI Fan Hub</em>.</p>
          <p><strong>${icon('phone')} Android:</strong> Chrome ⋮ menu → <em>Add to Home screen / Install app</em>, or <a href="downloads/gvi-fan-hub.apk" download>download the Android app (APK)</a>.</p>
          <p><strong>iPhone / iPad:</strong> Safari → Share → <em>Add to Home Screen</em>.</p></div>`,
      });
    }
  });

  /* --------------------------------------------------- notifications */
  async function openNotifications() {
    notifPop.innerHTML = `<div class="np-head"><strong>Notifications</strong><button class="link-btn" data-act="read-all">Mark all read</button></div><div class="np-body"><div class="loader"><span class="spinner"></span></div></div><a class="np-foot" href="#/notifications">View all notifications</a>`;
    try {
      const data = await api.get('notifications/list');
      $('.np-body', notifPop).innerHTML = data.items.length
        ? data.items.slice(0, 12).map(notifItem).join('')
        : `<div class="empty small">${icon('bell', 'empty-icon')}<p>No notifications yet</p></div>`;
    } catch (err) {
      $('.np-body', notifPop).innerHTML = `<p class="muted pad">${esc(err.message)}</p>`;
    }
  }
  bellBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = notifPop.hidden;
    if (open) {
      closePops(notifPop);
      notifPop.hidden = false;
      openNotifications();
    } else {
      closePops();
    }
  });
  notifPop.addEventListener('click', async (e) => {
    if (e.target.closest('[data-act="read-all"]')) {
      try {
        const r = await api.post('notifications/read', { all: true });
        state.counts.notifications = r.unread;
        renderBadges();
        $$('.notif.unread', notifPop).forEach((n) => n.classList.remove('unread'));
      } catch (err) {
        toastError(err);
      }
      return;
    }
    const item = e.target.closest('.notif');
    if (item) {
      closePops();
      if (item.classList.contains('unread')) {
        api.post('notifications/read', { id: item.dataset.id }).then((r) => {
          state.counts.notifications = r.unread;
          renderBadges();
        }).catch(() => {});
      }
      if (item.dataset.link) {
        if (item.dataset.link.startsWith('#')) navigate(item.dataset.link);
        else window.open(item.dataset.link, '_blank', 'noopener');
      }
    }
    if (e.target.closest('.np-foot')) closePops();
  });
  const onNewNotif = () => {
    bellBtn.classList.add('ring');
    setTimeout(() => bellBtn.classList.remove('ring'), 1200);
  };
  document.addEventListener('gvi:new-notifications', onNewNotif);

  /* ---------------------------------------------------------- search */
  const searchBox = $('.gvi-search-wrap', shellEl);
  const searchInput = $('input', searchBox);
  const results = $('.search-results', searchBox);
  const runSearch = debounce(async () => {
    const q = searchInput.value.trim();
    if (q.length < 2) {
      results.hidden = true;
      return;
    }
    try {
      const r = await api.get('app/search', { q });
      results.innerHTML = searchResultsHTML(r);
      results.hidden = false;
    } catch (err) {
      results.hidden = true;
    }
  }, 250);
  searchInput.addEventListener('input', runSearch);
  searchInput.addEventListener('focus', () => { if (results.innerHTML && searchInput.value.trim().length > 1) results.hidden = false; });
  results.addEventListener('click', (e) => {
    if (e.target.closest('a')) {
      results.hidden = true;
      searchInput.value = '';
      searchBox.classList.remove('open');
    }
  });
  $('.search-toggle', shellEl).addEventListener('click', (e) => {
    e.stopPropagation();
    closePops();
    searchBox.classList.toggle('open');
    if (searchBox.classList.contains('open')) searchInput.focus();
  });

  /* ------------------------------------------------------ route hook */
  function onRoute(route) {
    closePops();
    closeWheel();
    closeAllModals();
    searchBox.classList.remove('open');
    $('.gvi-page-name', shellEl).textContent = route.meta.title || '';
    shellEl.dataset.route = route.meta.key || '';
  }

  function destroy() {
    unsub();
    dock.destroy();
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('gvi:new-notifications', onNewNotif);
  }

  return { outlet, onRoute, destroy };
}

function notifItem(n) {
  return `<button class="notif gvi-pop-row ${n.is_read ? '' : 'unread'}" data-id="${n.id}" data-link="${esc(n.link || '')}">
    <span class="notif-icon">${n.actor ? avatar(n.actor, 'sm', { presence: false }) : icon(NOTIF_ICONS[n.type] || 'bell')}</span>
    <span class="notif-text"><strong>${esc(n.title)}</strong>${n.body ? `<span>${esc(n.body)}</span>` : ''}<small>${timeAgo(n.created_at)}</small></span>
  </button>`;
}
export { notifItem, NOTIF_ICONS };

function searchResultsHTML(r) {
  const groups = [];
  if (r.users.length) groups.push(`<div class="sr-group"><div class="sr-title">Players</div>${r.users.map((u) => `<a href="#/profile/${u.id}" class="sr-item">${avatar(u, 'xs')}<span>${esc(u.name)} <small class="muted">@${esc(u.username)}</small></span></a>`).join('')}</div>`);
  if (r.discussions.length) groups.push(`<div class="sr-group"><div class="sr-title">Discussions</div>${r.discussions.map((d) => `<a href="#/discussions/${d.id}" class="sr-item">${icon('discuss')}<span>${esc(d.title)}</span></a>`).join('')}</div>`);
  if (r.news.length) groups.push(`<div class="sr-group"><div class="sr-title">News</div>${r.news.map((n) => `<a href="#/news/${n.id}" class="sr-item">${icon('news')}<span>${esc(n.title)} <small class="muted">· ${esc(n.source_name)}</small></span></a>`).join('')}</div>`);
  if (r.items.length) groups.push(`<div class="sr-group"><div class="sr-title">Catalogue</div>${r.items.map((i) => `<a href="#/catalogue?item=${i.id}" class="sr-item">${icon('grid')}<span>${esc(i.name)} <small class="muted">· ${esc(i.category)}</small></span></a>`).join('')}</div>`);
  return groups.join('') || '<div class="sr-empty muted">No results</div>';
}

/** Opens a file picker and uploads a new profile picture. */
export function pickAndUploadAvatar() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';
  input.addEventListener('change', async () => {
    const file = input.files[0];
    if (!file) return;
    toast('Uploading profile picture…');
    try {
      const r = await api.upload('profile/avatar', { file });
      updateMe({ avatar: r.avatar });
      toast('Profile picture updated!', 'success');
    } catch (err) {
      toastError(err);
    }
  });
  input.click();
}
