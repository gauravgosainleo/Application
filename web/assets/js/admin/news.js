// Admin: news & articles (manual + approval queue) and the automatic news finder.
import { api } from '../core/api.js';
import { esc, $, $$, icon, asset, timeAgo, fmtDateTime, loader, errorState, confirmBox, toast, toastError, debounce, setBusy, openModal } from '../core/ui.js';
import { setQuery } from '../core/router.js';
import { pageHead, pager, formModal, send, setPending } from './admin.js';

export async function render(ctx) {
  if (ctx.path === '/news-auto') return renderAuto(ctx);
  return renderList(ctx);
}

const NEWS_FIELDS = [
  { name: 'title', label: 'Title', required: true, span: 2, max: 255 },
  { name: 'summary', label: 'Summary (shown on cards)', type: 'textarea', rows: 3, span: 2, max: 1000 },
  { name: 'content', label: 'Article text', type: 'textarea', rows: 8, span: 2, hint: 'Plain text. Leave a blank line between paragraphs. Links become clickable.' },
  { name: 'source_name', label: 'Source name (always shown)', required: true, placeholder: 'e.g. Rockstar Games, IGN' },
  { name: 'source_url', label: 'Source link', type: 'url', placeholder: 'https://…' },
  { name: 'image_url', label: 'Image link (optional)', type: 'url', placeholder: 'https://…/image.jpg' },
  { name: 'image', label: 'Or upload an image', type: 'file', preview: true },
  { name: 'status', label: 'Status', type: 'select', options: [['published', 'Published (public)'], ['pending', 'Pending approval'], ['rejected', 'Rejected']] },
  { name: 'is_featured', label: 'Feature at the top', type: 'checkbox' },
  { name: 'notify', label: 'Notify all players when published', type: 'checkbox' },
];

function editArticle(n, reload) {
  formModal({
    title: n ? 'Edit article' : 'New article',
    fields: NEWS_FIELDS,
    values: n ? { ...n, image: n.image_url && n.image_url.startsWith('uploads/') ? n.image_url : '', image_url: n.image_url && !n.image_url.startsWith('uploads/') ? n.image_url : '' } : { status: 'published', source_name: 'G VI Fan Hub' },
    submitText: n ? 'Save article' : 'Create article',
    intro: n && n.origin === 'auto' ? `<p class="muted small">${icon('info')} Found automatically on <strong>${esc(n.source_name)}</strong>. Check the facts, edit the summary if needed, then publish.</p>` : '',
    onSubmit: async (data) => {
      if (n) data.id = n.id;
      await send('admin/news-save', data);
      toast('Article saved', 'success');
      reload();
    },
  });
}

