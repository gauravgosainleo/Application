// Admin: trailers, discussion moderation, reports and gallery approval.
import { api } from '../core/api.js';
import { esc, $, $$, icon, asset, avatar, timeAgo, fmtDate, fmtDateTime, loader, errorState, confirmBox, toast, toastError, debounce, openModal, richText, youtubeEmbed, videoPlayer } from '../core/ui.js';
import { setQuery } from '../core/router.js';
import { pageHead, pager, formModal, send, banDialog, setPending } from './admin.js';

export async function render(ctx) {
  if (ctx.path === '/trailers') return trailers(ctx);
  if (ctx.path === '/discussions') return discussions(ctx);
  if (ctx.path === '/reports') return reports(ctx);
  return gallery(ctx);
}

/* =============================================================== TRAILERS */
async function trailers({ el }) {
  let list = [];
  const form = (t) => formModal({
    title: t ? 'Edit trailer' : 'Add trailer',
    size: 'md',
    fields: [
      { name: 'title', label: 'Title', required: true, span: 2 },
      { name: 'url', label: 'YouTube link or video URL', required: true, span: 2, placeholder: 'https://www.youtube.com/watch?v=…' },
      { name: 'description', label: 'Description', type: 'textarea', rows: 3, span: 2 },
      { name: 'released_on', label: 'Release date', type: 'date' },
      { name: 'sort_order', label: 'Order (lower = first)', type: 'number', default: 0 },
      { name: 'thumbnail', label: 'Custom thumbnail (optional)', type: 'file' },
      { name: 'is_featured', label: 'Featured (shown first and on the home page)', type: 'checkbox' },
      { name: 'notify', label: 'Notify all players', type: 'checkbox' },
    ],
    values: t ? { ...t, url: t.youtube_id ? `https://www.youtube.com/watch?v=${t.youtube_id}` : t.video_url } : { sort_order: 0 },
    onSubmit: async (data) => {
      if (t) data.id = t.id;
      await send('admin/trailer-save', data);
      toast('Trailer saved', 'success');
      load();
    },
  });
  el.innerHTML = pageHead('film', 'Trailers', 'Add or remove the trailers players can watch.', `<button class="btn btn-primary" data-act="add">${icon('plus')} Add trailer</button>`) + '<div class="tr-list"></div>';
  const box = $('.tr-list', el);
  async function load() {
    box.innerHTML = loader();
    try {
      list = await api.get('admin/trailers');
      box.innerHTML = list.length ? `<div class="admin-grid">${list.map((t) => `
        <article class="card admin-media" data-id="${t.id}">
          <button class="am-thumb" data-act="play">${t.thumbnail ? `<img src="${esc(asset(t.thumbnail))}" alt="">` : ''}<span class="play-badge sm">${icon('play')}</span></button>
          <div class="am-body">
            <div class="row gap wrap">${t.is_featured ? `<span class="tag tag-hot">${icon('star')} Featured</span>` : ''}<span class="muted small">${esc(fmtDate(t.released_on) || '')}</span></div>
            <strong>${esc(t.title)}</strong>
            ${t.description ? `<p class="muted small">${esc(t.description)}</p>` : ''}
            <div class="row gap"><button class="btn btn-ghost btn-sm" data-act="edit">${icon('edit')} Edit</button><button class="btn btn-ghost btn-sm danger" data-act="delete">${icon('trash')} Remove</button></div>
          </div>
        </article>`).join('')}</div>` : `<div class="empty card">${icon('film', 'empty-icon')}<h3>No trailers</h3></div>`;
    } catch (err) {
      box.innerHTML = '';
      box.appendChild(errorState(err, load));
    }
  }
  el.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    if (act === 'add') return form(null);
    const t = list.find((x) => x.id === Number(e.target.closest('[data-id]')?.dataset.id));
    if (!t) return;
    if (act === 'edit') form(t);
    if (act === 'play') openModal({ title: t.title, size: 'xl', className: 'video-modal', body: t.youtube_id ? youtubeEmbed(t.youtube_id) : videoPlayer(t.video_url) });
    if (act === 'delete') {
      if (!(await confirmBox(`Remove “${t.title}”?`, { confirmText: 'Remove', danger: true }))) return;
      try {
        await api.post('admin/trailer-delete', { id: t.id });
        toast('Trailer removed', 'success');
        load();
      } catch (err) {
        toastError(err);
      }
    }
  });
  load();
}

