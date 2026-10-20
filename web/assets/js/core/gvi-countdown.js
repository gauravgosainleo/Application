// Flip countdown: each digit group flips in (rotateX -90° → 0, 600ms) only when its value changes.
//
//   import { mountCountdown } from './core/gvi-countdown.js';
//   const stop = mountCountdown(el, new Date(2026, 10, 19), { size: 'default' | 'mini' | 'big', labels: ['Days','Hours','Minutes','Seconds'] });
//   // call stop() in the page's cleanup()

export function countdownParts(target, now = Date.now()) {
  let d = Math.max(0, target - now);
  const days = Math.floor(d / 864e5); d -= days * 864e5;
  const hours = Math.floor(d / 36e5); d -= hours * 36e5;
  const minutes = Math.floor(d / 6e4); d -= minutes * 6e4;
  return { days, hours, minutes, seconds: Math.floor(d / 1000) };
}

export function mountCountdown(el, target, { size = 'default', labels = ['Days', 'Hours', 'Minutes', 'Seconds'] } = {}) {
  const keys = ['days', 'hours', 'minutes', 'seconds'];
  el.className = 'gvi-cd' + (size === 'big' ? ' big' : '');
  el.innerHTML = keys.map((k, i) => `<div class="gvi-cd-cell${size === 'mini' ? ' mini' : ''}"><div class="gvi-cd-digit" data-k="${k}"><span></span></div><div class="gvi-cd-label">${labels[i]}</div></div>`).join('');
  const digits = Object.fromEntries(keys.map((k) => [k, el.querySelector(`[data-k="${k}"] span`)]));
  if (size === 'mini') Object.values(digits).forEach((s) => s.style.setProperty('--size', '36px'));
  let last = {};
  const tick = () => {
    const p = countdownParts(target);
    keys.forEach((k) => {
      const v = k === 'days' ? String(p[k]) : String(p[k]).padStart(2, '0');
      if (v === last[k]) return;
      last[k] = v;
      const s = digits[k];
      s.textContent = v;
      s.classList.remove('flip'); void s.offsetWidth; s.classList.add('flip'); // restart animation
    });
  };
  tick();
  const t = setInterval(tick, 1000);
  return () => clearInterval(t);
}

/** Hype meter: % of the way from Trailer 1 (4 Dec 2023) to launch. */
export function hypePercent(target, start = new Date('2023-12-04T23:00:00Z').getTime(), now = Date.now()) {
  return Math.min(100, Math.max(0, ((now - start) / (target - start)) * 100));
}
