// G VI Fan Hub - admin panel entry: login, layout, routing and shared form helpers.
import { api } from '../core/api.js';
import { ui, esc, el, $, $$, icon, toast, toastError, setBusy, openModal, closeAllModals, asset } from '../core/ui.js';
import { defineRoute, startRouter, stopRouter } from '../core/router.js';

api.base = '../api/index.php';
ui.assetBase = '../';

export const admin = { me: null, pending: { news: 0, gallery: 0, reports: 0 }, config: null };
const root = document.getElementById('app');

const NAV = [
  ['dashboard', 'grid', 'Dashboard', 'Overview'],
  ['users', 'users', 'Users & bans', 'Community'],
  ['news', 'news', 'News & articles', 'Content', 'news'],
  ['news-auto', 'refresh', 'News auto mode', 'Content'],
  ['trailers', 'film', 'Trailers', 'Content'],
  ['discussions', 'discuss', 'Discussions', 'Moderation'],
  ['reports', 'flag', 'Reports', 'Moderation', 'reports'],
  ['gallery', 'image', 'Gallery', 'Moderation', 'gallery'],
  ['catalogue', 'car', 'Catalogue', 'Content'],
  ['polls', 'poll', 'Polls', 'Engagement'],
  ['events', 'calendar', 'Events', 'Engagement'],
  ['rewards', 'gift', 'Rewards', 'Engagement'],
  ['broadcast', 'bell', 'Broadcast', 'Engagement'],
  ['settings', 'settings', 'Settings', 'System'],
  ['account', 'shield', 'Admin account', 'System'],
];

const page = (file) => () => import(`./${file}.js`);
defineRoute('/dashboard', page('dashboard'), { key: 'dashboard', title: 'Dashboard' });
defineRoute('/users', page('users'), { key: 'users', title: 'Users & bans' });
defineRoute('/news', page('news'), { key: 'news', title: 'News & articles' });
defineRoute('/news-auto', page('news'), { key: 'news-auto', title: 'News auto mode' });
defineRoute('/trailers', page('content'), { key: 'trailers', title: 'Trailers' });
defineRoute('/discussions', page('content'), { key: 'discussions', title: 'Discussions' });
defineRoute('/reports', page('content'), { key: 'reports', title: 'Reports' });
defineRoute('/gallery', page('content'), { key: 'gallery', title: 'Gallery' });
defineRoute('/catalogue', page('catalogue'), { key: 'catalogue', title: 'Catalogue' });
defineRoute('/polls', page('engage'), { key: 'polls', title: 'Polls' });
defineRoute('/events', page('engage'), { key: 'events', title: 'Events' });
defineRoute('/rewards', page('engage'), { key: 'rewards', title: 'Rewards' });
defineRoute('/broadcast', page('system'), { key: 'broadcast', title: 'Broadcast' });
defineRoute('/settings', page('system'), { key: 'settings', title: 'Settings' });
defineRoute('/account', page('system'), { key: 'account', title: 'Admin account' });
defineRoute('/home', page('dashboard'), { key: 'dashboard', title: 'Dashboard' });

/* ------------------------------------------------------------ helpers */

