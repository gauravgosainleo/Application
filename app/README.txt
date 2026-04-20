Koenig Video Calling App
========================

A simple Zoom/Teams-style Quick Meeting app built with plain PHP + MySQL +
WebRTC. No Node.js or WebSocket server required -- works on Hostinger shared
hosting.

HOW IT WORKS
------------
1. Landing page (index.php) lets the organiser create a Quick Meeting by
   entering a name + description and clicking "Generate link".
2. The link (http://.../meeting.php?code=XXXX) can be shared with anyone.
3. When a person opens the link, they grant camera + mic access, enter a
   display name and join.
4. All participants see and hear each other via peer-to-peer WebRTC.
   Signalling (offer / answer / ICE) is relayed through the PHP + MySQL
   backend using short-poll AJAX.
5. Controls in the room: mute / unmute audio, camera off / on, leave.

DEPLOYMENT (Hostinger)
----------------------
Upload everything inside the `app/` directory to:
    public_html/Koenig/Koenig video calling app/

so it's reachable at:
    http://learninganddevelopment.net/Koenig/Koenig%20video%20calling%20app/

Step 1 - Database
    The database credentials are pre-filled in config.php:
        DB name : u694536902_Koenigvideo
        DB user : u694536902_Koenigvideo
        password: Gauravgosain@1991

    If you change hosts or credentials, edit config.php.

Step 2 - Create the tables
    Open this URL once in the browser:
        http://learninganddevelopment.net/Koenig/Koenig%20video%20calling%20app/install.php
    You should see "installed OK". THEN DELETE install.php from the server.

Step 3 - Use the app
    Open index.php, create a meeting, share the link.

NOTES
-----
* Camera / microphone permission requires a secure context. Hostinger serves
  https by default -- use the https:// variant of the URL in production for
  best browser compatibility. (Chrome blocks getUserMedia on plain http
  except on localhost.)
* Topology is full mesh, suitable for small meetings (up to ~6-8 participants
  comfortably). For larger rooms use an SFU.
* If peers cannot connect across restrictive NATs, add a TURN server to the
  `iceServers` array in assets/js/meeting.js.

FILES
-----
  index.php             Landing page (Quick Meeting form)
  meeting.php           Meeting room page
  install.php           One-time DB installer (delete after use)
  config.php            DB credentials + base URL
  db.php                PDO helpers
  api/create_meeting.php  Create meeting + return link
  api/meeting_info.php    Fetch meeting by code
  api/join.php           Register participant
  api/peers.php          List current peers (+ heartbeat)
  api/signal.php         WebRTC signalling relay
  api/leave.php          Remove participant
  assets/css/style.css   Stylesheet
  assets/js/app.js       Landing page script
  assets/js/meeting.js   Meeting room / WebRTC logic
  .htaccess              Security headers + denies config.php over HTTP
