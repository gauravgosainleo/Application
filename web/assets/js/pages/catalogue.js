// Catalogue: vehicles, weapons, equipment ... with stats and favourites.
import { api } from '../core/api.js';
import { esc, $, $$, icon, asset, fmtMoney, openModal, toastError, debounce, emptyState, errorState, loader, setBusy, toast } from '../core/ui.js';
import { setQuery } from '../core/router.js';

const STATUS_CLASS = { Confirmed: 'ok', 'Spotted in trailer': 'hot', Expected: 'info', Rumored: 'warn', Leaked: 'warn' };

function itemCard(i) {
  return `<div class="card item-card" data-id="${i.id}" tabindex="0">
    <div class="ic-img">
      ${i.image ? `<img src="${esc(asset(i.image))}" alt="" loading="lazy" onerror="this.remove()">` : ''}
      <span class="ic-fallback">${esc(i.category_icon || '🎮')}</span>
      <span class="tag tag-${STATUS_CLASS[i.status] || 'info'} ic-status">${esc(i.status)}</span>
      <button class="fav-btn ${i.favorite ? 'on' : ''}" data-fav="${i.id}" aria-label="Favourite">${icon('heart')}</button>
    </div>
    <div class="ic-body">
      <strong>${esc(i.name)}</strong>
      <span class="muted small">${esc([i.subtype, i.manufacturer].filter(Boolean).join(' · ') || i.category || '')}</span>
      <span class="ic-price">${i.price !== null ? fmtMoney(i.price) : '<span class="muted">Price TBA</span>'}</span>
    </div>
  </div>`;
}

function statBars(stats) {
  if (!stats || !stats.length) return '';
  return `<div class="stat-bars">${stats.map((s) => typeof s.value === 'number'
    ? `<div class="stat"><div class="row-between small"><span>${esc(s.label)}</span><b>${Math.round(s.value)}</b></div><div class="stat-track"><span style="width:${Math.max(0, Math.min(100, s.value))}%"></span></div></div>`
    : `<div class="stat kv"><span>${esc(s.label)}</span><b>${esc(s.value)}</b></div>`).join('')}</div>`;
}

