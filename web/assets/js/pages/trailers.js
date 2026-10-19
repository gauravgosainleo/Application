// GTA VI trailers (YouTube embeds or direct video links).
import { api } from '../core/api.js';
import { esc, $, icon, fmtDate, openModal, youtubeEmbed, videoPlayer, skeletonCards, errorState, emptyState, asset, shareLink } from '../core/ui.js';

export async function render({ el }) {
  el.innerHTML = `
    <div class="page-head"><div><h2>${icon('film')} Trailers</h2><p class="muted">Every official Grand Theft Auto VI trailer in one place.</p></div></div>
    <div class="trailers-body">${skeletonCards(4)}</div>`;
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
  const [first, ...rest] = list;
  const thumb = (t) => (t.thumbnail ? asset(t.thumbnail) : '');
  body.innerHTML = `
    <button class="trailer-hero" data-id="${first.id}">
      <img src="${esc(thumb(first).replace('hqdefault', 'maxresdefault'))}" alt="" onerror="this.onerror=null;this.src='${esc(thumb(first))}'">
      <span class="trailer-hero-shade"></span>
      <span class="play-badge xl">${icon('play')}</span>
      <span class="trailer-hero-text">
        ${first.is_featured ? `<span class="tag tag-hot">${icon('star')} Featured</span>` : ''}
        <strong>${esc(first.title)}</strong>
        <small>${first.released_on ? esc(fmtDate(first.released_on, { day: 'numeric', month: 'long', year: 'numeric' })) : ''}</small>
        ${first.description ? `<p>${esc(first.description)}</p>` : ''}
      </span>
    </button>
    ${rest.length ? `<div class="grid-cards trailers-grid">${rest.map((t) => `
      <button class="card trailer-card" data-id="${t.id}">
        <span class="tc-thumb"><img src="${esc(thumb(t))}" alt="" loading="lazy"><span class="play-badge">${icon('play')}</span></span>
        <span class="tc-body"><strong>${esc(t.title)}</strong><small class="muted">${t.released_on ? esc(fmtDate(t.released_on)) : ''}</small>${t.description ? `<span class="muted small">${esc(t.description)}</span>` : ''}</span>
      </button>`).join('')}</div>` : ''}`;

  body.addEventListener('click', (e) => {
    const card = e.target.closest('[data-id]');
    if (!card) return;
    const t = list.find((x) => String(x.id) === card.dataset.id);
    const m = openModal({
      title: t.title,
      size: 'xl',
      className: 'video-modal',
      body: (t.youtube_id ? youtubeEmbed(t.youtube_id) : videoPlayer(t.video_url)) +
        `<div class="video-meta"><span class="muted">${t.released_on ? esc(fmtDate(t.released_on, { day: 'numeric', month: 'long', year: 'numeric' })) : ''}</span>
         <div class="row gap">${t.youtube_id ? `<a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="https://www.youtube.com/watch?v=${esc(t.youtube_id)}">${icon('external')} YouTube</a>` : ''}
         <button class="btn btn-ghost btn-sm" data-share>${icon('share')} Share</button></div></div>`,
    });
    const s = m.el.querySelector('[data-share]');
    if (s) s.addEventListener('click', () => shareLink(t.title, t.youtube_id ? `https://www.youtube.com/watch?v=${t.youtube_id}` : asset(t.video_url)));
  });
}
