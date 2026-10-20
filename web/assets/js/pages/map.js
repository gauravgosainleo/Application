// Interactive Leonida map: tilted 3D overview with lit pins (default) + the flat Leaflet editor for placing,
// dragging and editing markers. Markers carry notes and can be private, friends-only or public.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { esc, $, $$, icon, asset, openModal, confirmBox, toast, toastError, setBusy, richText, timeAgo, avatar, debounce, shareLink, appLink } from '../core/ui.js';

export const CATS = {
  destination: ['📍', 'Destination', '#ff3d8b'],
  mission: ['🎯', 'Mission', '#f59e0b'],
  collectible: ['💎', 'Collectible', '#19d3c5'],
  hideout: ['🏠', 'Hideout', '#a855f7'],
  meetup: ['🤝', 'Meet-up', '#34d399'],
  shop: ['🛒', 'Shop', '#fbbf24'],
  vehicle: ['🚗', 'Vehicle', '#60a5fa'],
  danger: ['⚠️', 'Danger', '#f43f5e'],
  other: ['⭐', 'Other', '#e5e7eb'],
};

// Positions (fractions of the default fan map) for region chips, labels and quick jumps.
const REGIONS = [
  ['Vice City', 0.815, 0.69],
  ['Leonida Keys', 0.56, 0.925],
  ['Grassrivers', 0.64, 0.73],
  ['Port Gellhorn', 0.21, 0.17],
  ['Ambrosia', 0.62, 0.42],
  ['Mount Kalaga', 0.58, 0.16],
];

const VIS_LABEL = { private: 'Only me', friends: 'Friends', public: 'Everyone' };

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
    s.onerror = () => { leafletPromise = null; reject(new Error('Could not load the map library.')); };
    document.head.appendChild(s);
  });
  return leafletPromise;
}

function nearestRegion(m) {
  let best = 0;
  let bestD = Infinity;
  REGIONS.forEach(([, x, y], i) => {
    const d = (m.x - x) ** 2 + (m.y - y) ** 2;
    if (d < bestD) { bestD = d; best = i; }
  });
  return best;
}

