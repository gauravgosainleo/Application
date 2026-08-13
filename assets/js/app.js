/* ============================================================
   AI Platform — SPA controller
   ============================================================ */
const $  = (s, r=document) => r.querySelector(s);
const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));
const main   = $('#main');
const modal  = $('#modal-root');

async function api(path, opts = {}) {
  const res = await fetch(path, {
    credentials: 'same-origin',
    headers: opts.body && !(opts.body instanceof FormData) ? {'Content-Type':'application/json'} : {},
    ...opts,
  });
  try { return await res.json(); }
  catch { return { success:false, message:'Invalid server response' }; }
}
const jpost = (path, body) => api(path, { method:'POST', body: JSON.stringify(body) });
const jget  = (path)       => api(path);
const upload = (path, fd)  => api(path, { method:'POST', body: fd });

function esc(s='') { return String(s).replace(/[&<>"']/g, m => (
  {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
)); }
function fmtDate(dt) {
  if (!dt) return '';
  const d = new Date(dt.replace(' ', 'T'));
  return d.toLocaleString();
}
function avatarUrl(u) {
  if (u && u.profile_picture_url) return u.profile_picture_url;
  return 'assets/css/default-avatar.svg';
}

function openModal(html) {
  modal.innerHTML = `<div class="modal-backdrop"><div class="modal">
    <button class="close" onclick="closeModal()">&times;</button>${html}</div></div>`;
  modal.querySelector('.modal-backdrop').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
  });
}
function closeModal() { modal.innerHTML = ''; }
window.closeModal = closeModal;

function toast(msg, isErr=false) {
  const t = document.createElement('div');
  t.textContent = msg;
  t.style.cssText = `position:fixed;bottom:90px;left:50%;transform:translateX(-50%);
    padding:10px 18px;border-radius:8px;color:#fff;z-index:1000;
    background:${isErr?'#ef4444':'#10b981'};box-shadow:0 6px 20px rgba(0,0,0,0.2)`;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3200);
}

const routes = {
  news:     renderNews,
  forums:   renderForums,
  shop:     renderShop,
  events:   renderEvents,
  friends:  renderFriends,
  messages: renderMessages,
};
async function go(view) {
  $$('.nav-btn').forEach(b => b.classList.toggle('active', b.dataset.view === view));
  main.innerHTML = '<div class="loading">Loading…</div>';
  await (routes[view] || renderNews)();
}
$$('.nav-btn').forEach(b => b.addEventListener('click', () => go(b.dataset.view)));

$('#btnLogout').addEventListener('click', async () => {
  await jpost('api/auth.php?action=logout', {});
  window.location.href = 'login.php';
});
$('#btnProfile').addEventListener('click', showProfile);
if (window.APP.isAdmin) $('#btnSettings').addEventListener('click', showAdminSettings);

(async () => {
  const j = await jget('api/profile.php?action=get');
  if (j.success && j.user && j.user.profile_picture_url) {
    $('#topbarAvatar').src = j.user.profile_picture_url;
  }
})();

go('news');

// ================================================================
// PROFILE MODAL
// ================================================================
async function showProfile() {
  if (window.APP.isAdmin) {
    openModal(`<h2>Administrator</h2>
      <p>Admin profile is read-only.</p>
      <p><strong>Email:</strong> admin@aiplatform.fun</p>`);
    return;
  }
  const j = await jget('api/profile.php?action=get');
  if (!j.success) return toast(j.message, true);
  const u = j.user;
  openModal(`
    <h2>My Profile</h2>
    <div style="display:flex;gap:14px;align-items:center;margin-bottom:12px">
      <img id="pfPic" class="avatar-md" style="width:80px;height:80px" src="${avatarUrl({profile_picture_url:u.profile_picture_url})}">
      <div>
        <input type="file" id="pfFile" accept="image/*">
        <div class="hint">JPG/PNG up to 10 MB</div>
      </div>
    </div>
    <form id="pfForm">
      <label>Name</label><input name="name" value="${esc(u.name||'')}" required>
      <label>Email</label><input name="email" type="email" value="${esc(u.email||'')}" required>
      <label>Phone</label><input name="phone" value="${esc(u.phone||'')}">
      <label>Bio</label><textarea name="bio">${esc(u.bio||'')}</textarea>
      <button class="btn primary" type="submit" style="margin-top:12px">Save</button>
    </form>
    <hr style="margin:16px 0">
    <h3>Change Password</h3>
    <form id="pwForm">
      <label>Current password</label><input name="current_password" type="password" required>
      <label>New password</label><input name="new_password" type="password" minlength="6" required>
      <label>Confirm new password</label><input name="confirm_password" type="password" minlength="6" required>
      <button class="btn primary" type="submit" style="margin-top:12px">Update password</button>
    </form>
  `);
  $('#pfFile').addEventListener('change', async e => {
    if (!e.target.files[0]) return;
    const fd = new FormData();
    fd.append('photo', e.target.files[0]);
    const r = await upload('api/profile.php?action=upload_photo', fd);
    if (r.success) { $('#pfPic').src = r.path; $('#topbarAvatar').src = r.path; toast('Photo updated'); }
    else toast(r.message || 'Upload failed', true);
  });
  $('#pfForm').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = Object.fromEntries(fd.entries());
    const r = await jpost('api/profile.php?action=update', body);
    toast(r.message, !r.success);
  });
  $('#pwForm').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = Object.fromEntries(fd.entries());
    const r = await jpost('api/profile.php?action=change_password', body);
    toast(r.message, !r.success);
    if (r.success) e.target.reset();
  });
}

