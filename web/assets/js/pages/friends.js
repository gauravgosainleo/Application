// Friends & Chat: conversations (direct + groups), friends list, requests and player search.
import { api } from '../core/api.js';
import { state, setState } from '../core/state.js';
import { esc, $, $$, icon, avatar, levelPill, timeAgo, fmtTime, fmtDate, presenceLabel, openModal, confirmBox, toast, toastError, setBusy, debounce, emptyState, asset, reportDialog, loader, errorState } from '../core/ui.js';
import { setQuery, navigate } from '../core/router.js';

const TABS = [['chats', 'chat', 'Chats'], ['friends', 'users', 'Friends'], ['requests', 'userPlus', 'Requests'], ['find', 'search', 'Find players']];

export async function render({ el, query }) {
  let tab = TABS.some((t) => t[0] === query.tab) ? query.tab : 'chats';
  let cleanupTab = null;
  el.innerHTML = `
    <div class="page-head compact">
      <div><h2>${icon('chat')} Friends & Chat</h2></div>
      <div class="tabs" role="tablist">
        ${TABS.map(([k, ic, l]) => `<button class="tab" data-tab="${k}" role="tab">${icon(ic)}<span>${l}</span><span class="tab-badge" data-tb="${k}" hidden></span></button>`).join('')}
      </div>
    </div>
    <div class="tab-body"></div>`;
  const body = $('.tab-body', el);

  function badges() {
    const c = state.counts || {};
    const set = (k, n) => { const b = $(`[data-tb="${k}"]`, el); if (b) { b.hidden = !n; b.textContent = n; } };
    set('chats', c.messages || 0);
    set('requests', c.friend_requests || 0);
  }
  badges();

  function switchTab(next, extra = {}) {
    tab = next;
    if (cleanupTab) cleanupTab();
    cleanupTab = null;
    $$('.tab', el).forEach((t) => t.classList.toggle('active', t.dataset.tab === tab));
    setQuery({ tab, chat: extra.chat || null, user: null });
    if (tab === 'chats') cleanupTab = renderChats(body, extra.chat || query.chat, query.user, badges);
    else if (tab === 'friends') renderFriends(body, switchTab);
    else if (tab === 'requests') renderRequests(body);
    else renderFind(body);
  }
  $$('.tab', el).forEach((t) => t.addEventListener('click', () => switchTab(t.dataset.tab)));
  switchTab(tab, { chat: query.chat });
  return () => { if (cleanupTab) cleanupTab(); };
}

/* =================================================================== CHATS */

