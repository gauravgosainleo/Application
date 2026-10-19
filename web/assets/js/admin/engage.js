// Admin: polls, events and rewards.
import { api } from '../core/api.js';
import { esc, $, icon, asset, timeUntil, fmtDateTime, loader, errorState, confirmBox, toast, toastError, openModal, promptBox } from '../core/ui.js';
import { pageHead, formModal, send } from './admin.js';

export async function render(ctx) {
  if (ctx.path === '/polls') return polls(ctx);
  if (ctx.path === '/events') return events(ctx);
  return rewards(ctx);
}

/* ================================================================== POLLS */
async function polls({ el }) {
  let list = [];
  const form = (p) => formModal({
    title: p ? 'Edit poll' : 'Raise a poll',
    fields: [
      { name: 'question', label: 'Question', required: true, span: 2, max: 255 },
      { name: 'description', label: 'Description (optional)', type: 'textarea', rows: 2, span: 2 },
      { name: 'options', label: 'Answer options (2-12)', type: 'list', span: 2, hint: p && p.voters ? 'Options that already have votes cannot be removed.' : '' },
      { name: 'is_multiple', label: 'Players can pick more than one option', type: 'checkbox' },
      { name: 'results_visibility', label: 'Show results', type: 'select', options: [['after_vote', 'After the player votes'], ['always', 'Always'], ['after_close', 'Only when the poll closes']] },
      { name: 'ends_at', label: 'Ends at (optional)', type: 'datetime' },
      { name: 'status', label: 'Status', type: 'select', options: [['open', 'Open'], ['closed', 'Closed']] },
      ...(p ? [] : [{ name: 'notify', label: 'Notify all players about this poll', type: 'checkbox', default: true }]),
    ],
    values: p ? { ...p } : { options: ['', ''], results_visibility: 'after_vote', status: 'open', notify: true },
    submitText: p ? 'Save poll' : 'Publish poll',
    onSubmit: async (data) => {
      if (p) data.id = p.id;
      if ((data.options || []).length < 2) throw new Error('Add at least two options.');
      await api.post('admin/poll-save', data);
      toast(p ? 'Poll updated' : 'Poll published!', 'success');
      load();
    },
  });
  el.innerHTML = pageHead('poll', 'Polls', 'Raise polls for the community and follow the results live.', `<button class="btn btn-primary" data-act="add">${icon('plus')} Raise a poll</button>`) + '<div class="polls-admin"></div>';
  const box = $('.polls-admin', el);
  async function load() {
    box.innerHTML = loader();
    try {
      list = await api.get('admin/polls');
      box.innerHTML = list.length ? `<div class="admin-grid wide">${list.map((p) => {
        const total = p.options.reduce((s, o) => s + o.votes, 0);
        return `<article class="card poll-admin" data-id="${p.id}">
          <div class="row gap wrap"><span class="tag ${!p.is_ended ? 'tag-ok' : ''}">${p.is_ended ? 'Closed' : 'Open'}</span>${p.is_multiple ? '<span class="tag">Multiple choice</span>' : ''}<span class="muted small">${p.voters} voter${p.voters === 1 ? '' : 's'}${p.ends_at ? ` · ${p.is_ended ? 'ended' : 'ends'} ${esc(fmtDateTime(p.ends_at))}` : ''}</span></div>
          <h3>${esc(p.question)}</h3>
          <div class="poll-results">${p.options.map((o) => {
            const pct = total ? Math.round((o.votes / total) * 1000) / 10 : 0;
            return `<div class="pr-row"><div class="row-between small"><span>${esc(o.label)}</span><b>${o.votes} · ${pct}%</b></div><div class="stat-track"><span style="width:${pct}%"></span></div></div>`;
          }).join('')}</div>
          <div class="row gap wrap">
            <button class="btn btn-ghost btn-sm" data-act="edit">${icon('edit')} Edit</button>
            <button class="btn btn-ghost btn-sm" data-act="toggle">${p.status === 'open' ? `${icon('lock')} Close` : `${icon('zap')} Reopen`}</button>
            <button class="icon-btn danger" data-act="delete" title="Delete">${icon('trash')}</button>
          </div>
        </article>`;
      }).join('')}</div>` : `<div class="empty card">${icon('poll', 'empty-icon')}<h3>No polls yet</h3></div>`;
    } catch (err) {
      box.innerHTML = '';
      box.appendChild(errorState(err, load));
    }
  }
  el.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    if (act === 'add') return form(null);
    const p = list.find((x) => x.id === Number(e.target.closest('[data-id]')?.dataset.id));
    if (!p) return;
    try {
      if (act === 'edit') form(p);
      if (act === 'toggle') {
        await api.post('admin/poll-status', { id: p.id, status: p.status === 'open' ? 'closed' : 'open' });
        load();
      }
      if (act === 'delete') {
        if (!(await confirmBox(`Delete the poll “${p.question}” and all votes?`, { confirmText: 'Delete', danger: true }))) return;
        await api.post('admin/poll-delete', { id: p.id });
        load();
      }
    } catch (err) {
      toastError(err);
    }
  });
  load();
}