// ================================================================
// NEWS
// ================================================================
async function renderNews() {
  const j = await jget('api/news.php?action=list');
  const items = j.items || [];
  main.innerHTML = `
    <div class="view-title">
      <span>🗞️ AI News & Updates</span>
      ${window.APP.isAdmin ? '<button class="btn primary" id="btnNewNews">+ Post News</button>' : ''}
    </div>
    <div id="newsList">
      ${items.length ? items.map(newsCard).join('') : '<div class="empty">No news yet.</div>'}
    </div>
  `;
  if (window.APP.isAdmin) $('#btnNewNews').addEventListener('click', showNewsForm);
  $$('#newsList .card').forEach(card => bindNewsCard(card));
}

function newsCard(n) {
  return `<div class="card" data-id="${n.id}">
    <h3>${esc(n.title)}</h3>
    <div class="meta">${fmtDate(n.created_at)} · ${n.comments_count} comment(s)</div>
    ${n.image_url ? `<img class="banner" src="${n.image_url}">` : ''}
    <div>${esc(n.content).replace(/\n/g,'<br>')}</div>
    <div style="margin-top:8px">
      <button class="btn ghost small toggleComments">💬 Comments</button>
      ${window.APP.isAdmin ? `<button class="btn danger small delNews">Delete</button>` : ''}
    </div>
    <div class="comments" style="display:none"></div>
  </div>`;
}

function bindNewsCard(card) {
  const id = card.dataset.id;
  const tog = card.querySelector('.toggleComments');
  const box = card.querySelector('.comments');
  tog.addEventListener('click', async () => {
    if (box.style.display === 'none') {
      box.style.display = 'block';
      const j = await jget(`api/news.php?action=comments&news_id=${id}`);
      box.innerHTML = renderComments(j.items || []) + (window.APP.isAdmin ? '' : `
        <form class="cForm" style="display:flex;gap:6px;margin-top:8px">
          <input name="c" placeholder="Add a comment..." required>
          <button class="btn primary small" type="submit">Send</button>
        </form>`);
      const f = box.querySelector('.cForm');
      if (f) f.addEventListener('submit', async e => {
        e.preventDefault();
        const c = f.c.value.trim();
        if (!c) return;
        const r = await jpost('api/news.php?action=comment', { news_id: id, comment: c });
        if (r.success) { f.reset(); tog.click(); tog.click(); }
        else toast(r.message, true);
      });
    } else box.style.display = 'none';
  });
  const del = card.querySelector('.delNews');
  if (del) del.addEventListener('click', async () => {
    if (!confirm('Delete this news post?')) return;
    const r = await jget(`api/news.php?action=delete&id=${id}`);
    if (r.success) { renderNews(); toast('Deleted'); }
  });
}

function renderComments(list) {
  if (!list.length) return '<div class="hint">No comments yet.</div>';
  return list.map(c => `<div class="comment">
      <img class="avatar-sm" src="${avatarUrl({profile_picture_url:c.profile_picture_url})}">
      <div><div class="who">${esc(c.name||'User')}</div>
        <div class="text">${esc(c.comment)}</div>
        <div class="hint">${fmtDate(c.created_at)}</div></div>
    </div>`).join('');
}

function showNewsForm() {
  openModal(`
    <h2>Post AI News</h2>
    <form id="nnForm" enctype="multipart/form-data">
      <label>Title</label><input name="title" required>
      <label>Content</label><textarea name="content" required></textarea>
      <label>Image (optional)</label><input type="file" name="image" accept="image/*">
      <button class="btn primary" type="submit" style="margin-top:12px">Publish</button>
    </form>
  `);
  $('#nnForm').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const r = await upload('api/news.php?action=create', fd);
    toast(r.message, !r.success);
    if (r.success) { closeModal(); renderNews(); }
  });
}

