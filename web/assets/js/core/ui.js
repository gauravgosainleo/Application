// Shared UI helpers: escaping, formatting, avatars, modals, toasts ...
import { icon } from './icons.js';

export const ui = { assetBase: '' };

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

export function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export function el(html) {
  const t = document.createElement('template');
  t.innerHTML = html.trim();
  return t.content.firstElementChild;
}

/** Resolves an uploaded/relative path for <img src>. External links are returned as-is. */
export function asset(path) {
  if (!path) return '';
  if (/^(https?:|data:|blob:)/i.test(path)) return path;
  return ui.assetBase + path;
}

export function debounce(fn, ms = 300) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
}

/* ------------------------------------------------------------ time */

export function timeAgo(iso) {
  if (!iso) return '';
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 0) return timeUntil(iso);
  if (diff < 45) return 'just now';
  if (diff < 3600) return `${Math.round(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.round(diff / 3600)}h ago`;
  if (diff < 86400 * 7) return `${Math.round(diff / 86400)}d ago`;
  return fmtDate(iso);
}

export function timeUntil(iso) {
  const diff = (new Date(iso).getTime() - Date.now()) / 1000;
  if (diff <= 0) return 'now';
  if (diff < 3600) return `in ${Math.max(1, Math.round(diff / 60))}m`;
  if (diff < 86400) return `in ${Math.round(diff / 3600)}h`;
  return `in ${Math.round(diff / 86400)}d`;
}

export function fmtDate(iso, opts = { day: 'numeric', month: 'short', year: 'numeric' }) {
  if (!iso) return '';
  const d = new Date(iso.length === 10 ? iso + 'T00:00:00' : iso);
  return isNaN(d) ? '' : d.toLocaleDateString(undefined, opts);
}

export function fmtDateTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return isNaN(d) ? '' : d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

export function fmtTime(iso) {
  const d = new Date(iso);
  return isNaN(d) ? '' : d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

export function fmtNumber(n) {
  if (n === null || n === undefined) return '';
  if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
  if (n >= 1e4) return (n / 1e3).toFixed(n >= 1e5 ? 0 : 1).replace(/\.0$/, '') + 'K';
  return Number(n).toLocaleString();
}

export function fmtMoney(n) {
  if (n === null || n === undefined) return '—';
  return '$' + Number(n).toLocaleString('en-US');
}

/* ------------------------------------------------------------ text */

/** Escapes text, turns links into anchors and blank lines into paragraphs. */
export function richText(text) {
  if (!text) return '';
  const linkify = (s) =>
    esc(s).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, (url) => `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`);
  return String(text)
    .split(/\n{2,}/)
    .map((p) => `<p>${linkify(p).replace(/\n/g, '<br>')}</p>`)
    .join('');
}

export function initials(name) {
  return String(name || '?')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0])
    .join('')
    .toUpperCase();
}

/* ---------------------------------------------------------- avatars */

const AVATAR_GRADS = [
  ['#ff3d8b', '#ff8a3d'], ['#8b5cf6', '#3b82f6'], ['#19d3c5', '#3b82f6'], ['#f59e0b', '#ef4444'],
  ['#22c55e', '#14b8a6'], ['#ec4899', '#8b5cf6'], ['#06b6d4', '#8b5cf6'], ['#f97316', '#ec4899'],
];

export function avatar(user, size = 'md', opts = {}) {
  const u = user || {};
  const showPresence = opts.presence !== false && u.presence && u.presence !== 'invisible';
  const frame = u.frame ? ` frame-${esc(u.frame)}` : '';
  const g = AVATAR_GRADS[(Number(u.id) || 0) % AVATAR_GRADS.length];
  const inner = u.avatar
    ? `<img src="${esc(asset(u.avatar))}" alt="" loading="lazy">`
    : `<span class="avatar-initials" style="background:linear-gradient(135deg,${g[0]},${g[1]})">${esc(initials(u.name))}</span>`;
  const dot = showPresence ? `<span class="presence-dot ${esc(u.presence)}" title="${esc(presenceLabel(u))}"></span>` : '';
  return `<span class="avatar avatar-${size}${frame}">${inner}${dot}</span>`;
}

