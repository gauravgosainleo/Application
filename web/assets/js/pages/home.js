// Home dashboard: launch hero with flip countdown, player strip, latest activity and the Explore grid.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { esc, $, icon, avatar, timeAgo, timeUntil, fmtDate, asset, countdownParts, openModal, youtubeEmbed, errorState, userName } from '../core/ui.js';
import { heroFx } from '../core/gvi-background.js';
import { mountCountdown } from '../core/gvi-countdown.js';
import { glare, ringAvatar, dateBlock, sectionHead, newsCard, avatarGrad } from '../core/gvi-ui.js';
import { downloadIcs, releaseDate } from './countdown.js';
import { NAV } from '../shell.js';

export async function render({ el }) {
  const cfg = state.config || {};
  const me = state.me;
  const target = releaseDate(cfg.release_at);
  const lv = me.level;
  let dismissed = false;
  try { dismissed = localStorage.getItem('gvi_dismiss_announcement') === (cfg.announcement || ''); } catch (e) { /* storage blocked */ }

  el.innerHTML = `
    ${cfg.announcement && !dismissed ? `<div class="gvi-announce">${icon('bell')}<span>${esc(cfg.announcement)}</span><button class="gvi-icon-btn" data-act="dismiss" aria-label="Dismiss">${icon('x')}</button></div>` : ''}
    <section class="gvi-hero">
      <div class="gvi-hero-img"></div>
      ${heroFx()}
      <div class="gvi-hero-shade"></div>
      <div class="gvi-hero-body">
        <span class="gvi-eyebrow">${icon('sparkles')}Welcome back, ${esc(me.name.split(' ')[0])}</span>
        <h2 class="gvi-hero-title">${esc(cfg.release_title || 'Grand Theft Auto VI')}</h2>
        <p class="gvi-hero-line">${icon('calendar')}<span>${esc(target.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}</span>${cfg.release_platforms ? `<span class="sep">·</span><span>${esc(cfg.release_platforms)}</span>` : ''}</p>
        <div class="home-cd" aria-live="off"></div>
        <div class="gvi-hero-actions">
          <button class="gvi-btn" data-act="trailer">${icon('play')}Watch launch trailer</button>
          <button class="gvi-btn ghost" data-act="ics">${icon('calendar')}Add to calendar</button>
          <a class="gvi-btn ghost" href="#/countdown">${icon('clock')}Full countdown</a>
        </div>
      </div>
    </section>
    <section class="gvi-player-strip gvi-panel">
      <a href="#/profile" class="ps-me">${ringAvatar(me, 48)}<span><strong>${esc(me.name)}</strong><small>${esc(me.title || lv.rank)}</small></span></a>
      <div class="ps-xp">
        <div class="ps-xp-row"><span class="gvi-level">LVL ${lv.level}</span><span>${lv.xp.toLocaleString()} / ${lv.next_xp.toLocaleString()} XP</span></div>
        <div class="gvi-xp"><span style="width:${Math.round((lv.progress || 0) * 100)}%"></span></div>
      </div>
      <div class="ps-stats">
        <div><b>${icon('fire')}${me.login_streak || 1}</b><span>day streak</span></div>
        <div><b class="online-count">–</b><span>friends online</span></div>
        <div><b class="status-count">–</b><span>new statuses</span></div>
      </div>
    </section>
    <div class="home-body"><div class="loader"><span class="spinner lg"></span></div></div>`;

  const cdBox = $('.home-cd', el);
  let stopCd = () => {};
  if (countdownParts(target).done) cdBox.innerHTML = `<div class="gvi-out-now">${icon('fire')} OUT NOW — welcome to Leonida!</div>`;
  else stopCd = mountCountdown(cdBox, target);

  el.addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'dismiss') {
      try { localStorage.setItem('gvi_dismiss_announcement', cfg.announcement || ''); } catch (err) { /* ignore */ }
      $('.gvi-announce', el).remove();
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
    return stopCd;
  }

  $('.online-count', el).textContent = data.online_friends.length;
  $('.status-count', el).textContent = data.status_users;
  const trailer = data.trailer;
  const openTrailer = () => {
    if (!trailer) return (location.hash = '#/trailers');
    openModal({ size: 'xl', className: 'video-modal gvi-video-modal', body: trailer.youtube_id ? youtubeEmbed(trailer.youtube_id) : `<div class="video-frame"><video src="${esc(asset(trailer.video_url))}" controls autoplay playsinline></video></div>` });
  };
  $('[data-act="trailer"]', el).addEventListener('click', openTrailer);

  const live = (ev) => new Date(ev.starts_at) <= new Date();
  bodyEl.innerHTML = `
    <div class="gvi-home-grid">
      <div class="gvi-home-main">
        <section>
          ${sectionHead('news', 'Latest news', '#/news', 'All news')}
          <div class="gvi-news-grid">${data.news.slice(0, 3).map((n) => newsCard(n, 'home')).join('') || '<p class="muted">No news yet.</p>'}</div>
        </section>
        ${trailer ? `
        <section>
          ${sectionHead('film', 'Featured trailer', '#/trailers', 'All trailers')}
          <button class="gvi-feature-trailer" data-act="trailer2" data-tilt="card" style="${trailer.thumbnail ? `--img:url('${esc(asset(trailer.thumbnail))}')` : ''}">
            <span class="ft-shade"></span>
            <span class="gvi-play">${icon('play')}</span>
            <span class="ft-meta"><strong>${esc(trailer.title)}</strong><small>${esc(fmtDate(trailer.released_on))}</small></span>
            ${glare}
          </button>
        </section>` : ''}
        <section>
          ${sectionHead('discuss', 'Trending discussions', '#/discussions', 'Join in')}
          <div class="gvi-list gvi-panel flush">${data.discussions.map((d) => `
            <a class="gvi-list-row" href="#/discussions/${d.id}">
              ${avatar(d.author, 'sm', { presence: false })}
              <span class="lr-main"><strong>${esc(d.title)}</strong><small>${esc(d.author.name)} · ${esc(d.category)} · ${timeAgo(d.created_at)}</small></span>
              <span class="lr-meta">${icon('heart')}${d.likes_count}${icon('discuss')}${d.comments_count}</span>
            </a>`).join('') || `<div class="empty small"><p>No discussions yet — <a href="#/discussions?new=1">start the first one</a>.</p></div>`}</div>
        </section>
      </div>
      <aside class="gvi-home-side">
        ${data.poll ? `
        <section class="gvi-panel accent gvi-poll-teaser" data-tilt="card">
          <div class="side-title">${icon('poll')}Poll · +10 XP</div>
          <p>${esc(data.poll.question)}</p>
          <a class="gvi-btn block" href="#/polls">Vote now${data.open_polls > 1 ? ` (${data.open_polls} open)` : ''}</a>
          ${glare}
        </section>` : ''}
        <section class="gvi-panel">
          <div class="side-title">${icon('calendar')}Upcoming events<a href="#/events" class="side-more" aria-label="All events">${icon('chevronRight')}</a></div>
          <div class="side-list">${data.events.map((ev) => `
            <a class="gvi-event-mini" href="#/events">
              ${dateBlock(ev.starts_at)}
              <span class="em-main"><strong>${esc(ev.title)}</strong><small>${live(ev) ? '<span class="gvi-live"></span> Live now' : 'Starts ' + timeUntil(ev.starts_at)} · ${ev.participants} joined${ev.reward_xp ? ` · +${ev.reward_xp} XP` : ''}</small></span>
              ${ev.joined ? `<span class="gvi-joined">${icon('check')}</span>` : ''}
            </a>`).join('') || '<p class="muted small">No upcoming events.</p>'}</div>
        </section>
        <section class="gvi-panel">
          <div class="side-title">${icon('users')}Friends online<a href="#/friends?tab=friends" class="side-more" aria-label="All friends">${icon('chevronRight')}</a></div>
          ${data.online_friends.length ? `<div class="side-list">${data.online_friends.slice(0, 8).map((f) => `
            <a class="gvi-online-row" href="#/profile/${f.id}">${avatar(f, 'sm')}<span><strong>${esc(f.name)}</strong><small>${esc(f.presence_text || presenceWord(f.presence))}</small></span></a>`).join('')}</div>`
            : `<p class="muted small">No friends online right now. <a href="#/friends?tab=find">Find players</a></p>`}
        </section>
      </aside>
    </div>
    <section class="gvi-explore">
      <h2 class="gvi-section-title">${icon('grid')}Explore</h2>
      <div class="gvi-tiles">${NAV.filter((n) => n.key !== 'home').map((n) => `<a class="gvi-tile" href="#/${n.key}" data-tilt="card"><span class="gvi-tile-icon">${icon(n.icon)}</span><strong>${esc(n.label)}</strong><small>${esc(n.sub)}</small>${glare}</a>`).join('')}</div>
    </section>`;

  const ft = $('[data-act="trailer2"]', bodyEl);
  if (ft) ft.addEventListener('click', openTrailer);

  return stopCd;
}

function presenceWord(p) {
  return { online: 'Online', away: 'Away', busy: 'Busy' }[p] || 'Online';
}

// Kept for other modules that import these helpers.
export { newsCard as newsMini, userName, avatarGrad };