/* ============================================================ DISCUSSIONS */
async function deleteDiscussion(d, done) {
  const r = await banDialog(d.author ? d.author.name : 'the author', { title: 'Delete discussion', allowNone: true });
  if (!r) return;
  try {
    const res = await api.post('admin/discussion-delete', { id: d.id, ban_days: r.days, ban_reason: r.reason, reason: r.reason });
    toast(res.banned ? 'Discussion deleted and the author was blocked.' : 'Discussion deleted.', 'success');
    done();
  } catch (err) {
    toastError(err);
  }
}

async function discussions({ el, query }) {
  let q = query.q || '';
  let filter = query.filter || 'all';
  let page = 1;
  let items = [];
  el.innerHTML = pageHead('discuss', 'Discussions', 'Review player articles. Delete posts that break the rules and optionally block the author.') + `
    <div class="filter-bar">
      <div class="search-input">${icon('search')}<input type="search" placeholder="Search title, text or author…" value="${esc(q)}"></div>
      <div class="tabs small">${[['all', 'All'], ['reported', 'Reported'], ['pinned', 'Pinned'], ['locked', 'Locked']].map(([k, l]) => `<button class="tab ${k === filter ? 'active' : ''}" data-f="${k}">${l}</button>`).join('')}</div>
    </div>
    <div class="table-wrap card"></div><div class="pager-wrap"></div>`;
  const wrap = $('.table-wrap', el);
  async function load() {
    wrap.innerHTML = loader();
    try {
      const data = await api.get('admin/discussions', { q, filter, page });
      items = data.items;
      wrap.innerHTML = items.length ? `<table class="table"><thead><tr><th>Discussion</th><th>Author</th><th>Activity</th><th>Posted</th><th></th></tr></thead><tbody>
        ${items.map((d) => `<tr data-id="${d.id}">
          <td><strong>${esc(d.title)}</strong>${d.reports ? ` <span class="tag tag-danger">${icon('flag')} ${d.reports} report${d.reports > 1 ? 's' : ''}</span>` : ''}${d.is_pinned ? ` <span class="tag tag-hot">Pinned</span>` : ''}${d.is_locked ? ` <span class="tag">Locked</span>` : ''}<small class="muted block">${esc(d.category)} · ${esc(d.excerpt)}</small></td>
          <td>${d.author ? `<div class="cell-user">${avatar(d.author, 'xs', { presence: false })}<div><strong>${esc(d.author.name)}</strong><small class="muted">@${esc(d.author.username)}${d.author.banned ? ' · blocked' : ''}</small></div></div>` : '—'}</td>
          <td class="small muted">${icon('heart')} ${d.likes_count} · ${icon('discuss')} ${d.comments_count} · ${icon('eye')} ${d.views}</td>
          <td class="small muted">${esc(timeAgo(d.created_at))}</td>
          <td class="actions"><button class="btn btn-ghost btn-sm" data-act="view">${icon('eye')} Review</button><button class="btn btn-danger btn-sm" data-act="delete">${icon('trash')} Delete</button></td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty small"><p>No discussions found.</p></div>`;
      pager($('.pager-wrap', el), data, (p) => { page = p; load(); });
    } catch (err) {
      wrap.innerHTML = '';
      wrap.appendChild(errorState(err, load));
    }
  }
  async function review(id) {
    const m = openModal({ title: 'Review discussion', size: 'lg', body: loader() });
    try {
      const { discussion: d, comments } = await api.get('admin/discussion', { id });
      m.body.innerHTML = `
        <div class="review">
          <div class="row gap wrap"><span class="tag">${esc(d.category)}</span><span class="muted small">${esc(fmtDateTime(d.created_at))}</span></div>
          <h2>${esc(d.title)}</h2>
          <p class="muted">By <strong>${esc(d.author ? d.author.name : 'deleted')}</strong> (@${esc(d.author ? d.author.username : '')}, ${esc(d.author ? d.author.email : '')})</p>
          ${d.cover_image ? `<img class="review-cover" src="${esc(asset(d.cover_image))}" alt="">` : ''}
          <div class="prose">${richText(d.body)}</div>
          <div class="row gap wrap review-actions">
            <button class="btn btn-ghost btn-sm" data-r="pin">${icon('pin')} ${d.is_pinned ? 'Unpin' : 'Pin'}</button>
            <button class="btn btn-ghost btn-sm" data-r="lock">${icon('lock')} ${d.is_locked ? 'Unlock comments' : 'Lock comments'}</button>
            <button class="btn btn-danger btn-sm" data-r="delete">${icon('trash')} Delete (and block author)</button>
          </div>
          <h4>${comments.length} comment${comments.length === 1 ? '' : 's'}</h4>
          <div class="review-comments">${comments.map((c) => `
            <div class="review-comment ${c.parent_id ? 'reply' : ''}" data-cid="${c.id}">
              <div class="row-between"><strong>${esc(c.author ? c.author.name : 'deleted')} <small class="muted">@${esc(c.author ? c.author.username : '')} · ${timeAgo(c.created_at)}</small></strong>
                <button class="btn btn-ghost btn-sm danger" data-r="del-comment">${icon('trash')}</button></div>
              <p>${esc(c.body)}</p>
            </div>`).join('') || '<p class="muted">No comments.</p>'}</div>
        </div>`;
      m.body.addEventListener('click', async (e) => {
        const act = e.target.closest('[data-r]')?.dataset.r;
        if (!act) return;
        try {
          if (act === 'pin' || act === 'lock') {
            await api.post('admin/discussion-flag', { id: d.id, field: act === 'pin' ? 'is_pinned' : 'is_locked', value: act === 'pin' ? !d.is_pinned : !d.is_locked });
            toast('Updated', 'success');
            m.close();
            review(d.id);
            load();
          } else if (act === 'delete') {
            m.close();
            deleteDiscussion(d, load);
          } else if (act === 'del-comment') {
            const cid = Number(e.target.closest('[data-cid]').dataset.cid);
            const c = comments.find((x) => x.id === cid);
            const r = await banDialog(c.author ? c.author.name : 'the author', { title: 'Delete comment', allowNone: true });
            if (!r) return;
            await api.post('admin/comment-delete', { id: cid, ban_days: r.days, ban_reason: r.reason });
            toast('Comment deleted', 'success');
            m.close();
            review(d.id);
            load();
          }
        } catch (err) {
          toastError(err);
        }
      });
    } catch (err) {
      m.body.innerHTML = '';
      m.body.appendChild(errorState(err));
    }
  }
  wrap.addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    const d = items.find((x) => x.id === Number(e.target.closest('tr[data-id]')?.dataset.id));
    if (!act || !d) return;
    if (act === 'view') review(d.id);
    if (act === 'delete') deleteDiscussion(d, load);
  });
  $$('[data-f]', el).forEach((t) => t.addEventListener('click', () => {
    filter = t.dataset.f;
    page = 1;
    $$('[data-f]', el).forEach((x) => x.classList.toggle('active', x === t));
    setQuery({ filter });
    load();
  }));
  $('.filter-bar input', el).addEventListener('input', debounce((e) => { q = e.target.value.trim(); page = 1; load(); }, 300));
  load();
}

