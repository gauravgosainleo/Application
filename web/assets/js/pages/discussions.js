// Discussion articles: list, read, write, like, comment, reply, report.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { esc, $, $$, icon, avatar, levelPill, timeAgo, fmtDateTime, richText, asset, openModal, confirmBox, toast, toastError, setBusy, debounce, emptyState, errorState, loader, shareLink, appLink, reportDialog, userName, presenceLabel } from '../core/ui.js';
import { glare, pageHead } from '../core/gvi-ui.js';
import { navigate, setQuery } from '../core/router.js';

export async function render(ctx) {
  if (ctx.params.id) return renderDetail(ctx);
  return renderList(ctx);
}

const SORTS = [['new', 'Newest'], ['active', 'Active'], ['top', 'Top']];

function discCard(d) {
  return `<article class="gvi-disc-card" data-id="${d.id}" data-tilt="card">
    <a class="dcc-av" href="#/profile/${d.author.id}" tabindex="-1">${avatar(d.author, 'md', { presence: false })}</a>
    <div class="dcc-main">
      <span class="dcc-meta"><span class="dcc-tag">${esc(d.category)}</span>${d.is_pinned ? `<span class="dcc-flag">${icon('pin')}Pinned</span>` : ''}${d.is_locked ? `<span class="dcc-flag">${icon('lock')}Locked</span>` : ''}<span>${esc(d.author.name)} · ${timeAgo(d.created_at)}</span></span>
      <a class="dcc-title" href="#/discussions/${d.id}">${esc(d.title)}</a>
      <span class="dcc-foot">
        <button class="dcc-like ${d.liked ? 'liked' : ''}" data-like aria-label="Like">${icon('heart')}<span>${d.likes_count}</span></button>
        <span class="dcc-stat">${icon('discuss')}${d.comments_count}</span>
        <span class="dcc-open">Open${icon('chevronRight')}</span>
      </span>
    </div>
    ${d.cover_image ? `<span class="dcc-cover"><img src="${esc(asset(d.cover_image))}" alt="" loading="lazy"></span>` : ''}
    ${glare}
  </article>`;
}

