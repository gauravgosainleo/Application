// Events & Rewards: join events, claim rewards, equip titles/frames, XP history.
import { api } from '../core/api.js';
import { esc, $, $$, icon, asset, avatar, levelPill, timeAgo, timeUntil, fmtDateTime, openModal, toast, toastError, setBusy, emptyState, errorState, loader, richText, copyText } from '../core/ui.js';
import { setQuery } from '../core/router.js';
import { refreshMe } from '../app.js';

const TYPE_ICONS = { Launch: 'zap', Contest: 'trophy', Community: 'users', Livestream: 'film', Meetup: 'pin' };
const REWARD_TYPES = { badge: 'Badge', title: 'Profile title', frame: 'Avatar frame', code: 'Promo code' };

export function rewardIcon(r, size = '') {
  if (r.icon && /^(uploads\/|https?:)/.test(r.icon)) return `<span class="reward-icon ${size} style-${esc(r.style || 'default')}"><img src="${esc(asset(r.icon))}" alt=""></span>`;
  return `<span class="reward-icon ${size} style-${esc(r.style || 'default')}">${esc(r.icon || '🏅')}</span>`;
}

export async function render({ el, query }) {
  let tab = ['events', 'rewards', 'xp'].includes(query.tab) ? query.tab : 'events';
  el.innerHTML = `
    <div class="page-head">
      <div><h2>${icon('trophy')} Events & Rewards</h2><p class="muted">Join community events, level up and unlock badges, titles and frames.</p></div>
      <div class="tabs">
        <button class="tab" data-tab="events">${icon('calendar')}<span>Events</span></button>
        <button class="tab" data-tab="rewards">${icon('gift')}<span>Rewards</span></button>
        <button class="tab" data-tab="xp">${icon('zap')}<span>My XP</span></button>
      </div>
    </div>
    <div class="ev-body"></div>`;
  const body = $('.ev-body', el);
  function go(t) {
    tab = t;
    $$('.tab', el).forEach((x) => x.classList.toggle('active', x.dataset.tab === t));
    setQuery({ tab: t });
    if (t === 'events') renderEvents(body);
    else if (t === 'rewards') renderRewards(body);
    else renderXp(body);
  }
  $$('.tab', el).forEach((t) => t.addEventListener('click', () => go(t.dataset.tab)));
  go(tab);
}

/* ------------------------------------------------------------ events */
function phaseBadge(e) {
  if (e.phase === 'live') return `<span class="tag tag-live"><span class="live-dot"></span> LIVE</span>`;
  if (e.phase === 'upcoming') return `<span class="tag tag-info">${icon('clock')} Starts ${timeUntil(e.starts_at)}</span>`;
  return `<span class="tag">${icon('check')} Ended</span>`;
}

function eventActions(e) {
  if (e.claimed) return `<span class="chip chip-ok">${icon('check')} Reward claimed</span>`;
  if (e.can_claim) return `<button class="btn btn-primary btn-sm" data-ev="claim">${icon('gift')} Claim reward</button>`;
  if (e.joined) return `<span class="chip chip-ok">${icon('check')} Joined</span>${e.phase !== 'ended' ? '<button class="btn btn-ghost btn-sm" data-ev="leave">Leave</button>' : ''}`;
  if (e.phase === 'ended') return '<span class="muted small">This event has ended</span>';
  if (e.is_full) return '<span class="muted small">Event is full</span>';
  return `<button class="btn btn-primary btn-sm" data-ev="join">${icon('plus')} Join event</button>`;
}

function eventCard(e) {
  return `<article class="card event-card phase-${e.phase}" data-id="${e.id}">
    <div class="ev-banner">
      ${e.banner ? `<img src="${esc(asset(e.banner))}" alt="" loading="lazy">` : `<span class="ev-banner-ph">${icon(TYPE_ICONS[e.event_type] || 'calendar')}</span>`}
      <div class="ev-badges">${phaseBadge(e)}<span class="tag">${esc(e.event_type)}</span></div>
    </div>
    <div class="ev-main">
      <h3>${esc(e.title)}</h3>
      <div class="ev-meta">
        <span>${icon('calendar')} ${esc(fmtDateTime(e.starts_at))}${e.ends_at ? ' → ' + esc(fmtDateTime(e.ends_at)) : ''}</span>
        ${e.location ? `<span>${icon('pin')} ${esc(e.location)}</span>` : ''}
        <span>${icon('users')} ${e.participants}${e.max_participants ? ' / ' + e.max_participants : ''} joined</span>
      </div>
      <div class="ev-rewards">
        ${e.reward_xp ? `<span class="xp-chip">${icon('zap')} +${e.reward_xp} XP</span>` : ''}
        ${e.reward ? `<span class="reward-chip">${rewardIcon(e.reward, 'xs')} ${esc(e.reward.title)}</span>` : ''}
      </div>
      <div class="ev-actions">${eventActions(e)}<button class="link-btn" data-ev="details">Details ${icon('chevronRight')}</button></div>
    </div>
  </article>`;
}

