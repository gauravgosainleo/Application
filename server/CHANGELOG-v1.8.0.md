# v1.8.0 — Instant phone notifications and the "please update" prompt

Goes with **Android app 1.8.0** (version code 180), the first release for
Google Play.

## What residents get

- **A phone notification within seconds** whenever something becomes public:
  a notice, an event, a new photo album, new photos or videos, a poll, a
  society ad, an AOA update, a new vendor, a change to the SPOC contacts, or
  new finance entries. Tapping it opens that page in the app.
- **A popup asking them to update** when the app on their phone is out of
  date, every time they open it, with a button that opens Google Play.
  Up-to-date apps never see it.

## Why the server had to change

Until now the app found out about new content by checking
`api/app_updates.php` in the background. Android runs that kind of check every
15 minutes at best, and much later on battery-saving phones (Xiaomi, Oppo,
Vivo, Realme, many Samsungs), sometimes not at all once the app has been swiped
away. So notifications were late or never arrived.

From this release the server pushes each item the moment it is published,
through Firebase Cloud Messaging (FCM). The background check stays as a safety
net, and the app shows each item once, whichever route delivers it first.

The installs residents have today (v1.0, installed from an APK file) have no
update code of their own, and Google Play cannot update them in place because
they were signed with a different key. They display these server pages, so the
server shows them the popup instead.

## What changed

### New

- `includes/app_feed.php` — one list of "what became public since …", used by
  both the background check and push, so the two never disagree. Adds two
  kinds the old feed did not cover: **SPOC contact changes** and **finance
  entries**. Photo uploads, SPOC edits and finance entries are **grouped**: a
  resident uploading 20 photos one by one produces one notification ("20 new
  photos in the gallery") that updates in place, not 20.
- `includes/app_push.php` — FCM HTTP v1 sender (OAuth with the service account
  key, signed with OpenSSL; no Composer or SDK needed), and the dispatcher.
  One FCM topic for the installation, so no device tokens are stored. Each item
  is pushed exactly once whichever trigger runs it: right **after any form POST
  on a module page has been answered** (so whoever publishes never waits on
  Google), after a phone polls (at most once a minute), and from the optional
  cron job. Failures are retried and shown to the admin.
- `includes/app_update_prompt.php` — recognises the client (new app, old app
  install, or a browser), decides whether to ask for an update, and renders
  the popup for old installs.
- `api/app_config.php` — what the app reads when it opens: the update settings
  and the Firebase settings for the app.
- `modules/mobile_app.php` — **Admin > Mobile app**: set up push by uploading
  two files from Firebase, check the connection, choose which kinds of content
  notify residents, see what was sent, and control the update prompt.
- `cron/push_updates.php` — optional once-a-minute safety net.
- `tests/app_push_test.php` — 100 checks (see Tests).

### Modified

- `api/app_updates.php` — uses the shared feed; items gain a `tag`; the reply
  carries the Firebase settings, so installed 1.8.0 apps switch to instant
  notifications in the background as soon as push is set up; each poll can
  trigger a dispatch. Old apps that poll it keep working unchanged.
- `includes/layout.php` — queues the push after a POST; shows the popup to old
  installs; adds "Mobile app" to the admin menu (Operations).
- `auth/login.php` — shows the popup to old installs on the sign-in page.
- `privacy.php` — new "Android app" section (FCM, Google Play update check,
  camera/location use).
- `docs/GOOGLE-PLAY-DATA-SAFETY.md` — the actual inventory of the 1.8.0 app.

### Deliberately unchanged

- **No database change.** No new tables or columns. Settings, the push log and
  the encrypted key live in a `mobile-app` folder inside the existing private
  directory (owner-only, outside the website, next to `master.key`).
- **`config/config.php` is not part of this release.** Keep your own.
- **Notification text stays generic** ("New society notice", "5 new photos in
  the gallery"). A push goes to every phone with the app, signed in or not, and
  is shown on lock screens, so titles, names and photos stay behind sign-in,
  exactly as the background check already did.
- Editing a notice, re-opening a closed poll or deleting something does not
  notify anyone. Only new public content does.

## Deploying

1. Upload these files over the existing installation (folders as shown):

   ```
   api/app_config.php              (new)
   api/app_updates.php
   auth/login.php
   cron/push_updates.php           (new)
   includes/app_feed.php           (new)
   includes/app_push.php           (new)
   includes/app_update_prompt.php  (new)
   includes/layout.php
   modules/mobile_app.php          (new)
   privacy.php
   tests/app_push_test.php         (new)
   docs/GOOGLE-PLAY-DATA-SAFETY.md
   CHANGELOG-v1.8.0.md             (new)
   ```

   Nothing else changes, and nothing breaks if push is never set up: phones
   then keep their background check.

2. Publish the app on Google Play (upload the `.aab`).

3. **Turn on instant notifications** (about 10 minutes, once):
   Admin > Mobile app > *Set up instant notifications* lists the steps. In
   short: create a free Firebase project, add an Android app with the package
   name `net.learninganddevelopment.uninav`, download `google-services.json`,
   generate a service account key, upload both files, press *Check
   connection*. No app rebuild is needed; phones pick it up by themselves.

4. **The update prompt needs nothing.** In the default *automatic* mode the
   server checks Google Play every few hours and starts asking old installs to
   update as soon as the listing is live. Admin > Mobile app shows the listing
   status and has *Check now*, *Always* and *Never*.

5. Optional: schedule the cron job next to the existing gym one:
   `* * * * * php /path/to/Application/cron/push_updates.php`

## Tests

- `tests/app_push_test.php` — **100/100** against MariaDB 10.11 with PHP 8.3:
  feed contents for every kind, no society content in any item, grouping,
  sealing and file permissions, validation of both Firebase files, JWT
  signature and claims, the OAuth/FCM exchange, token refresh, error handling,
  the dispatcher (exactly-once, cool-down, retries, switched-off kinds), and
  who sees the popup in each mode. Google is replaced by a fake transport.
- `tests/security_test.php` — 45/45, unchanged.
- End to end on PHP's built-in server: admin sign-in; publishing a notice
  through Settings returned normally, and the dispatch ran after the response
  and reached Google's token endpoint (rejected there only because the test
  key is not a real Firebase key); all 19 module pages return 200 with no PHP
  warnings; the popup appears only for the v1.0 app user agent and only when
  the prompt is active; uploads, the connection check, CSRF and validation
  messages on the admin page.

Not tested here: delivery to a real phone through a real Firebase project,
which needs your Firebase project. After step 3, publish a test notice and
check that a phone with the 1.8.0 app shows it within a few seconds.