async function renderList({ el, query }) {
  let page = 1;
  let category = query.category || '';
  let sort = SORTS.some(([k]) => k === query.sort) ? query.sort : 'new';
  let q = query.q || '';
  el.innerHTML = `
    ${pageHead('discuss', 'Discussions', 'Theories, articles and hot takes. Likes and replies earn XP.', `<button class="gvi-btn" data-act="new">${icon('plus')}New discussion</button>`)}
    <div class="gvi-disc-layout">
      <div class="gvi-disc-main">
        <div class="gvi-disc-tools">
          <div class="gvi-search-inline small">${icon('search')}<input type="search" class="gvi-input" placeholder="Search discussions…" value="${esc(q)}" aria-label="Search discussions"></div>
          <div class="gvi-seg sort-seg">${SORTS.map(([k, l]) => `<button data-sort="${k}" class="${k === sort ? 'is-active' : ''}">${l}</button>`).join('')}</div>
        </div>
        <div class="disc-list"></div>
        <div class="load-more-wrap"></div>
      </div>
      <aside class="gvi-disc-side">
        <section class="gvi-panel side-card"><div class="side-title">${icon('fire')}Hot categories</div><div class="hot-cats"></div></section>
        <section class="gvi-panel side-card"><div class="side-title">${icon('crown')}Top contributors this week</div><div class="top-contrib"><p class="muted small">Loading…</p></div></section>
      </aside>
    </div>`;
  const list = $('.disc-list', el);
  const more = $('.load-more-wrap', el);
  const cats = (state.config && state.config.discussion_categories) || [];

  let showAllCats = false;
  let hotCats = null;
  function drawCats() {
    const names = hotCats ? hotCats.map((h) => h.name) : cats;
    const shown = showAllCats ? names : names.filter((c, i) => i < 6 || c === category);
    const hidden = names.length - shown.length;
    $('.hot-cats', el).innerHTML = ['', ...shown].map((c) => `<button class="gvi-pill ${c === category ? 'is-active' : ''}" data-cat="${esc(c)}">${c ? esc(c) : 'All'}</button>`).join('') +
      (hidden ? `<button class="gvi-pill more" data-more-cats>+${hidden} more</button>` : '');
  }
  function drawContributors(top) {
    $('.top-contrib', el).innerHTML = top.length ? top.map((t) => `
      <a class="contrib-row" href="#/profile/${t.user.id}">${avatar(t.user, 'sm', { presence: false })}
        <span class="lr-main"><strong>${esc(t.user.name)}</strong><small>${esc(t.user.presence_text || presenceLabel(t.user))}</small></span>
        <span class="xp-chip">${icon('zap')}${t.xp} XP</span></a>`).join('')
      : '<p class="muted small">No activity this week yet — start a discussion to top the board.</p>';
  }
  drawCats();

  async function load(reset) {
    if (reset) {
      page = 1;
      list.innerHTML = loader();
    }
    try {
      const data = await api.get('discussions/list', { page, category, sort, q });
      if (reset) list.innerHTML = '';
      if (data.hot_categories) { hotCats = data.hot_categories; drawCats(); }
      if (data.contributors) drawContributors(data.contributors);
      if (!data.items.length && page === 1) {
        list.innerHTML = emptyState('discuss', 'No discussions here yet', 'Be the first to start one!', `<button class="gvi-btn" data-act="new">${icon('plus')}New discussion</button>`);
      } else {
        list.insertAdjacentHTML('beforeend', data.items.map(discCard).join(''));
      }
      more.innerHTML = data.has_more ? `<button class="gvi-btn ghost">${icon('refresh')}Load more</button>` : '';
      const b = $('button', more);
      if (b) b.addEventListener('click', () => { setBusy(b, true); page++; load(false); });
    } catch (err) {
      list.innerHTML = '';
      list.appendChild(errorState(err, () => load(true)));
    }
  }

  $('.gvi-disc-tools input', el).addEventListener('input', debounce((e) => { q = e.target.value.trim(); setQuery({ q }); load(true); }, 350));
  el.addEventListener('click', async (e) => {
    if (e.target.closest('[data-act="new"]')) return editor(null, (id) => navigate('#/discussions/' + id));
    if (e.target.closest('[data-more-cats]')) {
      showAllCats = true;
      return drawCats();
    }
    const cat = e.target.closest('[data-cat]');
    if (cat) {
      category = cat.dataset.cat;
      $$('[data-cat]', el).forEach((x) => x.classList.toggle('is-active', x === cat));
      setQuery({ category });
      return load(true);
    }
    const so = e.target.closest('[data-sort]');
    if (so) {
      sort = so.dataset.sort;
      $$('[data-sort]', el).forEach((x) => x.classList.toggle('is-active', x === so));
      setQuery({ sort });
      return load(true);
    }
    const card = e.target.closest('.gvi-disc-card');
    if (!card) return;
    const like = e.target.closest('[data-like]');
    if (like) {
      e.preventDefault();
      try {
        const r = await api.post('discussions/like', { id: Number(card.dataset.id) });
        like.classList.toggle('liked', r.liked);
        $('span', like).textContent = r.likes_count;
      } catch (err) {
        toastError(err);
      }
      return;
    }
    if (!e.target.closest('a')) navigate('#/discussions/' + card.dataset.id);
  });
  if (query.new) {
    setQuery({ new: null, title: null });
    editor({ title: query.title || '', body: '', category: 'News & Leaks' }, (id) => navigate('#/discussions/' + id));
  }
  load(true);
}