export function presenceLabel(u) {
  if (!u) return '';
  switch (u.presence) {
    case 'online': return 'Online';
    case 'away': return 'Away';
    case 'busy': return 'Busy';
    case 'invisible': return 'Invisible';
    default: return u.last_seen ? `Last seen ${timeAgo(u.last_seen)}` : 'Offline';
  }
}

export function levelPill(level) {
  return `<span class="level-pill" title="Level ${esc(level)}">LV ${esc(level)}</span>`;
}

export function userName(u, withLink = true) {
  if (!u || !u.id) return `<span class="muted">${esc(u?.name || 'Deleted user')}</span>`;
  const name = `${esc(u.name)}${u.title ? ` <span class="user-title">${esc(u.title)}</span>` : ''}`;
  return withLink ? `<a class="user-link" href="#/profile/${u.id}">${name}</a>` : name;
}

/* ---------------------------------------------------------- toasts */

let toastRoot;
export function toast(message, type = 'info', timeout = 3800) {
  if (!toastRoot) {
    toastRoot = el('<div class="toasts" role="status" aria-live="polite"></div>');
    document.body.appendChild(toastRoot);
  }
  const ic = { success: 'check', error: 'alert', info: 'info', warn: 'alert' }[type] || 'info';
  const t = el(`<div class="toast toast-${type}">${icon(ic)}<span>${esc(message)}</span></div>`);
  toastRoot.appendChild(t);
  requestAnimationFrame(() => t.classList.add('show'));
  setTimeout(() => {
    t.classList.remove('show');
    setTimeout(() => t.remove(), 300);
  }, timeout);
}

export function toastError(err) {
  toast(err && err.message ? err.message : 'Something went wrong.', 'error', 5000);
}

/* ---------------------------------------------------------- modals */

const openModals = [];

export function openModal({ title = '', body = '', size = 'md', className = '', onClose = null, footer = '' } = {}) {
  const root = el(`
    <div class="modal-backdrop ${className}">
      <div class="modal modal-${size}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
        ${title ? `<div class="modal-head"><h3>${esc(title)}</h3><button class="icon-btn modal-close" aria-label="Close">${icon('x')}</button></div>` : `<button class="icon-btn modal-close floating" aria-label="Close">${icon('x')}</button>`}
        <div class="modal-body"></div>
        ${footer ? `<div class="modal-foot">${footer}</div>` : ''}
      </div>
    </div>`);
  const bodyEl = root.querySelector('.modal-body');
  if (typeof body === 'string') bodyEl.innerHTML = body;
  else if (body) bodyEl.appendChild(body);
  let closed = false;
  const close = () => {
    if (closed) return;
    closed = true;
    root.classList.remove('show');
    const i = openModals.indexOf(api);
    if (i >= 0) openModals.splice(i, 1);
    setTimeout(() => root.remove(), 200);
    if (!openModals.length) document.body.classList.remove('modal-open');
    if (onClose) onClose();
  };
  const api = { el: root, body: bodyEl, close };
  root.addEventListener('mousedown', (e) => {
    if (e.target === root) close();
  });
  root.querySelectorAll('.modal-close').forEach((b) => b.addEventListener('click', close));
  document.body.appendChild(root);
  document.body.classList.add('modal-open');
  openModals.push(api);
  requestAnimationFrame(() => root.classList.add('show'));
  const focusable = root.querySelector('input:not([type=hidden]):not([type=file]), textarea, select');
  if (focusable && window.matchMedia('(pointer:fine)').matches) setTimeout(() => focusable.focus(), 60);
  return api;
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && openModals.length) openModals[openModals.length - 1].close();
});

export function closeAllModals() {
  [...openModals].forEach((m) => m.close());
}

