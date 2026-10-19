// Full-screen release countdown with hype meter and road-to-launch timeline.
import { state } from '../core/state.js';
import { esc, $, icon, countdownParts, pad, shareLink, appLink, toast } from '../core/ui.js';

const MILESTONES = [
  ['2023-12-04', 'Trailer 1 premieres', 'Rockstar reveals Leonida and Lucia.'],
  ['2025-05-06', 'Trailer 2', 'Jason and Lucia take centre stage.'],
  ['2026-06-18', 'Official cover art', 'The cover art is revealed.'],
  ['2026-08-27', 'An Extended Look', 'A longer look at the world of GTA VI.'],
  ['2026-10-15', 'Launch Trailer', 'The final trailer before release.'],
];

/** Parses the configured release time. Without an offset it means "midnight in the viewer's time zone". */
export function releaseDate(value) {
  const d = new Date(value || '2026-11-19T00:00:00');
  return isNaN(d) ? new Date(2026, 10, 19) : d;
}

/** Saves a file - uses the Android app bridge when running inside the APK. */
export function saveFile(name, mime, text) {
  if (window.GVIAndroid && typeof window.GVIAndroid.saveFile === 'function') {
    const b64 = btoa(unescape(encodeURIComponent(text)));
    window.GVIAndroid.saveFile(name, mime, b64);
    return;
  }
  const blob = new Blob([text], { type: mime });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

export function downloadIcs(cfg, target) {
  const y = target.getFullYear();
  const m = pad(target.getMonth() + 1);
  const d = pad(target.getDate());
  const next = new Date(target.getTime() + 86400000);
  const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z');
  const title = `${cfg.release_title || 'Grand Theft Auto VI'} launch day`;
  const android = window.GVIAndroid;
  if (android && typeof android.addToCalendar === 'function') {
    // Android app: open the calendar app with an all-day event (all-day times are midnight UTC).
    const begin = Date.UTC(y, target.getMonth(), target.getDate());
    if (android.addToCalendar(title, `${cfg.release_platforms || ''} - tracked with G VI Fan Hub`.replace(/^ - /, ''), begin, begin + 86400000, true)) return;
  }
  const ics = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//G VI Fan Hub//Countdown//EN', 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT',
    `UID:gvi-launch-${y}${m}${d}@gvifanhub`, `DTSTAMP:${stamp}`,
    `DTSTART;VALUE=DATE:${y}${m}${d}`, `DTEND;VALUE=DATE:${next.getFullYear()}${pad(next.getMonth() + 1)}${pad(next.getDate())}`,
    `SUMMARY:${title}`, `DESCRIPTION:${(cfg.release_platforms || '').replace(/[,;]/g, ' ')} - tracked with G VI Fan Hub`,
    'BEGIN:VALARM', 'TRIGGER:-PT12H', 'ACTION:DISPLAY', `DESCRIPTION:${title} is tomorrow!`, 'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR',
  ].join('\r\n');
  saveFile('gta-vi-launch.ics', 'text/calendar', ics);
  toast('Calendar file downloaded — open it to add the reminder.', 'success');
}

export async function render({ el }) {
  const cfg = state.config || {};
  const target = releaseDate(cfg.release_at);
  const localMode = !/(Z|[+-]\d{2}:\d{2})$/.test(cfg.release_at || '');
  const start = new Date('2023-12-04T23:00:00Z');

  el.innerHTML = `
    <section class="countdown-page">
      <div class="cdp-bg" style="background-image:url('assets/img/hero.svg')"></div>
      <div class="cdp-inner">
        <span class="eyebrow">${icon('clock')} Countdown to launch</span>
        <h2 class="cdp-title">${esc(cfg.release_title || 'Grand Theft Auto VI')}</h2>
        <p class="cdp-date">${esc(target.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}
          ${localMode ? '· midnight in your time zone' : '· ' + esc(target.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }))}</p>
        <div class="countdown huge"></div>
        <p class="cdp-platforms">${esc(cfg.release_platforms || '')}</p>
        <div class="hype">
          <div class="row-between small"><span>${icon('fire')} Hype meter</span><span class="hype-pct"></span></div>
          <div class="xp-bar hype-bar"><span></span></div>
          <div class="muted tiny">Time elapsed since Trailer 1</div>
        </div>
        <div class="hero-actions center">
          <button class="btn btn-primary" data-act="ics">${icon('calendar')} Add to calendar</button>
          <button class="btn btn-glass" data-act="share">${icon('share')} Share countdown</button>
          <a class="btn btn-glass" href="#/trailers">${icon('film')} Trailers</a>
        </div>
      </div>
    </section>
    <section class="section">
      <div class="section-head"><h2>${icon('compass')} Road to launch</h2></div>
      <ol class="timeline">
        ${MILESTONES.map(([date, title, text]) => `<li class="done"><span class="tl-date">${esc(new Date(date + 'T12:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }))}</span><strong>${esc(title)}</strong><p>${esc(text)}</p></li>`).join('')}
        <li class="${Date.now() >= target ? 'done' : 'next'}"><span class="tl-date">${esc(target.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }))}</span><strong>Launch day</strong><p>${esc(cfg.release_title || 'GTA VI')} arrives on ${esc(cfg.release_platforms || 'consoles')}.</p></li>
      </ol>
    </section>`;

  const box = $('.countdown', el);
  let last = {};
  const tick = () => {
    const p = countdownParts(target);
    if (p.done) {
      box.innerHTML = `<div class="cd-launched">${icon('fire')} OUT NOW!</div>`;
    } else {
      const parts = [['days', p.days, 'Days'], ['hours', p.hours, 'Hours'], ['minutes', p.minutes, 'Minutes'], ['seconds', p.seconds, 'Seconds']];
      box.innerHTML = parts.map(([k, v, l], i) => `<div class="cd-unit ${last[k] !== v ? 'flip' : ''}"><div class="cd-num">${i === 0 ? v : pad(v)}</div><div class="cd-lbl">${l}</div></div>`).join('<div class="cd-sep">:</div>');
      last = { days: p.days, hours: p.hours, minutes: p.minutes, seconds: p.seconds };
    }
    const pct = Math.min(100, Math.max(0, ((Date.now() - start) / (target - start)) * 100));
    $('.hype-bar span', el).style.width = pct.toFixed(2) + '%';
    $('.hype-pct', el).textContent = pct.toFixed(4) + '%';
  };
  tick();
  const timer = setInterval(tick, 1000);

  el.addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'ics') downloadIcs(cfg, target);
    if (act === 'share') {
      const p = countdownParts(target);
      shareLink(`${p.days} days until ${cfg.release_title || 'GTA VI'}! Counting down on G VI Fan Hub`, appLink('#/countdown'));
    }
  });
  return () => clearInterval(timer);
}