/** Create / edit form. */
function editor(existing, onSaved) {
  const cats = (state.config && state.config.discussion_categories) || ['General'];
  const d = existing || { title: '', body: '', category: 'General' };
  const editing = !!(existing && existing.id);
  let removeCover = false;
  const m = openModal({
    title: editing ? 'Edit discussion' : 'Start a discussion',
    size: 'lg',
    body: `
      <form class="stack disc-editor">
        <label class="field"><span>Title</span><input class="input" name="title" maxlength="200" required value="${esc(d.title)}" placeholder="e.g. Theory: who is really behind the Vice City heist?"></label>
        <label class="field"><span>Category</span><select class="input select" name="category">${cats.map((c) => `<option ${c === d.category ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></label>
        <label class="field"><span>Article</span><textarea class="input" name="body" rows="10" maxlength="20000" placeholder="Write your article… Links are clickable. Leave a blank line between paragraphs.">${esc(d.body)}</textarea><small class="muted char-count">0 / 20000</small></label>
        <div class="field"><span>Cover image (optional)</span>
          <div class="cover-pick">
            <img class="cover-preview" ${d.cover_image ? `src="${esc(asset(d.cover_image))}"` : 'hidden'} alt="">
            <label class="btn btn-ghost btn-sm">${icon('image')} Choose image<input type="file" name="file" accept="image/*" hidden></label>
            ${d.cover_image ? `<button type="button" class="btn btn-ghost btn-sm" data-act="rm-cover">${icon('trash')} Remove</button>` : ''}
          </div>
        </div>
        <p class="muted tiny">Be respectful and mark spoilers. Posts that break the rules can be removed and the author blocked.</p>
      </form>`,
    footer: `<button class="btn btn-ghost" data-act="cancel">Cancel</button><button class="btn btn-primary" data-act="save">${icon('send')} ${editing ? 'Save changes' : 'Publish (+20 XP)'}</button>`,
  });
  const form = $('form', m.el);
  const count = $('.char-count', m.el);
  const upd = () => { count.textContent = `${form.body.value.length} / 20000`; };
  form.body.addEventListener('input', upd);
  upd();
  form.file.addEventListener('change', () => {
    const f = form.file.files[0];
    if (f) {
      const img = $('.cover-preview', m.el);
      img.src = URL.createObjectURL(f);
      img.hidden = false;
      removeCover = false;
    }
  });
  const rm = $('[data-act="rm-cover"]', m.el);
  if (rm) rm.addEventListener('click', () => { removeCover = true; $('.cover-preview', m.el).hidden = true; form.file.value = ''; });
  $('[data-act="cancel"]', m.el).addEventListener('click', () => m.close());
  $('[data-act="save"]', m.el).addEventListener('click', async (e) => {
    const btn = e.target.closest('button');
    const payload = { title: form.title.value.trim(), body: form.body.value, category: form.category.value };
    if (payload.title.length < 5) return toast('The title needs at least 5 characters.', 'warn');
    if (payload.body.trim().length < 10) return toast('Write a bit more in your article.', 'warn');
    setBusy(btn, true);
    try {
      const file = form.file.files[0];
      const data = editing ? { ...payload, id: existing.id, remove_cover: removeCover } : payload;
      const route = editing ? 'discussions/update' : 'discussions/create';
      const r = file ? await api.upload(route, { ...data, file }) : await api.post(route, data);
      m.close();
      toast(editing ? 'Discussion updated' : 'Discussion published! +20 XP', 'success');
      onSaved(r.id);
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  });
}

async function renderDetail({ el, params }) {
  el.innerHTML = loader();
  let data;
  try {
    data = await api.get('discussions/get', { id: params.id });
  } catch (err) {
    el.innerHTML = `<a class="gvi-back" href="#/discussions">${icon('chevronLeft')}Discussions</a>`;
    el.appendChild(errorState(err));
    return;
  }
  const d = data.discussion;
  let comments = data.comments;

  function commentHTML(c, isReply = false) {
    const replies = comments.filter((r) => r.parent_id === c.id);
    return `<div class="comment ${isReply ? 'reply' : ''}" data-cid="${c.id}">
      <a href="#/profile/${c.author.id}">${avatar(c.author, 'sm')}</a>
      <div class="cm-main">
        <div class="cm-head">${userName(c.author)} ${levelPill(c.author.level)} <span class="muted small">· ${timeAgo(c.created_at)}${c.updated_at ? ' · edited' : ''}</span></div>
        <div class="cm-body prose">${richText(c.body)}</div>
        <div class="cm-actions">
          <button class="link-btn ${c.liked ? 'liked' : ''}" data-c="like">${icon('heart')} <span>${c.likes_count || ''}</span></button>
          ${!d.is_locked ? `<button class="link-btn" data-c="reply">${icon('discuss')} Reply</button>` : ''}
          ${c.own ? `<button class="link-btn" data-c="edit">${icon('edit')} Edit</button><button class="link-btn danger" data-c="delete">${icon('trash')} Delete</button>` : `<button class="link-btn" data-c="report">${icon('flag')} Report</button>`}
        </div>
        <div class="reply-slot"></div>
        ${!isReply && replies.length ? `<div class="replies">${replies.map((r) => commentHTML(r, true)).join('')}</div>` : ''}
      </div>
    </div>`;
  }

  function drawComments() {
    const top = comments.filter((c) => !c.parent_id || !comments.some((p) => p.id === c.parent_id));
    $('.comments-count', el).textContent = comments.length;
    $('.comment-list', el).innerHTML = top.length ? top.map((c) => commentHTML(c)).join('') : `<p class="muted">No comments yet — be the first to reply.</p>`;
  }

  async function reload() {
    try {
      const fresh = await api.get('discussions/get', { id: d.id });
      comments = fresh.comments;
      drawComments();
    } catch (err) {
      toastError(err);
    }
  }

  el.innerHTML = `
    <article class="gvi-article gvi-discussion">
      <a class="gvi-back" href="#/discussions">${icon('chevronLeft')}Discussions</a>
      ${d.cover_image ? `<div class="gvi-article-cover"><span class="gvi-media big"><img src="${esc(asset(d.cover_image))}" alt=""></span></div>` : ''}
      <div class="gvi-article-card gvi-panel ${d.cover_image ? '' : 'no-cover'}">
        <div class="dcc-meta"><span class="dcc-tag">${esc(d.category)}</span>${d.is_pinned ? `<span class="dcc-flag">${icon('pin')}Pinned</span>` : ''}${d.is_locked ? `<span class="dcc-flag">${icon('lock')}Comments closed</span>` : ''}</div>
        <h1 class="gvi-article-title">${esc(d.title)}</h1>
        <div class="gvi-author-row">
          <a href="#/profile/${d.author.id}">${avatar(d.author, 'md')}</a>
          <div><div>${userName(d.author)} ${levelPill(d.author.level)}</div><small>${fmtDateTime(d.created_at)}${d.updated_at ? ' · edited' : ''} · ${icon('eye')} ${d.views} views</small></div>
        </div>
        <div class="gvi-article-body prose">${richText(d.body)}</div>
        <div class="gvi-article-actions article-actions">
          <button class="gvi-btn ghost ${d.liked ? 'liked' : ''}" data-act="like">${icon('heart')}<span>${d.likes_count}</span></button>
          <button class="gvi-btn ghost" data-act="share">${icon('share')}Share</button>
          ${d.own ? `<button class="gvi-btn ghost" data-act="edit">${icon('edit')}Edit</button><button class="gvi-btn danger" data-act="delete">${icon('trash')}Delete</button>` : `<button class="gvi-btn ghost" data-act="report">${icon('flag')}Report</button>`}
        </div>
      </div>
    </article>
    <section class="gvi-comments gvi-panel comments">
      <h2 class="gvi-section-title">${icon('discuss')}<span>Comments (<span class="comments-count">0</span>)</span></h2>
      ${d.is_locked ? `<p class="muted">${icon('lock')} Comments are closed on this discussion.</p>` : `
      <form class="comment-form">${avatar(state.me, 'sm', { presence: false })}<textarea class="gvi-input" rows="2" maxlength="3000" placeholder="Add a comment… (+5 XP)" aria-label="Add a comment"></textarea><button class="composer-send" aria-label="Post comment">${icon('send')}</button></form>`}
      <div class="comment-list"></div>
    </section>`;
  drawComments();

  $('.article-actions', el).addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    try {
      if (act === 'like') {
        const r = await api.post('discussions/like', { id: d.id });
        const b = e.target.closest('button');
        b.classList.toggle('liked', r.liked);
        $('span', b).textContent = r.likes_count;
      } else if (act === 'share') {
        shareLink(d.title, appLink('#/discussions/' + d.id));
      } else if (act === 'report') {
        reportDialog(api, 'discussion', d.id);
      } else if (act === 'edit') {
        editor(d, () => renderDetail({ el, params }));
      } else if (act === 'delete') {
        if (!(await confirmBox('Delete this discussion and all its comments?', { confirmText: 'Delete', danger: true }))) return;
        await api.post('discussions/delete', { id: d.id });
        toast('Discussion deleted', 'success');
        navigate('#/discussions');
      }
    } catch (err) {
      toastError(err);
    }
  });

  const form = $('.comment-form', el);
  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const ta = $('textarea', form);
      if (!ta.value.trim()) return;
      const btn = $('button', form);
      setBusy(btn, true);
      try {
        await api.post('discussions/comment', { discussion_id: d.id, body: ta.value });
        ta.value = '';
        await reload();
        toast('Comment posted', 'success');
      } catch (err) {
        toastError(err);
      } finally {
        setBusy(btn, false);
      }
    });
  }

  $('.comment-list', el).addEventListener('click', async (e) => {
    const act = e.target.closest('[data-c]')?.dataset.c;
    if (!act) return;
    const box = e.target.closest('.comment');
    const id = Number(box.dataset.cid);
    const c = comments.find((x) => x.id === id);
    try {
      if (act === 'like') {
        const r = await api.post('discussions/comment-like', { id });
        c.liked = r.liked;
        c.likes_count = r.likes_count;
        const b = e.target.closest('button');
        b.classList.toggle('liked', r.liked);
        $('span', b).textContent = r.likes_count || '';
      } else if (act === 'report') {
        reportDialog(api, 'comment', id);
      } else if (act === 'delete') {
        if (!(await confirmBox('Delete this comment?', { confirmText: 'Delete', danger: true }))) return;
        await api.post('discussions/comment-delete', { id });
        reload();
      } else if (act === 'reply' || act === 'edit') {
        const slot = $('.reply-slot', box);
        if (slot.innerHTML) { slot.innerHTML = ''; return; }
        slot.innerHTML = `<form class="comment-form inline"><textarea class="gvi-input" rows="2" maxlength="3000" placeholder="${act === 'reply' ? 'Write a reply…' : ''}">${act === 'edit' ? esc(c.body) : ''}</textarea><button class="gvi-btn sm">${act === 'reply' ? 'Reply' : 'Save'}</button></form>`;
        const f = $('form', slot);
        const ta = $('textarea', f);
        ta.focus();
        f.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          if (!ta.value.trim()) return;
          setBusy($('button', f), true);
          try {
            if (act === 'reply') await api.post('discussions/comment', { discussion_id: d.id, body: ta.value, parent_id: id });
            else await api.post('discussions/comment-update', { id, body: ta.value });
            reload();
          } catch (err) {
            toastError(err);
            setBusy($('button', f), false);
          }
        });
      }
    } catch (err) {
      toastError(err);
    }
  });
}
