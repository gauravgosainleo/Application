// Interactive Leonida map (Leaflet, image overlay) with personal markers + notes.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { esc, $, $$, icon, asset, openModal, confirmBox, toast, toastError, setBusy, richText, timeAgo, avatar, debounce } from '../core/ui.js';

export const CATS = {
  destination: ['📍', 'Destination', '#ff3d8b'],
  mission: ['🎯', 'Mission', '#f97316'],
  collectible: ['💎', 'Collectible', '#22d3ee'],
  hideout: ['🏠', 'Hideout', '#a78bfa'],
  meetup: ['🤝', 'Meet-up', '#34d399'],
  shop: ['🛒', 'Shop', '#fbbf24'],
  vehicle: ['🚗', 'Vehicle', '#60a5fa'],
  danger: ['⚠️', 'Danger', '#f43f5e'],
  other: ['⭐', 'Other', '#e5e7eb'],
};

// Positions (fractions of the default fan map) for quick jumps.
const REGIONS = [
  ['Vice City', 0.815, 0.69],
  ['Leonida Keys', 0.56, 0.925],
  ['Grassrivers', 0.64, 0.73],
  ['Port Gellhorn', 0.21, 0.17],
  ['Ambrosia', 0.62, 0.42],
  ['Mount Kalaga', 0.58, 0.16],
];

let leafletPromise = null;
function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  if (leafletPromise) return leafletPromise;
  leafletPromise = new Promise((resolve, reject) => {
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'assets/vendor/leaflet/leaflet.css';
    document.head.appendChild(css);
    const s = document.createElement('script');
    s.src = 'assets/vendor/leaflet/leaflet.js';
    s.onload = () => resolve(window.L);
    s.onerror = () => reject(new Error('Could not load the map library.'));
    document.head.appendChild(s);
  });
  return leafletPromise;
}

