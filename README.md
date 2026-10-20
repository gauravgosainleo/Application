# Uni-Vishwas — Android app 1.8.0 and server update

Residents get a phone notification within seconds whenever something becomes
public in Uni-Vishwas: a notice, an event, new photos or videos, a poll, a
society ad, an AOA update, a vendor, a SPOC contact change or new finance
entries. Anyone running an out-of-date app is asked to update from Google Play
when they open it.

| Folder | What it is |
|---|---|
| [`android/`](android/) | The Android app (Java). Builds the `.aab` for Google Play. Build guide: [`android/README-BUILD.md`](android/README-BUILD.md) |
| [`server/`](server/) | The PHP files that change on the website for v1.8.0, laid out like the site. What and why: [`server/CHANGELOG-v1.8.0.md`](server/CHANGELOG-v1.8.0.md) |
| [`play-store/`](play-store/) | Store listing graphics: 512×512 icon and 1024×500 feature graphic |
| [`.github/workflows/android.yml`](.github/workflows/android.yml) | Builds the app on GitHub (debug APK always; signed AAB when the signing secrets are set) |

`server/` holds only changed and new files. `config/config.php` is not here on
purpose: it contains the database password and stays only on the server.

## How it works

```
 admin/resident publishes ──► website (PHP) ──► Firebase Cloud Messaging ──► every phone, in seconds
                                   │
                                   └── api/app_updates.php ◄── phones also check every 15–60 min (safety net)
```

- The server pushes each new item once, right after the page that published it
  has been sent. No device tokens are stored: all phones subscribe to one topic.
- Notifications say only what kind of update it is ("New society notice",
  "5 new photos in the gallery") and open the right page. Titles, names and
  photos stay behind sign-in, because notifications show on lock screens.
- Firebase is switched on from the website (Admin → Mobile app) by uploading
  two files. The app reads the settings from the server, so no new app
  release is needed. Until then the app uses its background check.
- **Update prompt:** the 1.8.0 app checks Google Play itself. Older installs
  (residents' current APK) can't, so the website shows them a popup with an
  *Open Google Play* button, but only inside the old app and only once the Play
  listing is live. Browsers and the new app never see it.

## Release checklist

1. **Website:** upload the contents of `server/` over the existing
   installation (`public_html/Uninav/Application/`). No database change.
2. **Google Play:** upload `Uni-Vishwas-1.8.0.aab` (steps below).
3. **Firebase**, once, about 10 minutes: website → Admin → **Mobile app** →
   *Set up instant notifications*, then follow the four steps there.
4. Nothing to do for the update prompt: it starts by itself when the Play
   listing goes live (Admin → Mobile app shows the status).

## Publishing on Google Play

1. In [Play Console](https://play.google.com/console), **Create app**: name
   *Uni-Vishwas*, App, Free.
2. **Test and release → Production** (or **Closed testing**, see the note
   below) → **Create new release** → upload `Uni-Vishwas-1.8.0.aab`. Accept
   **Play App Signing**. The key that signed this AAB becomes your *upload key*.
   Keep the keystore and its passwords safe: every future update must be
   signed with it.
3. **Store listing:** short and full description; `play-store/icon-512.png`;
   `play-store/feature-graphic-1024x500.png`; and at least two phone
   screenshots (take them on a phone).
4. **App content:**
   - Privacy policy: `https://learninganddevelopment.net/Uninav/Application/privacy.php`
   - **App access:** the app needs a login. Give Google's reviewers a working
     test house number and password, or the review will be rejected.
   - Ads: none. Target audience: 18+. Complete the content-rating questionnaire.
   - **Data safety:** answers are prepared in
     [`server/docs/GOOGLE-PLAY-DATA-SAFETY.md`](server/docs/GOOGLE-PLAY-DATA-SAFETY.md).
5. Countries: India. Send for review.

> **New personal developer accounts:** Google requires a closed test with at
> least 12 testers for 14 days before *Production* unlocks (organisation
> accounts are exempt). Residents make ideal testers: add their Gmail
> addresses to a closed-testing track, share the opt-in link, and apply for
> production after 14 days.

## Residents already using the old app

Their current app was installed from an APK file and signed with a different
key, so Google Play cannot update it in place. The popup tells them what to do:
open Google Play; if Play shows **Uninstall**, tap it, then **Install**; then
sign in again with house number and password. Expect a few residents to need
their password reset by the admin.

## Building it yourself

The AAB is reproducible from `android/`. See
[`android/README-BUILD.md`](android/README-BUILD.md) for Android Studio, the
command line, and the GitHub Actions workflow with the four signing secrets.
For a later release, bump `versionCode` (181, 182, …) and `versionName` in
`android/app/build.gradle`.

**Never commit the keystore or its passwords.** This repository is public.
`.gitignore` blocks `*.jks`, `*.keystore` and `keystore.properties`.
