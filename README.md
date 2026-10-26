# G VI Fan Hub

A fan companion app for **Grand Theft Auto VI** that runs on **desktop and Android**.
Players register with an email OTP, then get a countdown, news, trailers, an interactive map,
friends & chat, statuses, discussions, a catalogue, a gallery, polls, events and rewards.
An admin panel manages everything, including **automatic news collection** with admin approval.

| Part | Technology | Folder |
| --- | --- | --- |
| Website + installable app (PWA) | HTML, CSS, JavaScript (no build step) | `web/` |
| API / backend | PHP 7.4+ (8.x recommended) + MySQL | `web/api`, `web/app` |
| Admin panel | HTML, CSS, JavaScript | `web/admin` |
| Android app | Java (WebView wrapper with native share, calendar, downloads) | `android/` |

---

## Features

**Players**

- Register with name, email, password, confirm password and a **6-digit OTP sent by email**; log in after verifying.
- Forgot password (OTP by email) on the login page, and change password from **Profile → Password & security**.
- Optional 2-step login (OTP on every login), active sessions list with "log out other devices".
- Profile icon (top right) with profile picture, cover image, bio, platform, PSN / Xbox / Rockstar IDs, favourite character, presence (online / away / busy / invisible), level & XP, badges, titles, privacy settings and app theme.

The 11 sections:

1. **Countdown** to the release on **19 Nov 2026** (hype meter, timeline, add-to-calendar, share).
2. **News & articles** — every article shows its **source** with a link to the original.
3. **Trailers** — YouTube (or direct video) links, featured trailer on the home page.
4. **Interactive Leonida map** — drop markers where you want to go and write notes (private, friends or public).
5. **Friends** — friend requests, 1-to-1 chat and **group chats** (with photos and admin roles).
6. **Status** — 24-hour statuses (text or photo) from you and other players, with viewers.
7. **Discussions** — create articles, comment, reply, like and report.
8. **Catalogue** — vehicles, weapons, equipment… with stats and favourites.
9. **Photo & video gallery** — uploads (reviewed by admins), likes.
10. **Polls** — single or multiple choice, live results.
11. **Events & rewards** — join events, claim XP / badges / titles / profile frames, redeem codes.

**Admins** (`/admin/`, username `gg6admin`)

1. Block a player for **X days** (or permanently) with a reason; unblock any time. Blocked players see the reason and end date.
2. Publish news manually, or turn on **auto mode**: the app checks reliable GTA sources every **6, 12 or 24 hours** (your choice),
   puts new stories in an **approval queue**, and only approved stories become public — always with the source name and link.
3. Add / edit / remove trailers.
4. Delete discussion articles (and comments) and **ban the author** in the same step; review reports.
5. Manage the catalogue: categories, items, images and stats.
6. Create, edit and close polls.
7. Create events and rewards (XP, badges, titles, frames, redeem codes).
- Plus: dashboard, gallery moderation, broadcast notifications, site settings (release date, announcement), test email and admin password change.

---

## Deploying on Hostinger (FTP)

You need: the Hostinger database you already created (`u694536902_gta6`, user `u694536902_gta6`), its password, and FTP access.

### 1. Create the email address used for OTPs

hPanel → **Emails** → create a mailbox, e.g. `noreply@learninganddevelopment.net`, and note its password.

### 2. Edit `web/app/config.php`

Open it in any text editor and replace the two placeholders:

```php
'pass'     => 'PUT_YOUR_DB_PASSWORD_HERE',     // your MySQL database password
...
'password' => 'PUT_YOUR_EMAIL_PASSWORD_HERE',  // the mailbox password from step 1
```

The database name/user, SMTP host (`smtp.hostinger.com`, port 465, SSL) and sender address are already filled in.
If you used a different mailbox, change `username` and `from_email` too.

### 3. Upload the files

Upload **the contents of the `web/` folder** (not the folder itself) into the folder the site lives in —
`public_html/GTA6/App/` for the current site (or `public_html` itself to run it at the domain root)
(e.g. with FileZilla: host and username are in hPanel → **Files → FTP Accounts**, port 21).

- Make sure hidden files such as `.htaccess` are uploaded (FileZilla: *Server → Force showing hidden files*).
- Do not upload `app/config.local.php` if you created one for local testing.

After uploading, that folder should contain `index.html`, `install.php`, `admin/`, `api/`, `app/`, `assets/`, `cron/`, `downloads/`, `uploads/` …

### 4. Run the installer

Open `https://learninganddevelopment.net/GTA6/App/install.php`.
It checks PHP, the database connection and email settings, then creates all tables, the admin account
(username pre-filled as **gg6admin** – type your admin password) and starter content
(news sources, trailers, catalogue, events, rewards, polls).

**Delete `install.php` from the server afterwards.**

### 5. Turn on HTTPS

