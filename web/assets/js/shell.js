// App layout: sidebar, top bar (search, notifications, profile menu) and mobile bottom navigation.
import { api } from './core/api.js';
import { state, subscribe, updateMe } from './core/state.js';
import { esc, el, $, $$, avatar, levelPill, icon, toast, toastError, timeAgo, debounce, asset, openModal, closeAllModals } from './core/ui.js';
import { navigate } from './core/router.js';

export const NAV = [
  { key: 'home', label: 'Home', icon: 'home', href: '#/home', section: 'Main', short: 'Home' },
  { key: 'countdown', label: 'Countdown', icon: 'clock', href: '#/countdown', section: 'Main' },
  { key: 'news', label: 'News & Articles', icon: 'news', href: '#/news', section: 'Main', short: 'News' },
  { key: 'trailers', label: 'Trailers', icon: 'film', href: '#/trailers', section: 'Main' },
  { key: 'map', label: 'Leonida Map', icon: 'map', href: '#/map', section: 'Main', short: 'Map' },
  { key: 'friends', label: 'Friends & Chat', icon: 'chat', href: '#/friends', section: 'Social', badge: 'social', short: 'Chat' },
  { key: 'status', label: 'Status', icon: 'status', href: '#/status', section: 'Social' },
  { key: 'discussions', label: 'Discussions', icon: 'discuss', href: '#/discussions', section: 'Social' },
  { key: 'catalogue', label: 'Catalogue', icon: 'grid', href: '#/catalogue', section: 'Explore' },
  { key: 'gallery', label: 'Gallery', icon: 'image', href: '#/gallery', section: 'Explore' },
  { key: 'polls', label: 'Polls', icon: 'poll', href: '#/polls', section: 'Explore' },
  { key: 'events', label: 'Events & Rewards', icon: 'trophy', href: '#/events', section: 'Explore' },
];

const BOTTOM = ['home', 'news', 'map', 'friends'];

const NOTIF_ICONS = {
  friend_request: 'userPlus', friend_accepted: 'userCheck', discussion_comment: 'discuss', comment_reply: 'discuss',
  discussion_like: 'heart', level_up: 'zap', reward: 'gift', welcome: 'sparkles', security: 'shield', group_added: 'users',
  announcement: 'bell', news: 'news', poll: 'poll', event: 'calendar', trailer: 'film', gallery: 'image', moderation: 'flag', account: 'user',
};

function navLink(item, cls = 'nav-link') {
  const label = cls === 'bottom-link' && item.short ? item.short : item.label;
  return `<a class="${cls}" href="${item.href}" data-key="${item.key}">${icon(item.icon)}<span class="nav-label">${esc(label)}</span>${item.badge ? '<span class="nav-badge" data-badge="social" hidden></span>' : ''}</a>`;
}

