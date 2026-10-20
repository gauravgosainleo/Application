// Motion background: gradient sky, three drifting light blobs, floor glow, light sweep,
// two wireframe cubes, two rings, film grain, vignette. Pure CSS animation — no canvas, no RAF.
//
//   import { mountBackground } from './core/gvi-background.js';
//   mountBackground();   // once, before the shell renders. Adds body.gvi and <div class="gvi-scene"> as first child of <body>.

export function mountBackground() {
  if (document.querySelector('.gvi-scene')) return;
  document.body.classList.add('gvi');
  const cube = (size, left, top, spin, delay, color) => {
    const z = `translateZ(${size / 2}px)`;
    const faces = [z, `rotateY(180deg) ${z}`, `rotateY(90deg) ${z}`, `rotateY(-90deg) ${z}`, `rotateX(90deg) ${z}`, `rotateX(-90deg) ${z}`]
      .map((t) => `<i class="gvi-face" style="--c:${color};transform:${t}"></i>`).join('');
    return `<div class="gvi-cube" style="left:${left};top:${top};width:${size}px;height:${size}px;--spin:${spin}s;--float:${Math.round(spin * .4)}s;--delay:${delay}s"><div class="gvi-cube-in">${faces}</div></div>`;
  };
  const ring = (size, left, top, spin, color) =>
    `<div class="gvi-ring" style="left:${left};top:${top};width:${size}px;height:${size}px;--spin:${spin}s;--c:${color}"></div>`;
  const P = (v, pct) => `color-mix(in srgb, var(${v}) ${pct}%, transparent)`;

  const scene = document.createElement('div');
  scene.className = 'gvi-scene';
  scene.setAttribute('aria-hidden', 'true');
  scene.innerHTML = `
    <div class="gvi-sky"></div>
    <div class="gvi-blob gvi-blob-1"></div>
    <div class="gvi-blob gvi-blob-2"></div>
    <div class="gvi-blob gvi-blob-3"></div>
    <div class="gvi-floor"></div>
    <div class="gvi-sweep"></div>
    ${cube(150, '7vw', '16vh', 64, 0, P('--primary', 75))}
    ${cube(90, '80vw', '64vh', 50, 3, P('--teal', 75))}
    ${ring(240, '68vw', '6vh', 72, P('--accent', 75))}
    ${ring(130, '12vw', '72vh', 56, P('--primary-2', 75))}
    <div class="gvi-noise"></div>
    <div class="gvi-vignette"></div>`;
  document.body.prepend(scene);
}

/** Three-layer light rig for hero panels (pulsing glow, light sweep, scanlines). Append inside a position:relative hero. */
export function heroFx() {
  return `<div class="gvi-fx" aria-hidden="true"><div class="gvi-fx-glow"></div><div class="gvi-fx-sweep"></div><div class="gvi-fx-scan"></div></div>`;
}
