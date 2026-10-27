<?php
/*
 * G VI Fan Hub - Child Safety Standards (CSAE), the public page Google Play asks social apps
 * to publish. Describes what the app actually does: 18+ sign-up, friends-only messages by
 * default, moderated gallery, Report on every player and post (with a child-safety reason that
 * admins see first), Block, removal and bans. Contact comes from Admin -> Settings.
 */

require __DIR__ . '/app/bootstrap.php';

function pp_e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$appName = cfg('app.name', 'G VI Fan Hub');
$playName = 'New GTA - Gaming social app';
$contact = privacy_contact_email();
$siteUrl = app_url();
$effective = '27 October 2026';
try {
    $galleryReviewed = setting('gallery_requires_approval', '1') === '1';
} catch (Throwable $e) {
    $galleryReviewed = true;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Child Safety Standards · <?= pp_e($appName) ?></title>
<meta name="description" content="<?= pp_e($playName) ?> (<?= pp_e($appName) ?>) standards against child sexual abuse and exploitation (CSAE).">
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
  <h1>Child Safety Standards</h1>
  <p class="meta">Effective <?= pp_e($effective) ?> · Standards against child sexual abuse and exploitation (CSAE) for <strong><?= pp_e($playName) ?></strong> (<?= pp_e($appName) ?>) on Google Play and at <a href="<?= pp_e($siteUrl) ?>"><?= pp_e($siteUrl) ?></a></p>

  <section class="card summary">
    <p><strong>We have zero tolerance for child sexual abuse and exploitation.</strong> Any content or behaviour that sexualises, exploits or endangers children is forbidden. We remove it, permanently ban the accounts involved and report it to the authorities.</p>
  </section>

  <h2>1. What is not allowed</h2>
  <p>The following are strictly prohibited anywhere in the app: in profiles, chats, group chats, statuses, discussions, comments, gallery uploads, map notes and links.</p>
  <ul>
    <li>Child sexual abuse material (CSAM) of any kind, including drawn, animated, edited or AI-generated images or videos of minors, and links to or requests for such material.</li>
    <li>Sexualising minors in any way, including sexual comments about children and content that depicts minors in a sexual context.</li>
    <li>Grooming: building a relationship with a minor to manipulate them into sexual contact or into sharing images.</li>
    <li>Sextortion: threatening or blackmailing anyone with intimate images of a minor.</li>
    <li>Trafficking, or advertising or soliciting minors for sexual purposes.</li>
    <li>Contacting, or trying to meet, a minor for sexual purposes.</li>
  </ul>

  <h2>2. Who can use the app</h2>
  <p><?= pp_e($appName) ?> is for adults. Every player must confirm they are <strong>18 or older</strong> when they sign up, and every account is verified by email. If we learn that an account belongs to someone under 18, we delete it.</p>

  <h2>3. How we keep the community safe</h2>
  <ul>
    <li><strong>Signed-in community only:</strong> profiles, chats and posts are visible only to signed-in players, not to the open web.</li>
    <li><strong>Private messages are friends-only by default:</strong> players who aren't your friends can't message you unless you choose to allow it.</li>
    <?php if ($galleryReviewed): ?><li><strong>Moderated gallery:</strong> photos and videos that players upload to the gallery are checked by our moderators before anyone else can see them.</li><?php endif; ?>
    <li><strong>Report and Block everywhere:</strong> every player profile, chat message, status, discussion, comment and gallery post can be reported. Any player can be blocked; blocked players can't message you or see your content.</li>
    <li><strong>Moderation:</strong> our moderators review reports, remove content and block accounts, temporarily or permanently.</li>
  </ul>

  <h2>4. How to report a concern</h2>
  <ul>
    <li><strong>In the app:</strong> tap <strong>Report</strong> on the player, message, status, discussion, comment or gallery post, and choose <strong>"Child sexual abuse or exploitation"</strong>. These reports go to the top of our moderators' queue.</li>
    <li><strong>By email:</strong> contact our child safety team<?php if ($contact !== ''): ?> at <a href="mailto:<?= pp_e($contact) ?>?subject=<?= rawurlencode('Child safety report - ' . $playName) ?>"><strong><?= pp_e($contact) ?></strong></a><?php endif; ?>. Include the player's username and what you saw. Please don't attach or forward abusive images; describe them and tell us where they are.</li>
    <li><strong>If a child is in immediate danger,</strong> contact your local police or emergency number straight away (112 in India and the EU, 911 in the US).</li>
    <li>You can also report directly to the authorities:
      <ul>
        <li>India: <a href="https://cybercrime.gov.in/" rel="noopener" target="_blank">National Cyber Crime Reporting Portal</a>, or call Childline on <strong>1098</strong></li>
        <li>United States and worldwide: <a href="https://report.cybertip.org/" rel="noopener" target="_blank">NCMEC CyberTipline</a></li>
        <li>United Kingdom and worldwide: <a href="https://report.iwf.org.uk/" rel="noopener" target="_blank">Internet Watch Foundation</a></li>
      </ul>
    </li>
  </ul>

  <h2>5. What we do when we find or receive a report</h2>
  <ul>
    <li>We review child safety reports before any other report, as our highest priority.</li>
    <li>We remove the content immediately and permanently ban the accounts involved.</li>
    <li>We report child sexual abuse material and exploitation to the appropriate authorities, including India's National Cyber Crime Reporting Portal and law enforcement, and NCMEC's CyberTipline where applicable.</li>
    <li>We preserve the information the law requires and cooperate fully with law enforcement investigations.</li>
    <li>We may not tell the reported player about a report or our action when that could put a child at risk or hinder an investigation.</li>
  </ul>

  <h2>6. Compliance with child safety laws</h2>
  <p>We comply with the child safety laws that apply to us, including India's Protection of Children from Sexual Offences (POCSO) Act, 2012 and Information Technology Act, 2000, and the laws of the countries where the app is available. These standards apply alongside our <a href="privacy.php">Privacy Policy</a>.</p>

  <h2>7. Child safety point of contact</h2>
  <p>Our designated contact for child safety, including questions about these standards and requests from authorities:
    <?php if ($contact !== ''): ?><a href="mailto:<?= pp_e($contact) ?>?subject=<?= rawurlencode('Child safety - ' . $playName) ?>"><strong><?= pp_e($contact) ?></strong></a><?php else: ?><strong>the contact email shown in the app</strong><?php endif; ?>.</p>

  <h2>8. Changes</h2>
  <p>We review these standards regularly and post any update on this page with a new effective date.</p>

  <footer>
    <p>© <?= gmdate('Y') ?> <?= pp_e($appName) ?> · <a href="privacy.php">Privacy Policy</a> · <a href="delete-account.php">Delete account</a> · <a href="./">Open the app</a></p>
  </footer>
</main>
</body>
</html>
