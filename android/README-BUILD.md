# Uni-Vishwas Android app — build and release (v1.8.0)

The Uni-Vishwas app is a secure WebView around
`https://learninganddevelopment.net/Uninav/Application`, plus native
notifications and an update prompt. Package: `net.learninganddevelopment.uninav`.

| | |
|---|---|
| versionName / versionCode | `1.8.0` / `180` (next release: `1.8.1` / `181`) |
| targetSdk / compileSdk / minSdk | 36 / 36 / 24 (Android 7.0+) |
| Google Play requirement | API 36 for new apps and updates since 31 Aug 2026 — met |
| Build tools | Android Gradle Plugin 8.13.2, Gradle 8.14.3 (wrapper included), JDK 17+ |
| Native code | only `libdatastore_shared_counter.so` from AndroidX DataStore (via Firebase) — 16 KB page aligned |

## What the app does

| Capability | How it works |
|---|---|
| **Instant notifications** | Firebase Cloud Messaging. When anything becomes visible to residents (notice, event, album, photos, poll, ad, AOA update, vendor, SPOC or finance change) the server sends one message to a topic every phone subscribes to. `PushService` shows it. |
| **Fallback notifications** | `UpdatesWorker` polls `api/app_updates.php` every 15 minutes while push is not active (no Firebase set up yet, or a phone without Google Play services), and hourly as a safety net once it is. |
| No duplicates | Push and the background check share one history keyed by item id, so the same notice is never shown twice. Updates with the same *tag* (e.g. more photos in one album) replace each other in the tray silently. |
| First install is quiet | The first check only learns the server clock, so installing never dumps a week of history on the user. |
| **Update prompt** | On launch the app asks Google Play (In-App Updates API) and the server (`api/app_config.php`) whether a newer version exists. Only if it is out of date does it show *Update available* → opens the Play Store listing. "Later" holds for that session (or 12 h); the admin can force updates below a minimum version, which removes "Later". |
| QR scanning, attendance photos | Camera granted to the page (getUserMedia). Inputs marked `capture=` open the camera directly and the full-size photo is returned to the page (FileProvider + `EXTRA_OUTPUT`). |
| Photo & video uploads | Multi-select picker with a camera option (gallery, complaints, ads, profile). |
| Location | Attendance in/out punches. |
| Report downloads | CSV / XLS / XLSX / PDF exports download with the session cookie. |
| Saved login | Persistent cookies (flushed whenever the app goes to the background). |
| Modern Android | Edge-to-edge layout, predictive back gesture, themed (monochrome) icon, no backup / device-transfer of the login cookies. |

## How push is set up — no rebuild needed

There is **no `google-services.json` in this project** on purpose. The
Firebase project's public client settings (project id, app id, API key,
sender id — the values `google-services.json` carries; none of them is a
secret) are handed to the app by the server in the `push` block of
`api/app_config.php` and `api/app_updates.php`, and `PushManager` initialises
Firebase with them at runtime.

So the society switches instant push on from the web admin console
(**Admin → Mobile app**: upload the Firebase `google-services.json` and a
service-account key), and every installed copy of this same AAB picks it up on
its next launch or background check. Until then the app works exactly as
before with the 15-minute check.