/* ================================================================ REPORTS */
function appLink(link) {
  if (!link) return '';
  const [type, id] = link.split(':');
  if (type === 'discussion') return `../#/discussions/${id}`;
  if (type === 'user') return `../#/profile/${id}`;
  return '';
}

async function reports({ el, query }) {
  let status = query.status || 'open';
  let items = [];
  el.innerHTML = pageHead('flag', 'Reports', 'Content reported by players.') + `
    <div class="tabs small">${[['open', 'Open'], ['resolved', 'Resolved'], ['dismissed', 'Dismissed'], ['all', 'All']].map(([k, l]) => `<button class="tab ${k === status ? 'active' : ''}" data-s="${k}">${l}</button>`).join('')}</div>
    <div class="reports-list"></div>`;
  const box = $('.reports-list', el);
  async function load() {
    box.innerHTML = loader();
    try {
      items = await api.get('admin/reports', { status });
      if (status === 'open') setPending({ reports: items.length });
      box.innerHTML = items.length ? items.map((r) => `
        <article class="card report ${r.child_safety ? 'report-csae' : ''}" data-id="${r.id}">
          <div class="row gap wrap">${r.child_safety ? `<span class="tag tag-live">${icon('alert')} Child safety: act now</span>` : ''}<span class="tag tag-danger">${esc(r.target_type)}</span><span class="tag ${r.status === 'open' ? 'tag-warn' : ''}">${esc(r.status)}</span><span class="muted small">${esc(fmtDateTime(r.created_at))}</span></div>
          <p class="report-reason">“${esc(r.reason)}”</p>
          <div class="report-target">${r.target.image ? `<img src="${esc(asset(r.target.image))}" alt="">` : ''}<span>${esc(r.target.preview)}</span></div>
          <p class="muted small">Reported by ${r.reporter ? `<strong>${esc(r.reporter.name)}</strong> (@${esc(r.reporter.username)})` : 'a deleted user'}${r.owner ? ` · Content by <strong>${esc(r.owner.name)}</strong> (@${esc(r.owner.username)})${r.owner.banned ? ' — already blocked' : ''}` : ''}</p>
          <div class="row gap wrap">
            ${r.target.exists && r.target_type !== 'user' ? `<button class="btn btn-danger btn-sm" data-act="remove">${icon('trash')} Remove content</button>` : ''}
            ${r.owner && !r.owner.banned ? `<button class="btn btn-ghost btn-sm" data-act="ban">${icon('ban')} Block ${esc(r.owner.name)}</button>` : ''}
            ${appLink(r.target.link) ? `<a class="btn btn-ghost btn-sm" href="${appLink(r.target.link)}" target="_blank" rel="noopener">${icon('external')} View in app</a>` : ''}
            ${r.status === 'open' ? `<button class="btn btn-ghost btn-sm" data-act="resolve">${icon('check')} Mark resolved</button><button class="btn btn-ghost btn-sm" data-act="dismiss">Dismiss</button>` : `<button class="btn btn-ghost btn-sm" data-act="reopen">Reopen</button>`}
          </div>
        </article>`).join('') : `<div class="empty card">${icon('check', 'empty-icon')}<h3>No ${status === 'all' ? '' : status} reports</h3></div>`;
    } catch (err) {
      box.innerHTML = '';
      box.appendChild(errorState(err, load));
    }
  }
  box.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    const r = items.find((x) => x.id === Number(e.target.closest('[data-id]')?.dataset.id));
    if (!act || !r) return;
    try {
      if (act === 'remove') {
        if (!(await confirmBox('Remove the reported content?', { confirmText: 'Remove', danger: true }))) return;
        await api.post('admin/report-remove-content', { id: r.id });
        toast('Content removed', 'success');
      } else if (act === 'ban') {
        const b = await banDialog(r.owner.name);
        if (!b) return;
        await api.post('admin/ban', { user_id: r.owner.id, days: b.days, reason: b.reason, context: 'report', context_ref: `Report #${r.id}: ${r.reason}` });
        await api.post('admin/report-resolve', { id: r.id, status: 'resolved' });
        toast(`${r.owner.name} blocked`, 'success');
      } else {
        await api.post('admin/report-resolve', { id: r.id, status: { resolve: 'resolved', dismiss: 'dismissed', reopen: 'open' }[act] });
      }
      load();
    } catch (err) {
      toastError(err);
    }
  });
  $$('[data-s]', el).forEach((t) => t.addEventListener('click', () => {
    status = t.dataset.s;
    $$('[data-s]', el).forEach((x) => x.classList.toggle('active', x === t));
    setQuery({ status });
    load();
  }));
  load();
}

