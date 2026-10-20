// Events & Rewards: event list (join/claim/leave), level ring, badges & rewards (claim, equip, codes) and XP history.
import { api } from '../core/api.js';
import { esc, $, $$, icon, asset, avatar, levelPill, timeAgo, timeUntil, fmtDateTime, openModal, toast, toastError, setBusy, emptyState, errorState, loader, richText, copyText } from '../core/ui.js';
import { refreshMe } from '../app.js';
import { dateBlock, glare, tint } from '../core/gvi-ui.js';
import { pegHead } from './polls.js';

const TYPE_ICONS = { Launch: 'zap', Contest: 'trophy', Community: 'users', Livestream: 'film', Meetup: 'pin' };
const REWARD_TYPES = { badge: 'Badge', title: 'Profile title', frame: 'Avatar frame', code: 'Promo code' };

export function rewardIcon(r, size = '') {
  if (r.icon && /^(uploads\/|https?:)/.test(r.icon)) return `<span class="reward-icon ${size} style-${esc(r.style || 'default')}"><img src="${esc(asset(r.icon))}" alt=""></span>`;
  return `<span class="reward-icon ${size} style-${esc(r.style || 'default')}">${esc(r.icon || '🏅')}</span>`;
}

export async function render({ el, query }) {
  el.innerHTML = `${pegHead('events')}
    <div class="gvi-ev-layout">
      <div class="ev-main-col">
        <div class="gvi-chip-row scroll ev-filters">${FILTERS.map(([k, l]) => `<button class="gvi-chip ${k === 'all' ? 'is-active' : ''}" data-f="${k}">${l}</button>`).join('')}</div>
        <div class="events-list">${loader()}</div>
      </div>
      <aside class="ev-side">
        <section class="gvi-panel side-card level-panel">${loader()}</section>
        <section class="gvi-panel side-card badges-panel" id="rewards"><div class="side-title">${icon('gift')}Badges &amp; rewards</div><div class="badges-body">${loader()}</div></section>
      </aside>
    </div>`;
  const reloadRewards = () => renderRewards($('.level-panel', el), $('.badges-body', el));
  renderEvents($('.ev-main-col', el), reloadRewards);
  await reloadRewards();
  if (query.tab === 'rewards' || query.tab === 'xp') {
    const target = query.tab === 'xp' ? $('.level-panel', el) : $('.badges-panel', el);
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (query.tab === 'xp') xpModal();
  }
}

/* ------------------------------------------------------------ events */
const FILTERS = [['all', 'All'], ['live', 'Live'], ['upcoming', 'Upcoming'], ['mine', 'Joined'], ['past', 'Past']];

function phaseBadge(e) {
  if (e.phase === 'live') return `<span class="tag tag-live"><span class="live-dot"></span> LIVE</span>`;
  if (e.phase === 'upcoming') return `<span class="tag tag-info">${icon('clock')} Starts ${timeUntil(e.starts_at)}</span>`;
  return `<span class="tag">${icon('check')} Ended</span>`;
}

function phaseText(e) {
  if (e.phase === 'live') return '<span class="ev-live"><span class="dot"></span>Live now</span>';
  if (e.phase === 'upcoming') return `Starts ${esc(timeUntil(e.starts_at))}`;
  return 'Ended';
}

function eventActions(e) {
  if (e.claimed) return `<span class="gvi-btn sm joined static">${icon('check')}Claimed</span>`;
  if (e.can_claim) return `<button class="gvi-btn sm" data-ev="claim">${icon('gift')}Claim</button>`;
  if (e.joined) return `<span class="gvi-btn sm joined static">Joined</span>`;
  if (e.phase === 'ended') return '<span class="ev-note">Ended</span>';
  if (e.is_full) return '<span class="ev-note">Full</span>';
  return `<button class="gvi-btn sm" data-ev="join">Join</button>`;
}

function eventCard(e) {
  const meta = [phaseText(e), `${e.participants}${e.max_participants ? ' / ' + e.max_participants : ''} joined`];
  if (e.reward_xp) meta.push(`+${e.reward_xp} XP`);
  if (e.reward) meta.push(esc(e.reward.title));
  return `<article class="gvi-event-row phase-${e.phase}" data-id="${e.id}" data-tilt="card">
    ${dateBlock(e.starts_at)}
    <button class="er-main" data-ev="details">
      <strong>${esc(e.title)}</strong>
      <small>${meta.join(' · ')}</small>
    </button>
    <div class="er-actions">${eventActions(e)}</div>
    ${glare}
  </article>`;
}

