// News & articles: featured story + card grid, topic chips, search, and the article view (always credits the source).
import { api } from '../core/api.js';
import { esc, $, $$, icon, timeAgo, fmtDateTime, richText, debounce, errorState, emptyState, shareLink, appLink, setBusy } from '../core/ui.js';
import { setQuery } from '../core/router.js';
import { glare, media, newsCard, pageHead } from '../core/gvi-ui.js';

const TOPICS = [['', 'All'], ['official', 'Official'], ['analysis', 'Analysis'], ['community', 'Community'], ['rumours', 'Rumours']];

export async function render(ctx) {
  if (ctx.params.id) return renderArticle(ctx);
  return renderList(ctx);
}

function featuredCard(n) {
  return `<a class="gvi-news-featured" href="#/news/${n.id}" data-tilt="card">
    <span class="nf-media">${media(n.image_url, n.source_name, n.id, 'big')}<span class="nf-flag">FEATURED</span></span>
    <span class="nf-body">
      <span class="nf-meta"><span class="gvi-src">${esc(n.source_name)}</span><small>${timeAgo(n.published_at)}</small></span>
      <strong>${esc(n.title)}</strong>
      ${n.summary ? `<span class="nf-lead">${esc(n.summary.length > 220 ? n.summary.slice(0, 217) + '…' : n.summary)}</span>` : ''}
      <span class="nf-read">Read article${icon('chevronRight')}</span>
    </span>
    ${glare}
  </a>`;
}

function skeleton(n = 6) {
  return `<div class="gvi-news-cards">${Array.from({ length: n }, () => '<div class="gvi-card skeleton-card"><div class="sk sk-img"></div><div class="sk sk-line"></div><div class="sk sk-line short"></div></div>').join('')}</div>`;
}

async function renderList({ el, query }) {
  let page = 1;
  let q = query.q || '';
  let topic = TOPICS.some(([k]) => k === query.topic) ? query.topic : '';
  el.innerHTML = `
    ${pageHead('news', 'News & Articles', 'Pulled from trusted outlets and approved by our admins — every story credits its source. Tap a card to read.',
      `<div class="gvi-chip-row">${TOPICS.map(([k, l]) => `<button class="gvi-chip ${k === topic ? 'is-active' : ''}" data-topic="${k}">${l}</button>`).join('')}</div>`)}
    <div class="gvi-search-inline">${icon('search')}<input type="search" class="gvi-input" placeholder="Search news…" value="${esc(q)}" aria-label="Search news"></div>
    <div class="news-results">${skeleton()}</div>
    <div class="load-more-wrap"></div>`;
  const results = $('.news-results', el);
  const more = $('.load-more-wrap', el);

  async function load(reset) {
    if (reset) {
      page = 1;
      results.innerHTML = skeleton();
    }
    try {
      const data = await api.get('news/list', { page, q, topic });
      if (reset) results.innerHTML = '';
      if (!data.items.length && page === 1) {
        results.innerHTML = emptyState('news', q || topic ? 'No matching news' : 'No news yet', q || topic ? 'Try another filter or search.' : 'Admins will publish the latest GTA VI news here.');
      } else {
        let items = data.items;
        let html = '';
        if (page === 1 && !q) {
          html += featuredCard(items[0]);
          items = items.slice(1);
        }
        if (items.length) html += `<div class="gvi-news-cards">${items.map((n) => newsCard(n)).join('')}</div>`;
        results.insertAdjacentHTML('beforeend', html);
      }
      more.innerHTML = data.has_more ? `<button class="gvi-btn ghost">${icon('refresh')}Load more</button>` : '';
      const btn = $('button', more);
      if (btn) btn.addEventListener('click', () => { setBusy(btn, true); page++; load(false); });
    } catch (err) {
      results.innerHTML = '';
      results.appendChild(errorState(err, () => load(true)));
    }
  }
  const input = $('.gvi-search-inline input', el);
  input.addEventListener('input', debounce(() => {
    q = input.value.trim();
    setQuery({ q });
    load(true);
  }, 350));
  el.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-topic]');
    if (!chip) return;
    topic = chip.dataset.topic;
    $$('[data-topic]', el).forEach((c) => c.classList.toggle('is-active', c === chip));
    setQuery({ topic });
    load(true);
  });
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
    <article class="gvi-article">
      <a class="gvi-back" href="#/news">${icon('chevronLeft')}All news</a>
      <div class="gvi-article-cover">${media(a.image_url, a.source_name, a.id, 'big')}</div>
      <div class="gvi-article-card gvi-panel">
        <div class="gvi-article-meta"><span class="gvi-src">${icon('link')}${esc(a.source_name)}</span><span>${fmtDateTime(a.published_at)}</span><span>${icon('eye')}${a.views}</span></div>
        <h1 class="gvi-article-title">${esc(a.title)}</h1>
        ${a.summary ? `<p class="gvi-article-lead">${esc(a.summary)}</p>` : ''}
        <div class="gvi-article-body prose">${richText(a.content || '')}</div>
        <div class="gvi-source-box">
          <div class="sb-text">${icon('info')}<div><strong>Source: ${esc(a.source_name)}</strong><small>${a.origin === 'auto' ? 'Found automatically from a trusted feed and approved by our admins.' : 'Published by the G VI Fan Hub team.'}</small></div></div>
          ${a.source_url ? `<a class="gvi-btn" href="${esc(a.source_url)}" target="_blank" rel="noopener noreferrer">Read on ${esc(a.source_name)}${icon('external')}</a>` : ''}
        </div>
        <div class="gvi-article-actions">
          <button class="gvi-btn ghost" data-act="share">${icon('share')}Share</button>
          <a class="gvi-btn ghost" href="#/discussions?new=1&title=${encodeURIComponent(a.title)}">${icon('discuss')}Discuss this</a>
        </div>
      </div>
    </article>
    ${data.related.length ? `<section class="gvi-related"><h2 class="gvi-section-title">${icon('news')}More news</h2><div class="gvi-news-cards">${data.related.map((n) => newsCard(n)).join('')}</div></section>` : ''}`;
  $('[data-act="share"]', el).addEventListener('click', () => shareLink(a.title, appLink('#/news/' + a.id)));
}

