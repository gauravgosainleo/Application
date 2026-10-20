// Catalogue: 3D showcase of the selected item + grid of vehicles, weapons, equipment ... with stats and the garage (favourites).
import { api } from '../core/api.js';
import { esc, $, $$, icon, asset, fmtMoney, openModal, toastError, debounce, emptyState, errorState, loader, setBusy, toast } from '../core/ui.js';
import { setQuery } from '../core/router.js';
import { mountModel } from '../core/gvi-model.js';
import { glare, pageHead } from '../core/gvi-ui.js';

const STATUS_CLASS = { Confirmed: 'ok', 'Spotted in trailer': 'hot', Expected: 'info', Rumored: 'warn', Leaked: 'warn' };
const CAT_ICONS = { vehicles: 'car', 'boats-aircraft': 'compass', weapons: 'target', equipment: 'layers', characters: 'user' };
const MODEL_KIND = { vehicles: 'vehicles', 'boats-aircraft': 'boats', weapons: 'weapons' };
// Extra silhouettes for the placeholder model (viewBox, path).
const SHAPES = {
  boats: ['0 0 206 76', 'M8 44 H198 L182 66 Q178 70 170 70 H36 Q28 70 24 64 Z M58 44 V28 Q58 22 64 22 H116 L138 44 Z M150 44 V14 H156 V44 Z'],
  aircraft: ['0 0 206 80', 'M6 36 L78 34 L106 6 H122 L110 34 L172 33 Q200 34 200 40 Q200 46 172 47 L110 46 L122 74 H106 L78 46 L6 44 L2 54 H-2 L2 40 L-2 26 H2 Z'],
};

function modelFor(i) {
  const kind = MODEL_KIND[i.category_slug] || 'gear';
  if (kind !== 'boats') return [kind, undefined];
  return /heli|plane|jet|aircraft|chopper|aviat/i.test(`${i.name} ${i.subtype || ''}`) ? ['vehicles', SHAPES.aircraft] : ['vehicles', SHAPES.boats];
}
const SORTS = [['name', 'A–Z'], ['newest', 'Newest'], ['price_high', 'Price: high → low'], ['price_low', 'Price: low → high']];

const subline = (i) => [i.subtype, i.manufacturer].filter(Boolean).join(' · ') || i.category || '';

function itemCard(i, selected) {
  return `<div class="gvi-item-card item-card ${selected ? 'is-selected' : ''}" data-id="${i.id}" tabindex="0" role="button" data-tilt="card">
    <span class="ci-art">${i.image ? `<img src="${esc(asset(i.image))}" alt="" loading="lazy" onerror="this.remove()">` : ''}${icon(CAT_ICONS[i.category_slug] || 'grid')}</span>
    <strong>${esc(i.name)}</strong><small>${esc(subline(i))}</small>
    <button class="ci-fav ${i.favorite ? 'on' : ''}" data-fav="${i.id}" aria-label="${i.favorite ? 'Remove from garage' : 'Save to garage'}">${icon('bookmark')}</button>
    ${glare}
  </div>`;
}

function statBars(stats) {
  if (!stats || !stats.length) return '';
  return `<div class="stat-bars">${stats.map((s) => typeof s.value === 'number'
    ? `<div class="stat"><div class="row-between small"><span>${esc(s.label)}</span><b>${Math.round(s.value)}</b></div><div class="stat-track"><span style="width:${Math.max(0, Math.min(100, s.value))}%"></span></div></div>`
    : `<div class="stat kv"><span>${esc(s.label)}</span><b>${esc(s.value)}</b></div>`).join('')}</div>`;
}

function specCell(k, v, pct = null) {
  return `<div class="sc-spec"><small>${esc(k)}</small><strong>${v}</strong>${pct !== null ? `<span class="sc-bar"><i style="width:${Math.max(0, Math.min(100, pct))}%"></i></span>` : ''}</div>`;
}