/** ISO string -> value for <input type="datetime-local"> (browser time zone). */
export function toLocalInput(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return '';
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** <input type="datetime-local"> value -> ISO string in UTC. */
export function fromLocalInput(value) {
  if (!value) return '';
  const d = new Date(value);
  return isNaN(d) ? '' : d.toISOString();
}

export function setPending(p) {
  if (p) admin.pending = { ...admin.pending, ...p };
  $$('[data-pending]').forEach((b) => {
    const n = admin.pending[b.dataset.pending] || 0;
    b.hidden = !n;
    b.textContent = n;
  });
}

function fieldHTML(f, value) {
  const span = f.span === 2 ? ' span-2' : '';
  const hint = f.hint ? `<small class="muted">${f.hint}</small>` : '';
  const req = f.required ? ' required' : '';
  const ph = f.placeholder ? ` placeholder="${esc(f.placeholder)}"` : '';
  const v = value === undefined || value === null ? (f.default ?? '') : value;
  switch (f.type) {
    case 'textarea':
      return `<label class="field${span}"><span>${esc(f.label)}</span><textarea class="input" name="${f.name}" rows="${f.rows || 4}"${ph}${req} maxlength="${f.max || 60000}">${esc(v)}</textarea>${hint}</label>`;
    case 'select':
      return `<label class="field${span}"><span>${esc(f.label)}</span><select class="input select" name="${f.name}">${f.options.map(([ov, ol]) => `<option value="${esc(ov)}" ${String(ov) === String(v) ? 'selected' : ''}>${esc(ol)}</option>`).join('')}</select>${hint}</label>`;
    case 'checkbox':
      return `<label class="check field-check${span}"><input type="checkbox" name="${f.name}" ${v === true || v === 1 || v === '1' ? 'checked' : ''}><span>${esc(f.label)}</span></label>${hint ? `<div class="${span}">${hint}</div>` : ''}`;
    case 'datetime':
      return `<label class="field${span}"><span>${esc(f.label)}</span><input class="input" type="datetime-local" name="${f.name}" value="${esc(toLocalInput(v))}"${req}>${hint || '<small class="muted">Your local time</small>'}</label>`;
    case 'date':
      return `<label class="field${span}"><span>${esc(f.label)}</span><input class="input" type="date" name="${f.name}" value="${esc(v)}"${req}>${hint}</label>`;
    case 'file':
      return `<label class="field${span}"><span>${esc(f.label)}</span>${f.preview && v ? `<img class="field-preview" src="${esc(asset(v))}" alt="">` : ''}<input class="input" type="file" name="${f.name}" accept="${esc(f.accept || 'image/*')}">${hint}</label>`;
    case 'list':
      return `<div class="field${span}" data-list="${f.name}"><span>${esc(f.label)}</span><div class="list-rows">${(v || []).map((item) => listRow(item)).join('')}</div>
        <button type="button" class="btn btn-ghost btn-sm" data-add-row>${icon('plus')} Add option</button>${hint}</div>`;
    case 'stats':
      return `<div class="field${span}" data-stats="${f.name}"><span>${esc(f.label)}</span><div class="stat-rows">${(v || []).map((s) => statRow(s)).join('')}</div>
        <button type="button" class="btn btn-ghost btn-sm" data-add-stat>${icon('plus')} Add stat</button>${hint}</div>`;
    case 'html':
      return `<div class="field${span}">${f.html}</div>`;
    default:
      return `<label class="field${span}"><span>${esc(f.label)}</span><input class="input" type="${f.type || 'text'}" name="${f.name}" value="${esc(v)}"${ph}${req}${f.max ? ` maxlength="${f.max}"` : ''}${f.min !== undefined ? ` min="${f.min}"` : ''}>${hint}</label>`;
  }
}

function listRow(item) {
  const id = item && typeof item === 'object' ? item.id || '' : '';
  const label = item && typeof item === 'object' ? item.label : (item || '');
  const votes = item && typeof item === 'object' && item.votes ? `<small class="muted">${item.votes} votes</small>` : '';
  return `<div class="list-row-edit" data-id="${esc(id)}"><input class="input" value="${esc(label)}" maxlength="150" placeholder="Option text">${votes}<button type="button" class="icon-btn danger" data-del-row title="Remove">${icon('x')}</button></div>`;
}

function statRow(s = { label: '', value: '' }) {
  return `<div class="stat-row-edit"><input class="input" data-k="label" value="${esc(s.label)}" placeholder="Label (e.g. Top speed)" maxlength="40"><input class="input" data-k="value" value="${esc(s.value)}" placeholder="0-100 or text" maxlength="60"><button type="button" class="icon-btn danger" data-del-row title="Remove">${icon('x')}</button></div>`;
}

/**
 * Generic form modal. fields: [{name,label,type,options,required,span,hint,...}]
 * onSubmit(data) may return a promise; the modal closes when it resolves.
 */
export function formModal({ title, fields, values = {}, submitText = 'Save', size = 'lg', onSubmit, intro = '' }) {
  const m = openModal({
    title,
    size,
    body: `${intro}<form class="admin-form form-grid" novalidate>${fields.map((f) => fieldHTML(f, values[f.name])).join('')}</form>`,
    footer: `<button class="btn btn-ghost" data-x="cancel">Cancel</button><button class="btn btn-primary" data-x="save">${icon('check')} ${esc(submitText)}</button>`,
  });
  const form = $('form', m.el);
  form.addEventListener('click', (e) => {
    if (e.target.closest('[data-add-row]')) {
      e.target.closest('[data-list]').querySelector('.list-rows').insertAdjacentHTML('beforeend', listRow(''));
    } else if (e.target.closest('[data-add-stat]')) {
      e.target.closest('[data-stats]').querySelector('.stat-rows').insertAdjacentHTML('beforeend', statRow());
    } else if (e.target.closest('[data-del-row]')) {
      e.target.closest('.list-row-edit, .stat-row-edit').remove();
    }
  });
  $('[data-x="cancel"]', m.el).addEventListener('click', () => m.close());
  const submit = async () => {
    const data = {};
    for (const f of fields) {
      if (f.type === 'html') continue;
      if (f.type === 'list') {
        data[f.name] = $$(`[data-list="${f.name}"] .list-row-edit`, form)
          .map((r) => ({ id: Number(r.dataset.id) || 0, label: $('input', r).value.trim() }))
          .filter((o) => o.label);
        continue;
      }
      if (f.type === 'stats') {
        data[f.name] = $$(`[data-stats="${f.name}"] .stat-row-edit`, form)
          .map((r) => {
            const label = $('[data-k=label]', r).value.trim();
            const raw = $('[data-k=value]', r).value.trim();
            return { label, value: raw !== '' && !isNaN(Number(raw)) ? Number(raw) : raw };
          })
          .filter((s) => s.label);
        continue;
      }
      const input = form.elements[f.name];
      if (!input) continue;
      if (f.type === 'checkbox') data[f.name] = input.checked;
      else if (f.type === 'file') {
        if (input.files && input.files[0]) data[f.name] = input.files[0];
      } else if (f.type === 'datetime') data[f.name] = fromLocalInput(input.value);
      else data[f.name] = input.value.trim();
      if (f.required && (data[f.name] === '' || data[f.name] === undefined)) {
        toast(`${f.label} is required.`, 'warn');
        input.focus();
        return;
      }
    }
    const btn = $('[data-x="save"]', m.el);
    setBusy(btn, true);
    try {
      await onSubmit(data, m);
      m.close();
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  };
  $('[data-x="save"]', m.el).addEventListener('click', submit);
  form.addEventListener('submit', (e) => { e.preventDefault(); submit(); });
  return m;
}

/** Sends data either as JSON or multipart (when it contains a File). */
export function send(route, data) {
  const hasFile = Object.values(data).some((v) => v instanceof File);
  return hasFile ? api.upload(route, data) : api.post(route, data);
}

/** Simple page header with optional action buttons (HTML). */
export function pageHead(iconName, title, sub, actions = '') {
  return `<div class="page-head"><div><h2>${icon(iconName)} ${esc(title)}</h2>${sub ? `<p class="muted">${sub}</p>` : ''}</div>${actions ? `<div class="row gap wrap">${actions}</div>` : ''}</div>`;
}

export function pager(container, data, onPage) {
  container.innerHTML = data.total > 0
    ? `<div class="pager"><span class="muted small">${data.total} total · page ${data.page}</span>
        <div class="row gap"><button class="btn btn-ghost btn-sm" data-pg="prev" ${data.page <= 1 ? 'disabled' : ''}>${icon('chevronLeft')} Prev</button>
        <button class="btn btn-ghost btn-sm" data-pg="next" ${data.has_more ? '' : 'disabled'}>Next ${icon('chevronRight')}</button></div></div>`
    : '';
  container.onclick = (e) => {
    const b = e.target.closest('[data-pg]');
    if (b && !b.disabled) onPage(b.dataset.pg === 'next' ? data.page + 1 : data.page - 1);
  };
}

/** Asks for ban length + reason. Resolves to {days, reason} or null. */
export function banDialog(name, { title = 'Block account', allowNone = false } = {}) {
  return new Promise((resolve) => {
    let answered = false;
    const m = openModal({
      title,
      size: 'sm',
      body: `
        <p>Block <strong>${esc(name)}</strong> from using the app. They will see the reason and the date the block ends.</p>
        <div class="field"><span>Block for</span>
          <div class="chip-row wrap ban-days">
            ${allowNone ? '<button type="button" class="chip active" data-days="-1">Don\'t block</button>' : ''}
            ${[1, 3, 7, 14, 30].map((d) => `<button type="button" class="chip ${!allowNone && d === 7 ? 'active' : ''}" data-days="${d}">${d} day${d > 1 ? 's' : ''}</button>`).join('')}
            <button type="button" class="chip" data-days="0">Permanent</button>
          </div>
          <label class="field"><span>Or number of days</span><input class="input" type="number" min="1" max="3650" name="days" value="${allowNone ? '' : 7}"></label>
        </div>
        <label class="field"><span>Reason (shown to the player)</span><input class="input" name="reason" maxlength="255" value="Breaking the community rules"></label>`,
      footer: `<button class="btn btn-ghost" data-x="cancel">Cancel</button><button class="btn btn-danger" data-x="ok">${icon('ban')} Confirm</button>`,
      onClose: () => { if (!answered) resolve(null); },
    });
    let days = allowNone ? -1 : 7;
    m.el.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-days]');
      if (chip) {
        days = Number(chip.dataset.days);
        $$('[data-days]', m.el).forEach((c) => c.classList.toggle('active', c === chip));
        $('[name=days]', m.el).value = days > 0 ? days : '';
      }
    });
    $('[name=days]', m.el).addEventListener('input', (e) => {
      const n = Number(e.target.value);
      if (n > 0) {
        days = n;
        $$('[data-days]', m.el).forEach((c) => c.classList.remove('active'));
      }
    });
    $('[data-x="cancel"]', m.el).addEventListener('click', () => m.close());
    $('[data-x="ok"]', m.el).addEventListener('click', () => {
      answered = true;
      resolve({ days, reason: $('[name=reason]', m.el).value.trim() || 'Breaking the community rules' });
      m.close();
    });
  });
}