export function confirmBox(message, { title = 'Are you sure?', confirmText = 'Confirm', danger = false, cancelText = 'Cancel' } = {}) {
  return new Promise((resolve) => {
    let answered = false;
    const m = openModal({
      title,
      size: 'sm',
      body: `<p class="confirm-text">${esc(message)}</p>`,
      footer: `<button class="btn btn-ghost" data-act="no">${esc(cancelText)}</button><button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-act="yes">${esc(confirmText)}</button>`,
      onClose: () => { if (!answered) resolve(false); },
    });
    m.el.querySelector('[data-act=yes]').addEventListener('click', () => { answered = true; resolve(true); m.close(); });
    m.el.querySelector('[data-act=no]').addEventListener('click', () => { answered = true; resolve(false); m.close(); });
  });
}

export function promptBox({ title, label, placeholder = '', value = '', confirmText = 'Save', multiline = false, maxLength = 255, extraHTML = '' }) {
  return new Promise((resolve) => {
    let answered = false;
    const field = multiline
      ? `<textarea class="input" rows="4" maxlength="${maxLength}" placeholder="${esc(placeholder)}">${esc(value)}</textarea>`
      : `<input class="input" maxlength="${maxLength}" placeholder="${esc(placeholder)}" value="${esc(value)}">`;
    const m = openModal({
      title,
      size: 'sm',
      body: `<label class="field"><span>${esc(label)}</span>${field}</label>${extraHTML}`,
      footer: `<button class="btn btn-ghost" data-act="no">Cancel</button><button class="btn btn-primary" data-act="yes">${esc(confirmText)}</button>`,
      onClose: () => { if (!answered) resolve(null); },
    });
    const input = m.el.querySelector('.input');
    const ok = () => { answered = true; resolve({ value: input.value.trim(), modal: m }); m.close(); };
    m.el.querySelector('[data-act=yes]').addEventListener('click', ok);
    m.el.querySelector('[data-act=no]').addEventListener('click', () => { answered = true; resolve(null); m.close(); });
    if (!multiline) input.addEventListener('keydown', (e) => { if (e.key === 'Enter') ok(); });
  });
}

/** Report dialog used by discussions, comments, profiles, statuses, gallery and chat. */
export async function reportDialog(apiClient, targetType, targetId) {
  const reasons = ['Spam or advertising', 'Harassment or hate', 'Spoilers without warning', 'Inappropriate content', 'Cheating / scams'];
  const res = await promptBox({
    title: 'Report',
    label: 'What is wrong?',
    placeholder: 'Describe the problem…',
    confirmText: 'Send report',
    extraHTML: `<div class="chip-row report-reasons">${reasons.map((r) => `<button type="button" class="chip">${esc(r)}</button>`).join('')}</div>`,
  });
  if (!res || !res.value) return;
  try {
    await apiClient.post('reports/create', { target_type: targetType, target_id: targetId, reason: res.value });
    toast('Thanks — the moderators will review it.', 'success');
  } catch (e) {
    toastError(e);
  }
}
document.addEventListener('click', (e) => {
  const chip = e.target.closest('.report-reasons .chip');
  if (chip) {
    const input = chip.closest('.modal').querySelector('.input');
    input.value = chip.textContent;
    input.focus();
  }
});

/* ------------------------------------------------------ small parts */

export function setBusy(button, busy, label) {
  if (!button) return;
  if (busy) {
    button.dataset.label = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `<span class="spinner"></span>${label ? `<span>${esc(label)}</span>` : ''}`;
  } else {
    button.disabled = false;
    if (button.dataset.label) button.innerHTML = button.dataset.label;
  }
}

export function emptyState(ic, title, text = '', action = '') {
  return `<div class="empty">${icon(ic, 'empty-icon')}<h3>${esc(title)}</h3>${text ? `<p>${esc(text)}</p>` : ''}${action}</div>`;
}

export function loader(text = '') {
  return `<div class="loader"><span class="spinner lg"></span>${text ? `<span>${esc(text)}</span>` : ''}</div>`;
}

