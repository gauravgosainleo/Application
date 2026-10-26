/* G VI Fan Hub service worker - makes the app installable and loads the shell offline.
   Bump VERSION after uploading new files so every device picks up the update. */
const VERSION = 'gvi-v2.1.0';
const SHELL = [
  './',
  'index.html',
  'offline.html',
  'manifest.webmanifest',
  'assets/css/app.css',
  'assets/css/gvi-fx.css',
  'assets/css/gvi-app.css',
  'assets/js/app.js',
  'assets/js/shell.js',
  'assets/js/core/api.js',
  'assets/js/core/ui.js',
  'assets/js/core/icons.js',
  'assets/js/core/router.js',
  'assets/js/core/state.js',
  'assets/js/core/gvi-motion.js',
  'assets/js/core/gvi-background.js',
  'assets/js/core/gvi-nav.js',
  'assets/js/core/gvi-countdown.js',
  'assets/js/core/gvi-ui.js',
  'assets/js/pages/auth.js',
  'assets/js/pages/home.js',
  'assets/js/pages/countdown.js',
  'assets/img/logo.svg',
  'assets/img/hero.svg',
  'assets/img/icons/icon-192.png',
  'assets/fonts/inter-latin.woff2',
  'assets/fonts/bebas-neue-latin.woff2',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION)
      .then((cache) => Promise.all(SHELL.map((url) => cache.add(url).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  // Never cache API calls, uploads, the installer, admin or cron.
  if (/\/(api|admin|cron|uploads)\//.test(url.pathname) || url.pathname.endsWith('install.php')) return;

  if (req.mode === 'navigate') {
    // Only the app itself refreshes the cached shell; other pages (e.g. privacy.php) are cached under their own URL.
    const scope = new URL(self.registration.scope).pathname;
    const isShell = url.pathname === scope || url.pathname === scope + 'index.html';
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(VERSION).then((c) => c.put(isShell ? 'index.html' : req, copy));
          }
          return res;
        })
        .catch(() => (isShell ? caches.match('index.html') : caches.match(req))
          .then((r) => r || caches.match('offline.html')))
    );
    return;
  }

  const isStatic = /\.(png|jpg|jpeg|webp|gif|svg|woff2|ico)$/.test(url.pathname);
  if (isStatic) {
    // Cache first for images & fonts.
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(req, copy));
        }
        return res;
      }))
    );
    return;
  }

  // Network first for code (always fresh when online, cached copy offline).
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() => caches.match(req))
  );
});
