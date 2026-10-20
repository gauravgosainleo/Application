# Play Console review for Uni-Vishwas

The declaration is edited in Play Console and cannot be changed by a PHP ZIP.
Do not submit an "encrypted in transit" assurance until the installed Android
release and every API/SDK path have been tested. A redirected HTTP password POST
has already transmitted its credentials; the mobile client must start with HTTPS.

## Source-based inventory

| Information in the supplied application/database | Review the applicable Play category/purpose |
| --- | --- |
| Names, flat/account identifiers and family relationships | Personal information: name, user IDs, address/other information as applicable; account management and app functionality |
| Optional/retained email and profile photos | Email address and photos/videos; indicate actual optional/required handling |
| Complaints, descriptions and attachments | Other user-generated content and photos/videos; society complaint handling |
| Staff attendance coordinates | Precise location when transmitted; operational attendance functionality |
| Attendance photos and society gallery uploads | Photos/videos; attendance and gallery functionality |
| Gym visits, child pass names, vehicle/guest/parking details | Appropriate personal information, app activity or other categories according to actual implementation |
| Vendor/staff/SPOC contact records and society operations | Assess whether entered data is user data under Play's definitions; describe its purpose and authorised access |
| Push notifications (Android app 1.8.0) | Device or other IDs: the Firebase Cloud Messaging token / Firebase installation ID, processed by Google to deliver notifications (app functionality). Not stored by the society server |

This is an inventory and review aid, not an automatically completed declaration.
Confirm collection versus sharing and service-provider exceptions using Google's
current definitions. Voluntary entry or a resident-built welfare purpose does
not remove the need for accurate disclosure and secure handling.

Publish the current privacy notice at the HTTPS /privacy.php URL. Verify the
operator/contact, retention practices, deletion-request process and provider
information before adoption. Existing authenticated admin house-removal tools
support deletion but retention of operational records and backups needs an
actual policy. Do not delete records blindly to change a declaration.

## The Android app, release 1.8.0 (version code 180)

Package `net.learninganddevelopment.uninav`, target API 36, minimum Android 7.0
(API 24). A WebView around this site plus native notifications.

| Component | What it does | Data leaving the phone |
| --- | --- | --- |
| WebView of this site | Everything residents do in the portal | What the resident enters or uploads, to this server over HTTPS only |
| Firebase Cloud Messaging (`firebase-messaging`) | Instant notifications, through one topic | FCM token / Firebase installation ID, the app version and the Firebase user agent, to Google. No analytics: Firebase Analytics is not included and delivery-metrics export is off |
| Google Play In-App Updates (`app-update`) | Asks Google Play whether a newer version exists, for the update prompt | Standard Google Play service request |
| WorkManager background check | Polls `api/app_updates.php` (fallback for phones without Google Play services) | The shared app key and a timestamp; nothing about the resident |

Permissions and why:

| Permission | Why |
| --- | --- |
| `INTERNET`, `ACCESS_NETWORK_STATE` | Load the site; run the background check only when online |
| `POST_NOTIFICATIONS` | Show notifications (asked on first launch on Android 13+) |
| `RECEIVE_BOOT_COMPLETED` | Restart the background check after a reboot |
| `WAKE_LOCK`, `com.google.android.c2dm.permission.RECEIVE` | Added by Firebase Messaging / WorkManager to receive messages |
| `CAMERA` | QR scan (gym pass), vehicle number plate scan, photos for uploads and attendance |
| `ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION` | Attendance in/out location, foreground only (no background location) |
| `WRITE_EXTERNAL_STORAGE` (Android 9 and older only) | Save downloaded reports |

Not present: advertising ID (`AD_ID`), background location, SMS/call log,
all-files access, `QUERY_ALL_PACKAGES`, foreground-service types.

Suggested answers to review (confirm against Google's current wording):

- Data collected: Personal info (name, user IDs, email if provided, flat
  number as "other info"); Photos and videos; Precise location (attendance
  staff only); App activity / other user-generated content (complaints, ads,
  votes); Device or other IDs (FCM token). Purposes: app functionality and
  account management. None used for advertising or analytics.
- Data shared with third parties: none. Google processes the FCM token as a
  service provider to deliver notifications.
- Encrypted in transit: yes, once verified - the app refuses cleartext HTTP and
  certificate errors, but capture traffic from the Play-installed release first,
  as below.
- Deletion: on request through the contact on /privacy.php. Accounts are
  created by the society administrator, not inside the app.
- Advertising ID: the app does not use it.
- App access: sign-in is required. Give the reviewers a working test login
  (a test house number and password) in Play Console > App content > App
  access, or the review will be rejected.

Official references:
https://support.google.com/googleplay/android-developer/answer/10787469
https://support.google.com/googleplay/android-developer/answer/10144311
https://developer.android.com/privacy-and-security/security-config
https://firebase.google.com/docs/android/play-data-disclosure

Android checks (all implemented in 1.8.0; verify on the Play-installed build):
- Use HTTPS for initial WebView/start URL, API/background update URLs and every
  form action. Do not implement SSL-error acceptance in WebViewClient.
- Disable usesCleartextTraffic and cleartextTrafficPermitted in the release
  manifest/network-security configuration.
- Use valid certificates with standard hostname/certificate validation.
- Capture requests from the exact Play-distributed release and check there
  are no cleartext credentials, cookies, identifiers, media or other user data.
- Record versionCode/versionName, tested device, date, redacted request evidence
  and the declaration publishing status for CERT-In.