function renderChats(body, openId, openUser, refreshBadges) {
  const meId = state.me.id;
  let conversations = [];
  let active = null;
  let lastId = 0;
  let firstId = 0;
  let rendered = new Set();
  let othersRead = 0;
  let pollTimer = null;
  let listTimer = null;
  let destroyed = false;
  let loadingOlder = false;
  let hasMore = false;

  body.innerHTML = `
    <div class="chat-layout">
      <section class="conv-pane card">
        <div class="conv-tools">
          <div class="search-input small">${icon('search')}<input type="search" placeholder="Search chats…"></div>
          <button class="btn btn-primary btn-sm new-group" title="New group">${icon('users')} <span>New group</span></button>
        </div>
        <div class="conv-list">${loader()}</div>
      </section>
      <section class="thread-pane card">
        <div class="thread-empty">${icon('chat', 'empty-icon')}<h3>Your crew chats</h3><p class="muted">Pick a conversation, or open a friend's profile to start one.</p></div>
      </section>
    </div>`;
  const layout = $('.chat-layout', body);
  const listEl = $('.conv-list', body);
  const threadPane = $('.thread-pane', body);
  const searchInput = $('.conv-tools input', body);

  function convRow(c) {
    const last = c.last_message;
    let preview = 'No messages yet';
    if (last) {
      const who = last.user_id === meId ? 'You: ' : (c.type === 'group' && last.user_id ? ((c.members.find((m) => m.id === last.user_id) || {}).name || '').split(' ')[0] + ': ' : '');
      preview = last.deleted ? 'Message deleted' : last.kind === 'image' ? `${who}📷 Photo` : last.kind === 'system' ? last.body : who + (last.body || '');
    }
    const av = c.type === 'direct' ? avatar(c.other, 'md') : groupAvatar(c);
    return `<button class="conv-row ${active && active.id === c.id ? 'active' : ''} ${c.unread ? 'unread' : ''}" data-id="${c.id}">
      ${av}
      <span class="cr-main"><span class="cr-top"><strong>${esc(c.name || 'Group')}</strong><small>${timeAgo(c.last_message_at)}</small></span>
      <span class="cr-bottom"><span class="cr-preview">${esc(preview)}</span>${c.unread ? `<span class="count-badge">${c.unread}</span>` : ''}</span></span>
    </button>`;
  }

  function renderList() {
    const q = searchInput.value.trim().toLowerCase();
    const shown = conversations.filter((c) => !q || (c.name || '').toLowerCase().includes(q));
    listEl.innerHTML = shown.length ? shown.map(convRow).join('') : `<div class="empty small">${icon('chat', 'empty-icon')}<p>${q ? 'No chats found.' : 'No chats yet. Message a friend or create a group!'}</p><a class="btn btn-ghost btn-sm" href="#/friends?tab=friends">See friends</a></div>`;
  }

  async function loadList() {
    try {
      conversations = await api.get('chat/conversations');
      if (destroyed) return;
      renderList();
      const unread = conversations.reduce((s, c) => s + (active && c.id === active.id ? 0 : c.unread), 0);
      setState({ counts: { ...state.counts, messages: unread } });
      refreshBadges();
    } catch (err) {
      if (!conversations.length) listEl.innerHTML = `<p class="muted pad">${esc(err.message)}</p>`;
    }
  }

  function dayLabel(iso) {
    const d = new Date(iso);
    const today = new Date();
    const y = new Date(Date.now() - 86400000);
    if (d.toDateString() === today.toDateString()) return 'Today';
    if (d.toDateString() === y.toDateString()) return 'Yesterday';
    return fmtDate(iso, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
  }

  function msgText(text) {
    return esc(text).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, (u) => `<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`).replace(/\n/g, '<br>');
  }

  function msgHTML(m, prev) {
    let sep = '';
    if (!prev || new Date(prev.created_at).toDateString() !== new Date(m.created_at).toDateString()) {
      sep = `<div class="day-sep"><span>${dayLabel(m.created_at)}</span></div>`;
    }
    if (m.kind === 'system') return `${sep}<div class="msg-system" data-mid="${m.id}">${esc(m.body)}</div>`;
    const mine = m.user_id === meId;
    const author = active.members.find((u) => u.id === m.user_id) || { name: 'Former member', id: 0 };
    const grouped = prev && !sep && prev.user_id === m.user_id && prev.kind !== 'system' && (new Date(m.created_at) - new Date(prev.created_at)) < 300000;
    const showName = active.type === 'group' && !mine && !grouped;
    let content;
    if (m.deleted) content = `<em class="muted">Message deleted</em>`;
    else {
      content = '';
      if (m.attachment) content += `<a href="${esc(asset(m.attachment))}" target="_blank" class="msg-img-link"><img class="msg-img" src="${esc(asset(m.attachment))}" alt="Photo" loading="lazy"></a>`;
      if (m.body) content += `<div class="msg-text">${msgText(m.body)}</div>`;
    }
    return `${sep}<div class="msg ${mine ? 'mine' : 'theirs'} ${grouped ? 'grouped' : ''}" data-mid="${m.id}">
      ${!mine && active.type === 'group' ? (grouped ? '<span class="msg-av-space"></span>' : `<a href="#/profile/${author.id}">${avatar(author, 'xs', { presence: false })}</a>`) : ''}
      <div class="msg-bubble">
        ${showName ? `<span class="msg-author">${esc(author.name)}</span>` : ''}
        ${content}
        <span class="msg-time">${fmtTime(m.created_at)}</span>
      </div>
      ${!m.deleted ? `<button class="msg-action" data-act="${mine ? 'delete' : 'report'}" title="${mine ? 'Delete' : 'Report'}">${icon(mine ? 'trash' : 'flag')}</button>` : ''}
    </div>`;
  }

  let messages = [];
  function appendMessages(list, { prepend = false } = {}) {
    const box = $('.thread-msgs', threadPane);
    if (!box) return;
    const fresh = list.filter((m) => !rendered.has(m.id));
    if (!fresh.length) return;
    fresh.forEach((m) => rendered.add(m.id));
    const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;
    if (prepend) {
      messages = [...fresh, ...messages];
      const oldHeight = box.scrollHeight;
      renderAllMessages();
      box.scrollTop = box.scrollHeight - oldHeight;
    } else {
      const startPrev = messages[messages.length - 1];
      messages = [...messages, ...fresh];
      let html = '';
      fresh.forEach((m, i) => { html += msgHTML(m, i === 0 ? startPrev : fresh[i - 1]); });
      $('.msgs-end', box).insertAdjacentHTML('beforebegin', html);
      if (nearBottom || fresh.some((m) => m.user_id === meId)) box.scrollTop = box.scrollHeight;
    }
    lastId = Math.max(lastId, ...fresh.map((m) => m.id));
    firstId = Math.min(...messages.map((m) => m.id));
    renderSeen();
  }

  function renderAllMessages() {
    const box = $('.thread-msgs', threadPane);
    box.innerHTML = (hasMore ? '<div class="load-older"><button class="btn btn-ghost btn-sm">Load earlier messages</button></div>' : '') +
      messages.map((m, i) => msgHTML(m, messages[i - 1])).join('') + '<div class="seen-mark"></div><div class="msgs-end"></div>';
    const older = $('.load-older button', box);
    if (older) older.addEventListener('click', loadOlder);
    renderSeen();
  }

  function renderSeen() {
    const mark = $('.seen-mark', threadPane);
    if (!mark) return;
    const myLast = [...messages].reverse().find((m) => m.user_id === meId && m.kind !== 'system');
    const seen = myLast && othersRead >= myLast.id && messages[messages.length - 1] && messages[messages.length - 1].user_id === meId;
    mark.innerHTML = seen ? `<span>${icon('check')} Seen${active.type === 'group' ? ' by everyone' : ''}</span>` : '';
    const box = $('.thread-msgs', threadPane);
    box.appendChild(mark);
    box.appendChild($('.msgs-end', box));
  }

  async function loadOlder() {
    if (loadingOlder || !active) return;
    loadingOlder = true;
    try {
      const data = await api.get('chat/messages', { conversation_id: active.id, before_id: firstId });
      hasMore = data.has_more;
      appendMessages(data.messages, { prepend: true });
      if (!hasMore) { const lo = $('.load-older', threadPane); if (lo) lo.remove(); }
    } catch (err) {
      toastError(err);
    }
    loadingOlder = false;
  }

  function threadHeader(c) {
    const sub = c.type === 'direct'
      ? `<span class="presence-text"><span class="presence-dot inline ${esc(c.other.presence)}"></span>${esc(presenceLabel(c.other))}${c.other.presence_text ? ' · ' + esc(c.other.presence_text) : ''}</span>`
      : `<span>${c.members.length} members · ${esc(c.members.slice(0, 4).map((m) => m.name.split(' ')[0]).join(', '))}${c.members.length > 4 ? '…' : ''}</span>`;
    return `<button class="icon-btn back-btn" data-act="back" aria-label="Back">${icon('chevronLeft')}</button>
      <button class="th-id" data-act="info">${c.type === 'direct' ? avatar(c.other, 'md') : groupAvatar(c)}<span><strong>${esc(c.name || 'Group')}</strong><small class="muted">${sub}</small></span></button>
      <button class="icon-btn" data-act="info" aria-label="Details">${icon(c.type === 'direct' ? 'user' : 'settings')}</button>`;
  }

  async function openConversation(id) {
    clearInterval(pollTimer);
    rendered = new Set();
    messages = [];
    lastId = 0;
    othersRead = 0;
    layout.classList.add('thread-open');
    threadPane.innerHTML = loader();
    let data;
    try {
      data = await api.get('chat/messages', { conversation_id: id });
    } catch (err) {
      threadPane.innerHTML = '';
      threadPane.appendChild(errorState(err));
      return;
    }
    if (destroyed) return;
    active = data.conversation;
    hasMore = data.has_more;
    othersRead = data.others_read;
    setQuery({ tab: 'chats', chat: active.id });
    threadPane.innerHTML = `
      <div class="thread">
        <div class="thread-head">${threadHeader(active)}</div>
        <div class="thread-msgs"><div class="msgs-end"></div></div>
        <form class="composer">
          <label class="icon-btn attach" title="Send a photo">${icon('image')}<input type="file" accept="image/*" hidden></label>
          <div class="composer-input"><div class="attach-preview" hidden></div><textarea rows="1" maxlength="2000" placeholder="Message ${esc(active.name || '')}…"></textarea></div>
          <button class="btn btn-primary send-btn" type="submit" aria-label="Send">${icon('send')}</button>
        </form>
      </div>`;
    messages = [];
    renderAllMessages();
    appendMessages(data.messages);
    const box = $('.thread-msgs', threadPane);
    box.scrollTop = box.scrollHeight;
    const conv = conversations.find((c) => c.id === active.id);
    if (conv) conv.unread = 0;
    renderList();
    wireComposer();
    pollTimer = setInterval(poll, 3000);
    loadList();
  }

  async function poll() {
    if (!active || document.visibilityState !== 'visible') return;
    try {
      const data = await api.get('chat/messages', { conversation_id: active.id, after_id: lastId });
      if (destroyed || !active) return;
      othersRead = data.others_read;
      if (data.messages.length) {
        appendMessages(data.messages);
        if (data.messages.some((m) => m.kind === 'system')) refreshActive();
      } else {
        renderSeen();
      }
    } catch (err) {
      if (err.status === 404) {
        toast('You are no longer in this chat.', 'warn');
        closeThread();
        loadList();
      }
    }
  }

  async function refreshActive() {
    const fresh = await api.get('chat/conversations').catch(() => null);
    if (!fresh || !active) return;
    conversations = fresh;
    const c = conversations.find((x) => x.id === active.id);
    if (c) {
      active = { ...active, ...c };
      $('.thread-head', threadPane).innerHTML = threadHeader(active);
    }
    renderList();
  }

  function closeThread() {
    clearInterval(pollTimer);
    active = null;
    layout.classList.remove('thread-open');
    setQuery({ chat: null });
    threadPane.innerHTML = `<div class="thread-empty">${icon('chat', 'empty-icon')}<h3>Your crew chats</h3><p class="muted">Pick a conversation to start chatting.</p></div>`;
    renderList();
  }

  function wireComposer() {
    const form = $('.composer', threadPane);
    const ta = $('textarea', form);
    const fileInput = $('input[type=file]', form);
    const preview = $('.attach-preview', form);
    let file = null;
    const autosize = () => { ta.style.height = 'auto'; ta.style.height = Math.min(140, ta.scrollHeight) + 'px'; };
    ta.addEventListener('input', autosize);
    ta.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey && window.matchMedia('(pointer:fine)').matches) {
        e.preventDefault();
        form.requestSubmit();
      }
    });
    fileInput.addEventListener('change', () => {
      file = fileInput.files[0] || null;
      if (file) {
        preview.hidden = false;
        preview.innerHTML = `<img src="${URL.createObjectURL(file)}" alt=""><button type="button" class="icon-btn" aria-label="Remove">${icon('x')}</button>`;
        $('button', preview).addEventListener('click', () => { file = null; fileInput.value = ''; preview.hidden = true; preview.innerHTML = ''; });
      }
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = ta.value.trim();
      if (!text && !file) return;
      const btn = $('.send-btn', form);
      setBusy(btn, true);
      try {
        const msg = file
          ? await api.upload('chat/send', { conversation_id: active.id, body: text, file })
          : await api.post('chat/send', { conversation_id: active.id, body: text });
        ta.value = '';
        autosize();
        file = null;
        fileInput.value = '';
        preview.hidden = true;
        preview.innerHTML = '';
        appendMessages([msg]);
        const c = conversations.find((x) => x.id === active.id);
        if (c) {
          c.last_message = msg;
          c.last_message_at = msg.created_at;
          conversations = [c, ...conversations.filter((x) => x !== c)];
          renderList();
        }
      } catch (err) {
        toastError(err);
      } finally {
        setBusy(btn, false);
        ta.focus();
      }
    });
    if (window.matchMedia('(pointer:fine)').matches) ta.focus();
  }

  threadPane.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act || !active) return;
    if (act === 'back') closeThread();
    else if (act === 'info') {
      if (active.type === 'direct') navigate('#/profile/' + active.other.id);
      else groupInfo(active);
    } else if (act === 'delete' || act === 'report') {
      const row = e.target.closest('.msg');
      const id = Number(row.dataset.mid);
      if (act === 'report') return reportDialog(api, 'message', id);
      if (!(await confirmBox('Delete this message for everyone?', { confirmText: 'Delete', danger: true }))) return;
      try {
        const updated = await api.post('chat/delete-message', { message_id: id });
        const m = messages.find((x) => x.id === id);
        Object.assign(m, updated);
        renderAllMessages();
      } catch (err) {
        toastError(err);
      }
    }
  });

  listEl.addEventListener('click', (e) => {
    const row = e.target.closest('.conv-row');
    if (row) openConversation(Number(row.dataset.id));
  });
  searchInput.addEventListener('input', debounce(renderList, 150));
  $('.new-group', body).addEventListener('click', () => groupCreate((c) => { conversations.unshift(c); openConversation(c.id); }));

  /* ------------------------------------------------------ group info */
  function groupInfo(c) {
    const isOwner = c.my_role === 'owner';
    const m = openModal({
      title: 'Group info',
      size: 'md',
      body: `
        <div class="group-info">
          <div class="gi-head">
            <label class="gi-avatar" title="Change photo">${groupAvatar(c, 'xl')}<span class="gi-cam">${icon('camera')}</span><input type="file" accept="image/*" hidden></label>
            <div class="gi-name"><input class="input" value="${esc(c.name)}" maxlength="80"><button class="btn btn-ghost btn-sm" data-g="rename">Rename</button></div>
          </div>
          <div class="row-between"><strong>${c.members.length} members</strong><button class="btn btn-ghost btn-sm" data-g="add">${icon('userPlus')} Add friends</button></div>
          <div class="member-list">${c.members.map((u) => `
            <div class="member-row">${avatar(u, 'sm')}<a href="#/profile/${u.id}"><strong>${esc(u.name)}</strong><small class="muted">@${esc(u.username)}</small></a>
            ${c.roles[u.id] === 'owner' ? '<span class="chip">Owner</span>' : ''}
            ${isOwner && u.id !== meId ? `<button class="icon-btn danger" data-g="remove" data-uid="${u.id}" title="Remove">${icon('x')}</button>` : ''}</div>`).join('')}
          </div>
          <button class="btn btn-danger btn-block" data-g="leave">${icon('logout')} Leave group</button>
        </div>`,
    });
    const update = (conv) => {
      active = { ...active, ...conv };
      $('.thread-head', threadPane).innerHTML = threadHeader(active);
      loadList();
    };
    $('.gi-avatar input', m.el).addEventListener('change', async (ev) => {
      const file = ev.target.files[0];
      if (!file) return;
      try {
        update(await api.upload('chat/group-update', { conversation_id: c.id, file }));
        m.close();
        toast('Group photo updated', 'success');
      } catch (err) {
        toastError(err);
      }
    });
    m.el.addEventListener('click', async (ev) => {
      const g = ev.target.closest('[data-g]')?.dataset.g;
      if (!g) return;
      try {
        if (g === 'rename') {
          update(await api.post('chat/group-update', { conversation_id: c.id, name: $('.gi-name input', m.el).value.trim() }));
          m.close();
          toast('Group renamed', 'success');
        } else if (g === 'remove') {
          const uid = Number(ev.target.closest('[data-uid]').dataset.uid);
          if (!(await confirmBox('Remove this member from the group?', { confirmText: 'Remove', danger: true }))) return;
          update(await api.post('chat/group-remove', { conversation_id: c.id, user_id: uid }));
          m.close();
        } else if (g === 'add') {
          m.close();
          pickFriends({ title: 'Add friends to group', exclude: c.members.map((u) => u.id), confirm: 'Add' }, async (ids) => {
            update(await api.post('chat/group-add', { conversation_id: c.id, user_ids: ids }));
            toast('Members added', 'success');
          });
        } else if (g === 'leave') {
          if (!(await confirmBox(`Leave “${c.name}”?`, { confirmText: 'Leave group', danger: true }))) return;
          await api.post('chat/leave', { conversation_id: c.id });
          m.close();
          conversations = conversations.filter((x) => x.id !== c.id);
          closeThread();
          toast('You left the group', 'success');
        }
      } catch (err) {
        toastError(err);
      }
    });
  }

  // Initial load
  (async () => {
    await loadList();
    if (destroyed) return;
    if (openUser) {
      try {
        const c = await api.post('chat/open', { user_id: Number(openUser) });
        if (!conversations.find((x) => x.id === c.id)) conversations.unshift(c);
        renderList();
        openConversation(c.id);
      } catch (err) {
        toastError(err);
      }
    } else if (openId) {
      openConversation(Number(openId));
    } else if (window.innerWidth >= 900 && conversations.length) {
      openConversation(conversations[0].id);
    }
  })();
  listTimer = setInterval(() => { if (document.visibilityState === 'visible') loadList(); }, 12000);

  return () => {
    destroyed = true;
    clearInterval(pollTimer);
    clearInterval(listTimer);
  };
}

