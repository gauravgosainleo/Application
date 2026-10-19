// Home dashboard: release countdown hero + latest activity.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { esc, $, icon, avatar, levelPill, timeAgo, timeUntil, fmtDate, asset, countdownParts, pad, openModal, youtubeEmbed, errorState, userName } from '../core/ui.js';
import { downloadIcs, releaseDate } from './countdown.js';

const TILES = [
  ['#/countdown', 'clock', 'Countdown', 'Days until launch'],
  ['#/news', 'news', 'News', 'Latest from trusted sources'],
  ['#/trailers', 'film', 'Trailers', 'Watch every trailer'],
  ['#/map', 'map', 'Leonida Map', 'Pin places & notes'],
  ['#/friends', 'chat', 'Friends & Chat', 'Chat and groups'],
  ['#/status', 'status', 'Status', 'Stories & who is online'],
  ['#/discussions', 'discuss', 'Discussions', 'Theories & articles'],
  ['#/catalogue', 'grid', 'Catalogue', 'Vehicles, weapons, gear'],
  ['#/gallery', 'image', 'Gallery', 'Photos & videos'],
  ['#/polls', 'poll', 'Polls', 'Vote & earn XP'],
  ['#/events', 'trophy', 'Events & Rewards', 'Join, claim, level up'],
];