/* ================================================================= EVENTS */
async function events({ el }) {
  let list = [];
  let rewardsList = [];
  const form = (ev) => formModal({
    title: ev ? 'Edit event' : 'Create event',
    fields: [
      { name: 'title', label: 'Title', required: true, span: 2 },
      { name: 'description', label: 'Description', type: 'textarea', rows: 4, span: 2 },
      { name: 'event_type', label: 'Type', type: 'select', options: ['Community', 'Contest', 'Launch', 'Livestream', 'Meetup'].map((t) => [t, t]) },
      { name: 'location', label: 'Location', placeholder: 'e.g. Discussions, Interactive Map, Discord' },
      { name: 'starts_at', label: 'Starts', type: 'datetime', required: true },
      { name: 'ends_at', label: 'Ends (optional)', type: 'datetime' },
      { name: 'reward_xp', label: 'Reward XP', type: 'number', min: 0, default: 50 },
      { name: 'reward_id', label: 'Reward badge (optional)', type: 'select', options: [['0', '— none —'], ...rewardsList.map((r) => [r.id, `${r.icon && !/^(uploads|https?:)/.test(r.icon) ? r.icon + ' ' : ''}${r.title}${r.is_event_only ? ' (event only)' : ''}`])] },
      { name: 'max_participants', label: 'Max participants (0 = unlimited)', type: 'number', min: 0, default: 0 },
      { name: 'banner', label: 'Banner image', type: 'file', preview: true },
      { name: 'is_published', label: 'Visible to players', type: 'checkbox', default: true },
      ...(ev ? [{ name: 'remove_banner', label: 'Remove current banner', type: 'checkbox' }] : [{ name: 'notify', label: 'Notify all players', type: 'checkbox', default: true }]),
    ],
    values: ev ? { ...ev, reward_id: ev.reward_id || '0', max_participants: ev.max_participants || 0 } : { reward_xp: 50, is_published: true, notify: true, event_type: 'Community' },
    onSubmit: async (data) => {
      if (ev) data.id = ev.id;
      await send('admin/event-save', data);
      toast('Event saved', 'success');
      load();
    },
  });
  el.innerHTML = pageHead('calendar', 'Events', 'Create community events with XP and badge rewards.', `<button class="btn btn-primary" data-act="add">${icon('plus')} Create event</button>`) + '<div class="events-admin"></div>';
  const box = $('.events-admin', el);
  async function load() {
    box.innerHTML = loader();
    try {
      [list, rewardsList] = await Promise.all([api.get('admin/events'), api.get('admin/rewards')]);
      const now = Date.now();
      box.innerHTML = list.length ? `<div class="table-wrap card"><table class="table"><thead><tr><th>Event</th><th>When</th><th>Reward</th><th>Players</th><th></th></tr></thead><tbody>
        ${list.map((ev) => {
          const start = new Date(ev.starts_at).getTime();
          const end = ev.ends_at ? new Date(ev.ends_at).getTime() : null;
          const phase = start > now ? `<span class="tag tag-info">Starts ${timeUntil(ev.starts_at)}</span>` : end && end <= now ? '<span class="tag">Ended</span>' : '<span class="tag tag-live">LIVE</span>';
          return `<tr data-id="${ev.id}">
            <td><div class="cell-user"><span class="item-thumb">${ev.banner ? `<img src="${esc(asset(ev.banner))}" alt="">` : '📅'}</span><div><strong>${esc(ev.title)}</strong><small class="muted">${esc(ev.event_type)}${ev.location ? ' · ' + esc(ev.location) : ''}${ev.is_published ? '' : ' · hidden'}</small></div></div></td>
            <td class="small">${phase}<small class="muted block">${esc(fmtDateTime(ev.starts_at))}${ev.ends_at ? ' → ' + esc(fmtDateTime(ev.ends_at)) : ''}</small></td>
            <td class="small">${ev.reward_xp ? `+${ev.reward_xp} XP` : ''}${ev.reward_title ? `<small class="block">${esc(ev.reward_title)}</small>` : ''}</td>
            <td class="small">${ev.participants}${ev.max_participants ? ' / ' + ev.max_participants : ''} joined<small class="muted block">${ev.claimed} claimed</small></td>
            <td class="actions"><button class="btn btn-ghost btn-sm" data-act="people">${icon('users')}</button><button class="btn btn-ghost btn-sm" data-act="edit">${icon('edit')} Edit</button><button class="icon-btn danger" data-act="delete">${icon('trash')}</button></td>
          </tr>`;
        }).join('')}</tbody></table></div>` : `<div class="empty card">${icon('calendar', 'empty-icon')}<h3>No events yet</h3></div>`;
    } catch (err) {
      box.innerHTML = '';
      box.appendChild(errorState(err, load));
    }
  }
  el.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    if (act === 'add') return form(null);
    const ev = list.find((x) => x.id === Number(e.target.closest('[data-id]')?.dataset.id));
    if (!ev) return;
    try {
      if (act === 'edit') form(ev);
      if (act === 'delete') {
        if (!(await confirmBox(`Delete “${ev.title}”?`, { confirmText: 'Delete', danger: true }))) return;
        await api.post('admin/event-delete', { id: ev.id });
        load();
      }
      if (act === 'people') {
        const people = await api.get('admin/event-participants', { id: ev.id });
        openModal({
          title: `${ev.title} — participants`,
          size: 'md',
          body: people.length ? `<table class="table small"><thead><tr><th>Player</th><th>Joined</th><th>Reward</th></tr></thead><tbody>${people.map((p) => `<tr><td><strong>${esc(p.name)}</strong><small class="muted block">@${esc(p.username)} · ${esc(p.email)}</small></td><td>${esc(fmtDateTime(p.joined_at))}</td><td>${p.claimed_at ? '<span class="tag tag-ok">Claimed</span>' : '—'}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">Nobody joined yet.</p>',
        });
      }
    } catch (err) {
      toastError(err);
    }
  });
  load();
}