function groupAvatar(c, size = 'md') {
  if (c.avatar) return `<span class="avatar avatar-${size}"><img src="${esc(asset(c.avatar))}" alt=""></span>`;
  const others = (c.members || []).slice(0, 2);
  return `<span class="avatar avatar-${size} group-av">${others.map((u) => avatar(u, 'xs', { presence: false })).join('') || icon('users')}</span>`;
}

/** Friend picker modal. cb(ids) is awaited; errors are shown. */
async function pickFriends({ title, exclude = [], confirm = 'Done', withName = false }, cb) {
  let friends = [];
  try {
    friends = (await api.get('friends/list')).friends.filter((f) => !exclude.includes(f.id));
  } catch (err) {
    toastError(err);
    return;
  }
  const m = openModal({
    title,
    size: 'md',
    body: `
      ${withName ? `<label class="field"><span>Group name</span><input class="input group-name" maxlength="80" placeholder="e.g. Vice City Heist Crew"></label>
        <label class="field"><span>Group photo (optional)</span><input type="file" accept="image/*" class="input group-photo"></label>` : ''}
      <div class="search-input small">${icon('search')}<input type="search" class="fp-search" placeholder="Search friends…"></div>
      <div class="friend-pick">${friends.length ? friends.map((f) => `
        <label class="fp-row" data-name="${esc((f.name + ' ' + f.username).toLowerCase())}"><input type="checkbox" value="${f.id}">${avatar(f, 'sm')}<span><strong>${esc(f.name)}</strong><small class="muted">@${esc(f.username)}</small></span></label>`).join('')
        : `<div class="empty small"><p>No friends to add yet. <a href="#/friends?tab=find">Find players</a></p></div>`}</div>`,
    footer: `<button class="btn btn-ghost" data-act="cancel">Cancel</button><button class="btn btn-primary" data-act="ok">${esc(confirm)}</button>`,
  });
  $('.fp-search', m.el).addEventListener('input', (e) => {
    const q = e.target.value.toLowerCase();
    $$('.fp-row', m.el).forEach((r) => { r.hidden = q && !r.dataset.name.includes(q); });
  });
  $('[data-act="cancel"]', m.el).addEventListener('click', () => m.close());
  $('[data-act="ok"]', m.el).addEventListener('click', async (e) => {
    const ids = $$('.fp-row input:checked', m.el).map((i) => Number(i.value));
    if (!ids.length) return toast('Select at least one friend.', 'warn');
    const extra = withName ? { name: $('.group-name', m.el).value.trim(), file: $('.group-photo', m.el).files[0] } : {};
    if (withName && !extra.name) return toast('Give your group a name.', 'warn');
    setBusy(e.target, true);
    try {
      await cb(ids, extra);
      m.close();
    } catch (err) {
      toastError(err);
      setBusy(e.target, false);
    }
  });
}

