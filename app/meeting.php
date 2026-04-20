<?php
require_once __DIR__ . '/db.php';

$code = trim((string)($_GET['code'] ?? ''));
$meeting = null;
if ($code !== '') {
    $stmt = db()->prepare('SELECT meeting_code, name, description FROM meetings WHERE meeting_code = ?');
    $stmt->execute([$code]);
    $meeting = $stmt->fetch();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $meeting ? htmlspecialchars($meeting['name']) . ' &middot; ' : '' ?>Koenig Video</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="meeting-body">
<?php if (!$meeting): ?>
    <main class="center">
        <div class="card">
            <h1>Meeting not found</h1>
            <p>The meeting link is invalid or has expired.</p>
            <a class="btn primary" href="index.php">Start a new meeting</a>
        </div>
    </main>
<?php else: ?>
    <div id="meeting-app"
         data-code="<?= htmlspecialchars($meeting['meeting_code']) ?>"
         data-name="<?= htmlspecialchars($meeting['name']) ?>"
         data-desc="<?= htmlspecialchars($meeting['description'] ?? '') ?>">

        <div id="prejoin" class="prejoin">
            <div class="card">
                <h1><?= htmlspecialchars($meeting['name']) ?></h1>
                <?php if (!empty($meeting['description'])): ?>
                    <p class="lede"><?= nl2br(htmlspecialchars($meeting['description'])) ?></p>
                <?php endif; ?>
                <video id="preview-video" autoplay playsinline muted></video>
                <label>
                    <span>Your name</span>
                    <input type="text" id="display-name" maxlength="100" placeholder="Enter your name">
                </label>
                <div class="prejoin-actions">
                    <button id="join-btn" class="btn primary big">Join meeting</button>
                </div>
                <p id="prejoin-error" class="error hidden"></p>
                <p class="hint">Your browser will ask for camera &amp; microphone permission.</p>
            </div>
        </div>

        <div id="room" class="room hidden">
            <header class="room-header">
                <div>
                    <strong><?= htmlspecialchars($meeting['name']) ?></strong>
                    <span class="pill" id="peer-count">1</span>
                </div>
                <div class="room-header-actions">
                    <button id="copy-link" class="btn small">Copy invite link</button>
                </div>
            </header>

            <main id="video-grid" class="video-grid"></main>

            <footer class="room-controls">
                <button id="toggle-audio" class="ctl" aria-pressed="false" title="Mute microphone">
                    <span class="icon icon-mic"></span>
                    <span class="label">Mute</span>
                </button>
                <button id="toggle-video" class="ctl" aria-pressed="false" title="Stop camera">
                    <span class="icon icon-cam"></span>
                    <span class="label">Camera off</span>
                </button>
                <button id="leave" class="ctl danger" title="Leave meeting">
                    <span class="icon icon-leave"></span>
                    <span class="label">Leave</span>
                </button>
            </footer>
        </div>
    </div>

    <script>
        window.KOENIG_CONFIG = {
            apiBase: 'api/',
            code: <?= json_encode($meeting['meeting_code']) ?>,
            joinUrl: <?= json_encode(rtrim(APP_BASE_URL, '/') . '/meeting.php?code=' . urlencode($meeting['meeting_code'])) ?>
        };
    </script>
    <script src="assets/js/meeting.js"></script>
<?php endif; ?>
</body>
</html>
