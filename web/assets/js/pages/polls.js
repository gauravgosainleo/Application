// Polls raised by the admins: the next open poll is featured (tap an option to vote), the rest show live results.
import { api } from '../core/api.js';
import { esc, $, $$, icon, timeUntil, fmtDate, toast, toastError, setBusy, emptyState, errorState, loader } from '../core/ui.js';
import { refreshMe } from '../app.js';
import { glare } from '../core/gvi-ui.js';

/** Shared heading for the Polls / Events & Rewards pair. */
export function pegHead(active) {
  return `<div class="gvi-page-head">
    <div><h2 class="gvi-page-title">${icon('trophy')}Polls, Events &amp; Rewards</h2><p class="gvi-page-sub">Vote, join and claim. Everything here pays XP.</p></div>
    <div class="gvi-page-head-right"><nav class="gvi-seg" aria-label="Section">
      <a href="#/polls" class="${active === 'polls' ? 'is-active' : ''}">Polls</a>
      <a href="#/events" class="${active === 'events' ? 'is-active' : ''}">Events &amp; Rewards</a>
    </nav></div>
  </div>`;
}

function whenLabel(p) {
  if (p.status !== 'open') return 'Closed';
  if (!p.ends_at) return 'Open';
  const left = new Date(p.ends_at) - Date.now();
  if (left < 86400000) return 'Closes today';
  if (left < 2 * 86400000) return 'Closes tomorrow';
  return `Closes ${timeUntil(p.ends_at)}`;
}

function resultRows(p) {
  const top = Math.max(...p.options.map((o) => o.votes || 0));
  return p.options.map((o) => `
    <div class="pr-row ${o.mine ? 'mine' : ''} ${o.votes === top && top > 0 ? 'leader' : ''}">
      <div class="pr-top"><span>${o.mine ? icon('check') : ''}${esc(o.label)}</span><b>${o.percent}%</b></div>
      <div class="pr-track"><span style="width:${o.percent}%"></span></div>
    </div>`).join('');
}

/** One option: a button for single choice (votes on tap), a checkbox for multiple choice. */
function optionHTML(p, o, cls = '') {
  return p.is_multiple
    ? `<label class="pf-opt ${cls}"><input type="checkbox" name="opt" value="${o.id}"><span>${esc(o.label)}</span><i class="pf-check">${icon('check')}</i></label>`
    : `<button type="button" class="pf-opt ${cls}" data-opt="${o.id}"><span>${esc(o.label)}</span></button>`;
}

/** Featured poll: big accent card. */
function featuredPoll(p) {
  return `<article class="gvi-panel accent gvi-poll-featured poll-card" data-id="${p.id}" data-tilt="card">
    <div class="pf-head"><span class="pf-live"><span class="dot"></span>Open poll</span><span class="xp-chip">+10 XP</span></div>
    <h3 class="pf-q">${esc(p.question)}</h3>
    ${p.description ? `<p class="pf-desc">${esc(p.description)}</p>` : ''}
    <form class="pf-options">
      ${p.options.map((o) => optionHTML(p, o)).join('')}
      <div class="pf-foot"><span>${p.voters.toLocaleString()} vote${p.voters === 1 ? '' : 's'}${p.ends_at ? ' · ends ' + timeUntil(p.ends_at) : ''}</span>
        ${p.is_multiple ? `<button class="gvi-btn sm" type="submit">${icon('check')}Vote · +10 XP</button>` : '<span>Tap an option to vote · +10 XP</span>'}</div>
    </form>
    ${glare}
  </article>`;
}

/** Compact card: results when visible, otherwise the player's own vote. */
function resultPoll(p) {
  const open = p.status === 'open';
  const canVote = open && !p.voted;
  let inner;
  if (canVote) {
    inner = `<form class="pf-options compact">${p.options.map((o) => optionHTML(p, o, 'sm')).join('')}
      ${p.is_multiple ? `<div class="pf-foot"><span></span><button class="gvi-btn sm" type="submit">${icon('check')}Vote · +10 XP</button></div>` : ''}</form>`;
  } else if (p.show_results) {
    inner = resultRows(p);
  } else {
    inner = `<div class="pr-hidden">${icon('lock')}<span>Your vote: <b>${esc(p.options.filter((o) => o.mine).map((o) => o.label).join(', ') || '—')}</b>. Results appear when the poll closes.</span></div>`;
  }
  return `<article class="gvi-poll-card poll-card ${p.voted ? 'voted' : ''}" data-id="${p.id}">
    <div class="pc-head"><strong class="${open ? 'open' : ''}">${esc(whenLabel(p))}${p.voted ? ` · ${icon('check')}voted` : ''}</strong><small>${p.voters.toLocaleString()} vote${p.voters === 1 ? '' : 's'}${!open && p.ends_at ? ' · ' + fmtDate(p.ends_at) : ''}</small></div>
    <h3 class="pc-q">${esc(p.question)}</h3>
    ${inner}
  </article>`;
}

function rank(p) {
  if (p.status === 'open' && !p.voted) return 0;
  if (p.status === 'open') return 1;
  return 2;
}

export async function render({ el }) {
  el.innerHTML = `${pegHead('polls')}<div class="polls-body">${loader()}</div>`;
  const body = $('.polls-body', el);
  let polls = [];

  function draw() {
    if (!polls.length) {
      body.innerHTML = emptyState('poll', 'No polls right now', 'Check back soon — admins post new polls regularly.');
      return;
    }
    const sorted = [...polls].sort((a, b) => rank(a) - rank(b));
    const [first, ...rest] = sorted;
    const feature = rank(first) === 0 ? first : null;
    const others = feature ? rest : sorted;
    body.innerHTML = `<div class="gvi-polls-layout ${feature ? '' : 'no-feature'}">
      ${feature ? `<div class="pl-main">${featuredPoll(feature)}</div>` : ''}
      <div class="pl-side">${others.map(resultPoll).join('')}</div>
    </div>`;
  }

  async function load() {
    try {
      polls = await api.get('polls/list', { filter: 'all' });
      draw();
    } catch (err) {
      body.innerHTML = '';
      body.appendChild(errorState(err, load));
    }
  }

  async function vote(card, ids, btn) {
    if (!ids.length) return toast('Choose an option first.', 'warn');
    if (btn) setBusy(btn, true);
    card.classList.add('is-busy');
    try {
      const updated = await api.post('polls/vote', { poll_id: Number(card.dataset.id), option_ids: ids });
      polls = polls.map((p) => (p.id === updated.id ? updated : p));
      draw();
      toast('Vote counted! +10 XP', 'success');
      refreshMe();
    } catch (err) {
      toastError(err);
      card.classList.remove('is-busy');
      if (btn) setBusy(btn, false);
    }
  }

  body.addEventListener('submit', (e) => {
    e.preventDefault();
    const card = e.target.closest('.poll-card');
    vote(card, $$('input[name=opt]:checked', card).map((i) => Number(i.value)), $('button[type=submit]', card));
  });
  body.addEventListener('change', (e) => {
    if (e.target.name === 'opt') e.target.closest('.pf-opt').classList.toggle('checked', e.target.checked);
  });
  body.addEventListener('click', (e) => {
    const opt = e.target.closest('[data-opt]');
    const card = opt && opt.closest('.poll-card');
    if (!card || card.classList.contains('is-busy')) return;
    opt.classList.add('checked');
    vote(card, [Number(opt.dataset.opt)]);
  });
  load();
}