/* ------------------------------------------------------------- login */
function renderLogin(message) {
  stopRouter();
  root.innerHTML = `
    <div class="admin-login">
      <form class="auth-card admin-login-card" novalidate>
        <div class="brand big"><img src="../assets/img/logo.svg" alt="" class="brand-logo"><span class="brand-text"><strong>G VI</strong><small>ADMIN PANEL</small></span></div>
        <h2>Admin login</h2>
        <div class="auth-alert error" ${message ? '' : 'hidden'}>${icon('alert')}<span>${esc(message || '')}</span></div>
        <label class="field"><span>Username</span><input class="input" name="username" autocomplete="username" required></label>
        <label class="field"><span>Password</span><input class="input" name="password" type="password" autocomplete="current-password" required></label>
        <button class="btn btn-primary btn-block btn-lg" type="submit">${icon('lock')} Log in</button>
        <a class="back-link center-link" href="../">${icon('chevronLeft')} Back to the app</a>
      </form>
    </div>`;
  const form = $('form', root);
  form.username.focus();
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('button[type=submit]', form);
    setBusy(btn, true, 'Checking…');
    try {
      const data = await api.post('admin/login', { username: form.username.value.trim(), password: form.password.value });
      start(data);
    } catch (err) {
      const box = $('.auth-alert', form);
      box.hidden = false;
      $('span', box).textContent = err.message;
      setBusy(btn, false);
    }
  });
}