export function skeletonCards(n = 6, cls = '') {
  return `<div class="grid-cards ${cls}">${Array.from({ length: n }, () => '<div class="card skeleton-card"><div class="sk sk-img"></div><div class="sk sk-line"></div><div class="sk sk-line short"></div></div>').join('')}</div>`;
}

export function errorState(err, retry) {
  const box = el(`<div class="empty error">${icon('alert', 'empty-icon')}<h3>Could not load this</h3><p>${esc(err?.message || 'Unknown error')}</p>${retry ? '<button class="btn btn-ghost">Try again</button>' : ''}</div>`);
  if (retry) box.querySelector('button').addEventListener('click', retry);
  return box;
}

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast('Link copied to clipboard', 'success');
  } catch (e) {
    prompt('Copy this link:', text);
  }
}

export async function shareLink(title, url) {
  if (window.GVIAndroid && typeof window.GVIAndroid.share === 'function') {
    window.GVIAndroid.share(title || '', url);
    return;
  }
  if (navigator.share) {
    try {
      await navigator.share({ title, url });
      return;
    } catch (e) {
      if (e && e.name === 'AbortError') return;
    }
  }
  copyText(url);
}

export function appLink(hash) {
  return location.href.split('#')[0] + hash;
}

/** Wires a file input to an <img> preview. Returns a getter for the chosen file. */
export function filePreview(input, img, onChange) {
  let file = null;
  input.addEventListener('change', () => {
    file = input.files && input.files[0] ? input.files[0] : null;
    if (file && img && file.type.startsWith('image/')) {
      img.src = URL.createObjectURL(file);
      img.hidden = false;
    }
    if (onChange) onChange(file);
  });
  return () => file;
}

/** 6-box OTP input. Returns { el, value(), clear(), focus() }. */
export function otpInput(onComplete) {
  const wrap = el(`<div class="otp">${Array.from({ length: 6 }, (_, i) => `<input inputmode="numeric" autocomplete="${i === 0 ? 'one-time-code' : 'off'}" maxlength="6" aria-label="Digit ${i + 1}">`).join('')}</div>`);
  const boxes = $$('input', wrap);
  const value = () => boxes.map((b) => b.value).join('');
  boxes.forEach((box, i) => {
    box.addEventListener('input', () => {
      const digits = box.value.replace(/\D/g, '');
      if (digits.length > 1) {
        digits.split('').slice(0, 6 - i).forEach((d, j) => { boxes[i + j].value = d; });
        boxes[Math.min(5, i + digits.length - 1)].focus();
      } else {
        box.value = digits;
        if (digits && i < 5) boxes[i + 1].focus();
      }
      if (value().length === 6 && onComplete) onComplete(value());
    });
    box.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus();
      if (e.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
      if (e.key === 'ArrowRight' && i < 5) boxes[i + 1].focus();
    });
    box.addEventListener('focus', () => box.select());
  });
  return {
    el: wrap,
    value,
    clear: () => { boxes.forEach((b) => { b.value = ''; }); },
    focus: () => boxes[0].focus(),
    setDisabled: (d) => boxes.forEach((b) => { b.disabled = d; }),
  };
}

/** Countdown helper: returns {days, hours, minutes, seconds, done}. */
export function countdownParts(target) {
  const diff = Math.max(0, target.getTime() - Date.now());
  const s = Math.floor(diff / 1000);
  return {
    days: Math.floor(s / 86400),
    hours: Math.floor((s % 86400) / 3600),
    minutes: Math.floor((s % 3600) / 60),
    seconds: s % 60,
    done: diff <= 0,
  };
}

export function pad(n) {
  return String(n).padStart(2, '0');
}

export function youtubeEmbed(id, autoplay = true) {
  return `<div class="video-frame"><iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?rel=0&modestbranding=1${autoplay ? '&autoplay=1' : ''}" title="YouTube video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>`;
}

export function videoPlayer(src) {
  return `<div class="video-frame"><video src="${esc(asset(src))}" controls autoplay playsinline></video></div>`;
}

export { icon };