export async function render({ el, query }) {
  let categories = [];
  let category = query.category || '';
  let q = query.q || '';
  let sort = query.sort || 'name';
  let favorites = query.fav === '1';
  let page = 1;
  let items = [];

  el.innerHTML = `
    <div class="page-head">
      <div><h2>${icon('grid')} Catalogue</h2><p class="muted">Vehicles, weapons and equipment of Leonida. Tap the ♥ to build your wishlist.</p></div>
    </div>
    <div class="cat-tabs chip-row scroll"></div>
    <div class="filter-bar">
      <div class="search-input">${icon('search')}<input type="search" placeholder="Search the catalogue…" value="${esc(q)}"></div>
      <div class="filter-right">
        <button class="chip fav-toggle ${favorites ? 'active' : ''}">${icon('heart')} My wishlist</button>
        <select class="input select small sort">${[['name', 'A–Z'], ['newest', 'Newest'], ['price_high', 'Price: high → low'], ['price_low', 'Price: low → high']].map(([v, l]) => `<option value="${v}" ${v === sort ? 'selected' : ''}>${l}</option>`).join('')}</select>
      </div>
    </div>
    <div class="items-wrap">${loader()}</div>
    <div class="load-more-wrap"></div>`;
  const wrap = $('.items-wrap', el);
  const more = $('.load-more-wrap', el);

  try {
    categories = await api.get('catalogue/categories');
  } catch (err) {
    wrap.innerHTML = '';
    wrap.appendChild(errorState(err));
    return;
  }
  const total = categories.reduce((s, c) => s + c.items, 0);
  $('.cat-tabs', el).innerHTML = `<button class="chip ${!category ? 'active' : ''}" data-cat="">All <b>${total}</b></button>` +
    categories.map((c) => `<button class="chip ${c.slug === category ? 'active' : ''}" data-cat="${esc(c.slug)}">${esc(c.icon || '')} ${esc(c.name)} <b>${c.items}</b></button>`).join('');

  async function load(reset) {
    if (reset) {
      page = 1;
      items = [];
      wrap.innerHTML = loader();
    }
    try {
      const data = await api.get('catalogue/items', { category, q, sort, page, favorites: favorites ? 1 : '' });
      items = items.concat(data.items);
      if (reset) wrap.innerHTML = '<div class="items-grid"></div>';
      if (!items.length) {
        wrap.innerHTML = emptyState(favorites ? 'heart' : 'grid', favorites ? 'Your wishlist is empty' : 'Nothing here yet', favorites ? 'Tap the heart on any item to save it.' : 'Admins will add items to this category soon.');
      } else {
        $('.items-grid', wrap).insertAdjacentHTML('beforeend', data.items.map(itemCard).join(''));
      }
      more.innerHTML = data.has_more ? '<button class="btn btn-ghost">Load more</button>' : '';
      const b = $('button', more);
      if (b) b.addEventListener('click', () => { setBusy(b, true); page++; load(false); });
    } catch (err) {
      wrap.innerHTML = '';
      wrap.appendChild(errorState(err, () => load(true)));
    }
  }

  async function toggleFav(id, btn) {
    try {
      const r = await api.post('catalogue/favorite', { item_id: id });
      $$(`[data-fav="${id}"]`, document).forEach((b) => b.classList.toggle('on', r.favorite));
      const it = items.find((x) => x.id === id);
      if (it) it.favorite = r.favorite;
      toast(r.favorite ? 'Added to your wishlist' : 'Removed from your wishlist', 'success', 1800);
      if (favorites && !r.favorite) load(true);
    } catch (err) {
      toastError(err);
    }
  }

  async function openItem(id) {
    const m = openModal({ size: 'lg', className: 'item-modal', body: loader() });
    try {
      const { item, related } = await api.get('catalogue/item', { id });
      m.body.innerHTML = `
        <div class="item-detail">
          <div class="id-img">${item.image ? `<img src="${esc(asset(item.image))}" alt="">` : `<span class="ic-fallback big">${esc(item.category_icon || '🎮')}</span>`}</div>
          <div class="id-info">
            <div class="row gap wrap"><span class="tag">${esc(item.category_icon || '')} ${esc(item.category)}</span><span class="tag tag-${STATUS_CLASS[item.status] || 'info'}">${esc(item.status)}</span></div>
            <h2>${esc(item.name)}</h2>
            <p class="muted">${esc([item.subtype, item.manufacturer].filter(Boolean).join(' · '))}</p>
            <div class="id-price">${item.price !== null ? fmtMoney(item.price) : 'Price TBA'}</div>
            ${statBars(item.stats)}
            <div class="prose">${(item.description || '').split(/\n{2,}/).map((p) => `<p>${esc(p)}</p>`).join('')}</div>
            <div class="row gap"><button class="btn ${item.favorite ? 'btn-liked' : 'btn-ghost'}" data-fav="${item.id}">${icon('heart')} ${item.favorite ? 'In wishlist' : 'Add to wishlist'}</button><span class="muted small">${item.favorites_count} fans saved this</span></div>
          </div>
        </div>
        ${related.length ? `<h3 class="related-title">More in ${esc(item.category)}</h3><div class="items-grid small">${related.map(itemCard).join('')}</div>` : ''}`;
      m.body.addEventListener('click', (e) => {
        const fav = e.target.closest('[data-fav]');
        if (fav) {
          e.stopPropagation();
          const fid = Number(fav.dataset.fav);
          toggleFav(fid, fav).then(() => {
            if (fid === item.id) {
              item.favorite = !item.favorite;
              fav.className = `btn ${item.favorite ? 'btn-liked' : 'btn-ghost'}`;
              fav.innerHTML = `${icon('heart')} ${item.favorite ? 'In wishlist' : 'Add to wishlist'}`;
            }
          });
          return;
        }
        const card = e.target.closest('.item-card');
        if (card) { m.close(); openItem(Number(card.dataset.id)); }
      });
    } catch (err) {
      m.body.innerHTML = '';
      m.body.appendChild(errorState(err));
    }
  }

  el.addEventListener('click', (e) => {
    const fav = e.target.closest('.items-wrap [data-fav]');
    if (fav) {
      e.stopPropagation();
      toggleFav(Number(fav.dataset.fav), fav);
      return;
    }
    const card = e.target.closest('.items-wrap .item-card');
    if (card) openItem(Number(card.dataset.id));
    const cat = e.target.closest('[data-cat]');
    if (cat) {
      category = cat.dataset.cat;
      $$('[data-cat]', el).forEach((x) => x.classList.toggle('active', x === cat));
      setQuery({ category });
      load(true);
    }
  });
  el.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.classList.contains('item-card')) openItem(Number(e.target.dataset.id));
  });
  $('.fav-toggle', el).addEventListener('click', (e) => {
    favorites = !favorites;
    e.currentTarget.classList.toggle('active', favorites);
    setQuery({ fav: favorites ? '1' : null });
    load(true);
  });
  $('.sort', el).addEventListener('change', (e) => { sort = e.target.value; setQuery({ sort }); load(true); });
  $('.filter-bar .search-input input', el).addEventListener('input', debounce((e) => { q = e.target.value.trim(); setQuery({ q }); load(true); }, 300));
  await load(true);
  if (query.item) openItem(Number(query.item));
}
