// G VI Fan Hub - app entry point.
import { api } from './core/api.js';
import { state, setState } from './core/state.js';
import { toast, esc, fmtDateTime, icon } from './core/ui.js';
import { defineRoute, startRouter, stopRouter } from './core/router.js';

const root = document.getElementById('app');
let pulseTimer = null;
let shell = null;

/* ------------------------------------------------------------ routes */
const page = (name) => () => import(`./pages/${name}.js`);
defineRoute('/home', page('home'), { key: 'home', title: 'Home' });
defineRoute('/countdown', page('countdown'), { key: 'countdown', title: 'Countdown' });
defineRoute('/news', page('news'), { key: 'news', title: 'News & Articles' });
defineRoute('/news/:id', page('news'), { key: 'news', title: 'News & Articles' });
defineRoute('/trailers', page('trailers'), { key: 'trailers', title: 'Trailers' });
defineRoute('/map', page('map'), { key: 'map', title: 'Leonida Map', fullBleed: true });
defineRoute('/friends', page('friends'), { key: 'friends', title: 'Friends & Chat' });
defineRoute('/status', page('status'), { key: 'status', title: 'Status' });
defineRoute('/discussions', page('discussions'), { key: 'discussions', title: 'Discussions' });
defineRoute('/discussions/:id', page('discussions'), { key: 'discussions', title: 'Discussions' });
defineRoute('/catalogue', page('catalogue'), { key: 'catalogue', title: 'Catalogue' });
defineRoute('/gallery', page('gallery'), { key: 'gallery', title: 'Gallery' });
defineRoute('/polls', page('polls'), { key: 'polls', title: 'Polls' });
defineRoute('/events', page('events'), { key: 'events', title: 'Events & Rewards' });
defineRoute('/profile', page('profile'), { key: 'profile', title: 'Profile' });
defineRoute('/profile/:id', page('profile'), { key: 'profile', title: 'Profile' });
defineRoute('/notifications', page('notifications'), { key: 'notifications', title: 'Notifications' });

/* ------------------------------------------------------- app install */
window.__gviInstall = null;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  window.__gviInstall = e;
  document.documentElement.classList.add('can-install');
});
window.addEventListener('appinstalled', () => {
  window.__gviInstall = null;
  document.documentElement.classList.remove('can-install');
  toast('G VI Fan Hub was installed!', 'success');
});
if (/GVIFanHubApp/i.test(navigator.userAgent) || window.matchMedia('(display-mode: standalone)').matches) {
  document.documentElement.classList.add('is-app');
}
if (/Android/i.test(navigator.userAgent)) document.documentElement.classList.add('is-android');

if ('serviceWorker' in navigator && location.protocol !== 'file:') {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  });
}

/* ------------------------------------------------------------- theme */
export function applyTheme(theme) {
  document.documentElement.dataset.theme = theme || 'vice';
}

/* ------------------------------------------------------------- pulse */
async function pulse() {
  if (!state.me || document.visibilityState !== 'visible') return;
  try {
    const data = await api.get('app/pulse');
    const before = state.counts.notifications;
    setState({ counts: data.counts });
    if (data.xp !== undefined && state.me && data.xp !== state.me.level?.xp) {
      refreshMe();
    }
    if (data.counts.notifications > before) {
      document.dispatchEvent(new CustomEvent('gvi:new-notifications'));
    }
  } catch (e) {
    /* handled by api hooks */
  }
}

function startPulse() {
  stopPulse();
  pulseTimer = setInterval(pulse, 25000);
  document.addEventListener('visibilitychange', onVisible);
}

function stopPulse() {
  clearInterval(pulseTimer);
  document.removeEventListener('visibilitychange', onVisible);
}

function onVisible() {
  if (document.visibilityState === 'visible') pulse();
}

export async function refreshMe() {
  try {
    const data = await api.get('auth/me');
    if (data.user) {
      api.csrf = data.csrf;
      setState({ me: data.user, counts: data.counts, config: data.config });
    }
  } catch (e) {
    /* ignore */
  }
}

/* ----------------------------------------------------- start / stop */
async function startApp(data) {
  api.csrf = data.csrf;
  setState({ me: data.user, counts: data.counts || state.counts, config: data.config || state.config });
  applyTheme(data.user.theme);
  root.innerHTML = '';
  const { mountShell } = await import('./shell.js');
  shell = mountShell(root, { onLogout: logout });
  startRouter(shell.outlet, (route) => shell.onRoute(route));
  startPulse();
}

async function showAuth(message) {
  stopRouter();
  stopPulse();
  setState({ me: null });
  api.csrf = null;
  applyTheme('vice');
  const { renderAuth } = await import('./pages/auth.js');
  renderAuth(root, {
    message,
    onLoggedIn: (data) => {
      if (!location.hash || location.hash === '#/' || location.hash.startsWith('#/auth')) history.replaceState(null, '', '#/home');
      startApp(data);
    },
    onBanned: showBanned,
  });
}

function showBanned(ban, message) {
  stopRouter();
  stopPulse();
  setState({ me: null });
  const until = ban && ban.until ? fmtDateTime(ban.until) : null;
  root.innerHTML = `
    <div class="auth-screen banned-screen">
      <div class="auth-card ban-card">
        <div class="ban-icon">${icon('ban')}</div>
        <h1>Account blocked</h1>
        <p class="lead">${ban && ban.permanent ? 'Your account has been permanently blocked by the moderators.' : `Your account is blocked until <strong>${esc(until || '')}</strong>.`}</p>
        <div class="ban-reason"><span>Reason</span><p>${esc((ban && ban.reason) || message || 'Violation of the community rules.')}</p></div>
        <p class="muted small">You can sign in again once the block ends. If you think this is a mistake, contact the site admin.</p>
        <button class="btn btn-ghost" id="ban-back">${icon('chevronLeft')} Back to login</button>
      </div>
    </div>`;
  root.querySelector('#ban-back').addEventListener('click', () => showAuth());
}

async function logout() {
  try {
    await api.post('auth/logout');
  } catch (e) {
    /* ignore */
  }
  showAuth('You have been logged out. See you in Leonida!');
}

api.onAuthError = (err) => {
  if (state.me && err.code === 'unauthenticated') {
    showAuth('Your session has ended. Please log in again.');
  }
};
api.onBanned = (err) => {
  showBanned(err.payload && err.payload.ban, err.message);
};

async function boot() {
  try {
    const data = await api.get('auth/me');
    setState({ config: data.config });
    if (data.user) {
      startApp(data);
    } else if (data.ban) {
      showBanned(data.ban, data.ban_message);
    } else {
      showAuth();
    }
  } catch (e) {
    if (e.code === 'not_installed') {
      root.innerHTML = `<div class="auth-screen"><div class="auth-card"><h1>Almost there!</h1><p>The app is not installed yet. Open <a href="install.php">install.php</a> to finish the setup.</p></div></div>`;
      return;
    }
    root.innerHTML = `<div class="auth-screen"><div class="auth-card"><h1>Can't reach the server</h1><p class="muted">${esc(e.message)}</p><button class="btn btn-primary" onclick="location.reload()">${icon('refresh')} Try again</button></div></div>`;
  }
}

boot();