export function mountShell(root, { onLogout }) {
  const sections = [...new Set(NAV.map((n) => n.section))];
  const shellEl = el(`
    <div class="shell">
      <aside class="sidebar">
        <a class="brand" href="#/home">
          <img src="assets/img/logo.svg" alt="" class="brand-logo">
          <span class="brand-text"><strong>G VI</strong><small>FAN HUB</small></span>
        </a>
        <nav class="side-nav">
          ${sections.map((s) => `<div class="nav-section"><div class="nav-heading">${esc(s)}</div>${NAV.filter((n) => n.section === s).map((n) => navLink(n)).join('')}</div>`).join('')}
        </nav>
        <div class="sidebar-foot">
          <button class="btn btn-ghost btn-block install-btn" data-act="install">${icon('download')} Install app</button>
          <p class="disclaimer">Unofficial fan app. Not affiliated with Rockstar Games or Take-Two Interactive.</p>
        </div>
      </aside>
      <div class="main-col">
        <header class="topbar">
          <a class="brand brand-mobile" href="#/home"><img src="assets/img/logo.svg" alt="" class="brand-logo"><span class="brand-text"><strong>G VI</strong><small>FAN HUB</small></span></a>
          <h1 class="page-title"></h1>
          <div class="top-search">
            ${icon('search')}
            <input type="search" placeholder="Search players, discussions, news, cars…" aria-label="Search">
            <div class="search-results" hidden></div>
          </div>
          <div class="top-actions">
            <button class="icon-btn search-toggle" aria-label="Search">${icon('search')}</button>
            <a class="icon-btn chat-btn" href="#/friends" aria-label="Chat">${icon('chat')}<span class="badge-dot" data-badge="messages" hidden></span></a>
            <button class="icon-btn bell-btn" aria-label="Notifications">${icon('bell')}<span class="badge-dot" data-badge="notifications" hidden></span></button>
            <button class="avatar-btn" aria-label="Profile menu"></button>
          </div>
          <div class="dropdown notif-panel" hidden></div>
          <div class="dropdown profile-menu" hidden></div>
        </header>
        <main class="outlet" id="outlet"></main>
      </div>
      <nav class="bottom-nav">
        ${BOTTOM.map((k) => navLink(NAV.find((n) => n.key === k), 'bottom-link')).join('')}
        <button class="bottom-link more-btn" data-key="more">${icon('menu')}<span class="nav-label">More</span></button>
      </nav>
    </div>`);
  root.appendChild(shellEl);

  const outlet = $('#outlet', shellEl);
  const notifPanel = $('.notif-panel', shellEl);
  const profileMenu = $('.profile-menu', shellEl);
  const avatarBtn = $('.avatar-btn', shellEl);

  /* ------------------------------------------------- badges & avatar */
  function renderBadges() {
    const c = state.counts || {};
    const social = (c.messages || 0) + (c.friend_requests || 0);
    $$('[data-badge="social"]', shellEl).forEach((b) => { b.hidden = !social; b.textContent = social > 99 ? '99+' : social; });
    $$('[data-badge="messages"]', shellEl).forEach((b) => { b.hidden = !c.messages; });
    $$('[data-badge="notifications"]', shellEl).forEach((b) => { b.hidden = !c.notifications; b.textContent = c.notifications > 99 ? '99+' : c.notifications || ''; });
    document.title = (c.notifications || c.messages ? `(${(c.notifications || 0) + (c.messages || 0)}) ` : '') + 'G VI Fan Hub';
  }
  function renderAvatar() {
    if (!state.me) return;
    avatarBtn.innerHTML = avatar({ ...state.me, presence: state.me.presence === 'invisible' ? 'offline' : state.me.presence }, 'sm');
  }
  renderBadges();
  renderAvatar();
  const unsub = subscribe((s, patch) => {
    if (patch.counts) renderBadges();
    if (patch.me) {
      renderAvatar();
      if (!profileMenu.hidden) renderProfileMenu();
    }
  });

  /* ------------------------------------------------------- dropdowns */
  function closeDropdowns(except) {
    [notifPanel, profileMenu].forEach((d) => { if (d !== except) d.hidden = true; });
    $('.search-results', shellEl).hidden = true;
  }
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.notif-panel, .bell-btn, .profile-menu, .avatar-btn, .top-search')) closeDropdowns();
  });

  /* --------------------------------------------------- profile menu */
  function renderProfileMenu() {
    const me = state.me;
    const lv = me.level;
    const pct = Math.round((lv.progress || 0) * 100);
    const presences = [['online', 'Online'], ['away', 'Away'], ['busy', 'Busy'], ['invisible', 'Invisible']];
    profileMenu.innerHTML = `
      <div class="pm-head">
        <div class="pm-cover" ${me.cover ? `style="background-image:url('${esc(asset(me.cover))}')"` : ''}></div>
        <div class="pm-id">
          ${avatar({ ...me, presence: me.presence === 'invisible' ? 'offline' : me.presence }, 'lg')}
          <div class="pm-name">
            <strong>${esc(me.name)}</strong>
            <span class="muted">@${esc(me.username)}</span>
            ${me.title ? `<span class="user-title">${esc(me.title)}</span>` : ''}
          </div>
        </div>
        <div class="pm-level">
          <div class="pm-level-row">${levelPill(lv.level)}<span class="rank">${esc(lv.rank)}</span><span class="muted small">${lv.xp.toLocaleString()} XP</span></div>
          <div class="xp-bar" title="${pct}% to level ${lv.level + 1}"><span style="width:${pct}%"></span></div>
          <div class="muted tiny">${(lv.next_xp - lv.xp).toLocaleString()} XP to level ${lv.level + 1}${me.login_streak > 1 ? ` · 🔥 ${me.login_streak}-day streak` : ''}</div>
        </div>
      </div>
      <div class="pm-presence" role="group" aria-label="Your status">
        ${presences.map(([k, l]) => `<button class="presence-opt ${me.presence === k ? 'active' : ''}" data-presence="${k}"><span class="presence-dot ${k === 'invisible' ? 'offline' : k}"></span>${l}</button>`).join('')}
      </div>
      <div class="pm-links">
        <a href="#/profile">${icon('user')} My profile</a>
        <a href="#/profile?tab=edit">${icon('edit')} Edit profile</a>
        <button data-act="avatar">${icon('camera')} Change profile picture</button>
        <a href="#/events?tab=rewards">${icon('gift')} Rewards & badges</a>
        <a href="#/events?tab=xp">${icon('zap')} XP & level</a>
        <a href="#/friends?tab=friends">${icon('users')} Friends</a>
        <a href="#/profile?tab=settings">${icon('settings')} Settings & privacy</a>
        <a href="#/profile?tab=security">${icon('shield')} Password & security</a>
        <button data-act="install" class="install-btn">${icon('download')} Install app</button>
        <a href="downloads/gvi-fan-hub.apk" download class="apk-menu-link">${icon('phone')} Get the Android app</a>
        <button data-act="logout" class="danger-link">${icon('logout')} Log out</button>
      </div>`;
  }

  avatarBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = profileMenu.hidden;
    closeDropdowns(profileMenu);
    if (open) renderProfileMenu();
    profileMenu.hidden = !open;
  });

  profileMenu.addEventListener('click', async (e) => {
    const presenceBtn = e.target.closest('[data-presence]');
    if (presenceBtn) {
      const presence = presenceBtn.dataset.presence;
      try {
        await api.post('profile/presence', { presence, presence_text: state.me.presence_text || '' });
        updateMe({ presence });
        toast(`Status set to ${presenceBtn.textContent.trim()}`, 'success');
      } catch (err) {
        toastError(err);
      }
      return;
    }
    if (e.target.closest('a')) {
      profileMenu.hidden = true;
      return;
    }
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'logout') {
      profileMenu.hidden = true;
      onLogout();
    } else if (act === 'avatar') {
      profileMenu.hidden = true;
      pickAndUploadAvatar();
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
    notifPanel.innerHTML = `<div class="dd-head"><strong>Notifications</strong><button class="link-btn" data-act="read-all">Mark all read</button></div><div class="dd-body"><div class="loader"><span class="spinner"></span></div></div><a class="dd-foot" href="#/notifications">View all</a>`;
    try {
      const data = await api.get('notifications/list');
      $('.dd-body', notifPanel).innerHTML = data.items.length
        ? data.items.slice(0, 12).map(notifItem).join('')
        : `<div class="empty small">${icon('bell', 'empty-icon')}<p>No notifications yet</p></div>`;
    } catch (err) {
      $('.dd-body', notifPanel).innerHTML = `<p class="muted pad">${esc(err.message)}</p>`;
    }
  }
  $('.bell-btn', shellEl).addEventListener('click', (e) => {
    e.stopPropagation();
    if (window.innerWidth < 700) {
      navigate('#/notifications');
      return;
    }
    const open = notifPanel.hidden;
    closeDropdowns(notifPanel);
    notifPanel.hidden = !open;
    if (open) openNotifications();
  });
  notifPanel.addEventListener('click', async (e) => {
    if (e.target.closest('[data-act="read-all"]')) {
      try {
        const r = await api.post('notifications/read', { all: true });
        state.counts.notifications = r.unread;
        renderBadges();
        $$('.notif.unread', notifPanel).forEach((n) => n.classList.remove('unread'));
      } catch (err) {
        toastError(err);
      }
      return;
    }
    const item = e.target.closest('.notif');
    if (item) {
      notifPanel.hidden = true;
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
    if (e.target.closest('.dd-foot')) notifPanel.hidden = true;
  });
  document.addEventListener('gvi:new-notifications', () => {
    $('.bell-btn', shellEl).classList.add('ring');
    setTimeout(() => $('.bell-btn', shellEl).classList.remove('ring'), 1200);
  });

  /* ---------------------------------------------------------- search */
  const searchBox = $('.top-search', shellEl);
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
    searchBox.classList.toggle('open');
    if (searchBox.classList.contains('open')) searchInput.focus();
  });

  /* ------------------------------------------------------- more sheet */
  $('.more-btn', shellEl).addEventListener('click', () => {
    const m = openModal({
      size: 'sheet',
      className: 'sheet-modal',
      body: `
        <div class="more-head">${avatar(state.me, 'md')}<div><strong>${esc(state.me.name)}</strong><div class="muted small">@${esc(state.me.username)} · ${levelPill(state.me.level.level)}</div></div><a class="btn btn-sm btn-ghost" href="#/profile">Profile</a></div>
        <div class="more-grid">${NAV.map((n) => `<a href="${n.href}" class="more-item">${icon(n.icon)}<span>${esc(n.label)}</span>${n.badge ? '<span class="nav-badge" data-badge="social" hidden></span>' : ''}</a>`).join('')}
          <a href="#/notifications" class="more-item">${icon('bell')}<span>Notifications</span></a>
          <a href="#/profile?tab=settings" class="more-item">${icon('settings')}<span>Settings</span></a>
        </div>
        <button class="btn btn-ghost btn-block install-btn" data-act="install">${icon('download')} Install app</button>
        <button class="btn btn-ghost btn-block danger-link" data-act="logout">${icon('logout')} Log out</button>`,
    });
    const c = state.counts;
    const social = (c.messages || 0) + (c.friend_requests || 0);
    $$('[data-badge="social"]', m.el).forEach((b) => { b.hidden = !social; b.textContent = social; });
    m.el.addEventListener('click', (e) => {
      if (e.target.closest('a')) m.close();
      const act = e.target.closest('[data-act]')?.dataset.act;
      if (act === 'logout') { m.close(); onLogout(); }
      if (act === 'install') m.close();
    });
  });

  /* ------------------------------------------------------ route hook */
  function onRoute(route) {
    closeDropdowns();
    closeAllModals();
    const key = route.meta.key;
    $$('[data-key]', shellEl).forEach((a) => a.classList.toggle('active', a.dataset.key === key));
    const isMore = !BOTTOM.includes(key);
    $('.more-btn', shellEl).classList.toggle('active', isMore && key !== 'profile');
    $('.page-title', shellEl).textContent = route.meta.title || '';
    shellEl.classList.toggle('full-bleed', !!route.meta.fullBleed);
  }

  return { outlet, onRoute, destroy: unsub };
}

function notifItem(n) {
  return `<button class="notif ${n.is_read ? '' : 'unread'}" data-id="${n.id}" data-link="${esc(n.link || '')}">
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
