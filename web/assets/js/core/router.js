// Hash router: "#/news/12?tab=x" -> loads pages/news.js and calls render(ctx).

const routes = [];
let current = null;
let outlet = null;
let onChange = null;
let onMount = null;
let navToken = 0;

export function defineRoute(pattern, loader, meta = {}) {
  const keys = [];
  const regex = new RegExp('^' + pattern.replace(/:([a-z]+)/g, (_, k) => { keys.push(k); return '([^/]+)'; }) + '/?$');
  routes.push({ pattern, regex, keys, loader, meta });
}

export function parseHash() {
  const raw = location.hash.replace(/^#/, '') || '/home';
  const [path, qs] = raw.split('?');
  return { path: path || '/home', query: Object.fromEntries(new URLSearchParams(qs || '')) };
}

export function navigate(hash, replace = false) {
  if (!hash.startsWith('#')) hash = '#' + hash;
  if (replace) {
    history.replaceState(null, '', hash);
    handle();
  } else if (location.hash === hash) {
    handle();
  } else {
    location.hash = hash;
  }
}

/** Updates the query string of the current route without re-rendering. */
export function setQuery(patch) {
  const { path, query } = parseHash();
  const q = { ...query, ...patch };
  Object.keys(q).forEach((k) => (q[k] === null || q[k] === undefined || q[k] === '') && delete q[k]);
  const qs = new URLSearchParams(q).toString();
  history.replaceState(null, '', '#' + path + (qs ? '?' + qs : ''));
}

async function handle() {
  const { path, query } = parseHash();
  let match = null;
  let params = {};
  for (const r of routes) {
    const m = path.match(r.regex);
    if (m) {
      match = r;
      r.keys.forEach((k, i) => { params[k] = decodeURIComponent(m[i + 1]); });
      break;
    }
  }
  if (!match) {
    navigate('#/home', true);
    return;
  }
  const token = ++navToken;
  if (current && typeof current.cleanup === 'function') {
    try { current.cleanup(); } catch (e) { console.error(e); }
  }
  current = null;
  if (onChange) onChange(match, params, query);
  outlet.innerHTML = '<div class="page-loading"><span class="spinner lg"></span></div>';
  outlet.scrollTop = 0;
  window.scrollTo(0, 0);
  try {
    const mod = await match.loader();
    if (token !== navToken) return;
    const page = document.createElement('div');
    page.className = 'page page-' + (match.meta.key || 'x');
    outlet.innerHTML = '';
    outlet.appendChild(page);
    if (onMount) onMount(page);
    const cleanup = await mod.render({ el: page, params, query, path });
    if (token !== navToken) {
      if (typeof cleanup === 'function') cleanup();
      return;
    }
    current = { cleanup };
  } catch (e) {
    console.error(e);
    if (token !== navToken) return;
    outlet.innerHTML = `<div class="empty error"><h3>Could not open this page</h3><p>${String(e && e.message ? e.message : e).replace(/</g, '&lt;')}</p><button class="btn btn-ghost" onclick="location.reload()">Reload</button></div>`;
  }
}

export function startRouter(outletEl, changeCb, mountCb = null) {
  outlet = outletEl;
  onChange = changeCb;
  onMount = mountCb;
  window.addEventListener('hashchange', handle);
  handle();
}

export function stopRouter() {
  window.removeEventListener('hashchange', handle);
  if (current && typeof current.cleanup === 'function') current.cleanup();
  current = null;
}
