// Polls raised by the admins - vote once, see live results.
import { api } from '../core/api.js';
import { esc, $, $$, icon, timeUntil, fmtDate, toast, toastError, setBusy, emptyState, errorState, loader } from '../core/ui.js';
import { refreshMe } from '../app.js';

function pollCard(p) {
  const results = p.show_results;
  const open = p.status === 'open';
  const canVote = open && !p.voted;
  const top = results ? Math.max(...p.options.map((o) => o.votes || 0)) : 0;
  return `<article class="card poll-card ${p.voted ? 'voted' : ''}" data-id="${p.id}">
    <div class="poll-head">
      <span class="tag ${open ? 'tag-ok' : ''}">${open ? `${icon('zap')} Open` : `${icon('lock')} Closed`}</span>
      ${p.is_multiple ? `<span class="tag">Multiple choice</span>` : ''}
      ${p.voted ? `<span class="tag tag-hot">${icon('check')} You voted</span>` : ''}
    </div>
    <h3>${esc(p.question)}</h3>
    ${p.description ? `<p class="muted">${esc(p.description)}</p>` : ''}
    <form class="poll-options">
      ${p.options.map((o) => `
        <label class="poll-opt ${o.mine ? 'mine' : ''} ${results && o.votes === top && top > 0 ? 'leader' : ''} ${canVote ? 'selectable' : ''}">
          ${canVote ? `<input type="${p.is_multiple ? 'checkbox' : 'radio'}" name="opt" value="${o.id}">` : ''}
          ${results ? `<span class="po-fill" style="width:${o.percent}%"></span>` : ''}
          <span class="po-label">${o.mine ? icon('check') : ''}${esc(o.label)}</span>
          ${results ? `<span class="po-pct">${o.percent}%<small>${o.votes}</small></span>` : ''}
        </label>`).join('')}
      <div class="poll-foot">
        <div class="pf-meta">
          <span class="muted small">${p.voters} vote${p.voters === 1 ? '' : 's'} · ${open ? (p.ends_at ? `ends ${timeUntil(p.ends_at)}` : 'no end date') : `closed${p.ends_at ? ' ' + fmtDate(p.ends_at) : ''}`}</span>
          ${!results && p.voted ? '<span class="muted tiny">Results appear when the poll closes.</span>' : ''}
          ${!results && !p.voted && open ? '<span class="muted tiny">Vote to see the results.</span>' : ''}
        </div>
        ${canVote ? `<button class="btn btn-primary btn-sm" type="submit">${icon('check')} Vote · +10 XP</button>` : ''}
      </div>
    </form>
  </article>`;
}

export async function render({ el }) {
  let filter = 'open';
  el.innerHTML = `
    <div class="page-head">
      <div><h2>${icon('poll')} Polls</h2><p class="muted">Have your say — every vote earns you 10 XP.</p></div>
      <div class="tabs small"><button class="tab active" data-f="open">Open</button><button class="tab" data-f="closed">Closed</button><button class="tab" data-f="all">All</button></div>
    </div>
    <div class="polls-list">${loader()}</div>`;
  const list = $('.polls-list', el);
  let polls = [];

  async function load() {
    list.innerHTML = loader();
    try {
      polls = await api.get('polls/list', { filter });
      list.innerHTML = polls.length ? polls.map(pollCard).join('') : emptyState('poll', filter === 'closed' ? 'No closed polls yet' : 'No open polls right now', 'Check back soon — admins post new polls regularly.');
    } catch (err) {
      list.innerHTML = '';
      list.appendChild(errorState(err, load));
    }
  }

  list.addEventListener('submit', async (e) => {
    e.preventDefault();
    const card = e.target.closest('.poll-card');
    const ids = $$('input[name=opt]:checked', card).map((i) => Number(i.value));
    if (!ids.length) return toast('Choose an option first.', 'warn');
    const btn = $('button[type=submit]', card);
    setBusy(btn, true);
    try {
      const updated = await api.post('polls/vote', { poll_id: Number(card.dataset.id), option_ids: ids });
      card.outerHTML = pollCard(updated);
      toast('Vote counted! +10 XP', 'success');
      refreshMe();
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  });
  list.addEventListener('change', (e) => {
    if (e.target.name === 'opt' && e.target.type === 'radio') {
      $$('.poll-opt', e.target.closest('.poll-card')).forEach((l) => l.classList.toggle('checked', l.contains(e.target)));
    } else if (e.target.name === 'opt') {
      e.target.closest('.poll-opt').classList.toggle('checked', e.target.checked);
    }
  });
  $$('[data-f]', el).forEach((t) => t.addEventListener('click', () => {
    filter = t.dataset.f;
    $$('[data-f]', el).forEach((x) => x.classList.toggle('active', x === t));
    load();
  }));
  load();
}