// ================================================================
// FORUMS
// ================================================================
async function renderForums() {
  const [all, mine] = await Promise.all([
    jget('api/forums.php?action=list'),
    window.APP.isAdmin ? Promise.resolve({items:[]}) : jget('api/forums.php?action=mine'),
  ]);
  main.innerHTML = `
    <div class="view-title">
      <span>💬 Forums & Responses</span>
      ${window.APP.isAdmin ? '' : '<button class="btn primary" id="btnNewForum">+ New Forum</button>'}
    </div>
    <div class="card">
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <button class="btn ghost small" id="tabAll">All Forums</button>
        ${window.APP.isAdmin ? '' : '<button class="btn ghost small" id="tabMine">My Forums</button>'}
      </div>
      <div id="forumList"></div>
    </div>
  `;
  const renderList = (items) => {
    const el = $('#forumList');
    if (!items.length) { el.innerHTML = '<div class="empty">No forums yet.</div>'; return; }
    el.innerHTML = items.map(forumCard).join('');
    $$('#forumList .card').forEach(bindForumCard);
  };
  renderList(all.items || []);
  $('#tabAll').addEventListener('click', () => renderList(all.items || []));
  if ($('#tabMine')) $('#tabMine').addEventListener('click', () => renderList(mine.items || []));
  if ($('#btnNewForum')) $('#btnNewForum').addEventListener('click', showForumForm);
}

function forumCard(f) {
  return `<div class="card" data-id="${f.id}">
    <div style="display:flex;gap:8px;align-items:center">
      <img class="avatar-sm" src="${avatarUrl({profile_picture_url:f.profile_picture_url})}">
      <div>
        <div class="who" style="font-weight:700">${esc(f.author||'User')}</div>
        <div class="meta">${fmtDate(f.created_at)}</div>
      </div>
    </div>
    <h3 style="margin-top:8px">${esc(f.title)}</h3>
    <div>${esc(f.content).replace(/\n/g,'<br>')}</div>
    <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
      <button class="btn ghost small react" data-r="like">👍 ${f.likes}</button>
      <button class="btn ghost small react" data-r="dislike">👎 ${f.dislikes}</button>
      <button class="btn ghost small toggleC">💬 ${f.comments_count}</button>
      ${(window.APP.isAdmin || Number(f.user_id) === Number(window.APP.currentUser.id))
        ? '<button class="btn danger small delForum">Delete</button>' : ''}
    </div>
    <div class="comments" style="display:none"></div>
  </div>`;
}

function bindForumCard(card) {
  const id = card.dataset.id;
  card.querySelectorAll('.react').forEach(btn => btn.addEventListener('click', async () => {
    const r = await jpost('api/forums.php?action=react', { forum_id: id, reaction: btn.dataset.r });
    if (r.success) renderForums();
    else toast(r.message || 'Error', true);
  }));
  const tog = card.querySelector('.toggleC');
  const box = card.querySelector('.comments');
  tog.addEventListener('click', async () => {
    if (box.style.display === 'none') {
      box.style.display = 'block';
      const j = await jget(`api/forums.php?action=comments&forum_id=${id}`);
      box.innerHTML = renderComments(j.items||[]) + (window.APP.isAdmin ? '' : `
        <form class="cForm" style="display:flex;gap:6px;margin-top:8px">
          <input name="c" placeholder="Add a comment..." required>
          <button class="btn primary small" type="submit">Send</button>
        </form>`);
      const f = box.querySelector('.cForm');
      if (f) f.addEventListener('submit', async e => {
        e.preventDefault();
        const c = f.c.value.trim();
        if (!c) return;
        const r = await jpost('api/forums.php?action=comment', { forum_id: id, comment: c });
        if (r.success) { f.reset(); tog.click(); tog.click(); }
        else toast(r.message, true);
      });
    } else box.style.display = 'none';
  });
  const del = card.querySelector('.delForum');
  if (del) del.addEventListener('click', async () => {
    if (!confirm('Delete this forum?')) return;
    const r = await jget(`api/forums.php?action=delete&id=${id}`);
    if (r.success) renderForums();
  });
}

function showForumForm() {
  openModal(`<h2>New Forum</h2>
    <form id="fForm">
      <label>Title</label><input name="title" required>
      <label>Content</label><textarea name="content" required></textarea>
      <button class="btn primary" style="margin-top:12px" type="submit">Post</button>
    </form>`);
  $('#fForm').addEventListener('submit', async e => {
    e.preventDefault();
    const body = { title: e.target.title.value, content: e.target.content.value };
    const r = await jpost('api/forums.php?action=create', body);
    toast(r.message, !r.success);
    if (r.success) { closeModal(); renderForums(); }
  });
}

// ================================================================
// AI SHOP
// ================================================================
async function renderShop() {
  const [all, mine] = await Promise.all([
    jget('api/shop.php?action=list'),
    window.APP.isAdmin ? Promise.resolve({items:[]}) : jget('api/shop.php?action=mine'),
  ]);
  main.innerHTML = `
    <div class="view-title">
      <span>🛍️ AI Shop</span>
      ${window.APP.isAdmin ? '' : '<button class="btn primary" id="btnSell">Start selling AI agents</button>'}
    </div>
    ${window.APP.isAdmin ? '' : `<div class="card">
      <h3>My Submissions</h3>
      <div id="mineList">${(mine.items||[]).map(myApp).join('') || '<div class="hint">You have no submissions.</div>'}</div>
    </div>`}
    <div class="shop-grid" id="shopGrid">
      ${(all.items||[]).map(shopCard).join('') || '<div class="empty">No apps published yet.</div>'}
    </div>
  `;
  if ($('#btnSell')) $('#btnSell').addEventListener('click', showSellIntro);
  $$('#shopGrid .card').forEach(bindShopCard);
}