hPanel → **Security → SSL**: make sure the free SSL certificate is active and enable **Force HTTPS**.
HTTPS is required to install the app on phones/desktops and for secure logins.

### 6. Add the cron job (news auto mode, cleanup)

hPanel → **Advanced → Cron Jobs** → *Custom* → run **every hour**:

```
/usr/bin/php /home/u694536902/domains/learninganddevelopment.net/public_html/GTA6/App/cron/fetch-news.php
```

(The installer shows the exact path for your account.) The job only fetches news when the interval you picked
in the admin panel (6, 12 or 24 h) has passed. Without a cron job the site still checks on its own when people
use it, but the cron job makes it reliable.

### 7. Recommended PHP settings

hPanel → **Advanced → PHP Configuration**: choose **PHP 8.2 or newer**. For video uploads set
`upload_max_filesize` and `post_max_size` to at least **64M** (the included `.htaccess` tries to set this already).

### 8. First login

- Admin panel: `https://learninganddevelopment.net/GTA6/App/admin/` → username `gg6admin` and the password you typed in the installer.
  You can change it any time under **Admin account**. This repository is public, so never write the password in its files.
- Use **Settings → test email** in the admin panel to confirm OTP emails are delivered.
- Under **Settings → Privacy policy** enter the email address players should use for privacy questions and data
  requests. It appears on the Privacy Policy page (`https://learninganddevelopment.net/GTA6/App/privacy.php`);
  when empty, the OTP sender address is shown.
- Players open `https://learninganddevelopment.net/GTA6/App/` and register.

### 9. Updating to a new version

Update zips leave out `app/config.php` so your passwords are not overwritten.
**Extract the zip over the existing `GTA6/App/` folder — don't delete the folder first.**
The server needs to keep `app/config.php`, `app/storage/` (install lock and app key) and `uploads/` (player photos).

If you did start from an empty folder: upload `app/config.php` again with your database (and mailbox) password,
then open `install.php` once if it asks — the installer keeps all existing data.

---

## Desktop and Android apps

### Install from the browser (desktop & Android)

The site is a Progressive Web App:

- **Windows / macOS / Linux (Chrome or Edge):** click the install icon in the address bar, or the **Install app** item in the profile menu.
- **Android (Chrome):** ⋮ menu → *Install app / Add to Home screen*.
- **iPhone / iPad (Safari):** Share → *Add to Home Screen*.

### Android app (APK)

A ready-made APK is included at `web/downloads/gvi-fan-hub.apk`, so after uploading it is available at
`https://learninganddevelopment.net/GTA6/App/downloads/gvi-fan-hub.apk`. The login page (and the profile menu on Android phones)
links to it. Players allow *Install unknown apps* for their browser when Android asks.

The app opens `https://learninganddevelopment.net/GTA6/App/` and adds native features: the Android share sheet,
add-to-calendar for the launch date, file downloads, photo/video pickers, full-screen trailers, back gesture support
and an offline/retry screen. Because all content comes from the website, **website updates never need a new APK**.

#### Building the APK yourself

You only need this if you change the domain or the Android code.

**Option A – GitHub Actions (no installs needed):** on GitHub open **Actions → Android APK and AAB → Run workflow**
(optionally enter a different website address). When it finishes, download the `gvi-fan-hub-apk` artifact and
upload `gvi-fan-hub.apk` to the site's `downloads/` folder (`public_html/GTA6/App/downloads/`).

