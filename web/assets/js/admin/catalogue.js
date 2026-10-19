// Admin: catalogue categories and items (vehicles, weapons, equipment ...).
import { api } from '../core/api.js';
import { esc, $, $$, icon, asset, fmtMoney, loader, errorState, confirmBox, toast, toastError, debounce } from '../core/ui.js';
import { pageHead, pager, formModal, send } from './admin.js';

const STATUSES = ['Confirmed', 'Spotted in trailer', 'Expected', 'Rumored', 'Leaked'];

export async function render({ el }) {
  let categories = [];
  let current = 0;
  let q = '';
  let page = 1;
  let items = [];
  el.innerHTML = pageHead('car', 'Catalogue', 'Manage categories and the vehicles, weapons and equipment inside them.',
    `<button class="btn btn-ghost" data-act="add-cat">${icon('plus')} Add category</button><button class="btn btn-primary" data-act="add-item">${icon('plus')} Add item</button>`) + `
    <div class="cat-admin">
      <aside class="card cat-list"></aside>
      <section>
        <div class="filter-bar"><div class="search-input">${icon('search')}<input type="search" placeholder="Search items…"></div></div>
        <div class="table-wrap card"></div>
        <div class="pager-wrap"></div>
      </section>
    </div>`;
  const catBox = $('.cat-list', el);
  const wrap = $('.table-wrap', el);

  function drawCats() {
    const total = categories.reduce((s, c) => s + c.items, 0);
    catBox.innerHTML = `<button class="cat-row ${current === 0 ? 'active' : ''}" data-cat="0"><span>📚 All items</span><b>${total}</b></button>` +
      categories.map((c) => `
        <div class="cat-row ${current === c.id ? 'active' : ''}" data-cat="${c.id}">
          <button class="cat-pick-btn" data-cat="${c.id}"><span>${esc(c.icon || '📦')} ${esc(c.name)}</span><b>${c.items}</b></button>
          <span class="cat-tools"><button class="icon-btn" data-act="edit-cat" data-id="${c.id}" title="Edit">${icon('edit')}</button><button class="icon-btn danger" data-act="del-cat" data-id="${c.id}" title="Delete">${icon('trash')}</button></span>
        </div>`).join('');
  }

  async function loadCats() {
    categories = await api.get('admin/categories');
    drawCats();
  }

  async function loadItems() {
    wrap.innerHTML = loader();
    try {
      const data = await api.get('admin/items', { category_id: current || '', q, page });
      items = data.items;
      wrap.innerHTML = items.length ? `<table class="table"><thead><tr><th>Item</th><th>Category</th><th>Status</th><th>Price</th><th>Fans</th><th></th></tr></thead><tbody>
        ${items.map((i) => `<tr data-id="${i.id}">
          <td><div class="cell-user"><span class="item-thumb">${i.image ? `<img src="${esc(asset(i.image))}" alt="">` : '🎮'}</span><div><strong>${esc(i.name)}</strong><small class="muted">${esc([i.subtype, i.manufacturer].filter(Boolean).join(' · '))}</small></div></div></td>
          <td>${esc(i.category)}</td>
          <td><span class="tag">${esc(i.status)}</span></td>
          <td>${i.price !== null ? fmtMoney(i.price) : '<span class="muted">TBA</span>'}</td>
          <td>${i.favorites}</td>
          <td class="actions"><button class="btn btn-ghost btn-sm" data-act="edit-item">${icon('edit')} Edit</button><button class="icon-btn danger" data-act="del-item" title="Delete">${icon('trash')}</button></td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty small"><p>No items here yet.</p><button class="btn btn-primary btn-sm" data-act="add-item">${icon('plus')} Add item</button></div>`;
      pager($('.pager-wrap', el), data, (p) => { page = p; loadItems(); });
    } catch (err) {
      wrap.innerHTML = '';
      wrap.appendChild(errorState(err, loadItems));
    }
  }

  function catForm(c) {
    formModal({
      title: c ? 'Edit category' : 'Add category',
      size: 'md',
      fields: [
        { name: 'name', label: 'Name', required: true, placeholder: 'e.g. Motorcycles' },
        { name: 'icon', label: 'Icon (emoji)', placeholder: '🏍️', max: 16 },
        { name: 'description', label: 'Description', span: 2 },
        { name: 'sort_order', label: 'Order (lower = first)', type: 'number', default: 0 },
      ],
      values: c || {},
      onSubmit: async (data) => {
        if (c) data.id = c.id;
        categories = await api.post('admin/category-save', data);
        drawCats();
        toast('Category saved', 'success');
      },
    });
  }

  function itemForm(i) {
    if (!categories.length) return toast('Create a category first.', 'warn');
    formModal({
      title: i ? `Edit ${i.name}` : 'Add catalogue item',
      fields: [
        { name: 'name', label: 'Name', required: true },
        { name: 'category_id', label: 'Category', type: 'select', options: categories.map((c) => [c.id, `${c.icon || ''} ${c.name}`]) },
        { name: 'subtype', label: 'Type / class', placeholder: 'e.g. Sports, Pistol' },
        { name: 'manufacturer', label: 'Manufacturer / brand' },
        { name: 'price', label: 'Price (in-game $)', type: 'number', min: 0 },
        { name: 'status', label: 'Status', type: 'select', options: STATUSES.map((s) => [s, s]) },
        { name: 'description', label: 'Description', type: 'textarea', rows: 4, span: 2 },
        { name: 'stats', label: 'Stats (numbers 0-100 show as bars, text shows as a value)', type: 'stats', span: 2 },
        { name: 'image_url', label: 'Image link', type: 'url', placeholder: 'https://…' },
        { name: 'image', label: 'Or upload image', type: 'file', preview: true },
        { name: 'sort_order', label: 'Order', type: 'number', default: 0 },
        { name: 'remove_image', label: 'Remove current image', type: 'checkbox' },
      ],
      values: i ? { ...i, image_url: i.image && !i.image.startsWith('uploads/') ? i.image : '', image: i.image && i.image.startsWith('uploads/') ? i.image : '' }
        : { category_id: current || (categories[0] && categories[0].id), status: 'Confirmed', stats: [{ label: 'Top Speed', value: '' }] },
      onSubmit: async (data) => {
        if (i) data.id = i.id;
        await send('admin/item-save', { ...data, stats: JSON.stringify(data.stats || []) });
        toast('Item saved', 'success');
        await loadCats();
        loadItems();
      },
    });
  }

  el.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    const pick = e.target.closest('[data-cat]');
    if (!act && pick && catBox.contains(pick)) {
      current = Number(pick.dataset.cat);
      page = 1;
      drawCats();
      loadItems();
      return;
    }
    if (!act) return;
    try {
      if (act === 'add-cat') catForm(null);
      if (act === 'edit-cat') catForm(categories.find((c) => c.id === Number(e.target.closest('[data-id]').dataset.id)));
      if (act === 'del-cat') {
        const c = categories.find((x) => x.id === Number(e.target.closest('[data-id]').dataset.id));
        const force = c.items > 0;
        if (!(await confirmBox(force ? `Delete “${c.name}” AND its ${c.items} item(s)?` : `Delete “${c.name}”?`, { confirmText: 'Delete', danger: true }))) return;
        categories = await api.post('admin/category-delete', { id: c.id, force });
        if (current === c.id) current = 0;
        drawCats();
        loadItems();
      }
      if (act === 'add-item') itemForm(null);
      const row = e.target.closest('tr[data-id]');
      const item = row ? items.find((x) => x.id === Number(row.dataset.id)) : null;
      if (act === 'edit-item') itemForm(item);
      if (act === 'del-item') {
        if (!(await confirmBox(`Delete “${item.name}”?`, { confirmText: 'Delete', danger: true }))) return;
        await api.post('admin/item-delete', { id: item.id });
        await loadCats();
        loadItems();
      }
    } catch (err) {
      toastError(err);
    }
  });
  $('.filter-bar input', el).addEventListener('input', debounce((e) => { q = e.target.value.trim(); page = 1; loadItems(); }, 300));
  try {
    await loadCats();
  } catch (err) {
    catBox.innerHTML = `<p class="muted">${esc(err.message)}</p>`;
  }
  loadItems();
}