function myApp(a) {
  return `<div style="padding:8px 0;border-bottom:1px solid #eef2ff">
    <strong>${esc(a.app_name)}</strong>
    <span class="badge ${a.status}">${a.status.replace('_',' ')}</span>
    ${a.rejection_reason ? `<div class="hint">Reason: ${esc(a.rejection_reason)}</div>` : ''}
  </div>`;
}

function shopCard(a) {
  const img = (a.images && a.images[0]) || '';
  return `<div class="card shop-card" data-id="${a.id}">
    ${img ? `<img src="${img}">` : ''}
    <h3>${esc(a.app_name)}</h3>
    <div class="meta">By ${esc(a.developer_name)} · ${fmtDate(a.published_at)}</div>
    <div>${esc((a.description||'').slice(0,140))}${(a.description||'').length>140?'…':''}</div>
    <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
      <button class="btn ghost small viewApp">Open</button>
      <button class="btn ghost small likeApp">❤️ ${a.likes_count}</button>
      <button class="btn primary small contactDev">Connect with developer</button>
    </div>
  </div>`;
}

function bindShopCard(card) {
  const id = card.dataset.id;
  card.querySelector('.viewApp').addEventListener('click', () => openAppDetail(id));
  card.querySelector('.likeApp').addEventListener('click', async () => {
    const r = await jpost('api/shop.php?action=like', { app_id: id });
    if (r.success) renderShop();
    else toast(r.message, true);
  });
  card.querySelector('.contactDev').addEventListener('click', () => openContactDev(id));
}

async function openAppDetail(id) {
  const j = await jget('api/shop.php?action=view&id=' + id);
  if (!j.success) return toast(j.message, true);
  const a = j.app;
  const galleryHtml = (a.images||[]).map(u => `<img src="${u}" style="max-width:100%;border-radius:8px;margin-bottom:8px">`).join('');
  openModal(`
    <h2>${esc(a.app_name)}</h2>
    <div class="meta">By ${esc(a.developer_name)} · ${a.likes_count} likes</div>
    ${galleryHtml}
    <p>${esc(a.description).replace(/\n/g,'<br>')}</p>
    ${a.app_link ? `<p><strong>Link:</strong> <a href="${esc(a.app_link)}" target="_blank">${esc(a.app_link)}</a></p>` : ''}
    <div style="margin:12px 0">
      <button class="btn primary" id="mConnect">Connect with developer</button>
    </div>
    ${a.comments_enabled == 1 ? `
      <h3>Comments</h3>
      <div id="mComments"></div>
      ${window.APP.isAdmin ? '' : `
        <form id="mCForm" style="display:flex;gap:6px;margin-top:8px">
          <input name="c" placeholder="Add a comment..." required>
          <button class="btn primary small" type="submit">Send</button>
        </form>`}
    ` : '<div class="hint">Comments are disabled by the developer.</div>'}
  `);
  $('#mConnect').addEventListener('click', () => openContactDev(id));
  if (a.comments_enabled == 1) {
    const load = async () => {
      const cj = await jget('api/shop.php?action=comments&app_id=' + id);
      $('#mComments').innerHTML = renderComments(cj.items||[]);
    };
    load();
    if ($('#mCForm')) $('#mCForm').addEventListener('submit', async e => {
      e.preventDefault();
      const r = await jpost('api/shop.php?action=comment', { app_id: id, comment: e.target.c.value });
      if (r.success) { e.target.reset(); load(); } else toast(r.message, true);
    });
  }
}

async function openContactDev(id) {
  const j = await jget('api/shop.php?action=view&id=' + id);
  if (!j.success) return toast(j.message, true);
  const a = j.app;
  openModal(`
    <h2>Contact Developer</h2>
    <div style="display:flex;gap:12px;align-items:center;margin:10px 0">
      <img class="avatar-md" style="width:60px;height:60px"
        src="${a.developer_photo_url || 'assets/css/default-avatar.svg'}">
      <div>
        <div><strong>${esc(a.developer_name)}</strong></div>
        <div class="hint">${esc(a.developer_email||'')}</div>
        <div class="hint">${esc(a.developer_phone||'')}</div>
      </div>
    </div>
    <form id="intForm">
      <label>Message</label>
      <textarea name="msg" placeholder="Hi, I'm interested in your AI agent..." required></textarea>
      <button class="btn primary" style="margin-top:10px" type="submit">Send Interest</button>
    </form>
    <div class="hint" style="margin-top:8px">Or send a private message (requires friend request):</div>
    <button class="btn ghost" style="margin-top:6px" onclick='window.__openDMWith(${a.developer_id})'>Open Direct Messages</button>
  `);
  $('#intForm').addEventListener('submit', async e => {
    e.preventDefault();
    const r = await jpost('api/shop.php?action=interest', { app_id: id, message: e.target.msg.value });
    toast(r.message, !r.success);
    if (r.success) closeModal();
  });
}

