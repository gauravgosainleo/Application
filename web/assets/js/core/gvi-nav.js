// Floating glass dock + radial "weapon wheel" navigation.
//
//   const dock = mountDock({ items: NAV, current: () => parseHash().path, onGo: (key) => navigate('#/' + key), icon, iconBody });
//   dock.destroy();   // on logout
//
// NAV items: { key, label, short?, icon, desc }. 12 items fill the wheel (30° each); the arc is 360 / n.
// The dock shows home · news · [core] · map · friends. The core button opens the wheel.

export const LOGO_PATHS = '<g fill="none" stroke="url(#ID)" stroke-width="SW" stroke-linecap="round" stroke-linejoin="round"><path d="M205.3,210.7 A64,64 0 1,0 224,256 L174,256"/><path d="M266,192 L312,320 L358,192"/><path d="M416,192 L416,320"/></g><rect x="132" y="372" width="248" height="12" rx="6" fill="url(#ID)"/>';

let logoSeq = 0;
/** The G VI strokes with a theme gradient (recolours with the theme). */
export function logoSvg(strokeWidth = 44, attrs = '') {
  const id = 'gviLg' + (++logoSeq);
  return `<svg viewBox="0 0 512 512" aria-hidden="true" ${attrs}><defs><linearGradient id="${id}" x1="60" y1="140" x2="452" y2="380" gradientUnits="userSpaceOnUse"><stop offset="0" style="stop-color:var(--primary)"/><stop offset="1" style="stop-color:var(--primary-2)"/></linearGradient></defs>${LOGO_PATHS.replace(/ID/g, id).replace('SW', strokeWidth)}</svg>`;
}

let cfg = null, dockEl = null, wheelEl = null;

const onKey = (e) => { if (e.key === 'Escape') closeWheel(); };
const onHash = () => syncDock();

export function mountDock(options) {
  destroyDock();
  cfg = options;
  const { items, icon } = cfg;
  const btn = (k) => {
    const n = items.find((x) => x.key === k);
    return `<button class="gvi-dock-btn" data-key="${k}">${icon(n.icon)}<span>${n.short || n.label}</span>${n.badge ? '<i class="gvi-dock-badge" data-badge="social" hidden></i>' : ''}</button>`;
  };
  dockEl = document.createElement('nav');
  dockEl.className = 'gvi-dock';
  dockEl.setAttribute('aria-label', 'Quick navigation');
  dockEl.innerHTML = `${btn('home')}${btn('news')}
    <button class="gvi-dock-core" aria-label="Open navigation wheel"><span class="halo"></span><span data-tilt="global" style="position:absolute;inset:0;display:block"><span class="body"></span><span class="rim"></span>${logoSvg(46)}</span></button>
    ${btn('map')}${btn('friends')}`;
  dockEl.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.classList.contains('gvi-dock-core')) return wheelEl ? closeWheel() : openWheel();
    cfg.onGo(b.dataset.key);
  });
  document.body.appendChild(dockEl);
  syncDock();
  addEventListener('hashchange', onHash);
  addEventListener('keydown', onKey);
  return { el: dockEl, destroy: destroyDock };
}

export function destroyDock() {
  closeWheel();
  if (dockEl) dockEl.remove();
  dockEl = null;
  removeEventListener('hashchange', onHash);
  removeEventListener('keydown', onKey);
}

export function syncDock() {
  if (!dockEl || !cfg) return;
  const cur = cfg.current();
  dockEl.querySelectorAll('.gvi-dock-btn').forEach((b) => b.classList.toggle('is-active', cur.startsWith('/' + b.dataset.key)));
}

/* ---- wheel geometry: ring between r=94 and r=188 on a 400×400 viewBox, 1.3° gap each side ---- */
function segment(i, n) {
  const c = 200, r1 = 94, r2 = 188, step = 360 / n, rad = (a) => (a * Math.PI) / 180;
  const a0 = rad(-90 + i * step + 1.3), a1 = rad(-90 + (i + 1) * step - 1.3), am = rad(-90 + i * step + step / 2);
  const P = (r, a) => (c + r * Math.cos(a)).toFixed(1) + ' ' + (c + r * Math.sin(a)).toFixed(1);
  const x = c + 141 * Math.cos(am), y = c + 141 * Math.sin(am);
  return { d: `M${P(r2, a0)}A${r2} ${r2} 0 0 1 ${P(r2, a1)}L${P(r1, a1)}A${r1} ${r1} 0 0 0 ${P(r1, a0)}Z`, tf: `translate(${(x - 15).toFixed(1)} ${(y - 15).toFixed(1)}) scale(1.25)` };
}

const DEFAULT_LABEL = '<strong>G VI</strong><small>Pick a destination · Esc to close</small>';

export function openWheel() {
  if (wheelEl || !cfg) return;
  const { items, iconBody, current } = cfg;
  const cur = current();
  const segs = items.map((n, i) => {
    const g = segment(i, items.length);
    return `<g class="seg${cur.startsWith('/' + n.key) ? ' is-active' : ''}" data-key="${n.key}" data-i="${i}" role="link" aria-label="${n.label}"><path class="bg" d="${g.d}"/><g class="ic" transform="${g.tf}">${iconBody(n.icon)}</g></g>`;
  }).join('');
  wheelEl = document.createElement('div');
  wheelEl.className = 'gvi-wheel-backdrop';
  wheelEl.innerHTML = `
    <button class="gvi-close" aria-label="Close"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <div class="gvi-wheel">
      <svg viewBox="0 0 400 400"><defs><radialGradient id="gviWheelGrad" cx="50%" cy="50%" r="60%"><stop offset="0" style="stop-color:var(--primary-2)" stop-opacity=".95"/><stop offset="1" style="stop-color:var(--primary)" stop-opacity=".9"/></radialGradient></defs>
        <circle cx="200" cy="200" r="90" fill="rgba(12,6,24,.92)" stroke="rgba(255,255,255,.14)" stroke-width="1.5"/>
        <circle cx="200" cy="200" r="196" fill="none" stroke="rgba(255,255,255,.08)"/>
        ${segs}
      </svg>
      <div class="gvi-wheel-center">${DEFAULT_LABEL}</div>
    </div>`;
  const center = wheelEl.querySelector('.gvi-wheel-center');
  const setLabel = (n) => {
    center.innerHTML = n ? `<strong>${n.label}</strong><small>${n.desc || ''}</small>` : DEFAULT_LABEL;
  };
  wheelEl.addEventListener('pointerover', (e) => {
    const s = e.target.closest('.seg');
    if (s) setLabel(items[+s.dataset.i]);
  });
  wheelEl.addEventListener('pointerout', (e) => {
    if (e.target.closest('.seg') && !(e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('.seg'))) setLabel(null);
  });
  wheelEl.addEventListener('click', (e) => {
    const s = e.target.closest('.seg');
    if (s) {
      closeWheel();
      cfg.onGo(s.dataset.key);
      return;
    }
    if (!e.target.closest('.gvi-wheel') || e.target.closest('.gvi-close')) closeWheel();
  });
  document.body.appendChild(wheelEl);
  dockEl?.querySelector('.gvi-dock-core')?.setAttribute('aria-expanded', 'true');
}

export function closeWheel() {
  if (wheelEl) {
    wheelEl.remove();
    wheelEl = null;
  }
  dockEl?.querySelector('.gvi-dock-core')?.setAttribute('aria-expanded', 'false');
}

export function isWheelOpen() {
  return !!wheelEl;
}