export async function render({ el, query }) {
  el.innerHTML = `<div class="loader"><span class="spinner lg"></span><span>Loading the Leonida map…</span></div>`;
  const L = await loadLeaflet();
  const mapCfg = (state.config && state.config.map) || { image: 'assets/img/map/leonida.svg', width: 2000, height: 2600, custom: false };
  const W = mapCfg.width || 2000;
  const H = mapCfg.height || 2600;

  el.innerHTML = `
    <div class="map-page">
      <div class="leo-map" id="leo-map"></div>
      <div class="map-topbar">
        <button class="btn btn-glass btn-sm panel-toggle">${icon('layers')} <span>Markers</span></button>
        ${mapCfg.custom ? '' : `<div class="region-chips">${REGIONS.map(([n], i) => `<button class="chip" data-region="${i}">${esc(n)}</button>`).join('')}</div>`}
        <button class="btn btn-primary btn-sm add-btn">${icon('plus')} <span>Add marker</span></button>
      </div>
      <div class="map-hint" hidden>${icon('pin')} Tap the map where you want the marker <button class="link-btn cancel-add">Cancel</button></div>
      <aside class="map-panel">
        <div class="mp-head">
          <h2>${icon('map')} Leonida Map</h2>
          <button class="icon-btn mp-close" aria-label="Close panel">${icon('x')}</button>
        </div>
        <div class="search-input small">${icon('search')}<input type="search" placeholder="Search markers & notes…"></div>
        <div class="layer-toggles">
          <label class="toggle-chip"><input type="checkbox" data-layer="mine" checked><span>Mine <b data-count="mine">0</b></span></label>
          <label class="toggle-chip"><input type="checkbox" data-layer="friends" checked><span>Friends <b data-count="friends">0</b></span></label>
          <label class="toggle-chip"><input type="checkbox" data-layer="public"><span>Public <b data-count="public">0</b></span></label>
        </div>
        <div class="cat-filter">
          <button class="chip active" data-cat="">All</button>
          ${Object.entries(CATS).map(([k, [e, l]]) => `<button class="chip" data-cat="${k}" title="${l}">${e}</button>`).join('')}
        </div>
        <label class="check small"><input type="checkbox" class="hide-done"><span>Hide visited places</span></label>
        <div class="marker-list"></div>
        <p class="muted tiny map-disclaimer">${mapCfg.custom ? 'Map image provided by the admins.' : 'Fan-made map of Leonida for planning — not to scale. Admins can replace it with the official map after launch.'}</p>
      </aside>
    </div>`;

  const mapEl = $('#leo-map', el);
  const map = L.map(mapEl, { crs: L.CRS.Simple, minZoom: -8, maxZoom: 8, attributionControl: false, zoomControl: false, zoomSnap: 0.25, wheelPxPerZoomLevel: 90 });
  let touched = false;
  ['mousedown', 'touchstart', 'wheel'].forEach((ev) => mapEl.addEventListener(ev, () => { touched = true; }, { passive: true, once: true }));
  const bounds = [[0, 0], [H, W]];
  L.imageOverlay(asset(mapCfg.image), bounds, { className: 'map-image' }).addTo(map);
  const fit = () => {
    map.invalidateSize();
    map.setMinZoom(-8);
    const z = map.getBoundsZoom(bounds, false, L.point(20, 20));
    map.fitBounds(bounds, { padding: [10, 10], animate: false });
    map.setMinZoom(z - 0.75);
    map.setMaxZoom(z + 4);
  };
  fit();
  map.setMaxBounds([[-H * 0.25, -W * 0.25], [H * 1.25, W * 1.25]]);
  L.control.zoom({ position: 'bottomright' }).addTo(map);

  const toLatLng = (x, y) => [H * (1 - y), W * x];
  const fromLatLng = (ll) => ({ x: Math.min(1, Math.max(0, ll.lng / W)), y: Math.min(1, Math.max(0, 1 - ll.lat / H)) });

  let markers = [];
  const layers = { mine: true, friends: true, public: false };
  let catFilter = '';
  let search = '';
  let hideDone = false;
  let addMode = false;
  let tempMarker = null;
  const leafletMarkers = new Map();

  function markerIcon(m) {
    const [emoji, , color] = CATS[m.category] || CATS.other;
    return L.divIcon({
      className: 'mk',
      html: `<div class="mk-pin layer-${m.layer} ${m.is_done ? 'done' : ''}" style="--mk:${color}"><span>${emoji}</span></div>`,
      iconSize: [36, 46],
      iconAnchor: [18, 44],
      popupAnchor: [0, -42],
    });
  }

  function popupHTML(m) {
    const [emoji, label] = CATS[m.category] || CATS.other;
    const vis = { private: `${icon('lock')} Only me`, friends: `${icon('users')} Friends`, public: `${icon('globe')} Everyone` }[m.visibility];
    return `<div class="mk-popup" data-id="${m.id}">
      <div class="mkp-head"><span class="mkp-cat">${emoji} ${esc(label)}</span>${m.is_done ? `<span class="chip chip-ok">${icon('check')} Visited</span>` : ''}</div>
      <h4>${esc(m.title)}</h4>
      ${m.note ? `<div class="mkp-note">${richText(m.note)}</div>` : ''}
      <div class="mkp-foot">
        ${m.own ? `<span class="muted small">${vis}</span>` : `<a href="#/profile/${m.author.id}" class="mkp-author">${avatar(m.author, 'xs', { presence: false })} ${esc(m.author.name)}</a>`}
        <span class="muted small">${timeAgo(m.updated_at || m.created_at)}</span>
      </div>
      ${m.own ? `<div class="mkp-actions">
        <button class="btn btn-sm btn-ghost" data-mk="done">${icon('check')} ${m.is_done ? 'Not visited' : 'Visited'}</button>
        <button class="btn btn-sm btn-ghost" data-mk="edit">${icon('edit')} Edit</button>
        <button class="btn btn-sm btn-ghost danger" data-mk="delete">${icon('trash')}</button>
      </div>` : ''}
    </div>`;
  }

  function visible(m) {
    if (!layers[m.layer]) return false;
    if (catFilter && m.category !== catFilter) return false;
    if (hideDone && m.is_done) return false;
    if (search) {
      const hay = `${m.title} ${m.note || ''} ${m.author ? m.author.name : ''}`.toLowerCase();
      if (!hay.includes(search)) return false;
    }
    return true;
  }

  function sync() {
    const counts = { mine: 0, friends: 0, public: 0 };
    markers.forEach((m) => { counts[m.layer]++; });
    Object.entries(counts).forEach(([k, v]) => { const b = $(`[data-count="${k}"]`, el); if (b) b.textContent = v; });
    const ids = new Set();
    markers.forEach((m) => {
      if (!visible(m)) return;
      ids.add(m.id);
      let lm = leafletMarkers.get(m.id);
      if (!lm) {
        lm = L.marker(toLatLng(m.x, m.y), { icon: markerIcon(m), riseOnHover: true, draggable: m.own });
        lm.bindPopup(() => popupHTML(lm.__m), { maxWidth: 300, minWidth: 220 });
        lm.on('dragend', () => moveMarker(lm.__m, fromLatLng(lm.getLatLng())));
        leafletMarkers.set(m.id, lm);
      } else {
        lm.setLatLng(toLatLng(m.x, m.y));
        lm.setIcon(markerIcon(m));
      }
      lm.__m = m;
      if (!map.hasLayer(lm)) lm.addTo(map);
    });
    leafletMarkers.forEach((lm, id) => {
      if (!ids.has(id) && map.hasLayer(lm)) map.removeLayer(lm);
    });
    renderList();
  }

  function renderList() {
    const list = $('.marker-list', el);
    const shown = markers.filter(visible);
    if (!shown.length) {
      list.innerHTML = `<div class="empty small">${icon('pin', 'empty-icon')}<p>${markers.length ? 'No markers match your filters.' : 'No markers yet. Tap “Add marker” and click a spot on the map to plan your trip!'}</p></div>`;
      return;
    }
    list.innerHTML = shown.map((m) => {
      const [emoji] = CATS[m.category] || CATS.other;
      return `<button class="marker-row ${m.is_done ? 'done' : ''}" data-id="${m.id}">
        <span class="mr-emoji">${emoji}</span>
        <span class="mr-main"><strong>${esc(m.title)}</strong><small class="muted">${m.own ? ({ private: 'Only me', friends: 'Friends', public: 'Everyone' }[m.visibility]) : esc(m.author.name)}${m.note ? ' · ' + esc(m.note.slice(0, 60)) : ''}</small></span>
        ${m.is_done ? `<span class="mr-done">${icon('check')}</span>` : ''}
      </button>`;
    }).join('');
  }

  async function load() {
    try {
      markers = await api.get('map/markers');
      sync();
      const focus = Number(query.marker);
      if (focus) focusMarker(focus);
    } catch (err) {
      toastError(err);
    }
  }

  function focusMarker(id) {
    const m = markers.find((x) => x.id === id);
    if (!m) return;
    if (!layers[m.layer]) {
      layers[m.layer] = true;
      const cb = $(`[data-layer="${m.layer}"]`, el);
      if (cb) cb.checked = true;
      sync();
    }
    const lm = leafletMarkers.get(id);
    map.flyTo(toLatLng(m.x, m.y), Math.max(map.getZoom(), map.getMinZoom() + 2.25), { duration: 0.6 });
    setTimeout(() => lm && lm.openPopup(), 650);
    if (window.innerWidth < 900) el.querySelector('.map-page').classList.remove('panel-open');
  }

  async function moveMarker(m, pos) {
    try {
      const saved = await api.post('map/save', { id: m.id, title: m.title, note: m.note || '', category: m.category, visibility: m.visibility, is_done: m.is_done, x: pos.x, y: pos.y });
      Object.assign(m, saved);
      toast('Marker moved', 'success');
    } catch (err) {
      toastError(err);
      sync();
    }
  }

  function openForm(m, pos) {
    const editing = !!(m && m.id);
    const data = m || { title: '', note: '', category: 'destination', visibility: 'private', is_done: false };
    const modal = openModal({
      title: editing ? 'Edit marker' : 'New marker',
      size: 'md',
      body: `
        <form class="stack marker-form">
          <label class="field"><span>Title</span><input class="input" name="title" maxlength="100" required value="${esc(data.title)}" placeholder="e.g. Sunset spot at Vice Beach"></label>
          <div class="field"><span>Category</span>
            <div class="cat-pick">${Object.entries(CATS).map(([k, [e, l]]) => `<label class="cat-opt"><input type="radio" name="category" value="${k}" ${data.category === k ? 'checked' : ''}><span>${e}<small>${l}</small></span></label>`).join('')}</div>
          </div>
          <label class="field"><span>Note</span><textarea class="input" name="note" rows="4" maxlength="2000" placeholder="What do you want to do here? Add tips, times, who to bring…">${esc(data.note || '')}</textarea></label>
          <div class="field"><span>Who can see it?</span>
            <div class="segmented">
              <label><input type="radio" name="visibility" value="private" ${data.visibility === 'private' ? 'checked' : ''}><span>${icon('lock')} Only me</span></label>
              <label><input type="radio" name="visibility" value="friends" ${data.visibility === 'friends' ? 'checked' : ''}><span>${icon('users')} Friends</span></label>
              <label><input type="radio" name="visibility" value="public" ${data.visibility === 'public' ? 'checked' : ''}><span>${icon('globe')} Everyone</span></label>
            </div>
          </div>
          <label class="check"><input type="checkbox" name="is_done" ${data.is_done ? 'checked' : ''}><span>I have visited this place</span></label>
        </form>`,
      footer: `<button class="btn btn-ghost" data-act="cancel">Cancel</button><button class="btn btn-primary" data-act="save">${icon('check')} Save marker</button>`,
      onClose: () => { if (tempMarker) { map.removeLayer(tempMarker); tempMarker = null; } },
    });
    const form = $('form', modal.el);
    $('[data-act="cancel"]', modal.el).addEventListener('click', () => modal.close());
    const save = async () => {
      const btn = $('[data-act="save"]', modal.el);
      if (!form.title.value.trim()) {
        form.title.focus();
        toast('Give your marker a title.', 'warn');
        return;
      }
      setBusy(btn, true);
      try {
        const saved = await api.post('map/save', {
          id: editing ? m.id : undefined,
          title: form.title.value.trim(),
          note: form.note.value,
          category: form.category.value,
          visibility: form.visibility.value,
          is_done: form.is_done.checked,
          x: editing ? m.x : pos.x,
          y: editing ? m.y : pos.y,
        });
        if (editing) Object.assign(m, saved);
        else markers.unshift(saved);
        if (!layers.mine) {
          layers.mine = true;
          $('[data-layer="mine"]', el).checked = true;
        }
        modal.close();
        sync();
        toast(editing ? 'Marker updated' : 'Marker added to your map! +3 XP', 'success');
        if (!editing) setTimeout(() => focusMarker(saved.id), 100);
      } catch (err) {
        toastError(err);
        setBusy(btn, false);
      }
    };
    $('[data-act="save"]', modal.el).addEventListener('click', save);
    form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
  }

  function setAddMode(on) {
    addMode = on;
    mapEl.classList.toggle('adding', on);
    $('.map-hint', el).hidden = !on;
    if (on && window.innerWidth < 900) el.querySelector('.map-page').classList.remove('panel-open');
  }

  map.on('click', (e) => {
    const pos = fromLatLng(e.latlng);
    if (e.latlng.lat < 0 || e.latlng.lat > H || e.latlng.lng < 0 || e.latlng.lng > W) return;
    if (addMode) {
      setAddMode(false);
      tempMarker = L.marker(e.latlng, { icon: markerIcon({ category: 'destination', layer: 'mine' }) }).addTo(map);
      openForm(null, pos);
      return;
    }
    L.popup({ closeButton: false, className: 'quick-popup' })
      .setLatLng(e.latlng)
      .setContent(`<button class="btn btn-primary btn-sm quick-add">${icon('plus')} Add marker here</button>`)
      .openOn(map);
    setTimeout(() => {
      const b = document.querySelector('.quick-add');
      if (b) b.addEventListener('click', () => {
        map.closePopup();
        tempMarker = L.marker(e.latlng, { icon: markerIcon({ category: 'destination', layer: 'mine' }) }).addTo(map);
        openForm(null, pos);
      });
    }, 0);
  });

  map.on('popupopen', (e) => {
    const root = e.popup.getElement();
    const box = root && root.querySelector('.mk-popup');
    if (!box) return;
    const m = markers.find((x) => String(x.id) === box.dataset.id);
    root.querySelectorAll('[data-mk]').forEach((b) => b.addEventListener('click', async () => {
      const act = b.dataset.mk;
      if (act === 'edit') {
        map.closePopup();
        openForm(m);
      } else if (act === 'done') {
        try {
          Object.assign(m, await api.post('map/save', { id: m.id, title: m.title, note: m.note || '', category: m.category, visibility: m.visibility, is_done: !m.is_done, x: m.x, y: m.y }));
          map.closePopup();
          sync();
        } catch (err) {
          toastError(err);
        }
      } else if (act === 'delete') {
        if (!(await confirmBox(`Delete “${m.title}”?`, { confirmText: 'Delete', danger: true }))) return;
        try {
          await api.post('map/delete', { id: m.id });
          markers = markers.filter((x) => x.id !== m.id);
          const lm = leafletMarkers.get(m.id);
          if (lm) map.removeLayer(lm);
          leafletMarkers.delete(m.id);
          sync();
          toast('Marker deleted', 'success');
        } catch (err) {
          toastError(err);
        }
      }
    }));
  });

  // Panel interactions
  const page = $('.map-page', el);
  if (window.innerWidth >= 900) page.classList.add('panel-open');
  $('.panel-toggle', el).addEventListener('click', () => page.classList.toggle('panel-open'));
  $('.mp-close', el).addEventListener('click', () => page.classList.remove('panel-open'));
  $('.add-btn', el).addEventListener('click', () => setAddMode(!addMode));
  $('.cancel-add', el).addEventListener('click', () => setAddMode(false));
  $$('[data-layer]', el).forEach((cb) => cb.addEventListener('change', () => { layers[cb.dataset.layer] = cb.checked; sync(); }));
  $$('[data-cat]', el).forEach((b) => b.addEventListener('click', () => {
    catFilter = b.dataset.cat;
    $$('[data-cat]', el).forEach((x) => x.classList.toggle('active', x === b));
    sync();
  }));
  $('.hide-done', el).addEventListener('change', (e) => { hideDone = e.target.checked; sync(); });
  $('.map-panel input[type=search]', el).addEventListener('input', debounce((e) => { search = e.target.value.trim().toLowerCase(); sync(); }, 200));
  $('.marker-list', el).addEventListener('click', (e) => {
    const row = e.target.closest('.marker-row');
    if (row) focusMarker(Number(row.dataset.id));
  });
  $$('[data-region]', el).forEach((b) => b.addEventListener('click', () => {
    const [, x, y] = REGIONS[Number(b.dataset.region)];
    map.flyTo(toLatLng(x, y), map.getMinZoom() + 2, { duration: 0.8 });
  }));

  const onResize = debounce(() => { if (touched) map.invalidateSize(); else fit(); }, 150);
  window.addEventListener('resize', onResize);
  setTimeout(() => { if (!touched) fit(); else map.invalidateSize(); }, 250);
  load();

  return () => {
    window.removeEventListener('resize', onResize);
    map.remove();
  };
}