function groupCreate(onCreated) {
  pickFriends({ title: 'New group', confirm: 'Create group', withName: true }, async (ids, extra) => {
    const payload = { name: extra.name, member_ids: ids };
    const c = extra.file ? await api.upload('chat/group-create', { ...payload, file: extra.file }) : await api.post('chat/group-create', payload);
    toast('Group created!', 'success');
    onCreated(c);
  });
}

/* ================================================================= FRIENDS */

function friendCard(f, actions) {
  return `<div class="card friend-card" data-uid="${f.id}">
    <a href="#/profile/${f.id}" class="fc-top">${avatar(f, 'lg')}<span class="fc-name"><strong>${esc(f.name)}</strong><small class="muted">@${esc(f.username)}</small></span></a>
    <div class="fc-meta">${levelPill(f.level)}<span class="muted small">${esc(presenceLabel(f))}</span></div>
    ${f.presence_text ? `<p class="fc-activity">“${esc(f.presence_text)}”</p>` : ''}
    <div class="fc-actions">${actions}</div>
  </div>`;
}

async function renderFriends(body, switchTab) {
  body.innerHTML = loader();
  let data;
  try {
    data = await api.get('friends/list');
  } catch (err) {
    body.innerHTML = '';
    body.appendChild(errorState(err, () => renderFriends(body, switchTab)));
    return;
  }
  const online = data.friends.filter((f) => f.presence !== 'offline').length;
  body.innerHTML = `
    <div class="row-between section-head"><h3>${data.friends.length} friends · <span class="online-text">${online} online</span></h3>
      <button class="btn btn-ghost btn-sm" data-act="find">${icon('userPlus')} Add friends</button></div>
    ${data.friends.length ? `<div class="friends-grid">${data.friends.map((f) => friendCard(f, `
      <button class="btn btn-primary btn-sm" data-act="message">${icon('chat')} Message</button>
      <button class="icon-btn" data-act="menu" aria-label="More">${icon('more')}</button>`)).join('')}</div>`
      : emptyState('users', 'No friends yet', 'Find other fans and send them a friend request.', '<button class="btn btn-primary" data-act="find">Find players</button>')}
    ${data.blocked.length ? `<details class="blocked-list"><summary>${icon('ban')} Blocked players (${data.blocked.length})</summary>
      ${data.blocked.map((b) => `<div class="member-row" data-uid="${b.id}">${avatar(b, 'sm', { presence: false })}<strong>${esc(b.name)}</strong><button class="btn btn-ghost btn-sm" data-act="unblock">Unblock</button></div>`).join('')}</details>` : ''}`;

  body.onclick = async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (!act) return;
    if (act === 'find') return switchTab('find');
    const uid = Number(e.target.closest('[data-uid]')?.dataset.uid);
    const friend = data.friends.find((f) => f.id === uid);
    try {
      if (act === 'message') {
        navigate(`#/friends?tab=chats&user=${uid}`);
      } else if (act === 'unblock') {
        await api.post('friends/unblock', { user_id: uid });
        toast('Player unblocked', 'success');
        renderFriends(body, switchTab);
      } else if (act === 'menu') {
        const m = openModal({
          title: friend.name,
          size: 'sm',
          body: `<div class="menu-list">
            <a href="#/profile/${uid}" class="menu-item">${icon('user')} View profile</a>
            <button class="menu-item" data-m="remove">${icon('minus')} Remove friend</button>
            <button class="menu-item danger" data-m="block">${icon('ban')} Block</button></div>`,
        });
        m.el.addEventListener('click', async (ev) => {
          const mm = ev.target.closest('[data-m]')?.dataset.m;
          if (ev.target.closest('a')) m.close();
          if (!mm) return;
          m.close();
          if (mm === 'remove' && await confirmBox(`Remove ${friend.name} from your friends?`, { confirmText: 'Remove', danger: true })) {
            await api.post('friends/remove', { user_id: uid });
            toast('Friend removed', 'success');
            renderFriends(body, switchTab);
          }
          if (mm === 'block' && await confirmBox(`Block ${friend.name}? They won't be able to message you or see your content.`, { confirmText: 'Block', danger: true })) {
            await api.post('friends/block', { user_id: uid });
            toast('Player blocked', 'success');
            renderFriends(body, switchTab);
          }
        });
      }
    } catch (err) {
      toastError(err);
    }
  };
}