window.__openDMWith = async (uid) => {
  closeModal();
  await go('messages');
  setTimeout(() => {
    const t = document.querySelector(`.thread-item[data-uid="${uid}"]`);
    if (t) t.click();
    else toast('You must be friends first. Send a friend request from their profile.', true);
  }, 300);
};

function showSellIntro() {
  openModal(`
    <h2>Start selling on AI Shop</h2>
    <p>You can put your AI Agents up for sale on AI Shop. There is an annual charge
       of <strong>₹499 INR</strong> for an app to be displayed for users to purchase.
       Once published, users can view, like, comment (if enabled) and connect with you
       to buy the application.</p>
    <button class="btn primary" id="btnCont">Continue</button>
  `);
  $('#btnCont').addEventListener('click', showSellForm);
}

function showSellForm() {
  openModal(`
    <h2>List your AI Agent</h2>
    <form id="sellForm" enctype="multipart/form-data">
      <label>Name of the application</label>
      <input name="app_name" required>

      <label>Use of the application (description)</label>
      <textarea name="description" required></textarea>

      <label>Link (video demo or main app link)</label>
      <input name="app_link" type="url" placeholder="https://...">

      <label>Images of the application</label>
      <input type="file" name="images[]" accept="image/*" multiple>

      <label style="display:flex;gap:8px;align-items:center;margin-top:10px">
        <input type="checkbox" name="comments_enabled" value="1" checked style="width:auto">
        Comments enabled
      </label>

      <button class="btn primary" type="submit" style="margin-top:14px">
        Submit for review and pay ₹499
      </button>
    </form>
  `);
  $('#sellForm').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const r = await upload('api/shop.php?action=submit', fd);
    if (!r.success) return toast(r.message || 'Failed', true);
    const options = {
      key: r.key_id,
      amount: r.amount,
      currency: r.currency,
      name: r.name,
      description: r.description,
      order_id: r.order_id,
      prefill: r.prefill,
      theme: { color: '#4f46e5' },
      handler: async (resp) => {
        const v = await jpost('api/shop.php?action=verify_payment', resp);
        toast(v.message, !v.success);
        closeModal();
        renderShop();
      },
      modal: { ondismiss: () => toast('Payment cancelled', true) }
    };
    const rzp = new Razorpay(options);
    rzp.on('payment.failed', function(resp) { toast('Payment failed: ' + (resp.error?.description||''), true); });
    rzp.open();
  });
}

// ================================================================
// EVENTS / TRAININGS
// ================================================================
let calCursor = null;
async function renderEvents() {
  if (!calCursor) {
    const n = new Date();
    calCursor = { y: n.getFullYear(), m: n.getMonth() };
  }
  const ym = `${calCursor.y}-${String(calCursor.m+1).padStart(2,'0')}`;
  const j = await jget(`api/events.php?action=list&month=${ym}`);
  const events = j.items || [];
  const byDay = {};
  events.forEach(e => {
    const d = new Date(e.event_datetime.replace(' ','T'));
    const k = d.getFullYear() + '-' + (d.getMonth()+1) + '-' + d.getDate();
    (byDay[k] = byDay[k]||[]).push(e);
  });
  const first = new Date(calCursor.y, calCursor.m, 1);
  const last  = new Date(calCursor.y, calCursor.m+1, 0);
  const startDow = first.getDay();
  const daysInMonth = last.getDate();
  const monthName = first.toLocaleString('default', { month: 'long', year: 'numeric' });
  const today = new Date();
  const isToday = (d) =>
    today.getFullYear()===calCursor.y && today.getMonth()===calCursor.m && today.getDate()===d;

  const dows = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  let cells = '';
  for (let i=0;i<startDow;i++) cells += `<div class="cal-cell empty"></div>`;
  for (let d=1; d<=daysInMonth; d++) {
    const k = calCursor.y + '-' + (calCursor.m+1) + '-' + d;
    const has = byDay[k];
    cells += `<div class="cal-cell ${has?'has-event':''} ${isToday(d)?'today':''}" data-key="${k}">
      <div class="date-num">${d}</div>
      ${has ? `<div>${has.length} event(s)</div><div class="dot"></div>` : ''}
    </div>`;
  }

  main.innerHTML = `
    <div class="view-title">
      <span>🎓 AI Trainings, Seminars & Certifications</span>
      ${window.APP.isAdmin ? '<button class="btn primary" id="btnNewEvent">+ Add Event</button>' : ''}
    </div>
    <div class="cal-wrap">
      <div class="cal-head">
        <button class="btn ghost small" id="prevM">‹ Prev</button>
        <h3 style="margin:0">${monthName}</h3>
        <button class="btn ghost small" id="nextM">Next ›</button>
      </div>
      <div class="cal-grid">
        ${dows.map(d => `<div class="cal-dow">${d}</div>`).join('')}
        ${cells}
      </div>
    </div>
    <div class="card" style="margin-top:14px">
      <h3>Upcoming Events</h3>
      ${events.length ? events.map(eventRow).join('') : '<div class="hint">No events this month.</div>'}
    </div>
  `;
  $('#prevM').addEventListener('click', () => { calCursor.m--; if (calCursor.m<0){calCursor.m=11;calCursor.y--;} renderEvents(); });
  $('#nextM').addEventListener('click', () => { calCursor.m++; if (calCursor.m>11){calCursor.m=0;calCursor.y++;} renderEvents(); });
  if ($('#btnNewEvent')) $('#btnNewEvent').addEventListener('click', () => showEventForm());
  $$('.cal-cell[data-key]').forEach(c => c.addEventListener('click', () => {
    const list = byDay[c.dataset.key];
    if (!list) return;
    openModal(`<h2>${c.dataset.key}</h2>${list.map(eventFull).join('<hr>')}`);
  }));
}

