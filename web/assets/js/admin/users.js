// Admin: players - search, details, block for X days, unblock, bonus XP, delete.
import { api } from '../core/api.js';
import { esc, $, $$, icon, avatar, timeAgo, fmtDateTime, fmtDate, loader, errorState, openModal, confirmBox, promptBox, toast, toastError, debounce } from '../core/ui.js';
import { setQuery } from '../core/router.js';
import { pageHead, pager, banDialog } from './admin.js';

export async function render({ el, query }) {
  let filter = query.filter || 'all';
  let q = query.q || '';
  let page = 1;
  el.innerHTML = pageHead('users', 'Users & bans', 'Search players, view details and block accounts for a number of days.') + `
    <div class="filter-bar">
      <div class="search-input">${icon('search')}<input type="search" placeholder="Name, @username or email…" value="${esc(q)}"></div>
      <div class="tabs small">${[['all', 'All'], ['active', 'Active'], ['online', 'Online'], ['banned', 'Blocked']].map(([k, l]) => `<button class="tab ${k === filter ? 'active' : ''}" data-f="${k}">${l}</button>`).join('')}</div>
    </div>
    <div class="table-wrap card"></div>
    <div class="pager-wrap"></div>`;
  const wrap = $('.table-wrap', el);

  async function load() {
    wrap.innerHTML = loader();
    try {
      const data = await api.get('admin/users', { q, filter, page });
      wrap.innerHTML = data.items.length ? `
        <table class="table">
          <thead><tr><th>Player</th><th>Email</th><th>Level</th><th>Joined</th><th>Last seen</th><th>Status</th><th></th></tr></thead>
          <tbody>${data.items.map((u) => `
            <tr data-id="${u.id}">
              <td><div class="cell-user">${avatar({ ...u, presence: u.online ? 'online' : null }, 'sm')}<div><strong>${esc(u.name)}</strong><small class="muted">@${esc(u.username)}</small></div></div></td>
              <td class="muted">${esc(u.email)}</td>
              <td>LV ${u.level}<small class="muted block">${u.xp.toLocaleString()} XP</small></td>
              <td class="muted">${esc(fmtDate(u.created_at))}</td>
              <td class="muted">${u.online ? '<span class="tag tag-ok">Online</span>' : esc(timeAgo(u.last_seen_at) || '—')}</td>
              <td>${u.banned ? `<span class="tag tag-danger">${icon('ban')} ${u.ban.permanent ? 'Permanently blocked' : 'Blocked until ' + esc(fmtDateTime(u.ban.until))}</span>` : '<span class="tag tag-ok">Active</span>'}</td>
              <td class="actions">
                <button class="btn btn-ghost btn-sm" data-act="view">${icon('eye')} View</button>
                ${u.banned ? `<button class="btn btn-ghost btn-sm" data-act="unban">${icon('check')} Unblock</button>` : `<button class="btn btn-danger btn-sm" data-act="ban">${icon('ban')} Block</button>`}
              </td>
            </tr>`).join('')}</tbody>
        </table>` : `<div class="empty small"><p>No players found.</p></div>`;
      pager($('.pager-wrap', el), data, (p) => { page = p; load(); });
      wrap.__items = data.items;
    } catch (err) {
      wrap.innerHTML = '';
      wrap.appendChild(errorState(err, load));
    }
  }

  async function ban(u) {
    const r = await banDialog(u.name);
    if (!r) return;
    try {
      const res = await api.post('admin/ban', { user_id: u.id, days: r.days, reason: r.reason });
      toast(res.permanent ? `${u.name} is permanently blocked.` : `${u.name} is blocked until ${fmtDateTime(res.banned_until)}.`, 'success');
      load();
    } catch (err) {
      toastError(err);
    }
  }

  async function details(id) {
    const m = openModal({ title: 'Player details', size: 'lg', body: loader() });
    try {
      const { user: u, bans, discussions } = await api.get('admin/user', { id });
      m.body.innerHTML = `
        <div class="user-detail">
          <div class="ud-head">${avatar({ ...u, presence: u.online ? 'online' : null }, 'xl')}
            <div><h2>${esc(u.name)}</h2><p class="muted">@${esc(u.username)} · ${esc(u.email)}</p>
              <p>${u.banned ? `<span class="tag tag-danger">${icon('ban')} ${u.ban.permanent ? 'Permanently blocked' : 'Blocked until ' + esc(fmtDateTime(u.ban.until))}</span> <span class="muted small">Reason: ${esc(u.ban.reason)}</span>` : '<span class="tag tag-ok">Active</span>'}</p></div></div>
          <div class="kv-grid">
            <div><span>Level</span><b>${u.level} (${u.xp.toLocaleString()} XP)</b></div>
            <div><span>Joined</span><b>${esc(fmtDateTime(u.created_at))}</b></div>
            <div><span>Last login</span><b>${esc(fmtDateTime(u.last_login_at) || '—')}</b></div>
            <div><span>Last seen</span><b>${esc(timeAgo(u.last_seen_at) || '—')}</b></div>
            <div><span>Friends</span><b>${u.stats.friends}</b></div>
            <div><span>Discussions</span><b>${u.stats.discussions}</b></div>
            <div><span>Comments</span><b>${u.stats.comments}</b></div>
            <div><span>2-step login</span><b>${u.two_step ? 'On' : 'Off'}</b></div>
          </div>
          <h4>Block history</h4>
          ${bans.length ? `<table class="table small"><thead><tr><th>Date</th><th>Length</th><th>Reason</th><th>Context</th><th>Status</th></tr></thead><tbody>
            ${bans.map((b) => `<tr><td>${esc(fmtDateTime(b.created_at))}</td><td>${b.days ? b.days + ' days' : 'Permanent'}</td><td>${esc(b.reason)}</td><td class="muted">${esc(b.context_ref || b.context || '')}</td><td>${b.lifted_at ? `<span class="muted">Ended ${esc(fmtDate(b.lifted_at))}</span>` : '<span class="tag tag-danger">Active</span>'}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">No previous blocks.</p>'}
          <h4>Recent discussions</h4>
          ${discussions.length ? `<ul class="plain-list">${discussions.map((d) => `<li><a href="#/discussions?q=${encodeURIComponent(d.title)}">${esc(d.title)}</a> <span class="muted small">· ${timeAgo(d.created_at)} · ${d.comments_count} comments</span></li>`).join('')}</ul>` : '<p class="muted">None.</p>'}
          <div class="row gap wrap ud-actions">
            ${u.banned ? `<button class="btn btn-ghost" data-u="unban">${icon('check')} Unblock</button>` : `<button class="btn btn-danger" data-u="ban">${icon('ban')} Block for X days</button>`}
            <button class="btn btn-ghost" data-u="xp">${icon('zap')} Give bonus XP</button>
            <button class="btn btn-ghost danger" data-u="delete">${icon('trash')} Delete account</button>
          </div>
        </div>`;
      m.body.addEventListener('click', async (e) => {
        const act = e.target.closest('[data-u]')?.dataset.u;
        if (!act) return;
        if (e.target.closest('a')) m.close();
        try {
          if (act === 'ban') { m.close(); await ban(u); }
          if (act === 'unban') {
            await api.post('admin/unban', { user_id: u.id });
            toast(`${u.name} is unblocked.`, 'success');
            m.close();
            load();
          }
          if (act === 'xp') {
            const r = await promptBox({ title: 'Give bonus XP', label: `XP for ${u.name} (use a negative number to remove XP)`, value: '100', confirmText: 'Give XP' });
            if (!r || !r.value) return;
            const res = await api.post('admin/user-xp', { user_id: u.id, amount: Number(r.value) });
            toast(`${u.name} now has ${res.xp.toLocaleString()} XP.`, 'success');
            m.close();
            load();
          }
          if (act === 'delete') {
            if (!(await confirmBox(`Permanently delete ${u.name}'s account and all their content?`, { confirmText: 'Delete account', danger: true }))) return;
            await api.post('admin/user-delete', { user_id: u.id });
            toast('Account deleted.', 'success');
            m.close();
            load();
          }
        } catch (err) {
          toastError(err);
        }
      });
      m.body.addEventListener('click', (e) => { if (e.target.closest('a')) m.close(); });
    } catch (err) {
      m.body.innerHTML = '';
      m.body.appendChild(errorState(err));
    }
  }

  wrap.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    const row = e.target.closest('tr[data-id]');
    if (!act || !row) return;
    const u = wrap.__items.find((x) => x.id === Number(row.dataset.id));
    if (act === 'view') details(u.id);
    if (act === 'ban') ban(u);
    if (act === 'unban') {
      try {
        await api.post('admin/unban', { user_id: u.id });
        toast(`${u.name} is unblocked.`, 'success');
        load();
      } catch (err) {
        toastError(err);
      }
    }
  });
  $$('[data-f]', el).forEach((t) => t.addEventListener('click', () => {
    filter = t.dataset.f;
    page = 1;
    $$('[data-f]', el).forEach((x) => x.classList.toggle('active', x === t));
    setQuery({ filter });
    load();
  }));
  $('.filter-bar input', el).addEventListener('input', debounce((e) => { q = e.target.value.trim(); page = 1; setQuery({ q }); load(); }, 300));
  load();
}