function renderEvents(col, onRewardChange) {
  let filter = 'all';
  const list = $('.events-list', col);
  let events = [];
  async function load() {
    list.innerHTML = loader();
    try {
      events = await api.get('events/list', { filter });
      list.innerHTML = events.length ? events.map(eventCard).join('') : emptyState('calendar', 'No events here', 'New community events are announced by the admins.');
    } catch (err) {
      list.innerHTML = '';
      list.appendChild(errorState(err, load));
    }
  }
  const replace = (card, ev) => {
    events = events.map((x) => (x.id === ev.id ? ev : x));
    card.outerHTML = eventCard(ev);
  };
  col.addEventListener('click', async (e) => {
    const f = e.target.closest('[data-f]');
    if (f) {
      filter = f.dataset.f;
      $$('[data-f]', col).forEach((x) => x.classList.toggle('is-active', x === f));
      return load();
    }
    const act = e.target.closest('[data-ev]')?.dataset.ev;
    if (!act) return;
    const card = e.target.closest('.gvi-event-row');
    const id = Number(card.dataset.id);
    if (act === 'details') {
      return eventDetails(id, async (ev, granted) => {
        replace($(`.gvi-event-row[data-id="${id}"]`, col), ev);
        if (granted) { celebrate(granted); refreshMe(); onRewardChange(); }
      });
    }
    const btn = e.target.closest('button');
    setBusy(btn, true);
    try {
      if (act === 'join') {
        const ev = await api.post('events/join', { event_id: id });
        replace(card, ev);
        toast(ev.can_claim ? 'Joined! Your reward is ready to claim.' : 'You joined the event!', 'success');
      } else if (act === 'claim') {
        const r = await api.post('events/claim', { event_id: id });
        replace(card, r.event);
        celebrate(r.granted);
        refreshMe();
        onRewardChange();
      }
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  });
  load();
}

function celebrate(granted) {
  openModal({
    size: 'sm',
    className: 'celebrate',
    body: `<div class="celebrate-box"><div class="confetti">🎉</div><h2>Reward claimed!</h2>
      ${granted.xp ? `<p class="big-xp">+${granted.xp} XP</p>` : ''}
      ${granted.reward ? `<div class="reward-chip big">${rewardIcon(granted.reward)} <span>Unlocked <strong>${esc(granted.reward.title)}</strong></span></div>` : ''}
      <a class="gvi-btn" href="#/events?tab=rewards">See my rewards</a></div>`,
  });
}

async function eventDetails(id, onChange) {
  const m = openModal({ size: 'lg', body: loader() });
  async function draw() {
    const { event: e, participants } = await api.get('events/get', { id });
    let actions = '';
    if (e.can_claim) actions = `<button class="gvi-btn" data-ed="claim">${icon('gift')}Claim reward</button>`;
    else if (e.claimed) actions = `<span class="gvi-btn joined static">${icon('check')}Reward claimed</span>`;
    else if (e.joined) actions = `<span class="gvi-btn joined static">${icon('check')}Joined</span>${e.phase !== 'ended' ? `<button class="gvi-btn ghost" data-ed="leave">Leave event</button>` : ''}`;
    else if (e.phase !== 'ended' && !e.is_full) actions = `<button class="gvi-btn" data-ed="join">${icon('plus')}Join event</button>`;
    m.body.innerHTML = `
      <div class="event-detail">
        <div class="ev-banner big">${e.banner ? `<img src="${esc(asset(e.banner))}" alt="">` : `<span class="ev-banner-ph">${icon(TYPE_ICONS[e.event_type] || 'calendar')}</span>`}<div class="ev-badges">${phaseBadge(e)}<span class="tag">${esc(e.event_type)}</span></div></div>
        <h2>${esc(e.title)}</h2>
        <div class="ev-meta">
          <span>${icon('calendar')} ${esc(fmtDateTime(e.starts_at))}${e.ends_at ? ' → ' + esc(fmtDateTime(e.ends_at)) : ''}</span>
          ${e.location ? `<span>${icon('pin')} ${esc(e.location)}</span>` : ''}
        </div>
        <div class="prose">${richText(e.description || '')}</div>
        <div class="ev-rewards">${e.reward_xp ? `<span class="xp-chip">${icon('zap')} +${e.reward_xp} XP</span>` : ''}${e.reward ? `<span class="reward-chip">${rewardIcon(e.reward, 'xs')} ${esc(e.reward.title)}</span>` : ''}</div>
        ${actions ? `<div class="ed-actions">${actions}</div>` : ''}
        <h4>${icon('users')} ${e.participants} participant${e.participants === 1 ? '' : 's'}</h4>
        <div class="avatar-cloud">${participants.map((u) => `<a href="#/profile/${u.id}" title="${esc(u.name)}">${avatar(u, 'sm', { presence: false })}</a>`).join('') || '<span class="muted small">Be the first to join!</span>'}</div>
      </div>`;
  }
  m.body.addEventListener('click', async (ev) => {
    if (ev.target.closest('a')) return m.close();
    const act = ev.target.closest('[data-ed]')?.dataset.ed;
    if (!act) return;
    const btn = ev.target.closest('button');
    setBusy(btn, true);
    try {
      if (act === 'claim') {
        const r = await api.post('events/claim', { event_id: id });
        m.close();
        onChange(r.event, r.granted);
        return;
      }
      const updated = await api.post(act === 'join' ? 'events/join' : 'events/leave', { event_id: id });
      onChange(updated);
      if (act === 'join') toast(updated.can_claim ? 'Joined! Your reward is ready to claim.' : 'You joined the event!', 'success');
      await draw();
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  });
  try {
    await draw();
  } catch (err) {
    m.body.innerHTML = '';
    m.body.appendChild(errorState(err));
  }
}

/* ----------------------------------------------------------- rewards */
function levelRing(lv, size = 74) {
  const pct = Math.round(lv.progress * 100);
  return `<span class="gvi-level-ring" style="--p:${pct};--s:${size}px"><b>${pct}%</b></span>`;
}

async function renderRewards(levelEl, badgesEl) {
  let data;
  try {
    data = await api.get('rewards/list');
  } catch (err) {
    badgesEl.innerHTML = '';
    badgesEl.appendChild(errorState(err, () => renderRewards(levelEl, badgesEl)));
    levelEl.innerHTML = '';
    return;
  }
  const lv = data.level;
  levelEl.innerHTML = `
    <div class="side-title">${icon('zap')}Level ${lv.level} · ${esc(lv.rank)}</div>
    <div class="lp-row">${levelRing(lv)}
      <div class="lp-text"><strong>${lv.xp.toLocaleString()} / ${lv.next_xp.toLocaleString()} XP</strong><small>${(lv.next_xp - lv.xp).toLocaleString()} XP to level ${lv.level + 1}</small>
        <button class="gvi-see-all" data-xp>How to earn · history${icon('chevronRight')}</button></div></div>`;
  const rewards = data.rewards;
  badgesEl.innerHTML = rewards.length ? `<div class="gvi-badge-grid">${rewards.map((r, i) => {
    const locked = !r.claimed;
    const equipped = (r.reward_type === 'title' && data.equipped.title_id === r.id) || (r.reward_type === 'frame' && data.equipped.frame_id === r.id);
    const ready = !r.claimed && r.eligible && r.available && !r.is_event_only;
    return `<button class="gvi-badge-tile ${locked ? 'locked' : 'owned'} ${ready ? 'ready' : ''}" data-rid="${r.id}" style="${tint(i)}" title="${esc(r.description || r.title)}">
      ${rewardIcon(r)}<strong>${esc(r.title)}</strong>
      ${ready ? '<em>Claim</em>' : equipped ? '<em class="on">Equipped</em>' : ''}
    </button>`;
  }).join('')}</div>` : '<p class="muted small">Admins will add rewards soon.</p>';

  levelEl.onclick = (e) => { if (e.target.closest('[data-xp]')) xpModal(); };
  badgesEl.onclick = (e) => {
    const t = e.target.closest('[data-rid]');
    if (t) rewardModal(rewards.find((r) => r.id === Number(t.dataset.rid)), data, () => renderRewards(levelEl, badgesEl));
  };
}

function rewardModal(r, data, reload) {
  const equippable = r.reward_type === 'title' || r.reward_type === 'frame';
  const equipped = (r.reward_type === 'title' && data.equipped.title_id === r.id) || (r.reward_type === 'frame' && data.equipped.frame_id === r.id);
  let actions = '';
  if (r.claimed) {
    actions = `<span class="gvi-btn joined static">${icon('check')}Unlocked</span>` +
      (equippable ? `<button class="gvi-btn ${equipped ? 'ghost' : ''}" data-rw="${equipped ? 'unequip' : 'equip'}">${equipped ? 'Unequip' : 'Equip'}</button>` : '') +
      (r.code ? `<button class="code-box" data-rw="copy">${icon('copy')} ${esc(r.code)}</button>` : '');
  } else if (r.is_event_only) {
    actions = `<span class="muted">${icon('calendar')} Earn it by joining its event</span>`;
  } else if (!r.available) {
    actions = `<span class="muted">${r.remaining === 0 ? 'All claimed' : 'Not available'}</span>`;
  } else if (r.eligible) {
    actions = `<button class="gvi-btn" data-rw="claim">${icon('gift')}Claim</button>`;
  } else {
    actions = `<span class="locked">${icon('lock')} Reach level ${r.required_level}</span>`;
  }
  const m = openModal({
    size: 'sm',
    className: 'reward-modal',
    body: `<div class="rm-body">
      ${rewardIcon(r, 'lg')}
      <span class="rw-type">${esc(REWARD_TYPES[r.reward_type] || r.reward_type)}</span>
      <h3>${esc(r.title)}</h3>
      ${r.description ? `<p class="muted">${esc(r.description)}</p>` : ''}
      <div class="rw-meta">${levelPill(r.required_level)}${r.remaining !== null ? `<span class="muted tiny">${r.remaining} left</span>` : ''}${r.expires_at ? `<span class="muted tiny">ends ${timeUntil(r.expires_at)}</span>` : ''}${r.claimed_at ? `<span class="muted tiny">unlocked ${timeAgo(r.claimed_at)}</span>` : ''}</div>
      <div class="rm-actions">${actions}</div>
    </div>`,
  });
  m.el.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-rw]')?.dataset.rw;
    if (!act) return;
    const btn = e.target.closest('button');
    try {
      if (act === 'copy') return copyText(r.code);
      setBusy(btn, true);
      if (act === 'claim') {
        const res = await api.post('rewards/claim', { reward_id: r.id });
        toast(`Unlocked “${res.title}”!`, 'success');
        if (res.code) toast(`Your code: ${res.code}`, 'info', 8000);
        refreshMe();
      } else {
        const key = r.reward_type === 'title' ? 'title_id' : 'frame_id';
        await api.post('profile/equip', { [key]: act === 'equip' ? r.id : 0 });
        toast(act === 'equip' ? 'Equipped! Check your profile.' : 'Unequipped', 'success');
        refreshMe();
      }
      m.close();
      reload();
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  });
}