/* ================================================================ REWARDS */
async function rewards({ el }) {
  let list = [];
  const form = (r) => formModal({
    title: r ? 'Edit reward' : 'Create reward',
    fields: [
      { name: 'title', label: 'Title', required: true, hint: 'For “Profile title” rewards this text is shown next to the player name.' },
      { name: 'reward_type', label: 'Type', type: 'select', options: [['badge', 'Badge'], ['title', 'Profile title'], ['frame', 'Avatar frame'], ['code', 'Promo code']] },
      { name: 'description', label: 'Description', span: 2 },
      { name: 'icon', label: 'Icon (emoji)', placeholder: '🏆', max: 16 },
      { name: 'icon_file', label: 'Or upload an icon image', type: 'file' },
      { name: 'style', label: 'Colour / frame style', type: 'select', options: [['', 'Default'], ['pink', 'Pink'], ['gold', 'Gold'], ['teal', 'Teal'], ['neon', 'Neon'], ['sunset', 'Sunset']] },
      { name: 'required_level', label: 'Required level', type: 'number', min: 1, default: 1 },
      { name: 'secret_code', label: 'Promo code (type “Promo code” only)', hint: 'Revealed to the player after claiming.' },
      { name: 'quantity', label: 'How many can be claimed (0 = unlimited)', type: 'number', min: 0, default: 0 },
      { name: 'expires_at', label: 'Expires (optional)', type: 'datetime' },
      { name: 'is_event_only', label: 'Only obtainable through an event', type: 'checkbox' },
      { name: 'is_active', label: 'Active', type: 'checkbox', default: true },
      ...(r ? [] : [{ name: 'notify', label: 'Notify all players', type: 'checkbox' }]),
    ],
    values: r ? { ...r, icon: r.icon && /^(uploads|https?:)/.test(r.icon) ? '' : r.icon, quantity: r.quantity || 0 } : { reward_type: 'badge', required_level: 1, is_active: true },
    onSubmit: async (data) => {
      if (r) data.id = r.id;
      await send('admin/reward-save', data);
      toast('Reward saved', 'success');
      load();
    },
  });
  const iconHTML = (r) => (r.icon && /^(uploads|https?:)/.test(r.icon) ? `<img src="${esc(asset(r.icon))}" alt="">` : esc(r.icon || '🏅'));
  el.innerHTML = pageHead('gift', 'Rewards', 'Badges, profile titles, avatar frames and promo codes players unlock by levelling up or joining events.', `<button class="btn btn-primary" data-act="add">${icon('plus')} Create reward</button>`) + '<div class="rewards-admin"></div>';
  const box = $('.rewards-admin', el);
  async function load() {
    box.innerHTML = loader();
    try {
      list = await api.get('admin/rewards');
      box.innerHTML = list.length ? `<div class="table-wrap card"><table class="table"><thead><tr><th>Reward</th><th>Type</th><th>Unlock</th><th>Claimed</th><th></th></tr></thead><tbody>
        ${list.map((r) => `<tr data-id="${r.id}">
          <td><div class="cell-user"><span class="reward-icon style-${esc(r.style || 'default')}">${iconHTML(r)}</span><div><strong>${esc(r.title)}</strong><small class="muted">${esc(r.description || '')}</small></div></div></td>
          <td>${esc({ badge: 'Badge', title: 'Title', frame: 'Frame', code: 'Promo code' }[r.reward_type])}${r.secret_code ? `<small class="muted block">code: ${esc(r.secret_code)}</small>` : ''}</td>
          <td class="small">${r.is_event_only ? 'Event only' : `Level ${r.required_level}`}${r.expires_at ? `<small class="muted block">until ${esc(fmtDateTime(r.expires_at))}</small>` : ''}${r.is_active ? '' : '<small class="muted block">inactive</small>'}</td>
          <td class="small">${r.claimed_count}${r.quantity ? ' / ' + r.quantity : ''}</td>
          <td class="actions"><button class="btn btn-ghost btn-sm" data-act="claims">${icon('users')}</button><button class="btn btn-ghost btn-sm" data-act="grant">${icon('gift')} Give</button><button class="btn btn-ghost btn-sm" data-act="edit">${icon('edit')}</button><button class="icon-btn danger" data-act="delete">${icon('trash')}</button></td>
        </tr>`).join('')}</tbody></table></div>` : `<div class="empty card">${icon('gift', 'empty-icon')}<h3>No rewards yet</h3></div>`;
    } catch (err) {
      box.innerHTML = '';
      box.appendChild(errorState(err, load));
    }
  }
  el.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    if (act === 'add') return form(null);
    const r = list.find((x) => x.id === Number(e.target.closest('[data-id]')?.dataset.id));
    if (!r) return;
    try {
      if (act === 'edit') form(r);
      if (act === 'delete') {
        if (!(await confirmBox(`Delete “${r.title}”? Players who own it will lose it.`, { confirmText: 'Delete', danger: true }))) return;
        await api.post('admin/reward-delete', { id: r.id });
        load();
      }
      if (act === 'grant') {
        const res = await promptBox({ title: `Give “${r.title}”`, label: 'Player username or email', confirmText: 'Give reward' });
        if (!res || !res.value) return;
        await api.post('admin/reward-grant', { reward_id: r.id, username: res.value });
        toast('Reward given', 'success');
        load();
      }
      if (act === 'claims') {
        const claims = await api.get('admin/reward-claims', { id: r.id });
        openModal({
          title: `${r.title} — owners`,
          size: 'md',
          body: claims.length ? `<table class="table small"><thead><tr><th>Player</th><th>When</th><th>How</th></tr></thead><tbody>${claims.map((c) => `<tr><td><strong>${esc(c.name)}</strong><small class="muted block">@${esc(c.username)}</small></td><td>${esc(fmtDateTime(c.claimed_at))}</td><td>${esc(c.source || '')}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">Nobody has this reward yet.</p>',
        });
      }
    } catch (err) {
      toastError(err);
    }
  });
  load();
}