/* ------------------------------------------------------------- shell */
function start(data) {
  api.csrf = data.csrf;
  admin.me = data.admin;
  admin.config = data.config;
  admin.pending = data.pending || admin.pending;
  const sections = [...new Set(NAV.map((n) => n[3]))];
  root.innerHTML = '';
  root.appendChild(el(`
    <div class="shell admin-shell">
      <aside class="sidebar">
        <a class="brand" href="#/dashboard"><img src="../assets/img/logo.svg" alt="" class="brand-logo"><span class="brand-text"><strong>G VI</strong><small>ADMIN</small></span></a>
        <nav class="side-nav">
          ${sections.map((s) => `<div class="nav-section"><div class="nav-heading">${s}</div>${NAV.filter((n) => n[3] === s).map(([k, ic, l, , pend]) => `<a class="nav-link" href="#/${k}" data-key="${k}">${icon(ic)}<span class="nav-label">${l}</span>${pend ? `<span class="nav-badge" data-pending="${pend}" hidden></span>` : ''}</a>`).join('')}</div>`).join('')}
        </nav>
        <div class="sidebar-foot"><a class="btn btn-ghost btn-block" href="../" target="_blank">${icon('external')} Open the app</a></div>
      </aside>
      <div class="main-col">
        <header class="topbar">
          <button class="icon-btn admin-menu-btn" aria-label="Menu">${icon('menu')}</button>
          <h1 class="page-title"></h1>
          <div class="top-actions" style="margin-left:auto">
            <span class="admin-chip">${icon('shield')} ${esc(admin.me.username)}</span>
            <button class="btn btn-ghost btn-sm" data-act="logout">${icon('logout')} Log out</button>
          </div>
        </header>
        <main class="outlet" id="outlet"></main>
      </div>
    </div>`));
  setPending();
  const shell = $('.admin-shell', root);
  $('[data-act="logout"]', root).addEventListener('click', async () => {
    try { await api.post('admin/logout'); } catch (e) { /* ignore */ }
    renderLogin('You have been logged out.');
  });
  $('.admin-menu-btn', root).addEventListener('click', () => shell.classList.toggle('nav-open'));
  shell.addEventListener('click', (e) => { if (e.target === shell) shell.classList.remove('nav-open'); });
  $('.side-nav', root).addEventListener('click', (e) => { if (e.target.closest('a')) shell.classList.remove('nav-open'); });
  if (!location.hash || location.hash === '#/' ) history.replaceState(null, '', '#/dashboard');
  startRouter($('#outlet', root), (route) => {
    closeAllModals();
    $$('.nav-link', root).forEach((a) => a.classList.toggle('active', a.dataset.key === route.meta.key));
    $('.page-title', root).textContent = route.meta.title;
    document.title = `${route.meta.title} · G VI Admin`;
  });
}

api.onAuthError = (err) => {
  if (admin.me && err.code === 'admin_unauthenticated') {
    admin.me = null;
    renderLogin('Your admin session has ended. Please log in again.');
  }
};

(async function boot() {
  try {
    const data = await api.get('admin/me');
    if (data.admin) start(data);
    else renderLogin();
  } catch (err) {
    if (err.code === 'not_installed') {
      root.innerHTML = `<div class="admin-login"><div class="auth-card"><h2>Not installed</h2><p>Open <a href="../install.php">install.php</a> first.</p></div></div>`;
    } else {
      renderLogin(err.message);
    }
  }
})();

export { api, toast, toastError };