function eventRow(e) {
  return `<div style="padding:8px 0;border-bottom:1px solid #eef2ff">
    <strong>${esc(e.event_name)}</strong>
    <div class="hint">${fmtDate(e.event_datetime)}</div>
    <div>${esc(e.description).slice(0,180)}</div>
    ${e.link ? `<a href="${esc(e.link)}" target="_blank">Open link →</a>` : ''}
    ${window.APP.isAdmin ? `<div style="margin-top:6px">
      <button class="btn ghost small" onclick='window.__editEvent(${JSON.stringify(e).replace(/"/g,"&quot;")})'>Edit</button>
      <button class="btn danger small" onclick="window.__delEvent(${e.id})">Delete</button>
    </div>`:''}
  </div>`;
}
function eventFull(e) {
  return `<h3 style="margin:8px 0 4px">${esc(e.event_name)}</h3>
    <div class="hint">${fmtDate(e.event_datetime)}</div>
    <p>${esc(e.description).replace(/\n/g,'<br>')}</p>
    ${e.link ? `<a href="${esc(e.link)}" target="_blank">Open link →</a>` : ''}`;
}
window.__delEvent = async (id) => {
  if (!confirm('Delete this event?')) return;
  const r = await jget('api/events.php?action=delete&id='+id);
  if (r.success) renderEvents();
};
window.__editEvent = (e) => showEventForm(e);

function showEventForm(existing = null) {
  const isEdit = !!existing;
  const dtVal = existing ? existing.event_datetime.replace(' ','T').slice(0,16) : '';
  openModal(`
    <h2>${isEdit ? 'Edit' : 'Add'} Event</h2>
    <form id="eForm">
      <label>Event Name</label><input name="event_name" value="${esc(existing?.event_name||'')}" required>
      <label>Event Description</label><textarea name="description" required>${esc(existing?.description||'')}</textarea>
      <label>Link (optional)</label><input name="link" value="${esc(existing?.link||'')}">
      <label>Date & Time</label><input name="event_datetime" type="datetime-local" value="${dtVal}" required>
      <button class="btn primary" style="margin-top:10px" type="submit">Save</button>
    </form>
  `);
  $('#eForm').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = Object.fromEntries(fd.entries());
    if (isEdit) body.id = existing.id;
    const r = await jpost('api/events.php?action=' + (isEdit?'update':'create'), body);
    toast(r.message || 'Saved', !r.success);
    if (r.success) { closeModal(); renderEvents(); }
  });
}

