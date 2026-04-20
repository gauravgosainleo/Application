<?php
// Koenig Video Calling App - Configuration
// Update these values for production.

define('DB_HOST', 'localhost');
define('DB_NAME', 'u694536902_Koenigvideo');
define('DB_USER', 'u694536902_Koenigvideo');
define('DB_PASS', 'Gauravgosain@1991');
define('DB_CHARSET', 'utf8mb4');

// Full public base URL (used when generating meeting share links).
define('APP_BASE_URL', 'http://learninganddevelopment.net/Koenig/Koenig%20video%20calling%20app/');

// How long (seconds) a participant can go without a heartbeat before they are
// considered disconnected. WebRTC signalling polls ~ every 2s, so 15 is safe.
define('PARTICIPANT_TIMEOUT', 15);

date_default_timezone_set('UTC');