export async function render({ el, query }) {
  const mapCfg = (state.config && state.config.map) || { image: 'assets/img/map/leonida.svg', width: 2000, height: 2600, custom: false };
  const W = mapCfg.width || 2000;
  const H = mapCfg.height || 2600;
  const showRegions = !mapCfg.custom;

  el.innerHTML = `
    <div class="gvi-map-layout">
      <aside class="gvi-map-side">
        <div class="ms-head"><h2>${icon('map')}Markers <span class="ms-count">(0)</span></h2><button class="gvi-btn sm add-btn">${icon('plus')}Add</button></div>
        <div class="gvi-chip-row ms-layers">
          <button class="gvi-chip is-active" data-layer="mine">Mine <b data-count="mine">0</b></button>
          <button class="gvi-chip is-active" data-layer="friends">Friends <b data-count="friends">0</b></button>
          <button class="gvi-chip" data-layer="public">Public <b data-count="public">0</b></button>
        </div>
        <div class="ms-selected" hidden></div>
        <div class="gvi-search-inline small">${icon('search')}<input type="search" class="gvi-input" placeholder="Search markers & notes…" aria-label="Search markers"></div>
        <div class="gvi-chip-row scroll ms-cats">
          <button class="gvi-chip sm is-active" data-cat="">All</button>
          ${Object.entries(CATS).map(([k, [e, l]]) => `<button class="gvi-chip sm" data-cat="${k}" title="${l}" aria-label="${l}">${e}</button>`).join('')}
        </div>
        <div class="marker-list"></div>
        <label class="check small ms-done"><input type="checkbox" class="hide-done"><span>Hide visited places</span></label>
        <p class="ms-note">${mapCfg.custom ? 'Map image provided by the admins.' : 'Fan-made map of Leonida. Not the official map.'}</p>
      </aside>
      <section class="gvi-map-stage">
        <div class="gms-grid" aria-hidden="true"></div>
        <div class="gvi-map-persp">
          <div class="gvi-map-tilt" data-tilt="map" style="aspect-ratio:${W}/${H}">
            <img src="${esc(asset(mapCfg.image))}" alt="Fan map of Leonida">
            <span class="sheen"></span>
            ${showRegions ? REGIONS.map(([n, x, y], i) => `<button class="gms-label" data-region="${i}" style="left:${(x * 100).toFixed(1)}%;top:${(y * 100).toFixed(1)}%">${esc(n)}</button>`).join('') : ''}
            <div class="gms-pins"></div>
          </div>
        </div>
        ${showRegions ? `<div class="gms-regions"><button class="gvi-chip is-active" data-region="all">All of Leonida</button>${REGIONS.map(([n], i) => `<button class="gvi-chip" data-region="${i}">${esc(n)}</button>`).join('')}</div>` : ''}
        <div class="gms-hint">${icon('pin')}<span>Move the pointer to tilt · tap a pin for notes</span></div>
        <button class="gvi-btn ghost sm gms-mode" data-act="flat">${icon('layers')}<span>Flat map</span></button>
        <div class="gms-flat" hidden>
          <div class="leo-map" id="leo-map"></div>
          <div class="map-hint" hidden>${icon('pin')} Tap the map where you want the marker <button class="link-btn cancel-add">Cancel</button></div>
        </div>
      </section>
    </div>`;

  const stage = $('.gvi-map-stage', el);
  const pinsEl = $('.gms-pins', el);
  const selBox = $('.ms-selected', el);

  let markers = [];
  const layers = { mine: true, friends: true, public: false };
  let catFilter = '';
  let search = '';
  let hideDone = false;
  let region = 'all';
  let selected = null;
  let mode = '3d';

  /* ------------------------------------------------------------ filters */
  function visible(m) {
    if (!layers[m.layer]) return false;
    if (catFilter && m.category !== catFilter) return false;
    if (hideDone && m.is_done) return false;
    if (region !== 'all' && nearestRegion(m) !== Number(region)) return false;
    if (search) {
      const hay = `${m.title} ${m.note || ''} ${m.author ? m.author.name : ''}`.toLowerCase();
      if (!hay.includes(search)) return false;
    }
    return true;
  }

  const layerLabel = (m) => (m.own ? `My marker · ${VIS_LABEL[m.visibility]}` : `${m.layer === 'friends' ? 'Friend' : 'Public'} · ${m.author ? m.author.name : ''}`);

  /* ----------------------------------------------------------- 3D pins */
  function renderPins() {
    pinsEl.innerHTML = markers.filter(visible).map((m) => {
      const [emoji, , color] = CATS[m.category] || CATS.other;
      const sel = selected === m.id;
      return `<button class="gms-pin ${sel ? 'is-sel' : ''} ${m.is_done ? 'done' : ''}" data-pin="${m.id}" title="${esc(m.title)}" aria-label="${esc(m.title)}" style="left:${(m.x * 100).toFixed(2)}%;top:${(m.y * 100).toFixed(2)}%;--c:${color}">
        <span class="gp-shadow"></span><span class="gp-beam"></span><span class="gp-head"><i>${emoji}</i></span>
      </button>`;
    }).join('');
  }

  /* -------------------------------------------------------- side panel */
  function renderList() {
    const counts = { mine: 0, friends: 0, public: 0 };
    markers.forEach((m) => { counts[m.layer]++; });
    Object.entries(counts).forEach(([k, v]) => { const b = $(`[data-count="${k}"]`, el); if (b) b.textContent = v; });
    const shown = markers.filter(visible);
    $('.ms-count', el).textContent = `(${shown.length})`;
    const list = $('.marker-list', el);
    if (!shown.length) {
      list.innerHTML = `<div class="empty small">${icon('pin', 'empty-icon')}<p>${markers.length ? 'No markers match your filters.' : 'No markers yet. Tap “Add” and pick a spot to plan your trip!'}</p></div>`;
      return;
    }
    list.innerHTML = shown.map((m) => {
      const [emoji, , color] = CATS[m.category] || CATS.other;
      return `<button class="marker-row ${m.is_done ? 'done' : ''} ${selected === m.id ? 'is-sel' : ''}" data-id="${m.id}" style="--c:${color}">
        <span class="mr-icon">${emoji}</span>
        <span class="mr-main"><strong>${esc(m.title)}</strong><small>${esc(layerLabel(m))}</small></span>
        ${m.is_done ? `<span class="mr-done">${icon('check')}</span>` : ''}
      </button>`;
    }).join('');
  }

  function renderSelected() {
    const m = markers.find((x) => x.id === selected);
    if (!m) {
      selBox.hidden = true;
      selBox.innerHTML = '';
      return;
    }
    const [emoji, label, color] = CATS[m.category] || CATS.other;
    selBox.hidden = false;
    selBox.innerHTML = `
      <button class="ms-close" data-sel="close" aria-label="Close">${icon('x')}</button>
      <div class="ms-sel-head"><span class="ms-sel-icon" style="--c:${color}">${emoji}</span><div><strong>${esc(m.title)}</strong><small>${esc(label)} · ${esc(m.own ? VIS_LABEL[m.visibility] : (m.author ? m.author.name : ''))}</small></div></div>
      ${m.note ? `<div class="ms-sel-note">${richText(m.note)}</div>` : '<p class="ms-sel-note muted">No note yet.</p>'}
      <div class="ms-sel-foot">${m.own ? '' : `<a href="#/profile/${m.author.id}" class="ms-author">${avatar(m.author, 'xs', { presence: false })}${esc(m.author.name)}</a>`}<span>${m.is_done ? `${icon('check')} Visited · ` : ''}${timeAgo(m.updated_at || m.created_at)}</span></div>
      <div class="ms-sel-actions">
        ${m.own ? `
          <button class="gvi-btn ghost sm" data-sel="done">${icon('check')}${m.is_done ? 'Not visited' : 'Mark visited'}</button>
          <button class="gvi-btn ghost sm" data-sel="edit">${icon('edit')}Edit</button>
          <button class="gvi-btn ghost sm danger" data-sel="delete" aria-label="Delete">${icon('trash')}</button>`
        : `<button class="gvi-btn ghost sm" data-sel="share">${icon('share')}Share</button>`}
        <button class="gvi-btn ghost sm" data-sel="flat">${icon('layers')}${mode === 'flat' ? 'Zoom to' : 'Open'}</button>
      </div>`;
  }

  function refresh() {
    renderPins();
    renderList();
    renderSelected();
    if (leaf) leaf.sync();
  }

  function select(id, { scroll = false } = {}) {
    selected = selected === id ? null : id;
    renderPins();
    renderList();
    renderSelected();
    if (selected && scroll && window.innerWidth < 760) selBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  /* ----------------------------------------------------- data actions */
  async function load() {
    try {
      markers = await api.get('map/markers');
      const focus = Number(query.marker);
      if (focus) {
        const m = markers.find((x) => x.id === focus);
        if (m && !layers[m.layer]) {
          layers[m.layer] = true;
          $(`[data-layer="${m.layer}"]`, el)?.classList.add('is-active');
        }
        selected = m ? m.id : null;
      }
      refresh();
    } catch (err) {
      toastError(err);
      renderList();
    }
  }

  const payload = (m, patch = {}) => ({ id: m.id, title: m.title, note: m.note || '', category: m.category, visibility: m.visibility, is_done: m.is_done, x: m.x, y: m.y, ...patch });

  async function toggleDone(m) {
    try {
      Object.assign(m, await api.post('map/save', payload(m, { is_done: !m.is_done })));
      if (leaf) leaf.closePopup();
      refresh();
      toast(m.is_done ? 'Marked as visited' : 'Marked as not visited', 'success');
    } catch (err) {
      toastError(err);
    }
  }

  async function removeMarker(m) {
    if (!(await confirmBox(`Delete “${m.title}”?`, { confirmText: 'Delete', danger: true }))) return;
    try {
      await api.post('map/delete', { id: m.id });
      markers = markers.filter((x) => x.id !== m.id);
      if (selected === m.id) selected = null;
      if (leaf) leaf.remove(m.id);
      refresh();
      toast('Marker deleted', 'success');
    } catch (err) {
      toastError(err);
    }
  }

  async function moveMarker(m, pos) {
    try {
      Object.assign(m, await api.post('map/save', payload(m, { x: pos.x, y: pos.y })));
      refresh();
      toast('Marker moved', 'success');
    } catch (err) {
      toastError(err);
      refresh();
    }
  }

  function openForm(m, pos, onClose) {
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
      footer: `<button class="gvi-btn ghost" data-act="cancel">Cancel</button><button class="gvi-btn" data-act="save">${icon('check')}Save marker</button>`,
      onClose,
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
          $('[data-layer="mine"]', el).classList.add('is-active');
        }
        selected = saved.id;
        modal.close();
        refresh();
        toast(editing ? 'Marker updated' : 'Marker added to your map! +3 XP', 'success');
        if (!editing && leaf) setTimeout(() => leaf.focus(saved.id), 100);
      } catch (err) {
        toastError(err);
        setBusy(btn, false);
      }
    };
    $('[data-act="save"]', modal.el).addEventListener('click', save);
    form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
  }

  /* -------------------------------------------------- flat (Leaflet) */
  let leaf = null;

  async function setMode(next, { add = false } = {}) {
    mode = next;
    const flat = $('.gms-flat', el);
    stage.classList.toggle('is-flat', mode === 'flat');
    flat.hidden = mode !== 'flat';
    const btn = $('.gms-mode', el);
    btn.dataset.act = mode === 'flat' ? '3d' : 'flat';
    btn.innerHTML = mode === 'flat' ? `${icon('compass')}<span>3D view</span>` : `${icon('layers')}<span>Flat map</span>`;
    renderSelected();
    if (mode === 'flat') {
      try {
        if (!leaf) leaf = await createLeaflet();
        leaf.fit();
        leaf.sync();
        if (selected && !add) leaf.focus(selected);
        if (add) leaf.setAddMode(true);
      } catch (err) {
        toastError(err);
        setMode('3d');
      }
    } else if (leaf) {
      leaf.setAddMode(false);
      leaf.closePopup();
    }
  }

  async function createLeaflet() {
    const L = await loadLeaflet();
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
    map.setMaxBounds([[-H * 0.25, -W * 0.25], [H * 1.25, W * 1.25]]);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    const toLatLng = (x, y) => [H * (1 - y), W * x];
    const fromLatLng = (ll) => ({ x: Math.min(1, Math.max(0, ll.lng / W)), y: Math.min(1, Math.max(0, 1 - ll.lat / H)) });
    const lms = new Map();
    let addMode = false;
    let tempMarker = null;
    const dropTemp = () => { if (tempMarker) { map.removeLayer(tempMarker); tempMarker = null; } };

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

    function sync() {
      const ids = new Set();
      markers.forEach((m) => {
        if (!visible(m)) return;
        ids.add(m.id);
        let lm = lms.get(m.id);
        if (!lm) {
          lm = L.marker(toLatLng(m.x, m.y), { icon: markerIcon(m), riseOnHover: true, draggable: m.own });
          lm.bindPopup(() => popupHTML(lm.__m), { maxWidth: 300, minWidth: 220 });
          lm.on('dragend', () => moveMarker(lm.__m, fromLatLng(lm.getLatLng())));
          lm.on('click', () => { selected = lm.__m.id; renderPins(); renderList(); renderSelected(); });
          lms.set(m.id, lm);
        } else {
          lm.setLatLng(toLatLng(m.x, m.y));
          lm.setIcon(markerIcon(m));
        }
        lm.__m = m;
        if (!map.hasLayer(lm)) lm.addTo(map);
      });
      lms.forEach((lm, id) => {
        if (!ids.has(id) && map.hasLayer(lm)) map.removeLayer(lm);
      });
    }

    function focus(id) {
      const m = markers.find((x) => x.id === id);
      if (!m) return;
      sync();
      const lm = lms.get(id);
      map.flyTo(toLatLng(m.x, m.y), Math.max(map.getZoom(), map.getMinZoom() + 2.25), { duration: 0.6 });
      setTimeout(() => lm && lm.openPopup(), 650);
    }

    function setAddMode(on) {
      addMode = on;
      mapEl.classList.toggle('adding', on);
      $('.map-hint', el).hidden = !on;
    }

    map.on('click', (e) => {
      const pos = fromLatLng(e.latlng);
      if (e.latlng.lat < 0 || e.latlng.lat > H || e.latlng.lng < 0 || e.latlng.lng > W) return;
      if (addMode) {
        setAddMode(false);
        tempMarker = L.marker(e.latlng, { icon: markerIcon({ category: 'destination', layer: 'mine' }) }).addTo(map);
        openForm(null, pos, dropTemp);
        return;
      }
      L.popup({ closeButton: false, className: 'quick-popup' })
        .setLatLng(e.latlng)
        .setContent(`<button class="gvi-btn sm quick-add">${icon('plus')} Add marker here</button>`)
        .openOn(map);
      setTimeout(() => {
        const b = document.querySelector('.quick-add');
        if (b) b.addEventListener('click', () => {
          map.closePopup();
          tempMarker = L.marker(e.latlng, { icon: markerIcon({ category: 'destination', layer: 'mine' }) }).addTo(map);
          openForm(null, pos, dropTemp);
        });
      }, 0);
    });

    map.on('popupopen', (e) => {
      const root = e.popup.getElement();
      const box = root && root.querySelector('.mk-popup');
      if (!box) return;
      const m = markers.find((x) => String(x.id) === box.dataset.id);
      root.querySelectorAll('[data-mk]').forEach((b) => b.addEventListener('click', () => {
        const act = b.dataset.mk;
        if (act === 'edit') {
          map.closePopup();
          openForm(m);
        } else if (act === 'done') {
          toggleDone(m);
        } else if (act === 'delete') {
          removeMarker(m);
        }
      }));
    });

    const onResize = debounce(() => { if (mode !== 'flat') return; if (touched) map.invalidateSize(); else fit(); }, 150);
    window.addEventListener('resize', onResize);
    $('.cancel-add', el).addEventListener('click', () => setAddMode(false));

    return {
      fit: () => { if (!touched) fit(); else map.invalidateSize(); },
      sync,
      focus,
      setAddMode,
      flyToRegion: (i) => { const [, x, y] = REGIONS[i]; map.flyTo(toLatLng(x, y), map.getMinZoom() + 2, { duration: 0.8 }); },
      closePopup: () => map.closePopup(),
      remove: (id) => { const lm = lms.get(id); if (lm) map.removeLayer(lm); lms.delete(id); },
      destroy: () => { window.removeEventListener('resize', onResize); map.remove(); },
    };
  }

  /* -------------------------------------------------------- interactions */
  el.addEventListener('click', async (e) => {
    const pin = e.target.closest('[data-pin]');
    if (pin) return select(Number(pin.dataset.pin), { scroll: true });
    const row = e.target.closest('.marker-row');
    if (row) {
      const id = Number(row.dataset.id);
      if (mode === 'flat') {
        selected = id;
        renderPins();
        renderList();
        renderSelected();
        if (leaf) leaf.focus(id);
      } else {
        select(id);
      }
      return;
    }
    const layerBtn = e.target.closest('[data-layer]');
    if (layerBtn) {
      const k = layerBtn.dataset.layer;
      layers[k] = !layers[k];
      layerBtn.classList.toggle('is-active', layers[k]);
      return refresh();
    }
    const catBtn = e.target.closest('[data-cat]');
    if (catBtn) {
      catFilter = catBtn.dataset.cat;
      $$('[data-cat]', el).forEach((x) => x.classList.toggle('is-active', x === catBtn));
      return refresh();
    }
    const reg = e.target.closest('[data-region]');
    if (reg) {
      const key = reg.dataset.region;
      region = region === key && key !== 'all' ? 'all' : key;
      $$('.gms-regions [data-region]', el).forEach((x) => x.classList.toggle('is-active', x.dataset.region === region));
      $$('.gms-label', el).forEach((x) => x.classList.toggle('is-active', x.dataset.region === region));
      if (mode === 'flat' && leaf && region !== 'all') leaf.flyToRegion(Number(region));
      return refresh();
    }
    const sel = e.target.closest('[data-sel]');
    if (sel) {
      const m = markers.find((x) => x.id === selected);
      const act = sel.dataset.sel;
      if (act === 'close') { selected = null; return refresh(); }
      if (!m) return;
      if (act === 'done') return toggleDone(m);
      if (act === 'edit') return openForm(m);
      if (act === 'delete') return removeMarker(m);
      if (act === 'share') return shareLink(m.title, appLink('#/map?marker=' + m.id));
      if (act === 'flat') {
        if (mode !== 'flat') await setMode('flat');
        else if (leaf) leaf.focus(m.id);
      }
      return;
    }
    const modeBtn = e.target.closest('.gms-mode');
    if (modeBtn) return setMode(modeBtn.dataset.act === 'flat' ? 'flat' : '3d');
    if (e.target.closest('.add-btn')) return setMode('flat', { add: true });
  });
  $('.hide-done', el).addEventListener('change', (e) => { hideDone = e.target.checked; refresh(); });
  $('.gvi-map-side input[type=search]', el).addEventListener('input', debounce((e) => { search = e.target.value.trim().toLowerCase(); refresh(); }, 200));

  load();

  return () => {
    if (leaf) leaf.destroy();
  };
}
