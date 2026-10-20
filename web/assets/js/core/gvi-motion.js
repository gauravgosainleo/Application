// G VI motion layer: pointer tilt (data-tilt), page-enter transition, reduced-motion switch.
//
//   initMotion();          // once, at boot
//   pageEnter(outlet);     // after every route change
//
// Markup contract:
//   data-tilt="card"   → tilts toward the pointer (±7° X, ±9° Y), exposes --mx/--my/--g for the glare layer (.gvi-glare child)
//   data-tilt="global" → rotates with the pointer's position in the viewport (logo tiles, dock core)
//   data-tilt="map"    → the 3D map plane; base rotateX(55deg) rotateZ(-10deg) ± pointer

const KEY = 'gvi.reducedMotion';
let px = 0, py = 0, card = null, raf = 0, started = false;

function saved() {
  try { return localStorage.getItem(KEY); } catch (e) { return null; }
}

export function isReduced() {
  const v = saved();
  if (v !== null) return v === '1';
  return matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function setReducedMotion(on) {
  try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) { /* private mode: applies for this visit only */ }
  document.documentElement.dataset.motion = on ? 'reduced' : 'full';
  if (on) resetAll();
}

function applyMotionAttr() {
  document.documentElement.dataset.motion = isReduced() ? 'reduced' : 'full';
}

export function initMotion() {
  applyMotionAttr();
  if (started) return;
  started = true;
  document.addEventListener('pointermove', onMove, { passive: true });
  document.addEventListener('pointerdown', onMove, { passive: true });
  document.addEventListener('pointerup', onUp, { passive: true });
}

function reduced() {
  return document.documentElement.dataset.motion === 'reduced';
}

function onMove(e) {
  px = (e.clientX / innerWidth - .5) * 2;
  py = (e.clientY / innerHeight - .5) * 2;
  const c = e.target && e.target.closest ? e.target.closest('[data-tilt="card"]') : null;
  if (card && card !== c) resetCard(card);
  card = c;
  if (c && !reduced()) {
    const r = c.getBoundingClientRect();
    const x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
    c.style.transform = `perspective(900px) rotateX(${((.5 - y) * 7).toFixed(2)}deg) rotateY(${((x - .5) * 9).toFixed(2)}deg) translateY(-2px)`;
    c.style.setProperty('--mx', (x * 100).toFixed(1) + '%');
    c.style.setProperty('--my', (y * 100).toFixed(1) + '%');
    c.style.setProperty('--g', '1');
  }
  if (!raf) raf = requestAnimationFrame(applyGlobal);
}

function onUp(e) {
  if (e.pointerType === 'touch' && card) { resetCard(card); card = null; }
}

function resetCard(c) {
  c.style.transform = '';
  c.style.setProperty('--g', '0');
}

function resetAll() {
  document.querySelectorAll('[data-tilt]').forEach((el) => { el.style.transform = ''; });
}

function applyGlobal() {
  raf = 0;
  if (reduced()) return;
  document.querySelectorAll('[data-tilt="global"]').forEach((el) => {
    el.style.transform = `rotateY(${(px * 16).toFixed(2)}deg) rotateX(${(-py * 12).toFixed(2)}deg)`;
  });
  document.querySelectorAll('[data-tilt="map"]').forEach((el) => {
    el.style.transform = `rotateX(${(55 - py * 4).toFixed(2)}deg) rotateZ(${(-10 + px * 4).toFixed(2)}deg)`;
  });
}

/** Fade + rise the freshly rendered page (500ms, cubic-bezier(.2,.8,.2,1)). */
export function pageEnter(el) {
  if (reduced() || !el || !el.animate) return;
  el.animate(
    [{ opacity: 0, transform: 'translateY(14px) scale(.985)' }, { opacity: 1, transform: 'none' }],
    { duration: 500, easing: 'cubic-bezier(.2,.8,.2,1)' }
  );
}
