<?php
/*
 * G VI Fan Hub - Privacy Policy.
 * Written from how the app actually works (see app/api, app/lib and the Android app).
 * The contact address comes from Admin -> Settings ("Privacy contact email"),
 * falling back to the address the OTP emails are sent from.
 */

require __DIR__ . '/app/bootstrap.php';

function pp_e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$appName = cfg('app.name', 'G VI Fan Hub');
$contact = privacy_contact_email();
$siteUrl = app_url();
$effective = '26 October 2026';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Privacy Policy · <?= pp_e($appName) ?></title>
<meta name="description" content="How <?= pp_e($appName) ?> collects, uses, shares and protects your information, and your responsibility for what you share.">
<meta name="theme-color" content="#07030f">
<meta name="color-scheme" content="dark">
<link rel="icon" href="assets/img/icons/favicon-32.png" sizes="32x32" type="image/png">
<link rel="stylesheet" href="assets/css/legal.css">
</head>
<body>
<header class="top">
  <a class="brand" href="./"><img src="assets/img/logo.svg" alt=""><span><?= pp_e($appName) ?></span></a>
  <a class="back" href="./">Back to the app</a>
</header>
<main>
  <h1>Privacy Policy</h1>
  <p class="meta">Effective <?= pp_e($effective) ?> · Applies to the website <a href="<?= pp_e($siteUrl) ?>"><?= pp_e($siteUrl) ?></a> and the <?= pp_e($appName) ?> Android app</p>

  <section class="card summary">
    <strong>In short</strong>
    <ul>
      <li>We collect what you give us (your account, profile and the things you post) plus a few technical details (IP address and device/browser type) needed to keep you signed in and keep the app safe.</li>
      <li>We don't sell your data, show ads or use analytics or tracking tools.</li>
      <li>Other players can see your public profile and what you post. Your email address is never shown to other players.</li>
      <li><strong>You are solely responsible for your account, the information and content you share, and everything you do in the app</strong> (see <a href="#responsibility">section 6</a>).</li>
      <li>You can delete your account and data at any time in <em>Profile → Security → Delete account</em>, or request deletion without the app on our <a href="delete-account.php">account deletion page</a>.</li>
    </ul>
  </section>

  <h2 id="contents">Contents</h2>
  <ol class="toc">
    <li><a href="#who">Who we are</a></li>
    <li><a href="#collect">Information we collect</a></li>
    <li><a href="#use">How we use information</a></li>
    <li><a href="#see">Who can see your information</a></li>
    <li><a href="#share">Sharing and third parties</a></li>
    <li><a href="#responsibility">Your responsibility for your data and actions</a></li>
    <li><a href="#retention">How long we keep information</a></li>
    <li><a href="#security">Security</a></li>
    <li><a href="#rights">Your choices and rights</a></li>
    <li><a href="#children">Age requirement</a></li>
    <li><a href="#transfers">Where your data is stored</a></li>
    <li><a href="#changes">Changes to this policy</a></li>
    <li><a href="#contact">Contact us</a></li>
  </ol>

  <h2 id="who">1. Who we are</h2>
  <p><?= pp_e($appName) ?> ("we", "us") is an <strong>unofficial, fan-made</strong> companion app for Grand Theft Auto VI, run at <a href="<?= pp_e($siteUrl) ?>"><?= pp_e($siteUrl) ?></a>. It is not affiliated with, endorsed by or connected to Rockstar Games or Take-Two Interactive. Game names and trademarks belong to their owners.</p>
  <p>This policy explains what information the app collects, why, who can see it and what choices you have. By creating an account or using the app you agree to this policy.</p>

  <h2 id="collect">2. Information we collect</h2>

  <h3>2.1 Information you give us when you sign up</h3>
  <ul>
    <li><strong>Display name and email address.</strong> We send a one-time code (OTP) to your email to verify it before the account is created. A username is generated from your name, and you can change it.</li>
    <li><strong>Password.</strong> Stored only as a secure one-way hash. We can never see your password.</li>
  </ul>

  <h3>2.2 Profile information (optional)</h3>
  <p>Profile photo, cover image, bio, country, gaming platform, favourite character, Rockstar Social Club / PlayStation Network / Xbox gamer IDs, an activity line ("What are you up to?"), your online status (online, away, busy or invisible), your colour theme and your privacy settings.</p>

  <h3>2.3 Content you create</h3>
  <ul>
    <li><strong>Chat messages</strong> in direct and group chats, including photos you send, group names and group photos.</li>
    <li><strong>Statuses</strong> (text or photo stories), which disappear after 24 hours.</li>
    <li><strong>Discussions and comments</strong>, including cover images.</li>
    <li><strong>Gallery uploads</strong>: photos, videos and YouTube links, with titles and captions.</li>
    <li><strong>Map markers</strong>: titles, notes, categories and positions on the fan map of the game world (not your real location).</li>
    <li><strong>Reports</strong> you send about other players or content, with the reason you give.</li>
  </ul>

  <h3>2.4 Your activity in the app</h3>
  <p>Friend requests, friends and blocked players; likes; poll votes; events you join; rewards, badges, titles and frames you claim or equip; promo codes you unlock; saved catalogue items ("garage"); XP, level and daily login streak; notifications; which statuses you viewed and how far you have read in each chat (used for "Seen" and unread counts); and when you were last active.</p>

  <h3>2.5 Technical and security information</h3>
  <ul>
    <li><strong>Sign-in sessions:</strong> the IP address and device/browser type (user agent) of each signed-in device, the time it was created and last used. You can see these under <em>Profile → Security</em>.</li>
    <li><strong>Security records:</strong> one-time codes (stored only as hashes) with the requesting IP address; short-lived counters of failed sign-in attempts per IP address and account, to stop password guessing.</li>
    <li><strong>Moderation records:</strong> if a player is blocked by an admin, the reason and duration are stored.</li>
    <li><strong>Error logs:</strong> technical error messages that help us fix problems. Our hosting provider also keeps standard server access logs (including IP addresses).</li>
  </ul>
  <p>We do <strong>not</strong> use analytics, advertising, fingerprinting or third-party tracking tools, and we do not collect your real-world location, contacts or phone number.</p>

  <h3>2.6 Information stored on your device</h3>
  <ul>
    <li>A <strong>sign-in cookie</strong> (<code>gvi_session</code>) that keeps you logged in. It is strictly necessary, cannot be read by scripts and is sent only to this site. It lasts until you close the browser, or 60 days if you tick "Keep me signed in".</li>
    <li><strong>Local storage</strong> remembers small preferences such as "Reduce motion" and which announcement you dismissed.</li>
    <li>An <strong>offline cache</strong> of the app's own files (pages, styles, icons) so it loads fast and works offline. Your messages and other account data are not cached.</li>
  </ul>

  <h3>2.7 The Android app</h3>
  <p>The Android app shows this website inside the app, so everything above applies. It asks only for <strong>internet</strong> and <strong>network status</strong> access. It has no access to your camera, microphone, location, contacts or calendar data:</p>
  <ul>
    <li>When you upload a file you choose it yourself in Android's file picker; the app only receives the files you pick.</li>
    <li>"Add to calendar" opens your own calendar app with the launch date filled in. We never read your calendar.</li>
    <li>"Share" opens Android's share sheet; you decide where to send the link.</li>
    <li>Downloads (such as the launch-date calendar file) are saved to your device's Downloads folder.</li>
    <li>The app keeps its sign-in cookie and cache on your device and is excluded from Android cloud backups.</li>
  </ul>

  <h2 id="use">3. How we use information</h2>
  <ul>
    <li>To create and run your account, verify your email, sign you in and keep your sessions secure.</li>
    <li>To provide the features you use: profiles, friends, chat, statuses, discussions, gallery, map, polls, events, rewards, XP and notifications.</li>
    <li>To send <strong>service emails only</strong>: verification and sign-in codes, password-reset codes, and a notice if your account is blocked. We do not send marketing emails.</li>
    <li>To keep the community safe: preventing abuse and spam, limiting repeated failed logins, reviewing reports, approving gallery uploads and enforcing our rules.</li>
    <li>To fix problems and keep the service running.</li>
    <li>To meet legal obligations and respond to lawful requests.</li>
  </ul>
  <p>We use your information because it is needed to provide the service you signed up for, because of our legitimate interest in keeping the app safe and working, because you chose to share it (optional profile fields and content), or because the law requires it.</p>

  <h2 id="see">4. Who can see your information</h2>
  <p>Only signed-in players can use the app. What they can see:</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Information</th><th>Who can see it</th></tr></thead>
    <tbody>
      <tr><td>Display name, username, profile photo, cover, gaming platform, level and rank, equipped title and frame, join date</td><td>All players. Anyone can find you by name or username in player search.</td></tr>
      <tr><td>Online status, last seen and activity line</td><td>All players. Choose <strong>Invisible</strong> to appear offline and hide your last-seen time.</td></tr>
      <tr><td>Bio, country, favourite character, gamer IDs, stats, badges and recent discussions</td><td>Everyone, or only your friends if you turn on <strong>Friends-only profile</strong>.</td></tr>
      <tr><td>Email address</td><td><strong>Never shown to other players.</strong> Visible to admins.</td></tr>
      <tr><td>Chat messages and photos</td><td>Members of that chat. Members can see when you've read their messages ("Seen").</td></tr>
      <tr><td>Statuses</td><td>Your friends or all players, as you choose for each status. You can see who viewed your statuses.</td></tr>
      <tr><td>Discussions, comments, likes, approved gallery uploads</td><td>All players. Active posters can appear in "Top contributors this week".</td></tr>
      <tr><td>Map markers</td><td>Only you, your friends or all players, as you choose for each marker.</td></tr>
      <tr><td>Events you join</td><td>All players can see the participant list.</td></tr>
      <tr><td>Poll votes</td><td>Only totals are shown, never who voted for what.</td></tr>
      <tr><td>Uploaded files (photos and videos)</td><td>Stored under hard-to-guess links. Anyone who has a file's link can open it.</td></tr>
    </tbody>
  </table></div>
  <p><strong>Admins</strong> (the team that runs the app) can see account details including your email address, last-seen time, block history and recent discussions; block or delete accounts; remove content; review reported content (including a reported chat message); approve gallery uploads; and send announcements. Admins only look at private chat messages when one is reported to them.</p>

  <h2 id="share">5. Sharing and third parties</h2>
  <p>We <strong>do not sell or rent</strong> your personal information and do not share it for advertising. Information leaves our servers only in these cases:</p>
  <ul>
    <li><strong>Hosting and email:</strong> the app, its database, uploaded files and our email service run on Hostinger, which stores and processes data on our behalf.</li>
    <li><strong>YouTube:</strong> trailers and video posts play in YouTube's privacy-enhanced player (<code>youtube-nocookie.com</code>), and video thumbnails load from YouTube. When you play a video, YouTube (Google) receives your IP address and device information under <a href="https://policies.google.com/privacy" rel="noopener" target="_blank">Google's Privacy Policy</a>.</li>
    <li><strong>News images and links:</strong> news pictures load directly from the news outlets that published them, and "Read on …" opens their website. Those sites receive your IP address and follow their own privacy policies.</li>
    <li><strong>Legal reasons:</strong> when required by law, court order or a valid request from authorities, or to protect the rights, safety and property of our players, the public or us.</li>
    <li><strong>Change of operator:</strong> if the app is transferred to someone else, your information may move with it, and this policy will continue to apply.</li>
  </ul>

  <h2 id="responsibility">6. Your responsibility for your data and actions</h2>
  <section class="card duty">
    <p><strong>You are solely responsible for your account, for all information and content you choose to share in <?= pp_e($appName) ?>, and for everything you do in the app.</strong> In particular:</p>
    <ul>
      <li><strong>What you share is your choice.</strong> You decide what goes in your profile, chats, statuses, discussions, comments, gallery, markers and reports. Don't post anything you want to keep private, such as your home address, phone number, passwords, financial details, ID documents or other people's personal information.</li>
      <li><strong>Others can keep what you share.</strong> Anything you show other players can be copied, screenshotted, saved or forwarded by them. We cannot control or undo this, even after you delete the content or your account.</li>
      <li><strong>Your content and conduct.</strong> You are responsible for making sure what you post is lawful, that you have the right to share it (including photos, videos and other people's images), and that it respects other players. You are responsible for your messages, interactions and any consequences of them.</li>
      <li><strong>Your account security.</strong> Keep your password and sign-in codes secret, use a strong password, sign out on shared devices, and use <em>Log out all other devices</em> if you suspect misuse. You are responsible for activity carried out through your account.</li>
      <li><strong>Other players.</strong> Content posted by other players is their responsibility, not ours. Use <strong>Block</strong> and <strong>Report</strong> to protect yourself, and be careful about what you share with people you don't know.</li>
      <li><strong>Outside links and services.</strong> Links to news sites, YouTube and other websites are governed by their own terms and policies.</li>
    </ul>
    <p>To the extent permitted by law, we are not liable for any loss or harm resulting from information or content you choose to share, from your actions in the app, or from the actions of other players. This does not reduce any rights you have under applicable law, and it does not change the steps we take to protect your data as described in this policy.</p>
  </section>

  <h2 id="retention">7. How long we keep information</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Information</th><th>How long</th></tr></thead>
    <tbody>
      <tr><td>Account, profile and your content</td><td>Until you delete it or delete your account.</td></tr>
      <tr><td>Statuses (stories)</td><td>Shown for 24 hours, then deleted with their photo (usually within the next hour or two).</td></tr>
      <tr><td>Chat messages you delete</td><td>The text and photo are erased straight away; the chat shows "Message deleted".</td></tr>
      <tr><td>Sign-in sessions (IP address, device type)</td><td>Until you log out, or until the session goes unused for 1 day (60 days if you chose "Keep me signed in").</td></tr>
      <tr><td>One-time email codes</td><td>Valid for 10 minutes; records removed about a day after they expire.</td></tr>
      <tr><td>Failed sign-in and sign-up counters (IP address, account)</td><td>Up to 24 hours.</td></tr>
      <tr><td>Notifications</td><td>Read notifications are removed after 60 days; you can clear all of them at any time.</td></tr>
      <tr><td>Reports and moderation records</td><td>As long as needed to keep the community safe. Reports you made stay after you delete your account, without your name.</td></tr>
      <tr><td>Backups and server logs</td><td>Kept by our hosting provider for a limited period, after which they are overwritten.</td></tr>
    </tbody>
  </table></div>

  <h2 id="security">8. Security</h2>
  <p>We protect your information with reasonable technical measures, including:</p>
  <ul>
    <li>encrypted connections (HTTPS);</li>
    <li>passwords, one-time codes and sign-in tokens stored only as secure hashes;</li>
    <li>sign-in cookies that scripts cannot read;</li>
    <li>codes that expire after 10 minutes, and limits on repeated failed attempts;</li>
    <li>optional 2-step sign-in with an email code;</li>
    <li>photos re-processed when uploaded, which removes hidden location (GPS) and camera data. Videos are stored as uploaded, so check them before sharing.</li>
  </ul>
  <p>Chat messages are encrypted in transit and access-controlled on our servers, but they are <strong>not end-to-end encrypted</strong>. No online service can be 100% secure, which is one more reason to share only what you are comfortable sharing.</p>

  <h2 id="rights">9. Your choices and rights</h2>
  <ul>
    <li><strong>See and correct your data:</strong> view and edit your profile and settings at any time; see your signed-in devices under <em>Profile → Security</em>.</li>
    <li><strong>Control visibility:</strong> Friends-only profile, "Only friends can message me", Invisible status, and per-item visibility for statuses and map markers.</li>
    <li><strong>Delete content:</strong> delete your messages, statuses, discussions, comments, gallery uploads and markers whenever you like.</li>
    <li><strong>Delete your account:</strong> in the app or on the website, open <em>Profile → Security → Delete account</em> and confirm with your password. Without the app or your password, request deletion at <a href="<?= pp_e($siteUrl) ?>delete-account.php"><strong><?= pp_e($siteUrl) ?>delete-account.php</strong></a>: we email a one-time code to your account's address and delete the account as soon as you confirm it. Either way this permanently removes your account, profile, friends, statuses, discussions and comments, gallery uploads, markers, votes, rewards, notifications and sign-in sessions, and erases the text and photos of messages you sent. If you no longer have access to your email, contact us and we'll help.</li>
    <li><strong>Get a copy, or ask us anything about your data:</strong> email us (see <a href="#contact">Contact us</a>). We answer within 30 days.</li>
  </ul>
  <p>Depending on where you live (for example under India's Digital Personal Data Protection Act 2023, the EU/UK GDPR or California law), you may also have the right to access, correct, erase or port your data, to withdraw consent, to object to certain uses, to name someone to act for you, and to complain to your data protection authority. We will not treat you differently for using these rights.</p>

  <h2 id="children">10. Age requirement</h2>
  <p>Grand Theft Auto VI is a game for adults, and <?= pp_e($appName) ?> is meant for players who are <strong>18 or older</strong> (or the age of majority where they live). Do not create an account if you are younger. If we learn that an account belongs to a child, we will delete it. If you believe a child has given us information, please contact us.</p>

  <h2 id="transfers">11. Where your data is stored</h2>
  <p>Your data is stored on servers provided by our hosting provider, which may be located outside your country. By using the app you understand that your information may be processed there, with the safeguards described in this policy.</p>

  <h2 id="changes">12. Changes to this policy</h2>
  <p>We may update this policy when the app changes. We will post the new version on this page with a new effective date and, for important changes, tell you in the app. If you keep using the app after a change, the updated policy applies.</p>

  <h2 id="contact">13. Contact us</h2>
  <p>Questions, requests about your data, or privacy complaints (grievance contact):
    <?php if ($contact !== ''): ?><a href="mailto:<?= pp_e($contact) ?>?subject=<?= rawurlencode($appName . ' privacy request') ?>"><strong><?= pp_e($contact) ?></strong></a><?php else: ?><strong>the contact email shown in the app</strong><?php endif; ?>.
    Please email us from the address on your account so we can verify that the request is yours.</p>

  <footer>
    <p>© <?= gmdate('Y') ?> <?= pp_e($appName) ?> · Unofficial fan app · <a href="./">Open the app</a></p>
  </footer>
</main>
</body>
</html>