**Option B – your computer:** install [Android Studio](https://developer.android.com/studio) (or JDK 17+ and the Android SDK), then:

```bash
cd android
./gradlew assembleRelease                                         # uses https://learninganddevelopment.net/GTA6/App/
./gradlew assembleRelease -PappUrl=https://your-domain.com/       # or another address / sub-folder
# result: android/app/build/outputs/apk/release/app-release.apk
```

The default address is set in `android/app/build.gradle` (`appUrl`).

#### Signing with your own key (recommended before sharing widely / Google Play)

The included APK is signed with a temporary development key. To sign with your own permanent key:

```bash
keytool -genkeypair -v -keystore gvi-release.jks -alias gvi -keyalg RSA -keysize 4096 -validity 10000
```

- **Local builds:** copy `gvi-release.jks` into `android/` and create `android/keystore.properties`:
  ```
  storeFile=gvi-release.jks
  storePassword=YOUR_STORE_PASSWORD
  keyAlias=gvi
  keyPassword=YOUR_KEY_PASSWORD
  ```
- **GitHub Actions:** add repository secrets `ANDROID_KEYSTORE_BASE64` (output of `base64 -w0 gvi-release.jks`),
  `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS` and `ANDROID_KEY_PASSWORD`.

Keep the keystore and passwords safe and never commit them (they are git-ignored). Players who installed an APK
signed with a different key must uninstall it once before installing the newly signed one.

#### Publishing on Google Play (.aab)

Google Play takes an Android App Bundle signed with your **upload key** — the key from the step above, never the
development key. The build refuses to make a bundle until `android/keystore.properties` exists.

```bash
cd android
./gradlew bundleRelease
# result: android/app/build/outputs/bundle/release/app-release.aab
```

With the four signing secrets set, **Actions → Android APK and AAB → Run workflow** also produces it as the
`gvi-fan-hub-aab` artifact.

In the [Play Console](https://play.google.com/console):

1. **Create app**, keep **Play App Signing** on (Google keeps the app signing key, you keep the upload key), and
   upload the `.aab` to **Internal testing** first, then **Production**.
2. The package name `com.gvifanhub.app` can never change after the first upload.
3. Every new upload needs a higher `versionCode` in `android/app/build.gradle`. Website changes need no new upload.
4. Fill in **App content**:
   - **Privacy policy:** `https://learninganddevelopment.net/GTA6/App/privacy.php` (written from what the app
     actually stores; it also states that players are responsible for what they share and do).
   - **Data safety:** collected — email, name, user IDs, photos/videos, messages and other user content, app
     interactions, IP address/device type for sign-in security; nothing is sold, shared for ads or tracked;
     data is encrypted in transit and players can delete their account.
   - **Target audience:** 18+ (sign-up asks players to confirm they are 18 or older), plus the content rating questionnaire.
   - **Account deletion:** in the app under *Profile → Security → Delete account*, and on the website at
     `https://learninganddevelopment.net/GTA6/App/#/profile?tab=security`.
5. Present it as an **unofficial fan app** and don't use Rockstar's logos or key art in the listing, or Google can
   reject it for impersonation / intellectual property.
6. Players who installed the APK from the website must uninstall it once before installing from Google Play
   (it is signed with a different key).

If the upload key is ever lost, ask Google Play support to reset it (possible because Play App Signing is on).

---

## News auto mode

- Sources are RSS/Atom feeds of reliable outlets (Rockstar Intel, IGN, GameSpot, Polygon, Eurogamer, PC Gamer,
  GamesRadar+, VGC, Push Square and a Google News search). Add, disable or remove sources under **News auto mode** in the admin panel.
- Only stories matching the keywords (`GTA 6`, `GTA VI`, `Grand Theft Auto VI`, …) are collected; duplicates are skipped.
- New stories wait in **News & articles → Pending approval**. Approve (optionally edit first) or reject them.
  Published stories always show *Source: name* with a link to the original article.
- **Check now** (News auto mode) runs a check immediately.

---

## Project structure

```
web/                      → upload the contents to the site folder (public_html/GTA6/App/)
  index.html, assets/     player app (single-page app + PWA: manifest.webmanifest, sw.js)
  admin/                  admin panel
  api/index.php           JSON API entry point (index.php?r=module/action)
  app/config.php          ← your settings
  app/lib/                database, auth, mailer, uploads, news fetcher …
  app/api/                API endpoints (one file per feature, admin/ for the admin panel)
  app/sql/                schema.sql and starter content
  app/storage/            runtime files (install lock, secret key, logs) – not web-accessible
  uploads/                user uploads (avatars, gallery, chat …)
  cron/fetch-news.php     hourly job
  downloads/              Android APK
android/                  Android app (Java) – Gradle project
.github/workflows/        GitHub Actions build for the APK
```

## Local development

```bash
# MySQL/MariaDB with an empty database, then:
cp web/app/config.php web/app/config.local.php   # set the DB password, 'debug' => true, mail 'transport' => 'log'
php -S 127.0.0.1:8080 -t web
# open http://127.0.0.1:8080/install.php, then http://127.0.0.1:8080/
```

With `'transport' => 'log'` emails (including OTP codes) are written to `web/app/storage/mail.log` instead of being sent.

## Security notes

- Passwords and OTPs are hashed (bcrypt); OTPs expire after 10 minutes and are rate-limited.
- Sessions use HttpOnly cookies plus CSRF tokens; all database queries use prepared statements.
- `app/`, `cron/` and the storage folder are blocked from the web by `.htaccess`; uploads can't run scripts.
- Keep `'debug' => false` on the live site and delete `install.php` after installing.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| "The app is not installed yet" | Open `install.php` (and check the database password in `app/config.php`). |
| "Server error (500)" everywhere, or "app/config.php is missing" | `app/config.php` was deleted (for example by replacing the whole folder during an update). Upload it again with your passwords — see step 9. |
| No OTP email | Check the mailbox password in `config.php`, send a test email from the admin **Settings** page, look in spam. |
| Uploads fail | Raise `upload_max_filesize` / `post_max_size` in hPanel → PHP Configuration. |
| No news fetched | Open **News auto mode** in the admin panel (last run & errors per source); PHP needs cURL or `allow_url_fopen`. |
| Android app shows "Can't reach G VI Fan Hub" | Check the internet connection and that SSL / Force HTTPS is active for the domain. |