async function renderEvents(body) {
  let filter = 'all';
  body.innerHTML = `
    <div class="chip-row">${[['all', 'All'], ['live', 'Live'], ['upcoming', 'Upcoming'], ['mine', 'Joined'], ['past', 'Past']].map(([k, l]) => `<button class="chip ${k === filter ? 'active' : ''}" data-f="${k}">${l}</button>`).join('')}</div>
    <div class="events-list">${loader()}</div>`;
  const list = $('.events-list', body);
  let events = [];
  async function load() {
    list.innerHTML = loader();
    try {
      events = await api.get('events/list', { filter });
      list.innerHTML = events.length ? `<div class="events-grid">${events.map(eventCard).join('')}</div>` : emptyState('calendar', 'No events here', 'New community events are announced by the admins.');
    } catch (err) {
      list.innerHTML = '';
      list.appendChild(errorState(err, load));
    }
  }
  body.onclick = async (e) => {
    const f = e.target.closest('[data-f]');
    if (f) {
      filter = f.dataset.f;
      $$('[data-f]', body).forEach((x) => x.classList.toggle('active', x === f));
      return load();
    }
    const act = e.target.closest('[data-ev]')?.dataset.ev;
    if (!act) return;
    const card = e.target.closest('.event-card');
    const id = Number(card.dataset.id);
    if (act === 'details') return eventDetails(id, load);
    const btn = e.target.closest('button');
    setBusy(btn, true);
    try {
      if (act === 'join') {
        const ev = await api.post('events/join', { event_id: id });
        card.outerHTML = eventCard(ev);
        toast(ev.can_claim ? 'Joined! Your reward is ready to claim.' : 'You joined the event!', 'success');
      } else if (act === 'leave') {
        card.outerHTML = eventCard(await api.post('events/leave', { event_id: id }));
      } else if (act === 'claim') {
        const r = await api.post('events/claim', { event_id: id });
        card.outerHTML = eventCard(r.event);
        celebrate(r.granted);
        refreshMe();
      }
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  };
  load();
}

function celebrate(granted) {
  openModal({
    size: 'sm',
    className: 'celebrate',
    body: `<div class="celebrate-box"><div class="confetti">🎉</div><h2>Reward claimed!</h2>
      ${granted.xp ? `<p class="big-xp">+${granted.xp} XP</p>` : ''}
      ${granted.reward ? `<div class="reward-chip big">${rewardIcon(granted.reward)} <span>Unlocked <strong>${esc(granted.reward.title)}</strong></span></div>` : ''}
      <a class="btn btn-primary" href="#/events?tab=rewards">See my rewards</a></div>`,
  });
}

async function eventDetails(id, reload) {
  const m = openModal({ size: 'lg', body: loader() });
  try {
    const { event: e, participants } = await api.get('events/get', { id });
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
        <h4>${icon('users')} ${e.participants} participant${e.participants === 1 ? '' : 's'}</h4>
        <div class="avatar-cloud">${participants.map((u) => `<a href="#/profile/${u.id}" title="${esc(u.name)}">${avatar(u, 'sm', { presence: false })}</a>`).join('') || '<span class="muted small">Be the first to join!</span>'}</div>
      </div>`;
    m.body.addEventListener('click', (ev) => { if (ev.target.closest('a')) m.close(); });
  } catch (err) {
    m.body.innerHTML = '';
    m.body.appendChild(errorState(err));
  }
}

/* ----------------------------------------------------------- rewards */
async function renderRewards(body) {
  body.innerHTML = loader();
  let data;
  try {
    data = await api.get('rewards/list');
  } catch (err) {
    body.innerHTML = '';
    body.appendChild(errorState(err, () => renderRewards(body)));
    return;
  }
  const lv = data.level;
  const card = (r) => {
    let stateHtml = '';
    if (r.claimed) {
      const equippable = r.reward_type === 'title' || r.reward_type === 'frame';
      const equipped = (r.reward_type === 'title' && data.equipped.title_id === r.id) || (r.reward_type === 'frame' && data.equipped.frame_id === r.id);
      stateHtml = `<span class="chip chip-ok">${icon('check')} Unlocked</span>` +
        (equippable ? `<button class="btn btn-sm ${equipped ? 'btn-ghost' : 'btn-primary'}" data-rw="${equipped ? 'unequip' : 'equip'}">${equipped ? 'Unequip' : 'Equip'}</button>` : '') +
        (r.code ? `<button class="code-box" data-rw="copy" data-code="${esc(r.code)}">${icon('copy')} ${esc(r.code)}</button>` : '');
    } else if (r.is_event_only) {
      stateHtml = `<span class="muted small">${icon('calendar')} Earn it by joining its event</span>`;
    } else if (!r.available) {
      stateHtml = `<span class="muted small">${r.remaining === 0 ? 'All claimed' : 'Not available'}</span>`;
    } else if (r.eligible) {
      stateHtml = `<button class="btn btn-primary btn-sm" data-rw="claim">${icon('gift')} Claim</button>`;
    } else {
      stateHtml = `<span class="locked">${icon('lock')} Reach level ${r.required_level}</span>`;
    }
    return `<div class="card reward-card ${r.claimed ? 'claimed' : ''} ${!r.claimed && !r.eligible ? 'locked-card' : ''}" data-id="${r.id}" data-type="${r.reward_type}">
      ${rewardIcon(r, 'lg')}
      <span class="rw-type">${esc(REWARD_TYPES[r.reward_type] || r.reward_type)}</span>
      <strong>${esc(r.title)}</strong>
      ${r.description ? `<p class="muted small">${esc(r.description)}</p>` : ''}
      <div class="rw-meta">${levelPill(r.required_level)}${r.remaining !== null ? `<span class="muted tiny">${r.remaining} left</span>` : ''}${r.expires_at ? `<span class="muted tiny">ends ${timeUntil(r.expires_at)}</span>` : ''}</div>
      <div class="rw-state">${stateHtml}</div>
    </div>`;
  };
  body.innerHTML = `
    <section class="card level-card">
      <div class="lc-left"><span class="lc-level">${lv.level}</span><div><strong>${esc(lv.rank)}</strong><span class="muted small">${lv.xp.toLocaleString()} XP total</span></div></div>
      <div class="lc-bar"><div class="row-between small"><span>Level ${lv.level}</span><span>${(lv.next_xp - lv.xp).toLocaleString()} XP to level ${lv.level + 1}</span></div><div class="xp-bar"><span style="width:${Math.round(lv.progress * 100)}%"></span></div></div>
    </section>
    ${data.rewards.length ? `<div class="rewards-grid">${data.rewards.map(card).join('')}</div>` : emptyState('gift', 'No rewards yet', 'Admins will add rewards soon.')}`;
  body.onclick = async (e) => {
    const act = e.target.closest('[data-rw]')?.dataset.rw;
    if (!act) return;
    const c = e.target.closest('.reward-card');
    const id = Number(c.dataset.id);
    const btn = e.target.closest('button');
    try {
      if (act === 'copy') return copyText(btn.dataset.code);
      setBusy(btn, true);
      if (act === 'claim') {
        const r = await api.post('rewards/claim', { reward_id: id });
        toast(`Unlocked “${r.title}”!`, 'success');
        if (r.code) toast(`Your code: ${r.code}`, 'info', 8000);
      } else if (act === 'equip' || act === 'unequip') {
        const key = c.dataset.type === 'title' ? 'title_id' : 'frame_id';
        await api.post('profile/equip', { [key]: act === 'equip' ? id : 0 });
        toast(act === 'equip' ? 'Equipped! Check your profile.' : 'Unequipped', 'success');
        refreshMe();
      }
      renderRewards(body);
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  };
}

/* ---------------------------------------------------------------- XP */
async function renderXp(body) {
  body.innerHTML = loader();
  let data;
  try {
    data = await api.get('rewards/xp');
  } catch (err) {
    body.innerHTML = '';
    body.appendChild(errorState(err, () => renderXp(body)));
    return;
  }
  const lv = data.level;
  body.innerHTML = `
    <div class="xp-layout">
      <section class="card level-card big">
        <div class="lc-ring" style="--p:${Math.round(lv.progress * 100)}"><span>${lv.level}</span><small>LEVEL</small></div>
        <div><h3>${esc(lv.rank)}</h3><p class="muted">${lv.xp.toLocaleString()} XP · next level at ${lv.next_xp.toLocaleString()} XP</p>
          <p>🔥 ${data.streak}-day login streak</p></div>
      </section>
      <section class="card">
        <h3>${icon('sparkles')} How to earn XP</h3>
        <ul class="earn-list">${data.earn.map((x) => `<li><span>${esc(x.label)}</span><b>+${esc(x.xp)}</b></li>`).join('')}</ul>
      </section>
      <section class="card xp-history">
        <h3>${icon('clock')} Recent XP</h3>
        ${data.history.length ? `<ul class="history-list">${data.history.map((h) => `<li><span>${esc(h.label)}</span><small class="muted">${timeAgo(h.created_at)}</small><b class="${h.amount < 0 ? 'neg' : ''}">${h.amount > 0 ? '+' : ''}${h.amount} XP</b></li>`).join('')}</ul>` : '<p class="muted">No XP yet.</p>'}
      </section>
    </div>`;
}
