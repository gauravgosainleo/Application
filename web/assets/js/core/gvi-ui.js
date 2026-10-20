// Shared building blocks for the G VI redesign (tilt cards, ring avatars, date blocks, tinted media).
import { esc, icon, asset, initials, timeAgo } from './ui.js';

/** Tint pairs laid over the hero art when an item has no picture. */
export const TINTS = [
  ['rgba(255,61,139,.55)', 'rgba(124,58,237,.55)'],
  ['rgba(25,211,197,.5)', 'rgba(59,130,246,.55)'],
  ['rgba(255,138,61,.55)', 'rgba(255,61,139,.5)'],
  ['rgba(139,92,246,.6)', 'rgba(236,72,153,.45)'],
  ['rgba(59,130,246,.5)', 'rgba(255,61,139,.45)'],
  ['rgba(255,61,139,.55)', 'rgba(255,194,77,.45)'],
];

export function tint(n) {
  const t = TINTS[Math.abs(Number(n) || 0) % TINTS.length];
  return `--ta:${t[0]};--tb:${t[1]}`;
}

/** Glare layer for data-tilt="card" elements. */
export const glare = '<span class="gvi-glare" aria-hidden="true"></span>';

const AVATAR_GRADS = [
  ['#ff3d8b', '#ff8a3d'], ['#8b5cf6', '#3b82f6'], ['#19d3c5', '#3b82f6'], ['#f59e0b', '#ef4444'],
  ['#22c55e', '#14b8a6'], ['#ec4899', '#8b5cf6'], ['#06b6d4', '#8b5cf6'], ['#f97316', '#ec4899'],
];

/** Background for an initials avatar (same palette as ui.avatar). */
export function avatarGrad(u) {
  const g = AVATAR_GRADS[(Number(u && u.id) || 0) % AVATAR_GRADS.length];
  return `linear-gradient(135deg,${g[0]},${g[1]})`;
}

/** Avatar with the animated conic ring (player strip, profile, status composer). */
export function ringAvatar(u, size = 48, { presence = true, ring = true } = {}) {
  const face = u.avatar
    ? `<img src="${esc(asset(u.avatar))}" alt="" loading="lazy">`
    : `<span class="ra-initials" style="background:${avatarGrad(u)}">${esc(initials(u.name))}</span>`;
  const p = u.presence === 'invisible' ? 'offline' : (u.presence || 'offline');
  return `<span class="gvi-ring-av${ring ? '' : ' no-ring'}" style="--s:${size}px">${face}${presence ? `<span class="pdot ${esc(p)}"></span>` : ''}</span>`;
}

/** 46×50 date block (Bebas day + coloured month). */
export function dateBlock(iso) {
  const d = new Date(iso);
  return `<span class="gvi-date-block"><b>${d.getDate()}</b><small>${esc(d.toLocaleString(undefined, { month: 'short' }))}</small></span>`;
}

/** Section heading with icon and optional "see all" link. */
export function sectionHead(ic, title, href = '', linkText = '') {
  return `<div class="gvi-section-head"><h2 class="gvi-section-title">${icon(ic)}${esc(title)}</h2>${href ? `<a class="gvi-see-all" href="${href}">${esc(linkText)}${icon('chevronRight')}</a>` : ''}</div>`;
}

/** Page heading: icon + 26px title + sub line, with optional right side. */
export function pageHead(ic, title, sub = '', right = '') {
  return `<div class="gvi-page-head"><div><h2 class="gvi-page-title">${icon(ic)}${esc(title)}</h2>${sub ? `<p class="gvi-page-sub">${sub}</p>` : ''}</div>${right ? `<div class="gvi-page-head-right">${right}</div>` : ''}</div>`;
}

/** Tinted media block: the real image when there is one, the hero art with a source wordmark otherwise. */
export function media(imageUrl, label, seed, cls = '') {
  return `<span class="gvi-media ${cls}" style="${tint(seed)}">${imageUrl ? `<img src="${esc(asset(imageUrl))}" alt="" loading="lazy" onerror="this.remove()">` : ''}<b>${esc(label || '')}</b></span>`;
}

/** News card (home + news page). The home layout puts the time under the title. */
export function newsCard(n, layout = 'page') {
  const time = `<small>${timeAgo(n.published_at)}</small>`;
  return `<a class="gvi-card gvi-news-card" href="#/news/${n.id}" data-tilt="card">
    ${media(n.image_url, n.source_name, n.id)}
    <span class="gvi-card-body">
      <span class="gvi-card-meta"><span class="gvi-src">${esc(n.source_name)}</span>${layout === 'page' ? time : ''}</span>
      <strong>${esc(n.title)}</strong>
      ${layout === 'page' ? '' : time}
    </span>
    ${glare}
  </a>`;
}