/* ================================================================ REQUESTS */

async function renderRequests(body) {
  body.innerHTML = loader();
  let data;
  try {
    data = await api.get('friends/list');
  } catch (err) {
    body.innerHTML = '';
    body.appendChild(errorState(err, () => renderRequests(body)));
    return;
  }
  setState({ counts: { ...state.counts, friend_requests: data.incoming.length } });
  body.innerHTML = `
    <section class="section"><div class="section-head"><h3>${icon('userPlus')} Received (${data.incoming.length})</h3></div>
      ${data.incoming.length ? `<div class="friends-grid">${data.incoming.map((f) => friendCard(f, `
        <button class="btn btn-primary btn-sm" data-act="accept">${icon('check')} Accept</button>
        <button class="btn btn-ghost btn-sm" data-act="decline">Decline</button>`)).join('')}</div>` : '<p class="muted">No pending requests.</p>'}
    </section>
    <section class="section"><div class="section-head"><h3>${icon('send')} Sent (${data.outgoing.length})</h3></div>
      ${data.outgoing.length ? `<div class="friends-grid">${data.outgoing.map((f) => friendCard(f, `<button class="btn btn-ghost btn-sm" data-act="cancel">Cancel request</button>`)).join('')}</div>` : '<p class="muted">No sent requests.</p>'}
    </section>`;
  body.onclick = async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    const uid = Number(e.target.closest('[data-uid]')?.dataset.uid);
    if (!act || !uid) return;
    setBusy(e.target.closest('button'), true);
    try {
      if (act === 'accept') {
        await api.post('friends/respond', { user_id: uid, action: 'accept' });
        toast('Friend added! +5 XP', 'success');
      } else if (act === 'decline') {
        await api.post('friends/respond', { user_id: uid, action: 'decline' });
      } else if (act === 'cancel') {
        await api.post('friends/cancel', { user_id: uid });
      }
      renderRequests(body);
    } catch (err) {
      toastError(err);
    }
  };
}

