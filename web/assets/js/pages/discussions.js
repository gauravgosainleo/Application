// Discussion articles: list, read, write, like, comment, reply, report.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { esc, $, $$, icon, avatar, levelPill, timeAgo, fmtDateTime, richText, asset, openModal, confirmBox, toast, toastError, setBusy, debounce, emptyState, errorState, loader, shareLink, appLink, reportDialog, userName } from '../core/ui.js';
import { navigate, setQuery } from '../core/router.js';

export async function render(ctx) {
  if (ctx.params.id) return renderDetail(ctx);
  return renderList(ctx);
}

function discCard(d) {
  return `<a class="card disc-card" href="#/discussions/${d.id}">
    ${d.cover_image ? `<span class="dc-cover"><img src="${esc(asset(d.cover_image))}" alt="" loading="lazy"></span>` : ''}
    <span class="dc-body">
      <span class="dc-tags">${d.is_pinned ? `<span class="tag tag-hot">${icon('pin')} Pinned</span>` : ''}<span class="tag">${esc(d.category)}</span>${d.is_locked ? `<span class="tag">${icon('lock')} Locked</span>` : ''}</span>
      <strong class="dc-title">${esc(d.title)}</strong>
      <span class="dc-excerpt muted">${esc(d.excerpt)}</span>
      <span class="dc-foot">
        <span class="dc-author">${avatar(d.author, 'xs', { presence: false })}<span>${esc(d.author.name)}</span>${levelPill(d.author.level)}<span class="muted">· ${timeAgo(d.created_at)}</span></span>
        <span class="dc-stats"><span class="${d.liked ? 'liked' : ''}">${icon('heart')} ${d.likes_count}</span><span>${icon('discuss')} ${d.comments_count}</span><span>${icon('eye')} ${d.views}</span></span>
      </span>
    </span>
  </a>`;
}

async function renderList({ el, query }) {
  const cats = (state.config && state.config.discussion_categories) || [];
  let page = 1;
  let category = query.category || '';
  let sort = query.sort || 'new';
  let q = query.q || '';
  el.innerHTML = `
    <div class="page-head">
      <div><h2>${icon('discuss')} Discussions</h2><p class="muted">Write articles, share theories and talk GTA VI with the community.</p></div>
      <button class="btn btn-primary" data-act="new">${icon('edit')} Start a discussion</button>
    </div>
    <div class="filter-bar">
      <div class="chip-row scroll">${['', ...cats].map((c) => `<button class="chip ${c === category ? 'active' : ''}" data-cat="${esc(c)}">${c ? esc(c) : 'All'}</button>`).join('')}</div>
      <div class="filter-right">
        <div class="search-input small">${icon('search')}<input type="search" placeholder="Search…" value="${esc(q)}"></div>
        <select class="input select small sort">${[['new', 'Newest'], ['active', 'Recently active'], ['top', 'Top']].map(([v, l]) => `<option value="${v}" ${v === sort ? 'selected' : ''}>${l}</option>`).join('')}</select>
      </div>
    </div>
    <div class="disc-list"></div>
    <div class="load-more-wrap"></div>`;
  const list = $('.disc-list', el);
  const more = $('.load-more-wrap', el);

  async function load(reset) {
    if (reset) {
      page = 1;
      list.innerHTML = loader();
    }
    try {
      const data = await api.get('discussions/list', { page, category, sort, q });
      if (reset) list.innerHTML = '';
      if (!data.items.length && page === 1) {
        list.innerHTML = emptyState('discuss', 'No discussions here yet', 'Be the first to start one!', `<button class="btn btn-primary" data-act="new">${icon('edit')} Start a discussion</button>`);
      } else {
        list.insertAdjacentHTML('beforeend', data.items.map(discCard).join(''));
      }
      more.innerHTML = data.has_more ? '<button class="btn btn-ghost">Load more</button>' : '';
      const b = $('button', more);
      if (b) b.addEventListener('click', () => { setBusy(b, true); page++; load(false); });
    } catch (err) {
      list.innerHTML = '';
      list.appendChild(errorState(err, () => load(true)));
    }
  }

  $$('[data-cat]', el).forEach((c) => c.addEventListener('click', () => {
    category = c.dataset.cat;
    $$('[data-cat]', el).forEach((x) => x.classList.toggle('active', x === c));
    setQuery({ category });
    load(true);
  }));
  $('.sort', el).addEventListener('change', (e) => { sort = e.target.value; setQuery({ sort }); load(true); });
  $('.filter-right input', el).addEventListener('input', debounce((e) => { q = e.target.value.trim(); setQuery({ q }); load(true); }, 350));
  el.addEventListener('click', (e) => {
    if (e.target.closest('[data-act="new"]')) editor(null, (id) => navigate('#/discussions/' + id));
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
    el.innerHTML = `<a class="back-link" href="#/discussions">${icon('chevronLeft')} Discussions</a>`;
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
    <article class="article discussion">
      <a class="back-link" href="#/discussions">${icon('chevronLeft')} Discussions</a>
      ${d.cover_image ? `<div class="article-cover"><img src="${esc(asset(d.cover_image))}" alt=""></div>` : ''}
      <div class="dc-tags">${d.is_pinned ? `<span class="tag tag-hot">${icon('pin')} Pinned</span>` : ''}<span class="tag">${esc(d.category)}</span>${d.is_locked ? `<span class="tag">${icon('lock')} Comments closed</span>` : ''}</div>
      <h1 class="article-title">${esc(d.title)}</h1>
      <div class="author-row">
        <a href="#/profile/${d.author.id}">${avatar(d.author, 'md')}</a>
        <div><div>${userName(d.author)} ${levelPill(d.author.level)}</div><span class="muted small">${fmtDateTime(d.created_at)}${d.updated_at ? ' · edited' : ''} · ${icon('eye')} ${d.views} views</span></div>
      </div>
      <div class="article-body prose">${richText(d.body)}</div>
      <div class="article-actions">
        <button class="btn ${d.liked ? 'btn-liked' : 'btn-ghost'}" data-act="like">${icon('heart')} <span>${d.likes_count}</span></button>
        <button class="btn btn-ghost" data-act="share">${icon('share')} Share</button>
        ${d.own ? `<button class="btn btn-ghost" data-act="edit">${icon('edit')} Edit</button><button class="btn btn-ghost danger" data-act="delete">${icon('trash')} Delete</button>` : `<button class="btn btn-ghost" data-act="report">${icon('flag')} Report</button>`}
      </div>
    </article>
    <section class="comments card">
      <h3>${icon('discuss')} Comments (<span class="comments-count">0</span>)</h3>
      ${d.is_locked ? `<p class="muted">${icon('lock')} Comments are closed on this discussion.</p>` : `
      <form class="comment-form">${avatar(state.me, 'sm', { presence: false })}<textarea class="input" rows="2" maxlength="3000" placeholder="Add a comment… (+5 XP)"></textarea><button class="btn btn-primary">${icon('send')}</button></form>`}
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
        b.className = `btn ${r.liked ? 'btn-liked' : 'btn-ghost'}`;
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
        slot.innerHTML = `<form class="comment-form inline"><textarea class="input" rows="2" maxlength="3000" placeholder="${act === 'reply' ? 'Write a reply…' : ''}">${act === 'edit' ? esc(c.body) : ''}</textarea><button class="btn btn-primary btn-sm">${act === 'reply' ? 'Reply' : 'Save'}</button></form>`;
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
