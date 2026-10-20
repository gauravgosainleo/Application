// GTA VI trailers (YouTube embeds or direct video links): 21:9 hero for the newest one + grid of all trailers.
import { api } from '../core/api.js';
import { esc, $, icon, fmtDate, openModal, youtubeEmbed, videoPlayer, errorState, emptyState, asset, shareLink } from '../core/ui.js';
import { glare, tint } from '../core/gvi-ui.js';

export async function render({ el }) {
  el.innerHTML = `<div class="trailers-body"><div class="gvi-trailer-hero skeleton"><div class="sk" style="position:absolute;inset:0"></div></div></div>`;
  const body = $('.trailers-body', el);
  let list;
  try {
    list = await api.get('trailers/list');
  } catch (err) {
    body.innerHTML = '';
    body.appendChild(errorState(err, () => render({ el })));
    return;
  }
  if (!list.length) {
    body.innerHTML = emptyState('film', 'No trailers yet', 'Admins will add trailers here.');
    return;
  }
  const first = list[0];
  const thumb = (t) => (t.thumbnail ? asset(t.thumbnail) : '');
  const big = thumb(first).replace('hqdefault', 'maxresdefault');
  const recent = first.released_on && (Date.now() - new Date(first.released_on + 'T00:00:00')) < 45 * 86400000;
  const longDate = (t) => (t.released_on ? fmtDate(t.released_on, { day: 'numeric', month: 'short', year: 'numeric' }) : '');
  body.innerHTML = `
    <button class="gvi-trailer-hero" data-id="${first.id}" data-tilt="card">
      ${big ? `<img src="${esc(big)}" alt="" onerror="this.onerror=null;this.src='${esc(thumb(first))}'">` : ''}
      <span class="th-shade"></span>
      <span class="th-text">
        <span class="th-flag">${recent ? '<span class="dot"></span>NEW' : `${icon('star')}FEATURED`}</span>
        <strong>${esc(first.title)}</strong>
        <span class="th-meta">${esc([longDate(first), first.description].filter(Boolean).join(' · '))}</span>
      </span>
      <span class="gvi-play xl">${icon('play')}</span>
      ${glare}
    </button>
    <h2 class="gvi-section-title gvi-trailers-title">${icon('film')}All trailers</h2>
    <div class="gvi-trailer-grid">${list.map((t) => `
      <button class="gvi-card gvi-trailer-card" data-id="${t.id}" data-tilt="card">
        <span class="tc-media" style="${tint(t.id)}">${thumb(t) ? `<img src="${esc(thumb(t))}" alt="" loading="lazy">` : ''}<span class="tc-play">${icon('play')}</span></span>
        <span class="tc-body"><strong>${esc(t.title)}</strong><small>${esc(longDate(t))}</small></span>
        ${glare}
      </button>`).join('')}</div>`;

  body.addEventListener('click', (e) => {
    const card = e.target.closest('[data-id]');
    if (!card) return;
    const t = list.find((x) => String(x.id) === card.dataset.id);
    const m = openModal({
      size: 'xl',
      className: 'video-modal gvi-video-modal',
      body: (t.youtube_id ? youtubeEmbed(t.youtube_id) : videoPlayer(t.video_url)) +
        `<div class="video-meta"><div><strong>${esc(t.title)}</strong><small>${esc(longDate(t))}</small></div>
         <div class="row gap">${t.youtube_id ? `<a class="gvi-btn ghost sm" target="_blank" rel="noopener" href="https://www.youtube.com/watch?v=${esc(t.youtube_id)}">${icon('external')}YouTube</a>` : ''}
         <button class="gvi-btn ghost sm" data-share>${icon('share')}Share</button></div></div>`,
    });
    const s = m.el.querySelector('[data-share]');
    if (s) s.addEventListener('click', () => shareLink(t.title, t.youtube_id ? `https://www.youtube.com/watch?v=${t.youtube_id}` : asset(t.video_url)));
  });
}
