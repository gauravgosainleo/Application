// News & articles: list + article view (every article shows its source).
import { api } from '../core/api.js';
import { esc, $, icon, timeAgo, fmtDateTime, asset, richText, debounce, skeletonCards, errorState, emptyState, shareLink, appLink, setBusy } from '../core/ui.js';
import { setQuery } from '../core/router.js';

export async function render(ctx) {
  if (ctx.params.id) return renderArticle(ctx);
  return renderList(ctx);
}

function newsCard(n) {
  return `<a class="card news-card" href="#/news/${n.id}">
    <div class="nc-img">
      ${n.image_url ? `<img src="${esc(asset(n.image_url))}" alt="" loading="lazy" onerror="this.remove()">` : ''}
      <span class="nc-fallback fb-${n.id % 4}"><b>${esc(n.source_name)}</b></span>
      ${n.is_featured ? `<span class="tag tag-hot">${icon('fire')} Featured</span>` : ''}
    </div>
    <div class="nc-body">
      <div class="nc-meta"><span class="source-chip">${icon('link')} ${esc(n.source_name)}</span><span class="muted small">${timeAgo(n.published_at)}</span></div>
      <h3>${esc(n.title)}</h3>
      ${n.summary ? `<p class="muted">${esc(n.summary.length > 160 ? n.summary.slice(0, 157) + '…' : n.summary)}</p>` : ''}
    </div>
  </a>`;
}

async function renderList({ el, query }) {
  let page = 1;
  let q = query.q || '';
  el.innerHTML = `
    <div class="page-head">
      <div><h2>${icon('news')} News & Articles</h2><p class="muted">Hand-picked GTA VI stories from trusted sources — always credited.</p></div>
      <div class="search-input">${icon('search')}<input type="search" placeholder="Search news…" value="${esc(q)}"></div>
    </div>
    <div class="news-grid">${skeletonCards(6)}</div>
    <div class="load-more-wrap"></div>`;
  const grid = $('.news-grid', el);
  const more = $('.load-more-wrap', el);

  async function load(reset) {
    if (reset) {
      page = 1;
      grid.innerHTML = skeletonCards(6);
    }
    try {
      const data = await api.get('news/list', { page, q });
      if (reset) grid.innerHTML = '';
      if (!data.items.length && page === 1) {
        grid.innerHTML = emptyState('news', q ? 'No matching news' : 'No news yet', q ? 'Try another search.' : 'Admins will publish the latest GTA VI news here.');
      } else {
        grid.insertAdjacentHTML('beforeend', `<div class="grid-cards">${data.items.map(newsCard).join('')}</div>`);
      }
      more.innerHTML = data.has_more ? `<button class="btn btn-ghost">${icon('refresh')} Load more</button>` : '';
      const btn = $('button', more);
      if (btn) btn.addEventListener('click', () => { setBusy(btn, true); page++; load(false); });
    } catch (err) {
      grid.innerHTML = '';
      grid.appendChild(errorState(err, () => load(true)));
    }
  }
  const input = $('.search-input input', el);
  input.addEventListener('input', debounce(() => {
    q = input.value.trim();
    setQuery({ q });
    load(true);
  }, 350));
  load(true);
}

async function renderArticle({ el, params }) {
  el.innerHTML = `<div class="loader"><span class="spinner lg"></span></div>`;
  let data;
  try {
    data = await api.get('news/get', { id: params.id });
  } catch (err) {
    el.innerHTML = '';
    el.appendChild(errorState(err));
    return;
  }
  const a = data.article;
  el.innerHTML = `
    <article class="article">
      <a class="back-link" href="#/news">${icon('chevronLeft')} All news</a>
      ${a.image_url ? `<div class="article-cover"><img src="${esc(asset(a.image_url))}" alt="" onerror="this.parentElement.remove()"></div>` : ''}
      <div class="article-meta"><span class="source-chip">${icon('link')} ${esc(a.source_name)}</span><span class="muted">${fmtDateTime(a.published_at)}</span><span class="muted">${icon('eye')} ${a.views}</span></div>
      <h1 class="article-title">${esc(a.title)}</h1>
      ${a.summary ? `<p class="article-lead">${esc(a.summary)}</p>` : ''}
      <div class="article-body prose">${richText(a.content || '')}</div>
      <div class="source-box">
        <div>${icon('info')}<div><strong>Source: ${esc(a.source_name)}</strong><span class="muted small">${a.origin === 'auto' ? 'Found automatically from a trusted feed and approved by our admins.' : 'Published by the G VI Fan Hub team.'}</span></div></div>
        ${a.source_url ? `<a class="btn btn-primary" href="${esc(a.source_url)}" target="_blank" rel="noopener noreferrer">Read on ${esc(a.source_name)} ${icon('external')}</a>` : ''}
      </div>
      <div class="article-actions">
        <button class="btn btn-ghost" data-act="share">${icon('share')} Share</button>
        <a class="btn btn-ghost" href="#/discussions?new=1&title=${encodeURIComponent(a.title)}">${icon('discuss')} Discuss this</a>
      </div>
    </article>
    ${data.related.length ? `<section class="section"><div class="section-head"><h2>More news</h2></div><div class="grid-cards">${data.related.map(newsCard).join('')}</div></section>` : ''}`;
  $('[data-act="share"]', el).addEventListener('click', () => shareLink(a.title, appLink('#/news/' + a.id)));
}