/* ==================================================================== FIND */

export function relationButton(u) {
  switch (u.relation) {
    case 'friends': return `<button class="btn btn-ghost btn-sm" data-act="message">${icon('chat')} Message</button>`;
    case 'outgoing': return `<button class="btn btn-ghost btn-sm" data-act="cancel">${icon('clock')} Requested</button>`;
    case 'incoming': return `<button class="btn btn-primary btn-sm" data-act="accept">${icon('check')} Accept</button>`;
    case 'blocked': return `<button class="btn btn-ghost btn-sm" data-act="unblock">Unblock</button>`;
    default: return `<button class="btn btn-primary btn-sm" data-act="add">${icon('userPlus')} Add friend</button>`;
  }
}

function renderFind(body) {
  body.innerHTML = `
    <div class="find-bar search-input big">${icon('search')}<input type="search" placeholder="Search by name or @username…" autofocus></div>
    <h3 class="find-title muted small">Recently active players</h3>
    <div class="find-results friends-grid">${loader()}</div>`;
  const input = $('input', body);
  const results = $('.find-results', body);
  let list = [];
  let seq = 0;
  async function search() {
    const q = input.value.trim();
    const mine = ++seq;
    try {
      const found = await api.get('friends/search', { q });
      if (mine !== seq) return; // a newer search is on its way
      list = found;
      $('.find-title', body).textContent = q.length >= 2 ? `Results for “${q}”` : 'Recently active players';
      results.innerHTML = list.length ? list.map((u) => friendCard(u, relationButton(u))).join('') : `<div class="empty small"><p>No players found.</p></div>`;
    } catch (err) {
      results.innerHTML = `<p class="muted">${esc(err.message)}</p>`;
    }
  }
  input.addEventListener('input', debounce(search, 300));
  results.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    const uid = Number(e.target.closest('[data-uid]')?.dataset.uid);
    if (!act || !uid) return;
    const u = list.find((x) => x.id === uid);
    const btn = e.target.closest('button');
    try {
      if (act === 'message') return navigate(`#/friends?tab=chats&user=${uid}`);
      setBusy(btn, true);
      let r;
      if (act === 'add') r = await api.post('friends/request', { user_id: uid });
      else if (act === 'cancel') r = await api.post('friends/cancel', { user_id: uid });
      else if (act === 'accept') r = await api.post('friends/respond', { user_id: uid, action: 'accept' });
      else if (act === 'unblock') r = await api.post('friends/unblock', { user_id: uid });
      u.relation = r.relation;
      btn.outerHTML = relationButton(u);
      if (act === 'add') toast(r.relation === 'friends' ? 'You are now friends!' : 'Friend request sent', 'success');
    } catch (err) {
      toastError(err);
      setBusy(btn, false);
    }
  });
  search();
}
