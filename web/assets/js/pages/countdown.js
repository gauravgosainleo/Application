// Full-screen release countdown with hype meter and road-to-launch timeline.
import { state } from '../core/state.js';
import { esc, $, icon, countdownParts, pad, shareLink, appLink, toast } from '../core/ui.js';
import { heroFx } from '../core/gvi-background.js';
import { mountCountdown, hypePercent } from '../core/gvi-countdown.js';

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
  const fmtShort = (d) => d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  const launched = Date.now() >= target;

  el.innerHTML = `
    <section class="gvi-cdp">
      <div class="gvi-cdp-bg"></div>
      ${heroFx()}
      <div class="gvi-cdp-shade"></div>
      <div class="gvi-cdp-body">
        <span class="gvi-eyebrow">${icon('clock')}Countdown to launch</span>
        <h2 class="gvi-cdp-title">${esc(cfg.release_title || 'Grand Theft Auto VI')}</h2>
        <p class="gvi-cdp-date">${esc(target.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}
          ${localMode ? '· midnight in your time zone' : '· ' + esc(target.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }))}</p>
        <div class="gvi-cdp-cd"></div>
        <p class="gvi-cdp-platforms">${esc(cfg.release_platforms || '')}</p>
        <div class="gvi-hype">
          <div class="gvi-hype-row"><span>${icon('fire')}Hype meter</span><span class="hype-pct"></span></div>
          <div class="gvi-hype-bar"><span></span></div>
          <small>Time elapsed since Trailer 1</small>
        </div>
        <div class="gvi-cdp-actions">
          <button class="gvi-btn" data-act="ics">${icon('calendar')}Add to calendar</button>
          <button class="gvi-btn ghost" data-act="share">${icon('share')}Share countdown</button>
          <a class="gvi-btn ghost" href="#/trailers">${icon('film')}Trailers</a>
        </div>
      </div>
    </section>
    <section class="gvi-road">
      <h2 class="gvi-section-title">${icon('compass')}Road to launch</h2>
      <ol class="gvi-timeline">
        ${MILESTONES.map(([date, title, text]) => `<li class="done"><span class="tl-date">${esc(fmtShort(new Date(date + 'T12:00:00')))}</span><strong>${esc(title)}</strong><p>${esc(text)}</p></li>`).join('')}
        <li class="${launched ? 'done' : 'next'}"><span class="tl-date">${esc(fmtShort(target))}</span><strong>Launch day</strong><p>${esc(cfg.release_title || 'GTA VI')} arrives on ${esc(cfg.release_platforms || 'consoles')}.</p></li>
      </ol>
    </section>`;

  const box = $('.gvi-cdp-cd', el);
  let stop = () => {};
  if (launched) box.innerHTML = `<div class="gvi-out-now">${icon('fire')} OUT NOW!</div>`;
  else stop = mountCountdown(box, target, { size: 'big' });

  const hype = () => {
    const pct = hypePercent(target.getTime());
    $('.gvi-hype-bar span', el).style.width = pct.toFixed(2) + '%';
    $('.hype-pct', el).textContent = pct.toFixed(4) + '%';
  };
  hype();
  const timer = setInterval(hype, 1000);

  el.addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'ics') downloadIcs(cfg, target);
    if (act === 'share') {
      const p = countdownParts(target);
      shareLink(`${p.days} days until ${cfg.release_title || 'GTA VI'}! Counting down on G VI Fan Hub`, appLink('#/countdown'));
    }
  });
  return () => {
    clearInterval(timer);
    stop();
  };
}