/* ---------------------------------------------------------------- XP */
async function xpModal() {
  const m = openModal({ title: 'Your XP', size: 'md', body: loader() });
  let data;
  try {
    data = await api.get('rewards/xp');
  } catch (err) {
    m.body.innerHTML = '';
    m.body.appendChild(errorState(err));
    return;
  }
  const lv = data.level;
  m.body.innerHTML = `
    <div class="xp-modal">
      <div class="lp-row">${levelRing(lv, 86)}<div class="lp-text"><strong>Level ${lv.level} · ${esc(lv.rank)}</strong><small>${lv.xp.toLocaleString()} XP · next level at ${lv.next_xp.toLocaleString()} XP</small><small>🔥 ${data.streak}-day login streak</small></div></div>
      <h4>${icon('sparkles')} How to earn XP</h4>
      <ul class="earn-list">${data.earn.map((x) => `<li><span>${esc(x.label)}</span><b>+${esc(x.xp)}</b></li>`).join('')}</ul>
      <h4>${icon('clock')} Recent XP</h4>
      ${data.history.length ? `<ul class="history-list">${data.history.map((h) => `<li><span>${esc(h.label)}</span><small class="muted">${timeAgo(h.created_at)}</small><b class="${h.amount < 0 ? 'neg' : ''}">${h.amount > 0 ? '+' : ''}${h.amount} XP</b></li>`).join('')}</ul>` : '<p class="muted">No XP yet.</p>'}
    </div>`;
}
