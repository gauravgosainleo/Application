// Catalogue "3D" model: 12 stacked SVG silhouettes spaced 3.4px apart on Z, spinning on Y (18s) inside a
// perspective stage with a glowing floor line and pulsing shadow. No WebGL. Swap `paths` for real
// silhouettes per item when available (or replace the stage with a <model-viewer>/three.js scene later).
//
//   import { mountModel } from './core/gvi-model.js';
//   mountModel(stageEl, 'vehicles' | 'weapons' | 'gear');

const PATHS = {
  vehicles: ['0 0 206 76', 'M14 58 L24 40 Q44 30 72 27 L98 14 Q132 10 152 25 L180 31 Q194 35 192 48 L188 60 L166 60 A13 13 0 0 1 140 60 L66 60 A13 13 0 0 1 40 60 L18 60 Z M53 60 a9 9 0 1 0 0.1 0 M153 60 a9 9 0 1 0 0.1 0'],
  weapons: ['0 0 184 120', 'M10 32 H162 Q174 32 174 44 V54 H74 L66 102 Q64 110 54 110 H34 Q26 110 28 100 L40 54 H10 Z M96 44 h60 v6 h-60 z'],
  gear: ['0 0 200 112', 'M30 84 A70 62 0 0 1 170 84 V100 H30 Z M44 78 Q100 60 156 78 V92 H44 Z'],
};

export function mountModel(el, kind = 'vehicles', custom) {
  const [vb, d] = custom || PATHS[kind] || PATHS.vehicles;
  const N = 12, layers = [];
  for (let i = 0; i < N; i++) {
    const z = (i - (N - 1) / 2) * 3.4, edge = i === 0 || i === N - 1, front = i === N - 1;
    layers.push(`<svg viewBox="${vb}" class="${edge ? 'edge' : ''}${front ? ' front' : ''}" style="transform:translateZ(${z}px)"><path d="${d}"/></svg>`);
  }
  el.classList.add('gvi-model');
  el.innerHTML = `
    <svg width="0" height="0" style="position:absolute"><defs><linearGradient id="gviModelGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" style="stop-color:var(--primary)"/><stop offset="1" style="stop-color:var(--primary-2)"/></linearGradient></defs></svg>
    <div class="shadow"></div><div class="floor"></div>
    <div class="rig">${layers.join('')}</div>`;
}