export async function render({ el }) {
  const cfg = state.config || {};
  const me = state.me;
  const target = releaseDate(cfg.release_at);
  let dismissed = false;
  try { dismissed = localStorage.getItem('gvi_dismiss_announcement') === (cfg.announcement || ''); } catch (e) { /* storage blocked */ }

  el.innerHTML = `
    ${cfg.announcement && !dismissed ? `<div class="announcement">${icon('bell')}<span>${esc(cfg.announcement)}</span><button class="icon-btn" data-act="dismiss" aria-label="Dismiss">${icon('x')}</button></div>` : ''}
    <section class="hero-card">
      <div class="hero-bg" style="background-image:url('assets/img/hero.svg')"></div>
      <div class="hero-inner">
        <div class="hero-left">
          <span class="eyebrow">${icon('sparkles')} Welcome back, ${esc(me.name.split(' ')[0])}</span>
          <h2 class="hero-game">${esc(cfg.release_title || 'Grand Theft Auto VI')}</h2>
          <p class="hero-date">${icon('calendar')} ${esc(target.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))} · ${esc(cfg.release_platforms || '')}</p>
          <div class="countdown big" aria-live="off"></div>
          <div class="hero-actions">
            <button class="btn btn-primary" data-act="trailer">${icon('play')} Watch latest trailer</button>
            <button class="btn btn-glass" data-act="ics">${icon('calendar')} Add to calendar</button>
            <a class="btn btn-glass" href="#/countdown">${icon('clock')} Full countdown</a>
          </div>
        </div>
      </div>
    </section>
    <section class="player-strip card">
      <a href="#/profile" class="ps-me">${avatar(me, 'md')}<div><strong>${esc(me.name)}</strong><span class="muted small">${esc(me.level.rank)}</span></div></a>
      <div class="ps-xp">
        <div class="row-between small">${levelPill(me.level.level)}<span class="muted">${me.level.xp.toLocaleString()} / ${me.level.next_xp.toLocaleString()} XP</span></div>
        <div class="xp-bar"><span style="width:${Math.round(me.level.progress * 100)}%"></span></div>
      </div>
      <div class="ps-stats">
        <div><b>🔥 ${me.login_streak || 1}</b><span>day streak</span></div>
        <div><b class="online-count">–</b><span>friends online</span></div>
        <div><b class="status-count">–</b><span>new statuses</span></div>
      </div>
    </section>
    <div class="home-body"><div class="loader"><span class="spinner lg"></span></div></div>`;

  // Countdown ticker
  const cdBox = $('.countdown', el);
  const tick = () => {
    const p = countdownParts(target);
    cdBox.innerHTML = p.done
      ? `<div class="cd-launched">${icon('fire')} OUT NOW — welcome to Leonida!</div>`
      : [['Days', p.days], ['Hours', p.hours], ['Minutes', p.minutes], ['Seconds', p.seconds]]
          .map(([l, v], i) => `<div class="cd-unit"><div class="cd-num">${i === 0 ? v : pad(v)}</div><div class="cd-lbl">${l}</div></div>`).join('<div class="cd-sep">:</div>');
  };
  tick();
  const timer = setInterval(tick, 1000);

  el.addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'dismiss') {
      try { localStorage.setItem('gvi_dismiss_announcement', cfg.announcement || ''); } catch (err) { /* ignore */ }
      $('.announcement', el).remove();
    } else if (act === 'ics') {
      downloadIcs(cfg, target);
    }
  });

  const bodyEl = $('.home-body', el);
  let data;
  try {
    data = await api.get('app/home');
  } catch (err) {
    bodyEl.innerHTML = '';
    bodyEl.appendChild(errorState(err, () => render({ el })));
    return () => clearInterval(timer);
  }

  $('.online-count', el).textContent = data.online_friends.length;
  $('.status-count', el).textContent = data.status_users;
  const trailer = data.trailer;
  $('[data-act="trailer"]', el).addEventListener('click', () => {
    if (!trailer) return (location.hash = '#/trailers');
    openModal({ title: trailer.title, size: 'xl', className: 'video-modal', body: trailer.youtube_id ? youtubeEmbed(trailer.youtube_id) : `<div class="video-frame"><video src="${esc(asset(trailer.video_url))}" controls autoplay></video></div>` });
  });

  bodyEl.innerHTML = `
    <div class="home-grid">
      <div class="home-main">
        <section class="section">
          <div class="section-head"><h2>${icon('news')} Latest news</h2><a href="#/news" class="see-all">All news ${icon('chevronRight')}</a></div>
          <div class="news-strip">${data.news.map(newsMini).join('') || '<p class="muted">No news yet.</p>'}</div>
        </section>
        ${trailer ? `
        <section class="section">
          <div class="section-head"><h2>${icon('film')} Featured trailer</h2><a href="#/trailers" class="see-all">All trailers ${icon('chevronRight')}</a></div>
          <button class="feature-trailer" data-act="trailer2">
            <img src="${esc(trailer.thumbnail)}" alt="" loading="lazy">
            <span class="play-badge">${icon('play')}</span>
            <span class="ft-meta"><strong>${esc(trailer.title)}</strong><small>${esc(fmtDate(trailer.released_on))}</small></span>
          </button>
        </section>` : ''}
        <section class="section">
          <div class="section-head"><h2>${icon('discuss')} Trending discussions</h2><a href="#/discussions" class="see-all">Join in ${icon('chevronRight')}</a></div>
          <div class="list-card card">${data.discussions.map((d) => `
            <a class="list-row" href="#/discussions/${d.id}">
              ${avatar(d.author, 'sm')}
              <div class="lr-main"><strong>${esc(d.title)}</strong><span class="muted small">${esc(d.author.name)} · ${esc(d.category)} · ${timeAgo(d.created_at)}</span></div>
              <span class="lr-meta">${icon('heart')} ${d.likes_count} ${icon('discuss')} ${d.comments_count}</span>
            </a>`).join('') || `<div class="empty small"><p>No discussions yet — <a href="#/discussions?new=1">start the first one</a>.</p></div>`}</div>
        </section>
      </div>
      <aside class="home-side">
        ${data.poll ? `
        <section class="card side-card poll-teaser">
          <div class="side-title">${icon('poll')} Poll · +10 XP</div>
          <p class="pt-q">${esc(data.poll.question)}</p>
          <a class="btn btn-primary btn-block" href="#/polls">Vote now${data.open_polls > 1 ? ` (${data.open_polls} open)` : ''}</a>
        </section>` : ''}
        <section class="card side-card">
          <div class="side-title">${icon('calendar')} Upcoming events <a href="#/events" class="see-all">${icon('chevronRight')}</a></div>
          ${data.events.map((ev) => `
            <a class="event-mini" href="#/events">
              <span class="em-date"><b>${new Date(ev.starts_at).getDate()}</b><small>${new Date(ev.starts_at).toLocaleString(undefined, { month: 'short' })}</small></span>
              <span class="em-main"><strong>${esc(ev.title)}</strong><small class="muted">${new Date(ev.starts_at) > new Date() ? 'Starts ' + timeUntil(ev.starts_at) : '<span class="live-dot"></span> Live now'} · ${ev.participants} joined${ev.reward_xp ? ` · +${ev.reward_xp} XP` : ''}</small></span>
              ${ev.joined ? `<span class="chip chip-ok">${icon('check')} Joined</span>` : ''}
            </a>`).join('') || '<p class="muted small">No upcoming events.</p>'}
        </section>
        <section class="card side-card">
          <div class="side-title">${icon('users')} Friends online <a href="#/friends?tab=friends" class="see-all">${icon('chevronRight')}</a></div>
          ${data.online_friends.length ? `<div class="online-list">${data.online_friends.slice(0, 8).map((f) => `
            <a class="online-row" href="#/profile/${f.id}">${avatar(f, 'sm')}<span><strong>${esc(f.name)}</strong><small class="muted">${esc(f.presence_text || f.presence)}</small></span></a>`).join('')}</div>`
            : `<p class="muted small">No friends online right now. <a href="#/friends?tab=find">Find players</a></p>`}
        </section>
      </aside>
    </div>
    <section class="section">
      <div class="section-head"><h2>${icon('grid')} Explore</h2></div>
      <div class="tiles">${TILES.map(([href, ic, title, sub]) => `<a class="tile" href="${href}"><span class="tile-icon">${icon(ic)}</span><strong>${title}</strong><small>${sub}</small></a>`).join('')}</div>
    </section>`;

  const ft = $('[data-act="trailer2"]', bodyEl);
  if (ft) ft.addEventListener('click', () => $('[data-act="trailer"]', el).click());

  return () => clearInterval(timer);
}

function newsMini(n) {
  return `<a class="news-mini" href="#/news/${n.id}">
    <span class="nm-img">${n.image_url ? `<img src="${esc(asset(n.image_url))}" alt="" loading="lazy" onerror="this.remove()">` : ''}<span class="nc-fallback fb-${n.id % 4}"><b>${esc(n.source_name)}</b></span></span>
    <span class="nm-body"><span class="source-chip">${esc(n.source_name)}</span><strong>${esc(n.title)}</strong><small class="muted">${timeAgo(n.published_at)}</small></span>
  </a>`;
}
export { newsMini, userName };