function showcase(i) {
  const specs = [specCell('Price', i.price !== null ? esc(fmtMoney(i.price)) : 'TBA')];
  (i.stats || []).slice(0, 5).forEach((st) => {
    specs.push(typeof st.value === 'number' ? specCell(st.label, `${Math.round(st.value)}<em>/100</em>`, st.value) : specCell(st.label, esc(st.value)));
  });
  return `
    <div class="sc-gridbg"></div>
    <div class="sc-stage">${i.image ? `<img class="sc-img" src="${esc(asset(i.image))}" alt="">` : '<div class="sc-model"></div>'}
      <span class="sc-note">${i.image ? esc(i.category) : '3D preview · placeholder model'}</span></div>
    <div class="sc-info">
      <span class="sc-tag tag-${STATUS_CLASS[i.status] || 'info'}">${esc(i.status)}</span>
      <h3 class="sc-name">${esc(i.name)}</h3>
      <span class="sc-cls">${esc(subline(i))}</span>
      <div class="sc-specs">${specs.join('')}</div>
      <div class="sc-actions">
        <button class="gvi-btn ${i.favorite ? 'joined' : ''}" data-fav="${i.id}">${icon('bookmark')}${i.favorite ? 'In your garage' : 'Save to garage'}</button>
        <button class="gvi-btn ghost" data-details="${i.id}">${icon('info')}Details</button>
      </div>
    </div>`;
}