// ================================================================
// FRIENDS
// ================================================================
async function renderFriends() {
  if (window.APP.isAdmin) {
    main.innerHTML = '<div class="empty">Friends is disabled for admin.</div>';
    return;
  }
  const [pend, list] = await Promise.all([
    jget('api/friends.php?action=pending'),
    jget('api/friends.php?action=list'),
  ]);
  main.innerHTML = `
    <div class="view-title"><span>👥 Friends</span></div>
    <div class="card">
      <h3>Find people</h3>
      <input id="searchInput" placeholder="Search by name or email">
      <div id="searchResults" style="margin-top:8px"></div>
    </div>
    <div class="card">
      <h3>Pending requests</h3>
      <div id="pendList">
        ${(pend.items||[]).length ? (pend.items).map(pendRow).join('') : '<div class="hint">No pending requests.</div>'}
      </div>
    </div>
    <div class="card">
      <h3>My friends</h3>
      <div id="frList">
        ${(list.items||[]).length ? (list.items).map(friendRow).join('') : '<div class="hint">You have no friends yet.</div>'}
      </div>
    </div>
  `;
  let sTimer;
  $('#searchInput').addEventListener('input', e => {
    clearTimeout(sTimer);
    const q = e.target.value.trim();
    sTimer = setTimeout(async () => {
      if (q.length < 2) { $('#searchResults').innerHTML = ''; return; }
      const r = await jget('api/friends.php?action=search&q=' + encodeURIComponent(q));
      $('#searchResults').innerHTML = (r.items||[]).map(u => `
        <div style="display:flex;gap:8px;align-items:center;padding:6px 0">
          <img class="avatar-sm" src="${avatarUrl({profile_picture_url:u.profile_picture_url})}">
          <div style="flex:1">
            <div><strong>${esc(u.name)}</strong></div>
            <div class="hint">${esc(u.email)}</div>
          </div>
          <button class="btn primary small" onclick="__viewUser(${u.id})">View</button>
          <button class="btn ghost small" onclick="__sendReq(${u.id})">Add friend</button>
        </div>`).join('') || '<div class="hint">No matches.</div>';
    }, 300);
  });
  bindFriendActions();
}
function pendRow(p) {
  return `<div style="display:flex;gap:8px;align-items:center;padding:6px 0">
    <img class="avatar-sm" src="${avatarUrl({profile_picture_url:p.profile_picture_url})}">
    <div style="flex:1"><strong>${esc(p.name)}</strong><div class="hint">${esc(p.email)}</div></div>
    <button class="btn success small" onclick="__acceptReq(${p.request_id})">Accept</button>
    <button class="btn danger small"  onclick="__rejectReq(${p.request_id})">Reject</button>
  </div>`;
}
function friendRow(f) {
  return `<div style="display:flex;gap:8px;align-items:center;padding:6px 0">
    <img class="avatar-sm" src="${avatarUrl({profile_picture_url:f.profile_picture_url})}">
    <div style="flex:1"><strong>${esc(f.name)}</strong><div class="hint">${esc(f.email)}</div></div>
    <button class="btn ghost small" onclick="__viewUser(${f.id})">View</button>
    <button class="btn primary small" onclick="__openDMWith(${f.id})">Message</button>
  </div>`;
}
function bindFriendActions() {
  window.__sendReq   = async (id) => { const r = await jpost('api/friends.php?action=send',   { user_id:id }); toast(r.message, !r.success); };
  window.__acceptReq = async (id) => { const r = await jpost('api/friends.php?action=accept', { request_id:id }); if(r.success) renderFriends(); };
  window.__rejectReq = async (id) => { const r = await jpost('api/friends.php?action=reject', { request_id:id }); if(r.success) renderFriends(); };
  window.__viewUser  = async (id) => {
    const j = await jget('api/profile.php?action=view_user&id='+id);
    if (!j.success) return toast(j.message, true);
    const u = j.user;
    const fs = j.friendship;
    let actions = '';
    if (fs === 'friends') actions = `<button class="btn primary" onclick='__openDMWith(${u.id})'>Message</button>`;
    else if (fs === 'request_sent') actions = `<div class="hint">Friend request already sent.</div>`;
    else if (fs === 'request_received') actions = `<div class="hint">This user has sent you a request. Check pending list.</div>`;
    else actions = `<button class="btn primary" onclick='__sendReq(${u.id});closeModal()'>Send friend request</button>`;
    openModal(`
      <div style="display:flex;gap:12px;align-items:center">
        <img class="avatar-md" style="width:80px;height:80px"
          src="${u.profile_picture_url || 'assets/css/default-avatar.svg'}">
        <div>
          <h2 style="margin:0">${esc(u.name)}</h2>
          <div class="hint">${esc(u.email)}</div>
          <div class="hint">${esc(u.phone||'')}</div>
        </div>
      </div>
      <p>${esc(u.bio||'')}</p>
      <div style="margin-top:10px">${actions}</div>
    `);
  };
}

// ================================================================
// MESSAGES
// ================================================================
let activeThread = null;
async function renderMessages() {
  if (window.APP.isAdmin) { main.innerHTML = '<div class="empty">Messaging is disabled for admin.</div>'; return; }
  const j = await jget('api/messages.php?action=threads');
  const items = j.items || [];
  main.innerHTML = `
    <div class="view-title"><span>✉️ Messages</span></div>
    <div class="chat-wrap">
      <div class="threads-list">
        ${items.length ? items.map(threadItem).join('') : '<div class="hint" style="padding:8px">No conversations yet. Add friends to start.</div>'}
      </div>
      <div class="chat-pane" id="chatPane">
        <div class="empty">Select a conversation</div>
      </div>
    </div>
  `;
  $$('.thread-item').forEach(el => el.addEventListener('click', () => openThread(el.dataset.uid, el.dataset.name)));
}
function threadItem(t) {
  return `<div class="thread-item" data-uid="${t.id}" data-name="${esc(t.name)}">
    <img class="avatar-sm" src="${avatarUrl({profile_picture_url:t.profile_picture_url})}">
    <div style="flex:1;min-width:0">
      <div style="font-weight:700">${esc(t.name)}</div>
      <div class="hint" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
        ${esc((t.last_message||'').slice(0,40))}
      </div>
    </div>
    ${Number(t.unread) > 0 ? `<span class="unread">${t.unread}</span>` : ''}
  </div>`;
}

