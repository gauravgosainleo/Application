// Full notifications page (used on phones and via "View all").
import { api } from '../core/api.js';
import { state, setState } from '../core/state.js';
import { $, icon, toastError, loader, errorState, emptyState, confirmBox } from '../core/ui.js';
import { navigate } from '../core/router.js';
import { notifItem } from '../shell.js';
import { pageHead } from '../core/gvi-ui.js';

export async function render({ el }) {
  el.innerHTML = `
    ${pageHead('bell', 'Notifications', 'Friend requests, replies, rewards and announcements.',
      `<button class="gvi-btn ghost sm" data-act="read">${icon('check')}Mark all read</button><button class="gvi-btn ghost sm" data-act="clear">${icon('trash')}Clear</button>`)}
    <div class="gvi-panel flush notif-page">${loader()}</div>
    <div class="load-more-wrap"></div>`;
  const box = $('.notif-page', el);
  const more = $('.load-more-wrap', el);
  let items = [];

  async function load(beforeId) {
    try {
      const data = await api.get('notifications/list', { before_id: beforeId || '' });
      items = beforeId ? items.concat(data.items) : data.items;
      box.innerHTML = items.length ? items.map(notifItem).join('') : emptyState('bell', 'You are all caught up', 'Friend requests, replies, rewards and announcements will show up here.');
      more.innerHTML = data.has_more ? `<button class="gvi-btn ghost">${icon('refresh')}Load older</button>` : '';
      const b = $('button', more);
      if (b) b.addEventListener('click', () => load(items[items.length - 1].id));
      setState({ counts: { ...state.counts, notifications: data.unread } });
    } catch (err) {
      box.innerHTML = '';
      box.appendChild(errorState(err, () => load()));
    }
  }

  el.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    try {
      if (act === 'read') {
        const r = await api.post('notifications/read', { all: true });
        setState({ counts: { ...state.counts, notifications: r.unread } });
        load();
        return;
      }
      if (act === 'clear') {
        if (!(await confirmBox('Delete all notifications?', { confirmText: 'Clear all', danger: true }))) return;
        await api.post('notifications/clear');
        setState({ counts: { ...state.counts, notifications: 0 } });
        load();
        return;
      }
    } catch (err) {
      toastError(err);
      return;
    }
    const item = e.target.closest('.notif');
    if (!item) return;
    if (item.classList.contains('unread')) {
      item.classList.remove('unread');
      api.post('notifications/read', { id: item.dataset.id }).then((r) => setState({ counts: { ...state.counts, notifications: r.unread } })).catch(() => {});
    }
    const link = item.dataset.link;
    if (link) {
      if (link.startsWith('#')) navigate(link);
      else window.open(link, '_blank', 'noopener');
    }
  });
  load();
}