export async function render({ el, query }) {
  let categories = [];
  let category = query.category || '';
  let q = query.q || '';
  let sort = SORTS.some(([k]) => k === query.sort) ? query.sort : 'name';
  let favorites = query.fav === '1';
  let page = 1;
  let items = [];
  let selected = null;

  el.innerHTML = `
    ${pageHead('grid', 'Catalogue', 'Everything spotted so far. Models are placeholders until launch.', '<div class="gvi-seg cat-tabs"></div>')}
    <section class="gvi-showcase" hidden></section>
    <div class="gvi-cat-tools">
      <div class="gvi-search-inline small">${icon('search')}<input type="search" class="gvi-input" placeholder="Search the catalogue…" value="${esc(q)}" aria-label="Search the catalogue"></div>
      <button class="gvi-chip fav-toggle ${favorites ? 'is-active' : ''}">${icon('bookmark')}My garage</button>
      <select class="gvi-input gvi-select sort" aria-label="Sort">${SORTS.map(([v, l]) => `<option value="${v}" ${v === sort ? 'selected' : ''}>${l}</option>`).join('')}</select>
    </div>
    <div class="items-wrap">${loader()}</div>
    <div class="load-more-wrap"></div>`;
  const wrap = $('.items-wrap', el);
  const more = $('.load-more-wrap', el);
  const stage = $('.gvi-showcase', el);

  try {
    categories = await api.get('catalogue/categories');
  } catch (err) {
    wrap.innerHTML = '';
    wrap.appendChild(errorState(err));
    return;
  }
  const slugOf = Object.fromEntries(categories.map((c) => [c.id, c.slug]));
  const total = categories.reduce((s, c) => s + c.items, 0);
  $('.cat-tabs', el).innerHTML = `<button class="${!category ? 'is-active' : ''}" data-cat="">All <span class="count">${total}</span></button>` +
    categories.map((c) => `<button class="${c.slug === category ? 'is-active' : ''}" data-cat="${esc(c.slug)}">${esc(c.name)} <span class="count">${c.items}</span></button>`).join('');

  function select(item, scroll = false) {
    selected = item;
    $$('.gvi-item-card', wrap).forEach((c) => c.classList.toggle('is-selected', Number(c.dataset.id) === item.id));
    stage.hidden = false;
    stage.innerHTML = showcase(item);
    const model = $('.sc-model', stage);
    if (model) mountModel(model, ...modelFor(item));
    if (scroll) stage.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function load(reset) {
    if (reset) {
      page = 1;
      items = [];
      wrap.innerHTML = loader();
    }
    try {
      const data = await api.get('catalogue/items', { category, q, sort, page, favorites: favorites ? 1 : '' });
      data.items.forEach((i) => { i.category_slug = slugOf[i.category_id] || ''; });
      items = items.concat(data.items);
      if (reset) wrap.innerHTML = '<div class="gvi-items-grid"></div>';
      if (!items.length) {
        wrap.innerHTML = emptyState(favorites ? 'bookmark' : 'grid', favorites ? 'Your garage is empty' : 'Nothing here yet', favorites ? 'Tap the bookmark on any item to save it.' : 'Admins will add items to this category soon.');
        stage.hidden = true;
      } else {
        $('.gvi-items-grid', wrap).insertAdjacentHTML('beforeend', data.items.map((i) => itemCard(i, selected && selected.id === i.id)).join(''));
        if (reset || !selected) {
          select(items.find((i) => selected && i.id === selected.id)
            || (!category && items.find((i) => i.category_slug === 'vehicles')) || items[0]);
        }
      }
      more.innerHTML = data.has_more ? `<button class="gvi-btn ghost">${icon('refresh')}Load more</button>` : '';
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
      $$(`.ci-fav[data-fav="${id}"]`, document).forEach((b) => b.classList.toggle('on', r.favorite));
      const it = items.find((x) => x.id === id);
      if (it) it.favorite = r.favorite;
      if (selected && selected.id === id) {
        selected.favorite = r.favorite;
        const b = $(`.sc-actions [data-fav="${id}"]`, stage);
        if (b) {
          b.classList.toggle('joined', r.favorite);
          b.innerHTML = `${icon('bookmark')}${r.favorite ? 'In your garage' : 'Save to garage'}`;
        }
      }
      toast(r.favorite ? 'Saved to your garage' : 'Removed from your garage', 'success', 1800);
      if (favorites && !r.favorite) load(true);
      return r.favorite;
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
            <div class="row gap"><button class="gvi-btn ${item.favorite ? 'joined' : 'ghost'}" data-fav="${item.id}">${icon('bookmark')}${item.favorite ? 'In your garage' : 'Save to garage'}</button><span class="muted small">${item.favorites_count} fans saved this</span></div>
          </div>
        </div>
        ${related.length ? `<h3 class="related-title">More in ${esc(item.category)}</h3><div class="gvi-items-grid small">${related.map((r) => itemCard({ ...r, category_slug: slugOf[r.category_id] || '' })).join('')}</div>` : ''}`;
      m.body.addEventListener('click', (e) => {
        const fav = e.target.closest('[data-fav]');
        if (fav) {
          e.stopPropagation();
          const fid = Number(fav.dataset.fav);
          toggleFav(fid, fav).then((on) => {
            if (fid === item.id && on !== undefined) {
              item.favorite = on;
              fav.className = `gvi-btn ${on ? 'joined' : 'ghost'}`;
              fav.innerHTML = `${icon('bookmark')}${on ? 'In your garage' : 'Save to garage'}`;
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

  const pick = (card) => {
    const it = items.find((x) => x.id === Number(card.dataset.id));
    if (it) select(it, window.matchMedia('(max-width: 759px)').matches);
  };
  el.addEventListener('click', (e) => {
    const fav = e.target.closest('.items-wrap [data-fav], .gvi-showcase [data-fav]');
    if (fav) {
      e.stopPropagation();
      toggleFav(Number(fav.dataset.fav), fav);
      return;
    }
    const det = e.target.closest('[data-details]');
    if (det) return openItem(Number(det.dataset.details));
    const card = e.target.closest('.items-wrap .item-card');
    if (card) return pick(card);
    const cat = e.target.closest('[data-cat]');
    if (cat) {
      category = cat.dataset.cat;
      $$('[data-cat]', el).forEach((x) => x.classList.toggle('is-active', x === cat));
      setQuery({ category });
      selected = null;
      load(true);
    }
  });
  el.addEventListener('keydown', (e) => {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('item-card')) {
      e.preventDefault();
      pick(e.target);
    }
  });
  $('.fav-toggle', el).addEventListener('click', (e) => {
    favorites = !favorites;
    e.currentTarget.classList.toggle('is-active', favorites);
    setQuery({ fav: favorites ? '1' : null });
    load(true);
  });
  $('.sort', el).addEventListener('change', (e) => { sort = e.target.value; setQuery({ sort }); load(true); });
  $('.gvi-cat-tools input', el).addEventListener('input', debounce((e) => { q = e.target.value.trim(); setQuery({ q }); load(true); }, 300));
  await load(true);
  if (query.item) {
    const it = items.find((x) => x.id === Number(query.item));
    if (it) select(it);
    openItem(Number(query.item));
  }
}
