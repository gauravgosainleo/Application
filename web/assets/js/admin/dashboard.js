// Admin dashboard: key numbers, pending work and quick actions.
import { api } from '../core/api.js';
import { esc, icon, avatar, timeAgo, fmtDateTime, loader, errorState } from '../core/ui.js';
import { setPending, pageHead } from './admin.js';

export async function render({ el }) {
  el.innerHTML = pageHead('grid', 'Dashboard', 'Everything happening in G VI Fan Hub at a glance.') + loader();
  let s;
  try {
    s = await api.get('admin/stats');
  } catch (err) {
    el.innerHTML = '';
    el.appendChild(errorState(err, () => render({ el })));
    return;
  }
  setPending(s.pending);
  const tile = (ic, label, value, sub = '', href = '') => `
    <${href ? `a href="${href}"` : 'div'} class="stat-tile card">
      <span class="st-icon">${icon(ic)}</span>
      <span class="st-value">${Number(value).toLocaleString()}</span>
      <span class="st-label">${label}</span>
      ${sub ? `<span class="st-sub">${sub}</span>` : ''}
    </${href ? 'a' : 'div'}>`;
  const pend = s.pending;
  el.innerHTML = pageHead('grid', 'Dashboard', 'Everything happening in G VI Fan Hub at a glance.', `<a class="btn btn-primary" href="#/news">${icon('plus')} New article</a><a class="btn btn-ghost" href="#/polls">${icon('poll')} New poll</a>`) + `
    ${pend.news || pend.gallery || pend.reports ? `
    <div class="todo card">
      <strong>${icon('alert')} Needs your attention</strong>
      <div class="todo-items">
        ${pend.news ? `<a href="#/news" class="todo-item"><b>${pend.news}</b> news stories waiting for approval</a>` : ''}
        ${pend.gallery ? `<a href="#/gallery" class="todo-item"><b>${pend.gallery}</b> gallery uploads to review</a>` : ''}
        ${pend.reports ? `<a href="#/reports" class="todo-item"><b>${pend.reports}</b> open reports</a>` : ''}
      </div>
    </div>` : ''}
    <div class="stat-grid">
      ${tile('users', 'Players', s.users, `+${s.users_week} this week`, '#/users')}
      ${tile('zap', 'Online now', s.online, '', '#/users?filter=online')}
      ${tile('ban', 'Blocked accounts', s.banned, '', '#/users?filter=banned')}
      ${tile('news', 'Published news', s.news_published, `${pend.news} pending`, '#/news')}
      ${tile('discuss', 'Discussions', s.discussions, `${s.comments} comments`, '#/discussions')}
      ${tile('chat', 'Messages today', s.messages_today)}
      ${tile('pin', 'Map markers', s.markers)}
      ${tile('poll', 'Open polls', s.polls_open, '', '#/polls')}
      ${tile('calendar', 'Upcoming events', s.events_upcoming, '', '#/events')}
      ${tile('car', 'Catalogue items', s.catalogue_items, '', '#/catalogue')}
    </div>
    <div class="dash-grid">
      <section class="card">
        <h3>${icon('refresh')} News auto mode</h3>
        <p>${s.news_auto.enabled ? `<span class="tag tag-ok">ON</span> Checking trusted sources every <strong>${s.news_auto.interval} hours</strong>.` : '<span class="tag">OFF</span> New stories are only added manually.'}</p>
        <p class="muted small">Last check: ${s.news_auto.last_run_at ? esc(fmtDateTime(s.news_auto.last_run_at)) + ' (' + timeAgo(s.news_auto.last_run_at) + ')' : 'never'}${s.news_auto.next_run_at ? ` · next: ${esc(fmtDateTime(s.news_auto.next_run_at))}` : ''}</p>
        <a class="btn btn-ghost btn-sm" href="#/news-auto">${icon('settings')} Configure</a>
      </section>
      <section class="card">
        <h3>${icon('userPlus')} Newest players</h3>
        <div class="mini-list">${s.recent_users.map((u) => `
          <a class="mini-row" href="#/users?q=${encodeURIComponent(u.email)}">${avatar(u, 'sm', { presence: false })}<span><strong>${esc(u.name)}</strong><small class="muted">@${esc(u.username)} · ${esc(u.email)}</small></span><small class="muted">${timeAgo(u.created_at)}</small></a>`).join('') || '<p class="muted">No players yet.</p>'}</div>
      </section>
      <section class="card">
        <h3>${icon('clock')} Countdown</h3>
        <p>Release set to <strong>${esc(s.release_at)}</strong>${/(Z|[+-]\d\d:\d\d)$/.test(s.release_at) ? '' : ' (midnight in each player\'s own time zone)'}.</p>
        <a class="btn btn-ghost btn-sm" href="#/settings">${icon('edit')} Change</a>
      </section>
    </div>`;
}