async function openThread(uid, name) {
  activeThread = uid;
  $$('.thread-item').forEach(el => el.classList.toggle('active', el.dataset.uid === String(uid)));
  const pane = $('#chatPane');
  pane.innerHTML = `
    <div class="chat-head">${esc(name)}</div>
    <div class="chat-body" id="chatBody"><div class="loading">Loading…</div></div>
    <form class="chat-input" id="chatForm">
      <input name="m" placeholder="Type a message..." autocomplete="off" required>
      <button class="btn primary" type="submit">Send</button>
    </form>
  `;
  await loadMessages(uid);
  $('#chatForm').addEventListener('submit', async e => {
    e.preventDefault();
    const m = e.target.m.value.trim();
    if (!m) return;
    const r = await jpost('api/messages.php?action=send', { to: uid, message: m });
    if (!r.success) return toast(r.message, true);
    e.target.reset();
    await loadMessages(uid);
  });
}
async function loadMessages(uid) {
  const j = await jget('api/messages.php?action=list&with=' + uid);
  const body = $('#chatBody');
  if (!body) return;
  if (!j.success) { body.innerHTML = `<div class="empty">${esc(j.message||'Error')}</div>`; return; }
  body.innerHTML = (j.items||[]).map(m => {
    const mine = Number(m.sender_id) === Number(window.APP.currentUser.id);
    return `<div class="bubble ${mine?'me':'them'}">${esc(m.message)}
      <div style="font-size:10px;opacity:.7;margin-top:2px">${fmtDate(m.created_at)}</div></div>`;
  }).join('') || '<div class="empty">No messages yet.</div>';
  body.scrollTop = body.scrollHeight;
}

// ================================================================
// ADMIN SETTINGS
// ================================================================
async function showAdminSettings() {
  const j = await jget('api/shop.php?action=admin_pending');
  const pending = j.items || [];
  openModal(`
    <h2>Admin Settings</h2>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
      <button class="btn ghost small" data-tab="news">Post News</button>
      <button class="btn ghost small" data-tab="event">Add Event</button>
      <button class="btn ghost small" data-tab="review">Review Shop Apps (${pending.length})</button>
      <button class="btn ghost small" data-tab="logs">View Logs</button>
    </div>
    <div id="adminBody"></div>
  `);
  $$('[data-tab]').forEach(b => b.addEventListener('click', () => adminTab(b.dataset.tab, pending)));
  adminTab('news', pending);
}

function adminTab(tab, pending) {
  const body = $('#adminBody');
  if (tab === 'news') {
    body.innerHTML = `
      <form id="anForm" enctype="multipart/form-data">
        <label>Title</label><input name="title" required>
        <label>Content</label><textarea name="content" required></textarea>
        <label>Image (optional)</label><input type="file" name="image" accept="image/*">
        <button class="btn primary" style="margin-top:10px" type="submit">Publish News</button>
      </form>`;
    $('#anForm').addEventListener('submit', async e => {
      e.preventDefault();
      const r = await upload('api/news.php?action=create', new FormData(e.target));
      toast(r.message, !r.success);
      if (r.success) e.target.reset();
    });
  } else if (tab === 'event') {
    body.innerHTML = `
      <form id="aeForm">
        <label>Event Name</label><input name="event_name" required>
        <label>Description</label><textarea name="description" required></textarea>
        <label>Link (optional)</label><input name="link">
        <label>Date & Time</label><input name="event_datetime" type="datetime-local" required>
        <button class="btn primary" style="margin-top:10px" type="submit">Save Event</button>
      </form>`;
    $('#aeForm').addEventListener('submit', async e => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target).entries());
      const r = await jpost('api/events.php?action=create', body);
      toast(r.message, !r.success);
      if (r.success) e.target.reset();
    });
  } else if (tab === 'review') {
    body.innerHTML = pending.length ? pending.map(a => `
      <div class="card" style="margin-bottom:10px">
        <strong>${esc(a.app_name)}</strong>
        <div class="hint">By ${esc(a.developer_name)} · ${esc(a.developer_email)}</div>
        <p>${esc(a.description)}</p>
        ${a.app_link ? `<div><a href="${esc(a.app_link)}" target="_blank">Open link</a></div>` : ''}
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
          ${(a.images||[]).map(i => `<img src="${i}" style="width:80px;height:80px;object-fit:cover;border-radius:8px">`).join('')}
        </div>
        <div style="margin-top:10px">
          <button class="btn success small" onclick="__approveApp(${a.id})">Approve & Publish</button>
          <button class="btn danger small"  onclick="__rejectApp(${a.id})">Reject</button>
        </div>
      </div>
    `).join('') : '<div class="hint">No apps awaiting approval.</div>';
    window.__approveApp = async (id) => {
      const r = await jget('api/shop.php?action=admin_approve&id='+id);
      toast(r.success?'Approved':'Error', !r.success);
      showAdminSettings();
    };
    window.__rejectApp = async (id) => {
      const reason = prompt('Reason for rejection?','Does not meet quality guidelines');
      if (reason == null) return;
      const r = await jpost('api/shop.php?action=admin_reject', { id, reason });
      toast(r.success?'Rejected':'Error', !r.success);
      showAdminSettings();
    };
  } else if (tab === 'logs') {
    body.innerHTML = `<div class="hint">Login and activity logs are stored in the
      <code>login_logs</code> table in phpMyAdmin. Use the SQL panel there to view/export.</div>`;
  }
}
