# Uninav Society - Android App

A thin WebView wrapper around the hosted PHP portal at
`http://learninganddevelopment.net/Uninav/Application/`.

## What it does

- Loads your hosted society app in a full-screen WebView
- Cleartext HTTP allowed for `learninganddevelopment.net` (no HTTPS yet)
- Cookies + DOM storage enabled so login persists across launches
- **Camera / Gallery file picker** works for complaint images, ad photos, profile pics
- Hardware **Back button** navigates WebView history
- **Pull-to-refresh** reloads the page
- Splash screen on launch

## Build locally (Android Studio)

1. Install Android Studio Hedgehog (or newer).
2. Open `android/` as a project.
3. Let Gradle sync, then `Build → Build APK(s)` or `Run` on a device.
4. APK output: `android/app/build/outputs/apk/debug/app-debug.apk`.

## Build automatically via GitHub Actions

Every push to this branch triggers `.github/workflows/android-apk.yml`
which builds a debug APK and uploads it as a workflow artifact named
**UninavSociety-APK**.

To download:

1. Go to **Actions** tab on GitHub.
2. Open the latest "Build Android APK" run.
3. Scroll down to "Artifacts" → download `UninavSociety-APK.zip`.
4. Unzip it; you'll get `UninavSociety.apk`.

## Install APK on an Android phone

1. Copy the APK to your phone (Drive, Gmail, USB).
2. Open the APK file.
3. Android will prompt to **"Allow from this source"** — enable it for
   the app (Files / Chrome / Gmail, whichever you used).
4. Tap Install.
5. Launch **Uninav Society** from the home screen.

> Debug APKs are signed with the platform debug key and are safe to
> install on your own devices. Google Play requires a release-signed
> APK; that can be added later when you're ready to publish.

## Change the site URL

If you move your site, edit
`app/src/main/java/net/learninganddevelopment/uninav/MainActivity.java`
line with:

```java
public static final String HOME_URL = "http://…/Uninav/Application/";
```
