// Photo & video gallery with uploads (moderated), likes and a lightbox.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { esc, $, $$, icon, asset, avatar, timeAgo, openModal, confirmBox, toast, toastError, setBusy, emptyState, errorState, loader, youtubeEmbed, videoPlayer, reportDialog } from '../core/ui.js';
import { setQuery } from '../core/router.js';

const SCOPES = [['all', 'All'], ['official', 'Official'], ['community', 'Community'], ['mine', 'My uploads']];
const TYPES = [['all', 'All media'], ['photo', 'Photos'], ['video', 'Videos']];

export async function render({ el, query }) {
  let scope = SCOPES.some((s) => s[0] === query.scope) ? query.scope : 'all';
  let type = TYPES.some((t) => t[0] === query.type) ? query.type : 'all';
  let page = 1;
  let items = [];

  el.innerHTML = `
    <div class="page-head">
      <div><h2>${icon('image')} Photo & Video Gallery</h2><p class="muted">Official media and the best shots from the community.</p></div>
      <button class="btn btn-primary" data-act="upload">${icon('upload')} Upload</button>
    </div>
    <div class="filter-bar">
      <div class="tabs small">${SCOPES.map(([k, l]) => `<button class="tab ${k === scope ? 'active' : ''}" data-scope="${k}">${l}</button>`).join('')}</div>
      <div class="chip-row">${TYPES.map(([k, l]) => `<button class="chip ${k === type ? 'active' : ''}" data-type="${k}">${l}</button>`).join('')}</div>
    </div>
    <div class="gallery-wrap">${loader()}</div>
    <div class="load-more-wrap"></div>`;
  const wrap = $('.gallery-wrap', el);
  const more = $('.load-more-wrap', el);

  function tile(g, i) {
    return `<button class="g-tile ${g.media_type}" data-index="${i}">
      ${g.thumbnail ? `<img src="${esc(asset(g.thumbnail))}" alt="" loading="lazy">` : `<span class="g-video-ph">${icon('film')}</span>`}
      ${g.media_type === 'video' ? `<span class="play-badge sm">${icon('play')}</span>` : ''}
      ${g.status !== 'approved' ? `<span class="tag tag-${g.status === 'pending' ? 'warn' : 'danger'} g-status">${g.status === 'pending' ? 'Awaiting approval' : 'Not approved'}</span>` : ''}
      ${g.is_official ? `<span class="tag tag-hot g-official">${icon('star')} Official</span>` : ''}
      <span class="g-info"><span>${esc(g.title || (g.media_type === 'video' ? 'Video' : 'Photo'))}</span><span>${icon('heart')} ${g.likes_count}</span></span>
    </button>`;
  }

  async function load(reset) {
    if (reset) {
      page = 1;
      items = [];
      wrap.innerHTML = loader();
    }
    try {
      const data = await api.get('gallery/list', { scope, type, page });
      const start = items.length;
      items = items.concat(data.items);
      if (reset) wrap.innerHTML = '<div class="masonry"></div>';
      if (!items.length) {
        wrap.innerHTML = emptyState('image', scope === 'mine' ? 'You have not uploaded anything yet' : 'The gallery is empty', 'Upload your favourite GTA VI screenshots, fan art or videos.', `<button class="btn btn-primary" data-act="upload">${icon('upload')} Upload</button>`);
      } else {
        $('.masonry', wrap).insertAdjacentHTML('beforeend', data.items.map((g, i) => tile(g, start + i)).join(''));
      }
      more.innerHTML = data.has_more ? '<button class="btn btn-ghost">Load more</button>' : '';
      const b = $('button', more);
      if (b) b.addEventListener('click', () => { setBusy(b, true); page++; load(false); });
    } catch (err) {
      wrap.innerHTML = '';
      wrap.appendChild(errorState(err, () => load(true)));
    }
  }

  function lightbox(index) {
    let i = index;
    const m = openModal({ size: 'xl', className: 'lightbox', body: '<div class="lb"></div>' });
    const lb = $('.lb', m.el);
    function show() {
      const g = items[i];
      const media = g.media_type === 'photo'
        ? `<img class="lb-img" src="${esc(asset(g.file || g.thumbnail))}" alt="">`
        : g.youtube_id ? youtubeEmbed(g.youtube_id) : videoPlayer(g.file);
      lb.innerHTML = `
        <div class="lb-media">${media}
          ${i > 0 ? `<button class="lb-nav prev" data-lb="prev" aria-label="Previous">${icon('chevronLeft')}</button>` : ''}
          ${i < items.length - 1 ? `<button class="lb-nav next" data-lb="next" aria-label="Next">${icon('chevronRight')}</button>` : ''}
        </div>
        <div class="lb-meta">
          <div class="lb-text">
            <strong>${esc(g.title || (g.media_type === 'video' ? 'Video' : 'Photo'))}</strong>
            ${g.caption ? `<p>${esc(g.caption)}</p>` : ''}
            <span class="muted small">${g.author ? `${avatar(g.author, 'xs', { presence: false })} <a href="#/profile/${g.author.id}">${esc(g.author.name)}</a>` : `${icon('star')} Official`} · ${timeAgo(g.created_at)}</span>
          </div>
          <div class="row gap">
            ${g.status === 'approved' ? `<button class="btn ${g.liked ? 'btn-liked' : 'btn-ghost'} btn-sm" data-lb="like">${icon('heart')} <span>${g.likes_count}</span></button>` : ''}
            ${g.own ? `<button class="btn btn-ghost btn-sm danger" data-lb="delete">${icon('trash')}</button>` : (g.author ? `<button class="btn btn-ghost btn-sm" data-lb="report">${icon('flag')}</button>` : '')}
          </div>
        </div>`;
    }
    lb.addEventListener('click', async (e) => {
      const act = e.target.closest('[data-lb]')?.dataset.lb;
      const g = items[i];
      if (act === 'prev') { i--; show(); }
      else if (act === 'next') { i++; show(); }
      else if (act === 'like') {
        try {
          const r = await api.post('gallery/like', { id: g.id });
          g.liked = r.liked;
          g.likes_count = r.likes_count;
          show();
        } catch (err) { toastError(err); }
      } else if (act === 'report') reportDialog(api, 'gallery', g.id);
      else if (act === 'delete') {
        if (!(await confirmBox('Delete this upload?', { confirmText: 'Delete', danger: true }))) return;
        try {
          await api.post('gallery/delete', { id: g.id });
          m.close();
          toast('Deleted', 'success');
          load(true);
        } catch (err) { toastError(err); }
      }
    });
    show();
  }

  function uploadDialog() {
    let mode = 'file';
    const needsApproval = state.config && state.config.gallery_requires_approval;
    const m = openModal({
      title: 'Upload to the gallery',
      size: 'md',
      body: `
        <div class="tabs small"><button class="tab active" data-mode="file">${icon('upload')} Photo / video file</button><button class="tab" data-mode="link">${icon('link')} YouTube link</button></div>
        <form class="stack">
          <div class="up-file">
            <label class="drop-zone">${icon('image')}<span>Choose a photo (JPG, PNG, WEBP, GIF) or a short video (MP4, WEBM)</span><input type="file" name="file" accept="image/*,video/mp4,video/webm,video/quicktime" hidden></label>
            <div class="up-preview" hidden></div>
          </div>
          <label class="field up-link" hidden><span>YouTube link</span><input class="input" name="video_url" placeholder="https://www.youtube.com/watch?v=…"></label>
          <label class="field"><span>Title</span><input class="input" name="title" maxlength="150" placeholder="Give it a name"></label>
          <label class="field"><span>Caption</span><textarea class="input" name="caption" rows="2" maxlength="500" placeholder="Optional description"></textarea></label>
          ${needsApproval ? `<p class="muted small">${icon('info')} Uploads are checked by the admins before they appear publicly. You'll get a notification (+10 XP) when it's approved.</p>` : ''}
        </form>`,
      footer: `<button class="btn btn-ghost" data-act="cancel">Cancel</button><button class="btn btn-primary" data-act="send">${icon('upload')} Upload</button>`,
    });
    const form = $('form', m.el);
    $$('[data-mode]', m.el).forEach((t) => t.addEventListener('click', () => {
      mode = t.dataset.mode;
      $$('[data-mode]', m.el).forEach((x) => x.classList.toggle('active', x === t));
      $('.up-file', m.el).hidden = mode !== 'file';
      $('.up-link', m.el).hidden = mode !== 'link';
    }));
    form.file.addEventListener('change', () => {
      const f = form.file.files[0];
      const pv = $('.up-preview', m.el);
      if (!f) { pv.hidden = true; return; }
      pv.hidden = false;
      const url = URL.createObjectURL(f);
      pv.innerHTML = f.type.startsWith('video/') ? `<video src="${url}" muted controls></video>` : `<img src="${url}" alt="">`;
      if (!form.title.value) form.title.value = f.name.replace(/\.[^.]+$/, '').slice(0, 150);
    });
    $('[data-act="cancel"]', m.el).addEventListener('click', () => m.close());
    $('[data-act="send"]', m.el).addEventListener('click', async (e) => {
      const btn = e.target.closest('button');
      const data = { title: form.title.value.trim(), caption: form.caption.value.trim() };
      if (mode === 'file') {
        if (!form.file.files[0]) return toast('Choose a file first.', 'warn');
        data.file = form.file.files[0];
      } else {
        if (!form.video_url.value.trim()) return toast('Paste a YouTube link.', 'warn');
        data.video_url = form.video_url.value.trim();
      }
      setBusy(btn, true, 'Uploading…');
      try {
        const r = await api.upload('gallery/upload', data);
        m.close();
        toast(r.pending ? 'Uploaded! It will appear after admin approval.' : 'Uploaded! +10 XP', 'success', 5000);
        scope = 'mine';
        $$('[data-scope]', el).forEach((x) => x.classList.toggle('active', x.dataset.scope === scope));
        setQuery({ scope });
        load(true);
      } catch (err) {
        toastError(err);
        setBusy(btn, false);
      }
    });
  }

  el.addEventListener('click', (e) => {
    if (e.target.closest('[data-act="upload"]')) return uploadDialog();
    const t = e.target.closest('.g-tile');
    if (t) return lightbox(Number(t.dataset.index));
    const s = e.target.closest('[data-scope]');
    if (s) {
      scope = s.dataset.scope;
      $$('[data-scope]', el).forEach((x) => x.classList.toggle('active', x === s));
      setQuery({ scope });
      load(true);
    }
    const ty = e.target.closest('[data-type]');
    if (ty) {
      type = ty.dataset.type;
      $$('[data-type]', el).forEach((x) => x.classList.toggle('active', x === ty));
      setQuery({ type });
      load(true);
    }
  });
  load(true);
}