async function renderList({ el, query }) {
  let status = query.status || 'pending';
  let q = query.q || '';
  let page = 1;
  let items = [];
  el.innerHTML = pageHead('news', 'News & articles', 'Approve stories found automatically, or publish your own. The source is always shown to players.',
    `<a class="btn btn-ghost" href="#/news-auto">${icon('refresh')} Auto mode</a><button class="btn btn-primary" data-act="new">${icon('plus')} New article</button>`) + `
    <div class="filter-bar">
      <div class="tabs small">${[['pending', 'Pending approval'], ['published', 'Published'], ['rejected', 'Rejected'], ['all', 'All']].map(([k, l]) => `<button class="tab ${k === status ? 'active' : ''}" data-s="${k}">${l} <span class="tab-badge" data-count="${k}" hidden></span></button>`).join('')}</div>
      <div class="search-input small">${icon('search')}<input type="search" placeholder="Search title or source…" value="${esc(q)}"></div>
    </div>
    <div class="bulk-bar" hidden><span class="bulk-count"></span>
      <button class="btn btn-primary btn-sm" data-bulk="publish">${icon('check')} Approve & publish</button>
      <button class="btn btn-ghost btn-sm" data-bulk="reject">${icon('x')} Reject</button>
      <button class="btn btn-ghost btn-sm danger" data-bulk="delete">${icon('trash')} Delete</button></div>
    <div class="news-admin-list"></div>
    <div class="pager-wrap"></div>`;
  const list = $('.news-admin-list', el);

  async function load() {
    list.innerHTML = loader();
    try {
      const data = await api.get('admin/news', { status, q, page });
      items = data.items;
      Object.entries(data.counts).forEach(([k, v]) => {
        const b = $(`[data-count="${k}"]`, el);
        if (b) { b.hidden = !v; b.textContent = v; }
      });
      setPending({ news: data.counts.pending || 0 });
      list.innerHTML = items.length ? items.map((n) => `
        <article class="card news-admin" data-id="${n.id}">
          <label class="na-check"><input type="checkbox" class="sel" value="${n.id}"></label>
          <div class="na-thumb">${n.image_url ? `<img src="${esc(asset(n.image_url))}" alt="" loading="lazy" onerror="this.remove()">` : icon('news')}</div>
          <div class="na-main">
            <div class="row gap wrap">
              <span class="tag ${n.status === 'published' ? 'tag-ok' : n.status === 'pending' ? 'tag-warn' : 'tag-danger'}">${esc(n.status)}</span>
              <span class="tag">${n.origin === 'auto' ? `${icon('refresh')} Auto` : `${icon('edit')} Manual`}</span>
              ${n.is_featured ? `<span class="tag tag-hot">${icon('star')} Featured</span>` : ''}
              <span class="muted small">${esc(fmtDateTime(n.external_published_at || n.created_at))}</span>
            </div>
            <h3>${esc(n.title)}</h3>
            ${n.summary ? `<p class="muted small">${esc(n.summary.length > 220 ? n.summary.slice(0, 217) + '…' : n.summary)}</p>` : ''}
            <div class="na-source">${icon('link')} Source: <strong>${esc(n.source_name)}</strong>${n.source_url ? ` · <a href="${esc(n.source_url)}" target="_blank" rel="noopener">open original ${icon('external')}</a>` : ''}${n.status === 'published' ? ` · ${icon('eye')} ${n.views} views` : ''}</div>
          </div>
          <div class="na-actions">
            ${n.status !== 'published' ? `<button class="btn btn-primary btn-sm" data-act="publish">${icon('check')} Approve</button>` : `<button class="btn btn-ghost btn-sm" data-act="unpublish">${icon('eyeOff')} Unpublish</button>`}
            ${n.status === 'pending' ? `<button class="btn btn-ghost btn-sm" data-act="reject">${icon('x')} Reject</button>` : ''}
            <button class="btn btn-ghost btn-sm" data-act="edit">${icon('edit')} Edit</button>
            <button class="icon-btn danger" data-act="delete" title="Delete">${icon('trash')}</button>
          </div>
        </article>`).join('') : `<div class="empty card">${icon('news', 'empty-icon')}<h3>${status === 'pending' ? 'Nothing waiting for approval' : 'No articles here'}</h3><p>${status === 'pending' ? 'New stories found by auto mode will appear here.' : ''}</p></div>`;
      pager($('.pager-wrap', el), data, (p) => { page = p; load(); });
      updateBulk();
    } catch (err) {
      list.innerHTML = '';
      list.appendChild(errorState(err, load));
    }
  }

  function updateBulk() {
    const sel = $$('.sel:checked', list);
    $('.bulk-bar', el).hidden = !sel.length;
    $('.bulk-count', el).textContent = `${sel.length} selected`;
  }

  list.addEventListener('change', (e) => { if (e.target.classList.contains('sel')) updateBulk(); });
  list.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    const card = e.target.closest('[data-id]');
    if (!act || !card) return;
    const n = items.find((x) => x.id === Number(card.dataset.id));
    try {
      if (act === 'edit') return editArticle(n, load);
      if (act === 'delete') {
        if (!(await confirmBox(`Delete “${n.title}”?`, { confirmText: 'Delete', danger: true }))) return;
        await api.post('admin/news-delete', { id: n.id });
      } else if (act === 'publish') {
        if (!n.source_name) return toast('Add a source name first (Edit).', 'warn');
        await api.post('admin/news-status', { id: n.id, status: 'published' });
        toast('Published — players can see it now.', 'success');
      } else if (act === 'reject') {
        await api.post('admin/news-status', { id: n.id, status: 'rejected' });
      } else if (act === 'unpublish') {
        await api.post('admin/news-status', { id: n.id, status: 'pending' });
      }
      load();
    } catch (err) {
      toastError(err);
    }
  });
  $('.bulk-bar', el).addEventListener('click', async (e) => {
    const action = e.target.closest('[data-bulk]')?.dataset.bulk;
    if (!action) return;
    const ids = $$('.sel:checked', list).map((c) => Number(c.value));
    if (action === 'delete' && !(await confirmBox(`Delete ${ids.length} article(s)?`, { confirmText: 'Delete', danger: true }))) return;
    try {
      await api.post('admin/news-bulk', { ids, action });
      toast('Done', 'success');
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
  $('.filter-bar input', el).addEventListener('input', debounce((e) => { q = e.target.value.trim(); page = 1; load(); }, 300));
  $('[data-act="new"]', el).addEventListener('click', () => editArticle(null, load));
  load();
}

/* ============================================================ AUTO MODE */
async function renderAuto({ el }) {
  el.innerHTML = pageHead('refresh', 'News auto mode', 'The app checks trusted gaming news feeds for GTA 6 stories and sends them to you for approval.') + loader();
  let s;
  try {
    s = await api.get('admin/news-settings');
  } catch (err) {
    el.innerHTML = '';
    el.appendChild(errorState(err, () => renderAuto({ el })));
    return;
  }
  draw();

  function runSummary(run) {
    if (!run) return '<p class="muted">No automatic check has run yet.</p>';
    if (run.skipped) return `<p class="muted">${esc(run.skipped)}</p>`;
    return `<p><strong>${run.added}</strong> new stor${run.added === 1 ? 'y' : 'ies'} added for approval · ${esc(fmtDateTime(run.started_at))} (${esc(run.trigger)})</p>
      <table class="table small"><thead><tr><th>Source</th><th>Items</th><th>About GTA 6</th><th>New</th><th>Problem</th></tr></thead>
      <tbody>${run.sources.map((r) => `<tr><td>${esc(r.source)}</td><td>${r.found}</td><td>${r.matching}</td><td>${r.added ? `<b>${r.added}</b>` : 0}</td><td class="muted">${esc(r.error || '')}</td></tr>`).join('')}</tbody></table>`;
  }

  function draw() {
    el.innerHTML = pageHead('refresh', 'News auto mode', 'The app checks trusted gaming news feeds for GTA 6 stories and sends them to you for approval. Nothing is published without your OK.',
      `<a class="btn btn-ghost" href="#/news">${icon('news')} Approval queue</a><button class="btn btn-primary" data-act="fetch">${icon('refresh')} Check now</button>`) + `
      <div class="dash-grid two">
        <form class="card form-card auto-form">
          <h3>${icon('settings')} Auto mode</h3>
          <label class="switch"><input type="checkbox" name="auto_enabled" ${s.auto_enabled ? 'checked' : ''}><span class="slider"></span><span><strong>Automatically look for new GTA 6 news</strong></span></label>
          <div class="field"><span>Check every</span><div class="segmented">
            ${[6, 12, 24].map((h) => `<label><input type="radio" name="interval_hours" value="${h}" ${s.interval_hours === h ? 'checked' : ''}><span>${h} hours</span></label>`).join('')}</div></div>
          <label class="field"><span>Keywords (comma separated)</span><input class="input" name="keywords" value="${esc(s.keywords)}"><small class="muted">A story is kept when its title or summary contains one of these.</small></label>
          <div class="form-actions"><button class="btn btn-primary" type="submit">${icon('check')} Save</button></div>
          <p class="muted small">Last check: ${s.last_run_at ? esc(fmtDateTime(s.last_run_at)) + ' (' + timeAgo(s.last_run_at) + ')' : 'never'}${s.next_run_at ? ` · Next check after ${esc(fmtDateTime(s.next_run_at))}` : ''}</p>
        </form>
        <section class="card">
          <h3>${icon('clock')} Scheduling on Hostinger</h3>
          <p class="small">Auto mode also runs by itself while people use the app. For exact timing add a cron job in <strong>hPanel → Advanced → Cron Jobs</strong> (every hour):</p>
          <code class="code-block">${esc(s.cron_command)}</code>
          <p class="muted small">The job only fetches when the interval above has passed.</p>
        </section>
      </div>
      <section class="card run-card"><h3>${icon('zap')} Latest check</h3><div class="run-summary">${runSummary(s.last_run)}</div></section>
      <section class="card">
        <div class="row-between"><h3>${icon('link')} Trusted sources (${s.sources.length})</h3><button class="btn btn-ghost btn-sm" data-act="add-source">${icon('plus')} Add source</button></div>
        <table class="table">
          <thead><tr><th>Source</th><th>Feed</th><th>Stories</th><th>Last check</th><th>On</th><th></th></tr></thead>
          <tbody>${s.sources.map((src) => `
            <tr data-id="${src.id}">
              <td><strong>${esc(src.name)}</strong>${src.site_url ? `<small class="block"><a href="${esc(src.site_url)}" target="_blank" rel="noopener">${esc(src.site_url.replace(/^https?:\/\//, ''))}</a></small>` : ''}</td>
              <td class="muted small break">${esc(src.feed_url)}</td>
              <td>${src.stories}</td>
              <td class="small">${src.last_fetched_at ? esc(timeAgo(src.last_fetched_at)) : '—'}<small class="muted block">${esc(src.last_status || '')}</small></td>
              <td><label class="switch small"><input type="checkbox" data-act="toggle" ${src.enabled ? 'checked' : ''}><span class="slider"></span></label></td>
              <td class="actions"><button class="btn btn-ghost btn-sm" data-act="test">Test</button><button class="btn btn-ghost btn-sm" data-act="edit-source">${icon('edit')}</button><button class="icon-btn danger" data-act="del-source">${icon('trash')}</button></td>
            </tr>`).join('')}</tbody>
        </table>
      </section>`;

    $('.auto-form', el).addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      const btn = $('button[type=submit]', f);
      setBusy(btn, true);
      try {
        s = await api.post('admin/news-settings-save', {
          auto_enabled: f.auto_enabled.checked,
          interval_hours: Number(f.interval_hours.value),
          keywords: f.keywords.value,
        });
        toast(s.auto_enabled ? `Auto mode ON — checking every ${s.interval_hours} hours.` : 'Auto mode OFF.', 'success');
        draw();
      } catch (err) {
        toastError(err);
        setBusy(btn, false);
      }
    });
  }

  function sourceForm(src) {
    formModal({
      title: src ? 'Edit source' : 'Add news source',
      size: 'md',
      fields: [
        { name: 'name', label: 'Name', required: true, span: 2, placeholder: 'e.g. IGN' },
        { name: 'feed_url', label: 'RSS / Atom feed URL', type: 'url', required: true, span: 2, placeholder: 'https://site.com/feed' },
        { name: 'site_url', label: 'Website', type: 'url', span: 2 },
        { name: 'enabled', label: 'Enabled', type: 'checkbox', default: true },
      ],
      values: src || { enabled: true },
      onSubmit: async (data) => {
        if (src) data.id = src.id;
        s = await api.post('admin/news-source-save', data);
        toast('Source saved', 'success');
        draw();
      },
    });
  }

  el.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    const row = e.target.closest('tr[data-id]');
    const src = row ? s.sources.find((x) => x.id === Number(row.dataset.id)) : null;
    try {
      if (act === 'fetch') {
        const btn = e.target.closest('button');
        setBusy(btn, true, 'Checking sources…');
        const run = await api.post('admin/news-fetch');
        setBusy(btn, false);
        toast(`${run.added || 0} new stor${run.added === 1 ? 'y' : 'ies'} waiting for approval.`, 'success', 6000);
        s = await api.get('admin/news-settings');
        draw();
      } else if (act === 'add-source') sourceForm(null);
      else if (act === 'edit-source') sourceForm(src);
      else if (act === 'del-source') {
        if (!(await confirmBox(`Remove ${src.name} from the sources?`, { confirmText: 'Remove', danger: true }))) return;
        s = await api.post('admin/news-source-delete', { id: src.id });
        draw();
      } else if (act === 'test') {
        const btn = e.target.closest('button');
        setBusy(btn, true);
        const r = await api.post('admin/news-source-test', { feed_url: src.feed_url });
        setBusy(btn, false);
        openModal({
          title: `Feed test: ${src.name}`,
          size: 'md',
          body: `<p>${r.items} items in the feed, <strong>${r.matching}</strong> about GTA 6.</p><ul class="plain-list">${r.sample.map((x) => `<li><a href="${esc(x.link)}" target="_blank" rel="noopener">${esc(x.title)}</a></li>`).join('')}</ul>`,
        });
      }
    } catch (err) {
      toastError(err);
      $$('button[disabled]', el).forEach((b) => setBusy(b, false));
    }
  });
  el.addEventListener('change', async (e) => {
    if (e.target.dataset.act !== 'toggle') return;
    const row = e.target.closest('tr[data-id]');
    const src = s.sources.find((x) => x.id === Number(row.dataset.id));
    try {
      s = await api.post('admin/news-source-save', { id: src.id, name: src.name, feed_url: src.feed_url, site_url: src.site_url || '', enabled: e.target.checked });
      toast(`${src.name} ${e.target.checked ? 'enabled' : 'disabled'}`, 'success');
    } catch (err) {
      toastError(err);
    }
  });
}