Notification text is always generic ("New society notice — Tap to view it in
Uni-Vishwas"). The app key and the Firebase topic are inside every APK, so
anyone could receive these messages; residents sign in to read the real content.

The WebView adds ` UninavApp/1.8.0 (Android; build 180)` to its user agent.
The server uses it to tell this app (which has its own update prompt) from
old installs, which get a web page pop-up asking them to update instead.

## Security choices (do not undo these)

`START-HERE-SECURITY-UPGRADE.md` lists the mobile items needed for CERT-In
closure. They are all implemented here:

- `android:usesCleartextTraffic="false"` plus a network security config with
  `cleartextTrafficPermitted="false"`. An `http://` URL cannot load at all.
- **`onReceivedSslError` is deliberately not overridden.** The default cancels
  the load on a bad certificate. Never add an override that calls
  `ssl.proceed()` — that single line would reopen the issue the upgrade closed.
- Mixed content is blocked (`MIXED_CONTENT_NEVER_ALLOW`).
- Only your own host opens in the app; any other link goes to the system
  browser, so a link inside a notice cannot borrow the signed-in session.
- Notification and deep links are accepted only for `https://` on your own host.
- `allowBackup="false"` and data-extraction rules that exclude everything.

## Signing and Google Play

Play needs the AAB signed with your **upload key**. With Play App Signing (the
default for a new app) the first upload registers that key, and Google re-signs
the app for users with its own app-signing key. Every later upload must use the
same upload key — keep the keystore and its passwords backed up, and **never
commit them** (this repository is public; `.gitignore` blocks `*.jks`,
`*.keystore` and `keystore.properties`).

The build reads the signing details from either:

- environment variables `UNINAV_KEYSTORE_FILE`, `UNINAV_KEYSTORE_PASSWORD`,
  `UNINAV_KEY_ALIAS`, `UNINAV_KEY_PASSWORD`, or
- `android/keystore.properties` (copy `keystore.properties.example`).

Without either, release outputs are unsigned (Play rejects them); debug builds
always work.

To create a new upload key (only if you do not have one):

```bash
keytool -genkeypair -v -storetype PKCS12 -keystore uninav-upload.jks \
        -alias upload -keyalg RSA -keysize 2048 -validity 10000
```

## Option A — build on GitHub (no Android install needed)

`.github/workflows/android.yml` (at the repository root) builds on every push
to `main` that touches `android/`, or on demand: **Actions → Android build →
Run workflow**.

- Always: `uni-vishwas-debug-apk` — installs as *Uni-Vishwas* with package
  `…uninav.debug`, next to the Play version.
- With these repository secrets set, also `uni-vishwas-release-aab` (upload to
  Play) and `uni-vishwas-release-apk`:

| Secret | Value |
|---|---|
| `UNINAV_KEYSTORE_BASE64` | `base64 -w0 uninav-upload.jks` |
| `UNINAV_KEYSTORE_PASSWORD` | keystore password |
| `UNINAV_KEY_ALIAS` | `upload` |
| `UNINAV_KEY_PASSWORD` | key password |

## Option B — Android Studio

1. **File → Open** the `android/` folder and let it sync.
2. **Build → Generate Signed App Bundle / APK…** → *Android App Bundle* →
   choose your upload keystore → *release*.
3. The AAB lands in `app/release/` (or `app/build/outputs/bundle/release/`).

## Option C — command line

Needs JDK 17+ and the Android SDK (`ANDROID_HOME` set; platform 36 and
build-tools 36 are fetched automatically if licences are accepted):

```bash
cd android
./gradlew assembleDebug                    # app/build/outputs/apk/debug/app-debug.apk
./gradlew bundleRelease assembleRelease    # signed AAB + APK (needs signing details)
```

## Releasing an update later

1. Bump `versionCode` (e.g. 181) and `versionName` in `app/build.gradle`.
2. Build and upload the AAB to Play.
3. Once it is live, optionally set **Admin → Mobile app → Latest version code**
   on the server: phones that Play has not told yet (and side-loaded copies)
   then see the update prompt too. Use *Minimum version code* only to force an
   update (for example after a security fix).

## Pointing the app at a different installation

Edit `gradle.properties` only — no code changes:

```properties
UNINAV_BASE_URL=https://your.host/path/Application
UNINAV_HOST=your.host
UNINAV_NOTIFY_KEY=<must match APP_NOTIFY_KEY on the server>
UNINAV_POLL_MINUTES=15
```

## Before publishing to Play

- Data safety form: the app collects personal info, photos, precise location
  (attendance) and app activity through the web app, and Firebase Cloud
  Messaging processes a device identifier (FCM registration token / Firebase
  installation ID) for app functionality. Data is encrypted in transit. Do not
  declare "no data collected".
- Privacy policy URL: `https://learninganddevelopment.net/Uninav/Application/privacy.php`.
- Content rating questionnaire, target audience (adults), and the app access
  instructions (a test login) for the reviewer.
- Verify real HTTPS traffic from the built release with a traffic capture, as
  the upgrade notes require.
