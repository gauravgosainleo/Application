<?php require_once __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Koenig Video Calling</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="landing">
<header class="topbar">
    <div class="brand">Koenig <span>Video</span></div>
</header>

<main class="center">
    <div class="card">
        <h1>Start a Quick Meeting</h1>
        <p class="lede">Create an instant video room and share the link with anyone you want to join.</p>

        <form id="quick-meeting-form" class="form" autocomplete="off">
            <label>
                <span>Meeting name</span>
                <input type="text" name="name" id="m-name" maxlength="150" required placeholder="e.g. Weekly Sync">
            </label>
            <label>
                <span>Meeting description</span>
                <textarea name="description" id="m-desc" rows="3" maxlength="2000" placeholder="What is this meeting about?"></textarea>
            </label>
            <button type="submit" id="m-generate" class="btn primary">Generate link</button>
        </form>

        <div id="link-result" class="result hidden">
            <h2>Your meeting is ready</h2>
            <p class="meeting-title"></p>
            <div class="link-row">
                <input type="text" id="m-link" readonly>
                <button type="button" id="m-copy" class="btn">Copy</button>
            </div>
            <a id="m-join" class="btn primary big" href="#">Join meeting room</a>
            <p class="hint">Share the link above with anyone who should join &mdash; no login required.</p>
        </div>

        <p id="form-error" class="error hidden"></p>
    </div>
</main>

<footer class="foot">Koenig Video Calling &middot; Powered by WebRTC</footer>

<script src="assets/js/app.js"></script>
</body>
</html>