/* ================================================================ GALLERY */
async function gallery({ el, query }) {
  let status = query.status || 'pending';
  let page = 1;
  let items = [];
  el.innerHTML = pageHead('image', 'Gallery', 'Approve player uploads and post official photos & videos.', `<button class="btn btn-primary" data-act="add">${icon('upload')} Add official media</button>`) + `
    <div class="tabs small">${[['pending', 'Waiting for approval'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['all', 'All']].map(([k, l]) => `<button class="tab ${k === status ? 'active' : ''}" data-s="${k}">${l}</button>`).join('')}</div>
    <div class="g-admin"></div><div class="pager-wrap"></div>`;
  const box = $('.g-admin', el);
  async function load() {
    box.innerHTML = loader();
    try {
      const data = await api.get('admin/gallery', { status, page });
      items = data.items;
      if (status === 'pending') setPending({ gallery: data.total });
      box.innerHTML = items.length ? `<div class="admin-grid">${items.map((g) => `
        <article class="card admin-media" data-id="${g.id}">
          <button class="am-thumb" data-act="view">${g.thumbnail ? `<img src="${esc(asset(g.thumbnail))}" alt="" loading="lazy">` : `<span class="g-video-ph">${icon('film')}</span>`}${g.media_type === 'video' ? `<span class="play-badge sm">${icon('play')}</span>` : ''}</button>
          <div class="am-body">
            <div class="row gap wrap"><span class="tag ${g.status === 'approved' ? 'tag-ok' : g.status === 'pending' ? 'tag-warn' : 'tag-danger'}">${esc(g.status)}</span>${g.is_official ? `<span class="tag tag-hot">Official</span>` : ''}<span class="muted small">${timeAgo(g.created_at)}</span></div>
            <strong>${esc(g.title || (g.media_type === 'video' ? 'Video' : 'Photo'))}</strong>
            <span class="muted small">${g.author ? `by ${esc(g.author.name)} (@${esc(g.author.username)})` : 'Posted by admins'}</span>
            <div class="row gap wrap">
              ${g.status !== 'approved' ? `<button class="btn btn-primary btn-sm" data-act="approve">${icon('check')} Approve</button>` : ''}
              ${g.status !== 'rejected' && !g.is_official ? `<button class="btn btn-ghost btn-sm" data-act="reject">${icon('x')} Reject</button>` : ''}
              <button class="icon-btn danger" data-act="delete" title="Delete">${icon('trash')}</button>
            </div>
          </div>
        </article>`).join('')}</div>` : `<div class="empty card">${icon('image', 'empty-icon')}<h3>${status === 'pending' ? 'No uploads waiting' : 'Nothing here'}</h3></div>`;
      pager($('.pager-wrap', el), data, (p) => { page = p; load(); });
    } catch (err) {
      box.innerHTML = '';
      box.appendChild(errorState(err, load));
    }
  }
  el.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    if (act === 'add') {
      return formModal({
        title: 'Add official media',
        size: 'md',
        fields: [
          { name: 'file', label: 'Photo or video file', type: 'file', accept: 'image/*,video/mp4,video/webm', span: 2 },
          { name: 'video_url', label: '…or YouTube link', type: 'url', span: 2 },
          { name: 'title', label: 'Title', span: 2 },
          { name: 'caption', label: 'Caption', type: 'textarea', rows: 2, span: 2 },
        ],
        onSubmit: async (data) => {
          await send('admin/gallery-save', data);
          toast('Added to the gallery', 'success');
          status = 'approved';
          $$('[data-s]', el).forEach((x) => x.classList.toggle('active', x.dataset.s === status));
          load();
        },
      });
    }
    const s = e.target.closest('[data-s]');
    if (s) return;
    const g = items.find((x) => x.id === Number(e.target.closest('[data-id]')?.dataset.id));
    if (!g) return;
    try {
      if (act === 'view') {
        openModal({ title: g.title || 'Media', size: 'xl', className: 'video-modal', body: g.media_type === 'photo' ? `<img class="lb-img" src="${esc(asset(g.file || g.thumbnail))}" alt="" style="margin:0 auto">` : g.youtube_id ? youtubeEmbed(g.youtube_id) : videoPlayer(g.file) });
        return;
      }
      if (act === 'approve') await api.post('admin/gallery-status', { id: g.id, status: 'approved' });
      if (act === 'reject') await api.post('admin/gallery-status', { id: g.id, status: 'rejected' });
      if (act === 'delete') {
        if (!(await confirmBox('Delete this item?', { confirmText: 'Delete', danger: true }))) return;
        await api.post('admin/gallery-delete', { id: g.id });
      }
      load();
    } catch (err) {
      toastError(err);
    }
  });
  $$('[data-s]', el).forEach((t) => t.addEventListener('click', () => {
    status = t.dataset.s;
    page = 1;
    $$('[data-s]', el).forEach((x) => x.classList.toggle('active', x === t));
    setQuery({ status });
    load();
  }));
  load();
}
