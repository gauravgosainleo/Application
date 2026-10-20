// Status: 24h stories (text or photo) + everyone's presence/activity.
import { api } from '../core/api.js';
import { state, updateMe } from '../core/state.js';
import { esc, $, $$, icon, avatar, timeAgo, presenceLabel, openModal, confirmBox, toast, toastError, setBusy, asset, loader, errorState, reportDialog } from '../core/ui.js';
import { glare, ringAvatar, sectionHead } from '../core/gvi-ui.js';

const BG_LABELS = { sunset: 'Sunset', vice: 'Vice', ocean: 'Ocean', neon: 'Neon', gold: 'Gold', night: 'Night', jungle: 'Jungle', flame: 'Flame' };

export async function render({ el }) {
  el.innerHTML = `<div class="status-body">${loader()}</div>`;
  const body = $('.status-body', el);
  let feed = null;

  async function load() {
    try {
      feed = await api.get('status/feed');
    } catch (err) {
      body.innerHTML = '';
      body.appendChild(errorState(err, load));
      return;
    }
    draw();
  }

  function ring(group, isMe = false) {
    const viewed = isMe ? false : group.all_viewed;
    const count = group.statuses.length;
    return `<button class="story ${viewed ? 'viewed' : ''} ${isMe && !count ? 'empty-story' : ''}" data-uid="${group.user.id}" title="${count ? timeAgo(group.statuses[count - 1].created_at) : 'Add a status'}">
      <span class="story-ring">${avatar(group.user, 'lg', { presence: false })}${isMe ? `<span class="story-add" data-act="new" title="New status">${icon('plus')}</span>` : ''}</span>
      <span class="story-name">${isMe ? 'Your story' : esc(group.user.name.split(' ')[0])}</span>
    </button>`;
  }

  function draw() {
    const me = feed.me;
    const unseen = feed.groups.filter((g) => !g.all_viewed);
    const seen = feed.groups.filter((g) => g.all_viewed);
    const presenceOpts = [['online', 'Online'], ['away', 'Away'], ['busy', 'Busy'], ['invisible', 'Invisible']];
    const myPresence = me.presence === 'invisible' ? 'offline' : me.presence;
    const presenceSub = { online: 'Online · visible to friends', away: 'Away · visible to friends', busy: 'Busy · do not disturb', invisible: 'Invisible · you appear offline' };
    const online = feed.friends.filter((f) => f.presence !== 'offline');
    const others = feed.friends.filter((f) => f.presence === 'offline');
    body.innerHTML = `
      <section class="gvi-panel gvi-status-composer my-presence">
        <div class="sc-id">${ringAvatar({ ...me.user, presence: myPresence }, 60, { presence: true })}
          <div><strong>${esc(me.user.name)}</strong><small class="mp-sub">${esc(presenceSub[me.presence] || '')}</small></div></div>
        <form class="mp-form">
          <div class="gvi-seg presence-seg" role="radiogroup" aria-label="Presence">${presenceOpts.map(([k, l]) => `<label class="${me.presence === k ? 'is-active' : ''}"><input type="radio" name="presence" value="${k}" ${me.presence === k ? 'checked' : ''}><span class="pdot ${k === 'invisible' ? 'offline' : k}"></span>${l}</label>`).join('')}</div>
          <div class="mp-row"><input class="gvi-input" name="presence_text" maxlength="80" placeholder="What are you up to?" aria-label="What are you up to?" value="${esc(me.presence_text || '')}"><button class="gvi-btn">Save</button></div>
        </form>
      </section>
      <section class="gvi-status-section">
        <div class="gvi-section-head"><h2 class="gvi-section-title">${icon('target')}Stories</h2><button class="gvi-btn ghost sm" data-act="new">${icon('plus')}New status</button></div>
        <div class="stories-row">
          ${ring({ user: me.user, statuses: me.statuses }, true)}
          ${unseen.map((g) => ring(g)).join('')}
          ${seen.map((g) => ring(g)).join('')}
          ${!feed.groups.length ? `<div class="stories-empty">No stories from other players yet. Add friends to see theirs here — stories disappear after 24 hours.</div>` : ''}
        </div>
      </section>
      <section class="gvi-status-section">
        ${sectionHead('users', 'Who is online', null)}
        ${feed.friends.length ? `<div class="friends-grid">${[...online, ...others].map((f) => `
          <div class="gvi-person-card" data-tilt="card">
            <a class="pc-top" href="#/profile/${f.id}">${avatar(f, 'md')}<span class="pc-name"><strong>${esc(f.name)}</strong><small>${esc(presenceLabel(f))}${f.presence_text ? ' · ' + esc(f.presence_text) : f.platform ? ' · ' + esc(f.platform) : ''}</small></span></a>
            <div class="pc-actions"><a class="gvi-btn sm" href="#/friends?tab=chats&user=${f.id}">Message</a><a class="gvi-btn ghost sm" href="#/profile/${f.id}">Profile</a></div>
            ${glare}
          </div>`).join('')}</div>` : `<div class="empty small">${icon('users', 'empty-icon')}<p>Add friends to see their status here.</p><a class="gvi-btn ghost sm" href="#/friends?tab=find">Find players</a></div>`}
      </section>`;

    $('.mp-form', body).addEventListener('change', (e) => {
      if (e.target.name !== 'presence') return;
      $$('.presence-seg label', body).forEach((l) => l.classList.toggle('is-active', l.contains(e.target)));
      savePresence();
    });
    $('.mp-form', body).addEventListener('submit', (e) => { e.preventDefault(); savePresence(); });
    $$('.story', body).forEach((s) => s.addEventListener('click', (e) => {
      if (e.target.closest('.story-add')) return; // the + badge always creates a new status
      const uid = Number(s.dataset.uid);
      if (uid === me.user.id) {
        if (me.statuses.length) openViewer([{ user: me.user, statuses: me.statuses }], 0, true);
        else createStatus();
      } else {
        const all = [...unseen, ...seen];
        openViewer(all, all.findIndex((g) => g.user.id === uid), false);
      }
    }));
  }

  async function savePresence() {
    const form = $('.mp-form', body);
    const presence = form.presence.value;
    const presence_text = form.presence_text.value.trim();
    try {
      await api.post('profile/presence', { presence, presence_text });
      updateMe({ presence, presence_text });
      feed.me.presence = presence;
      feed.me.presence_text = presence_text;
      const sub = $('.mp-sub', body);
      if (sub) sub.textContent = { online: 'Online · visible to friends', away: 'Away · visible to friends', busy: 'Busy · do not disturb', invisible: 'Invisible · you appear offline' }[presence];
      const dot = $('.sc-id .pdot', body);
      if (dot) dot.className = 'pdot ' + (presence === 'invisible' ? 'offline' : presence);
      toast('Status updated', 'success');
    } catch (err) {
      toastError(err);
    }
  }

  /* ------------------------------------------------------- creator */
  function createStatus() {
    let mode = 'text';
    let bg = 'sunset';
    let file = null;
    const m = openModal({
      title: 'New status',
      size: 'md',
      body: `
        <div class="tabs small"><button class="tab active" data-mode="text">${icon('edit')} Text</button><button class="tab" data-mode="photo">${icon('camera')} Photo</button></div>
        <div class="status-compose">
          <div class="sc-preview story-bg-${bg}"><textarea maxlength="500" placeholder="Type a status…"></textarea><img hidden alt=""></div>
          <div class="sc-bgs">${Object.keys(BG_LABELS).map((k) => `<button class="bg-swatch story-bg-${k} ${k === bg ? 'active' : ''}" data-bg="${k}" title="${BG_LABELS[k]}"></button>`).join('')}</div>
          <div class="sc-photo" hidden>
            <label class="btn btn-ghost btn-block">${icon('image')} Choose photo<input type="file" accept="image/*" hidden></label>
            <input class="input caption" maxlength="500" placeholder="Add a caption (optional)">
          </div>
          <div class="field"><span>Who can see it?</span><div class="segmented">
            <label><input type="radio" name="vis" value="friends" checked><span>${icon('users')} Friends</span></label>
            <label><input type="radio" name="vis" value="public"><span>${icon('globe')} Everyone</span></label></div></div>
        </div>`,
      footer: `<button class="btn btn-ghost" data-act="cancel">Cancel</button><button class="btn btn-primary" data-act="post">${icon('send')} Post status</button>`,
    });
    const preview = $('.sc-preview', m.el);
    const ta = $('textarea', preview);
    const img = $('img', preview);
    $$('[data-mode]', m.el).forEach((t) => t.addEventListener('click', () => {
      mode = t.dataset.mode;
      $$('[data-mode]', m.el).forEach((x) => x.classList.toggle('active', x === t));
      $('.sc-photo', m.el).hidden = mode !== 'photo';
      $('.sc-bgs', m.el).hidden = mode !== 'text';
      ta.hidden = mode !== 'text';
      img.hidden = mode !== 'photo' || !file;
      preview.classList.toggle('photo-mode', mode === 'photo');
    }));
    $$('[data-bg]', m.el).forEach((b) => b.addEventListener('click', () => {
      bg = b.dataset.bg;
      preview.className = `sc-preview story-bg-${bg}`;
      $$('[data-bg]', m.el).forEach((x) => x.classList.toggle('active', x === b));
    }));
    $('.sc-photo input[type=file]', m.el).addEventListener('change', (e) => {
      file = e.target.files[0] || null;
      if (file) {
        img.src = URL.createObjectURL(file);
        img.hidden = false;
      }
    });
    $('[data-act="cancel"]', m.el).addEventListener('click', () => m.close());
    $('[data-act="post"]', m.el).addEventListener('click', async (e) => {
      const visibility = $('input[name=vis]:checked', m.el).value;
      const btn = e.target.closest('button');
      setBusy(btn, true);
      try {
        if (mode === 'photo') {
          if (!file) throw new Error('Choose a photo first.');
          await api.upload('status/create', { file, text: $('.caption', m.el).value.trim(), visibility });
        } else {
          if (!ta.value.trim()) throw new Error('Write something first.');
          await api.post('status/create', { text: ta.value.trim(), bg, visibility });
        }
        m.close();
        toast('Status posted! It disappears in 24 hours.', 'success');
        load();
      } catch (err) {
        toastError(err);
        setBusy(btn, false);
      }
    });
  }

  /* -------------------------------------------------------- viewer */
  function openViewer(groups, gIndex, isMine) {
    let gi = gIndex;
    let si = 0;
    let timer = null;
    let paused = false;
    let elapsed = 0;
    const DURATION = 5500;
    const onKey = (e) => { if (e.key === 'ArrowRight') next(); if (e.key === 'ArrowLeft') prev(); };
    document.addEventListener('keydown', onKey);
    const m = openModal({
      size: 'full',
      className: 'story-viewer',
      body: '<div class="sv"></div>',
      onClose: () => { clearInterval(timer); document.removeEventListener('keydown', onKey); load(); },
    });
    const sv = $('.sv', m.el);
    if (!isMine) {
      const firstUnseen = groups[gi].statuses.findIndex((s) => !s.viewed);
      si = firstUnseen >= 0 ? firstUnseen : 0;
    }

    function show() {
      const g = groups[gi];
      const s = g.statuses[si];
      elapsed = 0;
      sv.innerHTML = `
        <div class="sv-bars">${g.statuses.map((_, i) => `<span class="sv-bar"><i style="width:${i < si ? 100 : 0}%"></i></span>`).join('')}</div>
        <div class="sv-head">${avatar(g.user, 'sm', { presence: false })}<div><strong>${esc(isMine ? 'My status' : g.user.name)}</strong><small>${timeAgo(s.created_at)} · ${s.visibility === 'public' ? 'Everyone' : 'Friends'}</small></div>
          <div class="sv-tools">
            ${isMine ? `<button class="icon-btn" data-sv="viewers">${icon('eye')} <span>${s.views || 0}</span></button><button class="icon-btn" data-sv="delete">${icon('trash')}</button>` : `<button class="icon-btn" data-sv="report">${icon('flag')}</button>`}
          </div></div>
        <div class="sv-content ${s.kind === 'text' ? 'story-bg-' + esc(s.bg || 'sunset') : 'photo'}">
          ${s.kind === 'image' ? `<img src="${esc(asset(s.image))}" alt="">${s.text ? `<p class="sv-caption">${esc(s.text)}</p>` : ''}` : `<p class="sv-text">${esc(s.text)}</p>`}
        </div>
        <button class="sv-nav prev" data-sv="prev" aria-label="Previous"></button>
        <button class="sv-nav next" data-sv="next" aria-label="Next"></button>`;
      if (!isMine && !s.viewed) {
        s.viewed = true;
        api.post('status/view', { status_id: s.id }).catch(() => {});
      }
      clearInterval(timer);
      timer = setInterval(() => {
        if (paused) return;
        elapsed += 100;
        const bar = sv.querySelectorAll('.sv-bar i')[si];
        if (bar) bar.style.width = Math.min(100, (elapsed / DURATION) * 100) + '%';
        if (elapsed >= DURATION) next();
      }, 100);
    }

    function next() {
      const g = groups[gi];
      if (si < g.statuses.length - 1) si++;
      else if (gi < groups.length - 1) { gi++; si = 0; }
      else { m.close(); return; }
      show();
    }
    function prev() {
      if (si > 0) si--;
      else if (gi > 0) { gi--; si = groups[gi].statuses.length - 1; }
      show();
    }

    sv.addEventListener('click', async (e) => {
      const act = e.target.closest('[data-sv]')?.dataset.sv;
      const s = groups[gi].statuses[si];
      if (act === 'next') next();
      else if (act === 'prev') prev();
      else if (act === 'report') { paused = true; await reportDialog(api, 'status', s.id); paused = false; }
      else if (act === 'delete') {
        paused = true;
        if (await confirmBox('Delete this status?', { confirmText: 'Delete', danger: true })) {
          try {
            await api.post('status/delete', { status_id: s.id });
            groups[gi].statuses.splice(si, 1);
            if (!groups[gi].statuses.length) { m.close(); return; }
            si = Math.min(si, groups[gi].statuses.length - 1);
            show();
          } catch (err) { toastError(err); }
        }
        paused = false;
      } else if (act === 'viewers') {
        paused = true;
        try {
          const viewers = await api.get('status/viewers', { status_id: s.id });
          const vm = openModal({
            title: `Viewed by ${viewers.length}`,
            size: 'sm',
            body: viewers.length ? `<div class="member-list">${viewers.map((v) => `<a class="member-row" href="#/profile/${v.id}">${avatar(v, 'sm')}<strong>${esc(v.name)}</strong><small class="muted">${timeAgo(v.viewed_at)}</small></a>`).join('')}</div>` : '<p class="muted">No views yet.</p>',
            onClose: () => { paused = false; },
          });
          vm.el.addEventListener('click', (ev) => { if (ev.target.closest('a')) { vm.close(); m.close(); } });
        } catch (err) { toastError(err); paused = false; }
      }
    });
    sv.addEventListener('pointerdown', (e) => { if (e.target.closest('.sv-content')) paused = true; });
    sv.addEventListener('pointerup', () => { paused = false; });
    show();
  }

  el.addEventListener('click', (e) => { if (e.target.closest('[data-act="new"]')) createStatus(); });
  load();
}
